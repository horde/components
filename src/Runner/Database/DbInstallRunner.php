<?php

/**
 * DbInstall Runner - Generate and execute database installation script
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
 * DbInstall Runner - Generate and execute database installation script
 *
 * Generates idempotent Ubuntu 24.04 installation script with embedded
 * credentials. Script installs database software, PHP extensions, and
 * creates configured users. Self-deletes after execution.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class DbInstallRunner
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
     * Run install subcommand.
     *
     * @param array $options CLI options
     * @return int Exit code (0 = success, 1 = error)
     */
    public function run(array $options = []): int
    {
        $this->output->bold('=== Database Installation ===');
        $this->output->plain('');

        // Detect configured databases
        $configured = $this->getConfiguredDatabases();

        if (empty($configured)) {
            $this->output->fail('No databases configured. Run: horde-components database configure');
            return 1;
        }

        // Check if --db-type option provided
        if (!empty($options['db_type'])) {
            $type = strtolower($options['db_type']);

            // Validate type
            if (!in_array($type, ['sqlite', 'mysql', 'postgresql'])) {
                $this->output->fail("Invalid database type: {$type}");
                $this->output->info('Valid types: sqlite, mysql, postgresql');
                return 1;
            }

            // Check if configured
            if (!in_array($type, $configured)) {
                $this->output->fail("{$type} database not configured. Run: horde-components database configure --db-type={$type}");
                return 1;
            }

            $this->output->info("Installing specific database: {$type}");
            $this->output->plain('');

            return $this->installDatabase($type);
        }

        // Install all configured databases
        $this->output->info('Configured databases: ' . implode(', ', $configured));
        $this->output->plain('');

        $results = [];
        foreach ($configured as $type) {
            $this->output->bold("--- Installing {$type} ---");
            $this->output->plain('');

            $result = $this->installDatabase($type);
            $results[$type] = $result;

            $this->output->plain('');
        }

        // Show summary
        $this->output->bold('=== Installation Summary ===');
        $this->output->plain('');

        $allSuccess = true;
        foreach ($results as $type => $exitCode) {
            if ($exitCode === 0) {
                $this->output->ok("{$type}: Success ✓");
            } else {
                $this->output->fail("{$type}: Failed ✗");
                $allSuccess = false;
            }
        }

        return $allSuccess ? 0 : 1;
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
     * Install a specific database type.
     *
     * @param string $type Database type
     * @return int Exit code
     */
    private function installDatabase(string $type): int
    {
        // SQLite doesn't need installation script
        if ($type === 'sqlite') {
            return $this->handleSqlite($type);
        }

        // Generate installation script
        $this->output->info('Generating idempotent installation script for Ubuntu 24.04...');
        $this->output->plain('');

        $script = $this->generateInstallScript($type);
        $scriptPath = $this->saveScript($script, $type);

        if ($scriptPath === null) {
            $this->output->fail('Failed to save installation script');
            return 1;
        }

        $this->output->ok("Installation script generated: {$scriptPath}");
        $this->output->plain('');

        // Check if we have sudo
        return $this->executeOrPrint($scriptPath);
    }

    /**
     * Handle SQLite installation.
     *
     * @param string $type Database type (should be 'sqlite')
     * @return int Exit code
     */
    private function handleSqlite(string $type): int
    {
        $this->output->info('SQLite installation check...');
        $this->output->plain('');

        // Check if sqlite3 PHP extension is loaded
        if (extension_loaded('sqlite3') || extension_loaded('pdo_sqlite')) {
            $this->output->ok('SQLite support is already installed ✓');
            $this->output->plain('');

            // Check if database file exists
            $path = $this->config->getSetting("database.{$type}.path");
            if (file_exists($path)) {
                $this->output->ok("Database file exists: {$path}");
            } else {
                $dir = dirname($path);
                if (!is_dir($dir)) {
                    if (mkdir($dir, 0o755, true)) {
                        $this->output->ok("Created directory: {$dir}");
                    } else {
                        $this->output->fail("Failed to create directory: {$dir}");
                        return 1;
                    }
                }
                $this->output->info("Database file will be created on first connection");
            }

            return 0;
        }

        // Need to install SQLite
        $this->output->warn('SQLite PHP extension not found');
        $this->output->plain('');
        $this->output->info('Install with: sudo apt-get install php-sqlite3');

        return 1;
    }

    /**
     * Generate installation script for database type.
     *
     * @param string $type Database type (mysql or postgresql)
     * @return string Shell script content
     */
    private function generateInstallScript(string $type): string
    {
        $header = $this->generateHeader($type);
        $detection = $this->generateOsDetection();

        if ($type === 'mysql') {
            $install = $this->generateMySQLInstall();
            $users = $this->generateMySQLUsers($type);
        } else {
            $install = $this->generatePostgreSQLInstall();
            $users = $this->generatePostgreSQLUsers($type);
        }

        $footer = $this->generateFooter();

        return $header . $detection . $install . $users . $footer;
    }

    /**
     * Generate script header.
     *
     * @param string $type Database type
     * @return string Header content
     */
    private function generateHeader(string $type): string
    {
        $timestamp = date('Y-m-d H:i:s');

        return <<<BASH
            #!/bin/bash
            #
            # Database Installation Script
            # Generated by horde-components database install
            # Timestamp: {$timestamp}
            # Database Type: {$type}
            #
            # This script is idempotent and self-deleting.
            # It contains embedded credentials - delete after use.
            #

            set -e  # Exit on error
            set -u  # Exit on undefined variable

            SCRIPT_PATH="\${BASH_SOURCE[0]}"


            BASH
. "\n";
    }

    /**
     * Generate OS detection section.
     *
     * @return string OS detection code
     */
    private function generateOsDetection(): string
    {
        return <<<'BASH'
            # Detect OS
            if [ -f /etc/os-release ]; then
                . /etc/os-release
                OS=$ID
                VERSION_ID=$VERSION_ID
            else
                echo "Error: Cannot detect OS"
                exit 1
            fi

            if [ "$OS" != "ubuntu" ]; then
                echo "Warning: This script is designed for Ubuntu 24.04"
                echo "Detected: $OS $VERSION_ID"
                echo "Proceeding anyway..."
            fi

            echo "Installing on: $OS $VERSION_ID"
            echo ""


            BASH
. "\n";
    }

    /**
     * Generate MySQL installation section.
     *
     * @return string MySQL installation code
     */
    private function generateMySQLInstall(): string
    {
        return <<<'BASH'
            # Install MySQL Server and PHP Extension
            echo "==> Installing MySQL Server..."

            # Check if already installed
            if command -v mysql >/dev/null 2>&1; then
                echo "MySQL already installed: $(mysql --version | head -n1)"
            else
                export DEBIAN_FRONTEND=noninteractive
                apt-get update -qq
                apt-get install -y -qq mysql-server
                echo "MySQL installed successfully"
            fi

            echo ""
            echo "==> Installing PHP MySQL Extension..."

            # Check if extension is loaded
            if php -m | grep -q mysqli; then
                echo "PHP mysqli extension already loaded"
            else
                apt-get install -y -qq php-mysql
                echo "PHP mysqli extension installed"
            fi

            echo ""
            echo "==> Starting MySQL Service..."
            systemctl enable mysql >/dev/null 2>&1 || true
            systemctl start mysql || true
            systemctl status mysql --no-pager | head -n 5

            echo ""


            BASH
. "\n";
    }

    /**
     * Generate PostgreSQL installation section.
     *
     * @return string PostgreSQL installation code
     */
    private function generatePostgreSQLInstall(): string
    {
        return <<<'BASH'
            # Install PostgreSQL Server and PHP Extension
            echo "==> Installing PostgreSQL Server..."

            # Check if already installed
            if command -v psql >/dev/null 2>&1; then
                echo "PostgreSQL already installed: $(psql --version)"
            else
                export DEBIAN_FRONTEND=noninteractive
                apt-get update -qq
                apt-get install -y -qq postgresql postgresql-contrib
                echo "PostgreSQL installed successfully"
            fi

            echo ""
            echo "==> Installing PHP PostgreSQL Extension..."

            # Check if extension is loaded
            if php -m | grep -q pgsql; then
                echo "PHP pgsql extension already loaded"
            else
                apt-get install -y -qq php-pgsql
                echo "PHP pgsql extension installed"
            fi

            echo ""
            echo "==> Starting PostgreSQL Service..."
            systemctl enable postgresql >/dev/null 2>&1 || true
            systemctl start postgresql || true
            systemctl status postgresql --no-pager | head -n 5

            echo ""


            BASH
. "\n";
    }

    /**
     * Generate MySQL user creation section.
     *
     * @param string $type Database type
     * @return string MySQL user creation code
     */
    private function generateMySQLUsers(string $type): string
    {
        $dbName = $this->config->getSetting("database.{$type}.name");
        $userSQL = '';

        // Check for configured users
        $levels = ['admin', 'power', 'restricted'];
        $hasUsers = false;

        foreach ($levels as $level) {
            if ($this->config->hasSetting("database.{$type}.user.{$level}.username")) {
                $hasUsers = true;
                $username = $this->config->getSetting("database.{$type}.user.{$level}.username");
                $password = $this->config->getSetting("database.{$type}.user.{$level}.password");

                $privileges = match ($level) {
                    'admin' => 'ALL PRIVILEGES',
                    'power' => 'SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, INDEX, ALTER',
                    'restricted' => 'SELECT, INSERT, UPDATE, DELETE',
                };

                $userSQL .= "CREATE USER IF NOT EXISTS '{$username}'@'localhost' IDENTIFIED BY '{$password}';\n";
                $userSQL .= "GRANT {$privileges} ON \`{$dbName}\`.* TO '{$username}'@'localhost';\n";
            }
        }

        if (!$hasUsers) {
            return <<<'BASH'
                # No users configured
                echo "==> No database users configured"
                echo "    Run: horde-components database user"
                echo ""


                BASH
. "\n";
        }

        $userSQL .= "FLUSH PRIVILEGES;\n";

        return <<<BASH
            # Create Database and Users
            echo "==> Creating Database: {$dbName}..."

            mysql -u root <<'EOSQL'
            CREATE DATABASE IF NOT EXISTS \`{$dbName}\`;
            {$userSQL}
            EOSQL

            echo "Database and users created successfully"
            echo ""


            BASH
. "\n";
    }

    /**
     * Generate PostgreSQL user creation section.
     *
     * @param string $type Database type
     * @return string PostgreSQL user creation code
     */
    private function generatePostgreSQLUsers(string $type): string
    {
        $dbName = $this->config->getSetting("database.{$type}.name");
        $userSQL = '';

        // Check for configured users
        $levels = ['admin', 'power', 'restricted'];
        $hasUsers = false;

        foreach ($levels as $level) {
            if ($this->config->hasSetting("database.{$type}.user.{$level}.username")) {
                $hasUsers = true;
                $username = $this->config->getSetting("database.{$type}.user.{$level}.username");
                $password = $this->config->getSetting("database.{$type}.user.{$level}.password");

                $createOptions = match ($level) {
                    'admin' => 'CREATEDB CREATEROLE',
                    'power' => 'NOCREATEDB NOCREATEROLE',
                    'restricted' => 'NOCREATEDB NOCREATEROLE',
                };

                // PostgreSQL: Create user if not exists
                $userSQL .= "SELECT 'CREATE USER {$username} WITH PASSWORD ''{$password}'' {$createOptions}'\n";
                $userSQL .= "WHERE NOT EXISTS (SELECT FROM pg_catalog.pg_roles WHERE rolname = '{$username}')" . '\\' . "gexec\n";

                if ($level === 'admin') {
                    $userSQL .= "GRANT ALL PRIVILEGES ON DATABASE {$dbName} TO {$username};\n";
                } elseif ($level === 'power') {
                    $userSQL .= "GRANT CONNECT ON DATABASE {$dbName} TO {$username};\n";
                    $userSQL .= "GRANT CREATE ON SCHEMA public TO {$username};\n";
                } else {
                    $userSQL .= "GRANT CONNECT ON DATABASE {$dbName} TO {$username};\n";
                }
            }
        }

        if (!$hasUsers) {
            return <<<'BASH'
                # No users configured
                echo "==> No database users configured"
                echo "    Run: horde-components database user"
                echo ""


                BASH
. "\n";
        }

        return <<<BASH
            # Create Database and Users
            echo "==> Creating Database: {$dbName}..."

            sudo -u postgres psql <<'EOSQL'
            CREATE DATABASE {$dbName};
            {$userSQL}
            EOSQL

            echo "Database and users created successfully"
            echo ""


            BASH
. "\n";
    }

    /**
     * Generate script footer with self-deletion.
     *
     * @return string Footer content
     */
    private function generateFooter(): string
    {
        return <<<'BASH'
            # Self-delete
            echo "==> Installation complete!"
            echo "    Deleting installation script (contains credentials)..."
            rm -f "$SCRIPT_PATH"
            echo "    Script deleted."


            BASH
. "\n";
    }

    /**
     * Save script to temporary file.
     *
     * @param string $script Script content
     * @param string $type Database type
     * @return string|null Path to script file, or null on failure
     */
    private function saveScript(string $script, string $type): ?string
    {
        $tmpDir = sys_get_temp_dir();
        $scriptPath = $tmpDir . '/horde-components-db-install-' . $type . '-' . uniqid() . '.sh';

        if (file_put_contents($scriptPath, $script) === false) {
            return null;
        }

        chmod($scriptPath, 0o700);  // Make executable, readable only by owner

        return $scriptPath;
    }

    /**
     * Execute script if we have sudo, otherwise print instructions.
     *
     * @param string $scriptPath Path to script
     * @return int Exit code
     */
    private function executeOrPrint(string $scriptPath): int
    {
        // Check if we have sudo
        exec('sudo -n true 2>/dev/null', $output, $exitCode);

        if ($exitCode === 0) {
            // We have passwordless sudo
            $this->output->info('Executing installation script with sudo...');
            $this->output->plain('');

            passthru("sudo bash {$scriptPath}", $installExit);

            if ($installExit === 0) {
                $this->output->plain('');
                $this->output->ok('Installation completed successfully');
            } else {
                $this->output->plain('');
                $this->output->fail("Installation failed with exit code: {$installExit}");
            }

            return $installExit;
        }

        // No sudo - print instructions
        $this->output->warn('Sudo access required to install database software');
        $this->output->plain('');
        $this->output->info('Run the following command with sudo:');
        $this->output->plain('');
        $this->output->plain("    sudo bash {$scriptPath}");
        $this->output->plain('');
        $this->output->warn("Note: Script contains credentials and will self-delete after execution");

        return 0;
    }
}
