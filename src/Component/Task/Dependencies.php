<?php
/**
 * Horde\Components\Component\Task\Dependencies:: Declare and receive dependencies
 *
 * PHP version 7
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <lang@b1-systems.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */


namespace Horde\Components\Component\Task;
/**
 * Components\Component\Task\Dependencies:: Declare and receive dependencies
 *
 * Copyright 2011-2019 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <lang@b1-systems.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
trait Dependencies
{
    protected $_deps = [];

    /**
     * Ask for dependencies.
     * 
     * Declares which helpers or other utilities the task wants to have
     * 
     * The request format is an associative array.
     * The key will be reused by the answer from outside, the value is a string
     * identifying the base class or interface of the dependency.
     * 
     * As long as the injector knows how to provide the dependencies,
     * there is no need for individual factories or annotations per task.
     *
     * Dependencies unfulfilled should be returned as null values for the keys.
     * 
     * The task should decide itself if this is fatal or optional
     * 
     * @return string[] The dictionary of dependencies.
     */
    public function askDependencies()
    {
        return [];
    }

    /**
     * Receive dependencies
     * 
     * The format contains the same keys ad askDependencies, but the values
     * are instances of the requested types or null if unfulfilled.
     * 
     * @param array $dependencies The provided dependencies.
     * 
     * @return void
     */
    public function setDependencies(array $dependencies)
    {
        $this->_deps = $dependencies;
    }

    /**
     * Retrieve a dependency
     * 
     * It must have been registered by askDependencies and the calling code
     * should provide it or null
     * 
     * @param string $key The dependency to get.
     * 
     * @return object|null The dependency or null
     */
    public function getDependency($key)
    {
        return $this->_deps[$key] ?? null;
    }
}