<?php

/**
 * Horde\Components\Runner\GitSyncAll:: runner for repository synchronization.
 *
 * PHP Version 8.2+
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

declare(strict_types=1);

namespace Horde\Components\Runner;

use Exception;
use Horde\Components\ConventionalCommit;
use Horde\Components\Helper\Git as GitHelper;
use Horde\Components\Helper\Shell;
use Horde\Components\Output;
use Horde\Components\Report\BranchAliasCheck;
use Horde\Components\Report\RepositorySyncResult;
use Horde\Components\Report\SyncReport;
use Horde\Components\RuntimeContext\GitCheckoutDirectory;

/**
 * Horde\Components\Runner\GitSyncAll:: runner for repository synchronization.
 *
 * Copyright 2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class GitSyncAll
{
    /**
     * Constructor.
     *
     * @param GitCheckoutDirectory $checkoutDir Checkout directory context
     * @param GitHelper $gitHelper Git operations helper
     * @param Shell $shell Shell command executor
     * @param Output $output Output handler
     */
    public function __construct(
        private readonly GitCheckoutDirectory $checkoutDir,
        private readonly GitHelper $gitHelper,
        private readonly Shell $shell,
        private readonly Output $output
    ) {}

    /**
     * Run synchronization for repositories.
     *
     * @param bool $dryRun Preview mode without executing git operations
     * @param string|null $pattern Optional glob pattern to filter repositories
     * @param bool $allRepos Operate on all repositories (default: false, single repo)
     * @param string|null $singleRepo Path to single repository to sync (overrides pattern/allRepos)
     *
     * @return SyncReport The synchronization report
     */
    public function run(
        bool $dryRun = false,
        ?string $pattern = null,
        bool $allRepos = false,
        ?string $singleRepo = null
    ): SyncReport {
        $this->output->bold('=== Repository Synchronization Report ===');
        $this->output->plain('');

        $repositories = $this->getRepositories($pattern, $allRepos, $singleRepo);

        if (empty($repositories)) {
            $this->output->warn('No repositories found to sync');
            return new SyncReport([]);
        }

        $this->output->info(sprintf('Processing %d repositories...', count($repositories)));
        $this->output->plain('');

        $results = [];
        foreach ($repositories as $componentDir) {
            $result = $this->syncRepository((string) $componentDir, $dryRun);
            $results[] = $result;

            // Progress output
            $this->outputRepositoryResult($result);
        }

        // Final summary table
        $this->output->plain('');
        $report = new SyncReport($results);
        $this->presentSummaryTable($report);

        return $report;
    }

    /**
     * Synchronize a single repository.
     *
     * @param string $repoPath Path to repository
     * @param bool $dryRun Preview mode
     *
     * @return RepositorySyncResult The sync result
     */
    private function syncRepository(string $repoPath, bool $dryRun): RepositorySyncResult
    {
        $result = new RepositorySyncResult(
            path: $repoPath,
            name: basename($repoPath)
        );

        // 1. Fetch all remotes and tags
        if (!$dryRun) {
            try {
                $this->gitHelper->fetch($repoPath);
                $this->shell->shellExec('git fetch --all --tags --force', workingDir: $repoPath);
            } catch (Exception $e) {
                $result->skipReason = 'Fetch failed: ' . $e->getMessage();
                return $result;
            }
        }

        // 2. Check clean state
        $status = $this->shell->shellExec('git status --porcelain', workingDir: $repoPath);
        $result->isClean = empty(trim($status));

        if (!$result->isClean) {
            $result->skipReason = 'Uncommitted changes present';
            return $result;
        }

        // 3. Get current branch
        try {
            $result->currentBranch = $this->gitHelper->getCurrentBranch($repoPath);

            // Check for detached HEAD state
            if ($result->currentBranch === 'HEAD') {
                $result->skipReason = 'Detached HEAD state - checkout a branch first';
                return $result;
            }
        } catch (Exception $e) {
            $result->skipReason = 'Could not determine branch';
            return $result;
        }

        // 4. Attempt rebase
        $trackingBranch = $this->getTrackingBranch($repoPath, $result->currentBranch);
        if ($trackingBranch && !$dryRun) {
            try {
                $this->gitHelper->rebase($repoPath, $result->currentBranch, $trackingBranch);
                $result->rebaseSuccessful = true;
            } catch (Exception $e) {
                $result->rebaseSuccessful = false;
                $result->rebaseConflict = true;
                // Abort rebase
                try {
                    $this->shell->shellExec('git rebase --abort', workingDir: $repoPath);
                } catch (Exception $abortException) {
                    // Ignore abort errors
                }
            }
        } elseif ($trackingBranch) {
            // Dry run - assume success
            $result->rebaseSuccessful = true;
        }

        // 5. Check composer.json branch-alias
        $result->branchAliasConfig = $this->checkBranchAlias($repoPath, $result->currentBranch);

        // 6. Get last tag
        $result->lastTag = $this->getLastTag($repoPath);

        // 7. Count conventional commits since last tag
        if ($result->lastTag) {
            $commits = $this->analyzeCommitsSinceTag($repoPath, $result->lastTag);
            $result->featCount = $commits['feat'];
            $result->fixCount = $commits['fix'];
            $result->testCount = $commits['test'];
            $result->breakingCount = $commits['breaking'];
        }

        return $result;
    }

    /**
     * Analyze commits since a tag.
     *
     * @param string $repoPath Repository path
     * @param string $tag Tag name
     *
     * @return array Counts by type: ['feat' => int, 'fix' => int, 'test' => int, 'breaking' => int]
     */
    private function analyzeCommitsSinceTag(string $repoPath, string $tag): array
    {
        $counts = ['feat' => 0, 'fix' => 0, 'test' => 0, 'breaking' => 0];

        try {
            $gitLog = $this->gitHelper->getGitLog($repoPath, limit: 1000);

            $foundTag = false;
            foreach ($gitLog as $gitCommit) {
                // Stop at the tag commit
                $refs = is_array($gitCommit->refs) ? implode(' ', $gitCommit->refs) : (string) $gitCommit->refs;
                if (str_contains($gitCommit->subject, $tag) || str_contains($refs, $tag)) {
                    $foundTag = true;
                    break;
                }

                $conventional = ConventionalCommit::fromGitCommit($gitCommit);
                if ($conventional) {
                    $type = $conventional->type;
                    if (isset($counts[$type])) {
                        $counts[$type]++;
                    }
                    if ($conventional->breaking) {
                        $counts['breaking']++;
                    }
                }
            }
        } catch (Exception $e) {
            // If we can't get log, return zeros
        }

        return $counts;
    }

    /**
     * Check branch-alias configuration in composer.json.
     *
     * @param string $repoPath Repository path
     * @param string|null $branch Current branch name
     *
     * @return BranchAliasCheck|null Branch alias check result, or null if no composer.json
     */
    private function checkBranchAlias(string $repoPath, ?string $branch): ?BranchAliasCheck
    {
        $composerJson = $repoPath . '/composer.json';
        if (!file_exists($composerJson)) {
            return null;
        }

        try {
            $data = json_decode(file_get_contents($composerJson), true);
            if (!is_array($data)) {
                return null;
            }

            // Check for branch alias for current branch or FRAMEWORK_* branches
            $branchAlias = null;
            if ($branch !== null && isset($data['extra']['branch-alias']['dev-' . $branch])) {
                $branchAlias = $data['extra']['branch-alias']['dev-' . $branch];
            }

            return new BranchAliasCheck(
                configured: $branchAlias !== null,
                value: $branchAlias
            );
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Get last git tag.
     *
     * @param string $repoPath Repository path
     *
     * @return string|null Tag name or null
     */
    private function getLastTag(string $repoPath): ?string
    {
        try {
            $result = $this->shell->shellExec(
                'git describe --tags --abbrev=0 2>/dev/null',
                workingDir: $repoPath
            );
            return trim($result) ?: null;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Get tracking branch for a local branch.
     *
     * @param string $repoPath Repository path
     * @param string|null $branch Branch name
     *
     * @return string|null Tracking branch (e.g., "origin/FRAMEWORK_6_0") or null
     */
    private function getTrackingBranch(string $repoPath, ?string $branch): ?string
    {
        if ($branch === null) {
            return null;
        }

        try {
            $result = $this->shell->shellExec(
                sprintf("git rev-parse --abbrev-ref %s@{upstream} 2>/dev/null", escapeshellarg($branch)),
                workingDir: $repoPath
            );
            return trim($result) ?: null;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Get list of repositories to process.
     *
     * @param string|null $pattern Optional glob pattern
     * @param bool $allRepos Operate on all repositories
     * @param string|null $singleRepo Path to single repository (takes precedence)
     *
     * @return array Array of repository paths
     */
    private function getRepositories(?string $pattern, bool $allRepos, ?string $singleRepo): array
    {
        // Single repo mode takes precedence
        if ($singleRepo !== null) {
            if (is_dir($singleRepo . '/.git')) {
                return [$singleRepo];
            }
            $this->output->error("Not a git repository: {$singleRepo}");
            return [];
        }

        // Pattern or all-repos mode: iterate through checkout directory
        if ($pattern !== null || $allRepos) {
            $repos = [];
            foreach ($this->checkoutDir->getGitDirs() as $componentDir) {
                $repoPath = (string) $componentDir;

                // Apply pattern filter if provided
                if ($pattern !== null) {
                    $repoName = basename($repoPath);
                    if (!fnmatch($pattern, $repoName)) {
                        continue;
                    }
                }

                $repos[] = $repoPath;
            }

            return $repos;
        }

        // No arguments: this shouldn't happen, caller should provide singleRepo
        return [];
    }

    /**
     * Output result for a single repository.
     *
     * @param RepositorySyncResult $result The result
     *
     * @return void
     */
    private function outputRepositoryResult(RepositorySyncResult $result): void
    {
        $name = 'horde/' . $result->name;

        if (!$result->isClean) {
            $this->output->error(sprintf('[ ERROR! ] %s (dirty state, skipped)', $name));
        } elseif ($result->rebaseConflict) {
            $this->output->error(sprintf('[ ERROR! ] %s (rebase conflict on %s)', $name, $result->currentBranch));
        } elseif ($result->branchAliasConfig !== null && !$result->branchAliasConfig->configured) {
            $this->output->warn(sprintf('[  WARN  ] %s (clean, rebased, no branch-alias configured)', $name));
        } else {
            $commitInfo = '';
            if ($result->featCount > 0 || $result->fixCount > 0 || $result->testCount > 0) {
                $commitInfo = sprintf(
                    ', %d feat, %d fix, %d test since %s',
                    $result->featCount,
                    $result->fixCount,
                    $result->testCount,
                    $result->lastTag ?? 'no tag'
                );
            }
            $this->output->ok(sprintf('[   OK   ] %s (clean, rebased%s)', $name, $commitInfo));
        }
    }

    /**
     * Present summary table.
     *
     * @param SyncReport $report The report
     *
     * @return void
     */
    private function presentSummaryTable(SyncReport $report): void
    {
        $this->output->bold('=== Summary Table ===');
        $this->output->plain('');

        // Table header
        $this->output->plain(sprintf(
            '%-25s %-16s %-7s %-9s %-7s %-14s %-25s %-15s',
            'Component',
            'Branch',
            'Clean',
            'Rebased',
            'Alias',
            'Last Tag',
            'Commits (feat/fix/test)',
            'Suggest'
        ));
        $this->output->plain(str_repeat('-', 140));

        // Table rows
        foreach ($report->getResults() as $result) {
            $this->outputTableRow($result);
        }

        // Legend
        $this->output->plain('');
        $this->output->plain('Legend:');
        $this->output->plain('  ✓ = OK    ✗ = Issue    ~ = Warning    — = Skipped');
        $this->output->plain('');

        // Summary counts
        $this->output->plain('Actions needed:');
        if ($report->getDirtyCount() > 0) {
            $this->output->info(sprintf('  - Clean uncommitted changes: %d repositories', $report->getDirtyCount()));
        }
        if ($report->getConflictCount() > 0) {
            $this->output->info(sprintf('  - Resolve rebase conflicts: %d repositories', $report->getConflictCount()));
        }
        if ($report->getMissingAliasCount() > 0) {
            $this->output->info(sprintf('  - Check branch-alias config: %d repositories', $report->getMissingAliasCount()));
        }
        if ($report->getPatchReadyCount() > 0) {
            $this->output->ok(sprintf('  - Ready for patch release: %d repositories', $report->getPatchReadyCount()));
        }
        if ($report->getMinorReadyCount() > 0) {
            $this->output->ok(sprintf('  - Ready for minor release: %d repositories', $report->getMinorReadyCount()));
        }
        if ($report->getMajorReadyCount() > 0) {
            $this->output->ok(sprintf('  - Ready for major release: %d repositories', $report->getMajorReadyCount()));
        }
    }

    /**
     * Output a single table row.
     *
     * @param RepositorySyncResult $result The result
     *
     * @return void
     */
    private function outputTableRow(RepositorySyncResult $result): void
    {
        $name = 'horde/' . $result->name;
        $branch = $result->currentBranch ?? '—';
        $clean = $result->isClean ? '✓' : '✗';
        $rebased = $result->rebaseSuccessful ? '✓' : ($result->rebaseConflict ? '✗' : '—');
        $alias = '—';
        if ($result->branchAliasConfig !== null) {
            $alias = $result->branchAliasConfig->configured ? '✓' : '✗';
        }
        $tag = $result->lastTag ?? '—';
        $commits = $result->getCommitCountString();
        $suggest = $result->suggestRelease();

        $this->output->plain(sprintf(
            '%-25s %-16s %-7s %-9s %-7s %-14s %-25s %-15s',
            substr($name, 0, 25),
            substr($branch, 0, 16),
            $clean,
            $rebased,
            $alias,
            substr($tag, 0, 14),
            $commits,
            $suggest
        ));
    }
}
