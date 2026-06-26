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
use Horde\Components\Helper\PlatformResolver;
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

        // Drop PHP minors that apt/ondrej does not actually ship yet. A
        // component declaring `^8.2` legitimately includes 8.6 in its
        // testable set the moment PHP 8.6 enters CANDIDATE_PHP_MINORS,
        // but ondrej/php may not have shipped a phpX.Y package for that
        // minor yet. Without this filter PhpInstaller would throw a Fatal
        // and abort the whole run; with it the lane simply isn't in the
        // matrix for this build.
        $availableVersions = $this->phpInstaller->filterAvailable($testableVersions);
        if ($availableVersions !== $testableVersions) {
            $dropped = array_values(array_diff($testableVersions, $availableVersions));
            $this->output->info(sprintf(
                'Lanes dropped (not available in apt): %s',
                implode(', ', $dropped)
            ));
            // Rebuild CiConfig with the filtered list so every later
            // step (extension install, lane copier, lane matrix, run
            // command) sees the same set.
            $config = new CiConfig(array_merge([
                'mode' => $config->mode,
                'component_name' => $config->componentName,
                'component_branch' => $config->componentBranch,
                'component_type' => $config->componentType,
                'component_path' => $config->componentPath,
                'work_dir' => $config->workDir,
                'github_token' => $config->githubToken,
                'components_phar_url' => $config->componentsPharUrl,
                'local_components_path' => $config->localComponentsPath,
                'components_path' => $config->componentsPath,
                'php_versions' => $availableVersions,
                'min_php_version' => $availableVersions[0] ?? $config->minPhpVersion,
                'component_stability' => $config->componentStability,
                'required_extensions' => $config->requiredExtensions,
            ]));
            $testableVersions = $config->getTestablePhpVersions();
        }

        $this->phpInstaller->install($testableVersions);
        $this->output->plain('');

        // Install extensions
        $this->output->info('[4/5] Installing PHP extensions...');
        // Per-PHP-minor resolution: when the component has a resolved
        // ci-platform block in .horde.yml (written by
        // `horde-components dependencies --platform`), each lane installs
        // exactly the transitively-required extensions for its PHP minor.
        // Otherwise the helper falls back to flat baseline+composer.json
        // detection per version.
        $extensionsPerVersion = $this->extensionInstaller->detectExtensionsPerVersion(
            $config->componentPath,
            $config->componentName,
            $testableVersions
        );

        // Log the resolved sets so a failed-extension story in the
        // composer install step downstream has a paper trail right
        // here.
        foreach ($extensionsPerVersion as $minor => $exts) {
            $this->output->info(sprintf(
                'PHP %s extensions: %s',
                $minor,
                $exts === [] ? '(none)' : implode(', ', $exts)
            ));
        }

        // Capture per-version install failures so any lane whose
        // PHP version is missing a required ext-* gets marked
        // setup-failed *before* the composer-install loop wastes time
        // producing a cryptic downstream error. The map is keyed by
        // PHP minor -> list of failed extension names.
        $extInstallFailures = $this->extensionInstaller->installPerVersion($extensionsPerVersion);
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
        // Indexes of lanes whose composer-install failed. RunCommand will
        // pick the build/setup-failed.json sidecar up and surface the lane
        // as a failure rather than aborting the whole run.
        $setupFailedLanes = [];

        foreach ($lanes as $index => $lane) {
            $num = $index + 1;
            $total = count($lanes);
            $this->output->info("[{$num}/{$total}] PHP {$lane['php']} ({$lane['stability']})");

            if (in_array($index, $skippedLanes, true)) {
                $this->output->skip('Skipped (incompatible PHPUnit constraint)');
                $this->output->plain('');
                continue;
            }

            // Bail early when the PHP version for this lane is
            // missing one or more required extensions. Without this,
            // composer install would run, fail with the cryptic
            // "ext-<x> is missing" message, and produce a setup-failed
            // marker the maintainer has to decode. The category-tagged
            // marker we write here surfaces in the PR comment as
            // "Platform requirement missing" with the exact ext name.
            $extFailures = $extInstallFailures[$lane['php']] ?? [];
            if (!empty($extFailures)) {
                $reason = sprintf(
                    'Failed to install required extension(s) for PHP %s: %s',
                    $lane['php'],
                    implode(', ', array_map(static fn (string $e): string => 'ext-' . $e, $extFailures))
                );
                $this->output->error("  ✗ {$reason}");
                $this->writeSetupFailureMarker(
                    $lane['dir'],
                    $reason,
                    'platform_missing'
                );
                $setupFailedLanes[] = $index;
                $failed++;
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
                    $this->writeSetupFailureMarker(
                        $lane['dir'],
                        'composer install verification failed',
                        'unknown'
                    );
                    $setupFailedLanes[] = $index;
                    $failed++;
                }
            } catch (Exception $e) {
                $category = ComposerInstaller::classifyError($e->getMessage());
                $this->output->error("  ✗ Failed [{$category}]: " . $e->getMessage());
                $this->writeSetupFailureMarker(
                    $lane['dir'],
                    $e->getMessage(),
                    $category
                );
                $setupFailedLanes[] = $index;
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

            if (in_array($index, $setupFailedLanes, true)) {
                // Lane's composer install failed; no run-lane.sh is written.
                // The sidecar setup-failed.json (from
                // writeSetupFailureMarker above) tells RunCommand to mark
                // the lane as a failure in the PR summary without trying to run it.
                $this->output->skip('Skipped (setup failed, no script generated)');
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

        // Tolerate partial setup.
        //
        // The CI value of this run is the per-lane breakdown, not "did
        // every lane install cleanly". Lanes that failed composer install
        // carry a build/setup-failed.json marker; RunCommand surfaces them
        // as a failure in the PR comment alongside any lanes that ran. We only
        // signal global setup failure (which Module\Ci translates into a
        // throw + non-zero exit) when no non-skipped lane is usable  - 
        // there is then nothing for ci run to do.
        $usableLanes = $successful;
        $totalNonSkipped = count($lanes) - count($skippedLanes);
        if ($usableLanes === 0 && $totalNonSkipped > 0) {
            return false;
        }
        if ($failed > 0) {
            $this->output->warn(sprintf(
                'Continuing with %d/%d usable lanes; %d lane(s) marked as setup-failed.',
                $usableLanes,
                $totalNonSkipped,
                $failed
            ));
        }
        return true;
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

        // Compute the PHP-minor lane set from the component's declared
        // PHP constraint. PlatformResolver::phpVersionLaneSet() intersects
        // the constraint against CANDIDATE_PHP_MINORS, the canonical list
        // of PHP minors CI knows how to install. An empty or unparseable
        // constraint falls back to the full candidate set (broad-coverage
        // default), not to one version. The minimum PHP version is then
        // simply the first entry of the resulting list, no separate regex
        // needed.
        $deps = $hordeYml->getDependencies();
        $phpReq = $deps?->getRequiredPhp() ?? '';
        $phpVersions = PlatformResolver::phpVersionLaneSet($phpReq);
        $minPhp = $phpVersions[0];

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
            'php_versions' => $phpVersions,
            'component_stability' => $stability,
            'required_extensions' => $extensions,
        ];
    }

    /**
     * Persist a setup-failure marker for a lane.
     *
     * Writes `<laneDir>/build/setup-failed.json` so RunCommand can pick
     * the lane up, register it as a failure for both phpunit and phpstan, and
     * surface the reason in the PR comment. Distinct from
     * `build/skip.json` which means "deliberately not run". A
     * setup-failed lane is a real failure that the maintainer needs to
     * fix; we just don't let it prevent other lanes from running.
     *
     * The optional `$category` is one of the strings returned by
     * {@see ComposerInstaller::classifyError()}; downstream reporting
     * uses it to render `stability_gate` failures as a warning (working as
     * designed) rather than a hard failure (real bug).
     *
     * @param string $laneDir Lane component directory (where build/ lives)
     * @param string $reason Human-readable failure summary
     * @param string $category Classifier output: stability_gate /
     *                          platform_missing / php_version / unknown
     */
    private function writeSetupFailureMarker(
        string $laneDir,
        string $reason,
        string $category = 'unknown'
    ): void {
        $buildDir = $laneDir . '/build';
        if (!is_dir($buildDir) && !mkdir($buildDir, 0o755, true) && !is_dir($buildDir)) {
            // The lane dir may not exist if copy-to-lane failed earlier;
            // in that case we cannot persist a marker. RunCommand's
            // lane-discovery will not see the lane at all and the warn()
            // above is the only surface for the failure.
            return;
        }
        try {
            $payload = json_encode(
                [
                    'setup_failed' => true,
                    // PHPUnit and PHPStan are the two tools that would
                    // have run; both inherit the failure so the
                    // aggregator and PR comment present a coherent
                    // story.
                    'tools' => ['phpunit', 'phpstan'],
                    'reason' => $reason,
                    'category' => $category,
                ],
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
            );
        } catch (\JsonException) {
            return;
        }
        @file_put_contents($buildDir . '/setup-failed.json', $payload);
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
