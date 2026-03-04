<?php

declare(strict_types=1);

namespace Horde\Components\Test\Runner;

use Horde\Components\Runner\Status;
use Horde\Components\ConfigProvider\EffectiveConfigProvider;
use Horde\Components\Auth\AuthenticationFactory;
use Horde\Components\Auth\GitHubAppAuthenticationStrategy;
use Horde\Components\Auth\PatAuthenticationStrategy;
use Horde\Components\Output;
use Horde\Components\Composer\InstallationDirectory;
use Horde\Components\RuntimeContext\GitCheckoutDirectory;
use Horde\GithubApiClient\GithubApiClient;
use Horde\GithubApiClient\GithubApp;
use Horde\GithubApiClient\GithubUser;
use Horde\GithubApiClient\GithubInstallationList;
use Horde\GithubApiClient\RateLimit;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;

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
#[CoversClass(Status::class)]
class StatusTest extends TestCase
{
    private function createMockConfig(): EffectiveConfigProvider
    {
        $config = $this->createMock(EffectiveConfigProvider::class);
        $config->method('hasSetting')->willReturn(false);
        $config->method('getSetting')->willReturn('https://github.com/horde/');
        return $config;
    }

    private function createMockOutput(): Output
    {
        return $this->createMock(Output::class);
    }

    private function createMockAuthFactory(): AuthenticationFactory
    {
        $authFactory = $this->createMock(AuthenticationFactory::class);
        $authFactory->method('hasAuth')->willReturn(false);
        return $authFactory;
    }

    private function createMockGitCheckoutDir(bool $exists = true, int $repoCount = 0): GitCheckoutDirectory
    {
        $dir = $this->createMock(GitCheckoutDirectory::class);
        $dir->method('exists')->willReturn($exists);

        // Create mock iterator that is Countable
        $gitIterator = $this->createMock(\Horde\Components\RuntimeContext\GitDirectoryIterator::class);
        $gitIterator->method('count')->willReturn($repoCount);

        $hordeYmlIterator = $this->createMock(\Horde\Components\RuntimeContext\GitDirectoryIterator::class);
        $hordeYmlIterator->method('count')->willReturn($repoCount);

        $dir->method('getGitDirs')->willReturn($gitIterator);
        $dir->method('getHordeYmlDirs')->willReturn($hordeYmlIterator);

        return $dir;
    }

    private function createMockInstallDir(bool $exists = true, bool $hasComposer = true): InstallationDirectory
    {
        $dir = $this->createMock(InstallationDirectory::class);
        $dir->method('exists')->willReturn($exists);
        $dir->method('hasComposerJson')->willReturn($hasComposer);
        $dir->method('__toString')->willReturn('/path/to/install');
        return $dir;
    }

    public function testConstructorAcceptsParameters(): void
    {
        $status = new Status(
            ['status'],
            $this->createMockConfig(),
            '/path/to/config.php',
            $this->createMockOutput(),
            $this->createMockGitCheckoutDir(),
            $this->createMockInstallDir(),
            $this->createMockAuthFactory()
        );

        $this->assertInstanceOf(Status::class, $status);
    }

    public function testRunWithoutSubcommandShowsBasicStatus(): void
    {
        $output = $this->createMockOutput();

        // Expect basic status output
        $output->expects($this->once())
            ->method('plain')
            ->with($this->stringContains('horde-components status'));

        $output->expects($this->atLeastOnce())
            ->method('info');

        $status = new Status(
            ['status'],
            $this->createMockConfig(),
            '/path/to/config.php',
            $output,
            $this->createMockGitCheckoutDir(true, 5),
            $this->createMockInstallDir(),
            $this->createMockAuthFactory()
        );

        $status->run();
    }

