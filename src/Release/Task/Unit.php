<?php
/**
 * Components_Release_Task_Unit:: Run Quality Checks and Unit Tests
 *
 * PHP version 7
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <lang@b1-systems.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 * @link     https://wiki.horde.org/Doc/Dev/Component/Components
 * 
 * Adapted from original Code by Gunnar Wrobel and Jan Schneider
 */
namespace Horde\Components\Release\Task;

/**
 * Components_Release_Task_Unit:: Run Quality Checks and Unit Tests
 *
 * Wraps code originally part of the Release Runner
 * 
 * Copyright 2011-2019 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <lang@b1-systems.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 * @link     https://wiki.horde.org/Doc/Dev/Component/Components
 */

class Unit extends Base
{
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
        $component = $this->getComponent();
        $unit = $this->getDependency('qc')->getTask('unit', $component);
        return $unit->validate($options);
    }

    /**
     * This task can be skipped
     *
     * @param array $options Additional options. (Ignored)
     *
     * @return boolean Always True, can be skipped.
     */
    public function skip($options)
    {
        return true;
    }

    /**
     * Run the task.
     *
     * Push the branch to the desired remote
     * Supports pretend mode
     * 
     * @param array $options Additional options.
     *                       Keys:
     *                       'remote' Defaults to origin
     *                       'branch' Default empty, use tracking branch
     *
     * @return void
     */
    public function postValidate($options)
    {
        $issues = [];
        $component = $this->getComponent();
        $unit = $this->getDependency('qc')->getTask('unit', $component);
        if (!$unit->validate($options)) {
            $this->_output->info(
                'Running ' . $unit->getName() . ' on ' . $component->getName()
            );
            $this->_output->plain('');

            if ($unit->run($options)) {
                $this->_output->warn('Aborting due to unit test errors.');
                $issues[] = 'Aborting due to unit test errors.';
            }

            $this->_output->ok('No problems found in unit test.');
        }
        return $issues;
    }

    /**
     * Ask for the Qc\Tasks dependency
     * 
     * @return array The list of dependencies requested
     */
    public function askDependencies()
    {
        return ['qc' => 'Qc\Tasks'];
    }
}
