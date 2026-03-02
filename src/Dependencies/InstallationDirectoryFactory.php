<?php

namespace Horde\Components\Dependencies;

use Horde\Components\Composer\InstallationDirectory;
use Horde\Components\ConfigProvider\EnvironmentConfigProvider;

class InstallationDirectoryFactory
{
    public function __construct(
        private EnvironmentConfigProvider $environmentConfig
    ) {}

    public function __invoke(): InstallationDirectory
    {
        // Check for install_base in config, then environment, then defaults
        $installationDir = $this->environmentConfig->hasSetting('install_base')
            ? $this->environmentConfig->getSetting('install_base')
            : '';

        if (empty($installationDir)) {
            $installationDir = $this->environmentConfig->hasSetting('HORDE_INSTALL_DIR')
                ? $this->environmentConfig->getSetting('HORDE_INSTALL_DIR')
                : '';
        }

        if (empty($installationDir)) {
            $installationDir = $this->environmentConfig->hasSetting('HOME')
                ? $this->environmentConfig->getSetting('HOME') . '/www/horde-dev'
                : '/srv/www/horde-dev';
        }

        return new InstallationDirectory($installationDir);
    }
}
