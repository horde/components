<?php

declare(strict_types=1);

namespace Horde\Components\Test\Auth;

use Horde\Components\Auth\GitHubAppAuthenticationService;
use Horde\Components\Auth\GitHubAppConfig;
use Horde\Components\Auth\JwtGeneratorInterface;
use Horde\Components\Auth\GeneratedJwt;
use Horde\GithubApiClient\GithubApiClient;
use Horde\GithubApiClient\InstallationAccessToken;
use RuntimeException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

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
#[CoversClass(GitHubAppAuthenticationService::class)]
class GitHubAppAuthenticationServiceTest extends TestCase
{
    private string $testKeyPath;

    protected function setUp(): void
    {
        $this->testKeyPath = __DIR__ . '/test-private-key.pem';
    }

    public function testGetAuthenticatedClientReturnsClient(): void
    {
        $config = new GitHubAppConfig(123456, 789012, $this->testKeyPath);
        $jwtGenerator = $this->createMock(JwtGeneratorInterface::class);
        $httpClient = $this->createMock(ClientInterface::class);
        $requestFactory = $this->createMock(RequestFactoryInterface::class);
        $streamFactory = $this->createMock(StreamFactoryInterface::class);

        // Mock JWT generation
        $jwt = new GeneratedJwt('test.jwt.token', time() + 540);
        $jwtGenerator->method('generate')->willReturn($jwt);

        // Mock HTTP request/response for installation token
        $request = $this->createMock(RequestInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        $bodyStream = $this->createMock(StreamInterface::class);
        $requestStream = $this->createMock(StreamInterface::class);

        $requestFactory->method('createRequest')->willReturn($request);
        $request->method('withHeader')->willReturnSelf();
        $request->method('withBody')->willReturnSelf();
        $streamFactory->method('createStream')->willReturn($requestStream);

        $responseBody = json_encode([
            'token' => 'ghs_installation_token_123',
            'expires_at' => date('Y-m-d\TH:i:s\Z', time() + 3600),
            'permissions' => ['contents' => 'read'],
            'repository_selection' => 'all',
        ]);

        $bodyStream->method('__toString')->willReturn($responseBody);
        $response->method('getStatusCode')->willReturn(201);
        $response->method('getBody')->willReturn($bodyStream);
        $httpClient->method('sendRequest')->willReturn($response);

        $service = new GitHubAppAuthenticationService(
            $config,
            $jwtGenerator,
            $httpClient,
            $requestFactory,
            $streamFactory
        );

        $client = $service->getAuthenticatedClient();

        $this->assertInstanceOf(GithubApiClient::class, $client);
    }

    public function testGetAuthenticatedClientCachesToken(): void
    {
        $config = new GitHubAppConfig(123456, 789012, $this->testKeyPath);
        $jwtGenerator = $this->createMock(JwtGeneratorInterface::class);
        $httpClient = $this->createMock(ClientInterface::class);
        $requestFactory = $this->createMock(RequestFactoryInterface::class);
        $streamFactory = $this->createMock(StreamFactoryInterface::class);

        $jwt = new GeneratedJwt('test.jwt.token', time() + 540);
        $jwtGenerator->method('generate')->willReturn($jwt);

        $request = $this->createMock(RequestInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        $stream = $this->createMock(StreamInterface::class);
        $requestStream = $this->createMock(StreamInterface::class);

        $requestFactory->method('createRequest')->willReturn($request);
        $request->method('withHeader')->willReturnSelf();
        $request->method('withBody')->willReturnSelf();
        $streamFactory->method('createStream')->willReturn($requestStream);

        $responseBody = json_encode([
            'token' => 'ghs_cached_token',
            'expires_at' => date('Y-m-d\TH:i:s\Z', time() + 3600),
            'permissions' => [],
            'repository_selection' => 'all',
        ]);

        $stream->method('__toString')->willReturn($responseBody);
        $response->method('getStatusCode')->willReturn(201);
        $response->method('getBody')->willReturn($stream);

        // HTTP client should be called only once (token is cached)
        $httpClient->expects($this->once())
            ->method('sendRequest')
            ->willReturn($response);

        $service = new GitHubAppAuthenticationService(
            $config,
            $jwtGenerator,
            $httpClient,
            $requestFactory,
            $streamFactory
        );

        // First call - generates token
        $client1 = $service->getAuthenticatedClient();

        // Second call - uses cached token
        $client2 = $service->getAuthenticatedClient();

        $this->assertInstanceOf(GithubApiClient::class, $client1);
        $this->assertInstanceOf(GithubApiClient::class, $client2);
    }

    public function testClearCacheInvalidatesCachedToken(): void
    {
        $config = new GitHubAppConfig(123456, 789012, $this->testKeyPath);
        $jwtGenerator = $this->createMock(JwtGeneratorInterface::class);
        $httpClient = $this->createMock(ClientInterface::class);
        $requestFactory = $this->createMock(RequestFactoryInterface::class);
        $streamFactory = $this->createMock(StreamFactoryInterface::class);

        $jwt = new GeneratedJwt('test.jwt.token', time() + 540);
        $jwtGenerator->method('generate')->willReturn($jwt);

        $request = $this->createMock(RequestInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        $stream = $this->createMock(StreamInterface::class);
        $requestStream = $this->createMock(StreamInterface::class);

        $requestFactory->method('createRequest')->willReturn($request);
        $request->method('withHeader')->willReturnSelf();
        $request->method('withBody')->willReturnSelf();
        $streamFactory->method('createStream')->willReturn($requestStream);

        $responseBody = json_encode([
            'token' => 'ghs_token',
            'expires_at' => date('Y-m-d\TH:i:s\Z', time() + 3600),
            'permissions' => [],
            'repository_selection' => 'all',
        ]);

        $stream->method('__toString')->willReturn($responseBody);
        $response->method('getStatusCode')->willReturn(201);
        $response->method('getBody')->willReturn($stream);

        // HTTP client should be called twice (cache cleared between calls)
        $httpClient->expects($this->exactly(2))
            ->method('sendRequest')
            ->willReturn($response);

        $service = new GitHubAppAuthenticationService(
            $config,
            $jwtGenerator,
            $httpClient,
            $requestFactory,
            $streamFactory
        );

        // First call
        $service->getAuthenticatedClient();

        // Clear cache
        $service->clearCache();

        // Second call - should regenerate token
        $service->getAuthenticatedClient();
    }

    public function testHasValidCachedTokenReturnsFalseInitially(): void
    {
        $config = new GitHubAppConfig(123456, 789012, $this->testKeyPath);
        $jwtGenerator = $this->createMock(JwtGeneratorInterface::class);
        $httpClient = $this->createMock(ClientInterface::class);
        $requestFactory = $this->createMock(RequestFactoryInterface::class);
        $streamFactory = $this->createMock(StreamFactoryInterface::class);

        $service = new GitHubAppAuthenticationService(
            $config,
            $jwtGenerator,
            $httpClient,
            $requestFactory,
            $streamFactory
        );

        $this->assertFalse($service->hasValidCachedToken());
    }

    public function testHasValidCachedTokenReturnsTrueAfterAuthentication(): void
    {
        $config = new GitHubAppConfig(123456, 789012, $this->testKeyPath);
        $jwtGenerator = $this->createMock(JwtGeneratorInterface::class);
        $httpClient = $this->createMock(ClientInterface::class);
        $requestFactory = $this->createMock(RequestFactoryInterface::class);
        $streamFactory = $this->createMock(StreamFactoryInterface::class);

        $jwt = new GeneratedJwt('test.jwt.token', time() + 540);
        $jwtGenerator->method('generate')->willReturn($jwt);

        $request = $this->createMock(RequestInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        $stream = $this->createMock(StreamInterface::class);
        $requestStream = $this->createMock(StreamInterface::class);

        $requestFactory->method('createRequest')->willReturn($request);
        $request->method('withHeader')->willReturnSelf();
        $request->method('withBody')->willReturnSelf();
        $streamFactory->method('createStream')->willReturn($requestStream);

        $responseBody = json_encode([
            'token' => 'ghs_token',
            'expires_at' => date('Y-m-d\TH:i:s\Z', time() + 3600),
            'permissions' => [],
            'repository_selection' => 'all',
        ]);

        $stream->method('__toString')->willReturn($responseBody);
        $response->method('getStatusCode')->willReturn(201);
        $response->method('getBody')->willReturn($stream);
        $httpClient->method('sendRequest')->willReturn($response);

        $service = new GitHubAppAuthenticationService(
            $config,
            $jwtGenerator,
            $httpClient,
            $requestFactory,
            $streamFactory
        );

        $service->getAuthenticatedClient();

        $this->assertTrue($service->hasValidCachedToken());
    }

    public function testHasValidCachedTokenReturnsFalseAfterClearCache(): void
    {
        $config = new GitHubAppConfig(123456, 789012, $this->testKeyPath);
        $jwtGenerator = $this->createMock(JwtGeneratorInterface::class);
        $httpClient = $this->createMock(ClientInterface::class);
        $requestFactory = $this->createMock(RequestFactoryInterface::class);
        $streamFactory = $this->createMock(StreamFactoryInterface::class);

        $jwt = new GeneratedJwt('test.jwt.token', time() + 540);
        $jwtGenerator->method('generate')->willReturn($jwt);

        $request = $this->createMock(RequestInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        $stream = $this->createMock(StreamInterface::class);
        $requestStream = $this->createMock(StreamInterface::class);

        $requestFactory->method('createRequest')->willReturn($request);
        $request->method('withHeader')->willReturnSelf();
        $request->method('withBody')->willReturnSelf();
        $streamFactory->method('createStream')->willReturn($requestStream);

        $responseBody = json_encode([
            'token' => 'ghs_token',
            'expires_at' => date('Y-m-d\TH:i:s\Z', time() + 3600),
            'permissions' => [],
            'repository_selection' => 'all',
        ]);

        $stream->method('__toString')->willReturn($responseBody);
        $response->method('getStatusCode')->willReturn(201);
        $response->method('getBody')->willReturn($stream);
        $httpClient->method('sendRequest')->willReturn($response);

        $service = new GitHubAppAuthenticationService(
            $config,
            $jwtGenerator,
            $httpClient,
            $requestFactory,
            $streamFactory
        );

        $service->getAuthenticatedClient();
        $service->clearCache();

        $this->assertFalse($service->hasValidCachedToken());
    }
}
