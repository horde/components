<?php

/**
 * Components_Module_Ci:: manages CI setup and execution for components.
 *
 * PHP version 8.2+
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

declare(strict_types=1);

namespace Horde\Components\Module;

use Horde\Components\Component;
use Horde\Components\Output;
use Horde\Components\Exception;
use Horde\Components\Ci\Setup\SetupCommand;
use Horde\Components\Ci\Setup\EnvironmentDetector;
use Horde\Components\Ci\Setup\PhpInstaller;
use Horde\Components\Ci\Setup\ExtensionInstaller;
use Horde\Components\Ci\Setup\LaneCopier;
use Horde\Components\Ci\Setup\ComposerInstaller;
use Horde\Components\Ci\Setup\ToolCache;
use Horde\Components\Ci\Setup\LaneScriptGenerator;
use Horde\Components\Ci\Init\InitCommand;
use Horde\Components\Ci\Config\CiConfig;
use Horde\Components\Ci\Run\RunCommand;
use Horde\Components\Ci\Run\ResultCollector;

/**
 * Components_Module_Ci:: manages CI setup and execution for components.
 *
 * Copyright 2026 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Ci extends Base
{
    public function getOptionGroupTitle(): string
    {
        return 'Continuous Integration';
    }

    public function getOptionGroupDescription(): string
    {
        return 'This module manages CI setup and execution for Horde components.';
    }

    public function getOptionGroupOptions(): array
    {
        return [
            new \Horde\Argv\Option(
                '--ci-mode',
                [
                    'action' => 'store',
                    'help' => 'CI mode: github or local (default: auto-detect)',
                ]
            ),
            new \Horde\Argv\Option(
                '--work-dir',
                [
                    'action' => 'store',
                    'help' => 'Working directory for CI operations (default: /tmp/horde-ci)',
                ]
            ),
            new \Horde\Argv\Option(
                '--component',
                [
                    'action' => 'store',
                    'help' => 'Component name (default: auto-detect)',
                ]
            ),
            new \Horde\Argv\Option(
                '--local-path',
                [
                    'action' => 'store',
                    'help' => 'Local component path (local mode)',
                ]
            ),
            new \Horde\Argv\Option(
                '--force',
                [
                    'action' => 'store_true',
                    'help' => 'Force overwrite existing files (ci init)',
                ]
            ),
            new \Horde\Argv\Option(
                '--dry-run',
                [
                    'action' => 'store_true',
                    'help' => 'Show what would be generated without writing (ci init)',
                ]
            ),
        ];
    }

    /**
     * Get the usage title for this module.
     *
     * @return string The title.
     */
    public function getTitle(): string
    {
        return 'ci';
    }

    /**
     * Get the usage description for this module.
     *
     * @return string The description.
     */
    public function getUsage(): string
    {
        return 'Manage CI setup and execution.';
    }

    /**
     * Return the action arguments supported by this module.
     *
     * @return array A list of supported action arguments.
     */
    public function getActions(): array
    {
        return ['ci'];
    }

    /**
     * Return the help text for the specified action.
     *
     * @param string $action The action.
     *
     * @return string The help text.
     */
    public function getHelp($action): string
    {
        return 'Manage Continuous Integration for Horde components.

USAGE:
    horde-components ci <subcommand> [OPTIONS]

SUBCOMMANDS:
    init       Generate CI configuration files for a component
    check      Check if CI files are up to date
    setup      Setup CI environment (install PHP, extensions, prepare lanes)
    run        Run CI tests across all lanes (Phase 2 - not yet implemented)

DESCRIPTION:
    The ci command helps set up and run automated testing for Horde components
    across multiple PHP versions and dependency stability levels.

    The system works in two modes:
    - github: For GitHub Actions (downloads horde-components.phar)
    - local:  For local development (uses local horde-components)

CI INIT - Generate Configuration Files:
    Generates bootstrap scripts and GitHub Actions workflows from templates.

    # Generate CI files (auto-detect mode)
    horde-components ci init

    # Generate for GitHub Actions (default)
    horde-components ci init --ci-mode=github

    # Generate for local development
    horde-components ci init --ci-mode=local

    # Force overwrite existing files
    horde-components ci init --force

    # Preview without writing
    horde-components ci init --dry-run

    Generated files:
    - bin/ci-bootstrap.sh           Bootstrap script
    - .github/workflows/ci.yml      GitHub Actions workflow (github mode only)

CI CHECK - Validate Existing Files:
    Check if generated CI files are up to date with current templates.

    # Check CI files
    horde-components ci check

    This command:
    - Verifies files exist
    - Checks template versions
    - Warns if outdated

CI SETUP - Prepare Environment:
    Sets up the CI environment for testing:
    1. Validates configuration
    2. Reads component metadata (.horde.yml)
    3. Installs PHP versions (8.2, 8.3, 8.4, 8.5 via ondrej PPA)
    4. Installs required PHP extensions
    5. Copies component to test lanes (8 directories)
    6. Runs composer install per lane with stability control

    # Setup for local testing
    horde-components ci setup --ci-mode=local

    # Setup with custom work directory
    horde-components ci setup --work-dir=/tmp/my-ci

    # GitHub Actions mode (called by bootstrap script)
    horde-components ci setup --ci-mode=github --component=Db

    Test lanes created:
    - php8.2-dev     (minimum-stability: dev)
    - php8.2-stable  (minimum-stability: component\'s own stability)
    - php8.3-dev
    - php8.3-stable
    - php8.4-dev
    - php8.4-stable
    - php8.5-dev
    - php8.5-stable

    Note: Only PHP versions >= component\'s minimum are tested.

CI RUN - Execute Tests:
    (Phase 2 - not yet implemented)
    Will execute tests across all prepared lanes:
    - horde-components qc linter
    - PHPUnit (version depends on PHP version)
    - PHPStan (level from .horde.yml)
    - php-cs-fixer (once on PHP 8.4)

TYPICAL WORKFLOW:
    # 1. Generate CI files for your component
    cd ~/git/horde/Http
    horde-components ci init

    # 2. Commit the generated files
    git add bin/ci-bootstrap.sh .github/workflows/ci.yml
    git commit -m "feat(ci): add CI configuration"

    # 3. Test locally (requires Ubuntu 24.04)
    export LOCAL_COMPONENTS_PATH=~/components
    export LOCAL_COMPONENT_PATH=$(pwd)
    ./bin/ci-bootstrap.sh

    # 4. Push to GitHub - CI runs automatically on push/PR

ENVIRONMENT VARIABLES:
    GitHub Actions mode (auto-detected):
    - GITHUB_ACTIONS        Must be set (GitHub sets this)
    - GITHUB_TOKEN          Required for API access
    - GITHUB_REPOSITORY     Component repository (org/name)
    - GITHUB_REF            Branch reference
    - COMPONENTS_PHAR_URL   URL to download horde-components.phar

    Local mode (must set manually):
    - LOCAL_COMPONENTS_PATH Path to horde-components checkout
    - LOCAL_COMPONENT_PATH  Path to component being tested
    - CI_WORK_DIR          Working directory (default: /tmp/horde-ci)

REQUIREMENTS:
    Local mode:
    - Ubuntu 24.04 (or Debian-based with apt-get)
    - sudo access (for PHP installation via apt)
    - Composer installed globally
    - Git

    GitHub Actions:
    - ubuntu-24.04 runner
    - PHP 8.4 for bootstrap (via shivammathur/setup-php)

COMPONENT TYPES:
    Phase 1 supports:
    - library      Standard PHP library (fully supported)

    Not yet implemented:
    - horde-library    (Phase 6)
    - application      (Phase 6)
    - bundle           (Phase 6)

TEMPLATE VERSIONING:
    Generated files include version metadata:
    # Template version: 1.0.0

    Use "ci check" to detect outdated files.
    Regenerate with "ci init --force" after template updates.

TROUBLESHOOTING:
    Problem: "sudo access required"
    Solution: Ensure you can run "sudo apt-get" without password, or configure sudoers

    Problem: "composer not found"
    Solution: Install composer globally: https://getcomposer.org/download/

    Problem: "Template not found"
    Solution: Ensure you\'re using horde-components from the correct path

    Problem: "Component type \'X\' not yet implemented"
    Solution: Only \'library\' type is supported in Phase 1

    Problem: Generated files outdated
    Solution: Run "horde-components ci check" then "ci init --force"

EXAMPLES:
    # Initialize CI for current component
    cd ~/git/horde/Http
    horde-components ci init

    # Check if files are current
    horde-components ci check

    # Test setup locally
    export LOCAL_COMPONENTS_PATH=~/components
    export LOCAL_COMPONENT_PATH=$(pwd)
    horde-components ci setup --ci-mode=local

    # See what would be generated
    horde-components ci init --dry-run

MORE INFO:
    See ~/horde-development/components-ci-*.md for:
    - Implementation plan
    - Architecture decisions
    - Phase completion status
    - Troubleshooting guides';
    }

    /**
     * Determine if this module should act. Run all required actions if it has
     * been instructed to do so.
     *
     * @param array $options CLI options
     * @param array $arguments CLI arguments
     * @param Component|null $component The selected component (if any)
     *
     * @return bool True if the module performed some action.
     */
    public function handle(array $options, array $arguments, ?Component $component = null): bool
    {
        if (!isset($arguments[0]) || $arguments[0] !== 'ci') {
            return false;
        }

        $subcommand = $arguments[1] ?? '';

        if (empty($subcommand)) {
            $this->showHelp();
            return true;
        }

        // Get output handler
        $output = $this->dependencies->get(Output::class);

        try {
            switch ($subcommand) {
                case 'init':
                    return $this->handleInit($options, $output);

                case 'check':
                    return $this->handleCheck($options, $output);

                case 'setup':
                    return $this->handleSetup($options, $output);

                case 'run':
                    return $this->handleRun($options, $output);

                default:
                    $output->error("Unknown subcommand: {$subcommand}");
                    $output->info('Valid subcommands: init, check, setup, run');
                    return false;
            }
        } catch (Exception $e) {
            $output->error('CI command failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Show general help for ci command.
     */
    private function showHelp(): void
    {
        $output = $this->dependencies->get(Output::class);
        $output->bold('Horde Components CI Management');
        $output->plain('');
        $output->plain('Usage: horde-components ci <subcommand> [OPTIONS]');
        $output->plain('');
        $output->plain('Subcommands:');
        $output->plain('  init       Generate CI configuration files');
        $output->plain('  check      Check if CI files are up to date');
        $output->plain('  setup      Setup CI environment');
        $output->plain('  run        Run CI tests (Phase 2 - not yet implemented)');
        $output->plain('');
        $output->plain('For detailed help: horde-components help ci');
    }

    /**
     * Handle ci init subcommand.
     *
     * @param array $options CLI options
     * @param Output $output Output handler
     * @return bool True if successful
     */
    private function handleInit(array $options, Output $output): bool
    {
        $componentPath = $options['local-path'] ?? getcwd();
        $mode = $options['ci-mode'] ?? 'github';
        $force = isset($options['force']) && $options['force'];
        $dryRun = isset($options['dry-run']) && $options['dry-run'];

        $initCommand = new InitCommand($output);
        return $initCommand->execute($componentPath, $mode, $force, $dryRun);
    }

    /**
     * Handle ci check subcommand.
     *
     * @param array $options CLI options
     * @param Output $output Output handler
     * @return bool True if files are up to date
     */
    private function handleCheck(array $options, Output $output): bool
    {
        $componentPath = $options['local-path'] ?? getcwd();

        $initCommand = new InitCommand($output);
        return $initCommand->check($componentPath);
    }

    /**
     * Handle ci setup subcommand.
     *
     * @param array $options CLI options
     * @param Output $output Output handler
     * @return bool True if successful
     */
    private function handleSetup(array $options, Output $output): bool
    {
        // Build configuration
        $configArray = EnvironmentDetector::buildConfig(
            $options['ci_mode'] ?? null,
            $options['local_path'] ?? null
        );

        // Override with CLI options
        if (isset($options['work_dir'])) {
            $configArray['work_dir'] = $options['work_dir'];
        }
        if (isset($options['component'])) {
            $configArray['component_name'] = $options['component'];
        }

        $config = new CiConfig($configArray);

        // Create tools cache
        $toolsDir = $config->workDir . '/tools';
        $toolCache = new ToolCache($toolsDir, $output);

        // Create setup command
        $setupCommand = new SetupCommand(
            $output,
            new PhpInstaller($output),
            new ExtensionInstaller($output),
            new LaneCopier($output),
            new ComposerInstaller($output),
            $toolCache,
            new LaneScriptGenerator($output)
        );

        return $setupCommand->execute($config);
    }

    /**
     * Handle ci run subcommand.
     *
     * @param array $options CLI options
     * @param Output $output Output handler
     * @return bool True if successful
     */
    private function handleRun(array $options, Output $output): bool
    {
        // Get work directory (Horde_Argv converts dashes to underscores)
        $workDir = $options['work_dir'] ?? '/tmp/horde-ci';

        if (!is_dir($workDir)) {
            $output->fail("Work directory does not exist: {$workDir}");
            $output->info("Run 'horde-components ci setup' first to prepare test lanes.");
            return false;
        }

        // Determine horde-components path
        // Check if we're running from a phar
        if (strlen(\Phar::running()) > 0) {
            // We're inside a phar - use the phar path
            $componentsPath = \Phar::running(false);
        } else {
            // Normal file system - use relative path to bin/horde-components
            $componentsPath = realpath(__DIR__ . '/../../bin/horde-components');
            if ($componentsPath === false) {
                $output->fail("Could not locate horde-components binary");
                return false;
            }
        }

        // Create RunCommand with dependencies
        $collector = new ResultCollector($output);
        $runCommand = new RunCommand($output, $collector, $componentsPath, $workDir);

        try {
            $exitCode = $runCommand->execute($workDir);
            return $exitCode === 0;
        } catch (Exception $e) {
            $output->fail("CI run failed: " . $e->getMessage());
            return false;
        }
    }
}
