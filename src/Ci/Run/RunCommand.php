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
use Horde\GithubApiClient\GithubApiClient;
use Horde\Http\Uri;

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
     * @param GithubApiClient|null $apiClient GitHub API client (optional)
     */
    public function __construct(
        private readonly Output $output,
        private readonly ResultCollector $collector,
        private readonly string $componentsPath,
        private readonly string $workDir,
        private readonly ?GithubApiClient $apiClient = null
    ) {}

    /**
     * Map of lane name -> build directory, populated by aggregateResults.
     * Used downstream by generateDetailedMetrics to read per-lane native
     * tool JSON for findings deduplication.
     *
     * @var array<string,string>
     */
    private array $laneBuildDirs = [];

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

        // 7. Create GitHub Check Runs (if in GitHub Actions with API client)
        $this->createCheckRuns();

        // 8. Post PR comment (if in pull request context)
        $this->postPrComment();

        // 9. Write HTML report (if in local mode)
        $this->writeHtmlReport($workDir);

        // 10. Return exit code
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

        // Honour deliberate skips written by SetupCommand: don't try to run
        // a script that was never generated, and don't print Script-not-found
        // errors. Result aggregation will record the skip from skip.json.
        $skipFile = $lane['component_dir'] . '/build/skip.json';
        if (file_exists($skipFile)) {
            $this->output->skip("[{$lane['name']}] Deliberately skipped");
            return;
        }

        // F30: lane with build/setup-failed.json never had a run-lane.sh
        // generated. Stay silent here; aggregateResults emits the failure with
        // the failure reason.
        $setupFailFile = $lane['component_dir'] . '/build/setup-failed.json';
        if (file_exists($setupFailFile)) {
            $this->output->error("[{$lane['name']}] Setup failed; no lane script to run");
            return;
        }

        $this->output->info("[{$lane['name']}] Executing lane script...");

        // Check if script exists
        if (!file_exists($scriptPath)) {
            $this->output->error("[{$lane['name']}] Script not found: {$scriptPath}");
            $this->output->info("  Run 'horde-components ci setup' first");
            $this->collector->addMissing($lane['name'], 'phpunit', 'Script not found');
            $this->collector->addMissing($lane['name'], 'phpstan', 'Script not found');
            return;
        }

        // Check if script is executable
        if (!is_executable($scriptPath)) {
            $this->output->error("[{$lane['name']}] Script not executable: {$scriptPath}");
            $this->collector->addMissing($lane['name'], 'phpunit', 'Script not executable');
            $this->collector->addMissing($lane['name'], 'phpstan', 'Script not executable');
            return;
        }

        // Execute script and capture output
        $command = sprintf('bash %s 2>&1', escapeshellarg($scriptPath));

        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);

        // Log output
        foreach ($output as $line) {
            // GitHub Actions workflow commands must start the line for the
            // runner to parse them. Lines like `::error file=...::msg` from
            // a lane script have to pass through verbatim - prefixing with
            // `[lane-name]` turns them into ordinary log output and breaks
            // annotation rendering.
            if (str_starts_with($line, '::')) {
                $this->output->plain($line);
            } else {
                $this->output->plain("[{$lane['name']}] {$line}");
            }
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
            $this->laneBuildDirs[$lane['name']] = $buildDir;

            // Honour deliberate skips written by SetupCommand.
            $skipFile = $buildDir . '/skip.json';
            if (file_exists($skipFile)) {
                $skipData = json_decode((string) file_get_contents($skipFile), true);
                if (is_array($skipData) && ($skipData['deliberate_skip'] ?? false)) {
                    $reason = (string) ($skipData['reason'] ?? 'Lane deliberately skipped');
                    foreach ((array) ($skipData['tools'] ?? ['phpunit', 'phpstan']) as $tool) {
                        $this->collector->addSkipped($lane['name'], (string) $tool, $reason);
                    }
                    continue;
                }
            }

            // Surface setup-failed lanes as a failure for every tool that
            // would have run. The reason from setup-failed.json lands in
            // the PR comment so the maintainer sees *why* the lane never
            // executed without having to scroll through composer logs.
            //
            // The category (stability_gate / platform_missing /
            // php_version / unknown) lets PrCommentReporter render
            // "ecosystem not yet at this stability" failures distinctly
            // from real-bug failures.
            $setupFailFile = $buildDir . '/setup-failed.json';
            if (file_exists($setupFailFile)) {
                $failData = json_decode((string) file_get_contents($setupFailFile), true);
                if (is_array($failData) && ($failData['setup_failed'] ?? false)) {
                    $reason = (string) ($failData['reason'] ?? 'Lane setup failed');
                    $category = (string) ($failData['category'] ?? 'unknown');
                    foreach ((array) ($failData['tools'] ?? ['phpunit', 'phpstan']) as $tool) {
                        $this->collector->addMissing(
                            $lane['name'],
                            (string) $tool,
                            'Setup failed: ' . $reason,
                            $category
                        );
                    }
                    continue;
                }
            }

            // Read PHPUnit results
            $phpunitFile = $buildDir . '/phpunit-results-summary.json';
            if (file_exists($phpunitFile)) {
                $this->collector->addResultFromFile($lane['name'], 'phpunit', $phpunitFile);
            } else {
                $this->collector->addMissing($lane['name'], 'phpunit', 'No result file found');
            }

            // Read PHPStan results
            $phpstanFile = $buildDir . '/phpstan-results.json';
            if (file_exists($phpstanFile)) {
                $this->collector->addResultFromFile($lane['name'], 'phpstan', $phpstanFile);
            } else {
                $this->collector->addMissing($lane['name'], 'phpstan', 'No result file found');
            }

            // Read PHP CS Fixer results (only php8.4-dev)
            if ($lane['name'] === 'php8.4-dev') {
                $csFixerFile = $buildDir . '/php-cs-fixer-results.json';
                if (file_exists($csFixerFile)) {
                    $this->collector->addResultFromFile($lane['name'], 'phpcsfixer', $csFixerFile);
                } else {
                    $this->collector->addMissing($lane['name'], 'phpcsfixer', 'No result file found');
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
                $this->formatToolForTable($tools['phpunit'] ?? null, 'phpunit'),
                $this->formatToolForTable($tools['phpstan'] ?? null, 'phpstan'),
                $this->formatToolForTable($tools['phpcsfixer'] ?? null, 'phpcsfixer'),
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
            // success: false now covers both real tool failures and missing
            // result files (W2 - addMissing records success: false).
            if (!($result['success'] ?? false)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Format tool result for table cell.
     *
     * One short, glanceable label per cell. Per-tool vocabulary so a
     * reader sees the most actionable bit of info without parsing
     * stats. Detail (which test failed, which file has watermark
     * errors) lives in the Quality Metrics section and the per-lane
     * Failed Lanes detail block, not here.
     *
     * @param array<string,mixed>|null $result Tool result
     * @param string $toolName Tool name ("phpunit", "phpstan", "phpcsfixer")
     * @return string Markdown cell content
     */
    private function formatToolForTable(?array $result, string $toolName): string
    {
        if ($result === null) {
            return '-';
        }

        // Deliberately skipped (lane incompatible with this tool, e.g.
        // PHPUnit 12 on PHP 8.2).
        if (isset($result['deliberate_skip']) && $result['deliberate_skip']) {
            return '⊘ Skipped';
        }

        // Missing result file (lane crashed before writing JSON, etc.)
        // or surface "Could not run" for any state without usable stats.
        if (isset($result['missing']) && $result['missing']) {
            return '❌ Could not run';
        }

        if (isset($result['error'])) {
            return '❌ Could not run';
        }

        $stats = $result['statistics'] ?? [];
        $success = (bool) ($result['success'] ?? false);
        $mode = (string) ($result['mode'] ?? 'enforced');

        return match ($toolName) {
            'phpunit' => $this->formatPhpUnitCell($stats, $success, $mode),
            'phpstan' => $this->formatPhpStanCell($stats, $success, $mode),
            'phpcsfixer' => $this->formatPhpCsFixerCell($stats, $success),
            default => $success ? '✅' : '❌',
        };
    }

    /**
     * Per-tool cell for PHPUnit.
     *
     * @param array<string,mixed> $stats
     */
    private function formatPhpUnitCell(array $stats, bool $success, string $mode): string
    {
        // Library declared no test suite: not a failure, not a pass -
        // there was nothing to run. The Unit task writes this mode when
        // `test/` is absent on disk.
        if ($mode === 'no_test_suite') {
            return '✅ no tests available';
        }

        $tests = (int) ($stats['tests'] ?? 0);
        $failures = (int) ($stats['failures'] ?? 0);
        $errors = (int) ($stats['errors'] ?? 0);

        if ($tests === 0) {
            // PHPUnit emitted no test events. Either the suite-load
            // crashed (parse error in a test file, config rejection)
            // or no tests matched the suite. Either way, no signal to
            // report.
            return '❌ Could not run';
        }

        $bad = $failures + $errors;
        if ($success && $bad === 0) {
            return sprintf('✅ all %d passed', $tests);
        }
        return sprintf('❌ %d fail or error', $bad);
    }

    /**
     * Per-tool cell for PHPStan.
     *
     * @param array<string,mixed> $stats
     */
    private function formatPhpStanCell(array $stats, bool $success, string $mode): string
    {
        $errors = (int) ($stats['errors'] ?? 0);
        $advisory = ($mode === 'advisory');

        if ($success && $errors === 0) {
            return '✅ no errors';
        }
        if ($advisory) {
            // Advisory mode (legacy-only lib/ layout): findings are
            // informational. `success: true` is forced by the Unit task,
            // so we render them as advisories regardless of count.
            return sprintf('✅ %d advisories', $errors);
        }
        if ($success === false && $errors === 0) {
            // PHPStan exited non-zero before producing findings -
            // tooling failure (autoload-file unreachable, bad NEON,
            // unloadable rule, etc.). Surface as "Could not run" so the
            // reader doesn't go looking for a watermark error that
            // isn't there.
            return '❌ Could not run';
        }
        return sprintf('❌ %d watermark errors', $errors);
    }

    /**
     * Per-tool cell for PHP-CS-Fixer.
     *
     * @param array<string,mixed> $stats
     */
    private function formatPhpCsFixerCell(array $stats, bool $success): string
    {
        $checked = (int) ($stats['files_checked'] ?? 0);
        $withIssues = (int) ($stats['files_with_issues'] ?? 0);

        if ($checked === 0) {
            // PHP-CS-Fixer runs on one lane only (php8.4-dev). For all
            // other lanes the result is either missing or has no stats.
            return '-';
        }
        if ($success && $withIssues === 0) {
            return sprintf('✅ %d files styled', $checked);
        }
        return sprintf('❌ %d files need formatting', $withIssues);
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
            // tests is per-lane; show the max so a 6-lane matrix reads
            // "54 tests in 6 lanes" rather than the summed "324".
            $totalTests = $phpunitStats['tests_max'] ?? 0;
            $totalFailures = $phpunitStats['failures'] ?? 0;
            $totalErrors = $phpunitStats['errors'] ?? 0;
            $lanesRun = $phpunitStats['lanes_run'] ?? 0;

            if ($totalFailures === 0 && $totalErrors === 0) {
                $md .= "✅ **All tests passed** across {$lanesRun} lanes\n";
            } else {
                $md .= "❌ **Tests failed**\n";
            }

            $md .= "- Tests per lane: {$totalTests}\n";
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
            // Per-lane max instead of cross-lane sum: 4 lanes seeing
            // the same 352 errors should report 352, not 1408.
            $errorsPerLane = $phpstanStats['errors_max'] ?? 0;
            // files_scanned is per-lane; max == the actual codebase size.
            $filesScanned = $phpstanStats['files_scanned_max'] ?? 0;
            $lanesRun = $phpstanStats['lanes_run'] ?? 0;
            $advisory = $this->allPhpStanLanesAdvisory($results);

            if ($errorsPerLane === 0) {
                $md .= "✅ **No errors found** in {$filesScanned} files ({$lanesRun} lanes)\n\n";
            } elseif ($advisory) {
                $md .= "ℹ️ **{$errorsPerLane} advisories** ({$filesScanned} files scanned, {$lanesRun} lanes)\n\n";
                $md .= $this->renderPhpStanFindingsTable();
            } else {
                $md .= "⚠️ **{$errorsPerLane} watermark errors** ({$filesScanned} files scanned, {$lanesRun} lanes)\n\n";
                $md .= $this->renderPhpStanFindingsTable();
            }
        }

        // PHP-CS-Fixer section
        if (!empty($csFixerStats)) {
            $md .= "#### PHP-CS-Fixer\n\n";
            // files_checked/files_with_issues are per-lane; max == unique.
            $filesChecked = $csFixerStats['files_checked_max'] ?? 0;
            $filesWithIssues = $csFixerStats['files_with_issues_max'] ?? 0;

            if ($filesWithIssues === 0) {
                $md .= "✅ **No style issues** in {$filesChecked} files\n\n";
            } else {
                $md .= "⚠️ **{$filesWithIssues} files** with style issues (of {$filesChecked} checked)\n\n";
                $md .= $this->renderPhpCsFixerFindingsTable();
            }
        }

        return $md;
    }

    /**
     * Render a deduplicated PHPStan findings table.
     *
     * @return string Markdown
     */
    private function renderPhpStanFindingsTable(): string
    {
        $aggregator = new FindingsAggregator();
        $findings = $aggregator->aggregatePhpStan($this->laneBuildDirs);
        if ($findings === []) {
            return '';
        }

        $md = "| File | Line | Identifier | Message | Lanes |\n";
        $md .= "|------|------|------------|---------|-------|\n";
        foreach ($findings as $f) {
            $md .= sprintf(
                "| %s | %s | %s | %s | %s |\n",
                $this->escapeMd($f['file']),
                $f['line'] !== null ? (string) $f['line'] : '-',
                $f['identifier'] !== null ? '`' . $this->escapeMd($f['identifier']) . '`' : '-',
                $this->escapeMd($f['message']),
                $this->escapeMd(implode(', ', $f['lanes']))
            );
        }
        return $md . "\n";
    }

    /**
     * Render a deduplicated PHP-CS-Fixer findings table.
     *
     * @return string Markdown
     */
    private function renderPhpCsFixerFindingsTable(): string
    {
        $aggregator = new FindingsAggregator();
        $findings = $aggregator->aggregatePhpCsFixer($this->laneBuildDirs);
        if ($findings === []) {
            return '';
        }

        $md = "| File | Lanes |\n";
        $md .= "|------|-------|\n";
        foreach ($findings as $f) {
            $md .= sprintf(
                "| %s | %s |\n",
                $this->escapeMd($f['file']),
                $this->escapeMd(implode(', ', $f['lanes']))
            );
        }
        return $md . "\n";
    }

    /**
     * Lightly escape a value for use in a markdown table cell.
     */
    private function escapeMd(string $value): string
    {
        // Pipes and newlines would break the table layout.
        return strtr($value, ['|' => '\\|', "\n" => ' ', "\r" => '']);
    }

    /**
     * True when every lane that produced a PHPStan result reported
     * `mode: advisory` (legacy-only lib/ layout, see
     * `Qc\Task\Phpstan::resolveAnalysisPlan()`). When all PHPStan
     * voters are advisory, the Detailed Metrics PHPStan section labels
     * findings as advisories rather than watermark errors. Missing,
     * error, and deliberately-skipped lanes do not vote.
     *
     * Mirrors the helper of the same name in PrCommentReporter; kept
     * private to RunCommand to avoid a cross-file shared helper for
     * one method.
     *
     * @param array<string,array<string,array<string,mixed>>> $results
     */
    private function allPhpStanLanesAdvisory(array $results): bool
    {
        $seen = false;
        foreach ($results as $tools) {
            $result = $tools['phpstan'] ?? null;
            if (!is_array($result)) {
                continue;
            }
            if (isset($result['missing']) || isset($result['error']) || isset($result['deliberate_skip'])) {
                continue;
            }
            $seen = true;
            if (($result['mode'] ?? 'enforced') !== 'advisory') {
                return false;
            }
        }
        return $seen;
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

            // Skip lanes with no usable statistics
            if (isset($result['missing']) || isset($result['error']) || isset($result['deliberate_skip'])) {
                continue;
            }

            $aggregated['lanes_run']++;

            $stats = $result['statistics'] ?? [];
            foreach ($stats as $key => $value) {
                if (!is_numeric($value)) {
                    continue;
                }
                // Two views per numeric stat: `$key` sum across lanes
                // (meaningful for finding counts that stack), `${key}_max`
                // highest seen on any single lane (meaningful for
                // unit-of-work counts like tests/files which are
                // per-lane and would be overstated by the lane count).
                $aggregated[$key] = ($aggregated[$key] ?? 0) + $value;
                $maxKey = $key . '_max';
                $aggregated[$maxKey] = max(
                    $aggregated[$maxKey] ?? 0,
                    $value
                );
            }
        }

        return $aggregated;
    }

    /**
     * Write HTML report for local mode.
     *
     * Writes an HTML report to the work directory if NOT in GitHub Actions.
     *
     * @param string $workDir Work directory
     */
    private function writeHtmlReport(string $workDir): void
    {
        // Only write if NOT in GitHub Actions (local mode)
        if (getenv('GITHUB_ACTIONS') !== false) {
            return;
        }

        $reportFile = $workDir . '/ci-report.html';
        $html = $this->generateHtmlReport();

        file_put_contents($reportFile, $html);

        $this->output->plain('');
        $this->output->info("📊 HTML report written to: {$reportFile}");
        $this->output->info("   Open in browser: file://{$reportFile}");
    }

    /**
     * Generate HTML report.
     *
     * @return string HTML content
     */
    private function generateHtmlReport(): string
    {
        $results = $this->collector->getResults();
        $summary = $this->collector->getSummary();

        $timestamp = date('Y-m-d H:i:s');
        $failed = $summary['failed'] ?? 0;
        $total = $summary['total'] ?? 0;
        $statusClass = $failed === 0 ? 'success' : 'failure';
        $statusText = $failed === 0
            ? "✅ All {$total} lanes passed"
            : "❌ {$failed}/{$total} lanes failed";

        $html = <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>Horde CI Report - {$timestamp}</title>
                <style>
                    * {
                        margin: 0;
                        padding: 0;
                        box-sizing: border-box;
                    }

                    body {
                        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
                        line-height: 1.6;
                        color: #333;
                        background: #f5f5f5;
                        padding: 20px;
                    }

                    .container {
                        max-width: 1200px;
                        margin: 0 auto;
                        background: white;
                        border-radius: 8px;
                        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
                        padding: 30px;
                    }

                    h1 {
                        color: #2c3e50;
                        margin-bottom: 10px;
                        font-size: 28px;
                    }

                    .timestamp {
                        color: #7f8c8d;
                        font-size: 14px;
                        margin-bottom: 20px;
                    }

                    .status-banner {
                        padding: 15px 20px;
                        border-radius: 6px;
                        margin-bottom: 30px;
                        font-size: 18px;
                        font-weight: 500;
                    }

                    .status-banner.success {
                        background: #d4edda;
                        color: #155724;
                        border: 1px solid #c3e6cb;
                    }

                    .status-banner.failure {
                        background: #f8d7da;
                        color: #721c24;
                        border: 1px solid #f5c6cb;
                    }

                    h2 {
                        color: #2c3e50;
                        margin: 30px 0 15px 0;
                        font-size: 22px;
                        border-bottom: 2px solid #ecf0f1;
                        padding-bottom: 10px;
                    }

                    table {
                        width: 100%;
                        border-collapse: collapse;
                        margin-bottom: 30px;
                    }

                    th, td {
                        padding: 12px;
                        text-align: left;
                        border: 1px solid #ddd;
                    }

                    th {
                        background: #34495e;
                        color: white;
                        font-weight: 600;
                        text-transform: uppercase;
                        font-size: 12px;
                        letter-spacing: 0.5px;
                    }

                    tr:nth-child(even) {
                        background: #f8f9fa;
                    }

                    tr:hover {
                        background: #e8f4f8;
                    }

                    .lane-name {
                        font-family: 'Monaco', 'Courier New', monospace;
                        font-weight: 600;
                    }

                    .status-pass {
                        color: #28a745;
                    }

                    .status-fail {
                        color: #dc3545;
                    }

                    .status-skip {
                        color: #6c757d;
                    }

                    .metric-card {
                        background: #f8f9fa;
                        padding: 20px;
                        border-radius: 6px;
                        margin-bottom: 15px;
                        border-left: 4px solid #3498db;
                    }

                    .metric-card h3 {
                        color: #2c3e50;
                        font-size: 16px;
                        margin-bottom: 10px;
                    }

                    .metric-row {
                        display: flex;
                        justify-content: space-between;
                        padding: 8px 0;
                        border-bottom: 1px solid #e0e0e0;
                    }

                    .metric-row:last-child {
                        border-bottom: none;
                    }

                    .metric-label {
                        font-weight: 500;
                        color: #555;
                    }

                    .metric-value {
                        font-family: 'Monaco', 'Courier New', monospace;
                        font-weight: 600;
                    }

                    .footer {
                        margin-top: 40px;
                        padding-top: 20px;
                        border-top: 1px solid #ecf0f1;
                        color: #7f8c8d;
                        font-size: 14px;
                        text-align: center;
                    }

                    .footer a {
                        color: #3498db;
                        text-decoration: none;
                    }

                    .footer a:hover {
                        text-decoration: underline;
                    }

                    details {
                        margin-bottom: 15px;
                    }

                    summary {
                        cursor: pointer;
                        padding: 10px;
                        background: #ecf0f1;
                        border-radius: 4px;
                        font-weight: 600;
                        user-select: none;
                    }

                    summary:hover {
                        background: #d5dbdb;
                    }

                    .detail-content {
                        padding: 15px;
                        margin-top: 10px;
                        border-left: 3px solid #3498db;
                    }
                </style>
            </head>
            <body>
                <div class="container">
                    <h1>🔍 Horde CI Report</h1>
                    <div class="timestamp">Generated: {$timestamp}</div>

                    <div class="status-banner {$statusClass}">
                        {$statusText}
                    </div>

                    <h2>Lane Results</h2>
                    <table>
                        <thead>
                            <tr>
                                <th>Lane</th>
                                <th>PHPUnit</th>
                                <th>PHPStan</th>
                                <th>PHP-CS-Fixer</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>

            HTML;

        // Generate table rows
        foreach ($results as $laneName => $tools) {
            $laneStatus = $this->isLanePassed($tools);
            $statusIcon = $laneStatus ? '✅' : '❌';
            $statusClass = $laneStatus ? 'status-pass' : 'status-fail';

            $html .= "                <tr>\n";
            $html .= "                    <td class=\"lane-name\">{$laneName}</td>\n";
            $html .= "                    <td>" . $this->formatToolForHtml($tools['phpunit'] ?? null, 'phpunit') . "</td>\n";
            $html .= "                    <td>" . $this->formatToolForHtml($tools['phpstan'] ?? null, 'phpstan') . "</td>\n";
            $html .= "                    <td>" . $this->formatToolForHtml($tools['phpcsfixer'] ?? null, 'phpcsfixer') . "</td>\n";
            $html .= "                    <td class=\"{$statusClass}\">{$statusIcon}</td>\n";
            $html .= "                </tr>\n";
        }

        $html .= <<<HTML
                        </tbody>
                    </table>

                    <h2>Detailed Metrics</h2>

            HTML;

        // Add detailed metrics
        $html .= $this->generateHtmlMetrics($results);

        $html .= <<<HTML

                    <div class="footer">
                        CI powered by <a href="https://github.com/horde/components" target="_blank">horde-components</a>
                    </div>
                </div>
            </body>
            </html>
            HTML;

        return $html;
    }

    /**
     * Format tool result for HTML table cell.
     *
     * Delegates to {@see formatToolForTable()} for the actual label
     * (so HTML and Markdown stay in sync) and wraps the result in a
     * status-coloured span so the local HTML report keeps its red /
     * green / grey coding.
     *
     * @param array<string,mixed>|null $result Tool result
     * @param string $toolName Tool name ("phpunit", "phpstan", "phpcsfixer")
     * @return string HTML string
     */
    private function formatToolForHtml(?array $result, string $toolName): string
    {
        $label = $this->formatToolForTable($result, $toolName);

        // Class from the leading glyph; cheap and avoids restating the
        // state machine here.
        if (str_starts_with($label, '✅')) {
            $class = 'status-pass';
        } elseif (str_starts_with($label, '❌')) {
            $class = 'status-fail';
        } else {
            $class = 'status-skip';
        }

        return "<span class=\"{$class}\">{$label}</span>";
    }

    /**
     * Generate HTML metrics section.
     *
     * @param array<string,array<string,array<string,mixed>>> $results All results
     * @return string HTML content
     */
    private function generateHtmlMetrics(array $results): string
    {
        $html = '';

        // Aggregate stats
        $phpunitStats = $this->aggregateToolStats($results, 'phpunit');
        $phpstanStats = $this->aggregateToolStats($results, 'phpstan');
        $csFixerStats = $this->aggregateToolStats($results, 'phpcsfixer');

        // PHPUnit section
        if (!empty($phpunitStats)) {
            // Tests/assertions are per-lane; show the per-lane max so the
            // HTML report doesn't multiply by the matrix size.
            $totalTests = $phpunitStats['tests_max'] ?? 0;
            $totalFailures = $phpunitStats['failures'] ?? 0;
            $totalErrors = $phpunitStats['errors'] ?? 0;
            $lanesRun = $phpunitStats['lanes_run'] ?? 0;
            $assertions = $phpunitStats['assertions_max'] ?? 0;

            $statusIcon = ($totalFailures === 0 && $totalErrors === 0) ? '✅' : '❌';
            $statusText = ($totalFailures === 0 && $totalErrors === 0)
                ? 'All tests passed'
                : 'Tests failed';

            $html .= <<<HTML
                        <div class="metric-card">
                            <h3>{$statusIcon} PHPUnit - {$statusText}</h3>
                            <div class="metric-row">
                                <span class="metric-label">Lanes executed:</span>
                                <span class="metric-value">{$lanesRun}</span>
                            </div>
                            <div class="metric-row">
                                <span class="metric-label">Tests per lane:</span>
                                <span class="metric-value">{$totalTests}</span>
                            </div>
                            <div class="metric-row">
                                <span class="metric-label">Assertions per lane:</span>
                                <span class="metric-value">{$assertions}</span>
                            </div>

                HTML;

            if ($totalFailures > 0) {
                $html .= <<<HTML
                                <div class="metric-row">
                                    <span class="metric-label">Failures:</span>
                                    <span class="metric-value status-fail">{$totalFailures}</span>
                                </div>

                    HTML;
            }

            if ($totalErrors > 0) {
                $html .= <<<HTML
                                <div class="metric-row">
                                    <span class="metric-label">Errors:</span>
                                    <span class="metric-value status-fail">{$totalErrors}</span>
                                </div>

                    HTML;
            }

            $html .= "        </div>\n";
        }

        // PHPStan section
        if (!empty($phpstanStats)) {
            // Per-lane max instead of cross-lane sum (avoid reporting
            // 4 * 352 = 1408 when each lane sees the same 352 errors).
            $errorsPerLane = $phpstanStats['errors_max'] ?? 0;
            // Files scanned/with errors are per-lane; max == codebase.
            $filesScanned = $phpstanStats['files_scanned_max'] ?? 0;
            $filesWithErrors = $phpstanStats['files_with_errors_max'] ?? 0;
            $lanesRun = $phpstanStats['lanes_run'] ?? 0;
            $advisory = $this->allPhpStanLanesAdvisory($results);

            if ($errorsPerLane === 0) {
                $statusIcon = '✅';
                $statusText = 'No errors found';
            } elseif ($advisory) {
                $statusIcon = 'ℹ️';
                $statusText = "{$errorsPerLane} advisories";
            } else {
                $statusIcon = '⚠️';
                $statusText = "{$errorsPerLane} watermark errors";
            }

            $html .= <<<HTML
                        <div class="metric-card">
                            <h3>{$statusIcon} PHPStan - {$statusText}</h3>
                            <div class="metric-row">
                                <span class="metric-label">Lanes executed:</span>
                                <span class="metric-value">{$lanesRun}</span>
                            </div>
                            <div class="metric-row">
                                <span class="metric-label">Files scanned:</span>
                                <span class="metric-value">{$filesScanned}</span>
                            </div>
                            <div class="metric-row">
                                <span class="metric-label">Files with errors:</span>
                                <span class="metric-value">{$filesWithErrors}</span>
                            </div>
                            <div class="metric-row">
                                <span class="metric-label">Errors:</span>
                                <span class="metric-value">{$errorsPerLane}</span>
                            </div>
                        </div>

                HTML;
        }

        // PHP-CS-Fixer section
        if (!empty($csFixerStats)) {
            $filesChecked = $csFixerStats['files_checked_max'] ?? 0;
            $filesWithIssues = $csFixerStats['files_with_issues_max'] ?? 0;

            $statusIcon = $filesWithIssues === 0 ? '✅' : '⚠️';
            $statusText = $filesWithIssues === 0
                ? 'No style issues'
                : "{$filesWithIssues} files with issues";

            $html .= <<<HTML
                        <div class="metric-card">
                            <h3>{$statusIcon} PHP-CS-Fixer - {$statusText}</h3>
                            <div class="metric-row">
                                <span class="metric-label">Files checked:</span>
                                <span class="metric-value">{$filesChecked}</span>
                            </div>
                            <div class="metric-row">
                                <span class="metric-label">Files with issues:</span>
                                <span class="metric-value">{$filesWithIssues}</span>
                            </div>
                        </div>

                HTML;
        }

        return $html;
    }

    /**
     * Create GitHub Check Runs for each tool result.
     */
    private function createCheckRuns(): void
    {
        // Only run if we have API client and are in GitHub Actions
        if ($this->apiClient === null || getenv('GITHUB_ACTIONS') === false) {
            return;
        }

        // Get repository info from environment
        $repo = getenv('GITHUB_REPOSITORY');
        $sha = getenv('GITHUB_SHA');

        if ($repo === false || $sha === false) {
            return;
        }

        // Parse owner/repo
        $parts = explode('/', $repo, 2);
        if (count($parts) !== 2) {
            return;
        }

        [$owner, $repoName] = $parts;

        $reporter = new CheckReporter($this->output, $this->apiClient);
        $reporter->reportLaneResults(
            owner: $owner,
            repo: $repoName,
            sha: $sha,
            results: $this->collector->getResults()
        );
    }

    /**
     * Post PR comment with results.
     */
    private function postPrComment(): void
    {
        // Only run if we have API client and are in GitHub Actions
        if ($this->apiClient === null || getenv('GITHUB_ACTIONS') === false) {
            return;
        }

        // Check if this is a pull request event
        $eventPath = getenv('GITHUB_EVENT_PATH');
        if ($eventPath === false || !file_exists($eventPath)) {
            return;
        }

        $eventData = json_decode(file_get_contents($eventPath), true);
        if (!isset($eventData['pull_request'])) {
            return; // Not a PR event
        }

        $prNumber = $eventData['pull_request']['number'] ?? null;
        if ($prNumber === null) {
            return;
        }

        // Get repository info
        $repo = getenv('GITHUB_REPOSITORY');
        $runId = getenv('GITHUB_RUN_ID');
        $serverUrl = getenv('GITHUB_SERVER_URL') ?: 'https://github.com';

        if ($repo === false || $runId === false) {
            return;
        }

        $parts = explode('/', $repo, 2);
        if (count($parts) !== 2) {
            return;
        }

        [$owner, $repoName] = $parts;

        // Build GitHub Actions run URL
        $serverUrl = getenv('GITHUB_SERVER_URL') ?: 'https://github.com';
        $runUrl = (new Uri($serverUrl))
            ->withPath("/{$repo}/actions/runs/{$runId}")
            ->__toString();

        // Build deduped findings once so the comment can show "1 unique
        // error in 6 lanes" rather than the lanes-summed "6 errors".
        $aggregator = new FindingsAggregator();
        $findingsByTool = [
            'phpstan' => $aggregator->aggregatePhpStan($this->laneBuildDirs),
            'phpcsfixer' => $aggregator->aggregatePhpCsFixer($this->laneBuildDirs),
        ];

        $reporter = new PrCommentReporter($this->output, $this->apiClient);
        $reporter->postComment(
            owner: $owner,
            repo: $repoName,
            prNumber: (int) $prNumber,
            results: $this->collector->getResults(),
            summary: $this->collector->getSummary(),
            runUrl: $runUrl,
            findingsByTool: $findingsByTool
        );
    }
}
