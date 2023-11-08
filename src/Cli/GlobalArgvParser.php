<?php
declare(strict_types=1);
namespace Horde\Components\Cli;
use Horde\Components\ArgvWrapper;
use Horde_Argv_Option;
use Horde\Components\Constants;
use Horde_Argv_Values;

/**
 * The Global Argv Parser with the fixed, always present options.
 *
 * It seals the actual horde/argv Parser and only hands out copies.
 */
class GlobalArgvParser
{

    private array $arguments;
    private Horde_Argv_Values $options;
    public function __construct(
        /**
         * The command line argument parser.
         */
        private readonly \Horde_Argv_Parser $parser,
        private readonly ArgvWrapper $argv
    ) {
        $parser->addOption(
            new \Horde_Argv_Option(
                '-c',
                '--config',
                ['action' => 'store', 'help'   => sprintf(
                    'the path to the configuration file for the components script (default : %s).',
                    Constants::getConfigFile()
                ), 'default' => Constants::getConfigFile()]
            )
        );
        $parser->addOption(
            new \Horde_Argv_Option(
                '-q',
                '--quiet',
                ['action' => 'store_true', 'help'   => 'Reduce output to a minimum']
            )
        );
        $parser->addOption(
            new \Horde_Argv_Option(
                '-v',
                '--verbose',
                ['action' => 'store_true', 'help'   => 'Reduce output to a maximum']
            )
        );
        $parser->addOption(
            new \Horde_Argv_Option(
                '-P',
                '--pretend',
                ['action' => 'store_true', 'help'   => 'Just pretend and indicate what would be done rather than performing the action.']
            )
        );
        $parser->addOption(
            new \Horde_Argv_Option(
                '-N',
                '--nocolor',
                ['action' => 'store_true', 'help'   => 'Avoid colors in the output']
            )
        );
        $parser->addOption(
            new \Horde_Argv_Option(
                '-t',
                '--templatedir',
                ['action' => 'store', 'help'   => 'Location of a template directory that contains template definitions (see the data directory of this package to get an impression of which templates are available).']
            )
        );
        $parser->addOption(
            new \Horde_Argv_Option(
                '-D',
                '--destination',
                ['action' => 'store', 'help'   => 'Path to an (existing) destination directory where any output files will be placed.']
            )
        );
        $parser->addOption(
            new \Horde_Argv_Option(
                '-R',
                '--pearrc',
                ['action' => 'store', 'help'   => 'the path to the configuration of the PEAR installation you want to use for all PEAR based actions (leave empty to use your system default PEAR environment).']
            )
        );
        $parser->addOption(
            new \Horde_Argv_Option(
                '--allow-remote',
                ['action' => 'store_true', 'help'   => 'allow horde-components to access the remote https://pear.horde.org for dealing with stable releases. This option is not required in case you work locally in your git checkout and will only work for some actions that are able to operate on stable release packages.']
            )
        );
        $parser->addOption(
            new \Horde_Argv_Option(
                '-G',
                '--commit',
                ['action' => 'store_true', 'help'   => 'Commit any changes during the selected action to git.']
            )
        );
        $parser->addOption(
            new \Horde_Argv_Option(
                '--horde-root',
                ['action' => 'store', 'help'   => 'The root of the Horde git repository(ies).']
            )
        );
        $this->parse($this->argv);
    }

    function parse(ArgvWrapper $wrapper)
    {
        [$this->options, $this->arguments] = $this->parser->parseArgs(iterator_to_array($this->argv));
    }

    function parserError(string $error)
    {
        $this->parser->parserError($error);
    }
    function getArguments(): array
    {
        return $this->arguments;
    }

    function getOptions(): Horde_Argv_Values
    {
        return $this->options;
    }
}