<?php

/**
 * Copyright 2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 */

declare(strict_types=1);

namespace Horde\Components\Test\Unit\Module;

use Horde\Components\Auth\AuthenticationFactory;
use Horde\Components\Component\Task\SystemCallResult;
use Horde\Components\ConfigProvider\BuiltinConfigProvider;
use Horde\Components\ConfigProvider\ConfigProviderFactory;
use Horde\Components\ConfigProvider\EffectiveConfigProvider;
use Horde\Components\Helper\Git as GitHelper;
use Horde\Components\Helper\Shell;
use Horde\Components\Module\Git;
use Horde\Components\Output;
use Horde\Components\RuntimeContext\GitCheckoutDirectory;
use Horde\GithubApiClient\GithubApiClient;
use Horde\GithubApiClient\GithubApiConfig;
use Horde\GithubApiClient\GithubRepositoryList;
use Horde\Injector\Injector;
use Horde\Injector\TopLevel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(Git::class)]
class GitAuthenticationTest extends TestCase
{
    #[DataProvider('cloneOptions')]
    public function testCloneOrgRetriesCatalogWithPat(array $options): void
    {
        $directory = sys_get_temp_dir() . '/components-clone-auth-' . bin2hex(random_bytes(6));
        mkdir($directory);
        $previousPat = getenv('GITHUB_TOKEN');
        putenv('GITHUB_TOKEN=environment-pat');
        $output = $this->createMock(Output::class);
        $output->expects($this->once())->method('warn')->with($this->stringContains('retrying once'));
        $authentication = $this->createPartialMock(
            AuthenticationFactory::class,
            ['hasGitHubAppAuth', 'createConfig', 'createClient']
        );
        $authentication->method('hasGitHubAppAuth')->willReturn(true);
        $authentication->method('createConfig')->willReturn(new GithubApiConfig(accessToken: 'app-token'));
        $appClient = $this->createMock(GithubApiClient::class);
        $appClient->expects($this->once())->method('listRepositoriesInOrganization')
            ->willThrowException(new RuntimeException('App permission denied'));
        $patClient = $this->createMock(GithubApiClient::class);
        $patClient->expects($this->once())->method('listRepositoriesInOrganization')
            ->willReturn(new GithubRepositoryList([]));
        $tokens = [];
        $authentication->expects($this->exactly(2))->method('createClient')
            ->willReturnCallback(function (GithubApiConfig $config) use ($appClient, $patClient, &$tokens) {
                $tokens[] = $config->accessToken;
                return $config->accessToken === 'app-token' ? $appClient : $patClient;
            });
        $git = $this->createMock(GitHelper::class);
        $git->expects($this->once())->method('withGitHubAuthentication')
            ->with($authentication, $output)->willReturnSelf();
        $injector = $this->injector($directory, $authentication, $output, $git);

        try {
            $this->assertTrue((new Git($injector))->handle($options, ['github-clone-org']));
            $this->assertSame(['app-token', 'environment-pat'], $tokens);
        } finally {
            putenv($previousPat === false ? 'GITHUB_TOKEN' : 'GITHUB_TOKEN=' . $previousPat);
            if (is_file($directory . '/repos.json')) {
                unlink($directory . '/repos.json');
            }
            rmdir($directory);
        }
    }

    public static function cloneOptions(): array
    {
        return [
            'clone' => [[]],
            'detect differences' => [['detect_differences' => true]],
        ];
    }

    public function testGitSyncUsesAuthenticatedFetchAndStopsOnFailure(): void
    {
        $directory = sys_get_temp_dir() . '/components-sync-auth-' . bin2hex(random_bytes(6));
        $repo = $directory . '/horde/components';
        mkdir($repo . '/.git', 0o700, true);
        $authentication = $this->createStub(AuthenticationFactory::class);
        $output = $this->createMock(Output::class);
        $output->expects($this->once())->method('error')->with($this->stringContains('Fetch failed:'));
        $git = $this->createMock(GitHelper::class);
        $git->expects($this->once())->method('withGitHubAuthentication')
            ->with($authentication, $output)->willReturnSelf();
        $git->expects($this->once())->method('fetch')->with($repo, true)
            ->willReturn(new SystemCallResult([], 128));
        $shell = $this->createMock(Shell::class);
        $shell->expects($this->never())->method('shellExec');
        $injector = $this->injector($directory, $authentication, $output, $git);
        $injector->setInstance(Shell::class, $shell);

        try {
            $this->assertTrue((new Git($injector))->handle(['component' => 'components'], ['git', 'sync']));
        } finally {
            rmdir($repo . '/.git');
            rmdir($repo);
            rmdir($directory . '/horde');
            rmdir($directory);
        }
    }

    private function injector(
        string $directory,
        AuthenticationFactory $authentication,
        Output $output,
        GitHelper $git
    ): Injector {
        $configFactory = $this->createStub(ConfigProviderFactory::class);
        $configFactory->method('createDefault')->willReturn(
            new EffectiveConfigProvider(new BuiltinConfigProvider(['checkout.dir' => $directory]))
        );
        $injector = new Injector(new TopLevel());
        $injector->setInstance(ConfigProviderFactory::class, $configFactory);
        $injector->setInstance(AuthenticationFactory::class, $authentication);
        $injector->setInstance(Output::class, $output);
        $injector->setInstance(GitHelper::class, $git);
        $injector->setInstance(GitCheckoutDirectory::class, new GitCheckoutDirectory($directory));
        return $injector;
    }
}
