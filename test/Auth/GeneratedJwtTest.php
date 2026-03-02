<?php

declare(strict_types=1);

namespace Horde\Components\Test\Auth;

use Horde\Components\Auth\GeneratedJwt;
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
#[CoversClass(GeneratedJwt::class)]
class GeneratedJwtTest extends TestCase
{
    public function testConstructorSetsProperties(): void
    {
        $token = 'eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOjEyMzQ1fQ.signature';
        $expiresAt = time() + 600;

        $jwt = new GeneratedJwt($token, $expiresAt);

        $this->assertSame($token, $jwt->token);
        $this->assertSame($expiresAt, $jwt->expiresAt);
    }

    public function testIsExpiredReturnsFalseForFutureExpiry(): void
    {
        $expiresAt = time() + 300; // 5 minutes from now
        $jwt = new GeneratedJwt('token', $expiresAt);

        $this->assertFalse($jwt->isExpired());
    }

    public function testIsExpiredReturnsTrueForPastExpiry(): void
    {
        $expiresAt = time() - 100; // 100 seconds ago
        $jwt = new GeneratedJwt('token', $expiresAt);

        $this->assertTrue($jwt->isExpired());
    }

    public function testIsExpiredReturnsTrueForCurrentTime(): void
    {
        $expiresAt = time();
        $jwt = new GeneratedJwt('token', $expiresAt);

        $this->assertTrue($jwt->isExpired());
    }

    public function testGetSecondsUntilExpirationWithFutureExpiry(): void
    {
        $expiresAt = time() + 420; // 7 minutes from now
        $jwt = new GeneratedJwt('token', $expiresAt);

        $remaining = $jwt->getSecondsUntilExpiration();

        // Allow 1 second tolerance for test execution time
        $this->assertGreaterThanOrEqual(419, $remaining);
        $this->assertLessThanOrEqual(420, $remaining);
    }

    public function testGetSecondsUntilExpirationWithPastExpiry(): void
    {
        $expiresAt = time() - 50; // 50 seconds ago
        $jwt = new GeneratedJwt('token', $expiresAt);

        $remaining = $jwt->getSecondsUntilExpiration();

        // Should return negative value
        $this->assertLessThanOrEqual(-49, $remaining);
        $this->assertGreaterThanOrEqual(-50, $remaining);
    }

    public function testPropertiesAreReadonly(): void
    {
        $jwt = new GeneratedJwt('token', time() + 600);

        $reflection = new \ReflectionClass($jwt);

        $tokenProperty = $reflection->getProperty('token');
        $this->assertTrue($tokenProperty->isReadOnly());

        $expiresAtProperty = $reflection->getProperty('expiresAt');
        $this->assertTrue($expiresAtProperty->isReadOnly());
    }
}
