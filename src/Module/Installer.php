<?php
/**
 * Components_Module_Installer:: installs a Horde element including
 * its dependencies.
 *
 * PHP Version 7
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

namespace Horde\Components\Module;

use Horde\Components\Config;

/**
 * Components_Module_Installer:: installs a Horde element including
 * its dependencies.
 *
 * Copyright 2010-2024 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Installer extends Base
{
    /**
     * Return the title for the option group representing this module.
     *
     * @return string The group title.
     */
    public function getOptionGroupTitle(): string
    {
        return 'Installer';
    }

    /**
     * Return the description for the option group representing this module.
     *
     * @return string The group description.
     */
    public function getOptionGroupDescription(): string
    {
        return 'This module installs a Horde component including its dependencies.';
    }

    /**
     * Return the options for this module.
     *
     * @return array The group options.
     */
    public function getOptionGroupOptions(): array
    {
        return [new \Horde\Argv\Option(
            '-i',
            '--install',
            ['action' => 'store_true', 'help'   => 'Install the selected element into the PEAR environment indicated with the --destination option.']
        ), new \Horde\Argv\Option(
            '--nodeps',
            ['action' => 'store_true', 'help'   => 'Ignore package dependencies and just install the specified package.']
        ), new \Horde\Argv\Option(
            '-S',
            '--sourcepath',
            ['action' => 'store', 'help'   => 'Location of downloaded PEAR packages. Specifying this path allows you to avoid accessing the network for installing new packages.']
        ), new \Horde\Argv\Option(
            '-X',
            '--channelxmlpath',
            ['action' => 'store', 'help'   => 'Location of static channel XML descriptions. These files need to be named CHANNEL.channel.xml (e.g. pear.php.net.channel.xml). Specifying this path allows you to avoid accessing the network for installing new channels. If this is not specified but SOURCEPATH is given then SOURCEPATH will be checked for such channel XML files.']
        ), new \Horde\Argv\Option(
            '--build-distribution',
            ['action' => 'store_true', 'help'   => 'Download all elements required for installation to SOURCEPATH and CHANNELXMLPATH. If those paths have been left undefined they will be created automatically at DESTINATION/distribution if you activate this flag.']
        ), new \Horde\Argv\Option(
            '--instructions',
            ['action' => 'store', 'help'   => 'Points to a file that contains per-package installation instructions. This is a plain text file that holds a package identifier per line. You can either specify packages by name (e.g. PEAR), by a combination of channel and name (e.g. pear.php.net/PEAR), a channel name (e.g. channel:pear.php.net), or all packages by the special keyword ALL. The package identifier is followed by a set of options that can be any keyword of the following: include,exclude,symlink,git,snapshot,stable,beta,alpha,devel,force,nodeps.

      These have the following meaning:

       - include:  Include optional package(s) into the installation.
       - exclude:  Exclude optional package(s) from installation.
       - git:      Prefer installing from a source component.
       - snapshot: Prefer installing from a snapshot in the SOURCEPATH.
       - stable:   Prefer a remote package of stability "stable".
       - beta:     Prefer a remote package of stability "beta".
       - alpha:    Prefer a remote package of stability "alpha".
       - devel:    Prefer a remote package of stability "devel".
       - symlink:  Symlink a source component rather than copying it.
       - force:    Force the PEAR installer to install the package.
       - nodeps:   Instruct the PEAR installer to ignore dependencies.

      The INSTRUCTIONS file could look like this (ensure the identifiers move from less specific to more specific as the latter options will overwrite previous instructions in case both identifier match a compnent):

       ALL: symlink
       \Horde_Test: exclude
']
        ), new \Horde\Argv\Option(
            '-H',
            '--horde-dir',
            ['action' => 'store', 'help'   => 'The location of the horde installation directory. The default will be the DESTINATION/horde directory']
        )];
    }

    /**
     * Get the usage title for this module.
     *
     * @return string The title.
     */
    public function getTitle(): string
    {
        return 'install';
    }

    /**
     * Get the usage description for this module.
     *
     * @return string The description.
     */
    public function getUsage(): string
    {
        return 'Install a component.';
    }

    /**
     * Return the action arguments supported by this module.
     *
     * @return array A list of supported action arguments.
     */
    public function getActions(): array
    {
        return ['install'];
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
        return 'This module installs the selected component (including its dependencies) into a target environment.';
    }

    /**
     * Return the options that should be explained in the context help.
     *
     * @return array A list of option help texts.
     */
    public function getContextOptionHelp(): array
    {
        return ['--destination' => 'The path to the target for the installation.', '--instructions' => '', '--horde-dir' => '', '--pretend' => '', '--nodeps' => '', '--build-distribution' => '', '--sourcepath' => '', '--channelxmlpath' => ''];
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
        if (!empty($options['install'])
            || (isset($arguments[0]) && $arguments[0] == 'install')) {
            $this->dependencies->getRunnerInstaller()->run();
            return true;
        }
        return false;
    }
}
