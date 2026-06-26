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

namespace Horde\Components\Ci\Config;

use InvalidArgumentException;

/**
 * CI configuration container.
 *
 * Holds all configuration needed for CI setup and execution.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class CiConfig
{
    /**
     * Operational mode (github or local).
     */
    public readonly string $mode;

    /**
     * Component name (e.g., "Db", "Http").
     */
    public readonly string $componentName;

    /**
     * Component branch (e.g., "FRAMEWORK_6_0").
     */
    public readonly string $componentBranch;

    /**
     * Component type (library, horde-library, application, bundle).
     */
    public readonly string $componentType;

    /**
     * Base working directory for CI operations.
     */
    public readonly string $workDir;

    /**
     * Path to component source (already checked out).
     */
    public readonly string $componentPath;

    /**
     * PHP versions to test (e.g., ['8.2', '8.3', '8.4', '8.5']).
     *
     * @var array<string>
     */
    public readonly array $phpVersions;

    /**
     * Minimum PHP version from component's .horde.yml.
     */
    public readonly string $minPhpVersion;

    /**
     * Component's stability (alpha, beta, RC, stable).
     */
    public readonly string $componentStability;

    /**
     * Required PHP extensions.
     *
     * @var array<string>
     */
    public readonly array $requiredExtensions;

    /**
     * GitHub token (for API access).
     */
    public readonly ?string $githubToken;

    /**
     * URL to download horde-components.phar.
     */
    public readonly ?string $componentsPharUrl;

    /**
     * Path to horde-components binary (local mode).
     */
    public readonly ?string $localComponentsPath;

    /**
     * Path to horde-components executable (binary or PHAR).
     * Used by lane scripts to invoke QC tasks.
     */
    public readonly string $componentsPath;

    /**
     * Constructor.
     *
     * @param array<string,mixed> $config Configuration array
     */
    public function __construct(array $config)
    {
        $this->mode = $config['mode'] ?? 'github';
        $this->componentName = $config['component_name'] ?? '';
        $this->componentBranch = $config['component_branch'] ?? 'FRAMEWORK_6_0';
        $this->componentType = $config['component_type'] ?? 'library';
        $this->workDir = $config['work_dir'] ?? '/tmp/horde-ci';
        $this->componentPath = $config['component_path'] ?? getcwd();
        // Lane set is normally computed by SetupCommand::readComponentInfo()
        // (which calls PlatformResolver::phpVersionLaneSet() against the
        // component's `dependencies.required.php` constraint) and merged
        // into this config on the second pass. The static slice below is
        // a defensive last-resort for callers that skip the readComponentInfo
        // step entirely; it should not be the normal path in production.
        $this->phpVersions = $config['php_versions'] ?? ['8.2', '8.3', '8.4', '8.5'];
        $this->minPhpVersion = $config['min_php_version'] ?? '8.2';
        $this->componentStability = $config['component_stability'] ?? 'alpha';
        $this->requiredExtensions = $config['required_extensions'] ?? [];
        $this->githubToken = $config['github_token'] ?? null;
        $this->componentsPharUrl = $config['components_phar_url'] ?? null;
        $this->localComponentsPath = $config['local_components_path'] ?? null;

        // Components path must be provided - no guessing
        if (!isset($config['components_path'])) {
            throw new InvalidArgumentException('components_path must be provided in configuration');
        }
        $this->componentsPath = $config['components_path'];
    }

    /**
     * Get PHP versions to actually test.
     *
     * Filters out PHP versions below the component's minimum.
     *
     * @return array<string> PHP versions to test
     */
    public function getTestablePhpVersions(): array
    {
        return array_filter(
            $this->phpVersions,
            fn($version) => version_compare($version, $this->minPhpVersion, '>=')
        );
    }

    /**
     * Get all test lanes (PHP version × stability combinations).
     *
     * @return array<array{php: string, stability: string, dir: string}>
     */
    public function getTestLanes(): array
    {
        $lanes = [];
        $testablePhpVersions = $this->getTestablePhpVersions();

        foreach ($testablePhpVersions as $phpVersion) {
            // Dev lane — minimum-stability=dev so transitive deps may
            // resolve to dev branches.
            $lanes[] = [
                'php' => $phpVersion,
                'stability' => 'dev',
                'dir' => $this->workDir . '/lanes/php' . $phpVersion . '-dev/' . $this->componentName,
            ];

            // Second lane uses the component's own declared stability
            // (alpha, beta, RC, stable). The directory name carries the
            // same string so downstream readers (RunCommand::discoverLanes,
            // log labels, artifact globs) all see one consistent label.
            // Previously this was hardcoded to "-stable" while the
            // stability field carried the component's value, causing the
            // same lane to appear as both "php8.5-stable" and
            // "php8.5-alpha" in different log lines.
            $lanes[] = [
                'php' => $phpVersion,
                'stability' => $this->componentStability,
                'dir' => $this->workDir . '/lanes/php' . $phpVersion . '-' . $this->componentStability . '/' . $this->componentName,
            ];
        }

        return $lanes;
    }

    /**
     * Check if this is GitHub Actions mode.
     *
     * @return bool
     */
    public function isGithubMode(): bool
    {
        return $this->mode === 'github';
    }

    /**
     * Check if this is local development mode.
     *
     * @return bool
     */
    public function isLocalMode(): bool
    {
        return $this->mode === 'local';
    }

    /**
     * Validate configuration.
     *
     * @return array<string> Error messages (empty if valid)
     */
    public function validate(): array
    {
        $errors = [];

        if (empty($this->componentName)) {
            $errors[] = 'Component name is required';
        }

        if (empty($this->componentPath)) {
            $errors[] = 'Component path is required';
        }

        if (!is_dir($this->componentPath)) {
            $errors[] = "Component path does not exist: {$this->componentPath}";
        }

        if ($this->isGithubMode() && empty($this->githubToken)) {
            $errors[] = 'GitHub token is required in github mode';
        }

        if (empty($this->phpVersions)) {
            $errors[] = 'At least one PHP version must be specified';
        }

        if (!in_array($this->componentType, ['library', 'horde-library', 'application', 'bundle'])) {
            $errors[] = "Unknown component type: {$this->componentType}";
        }

        return $errors;
    }
}
