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
use Horde\Components\Wrapper;
use Horde\Components\WrapperTrait;
/**
 * Wrapper for the Application.php/Bundle.php files.
 *
 * @category   Horde
 * @package    Components
 * @author     Jan Schneider <jan@horde.org>
 * @license    http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class ApplicationPhp implements Wrapper
{
    use WrapperTrait;

    /**
     * Regular expression to find bundle version.
     */
    const BUNDLE_REGEXP = '/const VERSION = \'([^\']*)\';/';

    /**
     * Regular expression to find application version.
     */
    const APPLICATION_REGEXP = '/public \$version = \'([^\']*)\';/';

    /**
     * The file contents.
     *
     * @var string
     */
    protected $_contents = '';

    /**
     * Constructor.
     *
     * @param string $baseDir  Directory with lib/(Application|Bundle).php.
     */
    public function __construct($baseDir)
    {
        $this->_file = $baseDir . '/lib/Bundle.php';
        if (!$this->exists()) {
            $this->_file = $baseDir . '/lib/Application.php';
        }
        if ($this->exists()) {
            $this->_contents = file_get_contents($this->getFullPath());
        }
    }

    /**
     * Returns the file contents.
     */
    public function __toString()
    {
        return $this->_contents;
    }

    /**
     * Returns whether this is a wrapper around a lib/Bundle.php.
     *
     * @return boolean  True if this is a Bundle.php wrapper.
     */
    public function isBundle()
    {
        return (bool)strpos($this->_file, '/Bundle.php');
    }

    /**
     * Returns the current version.
     *
     * @return string  The current version.
     */
    public function getVersion()
    {
        if (!$this->exists()) {
            return;
        }
        if ($this->isBundle()) {
            if (preg_match(self::BUNDLE_REGEXP, $this->_contents, $match)) {
                return $match[1];
            }
        } else {
            if (preg_match(self::APPLICATION_REGEXP, $this->_contents, $match)) {
                return $match[1];
            }
        }
    }

    /**
     * Sets the current version.
     *
     * @param string $version  The new version.
     */
    public function setVersion($version)
    {
        if ($this->isBundle()) {
            $this->_contents = preg_replace(
                self::BUNDLE_REGEXP,
                'const VERSION = \'' . $version . '\';',
                $this->_contents
            );
        } else {
            $this->_contents = preg_replace(
                self::APPLICATION_REGEXP,
                'public \$version = \'' . $version . '\';',
                $this->_contents
            );
        }
    }
}
