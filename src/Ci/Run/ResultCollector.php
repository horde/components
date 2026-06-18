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

use Horde\Components\Output;

/**
 * Collects and aggregates test results from QC JSON output files.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class ResultCollector
{
    /**
     * Results by lane and tool.
     *
     * Structure: [lane_name => [tool => [success, exit_code, statistics, ...]]]
     *
     * @var array<string,array<string,array<string,mixed>>>
     */
    private array $results = [];

    /**
     * Constructor.
     *
     * @param Output $output Output handler
     */
    public function __construct(
        private readonly Output $output
    ) {}

    /**
     * Add result from a QC JSON file.
     *
     * @param string $laneName Lane name (e.g., "php8.4-dev")
     * @param string $tool Tool name ("phpunit", "phpstan", "phpcsfixer")
     * @param string $jsonFile Path to JSON result file
     */
    public function addResultFromFile(string $laneName, string $tool, string $jsonFile): void
    {
        if (!file_exists($jsonFile)) {
            $this->results[$laneName][$tool] = [
                'success' => false,
                'exit_code' => 1,
                'error' => 'Result file not found',
            ];
            return;
        }

        $content = file_get_contents($jsonFile);
        if ($content === false) {
            $this->results[$laneName][$tool] = [
                'success' => false,
                'exit_code' => 1,
                'error' => 'Failed to read result file',
            ];
            return;
        }

        $data = json_decode($content, true);
        if ($data === null) {
            $this->results[$laneName][$tool] = [
                'success' => false,
                'exit_code' => 1,
                'error' => 'Invalid JSON in result file',
            ];
            return;
        }

        $this->results[$laneName][$tool] = [
            'success' => $data['success'] ?? false,
            'exit_code' => $data['exit_code'] ?? 1,
            'statistics' => $data['statistics'] ?? [],
            'version' => $this->extractVersion($tool, $data),
        ];
    }

    /**
     * Record a missing result for a tool.
     *
     * Used when a result JSON was expected but never produced (lane script
     * absent, lane script crashed before writing JSON, etc.). Counted as a
     * lane failure: a missing JSON cannot be distinguished from a tool that
     * succeeded silently and is therefore treated as the worst case.
     *
     * Deliberate, value-judged skips (e.g. PHPUnit constraint not satisfiable
     * on the lane's PHP version) belong in a future addSkipped() with
     * `success: true`; they are not modeled here.
     *
     * @param string $laneName Lane name
     * @param string $tool Tool name
     * @param string $reason Reason the result is missing
     */
    public function addMissing(string $laneName, string $tool, string $reason): void
    {
        $this->results[$laneName][$tool] = [
            'success' => false,
            'exit_code' => 1,
            'missing' => true,
            'reason' => $reason,
        ];
    }

    /**
     * Extract tool version from result data.
     *
     * @param string $tool Tool name
     * @param array<string,mixed> $data Result data
     * @return string Tool version
     */
    private function extractVersion(string $tool, array $data): string
    {
        $versionKeys = [
            'phpunit' => 'phpunit_version',
            'phpstan' => 'phpstan_version',
            'phpcsfixer' => 'php_cs_fixer_version',
        ];

        $key = $versionKeys[$tool] ?? "{$tool}_version";
        return $data[$key] ?? 'unknown';
    }

    /**
     * Display summary to output.
     */
    public function displaySummary(): void
    {
        $this->output->bold("\n=== Test Results ===\n");

        // Display results for each lane
        foreach ($this->results as $laneName => $tools) {
            $this->output->info("\n{$laneName}:");

            foreach ($tools as $tool => $result) {
                $this->displayToolResult($tool, $result);
            }
        }

        // Display summary
        $summary = $this->getSummary();
        $this->output->bold("\n=== Summary ===");

        if ($summary['passed'] > 0) {
            $this->output->ok("✅ Passed: {$summary['passed']}/{$summary['total']} lanes");
        }

        if ($summary['failed'] > 0) {
            $this->output->error("❌ Failed: {$summary['failed']}/{$summary['total']} lanes");
            foreach ($summary['failed_lanes'] as $laneName) {
                $this->output->plain("  - {$laneName}");
            }
        }

        if ($summary['failed'] === 0) {
            $this->output->ok("\n✨ All lanes passed!");
        }
    }

    /**
     * Display result for a single tool.
     *
     * @param string $tool Tool name
     * @param array<string,mixed> $result Result data
     */
    private function displayToolResult(string $tool, array $result): void
    {
        $toolName = ucfirst($tool);

        // Handle missing result file
        if (isset($result['missing']) && $result['missing']) {
            $this->output->error("  ✗ {$toolName}: Missing result ({$result['reason']})");
            return;
        }

        // Handle errors
        if (isset($result['error'])) {
            $this->output->error("  ✗ {$toolName}: {$result['error']}");
            return;
        }

        // Format statistics
        $stats = $this->formatStats($tool, $result['statistics'] ?? []);
        $statsStr = $stats ? " ({$stats})" : '';

        if ($result['success']) {
            $this->output->ok("  ✓ {$toolName}: Passed{$statsStr}");
        } else {
            $this->output->error("  ✗ {$toolName}: FAILED{$statsStr}");
        }
    }

    /**
     * Format statistics for display.
     *
     * @param string $tool Tool name
     * @param array<string,mixed> $stats Statistics array
     * @return string Formatted string
     */
    private function formatStats(string $tool, array $stats): string
    {
        if (empty($stats)) {
            return '';
        }

        switch ($tool) {
            case 'phpunit':
                $parts = [];
                if (isset($stats['tests'])) {
                    $parts[] = "{$stats['tests']} tests";
                }
                if (isset($stats['assertions'])) {
                    $parts[] = "{$stats['assertions']} assertions";
                }
                if (isset($stats['failures']) && $stats['failures'] > 0) {
                    $parts[] = "{$stats['failures']} failures";
                }
                if (isset($stats['errors']) && $stats['errors'] > 0) {
                    $parts[] = "{$stats['errors']} errors";
                }
                return implode(', ', $parts);

            case 'phpstan':
                $parts = [];
                if (isset($stats['files_analyzed'])) {
                    $parts[] = "{$stats['files_analyzed']} files";
                }
                if (isset($stats['errors'])) {
                    $parts[] = "{$stats['errors']} errors";
                }
                return implode(', ', $parts);

            case 'phpcsfixer':
                $parts = [];
                if (isset($stats['files_checked'])) {
                    $parts[] = "{$stats['files_checked']} files";
                }
                if (isset($stats['files_with_issues'])) {
                    $parts[] = "{$stats['files_with_issues']} issues";
                }
                return implode(', ', $parts);

            default:
                return '';
        }
    }

    /**
     * Get summary statistics.
     *
     * @return array{passed: int, failed: int, total: int, failed_lanes: array<string>}
     */
    public function getSummary(): array
    {
        $passed = 0;
        $failed = 0;
        $failedLanes = [];

        foreach ($this->results as $laneName => $tools) {
            $lanePassed = true;

            foreach ($tools as $tool => $result) {
                // success: false covers both real tool failures and missing
                // result files (W2 — addMissing records success: false).
                if (!$result['success']) {
                    $lanePassed = false;
                    break;
                }
            }

            if ($lanePassed) {
                $passed++;
            } else {
                $failed++;
                $failedLanes[] = $laneName;
            }
        }

        return [
            'passed' => $passed,
            'failed' => $failed,
            'total' => count($this->results),
            'failed_lanes' => $failedLanes,
        ];
    }

    /**
     * Check if all lanes passed.
     *
     * @return bool
     */
    public function allPassed(): bool
    {
        $summary = $this->getSummary();
        return $summary['failed'] === 0;
    }

    /**
     * Get all results.
     *
     * @return array<string,array<string,array<string,mixed>>>
     */
    public function getResults(): array
    {
        return $this->results;
    }
}
