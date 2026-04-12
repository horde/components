<?php

declare(strict_types=1);

/**
 * Custom PHP CS Fixer to rewrite Horde_Util to PSR-4 Horde\Util\Util.
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
use PhpCsFixer\FixerDefinition\FixerDefinition;
use PhpCsFixer\FixerDefinition\FixerDefinitionInterface;
use PhpCsFixer\FixerDefinition\CodeSample;
use PhpCsFixer\Tokenizer\Tokens;
use PhpCsFixer\Tokenizer\Token;
use SplFileInfo;

/**
 * Rewrites PSR-0 Horde_Util calls to PSR-4 Horde\Util\Util.
 *
 * This fixer automatically converts static method calls from the PSR-0
 * Horde_Util class to the PSR-4 Horde\Util\Util class. It also adds
 * the necessary use statement.
 *
 * For methods removed in PSR-4 (dispelMagicQuotes, loadExtension), it adds
 * warning comments instead of rewriting.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class RewriteHordeUtilToPsr4Fixer extends AbstractFixer
{
    /**
     * Methods with direct PSR-4 equivalents.
     */
    private const SUPPORTED_METHODS = [
        'getFormData',
        'getGet',
        'getPost',
        'getPathInfo',
        'getTempFile',
        'getTempDir',
        'getTempFileWithExtension',
        'createTempDir',
        'realPath',
        'deleteAtShutdown',
        'nonInputVar',
        'extensionExists',
        'formInput',
        'pformInput',
    ];

    /**
     * Methods removed in PSR-4 version with warning messages.
     */
    private const REMOVED_METHODS = [
        'dispelMagicQuotes' => 'Magic quotes are obsolete in PHP 8+. Remove this call.',
        'loadExtension' => 'No direct equivalent. Check extension availability differently.',
    ];

    /**
     * Track if we've added a use statement (to avoid duplicates in one pass).
     */
    private bool $useStatementAdded = false;

    public function getName(): string
    {
        return 'Horde/rewrite_horde_util_to_psr4';
    }

    public function getDefinition(): FixerDefinitionInterface
    {
        return new FixerDefinition(
            'Rewrite Horde_Util:: calls to Horde\Util\Util:: (PSR-4).',
            [
                new CodeSample(
                    '<?php
namespace MyApp;

$data = Horde_Util::getFormData("key");
'
                ),
            ],
            'Automatically converts PSR-0 Horde_Util to PSR-4 Horde\Util\Util and adds use statements. RISKY: Changes code behavior.'
        );
    }

    public function isCandidate(Tokens $tokens): bool
    {
        return $tokens->isTokenKindFound(T_STRING) && $tokens->isTokenKindFound(T_DOUBLE_COLON);
    }

    public function isRisky(): bool
    {
        return true;
    }

    public function getPriority(): int
    {
        // Run after MarkDeprecatedHordeCallsFixer (15)
        return 20;
    }

    protected function applyFix(SplFileInfo $file, Tokens $tokens): void
    {
        // Reset use statement tracking for this file
        $this->useStatementAdded = false;

        // Find all Horde_Util method calls
        $calls = $this->findHordeUtilCalls($tokens);

        if (empty($calls)) {
            return;
        }

        // Check if we need to add use statement (only if we have supported methods)
        $hasSupportedMethods = false;
        foreach ($calls as $call) {
            if (in_array($call['method'], self::SUPPORTED_METHODS)) {
                $hasSupportedMethods = true;
                break;
            }
        }

        // Process calls FIRST in reverse order to maintain correct indices
        foreach (array_reverse($calls) as $call) {
            if (in_array($call['method'], self::SUPPORTED_METHODS)) {
                $this->rewriteMethodCall($tokens, $call['classIndex'], $call['methodIndex']);
            } elseif (isset(self::REMOVED_METHODS[$call['method']])) {
                $this->handleRemovedMethod($tokens, $call['classIndex'], $call['method']);
            }
        }

        // Add use statement AFTER processing calls (so indices don't shift)
        if ($hasSupportedMethods) {
            $this->ensureUseStatement($tokens);
        }
    }

    /**
     * Find all Horde_Util method calls in the token stream.
     *
     * @param Tokens $tokens The token stream
     *
     * @return array Array of calls with indices
     */
    private function findHordeUtilCalls(Tokens $tokens): array
    {
        $calls = [];

        for ($index = 0; $index < count($tokens); $index++) {
            $token = $tokens[$index];

            // Look for T_STRING token
            if (!$token->isGivenKind(T_STRING)) {
                continue;
            }

            // Check if it's 'Horde_Util'
            if ($token->getContent() !== 'Horde_Util') {
                continue;
            }

            // Check next token is ::
            if (!isset($tokens[$index + 1]) || !$tokens[$index + 1]->isGivenKind(T_DOUBLE_COLON)) {
                continue;
            }

            // Check token after :: is a string (method name)
            if (!isset($tokens[$index + 2]) || !$tokens[$index + 2]->isGivenKind(T_STRING)) {
                continue;
            }

            $methodName = $tokens[$index + 2]->getContent();

            // Check if it's a supported or removed method
            if (in_array($methodName, self::SUPPORTED_METHODS) || isset(self::REMOVED_METHODS[$methodName])) {
                $calls[] = [
                    'classIndex' => $index,
                    'methodIndex' => $index + 2,
                    'method' => $methodName,
                ];
            }
        }

        return $calls;
    }

    /**
     * Rewrite a Horde_Util method call to use PSR-4 Util class.
     *
     * @param Tokens $tokens      The token stream
     * @param int    $classIndex  The index of 'Horde_Util' token
     * @param int    $methodIndex The index of the method name token
     */
    private function rewriteMethodCall(Tokens $tokens, int $classIndex, int $methodIndex): void
    {
        // Replace 'Horde_Util' with 'Util'
        $tokens[$classIndex] = new Token([T_STRING, 'Util']);
    }

    /**
     * Handle a removed method by adding a warning comment.
     *
     * @param Tokens $tokens     The token stream
     * @param int    $classIndex The index of 'Horde_Util' token
     * @param string $method     The removed method name
     */
    private function handleRemovedMethod(Tokens $tokens, int $classIndex, string $method): void
    {
        // Find statement start
        $statementStart = $this->findStatementStart($tokens, $classIndex);

        // Check if there's already a docblock or comment
        if ($this->hasExistingComment($tokens, $statementStart, $classIndex)) {
            return;
        }

        // Get indentation
        $indent = $this->getIndentation($tokens, $statementStart);

        // Get warning message
        $warning = self::REMOVED_METHODS[$method];

        // Build warning comment
        $comment = sprintf(
            "/**\n%s * WARNING: Horde_Util::%s() removed in PSR-4 version\n" .
            "%s * %s\n" .
            "%s */\n%s",
            $indent,
            $method,
            $indent,
            $warning,
            $indent,
            $indent
        );

        // Insert the comment
        $tokens->insertAt($statementStart, [
            new Token([T_DOC_COMMENT, $comment]),
        ]);
    }

    /**
     * Ensure use statement for Horde\Util\Util exists.
     *
     * @param Tokens $tokens The token stream
     */
    private function ensureUseStatement(Tokens $tokens): void
    {
        if ($this->hasUseStatement($tokens)) {
            return;
        }

        $insertIndex = $this->findUseStatementInsertionPoint($tokens);

        if ($insertIndex === -1) {
            return; // Can't find a safe place to insert
        }

        // Insert use statement
        $tokens->insertAt($insertIndex, [
            new Token([T_USE, 'use']),
            new Token([T_WHITESPACE, ' ']),
            new Token([T_STRING, 'Horde']),
            new Token([T_NS_SEPARATOR, '\\']),
            new Token([T_STRING, 'Util']),
            new Token([T_NS_SEPARATOR, '\\']),
            new Token([T_STRING, 'Util']),
            new Token(';'),
            new Token([T_WHITESPACE, "\n"]),
        ]);

        $this->useStatementAdded = true;
    }

    /**
     * Check if use statement for Horde\Util\Util already exists.
     *
     * @param Tokens $tokens The token stream
     *
     * @return bool True if use statement exists
     */
    private function hasUseStatement(Tokens $tokens): bool
    {
        if ($this->useStatementAdded) {
            return true;
        }

        for ($index = 0; $index < count($tokens); $index++) {
            $token = $tokens[$index];

            if (!$token->isGivenKind(T_USE)) {
                continue;
            }

            // Look ahead for Horde\Util\Util pattern
            $useContent = '';
            for ($i = $index + 1; $i < count($tokens) && !$tokens[$i]->equals(';'); $i++) {
                if ($tokens[$i]->isGivenKind([T_STRING, T_NS_SEPARATOR])) {
                    $useContent .= $tokens[$i]->getContent();
                }
            }

            if ($useContent === 'Horde\\Util\\Util') {
                return true;
            }
        }

        return false;
    }

    /**
     * Find the insertion point for use statement.
     *
     * @param Tokens $tokens The token stream
     *
     * @return int The index where use statement should be inserted, or -1 if not found
     */
    private function findUseStatementInsertionPoint(Tokens $tokens): int
    {
        $namespaceIndex = -1;
        $lastUseIndex = -1;

        // Find namespace declaration
        for ($index = 0; $index < count($tokens); $index++) {
            $token = $tokens[$index];

            if ($token->isGivenKind(T_NAMESPACE)) {
                $namespaceIndex = $index;
            }

            if ($token->isGivenKind(T_USE)) {
                $lastUseIndex = $index;
            }
        }

        // If we have use statements, insert after the last one
        if ($lastUseIndex !== -1) {
            // Find the semicolon after the last use statement
            for ($i = $lastUseIndex; $i < count($tokens); $i++) {
                if ($tokens[$i]->equals(';')) {
                    // Skip whitespace after semicolon
                    $next = $i + 1;
                    if (isset($tokens[$next]) && $tokens[$next]->isGivenKind(T_WHITESPACE)) {
                        return $next + 1;
                    }
                    return $next;
                }
            }
        }

        // If we have a namespace, insert after it
        if ($namespaceIndex !== -1) {
            // Find semicolon or opening brace after namespace
            for ($i = $namespaceIndex; $i < count($tokens); $i++) {
                if ($tokens[$i]->equals(';') || $tokens[$i]->equals('{')) {
                    // Skip whitespace
                    $next = $i + 1;
                    if (isset($tokens[$next]) && $tokens[$next]->isGivenKind(T_WHITESPACE)) {
                        return $next + 1;
                    }
                    return $next;
                }
            }
        }

        // No namespace, insert after opening PHP tag
        for ($index = 0; $index < count($tokens); $index++) {
            if ($tokens[$index]->isGivenKind(T_OPEN_TAG)) {
                // Skip whitespace after opening tag
                $next = $index + 1;
                if (isset($tokens[$next]) && $tokens[$next]->isGivenKind(T_WHITESPACE)) {
                    return $next + 1;
                }
                return $next;
            }
        }

        return -1; // Can't find insertion point
    }

    /**
     * Find the start of the statement containing the given index.
     *
     * @param Tokens $tokens    The token stream
     * @param int    $fromIndex Start searching backward from this index
     *
     * @return int The index of the statement start
     */
    private function findStatementStart(Tokens $tokens, int $fromIndex): int
    {
        for ($i = $fromIndex - 1; $i >= 0; $i--) {
            $token = $tokens[$i];

            // Stop at statement terminators
            if ($token->equals(';') || $token->equals('{') || $token->equals('}')) {
                $next = $i + 1;
                if (isset($tokens[$next]) && $tokens[$next]->isGivenKind(T_WHITESPACE)) {
                    return $next + 1;
                }
                return $next;
            }

            // Stop at file start
            if ($token->isGivenKind(T_OPEN_TAG)) {
                $next = $i + 1;
                if (isset($tokens[$next]) && $tokens[$next]->isGivenKind(T_WHITESPACE)) {
                    return $next + 1;
                }
                return $next;
            }
        }

        return 0;
    }

    /**
     * Get the indentation string for the line at the given index.
     *
     * @param Tokens $tokens The token stream
     * @param int    $index  The index to get indentation for
     *
     * @return string The indentation string
     */
    private function getIndentation(Tokens $tokens, int $index): string
    {
        if ($index > 0) {
            $prevToken = $tokens[$index - 1];

            if ($prevToken->isGivenKind(T_WHITESPACE)) {
                $content = $prevToken->getContent();
                $lines = explode("\n", $content);
                $lastLine = end($lines);

                if (trim($lastLine) === '') {
                    return $lastLine;
                }
            }
        }

        return '';
    }

    /**
     * Check if there's already a comment near the given call.
     *
     * Scans both forward from statementStart (catching comments that
     * findStatementStart walked past) and backward (original position check).
     * This prevents stacking warning comments on repeated fixer runs.
     *
     * @param Tokens $tokens         The token stream
     * @param int    $statementStart The index of the statement start
     * @param int    $callIndex      The index of the Horde_Util call token
     *
     * @return bool True if a comment already exists
     */
    private function hasExistingComment(Tokens $tokens, int $statementStart, int $callIndex): bool
    {
        // Scan forward from statementStart toward the call for any comment
        // (either our warning marker or a user-written comment)
        for ($i = $statementStart; $i < $callIndex; $i++) {
            if ($tokens[$i]->isGivenKind([T_DOC_COMMENT, T_COMMENT])) {
                return true;
            }
        }

        // Check backward from statementStart for any comment (our marker or user-written)
        for ($i = $statementStart - 1; $i >= 0; $i--) {
            $token = $tokens[$i];

            if ($token->isGivenKind([T_DOC_COMMENT, T_COMMENT])) {
                return true;
            }

            if (!$token->isGivenKind(T_WHITESPACE)) {
                return false;
            }
        }

        return false;
    }
}
