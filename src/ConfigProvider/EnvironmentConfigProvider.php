<?php

declare(strict_types=1);

namespace Horde\Components\ConfigProvider;

use Exception;

/**
 * A config provider based on environment variables
 *
 * Reads configuration from environment variables. Supports explicit unset
 * values to block cascading to lower-precedence providers.
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
class EnvironmentConfigProvider implements ConfigProvider
{
    /**
     * Sentinel value to indicate explicit unset
     */
    private const UNSET_MARKER = '__UNSET__';

    public function __construct(private array $settings) {}

    public function hasSetting(string $id): bool
    {
        if (!array_key_exists($id, $this->settings)) {
            return false;
        }
        // If explicitly unset, it doesn't "have" a value, but blocks cascade
        return $this->settings[$id] !== self::UNSET_MARKER;
    }

    public function getSetting(string $id): string
    {
        if (!$this->hasSetting($id)) {
            throw new Exception("Setting '$id' not found in EnvironmentConfigProvider");
        }
        return $this->settings[$id];
    }

    public function isUnset(string $id): bool
    {
        return array_key_exists($id, $this->settings)
            && $this->settings[$id] === self::UNSET_MARKER;
    }

    public function getAvailableKeys(): array
    {
        return array_keys($this->settings);
    }

    public function isAvailable(): bool
    {
        // Environment provider is always available (even if empty)
        return true;
    }
}
