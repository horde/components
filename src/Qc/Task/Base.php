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
use Horde\Components\Config;
use Horde\Components\Component;
use Horde\Components\Output;
use Horde\Components\Qc\Tasks as QcTasks;
use Horde\Components\Release\Tasks as ReleaseTasks;
use Horde\Components\Component\Task\SystemCall;
/**
 * Components_Qc_Task_Base:: provides core functionality for qc tasks.
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
class Base
{
    /**
     * The configuration for the current job.
     *
     * @var Config
     */
    protected $_config;

    /**
     * The tasks handler.
     *
     * @var QcTasks
     */
    private $_tasks;

    /**
     * The task output.
     *
     * @var Output
     */
    private $_output;

    /**
     * The component that should be checked
     *
     * @var Component
     */
    private $_component;

    /**
     * The task name.
     *
     * @var string
     */
    private $_name;

    /**
     * Constructor.
     *
     * @param Config   $config The configuration for the current job.
     * @param QcTasks $tasks  The task handler.
     * @param Output   $output Accepts output.
     */
    public function __construct(
        Config $config,
        QcTasks $tasks,
        Output $output
    ) {
        $this->_config = $config;
        $this->_tasks = $tasks;
        $this->_output = $output;
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
     *
     * @return void
     */
    public function setComponent(Component $component)
    {
        $this->_component = $component;
    }

    /**
     * Get the component this task should act upon.
     *
     * @return Component The component to be checked.
     */
    protected function getComponent()
    {
        return $this->_component;
    }

    /**
     * Set the name of this task.
     *
     * @param string $name The task name.
     *
     * @return void
     */
    public function setName($name)
    {
        $this->_name = $name;
    }

    /**
     * Get the name of this task.
     *
     * @return string The task name.
     */
    public function getName()
    {
        return $this->_name;
    }

    /**
     * Get the tasks handler.
     *
     * @return ReleaseTasks The release tasks handler.
     */
    protected function getTasks()
    {
        return $this->_tasks;
    }

    /**
     * Get the output handler.
     *
     * @return Output The output handler.
     */
    protected function getOutput()
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
     * Run the task.
     *
     * @param array &$options Additional options.
     *
     * @return integer Number of errors.
     */
    public function run(array &$options = [])
    {
    }

    use SystemCall;
}