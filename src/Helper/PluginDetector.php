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

use Horde\Components\Wrapper\HordeYml as WrapperHordeYml;
use Horde\Components\Wrapper\ComposerJson as WrapperComposerJson;
use Horde\Components\Component\DependencyNode;

/**
 * Detects Composer plugins from various sources.
 *
 * Detection methods:
 * 1. Known plugins list (fast, no I/O)
 * 2. composer.json type field (requires file read)
 * 3. composer.json extra.class field (requires file read)
 * 4. Packagist API (requires network, opt-in)
 *
 * @author    Ralf Lang <lang@b1-systems.de>
 * @category  Horde
 * @copyright 2026 Horde LLC
 * @license   http://www.horde.org/licenses/lgpl21 LGPL 2.1
 * @package   Components
 */
class PluginDetector
{
    /**
     * Known Composer plugins (package name => plugin class)
     */
    private array $knownPlugins = [
        'horde/horde-installer-plugin' => 'Horde\\Composer\\HordeInstaller',
        'composer/installers' => 'Composer\\Installers\\Plugin',
        'composer/satis' => 'Composer\\Satis\\Console\\Application',
    ];

    /**
     * Constructor.
     *
     * @param bool $allowNetworkRequests Allow network requests to Packagist
     */
    public function __construct(
        private readonly bool $allowNetworkRequests = false
    ) {}

    /**
     * Add a known plugin to the detection list.
     *
     * @param string $packageName Package name (e.g., "vendor/package")
     * @param string $pluginClass Plugin class name
     */
    public function addKnownPlugin(string $packageName, string $pluginClass): void
    {
        $this->knownPlugins[$packageName] = $pluginClass;
    }

    /**
     * Get all known plugins.
     *
     * @return array Known plugins (package => class)
     */
    public function getKnownPlugins(): array
    {
        return $this->knownPlugins;
    }

    /**
     * Detect if a node is a Composer plugin and update it accordingly.
     *
     * @param DependencyNode $node Node to check
     * @return bool True if plugin detected
     */
    public function detect(DependencyNode $node): bool
    {
        // Method 1: Check known plugins list
        if ($this->detectFromKnownList($node)) {
            return true;
        }

        // Method 2: Check composer.json if path is available
        if (isset($node->metadata['composer_json_path'])) {
            if ($this->detectFromComposerJsonPath($node->metadata['composer_json_path'], $node)) {
                return true;
            }
        }

        // Method 3: Network request (opt-in)
        if ($this->allowNetworkRequests && $node->source !== 'git') {
            if ($this->detectFromPackagist($node)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if package is in known plugins list.
     *
     * @param DependencyNode $node Node to check
     * @return bool True if found in known list
     */
    private function detectFromKnownList(DependencyNode $node): bool
    {
        if (isset($this->knownPlugins[$node->name])) {
            $node->markAsPlugin($this->knownPlugins[$node->name]);
            return true;
        }

        return false;
    }

    /**
     * Detect plugin from composer.json file path.
     *
     * @param string $path Path to composer.json
     * @param DependencyNode $node Node to update
     * @return bool True if plugin detected
     */
    private function detectFromComposerJsonPath(string $path, DependencyNode $node): bool
    {
        if (!file_exists($path)) {
            return false;
        }

        try {
            $composerData = json_decode(file_get_contents($path), true);
            if (!is_array($composerData)) {
                return false;
            }

            return $this->detectFromComposerData($composerData, $node);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Detect plugin from parsed composer.json data.
     *
     * @param array $composerData Parsed composer.json
     * @param DependencyNode $node Node to update
     * @return bool True if plugin detected
     */
    private function detectFromComposerData(array $composerData, DependencyNode $node): bool
    {
        // Check type field
        if (isset($composerData['type']) && $composerData['type'] === 'composer-plugin') {
            // Extract plugin class from extra.class
            $pluginClass = null;
            if (isset($composerData['extra']['class'])) {
                $pluginClass = $composerData['extra']['class'];
            }

            $node->markAsPlugin($pluginClass);
            return true;
        }

        return false;
    }

    /**
     * Detect plugin from .horde.yml wrapper.
     *
     * @param WrapperHordeYml $yml .horde.yml wrapper
     * @param DependencyNode $node Node to update
     * @return bool True if plugin detected
     */
    public function detectFromHordeYml(WrapperHordeYml $yml, DependencyNode $node): bool
    {
        // Check if type is composer-plugin
        $type = $yml['type'] ?? '';
        if ($type === 'composer-plugin') {
            // Try to get plugin class from extra
            $pluginClass = null;
            if (isset($yml['extra']['class'])) {
                $pluginClass = $yml['extra']['class'];
            }

            $node->markAsPlugin($pluginClass);
            return true;
        }

        return false;
    }

    /**
     * Detect plugin from composer.json wrapper.
     *
     * @param WrapperComposerJson $composer composer.json wrapper
     * @param DependencyNode $node Node to update
     * @return bool True if plugin detected
     */
    public function detectFromComposerJson(WrapperComposerJson $composer, DependencyNode $node): bool
    {
        // Get composer data
        $data = json_decode((string) $composer, true);
        if (!is_array($data)) {
            return false;
        }

        return $this->detectFromComposerData($data, $node);
    }

    /**
     * Detect plugin from Packagist API (requires network access).
     *
     * @param DependencyNode $node Node to check
     * @return bool True if plugin detected
     */
    private function detectFromPackagist(DependencyNode $node): bool
    {
        // This would require network access - placeholder for future implementation
        // For now, we only support local detection methods
        return false;
    }

    /**
     * Batch detect plugins for multiple nodes.
     *
     * @param array $nodes Array of DependencyNode objects
     * @return array Array of detected plugin node keys
     */
    public function detectBatch(array $nodes): array
    {
        $detected = [];

        foreach ($nodes as $node) {
            if ($node instanceof DependencyNode && $this->detect($node)) {
                $detected[] = $node->key();
            }
        }

        return $detected;
    }
}
