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
use Horde\Composer\ComposerJsonFile;

/**
 * Runs composer install for test lanes with stability control.
 *
 * Modifies composer.json minimum-stability per lane and runs composer install.
 * Implements retry logic for network failures.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class ComposerInstaller
{
    /**
     * Maximum retry attempts for composer install.
     */
    private const MAX_RETRIES = 3;

    /**
     * Composer timeout in seconds.
     */
    private const TIMEOUT = 600; // 10 minutes

    /**
     * Constructor.
     *
     * @param Output $output Output handler
     */
    public function __construct(
        private readonly Output $output
    ) {}

    /**
     * Install composer dependencies for a lane.
     *
     * @param string $laneDir Lane directory
     * @param string $phpBinary Path to PHP binary for this lane
     * @param string $stability Minimum stability (dev, alpha, beta, RC, stable)
     * @return bool True if successful
     * @throws Exception If installation fails after retries
     */
    public function install(string $laneDir, string $phpBinary, string $stability): bool
    {
        if (!is_dir($laneDir)) {
            throw new Exception("Lane directory does not exist: {$laneDir}");
        }

        $composerFile = $laneDir . '/composer.json';
        if (!file_exists($composerFile)) {
            throw new Exception("composer.json not found in: {$laneDir}");
        }

        // Set minimum-stability
        $this->setMinimumStability($composerFile, $stability);

        // Run composer install with retries
        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            if ($attempt > 1) {
                $this->output->warn("Retry attempt {$attempt}/" . self::MAX_RETRIES);
                sleep(2 ** $attempt); // Exponential backoff: 2s, 4s, 8s
            }

            $result = $this->runComposerInstall($laneDir, $phpBinary);

            if ($result['success']) {
                return true;
            }

            // Check if it's a recoverable error
            if (!$this->isRecoverableError($result['output'])) {
                throw new Exception(
                    "Composer install failed in {$laneDir}: " . $result['error']
                );
            }
        }

        throw new Exception("Composer install failed after " . self::MAX_RETRIES . " attempts");
    }

    /**
     * Set minimum-stability in composer.json.
     *
     * Uses Horde\Composer\ComposerJsonFile for proper JSON manipulation.
     *
     * @param string $composerFile Path to composer.json
     * @param string $stability Minimum stability
     * @return bool True if successful
     * @throws Exception If file manipulation fails
     */
    private function setMinimumStability(string $composerFile, string $stability): bool
    {
        try {
            $composer = new ComposerJsonFile($composerFile);
            $composer->setMinimumStability($stability);
            $composer->save();
            return true;
        } catch (\Exception $e) {
            throw new Exception("Failed to set minimum-stability in {$composerFile}: " . $e->getMessage());
        }
    }

    /**
     * Run composer install.
     *
     * @param string $laneDir Lane directory
     * @param string $phpBinary Path to PHP binary
     * @return array{success: bool, output: string, error: string}
     */
    private function runComposerInstall(string $laneDir, string $phpBinary): array
    {
        // Find composer binary
        $composer = $this->findComposer();

        // Build command - wrap in bash -c for timeout to work with cd &&
        // Note: Composer 2.x installs dev dependencies by default (no --dev flag needed)
        $innerCommand = sprintf(
            'cd %s && %s %s install --no-interaction --no-progress --prefer-dist 2>&1',
            escapeshellarg($laneDir),
            escapeshellarg($phpBinary),
            escapeshellarg($composer)
        );

        // Wrap with timeout and bash
        $command = sprintf(
            'timeout %d bash -c %s',
            self::TIMEOUT,
            escapeshellarg($innerCommand)
        );

        // Execute
        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);

        $outputStr = implode("\n", $output);

        return [
            'success' => $exitCode === 0,
            'output' => $outputStr,
            'error' => $exitCode !== 0 ? $this->extractError($outputStr) : '',
        ];
    }

    /**
     * Find composer binary.
     *
     * @return string Path to composer
     * @throws Exception If composer not found
     */
    private function findComposer(): string
    {
        // Try composer command
        $which = shell_exec('which composer 2>/dev/null');
        if ($which !== null && trim($which) !== '') {
            return trim($which);
        }

        // Try composer.phar in common locations
        $locations = [
            '/usr/local/bin/composer.phar',
            '/usr/bin/composer.phar',
            getcwd() . '/composer.phar',
        ];

        foreach ($locations as $location) {
            if (file_exists($location) && is_executable($location)) {
                return $location;
            }
        }

        throw new Exception('composer not found. Please install composer.');
    }

    /**
     * Check if error is recoverable (network issues, etc.).
     *
     * @param string $output Command output
     * @return bool True if error might be temporary
     */
    private function isRecoverableError(string $output): bool
    {
        $recoverablePatterns = [
            '/connection.*timed out/i',
            '/failed to connect/i',
            '/could not fetch/i',
            '/temporary failure/i',
            '/network.*unreachable/i',
            '/curl error/i',
        ];

        foreach ($recoverablePatterns as $pattern) {
            if (preg_match($pattern, $output)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract a short, signal-bearing error line from composer output.
     *
     * Composer running under GitHub Actions emits its multi-line failure
     * paragraph as a single workflow-command annotation
     * (`::error ::Your requirements could not be resolved...%0A%0A  Problem 1...`)
     * where literal newlines are encoded as `%0A`. To `explode("\n", ...)`
     * this still looks like *one* line, so the caller would end up with
     * the entire 2 KB wall of text. Normalise `%0A` to real
     * newlines first, drop the `::error ::` workflow-command prefix,
     * then return the first informative line.
     *
     * @param string $output Command output
     * @return string Error message (one line, trimmed)
     */
    private function extractError(string $output): string
    {
        // Decode GitHub Actions workflow-command escaped newlines so the
        // per-line scan below can actually see the structure of composer's
        // resolver paragraph.
        $normalised = str_replace(['%0A', '%0D'], "\n", $output);

        $lines = explode("\n", $normalised);

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }
            // Strip the `::error ::`/`::error::`/`::error file=...::` prefix
            // GitHub Actions uses; the human-meaningful part is whatever
            // follows the second `::`.
            if (str_starts_with($trimmed, '::error')) {
                $sepPos = strpos($trimmed, '::', 7);
                if ($sepPos !== false) {
                    $trimmed = ltrim(substr($trimmed, $sepPos + 2));
                }
            }
            if ($trimmed === '') {
                continue;
            }
            if (stripos($trimmed, 'error') !== false
                || stripos($trimmed, 'failed') !== false
                || stripos($trimmed, 'could not be resolved') !== false
            ) {
                return $trimmed;
            }
        }

        // Return last non-empty line as fallback. `end()` returns false
        // on empty arrays; we guard above, but cast to string anyway so
        // PHPStan doesn't have to chase the false-or-string union.
        $nonEmpty = array_filter($lines, fn($l) => trim($l) !== '');
        if (empty($nonEmpty)) {
            return 'Unknown error';
        }

        return trim((string) end($nonEmpty));
    }

    /**
     * Classify a composer install failure message into a coarse category
     * so downstream reporting can present "stability-gated" failures
     * differently from "ext-* missing" and from "no idea".
     *
     * The classification is best-effort: composer's text is a moving
     * target. Categories returned:
     *
     * - `stability_gate`: a transitive dependency is only available at a
     *   stability lower than the lane's `minimum-stability`. Composer
     *   prints `does not match your minimum-stability`. Working as
     *   designed - the ecosystem is not yet ready for that lane's
     *   stability level. Maintainer action: wait for the upstream package
     *   to release at the required stability or accept the failure.
     *
     * - `platform_missing`: a `ext-*` (or `lib-*`) requirement is not
     *   installed on the runner. Composer prints `is missing from your
     *   system. Install or enable PHP's <ext> extension`. The fix lives
     *   in `.horde.yml`'s `ci-platform` (rerun
     *   `horde-components dependencies --platform`) or in the bootstrap
     *   if the resolver caught it but the apt-get install path missed
     *   the package.
     *
     * - `php_version`: the resolver couldn't satisfy a PHP version
     *   constraint. Composer prints `your php version (X.Y.Z) does not
     *   satisfy that requirement`. Usually a stale `^7` constraint
     *   surviving on a transitive horde/* package.
     *
     * - `unknown`: anything else. UI falls back to the generic
     *   "Setup failed" rendering with the raw message.
     */
    public static function classifyError(string $output): string
    {
        // The composer error messages contain literal newlines and the
        // workflow-command-escaped %0A variant; normalise both before
        // pattern matching so the classifier works whether the caller
        // hands us live output or the lane-prefixed copy from the
        // CI log.
        $normalised = str_replace(['%0A', '\\n'], "\n", $output);

        if (stripos($normalised, 'does not match your minimum-stability') !== false) {
            return 'stability_gate';
        }
        if (preg_match('/is missing from your system\\. Install or enable PHP/i', $normalised) === 1) {
            return 'platform_missing';
        }
        // Composer's pre-resolution platform-requirement rejection.
        if (preg_match('/require ext-\\S+ \\* -> it is missing from your system/i', $normalised) === 1) {
            return 'platform_missing';
        }
        if (preg_match('/your php version \\(\\S+\\) does not satisfy/i', $normalised) === 1) {
            return 'php_version';
        }
        return 'unknown';
    }

    /**
     * Verify composer installation succeeded.
     *
     * @param string $laneDir Lane directory
     * @return bool True if vendor directory exists
     */
    public function verifyInstallation(string $laneDir): bool
    {
        $vendorDir = $laneDir . '/vendor';
        $autoloadFile = $vendorDir . '/autoload.php';

        return is_dir($vendorDir) && file_exists($autoloadFile);
    }

    /**
     * Get installed package count.
     *
     * @param string $laneDir Lane directory
     * @return int Number of installed packages
     */
    public function getInstalledPackageCount(string $laneDir): int
    {
        $vendorDir = $laneDir . '/vendor';
        if (!is_dir($vendorDir)) {
            return 0;
        }

        $composerDir = $vendorDir . '/composer';
        $installedFile = $composerDir . '/installed.json';

        if (!file_exists($installedFile)) {
            return 0;
        }

        $content = file_get_contents($installedFile);
        if ($content === false) {
            return 0;
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            return 0;
        }

        // Format can be either {packages: [...]} or [...]
        if (isset($data['packages']) && is_array($data['packages'])) {
            return count($data['packages']);
        }

        if (isset($data[0])) {
            return count($data);
        }

        return 0;
    }
}
