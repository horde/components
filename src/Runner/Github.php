<?php
/**
 * Horde\Components\Runner\Git:: runner for git operations.
 *
 * PHP Version 7
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <lang@b1-systems.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

namespace Horde\Components\Runner;

use Horde\Components\Config;
use Horde\Components\Exception;
use RuntimeException;
use Horde\Components\Helper\Git as GitHelper;
use Horde\Components\Output;
use Horde\GithubApiClient\GithubApiClient;
use Horde\GithubApiClient\GithubOrganizationId;

/**
 * Horde\Components\Runner\Github:: runner for git operations.
 *
 * Copyright 2020-2021 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <lang@b1-systems.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Github
{
    /**
     * The repo base url.
     */
    private readonly string $gitRepoBase;

    /**
     * Where do we store local checkouts.
     */
    private readonly string $localCheckoutDir;

    /**
     * Constructor.
     *
     * @param Config    $config  The configuration for the current job.
     * @param Output    $output  The output handler.
     * @param GitHelper $git     The output handler.
     */
    public function __construct(
        private readonly Config $config,
        private readonly Output $output,
        private GitHelper $gitHelper,
        private GithubApiClient $client
    ) {
        //        $this->gitHelper = $git;
        $options = $this->config->getOptions();
        $this->gitRepoBase = $options['git_repo_base'] ??
        'https://github.com/horde/';
        $this->localCheckoutDir = $options['checkout_dir'] ?? '/srv/git/';
    }

    public function run()
    {
        $arguments = $this->config->getArguments();
        if (count($arguments) == 1 && $arguments[0] == 'github-clone-org') {
            $this->output->ok('About the clone a complete github org.');
            $this->output->plain('Trying to get the catalog');
            $repoMeta = $this->client->listRepositoriesInOrganization(new GithubOrganizationId('horde'));
            // TODO: Build a helper object for checking if repo dir exists
            if (!file_exists($this->localCheckoutDir)) {
                $this->output->plain('Trying to create local checkout directory: ' . $this->localCheckoutDir);
                $res = mkdir(directory: $this->localCheckoutDir, recursive: true);
            }
            if (!is_dir($this->localCheckoutDir)) {
                $this->output->plain('Local checkout directory missing and could not be created: ' . $this->localCheckoutDir);
                throw new RuntimeException('Local checkout directory missing and could not be created');
            }
            if (!is_writable($this->localCheckoutDir)) {
                $this->output->plain('Local checkout directory is not writable: ' . $this->localCheckoutDir);
                throw new RuntimeException('Local checkout directory is not writable');
            }

            foreach ($repoMeta as $repo)
            {
                // TODO: Build a helper object for checking if repo dir exists and another for creating if not
                $this->output->plain('Checking ' . $repo->getFullName());
                $repoDir = $this->localCheckoutDir . DIRECTORY_SEPARATOR  . $repo->getFullName();
                if (is_dir($repoDir . DIRECTORY_SEPARATOR . '.git')) {
                    $this->output->plain('Repo seems to be checked out already: ' . $repo->getFullName());
                } else {
                    $this->output->plain('Repo needs to be cloned: ' . $repo->getFullName());
                    $res = mkdir(directory: $repoDir, recursive: true);
                    $this->gitHelper->workflowClone($this->output, $repo->getCloneUrl(), $repoDir);
                }
            }
            return;
        } elseif (count($arguments) == 1) {
            $this->output->help('For usage help, run: horde-components help git');
            $this->config->unshiftArgument('help');
            return;
        }
    }
}
