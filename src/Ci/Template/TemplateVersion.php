<?php

/**
 * Copyright 2013-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

declare(strict_types=1);

namespace Horde\Components\Ci\Template;

/**
 * Extracts and compares template versions from generated files.
 *
 * Generated files include a version comment like:
 *   # Template version: 1.0.0
 *
 * This class can extract that version and compare it to the current version.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class TemplateVersion
{
    /**
     * Extract template version from a generated file.
     *
     * @param string $filePath Path to generated file
     * @return string|null Version string, or null if not found
     */
    public static function extractFromFile(string $filePath): ?string
    {
        if (!file_exists($filePath)) {
            return null;
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            return null;
        }

        // Look for: # Template version: X.Y.Z
        if (preg_match('/^#\s*Template version:\s*(.+)$/m', $content, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    /**
     * Check if a file's template version is outdated.
     *
     * @param string $filePath Path to generated file
     * @return bool True if version is older than current
     */
    public static function isOutdated(string $filePath): bool
    {
        $fileVersion = self::extractFromFile($filePath);

        if ($fileVersion === null) {
            // No version found - consider it outdated
            return true;
        }

        $currentVersion = TemplateRenderer::getTemplateVersion();

        return version_compare($fileVersion, $currentVersion, '<');
    }

    /**
     * Get version comparison information.
     *
     * @param string $filePath Path to generated file
     * @return array{file: string|null, current: string, outdated: bool}
     */
    public static function compare(string $filePath): array
    {
        $fileVersion = self::extractFromFile($filePath);
        $currentVersion = TemplateRenderer::getTemplateVersion();

        return [
            'file' => $fileVersion,
            'current' => $currentVersion,
            'outdated' => $fileVersion === null || version_compare($fileVersion, $currentVersion, '<'),
        ];
    }
}
