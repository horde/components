<?php

declare(strict_types=1);

namespace Horde\Components\Test\Auth;

use Horde\Components\Auth\GitHubAppAuthenticationStrategy;
use Horde\Components\Auth\GitHubAppAuthenticationService;
use Horde\GithubApiClient\GithubApiClient;
use RuntimeException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Copyright 2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
#[CoversClass(GitHubAppAuthenticationStrategy::class)]
class GitHubAppAuthenticationStrategyTest extends TestCase
{
    public function testAuthenticateReturnsConfiguredClient(): void
    {
        $mockClient = $this->createMock(GithubApiClient::class);

        $authService = $this->createMock(GitHubAppAuthenticationService::class);
        $authService->method('getAuthenticatedClient')->willReturn($mockClient);

        $strategy = new GitHubAppAuthenticationStrategy($authService);
        $client = $strategy->authenticate();

        $this->assertSame($mockClient, $client);
    }

    public function testAuthenticateThrowsOnServiceFailure(): void
    {
        $authService = $this->createMock(GitHubAppAuthenticationService::class);
        $authService->method('getAuthenticatedClient')
            ->willThrowException(new RuntimeException('Service failure'));

        $strategy = new GitHubAppAuthenticationStrategy($authService);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('GitHub App authentication failed');

        $strategy->authenticate();
    }

    public function testGetDescriptionReturnsCorrectString(): void
    {
        $authService = $this->createMock(GitHubAppAuthenticationService::class);
        $strategy = new GitHubAppAuthenticationStrategy($authService);

        $this->assertSame('GitHub App', $strategy->getDescription());
    }
}
