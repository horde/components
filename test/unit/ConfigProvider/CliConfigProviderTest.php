<?php

declare(strict_types=1);

namespace Horde\Components\Test\Unit\ConfigProvider;

use Horde\Components\ConfigProvider\CliConfigProvider;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Exception;

/**
 * Tests for CliConfigProvider
 *
 * Copyright 2026 The Horde Project (http://www.horde.org/)
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
#[CoversClass(CliConfigProvider::class)]
class CliConfigProviderTest extends TestCase
{
    public function testConstructorWithEmptyOptions(): void
    {
        $provider = new CliConfigProvider([]);

        $this->assertFalse($provider->hasSetting('anything'));
        $this->assertSame([], $provider->getAvailableKeys());
    }

    public function testConstructorParsesOptions(): void
    {
        $provider = new CliConfigProvider([
            'github-token' => 'ghp_test123',
            'checkout-dir' => '/path/to/checkout',
        ]);

        // Keys are normalized from kebab-case to dot notation
        $this->assertTrue($provider->hasSetting('github.token'));
        $this->assertTrue($provider->hasSetting('checkout.dir'));
        $this->assertSame('ghp_test123', $provider->getSetting('github.token'));
        $this->assertSame('/path/to/checkout', $provider->getSetting('checkout.dir'));
    }

    public function testConstructorFiltersNullValues(): void
    {
        $provider = new CliConfigProvider([
            'key1' => 'value1',
            'key2' => null,
            'key3' => 'value3',
        ]);

        $this->assertTrue($provider->hasSetting('key1'));
        $this->assertFalse($provider->hasSetting('key2')); // null filtered out
        $this->assertTrue($provider->hasSetting('key3'));
    }

    public function testConstructorFiltersFalseValues(): void
    {
        $provider = new CliConfigProvider([
            'key1' => 'value1',
            'key2' => false,
            'key3' => 'value3',
        ]);

        $this->assertTrue($provider->hasSetting('key1'));
        $this->assertFalse($provider->hasSetting('key2')); // false filtered out
        $this->assertTrue($provider->hasSetting('key3'));
    }

    public function testConstructorConvertsValuesToStrings(): void
    {
        $provider = new CliConfigProvider([
            'number' => 123,
            'boolean' => true,
        ]);

        $this->assertSame('123', $provider->getSetting('number'));
        $this->assertSame('1', $provider->getSetting('boolean')); // true -> '1'
    }

    public function testNormalizesKebabCaseToDotNotation(): void
    {
        $provider = new CliConfigProvider([
            'github-token' => 'ghp_test',
            'repo-org' => 'horde',
            'scm-domain' => 'https://github.com',
        ]);

        $this->assertTrue($provider->hasSetting('github.token'));
        $this->assertTrue($provider->hasSetting('repo.org'));
        $this->assertTrue($provider->hasSetting('scm.domain'));
    }

    public function testHandlesAlreadyDotNotationKeys(): void
    {
        $provider = new CliConfigProvider([
            'github.token' => 'ghp_test',
            'checkout.dir' => '/path',
        ]);

        $this->assertTrue($provider->hasSetting('github.token'));
        $this->assertTrue($provider->hasSetting('checkout.dir'));
    }

    public function testHasSettingReturnsFalseForNonExistingKey(): void
    {
        $provider = new CliConfigProvider(['key' => 'value']);

        $this->assertFalse($provider->hasSetting('nonexistent'));
    }

    public function testGetSettingThrowsExceptionForNonExistingKey(): void
    {
        $provider = new CliConfigProvider(['key' => 'value']);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Setting 'nonexistent' not found in CliConfigProvider");

        $provider->getSetting('nonexistent');
    }

    public function testIsUnsetAlwaysReturnsFalse(): void
    {
        $provider = new CliConfigProvider([
            'key1' => 'value1',
        ]);

        // CLI args cannot explicitly unset values
        $this->assertFalse($provider->isUnset('key1'));
        $this->assertFalse($provider->isUnset('nonexistent'));
    }

    public function testGetAvailableKeysReturnsNormalizedKeys(): void
    {
        $provider = new CliConfigProvider([
            'github-token' => 'ghp_test',
            'checkout-dir' => '/path',
            'repo.org' => 'horde',
        ]);

        $keys = $provider->getAvailableKeys();

        $this->assertCount(3, $keys);
        $this->assertContains('github.token', $keys);
        $this->assertContains('checkout.dir', $keys);
        $this->assertContains('repo.org', $keys);
    }

    public function testIsAvailableAlwaysReturnsTrue(): void
    {
        $emptyProvider = new CliConfigProvider([]);
        $this->assertTrue($emptyProvider->isAvailable());

        $providerWithData = new CliConfigProvider(['key' => 'value']);
        $this->assertTrue($providerWithData->isAvailable());
    }

    public function testHandlesEmptyStringValue(): void
    {
        $provider = new CliConfigProvider(['empty' => '']);

        $this->assertTrue($provider->hasSetting('empty'));
        $this->assertSame('', $provider->getSetting('empty'));
    }

    public function testHandlesZeroValue(): void
    {
        $provider = new CliConfigProvider(['zero' => 0]);

        $this->assertTrue($provider->hasSetting('zero'));
        $this->assertSame('0', $provider->getSetting('zero'));
    }

    public function testHandlesMultipleDashesInKey(): void
    {
        $provider = new CliConfigProvider([
            'some-long-key-name' => 'value',
        ]);

        // Multiple dashes converted to dots
        $this->assertTrue($provider->hasSetting('some.long.key.name'));
        $this->assertSame('value', $provider->getSetting('some.long.key.name'));
    }

    public function testHandlesSpecialCharactersInValue(): void
    {
        $provider = new CliConfigProvider([
            'url' => 'https://example.com/path?query=value&foo=bar',
            'token' => 'ghp_abc123!@#$%',
        ]);

        $this->assertSame('https://example.com/path?query=value&foo=bar', $provider->getSetting('url'));
        $this->assertSame('ghp_abc123!@#$%', $provider->getSetting('token'));
    }
}
