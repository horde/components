<?php
/**
 * Horde\Components\Helper\Git wraps git operations.
 *
 * PHP Version 7
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <lang@b1-systems.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
namespace Horde\Components\Helper;
use Horde\Components\Component;
use Horde\Components\Output;
use Horde\Components\Component\Task\SystemCallResult;

/**
 * HordeComponents\Helper\Git:: wraps git operations.
 * 
 * Git Helper provides two classes of methods:
 * 
 * Primitives which just do what they say
 * Primitives do not produce output unless by invoking commands
 * 
 * Workflows which provide some higher level usefulness
 * Workflow methods understand pretend mode and use output
 *
 * Copyright 2020-2021 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <lang@b1-systems.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Git
{
    /**
     * @var string Path to git binary
     */
    protected string $gitBin;
    /**
     * @var string Working directory
     */
    protected string $cwd;

    public function __construct(string $gitBin = '', array $options = [])
    {
        if (empty($gitBin)) {
            $this->gitBin = $this->detectGitBin();
        }
        
    }

    /**
     * Check some well known locations, fallback to which
     * @return string Fully qualified location of git command
     */
    public function detectGitBin(): string
    {
        // TODO
        return '/usr/bin/git';
    }

    /**
     * git branch -r
     * 
     * @return string[] Remote branches
     */
    public function getRemoteBranches(string $localDir): array
    {
        return $this->execInDirectory(
            $this->gitBin . ' branch --format "%(refname:short)"',
            $localDir
        )->getOutputArray();

    }

    /**
     * git branch
     * 
     * @param string $localDir Full path to repo
     * 
     * @return string[] Local branches
     */
    public function getLocalBranches(string $localDir): array
    {
        return $this->execInDirectory(
            $this->gitBin . ' branch --format "%(refname:short)"',
            $localDir
        )->getOutputArray();
    }

    /**
     * Get currently checked out branch
     * 
     * @param string $localDir Full path to repo
     * 
     * @return string The active branch
     */
    public function getCurrentBranch(string $localDir): string
    {
        return $this->execInDirectory(
            $this->gitBin . ' rev-parse --abbrev-ref HEAD', 
            $localDir
        );
    }

    /**
     * Check if a remote branch exists as of last fetch
     * 
     * @param string $localDir Full path to repo
     * @param string $branch   The branch to check for
     * 
     * @return bool True if exists
     */
    public function remoteBranchExists(string $localDir, string $branch): bool
    {

    }

    /**
     * Check if a local branch exists
     * 
     * @param string $localDir Full path to repo
     * @param string $branch   The branch to check for
     * 
     * @return bool True if exists
     */
    public function localBranchExists(string $localDir, string $branch)
    {
        return in_array($branch, $this->getLocalBranches($localDir));
    }

    /**
     * Checkout a local branch
     * 
     * @param string $localDir Full path to repo
     * @param string $branch   The branch to check for
     * 
     * @return SystemCallResult
     */
    public function checkoutBranch(string $localDir, string $branch): SystemCallResult
    {
        $cmd = $this->gitBin . ' checkout ' . $branch;
        return $this->execInDirectory($cmd, $localDir);
    }
    
    public function clone(string $uri, string $localDir, string $branch = ''): SystemCallResult
    {
        $options = '';
        if ($branch) {
            $options .= '-b ' . $branch;
        }
        return $this->exec(
            sprintf(
                '%s %s clone %s %s',
                $this->gitBin,
                $options,
                $uri,
                $localDir
            )
        );
    }

    /**
     * Fetch remote metadata
     * 
     * @param string $localDir Full path to repo
     * 
     * @return SystemCallResult
     */
    public function fetch(string $localDir): SystemCallResult
    {
        $cmd = sprintf(
            '%s fetch --all --tags',
            $this->gitBin);
        return $this->execInDirectory($cmd, $localDir);
    }

    public function addRemote()
    {
        // TODO
    }

    public function removeRemote()
    {
        // TODO
    }

    public function updateRemote()
    {
        // TODO
    }

    public function pull()
    {
        // TODO
    }

    /**
     * These methods below are imported from Commit helper 
     * which I'd like to deprecate.
     */

     /**
     * Add a path to be included in the commit and record the working directory
     * for this git operation.
     *
     * @param string $item The relative path to the modified file
     *
     * @return void
     */
    public function add(string $item)
    {
        $this->_added[$item] = $item;
    }

    /**
     * Add all modified files and commit them.
     *
     * @param string $log The commit message.
     *
     * @return void
     */
    public function commit(string $localDir, string $log)
    {
        if (empty($this->_added)) {
            return;
        }
        foreach ($this->_added as $path => $wd) {
            $this->systemInDirectory('git add ' . $path, $localDir);
        }
        $this->systemInDirectory('git commit -m "' . $log . '"', $wd);
        $this->_added = array();
    }

    /**
     * Tag the component.
     *
     * @param string $tag       Tag name.
     * @param string $message   Tag message.
     * @param string $directory The working directory.
     *
     * @return void
     */
    public function tag(string $tag, string $message, string $directory)
    {
        $this->systemInDirectory(
            'git tag -f -m "' . $message . '" ' . $tag, $directory
        );
    }

    /**
     * Run a system call.
     *
     * @param string $call The system call to execute.
     *
     * @return string The command output.
     */
    protected function system(string $call): string
    {
        if (empty($this->options['pretend'])) {
            //@todo Error handling
            return \system($call);
        } else {
            $this->output->info(\sprintf('Would run "%s" now.', $call));
        }
    }

    /**
     * Run a system call and capture output.
     *
     * @param string $call The system call to execute.
     *
     * @return SystemCallResult The command output.
     */
    protected function exec(string $call): SystemCallResult
    {
        if (empty($this->options['pretend'])) {
            //@todo Error handling
            \exec($call, $output, $retval);
            return new SystemCallResult($output, $retval);
        }
        $this->output->info(\sprintf('Would run "%s" now.', $call));
        return new SystemCallResult('', 0);
    }

    /**
     * Run a system call.
     *
     * @param string $call       The system call to execute.
     * @param string $target_dir Run the command in the provided target path.
     *
     * @return string The command output.
     */
    protected function systemInDirectory(string $call, string $target_dir)
    {
        if (empty($this->options['pretend'])) {
            $old_dir = getcwd();
            chdir($target_dir);
        }
        $result = $this->system($call);
        if (empty($this->options['pretend'])) {
            chdir($old_dir);
        }
        return $result;
    }

    /**
     * Run a system call in a given dir and capture output.
     *
     * @param string $call       The system call to execute.
     * @param string $targetDir Run the command in the provided target path.
     *
     * @return SystemCallResult The command output.
     */
    protected function execInDirectory(string $call, string $targetDir)
    {
        if (empty($this->options['pretend'])) {
            $oldDir = getcwd();
            chdir($targetDir);
        }
        $result = $this->exec($call);
        if (empty($this->options['pretend'])) {
            chdir($oldDir);
        }
        return $result;
    }
}