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

namespace Horde\Components\Release;

use Horde\Components\Util\YamlLoader;

/**
 * Map of git branch roles.
 *
 * Horde uses synchronized branch names across every repository to maintain which framework generation something belongs to.
 * However it uses individual SemVer versions for libraries and apps.
 *
 * So Horde 5.x might have horde/mail 2.x and Horde 6.0 (FRAMEWORK_6_0) may have any horde/mail 3.x version up to 3.5.x  bounded by when Horde 6.1 is defined, it comes with horde/mail 3.6.0 and no further feature release are allowed on FRAMEWORK_6_0.
 *
 * The role of a branch (release, supported, unstable) is managed centrally either through builtin defaults or a YAML file.
 *
 * Roles:
 * - release:   The single release branch for the current FRAMEWORK_X_Y generation.
 *              Also the repository default branch. Accepts features and bugfixes.
 * - supported: Maintenance branches that take bugfix-class releases only.
 * - unstable:  Optional branch for BC-breaking work.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class BranchRoles
{
    public const ROLE_RELEASE = 'release';
    public const ROLE_SUPPORTED = 'supported';
    public const ROLE_UNSTABLE = 'unstable';
    public const ROLE_UNKNOWN = 'unknown';

    private readonly string $release;

    /** @var list<string> */
    private readonly array $supported;

    private readonly ?string $unstable;

    /**
     * Constructor.
     *
     * @param string $release The release branch name.
     * @param list<string> $supported Supported (maintenance) branch names.
     * @param string|null $unstable The unstable branch name, or null if none.
     */
    public function __construct(
        string $release,
        array $supported = [],
        ?string $unstable = null
    ) {
        $this->release = $release;
        $this->supported = array_values(array_map('strval', $supported));
        $this->unstable = ($unstable === null || $unstable === '') ? null : $unstable;
    }

    /**
     * Build from a parsed YAML array data/branches.yml
     *
     * @param array<string,mixed> $data Parsed YAML.
     */
    public static function fromArray(array $data): self
    {
        $supported = $data['supported'] ?? [];
        if (!is_array($supported)) {
            $supported = [];
        }

        return new self(
            (string) ($data['release'] ?? ''),
            $supported,
            isset($data['unstable']) && $data['unstable'] !== null
                ? (string) $data['unstable']
                : null
        );
    }

    /**
     * Load the branch-role map from a YAML file.
     *
     * @param string|null $file Path to the YAML file. Defaults to data/branches.yml (or the phar bundled equivalent)
     *
     */
    public static function load(?string $file = null): self
    {
        $file ??= self::bundledFile();

        return self::fromArray(YamlLoader::loadFile($file));
    }

    /**
     * Absolute path to the default branch role map.
     */
    public static function bundledFile(): string
    {
        return dirname(__DIR__, 2) . '/data/branches.yml';
    }

    /**
     * The release branch name.
     */
    public function releaseBranch(): string
    {
        return $this->release;
    }

    /**
     * The supported (maintenance) branch names.
     *
     * @return list<string>
     */
    public function supportedBranches(): array
    {
        return $this->supported;
    }

    /**
     * The unstable branch name, or null when none is declared.
     */
    public function unstableBranch(): ?string
    {
        return $this->unstable;
    }

    /**
     * The role of a given branch name.
     *
     * @param string $branch The branch name.
     * @return string One of the ROLE_* constants.
     */
    public function roleOf(string $branch): string
    {
        if ($branch !== '' && $branch === $this->release) {
            return self::ROLE_RELEASE;
        }
        if (in_array($branch, $this->supported, true)) {
            return self::ROLE_SUPPORTED;
        }
        if ($this->unstable !== null && $branch === $this->unstable) {
            return self::ROLE_UNSTABLE;
        }

        return self::ROLE_UNKNOWN;
    }

    /**
     * Whether a branch is the release branch.
     */
    public function isRelease(string $branch): bool
    {
        return $this->roleOf($branch) === self::ROLE_RELEASE;
    }

    /**
     * Whether a branch is a supported (maintenance) branch.
     */
    public function isSupported(string $branch): bool
    {
        return $this->roleOf($branch) === self::ROLE_SUPPORTED;
    }
}
