<?php

declare(strict_types=1);

namespace Horde\Components\Dependencies;

use Horde\Components\Helper\GitHubChecker;
use Horde\Components\Helper\PullRequestManager;
use Horde\Components\Output;
use Horde\Injector\Injector;

/**
 * Factory for PullRequestManager
 *
 * Copyright 2013-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class PullRequestManagerFactory
{
    public function __invoke(Injector $injector): PullRequestManager
    {
        $githubChecker = $injector->getInstance(GitHubChecker::class);
        $output = $injector->getInstance(Output::class);
        return new PullRequestManager($githubChecker, $output);
    }
}
