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
use Horde\Components\Output;
use Horde\HordeYmlFile\HordeYmlFile;

/**
 * Installs PHP extensions for multiple PHP versions.
 *
 * Uses apt-get to install PHP extensions from ondrej PPA.
 *
 * Resolution strategy (in order):
 *
 * 1. **Preferred**: read `.horde.yml`'s `ci-platform.<minor>` section.
 *    Each minor entry is a list produced by
 *    `horde-components dependencies --platform`; this is the only data
 *    source that captures *transitive* extension requirements
 *    (`horde/mapi` → `ext-bcmath`, etc). When present, every lane gets
 *    exactly the extensions resolved for its PHP minor.
 *
 * 2. **Fallback** (no ci-platform yet, or marked `not resolvable`): the
 *    legacy hybrid detection — baseline extensions + composer.json
 *    `require`/`require-dev` walk + static per-component map.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class ExtensionInstaller
{
    /**
     * Baseline extensions installed for all components.
     */
    private const BASELINE_EXTENSIONS = [
        'cli',      // PHP CLI (usually installed with php-cli package)
        'common',   // Common files (timezone data, etc.)
        'curl',     // cURL
        'dom',      // DOM
        'intl',     // Internationalization (required by horde/core)
        'json',     // JSON (built-in in 8.0+, but package may exist)
        'mbstring', // Multibyte string
        'xml',      // XML
    ];

    /**
     * Component-specific extension mapping.
     *
     * @var array<string,array<string>>
     */
    private const COMPONENT_EXTENSIONS = [
        'imap_client' => ['imap'],
        'db' => ['pdo', 'mysql', 'pgsql', 'sqlite3'],
        'image' => ['gd'],
        'compress' => ['zip', 'bz2'],
        'ldap' => ['ldap'],
        'soap' => ['soap'],
    ];

    /**
     * Constructor.
     *
     * @param Output $output Output handler
     */
    public function __construct(
        private readonly Output $output
    ) {}

    /**
     * Detect required extensions for component.
     *
     * Uses hybrid strategy:
     * 1. Baseline extensions (always included)
     * 2. Extensions from composer.json require (ext-*)
     * 3. Static mapping based on component name
     *
     * Prefer {@see detectExtensionsPerVersion()} when the component has
     * a resolved `ci-platform` block in `.horde.yml`; this flat method
     * cannot represent per-PHP-minor differences and is kept as the
     * fallback for components that have not yet run
     * `horde-components dependencies --platform`.
     *
     * @param string $componentPath Path to component
     * @param string $componentName Component name
     * @return array<string> Required extension names
     */
    public function detectExtensions(string $componentPath, string $componentName): array
    {
        $extensions = self::BASELINE_EXTENSIONS;

        // Add from composer.json
        $composerExtensions = $this->extractFromComposer($componentPath);
        $extensions = array_merge($extensions, $composerExtensions);

        // Add from static mapping
        $componentKey = strtolower($componentName);
        if (isset(self::COMPONENT_EXTENSIONS[$componentKey])) {
            $extensions = array_merge($extensions, self::COMPONENT_EXTENSIONS[$componentKey]);
        }

        // Deduplicate and sort
        $extensions = array_unique($extensions);
        sort($extensions);

        return $extensions;
    }

    /**
     * Detect required extensions per PHP minor version.
     *
     * Returns a map of `<phpMinor> => <list of ext names>` so each lane
     * only installs the extensions actually needed for its resolved
     * dependency tree under that PHP version.
     *
     * Source of truth, in order of preference:
     *
     * 1. `.horde.yml`'s `ci-platform` section, written by
     *    `horde-components dependencies --platform`. This is the only
     *    place where *transitive* requirements (e.g. `horde/mapi` →
     *    `ext-bcmath`) are visible without running composer.
     *    - A list under a minor key means "install exactly these".
     *    - The literal string {@see PlatformResolver::NOT_RESOLVABLE}
     *      under a minor key means "couldn't resolve a lock for this
     *      PHP version, fall back to flat detection".
     *
     * 2. Flat detection via {@see detectExtensions()} when the ci-platform
     *    map has no entry for that minor (or the whole file is missing
     *    the ci-platform section). This is the same set the legacy
     *    bootstrap installed; it will miss transitive extensions but
     *    will not be empty.
     *
     * The returned map preserves the order of `$phpVersions`. Every
     * version in `$phpVersions` is represented in the map even if its
     * value is just the baseline set.
     *
     * @param string $componentPath Path to component on disk
     * @param string $componentName Component identifier (e.g. "ActiveSync")
     * @param array<string> $phpVersions PHP minors to compute sets for
     *                                   (e.g. ["8.2","8.3","8.4","8.5"])
     * @return array<string,list<string>> Map of minor → ext names
     */
    public function detectExtensionsPerVersion(
        string $componentPath,
        string $componentName,
        array $phpVersions
    ): array {
        $ciPlatform = $this->loadCiPlatform($componentPath);
        $fallback = $this->detectExtensions($componentPath, $componentName);

        $out = [];
        foreach ($phpVersions as $minor) {
            $resolved = $this->extractExtensionsForMinor($ciPlatform, $minor);
            if ($resolved === null) {
                // Either ci-platform missing entirely or this minor has
                // no usable entry. Use flat detection.
                $out[$minor] = $fallback;
                continue;
            }

            // ci-platform lists ext-* per minor. Always merge with the
            // baseline because the resolver only surfaces what packages
            // *declare*: PHP itself rarely requires you to install
            // ext-json (it's built in) but `dom`/`intl` etc. would be
            // missed if they weren't pulled in transitively. Baseline
            // covers the runtime essentials Horde components implicitly
            // rely on.
            $merged = array_values(array_unique(array_merge(self::BASELINE_EXTENSIONS, $resolved)));
            sort($merged);
            $out[$minor] = $merged;
        }
        return $out;
    }

    /**
     * Read the `ci-platform` section from a component's `.horde.yml`.
     *
     * Returns an empty array when the file is missing, malformed, or
     * has no ci-platform key. Callers must be prepared for any subset
     * of PHP minors to be absent — components only ran the resolver
     * for the range their constraint allowed.
     *
     * We pull from `toArray()` rather than `get('ci-platform')` because
     * `HordeYmlFile::get` returns nested associative data as stdClass
     * objects, but our extractor walks lists and string-keyed maps; a
     * plain array round-trips through json/var_export as the parser
     * sees it. The keys are stringified PHP minors ("8.3" etc.); cast
     * defensively below in case Symfony YAML produces ints on a
     * future version.
     *
     * @return array<string,mixed> The ci-platform map keyed by minor.
     */
    private function loadCiPlatform(string $componentPath): array
    {
        $hordeYmlPath = $componentPath . '/.horde.yml';
        if (!is_file($hordeYmlPath)) {
            return [];
        }
        try {
            $hordeYml = new HordeYmlFile($hordeYmlPath);
        } catch (\Throwable) {
            return [];
        }
        $data = $hordeYml->toArray();
        $section = $data['ci-platform'] ?? [];
        if (!is_array($section)) {
            return [];
        }
        // Normalise keys to strings so caller's array_key_exists(string) works
        // regardless of whether the YAML parser produced ints (8.3 is a float
        // in YAML 1.2 unless quoted, but every consumer expects "8.3").
        $normalised = [];
        foreach ($section as $key => $value) {
            $normalised[(string) $key] = $value;
        }
        return $normalised;
    }

    /**
     * Pull the ext-* list for a single PHP minor out of a ci-platform
     * map. Returns null when the entry is missing, not resolvable, or
     * malformed; returns a list (possibly empty) when the entry exists
     * and is a list.
     *
     * The shape we accept matches what
     * {@see \Horde\Components\Runner\Dependencies::runPlatformResolve()}
     * writes:
     *
     *   ci-platform:
     *     "8.3":
     *       - php: "^8.3"            # single-key map (php / composer-*)
     *       - composer-runtime-api: "^2"
     *       - ext-bcmath             # bare string (ext-* / lib-*)
     *       - ext-curl
     *     "8.4": "not resolvable"
     *
     * @param array<string,mixed> $ciPlatform Result of {@see loadCiPlatform()}
     * @return list<string>|null Extension names (without ext- prefix)
     *                            or null when no usable entry.
     */
    private function extractExtensionsForMinor(array $ciPlatform, string $minor): ?array
    {
        if (!array_key_exists($minor, $ciPlatform)) {
            return null;
        }
        $entry = $ciPlatform[$minor];
        if (is_string($entry)) {
            // 'not resolvable' or any other scalar — caller falls back.
            return null;
        }
        if (!is_array($entry)) {
            return null;
        }

        $exts = [];
        foreach ($entry as $item) {
            if (is_string($item) && str_starts_with($item, 'ext-')) {
                $exts[] = substr($item, 4);
                continue;
            }
            // Single-key maps (php / composer-*) are intentionally
            // ignored here: PhpInstaller handles the PHP itself, and
            // composer-meta packages are not installable extensions.
        }
        return array_values(array_unique($exts));
    }

    /**
     * Install extensions per PHP version, given a per-version map.
     *
     * Each lane gets exactly the extension set computed by
     * {@see detectExtensionsPerVersion()}; sibling PHP minors do not
     * affect each other. Falls through to the existing per-extension
     * install path, so partial failures (one extension unavailable for
     * one minor) are still tolerated rather than aborting the run.
     *
     * @param array<string,list<string>> $extensionsPerVersion Map of
     *        php minor → list of ext names (no `ext-` prefix).
     * @return bool True if the loop completed (individual extension
     *              failures are warned, not thrown).
     */
    public function installPerVersion(array $extensionsPerVersion): bool
    {
        if (empty($extensionsPerVersion)) {
            $this->output->info('No extensions to install');
            return true;
        }

        $failed = [];
        $succeeded = [];

        foreach ($extensionsPerVersion as $phpVersion => $extensions) {
            if (!is_array($extensions) || $extensions === []) {
                continue;
            }
            $this->output->info(sprintf(
                'PHP %s: %s',
                $phpVersion,
                implode(', ', $extensions)
            ));
            foreach ($extensions as $extension) {
                $result = $this->installExtension($extension, $phpVersion);

                if ($result === true) {
                    $succeeded[] = "php{$phpVersion}-{$extension}";
                } elseif ($result === false) {
                    $failed[] = "php{$phpVersion}-{$extension}";
                }
                // null means skipped (already installed or not available)
            }
        }

        if (!empty($succeeded)) {
            $this->output->ok('Installed: ' . implode(', ', $succeeded));
        }
        if (!empty($failed)) {
            // Per-extension failure is non-fatal; the relevant lane will
            // surface a real error at composer install or test time.
            $this->output->warn('Failed to install: ' . implode(', ', $failed));
        }
        return true;
    }

    /**
     * Extract extensions from composer.json.
     *
     * Looks for "ext-*" in require and require-dev.
     *
     * @param string $componentPath Path to component
     * @return array<string> Extension names (without ext- prefix)
     */
    private function extractFromComposer(string $componentPath): array
    {
        $composerFile = $componentPath . '/composer.json';
        if (!file_exists($composerFile)) {
            return [];
        }

        $content = file_get_contents($composerFile);
        if ($content === false) {
            return [];
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            return [];
        }

        $extensions = [];

        // Check require
        if (isset($data['require']) && is_array($data['require'])) {
            foreach (array_keys($data['require']) as $package) {
                if (is_string($package) && str_starts_with($package, 'ext-')) {
                    $extensions[] = substr($package, 4); // Remove "ext-" prefix
                }
            }
        }

        // Check require-dev
        if (isset($data['require-dev']) && is_array($data['require-dev'])) {
            foreach (array_keys($data['require-dev']) as $package) {
                if (is_string($package) && str_starts_with($package, 'ext-')) {
                    $extensions[] = substr($package, 4);
                }
            }
        }

        return $extensions;
    }

    /**
     * Install extensions for PHP versions.
     *
     * @param array<string> $extensions Extension names
     * @param array<string> $phpVersions PHP versions to install for
     * @return bool True if successful
     * @throws Exception If installation fails critically
     */
    public function install(array $extensions, array $phpVersions): bool
    {
        if (empty($extensions)) {
            $this->output->info('No extensions to install');
            return true;
        }

        if (empty($phpVersions)) {
            $this->output->warn('No PHP versions specified for extension installation');
            return true;
        }

        $this->output->info('Installing extensions: ' . implode(', ', $extensions));
        $this->output->info('For PHP versions: ' . implode(', ', $phpVersions));

        $failed = [];
        $succeeded = [];

        foreach ($phpVersions as $phpVersion) {
            foreach ($extensions as $extension) {
                $result = $this->installExtension($extension, $phpVersion);

                if ($result === true) {
                    $succeeded[] = "php{$phpVersion}-{$extension}";
                } elseif ($result === false) {
                    $failed[] = "php{$phpVersion}-{$extension}";
                }
                // null means skipped (already installed or not available)
            }
        }

        if (!empty($succeeded)) {
            $this->output->ok('Installed: ' . implode(', ', $succeeded));
        }

        if (!empty($failed)) {
            $this->output->warn('Failed to install: ' . implode(', ', $failed));
            // Don't throw exception - some extensions may not be available for all PHP versions
            // This is expected behavior (e.g., json is built-in in PHP 8.0+)
        }

        return true;
    }

    /**
     * Install single extension for specific PHP version.
     *
     * @param string $extension Extension name
     * @param string $phpVersion PHP version
     * @return bool|null True if installed, false if failed, null if skipped
     */
    private function installExtension(string $extension, string $phpVersion): ?bool
    {
        // Some extensions don't need explicit installation
        $builtIn = ['cli', 'common', 'json']; // json is built-in since PHP 8.0
        if (in_array($extension, $builtIn)) {
            return null; // Skip
        }

        $package = "php{$phpVersion}-{$extension}";

        // Check if already installed
        if ($this->isExtensionInstalled($extension, $phpVersion)) {
            return null; // Skip
        }

        // Try to install using sudo helper
        if (!SudoHelper::installExtension($phpVersion, $extension)) {
            $this->output->warn("Failed to install {$package}");
            return false;
        }

        return true;
    }

    /**
     * Check if extension is installed for PHP version.
     *
     * @param string $extension Extension name
     * @param string $phpVersion PHP version
     * @return bool
     */
    private function isExtensionInstalled(string $extension, string $phpVersion): bool
    {
        $php = "/usr/bin/php{$phpVersion}";

        if (!file_exists($php)) {
            return false;
        }

        // Check if extension is loaded
        $command = escapeshellarg($php) . " -m 2>/dev/null | grep -i " . escapeshellarg("^{$extension}$");
        $result = shell_exec($command);

        return $result !== null && trim($result) !== '';
    }

    /**
     * Get installed extensions for PHP version.
     *
     * @param string $phpVersion PHP version
     * @return array<string> Extension names
     */
    public function getInstalledExtensions(string $phpVersion): array
    {
        $php = "/usr/bin/php{$phpVersion}";

        if (!file_exists($php)) {
            return [];
        }

        $output = shell_exec(escapeshellarg($php) . " -m 2>/dev/null");
        if ($output === null) {
            return [];
        }

        $lines = explode("\n", trim($output));
        $extensions = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '' && $line !== '[PHP Modules]' && $line !== '[Zend Modules]') {
                $extensions[] = strtolower($line);
            }
        }

        sort($extensions);
        return $extensions;
    }
}
