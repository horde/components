<?php

declare(strict_types=1);

namespace Horde\Components\ConfigProvider;

/**
 * Configuration provider interface
 *
 * Provides a layered configuration system where multiple providers can be
 * chained together with explicit precedence rules.
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
interface ConfigProvider
{
    /**
     * Check if this provider has a setting
     *
     * @param string $id The setting key
     * @return bool True if the setting exists and has a value
     */
    public function hasSetting(string $id): bool;

    /**
     * Get the value of a setting
     *
     * All settings are ultimately strings for now
     *
     * @param string $id The setting key
     * @return string The setting value
     * @throws \Exception if the setting does not exist
     */
    public function getSetting(string $id): string;

    /**
     * Check if a setting is explicitly unset at this layer
     *
     * An unset setting blocks cascading to lower-precedence providers.
     * This allows higher layers to explicitly mask values from lower layers.
     *
     * Example: User config can set a value to null to prevent fallback
     * to environment variables or builtin defaults.
     *
     * @param string $id The setting key
     * @return bool True if the setting is explicitly unset (blocks cascade)
     */
    public function isUnset(string $id): bool;

    /**
     * Get all available setting keys in this provider
     *
     * Used for introspection and debugging. Returns all keys that either
     * have values or are explicitly unset.
     *
     * @return array List of setting keys available in this provider
     */
    public function getAvailableKeys(): array;

    /**
     * Check if this provider is available/initialized
     *
     * Allows graceful handling when a provider's underlying data source
     * is unavailable (e.g., config file doesn't exist, environment not set).
     *
     * @return bool True if this provider is operational
     */
    public function isAvailable(): bool;
}
