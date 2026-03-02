<?php

/**
 * Components_Module_Pullrequest:: manages GitHub pull requests.
 *
 * Copyright 2013-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

declare(strict_types=1);

namespace Horde\Components\Module;

use Horde\Components\Component;
use Horde\Argv\Option;
use Horde\Components\Runner\Pullrequest as RunnerPullrequest;
use Horde\Components\Output;
use Horde\Components\Helper\Git as GitHelper;
use Horde\Components\Helper\GitHubChecker;
use Horde\Components\Helper\PullRequestManager;

/**
 * Components_Module_Pullrequest:: manages GitHub pull requests.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Pullrequest extends Base
{
    /**
     * Return the title for the option group representing this module.
     *
     * @return string The group title.
     */
    public function getOptionGroupTitle(): string
    {
        return 'Pull Request Management';
    }

    /**
     * Return the description for the option group representing this module.
     *
     * @return string The group description.
     */
    public function getOptionGroupDescription(): string
    {
        return 'This module manages GitHub pull requests directly from the CLI.';
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
                '--pr',
                ['action' => 'store_true', 'help' => 'Pull request operations']
            ),
            new Option(
                '--pullrequest',
                ['action' => 'store_true', 'help' => 'Pull request operations (alias)']
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
        return 'pullrequest';
    }

    /**
     * Get the usage description for this module.
     *
     * @return string The description.
     */
    public function getUsage(): string
    {
        return 'Manage GitHub pull requests';
    }

    /**
     * Return the action arguments supported by this module.
     *
     * @return array A list of supported action arguments.
     */
    public function getActions(): array
    {
        return ['pullrequest', 'pr'];
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
        return 'Pull Request Management

Manage GitHub pull requests directly from the CLI.

Subcommands:
  pr list                  - List pull requests for the current repository
  pr checkout <id>         - Check out a pull request branch locally
  pr approve [id]          - Approve a pull request (current or specified)
  pr block [id]            - Request changes on a pull request
  pr merge [id]            - Merge a pull request
  pr close [id]            - Close a pull request without merging
  pr reopen [id]           - Reopen a closed pull request

Examples:
  horde-components pr list
  horde-components pr checkout 123
  horde-components pr checkout "#123"      (use quotes for # prefix)
  horde-components pr approve
  horde-components pr merge 123

Note: The shell interprets # as a comment. Use quotes: pr checkout "#123"';
    }

    /**
     * Return the options that should be explained in the context help.
     *
     * @return array A list of option help texts.
     */
    public function getContextOptionHelp(): array
    {
        return [
            '--pr' => 'Pull request operations',
            '--pullrequest' => 'Pull request operations (alias)',
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
        // Check if this module should handle the request
        if (isset($arguments[0]) && in_array($arguments[0], ['pr', 'pullrequest'])) {
            // Get dependencies
            $output = $this->dependencies->get(Output::class);
            $gitHelper = $this->dependencies->get(GitHelper::class);
            $githubChecker = $this->dependencies->get(GitHubChecker::class);
            $prManager = $this->dependencies->get(PullRequestManager::class);

            // Working directory defaults to current working directory
            $workingDir = getcwd();
            if ($workingDir === false) {
                $output->error('Could not determine current working directory');
                return false;
            }

            // Instantiate and run runner
            $runner = new RunnerPullrequest(
                $arguments,
                $workingDir,
                $output,
                $gitHelper,
                $githubChecker,
                $prManager
            );
            $runner->run();
            return true;
        }

        return false;
    }
}
