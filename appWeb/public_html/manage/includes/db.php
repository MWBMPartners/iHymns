<?php

declare(strict_types=1);

/**
 * iHymns — Database Connection (PDO Abstraction)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Provides a single PDO connection factory that currently uses SQLite
 * but can be switched to MySQL/MariaDB or SQL Server by changing the
 * driver configuration. All queries should use standard SQL or the
 * helpers in this file so the switch is painless.
 *
 * CONFIGURATION:
 * Edit the DB_CONFIG constant below to change the driver. The SQLite
 * database file lives in appWeb/data_share/SQLite/ — outside the
 * public web root.
 *
 * @requires PHP 8.5+ with pdo_sqlite (default) or pdo_mysql / pdo_sqlsrv
 */

/* =========================================================================
 * DIRECT ACCESS PREVENTION
 * ========================================================================= */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

/* =========================================================================
 * DATABASE CONFIGURATION
 *
 * Supported drivers: 'sqlite', 'mysql', 'sqlsrv'
 * Only the active driver's settings are used.
 * ========================================================================= */

define('DB_CONFIG', [

    /* Active driver — change this to switch database engines */
    'driver' => 'sqlite',

    /* SQLite — file-based, zero-configuration */
    'sqlite' => [
        'path' => dirname(__DIR__, 3) . '/data_share/SQLite/ihymns.db',
    ],

    /* MySQL / MariaDB — uncomment and configure when ready to migrate */
    'mysql' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'database' => 'ihymns',
        'username' => '',
        'password' => '',
        'charset'  => 'utf8mb4',
    ],

    /* SQL Server — uncomment and configure when ready to migrate */
    'sqlsrv' => [
        'host'     => '127.0.0.1',
        'port'     => 1433,
        'database' => 'ihymns',
        'username' => '',
        'password' => '',
    ],
]);

/* =========================================================================
 * CONNECTION FACTORY
 * ========================================================================= */

/** @var PDO|null Cached connection instance */
$_dbConnection = null;

/**
 * Get the shared PDO database connection.
 *
 * Creates the connection on first call and reuses it for subsequent calls
 * within the same request. Automatically creates the SQLite file and runs
 * schema migrations if needed.
 *
 * @return PDO
 * @throws RuntimeException If the connection cannot be established
 */
function getDb(): PDO
{
    global $_dbConnection;
    if ($_dbConnection !== null) {
        return $_dbConnection;
    }

    $driver = DB_CONFIG['driver'];
    $cfg    = DB_CONFIG[$driver] ?? [];

    switch ($driver) {
        case 'sqlite':
            $dir = dirname($cfg['path']);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $dsn = 'sqlite:' . $cfg['path'];
            $_dbConnection = new PDO($dsn);
            $_dbConnection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $_dbConnection->exec('PRAGMA journal_mode = WAL');
            $_dbConnection->exec('PRAGMA foreign_keys = ON');
            break;

        case 'mysql':
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $cfg['host'], $cfg['port'], $cfg['database'], $cfg['charset'] ?? 'utf8mb4'
            );
            $_dbConnection = new PDO($dsn, $cfg['username'], $cfg['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            break;

        case 'sqlsrv':
            $dsn = sprintf(
                'sqlsrv:Server=%s,%d;Database=%s',
                $cfg['host'], $cfg['port'], $cfg['database']
            );
            $_dbConnection = new PDO($dsn, $cfg['username'], $cfg['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            break;

        default:
            throw new \RuntimeException("Unsupported database driver: {$driver}");
    }

    /* Run migrations on every connection (idempotent) */
    runMigrations($_dbConnection);

    return $_dbConnection;
}

/* =========================================================================
 * SCHEMA MIGRATIONS
 *
 * Each migration is an idempotent SQL statement. New migrations are
 * appended to the array — they run in order and are tracked in a
 * migrations table so each runs only once.
 * ========================================================================= */

/**
 * Run pending schema migrations.
 *
 * @param PDO $db The database connection
 */
function runMigrations(PDO $db): void
{
    $driver = DB_CONFIG['driver'];

    /* Create the migrations tracking table (driver-agnostic) */
    $db->exec('CREATE TABLE IF NOT EXISTS migrations (
        id INTEGER PRIMARY KEY ' . ($driver === 'sqlite' ? 'AUTOINCREMENT' : 'AUTO_INCREMENT') . ',
        name TEXT NOT NULL UNIQUE,
        applied_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');

    /* Define all migrations — append new ones at the end */
    $migrations = [
        '001_create_users' => '
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY ' . ($driver === 'sqlite' ? 'AUTOINCREMENT' : 'AUTO_INCREMENT') . ',
                username TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL,
                display_name TEXT NOT NULL DEFAULT \'\',
                role TEXT NOT NULL DEFAULT \'editor\',
                is_active INTEGER NOT NULL DEFAULT 1,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ',
        '002_create_sessions' => '
            CREATE TABLE IF NOT EXISTS sessions (
                id TEXT PRIMARY KEY,
                user_id INTEGER NOT NULL,
                ip_address TEXT,
                user_agent TEXT,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                expires_at TEXT NOT NULL,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )
        ',

        /* API bearer tokens for public-facing user authentication */
        '003_create_api_tokens' => '
            CREATE TABLE IF NOT EXISTS api_tokens (
                token TEXT PRIMARY KEY,
                user_id INTEGER NOT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                expires_at TEXT NOT NULL,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )
        ',

        /* Server-side setlist storage linked to user accounts */
        '004_create_user_setlists' => '
            CREATE TABLE IF NOT EXISTS user_setlists (
                id ' . ($driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INTEGER PRIMARY KEY AUTO_INCREMENT') . ',
                user_id INTEGER NOT NULL,
                setlist_id TEXT NOT NULL,
                name TEXT NOT NULL,
                songs_json TEXT NOT NULL DEFAULT \'[]\',
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                UNIQUE(user_id, setlist_id)
            )
        ',

        /* Password reset tokens for "forgot password" functionality */
        '005_create_password_reset_tokens' => '
            CREATE TABLE IF NOT EXISTS password_reset_tokens (
                token TEXT PRIMARY KEY,
                user_id INTEGER NOT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                expires_at TEXT NOT NULL,
                used INTEGER NOT NULL DEFAULT 0,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )
        ',

        /* Add email column to users for password reset delivery.
         * Also normalises the role column: global_admin, admin, editor, user. */
        '006_add_user_email' => '
            ALTER TABLE users ADD COLUMN email TEXT NOT NULL DEFAULT \'\'
        ',
    ];

    /* Apply each migration that hasn't run yet */
    foreach ($migrations as $name => $sql) {
        $stmt = $db->prepare('SELECT COUNT(*) FROM migrations WHERE name = ?');
        $stmt->execute([$name]);
        if ((int)$stmt->fetchColumn() === 0) {
            $db->exec($sql);
            $insert = $db->prepare('INSERT INTO migrations (name) VALUES (?)');
            $insert->execute([$name]);
        }
    }
}
