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
     * Source template.
     *
     * @var string
     */
    protected $_source;

    /**
     * Target file.
     *
     * @var string
     */
    protected $_target;

    /**
     * Constructor.
     *
     * @param string $source     The source location.
     * @param string $target     The target location.
     */
    public function __construct($source, $target)
    {
        $this->_source = $source;
        $this->_target = $target;
    }

    /**
     * Rewrite the template from the source to the target location.
     *
     * @param array  $parameters The template parameters.
     *
     * @return void
     */
    public function write(array $parameters = array())
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