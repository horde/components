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
 * @author   Ralf Lang <lang@b1-systems.de>
 * @license  http://www.fsf.org/copyleft/lgpl.html LGPL
 */
namespace Horde\Components\Module;
use Horde\Components\Config;

/**
 * Horde\Components\Module\Git:: Useful git command wrappers for CI
 *
 * Copyright 2020-2021 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.fsf.org/copyleft/lgpl.html.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <lang@b1-systems.de>
 * @license  http://www.fsf.org/copyleft/lgpl.html LGPL
 */
class Git extends Base
{
    /**
     * Return the title for the option group representing this module.
     *
     * @return string The group title.
     */
    public function getOptionGroupTitle()
    {
        return 'Git Workflows';
    }

    /**
     * Return the description for the option group representing this module.
     *
     * @return string The group description.
     */
    public function getOptionGroupDescription()
    {
        return 'This module performs SCM operations.';
    }

    /**
     * Return the options for this module.
     *
     * @return array The group options.
     */
    public function getOptionGroupOptions()
    {
        return array(
            new \Horde_Argv_Option(
                '--git-bin',
                array(
                    'action' => 'store_true',
                    'help'   => 'Path to git binary.'
                )
            )
        );
    }

    /**
     * Get the usage title for this module.
     *
     * @return string The title.
     */
    public function getTitle()
    {
        return 'git';
    }

    /**
     * Get the usage description for this module.
     *
     * @return string The description.
     */
    public function getUsage()
    {
        return 'Run git workflows';
    }

    /**
     * Return the action arguments supported by this module.
     *
     * @return array A list of supported action arguments.
     */
    public function getActions()
    {
        return array('git');
    }

    /**
     * Return the help text for the specified action.
     *
     * @param string $action The action.
     *
     * @return string The help text.
     */
    public function getHelp($action)
    {
        return 'Run Git Actions

        clone [component] [branch]
          Clone a component from an online repo
        checkout [component] [branch]
          Locally checkout a branch
        update-branch [component] [branch] [source branch]
          Locally update a branch from another branch
        ';
    }

    /**
     * Return the options that should be explained in the context help.
     *
     * @return array A list of option help texts.
     */
    public function getContextOptionHelp()
    {
        return array(
            '--git-bin' => 'Path to git binary',
        );
    }

    /**
     * Determine if this module should act. Run all required actions if it has
     * been instructed to do so.
     *
     * @param Config $config The configuration.
     *
     * @return boolean True if the module performed some action.
     */
    public function handle(Config $config)
    {
        $options = $config->getOptions();
        $arguments = $config->getArguments();
        if (!empty($options['git'])
            || (isset($arguments[0]) && $arguments[0] == 'git')) {
            $this->_dependencies->getRunnerGit()->run();
            return true;
        }
    }
}
