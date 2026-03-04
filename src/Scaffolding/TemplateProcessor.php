<?php
/**
 * Processes template files by copying and replacing placeholders.
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
use Horde\Components\Output;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Processes template files by copying and replacing placeholders.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class TemplateProcessor
{
    /**
     * Constructor.
     *
     * @param Output $output Output handler
     */
    public function __construct(
        private readonly Output $output
    ) {}

    /**
     * Process template directory: copy files and replace placeholders.
     *
     * @param string $sourcePath Source template directory
     * @param string $targetPath Target directory (must exist and be empty)
     * @param array $replacements Placeholder replacement map
     * @param bool $force Overwrite existing files
     * @throws Exception If processing fails
     */
    public function process(
        string $sourcePath,
        string $targetPath,
        array $replacements,
        bool $force = false
    ): void {
        // Validate source
        if (!is_dir($sourcePath)) {
            throw new Exception("Source template not found: {$sourcePath}");
        }

        // Validate target
        if (!is_dir($targetPath)) {
            throw new Exception("Target directory not found: {$targetPath}");
        }

        // Check if target is empty (unless force)
        if (!$force && $this->directoryHasFiles($targetPath)) {
            throw new Exception(
                "Target directory is not empty. Use --force to overwrite."
            );
        }

        $this->output->info("Copying template from: {$sourcePath}");
        $this->output->info("To: {$targetPath}");

        // Process all files
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $sourcePath,
                RecursiveDirectoryIterator::SKIP_DOTS
            ),
            RecursiveIteratorIterator::SELF_FIRST
        );

        $filesProcessed = 0;
        $directoriesCreated = 0;

        foreach ($iterator as $item) {
            $relativePath = substr($item->getPathname(), strlen($sourcePath) + 1);

            // Skip certain paths
            if ($this->shouldSkip($relativePath)) {
                continue;
            }

            // Apply replacements to path itself
            $targetRelativePath = $this->replaceInPath($relativePath, $replacements);
            $targetItemPath = $targetPath . '/' . $targetRelativePath;

            if ($item->isDir()) {
                // Create directory
                if (!is_dir($targetItemPath)) {
                    mkdir($targetItemPath, 0755, true);
                    $directoriesCreated++;
                }
            } else {
                // Copy and process file
                $this->processFile(
                    $item->getPathname(),
                    $targetItemPath,
                    $replacements
                );
                $filesProcessed++;
            }
        }

        $this->output->ok(
            sprintf(
                'Created %d directories and processed %d files',
                $directoriesCreated,
                $filesProcessed
            )
        );
    }

    /**
     * Process a single file: copy and replace placeholders.
     *
     * @param string $sourcePath Source file path
     * @param string $targetPath Target file path
     * @param array $replacements Replacement map
     */
    private function processFile(
        string $sourcePath,
        string $targetPath,
        array $replacements
    ): void {
        // Ensure target directory exists
        $targetDir = dirname($targetPath);
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        // Check if file is binary
        if ($this->isBinaryFile($sourcePath)) {
            // Binary file: just copy
            copy($sourcePath, $targetPath);
            return;
        }

        // Text file: read, replace, write
        $content = file_get_contents($sourcePath);

        // Apply replacements
        $content = str_replace(
            array_keys($replacements),
            array_values($replacements),
            $content
        );

        // Write to target
        file_put_contents($targetPath, $content);

        // Preserve permissions
        $perms = fileperms($sourcePath);
        chmod($targetPath, $perms);
    }

    /**
     * Apply replacements to a file/directory path.
     *
     * @param string $path Original path
     * @param array $replacements Replacement map
     * @return string Transformed path
     */
    private function replaceInPath(string $path, array $replacements): string
    {
        // Only replace certain path-safe placeholders
        $pathReplacements = [
            'skeleton' => $replacements['skeleton'] ?? 'skeleton',
            'Skeleton' => $replacements['skeleton'] ?? 'skeleton',
        ];

        return str_replace(
            array_keys($pathReplacements),
            array_values($pathReplacements),
            $path
        );
    }

    /**
     * Check if a file/directory should be skipped.
     *
     * @param string $relativePath Relative path from source root
     * @return bool True if should skip
     */
    private function shouldSkip(string $relativePath): bool
    {
        $skipPatterns = [
            '.git',
            '.gitignore',
            '.github',
            'vendor',
            'composer.lock',
            'package-lock.json',
            'node_modules',
            '.DS_Store',
            'Thumbs.db',
        ];

        foreach ($skipPatterns as $pattern) {
            if (str_starts_with($relativePath, $pattern) ||
                str_contains($relativePath, '/' . $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a file is binary.
     *
     * @param string $path File path
     * @return bool True if binary
     */
    private function isBinaryFile(string $path): bool
    {
        // Check file extension first
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $binaryExtensions = [
            'png', 'jpg', 'jpeg', 'gif', 'ico', 'svg',
            'pdf', 'zip', 'tar', 'gz', 'bz2',
            'exe', 'dll', 'so', 'dylib',
            'ttf', 'otf', 'woff', 'woff2',
        ];

        if (in_array($extension, $binaryExtensions)) {
            return true;
        }

        // Check file content (first 8192 bytes)
        $fh = fopen($path, 'rb');
        if (!$fh) {
            return false;
        }

        $chunk = fread($fh, 8192);
        fclose($fh);

        // Check for null bytes (indicates binary)
        return str_contains($chunk, "\0");
    }

    /**
     * Check if directory has any files (excluding . and ..).
     *
     * @param string $path Directory path
     * @return bool True if directory contains files
     */
    private function directoryHasFiles(string $path): bool
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $path,
                RecursiveDirectoryIterator::SKIP_DOTS
            )
        );

        foreach ($iterator as $item) {
            // Found at least one item
            return true;
        }

        return false;
    }
}
