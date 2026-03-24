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

namespace Horde\Components\Helper;

use Horde\Components\Component;
use Horde\Components\Component\Dependency;
use Horde\Components\Component\DependencyGraph;
use Horde\Components\Component\DependencyNode;
use Horde\Components\Component\Factory as ComponentFactory;
use Horde\Components\Output;
use Horde\HordeYmlFile\HordeYmlFile;

/**
 * Builds a dependency tree/graph from a root component.
 *
 * Orchestrates dependency resolution, plugin detection, and graph construction.
 * Handles cycles, unknown dependencies, and optional network requests.
 *
 * @author    Ralf Lang <lang@b1-systems.de>
 * @category  Horde
 * @copyright 2026 Horde LLC
 * @license   http://www.horde.org/licenses/lgpl21 LGPL 2.1
 * @package   Components
 */
class DependencyTreeBuilder
{
    /**
     * Constructor.
     *
     * @param ComponentFactory $componentFactory Factory for creating component instances
     * @param PluginDetector $pluginDetector Plugin detection helper
     * @param Output $output Output handler
     * @param bool $allowNetworkRequests Allow network requests for resolution
     * @param int $maxDepth Maximum recursion depth (default: 10)
     */
    public function __construct(
        private readonly ComponentFactory $componentFactory,
        private readonly PluginDetector $pluginDetector,
        private readonly Output $output,
        private readonly bool $allowNetworkRequests = false,
        private readonly int $maxDepth = 10
    ) {}

    /**
     * Build a dependency graph from a root component.
     *
     * @param Component $root Root component to start from
     * @param array $options Build options
     *   - alldeps: Include optional dependencies (default: false)
     *   - detect_plugins: Run plugin detection (default: false)
     * @return DependencyGraph Complete dependency graph
     */
    public function build(Component $root, array $options = []): DependencyGraph
    {
        $graph = new DependencyGraph();
        $visited = [];

        // Create root node
        $rootNode = $this->createNodeFromComponent($root);
        $graph->setRoot($rootNode->key());
        $graph->addNode($rootNode);

        // Build tree recursively
        $this->buildRecursive($root, $graph, $options, $visited, 0);

        // Detect plugins if requested
        if (!empty($options['detect_plugins'])) {
            $this->detectPlugins($graph);
        }

        return $graph;
    }

    /**
     * Recursively build the dependency tree.
     *
     * @param Component $component Current component
     * @param DependencyGraph $graph Graph being built
     * @param array $options Build options
     * @param array $visited Visited nodes (for cycle detection)
     * @param int $depth Current recursion depth
     */
    private function buildRecursive(
        Component $component,
        DependencyGraph $graph,
        array $options,
        array &$visited,
        int $depth
    ): void {
        // Check depth limit
        if ($depth >= $this->maxDepth) {
            return;
        }

        $componentKey = $this->getComponentKey($component);

        // Mark as visited
        $visited[$componentKey] = true;

        // Get dependencies from .horde.yml directly
        try {
            $dependencies = $this->getDependenciesFromHordeYml($component);
        } catch (\Throwable $e) {
            $this->output->warn("Could not read dependencies from {$componentKey}: " . $e->getMessage());
            return;
        }

        // Process dependencies
        foreach ($dependencies as $depInfo) {
            // Skip PHP dependencies
            if ($depInfo['type'] === 'php') {
                continue;
            }

            // Skip if optional and not including all deps
            if (!empty($depInfo['optional']) && empty($options['alldeps'])) {
                continue;
            }

            // Create dependency node
            $depNode = $this->createNodeFromDependencyInfo($depInfo);
            $depKey = $depNode->key();

            // Add node if not already in graph
            if (!$graph->hasNode($depKey)) {
                $graph->addNode($depNode);
            }

            // Add edge
            $graph->addEdge($componentKey, $depKey);

            // Check for cycle
            if (isset($visited[$depKey])) {
                continue; // Stop recursion
            }

            // Try to resolve and recurse if it's a Horde component
            if ($depInfo['channel'] === 'pear.horde.org') {
                try {
                    $depComponent = $this->resolveHordeComponent($depInfo['name']);
                    if ($depComponent !== false) {
                        $this->buildRecursive($depComponent, $graph, $options, $visited, $depth + 1);
                    }
                } catch (\Throwable $e) {
                    // Component couldn't be resolved or has errors, skip recursion
                    $this->output->warn("Could not recurse into {$depInfo['name']}: " . $e->getMessage());
                }
            }
        }
    }

