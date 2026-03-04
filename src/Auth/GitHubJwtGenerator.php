<?php

declare(strict_types=1);

namespace Horde\Components\Auth;

use Horde\Core\Auth\Jwt\GeneratedJwt as CoreGeneratedJwt;
use Horde\Core\Auth\Jwt\PrivateKey as CorePrivateKey;
use Horde\Core\Auth\Jwt\Rs256Generator;
use RuntimeException;

/**
 * GitHub-specific JWT generator wrapper
 *
 * Wraps the Core Rs256Generator with GitHub App constraints (max 600 second expiry).
 * This maintains GitHub-specific business logic while using the shared JWT implementation.
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
    private Rs256Generator $generator;

    public function __construct()
    {
        $this->generator = new Rs256Generator();
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
        if ($expirySeconds > 600) {
            throw new RuntimeException(
                'GitHub App JWTs cannot have expiry greater than 600 seconds (10 minutes)'
            );
        }

        // Convert components types to Core types
        $corePrivateKey = CorePrivateKey::fromString($privateKey->content);

        // Generate JWT using Core implementation
        $coreJwt = $this->generator->generate($appId, $corePrivateKey, $expirySeconds);

        // Convert Core result back to components type
        return new GeneratedJwt($coreJwt->token, $coreJwt->expiresAt);
    }
}
