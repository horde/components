<?php

/**
 * Components\Runner\ConventionalCommit:: isolated actions for conventional commits.
 *
 * PHP Version 8.2+
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

declare(strict_types=1);

namespace Horde\Components\Runner;

use Horde\Components\Output;
use Horde\Components\Helper\Git as GitHelper;
use Horde\Components\Helper\Version as VersionHelper;
use Horde\Components\ConventionalCommitReader;
use InvalidArgumentException;

/**
 * Components_Runner_Change:: adds a new change log entry.
 *
 * Copyright 2011-2026 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class ConventionalCommit
{
    private VersionHelper $lastVersion;
    private VersionHelper $nextVersion;

    /**
     * Constructor.
     *
     * @param array $arguments CLI arguments for subcommand routing
     * @param string $workingDir The working directory
     * @param Output $output The output handler
     */
    public function __construct(
        private readonly array $arguments,
        private readonly string $workingDir,
        private readonly Output $output
    ) {}

    public function loadCommitReader(): ConventionalCommitReader
    {
        // TODO: Externalize this later
        $gitHelper = new GitHelper();
        $gitLog = $gitHelper->getGitLog($this->workingDir);
        $originalTagString = '0.0.1alpha1';
        foreach ($gitLog as $commit) {
            if ($commit->hasTags()) {
                $gitLog = $gitLog->getLogSince($commit);
                $originalTagString = $commit->getTagName();
                break;
            }
        }
        // Guard against tags that don't look like a version
        $versionHelper = VersionHelper::fromComposerString($originalTagString);
        $this->lastVersion = $versionHelper;
        $conventional = new ConventionalCommitReader($gitLog);
        $this->nextVersion = $this->lastVersion->nextVersionObject(severity: $conventional->getTopSeverity(), stability: $conventional->getLatestStabilityChange());
        return $conventional;
    }

    public function runShow(): void
    {
        $conventional = $this->loadCommitReader();
        $gitLog = $conventional->getLog();
        $this->output->plain(sprintf("Found %d commits in Conventional Commits format since the last tag %s", count($gitLog), $this->lastVersion->toHordeTag()));
        $this->output->plain("see https://www.conventionalcommits.org/");
        $this->output->plain(sprintf("Highest severity: %s\n", $conventional->getTopSeverity()));
        $this->output->plain("Anticipated next version tag: " . $this->nextVersion->toHordeTag());
        $this->output->plain("Stability: " . $conventional->getLatestStabilityChange());
        foreach ($gitLog as $commit) {
            $this->output->plain(str_repeat("-", 79));
            $this->output->plain(sprintf("%8s %8s %8s: %s", $commit->type, $commit->scope, $commit->severity, $commit->description));
            if ($commit->stability !== 'unchanged') {
                $this->output->plain("Stability: " . $commit->stability);
            }
        }
    }

    public function runLastVersion(): void
    {
        $conventional = $this->loadCommitReader();
        $this->output->plain($this->lastVersion->toHordeTag());
    }
    public function runNextVersion(): void
    {
        $conventional = $this->loadCommitReader();
        $this->output->plain($this->nextVersion->toHordeTag());
    }

    public function run(): void
    {
        $action = 'show';
        if (count($this->arguments) === 1 && $this->arguments[0] === 'conventionalcommit') {
            $this->runShow();
            return;
        }
        if (count($this->arguments) > 1 && $this->arguments[0] === 'conventionalcommit') {
            $action = $this->arguments[1];
        }
        switch ($action) {
            case 'show':
                $this->runShow();
                break;
            case 'lastversion':
                $this->runLastVersion();
                break;
            case 'nextversion':
                $this->runNextVersion();
                break;
            default:
                throw new InvalidArgumentException('Unknown action: ' . $action);
        }

    }
}
