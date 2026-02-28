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

use Horde\Components\Config\File as ConfigFile;
use Horde\Components\Constants;
use Horde\Components\Test\TestCase;

/**
 * Test the file based configuration handler.
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
class FileTest extends TestCase
{
    public function testGetOption()
    {
        $config = new ConfigFile(__DIR__ . '/../../../../config/conf.php.dist');
        $options = $config->getOptions();
        $this->assertEquals('pear.horde.org', $options['releaseserver']);
    }

    public function testArgumentsEmpty()
    {
        $config = new ConfigFile(__DIR__ . '/../../../../config/conf.php.dist');
        $this->assertEquals(
            [],
            $config->getArguments()
        );
    }

    public function testNonExistentConfigFileOption()
    {
        $config = new ConfigFile('/path/to/nonexistent/file.php');
        $options = $config->getOptions();
        $this->assertEquals([], $options);
    }
}
