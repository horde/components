<?php

/**
 * Copyright 2017-2026 Horde LLC (http://www.horde.org/)
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
use ArrayIterator;
use IteratorAggregate;
use Stringable;

/**
 * Wrapper for the CHANGES file.
 *
 * @category   Horde
 * @package    Components
 * @author     Jan Schneider <jan@horde.org>
 * @license    http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Changes implements IteratorAggregate, Wrapper, Stringable
{
    use WrapperTrait;

    /**
     * The file contents.
     *
     * @var string
     */
    protected $_changes = [];

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
            $discontinued = 'CHANGES FILE DISCONTINUED: Use changelog.yml';
            if ($this->_changes[1] != $discontinued) {
                array_unshift($this->_changes, str_repeat('-', mb_strlen($discontinued)));
                array_unshift($this->_changes, $discontinued);
                array_unshift($this->_changes, str_repeat('-', mb_strlen($discontinued)));
            }
        }
    }

    /**
     * Returns the iterator over the changes.
     *
     * @return ArrayIterator  An iterator.
     */
    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->_changes);
    }

    /**
     * Clears the content of the CHANGES file.
     */
    public function clear(): void
    {
        $this->_changes = [];
    }

    /**
     * Adds content to the CHANGES file.
     *
     * @param string $content  Content to add.
     */
    public function add($content): void
    {
        // We no longer edit this file
        // $this->_changes[] = $content;
    }

    /**
     * Returns the file contents.
     */
    public function __toString(): string
    {
        return implode('', $this->_changes);
    }
}
