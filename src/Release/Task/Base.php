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

use Horde\Components\TaskInterface;
use Horde\Components\Component\Source as ComponentSource;
use Horde\Components\Component\Task\Dependencies;
use Horde\Components\Component\Task\SystemCall;
use Horde\Components\Exception;
use Horde\Components\Output;
use Horde\Components\Release\Notes as ReleaseNotes;
use Horde\Components\Release\Tasks as ReleaseTasks;

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
class Base implements TaskInterface
{
    use SystemCall;
    use Dependencies;
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
     * @param ReleaseTasks $_tasks The task handler.
     * @param ReleaseNotes $_notes The release notes.
     * @param Output $_output Accepts output.
     */
    public function __construct(protected ReleaseTasks $_tasks, protected ReleaseNotes $_notes, protected Output $_output)
    {
    }

    /**
     * Set the component this task should act upon.
     *
     * @param ComponentSource $component The component to be released.
     */
    public function setComponent(ComponentSource $component): void
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
    public function setName($name): void
    {
        $this->_name = $name;
    }

    /**
     * Get the name of this task.
     *
     * @return string The task name.
     */
    public function getName(): string
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
        return [];
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
        return [];
    }

    /**
     * Run the task.
     *
     * @param array &$options Additional options.
     */
    public function run(&$options)
    {
    }

    public function pretend(): bool
    {
        return $this->getTasks()->pretend();
    }
}
