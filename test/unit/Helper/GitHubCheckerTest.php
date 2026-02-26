<?php

declare(strict_types=1);

namespace Horde\Components\Test\Unit\Helper;

use Horde\Components\Helper\Git;
use Horde\Components\Helper\GitHubChecker;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(GitHubChecker::class)]
class GitHubCheckerTest extends TestCase
{
    private Git $git;
    private GitHubChecker $checker;

    protected function setUp(): void
    {
        $this->git = $this->createMock(Git::class);
        $this->checker = new GitHubChecker($this->git);
    }

    public function testIsOnGitHubReturnsTrueForGitHubHttpsUrl(): void
    {
        $this->git->method('hasRemotes')->willReturn(true);
        $this->git->method('getRemoteUrl')->willReturn('https://github.com/horde/components.git');

        $result = $this->checker->isOnGitHub('/path/to/repo');

        $this->assertTrue($result);
    }

    public function testIsOnGitHubReturnsTrueForGitHubSshUrl(): void
    {
        $this->git->method('hasRemotes')->willReturn(true);
        $this->git->method('getRemoteUrl')->willReturn('git@github.com:horde/components.git');

        $result = $this->checker->isOnGitHub('/path/to/repo');

        $this->assertTrue($result);
    }

    public function testIsOnGitHubReturnsFalseForNonGitHubUrl(): void
    {
        $this->git->method('hasRemotes')->willReturn(true);
        $this->git->method('getRemoteUrl')->willReturn('https://gitlab.com/horde/components.git');

        $result = $this->checker->isOnGitHub('/path/to/repo');

        $this->assertFalse($result);
    }

    public function testIsOnGitHubReturnsFalseWhenNoRemotes(): void
    {
        $this->git->method('hasRemotes')->willReturn(false);

        $result = $this->checker->isOnGitHub('/path/to/repo');

        $this->assertFalse($result);
    }

    public function testIsOnGitHubReturnsFalseWhenRemoteNotFound(): void
    {
        $this->git->method('hasRemotes')->willReturn(true);
        $this->git->method('getRemoteUrl')->willReturn(null);

        $result = $this->checker->isOnGitHub('/path/to/repo');

        $this->assertFalse($result);
    }

    public function testGetRemoteUrlReturnsHttpsUrl(): void
    {
        $this->git->method('getRemoteUrl')->willReturn('https://github.com/horde/components.git');

        $url = $this->checker->getRemoteUrl('/path/to/repo');

        $this->assertSame('https://github.com/horde/components.git', $url);
    }

    public function testGetRemoteUrlReturnsSshUrl(): void
    {
        $this->git->method('getRemoteUrl')->willReturn('git@github.com:horde/components.git');

        $url = $this->checker->getRemoteUrl('/path/to/repo');

        $this->assertSame('git@github.com:horde/components.git', $url);
    }

    public function testGetRemoteUrlReturnsNullOnError(): void
    {
        $this->git->method('getRemoteUrl')->willReturn(null);

        $url = $this->checker->getRemoteUrl('/path/to/repo');

        $this->assertNull($url);
    }

    public function testIsGitHubUrlRecognizesHttpsUrl(): void
    {
        $result = $this->checker->isGitHubUrl('https://github.com/horde/components.git');
        $this->assertTrue($result);
    }

    public function testIsGitHubUrlRecognizesHttpsUrlWithoutGit(): void
    {
        $result = $this->checker->isGitHubUrl('https://github.com/horde/components');
        $this->assertTrue($result);
    }

    public function testIsGitHubUrlRecognizesSshUrl(): void
    {
        $result = $this->checker->isGitHubUrl('git@github.com:horde/components.git');
        $this->assertTrue($result);
    }

    public function testIsGitHubUrlRecognizesGitProtocol(): void
    {
        $result = $this->checker->isGitHubUrl('git://github.com/horde/components.git');
        $this->assertTrue($result);
    }

    public function testIsGitHubUrlIsCaseInsensitive(): void
    {
        $result = $this->checker->isGitHubUrl('HTTPS://GITHUB.COM/Horde/Components.git');
        $this->assertTrue($result);
    }

    public function testIsGitHubUrlRejectsBitbucket(): void
    {
        $result = $this->checker->isGitHubUrl('https://bitbucket.org/horde/components.git');
        $this->assertFalse($result);
    }

    public function testIsGitHubUrlRejectsGitLab(): void
    {
        $result = $this->checker->isGitHubUrl('https://gitlab.com/horde/components.git');
        $this->assertFalse($result);
    }

    public function testParseGitHubUrlExtractsOwnerAndRepoFromHttps(): void
    {
        $result = $this->checker->parseGitHubUrl('https://github.com/horde/components.git');

        $this->assertSame(['owner' => 'horde', 'repo' => 'components'], $result);
    }

    public function testParseGitHubUrlExtractsOwnerAndRepoFromSsh(): void
    {
        $result = $this->checker->parseGitHubUrl('git@github.com:octocat/Hello-World.git');

        $this->assertSame(['owner' => 'octocat', 'repo' => 'Hello-World'], $result);
    }

    public function testParseGitHubUrlHandlesUrlWithoutGitExtension(): void
    {
        $result = $this->checker->parseGitHubUrl('https://github.com/microsoft/vscode');

        $this->assertSame(['owner' => 'microsoft', 'repo' => 'vscode'], $result);
    }

    public function testParseGitHubUrlReturnsNullForNonGitHubUrl(): void
    {
        $result = $this->checker->parseGitHubUrl('https://gitlab.com/horde/components.git');

        $this->assertNull($result);
    }

    public function testGetGitHubRepositoryReturnsOwnerSlashRepo(): void
    {
        $this->git->method('getRemoteUrl')->willReturn('https://github.com/horde/components.git');

        $result = $this->checker->getGitHubRepository('/path/to/repo');

        $this->assertSame('horde/components', $result);
    }

    public function testGetGitHubRepositoryReturnsNullForNonGitHubUrl(): void
    {
        $this->git->method('getRemoteUrl')->willReturn('https://gitlab.com/horde/components.git');

        $result = $this->checker->getGitHubRepository('/path/to/repo');

        $this->assertNull($result);
    }

    public function testGetGitHubRepositoryReturnsNullWhenRemoteNotFound(): void
    {
        $this->git->method('getRemoteUrl')->willReturn(null);

        $result = $this->checker->getGitHubRepository('/path/to/repo');

        $this->assertNull($result);
    }

    public function testIsOnGitHubWithCustomRemote(): void
    {
        $this->git->method('hasRemotes')->willReturn(true);
        $this->git->method('getRemoteUrl')->willReturn('https://github.com/horde/components.git');

        $result = $this->checker->isOnGitHub('/path/to/repo', 'upstream');

        $this->assertTrue($result);
    }

    public function testGetGitHubRepositoryWithHyphenatedNames(): void
    {
        $this->git->method('getRemoteUrl')->willReturn('https://github.com/my-org/my-repo-name.git');

        $result = $this->checker->getGitHubRepository('/path/to/repo');

        $this->assertSame('my-org/my-repo-name', $result);
    }
}
