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

use Horde\Components\Exception;

/**
 * A single branch declaration from data/branches.yml.
 *
 * Carries the branch name and its {@see BranchPhpPolicy}. A branch in the
 * YAML can be written two ways; both produce a BranchDefinition:
 *
 *   # bare string -> empty policy
 *   - FRAMEWORK_6_0
 *
 *   # single-key map -> policy from the value
 *   - FRAMEWORK_5_2:
 *       min_php_version: "^7.4"
 *
 * A bare string is therefore just a map entry with no optional properties
 * set: {@see self::policy()}->isEmpty() is true.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
final class BranchDefinition
{
    public function __construct(
        private readonly string $name,
        private readonly BranchPhpPolicy $policy,
    ) {}

    /**
     * Build a bare-string branch (empty policy).
     */
    public static function bare(string $name): self
    {
        return new self($name, BranchPhpPolicy::none());
    }

    /**
     * Normalize one YAML branch value into a BranchDefinition.
     *
     * Accepts the two shapes the resolved document produces:
     *
     * - a scalar string branch name, or
     * - a single-key map `['BRANCH_NAME' => <properties|null>]`, where the
     *   properties are a map of policy keys (or null / empty for a map
     *   entry written with no body).
     *
     * @param mixed $value The resolved value of a branch slot.
     * @throws Exception If the value is neither a non-empty string nor a
     *                   single-key map with a non-empty string key.
     */
    public static function fromYamlValue(mixed $value): self
    {
        if (is_string($value)) {
            $name = trim($value);
            if ($name === '') {
                throw new Exception('Branch name must not be empty.');
            }

            return self::bare($name);
        }

        if (is_array($value) && count($value) === 1) {
            $name = (string) array_key_first($value);
            $name = trim($name);
            if ($name === '') {
                throw new Exception('Branch name must not be empty.');
            }

            $properties = $value[array_key_first($value)];
            if ($properties === null) {
                // `FRAMEWORK_x:` with no body -> empty policy.
                return self::bare($name);
            }
            if (!is_array($properties)) {
                throw new Exception(
                    sprintf('Branch "%s" map value must be a property map.', $name)
                );
            }

            return new self($name, BranchPhpPolicy::fromArray($properties));
        }

        throw new Exception(
            'Branch entry must be a string or a single-key map.'
        );
    }

    /**
     * The branch name, e.g. "FRAMEWORK_6_0".
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * The PHP-version policy for this branch (never null; empty for bare
     * branches).
     */
    public function policy(): BranchPhpPolicy
    {
        return $this->policy;
    }

    /**
     * Whether this branch carries any PHP-version policy at all.
     */
    public function hasPolicy(): bool
    {
        return !$this->policy->isEmpty();
    }
}
