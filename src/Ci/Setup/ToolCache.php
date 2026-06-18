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
use Phar;
use Throwable;

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
     * Ensure all required tools are cached for the given PHPUnit tags.
     *
     * @param array<string> $phpUnitTags PHPUnit major.minor tags to download
     *                                   (e.g. ["11.5", "12.5"]). Caller is
     *                                   responsible for picking these via
     *                                   {@see PhpUnitMatrix}; this method
     *                                   does not do version selection.
     * @throws Exception If download fails
     */
    public function ensureAllTools(array $phpUnitTags): void
    {
        // Create cache directory
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0o755, true);
        }

        // Download each requested PHPUnit tag, deduped.
        foreach (array_unique($phpUnitTags) as $tag) {
            $this->ensurePhpUnit($tag);
        }

        // Download PHPStan (single version for all PHP versions)
        $this->ensurePhpStan();

        // Download PHP-CS-Fixer (single version, only runs on PHP 8.4)
        $this->ensurePhpCsFixer();
    }

    /**
     * Ensure PHPUnit is cached at the given tag.
     *
     * @param string $tag PHPUnit major.minor tag (e.g. "12.5")
     * @return string Path to PHPUnit PHAR
     * @throws Exception If download fails
     */
    public function ensurePhpUnit(string $tag): string
    {
        $pharName = "phpunit-{$tag}.phar";
        $pharPath = $this->cacheDir . '/' . $pharName;

        if (file_exists($pharPath)) {
            $this->output->plain("  PHPUnit {$tag} (cached)");
            return $pharPath;
        }

        $this->output->info("  Downloading PHPUnit {$tag}...");

        $url = "https://phar.phpunit.de/phpunit-{$tag}.phar";
        $this->downloadPhar($url, $pharPath);

        $this->output->ok("  PHPUnit {$tag}");
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
            $this->output->plain("  PHPStan (cached)");
            return $pharPath;
        }

        $this->output->info("  Downloading PHPStan (latest)...");

        $url = 'https://github.com/phpstan/phpstan/releases/latest/download/phpstan.phar';
        $this->downloadPhar($url, $pharPath);

        $this->output->ok("  PHPStan");
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
            $this->output->plain("  PHP-CS-Fixer (cached)");
            return $pharPath;
        }

        $this->output->info("  Downloading PHP-CS-Fixer (latest)...");

        $url = 'https://github.com/PHP-CS-Fixer/PHP-CS-Fixer/releases/latest/download/php-cs-fixer.phar';
        $this->downloadPhar($url, $pharPath);

        $this->output->ok("  PHP-CS-Fixer");
        return $pharPath;
    }

    /**
     * Get the path to a cached PHPUnit PHAR by tag.
     *
     * @param string $tag PHPUnit major.minor tag (e.g. "12.5")
     * @return string|null Path to PHPUnit PHAR or null if not cached
     */
    public function getPhpUnitPath(string $tag): ?string
    {
        $pharPath = $this->cacheDir . "/phpunit-{$tag}.phar";
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
        chmod($destination, 0o755);
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
            Phar::loadPhar($path);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}
