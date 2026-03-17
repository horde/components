<?php

/**
 * Horde\Components\Runner\PackageDependencies:: Handle package dependency management operations
 *
 * PHP version 8.2+
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.fsf.org/copyleft/lgpl.html LGPL
 */

declare(strict_types=1);

namespace Horde\Components\Runner;

use Horde\Components\Component\ComponentDirectory;
use Horde\Components\Component\Factory as ComponentFactory;
use Horde\Components\Exception;
use Horde\Components\Helper\Composer;
use Horde\Components\Helper\Git;
use Horde\Components\Output;
use Horde\Components\RuntimeContext\CurrentWorkingDirectory;
use Horde\Components\Task\Composer\UpdateDependenciesTask;
use Horde\Components\Task\Context;
use Horde\Components\Wrapper\HordeYml as WrapperHordeYml;
use Horde\HordeYmlFile\HordeYmlFile;
use stdClass;

/**
 * Horde\Components\Runner\PackageDependencies:: Handle package dependency management operations
 *
 * Copyright 2026-2026 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.fsf.org/copyleft/lgpl.html.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.fsf.org/copyleft/lgpl.html LGPL
 */
class PackageDependencies
{
    /**
     * Constructor.
     *
     * @param array $arguments CLI arguments for routing
     * @param array $options CLI options
     * @param Output $output Output handler
     * @param ComponentFactory $componentFactory Component factory
     * @param Git $gitHelper Git operations helper
     * @param Composer $composerHelper Composer operations helper
     */
    public function __construct(
        private readonly array $arguments,
        private readonly array $options,
        private readonly Output $output,
        private readonly ComponentFactory $componentFactory,
        private readonly Git $gitHelper,
        private readonly Composer $composerHelper,
    ) {}

    /**
     * Run the dependencies command.
     */
    public function run(): void
    {
        // Extract action: arguments = ['package', 'dependencies', 'list|update', ...]
        $action = $this->arguments[2] ?? null;

        match ($action) {
            'list' => $this->handleList(),
            'update' => $this->handleUpdate(),
            default => $this->showDependenciesHelp(),
        };
    }

    /**
     * Show help text for dependencies subcommand.
     */
    private function showDependenciesHelp(): void
    {
        $this->output->plain('Usage: horde-components package dependencies <action>');
        $this->output->plain('');
        $this->output->plain('Actions:');
        $this->output->plain('  list    List all dependencies by category');
        $this->output->plain('  update  Update Horde dependency versions from FRAMEWORK_6_0');
        $this->output->plain('');
        $this->output->plain('Examples:');
        $this->output->plain('  horde-components package dependencies list');
        $this->output->plain('  horde-components package dependencies update --pretend');
    }

    /**
     * Handle the 'list' action - display all dependencies.
     */
    private function handleList(): void
    {
        // Respect --pretend flag (no-op for list, but consistent with framework)
        if (!empty($this->options['pretend'])) {
            $this->output->info('[PRETEND MODE] Listing dependencies (read-only operation)');
        }

        try {
            $componentPath = getcwd();
            if ($componentPath === false) {
                $this->output->error('Could not determine current working directory');
                return;
            }

            $hordeYml = new HordeYmlFile($componentPath . '/.horde.yml');

            $this->output->bold('Package: ' . $hordeYml->getName());
            $this->output->plain('Version: ' . $hordeYml->getReleaseVersion());
            $this->output->plain('');

            // Get dependencies from .horde.yml via wrapper for array access
            $wrapper = new WrapperHordeYml($componentPath);

            // Process each dependency category
            $this->displayDependencyCategory('Required', $wrapper['dependencies']['required'] ?? []);
            $this->displayDependencyCategory('Optional', $wrapper['dependencies']['optional'] ?? []);
            $this->displayDependencyCategory('Development', $wrapper['dependencies']['dev'] ?? []);
        } catch (\Exception $e) {
            $this->output->error('Error listing dependencies: ' . $e->getMessage());
        }
    }

