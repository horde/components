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
 * Task execution status.
 *
 * Indicates the outcome of a task execution.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
enum Status: string
{
    /**
     * Task succeeded without issues.
     */
    case SUCCESS = 'success';

    /**
     * Task failed (blocking error).
     */
    case FAILURE = 'failure';

    /**
     * Task completed with warnings (non-blocking).
     */
    case WARNING = 'warning';

    /**
     * Task was skipped.
     */
    case SKIPPED = 'skipped';
}
