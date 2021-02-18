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
namespace Horde\Components;

/**
 * Interface for the component file wrappers.
 *
 * @category   Horde
 * @package    Components
 * @author     Jan Schneider <jan@horde.org>
 * @license    http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
interface Wrapper
{

    /**
     * Returns the full path to the file.
     *
     * @return string  Path to the file.
     */
    public function getFullPath();

    /**
     * Returns the local path to the file inside the package.
     *
     * @param string $dir  The package directory.
     *
     * @return string  Path to the file.
     */
    public function getLocalPath($dir);

    /**
     * Returns the file name.
     *
     * @return string  The file name.
     */
    public function getFileName();

    /**
     * Returns whether the file exists.
     *
     * @return boolean  True if the file exists.
     */
    public function exists();

    /**
     * Returns a diff between the saved and the current version of the file.
     *
     * @param Wrapper $wrapper  Use this instead of the saved version.
     *
     * @return string  File diff.
     */
    public function diff(self $wrapper = null);

    /**
     * Saves this object to the file.
     */
    public function save();

    /**
     * Returns the file contents.
     */
    public function __toString();
}
