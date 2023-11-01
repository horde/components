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

use Horde\Cli\Modular\ModularCli;
use Horde\Components\Component\Identify;
use Horde\Components\Config\Cli as ConfigCli;
use Horde\Components\Config\File as ConfigFile;
use Horde\Components\ConfigProvider\BuiltinConfigProvider;
use Horde\Components\ConfigProvider\EnvironmentConfigProvider;
use Horde\Components\ConfigProvider\PhpConfigFileProvider;
//use Horde\Components\Dependencies\Injector;
use Horde\Injector\TopLevel;
use Horde\Injector\Injector;
use Horde\EventDispatcher\EventDispatcher;
use Horde\EventDispatcher\SimpleListenerProvider;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventdispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface;
use Horde\Components\Cli\ModuleProvider;
use Horde\Cli\Cli;
use Horde\Cli\Modular\Modules;
use Horde\Cli\Modular\ParserProvider;
use Horde_Argv_Parser;

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
    final public const ERROR_NO_COMPONENT = 'You are neither in a component directory nor specified it as the first argument!';

    final public const ERROR_NO_ACTION = 'You did not specify an action!';

    final public const ERROR_NO_ACTION_OR_COMPONENT = '"%s" specifies neither an action nor a component directory!';

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
    public static function main(array $parameters = []): void
    {
        // Setup the event system
        $provider = new SimpleListenerProvider();
        $dispatcher = new EventDispatcher($provider);
        // Setup the DI system and feed the container - whatever needs the event system will get it
        $injector = new Dependencies\Injector(new TopLevel);
        $injector->setInstance(EventDispatcherInterface::class, $dispatcher);
        $injector->setInstance(ListenerProviderInterface::class, $provider);
        $injector->setInstance(ArgvWrapper::class, ArgvWrapper::fromGlobal());
        $app = new Components($injector, $parameters);
    }

    public function __construct(Injector $injector, $parameters)
    {
        /**
         * Early init
         * - Save the environment,
         * - Find the config file, if any
         * - Find out if we know the component by env, cwd or first argument
         */
        $environmentConfig = new EnvironmentConfigProvider(getenv());
        $injector->setInstance(EnvironmentConfigProvider::class, $environmentConfig);
        $injector->setInstance(BuiltinConfigProvider::class, new BuiltinConfigProvider(
            [
                'checkout.dir' => $environmentConfig->hasSetting('HOME') ? $environmentConfig->getSetting('HOME') . '/git/horde' : '/srv/git/horde',
                'repo.org' => 'horde',
                'scm.domain' => 'https://github.com',
                'scm.type' => 'github',
            ]
        ));
        $finder = $injector->get(ConfigFileFinder::class);
        $configFileLocation = $finder->find();
        $phpConfig = new PhpConfigFileProvider($configFileLocation);
        $injector->setInstance(PhpConfigFileProvider::class, $phpConfig);
        Dependencies\Injector::registerAppDependencies($injector);
        // Identify if we are in a component dir or have provided one with variable
        $modular = self::_prepareModular($injector, $parameters);
        // If we don't do this, help introspection is broken.
        $injector->setInstance(ModularCli::class, $modular);
        // This is handled in prepareModular
        $parser = $injector->getInstance(Horde_Argv_Parser::class);
        // TODO: Get rid of this "config"
        $config = self::_prepareConfig($parser);
        $injector->setInstance(Config::class, $config);

        /**
         * By this point the modular CLI is setup to cycle through "handle"
         */
        try {
            $ran = false;
            foreach (clone $modular->getModules() as $module) {
                $ran |= $module->handle($config);
               //                $ran |= $modular->getProvider()->getModule($module)->handle($config);
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
        Dependencies|Injector $injector,
        array $parameters = []
    ): ModularCli {
        // TODO: Externalize to avoid non-code in a code file and to remove indention
        $usage = '[options] [COMPONENT_PATH] [ACTION] [ARGUMENTS]

COMPONENT_PATH

Specifies the path to the component you want to work with. This argument is optional in case your current working directory is the base directory of a component and contains a package.xml file.

ACTION

Selects the action to perform. Most actions can also be selected with an option switch.

This is a list of available actions (use "help ACTION" to get additional information on the specified ACTION):

';


        $cli = new Cli();
        $moduleProvider = new ModuleProvider($injector);
        $modules = $moduleProvider->getModules();
        $parserProvider = new ParserProvider();
        $injector->setInstance(ParserProvider::class, $parserProvider);
        // Do we really want this here?
        $modularCli = new ModularCli($cli, $modules, $parserProvider, $usage);
        $parser = $modularCli->getParser();
        $injector->setInstance(Horde_Argv_Parser::class, $parser);
        return $modularCli;

        /*
        $modular = new \Horde_Cli_Modular(
            ['parser' => ['class' => empty($parameters['parser']['class']) ? \Horde_Argv_Parser::class : $parameters['parser']['class'], 'usage' => '[options] [COMPONENT_PATH] [ACTION] [ARGUMENTS]

COMPONENT_PATH

Specifies the path to the component you want to work with. This argument is optional in case your current working directory is the base directory of a component and contains a package.xml file.

ACTION

Selects the action to perform. Most actions can also be selected with an option switch.

This is a list of available actions (use "help ACTION" to get additional information on the specified ACTION):

'], 'modules' => ['directory' => __DIR__ . '/Module', 'exclude' => 'Base'], 'provider' => ['prefix' => 'Horde\Components\Module\\', 'dependencies' => $injector], 'cli' => $injector->getInstance(\Horde_Cli::class)]
        );
        $injector->setInstance(\Horde_Cli_Modular::class, $modular);
        return $modular;*/
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
            return new Injector(new TopLevel());
        }
    }

    protected static function _prepareConfig(\Horde_Argv_Parser $parser): \Horde\Components\Configs
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
     */
    protected static function _getActionArguments(\Horde_Cli_Modular $modular): array
    {
        $actions = [];
        foreach ($modular->getModules() as $module) {
            $actions = array_merge(
                $actions,
                $modular->getProvider()->getModule($module)->getActions()
            );
        }
        return ['list' => $actions, 'missing_argument' => ['help']];
    }

    /**
     * Identify the selected component based on the command arguments.
     *
     * @param Config $config  The active configuration.
     * @param array             $actions The list of available actions.
     */
    protected static function _identifyComponent(
        Config $config,
        $actions,
        Injector $dependencies
    ): void {
        $identify = new Identify(
            $config,
            $actions,
            $dependencies
        );
        $identify->setComponentInConfiguration();
    }
}
