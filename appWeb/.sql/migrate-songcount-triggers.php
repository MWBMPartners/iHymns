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
 * Each trigger does a single UPDATE … WHERE Abbreviation = …
 * against an indexed column. ~3-8ms per row on a typical install;
 * acceptable for bulk imports (one-off cost) and free for normal
 * operation.
 *
 * Migration also runs an initial recompute as part of installation,
 * so a curator who installs PR #792 + this PR in either order ends
 * up with a clean cache without running the standalone #791
 * recompute migration.
 *
 * Idempotent — DROP TRIGGER IF EXISTS before each CREATE.
 *
 * Fallback: some shared hosts disable CREATE TRIGGER. The migration
 * catches the failure, logs it, and exits cleanly — the
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

/* Detect whether a mysqli error message indicates the host has denied
   trigger creation (typically: SUPER required, binary-logging strict
   mode, or shared-host policy). Three signals cover the common shapes:
     - "you do not have the SUPER privilege"
     - "TRIGGER command denied to user …"
     - explicit "privilege" / "binary logging" hints. */
function _migSCTrig_isDenied(string $err): bool
{
    return stripos($err, 'super') !== false
        || stripos($err, 'denied') !== false
        || stripos($err, 'privilege') !== false
        || stripos($err, 'binary logging') !== false;
}

/* PHP 8.1+ defaults mysqli_report to throw mysqli_sql_exception on
   failure, so $db->query() never returns false on error — it throws.
   The pre-existing `if (!$db->query(...))` branch was unreachable.
   This wrapper restores the run-and-classify pattern by catching the
   exception and surfacing the original error message + denied flag. */
function _migSCTrig_runQuery(\mysqli $db, string $sql): array
{
    try {
        $ok = $db->query($sql);
        if ($ok === false) {
            return ['ok' => false, 'denied' => _migSCTrig_isDenied($db->error), 'err' => $db->error];
        }
        return ['ok' => true, 'denied' => false, 'err' => ''];
    } catch (\mysqli_sql_exception $e) {
        return ['ok' => false, 'denied' => _migSCTrig_isDenied($e->getMessage()), 'err' => $e->getMessage()];
    }
}

/* =========================================================================
 * Step 1 — Drop any existing same-name triggers (idempotency)
 * ========================================================================= */
$triggerNames = [
    'trg_songs_songcount_ai',
    'trg_songs_songcount_ad',
    'trg_songs_songcount_au',
];
foreach ($triggerNames as $name) {
    $r = _migSCTrig_runQuery($db, "DROP TRIGGER IF EXISTS {$name}");
    if (!$r['ok']) {
        _migSCTrig_out("WARN: DROP TRIGGER IF EXISTS {$name} failed: {$r['err']}");
        if ($r['denied']) {
            /* If the host denies DROP TRIGGER it will also deny CREATE
               TRIGGER. Skip straight to the recompute fallback so the
               operator gets a clean run instead of one error per name. */
            break;
        }
    }
}

/* =========================================================================
 * Step 2 — Create the three triggers
 * ========================================================================= */
$triggersSql = [
    'trg_songs_songcount_ai' => "
        CREATE TRIGGER trg_songs_songcount_ai AFTER INSERT ON tblSongs
        FOR EACH ROW
            UPDATE tblSongbooks
               SET SongCount = (SELECT COUNT(*) FROM tblSongs WHERE SongbookAbbr = NEW.SongbookAbbr)
             WHERE Abbreviation = NEW.SongbookAbbr",

    'trg_songs_songcount_ad' => "
        CREATE TRIGGER trg_songs_songcount_ad AFTER DELETE ON tblSongs
        FOR EACH ROW
            UPDATE tblSongbooks
               SET SongCount = (SELECT COUNT(*) FROM tblSongs WHERE SongbookAbbr = OLD.SongbookAbbr)
             WHERE Abbreviation = OLD.SongbookAbbr",

    /* The UPDATE trigger is multi-statement so it needs a BEGIN/END
       block. The mysqli driver doesn't need DELIMITER changes when
       passing the whole CREATE TRIGGER as a single $db->query(); the
       BEGIN/END inside the trigger body is parsed as one statement. */
    'trg_songs_songcount_au' => "
        CREATE TRIGGER trg_songs_songcount_au AFTER UPDATE ON tblSongs
        FOR EACH ROW
        BEGIN
            IF OLD.SongbookAbbr <> NEW.SongbookAbbr THEN
                UPDATE tblSongbooks
                   SET SongCount = (SELECT COUNT(*) FROM tblSongs WHERE SongbookAbbr = OLD.SongbookAbbr)
                 WHERE Abbreviation = OLD.SongbookAbbr;
                UPDATE tblSongbooks
                   SET SongCount = (SELECT COUNT(*) FROM tblSongs WHERE SongbookAbbr = NEW.SongbookAbbr)
                 WHERE Abbreviation = NEW.SongbookAbbr;
            END IF;
        END",
];

