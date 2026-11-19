<?php

/**
 * Copyright 2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 */

declare(strict_types=1);

namespace Horde\Components\Test\Unit\Helper;

use Horde\Components\Auth\AuthenticationFactory;
use Horde\Components\Component\Task\SystemCallResult;
use Horde\Components\Helper\Git;
use Horde\Components\Helper\Shell;
use Horde\Components\Output;
use Horde\GithubApiClient\GithubApiConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

#[CoversClass(Git::class)]
class GitAuthenticationTest extends TestCase
{
    private string|false $originalPat;

    protected function setUp(): void
    {
        $this->originalPat = getenv('GITHUB_TOKEN');
        putenv('GITHUB_TOKEN=environment-pat');
    }

    protected function tearDown(): void
    {
        putenv($this->originalPat === false ? 'GITHUB_TOKEN' : 'GITHUB_TOKEN=' . $this->originalPat);
    }

    #[DataProvider('fetchResults')]
    public function testHttpsFetchUsesAppThenAtMostOnePatRetry(array $exitCodes): void
    {
        $authentication = $this->createPartialMock(AuthenticationFactory::class, ['hasGitHubAppAuth', 'createConfig']);
        $authentication->method('hasGitHubAppAuth')->willReturn(true);
        $authentication->expects($this->once())->method('createConfig')
            ->willReturn(new GithubApiConfig(accessToken: 'short-lived-app-token'));
        $output = $this->createMock(Output::class);
        $output->expects(count($exitCodes) === 1 ? $this->never() : $this->once())->method('warn');
        $shell = $this->createMock(Shell::class);
        $shell->method('isPretend')->willReturn(false);
        $shell->expects($this->once())->method('exec')
            ->with($this->stringContains('remote -v'), '/checkout')
            ->willReturn(new SystemCallResult(['origin https://github.com/horde/components.git (fetch)'], 0));
        $attempt = 0;
        $before = getenv('GIT_CONFIG_COUNT');
        $shell->expects($this->exactly(count($exitCodes)))->method('execWithEnvironment')
            ->willReturnCallback(function (string $command, array $environment, string $directory) use (&$attempt, $exitCodes) {
                $token = $attempt === 0 ? 'short-lived-app-token' : 'environment-pat';
                $this->assertStringContainsString('fetch --all --tags --force', $command);
                $this->assertStringNotContainsString($token, $command);
                $this->assertSame('/checkout', $directory);
                $this->assertStringContainsString("'http.https://github.com/.extraHeader='", $command);
                $this->assertStringContainsString(
                    '--config-env=\'http.https://github.com/.extraHeader=HORDE_COMPONENTS_GITHUB_AUTHORIZATION\'',
                    $command
                );
                $this->assertSame(
                    'Authorization: Basic ' . base64_encode('x-access-token:' . $token),
                    $environment['HORDE_COMPONENTS_GITHUB_AUTHORIZATION']
                );
                $this->assertStringContainsString("'credential.https://github.com.helper='", $command);
                return new SystemCallResult([], $exitCodes[$attempt++]);
            });
        $git = (new Git(shell: $shell))->withGitHubAuthentication($authentication, $output);

        if (end($exitCodes) !== 0) {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Git remote operation failed');
        }
        $result = $git->fetch('/checkout', force: true);

        $this->assertSame(0, $result->getReturnValue());
        $this->assertSame($before, getenv('GIT_CONFIG_COUNT'));
    }

    public static function fetchResults(): array
    {
        return [
            'App succeeds' => [[0]],
            'PAT retry succeeds' => [[128, 0]],
            'PAT retry fails' => [[128, 128]],
        ];
    }

    public function testSshFetchLeavesAuthenticationUnchanged(): void
    {
        $authentication = $this->createMock(AuthenticationFactory::class);
        $authentication->expects($this->never())->method('withEnvironmentPatFallback');
        $shell = $this->createMock(Shell::class);
        $shell->method('isPretend')->willReturn(false);
        $shell->expects($this->exactly(2))->method('exec')->willReturn(
            new SystemCallResult(['origin git@github.com:horde/components.git (fetch)'], 0),
            new SystemCallResult([], 0)
        );
        $shell->expects($this->never())->method('execWithEnvironment');
        $git = (new Git(shell: $shell))->withGitHubAuthentication($authentication, $this->createStub(Output::class));

        $this->assertSame(0, $git->fetch('/checkout')->getReturnValue());
    }

    public function testPretendDoesNotObtainAppToken(): void
    {
        $authentication = $this->createMock(AuthenticationFactory::class);
        $authentication->expects($this->never())->method('withEnvironmentPatFallback');
        $git = (new Git(shell: new Shell(pretend: true)))
            ->withGitHubAuthentication($authentication, $this->createStub(Output::class));

        $this->assertSame(0, $git->fetch('/checkout')->getReturnValue());
    }

    public function testCloneUsesTransientConfigWithoutChangingStoredRemote(): void
    {
        $directory = sys_get_temp_dir() . '/components-git-auth-' . bin2hex(random_bytes(6));
        mkdir($directory);
        $source = $directory . '/source';
        $target = $directory . '/checkout';
        $url = 'https://github.com/horde/auth-test.git';
        $token = 'short-lived-app-token';
        $realShell = new Shell();
        $authentication = $this->createPartialMock(AuthenticationFactory::class, ['hasGitHubAppAuth', 'createConfig']);
        $authentication->method('hasGitHubAppAuth')->willReturn(true);
        $authentication->expects($this->once())->method('createConfig')->willReturn(new GithubApiConfig(accessToken: $token));
        $output = $this->createMock(Output::class);
        $output->expects($this->never())->method('warn');
        $shell = $this->createMock(Shell::class);
        $shell->method('isPretend')->willReturn(false);
        $shell->expects($this->once())->method('execWithEnvironment')
            ->willReturnCallback(function (string $command, array $environment) use ($realShell, $source, $url) {
                $index = (int) (getenv('GIT_CONFIG_COUNT') ?: 0);
                $environment['GIT_CONFIG_KEY_' . $index] = 'url.' . $source . '.insteadOf';
                $environment['GIT_CONFIG_VALUE_' . $index] = $url;
                $environment['GIT_CONFIG_COUNT'] = (string) ($index + 1);
                $environment['GIT_ALLOW_PROTOCOL'] = 'file';
                return $realShell->execWithEnvironment($command, $environment);
            });
        $git = (new Git(shell: $shell))->withGitHubAuthentication($authentication, $output);
        $this->expectOutputRegex('/clone --branch .main./');

        try {
            $init = $realShell->exec('git init --quiet --initial-branch=main ' . escapeshellarg($source));
            $this->assertSame(0, $init->getReturnValue());
            $commit = $realShell->exec(
                'git -c user.name=Test -c user.email=test@example.invalid -c commit.gpgsign=false commit --quiet --allow-empty -m initial',
                $source
            );
            $this->assertSame(0, $commit->getReturnValue());

            $this->assertSame(0, $git->clone($url, $target, 'main')->getReturnValue());

            $config = file_get_contents($target . '/.git/config');
            $this->assertStringContainsString($url, $config);
            $this->assertStringNotContainsString($token, $config);
            $this->assertStringNotContainsString(base64_encode('x-access-token:' . $token), $config);
            $this->assertStringNotContainsString('extraheader', strtolower($config));
            $this->assertStringContainsString('[branch "main"]', $config);
        } finally {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $file) {
                if ($file->isDir()) {
                    rmdir($file->getPathname());
                } else {
                    unlink($file->getPathname());
                }
            }
            rmdir($directory);
        }
    }
}
