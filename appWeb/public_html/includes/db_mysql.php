<?php

declare(strict_types=1);

/**
 * iHymns — MySQLi Database Connection
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Provides a shared MySQLi connection for song data queries.
 * Credentials are loaded from appWeb/.auth/db_credentials.php.
 * Connection is created once per request and reused (singleton pattern).
 *
 * USAGE:
 *   require_once __DIR__ . DIRECTORY_SEPARATOR . 'db_mysql.php';
 *   $db = getDbMysqli();
 *   $stmt = $db->prepare("SELECT * FROM songs WHERE song_id = ?");
 *
 * @requires PHP 8.1+ with mysqli extension
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

$_dbCredentialsFile = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (file_exists($_dbCredentialsFile) && !defined('DB_HOST')) {
    require_once $_dbCredentialsFile;
}

/* =========================================================================
 * CONNECTION FACTORY
 * ========================================================================= */

/** @var mysqli|null Cached MySQLi connection */
$_mysqliConnection = null;

/**
 * Get the shared MySQLi database connection.
 *
 * Creates the connection on first call and reuses it for subsequent calls
 * within the same PHP request.
 *
 * @return mysqli
 * @throws RuntimeException If credentials are missing or connection fails
 */
function getDbMysqli(): mysqli
{
    global $_mysqliConnection;

    if ($_mysqliConnection !== null) {
        /* Verify the cached handle is still alive. The bulk migration
           runner in /manage/setup-database.php iterates many migration
           scripts in one PHP request, and it's easy for a script to
           call $mysqli->close() on the singleton — every subsequent
           caller would otherwise get back a closed handle and fail on
           the first prepare(). Touching `thread_id` on a closed
           connection throws under MYSQLI_REPORT_STRICT (set below);
           catching that lets us null the cache and reconnect below
           without a wasted MySQL ping round-trip. (#745) */
        try {
            $_ = $_mysqliConnection->thread_id;
            return $_mysqliConnection;
        } catch (\Throwable $_e) {
            $_mysqliConnection = null;
        }
    }

    /* Verify credentials are loaded */
    if (!defined('DB_HOST') || !defined('DB_USER') || !defined('DB_PASS') || !defined('DB_NAME')) {
        throw new \RuntimeException(
            'MySQL credentials not configured. '
            . 'Copy appWeb/.auth/db_credentials.example.php to appWeb/.auth/db_credentials.php '
            . 'and fill in your MySQL details.'
        );
    }

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    $port = defined('DB_PORT') ? (int)DB_PORT : 3306;
    $charset = defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4';

    $_mysqliConnection = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, $port);
    $_mysqliConnection->set_charset($charset);

    return $_mysqliConnection;
}
