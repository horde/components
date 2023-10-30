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
     * Constructor.
     *
     * @param Config $_config The configuration for the current job.
     * @param HelperWebsite $_website_helper The website helper.
     */
    public function __construct(private readonly Config $_config, private readonly HelperWebsite $_website_helper)
    {
    }

    public function run(): void
    {
        $this->_website_helper->update(
            $this->_config->getComponent(),
            $this->_config->getOptions()
        );
    }
}
