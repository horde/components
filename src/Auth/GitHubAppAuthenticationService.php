<?php

declare(strict_types=1);

namespace Horde\Components\Auth;

use Horde\GithubApiClient\GithubApiClient;
use Horde\GithubApiClient\GithubApiConfig;
use Horde\GithubApiClient\InstallationAccessToken;
use Horde\GithubApiClient\CreateInstallationAccessTokenParams;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use RuntimeException;

/**
 * GitHub App authentication service
 *
 * Manages JWT generation and installation token lifecycle for GitHub App authentication.
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
class GitHubAppAuthenticationService
{
    private ?InstallationAccessToken $cachedToken = null;
    private ?int $tokenExpiresAt = null;

    /**
     * @param GitHubAppConfig $config GitHub App configuration
     * @param JwtGeneratorInterface $jwtGenerator JWT generator for app authentication
     * @param ClientInterface $httpClient HTTP client for API calls
     * @param RequestFactoryInterface $requestFactory PSR-17 request factory
     * @param StreamFactoryInterface $streamFactory PSR-17 stream factory
     */
    public function __construct(
        private readonly GitHubAppConfig $config,
        private readonly JwtGeneratorInterface $jwtGenerator,
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory
    ) {}

    /**
     * Get authenticated GitHub API client
     *
     * Returns a client configured with a valid installation access token.
     * Tokens are cached and automatically refreshed when expired.
     *
     * @return GithubApiClient
     * @throws RuntimeException If authentication fails
     */
    public function getAuthenticatedClient(): GithubApiClient
    {
        $token = $this->getInstallationAccessToken();

        $config = new GithubApiConfig(
            accessToken: $token->token
        );

        return new GithubApiClient(
            $this->httpClient,
            $this->requestFactory,
            $config,
            $this->streamFactory
        );
    }

    /**
     * Get JWT-authenticated GitHub API client
     *
     * Returns a client configured with JWT for app-level operations.
     * Use this for operations like getAuthenticatedApp() and listInstallations().
     *
     * @return GithubApiClient
     * @throws RuntimeException If authentication fails
     */
    public function getJwtAuthenticatedClient(): GithubApiClient
    {
        // Load private key
        $privateKey = PrivateKey::fromFile($this->config->privateKeyPath);

        // Generate JWT (9 minutes to be safe)
        $jwt = $this->jwtGenerator->generate(
            $this->config->appId,
            $privateKey,
            540
        );

        // Create GitHub API client with JWT
        $jwtConfig = new GithubApiConfig(jwt: $jwt->token);
        return new GithubApiClient(
            $this->httpClient,
            $this->requestFactory,
            $jwtConfig,
            $this->streamFactory
        );
    }

    /**
     * Get installation access token (with caching)
     *
     * @return InstallationAccessToken
     * @throws RuntimeException If token generation fails
     */
    private function getInstallationAccessToken(): InstallationAccessToken
    {
        // Check if cached token is still valid (refresh 60 seconds before expiry)
        if ($this->cachedToken !== null && $this->tokenExpiresAt !== null) {
            if (time() < ($this->tokenExpiresAt - 60)) {
                return $this->cachedToken;
            }
        }

        // Generate new installation token
        $this->cachedToken = $this->generateInstallationAccessToken();

        // Parse expiry from token
        $this->tokenExpiresAt = $this->parseExpiryFromToken($this->cachedToken);

        return $this->cachedToken;
    }

    /**
     * Generate new installation access token
     *
     * @return InstallationAccessToken
     * @throws RuntimeException If generation fails
     */
    private function generateInstallationAccessToken(): InstallationAccessToken
    {
        // Load private key
        $privateKey = PrivateKey::fromFile($this->config->privateKeyPath);

        // Generate JWT (9 minutes to be safe)
        $jwt = $this->jwtGenerator->generate(
            $this->config->appId,
            $privateKey,
            540
        );

        // Create GitHub API client with JWT
        $jwtConfig = new GithubApiConfig(jwt: $jwt->token);
        $jwtClient = new GithubApiClient(
            $this->httpClient,
            $this->requestFactory,
            $jwtConfig,
            $this->streamFactory
        );

        // Request installation access token
        try {
            return $jwtClient->createInstallationAccessToken(
                $this->config->installationId,
                new CreateInstallationAccessTokenParams()
            );
        } catch (\Exception $e) {
            throw new RuntimeException(
                "Failed to create installation access token: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    /**
     * Parse expiry timestamp from installation access token
     *
     * @param InstallationAccessToken $token
     * @return int Unix timestamp
     */
    private function parseExpiryFromToken(InstallationAccessToken $token): int
    {
        // GitHub returns ISO 8601 format like "2026-03-02T13:00:00Z"
        $timestamp = strtotime($token->expiresAt);
        if ($timestamp === false) {
            // Fallback: assume 1 hour from now
            return time() + 3600;
        }
        return $timestamp;
    }

    /**
     * Clear cached token (useful for testing or forcing refresh)
     */
    public function clearCache(): void
    {
        $this->cachedToken = null;
        $this->tokenExpiresAt = null;
    }

    /**
     * Check if a valid token is cached
     *
     * @return bool
     */
    public function hasValidCachedToken(): bool
    {
        if ($this->cachedToken === null || $this->tokenExpiresAt === null) {
            return false;
        }

        // Consider valid if more than 60 seconds remaining
        return time() < ($this->tokenExpiresAt - 60);
    }
}
