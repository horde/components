<?php
namespace Horde\Components\Dependencies;

use Horde\Components\Composer\InstallationDirectory;
use Horde\Components\ConfigProvider\EnvironmentConfigProvider;
use Horde\Components\Config;

class InstallationDirectoryFactory
{
    public function __construct(
        private readonly Config $config,
        private EnvironmentConfigProvider $environmentConfig    
    )
    {

    }

    public function __invoke(): InstallationDirectory
    {
        $options = $this->config->getOptions();
        $defaultInstallationDir = $this->environmentConfig->hasSetting('HOME') ? $this->environmentConfig->getSetting('HOME') . '/www/horde-dev' : '/srv/www/horde-dev';
        return new InstallationDirectory($options['install_base'] ?? $defaultInstallationDir);
    }
}