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
use Horde\Components\Helper\Commit as HelperCommit;
use Horde\Components\Output;
use Horde\Components\Qc\Tasks as QcTasks;
use Horde\Components\Release\Tasks as ReleaseTasks;

/**
 * Components_Runner_Release:: releases a new version for a package.
 *
 * Copyright 2011-2020 Horde LLC (http://www.horde.org/)
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
     * The configuration for the current job.
     *
     * @var Config
     */
    private $_config;

    /**
     * The output handler.
     *
     * @param Output
     */
    private $_output;

    /**
     * The QC tasks handler.
     *
     * @param QcTasks
     */
    private $_qc;

    /**
     * The release tasks handler.
     *
     * @param ReleaseTasks
     */
    private $_release;

    /**
     * Constructor.
     *
     * @param Config       $config  The current job's configuration
     * @param Output       $output  The output handler.
     * @param ReleaseTasks $release The tasks handler.
     * @param QcTasks      $qc      QC tasks handler.
     */
    public function __construct(
        Config $config,
        Output $output,
        ReleaseTasks $release,
        QcTasks $qc
    ) {
        $this->_config = $config;
        $this->_output = $output;
        $this->_release = $release;
        $this->_qc = $qc;
    }

    /**
     * @throws Exception
     */
    public function run()
    {
        $component = $this->_config->getComponent();
        $options = $this->_config->getOptions();

        $sequence = array();

        $pre_commit = false;

        /**
         * Catch predefined release pipelines
         * Otherwise, revert to traditional behaviour
         */
        $arguments = $this->_config->getArguments();
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
        }

        if ($this->_doTask('unittest')) {
            $sequence[] = 'Unit';
            $pre_commit = true;
        }

        if ($this->_doTask('changelog')) {
            $sequence[] = 'Changelog';
            $pre_commit = true;
        }

        if ($this->_doTask('timestamp')) {
            $sequence[] = 'Timestamp';
            $pre_commit = true;
        }

        if ($this->_doTask('sentinel')) {
            $sequence[] = 'CurrentSentinel';
            $pre_commit = true;
        }

        $sequence[] = 'Diff';

        if ($this->_doTask('package')) {
            $sequence[] = 'Package';
            if ($this->_doTask('upload')) {
                $options['upload'] = true;
            } else {
                $this->_output->warn('Are you certain you don\'t want to upload the package? Add the "upload" option in case you want to correct your selection. Waiting 5 seconds ...');
                sleep(5);
            }
        } elseif ($this->_doTask('upload')) {
            throw new Exception('Selecting "upload" without "package" is not possible! Please add the "package" task if you want to upload the package!');
        }

        if ($this->_doTask('commit') && $pre_commit) {
            $sequence[] = 'CommitPreRelease';
        }

        if ($this->_doTask('tag')) {
            $sequence[] = 'TagRelease';
        }

        if ($this->_doTask('announce')) {
            $sequence[] = 'Announce';
        }

        if ($this->_doTask('website')) {
            $sequence[] = 'Website';
        }

        if ($this->_doTask('bugs')) {
            $sequence[] = 'Bugs';
        }

        if ($this->_doTask('next')) {
            $sequence[] = 'NextVersion';
            if ($this->_doTask('commit')) {
                $sequence[] = 'CommitPostRelease';
            }
        }

        $sequence[] = 'Diff';

        if (in_array('CommitPreRelease', $sequence) ||
            in_array('CommitPostRelease', $sequence)) {
            $options['commit'] = new HelperCommit(
                $this->_output, $options
            );
        }

        $options['skip_invalid'] = $this->_doTask('release');

        if (!empty($sequence)) {
            $this->_release->run(
                $sequence,
                $component,
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
     *
     * @return boolean True if the task is active.
     */
    private function _doTask($task)
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
