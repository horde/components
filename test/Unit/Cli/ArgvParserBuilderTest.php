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

namespace Horde\Components\Test\Unit\Cli;

use PHPUnit\Framework\TestCase;
use Horde\Components\Cli\ArgvParserBuilder;
use Horde\Argv\Parser;

/**
 * Test the ArgvParserBuilder with cli_format option.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 * @coversNothing
 */
class ArgvParserBuilderTest extends TestCase
{
    public function testBuilderCreatesParser(): void
    {
        $builder = new ArgvParserBuilder();
        $parser = $builder->build();

        $this->assertInstanceOf(Parser::class, $parser);
    }

    public function testWithGlobalOptionsAddsCliFormatOption(): void
    {
        $builder = new ArgvParserBuilder();
        $builder->withGlobalOptions();
        $parser = $builder->build();

        // Parse test arguments with cli-format option
        [$options, $args] = $parser->parseArgs(['--cli-format=classic']);

        $this->assertEquals('classic', $options['cli_format']);
    }

    public function testCliFormatOptionDefaultsToAutodetect(): void
    {
        $builder = new ArgvParserBuilder();
        $builder->withGlobalOptions();
        $parser = $builder->build();

        // Parse with no format option
        [$options, $args] = $parser->parseArgs([]);

        $this->assertEquals('autodetect', $options['cli_format']);
    }

    public function testCliFormatOptionAcceptsAllValues(): void
    {
        $builder = new ArgvParserBuilder();
        $builder->withGlobalOptions();
        $parser = $builder->build();

        $validFormats = ['classic', 'ci', 'unicode', 'autodetect'];

        foreach ($validFormats as $format) {
            [$options, $args] = $parser->parseArgs(["--cli-format={$format}"]);
            $this->assertEquals($format, $options['cli_format']);
        }
    }

    public function testCliFormatOptionWithOtherOptions(): void
    {
        $builder = new ArgvParserBuilder();
        $builder->withGlobalOptions();
        $parser = $builder->build();

        [$options, $args] = $parser->parseArgs([
            '--cli-format=ci',
            '--nocolor',
            '--verbose',
        ]);

        $this->assertEquals('ci', $options['cli_format']);
        $this->assertTrue($options['nocolor']);
        $this->assertTrue($options['verbose']);
    }

    public function testAllGlobalOptionsPresent(): void
    {
        $builder = new ArgvParserBuilder();
        $builder->withGlobalOptions();
        $parser = $builder->build();

        [$options, $args] = $parser->parseArgs([]);

        // Verify key expected options are present
        // Note: boolean flags like quiet/verbose/pretend may not be in array until set
        $this->assertArrayHasKey('config', $options);
        $this->assertArrayHasKey('cli_format', $options);
        $this->assertEquals('autodetect', $options['cli_format']);
    }
}
