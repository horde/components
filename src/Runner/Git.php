<?php

/**
 * Horde\Components\Runner\Git:: runner for git operations.
 *
 * PHP Version 8.2+
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

declare(strict_types=1);

namespace Horde\Components\Runner;

use Horde\Components\ConfigProvider\EffectiveConfigProvider;
use Horde\Components\Exception;
use Horde\Components\Helper\Git as GitHelper;
use Horde\Components\Output;

/**
 * Horde\Components\Runner\Git:: runner for git operations.
 *
 * Copyright 2020-2026 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Git
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
     * @param EffectiveConfigProvider $config Configuration provider
     * @param array $arguments CLI arguments
     * @param Output $output The output handler
     * @param GitHelper $gitHelper Git helper for operations
     */
    public function __construct(
        private readonly EffectiveConfigProvider $config,
        private readonly array $arguments,
        private readonly Output $output,
        private readonly GitHelper $gitHelper
    ) {
        $this->gitRepoBase = $this->config->hasSetting('git_repo_base')
            ? $this->config->getSetting('git_repo_base')
            : 'https://github.com/horde/';

        $checkoutDir = $this->config->hasSetting('checkout.dir')
            ? $this->config->getSetting('checkout.dir')
            : '/srv/git/horde';

        // Normalize path: remove trailing slash to avoid double slashes when concatenating
        $this->localCheckoutDir = rtrim($checkoutDir, '/');
    }

    public function run(): void
    {
        if (count($this->arguments) == 1) {
            $this->output->help('For usage help, run: horde-components help git');
            return;
        }
        if ($this->arguments[1] == 'clone' && count($this->arguments) > 1) {
            /**
             * TODO: Mind cwd
             * TODO: Mind Pretend Mode
             */
            if (empty($this->arguments[2])) {
                $this->output->help('Provide a component name.');
                $this->output->help('Cloning all components has not yet been ported from git-tools');
                return;
            }
            $component = $this->arguments[2];
            $branch = $this->arguments[4] ?? '';
            $componentDir = $this->localCheckoutDir . '/' . $component . '/';
            $cloneUrl = $this->gitRepoBase . '/' . $component . '.git';
            // Achieved fixed format, delegate to helper
            $this->gitHelper->workflowClone(
                $this->output,
                $cloneUrl,
                $componentDir,
                $branch
            );
            return;
        }
        if ($this->arguments[1] == 'checkout') {
            if (count($this->arguments) != 4) {
                $this->output->help('checkout currently only supports a fixed format');
                $this->output->help('checkout component branch');
            }
            [$git, $action, $component, $branch] = $this->arguments;
            $componentDir = $this->localCheckoutDir . '/' . $component . '/';
            $this->gitHelper->workflowCheckout(
                $this->output,
                $componentDir,
                $branch
            );
            return;
        }
        if ($this->arguments[1] == 'fetch') {
            if (count($this->arguments) != 3) {
                $this->output->help('fetch currently only supports a fixed format');
                $this->output->help('fetch [component]');
                return;
            }
            [$git, $action, $component] = $this->arguments;
            $componentDir = $this->localCheckoutDir . '/' . $component . '/';
            $this->gitHelper->fetch($componentDir);
            return;
        }
        if ($this->arguments[1] == 'branch') {
            if (count($this->arguments) != 5) {
                $this->output->help('branch currently only supports a fixed format');
                $this->output->help('branch [component] [branch] [source branch]');
                return;
            }
            [$git, $action, $component, $branch, $source] = $this->arguments;
            $componentDir = $this->localCheckoutDir . '/' . $component . '/';
            $this->gitHelper->workflowBranch(
                $this->output,
                $componentDir,
                $branch,
                $source
            );
            return;
        }
        if ($this->arguments[1] == 'tag') {
            if (count($this->arguments) != 6) {
                $this->output->help('tag currently only supports a fixed format');
                $this->output->help('tag component branch tagname comment');
            }
            [$git, $action, $component, $branch, $tag, $comment] = $this->arguments;
            $componentDir = $this->localCheckoutDir . '/' . $component . '/';
            if (!$this->gitHelper->localBranchExists($componentDir, $branch)) {
                $this->output->warn("Cannot tag, local branch does not exist");
                return;
            }
            $this->gitHelper->checkoutBranch($componentDir, $branch);
            // Do we really want to update existing tags?
            $this->gitHelper->tag($componentDir, $tag, $comment);
            return;
        }
        if ($this->arguments[1] == 'push') {
            if (count($this->arguments) != 3) {
                $this->output->help('push currently only supports a fixed format');
                $this->output->help('push component');
                exit();
            }
            [$git, $action, $component] = $this->arguments;
            $componentDir = $this->localCheckoutDir . '/' . $component . '/';
            $this->gitHelper->push($componentDir);
            return;
        }
        $this->output->warn("Could not understand your command:");
        $this->output->warn(implode(" ", $this->arguments));
    }
}
