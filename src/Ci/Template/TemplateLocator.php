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

use Horde\Components\Exception;

/**
 * Locates CI template files.
 *
 * Provides methods to find template directories and list available templates.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class TemplateLocator
{
    /**
     * Get the template directory path.
     *
     * @return string Absolute path to template directory
     * @throws Exception If template directory not found
     */
    public static function getTemplateDir(): string
    {
        // From src/Ci/Template/TemplateLocator.php
        // Go up to components/ root, then into data/ci/
        $dir = dirname(__DIR__, 3) . '/data/ci';

        if (!is_dir($dir)) {
            throw new Exception("Template directory not found: {$dir}");
        }

        return $dir;
    }

    /**
     * List available templates.
     *
     * @return array<string> Template names (without .template extension)
     */
    public static function listTemplates(): array
    {
        $dir = self::getTemplateDir();
        $files = glob($dir . '/*.template');

        if ($files === false) {
            return [];
        }

        return array_map(
            fn($file) => basename($file, '.template'),
            $files
        );
    }

    /**
     * Check if a template exists.
     *
     * @param string $templateName Template name (without .template extension)
     * @return bool True if template exists
     */
    public static function templateExists(string $templateName): bool
    {
        $path = self::getTemplateDir() . '/' . $templateName . '.template';
        return file_exists($path);
    }

    /**
     * Get full path to a template file.
     *
     * @param string $templateName Template name (without .template extension)
     * @return string Absolute path to template file
     * @throws Exception If template not found
     */
    public static function getTemplatePath(string $templateName): string
    {
        $path = self::getTemplateDir() . '/' . $templateName . '.template';

        if (!file_exists($path)) {
            throw new Exception("Template not found: {$templateName}");
        }

        return $path;
    }
}
