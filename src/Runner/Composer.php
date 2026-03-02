<?php

/**
 * Copyright 2013-2026 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category  Horde
 * @copyright 2013-2026 Horde LLC
 * @license   http://www.horde.org/licenses/lgpl21 LGPL 2.1
 * @package   Components
 */

declare(strict_types=1);

namespace Horde\Components\Runner;

use Horde\Components\Component;
use Horde\Components\Helper\Composer as HelperComposer;
use Horde\Components\Output;

/**
 * Generate config file for use with PHP Composer.
 *
 * @author    Michael Slusarz <slusarz@horde.org>
 * @category  Horde
 * @copyright 2013-2026 Horde LLC
 * @license   http://www.horde.org/licenses/lgpl21 LGPL 2.1
 * @package   Components
 */
class Composer
{
    /**
    * Constructor.
    *
     * @param Component $component The component
     * @param array $options CLI options
     * @param Output $output The output handler
    */
    public function __construct(
        private readonly Component $component,
        private readonly array $options,
        private readonly Output $output
    ) {}

    public function run(): void
    {
        $composer = new HelperComposer();
        $options = $this->options;

        $options['logger'] = $this->output;
        // We need to set the component first
        $composer->generateComposerJson(
            $this->component->getHordeYml(),
            $options
        );
    }
}
