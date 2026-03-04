<?php

/**
 * Horde\Components\Runner\Status:: runner for status output.
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

use Horde\Components\ConfigProvider\EffectiveConfigProvider;
use Horde\Components\Exception;
use Horde\Components\Helper\Git as GitHelper;
use Horde\Components\Output;
use Horde\Components\Composer\InstallationDirectory;
use Horde\Components\RuntimeContext\GitCheckoutDirectory;
use Horde\Components\Auth\AuthenticationFactory;
use Horde\Components\Auth\GitHubAppAuthenticationStrategy;
use Horde\Components\Auth\GitHubAppAuthenticationService;
use Horde\GithubApiClient\GithubApiClient;
use Horde\GithubApiClient\GithubApiConfig;
use Throwable;

/**
 * Horde\Components\Runner\Status:: runner for status output.
 *
 * Copyright 2020-2026 Horde LLC (http://www.horde.org/)
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
     * @param array $arguments CLI arguments
     * @param EffectiveConfigProvider $config Configuration provider
     * @param string $configFilePath Path to config file
     * @param Output $output The output handler
     * @param GitCheckoutDirectory $localCheckoutDir Local checkout directory
     * @param InstallationDirectory $installDir Installation directory
     * @param AuthenticationFactory $authFactory Authentication factory
     */
    public function __construct(
        private readonly array $arguments,
        private readonly EffectiveConfigProvider $config,
        private readonly string $configFilePath,
        private readonly Output $output,
        private readonly GitCheckoutDirectory $localCheckoutDir,
        private readonly InstallationDirectory $installDir,
        private readonly AuthenticationFactory $authFactory,
    ) {
        // Try new name first, then fall back to old name for backwards compatibility
        $this->gitRepoBase = $this->config->hasSetting('scm.repo.base')
            ? $this->config->getSetting('scm.repo.base')
            : ($this->config->hasSetting('git_repo_base')
                ? $this->config->getSetting('git_repo_base')
                : 'https://github.com/horde/');
    }

    public function run(): void
    {
        // Check for undocumented subcommand: status github-app-verify
        if (isset($this->arguments[1]) && $this->arguments[1] === 'github-app-verify') {
            $this->runGitHubAppVerify();
            return;
        }

        $this->output->plain("horde-components status -- minding any CLI switches, current working directory and config file content");
        $this->output->info("Config file path: $this->configFilePath");
        if (is_readable($this->configFilePath)) {
            $this->output->ok("Config file exists and is readable.");
        } else {
            $this->output->warn("Config file does not exist or is not readable.");
        };
        $this->output->info("Git Tree root path: $this->localCheckoutDir");
        if ($this->localCheckoutDir->exists()) {
            $componentsCount = count($this->localCheckoutDir->getHordeYmlDirs());
            $gitCount = count($this->localCheckoutDir->getGitDirs());
            if ($gitCount) {
                $this->output->ok("Git Tree dir exists and has $gitCount repos checked out ($componentsCount components)");
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

        // Check GitHub authentication
        $this->checkGitHubAuthentication();
    }

    /**
     * Check GitHub authentication configuration and status
     *
     * @return void
     */
    private function checkGitHubAuthentication(): void
    {
        $this->output->info("GitHub Authentication:");

        try {
            // Check if any auth is configured
            if (!$this->authFactory->hasAuth()) {
                $this->output->warn("No GitHub authentication configured.");
                $this->output->help("Configure either:
  1. GitHub App (RECOMMENDED for automation):
     - Set github.app.id, github.app.installation_id, github.app.private_key_path in config
     - Or export GITHUB_APP_ID, GITHUB_APP_INSTALLATION_ID, GITHUB_APP_PRIVATE_KEY_PATH

  2. Personal Access Token:
     - Export GITHUB_TOKEN=ghp_your_token_here
     - Or set github.token in config file");
                return;
            }

            // Show which auth method is configured
            $authMethod = $this->authFactory->getAuthMethod();
            $this->output->ok("Authentication method: $authMethod");

            // If PAT is configured but GitHub App takes precedence, warn user
            if ($this->authFactory->hasGitHubAppAuth()) {
                $patFromEnv = getenv('GITHUB_TOKEN');
                $patFromConfig = $this->config->hasSetting('github.token')
                    ? $this->config->getSetting('github.token')
                    : null;

                if (($patFromEnv !== false && trim($patFromEnv) !== '')
                    || ($patFromConfig !== null && trim($patFromConfig) !== '')) {
                    $this->output->info("Note: GitHub App authentication takes precedence over Personal Access Token");
                }

                // Show GitHub App specific information
                $this->checkGitHubAppStatus();
            } else {
                // Show PAT information
                $this->checkPatStatus();
            }

            // Try to authenticate and check API status
            try {
                $strategy = $this->authFactory->create();
                $apiClient = $strategy->authenticate();

                // Check rate limit
                $this->checkRateLimit($apiClient);

                // For PAT, check scopes
                if (!$this->authFactory->hasGitHubAppAuth()) {
                    $this->checkTokenScopes($apiClient);
                }
            } catch (\Exception $e) {
                $this->output->warn("Authentication failed: " . $e->getMessage());
            }
        } catch (Throwable $e) {
            $this->output->warn("Error checking GitHub authentication: " . $e->getMessage());
        }
    }

    /**
     * Check GitHub App specific status
     *
     * @return void
     */
    private function checkGitHubAppStatus(): void
    {
        try {
            $strategy = $this->authFactory->create();

            // If it's a GitHub App strategy, get more details
            if ($strategy instanceof GitHubAppAuthenticationStrategy) {
                // Get JWT-authenticated client for app-level operations
                $jwtClient = $strategy->getJwtAuthenticatedClient();

                // Get app information
                try {
                    $app = $jwtClient->getAuthenticatedApp();
                    $this->output->ok("GitHub App: {$app->name} (ID: {$app->id})");
                } catch (\Exception $e) {
                    $this->output->warn("Could not fetch app details: " . $e->getMessage());
                }

                // Get installations
                try {
                    $installations = $jwtClient->listInstallations();
                    $count = count($installations);
                    if ($count > 0) {
                        $this->output->ok("App has $count installation(s)");
                        foreach ($installations as $installation) {
                            $this->output->info("  - {$installation->account->login} (ID: {$installation->id})");
                        }
                    }
                } catch (\Exception $e) {
                    $this->output->warn("Could not list installations: " . $e->getMessage());
                }

                $this->output->info("Installation tokens are cached for ~1 hour and refreshed automatically");
            }
        } catch (\Exception $e) {
            $this->output->warn("Error checking GitHub App status: " . $e->getMessage());
        }
    }

    /**
     * Check Personal Access Token status
     *
     * @return void
     */
    private function checkPatStatus(): void
    {
        // Get token for display purposes
        $token = '';
        if ($this->config->hasSetting('GITHUB_TOKEN')) {
            $token = $this->config->getSetting('GITHUB_TOKEN');
        } elseif ($this->config->hasSetting('github.token')) {
            $token = $this->config->getSetting('github.token');
        }

        if ($token && mb_strlen($token) > 0) {
            $maskedToken = substr($token, 0, 8) . str_repeat('*', max(0, mb_strlen($token) - 8));
            $this->output->ok("Token configured: $maskedToken");
            $this->output->warn("Consider migrating to GitHub App authentication for better security");
        }
    }

    /**
     * Check token scopes (for PAT only)
     *
     * @param GithubApiClient $apiClient
     * @return void
     */
    private function checkTokenScopes(GithubApiClient $apiClient): void
    {
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
        } catch (Throwable $e) {
            $this->output->warn("Failed to verify token scopes: " . $e->getMessage());
            if (str_contains($e->getMessage(), '401')) {
                $this->output->warn("Token appears to be invalid, expired, or revoked");
                $this->output->help("Generate a new token at: https://github.com/settings/tokens");
            }
        }
    }

    /**
     * Check GitHub API rate limit
     *
     * @param GithubApiClient $apiClient
     * @return void
     */
    private function checkRateLimit(GithubApiClient $apiClient): void
    {
        try {
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
        } catch (Throwable $e) {
            $this->output->warn("Failed to check rate limit: " . $e->getMessage());
        }
    }

    /**
     * Run detailed GitHub App verification (undocumented subcommand)
     *
     * @return void
     */
    private function runGitHubAppVerify(): void
    {
        $this->output->plain("GitHub App Authentication Verification");
        $this->output->plain(str_repeat("=", 50));

        try {
            // Check if GitHub App is configured
            if (!$this->authFactory->hasGitHubAppAuth()) {
                $this->output->warn("No GitHub App authentication configured.");
                $this->showGitHubAppConfigHelp();
                return;
            }

            $this->output->ok("GitHub App configuration detected");

            // Show configuration source
            $this->showGitHubAppConfigSource();

            // Test authentication
            $this->output->info("Testing authentication...");

            try {
                $strategy = $this->authFactory->create();

                if (!$strategy instanceof GitHubAppAuthenticationStrategy) {
                    $this->output->warn("Authentication is configured but not using GitHub App");
                    $this->output->info("Current method: " . $this->authFactory->getAuthMethod());
                    return;
                }

                // Test installation token authentication (for repository operations)
                $apiClient = $strategy->authenticate();
                $this->output->ok("Successfully authenticated with GitHub (installation token)");

                // Get JWT-authenticated client for app-level operations
                $jwtClient = $strategy->getJwtAuthenticatedClient();
                $this->output->ok("Successfully authenticated with GitHub (JWT)");

                // Get and display app information (requires JWT)
                $this->displayDetailedAppInformation($jwtClient);

                // Get and display installations (requires JWT)
                $this->displayDetailedInstallations($jwtClient);

                // Check rate limit (uses installation token)
                $this->displayDetailedRateLimit($apiClient);

                // Check token caching status
                $this->displayTokenManagement();

                $this->output->plain("");
                $this->output->ok("GitHub App verification completed successfully!");

            } catch (\Exception $e) {
                $this->output->warn("Authentication failed: " . $e->getMessage());
                $this->showTroubleshootingHelp($e);
            }

        } catch (Throwable $e) {
            $this->output->warn("Error during verification: " . $e->getMessage());
        }
    }

    /**
     * Show GitHub App configuration source
     *
     * @return void
     */
    private function showGitHubAppConfigSource(): void
    {
        $this->output->info("Configuration source:");

        // Check environment variables
        $appIdEnv = getenv('GITHUB_APP_ID');
        $installIdEnv = getenv('GITHUB_APP_INSTALLATION_ID');
        $keyPathEnv = getenv('GITHUB_APP_PRIVATE_KEY_PATH');

        $fromEnv = ($appIdEnv !== false && trim($appIdEnv) !== '')
                   || ($installIdEnv !== false && trim($installIdEnv) !== '')
                   || ($keyPathEnv !== false && trim($keyPathEnv) !== '');

        if ($fromEnv) {
            $this->output->info("  - Using environment variables");
            if ($appIdEnv !== false && trim($appIdEnv) !== '') {
                $this->output->info("    GITHUB_APP_ID: $appIdEnv");
            }
            if ($installIdEnv !== false && trim($installIdEnv) !== '') {
                $this->output->info("    GITHUB_APP_INSTALLATION_ID: $installIdEnv");
            }
            if ($keyPathEnv !== false && trim($keyPathEnv) !== '') {
                $this->output->info("    GITHUB_APP_PRIVATE_KEY_PATH: $keyPathEnv");
            }
        } else {
            $this->output->info("  - Using configuration file");
            if ($this->config->hasSetting('github.app.id')) {
                $appId = $this->config->getSetting('github.app.id');
                $this->output->info("    github.app.id: $appId");
            }
            if ($this->config->hasSetting('github.app.installation_id')) {
                $installId = $this->config->getSetting('github.app.installation_id');
                $this->output->info("    github.app.installation_id: $installId");
            }
            if ($this->config->hasSetting('github.app.private_key_path')) {
                $keyPath = $this->config->getSetting('github.app.private_key_path');
                $this->output->info("    github.app.private_key_path: $keyPath");
            }
        }
    }

    /**
     * Display detailed GitHub App information
     *
     * @param GithubApiClient $apiClient
     * @return void
     */
    private function displayDetailedAppInformation(GithubApiClient $apiClient): void
    {
        try {
            $this->output->info("Fetching GitHub App information...");
            $app = $apiClient->getAuthenticatedApp();

            $this->output->plain("");
            $this->output->ok("GitHub App Details:");
            $this->output->info("  Name: {$app->name}");
            $this->output->info("  Slug: {$app->slug}");
            $this->output->info("  ID: {$app->id}");
            $this->output->info("  Owner: {$app->owner->login}");
            $this->output->info("  Created: {$app->createdAt}");
            $this->output->info("  Updated: {$app->updatedAt}");
        } catch (\Exception $e) {
            $this->output->warn("Failed to fetch app information: " . $e->getMessage());
        }
    }

    /**
     * Display detailed installations
     *
     * @param GithubApiClient $apiClient
     * @return void
     */
    private function displayDetailedInstallations(GithubApiClient $apiClient): void
    {
        try {
            $this->output->info("Fetching installations...");
            $installations = $apiClient->listInstallations();

            $count = count($installations);
            $this->output->plain("");
            $this->output->ok("Installations: $count");

            foreach ($installations as $installation) {
                $this->output->info("  - {$installation->account->login} (ID: {$installation->id})");
                $this->output->info("    Repository selection: {$installation->repositorySelection}");
                $this->output->info("    Created: {$installation->createdAt}");
            }

            if ($count === 0) {
                $this->output->warn("No installations found");
                $this->output->help("Install the app at: https://github.com/settings/apps");
            }
        } catch (\Exception $e) {
            $this->output->warn("Failed to fetch installations: " . $e->getMessage());
        }
    }

    /**
     * Display detailed rate limit information
     *
     * @param GithubApiClient $apiClient
     * @return void
     */
    private function displayDetailedRateLimit(GithubApiClient $apiClient): void
    {
        try {
            $this->output->info("Checking API rate limit...");
            $rateLimit = $apiClient->getRateLimit();

            $usagePercent = $rateLimit->getUsagePercentage();
            $this->output->plain("");

            if ($rateLimit->isExhausted()) {
                $resetTime = $rateLimit->getResetDateTime()->format('Y-m-d H:i:s T');
                $this->output->warn("Rate Limit Status: EXHAUSTED");
                $this->output->warn("  Resets at: $resetTime");
            } else {
                $this->output->ok("Rate Limit Status:");
                $this->output->info(sprintf(
                    "  Used: %d of %d (%.1f%%)",
                    $rateLimit->used,
                    $rateLimit->limit,
                    $usagePercent
                ));
                $this->output->info("  Remaining: {$rateLimit->remaining}");

                $secondsUntilReset = $rateLimit->getSecondsUntilReset();
                if ($secondsUntilReset > 0) {
                    $minutesUntilReset = ceil($secondsUntilReset / 60);
                    $this->output->info("  Resets in: $minutesUntilReset minutes");
                }
            }
        } catch (\Exception $e) {
            $this->output->warn("Failed to check rate limit: " . $e->getMessage());
        }
    }

    /**
     * Display token management information
     *
     * @return void
     */
    private function displayTokenManagement(): void
    {
        $this->output->plain("");
        $this->output->info("Token Management:");
        $this->output->info("  - Installation tokens are cached for ~1 hour");
        $this->output->info("  - Tokens are automatically refreshed 60 seconds before expiry");
        $this->output->info("  - No manual token management required");
    }

    /**
     * Show GitHub App configuration help
     *
     * @return void
     */
    private function showGitHubAppConfigHelp(): void
    {
        $this->output->help("
Configure GitHub App authentication via:

Environment variables:
  export GITHUB_APP_ID=123456
  export GITHUB_APP_INSTALLATION_ID=789012
  export GITHUB_APP_PRIVATE_KEY_PATH=/path/to/key.pem

Or config file (config/conf.php or ~/.config/horde/components.php):
  \$conf['github.app.id'] = '123456';
  \$conf['github.app.installation_id'] = '789012';
  \$conf['github.app.private_key_path'] = '/path/to/key.pem';

GitHub App Setup:
1. Create app at: https://github.com/settings/apps/new
2. Generate and download private key
3. Install app to your organization
4. Get installation ID from installed app URL
5. Configure the values above
");
    }

    /**
     * Show troubleshooting help based on error
     *
     * @param \Exception $e
     * @return void
     */
    private function showTroubleshootingHelp(\Exception $e): void
    {
        $message = $e->getMessage();

        $this->output->plain("");
        $this->output->help("Troubleshooting:");

        if (str_contains($message, 'private key')) {
            $this->output->help("
Private Key Issues:
  - Verify the path to your .pem file is correct
  - Ensure the file is readable by the current user
  - Check that the private key format is valid (PEM format)
  - Regenerate the key in GitHub App settings if needed
");
        } elseif (str_contains($message, '401') || str_contains($message, 'Unauthorized')) {
            $this->output->help("
Authentication Failed (401 Unauthorized):
  - Verify your App ID is correct
  - Check that the installation ID matches your organization
  - Ensure the private key matches the app
  - Verify the app has necessary permissions
");
        } elseif (str_contains($message, '404') || str_contains($message, 'Not Found')) {
            $this->output->help("
Not Found (404):
  - The installation ID may be incorrect
  - List installations at: https://github.com/settings/apps
  - Update GITHUB_APP_INSTALLATION_ID with correct value
  - Ensure the app is installed to your organization
");
        } elseif (str_contains($message, '403') || str_contains($message, 'Forbidden')) {
            $this->output->help("
Forbidden (403):
  - The app may not have required permissions
  - Check app permissions at: https://github.com/settings/apps
  - Ensure 'Metadata' and 'Contents' permissions are granted
  - Re-install the app if permissions were changed
");
        } else {
            $this->output->help("
General troubleshooting steps:
  1. Verify all three configuration values are set
  2. Check that the private key file exists and is readable
  3. Ensure the app is installed to your organization
  4. Verify network connectivity to api.github.com
  5. Check GitHub status at: https://www.githubstatus.com
");
        }
    }
}