    public function testRunWithGitHubAppVerifySubcommand(): void
    {
        $output = $this->createMockOutput();
        $authFactory = $this->createMock(AuthenticationFactory::class);

        // Configure for GitHub App auth
        $authFactory->method('hasGitHubAppAuth')->willReturn(true);

        // Expect verification header
        $output->expects($this->exactly(2))
            ->method('plain')
            ->with($this->logicalOr(
                $this->stringContains('GitHub App Authentication Verification'),
                $this->stringContains('===')
            ));

        $status = new Status(
            ['status', 'github-app-verify'],
            $this->createMockConfig(),
            '/path/to/config.php',
            $output,
            $this->createMockGitCheckoutDir(),
            $this->createMockInstallDir(),
            $authFactory
        );

        $status->run();
    }

    public function testGitHubAppVerifyWithNoAuthConfigured(): void
    {
        $output = $this->createMockOutput();
        $authFactory = $this->createMock(AuthenticationFactory::class);

        $authFactory->method('hasGitHubAppAuth')->willReturn(false);

        // Expect warning about no configuration
        $output->expects($this->atLeastOnce())
            ->method('warn')
            ->with($this->stringContains('No GitHub App authentication configured'));

        $output->expects($this->atLeastOnce())
            ->method('help');

        $status = new Status(
            ['status', 'github-app-verify'],
            $this->createMockConfig(),
            '/path/to/config.php',
            $output,
            $this->createMockGitCheckoutDir(),
            $this->createMockInstallDir(),
            $authFactory
        );

        $status->run();
    }

    public function testGitHubAppVerifyWithSuccessfulAuthentication(): void
    {
        $output = $this->createMockOutput();
        $authFactory = $this->createMock(AuthenticationFactory::class);
        $strategy = $this->createMock(GitHubAppAuthenticationStrategy::class);
        $apiClient = $this->createMock(GithubApiClient::class);
        $jwtClient = $this->createMock(GithubApiClient::class);

        $authFactory->method('hasGitHubAppAuth')->willReturn(true);
        $authFactory->method('create')->willReturn($strategy);

        $strategy->method('authenticate')->willReturn($apiClient);
        $strategy->method('getJwtAuthenticatedClient')->willReturn($jwtClient);

        // Mock GitHub App info - create actual instance with readonly properties
        $owner = new GithubUser(
            login: 'test-org',
            id: 789,
            avatarUrl: 'https://example.com/avatar.png',
            htmlUrl: 'https://github.com/test-org',
            type: 'Organization'
        );

        $app = new GithubApp(
            id: 123456,
            slug: 'test-app',
            name: 'Test App',
            owner: $owner,
            createdAt: '2026-01-01T00:00:00Z',
            updatedAt: '2026-01-02T00:00:00Z'
        );

        $jwtClient->method('getAuthenticatedApp')->willReturn($app);

        // Mock installations
        $installations = $this->createMock(GithubInstallationList::class);
        $jwtClient->method('listInstallations')->willReturn($installations);

        // Mock rate limit - create actual instance with readonly properties
        $rateLimit = new RateLimit(
            limit: 5000,
            remaining: 5000,
            reset: time() + 3600,
            used: 0
        );

        $apiClient->method('getRateLimit')->willReturn($rateLimit);

        // Expect success messages
        $output->expects($this->atLeastOnce())
            ->method('ok')
            ->with($this->logicalOr(
                $this->stringContains('GitHub App configuration detected'),
                $this->stringContains('Successfully authenticated'),
                $this->stringContains('verification completed successfully')
            ));

        $status = new Status(
            ['status', 'github-app-verify'],
            $this->createMockConfig(),
            '/path/to/config.php',
            $output,
            $this->createMockGitCheckoutDir(),
            $this->createMockInstallDir(),
            $authFactory
        );

        $status->run();
    }

