<?php

declare(strict_types=1);

namespace Horde\Components\ConfigProvider;

use Exception;

/**
 * Readonly defaults builtin as last resort
 *
 * Provides hardcoded default configuration values. This is the lowest
 * precedence provider - values here are overridden by all other providers.
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
class BuiltinConfigProvider implements ConfigProvider
{
    public function __construct(
        private array $settings = []
    ) {}

    public function hasSetting(string $id): bool
    {
        return array_key_exists($id, $this->settings);
    }

    public function getSetting(string $id): string
    {
        if (!$this->hasSetting($id)) {
            throw new Exception("Setting '$id' not found in BuiltinConfigProvider");
        }
        return $this->settings[$id];
    }

    public function isUnset(string $id): bool
    {
        // Builtin defaults cannot unset values - they are the lowest layer
        return false;
    }

    public function getAvailableKeys(): array
    {
        return array_keys($this->settings);
    }

    public function isAvailable(): bool
    {
        // Builtin provider is always available
        return true;
    }

    /**
     * Get all settings as an array
     *
     * Used for initialization of user config files
     *
     * @return array All builtin settings
     */
    public function dumpSettings(): array
    {
        return $this->settings;
    }
}
