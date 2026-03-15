<?php

/**
 * Horde\Components\Module\Package:: Frontend to check various aspects of the package under test
 *
 * PHP version 8.2+
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.fsf.org/copyleft/lgpl.html LGPL
 */

declare(strict_types=1);

namespace Horde\Components\Module;

use Horde\Components\Component;
use Horde\Components\Helper\Composer;
use Horde\Components\Helper\Git;
use Horde\Components\Output;
use Horde\Components\Runner\PackageDependencies;
use Horde\Cli\Cli;

/**
 * Horde\Components\Module\Package:: Frontend to check various aspects of the package under test
 *
 * Copyright 2023-2026 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.fsf.org/copyleft/lgpl.html.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.fsf.org/copyleft/lgpl.html LGPL
 */
class Package extends Base
{
    /**
     * Return the title for the option group representing this module.
     *
     * @return string The group title.
     */
    public function getOptionGroupTitle(): string
    {
        return 'Package Info';
    }

    /**
     * Return the description for the option group representing this module.
     *
     * @return string The group description.
     */
    public function getOptionGroupDescription(): string
    {
        return 'Check package info';
    }

    /**
     * Return the options for this module.
     *
     * @return array The group options.
     */
    public function getOptionGroupOptions(): array
    {
        return [];
    }

    /**
     * Get the usage title for this module.
     *
     * @return string The title.
     */
    public function getTitle(): string
    {
        return 'package';
    }

    /**
     * Get the usage description for this module.
     *
     * @return string The description.
     */
    public function getUsage(): string
    {
        return 'Check Package Info';
    }

    /**
     * Get a short one-line description for command listings.
     *
     * @return string The short description.
     */
    public function getShortDescription(): string
    {
        return 'Check package information';
    }

    /**
     * Return the action arguments supported by this module.
     *
     * @return array A list of supported action arguments.
     */
    public function getActions(): array
    {
        return ['package'];
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
        return $this->getPackageHelp();
    }

    /**
     * Get comprehensive help text for package command.
     *
     * @return string The help text.
     */
    private function getPackageHelp(): string
    {
        return <<<'EOT'
USAGE:
    horde-components package [subcommand]

SUBCOMMANDS:
    status                  Show package information
    dependencies list       List all dependencies by type
    dependencies update     Update Horde dependency versions from FRAMEWORK_6_0

EXAMPLES:
    # Show helpful recommendations (no subcommand)
    horde-components package

    # Show package info
    horde-components package status

    # List all dependencies
    horde-components package dependencies list

    # Update dependency versions (pretend mode)
    horde-components package dependencies update --pretend
    horde-components package dependencies update -P

    # Update dependency versions
    horde-components package dependencies update

DEPENDENCY UPDATE BEHAVIOR:
    - Only updates horde/* composer dependencies
    - Reads versions from FRAMEWORK_6_0 branches in git checkout
    - Uses checkout.dir config or ~/git by default
    - Skips components not found or not on FRAMEWORK_6_0
    - Updates .horde.yml and regenerates composer.json
    - Uses constraint pattern: version 3.0.5 → constraint ^3
    - Supports global --pretend|-P flag for preview

PRETEND MODE:
    - Use --pretend or -P to preview changes without modifying files
    - Works with both 'list' (no-op) and 'update' (preview) commands
    - Consistent with other horde-components commands

EOT;
    }

    /**
     * Return the options that should be explained in the context help.
     *
     * @return array A list of option help texts.
     */
    public function getContextOptionHelp(): array
    {
        return [];
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
        if (!in_array($arguments[0] ?? '', $this->getActions())) {
            return false;
        }

        // Extract subcommand
        $subcommand = $arguments[1] ?? null;

        // Dispatch based on subcommand
        match ($subcommand) {
            'status' => $this->handleStatus($component),
            'dependencies' => $this->handleDependencies($options, $arguments),
            null => $this->showRecommendations(),
            default => $this->showUnknownSubcommand($subcommand),
        };

        return true;
    }

    /**
     * Show package status information.
     *
     * @param Component|null $component The selected component.
     */
    private function handleStatus(?Component $component): void
    {
        $output = $this->dependencies->get(Output::class);

        if ($component === null) {
            $output->error('No component found in current directory.');
            return;
        }

        try {
            $output->bold('Package: ' . $component->getName());
            $output->plain('Version: ' . $component->getVersion());

            $summary = $component->getSummary();
            if ($summary) {
                $output->plain('Description: ' . $summary);
            }

            $output->plain('');
        } catch (\Exception $e) {
            $output->error('Error reading package information: ' . $e->getMessage());
        }
    }

    /**
     * Handle dependencies subcommands.
     *
     * @param array $options CLI options
     * @param array $arguments CLI arguments
     */
    private function handleDependencies(array $options, array $arguments): void
    {
        $output = $this->dependencies->get(Output::class);
        $componentFactory = $this->dependencies->getComponentFactory();
        $gitHelper = $this->dependencies->get(Git::class);
        $composerHelper = $this->dependencies->get(Composer::class);

        $runner = new PackageDependencies(
            $arguments,
            $options,
            $output,
            $componentFactory,
            $gitHelper,
            $composerHelper
        );
        $runner->run();
    }

    /**
     * Show helpful recommendations when no subcommand is provided.
     */
    private function showRecommendations(): void
    {
        $output = $this->dependencies->get(Output::class);

        $output->plain('Usage: horde-components package <subcommand>');
        $output->plain('');
        $output->plain('Available subcommands:');
        $output->plain('  status             Show package information');
        $output->plain('  dependencies list  List all dependencies');
        $output->plain('  dependencies update Update Horde dependency versions');
        $output->plain('');
        $output->plain('Examples:');
        $output->plain('  horde-components package status');
        $output->plain('  horde-components package dependencies list');
        $output->plain('  horde-components package dependencies update --pretend');
        $output->plain('');
        $output->plain('For more help: horde-components help package');
    }

    /**
     * Handle unknown subcommand.
     *
     * @param string $subcommand The unknown subcommand.
     */
    private function showUnknownSubcommand(string $subcommand): void
    {
        $output = $this->dependencies->get(Output::class);
        $output->error("Unknown subcommand: {$subcommand}");
        $output->plain('');
        $this->showRecommendations();
    }
}
