<?php

/**
 * Horde\Components\Runner\Github:: runner for github operations.
 *
 * PHP Version 8.2+
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

declare(strict_types=1);

namespace Horde\Components\Runner;

use Horde\Components\ConfigProvider\EffectiveConfigProvider;
use Horde\Components\Exception;
use RuntimeException;
use Horde\Components\Helper\Git as GitHelper;
use Horde\Components\Output;
use Horde\GithubApiClient\GithubApiClient;
use Horde\GithubApiClient\GithubOrganizationId;
use Horde\Components\RuntimeContext\GitCheckoutDirectory;

/**
 * Horde\Components\Runner\Github:: runner for github operations.
 *
 * Copyright 2020-2026 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
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
     * @param EffectiveConfigProvider $config Configuration provider
     * @param array $arguments CLI arguments
     * @param Output $output The output handler
     * @param GitHelper $gitHelper Git helper for operations
     * @param GithubApiClient $client Github API client
     * @param GitCheckoutDirectory $gitCheckoutDirectory Default checkout directory
     */
    public function __construct(
        private readonly EffectiveConfigProvider $config,
        private readonly array $arguments,
        private readonly Output $output,
        private readonly GitHelper $gitHelper,
        private readonly GithubApiClient $client,
        private readonly GitCheckoutDirectory $gitCheckoutDirectory
    ) {
        // Try new name first, then fall back to old name for backwards compatibility
        $this->gitRepoBase = $this->config->hasSetting('scm.repo.base')
            ? $this->config->getSetting('scm.repo.base')
            : ($this->config->hasSetting('git_repo_base')
                ? $this->config->getSetting('git_repo_base')
                : 'https://github.com/horde/');

        $this->localCheckoutDir = $this->config->hasSetting('checkout.dir')
            ? $this->config->getSetting('checkout.dir')
            : (string) $this->gitCheckoutDirectory;
    }

    public function run(): void
    {
        if (count($this->arguments) == 1 && $this->arguments[0] == 'github-clone-org') {
            // TODO: Configure this
            $headBranch = 'FRAMEWORK_6_0';
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
            $catalog = [];
            foreach ($repoMeta as $repo) {
                // TODO: Build a helper object for checking if repo dir exists and another for creating if not
                $this->output->plain('Checking ' . $repo->getFullName());
                $repoDir = $this->localCheckoutDir . DIRECTORY_SEPARATOR . $repo->getFullName();
                if (is_dir($repoDir . DIRECTORY_SEPARATOR . '.git')) {
                    $this->output->plain('Repo seems to be checked out already: ' . $repo->getFullName());
                    // Update the local component
                    $this->gitHelper->fetch($repoDir);
                    $this->gitHelper->workflowUpdate($this->output, $repoDir, branch: $headBranch, source: $headBranch);
                } else {
                    $this->output->plain('Repo needs to be cloned: ' . $repo->getFullName());
                    $res = mkdir(directory: $repoDir, recursive: true);
                    $this->gitHelper->workflowClone($this->output, $repo->getCloneUrl(), $repoDir);
                }
                $catalog[$repo->getFullName()] = ['path' => $this->localCheckoutDir];
            }
            file_put_contents($this->localCheckoutDir . '/repos.json', json_encode($catalog, JSON_PRETTY_PRINT));
            return;
        } elseif (count($this->arguments) == 1) {
            $this->output->help('For usage help, run: horde-components help git');
            return;
        }
    }
}
