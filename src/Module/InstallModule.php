<?php
/**
 * InstallModule:: Setup a horde installation from a git checkout
 *
 * PHP Version 7
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

namespace Horde\Components\Module;

use Horde\Components\Config;
use Horde\Components\Dependencies;
use Horde\Components\Component\ComponentDirectory;
use Horde\Components\Runner\InstallRunner;
use Horde\Components\RuntimeContext\CurrentWorkingDirectory;

/**
 * InstallModule:: Setup a horde installation from a git checkout
 *
 * Copyright 2023-2024 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class InstallModule extends Base
{

    public function getOptionGroupTitle(): string
    {
        return 'install';
    }

    public function getOptionGroupDescription(): string
    {
        return 'Install a git checkout into a web tree';
    }

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
        return 'install';
    }

    /**
     * Get the usage description for this module.
     *
     * @return string The description.
     */
    public function getUsage(): string
    {
        return 'install';
    }

    /**
     * Return the action arguments supported by this module.
     *
     * @return array A list of supported action arguments.
     */
    public function getActions(): array
    {
        return ['install'];
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
        return 'horde-components install';
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
     * @param Config $config The configuration.
     *
     * @return bool True if the module performed some action.
     */
    public function handle(Config $config): bool
    {
        $options = $config->getOptions();
        $arguments = $config->getArguments();

        if (!empty($options['install']) ||
            (isset($arguments[0]) && $arguments[0] == 'install')) {
            $componentDirectory = new ComponentDirectory($options['working_dir'] ?? new CurrentWorkingDirectory);
            $component = $this->dependencies
            ->getComponentFactory()
            ->createSource($componentDirectory);
            $config->setComponent($component);
            $this->dependencies->get(InstallRunner::class)->run($config);
            return true;
        }
        return false;
    }
}
