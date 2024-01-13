<?php
/**
 * Components_Release_Task_Timestamp:: timestamps the package right before the
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

use Horde\Components\Exception;

/**
 * Components_Release_Task_Timestamp:: timestamps the package right before the
 * release.
 *
 * Copyright 2011-2024 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Timestamp extends Base
{
    /**
     * Validate the preconditions required for this release task.
     *
     * @param array $options Additional options.
     *
     * @return array An empty array if all preconditions are met and a list of
     *               error messages otherwise.
     * @throws Exception
     * @throws \Horde_Exception_NotFound
     */
    public function preValidate($options): array
    {
        if (!$this->getComponent()->getWrapper('ChangelogYml')->exists()) {
            return ['The component lacks a changelog.yml!'];
        }
        return [];
    }

    /**
     * Can the task be skipped?
     *
     * @param array $options Additional options.
     *
     * @return bool True if it can be skipped.
     */
    public function skip($options): bool
    {
        return false;
    }

    /**
     * Run the task.
     *
     * @param array &$options Additional options.
     */
    public function run(&$options): void
    {
        $result = $this->getComponent()
            ->timestamp($options);
        if (!$this->getTasks()->pretend()) {
            $this->getOutput()->ok($result);
        } else {
            $this->getOutput()->info($result);
        }
    }
}
