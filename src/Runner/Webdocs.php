<?php
/**
 * Components_Runner_Webdocs:: generates the www.horde.org data for a component.
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
use Horde\Components\Helper\Website as HelperWebsite;
use Horde\Components\Output;

/**
 * Components_Runner_Webdocs:: generates the www.horde.org data for a component.
 *
 * Copyright 2011-2020 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Webdocs
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
     * The website helper.
     *
     * @var HelperWebsite
     */
    private $_website_helper;

    /**
     * Constructor.
     *
     * @param Config         $config The configuration for the current job.
     * @param HelperWebsite $helper The website helper.
     */
    public function __construct(
        Config $config,
        HelperWebsite $helper
    ) {
        $this->_config = $config;
        $this->_website_helper = $helper;
    }

    public function run()
    {
        $this->_website_helper->update(
            $this->_config->getComponent(),
            $this->_config->getOptions()
        );
    }
}
