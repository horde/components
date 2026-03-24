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

namespace Horde\Components\Test\Unit\Component;

use Horde\Components\Component\DependencyNode;
use Horde\Components\Component\DependencyGraph;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(DependencyNode::class)]
#[CoversClass(DependencyGraph::class)]
class DependencyGraphTest extends TestCase
{
    public function testNodeCreation(): void
    {
        $node = new DependencyNode(
            'horde/core',
            'pear.horde.org',
            '3.0.0',
            'horde-library',
            'git'
        );

        $this->assertEquals('horde/core', $node->name);
        $this->assertEquals('pear.horde.org', $node->channel);
        $this->assertEquals('3.0.0', $node->version);
        $this->assertEquals('horde-library', $node->type);
        $this->assertEquals('git', $node->source);
        $this->assertFalse($node->isPlugin);
    }

    public function testNodeKey(): void
    {
        $node = new DependencyNode('horde/core', 'pear.horde.org');
        $this->assertEquals('horde/core/pear.horde.org', $node->key());
    }

    public function testNodeDependencies(): void
    {
        $node = new DependencyNode('horde/core', 'pear.horde.org');
        $node->addDependency('horde/alarm/pear.horde.org');
        $node->addDependency('horde/auth/pear.horde.org');
        $node->addDependency('horde/alarm/pear.horde.org'); // Duplicate

        $this->assertCount(2, $node->dependencies);
        $this->assertContains('horde/alarm/pear.horde.org', $node->dependencies);
        $this->assertContains('horde/auth/pear.horde.org', $node->dependencies);
    }

    public function testNodeMarkAsPlugin(): void
    {
        $node = new DependencyNode('horde/horde-installer-plugin', 'pear.horde.org');
        $node->markAsPlugin('Horde\\Composer\\HordeInstaller');

        $this->assertTrue($node->isPlugin);
        $this->assertEquals('Horde\\Composer\\HordeInstaller', $node->pluginClass);
    }

    public function testNodeToArray(): void
    {
        $node = new DependencyNode(
            'horde/core',
            'pear.horde.org',
            '3.0.0',
            'horde-library',
            'git'
        );
        $node->addDependency('horde/alarm/pear.horde.org');
        $node->markAsPlugin('TestPlugin');

        $array = $node->toArray();

        $this->assertEquals('horde/core', $array['name']);
        $this->assertEquals('pear.horde.org', $array['channel']);
        $this->assertEquals('3.0.0', $array['version']);
        $this->assertEquals('horde-library', $array['type']);
        $this->assertEquals('git', $array['source']);
        $this->assertTrue($array['is_plugin']);
        $this->assertEquals('TestPlugin', $array['plugin_class']);
        $this->assertContains('horde/alarm/pear.horde.org', $array['dependencies']);
    }

    public function testNodeFromArray(): void
    {
        $data = [
            'name' => 'horde/core',
            'channel' => 'pear.horde.org',
            'version' => '3.0.0',
            'type' => 'horde-library',
            'source' => 'git',
            'dependencies' => ['horde/alarm/pear.horde.org'],
            'is_plugin' => true,
            'plugin_class' => 'TestPlugin',
        ];

        $node = DependencyNode::fromArray($data);

        $this->assertEquals('horde/core', $node->name);
        $this->assertEquals('pear.horde.org', $node->channel);
        $this->assertEquals('3.0.0', $node->version);
        $this->assertTrue($node->isPlugin);
        $this->assertEquals('TestPlugin', $node->pluginClass);
    }

    public function testGraphAddNode(): void
    {
        $graph = new DependencyGraph();
        $node = new DependencyNode('horde/core', 'pear.horde.org');

        $graph->addNode($node);

        $this->assertTrue($graph->hasNode('horde/core/pear.horde.org'));
        $this->assertNotNull($graph->getNode('horde/core/pear.horde.org'));
    }

    public function testGraphAddEdge(): void
    {
        $graph = new DependencyGraph();

        $node1 = new DependencyNode('horde/core', 'pear.horde.org');
        $node2 = new DependencyNode('horde/alarm', 'pear.horde.org');

        $graph->addNode($node1);
        $graph->addNode($node2);
        $graph->addEdge($node1->key(), $node2->key());

        $edges = $graph->getEdges($node1->key());
        $this->assertCount(1, $edges);
        $this->assertContains($node2->key(), $edges);
    }

