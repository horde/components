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
     * Cached result of isRoot() check.
     */
    private static ?bool $isRoot = null;

    /**
     * Check if running as root user.
     *
     * @return bool
     */
    public static function isRoot(): bool
    {
        if (self::$isRoot === null) {
            self::$isRoot = posix_geteuid() === 0;
        }
        return self::$isRoot;
    }

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
        // Root doesn't need sudo
        if (self::isRoot()) {
            return true;
        }

        if (!self::isAvailable()) {
            return false;
        }

        $result = shell_exec('sudo -n ' . escapeshellarg(self::HELPER_SCRIPT) . ' check-php 8.4 2>&1');
        return $result !== null && !str_contains($result, 'password');
    }

    /**
     * Add ondrej PPA.
     *
     * @return array{success: bool, output: string, exitCode: int}
     *         Combined stdout/stderr captured from the helper is in
     *         `output` so callers can surface apt's real complaint
     *         when the helper exits non-zero.
     * @throws Exception If helper not available
     */
    public static function addPpa(): array
    {
        if (!self::isAvailable()) {
            throw new Exception('Sudo helper not found: ' . self::HELPER_SCRIPT);
        }

        $sudo = self::isRoot() ? '' : 'sudo ';
        $command = $sudo . escapeshellarg(self::HELPER_SCRIPT) . ' add-ppa 2>&1';
        return self::runCommand($command);
    }

    /**
     * Install PHP version.
     *
     * @param string $version PHP version (e.g., '8.4')
     * @return array{success: bool, output: string, exitCode: int}
     *         Combined stdout/stderr from apt is in `output` so
     *         callers can print the real "Unable to locate package"
     *         (or similar) message on failure instead of a bare
     *         "Failed to install PHP X".
     * @throws Exception If helper not available
     */
    public static function installPhp(string $version): array
    {
        if (!self::isAvailable()) {
            throw new Exception('Sudo helper not found: ' . self::HELPER_SCRIPT);
        }

        $sudo = self::isRoot() ? '' : 'sudo ';
        $command = sprintf(
            '%s%s install-php %s 2>&1',
            $sudo,
            escapeshellarg(self::HELPER_SCRIPT),
            escapeshellarg($version)
        );

        return self::runCommand($command);
    }

    /**
     * Install PHP extension.
     *
     * @param string $phpVersion PHP version (e.g., '8.4')
     * @param string $extension Extension name (e.g., 'curl')
     * @return array{success: bool, output: string, exitCode: int}
     *         Combined stdout/stderr from apt is in `output`.
     * @throws Exception If helper not available
     */
    public static function installExtension(string $phpVersion, string $extension): array
    {
        if (!self::isAvailable()) {
            throw new Exception('Sudo helper not found: ' . self::HELPER_SCRIPT);
        }

        $sudo = self::isRoot() ? '' : 'sudo ';
        $command = sprintf(
            '%s%s install-extension %s %s 2>&1',
            $sudo,
            escapeshellarg(self::HELPER_SCRIPT),
            escapeshellarg($phpVersion),
            escapeshellarg($extension)
        );

        return self::runCommand($command);
    }

    /**
     * Run a helper command and capture stdout/stderr plus exit code.
     *
     * Centralised so all privileged wrappers surface diagnostics the
     * same way. Callers get the exact bytes apt (or the helper's own
     * validation) printed; a bare boolean would strand them.
     *
     * @param string $command Shell command already redirecting 2>&1
     * @return array{success: bool, output: string, exitCode: int}
     */
    private static function runCommand(string $command): array
    {
        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);

        return [
            'success' => $exitCode === 0,
            'output' => implode("\n", $output),
            'exitCode' => $exitCode,
        ];
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

        $sudo = self::isRoot() ? '' : 'sudo ';
        $command = sprintf(
            '%s%s check-php %s 2>&1',
            $sudo,
            escapeshellarg(self::HELPER_SCRIPT),
            escapeshellarg($version)
        );

        $result = shell_exec($command);
        return $result !== null && trim($result) === 'installed';
    }
}
