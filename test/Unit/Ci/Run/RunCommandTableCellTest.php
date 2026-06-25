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

use Horde\Components\Ci\Run\ResultCollector;
use Horde\Components\Ci\Run\RunCommand;
use Horde\Components\Output;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Tests for the per-tool table-cell rendering on the GitHub Actions
 * Step Summary. Each cell is a short, glanceable label tuned to the
 * tool: "all NN passed" / "NN fail or error" for PHPUnit;
 * "no errors" / "NN advisories" / "NN watermark errors" for PHPStan.
 *
 * The methods under test are private; the rendering only emits one
 * short string and has no side effects, so reflection is the
 * lightest-weight harness here.
 */
#[CoversClass(RunCommand::class)]
class RunCommandTableCellTest extends TestCase
{
    private RunCommand $cmd;
    private ReflectionClass $refl;

    protected function setUp(): void
    {
        $output = $this->createStub(Output::class);
        $collector = $this->createStub(ResultCollector::class);
        // The rendering helpers don't touch any constructor arg, but
        // RunCommand's signature wants a componentsPath + workDir, so
        // pass empty strings; the methods we exercise never read them.
        $this->cmd = new RunCommand($output, $collector, '', '');
        $this->refl = new ReflectionClass(RunCommand::class);
    }

    public function testPhpUnitCellAllPassed(): void
    {
        $cell = $this->format([
            'success' => true,
            'statistics' => ['tests' => 198, 'failures' => 0, 'errors' => 0],
        ], 'phpunit');
        $this->assertSame('✅ all 198 passed', $cell);
    }

    public function testPhpUnitCellFailOrError(): void
    {
        $cell = $this->format([
            'success' => false,
            'statistics' => ['tests' => 198, 'failures' => 2, 'errors' => 3],
        ], 'phpunit');
        $this->assertSame('❌ 5 fail or error', $cell);
    }

    public function testPhpUnitCellNoTestSuite(): void
    {
        $cell = $this->format([
            'success' => true,
            'mode' => 'no_test_suite',
            'statistics' => ['tests' => 0],
        ], 'phpunit');
        $this->assertSame('✅ no tests available', $cell);
    }

    public function testPhpUnitCellCouldNotRunOnZeroTests(): void
    {
        // PHPUnit exited non-zero with no test events fired
        // (parse error during suite load, XML-config rejection, etc.).
        $cell = $this->format([
            'success' => false,
            'exit_code' => 2,
            'statistics' => ['tests' => 0, 'failures' => 0, 'errors' => 0],
        ], 'phpunit');
        $this->assertSame('❌ Could not run', $cell);
    }

    public function testPhpUnitCellMissingResult(): void
    {
        $cell = $this->format([
            'success' => false,
            'missing' => true,
            'reason' => 'Setup failed: bcmath',
        ], 'phpunit');
        $this->assertSame('❌ Could not run', $cell);
    }

    public function testPhpUnitCellDeliberateSkip(): void
    {
        $cell = $this->format([
            'success' => true,
            'deliberate_skip' => true,
            'reason' => 'PHPUnit ^12 needs PHP 8.3+',
        ], 'phpunit');
        $this->assertSame('⊘ Skipped', $cell);
    }

    public function testPhpStanCellNoErrors(): void
    {
        $cell = $this->format([
            'success' => true,
            'mode' => 'enforced',
            'statistics' => ['errors' => 0],
        ], 'phpstan');
        $this->assertSame('✅ no errors', $cell);
    }

    public function testPhpStanCellAdvisory(): void
    {
        // Legacy-only lib/ layout (no src/). Findings appear but the
        // lane is not failed on them.
        $cell = $this->format([
            'success' => true,
            'mode' => 'advisory',
            'statistics' => ['errors' => 342],
        ], 'phpstan');
        $this->assertSame('✅ 342 advisories', $cell);
    }

    public function testPhpStanCellWatermarkErrors(): void
    {
        $cell = $this->format([
            'success' => false,
            'mode' => 'enforced',
            'statistics' => ['errors' => 12],
        ], 'phpstan');
        $this->assertSame('❌ 12 watermark errors', $cell);
    }

    public function testPhpStanCellCouldNotRunOnZeroErrors(): void
    {
        // PHPStan exited non-zero with zero findings: tooling failure.
        $cell = $this->format([
            'success' => false,
            'mode' => 'enforced',
            'statistics' => ['errors' => 0],
        ], 'phpstan');
        $this->assertSame('❌ Could not run', $cell);
    }

    public function testCellNullResult(): void
    {
        $cell = $this->format(null, 'phpunit');
        $this->assertSame('-', $cell);
    }

    /**
     * Helper: invoke the private formatToolForTable via reflection.
     */
    private function format(?array $result, string $tool): string
    {
        $method = $this->refl->getMethod('formatToolForTable');
        $method->setAccessible(true);
        return (string) $method->invoke($this->cmd, $result, $tool);
    }
}
