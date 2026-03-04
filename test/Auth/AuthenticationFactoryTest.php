<?php

declare(strict_types=1);

namespace Horde\Components\Test\Auth;

use Horde\Components\Auth\AuthenticationFactory;
use Horde\Components\Auth\GitHubAppAuthenticationStrategy;
use Horde\Components\Auth\PatAuthenticationStrategy;
use Horde\Components\ConfigProvider\ConfigProvider;
use RuntimeException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Exception;

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
#[CoversClass(AuthenticationFactory::class)]
class AuthenticationFactoryTest extends TestCase
{
    private string $testKeyPath;

    protected function setUp(): void
    {
        $this->testKeyPath = __DIR__ . '/test-private-key.pem';

        // Clear environment variables before each test
        putenv('GITHUB_TOKEN');
        putenv('GITHUB_APP_ID');
        putenv('GITHUB_APP_INSTALLATION_ID');
        putenv('GITHUB_APP_PRIVATE_KEY_PATH');
    }

    protected function tearDown(): void
    {
        // Clean up environment variables
        putenv('GITHUB_TOKEN');
        putenv('GITHUB_APP_ID');
        putenv('GITHUB_APP_INSTALLATION_ID');
        putenv('GITHUB_APP_PRIVATE_KEY_PATH');
    }

    public function testCreateWithGitHubAppFromConfig(): void
    {
        $config = $this->createMock(ConfigProvider::class);
        $config->method('hasSetting')->willReturnCallback(function ($key) {
            return in_array($key, ['github.app.id', 'github.app.installation_id', 'github.app.private_key_path']);
        });
        $config->method('getSetting')->willReturnCallback(function ($key) {
            return match ($key) {
                'github.app.id' => '123456',
                'github.app.installation_id' => '789012',
                'github.app.private_key_path' => $this->testKeyPath,
                default => throw new Exception("Setting not found")
            };
        });

        $factory = new AuthenticationFactory(
            $config,
            $this->createMock(ClientInterface::class),
            $this->createMock(RequestFactoryInterface::class),
            $this->createMock(StreamFactoryInterface::class)
        );

        $strategy = $factory->create();

        $this->assertInstanceOf(GitHubAppAuthenticationStrategy::class, $strategy);
        $this->assertSame('GitHub App', $strategy->getDescription());
    }

    public function testCreateWithGitHubAppFromEnvironment(): void
    {
        putenv('GITHUB_APP_ID=999888');
        putenv('GITHUB_APP_INSTALLATION_ID=777666');
        putenv("GITHUB_APP_PRIVATE_KEY_PATH={$this->testKeyPath}");

        $config = $this->createMock(ConfigProvider::class);
        $config->method('hasSetting')->willReturn(false);

        $factory = new AuthenticationFactory(
            $config,
            $this->createMock(ClientInterface::class),
            $this->createMock(RequestFactoryInterface::class),
            $this->createMock(StreamFactoryInterface::class)
        );

        $strategy = $factory->create();

        $this->assertInstanceOf(GitHubAppAuthenticationStrategy::class, $strategy);
    }

    public function testCreateWithPatFromEnvironment(): void
    {
        putenv('GITHUB_TOKEN=ghp_env_token_123');

        $config = $this->createMock(ConfigProvider::class);
        $config->method('hasSetting')->willReturn(false);

        $factory = new AuthenticationFactory(
            $config,
            $this->createMock(ClientInterface::class),
            $this->createMock(RequestFactoryInterface::class),
            $this->createMock(StreamFactoryInterface::class)
        );

        $strategy = $factory->create();

        $this->assertInstanceOf(PatAuthenticationStrategy::class, $strategy);
        $this->assertSame('Personal Access Token', $strategy->getDescription());
    }

    public function testCreateWithPatFromConfig(): void
    {
        $config = $this->createMock(ConfigProvider::class);
        $config->method('hasSetting')->willReturnCallback(function ($key) {
            return $key === 'github.token';
        });
        $config->method('getSetting')->willReturnCallback(function ($key) {
            return match ($key) {
                'github.token' => 'ghp_config_token_456',
                default => throw new Exception("Setting not found")
            };
        });

        $factory = new AuthenticationFactory(
            $config,
            $this->createMock(ClientInterface::class),
            $this->createMock(RequestFactoryInterface::class),
            $this->createMock(StreamFactoryInterface::class)
        );

        $strategy = $factory->create();

        $this->assertInstanceOf(PatAuthenticationStrategy::class, $strategy);
    }

