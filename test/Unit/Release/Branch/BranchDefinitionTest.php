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

use Horde\Components\Exception;
use Horde\Components\Release\Branch\BranchDefinition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for a single branch definition and its YAML normalization.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
#[CoversClass(BranchDefinition::class)]
class BranchDefinitionTest extends TestCase
{
    public function testBareStringYieldsEmptyPolicy(): void
    {
        $definition = BranchDefinition::fromYamlValue('FRAMEWORK_6_0');

        $this->assertSame('FRAMEWORK_6_0', $definition->name());
        $this->assertTrue($definition->policy()->isEmpty());
        $this->assertFalse($definition->hasPolicy());
    }

    public function testBareStringIsTrimmed(): void
    {
        $definition = BranchDefinition::fromYamlValue('  FRAMEWORK_6_0  ');

        $this->assertSame('FRAMEWORK_6_0', $definition->name());
    }

    public function testSingleKeyMapCarriesPolicy(): void
    {
        $definition = BranchDefinition::fromYamlValue([
            'FRAMEWORK_6_1' => [
                'min_php_version' => '^8.1',
                'max_php_version' => '^8.6',
            ],
        ]);

        $this->assertSame('FRAMEWORK_6_1', $definition->name());
        $this->assertTrue($definition->hasPolicy());
        $this->assertSame('^8.1', $definition->policy()->min());
        $this->assertSame('^8.6', $definition->policy()->max());
    }

    /**
     * A map entry written as `FRAMEWORK_x:` with no body resolves to a
     * null value; it is a branch with an empty policy, not an error.
     */
    public function testSingleKeyMapWithNullBodyIsBare(): void
    {
        $definition = BranchDefinition::fromYamlValue(['FRAMEWORK_6_1' => null]);

        $this->assertSame('FRAMEWORK_6_1', $definition->name());
        $this->assertTrue($definition->policy()->isEmpty());
    }

    public function testBareFactoryProducesEmptyPolicy(): void
    {
        $definition = BranchDefinition::bare('MAIN');

        $this->assertSame('MAIN', $definition->name());
        $this->assertTrue($definition->policy()->isEmpty());
    }

    public function testEmptyStringNameThrows(): void
    {
        $this->expectException(Exception::class);
        BranchDefinition::fromYamlValue('   ');
    }

    public function testEmptyMapKeyThrows(): void
    {
        $this->expectException(Exception::class);
        BranchDefinition::fromYamlValue(['' => ['min_php_version' => '^8.2']]);
    }

    public function testNonArrayMapValueThrows(): void
    {
        $this->expectException(Exception::class);
        BranchDefinition::fromYamlValue(['FRAMEWORK_6_1' => 'not-a-map']);
    }

    public function testMultiKeyMapThrows(): void
    {
        $this->expectException(Exception::class);
        BranchDefinition::fromYamlValue([
            'FRAMEWORK_6_0' => null,
            'FRAMEWORK_6_1' => null,
        ]);
    }

    public function testScalarNonStringThrows(): void
    {
        $this->expectException(Exception::class);
        BranchDefinition::fromYamlValue(42);
    }
}
