<?php
/**
 * Components_Qc_Task_Base:: provides core functionality for qc tasks.
 *
 * PHP Version 7
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

namespace Horde\Components\Qc\Task;

use Horde\Components\Component;
use Horde\Components\Component\Task\SystemCall;
use Horde\Components\Config;
use Horde\Components\Output;
use Horde\Components\Qc\Tasks as QcTasks;
use Horde\Components\Release\Tasks as ReleaseTasks;

/**
 * Components_Qc_Task_Base:: provides core functionality for qc tasks.
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
class Base
{
    use SystemCall;
    /**
     * The component that should be checked
     */
    private ?Component $_component = null;

    /**
     * The task name.
     */
    private ?string $_name = null;

    /**
     * Constructor.
     *
     * @param Config $_config The configuration for the current job.
     * @param QcTasks $_tasks The task handler.
     * @param Output $_output Accepts output.
     */
    public function __construct(
        protected Config $_config,
        private readonly QcTasks $_tasks,
        private readonly Output $_output
    ) {
        if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
            require __DIR__ . '/../vendor/autoload.php';
        } elseif (file_exists('/../../../bundle/vendor/autoload.php')) {
            require __DIR__ . '/../../../bundle/vendor/autoload.php';
        }
    }

    /**
     * Set the component this task should act upon.
     *
     * @param Component $component The component to be checked.
     */
    public function setComponent(Component $component): void
    {
        $this->_component = $component;
    }

    /**
     * Get the component this task should act upon.
     *
     * @return Component The component to be checked.
     */
    protected function getComponent(): ?\Horde\Components\Component
    {
        return $this->_component;
    }

    /**
     * Set the name of this task.
     *
     * @param string $name The task name.
     */
    public function setName($name): void
    {
        $this->_name = $name;
    }

    /**
     * Get the name of this task.
     *
     * @return string The task name.
     */
    public function getName(): ?string
    {
        return $this->_name;
    }

    /**
     * Get the tasks handler.
     *
     * @return ReleaseTasks The release tasks handler.
     */
    protected function getTasks(): QcTasks
    {
        return $this->_tasks;
    }

    /**
     * Get the output handler.
     *
     * @return Output The output handler.
     */
    protected function getOutput(): \Horde\Components\Output
    {
        return $this->_output;
    }

    /**
     * Validate the preconditions required for this release task.
     *
     * @param array $options Additional options.
     *
     * @return array An empty array if all preconditions are met and a list of
     *               error messages otherwise.
     */
    public function validate(array $options = []): array
    {
        return [];
    }

    /**
     * Formally needed because the system call trait supports pretend mode.
     */
    public function pretend(): bool
    {
        return false;
    }

    /**
     * Run the task.
     *
     * @param array &$options Additional options.
     *
     * @return int Number of errors.
     */
    public function run(array &$options = []): int
    {
        return 0;
    }
}
