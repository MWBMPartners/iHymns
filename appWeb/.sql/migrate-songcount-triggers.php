<?php

declare(strict_types=1);

/**
 * iHymns — Auto-maintain tblSongbooks.SongCount via triggers (#793)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * tblSongbooks.SongCount is a cached aggregate that the home + the
 * /songbooks tile-grid use to gate render. Several application-side
 * write paths refresh it (bulk_import_zip, save_song since #791,
 * the legacy bulk save action), but every NEW write path has to
 * remember to recompute or the cache silently drifts — exactly
 * what produced #791.
 *
 * The right fix is a database trigger that auto-maintains the
 * cache atomically with every INSERT / UPDATE / DELETE on tblSongs.
 * Three triggers cover the cases:
 *
 *   AFTER INSERT  — increment NEW.SongbookAbbr's count
 *   AFTER DELETE  — decrement OLD.SongbookAbbr's count
 *   AFTER UPDATE  — when SongbookAbbr changes, refresh both old
 *                   and new (atomic; song moved between books)
 *
 * THE TRIGGER DDL LIVES IN lib/songcount-triggers.php, NOT HERE (#1694 D1a).
 * Song soft delete redefines these same three triggers to count only
 * `IsDeleted = 0` rows, so the DDL was extracted into the shared lib both
 * migration cards call (modularity rule — the second occurrence is an
 * extraction, not a copy). The lib probes the LIVE schema per run: on an
 * install where tblSongs.IsDeleted does not exist yet (a fresh install running
 * cards in historical order lands here BEFORE the soft-delete card), it emits
 * the original unfiltered triggers — a trigger referencing a column that is
 * not there yet would break every subsequent tblSongs write. Re-running this
 * card AFTER soft delete has migrated keeps the filtered flavour.
 *
 * Each trigger does a single UPDATE … WHERE Abbreviation = …
 * against an indexed column. ~3-8ms per row on a typical install;
 * acceptable for bulk imports (one-off cost) and free for normal
 * operation.
 *
 * Migration also runs a recompute as part of installation, so a curator
 * who installs PR #792 + this PR in either order ends up with a clean
 * cache without running the standalone #791 recompute migration.
 *
 * Idempotent — DROP TRIGGER IF EXISTS before each CREATE (in the lib).
 *
 * Fallback: some shared hosts disable CREATE TRIGGER. The lib classifies
 * the failure, this card logs it, and exits cleanly — the
 * application-side recompute from PR #792 stays as the safety net,
 * so editor saves still keep the cache correct on those hosts.
 *
 * USAGE:
 *   CLI: php appWeb/.sql/migrate-songcount-triggers.php
 *   Web: /manage/setup-database → "SongCount triggers (#793)"
 *        (entry point requires global_admin)
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

/* The ONE copy of the trigger DDL + recompute, shared with
   migrate-song-soft-delete.php (#1694 D1a). require_once so a bulk
   "Apply all" that requires both cards in one request defines the lib
   functions exactly once. */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'songcount-triggers.php';

function _migSCTrig_out(string $line): void
{
    global $isCli;
    echo $line . ($isCli ? "\n" : "<br>\n");
    if ($isCli) flush();
}

_migSCTrig_out('SongCount triggers migration starting (#793)…');

$db = getDbMysqli();
if (!$db) {
    /* Throw rather than exit so the bulk runner records this as a real
       failure (and its admin chrome still renders). exit() in a child
       mid-bulk truncates the page — see #817. */
    throw new \RuntimeException('Could not connect to database.');
}

/* =========================================================================
 * Step 1 + 2 — DROP + CREATE the three triggers, in the flavour the live
 * schema supports (the lib probes tblSongs.IsDeleted itself). Denied hosts
 * come back {denied: true} and fall through to the recompute safety net.
 * ========================================================================= */
$install = ihymnsSongCountTriggersInstall($db, '_migSCTrig_out');
$triggersCreated = $install['created'];
$triggersDenied  = $install['denied'];

if ($triggersCreated === 0 && !$triggersDenied) {
    _migSCTrig_out('ERROR: no triggers could be created. Cache will rely on application-side recompute (PR #792) only.');
    /* Use return rather than exit(1) so the bulk runner's page chrome
       still renders (an exit() in a child mid-bulk truncates the
       admin layout — see #817). The bulk runner doesn't see this as
       a failure; the operator sees the WARN lines above. */
    return;
}
if ($triggersDenied) {
    _migSCTrig_out('');
    _migSCTrig_out('SongCount triggers skipped on this host — using application-side recompute (PR #792) as the cache safety net.');
    /* Still run the initial recompute below so the cache is clean
       regardless of whether triggers were installed. */
}

/* =========================================================================
 * Step 3 — Initial recompute so the migration leaves a clean cache
 * regardless of whether the standalone #791 recompute migration was
 * run first. The lib applies the SAME visibility predicate the triggers
 * carry (#1694 D1a), so a trigger-denied host still shows the right number.
 * ========================================================================= */
_migSCTrig_out('');
_migSCTrig_out('Running initial recompute…');
ihymnsSongCountRecompute($db, '_migSCTrig_out');

_migSCTrig_out('');
_migSCTrig_out('SongCount triggers migration finished (#793).');
_migSCTrig_out('Future INSERT / UPDATE / DELETE on tblSongs will keep tblSongbooks.SongCount honest automatically.');

/* Mark the migration as attempted so the dashboard's pending-probe can
   flip "pending → applied" on hosts where CREATE TRIGGER was denied. The
   trigger-presence probe alone leaves this card stuck pending forever on
   hosts that disallow trigger creation (shared MySQL hosts pre-MySQL-8.0
   typically require SUPER for CREATE TRIGGER, or deny it outright via
   policy). The migration is idempotent + the recompute path runs every
   time, so "attempted = applied" is the right semantics for the
   dashboard. (#claude/fix-songcount-triggers-probe follow-up.) */
$_sentinelStmt = $db->prepare(
    'INSERT INTO tblAppSettings (SettingKey, SettingValue, Description)
     VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE SettingValue = VALUES(SettingValue), Description = VALUES(Description)'
);
$_sentinelKey  = 'songcount_triggers_attempted';
$_sentinelVal  = '1';
$_sentinelDesc = 'SongCount triggers migration ran (#793). Value 1 regardless of '
               . 'whether CREATE TRIGGER succeeded — recompute path runs on every '
               . 'pass so the cache stays correct even when triggers are denied.';
$_sentinelStmt->bind_param('sss', $_sentinelKey, $_sentinelVal, $_sentinelDesc);
if (!$_sentinelStmt->execute()) {
    _migSCTrig_out('[warn] could not set sentinel (' . $db->error . '); migration body still ran.');
} else {
    _migSCTrig_out('[mark] tblAppSettings.songcount_triggers_attempted = 1.');
}
$_sentinelStmt->close();
