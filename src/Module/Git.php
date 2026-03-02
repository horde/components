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

use Horde\Components\Component;
use Horde\Components\ConfigProvider\ConfigProviderFactory;
use Horde\Components\Runner\Git as RunnerGit;
use Horde\Components\Runner\Github as RunnerGithub;
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
        return [new \Horde\Argv\Option(
            '--git-bin',
            ['action' => 'store', 'help'   => 'Path to git binary.']
        )];
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
     * Return the action arguments supported by this module.
     *
     * @return array A list of supported action arguments.
     */
    public function getActions(): array
    {
        return ['git'];
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
        return 'Run Git Actions

For checking out all repositories from an organization
    horde-components github-clone-org

Clone a component from an online repo
    horde-components git clone [component] [branch]

Fetch metadata from all remotes, including tags
    horde-components git fetch [component]

Locally checkout a branch
    horde-components git checkout [component] [branch]

Update a branch from another branch
    horde-components git branch [component] [branch] [source branch]

Write a tag to a branch
    horde-components git tag [component] [branch] [tag] [comment]

Push a component to a remote
    horde-components git push [component] [remote]
        ';
    }

    /**
     * Return the options that should be explained in the context help.
     *
     * @return array A list of option help texts.
     */
    public function getContextOptionHelp(): array
    {
        return ['--git-bin' => 'Path to git binary'];
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
                $output,
                $gitHelper,
                $client,
                $checkoutDir
            );
            $runner->run();
            return true;
        }

        // Handle git commands
        if (isset($arguments[0]) && $arguments[0] == 'git') {
            $runner = new RunnerGit(
                $effectiveConfig,
                $arguments,
                $output,
                $gitHelper
            );
            $runner->run();
            return true;
        }

        return false;
    }
}
