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

namespace Horde\Components\Test\Unit\Qc;

use Horde\Components\Qc\PipelineResult;
use Horde\Components\Task\Result;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PipelineResult.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
#[CoversClass(PipelineResult::class)]
class PipelineResultTest extends TestCase
{
    public function testEmptyResultHasNoFailures(): void
    {
        $result = new PipelineResult([]);

        $this->assertSame(0, $result->totalErrors());
        $this->assertFalse($result->hasFailures());
        $this->assertSame([], $result->failures());
        $this->assertSame('', $result->summary());
    }

    public function testCleanPipelineHasNoFailures(): void
    {
        $result = new PipelineResult([
            'lint' => Result::qc(0),
            'phpstan' => Result::qc(0),
        ]);

        $this->assertSame(0, $result->totalErrors());
        $this->assertFalse($result->hasFailures());
        $this->assertSame([], $result->failures());
        $this->assertSame('', $result->summary());
    }

    public function testTotalErrorsSumsAcrossTasks(): void
    {
        $result = new PipelineResult([
            'phpstan' => Result::qc(1),
            'unit' => Result::qc(3),
            'lint' => Result::qc(0),
        ]);

        $this->assertSame(4, $result->totalErrors());
        $this->assertTrue($result->hasFailures());
    }

    public function testFailuresExcludeCleanAndSkippedTasks(): void
    {
        $result = new PipelineResult([
            'phpstan' => Result::qc(1),
            'lint' => Result::qc(0),
            'md' => Result::skipped('phpmd not installed'),
        ]);

        $failures = $result->failures();

        $this->assertSame(['phpstan'], array_keys($failures));
    }

    public function testSummaryListsSingleFailingTaskWithSingularNoun(): void
    {
        $result = new PipelineResult([
            'phpstan' => Result::qc(1),
        ]);

        $this->assertSame('phpstan (1 error)', $result->summary());
    }

    public function testSummaryListsMultipleFailingTasksWithPlural(): void
    {
        $result = new PipelineResult([
            'phpstan' => Result::qc(1),
            'unit' => Result::qc(3),
        ]);

        $this->assertSame(
            'phpstan (1 error), unit (3 errors)',
            $result->summary()
        );
    }

    public function testSummaryOmitsCleanAndSkippedTasks(): void
    {
        $result = new PipelineResult([
            'lint' => Result::qc(0),
            'phpstan' => Result::qc(2),
            'md' => Result::skipped('phpmd not installed'),
        ]);

        $this->assertSame('phpstan (2 errors)', $result->summary());
    }
}
