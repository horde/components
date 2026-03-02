<?php

declare(strict_types=1);

namespace Horde\Components\Test\Unit\ConfigProvider;

use Horde\Components\ConfigProvider\BuiltinConfigProvider;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Exception;

/**
 * Tests for BuiltinConfigProvider
 *
 * Copyright 2026 The Horde Project (http://www.horde.org/)
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
#[CoversClass(BuiltinConfigProvider::class)]
class BuiltinConfigProviderTest extends TestCase
{
    public function testHasSettingReturnsTrueForExistingKey(): void
    {
        $provider = new BuiltinConfigProvider([
            'key1' => 'value1',
            'key2' => 'value2',
        ]);

        $this->assertTrue($provider->hasSetting('key1'));
        $this->assertTrue($provider->hasSetting('key2'));
    }

    public function testHasSettingReturnsFalseForNonExistingKey(): void
    {
        $provider = new BuiltinConfigProvider([
            'key1' => 'value1',
        ]);

        $this->assertFalse($provider->hasSetting('nonexistent'));
        $this->assertFalse($provider->hasSetting(''));
    }

    public function testGetSettingReturnsCorrectValue(): void
    {
        $provider = new BuiltinConfigProvider([
            'checkout.dir' => '/path/to/checkout',
            'repo.org' => 'horde',
        ]);

        $this->assertSame('/path/to/checkout', $provider->getSetting('checkout.dir'));
        $this->assertSame('horde', $provider->getSetting('repo.org'));
    }

    public function testGetSettingThrowsExceptionForNonExistingKey(): void
    {
        $provider = new BuiltinConfigProvider(['key1' => 'value1']);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Setting 'nonexistent' not found in BuiltinConfigProvider");

        $provider->getSetting('nonexistent');
    }

    public function testIsUnsetAlwaysReturnsFalse(): void
    {
        $provider = new BuiltinConfigProvider([
            'key1' => 'value1',
        ]);

        // Builtin provider cannot unset values - it's the lowest layer
        $this->assertFalse($provider->isUnset('key1'));
        $this->assertFalse($provider->isUnset('nonexistent'));
        $this->assertFalse($provider->isUnset(''));
    }

    public function testGetAvailableKeysReturnsAllKeys(): void
    {
        $provider = new BuiltinConfigProvider([
            'checkout.dir' => '/path',
            'repo.org' => 'horde',
            'scm.domain' => 'https://github.com',
        ]);

        $keys = $provider->getAvailableKeys();

        $this->assertCount(3, $keys);
        $this->assertContains('checkout.dir', $keys);
        $this->assertContains('repo.org', $keys);
        $this->assertContains('scm.domain', $keys);
    }

    public function testGetAvailableKeysReturnsEmptyArrayWhenNoSettings(): void
    {
        $provider = new BuiltinConfigProvider([]);

        $this->assertSame([], $provider->getAvailableKeys());
    }

    public function testIsAvailableAlwaysReturnsTrue(): void
    {
        $provider = new BuiltinConfigProvider([]);
        $this->assertTrue($provider->isAvailable());

        $providerWithData = new BuiltinConfigProvider(['key' => 'value']);
        $this->assertTrue($providerWithData->isAvailable());
    }

    public function testDumpSettingsReturnsAllSettings(): void
    {
        $settings = [
            'checkout.dir' => '/path',
            'repo.org' => 'horde',
        ];
        $provider = new BuiltinConfigProvider($settings);

        $this->assertSame($settings, $provider->dumpSettings());
    }

    public function testEmptyConstructor(): void
    {
        $provider = new BuiltinConfigProvider();

        $this->assertFalse($provider->hasSetting('anything'));
        $this->assertSame([], $provider->getAvailableKeys());
        $this->assertTrue($provider->isAvailable());
    }

    public function testHandlesEmptyStringKey(): void
    {
        $provider = new BuiltinConfigProvider(['key' => 'value']);

        $this->assertFalse($provider->hasSetting(''));
        $this->assertFalse($provider->isUnset(''));
    }

    public function testHandlesEmptyStringValue(): void
    {
        $provider = new BuiltinConfigProvider(['empty' => '']);

        $this->assertTrue($provider->hasSetting('empty'));
        $this->assertSame('', $provider->getSetting('empty'));
    }
}
