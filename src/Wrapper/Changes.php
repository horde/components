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
 * Wrapper for the CHANGES file.
 *
 * @category   Horde
 * @package    Components
 * @author     Jan Schneider <jan@horde.org>
 * @license    http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Changes implements \IteratorAggregate, Wrapper
{
    use WrapperTrait;

    /**
     * The file contents.
     *
     * @var string
     */
    protected $_changes = array();

    /**
     * Constructor.
     *
     * @param string $docDir  Directory with CHANGES.
     */
    public function __construct($docDir)
    {
        $this->_file = $docDir . '/CHANGES';
        if ($this->exists()) {
            $this->_changes = file($this->getFullPath());
        }
    }

    /**
     * Returns the iterator over the changes.
     *
     * @return \ArrayIterator  An iterator.
     */
    public function getIterator()
    {
        return new \ArrayIterator($this->_changes);
    }

    /**
     * Clears the content of the CHANGES file.
     */
    public function clear()
    {
        $this->_changes = array();
    }

    /**
     * Adds content to the CHANGES file.
     *
     * @param string $content  Content to add.
     */
    public function add($content)
    {
        $this->_changes[] = $content;
    }

    /**
     * Returns the file contents.
     */
    public function __toString()
    {
        return implode('', $this->_changes);
    }
}
