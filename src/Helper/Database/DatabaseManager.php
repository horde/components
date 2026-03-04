<?php

/**
 * Database Manager - Handle database connections and operations
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

namespace Horde\Components\Helper\Database;

use Horde\Components\Output;
use Horde\Components\ConfigProvider\PhpConfigFileProvider;
use PDO;
use PDOException;
use Exception;

/**
 * Database Manager - Handle database connections and operations
 *
 * Provides database connection management and common operations
 * for SQLite, MySQL, and PostgreSQL databases.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class DatabaseManager
{
    private ?PDO $pdo = null;
    private readonly string $type;

    /**
     * Constructor.
     *
     * @param PhpConfigFileProvider $config Configuration provider
     * @param Output $output Output handler
     * @param string $type Database type (sqlite, mysql, or postgresql)
     */
    public function __construct(
        private readonly PhpConfigFileProvider $config,
        private readonly Output $output,
        string $type
    ) {
        $this->type = $type;
    }

    /**
     * Get database connection.
     *
     * Creates connection on first call, reuses on subsequent calls.
     *
     * @return PDO Database connection
     * @throws PDOException If connection fails
     */
    public function connect(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        $dsn = $this->getDsn();
        $username = $this->getUsername();
        $password = $this->getPassword();

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];

        $this->pdo = new PDO($dsn, $username, $password, $options);

        return $this->pdo;
    }

    /**
     * Test if database connection works.
     *
     * @return bool True if connection successful
     */
    public function testConnection(): bool
    {
        try {
            $pdo = $this->connect();

            // Try a simple query
            $stmt = $pdo->query('SELECT 1');

            return $stmt !== false;
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * Build DSN string from configuration.
     *
     * @return string PDO DSN string
     * @throws Exception If configuration is invalid
     */
    public function getDsn(): string
    {
        if ($this->type === 'sqlite') {
            $path = $this->config->getSetting("database.{$this->type}.path");
            return "sqlite:{$path}";
        }

        $name = $this->config->getSetting("database.{$this->type}.name");
        $host = $this->config->getSetting("database.{$this->type}.host");
        $port = $this->config->getSetting("database.{$this->type}.port");

        if ($this->type === 'mysql') {
            return "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
        }

        if ($this->type === 'postgresql') {
            return "pgsql:host={$host};port={$port};dbname={$name}";
        }

        throw new Exception("Unsupported database type: {$this->type}");
    }

    /**
     * Get username from configuration.
     *
     * For SQLite, returns null.
     * For MySQL/PostgreSQL, tries admin user first, then power, then restricted.
     *
     * @return string|null Username or null
     */
    private function getUsername(): ?string
    {
        if ($this->type === 'sqlite') {
            return null;
        }

        // Try admin user first
        if ($this->config->hasSetting("database.{$this->type}.user.admin.username")) {
            return $this->config->getSetting("database.{$this->type}.user.admin.username");
        }

        // Try power user
        if ($this->config->hasSetting("database.{$this->type}.user.power.username")) {
            return $this->config->getSetting("database.{$this->type}.user.power.username");
        }

        // Try restricted user
        if ($this->config->hasSetting("database.{$this->type}.user.restricted.username")) {
            return $this->config->getSetting("database.{$this->type}.user.restricted.username");
        }

        // Fall back to root for connection test
        return 'root';
    }

    /**
     * Get password from configuration.
     *
     * For SQLite, returns null.
     * For MySQL/PostgreSQL, tries to match username.
     *
     * @return string|null Password or null
     */
    private function getPassword(): ?string
    {
        if ($this->type === 'sqlite') {
            return null;
        }

        // Try admin password
        if ($this->config->hasSetting("database.{$this->type}.user.admin.password")) {
            return $this->config->getSetting("database.{$this->type}.user.admin.password");
        }

        // Try power password
        if ($this->config->hasSetting("database.{$this->type}.user.power.password")) {
            return $this->config->getSetting("database.{$this->type}.user.power.password");
        }

        // Try restricted password
        if ($this->config->hasSetting("database.{$this->type}.user.restricted.password")) {
            return $this->config->getSetting("database.{$this->type}.user.restricted.password");
        }

        // Empty password for root (passwordless local connection)
        return '';
    }

    /**
     * List all tables in database.
     *
     * @param string $prefix Optional prefix filter
     * @return array List of table names
     */
    public function listTables(string $prefix = ''): array
    {
        $pdo = $this->connect();

        if ($this->type === 'sqlite') {
            $sql = "SELECT name FROM sqlite_master WHERE type='table'";
            if ($prefix) {
                $sql .= " AND name LIKE :prefix";
            }
            $sql .= " ORDER BY name";
        } elseif ($this->type === 'mysql') {
            $dbName = $this->config->getSetting("database.{$this->type}.name");
            $sql = "SELECT TABLE_NAME as name FROM information_schema.TABLES WHERE TABLE_SCHEMA = :dbname";
            if ($prefix) {
                $sql .= " AND TABLE_NAME LIKE :prefix";
            }
            $sql .= " ORDER BY TABLE_NAME";
        } else { // postgresql
            $sql = "SELECT tablename as name FROM pg_tables WHERE schemaname = 'public'";
            if ($prefix) {
                $sql .= " AND tablename LIKE :prefix";
            }
            $sql .= " ORDER BY tablename";
        }

        $stmt = $pdo->prepare($sql);

        if ($this->type === 'mysql') {
            $stmt->bindValue(':dbname', $this->config->getSetting("database.{$this->type}.name"));
        }

        if ($prefix) {
            $stmt->bindValue(':prefix', $prefix . '%');
        }

        $stmt->execute();

        $tables = [];
        while ($row = $stmt->fetch()) {
            $tables[] = $row['name'];
        }

        return $tables;
    }

    /**
     * Drop a single table.
     *
     * @param string $table Table name
     * @return bool True if successful
     */
    public function dropTable(string $table): bool
    {
        try {
            $pdo = $this->connect();

            // Validate table name (alphanumeric + underscore only)
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
                throw new Exception("Invalid table name: {$table}");
            }

            // Use identifier quoting for safety
            if ($this->type === 'mysql') {
                $quotedTable = "`{$table}`";
            } elseif ($this->type === 'postgresql') {
                $quotedTable = "\"{$table}\"";
            } else { // sqlite
                $quotedTable = "\"{$table}\"";
            }

            $sql = "DROP TABLE IF EXISTS {$quotedTable}";
            $pdo->exec($sql);

            return true;
        } catch (PDOException $e) {
            $this->output->warn("Failed to drop table {$table}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Create database if it doesn't exist.
     *
     * Only applicable for MySQL/PostgreSQL (SQLite creates on connect).
     *
     * @param string $name Database name
     * @return bool True if successful
     */
    public function createDatabase(string $name): bool
    {
        if ($this->type === 'sqlite') {
            // SQLite creates database on connect
            return true;
        }

        try {
            // Connect without database name
            $host = $this->config->getSetting("database.{$this->type}.host");
            $port = $this->config->getSetting("database.{$this->type}.port");
            $username = $this->getUsername() ?? 'root';
            $password = $this->getPassword() ?? '';

            if ($this->type === 'mysql') {
                $dsn = "mysql:host={$host};port={$port}";
            } else { // postgresql
                $dsn = "pgsql:host={$host};port={$port}";
            }

            $pdo = new PDO($dsn, $username, $password);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Validate database name
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
                throw new Exception("Invalid database name: {$name}");
            }

            $sql = "CREATE DATABASE IF NOT EXISTS `{$name}`";
            $pdo->exec($sql);

            return true;
        } catch (PDOException $e) {
            $this->output->warn("Failed to create database {$name}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Execute arbitrary SQL query.
     *
     * @param string $sql SQL query
     * @param array $params Parameters for prepared statement
     * @return mixed Query result
     */
    public function executeQuery(string $sql, array $params = []): mixed
    {
        $pdo = $this->connect();

        if (empty($params)) {
            return $pdo->exec($sql);
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt;
    }
}
