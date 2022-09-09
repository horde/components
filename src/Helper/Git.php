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
declare(strict_types=1);

namespace Horde\Components\Helper;

use Horde\Components\Component;
use Horde\Components\Component\Task\SystemCallResult;
use Horde\Components\Output;
use RuntimeException;

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

    /**
     * Constructor
     *
     * @param string $gitBin  Path to git binary. Empty string to autodetect.
     * @param array  $options Any options this helper consumes. None yet.
     */
    public function __construct(string $gitBin = '', array $options = [])
    {
        if (empty($gitBin)) {
            $this->gitBin = $this->detectGitBin();
        }
    }


    /**
     * Workflow: Clone a component
     *
     * Add safety logic and output to the bare clone command
     * Authentication/Secrets handling is out of scope.
     * Configure git secrets handler or ssh key externally if needed.
     *
     * @param Output $output       The output utility
     * @param string $cloneUrl     The source repo URI
     * @param string $componentDir Where to clone to
     * @param string $branch       Optional the branch to check out
     *
     * @return bool True if workflow was successful
     */
    public function workflowClone(
        Output $output,
        string $cloneUrl,
        string $componentDir,
        string $branch = ''
    ): bool {
        // TODO: Check if empty or missing before cloning
        // TODO: Mind pretend mode
        $output->info(
            sprintf(
                'Will clone component from %s to %s',
                $cloneUrl,
                $componentDir
            )
        );
        $this->clone($cloneUrl, $componentDir, $branch);
        return true;
    }

    /**
     * Workflow Checkout a branch
     *
     * Checkout an existing local branch or
     * Set up a known remote branch as a tracking local branch.
     *
     * Do nothing and return success if already checked out
     * Return false if neither local nor remote branch found
     *
     * @param Output $output       The output utility
     * @param string $componentDir The Component checkout dir
     * @param string $branch       The branch to check out
     *
     * @return bool True if workflow was successful
     */
    public function workflowCheckout(
        Output $output,
        string $componentDir,
        string $branch
    ): bool {
        // Do nothing if already checked out
        if ($branch == $this->getCurrentBranch($componentDir)) {
            $output->info('Branch already checked out');
            return true;
        }
        if ($this->localBranchExists($componentDir, $branch)) {
            $output->info('Branch exists locally, checking out');
            $this->checkoutBranch($componentDir, $branch);
            return true;
        }
        if ($this->remoteBranchExists($componentDir, $branch)) {
            $output->info('Branch exists in remote, tracking and checking out');
            $this->createRemoteTrackingBranch($componentDir, $branch);
            $this->checkoutBranch($componentDir, $branch);
            return true;
        }
        $output->warn("The branch $branch does not exist on local copy or in remotes");
        return false;
    }

    /**
     * Workflow create a branch if missing
     *
     * Return success if already present.
     * If missing,
     * Set up a known remote branch as a tracking local branch
     * Create a missing branch from local source branch
     * Create a missing local source branch from remote first
     *
     * Return false if missing and source branch also missing.
     *
     * Do nothing and return success if already checked out
     * Return false if neither local nor remote branch found
     *
     * @param Output $output       The output utility
     * @param string $componentDir The Component checkout dir
     * @param string $branch       The branch to check out
     * @param string $source       The source branch
     *
     * @return bool True if workflow was successful
     */
    public function workflowBranch(
        Output $output,
        string $componentDir,
        string $branch,
        string $source
    ): bool {
        if ($this->localBranchExists($componentDir, $branch)) {
            $output->info('branch already exists');
            return true;
        }
        if ($this->remoteBranchExists($componentDir, $branch)) {
            $output->info('Branch exists in remote, tracking and checking out');
            $this->createRemoteTrackingBranch($componentDir, $branch);
            return true;
        }
        // Branch needs to be created
        if ($this->localBranchExists($componentDir, $source)) {
            $output->info('Creating from local source branch');
            $this->branchFromLocal($componentDir, $branch, $source);
            return true;
        }
        if ($this->remoteBranchExists($componentDir, $source)) {
            $output->info('First creating source branch from remote');
            $this->createRemoteTrackingBranch($componentDir, $source);
            $output->info('Creating from local source branch');
            $this->branchFromLocal($componentDir, $branch, $source);
            return true;
        }
        // No source found
        $output->warn('No Source Branch found. Cannot checkout.');
        return false;
    }

    /**
     * Workflow update a branch
     *
     * Intended for release preparation.
     *
     * Run fetch
     * Create local source branch from remote if missing.
     * Update the source branch from remote source.
     * Create branch from local source branch if missing.
     * Update the target branch from remote.
     * Update the target branch from local source.
     *
     * Return false if missing and source branch also missing.
     *
     * Do nothing and return success if already checked out
     * Return false if neither local nor remote branch found
     *
     * @param Output $output       The output utility
     * @param string $componentDir The Component checkout dir
     * @param string $branch       The branch to check out
     * @param string $source       The source branch
     *
     * @return bool True if workflow was successful
     */
    public function workflowUpdate(
        Output $output,
        string $componentDir,
        string $branch,
        string $source
    ): void {
        $output->info('Fetching remote');
        $this->fetch($componentDir);
        // Set up local source branch.
        if ($this->localBranchExists($componentDir, $source)) {
            $output->info('Local source branch already exists');
        } else {
            if ($this->remoteBranchExists($componentDir, $source)) {
                $output->info('Local source branch created from remote');
                $this->createRemoteTrackingBranch($componentDir, $source);
            }
        }
        // If we do not have a source branch by now, give up.
        if (!$this->localBranchExists($componentDir, $source)) {
            $output->warn('Neither local nor remote source branch');
            // TODO: Exception instead? This is never an intended scenario.
            return;
        }
        // Update local source branch from remote if exists.
        if ($this->remoteBranchExists($componentDir, $source)) {
            $this->checkoutBranch($componentDir, $source);
            // TODO: Support other remotes than origin?
            $this->rebase($componentDir, $source, 'origin/' . $source);
        }
        // Local source branch is good by now.

        // Ensure we have a local target branch
        if ($this->localBranchExists($componentDir, $branch)) {
            $output->info('Local branch already exists');
        } elseif ($this->remoteBranchExists($componentDir, $branch)) {
            $output->info('Setting up branch from remote');
            $this->createRemoteTrackingBranch($componentDir, $branch);
        } else {
            $output->info('No remote branch. Setting up branch from source');
            $this->branchFromLocal($componentDir, $branch, $source);
        }
        $output->info('Checkout branch ' . $branch);
        $this->checkoutBranch($componentDir, $branch);
        // Update from remote first if possible
        if ($this->remoteBranchExists($componentDir, $branch)) {
            // TODO: Support other remotes than origin?
            $output->info('Rebase branch on remote first' . $branch);
            $this->rebase($componentDir, $branch, 'origin/' . $branch);
        }
        // Finally: Update from local source branch
        $output->info('Rebase branch on source' . $source);
        $this->rebase($componentDir, $branch, $source);
        $output->info('Done updating branch');
        $this->checkoutBranch($componentDir, $branch);
        return;
    }
    /**
     * Check some well known locations, fallback to which
     *
     * @return string Fully qualified location of git command
     * @throws RuntimeException
     */
    public function detectGitBin(): string
    {
        $candidates = [
            '/usr/bin/git',
            '/usr/local/bin/git',
            '/bin/git',
        ];
        foreach ($candidates as $candidatePath) {
            if (file_exists($candidatePath)) {
                return realpath($candidatePath);
            }  
        }
        throw new RuntimeException('Could not detect git binary');
    }

    /**
     * Run git branch -r
     *
     * @return string[] Remote branches
     */
    public function getRemoteBranches(string $localDir): array
    {
        return $this->execInDirectory(
            $this->gitBin . ' branch -r --format "%(refname:short)"',
            $localDir
        )->getOutputArray();
    }

    /**
     * Run git branch
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
        )->getOutputString();
    }

    /**
     * Rebase branch on a local or remote source
     *
     * @param string $localDir Full path to repo
     * @param string $branch   Full path to repo
     * @param string $source   Full path to repo. If empty, origin/branch
     *
     * @return string SystemCallResult
     */
    public function rebase(
        string $localDir,
        string $branch,
        string $source = ''
    ): SystemCallResult {
        if ($source == '') {
            $source = 'origin/' . $branch;
        }
        $cmd = sprintf(
            '%s rebase %s %s',
            $this->gitBin,
            $branch,
            $source
        );
        return $this->execInDirectory(
            $cmd,
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
    public function remoteBranchExists(
        string $localDir,
        string $branch,
        string $remote = 'origin'
    ): bool {
        $remoteBranches = $this->getRemoteBranches($localDir);
        return in_array("$remote/$branch", $remoteBranches);
    }

    /**
     * Check if a local branch exists
     *
     * @param string $localDir Full path to repo
     * @param string $branch   The branch to check for
     *
     * @return bool True if exists
     */
    public function localBranchExists(string $localDir, string $branch): bool
    {
        return in_array($branch, $this->getLocalBranches($localDir));
    }

    public function localTagExists(string $localDir, string $tag): bool
    {
        return in_array($tag, $this->getLocalTags($localDir));
    }

    /**
     * List the local git tags in the repository
     * 
     * @param string $localDir The path to the repository
     * @return array<string> A list of available tags
     */
    public function getLocalTags(string $localDir): array
    {
        $cmd = $this->gitBin . ' tag -l';
        $res = $this->execInDirectory($cmd, $localDir);
        return $res->getOutputArray();
    }

    /**
     * Checkout a local branch (primitive)
     *
     * @param string $localDir Full path to repo
     * @param string $branch   The branch to check for
     */
    public function checkoutBranch(string $localDir, string $branch): SystemCallResult
    {
        $cmd = $this->gitBin . ' checkout ' . $branch;
        return $this->execInDirectory($cmd, $localDir);
    }

    /**
     * Clone a remote repo (primitive)
     *
     * @param string $uri      The remote repo to clone
     * @param string $localDir The local target dir
     * @param string $branch   Optional: Checkout a specific branch
     */
    public function clone(string $uri, string $localDir, string $branch = ''): SystemCallResult
    {
        $options = '';
        if ($branch) {
            $options .= '-b ' . $branch;
        }
        $cmd = sprintf(
            '%s %s clone %s %s',
            $this->gitBin,
            $options,
            $uri,
            $localDir
        );
        echo $cmd;

        return $this->exec($cmd);
    }

    /**
     * Create a branch from a local source branch
     *
     * @param string $localDir The repo path
     * @param string $branch   The new branch's name
     * @param string $source   The source branch to use
     */
    public function branchFromLocal(
        string $localDir,
        string $branch,
        string $source
    ): SystemCallResult {
        // git branch -t $branch origin/$branch
        $cmd = sprintf(
            '%s branch %s %s',
            $this->gitBin,
            $branch,
            $source
        );
        return $this->execInDirectory($cmd, $localDir);
    }

    /**
     * Create a local branch from a remote of same name
     *
     * @param string $localDir The repo path
     * @param string $branch   The new branch's name
     * @param string $remote   The remote
     */
    public function createRemoteTrackingBranch(
        string $localDir,
        string $branch,
        string $remote = 'origin'
    ): \Horde\Components\Component\Task\SystemCallResult {
        // git branch -t $branch origin/$branch
        $cmd = sprintf(
            '%s branch -t %s %s/%s',
            $this->gitBin,
            $branch,
            $remote,
            $branch
        );
        return $this->execInDirectory($cmd, $localDir);
    }

    /**
     * Fetch remote metadata
     *
     * @param string $localDir Full path to repo
     */
    public function fetch(string $localDir): SystemCallResult
    {
        $cmd = sprintf(
            '%s fetch --all --tags',
            $this->gitBin
        );
        return $this->execInDirectory($cmd, $localDir);
    }

    public function addRemote(): void
    {
        // TODO
    }

    public function removeRemote(): void
    {
        // TODO
    }

    public function updateRemote(): void
    {
        // TODO
    }

    public function pull(): void
    {
        // TODO
    }

    /**
     * Ensure the git checkout in a dir does not contain uncommitted changes
     * 
     * @param string $localDir 
     * @return bool 
     */
    public function checkoutIsClean(string $localDir): bool
    {
        $cmd = $this->gitBin . ' diff --exit-code';
        $res = $this->systemInDirectory(
            $cmd,
            $localDir
        );
        if ($res !== '') {
            return false;
        }
        $cmd = $this->gitBin . ' diff --exit-code --cached';
        $res = $this->systemInDirectory(
            $cmd,
            $localDir
        );
        if ($res !== '') {
            return false;
        }
        $cmd = $this->gitBin . ' status --untracked-files=no --porcelain';
        $res = $this->systemInDirectory(
            $cmd,
            $localDir
        );
        if ($res !== '') {
            return false;
        }
        return true;
    }

    /**
     * @param string $localDir The checkout dir of the component
     * @param string $remote   Optional remote, defaults to origin
     */
    public function push(string $localDir, $remote = 'origin', bool $force = false): void
    {
        $forceCmd = $force ? '--force' : '';
        $cmd = sprintf(
            'git push %s --set-upstream %s %s --follow-tags',
            $forceCmd,
            $remote,
            $this->getCurrentBranch($localDir)
        );
        $this->systemInDirectory(
            $cmd,
            $localDir
        );
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
     */
    public function add(string $item): void
    {
        $this->_added[$item] = $item;
    }

    /**
     * Add all modified files and commit them.
     *
     * @param string $log The commit message.
     */
    public function commit(string $localDir, string $log): void
    {
        $wd = null;
        if (empty($this->_added)) {
            return;
        }
        foreach ($this->_added as $path => $wd) {
            $this->systemInDirectory('git add ' . $path, $localDir);
        }
        $this->systemInDirectory('git commit -m "' . $log . '"', $wd);
        $this->_added = [];
    }

    /**
     * Tag the component.
     *
     * @param string $tag       Tag name.
     * @param string $message   Tag message.
     * @param string $directory The working directory.
     * @param bool   $force     If the tag already exists, overwrite it.   
     */
    public function tag(string $localDir, string $tag, string $message, bool $force = false): void
    {
        $forceSwitch = $force ? '--force ' : '';
        $cmd = $this->gitBin . ' tag ' . $forceSwitch . '-m "' . $message . '" ' . $tag;
        $this->systemInDirectory(
            $cmd,
            $localDir
        );
    }

    /**
     * Check the git repo's current position for a branch name, tag name or bare position.
     * 
     * @param string $localDir 
     * @return string 
     */
    public function getCurrentRefName(string $localDir): string
    {
        $cmd = $this->gitBin . ' symbolic-ref --short -q HEAD';
        $branch = $this->systemInDirectory(
            $cmd,
            $localDir
        );
        if ($branch) {
            return $branch;
        }
        $cmd = $this->gitBin . ' describe --tags';
        // TODO: Check if the output is really a tag and if not, get a full ref hash
        $tag = $this->systemInDirectory(
            $cmd,
            $localDir
        );
        if ($tag) {
            return $tag;
        }
        return '';
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
        return new SystemCallResult([], 0);
    }

    /**
     * Run a system call.
     *
     * @param string $call       The system call to execute.
     * @param string $target_dir Run the command in the provided target path.
     *
     * @return string The command output.
     */
    protected function systemInDirectory(string $call, string $target_dir): string
    {
        $old_dir = null;
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
    protected function execInDirectory(string $call, string $targetDir): SystemCallResult
    {
        $oldDir = null;
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
