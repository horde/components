<?php
/**
 * Components_Module_Release:: generates a release.
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
use Horde\Components\Exception;

/**
 * Components_Module_Release:: generates a release.
 *
 * Copyright 2011-2021 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Release extends Base
{
    public function getOptionGroupTitle(): string
    {
        return 'Package release';
    }

    public function getOptionGroupDescription(): string
    {
        return 'This module releases a new version for the specified package';
    }

    public function getOptionGroupOptions(): array
    {
        return [new \Horde\Argv\Option(
            '-r',
            '--release',
            ['action' => 'store_true', 'help'   => 'Release the next version of the package.']
        ), new \Horde\Argv\Option(
            '-M',
            '--releaseserver',
            ['action' => 'store', 'help'   => 'The remote server SSH connection string. The release package will be copied here via "scp".']
        ), new \Horde\Argv\Option(
            '-U',
            '--releasedir',
            ['action' => 'store', 'help'   => 'PEAR server target directory on the remote machine.']
        ), new \Horde\Argv\Option(
            '--next-version',
            ['action' => 'store', 'help'   => 'The version number planned for the next release of the component.']
        ), new \Horde\Argv\Option(
            '--version-part',
            ['action' => 'store', 'help'   => 'Select the version part that should be incremented if no version is specified. Either "minor" or "patch" (default)']
        ), new \Horde\Argv\Option(
            '--next-note',
            ['action' => 'store', 'default' => '', 'help'   => 'Initial change log note for the next version of the component [default: empty entry].']
        ), new \Horde\Argv\Option(
            '--next-apistate',
            ['action' => 'store', 'help'   => 'The next API stability [default: no change].']
        ), new \Horde\Argv\Option(
            '--next-relstate',
            ['action' => 'store', 'help'   => 'The next release stability [default: no change].']
        ), new \Horde\Argv\Option(
            '--from',
            ['action' => 'store', 'help'   => 'The sender address for mailing list announcements.']
        ), new \Horde\Argv\Option(
            '--horde-user',
            ['action' => 'store', 'help'   => 'The username for accessing bugs.horde.org.']
        ), new \Horde\Argv\Option(
            '--horde-pass',
            ['action' => 'store', 'help'   => 'The password for accessing bugs.horde.org.']
        ), new \Horde\Argv\Option(
            '--web-dir',
            ['action' => 'store', 'help'   => 'The directory of a horde-web checkout.']
        ), new \Horde\Argv\Option(
            '--dump',
            ['action' => 'store_true', 'help'   => 'Prints the release notes only.']
        )];
    }

    /**
     * Get the usage title for this module.
     *
     * @return string The title.
     */
    public function getTitle(): string
    {
        return 'release';
    }

    /**
     * Get the usage description for this module.
     *
     * @return string The description.
     */
    public function getUsage(): string
    {
        return 'Releases a component.';
    }

    /**
     * Return the action arguments supported by this module.
     *
     * @return array A list of supported action arguments.
     */
    public function getActions(): array
    {
        return ['release'];
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
        return 'Releases the component. This handles a number of automated steps usually required when releasing a package. In the most simple situation it will be sufficient to move to the directory of the component you wish to release and run
For a classic H5 pear release
  horde-components release
For running a release pipeline from conf.php
  horde-components release for :pipeline
For running the horde H6 release pipeline
  horde-components release for h6
This should perform all required actions. Sometimes it might be necessary to avoid some of the steps that are part of the release process. This can be done by adding additional arguments after the "release" keyword. Each argument indicates that the corresponding task should be run.

The available tasks are:

 - unittest    : Perform unittests on the package.
 - timestamp   : Timestamp the release.
 - changelog   : Update the change logs.
 - sentinel    : Update the sentinels in doc/CHANGES and lib/Application.php.
 - commit      : Commit any changes with an automated message.
 - package     : Prepare a *.tgz package.
   - upload    : Upload the package to pear.horde.org
 - tag         : Add a git release tag.
 - announce    : Announce the release on the mailing lists.
 - website     : Add the new release on www.horde.org
 - bugs        : Add the new release on bugs.horde.org
 - next        : Update package.xml with the next version.
 - nextsentinel: Update the sentinels for the next version as well.

The indentation indicates task that depend on a parent task. Activating them without activating the parent has no effect.

The following example would generate the package and add the release tag to git without any other release task being performed:

  horde-components release package tag';
    }

    /**
     * Return the options that should be explained in the context help.
     *
     * @return array A list of option help texts.
     */
    public function getContextOptionHelp(): array
    {
        return ['--pretend' => '', '--config' => '', '--releaseserver' => '', '--releasedir' => '', '--next-note' => '', '--next-version' => '', '--version-part' => '', '--next-relstate' => '', '--next-apistate' => '', '--from' => '', '--horde-user' => '', '--horde-pass' => '', '--web-dir' => '', '--dump' => ''];
    }

    /**
     * Determine if this module should act. Run all required actions if it has
     * been instructed to do so.
     *
     * @param Config $config The configuration.
     *
     * @return bool True if the module performed some action.
     * @throws Exception
     */
    public function handle(Config $config): bool
    {
        $options = $config->getOptions();
        if (!empty($options['dump'])) {
            $config->setOption('pretend', true);
        }
        $arguments = $config->getArguments();
        if (!empty($options['release']) ||
            (isset($arguments[0]) && $arguments[0] == 'release')) {
            $this->dependencies->getRunnerRelease()->run();
            return true;
        }
        return false;
    }
}
