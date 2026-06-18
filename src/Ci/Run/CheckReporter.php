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
use Horde\GithubApiClient\GithubClient;
use Exception;

/**
 * Reports CI results to GitHub Check Runs API.
 *
 * Creates check runs for each tool×lane combination to provide
 * native GitHub PR integration.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class CheckReporter
{
    /**
     * Constructor.
     *
     * @param Output $output Output handler
     * @param GithubClient $apiClient GitHub API client
     */
    public function __construct(
        private readonly Output $output,
        private readonly GithubClient $apiClient
    ) {}

    /**
     * Report lane results as GitHub Check Runs.
     *
     * Creates a check run for each tool in each lane.
     *
     * @param string $owner Repository owner
     * @param string $repo Repository name
     * @param string $sha Commit SHA
     * @param array<string,array<string,array<string,mixed>>> $results Results by lane and tool
     */
    public function reportLaneResults(
        string $owner,
        string $repo,
        string $sha,
        array $results
    ): void {
        $this->output->info('Creating GitHub Check Runs...');

        $checkCount = 0;

        foreach ($results as $laneName => $tools) {
            foreach ($tools as $toolName => $result) {
                // Don't post Check Runs for deliberately-skipped tools.
                if (isset($result['deliberate_skip']) && $result['deliberate_skip']) {
                    continue;
                }

                $this->createCheckRun(
                    owner: $owner,
                    repo: $repo,
                    sha: $sha,
                    laneName: $laneName,
                    toolName: $toolName,
                    result: $result
                );

                $checkCount++;
            }
        }

        $this->output->ok("Created {$checkCount} check runs");
    }

    /**
     * Create a single check run.
     *
     * @param string $owner Repository owner
     * @param string $repo Repository name
     * @param string $sha Commit SHA
     * @param string $laneName Lane name (e.g., "php8.4-dev")
     * @param string $toolName Tool name (e.g., "phpunit")
     * @param array<string,mixed> $result Tool result
     */
    private function createCheckRun(
        string $owner,
        string $repo,
        string $sha,
        string $laneName,
        string $toolName,
        array $result
    ): void {
        $checkName = $this->formatCheckName($toolName, $laneName);
        $conclusion = $this->determineConclusion($result);
        $output = $this->formatOutput($toolName, $result);

        $data = [
            'name' => $checkName,
            'head_sha' => $sha,
            'status' => 'completed',
            'conclusion' => $conclusion,
            'output' => $output,
        ];

        try {
            $this->apiClient->post(
                "/repos/{$owner}/{$repo}/check-runs",
                $data
            );
        } catch (Exception $e) {
            $this->output->warn("Failed to create check run '{$checkName}': " . $e->getMessage());
        }
    }

    /**
     * Format check run name.
     *
     * @param string $toolName Tool name
     * @param string $laneName Lane name
     * @return string Formatted name
     */
    private function formatCheckName(string $toolName, string $laneName): string
    {
        $toolDisplay = match ($toolName) {
            'phpunit' => 'PHPUnit',
            'phpstan' => 'PHPStan',
            'phpcsfixer' => 'PHP-CS-Fixer',
            default => ucfirst($toolName),
        };

        return "{$toolDisplay} ({$laneName})";
    }

    /**
     * Determine check run conclusion.
     *
     * @param array<string,mixed> $result Tool result
     * @return string Conclusion (success, failure, neutral)
     */
    private function determineConclusion(array $result): string
    {
        // Error state
        if (isset($result['error'])) {
            return 'failure';
        }

        // Check if blocking failure
        $success = $result['success'] ?? false;

        if ($success) {
            return 'success';
        }

        // Check if this is a watermark failure (blocking) or just warnings
        // For now, all failures are blocking
        // TODO: Integrate watermark system to differentiate
        return 'failure';
    }

    /**
     * Format check run output.
     *
     * @param string $toolName Tool name
     * @param array<string,mixed> $result Tool result
     * @return array{title: string, summary: string} Output data
     */
    private function formatOutput(string $toolName, array $result): array
    {
        // Error state
        if (isset($result['error'])) {
            return [
                'title' => 'Error',
                'summary' => $result['error'],
            ];
        }

        $stats = $result['statistics'] ?? [];
        $success = $result['success'] ?? false;

        return match ($toolName) {
            'phpunit' => $this->formatPhpUnitOutput($stats, $success),
            'phpstan' => $this->formatPhpStanOutput($stats, $success),
            'phpcsfixer' => $this->formatPhpCsFixerOutput($stats, $success),
            default => [
                'title' => $success ? 'Passed' : 'Failed',
                'summary' => 'Test execution completed',
            ],
        };
    }

    /**
     * Format PHPUnit output.
     *
     * @param array<string,mixed> $stats Statistics
     * @param bool $success Success status
     * @return array{title: string, summary: string}
     */
    private function formatPhpUnitOutput(array $stats, bool $success): array
    {
        $tests = $stats['tests'] ?? 0;
        $assertions = $stats['assertions'] ?? 0;
        $failures = $stats['failures'] ?? 0;
        $errors = $stats['errors'] ?? 0;

        if ($success) {
            return [
                'title' => "✅ All {$tests} tests passed",
                'summary' => "**Tests**: {$tests}\n**Assertions**: {$assertions}\n\n✨ All tests passed successfully!",
            ];
        }

        $summary = "**Tests**: {$tests}\n**Assertions**: {$assertions}\n";

        if ($failures > 0) {
            $summary .= "**Failures**: {$failures}\n";
        }

        if ($errors > 0) {
            $summary .= "**Errors**: {$errors}\n";
        }

        $summary .= "\n❌ Some tests failed.";

        return [
            'title' => "❌ {$failures} failures, {$errors} errors",
            'summary' => $summary,
        ];
    }

    /**
     * Format PHPStan output.
     *
     * @param array<string,mixed> $stats Statistics
     * @param bool $success Success status
     * @return array{title: string, summary: string}
     */
    private function formatPhpStanOutput(array $stats, bool $success): array
    {
        $filesScanned = $stats['files_scanned'] ?? 0;
        $filesWithErrors = $stats['files_with_errors'] ?? 0;
        $errors = $stats['errors'] ?? 0;

        if ($success) {
            return [
                'title' => "✅ No errors found",
                'summary' => "**Files scanned**: {$filesScanned}\n**Errors**: 0\n\n✨ Static analysis passed!",
            ];
        }

        $summary = "**Files scanned**: {$filesScanned}\n"
            . "**Files with errors**: {$filesWithErrors}\n"
            . "**Errors**: {$errors}\n\n"
            . "⚠️ PHPStan found issues that need attention.";

        return [
            'title' => "⚠️ {$errors} errors found",
            'summary' => $summary,
        ];
    }

    /**
     * Format PHP-CS-Fixer output.
     *
     * @param array<string,mixed> $stats Statistics
     * @param bool $success Success status
     * @return array{title: string, summary: string}
     */
    private function formatPhpCsFixerOutput(array $stats, bool $success): array
    {
        $filesChecked = $stats['files_checked'] ?? 0;
        $filesWithIssues = $stats['files_with_issues'] ?? 0;

        if ($success) {
            return [
                'title' => "✅ No style issues",
                'summary' => "**Files checked**: {$filesChecked}\n**Issues**: 0\n\n✨ Code style looks good!",
            ];
        }

        $summary = "**Files checked**: {$filesChecked}\n**Files with issues**: {$filesWithIssues}\n\n";
        $summary .= "⚠️ Some files have code style issues.";

        return [
            'title' => "⚠️ {$filesWithIssues} files with issues",
            'summary' => $summary,
        ];
    }
}
