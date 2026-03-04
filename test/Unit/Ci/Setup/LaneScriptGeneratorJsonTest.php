<?php

/**
 * Copyright 2013-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf-lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

declare(strict_types=1);

namespace Horde\Components\Test\Unit\Ci\Setup;

use Horde\Components\Ci\Setup\LaneScriptGenerator;
use Horde\Components\Output;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for LaneScriptGenerator JSON result generation.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf-lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
#[CoversClass(LaneScriptGenerator::class)]
class LaneScriptGeneratorJsonTest extends TestCase
{
    private LaneScriptGenerator $generator;
    private Output $output;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->output = $this->createMock(Output::class);
        $this->generator = new LaneScriptGenerator($this->output);
        $this->tempDir = sys_get_temp_dir() . '/lane-script-test-' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            exec('rm -rf ' . escapeshellarg($this->tempDir));
        }
    }

    public function testScriptGeneratesPhpunitJsonSummary(): void
    {
        $scriptPath = $this->tempDir . '/run-lane.sh';
        $config = [
            'lane_name' => 'php8.4-dev',
            'php_version' => '8.4',
            'php_binary' => '/usr/bin/php8.4',
            'stability' => 'dev',
            'component_dir' => $this->tempDir . '/component',
            'tools_dir' => $this->tempDir . '/tools',
            'build_dir' => $this->tempDir . '/component/build',
        ];

        $this->generator->generate($scriptPath, $config);

        $script = file_get_contents($scriptPath);

        // Should generate JSON summary file for PHPUnit
        $this->assertStringContainsString(
            'phpunit-results-summary.json',
            $script,
            'Script should write PHPUnit JSON summary'
        );

        // Should extract test statistics from PHPUnit output
        $this->assertStringContainsString(
            '"success"',
            $script,
            'JSON should include success field'
        );

        $this->assertStringContainsString(
            '"exit_code"',
            $script,
            'JSON should include exit_code field'
        );
    }

    public function testScriptParsesPhpunitXmlForStatistics(): void
    {
        $scriptPath = $this->tempDir . '/run-lane.sh';
        $config = [
            'lane_name' => 'php8.4-dev',
            'php_version' => '8.4',
            'php_binary' => '/usr/bin/php8.4',
            'stability' => 'dev',
            'component_dir' => $this->tempDir . '/component',
            'tools_dir' => $this->tempDir . '/tools',
            'build_dir' => $this->tempDir . '/component/build',
        ];

        $this->generator->generate($scriptPath, $config);

        $script = file_get_contents($scriptPath);

        // Should parse JUnit XML for test counts
        $this->assertStringContainsString(
            'phpunit-results.xml',
            $script,
            'Script should read PHPUnit XML results'
        );

        // Should extract statistics (tests, failures, errors, etc.)
        $this->assertStringContainsString(
            '"statistics"',
            $script,
            'JSON should include statistics field'
        );
    }

    public function testScriptWritesPhpstanJsonSummary(): void
    {
        $scriptPath = $this->tempDir . '/run-lane.sh';
        $config = [
            'lane_name' => 'php8.4-dev',
            'php_version' => '8.4',
            'php_binary' => '/usr/bin/php8.4',
            'stability' => 'dev',
            'component_dir' => $this->tempDir . '/component',
            'tools_dir' => $this->tempDir . '/tools',
            'build_dir' => $this->tempDir . '/component/build',
        ];

        $this->generator->generate($scriptPath, $config);

        $script = file_get_contents($scriptPath);

        // PHPStan already outputs JSON, but we need to wrap it with success/exit_code
        $this->assertStringContainsString(
            'phpstan-results.json',
            $script,
            'Script should handle PHPStan JSON'
        );
    }

    public function testScriptWritesPhpCsFixerJsonSummary(): void
    {
        $scriptPath = $this->tempDir . '/run-lane.sh';
        $config = [
            'lane_name' => 'php8.4-dev',
            'php_version' => '8.4',
            'php_binary' => '/usr/bin/php8.4',
            'stability' => 'dev',
            'component_dir' => $this->tempDir . '/component',
            'tools_dir' => $this->tempDir . '/tools',
            'build_dir' => $this->tempDir . '/component/build',
        ];

        $this->generator->generate($scriptPath, $config);

        $script = file_get_contents($scriptPath);

        // PHP-CS-Fixer outputs JSON already, similar to PHPStan
        $this->assertStringContainsString(
            'php-cs-fixer-results.json',
            $script,
            'Script should handle PHP-CS-Fixer JSON'
        );
    }

    public function testJsonFormatMatchesResultCollectorExpectations(): void
    {
        $scriptPath = $this->tempDir . '/run-lane.sh';
        $config = [
            'lane_name' => 'php8.4-dev',
            'php_version' => '8.4',
            'php_binary' => '/usr/bin/php8.4',
            'stability' => 'dev',
            'component_dir' => $this->tempDir . '/component',
            'tools_dir' => $this->tempDir . '/tools',
            'build_dir' => $this->tempDir . '/component/build',
        ];

        $this->generator->generate($scriptPath, $config);

        $script = file_get_contents($scriptPath);

        // ResultCollector expects: success, exit_code, statistics, version
        $expectedFields = ['success', 'exit_code', 'statistics'];

        foreach ($expectedFields as $field) {
            $this->assertStringContainsString(
                "\"$field\"",
                $script,
                "JSON should include required field: $field"
            );
        }
    }
}
