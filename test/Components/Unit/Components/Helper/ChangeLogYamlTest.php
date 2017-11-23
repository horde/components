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
 * Tests the changelog.yml handler.
 *
 * @category   Horde
 * @package    Components
 * @subpackage UnitTests
 * @author     Jan Schneider <jan@horde.org>
 * @license    http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Components_Unit_Components_Helper_ChangeLogYamlTest
extends Components_TestCase
{
    public function testConstruct()
    {
        $changelog = new Components_Helper_ChangeLog_Yaml(
            __DIR__ . '/../../../fixture/deps'
        );
        $this->assertInstanceOf('ArrayObject', $changelog);
        $this->assertEmpty($changelog);
        $changelog = new Components_Helper_ChangeLog_Yaml(
            __DIR__ . '/../../../fixture/deps/doc/Horde/Deps'
        );
        $this->assertInstanceOf('ArrayObject', $changelog);
        $this->assertCount(1, $changelog);
    }

    public function testExists()
    {
        $changelog = new Components_Helper_ChangeLog_Yaml(
            __DIR__ . '/../../../fixture/deps'
        );
        $this->assertFalse($changelog->exists());
        $changelog = new Components_Helper_ChangeLog_Yaml(
            __DIR__ . '/../../../fixture/deps/doc/Horde/Deps'
        );
        $this->assertTrue($changelog->exists());
    }

    public function testGetFile()
    {
        $changelog = new Components_Helper_ChangeLog_Yaml(
            __DIR__ . '/../../../fixture/deps'
        );
        $this->assertEquals(
            __DIR__ . '/../../../fixture/deps/changelog.yml',
            $changelog->getFile()
        );
        $changelog = new Components_Helper_ChangeLog_Yaml(
            __DIR__ . '/../../../fixture/deps/doc/Horde/Deps'
        );
        $this->assertEquals(
            __DIR__ . '/../../../fixture/deps/doc/Horde/Deps/changelog.yml',
            $changelog->getFile()
        );
    }

    public function testChangeProperty()
    {
        $dir = Horde_Util::createTempDir();
        copy(
            __DIR__ . '/../../../fixture/deps/doc/Horde/Deps/changelog.yml',
            $dir . '/changelog.yml'
        );
        $changelog = new Components_Helper_ChangeLog_Yaml($dir);
        $changelog['2.31.0']['date'] = '2017-12-31';
        $changelog->save();
        $this->assertFileEquals(
            __DIR__ . '/../../../fixture/deps/doc/Horde/Deps/changelog-new-1.yml',
            $changelog->getFile()
        );
    }

    public function testAddEntry()
    {
        $dir = Horde_Util::createTempDir();
        copy(
            __DIR__ . '/../../../fixture/deps/doc/Horde/Deps/changelog.yml',
            $dir . '/changelog.yml'
        );
        $changelog = new Components_Helper_ChangeLog_Yaml($dir);
        $entry = $changelog['2.31.0'];
        $changelog['2.31.1'] = $entry;
        $changelog->save();
        $this->assertFileEquals(
            __DIR__ . '/../../../fixture/deps/doc/Horde/Deps/changelog-new-2.yml',
            $changelog->getFile()
        );
    }

    public function testChangeKey()
    {
        $dir = Horde_Util::createTempDir();
        copy(
            __DIR__ . '/../../../fixture/deps/doc/Horde/Deps/changelog.yml',
            $dir . '/changelog.yml'
        );
        $changelog = new Components_Helper_ChangeLog_Yaml($dir);
        $changelog['2.32.0'] = $changelog['2.31.0'];
        $changelog['2.32.0']['api'] = '2.32.0';
        unset($changelog['2.31.0']);
        $changelog->save();
        $this->assertFileEquals(
            __DIR__ . '/../../../fixture/deps/doc/Horde/Deps/changelog-new-3.yml',
            $changelog->getFile()
        );
    }
}
