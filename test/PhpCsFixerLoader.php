<?php

declare(strict_types=1);

/**
 * Helper class to conditionally load PHP-CS-Fixer for testing.
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

namespace Horde\Components\Test;

/**
 * PHP-CS-Fixer loader for tests.
 *
 * This class handles conditional loading of PHP-CS-Fixer classes for unit tests.
 * Since PHP-CS-Fixer is an external tool (not a Composer dependency), tests need
 * to check for its availability and load it from known locations.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class PhpCsFixerLoader
{
    /**
     * Well-known locations for PHP-CS-Fixer PHAR.
     */
    private const KNOWN_LOCATIONS = [
        '/usr/local/bin/php-cs-fixer',
        '/usr/bin/php-cs-fixer',
        '~/.local/bin/php-cs-fixer',
        '~/.phive/php-cs-fixer',
        '/opt/homebrew/bin/php-cs-fixer', // macOS Homebrew
        '/home/linuxbrew/.linuxbrew/bin/php-cs-fixer', // Linux Homebrew
    ];

    /**
     * Path to loaded PHAR, or null if not loaded.
     */
    private static ?string $pharPath = null;

    /**
     * Whether we've already attempted to load.
     */
    private static bool $loadAttempted = false;

    /**
     * Check if PHP-CS-Fixer is available and load it.
     *
     * @return bool True if PHP-CS-Fixer classes are available
     */
    public static function load(): bool
    {
        if (self::$loadAttempted) {
            return self::$pharPath !== null;
        }

        self::$loadAttempted = true;

        // Check if classes are already available (e.g., via Composer)
        if (class_exists('PhpCsFixer\AbstractFixer', false)) {
            return true;
        }

        // Try to find and load PHAR
        self::$pharPath = self::findPhar();

        if (self::$pharPath === null) {
            return false;
        }

        // Load the PHAR
        try {
            require_once 'phar://' . self::$pharPath . '/vendor/autoload.php';
            return class_exists('PhpCsFixer\AbstractFixer', false);
        } catch (\Throwable $e) {
            self::$pharPath = null;
            return false;
        }
    }

    /**
     * Find PHP-CS-Fixer PHAR in known locations.
     *
     * @return string|null Path to PHAR, or null if not found
     */
    private static function findPhar(): ?string
    {
        // Check environment variable first
        $envPath = getenv('PHP_CS_FIXER_PHAR');
        if ($envPath && self::isValidPhar($envPath)) {
            return $envPath;
        }

        // Check known locations
        foreach (self::KNOWN_LOCATIONS as $location) {
            // Expand tilde
            $expanded = str_replace('~', getenv('HOME') ?: '', $location);

            if (self::isValidPhar($expanded)) {
                return $expanded;
            }
        }

        // Try which command
        $whichPath = trim((string) shell_exec('which php-cs-fixer 2>/dev/null'));
        if ($whichPath && self::isValidPhar($whichPath)) {
            return $whichPath;
        }

        return null;
    }

    /**
     * Check if a file is a valid PHP-CS-Fixer PHAR.
     *
     * @param string $path Path to check
     *
     * @return bool True if valid PHAR
     */
    private static function isValidPhar(string $path): bool
    {
        if (!file_exists($path)) {
            return false;
        }

        if (!is_readable($path)) {
            return false;
        }

        // Check if it's a PHAR
        try {
            $phar = new \Phar($path);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Get the path to the loaded PHAR.
     *
     * @return string|null Path to PHAR, or null if not loaded
     */
    public static function getPharPath(): ?string
    {
        return self::$pharPath;
    }

    /**
     * Check if PHP-CS-Fixer is available (without loading).
     *
     * @return bool True if available
     */
    public static function isAvailable(): bool
    {
        if (class_exists('PhpCsFixer\AbstractFixer', false)) {
            return true;
        }

        return self::findPhar() !== null;
    }
}
