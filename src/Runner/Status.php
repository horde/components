<?php

/**
 * Horde\Components\Runner\Status:: runner for status output.
 *
 * PHP Version 7
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

namespace Horde\Components\Runner;

use Horde\Components\Config;
use Horde\Components\Exception;
use Horde\Components\Helper\Git as GitHelper;
use Horde\Components\Output;
use Horde\Components\Composer\InstallationDirectory;
use Horde\Components\RuntimeContext\GitCheckoutDirectory;
use Horde\GithubApiClient\GithubApiClient;
use Horde\GithubApiClient\GithubApiConfig;
use Horde\Http\Client\Curl as CurlClient;
use Horde\Http\StreamFactory;
use Horde\Http\RequestFactory;
use Horde\Http\ResponseFactory;
use Horde\Http\Client\Options;
use Psr\Http\Client\ClientInterface;

/**
 * Horde\Components\Runner\Status:: runner for status output.
 *
 * Copyright 2020-2024 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Status
{
    /**
     * The repo base url.
     */
    private readonly string $gitRepoBase;

    /**
     * Constructor.
     *
     * @param Config    $config  The configuration for the current job.
     * @param Output    $output  The output handler.
     * @param GitHelper $git     The output handler.
     */
    public function __construct(
        private readonly Config $config,
        private readonly Output $output,
        private readonly GitCheckoutDirectory $localCheckoutDir,
        private readonly InstallationDirectory $installDir,
    ) {
        //        $this->gitHelper = $git;
        $options = $this->config->getOptions();
        $this->gitRepoBase = $options['git_repo_base']
        ?? 'https://github.com/horde/';
    }

    public function run()
    {
        $arguments = $this->config->getArguments();
        $this->output->plain("horde-components status -- minding any CLI switches, current working directory and config file content");
        $configFilePath = $this->config->getOptions()['config'];
        $this->output->info("Config file path: $configFilePath");
        if (is_readable($configFilePath)) {
            $this->output->ok("Config file exists and is readable.");
        } else {
            $this->output->warn("Config file does not exist or is not readable.");
        };
        $this->output->info("Git Tree root path: $this->localCheckoutDir");
        if (is_readable($this->localCheckoutDir)) {
            $componentsCount = count($this->localCheckoutDir->getHordeYmlDirs());
            $gitCount = count($this->localCheckoutDir->getGitDirs());
            if ($gitCount) {
                $this->output->ok("Git Tree dir exists and has $gitCount repos checked out ($componentsCount components)");
                // TODO Verbose:
                /*foreach ($this->localCheckoutDir->getGitDirs() as $dir) {
                    $this->output->plain($dir);
                }*/
            } else {
                $this->output->warn("Git Tree dir exists but no components are checked out\nRun:    horde-components github-clone-org");
            }
        } else {
            $this->output->warn("Git Tree dir does not exist or is not readable.");
        };
        $installDir = $this->installDir;
        $this->output->info("Install Base path: $installDir");

        if ($installDir->exists()) {
            $this->output->ok("Install dir exists.");
            if ($installDir->hasComposerJson()) {
                $this->output->ok("Root composer.json file exists.");
            }
        } else {
            $this->output->warn("Install dir does not exist or is not readable.");
            $this->output->help("Run: \ncomposer create-project horde/bundle $installDir");
        };

        // Check GitHub API token
        $githubToken = getenv('GITHUB_TOKEN');
        $this->output->info("GitHub API Token:");
        if ($githubToken && strlen($githubToken) > 0) {
            $maskedToken = substr($githubToken, 0, 8) . str_repeat('*', max(0, strlen($githubToken) - 8));
            $this->output->ok("GitHub API token is configured ($maskedToken)");

            // Check token validity, scopes, and rate limit
            $this->checkGitHubApiStatus($githubToken);
        } else {
            $this->output->warn("GitHub API token is not configured.");
            $this->output->help("Set GITHUB_TOKEN environment variable for GitHub operations
Export in your shell: export GITHUB_TOKEN=ghp_your_token_here");
        }
    }

    /**
     * Check GitHub API status including token validity, scopes, and rate limit
     *
     * @param string $token The GitHub API token
     * @return void
     */
    private function checkGitHubApiStatus(string $token): void
    {
        try {
            // Create HTTP client
            $httpClient = new CurlClient(
                new ResponseFactory(),
                new StreamFactory(),
                new Options()
            );

            // Create GitHub API client
            $apiConfig = new GithubApiConfig(accessToken: $token);
            $apiClient = new GithubApiClient($httpClient, new RequestFactory(), $apiConfig);

            // First try to get token scopes to verify token is valid
            try {
                $this->output->info("GitHub API Token Scopes:");
                $scopes = $apiClient->getTokenScopes();

                if ($scopes->isEmpty()) {
                    $this->output->warn("Token has no scopes (may be expired or invalid)");
                    return;
                }

                $scopesList = $scopes->toArray();
                $this->output->ok("Token has " . count($scopesList) . " scopes: " . implode(', ', $scopesList));

                // Check for useful scopes
                if ($scopes->canReadRepositories()) {
                    $this->output->ok("Token can read repositories");
                }
                if ($scopes->canWriteRepositories()) {
                    $this->output->ok("Token can write to repositories");
                }
                if ($scopes->canReadOrganizations()) {
                    $this->output->ok("Token can read organizations");
                }
            } catch (\Throwable $e) {
                $this->output->warn("Failed to verify token scopes: " . $e->getMessage());
                if (str_contains($e->getMessage(), '401')) {
                    $this->output->warn("Token appears to be invalid, expired, or revoked");
                    $this->output->help("Generate a new token at: https://github.com/settings/tokens");
                    return;
                }
                // Continue to try rate limit check anyway
            }

            // Get rate limit
            $this->output->info("GitHub API Rate Limit:");
            $rateLimit = $apiClient->getRateLimit();

            $usagePercent = $rateLimit->getUsagePercentage();
            $message = sprintf(
                "Used %d of %d requests (%.1f%% used, %d remaining)",
                $rateLimit->used,
                $rateLimit->limit,
                $usagePercent,
                $rateLimit->remaining
            );

            // Determine status based on usage
            if ($rateLimit->isExhausted()) {
                $resetTime = $rateLimit->getResetDateTime()->format('Y-m-d H:i:s T');
                $this->output->warn($message);
                $this->output->warn("Rate limit exhausted! Resets at: $resetTime");
            } elseif ($usagePercent > 80) {
                $this->output->warn($message);
            } else {
                $this->output->ok($message);
            }

            $resetTime = $rateLimit->getResetDateTime()->format('Y-m-d H:i:s T');
            $secondsUntilReset = $rateLimit->getSecondsUntilReset();
            if ($secondsUntilReset > 0) {
                $minutesUntilReset = ceil($secondsUntilReset / 60);
                $this->output->info("Rate limit resets in $minutesUntilReset minutes ($resetTime)");
            } else {
                $this->output->info("Rate limit reset time: $resetTime");
            }
        } catch (\Throwable $e) {
            $this->output->warn("Failed to check GitHub API status: " . $e->getMessage());
            if (str_contains($e->getMessage(), '401')) {
                $this->output->warn("Token appears to be invalid, expired, or revoked");
                $this->output->help("Generate a new token at: https://github.com/settings/tokens
Token should have at least 'repo' or 'public_repo' scope");
            }
        }
    }
}
