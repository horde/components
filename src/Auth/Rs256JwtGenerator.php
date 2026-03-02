<?php

declare(strict_types=1);

namespace Horde\Components\Auth;

use RuntimeException;

/**
 * JWT Generator using RS256 algorithm for GitHub App authentication
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
class Rs256JwtGenerator implements JwtGeneratorInterface
{
    /**
     * Generate a JWT for GitHub App authentication
     *
     * @param int $appId GitHub App ID (iss claim)
     * @param PrivateKey $privateKey Private key for signing
     * @param int $expirySeconds JWT expiry time in seconds (max 600 for GitHub)
     * @return GeneratedJwt
     * @throws RuntimeException If JWT generation fails
     */
    public function generate(
        int $appId,
        PrivateKey $privateKey,
        int $expirySeconds = 600
    ): GeneratedJwt {
        if ($expirySeconds <= 0) {
            throw new RuntimeException('Expiry seconds must be positive');
        }

        if ($expirySeconds > 600) {
            throw new RuntimeException('GitHub App JWTs cannot have expiry greater than 600 seconds (10 minutes)');
        }

        $now = time();
        $expiresAt = $now + $expirySeconds;

        // Build JWT header
        $header = [
            'alg' => 'RS256',
            'typ' => 'JWT',
        ];

        // Build JWT payload with GitHub App claims
        $payload = [
            'iss' => $appId,
            'iat' => $now,
            'exp' => $expiresAt,
        ];

        // Encode header and payload
        $headerEncoded = $this->base64UrlEncode(json_encode($header, JSON_THROW_ON_ERROR));
        $payloadEncoded = $this->base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));

        // Create signature base
        $signatureBase = "{$headerEncoded}.{$payloadEncoded}";

        // Sign with private key
        $signature = $this->sign($signatureBase, $privateKey);

        // Construct JWT
        $jwt = "{$signatureBase}.{$signature}";

        return new GeneratedJwt($jwt, $expiresAt);
    }

    /**
     * Base64 URL-safe encoding
     *
     * @param string $data Data to encode
     * @return string Base64 URL-encoded string
     */
    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Sign data with RS256 algorithm
     *
     * @param string $data Data to sign
     * @param PrivateKey $privateKey Private key for signing
     * @return string Base64 URL-encoded signature
     * @throws RuntimeException If signing fails
     */
    private function sign(string $data, PrivateKey $privateKey): string
    {
        $keyResource = $privateKey->getResource();

        $signature = '';
        $success = openssl_sign($data, $signature, $keyResource, OPENSSL_ALGO_SHA256);

        if (!$success) {
            throw new RuntimeException('Failed to sign JWT: ' . openssl_error_string());
        }

        return $this->base64UrlEncode($signature);
    }
}
