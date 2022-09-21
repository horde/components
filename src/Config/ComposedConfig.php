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

use RuntimeException;

class ComposedConfig extends ComposedConfigInterface
{
    protected array $configs = [];
    protected array $configOrder = [];
    /**
     * Ask the stack of configs, true as soon as one layer has it
     * 
     * By default, circle through the layers by priority
     * 
     * Otherwise check the layers in the order of provided keys
     */
    public function has(string $id, array $layers = []): bool
    {
        if (empty($layers)) {
            $layers = $this->configOrder;
        }
        foreach ($layers as $configName) {
            if ($this->configs[$configName]->has($id)) {
                return true;
            }
        }
        return false;
    }
    /**
     * Ask the stack of configs by priority
     * 
     * By default, circle through the layers by priority
     */
    public function get(string $id, array $layers = []): string
    {
        if (empty($layers)) {
            $layers = $this->configOrder;
        }
        foreach ($layers as $configName) {
            if ($this->configs[$configName]->has($id)) {
                return $this->configs[$configName]->get($id);
            }
        }
    }

    /**
     * List all config keys available
     * 
     * @return array<string> A config key present in at least one layer
     */
    public function getKeys(array $layers = []): array
    {
        $keys = [];
        if (empty($layers)) {
            $layers = $this->configOrder;
        }
        foreach ($layers as $configName) {
            $keys = array_merge($keys, $this->configs[$configName]->getKeys());
        }
        return array_unique($keys);
    }

    /**
     * @return array<string> List a set of configuration keys
     */
    public function listConfigs(): array
    {
        return array_keys($this->configs);
    }

    public function hasConfig(string $key): bool
    {
        return array_key_exists($key, $this->configs);
    }

    /**
     * Add a new config layer to be checked first
     */
    public function addPriorityConfig(ConfigInterface $config, string $key = '')
    {
        if (empty($key)) {
            $key = $config::class;
        }
        if ($this->hasConfig($key)) {
            throw new RuntimeException('Config already present: ' . $key);
        }
        $this->configs[$key] = $config;
        array_unshift($this->configs, $key);
    }

    /**
     * Add a new config layer to be checked last
     */
    public function addFallbackConfig(ConfigInterface $config, string $key = '')
    {
        if (empty($key)) {
            $key = $config::class;
        }
        if ($this->hasConfig($key)) {
            throw new RuntimeException('Config already present: ' . $key);
        }
        $this->configs[$key] = $config;
        array_push($this->configs, $key);
    }
}
