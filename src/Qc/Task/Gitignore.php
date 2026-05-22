<?php

/**
 * Components_Qc_Task_Gitignore:: checks .gitignore for required entries.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

namespace Horde\Components\Qc\Task;

use Horde\HordeYmlFile\HordeYmlFile;

/**
 * Components_Qc_Task_Gitignore:: checks .gitignore for required entries.
 *
 * Copyright 2026 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Gitignore extends Base
{
    /**
     * Required entries that should be in .gitignore
     */
    private const REQUIRED_ENTRIES = [
        '/build/' => 'Build artifacts directory',
        '/vendor/' => 'Composer dependencies directory',
        '/composer.lock' => 'Composer lock file (libraries should not commit lock files)',
        '/.idea/' => 'PHPStorm IDE settings',
        '/.vscode/' => 'VSCode IDE settings',
        '/.claude/' => 'Claude Code CLI cache and state',
        '/.cline/' => 'Cline extension data',
        '/.php-cs-fixer.cache' => 'PHP CS Fixer cache file',
        '/.phpunit.result.cache' => 'PHPUnit result cache',
        '/.phpunit.cache' => 'PHPUnit Cache (other)',
        '/phpstan.neon' => 'PHPStan local configuration',
        '/.phpstan.cache/' => 'PHPStan cache directory',
    ];

    /**
     * Additional entries for components using the horde-installer-plugin
     */
    private const INSTALLER_PLUGIN_ENTRIES = [
        '/var/' => 'Horde installer plugin runtime data',
        '/web/' => 'Horde installer plugin web-accessible directory',
    ];

    /**
     * Component types that use the horde-installer-plugin
     */
    private const INSTALLER_PLUGIN_TYPES = ['library', 'application', 'component', 'horde-theme'];

    /**
     * Get the name of this task.
     *
     * @return string The task name.
     */
    public function getName(): string
    {
        return '.gitignore check';
    }

    /**
     * Run the task.
     *
     * @param array &$options Additional options.
     *
     * @return int Number of errors.
     */
    public function run(array &$options = []): int
    {
        $componentPath = $this->getPath();

        if (empty($componentPath)) {
            $componentPath = getcwd();
        }

        $requiredEntries = $this->getRequiredEntries($componentPath);
        $gitignorePath = $this->findGitignore($componentPath);

        if ($gitignorePath === null) {
            $this->getOutput()->warn('No .gitignore file found');

            if (!empty($options['fix_qc_issues'])) {
                return $this->createGitignore($componentPath, $requiredEntries);
            }

            return 1; // Error: no .gitignore found
        }

        $this->getOutput()->info('Found .gitignore at: ' . $gitignorePath);

        // Read and parse .gitignore
        $content = file_get_contents($gitignorePath);
        $lines = explode("\n", $content);

        // Check for required entries
        $missing = [];
        foreach ($requiredEntries as $pattern => $description) {
            if (!$this->hasPattern($lines, $pattern)) {
                $missing[$pattern] = $description;
            }
        }

        if (empty($missing)) {
            $this->getOutput()->ok('All required patterns are present in .gitignore');
            return 0;
        }

        // Report missing entries
        $this->getOutput()->warn('Missing required .gitignore entries:');
        foreach ($missing as $pattern => $description) {
            $this->getOutput()->plain('  ' . $pattern . ' (' . $description . ')');
        }

        // Fix if requested
        if (!empty($options['fix_qc_issues'])) {
            return $this->addMissingEntries($gitignorePath, $missing);
        }

        return count($missing);
    }

    /**
     * Find .gitignore file in canonical locations.
     *
     * @param string $componentPath Path to the component.
     *
     * @return string|null Path to .gitignore or null if not found.
     */
    private function findGitignore(string $componentPath): ?string
    {
        // Canonical locations in order of preference
        $locations = [
            $componentPath . '/.gitignore',
            $componentPath . '/gitignore',
            $componentPath . '/.git/info/exclude',
        ];

        foreach ($locations as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Check if a pattern exists in .gitignore lines.
     *
     * @param array $lines Lines from .gitignore.
     * @param string $pattern Pattern to search for.
     *
     * @return bool True if pattern is found.
     */
    private function hasPattern(array $lines, string $pattern): bool
    {
        // Normalize pattern for comparison (remove leading slash for comparison)
        $normalizedPattern = ltrim($pattern, '/');

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip comments and empty lines
            if (empty($line) || str_starts_with($line, '#')) {
                continue;
            }

            $normalizedLine = ltrim($line, '/');

            // Check for exact match or pattern match
            if ($normalizedLine === $normalizedPattern || $line === $pattern) {
                return true;
            }

            // Also check for patterns like "build/" matching "/build/"
            if (rtrim($normalizedLine, '/') === rtrim($normalizedPattern, '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Create a new .gitignore file with required entries.
     *
     * @param string $componentPath Path to the component.
     * @param array $entries Required patterns and descriptions.
     *
     * @return int Number of errors (0 = success).
     */
    private function createGitignore(string $componentPath, array $entries): int
    {
        $gitignorePath = $componentPath . '/.gitignore';

        $content = "# Generated by horde-components QC\n";
        $content .= "# Standard ignores for Horde components\n\n";

        foreach ($entries as $pattern => $description) {
            $content .= "# $description\n";
            $content .= "$pattern\n\n";
        }

        if (file_put_contents($gitignorePath, $content) === false) {
            $this->getOutput()->warn('Failed to create .gitignore file');
            return 1;
        }

        $this->getOutput()->ok('Created .gitignore with required entries at: ' . $gitignorePath);
        return 0;
    }

    /**
     * Add missing entries to existing .gitignore.
     *
     * @param string $gitignorePath Path to .gitignore file.
     * @param array $missing Missing patterns and descriptions.
     *
     * @return int Number of errors (0 = success).
     */
    private function addMissingEntries(string $gitignorePath, array $missing): int
    {
        $content = file_get_contents($gitignorePath);

        // Ensure file ends with newline
        if (!str_ends_with($content, "\n")) {
            $content .= "\n";
        }

        $content .= "\n# Added by horde-components QC --fix-qc-issues\n";

        foreach ($missing as $pattern => $description) {
            $content .= "# $description\n";
            $content .= "$pattern\n";
        }

        if (file_put_contents($gitignorePath, $content) === false) {
            $this->getOutput()->warn('Failed to update .gitignore file');
            return count($missing);
        }

        $this->getOutput()->ok('Added ' . count($missing) . ' missing entries to .gitignore');
        return 0;
    }

    /**
     * Get the full set of required entries for this component.
     *
     * Includes installer-plugin entries (var/, web/) when the component
     * type uses the horde-installer-plugin.
     *
     * @param string $componentPath Path to the component.
     *
     * @return array Pattern => description pairs.
     */
    private function getRequiredEntries(string $componentPath): array
    {
        $entries = self::REQUIRED_ENTRIES;

        $hordeYmlPath = $componentPath . '/.horde.yml';
        if (file_exists($hordeYmlPath)) {
            $hordeYml = new HordeYmlFile($hordeYmlPath);
            $type = $hordeYml->getType();
            if (in_array($type, self::INSTALLER_PLUGIN_TYPES, true)) {
                $entries = array_merge($entries, self::INSTALLER_PLUGIN_ENTRIES);
            }
        }

        return $entries;
    }
}
