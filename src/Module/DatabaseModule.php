<?php

/**
 * Database Module - Manage database installation and configuration
 *
 * PHP version 8.2+
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

declare(strict_types=1);

namespace Horde\Components\Module;

use Horde\Argv\Option;
use Horde\Components\Component;
use Horde\Components\Output;
use Horde\Components\Runner\DatabaseRunner;
use Horde\Components\ConfigProvider\PhpConfigFileProvider;
use Horde_Cli;

/**
 * Database Module - Manage database installation and configuration
 *
 * Provides interactive commands for:
 * - Installing database software and PHP drivers
 * - Configuring database connections
 * - Managing database users and privileges
 * - Cleaning up test tables
 *
 * Copyright 2026 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class DatabaseModule extends Base
{
    public function getOptionGroupTitle(): string
    {
        return 'Database';
    }

    public function getOptionGroupDescription(): string
    {
        return 'Manage database installation, configuration, and lifecycle';
    }

    public function getOptionGroupOptions(): array
    {
        return [
            new Option(
                '--db-type',
                [
                    'action' => 'store',
                    'help'   => 'Database type: sqlite, mysql, or postgresql',
                ]
            ),
            new Option(
                '--user-type',
                [
                    'action' => 'store',
                    'help'   => 'User privilege level: admin, power, or restricted',
                ]
            ),
            new Option(
                '--auto-password',
                [
                    'action' => 'store_true',
                    'help'   => 'Automatically generate random password (skip prompt)',
                ]
            ),
        ];
    }

    /**
     * Get the usage title for this module.
     *
     * @return string The title.
     */
    public function getTitle(): string
    {
        return 'database';
    }

    /**
     * Get the usage description for this module.
     *
     * @return string The description.
     */
    public function getUsage(): string
    {
        return <<<'HELP'
            Manage database software installation, configuration, and lifecycle for testing.

            This module helps set up and manage databases for CI pipelines and local development.
            Supports SQLite, MySQL, and PostgreSQL with interactive configuration.

            USAGE:
                horde-components database <SUBCOMMAND>
                horde-components db <SUBCOMMAND>

            SUBCOMMANDS:
                install       Install database software and PHP drivers
                              Generates an idempotent Ubuntu 24.04 install script
                              Creates database users from config if configured
                              Executes script if running with sudo privileges

                configure     Configure database connection parameters
                              Interactive prompts for type, name, host, port, prefix
                              Saves configuration to ~/.config/horde/components.php
                              Tests connection after configuration

                user          Configure database user credentials
                              Three privilege levels: admin, power, restricted
                              Generates random passwords if not provided
                              Saves credentials to config (used by install)

                drop          Drop all tables matching configured prefix
                              Lists tables before dropping
                              Requires explicit confirmation
                              Useful for cleaning up after tests

            WORKFLOW - Fresh Setup:
                # 1. Configure database connection
                horde-components database configure

                # 2. Configure users (credentials saved to config)
                horde-components database user

                # 3. Install database software and create users
                horde-components database install

                # 4. Run your tests...

                # 5. Clean up after tests
                horde-components database drop

            WORKFLOW - Existing Database:
                # 1. Configure connection to existing database
                horde-components database configure

                # 2. Use existing users (no install needed)

                # 3. Run your tests...

                # 4. Clean up only test tables
                horde-components database drop

            CONFIGURATION:
                Configuration is stored in: ~/.config/horde/components.php

                Connection parameters:
                    database.type      Database type (sqlite, mysql, postgresql)
                    database.name      Database name
                    database.host      Host (not for SQLite)
                    database.port      Port (not for SQLite)
                    database.prefix    Table prefix for test tables
                    database.path      File path (SQLite only)

                User credentials (three levels):
                    database.user.admin.username       Admin user (full privileges)
                    database.user.admin.password       Admin password
                    database.user.power.username       Power user (DDL + DML)
                    database.user.power.password       Power password
                    database.user.restricted.username  Restricted user (DML only)
                    database.user.restricted.password  Restricted password

            EXAMPLES:
                # SQLite setup (simplest)
                horde-components database configure
                # Choose: sqlite
                # Database file: build/horde_ci.sqlite
                # Prefix: test_

                # MySQL setup with users
                horde-components database configure
                # Choose: mysql
                # Name: horde_ci
                # Host: localhost
                # Port: 3306
                # Prefix: test_components_

                horde-components database user
                # Choose privilege level: admin
                # Username: ci_admin
                # Password: (empty for random)

                horde-components database install
                # Generates script, installs MySQL, creates users

            PRIVILEGE LEVELS:
                admin       Full database control (ALL PRIVILEGES)
                            Use for: Database setup, migrations, schema changes

                power       Schema modification allowed (DDL + DML)
                            Privileges: SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, INDEX, ALTER
                            Use for: Tests that create/drop tables

                restricted  Data access only (DML)
                            Privileges: SELECT, INSERT, UPDATE, DELETE
                            Use for: Tests that only read/write data

            SECURITY NOTE:
                Passwords are stored in plain text in the config file.
                File permissions: 0600 (readable only by you)
                This is acceptable for development/CI tools.

            SEE ALSO:
                horde-components config     View/edit configuration
                horde-components ci setup   Setup CI environment
            HELP;
    }

    /**
     * Get a short one-line description for command listings.
     *
     * @return string The short description.
     */
    public function getShortDescription(): string
    {
        return 'Manage database setup for testing';
    }

    /**
     * Return the action arguments supported by this module.
     *
     * @return array A list of supported action arguments.
     */
    public function getActions(): array
    {
        return ['database', 'db'];
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
        return $this->getUsage();
    }

    /**
     * Return the options that should be explained in the context help.
     *
     * @return array A list of option help texts.
     */
    public function getContextOptionHelp(): array
    {
        return [];
    }

    /**
     * Determine if this module should act. Run all required actions if it has
     * been instructed to do so.
     *
     * @param array $options CLI options
     * @param array $arguments CLI arguments
     * @param Component|null $component The selected component (if any)
     *
     * @return bool True if the module performed some action.
     */
    public function handle(array $options, array $arguments, ?Component $component = null): bool
    {
        // Check if this module should handle the request
        if (empty($arguments[0]) || !in_array($arguments[0], ['database', 'db'])) {
            return false;
        }

        // Get dependencies
        $output = $this->dependencies->get(Output::class);
        $configFile = $this->dependencies->get(PhpConfigFileProvider::class);
        $cli = $this->dependencies->get(Horde_Cli::class);

        // Create and run runner
        $runner = new DatabaseRunner(
            $cli,
            $output,
            $configFile
        );

        $runner->run($arguments, $options);

        return true;
    }
}
