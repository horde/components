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
use Horde\Components\Release\Branch\BranchRoleMap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the structured, policy-aware branches.yml reader.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
#[CoversClass(BranchRoleMap::class)]
class BranchRoleMapTest extends TestCase
{
    private ?string $tempPath = null;

    protected function tearDown(): void
    {
        if ($this->tempPath !== null) {
            @unlink($this->tempPath);
            $this->tempPath = null;
        }
    }

    public function testSimpleBareStringFormat(): void
    {
        $map = BranchRoleMap::fromString(<<<'YAML'
            release: FRAMEWORK_6_0
            supported:
              - FRAMEWORK_5_2
            unstable: null
            YAML);

        $this->assertSame('FRAMEWORK_6_0', $map->release()->name());
        $this->assertTrue($map->release()->policy()->isEmpty());

        $supported = $map->supported();
        $this->assertCount(1, $supported);
        $this->assertSame('FRAMEWORK_5_2', $supported[0]->name());
        $this->assertTrue($supported[0]->policy()->isEmpty());

        $this->assertNull($map->unstable());
    }

    public function testEnhancedMapFormatForRelease(): void
    {
        $map = BranchRoleMap::fromString(<<<'YAML'
            release:
              FRAMEWORK_6_1:
                min_php_version: "^8.1"
                max_php_version: "^8.6"
                sync_min_php_version: true
            supported:
              - FRAMEWORK_6_0
              - FRAMEWORK_5_2:
                  min_php_version: "^7.4"
            unstable: null
            YAML);

        $release = $map->release();
        $this->assertSame('FRAMEWORK_6_1', $release->name());
        $this->assertTrue($release->hasPolicy());
        $this->assertSame('^8.1', $release->policy()->min());
        $this->assertSame('^8.6', $release->policy()->max());
        $this->assertTrue($release->policy()->syncsMin());
        $this->assertFalse($release->policy()->syncsMax());

        $supported = $map->supported();
        $this->assertCount(2, $supported);

        // Bare string item -> empty policy.
        $this->assertSame('FRAMEWORK_6_0', $supported[0]->name());
        $this->assertTrue($supported[0]->policy()->isEmpty());

        // Map item -> policy carried.
        $this->assertSame('FRAMEWORK_5_2', $supported[1]->name());
        $this->assertSame('^7.4', $supported[1]->policy()->min());
    }

    public function testUnstableMapFormat(): void
    {
        $map = BranchRoleMap::fromString(<<<'YAML'
            release: FRAMEWORK_6_0
            unstable:
              FRAMEWORK_7_0:
                min_php_version: "^8.4"
            YAML);

        $unstable = $map->unstable();
        $this->assertNotNull($unstable);
        $this->assertSame('FRAMEWORK_7_0', $unstable->name());
        $this->assertSame('^8.4', $unstable->policy()->min());
    }

    public function testUnstableNullAndEmptyTreatedAsNone(): void
    {
        $this->assertNull(
            BranchRoleMap::fromString("release: R\nunstable: null\n")->unstable()
        );
        $this->assertNull(
            BranchRoleMap::fromString("release: R\nunstable: ''\n")->unstable()
        );
        $this->assertNull(
            BranchRoleMap::fromString("release: R\n")->unstable()
        );
    }

    public function testMalformedSupportedScalarDegradesToEmptyList(): void
    {
        // A non-list "supported" value must not fatal; it degrades to none.
        $map = BranchRoleMap::fromString("release: R\nsupported: oops\n");

        $this->assertSame([], $map->supported());
    }

    public function testAllCollectsEveryRoleReleaseFirst(): void
    {
        $map = BranchRoleMap::fromString(<<<'YAML'
            release: FRAMEWORK_6_0
            supported:
              - FRAMEWORK_5_2
              - FRAMEWORK_5_1
            unstable: FRAMEWORK_7_0
            YAML);

        $names = array_map(
            static fn($d) => $d->name(),
            $map->all()
        );

        $this->assertSame(
            ['FRAMEWORK_6_0', 'FRAMEWORK_5_2', 'FRAMEWORK_5_1', 'FRAMEWORK_7_0'],
            $names
        );
    }

    public function testDefinitionForFindsByName(): void
    {
        $map = BranchRoleMap::fromString(<<<'YAML'
            release: FRAMEWORK_6_0
            supported:
              - FRAMEWORK_5_2:
                  min_php_version: "^7.4"
            YAML);

        $this->assertSame('FRAMEWORK_5_2', $map->definitionFor('FRAMEWORK_5_2')?->name());
        $this->assertSame('^7.4', $map->definitionFor('FRAMEWORK_5_2')?->policy()->min());
        $this->assertNull($map->definitionFor('does-not-exist'));
    }

    public function testDefinitionForNeverMatchesEmptyName(): void
    {
        // An empty release name must not match an empty-string lookup.
        $map = BranchRoleMap::empty();

        $this->assertNull($map->definitionFor(''));
    }

    public function testEmptyStringYieldsEmptyMap(): void
    {
        $map = BranchRoleMap::fromString('');

        $this->assertSame('', $map->release()->name());
        $this->assertSame([], $map->supported());
        $this->assertNull($map->unstable());
    }

    public function testCommentOnlyYieldsEmptyMap(): void
    {
        $map = BranchRoleMap::fromString("# just a comment\n");

        $this->assertSame('', $map->release()->name());
    }

    public function testTopLevelScalarYieldsEmptyMap(): void
    {
        // A top-level scalar is not a role map; degrade rather than guess.
        $map = BranchRoleMap::fromString("justascalar\n");

        $this->assertSame('', $map->release()->name());
        $this->assertSame([], $map->supported());
    }

    public function testMalformedBranchEntryThrows(): void
    {
        $this->expectException(Exception::class);
        // A multi-key map in a branch slot is ambiguous.
        BranchRoleMap::fromString(<<<'YAML'
            release:
              FRAMEWORK_6_0:
                min_php_version: "^8.2"
              FRAMEWORK_6_1:
                min_php_version: "^8.3"
            YAML);
    }

    public function testMissingFileYieldsEmptyMap(): void
    {
        $map = BranchRoleMap::load('/nonexistent/branches.yml');

        $this->assertSame('', $map->release()->name());
        $this->assertSame([], $map->supported());
        $this->assertNull($map->unstable());
    }

    public function testLoadFromExplicitFile(): void
    {
        $this->tempPath = tempnam(sys_get_temp_dir(), 'branches-') . '.yml';
        file_put_contents($this->tempPath, <<<'YAML'
            release:
              MAIN:
                max_php_version: "^8.6"
            supported:
              - OLD_1
              - OLD_2
            unstable: NEXT
            YAML);

        $map = BranchRoleMap::load($this->tempPath);

        $this->assertSame('MAIN', $map->release()->name());
        $this->assertSame('^8.6', $map->release()->policy()->max());
        $this->assertSame(
            ['OLD_1', 'OLD_2'],
            array_map(static fn($d) => $d->name(), $map->supported())
        );
        $this->assertSame('NEXT', $map->unstable()?->name());
    }

    /**
     * The live bundled data/branches.yml must parse through the structured
     * reader and classify the current collection roles.
     */
    public function testBundledFileParses(): void
    {
        $map = BranchRoleMap::load();

        $this->assertSame('FRAMEWORK_6_0', $map->release()->name());
        $this->assertSame(
            'FRAMEWORK_5_2',
            $map->definitionFor('FRAMEWORK_5_2')?->name()
        );
    }
}
