<?php
/**
 * Components_Release_Task_Base:: provides core functionality for release tasks.
 *
 * PHP Version 7
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

namespace Horde\Components;

use Horde\Components\Component\Source as ComponentSource;
use Horde\Components\Component\Task\Dependencies;
use Horde\Components\Component\Task\SystemCall;
use Horde\Components\Exception;
use Horde\Components\Output;
use Horde\Components\Release\Notes as ReleaseNotes;
use Horde\Components\Release\Tasks as ReleaseTasks;

/**
 * Common interface for tasks used in Release, Qc and Pipeline
 *
 * Copyright 2011-2020 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
interface TaskInterface
{
    /**
     * Change the component this task should act upon.
     *
     * This might be needed if the component is not present before the task runs
     *
     * @param ComponentSource $component The component to be released.
     */
    public function setComponent(ComponentSource $component): void;

    /**
     * Get the name of this task.
     *
     * @return string The task name.
     */
    public function getName(): string;

    /**
     * Can the task be skipped?
     *
     * @param array $options Additional options.
     *
     * @return boolean True if it can be skipped.
     */
    public function skip($options);

    /**
     * Validate the preconditions required for this release task.
     *
     * @param array $options Additional options.
     *
     * @return array An empty array if all preconditions are met and a list of
     *               error messages otherwise.
     */
    public function preValidate($options);

    /**
     * Validate the postconditions required for this release task to have
     * succeeded.
     *
     * @param array $options Additional options.
     *
     * @return array An empty array if all postconditions are met and a list of
     *               error messages otherwise.
     */
    public function postValidate($options);

    /**
     * Run the task.
     *
     * @param array &$options Additional options.
     */
    public function run(&$options);
}
