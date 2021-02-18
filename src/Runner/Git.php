<?php
/**
 * Horde\Components\Runner\Git:: runner for git operations.
 *
 * PHP Version 7
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <lang@b1-systems.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
namespace Horde\Components\Runner;
use Horde\Components\Config;
use Horde\Components\Exception;
use Horde\Components\Output;
use Horde\Components\Helper\Git as GitHelper;

/**
 * Horde\Components\Runner\Git:: runner for git operations.
 *
 * Copyright 2020-2021 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <lang@b1-systems.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Git
{
    /**
     * The configuration for the current job.
     *
     * @var Config
     */
    private $config;

    /**
     * The output handler.
     *
     * @var Output
     */
    private $output;

    /**
     * The repo base url.
     *
     * @var string
     */
    private $gitRepoBase;

    /**
     * Constructor.
     *
     * @param Config    $config  The configuration for the current job.
     * @param Output    $output  The output handler.
     * @param GitHelper $git     The output handler.
     */
    public function __construct(
        Config $config,
        Output $output,
        GitHelper $git
    ) {
        $this->config  = $config;
        $this->output  = $output;
        $this->gitHelper = $git;
        $options = $this->config->getOptions();
        $this->gitRepoBase = $options['git_repo_base'] ?? 
        'https://github.com/horde/';
        $this->localCheckoutDir = $options['checkout_dir'] ?? '/srv/git/';

    }

    public function run()
    {
        $arguments = $this->config->getArguments();
        if (count($arguments) == 1) {
            $this->output->help('For usage help, run: horde-components help git');
            return;
        }
        if ($arguments[1] == 'clone' && count($arguments) > 1) {
            /** 
             * TODO: Mind cwd
             * TODO: Mind authentication
             * TODO: Mind Pretend Mode
             * TODO: Move implementation to helper method
             */
            if (empty($arguments[2])) {
                $this->output->help('Provide a component name.');
                $this->output->help('Cloning all components has not yet been ported from git-tools');
                return;    
            }
            $component = $arguments[2];
            $branch = $arguments[4] ?? '';
            $componentDir = $this->localCheckoutDir . $component . '/';
            $cloneUrl = $this->gitRepoBase . $component . '.git';

            $this->output->info(
                sprintf(
                    'Will clone component %s from %s to %s',
                    $component,
                    $cloneUrl,
                    $componentDir
                )
            );
            $this->gitHelper->clone($cloneUrl, $componentDir, $branch);
            return;
        }
        if ($arguments[1] == 'checkout') {
            if (count($arguments) != 4) {
                $this->output->help('checkout currently only supports a fixed format');
                $this->output->help('checkout component branch');    
            }
            list($git, $action, $component, $branch) = $arguments;
            // Achieved fixed format, everything below could move to a helper
            $componentDir = $this->localCheckoutDir . $component . '/';
            // Do nothing if already checked out
            if ($branch == $this->gitHelper->getCurrentBranch($componentDir)) {
                $this->output->info('Branch already checked out');
                return;
            }
            if ($this->gitHelper->localBranchExists($componentDir, $branch)) {
                $this->output->info('Branch exists locally');
                $this->gitHelper->checkout($componentDir, $branch);
            }

        }
        if ($arguments[1] == 'fetch') {
            if (count($arguments) != 3) {
                $this->output->help('checkout currently only supports a fixed format');
                $this->output->help('fetch component');
            }
            list($git, $action, $component) = $arguments;
            $componentDir = $this->localCheckoutDir . $component . '/';
            $this->gitHelper->fetch($componentDir);
        }

    }
}
