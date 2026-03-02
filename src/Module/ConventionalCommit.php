<?php

/**
 * Components\Module\ConventionalCommit:: Handle conventional commits.
 *
 * PHP Version 8.2+
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

declare(strict_types=1);

namespace Horde\Components\Module;

use Horde\Components\Component;
use Horde\Components\Dependencies;
use Horde\Components\Runner\ConventionalCommit as RunnerConventionalCommit;
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
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class ConventionalCommit extends Base
{
    public function __construct(Dependencies $dependencies)
    {
        parent::__construct($dependencies);
    }


    public function getOptionGroupTitle(): string
    {
        return 'Conventional Commit';
    }

    public function getOptionGroupDescription(): string
    {
        return 'Extracts changelog and version information from git logs in Conventional Commit format.';
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
        return 'conventionalcommit';
    }

    /**
     * Get the usage description for this module.
     *
     * @return string The description.
     */
    public function getUsage(): string
    {
        return "[show|lastversion|nextversion] [--since=tag] - Read from git log";
    }

    /**
     * Return the action arguments supported by this module.
     *
     * @return array A list of supported action arguments.
     */
    public function getActions(): array
    {
        return ['conventionalcommit'];
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
        return "
        conventionalcommit [show] [--since=tag] - Summary of changes.
        conventionalcommit lastversion - Show the last version as of git tags.
        conventionalcommit 
        conventionalcommit nextversion - Show the next version as of git tags.";
    }

    /**
     * Return the options that should be explained in the context help.
     *
     * @return array A list of option help texts.
     */
    public function getContextOptionHelp(): array
    {
        return [
            //'--commit' => 'Commit the change log entries to git (using the change log entry as commit message).', '--pretend' => ''
            //
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
        if (isset($arguments[0]) && $arguments[0] == 'conventionalcommit') {
            // Get dependencies
            $output = $this->dependencies->get(Output::class);

            // Working directory defaults to current working directory
            $workingDir = getcwd();
            if ($workingDir === false) {
                $output->error('Could not determine current working directory');
                return false;
            }

            // Instantiate and run runner
            $runner = new RunnerConventionalCommit(
                $arguments,
                $workingDir,
                $output
            );
            $runner->run();
            return true;
        }
        return false;
    }
}
