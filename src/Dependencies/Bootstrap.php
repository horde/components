<?php
/**
 * The Components_Dependencies_Bootstrap:: class provides the Components
 * dependencies specifically for the bootstrapping process.
 *
 * PHP Version 7
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

namespace Horde\Components\Dependencies;

use Horde\Components\Component\Factory as ComponentFactory;
use Horde\Components\Config;
use Horde\Components\Config\Bootstrap as ConfigBootstrap;
use Horde\Components\Dependencies;
use Horde\Components\Output;
use Horde\Components\Pear\Dependencies as PearDependencies;
use Horde\Components\Pear\Environment as PearEnvironment;
use Horde\Components\Pear\Factory as PearFactory;
use Horde\Components\Pear\Package as PearPackage;
use Horde\Components\Release\Tasks as ReleaseTasks;
use Horde\Components\Runner\Change as RunnerChange;
use Horde\Components\Runner\CiDistribute as RunnerDistribute;
use Horde\Components\Runner\CiPrebuild as RunnerCiPrebuild;
use Horde\Components\Runner\CiSetup as RunnerCiSetup;
use Horde\Components\Runner\Composer as RunnerComposer;
use Horde\Components\Runner\Dependencies as RunnerDependencies;
use Horde\Components\Runner\Fetchdocs as RunnerFetchdocs;
use Horde\Components\Runner\Init as RunnerInit;
use Horde\Components\Runner\Installer as RunnerInstaller;
use Horde\Components\Runner\Qc as RunnerQc;
use Horde\Components\Runner\Release as RunnerRelease;
use Horde\Components\Runner\Snapshot as RunnerSnapshot;
use Horde\Components\Runner\Update as RunnerUpdate;
use Horde\Components\Runner\Webdocs as RunnerWebDocs;

/**
 * The Components_Dependencies_Bootstrap:: class provides the Components
 * dependencies specifically for the bootstrapping process.
 *
 * Copyright 2011-2020 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Bootstrap implements Dependencies
{
    /**
     * Initialized instances.
     *
     * @var array
     */
    private $_instances;

    /**
     * Constructor.
     */
    public function __construct()
    {
    }

    /**
     * Returns an instance.
     *
     * @param string $interface The interface matching the requested instance.
     *
     * @return mixed the instance.
     */
    public function getInstance($interface)
    {
        if (!isset($this->_instances[$interface])) {
            switch ($interface) {
                case \Horde\Components\Component\Factory::class:
                case 'Component\Factory':
                    require_once __DIR__ . '/../Component/Factory.php';
                    $this->_instances[$interface] = new $interface(
                        $this->getInstance(Config::class),
                        $this->getInstance(PearFactory::class),
                        new \Horde_Http_Client()
                    );
                    break;
                case PearFactory::class:
                    require_once __DIR__ . '/../Pear/Factory.php';
                    $this->_instances[$interface] = new $interface($this);
                    break;
                case Config::class:
                    require_once __DIR__ . '/../Config.php';
                    require_once __DIR__ . '/../Config/Base.php';
                    require_once __DIR__ . '/../Config/Bootstrap.php';
                    $this->_instances[$interface] = new ConfigBootstrap();
                    break;
                case Output::class:
                    require_once __DIR__ . '/../Output.php';
                    $this->_instances[$interface] = new Output(
                        $this->getInstance(\Horde_Cli::class),
                        $this->getInstance(Config::class)->getOptions()
                    );
                    break;
                case \Horde_Cli::class:
                    require_once __DIR__ . '/../../../../Cli/lib/Horde/Cli.php';
                    $this->_instances[$interface] = new \Horde_Cli();
                    break;
            }
        }
        return $this->_instances[$interface];
    }

    /**
     * Creates an instance.
     *
     * @param string $interface The interface matching the requested instance.
     *
     * @return mixed the instance.
     */
    public function createInstance($interface)
    {
        switch ($interface) {
            case PearEnvironment::class:
                return new $interface($this->getInstance(Output::class));
            case PearPackage::class:
                return new $interface($this->getInstance(Output::class));
            case PearDependencies::class:
                return new $interface($this->getInstance(Output::class));
        }
    }

    /**
     * Initial configuration setup.
     *
     * @param Config $config The configuration.
     */
    public function initConfig(Config $config): void
    {
    }

    /**
     * Set the list of modules.
     *
     * @param \Horde_Cli_Modular $modules The list of modules.
     */
    public function setModules(\Horde_Cli_Modular $modules): void
    {
    }

    /**
     * Return the list of modules.
     *
     * @retunr \Horde_Cli_Modular The list of modules.
     */
    public function getModules(): void
    {
    }

    /**
     * Set the CLI parser.
     *
     * @param \Horde_Argv_Parser $parser The parser.
     */
    public function setParser($parser): void
    {
    }

    /**
     * Return the CLI parser.
     *
     * @return \Horde_Argv_Parser The parser.
     */
    public function getParser(): void
    {
    }

    /**
     * Returns the continuous integration setup handler.
     *
     * @return RunnerCiSetup The CI setup handler.
     */
    public function getRunnerCiSetup(): RunnerCiSetup
    {
        return $this->getInstance(RunnerCiSetup::class);
    }

    /**
     * Returns the continuous integration pre-build handler.
     *
     * @return RunnerCiPrebuild The CI pre-build handler.
     */
    public function getRunnerCiPrebuild(): RunnerCiPrebuild
    {
        return $this->getInstance(RunnerCiPrebuild::class);
    }

    /**
     * Returns the distribution handler for a package.
     *
     * @return RunnerDistribute The distribution handler.
     */
    public function getRunnerDistribute(): RunnerDistribute
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
        return $this->getInstance(Runner\Webdocs::class);
    }

    /**
     * Returns the documentation fetch handler for a package.
     *
     * @return RunnerFetchdocs The fetch handler.
     */
    public function getRunnerFetchdocs(): RunnerFetchdocs
    {
        return $this->getInstance(RunnerFetchdocs::class);
    }

    /**
     * Returns the init handler for a package.
     *
     * @return RunnerInit The fetch handler.
     */
    public function getRunnerInit(): RunnerInit
    {
        return $this->getInstance(RunnerInit::class);
    }

    /**
     * Returns the composer handler for a package.
     *
     * @return RunnerComposer The composer handler.
     */
    public function getRunnerComposer(): RunnerComposer
    {
        return $this->getInstance(RunnerComposer::class);
    }

    /**
     * Returns the release handler for a package.
     *
     * @return RunnerRelease The release handler.
     */
    public function getRunnerRelease(): RunnerRelease
    {
        return $this->getInstance(RunnerRelease::class);
    }

    /**
     * Returns the qc handler for a package.
     *
     * @return RunnerQc The qc handler.
     */
    public function getRunnerQc(): RunnerQc
    {
        return $this->getInstance(RunnerQc::class);
    }

    /**
     * Returns the change log handler for a package.
     *
     * @return RunnerChange The change log handler.
     */
    public function getRunnerChange(): RunnerChange
    {
        return $this->getInstance(RunnerChange::class);
    }

    /**
     * Returns the snapshot packaging handler for a package.
     *
     * @return RunnerSnapshot The snapshot handler.
     */
    public function getRunnerSnapshot(): RunnerSnapshot
    {
        return $this->getInstance(RunnerSnapshot::class);
    }

    /**
     * Returns the dependency list handler for a package.
     *
     * @return RunnerDependencies The dependency handler.
     */
    public function getRunnerDependencies(): RunnerDependencies
    {
        return $this->getInstance(RunnerDependencies::class);
    }

    /**
     * Returns the installer for a package.
     *
     * @return RunnerInstaller The installer.
     */
    public function getRunnerInstaller(): RunnerInstaller
    {
        return $this->getInstance(RunnerInstaller::class);
    }

    /**
     * Returns the package XML handler for a package.
     *
     * @return RunnerUpdate The package XML handler.
     */
    public function getRunnerUpdate(): RunnerUpdate
    {
        return $this->getInstance(RunnerUpdate::class);
    }

    /**
     * Returns the release tasks handler.
     *
     * @return ReleaseTasks The release tasks handler.
     */
    public function getReleaseTasks(): ReleaseTasks
    {
        return $this->getInstance(ReleaseTasks::class);
    }

    /**
     * Returns the output handler.
     *
     * @return Output The output handler.
     */
    public function getOutput(): \Horde\Components\Output
    {
        return $this->getInstance(Output::class);
    }

    /**
     * Returns a component instance factory.
     *
     * @return ComponentFactory The component factory.
     */
    public function getComponentFactory(): ComponentFactory
    {
        return $this->getInstance(ComponentFactory::class);
    }

    /**
     * Returns the handler for remote PEAR servers.
     *
     * @return \Horde_Pear_Remote The handler.
     */
    public function getRemote(): \Horde_Pear_Remote
    {
        return $this->getInstance(\Horde_Pear_Remote::class);
    }

    /**
     * Create the CLI handler.
     *
     * @return \Horde_Cli The CLI handler.
     */
    public function createCli(): \Horde_Cli
    {
        return \Horde_Cli::init();
    }
}
