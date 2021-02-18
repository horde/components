<?php
/**
 * Components_Helper_Templates:: converts templates into target files.
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
 * Components_Helper_Templates:: converts templates into target files.
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
abstract class Templates
{
    /**
     * Rewrite the template from the source to the target location.
     *
     * @param string $source     The source location.
     * @param string $target     The target location.
     * @param array  $parameters The template(s) parameters.
     *
     * @return void
     */
    protected function writeSourceToTarget($source, $target, array $parameters = array())
    {
        $template = new Template($source, $target);
        $template->write($parameters);
    }
}