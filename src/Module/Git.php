<?php

/**
 * Horde\Components\Module\Git:: Useful git command wrappers for CI
 *
 * Some code inherited from the Commit helper by Gunnar Wrobel
 * and the horde/git-tools codebase by Michael Rubinsky
 *
 * PHP version 8.2+
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.fsf.org/copyleft/lgpl.html LGPL
 */

declare(strict_types=1);

namespace Horde\Components\Module;

use Horde\Argv\Option;
use Horde\Components\Component;
use Horde\Components\ConfigProvider\ConfigProviderFactory;
use Horde\Components\Helper\Shell;
use Horde\Components\Runner\Git as RunnerGit;
use Horde\Components\Runner\Github as RunnerGithub;
use Horde\Components\Runner\GitSyncAll;
use Horde\Components\Output;
use Horde\Components\Helper\Git as GitHelper;
use Horde\GithubApiClient\GithubApiClient;
use Horde\Components\RuntimeContext\GitCheckoutDirectory;

/**
 * Horde\Components\Module\Git:: Useful git command wrappers for CI
 *
 * Copyright 2020-2026 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.fsf.org/copyleft/lgpl.html.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.fsf.org/copyleft/lgpl.html LGPL
 */
class Git extends Base
{
    /**
     * Return the title for the option group representing this module.
     *
     * @return string The group title.
     */
    public function getOptionGroupTitle(): string
    {
        return 'Git Workflows';
    }

    /**
     * Return the description for the option group representing this module.
     *
     * @return string The group description.
     */
    public function getOptionGroupDescription(): string
    {
        return 'This module performs SCM operations.';
    }

    /**
     * Return the options for this module.
     *
     * @return array The group options.
     */
    public function getOptionGroupOptions(): array
    {
        return [
            new Option(
                '--git-bin',
                ['action' => 'store', 'help'   => 'Path to git binary.']
            ),
            new Option(
                '--detect-differences',
                ['action' => 'store_true', 'help' => 'Detect differences between GitHub and local repositories.']
            ),
            new Option(
                '--sync',
                ['action' => 'store_true', 'help' => 'Clone missing repositories after detection.']
            ),
            new Option(
                '--all-repos',
                ['action' => 'store_true', 'help' => 'Apply operation to all repositories in checkout directory.']
            ),
            new Option(
                '--pattern',
                ['action' => 'store', 'help' => 'Filter repositories by glob pattern (e.g., "Cli*").']
            ),
        ];
    }

    /**
     * Get the usage title for this module.
     *
     * @return string The title.
     */
    public function getTitle(): string
    {
        return 'git';
    }

    /**
     * Get the usage description for this module.
     *
     * @return string The description.
     */
    public function getUsage(): string
    {
        return 'Run git workflows';
    }

    /**
     * Get a short one-line description for command listings.
     *
     * @return string The short description.
     */
    public function getShortDescription(): string
    {
        return 'Run git workflows';
    }

    /**
     * Return the action arguments supported by this module.
     *
     * @return array A list of supported action arguments.
     */
    public function getActions(): array
    {
        return ['git', 'github-clone-org'];
    }

    /**
     * Return the help text for the specified action.
     *
     * @param string $action The action.
     *
     * @return string The help text.
     */
    public function getHelp($action): string
    {
        if ($action === 'github-clone-org') {
            return 'Clone and manage all repositories from GitHub organization

Clone all repositories from the Horde organization on GitHub into your local
checkout directory (default: ~/git/). This is the first step when setting up
a complete Horde development environment.

BASIC USAGE:
    horde-components github-clone-org

    Clones all repositories from the horde GitHub organization. If a repository
    already exists locally, it will be updated (fetch + rebase).

DETECT MISSING REPOSITORIES:
    horde-components github-clone-org --detect-differences

    Compares your local checkout with GitHub and reports:
    - Repositories on GitHub that are not cloned locally
    - Local directories that are not found on GitHub (orphaned)
    - Repositories that exist in both places

SYNC MISSING REPOSITORIES:
    horde-components github-clone-org --detect-differences --sync

    Detects differences and automatically clones missing repositories.

CONFIGURATION:
    The checkout directory can be configured in ~/.config/horde/components.php:

    \'checkout.dir\' => \'/path/to/checkout\',  // Default: ~/git

EXAMPLES:
    # Initial setup - clone all repos
    horde-components github-clone-org

    # Check if any repos are missing
    horde-components github-clone-org --detect-differences

    # Clone any missing repos
    horde-components github-clone-org --detect-differences --sync
        ';
        }

        return 'Run Git Actions

MULTI-REPOSITORY OPERATIONS:

For checking out all repositories from an organization
    horde-components github-clone-org

Detect differences between GitHub and local repositories
    horde-components github-clone-org --detect-differences

Detect and sync missing repositories
    horde-components github-clone-org --detect-differences --sync

Synchronize repositories (fetch, rebase, analyze)
    horde-components git sync                     # Sync current directory repo
    horde-components git sync -c Data             # Sync specific component
    horde-components git sync --all-repos         # Sync all repos
    horde-components git sync --pattern="Cli*"    # Sync repos matching pattern

Preview sync operations without executing
    horde-components git sync --all-repos --pretend

SINGLE COMPONENT OPERATIONS:

Clone a component from an online repo
    horde-components git clone [component] [branch]

Fetch metadata from all remotes, including tags
    horde-components git fetch [component]        # Single repo
    horde-components git fetch -c Data            # Fetch specific component
    horde-components git fetch --all-repos        # All repos
    horde-components git fetch --pattern="Cli*"   # Repos matching pattern

Locally checkout a branch
    horde-components git checkout [component] [branch]    # Single repo
    horde-components git checkout [branch] -c Data        # Checkout in specific component
    horde-components git checkout [branch] --all-repos    # All repos
    horde-components git checkout [branch] --pattern="Cli*"  # Repos matching pattern

Update a branch from another branch
    horde-components git branch [component] [branch] [source branch]

Write a tag to a branch
    horde-components git tag [component] [branch] [tag] [comment]

Push a component to a remote
    horde-components git push [component] [remote]

NOTE: --all-repos and --pattern are mutually exclusive.
      --pattern implies operating on all repos that match the pattern.
      -c/--component specifies a single component by name.
        ';
    }

