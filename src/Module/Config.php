<?php
/**
 * Components\Module\Change:: Read and Manipulate the Config File
 *
 * PHP Version 8
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

namespace Horde\Components\Module;

use Horde\Cli\Cli;
use Horde\Components\Config as ComponentsConfig;
use Horde\Components\ConfigProvider\BuiltinConfigProvider;
use Horde\Components\ConfigProvider\PhpConfigFileProvider;

/**
 * Components\Module\Change:: Read and Manipulate the Config File
 *
 * Copyright 2023-2024 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Config extends Base
{
    public function getOptionGroupTitle(): string
    {
        return 'Configuration';
    }

    public function getOptionGroupDescription(): string
    {
        return 'This module reads and writes config files';
    }

    public function getOptionGroupOptions(): array
    {
        return [];
    }

    /**
     * Get the usage title for this module.
     *
     * @return string The title.
     */
    public function getTitle(): string
    {
        return 'config';
    }

    /**
     * Get the usage description for this module.
     *
     * @return string The description.
     */
    public function getUsage(): string
    {
        return 'configure the tool';
    }

    /**
     * Return the action arguments supported by this module.
     *
     * @return array A list of supported action arguments.
     */
    public function getActions(): array
    {
        return ['config'];
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
        return 'This module manages the horde/components tool\'s config file

For reading a config file value
    horde-components config key

For writing a config file value
    horde-components config key value

';
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
     * @param ComponentsConfig $config The configuration.
     *
     * @return bool True if the module performed some action.
     */
    public function handle(ComponentsConfig $config): bool
    {
        $arguments = $config->getArguments();
        if ((isset($arguments[0]) && $arguments[0] == 'config')) {
            $this->_handle($arguments);
            return true;
        }
        return false;
    }

    private function _handle(array $arguments)
    {
        $builtin = $this->dependencies->get(BuiltinConfigProvider::class);
        $phpFile = $this->dependencies->get(PhpConfigFileProvider::class);
        $cli = $this->dependencies->get(Cli::class);
        if (isset($arguments[1]) && $arguments[1] == 'init') {
            foreach ($builtin->dumpSettings() as $id => $value) {
                $phpFile->setSetting($id, $value);
            }
            $phpFile->writeToDisk();
        } elseif (isset($arguments[1]) && isset($arguments[2])) {
            $phpFile->setSetting($arguments[1], $arguments[2]);
            $phpFile->writeToDisk();
        } elseif (isset($arguments[1])) {
            $cli->writeln(sprintf("The value of %s is:", $arguments[1]));
            $cli->writeln($phpFile->getSetting($arguments[1]));
        } else {
            $cli->writeln($this->getHelp(""));
        }
    }
}
