<?php

/**
 * Components_Qc_Task_Base:: provides core functionality for qc tasks.
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

namespace Horde\Components\Qc\Task;

use Horde\Components\Component;
use Horde\Components\Component\Task\SystemCallResult;
use Horde\Components\Output;
use Horde\Components\Qc\Tasks as QcTasks;
use Horde\Components\Release\Tasks as ReleaseTasks;
use Horde\Components\Helper\Shell;

/**
 * Components_Qc_Task_Base:: provides core functionality for qc tasks.
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
class Base
{
    /**
     * The component that should be checked
     */
    private ?Component $_component = null;

    /**
     * The task name.
     */
    private ?string $_name = null;

    /**
     * The component path
     */
    private ?string $_path = null;

    /**
     * Shell executor for running commands
     */
    private Shell $_shell;

    /**
     * Constructor.
     *
     * @param QcTasks $_tasks The task handler.
     * @param Output $_output Accepts output.
     */
    public function __construct(
        private readonly QcTasks $_tasks,
        private readonly Output $_output
    ) {
        if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
            require __DIR__ . '/../../../bundle/vendor/autoload.php';
        } elseif (file_exists('/../../../bundle/vendor/autoload.php')) {
            require __DIR__ . '/../../../bundle/vendor/autoload.php';
        }

        // Initialize shell (QC tasks don't have pretend mode)
        $this->_shell = new Shell();
    }

    /**
     * Set the component this task should act upon.
     *
     * @param Component $component The component to be checked.
     */
    public function setComponent(Component $component): void
    {
        $this->_component = $component;
        $this->_path = $component->getComponentDirectory();
    }

    /**
     * Get the component path.
     *
     * @return string|null The component path.
     */
    protected function getPath(): ?string
    {
        return $this->_path;
    }

    /**
     * Get the component this task should act upon.
     *
     * @return Component The component to be checked.
     */
    protected function getComponent(): ?Component
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
     * @return QcTasks The QC tasks handler.
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
    protected function getOutput(): Output
    {
        return $this->_output;
    }

    /**
     * Get the Shell instance for executing commands.
     *
     * Subclasses should use this to execute shell commands:
     * - $this->getShell()->exec($cmd, $dir)
     * - $this->getShell()->system($cmd, $dir)
     *
     * @return Shell The shell executor
     */
    protected function getShell(): Shell
    {
        return $this->_shell;
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
     * QC tasks don't support pretend mode - they always execute.
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
