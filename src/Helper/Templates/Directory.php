<?php
/**
 * Components_Helper_Templatesdirectory:: converts template files from a
 * directory into files in a target directory.
 *
 * PHP Version 7
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

namespace Horde\Components\Helper\Templates;

use Horde\Components\Exception;
use Horde\Components\Helper\Templates;

/**
 * Components_Helper_Templatesdirectory:: converts template files from a
 * directory into files in a target directory.
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
class Directory extends Templates
{
    /**
     * The source location.
     */
    private ?string $_source = null;

    /**
     * Constructor.
     *
     * @param string $sdir  The templates source directory.
     * @param string $_target The templates target directory.
     */
    public function __construct($sdir, private $_target)
    {
        if (file_exists($sdir)) {
            $this->_source = $sdir;
        } else {
            throw new Exception("No template directory at $sdir!");
        }
    }

    /**
     * Rewrite the template(s) from the source(s) to the target location(s).
     *
     * @param array  $parameters The template(s) parameters.
     */
    public function write(array $parameters = []): void
    {
        if (!file_exists($this->_target)) {
            mkdir($this->_target, 0777, true);
        }
        foreach (
            new \IteratorIterator(new \DirectoryIterator($this->_source))
            as $file
        ) {
            if ($file->isFile()) {
                $this->writeSourceToTarget(
                    $file->getPathname(),
                    $this->_target . DIRECTORY_SEPARATOR . $file->getBasename(),
                    $parameters
                );
            }
        }
    }
}