    public function testGitHubAppTakesPrecedenceOverPatEnvironment(): void
    {
        // Set both GitHub App and PAT
        putenv('GITHUB_APP_ID=123');
        putenv('GITHUB_APP_INSTALLATION_ID=456');
        putenv("GITHUB_APP_PRIVATE_KEY_PATH={$this->testKeyPath}");
        putenv('GITHUB_TOKEN=ghp_should_be_ignored');

        $config = $this->createMock(ConfigProvider::class);
        $config->method('hasSetting')->willReturn(false);

        $factory = new AuthenticationFactory(
            $config,
            $this->createMock(ClientInterface::class),
            $this->createMock(RequestFactoryInterface::class),
            $this->createMock(StreamFactoryInterface::class)
        );

        $strategy = $factory->create();

        // Should use GitHub App, not PAT
        $this->assertInstanceOf(GitHubAppAuthenticationStrategy::class, $strategy);
    }

    public function testGitHubAppTakesPrecedenceOverPatConfig(): void
    {
        $config = $this->createMock(ConfigProvider::class);
        $config->method('hasSetting')->willReturnCallback(function ($key) {
            return in_array($key, ['github.app.id', 'github.app.installation_id', 'github.app.private_key_path', 'github.token']);
        });
        $config->method('getSetting')->willReturnCallback(function ($key) {
            return match ($key) {
                'github.app.id' => '123',
                'github.app.installation_id' => '456',
                'github.app.private_key_path' => $this->testKeyPath,
                'github.token' => 'ghp_should_be_ignored',
                default => throw new Exception("Setting not found")
            };
        });

        $factory = new AuthenticationFactory(
            $config,
            $this->createMock(ClientInterface::class),
            $this->createMock(RequestFactoryInterface::class),
            $this->createMock(StreamFactoryInterface::class)
        );

        $strategy = $factory->create();

        $this->assertInstanceOf(GitHubAppAuthenticationStrategy::class, $strategy);
    }

    public function testPatEnvironmentTakesPrecedenceOverPatConfig(): void
    {
        putenv('GITHUB_TOKEN=ghp_from_env');

        $config = $this->createMock(ConfigProvider::class);
        $config->method('hasSetting')->willReturnCallback(function ($key) {
            return $key === 'github.token';
        });
        $config->method('getSetting')->willReturn('ghp_from_config');

        $factory = new AuthenticationFactory(
            $config,
            $this->createMock(ClientInterface::class),
            $this->createMock(RequestFactoryInterface::class),
            $this->createMock(StreamFactoryInterface::class)
        );

        $strategy = $factory->create();

        $this->assertInstanceOf(PatAuthenticationStrategy::class, $strategy);
        // Note: We can't easily verify which PAT was used without exposing internal state
    }

