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
use Horde\Components\Config;
use Horde\Components\Exception;
use Horde\Components\Output;

/**
 * Horde\Components\Module\Init:: initializes component metadata.
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
class Init extends Base
{
    public function getOptionGroupTitle()
    {
        return 'Init';
    }

    public function getOptionGroupDescription()
    {
        return 'This module initializes .horde.yml, doc/changelog.yml and package.xml (and doc/CHANGES for apps).';
    }

    public function getOptionGroupOptions()
    {
        return array(
            new \Horde_Argv_Option(
                '',
                '--author',
                array(
                    'action' => 'store',
                    'help'   => 'First author\'s name'
                )
            ),
            new \Horde_Argv_Option(
                '',
                '--email',
                array(
                    'action' => 'store',
                    'help'   => 'Author\'s email'
                )
            ),
            new \Horde_Argv_Option(
                '',
                '--license',
                array(
                    'action' => 'store',
                    'help'   => 'License'
                )
            )
        );
    }

    /**
     * Get the usage title for this module.
     *
     * @return string The title.
     */
    public function getTitle()
    {
        return 'init';
    }

    /**
     * Get the usage description for this module.
     *
     * @return string The description.
     */
    public function getUsage()
    {
        return 'Initialize metadata and dirs';
    }

    /**
     * Return the action arguments supported by this module.
     *
     * @return array A list of supported action arguments.
     */
    public function getActions()
    {
        return array('init');
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
        return 'This module creates doc/changelog.yml, package.xml, and
doc/CHANGES. It will also create the .horde.yml metadata.

Move into the directory of the component you wish to record a change for
and run

  horde-components init application --author "Some Guy" --email "foo@bar.com"
or
  horde-components init library --author "Some Guy" --email "foo@bar.com"';

    }

    /**
     * Return the options that should be explained in the context help.
     *
     * @return array A list of option help texts.
     */
    public function getContextOptionHelp()
    {
        return array(
            '--author' => 'The primary author\'s name',
            '--email' => 'Your Email Address'
        );
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

        if (!empty($arguments[0]) && $arguments[0] == 'init') {

            switch ($arguments[1]) {
                case 'application':
                    $this->_dependencies->getRunnerInit()->run();
                    return true;

                case 'library':
                    $this->_dependencies->getRunnerInit()->run();
                    return true;
                default;
                    return false;
            }
        }
    }
}
