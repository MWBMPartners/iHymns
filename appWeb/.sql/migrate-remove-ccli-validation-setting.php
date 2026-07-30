<?php

declare(strict_types=1);

/**
 * iHymns — Remove the dead `ccli_validation_enabled` setting (#1668)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Deletes the `ccli_validation_enabled` row from tblAppSettings.
 *
 * ELI5: there was a switch on the wall labelled "copyright protection". It was
 * not connected to anything. Flipping it did nothing at all — but anyone who
 * flipped it would have walked away believing the building was locked. We are
 * taking the switch off the wall.
 *
 * WHY (the safety argument, #1640 → #1668):
 * A tree-wide scan found ZERO readers of this key — not in PHP, not in JS, not
 * in the native apps. Despite that, its seeded description read "Require valid
 * CCLI licence for copyrighted songs (0=off, 1=on)", which is exactly what an
 * operator looking for the CCLI enforcement switch would expect to find. The
 * real controls are:
 *   - `content_gating_enabled` = '1'                     (the master switch), AND
 *   - `require_licence:ccli` rows in tblContentRestrictions,
 * with the licence itself resolved by `userHasValidCcli()` in
 * includes/licences.php (#1668). A dead flag that misdescribes itself is worse
 * than no flag, because it converts "no protection" into "believed protection".
 *
 * schema.sql's copy of the seed was corrected in place earlier (#1640) but
 * `INSERT IGNORE` means EXISTING installs kept the original, false description
 * forever — a schema.sql edit can never reach them. Hence this migration.
 *
 * SAFE TO RUN: the row has no readers, so deleting it cannot change any
 * behaviour on any install. It is not registered as `manual`/destructive for
 * that reason — there is no data here to lose. Idempotent: a second run deletes
 * zero rows and reports so.
 *
 * @migration-modifies tblAppSettings (deletes ccli_validation_enabled)
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-remove-ccli-validation-setting.php
 *   Web:  /manage/setup-database -> "Remove dead ccli_validation_enabled setting (#1668)"
 *
 * @requires PHP 8.1+ with mysqli extension
 * @link https://dev.mysql.com/doc/refman/8.0/en/delete.html
 */

if (PHP_SAPI === 'cli') {
    if (!function_exists('getDbMysqli')) {
        require_once dirname(__DIR__) . '/public_html/includes/db_mysql.php';
    }
    $isCli = true;
} else {
    if (!defined('IHYMNS_SETUP_DASHBOARD')) {
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
    if (!function_exists('getDbMysqli')) {
        require_once dirname(__DIR__) . '/public_html/includes/db_mysql.php';
    }
    $isCli = false;
}

function _migRmCcliSetting_out(string $line): void
{
    global $isCli;
    echo $line . ($isCli ? "\n" : "<br>\n");
    if ($isCli) { flush(); }
}

_migRmCcliSetting_out('Remove ccli_validation_enabled setting migration starting (#1668)…');

$mysqli = getDbMysqli();
if (!$mysqli) {
    throw new \RuntimeException('Could not connect to database.');
}

/* Bound parameter, not interpolation — the project rule applies even to a
   constant string (rule #5 of .claude/CLAUDE.md). */
$key  = 'ccli_validation_enabled';
$stmt = $mysqli->prepare('DELETE FROM tblAppSettings WHERE SettingKey = ?');
$stmt->bind_param('s', $key);
if (!$stmt->execute()) {
    $stmt->close();
    throw new \RuntimeException('DELETE ccli_validation_enabled failed: ' . $mysqli->error);
}
$affected = $stmt->affected_rows;
$stmt->close();

if ($affected > 0) {
    _migRmCcliSetting_out("[del ] tblAppSettings.ccli_validation_enabled removed ({$affected} row).");
} else {
    _migRmCcliSetting_out('[skip] tblAppSettings.ccli_validation_enabled not present — nothing to do.');
}

_migRmCcliSetting_out('Remove ccli_validation_enabled setting migration finished (#1668).');
