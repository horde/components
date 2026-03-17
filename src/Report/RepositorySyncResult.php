<?php

/**
 * Repository synchronization result for a single repository.
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
 * Repository synchronization result for a single repository.
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
class RepositorySyncResult
{
    /**
     * Constructor.
     *
     * @param string $path Repository path
     * @param string $name Repository name
     * @param bool $isClean Working tree is clean
     * @param string|null $currentBranch Current branch name
     * @param bool $rebaseSuccessful Rebase succeeded
     * @param bool $rebaseConflict Rebase had conflicts
     * @param BranchAliasCheck|null $branchAliasConfig Branch alias check result
     * @param string|null $lastTag Last git tag
     * @param int $featCount Number of feat commits since tag
     * @param int $fixCount Number of fix commits since tag
     * @param int $testCount Number of test commits since tag
     * @param int $breakingCount Number of breaking changes since tag
     * @param string|null $skipReason Reason for skipping sync operations
     */
    public function __construct(
        public readonly string $path,
        public readonly string $name,
        public bool $isClean = false,
        public ?string $currentBranch = null,
        public bool $rebaseSuccessful = false,
        public bool $rebaseConflict = false,
        public ?BranchAliasCheck $branchAliasConfig = null,
        public ?string $lastTag = null,
        public int $featCount = 0,
        public int $fixCount = 0,
        public int $testCount = 0,
        public int $breakingCount = 0,
        public ?string $skipReason = null
    ) {}

    /**
     * Suggest the type of release based on commit analysis.
     *
     * @return string Release suggestion: "Major", "Minor", "Patch", "Clean first", "Resolve conflict", or "—"
     */
    public function suggestRelease(): string
    {
        if (!$this->isClean) {
            return 'Clean first';
        }
        if ($this->rebaseConflict) {
            return 'Resolve conflict';
        }
        if ($this->breakingCount > 0) {
            return 'Major';
        }
        if ($this->featCount > 0) {
            return 'Minor';
        }
        if ($this->fixCount > 0) {
            return 'Patch';
        }
        return '—';
    }

    /**
     * Get formatted commit count string.
     *
     * @return string Format: "feat/fix/test"
     */
    public function getCommitCountString(): string
    {
        if ($this->skipReason !== null) {
            return '—';
        }
        return sprintf('%d/%d/%d', $this->featCount, $this->fixCount, $this->testCount);
    }

    /**
     * Check if repository has any issues.
     *
     * @return bool True if there are issues (dirty, conflict, no alias)
     */
    public function hasIssues(): bool
    {
        return !$this->isClean
            || $this->rebaseConflict
            || ($this->branchAliasConfig !== null && !$this->branchAliasConfig->configured);
    }
}
