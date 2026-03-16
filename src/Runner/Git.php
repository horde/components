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
use Horde\Components\RuntimeContext\GitCheckoutDirectory;

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
     * @param array $options CLI options
     * @param Output $output The output handler
     * @param GitHelper $gitHelper Git helper for operations
     * @param GitCheckoutDirectory|null $checkoutDir Checkout directory context (optional, for --all-repos)
     */
    public function __construct(
        private readonly EffectiveConfigProvider $config,
        private readonly array $arguments,
        private readonly array $options,
        private readonly Output $output,
        private readonly GitHelper $gitHelper,
        private readonly ?GitCheckoutDirectory $checkoutDir = null
    ) {
        // Try new name first, then fall back to old name for backwards compatibility
        $this->gitRepoBase = $this->config->hasSetting('scm.repo.base')
            ? $this->config->getSetting('scm.repo.base')
            : ($this->config->hasSetting('git_repo_base')
                ? $this->config->getSetting('git_repo_base')
                : 'https://github.com/horde/');

        $checkoutDir = $this->config->hasSetting('checkout.dir')
            ? $this->config->getSetting('checkout.dir')
            : '/srv/git';

        // Normalize path: remove trailing slash to avoid double slashes when concatenating
        $this->localCheckoutDir = rtrim($checkoutDir, '/');
    }

    /**
     * Normalize component name to include vendor prefix
     *
     * @param string $component Component name (e.g., "bundle" or "horde/bundle")
     * @return string Component with vendor prefix (e.g., "horde/bundle")
     */
    private function normalizeComponentName(string $component): string
    {
        // Add vendor prefix if not present (assume horde)
        if (!str_contains($component, '/')) {
            return 'horde/' . $component;
        }
        return $component;
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
            $component = $this->normalizeComponentName($this->arguments[2]);
            $branch = $this->arguments[4] ?? '';
            $componentDir = $this->localCheckoutDir . '/' . $component . '/';
            // Extract component name for clone URL (without vendor prefix)
            $componentName = basename($component);
            $cloneUrl = $this->gitRepoBase . '/' . $componentName . '.git';
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
            $isAllRepos = !empty($this->options['all-repos']);
            $pattern = $this->options['pattern'] ?? null;

            // Validate: --all-repos and --pattern are mutually exclusive
            if ($isAllRepos && $pattern) {
                $this->output->error('--all-repos and --pattern are mutually exclusive');
                $this->output->help('Use --pattern to filter repos, OR --all-repos for all repos');
                return;
            }

            if ($isAllRepos || $pattern) {
                // Multi-repo mode: checkout branch --all-repos OR checkout branch --pattern="X"
                if ($this->checkoutDir === null) {
                    $this->output->error('Multi-repo operations require GitCheckoutDirectory dependency');
                    return;
                }

                if (count($this->arguments) < 3) {
                    $this->output->error('Branch name required');
                    $this->output->help('Usage: checkout [branch] --all-repos OR checkout [branch] --pattern="pattern"');
                    return;
                }

                $branch = $this->arguments[2];
                $repos = [];

                foreach ($this->checkoutDir->getGitDirs() as $componentDir) {
                    $repoPath = (string) $componentDir;
                    $repoName = basename($repoPath);

                    // Apply pattern filter if provided
                    if ($pattern !== null && !fnmatch($pattern, $repoName)) {
                        continue;
                    }

                    $repos[] = $repoPath;
                }

                if (empty($repos)) {
                    $this->output->warn('No repositories found matching criteria');
                    return;
                }

                foreach ($repos as $componentDir) {
                    $componentName = basename($componentDir);
                    $this->output->info("Checking out {$branch} in {$componentName}...");
                    $this->gitHelper->workflowCheckout(
                        $this->output,
                        $componentDir,
                        $branch
                    );
                }
            } else {
                // Single component mode: checkout component branch
                if (count($this->arguments) < 4) {
                    $this->output->help('Usage: checkout [component] [branch] OR checkout [branch] --all-repos OR checkout [branch] --pattern="pattern"');
                    return;
                }

                $component = $this->normalizeComponentName($this->arguments[2]);
                $branch = $this->arguments[3];
                $componentDir = $this->localCheckoutDir . '/' . $component . '/';

                $this->gitHelper->workflowCheckout(
                    $this->output,
                    $componentDir,
                    $branch
                );
            }
            return;
        }
        if ($this->arguments[1] == 'fetch') {
            $isAllRepos = !empty($this->options['all-repos']);
            $pattern = $this->options['pattern'] ?? null;

            // Validate: --all-repos and --pattern are mutually exclusive
            if ($isAllRepos && $pattern) {
                $this->output->error('--all-repos and --pattern are mutually exclusive');
                $this->output->help('Use --pattern to filter repos, OR --all-repos for all repos');
                return;
            }

            if ($isAllRepos || $pattern) {
                // Multi-repo mode
                if ($this->checkoutDir === null) {
                    $this->output->error('Multi-repo operations require GitCheckoutDirectory dependency');
                    return;
                }

                $repos = [];
                foreach ($this->checkoutDir->getGitDirs() as $componentDir) {
                    $repoPath = (string) $componentDir;
                    $repoName = basename($repoPath);

                    // Apply pattern filter if provided
                    if ($pattern !== null && !fnmatch($pattern, $repoName)) {
                        continue;
                    }

                    $repos[] = $repoPath;
                }

                if (empty($repos)) {
                    $this->output->warn('No repositories found matching criteria');
                    return;
                }

                foreach ($repos as $componentDir) {
                    $componentName = basename($componentDir);
                    $this->output->info("Fetching {$componentName}...");
                    $this->gitHelper->fetch($componentDir);
                }
            } else {
                // Single component mode
                if (count($this->arguments) < 3) {
                    $this->output->help('Usage: fetch [component] OR fetch --all-repos OR fetch --pattern="pattern"');
                    return;
                }

                $component = $this->normalizeComponentName($this->arguments[2]);
                $componentDir = $this->localCheckoutDir . '/' . $component . '/';
                $this->gitHelper->fetch($componentDir);
            }
            return;
        }
        if ($this->arguments[1] == 'branch') {
            if (count($this->arguments) != 5) {
                $this->output->help('branch currently only supports a fixed format');
                $this->output->help('branch [component] [branch] [source branch]');
                return;
            }
            [$git, $action, $component, $branch, $source] = $this->arguments;
            $component = $this->normalizeComponentName($component);
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
            $component = $this->normalizeComponentName($component);
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
            $component = $this->normalizeComponentName($component);
            $componentDir = $this->localCheckoutDir . '/' . $component . '/';
            $this->gitHelper->push($componentDir);
            return;
        }
        $this->output->warn("Could not understand your command:");
        $this->output->warn(implode(" ", $this->arguments));
    }
}
