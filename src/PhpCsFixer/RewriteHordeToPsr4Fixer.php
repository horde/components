<?php

declare(strict_types=1);

/**
 * Custom PHP CS Fixer to rewrite Horde:: to \Horde\Core\Horde:: for
 * methods annotated as forwarded.
 *
 * Copyright 2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

namespace Horde\Components\PhpCsFixer;

use PhpCsFixer\AbstractFixer;
use PhpCsFixer\FixerDefinition\CodeSample;
use PhpCsFixer\FixerDefinition\FixerDefinition;
use PhpCsFixer\FixerDefinition\FixerDefinitionInterface;
use PhpCsFixer\Tokenizer\Token;
use PhpCsFixer\Tokenizer\Tokens;
use SplFileInfo;

/**
 * Rewrites Horde::method() and \Horde::method() calls to use
 * \Horde\Core\Horde for methods that have been forwarded from the
 * legacy global Horde class.
 *
 * The legacy lib/Horde.php class annotates forwarded methods with:
 *   @deprecated Use {@see \Horde\Core\Horde::methodName()} instead.
 *
 * This fixer detects calls to those specific methods and rewrites them:
 *
 * - Bare Horde:: in a namespaced file where ALL bare Horde:: calls are
 *   forwarded: adds `use Horde\Core\Horde;` (cleaner, preferred form).
 * - Bare Horde:: in mixed files (some non-forwarded): rewrites only the
 *   forwarded calls to FQCN `\Horde\Core\Horde::method()`.
 * - FQCN \Horde:: calls: always rewritten to `\Horde\Core\Horde::`,
 *   even if a use statement is present, because the leading backslash
 *   explicitly references the global class.
 *
 * Skips files inside lib/ (legacy PSR-0 code) and the Horde.php files
 * themselves.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class RewriteHordeToPsr4Fixer extends AbstractFixer
{
    /**
     * Methods in the legacy Horde class that forward to \Horde\Core\Horde.
     *
     * This list matches the methods annotated with:
     *   @deprecated Use {@see \Horde\Core\Horde::methodName()} instead.
     */
    private const FORWARDED_METHODS = [
        'signQueryString',
        'verifySignedQueryString',
        'verifySignedUrl',
        'escapeJson',
        'isConnectionSecure',
        'requireSecureConnection',
        'getDriverConfig',
        'assertDriverConfig',
        'externalUrl',
        'link',
        'linkTooltip',
        'widget',
        'getTempDir',
        'getTempFile',
        'webServerID',
        'getAccessKey',
        'stripAccessKey',
        'highlightAccessKey',
        'getAccessKeyAndTitle',
        'label',
        'wrapInlineScript',
        'popupJs',
        'startBuffer',
        'endBuffer',
        'contentSent',
        'sidebar',
        'permissionDeniedError',
    ];

    public function getName(): string
    {
        return 'Horde/rewrite_horde_to_psr4';
    }

    public function getDefinition(): FixerDefinitionInterface
    {
        return new FixerDefinition(
            'Rewrite Horde:: calls to \Horde\Core\Horde:: for forwarded methods.',
            [
                new CodeSample(
                    '<?php
namespace MyApp;

$json = Horde::escapeJson($data);
'
                ),
            ],
            'Automatically converts legacy global Horde:: static calls to '
            . 'the namespaced \Horde\Core\Horde:: equivalent for methods that '
            . 'have been annotated as forwarded. RISKY: Changes class resolution.'
        );
    }

    public function isCandidate(Tokens $tokens): bool
    {
        return $tokens->isTokenKindFound(T_STRING)
            && $tokens->isTokenKindFound(T_DOUBLE_COLON);
    }

    public function isRisky(): bool
    {
        return true;
    }

    public function getPriority(): int
    {
        // Run after MarkDeprecatedHordeCallsFixer (15) and
        // RewriteHordeUtilToPsr4Fixer (20).
        return 25;
    }

    protected function applyFix(SplFileInfo $file, Tokens $tokens): void
    {
        if ($this->shouldSkipFile($file, $tokens)) {
            return;
        }

        $calls = $this->findForwardedHordeCalls($tokens);

        if (empty($calls)) {
            return;
        }

        // Separate bare Horde:: from FQCN \Horde:: calls.
        $bareCalls = array_filter($calls, fn($c) => !$c['fqcn']);
        $hasUse = $this->hasUseStatement($tokens);
        $useAdded = false;

        // Bare Horde:: calls resolve correctly once a use statement exists.
        if (!empty($bareCalls) && !$hasUse) {
            // Decide strategy for bare calls: use statement vs FQCN.
            $allBareCalls = $this->findAllHordeCalls($tokens, true);
            $allBareForwarded = true;
            foreach ($allBareCalls as $call) {
                if (!in_array($call['method'], self::FORWARDED_METHODS, true)) {
                    $allBareForwarded = false;
                    break;
                }
            }

            if ($allBareForwarded && $this->hasNamespace($tokens)) {
                // All bare calls are forwarded and file is namespaced —
                // add use statement so bare Horde:: resolves to the
                // namespaced class.
                $this->ensureUseStatement($tokens);
                $useAdded = true;
            } else {
                // Mixed bare calls — rewrite only forwarded bare calls
                // to FQCN, processing in reverse for index stability.
                foreach (array_reverse(array_values($bareCalls)) as $call) {
                    $this->rewriteToFqcn($tokens, $call['classIndex'], false);
                }
            }
        }

        // FQCN \Horde:: calls always need rewriting regardless of use
        // statement — \Horde:: explicitly references the global class.
        // Re-scan after any token modifications above (indices shifted).
        $fqcnCalls = array_filter(
            $this->findForwardedHordeCalls($tokens),
            fn($c) => $c['fqcn'],
        );

        foreach (array_reverse(array_values($fqcnCalls)) as $call) {
            $this->rewriteToFqcn($tokens, $call['classIndex'], true);
        }
    }

    /**
     * Skip the Horde class files themselves and lib/ directory.
     */
    private function shouldSkipFile(SplFileInfo $file, Tokens $tokens): bool
    {
        $path = $file->getPathname();

        // Skip the legacy Horde class itself
        if (str_ends_with($path, '/lib/Horde.php')
            || str_ends_with($path, '/src/Horde.php')) {
            return true;
        }

        // Skip lib/ directory entirely (legacy PSR-0 code)
        if (str_contains($path, '/lib/')) {
            return true;
        }

        return false;
    }

    /**
     * Find Horde:: calls where the method is in the forwarded set.
     *
     * Matches both bare `Horde::method()` and FQCN `\Horde::method()`.
     * Already-rewritten `\Horde\Core\Horde::` calls are excluded.
     *
     * @return list<array{classIndex: int, method: string, fqcn: bool}>
     */
    private function findForwardedHordeCalls(Tokens $tokens): array
    {
        $calls = [];

        for ($index = 0, $count = count($tokens); $index < $count; $index++) {
            $token = $tokens[$index];

            if (!$token->isGivenKind(T_STRING) || $token->getContent() !== 'Horde') {
                continue;
            }

            // Skip instance calls (->Horde, ?->Horde)
            if ($index > 0) {
                $prev = $tokens[$index - 1];
                if ($prev->isGivenKind(T_OBJECT_OPERATOR)
                    || $prev->isGivenKind(T_NULLSAFE_OBJECT_OPERATOR)) {
                    continue;
                }
            }

            // Detect FQCN form: preceding \ that is NOT part of a longer
            // namespace (e.g. \Horde:: vs Foo\Horde::).
            $isFqcn = false;
            if ($index > 0 && $tokens[$index - 1]->isGivenKind(T_NS_SEPARATOR)) {
                // Check what precedes the backslash — if it's another
                // T_STRING then this is part of a compound name like
                // Foo\Horde, not a leading \Horde.
                if ($index > 1 && $tokens[$index - 2]->isGivenKind(T_STRING)) {
                    continue;
                }
                $isFqcn = true;
            }

            // Next must be ::
            if (!isset($tokens[$index + 1])
                || !$tokens[$index + 1]->isGivenKind(T_DOUBLE_COLON)) {
                continue;
            }

            // Token after :: must be a method name
            if (!isset($tokens[$index + 2])
                || !$tokens[$index + 2]->isGivenKind(T_STRING)) {
                continue;
            }

            $method = $tokens[$index + 2]->getContent();

            if (in_array($method, self::FORWARDED_METHODS, true)) {
                $calls[] = [
                    'classIndex' => $isFqcn ? $index - 1 : $index,
                    'method' => $method,
                    'fqcn' => $isFqcn,
                ];
            }
        }

        return $calls;
    }

    /**
     * Find ALL Horde:: static method calls (forwarded or not).
     *
     * @param bool $bareOnly When true, only match bare Horde:: (not \Horde::).
     *
     * @return list<array{classIndex: int, method: string, fqcn: bool}>
     */
    private function findAllHordeCalls(Tokens $tokens, bool $bareOnly = false): array
    {
        $calls = [];

        for ($index = 0, $count = count($tokens); $index < $count; $index++) {
            $token = $tokens[$index];

            if (!$token->isGivenKind(T_STRING) || $token->getContent() !== 'Horde') {
                continue;
            }

            if ($index > 0) {
                $prev = $tokens[$index - 1];
                if ($prev->isGivenKind(T_OBJECT_OPERATOR)
                    || $prev->isGivenKind(T_NULLSAFE_OBJECT_OPERATOR)) {
                    continue;
                }
            }

            $isFqcn = false;
            if ($index > 0 && $tokens[$index - 1]->isGivenKind(T_NS_SEPARATOR)) {
                if ($index > 1 && $tokens[$index - 2]->isGivenKind(T_STRING)) {
                    continue;
                }
                if ($bareOnly) {
                    continue;
                }
                $isFqcn = true;
            }

            if (!isset($tokens[$index + 1])
                || !$tokens[$index + 1]->isGivenKind(T_DOUBLE_COLON)) {
                continue;
            }

            if (!isset($tokens[$index + 2])
                || !$tokens[$index + 2]->isGivenKind(T_STRING)) {
                continue;
            }

            $calls[] = [
                'classIndex' => $isFqcn ? $index - 1 : $index,
                'method' => $tokens[$index + 2]->getContent(),
                'fqcn' => $isFqcn,
            ];
        }

        return $calls;
    }

    /**
     * Rewrite a Horde:: or \Horde:: call to \Horde\Core\Horde::.
     *
     * @param int $classIndex Index of the first token ('Horde' for bare, '\' for FQCN).
     * @param bool $isFqcn Whether the call is \Horde:: (two tokens) vs bare Horde:: (one token).
     */
    private function rewriteToFqcn(Tokens $tokens, int $classIndex, bool $isFqcn): void
    {
        $replacement = [
            new Token([T_NS_SEPARATOR, '\\']),
            new Token([T_STRING, 'Horde']),
            new Token([T_NS_SEPARATOR, '\\']),
            new Token([T_STRING, 'Core']),
            new Token([T_NS_SEPARATOR, '\\']),
            new Token([T_STRING, 'Horde']),
        ];

        if ($isFqcn) {
            // Replace \ and Horde (two tokens) with \Horde\Core\Horde
            $tokens->clearAt($classIndex);     // '\'
            $tokens->clearAt($classIndex + 1); // 'Horde'
            $tokens->insertAt($classIndex, $replacement);
        } else {
            // Replace bare Horde (one token) with \Horde\Core\Horde
            $tokens->clearAt($classIndex);
            $tokens->insertAt($classIndex, $replacement);
        }
    }

    /**
     * Check if the file has a namespace declaration.
     */
    private function hasNamespace(Tokens $tokens): bool
    {
        for ($i = 0, $count = count($tokens); $i < $count; $i++) {
            if ($tokens[$i]->isGivenKind(T_NAMESPACE)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if `use Horde\Core\Horde;` already exists.
     */
    private function hasUseStatement(Tokens $tokens): bool
    {
        for ($index = 0, $count = count($tokens); $index < $count; $index++) {
            if (!$tokens[$index]->isGivenKind(T_USE)) {
                continue;
            }

            // Collect the full use path
            $path = '';
            for ($i = $index + 1; $i < $count && !$tokens[$i]->equals(';'); $i++) {
                if ($tokens[$i]->isGivenKind([T_STRING, T_NS_SEPARATOR])) {
                    $path .= $tokens[$i]->getContent();
                }
            }

            if ($path === 'Horde\\Core\\Horde') {
                return true;
            }
        }

        return false;
    }

    /**
     * Add `use Horde\Core\Horde;` after the last existing use statement,
     * or after the namespace declaration.
     */
    private function ensureUseStatement(Tokens $tokens): void
    {
        if ($this->hasUseStatement($tokens)) {
            return;
        }

        [$insertIndex, $afterUse] = $this->findUseStatementInsertionPoint($tokens);
        if ($insertIndex === -1) {
            return;
        }

        if ($afterUse) {
            // Inserting after an existing use statement — place before the
            // whitespace that separates use block from code so that the new
            // use line groups with the existing ones.
            $tokens->insertAt($insertIndex, [
                new Token([T_WHITESPACE, "\n"]),
                new Token([T_USE, 'use']),
                new Token([T_WHITESPACE, ' ']),
                new Token([T_STRING, 'Horde']),
                new Token([T_NS_SEPARATOR, '\\']),
                new Token([T_STRING, 'Core']),
                new Token([T_NS_SEPARATOR, '\\']),
                new Token([T_STRING, 'Horde']),
                new Token(';'),
            ]);
        } else {
            // Inserting after namespace declaration — need trailing blank
            // line to separate from the code that follows.
            $tokens->insertAt($insertIndex, [
                new Token([T_USE, 'use']),
                new Token([T_WHITESPACE, ' ']),
                new Token([T_STRING, 'Horde']),
                new Token([T_NS_SEPARATOR, '\\']),
                new Token([T_STRING, 'Core']),
                new Token([T_NS_SEPARATOR, '\\']),
                new Token([T_STRING, 'Horde']),
                new Token(';'),
                new Token([T_WHITESPACE, "\n\n"]),
            ]);
        }
    }

    /**
     * Find where to insert a use statement.
     *
     * @return array{int, bool} [insertion index, whether inserting after a use statement]
     */
    private function findUseStatementInsertionPoint(Tokens $tokens): array
    {
        $lastUseIndex = -1;
        $namespaceIndex = -1;

        for ($index = 0, $count = count($tokens); $index < $count; $index++) {
            if ($tokens[$index]->isGivenKind(T_NAMESPACE)) {
                $namespaceIndex = $index;
            }
            if ($tokens[$index]->isGivenKind(T_USE)) {
                $lastUseIndex = $index;
            }
        }

        // Insert after the last use statement — return the index of the
        // whitespace token that follows the semicolon so the new use
        // statement is placed before the blank line separating the use
        // block from the code body.
        if ($lastUseIndex !== -1) {
            for ($i = $lastUseIndex; $i < count($tokens); $i++) {
                if ($tokens[$i]->equals(';')) {
                    $next = $i + 1;
                    if (isset($tokens[$next])
                        && $tokens[$next]->isGivenKind(T_WHITESPACE)) {
                        return [$next, true];
                    }
                    return [$next, true];
                }
            }
        }

        // Insert after namespace declaration
        if ($namespaceIndex !== -1) {
            for ($i = $namespaceIndex; $i < count($tokens); $i++) {
                if ($tokens[$i]->equals(';') || $tokens[$i]->equals('{')) {
                    $next = $i + 1;
                    if (isset($tokens[$next])
                        && $tokens[$next]->isGivenKind(T_WHITESPACE)) {
                        return [$next + 1, false];
                    }
                    return [$next, false];
                }
            }
        }

        return [-1, false];
    }
}
