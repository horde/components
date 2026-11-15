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

namespace Horde\Components\Test\Unit\Release\Branch;

use Horde\Components\Release\Branch\BranchPhpPolicy;
use Horde\Version\Constraint\CaretConstraint;
use Horde\Version\RelaxedSemanticVersion;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the per-branch PHP-version policy value object.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
#[CoversClass(BranchPhpPolicy::class)]
class BranchPhpPolicyTest extends TestCase
{
    public function testNoneIsEmpty(): void
    {
        $policy = BranchPhpPolicy::none();

        $this->assertTrue($policy->isEmpty());
        $this->assertNull($policy->min());
        $this->assertNull($policy->max());
        $this->assertFalse($policy->syncsMin());
        $this->assertFalse($policy->syncsMax());
        $this->assertNull($policy->minConstraint());
        $this->assertNull($policy->maxConstraint());
    }

    public function testFromArrayReadsAllFourProperties(): void
    {
        $policy = BranchPhpPolicy::fromArray([
            'min_php_version' => '^8.1',
            'sync_min_php_version' => true,
            'max_php_version' => '^8.6',
            'sync_max_php_version' => true,
        ]);

        $this->assertFalse($policy->isEmpty());
        $this->assertSame('^8.1', $policy->min());
        $this->assertSame('^8.6', $policy->max());
        $this->assertTrue($policy->syncsMin());
        $this->assertTrue($policy->syncsMax());
    }

    public function testFromArrayDefaultsMissingKeys(): void
    {
        $policy = BranchPhpPolicy::fromArray(['min_php_version' => '^8.2']);

        $this->assertSame('^8.2', $policy->min());
        $this->assertNull($policy->max());
        $this->assertFalse($policy->syncsMin());
        $this->assertFalse($policy->syncsMax());
        $this->assertFalse($policy->isEmpty());
    }

    public function testEmptyPropertyMapIsEmptyPolicy(): void
    {
        $this->assertTrue(BranchPhpPolicy::fromArray([])->isEmpty());
    }

    public function testUnknownKeysAreIgnored(): void
    {
        $policy = BranchPhpPolicy::fromArray(['something_else' => 'x']);

        $this->assertTrue($policy->isEmpty());
    }

    /**
     * YAML hands back an unquoted 8.1 as a float; it must still become the
     * string "8.1" so the constraint parser sees a version, not a number.
     */
    public function testFloatVersionIsStringified(): void
    {
        $policy = BranchPhpPolicy::fromArray(['min_php_version' => 8.1]);

        $this->assertSame('8.1', $policy->min());
    }

    public function testBlankVersionStringDegradesToNull(): void
    {
        $policy = BranchPhpPolicy::fromArray([
            'min_php_version' => '',
            'max_php_version' => '   ',
        ]);

        $this->assertNull($policy->min());
        $this->assertNull($policy->max());
        $this->assertTrue($policy->isEmpty());
    }

    public function testSyncFlagAcceptsStringTrue(): void
    {
        $policy = BranchPhpPolicy::fromArray([
            'min_php_version' => '^8.2',
            'sync_min_php_version' => 'true',
        ]);

        $this->assertTrue($policy->syncsMin());
    }

    public function testSyncFlagRejectsNonBooleanish(): void
    {
        $policy = BranchPhpPolicy::fromArray([
            'sync_min_php_version' => 'yes',
            'sync_max_php_version' => 1,
        ]);

        $this->assertFalse($policy->syncsMin());
        $this->assertFalse($policy->syncsMax());
    }

    public function testSyncOnlyPolicyIsNotEmpty(): void
    {
        $policy = BranchPhpPolicy::fromArray(['sync_min_php_version' => true]);

        $this->assertFalse($policy->isEmpty());
    }

    public function testMinConstraintParsesViaHordeVersion(): void
    {
        $policy = BranchPhpPolicy::fromArray(['min_php_version' => '^8.2']);
        $constraint = $policy->minConstraint();

        $this->assertInstanceOf(CaretConstraint::class, $constraint);
        // ^8.2 := >=8.2.0 <9.0.0
        $this->assertFalse($constraint->isSatisfiedBy(new RelaxedSemanticVersion('8.1.0')));
        $this->assertTrue($constraint->isSatisfiedBy(new RelaxedSemanticVersion('8.2.0')));
        $this->assertTrue($constraint->isSatisfiedBy(new RelaxedSemanticVersion('8.4.0')));
        $this->assertFalse($constraint->isSatisfiedBy(new RelaxedSemanticVersion('9.0.0')));
    }

    public function testMaxConstraintParsesViaHordeVersion(): void
    {
        $policy = BranchPhpPolicy::fromArray(['max_php_version' => '^8.6']);
        $constraint = $policy->maxConstraint();

        $this->assertNotNull($constraint);
        $this->assertSame('^8.6.0', (string) $constraint);
    }
}
