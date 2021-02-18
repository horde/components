<?php
/**
 * Components_Config_Application:: provides a wrapper that provides application
 * specific configuration values by combining defaults and options provided at
 * runtime.
 *
 * PHP Version 7
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
namespace Horde\Components\Config;
use Horde\Components\Config;
use Horde\Components\Constants;

/**
 * Config\Application:: provides a wrapper that provides application
 * specific configuration values by combining defaults and options provided at
 * runtime.
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
class Application
{
    /**
     * The generic configuration handler.
     *
     * @var Config
     */
    private $_config;

    /**
     * Constructor.
     *
     * @param Config $config The generic configuration handler.
     */
    public function __construct(
        Config $config
    ) {
        $this->_config = $config;
    }

    /**
     * Return the path to the template directory
     *
     * @return string The path to the template directory.
     */
    public function getTemplateDirectory()
    {
        $options = $this->_config->getOptions();
        if (!isset($options['templatedir'])) {
            return Constants::getDataDirectory();
        } else {
            return $options['templatedir'];
        }
    }
}
