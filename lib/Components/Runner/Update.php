<?php
/**
 * Copyright 2010-2017 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @author   Jan Schneider <jan@horde.org>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

/**
 * Components_Runner_Update updates the package files of a Horde component.
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @author   Jan Schneider <jan@horde.org>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Components_Runner_Update
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
     * @param Component_Output
     */
    private $_output;

    /**
     * Constructor.
     *
     * @param Components_Config $config  The configuration for the current job.
     * @param Component_Output $output   The output handler.
     */
    public function __construct(
        Components_Config $config,
        Components_Output $output
    )
    {
        $this->_config  = $config;
        $this->_output = $output;
    }

    public function run()
    {
        $arguments = $this->_config->getArguments();
        $options = array_merge(array(
            'new_version' => false,
            'new_api' => false,
            'new_state' => false,
            'new_apistate' => false,
            'theme' => false,
        ), $this->_config->getOptions());

        if (!empty($options['updatexml']) ||
            (isset($arguments[0]) && $arguments[0] == 'update')) {
            $action = !empty($options['action'])
                ? $options['action']
                : 'update';
            if (!empty($options['pretend']) && $action == 'update') {
                $action = 'diff';
            }
            $options['action'] = $action;
            if (!empty($options['commit'])) {
                $options['commit'] = new Components_Helper_Commit(
                    $this->_output, $options
                );
            }
            $component = $this->_config->getComponent();
            if (!empty($options['new_version']) ||
                !empty($options['new_api'])) {
                $result = $component->setVersion(
                    $options['new_version'],
                    $options['new_api'],
                    $options
                );
                if ($action != 'print' && $action != 'diff') {
                    $this->_output->ok($result);
                }
                if (!empty($options['new_version']) &&
                    !empty($options['sentinel'])) {
                    $notes = new Components_Release_Notes($this->_output);
                    $notes->setComponent($component);
                    $application_version =
                        Components_Helper_Version::pearToHordeWithBranch(
                            $options['new_version'] . '-git',
                            $notes->getBranch()
                        );
                    $sentinel_result = $component->currentSentinel(
                        $options['new_version'] . '-git',
                        $application_version,
                        $options
                    );
                    foreach ($sentinel_result as $file) {
                        $this->_output->ok($file);
                    }
                }
            }
            if (!empty($options['new_state']) ||
                !empty($options['new_apistate'])) {
                $result = $component->setState(
                    $options['new_state'], $options['new_apistate'], $options
                );
                if ($action != 'print' && $action != 'diff') {
                    $this->_output->ok($result);
                } else {
                    $this->_output->info($result);
                }
            }
            $result = $component->updatePackage($action, $options);
            if (!empty($options['commit'])) {
                $options['commit']->commit(
                    'Components updated the package files.'
                );
            }
            if ($result === true) {
                $this->_output->ok(
                    'Successfully updated package files of '
                    . $component->getName() . '.'
                );
            } else {
                $this->_output->plain($result);
            }
        }
    }
}
