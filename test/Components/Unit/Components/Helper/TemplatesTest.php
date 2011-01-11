<?php
/**
 * Test the template machinery.
 *
 * PHP version 5
 *
 * @category   Horde
 * @package    Components
 * @subpackage UnitTests
 * @author     Gunnar Wrobel <wrobel@pardus.de>
 * @license    http://www.fsf.org/copyleft/lgpl.html LGPL
 * @link       http://pear.horde.org/index.php?package=Components
 */

/**
 * Prepare the test setup.
 */
require_once dirname(__FILE__) . '/../../../Autoload.php';

/**
 * Test the template machinery.
 *
 * Copyright 2011 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file COPYING for license information (LGPL). If you
 * did not receive this file, see http://www.fsf.org/copyleft/lgpl.html.
 *
 * @category   Horde
 * @package    Components
 * @subpackage UnitTests
 * @author     Gunnar Wrobel <wrobel@pardus.de>
 * @license    http://www.fsf.org/copyleft/lgpl.html LGPL
 * @link       http://pear.horde.org/index.php?package=Components
 */
class Components_Unit_Components_Helper_TemplatesTest
extends Components_TestCase
{
    public function testWrite()
    {
        $source = dirname(__FILE__) . '/../../../fixture/templates/simple';
        $target = $this->getTemporaryDirectory() . '/target';
        $templates = new Components_Helper_Templates($source, $target);
        $templates->write();
        $this->assertTrue(file_exists($target));
    }

    public function testSource()
    {
        $source = dirname(__FILE__) . '/../../../fixture/templates/simple';
        $target = $this->getTemporaryDirectory() . '/target';
        $templates = new Components_Helper_Templates($source, $target);
        $templates->write();
        $this->assertEquals("SIMPLE\n", file_get_contents($target));
    }

    /**
     * @expectedException Components_Exception
     */
    public function testMissingSource()
    {
        $source = dirname(__FILE__) . '/NO_SUCH_TEMPLATE';
        $templates = new Components_Helper_Templates($source, '');
    }

    public function testVariables()
    {
        $source = dirname(__FILE__) . '/../../../fixture/templates/variables';
        $target = $this->getTemporaryDirectory() . '/target';
        $templates = new Components_Helper_Templates($source, $target);
        $templates->write(array('1' => 'One', '2' => 'Two'));
        $this->assertEquals("One : Two\n", file_get_contents($target));
    }
}