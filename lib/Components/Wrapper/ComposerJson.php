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
 * Wrapper for the composer.json file.
 *
 * @category   Horde
 * @package    Components
 * @author     Jan Schneider <jan@horde.org>
 * @license    http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Components_Wrapper_ComposerJson extends ArrayObject
{
    use Components_Wrapper_Trait;

    /**
     * Constructor.
     *
     * @param string $baseDir  Directory with composer.json.
     */
    public function __construct($baseDir)
    {
        $this->_file = $baseDir . '/composer.json';
        if ($this->exists()) {
            try {
                $horde = Horde_Yaml::loadFile($this->_file);
            } catch (Horde_Yaml_Exception $e) {
                throw new Components_Exception($e);
            }
        } else {
            $horde = array();
        }
        parent::__construct($horde);
    }

    /**
     * Returns the file contents.
     */
    public function __toString()
    {
        return json_encode(
            iterator_to_array($this),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        ) . "\n";
    }
}
