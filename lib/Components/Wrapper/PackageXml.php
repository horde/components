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

/**
 * Wrapper for the package.xml file.
 *
 * @category   Horde
 * @package    Components
 * @author     Jan Schneider <jan@horde.org>
 * @license    http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Components_Wrapper_PackageXml
extends Horde_Pear_Package_Xml
implements Components_Wrapper
{
    use Components_Wrapper_Trait;

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
