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
use Horde\Components\Output;

/**
 * Installs PHP extensions for multiple PHP versions.
 *
 * Uses apt-get to install PHP extensions from ondrej PPA.
 * Employs a hybrid detection strategy: baseline + composer.json + static mapping.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class ExtensionInstaller
{
    /**
     * Baseline extensions installed for all components.
     */
    private const BASELINE_EXTENSIONS = [
        'cli',      // PHP CLI (usually installed with php-cli package)
        'common',   // Common files (timezone data, etc.)
        'curl',     // cURL
        'dom',      // DOM
        'intl',     // Internationalization (required by horde/core)
        'json',     // JSON (built-in in 8.0+, but package may exist)
        'mbstring', // Multibyte string
        'xml',      // XML
    ];

    /**
     * Component-specific extension mapping.
     *
     * @var array<string,array<string>>
     */
    private const COMPONENT_EXTENSIONS = [
        'imap_client' => ['imap'],
        'db' => ['pdo', 'mysql', 'pgsql', 'sqlite3'],
        'image' => ['gd'],
        'compress' => ['zip', 'bz2'],
        'ldap' => ['ldap'],
        'soap' => ['soap'],
    ];

    /**
     * Constructor.
     *
     * @param Output $output Output handler
     */
    public function __construct(
        private readonly Output $output
    ) {}

    /**
     * Detect required extensions for component.
     *
     * Uses hybrid strategy:
     * 1. Baseline extensions (always included)
     * 2. Extensions from composer.json require (ext-*)
     * 3. Static mapping based on component name
     *
     * @param string $componentPath Path to component
     * @param string $componentName Component name
     * @return array<string> Required extension names
     */
    public function detectExtensions(string $componentPath, string $componentName): array
    {
        $extensions = self::BASELINE_EXTENSIONS;

        // Add from composer.json
        $composerExtensions = $this->extractFromComposer($componentPath);
        $extensions = array_merge($extensions, $composerExtensions);

        // Add from static mapping
        $componentKey = strtolower($componentName);
        if (isset(self::COMPONENT_EXTENSIONS[$componentKey])) {
            $extensions = array_merge($extensions, self::COMPONENT_EXTENSIONS[$componentKey]);
        }

        // Deduplicate and sort
        $extensions = array_unique($extensions);
        sort($extensions);

        return $extensions;
    }

    /**
     * Extract extensions from composer.json.
     *
     * Looks for "ext-*" in require and require-dev.
     *
     * @param string $componentPath Path to component
     * @return array<string> Extension names (without ext- prefix)
     */
    private function extractFromComposer(string $componentPath): array
    {
        $composerFile = $componentPath . '/composer.json';
        if (!file_exists($composerFile)) {
            return [];
        }

        $content = file_get_contents($composerFile);
        if ($content === false) {
            return [];
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            return [];
        }

        $extensions = [];

        // Check require
        if (isset($data['require']) && is_array($data['require'])) {
            foreach (array_keys($data['require']) as $package) {
                if (is_string($package) && str_starts_with($package, 'ext-')) {
                    $extensions[] = substr($package, 4); // Remove "ext-" prefix
                }
            }
        }

        // Check require-dev
        if (isset($data['require-dev']) && is_array($data['require-dev'])) {
            foreach (array_keys($data['require-dev']) as $package) {
                if (is_string($package) && str_starts_with($package, 'ext-')) {
                    $extensions[] = substr($package, 4);
                }
            }
        }

        return $extensions;
    }

    /**
     * Install extensions for PHP versions.
     *
     * @param array<string> $extensions Extension names
     * @param array<string> $phpVersions PHP versions to install for
     * @return bool True if successful
     * @throws Exception If installation fails critically
     */
    public function install(array $extensions, array $phpVersions): bool
    {
        if (empty($extensions)) {
            $this->output->info('No extensions to install');
            return true;
        }

        if (empty($phpVersions)) {
            $this->output->warn('No PHP versions specified for extension installation');
            return true;
        }

        $this->output->info('Installing extensions: ' . implode(', ', $extensions));
        $this->output->info('For PHP versions: ' . implode(', ', $phpVersions));

        $failed = [];
        $succeeded = [];

        foreach ($phpVersions as $phpVersion) {
            foreach ($extensions as $extension) {
                $result = $this->installExtension($extension, $phpVersion);

                if ($result === true) {
                    $succeeded[] = "php{$phpVersion}-{$extension}";
                } elseif ($result === false) {
                    $failed[] = "php{$phpVersion}-{$extension}";
                }
                // null means skipped (already installed or not available)
            }
        }

        if (!empty($succeeded)) {
            $this->output->ok('Installed: ' . implode(', ', $succeeded));
        }

        if (!empty($failed)) {
            $this->output->warn('Failed to install: ' . implode(', ', $failed));
            // Don't throw exception - some extensions may not be available for all PHP versions
            // This is expected behavior (e.g., json is built-in in PHP 8.0+)
        }

        return true;
    }

    /**
     * Install single extension for specific PHP version.
     *
     * @param string $extension Extension name
     * @param string $phpVersion PHP version
     * @return bool|null True if installed, false if failed, null if skipped
     */
    private function installExtension(string $extension, string $phpVersion): ?bool
    {
        // Some extensions don't need explicit installation
        $builtIn = ['cli', 'common', 'json']; // json is built-in since PHP 8.0
        if (in_array($extension, $builtIn)) {
            return null; // Skip
        }

        $package = "php{$phpVersion}-{$extension}";

        // Check if already installed
        if ($this->isExtensionInstalled($extension, $phpVersion)) {
            return null; // Skip
        }

        // Try to install using sudo helper
        if (!SudoHelper::installExtension($phpVersion, $extension)) {
            $this->output->warn("Failed to install {$package}");
            return false;
        }

        return true;
    }

    /**
     * Check if extension is installed for PHP version.
     *
     * @param string $extension Extension name
     * @param string $phpVersion PHP version
     * @return bool
     */
    private function isExtensionInstalled(string $extension, string $phpVersion): bool
    {
        $php = "/usr/bin/php{$phpVersion}";

        if (!file_exists($php)) {
            return false;
        }

        // Check if extension is loaded
        $command = escapeshellarg($php) . " -m 2>/dev/null | grep -i " . escapeshellarg("^{$extension}$");
        $result = shell_exec($command);

        return $result !== null && trim($result) !== '';
    }

    /**
     * Get installed extensions for PHP version.
     *
     * @param string $phpVersion PHP version
     * @return array<string> Extension names
     */
    public function getInstalledExtensions(string $phpVersion): array
    {
        $php = "/usr/bin/php{$phpVersion}";

        if (!file_exists($php)) {
            return [];
        }

        $output = shell_exec(escapeshellarg($php) . " -m 2>/dev/null");
        if ($output === null) {
            return [];
        }

        $lines = explode("\n", trim($output));
        $extensions = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '' && $line !== '[PHP Modules]' && $line !== '[Zend Modules]') {
                $extensions[] = strtolower($line);
            }
        }

        sort($extensions);
        return $extensions;
    }
}
