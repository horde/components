<?php

declare(strict_types=1);

/**
 * Custom PHP CS Fixer to mark deprecated Horde:: static method calls.
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
 * Marks deprecated Horde:: static method calls with architecture violation warnings.
 *
 * This fixer identifies calls to deprecated methods that are routed through
 * Horde::__callStatic() to Horde_Deprecated class. It adds docblock comments
 * with warnings and specific upgrade paths for each deprecated method.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class MarkDeprecatedHordeCallsFixer extends AbstractFixer
{
    /**
     * Map of deprecated method names to upgrade paths.
     *
     * These methods exist in Horde_Deprecated class and are accessed via
     * Horde::__callStatic() magic method.
     */
    private const DEPRECATED_METHODS = [
        'img' => 'Horde_Themes_Image::tag()',
        'fullSrcImg' => 'Horde_Themes_Image::tag()',
        'base64ImgData' => 'Horde_Themes_Image::base64ImgData()',
        'callHook' => '$GLOBALS[\'injector\']->getInstance(\'Horde_Core_Hooks\')->callHook()',
        'hookExists' => '$GLOBALS[\'injector\']->getInstance(\'Horde_Core_Hooks\')->hookExists()',
        'loadConfiguration' => '$registry->loadConfigFile()',
        'initMap' => 'Horde_Core_HordeMap::init()',
        'sendHTTPResponse' => '(Refactor to use PSR-7 responses)',
        'prepareResponse' => '(Refactor to use PSR-7 responses)',
        'redirect' => '(Refactor to use PSR-7 responses)',
    ];

    public function getName(): string
    {
        return 'Horde/mark_deprecated_horde_calls';
    }

    public function getDefinition(): FixerDefinitionInterface
    {
        return new FixerDefinition(
            'Mark deprecated Horde:: static method calls with architecture violation warnings.',
            [
                new CodeSample(
                    '<?php
class Foo {
    public function bar() {
        $img = Horde::img("icon.png", "Icon");
    }
}
'
                ),
            ],
            'Adds docblock comments with upgrade paths for deprecated Horde:: method calls.'
        );
    }

    public function isCandidate(Tokens $tokens): bool
    {
        return $tokens->isTokenKindFound(T_STRING) && $tokens->isTokenKindFound(T_DOUBLE_COLON);
    }

    public function getPriority(): int
    {
        // Run after RemovePhpVersionCommentFixer (10), before UpdateCopyrightYearFixer (5)
        return 15;
    }

    protected function applyFix(SplFileInfo $file, Tokens $tokens): void
    {
        // Skip the Horde_Deprecated class file itself
        if ($this->isDeprecatedFile($file)) {
            return;
        }

        // Find all deprecated Horde:: method calls
        $calls = $this->findHordeDeprecatedCalls($tokens);

        // Process in reverse order to maintain correct indices
        foreach (array_reverse($calls) as $call) {
            $this->insertDocblock($tokens, $call['index'], $call['method']);
        }
    }

    /**
     * Check if this is the Horde_Deprecated class file itself.
     *
     * @param SplFileInfo $file The file being processed
     *
     * @return bool True if this is the Deprecated.php file
     */
    private function isDeprecatedFile(SplFileInfo $file): bool
    {
        $filename = $file->getFilename();
        return $filename === 'Deprecated.php' || str_contains($file->getPathname(), '/Horde/Deprecated.php');
    }

    /**
     * Find all deprecated Horde:: method calls in the token stream.
     *
     * @param Tokens $tokens The token stream
     *
     * @return array Array of calls with 'index' and 'method' keys
     */
    private function findHordeDeprecatedCalls(Tokens $tokens): array
    {
        $calls = [];

        for ($index = 0; $index < count($tokens); $index++) {
            $token = $tokens[$index];

            // Look for T_STRING token
            if (!$token->isGivenKind(T_STRING)) {
                continue;
            }

            // Check if it's 'Horde'
            if ($token->getContent() !== 'Horde') {
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

            // Check if it's a deprecated method
            if (!isset(self::DEPRECATED_METHODS[$methodName])) {
                continue;
            }

            // Found a deprecated call
            $calls[] = [
                'index' => $index,
                'method' => $methodName,
            ];
        }

        return $calls;
    }

    /**
     * Insert docblock comment before a deprecated method call.
     *
     * @param Tokens $tokens The token stream
     * @param int    $index  The index of the 'Horde' token
     * @param string $method The deprecated method name
     */
    private function insertDocblock(Tokens $tokens, int $index, string $method): void
    {
        // Find the start of the statement
        $statementStart = $this->findStatementStart($tokens, $index);

        // Check if there's already a docblock
        if ($this->hasExistingDocblock($tokens, $statementStart)) {
            return;
        }

        // Get indentation
        $indent = $this->getIndentation($tokens, $statementStart);

        // Get upgrade path
        $upgradePath = self::DEPRECATED_METHODS[$method];

        // Build docblock
        $docblock = sprintf(
            "/**\n%s * ARCHITECTURE VIOLATION: Using deprecated Horde::%s()\n" .
            "%s * @deprecated Use %s instead\n" .
            "%s * @see Horde_Deprecated::%s()\n" .
            "%s */\n%s",
            $indent,
            $method,
            $indent,
            $upgradePath,
            $indent,
            $method,
            $indent,
            $indent
        );

        // Insert the docblock
        $tokens->insertAt($statementStart, [
            new Token([T_DOC_COMMENT, $docblock]),
        ]);
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
                // Skip whitespace after terminator
                $next = $i + 1;
                if (isset($tokens[$next]) && $tokens[$next]->isGivenKind(T_WHITESPACE)) {
                    return $next + 1;
                }
                return $next;
            }

            // Stop at file start
            if ($token->isGivenKind(T_OPEN_TAG)) {
                // Skip whitespace after opening tag
                $next = $i + 1;
                if (isset($tokens[$next]) && $tokens[$next]->isGivenKind(T_WHITESPACE)) {
                    return $next + 1;
                }
                return $next;
            }
        }

        return 0; // File start
    }

    /**
     * Get the indentation string for the line at the given index.
     *
     * @param Tokens $tokens The token stream
     * @param int    $index  The index to get indentation for
     *
     * @return string The indentation string (spaces or tabs)
     */
    private function getIndentation(Tokens $tokens, int $index): string
    {
        // Check the previous token for whitespace
        if ($index > 0) {
            $prevToken = $tokens[$index - 1];

            if ($prevToken->isGivenKind(T_WHITESPACE)) {
                $content = $prevToken->getContent();
                // Extract the last line's indentation
                $lines = explode("\n", $content);
                $lastLine = end($lines);

                // If last line is just whitespace, that's our indentation
                if (trim($lastLine) === '') {
                    return $lastLine;
                }
            }
        }

        return ''; // No indentation
    }

    /**
     * Check if there's already a docblock before the given index.
     *
     * @param Tokens $tokens The token stream
     * @param int    $index  The index to check before
     *
     * @return bool True if a docblock already exists
     */
    private function hasExistingDocblock(Tokens $tokens, int $index): bool
    {
        // Look backwards for a docblock, skipping only whitespace
        for ($i = $index - 1; $i >= 0; $i--) {
            $token = $tokens[$i];

            // Found a docblock
            if ($token->isGivenKind(T_DOC_COMMENT)) {
                return true;
            }

            // Found non-whitespace that's not a docblock
            if (!$token->isGivenKind(T_WHITESPACE)) {
                return false;
            }
        }

        return false;
    }
}
