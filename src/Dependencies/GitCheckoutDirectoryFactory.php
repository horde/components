<?php
namespace Horde\Components\Dependencies;

use Horde\Components\RuntimeContext\GitCheckoutDirectory;
use Horde\Components\ConfigProvider\EnvironmentConfigProvider;
use Horde\Components\Config;

class GitCheckoutDirectoryFactory
{
    public function __construct(
        private readonly Config $config,
        private EnvironmentConfigProvider $environmentConfig
    )
    {

    }

    public function __invoke(): GitCheckoutDirectory
    {
        $options = $this->config->getOptions();
        $defaultLocalCheckoutDir = $this->environmentConfig->hasSetting('HOME') ? $this->environmentConfig->getSetting('HOME') . '/git' : '/srv/git/horde';
        return new GitCheckoutDirectory($options['checkout_dir'] ?? $defaultLocalCheckoutDir);
    }
}