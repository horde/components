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

use RuntimeException;
use Exception;

class ComposedConfig implements ComposedConfigInterface
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
     *
     * @param string $id
     */
    public function get(string $id, array $layers = [])
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
     * @param array<string> Specify which layer(s) to check
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
     * @return array<string> List the set of configuration layer names
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
     * Add a new config layer to be checked before others
     *
     * Defaults to top priority
     * @param ConfigInterface The configuration to add
     * @param string $key The name of the config layer. Must be unique. Defaults to class name.
     * @param string $before The config before which to insert the new config. If empty, top priority.
     */
    public function addConfigBefore(ConfigInterface $config, string $key = '', string $before = '')
    {
        if (empty($key)) {
            $key = $config::class;
        }
        if ($this->hasConfig($key)) {
            throw new RuntimeException('Config already present: ' . $key);
        }
        $this->configs[$key] = $config;
        $last = array_key_last($this->configOrder);
        if ($before === '') {
            $pos = 0;
        } else {
            $pos = array_search($before, $this->configOrder, true);
            if ($pos === false) {
                throw new Exception('Referenced Config Layer Key not present: ' . $before);
            }
        }
        // Trivial case
        if ($pos == 0) {
            array_unshift($this->configOrder, $key);
            return;
        }
        $this->configOrder = array_merge(
            array_slice($this->configOrder, 0, $pos - 1 ),
            [$key],
            array_slice($this->configOrder, $pos, $last)
        );
    }

    /**
     * Add a new config layer to be checked after others
     *
     * Defaults to least priority
     *
     * @param ConfigInterface The configuration to add
     * @param string $key The name of the config layer. Must be unique. Defaults to class name.
     * @param string $after The config after which to insert the new config. If empty, last priority.
     */
    public function addConfigAfter(ConfigInterface $config, string $key = '', string $after = '')
    {
        if (empty($key)) {
            $key = $config::class;
        }
        if ($this->hasConfig($key)) {
            throw new RuntimeException('Config already present: ' . $key);
        }
        $this->configs[$key] = $config;
        $last = array_key_last($this->configOrder);
        if ($after === '') {
            $pos = $last;
        } else {
            $pos = array_search($after, $this->configOrder, true);
            if ($pos === false) {
                throw new Exception('Referenced Config Layer Key not present: ' . $after);
            }
        }
        $this->configOrder = array_merge(
            array_slice($this->configOrder, 0, $pos),
            [$key],
            array_slice($this->configOrder, $pos + 1, $last)
        );
    }
}
