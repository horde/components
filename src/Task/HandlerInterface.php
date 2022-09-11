<?php
/**
 * PipelineInterface - Components Task Runner.
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
 * Common interface for task handlers used in Release, Qc and Pipeline
 *
 * Possibly also a generic blueprint for Module Runners.
 * 
 * Copyright 2011-2022 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * Inspired by PSR-15 HandlerInterface
 * Derived from Components_Release_Tasks and Components_Qc_Tasks by Gunnar Wrobel
 * 
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
interface HandlerInterface
{
    /**
     * Handles a TaskInput and produces a Result.
     *
     * May call other collaborating code to generate the result.
     */
    public function handle(InputInterface $input): ResultInterface;
}
