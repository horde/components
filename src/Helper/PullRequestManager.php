<?php

/**
 * Helper for managing GitHub pull requests
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

namespace Horde\Components\Helper;

use Horde\Components\Output;
use Horde\GithubApiClient\GithubApiClient;
use Horde\GithubApiClient\GithubApiConfig;
use Horde\GithubApiClient\GithubRepository;
use Horde\GithubApiClient\GithubPullRequestList;
use Horde\GithubApiClient\MergePullRequestParams;
use Horde\Http\Client\Curl as CurlClient;
use Horde\Http\Client\Options;
use Horde\Http\StreamFactory;
use Horde\Http\RequestFactory;
use Horde\Http\ResponseFactory;

/**
 * Helper for managing GitHub pull requests
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class PullRequestManager
{
    private ?GithubApiClient $apiClient = null;
    private ?GithubRepository $repository = null;

    public function __construct(
        private readonly GitHubChecker $githubChecker,
        private readonly Output $output,
        private readonly GithubApiConfig $githubApiConfig
    ) {}

    /**
     * Initialize the GitHub API client for a local directory
     *
     * @param string $localDir The local directory path
     * @return bool True if initialized successfully
     */
    public function initialize(string $localDir): bool
    {
        // Check if this is a GitHub repository
        if (!$this->githubChecker->isOnGitHub($localDir)) {
            $this->output->warn('Not a GitHub repository');
            return false;
        }

        // Get GitHub token from DI-provided config
        $githubToken = $this->githubApiConfig->accessToken;
        if ($githubToken === '') {
            $this->output->warn('GitHub token not configured');
            $this->output->help("Configure token via:");
            $this->output->help("  1. CLI: --github-token=ghp_xxx");
            $this->output->help("  2. Environment: export GITHUB_TOKEN=ghp_xxx");
            $this->output->help("  3. Config file: \$conf['github.token'] = 'ghp_xxx'");
            return false;
        }

        // Get repository identifier
        $repoFullName = $this->githubChecker->getGitHubRepository($localDir);
        if (!$repoFullName) {
            $this->output->warn('Could not determine GitHub repository');
            return false;
        }

        try {
            // Initialize GitHub API client
            $httpClient = new CurlClient(new ResponseFactory(), new StreamFactory(), new Options());
            $requestFactory = new RequestFactory();
            $streamFactory = new StreamFactory();
            $config = new GithubApiConfig(accessToken: $githubToken);
            $this->apiClient = new GithubApiClient($httpClient, $requestFactory, $config, $streamFactory);
            $this->repository = GithubRepository::fromFullName($repoFullName);

            return true;
        } catch (\Exception $e) {
            $this->output->error("Failed to initialize GitHub API client: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Get the API client instance
     *
     * @return GithubApiClient|null
     */
    public function getApiClient(): ?GithubApiClient
    {
        return $this->apiClient;
    }

    /**
     * Get the repository instance
     *
     * @return GithubRepository|null
     */
    public function getRepository(): ?GithubRepository
    {
        return $this->repository;
    }

    /**
     * List pull requests
     *
     * @param string $state State filter: 'open', 'closed', or 'all'
     * @param string $baseBranch Base branch filter
     * @param string $headRef Head ref filter
     * @return GithubPullRequestList|null
     */
    public function listPullRequests(string $state = 'open', string $baseBranch = '', string $headRef = ''): ?GithubPullRequestList
    {
        if (!$this->apiClient || !$this->repository) {
            $this->output->error('API client not initialized. Call initialize() first.');
            return null;
        }

        try {
            return $this->apiClient->listPullRequests($this->repository, $baseBranch, $headRef, $state);
        } catch (\Exception $e) {
            $this->output->error("Failed to list pull requests: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Get check run status for a pull request
     *
     * @param \Horde\GithubApiClient\GithubPullRequest $pr The pull request
     * @return string Status indicator: '✓', '✗', '⋯', or '-'
     */
    public function getCheckStatus(\Horde\GithubApiClient\GithubPullRequest $pr): string
    {
        if (!$this->apiClient || !$this->repository) {
            return '-';
        }

        try {
            // Get the head SHA from the PR
            $headRef = $pr->headBranch;

            // Try to get check runs
            $checkRuns = $this->apiClient->listCheckRuns($this->repository, $headRef);

            if (count($checkRuns) === 0) {
                // No checks configured
                return '-';
            }

            $hasFailure = false;
            $hasPending = false;

            foreach ($checkRuns as $checkRun) {
                if ($checkRun->status === 'completed') {
                    if ($checkRun->conclusion === 'failure' || $checkRun->conclusion === 'cancelled') {
                        $hasFailure = true;
                    }
                } else {
                    $hasPending = true;
                }
            }

            if ($hasFailure) {
                return '✗';
            }
            if ($hasPending) {
                return '⋯';
            }

            return '✓';
        } catch (\Exception $e) {
            // If we can't get check status, just return unknown
            return '-';
        }
    }

    /**
     * Merge a pull request
     *
     * @param int $number PR number
     * @param MergePullRequestParams|null $params Merge parameters
     * @return bool True if merged successfully
     */
    public function mergePullRequest(int $number, ?MergePullRequestParams $params = null): bool
    {
        if (!$this->apiClient || !$this->repository) {
            $this->output->error('API client not initialized. Call initialize() first.');
            return false;
        }

        try {
            $params = $params ?? new MergePullRequestParams();
            $result = $this->apiClient->mergePullRequest($this->repository, $number, $params);

            if ($result->merged) {
                $this->output->ok("Successfully merged PR #{$number}");
                $this->output->info("Merge commit: {$result->sha}");
                return true;
            } else {
                $this->output->warn("PR #{$number} was not merged: {$result->message}");
                return false;
            }
        } catch (\Exception $e) {
            $this->output->error("Failed to merge PR #{$number}: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Close a pull request without merging
     *
     * @param int $number PR number
     * @return bool True if closed successfully
     */
    public function closePullRequest(int $number): bool
    {
        if (!$this->apiClient || !$this->repository) {
            $this->output->error('API client not initialized. Call initialize() first.');
            return false;
        }

        try {
            $pr = $this->apiClient->closePullRequest($this->repository, $number);
            $this->output->ok("Successfully closed PR #{$pr->number}: {$pr->title}");
            return true;
        } catch (\Exception $e) {
            $this->output->error("Failed to close PR #{$number}: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Reopen a closed pull request
     *
     * @param int $number PR number
     * @return bool True if reopened successfully
     */
    public function reopenPullRequest(int $number): bool
    {
        if (!$this->apiClient || !$this->repository) {
            $this->output->error('API client not initialized. Call initialize() first.');
            return false;
        }

        try {
            $pr = $this->apiClient->reopenPullRequest($this->repository, $number);
            $this->output->ok("Successfully reopened PR #{$pr->number}: {$pr->title}");
            return true;
        } catch (\Exception $e) {
            $this->output->error("Failed to reopen PR #{$number}: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Approve a pull request
     *
     * @param int $number PR number
     * @param string $body Optional review comment
     * @return bool True if approved successfully
     */
    public function approvePullRequest(int $number, string $body = ''): bool
    {
        if (!$this->apiClient || !$this->repository) {
            $this->output->error('API client not initialized. Call initialize() first.');
            return false;
        }

        try {
            $params = new \Horde\GithubApiClient\CreateReviewParams(
                event: 'APPROVE',
                body: $body
            );
            $review = $this->apiClient->createReview($this->repository, $number, $params);
            $this->output->ok("Successfully approved PR #{$number}");
            if ($body !== '') {
                $this->output->info("Review comment: {$body}");
            }
            return true;
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();

            // Special handling for self-approval attempt
            if (str_contains($errorMessage, 'Can not approve your own pull request')) {
                $this->output->warn("Cannot approve your own pull request #{$number}");
                $this->output->info("Tip: Ask another maintainer to review, or use 'pr merge' if you have permissions.");
            } else {
                $this->output->error("Failed to approve PR #{$number}: {$errorMessage}");
            }
            return false;
        }
    }

    /**
     * Request changes on a pull request
     *
     * @param int $number PR number
     * @param string $body Review comment (required)
     * @return bool True if submitted successfully
     */
    public function requestChangesPullRequest(int $number, string $body): bool
    {
        if (!$this->apiClient || !$this->repository) {
            $this->output->error('API client not initialized. Call initialize() first.');
            return false;
        }

        if ($body === '') {
            $this->output->error('Review comment is required when requesting changes');
            return false;
        }

        try {
            $params = new \Horde\GithubApiClient\CreateReviewParams(
                event: 'REQUEST_CHANGES',
                body: $body
            );
            $review = $this->apiClient->createReview($this->repository, $number, $params);
            $this->output->ok("Successfully requested changes on PR #{$number}");
            $this->output->info("Review comment: {$body}");
            return true;
        } catch (\Exception $e) {
            $this->output->error("Failed to request changes on PR #{$number}: {$e->getMessage()}");
            return false;
        }
    }
}
