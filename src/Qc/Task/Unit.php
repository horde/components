<?php
/**
 * Components_Qc_Task_Unit:: runs the test suite of the component.
 *
 * PHP Version 7
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
namespace Horde\Components\Qc\Task;

/**
 * Components_Qc_Task_Unit:: runs the test suite of the component.
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
class Unit extends Base
{
    /**
     * Get the name of this task.
     *
     * @return string The task name.
     */
    public function getName()
    {
        return 'PHPUnit testsuite';
    }

    /**
     * Validate the preconditions required for this release task.
     *
     * @param array $options Additional options.
     *
     * @return array An empty array if all preconditions are met and a list of
     *               error messages otherwise.
     */
    public function validate(array $options = []): array
    {
        if (!class_exists('Horde_Test_AllTests')) {
            return ['Horde_Test is not installed!'];
        }
        if (!class_exists('PHPUnit_Runner_BaseTestRunner') && !class_exists('PHPUnit\Runner\BaseTestRunner')) {
            return ['PHPUnit is not installed!'];
        }
        return [];
    }

    /**
     * Run the task.
     *
     * @param array &$options Additional options.
     *
     * @return integer Number of errors.
     */
    public function run(array &$options = [])
    {
        try {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(realpath($this->_config->getPath() . '/test')));
        } catch (Exception $e) {
            return false;
        }

        $result = null;
        foreach ($iterator as $file) {
            if ($file->getFilename() == 'AllTests.php') {
                $result = \Horde_Test_AllTests::init(strval($file))->run();
            }
        }

        if ($result) {
            return $result->errorCount() + $result->failureCount();
        }
    }
}
