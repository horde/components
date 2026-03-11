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

use Horde\Components\Output;

/**
 * Base implementation for all tasks.
 *
 * Provides common functionality and sensible defaults.
 * Tasks extending this class only need to implement getName() and run().
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
abstract class AbstractTask implements TaskInterface
{
    /**
     * Constructor.
     *
     * @param Output $output Output handler for messages
     * @param bool $pretend Pretend (dry-run) mode - don't make changes
     */
    public function __construct(
        protected readonly Output $output,
        protected readonly bool $pretend = false
    ) {}

    /**
     * Get task name for display.
     *
     * Subclasses should override to provide descriptive name.
     *
     * @return string Human-readable task name
     */
    abstract public function getName(): string;

    /**
     * Validate preconditions before execution.
     *
     * Default implementation has no validation errors.
     * Override to add validation logic.
     *
     * @param Context $context Execution context
     * @return array<string> Empty if valid, error messages otherwise
     */
    public function validate(Context $context): array
    {
        return [];
    }

    /**
     * Check if task should be skipped.
     *
     * Default implementation never skips.
     * Override to add skip logic.
     *
     * @param Context $context Execution context
     * @return bool True to skip, false to run
     */
    public function shouldSkip(Context $context): bool
    {
        return false;
    }

    /**
     * Execute the task.
     *
     * Subclasses must implement the actual task logic.
     *
     * @param Context $context Execution context
     * @return Result Result object with success/failure and details
     */
    abstract public function run(Context $context): Result;

    /**
     * Helper for pretend mode - outputs message and returns success result.
     *
     * @param string $action Action that would be performed
     * @return Result Success result with pretend message
     */
    protected function pretend(string $action): Result
    {
        $this->output->info("[PRETEND] Would: {$action}");
        return Result::success("Would: {$action}");
    }

    /**
     * Get output handler.
     *
     * @return Output
     */
    protected function getOutput(): Output
    {
        return $this->output;
    }

    /**
     * Check if in pretend mode.
     *
     * @return bool True if pretending
     */
    protected function isPretending(): bool
    {
        return $this->pretend;
    }
}
