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

use Horde\Components\Release\BranchRoles;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the central branch-role map loader.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
#[CoversClass(BranchRoles::class)]
class BranchRolesTest extends TestCase
{
    private ?string $tempPath = null;

    protected function tearDown(): void
    {
        if ($this->tempPath !== null) {
            @unlink($this->tempPath);
            $this->tempPath = null;
        }
    }

    public function testBundledFileLoadsAndClassifies(): void
    {
        // The bundled data/branches.yml ships the live collection map.
        $roles = BranchRoles::load();

        $this->assertSame('FRAMEWORK_6_0', $roles->releaseBranch());
        $this->assertSame(BranchRoles::ROLE_RELEASE, $roles->roleOf('FRAMEWORK_6_0'));
        $this->assertSame(BranchRoles::ROLE_SUPPORTED, $roles->roleOf('FRAMEWORK_5_2'));
        $this->assertSame(BranchRoles::ROLE_UNKNOWN, $roles->roleOf('feat/anything'));
    }

    public function testFromArrayFullShape(): void
    {
        $roles = BranchRoles::fromArray([
            'release' => 'FRAMEWORK_6_0',
            'supported' => ['FRAMEWORK_5_2', 'FRAMEWORK_5_1'],
            'unstable' => 'FRAMEWORK_7_0',
        ]);

        $this->assertTrue($roles->isRelease('FRAMEWORK_6_0'));
        $this->assertTrue($roles->isSupported('FRAMEWORK_5_1'));
        $this->assertSame(['FRAMEWORK_5_2', 'FRAMEWORK_5_1'], $roles->supportedBranches());
        $this->assertSame('FRAMEWORK_7_0', $roles->unstableBranch());
        $this->assertSame(BranchRoles::ROLE_UNSTABLE, $roles->roleOf('FRAMEWORK_7_0'));
    }

    public function testUnstableNullAndEmptyTreatedAsNone(): void
    {
        $this->assertNull(BranchRoles::fromArray(['release' => 'R', 'unstable' => null])->unstableBranch());
        $this->assertNull(BranchRoles::fromArray(['release' => 'R', 'unstable' => ''])->unstableBranch());
        $this->assertNull(BranchRoles::fromArray(['release' => 'R'])->unstableBranch());
    }

    public function testMalformedSupportedDefaultsToEmpty(): void
    {
        // A non-list "supported" value must not fatal; it degrades to none.
        $roles = BranchRoles::fromArray(['release' => 'R', 'supported' => 'oops']);
        $this->assertSame([], $roles->supportedBranches());
    }

    public function testEmptyBranchNameIsNeverRelease(): void
    {
        // Guards against an empty release name matching an empty lookup.
        $roles = BranchRoles::fromArray(['release' => '']);
        $this->assertSame(BranchRoles::ROLE_UNKNOWN, $roles->roleOf(''));
    }

    public function testLoadFromExplicitFile(): void
    {
        $this->tempPath = tempnam(sys_get_temp_dir(), 'branches-') . '.yml';
        file_put_contents($this->tempPath, <<<'YAML'
            release: MAIN
            supported:
              - OLD_1
              - OLD_2
            unstable: NEXT
            YAML);

        $roles = BranchRoles::load($this->tempPath);

        $this->assertSame('MAIN', $roles->releaseBranch());
        $this->assertSame(['OLD_1', 'OLD_2'], $roles->supportedBranches());
        $this->assertSame('NEXT', $roles->unstableBranch());
    }

    public function testMissingFileYieldsEmptyMap(): void
    {
        // YamlLoader::loadFile returns [] for a missing file; the loader
        // must not fatal, just produce an all-unknown map.
        $roles = BranchRoles::load('/nonexistent/branches.yml');
        $this->assertSame('', $roles->releaseBranch());
        $this->assertSame(BranchRoles::ROLE_UNKNOWN, $roles->roleOf('FRAMEWORK_6_0'));
    }
}
