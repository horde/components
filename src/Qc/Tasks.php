<?php

/**
 * Components_Qc_Tasks:: organizes the different tasks required for
 * releasing a package.
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

declare(strict_types=1);

namespace Horde\Components\Qc;

use Horde\Components\Component;
use Horde\Components\Dependencies;
use Horde\Components\Output;
use Horde\Components\Qc\Task\Base as TaskBase;
use Horde\Components\Task\Result;

/**
 * Components_Qc_Tasks:: organizes the different tasks required for
 * releasing a package.
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
class Tasks
{
    /**
     * The options for the current qc run.
     */
    private array $_options = [];

    /**
     * The sequence for the current qc run.
     */
    private array $_sequence = [];

    /**
     * Constructor.
     *
     * @param Dependencies $_dependencies The task factory.
     */
    public function __construct(private readonly Dependencies $_dependencies) {}

    /**
     * Check if we are in pretend mode.
     *
     * @return bool True if in pretend mode.
     */
    public function pretend(): bool
    {
        return !empty($this->_options['pretend']);
    }

    /**
     * Return the named task.
     *
     * @param string $name                    The name of the task.
     * @param Component $component The component to be checked.
     *
     * @return TaskBase The task.
     */
    public function getTask($name, Component $component): TaskBase
    {
        $task = $this->_dependencies->getInstance(
            'Horde\Components\Qc\Task\\' . ucfirst($name)
        );
        $task->setComponent($component);
        $task->setName($name);
        return $task;
    }

    /**
     * Run a sequence of qc tasks.
     *
     * @param array                $sequence The task sequence.
     * @param Component $component The component to be checked.
     * @param array                $options  Additional options.
     *
     * @return PipelineResult Per-task results keyed by task name. Used by
     *                        Module\Qc to decide whether to throw and to name
     *                        the failing modules in the summary.
     */
    public function run(
        array $sequence,
        Component $component,
        $options = []
    ): PipelineResult {
        $this->_options = $options;
        $this->_sequence = $sequence;

        $task_sequence = [];
        foreach ($sequence as $name) {
            $task_sequence[] = $this->getTask($name, $component);
        }
        $selected_tasks = [];
        $results = [];
        foreach ($task_sequence as $task) {
            $task_errors = $task->validate($options);
            if (!empty($task_errors)) {
                $output = $this->_dependencies->getInstance(Output::class);
                $output->plain('');
                $output->bold(str_repeat('-', 30));
                $this->_dependencies->getOutput()->warn(
                    sprintf(
                        "Deactivated task \"%s\":\n\n%s",
                        $task->getName(),
                        join("\n", $task_errors)
                    )
                );
                $output->bold(str_repeat('-', 30));
                $output->plain('');
                $results[$task->getName()] = Result::skipped(
                    join("\n", $task_errors)
                );
            } else {
                $selected_tasks[] = $task;
            }
        }
        $output = $this->_dependencies->getInstance(Output::class);
        foreach ($selected_tasks as $task) {
            $output->bold(str_repeat('-', 30));
            $output->ok(
                'Running ' . $task->getName() . ' on ' . $component->getName()
            );
            $output->plain('');

            $numErrors = max(0, (int) $task->run($options));
            $results[$task->getName()] = Result::qc($numErrors);

            $output->plain('');
            if ($numErrors == 1) {
                $output->warn("$numErrors error!");
            } elseif ($numErrors) {
                $output->warn("$numErrors errors!");
            }
            $output->bold(str_repeat('-', 30) . "\n");
        }
        return new PipelineResult($results);
    }
}
