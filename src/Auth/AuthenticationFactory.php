<?php

declare(strict_types=1);

namespace Horde\Components\Auth;

use Horde\Components\ConfigProvider\ConfigProvider;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use RuntimeException;
use InvalidArgumentException;

/**
 * Authentication factory that determines which authentication method to use
 *
 * Precedence (highest to lowest):
 * 1. GitHub App configuration (config file or environment)
 * 2. PAT from GITHUB_TOKEN environment variable
 * 3. PAT from config file (github.token)
 *
 * Copyright 2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class AuthenticationFactory
{
    /**
     * @param ConfigProvider $config Components configuration
     * @param ClientInterface $httpClient HTTP client for API calls
     * @param RequestFactoryInterface $requestFactory PSR-17 request factory
     * @param StreamFactoryInterface $streamFactory PSR-17 stream factory
     */
    public function __construct(
        private readonly ConfigProvider $config,
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory
    ) {}

    /**
     * Create authentication strategy based on available configuration
     *
     * @return AuthenticationStrategyInterface
     * @throws RuntimeException If no valid authentication is configured
     */
    public function create(): AuthenticationStrategyInterface
    {
        // Priority 1: GitHub App (from config or environment)
        $githubAppStrategy = $this->createGitHubAppStrategy();
        if ($githubAppStrategy !== null) {
            return $githubAppStrategy;
        }

        // Priority 2: PAT from environment variable
        $patFromEnv = getenv('GITHUB_TOKEN');
        if ($patFromEnv !== false && trim($patFromEnv) !== '') {
            return new PatAuthenticationStrategy(
                $patFromEnv,
                $this->httpClient,
                $this->requestFactory,
                $this->streamFactory
            );
        }

        // Priority 3: PAT from config file
        if ($this->config->hasSetting('github.token')) {
            $patFromConfig = $this->config->getSetting('github.token');
            if (trim($patFromConfig) !== '') {
                return new PatAuthenticationStrategy(
                    $patFromConfig,
                    $this->httpClient,
                    $this->requestFactory,
                    $this->streamFactory
                );
            }
        }

        throw new RuntimeException(
            'No GitHub authentication configured. Please set either:' . PHP_EOL
            . '  1. GitHub App credentials (config: github.app.* or env: GITHUB_APP_*)' . PHP_EOL
            . '  2. Personal Access Token (env: GITHUB_TOKEN or config: github.token)'
        );
    }

    /**
     * Attempt to create GitHub App authentication strategy
     *
     * @return AuthenticationStrategyInterface|null
     */
    private function createGitHubAppStrategy(): ?AuthenticationStrategyInterface
    {
        $appConfig = $this->loadGitHubAppConfig();
        if ($appConfig === null) {
            return null;
        }

        $jwtGenerator = new GitHubJwtGenerator();
        $authService = new GitHubAppAuthenticationService(
            $appConfig,
            $jwtGenerator,
            $this->httpClient,
            $this->requestFactory,
            $this->streamFactory
        );

        return new GitHubAppAuthenticationStrategy($authService);
    }

    /**
     * Load GitHub App configuration from config or environment
     *
     * Tries environment variables first, then config file
     *
     * @return GitHubAppConfig|null
     */
    private function loadGitHubAppConfig(): ?GitHubAppConfig
    {
        // Try environment variables first
        try {
            return GitHubAppConfig::fromEnvironment();
        } catch (InvalidArgumentException $e) {
            // Environment variables not set or incomplete, try config file
        }

        // Try config file
        $appId = null;
        $installationId = null;
        $privateKeyPath = null;

        if ($this->config->hasSetting('github.app.id')) {
            $appId = $this->config->getSetting('github.app.id');
        }
        if ($this->config->hasSetting('github.app.installation_id')) {
            $installationId = $this->config->getSetting('github.app.installation_id');
        }
        if ($this->config->hasSetting('github.app.private_key_path')) {
            $privateKeyPath = $this->config->getSetting('github.app.private_key_path');
        }

        // Check if all required values are present
        if (empty($appId) || empty($installationId) || empty($privateKeyPath)) {
            return null;
        }

        try {
            return new GitHubAppConfig(
                appId: (int) $appId,
                installationId: (int) $installationId,
                privateKeyPath: (string) $privateKeyPath
            );
        } catch (InvalidArgumentException $e) {
            // Invalid configuration, return null
            return null;
        }
    }

    /**
     * Check if GitHub App authentication is configured
     *
     * @return bool
     */
    public function hasGitHubAppAuth(): bool
    {
        return $this->loadGitHubAppConfig() !== null;
    }

    /**
     * Check if any authentication is configured
     *
     * @return bool
     */
    public function hasAuth(): bool
    {
        // Check GitHub App
        if ($this->hasGitHubAppAuth()) {
            return true;
        }

        // Check PAT from environment
        $patFromEnv = getenv('GITHUB_TOKEN');
        if ($patFromEnv !== false && trim($patFromEnv) !== '') {
            return true;
        }

        // Check PAT from config
        if ($this->config->hasSetting('github.token')) {
            $patFromConfig = $this->config->getSetting('github.token');
            if (trim($patFromConfig) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Get description of configured authentication method
     *
     * @return string
     */
    public function getAuthMethod(): string
    {
        if ($this->hasGitHubAppAuth()) {
            return 'GitHub App';
        }

        $patFromEnv = getenv('GITHUB_TOKEN');
        if ($patFromEnv !== false && trim($patFromEnv) !== '') {
            return 'Personal Access Token (from GITHUB_TOKEN environment variable)';
        }

        if ($this->config->hasSetting('github.token')) {
            $patFromConfig = $this->config->getSetting('github.token');
            if (trim($patFromConfig) !== '') {
                return 'Personal Access Token (from config file)';
            }
        }

        return 'None';
    }
}
