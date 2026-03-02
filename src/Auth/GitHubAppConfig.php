<?php

declare(strict_types=1);

namespace Horde\Components\Auth;

use InvalidArgumentException;

/**
 * GitHub App configuration
 *
 * Copyright 2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class GitHubAppConfig
{
    /**
     * @param int $appId GitHub App ID
     * @param int $installationId Installation ID for the organization/user
     * @param string $privateKeyPath Path to the private key PEM file
     */
    public function __construct(
        public readonly int $appId,
        public readonly int $installationId,
        public readonly string $privateKeyPath
    ) {
        $this->validate();
    }

    /**
     * Validate configuration
     *
     * @throws InvalidArgumentException If configuration is invalid
     */
    private function validate(): void
    {
        if ($this->appId <= 0) {
            throw new InvalidArgumentException('GitHub App ID must be positive');
        }

        if ($this->installationId <= 0) {
            throw new InvalidArgumentException('GitHub App Installation ID must be positive');
        }

        if (trim($this->privateKeyPath) === '') {
            throw new InvalidArgumentException('Private key path cannot be empty');
        }
    }

    /**
     * Create from array (for config file loading)
     *
     * @param array<string, mixed> $config Configuration array
     * @return self
     * @throws InvalidArgumentException If required keys are missing or invalid
     */
    public static function fromArray(array $config): self
    {
        if (!isset($config['app_id'])) {
            throw new InvalidArgumentException('Missing required config key: app_id');
        }

        if (!isset($config['installation_id'])) {
            throw new InvalidArgumentException('Missing required config key: installation_id');
        }

        if (!isset($config['private_key_path'])) {
            throw new InvalidArgumentException('Missing required config key: private_key_path');
        }

        return new self(
            appId: (int) $config['app_id'],
            installationId: (int) $config['installation_id'],
            privateKeyPath: (string) $config['private_key_path']
        );
    }

    /**
     * Create from environment variables
     *
     * @return self
     * @throws InvalidArgumentException If required environment variables are missing
     */
    public static function fromEnvironment(): self
    {
        $appId = getenv('GITHUB_APP_ID');
        $installationId = getenv('GITHUB_APP_INSTALLATION_ID');
        $privateKeyPath = getenv('GITHUB_APP_PRIVATE_KEY_PATH');

        if ($appId === false || $appId === '') {
            throw new InvalidArgumentException('Missing environment variable: GITHUB_APP_ID');
        }

        if ($installationId === false || $installationId === '') {
            throw new InvalidArgumentException('Missing environment variable: GITHUB_APP_INSTALLATION_ID');
        }

        if ($privateKeyPath === false || $privateKeyPath === '') {
            throw new InvalidArgumentException('Missing environment variable: GITHUB_APP_PRIVATE_KEY_PATH');
        }

        return new self(
            appId: (int) $appId,
            installationId: (int) $installationId,
            privateKeyPath: (string) $privateKeyPath
        );
    }
}
