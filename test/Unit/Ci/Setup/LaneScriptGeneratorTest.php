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

namespace Horde\Components\Test\Unit\Ci\Setup;

use Horde\Components\Ci\Setup\LaneScriptGenerator;
use Horde\Components\Output;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for LaneScriptGenerator.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
#[CoversClass(LaneScriptGenerator::class)]
class LaneScriptGeneratorTest extends TestCase
{
    private LaneScriptGenerator $generator;
    private Output $output;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->output = $this->createMock(Output::class);
        $this->generator = new LaneScriptGenerator($this->output);

        // Create temp directory for test scripts
        $this->tempDir = sys_get_temp_dir() . '/horde-ci-test-' . uniqid();
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        // Cleanup temp directory
        if (is_dir($this->tempDir)) {
            exec('rm -rf ' . escapeshellarg($this->tempDir));
        }
    }

    public function testGenerateScriptWithAllTasks(): void
    {
        $scriptPath = $this->tempDir . '/run-lane.sh';

        $config = [
            'lane_name' => 'php8.4-dev',
            'php_version' => '8.4',
            'php_binary' => '/usr/bin/php8.4',
            'tool_php_binary' => '/usr/bin/php8.4',
            'stability' => 'dev',
            'component_dir' => '/tmp/horde-ci/lanes/php8.4-dev/Http',
            'tools_dir' => '/tmp/horde-ci/tools',
            'build_dir' => '/tmp/horde-ci/lanes/php8.4-dev/Http/build',
        ];

        $result = $this->generator->generate($scriptPath, $config);

        $this->assertTrue($result, 'Generator should return true on success');
        $this->assertFileExists($scriptPath, 'Script file should be created');

        $content = file_get_contents($scriptPath);

        // Check all tasks are included
        $this->assertStringContainsString('phpunit', $content, 'Should include PHPUnit task');
        $this->assertStringContainsString('phpstan', $content, 'Should include PHPStan task');
        $this->assertStringContainsString('php-cs-fixer', $content, 'Should include PHP-CS-Fixer task');
    }

    public function testGenerateScriptWithMinimalTasks(): void
    {
        $scriptPath = $this->tempDir . '/run-lane.sh';

        $config = [
            'lane_name' => 'php8.2-stable',
            'php_version' => '8.2',
            'php_binary' => '/usr/bin/php8.2',
            'tool_php_binary' => '/usr/bin/php8.4',
            'stability' => 'stable',
            'component_dir' => '/tmp/horde-ci/lanes/php8.2-stable/Http',
            'tools_dir' => '/tmp/horde-ci/tools',
            'build_dir' => '/tmp/horde-ci/lanes/php8.2-stable/Http/build',
        ];

        $result = $this->generator->generate($scriptPath, $config);

        $this->assertTrue($result, 'Generator should return true on success');
        $this->assertFileExists($scriptPath, 'Script file should be created');

        $content = file_get_contents($scriptPath);

        // Check minimal tasks are included
        $this->assertStringContainsString('phpunit', $content, 'Should include PHPUnit task');
        $this->assertStringContainsString('phpstan', $content, 'Should include PHPStan task');
        // PHP-CS-Fixer should NOT be in stable lanes for non-8.4
        $this->assertStringNotContainsString('php-cs-fixer', $content, 'Should not include PHP-CS-Fixer on stable lanes');
    }

    public function testScriptIsExecutable(): void
    {
        $scriptPath = $this->tempDir . '/run-lane.sh';

        $config = [
            'lane_name' => 'php8.4-dev',
            'php_version' => '8.4',
            'php_binary' => '/usr/bin/php8.4',
            'tool_php_binary' => '/usr/bin/php8.4',
            'stability' => 'dev',
            'component_dir' => '/tmp/horde-ci/lanes/php8.4-dev/Http',
            'tools_dir' => '/tmp/horde-ci/tools',
            'build_dir' => '/tmp/horde-ci/lanes/php8.4-dev/Http/build',
        ];

        $this->generator->generate($scriptPath, $config);

        $this->assertTrue(is_executable($scriptPath), 'Script should be executable');

        // Check actual permissions
        $perms = fileperms($scriptPath);
        $this->assertTrue(($perms & 0o100) !== 0, 'Owner should have execute permission');
    }

    public function testScriptContainsCorrectShebang(): void
    {
        $scriptPath = $this->tempDir . '/run-lane.sh';

        $config = [
            'lane_name' => 'php8.4-dev',
            'php_version' => '8.4',
            'php_binary' => '/usr/bin/php8.4',
            'tool_php_binary' => '/usr/bin/php8.4',
            'stability' => 'dev',
            'component_dir' => '/tmp/horde-ci/lanes/php8.4-dev/Http',
            'tools_dir' => '/tmp/horde-ci/tools',
            'build_dir' => '/tmp/horde-ci/lanes/php8.4-dev/Http/build',
        ];

        $this->generator->generate($scriptPath, $config);

        $content = file_get_contents($scriptPath);
        $lines = explode("\n", $content);

        $this->assertSame('#!/bin/bash', $lines[0], 'First line should be bash shebang');
    }

    public function testScriptUsesCorrectPhpBinary(): void
    {
        $scriptPath = $this->tempDir . '/run-lane.sh';

        $config = [
            'lane_name' => 'php8.3-dev',
            'php_version' => '8.3',
            'php_binary' => '/usr/bin/php8.3',
            'tool_php_binary' => '/usr/bin/php8.4',
            'stability' => 'dev',
            'component_dir' => '/tmp/horde-ci/lanes/php8.3-dev/Http',
            'tools_dir' => '/tmp/horde-ci/tools',
            'build_dir' => '/tmp/horde-ci/lanes/php8.3-dev/Http/build',
        ];

        $this->generator->generate($scriptPath, $config);

        $content = file_get_contents($scriptPath);

        // Lane PHP - what PHPUnit/PHPStan/PHP-CS-Fixer run under.
        $this->assertStringContainsString('LANE_PHP="/usr/bin/php8.3"', $content, 'Should set correct LANE_PHP variable');
        // Tool PHP - what horde-components.phar itself runs under.
        $this->assertStringContainsString('TOOL_PHP="/usr/bin/php8.4"', $content, 'Should set correct TOOL_PHP variable');
        // Compat alias (one release cycle).
        $this->assertStringContainsString('PHP_BINARY="$LANE_PHP"', $content, 'Should keep PHP_BINARY alias for back-compat');
        // Task lines invoke horde-components via TOOL_PHP and pass --php=LANE_PHP.
        $this->assertStringContainsString('"$TOOL_PHP" "$COMPONENTS_PATH"', $content, 'Should invoke horde-components via TOOL_PHP');
        $this->assertStringContainsString('--php="$LANE_PHP"', $content, 'Should pass --php=LANE_PHP to qc invocations');
    }

    public function testScriptUsesCorrectToolPaths(): void
    {
        $scriptPath = $this->tempDir . '/run-lane.sh';

        $config = [
            'lane_name' => 'php8.4-dev',
            'php_version' => '8.4',
            'php_binary' => '/usr/bin/php8.4',
            'tool_php_binary' => '/usr/bin/php8.4',
            'stability' => 'dev',
            'component_dir' => '/tmp/horde-ci/lanes/php8.4-dev/Http',
            'tools_dir' => '/custom/tools/path',
            'build_dir' => '/tmp/horde-ci/lanes/php8.4-dev/Http/build',
            'components_path' => '/usr/bin/horde-components',
        ];

        $this->generator->generate($scriptPath, $config);

        $content = file_get_contents($scriptPath);

        $this->assertStringContainsString('TOOLS_DIR="/custom/tools/path"', $content, 'Should set correct tools directory');
        $this->assertStringContainsString('--tools-dir="$TOOLS_DIR"', $content, 'Should pass tools directory to qc commands');
    }

    public function testScriptCreatesBuilDirectory(): void
    {
        $scriptPath = $this->tempDir . '/run-lane.sh';

        $config = [
            'lane_name' => 'php8.4-dev',
            'php_version' => '8.4',
            'php_binary' => '/usr/bin/php8.4',
            'tool_php_binary' => '/usr/bin/php8.4',
            'stability' => 'dev',
            'component_dir' => '/tmp/horde-ci/lanes/php8.4-dev/Http',
            'tools_dir' => '/tmp/horde-ci/tools',
            'build_dir' => '/tmp/horde-ci/lanes/php8.4-dev/Http/build',
        ];

        $this->generator->generate($scriptPath, $config);

        $content = file_get_contents($scriptPath);

        $this->assertStringContainsString('mkdir -p "$BUILD_DIR"', $content, 'Should create build directory');
    }

    public function testScriptCapturesExitCodes(): void
    {
        $scriptPath = $this->tempDir . '/run-lane.sh';

        $config = [
            'lane_name' => 'php8.4-dev',
            'php_version' => '8.4',
            'php_binary' => '/usr/bin/php8.4',
            'tool_php_binary' => '/usr/bin/php8.4',
            'stability' => 'dev',
            'component_dir' => '/tmp/horde-ci/lanes/php8.4-dev/Http',
            'tools_dir' => '/tmp/horde-ci/tools',
            'build_dir' => '/tmp/horde-ci/lanes/php8.4-dev/Http/build',
        ];

        $this->generator->generate($scriptPath, $config);

        $content = file_get_contents($scriptPath);

        // Check that exit codes are captured (using || to continue on error)
        $this->assertStringContainsString('|| PHPUNIT_EXIT=$?', $content, 'Should capture PHPUnit exit code');
        $this->assertStringContainsString('|| PHPSTAN_EXIT=$?', $content, 'Should capture PHPStan exit code');

        // Check that exit codes are checked at the end
        $this->assertStringContainsString('PHPUNIT_EXIT', $content, 'Should reference PHPUnit exit code');
        $this->assertStringContainsString('PHPSTAN_EXIT', $content, 'Should reference PHPStan exit code');
    }

    public function testPhpCsFixerOnlyOnPhp84Dev(): void
    {
        // Test php8.4-dev (should have PHP-CS-Fixer)
        $scriptPath1 = $this->tempDir . '/run-lane-84-dev.sh';
        $config1 = [
            'lane_name' => 'php8.4-dev',
            'php_version' => '8.4',
            'php_binary' => '/usr/bin/php8.4',
            'tool_php_binary' => '/usr/bin/php8.4',
            'stability' => 'dev',
            'component_dir' => '/tmp/horde-ci/lanes/php8.4-dev/Http',
            'tools_dir' => '/tmp/horde-ci/tools',
            'build_dir' => '/tmp/horde-ci/lanes/php8.4-dev/Http/build',
        ];

        $this->generator->generate($scriptPath1, $config1);
        $content1 = file_get_contents($scriptPath1);
        $this->assertStringContainsString('php-cs-fixer', $content1, 'php8.4-dev should include PHP-CS-Fixer');

        // Test php8.4-stable (should NOT have PHP-CS-Fixer)
        $scriptPath2 = $this->tempDir . '/run-lane-84-stable.sh';
        $config2 = [
            'lane_name' => 'php8.4-stable',
            'php_version' => '8.4',
            'php_binary' => '/usr/bin/php8.4',
            'tool_php_binary' => '/usr/bin/php8.4',
            'stability' => 'stable',
            'component_dir' => '/tmp/horde-ci/lanes/php8.4-stable/Http',
            'tools_dir' => '/tmp/horde-ci/tools',
            'build_dir' => '/tmp/horde-ci/lanes/php8.4-stable/Http/build',
        ];

        $this->generator->generate($scriptPath2, $config2);
        $content2 = file_get_contents($scriptPath2);
        $this->assertStringNotContainsString('php-cs-fixer', $content2, 'php8.4-stable should not include PHP-CS-Fixer');

        // Test php8.3-dev (should NOT have PHP-CS-Fixer)
        $scriptPath3 = $this->tempDir . '/run-lane-83-dev.sh';
        $config3 = [
            'lane_name' => 'php8.3-dev',
            'php_version' => '8.3',
            'php_binary' => '/usr/bin/php8.3',
            'tool_php_binary' => '/usr/bin/php8.4',
            'stability' => 'dev',
            'component_dir' => '/tmp/horde-ci/lanes/php8.3-dev/Http',
            'tools_dir' => '/tmp/horde-ci/tools',
            'build_dir' => '/tmp/horde-ci/lanes/php8.3-dev/Http/build',
        ];

        $this->generator->generate($scriptPath3, $config3);
        $content3 = file_get_contents($scriptPath3);
        $this->assertStringNotContainsString('php-cs-fixer', $content3, 'php8.3-dev should not include PHP-CS-Fixer');
    }

    public function testGenerateScriptWithBashSafetyFeatures(): void
    {
        $scriptPath = $this->tempDir . '/run-lane.sh';

        $config = [
            'lane_name' => 'php8.4-dev',
            'php_version' => '8.4',
            'php_binary' => '/usr/bin/php8.4',
            'tool_php_binary' => '/usr/bin/php8.4',
            'stability' => 'dev',
            'component_dir' => '/tmp/horde-ci/lanes/php8.4-dev/Http',
            'tools_dir' => '/tmp/horde-ci/tools',
            'build_dir' => '/tmp/horde-ci/lanes/php8.4-dev/Http/build',
        ];

        $this->generator->generate($scriptPath, $config);

        $content = file_get_contents($scriptPath);

        // Check for bash safety features
        $this->assertStringContainsString('set -e', $content, 'Should have set -e for exit on error');
        $this->assertStringContainsString('set -o pipefail', $content, 'Should have set -o pipefail for pipe error handling');
    }

    public function testGenerateScriptIncludesMetadata(): void
    {
        $scriptPath = $this->tempDir . '/run-lane.sh';

        $config = [
            'lane_name' => 'php8.4-dev',
            'php_version' => '8.4',
            'php_binary' => '/usr/bin/php8.4',
            'tool_php_binary' => '/usr/bin/php8.4',
            'stability' => 'dev',
            'component_dir' => '/tmp/horde-ci/lanes/php8.4-dev/Http',
            'tools_dir' => '/tmp/horde-ci/tools',
            'build_dir' => '/tmp/horde-ci/lanes/php8.4-dev/Http/build',
        ];

        $this->generator->generate($scriptPath, $config);

        $content = file_get_contents($scriptPath);

        // Check metadata comments
        $this->assertStringContainsString('# Lane: php8.4-dev', $content, 'Should include lane name comment');
        $this->assertStringContainsString('# PHP: 8.4', $content, 'Should include PHP version comment');
        $this->assertStringContainsString('# Stability: dev', $content, 'Should include stability comment');
        $this->assertStringContainsString('# Generated by: horde-components', $content, 'Should include generator comment');
    }

    public function testGenerateScriptChangesDirectoryToComponentDir(): void
    {
        $scriptPath = $this->tempDir . '/run-lane.sh';

        $config = [
            'lane_name' => 'php8.4-dev',
            'php_version' => '8.4',
            'php_binary' => '/usr/bin/php8.4',
            'tool_php_binary' => '/usr/bin/php8.4',
            'stability' => 'dev',
            'component_dir' => '/tmp/horde-ci/lanes/php8.4-dev/Http',
            'tools_dir' => '/tmp/horde-ci/tools',
            'build_dir' => '/tmp/horde-ci/lanes/php8.4-dev/Http/build',
        ];

        $this->generator->generate($scriptPath, $config);

        $content = file_get_contents($scriptPath);

        $this->assertStringContainsString('cd "$COMPONENT_DIR"', $content, 'Should change to component directory');
    }

    public function testGenerateScriptFailsWithInvalidPath(): void
    {
        // Try to create script in non-existent directory (without creating parent)
        $scriptPath = '/non/existent/path/run-lane.sh';

        $config = [
            'lane_name' => 'php8.4-dev',
            'php_version' => '8.4',
            'php_binary' => '/usr/bin/php8.4',
            'tool_php_binary' => '/usr/bin/php8.4',
            'stability' => 'dev',
            'component_dir' => '/tmp/horde-ci/lanes/php8.4-dev/Http',
            'tools_dir' => '/tmp/horde-ci/tools',
            'build_dir' => '/tmp/horde-ci/lanes/php8.4-dev/Http/build',
        ];

        $result = $this->generator->generate($scriptPath, $config);

        $this->assertFalse($result, 'Generator should return false on failure');
        $this->assertFileDoesNotExist($scriptPath, 'Script file should not be created on invalid path');
    }

    public function testGenerateScriptWithEchoStatements(): void
    {
        $scriptPath = $this->tempDir . '/run-lane.sh';

        $config = [
            'lane_name' => 'php8.4-dev',
            'php_version' => '8.4',
            'php_binary' => '/usr/bin/php8.4',
            'tool_php_binary' => '/usr/bin/php8.4',
            'stability' => 'dev',
            'component_dir' => '/tmp/horde-ci/lanes/php8.4-dev/Http',
            'tools_dir' => '/tmp/horde-ci/tools',
            'build_dir' => '/tmp/horde-ci/lanes/php8.4-dev/Http/build',
        ];

        $this->generator->generate($scriptPath, $config);

        $content = file_get_contents($scriptPath);

        // Check for informative echo statements
        $this->assertStringContainsString('echo "=== Running PHPUnit ==="', $content, 'Should have PHPUnit section header');
        $this->assertStringContainsString('echo "=== Running PHPStan ==="', $content, 'Should have PHPStan section header');
        $this->assertMatchesRegularExpression('/echo.*Summary/', $content, 'Should have summary section');
    }

    public function testGenerateScriptHandlesMissingConfigKeys(): void
    {
        $scriptPath = $this->tempDir . '/run-lane.sh';

        // Config missing some keys
        $config = [
            'lane_name' => 'php8.4-dev',
            'php_version' => '8.4',
            // Missing: php_binary, tool_php_binary, stability, component_dir, tools_dir, build_dir
        ];

        $result = $this->generator->generate($scriptPath, $config);

        // Should fail gracefully
        $this->assertFalse($result, 'Generator should return false when required config keys are missing');
    }

    public function testGenerateScriptExitCodeLogic(): void
    {
        $scriptPath = $this->tempDir . '/run-lane.sh';

        $config = [
            'lane_name' => 'php8.4-dev',
            'php_version' => '8.4',
            'php_binary' => '/usr/bin/php8.4',
            'tool_php_binary' => '/usr/bin/php8.4',
            'stability' => 'dev',
            'component_dir' => '/tmp/horde-ci/lanes/php8.4-dev/Http',
            'tools_dir' => '/tmp/horde-ci/tools',
            'build_dir' => '/tmp/horde-ci/lanes/php8.4-dev/Http/build',
        ];

        $this->generator->generate($scriptPath, $config);

        $content = file_get_contents($scriptPath);

        // Check exit code handling
        $this->assertStringContainsString('[ "${PHPUNIT_EXIT:-0}" -ne 0 ]', $content, 'Should check PHPUnit exit code');
        $this->assertStringContainsString('[ "${PHPSTAN_EXIT:-0}" -ne 0 ]', $content, 'Should check PHPStan exit code');
        $this->assertStringContainsString('exit "$PHPUNIT_EXIT"', $content, 'Should exit with PHPUnit code on failure');
        $this->assertStringContainsString('exit 0', $content, 'Should exit 0 on success');
    }
}
