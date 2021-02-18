<?php
/**
 * The Components:: class is the entry point for the various component actions
 * provided by the package.
 *
 * PHP Version 7
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
namespace Horde\Components;
use Horde\Components\Dependencies\Injector;
use Horde\Components\Config\Cli as ConfigCli;
use Horde\Components\Config\File as ConfigFile;
use Horde\Components\Component\Identify;

/**
 * The Components:: class is the entry point for the various component actions
 * provided by the package.
 *
 * Copyright 2010-2020 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Components
{

    const ERROR_NO_COMPONENT = 'You are neither in a component directory nor specified it as the first argument!';

    const ERROR_NO_ACTION = 'You did not specify an action!';

    const ERROR_NO_ACTION_OR_COMPONENT = '"%s" specifies neither an action nor a component directory!';

    /**
     * The main entry point for the application.
     *
     * @param array $parameters A list of named configuration parameters.
     * <pre>
     * 'cli'        - (array)  CLI configuration parameters.
     *   'parser'   - (array)  Parser configuration parameters.
     *     'class'  - (string) The class name of the parser to use.
     * </pre>
     */
    public static function main(array $parameters = array())
    {
        $dependencies = self::_prepareDependencies($parameters);
        $modular = self::_prepareModular($dependencies, $parameters);
        $parser = $modular->createParser();
        $dependencies->setParser($parser);
        $config = self::_prepareConfig($parser);
        $dependencies->initConfig($config);

        /**
         * Issue: Some commands do not require a component or need the 
         * component path to be empty/non-existing, i.e. git clone
         */
        try {
            self::_identifyComponent(
                $config, self::_getActionArguments($modular), $dependencies
            );
        } catch (Exception $e) {
            $parser->parserError($e->getMessage());
            return;
        }

        try {
            $ran = false;
            foreach (clone $modular->getModules() as $module) {
                $ran |= $modular->getProvider()->getModule($module)->handle($config);
            }
        } catch (Exception $e) {
            $dependencies->getOutput()->fail($e);
            return;
        }

        if (!$ran) {
            $parser->parserError(self::ERROR_NO_ACTION);
        }
    }

    protected static function _prepareModular(
        Dependencies $dependencies, array $parameters = array()
    ) {
        $modular = new \Horde_Cli_Modular(
            array(
                'parser' => array(
                    'class' => empty($parameters['parser']['class']) ? '\Horde_Argv_Parser' : $parameters['parser']['class'],
                    'usage' => '[options] [COMPONENT_PATH] [ACTION] [ARGUMENTS]

COMPONENT_PATH

Specifies the path to the component you want to work with. This argument is optional in case your current working directory is the base directory of a component and contains a package.xml file.

ACTION

Selects the action to perform. Most actions can also be selected with an option switch.

This is a list of available actions (use "help ACTION" to get additional information on the specified ACTION):

'
                ),
                'modules' => array(
                    'directory' => __DIR__ . '/Module',
                    'exclude' => 'Base'
                ),
                'provider' => array(
                    'prefix' => 'Horde\Components\Module\\',
                    'dependencies' => $dependencies
                ),
                'cli' => $dependencies->getInstance(\Horde_Cli::class),
            )
        );
        $dependencies->setModules($modular);
        return $modular;
    }

    /**
     * The main entry point for the application.
     *
     * @param array $parameters A list of named configuration parameters.
     *
     * @return Dependencies The dependency handler.
     */
    protected static function _prepareDependencies($parameters)
    {
        if (isset($parameters['dependencies'])
            && $parameters['dependencies'] instanceof Dependencies) {
            return $parameters['dependencies'];
        } else {
            return new Injector();
        }
    }

    protected static function _prepareConfig(\Horde_Argv_Parser $parser)
    {
        $config = new Configs();
        $config->addConfigurationType(
            new ConfigCli(
                $parser
            )
        );
        $config->unshiftConfigurationType(
            new ConfigFile(
                $config->getOption('config')
            )
        );
        return $config;
    }

    /**
     * Provide a list of available action arguments.
     *
     * @param Config $config The active configuration.
     *
     * @return void
     */
    protected static function _getActionArguments(\Horde_Cli_Modular $modular)
    {
        $actions = array();
        foreach ($modular->getModules() as $module) {
            $actions = array_merge(
                $actions,
                $modular->getProvider()->getModule($module)->getActions()
            );
        }
        return array('list' => $actions, 'missing_argument' => array('help'));
    }

    /**
     * Identify the selected component based on the command arguments.
     *
     * @param Config $config  The active configuration.
     * @param array             $actions The list of available actions.
     *
     * @return void
     */
    protected static function _identifyComponent(
        Config $config,
        $actions,
        Dependencies $dependencies
    ) {
        $identify = new Identify(
            $config, $actions, $dependencies
        );
        $identify->setComponentInConfiguration();
    }
}
