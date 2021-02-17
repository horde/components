<?php
/**
 * Test the root helper.
 *
 * PHP version 5
 *
 * @category   Horde
 * @package    Components
 * @subpackage UnitTests
 * @author     Gunnar Wrobel <wrobel@pardus.de>
 * @license    http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

/**
 * Test the root helper.
 *
 * Copyright 2011-2017 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category   Horde
 * @package    Components
 * @subpackage UnitTests
 * @author     Gunnar Wrobel <wrobel@pardus.de>
 * @license    http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Components_Unit_Components_Helper_RootTest
extends Components_TestCase
{
    /**
     * @expectedException Components_Exception
     */
    public function testInvalidCwd()
    {
        $this->changeDirectory('/');
        $root = new Components_Helper_Root();
        $root->getRoot();
    }

    public function testValidCwd()
    {
        $path = __DIR__ . '/../../../fixture';
        $this->changeDirectory($path);
        $root = new Components_Helper_Root();
        $this->assertEquals(realpath($path), realpath($root->getRoot()));
    }

    public function testValidSubCwd()
    {
        $path = __DIR__ . '/../../../fixture';
        $this->changeDirectory($path . '/horde');
        $root = new Components_Helper_Root();
        $this->assertEquals(realpath($path), realpath($root->getRoot()));
    }

    /**
     * @expectedException Components_Exception
     */
    public function testInvalidPath()
    {
        $this->changeDirectory('/');
        $root = new Components_Helper_Root('/');
        $root->getRoot();
    }

    public function testDetermineRootInTestFixture()
    {
        $path = __DIR__ . '/../../../fixture';
        $root = new Components_Helper_Root(null, null, $path);
        $this->assertEquals($path, $root->getRoot());
    }

    public function testDetermineRootInSubdirectory()
    {
        $path = __DIR__ . '/../../../fixture';
        $root = new Components_Helper_Root(null, null, $path . '/horde');
        $this->assertEquals($path, $root->getRoot());
    }

    /**
     * @expectedException Components_Exception
     */
    public function testInvalidOption()
    {
        $this->changeDirectory('/');
        $root = new Components_Helper_Root(
            array('horde_root' => '/')
        );
        $root->getRoot();
    }

    public function testDetermineRootViaOption()
    {
        $path = __DIR__ . '/../../../fixture';
        $root = new Components_Helper_Root(
            array('horde_root' => $path)
        );
        $this->assertEquals($path, $root->getRoot());
    }

    /**
     * @expectedException Components_Exception
     */
    public function testDetermineRootViaOptionSubdirectory()
    {
        $this->changeDirectory('/');
        $path = __DIR__ . '/../../../fixture';
        $root = new Components_Helper_Root(
            array('horde_root' => $path . '/horde')
        );
        $root->getRoot();
    }

    /**
     * @expectedException Horde_Exception_NotFound
     */
    public function testInvalidComponent()
    {
        $this->changeDirectory('/');
        $root = new Components_Helper_Root(null, $this->getComponent('/'));
        $root->getRoot();
    }

    public function testDetermineRootViaComponent()
    {
        $path = __DIR__ . '/../../../fixture/framework';
        $root = new Components_Helper_Root(
            null, $this->getComponent($path . '/Install')
        );
        $this->assertEquals(realpath($path), realpath($root->getRoot()));
    }

    public function testFrameworkComponent()
    {
        $path = __DIR__ . '/../../../fixture/framework';
        $root = new Components_Helper_Root(array('horde_root' => $path));
        $this->assertEquals(
            $path . '/Old/package.xml',
            $root->getPackageXml('Old')
        );
    }

    public function testFrameworkComponentTwo()
    {
        $path = __DIR__ . '/../../../fixture/framework';
        $root = new Components_Helper_Root(array('horde_root' => $path));
        $this->assertEquals(
            $path . '/Old/package.xml',
            $root->getPackageXml('Horde_Old')
        );
    }

    public function testBundleComponent()
    {
        $path = __DIR__ . '/../../../fixture/bundles';
        $root = new Components_Helper_Root(array('horde_root' => $path));
        $this->assertEquals(
            $path . '/Bundle/package.xml',
            $root->getPackageXml('Bundle')
        );
    }

    public function testApplicationComponent()
    {
        $path = __DIR__ . '/../../../fixture';
        $root = new Components_Helper_Root(array('horde_root' => $path));
        $this->assertEquals(
            $path . '/horde/package.xml',
            $root->getPackageXml('horde')
        );
    }
}
