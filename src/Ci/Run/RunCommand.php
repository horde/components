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

namespace Horde\Components\Ci\Run;

use Horde\Components\Exception;
use Horde\Components\Output;

/**
 * Orchestrates test execution across all test lanes by executing generated scripts.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class RunCommand
{
    /**
     * Constructor.
     *
     * @param Output $output Output handler
     * @param ResultCollector $collector Result collector
     * @param string $componentsPath Path to horde-components binary
     * @param string $workDir Work directory (for tools path)
     */
    public function __construct(
        private readonly Output $output,
        private readonly ResultCollector $collector,
        private readonly string $componentsPath,
        private readonly string $workDir
    ) {}

    /**
     * Execute tests across all lanes.
     *
     * @param string $workDir Work directory containing lanes
     * @return int Exit code (0 = success, 1 = failures)
     * @throws Exception If work directory invalid
     */
    public function execute(string $workDir): int
    {
        $this->output->bold("=== Horde CI Run ===");

        // 1. Validate work directory
        if (!is_dir($workDir)) {
            throw new Exception("Work directory does not exist: {$workDir}");
        }

        $lanesDir = $workDir . '/lanes';
        if (!is_dir($lanesDir)) {
            throw new Exception("Lanes directory not found: {$lanesDir}");
        }

        // 2. Discover lanes
        $lanes = $this->discoverLanes($lanesDir);

        if (empty($lanes)) {
            throw new Exception("No test lanes found in: {$lanesDir}");
        }

        $this->output->info("Found " . count($lanes) . " test lanes");
        $this->output->plain('');

        // 3. Run lane scripts
        foreach ($lanes as $lane) {
            $this->runLaneScript($lane);
        }

        // 4. Aggregate results from JSON files
        $this->aggregateResults($lanes);

        // 5. Display summary
        $this->collector->displaySummary();

        // 6. Return exit code
        return $this->collector->allPassed() ? 0 : 1;
    }

    /**
     * Discover test lanes in work directory.
     *
     * @param string $lanesDir Lanes directory
     * @return array<array{name: string, dir: string, component_dir: string, php_version: string, stability: string}> Lane info
     */
    private function discoverLanes(string $lanesDir): array
    {
        $lanes = [];
        $dirs = glob($lanesDir . '/php*', GLOB_ONLYDIR);

        if ($dirs === false) {
            return [];
        }

        foreach ($dirs as $dir) {
            $name = basename($dir);

            // Parse lane name: "php8.4-dev" -> php_version="8.4", stability="dev"
            // Accept any stability: dev, stable, alpha, beta, RC, etc.
            if (!preg_match('/^php(\d+\.\d+)-(\w+)$/', $name, $matches)) {
                continue; // Skip invalid lane names
            }

            // Find component subdirectory (first directory in lane)
            $componentDirs = glob($dir . '/*', GLOB_ONLYDIR);
            if (empty($componentDirs)) {
                continue; // Skip lanes without component
            }

            $lanes[] = [
                'name' => $name,
                'dir' => $dir,
                'component_dir' => $componentDirs[0], // e.g., /path/lanes/php8.4-dev/Http
                'php_version' => $matches[1],
                'stability' => $matches[2],
            ];
        }

        // Sort by PHP version then stability
        usort($lanes, function ($a, $b) {
            $cmp = version_compare($a['php_version'], $b['php_version']);
            if ($cmp !== 0) {
                return $cmp;
            }
            // dev before stable
            return $a['stability'] === 'dev' ? -1 : 1;
        });

        return $lanes;
    }

    /**
     * Run tests in a single lane by executing its script.
     *
     * @param array{name: string, dir: string, component_dir: string, php_version: string, stability: string} $lane Lane info
     */
    private function runLaneScript(array $lane): void
    {
        $scriptPath = $lane['dir'] . '/run-lane.sh';

        $this->output->info("[{$lane['name']}] Executing lane script...");

        // Check if script exists
        if (!file_exists($scriptPath)) {
            $this->output->error("[{$lane['name']}] Script not found: {$scriptPath}");
            $this->output->info("  Run 'horde-components ci setup' first");
            $this->collector->addSkipped($lane['name'], 'phpunit', 'Script not found');
            $this->collector->addSkipped($lane['name'], 'phpstan', 'Script not found');
            return;
        }

        // Check if script is executable
        if (!is_executable($scriptPath)) {
            $this->output->error("[{$lane['name']}] Script not executable: {$scriptPath}");
            $this->collector->addSkipped($lane['name'], 'phpunit', 'Script not executable');
            $this->collector->addSkipped($lane['name'], 'phpstan', 'Script not executable');
            return;
        }

        // Execute script and capture output
        $command = sprintf('bash %s 2>&1', escapeshellarg($scriptPath));

        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);

        // Log output
        foreach ($output as $line) {
            $this->output->plain("[{$lane['name']}] {$line}");
        }

        // Report result
        if ($exitCode === 0) {
            $this->output->ok("[{$lane['name']}] Completed successfully");
        } else {
            $this->output->error("[{$lane['name']}] Failed with exit code: {$exitCode}");
        }
    }

    /**
     * Aggregate results from JSON files written by QC.
     *
     * @param array<array{name: string, dir: string, component_dir: string, php_version: string, stability: string}> $lanes Lane info
     */
    private function aggregateResults(array $lanes): void
    {
        $this->output->plain('');
        $this->output->info('Aggregating results...');

        foreach ($lanes as $lane) {
            $buildDir = $lane['component_dir'] . '/build';

            // Read PHPUnit results
            $phpunitFile = $buildDir . '/phpunit-results-summary.json';
            if (file_exists($phpunitFile)) {
                $this->collector->addResultFromFile($lane['name'], 'phpunit', $phpunitFile);
            } else {
                $this->collector->addSkipped($lane['name'], 'phpunit', 'No result file found');
            }

            // Read PHPStan results
            $phpstanFile = $buildDir . '/phpstan-results.json';
            if (file_exists($phpstanFile)) {
                $this->collector->addResultFromFile($lane['name'], 'phpstan', $phpstanFile);
            } else {
                $this->collector->addSkipped($lane['name'], 'phpstan', 'No result file found');
            }

            // Read PHP CS Fixer results (only php8.4-dev)
            if ($lane['name'] === 'php8.4-dev') {
                $csFixerFile = $buildDir . '/php-cs-fixer-results.json';
                if (file_exists($csFixerFile)) {
                    $this->collector->addResultFromFile($lane['name'], 'phpcsfixer', $csFixerFile);
                } else {
                    $this->collector->addSkipped($lane['name'], 'phpcsfixer', 'No result file found');
                }
            }
        }
    }
}
