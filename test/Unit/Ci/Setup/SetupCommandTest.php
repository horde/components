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
use Horde\Components\Helper\PlatformResolver;
use Horde\Components\Output;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use ReflectionMethod;

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

        // Default filterAvailable to a passthrough: tests that don't
        // specifically care about lane filtering get the happy path
        // (every requested PHP version is installable). Tests that
        // exercise the filter override this with their own expectation.
        $this->phpInstaller
            ->method('filterAvailable')
            ->willReturnCallback(static fn (array $versions): array => array_values($versions));

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

        // Mock extension installer (per-version resolution)
        $this->extensionInstaller
            ->expects($this->once())
            ->method('detectExtensionsPerVersion')
            ->willReturn(['8.4' => []]);

        $this->extensionInstaller
            ->expects($this->once())
            ->method('installPerVersion');

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
        $this->extensionInstaller->method('detectExtensionsPerVersion')->willReturn([]);
        $this->extensionInstaller->method('installPerVersion');
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
        $this->extensionInstaller->method('detectExtensionsPerVersion')->willReturn([]);
        $this->extensionInstaller->method('installPerVersion');
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
        $this->extensionInstaller->method('detectExtensionsPerVersion')->willReturn([]);
        $this->extensionInstaller->method('installPerVersion');
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
        $this->extensionInstaller->method('detectExtensionsPerVersion')->willReturn([]);
        $this->extensionInstaller->method('installPerVersion');
        $this->laneCopier->method('copyToLanes');

        // Composer install succeeds but verification fails
        $this->composerInstaller->method('install');
        $this->composerInstaller->method('verifyInstallation')->willReturn(false);

        $this->toolCache->method('ensureAllTools');

        // A lane whose composer install or verification failed is
        // recorded as setup-failed and gets no run-lane.sh. RunCommand
        // surfaces it as ❌ via the build/setup-failed.json sidecar
        // instead of running a broken script.
        $this->laneScriptGenerator
            ->expects($this->never())
            ->method('generate');

        // Execute setup
        $result = $this->setupCommand->execute($config);

        // Both lanes fail setup → no usable lanes → return false so
        // Module/Ci raises the fatal-error exception (every lane dead).
        $this->assertFalse($result, 'Setup should report failure when every lane fails composer verification');
    }

    /**
     * Partial setup must not abort. Half the lanes fail composer
     * install, the other half succeed; setup returns true (some lanes
     * are still runnable) and the failing lanes carry a build/setup-failed.json
     * marker so RunCommand reports them as ❌ in the PR comment instead
     * of dying before any tests run.
     */
    public function testPartialSetupSucceedsAndMarksFailedLanes(): void
    {
        $workDir = $this->tempDir . '/work';
        $config = new CiConfig([
            'mode' => 'local',
            'component_name' => 'TestComponent',
            'component_path' => $this->testComponentPath,
            'work_dir' => $workDir,
            'php_versions' => ['8.4'],
            'min_php_version' => '8.2',
            'component_stability' => 'stable',
            'local_components_path' => '/usr/bin/horde-components',
            'components_path' => '/usr/bin/horde-components',
        ]);

        $this->phpInstaller->method('install');
        $this->phpInstaller->method('getPhpBinary')->willReturnCallback(function ($v) {
            return "/usr/bin/php{$v}";
        });
        $this->extensionInstaller->method('detectExtensionsPerVersion')->willReturn([]);
        $this->extensionInstaller->method('installPerVersion');

        // LaneCopier is mocked so the lane directories don't actually
        // get created by the test infrastructure. Create them by hand
        // so writeSetupFailureMarker has somewhere to land its sidecar.
        $devLane = $workDir . '/lanes/php8.4-dev/TestComponent';
        $stableLane = $workDir . '/lanes/php8.4-stable/TestComponent';
        $this->laneCopier
            ->method('copyToLanes')
            ->willReturnCallback(function () use ($devLane, $stableLane): bool {
                mkdir($devLane, 0o755, true);
                mkdir($stableLane, 0o755, true);
                return true;
            });

        // Dev lane installs cleanly; stable lane verification fails.
        // The matching call to writeSetupFailureMarker must produce a
        // sidecar JSON at $stableLane/build/setup-failed.json.
        $this->composerInstaller->method('install');
        $this->composerInstaller
            ->method('verifyInstallation')
            ->willReturnCallback(function (string $dir) use ($devLane): bool {
                return $dir === $devLane;
            });
        $this->composerInstaller
            ->method('getInstalledPackageCount')
            ->willReturn(42);

        $this->toolCache->method('ensureAllTools');

        // Only the dev lane should get a run-lane.sh; the failed lane is
        // skipped at script-generation time.
        $generatedFor = [];
        $this->laneScriptGenerator
            ->method('generate')
            ->willReturnCallback(function (string $scriptPath, array $cfg) use (&$generatedFor): bool {
                $generatedFor[] = $cfg['lane_name'];
                return true;
            });

        $result = $this->setupCommand->execute($config);

        $this->assertTrue(
            $result,
            'Setup should return true when at least one lane is usable'
        );
        $this->assertSame(
            ['php8.4-dev'],
            $generatedFor,
            'Only successful lanes should get run-lane.sh'
        );
        $this->assertFileExists(
            $stableLane . '/build/setup-failed.json',
            'Failed lane must carry a setup-failed.json sidecar'
        );

        $marker = json_decode(
            (string) file_get_contents($stableLane . '/build/setup-failed.json'),
            true
        );
        $this->assertIsArray($marker);
        $this->assertTrue($marker['setup_failed'] ?? false);
        $this->assertSame(['phpunit', 'phpstan'], $marker['tools'] ?? null);
        $this->assertNotEmpty($marker['reason'] ?? '');
    }

    /**
     * When ExtensionInstaller reports an apt-get install failure
     * for a PHP minor (e.g. `php8.5-imaginary` doesn't exist), every
     * lane on that minor must be marked setup-failed with category
     * `platform_missing` *before* composer install runs.
     *
     * The composer-install attempt for such lanes is wasted work — it
     * would fail downstream with the cryptic "ext-<x> is missing" and
     * produce a marker the maintainer has to decode. The early-skip routing
     * produces a clean per-lane marker naming the exact missing
     * extension.
     */
    public function testExtInstallFailureMarksLaneSetupFailedBeforeComposer(): void
    {
        $workDir = $this->tempDir . '/work';
        $config = new CiConfig([
            'mode' => 'local',
            'component_name' => 'TestComponent',
            'component_path' => $this->testComponentPath,
            'work_dir' => $workDir,
            'php_versions' => ['8.4'],
            'min_php_version' => '8.2',
            'component_stability' => 'stable',
            'local_components_path' => '/usr/bin/horde-components',
            'components_path' => '/usr/bin/horde-components',
        ]);

        $this->phpInstaller->method('install');
        $this->phpInstaller->method('getPhpBinary')->willReturnCallback(
            static fn (string $v): string => "/usr/bin/php{$v}"
        );

        // ExtensionInstaller reports `imaginary` could not be installed
        // for PHP 8.4. Both 8.4 lanes (dev + stable) must therefore be
        // marked setup-failed with platform_missing.
        $this->extensionInstaller
            ->method('detectExtensionsPerVersion')
            ->willReturn(['8.4' => ['imaginary']]);
        $this->extensionInstaller
            ->method('installPerVersion')
            ->willReturn(['8.4' => ['imaginary']]);

        // Create the lane directories on disk so writeSetupFailureMarker
        // has somewhere to land its sidecar. We only care about the 8.4
        // lanes; if other lanes get created we don't assert on them.
        $this->laneCopier
            ->method('copyToLanes')
            ->willReturnCallback(static function () use ($workDir): bool {
                foreach (['8.4-dev', '8.4-stable'] as $lane) {
                    mkdir("{$workDir}/lanes/php{$lane}/TestComponent", 0o755, true);
                }
                return true;
            });

        // composerInstaller and laneScriptGenerator may be called for
        // healthy lanes; stub them but don't pin call counts. The
        // contract here is about the marker, not about who else ran.
        $this->composerInstaller->method('install');
        $this->composerInstaller->method('verifyInstallation')->willReturn(true);
        $this->composerInstaller->method('getInstalledPackageCount')->willReturn(0);
        $this->laneScriptGenerator->method('generate')->willReturn(true);
        $this->toolCache->method('ensureAllTools');

        $this->setupCommand->execute($config);

        // Both 8.4 lanes carry the platform_missing marker with the
        // exact failed ext name and the PHP version.
        foreach (['8.4-dev', '8.4-stable'] as $lane) {
            $markerPath = "{$workDir}/lanes/php{$lane}/TestComponent/build/setup-failed.json";
            $this->assertFileExists($markerPath, "Marker missing for {$lane}");
            $marker = json_decode((string) file_get_contents($markerPath), true);
            $this->assertIsArray($marker);
            $this->assertTrue($marker['setup_failed'] ?? false);
            $this->assertSame(
                'platform_missing',
                $marker['category'] ?? null,
                "Setup-failed marker for {$lane} must use the platform_missing category"
            );
            $this->assertStringContainsString(
                'ext-imaginary',
                (string) ($marker['reason'] ?? ''),
                'Reason must name the failed extension verbatim'
            );
            $this->assertStringContainsString(
                'PHP 8.4',
                (string) ($marker['reason'] ?? ''),
                'Reason must name the PHP version so the maintainer sees which lane and why'
            );
        }
    }

    /**
     * readComponentInfo() must derive `php_versions` from the
     * component's declared `dependencies.required.php` constraint via
     * PlatformResolver::phpVersionLaneSet(). The previous regex-based
     * extraction only computed `min_php_version` and lost the upper
     * bound entirely - a component with `^8.1` would silently lose its
     * 8.1 lane and a hypothetical `^8.3` component would keep a stale
     * 8.2 lane.
     */
    public function testReadComponentInfoComputesPhpVersionsFromCaret81(): void
    {
        // Date-style: php: ^8.1 should produce lanes for every PHP
        // minor we know that satisfies the constraint (8.1 through
        // CANDIDATE_PHP_MINORS' tail).
        $info = $this->callReadComponentInfo('^8.1');
        $this->assertSame(
            ['8.1', '8.2', '8.3', '8.4', '8.5', '8.6'],
            $info['php_versions'],
            'php: ^8.1 should expand to the full 8.1..8.6 lane set'
        );
        $this->assertSame(
            '8.1',
            $info['min_php_version'],
            'min_php_version is the first lane, no separate regex needed'
        );
    }

    public function testReadComponentInfoComputesPhpVersionsFromCaret82(): void
    {
        // Most Horde libraries: php: ^8.2 drops 8.0 and 8.1 from the
        // matrix entirely. The previous static fallback included
        // neither, so this is identical to the old behaviour for the
        // common case.
        $info = $this->callReadComponentInfo('^8.2');
        $this->assertSame(
            ['8.2', '8.3', '8.4', '8.5', '8.6'],
            $info['php_versions']
        );
        $this->assertSame('8.2', $info['min_php_version']);
    }

    public function testReadComponentInfoFallsBackToFullCandidatesWithoutConstraint(): void
    {
        // .horde.yml lacks dependencies.required.php entirely. Lane
        // selection casts a wide net rather than silently picking one
        // version.
        $info = $this->callReadComponentInfoFromYaml(
            <<<YAML
                id: NoPhpConstraint
                name: NoPhpConstraint
                type: library
                version:
                  release: 1.0.0
                state:
                  release: stable
                YAML
        );
        $this->assertSame(
            PlatformResolver::CANDIDATE_PHP_MINORS,
            $info['php_versions'],
            'A component without a php constraint should run on the full candidate list'
        );
        // min_php_version comes from the first candidate; today 8.0.
        $this->assertSame(PlatformResolver::CANDIDATE_PHP_MINORS[0], $info['min_php_version']);
    }

    /**
     * Helper: invoke private readComponentInfo() with a synthetic
     * .horde.yml whose `dependencies.required.php` is the given
     * constraint string.
     */
    private function callReadComponentInfo(string $phpConstraint): array
    {
        return $this->callReadComponentInfoFromYaml(
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
                    php: {$phpConstraint}
                YAML
        );
    }

    /**
     * Helper: invoke private readComponentInfo() with the given raw
     * .horde.yml content.
     */
    private function callReadComponentInfoFromYaml(string $yaml): array
    {
        // Use a dedicated component path so the YAML written here
        // doesn't collide with the setUp() fixture (which is ^8.2).
        $path = $this->tempDir . '/info-fixture-' . uniqid();
        mkdir($path, 0o755, true);
        file_put_contents($path . '/.horde.yml', $yaml);

        $method = new ReflectionMethod(SetupCommand::class, 'readComponentInfo');
        $method->setAccessible(true);
        return (array) $method->invoke($this->setupCommand, $path);
    }

    /**
     * When PhpInstaller::filterAvailable() drops a PHP minor (ondrej/php
     * has not shipped phpX.Y yet), SetupCommand must drop that minor
     * from every downstream step (extension install, lane copy, lane
     * scripts) - not just from PhpInstaller::install(). Otherwise the
     * extension installer reaches for a php binary that does not exist
     * and the lane goes setup-failed for the wrong reason.
     */
    public function testFilterAvailableDropsUnavailableMinorsFromEntireFlow(): void
    {
        // Override the setUp() fixture so readComponentInfo() resolves
        // a constraint matching the lane list under test. `^8.3` expands
        // (via PlatformResolver::phpVersionLaneSet) to [8.3, 8.4, 8.5, 8.6];
        // the filter drops 8.6 to simulate the real-world case where
        // ondrej/php has not shipped that minor's apt package yet.
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
                    php: ^8.3
                YAML
        );

        $workDir = $this->tempDir . '/work';
        $config = new CiConfig([
            'mode' => 'local',
            'component_name' => 'TestComponent',
            'component_path' => $this->testComponentPath,
            'work_dir' => $workDir,
            // The php_versions key here is overridden by
            // readComponentInfo() during setupCommand->execute(); the
            // .horde.yml constraint above is the real driver.
            'min_php_version' => '8.3',
            'component_stability' => 'stable',
            'local_components_path' => '/usr/bin/horde-components',
            'components_path' => '/usr/bin/horde-components',
        ]);

        // Override the setUp() passthrough: drop 8.6 (apt has no
        // php8.6 package yet) and keep 8.3, 8.4, 8.5.
        $this->phpInstaller = $this->createMock(PhpInstaller::class);
        $this->phpInstaller
            ->method('filterAvailable')
            ->with(['8.3', '8.4', '8.5', '8.6'])
            ->willReturn(['8.3', '8.4', '8.5']);

        // install() must only receive the filtered set; if it received
        // the original list it would throw on 8.6 in production.
        $this->phpInstaller
            ->expects($this->once())
            ->method('install')
            ->with(['8.3', '8.4', '8.5']);

        $this->phpInstaller
            ->method('getPhpBinary')
            ->willReturnCallback(static fn (string $v): string => "/usr/bin/php{$v}");

        // detectExtensionsPerVersion must receive the filtered set
        // too. Asking for extensions for a PHP we cannot install would
        // attempt apt-get install php8.6-X and fail downstream with the
        // same diagnostic the filter was designed to prevent.
        $this->extensionInstaller
            ->expects($this->once())
            ->method('detectExtensionsPerVersion')
            ->with($this->testComponentPath, 'TestComponent', ['8.3', '8.4', '8.5'])
            ->willReturn(['8.3' => [], '8.4' => [], '8.5' => []]);

        $this->extensionInstaller->method('installPerVersion')->willReturn([]);
        $this->laneCopier->method('copyToLanes');
        $this->composerInstaller->method('install');
        $this->composerInstaller->method('verifyInstallation')->willReturn(true);
        $this->composerInstaller->method('getInstalledPackageCount')->willReturn(10);
        $this->toolCache->method('ensureAllTools');

        // Capture which lanes get a run-lane.sh; 8.6 must not appear.
        $generatedLanes = [];
        $this->laneScriptGenerator
            ->method('generate')
            ->willReturnCallback(function (string $_path, array $cfg) use (&$generatedLanes): bool {
                $generatedLanes[] = $cfg['php_version'];
                return true;
            });

        // Rebuild the command with the per-test phpInstaller mock.
        $this->setupCommand = new SetupCommand(
            $this->output,
            $this->phpInstaller,
            $this->extensionInstaller,
            $this->laneCopier,
            $this->composerInstaller,
            $this->toolCache,
            $this->laneScriptGenerator
        );

        $this->setupCommand->execute($config);

        $this->assertNotContains(
            '8.6',
            $generatedLanes,
            'Dropped PHP minor must not get a run-lane.sh'
        );
        $this->assertContains('8.3', $generatedLanes);
        $this->assertContains('8.4', $generatedLanes);
        $this->assertContains('8.5', $generatedLanes);
    }
}
