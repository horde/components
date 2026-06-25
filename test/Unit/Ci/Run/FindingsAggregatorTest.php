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

namespace Horde\Components\Test\Unit\Ci\Run;

use Horde\Components\Ci\Run\FindingsAggregator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the per-tool findings aggregator.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
#[CoversClass(FindingsAggregator::class)]
class FindingsAggregatorTest extends TestCase
{
    private string $tempBase;

    protected function setUp(): void
    {
        $this->tempBase = sys_get_temp_dir() . '/findings-aggregator-' . bin2hex(random_bytes(4));
        mkdir($this->tempBase, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->recursiveRm($this->tempBase);
    }

    public function testPhpStanIdenticalFindingAcrossLanesCollapsesToOne(): void
    {
        $native = [
            'files' => [
                '/tmp/horde-ci/lanes/php8.3-dev/Victim/src/Foo.php' => [
                    'errors' => 1,
                    'messages' => [
                        ['message' => 'm', 'line' => 10, 'identifier' => 'isset.property'],
                    ],
                ],
            ],
        ];
        $native2 = [
            'files' => [
                '/tmp/horde-ci/lanes/php8.4-dev/Victim/src/Foo.php' => [
                    'errors' => 1,
                    'messages' => [
                        ['message' => 'm', 'line' => 10, 'identifier' => 'isset.property'],
                    ],
                ],
            ],
        ];

        $laneA = $this->writeBuild('php8.3-dev', 'phpstan-native.json', $native);
        $laneB = $this->writeBuild('php8.4-dev', 'phpstan-native.json', $native2);

        $findings = (new FindingsAggregator())->aggregatePhpStan([
            'php8.3-dev' => $laneA,
            'php8.4-dev' => $laneB,
        ]);

        $this->assertCount(1, $findings);
        $this->assertSame('src/Foo.php', $findings[0]['file']);
        $this->assertSame(10, $findings[0]['line']);
        $this->assertSame('isset.property', $findings[0]['identifier']);
        $this->assertSame(['php8.3-dev', 'php8.4-dev'], $findings[0]['lanes']);
    }

    public function testPhpStanDifferentLineSplitsToTwoFindings(): void
    {
        $a = [
            'files' => [
                '/tmp/horde-ci/lanes/php8.3-dev/X/src/Foo.php' => [
                    'errors' => 2,
                    'messages' => [
                        ['message' => 'm', 'line' => 10, 'identifier' => 'isset.property'],
                        ['message' => 'm', 'line' => 20, 'identifier' => 'isset.property'],
                    ],
                ],
            ],
        ];
        $laneA = $this->writeBuild('php8.3-dev', 'phpstan-native.json', $a);

        $findings = (new FindingsAggregator())->aggregatePhpStan(['php8.3-dev' => $laneA]);
        $this->assertCount(2, $findings);
        $this->assertSame(10, $findings[0]['line']);
        $this->assertSame(20, $findings[1]['line']);
    }

    public function testPhpStanFallsBackToMessageWhenIdentifierMissing(): void
    {
        $a = [
            'files' => [
                '/tmp/horde-ci/lanes/php8.3-dev/X/src/Foo.php' => [
                    'errors' => 1,
                    'messages' => [
                        ['message' => 'msg-A', 'line' => 10],
                    ],
                ],
            ],
        ];
        $b = [
            'files' => [
                '/tmp/horde-ci/lanes/php8.4-dev/X/src/Foo.php' => [
                    'errors' => 1,
                    'messages' => [
                        ['message' => 'msg-B', 'line' => 10],
                    ],
                ],
            ],
        ];
        $laneA = $this->writeBuild('php8.3-dev', 'phpstan-native.json', $a);
        $laneB = $this->writeBuild('php8.4-dev', 'phpstan-native.json', $b);

        $findings = (new FindingsAggregator())->aggregatePhpStan([
            'php8.3-dev' => $laneA,
            'php8.4-dev' => $laneB,
        ]);

        // Different message text → distinct findings even at same file/line.
        $this->assertCount(2, $findings);
    }

    public function testPhpStanMissingNativeFileIsSkipped(): void
    {
        // Only one lane has a native file; the other has none.
        $a = [
            'files' => [
                '/x/src/Foo.php' => ['messages' => [['message' => 'm', 'line' => 1, 'identifier' => 'i']]],
            ],
        ];
        $laneA = $this->writeBuild('php8.3-dev', 'phpstan-native.json', $a);
        $laneB = $this->tempBase . '/php8.4-dev/build';
        mkdir($laneB, 0o755, true);

        $findings = (new FindingsAggregator())->aggregatePhpStan([
            'php8.3-dev' => $laneA,
            'php8.4-dev' => $laneB,
        ]);

        $this->assertCount(1, $findings);
        $this->assertSame(['php8.3-dev'], $findings[0]['lanes']);
    }

    public function testPhpCsFixerSameFileAcrossLanesCollapsesToOne(): void
    {
        $a = ['files' => [['name' => 'src/Foo.php']]];
        $b = ['files' => [['name' => 'src/Foo.php'], ['name' => 'src/Bar.php']]];

        $laneA = $this->writeBuild('php8.3-dev', 'php-cs-fixer-native.json', $a);
        $laneB = $this->writeBuild('php8.4-dev', 'php-cs-fixer-native.json', $b);

        $findings = (new FindingsAggregator())->aggregatePhpCsFixer([
            'php8.3-dev' => $laneA,
            'php8.4-dev' => $laneB,
        ]);

        $this->assertCount(2, $findings);
        $this->assertSame('src/Bar.php', $findings[0]['file']);
        $this->assertSame(['php8.4-dev'], $findings[0]['lanes']);
        $this->assertSame('src/Foo.php', $findings[1]['file']);
        $this->assertSame(['php8.3-dev', 'php8.4-dev'], $findings[1]['lanes']);
    }

    public function testEmptyInputsReturnEmpty(): void
    {
        $this->assertSame([], (new FindingsAggregator())->aggregatePhpStan([]));
        $this->assertSame([], (new FindingsAggregator())->aggregatePhpCsFixer([]));
    }

    public function testFindingsAreSortedDeterministically(): void
    {
        $a = [
            'files' => [
                '/tmp/horde-ci/lanes/X/Component/src/Z.php' => ['messages' => [['message' => 'm', 'line' => 5, 'identifier' => 'b']]],
                '/tmp/horde-ci/lanes/X/Component/src/A.php' => ['messages' => [['message' => 'm', 'line' => 10, 'identifier' => 'a']]],
            ],
        ];
        $laneA = $this->writeBuild('X', 'phpstan-native.json', $a);

        $findings = (new FindingsAggregator())->aggregatePhpStan(['X' => $laneA]);
        $this->assertSame('src/A.php', $findings[0]['file']);
        $this->assertSame('src/Z.php', $findings[1]['file']);
    }

    public function testPhpUnitSameFailureAcrossLanesCollapsesToOne(): void
    {
        // Same test, same message, four lanes - dedup to a single
        // finding with all four lanes listed.
        $record = [
            'type' => 'failure',
            'test_class' => 'Horde\\ActiveSync\\StateTest',
            'test_method' => 'testHierarchy',
            'file' => '/tmp/horde-ci/lanes/php8.2-dev/ActiveSync/test/unit/StateTest.php',
            'line' => 137,
            'exception_class' => 'PHPUnit\\Framework\\AssertionFailedError',
            'message' => 'Failed asserting that null is not null.',
            'trace' => 'stack trace lines...',
        ];

        $lanes = ['php8.2-dev', 'php8.3-dev', 'php8.4-dev', 'php8.5-dev'];
        $laneBuildDirs = [];
        foreach ($lanes as $laneName) {
            $perLane = $record;
            $perLane['file'] = "/tmp/horde-ci/lanes/{$laneName}/ActiveSync/test/unit/StateTest.php";
            $laneBuildDirs[$laneName] = $this->writeBuild(
                $laneName,
                'phpunit-results-summary.json',
                ['failures' => [$perLane], 'errors' => []]
            );
        }

        $findings = (new FindingsAggregator())->aggregatePhpUnit($laneBuildDirs);

        $this->assertCount(1, $findings);
        $this->assertSame('Horde\\ActiveSync\\StateTest', $findings[0]['test_class']);
        $this->assertSame('testHierarchy', $findings[0]['test_method']);
        $this->assertSame($lanes, $findings[0]['lanes']);
        // Path should be lane-relative: "test/unit/StateTest.php"
        // rather than "/tmp/horde-ci/lanes/<lane>/ActiveSync/...".
        $this->assertSame('test/unit/StateTest.php', $findings[0]['file']);
    }

    public function testPhpUnitDifferentMessageSplitsToTwoFindings(): void
    {
        // Same test name fails with different messages on different
        // PHP versions - two distinct entries, each tagging the lanes
        // that saw it.
        $base = [
            'type' => 'failure',
            'test_class' => 'Horde\\ActiveSync\\StateTest',
            'test_method' => 'testHierarchy',
            'file' => '/tmp/horde-ci/lanes/php8.2-dev/ActiveSync/test/unit/StateTest.php',
            'line' => 137,
            'exception_class' => 'PHPUnit\\Framework\\AssertionFailedError',
            'trace' => '',
        ];

        $laneA = $this->writeBuild('php8.2-dev', 'phpunit-results-summary.json', [
            'failures' => [['message' => 'Expected null'] + $base],
            'errors' => [],
        ]);
        $laneB = $this->writeBuild('php8.3-dev', 'phpunit-results-summary.json', [
            'failures' => [['message' => 'Expected array'] + $base],
            'errors' => [],
        ]);

        $findings = (new FindingsAggregator())->aggregatePhpUnit([
            'php8.2-dev' => $laneA,
            'php8.3-dev' => $laneB,
        ]);

        $this->assertCount(2, $findings);
        // Findings are sorted by (class, method); both have the same
        // pair here, so order is whatever PHP's stable sort produced.
        $messages = array_map(static fn (array $f): string => $f['message'], $findings);
        sort($messages);
        $this->assertSame(['Expected array', 'Expected null'], $messages);
    }

    public function testPhpUnitFailureAndErrorAreBothCaptured(): void
    {
        // A failure (assertion) and an error (uncaught throwable) on
        // the same lane should both end up in the result, each carrying
        // its own `type`.
        $laneA = $this->writeBuild('php8.3-dev', 'phpunit-results-summary.json', [
            'failures' => [[
                'type' => 'failure',
                'test_class' => 'FooTest',
                'test_method' => 'testAssert',
                'file' => '',
                'line' => 0,
                'exception_class' => 'AssertionFailedError',
                'message' => 'asserted false',
                'trace' => '',
            ]],
            'errors' => [[
                'type' => 'error',
                'test_class' => 'BarTest',
                'test_method' => 'testThrow',
                'file' => '',
                'line' => 0,
                'exception_class' => 'RuntimeException',
                'message' => 'boom',
                'trace' => '',
            ]],
        ]);

        $findings = (new FindingsAggregator())->aggregatePhpUnit(['php8.3-dev' => $laneA]);

        $this->assertCount(2, $findings);
        $types = array_map(static fn (array $f): string => $f['type'], $findings);
        sort($types);
        $this->assertSame(['error', 'failure'], $types);
    }

    public function testPhpUnitMissingSummaryFileIsSkipped(): void
    {
        // Lane wrote nothing (setup-failed or crashed before write):
        // aggregator must not throw, just skip the lane.
        $findings = (new FindingsAggregator())->aggregatePhpUnit([
            'php8.2-dev' => $this->tempBase . '/nonexistent/build',
        ]);
        $this->assertSame([], $findings);
    }

    public function testPhpUnitEmptyArraysProduceNoFindings(): void
    {
        // The "clean run" shape: summary exists, both arrays empty.
        $laneA = $this->writeBuild('php8.3-dev', 'phpunit-results-summary.json', [
            'failures' => [],
            'errors' => [],
        ]);
        $findings = (new FindingsAggregator())->aggregatePhpUnit(['php8.3-dev' => $laneA]);
        $this->assertSame([], $findings);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function writeBuild(string $laneName, string $filename, array $payload): string
    {
        $buildDir = $this->tempBase . '/' . $laneName . '/build';
        mkdir($buildDir, 0o755, true);
        file_put_contents(
            $buildDir . '/' . $filename,
            json_encode($payload, JSON_THROW_ON_ERROR)
        );
        return $buildDir;
    }

    private function recursiveRm(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->recursiveRm($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
