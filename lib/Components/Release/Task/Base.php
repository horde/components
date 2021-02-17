<?php
/**
 * Components_Release_Task_Base:: provides core functionality for release tasks.
 *
 * PHP version 5
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

/**
 * Components_Release_Task_Base:: provides core functionality for release tasks.
 *
 * Copyright 2011-2017 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Components_Release_Task_Base
{
    /**
     * The tasks handler.
     *
     * @var Components_Release_Tasks
     */
    protected $_tasks;

    /**
     * The release notes handler.
     *
     * @var Components_Release_Notes
     */
    protected $_notes;

    /**
     * The task output.
     *
     * @var Components_Output
     */
    protected $_output;

    /**
     * The component that should be released
     *
     * @var Components_Component_Source
     */
    protected $_component;

    /**
     * The task name.
     *
     * @var string
     */
    protected $_name;

    /**
     * Constructor.
     *
     * @param Components_Release_Tasks $tasks The task handler.
     * @param Components_Release_Notes $notes The release notes.
     * @param Components_Output $output Accepts output.
     */
    public function __construct(
        Components_Release_Tasks $tasks,
        Components_Release_Notes $notes,
        Components_Output $output
    ) {
        $this->_tasks = $tasks;
        $this->_notes = $notes;
        $this->_output = $output;
    }

    /**
     * Set the component this task should act upon.
     *
     * @param Components_Component_Source $component The component to be released.
     */
    public function setComponent(Components_Component_Source $component)
    {
        $this->_component = $component;
        $this->_notes->setComponent($component);
    }

    /**
     * Get the component this task should act upon.
     *
     * @return Components_Component_Source The component to be released.
     */
    protected function getComponent()
    {
        return $this->_component;
    }

    /**
     * Set the name of this task.
     *
     * @param string $name The task name.
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
     * @return Components_Release_Tasks The release tasks handler.
     */
    protected function getTasks()
    {
        return $this->_tasks;
    }

    /**
     * Get the release notes.
     *
     * @return Components_Release_Notes The release notes.
     */
    protected function getNotes()
    {
        return $this->_notes;
    }

    /**
     * Get the output handler.
     *
     * @return Components_Output The output handler.
     */
    protected function getOutput()
    {
        return $this->_output;
    }

    /**
     * Can the task be skipped?
     *
     * @param array $options Additional options.
     *
     * @return boolean True if it can be skipped.
     */
    public function skip($options)
    {
        return !empty($options['skip_invalid']);
    }

    /**
     * Validate the preconditions required for this release task.
     *
     * @param array $options Additional options.
     *
     * @return array An empty array if all preconditions are met and a list of
     *               error messages otherwise.
     */
    public function preValidate($options)
    {
        return array();
    }

    /**
     * Validate the postconditions required for this release task to have
     * succeeded.
     *
     * @param array $options Additional options.
     *
     * @return array An empty array if all postconditions are met and a list of
     *               error messages otherwise.
     */
    public function postValidate($options)
    {
        return array();
    }

    /**
     * Run the task.
     *
     * @param array &$options Additional options.
     */
    public function run(&$options)
    {
    }

    /**
     * Run a system call.
     *
     * @param string $call The system call to execute.
     *
     * @return string The command output.
     */
    protected function system($call)
    {
        if (!$this->getTasks()->pretend()) {
            //@todo Error handling
            return system($call);
        } else {
            $this->getOutput()->info(sprintf('Would run "%s" now.', $call));
        }
    }

    /**
     * Run a system call.
     *
     * @param string $call       The system call to execute.
     * @param string $target_dir Run the command in the provided target path.
     *
     * @return string The command output.
     */
    protected function systemInDirectory($call, $target_dir)
    {
        if (!$this->getTasks()->pretend()) {
            $old_dir = getcwd();
            chdir($target_dir);
        }
        $result = $this->system($call);
        if (!$this->getTasks()->pretend()) {
            chdir($old_dir);
        }
        return $result;
    }
}
