<?php
/**
 * Test the root helper.
 *
 * PHP Version 7
 *
 * @category   Horde
 * @package    Components
 * @subpackage UnitTests
 * @author     Gunnar Wrobel <wrobel@pardus.de>
 * @license    http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

namespace Horde\Components\Unit\Components\Helper;

use Horde\Components\Exception;
use Horde\Components\Helper\Root as HelperRoot;
use Horde\Components\Test\TestCase;

/**
 * Test the root helper.
 *
 * Copyright 2011-2020 Horde LLC (http://www.horde.org/)
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
class RootTest extends TestCase
{
    public function testInvalidCwd()
    {
        $this->expectException(Exception::class);
        $this->changeDirectory('/');
        $root = new HelperRoot();
        $root->getRoot();
    }

    public function testValidCwd()
    {
        $path = __DIR__ . '/../../../fixture';
        $this->changeDirectory($path);
        $root = new HelperRoot();
        $this->assertEquals(realpath($path), realpath($root->getRoot()));
    }

    public function testValidSubCwd()
    {
        $path = __DIR__ . '/../../../fixture';
        $this->changeDirectory($path . '/horde');
        $root = new HelperRoot();
        $this->assertEquals(realpath($path), realpath($root->getRoot()));
    }

    public function testInvalidPath()
    {
        $this->expectException(Exception::class);
        $this->changeDirectory('/');
        $root = new HelperRoot('/');
        $root->getRoot();
    }

    public function testDetermineRootInTestFixture()
    {
        $path = __DIR__ . '/../../../fixture';
        $root = new HelperRoot(null, null, $path);
        $this->assertEquals($path, $root->getRoot());
    }

    public function testDetermineRootInSubdirectory()
    {
        $path = __DIR__ . '/../../../fixture';
        $root = new HelperRoot(null, null, $path . '/horde');
        $this->assertEquals($path, $root->getRoot());
    }

    public function testInvalidOption()
    {
        $this->expectException(Exception::class);
        $this->changeDirectory('/');
        $root = new HelperRoot(
            ['horde_root' => '/']
        );
        $root->getRoot();
    }

    public function testDetermineRootViaOption()
    {
        $path = __DIR__ . '/../../../fixture';
        $root = new HelperRoot(
            ['horde_root' => $path]
        );
        $this->assertEquals($path, $root->getRoot());
    }

    public function testDetermineRootViaOptionSubdirectory()
    {
        $this->expectException(Exception::class);
        $this->changeDirectory('/');
        $path = __DIR__ . '/../../../fixture';
        $root = new HelperRoot(
            ['horde_root' => $path . '/horde']
        );
        $root->getRoot();
    }

    public function testInvalidComponent()
    {
        $this->expectException(\Horde_Exception_NotFound::class);
        $this->changeDirectory('/');
        $root = new HelperRoot(null, $this->getComponent('/'));
        $root->getRoot();
    }

    public function testDetermineRootViaComponent()
    {
        $path = __DIR__ . '/../../../fixture/framework';
        $root = new HelperRoot(
            null,
            $this->getComponent($path . '/Install')
        );
        $this->assertEquals(realpath($path), realpath($root->getRoot()));
    }

    public function testFrameworkComponent()
    {
        $path = __DIR__ . '/../../../fixture/framework';
        $root = new HelperRoot(['horde_root' => $path]);
        $this->assertEquals(
            $path . '/Old/package.xml',
            $root->getPackageXml('Old')
        );
    }

    public function testFrameworkComponentTwo()
    {
        $path = __DIR__ . '/../../../fixture/framework';
        $root = new HelperRoot(['horde_root' => $path]);
        $this->assertEquals(
            $path . '/Old/package.xml',
            $root->getPackageXml('Horde_Old')
        );
    }

    public function testBundleComponent()
    {
        $path = __DIR__ . '/../../../fixture/bundles';
        $root = new HelperRoot(['horde_root' => $path]);
        $this->assertEquals(
            $path . '/Bundle/package.xml',
            $root->getPackageXml('Bundle')
        );
    }

    public function testApplicationComponent()
    {
        $path = __DIR__ . '/../../../fixture';
        $root = new HelperRoot(['horde_root' => $path]);
        $this->assertEquals(
            $path . '/horde/package.xml',
            $root->getPackageXml('horde')
        );
    }
}
