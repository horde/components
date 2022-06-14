<?php
/**
 * Components_Release_Task_CommitPostRelease:: commits any changes after to the
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
use Horde\Components\Helper\Version as HelperVersion;
use Horde\Components\Exception;
use Horde\Components\Output;

/**
 * Components_Release_Task_CommitPostRelease:: commits any changes after to the
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
class CommitPostRelease extends Base
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
        if (empty($options['next_version'])) {
            if (empty($options['old_version'])) {
                $next_version = $this->getComponent()->getVersion();
            } else {
                $versionPart = $options['version_part'] ?? 'patch';
                $next_version = HelperVersion::nextVersionByPart($options['old_version'], $versionPart);
            }
        } else {
            $next_version = $options['next_version'];
        }
        if (isset($options['commit'])) {
            $options['commit']->commit(
                'Development mode for ' . $this->getComponent()->getName()
                . '-' . HelperVersion::validatePear($next_version)
            );
        }
    }
}
