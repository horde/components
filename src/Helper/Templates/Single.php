<?php
/**
 * Components_Helper_Templates_Single:: converts a single template file into a
 * target file.
 *
 * PHP Version 7
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
namespace Horde\Components\Helper\Templates;
use Horde\Components\Helper\Templates;
use Horde\Components\Exception;

/**
 * Components_Helper_Templates_Single:: converts a single template file into a
 * target file.
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
class Single extends Templates
{
    /**
     * The source location.
     *
     * @var string
     */
    private $_source;

    /**
     * The target location.
     *
     * @var string
     */
    private $_target;

    /**
     * Constructor.
     *
     * @param string $sdir  The templates source directory.
     * @param string $tdir  The templates target directory.
     * @param string $sfile The exact template source file.
     * @param string $tfile The exact template target file.
     */
    public function __construct($sdir, $tdir, $sfile, $tfile)
    {
        $source = $sdir . DIRECTORY_SEPARATOR . $sfile . '.template';
        if (file_exists($source)) {
            $this->_source = $source;
        } else {
            throw new Exception("No template at $source!");
        }
        $this->_target = $tdir . DIRECTORY_SEPARATOR . $tfile;
    }

    /**
     * Rewrite the template(s) from the source(s) to the target location(s).
     *
     * @param array  $parameters The template(s) parameters.
     *
     * @return void
     */
    public function write(array $parameters = array())
    {
        $this->writeSourceToTarget($this->_source, $this->_target, $parameters);
    }
}