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

namespace Horde\Components\Qc;

use Horde\Components\Task\Result;

/**
 * Aggregate result of a qc pipeline run.
 *
 * Composes one Horde\Components\Task\Result per executed or skipped task,
 * keyed by task name, so callers can see which modules failed rather than
 * only a collapsed total error count.
 *
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
final class PipelineResult
{
    /**
     * Constructor.
     *
     * @param array<string, Result> $results Per-task results keyed by task name.
     */
    public function __construct(
        public readonly array $results = [],
    ) {}

    /**
     * Total number of errors across all tasks in the pipeline.
     *
     * @return int The summed error count.
     */
    public function totalErrors(): int
    {
        $total = 0;
        foreach ($this->results as $result) {
            $total += $result->errorCount;
        }

        return $total;
    }

    /**
     * The subset of results that reported at least one error.
     *
     * @return array<string, Result> Failing results keyed by task name.
     */
    public function failures(): array
    {
        return array_filter(
            $this->results,
            static fn(Result $result): bool => $result->errorCount > 0
        );
    }

    /**
     * Did any task in the pipeline report errors?
     *
     * @return bool True if at least one task failed.
     */
    public function hasFailures(): bool
    {
        return $this->totalErrors() > 0;
    }

    /**
     * Human-readable summary of the failing tasks.
     *
     * Lists only tasks that reported errors, e.g.
     * "phpstan (1 error), unit (3 errors)". Returns an empty string when
     * the pipeline had no failures.
     *
     * @return string The summary line.
     */
    public function summary(): string
    {
        $parts = [];
        foreach ($this->failures() as $name => $result) {
            $noun = $result->errorCount === 1 ? 'error' : 'errors';
            $parts[] = sprintf('%s (%d %s)', $name, $result->errorCount, $noun);
        }

        return implode(', ', $parts);
    }
}
