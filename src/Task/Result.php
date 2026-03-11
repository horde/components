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
 * Result of task execution.
 *
 * Standardizes task return values across all task types.
 * Provides rich information about success, failure, warnings,
 * and additional metadata.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Result
{
    /**
     * Constructor.
     *
     * @param Status $status Execution status
     * @param string $message Human-readable message
     * @param int $errorCount Number of errors (for QC tasks)
     * @param int $warningCount Number of warnings (for QC tasks)
     * @param array<string,mixed> $metadata Additional data for pipeline use
     */
    public function __construct(
        public readonly Status $status,
        public readonly string $message = '',
        public readonly int $errorCount = 0,
        public readonly int $warningCount = 0,
        public readonly array $metadata = []
    ) {}

    /**
     * Create a success result.
     *
     * @param string $message Success message
     * @param array<string,mixed> $metadata Additional data
     * @return self
     */
    public static function success(string $message = '', array $metadata = []): self
    {
        return new self(Status::SUCCESS, $message, 0, 0, $metadata);
    }

    /**
     * Create a failure result.
     *
     * @param string $message Failure message
     * @param int $errorCount Number of errors
     * @return self
     */
    public static function failure(string $message, int $errorCount = 1): self
    {
        return new self(Status::FAILURE, $message, $errorCount, 0, []);
    }

    /**
     * Create a warning result.
     *
     * Task completed but with non-blocking issues.
     *
     * @param string $message Warning message
     * @param int $warningCount Number of warnings
     * @param array<string,mixed> $metadata Additional data
     * @return self
     */
    public static function warning(
        string $message,
        int $warningCount = 1,
        array $metadata = []
    ): self {
        return new self(Status::WARNING, $message, 0, $warningCount, $metadata);
    }

    /**
     * Create a skipped result.
     *
     * @param string $reason Skip reason
     * @return self
     */
    public static function skipped(string $reason): self
    {
        return new self(Status::SKIPPED, $reason, 0, 0, []);
    }

    /**
     * Create a QC result (may have errors and/or warnings).
     *
     * @param int $errorCount Number of errors
     * @param int $warningCount Number of warnings
     * @param string $message Result message
     * @return self
     */
    public static function qc(
        int $errorCount,
        int $warningCount = 0,
        string $message = ''
    ): self {
        $status = $errorCount > 0 ? Status::FAILURE : Status::SUCCESS;

        if ($message === '') {
            if ($errorCount === 0 && $warningCount === 0) {
                $message = 'No issues found';
            } elseif ($errorCount > 0) {
                $message = "{$errorCount} error(s) found";
            } else {
                $message = "{$warningCount} warning(s) found";
            }
        }

        return new self($status, $message, $errorCount, $warningCount, []);
    }

    /**
     * Check if task succeeded.
     *
     * @return bool True if success or warning
     */
    public function isSuccess(): bool
    {
        return $this->status === Status::SUCCESS || $this->status === Status::WARNING;
    }

    /**
     * Check if task failed (blocking error).
     *
     * @return bool True if failure
     */
    public function isFailure(): bool
    {
        return $this->status === Status::FAILURE;
    }

    /**
     * Check if task was skipped.
     *
     * @return bool True if skipped
     */
    public function isSkipped(): bool
    {
        return $this->status === Status::SKIPPED;
    }

    /**
     * Check if task had any issues (errors or warnings).
     *
     * @return bool True if errors or warnings present
     */
    public function hasIssues(): bool
    {
        return $this->errorCount > 0 || $this->warningCount > 0;
    }

    /**
     * Check if pipeline should stop after this task.
     *
     * @return bool True if task failed
     */
    public function shouldStopPipeline(): bool
    {
        return $this->status === Status::FAILURE;
    }
}
