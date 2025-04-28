<?php
/**
 * Components\Runner\ConventionalCommit:: isolated actions for conventional commits.
 *
 * PHP Version 8
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

namespace Horde\Components\Runner;

use Horde\Components\Config;
use Horde\Components\Helper\Commit as CommitHelper;
use Horde\Components\Output;
use Horde\Components\Helper\Git as GitHelper;
use Horde\Components\Helper\Version as VersionHelper;
use Horde\Components\ConventionalCommitReader;

/**
 * Components_Runner_Change:: adds a new change log entry.
 *
 * Copyright 2011-2024 Horde LLC (http://www.horde.org/)
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
     * @param Config $_config The configuration for the current job.
     * @param Output $_output The output handler.
     */
    public function __construct(
        private readonly Config $_config,
        /**
         * The output handler.
         *
         * @param Output
         */
        private readonly Output $_output
    ) {
    }

    public function loadCommitReader(): ConventionalCommitReader
    {
        // TODO: Externalize this later
        $gitHelper = new GitHelper();
        // TODO: Don't rely on cwd, rely on component path
        $gitLog = $gitHelper->getGitLog(getcwd());
        $originalTagString='0.0.1alpha1';
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
        $this->nextVersion = $this->lastVersion->nextVersionObject($conventional->getTopSeverity());
        return $conventional;
    }
    public function runShow(): void
    {
        $conventional = $this->loadCommitReader();
        $this->nextVersion = $this->lastVersion->nextVersionObject($conventional->getTopSeverity());
        $gitLog = $conventional->getLog();
        $this->_output->plain(sprintf("Found %d commits in Conventional Commits format since the last tag %s", count($gitLog), $this->lastVersion->toHordeTag()));
        $this->_output->plain("see https://www.conventionalcommits.org/");
        $this->_output->plain(sprintf("Highest severity: %s\n", $conventional->getTopSeverity()));
        $this->_output->plain("Anticipated next version tag: " . $this->nextVersion->toHordeTag());
        foreach ($gitLog as $commit) {
            $this->_output->plain(str_repeat("-", 79));
            $this->_output->plain(sprintf("%8s %8s %8s: %s\n", $commit->type, $commit->scope, $commit->severity, $commit->description));
        }
    }

    public function runLastVersion(): void
    {
        $conventional = $this->loadCommitReader();
        $this->_output->plain($this->lastVersion->toHordeTag());
    }
    public function runNextVersion(): void
    {
        $conventional = $this->loadCommitReader();
        $this->_output->plain($this->nextVersion->toHordeTag());
    }

    public function run(Config $config): void
    {
        $arguments = $this->_config->getArguments();
        $action = 'show';
        if (count($arguments) === 1 && $arguments[0] === 'conventionalcommit') {
            $this->runShow();
            return;
        }
        if (count($arguments) > 1 && $arguments[0] === 'conventionalcommit') {
            $action = $arguments[1];
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
                throw new \InvalidArgumentException('Unknown action: ' . $action);
        }

    }
}