    /**
     * Create a DependencyNode from a Component.
     *
     * @param Component $component Component to convert
     * @return DependencyNode Node representing the component
     */
    private function createNodeFromComponent(Component $component): DependencyNode
    {
        $node = new DependencyNode(
            $component->getName(),
            $component->getChannel(),
            $component->getVersion(),
            '', // Type will be determined from component if available
            'git' // Resolved from git checkout
        );

        // Try to get type from component
        try {
            // Use reflection to check if method exists (not on interface)
            if (method_exists($component, 'getWrapper')) {
                $wrapper = $component->getWrapper('HordeYml');
                if ($wrapper && isset($wrapper['type'])) {
                    $node->type = $wrapper['type'];
                }
            }
        } catch (\Exception $e) {
            // Type not available, leave empty
        }

        // Store component path for plugin detection
        try {
            // Use reflection to check if method exists (not on interface)
            if (method_exists($component, 'getComponentDirectory')) {
                $path = $component->getComponentDirectory();
                $composerJsonPath = $path . '/composer.json';
                if (file_exists($composerJsonPath)) {
                    $node->metadata['composer_json_path'] = $composerJsonPath;
                }
            }
        } catch (\Exception $e) {
            // Path not available
        }

        return $node;
    }

    /**
     * Create a DependencyNode from a Dependency (unresolved).
     *
     * @param Dependency $dependency Dependency to convert
     * @return DependencyNode Node representing the dependency
     */
    private function createNodeFromDependency(Dependency $dependency): DependencyNode
    {
        $node = new DependencyNode(
            $dependency->getName(),
            $dependency->getChannel(),
            '', // Version not available for unresolved
            '', // Type not available for unresolved
            'unknown' // Could not resolve
        );

        return $node;
    }

    /**
     * Get a unique key for a component.
     *
     * @param Component $component Component
     * @return string Unique key (name/channel)
     */
    private function getComponentKey(Component $component): string
    {
        return $component->getName() . '/' . $component->getChannel();
    }

    /**
     * Get dependencies from .horde.yml file.
     *
     * @param Component $component Component to read dependencies from
     * @return array Array of dependency info
     */
    private function getDependenciesFromHordeYml(Component $component): array
    {
        $dependencies = [];

        try {
            // Get component directory
            if (!method_exists($component, 'getComponentDirectory')) {
                return [];
            }

            $componentDir = $component->getComponentDirectory();
            $hordeYmlPath = $componentDir . '/.horde.yml';

            if (!file_exists($hordeYmlPath)) {
                return [];
            }

            // Initialize HordeYmlFile directly (DI pattern)
            $hordeYmlFile = new HordeYmlFile($hordeYmlPath);
            $hordeYml = $hordeYmlFile->toArray();

            if (!isset($hordeYml['dependencies'])) {
                return [];
            }

            $deps = $hordeYml['dependencies'];
            return $this->parseDependenciesArray($deps);
        } catch (\Throwable $e) {
            // If we can't read dependencies, return empty array
            $this->output->warn("Could not read dependencies from {$component->getName()}: " . $e->getMessage());
        }

        return $dependencies;
    }

    /**
     * Parse dependencies array from .horde.yml format.
     *
     * @param array $deps Dependencies array from .horde.yml
     * @return array Standardized dependency info array
     */
    private function parseDependenciesArray(array $deps): array
    {
        $dependencies = [];

            // Process required dependencies
            if (isset($deps['required'])) {
                foreach ($deps['required'] as $type => $items) {
                    if ($type === 'php') {
                        // Skip PHP version requirement
                        continue;
                    }

                    if (!is_array($items)) {
                        // Skip if items is not an array
                        continue;
                    }

                    if ($type === 'ext') {
                        // Extensions
                        foreach ($items as $name => $version) {
                            $dependencies[] = [
                                'name' => $name,
                                'channel' => 'ext',
                                'version' => is_array($version) ? ($version['version'] ?? '*') : $version,
                                'type' => 'ext',
                                'optional' => false,
                            ];
                        }
                    } elseif ($type === 'composer') {
                        // Composer dependencies
                        foreach ($items as $name => $version) {
                            // Determine channel from package name
                            $channel = 'packagist.org';
                            if (str_starts_with($name, 'horde/')) {
                                $channel = 'pear.horde.org';
                            }

                            $dependencies[] = [
                                'name' => $name,
                                'channel' => $channel,
                                'version' => $version,
                                'type' => 'pkg',
                                'optional' => false,
                            ];
                        }
                    } elseif ($type === 'pear') {
                        // PEAR dependencies
                        foreach ($items as $pearPkg => $version) {
                            [$channel, $name] = explode('/', $pearPkg, 2);
                            $dependencies[] = [
                                'name' => $name,
                                'channel' => $channel,
                                'version' => is_array($version) ? ($version['version'] ?? '*') : $version,
                                'type' => 'pkg',
                                'optional' => false,
                            ];
                        }
                    }
                }
            }

            // Process optional dependencies
            if (isset($deps['optional'])) {
                foreach ($deps['optional'] as $type => $items) {
                    if ($type === 'php') {
                        continue;
                    }

                    if (!is_array($items)) {
                        // Skip if items is not an array
                        continue;
                    }

                    if ($type === 'ext') {
                        foreach ($items as $name => $version) {
                            $dependencies[] = [
                                'name' => $name,
                                'channel' => 'ext',
                                'version' => is_array($version) ? ($version['version'] ?? '*') : $version,
                                'type' => 'ext',
                                'optional' => true,
                            ];
                        }
                    } elseif ($type === 'composer') {
                        foreach ($items as $name => $version) {
                            $channel = 'packagist.org';
                            if (str_starts_with($name, 'horde/')) {
                                $channel = 'pear.horde.org';
                            }

                            $dependencies[] = [
                                'name' => $name,
                                'channel' => $channel,
                                'version' => $version,
                                'type' => 'pkg',
                                'optional' => true,
                            ];
                        }
                    } elseif ($type === 'pear') {
                        foreach ($items as $pearPkg => $version) {
                            [$channel, $name] = explode('/', $pearPkg, 2);
                            $dependencies[] = [
                                'name' => $name,
                                'channel' => $channel,
                                'version' => is_array($version) ? ($version['version'] ?? '*') : $version,
                                'type' => 'pkg',
                                'optional' => true,
                            ];
                        }
                    }
                }
            }

        return $dependencies;
    }

