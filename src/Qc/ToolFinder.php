<?php

/**
 * Tool binary finder for quality check tasks.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

namespace Horde\Components\Qc;

use Horde\Components\Ci\Setup\PhpUnitMatrix;
use Phar;
use PharException;
use Throwable;

/**
 * Tool binary finder for quality check tasks.
 *
 * Provides centralized logic for locating tool binaries (PHPUnit, PHPStan,
 * PHP-CS-Fixer, etc.) and loading PHAR archives.
 *
 * Copyright 2026 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class ToolFinder
{
    /**
     * Constructor.
     *
     * @param string|null $componentPath Path to the component directory.
     *                                   If null or empty, uses current working directory.
     * @param string|null $toolsDir Optional directory containing tool binaries (highest priority).
     * @param string|null $lanePhpVersion Optional lane PHP version (e.g. "8.2")
     *                                    used by {@see resolvePhpUnitTag()} to pick a
     *                                    PHPUnit tag compatible with the target lane
     *                                    rather than the interpreter currently running
     *                                    horde-components. Null falls back to
     *                                    `PHP_MAJOR_VERSION.PHP_MINOR_VERSION` — the
     *                                    tool interpreter — preserving prior behavior
     *                                    for callers that predate the lane/tool PHP
     *                                    split.
     */
    public function __construct(
        private readonly ?string $componentPath,
        private readonly ?string $toolsDir = null,
        private readonly ?string $lanePhpVersion = null,
    ) {}

    /**
     * Get the component path, falling back to current directory if null.
     *
     * @return string The component path.
     */
    private function getComponentPath(): string
    {
        if (empty($this->componentPath)) {
            return getcwd() ?: '.';
        }
        return $this->componentPath;
    }

    /**
     * Find a tool binary in standard locations.
     *
     * Searches in this order:
     * 1. Tools directory (if provided via constructor)
     * 2. Component vendor/bin directory
     * 3. Component tools directory
     * 4. User's Phive directory (~/.phive)
     * 5. System directories (/usr/local/bin, /usr/bin)
     * 6. System PATH (using 'which' command)
     *
     * @param string $toolName Name of the tool (e.g., 'phpunit', 'phpstan')
     * @param bool $checkPhar Also check for .phar variants (default: true)
     *
     * @return string|null Path to the tool binary or null if not found.
     */
    public function findBinary(string $toolName, bool $checkPhar = true): ?string
    {
        $componentPath = $this->getComponentPath();

        // Build list of possible locations
        $locations = [];

        // 0. Tools directory (highest priority - for CI mode)
        if (!empty($this->toolsDir)) {
            // For PHPUnit, ask PhpUnitMatrix for the right tag based on the
            // component's composer.json constraint and the running PHP. Falls
            // back to "highest compatible" when the component does not declare
            // a constraint (matrix returns the highest tag for the PHP).
            if ($toolName === 'phpunit' && $checkPhar) {
                $tag = $this->resolvePhpUnitTag();
                if ($tag !== null) {
                    $locations[] = $this->toolsDir . '/phpunit-' . $tag . '.phar';
                }
            }

            // Standard tool names
            $locations[] = $this->toolsDir . '/' . $toolName;
            if ($checkPhar) {
                $locations[] = $this->toolsDir . '/' . $toolName . '.phar';
            }
        }

        // 1. Component vendor/bin
        $locations[] = $componentPath . '/vendor/bin/' . $toolName;
        if ($checkPhar) {
            $locations[] = $componentPath . '/vendor/bin/' . $toolName . '.phar';
        }

        // 2. Component tools directory
        $locations[] = $componentPath . '/tools/' . $toolName;
        if ($checkPhar) {
            $locations[] = $componentPath . '/tools/' . $toolName . '.phar';
        }

        // 3. User's Phive directory
        if (!empty($_SERVER['HOME'])) {
            $locations[] = $_SERVER['HOME'] . '/.phive/' . $toolName;
            if ($checkPhar) {
                $locations[] = $_SERVER['HOME'] . '/.phive/' . $toolName . '.phar';
            }
        }

        // 4. System directories
        $locations[] = '/usr/local/bin/' . $toolName;
        if ($checkPhar) {
            $locations[] = '/usr/local/bin/' . $toolName . '.phar';
        }
        $locations[] = '/usr/bin/' . $toolName;
        if ($checkPhar) {
            $locations[] = '/usr/bin/' . $toolName . '.phar';
        }

        // Check each location
        foreach ($locations as $path) {
            if (file_exists($path) && is_executable($path)) {
                return $path;
            }
        }

        // 5. Fallback to PATH
        $which = trim((string) shell_exec('which ' . escapeshellarg($toolName) . ' 2>/dev/null'));
        if (!empty($which) && file_exists($which)) {
            return $which;
        }

        return null;
    }

    /**
     * Find a tool binary with additional search locations.
     *
     * Like findBinary() but allows specifying additional paths to check
     * before the standard locations. Useful for tools with special
     * installation patterns (e.g., monorepo vendor, Windows paths).
     *
     * @param string $toolName Name of the tool.
     * @param array<string> $additionalPaths Additional paths to check first.
     * @param bool $checkPhar Also check for .phar variants (default: true)
     *
     * @return string|null Path to the tool binary or null if not found.
     */
    public function findBinaryWithPaths(
        string $toolName,
        array $additionalPaths,
        bool $checkPhar = true
    ): ?string {
        // Check additional paths first
        foreach ($additionalPaths as $path) {
            if ($path && file_exists($path) && is_executable($path)) {
                return $path;
            }
        }

        // Fall back to standard search
        return $this->findBinary($toolName, $checkPhar);
    }

    /**
     * Attempt to load a PHAR file.
     *
     * Uses Phar::loadPhar() to validate the PHAR format, then requires the
     * file to execute its stub and register autoloaders. This works regardless
     * of file extension and properly detects PHAR files with __HALT_COMPILER()
     * markers far into the file.
     *
     * The method uses PHP's built-in PHAR validation which checks for the
     * GBMB signature at the end of the file, making it reliable for any
     * valid PHAR regardless of size or structure.
     *
     * This method is idempotent - it's safe to call multiple times on the
     * same PHAR file.
     *
     * @param string $path Path to the potential PHAR file.
     *
     * @return bool True if the file was successfully loaded as a PHAR.
     */
    public function loadPhar(string $path): bool
    {
        if (!is_readable($path)) {
            return false;
        }

        try {
            // First, validate it's a PHAR using PHP's built-in validation
            // This checks the GBMB signature at the end of the file
            Phar::loadPhar($path);

            // Then require it to execute the stub and register autoloaders
            require_once $path;

            return true;
        } catch (PharException $e) {
            // Not a valid PHAR file
            return false;
        } catch (Throwable $e) {
            // Other errors (permissions, require_once already called, etc.)
            // Note: require_once is safe to call multiple times
            return false;
        }
    }

    /**
     * Check if a file is a valid PHAR archive.
     *
     * This only validates without loading/executing the PHAR.
     * Use loadPhar() if you need to actually use the PHAR.
     *
     * @param string $path Path to check.
     *
     * @return bool True if the file is a valid PHAR.
     */
    public function isPhar(string $path): bool
    {
        if (!is_readable($path)) {
            return false;
        }

        try {
            Phar::loadPhar($path);
            return true;
        } catch (PharException $e) {
            return false;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Load a tool and make its classes available.
     *
     * Handles both PHAR and Composer-installed tools.
     * After successful loading, the tool's classes will be available via autoloading.
     *
     * @param string $toolPath Path to the tool binary.
     *
     * @return bool True if the tool was successfully loaded.
     */
    public function loadTool(string $toolPath): bool
    {
        if (!is_readable($toolPath)) {
            return false;
        }

        try {
            // Path A: PHAR archive
            if ($this->isPhar($toolPath)) {
                // Require the PHAR file itself
                // The stub will detect it's not being run as CLI (via __FILE__ check)
                // and will only register autoloader without executing
                require_once $toolPath;
                return true;
            }

            // Path B: Composer-installed (regular PHP script)
            $autoloader = $this->findAutoloaderForTool($toolPath);
            if ($autoloader && file_exists($autoloader)) {
                require_once $autoloader;
                return true;
            }
        } catch (Throwable $e) {
            return false;
        }

        return false;
    }

    /**
     * Find the Composer autoloader for a tool binary.
     *
     * Handles several common patterns:
     * - vendor/bin/tool -> vendor/autoload.php
     * - vendor/package/package/bin/tool -> vendor/autoload.php (via ../../..)
     * - ~/.config/composer/vendor/bin/tool -> ~/.config/composer/vendor/autoload.php
     *
     * @param string $toolPath Path to the tool binary.
     *
     * @return string|null Path to autoloader or null if not found.
     */
    private function findAutoloaderForTool(string $toolPath): ?string
    {
        $dir = dirname($toolPath);

        // Pattern 1: tool is in vendor/bin/ -> autoloader is vendor/autoload.php
        // e.g., /path/vendor/bin/phpunit -> /path/vendor/autoload.php
        if (basename($dir) === 'bin' && basename(dirname($dir)) === 'vendor') {
            $autoloader = dirname($dir) . '/autoload.php';
            if (file_exists($autoloader)) {
                return $autoloader;
            }
        }

        // Pattern 2: tool is in vendor/package/package/bin/ or similar
        // e.g., /path/vendor/phpunit/phpunit/bin/phpunit -> /path/vendor/autoload.php
        // Walk up directories looking for vendor/autoload.php
        $checkDir = $dir;
        for ($i = 0; $i < 5; $i++) {
            if (basename($checkDir) === 'vendor') {
                $autoloader = $checkDir . '/autoload.php';
                if (file_exists($autoloader)) {
                    return $autoloader;
                }
                break;
            }
            $parent = dirname($checkDir);
            if ($parent === $checkDir) {
                break; // Reached filesystem root
            }
            $checkDir = $parent;
        }

        // Pattern 3: Check for Composer-generated proxy script marker
        // These scripts contain: $GLOBALS['_composer_autoload_path'] = __DIR__ . '/../autoload.php';
        if (is_readable($toolPath)) {
            $content = file_get_contents($toolPath, false, null, 0, 4096);
            if ($content && str_contains($content, '_composer_autoload_path')) {
                // Standard Composer proxy: autoloader is ../autoload.php relative to bin dir
                $autoloader = dirname($dir) . '/autoload.php';
                if (file_exists($autoloader)) {
                    return $autoloader;
                }
            }
        }

        return null;
    }

    /**
     * Resolve which PHPUnit tag this lane should run via PhpUnitMatrix.
     *
     * Reads the component's composer.json (if present) and intersects the
     * declared phpunit/phpunit constraint with the lane's running PHP.
     *
     * @return string|null Tag like "12.5", or null when neither the
     *                    constraint nor the PHP version yields a hit
     *                    (caller's lookup falls through to vendor/bin etc.).
     */
    private function resolvePhpUnitTag(): ?string
    {
        $matrix = new PhpUnitMatrix();
        // Prefer the lane's PHP when provided. Otherwise fall back to
        // the current interpreter — same behavior as before this class
        // learned about lane/tool PHP separation. Callers under the CI
        // harness (Task\Unit via --php) always pass a lane version;
        // ad-hoc callers (local `qc unit` without --php) get the tool
        // PHP which equals the runtime PHP.
        $phpVersion = $this->lanePhpVersion
            ?? PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;

        $composerJson = $this->getComponentPath() . '/composer.json';
        if (is_file($composerJson)) {
            try {
                return $matrix->pickWithSource($composerJson, $phpVersion)->tag;
            } catch (Throwable) {
                // Fall through to constraint-less lookup below.
            }
        }
        return $matrix->pick(null, $phpVersion);
    }
}
