<?php
/**
 * Setup autoloading for the tests.
 *
 * PHP Version 7
 *
 * Copyright 2009-2020 Horde LLC (http://www.horde.org/)
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

if (!class_exists('Components')) {
    set_include_path(dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR . 'src' . PATH_SEPARATOR . get_include_path());
}

/** Load the basic test definition */
require_once __DIR__ . '/StoryTestCase.php';
require_once __DIR__ . '/TestCase.php';

/** Load stub definitions */
require_once __DIR__ . '/Stub/Config.php';
require_once __DIR__ . '/Stub/Output.php';
require_once __DIR__ . '/Stub/OutputCli.php';
