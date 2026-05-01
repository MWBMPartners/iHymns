<?php

declare(strict_types=1);

/**
 * iHymns — Per-user avatar-service preference (#616 / #581 follow-up)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Adds the `AvatarService` column to `tblUsers` so each signed-in user
 * can choose how their avatar is resolved — overriding the project-
 * level default in APP_CONFIG['avatar']['service']. This is the
 * per-user opt-out half of #581's acceptance criteria.
 *
 * Schema:
 *   tblUsers.AvatarService VARCHAR(16) NULL
 *
 *   NULL    = inherit project default (Gravatar unless APP_CONFIG says
 *             otherwise) — this is the legacy / first-time-user state.
 *   string  = one of 'gravatar' | 'libravatar' | 'dicebear' | 'none'.
 *
 * @migration-adds tblUsers.AvatarService
 *
 * USAGE:
 *   Web:  /manage/setup-database → Apply all pending migrations
 *   CLI:  php appWeb/.sql/migrate-user-avatar-service.php
 *
 * Idempotent — re-running is safe; the column-exists check skips the
 * ALTER, no backfill needed (NULL is the "inherit" sentinel).
 */

if (PHP_SAPI === 'cli') {
    /* Guarded require — see #652. The dashboard has already loaded

       db_mysql.php via auth.php's bootstrap, so the function already

       exists at this point in dashboard mode; the guard skips the

       re-open that some hosts block from outside public_html/. */

    if (!function_exists('getDbMysqli')) {

        require_once dirname(__DIR__) . '/public_html/includes/db_mysql.php';

    }
    $isCli = true;
} else {
    if (!defined('IHYMNS_SETUP_DASHBOARD')) {
        /* Guarded: dashboard mode pre-loads auth.php transitively. The

           guard also avoids re-opening the file from outside public_html/,

           which some hosts (open_basedir / php-fpm chroot) refuse even

           though the file is otherwise reachable (#652). */

        if (!function_exists('isAuthenticated')) {

            require_once dirname(__DIR__) . '/public_html/manage/includes/auth.php';

        }
        if (!isAuthenticated()) {
            http_response_code(401);
            exit('Authentication required.');
        }
        $u = getCurrentUser();
        if (!$u || $u['role'] !== 'global_admin') {
            http_response_code(403);
            exit('Global admin required.');
        }
    }
    /* Guarded require — see #652. The dashboard has already loaded

       db_mysql.php via auth.php's bootstrap, so the function already

       exists at this point in dashboard mode; the guard skips the

       re-open that some hosts block from outside public_html/. */

    if (!function_exists('getDbMysqli')) {

        require_once dirname(__DIR__) . '/public_html/includes/db_mysql.php';

    }
    $isCli = false;
}

function _migAvatarSvc_out(string $line): void
{
    global $isCli;
    echo $line . ($isCli ? "\n" : "<br>\n");
    /* CLI only — see migrate-credit-people-flags.php for rationale (#661). */
    if ($isCli) {
        flush();
    }
}

function _migAvatarSvc_columnExists(mysqli $db, string $table, string $column): bool
{
    $stmt = $db->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME   = ?
            AND COLUMN_NAME  = ?
          LIMIT 1'
    );
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $exists;
}

_migAvatarSvc_out('User avatar-service migration starting…');

$mysqli = getDbMysqli();
if (!$mysqli) {
    _migAvatarSvc_out('ERROR: could not connect to database.');
    exit(1);
}

if (_migAvatarSvc_columnExists($mysqli, 'tblUsers', 'AvatarService')) {
    _migAvatarSvc_out('[skip] tblUsers.AvatarService already present.');
} else {
    $sql = 'ALTER TABLE tblUsers
            ADD COLUMN AvatarService VARCHAR(16) NULL';
    if (!$mysqli->query($sql)) {
        _migAvatarSvc_out('ERROR: adding AvatarService failed: ' . $mysqli->error);
        exit(1);
    }
    _migAvatarSvc_out('[add ] tblUsers.AvatarService.');
}

_migAvatarSvc_out('User avatar-service migration finished.');
/* Don't close $mysqli — it's the shared singleton from getDbMysqli().
   The bulk migration runner in /manage/setup-database.php iterates many
   migrations in one PHP request; closing here would invalidate the
   handle for every subsequent migration that calls getDbMysqli(). PHP
   closes the connection on script exit anyway. */
