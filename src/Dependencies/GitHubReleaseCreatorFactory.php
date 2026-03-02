<?php

declare(strict_types=1);

namespace Horde\Components\Dependencies;

use Horde\Components\Helper\GitHubChecker;
use Horde\Components\Helper\GitHubReleaseCreator;
use Horde\Components\Output;
use Horde\GithubApiClient\GithubApiConfig;
use Horde\Injector\Injector;

/**
 * Factory for GitHubReleaseCreator
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
class GitHubReleaseCreatorFactory
{
    public function __invoke(Injector $injector): GitHubReleaseCreator
    {
        $githubChecker = $injector->getInstance(GitHubChecker::class);
        $output = $injector->getInstance(Output::class);
        $githubApiConfig = $injector->getInstance(GithubApiConfig::class);
        return new GitHubReleaseCreator($githubChecker, $output, $githubApiConfig);
    }
}
