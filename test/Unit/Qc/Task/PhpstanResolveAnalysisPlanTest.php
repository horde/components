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

namespace Horde\Components\Test\Unit\Qc\Task;

use Horde\Components\Output;
use Horde\Components\Qc\Task\Phpstan;
use Horde\Components\Qc\Tasks as QcTasks;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Tests for `Phpstan::resolveAnalysisPlan()` — the matrix that decides
 * which directories PHPStan analyzes, which it scans for symbols only,
 * and whether the resulting findings are advisory (non-fatal for the
 * lane).
 *
 * The decision matrix:
 *
 *   | has src/ | has lib/ | paths:                          | scanDirectories: | advisory |
 *   |----------|----------|---------------------------------|------------------|----------|
 *   | yes      | yes      | src/ (+ migration/)             | lib/             | no       |
 *   | yes      | no       | src/ (+ migration/)             | —                | no       |
 *   | no       | yes      | lib/ (+ migration/)             | —                | yes      |
 *   | no       | no       | <componentPath> (+ migration/)  | —                | no       |
 *
 * `migration/` joins `paths:` independently when present.
 */
#[CoversClass(Phpstan::class)]
class PhpstanResolveAnalysisPlanTest extends TestCase
{
    private string $tmpDir = '';
    private Phpstan $task;
    private ReflectionClass $refl;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/phpstan-plan-test-' . uniqid();
        mkdir($this->tmpDir, 0o755, true);
        // Phpstan extends Qc\Task\Base which requires (QcTasks, Output).
        // resolveAnalysisPlan() only reads the filesystem, so stubs are
        // enough — neither dependency is touched by the method under test.
        $this->task = new Phpstan(
            $this->createStub(QcTasks::class),
            $this->createStub(Output::class),
        );
        $this->refl = new ReflectionClass(Phpstan::class);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            exec('rm -rf ' . escapeshellarg($this->tmpDir));
        }
    }

    public function testSrcOnlyComponent(): void
    {
        mkdir($this->tmpDir . '/src', 0o755);

        $plan = $this->resolveAnalysisPlan();

        $this->assertSame([$this->tmpDir . '/src'], $plan['paths']);
        $this->assertSame([], $plan['scanDirectories']);
        $this->assertFalse($plan['advisory']);
    }

    public function testSrcPlusLibLoadsLibAsScanDirectory(): void
    {
        mkdir($this->tmpDir . '/src', 0o755);
        mkdir($this->tmpDir . '/lib', 0o755);

        $plan = $this->resolveAnalysisPlan();

        $this->assertSame([$this->tmpDir . '/src'], $plan['paths']);
        $this->assertSame([$this->tmpDir . '/lib'], $plan['scanDirectories']);
        $this->assertFalse(
            $plan['advisory'],
            'src/-plus-lib/ is the modernised shape; PHPStan must fail the lane on findings.'
        );
    }

    public function testSrcLibAndMigrationPutsMigrationInPathsAndLibInScan(): void
    {
        mkdir($this->tmpDir . '/src', 0o755);
        mkdir($this->tmpDir . '/lib', 0o755);
        mkdir($this->tmpDir . '/migration', 0o755);

        $plan = $this->resolveAnalysisPlan();

        $this->assertSame(
            [$this->tmpDir . '/src', $this->tmpDir . '/migration'],
            $plan['paths']
        );
        $this->assertSame([$this->tmpDir . '/lib'], $plan['scanDirectories']);
        $this->assertFalse($plan['advisory']);
    }

    public function testLegacyOnlyComponentIsAdvisory(): void
    {
        // No src/, has lib/. PHPStan still runs against lib/ so the
        // report appears in the PR comment, but the lane keeps walking
        // regardless of what PHPStan finds — modernisation is opt-in,
        // not opt-out.
        mkdir($this->tmpDir . '/lib', 0o755);

        $plan = $this->resolveAnalysisPlan();

        $this->assertSame([$this->tmpDir . '/lib'], $plan['paths']);
        $this->assertSame([], $plan['scanDirectories']);
        $this->assertTrue($plan['advisory']);
    }

    public function testLegacyOnlyPlusMigrationStillAdvisory(): void
    {
        mkdir($this->tmpDir . '/lib', 0o755);
        mkdir($this->tmpDir . '/migration', 0o755);

        $plan = $this->resolveAnalysisPlan();

        $this->assertSame(
            [$this->tmpDir . '/lib', $this->tmpDir . '/migration'],
            $plan['paths']
        );
        $this->assertSame([], $plan['scanDirectories']);
        $this->assertTrue(
            $plan['advisory'],
            'Adding migration/ to a legacy-only component does not change the advisory verdict.'
        );
    }

    public function testNoConventionalLayoutFallsBackToComponentPath(): void
    {
        // No src/, no lib/. The component might still have a phpstan.neon
        // and analyzable content directly at its root. Hand componentPath
        // to PHPStan so the run is non-degenerate.
        $plan = $this->resolveAnalysisPlan();

        $this->assertSame([$this->tmpDir], $plan['paths']);
        $this->assertSame([], $plan['scanDirectories']);
        $this->assertFalse(
            $plan['advisory'],
            'Bare componentPath fallback is the enforced default; only legacy-lib/ is advisory.'
        );
    }

    public function testMigrationOnlyComponentPutsMigrationInPaths(): void
    {
        // Edge case: a metapackage that ships only migrations. Falls
        // into the "no src/, no lib/" matrix row, then migration/ is
        // added on top of the componentPath fallback.
        mkdir($this->tmpDir . '/migration', 0o755);

        $plan = $this->resolveAnalysisPlan();

        // componentPath fallback would normally apply, but migration/
        // present pushes it into paths instead.
        $this->assertSame([$this->tmpDir . '/migration'], $plan['paths']);
        $this->assertSame([], $plan['scanDirectories']);
        $this->assertFalse($plan['advisory']);
    }

    private function resolveAnalysisPlan(): array
    {
        $method = $this->refl->getMethod('resolveAnalysisPlan');
        $method->setAccessible(true);
        $result = $method->invoke($this->task, $this->tmpDir);
        $this->assertIsArray($result);
        return $result;
    }
}
