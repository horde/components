<?php
/**
 * Horde\Components\Runner\Status:: runner for status output.
 *
 * PHP Version 7
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

namespace Horde\Components\Runner;

use Horde\Components\Config;
use Horde\Components\Exception;
use Horde\Components\Helper\Git as GitHelper;
use Horde\Components\Output;
use Horde\Components\Composer\InstallationDirectory;

/**
 * Horde\Components\Runner\Status:: runner for status output.
 *
 * Copyright 2020-2021 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Status
{
    /**
     * The repo base url.
     */
    private readonly string $gitRepoBase;

    /**
     * Where do we store local checkouts.
     */
    private readonly string $localCheckoutDir;

    /**
     * Constructor.
     *
     * @param Config    $config  The configuration for the current job.
     * @param Output    $output  The output handler.
     * @param GitHelper $git     The output handler.
     */
    public function __construct(
        private readonly Config $config,
        private readonly Output $output,
        private GitHelper $gitHelper
    ) {
        //        $this->gitHelper = $git;
        $options = $this->config->getOptions();
        $this->gitRepoBase = $options['git_repo_base'] ??
        'https://github.com/horde/';
        $this->localCheckoutDir = $options['checkout_dir'] ?? '/srv/git/horde';
    }

    public function run()
    {
        $arguments = $this->config->getArguments();
        $this->output->plain("horde-components status -- minding any CLI switches, current working directory and config file content");
        $configFilePath = $this->config->getOptions()['config'];
        $this->output->info("Config file path: $configFilePath");
        if (is_readable($configFilePath)) {
            $this->output->ok("Config file exists and is readable.");
        } else {
            $this->output->warn("Config file does not exist or is not readable.");
        };
        $this->output->info("Git Tree root path: $this->localCheckoutDir");
        if (is_readable($this->localCheckoutDir)) {
            $componentsCount = count(glob($this->localCheckoutDir . '/*', GLOB_ONLYDIR));
            $this->output->ok("Git Tree dir exists and has $componentsCount repos checked out");
        } else {
            $this->output->warn("Git Tree dir does not exist or is not readable.");
        };
        $installDir = new InstallationDirectory($this->config->getOptions()['install_base']);
        $this->output->info("Install Base path: $installDir");

        if ($installDir->exists()) {
            $this->output->ok("Install dir exists.");
            if ($installDir->hasComposerJson()) {
                $this->output->ok("Root composer.json file exists.");
            }
        } else {
            $this->output->warn("Install dir does not exist or is not readable.");
            $this->output->help("Run: \ncomposer create-project horde/bundle $installDir");
        };
    }
}
