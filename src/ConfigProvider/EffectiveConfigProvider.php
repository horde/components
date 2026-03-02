<?php

declare(strict_types=1);

namespace Horde\Components\ConfigProvider;

use Exception;

/**
 * Effective configuration provider with cascading lookup
 *
 * Implements a "first provider wins" strategy for configuration lookups.
 * Providers are checked in order - the first provider that has a setting
 * provides the value. Explicit unset values stop the cascade.
 *
 * Provides introspection capabilities to determine which provider layer
 * is actually providing a given configuration value.
 *
 * Copyright 2023-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class EffectiveConfigProvider implements ConfigProvider
{
    private iterable $providers;

    /**
     * Constructor
     *
     * @param ConfigProvider ...$providers Providers in precedence order (highest first)
     */
    public function __construct(ConfigProvider ...$providers)
    {
        $this->providers = $providers;
    }

    public function hasSetting(string $id): bool
    {
        foreach ($this->providers as $provider) {
            if (!$provider->isAvailable()) {
                continue;
            }

            // Check if explicitly unset at this layer - stops search
            if ($provider->isUnset($id)) {
                return false;
            }

            if ($provider->hasSetting($id)) {
                return true;
            }
        }
        return false;
    }

    public function getSetting(string $id): string
    {
        foreach ($this->providers as $provider) {
            if (!$provider->isAvailable()) {
                continue;
            }

            if ($provider->isUnset($id)) {
                throw new Exception("Setting '$id' is explicitly undefined in " . $this->getProviderName($provider));
            }

            if ($provider->hasSetting($id)) {
                return $provider->getSetting($id);
            }
        }
        throw new Exception("Setting '$id' not found in any provider");
    }

    public function isUnset(string $id): bool
    {
        // Check if any available provider explicitly unsets this key
        foreach ($this->providers as $provider) {
            if (!$provider->isAvailable()) {
                continue;
            }

            if ($provider->isUnset($id)) {
                return true;
            }

            // If provider has the setting, it's not unset
            if ($provider->hasSetting($id)) {
                return false;
            }
        }
        return false;
    }

    public function getAvailableKeys(): array
    {
        $keys = [];
        // Collect keys from all providers (reverse order to prioritize higher layers)
        foreach (array_reverse([...$this->providers]) as $provider) {
            if (!$provider->isAvailable()) {
                continue;
            }
            $keys = array_merge($keys, $provider->getAvailableKeys());
        }
        return array_unique($keys);
    }

    public function isAvailable(): bool
    {
        // Chain is available if ANY provider is available
        foreach ($this->providers as $provider) {
            if ($provider->isAvailable()) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get the provider that supplies a given setting
     *
     * Returns the first available provider that has this setting, or null
     * if explicitly unset or not found.
     *
     * @param string $id The setting key
     * @return ConfigProvider|null The providing layer, or null if not found/unset
     */
    public function getProvidingLayer(string $id): ?ConfigProvider
    {
        foreach ($this->providers as $provider) {
            if (!$provider->isAvailable()) {
                continue;
            }

            if ($provider->isUnset($id)) {
                return null; // Explicitly undefined
            }

            if ($provider->hasSetting($id)) {
                return $provider;
            }
        }
        return null;
    }

    /**
     * Get the name of the provider that supplies a given setting
     *
     * Useful for debugging and introspection.
     *
     * @param string $id The setting key
     * @return string|null The provider class name, or null if not found/unset
     */
    public function getProvidingLayerName(string $id): ?string
    {
        $provider = $this->getProvidingLayer($id);
        if ($provider === null) {
            return null;
        }
        return $this->getProviderName($provider);
    }

    /**
     * Get a human-readable name for a provider
     *
     * @param ConfigProvider $provider The provider instance
     * @return string The provider name
     */
    private function getProviderName(ConfigProvider $provider): string
    {
        $className = get_class($provider);
        // Strip namespace for cleaner display
        $parts = explode('\\', $className);
        return end($parts);
    }

    /**
     * Get diagnostic information about a setting
     *
     * Returns an array with details about where a setting comes from,
     * useful for debugging configuration issues.
     *
     * @param string $id The setting key
     * @return array Diagnostic information
     */
    public function getDiagnostics(string $id): array
    {
        $info = [
            'key' => $id,
            'exists' => $this->hasSetting($id),
            'is_unset' => $this->isUnset($id),
            'value' => null,
            'providing_layer' => null,
            'checked_layers' => [],
        ];

        if ($info['exists']) {
            $info['value'] = $this->getSetting($id);
            $info['providing_layer'] = $this->getProvidingLayerName($id);
        }

        // Show which layers were checked
        foreach ($this->providers as $provider) {
            $layerInfo = [
                'name' => $this->getProviderName($provider),
                'available' => $provider->isAvailable(),
                'has_setting' => false,
                'is_unset' => false,
            ];

            if ($provider->isAvailable()) {
                $layerInfo['has_setting'] = $provider->hasSetting($id);
                $layerInfo['is_unset'] = $provider->isUnset($id);
            }

            $info['checked_layers'][] = $layerInfo;
        }

        return $info;
    }
}
