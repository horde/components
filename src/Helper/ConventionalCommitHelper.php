<?php

namespace Horde\Components\Helper;

use Horde\Components\GitCommitLog;
use Horde\Components\ConventionalCommitReader;

/**
 * Read git commits since last tag and determine
 * - Conventional commits
 * - Last version
 * - Next version
 */
class ConventionalCommitHelper
{
    public readonly Version $lastVersion;
    public readonly Version $nextVersion;
    public readonly ConventionalCommitReader $commitReader;

    // TODO: Support more unusual cases like looking up histories from-to
    public function __construct(Git $gitHelper)
    {
        $gitLog = $gitHelper->getGitLog(getcwd());
        $originalTagString = '0.0.1alpha1';
        foreach ($gitLog as $commit) {
            if ($commit->hasTags()) {
                $gitLog = $gitLog->getLogSince($commit);
                $originalTagString = $commit->getTagName();
                break;
            }
        }
        // Guard against tags that don't look like a version
        $versionHelper = Version::fromComposerString($originalTagString);
        $this->lastVersion = $versionHelper;
        $conventional = new ConventionalCommitReader($gitLog);
        $this->nextVersion = $this->lastVersion->nextVersionObject(severity: $conventional->getTopSeverity(), stability: $conventional->getLatestStabilityChange());
        $this->commitReader = $conventional;
    }

}
