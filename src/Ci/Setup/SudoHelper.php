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

namespace Horde\Components\Ci\Setup;

use Horde\Components\Exception;

/**
 * Helper for executing privileged operations via sudo helper script.
 *
 * Uses /usr/local/bin/horde-ci-sudo-helper for all operations requiring root.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class SudoHelper
{
    /**
     * Path to sudo helper script.
     */
    private const HELPER_SCRIPT = '/usr/local/bin/horde-ci-sudo-helper';

    /**
     * Check if sudo helper is available.
     *
     * @return bool
     */
    public static function isAvailable(): bool
    {
        return file_exists(self::HELPER_SCRIPT) && is_executable(self::HELPER_SCRIPT);
    }

    /**
     * Check if we can run sudo helper without password.
     *
     * @return bool
     */
    public static function canRunPasswordless(): bool
    {
        if (!self::isAvailable()) {
            return false;
        }

        $result = shell_exec('sudo -n ' . escapeshellarg(self::HELPER_SCRIPT) . ' check-php 8.4 2>&1');
        return $result !== null && !str_contains($result, 'password');
    }

    /**
     * Add ondrej PPA.
     *
     * @return bool True if successful
     * @throws Exception If helper not available
     */
    public static function addPpa(): bool
    {
        if (!self::isAvailable()) {
            throw new Exception('Sudo helper not found: ' . self::HELPER_SCRIPT);
        }

        $command = 'sudo ' . escapeshellarg(self::HELPER_SCRIPT) . ' add-ppa 2>&1';
        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);

        return $exitCode === 0;
    }

    /**
     * Install PHP version.
     *
     * @param string $version PHP version (e.g., '8.4')
     * @return bool True if successful
     * @throws Exception If helper not available
     */
    public static function installPhp(string $version): bool
    {
        if (!self::isAvailable()) {
            throw new Exception('Sudo helper not found: ' . self::HELPER_SCRIPT);
        }

        $command = sprintf(
            'sudo %s install-php %s 2>&1',
            escapeshellarg(self::HELPER_SCRIPT),
            escapeshellarg($version)
        );

        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);

        return $exitCode === 0;
    }

    /**
     * Install PHP extension.
     *
     * @param string $phpVersion PHP version (e.g., '8.4')
     * @param string $extension Extension name (e.g., 'curl')
     * @return bool True if successful
     * @throws Exception If helper not available
     */
    public static function installExtension(string $phpVersion, string $extension): bool
    {
        if (!self::isAvailable()) {
            throw new Exception('Sudo helper not found: ' . self::HELPER_SCRIPT);
        }

        $command = sprintf(
            'sudo %s install-extension %s %s 2>&1',
            escapeshellarg(self::HELPER_SCRIPT),
            escapeshellarg($phpVersion),
            escapeshellarg($extension)
        );

        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);

        return $exitCode === 0;
    }

    /**
     * Check if PHP version is installed.
     *
     * @param string $version PHP version (e.g., '8.4')
     * @return bool
     */
    public static function isPhpInstalled(string $version): bool
    {
        if (!self::isAvailable()) {
            // Fall back to direct check
            return file_exists("/usr/bin/php{$version}");
        }

        $command = sprintf(
            'sudo %s check-php %s 2>&1',
            escapeshellarg(self::HELPER_SCRIPT),
            escapeshellarg($version)
        );

        $result = shell_exec($command);
        return $result !== null && trim($result) === 'installed';
    }
}
