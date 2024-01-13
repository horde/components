<?php
namespace Horde\Components\Dependencies;

use Horde\Components\Composer\InstallationDirectory;
use Horde\Components\RuntimeContext\GitCheckoutDirectory;
use Horde\Components\Config;

class InstallationDirectoryFactory
{
    public function __construct(private readonly Config $config)
    {

    }

    public function __invoke(): InstallationDirectory
    {
        $options = $this->config->getOptions();
        return new InstallationDirectory($options['install_base'] ?? '/srv/www/horde-dev');
    }
}