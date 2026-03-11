<?php

/**
 * Components_Module_Release:: generates a release.
 *
 * PHP Version 8.2+
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

declare(strict_types=1);

namespace Horde\Components\Module;

use Horde\Components\Component;
use Horde\Components\Exception;
use Horde\Argv\Option;
use Horde\Components\Component\ComponentDirectory;
use Horde\Components\RuntimeContext\CurrentWorkingDirectory;
use Horde\Components\Runner\Release as RunnerRelease;
use Horde\Components\Output;
use Horde\Components\Release\Tasks as ReleaseTasks;
use Horde\Components\Qc\Tasks as QcTasks;

/**
 * Components_Module_Release:: generates a release.
 *
 * Copyright 2011-2026 Horde LLC (http://www.horde.org/)
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
        return [new Option(
            '-r',
            '--release',
            ['action' => 'store_true', 'help'   => 'Release the next version of the package.']
        ), new Option(
            '-M',
            '--releaseserver',
            ['action' => 'store', 'help'   => 'The remote server SSH connection string. The release package will be copied here via "scp".']
        ), new Option(
            '-U',
            '--releasedir',
            ['action' => 'store', 'help'   => 'PEAR server target directory on the remote machine.']
        ), new Option(
            '--next-version',
            ['action' => 'store', 'help'   => 'The version number planned for the next release of the component.']
        ), new Option(
            '--version-part',
            ['action' => 'store', 'help'   => 'Select the version part that should be incremented if no version is specified. Either "minor" or "patch" (default)']
        ), new Option(
            '--next-note',
            ['action' => 'store', 'default' => '', 'help'   => 'Initial change log note for the next version of the component [default: empty entry].']
        ), new Option(
            '--next-apistate',
            ['action' => 'store', 'help'   => 'The next API stability [default: no change].']
        ), new Option(
            '--next-relstate',
            ['action' => 'store', 'help'   => 'The next release stability [default: no change].']
        ), new Option(
            '--from',
            ['action' => 'store', 'help'   => 'The sender address for mailing list announcements.']
        ), new Option(
            '--horde-user',
            ['action' => 'store', 'help'   => 'The username for accessing bugs.horde.org.']
        ), new Option(
            '--horde-pass',
            ['action' => 'store', 'help'   => 'The password for accessing bugs.horde.org.']
        ), new Option(
            '--web-dir',
            ['action' => 'store', 'help'   => 'The directory of a horde-web checkout.']
        ), new Option(
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
     * Get a short one-line description for command listings.
     *
     * @return string The short description.
     */
    public function getShortDescription(): string
    {
        return 'Release a component (h5 or h6 workflow)';
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
     * @param array $options CLI options
     * @param array $arguments CLI arguments
     * @param Component|null $component The selected component (if any)
     *
     * @return bool True if the module performed some action.
     * @throws Exception
     */
    public function handle(array $options, array $arguments, ?Component $component = null): bool
    {
        if (isset($arguments[0]) && $arguments[0] == 'release') {
            // Resolve component from working directory
            $componentDirectory = new ComponentDirectory(new CurrentWorkingDirectory());
            $component = $this->dependencies
                ->getComponentFactory()
                ->createSource($componentDirectory);

            // Get dependencies
            $output = $this->dependencies->get(Output::class);
            $releaseTasks = $this->dependencies->get(ReleaseTasks::class);
            $qcTasks = $this->dependencies->get(QcTasks::class);

            // Handle --dump option (sets pretend mode)
            if (!empty($options['dump'])) {
                $options['pretend'] = true;
            }

            // Instantiate and run runner
            $runner = new RunnerRelease(
                $component,
                $arguments,
                $options,
                $output,
                $releaseTasks,
                $qcTasks
            );
            $runner->run();
            return true;
        }
        return false;
    }
}
