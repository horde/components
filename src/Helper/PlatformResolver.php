<?php

/**
 * Copyright 2026 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

declare(strict_types=1);

namespace Horde\Components\Helper;

use Horde\Components\Component;
use Horde\Components\Output;
use Horde\HordeYmlFile\HordeYmlFile;
use Horde\Version\ConstraintParser;
use Horde\Version\InvalidVersionException;
use Horde\Version\RelaxedSemanticVersion;
use JsonException;

/**
 * Resolve transitive platform requirements (PHP version, extensions,
 * composer meta packages) per supported PHP minor version.
 *
 * The PHP version range is derived from the component's own
 * `dependencies.required.php` constraint in `.horde.yml`. The lower
 * bound comes from the constraint's floor; the upper bound is the
 * constraint's ceiling, further capped at {@see self::CURRENT_MAX_PHP}
 * so the sweep does not generate entries for PHP versions that do not
 * yet exist.
 *
 * For each minor version in the range, this helper spins up a throwaway
 * composer project, pins `platform.php` to that version, requires the
 * component as if it were a downstream consumer, and runs
 * `composer update --ignore-platform-reqs --no-install`. The resulting
 * `composer.lock` is walked: every `require` key matching a *platform
 * package* (php, composer-*, ext-*, lib-*) is collected, deduplicated,
 * and returned. When `composer update` exits non-zero — typically
 * because a transitive dependency's latest release rules out the pinned
 * PHP version — the entry for that version is the literal string
 * `'not resolvable'`.
 *
 * Network access: this helper hits Packagist (and any extra repositories
 * configured in the synthesized project). It is not safe to call from
 * sandboxed contexts.
 */
class PlatformResolver
{
    /**
     * Latest PHP minor version we currently know about. The upper bound
     * of any resolved range is capped at this value so newly bumped
     * `^8.2` style constraints do not generate entries for hypothetical
     * future PHP releases. To support a new PHP minor, add it to
     * {@see self::CANDIDATE_PHP_MINORS}.
     */
    public const string CURRENT_MAX_PHP = '8.6';

    /**
     * Every PHP minor version we know how to resolve against. Used as
     * the candidate set when probing a constraint: each entry is tested
     * with `isSatisfiedBy(new RelaxedSemanticVersion("X.Y.0"))` and the
     * matching minors are returned in order. Bumping this list is the
     * only change required when a new PHP minor ships.
     */
    private const array CANDIDATE_PHP_MINORS = [
        '8.0', '8.1', '8.2', '8.3', '8.4', '8.5', '8.6',
    ];

    /**
     * Sentinel value emitted under a PHP version key in the output of
     * {@see self::resolveRange()} when composer fails to resolve a
     * dependency set for that version.
     */
    public const string NOT_RESOLVABLE = 'not resolvable';

    public function __construct(
        private readonly Shell $shell,
        private readonly ?Output $output = null,
    ) {}

    /**
     * Resolve platform requirements across a component's full
     * supported PHP version range, reading the constraint and the
     * package name from a parsed `.horde.yml`.
     *
     * @return array<string, list<array{string, ?string}>|string>
     *   Keyed by minor version (e.g. "8.2"). Each value is either a
     *   list of [name, constraint] pairs or {@see self::NOT_RESOLVABLE}.
     *   Constraint is null for ext-* and lib-* entries.
     */
    public function resolveRangeFromHordeYml(HordeYmlFile $hordeYml): array
    {
        $phpConstraint = $hordeYml->getRequiredPhp();
        $packageName = $hordeYml->getComposerName();
        $versionConstraint = $this->deriveVersionConstraint($hordeYml->getReleaseVersion());
        $stability = $hordeYml->getReleaseState() ?: 'stable';

        return $this->resolveRangeFor(
            $phpConstraint,
            $packageName,
            $versionConstraint,
            $stability,
        );
    }

