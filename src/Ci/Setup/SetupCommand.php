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

namespace Horde\Components\Ci\Setup;

use Horde\Components\Exception;
use Horde\Components\Output;
use Horde\Components\Ci\Config\CiConfig;
use Horde\Components\Component;
use Horde\HordeYmlFile\HordeYmlFile;

/**
 * Main orchestrator for CI setup.
 *
 * Coordinates all setup steps:
 * 1. Detect/validate environment
 * 2. Install PHP versions
 * 3. Install extensions
 * 4. Copy component to lanes
 * 5. Run composer install per lane
 * 6. Generate lane execution scripts
 * 7. Download QC tools
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class SetupCommand
{
    /**
     * Constructor.
     *
     * @param Output $output Output handler
     * @param PhpInstaller $phpInstaller PHP installer
     * @param ExtensionInstaller $extensionInstaller Extension installer
     * @param LaneCopier $laneCopier Lane copier
     * @param ComposerInstaller $composerInstaller Composer installer
     * @param ToolCache $toolCache Tool cache manager
     * @param LaneScriptGenerator $laneScriptGenerator Lane script generator
     */
    public function __construct(
        private readonly Output $output,
        private readonly PhpInstaller $phpInstaller,
        private readonly ExtensionInstaller $extensionInstaller,
        private readonly LaneCopier $laneCopier,
        private readonly ComposerInstaller $composerInstaller,
        private readonly ToolCache $toolCache,
        private readonly LaneScriptGenerator $laneScriptGenerator,
        private readonly PhpUnitMatrix $phpUnitMatrix = new PhpUnitMatrix(),
    ) {}

    /**
     * Execute CI setup.
     *
     * @param CiConfig $config CI configuration
     * @return bool True if successful
     * @throws Exception If setup fails
     */
    public function execute(CiConfig $config): bool
    {
        $this->output->bold('=== Horde CI Setup ===');
        $this->output->info("Component: {$config->componentName}");
        $this->output->info("Mode: {$config->mode}");
        $this->output->info("Branch: {$config->componentBranch}");
        $this->output->plain('');

        // Validate configuration
        $this->output->info('[1/5] Validating configuration...');
        $errors = $config->validate();
        if (!empty($errors)) {
            foreach ($errors as $error) {
                $this->output->error($error);
            }
            throw new Exception('Configuration validation failed');
        }
        $this->output->ok('Configuration valid');
        $this->output->plain('');

        // Detect component info from .horde.yml
        $this->output->info('[2/5] Reading component metadata...');
        $componentInfo = $this->readComponentInfo($config->componentPath);

        // Update config with detected values
        $config = new CiConfig(array_merge([
            'mode' => $config->mode,
            'component_name' => $config->componentName,
            'component_branch' => $config->componentBranch,
            'component_path' => $config->componentPath,
            'work_dir' => $config->workDir,
            'github_token' => $config->githubToken,
            'components_phar_url' => $config->componentsPharUrl,
            'local_components_path' => $config->localComponentsPath,
            'components_path' => $config->componentsPath,
        ], $componentInfo));

        $this->output->ok("Component type: {$config->componentType}");
        $this->output->ok("Min PHP: {$config->minPhpVersion}");
        $this->output->ok("Stability: {$config->componentStability}");
        $this->output->plain('');

        // Check component type support
        if ($config->componentType !== 'library') {
            throw new Exception(
                "Component type '{$config->componentType}' not yet implemented. "
                . "Only 'library' is supported in Phase 1."
            );
        }

        // Install PHP versions
        $this->output->info('[3/5] Installing PHP versions...');
        $testableVersions = $config->getTestablePhpVersions();
        $this->output->info('Testable versions: ' . implode(', ', $testableVersions));

        $this->phpInstaller->install($testableVersions);
        $this->output->plain('');

        // Install extensions
        $this->output->info('[4/5] Installing PHP extensions...');
        $extensions = $this->extensionInstaller->detectExtensions(
            $config->componentPath,
            $config->componentName
        );
        $this->output->info('Required extensions: ' . implode(', ', $extensions));

        $this->extensionInstaller->install($extensions, $testableVersions);
        $this->output->plain('');

        // Copy to lanes
        $this->output->info('[5/5] Setting up test lanes...');
        $lanes = $config->getTestLanes();
        $this->output->info('Creating ' . count($lanes) . ' test lanes');

        $this->laneCopier->copyToLanes($config);
        $this->output->plain('');

        // Detect lanes deliberately incompatible with the component's
        // declared PHPUnit constraint. Each such lane gets a sidecar
        // build/skip.json so RunCommand can mark it as a deliberate skip
        // (NOT a failure) without ever invoking composer install.
        $skippedLanes = $this->markIncompatibleLanes($lanes);
        if (!empty($skippedLanes)) {
            $this->output->plain('');
        }

        // Run composer install for each lane
        $this->output->bold('=== Running composer install ===');
        $successful = 0;
        $failed = 0;

        foreach ($lanes as $index => $lane) {
            $num = $index + 1;
            $total = count($lanes);
            $this->output->info("[{$num}/{$total}] PHP {$lane['php']} ({$lane['stability']})");

            if (in_array($index, $skippedLanes, true)) {
                $this->output->skip('Skipped (incompatible PHPUnit constraint)');
                $this->output->plain('');
                continue;
            }

            try {
                $phpBinary = $this->phpInstaller->getPhpBinary($lane['php']);

                $this->composerInstaller->install(
                    $lane['dir'],
                    $phpBinary,
                    $lane['stability']
                );

                // Verify installation
                if ($this->composerInstaller->verifyInstallation($lane['dir'])) {
                    $packageCount = $this->composerInstaller->getInstalledPackageCount($lane['dir']);
                    $this->output->ok("  ✓ Installed ({$packageCount} packages)");
                    $successful++;
                } else {
                    $this->output->warn("  ⚠ Installation verification failed");
                    $failed++;
                }
            } catch (Exception $e) {
                $this->output->error("  ✗ Failed: " . $e->getMessage());
                $failed++;
            }

            $this->output->plain('');
        }

        // Generate lane execution scripts
        $this->output->bold('=== Generating lane scripts ===');
        $scriptsFailed = 0;

        foreach ($lanes as $index => $lane) {
            $num = $index + 1;
            $total = count($lanes);

            // Construct lane name from PHP version and stability
            $laneName = 'php' . $lane['php'] . '-' . $lane['stability'];
            $scriptPath = dirname($lane['dir']) . '/run-lane.sh';

            $this->output->info("[{$num}/{$total}] {$laneName}");

            if (in_array($index, $skippedLanes, true)) {
                $this->output->skip('Skipped (no script generated)');
                continue;
            }

            $laneConfig = [
                'lane_name' => $laneName,
                'php_version' => $lane['php'],
                'php_binary' => $this->phpInstaller->getPhpBinary($lane['php']),
                'stability' => $lane['stability'],
                'component_dir' => $lane['dir'], // dir IS the component dir
                'tools_dir' => $config->workDir . '/tools',
                'build_dir' => $lane['dir'] . '/build',
                'components_path' => $config->componentsPath,  // NEW
            ];

            if ($this->laneScriptGenerator->generate($scriptPath, $laneConfig)) {
                $this->output->ok("  ✓ Generated {$scriptPath}");
            } else {
                $this->output->error("  ✗ Failed to generate script for lane: {$laneName}");
                $scriptsFailed++;
            }
        }

        $this->output->plain('');

        // Summary
        $this->output->bold('=== Setup Summary ===');
        $this->output->ok("Successful lanes: {$successful}");

        if (!empty($skippedLanes)) {
            $this->output->info("Skipped lanes: " . count($skippedLanes) . " (incompatible PHPUnit constraint)");
        }

        if ($failed > 0) {
            $this->output->warn("Failed lanes: {$failed}");
        }

        if ($scriptsFailed > 0) {
            $this->output->warn("Failed script generation: {$scriptsFailed}");
        }

        $this->output->plain('');

        // Download QC tools to cache
        $this->output->bold('=== Downloading QC Tools ===');
        try {
            $this->toolCache->ensureAllTools(
                $this->collectPhpUnitTags($lanes, $skippedLanes)
            );
            $this->output->ok('All tools downloaded');
        } catch (Exception $e) {
            $this->output->error("Tool download failed: " . $e->getMessage());
            return false;
        }

        $this->output->plain('');
        $this->output->bold('Setup complete!');
        $this->output->info("Workspace: {$config->workDir}");
        $this->output->info("Tools cache: {$config->workDir}/tools");
        $this->output->info("Next step: horde-components ci run --work-dir={$config->workDir}");

        return $failed === 0;
    }

    /**
     * Read component information from .horde.yml.
     *
     * @param string $componentPath Path to component
     * @return array<string,mixed> Component info
     * @throws Exception If .horde.yml not found or invalid
     */
    private function readComponentInfo(string $componentPath): array
    {
        $hordeYmlPath = $componentPath . '/.horde.yml';

        if (!file_exists($hordeYmlPath)) {
            throw new Exception('.horde.yml not found in component directory');
        }

        try {
            $hordeYml = new HordeYmlFile($hordeYmlPath);
        } catch (\Exception $e) {
            throw new Exception('Failed to read .horde.yml: ' . $e->getMessage(), 0, $e);
        }

        // Extract minimum PHP version using typed Dependencies API
        $minPhp = '8.2'; // Default
        $deps = $hordeYml->getDependencies();
        if ($deps !== null) {
            $phpReq = $deps->getRequiredPhp();
            if ($phpReq !== null) {
                // Parse requirements like "^8.2", ">=8.3", "^8.2 || ^8.3"
                if (preg_match('/[>^~]?\s*(\d+\.\d+)/', $phpReq, $matches)) {
                    $minPhp = $matches[1];
                }
            }
        }

        // Extract component stability
        $stability = $hordeYml->getReleaseState() ?: 'alpha';

        // Extract component type
        $type = $hordeYml->getType() ?: 'library';

        // Detect required extensions from dependencies
        $extensions = [];
        if ($deps !== null) {
            $requiredSet = $deps->getRequired();
            if ($requiredSet !== null) {
                $extensions = $requiredSet->getExtensions();
            }
        }

        return [
            'component_type' => $type,
            'min_php_version' => $minPhp,
            'component_stability' => $stability,
            'required_extensions' => $extensions,
        ];
    }

    /**
     * Detect which lanes are deliberately incompatible with the component's
     * PHPUnit constraint and write a sidecar build/skip.json so RunCommand
     * marks them as deliberate skips rather than running them.
     *
     * @param array<int,array{php: string, stability: string, dir: string}> $lanes
     * @return array<int> Indexes of lanes that were marked as skipped
     */
    private function markIncompatibleLanes(array $lanes): array
    {
        $skipped = [];

        foreach ($lanes as $index => $lane) {
            $composerJson = $lane['dir'] . '/composer.json';
            if (!is_file($composerJson)) {
                // Lane directory not yet populated, or component lacks
                // composer.json. Either way we cannot decide; let
                // composer install fail naturally.
                continue;
            }

            try {
                $selection = $this->phpUnitMatrix->pickWithSource(
                    $composerJson,
                    $lane['php']
                );
            } catch (Exception $e) {
                $this->output->warn(
                    "Could not evaluate PHPUnit compatibility for {$lane['dir']}: "
                    . $e->getMessage()
                );
                continue;
            }

            if ($selection->isSatisfied()) {
                continue;
            }

            // Persist the skip reason so RunCommand can pick it up.
            $buildDir = $lane['dir'] . '/build';
            if (!is_dir($buildDir) && !mkdir($buildDir, 0o755, true) && !is_dir($buildDir)) {
                throw new Exception("Failed to create build directory: {$buildDir}");
            }
            file_put_contents(
                $buildDir . '/skip.json',
                json_encode(
                    [
                        'deliberate_skip' => true,
                        'tools' => ['phpunit', 'phpstan'],
                        'reason' => $selection->skipReason,
                    ],
                    JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
                )
            );

            $laneName = 'php' . $lane['php'] . '-' . $lane['stability'];
            $this->output->skip("{$laneName}: " . $selection->skipReason);
            $skipped[] = $index;
        }

        return $skipped;
    }

    /**
     * Compute the set of PHPUnit tags surviving lanes will need.
     *
     * Iterates the lanes that were not deliberately skipped, asks the
     * matrix which PHPUnit tag fits each, and returns the deduped list.
     *
     * @param array<int,array{php: string, stability: string, dir: string}> $lanes
     * @param array<int> $skippedLanes Indexes of lanes that were skipped
     * @return array<string> Unique PHPUnit tags (e.g. ["11.5", "12.5"])
     */
    private function collectPhpUnitTags(array $lanes, array $skippedLanes): array
    {
        $tags = [];
        foreach ($lanes as $index => $lane) {
            if (in_array($index, $skippedLanes, true)) {
                continue;
            }
            $composerJson = $lane['dir'] . '/composer.json';
            if (!is_file($composerJson)) {
                continue;
            }
            try {
                $selection = $this->phpUnitMatrix->pickWithSource(
                    $composerJson,
                    $lane['php']
                );
            } catch (Exception) {
                continue;
            }
            if ($selection->tag !== null) {
                $tags[] = $selection->tag;
            }
        }
        return array_values(array_unique($tags));
    }
}
