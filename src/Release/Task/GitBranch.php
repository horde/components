<?php
/**
 * Components_Release_Task_GitBranch:: Check or enforce a branch checkout
 *
 * PHP version 7
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <lang@b1-systems.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

namespace Horde\Components\Release\Task;

use Horde\Components\Helper\Git as GitHelper;

/**
 * Components_Release_Task_GitBranch:: Check or enforce a branch checkout
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
class GitBranch extends Base
{
    /**
     * Ask for the Qc\Tasks dependency
     *
     * @return array The list of dependencies requested
     */
    public function askDependencies(): array
    {
        return ['git' => GitHelper::class];
    }

    /**
     * Run the task.
     *
     * Checkout the wanted branch
     * Supports pretend mode
     *
     * @param array $options Additional options by reference.
     *
     * @return void;
     */
    public function run(&$options): void
    {
        // use master branch unless otherwise stated
        $wanted = $options['git_branch'] ?? 'master';
        // create branch from master unless otherwise stated
        $source = $options['git_source_branch'] ?? 'master';
        $current = $this->_currentBranch();
        // save the current git branch to options
        // unless another one was saved before (running twice)
        $options['git_orig_branch'] ??= $current;
        if ($current == $wanted) {
            $this->getOutput()->info(
                sprintf('Branch "%s" is checked out.', $wanted)
            );
            return;
        }
        if ($this->getTasks()->pretend()) {
            $this->getOutput()->info(
                sprintf('Would check out branch "%s".', $wanted)
            );
            return;
        }
        $this->getOutput()->info(
            sprintf('Checking out branch "%s".', $wanted)
        );
        if (empty($options['update'])) {
            $this->_checkout($wanted, $source);
        } else {
            $this->_update($wanted, $source);
        }
        return;
    }

    /**
     * Validate if the desired branch actually exists
     *
     * @param array $options Additional options.
     *
     * @return array An empty array if all preconditions are met and a list of
     *               error messages otherwise.
     */
    public function preValidate($options): array
    {
        $wantedBranch = $options['git_branch'] ?? 'master';
        // if the branch needs to be already checked out
        $prereq = $options['git_branch_prereq'] ?? false;
        $issues = [];
        $pretend = $this->getTasks()->pretend();
        if ($pretend) {
            $git = 'git';
        } else {
            $git = $this->_whichGit();
        }
        if (empty($git)) {
            $issues[] = 'Could not detect installed git binary';
            return $issues;
        }
        if ($prereq && !$this->_branchExists($wantedBranch)) {
            $issues[] = "The local branch $wantedBranch does not exist";
        }
        $currentBranch = $this->_currentBranch();
        if ($prereq && ($wantedBranch != $currentBranch)) {
            $issues[] = "Checked out $currentBranch is not $wantedBranch";
        }
        // Don't break the pipeline in pretend mode
        $issues = $pretend ? [] : $issues;
        return $issues;
    }

    /**
     * Validate if the checkout succeeded (or was not necessary)
     *
     * A git checkout may fail for multiple reasons, including uncommitted
     * changes in the current checkout. We don't want to continue release
     * if this happens as all sorts of weird accidents may happen
     *
     * @param array $options Additional options.
     *
     * @return array An empty array if all postconditions are met and a list of
     *               error messages otherwise.
     */
    public function postValidate($options): array
    {
        $pretend = $this->getTasks()->pretend();
        $wantedBranch = $options['git_branch'] ?? 'master';
        $issues = [];
        $currentBranch = $this->_currentBranch();
        if (!$pretend && ($wantedBranch != $currentBranch)) {
            $issues[] = "Checked out $currentBranch is not $wantedBranch";
        }
        return $issues;
    }

    /**
     * This task may not be skipped
     *
     * @param array $options Not used, signature only
     *
     * @return boolean Always false, this task may not be skipped
     */
    public function skip($options = []): bool
    {
        return false;
    }

    /**
     * Look for the git binary
     *
     * @return string|void
     * Might make sense to factor out into a git helper for reuse?
     */
    protected function _whichGit(): \Horde\Components\Component\Task\SystemCallResult
    {
        return $this->exec('which git');
    }

    /**
     * Get the current branch
     *
     * @return string current branch
     */
    protected function _currentBranch(): string
    {
        return $this->getDependency('git')->getCurrentBranch(
            $this->getComponent()->getComponentDirectory()
        );
    }

    /**
     * Check out a branch
     *
     * @param string $branch The branch to check out
     * @param string $source The source branch to create from if missing
     *
     */
    protected function _checkout(string $branch, string $source)
    {
        return $this->getDependency('git')->workflowCheckout(
            $this->getOutput(),
            $this->getComponent()->getComponentDirectory(),
            $branch,
            $source
        );
    }

    /**
     * Update branch from source branch
     *
     * @param string $branch The branch to check out
     * @param string $source The source branch to create from if missing
     */
    protected function _update(string $branch, string $source)
    {
        return $this->getDependency('git')->workflowUpdate(
            $this->getOutput(),
            $this->getComponent()->getComponentDirectory(),
            $branch,
            $source
        );
    }

    /**
     * Get the list of branch
     *
     * @return string[] The list of local branches
     */
    protected function _getBranches(): array
    {
        return $this->getDependency('git')->getLocalBranches(
            $this->getComponent()->getComponentDirectory()
        );
    }

    /**
     * Check if a given local branch exists
     *
     * @param string $branch The branch to check
     *
     * @return boolean  True if the branch exists
     */
    protected function _branchExists(string $branch): bool
    {
        return in_array($branch, $this->_getBranches());
    }
}
