<?php
/**
 * The Components_Dependencies:: interface is a central broker for
 * providing the dependencies to the different application parts.
 *
 * PHP Version 7
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
namespace Horde\Components;
use Horde\Components\Runner\CiPrebuild as RunnerCiPrebuilt;
use Horde\Components\Runner\CiSetup as RunnerCiSetup;
use Horde\Components\Runner\Release as RunnerRelease;
use Horde\Components\Runner\Qc as RunnerQc;
use Horde\Components\Runner\Change as RunnerChange;
use Horde\Components\Runner\Composer as RunnerComposer;
use Horde\Components\Runner\Snapshot as RunnerSnapshot;
use Horde\Components\Runner\Distribute as RunnerDistribute;
use Horde\Components\Runner\FetchDocs as RunnerFetchDocs;
use Horde\Components\Runner\Installer as RunnerInstaller;
use Horde\Components\Runner\Update as RunnerUpdate;
use Horde\Components\Runner\Webdocs as RunnerWebdocs;
use Horde\Components\Release\Tasks as ReleaseTasks;


/**
 * The Components_Dependencies:: interface is a central broker for
 * providing the dependencies to the different application parts.
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
interface Dependencies
{
    /**
     * Returns an instance.
     *
     * @param string $interface The interface matching the requested instance.
     *
     * @return mixed the instance.
     */
    public function getInstance(string $interface);

    /**
     * Initial configuration setup.
     *
     * @param Config $config The configuration.
     *
     * @return void
     */
    public function initConfig(Config $config);

    /**
     * Set the list of modules.
     *
     * @param \Horde_Cli_Modular $modules The list of modules.
     *
     * @return void
     */
    public function setModules(\Horde_Cli_Modular $modules);

    /**
     * Return the list of modules.
     *
     * @retunr \Horde_Cli_Modular The list of modules.
     */
    public function getModules();

    /**
     * Set the CLI parser.
     *
     * @param \Horde_Argv_Parser $parser The parser.
     *
     * @return void
     */
    public function setParser($parser);

    /**
     * Return the CLI parser.
     *
     * @retunr \Horde_Argv_Parser The parser.
     */
    public function getParser();

    /**
     * Returns the continuous integration setup handler.
     *
     * @return RunnerCiSetup The CI setup handler.
     */
    public function getRunnerCiSetup();

    /**
     * Returns the continuous integration pre-build handler.
     *
     * @return RunnerCiPrebuild The CI pre-build handler.
     */
    public function getRunnerCiPrebuild();

    /**
     * Returns the composer handler for a package.
     *
     * @return RunnerComposer The composer handler.
     */
    public function getRunnerComposer();

    /**
     * Returns the release handler for a package.
     *
     * @return RunnerRelease The release handler.
     */
    public function getRunnerRelease();

    /**
     * Returns the qc handler for a package.
     *
     * @return RunnerQc The qc handler.
     */
    public function getRunnerQc();

    /**
     * Returns the change log handler for a package.
     *
     * @return RunnerChange The change log handler.
     */
    public function getRunnerChange();

    /**
     * Returns the snapshot packaging handler for a package.
     *
     * @return RunnerSnapshot The snapshot handler.
     */
    public function getRunnerSnapshot();

    /**
     * Returns the distribution handler for a package.
     *
     * @return RunnerDistribute The distribution handler.
     */
    public function getRunnerDistribute();

    /**
     * Returns the website documentation handler for a package.
     *
     * @return RunnerWebdocs The documentation handler.
     */
    public function getRunnerWebdocs();

    /**
     * Returns the documentation fetch handler for a package.
     *
     * @return RunnerFetchdocs The fetch handler.
     */
    public function getRunnerFetchdocs();

    /**
     * Returns the installer for a package.
     *
     * @return RunnerInstaller The installer.
     */
    public function getRunnerInstaller();

    /**
     * Returns the package XML handler for a package.
     *
     * @return RunnerUpdate The package XML handler.
     */
    public function getRunnerUpdate();

    /**
     * Returns the release tasks handler.
     *
     * @return ReleaseTasks The release tasks handler.
     */
    public function getReleaseTasks();

    /**
     * Returns the output handler.
     *
     * @return Output The output handler.
     */
    public function getOutput();

    /**
     * Returns a component instance factory.
     *
     * @return Component\Factory The component factory.
     */
    public function getComponentFactory();

    /**
     * Returns the handler for remote PEAR servers.
     *
     * @return \Horde_Pear_Remote The handler.
     */
    public function getRemote();
}
