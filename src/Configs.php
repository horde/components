<?php
/**
 * Components_Configs:: class represents configuration for the
 * Horde component tool.
 *
 * PHP Version 7
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

namespace Horde\Components;

use Horde\Components\Config\Base;

/**
 * Components_Configs:: class represents configuration for the
 * Horde component tool.
 *
 * Copyright 2009-2020 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Configs extends Base
{
    /**
     * The different configuration handlers.
     */
    private array $_configs = [];

    /**
     * Have the arguments been collected?
     */
    private bool $_collected = false;

    /**
     * Constructor.
     */
    public function __construct()
    {
    }

    /**
     * Add a configuration type to the configuration handler.
     *
     * @param Config $type The configuration type.
     */
    public function addConfigurationType(Config $type): void
    {
        $this->_configs[] = $type;
    }

    /**
     * Store a configuration type at the start of the configuration stack. Any
     * options provided by the new configuration can/will be overridden by
     * configurations already present.
     *
     * @param Config $type The configuration type.
     */
    public function unshiftConfigurationType(Config $type): void
    {
        array_unshift($this->_configs, $type);
    }

    /**
     * Provide each configuration handler with the list of supported modules.
     *
     * @param Module[] $modules A list of modules.
     */
    public function handleModules(iterable $modules): void
    {
        foreach ($this->_configs as $config) {
            $config->handleModules($modules);
        }
    }

    /**
     * Return the options provided by the configuration handlers.
     *
     * @return array An array of options.
     */
    public function getOptions(): array
    {
        $options = [];
        foreach ($this->_configs as $config) {
            if ((is_countable($config->getOptions()) ? count($config->getOptions()) : 0) !== 0) {
                $config_options = [];
                foreach ($config->getOptions() as $name => $option) {
                    if ($option !== null) {
                        $config_options[$name] = $option;
                    }
                }
                $options = array_merge($options, $config_options);
            }
        }
        $options = array_merge($options, $this->_options);
        return $options;
    }

    /**
     * Return the arguments provided by the configuration handlers.
     *
     * @return array An array of arguments.
     */
    public function getArguments(): array
    {
        if (!$this->_collected) {
            foreach ($this->_configs as $config) {
                $config_arguments = $config->getArguments();
                if (!empty($config_arguments)) {
                    $this->_arguments = array_merge(
                        $this->_arguments,
                        $config_arguments
                    );
                }
            }
            $this->_collected = true;
        }
        return $this->_arguments;
    }
}
