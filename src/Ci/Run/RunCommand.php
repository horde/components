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

        // 6. Write GitHub Actions job summary (if in GitHub Actions)
        $this->writeGitHubSummary();

        // 7. Return exit code
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

    /**
     * Write GitHub Actions job summary.
     *
     * Writes a markdown summary to $GITHUB_STEP_SUMMARY if running in GitHub Actions.
     */
    private function writeGitHubSummary(): void
    {
        // Only write if in GitHub Actions
        $summaryFile = getenv('GITHUB_STEP_SUMMARY');
        if ($summaryFile === false || $summaryFile === '') {
            return;
        }

        $markdown = $this->generateSummaryMarkdown();

        // Append to summary file
        file_put_contents($summaryFile, $markdown, FILE_APPEND);
    }

    /**
     * Generate markdown summary for GitHub Actions.
     *
     * @return string Markdown content
     */
    private function generateSummaryMarkdown(): string
    {
        $results = $this->collector->getResults();
        $summary = $this->collector->getSummary();

        $md = "## 🔍 CI Results Summary\n\n";

        // Overall status
        if ($summary['failed'] === 0) {
            $md .= "**Status**: ✅ All {$summary['total']} lanes passed\n\n";
        } else {
            $md .= "**Status**: ❌ {$summary['failed']}/{$summary['total']} lanes failed\n\n";
        }

        // Lane results table
        $md .= "### Lane Results\n\n";
        $md .= "| Lane | PHPUnit | PHPStan | PHP-CS-Fixer | Status |\n";
        $md .= "|------|---------|---------|--------------|--------|\n";

        foreach ($results as $laneName => $tools) {
            $laneStatus = $this->isLanePassed($tools) ? '✅' : '❌';

            $md .= sprintf(
                "| %s | %s | %s | %s | %s |\n",
                $laneName,
                $this->formatToolForTable($tools['phpunit'] ?? null),
                $this->formatToolForTable($tools['phpstan'] ?? null),
                $this->formatToolForTable($tools['phpcsfixer'] ?? null),
                $laneStatus
            );
        }

        // Detailed metrics
        $md .= "\n### Detailed Metrics\n\n";
        $md .= $this->generateDetailedMetrics($results);

        // Footer
        $md .= "\n---\n";
        $md .= "*CI powered by [horde-components](https://github.com/horde/components)*\n";

        return $md;
    }

    /**
     * Check if a lane passed all tools.
     *
     * @param array<string,array<string,mixed>> $tools Tool results
     * @return bool
     */
    private function isLanePassed(array $tools): bool
    {
        foreach ($tools as $result) {
            // Skipped is not a failure
            if (isset($result['skipped']) && $result['skipped']) {
                continue;
            }

            if (!($result['success'] ?? false)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Format tool result for table cell.
     *
     * @param array<string,mixed>|null $result Tool result
     * @return string Formatted string
     */
    private function formatToolForTable(?array $result): string
    {
        if ($result === null) {
            return '—';
        }

        // Skipped
        if (isset($result['skipped']) && $result['skipped']) {
            return '⊘ Skipped';
        }

        // Error
        if (isset($result['error'])) {
            return '❌ Error';
        }

        // Success/failure with stats
        $stats = $result['statistics'] ?? [];
        $emoji = ($result['success'] ?? false) ? '✅' : '❌';

        // Extract key metric
        $metric = '';
        if (isset($stats['tests'])) {
            $metric = "{$stats['tests']} tests";
        } elseif (isset($stats['errors'])) {
            $metric = "{$stats['errors']} errors";
        } elseif (isset($stats['files_checked'])) {
            $metric = "{$stats['files_checked']} files";
        }

        return $metric ? "{$emoji} {$metric}" : $emoji;
    }

    /**
     * Generate detailed metrics section.
     *
     * @param array<string,array<string,array<string,mixed>>> $results All results
     * @return string Markdown content
     */
    private function generateDetailedMetrics(array $results): string
    {
        $md = '';

        // Aggregate stats across all lanes
        $phpunitStats = $this->aggregateToolStats($results, 'phpunit');
        $phpstanStats = $this->aggregateToolStats($results, 'phpstan');
        $csFixerStats = $this->aggregateToolStats($results, 'phpcsfixer');

        // PHPUnit section
        if (!empty($phpunitStats)) {
            $md .= "#### PHPUnit\n\n";
            $totalTests = $phpunitStats['tests'] ?? 0;
            $totalFailures = $phpunitStats['failures'] ?? 0;
            $totalErrors = $phpunitStats['errors'] ?? 0;
            $lanesRun = $phpunitStats['lanes_run'] ?? 0;

            if ($totalFailures === 0 && $totalErrors === 0) {
                $md .= "✅ **All tests passed** across {$lanesRun} lanes\n";
            } else {
                $md .= "❌ **Tests failed**\n";
            }

            $md .= "- Total tests: {$totalTests}\n";
            if ($totalFailures > 0) {
                $md .= "- Failures: {$totalFailures}\n";
            }
            if ($totalErrors > 0) {
                $md .= "- Errors: {$totalErrors}\n";
            }
            $md .= "\n";
        }

        // PHPStan section
        if (!empty($phpstanStats)) {
            $md .= "#### PHPStan\n\n";
            $totalErrors = $phpstanStats['errors'] ?? 0;
            $filesAnalyzed = $phpstanStats['files_analyzed'] ?? 0;
            $lanesRun = $phpstanStats['lanes_run'] ?? 0;

            if ($totalErrors === 0) {
                $md .= "✅ **No errors found** in {$filesAnalyzed} files ({$lanesRun} lanes)\n\n";
            } else {
                $md .= "⚠️ **{$totalErrors} errors found** in {$filesAnalyzed} files ({$lanesRun} lanes)\n\n";
            }
        }

        // PHP-CS-Fixer section
        if (!empty($csFixerStats)) {
            $md .= "#### PHP-CS-Fixer\n\n";
            $filesChecked = $csFixerStats['files_checked'] ?? 0;
            $filesWithIssues = $csFixerStats['files_with_issues'] ?? 0;

            if ($filesWithIssues === 0) {
                $md .= "✅ **No style issues** in {$filesChecked} files\n\n";
            } else {
                $md .= "⚠️ **{$filesWithIssues} files** with style issues (of {$filesChecked} checked)\n\n";
            }
        }

        return $md;
    }

    /**
     * Aggregate statistics for a specific tool across all lanes.
     *
     * @param array<string,array<string,array<string,mixed>>> $results All results
     * @param string $tool Tool name
     * @return array<string,int> Aggregated stats
     */
    private function aggregateToolStats(array $results, string $tool): array
    {
        $aggregated = ['lanes_run' => 0];

        foreach ($results as $laneName => $tools) {
            if (!isset($tools[$tool])) {
                continue;
            }

            $result = $tools[$tool];

            // Skip skipped/errored lanes
            if (isset($result['skipped']) || isset($result['error'])) {
                continue;
            }

            $aggregated['lanes_run']++;

            // Aggregate statistics
            $stats = $result['statistics'] ?? [];
            foreach ($stats as $key => $value) {
                if (is_numeric($value)) {
                    $aggregated[$key] = ($aggregated[$key] ?? 0) + $value;
                }
            }
        }

        return $aggregated;
    }
}
