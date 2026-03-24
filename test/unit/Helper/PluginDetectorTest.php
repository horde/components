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

use Horde\Components\Helper\PluginDetector;
use Horde\Components\Component\DependencyNode;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(PluginDetector::class)]
class PluginDetectorTest extends TestCase
{
    private PluginDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new PluginDetector();
    }

    public function testKnownPluginsListIncludesHordeInstaller(): void
    {
        $knownPlugins = $this->detector->getKnownPlugins();

        $this->assertArrayHasKey('horde/horde-installer-plugin', $knownPlugins);
        $this->assertEquals('Horde\\Composer\\HordeInstaller', $knownPlugins['horde/horde-installer-plugin']);
    }

    public function testKnownPluginsListIncludesComposerInstallers(): void
    {
        $knownPlugins = $this->detector->getKnownPlugins();

        $this->assertArrayHasKey('composer/installers', $knownPlugins);
        $this->assertEquals('Composer\\Installers\\Plugin', $knownPlugins['composer/installers']);
    }

    public function testAddKnownPlugin(): void
    {
        $this->detector->addKnownPlugin('vendor/my-plugin', 'Vendor\\MyPlugin');

        $knownPlugins = $this->detector->getKnownPlugins();
        $this->assertArrayHasKey('vendor/my-plugin', $knownPlugins);
        $this->assertEquals('Vendor\\MyPlugin', $knownPlugins['vendor/my-plugin']);
    }

    public function testDetectFromKnownList(): void
    {
        $node = new DependencyNode('horde/horde-installer-plugin', 'pear.horde.org');

        $result = $this->detector->detect($node);

        $this->assertTrue($result);
        $this->assertTrue($node->isPlugin);
        $this->assertEquals('Horde\\Composer\\HordeInstaller', $node->pluginClass);
    }

    public function testDetectUnknownPackage(): void
    {
        $node = new DependencyNode('horde/core', 'pear.horde.org');

        $result = $this->detector->detect($node);

        $this->assertFalse($result);
        $this->assertFalse($node->isPlugin);
    }

    public function testDetectFromComposerJsonWithTypeComposerPlugin(): void
    {
        // Create temporary composer.json
        $tempFile = tempnam(sys_get_temp_dir(), 'composer_') . '.json';
        $composerData = [
            'name' => 'vendor/test-plugin',
            'type' => 'composer-plugin',
            'extra' => [
                'class' => 'Vendor\\TestPlugin',
            ],
        ];
        file_put_contents($tempFile, json_encode($composerData));

        try {
            $node = new DependencyNode('vendor/test-plugin', 'packagist.org');
            $node->metadata['composer_json_path'] = $tempFile;

            $result = $this->detector->detect($node);

            $this->assertTrue($result);
            $this->assertTrue($node->isPlugin);
            $this->assertEquals('Vendor\\TestPlugin', $node->pluginClass);
        } finally {
            unlink($tempFile);
        }
    }

    public function testDetectFromComposerJsonWithoutExtraClass(): void
    {
        // Create temporary composer.json
        $tempFile = tempnam(sys_get_temp_dir(), 'composer_') . '.json';
        $composerData = [
            'name' => 'vendor/test-plugin',
            'type' => 'composer-plugin',
        ];
        file_put_contents($tempFile, json_encode($composerData));

        try {
            $node = new DependencyNode('vendor/test-plugin', 'packagist.org');
            $node->metadata['composer_json_path'] = $tempFile;

            $result = $this->detector->detect($node);

            $this->assertTrue($result);
            $this->assertTrue($node->isPlugin);
            $this->assertNull($node->pluginClass);
        } finally {
            unlink($tempFile);
        }
    }

    public function testDetectFromComposerJsonNotPlugin(): void
    {
        // Create temporary composer.json
        $tempFile = tempnam(sys_get_temp_dir(), 'composer_') . '.json';
        $composerData = [
            'name' => 'vendor/test-library',
            'type' => 'library',
        ];
        file_put_contents($tempFile, json_encode($composerData));

        try {
            $node = new DependencyNode('vendor/test-library', 'packagist.org');
            $node->metadata['composer_json_path'] = $tempFile;

            $result = $this->detector->detect($node);

            $this->assertFalse($result);
            $this->assertFalse($node->isPlugin);
        } finally {
            unlink($tempFile);
        }
    }

    public function testDetectFromNonExistentComposerJson(): void
    {
        $node = new DependencyNode('vendor/test', 'packagist.org');
        $node->metadata['composer_json_path'] = '/nonexistent/composer.json';

        $result = $this->detector->detect($node);

        $this->assertFalse($result);
        $this->assertFalse($node->isPlugin);
    }

    public function testDetectFromMalformedComposerJson(): void
    {
        // Create temporary malformed composer.json
        $tempFile = tempnam(sys_get_temp_dir(), 'composer_') . '.json';
        file_put_contents($tempFile, '{invalid json}');

        try {
            $node = new DependencyNode('vendor/test', 'packagist.org');
            $node->metadata['composer_json_path'] = $tempFile;

            $result = $this->detector->detect($node);

            $this->assertFalse($result);
            $this->assertFalse($node->isPlugin);
        } finally {
            unlink($tempFile);
        }
    }

    public function testDetectBatch(): void
    {
        $nodes = [
            new DependencyNode('horde/horde-installer-plugin', 'pear.horde.org'),
            new DependencyNode('horde/core', 'pear.horde.org'),
            new DependencyNode('composer/installers', 'packagist.org'),
        ];

        $detected = $this->detector->detectBatch($nodes);

        $this->assertCount(2, $detected);
        $this->assertContains('horde/horde-installer-plugin/pear.horde.org', $detected);
        $this->assertContains('composer/installers/packagist.org', $detected);

        // Verify nodes were updated
        $this->assertTrue($nodes[0]->isPlugin);
        $this->assertFalse($nodes[1]->isPlugin);
        $this->assertTrue($nodes[2]->isPlugin);
    }

    public function testDetectBatchEmptyArray(): void
    {
        $detected = $this->detector->detectBatch([]);

        $this->assertCount(0, $detected);
    }

    public function testDetectionOrderKnownListTakesPrecedence(): void
    {
        // Create temporary composer.json that would detect differently
        $tempFile = tempnam(sys_get_temp_dir(), 'composer_') . '.json';
        $composerData = [
            'name' => 'horde/horde-installer-plugin',
            'type' => 'composer-plugin',
            'extra' => [
                'class' => 'Different\\Class',
            ],
        ];
        file_put_contents($tempFile, json_encode($composerData));

        try {
            $node = new DependencyNode('horde/horde-installer-plugin', 'pear.horde.org');
            $node->metadata['composer_json_path'] = $tempFile;

            $result = $this->detector->detect($node);

            $this->assertTrue($result);
            $this->assertTrue($node->isPlugin);
            // Should use known list value, not composer.json value
            $this->assertEquals('Horde\\Composer\\HordeInstaller', $node->pluginClass);
        } finally {
            unlink($tempFile);
        }
    }

    public function testNetworkRequestsDisabledByDefault(): void
    {
        $detector = new PluginDetector(allowNetworkRequests: false);

        $node = new DependencyNode('unknown/plugin', 'packagist.org');
        $node->source = 'packagist';

        $result = $detector->detect($node);

        // Should not detect without network access
        $this->assertFalse($result);
    }

    public function testNetworkRequestsEnabledButNotImplemented(): void
    {
        $detector = new PluginDetector(allowNetworkRequests: true);

        $node = new DependencyNode('unknown/plugin', 'packagist.org');
        $node->source = 'packagist';

        $result = $detector->detect($node);

        // Network detection not yet implemented
        $this->assertFalse($result);
    }

    public function testGitSourceSkipsNetworkDetection(): void
    {
        $detector = new PluginDetector(allowNetworkRequests: true);

        $node = new DependencyNode('unknown/package', 'pear.horde.org');
        $node->source = 'git';

        $result = $detector->detect($node);

        // Should not try network for git sources
        $this->assertFalse($result);
    }
}
