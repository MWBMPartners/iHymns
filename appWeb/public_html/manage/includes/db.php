<?php

declare(strict_types=1);

/**
 * iHymns — Database Connection (PDO Abstraction)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Provides a single PDO connection factory for the admin panel (/manage/).
 * Used by the authentication system, user management, and setlist sync.
 *
 * The connection uses MySQL via the shared credentials file at
 * appWeb/.auth/db_credentials.php (same credentials as the MySQLi
 * song data connection in includes/db_mysql.php).
 *
 * CONFIGURATION:
 * Credentials are configured via the interactive installer:
 *   php appWeb/.sql/install.php
 * Or by manually editing appWeb/.auth/db_credentials.php.
 *
 * @requires PHP 8.1+ with pdo_mysql extension
 */

/* =========================================================================
 * DIRECT ACCESS PREVENTION
 * ========================================================================= */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

/* =========================================================================
 * LOAD CREDENTIALS
 * ========================================================================= */

$_dbCredPath = dirname(__DIR__, 3) . '/.auth/db_credentials.php';
if (file_exists($_dbCredPath) && !defined('DB_HOST')) {
    require_once $_dbCredPath;
}

/* =========================================================================
 * CONNECTION FACTORY
 * ========================================================================= */

/** @var PDO|null Cached connection instance */
$_dbConnection = null;

/**
 * Get the shared PDO database connection.
 *
 * Creates the connection on first call and reuses it for subsequent calls
 * within the same request.
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

    if (!defined('DB_HOST') || !defined('DB_USER') || !defined('DB_PASS') || !defined('DB_NAME')) {
        throw new \RuntimeException(
            'MySQL credentials not configured. '
            . 'Run: php appWeb/.sql/install.php'
        );
    }

    $port    = defined('DB_PORT') ? (int)DB_PORT : 3306;
    $charset = defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4';

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        DB_HOST, $port, DB_NAME, $charset
    );

    $_dbConnection = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    return $_dbConnection;
}
