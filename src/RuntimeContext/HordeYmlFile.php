<?php
declare(strict_types=1);

namespace Horde\Components\RuntimeContext;

use Horde\Components\ArgvWrapper;
use Horde\Components\ConfigProvider\EnvironmentConfigProvider;
use Horde\Components\Config\Cli as CliConfig;

/**
 * A component directory is a directory with a .horde.yml file
 */
class HordeYmlFile
{
    private string $hordeYmlFilePath;
    protected string $filename = '.horde.yml';

    public function __construct(private CurrentWorkingDirectory $cwd, private EnvironmentConfigProvider $env, private CliConfig $argv)
    {
//        $this->hordeYmlFilePath = $this->checkFirstArg($this->filename) ?? $this->checkEnv($this->filename) ?? $this->checkCwd($this->filename) ?? '';
        $hordeYmlFilePath = $this->checkEnv($this->filename);
        if (empty($hordeYmlFilePath)) {
            $hordeYmlFilePath = $this->checkCwd($this->filename);
        }
        $this->hordeYmlFilePath = $hordeYmlFilePath;
    }


    public function checkCwd($filename): string
    {
        $path = $this->cwd->get();

        if ($path) {
            $path .= '/' . $filename;
            if (file_exists($path)) {
                return $path;
            }
        }
        return '';
    }

    public function checkEnv($filename)
    {
        if ($this->env->hasSetting('HORDE_COMPONENT_DIR')) {
            $path = $this->env->getSetting('HORDE_COMPONENT_DIR') . '/' . $filename;
            if (file_exists($path)) {
                return $path;
            }
        }
        return '';
    }

    public function checkFirstArg($filename)
    {
        $args = $this->argv->getArguments();
        return 'ff';
    }

    /**
     * Returns true if either the CWD is a component directory or one was given via env or first argument
     *
     */
    public function has(): bool
    {
        return false;
    }

    /**
     * Return the component dir or an empty string.
     */
    public function get(): string
    {
        return '';
    }
}