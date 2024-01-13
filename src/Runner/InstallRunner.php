<?php
declare(strict_types=1);
namespace Horde\Components\Runner;

use Horde\Components\Composer\InstallationDirectory;
use Horde\Components\Config;
use Horde\Components\Dependencies\GitCheckoutDirectoryFactory;

class InstallRunner
{
    public function __construct(
        private GitCheckoutDirectoryFactory $gitCheckoutDirectory,
        private InstallationDirectory $installationDirectory
    )
    {
    }

    public function run(Config $config)
    {

    }
}