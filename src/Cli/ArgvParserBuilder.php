<?php

declare(strict_types=1);

namespace Horde\Components\Cli;

use Horde\Components\Constants;
use Horde\Argv\Parser;
use Horde\Argv\Option;
use Horde\Argv\OptionGroup;
use Horde\Argv\Values;
use Horde\Components\Module;
/**
 * The Global Argv Parser with the fixed, always present options.
 *
 * It seals the actual horde/argv Parser and only hands out copies.
 */
class ArgvParserBuilder
{
    public function __construct(
        /**
         * The command line argument parser.
         */
    ) {
        $this->reset();
    }

    public function reset(): self
    {
        $this->parser = new Parser(
            [
                'allowUnknownArgs' => true,
                'ignoreUnknownArgs' => true,
        ]);
        return $this;
    }

    public function withGlobalOptions(): self
    {
        $this->parser->addOption(
            new Option(
                '-c',
                '--config',
                ['action' => 'store', 'help'   => sprintf(
                    'the path to the configuration file for the components script (default : %s).',
                    Constants::getConfigFile()
                ), 'default' => Constants::getConfigFile()]
            )
        );
        $this->parser->addOption(
            new Option(
                '-q',
                '--quiet',
                ['action' => 'store_true', 'help'   => 'Reduce output to a minimum']
            )
        );
        $this->parser->addOption(
            new Option(
                '-v',
                '--verbose',
                ['action' => 'store_true', 'help'   => 'Reduce output to a maximum']
            )
        );
        $this->parser->addOption(
            new Option(
                '-P',
                '--pretend',
                ['action' => 'store_true', 'help'   => 'Just pretend and indicate what would be done rather than performing the action.']
            )
        );
        $this->parser->addOption(
            new Option(
                '-N',
                '--nocolor',
                ['action' => 'store_true', 'help'   => 'Avoid colors in the output']
            )
        );
        $this->parser->addOption(
            new Option(
                '-d',
                '--working-dir',
                ['action' => 'store', 'help'   => 'The working directory for the command']
            )
        );
        return $this;
    }

    public function withModuleOptions(Module $module): self
    {
        $group = new OptionGroup($this->parser, $module->getOptionGroupTitle(), $module->getOptionGroupDescription());
        $group->addOptions($module->getOptionGroupOptions());
        $this->parser->addOptionGroup($group);
        $this->parser->allowUnknownArgs = true;
        $this->parser->ignoreUnknownArgs = true;
        return $this;
    }

    public function build(): Parser
    {
        return $this->parser;
    }
}
