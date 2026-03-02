<?php

declare(strict_types=1);

namespace Horde\Components\ConfigProvider;

use Exception;

/**
 * CLI arguments configuration provider
 *
 * Provides configuration from command-line arguments. This is the highest
 * precedence provider - CLI args override all other configuration sources.
 *
 * Supports formats like:
 * - --config-key=value
 * - --github-token=xxx
 *
 * Copyright 2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class CliConfigProvider implements ConfigProvider
{
    private array $settings = [];

    /**
     * Constructor
     *
     * @param array $parsedOptions Parsed CLI options from Horde_Argv_Parser
     */
    public function __construct(array $parsedOptions)
    {
        // Filter out null values and convert dashes to dots
        // Example: 'github-token' => 'github.token'
        foreach ($parsedOptions as $key => $value) {
            if ($value !== null && $value !== false) {
                // Convert kebab-case to dot notation
                $normalizedKey = str_replace('-', '.', $key);
                $this->settings[$normalizedKey] = (string) $value;
            }
        }
    }

    public function hasSetting(string $id): bool
    {
        return array_key_exists($id, $this->settings);
    }

    public function getSetting(string $id): string
    {
        if (!$this->hasSetting($id)) {
            throw new Exception("Setting '$id' not found in CliConfigProvider");
        }
        return $this->settings[$id];
    }

    public function isUnset(string $id): bool
    {
        // CLI args cannot explicitly unset values
        return false;
    }

    public function getAvailableKeys(): array
    {
        return array_keys($this->settings);
    }

    public function isAvailable(): bool
    {
        // CLI provider is always available (even if no args provided)
        return true;
    }
}
