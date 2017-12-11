<?php
/**
 * Components_Release_Task_CurrentSentinel:: updates the CHANGES and the
 * Application.php/Bundle.php files with the current package version.
 *
 * PHP version 5
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

/**
 * Components_Release_Task_CurrentSentinel:: updates the CHANGES and the
 * Application.php/Bundle.php files with the current package version.
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
class Components_Release_Task_CurrentSentinel
extends Components_Release_Task_Sentinel
{
    /**
     * Run the task.
     *
     * @param array &$options Additional options.
     *
     * @return NULL
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
