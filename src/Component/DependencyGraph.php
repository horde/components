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

use Horde\Components\Util\YamlLoader;

/**
 * Represents a complete dependency graph with nodes and edges.
 *
 * Manages the full dependency tree including detection of plugins,
 * cycle detection, and export to various formats.
 *
 * @author    Ralf Lang <lang@b1-systems.de>
 * @category  Horde
 * @copyright 2026 Horde LLC
 * @license   http://www.horde.org/licenses/lgpl21 LGPL 2.1
 * @package   Components
 */
class DependencyGraph
{
    /**
     * Nodes in the graph (key => DependencyNode)
     */
    private array $nodes = [];

    /**
     * Edges in the graph (from_key => [to_key, ...])
     */
    private array $edges = [];

    /**
     * Root node key
     */
    private ?string $rootKey = null;

    /**
     * Detected Composer plugins (array of node keys)
     */
    private array $plugins = [];

    /**
     * Unknown/unresolved dependencies (array of node keys)
     */
    private array $unknown = [];

    /**
     * Set the root node of the graph.
     *
     * @param string $key Root node key
     */
    public function setRoot(string $key): void
    {
        $this->rootKey = $key;
    }

    /**
     * Get the root node key.
     *
     * @return string|null Root node key or null
     */
    public function getRoot(): ?string
    {
        return $this->rootKey;
    }

    /**
     * Add a node to the graph.
     *
     * @param DependencyNode $node Node to add
     */
    public function addNode(DependencyNode $node): void
    {
        $key = $node->key();
        $this->nodes[$key] = $node;

        // Track plugins
        if ($node->isPlugin) {
            if (!in_array($key, $this->plugins)) {
                $this->plugins[] = $key;
            }
        }

        // Track unknowns
        if ($node->source === 'unknown') {
            if (!in_array($key, $this->unknown)) {
                $this->unknown[] = $key;
            }
        }
    }

    /**
     * Get a node by key.
     *
     * @param string $key Node key
     * @return DependencyNode|null Node or null if not found
     */
    public function getNode(string $key): ?DependencyNode
    {
        return $this->nodes[$key] ?? null;
    }

    /**
     * Check if a node exists.
     *
     * @param string $key Node key
     * @return bool True if node exists
     */
    public function hasNode(string $key): bool
    {
        return isset($this->nodes[$key]);
    }

    /**
     * Get all nodes.
     *
     * @return array Array of DependencyNode objects
     */
    public function getNodes(): array
    {
        return $this->nodes;
    }

    /**
     * Add an edge between two nodes.
     *
     * @param string $fromKey Source node key
     * @param string $toKey Target node key
     */
    public function addEdge(string $fromKey, string $toKey): void
    {
        if (!isset($this->edges[$fromKey])) {
            $this->edges[$fromKey] = [];
        }

        if (!in_array($toKey, $this->edges[$fromKey])) {
            $this->edges[$fromKey][] = $toKey;
        }
    }

    /**
     * Get edges for a node.
     *
     * @param string $key Node key
     * @return array Array of target node keys
     */
    public function getEdges(string $key): array
    {
        return $this->edges[$key] ?? [];
    }

    /**
     * Get all edges.
     *
     * @return array Edges array (from_key => [to_key, ...])
     */
    public function getAllEdges(): array
    {
        return $this->edges;
    }

    /**
     * Get detected plugins.
     *
     * @return array Array of plugin node keys
     */
    public function getPlugins(): array
    {
        return $this->plugins;
    }

    /**
     * Get unknown/unresolved dependencies.
     *
     * @return array Array of unknown node keys
     */
    public function getUnknown(): array
    {
        return $this->unknown;
    }

    /**
     * Check for cycles in the graph.
     *
     * @param string $startKey Starting node key
     * @param array $visited Visited nodes (for recursion)
     * @param array $stack Current path stack (for recursion)
     * @return array|null Array representing cycle path, or null if no cycle
     */
    public function detectCycle(string $startKey, array $visited = [], array $stack = []): ?array
    {
        if (in_array($startKey, $stack)) {
            // Cycle detected - return the cycle path
            $cycleStart = array_search($startKey, $stack);
            return array_slice($stack, $cycleStart);
        }

        if (in_array($startKey, $visited)) {
            return null;
        }

        $visited[] = $startKey;
        $stack[] = $startKey;

        foreach ($this->getEdges($startKey) as $targetKey) {
            $cycle = $this->detectCycle($targetKey, $visited, $stack);
            if ($cycle !== null) {
                return $cycle;
            }
        }

        return null;
    }

    /**
     * Convert graph to array representation.
     *
     * @return array Graph data as associative array
     */
    public function toArray(): array
    {
        $nodes = [];
        foreach ($this->nodes as $key => $node) {
            $nodes[$key] = $node->toArray();
        }

        $data = [
            'nodes' => $nodes,
            'edges' => $this->edges,
        ];

        if ($this->rootKey !== null) {
            $data['root'] = $this->rootKey;
        }

        if (!empty($this->plugins)) {
            $data['plugins'] = $this->plugins;
        }

        if (!empty($this->unknown)) {
            $data['unknown'] = $this->unknown;
        }

        return $data;
    }

    /**
     * Export graph to YAML format.
     *
     * @return string YAML representation of the graph
     */
    public function toYaml(): string
    {
        return YamlLoader::dump($this->toArray());
    }

    /**
     * Create graph from array representation.
     *
     * @param array $data Graph data as associative array
     * @return DependencyGraph
     */
    public static function fromArray(array $data): DependencyGraph
    {
        $graph = new self();

        // Restore root
        if (isset($data['root'])) {
            $graph->rootKey = $data['root'];
        }

        // Restore nodes
        foreach ($data['nodes'] ?? [] as $key => $nodeData) {
            $node = DependencyNode::fromArray($nodeData);
            $graph->nodes[$key] = $node;
        }

        // Restore edges
        $graph->edges = $data['edges'] ?? [];

        // Restore plugins list
        $graph->plugins = $data['plugins'] ?? [];

        // Restore unknowns list
        $graph->unknown = $data['unknown'] ?? [];

        return $graph;
    }

    /**
     * Load graph from YAML string.
     *
     * @param string $yaml YAML string
     * @return DependencyGraph
     */
    public static function fromYaml(string $yaml): DependencyGraph
    {
        $data = YamlLoader::load($yaml);
        return self::fromArray($data);
    }
}
