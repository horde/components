<?php
/**
 * ConfigInterface with setter
 *
 * PHP Version 8
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
namespace Horde\Components;

interface WritableConfigInterface extends ConfigInterface
{
    /**
     * Add a config key and value
     * 
     * Entry values are untyped by purpose. This is controversial.
     * Implementations may restrict what type of values are allowed in a config.
     * String only, primitives, serializable objects, Stringables all can be argued.
     * 
     * @param string $id The key of the config entry
     * @param string $value The value of the config entry
     */
    public function set(string $id, $value);
}
