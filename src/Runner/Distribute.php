<?php

/**
 * Components_Runner_Distribute:: prepares a distribution package for a
 * component.
 *
 * PHP version 8.2+
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

declare(strict_types=1);

namespace Horde\Components\Runner;

use Horde\Components\Config\Application as ConfigApplication;
use Horde\Components\Exception;
use Horde\Components\Output;

/**
 * Components_Runner_Distribute:: prepares a distribution package for a
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
class Distribute
{
    /**
     * Constructor.
     *
     * @param array $options CLI options
     * @param ConfigApplication $configApplication The application configuration
     * @param Output $output The output handler
     */
    public function __construct(
        private readonly array $options,
        private readonly ConfigApplication $configApplication,
        private readonly Output $output
    ) {}

    public function run(): void
    {
        $script = $this->configApplication->getTemplateDirectory() . '/components.php';
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
