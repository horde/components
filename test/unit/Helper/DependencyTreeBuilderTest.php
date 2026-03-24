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

namespace Horde\Components\Test\Unit\Helper;

use Horde\Components\Helper\DependencyTreeBuilder;
use Horde\Components\Helper\PluginDetector;
use Horde\Components\Component;
use Horde\Components\Output;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(DependencyTreeBuilder::class)]
class DependencyTreeBuilderTest extends TestCase
{
    private DependencyTreeBuilder $builder;
    private PluginDetector $pluginDetector;
    private Output $output;

    protected function setUp(): void
    {
        $this->pluginDetector = new PluginDetector();
        $this->output = $this->createMock(Output::class);
        $this->builder = new DependencyTreeBuilder($this->pluginDetector, $this->output);
    }

    public function testBuildSimpleGraph(): void
    {
        // Create mock root component with empty dependencies
        $root = $this->createMockComponent('horde/core', 'pear.horde.org', '3.0.0');

        // Return empty array iterator for dependency list
        $emptyList = new \ArrayIterator([]);
        $root->method('getDependencyList')->willReturn($emptyList);

        $graph = $this->builder->build($root);

        $this->assertEquals('horde/core/pear.horde.org', $graph->getRoot());
        $this->assertTrue($graph->hasNode('horde/core/pear.horde.org'));
        $this->assertCount(1, $graph->getNodes());
    }

    public function testBuildWithPluginDetector(): void
    {
        $root = $this->createMockComponent('horde/horde-installer-plugin', 'pear.horde.org', '3.0.0');
        $root->method('getDependencyList')->willReturn(new \ArrayIterator([]));

        // Plugin detection will only mark node as plugin if it can detect it
        // In this mock setup, it will use the known plugins list
        $graph = $this->builder->build($root, ['detect_plugins' => true]);

        // The known plugins list should detect horde-installer-plugin
        $node = $graph->getNode('horde/horde-installer-plugin/pear.horde.org');
        $this->assertNotNull($node);
        // The node should be marked as plugin after detection
        $this->assertTrue($node->isPlugin);
    }

    public function testBuildAndExportCreatesFile(): void
    {
        $root = $this->createMockComponent('horde/core', 'pear.horde.org', '3.0.0');
        $root->method('getDependencyList')->willReturn(new \ArrayIterator([]));

        $tempFile = tempnam(sys_get_temp_dir(), 'deps_') . '.yml';

        try {
            $this->output->expects($this->once())
                ->method('ok')
                ->with($this->stringContains('exported to'));

            $this->builder->buildAndExport($root, $tempFile);

            $this->assertFileExists($tempFile);
            $content = file_get_contents($tempFile);
            $this->assertNotEmpty($content);
            $this->assertStringContainsString('horde/core', $content);
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    public function testBuildWithNetworkRequestsOption(): void
    {
        $detector = new PluginDetector(allowNetworkRequests: true);
        $builder = new DependencyTreeBuilder($detector, $this->output, allowNetworkRequests: true);

        $root = $this->createMockComponent('horde/core', 'pear.horde.org', '3.0.0');
        $root->method('getDependencyList')->willReturn(new \ArrayIterator([]));

        $graph = $builder->build($root);

        $this->assertInstanceOf(\Horde\Components\Component\DependencyGraph::class, $graph);
    }

    public function testBuildGraphSetsCorrectMetadata(): void
    {
        $root = $this->createMockComponent('horde/core', 'pear.horde.org', '3.0.0');
        $root->method('getDependencyList')->willReturn(new \ArrayIterator([]));

        $graph = $this->builder->build($root);

        $rootNode = $graph->getNode('horde/core/pear.horde.org');
        $this->assertNotNull($rootNode);
        $this->assertEquals('horde/core', $rootNode->name);
        $this->assertEquals('pear.horde.org', $rootNode->channel);
        $this->assertEquals('3.0.0', $rootNode->version);
        $this->assertEquals('git', $rootNode->source);
    }

    /**
     * Helper to create a mock Component.
     */
    private function createMockComponent(string $name, string $channel, string $version): Component
    {
        $component = $this->createMock(Component::class);
        $component->method('getName')->willReturn($name);
        $component->method('getChannel')->willReturn($channel);
        $component->method('getVersion')->willReturn($version);

        return $component;
    }
}
