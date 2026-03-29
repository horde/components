<?php

/**
 * Components_Runner_Dependencies:: lists a tree of dependencies.
 *
 * PHP Version 8.2+
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

declare(strict_types=1);

namespace Horde\Components\Runner;

use Horde\Components\Component;
use Horde\Components\Component\DependencyGraph;
use Horde\Components\Component\Factory as ComponentFactory;
use Horde\Components\Helper\Dependencies as HelperDependencies;
use Horde\Components\Helper\DependencyTreeBuilder;
use Horde\Components\Helper\PluginDetector;
use Horde\Components\Output;
use Horde\Components\Util\YamlLoader;
use Horde\HordeYmlFile\HordeYmlFile;

/**
 * Horde\Components\Runner\Dependencies:: lists a tree of dependencies.
 *
 * Copyright 2010-2026 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Dependencies
{
    /**
     * Constructor.
     *
     * @param Component $component The component
     * @param array $options CLI options
     * @param HelperDependencies $dependenciesHelper The list helper
     * @param ComponentFactory $componentFactory Factory for creating component instances
     */
    public function __construct(
        private readonly Component $component,
        private readonly array $options,
        private readonly HelperDependencies $dependenciesHelper,
        private readonly ComponentFactory $componentFactory
    ) {}

    public function run(): void
    {
        // Check if we should export to YAML file
        if (!empty($this->options['export_yaml'])) {
            $this->runGraphExport();
            return;
        }

        // Check if we should print raw YAML (--no-tree)
        if (!empty($this->options['no_tree'])) {
            $this->runRawYaml();
            return;
        }

        // Default: print tree to stdout
        $this->runTreeOutput();
    }

    /**
     * Run tree output to stdout.
     */
    private function runTreeOutput(): void
    {
        // Determine network access
        $allowNetworkRequests = !empty($this->options['allow_network_requests'])
            || !empty($this->options['allow_remote']);

        // Create plugin detector
        $pluginDetector = new PluginDetector($allowNetworkRequests);

        // Get output
        $reflection = new \ReflectionClass($this->dependenciesHelper);
        $outputProperty = $reflection->getProperty('_output');
        $outputProperty->setAccessible(true);
        $output = $outputProperty->getValue($this->dependenciesHelper);

        // Create tree builder
        $builder = new DependencyTreeBuilder(
            $this->componentFactory,
            $pluginDetector,
            $output,
            $allowNetworkRequests
        );

        // Build options
        $buildOptions = [
            'alldeps' => !empty($this->options['alldeps']),
            'detect_plugins' => !empty($this->options['detect_plugins']),
        ];

        // Build graph
        $graph = $builder->build($this->component, $buildOptions);

        // Print tree
        $this->printTree($graph, $output);
    }

    /**
     * Print dependency tree to stdout.
     *
     * @param DependencyGraph $graph The graph
     * @param Output $output Output handler
     */
    private function printTree(DependencyGraph $graph, Output $output): void
    {
        $includeOptional = !empty($this->options['alldeps']);

        echo "The list " . ($includeOptional ? "contains" : "only contains required") . " dependencies!\n";
        echo "Dependencies on PEAR itself are not displayed.\n\n";

        $rootKey = $graph->getRoot();
        $rootNode = $graph->getNode($rootKey);

        // Print root
        $rootLabel = $this->formatDependencyLabel($rootNode);
        echo "|_" . $rootLabel . "\n";

        // Get edges for root
        $edges = $graph->getEdges($rootKey);
        if (!empty($edges)) {
            $this->printDependencies($graph, $edges, "  ", []);
        }
    }

    /**
     * Recursively print dependencies.
     *
     * @param DependencyGraph $graph The graph
     * @param array $depKeys Dependency keys to print
     * @param string $indent Current indentation
     * @param array $visited Visited nodes (for cycle detection)
     */
    private function printDependencies(DependencyGraph $graph, array $depKeys, string $indent, array $visited): void
    {
        foreach ($depKeys as $depKey) {
            if (isset($visited[$depKey])) {
                continue; // Skip cycles
            }

            $node = $graph->getNode($depKey);
            if (!$node) {
                continue;
            }

            // Skip extensions if not showing all deps
            if ($node->channel === 'ext' && empty($this->options['alldeps'])) {
                continue;
            }

            // Format dependency info
            $label = $this->formatDependencyLabel($node);
            echo $indent . "|_" . $label . "\n";

            // Mark as visited
            $visited[$depKey] = true;

            // Get children
            $childEdges = $graph->getEdges($depKey);
            if (!empty($childEdges)) {
                $this->printDependencies($graph, $childEdges, $indent . "  ", $visited);
            }
        }
    }

    /**
     * Format a dependency label for display.
     *
     * @param \Horde\Components\Component\DependencyNode $node The dependency node
     * @return string Formatted label
     */
    private function formatDependencyLabel($node): string
    {
        $name = $node->name;
        $version = $node->version;

        // Determine the source/type label with composer type if available
        if ($node->channel === 'ext') {
            $sourceLabel = 'PHP extension';
        } elseif ($node->channel === 'pear.horde.org') {
            // Horde packages - show composer type if available
            if (!empty($node->type) && $node->type !== 'pkg') {
                $sourceLabel = $this->formatComposerType($node->type);
            } else {
                $sourceLabel = 'Horde (composer)';
            }
        } elseif ($node->channel === 'packagist.org') {
            // Packagist packages - show composer type if available
            if (!empty($node->type) && $node->type !== 'pkg') {
                $sourceLabel = $this->formatComposerType($node->type);
            } else {
                $sourceLabel = 'Packagist';
            }
        } else {
            // PEAR channel
            $sourceLabel = 'PEAR: ' . $node->channel;
        }

        return sprintf("%s-%s [%s]", $name, $version, $sourceLabel);
    }

    /**
     * Format composer package type for display.
     *
     * @param string $type Composer package type
     * @return string Formatted type label
     */
    private function formatComposerType(string $type): string
    {
        // Map composer types to display labels
        $typeMap = [
            'library' => 'Library',
            'composer-plugin' => 'Composer Plugin',
            'project' => 'Application',
            'metapackage' => 'Metapackage',
            'horde-library' => 'Horde Library',
            'horde-application' => 'Horde Application',
            'horde-theme' => 'Horde Theme',
            'horde-languagepack' => 'Horde Language Pack',
        ];

        return $typeMap[$type] ?? ucfirst(str_replace('-', ' ', $type));
    }

    /**
     * Run raw YAML output (--no-tree).
     */
    private function runRawYaml(): void
    {
        // Get component directory
        if (!method_exists($this->component, 'getComponentDirectory')) {
            echo "---\n";
            return;
        }

        $componentDir = $this->component->getComponentDirectory();
        $hordeYmlPath = $componentDir . '/.horde.yml';

        if (!file_exists($hordeYmlPath)) {
            echo "---\n";
            return;
        }

        // Initialize HordeYmlFile directly (DI pattern)
        try {
            $hordeYmlFile = new HordeYmlFile($hordeYmlPath);
            $hordeYml = $hordeYmlFile->toArray();

            if (isset($hordeYml['dependencies'])) {
                print YamlLoader::dump($hordeYml['dependencies']);
            } else {
                echo "---\n";
            }
        } catch (\Throwable $e) {
            echo "---\n";
        }
    }

    /**
     * Run new graph-based export.
     */
    private function runGraphExport(): void
    {
        // Determine network access (support both new and legacy flags)
        $allowNetworkRequests = !empty($this->options['allow_network_requests'])
            || !empty($this->options['allow_remote']);

        // Create plugin detector
        $pluginDetector = new PluginDetector($allowNetworkRequests);

        // Get output - use a reflection hack to access private property
        // In production, this would be refactored to use proper DI
        $reflection = new \ReflectionClass($this->dependenciesHelper);
        $outputProperty = $reflection->getProperty('_output');
        $outputProperty->setAccessible(true);
        $output = $outputProperty->getValue($this->dependenciesHelper);

        // Create tree builder
        $builder = new DependencyTreeBuilder(
            $this->componentFactory,
            $pluginDetector,
            $output,
            $allowNetworkRequests
        );

        // Build options
        $buildOptions = [
            'alldeps' => !empty($this->options['alldeps']),
            'detect_plugins' => !empty($this->options['detect_plugins']),
        ];

        // Export to file
        $filename = $this->options['export_yaml'];
        $builder->buildAndExport($this->component, $filename, $buildOptions);
    }
}
