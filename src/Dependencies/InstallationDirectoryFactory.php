<?php

namespace Horde\Components\Dependencies;

use Horde\Components\Composer\InstallationDirectory;
use Horde\Components\ConfigProvider\EffectiveConfigProvider;
use Horde\Components\ConfigProvider\EnvironmentConfigProvider;

class InstallationDirectoryFactory
{
    public function __construct(
        private EffectiveConfigProvider|EnvironmentConfigProvider $config
    ) {}

    public function __invoke(): InstallationDirectory
    {
        // Check for install.dir in config (includes file + env + defaults)
        $installationDir = $this->config->hasSetting('install.dir')
            ? $this->config->getSetting('install.dir')
            : '';

        // Fallback to HORDE_INSTALL_DIR environment variable
        if (empty($installationDir)) {
            $installationDir = $this->config->hasSetting('HORDE_INSTALL_DIR')
                ? $this->config->getSetting('HORDE_INSTALL_DIR')
                : '';
        }

        // Final fallback to default location
        if (empty($installationDir)) {
            $installationDir = $this->config->hasSetting('HOME')
                ? $this->config->getSetting('HOME') . '/www/horde-dev'
                : '/srv/www/horde-dev';
        }

        return new InstallationDirectory($installationDir);
    }
}
