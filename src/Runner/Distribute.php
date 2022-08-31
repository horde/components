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
use Horde\Components\Config\Application as ConfigApplication;
use Horde\Components\Exception;
use Horde\Components\Helper\Dependencies as HelperDependencies;
use Horde\Components\Output;

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
    * Constructor.
    *
     * @param Config $_config The configuration for the current job.
     * @param ConfigApplication $_config_application The application
                                             configuration.
    */
    public function __construct(
        private readonly Config $_config,
        private readonly ConfigApplication $_config_application,
        /**
         * The output handler.
         *
         * @param Component_Output
         */
        private readonly Output $_output
    ) {
    }

    public function run(): void
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
