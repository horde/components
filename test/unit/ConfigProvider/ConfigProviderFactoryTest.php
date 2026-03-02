<?php

declare(strict_types=1);

namespace Horde\Components\Test\Unit\ConfigProvider;

use Horde\Components\ConfigProvider\ConfigProviderFactory;
use Horde\Components\ConfigProvider\EnvironmentConfigProvider;
use Horde\Components\ConfigProvider\PhpConfigFileProvider;
use Horde\Components\ConfigProvider\BuiltinConfigProvider;
use Horde\Components\ConfigProvider\CliConfigProvider;
use Horde\Components\ConfigProvider\EffectiveConfigProvider;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for ConfigProviderFactory
 *
 * Copyright 2026 The Horde Project (http://www.horde.org/)
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
#[CoversClass(ConfigProviderFactory::class)]
class ConfigProviderFactoryTest extends TestCase
{
    private EnvironmentConfigProvider $env;
    private PhpConfigFileProvider $userConfig;
    private PhpConfigFileProvider $legacyConfig;
    private BuiltinConfigProvider $builtin;
    private CliConfigProvider $cli;
    private string $tempFile;

    protected function setUp(): void
    {
        $this->env = new EnvironmentConfigProvider(['TEST_ENV' => 'env_value']);

        // Create temp file for user config
        $this->tempFile = sys_get_temp_dir() . '/test_config_' . uniqid() . '.php';
        $this->userConfig = new PhpConfigFileProvider($this->tempFile);
        $this->userConfig->setSetting('user.key', 'user_value');

        // Create temp file for legacy config
        $legacyFile = sys_get_temp_dir() . '/test_legacy_' . uniqid() . '.php';
        $this->legacyConfig = new PhpConfigFileProvider($legacyFile);
        $this->legacyConfig->setSetting('legacy.key', 'legacy_value');

        $this->builtin = new BuiltinConfigProvider(['builtin.key' => 'builtin_value']);
        $this->cli = new CliConfigProvider(['cli-key' => 'cli_value']);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempFile)) {
            unlink($this->tempFile);
        }
    }

    public function testCreateDefaultReturnsEffectiveConfigProvider(): void
    {
        $factory = new ConfigProviderFactory(
            $this->env,
            $this->userConfig,
            $this->legacyConfig,
            $this->builtin,
            $this->cli
        );

        $provider = $factory->createDefault();

        $this->assertInstanceOf(EffectiveConfigProvider::class, $provider);
    }

    public function testCreateDefaultWithNullLegacyConfig(): void
    {
        $factory = new ConfigProviderFactory(
            $this->env,
            $this->userConfig,
            null, // No legacy config
            $this->builtin,
            $this->cli
        );

        $provider = $factory->createDefault();

        $this->assertInstanceOf(EffectiveConfigProvider::class, $provider);
        $this->assertTrue($provider->isAvailable());
    }

    public function testCreateDefaultWithNullCliConfig(): void
    {
        $factory = new ConfigProviderFactory(
            $this->env,
            $this->userConfig,
            $this->legacyConfig,
            $this->builtin,
            null // No CLI config
        );

        $provider = $factory->createDefault();

        $this->assertInstanceOf(EffectiveConfigProvider::class, $provider);
        $this->assertTrue($provider->isAvailable());
    }

    public function testCreateDefaultHasCorrectPrecedence(): void
    {
        // Set same key in multiple providers
        $this->cli = new CliConfigProvider(['test-key' => 'from_cli']);
        $this->env = new EnvironmentConfigProvider(['test.key' => 'from_env']);
        $this->userConfig->setSetting('test.key', 'from_user');
        $this->legacyConfig->setSetting('test.key', 'from_legacy');
        $this->builtin = new BuiltinConfigProvider(['test.key' => 'from_builtin']);

        $factory = new ConfigProviderFactory(
            $this->env,
            $this->userConfig,
            $this->legacyConfig,
            $this->builtin,
            $this->cli
        );

        $provider = $factory->createDefault();

        // CLI should win (highest precedence)
        $this->assertSame('from_cli', $provider->getSetting('test.key'));
    }

    public function testCreateDefaultCascadesToLowerLayers(): void
    {
        $factory = new ConfigProviderFactory(
            $this->env,
            $this->userConfig,
            $this->legacyConfig,
            $this->builtin,
            $this->cli
        );

        $provider = $factory->createDefault();

        // Each layer has unique keys
        $this->assertSame('cli_value', $provider->getSetting('cli.key'));
        $this->assertSame('env_value', $provider->getSetting('TEST_ENV'));
        $this->assertSame('user_value', $provider->getSetting('user.key'));
        $this->assertSame('legacy_value', $provider->getSetting('legacy.key'));
        $this->assertSame('builtin_value', $provider->getSetting('builtin.key'));
    }

    public function testCreateSelectiveWithSingleLayer(): void
    {
        $factory = new ConfigProviderFactory(
            $this->env,
            $this->userConfig,
            $this->legacyConfig,
            $this->builtin,
            $this->cli
        );

        $provider = $factory->createSelective(['builtin']);

        $this->assertInstanceOf(EffectiveConfigProvider::class, $provider);
        $this->assertTrue($provider->hasSetting('builtin.key'));
        $this->assertFalse($provider->hasSetting('cli.key'));
    }

    public function testCreateSelectiveWithMultipleLayers(): void
    {
        $factory = new ConfigProviderFactory(
            $this->env,
            $this->userConfig,
            $this->legacyConfig,
            $this->builtin,
            $this->cli
        );

        $provider = $factory->createSelective(['env', 'builtin']);

        $this->assertTrue($provider->hasSetting('TEST_ENV'));
        $this->assertTrue($provider->hasSetting('builtin.key'));
        $this->assertFalse($provider->hasSetting('cli.key'));
        $this->assertFalse($provider->hasSetting('user.key'));
    }

    public function testCreateSelectiveWithCorrectOrder(): void
    {
        // Set same key in env and builtin
        $this->env = new EnvironmentConfigProvider(['test.key' => 'from_env']);
        $this->builtin = new BuiltinConfigProvider(['test.key' => 'from_builtin']);

        $factory = new ConfigProviderFactory(
            $this->env,
            $this->userConfig,
            $this->legacyConfig,
            $this->builtin,
            $this->cli
        );

        // Order matters: first in list has higher precedence
        $provider = $factory->createSelective(['env', 'builtin']);
        $this->assertSame('from_env', $provider->getSetting('test.key'));

        $provider2 = $factory->createSelective(['builtin', 'env']);
        $this->assertSame('from_builtin', $provider2->getSetting('test.key'));
    }

    public function testCreateSelectiveFiltersInvalidNames(): void
    {
        $factory = new ConfigProviderFactory(
            $this->env,
            $this->userConfig,
            $this->legacyConfig,
            $this->builtin,
            $this->cli
        );

        // Should silently ignore invalid layer names
        $provider = $factory->createSelective(['builtin', 'invalid', 'nonexistent']);

        $this->assertTrue($provider->hasSetting('builtin.key'));
    }

    public function testCreateSelectiveWithNullProvider(): void
    {
        $factory = new ConfigProviderFactory(
            $this->env,
            $this->userConfig,
            null, // Legacy config is null
            $this->builtin,
            $this->cli
        );

        // Requesting null provider should be handled gracefully
        $provider = $factory->createSelective(['legacyConfig', 'builtin']);

        $this->assertTrue($provider->hasSetting('builtin.key'));
    }

    public function testCreateEnvironmentOnly(): void
    {
        $factory = new ConfigProviderFactory(
            $this->env,
            $this->userConfig,
            $this->legacyConfig,
            $this->builtin,
            $this->cli
        );

        $provider = $factory->createEnvironmentOnly();

        $this->assertSame($this->env, $provider);
    }

    public function testCreateUserConfigOnly(): void
    {
        $factory = new ConfigProviderFactory(
            $this->env,
            $this->userConfig,
            $this->legacyConfig,
            $this->builtin,
            $this->cli
        );

        $provider = $factory->createUserConfigOnly();

        $this->assertSame($this->userConfig, $provider);
    }

    public function testCreateBuiltinOnly(): void
    {
        $factory = new ConfigProviderFactory(
            $this->env,
            $this->userConfig,
            $this->legacyConfig,
            $this->builtin,
            $this->cli
        );

        $provider = $factory->createBuiltinOnly();

        $this->assertSame($this->builtin, $provider);
    }

    public function testGetEnvironmentProvider(): void
    {
        $factory = new ConfigProviderFactory(
            $this->env,
            $this->userConfig,
            $this->legacyConfig,
            $this->builtin,
            $this->cli
        );

        $this->assertSame($this->env, $factory->getEnvironmentProvider());
    }

    public function testGetUserConfigProvider(): void
    {
        $factory = new ConfigProviderFactory(
            $this->env,
            $this->userConfig,
            $this->legacyConfig,
            $this->builtin,
            $this->cli
        );

        $this->assertSame($this->userConfig, $factory->getUserConfigProvider());
    }

    public function testGetLegacyConfigProvider(): void
    {
        $factory = new ConfigProviderFactory(
            $this->env,
            $this->userConfig,
            $this->legacyConfig,
            $this->builtin,
            $this->cli
        );

        $this->assertSame($this->legacyConfig, $factory->getLegacyConfigProvider());
    }

    public function testGetLegacyConfigProviderReturnsNullWhenNotSet(): void
    {
        $factory = new ConfigProviderFactory(
            $this->env,
            $this->userConfig,
            null,
            $this->builtin,
            $this->cli
        );

        $this->assertNull($factory->getLegacyConfigProvider());
    }

    public function testGetBuiltinProvider(): void
    {
        $factory = new ConfigProviderFactory(
            $this->env,
            $this->userConfig,
            $this->legacyConfig,
            $this->builtin,
            $this->cli
        );

        $this->assertSame($this->builtin, $factory->getBuiltinProvider());
    }

    public function testGetCliProvider(): void
    {
        $factory = new ConfigProviderFactory(
            $this->env,
            $this->userConfig,
            $this->legacyConfig,
            $this->builtin,
            $this->cli
        );

        $this->assertSame($this->cli, $factory->getCliProvider());
    }

    public function testGetCliProviderReturnsNullWhenNotSet(): void
    {
        $factory = new ConfigProviderFactory(
            $this->env,
            $this->userConfig,
            $this->legacyConfig,
            $this->builtin,
            null
        );

        $this->assertNull($factory->getCliProvider());
    }

    public function testCreateSelectiveWithEmptyArray(): void
    {
        $factory = new ConfigProviderFactory(
            $this->env,
            $this->userConfig,
            $this->legacyConfig,
            $this->builtin,
            $this->cli
        );

        $provider = $factory->createSelective([]);

        $this->assertInstanceOf(EffectiveConfigProvider::class, $provider);
        // Should have no providers, so nothing available
        $this->assertFalse($provider->hasSetting('anything'));
    }
}
