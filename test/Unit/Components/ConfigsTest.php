<?php
/**
 * Test the configuration handler.
 *
 * PHP Version 7
 *
 * @category   Horde
 * @package    Components
 * @subpackage UnitTests
 * @author     Gunnar Wrobel <wrobel@pardus.de>
 * @license    http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

namespace Horde\Components\Test\Unit\Components;

use Horde\Components\Config\File as ConfigFile;
use Horde\Components\Configs;
use Horde\Components\Test\TestCase;

/**
 * Test the configuration handler.
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
class ConfigsTest extends TestCase
{
    public function testSetOption()
    {
        $configs = new Configs();
        $configs->setOption('key', 'value');
        $options = $configs->getOptions();
        $this->assertEquals(
            'value',
            $options['key']
        );
    }

    public function testUnshiftArgument()
    {
        $configs = new Configs();
        $configs->unshiftArgument('test');
        $arguments = $configs->getArguments();
        $this->assertEquals(
            'test',
            $arguments[0]
        );
    }

    public function testBOverridesA()
    {
        $configs = new Configs();
        $configs->addConfigurationType($this->_getAConfig());
        $configs->addConfigurationType($this->_getBConfig());
        $config = $configs->getOptions();
        $this->assertEquals('B', $config['a']);
    }

    public function testAOverridesB()
    {
        $configs = new Configs();
        $configs->addConfigurationType($this->_getBConfig());
        $configs->addConfigurationType($this->_getAConfig());
        $config = $configs->getOptions();
        $this->assertEquals('A', $config['a']);
    }

    public function testPushConfig()
    {
        $configs = new Configs();
        $configs->addConfigurationType($this->_getAConfig());
        $configs->unshiftConfigurationType($this->_getBConfig());
        $config = $configs->getOptions();
        $this->assertEquals('A', $config['a']);
    }

    public function testNoNullOverride()
    {
        $configs = new Configs();
        $configs->addConfigurationType($this->_getAConfig());
        $configs->addConfigurationType($this->_getNullConfig());
        $config = $configs->getOptions();
        $this->assertEquals('A', $config['a']);
    }

    private function _getAConfig()
    {
        return new ConfigFile(
            __DIR__ . '/../../fixture/config/a.php'
        );
    }

    private function _getBConfig()
    {
        return new ConfigFile(
            __DIR__ . '/../../fixture/config/b.php'
        );
    }

    private function _getNullConfig()
    {
        return new ConfigFile(
            __DIR__ . '/../../fixture/config/null.php'
        );
    }
}
