<?php

declare(strict_types=1);

namespace Horde\Components\Auth;

/**
 * Interface for JWT generation
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
interface JwtGeneratorInterface
{
    /**
     * Generate a JWT for GitHub App authentication
     *
     * @param int $appId GitHub App ID (iss claim)
     * @param PrivateKey $privateKey Private key for signing
     * @param int $expirySeconds JWT expiry time in seconds (max 600 for GitHub)
     * @return GeneratedJwt
     * @throws \RuntimeException If JWT generation fails
     */
    public function generate(
        int $appId,
        PrivateKey $privateKey,
        int $expirySeconds = 600
    ): GeneratedJwt;
}
