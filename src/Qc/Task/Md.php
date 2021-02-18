<?php
/**
 * Components_Qc_Task_Md:: runs a mess detection check on the component.
 *
 * PHP Version 7
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
namespace Horde\Components\Qc\Task;
use Horde\Components\Constants;

/**
 * Components_Qc_Task_Md:: runs a mess detection check on the component.
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
class Md extends Base
{
    /**
     * Get the name of this task.
     *
     * @return string The task name.
     */
    public function getName()
    {
        return 'mess detection';
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
        if (!class_exists('\\PHPMD\\PHPMD')) {
            return ['PHPMD is not available!'];
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
        $lib = realpath($this->_config->getPath() . '/lib');

        $renderer = new PHPMD\Renderer\TextRenderer();
        $renderer->setWriter(new PHPMD\Writer\StreamWriter(\STDOUT));

        $ruleSetFactory = new PHPMD\RuleSetFactory();
        $ruleSetFactory->setMinimumPriority(PHPMD\AbstractRule::LOWEST_PRIORITY);

        $phpmd = new PHPMD\PHPMD();

        $phpmd->processFiles(
            $lib,
            Constants::getDataDirectory() . '/qc_standards/phpmd.xml',
            array($renderer),
            $ruleSetFactory
        );

        return $phpmd->hasViolations();
    }
}