    /**
     * Resolve platform requirements across a component's full
     * supported PHP version range, reading data from the legacy
     * Component interface. Prefer
     * {@see self::resolveRangeFromHordeYml()} when a parsed
     * .horde.yml is available; this path is for callers that only
     * hold the Component abstraction.
     *
     * @return array<string, list<array{string, ?string}>|string>
     */
    public function resolveRange(Component $component): array
    {
        $phpConstraint = $this->getComponentPhpConstraint($component);
        $packageName = $this->getComponentPackageName($component);
        $versionConstraint = $this->deriveVersionConstraint($component->getVersion());
        $stability = $component->getState('release') ?: 'stable';

        return $this->resolveRangeFor(
            $phpConstraint,
            $packageName,
            $versionConstraint,
            $stability,
        );
    }

    /**
     * @return array<string, list<array{string, ?string}>|string>
     */
    private function resolveRangeFor(
        string $phpConstraint,
        string $packageName,
        string $versionConstraint,
        string $stability,
    ): array {
        $range = $this->phpVersionRange($phpConstraint);

        $out = [];
        foreach ($range as $minor) {
            $this->output?->info(sprintf('Resolving platform deps for PHP %s', $minor));
            $out[$minor] = $this->resolveSingleFor(
                $packageName,
                $versionConstraint,
                $stability,
                $minor,
            );
        }

        return $out;
    }

    /**
     * Resolve platform requirements for a single pinned PHP minor
     * version.
     *
     * @return list<array{string, ?string}>|string Either the parsed
     *         platform list or {@see self::NOT_RESOLVABLE}.
     */
    public function resolveSingle(Component $component, string $minorVersion): array|string
    {
        return $this->resolveSingleFor(
            $this->getComponentPackageName($component),
            $this->deriveVersionConstraint($component->getVersion()),
            $component->getState('release') ?: 'stable',
            $minorVersion,
        );
    }

    /**
     * @return list<array{string, ?string}>|string
     */
    private function resolveSingleFor(
        string $packageName,
        string $versionConstraint,
        string $stability,
        string $minorVersion,
    ): array|string {
        // $stability comes from the component's own release state but
        // is intentionally not used as the composer minimum-stability:
        // the transitive dep graph routinely contains alpha and dev
        // packages from sibling horde/* libraries while the component
        // being resolved is RC or stable. We always set
        // minimum-stability=dev + prefer-stable=true so composer picks
        // stable when available and falls through to dev when nothing
        // else exists. Without this, the require step fails on any
        // ActiveSync-shaped tree where a transitive sibling has not
        // released a stable yet.
        unset($stability);

        $tmpDir = $this->makeTempDir();
        try {
            // 1. composer init -n
            $r = $this->shell->exec(
                sprintf('composer init -n --name=%s --no-interaction 2>&1', escapeshellarg('horde-tmp/platform-resolver')),
                $tmpDir,
            );
            if ($r->getReturnValue() !== 0) {
                return self::NOT_RESOLVABLE;
            }

            // 2. composer config platform.php X.Y.0
            $r = $this->shell->exec(
                sprintf('composer config platform.php %s 2>&1', escapeshellarg($minorVersion . '.0')),
                $tmpDir,
            );
            if ($r->getReturnValue() !== 0) {
                return self::NOT_RESOLVABLE;
            }

            // 3. Stability: always dev + prefer-stable. See note above.
            $this->shell->exec('composer config minimum-stability dev 2>&1', $tmpDir);
            $this->shell->exec('composer config prefer-stable true 2>&1', $tmpDir);

            // 4. composer require <package>:<constraint> --no-install
            $requireSpec = $packageName . ($versionConstraint !== '' ? ':' . $versionConstraint : '');
            $r = $this->shell->exec(
                sprintf('composer require %s --no-install --no-interaction 2>&1', escapeshellarg($requireSpec)),
                $tmpDir,
            );
            if ($r->getReturnValue() !== 0) {
                return self::NOT_RESOLVABLE;
            }

            // 5. composer update --ignore-platform-reqs --no-install
            //    The require step in step 4 already writes a composer.lock,
            //    so this step is mostly redundant. We still run it so the
            //    sequence matches the documented manual recipe and so any
            //    plugin-level resolution post-processing happens before we
            //    read the lock.
            $r = $this->shell->exec(
                'composer update --ignore-platform-reqs --no-install --no-interaction 2>&1',
                $tmpDir,
            );
            if ($r->getReturnValue() !== 0) {
                return self::NOT_RESOLVABLE;
            }

            // 6. Parse composer.lock
            $lockPath = $tmpDir . '/composer.lock';
            if (!is_file($lockPath)) {
                return self::NOT_RESOLVABLE;
            }

            $extracted = $this->extractFromLock(file_get_contents($lockPath) ?: '');
            if ($extracted === []) {
                // A successful resolve produces at least the platform
                // requirements of the requested package itself. An empty
                // list almost always means the require step rolled itself
                // back without surfacing an error code we caught. Treat
                // as not resolvable rather than emitting a misleading
                // empty list.
                return self::NOT_RESOLVABLE;
            }

            return $extracted;
        } finally {
            $this->rmrf($tmpDir);
        }
    }

