<?php
/**
 * Components_Release_Task_CommitPostRelease:: commits any changes after to the
 * release.
 *
 * PHP version 5
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

/**
 * Components_Release_Task_CommitPostRelease:: commits any changes after to the
 * release.
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
class Components_Release_Task_CommitPostRelease
extends Components_Release_Task_Base
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
        if (empty($options['next_version'])) {
            if (empty($options['old_version'])) {
                $options['old_version'] = $this->getComponent()->getVersion();
            }
            $next_version = Components_Helper_Version::nextPearVersion($options['old_version']);
        } else {
            $next_version = $options['next_version'];
        }
        if (isset($options['commit'])) {
            $options['commit']->commit(
                'Development mode for ' . $this->getComponent()->getName()
                . '-' . Components_Helper_Version::validatePear($next_version)
            );
        }
    }
}
