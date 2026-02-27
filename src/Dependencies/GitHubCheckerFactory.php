<?php

declare(strict_types=1);

namespace Horde\Components\Dependencies;

use Horde\Components\Helper\Git as GitHelper;
use Horde\Components\Helper\GitHubChecker;
use Horde\Injector\Injector;

/**
 * Factory for GitHubChecker
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
class GitHubCheckerFactory
{
    public function __invoke(Injector $injector): GitHubChecker
    {
        $gitHelper = $injector->getInstance(GitHelper::class);
        return new GitHubChecker($gitHelper);
    }
}
