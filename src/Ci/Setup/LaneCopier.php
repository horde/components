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
use Horde\Components\Ci\Config\CiConfig;

/**
 * Copies component source to test lanes.
 *
 * Creates separate directory copies for each PHP version x stability combination.
 * Each lane gets a deep copy of the source to isolate composer installations.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class LaneCopier
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
     * Copy component source to all test lanes.
     *
     * @param CiConfig $config CI configuration
     * @return bool True if successful
     * @throws Exception If copy fails
     */
    public function copyToLanes(CiConfig $config): bool
    {
        $lanes = $config->getTestLanes();

        if (empty($lanes)) {
            throw new Exception('No test lanes defined');
        }

        $this->output->info("Copying component to {$config->componentName} lanes...");

        foreach ($lanes as $lane) {
            $this->output->plain("  → {$lane['php']}/{$lane['stability']}");
            $this->copyLane($config->componentPath, $lane['dir']);
        }

        $this->output->ok("Copied to " . count($lanes) . " lanes");

        return true;
    }

    /**
     * Copy component to a single lane directory.
     *
     * @param string $sourcePath Source component path
     * @param string $targetPath Target lane path
     * @return bool True if successful
     * @throws Exception If copy fails
     */
    private function copyLane(string $sourcePath, string $targetPath): bool
    {
        // Create parent directory
        $parentDir = dirname($targetPath);
        if (!is_dir($parentDir)) {
            if (!mkdir($parentDir, 0o755, true)) {
                throw new Exception("Failed to create directory: {$parentDir}");
            }
        }

        // Remove existing target if present
        if (is_dir($targetPath)) {
            $this->removeDirectory($targetPath);
        }

        // Copy using cp -r (faster than PHP recursive copy)
        $command = sprintf(
            'cp -r %s %s 2>&1',
            escapeshellarg($sourcePath),
            escapeshellarg($targetPath)
        );

        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);

        if ($exitCode !== 0) {
            throw new Exception("Failed to copy to {$targetPath}: " . implode("\n", $output));
        }

        // Remove .git directory from copy (don't need git in test lanes)
        $gitDir = $targetPath . '/.git';
        if (is_dir($gitDir)) {
            $this->removeDirectory($gitDir);
        }

        // Remove vendor directory from copy (will be recreated by composer install)
        $vendorDir = $targetPath . '/vendor';
        if (is_dir($vendorDir)) {
            $this->removeDirectory($vendorDir);
        }

        // Remove any existing composer.lock so composer install resolves
        // from composer.json afresh. A lock that predates the lane's
        // stability override (each lane writes its own minimum-stability
        // into composer.json) - or that predates a recent .horde.yml
        // edit - would otherwise cause composer to refuse with
        // "Required package X is not present in the lock file".
        $lockFile = $targetPath . '/composer.lock';
        if (is_file($lockFile)) {
            @unlink($lockFile);
        }

        return true;
    }

    /**
     * Remove directory recursively.
     *
     * @param string $dir Directory to remove
     * @return bool True if successful
     */
    private function removeDirectory(string $dir): bool
    {
        if (!is_dir($dir)) {
            return true;
        }

        // Use rm -rf for speed
        $command = sprintf('rm -rf %s 2>&1', escapeshellarg($dir));
        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);

        return $exitCode === 0;
    }

    /**
     * Get lane directory size in MB.
     *
     * @param string $laneDir Lane directory path
     * @return float Size in megabytes
     */
    public function getLaneSize(string $laneDir): float
    {
        if (!is_dir($laneDir)) {
            return 0.0;
        }

        $output = shell_exec('du -sm ' . escapeshellarg($laneDir) . ' 2>/dev/null');
        if ($output === null) {
            return 0.0;
        }

        $parts = explode("\t", trim($output));
        return isset($parts[0]) ? (float) $parts[0] : 0.0;
    }

    /**
     * Clean up all lanes.
     *
     * @param CiConfig $config CI configuration
     * @return bool True if successful
     */
    public function cleanupLanes(CiConfig $config): bool
    {
        $lanesDir = $config->workDir . '/lanes';

        if (!is_dir($lanesDir)) {
            return true;
        }

        $this->output->info('Cleaning up test lanes...');

        return $this->removeDirectory($lanesDir);
    }

    /**
     * Verify lane copy is complete.
     *
     * Checks that essential files exist in the lane.
     *
     * @param string $laneDir Lane directory path
     * @return bool True if valid
     */
    public function verifyLane(string $laneDir): bool
    {
        // Check directory exists
        if (!is_dir($laneDir)) {
            return false;
        }

        // Check for composer.json (should exist in all Horde components)
        if (!file_exists($laneDir . '/composer.json')) {
            return false;
        }

        // Check for .horde.yml
        if (!file_exists($laneDir . '/.horde.yml')) {
            return false;
        }

        return true;
    }
}
