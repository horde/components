<?php

/**
 * Components_Module_Change:: records a change log entry.
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
use Horde\Components\ConfigProvider\ConfigProviderFactory;
use Horde\Components\Dependencies;
use Horde\Components\Component\ComponentDirectory;
use Horde\Components\RuntimeContext\CurrentWorkingDirectory;
use Horde\Components\Runner\Change as RunnerChange;
use Horde\Components\Output;

/**
 * Components_Module_Change:: records a change log entry.
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
class Change extends Base
{
    public function __construct(Dependencies $dependencies)
    {
        parent::__construct($dependencies);
    }


    public function getOptionGroupTitle(): string
    {
        return 'Change log';
    }

    public function getOptionGroupDescription(): string
    {
        return 'This module records a change log entry in doc/changelog.yml and package.xml (and doc/CHANGES in case it exists).';
    }

    public function getOptionGroupOptions(): array
    {
        return [];
    }

    /**
     * Get the usage title for this module.
     *
     * @return string The title.
     */
    public function getTitle(): string
    {
        return 'changed';
    }

    /**
     * Get the usage description for this module.
     *
     * @return string The description.
     */
    public function getUsage(): string
    {
        return 'Add a change log entry.';
    }

    /**
     * Return the action arguments supported by this module.
     *
     * @return array A list of supported action arguments.
     */
    public function getActions(): array
    {
        return ['changed'];
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
        return 'This module records a change log entry in doc/changelog.yml, package.xml, and
doc/CHANGES in case they exist.

Move into the directory of the component you wish to record a change for
and run

  horde-components changed "[xyz] Fixed issue #99999"

If you want to commit the change log entry immediately you can run the
command with the "--commit" flag:

  horde-components changed --commit "[xyz] Fixed issue #99999"

This will use the change log message as commit message. You might wish to
ensure that you already added all changes you want to mark with that
commit message by using "git add ..." before.

If you edited changelog.yml manually you can run the command without an entry
to just update package.xml and doc/CHANGES:

  horde-components changed';
    }

    /**
     * Return the options that should be explained in the context help.
     *
     * @return array A list of option help texts.
     */
    public function getContextOptionHelp(): array
    {
        return ['--commit' => 'Commit the change log entries to git (using the change log entry as commit message).', '--pretend' => ''];
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
        if (isset($arguments[0]) && $arguments[0] == 'changed') {
            // Resolve component from working directory
            $componentDirectory = new ComponentDirectory(new CurrentWorkingDirectory());
            $component = $this->dependencies
                ->getComponentFactory()
                ->createSource($componentDirectory);

            // Get dependencies
            $output = $this->dependencies->get(Output::class);

            // Instantiate and run runner
            $runner = new RunnerChange(
                $component,
                $arguments,
                $options,
                $output
            );
            $runner->run();
            return true;
        }
        return false;
    }
}
