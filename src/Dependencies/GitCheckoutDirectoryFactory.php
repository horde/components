<?php

namespace Horde\Components\Dependencies;

use Horde\Components\RuntimeContext\GitCheckoutDirectory;
use Horde\Components\ConfigProvider\EffectiveConfigProvider;
use Horde\Components\ConfigProvider\EnvironmentConfigProvider;

class GitCheckoutDirectoryFactory
{
    public function __construct(
        private EffectiveConfigProvider|EnvironmentConfigProvider $config
    ) {}

    /**
     * Setup Git Checkout Directory
     *
     * Priority of checkout.dir:
     * 1. checkout.dir from config (includes file + env + defaults)
     * 2. HORDE_GIT_DIR environment variable
     * 3. HOME/git
     * 4. /srv/git
     *
     * NOTE: checkout.dir should point to the parent of vendor directories.
     * Components are located at: checkout.dir/vendor/component
     * Example: /home/user/git/horde/ActiveSync
     */
    public function __invoke(): GitCheckoutDirectory
    {
        // Check for checkout.dir in config first (includes file + env + defaults)
        $checkoutDir = $this->config->hasSetting('checkout.dir')
            ? $this->config->getSetting('checkout.dir')
            : '';

        // Fallback to HORDE_GIT_DIR environment variable
        if (empty($checkoutDir)) {
            $checkoutDir = $this->config->hasSetting('HORDE_GIT_DIR')
                ? $this->config->getSetting('HORDE_GIT_DIR')
                : '';
        }

        // Final fallback to default location
        if (empty($checkoutDir)) {
            $checkoutDir = $this->config->hasSetting('HOME')
                ? $this->config->getSetting('HOME') . '/git'
                : '/srv/git';
        }

        return new GitCheckoutDirectory($checkoutDir);
    }
}