    /**
     * Display a dependency category.
     *
     * @param string $title Category title
     * @param array $deps Dependency array
     */
    private function displayDependencyCategory(string $title, array $deps): void
    {
        if (empty($deps)) {
            return;
        }

        $this->output->bold("\n{$title} Dependencies:");

        // Group by type: php, ext, composer
        $grouped = $this->groupDependencies($deps);

        if (!empty($grouped['platform'])) {
            $this->output->plain('  Platform:');
            foreach ($grouped['platform'] as $name => $version) {
                $this->output->plain("    {$name}: {$version}");
            }
        }

        if (!empty($grouped['composer'])) {
            $this->output->plain('  Composer:');
            foreach ($grouped['composer'] as $name => $version) {
                $this->output->plain("    {$name}: {$version}");
            }
        }
    }

    /**
     * Group dependencies by platform vs composer.
     *
     * @param array $deps Raw dependency array from .horde.yml
     * @return array Grouped dependencies
     */
    private function groupDependencies(array $deps): array
    {
        $result = ['platform' => [], 'composer' => []];

        foreach ($deps as $type => $packages) {
            if ($type === 'php') {
                $result['platform']['php'] = $packages;
            } elseif ($type === 'ext') {
                foreach ($packages as $ext => $version) {
                    $result['platform']['ext-' . $ext] = $version;
                }
            } elseif ($type === 'composer') {
                $result['composer'] = $packages;
            }
        }

        return $result;
    }

    /**
     * Handle the 'update' action - update Horde dependency versions.
     */
    private function handleUpdate(): void
    {
        $pretend = !empty($this->options['pretend']);

        try {
            $componentPath = getcwd();
            if ($componentPath === false) {
                $this->output->error('Could not determine current working directory');
                return;
            }

            // Create minimal component wrapper for Context
            $component = $this->createMinimalComponent($componentPath);

            // Create context
            $context = new Context($component, $this->options);

            // Create and execute task
            $task = new UpdateDependenciesTask(
                $this->output,
                $this->gitHelper,
                $this->composerHelper,
                $pretend
            );

            $result = $task->run($context);

            // Handle result
            if ($result->isFailure()) {
                $this->output->error($result->message);
                return;
            }

            // Get results from metadata
            $changes = $result->metadata['changes'] ?? [];
            $suggestions = $result->metadata['suggestions'] ?? [];
            $todos = $result->metadata['todos'] ?? [];

            // Check if we have anything to display
            if (empty($changes) && empty($suggestions) && empty($todos)) {
                $this->output->ok('All dependency versions are up to date.');
                return;
            }

            // Display changes summary
            if (!empty($changes)) {
                $this->displayChangeSummary($changes, $pretend, 'Dependency Updates');
            }

            // Display suggestions (major version upgrades)
            if (!empty($suggestions)) {
                $this->displaySuggestions($suggestions);
            }

            // Display TODOs (compound versions)
            if (!empty($todos)) {
                $this->displayTodos($todos);
            }

            if (!$pretend && !empty($changes)) {
                $this->output->ok('Dependencies updated successfully.');
            } elseif ($pretend && !empty($changes)) {
                $this->output->info('No changes made (pretend mode).');
            }
        } catch (\Exception $e) {
            $this->output->error('Error updating dependencies: ' . $e->getMessage());
        }
    }

