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

namespace Horde\Components\Ci\Setup;

use Horde\Components\Exception;
use Horde\Components\Helper\Git;

/**
 * Detects CI environment and extracts configuration.
 *
 * Determines whether we're running in GitHub Actions or local mode,
 * and extracts relevant environment variables.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class EnvironmentDetector
{
    /**
     * Detect operational mode.
     *
     * @return string 'github' or 'local'
     */
    public static function detectMode(): string
    {
        // Check for explicit mode in environment
        $mode = getenv('CI_MODE');
        if ($mode !== false && in_array($mode, ['github', 'local'])) {
            return $mode;
        }

        // Auto-detect based on GitHub Actions environment
        if (getenv('GITHUB_ACTIONS') !== false) {
            return 'github';
        }

        // Default to local
        return 'local';
    }

    /**
     * Detect component name.
     *
     * @param string $componentPath Path to component
     * @return string Component name
     */
    public static function detectComponentName(string $componentPath): string
    {
        // Try from environment first
        $name = getenv('COMPONENT_NAME');
        if ($name !== false && $name !== '') {
            return $name;
        }

        // Try from GITHUB_REPOSITORY
        $repo = getenv('GITHUB_REPOSITORY');
        if ($repo !== false) {
            // Format: "horde/Db" -> "Db"
            $parts = explode('/', $repo);
            return end($parts);
        }

        // Fall back to directory name
        return basename($componentPath);
    }

    /**
     * Detect component branch.
     *
     * @param string $componentPath Path to component
     * @return string Branch name
     */
    public static function detectComponentBranch(string $componentPath): string
    {
        // Try from environment first
        $branch = getenv('COMPONENT_BRANCH');
        if ($branch !== false && $branch !== '') {
            return $branch;
        }

        // Try from GITHUB_REF
        $ref = getenv('GITHUB_REF');
        if ($ref !== false) {
            // Format: "refs/heads/FRAMEWORK_6_0" -> "FRAMEWORK_6_0"
            return preg_replace('#^refs/heads/#', '', $ref);
        }

        // Try from git
        $gitDir = $componentPath . '/.git';
        if (is_dir($gitDir)) {
            $git = new Git();
            $branch = $git->getCurrentBranch($componentPath);
            if ($branch !== '') {
                return $branch;
            }
        }

        // Default
        return 'FRAMEWORK_6_0';
    }

    /**
     * Get GitHub token.
     *
     * @return string|null Token or null if not available
     */
    public static function getGithubToken(): ?string
    {
        $token = getenv('GITHUB_TOKEN');
        return $token !== false ? $token : null;
    }

    /**
     * Get work directory.
     *
     * @return string Work directory path
     */
    public static function getWorkDir(): string
    {
        $workDir = getenv('CI_WORK_DIR');
        if ($workDir !== false && $workDir !== '') {
            return $workDir;
        }

        return '/tmp/horde-ci';
    }

    /**
     * Get local components path.
     *
     * @return string|null Local components path or null if not set
     */
    public static function getLocalComponentsPath(): ?string
    {
        $path = getenv('LOCAL_COMPONENTS_PATH');
        return $path !== false && $path !== '' ? $path : null;
    }

    /**
     * Get local component path.
     *
     * @return string|null Local component path or null if not set
     */
    public static function getLocalComponentPath(): ?string
    {
        $path = getenv('LOCAL_COMPONENT_PATH');
        return $path !== false && $path !== '' ? $path : null;
    }

    /**
     * Get path to currently running horde-components executable.
     *
     * Returns the absolute path to the horde-components script that is
     * currently executing. This works for both source installations and
     * PHAR archives.
     *
     * @return string Absolute path to horde-components executable
     */
    public static function getComponentsExecutablePath(): string
    {
        // Check if we're running from a PHAR
        if (class_exists('Phar') && \Phar::running(false) !== '') {
            return \Phar::running(false);
        }

        // Not a PHAR - find the bin/horde-components script
        // We're in src/Ci/Setup/, so go up to root then to bin/
        $binPath = realpath(__DIR__ . '/../../../bin/horde-components');

        if ($binPath === false) {
            // Fallback: try to find in PATH
            $which = trim((string) shell_exec('which horde-components 2>/dev/null'));
            if (!empty($which) && file_exists($which)) {
                return $which;
            }

            throw new Exception('Could not determine path to horde-components executable');
        }

        return $binPath;
    }

    /**
     * Get components PHAR URL.
     *
     * @return string PHAR URL
     */
    public static function getComponentsPharUrl(): string
    {
        $url = getenv('COMPONENTS_PHAR_URL');
        if ($url !== false && $url !== '') {
            return $url;
        }

        // Default URL (should be configurable in horde-components config)
        return 'https://dev.horde.org/ci/horde-components.phar';
    }

    /**
     * Build configuration array from environment.
     *
     * @param string|null $mode Override mode (null to auto-detect)
     * @param string|null $componentPath Component path (null to use current dir)
     * @return array<string,mixed> Configuration array for CiConfig
     */
    public static function buildConfig(?string $mode = null, ?string $componentPath = null): array
    {
        $mode = $mode ?? self::detectMode();
        $componentPath = $componentPath ?? getcwd();

        return [
            'mode' => $mode,
            'component_name' => self::detectComponentName($componentPath),
            'component_branch' => self::detectComponentBranch($componentPath),
            'component_path' => $componentPath,
            'work_dir' => self::getWorkDir(),
            'github_token' => self::getGithubToken(),
            'components_phar_url' => self::getComponentsPharUrl(),
            'local_components_path' => self::getLocalComponentsPath(),
            'components_path' => self::getComponentsExecutablePath(),  // NEW
        ];
    }

    /**
     * Validate environment for given mode.
     *
     * @param string $mode Mode to validate (github or local)
     * @return array<string> Error messages (empty if valid)
     */
    public static function validateEnvironment(string $mode): array
    {
        $errors = [];

        if ($mode === 'github') {
            if (getenv('GITHUB_ACTIONS') === false) {
                $errors[] = 'Not running in GitHub Actions environment';
            }
            if (getenv('GITHUB_TOKEN') === false) {
                $errors[] = 'GITHUB_TOKEN not set';
            }
        }

        if ($mode === 'local') {
            if (self::getLocalComponentsPath() === null) {
                $errors[] = 'LOCAL_COMPONENTS_PATH not set (required in local mode)';
            }
        }

        return $errors;
    }
}
