<?php
/**
 * Components_Release_Task_TagRelease:: tags the git repository.
 *
 * PHP version 5
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.fsf.org/copyleft/lgpl.html LGPL
 * @link     http://pear.horde.org/index.php?package=Components
 */

/**
 * Components_Release_Task_TagRelease:: tags the git repository.
 *
 * Copyright 2011 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file COPYING for license information (LGPL). If you
 * did not receive this file, see http://www.fsf.org/copyleft/lgpl.html.
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.fsf.org/copyleft/lgpl.html LGPL
 * @link     http://pear.horde.org/index.php?package=Components
 */
class Components_Release_Task_TagRelease
extends Components_Release_Task_Base
{
    /**
     * Run the task.
     *
     * @param array $options Additional options.
     *
     * @return NULL
     */
    public function run($options)
    {
        $release = $this->getPackage()->getName() 
            . '-' . $this->getPackage()->getVersion();
        $this->systemInDirectory(
            'git tag -f -m "Released ' . $release . '." ' . strtolower($release),
            $this->getPackage()->getComponentDirectory()
        );
    }
}