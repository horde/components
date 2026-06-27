<?php

/**
 * Copyright 2026 Horde LLC (http://www.horde.org/)
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

namespace Horde\Components\Test\Unit\Ci\Run;

use Horde\Components\Ci\Run\PrCommentReporter;
use Horde\Components\Output;
use Horde\GithubApiClient\GithubApiClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Tests for the PR-comment rendering pieces that don't need the GitHub
 * API client: composer dump truncation and empty PHPUnit
 * run reporting).
 *
 * PrCommentReporter::postComment shells out to GitHub. We test the
 * private helpers behind it via reflection rather than mocking out
 * an entire HTTP transport; the helpers are pure functions of input
 * data and pure functions are the natural test target.
 */
#[CoversClass(PrCommentReporter::class)]
class PrCommentReporterTest extends TestCase
{
    private PrCommentReporter $reporter;
    private ReflectionClass $refl;

    protected function setUp(): void
    {
        // The Output and GithubApiClient instances are not exercised by
        // the helpers under test — only postComment() touches them.
        // createStub() is enough because we never call methods on them.
        $output = $this->createStub(Output::class);
        $apiClient = $this->createStub(GithubApiClient::class);
        $this->reporter = new PrCommentReporter($output, $apiClient);
        $this->refl = new ReflectionClass(PrCommentReporter::class);
    }

    /**
     * summariseReason should strip the workflow-command prefix,
     * decode %0A newlines, drop the "Setup failed: " prefix, take
     * the first informative line, and cap at 200 chars.
     */
    public function testSummariseReasonStripsWorkflowCommandPrefix(): void
    {
        $input = '::error ::Your requirements could not be resolved to an installable set of packages.';
        $this->assertSame(
            'Your requirements could not be resolved to an installable set of packages.',
            $this->summariseReason($input)
        );
    }

    public function testSummariseReasonDecodesWorkflowEscapedNewlines(): void
    {
        // The composer wall: single line in the workflow log, %0A separators.
        $input = '::error ::Your requirements could not be resolved.%0A%0A  Problem 1%0A    - Root composer.json requires horde/mapi.';
        $this->assertSame(
            'Your requirements could not be resolved.',
            $this->summariseReason($input)
        );
    }

    public function testSummariseReasonDropsSetupFailedPrefix(): void
    {
        $input = 'Setup failed: Composer install failed in /tmp/lane';
        $this->assertSame(
            'Composer install failed in /tmp/lane',
            $this->summariseReason($input)
        );
    }

    public function testSummariseReasonCapsAt200Chars(): void
    {
        // 250 chars of letters: should be cut at 199 + ellipsis (1) = 200 total.
        $longLine = str_repeat('A', 250);
        $result = $this->summariseReason($longLine);
        // 199 'A' + '…' (one multibyte char) = 200 visual characters.
        $this->assertSame(200, mb_strlen($result));
        $this->assertStringEndsWith('…', $result);
    }

    public function testSummariseReasonShortInputIsUnchanged(): void
    {
        $input = 'simple message';
        $this->assertSame('simple message', $this->summariseReason($input));
    }

    /**
     * When every PHPUnit lane crashed before producing test
     * output (e.g. PHP 8.3 parse-time error on PHP-8.4-only syntax),
     * the metric line must NOT read "0 tests passed ✅". The correct
     * render is "did not run … exited non-zero with no test output ❌".
     */
    public function testQualityMetricsRendersEmptyPhpUnitRunAsFailure(): void
    {
        // Lane exited 255, zero tests, zero failures, zero errors.
        // success: false because exit_code !== 0.
        $results = [
            'php8.3-dev' => [
                'phpunit' => [
                    'success' => false,
                    'exit_code' => 255,
                    'statistics' => [
                        'tests' => 0,
                        'assertions' => 0,
                        'failures' => 0,
                        'errors' => 0,
                        'skipped' => 0,
                    ],
                    'version' => '12.5.30',
                ],
            ],
        ];

        $md = $this->generateQualityMetrics($results);
        $phpunitLine = $this->extractPhpUnitLine($md);
        $this->assertNotNull($phpunitLine, 'PHPUnit metric line should be present');
        $this->assertStringContainsString('did not run', $phpunitLine);
        $this->assertStringContainsString('❌', $phpunitLine);
        $this->assertStringNotContainsString(
            '0 tests passed',
            $phpunitLine,
            'Regression: empty PHPUnit run rendered as success'
        );
        $this->assertStringNotContainsString(
            '✅',
            $phpunitLine,
            'Regression: success indicator leaked into a failed PHPUnit row'
        );
    }

