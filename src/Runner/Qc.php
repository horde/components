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
class Qc
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
     * The quality control tasks handler.
     *
     * @param QcTasks
     */
    private $_qc;

    /**
     * Constructor.
     *
     * @param Config  $config The configuration for the current job.
     * @param Output  $output The output handler.
     * @param QcTasks $qc     The qc handler.
     */
    public function __construct(Config $config,
                                Output $output,
                                QcTasks $qc)
    {
        $this->_config = $config;
        $this->_output = $output;
        $this->_qc = $qc;
    }

    public function run()
    {
        $sequence = array();
        if ($this->_doTask('unit')) {
            $sequence[] = 'unit';
        }

        if ($this->_doTask('md')) {
            $sequence[] = 'md';
        }

        if ($this->_doTask('cs')) {
            $sequence[] = 'cs';
        }

        if ($this->_doTask('cpd')) {
            $sequence[] = 'cpd';
        }

        if ($this->_doTask('lint')) {
            $sequence[] = 'lint';
        }

        if ($this->_doTask('loc')) {
            $sequence[] = 'loc';
        }

        if ($this->_doTask('dcd')) {
            $sequence[] = 'dcd';
        }

        if (!empty($sequence)) {
            $this->_qc->run(
                $sequence,
                $this->_config->getComponent(),
                $this->_config->getOptions()
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
        if ((count($arguments) == 1 && $arguments[0] == 'qc')
            || in_array($task, $arguments)) {
            return true;
        }
        return false;
    }
}
