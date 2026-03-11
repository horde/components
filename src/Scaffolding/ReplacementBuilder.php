<?php

/**
 * Builds replacement map for template placeholders.
 *
 * Copyright 2013-2026 The Horde Project (http://www.horde.org/)
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

namespace Horde\Components\Scaffolding;

/**
 * Builds replacement map for template placeholders.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class ReplacementBuilder
{
    /**
     * Build replacement map from configuration.
     *
     * @param array $config Configuration array with keys:
     *   - name: Component name (e.g., "MyApp")
     *   - type: Component type (application/library/theme)
     *   - author_name: Author's full name
     *   - author_email: Author's email
     *   - author_user: Author's Horde username (optional)
     *   - description: Short description
     *   - description_long: Long description (optional)
     *   - license_id: License identifier (default: LGPL-2.1)
     *   - license_uri: License URI (optional)
     *
     * @return array Replacement map for template processor
     */
    public static function build(array $config): array
    {
        $name = $config['name'] ?? 'Component';
        $type = $config['type'] ?? 'library';

        // Derive various name formats
        $nameCamel = self::toCamelCase($name);        // MyApp
        $nameLower = self::toLowerCase($name);         // myapp
        $nameTitle = self::toTitleCase($name);        // My App
        $nameUnderscore = self::toUnderscoreCase($name); // my_app

        // Author information
        $authorName = $config['author_name'] ?? 'Unknown Author';
        $authorEmail = $config['author_email'] ?? 'unknown@example.com';
        $authorUser = $config['author_user'] ?? self::deriveUsername($authorEmail);

        // Description
        $descShort = $config['description'] ?? "A Horde $type";
        $descLong = $config['description_long'] ?? $descShort;

        // License
        $licenseId = $config['license_id'] ?? 'LGPL-2.1';
        $licenseUri = $config['license_uri'] ?? self::getLicenseUri($licenseId);

        // Dates
        $year = date('Y');
        $date = date('Y-m-d');

        // URLs (for applications)
        $homepage = '';
        if ($type === 'application') {
            $homepage = "http://www.horde.org/apps/{$nameLower}";
        } elseif ($type === 'library') {
            $homepage = "http://www.horde.org/libraries/Horde_{$nameCamel}";
        }

        // Build replacement map
        // These will be used for text replacement in files
        return [
            // Component names (various formats)
            'Skeleton' => $nameCamel,
            'skeleton' => $nameLower,
            'SKELETON' => strtoupper($nameUnderscore),

            // Namespaces
            '\\Skeleton\\' => "\\{$nameCamel}\\",
            'namespace Skeleton' => "namespace {$nameCamel}",
            'use Skeleton\\' => "use {$nameCamel}\\",

            // File paths
            'skeleton/' => "{$nameLower}/",
            'horde/skeleton' => "horde/{$nameLower}",
            'Horde_Skeleton' => "Horde_{$nameCamel}",

            // Descriptions
            'Skeleton is ...' => "{$nameTitle} is {$descShort}",
            'Skeleton Application' => "{$nameTitle}",
            'Horde Skeleton' => "Horde {$nameTitle}",
            'Long, detailed description of Skeleton which may span multiple lines' => $descLong,
            'Short headline for Skeleton' => $descShort,

            // Author information
            'Some Person' => $authorName,
            'some.person@example.com' => $authorEmail,
            'tbd' => $authorUser,

            // Dates
            '2017' => $year,
            '2018' => $year,
            '2019' => $year,
            '2020' => $year,
            '2021' => $year,
            '2022' => $year,
            '2023' => $year,
            '2024' => $year,
            '2025' => $year,

            // URLs
            'http://www.horde.org/apps/skeleton' => $homepage,

            // License (only replace if not default LGPL-2.1)
            // Note: Order matters! More specific patterns first.
            'LGPL-2.1' => $licenseId,
            'http://www.horde.org/licenses/lgpl21' => $licenseUri,

            // Metadata
            'component_id' => $nameCamel,
            'component_name' => $nameLower,
            'component_title' => $nameTitle,
            'component_type' => $type,
        ];
    }

    /**
     * Convert name to CamelCase.
     */
    private static function toCamelCase(string $name): string
    {
        // Remove special characters, split on spaces/underscores/hyphens
        $parts = preg_split('/[\s_-]+/', $name);
        return implode('', array_map('ucfirst', $parts));
    }

    /**
     * Convert name to lowercase.
     */
    private static function toLowerCase(string $name): string
    {
        // Remove special characters except underscores/hyphens
        return strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', $name));
    }

    /**
     * Convert name to Title Case.
     */
    private static function toTitleCase(string $name): string
    {
        // Split on capital letters, spaces, underscores, hyphens
        $parts = preg_split('/(?=[A-Z])|[\s_-]+/', $name, -1, PREG_SPLIT_NO_EMPTY);
        return implode(' ', array_map('ucfirst', array_map('strtolower', $parts)));
    }

    /**
     * Convert name to underscore_case.
     */
    private static function toUnderscoreCase(string $name): string
    {
        // Insert underscores before capital letters
        $name = preg_replace('/(?<!^)[A-Z]/', '_$0', $name);
        // Replace spaces/hyphens with underscores
        $name = preg_replace('/[\s-]+/', '_', $name);
        return strtolower($name);
    }

    /**
     * Derive username from email address.
     */
    private static function deriveUsername(string $email): string
    {
        $parts = explode('@', $email);
        return $parts[0] ?? 'unknown';
    }

    /**
     * Get standard license URI for common licenses.
     */
    private static function getLicenseUri(string $licenseId): string
    {
        $uris = [
            'LGPL-2.1' => 'http://www.horde.org/licenses/lgpl21',
            'GPL-2.0' => 'http://www.horde.org/licenses/gpl',
            'BSD-2-Clause' => 'http://www.horde.org/licenses/bsd',
            'Apache-2.0' => 'http://www.horde.org/licenses/apache',
        ];

        return $uris[$licenseId] ?? 'http://www.horde.org/licenses/lgpl21';
    }
}
