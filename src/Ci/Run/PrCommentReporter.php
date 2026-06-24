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
use Horde\GithubApiClient\GithubApiClient;
use Horde\GithubApiClient\GithubComment;
use Horde\GithubApiClient\GithubRepository;
use Exception;

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
     * @param GithubApiClient $apiClient GitHub API client
     */
    public function __construct(
        private readonly Output $output,
        private readonly GithubApiClient $apiClient
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
     * @param array<string,array<int,array<string,mixed>>> $findingsByTool
     *        Per-tool deduplicated findings list produced by
     *        {@see \Horde\Components\Ci\Run\FindingsAggregator}. Keys are
     *        tool names ("phpstan", "phpcsfixer"). When empty (e.g.
     *        because the aggregator wasn't run), the comment falls back to
     *        summed counts.
     */
    public function postComment(
        string $owner,
        string $repo,
        int $prNumber,
        array $results,
        array $summary,
        string $runUrl,
        array $findingsByTool = []
    ): void {
        $this->output->info("Posting CI results comment to PR #{$prNumber}...");

        $markdown = $this->generateCommentMarkdown($results, $summary, $runUrl, $findingsByTool);
        // The typed API takes a GithubRepository value object. Only owner +
        // name are used by the comment endpoints; description and clone-URL
        // can be empty.
        $repository = new GithubRepository($repo, "{$owner}/{$repo}", '', '');

        try {
            // Find existing comment (idempotency marker lives in the body).
            $existingComment = $this->findExistingComment($repository, $prNumber);

            if ($existingComment !== null) {
                $this->apiClient->updateComment($repository, $existingComment->id, $markdown);
                $this->output->ok("Updated existing PR comment");
            } else {
                $this->apiClient->createPullRequestComment($repository, $prNumber, $markdown);
                $this->output->ok("Created new PR comment");
            }
        } catch (Exception $e) {
            $this->output->warn("Failed to post PR comment: " . $e->getMessage());
        }
    }

    /**
     * Find an existing horde-components CI comment on the PR.
     *
     * Identifies a previous comment by the HTML marker we plant at the top
     * of every CI comment body. Returns null when no such comment exists or
     * the lookup fails.
     *
     * @param GithubRepository $repository Target repository.
     * @param int $prNumber PR number.
     * @return GithubComment|null
     */
    private function findExistingComment(GithubRepository $repository, int $prNumber): ?GithubComment
    {
        try {
            $comments = $this->apiClient->listPullRequestComments($repository, $prNumber);
            foreach ($comments as $comment) {
                if (str_contains($comment->body, self::COMMENT_MARKER)) {
                    return $comment;
                }
            }
        } catch (Exception $e) {
            // Lookup failure isn't fatal; the worst case is a duplicate
            // comment on the PR rather than a swallowed run.
            $this->output->warn(
                'Could not list PR comments to find existing CI comment: ' . $e->getMessage()
            );
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
        string $runUrl,
        array $findingsByTool = []
    ): string {
        $md = self::COMMENT_MARKER . "\n";
        $md .= "## 🔍 CI Results\n\n";

        // Overall status — the count line.
        if ($summary['failed'] === 0) {
            $md .= "**Overall**: ✅ All {$summary['total']} lanes passed\n\n";
        } else {
            $md .= "**Overall**: ❌ {$summary['failed']}/{$summary['total']} lanes failed\n\n";
        }

        // TL;DR — the tool breakdown. Drops the lane count (already on
        // Overall) and uses deduplicated finding counts where the
        // aggregator provided them.
        $md .= $this->generateTldr($results, $summary, $findingsByTool) . "\n\n";

        // Summary by PHP version
        $md .= "### Summary by PHP Version\n\n";
        $md .= $this->generateVersionTable($results);
        $md .= "\n";

        // Quality metrics
        $md .= "### Quality Metrics\n\n";
        $md .= $this->generateQualityMetrics($results, $findingsByTool);
        $md .= "\n";

        // Failed lanes detail (if any)
        if ($summary['failed'] > 0) {
            $md .= "<details>\n";
            $md .= "<summary>❌ Failed Lanes</summary>\n\n";

            foreach ($summary['failed_lanes'] as $laneName) {
                $md .= "**{$laneName}**\n";
                $tools = $results[$laneName] ?? [];

                foreach ($tools as $toolName => $result) {
                    if (!($result['success'] ?? false)) {
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
     * Build the TL;DR line at the top of the PR comment.
     *
     * Drops the lane count (already on the Overall line above) and shows
     * the per-tool breakdown.
     *
     * Green case:
     *   `**TL;DR:** ✅ All clean: 54 tests, 15 files scanned, 19 files styled.`
     *
     * Red case (with deduped findings):
     *   `**TL;DR:** ❌ Quality issues: PHPStan: 1 unique error in 6 lanes;
     *   PHP-CS-Fixer: 4 files.`
     *
     * Red case (no aggregator data, falls back to summed counts):
     *   `**TL;DR:** ❌ Quality issues: PHPStan: 6 errors.`
     *
     * @param array<string,array<string,array<string,mixed>>> $results Results by lane and tool
     * @param array{passed: int, failed: int, total: int, failed_lanes: array<string>} $summary
     * @param array<string,array<int,array<string,mixed>>> $findingsByTool Deduped findings per tool, optional
     * @return string Markdown line (no trailing newline).
     */
    private function generateTldr(array $results, array $summary, array $findingsByTool = []): string
    {
        $phpunit = $this->aggregateToolStats($results, 'phpunit');
        $phpstan = $this->aggregateToolStats($results, 'phpstan');
        $pcf = $this->aggregateToolStats($results, 'phpcsfixer');

        if ($summary['failed'] === 0) {
            $parts = [];
            // Tests/files are per-lane unit counts. Use the per-lane max so
            // a 6-lane matrix doesn't read as `324 tests`. Lane count from
            // either tool (whichever ran) frames the matrix size.
            $phpunitLanes = $phpunit['lanes_run'] ?? 0;
            $tests = $phpunit['tests_max'] ?? 0;
            $assertions = $phpunit['assertions_max'] ?? 0;
            if ($tests > 0) {
                $testsFragment = $assertions > 0
                    ? "{$tests} tests ({$assertions} assertions)"
                    : "{$tests} tests";
                $parts[] = $phpunitLanes > 1
                    ? "{$testsFragment} in {$phpunitLanes} lanes"
                    : $testsFragment;
            }
            $scanned = $phpstan['files_scanned_max'] ?? 0;
            if ($scanned > 0) {
                $parts[] = "{$scanned} files scanned";
            }
            $checked = $pcf['files_checked_max'] ?? 0;
            if ($checked > 0) {
                $parts[] = "{$checked} files styled";
            }
            $detail = $parts === [] ? '' : ': ' . implode(', ', $parts);
            return "**TL;DR:** ✅ All clean{$detail}.";
        }

        $reasons = [];
        $phpunitFailures = ($phpunit['failures'] ?? 0) + ($phpunit['errors'] ?? 0);
        if ($phpunitFailures > 0) {
            $reasons[] = "PHPUnit: {$phpunitFailures} test"
                . ($phpunitFailures === 1 ? '' : 's')
                . ' failed';
        }

        // PHPStan: prefer the deduped finding count (one row per distinct
        // file/line/identifier) over the lanes-summed count. Without dedup
        // a single bug repeated across 6 lanes reads as "6 errors", which
        // overstates the work.
        $phpstanFindings = $findingsByTool['phpstan'] ?? null;
        if (is_array($phpstanFindings) && $phpstanFindings !== []) {
            $unique = count($phpstanFindings);
            $laneCount = count($this->collectLanes($phpstanFindings));
            $reasons[] = sprintf(
                'PHPStan: %d unique error%s in %d lane%s',
                $unique,
                $unique === 1 ? '' : 's',
                $laneCount,
                $laneCount === 1 ? '' : 's'
            );
        } else {
            $phpstanErrors = $phpstan['errors'] ?? 0;
            if ($phpstanErrors > 0) {
                $reasons[] = "PHPStan: {$phpstanErrors} error"
                    . ($phpstanErrors === 1 ? '' : 's');
            }
        }

        // PHP-CS-Fixer: prefer the deduped file count too.
        $pcfFindings = $findingsByTool['phpcsfixer'] ?? null;
        if (is_array($pcfFindings) && $pcfFindings !== []) {
            $uniqueFiles = count($pcfFindings);
            $reasons[] = "PHP-CS-Fixer: {$uniqueFiles} file"
                . ($uniqueFiles === 1 ? '' : 's');
        } else {
            $pcfHits = $pcf['files_with_issues_max'] ?? 0;
            if ($pcfHits > 0) {
                $reasons[] = "PHP-CS-Fixer: {$pcfHits} file"
                    . ($pcfHits === 1 ? '' : 's');
            }
        }

        $detail = $reasons === [] ? '' : ': ' . implode('; ', $reasons);
        return "**TL;DR:** ❌ Quality issues{$detail}.";
    }

    /**
     * Count the unique lanes referenced by a list of deduped findings.
     *
     * @param array<int,array<string,mixed>> $findings
     * @return array<int,string>
     */
    private function collectLanes(array $findings): array
    {
        $lanes = [];
        foreach ($findings as $finding) {
            foreach ((array) ($finding['lanes'] ?? []) as $lane) {
                $lanes[(string) $lane] = true;
            }
        }
        return array_keys($lanes);
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
                $stabilities['dev'] ?? '-',
                $stabilities['alpha'] ?? '-'
            );
        }

        return $md;
    }

    /**
     * Generate quality metrics section.
     *
     * @param array<string,array<string,array<string,mixed>>> $results Results by lane and tool
     * @param array<string,array<int,array<string,mixed>>> $findingsByTool Deduped findings per tool, optional
     * @return string Markdown content
     */
    private function generateQualityMetrics(array $results, array $findingsByTool = []): string
    {
        $md = '';

        // Aggregate stats
        $phpunitStats = $this->aggregateToolStats($results, 'phpunit');
        $phpstanStats = $this->aggregateToolStats($results, 'phpstan');
        $csFixerStats = $this->aggregateToolStats($results, 'phpcsfixer');

        // PHPUnit
        if (!empty($phpunitStats)) {
            $tests = $phpunitStats['tests_max'] ?? 0;
            $assertions = $phpunitStats['assertions_max'] ?? 0;
            $failures = $phpunitStats['failures'] ?? 0;
            $errors = $phpunitStats['errors'] ?? 0;
            $lanes = $phpunitStats['lanes_run'] ?? 0;

            if ($failures === 0 && $errors === 0) {
                $testsFragment = $assertions > 0
                    ? "{$tests} tests ({$assertions} assertions)"
                    : "{$tests} tests";
                $md .= $lanes > 1
                    ? "- **PHPUnit**: {$testsFragment} passed in {$lanes} lanes ✅\n"
                    : "- **PHPUnit**: {$testsFragment} passed ✅\n";
            } else {
                $md .= "- **PHPUnit**: {$failures} failures, {$errors} errors ❌\n";
            }
        }

        // PHPStan: prefer deduped finding count when the aggregator
        // provided it. "1 unique error in 6 lanes" reads more honestly
        // than "6 errors found" when the same bug repeats across the matrix.
        if (!empty($phpstanStats)) {
            $phpstanFindings = $findingsByTool['phpstan'] ?? null;
            if (is_array($phpstanFindings) && $phpstanFindings !== []) {
                $unique = count($phpstanFindings);
                $laneCount = count($this->collectLanes($phpstanFindings));
                $md .= sprintf(
                    "- **PHPStan**: %d unique error%s in %d lane%s ⚠️\n",
                    $unique,
                    $unique === 1 ? '' : 's',
                    $laneCount,
                    $laneCount === 1 ? '' : 's'
                );
            } else {
                $errors = $phpstanStats['errors'] ?? 0;
                if ($errors === 0) {
                    $md .= "- **PHPStan**: No errors found ✅\n";
                } else {
                    $md .= sprintf(
                        "- **PHPStan**: %d error%s found ⚠️\n",
                        $errors,
                        $errors === 1 ? '' : 's'
                    );
                }
            }
        }

        // PHP-CS-Fixer
        if (!empty($csFixerStats)) {
            $filesChecked = $csFixerStats['files_checked_max'] ?? 0;
            $pcfFindings = $findingsByTool['phpcsfixer'] ?? null;
            if (is_array($pcfFindings) && $pcfFindings !== []) {
                $uniqueFiles = count($pcfFindings);
                $md .= sprintf(
                    "- **PHP-CS-Fixer**: %d unique file%s with issues (of %d checked) ⚠️\n",
                    $uniqueFiles,
                    $uniqueFiles === 1 ? '' : 's',
                    $filesChecked
                );
            } else {
                $filesWithIssues = $csFixerStats['files_with_issues_max'] ?? 0;
                if ($filesWithIssues === 0) {
                    $md .= "- **PHP-CS-Fixer**: {$filesChecked} files checked, no issues ✅\n";
                } else {
                    $md .= sprintf(
                        "- **PHP-CS-Fixer**: %d file%s with issues ⚠️\n",
                        $filesWithIssues,
                        $filesWithIssues === 1 ? '' : 's'
                    );
                }
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

        // Setup-failed lanes (F30) arrive here as `missing: true` with
        // a reason prefixed by "Setup failed: " by RunCommand. The
        // ResultCollector::addMissing contract carries that reason in
        // the `reason` key. We classify the user-facing rendering by
        // category if available; otherwise fall back to the raw reason.
        if (!empty($result['missing'])) {
            $reason = (string) ($result['reason'] ?? 'Missing result file');
            $category = (string) ($result['category'] ?? '');
            $tag = match ($category) {
                'stability_gate'   => '⚠️ Stability-gated',
                'platform_missing' => '❌ Platform requirement missing',
                'php_version'      => '❌ PHP version conflict',
                default            => '❌ Setup failed',
            };
            return "{$toolDisplay}: {$tag} ({$reason})";
        }

        return match ($toolName) {
            'phpunit' => sprintf(
                '%s: %d failure%s, %d error%s',
                $toolDisplay,
                $stats['failures'] ?? 0,
                ($stats['failures'] ?? 0) === 1 ? '' : 's',
                $stats['errors'] ?? 0,
                ($stats['errors'] ?? 0) === 1 ? '' : 's'
            ),
            'phpstan' => sprintf(
                '%s: %d error%s found',
                $toolDisplay,
                $stats['errors'] ?? 0,
                ($stats['errors'] ?? 0) === 1 ? '' : 's'
            ),
            'phpcsfixer' => sprintf(
                '%s: %d file%s with issues',
                $toolDisplay,
                $stats['files_with_issues'] ?? 0,
                ($stats['files_with_issues'] ?? 0) === 1 ? '' : 's'
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
                // Two views per numeric stat:
                //   `$key`        sum across lanes — meaningful for
                //                 finding counts (failures, errors)
                //                 that legitimately stack.
                //   `${key}_max`  highest value seen on any single
                //                 lane — meaningful for unit-of-work
                //                 counts (tests, assertions, files
                //                 scanned/checked) which are constant
                //                 per lane and would otherwise be
                //                 overstated by the lane count.
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
}
