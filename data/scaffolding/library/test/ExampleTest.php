<?php
/**
 * Tests for Example class.
 *
 * Copyright 2013-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Skeleton
 * @author   Some Person <some.person@example.com>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

declare(strict_types=1);

namespace Horde\Skeleton\Test;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Horde\Skeleton\Example;

/**
 * Tests for Example class.
 *
 * @category Horde
 * @package  Skeleton
 * @author   Some Person <some.person@example.com>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
#[CoversClass(Example::class)]
class ExampleTest extends TestCase
{
    private Example $example;

    protected function setUp(): void
    {
        $this->example = new Example();
    }

    public function testGreet(): void
    {
        $result = $this->example->greet();
        $this->assertSame('Hello from Skeleton!', $result);
    }

    public function testAdd(): void
    {
        $this->assertSame(5, $this->example->add(2, 3));
        $this->assertSame(0, $this->example->add(-1, 1));
        $this->assertSame(-5, $this->example->add(-2, -3));
    }
}
