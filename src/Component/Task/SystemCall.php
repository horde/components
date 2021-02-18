<?php
/**
 * Horde\Components\Component\Task\SystemCall:: Run system calls from tasks
 *
 * PHP version 7
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <lang@b1-systems.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */


namespace Horde\Components\Component\Task;
/**
 * Components\Component\Task\SystemCall:: Run system calls from tasks
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
trait SystemCall
{
    /**
     * Run a system call.
     *
     * @param string $call The system call to execute.
     *
     * @return string The command output.
     */
    protected function system($call)
    {
        if (!$this->getTasks()->pretend()) {
            //@todo Error handling
            return system($call);
        } else {
            $this->getOutput()->info(sprintf('Would run "%s" now.', $call));
        }
    }

    /**
     * Run a system call.
     *
     * @param string $call       The system call to execute.
     * @param string $target_dir Run the command in the provided target path.
     *
     * @return string The command output.
     */
    protected function systemInDirectory($call, $target_dir)
    {
        if (!$this->getTasks()->pretend()) {
            $old_dir = getcwd();
            chdir($target_dir);
        }
        $result = $this->system($call);
        if (!$this->getTasks()->pretend()) {
            chdir($old_dir);
        }
        return $result;
    }
    /**
     * Run a system call and capture output.
     *
     * @param string $call The system call to execute.
     *
     * @return SystemCallResult The command output.
     */
    protected function exec($call)
    {
        if (!$this->getTasks()->pretend()) {
            exec($call, $output, $retval);
            return new SystemCallResult($output, $retval);
        } else {
            $this->getOutput()->info(sprintf('Would run "%s" now.', $call));
        }
        return new SystemCallResult([], 0);
    }

    /**
     * Run a system call in a given dir and capture output.
     *
     * @param string $call       The system call to execute.
     * @param string $target_dir Run the command in the provided target path.
     *
     * @return SystemCallResult The command output.
     */
    protected function execInDirectory($call, $target_dir)
    {
        if (!$this->getTasks()->pretend()) {
            $old_dir = getcwd();
            chdir($target_dir);
        }
        $result = $this->exec($call);
        if (!$this->getTasks()->pretend()) {
            chdir($old_dir);
        }
        return $result;
    }
}