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
    ) {}

    /**
     * Setup Git Checkout Directory
     *
     * Priority of checkout_dir:
     * 1. config file
     * 2. HORDE_GIT_DIR
     * 3. HOME/git
     * 4. /srv/git/horde
     */
    public function __invoke(): GitCheckoutDirectory
    {
        $options = $this->config->getOptions();
        $defaultLocalCheckoutDir = $this->environmentConfig->hasSetting('HORDE_GIT_DIR') ? $this->environmentConfig->getSetting('HORDE_GIT_DIR') : '';
        if (empty($defaultLocalCheckoutDir)) {
            $defaultLocalCheckoutDir = $this->environmentConfig->hasSetting('HOME') ? $this->environmentConfig->getSetting('HOME') . '/git' : '/srv/git/horde';
        }
        return new GitCheckoutDirectory($options['checkout_dir'] ?? $defaultLocalCheckoutDir);
    }
}