    /**
     * Create minimal Component wrapper for Context.
     *
     * @param string $componentPath Component directory path
     * @return \Horde\Components\Component
     */
    private function createMinimalComponent(string $componentPath): \Horde\Components\Component
    {
        return new class ($componentPath) implements \Horde\Components\Component {
            public function __construct(private string $path) {}

            public function getComponentDirectory(): string
            {
                return $this->path;
            }

            // Required interface methods - minimal implementations
            public function getName(): string
            {
                return '';
            }

            public function getSummary(): string
            {
                return '';
            }

            public function getDescription(): string
            {
                return '';
            }

            public function getVersion(): string
            {
                return '';
            }

            public function getPreviousVersion(): string
            {
                return '';
            }

            public function getDate(): string
            {
                return '';
            }

            public function getChannel(): string
            {
                return '';
            }

            public function getDependencies(): array
            {
                return [];
            }

            public function getState($key = 'release'): string
            {
                return '';
            }

            public function getLeads()
            {
                return [];
            }

            public function getLicense()
            {
                return '';
            }

            public function getLicenseLocation(): string
            {
                return '';
            }

            public function hasLocalPackageXml(): bool
            {
                return false;
            }

            public function getChangelogLink(): string
            {
                return '';
            }

            public function getReleaseNotesPath(): string|bool
            {
                return false;
            }

            public function getDependencyList()
            {
                return null;
            }

            public function getData(): stdClass
            {
                return new stdClass();
            }

            public function getDocumentOrigin(): ?string
            {
                return null;
            }

            public function updatePackage($action, $options): string
            {
                return '';
            }

            public function changed($log, $options): array
            {
                return [];
            }

            public function timestamp($options): string
            {
                return '';
            }

            public function nextVersion(
                $version,
                $initial_note,
                $stability_api = null,
                $stability_release = null,
                $options = []
            ) {}

            public function currentSentinel($changes, $app, $options): array
            {
                return [];
            }

            public function tag(string $tag, string $message, \Horde\Components\Helper\Commit $commit): string
            {
                return '';
            }

            public function placeArchive(string $destination, $options = []): array
            {
                return [];
            }

            public function repositoryRoot(\Horde\Components\Helper\Root $helper): string
            {
                return '';
            }

            public function installChannel(\Horde\Components\Pear\Environment $env, $options = []): void {}

            public function install(
                \Horde\Components\Pear\Environment $env,
                $options = [],
                $reason = ''
            ): void {}
        };
    }

    /**
     * Display summary of dependency changes.
     *
     * @param array $changes Array of changes
     * @param bool $pretend Whether in pretend mode
     * @param string $title Section title
     */
    private function displayChangeSummary(array $changes, bool $pretend, string $title = 'Dependency Updates'): void
    {
        $mode = $pretend ? ' [PRETEND MODE]' : '';
        $this->output->bold("{$title}{$mode}:");

        foreach ($changes as $change) {
            $this->output->plain(
                "  {$change['package']}: {$change['current']} → {$change['new']} "
                . "(from version {$change['version']})"
            );
        }

        $this->output->plain('');
    }

    /**
     * Display suggested dependency updates (e.g., major version upgrades).
     *
     * @param array $suggestions Array of suggestions
     */
    private function displaySuggestions(array $suggestions): void
    {
        $this->output->bold("Suggested Updates (not applied automatically):");

        foreach ($suggestions as $suggestion) {
            $reason = $suggestion['reason'] ?? '';
            $this->output->warn(
                "  {$suggestion['package']}: {$suggestion['current']} → {$suggestion['new']} "
                . "(from version {$suggestion['version']}) - {$reason}"
            );
        }

        $this->output->plain('');
    }

    /**
     * Display TODO items (e.g., compound version constraints).
     *
     * @param array $todos Array of TODO items
     */
    private function displayTodos(array $todos): void
    {
        $this->output->bold("Manual Review Required:");

        foreach ($todos as $todo) {
            $reason = $todo['reason'] ?? '';
            $this->output->info(
                "  {$todo['package']}: {$todo['current']} - {$reason}"
            );
        }

        $this->output->plain('');
    }

    /**
     * Load the current component from working directory.
     *
     * @return \Horde\Components\Component
     * @throws Exception
     */
    private function loadCurrentComponent(): \Horde\Components\Component
    {
        $componentDirectory = new ComponentDirectory(new CurrentWorkingDirectory());
        return $this->componentFactory->createSource($componentDirectory);
    }
}
