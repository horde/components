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

/**
 * Installs multiple PHP versions via ondrej PPA.
 *
 * Uses apt-get to install PHP versions 8.2, 8.3, 8.4, 8.5 from
 * the ondrej/php PPA on Ubuntu systems.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class PhpInstaller
{
    /**
     * ondrej PPA URL.
     */
    private const ONDREJ_PPA = 'ppa:ondrej/php';

    /**
     * Constructor.
     *
     * @param Output $output Output handler
     */
    public function __construct(
        private readonly Output $output
    ) {}

    /**
     * Install multiple PHP versions.
     *
     * @param array<string> $versions PHP versions to install (e.g., ['8.2', '8.3'])
     * @return bool True if successful
     * @throws Exception If installation fails
     */
    public function install(array $versions): bool
    {
        if (empty($versions)) {
            $this->output->warn('No PHP versions specified for installation');
            return true;
        }

        $this->output->info('Installing PHP versions: ' . implode(', ', $versions));

        // Check if we're on a Debian/Ubuntu system
        if (!$this->isDebianBased()) {
            throw new Exception('PHP installation via ondrej PPA only works on Debian/Ubuntu systems');
        }

        // Check if we have root access or sudo
        if (!SudoHelper::isRoot() && !$this->hasSudo()) {
            throw new Exception('root or sudo access required for PHP installation');
        }

        // Add ondrej PPA if not already added
        if (!$this->isPpaAdded()) {
            $this->output->info('Adding ondrej/php PPA...');
            if (!$this->addPpa()) {
                throw new Exception('Failed to add ondrej/php PPA');
            }
        }

        // Update package list
        $this->output->info('Updating package list...');
        if (!$this->updatePackageList()) {
            throw new Exception('Failed to update package list');
        }

        // Install each PHP version
        foreach ($versions as $version) {
            if ($this->isPhpVersionInstalled($version)) {
                $this->output->info("PHP {$version} already installed");
                continue;
            }

            $this->output->info("Installing PHP {$version}...");
            if (!$this->installPhpVersion($version)) {
                throw new Exception("Failed to install PHP {$version}");
            }
        }

        $this->output->ok('All PHP versions installed successfully');
        return true;
    }

    /**
     * Check if system is Debian-based.
     *
     * @return bool
     */
    private function isDebianBased(): bool
    {
        return file_exists('/etc/debian_version')
               || file_exists('/etc/lsb-release')
               || is_executable('/usr/bin/apt-get');
    }

    /**
     * Check if we have sudo access.
     *
     * @return bool
     */
    private function hasSudo(): bool
    {
        // Check if running as root
        if (posix_geteuid() === 0) {
            return true;
        }

        // Check if sudo helper is available and can run passwordless
        return SudoHelper::isAvailable() && SudoHelper::canRunPasswordless();
    }

    /**
     * Check if ondrej PPA is already added.
     *
     * @return bool
     */
    private function isPpaAdded(): bool
    {
        $sources = '/etc/apt/sources.list.d/';
        if (!is_dir($sources)) {
            return false;
        }

        $files = glob($sources . '*ondrej*');
        return $files !== false && count($files) > 0;
    }

    /**
     * Add ondrej PPA.
     *
     * On failure, prints whatever the sudo helper (and any apt commands
     * it invoked) wrote to stdout/stderr before returning false, so
     * callers don't have to guess whether add-apt-repository, apt-get
     * update, or PPA GPG import was the thing that broke.
     *
     * @return bool
     */
    private function addPpa(): bool
    {
        $result = SudoHelper::addPpa();
        if (!$result['success']) {
            if ($result['output'] !== '') {
                $this->output->plain($result['output']);
            }
            $this->output->error(sprintf(
                'sudo helper exited %d while adding ondrej/php PPA',
                $result['exitCode']
            ));
        }
        return $result['success'];
    }

    /**
     * Update package list.
     *
     * @return bool
     */
    private function updatePackageList(): bool
    {
        // The sudo helper's add-ppa command already runs apt-get update
        // So this method is no longer needed when using SudoHelper
        return true;
    }

    /**
     * Check if PHP version is already installed.
     *
     * @param string $version PHP version (e.g., '8.4')
     * @return bool
     */
    private function isPhpVersionInstalled(string $version): bool
    {
        return SudoHelper::isPhpInstalled($version);
    }

    /**
     * Filter a list of PHP minors down to those that have a `phpX.Y`
     * package in apt's current view of the world.
     *
     * The matrix builder ({@see PlatformResolver::phpVersionLaneSet})
     * intersects the component's PHP constraint against every minor
     * we know about, but the ondrej/php PPA does not always carry the
     * very latest minor on day-zero of its release. A component that
     * legitimately allows PHP 8.6 must not cause a fatal CI abort just
     * because apt doesn't have 8.6 yet; the lane is silently dropped
     * from the matrix instead.
     *
     * Idempotent: calling this when the PPA is already added and
     * `apt-get update` already ran is a fast no-op that just runs
     * `apt-cache show` per version.
     *
     * Already-installed versions are always reported as available; the
     * apt-cache check is skipped for them so a fresh-from-cache build
     * doesn't need network access to confirm what's on disk.
     *
     * @param array<string> $versions PHP minors to probe, e.g.
     *                                ['8.2', '8.3', '8.4', '8.5', '8.6'].
     * @return list<string> The subset whose `phpX.Y` package exists.
     *                      Order matches the input order.
     */
    public function filterAvailable(array $versions): array
    {
        if (empty($versions)) {
            return [];
        }

        if (!$this->isDebianBased()) {
            // Non-Debian systems can't be probed via apt; trust the
            // caller's list and let install() fail later with a clearer
            // diagnostic if needed.
            return array_values($versions);
        }

        // The ondrej PPA must be visible to apt before `apt-cache show`
        // can find phpX.Y packages from it. Skip the add when sources
        // already mention ondrej; this keeps repeated CI calls cheap.
        if (!$this->isPpaAdded()) {
            $this->output->info('Adding ondrej/php PPA (probe phase)...');
            if (!$this->addPpa()) {
                // PPA add failed; don't drop everything. Fall back to
                // letting install() try and produce its own error.
                return array_values($versions);
            }
        }

        $available = [];
        foreach ($versions as $version) {
            if ($this->isPhpVersionInstalled($version)) {
                $available[] = $version;
                continue;
            }
            if ($this->aptHasPackage("php{$version}")) {
                $available[] = $version;
                continue;
            }
            $this->output->info(sprintf(
                'PHP %s not available in apt (ondrej/php has not shipped it yet); lane dropped',
                $version
            ));
        }

        return $available;
    }

    /**
     * Probe `apt-cache show <package>` and return true when the
     * package is known to apt. Used by {@see self::filterAvailable()}.
     */
    private function aptHasPackage(string $package): bool
    {
        // `apt-cache show` exits 100 when the package is unknown,
        // 0 when at least one version exists. We don't need the
        // payload; just the exit code. Stderr is muted because
        // unknown packages print "N: Unable to locate package …"
        // which is noise in the CI log.
        $escaped = escapeshellarg($package);
        exec("apt-cache show {$escaped} > /dev/null 2>&1", $_unused, $exitCode);
        return $exitCode === 0;
    }

    /**
     * Install a specific PHP version.
     *
     * @param string $version PHP version (e.g., '8.4')
     * @return bool
     */
    private function installPhpVersion(string $version): bool
    {
        // Use sudo helper to install PHP.
        //
        // On failure, print the helper's captured output (which is
        // apt-get's own message plus, if the version was rejected by
        // the shape guard in sudo-helper.sh, that rejection line) so
        // the CI log shows the real reason — e.g. "E: Unable to locate
        // package php9.0-cli" — instead of a bare "Failed to install
        // PHP 9.0".
        $result = SudoHelper::installPhp($version);
        if (!$result['success']) {
            if ($result['output'] !== '') {
                $this->output->plain($result['output']);
            }
            $this->output->error(sprintf(
                "Failed to install PHP {$version} (sudo helper exited %d)",
                $result['exitCode']
            ));
            return false;
        }

        // Verify installation
        if (!$this->isPhpVersionInstalled($version)) {
            $this->output->error("PHP {$version} installation succeeded but binary not found");
            return false;
        }

        // Show installed version
        $versionOutput = shell_exec("/usr/bin/php{$version} -v 2>&1");
        if ($versionOutput !== null) {
            $this->output->plain($versionOutput);
        }

        return true;
    }

    /**
     * Get installed PHP versions.
     *
     * @return array<string> Installed PHP versions
     */
    public function getInstalledVersions(): array
    {
        $versions = [];
        $binaries = glob('/usr/bin/php[0-9].[0-9]');

        if ($binaries === false) {
            return [];
        }

        foreach ($binaries as $binary) {
            if (preg_match('/php(\d+\.\d+)$/', $binary, $matches)) {
                $versions[] = $matches[1];
            }
        }

        sort($versions);
        return $versions;
    }

    /**
     * Get path to PHP binary for version.
     *
     * @param string $version PHP version (e.g., '8.4')
     * @return string Path to binary
     * @throws Exception If version not installed
     */
    public function getPhpBinary(string $version): string
    {
        $binary = "/usr/bin/php{$version}";

        if (!file_exists($binary) || !is_executable($binary)) {
            throw new Exception("PHP {$version} is not installed");
        }

        return $binary;
    }

    /**
     * Pick a PHP binary suitable for running horde-components' own tool
     * phars (PHPUnit, PHPStan, PHP-CS-Fixer). Prefers the runner's
     * default `php` when it is at or above $minVersion; otherwise scans
     * installed phpX.Y binaries and returns the lowest matching one.
     *
     * Lowest-matching is deliberate: horde-components' own tool phars
     * only need $minVersion. Preferring the lowest keeps subprocesses
     * from picking up newer language features than the tools themselves
     * require, and reduces surprises when the runner ships a very new
     * PHP alongside older ones.
     *
     * @param string $minVersion Minimum acceptable PHP version, e.g. '8.2'.
     *
     * @return string Absolute path to a suitable PHP binary.
     * @throws Exception When no PHP >= $minVersion is available; lanes
     *                   cannot run without one.
     */
    public function findToolPhpBinary(string $minVersion): string
    {
        // 1. Runner's default `php` if it is >= $minVersion.
        $defaultPhp = $this->getDefaultPhpPath();
        if ($defaultPhp !== '' && is_executable($defaultPhp)) {
            $version = $this->probeVersion($defaultPhp);
            if ($version !== null && version_compare($version, $minVersion, '>=')) {
                return $defaultPhp;
            }
        }

        // 2. Lowest installed phpX.Y >= $minVersion.
        foreach ($this->getInstalledVersions() as $version) {
            if (version_compare($version, $minVersion, '>=')) {
                return $this->getPhpBinary($version);
            }
        }

        // 3. Fatal - lanes cannot invoke horde-components without a
        //    compatible PHP.
        throw new Exception(
            "No PHP >= {$minVersion} installed. horde-components' tool "
            . 'phars require PHP ' . $minVersion . '+. Install one of the '
            . 'ondrej/php packages, e.g. `sudo apt install php8.2-cli`.'
        );
    }

    /**
     * Return the absolute path to the runner's default `php` on
     * PATH, or an empty string when none is found. Split out as a
     * protected method so tests can inject a canned value without
     * relying on the host environment.
     *
     * @return string Absolute path to `php`, or '' when not on PATH.
     */
    protected function getDefaultPhpPath(): string
    {
        return trim((string) shell_exec('command -v php 2>/dev/null'));
    }

    /**
     * Probe a PHP binary for its major.minor version by parsing the
     * first line of `php -v`. Returns null when the probe fails, so
     * findToolPhpBinary() treats the binary as unusable and falls
     * through to the next resolution step.
     *
     * @param string $php Absolute path to a PHP binary.
     * @return string|null "<major>.<minor>" (e.g. "8.3"), or null.
     */
    protected function probeVersion(string $php): ?string
    {
        $out = @shell_exec(escapeshellarg($php) . ' -v 2>/dev/null');
        if (!is_string($out) || !preg_match('/^PHP\s+(\d+\.\d+)/', $out, $m)) {
            return null;
        }
        return $m[1];
    }
}
