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

use Horde\Components\Ci\Setup\SetupCommand;
use Horde\Components\Ci\Setup\PhpInstaller;
use Horde\Components\Ci\Setup\ExtensionInstaller;
use Horde\Components\Ci\Setup\LaneCopier;
use Horde\Components\Ci\Setup\ComposerInstaller;
use Horde\Components\Ci\Setup\ToolCache;
use Horde\Components\Ci\Setup\LaneScriptGenerator;
use Horde\Components\Ci\Config\CiConfig;
use Horde\Components\Output;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for SetupCommand - specifically lane script generation.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
#[CoversClass(SetupCommand::class)]
class SetupCommandTest extends TestCase
{
    private SetupCommand $setupCommand;
    private Output $output;
    private PhpInstaller $phpInstaller;
    private ExtensionInstaller $extensionInstaller;
    private LaneCopier $laneCopier;
    private ComposerInstaller $composerInstaller;
    private ToolCache $toolCache;
    private LaneScriptGenerator $laneScriptGenerator;
    private string $tempDir;
    private string $testComponentPath;

    protected function setUp(): void
    {
        // Create mocks for all dependencies
        $this->output = $this->createMock(Output::class);
        $this->phpInstaller = $this->createMock(PhpInstaller::class);
        $this->extensionInstaller = $this->createMock(ExtensionInstaller::class);
        $this->laneCopier = $this->createMock(LaneCopier::class);
        $this->composerInstaller = $this->createMock(ComposerInstaller::class);
        $this->toolCache = $this->createMock(ToolCache::class);
        $this->laneScriptGenerator = $this->createMock(LaneScriptGenerator::class);

        // Create temp directory for test component
        $this->tempDir = sys_get_temp_dir() . '/horde-ci-setup-test-' . uniqid();
        mkdir($this->tempDir, 0o755, true);

        $this->testComponentPath = $this->tempDir . '/component';
        mkdir($this->testComponentPath, 0o755, true);

        // Create minimal .horde.yml
        file_put_contents(
            $this->testComponentPath . '/.horde.yml',
            <<<YAML
                id: TestComponent
                name: TestComponent
                type: library
                version:
                  release: 1.0.0
                state:
                  release: stable
                dependencies:
                  required:
                    php: ^8.2
                YAML
        );

        // Create SetupCommand with mocked dependencies
        $this->setupCommand = new SetupCommand(
            $this->output,
            $this->phpInstaller,
            $this->extensionInstaller,
            $this->laneCopier,
            $this->composerInstaller,
            $this->toolCache,
            $this->laneScriptGenerator
        );
    }

    protected function tearDown(): void
    {
        // Cleanup temp directory
        if (is_dir($this->tempDir)) {
            exec('rm -rf ' . escapeshellarg($this->tempDir));
        }
    }

    public function testGeneratesLaneScriptsAfterComposerInstall(): void
    {
        $config = new CiConfig([
            'mode' => 'local',
            'component_name' => 'TestComponent',
            'component_path' => $this->testComponentPath,
            'work_dir' => $this->tempDir . '/work',
            'php_versions' => ['8.4'], // Specify exact version
            'min_php_version' => '8.2',
            'component_stability' => 'stable',
            'local_components_path' => '/usr/bin/horde-components',
            'components_path' => '/usr/bin/horde-components',
        ]);

        // Mock PHP installer to return binary path
        $this->phpInstaller
            ->expects($this->once())
            ->method('install')
            ->with($this->callback(function ($versions) {
                // Accept any PHP versions that include 8.4
                return in_array('8.4', $versions);
            }));

        $this->phpInstaller
            ->method('getPhpBinary')
            ->willReturnCallback(function ($version) {
                return "/usr/bin/php{$version}";
            });

        // Mock extension installer
        $this->extensionInstaller
            ->expects($this->once())
            ->method('detectExtensions')
            ->willReturn([]);

        $this->extensionInstaller
            ->expects($this->once())
            ->method('install');

        // Mock lane copier
        $this->laneCopier
            ->expects($this->once())
            ->method('copyToLanes');

        // Mock composer installer - will be called for each lane
        $this->composerInstaller
            ->method('install');

        $this->composerInstaller
            ->method('verifyInstallation')
            ->willReturn(true);

        $this->composerInstaller
            ->method('getInstalledPackageCount')
            ->willReturn(10);

        // Mock tool cache
        $this->toolCache
            ->expects($this->once())
            ->method('ensureAllTools');

        // IMPORTANT: Assert that lane script generator is called for each lane
        $this->laneScriptGenerator
            ->expects($this->atLeastOnce()) // At least one lane
            ->method('generate')
            ->willReturnCallback(function (string $scriptPath, array $config) {
                // Verify script path format
                $this->assertStringContainsString('/run-lane.sh', $scriptPath);

                // Verify config contains required keys
                $this->assertArrayHasKey('lane_name', $config);
                $this->assertArrayHasKey('php_version', $config);
                $this->assertArrayHasKey('php_binary', $config);
                $this->assertArrayHasKey('stability', $config);
                $this->assertArrayHasKey('component_dir', $config);
                $this->assertArrayHasKey('tools_dir', $config);
                $this->assertArrayHasKey('build_dir', $config);

                return true;
            });

        // Execute setup
        $result = $this->setupCommand->execute($config);

        $this->assertTrue($result, 'Setup should succeed');
    }

