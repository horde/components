<?php

/**
 * User Runner - Interactive database user configuration
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
use Horde_Cli;

/**
 * User Runner - Interactive database user configuration
 *
 * Prompts for database user credentials with three privilege levels:
 * - admin: Full database control (ALL PRIVILEGES)
 * - power: Schema modification (DDL + DML)
 * - restricted: Data access only (DML)
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class UserRunner
{
    private const PRIVILEGE_LEVELS = ['admin', 'power', 'restricted'];

    private const PRIVILEGE_DESCRIPTIONS = [
        'admin' => 'Full database control (ALL PRIVILEGES) - for setup and migrations',
        'power' => 'Schema modification (DDL + DML) - for tests that create/drop tables',
        'restricted' => 'Data access only (DML) - for tests that only read/write data',
    ];

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
     * Run user configuration subcommand.
     *
     * @param array $options CLI options
     * @return int Exit code (0 = success, 1 = error)
     */
    public function run(array $options = []): int
    {
        $this->output->bold('=== Database User Configuration ===');
        $this->output->plain('');

        // Detect configured databases
        $configured = $this->getConfiguredDatabases();

        if (empty($configured)) {
            $this->output->fail('No databases configured. Run: horde-components database configure');
            return 1;
        }

        // Get database type from option or prompt
        $type = $this->getDatabaseType($options, $configured);

        // SQLite doesn't need user configuration
        if ($type === 'sqlite') {
            $this->output->info('SQLite does not require user configuration (uses file permissions)');
            return 0;
        }

        // Show security warning
        $this->showSecurityWarning();

        // Show privilege level descriptions
        $this->showPrivilegeLevelInfo();

        // Get privilege level from option or prompt
        $level = $this->getPrivilegeLevel($options, $type);

        // Configure the selected privilege level
        return $this->configureUser($type, $level, $options);
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
     * Get privilege level from options or prompt.
     *
     * @param array $options CLI options
     * @param string $type Database type
     * @return string Privilege level
     */
    private function getPrivilegeLevel(array $options, string $type): string
    {
        // Check if --user-type option provided
        if (!empty($options['user_type'])) {
            $level = strtolower($options['user_type']);

            // Validate level
            if (!in_array($level, self::PRIVILEGE_LEVELS)) {
                $this->output->fail("Invalid user type: {$level}");
                $this->output->info('Valid types: ' . implode(', ', self::PRIVILEGE_LEVELS));
                exit(1);
            }

            $this->output->info("Using privilege level from --user-type: {$level}");
            $this->output->plain('');
            return $level;
        }

        // Prompt for privilege level
        return $this->promptPrivilegeLevel($type);
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
     * Prompt for database type to configure.
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
        $this->output->info('Multiple databases configured: ' . implode(', ', $configured));
        $this->output->plain('');

        $defaultIndex = 0;
        $choice = $this->cli->prompt(
            'Which database type to configure users for? [' . implode('/', $configured) . ']:',
            $configured,
            $defaultIndex
        );

        return $configured[$choice];
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
     * Show privilege level information.
     */
    private function showPrivilegeLevelInfo(): void
    {
        $this->output->bold('Available Privilege Levels:');
        $this->output->plain('');

        foreach (self::PRIVILEGE_LEVELS as $level) {
            $description = self::PRIVILEGE_DESCRIPTIONS[$level];
            $this->output->info("  {$level}: {$description}");
        }

        $this->output->plain('');
        $this->output->info('You can configure multiple levels (e.g., admin for setup, restricted for tests)');
        $this->output->info('Or configure just one level for all operations');
        $this->output->plain('');
    }

    /**
     * Prompt for privilege level to configure.
     *
     * @param string $type Database type
     * @return string Selected privilege level
     */
    private function promptPrivilegeLevel(string $type): string
    {
        // Find default based on what's already configured
        $defaultIndex = 0;

        // Prefer admin if nothing configured, otherwise select first configured
        if ($this->config->hasSetting("database.{$type}.user.admin.username")) {
            $defaultIndex = 0;
        } elseif ($this->config->hasSetting("database.{$type}.user.power.username")) {
            $defaultIndex = 1;
        } elseif ($this->config->hasSetting("database.{$type}.user.restricted.username")) {
            $defaultIndex = 2;
        }

        $choice = $this->cli->prompt(
            'Which privilege level to configure? [admin/power/restricted]:',
            self::PRIVILEGE_LEVELS,
            $defaultIndex
        );

        return self::PRIVILEGE_LEVELS[$choice];
    }

    /**
     * Configure user for specific privilege level.
     *
     * @param string $type Database type
     * @param string $level Privilege level (admin, power, restricted)
     * @param array $options CLI options
     * @return int Exit code
     */
    private function configureUser(string $type, string $level, array $options): int
    {
        $this->output->info("Configuring {$level} user for {$type}...");
        $this->output->plain('');

        // Get database name for username suggestion
        $dbName = $this->config->getSetting("database.{$type}.name");

        // Suggest username based on database name and level
        $suggestedUsername = $this->suggestUsername($dbName, $level);

        $currentUsername = $this->config->hasSetting("database.{$type}.user.{$level}.username")
            ? $this->config->getSetting("database.{$type}.user.{$level}.username")
            : $suggestedUsername;

        $username = $this->cli->prompt('Username:', null, $currentUsername);
        $username = trim($username);

        if (empty($username)) {
            $this->output->fail('Username cannot be empty');
            return 1;
        }

        // Handle password based on --auto-password option
        $password = $this->getPassword($type, $level, $options);

        if ($password === null) {
            return 1; // Error already reported
        }

        // Save to config
        $this->config->setSetting("database.{$type}.user.{$level}.username", $username);
        $this->config->setSetting("database.{$type}.user.{$level}.password", $password);
        $this->config->writeToDisk();

        $this->output->plain('');
        $this->output->ok("User '{$username}' configured for {$level} privilege level on {$type}");

        // Show SQL commands for creating user
        $this->showUserCreationSQL($type, $username, $password, $level, $dbName);

        // Ask if user wants to configure another level (unless --auto-password, which is batch mode)
        if (!empty($options['auto_password'])) {
            return 0; // Skip prompt in batch mode
        }

        return $this->promptConfigureAnother($type);
    }

    /**
     * Get password from options or prompt.
     *
     * @param string $type Database type
     * @param string $level Privilege level
     * @param array $options CLI options
     * @return string|null Password or null on error
     */
    private function getPassword(string $type, string $level, array $options): ?string
    {
        // Check if --auto-password flag set
        if (!empty($options['auto_password'])) {
            $password = $this->generatePassword();
            $this->output->ok("Generated password: {$password}");
            $this->output->warn('Save this password - it will be stored in config file');
            return $password;
        }

        // Prompt for password
        $this->output->plain('');

        // Check if there's a current password
        $hasCurrentPassword = $this->config->hasSetting("database.{$type}.user.{$level}.password");

        if ($hasCurrentPassword) {
            $this->output->info('Password already configured. Leave empty to keep current, or enter new:');
        } else {
            $this->output->info('Password (leave empty to generate random secure password):');
        }

        $password = $this->cli->passwordPrompt('Password:');
        $password = trim($password);

        // If empty and there's a current password, keep it
        if (empty($password) && $hasCurrentPassword) {
            $password = $this->config->getSetting("database.{$type}.user.{$level}.password");
            $this->output->ok('Keeping existing password');
        } elseif (empty($password)) {
            // Generate random password if empty and no current password
            $password = $this->generatePassword();
            $this->output->ok("Generated password: {$password}");
            $this->output->warn('Save this password - it will be stored in config file');
        } else {
            // User entered a new password
            $this->output->ok('Password updated');
        }

        return $password;
    }

    /**
     * Suggest username based on database name and privilege level.
     *
     * @param string $dbName Database name
     * @param string $level Privilege level
     * @return string Suggested username
     */
    private function suggestUsername(string $dbName, string $level): string
    {
        // Remove common prefixes/suffixes
        $base = preg_replace('/(^(horde|test)_|_(db|ci|test)$)/i', '', $dbName);

        // Shorten level names
        $levelShort = match ($level) {
            'admin' => 'adm',
            'power' => 'pwr',
            'restricted' => 'rw',
            default => $level,
        };

        return strtolower($base . '_' . $levelShort);
    }

    /**
     * Generate random secure password.
     *
     * @return string Generated password
     */
    private function generatePassword(): string
    {
        // Use cryptographically secure random
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*-_=+';
        $length = 24;

        $password = '';
        $max = strlen($chars) - 1;

        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[random_int(0, $max)];
        }

        return $password;
    }

    /**
     * Show SQL commands for creating user with appropriate privileges.
     *
     * @param string $type Database type
     * @param string $username Username
     * @param string $password Password
     * @param string $level Privilege level
     * @param string $dbName Database name
     */
    private function showUserCreationSQL(
        string $type,
        string $username,
        string $password,
        string $level,
        string $dbName
    ): void {
        $this->output->plain('');
        $this->output->bold('User Creation SQL:');
        $this->output->plain('');

        if ($type === 'mysql') {
            $this->showMySQLUserSQL($username, $password, $level, $dbName);
        } elseif ($type === 'postgresql') {
            $this->showPostgreSQLUserSQL($username, $password, $level, $dbName);
        }

        $this->output->plain('');
        $this->output->info('These commands will be automatically included in install script:');
        $this->output->info('  horde-components database install');
    }

    /**
     * Show MySQL user creation SQL.
     *
     * @param string $username Username
     * @param string $password Password
     * @param string $level Privilege level
     * @param string $dbName Database name
     */
    private function showMySQLUserSQL(
        string $username,
        string $password,
        string $level,
        string $dbName
    ): void {
        $privileges = match ($level) {
            'admin' => 'ALL PRIVILEGES',
            'power' => 'SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, INDEX, ALTER',
            'restricted' => 'SELECT, INSERT, UPDATE, DELETE',
            default => 'SELECT',
        };

        $this->output->plain("CREATE USER IF NOT EXISTS '{$username}'@'localhost' IDENTIFIED BY '{$password}';");
        $this->output->plain("GRANT {$privileges} ON `{$dbName}`.* TO '{$username}'@'localhost';");
        $this->output->plain("FLUSH PRIVILEGES;");
    }

    /**
     * Show PostgreSQL user creation SQL.
     *
     * @param string $username Username
     * @param string $password Password
     * @param string $level Privilege level
     * @param string $dbName Database name
     */
    private function showPostgreSQLUserSQL(
        string $username,
        string $password,
        string $level,
        string $dbName
    ): void {
        // PostgreSQL uses roles
        $createOptions = match ($level) {
            'admin' => 'CREATEDB CREATEROLE',
            'power' => 'NOCREATEDB NOCREATEROLE',
            'restricted' => 'NOCREATEDB NOCREATEROLE',
            default => 'NOCREATEDB NOCREATEROLE',
        };

        $this->output->plain("CREATE USER {$username} WITH PASSWORD '{$password}' {$createOptions};");

        if ($level === 'admin') {
            $this->output->plain("GRANT ALL PRIVILEGES ON DATABASE {$dbName} TO {$username};");
        } elseif ($level === 'power') {
            $this->output->plain("GRANT CONNECT ON DATABASE {$dbName} TO {$username};");
            $this->output->plain("GRANT CREATE ON SCHEMA public TO {$username};");
            $this->output->plain("GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA public TO {$username};");
        } else {
            $this->output->plain("GRANT CONNECT ON DATABASE {$dbName} TO {$username};");
            $this->output->plain("GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO {$username};");
        }
    }

    /**
     * Prompt to configure another privilege level.
     *
     * @param string $type Database type
     * @return int Exit code (0 to continue, 1 to exit)
     */
    private function promptConfigureAnother(string $type): int
    {
        $this->output->plain('');

        $choices = ['y', 'n'];
        $response = $this->cli->prompt(
            'Configure another privilege level? [y/n]:',
            $choices,
            1  // Default to 'n'
        );

        // Response is numeric index
        if ($choices[$response] === 'y') {
            $this->output->plain('');
            // Reconfigure for the same database type
            $level = $this->promptPrivilegeLevel($type);
            return $this->configureUser($type, $level, []);
        }

        $this->output->plain('');
        $this->output->ok('User configuration complete');
        $this->output->plain('');
        $this->output->info('Next steps:');
        $this->output->info('  1. Install database and create users: horde-components database install');
        $this->output->info('  2. Or manually create users using SQL commands above');

        return 0;
    }
}
