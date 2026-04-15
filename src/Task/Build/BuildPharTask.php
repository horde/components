<?php

/**
 * Copyright 2024-2026 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @author   Ralf Lang <lang@b1-systems.de>
 * @category Horde
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 * @package  Components
 */

declare(strict_types=1);

namespace Horde\Components\Task\Build;

use DirectoryIterator;
use Horde\Components\Helper\Shell as ShellHelper;
use Horde\Components\Output;
use Horde\Components\Task\AbstractTask;
use Horde\Components\Task\Context;
use Horde\Components\Task\Result;
use Exception;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Build a PHAR archive using Box.
 *
 * Builds PHAR at location specified in box.json.dist (e.g., 'build/horde-components.phar')
 * and uploads to GitHub with basename only (e.g., 'horde-components.phar'). Skips if
 * box.json.dist doesn't exist.
 *
 * Optional Options:
 * - phar_name (string) - Override PHAR filename
 *
 * Emitted Facts:
 * - phar.built (bool) - True if PHAR built
 * - phar.file_path (string) - Absolute path to PHAR file on disk
 * - phar.file_name (string) - Basename for GitHub upload (no directory prefix)
 * - phar.file_size (int) - PHAR size in bytes
 *
 * @author    Ralf Lang <lang@b1-systems.de>
 * @category  Horde
 * @copyright 2024-2026 Horde LLC
 * @license   http://www.horde.org/licenses/lgpl21 LGPL 2.1
 * @package   Components
 */
class BuildPharTask extends AbstractTask
{
    public function __construct(
        Output $output,
        private readonly ShellHelper $shellHelper,
        bool $pretend = false
    ) {
        parent::__construct($output, $pretend);
    }

    public function shouldSkip(Context $context): bool
    {
        // Skip if no box.json.dist (not a PHAR-enabled component)
        $componentPath = $context->getComponentPath();
        return !file_exists($componentPath . '/box.json.dist');
    }


    public function getName(): string
    {
        return "Build Phar";
    }
    public function run(Context $context): Result
    {
        $componentPath = $context->getComponentPath();
        $boxConfig = $componentPath . '/box.json.dist';

        // Validate box.json.dist exists
        if (!file_exists($boxConfig)) {
            throw new Exception('box.json.dist not found');
        }

        // Check box utility available
        $result = $this->shellHelper->run('which box', $componentPath);
        if ($result->getReturnValue() !== 0) {
            throw new Exception(
                'Box utility not found in PATH. Install with: composer global require humbug/box'
            );
        }

        // Determine PHAR file locations
        $overrideName = $context->getOption('phar_name');

        if ($overrideName === null) {
            // Read from box.json.dist
            $boxJson = json_decode(file_get_contents($boxConfig), true);
            $localPharFilename = $boxJson['output'] ?? 'dist.phar';
            $localPath = $componentPath . '/' . $localPharFilename;
            $uploadedFileName = basename($localPharFilename);
        } else {
            $localPath = $componentPath . '/' . $overrideName;
            $localPharFilename = $overrideName;
            $uploadedFileName = basename($overrideName);
        }

        // Build PHAR
        if (!$this->pretend) {
            // Box does not follow symlinks. Replace symlinked vendor
            // directories with real copies so they are included in the PHAR.
            $resolvedSymlinks = $this->resolveVendorSymlinks($componentPath);
            if ($resolvedSymlinks) {
                $this->output->info(
                    'Resolved ' . count($resolvedSymlinks) . ' vendor symlink(s) for PHAR build'
                );
            }

            try {
                $result = $this->shellHelper->run('box compile', $componentPath);

                if ($result->getReturnValue() !== 0) {
                    throw new Exception('Failed to build PHAR: ' . $result->getOutputString());
                }

                // Verify PHAR was created
                if (!file_exists($localPath)) {
                    throw new Exception("PHAR not found after build: {$localPath}");
                }

                // Quick sanity check: PHAR is valid
                $result = $this->shellHelper->run("php {$localPath} version 2>&1", $componentPath);
                if ($result->getReturnValue() !== 0) {
                    throw new Exception('Built PHAR is not valid/executable');
                }
            } finally {
                $this->restoreVendorSymlinks($resolvedSymlinks);
            }
        }

        $pharSize = $this->pretend ? 0 : filesize($localPath);

        // Emit facts
        $context->setFact('phar.built', true);
        $context->setFact('phar.file_path', $localPath);
        $context->setFact('phar.file_name', $uploadedFileName);
        $context->setFact('phar.file_size', $pharSize);

        $action = $this->pretend ? 'Would build' : 'Built';
        $sizeInfo = $this->pretend ? '' : ' (' . $this->formatBytes($pharSize) . ')';

        return Result::success(
            "{$action} PHAR: {$uploadedFileName}{$sizeInfo}",
            [
                'phar_path' => $localPath,
                'phar_name' => $uploadedFileName,
                'phar_size' => $pharSize,
            ]
        );
    }

    /**
     * Format bytes to human-readable size.
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= (1 << (10 * $pow));

        return round($bytes, 2) . ' ' . $units[$pow];
    }

    /**
     * Replace symlinked vendor directories with real file copies.
     *
     * Box does not follow symlinks, so symlinked packages would be
     * silently excluded from the PHAR. This scans vendor/ for symlinked
     * directories two levels deep (vendor/org/package) and replaces each
     * with a recursive copy of the target.
     *
     * @return array<string, string> Map of link path => original target
     */
    private function resolveVendorSymlinks(string $componentPath): array
    {
        $vendorDir = $componentPath . '/vendor';
        $resolved = [];

        if (!is_dir($vendorDir)) {
            return $resolved;
        }

        foreach (new DirectoryIterator($vendorDir) as $org) {
            if ($org->isDot() || !$org->isDir()) {
                continue;
            }
            foreach (new DirectoryIterator($org->getPathname()) as $pkg) {
                if ($pkg->isDot() || !$pkg->isLink()) {
                    continue;
                }
                $linkPath = $pkg->getPathname();
                $target = realpath($linkPath);
                if ($target === false || !is_dir($target)) {
                    continue;
                }

                $resolved[$linkPath] = $target;
                unlink($linkPath);
                $this->copyDirectory($target, $linkPath);
            }
        }

        return $resolved;
    }

    /**
     * Restore symlinks after PHAR build.
     *
     * @param array<string, string> $resolved Map from resolveVendorSymlinks
     */
    private function restoreVendorSymlinks(array $resolved): void
    {
        foreach ($resolved as $linkPath => $target) {
            if (is_dir($linkPath) && !is_link($linkPath)) {
                $this->removeDirectory($linkPath);
            }
            symlink($target, $linkPath);
        }
    }

    /**
     * Recursively copy a directory, skipping dev-only subdirectories.
     *
     * Excludes .git, vendor, test, tests, and build directories since
     * Box would also exclude them from the PHAR.
     */
    private function copyDirectory(string $source, string $dest): void
    {
        $skip = ['.git', 'vendor', 'test', 'tests', 'build'];
        mkdir($dest, 0755);

        foreach (new DirectoryIterator($source) as $item) {
            if ($item->isDot()) {
                continue;
            }
            $targetPath = $dest . '/' . $item->getFilename();
            if ($item->isDir()) {
                if (in_array($item->getFilename(), $skip, true)) {
                    continue;
                }
                $this->copyDirectory($item->getPathname(), $targetPath);
            } else {
                copy($item->getPathname(), $targetPath);
            }
        }
    }

    /**
     * Recursively remove a directory.
     */
    private function removeDirectory(string $dir): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($dir);
    }
}
