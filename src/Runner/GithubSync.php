<?php

/**
 * Horde\Components\Runner\GithubSync:: runner for GitHub synchronization operations.
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

use Horde\Components\Helper\Git as GitHelper;
use Horde\Components\Output;
use Horde\Components\Report\DifferenceReport;
use Horde\Components\RuntimeContext\GitCheckoutDirectory;
use Horde\GithubApiClient\GithubApiClient;
use Horde\GithubApiClient\GithubOrganizationId;
use RuntimeException;

/**
 * Horde\Components\Runner\GithubSync:: runner for GitHub synchronization operations.
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
class GithubSync
{
    /**
     * Constructor.
     *
     * @param GithubApiClient $githubClient GitHub API client
     * @param GitCheckoutDirectory $checkoutDir Local checkout directory
     * @param GitHelper $gitHelper Git operations helper
     * @param Output $output Output handler
     * @param string $organizationName GitHub organization name
     * @param string $localCheckoutDir Local checkout directory path
     * @param string $gitRepoBase Git repository base URL
     */
    public function __construct(
        private readonly GithubApiClient $githubClient,
        private readonly GitCheckoutDirectory $checkoutDir,
        private readonly GitHelper $gitHelper,
        private readonly Output $output,
        private readonly string $organizationName = 'horde',
        private readonly string $localCheckoutDir = '',
        private readonly string $gitRepoBase = 'https://github.com/horde/'
    ) {}

    /**
     * Detect differences between GitHub organization and local checkout.
     *
     * @return DifferenceReport The difference report
     */
    public function detectDifferences(): DifferenceReport
    {
        $this->output->info('Fetching repository list from GitHub...');
        $remoteRepos = $this->fetchRemoteRepositories();

        $this->output->info('Scanning local checkout directory...');
        $localRepos = $this->scanLocalRepositories();

        // Compare lists
        $remoteOnly = array_diff($remoteRepos, $localRepos);
        $localOnly = array_diff($localRepos, $remoteRepos);
        $inSync = array_intersect($remoteRepos, $localRepos);

        // Sort for consistent output
        sort($remoteOnly);
        sort($localOnly);
        sort($inSync);

        return new DifferenceReport($remoteOnly, $localOnly, $inSync);
    }

    /**
     * Sync missing repositories by cloning them from GitHub.
     *
     * @param array $reposToClone List of repository names to clone
     * @param string $branch Branch to checkout (default: FRAMEWORK_6_0)
     *
     * @return void
     */
    public function syncMissingRepositories(array $reposToClone, string $branch = 'FRAMEWORK_6_0'): void
    {
        if (empty($reposToClone)) {
            $this->output->info('No repositories to clone.');
            return;
        }

        $this->output->info(sprintf('Cloning %d repositories...', count($reposToClone)));

        $success = 0;
        $failed = 0;

        foreach ($reposToClone as $repoName) {
            try {
                $this->cloneRepository($repoName, $branch);
                $success++;
                $this->output->ok(sprintf('  ✓ Cloned: %s', $repoName));
            } catch (RuntimeException $e) {
                $failed++;
                $this->output->error(sprintf('  ✗ Failed to clone %s: %s', $repoName, $e->getMessage()));
            }
        }

        $this->output->plain('');
        $this->output->ok(sprintf('Successfully cloned: %d repositories', $success));
        if ($failed > 0) {
            $this->output->error(sprintf('Failed to clone: %d repositories', $failed));
        }
    }

    /**
     * Present the difference report to the user.
     *
     * @param DifferenceReport $report The report to present
     *
     * @return void
     */
    public function presentReport(DifferenceReport $report): void
    {
        $this->output->plain('');
        $this->output->bold('=== GitHub Repository Analysis ===');
        $this->output->plain('');
        $this->output->plain(sprintf('Remote Repositories (GitHub): %d', $report->countRemote()));
        $this->output->plain(sprintf('Local Repositories (%s): %d', $this->localCheckoutDir, $report->countLocal()));
        $this->output->plain('');

        // Remote only
        if ($report->hasRemoteOnly()) {
            $this->output->info(sprintf('[  INFO  ] Remote only (not cloned locally): %d', count($report->remoteOnly)));
            foreach (array_slice($report->remoteOnly, 0, 10) as $repo) {
                $this->output->plain(sprintf('  - %s', $repo));
            }
            if (count($report->remoteOnly) > 10) {
                $this->output->plain(sprintf('  ... and %d more', count($report->remoteOnly) - 10));
            }
            $this->output->plain('');
        }

        // Local only
        if ($report->hasLocalOnly()) {
            $this->output->warn(sprintf('[  WARN  ] Local only (orphaned, not in GitHub): %d', count($report->localOnly)));
            foreach (array_slice($report->localOnly, 0, 10) as $repo) {
                $this->output->plain(sprintf('  - %s', $repo));
            }
            if (count($report->localOnly) > 10) {
                $this->output->plain(sprintf('  ... and %d more', count($report->localOnly) - 10));
            }
            $this->output->plain('');
        }

        // In sync
        $this->output->ok(sprintf('[   OK   ] In sync: %d repositories', $report->countInSync()));
        $this->output->plain('');

        // Recommendations
        if ($report->hasRemoteOnly() || $report->hasLocalOnly()) {
            $this->output->plain('Recommendation:');
            if ($report->hasRemoteOnly()) {
                $this->output->plain('  - Run with --sync to clone missing repositories');
            }
            if ($report->hasLocalOnly()) {
                $this->output->plain('  - Review orphaned directories for cleanup');
            }
        }
    }

    /**
     * Fetch repository list from GitHub API.
     *
     * @return array Array of repository names (without vendor prefix)
     */
    private function fetchRemoteRepositories(): array
    {
        $repos = $this->githubClient->listRepositoriesInOrganization(
            new GithubOrganizationId($this->organizationName)
        );

        $names = [];
        foreach ($repos as $repo) {
            // Extract repository name without vendor prefix
            // e.g., "horde/ActiveSync" -> "ActiveSync"
            $fullName = $repo->getFullName();
            $parts = explode('/', $fullName, 2);
            $names[] = $parts[1] ?? $fullName;
        }

        sort($names);
        return $names;
    }

    /**
     * Scan local checkout directory for git repositories.
     *
     * @return array Array of repository names (directory names)
     */
    private function scanLocalRepositories(): array
    {
        if (!$this->checkoutDir->exists()) {
            return [];
        }

        $names = [];
        foreach ($this->checkoutDir->getGitDirs() as $componentDir) {
            // Extract just the component name from the full path
            // e.g., "/home/user/git/horde/ActiveSync" -> "ActiveSync"
            $names[] = basename((string) $componentDir);
        }

        sort($names);
        return $names;
    }

    /**
     * Clone a single repository from GitHub.
     *
     * @param string $repoName Repository name
     * @param string $branch Branch to checkout
     *
     * @return void
     * @throws RuntimeException If clone fails
     */
    private function cloneRepository(string $repoName, string $branch): void
    {
        $cloneUrl = $this->gitRepoBase . $this->organizationName . '/' . $repoName;
        $localPath = $this->localCheckoutDir . '/' . $this->organizationName . '/' . $repoName;

        // Ensure parent directory exists
        $parentDir = dirname($localPath);
        if (!is_dir($parentDir)) {
            if (!mkdir($parentDir, 0755, true)) {
                throw new RuntimeException(sprintf('Failed to create directory: %s', $parentDir));
            }
        }

        // Clone repository
        $this->gitHelper->workflowClone(
            $this->output,
            $cloneUrl,
            $localPath,
            branch: $branch
        );
    }
}
