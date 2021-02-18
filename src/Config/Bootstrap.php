<?php
/**
 * Bootstrap:: class provides simple options for the bootstrap
 * process.
 *
 * PHP Version 7
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
namespace Horde\Components\Config;
use Horde\Components\Config;

/**
 * Bootstrap:: class provides simple options for the bootstrap
 * process.
 *
 * Copyright 2011-2020 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Bootstrap
extends Base
{
    /**
     * Constructor.
     *
     */
    public function __construct()
    {
        $this->_options = array(
            'instructions' => array(
                'ALL' => array('include' => true),
                'channel:pecl.php.net' => array('exclude' => true),
            ),
            'force' => true,
            'symlink' => true,
        );
        $this->_arguments = array();
    }
}
