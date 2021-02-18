<?php
/**
 * Components_Runner_Change:: adds a new change log entry.
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
use Horde\Components\Helper\Commit as HelperCommit;

/**
 * Components_Runner_Change:: adds a new change log entry.
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
class Change
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
     * Constructor.
     *
     * @param Config $config  The configuration for the current job.
     * @param Output $output  The output handler.
     */
    public function __construct(Config $config, Output $output)
    {
        $this->_config = $config;
        $this->_output = $output;
    }

    public function run()
    {
        $options = $this->_config->getOptions();
        $arguments = $this->_config->getArguments();

        if (count($arguments) > 1 && $arguments[0] == 'changed') {
            $log = $arguments[1];
        } else {
            $log = null;
        }

        if ($log && !empty($options['commit'])) {
            $options['commit'] = new HelperCommit(
                $this->_output, $options
            );
        }
        $output = $this->_config->getComponent()->changed($log, $options);
        if ($log && !empty($options['commit'])) {
            $options['commit']->commit($log);
        }
        foreach ($output as $message) {
            $this->_output->plain($message);
        }
    }
}
