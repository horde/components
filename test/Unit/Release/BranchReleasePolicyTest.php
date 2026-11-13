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

use Horde\Components\Release\BranchReleaseDecision;
use Horde\Components\Release\BranchReleasePolicy;
use Horde\Components\Release\BranchRoles;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the pure branch release policy.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
#[CoversClass(BranchReleasePolicy::class)]
#[CoversClass(BranchReleaseDecision::class)]
class BranchReleasePolicyTest extends TestCase
{
    private BranchReleasePolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new BranchReleasePolicy();
    }

    public function testSupportedBranchAllowsBugfix(): void
    {
        foreach (['patch', 'subpatch'] as $severity) {
            $decision = $this->policy->evaluate(BranchRoles::ROLE_SUPPORTED, $severity, 'stable');
            $this->assertTrue($decision->isAllowed(), "severity {$severity} should pass on supported");
            $this->assertSame([], $decision->violations());
        }
    }

    public function testSupportedBranchRejectsFeature(): void
    {
        $decision = $this->policy->evaluate(BranchRoles::ROLE_SUPPORTED, 'minor', 'stable');
        $this->assertFalse($decision->isAllowed());
        $this->assertStringContainsString('bugfix releases only', $decision->violations()[0]);
    }

    public function testSupportedBranchRejectsBreakingChange(): void
    {
        $decision = $this->policy->evaluate(BranchRoles::ROLE_SUPPORTED, 'major', 'alpha');
        $this->assertFalse($decision->isAllowed());
        $this->assertStringContainsString('bugfix releases only', $decision->violations()[0]);
    }

    public function testReleaseBranchAllowsFeature(): void
    {
        $decision = $this->policy->evaluate(BranchRoles::ROLE_RELEASE, 'minor', 'stable');
        $this->assertTrue($decision->isAllowed());
    }

    public function testReleaseBranchAllowsBreakingChangeWhilePreStable(): void
    {
        // alpha/beta and a bare pre-1.0 stability are "unstable".
        foreach (['alpha', 'beta', 'RC'] as $stability) {
            $decision = $this->policy->evaluate(BranchRoles::ROLE_RELEASE, 'major', $stability);
            $this->assertTrue($decision->isAllowed(), "major should pass on {$stability} release");
        }
    }

    public function testReleaseBranchRejectsBreakingChangeWhenStable(): void
    {
        foreach (['stable', ''] as $stability) {
            $decision = $this->policy->evaluate(BranchRoles::ROLE_RELEASE, 'major', $stability);
            $this->assertFalse($decision->isAllowed(), "major should fail on stable release");
            $this->assertStringContainsString('not allowed', $decision->violations()[0]);
        }
    }

    public function testUnstableBranchAllowsAnything(): void
    {
        foreach (['subpatch', 'patch', 'minor', 'major'] as $severity) {
            $decision = $this->policy->evaluate(BranchRoles::ROLE_UNSTABLE, $severity, 'stable');
            $this->assertTrue($decision->isAllowed(), "severity {$severity} should pass on unstable");
        }
    }

    public function testUnknownBranchIsRejected(): void
    {
        $decision = $this->policy->evaluate(BranchRoles::ROLE_UNKNOWN, 'patch', 'stable');
        $this->assertFalse($decision->isAllowed());
        $this->assertStringContainsString('no declared role', $decision->violations()[0]);
    }
}
