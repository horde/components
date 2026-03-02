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

use Horde\Components\ConfigProvider\ConfigProvider;
use Horde\Components\Constants;
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
     * @param ConfigProvider $configProvider The configuration provider
     * @param Output $output The output handler
     */
    public function __construct(
        private readonly array $options,
        private readonly ConfigProvider $configProvider,
        private readonly Output $output
    ) {}

    public function run(): void
    {
        $script = $this->getTemplateDirectory() . '/components.php';
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

    /**
     * Get the template directory path.
     *
     * @return string The template directory path
     */
    private function getTemplateDirectory(): string
    {
        if ($this->configProvider->hasSetting('templatedir')) {
            return $this->configProvider->getSetting('templatedir');
        }
        return Constants::getDataDirectory();
    }
}
