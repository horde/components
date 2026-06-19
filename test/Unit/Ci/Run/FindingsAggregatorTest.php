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
