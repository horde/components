<?php

/**
 * Unit tests for BranchAliasCheck
 *
 * PHP version 8.2+
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

declare(strict_types=1);

namespace Horde\Components\Test\Unit\Report;

use Horde\Components\Report\BranchAliasCheck;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BranchAliasCheck::class)]
class BranchAliasCheckTest extends TestCase
{
    public function testConfiguredBranchAlias(): void
    {
        $check = new BranchAliasCheck(configured: true, value: '3.x-dev');

        $this->assertTrue($check->configured);
        $this->assertEquals('3.x-dev', $check->value);
    }

    public function testUnconfiguredBranchAlias(): void
    {
        $check = new BranchAliasCheck(configured: false, value: null);

        $this->assertFalse($check->configured);
        $this->assertNull($check->value);
    }
}
