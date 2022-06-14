<?php
/**
 * Components_Release_Task_NextVersion:: updates the package.xml file with
 * information about the next component version.
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

/**
 * Components_Release_Task_NextVersion:: updates the package.xml file with
 * information about the next component version.
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
class NextVersion extends Base
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
        $errors = array();
        if (!isset($options['next_note']) || $options['next_note'] === null) {
            $errors[] = 'The "next_note" option has no value! What should the initial change log note be?';
        }
        return $errors;
    }

    /**
     * Run the task.
     *
     * @param array &$options Additional options.
     *
     * @return void
     */
    public function run(&$options)
    {
        $api_state = isset($options['next_apistate']) ? $options['next_apistate'] : null;
        $rel_state = isset($options['next_relstate']) ? $options['next_relstate'] : null;
        if (empty($options['next_version'])) {
            if (empty($options['old_version'])) {
                $options['old_version'] = $this->getComponent()->getVersion();
            }
            $versionPart = $options['version_part'] ?? 'patch';
            $next_version = HelperVersion::nextVersionByPart($options['old_version'], $versionPart);
        } else {
            $next_version = $options['next_version'];
        }
        if ($this->getTasks()->pretend()) {
            $options['old_wrappers'] = $this->getComponent()->cloneWrappers();
        }
        $result = $this->getComponent()->nextVersion(
            $next_version,
            $options['next_note'],
            $api_state,
            $rel_state,
            $options
        );
        if (!$this->getTasks()->pretend()) {
            $this->getOutput()->ok($result);
        } else {
            $this->getOutput()->info($result);
        }
    }
}
