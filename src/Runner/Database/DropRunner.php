<?php

/**
 * Drop Runner - Drop database tables matching prefix
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

namespace Horde\Components\Runner\Database;

use Horde\Components\Output;
use Horde\Components\ConfigProvider\PhpConfigFileProvider;
use Horde\Components\Helper\Database\DatabaseManager;
use Horde_Cli;
use Exception;

/**
 * Drop Runner - Drop database tables matching prefix
 *
 * Lists tables matching configured prefix and prompts for confirmation
 * before dropping them. Safety-first approach for cleaning up test data.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class DropRunner
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
     * Run drop subcommand.
     *
     * @param array $options CLI options
     * @return int Exit code (0 = success, 1 = error)
     */
    public function run(array $options = []): int
    {
        $this->output->bold('=== Drop Database Tables ===');
        $this->output->plain('');

        // Detect configured databases
        $configured = $this->getConfiguredDatabases();

        if (empty($configured)) {
            $this->output->fail('No databases configured. Run: horde-components database configure');
            return 1;
        }

        // Get database type from option or prompt
        $type = $this->getDatabaseType($options, $configured);

        // Get prefix from config
        if (!$this->config->hasSetting("database.{$type}.prefix")) {
            $this->output->fail("Table prefix not configured for {$type}. Run: horde-components database configure");
            return 1;
        }

        $prefix = $this->config->getSetting("database.{$type}.prefix");

        // Create database manager
        try {
            $manager = new DatabaseManager($this->config, $this->output, $type);
        } catch (Exception $e) {
            $this->output->fail('Failed to create database connection: ' . $e->getMessage());
            return 1;
        }

        // Test connection
        if (!$manager->testConnection()) {
            $this->output->fail('Cannot connect to database. Check configuration and credentials.');
            $this->output->info('Run: horde-components database configure');
            return 1;
        }

        // List tables matching prefix
        $this->output->info("Searching for tables with prefix: {$prefix}");
        $this->output->plain('');

        try {
            $tables = $manager->listTables($prefix);
        } catch (Exception $e) {
            $this->output->fail('Failed to list tables: ' . $e->getMessage());
            return 1;
        }

        if (empty($tables)) {
            $this->output->ok('No tables found with prefix: ' . $prefix);
            $this->output->plain('');
            $this->output->info('Database is clean - nothing to drop');
            return 0;
        }

        // Show tables to be dropped
        $this->output->warn('Found ' . count($tables) . ' table(s) to drop:');
        $this->output->plain('');

        foreach ($tables as $table) {
            $this->output->plain("  - {$table}");
        }

        $this->output->plain('');

        // Show warning
        $this->showWarning();

        // Confirm deletion
        if (!$this->confirmDrop($tables)) {
            $this->output->info('Operation cancelled - no tables were dropped');
            return 0;
        }

        // Drop tables
        return $this->dropTables($manager, $tables);
    }

    /**
     * Get database type from options or prompt.
     *
     * @param array $options CLI options
     * @param array $configured List of configured databases
     * @return string Database type
     */
    private function getDatabaseType(array $options, array $configured): string
    {
        // Check if --db-type option provided
        if (!empty($options['db_type'])) {
            $type = strtolower($options['db_type']);

            // Validate type
            if (!in_array($type, ['sqlite', 'mysql', 'postgresql'])) {
                $this->output->fail("Invalid database type: {$type}");
                $this->output->info('Valid types: sqlite, mysql, postgresql');
                exit(1);
            }

            // Check if configured
            if (!in_array($type, $configured)) {
                $this->output->fail("{$type} database not configured. Run: horde-components database configure --db-type={$type}");
                exit(1);
            }

            $this->output->info("Using database type from --db-type: {$type}");
            $this->output->plain('');
            return $type;
        }

        // Prompt for database type
        return $this->promptDatabaseType($configured);
    }

    /**
     * Show warning about destructive operation.
     */
    private function showWarning(): void
    {
        $this->output->warn('⚠️  WARNING: This operation is DESTRUCTIVE and IRREVERSIBLE');
        $this->output->warn('⚠️  All data in these tables will be permanently deleted');
        $this->output->plain('');
    }

    /**
     * Confirm table drop with user.
     *
     * @param array $tables List of tables to drop
     * @return bool True if user confirmed
     */
    private function confirmDrop(array $tables): bool
    {
        $tableCount = count($tables);

        $this->output->info('Type the number of tables to confirm deletion:');
        $expectedInput = (string) $tableCount;

        $response = $this->cli->prompt("Type '{$expectedInput}' to confirm", null, '');
        $response = trim($response);

        if ($response !== $expectedInput) {
            return false;
        }

        // Double confirmation for large numbers
        if ($tableCount > 10) {
            $this->output->plain('');
            $this->output->warn('You are about to drop ' . $tableCount . ' tables');

            $choices = ['y', 'n'];
            $confirm = $this->cli->prompt(
                'Are you absolutely sure? [y/n]:',
                $choices,
                1  // Default to 'n'
            );

            return $choices[$confirm] === 'y';
        }

        return true;
    }

    /**
     * Drop tables from database.
     *
     * @param DatabaseManager $manager Database manager
     * @param array $tables List of tables to drop
     * @return int Exit code
     */
    private function dropTables(DatabaseManager $manager, array $tables): int
    {
        $this->output->plain('');
        $this->output->info('Dropping tables...');
        $this->output->plain('');

        $dropped = 0;
        $failed = 0;

        foreach ($tables as $table) {
            $this->output->plain("Dropping: {$table}...", false);

            if ($manager->dropTable($table)) {
                $this->output->ok(' ✓');
                $dropped++;
            } else {
                $this->output->fail(' ✗');
                $failed++;
            }
        }

        $this->output->plain('');

        // Show summary
        if ($failed === 0) {
            $this->output->ok("Successfully dropped {$dropped} table(s)");
            return 0;
        } elseif ($dropped > 0) {
            $this->output->warn("Dropped {$dropped} table(s), {$failed} failed");
            return 1;
        } else {
            $this->output->fail("Failed to drop all {$failed} table(s)");
            return 1;
        }
    }

    /**
     * Get list of configured database types.
     *
     * @return array List of configured database types
     */
    private function getConfiguredDatabases(): array
    {
        $configured = [];
        $types = ['sqlite', 'mysql', 'postgresql'];

        foreach ($types as $type) {
            if ($this->config->hasSetting("database.{$type}.name")) {
                $configured[] = $type;
            }
        }

        return $configured;
    }

    /**
     * Prompt for database type to drop tables from.
     *
     * @param array $configured List of configured database types
     * @return string Selected database type
     */
    private function promptDatabaseType(array $configured): string
    {
        // If only one database configured, use it
        if (count($configured) === 1) {
            $type = $configured[0];
            $this->output->info("Using configured database: {$type}");
            $this->output->plain('');
            return $type;
        }

        // Multiple databases - prompt user
        $this->output->warn('Multiple databases configured - choose carefully!');
        $this->output->info('Databases: ' . implode(', ', $configured));
        $this->output->plain('');

        $defaultIndex = 0;
        $choice = $this->cli->prompt(
            'Which database to drop tables from? [' . implode('/', $configured) . ']:',
            $configured,
            $defaultIndex
        );

        return $configured[$choice];
    }
}