    public function testGraphPluginTracking(): void
    {
        $graph = new DependencyGraph();

        $plugin = new DependencyNode('horde/horde-installer-plugin', 'pear.horde.org');
        $plugin->markAsPlugin();

        $regular = new DependencyNode('horde/core', 'pear.horde.org');

        $graph->addNode($plugin);
        $graph->addNode($regular);

        $plugins = $graph->getPlugins();
        $this->assertCount(1, $plugins);
        $this->assertContains($plugin->key(), $plugins);
    }

    public function testGraphUnknownTracking(): void
    {
        $graph = new DependencyGraph();

        $unknown = new DependencyNode('pear/some-package', 'pear.php.net', '', '', 'unknown');
        $known = new DependencyNode('horde/core', 'pear.horde.org', '', '', 'git');

        $graph->addNode($unknown);
        $graph->addNode($known);

        $unknowns = $graph->getUnknown();
        $this->assertCount(1, $unknowns);
        $this->assertContains($unknown->key(), $unknowns);
    }

    public function testGraphCycleDetection(): void
    {
        $graph = new DependencyGraph();

        $node1 = new DependencyNode('package-a', 'test');
        $node2 = new DependencyNode('package-b', 'test');
        $node3 = new DependencyNode('package-c', 'test');

        $graph->addNode($node1);
        $graph->addNode($node2);
        $graph->addNode($node3);

        // Create cycle: A -> B -> C -> A
        $graph->addEdge($node1->key(), $node2->key());
        $graph->addEdge($node2->key(), $node3->key());
        $graph->addEdge($node3->key(), $node1->key());

        $cycle = $graph->detectCycle($node1->key());
        $this->assertNotNull($cycle);
        $this->assertContains($node1->key(), $cycle);
    }

    public function testGraphNoCycle(): void
    {
        $graph = new DependencyGraph();

        $node1 = new DependencyNode('package-a', 'test');
        $node2 = new DependencyNode('package-b', 'test');
        $node3 = new DependencyNode('package-c', 'test');

        $graph->addNode($node1);
        $graph->addNode($node2);
        $graph->addNode($node3);

        // Linear: A -> B -> C
        $graph->addEdge($node1->key(), $node2->key());
        $graph->addEdge($node2->key(), $node3->key());

        $cycle = $graph->detectCycle($node1->key());
        $this->assertNull($cycle);
    }

    public function testGraphToArray(): void
    {
        $graph = new DependencyGraph();

        $node1 = new DependencyNode('horde/core', 'pear.horde.org', '3.0.0', 'horde-library', 'git');
        $node2 = new DependencyNode('horde/alarm', 'pear.horde.org', '3.0.0', 'horde-library', 'git');

        $graph->setRoot($node1->key());
        $graph->addNode($node1);
        $graph->addNode($node2);
        $graph->addEdge($node1->key(), $node2->key());

        $array = $graph->toArray();

        $this->assertEquals($node1->key(), $array['root']);
        $this->assertArrayHasKey('nodes', $array);
        $this->assertArrayHasKey('edges', $array);
        $this->assertCount(2, $array['nodes']);
    }

    public function testGraphRoundTrip(): void
    {
        $graph = new DependencyGraph();

        $node1 = new DependencyNode('horde/core', 'pear.horde.org', '3.0.0', 'horde-library', 'git');
        $node2 = new DependencyNode('horde/alarm', 'pear.horde.org', '3.0.0', 'horde-library', 'git');

        $graph->setRoot($node1->key());
        $graph->addNode($node1);
        $graph->addNode($node2);
        $graph->addEdge($node1->key(), $node2->key());

        // Convert to array and back
        $array = $graph->toArray();
        $restored = DependencyGraph::fromArray($array);

        $this->assertEquals($graph->getRoot(), $restored->getRoot());
        $this->assertCount(2, $restored->getNodes());
        $this->assertTrue($restored->hasNode($node1->key()));
        $this->assertTrue($restored->hasNode($node2->key()));
    }

    public function testGraphYamlExport(): void
    {
        $graph = new DependencyGraph();

        $node = new DependencyNode('horde/core', 'pear.horde.org', '3.0.0', 'horde-library', 'git');
        $graph->addNode($node);
        $graph->setRoot($node->key());

        $yaml = $graph->toYaml();

        $this->assertIsString($yaml);
        $this->assertStringContainsString('horde/core', $yaml);
        $this->assertStringContainsString('pear.horde.org', $yaml);
    }
}
