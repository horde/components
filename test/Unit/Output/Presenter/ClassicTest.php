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
use Horde\Components\Output\Presenter\Classic;
use Horde_Cli;

/**
 * Test the Classic presenter.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 * @coversNothing
 */
class ClassicTest extends TestCase
{
    private $cli;
    private $presenter;
    private $output = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Create a mock Horde_Cli that captures output
        $this->cli = $this->createMock(Horde_Cli::class);
        $this->output = [];

        // Capture message() calls
        $this->cli->method('message')
            ->willReturnCallback(function ($text, $type) {
                $this->output[] = ['method' => 'message', 'text' => $text, 'type' => $type];
            });

        // Capture writeln() calls
        $this->cli->method('writeln')
            ->willReturnCallback(function ($text) {
                $this->output[] = ['method' => 'writeln', 'text' => $text];
            });

        // Mock formatting methods to return formatted text
        $this->cli->method('bold')
            ->willReturnCallback(fn($text) => "**{$text}**");
        $this->cli->method('blue')
            ->willReturnCallback(fn($text) => "BLUE[{$text}]");
        $this->cli->method('green')
            ->willReturnCallback(fn($text) => "GREEN[{$text}]");
        $this->cli->method('yellow')
            ->willReturnCallback(fn($text) => "YELLOW[{$text}]");
    }

    public function testOkCallsMessageWithSuccessType(): void
    {
        $presenter = new Classic($this->cli, false);
        $presenter->ok('Test success message');

        $this->assertCount(1, $this->output);
        $this->assertEquals('message', $this->output[0]['method']);
        $this->assertEquals('Test success message', $this->output[0]['text']);
        $this->assertEquals('cli.success', $this->output[0]['type']);
    }

    public function testWarnCallsMessageWithWarningType(): void
    {
        $presenter = new Classic($this->cli, false);
        $presenter->warn('Test warning message');

        $this->assertCount(1, $this->output);
        $this->assertEquals('message', $this->output[0]['method']);
        $this->assertEquals('Test warning message', $this->output[0]['text']);
        $this->assertEquals('cli.warning', $this->output[0]['type']);
    }

    public function testInfoCallsMessageWithMessageType(): void
    {
        $presenter = new Classic($this->cli, false);
        $presenter->info('Test info message');

        $this->assertCount(1, $this->output);
        $this->assertEquals('message', $this->output[0]['method']);
        $this->assertEquals('Test info message', $this->output[0]['text']);
        $this->assertEquals('cli.message', $this->output[0]['type']);
    }

    public function testErrorCallsMessageWithErrorType(): void
    {
        $presenter = new Classic($this->cli, false);
        $presenter->error('Test error message');

        $this->assertCount(1, $this->output);
        $this->assertEquals('message', $this->output[0]['method']);
        $this->assertEquals('Test error message', $this->output[0]['text']);
        $this->assertEquals('cli.error', $this->output[0]['type']);
    }

    public function testBoldWithColors(): void
    {
        $presenter = new Classic($this->cli, false);
        $presenter->bold('Bold text');

        $this->assertCount(1, $this->output);
        $this->assertEquals('writeln', $this->output[0]['method']);
        $this->assertEquals('**Bold text**', $this->output[0]['text']);
    }

    public function testBoldWithNoColor(): void
    {
        $presenter = new Classic($this->cli, true);
        $presenter->bold('Bold text');

        $this->assertCount(1, $this->output);
        $this->assertEquals('writeln', $this->output[0]['method']);
        $this->assertEquals('Bold text', $this->output[0]['text']);
    }

    public function testBlueWithColors(): void
    {
        $presenter = new Classic($this->cli, false);
        $presenter->blue('Blue text');

        $this->assertCount(1, $this->output);
        $this->assertEquals('writeln', $this->output[0]['method']);
        $this->assertEquals('BLUE[Blue text]', $this->output[0]['text']);
    }

    public function testBlueWithNoColor(): void
    {
        $presenter = new Classic($this->cli, true);
        $presenter->blue('Blue text');

        $this->assertCount(1, $this->output);
        $this->assertEquals('writeln', $this->output[0]['method']);
        $this->assertEquals('Blue text', $this->output[0]['text']);
    }

    public function testGreenWithColors(): void
    {
        $presenter = new Classic($this->cli, false);
        $presenter->green('Green text');

        $this->assertCount(1, $this->output);
        $this->assertEquals('writeln', $this->output[0]['method']);
        $this->assertEquals('GREEN[Green text]', $this->output[0]['text']);
    }

    public function testGreenWithNoColor(): void
    {
        $presenter = new Classic($this->cli, true);
        $presenter->green('Green text');

        $this->assertCount(1, $this->output);
        $this->assertEquals('writeln', $this->output[0]['method']);
        $this->assertEquals('Green text', $this->output[0]['text']);
    }

    public function testYellowWithColors(): void
    {
        $presenter = new Classic($this->cli, false);
        $presenter->yellow('Yellow text');

        $this->assertCount(1, $this->output);
        $this->assertEquals('writeln', $this->output[0]['method']);
        $this->assertEquals('YELLOW[Yellow text]', $this->output[0]['text']);
    }

    public function testYellowWithNoColor(): void
    {
        $presenter = new Classic($this->cli, true);
        $presenter->yellow('Yellow text');

        $this->assertCount(1, $this->output);
        $this->assertEquals('writeln', $this->output[0]['method']);
        $this->assertEquals('Yellow text', $this->output[0]['text']);
    }

    public function testPlain(): void
    {
        $presenter = new Classic($this->cli, false);
        $presenter->plain('Plain text');

        $this->assertCount(1, $this->output);
        $this->assertEquals('writeln', $this->output[0]['method']);
        $this->assertEquals('Plain text', $this->output[0]['text']);
    }

    public function testPearOutputFormat(): void
    {
        $presenter = new Classic($this->cli, false);
        $presenter->pear('Test content');

        // Should output 7 items: 3 headers, content, 3 footers
        $this->assertCount(7, $this->output);

        // Check header
        $this->assertEquals('message', $this->output[0]['method']);
        $this->assertEquals('-------------------------------------------------', $this->output[0]['text']);
        $this->assertEquals('cli.message', $this->output[0]['type']);

        $this->assertEquals('message', $this->output[1]['method']);
        $this->assertEquals('PEAR output START', $this->output[1]['text']);
        $this->assertEquals('cli.message', $this->output[1]['type']);

        $this->assertEquals('message', $this->output[2]['method']);
        $this->assertEquals('-------------------------------------------------', $this->output[2]['text']);
        $this->assertEquals('cli.message', $this->output[2]['type']);

        // Check content
        $this->assertEquals('writeln', $this->output[3]['method']);
        $this->assertEquals('Test content', $this->output[3]['text']);

        // Check footer
        $this->assertEquals('message', $this->output[4]['method']);
        $this->assertEquals('-------------------------------------------------', $this->output[4]['text']);
        $this->assertEquals('cli.message', $this->output[4]['type']);

        $this->assertEquals('message', $this->output[5]['method']);
        $this->assertEquals('PEAR output END', $this->output[5]['text']);
        $this->assertEquals('cli.message', $this->output[5]['type']);

        $this->assertEquals('message', $this->output[6]['method']);
        $this->assertEquals('-------------------------------------------------', $this->output[6]['text']);
        $this->assertEquals('cli.message', $this->output[6]['type']);
    }

    public function testNoColorDisablesMessageTypes(): void
    {
        $presenter = new Classic($this->cli, true);

        $presenter->ok('Success');
        $presenter->warn('Warning');
        $presenter->info('Info');

        // With nocolor=true, message type should be empty string
        $this->assertEquals('', $this->output[0]['type']);
        $this->assertEquals('', $this->output[1]['type']);
        $this->assertEquals('', $this->output[2]['type']);
    }

    public function testErrorAlwaysHasType(): void
    {
        $presenter = new Classic($this->cli, true);
        $presenter->error('Error message');

        // Error always has type, even with nocolor
        $this->assertEquals('cli.error', $this->output[0]['type']);
    }

    public function testSemanticDetected(): void
    {
        $presenter = new Classic($this->cli, false);
        $presenter->semantic('detected', 'Found tool');

        $this->assertCount(1, $this->output);
        $this->assertEquals('message', $this->output[0]['method']);
        $this->assertEquals('Found tool', $this->output[0]['text']);
        $this->assertEquals('cli.message', $this->output[0]['type']);
    }

    public function testSemanticRegression(): void
    {
        $presenter = new Classic($this->cli, false);
        $presenter->semantic('regression', 'Quality degraded');

        $this->assertCount(1, $this->output);
        $this->assertEquals('cli.error', $this->output[0]['type']);
    }

    public function testSemanticImprovement(): void
    {
        $presenter = new Classic($this->cli, false);
        $presenter->semantic('improvement', 'Quality improved');

        $this->assertCount(1, $this->output);
        $this->assertEquals('cli.success', $this->output[0]['type']);
    }

    public function testSemanticSkip(): void
    {
        $presenter = new Classic($this->cli, false);
        $presenter->semantic('skip', 'Task skipped');

        $this->assertCount(1, $this->output);
        $this->assertEquals('', $this->output[0]['type']);  // No color for skip
    }

    public function testSemanticAuto(): void
    {
        $presenter = new Classic($this->cli, false);
        $presenter->semantic('auto', 'Auto-correction applied');

        $this->assertCount(1, $this->output);
        $this->assertEquals('cli.success', $this->output[0]['type']);
    }

    public function testSemanticUnknownCategoryFallsBack(): void
    {
        $presenter = new Classic($this->cli, false);
        $presenter->semantic('unknown-category', 'Unknown message');

        $this->assertCount(1, $this->output);
        $this->assertEquals('cli.message', $this->output[0]['type']);  // Falls back to info
    }

    public function testSemanticMultipleCategories(): void
    {
        $presenter = new Classic($this->cli, false);

        $presenter->semantic('detected', 'Tool found');
        $presenter->semantic('running', 'Process started');
        $presenter->semantic('metrics', 'Statistics ready');

        $this->assertCount(3, $this->output);
    }

    public function testSemanticAcceptsTraditionalOk(): void
    {
        $presenter = new Classic($this->cli, false);
        $presenter->semantic('ok', 'Success message');

        $this->assertCount(1, $this->output);
        $this->assertEquals('message', $this->output[0]['method']);
        $this->assertEquals('Success message', $this->output[0]['text']);
        $this->assertEquals('cli.success', $this->output[0]['type']);
    }

    public function testSemanticAcceptsTraditionalWarn(): void
    {
        $presenter = new Classic($this->cli, false);
        $presenter->semantic('warn', 'Warning message');

        $this->assertCount(1, $this->output);
        $this->assertEquals('message', $this->output[0]['method']);
        $this->assertEquals('Warning message', $this->output[0]['text']);
        $this->assertEquals('cli.warning', $this->output[0]['type']);
    }

    public function testSemanticAcceptsTraditionalInfo(): void
    {
        $presenter = new Classic($this->cli, false);
        $presenter->semantic('info', 'Info message');

        $this->assertCount(1, $this->output);
        $this->assertEquals('message', $this->output[0]['method']);
        $this->assertEquals('Info message', $this->output[0]['text']);
        $this->assertEquals('cli.message', $this->output[0]['type']);
    }

    public function testSemanticAcceptsTraditionalError(): void
    {
        $presenter = new Classic($this->cli, false);
        $presenter->semantic('error', 'Error message');

        $this->assertCount(1, $this->output);
        $this->assertEquals('message', $this->output[0]['method']);
        $this->assertEquals('Error message', $this->output[0]['text']);
        $this->assertEquals('cli.error', $this->output[0]['type']);
    }
}
