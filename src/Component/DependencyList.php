<?php
/**
 * Horde\Components\Component\Dependencies:: provides dependency handling mechanisms.
 *
 * PHP Version 7
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
namespace Horde\Components\Component;
use Horde\Components\Component;
/**
 * Horde\Components\Component\Dependencies:: provides dependency handling mechanisms.
 *
 * Copyright 2010-2020 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class DependencyList
implements \Iterator
{
    /**
     * The component.
     *
     * @param Component
     */
    private $_component;

    /**
     * Factory helper.
     *
     * @param Factory
     */
    private $_factory;

    /**
     * The dependency list.
     *
     * @param array
     */
    private $_dependencies;

    /**
     * Constructor.
     *
     * @param Component         $component The component.
     * @param Factory $factory   Generator for dependency
     *                                                representations.
     */
    public function __construct(
        Component $component,
        Factory $factory
    )
    {
        $this->_component = $component;
        $this->_factory = $factory;
    }

    /**
     * Return all channels required for the component and its dependencies.
     *
     * @return array The list of channels.
     */
    public function listAllChannels()
    {
        $channel = array();
        foreach ($this->_component->getDependencies() as $dependency) {
            if (isset($dependency['channel'])) {
                $channel[] = $dependency['channel'];
            }
        }
        $channel[] = $this->_component->getChannel();
        return array_unique($channel);
    }    

    /**
     * Return all dependencies for this package.
     *
     * @return array The list of dependencies.
     */
    private function _getDependencies()
    {
        if ($this->_dependencies === null) {
            $dependencies = $this->_component->getDependencies();
            if (empty($dependencies)) {
                $this->_dependencies = array();
            }
            foreach ($dependencies as $dependency) {
                $instance = $this->_factory->createDependency($dependency);
                $this->_dependencies[$instance->key()] = $instance;
            }
        }
        return $this->_dependencies;
    }

    /**
     * Implementation of the Iterator rewind() method. Rewinds the dependency list.
     *
     * return void
     */
    public function __get($key)
    {
        $dependencies = $this->_getDependencies();
        if (isset($dependencies[$key])) {
            return $dependencies[$key];
        }
    }

    /**
     * Implementation of the Iterator rewind() method. Rewinds the dependency list.
     *
     * return void
     */
    public function rewind()
    {
        $this->_getDependencies();
        return reset($this->_dependencies);
    }

    /**
     * Implementation of the Iterator current(). Returns the current dependency.
     *
     * @return Dependency|null The current dependency.
     */
    public function current()
    {
        return current($this->_dependencies);
    }

    /**
     * Implementation of the Iterator key() method. Returns the key of the current dependency.
     *
     * @return mixed The key for the current position.
     */
    public function key()
    {
        return key($this->_dependencies);
    }

    /**
     * Implementation of the Iterator next() method. Returns the next dependency.
     *
     * @return Dependency|null The next
     * dependency or null if there are no more dependencies.
     */
    public function next()
    {
        return next($this->_dependencies);
    }

    /**
     * Implementation of the Iterator valid() method. Indicates if the current element is a valid element.
     *
     * @return boolean Whether the current element is valid
     */
    public function valid()
    {
        return key($this->_dependencies) !== null;
    }

}