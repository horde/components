<?php

declare(strict_types=1);

namespace Horde\Components\Test\Auth;

use Horde\Components\Auth\GitHubJwtGenerator;
use Horde\Components\Auth\PrivateKey;
use Horde\Components\Auth\GeneratedJwt;
use RuntimeException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Copyright 2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
#[CoversClass(GitHubJwtGenerator::class)]
class Rs256JwtGeneratorTest extends TestCase
{
    private GitHubJwtGenerator $generator;
    private PrivateKey $privateKey;
    private string $publicKeyPath;

    protected function setUp(): void
    {
        $this->generator = new GitHubJwtGenerator();
        $privateKeyPath = __DIR__ . '/test-private-key.pem';
        $this->publicKeyPath = __DIR__ . '/test-public-key.pem';
        $this->privateKey = PrivateKey::fromFile($privateKeyPath);
    }

    public function testGenerateCreatesValidJwt(): void
    {
        $appId = 123456;
        $jwt = $this->generator->generate($appId, $this->privateKey);

        $this->assertInstanceOf(GeneratedJwt::class, $jwt);
        $this->assertNotEmpty($jwt->token);
        $this->assertGreaterThan(time(), $jwt->expiresAt);
    }

    public function testGeneratedJwtHasCorrectStructure(): void
    {
        $appId = 789012;
        $jwt = $this->generator->generate($appId, $this->privateKey);

        // JWT should have three parts separated by dots
        $parts = explode('.', $jwt->token);
        $this->assertCount(3, $parts);

        [$headerEncoded, $payloadEncoded, $signatureEncoded] = $parts;

        $this->assertNotEmpty($headerEncoded);
        $this->assertNotEmpty($payloadEncoded);
        $this->assertNotEmpty($signatureEncoded);
    }

    public function testGeneratedJwtHasCorrectHeader(): void
    {
        $appId = 111222;
        $jwt = $this->generator->generate($appId, $this->privateKey);

        $parts = explode('.', $jwt->token);
        $headerJson = $this->base64UrlDecode($parts[0]);
        $header = json_decode($headerJson, true);

        $this->assertSame('RS256', $header['alg']);
        $this->assertSame('JWT', $header['typ']);
    }

    public function testGeneratedJwtHasCorrectPayload(): void
    {
        $appId = 333444;
        $expirySeconds = 300;
        $beforeGeneration = time();

        $jwt = $this->generator->generate($appId, $this->privateKey, $expirySeconds);

        $afterGeneration = time();

        $parts = explode('.', $jwt->token);
        $payloadJson = $this->base64UrlDecode($parts[1]);
        $payload = json_decode($payloadJson, true);

        // Check iss (issuer) claim
        $this->assertSame($appId, $payload['iss']);

        // Check iat (issued at) claim - should be within reasonable range
        $this->assertGreaterThanOrEqual($beforeGeneration, $payload['iat']);
        $this->assertLessThanOrEqual($afterGeneration, $payload['iat']);

        // Check exp (expiry) claim
        $expectedExpiry = $payload['iat'] + $expirySeconds;
        $this->assertSame($expectedExpiry, $payload['exp']);
    }

    public function testGeneratedJwtSignatureIsValid(): void
    {
        $appId = 555666;
        $jwt = $this->generator->generate($appId, $this->privateKey);

        $parts = explode('.', $jwt->token);
        $signatureBase = "{$parts[0]}.{$parts[1]}";
        $signature = $this->base64UrlDecode($parts[2]);

        // Verify signature with public key
        $publicKey = openssl_pkey_get_public(file_get_contents($this->publicKeyPath));
        $isValid = openssl_verify($signatureBase, $signature, $publicKey, OPENSSL_ALGO_SHA256);

        $this->assertSame(1, $isValid, 'JWT signature should be valid');
    }

    public function testGenerateWithCustomExpirySeconds(): void
    {
        $appId = 777888;
        $expirySeconds = 180; // 3 minutes
        $beforeGeneration = time();

        $jwt = $this->generator->generate($appId, $this->privateKey, $expirySeconds);

        $expectedExpiry = $beforeGeneration + $expirySeconds;

        // Allow 2 seconds tolerance for test execution
        $this->assertGreaterThanOrEqual($expectedExpiry - 1, $jwt->expiresAt);
        $this->assertLessThanOrEqual($expectedExpiry + 1, $jwt->expiresAt);
    }

    public function testGenerateWithMaximumExpiry(): void
    {
        $appId = 999000;
        $expirySeconds = 600; // GitHub's maximum: 10 minutes

        $jwt = $this->generator->generate($appId, $this->privateKey, $expirySeconds);

        $this->assertInstanceOf(GeneratedJwt::class, $jwt);
        $this->assertFalse($jwt->isExpired());
    }

    public function testGenerateThrowsOnExpiryGreaterThan600Seconds(): void
    {
        $appId = 111111;
        $expirySeconds = 601; // Over GitHub's maximum

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cannot have expiry greater than 600 seconds');

        $this->generator->generate($appId, $this->privateKey, $expirySeconds);
    }

    public function testGenerateThrowsOnZeroExpiry(): void
    {
        $appId = 222222;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Expiry seconds must be positive');

        $this->generator->generate($appId, $this->privateKey, 0);
    }

    public function testGenerateThrowsOnNegativeExpiry(): void
    {
        $appId = 333333;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Expiry seconds must be positive');

        $this->generator->generate($appId, $this->privateKey, -100);
    }

    public function testGenerateDefaultExpiryIs600Seconds(): void
    {
        $appId = 444444;
        $beforeGeneration = time();

        $jwt = $this->generator->generate($appId, $this->privateKey);

        $parts = explode('.', $jwt->token);
        $payloadJson = $this->base64UrlDecode($parts[1]);
        $payload = json_decode($payloadJson, true);

        $actualExpiry = $payload['exp'] - $payload['iat'];
        $this->assertSame(600, $actualExpiry);
    }

    public function testMultipleGenerationsProduceDifferentTokens(): void
    {
        $appId = 555555;

        $jwt1 = $this->generator->generate($appId, $this->privateKey);
        sleep(1); // Ensure different iat timestamp
        $jwt2 = $this->generator->generate($appId, $this->privateKey);

        $this->assertNotSame($jwt1->token, $jwt2->token);
    }

    public function testGeneratedJwtCanBeUsedImmediately(): void
    {
        $appId = 666666;
        $jwt = $this->generator->generate($appId, $this->privateKey);

        $this->assertFalse($jwt->isExpired());
        $this->assertGreaterThan(0, $jwt->getSecondsUntilExpiration());
    }

    /**
     * Base64 URL-safe decode helper
     */
    private function base64UrlDecode(string $data): string
    {
        $padded = str_pad($data, strlen($data) % 4, '=', STR_PAD_RIGHT);
        return base64_decode(strtr($padded, '-_', '+/'));
    }
}
