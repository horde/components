<?php

declare(strict_types=1);

/**
 * Copyright 2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

namespace Horde\Components\Test\Unit\Release\Branches;

use Horde\Components\Helper\Git as GitHelper;
use Horde\Components\Helper\GitHubChecker;
use Horde\Components\Output;
use Horde\Components\Release\Branches\EnsureCommand;
use Horde\GithubApiClient\GithubApiConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the branch-ensure git decision tree and auth-less paths.
 *
 * The GitHub API write path is covered at the library level
 * (GithubApiClientRepositoryTest); here we drive the pure branch logic with
 * mocked git + checker and an unauthenticated config so no network is used.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
#[CoversClass(EnsureCommand::class)]
class EnsureCommandTest extends TestCase
{
    private function command(GitHelper $git, GitHubChecker $checker, string $token = ''): EnsureCommand
    {
        return new EnsureCommand(
            $this->createStub(Output::class),
            $git,
            $checker,
            new GithubApiConfig(accessToken: $token)
        );
    }

    private function checkerNotGitHub(): GitHubChecker
    {
        $checker = $this->createStub(GitHubChecker::class);
        $checker->method('isOnGitHub')->willReturn(false);
        return $checker;
    }

    public function testCreatesLocalFromDefaultWhenBothMissing(): void
    {
        $git = $this->createMock(GitHelper::class);
        $git->method('hasRemotes')->willReturn(true);
        $git->method('localBranchExists')->willReturn(false);
        // Not on remote either (both existence probes false), then remote
        // push probe also false so a push is attempted.
        $git->method('remoteBranchExists')->willReturn(false);
        $git->method('getDefaultBranch')->willReturn('main');

        // Expect a branch created from the default branch, then a push.
        $git->expects($this->once())->method('branchFromLocal')
            ->with($this->anything(), 'FRAMEWORK_6_0', 'main');
        $git->expects($this->once())->method('push')
            ->with($this->anything(), 'origin', 'FRAMEWORK_6_0');

        $ok = $this->command($git, $this->checkerNotGitHub())
            ->execute('/x', $this->rolesFile(), false);

        $this->assertTrue($ok);
    }

    public function testCreatesLocalTrackingRemoteWhenOnlyLocalMissing(): void
    {
        $git = $this->createMock(GitHelper::class);
        $git->method('hasRemotes')->willReturn(true);
        $git->method('localBranchExists')->willReturn(false);
        $git->method('remoteBranchExists')->willReturn(true);

        $git->expects($this->once())->method('createRemoteTrackingBranch')
            ->with($this->anything(), 'FRAMEWORK_6_0');
        // Remote already exists, so no branchFromLocal and no push.
        $git->expects($this->never())->method('branchFromLocal');
        $git->expects($this->never())->method('push');

        $ok = $this->command($git, $this->checkerNotGitHub())
            ->execute('/x', $this->rolesFile(), false);

        $this->assertTrue($ok);
    }

    public function testPretendMakesNoMutations(): void
    {
        $git = $this->createMock(GitHelper::class);
        $git->method('hasRemotes')->willReturn(true);
        $git->method('localBranchExists')->willReturn(false);
        $git->method('remoteBranchExists')->willReturn(false);
        $git->method('getDefaultBranch')->willReturn('main');

        $git->expects($this->never())->method('branchFromLocal');
        $git->expects($this->never())->method('createRemoteTrackingBranch');
        $git->expects($this->never())->method('push');

        $ok = $this->command($git, $this->checkerNotGitHub())
            ->execute('/x', $this->rolesFile(), true);

        $this->assertTrue($ok);
    }

    public function testNoAuthDoesGitWorkAndSkipsDefaultBranch(): void
    {
        $git = $this->createStub(GitHelper::class);
        $git->method('hasRemotes')->willReturn(true);
        $git->method('localBranchExists')->willReturn(true);
        $git->method('remoteBranchExists')->willReturn(true);

        // GitHub remote, but no token -> must not attempt the API, must
        // still succeed (git side already consistent).
        $checker = $this->createStub(GitHubChecker::class);
        $checker->method('isOnGitHub')->willReturn(true);
        $checker->method('getRemoteUrl')->willReturn('https://github.com/horde/Victim.git');
        $checker->method('parseGitHubUrl')->willReturn(['owner' => 'horde', 'repo' => 'Victim']);

        $ok = $this->command($git, $checker, '')->execute('/x', $this->rolesFile(), false);

        $this->assertTrue($ok);
    }

    public function testEmptyReleaseBranchFails(): void
    {
        $git = $this->createStub(GitHelper::class);
        $git->method('hasRemotes')->willReturn(false);

        $ok = $this->command($git, $this->checkerNotGitHub())
            ->execute('/x', $this->emptyRolesFile(), false);

        $this->assertFalse($ok);
    }

    public function testFromScratchForwardStepCommitsOpeningVersion(): void
    {
        // A real component dir with a .horde.yml at 2.4.38 stable, a roles
        // file declaring FRAMEWORK_6_1 as release, and git reporting the
        // branch missing locally+remotely with FRAMEWORK_6_0 as the default
        // branch. The from-scratch arm must create FRAMEWORK_6_1 and open it
        // at 2.5.0-alpha with a commit.
        $componentDir = $this->componentDir('2.4.38', 'stable');
        $roles = $this->rolesFileFor('FRAMEWORK_6_1');

        $git = $this->createMock(GitHelper::class);
        $git->method('hasRemotes')->willReturn(true);
        $git->method('localBranchExists')->willReturn(false);
        $git->method('remoteBranchExists')->willReturn(false);
        $git->method('getDefaultBranch')->willReturn('FRAMEWORK_6_0');

        $git->expects($this->once())->method('branchFromLocal')
            ->with($this->anything(), 'FRAMEWORK_6_1', 'FRAMEWORK_6_0');
        $git->expects($this->once())->method('checkoutBranch')
            ->with($this->anything(), 'FRAMEWORK_6_1');
        $git->expects($this->once())->method('add')->with('.horde.yml');
        $git->expects($this->once())->method('commit')
            ->with($componentDir, 'chore(release): open FRAMEWORK_6_1 at 2.5.0-alpha');

        $ok = $this->command($git, $this->checkerNotGitHub())
            ->execute($componentDir, $roles, false);

        $this->assertTrue($ok);
        // .horde.yml was rewritten to the opening version.
        $yml = file_get_contents($componentDir . '/.horde.yml');
        $this->assertStringContainsString('2.5.0-alpha', $yml);
    }

    public function testFromScratchPrefersCurrentFrameworkBranchOverStaleDefault(): void
    {
        // The checkout is on FRAMEWORK_6_0 but local origin/HEAD is a stale
        // 'main' (a real Victim quirk). The source must be the current
        // FRAMEWORK branch, not the stale default, so the generation delta
        // (6_0 -> 6_1) is honored and the opening version is computed.
        $componentDir = $this->componentDir('1.0.0', 'stable');
        $roles = $this->rolesFileFor('FRAMEWORK_6_1');

        $git = $this->createMock(GitHelper::class);
        $git->method('hasRemotes')->willReturn(true);
        $git->method('localBranchExists')->willReturn(false);
        $git->method('remoteBranchExists')->willReturn(false);
        $git->method('getCurrentBranch')->willReturn('FRAMEWORK_6_0');
        $git->method('getDefaultBranch')->willReturn('main'); // stale, must be ignored

        $git->expects($this->once())->method('branchFromLocal')
            ->with($this->anything(), 'FRAMEWORK_6_1', 'FRAMEWORK_6_0');
        $git->expects($this->once())->method('commit')
            ->with($componentDir, 'chore(release): open FRAMEWORK_6_1 at 1.1.0-alpha');

        $ok = $this->command($git, $this->checkerNotGitHub())
            ->execute($componentDir, $roles, false);

        $this->assertTrue($ok);
    }

    public function testFromScratchNonForwardStepDoesNotCommit(): void
    {
        // Default branch FRAMEWORK_6_1, release FRAMEWORK_6_0: backward, so
        // the branch is still created but no opening commit is made.
        $componentDir = $this->componentDir('2.4.38', 'stable');
        $roles = $this->rolesFileFor('FRAMEWORK_6_0');

        $git = $this->createMock(GitHelper::class);
        $git->method('hasRemotes')->willReturn(true);
        $git->method('localBranchExists')->willReturn(false);
        $git->method('remoteBranchExists')->willReturn(false);
        $git->method('getDefaultBranch')->willReturn('FRAMEWORK_6_1');

        $git->expects($this->once())->method('branchFromLocal');
        $git->expects($this->never())->method('add');
        $git->expects($this->never())->method('commit');

        $ok = $this->command($git, $this->checkerNotGitHub())
            ->execute($componentDir, $roles, false);

        $this->assertTrue($ok);
    }

    public function testFromScratchForwardStepPretendMakesNoCommit(): void
    {
        $componentDir = $this->componentDir('2.4.38', 'stable');
        $roles = $this->rolesFileFor('FRAMEWORK_6_1');
        $original = file_get_contents($componentDir . '/.horde.yml');

        $git = $this->createMock(GitHelper::class);
        $git->method('hasRemotes')->willReturn(true);
        $git->method('localBranchExists')->willReturn(false);
        $git->method('remoteBranchExists')->willReturn(false);
        $git->method('getDefaultBranch')->willReturn('FRAMEWORK_6_0');

        $git->expects($this->never())->method('add');
        $git->expects($this->never())->method('commit');
        $git->expects($this->never())->method('checkoutBranch');

        $ok = $this->command($git, $this->checkerNotGitHub())
            ->execute($componentDir, $roles, true);

        $this->assertTrue($ok);
        // .horde.yml untouched in pretend mode.
        $this->assertSame($original, file_get_contents($componentDir . '/.horde.yml'));
    }

    private function componentDir(string $version, string $stability): string
    {
        $dir = sys_get_temp_dir() . '/ensure-cmp-' . bin2hex(random_bytes(6));
        mkdir($dir, 0o755, true);
        file_put_contents($dir . '/.horde.yml', <<<YML
            ---
            id: sbx
            name: Sbx
            full: Sandbox
            description: Sandbox
            list: horde
            type: library
            authors:
              - { name: T, user: t, email: t@t, active: true, role: lead }
            version:
              release: {$version}
              api: {$version}
            state:
              release: {$stability}
              api: {$stability}
            license: { identifier: LGPL-2.1-only, uri: x }
            YML);
        $this->cleanupDirs[] = $dir;
        return $dir;
    }

    private function rolesFileFor(string $release): string
    {
        $path = tempnam(sys_get_temp_dir(), 'roles-') . '.yml';
        file_put_contents($path, "release: {$release}\nsupported: []\nunstable: null\n");
        $this->cleanup[] = $path;
        return $path;
    }

    private function rolesFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'roles-') . '.yml';
        file_put_contents($path, "release: FRAMEWORK_6_0\nsupported: []\nunstable: null\n");
        $this->cleanup[] = $path;
        return $path;
    }

    private function emptyRolesFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'roles-') . '.yml';
        file_put_contents($path, "supported: []\n");
        $this->cleanup[] = $path;
        return $path;
    }

    /** @var list<string> */
    private array $cleanup = [];

    /** @var list<string> */
    private array $cleanupDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $path) {
            @unlink($path);
        }
        foreach ($this->cleanupDirs as $dir) {
            @unlink($dir . '/.horde.yml');
            @unlink($dir . '/composer.json');
            @rmdir($dir);
        }
        $this->cleanup = [];
        $this->cleanupDirs = [];
    }
}
