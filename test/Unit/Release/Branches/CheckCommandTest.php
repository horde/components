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
use Horde\Components\Output;
use Horde\Components\Release\Branches\CheckCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the branch-check PHP-version policy compliance reporting.
 *
 * The PHP policy is reported and fails the command on drift independent of
 * --force. Git state is stubbed; the commit-severity classification
 * degrades to 'patch' when the component dir is not a git repo, which is
 * fine for these tests.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
#[CoversClass(CheckCommand::class)]
class CheckCommandTest extends TestCase
{
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
            @rmdir($dir);
        }
        $this->cleanup = [];
        $this->cleanupDirs = [];
    }

    private function command(string $currentBranch, ?string $defaultBranch = null): CheckCommand
    {
        $git = $this->createStub(GitHelper::class);
        $git->method('getCurrentBranch')->willReturn($currentBranch);
        $git->method('getDefaultBranch')->willReturn($defaultBranch);
        return new CheckCommand($this->createStub(Output::class), $git);
    }

    public function testCompliantPasses(): void
    {
        $dir = $this->componentDir('^8.2');
        $roles = $this->rolesFileWithPolicy('FRAMEWORK_6_0', '^8.2');

        $this->assertTrue(
            $this->command('FRAMEWORK_6_0', 'FRAMEWORK_6_0')->execute($dir, $roles, false)
        );
    }

    public function testNonCompliantFailsWithoutForce(): void
    {
        $dir = $this->componentDir('^8.0');
        $roles = $this->rolesFileWithPolicy('FRAMEWORK_6_0', '^8.2');

        $this->assertFalse(
            $this->command('FRAMEWORK_6_0', 'FRAMEWORK_6_0')->execute($dir, $roles, false)
        );
    }

    public function testNonCompliantFailsEvenWithForce(): void
    {
        // --force must not suppress PHP-policy drift.
        $dir = $this->componentDir('^8.0');
        $roles = $this->rolesFileWithPolicy('FRAMEWORK_6_0', '^8.2');

        $this->assertFalse(
            $this->command('FRAMEWORK_6_0', 'FRAMEWORK_6_0')->execute($dir, $roles, true)
        );
    }

    public function testNoPolicyUnaffected(): void
    {
        $dir = $this->componentDir('^8.0');
        // Bare-string release, no policy.
        $roles = tempnam(sys_get_temp_dir(), 'roles-') . '.yml';
        file_put_contents($roles, "release: FRAMEWORK_6_0\nsupported: []\nunstable: null\n");
        $this->cleanup[] = $roles;

        $this->assertTrue(
            $this->command('FRAMEWORK_6_0', 'FRAMEWORK_6_0')->execute($dir, $roles, false)
        );
    }

    public function testCurrentBranchPolicyPreferredOverRelease(): void
    {
        // The current branch is a supported branch with a stricter floor
        // than the component declares; check keys on the current branch.
        $dir = $this->componentDir('^8.1');
        $roles = tempnam(sys_get_temp_dir(), 'roles-') . '.yml';
        file_put_contents($roles, <<<'YAML'
            release: FRAMEWORK_6_0
            supported:
              - FRAMEWORK_5_2:
                  min_php_version: "^8.3"
            unstable: null
            YAML);
        $this->cleanup[] = $roles;

        // On FRAMEWORK_5_2 the ^8.3 floor is unmet by ^8.1 -> fails.
        $this->assertFalse(
            $this->command('FRAMEWORK_5_2', 'FRAMEWORK_6_0')->execute($dir, $roles, false)
        );
    }

    private function componentDir(string $php): string
    {
        $dir = sys_get_temp_dir() . '/check-cmp-' . bin2hex(random_bytes(6));
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
              release: 1.0.0
              api: 1.0.0
            state:
              release: stable
              api: stable
            license: { identifier: LGPL-2.1-only, uri: x }
            dependencies:
              required:
                php: "{$php}"
            YML);
        $this->cleanupDirs[] = $dir;
        return $dir;
    }

    private function rolesFileWithPolicy(string $release, string $min): string
    {
        $path = tempnam(sys_get_temp_dir(), 'roles-') . '.yml';
        file_put_contents($path, <<<YAML
            release:
              {$release}:
                min_php_version: "{$min}"
            supported: []
            unstable: null
            YAML);
        $this->cleanup[] = $path;
        return $path;
    }
}