    /**
     * Create a DependencyNode from dependency info array.
     *
     * @param array $depInfo Dependency information
     * @return DependencyNode Node representing the dependency
     */
    private function createNodeFromDependencyInfo(array $depInfo): DependencyNode
    {
        $source = 'unknown';

        // If it's a Horde component, try to resolve from git
        if ($depInfo['channel'] === 'pear.horde.org' && $this->resolveHordeComponent($depInfo['name']) !== false) {
            $source = 'git';
        } elseif ($depInfo['type'] === 'ext') {
            $source = 'ext';
        }

        return new DependencyNode(
            $depInfo['name'],
            $depInfo['channel'],
            $depInfo['version'] ?? '',
            $depInfo['type'] ?? '',
            $source
        );
    }

    /**
     * Try to resolve a Horde component from git checkout.
     *
     * @param string $name Component name (e.g., "horde/alarm")
     * @return Component|false Component if found, false otherwise
     */
    private function resolveHordeComponent(string $name): Component|false
    {
        // Extract component name from horde/name format
        $componentName = $name;
        if (str_starts_with($name, 'horde/')) {
            $componentName = substr($name, 6); // Remove "horde/" prefix
        }

        // Convert to proper case (first letter uppercase, rest lowercase for most, but check variations)
        $possibleNames = [
            ucfirst(strtolower($componentName)), // Alarm, Core
            strtoupper($componentName),            // If all caps
            $componentName,                         // Original case
        ];

        foreach ($possibleNames as $tryName) {
            $gitDir = $_SERVER['HOME'] . '/php/git/horde/' . $tryName;

            if (is_dir($gitDir) && file_exists($gitDir . '/.horde.yml')) {
                try {
                    // Use factory to create component from git directory
                    return $this->componentFactory->createSource($gitDir);
                } catch (\Throwable $e) {
                    // Component has errors (bad composer.json, etc), skip it
                    return false;
                }
            }
        }

        return false;
    }

    /**
     * Detect plugins in all nodes of the graph.
     *
     * @param DependencyGraph $graph Graph to process
     */
    private function detectPlugins(DependencyGraph $graph): void
    {
        $nodes = $graph->getNodes();
        $detected = $this->pluginDetector->detectBatch($nodes);

        if (!empty($detected)) {
            $this->output->ok(sprintf('Detected %d Composer plugin(s)', count($detected)));
        }
    }

    /**
     * Build a graph and export to YAML file.
     *
     * @param Component $root Root component
     * @param string $filename Output filename
     * @param array $options Build options
     */
    public function buildAndExport(Component $root, string $filename, array $options = []): void
    {
        $graph = $this->build($root, $options);

        $yaml = $graph->toYaml();
        file_put_contents($filename, $yaml);

        $this->output->ok("Dependency graph exported to: {$filename}");
        $this->output->info(sprintf(
            'Graph contains %d nodes, %d edges',
            count($graph->getNodes()),
            count($graph->getAllEdges())
        ));

        if (!empty($graph->getPlugins())) {
            $this->output->info(sprintf('Detected %d plugin(s)', count($graph->getPlugins())));
        }

        if (!empty($graph->getUnknown())) {
            $this->output->warn(sprintf('Found %d unresolved dependency/dependencies', count($graph->getUnknown())));
        }
    }
}
