<?php

/**
 * Copyright 2017-2026 Horde LLC (http://www.horde.org/)
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

namespace Horde\Components\Test\Unit\Output\Presenter;

use PHPUnit\Framework\TestCase;
use Horde\Components\Output\Presenter\Unicode;
use Horde_Cli;

/**
 * Test the Unicode presenter.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 * @coversNothing
 */
class UnicodeTest extends TestCase
{
    private $cli;
    private $output = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Create a mock Horde_Cli that captures output
        $this->cli = $this->createMock(Horde_Cli::class);
        $this->output = [];

        // Capture writeln() calls
        $this->cli->method('writeln')
            ->willReturnCallback(function ($text) {
                $this->output[] = $text;
            });

        // Mock color formatting methods
        $this->cli->method('bold')
            ->willReturnCallback(fn($text) => "**{$text}**");
        $this->cli->method('blue')
            ->willReturnCallback(fn($text) => "BLUE[{$text}]");
        $this->cli->method('green')
            ->willReturnCallback(fn($text) => "GREEN[{$text}]");
        $this->cli->method('yellow')
            ->willReturnCallback(fn($text) => "YELLOW[{$text}]");
        $this->cli->method('red')
            ->willReturnCallback(fn($text) => "RED[{$text}]");
    }

    public function testOkWithColors(): void
    {
        $presenter = new Unicode($this->cli, true);
        $presenter->ok('Success message');

        $this->assertCount(1, $this->output);
        $this->assertStringContainsString('✅', $this->output[0]);
        $this->assertStringContainsString('Success message', $this->output[0]);
        $this->assertStringContainsString('GREEN', $this->output[0]);
    }

    public function testOkWithoutColors(): void
    {
        $presenter = new Unicode($this->cli, false);
        $presenter->ok('Success message');

        $this->assertCount(1, $this->output);
        $this->assertStringContainsString('✅', $this->output[0]);
        $this->assertStringContainsString('Success message', $this->output[0]);
        $this->assertStringNotContainsString('GREEN', $this->output[0]);
    }

    public function testWarnWithColors(): void
    {
        $presenter = new Unicode($this->cli, true);
        $presenter->warn('Warning message');

        $this->assertCount(1, $this->output);
        $this->assertStringContainsString('⚠️', $this->output[0]);
        $this->assertStringContainsString('Warning message', $this->output[0]);
        $this->assertStringContainsString('YELLOW', $this->output[0]);
    }

    public function testWarnWithoutColors(): void
    {
        $presenter = new Unicode($this->cli, false);
        $presenter->warn('Warning message');

        $this->assertCount(1, $this->output);
        $this->assertStringContainsString('⚠️', $this->output[0]);
        $this->assertStringContainsString('Warning message', $this->output[0]);
        $this->assertStringNotContainsString('YELLOW', $this->output[0]);
    }

    public function testInfoWithColors(): void
    {
        $presenter = new Unicode($this->cli, true);
        $presenter->info('Info message');

        $this->assertCount(1, $this->output);
        $this->assertStringContainsString('ℹ️', $this->output[0]);
        $this->assertStringContainsString('Info message', $this->output[0]);
        $this->assertStringContainsString('BLUE', $this->output[0]);
    }

    public function testInfoWithoutColors(): void
    {
        $presenter = new Unicode($this->cli, false);
        $presenter->info('Info message');

        $this->assertCount(1, $this->output);
        $this->assertStringContainsString('ℹ️', $this->output[0]);
        $this->assertStringContainsString('Info message', $this->output[0]);
        $this->assertStringNotContainsString('BLUE', $this->output[0]);
    }

    public function testErrorWithColors(): void
    {
        $presenter = new Unicode($this->cli, true);
        $presenter->error('Error message');

        $this->assertCount(1, $this->output);
        $this->assertStringContainsString('❌', $this->output[0]);
        $this->assertStringContainsString('Error message', $this->output[0]);
        $this->assertStringContainsString('RED', $this->output[0]);
    }

    public function testErrorWithoutColors(): void
    {
        $presenter = new Unicode($this->cli, false);
        $presenter->error('Error message');

        $this->assertCount(1, $this->output);
        $this->assertStringContainsString('❌', $this->output[0]);
        $this->assertStringContainsString('Error message', $this->output[0]);
        $this->assertStringNotContainsString('RED', $this->output[0]);
    }

    public function testBoldWithColors(): void
    {
        $presenter = new Unicode($this->cli, true);
        $presenter->bold('Bold text');

        $this->assertCount(1, $this->output);
        $this->assertEquals('**Bold text**', $this->output[0]);
    }

    public function testBoldWithoutColors(): void
    {
        $presenter = new Unicode($this->cli, false);
        $presenter->bold('Bold text');

        $this->assertCount(1, $this->output);
        $this->assertEquals('Bold text', $this->output[0]);
    }

    public function testBlueWithColors(): void
    {
        $presenter = new Unicode($this->cli, true);
        $presenter->blue('Blue text');

        $this->assertCount(1, $this->output);
        $this->assertEquals('BLUE[Blue text]', $this->output[0]);
    }

    public function testBlueWithoutColors(): void
    {
        $presenter = new Unicode($this->cli, false);
        $presenter->blue('Blue text');

        $this->assertCount(1, $this->output);
        $this->assertEquals('Blue text', $this->output[0]);
    }

    public function testGreenWithColors(): void
    {
        $presenter = new Unicode($this->cli, true);
        $presenter->green('Green text');

        $this->assertCount(1, $this->output);
        $this->assertEquals('GREEN[Green text]', $this->output[0]);
    }

    public function testGreenWithoutColors(): void
    {
        $presenter = new Unicode($this->cli, false);
        $presenter->green('Green text');

        $this->assertCount(1, $this->output);
        $this->assertEquals('Green text', $this->output[0]);
    }

    public function testYellowWithColors(): void
    {
        $presenter = new Unicode($this->cli, true);
        $presenter->yellow('Yellow text');

        $this->assertCount(1, $this->output);
        $this->assertEquals('YELLOW[Yellow text]', $this->output[0]);
    }

    public function testYellowWithoutColors(): void
    {
        $presenter = new Unicode($this->cli, false);
        $presenter->yellow('Yellow text');

        $this->assertCount(1, $this->output);
        $this->assertEquals('Yellow text', $this->output[0]);
    }

    public function testPlain(): void
    {
        $presenter = new Unicode($this->cli, true);
        $presenter->plain('Plain text');

        $this->assertCount(1, $this->output);
        $this->assertEquals('Plain text', $this->output[0]);
    }

    public function testPearOutputFormat(): void
    {
        $presenter = new Unicode($this->cli, true);
        $presenter->pear('Test content');

        $this->assertCount(3, $this->output);
        $this->assertStringContainsString('━', $this->output[0]);
        $this->assertStringContainsString('PEAR output START', $this->output[0]);
        $this->assertEquals('Test content', $this->output[1]);
        $this->assertStringContainsString('━', $this->output[2]);
        $this->assertStringContainsString('PEAR output END', $this->output[2]);
    }

    public function testMultipleOutputs(): void
    {
        $presenter = new Unicode($this->cli, true);

        $presenter->ok('First');
        $presenter->warn('Second');
        $presenter->info('Third');
        $presenter->error('Fourth');

        $this->assertCount(4, $this->output);
        $this->assertStringContainsString('✅', $this->output[0]);
        $this->assertStringContainsString('⚠️', $this->output[1]);
        $this->assertStringContainsString('ℹ️', $this->output[2]);
        $this->assertStringContainsString('❌', $this->output[3]);
    }

    public function testUnicodeSymbolsPresent(): void
    {
        $presenter = new Unicode($this->cli, false);

        $presenter->ok('Test');
        $presenter->warn('Test');
        $presenter->info('Test');
        $presenter->error('Test');

        // Verify all symbols are present (even without colors)
        $allOutput = implode('', $this->output);
        $this->assertStringContainsString('✅', $allOutput);
        $this->assertStringContainsString('⚠️', $allOutput);
        $this->assertStringContainsString('ℹ️', $allOutput);
        $this->assertStringContainsString('❌', $allOutput);
    }

    public function testNoColorFlagPreventsColorFormatting(): void
    {
        $presenter = new Unicode($this->cli, false);

        $presenter->ok('Test');
        $presenter->warn('Test');
        $presenter->info('Test');
        $presenter->error('Test');

        $allOutput = implode('', $this->output);

        // No color formatting should be present
        $this->assertStringNotContainsString('GREEN', $allOutput);
        $this->assertStringNotContainsString('YELLOW', $allOutput);
        $this->assertStringNotContainsString('BLUE', $allOutput);
        $this->assertStringNotContainsString('RED', $allOutput);
    }

    public function testSpecialCharactersInMessage(): void
    {
        $presenter = new Unicode($this->cli, false);
        $presenter->ok('Message with "quotes" and $special <chars>');

        $this->assertStringContainsString('Message with "quotes" and $special <chars>', $this->output[0]);
    }

    public function testMultilineMessage(): void
    {
        $presenter = new Unicode($this->cli, false);
        $presenter->info("Line 1\nLine 2\nLine 3");

        $this->assertStringContainsString("Line 1\nLine 2\nLine 3", $this->output[0]);
    }

    public function testSemanticDetected(): void
    {
        $presenter = new Unicode($this->cli, false);
        $presenter->semantic('detected', 'Found tool');

        $this->assertCount(1, $this->output);
        $this->assertStringContainsString('🔍', $this->output[0]);
        $this->assertStringContainsString('Found tool', $this->output[0]);
    }

    public function testSemanticDetectedWithColors(): void
    {
        $presenter = new Unicode($this->cli, true);
        $presenter->semantic('detected', 'Found tool');

        $this->assertCount(1, $this->output);
        $this->assertStringContainsString('BLUE', $this->output[0]);
        $this->assertStringContainsString('🔍', $this->output[0]);
    }

    public function testSemanticRunning(): void
    {
        $presenter = new Unicode($this->cli, false);
        $presenter->semantic('running', 'Process started');

        $this->assertStringContainsString('▶️', $this->output[0]);
        $this->assertStringContainsString('Process started', $this->output[0]);
    }

    public function testSemanticMetrics(): void
    {
        $presenter = new Unicode($this->cli, false);
        $presenter->semantic('metrics', 'Statistics');

        $this->assertStringContainsString('📊', $this->output[0]);
    }

    public function testSemanticRegression(): void
    {
        $presenter = new Unicode($this->cli, true);
        $presenter->semantic('regression', 'Quality degraded');

        $this->assertStringContainsString('📉', $this->output[0]);
        $this->assertStringContainsString('RED', $this->output[0]);
    }

    public function testSemanticImprovement(): void
    {
        $presenter = new Unicode($this->cli, true);
        $presenter->semantic('improvement', 'Quality improved');

        $this->assertStringContainsString('📈', $this->output[0]);
        $this->assertStringContainsString('GREEN', $this->output[0]);
    }

    public function testSemanticSkip(): void
    {
        $presenter = new Unicode($this->cli, false);
        $presenter->semantic('skip', 'Task skipped');

        $this->assertStringContainsString('⏭️', $this->output[0]);
    }

    public function testSemanticAuto(): void
    {
        $presenter = new Unicode($this->cli, true);
        $presenter->semantic('auto', 'Auto-correction');

        $this->assertStringContainsString('🤖', $this->output[0]);
        $this->assertStringContainsString('GREEN', $this->output[0]);
    }

    public function testSemanticFileOperations(): void
    {
        $presenter = new Unicode($this->cli, false);

        $presenter->semantic('created', 'File created');
        $presenter->semantic('updated', 'File updated');
        $presenter->semantic('deleted', 'File deleted');

        $this->assertStringContainsString('📝', $this->output[0]);
        $this->assertStringContainsString('🔄', $this->output[1]);
        $this->assertStringContainsString('🗑️', $this->output[2]);
    }

    public function testSemanticGitOperations(): void
    {
        $presenter = new Unicode($this->cli, false);

        $presenter->semantic('branch', 'Branch created');
        $presenter->semantic('push', 'Pushed to remote');
        $presenter->semantic('pull', 'Pulled from remote');
        $presenter->semantic('commit', 'Committed changes');
        $presenter->semantic('tag', 'Tagged release');

        $this->assertStringContainsString('🌿', $this->output[0]);
        $this->assertStringContainsString('📤', $this->output[1]);
        $this->assertStringContainsString('📥', $this->output[2]);
        $this->assertStringContainsString('💬', $this->output[3]);
        $this->assertStringContainsString('🏷️', $this->output[4]);
    }

    public function testSemanticConfigAndRelease(): void
    {
        $presenter = new Unicode($this->cli, false);

        $presenter->semantic('config', 'Config updated');
        $presenter->semantic('release', 'Package released');

        $this->assertStringContainsString('⚙️', $this->output[0]);
        $this->assertStringContainsString('📦', $this->output[1]);
    }

    public function testSemanticValidation(): void
    {
        $presenter = new Unicode($this->cli, true);
        $presenter->semantic('validation', 'Validation failed');

        $this->assertStringContainsString('✗', $this->output[0]);
        $this->assertStringContainsString('RED', $this->output[0]);
    }

    public function testSemanticUnknownCategoryFallsBack(): void
    {
        $presenter = new Unicode($this->cli, false);
        $presenter->semantic('unknown', 'Unknown category');

        // Should fall back to info symbol
        $this->assertStringContainsString('ℹ️', $this->output[0]);
    }

    public function testSemanticColorFlagRespected(): void
    {
        $presenter = new Unicode($this->cli, false);

        $presenter->semantic('regression', 'Test');
        $presenter->semantic('improvement', 'Test');

        $allOutput = implode('', $this->output);
        $this->assertStringNotContainsString('RED', $allOutput);
        $this->assertStringNotContainsString('GREEN', $allOutput);
    }

    public function testSemanticAcceptsTraditionalOk(): void
    {
        $presenter = new Unicode($this->cli, true);
        $presenter->semantic('ok', 'Success message');

        $this->assertCount(1, $this->output);
        $this->assertStringContainsString('✅', $this->output[0]);
        $this->assertStringContainsString('Success message', $this->output[0]);
    }

    public function testSemanticAcceptsTraditionalWarn(): void
    {
        $presenter = new Unicode($this->cli, true);
        $presenter->semantic('warn', 'Warning message');

        $this->assertCount(1, $this->output);
        $this->assertStringContainsString('⚠️', $this->output[0]);
        $this->assertStringContainsString('Warning message', $this->output[0]);
    }

    public function testSemanticAcceptsTraditionalInfo(): void
    {
        $presenter = new Unicode($this->cli, true);
        $presenter->semantic('info', 'Info message');

        $this->assertCount(1, $this->output);
        $this->assertStringContainsString('ℹ️', $this->output[0]);
        $this->assertStringContainsString('Info message', $this->output[0]);
    }

    public function testSemanticAcceptsTraditionalError(): void
    {
        $presenter = new Unicode($this->cli, true);
        $presenter->semantic('error', 'Error message');

        $this->assertCount(1, $this->output);
        $this->assertStringContainsString('❌', $this->output[0]);
        $this->assertStringContainsString('Error message', $this->output[0]);
    }
}