    /**
     * Pull the [name, constraint] pairs for every platform requirement
     * found across every package in a composer.lock document.
     *
     * The list is deduplicated by name (first occurrence wins for
     * constraint) and sorted with `php` first, then the composer-*
     * meta packages, then ext-* and lib-* alphabetically. This is a
     * deterministic order so the resulting .horde.yml diff is stable.
     *
     * @return list<array{string, ?string}>
     */
    public function extractFromLock(string $lockJson): array
    {
        try {
            $lock = json_decode($lockJson, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        if (!is_array($lock)) {
            return [];
        }

        /** @var array<string, ?string> $found */
        $found = [];

        $packages = array_merge(
            is_array($lock['packages'] ?? null) ? $lock['packages'] : [],
            // packages-dev exists in synthesized projects but we never
            // require any dev deps from the throwaway root, so this
            // section is normally empty. Walked defensively in case a
            // transitive plugin pulls dev things in.
            is_array($lock['packages-dev'] ?? null) ? $lock['packages-dev'] : [],
        );

        foreach ($packages as $package) {
            if (!is_array($package)) {
                continue;
            }
            $require = $package['require'] ?? null;
            if (!is_array($require)) {
                continue;
            }
            foreach ($require as $name => $constraint) {
                if (!is_string($name)) {
                    continue;
                }
                if (!self::isPlatformKey($name)) {
                    continue;
                }
                if (!array_key_exists($name, $found)) {
                    $found[$name] = is_string($constraint) ? $constraint : null;
                }
            }
        }

        // Stable, opinionated ordering.
        return self::sortPlatformEntries($found);
    }

    /**
     * Decide whether a composer require key is a platform package as
     * defined by composer itself: php, composer meta, ext-*, lib-*.
     */
    public static function isPlatformKey(string $name): bool
    {
        if ($name === 'php' || $name === 'php-64bit' || $name === 'php-ipv6' || $name === 'php-zts' || $name === 'php-debug') {
            return true;
        }
        if ($name === 'composer' || $name === 'composer-plugin-api' || $name === 'composer-runtime-api') {
            return true;
        }
        return str_starts_with($name, 'ext-') || str_starts_with($name, 'lib-');
    }

    /**
     * Convert the {name => constraint} map into the public list shape
     * with a stable order.
     *
     * Order: php-family first, then composer-* meta, then ext-* /
     * lib-* alphabetically. Within php-family we sort the variants
     * (php, php-64bit, ...) alphabetically too; in practice only
     * `php` shows up.
     *
     * @param array<string, ?string> $found
     * @return list<array{string, ?string}>
     */
    private static function sortPlatformEntries(array $found): array
    {
        $phpFamily = [];
        $composerFamily = [];
        $rest = [];
        foreach ($found as $name => $constraint) {
            if ($name === 'php' || str_starts_with($name, 'php-')) {
                $phpFamily[$name] = $constraint;
            } elseif ($name === 'composer' || str_starts_with($name, 'composer-')) {
                $composerFamily[$name] = $constraint;
            } else {
                $rest[$name] = $constraint;
            }
        }
        ksort($phpFamily);
        ksort($composerFamily);
        ksort($rest);

        $out = [];
        foreach ([$phpFamily, $composerFamily, $rest] as $group) {
            foreach ($group as $name => $constraint) {
                $out[] = [$name, $constraint];
            }
        }
        return $out;
    }

    /**
     * Compute the list of PHP minor versions to resolve for, given a
     * composer-style PHP version constraint.
     *
     * The constraint is parsed by {@see ConstraintParser}, which already
     * handles every shape composer accepts (caret, tilde, ranges, OR,
     * AND, wildcards, comparisons). We then probe each entry in
     * {@see self::CANDIDATE_PHP_MINORS} as the major.minor.0
     * representative and keep the matches in order.
     *
     * The candidate list doubles as the upper-bound cap: a constraint
     * like `^8.5` that allows up to PHP 9 will only return the candidate
     * minors we actually know about, capped at
     * {@see self::CURRENT_MAX_PHP}.
     *
     * Unparseable constraints fall back to a single-entry list at
     * CURRENT_MAX_PHP so the caller has at least one PHP version to
     * try; better than failing the whole command on an unfamiliar
     * constraint string.
     *
     * @return list<string> e.g. ['8.2', '8.3', '8.4']
     */
    public function phpVersionRange(string $constraint): array
    {
        $constraint = trim($constraint);
        if ($constraint === '') {
            return [self::CURRENT_MAX_PHP];
        }

        // Composer accepts both `,` and ` ` as AND separators (per the
        // package versions documentation); Horde\Version\ConstraintParser
        // only recognises space-separated AND, so normalise commas to
        // spaces before parsing. OR (`||`) stays untouched.
        $normalised = preg_replace('/\s*,\s*/', ' ', $constraint) ?? $constraint;

        try {
            $parsed = (new ConstraintParser())->parse($normalised);
        } catch (InvalidVersionException) {
            return [self::CURRENT_MAX_PHP];
        }

        $out = [];
        foreach (self::CANDIDATE_PHP_MINORS as $minor) {
            $probe = new RelaxedSemanticVersion($minor . '.0');
            if ($parsed->isSatisfiedBy($probe)) {
                $out[] = $minor;
            }
        }

        // Defensive fallback: if no candidate satisfies the constraint
        // (e.g. constraint is for an unsupported PHP major), return
        // CURRENT_MAX_PHP rather than an empty range. Callers iterate
        // over the result and an empty list would skip the component
        // entirely.
        if ($out === []) {
            return [self::CURRENT_MAX_PHP];
        }

        return $out;
    }

    private function getComponentPhpConstraint(Component $component): string
    {
        $deps = $component->getDependencies();
        if (!is_array($deps)) {
            return '';
        }
        $required = $deps['required'] ?? null;
        if (!is_array($required)) {
            return '';
        }
        $php = $required['php'] ?? '';
        return is_string($php) ? $php : '';
    }

    private function getComponentPackageName(Component $component): string
    {
        // Fall back to "horde/<name>" if the component doesn't expose
        // a richer composer name. Every modern Horde component uses
        // the horde/<id> shape on Packagist.
        $name = $component->getName();
        if ($name === '') {
            return 'horde/unknown';
        }
        if (str_contains($name, '/')) {
            return $name;
        }
        return 'horde/' . $name;
    }

    private function getComponentVersionConstraint(Component $component): string
    {
        return $this->deriveVersionConstraint($component->getVersion());
    }

    /**
     * Build a caret-range constraint usable in `composer require` from
     * a raw release version. `3.1.4-RC1` → `^3.1`, `3.0` → `^3.0`,
     * empty → `*` (latest).
     */
    private function deriveVersionConstraint(string $version): string
    {
        if ($version === '') {
            return '*';
        }
        $normalized = preg_replace('/[-+].*/', '', $version) ?? $version;
        $parts = explode('.', $normalized);
        if (count($parts) < 2) {
            return '^' . $normalized;
        }
        return '^' . $parts[0] . '.' . $parts[1];
    }

    private function makeTempDir(): string
    {
        $base = sys_get_temp_dir() . '/horde-platform-resolver-' . uniqid('', true);
        if (!mkdir($base, 0o755, true) && !is_dir($base)) {
            throw new \RuntimeException('Failed to create temp dir: ' . $base);
        }
        return $base;
    }

    private function rmrf(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $child) {
            if ($child === '.' || $child === '..') {
                continue;
            }
            $this->rmrf($path . '/' . $child);
        }
        @rmdir($path);
    }
}
