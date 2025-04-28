<?php

namespace Horde\Components;

/**
 * See https://www.conventionalcommits.org/en/v1.0.0/
 *
 * PHP version 8
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 */
final class ConventionalCommitReader
{
    private GitCommitLog $log;
    private string $topSeverity = 'subpatch';
    private string $stability = 'unchanged';
    public function __construct(
        GitCommitLog $log,
    ) {
        $this->readConventionalCommits($log);
    }


    private function readConventionalCommits(GitCommitLog $log): void
    {
        $conventionalCommits = [];
        foreach ($log as $commit) {
            $conventionalCommit = ConventionalCommit::fromGitCommit($commit);
            if ($conventionalCommit) {
                $this->setTopSeverity($conventionalCommit->severity);
                $conventionalCommits[] = $conventionalCommit;
            }
        }
        // Get the latest stability-changing commit
        foreach ($conventionalCommits as $commit) {
            if ($commit->stability !== 'unchanged') {
                $this->stability = $commit->stability;
            }
            break;
        }
        // TODO: Handle trailers
        // Save only conventional commits
        $this->log = new GitCommitLog(...$conventionalCommits);
    }
    private function setTopSeverity(string $severity): void
    {
        $severityValues = [
            'subpatch' => 1,
            'patch' => 2,
            'minor' => 3,
            'major' => 4,
        ];
        // TODO: Croak on unknown severity
        $severityValue = $severityValues[$severity];
        if ($severityValues[$severity] > $severityValues[$this->topSeverity]) {
            $this->topSeverity = $severity;
        }
    }
    public function getTopSeverity(): string
    {
        return $this->topSeverity;
    }
    public function getLatestStabilityChange(): string
    {
        return $this->stability;
    }
    public function getLog(): GitCommitLog
    {
        return $this->log;
    }
}
