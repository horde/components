<?php
/**
 * Components_Runner_CiSetup:: prepares a continuous integration setup for a
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
use Horde\Components\Config\Application as ConfigApplication;
use Horde\Components\Factory;
use Horde\Components\Helper\Templates\RecursiveDirectory as HelperTemplatesRecursiveDirectory;
use Horde\Components\Pear\Factory as PearFactory;
use Horde\Components\Output;

/**
 * Components_Runner_CiSetup:: prepares a continuous integration setup for a
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
class CiSetup
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
     * The factory for PEAR handlers.
     *
     * @var Factory
     */
    private $_factory;

    /**
     * Constructor.
     *
     * @param Config             $config  The configuration for the
     *                                               current job.
     * @param ConfigApplication $cfgapp  The application
     *                                               configuration.
     * @param PearFactory       $factory Generator for all
     *                                               required PEAR components.
     */
    public function __construct(
        Config $config, 
        ConfigApplication $cfgapp, 
        PearFactory $factory
    ) {
        $this->_config             = $config;
        $this->_config_application = $cfgapp;
        $this->_factory            = $factory;
    }

    public function run()
    {
        $options = $this->_config->getOptions();
        $templates = new HelperTemplatesRecursiveDirectory(
            $this->_config_application->getTemplateDirectory(),
            $options['cisetup']
        );
        $templates->write(array('config' => $this->_config));
    }
}
