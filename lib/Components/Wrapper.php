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
 * Interface for the component file wrappers.
 *
 * @category   Horde
 * @package    Components
 * @author     Jan Schneider <jan@horde.org>
 * @license    http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
interface Components_Wrapper
{
    /**
     * Returns the full path to the file.
     *
     * @return string  Path to the file.
     */
    public function getFile();

    /**
     * Returns whether the file exists.
     *
     * @return boolean  True if the file exists.
     */
    public function exists();

    /**
     * Returns a diff between the saved and the current version of the file.
     *
     * @return string  File diff.
     */
    public function diff();

    /**
     * Saves this object to the file.
     */
    public function save();

    /**
     * Returns the file contents.
     */
    public function __toString();
}
