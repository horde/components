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
 * and returned. When `composer update` exits non-zero - typically
 * because a transitive dependency's latest release rules out the pinned
 * PHP version - the entry for that version is the literal string
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
     *
     * Public so the CI matrix builder ({@see self::phpVersionLaneSet()}
     * and its callers) can share one source of truth for "what PHP
     * minors does CI know how to install."
     */
    public const array CANDIDATE_PHP_MINORS = [
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
     * Resolve platform requirements for the UUT across its supported PHP
     * version range.
     *
     * The UUT is the *root* of the synthesized composer project. We copy
     * its composer.json to a throwaway directory and let composer's
     * resolver compute the full dependency closure for each pinned PHP
     * minor. The resulting `composer.lock` is walked for platform keys
     * (php, php-*, composer-*, ext-*, lib-*); they get returned per minor.
     *
     * Why copy instead of `composer require`: the UUT is typically an
     * in-development component (Victim, ActiveSync from a feature branch,
     * etc.) and may not be published to Packagist. The previous recipe
     * `composer require horde/<uut>:^X.Y` in a synthesized project relied
     * on Packagist visibility and failed for unpublished components. The
     * UUT-as-root recipe sidesteps that entirely: composer treats the
     * copied composer.json as the developer's own local project.
     *
     * The PHP minor range is derived from the UUT's
     * `dependencies.required.php` constraint, capped at
     * {@see self::CURRENT_MAX_PHP}.
     *
     * @param HordeYmlFile $hordeYml Parsed .horde.yml of the UUT.
     * @param string $componentDir Directory containing the UUT's
     *                              composer.json (sibling of .horde.yml).
     * @return array<string, array{platform: list<array{string, ?string}>, lock: string}|string>
     *   Keyed by minor version. Value is either a record carrying the
     *   parsed platform list and the raw lock JSON (success), or
     *   {@see self::NOT_RESOLVABLE} when composer could not produce a
     *   lock for that minor. The lock JSON is preserved so callers
     *   downstream of {@see self::resolveAndShapeForCiPlatform()} can
     *   run additional extractors against the same resolution without
     *   a second composer round-trip.
     */
    public function resolveRangeFromHordeYml(
        HordeYmlFile $hordeYml,
        string $componentDir,
    ): array {
        $phpConstraint = $hordeYml->getRequiredPhp();
        $composerJsonPath = $componentDir . '/composer.json';
        // The UUT's declared release version. Composer otherwise
        // defaults the unresolvable-root version to 1.0.0 and any
        // require-dev that ships a circular self-reference (e.g.
        // horde/icalendar require horde/date ^3) fails to match the
        // synthesized root. Passing COMPOSER_ROOT_VERSION makes the
        // root advertise its real version so transitive constraints
        // line up.
        $rootVersion = $hordeYml->getReleaseVersion();

        $range = $this->phpVersionRange($phpConstraint);

        $out = [];
        foreach ($range as $minor) {
            $this->output?->info(sprintf('Resolving platform deps for PHP %s', $minor));
            $out[$minor] = $this->resolveSingleForRoot($composerJsonPath, $minor, $rootVersion);
        }

        return $out;
    }

    /**
     * Resolve platform requirements for the UUT and shape the result
     * into the structure stored under the `ci-platform:` key in
     * `.horde.yml`. The shape mirrors what `Runner/Dependencies::runPlatformResolve()`
     * persists when the maintainer runs `horde-components deps --platform`,
     * so the release pipeline can refresh the same block by calling this
     * helper and the `deps --platform` runner can stay as a thin entry
     * point.
     *
     * Shape per minor:
     * - ext-* / lib-* render as bare list items (string scalars).
     * - php / composer-* render as single-key maps so the version
     *   constraint travels with the name.
     * - 'not resolvable' renders as a scalar string under the minor
     *   key when composer could not produce a lock for that PHP version.
     *
     * Alongside the platform structure this method also produces the
     * `ci-platform-flags` block: a small map of booleans flagging
     * properties of the transitive dep tree that downstream writers
     * need to know about. Currently the only flag is
     * `needs_installer_plugin` (true when any package in any resolved
     * minor is type horde-library, horde-application, or requires
     * horde/horde-installer-plugin); the composer.json writer reads
     * this to emit the matching `config.allow-plugins` entry.
     *
     * Network access: see {@see self::resolveRangeFromHordeYml()}. This
     * helper inherits the same Packagist round-trips.
     *
     * @return array{
     *     'ci-platform': array<string, list<string|array<string, string>>|string>,
     *     'ci-platform-flags': array<string, bool>
     * }
     */
    public function resolveAndShapeForCiPlatform(
        HordeYmlFile $hordeYml,
        string $componentDir,
    ): array {
        $resolved = $this->resolveRangeFromHordeYml($hordeYml, $componentDir);

        $ciPlatform = [];
        $needsInstallerPlugin = false;

        foreach ($resolved as $minor => $record) {
            if (is_string($record)) {
                // NOT_RESOLVABLE sentinel; pass through under the minor
                // key as a string scalar.
                $ciPlatform[$minor] = $record;
                continue;
            }

            // The new richer record carries both the platform list and
            // the raw lock JSON. Shape platform-list for the ci-platform
            // block, then walk the same lock for the flags.
            $list = [];
            foreach ($record['platform'] as [$name, $constraint]) {
                if (str_starts_with($name, 'ext-') || str_starts_with($name, 'lib-')) {
                    $list[] = $name;
                } else {
                    $list[] = [$name => $constraint ?? '*'];
                }
            }
            $ciPlatform[$minor] = $list;

            // Union across minors: any single minor's resolution
            // surfacing the trigger sets the flag for the component.
            $flags = $this->extractFlagsFromLock($record['lock']);
            if (!empty($flags['needs_installer_plugin'])) {
                $needsInstallerPlugin = true;
            }
        }

        return [
            'ci-platform' => $ciPlatform,
            'ci-platform-flags' => [
                'needs_installer_plugin' => $needsInstallerPlugin,
            ],
        ];
    }

    /**
     * Resolve platform requirements for a single pinned PHP minor by
     * treating the UUT's composer.json as the root project.
     *
     * @param string $uutComposerJsonPath Absolute path to the UUT's
     *                                     composer.json.
     * @param string $minorVersion PHP minor (e.g. "8.3").
     * @param string $rootVersion The UUT's release version. Set as
     *                            COMPOSER_ROOT_VERSION so circular
     *                            require-dev deps resolve. Empty
     *                            disables the env injection.
     * @return array{platform: list<array{string, ?string}>, lock: string}|string
     *         Either a record carrying the parsed platform list and
     *         the raw lock JSON, or {@see self::NOT_RESOLVABLE}. The
     *         lock JSON is returned so additional extractors (flags,
     *         per-package metadata) can be run against the same
     *         resolution without paying for a second composer
     *         round-trip.
     */
    private function resolveSingleForRoot(
        string $uutComposerJsonPath,
        string $minorVersion,
        string $rootVersion = '',
    ): array|string {
        if (!is_readable($uutComposerJsonPath)) {
            return self::NOT_RESOLVABLE;
        }

        $tmpDir = $this->makeTempDir();
        try {
            // 1. Copy the UUT's composer.json into the throwaway dir.
            //    Composer will treat this as the developer's own local
            //    project - no `composer init` synthesis, no
            //    `composer require <pkg>:<constraint>` against Packagist.
            if (!@copy($uutComposerJsonPath, $tmpDir . '/composer.json')) {
                return self::NOT_RESOLVABLE;
            }

            // Defensive: makeTempDir created an empty directory and we
            // never copy composer.lock into it, but be explicit so a
            // future contributor adding `cp -r` here doesn't silently
            // ship a stale lock into the resolver's `composer update`.
            $tmpLock = $tmpDir . '/composer.lock';
            if (is_file($tmpLock)) {
                @unlink($tmpLock);
            }

            // Composer env prefix: ROOT_VERSION lets the synthesized
            // root resolve circular `require-dev` self-references
            // (e.g. horde/icalendar in horde/date's require-dev has
            // `require horde/date ^3`; without ROOT_VERSION composer
            // defaults the root to 1.0.0 and the constraint fails).
            // Empty when the UUT has no declared version.
            $envPrefix = $rootVersion !== ''
                ? sprintf('COMPOSER_ROOT_VERSION=%s ', escapeshellarg($rootVersion))
                : '';

            // 2. Pin platform.php so composer resolves what *this* minor
            //    would see, independent of the PHP version actually
            //    running the resolver.
            $r = $this->shell->exec(
                sprintf('%scomposer config platform.php %s 2>&1', $envPrefix, escapeshellarg($minorVersion . '.0')),
                $tmpDir,
            );
            if ($r->getReturnValue() !== 0) {
                return self::NOT_RESOLVABLE;
            }

            // 3. Stability: always dev + prefer-stable. The transitive
            //    dep graph routinely contains alpha and dev packages from
            //    sibling horde/* libraries while the UUT itself is RC or
            //    stable. minimum-stability=dev + prefer-stable=true lets
            //    composer pick stable where available and fall through to
            //    dev where nothing else exists. Without this, resolve
            //    fails on any tree where a transitive sibling has not
            //    released a stable yet.
            $this->shell->exec($envPrefix . 'composer config minimum-stability dev 2>&1', $tmpDir);
            $this->shell->exec($envPrefix . 'composer config prefer-stable true 2>&1', $tmpDir);

            // 4. Resolve dependencies, ignoring platform requirements so
            //    a missing ext-* on the host running the resolver does
            //    not block the lock from being written. We never install.
            $r = $this->shell->exec(
                $envPrefix . 'composer update --ignore-platform-reqs --no-install --no-interaction 2>&1',
                $tmpDir,
            );
            if ($r->getReturnValue() !== 0) {
                return self::NOT_RESOLVABLE;
            }

            // 5. Parse the lock for platform keys.
            $lockPath = $tmpDir . '/composer.lock';
            if (!is_file($lockPath)) {
                return self::NOT_RESOLVABLE;
            }

            $lockJson = file_get_contents($lockPath);
            if ($lockJson === false) {
                return self::NOT_RESOLVABLE;
            }

            $extracted = $this->extractFromLock($lockJson);
            if ($extracted === []) {
                // A UUT with no transitive platform requirements at all
                // is possible but rare; we cannot distinguish that from
                // "the resolve silently rolled back" with current
                // information. Treat as not resolvable so the maintainer
                // notices and can verify.
                return self::NOT_RESOLVABLE;
            }

            return [
                'platform' => $extracted,
                'lock' => $lockJson,
            ];
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
     * Walk a composer.lock document and surface boolean facts about the
     * transitive dep tree that downstream writers care about.
     *
     * Currently produces a single key, `needs_installer_plugin`, which
     * is true when ANY package in the lock satisfies any of:
     *
     * - `type` is `horde-library`
     * - `type` is `horde-application`
     * - `require` contains `horde/horde-installer-plugin`
     *
     * Composer 2.2+ refuses to run a plugin unless the consuming project
     * lists it under `config.allow-plugins`. A component that pulls
     * `horde/horde-installer-plugin` in transitively (via any
     * horde-library dependency) still needs the plugin active at install
     * time, even when the component's own composer.json does not require
     * it directly. The composer.json writer reads this flag and emits
     * the allow-plugins entry so the install does not silently skip the
     * plugin.
     *
     * Walks both `packages` and `packages-dev`. The dev section is
     * normally empty for synthesized resolver projects but is walked
     * defensively so a transitive plugin in require-dev is still picked
     * up.
     *
     * @return array{needs_installer_plugin: bool}
     */
    public function extractFlagsFromLock(string $lockJson): array
    {
        $flags = ['needs_installer_plugin' => false];

        try {
            $lock = json_decode($lockJson, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $flags;
        }

        if (!is_array($lock)) {
            return $flags;
        }

        $packages = array_merge(
            is_array($lock['packages'] ?? null) ? $lock['packages'] : [],
            is_array($lock['packages-dev'] ?? null) ? $lock['packages-dev'] : [],
        );

        foreach ($packages as $package) {
            if (!is_array($package)) {
                continue;
            }
            $type = $package['type'] ?? null;
            if ($type === 'horde-library' || $type === 'horde-application') {
                $flags['needs_installer_plugin'] = true;
                // Early exit: one positive is enough to set the flag.
                // Continue walking would not change the outcome.
                return $flags;
            }
            $require = $package['require'] ?? null;
            if (is_array($require) && array_key_exists('horde/horde-installer-plugin', $require)) {
                $flags['needs_installer_plugin'] = true;
                return $flags;
            }
        }

        return $flags;
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

        $matched = self::intersectConstraintWithCandidates($constraint);
        if ($matched === null) {
            // Unparseable constraint.
            return [self::CURRENT_MAX_PHP];
        }

        // Defensive fallback: if no candidate satisfies the constraint
        // (e.g. constraint is for an unsupported PHP major), return
        // CURRENT_MAX_PHP rather than an empty range. Callers iterate
        // over the result and an empty list would skip the component
        // entirely.
        if ($matched === []) {
            return [self::CURRENT_MAX_PHP];
        }

        return $matched;
    }

    /**
     * Compute the list of PHP minor versions to use as CI lanes for the
     * component, given its `dependencies.required.php` constraint from
     * `.horde.yml`.
     *
     * Like {@see self::phpVersionRange()} this intersects the constraint
     * against {@see self::CANDIDATE_PHP_MINORS}. The fallback semantics
     * differ: lane selection treats an empty or unparseable constraint
     * as "test on every PHP minor we know" (the full candidate list)
     * rather than "narrow to the latest." That matches what a maintainer
     * expects when they forget to declare a PHP constraint: CI should
     * cast a wide net, not silently pick one version.
     *
     * Unsupported-major constraints (e.g. `^7.4` when we no longer list
     * 7.x candidates) also fall back to the full list rather than
     * collapsing to CURRENT_MAX_PHP, for the same reason.
     *
     * Static because the answer depends only on the constraint string
     * and the canonical candidate list; there is no resolver state to
     * consult. Callers can use this without constructing the heavier
     * PlatformResolver (which carries a shell and an output handle for
     * its composer subprocess work).
     *
     * @return list<string> Ordered list of PHP minors, e.g.
     *                     `['8.2', '8.3', '8.4', '8.5', '8.6']`.
     */
    public static function phpVersionLaneSet(string $constraint): array
    {
        $constraint = trim($constraint);
        if ($constraint === '') {
            return self::CANDIDATE_PHP_MINORS;
        }

        $matched = self::intersectConstraintWithCandidates($constraint);
        if ($matched === null || $matched === []) {
            // Parse failure or no candidate satisfies the constraint.
            // Fall back to the full candidate list rather than narrowing
            // to CURRENT_MAX_PHP; lane selection should default to broad
            // coverage when input is ambiguous.
            return self::CANDIDATE_PHP_MINORS;
        }

        return $matched;
    }

    /**
     * Shared helper: parse the constraint and return every candidate
     * minor that satisfies it, preserving candidate order.
     *
     * @return list<string>|null List of matching minors (possibly
     *                           empty), or null if the constraint
     *                           failed to parse.
     */
    private static function intersectConstraintWithCandidates(string $constraint): ?array
    {
        // Composer accepts both `,` and ` ` as AND separators (per the
        // package versions documentation); Horde\Version\ConstraintParser
        // only recognises space-separated AND, so normalise commas to
        // spaces before parsing. OR (`||`) stays untouched.
        $normalised = preg_replace('/\s*,\s*/', ' ', $constraint) ?? $constraint;

        try {
            $parsed = (new ConstraintParser())->parse($normalised);
        } catch (InvalidVersionException) {
            return null;
        }

        $out = [];
        foreach (self::CANDIDATE_PHP_MINORS as $minor) {
            $probe = new RelaxedSemanticVersion($minor . '.0');
            if ($parsed->isSatisfiedBy($probe)) {
                $out[] = $minor;
            }
        }

        return $out;
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
