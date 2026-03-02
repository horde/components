<?php

declare(strict_types=1);

namespace Horde\Components\Test\Unit\ConfigProvider;

use Horde\Components\ConfigProvider\EffectiveConfigProvider;
use Horde\Components\ConfigProvider\ConfigProvider;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Exception;

/**
 * Tests for EffectiveConfigProvider
 *
 * Copyright 2026 The Horde Project (http://www.horde.org/)
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
#[CoversClass(EffectiveConfigProvider::class)]
class EffectiveConfigProviderTest extends TestCase
{
    public function testConstructorWithNoProviders(): void
    {
        $provider = new EffectiveConfigProvider();

        $this->assertFalse($provider->hasSetting('anything'));
        $this->assertFalse($provider->isAvailable());
    }

    public function testFirstProviderWins(): void
    {
        $provider1 = $this->createMockProvider([
            'key1' => 'value_from_provider1',
        ]);
        $provider2 = $this->createMockProvider([
            'key1' => 'value_from_provider2',
        ]);

        $effective = new EffectiveConfigProvider($provider1, $provider2);

        $this->assertSame('value_from_provider1', $effective->getSetting('key1'));
    }

    public function testCascadesToLowerPrecedenceProvider(): void
    {
        $provider1 = $this->createMockProvider([
            'key1' => 'value1',
        ]);
        $provider2 = $this->createMockProvider([
            'key2' => 'value2',
        ]);

        $effective = new EffectiveConfigProvider($provider1, $provider2);

        $this->assertSame('value1', $effective->getSetting('key1')); // From provider1
        $this->assertSame('value2', $effective->getSetting('key2')); // From provider2
    }

    public function testUnsetBlocksCascade(): void
    {
        $provider1 = $this->createMockProvider(
            values: [],
            unsetKeys: ['key1']  // Explicitly unset
        );
        $provider2 = $this->createMockProvider([
            'key1' => 'value_from_provider2',
        ]);

        $effective = new EffectiveConfigProvider($provider1, $provider2);

        $this->assertFalse($effective->hasSetting('key1')); // Blocked by unset
        $this->assertTrue($effective->isUnset('key1'));
    }

    public function testUnsetThrowsExceptionOnGetSetting(): void
    {
        $provider1 = $this->createMockProvider(
            values: [],
            unsetKeys: ['key1']
        );

        $effective = new EffectiveConfigProvider($provider1);

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches("/Setting 'key1' is explicitly undefined/");

        $effective->getSetting('key1');
    }

    public function testUnavailableProvidersAreSkipped(): void
    {
        $unavailableProvider = $this->createMockProvider(
            values: ['key1' => 'value_unavailable'],
            unsetKeys: [],
            available: false
        );
        $availableProvider = $this->createMockProvider([
            'key1' => 'value_available',
        ]);

        $effective = new EffectiveConfigProvider($unavailableProvider, $availableProvider);

        // Should skip unavailable and use available
        $this->assertSame('value_available', $effective->getSetting('key1'));
    }

    public function testHasSettingWithMultipleProviders(): void
    {
        $provider1 = $this->createMockProvider(['key1' => 'value1']);
        $provider2 = $this->createMockProvider(['key2' => 'value2']);
        $provider3 = $this->createMockProvider(['key3' => 'value3']);

        $effective = new EffectiveConfigProvider($provider1, $provider2, $provider3);

        $this->assertTrue($effective->hasSetting('key1'));
        $this->assertTrue($effective->hasSetting('key2'));
        $this->assertTrue($effective->hasSetting('key3'));
        $this->assertFalse($effective->hasSetting('nonexistent'));
    }

    public function testGetSettingThrowsExceptionForNonExistingKey(): void
    {
        $provider = $this->createMockProvider(['key1' => 'value1']);
        $effective = new EffectiveConfigProvider($provider);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Setting 'nonexistent' not found in any provider");

        $effective->getSetting('nonexistent');
    }

    public function testGetAvailableKeysFromMultipleProviders(): void
    {
        $provider1 = $this->createMockProvider(['key1' => 'v1', 'key2' => 'v2']);
        $provider2 = $this->createMockProvider(['key3' => 'v3']);
        $provider3 = $this->createMockProvider(['key4' => 'v4']);

        $effective = new EffectiveConfigProvider($provider1, $provider2, $provider3);

        $keys = $effective->getAvailableKeys();

        $this->assertCount(4, $keys);
        $this->assertContains('key1', $keys);
        $this->assertContains('key2', $keys);
        $this->assertContains('key3', $keys);
        $this->assertContains('key4', $keys);
    }

    public function testGetAvailableKeysDeduplicates(): void
    {
        $provider1 = $this->createMockProvider(['key1' => 'from_p1']);
        $provider2 = $this->createMockProvider(['key1' => 'from_p2']); // Same key

        $effective = new EffectiveConfigProvider($provider1, $provider2);

        $keys = $effective->getAvailableKeys();

        $this->assertCount(1, $keys); // Deduplicated
        $this->assertContains('key1', $keys);
    }

    public function testIsAvailableReturnsTrueIfAnyProviderAvailable(): void
    {
        $unavailableProvider = $this->createMockProvider([], [], false);
        $availableProvider = $this->createMockProvider(['key' => 'value']);

        $effective = new EffectiveConfigProvider($unavailableProvider, $availableProvider);

        $this->assertTrue($effective->isAvailable());
    }

    public function testIsAvailableReturnsFalseIfAllProvidersUnavailable(): void
    {
        $provider1 = $this->createMockProvider([], [], false);
        $provider2 = $this->createMockProvider([], [], false);

        $effective = new EffectiveConfigProvider($provider1, $provider2);

        $this->assertFalse($effective->isAvailable());
    }

    public function testGetProvidingLayerReturnsCorrectProvider(): void
    {
        $provider1 = $this->createMockProvider(['key1' => 'value1']);
        $provider2 = $this->createMockProvider(['key2' => 'value2']);

        $effective = new EffectiveConfigProvider($provider1, $provider2);

        $this->assertSame($provider1, $effective->getProvidingLayer('key1'));
        $this->assertSame($provider2, $effective->getProvidingLayer('key2'));
    }

    public function testGetProvidingLayerReturnsNullForNonExisting(): void
    {
        $provider = $this->createMockProvider(['key1' => 'value1']);
        $effective = new EffectiveConfigProvider($provider);

        $this->assertNull($effective->getProvidingLayer('nonexistent'));
    }

    public function testGetProvidingLayerReturnsNullForUnset(): void
    {
        $provider = $this->createMockProvider(
            values: [],
            unsetKeys: ['key1']
        );
        $effective = new EffectiveConfigProvider($provider);

        $this->assertNull($effective->getProvidingLayer('key1'));
    }

    public function testGetProvidingLayerNameReturnsClassName(): void
    {
        $provider = $this->createMockProvider(['key1' => 'value1']);
        $effective = new EffectiveConfigProvider($provider);

        $name = $effective->getProvidingLayerName('key1');

        // Should be the mock class name (something like Mock_ConfigProvider_...)
        $this->assertIsString($name);
        $this->assertNotEmpty($name);
    }

    public function testGetProvidingLayerNameReturnsNullForNonExisting(): void
    {
        $provider = $this->createMockProvider(['key1' => 'value1']);
        $effective = new EffectiveConfigProvider($provider);

        $this->assertNull($effective->getProvidingLayerName('nonexistent'));
    }

    public function testGetDiagnosticsForExistingKey(): void
    {
        $provider = $this->createMockProvider(['key1' => 'value1']);
        $effective = new EffectiveConfigProvider($provider);

        $diag = $effective->getDiagnostics('key1');

        $this->assertSame('key1', $diag['key']);
        $this->assertTrue($diag['exists']);
        $this->assertFalse($diag['is_unset']);
        $this->assertSame('value1', $diag['value']);
        $this->assertIsString($diag['providing_layer']);
        $this->assertIsArray($diag['checked_layers']);
        $this->assertCount(1, $diag['checked_layers']);
    }

    public function testGetDiagnosticsForNonExistingKey(): void
    {
        $provider = $this->createMockProvider(['key1' => 'value1']);
        $effective = new EffectiveConfigProvider($provider);

        $diag = $effective->getDiagnostics('nonexistent');

        $this->assertSame('nonexistent', $diag['key']);
        $this->assertFalse($diag['exists']);
        $this->assertNull($diag['value']);
        $this->assertNull($diag['providing_layer']);
    }

    public function testGetDiagnosticsForUnsetKey(): void
    {
        $provider = $this->createMockProvider(
            values: [],
            unsetKeys: ['key1']
        );
        $effective = new EffectiveConfigProvider($provider);

        $diag = $effective->getDiagnostics('key1');

        $this->assertSame('key1', $diag['key']);
        $this->assertFalse($diag['exists']);
        $this->assertTrue($diag['is_unset']);
    }

    public function testComplexPrecedenceScenario(): void
    {
        // Simulate: CLI > Env > User > Builtin
        $cli = $this->createMockProvider(['key1' => 'from_cli']);
        $env = $this->createMockProvider(['key2' => 'from_env']);
        $user = $this->createMockProvider(['key3' => 'from_user']);
        $builtin = $this->createMockProvider(['key4' => 'from_builtin']);

        $effective = new EffectiveConfigProvider($cli, $env, $user, $builtin);

        $this->assertSame('from_cli', $effective->getSetting('key1'));
        $this->assertSame('from_env', $effective->getSetting('key2'));
        $this->assertSame('from_user', $effective->getSetting('key3'));
        $this->assertSame('from_builtin', $effective->getSetting('key4'));
    }

    public function testUnsetInMiddleLayerBlocksLowerLayers(): void
    {
        $high = $this->createMockProvider([]);
        $middle = $this->createMockProvider(
            values: [],
            unsetKeys: ['key1']  // Unset here
        );
        $low = $this->createMockProvider(['key1' => 'from_low']);

        $effective = new EffectiveConfigProvider($high, $middle, $low);

        $this->assertFalse($effective->hasSetting('key1'));
        $this->assertTrue($effective->isUnset('key1'));
    }

    public function testIsUnsetReturnsFalseWhenKeyHasValue(): void
    {
        $provider = $this->createMockProvider(['key1' => 'value1']);
        $effective = new EffectiveConfigProvider($provider);

        $this->assertFalse($effective->isUnset('key1'));
    }

    public function testEmptyStringValue(): void
    {
        $provider = $this->createMockProvider(['empty' => '']);
        $effective = new EffectiveConfigProvider($provider);

        $this->assertTrue($effective->hasSetting('empty'));
        $this->assertSame('', $effective->getSetting('empty'));
    }

    /**
     * Helper to create a mock ConfigProvider
     */
    private function createMockProvider(
        array $values = [],
        array $unsetKeys = [],
        bool $available = true
    ): ConfigProvider {
        $mock = $this->createMock(ConfigProvider::class);

        $mock->method('hasSetting')
            ->willReturnCallback(fn($id) => isset($values[$id]) && !in_array($id, $unsetKeys));

        $mock->method('getSetting')
            ->willReturnCallback(function ($id) use ($values, $unsetKeys) {
                if (!isset($values[$id]) || in_array($id, $unsetKeys)) {
                    throw new Exception("Setting '$id' not found");
                }
                return $values[$id];
            });

        $mock->method('isUnset')
            ->willReturnCallback(fn($id) => in_array($id, $unsetKeys));

        $mock->method('getAvailableKeys')
            ->willReturn(array_keys($values));

        $mock->method('isAvailable')
            ->willReturn($available);

        return $mock;
    }
}
