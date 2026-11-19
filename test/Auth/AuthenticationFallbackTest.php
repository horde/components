<?php

/**
 * Copyright 2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 */

declare(strict_types=1);

namespace Horde\Components\Test\Auth;

use Horde\Components\Auth\AuthenticationFactory;
use Horde\Components\ConfigProvider\BuiltinConfigProvider;
use Horde\Components\Output;
use Horde\GithubApiClient\GithubApiConfig;
use Horde\GithubApiClient\GithubOrganizationId;
use Horde\Http\RequestFactory;
use Horde\Http\Response;
use Horde\Http\StreamFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

#[CoversClass(AuthenticationFactory::class)]
class AuthenticationFallbackTest extends TestCase
{
    private array $environment = [];

    protected function setUp(): void
    {
        foreach (['GITHUB_TOKEN', 'GITHUB_APP_ID', 'GITHUB_APP_INSTALLATION_ID', 'GITHUB_APP_PRIVATE_KEY_PATH'] as $name) {
            $this->environment[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
    }

    public function testCatalogTriesAppBeforeEnvironmentPatAndReusesValidToken(): void
    {
        putenv('GITHUB_TOKEN=environment-pat');
        $http = $this->createMock(ClientInterface::class);
        $requests = [];
        $http->expects($this->exactly(4))->method('sendRequest')
            ->willReturnCallback(function (RequestInterface $request) use (&$requests) {
                $requests[] = $request;
                return match (count($requests)) {
                    1 => new Response(201, [], json_encode([
                        'token' => 'short-lived-app-token',
                        'expires_at' => gmdate('c', time() + 3600),
                    ], JSON_THROW_ON_ERROR)),
                    2 => new Response(403, [], '{"message":"Forbidden"}'),
                    default => new Response(200, [], '[]'),
                };
            });
        $factory = $this->appFactory($http);
        $output = $this->createMock(Output::class);
        $output->expects($this->once())->method('warn')->with($this->stringContains('retrying once'));
        $operation = fn(GithubApiConfig $config) => $factory->createClient($config)
            ->listRepositoriesInOrganization(new GithubOrganizationId('horde'));

        $factory->withEnvironmentPatFallback($operation, $output);
        $factory->withEnvironmentPatFallback($operation, $output);

        $this->assertSame('/app/installations/456/access_tokens', $requests[0]->getUri()->getPath());
        $this->assertStringContainsString('short-lived-app-token', $requests[1]->getHeaderLine('Authorization'));
        $this->assertStringContainsString('environment-pat', $requests[2]->getHeaderLine('Authorization'));
        $this->assertStringContainsString('short-lived-app-token', $requests[3]->getHeaderLine('Authorization'));
    }

    public function testTokenExchangeFailureRetriesOperationWithPat(): void
    {
        putenv('GITHUB_TOKEN=environment-pat');
        $http = $this->createMock(ClientInterface::class);
        $http->expects($this->once())->method('sendRequest')
            ->willThrowException(new RuntimeException('Token exchange failed'));
        $output = $this->createMock(Output::class);
        $output->expects($this->once())->method('warn');

        $token = $this->appFactory($http)->withEnvironmentPatFallback(
            fn(GithubApiConfig $config) => $config->accessToken,
            $output
        );

        $this->assertSame('environment-pat', $token);
    }

    public function testPatFailureIsNotRetriedAgain(): void
    {
        putenv('GITHUB_TOKEN=environment-pat');
        $factory = $this->createPartialMock(AuthenticationFactory::class, ['hasGitHubAppAuth', 'createConfig']);
        $factory->method('hasGitHubAppAuth')->willReturn(true);
        $factory->expects($this->once())->method('createConfig')->willReturn(new GithubApiConfig(accessToken: 'app-token'));
        $output = $this->createMock(Output::class);
        $output->expects($this->once())->method('warn');
        $tokens = [];

        try {
            $factory->withEnvironmentPatFallback(function (GithubApiConfig $config) use (&$tokens): never {
                $tokens[] = $config->accessToken;
                throw new RuntimeException($config->accessToken === 'app-token' ? 'App failed' : 'PAT failed');
            }, $output);
            $this->fail('The failed PAT retry must propagate.');
        } catch (RuntimeException $e) {
            $this->assertSame('PAT failed', $e->getMessage());
        }

        $this->assertSame(['app-token', 'environment-pat'], $tokens);
    }

    #[DataProvider('noFallbackCases')]
    public function testFailureWithoutBothCredentialsIsNotRetried(bool $usingApp, ?string $pat): void
    {
        if ($pat !== null) {
            putenv('GITHUB_TOKEN=' . $pat);
        }
        $factory = $this->createPartialMock(AuthenticationFactory::class, ['hasGitHubAppAuth', 'createConfig']);
        $factory->method('hasGitHubAppAuth')->willReturn($usingApp);
        $factory->expects($this->once())->method('createConfig')->willReturn(new GithubApiConfig());
        $output = $this->createMock(Output::class);
        $output->expects($this->never())->method('warn');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Original failure');
        $factory->withEnvironmentPatFallback(function (): never {
            throw new RuntimeException('Original failure');
        }, $output);
    }

    public static function noFallbackCases(): array
    {
        return [
            'no PAT' => [true, null],
            'blank PAT' => [true, '  '],
            'PAT only' => [false, 'environment-pat'],
        ];
    }

    private function appFactory(ClientInterface $http): AuthenticationFactory
    {
        return new AuthenticationFactory(
            new BuiltinConfigProvider([
                'github.app.id' => '123',
                'github.app.installation_id' => '456',
                'github.app.private_key_path' => __DIR__ . '/test-private-key.pem',
            ]),
            $http,
            new RequestFactory(),
            new StreamFactory()
        );
    }
}
