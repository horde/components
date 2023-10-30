<?php

namespace Horde\Components\Module;

use Horde\Components\Config;
use Horde\Components\Exception;
use Horde\Components\Output;
use Horde\Components\Runner\Pipeline as PipelineRunner;

class Pipeline extends Base
{
    public function getOptionGroupTitle(): string
    {
        return 'Pipeline';
    }

    public function getOptionGroupDescription(): string
    {
        return 'Runs pipelines in a clean room directory';
    }

    public function getOptionGroupOptions(): array
    {
        return [new \Horde_Argv_Option(
            '',
            '--clean-room-dir',
            ['action' => 'store', 'help'   => 'Where to put the auto-deleted dir?',
            'default' => dirname(__FILE__, 3) . '/tmp'
            ]
        )];
    }

    /**
     * Get the usage title for this module.
     *
     * @return string The title.
     */
    public function getTitle(): string
    {
        return 'pipeline';
    }

    /**
     * Get the usage description for this module.
     *
     * @return string The description.
     */
    public function getUsage(): string
    {
        return 'Run clean-room pipelines on checked in source code';
    }

    /**
     * Return the action arguments supported by this module.
     *
     * @return array A list of supported action arguments.
     */
    public function getActions(): array
    {
        return ['pipeline'];
    }

    /**
     * Return the help text for the specified action.
     *
     * @param string $action The action.
     *
     * @return string The help text.
     */
    public function getHelp($action): string
    {
        return 'This module runs pipelines defined in the config file on a clean room dir.
        Tasks previously designed for the qc and release commands can be reused in these pipelines as far as they are fit.';
    }

    /**
     * Return the options that should be explained in the context help.
     *
     * @return array A list of option help texts.
     */
    public function getContextOptionHelp(): array
    {
        return [];
    }

    /**
     * Determine if this module should act. Run all required actions if it has
     * been instructed to do so.
     *
     * @param Config $config The configuration.
     *
     * @return boolean True if the module performed some action.
     */
    public function handle(Config $config)
    {
        $options = $config->getOptions();
        $arguments = $config->getArguments();
        if (!empty($arguments[0]) && $arguments[0] == 'pipeline') {

            $this->_dependencies->get(PipelineRunner::class)->run();
            return true;
        }
        return false;
    }
}
