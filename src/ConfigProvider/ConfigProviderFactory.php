<?php

declare(strict_types=1);

namespace Horde\Components\ConfigProvider;

/**
 * Factory for creating configuration provider hierarchies
 *
 * Provides methods to build different provider chains based on needs:
 * - Default hierarchy with all layers
 * - Selective hierarchies with only specified layers
 * - Single-layer providers for testing
 *
 * Copyright 2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class ConfigProviderFactory
{
    /**
     * Constructor
     *
     * @param EnvironmentConfigProvider $env Environment variables provider
     * @param PhpConfigFileProvider $userConfig User config file (~/.config/horde/components.php)
     * @param PhpConfigFileProvider|null $legacyConfig Legacy config file (config/conf.php)
     * @param BuiltinConfigProvider $builtin Builtin defaults
     * @param CliConfigProvider|null $cli CLI arguments provider
     */
    public function __construct(
        private EnvironmentConfigProvider $env,
        private PhpConfigFileProvider $userConfig,
        private ?PhpConfigFileProvider $legacyConfig,
        private BuiltinConfigProvider $builtin,
        private ?CliConfigProvider $cli = null
    ) {}

    /**
     * Create the default full hierarchy
     *
     * Precedence order (highest to lowest):
     * 1. CLI arguments (if available)
     * 2. Environment variables
     * 3. User config file (~/.config/horde/components.php)
     * 4. Legacy config file (config/conf.php, if exists)
     * 5. Builtin defaults
     *
     * @return EffectiveConfigProvider The configured hierarchy
     */
    public function createDefault(): EffectiveConfigProvider
    {
        $providers = array_filter([
            $this->cli,           // Highest precedence
            $this->env,
            $this->userConfig,
            $this->legacyConfig,
            $this->builtin        // Lowest precedence
        ], fn($p) => $p !== null);

        return new EffectiveConfigProvider(...$providers);
    }

    /**
     * Create selective hierarchy - only specified layers
     *
     * Allows building custom provider chains for specific use cases.
     * Providers are added in the order specified.
     *
     * Example: createSelective(['env', 'userConfig', 'builtin'])
     * creates a chain that checks environment, then user config, then builtins.
     *
     * @param array $layerNames Array of layer names to include
     *                          Valid names: 'cli', 'env', 'userConfig', 'legacyConfig', 'builtin'
     * @return EffectiveConfigProvider The configured selective hierarchy
     */
    public function createSelective(array $layerNames): EffectiveConfigProvider
    {
        $map = [
            'cli' => $this->cli,
            'env' => $this->env,
            'userConfig' => $this->userConfig,
            'legacyConfig' => $this->legacyConfig,
            'builtin' => $this->builtin,
        ];

        $providers = [];
        foreach ($layerNames as $name) {
            if (isset($map[$name]) && $map[$name] !== null) {
                $providers[] = $map[$name];
            }
        }

        return new EffectiveConfigProvider(...$providers);
    }

    /**
     * Create environment-only provider
     *
     * Useful for reading only environment variables without fallbacks.
     * Example: For GitHub token from GITHUB_TOKEN env var only.
     *
     * @return ConfigProvider Environment provider
     */
    public function createEnvironmentOnly(): ConfigProvider
    {
        return $this->env;
    }

    /**
     * Create user-config-only provider
     *
     * Useful for reading only the user config file without fallbacks.
     *
     * @return ConfigProvider User config provider
     */
    public function createUserConfigOnly(): ConfigProvider
    {
        return $this->userConfig;
    }

    /**
     * Create builtin-only provider
     *
     * Useful for testing with only default values.
     *
     * @return ConfigProvider Builtin provider
     */
    public function createBuiltinOnly(): ConfigProvider
    {
        return $this->builtin;
    }

    /**
     * Get the environment provider
     *
     * @return EnvironmentConfigProvider
     */
    public function getEnvironmentProvider(): EnvironmentConfigProvider
    {
        return $this->env;
    }

    /**
     * Get the user config provider
     *
     * @return PhpConfigFileProvider
     */
    public function getUserConfigProvider(): PhpConfigFileProvider
    {
        return $this->userConfig;
    }

    /**
     * Get the legacy config provider
     *
     * @return PhpConfigFileProvider|null
     */
    public function getLegacyConfigProvider(): ?PhpConfigFileProvider
    {
        return $this->legacyConfig;
    }

    /**
     * Get the builtin provider
     *
     * @return BuiltinConfigProvider
     */
    public function getBuiltinProvider(): BuiltinConfigProvider
    {
        return $this->builtin;
    }

    /**
     * Get the CLI provider
     *
     * @return CliConfigProvider|null
     */
    public function getCliProvider(): ?CliConfigProvider
    {
        return $this->cli;
    }
}
