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

namespace Horde\Components\Test\Unit\Qc\Task;

use Horde\Components\Output;
use Horde\Components\Qc\Task\Phpcsfixer;
use Horde\Components\Qc\Tasks as QcTasks;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Tests for the PHP-CS-Fixer task — exit-code classifier and dry-run
 * JSON parser.
 *
 * The task as a whole spawns php-cs-fixer as a subprocess, which is hard
 * to drive in unit tests. These tests target the pure-data-shape methods
 * via reflection.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
#[CoversClass(Phpcsfixer::class)]
class PhpcsfixerTest extends TestCase
{
    public function testExitCodeZeroIsSuccess(): void
    {
        $this->assertTrue($this->callIsSuccess(0, true));
        $this->assertTrue($this->callIsSuccess(0, false));
    }

    public function testExitCodeEightIsFailure(): void
    {
        // 8 = "would be modified" in dry-run; treated as failure for CI gating.
        $this->assertFalse($this->callIsSuccess(8, true));
    }

    public function testToolingErrorCodes(): void
    {
        $this->assertTrue($this->callIsToolingError(16));
        $this->assertTrue($this->callIsToolingError(32));
        $this->assertTrue($this->callIsToolingError(64));
        $this->assertTrue($this->callIsToolingError(16 | 8)); // combined flags
    }

    public function testNonToolingErrorCodes(): void
    {
        $this->assertFalse($this->callIsToolingError(0));
        $this->assertFalse($this->callIsToolingError(1));
        $this->assertFalse($this->callIsToolingError(4));
        $this->assertFalse($this->callIsToolingError(8));
    }

    public function testParseDryRunCountsNameOnlyEntriesAsIssues(): void
    {
        $task = $this->makeTask();
        $native = [
            'files' => [
                ['name' => 'src/Foo.php'],
                ['name' => 'src/Bar.php'],
                ['name' => 'src/Baz.php'],
            ],
        ];

        $stats = $this->callParse($task, $native);

        $this->assertSame(3, $stats['files_with_issues']);
        $this->assertSame(0, $stats['files_fixed']); // no actual fixing in dry-run
    }

    public function testParseFixModeCountsAppliedFixers(): void
    {
        $task = $this->makeTask();
        $native = [
            'files' => [
                ['name' => 'src/Foo.php', 'appliedFixers' => ['braces']],
                ['name' => 'src/Bar.php', 'appliedFixers' => ['indentation_type', 'no_trailing_whitespace']],
                // entry without appliedFixers in fix mode shouldn't happen, but
                // count it as an issue too (lenient parsing)
                ['name' => 'src/Baz.php'],
            ],
        ];

        $stats = $this->callParse($task, $native);

        $this->assertSame(3, $stats['files_with_issues']);
        $this->assertSame(2, $stats['files_fixed']); // only entries with appliedFixers
    }

    private function callIsSuccess(int $exitCode, bool $isDryRun): bool
    {
        $task = $this->makeTask();
        $reflection = new ReflectionClass($task);
        $method = $reflection->getMethod('isSuccessExitCode');
        $method->setAccessible(true);
        return $method->invoke($task, $exitCode, $isDryRun);
    }

    private function callIsToolingError(int $exitCode): bool
    {
        $task = $this->makeTask();
        $reflection = new ReflectionClass($task);
        $method = $reflection->getMethod('isToolingErrorExitCode');
        $method->setAccessible(true);
        return $method->invoke($task, $exitCode);
    }

    /**
     * @param array<string,mixed> $native
     * @return array<string,int>
     */
    private function callParse(Phpcsfixer $task, array $native): array
    {
        $reflection = new ReflectionClass($task);

        $natProp = $reflection->getProperty('nativeResults');
        $natProp->setAccessible(true);
        $natProp->setValue($task, $native);

        $statsProp = $reflection->getProperty('stats');
        $statsProp->setAccessible(true);
        $statsProp->setValue($task, [
            'files_checked' => 0,
            'files_with_issues' => 0,
            'files_fixed' => 0,
            'files_invalid' => 0,
            'files_skipped' => 0,
        ]);

        $method = $reflection->getMethod('parseResults');
        $method->setAccessible(true);
        $method->invoke($task);

        return $statsProp->getValue($task);
    }

    private function makeTask(): Phpcsfixer
    {
        return new Phpcsfixer(
            $this->createMock(QcTasks::class),
            $this->createMock(Output::class)
        );
    }
}