    /**
     * Return the options that should be explained in the context help.
     *
     * @return array A list of option help texts.
     */
    public function getContextOptionHelp(): array
    {
        return [
            '--git-bin' => 'Path to git binary',
            '--detect-differences' => 'Detect differences between GitHub and local repositories',
            '--sync' => 'Clone missing repositories after detection',
            '--all-repos' => 'Apply operation to all repositories in checkout directory',
            '--pattern' => 'Filter repositories by glob pattern',
        ];
    }

    /**
     * Determine if this module should act. Run all required actions if it has
     * been instructed to do so.
     *
     * @param array $options CLI options
     * @param array $arguments CLI arguments
     * @param Component|null $component The selected component (if any)
     *
     * @return bool True if the module performed some action.
     */
    public function handle(array $options, array $arguments, ?Component $component = null): bool
    {
        $effectiveConfig = $this->dependencies->get(ConfigProviderFactory::class)->createDefault();
        $output = $this->dependencies->get(Output::class);
        $gitHelper = $this->dependencies->get(GitHelper::class);

        // Handle github-clone-org and github commands
        if ((isset($arguments[0]) && $arguments[0] == 'github-clone-org')
            || (isset($arguments[0]) && $arguments[0] == 'github')) {
            $client = $this->dependencies->get(GithubApiClient::class);
            $checkoutDir = $this->dependencies->get(GitCheckoutDirectory::class);

            $runner = new RunnerGithub(
                $effectiveConfig,
                $arguments,
                $options,
                $output,
                $gitHelper,
                $client,
                $checkoutDir
            );
            $runner->run();
            return true;
        }

        // Handle git sync command (replaces sync-all)
        if (isset($arguments[0]) && $arguments[0] == 'git'
            && isset($arguments[1]) && ($arguments[1] == 'sync' || $arguments[1] == 'sync-all')) {
            $checkoutDir = $this->dependencies->get(GitCheckoutDirectory::class);
            $shell = $this->dependencies->get(Shell::class);

            $runner = new GitSyncAll(
                $checkoutDir,
                $gitHelper,
                $shell,
                $output
            );

            $dryRun = isset($options['pretend']) && $options['pretend'];
            $pattern = isset($options['pattern']) && $options['pattern'] !== '' ? $options['pattern'] : null;
            $allRepos = isset($options['all_repos']) && $options['all_repos'] === true;
            $componentFlag = $options['component'] ?? null;

            // Validate: --all-repos and --pattern are mutually exclusive
            if ($allRepos && $pattern !== null) {
                $output->error('--all-repos and --pattern are mutually exclusive');
                $output->plain('Use --all-repos for all repositories, OR --pattern="glob" to filter repositories by pattern.');
                return true;
            }

            // Validate: -c/--component incompatible with --all-repos/--pattern
            if ($componentFlag && ($allRepos || $pattern !== null)) {
                $output->error('-c/--component cannot be used with --all-repos or --pattern');
                $output->plain('Use -c/--component for a single repository, OR --all-repos/--pattern for multiple repositories.');
                return true;
            }

            // Determine single repo: from -c flag, or cwd if no pattern/all-repos
            $singleRepo = null;
            if ($componentFlag) {
                // Use -c flag to specify component
                $componentName = str_contains($componentFlag, '/') ? $componentFlag : 'horde/' . $componentFlag;
                $singleRepo = $effectiveConfig->getSetting('checkout.dir') . '/' . $componentName;

                if (!is_dir($singleRepo . '/.git')) {
                    $output->error("Not a git repository: {$singleRepo}");
                    return true;
                }
            } elseif (!$pattern && !$allRepos) {
                // Try to detect git repo in current directory
                $cwd = getcwd();
                if ($cwd && is_dir($cwd . '/.git')) {
                    $singleRepo = $cwd;
                } else {
                    $output->error('Not in a git repository. Use -c/--component, --all-repos, or --pattern, or run from a repo directory.');
                    return true;
                }
            }

            $runner->run($dryRun, $pattern, $allRepos, $singleRepo);
            return true;
        }

        // Handle git commands
        if (isset($arguments[0]) && $arguments[0] == 'git') {
            $checkoutDir = $this->dependencies->get(GitCheckoutDirectory::class);

            $runner = new RunnerGit(
                $effectiveConfig,
                $arguments,
                $options,
                $output,
                $gitHelper,
                $checkoutDir
            );
            $runner->run();
            return true;
        }

        return false;
    }
}
