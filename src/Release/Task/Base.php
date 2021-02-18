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
namespace Horde\Components\Release\Task;
use Horde\Components\Exception;
use Horde\Components\Output;
use Horde\Components\Component\Source as ComponentSource;
use Horde\Components\Release\Tasks as ReleaseTasks;
use Horde\Components\Release\Notes as ReleaseNotes;
use Horde\Components\Component\Task\SystemCall;
use Horde\Components\Component\Task\Dependencies;

/**
 * Components_Release_Task_Base:: provides core functionality for release tasks.
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
     * The tasks handler.
     *
     * @var ReleaseTasks
     */
    protected $_tasks;

    /**
     * The release notes handler.
     *
     * @var ReleaseNotes
     */
    protected $_notes;

    /**
     * The task output.
     *
     * @var Output
     */
    protected $_output;

    /**
     * The component that should be released
     *
     * @var ComponentSource
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
     * @param ReleaseTasks $tasks  The task handler.
     * @param ReleaseNotes $notes  The release notes.
     * @param Output       $output Accepts output.
     */
    public function __construct(
        ReleaseTasks $tasks,
        ReleaseNotes $notes,
        Output $output
    ) {
        $this->_tasks = $tasks;
        $this->_notes = $notes;
        $this->_output = $output;
    }

    /**
     * Set the component this task should act upon.
     *
     * @param ComponentSource $component The component to be released.
     */
    public function setComponent(ComponentSource $component)
    {
        $this->_component = $component;
        $this->_notes->setComponent($component);
    }

    /**
     * Get the component this task should act upon.
     *
     * @return ComponentSource The component to be released.
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
     * @return ReleaseTasks The release tasks handler.
     */
    protected function getTasks()
    {
        return $this->_tasks;
    }

    /**
     * Get the release notes.
     *
     * @return ReleaseNotes The release notes.
     */
    protected function getNotes()
    {
        return $this->_notes;
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

    use SystemCall;
    use Dependencies;
}
