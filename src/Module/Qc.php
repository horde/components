<?php
/**
 * Components_Module_Qc:: checks the component for quality.
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
 * Components_Module_Qc:: checks the component for quality.
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
class Qc extends Base
{
    public function getOptionGroupTitle(): string
    {
        return 'Package quality control';
    }

    public function getOptionGroupDescription(): string
    {
        return 'This module runs a quality control check for the specified package.';
    }

    public function getOptionGroupOptions(): array
    {
        return [new \Horde_Argv_Option(
            '-Q',
            '--qc',
            ['action' => 'store_true', 'help'   => 'Check the package quality.']
        )];
    }

    /**
     * Get the usage title for this module.
     *
     * @return string The title.
     */
    public function getTitle(): string
    {
        return 'qc';
    }

    /**
     * Get the usage description for this module.
     *
     * @return string The description.
     */
    public function getUsage(): string
    {
        return 'Check the package quality.';
    }

    /**
     * Return the action arguments supported by this module.
     *
     * @return array A list of supported action arguments.
     */
    public function getActions(): array
    {
        return ['qc'];
    }

    /**
     * Return the help text for the specified action.
     *
     * @param string $action The action.
     *
     * @return string The help text.
     */
    public function getHelp($action): string
    {
        return 'Runs quality control checks for the component. This executes a number of automated quality control checks that are similar to the checks you find on ci.horde.org. In the most simple situation it will be sufficient to move to the directory of the component you wish to release and run

  horde-components qc

This will run all available checks. You can also choose to execute only some of the quality control checks. For that you need to indicate the desired checks after the "qc" keyword. Each argument indicates that the corresponding check should be run.

The available checks are:

 - unit: Runs the PHPUnit unit test suite of the component.
 - md  : Runs the PHP mess detector on the code of the component.
 - cs  : Runs a checkstyle analysis of the component.
 - lint: Runs a lint check of the source code.
 - loc : Measure the size and analyze the structure of the component.

The following example would solely run the PHPUnit test for the package:

  horde-components qc unit';
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
        $arguments = $config->getArguments();
        if (!empty($options['qc'])
            || (isset($arguments[0]) && $arguments[0] == 'qc')) {
            $this->_dependencies->getRunnerQc()->run();
            return true;
        }
        return false;
    }
}
