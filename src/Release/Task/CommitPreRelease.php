<?php
/**
 * Components_Release_Task_CommitPreRelease:: commits any changes prior to the
 * release.
 *
 * PHP Version 7
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
namespace Horde\Components\Release\Task;

/**
 * Components_Release_Task_CommitPreRelease:: commits any changes prior to the
 * release.
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
class CommitPreRelease extends Base
{
    /**
     * Run the task.
     *
     * @param array &$options Additional options.
     *
     * @return void
     */
    public function run(&$options)
    {
        if (isset($options['commit'])) {
            $options['commit']->commit(
                'Released ' . $this->getComponent()->getName()
                . '-' . $this->getComponent()->getVersion()
            );
        }
    }
}