<?php

declare(strict_types=1);

namespace Horde\Components\Test\Auth;

use Horde\Components\Auth\GitHubAppConfig;
use InvalidArgumentException;
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
#[CoversClass(GitHubAppConfig::class)]
class GitHubAppConfigTest extends TestCase
{
    public function testConstructorWithValidValues(): void
    {
        $config = new GitHubAppConfig(
            appId: 123456,
            installationId: 789012,
            privateKeyPath: '/path/to/key.pem'
        );

        $this->assertSame(123456, $config->appId);
        $this->assertSame(789012, $config->installationId);
        $this->assertSame('/path/to/key.pem', $config->privateKeyPath);
    }

    public function testConstructorThrowsOnZeroAppId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('GitHub App ID must be positive');

        new GitHubAppConfig(
            appId: 0,
            installationId: 123,
            privateKeyPath: '/path/to/key.pem'
        );
    }

    public function testConstructorThrowsOnNegativeAppId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('GitHub App ID must be positive');

        new GitHubAppConfig(
            appId: -1,
            installationId: 123,
            privateKeyPath: '/path/to/key.pem'
        );
    }

    public function testConstructorThrowsOnZeroInstallationId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Installation ID must be positive');

        new GitHubAppConfig(
            appId: 123,
            installationId: 0,
            privateKeyPath: '/path/to/key.pem'
        );
    }

    public function testConstructorThrowsOnNegativeInstallationId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Installation ID must be positive');

        new GitHubAppConfig(
            appId: 123,
            installationId: -1,
            privateKeyPath: '/path/to/key.pem'
        );
    }

    public function testConstructorThrowsOnEmptyPrivateKeyPath(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Private key path cannot be empty');

        new GitHubAppConfig(
            appId: 123,
            installationId: 456,
            privateKeyPath: ''
        );
    }

    public function testConstructorThrowsOnWhitespacePrivateKeyPath(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Private key path cannot be empty');

        new GitHubAppConfig(
            appId: 123,
            installationId: 456,
            privateKeyPath: "   \t   "
        );
    }

    public function testFromArrayWithValidConfig(): void
    {
        $array = [
            'app_id' => 111222,
            'installation_id' => 333444,
            'private_key_path' => '/var/keys/app.pem',
        ];

        $config = GitHubAppConfig::fromArray($array);

        $this->assertSame(111222, $config->appId);
        $this->assertSame(333444, $config->installationId);
        $this->assertSame('/var/keys/app.pem', $config->privateKeyPath);
    }

    public function testFromArrayThrowsOnMissingAppId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing required config key: app_id');

        GitHubAppConfig::fromArray([
            'installation_id' => 123,
            'private_key_path' => '/path/to/key.pem',
        ]);
    }

    public function testFromArrayThrowsOnMissingInstallationId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing required config key: installation_id');

        GitHubAppConfig::fromArray([
            'app_id' => 123,
            'private_key_path' => '/path/to/key.pem',
        ]);
    }

    public function testFromArrayThrowsOnMissingPrivateKeyPath(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing required config key: private_key_path');

        GitHubAppConfig::fromArray([
            'app_id' => 123,
            'installation_id' => 456,
        ]);
    }

    public function testFromEnvironmentWithValidVariables(): void
    {
        putenv('GITHUB_APP_ID=555666');
        putenv('GITHUB_APP_INSTALLATION_ID=777888');
        putenv('GITHUB_APP_PRIVATE_KEY_PATH=/home/user/.ssh/github-app.pem');

        try {
            $config = GitHubAppConfig::fromEnvironment();

            $this->assertSame(555666, $config->appId);
            $this->assertSame(777888, $config->installationId);
            $this->assertSame('/home/user/.ssh/github-app.pem', $config->privateKeyPath);
        } finally {
            putenv('GITHUB_APP_ID');
            putenv('GITHUB_APP_INSTALLATION_ID');
            putenv('GITHUB_APP_PRIVATE_KEY_PATH');
        }
    }

    public function testFromEnvironmentThrowsOnMissingAppId(): void
    {
        putenv('GITHUB_APP_ID');
        putenv('GITHUB_APP_INSTALLATION_ID=123');
        putenv('GITHUB_APP_PRIVATE_KEY_PATH=/path/key.pem');

        try {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('Missing environment variable: GITHUB_APP_ID');

            GitHubAppConfig::fromEnvironment();
        } finally {
            putenv('GITHUB_APP_INSTALLATION_ID');
            putenv('GITHUB_APP_PRIVATE_KEY_PATH');
        }
    }

    public function testFromEnvironmentThrowsOnMissingInstallationId(): void
    {
        putenv('GITHUB_APP_ID=123');
        putenv('GITHUB_APP_INSTALLATION_ID');
        putenv('GITHUB_APP_PRIVATE_KEY_PATH=/path/key.pem');

        try {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('Missing environment variable: GITHUB_APP_INSTALLATION_ID');

            GitHubAppConfig::fromEnvironment();
        } finally {
            putenv('GITHUB_APP_ID');
            putenv('GITHUB_APP_PRIVATE_KEY_PATH');
        }
    }

    public function testFromEnvironmentThrowsOnMissingPrivateKeyPath(): void
    {
        putenv('GITHUB_APP_ID=123');
        putenv('GITHUB_APP_INSTALLATION_ID=456');
        putenv('GITHUB_APP_PRIVATE_KEY_PATH');

        try {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('Missing environment variable: GITHUB_APP_PRIVATE_KEY_PATH');

            GitHubAppConfig::fromEnvironment();
        } finally {
            putenv('GITHUB_APP_ID');
            putenv('GITHUB_APP_INSTALLATION_ID');
        }
    }

    public function testPropertiesAreReadonly(): void
    {
        $config = new GitHubAppConfig(123, 456, '/path/key.pem');

        $reflection = new \ReflectionClass($config);

        foreach ($reflection->getProperties() as $property) {
            $this->assertTrue(
                $property->isReadOnly(),
                "Property {$property->getName()} should be readonly"
            );
        }
    }
}
