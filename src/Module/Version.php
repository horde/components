<?php

/**
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

namespace Horde\Components\Module;

use Horde\Components\Component;
use Horde\Components\Output;
use Horde\Components\Exception;
use Horde\Components\Component\ComponentDirectory;
use Horde\Components\RuntimeContext\CurrentWorkingDirectory;
use Horde\Components\Helper\Git;
use Horde\Version\RelaxedSemanticVersion;
use Horde\Version\InvalidVersionException;

/**
 * Display version information for horde-components tool and UUT.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Version extends Base
{
    /**
     * Return the title for the option group representing this module.
     *
     * @return string The group title.
     */
    public function getOptionGroupTitle(): string
    {
        return 'Version Information';
    }

    /**
     * Return the description for the option group representing this module.
     *
     * @return string The group description.
     */
    public function getOptionGroupDescription(): string
    {
        return 'Display version information';
    }

    /**
     * Return the options for this module.
     *
     * @return array The group options.
     */
    public function getOptionGroupOptions(): array
    {
        return [];
    }

    /**
     * Get the usage title for this module.
     *
     * @return string The title.
     */
    public function getTitle(): string
    {
        return 'version';
    }

    /**
     * Get the usage description for this module.
     *
     * @return string The description.
     */
    public function getUsage(): string
    {
        return 'Display version information';
    }

    /**
     * Return the action arguments supported by this module.
     *
     * @return array A list of supported action arguments.
     */
    public function getActions(): array
    {
        return ['version'];
    }

    /**
     * Return the help text for the specified action.
     *
     * @param string $action The action.
     *
     * @return string The help text.
     */
    public function getHelp($action): string
    {
        return 'Display version information

Usage:
    horde-components version

This command displays:
- Tool Version: Version of the horde-components tool itself
- UUT Version: Version of the Unit Under Test (component in current directory)

The UUT version is read from the component\'s .horde.yml file.
';
    }

    /**
     * Return the options that should be explained in the context help.
     *
     * @return array A list of option help texts.
     */
    public function getContextOptionHelp(): array
    {
        return [];
    }

    /**
     * Determine if this module should act. Run all required actions if it has
     * been instructed to do so.
     *
     * @param array $options CLI options
     * @param array $arguments CLI arguments
     * @param Component|null $component The selected component (if any)
     *
     * @return bool True if the module performed some action.
     */
    public function handle(array $options, array $arguments, ?Component $component = null): bool
    {
        if (!isset($arguments[0]) || $arguments[0] !== 'version') {
            return false;
        }

        /** @var Output $output */
        $output = $this->dependencies->getInstance(Output::class);

        // Get tool version
        $toolVersion = $this->getToolVersion();
        $formattedToolVersion = $this->formatVersion($toolVersion);
        $output->plain("Tool Version: {$formattedToolVersion}");

        // Get UUT version - resolve component from working directory if not provided
        if ($component === null) {
            try {
                $componentDirectory = new ComponentDirectory(new CurrentWorkingDirectory());
                $component = $this->dependencies
                    ->getComponentFactory()
                    ->createSource($componentDirectory);
            } catch (\Exception $e) {
                $output->warn("UUT Version: Not available (No component found: {$e->getMessage()})");
                return true;
            }
        }

        try {
            $uutVersion = $this->getUutVersion($component);
            $formattedUutVersion = $this->formatVersion($uutVersion);
            $output->plain("UUT Version: {$formattedUutVersion}");
        } catch (Exception $e) {
            $output->warn("UUT Version: Not available ({$e->getMessage()})");
        }

        return true;
    }

    /**
     * Get the version of the horde-components tool.
     *
     * @return string Tool version
     */
    private function getToolVersion(): string
    {
        // Check if running from PHAR
        if (class_exists('Phar') && \Phar::running(false) !== '') {
            // Try to get git version from PHAR manifest
            $pharPath = \Phar::running(false);
            try {
                $phar = new \Phar($pharPath);
                $meta = $phar->getMetadata();
                if (is_array($meta) && isset($meta['version'])) {
                    return $meta['version'];
                }
            } catch (\Exception $e) {
                // Ignore and fall through
            }

            // Try reading from embedded box.json.dist
            $boxJsonPath = 'phar://' . $pharPath . '/box.json.dist';
            if (file_exists($boxJsonPath)) {
                $boxJson = json_decode(file_get_contents($boxJsonPath), true);
                if (isset($boxJson['replacements']['package_version'])) {
                    return $boxJson['replacements']['package_version'];
                }
            }
        } else {
            // Running from source - try to get git describe
            $componentsDir = dirname(__DIR__, 2);
            if (is_dir($componentsDir . '/.git')) {
                $git = new Git();
                $gitDescribe = $git->describeWithTags($componentsDir);
                if (!empty($gitDescribe)) {
                    return $gitDescribe;
                }
            }

            // Fall back to reading from .horde.yml in components directory
            $componentsHordeYml = dirname(__DIR__, 2) . '/.horde.yml';
            if (file_exists($componentsHordeYml)) {
                $content = file_get_contents($componentsHordeYml);
                if (preg_match('/^version:\s*release:\s*(.+)$/m', $content, $matches)) {
                    return trim($matches[1]);
                }
            }
        }

        return 'dev';
    }

    /**
     * Get the version of the Unit Under Test (component).
     *
     * @param Component $component The component
     * @return string UUT version
     * @throws Exception If version cannot be determined
     */
    private function getUutVersion(Component $component): string
    {
        try {
            $version = $component->getVersion();
            return $version;
        } catch (\Exception $e) {
            throw new Exception('Could not read component version: ' . $e->getMessage());
        }
    }

    /**
     * Format a version string as strict SemVer V2.
     *
     * @param string $versionString The version string to format
     * @return string Formatted SemVer V2 version
     */
    private function formatVersion(string $versionString): string
    {
        try {
            $version = new RelaxedSemanticVersion($versionString);
            return $version->formatSemVerV2();
        } catch (InvalidVersionException $e) {
            // If it doesn't parse as semantic version, return as-is
            return $versionString;
        }
    }
}
