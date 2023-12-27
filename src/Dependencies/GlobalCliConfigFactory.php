<?php
declare(strict_types=1);
namespace Horde\Components\Dependencies;

use Horde\Components\ArgvWrapper;
use Horde\Argv\Parser;

class GlobalCliConfigFactory
{
    public function __construct(ArgvWrapper $argv)
    {
        // Create a new parser
        $parser = new Parser(
        $parser->addOption(
            new \Horde\Argv\Option(
                '-c',
                '--config',
                ['action' => 'store', 'help'   => sprintf(
                    'the path to the configuration file for the components script (default : %s).',
                    Constants::getConfigFile()
                ), 'default' => Constants::getConfigFile()]
            )
        );
        $parser->addOption(
            new \Horde\Argv\Option(
                '-q',
                '--quiet',
                ['action' => 'store_true', 'help'   => 'Reduce output to a minimum']
            )
        );
        $parser->addOption(
            new \Horde\Argv\Option(
                '-v',
                '--verbose',
                ['action' => 'store_true', 'help'   => 'Reduce output to a maximum']
            )
        );
        $parser->addOption(
            new \Horde\Argv\Option(
                '-P',
                '--pretend',
                ['action' => 'store_true', 'help'   => 'Just pretend and indicate what would be done rather than performing the action.']
            )
        );
        $parser->addOption(
            new \Horde\Argv\Option(
                '-N',
                '--nocolor',
                ['action' => 'store_true', 'help'   => 'Avoid colors in the output']
            )
        );
        $parser->addOption(
            new \Horde\Argv\Option(
                '-d',
                '--working-dir=WORKING-DIR',
                ['action' => 'store_true', 'help'   => 'The working directory for the command']
            )
        );
        $this->parse(Ar$argv);
    }
}