    public function testGitHubAppVerifyWithAuthenticationFailure(): void
    {
        $output = $this->createMockOutput();
        $authFactory = $this->createMock(AuthenticationFactory::class);
        $strategy = $this->createMock(GitHubAppAuthenticationStrategy::class);

        $authFactory->method('hasGitHubAppAuth')->willReturn(true);
        $authFactory->method('create')->willReturn($strategy);

        // Simulate authentication failure
        $strategy->method('authenticate')
            ->willThrowException(new RuntimeException('Authentication failed: Invalid credentials'));

        // Expect failure warning
        $output->expects($this->atLeastOnce())
            ->method('warn')
            ->with($this->stringContains('Authentication failed'));

        // Expect troubleshooting help
        $output->expects($this->atLeastOnce())
            ->method('help');

        $status = new Status(
            ['status', 'github-app-verify'],
            $this->createMockConfig(),
            '/path/to/config.php',
            $output,
            $this->createMockGitCheckoutDir(),
            $this->createMockInstallDir(),
            $authFactory
        );

        $status->run();
    }

    public function testGitHubAppVerifyWithNonGitHubAppStrategy(): void
    {
        $output = $this->createMockOutput();
        $authFactory = $this->createMock(AuthenticationFactory::class);
        $strategy = $this->createMock(PatAuthenticationStrategy::class);

        $authFactory->method('hasGitHubAppAuth')->willReturn(true);
        $authFactory->method('create')->willReturn($strategy);
        $authFactory->method('getAuthMethod')->willReturn('Personal Access Token');

        // Expect warning about wrong auth method
        $output->expects($this->atLeastOnce())
            ->method('warn')
            ->with($this->stringContains('not using GitHub App'));

        $status = new Status(
            ['status', 'github-app-verify'],
            $this->createMockConfig(),
            '/path/to/config.php',
            $output,
            $this->createMockGitCheckoutDir(),
            $this->createMockInstallDir(),
            $authFactory
        );

        $status->run();
    }

    public function testRunReportsGitCheckoutDirStatus(): void
    {
        $output = $this->createMockOutput();
        $config = $this->createMockConfig();
        $config->method('hasSetting')->willReturn(true);
        $config->method('getSetting')->willReturn('/tmp/fake-config.php');

        // Expect OK message with repo count
        $output->expects($this->atLeastOnce())
            ->method('ok')
            ->with($this->logicalOr(
                $this->stringContains('10 repos checked out'),
                $this->anything()
            ));

        $status = new Status(
            ['status'],
            $config,
            '/tmp/fake-config.php',  // Use same path as config returns
            $output,
            $this->createMockGitCheckoutDir(true, 10),
            $this->createMockInstallDir(),
            $this->createMockAuthFactory()
        );

        $status->run();
    }

    public function testRunReportsMissingGitCheckoutDir(): void
    {
        $output = $this->createMockOutput();
        $config = $this->createMockConfig();
        $config->method('hasSetting')->willReturn(true);
        $config->method('getSetting')->willReturn('/tmp/fake-config.php');

        // Expect warning about missing dir
        $output->expects($this->atLeastOnce())
            ->method('warn')
            ->with($this->logicalOr(
                $this->stringContains('Git Tree dir does not exist'),
                $this->anything()
            ));

        $status = new Status(
            ['status'],
            $config,
            '/tmp/fake-config.php',
            $output,
            $this->createMockGitCheckoutDir(false),
            $this->createMockInstallDir(),
            $this->createMockAuthFactory()
        );

        $status->run();
    }

    public function testRunReportsMissingInstallDir(): void
    {
        $output = $this->createMockOutput();
        $config = $this->createMockConfig();
        $config->method('hasSetting')->willReturn(true);
        $config->method('getSetting')->willReturn('/tmp/fake-config.php');

        // Expect warning about missing install dir
        $output->expects($this->atLeastOnce())
            ->method('warn')
            ->with($this->logicalOr(
                $this->stringContains('Install dir does not exist'),
                $this->anything()
            ));

        // Expect help message with composer command
        $output->expects($this->atLeastOnce())
            ->method('help')
            ->with($this->logicalOr(
                $this->stringContains('composer create-project'),
                $this->anything()
            ));

        $status = new Status(
            ['status'],
            $config,
            '/tmp/fake-config.php',
            $output,
            $this->createMockGitCheckoutDir(),
            $this->createMockInstallDir(false),
            $this->createMockAuthFactory()
        );

        $status->run();
    }
}
