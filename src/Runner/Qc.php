<?php

/**
 * Components_Runner_Qc:: checks the component for quality.
 *
 * PHP Version 7
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

namespace Horde\Components\Runner;

use Horde\Components\Config;
use Horde\Components\Output;
use Horde\Components\Qc\Tasks as QcTasks;

/**
 * Components_Runner_Qc:: checks the component for quality.
 *
 * Copyright 2011-2024 Horde LLC (http://www.horde.org/)
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
     * @param Output $_output The output handler.
     * @param QcTasks $_qc The qc handler.
     */
    public function __construct(
        private readonly Output $_output,
        private readonly QcTasks $_qc
    ) {}

    public function run(Config $config): void
    {
        $arguments = $config->getArguments();
        $options = $config->getOptions();

        $sequence = [];

        // Gitignore check runs early - ensures proper VCS configuration
        if ($this->_doTask('gitignore', $arguments)) {
            $sequence[] = 'gitignore';
        }

        if ($this->_doTask('lint', $arguments)) {
            $sequence[] = 'lint';
        }

        // PHP CS Fixer runs after lint (valid PHP) but before cs (fixes many PHPCS issues)
        if ($this->_doTask('phpcsfixer', $arguments)) {
            $sequence[] = 'phpcsfixer';
        }

        if ($this->_doTask('unit', $arguments)) {
            $sequence[] = 'unit';
        }

        // PHPStan runs after unit tests - comprehensive static analysis
        if ($this->_doTask('phpstan', $arguments)) {
            $sequence[] = 'phpstan';
        }

        // PHPMD (md) is only run when explicitly requested, not in default pipeline
        if ($this->_doTask('md', $arguments, false)) {
            $sequence[] = 'md';
        }

        // PHPCS (cs) is only run when explicitly requested, not in default pipeline
        if ($this->_doTask('cs', $arguments, false)) {
            $sequence[] = 'cs';
        }

        // LOC (phploc) is only run when explicitly requested, not in default pipeline
        // Deprecated: Use 'metrics' task (PHPMetrics) instead for modern metrics
        if ($this->_doTask('loc', $arguments, false)) {
            $sequence[] = 'loc';
        }

        // Metrics (phpmetrics) is only run when explicitly requested, not in default pipeline yet
        // Modern replacement for deprecated LOC task
        if ($this->_doTask('metrics', $arguments, false)) {
            $sequence[] = 'metrics';
        }

        if (!empty($sequence)) {
            $this->_qc->run(
                $sequence,
                $config->getComponent(),
                $options
            );
        } else {
            $this->_output->warn('Huh?! No tasks selected... All done!');
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
