<?php

declare(strict_types=1);

/**
 * iHymns — Notifications: per-environment scope + expiry (#1238)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Adds two optional columns to tblNotifications so a broadcast can be scoped to a
 * single environment and/or auto-expire:
 *   1. Environment VARCHAR(16) NULL — NULL = all environments (the existing
 *      system-wide behaviour); 'alpha' / 'beta' / 'production' = show only on
 *      that environment (the three envs share one DB, so the client filters on
 *      ihymns_environment(), the same detector #1233 introduced).
 *   2. ExpiresAt   DATETIME NULL — NULL = never expires; a timestamp = the client
 *      stops showing the notification after that moment.
 *
 * VARCHAR not ENUM for Environment (rule #20 — a growable vocab); DATETIME not
 * TIMESTAMP for ExpiresAt (rule #20 — TTL convention). STRICTLY ADDITIVE +
 * IDEMPOTENT (each ADD COLUMN gated on SHOW COLUMNS).
 *
 * @migration-adds tblNotifications.Environment
 * @migration-adds tblNotifications.ExpiresAt
 *
 * USAGE:
 *   Web:  /manage/setup-database → "Notification scope + expiry"
 *   CLI:  php appWeb/.sql/migrate-notification-scope-expiry.php
 */

$isCli = (PHP_SAPI === 'cli');
if (!defined('IHYMNS_SETUP_DASHBOARD') && !function_exists('getDbMysqli')) {
    require_once dirname(__DIR__) . '/public_html/includes/db_mysql.php';
}

function _migNotifScope_out(string $msg): void
{
    global $isCli;
    echo $msg . ($isCli ? "\n" : "<br>\n");
    if (!$isCli) { @flush(); }
}

/** Add a column only if it isn't already present (idempotent). */
function _migNotifScope_addCol(mysqli $db, string $col, string $ddl): void
{
    $res = $db->query("SHOW COLUMNS FROM tblNotifications LIKE '" . $db->real_escape_string($col) . "'");
    if ($res && $res->num_rows > 0) {
        _migNotifScope_out("  [SKIP] tblNotifications.{$col} already present.");
        return;
    }
    $db->query("ALTER TABLE tblNotifications ADD COLUMN {$ddl}");
    _migNotifScope_out("  [OK] Added tblNotifications.{$col}.");
}

$db = function_exists('getDbMysqli') ? getDbMysqli() : null;
if (!($db instanceof mysqli)) {
    _migNotifScope_out('ERROR: could not connect to database.');
    if ($isCli) { exit(1); }
    return;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

_migNotifScope_out('=== iHymns — Notifications scope + expiry (#1238) ===');

try {
    /* DDL byte-identical to appWeb/.sql/schema.sql (rule #19). */
    _migNotifScope_addCol(
        $db,
        'Environment',
        "Environment VARCHAR(16) NULL DEFAULT NULL COMMENT 'Target environment (#1238): NULL = all; alpha / beta / production = that env only' AFTER IsRead"
    );
    _migNotifScope_addCol(
        $db,
        'ExpiresAt',
        "ExpiresAt DATETIME NULL DEFAULT NULL COMMENT 'Optional expiry (#1238): NULL = never; the client hides the notification after this moment' AFTER Environment"
    );
    _migNotifScope_out('');
    _migNotifScope_out('Migration complete — notifications can now be environment-scoped and/or set to expire.');
} catch (\Throwable $e) {
    _migNotifScope_out('  [ERROR] ' . $e->getMessage());
    if ($isCli) { exit(1); }
    return;
}

return;
