<?php
/**
 * Copyright 2013-2020 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category  Horde
 * @copyright 2013-2020 Horde LLC
 * @license   http://www.horde.org/licenses/lgpl21 LGPL 2.1
 * @package   Components
 */
namespace Horde\Components\Module;
use Horde\Components\Config;

/**
 * Creates a config file for use with PHP Composer.
 *
 * @author    Michael Slusarz <slusarz@horde.org>
 * @category  Horde
 * @copyright 2013-2020 Horde LLC
 * @license   http://www.horde.org/licenses/lgpl21 LGPL 2.1
 * @package   Components
 */
class Composer extends Base
{
    /**
     */
    public function getOptionGroupTitle()
    {
        return 'PHP Composer configuration';
    }

    /**
     */
    public function getOptionGroupDescription()
    {
        return 'This module creates a config file for use with PHP Composer.';
    }

    /**
     */
    public function getOptionGroupOptions()
    {
        return array();
    }

    /**
     * Get the usage title for this module.
     *
     * @return string The title.
     */
    public function getTitle()
    {
        return 'composer';
    }

    /**
     * Get the usage description for this module.
     *
     * @return string The description.
     */
    public function getUsage()
    {
        return 'Create config file for PHP Composer.';
    }

    /**
     * Return the action arguments supported by this module.
     *
     * @return array A list of supported action arguments.
     */
    public function getActions()
    {
        return array('composer');
    }

    /**
     * Return the help text for the specified action.
     *
     * @param string $action The action.
     *
     * @return string The help text.
     */
    public function getHelp($action)
    {
        return 'Creates a composer.json config file to be used with PHP Composer. Usage:

  horde-components composer';
    }

    /**
     * Determine if this module should act. Run all required actions if it has
     * been instructed to do so.
     *
     * @param Config $config The configuration.
     *
     * @return boolean True if the module performed some action.
     */
    public function handle(Config $config)
    {
        $options = $config->getOptions();
        $arguments = $config->getArguments();
        if (!empty($options['composer'])
            || (isset($arguments[0]) && $arguments[0] == 'composer')) {
            $this->_dependencies->getRunnerComposer()->run();
            return true;
        }
    }
}
