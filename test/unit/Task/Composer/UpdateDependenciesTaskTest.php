<?php

/**
 * Copyright 2026-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

declare(strict_types=1);

namespace Horde\Components\Test\Task\Composer;

use Horde\Components\Helper\Composer;
use Horde\Components\Helper\Git;
use Horde\Components\Output;
use Horde\Components\Task\Composer\UpdateDependenciesTask;
use Horde\Components\Task\Context;
use Horde\HordeYmlFile\HordeYmlFile;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;

/**
 * Unit tests for UpdateDependenciesTask.
 *
 * Tests focus on the constraint calculation logic, including compound
 * OR constraints, version parsing, and stability handling.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
#[CoversClass(UpdateDependenciesTask::class)]
class UpdateDependenciesTaskTest extends TestCase
{
    private UpdateDependenciesTask $task;
    private Output $output;
    private Git $git;
    private Composer $composer;

    protected function setUp(): void
    {
        $this->output = $this->createMock(Output::class);
        $this->git = $this->createMock(Git::class);
        $this->composer = $this->createMock(Composer::class);

        $this->task = new UpdateDependenciesTask(
            $this->output,
            $this->git,
            $this->composer,
            true // pretend mode for tests
        );
    }

    /**
     * Test calculateConstraint with simple caret constraint and stable version.
     */
    public function testCalculateConstraintSimpleStable(): void
    {
        $result = $this->invokePrivateMethod(
            'calculateConstraint',
            ['horde/test', '^3.0', '3.1.0']
        );

        $this->assertNotNull($result);
        $this->assertEquals('3.1.0', $result['version']);
        $this->assertEquals('^3.1', $result['constraint']);
        $this->assertArrayNotHasKey('suggestion', $result);
        $this->assertArrayNotHasKey('todo', $result);
    }

    /**
     * Test calculateConstraint with alpha version (from wildcard).
     */
    public function testCalculateConstraintAlphaVersion(): void
    {
        // Wildcard doesn't have stability, so alpha update is allowed
        $result = $this->invokePrivateMethod(
            'calculateConstraint',
            ['horde/test', '*', '3.0.0-alpha5']
        );

        $this->assertNotNull($result);
        $this->assertEquals('3.0.0-alpha5', $result['version']);
        $this->assertEquals('^3.0.0-alpha5', $result['constraint']);
    }

    /**
     * Test calculateConstraint with beta version (from wildcard).
     */
    public function testCalculateConstraintBetaVersion(): void
    {
        $result = $this->invokePrivateMethod(
            'calculateConstraint',
            ['horde/test', '*', '3.0.0-beta2']
        );

        $this->assertNotNull($result);
        $this->assertEquals('3.0.0-beta2', $result['version']);
        $this->assertEquals('^3.0.0-beta2', $result['constraint']);
    }

    /**
     * Test calculateConstraint with rc version (from wildcard).
     */
    public function testCalculateConstraintRcVersion(): void
    {
        $result = $this->invokePrivateMethod(
            'calculateConstraint',
            ['horde/test', '*', '3.0.0-rc1']
        );

        $this->assertNotNull($result);
        $this->assertEquals('3.0.0-rc1', $result['version']);
        $this->assertEquals('^3.0.0-rc1', $result['constraint']);
    }

    /**
     * Test calculateConstraint preserves existing bugfix constraint.
     */
    public function testCalculateConstraintPreservesBugfixVersion(): void
    {
        // When current is ^3.1.2 and new is 3.2.0 (minor bump), update to ^3.2 but preserve bugfix pattern
        $result = $this->invokePrivateMethod(
            'calculateConstraint',
            ['horde/test', '^3.1.2', '3.2.0']
        );

        $this->assertNotNull($result);
        // Bumps minor, no bugfix since new is .0
        $this->assertEquals('^3.2', $result['constraint']);
    }

    /**
     * Test calculateConstraint doesn't downgrade bugfix version.
     */
    public function testCalculateConstraintNoDowngradeBugfix(): void
    {
        $result = $this->invokePrivateMethod(
            'calculateConstraint',
            ['horde/test', '^3.1.5', '3.1.2']
        );

        $this->assertNull($result); // No update needed
    }

    /**
     * Test calculateConstraint with major version upgrade (should suggest).
     */
    public function testCalculateConstraintMajorUpgradeSuggestion(): void
    {
        $result = $this->invokePrivateMethod(
            'calculateConstraint',
            ['horde/test', '^3.1', '4.0.0']
        );

        $this->assertNotNull($result);
        $this->assertEquals('4.0.0', $result['version']);
        $this->assertEquals('^4.0', $result['constraint']);
        $this->assertTrue($result['suggestion'] ?? false);
        $this->assertStringContainsString('Major version upgrade', $result['reason'] ?? '');
    }

    /**
     * Test calculateConstraint doesn't downgrade stability (stable to alpha).
     */
    public function testCalculateConstraintNoStabilityDowngrade(): void
    {
        // Current: stable ^3.1, New: alpha 3.2.0-alpha1
        $result = $this->invokePrivateMethod(
            'calculateConstraint',
            ['horde/test', '^3.1', '3.2.0-alpha1']
        );

        $this->assertNull($result); // No update - don't downgrade to alpha
    }

    /**
     * Test calculateConstraint handles wildcard constraints.
     */
    public function testCalculateConstraintWildcard(): void
    {
        $result = $this->invokePrivateMethod(
            'calculateConstraint',
            ['horde/test', '*', '3.1.0']
        );

        $this->assertNotNull($result);
        $this->assertEquals('^3.1', $result['constraint']);
    }

    /**
     * Test parseVersion with standard format.
     */
    public function testParseVersionStandard(): void
    {
        $result = $this->invokePrivateMethod('parseVersion', ['3.1.2']);

        $this->assertEquals(3, $result['major']);
        $this->assertEquals(1, $result['minor']);
        $this->assertEquals(2, $result['patch']);
        $this->assertNull($result['prerelease']);
    }

    /**
     * Test parseVersion with alpha.
     */
    public function testParseVersionAlpha(): void
    {
        $result = $this->invokePrivateMethod('parseVersion', ['3.0.0-alpha5']);

        $this->assertEquals(3, $result['major']);
        $this->assertEquals(0, $result['minor']);
        $this->assertEquals(0, $result['patch']);
        $this->assertEquals('alpha5', $result['prerelease']);
    }

    /**
     * Test parseVersion with Horde legacy format.
     */
    public function testParseVersionHordeLegacy(): void
    {
        $result = $this->invokePrivateMethod('parseVersion', ['3.0.0alpha5']);

        $this->assertEquals(3, $result['major']);
        $this->assertEquals(0, $result['minor']);
        $this->assertEquals(0, $result['patch']);
        $this->assertEquals('alpha5', $result['prerelease']);
    }

    /**
     * Test parseVersion with beta.
     */
    public function testParseVersionBeta(): void
    {
        $result = $this->invokePrivateMethod('parseVersion', ['3.0.0-beta2']);

        $this->assertEquals(3, $result['major']);
        $this->assertEquals(0, $result['minor']);
        $this->assertEquals(0, $result['patch']);
        $this->assertEquals('beta2', $result['prerelease']);
    }

    /**
     * Test parseVersion with rc.
     */
    public function testParseVersionRc(): void
    {
        $result = $this->invokePrivateMethod('parseVersion', ['3.0.0-rc1']);

        $this->assertEquals('rc1', $result['prerelease']);
    }

    /**
     * Test buildConstraint with stable version.
     */
    public function testBuildConstraintStable(): void
    {
        $parsed = ['major' => 3, 'minor' => 1, 'patch' => 0, 'prerelease' => null];
        $result = $this->invokePrivateMethod('buildConstraint', [$parsed]);

        $this->assertEquals('^3.1', $result);
    }

    /**
     * Test buildConstraint with alpha version.
     */
    public function testBuildConstraintAlpha(): void
    {
        $parsed = ['major' => 3, 'minor' => 0, 'patch' => 0, 'prerelease' => 'alpha5'];
        $result = $this->invokePrivateMethod('buildConstraint', [$parsed]);

        $this->assertEquals('^3.0.0-alpha5', $result);
    }

    /**
     * Test buildConstraint preserves existing bugfix version from current.
     */
    public function testBuildConstraintPreservesBugfix(): void
    {
        $parsed = ['major' => 3, 'minor' => 1, 'patch' => 5, 'prerelease' => null];
        $currentParsed = ['major' => 3, 'minor' => 1, 'patch' => 3, 'prerelease' => null];

        $result = $this->invokePrivateMethod('buildConstraint', [$parsed, $currentParsed]);

        // buildConstraint preserves currentParsed patch when it exists and > 0
        $this->assertEquals('^3.1.3', $result);
    }

    /**
     * Test buildConstraint with no current bugfix version.
     */
    public function testBuildConstraintNoBugfix(): void
    {
        $parsed = ['major' => 3, 'minor' => 1, 'patch' => 5, 'prerelease' => null];
        $currentParsed = ['major' => 3, 'minor' => 1, 'patch' => 0, 'prerelease' => null];

        $result = $this->invokePrivateMethod('buildConstraint', [$parsed, $currentParsed]);

        // When current patch is 0, use major.minor only
        $this->assertEquals('^3.1', $result);
    }

    /**
     * Test getStabilityLevel for different pre-release tags.
     */
    public function testGetStabilityLevel(): void
    {
        $alpha = $this->invokePrivateMethod('getStabilityLevel', ['alpha5']);
        $beta = $this->invokePrivateMethod('getStabilityLevel', ['beta2']);
        $rc = $this->invokePrivateMethod('getStabilityLevel', ['rc1']);
        $dev = $this->invokePrivateMethod('getStabilityLevel', ['dev']);

        // Verify ordering: dev < alpha < beta < rc
        $this->assertLessThan($alpha, $dev);
        $this->assertLessThan($beta, $alpha);
        $this->assertLessThan($rc, $beta);
    }

    /**
     * Test isStabilityDowngrade detects downgrade from stable to alpha.
     */
    public function testIsStabilityDowngradeStableToAlpha(): void
    {
        $current = ['major' => 3, 'minor' => 1, 'patch' => 0, 'prerelease' => null];
        $new = ['major' => 3, 'minor' => 2, 'patch' => 0, 'prerelease' => 'alpha1'];

        $result = $this->invokePrivateMethod('isStabilityDowngrade', [$current, $new]);

        $this->assertTrue($result);
    }

    /**
     * Test isStabilityDowngrade detects downgrade from beta to alpha.
     */
    public function testIsStabilityDowngradeBetaToAlpha(): void
    {
        $current = ['major' => 3, 'minor' => 0, 'patch' => 0, 'prerelease' => 'beta1'];
        $new = ['major' => 3, 'minor' => 0, 'patch' => 0, 'prerelease' => 'alpha5'];

        $result = $this->invokePrivateMethod('isStabilityDowngrade', [$current, $new]);

        $this->assertTrue($result);
    }

    /**
     * Test isStabilityDowngrade allows upgrade from alpha to beta.
     */
    public function testIsStabilityDowngradeAlphaToBeta(): void
    {
        $current = ['major' => 3, 'minor' => 0, 'patch' => 0, 'prerelease' => 'alpha5'];
        $new = ['major' => 3, 'minor' => 0, 'patch' => 0, 'prerelease' => 'beta1'];

        $result = $this->invokePrivateMethod('isStabilityDowngrade', [$current, $new]);

        $this->assertFalse($result);
    }

    /**
     * Test isStabilityDowngrade allows upgrade from beta to stable.
     */
    public function testIsStabilityDowngradeBetaToStable(): void
    {
        $current = ['major' => 3, 'minor' => 0, 'patch' => 0, 'prerelease' => 'beta1'];
        $new = ['major' => 3, 'minor' => 0, 'patch' => 0, 'prerelease' => null];

        $result = $this->invokePrivateMethod('isStabilityDowngrade', [$current, $new]);

        $this->assertFalse($result);
    }

    /**
     * Helper method to invoke private methods for testing.
     */
    private function invokePrivateMethod(string $methodName, array $args = []): mixed
    {
        $reflection = new ReflectionClass($this->task);
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);
        return $method->invokeArgs($this->task, $args);
    }
}
