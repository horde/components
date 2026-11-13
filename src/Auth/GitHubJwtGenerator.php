<?php

declare(strict_types=1);

namespace Horde\Components\Auth;

use RuntimeException;

/**
 * GitHub-specific JWT generator wrapper
 *
 * Wraps the local Rs256Generator with GitHub App constraints (max 600 second expiry).
 * This keeps GitHub-specific business logic in one place while delegating the
 * actual RS256 signing to the shared implementation.
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
class GitHubJwtGenerator implements JwtGeneratorInterface
{
    private Rs256JwtGenerator $generator;

    public function __construct()
    {
        $this->generator = new Rs256JwtGenerator();
    }

    /**
     * Generate a JWT for GitHub App authentication
     *
     * @param int $appId GitHub App ID (iss claim)
     * @param PrivateKey $privateKey Private key for signing
     * @param int $expirySeconds JWT expiry time in seconds (max 600 for GitHub)
     * @return GeneratedJwt
     * @throws RuntimeException If JWT generation fails or expiry exceeds GitHub limit
     */
    public function generate(
        int $appId,
        PrivateKey $privateKey,
        int $expirySeconds = 600
    ): GeneratedJwt {
        return $this->generator->generate($appId, $privateKey, $expirySeconds);
    }
}
