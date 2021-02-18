<?php
/**
 * Test the file based configuration handler.
 *
 * PHP Version 7
 *
 * @category   Horde
 * @package    Components
 * @subpackage UnitTests
 * @author     Gunnar Wrobel <wrobel@pardus.de>
 * @license    http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
namespace Horde\Components\Unit\Components\Config;
use Horde\Components\TestCase;

use Horde\Components\Constants;
use Horde\Components\Config\File as ConfigFile;

/**
 * Test the file based configuration handler.
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
class FileTest extends TestCase
{
    public function testGetOption()
    {
        $config = $this->_getFileConfig();
        $options = $config->getOptions();
        $this->assertEquals('pear.horde.org', $options['releaseserver']);
    }

    public function testArgumentsEmpty()
    {
        $this->assertEquals(
            array(),
            $this->_getFileConfig()->getArguments()
        );
    }

    private function _getFileConfig()
    {
        $path = Constants::getConfigFile();
        return new ConfigFile(
            $path . '.dist'
        );
    }
}
