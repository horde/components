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

trait ConfigTrait
{
    protected array $settings = [];

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->settings);
    }
    /**
     * Configuration values are strings.
     *
     */
    public function get(string $id): string
    {
        if (!$this->has($id)) {
            throw new RuntimeException('Config Item not present: ' . $id);
        }
        return $this->settings[$id];
    }

    /**
     * Gets all registered config keys
     *
     * @return string[]
     */
    public function getKeys(): array
    {
        return array_keys($this->settings);
    }
}
