<?php

/**
 * Components_Release_Task_CommitPreRelease:: commits any changes prior to the
 * release.
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
 * Copyright 2011-2026 Horde LLC (http://www.horde.org/)
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
     */
    public function run(&$options): void
    {
        if (isset($options['commit'])) {
            $componentName = $this->getComponent()->getName();
            $version = $this->getComponent()->getVersion();

            // Conventional Commit format: chore(release): bump version to X.Y.Z
            $message = sprintf(
                "chore(release): bump version to %s\n\nRelease %s-%s",
                $version,
                $componentName,
                $version
            );

            $options['commit']->commit($message);
        }
    }
}
