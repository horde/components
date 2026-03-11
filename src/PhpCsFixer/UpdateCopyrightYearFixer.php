<?php

declare(strict_types=1);

/**
 * Custom PHP CS Fixer to update copyright years to current year.
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
 * Updates copyright year ranges to include current year.
 *
 * This fixer updates copyright statements in docblocks:
 * - "Copyright 2025" becomes "Copyright 2025-2026"
 * - "Copyright 2020-2024" becomes "Copyright 2020-2026"
 * - Uses current year dynamically
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class UpdateCopyrightYearFixer extends AbstractFixer
{
    public function getName(): string
    {
        return 'Horde/update_copyright_year';
    }

    public function getDefinition(): FixerDefinitionInterface
    {
        return new FixerDefinition(
            'Update copyright years to include current year.',
            [
                new CodeSample(
                    '<?php
/**
 * Copyright 2025 The Horde Project
 */
class Foo {}
'
                ),
            ],
            'Updates copyright year ranges to include the current year.'
        );
    }

    public function isCandidate(Tokens $tokens): bool
    {
        return $tokens->isTokenKindFound(T_DOC_COMMENT);
    }

    public function getPriority(): int
    {
        // Run after RemovePhpVersionCommentFixer
        return 5;
    }

    protected function applyFix(SplFileInfo $file, Tokens $tokens): void
    {
        $currentYear = date('Y');

        foreach ($tokens as $index => $token) {
            if (!$token->isGivenKind(T_DOC_COMMENT)) {
                continue;
            }

            $content = $token->getContent();
            $updatedContent = $this->updateCopyrightYears($content, $currentYear);

            if ($content !== $updatedContent) {
                $tokens[$index] = new Token([T_DOC_COMMENT, $updatedContent]);
            }
        }
    }

    /**
     * Update copyright years in docblock content.
     *
     * @param string $docblock    The original docblock content
     * @param string $currentYear The current year (e.g., "2026")
     *
     * @return string The docblock with updated copyright years
     */
    private function updateCopyrightYears(string $docblock, string $currentYear): string
    {
        $lines = explode("\n", $docblock);
        $result = [];

        foreach ($lines as $line) {
            // Match: Copyright YYYY (single year, not current year)
            // Convert to: Copyright YYYY-CURRENTYEAR
            if (preg_match('/^(\s*\*\s*Copyright\s+)(\d{4})(\s+.*)$/', $line, $matches)) {
                $year = $matches[2];
                if ($year !== $currentYear) {
                    $line = $matches[1] . $year . '-' . $currentYear . $matches[3];
                }
            }
            // Match: Copyright YYYY-ZZZZ (year range, end year not current)
            // Convert to: Copyright YYYY-CURRENTYEAR
            elseif (preg_match('/^(\s*\*\s*Copyright\s+)(\d{4})-(\d{4})(\s+.*)$/', $line, $matches)) {
                $startYear = $matches[2];
                $endYear = $matches[3];
                if ($endYear !== $currentYear) {
                    $line = $matches[1] . $startYear . '-' . $currentYear . $matches[4];
                }
            }

            $result[] = $line;
        }

        return implode("\n", $result);
    }
}
