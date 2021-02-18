<?php
/**
 * Base:: provides common utilities for the configuration
 * handlers.
 *
 * PHP Version 7
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
namespace Horde\Components\Config;
use Horde\Components\Config;
use Horde\Components\Component;
use Horde\Components\Exception;

/**
 * Base:: provides common utilities for the configuration
 * handlers.
 *
 * Copyright 2009-2020 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
abstract class Base
implements Config
{
    /**
     * Additional options.
     *
     * @var array
     */
    protected $_options = array();

    /**
     * Additional arguments.
     *
     * @var array
     */
    protected $_arguments = array();

    /**
     * The selected component.
     *
     * @var Component
     */
    private $_component;

    /**
     * The path to component in case the selected one is a source component.
     *
     * @var string
     */
    private $_path;

    /**
     * Set an additional option value.
     *
     * @param string $key   The option to set.
     * @param string $value The value of the option.
     */
    public function setOption($key, $value)
    {
        $this->_options[$key] = $value;
    }

    /**
     * Return all options.
     *
     * @return array
     */
    public function getOptions()
    {
        return $this->_options;
    }

    /**
     * Return the specified option.
     *
     * @param string $option The name of the option.
     *
     * @return mixed The option value or NULL if it is not defined.
     */
    public function getOption($option)
    {
        $options = $this->getOptions();
        if (isset($options[$option])) {
            return $options[$option];
        }
        return null;
    }

    /**
     * Shift an element from the argument list.
     *
     * @return mixed The shifted element.
     */
    public function shiftArgument()
    {
        return array_shift($this->_arguments);
    }

    /**
     * Unshift an element to the argument list.
     *
     * @param string $element The element to unshift.
     */
    public function unshiftArgument($element)
    {
        array_unshift($this->_arguments, $element);
    }

    /**
     * Return the arguments parsed from the command line.
     *
     * @return array An array of arguments.
     */
    public function getArguments()
    {
        return $this->_arguments;
    }

    /**
     * Set the path to the component directory.
     *
     * @param Component $component The path to the component directory.
     */
    public function setComponent(Component $component)
    {
        $this->_component = $component;
    }

    /**
     * Return the selected component.
     *
     * @return Component The selected component.
     * @throws Exception
     */
    public function getComponent()
    {
        if ($this->_component === null) {
            throw new Exception(
                'The selected component has not been identified yet!'
            );
        }
        return $this->_component;
    }

    /**
     * Set the path to the directory of the selected source component.
     *
     * @param string $path The path to the component directory.
     */
    public function setPath($path)
    {
        $this->_path = $path;
    }

    /**
     * Get the path to the directory of the selected component (in case it was a
     * source component).
     *
     * @return string The path to the component directory.
     */
    public function getPath()
    {
        return $this->_path;
    }
}
