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
use Horde\GithubApiClient\GithubClient;
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
     * @param GithubClient|null $apiClient GitHub API client (optional)
     */
    public function __construct(
        private readonly Output $output,
        private readonly ResultCollector $collector,
        private readonly string $componentsPath,
        private readonly string $workDir,
        private readonly ?GithubClient $apiClient = null
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
            // success: false now covers both real tool failures and missing
            // result files (W2 — addMissing records success: false).
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

        // Deliberately skipped (lane incompatible with this tool)
        if (isset($result['deliberate_skip']) && $result['deliberate_skip']) {
            return '⊘ Skipped';
        }

        // Missing result file (lane crashed before writing JSON, etc.)
        if (isset($result['missing']) && $result['missing']) {
            return '❌ Missing';
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

            // Skip lanes with no usable statistics
            if (isset($result['missing']) || isset($result['error']) || isset($result['deliberate_skip'])) {
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
            $html .= "                    <td>" . $this->formatToolForHtml($tools['phpunit'] ?? null) . "</td>\n";
            $html .= "                    <td>" . $this->formatToolForHtml($tools['phpstan'] ?? null) . "</td>\n";
            $html .= "                    <td>" . $this->formatToolForHtml($tools['phpcsfixer'] ?? null) . "</td>\n";
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
     * @param array<string,mixed>|null $result Tool result
     * @return string HTML string
     */
    private function formatToolForHtml(?array $result): string
    {
        if ($result === null) {
            return '<span class="status-skip">—</span>';
        }

        // Deliberately skipped (lane incompatible with this tool)
        if (isset($result['deliberate_skip']) && $result['deliberate_skip']) {
            return '<span class="status-skip">⊘ Skipped</span>';
        }

        // Missing result file (lane crashed before writing JSON, etc.)
        if (isset($result['missing']) && $result['missing']) {
            return '<span class="status-fail">❌ Missing</span>';
        }

        // Error
        if (isset($result['error'])) {
            return '<span class="status-fail">❌ Error</span>';
        }

        // Success/failure with stats
        $stats = $result['statistics'] ?? [];
        $success = $result['success'] ?? false;
        $emoji = $success ? '✅' : '❌';
        $class = $success ? 'status-pass' : 'status-fail';

        // Extract key metric
        $metric = '';
        if (isset($stats['tests'])) {
            $metric = "{$stats['tests']} tests";
        } elseif (isset($stats['errors'])) {
            $metric = "{$stats['errors']} errors";
        } elseif (isset($stats['files_checked'])) {
            $metric = "{$stats['files_checked']} files";
        }

        $display = $metric ? "{$emoji} {$metric}" : $emoji;
        return "<span class=\"{$class}\">{$display}</span>";
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
            $totalTests = $phpunitStats['tests'] ?? 0;
            $totalFailures = $phpunitStats['failures'] ?? 0;
            $totalErrors = $phpunitStats['errors'] ?? 0;
            $lanesRun = $phpunitStats['lanes_run'] ?? 0;
            $assertions = $phpunitStats['assertions'] ?? 0;

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
                                <span class="metric-label">Total tests:</span>
                                <span class="metric-value">{$totalTests}</span>
                            </div>
                            <div class="metric-row">
                                <span class="metric-label">Total assertions:</span>
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
            $totalErrors = $phpstanStats['errors'] ?? 0;
            $filesAnalyzed = $phpstanStats['files_analyzed'] ?? 0;
            $lanesRun = $phpstanStats['lanes_run'] ?? 0;

            $statusIcon = $totalErrors === 0 ? '✅' : '⚠️';
            $statusText = $totalErrors === 0
                ? 'No errors found'
                : "{$totalErrors} errors found";

            $html .= <<<HTML
                        <div class="metric-card">
                            <h3>{$statusIcon} PHPStan - {$statusText}</h3>
                            <div class="metric-row">
                                <span class="metric-label">Lanes executed:</span>
                                <span class="metric-value">{$lanesRun}</span>
                            </div>
                            <div class="metric-row">
                                <span class="metric-label">Files analyzed:</span>
                                <span class="metric-value">{$filesAnalyzed}</span>
                            </div>
                            <div class="metric-row">
                                <span class="metric-label">Errors:</span>
                                <span class="metric-value">{$totalErrors}</span>
                            </div>
                        </div>

                HTML;
        }

        // PHP-CS-Fixer section
        if (!empty($csFixerStats)) {
            $filesChecked = $csFixerStats['files_checked'] ?? 0;
            $filesWithIssues = $csFixerStats['files_with_issues'] ?? 0;

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

        $reporter = new PrCommentReporter($this->output, $this->apiClient);
        $reporter->postComment(
            owner: $owner,
            repo: $repoName,
            prNumber: (int) $prNumber,
            results: $this->collector->getResults(),
            summary: $this->collector->getSummary(),
            runUrl: $runUrl
        );
    }
}
