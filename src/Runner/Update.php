<?php
/**
 * Copyright 2010-2020 Horde LLC (http://www.horde.org/)
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
namespace Horde\Components\Runner;
use Horde\Components\Config;
use Horde\Components\Output;
use Horde\Components\Helper\Commit as HelperCommit;
use Horde\Components\Helper\Version as HelperVersion;
use Horde\Components\Release\Notes as ReleaseNotes;

/**
 * Components_Runner_Update updates the package files of a Horde component.
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @author   Jan Schneider <jan@horde.org>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Update
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
    public function __construct(
        Config $config,
        Output $output
    )
    {
        $this->_config = $config;
        $this->_output = $output;
    }

    /**
     * @throws Exception
     * @throws \Horde_Pear_Exception
     */
    public function run()
    {
        $arguments = $this->_config->getArguments();
        $options = array_merge(
            array(
                'new_version' => false,
                'new_api' => false,
                'new_state' => false,
                'new_apistate' => false,
                'theme' => false,
            ),
            $this->_config->getOptions()
        );

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
                $options['commit'] = new HelperCommit(
                    $this->_output, $options
                );
            }
            /** @var Horde\Components\Component\Source $component */
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
                    $notes = new ReleaseNotes($this->_output);
                    $notes->setComponent($component);
                    $application_version =
                        HelperVersion::pearToHordeWithBranch(
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
