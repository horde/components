<?php
/**
 * Horde\Components\Module\Init:: initializes component metadata.
 *
 * PHP version 7
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <lang@b1-systems.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

namespace Horde\Components\Module;

use Horde\Components\Config as AppConfig;
use Horde\Components\Exception;
use Horde\Components\Output;
use Horde\Components\Runner\ConfigHandler;
use Horde\Components\Task\Input;

/**
 * Horde\Components\Module\Config:: initializes or modifies configuration.
 *
 * Copyright 2018-2021 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <lang@b1-systems.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Config extends Base
{
    public function getOptionGroupTitle(): string
    {
        return 'Config';
    }

    public function getOptionGroupDescription(): string
    {
        return 'This module initializes or modifies horde-components\' configuration';
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
        return 'config';
    }

    /**
     * Get the usage description for this module.
     *
     * @return string The description.
     */
    public function getUsage(): string
    {
        return 'Initialize metadata and dirs';
    }

    /**
     * Return the action arguments supported by this module.
     *
     * @return array A list of supported action arguments.
     */
    public function getActions(): array
    {
        return ['config'];
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
        return 'TODO';
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
     * @return boolean True if the module performed some action.
     */
    public function handle(AppConfig $config)
    {
        $options = $config->getOptions();
        $arguments = $config->getArguments();

        if (!empty($arguments[0]) && $arguments[0] == 'config') {
            $input = new Input($config);
            $this->_dependencies->get(ConfigHandler::class)->handle($input);
            return true;
        }
        return false;
    }
}
