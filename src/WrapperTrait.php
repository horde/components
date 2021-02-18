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
use Horde\Components\Wrapper;
/**
 * Trait for the component file wrappers.
 *
 * @category   Horde
 * @package    Components
 * @author     Jan Schneider <jan@horde.org>
 * @license    http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
trait WrapperTrait
{
    /**
     * Full path to the file.
     *
     * @var string
     */
    protected $_file;

    /**
     * Returns the full path to the file.
     *
     * @return string  Path to the file.
     */
    public function getFullPath()
    {
        return $this->_file;
    }

    /**
     * Returns the local path to the file inside the package.
     *
     * @param string $dir  The package directory.
     *
     * @return string  Path to the file.
     */
    public function getLocalPath($dir)
    {
        return preg_replace(
            '|^' . preg_quote(rtrim($dir, '/'), '|') . '/|',
            '',
            $this->_file
        );
    }

    /**
     * Returns the file name.
     *
     * @return string  The file name.
     */
    public function getFileName()
    {
        return basename($this->_file);
    }

    /**
     * Returns whether the file exists.
     *
     * @return boolean  True if the file exists.
     */
    public function exists()
    {
        return file_exists($this->_file);
    }

    /**
     * Returns a diff between the saved and the current version of the file.
     *
     * @param Wrapper $wrapper
     *
     * @return string  File diff.
     */
    public function diff(Wrapper $wrapper = null)
    {
        $renderer = new \Horde_Text_Diff_Renderer_Unified();
        if ($wrapper) {
            $old = explode("\n", trim($wrapper, "\n"));
        } elseif ($this->exists()) {
            $old = file($this->getFullPath());
        } else {
            $old = array();
        }
        return $renderer->render(
            new \Horde_Text_Diff(
                'auto', array($old, explode("\n", rtrim($this, "\n")))
            )
        );
    }

    /**
     * Saves this object to the file.
     */
    public function save()
    {
        $contents = (string)$this;
        if (!strlen($contents) && !$this->exists()) {
            return;
        }
        if (!is_dir(dirname($this->_file))) {
            mkdir(dirname($this->_file), 0777, true);
        }
        file_put_contents($this->_file, $contents);
    }

    /**
     * Returns the file contents.
     */
    abstract public function __toString();
}
