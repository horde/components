<?php

/**
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

declare(strict_types=1);

namespace Horde\Components\Util;

use Horde\Components\Exception;
use Horde\Yaml\Yaml;
use Horde\Yaml\Exception as YamlException;

/**
 * Utility for loading YAML files using Horde\Yaml (PSR-4).
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class YamlLoader
{
    /**
     * Load a YAML file and return parsed data.
     *
     * @param string $file Path to YAML file
     * @return array<mixed> Parsed YAML data
     * @throws Exception If file doesn't exist or YAML is invalid
     */
    public static function loadFile(string $file): array
    {
        if (!file_exists($file)) {
            return [];
        }

        try {
            $result = Yaml::load(file_get_contents($file));
            // Yaml::load might return null for empty files
            return is_array($result) ? $result : [];
        } catch (YamlException $e) {
            throw new Exception("Failed to parse YAML file {$file}: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Dump data to YAML string.
     *
     * @param mixed $data Data to dump
     * @param int $indent Indentation
     * @param int $wordwrap Column to wrap at
     * @param bool $exceptionOnInvalidType Whether to throw exception on invalid types
     * @return string YAML string
     */
    public static function dump(
        $data,
        int $indent = 2,
        int $wordwrap = 0,
        bool $exceptionOnInvalidType = false
    ): string {
        return Yaml::dump($data, $indent, $wordwrap, $exceptionOnInvalidType);
    }
}
