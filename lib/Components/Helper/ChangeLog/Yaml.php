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
 * Wrapper for the changelog.yml file.
 *
 * @category   Horde
 * @package    Components
 * @author     Jan Schneider <jan@horde.org>
 * @license    http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Components_Helper_ChangeLog_Yaml extends ArrayObject
{
    /**
     * Name of the changelog.yml file.
     */
    const CHANGELOG = '/changelog.yml';

    /**
     * Full path to changelog.yml file.
     *
     * @var string
     */
    protected $_file;

    /**
     * Constructor.
     *
     * @param string $docDir  Directory with changelog.yml.
     */
    public function __construct($docDir)
    {
        $this->_file = $docDir . self::CHANGELOG;
        if ($this->exists()) {
            $changelog = Horde_Yaml::loadFile($this->_file);
        } else {
            $changelog = array();
        }
        parent::__construct($changelog);
    }

    /**
     * Returns the full path to the changelog.yml file.
     *
     * @return string  Path to changelog.yml.
     */
    public function getFile()
    {
        return $this->_file;
    }

    /**
     * Returns whether the changelog.yml file exists.
     *
     * @return boolean  True if changelog.yml exists.
     */
    public function exists()
    {
        return file_exists($this->_file);
    }

    /**
     * Saves this object to changelog.yml.
     */
    public function save()
    {
        file_put_contents(
            $this->_file,
            Horde_Yaml::dump(iterator_to_array($this), array('wordwrap' => 0))
        );
    }
}
