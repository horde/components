<?php
/**
 * Components_Release_Tasks:: organizes the different tasks required for
 * releasing a package.
 *
 * PHP Version 7
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

namespace Horde\Components\Release;

use Horde\Components\Component;
use Horde\Components\Dependencies;
use Horde\Components\Exception;
use Horde\Components\Helper\Commit as HelperCommit;
use Horde\Components\Release\Task\Base as TaskBase;

/**
 * Components_Release_Tasks:: organizes the different tasks required for
 * releasing a package.
 *
 * Copyright 2011-2024 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Tasks
{
    /**
     * The options for the current release run.
     */
    private array $_options = [];

    /**
     * The sequence for the current release run.
     */
    private array $_sequence = [];

    /**
     * Constructor.
     *
     * @param Dependencies $_dependencies The task factory.
     */
    public function __construct(private readonly Dependencies $_dependencies)
    {
    }

    /**
     * Return the named task.
     *
     * @param string               $name      The name of the task.
     * @param Component $component The component to be released.
     *
     * @return TaskBase The task.
     */
    public function getTask($name, Component $component): TaskBase
    {
        $task = $this->_dependencies->getInstance(
            'Horde\Components\Release\Task\\' . ucfirst($name)
        );
        $task->setComponent($component);
        $task->setName($name);
        $deps = [];
        foreach ($task->askDependencies() as $key => $dependency) {
            try {
                $deps[$key] = $this->_dependencies->getInstance($dependency);
            } catch (\Horde_Exception) {
                // what to do here?
            }
        }
        $task->setDependencies($deps);
        return $task;
    }

    /**
     * Run a sequence of release tasks.
     *
     * @param array         $sequence  The task sequence.
     * @param Component     $component The component to be released.
     * @param array         $options   Additional options.
     *
     * @throws Exception
     */
    public function run(
        array $sequence,
        Component $component,
        $options = []
    ): void {
        $this->_options = $options;
        $this->_sequence = $sequence;
        $taskSequence = [];
        // check for predefined pipelines
        if ((count($sequence) == 2) &&
            $sequence[0] == 'pipeline:'
        ) {
            $pipeline = $sequence[1];
            $this->_dependencies->getOutput()->info("Running Pipeline $pipeline");
            foreach ($options['pipeline']['release'][$pipeline] as $task) {
                if (empty($task['name'])) {
                    $this->_dependencies->getOutput()->warn('Pipeline contained an empty or unnamed step');
                    continue;
                }
                $taskSequence[] = $this->getTask($task['name'], $component);
                if (in_array($task['name'], ['CommitPreRelease', 'CommitPostRelease'])) {
                    $options['commit'] = new HelperCommit(
                        $this->_dependencies->getOutput(),
                        $options
                    );
                }
                $extraOptions[] = empty($task['options']) ? [] : $task['options'];
            }
        } else {
            // default or commandline sequences
            foreach ($sequence as $name) {
                $taskSequence[] = $this->getTask($name, $component);
                // ensure old and new format work the same
                $extraOptions[] = [];
            }
        }
        $selectedTasks = [];
        foreach ($taskSequence as $index => $task) {
            // Note: Extra options override global options - which is OK. But they also override CLI options.
            $taskOptions = array_merge($options, $extraOptions[$index]);
            $taskErrors = $task->preValidate($taskOptions);
            if (!empty($taskErrors)) {
                if ($task->skip($taskOptions)) {
                    $this->_dependencies->getOutput()->warn(
                        sprintf(
                            "Deactivated task \"%s\":\n\n%s",
                            $task->getName(),
                            join("\n", $taskErrors)
                        )
                    );
                } else {
                    $this->_dependencies->getOutput()->error(
                        sprintf(
                            "Precondition for task \"%s\" failed:\n\n%s",
                            $task->getName(),
                            join("\n", $taskErrors)
                        )
                    );
                }
            } else {
                $selectedTasks[] = $task;
                $selectedOptions[] = $taskOptions;
            }
        }
        if (!empty($errors)) {
            throw new Exception(
                "Unable to release:\n\n" . join("\n", $errors)
            );
        }
        foreach ($selectedTasks as $index => $task) {
            $taskOptions = $selectedOptions[$index];
            $task->run($taskOptions);
            $taskErrors = $task->postValidate($taskOptions);
            if (!empty($taskErrors)) {
                $this->_dependencies->getOutput()->fail(
                    sprintf(
                        "Task %d \"%s\" failed:\n\n%s",
                        $index,
                        $task->getName(),
                        join("\n", $taskErrors)
                    )
                );
            }
        }
    }

    /**
     * Is the current run operating in "pretend" mode?
     *
     * @return bool True in case we should be pretending.
     */
    public function pretend(): bool
    {
        return !empty($this->_options['pretend']);
    }

    /**
     * Is the specified task active for the current run?
     *
     * @param string $task The task name.
     *
     * @return bool True in case the task is active.
     */
    public function isTaskActive($task): bool
    {
        return in_array($task, $this->_sequence);
    }
}
