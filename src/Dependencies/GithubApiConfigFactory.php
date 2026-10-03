<?php

declare(strict_types=1);

namespace Horde\Components\Dependencies;

use Horde\Components\Auth\AuthenticationFactory;
use Horde\GithubApiClient\GithubApiConfig;
use Horde\Injector\Injector;

/**
 * Factory for the shared GithubApiConfig instance
 *
 * Resolves the GithubApiConfig (endpoint/accessToken) through
 * AuthenticationFactory::createConfig(), so it honors the documented
 * GitHub App > GITHUB_TOKEN env var > github.token config precedence -
 * rather than the previous bootstrap-time construction, which only ever
 * considered GITHUB_TOKEN/github.token and silently ignored any
 * configured GitHub App credentials.
 *
 * Bound lazily (bindFactory, not setInstance) so this - and any GitHub
 * App JWT/installation-token network round-trip it may trigger - only
 * happens the first time something actually needs GitHub auth (e.g.
 * github-clone-org, PR/release creation), not on every CLI invocation.
 * The resolved instance is then cached by the injector for the rest of
 * the process, same as any other getInstance() target.
 *
 * Copyright 2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class GithubApiConfigFactory
{
    public function __construct(private readonly Injector $injector) {}

    public function __invoke(): GithubApiConfig
    {
        return $this->injector->getInstance(AuthenticationFactory::class)->createConfig();
    }
}
