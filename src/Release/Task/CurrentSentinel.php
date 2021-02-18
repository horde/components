<?php
/**
 * Components_Release_Task_CurrentSentinel:: updates the CHANGES and the
 * Application.php/Bundle.php files with the current package version.
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
 * Components_Release_Task_CurrentSentinel:: updates the CHANGES and the
 * Application.php/Bundle.php files with the current package version.
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
class CurrentSentinel extends Sentinel
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
        $component = $this->getComponent();
        $changes_version = $component->getVersion();
        $result = $component->setVersion(
            $changes_version, null, $options
        );
        if (!$this->getTasks()->pretend()) {
            $component->saveWrappers();
            $this->getOutput()->ok($result);
        } else {
            $this->getOutput()->info($result);
        }
    }
}
