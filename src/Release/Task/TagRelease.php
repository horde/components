<?php
/**
 * Components_Release_Task_TagRelease:: tags the git repository.
 *
 * PHP Version 7
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
namespace Horde\Components\Release\Task;
use Horde\Components\Helper\Commit as HelperCommit;
/**
 * Components_Release_Task_TagRelease:: tags the git repository.
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
class TagRelease extends Base
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
        $release = 'v' . $this->getComponent()->getVersion();
        $this->getComponent()->tag(
            strtolower($release),
            'Released ' . $release . '.',
            new HelperCommit(
                $this->getOutput(),
                $options
            )
        );
    }
}