    public function testQualityMetricsRendersCleanRunAsSuccess(): void
    {
        // Real successful lane: success: true, tests > 0, no failures.
        $results = [
            'php8.4-dev' => [
                'phpunit' => [
                    'success' => true,
                    'exit_code' => 0,
                    'statistics' => [
                        'tests' => 54,
                        'assertions' => 121,
                        'failures' => 0,
                        'errors' => 0,
                        'skipped' => 0,
                    ],
                    'version' => '12.5.30',
                ],
            ],
        ];

        $md = $this->generateQualityMetrics($results);
        $this->assertStringContainsString('PHPUnit', $md);
        $this->assertStringContainsString('54 tests', $md);
        $this->assertStringContainsString('✅', $md);
        $this->assertStringNotContainsString('did not run', $md);
    }

    public function testQualityMetricsRendersFailuresAsRed(): void
    {
        $results = [
            'php8.4-dev' => [
                'phpunit' => [
                    'success' => false,
                    'exit_code' => 2,
                    'statistics' => [
                        'tests' => 100,
                        'assertions' => 300,
                        'failures' => 2,
                        'errors' => 1,
                        'skipped' => 0,
                    ],
                    'version' => '12.5.30',
                ],
            ],
        ];

        $md = $this->generateQualityMetrics($results);
        // The metric line carries enough context for the reader to
        // tell "8 failures out of 8 tests" from "8 failures out of
        // 1000 tests". Previously the line only said "2 failures,
        // 1 errors" with no denominator at all.
        $this->assertStringContainsString('2 failures, 1 errors out of 100 tests in 1 lane', $md);
        $this->assertStringContainsString('❌', $md);
    }

    public function testFailedPhpUnitLinksCountToArtifacts(): void
    {
        // When the run URL is supplied (we're in a real GitHub Actions
        // context), the failure/error count fragment in the PHPUnit
        // metric line links to the workflow's artifacts section so a
        // maintainer can fetch the raw JUnit XML in one click.
        $results = [
            'php8.4-dev' => [
                'phpunit' => [
                    'success' => false,
                    'exit_code' => 2,
                    'statistics' => [
                        'tests' => 100,
                        'assertions' => 300,
                        'failures' => 2,
                        'errors' => 1,
                        'skipped' => 0,
                    ],
                ],
            ],
        ];
        $runUrl = 'https://github.com/horde/ActiveSync/actions/runs/12345';

        $md = $this->generateQualityMetrics($results, [], $runUrl);

        $this->assertStringContainsString(
            "[2 failures, 1 errors]({$runUrl}#artifacts)",
            $md,
            'Counts should link to the workflow artifacts section.'
        );
    }

    /**
     * Additional safety: a "missing" lane (RunCommand wrote nothing
     * because the setup-failed marker fired upstream) must not slip
     * through aggregateToolStats and feed zeros into the renderer at all.
     * The four-state extension fixes this edge case: when every PHPUnit
     * lane was missing (lanes_run === 0), the row reads "did not run on
     * any lane ❌" rather than the previous misleading "0 tests passed".
     */
    public function testQualityMetricsRendersAllMissingPhpUnitAsDidNotRun(): void
    {
        $results = [
            'php8.3-dev' => [
                'phpunit' => [
                    'success' => false,
                    'exit_code' => 1,
                    'missing' => true,
                    'reason' => 'Setup failed: bcmath missing',
                    'category' => 'platform_missing',
                ],
            ],
        ];
        $md = $this->generateQualityMetrics($results);
        $phpunitLine = $this->extractPhpUnitLine($md);
        $this->assertNotNull($phpunitLine);
        $this->assertStringContainsString('did not run on any lane', $phpunitLine);
        $this->assertStringContainsString('❌', $phpunitLine);
        $this->assertStringNotContainsString('0 tests passed', $phpunitLine);
        $this->assertStringNotContainsString('✅', $phpunitLine);
    }

