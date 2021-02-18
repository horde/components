<?php
/**
 * Config\File:: class provides simple options for the bootstrap
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
 * Config\File:: class provides simple options for the bootstrap
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
class File
extends Base
{
    /**
     * Constructor.
     *
     * @param string $path The path to the configuration file.
     */
    public function __construct($path)
    {
        if (file_exists($path)) {
            include $path;
            $this->_options = $conf;
        } else {
            $this->_options = array();
        }
        $this->_arguments = array();
    }
}
