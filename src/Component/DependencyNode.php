<?php

/**
 * Copyright 2026 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @author   Ralf Lang <lang@b1-systems.de>
 * @category Horde
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 * @package  Components
 */

declare(strict_types=1);

namespace Horde\Components\Component;

/**
 * Represents a single node in the dependency graph.
 *
 * A node contains metadata about a package/component including its name,
 * version, type, source, and whether it's a Composer plugin.
 *
 * @author    Ralf Lang <lang@b1-systems.de>
 * @category  Horde
 * @copyright 2026 Horde LLC
 * @license   http://www.horde.org/licenses/lgpl21 LGPL 2.1
 * @package   Components
 */
class DependencyNode
{
    /**
     * Package name (e.g., "horde/core", "psr/log")
     */
    public string $name;

    /**
     * Channel or type identifier (e.g., "pear.horde.org", "packagist.org", "ext")
     */
    public string $channel;

    /**
     * Version string (e.g., "3.0.0-beta5", "^2.0")
     */
    public string $version;

    /**
     * Package type (e.g., "horde-library", "library", "composer-plugin", "ext")
     */
    public string $type;

    /**
     * Resolution source (e.g., "git", "composer", "packagist", "unknown")
     */
    public string $source;

    /**
     * Direct dependencies (array of package names)
     */
    public array $dependencies = [];

    /**
     * Whether this package is a Composer plugin
     */
    public bool $isPlugin = false;

    /**
     * Plugin class name if this is a Composer plugin
     */
    public ?string $pluginClass = null;

    /**
     * Additional metadata (flexible for future extensions)
     */
    public array $metadata = [];

    /**
     * Constructor.
     *
     * @param string $name Package name
     * @param string $channel Channel or type identifier
     * @param string $version Version string
     * @param string $type Package type
     * @param string $source Resolution source
     */
    public function __construct(
        string $name,
        string $channel,
        string $version = '',
        string $type = '',
        string $source = 'unknown'
    ) {
        $this->name = $name;
        $this->channel = $channel;
        $this->version = $version;
        $this->type = $type;
        $this->source = $source;
    }

    /**
     * Get a unique key for this node.
     *
     * @return string Unique identifier (name/channel)
     */
    public function key(): string
    {
        return $this->name . '/' . $this->channel;
    }

    /**
     * Add a dependency to this node.
     *
     * @param string $dependencyKey Dependency key (name/channel)
     */
    public function addDependency(string $dependencyKey): void
    {
        if (!in_array($dependencyKey, $this->dependencies)) {
            $this->dependencies[] = $dependencyKey;
        }
    }

    /**
     * Mark this node as a Composer plugin.
     *
     * @param string|null $pluginClass Optional plugin class name
     */
    public function markAsPlugin(?string $pluginClass = null): void
    {
        $this->isPlugin = true;
        if ($pluginClass !== null) {
            $this->pluginClass = $pluginClass;
        }
    }

    /**
     * Convert node to array representation.
     *
     * @return array Node data as associative array
     */
    public function toArray(): array
    {
        $data = [
            'name' => $this->name,
            'channel' => $this->channel,
            'version' => $this->version,
            'type' => $this->type,
            'source' => $this->source,
            'dependencies' => $this->dependencies,
            'is_plugin' => $this->isPlugin,
        ];

        if ($this->pluginClass !== null) {
            $data['plugin_class'] = $this->pluginClass;
        }

        if (!empty($this->metadata)) {
            $data['metadata'] = $this->metadata;
        }

        return $data;
    }

    /**
     * Create node from array representation.
     *
     * @param array $data Node data as associative array
     * @return DependencyNode
     */
    public static function fromArray(array $data): DependencyNode
    {
        $node = new self(
            $data['name'] ?? '',
            $data['channel'] ?? '',
            $data['version'] ?? '',
            $data['type'] ?? '',
            $data['source'] ?? 'unknown'
        );

        $node->dependencies = $data['dependencies'] ?? [];
        $node->isPlugin = $data['is_plugin'] ?? false;
        $node->pluginClass = $data['plugin_class'] ?? null;
        $node->metadata = $data['metadata'] ?? [];

        return $node;
    }
}
