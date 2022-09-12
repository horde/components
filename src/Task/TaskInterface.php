<?php
/**
 * TaskInterface - common interface for all task implementations.
 *
 * PHP Version 8
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

namespace Horde\Components\Task;

/**
 * Common interface for tasks used in Release, Qc and Pipeline
 *
 * Copyright 2011-2022 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * Inspired by PSR-15 MiddlewareInterface
 * Derived from Components_Release_Task_Base by Gunnar Wrobel
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
interface TaskInterface
{
    /**
     * Process an incoming TaskInput.
     *
     * Processes an incoming TaskInput in order to produce a TaskOutput.
     * If unable to produce the output itself, it may delegate to the provided
     * pipeline to do so.
     */
    public function process(InputInterface $input, HandlerInterface $handler): ResultInterface;

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
}
