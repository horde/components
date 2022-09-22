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
namespace Horde\Components\Config;

interface ComposedConfigInterface extends ConfigInterface
{
    /**
     * Ask the stack of configs, true as soon as one layer has it
     * 
     * By default, circle through the layers by priority
     * @param string $id    The config key to check for
     * @param array<string> Specify which layer(s) in which order to check
     * 
     */
    public function has(string $id, array $layers = []): bool;
    /**
     * Ask the stack of configs by priority
     * 
     * By default, circle through the layers by priority
     *
     * @param string $id 
     * @param array<string> Specify which layer(s) in which order to check
     */
    public function get(string $id, array $layers = []);

    /**
     * List all config keys available
     * 
     * @param array<string> Specify which layer(s) to check
     * @return array<string> A config key present in at least one layer
     */
    public function getKeys(array $layers = []): array;

    /**
     * @return array<string> List the set of configuration layer names
     */
    public function listConfigs(): array;

    /**
     * Add a new config layer to be checked before others
     * 
     * Defaults to top priority
     * @param ConfigInterface The configuration to add
     * @param string $key The name of the config layer. Must be unique. Defaults to class name.
     * @param string $before The config before which to insert the new config. If empty, top priority.
     */
    public function addConfigBefore(ConfigInterface $config, string $key = '', string $before = '');

    /**
     * Add a new config layer to be checked after others
     * 
     * Defaults to least priority
     *
     * @param ConfigInterface The configuration to add
     * @param string $key The name of the config layer. Must be unique. Defaults to class name.
     * @param string $after The config after which to insert the new config. If empty, last priority.
     */
    public function addConfigAfter(ConfigInterface $config, string $key = '', string $after = '');
}
