<?php

/**
 * Components_Runner_Pullrequest:: runner for pull request operations.
 *
 * Copyright 2013-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

declare(strict_types=1);

namespace Horde\Components\Runner;

use Horde\Components\Output;
use Horde\Components\Helper\GitHubChecker;
use Horde\Components\Helper\Git as GitHelper;
use Horde\Components\Helper\PullRequestManager;

/**
 * Components_Runner_Pullrequest:: runner for pull request operations.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Pullrequest
{
    /**
     * Constructor.
     *
     * @param array $arguments CLI arguments for subcommand routing
     * @param string $workingDir The working directory
     * @param Output $output The output handler
     * @param GitHelper $gitHelper The git helper
     * @param GitHubChecker $githubChecker The GitHub checker helper
     * @param PullRequestManager $prManager The pull request manager
     */
    public function __construct(
        private readonly array $arguments,
        private readonly string $workingDir,
        private readonly Output $output,
        private readonly GitHelper $gitHelper,
        private readonly GitHubChecker $githubChecker,
        private readonly PullRequestManager $prManager
    ) {}

    /**
     * Run the pull request command.
     */
    public function run(): void
    {
        // Extract subcommand from arguments
        // Expected formats:
        //   pr list
        //   pr checkout 123
        //   pr approve
        //   pr merge 456

        $commandIndex = 0;
        if (isset($this->arguments[0]) && in_array($this->arguments[0], ['pr', 'pullrequest'])) {
            $commandIndex = 1;  // Subcommand is at index 1
        }

        if (!isset($this->arguments[$commandIndex])) {
            $this->output->warn('No subcommand specified');
            $this->showHelp();
            return;
        }

        $subcommand = $this->arguments[$commandIndex];
        $prNumber = $this->arguments[$commandIndex + 1] ?? null;

        // Dispatch to appropriate handler
        match ($subcommand) {
            'list' => $this->handleList(),
            'checkout' => $this->handleCheckout($prNumber),
            'approve' => $this->handleApprove($prNumber),
            'block' => $this->handleBlock($prNumber),
            'merge' => $this->handleMerge($prNumber),
            'close' => $this->handleClose($prNumber),
            'reopen' => $this->handleReopen($prNumber),
            default => $this->showHelp(),
        };
    }

    /**
     * Handle the 'list' subcommand.
     */
    private function handleList(): void
    {
        // Initialize the PR manager with the current directory
        if (!$this->prManager->initialize($this->workingDir)) {
            return;
        }

        // Get the repository
        $repo = $this->prManager->getRepository();
        if (!$repo) {
            $this->output->error('Could not determine repository');
            return;
        }

        $this->output->info("Listing pull requests for {$repo->owner}/{$repo->name}...");
        $this->output->plain('');

        // List pull requests
        $prs = $this->prManager->listPullRequests('open');
        if ($prs === null) {
            return;
        }

        if (count($prs) === 0) {
            $this->output->info('No open pull requests found.');
            return;
        }

        // Calculate dynamic column widths based on terminal size
        $widths = $this->calculateColumnWidths();

        // Render table header
        $this->output->plain(sprintf(
            '%-6s  %-' . $widths['author'] . 's  %-' . $widths['branch'] . 's  %-' . $widths['title'] . 's  %s',
            'ID',
            'Author',
            'Branch',
            'Title',
            'CI'
        ));
        $this->output->plain(str_repeat('─', $widths['total']));

        // Render each PR
        foreach ($prs as $pr) {
            $author = $this->truncate($pr->author->login, $widths['author']);
            $branch = $this->formatBranch($pr, $widths['branch']);
            $title = $this->truncate($pr->title, $widths['title']);
            $ciStatus = $this->prManager->getCheckStatus($pr);

            $this->output->plain(sprintf(
                '%-6s  %-' . $widths['author'] . 's  %-' . $widths['branch'] . 's  %-' . $widths['title'] . 's  %s',
                "#{$pr->number}",
                $author,
                $branch,
                $title,
                $ciStatus
            ));
        }

        $this->output->plain('');
        $this->output->info('CI Status: ✓ = passed, ✗ = failed, ⋯ = pending, - = no checks');
    }

    /**
     * Calculate column widths based on terminal size
     *
     * @return array Column widths: ['author' => int, 'branch' => int, 'title' => int, 'total' => int]
     */
    private function calculateColumnWidths(): array
    {
        $terminalWidth = $this->output->getTerminalWidth() ?? 120;

        // Reserve 2 columns for scrollbars and other UI elements
        $terminalWidth = max(80, $terminalWidth - 2);

        // Fixed widths
        $idWidth = 6;
        $ciWidth = 1;
        $spacing = 8; // 2 spaces between each column (4 gaps)

        // Minimum widths for variable columns
        $minAuthor = 10;
        $minBranch = 15;
        $minTitle = 20;

        // Default/preferred widths
        $prefAuthor = 15;
        $prefBranch = 25;

        // Calculate available space for variable columns
        $available = $terminalWidth - $idWidth - $ciWidth - $spacing;

        // Start with minimum widths
        $authorWidth = $minAuthor;
        $branchWidth = $minBranch;
        $titleWidth = $minTitle;

        // Try to allocate preferred widths if we have space
        if ($available >= $prefAuthor + $prefBranch + $minTitle) {
            $authorWidth = $prefAuthor;
            $branchWidth = $prefBranch;
            $titleWidth = $available - $prefAuthor - $prefBranch;
        } elseif ($available >= $minAuthor + $minBranch + $minTitle) {
            // Scale proportionally from minimum to preferred
            $remaining = $available - $minAuthor - $minBranch - $minTitle;
            $authorWidth = $minAuthor + (int) ($remaining * 0.2);
            $branchWidth = $minBranch + (int) ($remaining * 0.3);
            $titleWidth = $available - $authorWidth - $branchWidth;
        } else {
            // Terminal too narrow, use minimums
            $titleWidth = max($minTitle, $available - $minAuthor - $minBranch);
        }

        // Ensure title gets any extra space
        $titleWidth = max($titleWidth, $minTitle);

        // Calculate total width for separator line
        $totalWidth = $idWidth + 2 + $authorWidth + 2 + $branchWidth + 2 + $titleWidth + 2 + $ciWidth;

        return [
            'author' => $authorWidth,
            'branch' => $branchWidth,
            'title' => $titleWidth,
            'total' => $totalWidth,
        ];
    }

    /**
     * Truncate a string to a maximum length, adding ellipsis if needed
     *
     * @param string $text The text to truncate
     * @param int $maxLength Maximum length
     * @return string Truncated text
     */
    private function truncate(string $text, int $maxLength): string
    {
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        return mb_substr($text, 0, $maxLength - 1) . '…';
    }

    /**
     * Format branch information for display
     *
     * Shows "owner:branch" for external PRs, or just "branch" for same-repo PRs
     *
     * @param \Horde\GithubApiClient\GithubPullRequest $pr The pull request
     * @param int $maxWidth Maximum width for the branch display
     * @return string Formatted branch info, truncated to maxWidth
     */
    private function formatBranch(\Horde\GithubApiClient\GithubPullRequest $pr, int $maxWidth): string
    {
        $baseRepo = $pr->baseRepo;
        $headRepo = $pr->headRepo;

        // Check if this is a fork (different repository)
        if ($headRepo->owner !== $baseRepo->owner || $headRepo->name !== $baseRepo->name) {
            // External PR from a fork - show owner:branch
            $branch = "{$headRepo->owner}:{$pr->headBranch}";
        } else {
            // Same repo - just show branch name
            $branch = $pr->headBranch;
        }

        return $this->truncate($branch, $maxWidth);
    }

    /**
     * Handle the 'checkout' subcommand.
     *
     * @param ?string $prNumber The PR number to checkout.
     */
    private function handleCheckout(?string $prNumber): void
    {
        if (!$prNumber) {
            $this->output->error('PR number required for checkout command');
            $this->output->plain('');
            $this->output->help('Usage: horde-components pr checkout <id>');
            $this->output->help('       horde-components pr checkout 123');
            $this->output->help('       horde-components pr checkout "#123"  (quotes needed for # prefix)');
            return;
        }

        // Strip # prefix if present
        $prNumber = ltrim($prNumber, '#');
        if (!is_numeric($prNumber)) {
            $this->output->error("Invalid PR number: {$prNumber}");
            return;
        }

        $prNumber = (int) $prNumber;

        // Initialize the PR manager
        if (!$this->prManager->initialize($this->workingDir)) {
            return;
        }

        $repo = $this->prManager->getRepository();
        if (!$repo) {
            $this->output->error('Could not determine repository');
            return;
        }

        $this->output->info("Fetching PR #{$prNumber} from {$repo->owner}/{$repo->name}...");

        // Get PR details
        try {
            $apiClient = $this->prManager->getApiClient();
            $pr = $apiClient->getPullRequest($repo, $prNumber);
        } catch (\Exception $e) {
            $this->output->error("Failed to fetch PR #{$prNumber}: {$e->getMessage()}");
            return;
        }

        $this->output->plain('');
        $this->output->info("PR #{$pr->number}: {$pr->title}");
        $this->output->plain("  Author: {$pr->author->login}");
        $this->output->plain("  Branch: {$pr->headRepo->owner}/{$pr->headRepo->name}:{$pr->headBranch}");
        $this->output->plain('');

        // Check if working directory is clean
        if (!$this->isWorkingDirectoryClean($this->workingDir)) {
            $this->output->error('Working directory is not clean');
            $this->output->help('Commit, stash, or discard your changes before checking out a PR');
            return;
        }

        // Determine if this is same-repo or cross-repo PR
        $isSameRepo = ($pr->headRepo->owner === $pr->baseRepo->owner
                       && $pr->headRepo->name === $pr->baseRepo->name);

        if ($isSameRepo) {
            $this->checkoutSameRepoPR($this->workingDir, $pr);
        } else {
            $this->checkoutCrossRepoPR($this->workingDir, $pr);
        }
    }

    /**
     * Check if working directory is clean (no uncommitted changes)
     *
     * @param string $localDir The local directory
     * @return bool True if clean
     */
    private function isWorkingDirectoryClean(string $localDir): bool
    {
        return $this->gitHelper->checkoutIsClean($localDir);
    }

    /**
     * Checkout a PR from the same repository
     *
     * @param string $localDir The local directory
     * @param \Horde\GithubApiClient\GithubPullRequest $pr The pull request
     */
    private function checkoutSameRepoPR(string $localDir, \Horde\GithubApiClient\GithubPullRequest $pr): void
    {
        $branchName = $pr->headBranch;

        $this->output->info("Checking out branch '{$branchName}'...");

        try {
            // Fetch from origin to ensure we have latest
            $this->output->plain("  Fetching from origin...");
            $result = $this->gitHelper->fetch($localDir);

            if ($result->getReturnValue() !== 0) {
                throw new \Exception("Fetch failed: {$result->getOutputString()}");
            }

            // Check if local branch exists
            $localExists = $this->gitHelper->localBranchExists($localDir, $branchName);

            if ($localExists) {
                // Local branch exists, just checkout
                $this->output->plain("  Checking out existing local branch...");
                $result = $this->gitHelper->checkoutBranch($localDir, $branchName);

                if ($result->getReturnValue() !== 0) {
                    throw new \Exception("Checkout failed: {$result->getOutputString()}");
                }

                // Update from remote (pull)
                $this->output->plain("  Pulling latest changes...");
                $this->gitHelper->pull();
            } else {
                // Create new tracking branch
                $this->output->plain("  Creating tracking branch...");
                $result = $this->gitHelper->createRemoteTrackingBranch($localDir, $branchName, 'origin');

                if ($result->getReturnValue() !== 0) {
                    throw new \Exception("Failed to create tracking branch: {$result->getOutputString()}");
                }

                // Checkout the newly created branch
                $result = $this->gitHelper->checkoutBranch($localDir, $branchName);

                if ($result->getReturnValue() !== 0) {
                    throw new \Exception("Checkout failed: {$result->getOutputString()}");
                }
            }

            $this->output->ok("Successfully checked out PR #{$pr->number} on branch '{$branchName}'");
        } catch (\Exception $e) {
            $this->output->error("Failed to checkout branch: {$e->getMessage()}");
        }
    }

    /**
     * Checkout a PR from a different repository (fork)
     *
     * @param string $localDir The local directory
     * @param \Horde\GithubApiClient\GithubPullRequest $pr The pull request
     */
    private function checkoutCrossRepoPR(string $localDir, \Horde\GithubApiClient\GithubPullRequest $pr): void
    {
        $remoteName = $pr->headRepo->owner;
        $remoteBranch = $pr->headBranch;
        $localBranchName = "{$remoteName}_{$remoteBranch}";

        $this->output->info("Checking out cross-repo PR from {$remoteName}/{$pr->headRepo->name}...");

        try {
            // Check if remote exists
            $remoteUrl = $this->gitHelper->getRemoteUrl($localDir, $remoteName);
            $remoteExists = ($remoteUrl !== null);

            if (!$remoteExists) {
                // Add the remote
                $remoteUrl = "https://github.com/{$pr->headRepo->owner}/{$pr->headRepo->name}.git";
                $this->output->plain("  Adding remote '{$remoteName}': {$remoteUrl}");
                $this->addRemote($localDir, $remoteName, $remoteUrl);
            }

            // Fetch from the remote
            $this->output->plain("  Fetching from {$remoteName}...");
            $result = $this->gitHelper->fetch($localDir);

            if ($result->getReturnValue() !== 0) {
                throw new \Exception("Fetch failed: {$result->getOutputString()}");
            }

            // Check if local branch exists
            $localExists = $this->gitHelper->localBranchExists($localDir, $localBranchName);

            if ($localExists) {
                // Local branch exists, checkout and update
                $this->output->plain("  Checking out existing local branch '{$localBranchName}'...");
                $result = $this->gitHelper->checkoutBranch($localDir, $localBranchName);

                if ($result->getReturnValue() !== 0) {
                    throw new \Exception("Checkout failed: {$result->getOutputString()}");
                }

                $this->output->plain("  Pulling latest changes...");
                $this->gitHelper->pull();
            } else {
                // Create new tracking branch
                $this->output->plain("  Creating local branch '{$localBranchName}'...");
                $result = $this->gitHelper->createRemoteTrackingBranch($localDir, $localBranchName, $remoteName);

                if ($result->getReturnValue() !== 0) {
                    // Try alternative: checkout -b with explicit remote branch
                    $result = $this->gitHelper->branchFromLocal($localDir, $localBranchName, "{$remoteName}/{$remoteBranch}");

                    if ($result->getReturnValue() !== 0) {
                        throw new \Exception("Failed to create branch: {$result->getOutputString()}");
                    }
                }

                // Checkout the newly created branch
                $result = $this->gitHelper->checkoutBranch($localDir, $localBranchName);

                if ($result->getReturnValue() !== 0) {
                    throw new \Exception("Checkout failed: {$result->getOutputString()}");
                }
            }

            $this->output->ok("Successfully checked out PR #{$pr->number} on branch '{$localBranchName}'");
            $this->output->plain("  Tracking: {$remoteName}/{$remoteBranch}");
        } catch (\Exception $e) {
            $this->output->error("Failed to checkout cross-repo PR: {$e->getMessage()}");
        }
    }

    /**
     * Add a git remote
     *
     * @param string $localDir The local directory
     * @param string $name The remote name
     * @param string $url The remote URL
     */
    private function addRemote(string $localDir, string $name, string $url): void
    {
        $oldDir = getcwd();
        chdir($localDir);

        $gitBin = $this->gitHelper->detectGitBin();
        $cmd = sprintf('%s remote add %s %s', $gitBin, escapeshellarg($name), escapeshellarg($url));
        exec($cmd, $output, $returnCode);

        chdir($oldDir);

        if ($returnCode !== 0) {
            throw new \Exception("Failed to add remote: " . implode("\n", $output));
        }
    }

    /**
     * Deduce the PR number from the current branch and tracking remote
     *
     * This method attempts to find an open PR that matches the current branch.
     * It checks for PRs where the head branch matches the current branch name,
     * handling both same-repo branches and cross-repo (fork) branches.
     *
     * @return string|null The PR number as a string, or null if not found
     */
    private function deducePRFromCurrentBranch(): ?string
    {
        try {
            // Get current branch name
            $currentBranch = $this->gitHelper->getCurrentBranch($this->workingDir);
            if (!$currentBranch) {
                $this->output->error('Not on a branch');
                return null;
            }

            $this->output->info("Current branch: {$currentBranch}");

            // Get repository info
            $repo = $this->prManager->getRepository();
            $apiClient = $this->prManager->getApiClient();

            // List all open PRs
            $prs = $apiClient->listPullRequests($repo, '', '', 'open');

            // Check if current branch matches any PR
            // For same-repo PRs: branch name matches directly
            // For cross-repo PRs: branch name is {owner}_{branch}, so we need to check the pattern
            foreach ($prs as $pr) {
                $isSameRepo = ($pr->headRepo->owner === $pr->baseRepo->owner
                               && $pr->headRepo->name === $pr->baseRepo->name);

                if ($isSameRepo && $pr->headBranch === $currentBranch) {
                    // Same-repo PR: branch name matches directly
                    $this->output->ok("Found PR #{$pr->number}: {$pr->title}");
                    return (string) $pr->number;
                } elseif (!$isSameRepo) {
                    // Cross-repo PR: check if current branch is {owner}_{branch}
                    $expectedBranchName = "{$pr->headRepo->owner}_{$pr->headBranch}";
                    if ($expectedBranchName === $currentBranch) {
                        $this->output->ok("Found PR #{$pr->number}: {$pr->title}");
                        return (string) $pr->number;
                    }
                }
            }

            $this->output->warn("No open PR found for branch '{$currentBranch}'");
            $this->output->help('Specify PR number explicitly: horde-components pr merge <id>');
            return null;
        } catch (\Exception $e) {
            $this->output->error("Failed to deduce PR from branch: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Handle the 'approve' subcommand.
     *
     * @param ?string $prNumber The PR number to approve (optional).
     */
    private function handleApprove(?string $prNumber): void
    {
        // Initialize the PR manager
        if (!$this->prManager->initialize($this->workingDir)) {
            return;
        }

        // If no PR number provided, deduce from current branch
        if (!$prNumber) {
            $prNumber = $this->deducePRFromCurrentBranch();
            if (!$prNumber) {
                return;
            }
        } else {
            // Strip # prefix if present
            $prNumber = ltrim($prNumber, '#');
            if (!is_numeric($prNumber)) {
                $this->output->error("Invalid PR number: {$prNumber}");
                return;
            }
        }

        $prNumber = (int) $prNumber;

        // Get PR details first
        try {
            $apiClient = $this->prManager->getApiClient();
            $repo = $this->prManager->getRepository();
            $pr = $apiClient->getPullRequest($repo, $prNumber);

            $this->output->info("Approving PR #{$pr->number}: {$pr->title}");
            $this->output->plain("  Author: {$pr->author->login}");
            $this->output->plain('');

            // Optional: prompt for review comment
            // For now, approve without comment
            $this->prManager->approvePullRequest($prNumber);
        } catch (\Exception $e) {
            $this->output->error("Failed to approve PR #{$prNumber}: {$e->getMessage()}");
        }
    }

    /**
     * Handle the 'block' subcommand.
     *
     * @param ?string $prNumber The PR number to block (optional).
     */
    private function handleBlock(?string $prNumber): void
    {
        // Initialize the PR manager
        if (!$this->prManager->initialize($this->workingDir)) {
            return;
        }

        // If no PR number provided, deduce from current branch
        if (!$prNumber) {
            $prNumber = $this->deducePRFromCurrentBranch();
            if (!$prNumber) {
                return;
            }
        } else {
            // Strip # prefix if present
            $prNumber = ltrim($prNumber, '#');
            if (!is_numeric($prNumber)) {
                $this->output->error("Invalid PR number: {$prNumber}");
                return;
            }
        }

        $prNumber = (int) $prNumber;

        // Get PR details first
        try {
            $apiClient = $this->prManager->getApiClient();
            $repo = $this->prManager->getRepository();
            $pr = $apiClient->getPullRequest($repo, $prNumber);

            $this->output->info("Requesting changes on PR #{$pr->number}: {$pr->title}");
            $this->output->plain("  Author: {$pr->author->login}");
            $this->output->plain('');

            // Prompt for review comment (required)
            $this->output->warn('Review comment is required when requesting changes.');
            $this->output->plain('Enter your review comment (press Ctrl+D when done):');
            $this->output->plain('');

            // Read multi-line input from stdin
            $comment = '';
            while (($line = fgets(STDIN)) !== false) {
                $comment .= $line;
            }

            $comment = trim($comment);

            if ($comment === '') {
                $this->output->error('Review comment cannot be empty');
                return;
            }

            $this->prManager->requestChangesPullRequest($prNumber, $comment);
        } catch (\Exception $e) {
            $this->output->error("Failed to request changes on PR #{$prNumber}: {$e->getMessage()}");
        }
    }

    /**
     * Handle the 'merge' subcommand.
     *
     * @param ?string $prNumber The PR number to merge (optional).
     */
    private function handleMerge(?string $prNumber): void
    {
        // Initialize the PR manager
        if (!$this->prManager->initialize($this->workingDir)) {
            return;
        }

        // If no PR number provided, deduce from current branch
        if (!$prNumber) {
            $prNumber = $this->deducePRFromCurrentBranch();
            if (!$prNumber) {
                return;
            }
        } else {
            // Strip # prefix if present
            $prNumber = ltrim($prNumber, '#');
            if (!is_numeric($prNumber)) {
                $this->output->error("Invalid PR number: {$prNumber}");
                return;
            }
        }

        $prNumber = (int) $prNumber;

        // Get PR details first
        try {
            $apiClient = $this->prManager->getApiClient();
            $repo = $this->prManager->getRepository();
            $pr = $apiClient->getPullRequest($repo, $prNumber);

            $this->output->info("Merging PR #{$pr->number}: {$pr->title}");
            $this->output->plain("  Author: {$pr->author->login}");
            $this->output->plain("  Base: {$pr->baseBranch}");
            $this->output->plain('');

            // Attempt to merge
            $this->prManager->mergePullRequest($prNumber);
        } catch (\Exception $e) {
            $this->output->error("Failed to merge PR #{$prNumber}: {$e->getMessage()}");
        }
    }

    /**
     * Handle the 'close' subcommand.
     *
     * @param ?string $prNumber The PR number to close (optional).
     */
    private function handleClose(?string $prNumber): void
    {
        // Initialize the PR manager
        if (!$this->prManager->initialize($this->workingDir)) {
            return;
        }

        // If no PR number provided, deduce from current branch
        if (!$prNumber) {
            $prNumber = $this->deducePRFromCurrentBranch();
            if (!$prNumber) {
                return;
            }
        } else {
            // Strip # prefix if present
            $prNumber = ltrim($prNumber, '#');
            if (!is_numeric($prNumber)) {
                $this->output->error("Invalid PR number: {$prNumber}");
                return;
            }
        }

        $prNumber = (int) $prNumber;

        // Get PR details first
        try {
            $apiClient = $this->prManager->getApiClient();
            $repo = $this->prManager->getRepository();
            $pr = $apiClient->getPullRequest($repo, $prNumber);

            $this->output->info("Closing PR #{$pr->number}: {$pr->title}");
            $this->output->plain("  Author: {$pr->author->login}");
            $this->output->plain('');

            // Attempt to close
            $this->prManager->closePullRequest($prNumber);
        } catch (\Exception $e) {
            $this->output->error("Failed to close PR #{$prNumber}: {$e->getMessage()}");
        }
    }

    /**
     * Handle the 'reopen' subcommand.
     *
     * @param ?string $prNumber The PR number to reopen.
     */
    private function handleReopen(?string $prNumber): void
    {
        if (!$prNumber) {
            $this->output->error('PR number required for reopen command');
            $this->output->plain('');
            $this->output->help('Usage: horde-components pr reopen <id>');
            $this->output->help('       horde-components pr reopen 123');
            $this->output->help('       horde-components pr reopen "#123"  (quotes needed for # prefix)');
            return;
        }

        // Strip # prefix if present
        $prNumber = ltrim($prNumber, '#');
        if (!is_numeric($prNumber)) {
            $this->output->error("Invalid PR number: {$prNumber}");
            return;
        }

        $prNumber = (int) $prNumber;

        // Initialize the PR manager
        if (!$this->prManager->initialize($this->workingDir)) {
            return;
        }

        // Get PR details first
        try {
            $apiClient = $this->prManager->getApiClient();
            $repo = $this->prManager->getRepository();
            $pr = $apiClient->getPullRequest($repo, $prNumber);

            $this->output->info("Reopening PR #{$pr->number}: {$pr->title}");
            $this->output->plain("  Author: {$pr->author->login}");
            $this->output->plain('');

            // Attempt to reopen
            $this->prManager->reopenPullRequest($prNumber);
        } catch (\Exception $e) {
            $this->output->error("Failed to reopen PR #{$prNumber}: {$e->getMessage()}");
        }
    }

    /**
     * Show help for the pull request command.
     */
    private function showHelp(): void
    {
        $this->output->info('Pull Request Management');
        $this->output->plain('');
        $this->output->plain('Usage: horde-components pr <subcommand> [options]');
        $this->output->plain('');
        $this->output->plain('Subcommands:');
        $this->output->plain('  list              List pull requests');
        $this->output->plain('  checkout <id>     Check out a PR branch (e.g., checkout 123 or checkout "#123")');
        $this->output->plain('  approve [id]      Approve a PR');
        $this->output->plain('  block [id]        Request changes on a PR');
        $this->output->plain('  merge [id]        Merge a PR');
        $this->output->plain('  close [id]        Close a PR without merging');
        $this->output->plain('  reopen <id>       Reopen a closed PR');
        $this->output->plain('');
        $this->output->plain('Note: When using # prefix, quote the argument: pr checkout "#123"');
    }
}
