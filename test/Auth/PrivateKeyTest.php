<?php

declare(strict_types=1);

namespace Horde\Components\Test\Auth;

use Horde\Components\Auth\PrivateKey;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use OpenSSLAsymmetricKey;
use ReflectionClass;

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
#[CoversClass(PrivateKey::class)]
class PrivateKeyTest extends TestCase
{
    private string $testKeyPath;
    private string $testKeyContent;

    protected function setUp(): void
    {
        $this->testKeyPath = __DIR__ . '/test-private-key.pem';
        $this->testKeyContent = file_get_contents($this->testKeyPath);
    }

    public function testFromStringWithValidKey(): void
    {
        $key = PrivateKey::fromString($this->testKeyContent);

        $this->assertInstanceOf(PrivateKey::class, $key);
        $this->assertSame($this->testKeyContent, $key->content);
    }

    public function testFromStringThrowsOnEmptyContent(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Private key content cannot be empty');

        PrivateKey::fromString('');
    }

    public function testFromStringThrowsOnWhitespaceOnly(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Private key content cannot be empty');

        PrivateKey::fromString("   \n   \t   ");
    }

    public function testFromStringThrowsOnInvalidKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid private key format');

        PrivateKey::fromString('not a valid key');
    }

    public function testFromFileWithValidPath(): void
    {
        $key = PrivateKey::fromFile($this->testKeyPath);

        $this->assertInstanceOf(PrivateKey::class, $key);
        $this->assertSame($this->testKeyContent, $key->content);
    }

    public function testFromFileThrowsOnNonExistentFile(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Private key file not found');

        PrivateKey::fromFile('/nonexistent/path/key.pem');
    }

    public function testFromFileThrowsOnUnreadableFile(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'test_key_');
        file_put_contents($tempFile, $this->testKeyContent);
        chmod($tempFile, 0o000);

        try {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('not readable');

            PrivateKey::fromFile($tempFile);
        } finally {
            chmod($tempFile, 0o644);
            unlink($tempFile);
        }
    }

    public function testGetResourceReturnsValidResource(): void
    {
        $key = PrivateKey::fromString($this->testKeyContent);
        $resource = $key->getResource();

        // In PHP 8+, openssl_pkey_get_private returns OpenSSLAsymmetricKey object
        // In PHP 7, it returns a resource
        $this->assertTrue(
            is_resource($resource) || $resource instanceof OpenSSLAsymmetricKey,
            'Expected OpenSSL key resource or OpenSSLAsymmetricKey object'
        );

        // Verify it's a valid OpenSSL key by checking details
        $details = openssl_pkey_get_details($resource);
        $this->assertIsArray($details);
        $this->assertArrayHasKey('bits', $details);
        $this->assertArrayHasKey('type', $details);
    }

    public function testContentIsReadonly(): void
    {
        $key = PrivateKey::fromString($this->testKeyContent);

        $reflection = new ReflectionClass($key);
        $property = $reflection->getProperty('content');

        $this->assertTrue($property->isReadOnly());
    }
}
