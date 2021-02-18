<?php
/**
 * Copyright 2017 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category   Horde
 * @package    Components
 * @author     Jan Schneider <jan@horde.org>
 * @license    http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
namespace Horde\Components\Wrapper;
use Horde\Components\Exception;
use Horde\Components\Wrapper;
use Horde\Components\WrapperTrait;

/**
 * Wrapper for the package.xml file.
 *
 * @category   Horde
 * @package    Components
 * @author     Jan Schneider <jan@horde.org>
 * @license    http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class PackageXml extends \Horde_Pear_Package_Xml
implements Wrapper
{
    use WrapperTrait;

    /**
     * Constructor.
     *
     * @param string $baseDir  Directory with composer.json.
     */
    public function __construct($baseDir)
    {
        $this->_file = $baseDir . '/package.xml';
        if ($this->exists()) {
            parent::__construct($this->_file);
        }
    }
}
