<?php

/**
 * Copyright 2013-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

declare(strict_types=1);

namespace Horde\Components\Task;

/**
 * Task interface for all task types.
 *
 * Tasks are discrete units of work that can be validated, executed,
 * and composed into pipelines. Tasks can emit facts for later tasks,
 * support pretend (dry-run) mode, and return structured results.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
interface TaskInterface
{
    /**
     * Get task name for display.
     *
     * @return string Human-readable task name
     */
    public function getName(): string;

    /**
     * Validate preconditions before running the task.
     *
     * This is called before execution to check if all requirements
     * are met (e.g., required tools installed, required facts present).
     *
     * @param Context $context Execution context
     * @return array<string> Empty if valid, error messages otherwise
     */
    public function validate(Context $context): array;

    /**
     * Check if this task should be skipped.
     *
     * Called after validation passes. Allows tasks to skip based on
     * context or options (e.g., skip if fact already set).
     *
     * @param Context $context Execution context
     * @return bool True to skip, false to run
     */
    public function shouldSkip(Context $context): bool;

    /**
     * Execute the task.
     *
     * Performs the task's work and returns a result indicating success,
     * failure, or warnings. Tasks can emit facts via $context->setFact()
     * for use by later tasks in the pipeline.
     *
     * @param Context $context Execution context
     * @return Result Result object with success/failure and details
     */
    public function run(Context $context): Result;
}
