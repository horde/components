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

namespace Horde\Components\Qc;

use Horde\Components\Exception;
use Phar;

/**
 * Materialize the PHPStan custom-rules bootstrap on disk.
 *
 * The Horde-specific PHPStan rules ship inside `horde-components.phar`. PHPStan
 * itself runs as a separate phar process and cannot read into another phar's
 * stream wrapper, so when horde-components is invoked from a phar (the CI
 * mode) the bootstrap and rule files have to be extracted to a real
 * filesystem directory first.
 *
 * Outside a phar (developer-checkout invocation) the rules are already on
 * disk; the extractor short-circuits and returns the in-tree path.
 *
 * The bootstrap and the rule files share a `__DIR__`-relative layout:
 *
 *   <root>/phpstan-bootstrap.php
 *   <root>/src/PhpStan/Rules/*.php
 *   <root>/src/PhpStan/Rules/Draft/*.php
 *
 * The extractor preserves that layout so `phpstan-bootstrap.php`'s
 * `require_once __DIR__ . '/src/PhpStan/Rules/...';` calls keep working.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class PhpStanRuleExtractor
{
    /**
     * Source files to extract relative to the components root, both
     * inside the phar and on a developer checkout.
     */
    private const FILES = [
        'phpstan-bootstrap.php',
        'src/PhpStan/Rules/NoDirectGlobalAccessRule.php',
        'src/PhpStan/Rules/RequireImmutableUriUsageRule.php',
        'src/PhpStan/Rules/NoDeprecatedHordeUtilRule.php',
        'src/PhpStan/Rules/Draft/NoExitInLibraryCodeRule.php',
        'src/PhpStan/Rules/Draft/RequireDependencyInjectionRule.php',
        'src/PhpStan/Rules/Draft/RequireCoversClassAttributeRule.php',
    ];

    /**
     * Subdirectory under $cacheDir into which the rule tree is extracted.
     */
    private const CACHE_SUBDIR = 'phpstan-rules';

    /**
     * Return an on-disk path to phpstan-bootstrap.php usable by a separate
     * PHPStan process via --autoload-file.
     *
     * - When running from a phar, extracts bootstrap + rule files into
     *   `$cacheDir/phpstan-rules/` (idempotent) and returns the on-disk
     *   bootstrap path there.
     * - When running from a developer checkout, returns the in-tree
     *   path unchanged. The cacheDir argument is ignored.
     *
     * @param string|null $cacheDir Directory the extractor may write to.
     *                              Required when running from a phar.
     * @return string Absolute path to a readable phpstan-bootstrap.php.
     * @throws Exception If running from a phar and the cache directory is
     *                   missing, or extraction fails.
     */
    public function extract(?string $cacheDir): string
    {
        $pharRoot = Phar::running(false);

        if ($pharRoot === '') {
            // Developer checkout — the bootstrap is already on disk.
            return $this->checkoutBootstrapPath();
        }

        if ($cacheDir === null || $cacheDir === '') {
            throw new Exception(
                'PhpStanRuleExtractor: a cacheDir is required when running '
                . 'from a phar (got null/empty).'
            );
        }

        $destRoot = rtrim($cacheDir, '/') . '/' . self::CACHE_SUBDIR;
        $destBootstrap = $destRoot . '/phpstan-bootstrap.php';

        if (is_file($destBootstrap)) {
            // Idempotent — assume a previous extract already populated the tree.
            return $destBootstrap;
        }

        $sourceRoot = 'phar://' . $pharRoot;
        foreach (self::FILES as $relative) {
            $sourcePath = $sourceRoot . '/' . $relative;
            $destPath = $destRoot . '/' . $relative;

            // Drafts are guarded by class_exists() in the bootstrap; skip
            // gracefully if a draft rule was removed from the phar.
            if (!file_exists($sourcePath)) {
                continue;
            }

            $destDir = dirname($destPath);
            if (!is_dir($destDir) && !mkdir($destDir, 0o755, true) && !is_dir($destDir)) {
                throw new Exception("Failed to create directory: {$destDir}");
            }

            if (!copy($sourcePath, $destPath)) {
                throw new Exception("Failed to extract {$relative} to {$destPath}");
            }
        }

        if (!is_file($destBootstrap)) {
            throw new Exception(
                "PhpStanRuleExtractor: phpstan-bootstrap.php missing from "
                . "phar after extraction (looked at {$sourceRoot}/phpstan-bootstrap.php)."
            );
        }

        return $destBootstrap;
    }

    /**
     * Path to the bootstrap in a developer checkout.
     */
    private function checkoutBootstrapPath(): string
    {
        // src/Qc/PhpStanRuleExtractor.php → up 3 levels → repo root.
        return dirname(__DIR__, 2) . '/phpstan-bootstrap.php';
    }
}
