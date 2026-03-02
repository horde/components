<?php

namespace Horde\Components\Dependencies;

use Horde\Components\RuntimeContext\GitCheckoutDirectory;
use Horde\Components\ConfigProvider\EnvironmentConfigProvider;

class GitCheckoutDirectoryFactory
{
    public function __construct(
        private EnvironmentConfigProvider $environmentConfig
    ) {}

    /**
     * Setup Git Checkout Directory
     *
     * Priority of checkout_dir:
     * 1. checkout_dir from config
     * 2. HORDE_GIT_DIR environment variable
     * 3. HOME/git/horde
     * 4. /srv/git/horde
     */
    public function __invoke(): GitCheckoutDirectory
    {
        // Check for checkout_dir in config first
        $checkoutDir = $this->environmentConfig->hasSetting('checkout_dir')
            ? $this->environmentConfig->getSetting('checkout_dir')
            : '';

        if (empty($checkoutDir)) {
            $checkoutDir = $this->environmentConfig->hasSetting('HORDE_GIT_DIR')
                ? $this->environmentConfig->getSetting('HORDE_GIT_DIR')
                : '';
        }

        if (empty($checkoutDir)) {
            $checkoutDir = $this->environmentConfig->hasSetting('HOME')
                ? $this->environmentConfig->getSetting('HOME') . '/git/horde'
                : '/srv/git/horde';
        }

        return new GitCheckoutDirectory($checkoutDir);
    }
}
