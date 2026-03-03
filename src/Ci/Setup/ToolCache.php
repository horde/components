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

use Horde\Components\Output;
use Horde\Components\Exception;

/**
 * Manages cached QC tool binaries (PHPUnit, PHPStan, PHP-CS-Fixer).
 *
 * Downloads and caches PHAR files for quality check tools, enabling:
 * - Version-specific tools per PHP version
 * - No need to install tools in every lane's vendor/
 * - Fast setup and consistent tool versions
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class ToolCache
{
    /**
     * PHPUnit version mapping for PHP versions.
     *
     * PHPUnit 11.x for PHP 8.2-8.3
     * PHPUnit 12.x for PHP 8.4+
     */
    private const PHPUNIT_VERSIONS = [
        '8.2' => '11.5',
        '8.3' => '11.5',
        '8.4' => '12.5',
        '8.5' => '12.5',
    ];

    /**
     * Constructor.
     *
     * @param string $cacheDir Directory to store tool PHARs
     * @param Output $output Output handler
     */
    public function __construct(
        private readonly string $cacheDir,
        private readonly Output $output
    ) {}

    /**
     * Ensure all required tools are cached for given PHP versions.
     *
     * @param array<string> $phpVersions PHP versions to prepare tools for
     * @throws Exception If download fails
     */
    public function ensureAllTools(array $phpVersions): void
    {
        // Create cache directory
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }

        // Download PHPUnit for each PHP version
        foreach ($phpVersions as $phpVersion) {
            $this->ensurePhpUnit($phpVersion);
        }

        // Download PHPStan (single version for all PHP versions)
        $this->ensurePhpStan();

        // Download PHP-CS-Fixer (single version, only runs on PHP 8.4)
        $this->ensurePhpCsFixer();
    }

    /**
     * Ensure PHPUnit is cached for specific PHP version.
     *
     * @param string $phpVersion PHP version (e.g., "8.4")
     * @return string Path to PHPUnit PHAR
     * @throws Exception If version not supported or download fails
     */
    public function ensurePhpUnit(string $phpVersion): string
    {
        if (!isset(self::PHPUNIT_VERSIONS[$phpVersion])) {
            throw new Exception("No PHPUnit version mapped for PHP {$phpVersion}");
        }

        $phpunitVersion = self::PHPUNIT_VERSIONS[$phpVersion];
        $pharName = "phpunit-{$phpunitVersion}.phar";
        $pharPath = $this->cacheDir . '/' . $pharName;

        if (file_exists($pharPath)) {
            $this->output->plain("  ✓ PHPUnit {$phpunitVersion} (cached)");
            return $pharPath;
        }

        $this->output->info("  ⬇ Downloading PHPUnit {$phpunitVersion}...");

        $url = "https://phar.phpunit.de/phpunit-{$phpunitVersion}.phar";
        $this->downloadPhar($url, $pharPath);

        $this->output->ok("  ✓ PHPUnit {$phpunitVersion}");
        return $pharPath;
    }

    /**
     * Ensure PHPStan is cached.
     *
     * @return string Path to PHPStan PHAR
     * @throws Exception If download fails
     */
    public function ensurePhpStan(): string
    {
        $pharName = 'phpstan.phar';
        $pharPath = $this->cacheDir . '/' . $pharName;

        if (file_exists($pharPath)) {
            $this->output->plain("  ✓ PHPStan (cached)");
            return $pharPath;
        }

        $this->output->info("  ⬇ Downloading PHPStan (latest)...");

        $url = 'https://github.com/phpstan/phpstan/releases/latest/download/phpstan.phar';
        $this->downloadPhar($url, $pharPath);

        $this->output->ok("  ✓ PHPStan");
        return $pharPath;
    }

    /**
     * Ensure PHP-CS-Fixer is cached.
     *
     * @return string Path to PHP-CS-Fixer PHAR
     * @throws Exception If download fails
     */
    public function ensurePhpCsFixer(): string
    {
        $pharName = 'php-cs-fixer.phar';
        $pharPath = $this->cacheDir . '/' . $pharName;

        if (file_exists($pharPath)) {
            $this->output->plain("  ✓ PHP-CS-Fixer (cached)");
            return $pharPath;
        }

        $this->output->info("  ⬇ Downloading PHP-CS-Fixer (latest)...");

        $url = 'https://github.com/PHP-CS-Fixer/PHP-CS-Fixer/releases/latest/download/php-cs-fixer.phar';
        $this->downloadPhar($url, $pharPath);

        $this->output->ok("  ✓ PHP-CS-Fixer");
        return $pharPath;
    }

    /**
     * Get the path to PHPUnit for a specific PHP version.
     *
     * @param string $phpVersion PHP version (e.g., "8.4")
     * @return string|null Path to PHPUnit PHAR or null if not cached
     */
    public function getPhpUnitPath(string $phpVersion): ?string
    {
        if (!isset(self::PHPUNIT_VERSIONS[$phpVersion])) {
            return null;
        }

        $phpunitVersion = self::PHPUNIT_VERSIONS[$phpVersion];
        $pharPath = $this->cacheDir . "/phpunit-{$phpunitVersion}.phar";

        return file_exists($pharPath) ? $pharPath : null;
    }

    /**
     * Get the path to PHPStan.
     *
     * @return string|null Path to PHPStan PHAR or null if not cached
     */
    public function getPhpStanPath(): ?string
    {
        $pharPath = $this->cacheDir . '/phpstan.phar';
        return file_exists($pharPath) ? $pharPath : null;
    }

    /**
     * Get the path to PHP-CS-Fixer.
     *
     * @return string|null Path to PHP-CS-Fixer PHAR or null if not cached
     */
    public function getPhpCsFixerPath(): ?string
    {
        $pharPath = $this->cacheDir . '/php-cs-fixer.phar';
        return file_exists($pharPath) ? $pharPath : null;
    }

    /**
     * Download a PHAR file.
     *
     * @param string $url URL to download from
     * @param string $destination Local path to save to
     * @throws Exception If download fails
     */
    private function downloadPhar(string $url, string $destination): void
    {
        // Use curl for reliable downloads with redirects
        $command = sprintf(
            'curl -L -f -o %s %s 2>&1',
            escapeshellarg($destination),
            escapeshellarg($url)
        );

        exec($command, $output, $exitCode);

        if ($exitCode !== 0) {
            throw new Exception("Failed to download {$url}: " . implode("\n", $output));
        }

        // Verify it's a valid PHAR
        if (!$this->isValidPhar($destination)) {
            unlink($destination);
            throw new Exception("Downloaded file is not a valid PHAR: {$url}");
        }

        // Make executable
        chmod($destination, 0755);
    }

    /**
     * Check if a file is a valid PHAR.
     *
     * @param string $path Path to check
     * @return bool True if valid PHAR
     */
    private function isValidPhar(string $path): bool
    {
        if (!is_readable($path)) {
            return false;
        }

        try {
            \Phar::loadPhar($path);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
