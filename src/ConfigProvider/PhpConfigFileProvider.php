<?php

declare(strict_types=1);

namespace Horde\Components\ConfigProvider;

use Exception;

/**
 * PHP configuration file provider
 *
 * Reads and writes configuration from PHP files containing a $conf array.
 * Supports null values to explicitly unset settings and block cascading.
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
class PhpConfigFileProvider implements ConfigProvider
{
    private array $settings = [];
    private array $unsetKeys = [];

    public function __construct(private string $location)
    {
        $path = dirname($location);
        $file = basename($location);
        if (!file_exists($path)) {
            mkdir($path, 0o700, true);
        }
        if (!file_exists($location)) {
            $this->writeToDisk($location);
        }
        if (is_readable($location)) {
            $conf = [];
            require $location;

            // Separate actual values from null (unset) values
            foreach ($conf as $key => $value) {
                if ($value === null) {
                    $this->unsetKeys[] = $key;
                } else {
                    $this->settings[$key] = $value;
                }
            }
        }
    }

    public function hasSetting(string $id): bool
    {
        // Has a setting only if it exists and is not explicitly unset
        return array_key_exists($id, $this->settings) && !in_array($id, $this->unsetKeys);
    }

    public function getSetting(string $id): string
    {
        if (!$this->hasSetting($id)) {
            throw new Exception("Setting '$id' not found in PhpConfigFileProvider");
        }
        return $this->settings[$id];
    }

    public function isUnset(string $id): bool
    {
        return in_array($id, $this->unsetKeys);
    }

    public function getAvailableKeys(): array
    {
        // Return both set and unset keys for complete introspection
        return array_unique(array_merge(array_keys($this->settings), $this->unsetKeys));
    }

    public function isAvailable(): bool
    {
        return file_exists($this->location) && is_readable($this->location);
    }

    /**
     * Set a configuration value
     *
     * Currently only supports strings. Pass null to explicitly unset a value.
     *
     * @param string $key The configuration key
     * @param string|null $value The value, or null to unset
     */
    public function setSetting(string $key, ?string $value): void
    {
        if ($value === null) {
            // Explicitly unset - remove from settings and add to unset list
            unset($this->settings[$key]);
            if (!in_array($key, $this->unsetKeys)) {
                $this->unsetKeys[] = $key;
            }
        } else {
            // Set value - remove from unset list if present
            $this->settings[$key] = $value;
            $this->unsetKeys = array_filter($this->unsetKeys, fn($k) => $k !== $key);
        }
    }

    /**
     * Write configuration to disk
     *
     * Writes both regular settings and null values (for unset keys)
     */
    public function writeToDisk(): void
    {
        $fileContent = '<?php' . PHP_EOL . '//Horde Components Config File' . PHP_EOL . '$conf = [];' . PHP_EOL;

        // Write regular settings
        foreach ($this->settings as $id => $value) {
            $fileContent .= '$conf["' . $id . '"] = "' . $value . '";' . PHP_EOL;
        }

        // Write unset markers (null values)
        foreach ($this->unsetKeys as $id) {
            $fileContent .= '$conf["' . $id . '"] = null; // Explicitly unset - blocks cascade' . PHP_EOL;
        }

        file_put_contents($this->location, $fileContent);
    }
}
