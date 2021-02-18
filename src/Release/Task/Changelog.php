<?php
/**
 * Copyright 2018 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Jan Schneider <jan@horde.org>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
namespace Horde\Components\Release\Task;
use Horde\Components\Exception;
use Horde\Components\Output;

/**
 * Components_Release_Task_Changelog updates the change logs in CHANGES and package.xml.
 *
 * @category Horde
 * @package  Components
 * @author   Jan Schneider <jan@horde.org>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Changelog extends Base
{
    private $_wasUpdated = false;

    /**
     * Validate the preconditions required for this release task.
     *
     * @param array $options Additional options.
     *
     * @return array An empty array if all preconditions are met and a list of
     *               error messages otherwise.
     */
    public function preValidate($options)
    {
        if (!$this->getComponent()->hasLocalPackageXml()) {
            return array(
                'The component lacks a local package.xml!',
            );
        }
        return array();
    }

    /**
     * Validate the postconditions required for this release task to have
     * succeeded.
     *
     * @param array $options Additional options.
     *
     * @return array An empty array if all postconditions are met and a list of
     *               error messages otherwise.
     */
    public function postValidate($options)
    {
        $diff_options = $options;
        $diff_options['no_timestamp'] = true;
        $diff_options['from_memory'] = !empty($options['pretend']);
        $diff = $this->getComponent()->updatePackage('diff', $diff_options);
        if (!empty($diff)) {
            return array(
                "At least one metadata file file is not up-to-date:\n$diff"
            );
        }
        return array();
    }

    /**
     * Run the task.
     *
     * @param array &$options Additional options.
     *
     * @return void
     */
    public function run(&$options)
    {
        $result = $this->getComponent()
            ->sync($options);
        if (!$this->getTasks()->pretend()) {
            $this->getOutput()->ok($result);
        } else {
            $this->getOutput()->info($result);
        }
        $this->_wasUpdated = true;
    }
}
