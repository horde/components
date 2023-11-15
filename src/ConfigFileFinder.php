<?php

declare(strict_types=1);

namespace Horde\Components;

use Horde\Components\ConfigProvider\EnvironmentConfigProvider;

class ConfigFileFinder
{
    public function __construct(private EnvironmentConfigProvider $env)
    {
    }

    public function find(): string
    {
        if ($this->env->hasSetting('HORDE_COMPONENTS_CONFIG')) {
            return $this->env->getSetting('HORDE_COMPONENTS_CONFIG');
        }
        if ($this->env->hasSetting('HOME')) {
            return $this->env->getSetting('HOME') . '/.config/horde/components.php';
        }
        return '';
    }
}
