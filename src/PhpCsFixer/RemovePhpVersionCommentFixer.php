<?php

declare(strict_types=1);

/**
 * Custom PHP CS Fixer to remove "PHP Version X" comments from docblocks.
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
 * Removes "PHP Version X" lines from docblocks.
 *
 * This fixer identifies and removes lines containing "PHP Version 5", "PHP Version 7",
 * or "PHP Version 8" from file-level and class-level docblocks. It also removes
 * the empty line that follows if present.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class RemovePhpVersionCommentFixer extends AbstractFixer
{
    public function getName(): string
    {
        return 'Horde/remove_php_version_comment';
    }

    public function getDefinition(): FixerDefinitionInterface
    {
        return new FixerDefinition(
            'Remove "PHP Version X" comments from docblocks.',
            [
                new CodeSample(
                    '<?php
/**
 * Some description.
 *
 * PHP Version 7
 *
 * @category Horde
 */
class Foo {}
'
                ),
            ],
            'Removes legacy PHP version comments from file and class docblocks.'
        );
    }

    public function isCandidate(Tokens $tokens): bool
    {
        return $tokens->isTokenKindFound(T_DOC_COMMENT);
    }

    public function getPriority(): int
    {
        // Run before most other fixers
        return 10;
    }

    protected function applyFix(SplFileInfo $file, Tokens $tokens): void
    {
        foreach ($tokens as $index => $token) {
            if (!$token->isGivenKind(T_DOC_COMMENT)) {
                continue;
            }

            $content = $token->getContent();
            $updatedContent = $this->removePhpVersionLines($content);

            if ($content !== $updatedContent) {
                $tokens[$index] = new Token([T_DOC_COMMENT, $updatedContent]);
            }
        }
    }

    /**
     * Remove PHP Version lines from docblock content.
     *
     * @param string $docblock The original docblock content
     *
     * @return string The docblock with PHP Version lines removed
     */
    private function removePhpVersionLines(string $docblock): string
    {
        $lines = explode("\n", $docblock);
        $result = [];
        $skipNextEmptyLine = false;

        foreach ($lines as $line) {
            // Check if this line contains "PHP Version X"
            if (preg_match('/^\s*\*\s*PHP Version [0-9]+\s*$/', $line)) {
                // Skip this line and flag to skip next empty line
                $skipNextEmptyLine = true;
                continue;
            }

            // Check if we should skip this line (empty line after PHP Version)
            if ($skipNextEmptyLine && preg_match('/^\s*\*\s*$/', $line)) {
                $skipNextEmptyLine = false;
                continue;
            }

            $skipNextEmptyLine = false;
            $result[] = $line;
        }

        return implode("\n", $result);
    }
}
