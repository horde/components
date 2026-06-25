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

        // Overall status - the count line.
        if ($summary['failed'] === 0) {
            $md .= "**Overall**: ✅ All {$summary['total']} lanes passed\n\n";
        } else {
            $md .= "**Overall**: ❌ {$summary['failed']}/{$summary['total']} lanes failed\n\n";
        }

        // TL;DR - the tool breakdown. Drops the lane count (already on
        // Overall) and uses deduplicated finding counts where the
        // aggregator provided them.
        $md .= $this->generateTldr($results, $summary, $findingsByTool) . "\n\n";

        // Summary by PHP version
        $md .= "### Summary by PHP Version\n\n";
        $md .= $this->generateVersionTable($results);
        $md .= "\n";

        // Quality metrics
        $md .= "### Quality Metrics\n\n";
        $md .= $this->generateQualityMetrics($results, $findingsByTool, $runUrl);
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
     *   `**TL;DR:** All clean: 54 tests, 15 files scanned, 19 files styled.`
     *
     * Red case (with deduped findings):
     *   `**TL;DR:** Quality issues: PHPStan: 1 unique error in 6 lanes;
     *   PHP-CS-Fixer: 4 files.`
     *
     * Red case (no aggregator data, falls back to summed counts):
     *   `**TL;DR:** Quality issues: PHPStan: 6 errors.`
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
     * True when every lane that ran PHPStan reported `mode: advisory`.
     *
     * Advisory mode is set on the PHPStan task side for legacy-only
     * layouts (no src/, has lib/) - see
     * `Qc\Task\Phpstan::resolveAnalysisPlan()`. When all lanes carry
     * that label, the metric line should read "(advisory)" so a
     * reviewer doesn't read the findings as blocking. Mixed lane sets
     * (some advisory, some enforced) are extremely unlikely - a
     * component is either legacy-only or modernised on disk - but we
     * keep the strict-all-advisory rule to avoid mis-labelling when
     * one lane crashed before writing JSON and the rest were enforced.
     *
     * Lanes that didn't write a PHPStan result (missing/skipped/error)
     * don't count as advisory; they simply don't vote.
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
     * True when every lane that produced a PHPUnit result reported
     * `mode: no_test_suite` (no test/ directory found by Unit::run).
     *
     * The component has deliberately shipped without PHPUnit tests; the
     * quality-metrics row should read "no test suite" as a skip rather than
     * the misleading "did not run on any lane" failure the lanes_run===0
     * branch would otherwise produce. Missing/error/skip lanes don't
     * vote - they simply don't speak. Returns false when at least one
     * voter ran tests normally (or crashed mid-run) and is therefore
     * not a no-test-suite verdict.
     *
     * @param array<string,array<string,array<string,mixed>>> $results
     */
    private function allPhpUnitLanesNoTestSuite(array $results): bool
    {
        $seen = false;
        foreach ($results as $tools) {
            $result = $tools['phpunit'] ?? null;
            if (!is_array($result)) {
                continue;
            }
            if (isset($result['missing']) || isset($result['error']) || isset($result['deliberate_skip'])) {
                continue;
            }
            $seen = true;
            if (($result['mode'] ?? 'enforced') !== 'no_test_suite') {
                return false;
            }
        }
        return $seen;
    }

    /**
     * Generate version summary table.
     *
     * @param array<string,array<string,array<string,mixed>>> $results Results by lane and tool
     * @return string Markdown table
     */
    private function generateVersionTable(array $results): string
    {
        // Group by PHP version. Also collect the set of stabilities the
        // run actually exercised — the column headers below come from
        // that set rather than a hardcoded "dev | alpha" pair, which
        // silently dropped non-dev data for components above alpha.
        $byVersion = [];
        $stabilitiesSeen = [];

        foreach ($results as $laneName => $tools) {
            // Parse lane name: "php8.4-dev" -> version="8.4", stability="dev"
            if (!preg_match('/^php(\d+\.\d+)-(\w+)$/', $laneName, $matches)) {
                continue;
            }

            $version = $matches[1];
            $stability = $matches[2];

            $passed = $this->isLanePassed($tools);
            $byVersion[$version][$stability] = $passed ? '✅' : '❌';
            $stabilitiesSeen[$stability] = true;
        }

        // Sort versions
        uksort($byVersion, 'version_compare');

        // Column order: `dev` first (always present in any run), then
        // whatever other stability the component happened to be at
        // (alpha / beta / RC / stable) in observed order. Empty runs
        // fall back to "dev | stable" so the table still renders.
        $columns = ['dev'];
        unset($stabilitiesSeen['dev']);
        foreach (array_keys($stabilitiesSeen) as $stability) {
            $columns[] = $stability;
        }
        if (count($columns) === 1) {
            $columns[] = 'stable';
        }

        // Build table header from the column list
        $md = '| PHP | ' . implode(' | ', $columns) . " |\n";
        $md .= '|-----|' . str_repeat('-----|', count($columns)) . "\n";

        foreach ($byVersion as $version => $stabilities) {
            $cells = [$version];
            foreach ($columns as $column) {
                $cells[] = $stabilities[$column] ?? '-';
            }
            $md .= '| ' . implode(' | ', $cells) . " |\n";
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
    private function generateQualityMetrics(array $results, array $findingsByTool = [], string $runUrl = ''): string
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
            $lanesPassed = $phpunitStats['lanes_passed'] ?? 0;

            // Five distinct states.
            //
            //   1. "Library has no PHPUnit test suite." Every lane that
            //      attempted PHPUnit wrote `mode: no_test_suite` (the
            //      Unit task's marker when `test/` is absent on disk).
            //      Render as a skip - neither pass nor fail; the component
            //      is working as designed.
            //
            //   2. "PHPUnit never produced usable output." Every lane
            //      that tried PHPUnit had `missing: true` (setup-failed
            //      sidecar or no result JSON), so aggregateToolStats
            //      didn't count any usable lane. `lanes_run === 0`.
            //      Render as a failure with "did not run on any lane".
            //
            //   3. "PHPUnit ran but never collected a test." Lanes were
            //      counted, but `tests_max === 0`. Parse error during
            //      suite load, XML-config rejection, etc. Render as a failure
            //      with "exited non-zero with no test output".
            //
            //   4. "PHPUnit ran with failures or errors." Tests were
            //      collected and at least one failed/errored. Standard
            //      red render.
            //
            //   5. "PHPUnit clean." Tests collected, zero failures,
            //      zero errors, at least one lane reported success.
            //      Standard green render.
            //
            // States 1 and 2 both produce `lanes_run === 0` in the
            // aggregator. The fifth-state ordering below resolves the
            // ambiguity by checking the no-test-suite case first.
            $ran = $tests > 0;
            $clean = $failures === 0 && $errors === 0 && $lanesPassed > 0;

            if ($this->allPhpUnitLanesNoTestSuite($results)) {
                $md .= "- **PHPUnit**: no test suite (`test/` directory absent) ⊘\n";
            } elseif ($lanes === 0) {
                $md .= "- **PHPUnit**: did not run on any lane ❌\n";
            } elseif (!$ran) {
                $md .= sprintf(
                    "- **PHPUnit**: did not run (%d lane%s exited non-zero with no test output) ❌\n",
                    $lanes,
                    $lanes === 1 ? '' : 's'
                );
            } elseif ($clean) {
                $testsFragment = $assertions > 0
                    ? "{$tests} tests ({$assertions} assertions)"
                    : "{$tests} tests";
                $md .= $lanes > 1
                    ? "- **PHPUnit**: {$testsFragment} passed in {$lanes} lanes ✅\n"
                    : "- **PHPUnit**: {$testsFragment} passed ✅\n";
            } else {
                // Link the failures/errors counts to the workflow run's
                // artifacts section so the maintainer can fetch the raw
                // JUnit XML and per-lane JSON in one click. The deduped
                // table below answers "which tests" - the artifact link
                // answers "give me the stack trace and full message".
                $countFragment = sprintf('%d failures, %d errors', $failures, $errors);
                $linkedCount = ($runUrl !== '')
                    ? sprintf('[%s](%s#artifacts)', $countFragment, $runUrl)
                    : $countFragment;
                $md .= sprintf(
                    "- **PHPUnit**: %s out of %d tests in %d lane%s ❌\n",
                    $linkedCount,
                    $tests,
                    $lanes,
                    $lanes === 1 ? '' : 's'
                );
                $phpunitFindings = $findingsByTool['phpunit'] ?? null;
                if (is_array($phpunitFindings) && $phpunitFindings !== []) {
                    $hint = 'Per-test detail in the table below. ';
                    $hint .= ($runUrl !== '')
                        ? sprintf('[Raw JUnit XML and JSON are uploaded as workflow artifacts](%s#artifacts).', $runUrl)
                        : 'Raw JUnit XML and JSON are uploaded as workflow artifacts.';
                    $md .= '  ' . $hint . "\n";
                    $md .= $this->renderPhpUnitFailuresDetails($phpunitFindings);
                }
            }
        }

        // PHPStan: prefer deduped finding count when the aggregator
        // provided it. "1 unique error in 6 lanes" reads more honestly
        // than "6 errors found" when the same bug repeats across the matrix.
        if (!empty($phpstanStats)) {
            // "(advisory)" tag: every PHPStan-running lane was in
            // advisory mode (legacy-only layout, see
            // PHPStan::resolveAnalysisPlan). Findings still show up but
            // they didn't fail the lane and shouldn't read as blocking.
            $advisory = $this->allPhpStanLanesAdvisory($results);
            $advisorySuffix = $advisory ? ' (advisory)' : '';

            $phpstanFindings = $findingsByTool['phpstan'] ?? null;
            if (is_array($phpstanFindings) && $phpstanFindings !== []) {
                $unique = count($phpstanFindings);
                $laneCount = count($this->collectLanes($phpstanFindings));
                $md .= sprintf(
                    "- **PHPStan**%s: %d unique error%s in %d lane%s ⚠️\n",
                    $advisorySuffix,
                    $unique,
                    $unique === 1 ? '' : 's',
                    $laneCount,
                    $laneCount === 1 ? '' : 's'
                );
            } else {
                $errors = $phpstanStats['errors'] ?? 0;
                if ($errors === 0) {
                    $md .= "- **PHPStan**{$advisorySuffix}: No errors found ✅\n";
                } else {
                    $md .= sprintf(
                        "- **PHPStan**%s: %d error%s found ⚠️\n",
                        $advisorySuffix,
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
     * Render the collapsed "which tests failed" block for the PR comment.
     *
     * One row per deduplicated finding; lanes column lists which PHP
     * versions saw the same test fail the same way. Trace is omitted -
     * the JUnit XML artifact carries the full stack for offline triage.
     *
     * @param list<array{type:string,test_class:string,test_method:string,file:string,line:int,exception_class:string,message:string,trace:string,lanes:list<string>}> $findings
     */
    private function renderPhpUnitFailuresDetails(array $findings): string
    {
        $md = "  <details>\n";
        $md .= "  <summary>Which tests failed</summary>\n\n";
        $md .= "  | Type | Test | Lanes | Message |\n";
        $md .= "  |---|---|---|---|\n";
        foreach ($findings as $f) {
            $type = $f['type'] === 'failure' ? '❌ failure' : '⚠️ error';
            $testName = $this->escapeMd($f['test_class'] . '::' . $f['test_method']);
            $lanes = $this->escapeMd(implode(', ', $f['lanes']));
            $exception = $f['exception_class'] !== ''
                ? '[' . $this->escapeMd($f['exception_class']) . '] '
                : '';
            $message = $exception . $this->truncate($this->escapeMd($f['message']), 200);
            $md .= "  | {$type} | {$testName} | {$lanes} | {$message} |\n";
        }
        $md .= "  </details>\n";
        return $md;
    }

    /**
     * Lightly escape a string for inline markdown table content.
     */
    private function escapeMd(string $s): string
    {
        // Pipes and newlines would break the table layout; backticks are
        // tolerated.
        return strtr($s, ['|' => '\\|', "\n" => ' ', "\r" => '', "\t" => ' ']);
    }

    /**
     * Truncate to a max length with a trailing ellipsis when shortened.
     */
    private function truncate(string $s, int $max): string
    {
        if (mb_strlen($s) <= $max) {
            return $s;
        }
        return mb_substr($s, 0, $max - 1) . '…';
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

        // Setup-failed lanes arrive here as `missing: true` with
        // a reason prefixed by "Setup failed: " by RunCommand. The
        // ResultCollector::addMissing contract carries that reason in
        // the `reason` key. We classify the user-facing rendering by
        // category if available; otherwise fall back to the raw reason.
        if (!empty($result['missing'])) {
            $reason = (string) ($result['reason'] ?? 'Missing result file');
            // Keep the reason short. The classifier-tagged category
            // already names the *kind* of failure; a 2 KB composer
            // resolver paragraph adds noise, not signal. Truncate to one
            // sentence (or 200 chars max) so the PR comment stays
            // readable.
            $reason = $this->summariseReason($reason);
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
     * Compress a setup-failure reason to one short, signal-bearing line.
     *
     * Defense-in-depth guard. ComposerInstaller::extractError now strips the workflow-
     * command prefix and picks one informative line, so a freshly-produced
     * reason already arrives short. But this method runs against whatever
     * shape the pipeline hands it - including legacy markers written by
     * older versions of horde-components.phar that the maintainer might
     * have cached. We belt-and-brace here:
     *
     *   1. Decode the GitHub Actions `%0A`/`%0D` newline escapes so a
     *      single-line workflow-command payload is treated as the
     *      multi-line text it actually is.
     *   2. Drop the `Setup failed: ` prefix RunCommand always adds; the
     *      classifier-tagged category in the calling row says the same
     *      thing.
     *   3. Strip a leading `::error[ file=...]::` workflow-command prefix
     *      should one still be present.
     *   4. Take the first non-empty line.
     *   5. Cap at 200 characters and append an ellipsis so a maintainer can
     *      tell the value was trimmed.
     */
    private function summariseReason(string $reason): string
    {
        $reason = str_replace(['%0A', '%0D'], "\n", $reason);

        // RunCommand always prefixes "Setup failed: " - the category
        // tag already conveys this; redundant in the rendered cell.
        if (str_starts_with($reason, 'Setup failed: ')) {
            $reason = substr($reason, strlen('Setup failed: '));
        }

        // Defensive strip of a workflow-command prefix.
        $trimmed = ltrim($reason);
        if (str_starts_with($trimmed, '::error')) {
            $sep = strpos($trimmed, '::', 7);
            if ($sep !== false) {
                $reason = ltrim(substr($trimmed, $sep + 2));
            }
        }

        // First non-empty line wins; everything past it is composer's
        // verbose explanation, which the maintainer can read in the
        // build log if they need it.
        foreach (explode("\n", $reason) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $reason = $line;
                break;
            }
        }

        // Final length cap. 200 chars is enough for one composer-resolver
        // sentence ("Root composer.json requires horde/mapi ^2 || dev-... satisfiable by ...")
        // and short enough that the PR comment cell stays readable in a
        // table-shaped GitHub render.
        if (mb_strlen($reason) > 200) {
            $reason = mb_substr($reason, 0, 199) . '…';
        }
        return $reason;
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
        $aggregated = ['lanes_run' => 0, 'lanes_passed' => 0];

        foreach ($results as $laneName => $tools) {
            if (!isset($tools[$tool])) {
                continue;
            }

            $result = $tools[$tool];

            // Skip lanes with no usable statistics. `no_test_suite` joins
            // the missing/error/skip set: the lane technically wrote a
            // results JSON (success: true) but there were no tests to
            // run, so the lane shouldn't contribute to tests_max,
            // assertions, failures, etc. The fifth-state renderer below
            // recovers the no-test-suite count from a separate pass.
            if (isset($result['missing']) || isset($result['error']) || isset($result['deliberate_skip'])) {
                continue;
            }
            if (($result['mode'] ?? 'enforced') === 'no_test_suite') {
                continue;
            }

            $aggregated['lanes_run']++;
            // Track whether the tool itself reported success on
            // this lane. Without this, a PHPUnit that crashed at parse
            // time (exit 255, tests=0, failures=0, errors=0) was
            // indistinguishable from a clean run with no tests to count.
            if (!empty($result['success'])) {
                $aggregated['lanes_passed']++;
            }
            $stats = $result['statistics'] ?? [];
            foreach ($stats as $key => $value) {
                if (!is_numeric($value)) {
                    continue;
                }
                // Two views per numeric stat:
                //   `$key`        sum across lanes - meaningful for
                //                 finding counts (failures, errors)
                //                 that legitimately stack.
                //   `${key}_max`  highest value seen on any single
                //                 lane - meaningful for unit-of-work
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
