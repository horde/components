<?php
/**
 * Components_Helper_Template:: converts a template into a target file.
 *
 * PHP Version 7
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

namespace Horde\Components\Helper;

/**
 * Components_Helper_Template:: converts a template into a target file.
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
class Template
{
    /**
     * Constructor.
     *
     * @param string $_source The source location.
     * @param string $_target The target location.
     */
    public function __construct(protected $_source, protected $_target)
    {
    }

    /**
     * Rewrite the template from the source to the target location.
     *
     * @param array  $parameters The template parameters.
     */
    public function write(array $parameters = []): void
    {
        foreach ($parameters as $key => $value) {
            ${$key} = $value;
        }
        $tdir = \dirname($this->_target);
        $target = \basename($this->_target);
        \ob_start();
        include $this->_source;
        \file_put_contents($tdir . DIRECTORY_SEPARATOR . $target, \ob_get_clean());
    }
}
