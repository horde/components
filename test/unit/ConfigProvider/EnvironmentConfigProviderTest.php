<?php

declare(strict_types=1);

namespace Horde\Components\Test\Unit\ConfigProvider;

use Horde\Components\ConfigProvider\EnvironmentConfigProvider;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Exception;

/**
 * Tests for EnvironmentConfigProvider
 *
 * Copyright 2026 The Horde Project (http://www.horde.org/)
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
#[CoversClass(EnvironmentConfigProvider::class)]
class EnvironmentConfigProviderTest extends TestCase
{
    public function testHasSettingReturnsTrueForExistingKey(): void
    {
        $provider = new EnvironmentConfigProvider([
            'GITHUB_TOKEN' => 'ghp_test123',
            'HOME' => '/home/user',
        ]);

        $this->assertTrue($provider->hasSetting('GITHUB_TOKEN'));
        $this->assertTrue($provider->hasSetting('HOME'));
    }

    public function testHasSettingReturnsFalseForNonExistingKey(): void
    {
        $provider = new EnvironmentConfigProvider([
            'HOME' => '/home/user',
        ]);

        $this->assertFalse($provider->hasSetting('NONEXISTENT'));
        $this->assertFalse($provider->hasSetting('GITHUB_TOKEN'));
    }

    public function testHasSettingReturnsFalseForUnsetMarker(): void
    {
        $provider = new EnvironmentConfigProvider([
            'KEY1' => 'value1',
            'KEY2' => '__UNSET__',
        ]);

        $this->assertTrue($provider->hasSetting('KEY1'));
        $this->assertFalse($provider->hasSetting('KEY2')); // Unset marker
    }

    public function testGetSettingReturnsCorrectValue(): void
    {
        $provider = new EnvironmentConfigProvider([
            'GITHUB_TOKEN' => 'ghp_test123',
            'HOME' => '/home/user',
        ]);

        $this->assertSame('ghp_test123', $provider->getSetting('GITHUB_TOKEN'));
        $this->assertSame('/home/user', $provider->getSetting('HOME'));
    }

    public function testGetSettingThrowsExceptionForNonExistingKey(): void
    {
        $provider = new EnvironmentConfigProvider(['KEY' => 'value']);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Setting 'NONEXISTENT' not found in EnvironmentConfigProvider");

        $provider->getSetting('NONEXISTENT');
    }

    public function testGetSettingThrowsExceptionForUnsetMarker(): void
    {
        $provider = new EnvironmentConfigProvider([
            'KEY' => '__UNSET__',
        ]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Setting 'KEY' not found in EnvironmentConfigProvider");

        $provider->getSetting('KEY');
    }

    public function testIsUnsetReturnsTrueForUnsetMarker(): void
    {
        $provider = new EnvironmentConfigProvider([
            'KEY1' => 'value1',
            'KEY2' => '__UNSET__',
        ]);

        $this->assertFalse($provider->isUnset('KEY1'));
        $this->assertTrue($provider->isUnset('KEY2'));
    }

    public function testIsUnsetReturnsFalseForNonExistingKey(): void
    {
        $provider = new EnvironmentConfigProvider(['KEY' => 'value']);

        $this->assertFalse($provider->isUnset('NONEXISTENT'));
    }

    public function testGetAvailableKeysReturnsAllKeys(): void
    {
        $provider = new EnvironmentConfigProvider([
            'GITHUB_TOKEN' => 'ghp_test',
            'HOME' => '/home/user',
            'UNSET_KEY' => '__UNSET__',
        ]);

        $keys = $provider->getAvailableKeys();

        $this->assertCount(3, $keys);
        $this->assertContains('GITHUB_TOKEN', $keys);
        $this->assertContains('HOME', $keys);
        $this->assertContains('UNSET_KEY', $keys); // Includes unset keys
    }

    public function testGetAvailableKeysReturnsEmptyArrayWhenNoSettings(): void
    {
        $provider = new EnvironmentConfigProvider([]);

        $this->assertSame([], $provider->getAvailableKeys());
    }

    public function testIsAvailableAlwaysReturnsTrue(): void
    {
        $provider = new EnvironmentConfigProvider([]);
        $this->assertTrue($provider->isAvailable());

        $providerWithData = new EnvironmentConfigProvider(['KEY' => 'value']);
        $this->assertTrue($providerWithData->isAvailable());
    }

    public function testEmptyEnvironment(): void
    {
        $provider = new EnvironmentConfigProvider([]);

        $this->assertFalse($provider->hasSetting('anything'));
        $this->assertSame([], $provider->getAvailableKeys());
        $this->assertTrue($provider->isAvailable());
    }

    public function testHandlesEmptyStringValue(): void
    {
        $provider = new EnvironmentConfigProvider(['EMPTY' => '']);

        $this->assertTrue($provider->hasSetting('EMPTY'));
        $this->assertSame('', $provider->getSetting('EMPTY'));
    }

    public function testMultipleUnsetMarkers(): void
    {
        $provider = new EnvironmentConfigProvider([
            'KEY1' => '__UNSET__',
            'KEY2' => '__UNSET__',
            'KEY3' => 'value3',
        ]);

        $this->assertTrue($provider->isUnset('KEY1'));
        $this->assertTrue($provider->isUnset('KEY2'));
        $this->assertFalse($provider->isUnset('KEY3'));
        $this->assertTrue($provider->hasSetting('KEY3'));
    }

    public function testUnsetMarkerIsCaseSensitive(): void
    {
        $provider = new EnvironmentConfigProvider([
            'KEY1' => '__UNSET__',
            'KEY2' => '__unset__',
            'KEY3' => 'UNSET',
        ]);

        $this->assertTrue($provider->isUnset('KEY1'));
        $this->assertFalse($provider->isUnset('KEY2')); // Wrong case
        $this->assertFalse($provider->isUnset('KEY3')); // Not the marker
        $this->assertTrue($provider->hasSetting('KEY2'));
        $this->assertTrue($provider->hasSetting('KEY3'));
    }
}
