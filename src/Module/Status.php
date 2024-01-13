<?php
/**
 * Components_Module_Change:: records a change log entry.
 *
 * PHP Version 7
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

namespace Horde\Components\Module;

use Horde\Components\Config;
use Horde\Components\Dependencies;
use Horde\Components\Component\ComponentDirectory;
use Horde\Components\Dependencies\GitCheckoutDirectoryFactory;
use Horde\Components\RuntimeContext\CurrentWorkingDirectory;
use Horde\Components\Runner\Status as RunnerStatus;

/**
 * Components_Module_Change:: records a change log entry.
 *
 * Copyright 2011-2024 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Status extends Base
{

    public function getOptionGroupTitle(): string
    {
        return 'status';
    }

    public function getOptionGroupDescription(): string
    {
        return 'This module prints out status';
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
        return 'status';
    }

    /**
     * Get the usage description for this module.
     *
     * @return string The description.
     */
    public function getUsage(): string
    {
        return 'Show status';
    }

    /**
     * Return the action arguments supported by this module.
     *
     * @return array A list of supported action arguments.
     */
    public function getActions(): array
    {
        return ['changed'];
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
        return 'horde-components status';
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

        if (!empty($options['status']) ||
            (isset($arguments[0]) && $arguments[0] == 'status')) {
            $componentDirectory = new ComponentDirectory($options['working_dir'] ?? new CurrentWorkingDirectory);
            $component = $this->dependencies
            ->getComponentFactory()
            ->createSource($componentDirectory);
            $config->setComponent($component);
            $this->dependencies->get(RunnerStatus::class)->run($config);
            return true;
        }
        return false;
    }
}
