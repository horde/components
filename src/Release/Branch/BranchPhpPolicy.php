<?php

declare(strict_types=1);

/**
 * Copyright 2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

namespace Horde\Components\Release\Branch;

use Horde\Version\ConstraintParser;
use Horde\Version\InvalidVersionException;
use Horde\Version\VersionConstraint;

/**
 * Per-branch PHP-version policy from data/branches.yml.
 *
 * A branch map entry may carry optional PHP-version constraints that the
 * collection enforces across every package on that branch:
 *
 * - min_php_version:      composer-like constraint string, e.g. "^8.2".
 *                         The floor packages must meet or exceed.
 * - sync_min_php_version: when true, pin every package to exactly this
 *                         floor (no higher per-package minimums allowed).
 * - max_php_version:      composer-like constraint string, e.g. "^8.6".
 *                         The ceiling packages must stay at or below.
 * - sync_max_php_version: when true, give every package exactly this
 *                         ceiling (no tighter per-package maximums).
 *
 * All four properties are optional. A bare-string branch (one with no map
 * value) is modelled as an empty policy: {@see self::isEmpty()} is true
 * and every accessor returns null/false.
 *
 * The raw constraint strings are kept verbatim; {@see self::minConstraint()}
 * and {@see self::maxConstraint()} parse them on demand through
 * horde/version's {@see ConstraintParser}, which already understands every
 * composer shape (caret, tilde, range, wildcard, AND, OR, comparisons).
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
final class BranchPhpPolicy
{
    public function __construct(
        private readonly ?string $minPhpVersion = null,
        private readonly bool $syncMinPhpVersion = false,
        private readonly ?string $maxPhpVersion = null,
        private readonly bool $syncMaxPhpVersion = false,
    ) {}

    /**
     * The empty policy: nothing constrained, nothing synchronized.
     *
     * Used for bare-string branches.
     */
    public static function none(): self
    {
        return new self();
    }

    /**
     * Build from the properties of a branch map entry.
     *
     * Unknown keys are ignored. Missing version keys become null; missing
     * or non-boolean sync flags become false. An empty-string version is
     * treated as "not set" (null) so a stray `min_php_version:` with no
     * value does not produce an unparseable constraint later.
     *
     * @param array<string,mixed> $properties The map value of a branch entry.
     */
    public static function fromArray(array $properties): self
    {
        return new self(
            self::normalizeVersion($properties['min_php_version'] ?? null),
            self::normalizeFlag($properties['sync_min_php_version'] ?? null),
            self::normalizeVersion($properties['max_php_version'] ?? null),
            self::normalizeFlag($properties['sync_max_php_version'] ?? null),
        );
    }

    /**
     * The raw minimum PHP-version constraint string, or null when unset.
     */
    public function min(): ?string
    {
        return $this->minPhpVersion;
    }

    /**
     * The raw maximum PHP-version constraint string, or null when unset.
     */
    public function max(): ?string
    {
        return $this->maxPhpVersion;
    }

    /**
     * Whether every package's minimum PHP version must be pinned to
     * exactly {@see self::min()}.
     */
    public function syncsMin(): bool
    {
        return $this->syncMinPhpVersion;
    }

    /**
     * Whether every package's maximum PHP version must be pinned to
     * exactly {@see self::max()}.
     */
    public function syncsMax(): bool
    {
        return $this->syncMaxPhpVersion;
    }

    /**
     * The parsed minimum constraint, or null when no minimum is set.
     *
     * @throws InvalidVersionException If the stored string is not a valid
     *                                 constraint.
     */
    public function minConstraint(): ?VersionConstraint
    {
        return $this->minPhpVersion === null
            ? null
            : (new ConstraintParser())->parse($this->minPhpVersion);
    }

    /**
     * The parsed maximum constraint, or null when no maximum is set.
     *
     * @throws InvalidVersionException If the stored string is not a valid
     *                                 constraint.
     */
    public function maxConstraint(): ?VersionConstraint
    {
        return $this->maxPhpVersion === null
            ? null
            : (new ConstraintParser())->parse($this->maxPhpVersion);
    }

    /**
     * Whether this policy constrains nothing at all.
     *
     * True for a bare-string branch and for a map entry that happens to
     * set none of the four properties.
     */
    public function isEmpty(): bool
    {
        return $this->minPhpVersion === null
            && $this->maxPhpVersion === null
            && !$this->syncMinPhpVersion
            && !$this->syncMaxPhpVersion;
    }

    /**
     * Coerce a raw YAML value into a trimmed constraint string or null.
     *
     * YAML may hand us a float (unquoted `8.1`) or int; cast to string so
     * the constraint parser sees "8.1" rather than a number. Empty and
     * non-scalar values degrade to null.
     */
    private static function normalizeVersion(mixed $value): ?string
    {
        if ($value === null || is_bool($value) || is_array($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * Coerce a raw YAML value into a strict boolean flag.
     *
     * Only a real true (or the string "true") enables a sync flag;
     * anything else, including null, is false.
     */
    private static function normalizeFlag(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return is_string($value) && strtolower(trim($value)) === 'true';
    }
}
