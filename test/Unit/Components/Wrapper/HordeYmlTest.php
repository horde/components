<?php

/**
 * Copyright 2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

declare(strict_types=1);

namespace Horde\Components\Test\Unit\Components\Wrapper;

use Horde\Components\Wrapper\HordeYml;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the components-side HordeYml wrapper.
 *
 * The wrapper is a thin layer over horde/horde-yml-file that adds
 * components-specific rules around composer plugin allow-listing. The
 * tests here exercise the auto-allow rules that decide which Composer
 * plugins land under config.allow-plugins in the regenerated
 * composer.json.
 */
#[CoversClass(HordeYml::class)]
class HordeYmlTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/horde-wrapper-yml-' . uniqid();
        mkdir($this->tmpDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            $files = scandir($this->tmpDir) ?: [];
            foreach ($files as $f) {
                if ($f === '.' || $f === '..') {
                    continue;
                }
                @unlink($this->tmpDir . '/' . $f);
            }
            @rmdir($this->tmpDir);
        }
    }

    /**
     * A library component with no plugin-related metadata should produce
     * an empty allow-plugins object. The auto-allow rules only kick in
     * for components/applications or for marker-flagged libraries.
     */
    public function testPlainLibraryWithoutMarkerProducesEmptyAllowPlugins(): void
    {
        $this->writeHordeYml(<<<'YAML'
            ---
            id: test
            name: Test
            type: library
            version:
              release: 1.0.0
              api: 1.0.0
            state:
              release: stable
              api: stable
            YAML);

        $wrapper = new HordeYml($this->tmpDir);
        $plugins = (array) $wrapper->getAllowedPlugins();
        $this->assertArrayNotHasKey('horde/horde-installer-plugin', $plugins);
    }

    /**
     * Components and applications get the plugin auto-allowed
     * regardless of any marker - that rule predates the marker
     * mechanism and is preserved.
     */
    public function testComponentTypeAutoAllowsInstallerPlugin(): void
    {
        $this->writeHordeYml(<<<'YAML'
            ---
            id: test
            name: Test
            type: component
            version:
              release: 1.0.0
              api: 1.0.0
            state:
              release: stable
              api: stable
            YAML);

        $wrapper = new HordeYml($this->tmpDir);
        $plugins = (array) $wrapper->getAllowedPlugins();
        $this->assertArrayHasKey('horde/horde-installer-plugin', $plugins);
        $this->assertTrue($plugins['horde/horde-installer-plugin']);
    }

    public function testApplicationTypeAutoAllowsInstallerPlugin(): void
    {
        $this->writeHordeYml(<<<'YAML'
            ---
            id: test
            name: Test
            type: application
            version:
              release: 1.0.0
              api: 1.0.0
            state:
              release: stable
              api: stable
            YAML);

        $wrapper = new HordeYml($this->tmpDir);
        $plugins = (array) $wrapper->getAllowedPlugins();
        $this->assertArrayHasKey('horde/horde-installer-plugin', $plugins);
        $this->assertTrue($plugins['horde/horde-installer-plugin']);
    }

    /**
     * The ci-platform-flags marker enables auto-allow for library types
     * that transitively pull horde/horde-installer-plugin via a
     * horde-library dep. The composer.json writer needs this entry or
     * composer 2.2+ silently disables the plugin and the install ends
     * up wired wrong.
     */
    public function testLibraryWithMarkerTrueAutoAllowsInstallerPlugin(): void
    {
        $this->writeHordeYml(<<<'YAML'
            ---
            id: test
            name: Test
            type: library
            version:
              release: 1.0.0
              api: 1.0.0
            state:
              release: stable
              api: stable
            ci-platform-flags:
              needs_installer_plugin: true
            YAML);

        $wrapper = new HordeYml($this->tmpDir);
        $plugins = (array) $wrapper->getAllowedPlugins();
        $this->assertArrayHasKey('horde/horde-installer-plugin', $plugins);
        $this->assertTrue($plugins['horde/horde-installer-plugin']);
    }

    /**
     * The marker explicitly set to false leaves the library plugin
     * allow-list untouched. The deps --platform pipeline writes
     * needs_installer_plugin: false when no transitive trigger is
     * detected; this case should NOT cause the plugin to be auto-allowed.
     */
    public function testLibraryWithMarkerFalseDoesNotAutoAllow(): void
    {
        $this->writeHordeYml(<<<'YAML'
            ---
            id: test
            name: Test
            type: library
            version:
              release: 1.0.0
              api: 1.0.0
            state:
              release: stable
              api: stable
            ci-platform-flags:
              needs_installer_plugin: false
            YAML);

        $wrapper = new HordeYml($this->tmpDir);
        $plugins = (array) $wrapper->getAllowedPlugins();
        $this->assertArrayNotHasKey('horde/horde-installer-plugin', $plugins);
    }

    private function writeHordeYml(string $yaml): void
    {
        file_put_contents($this->tmpDir . '/.horde.yml', $yaml);
    }
}
