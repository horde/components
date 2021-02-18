<?php
/**
 * Components_Release_Task_GitPush:: Push any changes to a remote server
 *
 * PHP version 7
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <lang@b1-systems.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
namespace Horde\Components\Release\Task;

use Horde\Components\Component\Task\SystemCall;
use Horde\Components\Component\Task\SystemCallResult;
/**
 * Components_Release_Task_GitPush:: Push any changes to a remote server
 *
 * Copyright 2011-2019 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <lang@b1-systems.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

class GitPush extends Base
{
    /**
     * Run the task.
     *
     * Push the branch to the desired remote
     * Supports pretend mode
     * 
     * @param array $options Additional options by-reference.
     *                       Keys:
     *                       'remote' Defaults to origin
     *                       'branch' Default empty, use tracking branch
     *
     * @return void
     */
    public function run(&$options)
    {
        $remote = $options['remote'] ?? 'origin';
        // use tracking branch name unless otherwise stated
        $branch = $options['branch'] ?? '';
        if ($this->getTasks()->pretend()) {
            $this->getOutput()->info(
                sprintf('Would push to remote "%s".', $remote)
            );
            return;
        }
        $this->getOutput()->info(
            sprintf('Push to remote "%s".', $remote)
        );
        $res = $this->_push($remote, $branch);
        $this->_pushTags($remote, $branch);
        if ($res->getReturnValue()) {
            $this->getOutput()->fail(sprintf(
                "Failed to push with code '%d' and result:\n%s",
		$res->getReturnValue(),
		$res->getOutputString()
	));
        }
    }

    /**
     * This task may not be skipped
     * 
     * @return boolean Always false, this task may not be skipped
     */
    public function skip($options)
    {
        return false;
    }

    /**
     * Push a the current checkout to remote
     * 
     * @param string $remote The remote to push to
     * @param string $branch The remote branch. Leave empty for tracking branch
     * 
     * @return SystemCallResult The result object
     * Might make sense to factor out into a git helper for reuse?
     */
    protected function _push(string $remote, string $branch = '')
    {
        return $this->execInDirectory(
            sprintf('git push %s %s', $remote, $branch),
            $this->getComponent()->getComponentDirectory()      
        );
    }

    /**
     * Push a the current checkout's tags to remote
     * 
     * @param string $remote The remote to push to
     * @param string $branch The remote branch. Leave empty for tracking branch
     * 
     * @return SystemCallResult The result object
     * Might make sense to factor out into a git helper for reuse?
     */
    protected function _pushTags(string $remote, string $branch = '')
    {
        return $this->execInDirectory(
            sprintf('git push %s %s --tags', $remote, $branch),
            $this->getComponent()->getComponentDirectory()      
        );
    }
}
