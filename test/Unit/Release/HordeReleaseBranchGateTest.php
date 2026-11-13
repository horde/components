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

namespace Horde\Components\Test\Unit\Release;

use Horde\Components\Component\ComponentDirectory;
use Horde\Components\Exception;
use Horde\Components\Helper\Composer as ComposerHelper;
use Horde\Components\Helper\Git as GitHelper;
use Horde\Components\Helper\GitHubReleaseCreator;
use Horde\Components\Helper\Shell as ShellHelper;
use Horde\Components\Output;
use Horde\Components\Qc\Tasks as QcTasks;
use Horde\Components\Release\BranchRoles;
use Horde\Components\Release\HordeRelease;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Tests the role-aware release preflight and the branch-policy gate.
 *
 * Both are small private helpers on HordeRelease, exercised here via
 * reflection so no release pipeline, git, or filesystem is touched (same
 * decomposition that keeps BranchReleasePolicy itself pure and testable).
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
#[CoversClass(HordeRelease::class)]
class HordeReleaseBranchGateTest extends TestCase
{
    private function release(string $currentBranch, BranchRoles $roles): HordeRelease
    {
        $git = $this->createStub(GitHelper::class);
        $git->method('getCurrentBranch')->willReturn($currentBranch);

        $directory = $this->createStub(ComponentDirectory::class);
        $directory->method('__toString')->willReturn('/path/to/component');

        return new HordeRelease(
            $this->createStub(ComposerHelper::class),
            $git,
            $directory,
            $this->createStub(Output::class),
            $this->createStub(GitHubReleaseCreator::class),
            $this->createStub(QcTasks::class),
            $this->createStub(ShellHelper::class),
            $roles,
            true
        );
    }

    private function resolveRole(HordeRelease $release): string
    {
        return (new ReflectionMethod($release, 'resolveBranchRole'))->invoke($release);
    }

    private function enforce(
        HordeRelease $release,
        string $role,
        string $severity,
        string $stability,
        bool $force
    ): void {
        (new ReflectionMethod($release, 'enforceBranchPolicy'))
            ->invoke($release, $role, $severity, $stability, $force);
    }

    // --- role-aware preflight ------------------------------------------

    public function testReleaseBranchResolvesToReleaseRole(): void
    {
        $roles = new BranchRoles('FRAMEWORK_9_9', ['FRAMEWORK_9_8']);
        $this->assertSame(
            BranchRoles::ROLE_RELEASE,
            $this->resolveRole($this->release('FRAMEWORK_9_9', $roles))
        );
    }

    public function testSupportedBranchResolvesToSupportedRole(): void
    {
        $roles = new BranchRoles('FRAMEWORK_9_9', ['FRAMEWORK_9_8']);
        $this->assertSame(
            BranchRoles::ROLE_SUPPORTED,
            $this->resolveRole($this->release('FRAMEWORK_9_8', $roles))
        );
    }

    public function testUnknownBranchIsRefused(): void
    {
        $roles = new BranchRoles('FRAMEWORK_9_9', ['FRAMEWORK_9_8']);
        $release = $this->release('feature/random', $roles);

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/no release role/');
        $this->resolveRole($release);
    }

    // --- policy gate ---------------------------------------------------

    public function testSupportedBugfixPasses(): void
    {
        $release = $this->release('FRAMEWORK_9_8', new BranchRoles('FRAMEWORK_9_9', ['FRAMEWORK_9_8']));
        $this->enforce($release, BranchRoles::ROLE_SUPPORTED, 'patch', 'stable', false);
        $this->addToAssertionCount(1);
    }

    public function testSupportedFeatureRefusedWithoutForce(): void
    {
        $release = $this->release('FRAMEWORK_9_8', new BranchRoles('FRAMEWORK_9_9', ['FRAMEWORK_9_8']));
        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/branch policy/');
        $this->enforce($release, BranchRoles::ROLE_SUPPORTED, 'minor', 'stable', false);
    }

    public function testSupportedFeatureProceedsWithForce(): void
    {
        $release = $this->release('FRAMEWORK_9_8', new BranchRoles('FRAMEWORK_9_9', ['FRAMEWORK_9_8']));
        $this->enforce($release, BranchRoles::ROLE_SUPPORTED, 'minor', 'stable', true);
        $this->addToAssertionCount(1);
    }

    public function testReleaseBreakingOnStableRefusedWithoutForce(): void
    {
        $release = $this->release('FRAMEWORK_9_9', new BranchRoles('FRAMEWORK_9_9'));
        $this->expectException(Exception::class);
        $this->enforce($release, BranchRoles::ROLE_RELEASE, 'major', 'stable', false);
    }

    public function testReleaseFeatureOnStablePasses(): void
    {
        $release = $this->release('FRAMEWORK_9_9', new BranchRoles('FRAMEWORK_9_9'));
        $this->enforce($release, BranchRoles::ROLE_RELEASE, 'minor', 'stable', false);
        $this->addToAssertionCount(1);
    }
}
