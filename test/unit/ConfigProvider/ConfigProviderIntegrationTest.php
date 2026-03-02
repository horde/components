<?php

declare(strict_types=1);

namespace Horde\Components\Test\Unit\ConfigProvider;

use Horde\Components\ConfigProvider\CliConfigProvider;
use Horde\Components\ConfigProvider\EnvironmentConfigProvider;
use Horde\Components\ConfigProvider\PhpConfigFileProvider;
use Horde\Components\ConfigProvider\BuiltinConfigProvider;
use Horde\Components\ConfigProvider\EffectiveConfigProvider;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Integration tests for full config provider hierarchy including CLI
 *
 * Copyright 2026 The Horde Project (http://www.horde.org/)
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
#[CoversClass(EffectiveConfigProvider::class)]
class ConfigProviderIntegrationTest extends TestCase
{
    public function testCliOverridesAllLayers(): void
    {
        // Setup all layers with same key, different values
        $cli = new CliConfigProvider(['test-key' => 'from-cli']);
        $env = new EnvironmentConfigProvider(['test.key' => 'from-env']);
        $builtin = new BuiltinConfigProvider(['test.key' => 'from-builtin']);

        // CLI should win
        $effective = new EffectiveConfigProvider($cli, $env, $builtin);

        $this->assertSame('from-cli', $effective->getSetting('test.key'));
        $this->assertSame('CliConfigProvider', $effective->getProvidingLayerName('test.key'));
    }

    public function testCliWithDashesNormalizesToDots(): void
    {
        // CLI options use kebab-case (--github-token)
        $cli = new CliConfigProvider(['github-token' => 'ghp_cli123']);
        $builtin = new BuiltinConfigProvider(['github.token' => 'ghp_builtin']);

        $effective = new EffectiveConfigProvider($cli, $builtin);

        // Should normalize github-token to github.token
        $this->assertSame('ghp_cli123', $effective->getSetting('github.token'));
    }

    public function testFullHierarchyPrecedence(): void
    {
        // Build full hierarchy
        $cli = new CliConfigProvider([
            'cli-only' => 'cli-value',
            'override-me' => 'from-cli'
        ]);

        $env = new EnvironmentConfigProvider([
            'env.only' => 'env-value',
            'override.me' => 'from-env'
        ]);

        $builtin = new BuiltinConfigProvider([
            'builtin.only' => 'builtin-value',
            'override.me' => 'from-builtin'
        ]);

        $effective = new EffectiveConfigProvider($cli, $env, $builtin);

        // CLI-only setting
        $this->assertSame('cli-value', $effective->getSetting('cli.only'));
        $this->assertSame('CliConfigProvider', $effective->getProvidingLayerName('cli.only'));

        // Env-only setting
        $this->assertSame('env-value', $effective->getSetting('env.only'));
        $this->assertSame('EnvironmentConfigProvider', $effective->getProvidingLayerName('env.only'));

        // Builtin-only setting
        $this->assertSame('builtin-value', $effective->getSetting('builtin.only'));
        $this->assertSame('BuiltinConfigProvider', $effective->getProvidingLayerName('builtin.only'));

        // Override test - CLI should win
        $this->assertSame('from-cli', $effective->getSetting('override.me'));
        $this->assertSame('CliConfigProvider', $effective->getProvidingLayerName('override.me'));
    }

    public function testCliFiltersNullAndFalseValues(): void
    {
        // Parser might set options to null/false for unset flags
        $cli = new CliConfigProvider([
            'set-value' => 'actual-value',
            'null-value' => null,
            'false-value' => false,
            'empty-string' => '',  // Empty string IS a value
            'zero-value' => '0'    // Zero IS a value
        ]);

        $builtin = new BuiltinConfigProvider([
            'null.value' => 'from-builtin',
            'false.value' => 'from-builtin',
        ]);

        $effective = new EffectiveConfigProvider($cli, $builtin);

        // Null and false should not override builtin
        $this->assertSame('from-builtin', $effective->getSetting('null.value'));
        $this->assertSame('from-builtin', $effective->getSetting('false.value'));

        // But empty string and zero are valid values
        $this->assertSame('', $effective->getSetting('empty.string'));
        $this->assertSame('0', $effective->getSetting('zero.value'));
    }

    public function testAuthorEmailScenario(): void
    {
        // Real-world scenario: init command with --author and --email
        $cli = new CliConfigProvider([
            'author' => 'Jane Doe',
            'email' => 'jane@example.com'
        ]);

        $builtin = new BuiltinConfigProvider([
            'author' => 'Default Author',
            'email' => 'default@example.com'
        ]);

        $effective = new EffectiveConfigProvider($cli, $builtin);

        // CLI values should be used
        $this->assertSame('Jane Doe', $effective->getSetting('author'));
        $this->assertSame('jane@example.com', $effective->getSetting('email'));
    }

    public function testWebConfigScenario(): void
    {
        // Real-world scenario: web command with --web-token
        $cli = new CliConfigProvider([
            'web-token' => 'ghp_from_cli'
        ]);

        $env = new EnvironmentConfigProvider([
            'GITHUB_TOKEN' => 'ghp_from_env'
        ]);

        $effective = new EffectiveConfigProvider($cli, $env);

        // CLI web-token normalizes to web.token and overrides env GITHUB_TOKEN
        $this->assertSame('ghp_from_cli', $effective->getSetting('web.token'));

        // But GITHUB_TOKEN is still available from env
        $this->assertSame('ghp_from_env', $effective->getSetting('GITHUB_TOKEN'));
    }

    public function testGetDiagnosticsWithCli(): void
    {
        $cli = new CliConfigProvider(['test' => 'cli-value']);
        $env = new EnvironmentConfigProvider(['test' => 'env-value']);
        $builtin = new BuiltinConfigProvider(['test' => 'builtin-value']);

        $effective = new EffectiveConfigProvider($cli, $env, $builtin);

        $diagnostics = $effective->getDiagnostics('test');

        // Check main info
        $this->assertSame('test', $diagnostics['key']);
        $this->assertTrue($diagnostics['exists']);
        $this->assertFalse($diagnostics['is_unset']);
        $this->assertSame('cli-value', $diagnostics['value']);
        $this->assertSame('CliConfigProvider', $diagnostics['providing_layer']);

        // Check layers
        $this->assertGreaterThanOrEqual(3, count($diagnostics['checked_layers']));

        // Find our layers
        $cliLayer = null;
        $envLayer = null;
        $builtinLayer = null;

        foreach ($diagnostics['checked_layers'] as $layer) {
            if ($layer['name'] === 'CliConfigProvider') {
                $cliLayer = $layer;
            } elseif ($layer['name'] === 'EnvironmentConfigProvider') {
                $envLayer = $layer;
            } elseif ($layer['name'] === 'BuiltinConfigProvider') {
                $builtinLayer = $layer;
            }
        }

        $this->assertNotNull($cliLayer);
        $this->assertNotNull($envLayer);
        $this->assertNotNull($builtinLayer);

        $this->assertTrue($cliLayer['has_setting']);
        $this->assertTrue($envLayer['has_setting']);
        $this->assertTrue($builtinLayer['has_setting']);
    }

    public function testAvailableKeysIncludesCliKeys(): void
    {
        $cli = new CliConfigProvider(['cli-key' => 'value']);
        $env = new EnvironmentConfigProvider(['env.key' => 'value']);

        $effective = new EffectiveConfigProvider($cli, $env);

        $keys = $effective->getAvailableKeys();

        $this->assertContains('cli.key', $keys);  // Normalized from cli-key
        $this->assertContains('env.key', $keys);
    }
}
