<?php
/**
 * Components_Module_CiSetup:: generates the configuration for Hudson based
 * continuous integration of a Horde PEAR package.
 *
 * PHP Version 7
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

namespace Horde\Components\Module;

use Horde\Components\Config;

/**
 * Components_Module_CiSetup:: generates the configuration for Hudson based
 * continuous integration of a Horde PEAR package.
 *
 * Copyright 2010-2024 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class CiSetup extends Base
{
    public function getOptionGroupTitle(): string
    {
        return 'Continuous Integration Setup';
    }

    public function getOptionGroupDescription(): string
    {
        return 'This module generates the configuration for Hudson based continuous integration of a Horde PEAR package.';
    }

    public function getOptionGroupOptions(): array
    {
        return [new \Horde\Argv\Option(
            '--cisetup',
            ['action' => 'store', 'help'   => 'generate the basic Hudson project configuration for a Horde PEAR package in CISETUP']
        ), new \Horde\Argv\Option(
            '--ciprebuild',
            ['action' => 'store', 'help'   => 'generate the Hudson build configuration for a Horde PEAR package in CIPREBUILD']
        ), new \Horde\Argv\Option(
            '-T',
            '--toolsdir',
            ['action' => 'store', 'help'   => 'the path to the PEAR installation holding the required analysis tools']
        )];
    }

    /**
     * Determine if this module should act. Run all required actions if it has
     * been instructed to do so.
     *
     * @param Config $config The configuration.
     *
     * @return bool True if the module performed some action.
     */
    public function handle(Config $config): bool
    {
        $options = $config->getOptions();
        //@todo Split into two different runners here
        if (!empty($options['cisetup'])) {
            $this->dependencies->getRunnerCiSetup()->run();
            return true;
        }
        if (!empty($options['ciprebuild'])) {
            $this->dependencies->getRunnerCiPrebuild()->run();
            return true;
        }
        return false;
    }
}
