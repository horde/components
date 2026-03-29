<?php

/**
 * Components_Runner_Qc:: checks the component for quality.
 *
 * PHP Version 8.2+
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

declare(strict_types=1);

namespace Horde\Components\Runner;

use Horde\Components\Component;
use Horde\Components\Output;
use Horde\Components\Qc\Tasks as QcTasks;

/**
 * Components_Runner_Qc:: checks the component for quality.
 *
 * Copyright 2011-2026 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Qc
{
    /**
     * Constructor.
     *
     * @param Component $component The component to check
     * @param array $arguments CLI arguments for task selection
     * @param array $options CLI options (e.g., fix-qc-issues)
     * @param Output $output The output handler
     * @param QcTasks $qc The qc handler
     */
    public function __construct(
        private readonly Component $component,
        private readonly array $arguments,
        private readonly array $options,
        private readonly Output $output,
        private readonly QcTasks $qc
    ) {}

    public function run(): void
    {
        $sequence = [];

        // Gitignore check runs early - ensures proper VCS configuration
        if ($this->_doTask('gitignore', $this->arguments)) {
            $sequence[] = 'gitignore';
        }

        // HordeYml check - validates .horde.yml metadata quality
        if ($this->_doTask('hordeyml', $this->arguments)) {
            $sequence[] = 'hordeyml';
        }

        if ($this->_doTask('lint', $this->arguments)) {
            $sequence[] = 'lint';
        }

        // PHP CS Fixer runs after lint (valid PHP) but before cs (fixes many PHPCS issues)
        if ($this->_doTask('phpcsfixer', $this->arguments)) {
            $sequence[] = 'phpcsfixer';
        }

        if ($this->_doTask('unit', $this->arguments)) {
            $sequence[] = 'unit';
        }

        // PHPStan runs after unit tests - comprehensive static analysis
        if ($this->_doTask('phpstan', $this->arguments)) {
            $sequence[] = 'phpstan';
        }

        // Metrics (phpmetrics) provides code quality insights
        if ($this->_doTask('metrics', $this->arguments)) {
            $sequence[] = 'metrics';
        }

        // PHPMD (md) is only run when explicitly requested, not in default pipeline
        if ($this->_doTask('md', $this->arguments, false)) {
            $sequence[] = 'md';
        }

        // PHPCS (cs) is only run when explicitly requested, not in default pipeline
        if ($this->_doTask('cs', $this->arguments, false)) {
            $sequence[] = 'cs';
        }

        // LOC (phploc) is only run when explicitly requested, not in default pipeline
        // Deprecated: Use 'metrics' task (PHPMetrics) instead for modern metrics
        if ($this->_doTask('loc', $this->arguments, false)) {
            $sequence[] = 'loc';
        }

        if (!empty($sequence)) {
            $this->qc->run(
                $sequence,
                $this->component,
                $this->options
            );
        } else {
            $this->output->warn('Huh?! No tasks selected... All done!');
        }
    }

    /**
     * Did the user activate the given task?
     *
     * @param string $task The task name.
     * @param array $arguments The command arguments.
     * @param bool $includeInDefault Whether to include in default "qc" run.
     *
     * @return bool True if the task is active.
     */
    private function _doTask(string $task, array $arguments, bool $includeInDefault = true): bool
    {
        // Task explicitly mentioned in arguments
        if (in_array($task, $arguments)) {
            return true;
        }

        // Running default "qc" - only include if task is in default pipeline
        if (count($arguments) == 1 && $arguments[0] == 'qc' && $includeInDefault) {
            return true;
        }

        return false;
    }
}
