<?php
/**
 * Components_Helper_Templates:: converts templates into target files.
 *
 * PHP version 5
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

/**
 * Components_Helper_Templates:: converts templates into target files.
 *
 * Copyright 2011-2017 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
abstract class Components_Helper_Templates
{
    /**
     * Rewrite the template from the source to the target location.
     *
     * @param string $source     The source location.
     * @param string $target     The target location.
     * @param array  $parameters The template(s) parameters.
     *
     * @return NULL
     */
    protected function writeSourceToTarget($source, $target, array $parameters = array())
    {
        $template = new Components_Helper_Template($source, $target);
        $template->write($parameters);
    }
}