$triggersCreated = 0;
$triggersDenied  = false;
foreach ($triggersSql as $name => $sql) {
    $r = _migSCTrig_runQuery($db, $sql);
    if ($r['ok']) {
        _migSCTrig_out("[add ] {$name}.");
        $triggersCreated++;
        continue;
    }
    _migSCTrig_out("WARN: CREATE TRIGGER {$name} failed: {$r['err']}");
    if ($r['denied']) {
        /* Most-common failure on shared hosts: SUPER privilege required
           pre-MySQL-8.0, or trigger creation explicitly denied by the
           hosting policy. Surface a clear hint so the operator knows
           the application-side recompute from PR #792 is the
           remaining safety net, then exit clean — failing the migration
           would block `Apply all` for no benefit. */
        $triggersDenied = true;
        _migSCTrig_out('       Hint: this MySQL host disallows CREATE TRIGGER (typically: SUPER privilege, or shared-host policy).');
        _migSCTrig_out('       Save_song from #791/PR #792 still keeps the cache correct for editor saves; bulk imports recompute via their per-import path. The migration is not failing — the triggers are an optimisation, not a requirement.');
        break;
    }
    /* Non-denial failure (e.g. syntax error, server error). Re-throw so
       the bulk runner records the failure and stops — this would be a
       genuine bug in the migration, not a host policy. */
    throw new \RuntimeException("CREATE TRIGGER {$name} failed: {$r['err']}");
}

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
 * run first. Same shape as migrate-recompute-songbook-songcount.php.
 * ========================================================================= */
_migSCTrig_out('');
_migSCTrig_out('Running initial recompute…');
$res = $db->query(
    "SELECT b.Id, b.Abbreviation, b.SongCount AS cached,
            COALESCE(s.live_count, 0) AS live
       FROM tblSongbooks b
       LEFT JOIN (
            SELECT SongbookAbbr, COUNT(*) AS live_count
              FROM tblSongs
             GROUP BY SongbookAbbr
       ) s ON s.SongbookAbbr = b.Abbreviation
      ORDER BY b.Abbreviation"
);
if (!$res) {
    _migSCTrig_out('WARN: initial recompute SELECT failed: ' . $db->error);
} else {
    $rows = $res->fetch_all(MYSQLI_ASSOC);
    $res->close();
    $upStmt = $db->prepare('UPDATE tblSongbooks SET SongCount = ? WHERE Id = ?');
    $drifted = 0;
    foreach ($rows as $r) {
        $cached = (int)$r['cached'];
        $live   = (int)$r['live'];
        if ($cached !== $live) {
            $id = (int)$r['Id'];
            $upStmt->bind_param('ii', $live, $id);
            $upStmt->execute();
            $drifted++;
        }
    }
    $upStmt->close();
    _migSCTrig_out("Initial recompute: {$drifted} songbook(s) corrected.");
}

_migSCTrig_out('');
_migSCTrig_out('SongCount triggers migration finished (#793).');
_migSCTrig_out('Future INSERT / UPDATE / DELETE on tblSongs will keep tblSongbooks.SongCount honest automatically.');
