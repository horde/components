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

/**
 * Main orchestrator for CI setup.
 *
 * Coordinates all setup steps:
 * 1. Detect/validate environment
 * 2. Install PHP versions
 * 3. Install extensions
 * 4. Copy component to lanes
 * 5. Run composer install per lane
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
     */
    public function __construct(
        private readonly Output $output,
        private readonly PhpInstaller $phpInstaller,
        private readonly ExtensionInstaller $extensionInstaller,
        private readonly LaneCopier $laneCopier,
        private readonly ComposerInstaller $composerInstaller
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
        ], $componentInfo));

        $this->output->ok("Component type: {$config->componentType}");
        $this->output->ok("Min PHP: {$config->minPhpVersion}");
        $this->output->ok("Stability: {$config->componentStability}");
        $this->output->plain('');

        // Check component type support
        if ($config->componentType !== 'library') {
            throw new Exception(
                "Component type '{$config->componentType}' not yet implemented. " .
                "Only 'library' is supported in Phase 1."
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

        // Run composer install for each lane
        $this->output->bold('=== Running composer install ===');
        $successful = 0;
        $failed = 0;

        foreach ($lanes as $index => $lane) {
            $num = $index + 1;
            $total = count($lanes);
            $this->output->info("[{$num}/{$total}] PHP {$lane['php']} ({$lane['stability']})");

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

        // Summary
        $this->output->bold('=== Setup Summary ===');
        $this->output->ok("Successful lanes: {$successful}");

        if ($failed > 0) {
            $this->output->warn("Failed lanes: {$failed}");
        }

        $this->output->plain('');
        $this->output->bold('Setup complete!');
        $this->output->info("Workspace: {$config->workDir}");
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
        $hordeYml = $componentPath . '/.horde.yml';

        if (!file_exists($hordeYml)) {
            throw new Exception('.horde.yml not found in component directory');
        }

        $content = file_get_contents($hordeYml);
        if ($content === false) {
            throw new Exception('Failed to read .horde.yml');
        }

        // Parse YAML (simple parsing for now)
        $data = $this->parseSimpleYaml($content);

        // Extract minimum PHP version
        $minPhp = '8.2'; // Default
        if (isset($data['dependencies']['required']['php'])) {
            $phpReq = $data['dependencies']['required']['php'];
            // Parse requirements like "^8.2", ">=8.3", "^8.2 || ^8.3"
            if (preg_match('/[>^~]?\s*(\d+\.\d+)/', $phpReq, $matches)) {
                $minPhp = $matches[1];
            }
        }

        // Extract component stability
        $stability = 'alpha'; // Default
        if (isset($data['state']['release'])) {
            $stability = $data['state']['release'];
        }

        // Extract component type
        $type = 'library'; // Default
        if (isset($data['type'])) {
            $type = $data['type'];
        }

        // Detect required extensions from dependencies
        $extensions = [];
        if (isset($data['dependencies']['required']['ext']) && is_array($data['dependencies']['required']['ext'])) {
            foreach ($data['dependencies']['required']['ext'] as $ext) {
                if (is_string($ext)) {
                    $extensions[] = $ext;
                }
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
     * Simple YAML parser (handles basic structure only).
     *
     * This is a simplified parser for .horde.yml structure.
     * For production, consider using symfony/yaml.
     *
     * @param string $content YAML content
     * @return array<string,mixed> Parsed data
     */
    private function parseSimpleYaml(string $content): array
    {
        $data = [];
        $lines = explode("\n", $content);
        $stack = [&$data];
        $indents = [0];

        foreach ($lines as $line) {
            // Skip comments and empty lines
            if (preg_match('/^\s*#/', $line) || trim($line) === '') {
                continue;
            }

            // Get indentation
            preg_match('/^(\s*)/', $line, $matches);
            $indent = strlen($matches[1]);
            $line = trim($line);

            // Pop stack if indent decreased
            while (count($indents) > 1 && $indent < end($indents)) {
                array_pop($stack);
                array_pop($indents);
            }

            // Parse key: value
            if (preg_match('/^([^:]+):\s*(.*)$/', $line, $matches)) {
                $key = trim($matches[1]);
                $value = trim($matches[2]);

                $current = &$stack[count($stack) - 1];

                if ($value === '') {
                    // New array
                    $current[$key] = [];
                    $stack[] = &$current[$key];
                    $indents[] = $indent;
                } else {
                    // Simple value
                    $current[$key] = $value;
                }
            }
            // Parse array item
            elseif (preg_match('/^-\s+(.+)$/', $line, $matches)) {
                $value = trim($matches[1]);
                $current = &$stack[count($stack) - 1];
                $current[] = $value;
            }
        }

        return $data;
    }
}
