<?php
namespace Horde\Components\Dependencies;

use Horde\Components\RuntimeContext\GitCheckoutDirectory;
use Horde\Components\Config;

class GitCheckoutDirectoryFactory
{
    public function __construct(private readonly Config $config)
    {

    }

    public function __invoke(): GitCheckoutDirectory
    {
        $options = $this->config->getOptions();
        return new GitCheckoutDirectory($options['checkout_dir'] ?? '/srv/git/horde');
    }
}