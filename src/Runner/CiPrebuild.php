<?php

/**
 * Components_Runner_CiPrebuild:: prepares a continuous integration setup for a
 * component.
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

use Horde\Components\Config\Application as ConfigApplication;
use Horde\Components\Helper\Templates\RecursiveDirectory as HelperTemplatesRecursiveDirectory;

/**
 * Components_Runner_CiPrebuild:: prepares a continuous integration setup for a
 * component.
 *
 * Copyright 2010-2026 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class CiPrebuild
{
    /**
     * Constructor.
     *
     * @param array $options CLI options including ciprebuild path
     * @param ConfigApplication $configApplication The application configuration
     */
    public function __construct(
        private readonly array $options,
        private readonly ConfigApplication $configApplication
    ) {}

    public function run(): void
    {
        $templates = new HelperTemplatesRecursiveDirectory(
            $this->configApplication->getTemplateDirectory(),
            $this->options['ciprebuild']
        );
        $templates->write(['config' => $this->options]);
    }
}