    /**
     * F33: the matrix's non-dev column comes from observed lane
     * stabilities, not from a hardcoded "alpha" pair. For a component
     * at RC the column reads "RC" and the data goes into it; for stable
     * it reads "stable"; for alpha it still reads "alpha". The previous
     * hardcoded header silently dropped non-alpha data on the floor.
     */
    public function testVersionTableHeaderReflectsObservedStability(): void
    {
        $results = [
            'php8.3-dev' => ['phpunit' => ['success' => true, 'mode' => 'enforced', 'statistics' => []]],
            'php8.3-RC' => ['phpunit' => ['success' => true, 'mode' => 'enforced', 'statistics' => []]],
            'php8.4-dev' => ['phpunit' => ['success' => false, 'mode' => 'enforced', 'statistics' => []]],
            'php8.4-RC' => ['phpunit' => ['success' => true, 'mode' => 'enforced', 'statistics' => []]],
        ];
        $md = $this->generateVersionTable($results);

        // Header lists dev then RC, in that order.
        $this->assertStringContainsString('| PHP | dev | RC |', $md);

        // Data rows: 8.3 dev passed + RC passed; 8.4 dev failed + RC passed.
        $this->assertStringContainsString('| 8.3 | ✅ | ✅ |', $md);
        $this->assertStringContainsString('| 8.4 | ❌ | ✅ |', $md);

        // Regression guard: no leftover "alpha" header for a non-alpha run.
        $this->assertStringNotContainsString('alpha', $md);
    }

    public function testVersionTableAlphaStabilityStillRendersAsAlpha(): void
    {
        $results = [
            'php8.4-dev' => ['phpunit' => ['success' => true, 'mode' => 'enforced', 'statistics' => []]],
            'php8.4-alpha' => ['phpunit' => ['success' => false, 'mode' => 'enforced', 'statistics' => []]],
        ];
        $md = $this->generateVersionTable($results);

        $this->assertStringContainsString('| PHP | dev | alpha |', $md);
        $this->assertStringContainsString('| 8.4 | ✅ | ❌ |', $md);
    }

    public function testVersionTableEmptyResultsFallsBackToStableColumn(): void
    {
        // Defensive: an empty results array should still produce a
        // valid markdown table rather than a header with a single
        // column. The fallback is the canonical "dev | stable" pair.
        $md = $this->generateVersionTable([]);

        $this->assertStringContainsString('| PHP | dev | stable |', $md);
    }

    public function testPhpCsFixerDidNotRunOnAnyLaneRendersAsFailure(): void
    {
        // PHP-CS-Fixer runs on one designated lane. When that lane
        // setup-failed before reaching PHP-CS-Fixer there's no result
        // file on any lane; aggregateToolStats reports lanes_run = 0.
        // Previously this fell through to the green "0 files checked,
        // no issues" branch, masking the real outcome.
        $results = [
            'php8.4-dev' => [
                'phpcsfixer' => [
                    'success' => false,
                    'exit_code' => 1,
                    'missing' => true,
                    'reason' => 'Setup failed: bcmath missing',
                    'category' => 'platform_missing',
                ],
            ],
        ];
        $md = $this->generateQualityMetrics($results);
        $line = $this->extractPhpCsFixerLine($md);
        $this->assertNotNull($line);
        $this->assertStringContainsString('did not run on any lane', $line);
        $this->assertStringContainsString('❌', $line);
        $this->assertStringNotContainsString('no issues', $line);
        $this->assertStringNotContainsString('✅', $line);
    }

    public function testPhpCsFixerCleanRunStillRendersAsSuccess(): void
    {
        // The happy path must not regress when the designated lane
        // actually ran and reported no issues.
        $results = [
            'php8.4-dev' => [
                'phpcsfixer' => [
                    'success' => true,
                    'exit_code' => 0,
                    'mode' => 'enforced',
                    'statistics' => [
                        'files_checked' => 19,
                        'files_with_issues' => 0,
                    ],
                ],
            ],
        ];
        $md = $this->generateQualityMetrics($results);
        $line = $this->extractPhpCsFixerLine($md);
        $this->assertNotNull($line);
        $this->assertStringContainsString('19 files checked, no issues', $line);
        $this->assertStringContainsString('✅', $line);
    }

