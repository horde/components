<?php

declare(strict_types=1);

namespace Horde\Components\Auth;

use Horde\GithubApiClient\GithubApiClient;
use RuntimeException;

/**
 * Interface for GitHub authentication strategies
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
interface AuthenticationStrategyInterface
{
    /**
     * Authenticate and return configured GitHub API client
     *
     * @return GithubApiClient Configured client ready to make API calls
     * @throws RuntimeException If authentication fails
     */
    public function authenticate(): GithubApiClient;

    /**
     * Get human-readable description of the authentication method
     *
     * @return string Description (e.g., "GitHub App", "Personal Access Token")
     */
    public function getDescription(): string;
}
