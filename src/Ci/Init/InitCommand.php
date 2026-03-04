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

namespace Horde\Components\Ci\Init;

use Horde\Components\Exception;
use Horde\Components\Output;
use Horde\Components\Ci\Template\TemplateRenderer;
use Horde\Components\Ci\Template\TemplateLocator;
use Horde\Components\Ci\Template\TemplateVersion;

/**
 * Initialize CI for a component.
 *
 * Generates CI configuration files from templates:
 * - bin/ci-bootstrap.sh - Bootstrap script
 * - .github/workflows/ci.yml - GitHub Actions workflow (github mode only)
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class InitCommand
{
    /**
     * Constructor.
     *
     * @param Output $output Output handler
     */
    public function __construct(
        private readonly Output $output
    ) {}

    /**
     * Execute ci init command.
     *
     * @param string $componentPath Path to component directory
     * @param string $mode Mode (github or local)
     * @param bool $force Force overwrite existing files
     * @param bool $dryRun Show what would be generated without writing
     * @return bool True if successful
     * @throws Exception If generation fails
     */
    public function execute(
        string $componentPath,
        string $mode = 'github',
        bool $force = false,
        bool $dryRun = false
    ): bool {
        $this->output->bold('=== Horde CI Init ===');
        $this->output->info("Component path: {$componentPath}");
        $this->output->info("Mode: {$mode}");
        $this->output->plain('');

        // Validate mode
        if (!in_array($mode, ['github', 'local'])) {
            throw new Exception("Invalid mode: {$mode}. Must be 'github' or 'local'.");
        }

        // Validate component path
        if (!is_dir($componentPath)) {
            throw new Exception("Component directory does not exist: {$componentPath}");
        }

        // Detect component name
        $componentName = basename(realpath($componentPath));
        $this->output->info("Component: {$componentName}");
        $this->output->plain('');

        // Get configuration values
        $config = $this->getConfiguration($componentPath, $componentName, $mode);

        // Check for existing files
        $existingFiles = $this->checkExistingFiles($componentPath, $mode);
        if (!empty($existingFiles) && !$force && !$dryRun) {
            $this->output->warn('The following files already exist:');
            foreach ($existingFiles as $file) {
                $this->output->plain("  - {$file}");
            }
            $this->output->plain('');
            $this->output->warn('Use --force to overwrite existing files.');
            return false;
        }

        // Generate files
        $renderer = new TemplateRenderer(TemplateLocator::getTemplateDir());

        // Generate bootstrap script
        $bootstrapFile = $componentPath . '/bin/ci-bootstrap.sh';
        $this->generateBootstrapScript(
            $renderer,
            $mode,
            $config,
            $bootstrapFile,
            $dryRun
        );

        // Generate workflow (GitHub mode only)
        if ($mode === 'github') {
            $workflowFile = $componentPath . '/.github/workflows/ci.yml';
            $this->generateWorkflow(
                $renderer,
                $config,
                $workflowFile,
                $dryRun
            );
        }

        $this->output->plain('');
        if ($dryRun) {
            $this->output->bold('=== Dry run complete (no files written) ===');
        } else {
            $this->output->bold('=== CI initialization complete ===');
            $this->output->info('Generated files can be committed to your repository.');
            $this->output->info('To regenerate: horde-components ci init --force');
        }

        return true;
    }

    /**
     * Check for existing CI files.
     *
     * @param string $componentPath Component path
     * @param string $mode Mode
     * @return array<string> Relative paths to existing files
     */
    private function checkExistingFiles(string $componentPath, string $mode): array
    {
        $existing = [];

        $bootstrapFile = $componentPath . '/bin/ci-bootstrap.sh';
        if (file_exists($bootstrapFile)) {
            $existing[] = 'bin/ci-bootstrap.sh';

            // Check if outdated
            if (TemplateVersion::isOutdated($bootstrapFile)) {
                $comparison = TemplateVersion::compare($bootstrapFile);
                $this->output->warn(
                    "  (outdated: v{$comparison['file']} < v{$comparison['current']})"
                );
            }
        }

        if ($mode === 'github') {
            $workflowFile = $componentPath . '/.github/workflows/ci.yml';
            if (file_exists($workflowFile)) {
                $existing[] = '.github/workflows/ci.yml';
            }
        }

        return $existing;
    }

    /**
     * Get configuration values.
     *
     * @param string $componentPath Component path
     * @param string $componentName Component name
     * @param string $mode Mode
     * @return array<string,string> Configuration variables
     */
    private function getConfiguration(
        string $componentPath,
        string $componentName,
        string $mode
    ): array {
        $config = [
            '{{COMPONENT_NAME}}' => $componentName,
            '{{WORK_DIR}}' => '/tmp/horde-ci',
        ];

        if ($mode === 'github') {
            // GitHub mode configuration
            $config['{{COMPONENTS_PHAR_URL}}'] = $this->getComponentsPharUrl();
        } else {
            // Local mode configuration
            $config['{{LOCAL_COMPONENTS_PATH}}'] = getenv('LOCAL_COMPONENTS_PATH') ?: '${LOCAL_COMPONENTS_PATH}';
            $config['{{LOCAL_COMPONENT_PATH}}'] = getenv('LOCAL_COMPONENT_PATH') ?: '${LOCAL_COMPONENT_PATH}';
        }

        return $config;
    }

    /**
     * Get components PHAR URL.
     *
     * Priority:
     * 1. COMPONENTS_PHAR_URL environment variable
     * 2. Config file setting
     * 3. Default to latest GitHub release
     *
     * @return string PHAR URL
     */
    private function getComponentsPharUrl(): string
    {
        // Try environment variable
        $url = getenv('COMPONENTS_PHAR_URL');
        if ($url !== false && $url !== '') {
            return $url;
        }

        // Try from config (would need to access config system)
        // For now, use default pointing to latest GitHub release
        // Note: Users should set organization variable COMPONENTS_PHAR_URL
        // pointing to specific version for production use
        return 'https://github.com/horde/components/releases/latest/download/horde-components.phar';
    }

    /**
     * Generate bootstrap script.
     *
     * @param TemplateRenderer $renderer Template renderer
     * @param string $mode Mode
     * @param array<string,string> $config Configuration
     * @param string $outputFile Output file path
     * @param bool $dryRun Dry run mode
     * @return bool True if successful
     * @throws Exception If generation fails
     */
    private function generateBootstrapScript(
        TemplateRenderer $renderer,
        string $mode,
        array $config,
        string $outputFile,
        bool $dryRun
    ): bool {
        $templateName = $mode === 'github' ? 'bootstrap-github.sh' : 'bootstrap-local.sh';

        $this->output->info("Generating {$outputFile}...");

        try {
            $content = $renderer->render($templateName, $config);

            if ($dryRun) {
                $this->output->plain('--- Preview ---');
                $lines = explode("\n", $content);
                foreach (array_slice($lines, 0, 20) as $line) {
                    $this->output->plain($line);
                }
                if (count($lines) > 20) {
                    $this->output->plain('... (' . (count($lines) - 20) . ' more lines)');
                }
                $this->output->plain('');
                return true;
            }

            // Create directory if needed
            $dir = dirname($outputFile);
            if (!is_dir($dir)) {
                if (!mkdir($dir, 0o755, true)) {
                    throw new Exception("Failed to create directory: {$dir}");
                }
            }

            // Write file
            if (file_put_contents($outputFile, $content) === false) {
                throw new Exception("Failed to write file: {$outputFile}");
            }

            // Make executable
            chmod($outputFile, 0o755);

            $this->output->ok("Created: {$outputFile}");
            return true;

        } catch (Exception $e) {
            $this->output->error("Failed to generate bootstrap script: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Generate GitHub Actions workflow.
     *
     * @param TemplateRenderer $renderer Template renderer
     * @param array<string,string> $config Configuration
     * @param string $outputFile Output file path
     * @param bool $dryRun Dry run mode
     * @return bool True if successful
     * @throws Exception If generation fails
     */
    private function generateWorkflow(
        TemplateRenderer $renderer,
        array $config,
        string $outputFile,
        bool $dryRun
    ): bool {
        $this->output->info("Generating {$outputFile}...");

        try {
            $content = $renderer->render('workflow.yml', $config);

            if ($dryRun) {
                $this->output->plain('--- Preview ---');
                $lines = explode("\n", $content);
                foreach (array_slice($lines, 0, 20) as $line) {
                    $this->output->plain($line);
                }
                if (count($lines) > 20) {
                    $this->output->plain('... (' . (count($lines) - 20) . ' more lines)');
                }
                $this->output->plain('');
                return true;
            }

            // Create directory if needed
            $dir = dirname($outputFile);
            if (!is_dir($dir)) {
                if (!mkdir($dir, 0o755, true)) {
                    throw new Exception("Failed to create directory: {$dir}");
                }
            }

            // Write file
            if (file_put_contents($outputFile, $content) === false) {
                throw new Exception("Failed to write file: {$outputFile}");
            }

            $this->output->ok("Created: {$outputFile}");
            return true;

        } catch (Exception $e) {
            $this->output->error("Failed to generate workflow: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Check command for validating existing CI files.
     *
     * @param string $componentPath Component path
     * @return bool True if files are up to date
     */
    public function check(string $componentPath): bool
    {
        $this->output->bold('=== Checking CI Files ===');
        $this->output->info("Component: {$componentPath}");
        $this->output->plain('');

        $bootstrapFile = $componentPath . '/bin/ci-bootstrap.sh';
        $workflowFile = $componentPath . '/.github/workflows/ci.yml';

        $allCurrent = true;

        // Check bootstrap
        if (!file_exists($bootstrapFile)) {
            $this->output->warn('bin/ci-bootstrap.sh not found');
            $this->output->info('  Run: horde-components ci init');
            $allCurrent = false;
        } else {
            $comparison = TemplateVersion::compare($bootstrapFile);

            if ($comparison['outdated']) {
                $fileVer = $comparison['file'] ?? 'unknown';
                $currentVer = $comparison['current'];
                $this->output->warn("bin/ci-bootstrap.sh is outdated");
                $this->output->info("  File version: {$fileVer}");
                $this->output->info("  Current version: {$currentVer}");
                $this->output->info("  Run: horde-components ci init --force");
                $allCurrent = false;
            } else {
                $this->output->ok("bin/ci-bootstrap.sh is up to date (v{$comparison['file']})");
            }
        }

        // Check workflow
        if (file_exists($workflowFile)) {
            $this->output->ok('.github/workflows/ci.yml exists');
        }

        $this->output->plain('');

        if ($allCurrent) {
            $this->output->bold('All CI files are up to date');
        } else {
            $this->output->bold('Some CI files need updating');
        }

        return $allCurrent;
    }
}
