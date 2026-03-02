<?php

declare(strict_types=1);

namespace Horde\Components\Auth;

use Horde\GithubApiClient\GithubApiClient;
use RuntimeException;

/**
 * GitHub App authentication strategy
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
class GitHubAppAuthenticationStrategy implements AuthenticationStrategyInterface
{
    /**
     * @param GitHubAppAuthenticationService $authService GitHub App authentication service
     */
    public function __construct(
        private readonly GitHubAppAuthenticationService $authService
    ) {}

    /**
     * {@inheritdoc}
     */
    public function authenticate(): GithubApiClient
    {
        try {
            return $this->authService->getAuthenticatedClient();
        } catch (\Exception $e) {
            throw new RuntimeException(
                "GitHub App authentication failed: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    /**
     * Get JWT-authenticated client for app-level operations
     *
     * Returns a client authenticated with JWT for operations like:
     * - getAuthenticatedApp()
     * - listInstallations()
     *
     * For normal repository/PR operations, use authenticate() instead.
     *
     * @return GithubApiClient
     * @throws RuntimeException If authentication fails
     */
    public function getJwtAuthenticatedClient(): GithubApiClient
    {
        try {
            return $this->authService->getJwtAuthenticatedClient();
        } catch (\Exception $e) {
            throw new RuntimeException(
                "GitHub App JWT authentication failed: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getDescription(): string
    {
        return 'GitHub App';
    }
}
