<?php
/**
 * Horde\Components\Module\Git:: Useful git command wrappers for CI
 *
 * Some code inherited from the Commit helper by Gunnar Wrobel
 * and the horde/git-tools codebase by Michael Rubinsky
 *
 * PHP Version 7
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.fsf.org/copyleft/lgpl.html LGPL
 */

namespace Horde\Components\Module;

use Horde\Components\Config;
use Horde\Components\Runner\Git as RunnerGit;
use Horde\Components\Runner\Github as RunnerGithub;

/**
 * Horde\Components\Module\Git:: Useful git command wrappers for CI
 *
 * Copyright 2020-2024 Horde LLC (http://www.horde.org/)
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
     * @param Config $config The configuration.
     *
     * @return bool True if the module performed some action.
     */
    public function handle(Config $config): bool
    {
        $options = $config->getOptions();
        $arguments = $config->getArguments();
        if (!empty($options['github-clone-org'])
            || (isset($arguments[0]) && $arguments[0] == 'github-clone-org')) {
            $this->dependencies->get(RunnerGithub::class)->run();
            return true;
        }
        if (!empty($options['git'])
            || (isset($arguments[0]) && $arguments[0] == 'git')) {
            $this->dependencies->get(RunnerGit::class)->run();
            return true;
        }
        return false;
    }
}
