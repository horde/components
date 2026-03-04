<?php

/**
 * The Components:: class is the entry point for the various component actions
 * provided by the package.
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

namespace Horde\Components;

use Horde\Cli\Modular\ModularCli;
use Horde\Components\Component\Identify;
use Horde\Components\ConfigProvider\BuiltinConfigProvider;
use Horde\Components\ConfigProvider\CliConfigProvider;
use Horde\Components\ConfigProvider\ConfigProvider;
use Horde\Components\ConfigProvider\EnvironmentConfigProvider;
use Horde\Components\ConfigProvider\PhpConfigFileProvider;
use Horde\Components\ConfigProvider\ConfigProviderFactory;
use Horde\Components\Module;
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
use Horde\Components\Application\ShellEnvironment;
use Horde\Components\RuntimeContext\DefaultConfigFilePath;
use Horde_Argv_Parser;
// For Github API Client
use Horde\Http\Client\Options;
use Horde\Http\Client\Curl as CurlClient;
use Horde\Http\StreamFactory;
use Horde\Http\RequestFactory;
use Horde\Http\ResponseFactory;
use Horde\GithubApiClient\GithubApiClient;
use Horde\GithubApiClient\GithubApiConfig;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Horde_Cli_Modular;

/**
 * The Components:: class is the entry point for the various component actions
 * provided by the package.
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
        $injector = new Dependencies\Injector(new TopLevel());
        $injector->setInstance(EventdispatcherInterface::class, $dispatcher);
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
        $shellEnv = ShellEnvironment::fromGetEnv();
        $injector->setInstance(DefaultConfigFilePath::class, new DefaultConfigFilePath($shellEnv));
        $injector->setInstance(EnvironmentConfigProvider::class, $environmentConfig);
        $injector->setInstance(BuiltinConfigProvider::class, new BuiltinConfigProvider(
            [
                'checkout.dir' => $environmentConfig->hasSetting('HOME') ? $environmentConfig->getSetting('HOME') . '/git' : '/srv/git',
                'repo.org' => 'horde',
                'scm.domain' => 'https://github.com',
                'scm.type' => 'github',
            ]
        ));
        $finder = $injector->get(ConfigFileFinder::class);
        $configFileLocation = $finder->find();
        $phpConfig = new PhpConfigFileProvider($configFileLocation);
        $injector->setInstance(PhpConfigFileProvider::class, $phpConfig);

        // Check for legacy config file (config/conf.php)
        $legacyConfigPath = dirname(__DIR__) . '/config/conf.php';
        $legacyConfig = null;
        if (file_exists($legacyConfigPath) && is_readable($legacyConfigPath)) {
            $legacyConfig = new PhpConfigFileProvider($legacyConfigPath);
        }

        // Set up ConfigProviderFactory with all layers
        // Note: CLI provider will be added in _prepareModular after parser is ready
        $configFactory = new ConfigProviderFactory(
            $environmentConfig,
            $phpConfig,
            $legacyConfig,
            $injector->get(BuiltinConfigProvider::class),
            null  // CLI provider added later
        );
        $injector->setInstance(ConfigProviderFactory::class, $configFactory);

        Dependencies\Injector::registerAppDependencies($injector);
        // Identify if we are in a component dir or have provided one with variable
        $modular = self::_prepareModular($injector, $parameters);
        // If we don't do this, help introspection is broken.
        $injector->setInstance(ModularCli::class, $modular);

        // NOW that parser is ready, we can create CliConfigProvider and update factory
        $parser = $modular->getParser();
        [$parsedOptions, $parsedArgs] = $parser->parseArgs();

        // Convert Horde\Argv\Values object to array
        $optionsArray = [];
        foreach ($parsedOptions as $key => $value) {
            $optionsArray[$key] = $value;
        }

        $cliProvider = new CliConfigProvider($optionsArray);

        // Create new factory WITH CLI provider
        $configFactoryWithCli = new ConfigProviderFactory(
            $environmentConfig,
            $phpConfig,
            $legacyConfig,
            $injector->get(BuiltinConfigProvider::class),
            $cliProvider  // NOW we have CLI options!
        );
        // Replace the old factory
        $injector->setInstance(ConfigProviderFactory::class, $configFactoryWithCli);

        // Get ConfigProvider and register it
        $configProvider = $configFactoryWithCli->createDefault();
        $injector->setInstance(ConfigProvider::class, $configProvider);

        // Store parsed options for Output factory
        $injector->setInstance('parsed_options', $optionsArray);

        // Identify component if working in a component directory
        $component = null;
        $componentPath = null;
        try {
            $identify = $injector->getInstance(Identify::class);
            [$component, $componentPath, $parsedArgs] = $identify->identifyComponent(
                $parsedArgs,  // Pass by reference, will be modified
                getcwd()
            );
        } catch (\Exception $e) {
            // No component in current directory - that's fine for many commands
        }

        /**
         * By this point the modular CLI is setup to cycle through "handle"
         */
        try {
            $ran = false;
            foreach (clone $modular->getModules() as $module) {
                $ran |= $module->handle($optionsArray, $parsedArgs, $component);
            }
        } catch (Exception $e) {
            $injector->getInstance(Output::class)->fail($e);
            return;
        }

        if (!$ran) {
            // Show brief help instead of parser error
            $helpModule = null;
            foreach ($modular->getModules() as $module) {
                if ($module instanceof Module\Help) {
                    $helpModule = $module;
                    break;
                }
            }
            if ($helpModule) {
                $helpModule->showBriefHelp();
            } else {
                $modular->getParser()->parserError(self::ERROR_NO_ACTION);
            }
        }
    }


    protected static function _prepareModular(
        Dependencies|Injector $injector,
        array $parameters = []
    ): ModularCli {
        // TODO: Externalize to avoid non-code in a code file and to remove indention
        $usage = '[options] [ACTION] [ARGUMENTS]

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
        $parser->ignoreUnknownArgs = true;
        $parser->allowUnknownArgs = true;
        $injector->setInstance(Horde_Argv_Parser::class, $parser);

        // HTTP/PSR-17 factories
        $streamFactory = new StreamFactory();
        $responseFactory = new ResponseFactory();
        $requestFactory = new RequestFactory();

        $injector->setInstance(StreamFactoryInterface::class, $streamFactory);
        $injector->setInstance(RequestFactoryInterface::class, $requestFactory);
        $injector->setInstance(ClientInterface::class, new CurlClient($responseFactory, $streamFactory, new Options()));

        // Get GitHub token from ConfigProvider hierarchy
        // Precedence: CLI args > GITHUB_TOKEN env var > github.token config key
        $configFactory = $injector->getInstance(ConfigProviderFactory::class);
        $config = $configFactory->createDefault();

        $githubToken = '';
        // First check GITHUB_TOKEN environment variable (backward compatibility)
        if ($config->hasSetting('GITHUB_TOKEN')) {
            $githubToken = $config->getSetting('GITHUB_TOKEN');
        }
        // Then check github.token config key (new way)
        elseif ($config->hasSetting('github.token')) {
            $githubToken = $config->getSetting('github.token');
        }

        $injector->setInstance(GithubApiConfig::class, new GithubApiConfig(accessToken: $githubToken));
        return $modularCli;
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

    /**
     * Provide a list of available action arguments.
     */
    protected static function _getActionArguments(Horde_Cli_Modular $modular): array
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
}
