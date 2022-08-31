<?php
/**
 * Test the identification of the selected component.
 *
 * PHP Version 7
 *
 * @category   Horde
 * @package    Components
 * @subpackage UnitTests
 * @author     Gunnar Wrobel <wrobel@pardus.de>
 * @license    http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

namespace Horde\Components\Unit\Components\Component;

use Horde\Components\Component\Identify;
use Horde\Components\Dependencies\Injector;
use Horde\Components\Exception;
use Horde\Components\Test\Stub\Config;
use Horde\Components\Test\TestCase;

/**
 * Test the identification of the selected component.
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
class IdentifyTest extends TestCase
{
    public function tearDown(): void
    {
        if (isset($this->oldcwd) && $this->oldcwd != getcwd()) {
            chdir($this->oldcwd);
        }
    }

    public function testHelp()
    {
        $this->expectException(Exception::class);
        $this->_initIdentify(['help']);
        $this->config->getComponent();
    }

    public function testNoArgument()
    {
        $this->expectException(Exception::class);
        $this->oldcwd = getcwd();
        // cwd cannot be inside any component
        chdir('/');
        $this->_initIdentify([]);
        chdir($this->oldcwd);
    }

    public function testWithPackageXml()
    {
        $this->_initIdentify(
            [__DIR__ . '/../../../fixture/framework/Install/package.xml']
        );
        $this->assertInstanceOf(
            'Horde\Components\Component\Source',
            $this->config->getComponent()
        );
    }

    public function testWithPackageXmlDirectory()
    {
        $this->_initIdentify(
            [__DIR__ . '/../../../fixture/framework/Install']
        );
        $this->assertInstanceOf(
            'Horde\Components\Component\Source',
            $this->config->getComponent()
        );
    }

    public function testWithPackageXmlDirectoryAndSlash()
    {
        $this->_initIdentify(
            [__DIR__ . '/../../../fixture/framework/Install/']
        );
        $this->assertInstanceOf(
            'Horde\Components\Component\Source',
            $this->config->getComponent()
        );
    }

    public function testWithinComponent()
    {
        $this->oldcwd = getcwd();
        chdir(__DIR__ . '/../../../fixture/framework/Install');
        $this->_initIdentify(['test']);
        chdir($this->oldcwd);
        $this->assertInstanceOf(
            'Horde\Components\Component\Source',
            $this->config->getComponent()
        );
    }

    public function testWithinComponentNoAction()
    {
        $this->oldcwd = getcwd();
        chdir(__DIR__ . '/../../../fixture/framework/Install');
        $this->_initIdentify([]);
        chdir($this->oldcwd);
        $this->assertInstanceOf(
            'Horde\Components\Component\Source',
            $this->config->getComponent()
        );
    }

    public function testWithoutValidComponent()
    {
        $this->expectException(Exception::class);
        $this->_initIdentify(
            [__DIR__ . '/../../../fixture/DOESNOTEXIST']
        );
    }

    private function _initIdentify(
        $arguments,
        $options = [],
        $dependencies = null
    ) {
        if ($dependencies === null) {
            $dependencies = new Injector();
        }
        $this->config = new Config($arguments, $options);
        $dependencies->initConfig($this->config);
        $identify = new Identify(
            $this->config,
            [
                'list' => ['test'],
                'missing_argument' => ['help']
            ],
            $dependencies
        );
        $identify->setComponentInConfiguration();
    }
}
