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
use Horde\Components\TestCase;
use Horde\Components\Dependencies\Injector;
use Horde\Components\Component\Identify;
use Horde\Components\Stub\Config;

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
class CIdentifyTest extends TestCase
{
    public function tearDown()
    {
        if (isset($this->oldcwd) && $this->oldcwd != getcwd()) {
            chdir($this->oldcwd);
        }
    }

    /**
     * @expectedException Horde\Components\Exception
     */
    public function testHelp()
    {
        $this->_initIdentify(array('help'));
        $this->config->getComponent();
    }

    /**
     * This test does not yield an exception anymore because
     * the identifyer would find the Components app from the subdir
     * Rewriting Test to prove that.
     * 
     * #expectedException Horde\Components\Exception
     */
    public function testNoArgument()
    {
        $this->oldcwd = getcwd();
        chdir(__DIR__ . '/../../../fixture/');
        $this->_initIdentify(array());
        chdir($this->oldcwd);
    }

    public function testWithPackageXml()
    {
        $this->_initIdentify(
            array(__DIR__ . '/../../../fixture/framework/Install/package.xml')
        );
        $this->assertInstanceOf(
            'Horde\Components\Component\Source',
            $this->config->getComponent()
        );
    }

    public function testWithPackageXmlDirectory()
    {
        $this->_initIdentify(
            array(__DIR__ . '/../../../fixture/framework/Install')
        );
        $this->assertInstanceOf(
            'Horde\Components\Component\Source',
            $this->config->getComponent()
        );
    }

    public function testWithPackageXmlDirectoryAndSlash()
    {
        $this->_initIdentify(
            array(__DIR__ . '/../../../fixture/framework/Install/')
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
        $this->_initIdentify(array('test'));
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
        $this->_initIdentify(array());
        chdir($this->oldcwd);
        $this->assertInstanceOf(
            'Horde\Components\Component\Source',
            $this->config->getComponent()
        );
    }

    /**
     * #expectedException Horde\Components\Exception
     */
/* This test might fail by now as we now can identify Components itself from the fixture dir
    public function testWithoutValidComponent()
    {
        $this->_initIdentify(
            array(__DIR__ . '/../../../fixture/DOESNOTEXIST')
        );
    }*/

    private function _initIdentify(
        $arguments, $options = array(), $dependencies = null
    )
    {
        if ($dependencies === null) {
            $dependencies = new Injector();
        }
        $this->config = new Config($arguments, $options);
        $dependencies->initConfig($this->config);
        $identify = new Identify(
            $this->config,
            array(
                'list' => array('test'),
                'missing_argument' => array('help')
            ),
            $dependencies
        );
        $identify->setComponentInConfiguration();
    }

}
