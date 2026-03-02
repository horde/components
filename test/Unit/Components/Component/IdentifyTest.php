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

use Horde\Components\Component;
use Horde\Components\Component\Identify;
use Horde\Components\ConfigProvider\BuiltinConfigProvider;
use Horde\Components\Dependencies\Injector;
use Horde\Components\Exception;
use Horde\Components\Test\TestCase;

/**
 * Test the identification of the selected component.
 *
 * Copyright 2011-2024 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category   Horde
 * @package    Components
 * @subpackage UnitTests
 * @author     Gunnar Wrobel <wrobel@pardus.de>
 * @license    http://www.horde.org/licenses/lgpl21 LGPL 2.1
 * @coversNothing
 */
class IdentifyTest extends TestCase
{
    /**
     * @var string|null
     */
    private $oldcwd;

    /**
     * @var Component|null
     */
    private $component;

    /**
     * @var string|null
     */
    private $componentPath;

    public function tearDown(): void
    {
        if (isset($this->oldcwd) && $this->oldcwd != getcwd()) {
            chdir($this->oldcwd);
        }
    }

    public function testHelp()
    {
        $this->_initIdentify(['help']);
        // 'help' is in missing_argument list, so no component should be set
        $this->assertNull($this->component);
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
            [__DIR__ . '/../../../fixtures/framework/Install/package.xml']
        );
        $this->assertInstanceOf(
            'Horde\Components\Component\Source',
            $this->component
        );
    }

    public function testWithPackageXmlDirectory()
    {
        $this->_initIdentify(
            [__DIR__ . '/../../../fixtures/framework/Install']
        );
        $this->assertInstanceOf(
            'Horde\Components\Component\Source',
            $this->component
        );
    }

    public function testWithPackageXmlDirectoryAndSlash()
    {
        $this->_initIdentify(
            [__DIR__ . '/../../../fixtures/framework/Install/']
        );
        $this->assertInstanceOf(
            'Horde\Components\Component\Source',
            $this->component
        );
    }

    public function testWithinComponent()
    {
        $this->oldcwd = getcwd();
        chdir(__DIR__ . '/../../../fixtures/framework/Install');
        $this->_initIdentify(['test']);
        chdir($this->oldcwd);
        $this->assertInstanceOf(
            'Horde\Components\Component\Source',
            $this->component
        );
    }

    public function testWithinComponentNoAction()
    {
        $this->oldcwd = getcwd();
        chdir(__DIR__ . '/../../../fixtures/framework/Install');
        $this->_initIdentify([]);
        chdir($this->oldcwd);
        $this->assertInstanceOf(
            'Horde\Components\Component\Source',
            $this->component
        );
    }

    public function testWithoutValidComponent()
    {
        $this->expectException(Exception::class);
        $this->_initIdentify(
            [__DIR__ . '/../../../fixtures/DOESNOTEXIST']
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

        // Create a minimal ConfigProvider for testing
        $configProvider = new BuiltinConfigProvider($options);
        $componentFactory = $dependencies->getComponentFactory();

        $identify = new Identify(
            $configProvider,
            [
                'list' => ['test'],
                'missing_argument' => ['help'],
            ],
            $componentFactory
        );

        [$this->component, $this->componentPath, $remainingArgs] = $identify->identifyComponent(
            $arguments,
            getcwd()
        );
    }
}