    public function testPhpCsFixerWithIssuesRendersAsWarning(): void
    {
        // Non-zero issue count: the existing yellow path stays.
        $results = [
            'php8.4-dev' => [
                'phpcsfixer' => [
                    'success' => false,
                    'exit_code' => 8,
                    'mode' => 'enforced',
                    'statistics' => [
                        'files_checked' => 19,
                        'files_with_issues' => 3,
                    ],
                ],
            ],
        ];
        $md = $this->generateQualityMetrics($results);
        $line = $this->extractPhpCsFixerLine($md);
        $this->assertNotNull($line);
        $this->assertStringContainsString('3 files with issues', $line);
        $this->assertStringContainsString('⚠️', $line);
    }

    /**
     * Watermark+1 advisory: the maintainer's promotion budget. When the
     * PHPStan task captures an `advisory_next_level` block, the PR
     * comment's success line appends "— N advisories at level X" so the
     * maintainer sees how close they are to the next promotion.
     */
    public function testPhpStanWatermarkAdvisorySuffixAppendedToSuccessLine(): void
    {
        $results = [
            'php8.3-dev' => [
                'phpstan' => [
                    'success' => true,
                    'exit_code' => 0,
                    'mode' => 'enforced',
                    'statistics' => [
                        'files_scanned' => 12,
                        'errors' => 0,
                    ],
                    'advisory_next_level' => [
                        'level' => 4,
                        'errors' => 12,
                        'files_with_errors' => 5,
                        'passing' => false,
                    ],
                ],
            ],
        ];
        $md = $this->generateQualityMetrics($results);
        $line = $this->extractPhpStanLine($md);
        $this->assertNotNull($line);
        $this->assertStringContainsString('No errors found ✅', $line);
        $this->assertStringContainsString('12 advisories at level 4', $line);
    }

    public function testPhpStanWatermarkAdvisorySingularGrammar(): void
    {
        // Single-error advisory should read "1 advisory" not "1 advisories".
        $results = [
            'php8.3-dev' => [
                'phpstan' => [
                    'success' => true,
                    'exit_code' => 0,
                    'mode' => 'enforced',
                    'statistics' => ['files_scanned' => 12, 'errors' => 0],
                    'advisory_next_level' => [
                        'level' => 5,
                        'errors' => 1,
                        'files_with_errors' => 1,
                        'passing' => false,
                    ],
                ],
            ],
        ];
        $md = $this->generateQualityMetrics($results);
        $line = $this->extractPhpStanLine($md);
        $this->assertNotNull($line);
        $this->assertStringContainsString('1 advisory at level 5', $line);
        $this->assertStringNotContainsString('1 advisories', $line);
    }

    public function testPhpStanWatermarkAdvisoryAbsentWhenNotCaptured(): void
    {
        // No `advisory_next_level` field at all - typical of a lane
        // already at max level or one where watermark failed. The
        // success line stays unchanged ("No errors found ✅").
        $results = [
            'php8.3-dev' => [
                'phpstan' => [
                    'success' => true,
                    'exit_code' => 0,
                    'mode' => 'enforced',
                    'statistics' => ['files_scanned' => 12, 'errors' => 0],
                    // no advisory_next_level
                ],
            ],
        ];
        $md = $this->generateQualityMetrics($results);
        $line = $this->extractPhpStanLine($md);
        $this->assertNotNull($line);
        $this->assertStringContainsString('No errors found ✅', $line);
        $this->assertStringNotContainsString('advisor', $line);
        $this->assertStringNotContainsString('at level', $line);
    }

    public function testPhpStanWatermarkAdvisoryNotShownOnHardFailure(): void
    {
        // Watermark failed AND a lane reported advisory data (defense
        // in depth - shouldn't happen in practice). The hard-error
        // line wins; we don't render the advisory suffix on a failing
        // line because the maintainer needs to fix the watermark
        // first.
        $results = [
            'php8.3-dev' => [
                'phpstan' => [
                    'success' => false,
                    'exit_code' => 1,
                    'mode' => 'enforced',
                    'statistics' => [
                        'files_scanned' => 12,
                        'errors' => 4,
                    ],
                    'advisory_next_level' => [
                        'level' => 4,
                        'errors' => 12,
                        'passing' => false,
                    ],
                ],
            ],
        ];
        $md = $this->generateQualityMetrics($results);
        $line = $this->extractPhpStanLine($md);
        $this->assertNotNull($line);
        $this->assertStringContainsString('4 errors found', $line);
        $this->assertStringContainsString('⚠️', $line);
        $this->assertStringNotContainsString('advisor', $line);
    }

