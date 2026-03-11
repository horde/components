<?php

/**
 * The Components_Dependencies_Injector:: class provides the
 * Components dependencies based on the Horde injector.
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

namespace Horde\Components\Dependencies;

use Horde\Components\Component\Factory as ComponentFactory;
use Horde\Components\Composer\InstallationDirectory;
use Horde\Components\ConfigProvider\EnvironmentConfigProvider;
use Horde\Components\Dependencies;
use Horde\Components\Output;
use Horde\Components\Auth\AuthenticationFactory;
use Horde\Components\Helper\Git as GitHelper;
use Horde\Components\Helper\GitHubChecker;
use Horde\Components\Helper\GitHubReleaseCreator;
use Horde\Components\Helper\PullRequestManager;
use Horde\Components\Release\Notes as ReleaseNotes;
use Horde\Components\Release\Tasks as ReleaseTasks;
use Horde\Components\Runner\Change as RunnerChange;
use Horde\Components\Runner\ConventionalCommit as RunnerConventionalCommit;
use Horde\Components\Runner\CiPrebuild as RunnerCiPrebuild;
use Horde\Components\Runner\CiSetup as RunnerCiSetup;
use Horde\Components\Runner\Composer as RunnerComposer;
use Horde\Components\Runner\Dependencies as RunnerDependencies;
use Horde\Components\Runner\Distribute as RunnerDistribute;
use Horde\Components\Runner\Fetchdocs as RunnerFetchdocs;
use Horde\Components\Runner\Git as RunnerGit;
use Horde\Components\Runner\Github as RunnerGithub;
use Horde\Components\Runner\Init as RunnerInit;
use Horde\Components\Runner\Installer as RunnerInstaller;
use Horde\Components\Runner\Pullrequest as RunnerPullrequest;
use Horde\Components\Runner\Qc as RunnerQc;
use Horde\Components\Runner\Release as RunnerRelease;
use Horde\Components\Runner\Snapshot as RunnerSnapshot;
use Horde\Components\Runner\Update as RunnerUpdate;
use Horde\Components\Runner\Webdocs as RunnerWebdocs;
use Horde\Components\RuntimeContext\GitCheckoutDirectory;
use Horde\Injector\Injector as HordeInjector;
use Horde\Injector\TopLevel;
use Exception;
use Horde_Argv_Parser;
use Horde_Cli;
use Horde_Cli_Modular;
use Horde_Http_Client;
use Horde_Pear_Remote;

/**
 * The Components_Dependencies_Injector:: class provides the
 * Components dependencies based on the Horde injector.
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
class Injector extends HordeInjector implements Dependencies
{
    /**
     * Use a pager for \Horde_Cli?
     *
     * @var boolean
     */
    protected $_usePager = false;

    /**
     * Constructor.
     *
     * @param Injector $parentInjector A parent injector, if any
     */
    public function __construct($parentInjector = null)
    {
        parent::__construct($parentInjector ?? new TopLevel());
        $this->setInstance(Dependencies::class, $this);
        $this->setInstance(EnvironmentConfigProvider::class, new EnvironmentConfigProvider(getenv()));
        $this->bindFactory(
            ComponentFactory::class,
            Dependencies::class,
            'createComponentFactory'
        );
        $this->bindFactory(
            Horde_Cli::class,
            Dependencies::class,
            'createCli'
        );
        $this->bindFactory(
            GitCheckoutDirectory::class,
            GitCheckoutDirectoryFactory::class,
            '__invoke'
        );
        $this->bindFactory(
            InstallationDirectory::class,
            InstallationDirectoryFactory::class,
            '__invoke'
        );
        $this->bindFactory(
            Output::class,
            Dependencies::class,
            'createOutput'
        );
        $this->setInstance(GitHelper::class, new GitHelper());

        // Authentication - use factory for lazy initialization
        $this->bindFactory(
            AuthenticationFactory::class,
            AuthenticationFactoryFactory::class,
            '__invoke'
        );

        // GitHub integration dependencies - use factories for lazy initialization
        $this->bindFactory(
            GitHubChecker::class,
            GitHubCheckerFactory::class,
            '__invoke'
        );
        $this->bindFactory(
            GitHubReleaseCreator::class,
            GitHubReleaseCreatorFactory::class,
            '__invoke'
        );
        $this->bindFactory(
            PullRequestManager::class,
            PullRequestManagerFactory::class,
            '__invoke'
        );
    }

    public static function registerAppDependencies(HordeInjector $injector)
    {
        $injector->bindFactory(
            Horde_Cli::class,
            Dependencies::class,
            'createCli'
        );
        $injector->bindFactory(
            Output::class,
            Dependencies::class,
            'createOutput'
        );
        $injector->setInstance(GitHelper::class, new GitHelper());

        // Authentication - use factory for lazy initialization
        $injector->bindFactory(
            AuthenticationFactory::class,
            AuthenticationFactoryFactory::class,
            '__invoke'
        );

        // GitHub integration dependencies - use factories for lazy initialization
        $injector->bindFactory(
            GitHubChecker::class,
            GitHubCheckerFactory::class,
            '__invoke'
        );
        $injector->bindFactory(
            GitHubReleaseCreator::class,
            GitHubReleaseCreatorFactory::class,
            '__invoke'
        );
        $injector->bindFactory(
            PullRequestManager::class,
            PullRequestManagerFactory::class,
            '__invoke'
        );
    }

    /**
     * Set the list of modules.
     *
     * @param Horde_Cli_Modular $modules The list of modules.
     */
    public function setModules(Horde_Cli_Modular $modules): void
    {
        $this->setInstance(Horde_Cli_Modular::class, $modules);
    }

    /**
     * Return the list of modules.
     *
     * @return Horde_Cli_Modular The list of modules.
     */
    public function getModules()
    {
        return $this->getInstance(Horde_Cli_Modular::class);
    }

    /**
     * Set the CLI parser.
     *
     * @param Horde_Argv_Parser $parser The parser.
     */
    public function setParser($parser): void
    {
        $this->setInstance(Horde_Argv_Parser::class, $parser);
    }

    /**
     * Return the CLI parser.
     *
     * @return Horde_Argv_Parser The parser.
     */
    public function getParser()
    {
        return $this->getInstance(Horde_Argv_Parser::class);
    }

    /**
     * Returns the continuous integration setup handler.
     *
     * @return RunnerCiSetup The CI setup handler.
     */
    public function getRunnerCiSetup()
    {
        return $this->getInstance(RunnerCiSetup::class);
    }

    /**
     * Returns the continuous integration pre-build handler.
     *
     * @return RunnerCiPrebuild The CI pre-build handler.
     */
    public function getRunnerCiPrebuild()
    {
        return $this->getInstance(RunnerCiPrebuild::class);
    }

    /**
     * Returns the distribution handler for a package.
     *
     * @return RunnerDistribute The distribution handler.
     */
    public function getRunnerDistribute()
    {
        return $this->getInstance(RunnerDistribute::class);
    }

    /**
     * Returns the website documentation handler for a package.
     *
     * @return RunnerWebdocs The documentation handler.
     */
    public function getRunnerWebdocs()
    {
        return $this->getInstance(RunnerWebdocs::class);
    }

    /**
     * Returns the documentation fetch handler for a package.
     *
     * @return RunnerFetchdocs The fetch handler.
     */
    public function getRunnerFetchdocs()
    {
        return $this->getInstance(RunnerFetchdocs::class);
    }

    /**
     * Returns the composer handler for a package.
     *
     * @return RunnerComposer The composer handler.
     */
    public function getRunnerComposer()
    {
        return $this->getInstance(RunnerComposer::class);
    }

    /**
     * Returns the release handler for a package.
     *
     * @return RunnerRelease The release handler.
     */
    public function getRunnerRelease()
    {
        return $this->getInstance(RunnerRelease::class);
    }

    /**
     * Returns the qc handler for a package.
     *
     * @return RunnerQc The qc handler.
     */
    public function getRunnerQc()
    {
        return $this->getInstance(RunnerQc::class);
    }

    /**
     * Returns the change log handler for a package.
     *
     * @return RunnerChange The change log handler.
     */
    public function getRunnerChange()
    {
        return $this->getInstance(RunnerChange::class);
    }
    public function getRunnerConventionalCommit()
    {
        return $this->getInstance(RunnerConventionalCommit::class);
    }

    /**
     * Returns the snapshot packaging handler for a package.
     *
     * @return RunnerSnapshot The snapshot handler.
     */
    public function getRunnerSnapshot()
    {
        return $this->getInstance(RunnerSnapshot::class);
    }

    /**
     * Returns the dependency list handler for a package.
     *
     * @return RunnerDependencies The dependency handler.
     */
    public function getRunnerDependencies()
    {
        return $this->getInstance(RunnerDependencies::class);
    }
    /**
     * Returns the dependency list handler for a package.
     *
     * @return RunnerGit The Git Handler
     */
    public function getRunnerGit()
    {
        return $this->getInstance(RunnerGit::class);
    }
    /**
     * Returns the dependency list handler for a package.
     *
     * @return RunnerGit The Git Handler
     */
    public function getRunnerGithub()
    {
        return $this->getInstance(RunnerGithub::class);
    }

    /**
     * Returns the init handler for a package.
     *
     * @return RunnerInit The fetch handler.
     */
    public function getRunnerInit()
    {
        return $this->getInstance(RunnerInit::class);
    }

    /**
     * Returns the installer for a package.
     *
     * @return RunnerInstaller The installer.
     */
    public function getRunnerInstaller()
    {
        return $this->getInstance(RunnerInstaller::class);
    }

    /**
     * Returns the pull request handler.
     *
     * @return RunnerPullrequest The pull request handler.
     */
    public function getRunnerPullrequest()
    {
        return $this->getInstance(RunnerPullrequest::class);
    }

    /**
     * Returns the package XML handler for a package.
     *
     * @return RunnerUpdate The package XML handler.
     */
    public function getRunnerUpdate()
    {
        return $this->getInstance(RunnerUpdate::class);
    }

    /**
     * Returns the release tasks handler.
     *
     * @return ReleaseTasks The release tasks handler.
     */
    public function getReleaseTasks()
    {
        return $this->getInstance(ReleaseTasks::class);
    }

    /**
     * Returns the output handler.
     *
     * @return Output The output handler.
     */
    public function getOutput()
    {
        return $this->getInstance(Output::class);
    }

    /**
     * Returns a component instance factory.
     *
     * @return ComponentFactory The component factory.
     */
    public function getComponentFactory()
    {
        return $this->getInstance(ComponentFactory::class);
    }

    /**
     * Returns the handler for remote PEAR servers.
     *
     * @return Horde_Pear_Remote The handler.
     */
    public function getRemote()
    {
        return $this->getInstance(Horde_Pear_Remote::class);
    }

    /**
     * Enables a pager for \Horde_Cli objects.
     */
    public function useCliPager(): void
    {
        $this->_usePager = true;
    }

    /**
     * Creates a component instance factory.
     *
     * @return ComponentFactory The component factory.
     */
    public function createComponentFactory(): ComponentFactory
    {
        // Get options from ConfigProvider if available, or empty array as fallback
        try {
            $configProvider = $this->getInstance(\Horde\Components\ConfigProvider\ConfigProvider::class);
            $options = [];
            foreach ($configProvider->getAvailableKeys() as $key) {
                if ($configProvider->hasSetting($key)) {
                    $options[$key] = $configProvider->getSetting($key);
                }
            }
        } catch (Exception $e) {
            $options = [];
        }

        return new ComponentFactory(
            $options,
            $this->getInstance(\Horde\Components\Pear\Factory::class),
            $this->getInstance(Horde_Http_Client::class),
            $this->getInstance(Output::class),
            $this->getInstance(ReleaseNotes::class)
        );
    }

    /**
     * Create the CLI handler.
     *
     * Horde_Cli::init() sets a global exception handler which can interfere
     * with PHPUnit's exception handling in tests. We detect if running under
     * PHPUnit and avoid init() in that case.
     *
     * @return Horde_Cli The CLI handler.
     */
    public function createCli(): Horde_Cli
    {
        // Check if running under PHPUnit
        $isTestEnvironment = defined('PHPUNIT_COMPOSER_INSTALL')
                           || defined('__PHPUNIT_PHAR__')
                           || class_exists('PHPUnit\\Framework\\TestCase', false);

        if ($isTestEnvironment) {
            // In test environment, use constructor directly to avoid
            // set_exception_handler() call in Horde_Cli::init()
            return new Horde_Cli(['pager' => $this->_usePager]);
        }

        // In production, use init() which sets up full CLI environment
        return Horde_Cli::init(['pager' => $this->_usePager]);
    }

    /**
     * Create the Components\Output handler.
     *
     * @param Injector $injector The injector to use
     *
     * @return Output The output handler.
     */
    public function createOutput(Injector $injector): Output
    {
        // Get parsed options from DI if available, otherwise use empty array
        $options = [];
        if ($injector->has('parsed_options')) {
            $options = $injector->getInstance('parsed_options');
        }

        return new Output(
            $injector->getInstance(Horde_Cli::class),
            $options
        );
    }
}
