<?php

/**
 * Components_Moduledependencies:: generates a dependency listing for the
 * specified package.
 *
 * PHP version 8.2+
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

declare(strict_types=1);

namespace Horde\Components\Module;

use Horde\Argv\Option;
use Horde\Components\Component;
use Horde\Components\Component\ComponentDirectory;
use Horde\Components\RuntimeContext\CurrentWorkingDirectory;

/**
 * Components_Moduledependencies:: generates a dependency listing for the
 * specified package.
 *
 * Copyright 2010-2026 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Dependencies extends Base
{
    /**
     * Return the title for the option group representing this module.
     *
     * @return string The group title.
     */
    public function getOptionGroupTitle(): string
    {
        return 'Package Dependencies';
    }

    /**
     * Return the description for the option group representing this module.
     *
     * @return string The group description.
     */
    public function getOptionGroupDescription(): string
    {
        return 'This module generates a list of dependencies for the specified package';
    }

    /**
     * Return the options for this module.
     *
     * @return array The group options.
     */
    public function getOptionGroupOptions(): array
    {
        return [
            new Option(
                '-L',
                '--list-deps',
                ['action' => 'store_true', 'help' => 'generate a dependency listing']
            ),
            new Option(
                '--short',
                ['action' => 'store_true', 'help' => 'Generate a brief dependency list.']
            ),
            new Option(
                '--alldeps',
                ['action' => 'store_true', 'help' => 'Include all optional dependencies into the dependency list.']
            ),
            new Option(
                '--no-tree',
                ['action' => 'store_true', 'help' => 'Just print the dependencies of this package (YAML format) rather than generating a complete tree.']
            ),
            new Option(
                '--export-yaml',
                ['action' => 'store', 'help' => 'Export dependency graph to YAML file (e.g., --export-yaml=deps.yml)']
            ),
            new Option(
                '--detect-plugins',
                ['action' => 'store_true', 'help' => 'Detect Composer plugins in the dependency tree.']
            ),
            new Option(
                '--allow-network-requests',
                ['action' => 'store_true', 'help' => 'Allow network requests to resolve dependencies from remote sources (Packagist, etc.).']
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
        return 'deps';
    }

    /**
     * Get the usage description for this module.
     *
     * @return string The description.
     */
    public function getUsage(): string
    {
        return 'Generate a dependency list.';
    }

    /**
     * Return the action arguments supported by this module.
     *
     * @return array A list of supported action arguments.
     */
    public function getActions(): array
    {
        return ['deps'];
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
        return 'This module generates a dependency tree for a component.';
    }

    /**
     * Return the options that should be explained in the context help.
     *
     * @return array A list of option help texts.
     */
    public function getContextOptionHelp(): array
    {
        return [
            '--short' => '',
            '--alldeps' => '',
            '--no-tree' => '',
            '--export-yaml' => 'Export the dependency graph to a YAML file for analysis.',
            '--detect-plugins' => 'Automatically detect Composer plugins in the dependency tree.',
            '--allow-network-requests' => 'Allow network requests to resolve dependencies from remote sources (Packagist, PEAR channels, etc.). By default, only local git checkout is used.',
            '--allow-remote' => 'Legacy option: use --allow-network-requests instead.',
        ];
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
        if (!empty($options['list_deps'])
            || (isset($arguments[0]) && $arguments[0] == 'deps')) {

            // Resolve component from working directory if not provided
            if ($component === null) {
                $componentDirectory = new ComponentDirectory(new CurrentWorkingDirectory());
                $component = $this->dependencies
                    ->getComponentFactory()
                    ->createSource($componentDirectory);
            }

            // Create runner with component
            $runner = $this->dependencies->createRunnerDependencies($component, $options);
            $runner->run();

            return true;
        }
        return false;
    }
}
