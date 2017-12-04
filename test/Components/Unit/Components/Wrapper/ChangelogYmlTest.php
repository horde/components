<?php
/**
 * Copyright 2017 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category   Horde
 * @package    Components
 * @subpackage UnitTests
 * @author     Jan Schneider <jan@horde.org>
 * @license    http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

/**
 * Tests the changelog.yml wrapper.
 *
 * @category   Horde
 * @package    Components
 * @subpackage UnitTests
 * @author     Jan Schneider <jan@horde.org>
 * @license    http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Components_Unit_Components_Wrapper_ChangelogYmlTest
extends Components_TestCase
{
    public function testConstruct()
    {
        $changelog = new Components_Wrapper_ChangelogYml(
            __DIR__ . '/../../../fixture/deps'
        );
        $this->assertInstanceOf('ArrayObject', $changelog);
        $this->assertEmpty($changelog);
        $changelog = new Components_Wrapper_ChangelogYml(
            __DIR__ . '/../../../fixture/deps/doc/Horde/Deps'
        );
        $this->assertInstanceOf('ArrayObject', $changelog);
        $this->assertCount(1, $changelog);
    }

    public function testExists()
    {
        $changelog = new Components_Wrapper_ChangelogYml(
            __DIR__ . '/../../../fixture/deps'
        );
        $this->assertFalse($changelog->exists());
        $changelog = new Components_Wrapper_ChangelogYml(
            __DIR__ . '/../../../fixture/deps/doc/Horde/Deps'
        );
        $this->assertTrue($changelog->exists());
    }

    public function testGetFullPath()
    {
        $changelog = new Components_Wrapper_ChangelogYml(
            __DIR__ . '/../../../fixture/deps'
        );
        $this->assertEquals(
            __DIR__ . '/../../../fixture/deps/changelog.yml',
            $changelog->getFullPath()
        );
        $changelog = new Components_Wrapper_ChangelogYml(
            __DIR__ . '/../../../fixture/deps/doc/Horde/Deps'
        );
        $this->assertEquals(
            __DIR__ . '/../../../fixture/deps/doc/Horde/Deps/changelog.yml',
            $changelog->getFullPath()
        );
    }

    public function testGetLocalPath()
    {
        $changelog = new Components_Wrapper_ChangelogYml(
            __DIR__ . '/../../../fixture/deps'
        );
        $this->assertEquals(
            'changelog.yml',
            $changelog->getLocalPath(__DIR__ . '/../../../fixture/deps')
        );
        $changelog = new Components_Wrapper_ChangelogYml(
            __DIR__ . '/../../../fixture/deps/doc/Horde/Deps'
        );
        $this->assertEquals(
            'doc/Horde/Deps/changelog.yml',
            $changelog->getLocalPath(__DIR__ . '/../../../fixture/deps')
        );
    }

    public function testGetFileName()
    {
        $changelog = new Components_Wrapper_ChangelogYml(
            __DIR__ . '/../../../fixture/deps'
        );
        $this->assertEquals(
            'changelog.yml',
            $changelog->getFileName()
        );
        $changelog = new Components_Wrapper_ChangelogYml(
            __DIR__ . '/../../../fixture/deps/doc/Horde/Deps'
        );
        $this->assertEquals(
            'changelog.yml',
            $changelog->getFileName()
        );
    }

    public function testChangeProperty()
    {
        $dir = Horde_Util::createTempDir();
        copy(
            __DIR__ . '/../../../fixture/deps/doc/Horde/Deps/changelog.yml',
            $dir . '/changelog.yml'
        );
        $changelog = new Components_Wrapper_ChangelogYml($dir);
        $changelog['2.31.0']['date'] = '2017-12-31';
        $changelog->save();
        $this->assertFileEquals(
            __DIR__ . '/../../../fixture/deps/doc/Horde/Deps/changelog-new-1.yml',
            $changelog->getFullPath()
        );
    }

    public function testAddEntry()
    {
        $dir = Horde_Util::createTempDir();
        copy(
            __DIR__ . '/../../../fixture/deps/doc/Horde/Deps/changelog.yml',
            $dir . '/changelog.yml'
        );
        $changelog = new Components_Wrapper_ChangelogYml($dir);
        $entry = $changelog['2.31.0'];
        $changelog['2.31.1'] = $entry;
        $changelog->save();
        $this->assertFileEquals(
            __DIR__ . '/../../../fixture/deps/doc/Horde/Deps/changelog-new-2.yml',
            $changelog->getFullPath()
        );
    }

    public function testChangeKey()
    {
        $dir = Horde_Util::createTempDir();
        copy(
            __DIR__ . '/../../../fixture/deps/doc/Horde/Deps/changelog.yml',
            $dir . '/changelog.yml'
        );
        $changelog = new Components_Wrapper_ChangelogYml($dir);
        $changelog['2.32.0'] = $changelog['2.31.0'];
        $changelog['2.32.0']['api'] = '2.32.0';
        unset($changelog['2.31.0']);
        $changelog->save();
        $this->assertFileEquals(
            __DIR__ . '/../../../fixture/deps/doc/Horde/Deps/changelog-new-3.yml',
            $changelog->getFullPath()
        );
    }
}
