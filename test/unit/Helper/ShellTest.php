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

namespace Horde\Components\Test\Unit\Helper;

use Horde\Components\Helper\Shell;
use Horde\Components\Output;
use Horde\Components\Component\Task\SystemCallResult;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Test the Shell helper.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
#[CoversClass(Shell::class)]
class ShellTest extends TestCase
{
    public function testExecReturnsSystemCallResult(): void
    {
        $shell = new Shell();
        $result = $shell->exec('echo "test"');

        $this->assertInstanceOf(SystemCallResult::class, $result);
        $this->assertSame(0, $result->getReturnValue());
        $this->assertSame(['test'], $result->getOutputArray());
    }

    public function testExecInWorkingDirectory(): void
    {
        $shell = new Shell();
        $tmpDir = sys_get_temp_dir();
        $result = $shell->exec('pwd', $tmpDir);

        $this->assertSame(0, $result->getReturnValue());
        $this->assertStringContainsString($tmpDir, $result->getOutputString());
    }

    public function testExecWithNonZeroExitCode(): void
    {
        $shell = new Shell();
        $result = $shell->exec('exit 42');

        $this->assertSame(42, $result->getReturnValue());
    }

    public function testSystemReturnsString(): void
    {
        $shell = new Shell();

        // Capture stdout to prevent risky test warning
        ob_start();
        $result = $shell->system('echo "test"');
        ob_end_clean();

        $this->assertIsString($result);
        $this->assertStringContainsString('test', $result);
    }

    public function testSystemInWorkingDirectory(): void
    {
        $shell = new Shell();
        $tmpDir = sys_get_temp_dir();

        // Capture stdout to prevent risky test warning
        ob_start();
        $result = $shell->system('pwd', $tmpDir);
        ob_end_clean();

        $this->assertStringContainsString($tmpDir, $result);
    }

    public function testShellExecReturnsString(): void
    {
        $shell = new Shell();
        $result = $shell->shellExec('echo "test"');

        $this->assertIsString($result);
        $this->assertStringContainsString('test', $result);
    }

    public function testShellExecInWorkingDirectory(): void
    {
        $shell = new Shell();
        $tmpDir = sys_get_temp_dir();
        $result = $shell->shellExec('pwd', $tmpDir);

        $this->assertStringContainsString($tmpDir, $result);
    }

    public function testPretendModeDoesNotExecute(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'shell_test_');
        unlink($tmpFile); // Remove it so we can test it's not created

        $shell = new Shell(null, true);
        $result = $shell->exec("touch {$tmpFile}");

        $this->assertSame(0, $result->getReturnValue());
        $this->assertEmpty($result->getOutputArray());
        $this->assertFileDoesNotExist($tmpFile);
    }

    public function testPretendModeWithOutputShowsCommand(): void
    {
        $output = $this->createMock(Output::class);
        $output->expects($this->once())
            ->method('info')
            ->with($this->stringContains('Would run: "echo test"'));

        $shell = new Shell($output, true);
        $shell->exec('echo test');
    }

    public function testPretendModeWithWorkingDirShowsDir(): void
    {
        $output = $this->createMock(Output::class);
        $output->expects($this->once())
            ->method('info')
            ->with($this->stringContains('(in /tmp)'));

        $shell = new Shell($output, true);
        $shell->exec('echo test', '/tmp');
    }

    public function testPretendModeSystemReturnsEmptyString(): void
    {
        $shell = new Shell(null, true);
        $result = $shell->system('echo test');

        $this->assertSame('', $result);
    }

    public function testPretendModeShellExecReturnsEmptyString(): void
    {
        $shell = new Shell(null, true);
        $result = $shell->shellExec('echo test');

        $this->assertSame('', $result);
    }

    public function testStaticRunMethod(): void
    {
        $result = Shell::run('echo "test"');

        $this->assertInstanceOf(SystemCallResult::class, $result);
        $this->assertSame(0, $result->getReturnValue());
        $this->assertSame(['test'], $result->getOutputArray());
    }

    public function testStaticRunMethodWithWorkingDir(): void
    {
        $tmpDir = sys_get_temp_dir();
        $result = Shell::run('pwd', $tmpDir);

        $this->assertStringContainsString($tmpDir, $result->getOutputString());
    }

    public function testStaticRunSystemMethod(): void
    {
        // Capture stdout to prevent risky test warning
        ob_start();
        $result = Shell::runSystem('echo "test"');
        ob_end_clean();

        $this->assertIsString($result);
        $this->assertStringContainsString('test', $result);
    }

    public function testStaticCaptureMethod(): void
    {
        $result = Shell::capture('echo "test"');

        $this->assertIsString($result);
        $this->assertStringContainsString('test', $result);
    }

    public function testIsPretendReturnsFalseByDefault(): void
    {
        $shell = new Shell();
        $this->assertFalse($shell->isPretend());
    }

    public function testIsPretendReturnsTrueWhenEnabled(): void
    {
        $shell = new Shell(null, true);
        $this->assertTrue($shell->isPretend());
    }

    public function testExecPreservesCurrentDirectory(): void
    {
        $originalDir = getcwd();
        $tmpDir = sys_get_temp_dir();

        $shell = new Shell();
        $shell->exec('echo test', $tmpDir);

        $this->assertSame($originalDir, getcwd());
    }

    public function testSystemPreservesCurrentDirectory(): void
    {
        $originalDir = getcwd();
        $tmpDir = sys_get_temp_dir();

        $shell = new Shell();

        // Capture stdout to prevent risky test warning
        ob_start();
        $shell->system('echo test', $tmpDir);
        ob_end_clean();

        $this->assertSame($originalDir, getcwd());
    }

    public function testShellExecPreservesCurrentDirectory(): void
    {
        $originalDir = getcwd();
        $tmpDir = sys_get_temp_dir();

        $shell = new Shell();
        $shell->shellExec('echo test', $tmpDir);

        $this->assertSame($originalDir, getcwd());
    }
}
