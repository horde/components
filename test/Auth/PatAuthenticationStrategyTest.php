<?php

declare(strict_types=1);

namespace Horde\Components\Test\Auth;

use Horde\Components\Auth\PatAuthenticationStrategy;
use Horde\GithubApiClient\GithubApiClient;
use RuntimeException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

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
#[CoversClass(PatAuthenticationStrategy::class)]
class PatAuthenticationStrategyTest extends TestCase
{
    public function testAuthenticateReturnsConfiguredClient(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $requestFactory = $this->createMock(RequestFactoryInterface::class);
        $streamFactory = $this->createMock(StreamFactoryInterface::class);

        $strategy = new PatAuthenticationStrategy(
            'ghp_test_token_123',
            $httpClient,
            $requestFactory,
            $streamFactory
        );

        $client = $strategy->authenticate();

        $this->assertInstanceOf(GithubApiClient::class, $client);
    }

    public function testAuthenticateWithoutStreamFactory(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $requestFactory = $this->createMock(RequestFactoryInterface::class);

        $strategy = new PatAuthenticationStrategy(
            'ghp_test_token_456',
            $httpClient,
            $requestFactory
        );

        $client = $strategy->authenticate();

        $this->assertInstanceOf(GithubApiClient::class, $client);
    }

    public function testGetDescriptionReturnsCorrectString(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $requestFactory = $this->createMock(RequestFactoryInterface::class);

        $strategy = new PatAuthenticationStrategy(
            'ghp_token',
            $httpClient,
            $requestFactory
        );

        $this->assertSame('Personal Access Token', $strategy->getDescription());
    }

    public function testConstructorThrowsOnEmptyToken(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $requestFactory = $this->createMock(RequestFactoryInterface::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Personal Access Token cannot be empty');

        new PatAuthenticationStrategy(
            '',
            $httpClient,
            $requestFactory
        );
    }

    public function testConstructorThrowsOnWhitespaceToken(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $requestFactory = $this->createMock(RequestFactoryInterface::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Personal Access Token cannot be empty');

        new PatAuthenticationStrategy(
            "   \t   ",
            $httpClient,
            $requestFactory
        );
    }
}
