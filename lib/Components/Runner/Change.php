<?php
/**
 * Components_Runner_Change:: adds a new change log entry.
 *
 * PHP version 5
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

/**
 * Components_Runner_Change:: adds a new change log entry.
 *
 * Copyright 2011-2017 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Components_Runner_Change
{
    /**
     * The configuration for the current job.
     *
     * @var Components_Config
     */
    private $_config;

    /**
     * The output handler.
     *
     * @param Components_Output
     */
    private $_output;

    /**
     * Constructor.
     *
     * @param Components_Config $config  The configuration for the current job.
     * @param Components_Output $output  The output handler.
     */
    public function __construct(
        Components_Config $config,
        Components_Output $output
    )
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
            $options['commit'] = new Components_Helper_Commit(
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
