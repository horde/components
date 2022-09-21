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

interface ComposedConfigInterface extends ConfigInterface
{
    /**
     * Ask the stack of configs, true as soon as one layer has it
     * 
     * By default, circle through the layers by priority
     * 
     * Otherwise check the layers in the order of provided keys
     */
    public function has(string $id, array $layers = []): bool;
    /**
     * Ask the stack of configs by priority
     * 
     * By default, circle through the layers by priority
     */
    public function get(string $id, array $layers = []): string;

    /**
     * List all config keys available
     * 
     * @return array<string> A config key present in at least one layer
     */
    public function getKeys(array $layers = []): array;

    /**
     * @return array<string> List a set of configuration keys
     */
    public function listConfigs(): array;

    /**
     * Add a new config layer to be checked first
     */
    public function addPriorityConfig(ConfigInterface $config, string $key = '');

    /**
     * Add a new config layer to be checked last
     */
    public function addFallbackConfig(ConfigInterface $config, string $key = '');
}
