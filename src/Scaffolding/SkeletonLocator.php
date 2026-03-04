<?php
/**
 * Locates skeleton/template directories for component scaffolding.
 *
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

namespace Horde\Components\Scaffolding;

use Horde\Components\Exception;

/**
 * Locates skeleton/template directories for component scaffolding.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class SkeletonLocator
{
    /**
     * Locate the skeleton template directory for a given component type.
     *
     * Search order:
     * 1. Configured skeleton path (from config)
     * 2. ../git/horde/skeleton (relative to components, for applications)
     * 3. Built-in templates in components/data/scaffolding/ (for libraries/themes)
     *
     * @param string $type Component type: 'application', 'library', or 'theme'
     * @param string|null $configuredPath Optional configured skeleton path
     * @return string Path to skeleton template
     * @throws Exception If skeleton cannot be found
     */
    public static function locate(string $type, ?string $configuredPath = null): string
    {
        // Try configured path first
        if ($configuredPath !== null && is_dir($configuredPath)) {
            self::validateSkeleton($configuredPath, $type);
            return $configuredPath;
        }

        // For applications, try horde/skeleton repository
        if ($type === 'application') {
            $componentsDir = self::getComponentsDir();

            // Try ../git/horde/skeleton (common development layout)
            $skeletonPath = dirname($componentsDir) . '/git/horde/skeleton';
            if (is_dir($skeletonPath)) {
                self::validateSkeleton($skeletonPath, $type);
                return $skeletonPath;
            }

            // Try ../../skeleton (if components is in git/horde/components)
            $skeletonPath = dirname(dirname($componentsDir)) . '/skeleton';
            if (is_dir($skeletonPath)) {
                self::validateSkeleton($skeletonPath, $type);
                return $skeletonPath;
            }
        }

        // For libraries and themes, use built-in templates
        $builtInTemplate = self::getComponentsDir() . '/data/scaffolding/' . $type;
        if (is_dir($builtInTemplate)) {
            return $builtInTemplate;
        }

        // Not found
        throw new Exception(
            sprintf(
                'Could not locate %s template. Tried: %s',
                $type,
                self::getSearchPaths($type)
            )
        );
    }

    /**
     * Validate that a skeleton directory is complete and usable.
     *
     * @param string $path Path to skeleton
     * @param string $type Component type
     * @throws Exception If skeleton is invalid
     */
    public static function validateSkeleton(string $path, string $type): void
    {
        $required = [];

        switch ($type) {
            case 'application':
                $required = [
                    '.horde.yml',
                    'composer.json',
                    'src/Application.php',
                ];
                break;

            case 'library':
                $required = [
                    '.horde.yml',
                    'composer.json',
                    'src',
                ];
                break;

            case 'theme':
                $required = [
                    '.horde.yml',
                    'themes',
                ];
                break;
        }

        foreach ($required as $file) {
            $fullPath = $path . '/' . $file;
            if (!file_exists($fullPath)) {
                throw new Exception(
                    sprintf(
                        'Invalid %s skeleton at %s: missing %s',
                        $type,
                        $path,
                        $file
                    )
                );
            }
        }
    }

    /**
     * Get the components base directory.
     *
     * @return string Absolute path to components directory
     */
    private static function getComponentsDir(): string
    {
        // From src/Scaffolding, go up two levels to components root
        return dirname(__DIR__, 2);
    }

    /**
     * Get human-readable list of search paths for error messages.
     *
     * @param string $type Component type
     * @return string Formatted search path list
     */
    private static function getSearchPaths(string $type): string
    {
        $componentsDir = self::getComponentsDir();
        $paths = [];

        if ($type === 'application') {
            $paths[] = dirname($componentsDir) . '/git/horde/skeleton';
            $paths[] = dirname(dirname($componentsDir)) . '/skeleton';
        }

        $paths[] = $componentsDir . '/data/scaffolding/' . $type;

        return implode(', ', $paths);
    }

    /**
     * Check if a skeleton exists without throwing an exception.
     *
     * @param string $type Component type
     * @param string|null $configuredPath Optional configured skeleton path
     * @return bool True if skeleton exists and is valid
     */
    public static function exists(string $type, ?string $configuredPath = null): bool
    {
        try {
            self::locate($type, $configuredPath);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}
