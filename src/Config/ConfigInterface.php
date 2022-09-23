<?php
declare(strict_types=1);
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
namespace Horde\Components\Config;

interface ConfigInterface
{
    /**
     * Check if a certain config key is present.
     *
     * @param string $id The name of the config value
     * @return bool      True if present
     */
    public function has(string $id): bool;

    /**
     * Retrieve an existing config value
     *
     * Configuration values are strings.
     *
     * @param string $id The name of the config value
     */
    public function get(string $id);

    /**
     * Gets all registered config keys
     *
     * @return string[] The list of config keys
     */
    public function getKeys(): array;
}