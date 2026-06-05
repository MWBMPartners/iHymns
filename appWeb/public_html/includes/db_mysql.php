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

/**
 * Is this Throwable a MySQL CONNECTION / availability failure — server down,
 * unreachable, too many connections, handshake/auth, unknown DB, or missing
 * credentials — as opposed to an ordinary query error (which should stay a
 * 500)? The public entry points map a true result to a transient 503 +
 * Retry-After so a DB outage degrades gracefully (WS-K #1021).
 *
 * @param \Throwable $e
 * @return bool
 */
function isDbConnectionFailure(\Throwable $e): bool
{
    if ($e instanceof \mysqli_sql_exception) {
        /* Client-side connect/lost errors (20xx) + server-side availability
           errors (10xx). Ordinary query errors — ER_DUP_ENTRY 1062 etc. —
           are deliberately excluded so genuine app bugs still surface as 500. */
        return in_array($e->getCode(), [
            2002, 2003, 2004, 2005, 2006, 2012, 2013,  /* can't connect / lost / handshake */
            1040, 1045, 1049, 1129, 1203,              /* too many conns / access denied / unknown DB / host blocked / per-user limit */
        ], true);
    }
    return $e instanceof \RuntimeException
        && str_contains($e->getMessage(), 'MySQL credentials not configured');
}

/**
 * Defensive `bind_param` wrapper. Asserts that the type-string length
 * matches the number of bound variables BEFORE calling
 * `$stmt->bind_param`, so a typo throws a clear error naming the
 * calling site rather than mysqli's generic "Number of elements in
 * type definition string doesn't match number of bind variables"
 * which is easy to misattribute to a query-level problem.
 *
 * Motivated by #923 — a one-character typo in
 * `activity_log.php`'s INSERT type string (`'isssssssssssssi'`,
 * 15 chars vs 14 placeholders) caused every logActivity() call to
 * silently fail for hours after #919's deploy. The function-level
 * try/catch swallowed mysqli's exception to a single error_log line
 * and `tblActivityLog` got nothing — the audit trail went dark.
 *
 * Helper signature:
 *
 *     bindParamSafe(string $context, \mysqli_stmt $stmt,
 *                   string $types, mixed ...$args): bool
 *
 *     - $context: short descriptor of the calling site, used in
 *                 the thrown error so the maintainer can find it
 *                 without grepping. e.g. 'logActivity INSERT'.
 *     - $stmt:    the prepared statement to bind against.
 *     - $types:   the mysqli type-string ('issssi' etc.).
 *     - $args:    the variables, varargs.
 *
 * Throws \RuntimeException with a clear message when length(types)
 * !== count(args). Otherwise delegates to $stmt->bind_param() and
 * returns its bool.
 *
 * Adoption: incremental — use in any new bind_param site, plus
 * targeted retrofit of files where the regression originated
 * (activity_log.php at minimum). The full repo-wide sweep is
 * tracked in #926's "out of scope" section as a separate concern.
 */
function bindParamSafe(string $context, \mysqli_stmt $stmt, string $types, mixed ...$args): bool
{
    $expected = strlen($types);
    $given    = count($args);
    if ($expected !== $given) {
        throw new \RuntimeException(sprintf(
            'bind_param mismatch in %s: type string has %d chars (%s) but %d args were passed',
            $context,
            $expected,
            $types,
            $given
        ));
    }
    return $stmt->bind_param($types, ...$args);
}
