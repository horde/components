<?php
/**
 * Components_Config:: interface represents a configuration type for the Horde
 * component tool.
 *
 * PHP Version 7
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
namespace Horde\Components;

/**
 * Components_Config:: interface represents a configuration type for the Horde
 * component tool.
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
interface Config
{
    /**
     * Set an additional option value.
     *
     * @param string $key   The option to set.
     * @param string $value The value of the option.
     *
     * @return void
     */
    public function setOption($key, $value);

    /**
     * Return the specified option.
     *
     * @param string $option The name of the option.
     *
     * @return mixed The option value or NULL if it is not defined.
     */
    public function getOption($option);

    /**
     * Return the options provided by the configuration handlers.
     *
     * @return array An array of options.
     */
    public function getOptions();

    /**
     * Shift an element from the argument list.
     *
     * @return mixed The shifted element.
     */
    public function shiftArgument();

    /**
     * Unshift an element to the argument list.
     *
     * @param string $element The element to unshift.
     *
     * @return void
     */
    public function unshiftArgument($element);

    /**
     * Return the arguments provided by the configuration handlers.
     *
     * @return array An array of arguments.
     */
    public function getArguments();

    /**
     * Set the selected component.
     *
     * @param Component $component The selected component.
     *
     * @return void
     */
    public function setComponent(Component $component);

    /**
     * Return the selected component.
     *
     * @return Component The selected component.
     */
    public function getComponent();

    /**
     * Set the path to the directory of the selected source component.
     *
     * @param string $path The path to the component directory.
     *
     * @return void
     */
    public function setPath($path);

    /**
     * Get the path to the directory of the selected component (in case it was a
     * source component).
     *
     * @return string The path to the component directory.
     */
    public function getPath();
}