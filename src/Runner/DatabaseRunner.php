<?php

/**
 * Database Runner - Orchestrates database management subcommands
 *
 * Copyright 2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

declare(strict_types=1);

namespace Horde\Components\Runner;

use Horde\Components\Output;
use Horde\Components\ConfigProvider\PhpConfigFileProvider;
use Horde\Components\Runner\Database\ConfigureRunner;
use Horde\Components\Runner\Database\UserRunner;
use Horde\Components\Runner\Database\DropRunner;
use Horde\Components\Runner\Database\DbInstallRunner;
use Horde_Cli;

/**
 * Database Runner - Orchestrates database management subcommands
 *
 * Routes to specific subcommand runners based on user input.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class DatabaseRunner
{
    /**
     * Constructor.
     *
     * @param Horde_Cli $cli CLI interaction handler
     * @param Output $output Output handler
     * @param PhpConfigFileProvider $config Configuration provider
     */
    public function __construct(
        private readonly Horde_Cli $cli,
        private readonly Output $output,
        private readonly PhpConfigFileProvider $config
    ) {}

    /**
     * Run database management command.
     *
     * Routes to appropriate subcommand runner based on arguments.
     *
     * @param array $arguments Command line arguments
     * @param array $options CLI options
     * @return int Exit code (0 = success, 1 = error)
     */
    public function run(array $arguments, array $options = []): int
    {
        // Arguments: ['database', 'subcommand', ...]
        $subcommand = $arguments[1] ?? null;

        return match ($subcommand) {
            'install' => $this->runInstall($options),
            'configure' => $this->runConfigure($options),
            'user' => $this->runUser($options),
            'drop' => $this->runDrop($options),
            null => $this->showHelp(),
            default => $this->unknownSubcommand($subcommand),
        };
    }

    /**
     * Run install subcommand.
     *
     * @param array $options CLI options
     * @return int Exit code
     */
    private function runInstall(array $options): int
    {
        $runner = new DbInstallRunner($this->cli, $this->output, $this->config);
        return $runner->run($options);
    }

    /**
     * Run configure subcommand.
     *
     * @param array $options CLI options
     * @return int Exit code
     */
    private function runConfigure(array $options): int
    {
        $runner = new ConfigureRunner($this->cli, $this->output, $this->config);
        return $runner->run($options);
    }

    /**
     * Run user subcommand.
     *
     * @param array $options CLI options
     * @return int Exit code
     */
    private function runUser(array $options): int
    {
        $runner = new UserRunner($this->cli, $this->output, $this->config);
        return $runner->run($options);
    }

    /**
     * Run drop subcommand.
     *
     * @param array $options CLI options
     * @return int Exit code
     */
    private function runDrop(array $options): int
    {
        $runner = new DropRunner($this->cli, $this->output, $this->config);
        return $runner->run($options);
    }

    /**
     * Show help text.
     *
     * @return int Exit code
     */
    private function showHelp(): int
    {
        $this->output->bold('Database Module - Manage database lifecycle');
        $this->output->plain('');
        $this->output->plain('USAGE:');
        $this->output->plain('    horde-components database <SUBCOMMAND>');
        $this->output->plain('    horde-components db <SUBCOMMAND>');
        $this->output->plain('');
        $this->output->plain('SUBCOMMANDS:');
        $this->output->plain('    install       Install database software and PHP drivers');
        $this->output->plain('    configure     Configure database connection parameters');
        $this->output->plain('    user          Configure database user credentials');
        $this->output->plain('    drop          Drop all tables matching configured prefix');
        $this->output->plain('');
        $this->output->plain('EXAMPLES:');
        $this->output->plain('    horde-components database configure');
        $this->output->plain('    horde-components database user');
        $this->output->plain('    horde-components database install');
        $this->output->plain('    horde-components database drop');
        $this->output->plain('');
        $this->output->plain('For detailed help, run:');
        $this->output->plain('    horde-components help database');

        return 0;
    }

    /**
     * Handle unknown subcommand.
     *
     * @param string $subcommand The unknown subcommand
     * @return int Exit code
     */
    private function unknownSubcommand(string $subcommand): int
    {
        $this->output->warn("Unknown subcommand: {$subcommand}");
        $this->output->plain('');
        $this->output->plain('Valid subcommands: install, configure, user, drop');
        $this->output->plain('');
        $this->output->plain('Run for help: horde-components database');

        return 1;
    }
}
