<?php
/**
 * Copyright 2013-2020 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category  Horde
 * @copyright 2013-2020 Horde LLC
 * @license   http://www.horde.org/licenses/lgpl21 LGPL 2.1
 * @package   Components
 */

namespace Horde\Components\Runner;

use Horde\Components\Config;
use Horde\Components\Helper\Composer as HelperComposer;
use Horde\Components\Output;

/**
 * Generate config file for use with PHP Composer.
 *
 * @author    Michael Slusarz <slusarz@horde.org>
 * @category  Horde
 * @copyright 2013-2020 Horde LLC
 * @license   http://www.horde.org/licenses/lgpl21 LGPL 2.1
 * @package   Components
 */
class Composer
{
    /**
    * Constructor.
    *
     * @param Config $_config The configuration for the current
                                 job.
     * @param Output $_output The output handler.
    */
    public function __construct(private readonly Output $_output)
    {

    }

    public function run(Config $config): void
    {
        $composer = new HelperComposer();
        $options = $config->getOptions();

        $options['logger'] = $this->_output;
        // We need to set the component first
        $composer->generateComposerJson(
            $config->getComponent()->getHordeYml(),
            $options
        );
    }
}
