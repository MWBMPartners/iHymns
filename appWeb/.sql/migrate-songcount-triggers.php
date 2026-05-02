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
    _migSCTrig_out('ERROR: could not connect to database.');
    exit(1);
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
    if (!$db->query("DROP TRIGGER IF EXISTS {$name}")) {
        _migSCTrig_out("WARN: DROP TRIGGER IF EXISTS {$name} failed: " . $db->error);
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
$triggersFailed  = false;
foreach ($triggersSql as $name => $sql) {
    if ($db->query($sql)) {
        _migSCTrig_out("[add ] {$name}.");
        $triggersCreated++;
    } else {
        $triggersFailed = true;
        $err = $db->error;
        _migSCTrig_out("WARN: CREATE TRIGGER {$name} failed: {$err}");
        /* Most-common failure on shared hosts: SUPER privilege required
           pre-MySQL-8.0, or trigger creation explicitly denied by the
           hosting policy. Surface a clear hint so the operator knows
           the application-side recompute from PR #792 is the
           remaining safety net. */
        if (stripos($err, 'super') !== false || stripos($err, 'denied') !== false || stripos($err, 'privilege') !== false) {
            _migSCTrig_out('       Hint: this MySQL host disallows CREATE TRIGGER without SUPER. Save_song from #791/PR #792 still keeps the cache correct for editor saves; bulk imports continue to recompute via their existing per-import path.');
            break;
        }
    }
}

if ($triggersCreated === 0) {
    _migSCTrig_out('ERROR: no triggers could be created. Cache will rely on application-side recompute (PR #792) only.');
    exit(1);
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
