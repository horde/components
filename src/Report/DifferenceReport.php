<?php

/**
 * Repository difference report between GitHub and local checkout.
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
 * Repository difference report between GitHub and local checkout.
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
class DifferenceReport
{
    /**
     * Constructor.
     *
     * @param array $remoteOnly Repositories on GitHub not cloned locally
     * @param array $localOnly Local directories not found on GitHub (orphaned)
     * @param array $inSync Repositories that exist in both places
     */
    public function __construct(
        public readonly array $remoteOnly,
        public readonly array $localOnly,
        public readonly array $inSync
    ) {}

    /**
     * Check if there are repositories on GitHub not cloned locally.
     *
     * @return bool True if remote-only repos exist
     */
    public function hasRemoteOnly(): bool
    {
        return !empty($this->remoteOnly);
    }

    /**
     * Check if there are local directories not found on GitHub.
     *
     * @return bool True if local-only directories exist
     */
    public function hasLocalOnly(): bool
    {
        return !empty($this->localOnly);
    }

    /**
     * Get total count of remote repositories.
     *
     * @return int Total remote repository count
     */
    public function countRemote(): int
    {
        return count($this->remoteOnly) + count($this->inSync);
    }

    /**
     * Get total count of local repositories.
     *
     * @return int Total local repository count
     */
    public function countLocal(): int
    {
        return count($this->localOnly) + count($this->inSync);
    }

    /**
     * Get count of repositories in sync.
     *
     * @return int Count of in-sync repositories
     */
    public function countInSync(): int
    {
        return count($this->inSync);
    }
}
