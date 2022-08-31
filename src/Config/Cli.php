<?php
/**
 * Components_Config_Cli:: class provides central options for the command line
 * configuration of the components tool.
 *
 * PHP Version 7
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

namespace Horde\Components\Config;

use Horde\Components\Constants;

/**
 * Config\Cli:: class provides central options for the command line
 * configuration of the components tool.
 *
 * Copyright 2009-2020 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Cli extends Base
{
    /**
     * Constructor.
     *
     */
    public function __construct(
        /**
         * The command line argument parser.
         */
        private readonly \Horde_Argv_Parser $_parser
    ) {
        $_parser->addOption(
            new \Horde_Argv_Option(
                '-c',
                '--config',
                ['action' => 'store', 'help'   => sprintf(
                    'the path to the configuration file for the components script (default : %s).',
                    Constants::getConfigFile()
                ), 'default' => Constants::getConfigFile()]
            )
        );
        $_parser->addOption(
            new \Horde_Argv_Option(
                '-q',
                '--quiet',
                ['action' => 'store_true', 'help'   => 'Reduce output to a minimum']
            )
        );
        $_parser->addOption(
            new \Horde_Argv_Option(
                '-v',
                '--verbose',
                ['action' => 'store_true', 'help'   => 'Reduce output to a maximum']
            )
        );
        $_parser->addOption(
            new \Horde_Argv_Option(
                '-P',
                '--pretend',
                ['action' => 'store_true', 'help'   => 'Just pretend and indicate what would be done rather than performing the action.']
            )
        );
        $_parser->addOption(
            new \Horde_Argv_Option(
                '-N',
                '--nocolor',
                ['action' => 'store_true', 'help'   => 'Avoid colors in the output']
            )
        );
        $_parser->addOption(
            new \Horde_Argv_Option(
                '-t',
                '--templatedir',
                ['action' => 'store', 'help'   => 'Location of a template directory that contains template definitions (see the data directory of this package to get an impression of which templates are available).']
            )
        );
        $_parser->addOption(
            new \Horde_Argv_Option(
                '-D',
                '--destination',
                ['action' => 'store', 'help'   => 'Path to an (existing) destination directory where any output files will be placed.']
            )
        );
        $_parser->addOption(
            new \Horde_Argv_Option(
                '-R',
                '--pearrc',
                ['action' => 'store', 'help'   => 'the path to the configuration of the PEAR installation you want to use for all PEAR based actions (leave empty to use your system default PEAR environment).']
            )
        );
        $_parser->addOption(
            new \Horde_Argv_Option(
                '--allow-remote',
                ['action' => 'store_true', 'help'   => 'allow horde-components to access the remote https://pear.horde.org for dealing with stable releases. This option is not required in case you work locally in your git checkout and will only work for some actions that are able to operate on stable release packages.']
            )
        );
        $_parser->addOption(
            new \Horde_Argv_Option(
                '-G',
                '--commit',
                ['action' => 'store_true', 'help'   => 'Commit any changes during the selected action to git.']
            )
        );
        $_parser->addOption(
            new \Horde_Argv_Option(
                '--horde-root',
                ['action' => 'store', 'help'   => 'The root of the Horde git repository(ies).']
            )
        );
        [$this->_options, $this->_arguments] = $this->_parser->parseArgs();
    }
}
