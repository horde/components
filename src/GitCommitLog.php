<?php

namespace Horde\Components;

use IteratorAggregate;
use ArrayIterator;
use Countable;
use Traversable;

/**
 * Horde\Components\GitCommitLog: Holds a collection of GitCommit objects
 *
 * PHP version 8
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 */
class GitCommitLog implements IteratorAggregate, Countable
{
    private array $commits = [];
    public function __construct(GitCommit ...$commits)
    {
        $this->commits = $commits;
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->commits);
    }

    public function count(): int
    {
        return count($this->commits);
    }

    public function getCommitByHash(string $hash): ?GitCommit
    {
        foreach ($this->commits as $commit) {
            if ($commit->commit === $hash) {
                return $commit;
            }
        }
        return null;
    }

    /**
     * Get a new log from newest to excluding the given commit.
     *
     * If the commit is not found, return the whole log.
     *
     * @return GitCommitLog
     */
    public function getLogSince(GitCommit $reference): GitCommitLog
    {
        $newCommits = [];
        foreach ($this->commits as $commit) {
            if ($commit->commit === $reference->commit) {
                return new GitCommitLog(...$newCommits);
            } else {
                $newCommits[] = $commit;
            }
        }
        return new GitCommitLog($newCommits);
    }

    public function getCommitByTag(string $tag): ?GitCommit
    {
        foreach ($this->commits as $commit) {
            if ($commit->hasTags() && $commit->getTagName() === $tag) {
                return $commit;
            }
        }
        return null;
    }
}
