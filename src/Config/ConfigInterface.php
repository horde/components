<?php
/**
 * ConfigInterface
 *
 * PHP Version 8
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
namespace Horde\Components;

interface ConfigInterface
{
    public function has(string $id): bool;
    /**
     * Configuration values are strings.
     */
    public function get(string $id): string;

    /**
     * Gets all registered config keys
     *
     * @return string[]
     */
    public function getKeys(): array;
}