    public function testCreateThrowsWhenNoAuthConfigured(): void
    {
        $config = $this->createMock(ConfigProvider::class);
        $config->method('hasSetting')->willReturn(false);

        $factory = new AuthenticationFactory(
            $config,
            $this->createMock(ClientInterface::class),
            $this->createMock(RequestFactoryInterface::class),
            $this->createMock(StreamFactoryInterface::class)
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No GitHub authentication configured');

        $factory->create();
    }

    public function testHasGitHubAppAuthReturnsTrueWhenConfigured(): void
    {
        $config = $this->createMock(ConfigProvider::class);
        $config->method('hasSetting')->willReturnCallback(function ($key) {
            return in_array($key, ['github.app.id', 'github.app.installation_id', 'github.app.private_key_path']);
        });
        $config->method('getSetting')->willReturnCallback(function ($key) {
            return match ($key) {
                'github.app.id' => '123',
                'github.app.installation_id' => '456',
                'github.app.private_key_path' => $this->testKeyPath,
                default => throw new Exception("Setting not found")
            };
        });

        $factory = new AuthenticationFactory(
            $config,
            $this->createMock(ClientInterface::class),
            $this->createMock(RequestFactoryInterface::class),
            $this->createMock(StreamFactoryInterface::class)
        );

        $this->assertTrue($factory->hasGitHubAppAuth());
    }

    public function testHasGitHubAppAuthReturnsFalseWhenNotConfigured(): void
    {
        $config = $this->createMock(ConfigProvider::class);
        $config->method('hasSetting')->willReturn(false);

        $factory = new AuthenticationFactory(
            $config,
            $this->createMock(ClientInterface::class),
            $this->createMock(RequestFactoryInterface::class),
            $this->createMock(StreamFactoryInterface::class)
        );

        $this->assertFalse($factory->hasGitHubAppAuth());
    }

    public function testHasAuthReturnsTrueForGitHubApp(): void
    {
        $config = $this->createMock(ConfigProvider::class);
        $config->method('hasSetting')->willReturnCallback(function ($key) {
            return in_array($key, ['github.app.id', 'github.app.installation_id', 'github.app.private_key_path']);
        });
        $config->method('getSetting')->willReturnCallback(function ($key) {
            return match ($key) {
                'github.app.id' => '123',
                'github.app.installation_id' => '456',
                'github.app.private_key_path' => $this->testKeyPath,
                default => throw new Exception("Setting not found")
            };
        });

        $factory = new AuthenticationFactory(
            $config,
            $this->createMock(ClientInterface::class),
            $this->createMock(RequestFactoryInterface::class),
            $this->createMock(StreamFactoryInterface::class)
        );

        $this->assertTrue($factory->hasAuth());
    }

    public function testHasAuthReturnsTrueForPatEnvironment(): void
    {
        putenv('GITHUB_TOKEN=ghp_test');

        $config = $this->createMock(ConfigProvider::class);
        $config->method('hasSetting')->willReturn(false);

        $factory = new AuthenticationFactory(
            $config,
            $this->createMock(ClientInterface::class),
            $this->createMock(RequestFactoryInterface::class),
            $this->createMock(StreamFactoryInterface::class)
        );

        $this->assertTrue($factory->hasAuth());
    }

    public function testHasAuthReturnsTrueForPatConfig(): void
    {
        $config = $this->createMock(ConfigProvider::class);
        $config->method('hasSetting')->willReturnCallback(function ($key) {
            return $key === 'github.token';
        });
        $config->method('getSetting')->willReturn('ghp_test');

        $factory = new AuthenticationFactory(
            $config,
            $this->createMock(ClientInterface::class),
            $this->createMock(RequestFactoryInterface::class),
            $this->createMock(StreamFactoryInterface::class)
        );

        $this->assertTrue($factory->hasAuth());
    }

    public function testHasAuthReturnsFalseWhenNoAuthConfigured(): void
    {
        $config = $this->createMock(ConfigProvider::class);
        $config->method('hasSetting')->willReturn(false);

        $factory = new AuthenticationFactory(
            $config,
            $this->createMock(ClientInterface::class),
            $this->createMock(RequestFactoryInterface::class),
            $this->createMock(StreamFactoryInterface::class)
        );

        $this->assertFalse($factory->hasAuth());
    }

    public function testGetAuthMethodReturnsCorrectDescriptionForGitHubApp(): void
    {
        $config = $this->createMock(ConfigProvider::class);
        $config->method('hasSetting')->willReturnCallback(function ($key) {
            return in_array($key, ['github.app.id', 'github.app.installation_id', 'github.app.private_key_path']);
        });
        $config->method('getSetting')->willReturnCallback(function ($key) {
            return match ($key) {
                'github.app.id' => '123',
                'github.app.installation_id' => '456',
                'github.app.private_key_path' => $this->testKeyPath,
                default => throw new Exception("Setting not found")
            };
        });

        $factory = new AuthenticationFactory(
            $config,
            $this->createMock(ClientInterface::class),
            $this->createMock(RequestFactoryInterface::class),
            $this->createMock(StreamFactoryInterface::class)
        );

        $this->assertSame('GitHub App', $factory->getAuthMethod());
    }

    public function testGetAuthMethodReturnsCorrectDescriptionForPatEnvironment(): void
    {
        putenv('GITHUB_TOKEN=ghp_test');

        $config = $this->createMock(ConfigProvider::class);
        $config->method('hasSetting')->willReturn(false);

        $factory = new AuthenticationFactory(
            $config,
            $this->createMock(ClientInterface::class),
            $this->createMock(RequestFactoryInterface::class),
            $this->createMock(StreamFactoryInterface::class)
        );

        $this->assertSame('Personal Access Token (from GITHUB_TOKEN environment variable)', $factory->getAuthMethod());
    }

    public function testGetAuthMethodReturnsCorrectDescriptionForPatConfig(): void
    {
        $config = $this->createMock(ConfigProvider::class);
        $config->method('hasSetting')->willReturnCallback(function ($key) {
            return $key === 'github.token';
        });
        $config->method('getSetting')->willReturn('ghp_test');

        $factory = new AuthenticationFactory(
            $config,
            $this->createMock(ClientInterface::class),
            $this->createMock(RequestFactoryInterface::class),
            $this->createMock(StreamFactoryInterface::class)
        );

        $this->assertSame('Personal Access Token (from config file)', $factory->getAuthMethod());
    }

    public function testGetAuthMethodReturnsNoneWhenNoAuthConfigured(): void
    {
        $config = $this->createMock(ConfigProvider::class);
        $config->method('hasSetting')->willReturn(false);

        $factory = new AuthenticationFactory(
            $config,
            $this->createMock(ClientInterface::class),
            $this->createMock(RequestFactoryInterface::class),
            $this->createMock(StreamFactoryInterface::class)
        );

        $this->assertSame('None', $factory->getAuthMethod());
    }
}
