<?php

/**
 * Configure Runner - Interactive database connection configuration
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
 * Configure Runner - Interactive database connection configuration
 *
 * Prompts user for database connection parameters and saves to config file.
 * Supports SQLite, MySQL, and PostgreSQL with appropriate parameter sets.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class ConfigureRunner
{
    private const SUPPORTED_TYPES = ['sqlite', 'mysql', 'postgresql'];
    private const DEFAULT_TYPE = 'sqlite';

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
     * Run configure subcommand.
     *
     * @param array $options CLI options
     * @return int Exit code (0 = success, 1 = error)
     */
    public function run(array $options = []): int
    {
        $this->output->bold('=== Database Configuration ===');
        $this->output->plain('');

        // Show info about multi-database support
        $this->output->info('You can configure multiple database types for comprehensive testing');
        $this->output->info('Each database type is stored separately in config');
        $this->output->plain('');

        // Show security warning
        $this->showSecurityWarning();

        // Get database type from option or prompt
        $type = $this->getDatabaseType($options);

        // Prompt for parameters based on type
        $result = ($type === 'sqlite')
            ? $this->configureSqlite()
            : $this->configureServer($type);

        if ($result === 0) {
            // Ask if they want to configure another database type
            $this->output->plain('');
            return $this->promptConfigureAnother();
        }

        return $result;
    }

    /**
     * Get database type from options or prompt.
     *
     * @param array $options CLI options
     * @return string Database type
     */
    private function getDatabaseType(array $options): string
    {
        // Check if --db-type option provided (Horde_Argv uses underscores in keys)
        if (!empty($options['db_type'])) {
            $type = strtolower($options['db_type']);

            // Validate type
            if (!in_array($type, self::SUPPORTED_TYPES)) {
                $this->output->fail("Invalid database type: {$type}");
                $this->output->info('Valid types: ' . implode(', ', self::SUPPORTED_TYPES));
                exit(1);
            }

            $this->output->info("Using database type from --db-type: {$type}");
            $this->output->plain('');
            return $type;
        }

        // Prompt for database type
        return $this->promptDatabaseType();
    }

    /**
     * Show security warning about password storage.
     */
    private function showSecurityWarning(): void
    {
        $this->output->warn('Passwords will be stored in plain text in config file');
        $this->output->info('File permissions: 0600 (readable only by you)');
        $this->output->info('Location: ~/.config/horde/components.php');
        $this->output->plain('');
    }

    /**
     * Prompt for database type.
     *
     * @return string Selected database type
     */
    private function promptDatabaseType(): string
    {
        // Check which databases are already configured
        $configured = [];
        foreach (self::SUPPORTED_TYPES as $type) {
            if ($this->config->hasSetting("database.{$type}.name")) {
                $configured[] = $type;
            }
        }

        if (!empty($configured)) {
            $this->output->info('Already configured: ' . implode(', ', $configured));
            $this->output->plain('');
        }

        // Default to first unconfigured, or sqlite
        $defaultType = self::DEFAULT_TYPE;
        foreach (self::SUPPORTED_TYPES as $type) {
            if (!in_array($type, $configured)) {
                $defaultType = $type;
                break;
            }
        }

        // Find default index
        $defaultIndex = array_search($defaultType, self::SUPPORTED_TYPES);
        if ($defaultIndex === false) {
            $defaultIndex = 0;
        }

        $choice = $this->cli->prompt(
            'Database type to configure [sqlite/mysql/postgresql]:',
            self::SUPPORTED_TYPES,
            $defaultIndex
        );

        return self::SUPPORTED_TYPES[$choice];
    }

    /**
     * Configure SQLite database.
     *
     * @return int Exit code
     */
    private function configureSqlite(): int
    {
        $this->output->info('Configuring SQLite database...');
        $this->output->plain('');

        // Database name (file name without extension)
        $currentName = $this->config->hasSetting('database.sqlite.name')
            ? $this->config->getSetting('database.sqlite.name')
            : 'horde_ci';

        $name = $this->cli->prompt('Database name:', null, $currentName);
        $name = trim($name);

        // Database path (full path to .sqlite file)
        $defaultPath = getcwd() . '/build/' . $name . '.sqlite';
        $currentPath = $this->config->hasSetting('database.sqlite.path')
            ? $this->config->getSetting('database.sqlite.path')
            : $defaultPath;

        $path = $this->cli->prompt('SQLite file path:', null, $currentPath);
        $path = trim($path);

        // Ensure directory exists
        $dir = dirname($path);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0o755, true)) {
                $this->output->fail("Failed to create directory: {$dir}");
                return 1;
            }
            $this->output->ok("Created directory: {$dir}");
        }

        // Table prefix
        $prefix = $this->promptTablePrefix('sqlite');

        // Save to config with new structure
        $this->config->setSetting('database.sqlite.name', $name);
        $this->config->setSetting('database.sqlite.path', $path);
        $this->config->setSetting('database.sqlite.prefix', $prefix);

        $this->config->writeToDisk();

        $this->output->plain('');
        $this->output->ok('SQLite configuration saved');
        $this->showSavedConfig('sqlite');

        // Test connection
        return $this->testConnection('sqlite');
    }

    /**
     * Configure MySQL or PostgreSQL database.
     *
     * @param string $type Database type (mysql or postgresql)
     * @return int Exit code
     */
    private function configureServer(string $type): int
    {
        $this->output->info("Configuring {$type} database...");
        $this->output->plain('');

        // Database name
        $currentName = $this->config->hasSetting("database.{$type}.name")
            ? $this->config->getSetting("database.{$type}.name")
            : 'horde_ci';

        $name = $this->cli->prompt('Database name:', null, $currentName);
        $name = trim($name);

        // Host
        $currentHost = $this->config->hasSetting("database.{$type}.host")
            ? $this->config->getSetting("database.{$type}.host")
            : 'localhost';

        $host = $this->cli->prompt('Host:', null, $currentHost);
        $host = trim($host);

        // Port
        $defaultPort = $type === 'mysql' ? '3306' : '5432';
        $currentPort = $this->config->hasSetting("database.{$type}.port")
            ? $this->config->getSetting("database.{$type}.port")
            : $defaultPort;

        $port = $this->cli->prompt('Port:', null, $currentPort);
        $port = trim($port);

        // Validate port is numeric
        if (!ctype_digit($port)) {
            $this->output->fail("Port must be numeric: {$port}");
            return 1;
        }

        // Table prefix
        $prefix = $this->promptTablePrefix($type);

        // Save to config with new structure
        $this->config->setSetting("database.{$type}.name", $name);
        $this->config->setSetting("database.{$type}.host", $host);
        $this->config->setSetting("database.{$type}.port", $port);
        $this->config->setSetting("database.{$type}.prefix", $prefix);

        $this->config->writeToDisk();

        $this->output->plain('');
        $this->output->ok("{$type} configuration saved");
        $this->showSavedConfig($type);

        // Test connection
        return $this->testConnection($type);
    }

    /**
     * Prompt for table prefix.
     *
     * @param string $type Database type
     * @return string Table prefix
     */
    private function promptTablePrefix(string $type): string
    {
        $currentPrefix = $this->config->hasSetting("database.{$type}.prefix")
            ? $this->config->getSetting("database.{$type}.prefix")
            : 'test_components_';

        $prefix = $this->cli->prompt('Table prefix:', null, $currentPrefix);
        return trim($prefix);
    }

    /**
     * Show saved configuration summary.
     *
     * @param string $type Database type
     */
    private function showSavedConfig(string $type): void
    {
        $this->output->plain('');
        $this->output->bold('Configuration Summary:');

        $this->output->plain("  Type:   {$type}");

        $name = $this->config->getSetting("database.{$type}.name");
        $this->output->plain("  Name:   {$name}");

        if ($type === 'sqlite') {
            $path = $this->config->getSetting("database.{$type}.path");
            $this->output->plain("  Path:   {$path}");
        } else {
            $host = $this->config->getSetting("database.{$type}.host");
            $port = $this->config->getSetting("database.{$type}.port");
            $this->output->plain("  Host:   {$host}");
            $this->output->plain("  Port:   {$port}");
        }

        $prefix = $this->config->getSetting("database.{$type}.prefix");
        $this->output->plain("  Prefix: {$prefix}");

        $this->output->plain('');
    }

    /**
     * Test database connection.
     *
     * @param string $type Database type
     * @return int Exit code (0 = success, 1 = error)
     */
    private function testConnection(string $type): int
    {
        $this->output->info('Testing database connection...');

        try {
            $manager = new DatabaseManager($this->config, $this->output, $type);

            if ($manager->testConnection()) {
                $this->output->ok('Connection successful! ✓');
                $this->output->plain('');
                $this->output->info('Next steps:');
                $this->output->info('  1. Configure users: horde-components database user ' . $type);
                $this->output->info('  2. Install database: horde-components database install');
                return 0;
            } else {
                $this->output->warn('Connection test returned false');
                $this->showTroubleshooting($type);
                return 1;
            }
        } catch (Exception $e) {
            $this->output->fail('Connection failed: ' . $e->getMessage());
            $this->showTroubleshooting($type);
            return 1;
        }
    }

    /**
     * Show troubleshooting tips.
     *
     * @param string $type Database type
     */
    private function showTroubleshooting(string $type): void
    {
        $this->output->plain('');
        $this->output->bold('Troubleshooting:');

        if ($type === 'sqlite') {
            $this->output->info('  1. Check directory is writable');
            $this->output->info('  2. Ensure sqlite3 extension is installed: php -m | grep sqlite3');
            $path = $this->config->getSetting("database.{$type}.path");
            $this->output->info("  3. Try creating file manually: touch {$path}");
        } else {
            $this->output->info('  1. Check database server is running');

            if ($type === 'mysql') {
                $this->output->info('     systemctl status mysql');
            } else {
                $this->output->info('     systemctl status postgresql');
            }

            $this->output->info('  2. Verify connection manually:');

            $host = $this->config->getSetting("database.{$type}.host");
            $name = $this->config->getSetting("database.{$type}.name");

            if ($type === 'mysql') {
                $this->output->info("     mysql -h {$host} -u root -p {$name}");
            } else {
                $this->output->info("     psql -h {$host} -U postgres {$name}");
            }

            $this->output->info('  3. Ensure PHP extension is installed:');
            $ext = $type === 'mysql' ? 'mysqli' : 'pgsql';
            $this->output->info("     php -m | grep {$ext}");
        }

        $this->output->info('  4. Check config: cat ~/.config/horde/components.php');
    }

    /**
     * Prompt to configure another database type.
     *
     * @return int Exit code
     */
    private function promptConfigureAnother(): int
    {
        $choices = ['y', 'n'];
        $response = $this->cli->prompt(
            'Configure another database type? [y/n]:',
            $choices,
            1  // Default to 'n'
        );

        if ($choices[$response] === 'y') {
            $this->output->plain('');
            return $this->run();  // Recursively run again
        }

        return 0;
    }
}
