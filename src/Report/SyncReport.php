<?php

/**
 * Overall synchronization report for all repositories.
 *
 * PHP Version 8.2+
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

declare(strict_types=1);

namespace Horde\Components\Report;

/**
 * Overall synchronization report for all repositories.
 *
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
class SyncReport
{
    /**
     * Constructor.
     *
     * @param array $results Array of RepositorySyncResult objects
     */
    public function __construct(
        private readonly array $results
    ) {}

    /**
     * Get all repository results.
     *
     * @return array Array of RepositorySyncResult objects
     */
    public function getResults(): array
    {
        return $this->results;
    }

    /**
     * Count repositories with uncommitted changes.
     *
     * @return int Number of dirty repositories
     */
    public function getDirtyCount(): int
    {
        return count(array_filter($this->results, fn($r) => !$r->isClean));
    }

    /**
     * Count repositories with rebase conflicts.
     *
     * @return int Number of repositories with conflicts
     */
    public function getConflictCount(): int
    {
        return count(array_filter($this->results, fn($r) => $r->rebaseConflict));
    }

    /**
     * Count repositories without branch-alias configuration.
     *
     * @return int Number of repositories missing alias config
     */
    public function getMissingAliasCount(): int
    {
        return count(array_filter(
            $this->results,
            fn($r)
            => $r->branchAliasConfig !== null && !$r->branchAliasConfig->configured
        ));
    }

    /**
     * Count repositories ready for patch release.
     *
     * @return int Number of repositories ready for patch
     */
    public function getPatchReadyCount(): int
    {
        return count(array_filter(
            $this->results,
            fn($r)
            => $r->suggestRelease() === 'Patch'
        ));
    }

    /**
     * Count repositories ready for minor release.
     *
     * @return int Number of repositories ready for minor
     */
    public function getMinorReadyCount(): int
    {
        return count(array_filter(
            $this->results,
            fn($r)
            => $r->suggestRelease() === 'Minor'
        ));
    }

    /**
     * Count repositories ready for major release.
     *
     * @return int Number of repositories ready for major
     */
    public function getMajorReadyCount(): int
    {
        return count(array_filter(
            $this->results,
            fn($r)
            => $r->suggestRelease() === 'Major'
        ));
    }

    /**
     * Get total repository count.
     *
     * @return int Total number of repositories processed
     */
    public function getTotalCount(): int
    {
        return count($this->results);
    }

    /**
     * Get repositories with issues.
     *
     * @return array Array of RepositorySyncResult objects with issues
     */
    public function getRepositoriesWithIssues(): array
    {
        return array_filter($this->results, fn($r) => $r->hasIssues());
    }
}