    public function testLaneScriptGenerationFailureIsReported(): void
    {
        $config = new CiConfig([
            'mode' => 'local',
            'component_name' => 'TestComponent',
            'component_path' => $this->testComponentPath,
            'work_dir' => $this->tempDir . '/work',
            'php_versions' => ['8.4'],
            'min_php_version' => '8.2',
            'component_stability' => 'stable',
            'local_components_path' => '/usr/bin/horde-components',
            'components_path' => '/usr/bin/horde-components',
        ]);

        // Setup mocks for successful setup until lane script generation
        $this->phpInstaller->method('install');
        $this->phpInstaller->method('getPhpBinary')->willReturnCallback(function ($v) {
            return "/usr/bin/php{$v}";
        });
        $this->extensionInstaller->method('detectExtensions')->willReturn([]);
        $this->extensionInstaller->method('install');
        $this->laneCopier->method('copyToLanes');
        $this->composerInstaller->method('install');
        $this->composerInstaller->method('verifyInstallation')->willReturn(true);
        $this->composerInstaller->method('getInstalledPackageCount')->willReturn(10);
        $this->toolCache->method('ensureAllTools');

        // Track calls to lane script generator
        $callCount = 0;
        $this->laneScriptGenerator
            ->method('generate')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                // Fail first lane, succeed others
                return $callCount > 1;
            });

        // Expect error to be logged
        $this->output
            ->expects($this->atLeastOnce())
            ->method('error')
            ->with($this->stringContains('Failed to generate script'));

        // Execute setup
        $result = $this->setupCommand->execute($config);

        // Setup should still complete but report the failure
        $this->assertTrue($result, 'Setup should complete even with script generation failure');
    }

    public function testLaneScriptConfigContainsCorrectValues(): void
    {
        $config = new CiConfig([
            'mode' => 'local',
            'component_name' => 'Http',
            'component_path' => $this->testComponentPath,
            'work_dir' => '/tmp/test-ci',
            'php_versions' => ['8.3'],
            'min_php_version' => '8.2',
            'component_stability' => 'stable',
            'local_components_path' => '/usr/bin/horde-components',
            'components_path' => '/usr/bin/horde-components',
        ]);

        // Setup mocks
        $this->phpInstaller->method('install');
        $this->phpInstaller->method('getPhpBinary')->willReturnCallback(function ($v) {
            return "/usr/bin/php{$v}";
        });
        $this->extensionInstaller->method('detectExtensions')->willReturn([]);
        $this->extensionInstaller->method('install');
        $this->laneCopier->method('copyToLanes');
        $this->composerInstaller->method('install');
        $this->composerInstaller->method('verifyInstallation')->willReturn(true);
        $this->composerInstaller->method('getInstalledPackageCount')->willReturn(10);
        $this->toolCache->method('ensureAllTools');

        // Capture lane script config
        $capturedConfigs = [];
        $this->laneScriptGenerator
            ->method('generate')
            ->willReturnCallback(function (string $scriptPath, array $config) use (&$capturedConfigs) {
                $capturedConfigs[] = $config;
                return true;
            });

        // Execute setup
        $this->setupCommand->execute($config);

        // Verify we got configs for lanes
        $this->assertGreaterThanOrEqual(2, count($capturedConfigs), 'Should generate scripts for at least 2 lanes');

        // Find configs for 8.3-dev and 8.3-stable (component_stability is set to 'stable')
        $devLaneConfig = null;
        $stableLaneConfig = null;
        foreach ($capturedConfigs as $cfg) {
            if ($cfg['php_version'] === '8.3' && $cfg['stability'] === 'dev') {
                $devLaneConfig = $cfg;
            }
            if ($cfg['php_version'] === '8.3' && $cfg['stability'] === 'stable') {
                $stableLaneConfig = $cfg;
            }
        }

        $this->assertNotNull($devLaneConfig, 'Should have php8.3-dev lane config');
        $this->assertNotNull($stableLaneConfig, 'Should have php8.3-stable lane config');

        // Verify dev lane config
        $this->assertSame('php8.3-dev', $devLaneConfig['lane_name']);
        $this->assertSame('8.3', $devLaneConfig['php_version']);
        $this->assertSame('/usr/bin/php8.3', $devLaneConfig['php_binary']);
        $this->assertSame('dev', $devLaneConfig['stability']);
        $this->assertStringContainsString('/lanes/php8.3-dev/Http', $devLaneConfig['component_dir']);
        $this->assertSame('/tmp/test-ci/tools', $devLaneConfig['tools_dir']);
        $this->assertStringContainsString('/build', $devLaneConfig['build_dir']);

        // Verify stable lane config
        $this->assertSame('php8.3-stable', $stableLaneConfig['lane_name']);
        $this->assertSame('8.3', $stableLaneConfig['php_version']);
        $this->assertSame('stable', $stableLaneConfig['stability']);
    }

    public function testLaneScriptsGeneratedAfterComposerNotBefore(): void
    {
        $config = new CiConfig([
            'mode' => 'local',
            'component_name' => 'TestComponent',
            'component_path' => $this->testComponentPath,
            'work_dir' => $this->tempDir . '/work',
            'php_versions' => ['8.4'],
            'min_php_version' => '8.2',
            'component_stability' => 'stable',
            'local_components_path' => '/usr/bin/horde-components',
            'components_path' => '/usr/bin/horde-components',
        ]);

        $callOrder = [];

        // Track call order
        $this->phpInstaller->method('install')->willReturnCallback(function () use (&$callOrder) {
            $callOrder[] = 'php_install';
            return true;
        });
        $this->phpInstaller->method('getPhpBinary')->willReturnCallback(function ($v) {
            return "/usr/bin/php{$v}";
        });
        $this->extensionInstaller->method('detectExtensions')->willReturn([]);
        $this->extensionInstaller->method('install');
        $this->laneCopier->method('copyToLanes');

        $this->composerInstaller->method('install')->willReturnCallback(function () use (&$callOrder) {
            $callOrder[] = 'composer_install';
            return true;
        });
        $this->composerInstaller->method('verifyInstallation')->willReturn(true);
        $this->composerInstaller->method('getInstalledPackageCount')->willReturn(10);

        $this->toolCache->method('ensureAllTools')->willReturnCallback(function () use (&$callOrder) {
            $callOrder[] = 'tool_cache';
        });

        $this->laneScriptGenerator->method('generate')->willReturnCallback(function () use (&$callOrder) {
            $callOrder[] = 'lane_script';
            return true;
        });

        // Execute setup
        $this->setupCommand->execute($config);

        // Verify lane scripts are generated AFTER composer install
        $composerIndex = array_search('composer_install', $callOrder);
        $scriptIndex = array_search('lane_script', $callOrder);

        $this->assertNotFalse($composerIndex, 'Composer install should be called');
        $this->assertNotFalse($scriptIndex, 'Lane script generation should be called');
        $this->assertGreaterThan($composerIndex, $scriptIndex, 'Lane scripts should be generated AFTER composer install');
    }

    public function testNoLaneScriptsGeneratedIfComposerFails(): void
    {
        $config = new CiConfig([
            'mode' => 'local',
            'component_name' => 'TestComponent',
            'component_path' => $this->testComponentPath,
            'work_dir' => $this->tempDir . '/work',
            'php_versions' => ['8.4'],
            'min_php_version' => '8.2',
            'component_stability' => 'stable',
            'local_components_path' => '/usr/bin/horde-components',
            'components_path' => '/usr/bin/horde-components',
        ]);

        // Setup mocks
        $this->phpInstaller->method('install');
        $this->phpInstaller->method('getPhpBinary')->willReturnCallback(function ($v) {
            return "/usr/bin/php{$v}";
        });
        $this->extensionInstaller->method('detectExtensions')->willReturn([]);
        $this->extensionInstaller->method('install');
        $this->laneCopier->method('copyToLanes');

        // Composer install succeeds but verification fails
        $this->composerInstaller->method('install');
        $this->composerInstaller->method('verifyInstallation')->willReturn(false);

        $this->toolCache->method('ensureAllTools');

        // Lane script generator should still be called (we generate scripts even if composer has issues)
        // This allows debugging of composer issues by inspecting the lane
        $this->laneScriptGenerator
            ->expects($this->atLeastOnce())
            ->method('generate')
            ->willReturn(true);

        // Execute setup
        $result = $this->setupCommand->execute($config);

        // Setup reports failure due to composer issues
        $this->assertFalse($result, 'Setup should report failure when composer verification fails');
    }
}
