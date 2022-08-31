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
use Horde\Components\Output;
use Horde\Components\Pear\Factory as PearFactory;

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
    * Constructor.
    *
     * @param Config $_config The configuration for the
                                             current job.
     * @param ConfigApplication $_config_application The application
                                             configuration.
     * @param PearFactory $_factory Generator for all
                                             required PEAR components.
    */
    public function __construct(private readonly Config $_config, private readonly ConfigApplication $_config_application, private readonly PearFactory $_factory)
    {
    }

    public function run(): void
    {
        $options = $this->_config->getOptions();
        $templates = new HelperTemplatesRecursiveDirectory(
            $this->_config_application->getTemplateDirectory(),
            $options['cisetup']
        );
        $templates->write(['config' => $this->_config]);
    }
}
