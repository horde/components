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

/**
 * Posts CI results as PR comments.
 *
 * Creates or updates a summary comment on pull requests with CI results.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class PrCommentReporter
{
    /**
     * Marker to identify bot comments.
     */
    private const COMMENT_MARKER = '<!-- horde-ci-results -->';

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
     * Post or update PR comment with CI results.
     *
     * @param string $owner Repository owner
     * @param string $repo Repository name
     * @param int $prNumber PR number
     * @param array<string,array<string,array<string,mixed>>> $results Results by lane and tool
     * @param array{passed: int, failed: int, total: int, failed_lanes: array<string>} $summary Summary statistics
     * @param string $runUrl URL to the GitHub Actions run
     */
    public function postComment(
        string $owner,
        string $repo,
        int $prNumber,
        array $results,
        array $summary,
        string $runUrl
    ): void {
        $this->output->info("Posting CI results comment to PR #{$prNumber}...");

        $markdown = $this->generateCommentMarkdown($results, $summary, $runUrl);

        try {
            // Find existing comment
            $existingComment = $this->findExistingComment($owner, $repo, $prNumber);

            if ($existingComment !== null) {
                // Update existing comment
                $this->apiClient->patch(
                    "/repos/{$owner}/{$repo}/issues/comments/{$existingComment['id']}",
                    ['body' => $markdown]
                );
                $this->output->ok("Updated existing PR comment");
            } else {
                // Create new comment
                $this->apiClient->post(
                    "/repos/{$owner}/{$repo}/issues/{$prNumber}/comments",
                    ['body' => $markdown]
                );
                $this->output->ok("Created new PR comment");
            }
        } catch (\Exception $e) {
            $this->output->warn("Failed to post PR comment: " . $e->getMessage());
        }
    }

    /**
     * Find existing bot comment on PR.
     *
     * @param string $owner Repository owner
     * @param string $repo Repository name
     * @param int $prNumber PR number
     * @return array<string,mixed>|null Comment data or null if not found
     */
    private function findExistingComment(string $owner, string $repo, int $prNumber): ?array
    {
        try {
            $comments = $this->apiClient->get(
                "/repos/{$owner}/{$repo}/issues/{$prNumber}/comments"
            );

            foreach ($comments as $comment) {
                if (strpos($comment['body'] ?? '', self::COMMENT_MARKER) !== false) {
                    return $comment;
                }
            }
        } catch (\Exception $e) {
            // Ignore errors finding existing comment
        }

        return null;
    }

    /**
     * Generate markdown for PR comment.
     *
     * @param array<string,array<string,array<string,mixed>>> $results Results by lane and tool
     * @param array{passed: int, failed: int, total: int, failed_lanes: array<string>} $summary Summary statistics
     * @param string $runUrl URL to the GitHub Actions run
     * @return string Markdown content
     */
    private function generateCommentMarkdown(
        array $results,
        array $summary,
        string $runUrl
    ): string {
        $md = self::COMMENT_MARKER . "\n";
        $md .= "## 🔍 CI Results\n\n";

        // Overall status
        if ($summary['failed'] === 0) {
            $md .= "**Overall**: ✅ All {$summary['total']} lanes passed\n\n";
        } else {
            $md .= "**Overall**: ❌ {$summary['failed']}/{$summary['total']} lanes failed\n\n";
        }

        // Summary by PHP version
        $md .= "### Summary by PHP Version\n\n";
        $md .= $this->generateVersionTable($results);
        $md .= "\n";

        // Quality metrics
        $md .= "### Quality Metrics\n\n";
        $md .= $this->generateQualityMetrics($results);
        $md .= "\n";

        // Failed lanes detail (if any)
        if ($summary['failed'] > 0) {
            $md .= "<details>\n";
            $md .= "<summary>❌ Failed Lanes</summary>\n\n";

            foreach ($summary['failed_lanes'] as $laneName) {
                $md .= "**{$laneName}**\n";
                $tools = $results[$laneName] ?? [];

                foreach ($tools as $toolName => $result) {
                    if (!($result['success'] ?? false) && !isset($result['skipped'])) {
                        $md .= "- " . $this->formatToolFailure($toolName, $result) . "\n";
                    }
                }

                $md .= "\n";
            }

            $md .= "</details>\n\n";
        }

        // Footer
        $md .= "---\n";
        $md .= "*CI powered by [horde-components](https://github.com/horde/components) • ";
        $md .= "[View full results]({$runUrl})*\n";

        return $md;
    }

    /**
     * Generate version summary table.
     *
     * @param array<string,array<string,array<string,mixed>>> $results Results by lane and tool
     * @return string Markdown table
     */
    private function generateVersionTable(array $results): string
    {
        // Group by PHP version
        $byVersion = [];

        foreach ($results as $laneName => $tools) {
            // Parse lane name: "php8.4-dev" -> version="8.4", stability="dev"
            if (!preg_match('/^php(\d+\.\d+)-(\w+)$/', $laneName, $matches)) {
                continue;
            }

            $version = $matches[1];
            $stability = $matches[2];

            $passed = $this->isLanePassed($tools);
            $byVersion[$version][$stability] = $passed ? '✅' : '❌';
        }

        // Sort versions
        uksort($byVersion, 'version_compare');

        // Build table
        $md = "| PHP | dev | alpha |\n";
        $md .= "|-----|-----|-------|\n";

        foreach ($byVersion as $version => $stabilities) {
            $md .= sprintf(
                "| %s | %s | %s |\n",
                $version,
                $stabilities['dev'] ?? '—',
                $stabilities['alpha'] ?? '—'
            );
        }

        return $md;
    }

    /**
     * Generate quality metrics section.
     *
     * @param array<string,array<string,array<string,mixed>>> $results Results by lane and tool
     * @return string Markdown content
     */
    private function generateQualityMetrics(array $results): string
    {
        $md = '';

        // Aggregate stats
        $phpunitStats = $this->aggregateToolStats($results, 'phpunit');
        $phpstanStats = $this->aggregateToolStats($results, 'phpstan');
        $csFixerStats = $this->aggregateToolStats($results, 'phpcsfixer');

        // PHPUnit
        if (!empty($phpunitStats)) {
            $tests = $phpunitStats['tests'] ?? 0;
            $failures = $phpunitStats['failures'] ?? 0;
            $errors = $phpunitStats['errors'] ?? 0;

            if ($failures === 0 && $errors === 0) {
                $md .= "- **PHPUnit**: {$tests} tests passed ✅\n";
            } else {
                $md .= "- **PHPUnit**: {$failures} failures, {$errors} errors ❌\n";
            }
        }

        // PHPStan
        if (!empty($phpstanStats)) {
            $errors = $phpstanStats['errors'] ?? 0;

            if ($errors === 0) {
                $md .= "- **PHPStan**: No errors found ✅\n";
            } else {
                $md .= "- **PHPStan**: {$errors} errors found ⚠️\n";
            }
        }

        // PHP-CS-Fixer
        if (!empty($csFixerStats)) {
            $filesChecked = $csFixerStats['files_checked'] ?? 0;
            $filesWithIssues = $csFixerStats['files_with_issues'] ?? 0;

            if ($filesWithIssues === 0) {
                $md .= "- **PHP-CS-Fixer**: {$filesChecked} files checked, no issues ✅\n";
            } else {
                $md .= "- **PHP-CS-Fixer**: {$filesWithIssues} files with issues ⚠️\n";
            }
        }

        return $md;
    }

    /**
     * Check if a lane passed.
     *
     * @param array<string,array<string,mixed>> $tools Tool results
     * @return bool
     */
    private function isLanePassed(array $tools): bool
    {
        foreach ($tools as $result) {
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
     * Format tool failure for display.
     *
     * @param string $toolName Tool name
     * @param array<string,mixed> $result Result data
     * @return string Formatted string
     */
    private function formatToolFailure(string $toolName, array $result): string
    {
        $stats = $result['statistics'] ?? [];

        $toolDisplay = match ($toolName) {
            'phpunit' => 'PHPUnit',
            'phpstan' => 'PHPStan',
            'phpcsfixer' => 'PHP-CS-Fixer',
            default => ucfirst($toolName),
        };

        if (isset($result['error'])) {
            return "{$toolDisplay}: Error - {$result['error']}";
        }

        return match ($toolName) {
            'phpunit' => sprintf(
                "%s: %d failures, %d errors",
                $toolDisplay,
                $stats['failures'] ?? 0,
                $stats['errors'] ?? 0
            ),
            'phpstan' => sprintf(
                "%s: %d errors found",
                $toolDisplay,
                $stats['errors'] ?? 0
            ),
            'phpcsfixer' => sprintf(
                "%s: %d files with issues",
                $toolDisplay,
                $stats['files_with_issues'] ?? 0
            ),
            default => "{$toolDisplay}: Failed",
        };
    }

    /**
     * Aggregate statistics for a specific tool.
     *
     * @param array<string,array<string,array<string,mixed>>> $results All results
     * @param string $tool Tool name
     * @return array<string,int> Aggregated stats
     */
    private function aggregateToolStats(array $results, string $tool): array
    {
        $aggregated = [];

        foreach ($results as $laneName => $tools) {
            if (!isset($tools[$tool])) {
                continue;
            }

            $result = $tools[$tool];

            if (isset($result['skipped']) || isset($result['error'])) {
                continue;
            }

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
