<?php

/**
 * Components_Runner_Release:: releases a new version for a package.
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
use Horde\Components\Release\Tasks as ReleaseTasks;
use Horde\Components\Exception;
use Horde\Components\Component\ComponentDirectory;
use Horde\Components\Helper\Git as GitHelper;
use Horde\Components\Helper\Composer as ComposerHelper;
use Horde\Components\Release\HordeRelease;

/**
 * Components_Runner_Release:: releases a new version for a package.
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
class Release
{
    /**
     * Constructor.
     *
     * @param Config $_config The current job's configuration
     * @param Output $_output The output handler.
     * @param ReleaseTasks $_release The tasks handler.
     * @param QcTasks $_qc QC tasks handler.
     */
    public function __construct(
        private readonly Config $_config,
        /**
         * The output handler.
         *
         * @param Output $_output
         */
        private readonly Output $_output,
        /**
         * The release tasks handler.
         *
         * @param ReleaseTasks
         */
        private readonly ReleaseTasks $_release,
        /**
         * The QC tasks handler.
         *
         * @param QcTasks
         */
        private readonly QcTasks $_qc
    ) {}

    /**
     * @throws Exception
     */
    public function run(Config $config): void
    {
        $component = $config->getComponent();
        $options = $config->getOptions();

        $sequence = [];

        $pre_commit = false;

        /**
         * Catch predefined release pipelines
         */
        $arguments = $config->getArguments();
        if ((count($arguments) == 3) &&
            $arguments[0] == 'release' &&
            $arguments[1] == 'for') {
            $pipeline = $arguments[2];
            if (empty($options['pipeline']['release'][$pipeline])) {
                $this->_output->warn("Pipeline $pipeline not defined in config");
                return;
            }
            $this->_release->run(
                ['pipeline:', $pipeline],
                $component,
                $options
            );
            return;
        } elseif ((count($arguments) == 2) &&
        $arguments[0] == 'release' &&
        $arguments[1] == 'h6') {
            $this->_output->warn('H6 Release Pipeline');
            $path = new ComponentDirectory($component->getComponentDirectory());
            $gitHelper = new GitHelper();
            $composerHelper = new ComposerHelper();
            $release = new HordeRelease(
                $composerHelper,
                $gitHelper,
                $path,
                $this->_output
            );
            $release->run($config);
            return;
        } else {
            $this->_output->warn('Run "horde-components release for <pipeline>"');
            $this->_output->info("Available pipelines from your configuration: \n" . implode("\n", array_keys($options['pipeline']['release'] ?? [])));
        }
    }

    /**
     * Did the user activate the given task?
     *
     * @param string $task The task name.
     *
     * @return bool True if the task is active.
     */
    private function _doTask($task): bool
    {
        $arguments = $this->_config->getArguments();
        if ((count($arguments) == 1 && $arguments[0] == 'release') ||
            in_array($task, $arguments)) {
            if ($this->_config->getOption('dump') && $task != 'announce') {
                return false;
            }
            return true;
        }
        return false;
    }
}
