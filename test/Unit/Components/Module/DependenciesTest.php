<?php
/**
 * Test the Dependencies module.
 *
 * PHP Version 7
 *
 * @category   Horde
 * @package    Components
 * @subpackage UnitTests
 * @author     Gunnar Wrobel <wrobel@pardus.de>
 * @license    http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
namespace Horde\Components\Unit\Components\Module;
use Horde\Components\TestCase;

/**
 * Test the Dependencies module.
 *
 * Copyright 2010-2020 Horde LLC (http://www.horde.org/)
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
class DependenciesTest extends TestCase
{
    public function testDependenciesOption()
    {
        $this->assertRegExp('/-L,\s*--list-deps/', $this->getHelp());
    }

    public function testDependenciesAction()
    {
        $this->assertRegExp('/ACTION "deps"/', $this->getActionHelp('deps'));
    }

    public function testDependencies()
    {
        $_SERVER['argv'] = array(
            'horde-components',
            '--list-deps',
            __DIR__ . '/../../../fixture/framework/Install'
        );
        $this->assertContains(
            'Dependency-0.0.1', $this->_callUnstrictComponents()
        );
    }

    public function testAllDependencies()
    {
        $_SERVER['argv'] = array(
            'horde-components',
            '--list-deps',
            '--alldeps',
            __DIR__ . '/../../../fixture/framework/Install'
        );
        $this->assertContains(
            '_Console_Getopt', $this->_callUnstrictComponents()
        );
    }

    public function testShortDependencies()
    {
        $_SERVER['argv'] = array(
            'horde-components',
            '--list-deps',
            '--alldeps',
            '--short',
            __DIR__ . '/../../../fixture/framework/Install'
        );
        $this->assertContains(
            'Console_Getopt', $this->_callUnstrictComponents()
        );
    }

}
