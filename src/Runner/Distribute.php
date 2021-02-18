<?php
/**
 * Components_Runner_Distribute:: prepares a distribution package for a
 * component.
 *
 * PHP Version 7
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
namespace Horde\Components\Runner;
use Horde\Components\Config;
use Horde\Components\Exception;
use Horde\Components\Config\Application as ConfigApplication;
use Horde\Components\Output;
use Horde\Components\Helper\Dependencies as HelperDependencies;

/**
 * Components_Runner_Distribute:: prepares a distribution package for a
 * component.
 *
 * Copyright 2010-2020 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Distribute
{
    /**
     * The configuration for the current job.
     *
     * @var Config
     */
    private $_config;

    /**
     * The application configuration.
     *
     * @var ConfigApplication
     */
    private $_config_application;

    /**
     * The output handler.
     *
     * @param Component_Output
     */
    private $_output;

    /**
     * Constructor.
     *
     * @param Config             $config  The configuration for the current job.
     * @param ConfigApplication $cfgapp  The application
     *                                               configuration.
     */
    public function __construct(
        Config $config,
        ConfigApplication $cfgapp,
        Output $output
    ) {
        $this->_config  = $config;
        $this->_config_application = $cfgapp;
        $this->_output  = $output;
    }

    public function run()
    {
        $script = $this->_config_application->getTemplateDirectory() . '/components.php';
        if (file_exists($script)) {
            include $script;
        } else {
            throw new Exception(
                sprintf(
                    'The distribution specific helper script at "%s" is missing!',
                    $script
                )
            );
        }
    }
}
