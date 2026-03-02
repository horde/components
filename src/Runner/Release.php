<?php

/**
 * Components_Runner_Release:: releases a new version for a package.
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

use Horde\Components\Component;
use Horde\Components\Output;
use Horde\Components\Qc\Tasks as QcTasks;
use Horde\Components\Release\Tasks as ReleaseTasks;
use Horde\Components\Exception;
use Horde\Components\Component\ComponentDirectory;
use Horde\Components\Helper\Git as GitHelper;
use Horde\Components\Helper\Composer as ComposerHelper;
use Horde\Components\Release\HordeRelease;
use Horde\GithubApiClient\GithubApiConfig;

/**
 * Components_Runner_Release:: releases a new version for a package.
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
class Release
{
    /**
     * Constructor.
     *
     * @param Component $component The component to release
     * @param array $arguments CLI arguments for subcommand routing
     * @param array $options CLI options (pipelines, release settings)
     * @param Output $output The output handler
     * @param ReleaseTasks $releaseTasks The tasks handler
     * @param QcTasks $qcTasks QC tasks handler
     */
    public function __construct(
        private readonly Component $component,
        private readonly array $arguments,
        private readonly array $options,
        private readonly Output $output,
        private readonly ReleaseTasks $releaseTasks,
        private readonly QcTasks $qcTasks
    ) {}

    /**
     * @throws Exception
     */
    public function run(): void
    {
        /**
         * Catch predefined release pipelines
         */
        if ((count($this->arguments) == 3)
            && $this->arguments[0] == 'release'
            && $this->arguments[1] == 'for') {
            $pipeline = $this->arguments[2];
            if (empty($this->options['pipeline']['release'][$pipeline])) {
                $this->output->warn("Pipeline $pipeline not defined in config");
                return;
            }
            $this->releaseTasks->run(
                ['pipeline:', $pipeline],
                $this->component,
                $this->options
            );
            return;
        } elseif ((count($this->arguments) == 2)
        && $this->arguments[0] == 'release'
        && $this->arguments[1] == 'h6') {
            $this->output->warn('H6 Release Pipeline');
            $path = new ComponentDirectory($this->component->getComponentDirectory());
            $gitHelper = new GitHelper();
            $composerHelper = new ComposerHelper();

            // Get GitHubChecker and GitHubReleaseCreator from dependencies
            $githubChecker = new \Horde\Components\Helper\GitHubChecker($gitHelper);

            // Get GitHub token from environment
            $githubToken = getenv('GITHUB_TOKEN') ?: '';
            $githubApiConfig = new GithubApiConfig(accessToken: $githubToken);

            $githubReleaseCreator = new \Horde\Components\Helper\GitHubReleaseCreator(
                $githubChecker,
                $this->output,
                $githubApiConfig
            );

            $release = new HordeRelease(
                $composerHelper,
                $gitHelper,
                $path,
                $this->output,
                $githubChecker,
                $githubReleaseCreator,
                $this->qcTasks
            );

            $release->run($this->component, $this->options);
            return;
        } else {
            $this->output->warn('Run "horde-components release for <pipeline>"');
            $this->output->info("Available pipelines from your configuration: \n" . implode("\n", array_keys($this->options['pipeline']['release'] ?? [])));
        }
    }
}
