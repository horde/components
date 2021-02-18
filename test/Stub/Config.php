<?php
/**
 * Test Stub for the Config interface
 *
 * PHP version 7
 *
 * @category   Horde
 * @package    Components
 * @subpackage UnitTests
 * @author     Gunnar Wrobel <wrobel@pardus.de>
 * @author     Ralf Lang <lang@b1-systems.de>
 * @license    http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
namespace Horde\Components\Stub;
use Horde\Components\Config\Base;

class Config extends Base
{
    public function __construct($arguments, $options)
    {
        $this->_arguments = $arguments;
        $this->_options = $options;
    }
}