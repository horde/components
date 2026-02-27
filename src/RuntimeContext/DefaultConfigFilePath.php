<?php

declare(strict_types=1);

namespace Horde\Components\RuntimeContext;

use Horde\Components\Application\ShellEnvironment;

class DefaultConfigFilePath
{
    public function __construct(private ShellEnvironment $env) {}

    /**
     * Returns the default config file path.
     * This is either
     * - TODO: A specific env value
     * - a file under the user's home dir 'home/$foo/.config/horde/components.php'
     * - a legacy fallback to components/config/conf.php
     *
     * If only one of default and legacy path actually has the file, that one is returned.
     * Otherwise, the default path is returned even if there is no file.
     *
     * A commandline switch will have higher precendence.
     */
    public function find(): string
    {
        // TODO: Mind CLI option.
        $homeDir = $this->env->getOrDefault('HOME', '');
        $defaultConfigFilePath = implode(DIRECTORY_SEPARATOR, [$homeDir, '.config', 'horde', 'components.php']);
        $legacyConfigFilePath = dirname(__FILE__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'conf.php';
        if (is_readable($defaultConfigFilePath)) {
            return $defaultConfigFilePath;
        }
        if (is_readable($legacyConfigFilePath)) {
            return $legacyConfigFilePath;
        }
        return $defaultConfigFilePath;
    }


    public function __invoke(): string
    {
        return $this->find();
    }
}