    public function testPhpStanWatermarkAdvisoryUsesMaxAcrossLanes(): void
    {
        // Two lanes report advisory data with different counts at the
        // same level; PR comment takes the max as the upper-bound
        // promotion budget.
        $results = [
            'php8.3-dev' => [
                'phpstan' => [
                    'success' => true,
                    'exit_code' => 0,
                    'mode' => 'enforced',
                    'statistics' => ['files_scanned' => 12, 'errors' => 0],
                    'advisory_next_level' => [
                        'level' => 4,
                        'errors' => 8,
                        'passing' => false,
                    ],
                ],
            ],
            'php8.4-dev' => [
                'phpstan' => [
                    'success' => true,
                    'exit_code' => 0,
                    'mode' => 'enforced',
                    'statistics' => ['files_scanned' => 12, 'errors' => 0],
                    'advisory_next_level' => [
                        'level' => 4,
                        'errors' => 12,
                        'passing' => false,
                    ],
                ],
            ],
        ];
        $md = $this->generateQualityMetrics($results);
        $line = $this->extractPhpStanLine($md);
        $this->assertNotNull($line);
        $this->assertStringContainsString('12 advisories at level 4', $line);
        $this->assertStringNotContainsString('8 advisories', $line);
    }

    /**
     * Helper: pull just the PHPStan metric line out of a quality-metrics
     * block so assertions don't get false positives from the PHPUnit or
     * PHP-CS-Fixer rows.
     */
    private function extractPhpStanLine(string $md): ?string
    {
        foreach (explode("\n", $md) as $line) {
            if (str_starts_with($line, '- **PHPStan**')) {
                return $line;
            }
        }
        return null;
    }

    /**
     * Helper: invoke the private summariseReason via reflection.
     */
    private function summariseReason(string $input): string
    {
        $method = $this->refl->getMethod('summariseReason');
        $method->setAccessible(true);
        return (string) $method->invoke($this->reporter, $input);
    }

    /**
     * Helper: invoke the private generateQualityMetrics via reflection.
     *
     * @param array<string,array<string,array<string,mixed>>> $results
     */
    private function generateQualityMetrics(array $results, array $findingsByTool = [], string $runUrl = ''): string
    {
        $method = $this->refl->getMethod('generateQualityMetrics');
        $method->setAccessible(true);
        return (string) $method->invoke($this->reporter, $results, $findingsByTool, $runUrl);
    }

    /**
     * Helper: invoke the private generateVersionTable via reflection.
     *
     * @param array<string,array<string,array<string,mixed>>> $results
     */
    private function generateVersionTable(array $results): string
    {
        $method = $this->refl->getMethod('generateVersionTable');
        $method->setAccessible(true);
        return (string) $method->invoke($this->reporter, $results);
    }

    /**
     * Helper: pull just the PHPUnit metric line out of a quality-metrics
     * block so assertions don't get false positives from the PHPStan or
     * PHP-CS-Fixer rows (which legitimately use ✅ when those tools had
     * nothing to flag).
     */
    private function extractPhpUnitLine(string $md): ?string
    {
        foreach (explode("\n", $md) as $line) {
            if (str_starts_with($line, '- **PHPUnit**:')) {
                return $line;
            }
        }
        return null;
    }

    /**
     * Helper: pull just the PHP-CS-Fixer metric line out of a
     * quality-metrics block so assertions don't pick up the PHPUnit or
     * PHPStan rows by accident.
     */
    private function extractPhpCsFixerLine(string $md): ?string
    {
        foreach (explode("\n", $md) as $line) {
            if (str_starts_with($line, '- **PHP-CS-Fixer**:')) {
                return $line;
            }
        }
        return null;
    }
}
