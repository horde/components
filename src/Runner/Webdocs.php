<?php

/**
 * Components_Runner_Webdocs:: generates the www.horde.org data for a component.
 *
 * PHP Version 8.2+
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

declare(strict_types=1);

namespace Horde\Components\Runner;

use Horde\Components\Component;
use Horde\Components\Helper\Website as HelperWebsite;

/**
 * Components_Runner_Webdocs:: generates the www.horde.org data for a component.
 *
 * Copyright 2011-2026 Horde LLC (http://www.horde.org/)
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
     * @param Component $component The component
     * @param array $options CLI options
     * @param HelperWebsite $websiteHelper The website helper
     */
    public function __construct(
        private readonly Component $component,
        private readonly array $options,
        private readonly HelperWebsite $websiteHelper
    ) {}

    public function run(): void
    {
        $this->websiteHelper->update(
            $this->component,
            $this->options
        );
    }
}
