<?php

declare(strict_types=1);

/**
 * iHymns — tblSongMedia.Visibility publish-state column (#1968 P4)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Adds ONE additive, dormant column — `tblSongMedia.Visibility` — the per-row
 * publish state that P4 (ProPresenter media ingest) needs so imported bundle
 * media can land curator-only ("admin") and be served publicly only when a
 * curator opts that song's media in (owner decision D1,
 * .claude/propresenter-interop-1968-plan.md §6.1).
 *
 *   - tblSongMedia.Visibility VARCHAR(20) NOT NULL DEFAULT 'public' —
 *     a growable, app-validated vocabulary (`public | admin` today; `org` and
 *     `pending` are RESERVED future values, each a one-line addition to
 *     IHYMNS_SONG_MEDIA_VISIBILITIES in includes/song_media_visibility.php),
 *     so VARCHAR not ENUM (rule #20 — an ENUM value-add is the second ALTER we
 *     forbid). The serving gate resolves through that ONE helper file at both
 *     grains (the list-emit SQL filter + the song-media.php byte gate) so they
 *     cannot diverge (rule #35).
 *
 * VERIFIED NO-OP for all existing content: the `NOT NULL DEFAULT 'public'`
 * stamps every current row `'public'`, so every read that later adds the
 * `AND Visibility = 'public'` filter matches exactly the rows it matched
 * before. Nothing writes a non-`public` row until P4's ingest lands AND the
 * owner flips `tblAppSettings.pp7_media_ingest_enabled` (default '0'), so this
 * card is safe to run early on any env — the column alone changes no behaviour.
 *
 * No new index: every consumer filters by `SongId` first (idx_song_kind covers
 * it); the visibility predicate is residual over a handful of rows per song.
 *
 * STRICTLY ADDITIVE + IDEMPOTENT. The ADD COLUMN is existence-guarded, so
 * re-running is a no-op.
 *
 * @migration-adds tblSongMedia.Visibility
 *
 * SCHEMA MIRROR: the column is mirrored byte-identical (incl. COMMENT text) in
 * appWeb/.sql/schema.sql's tblSongMedia CREATE TABLE block — rule #19.
 *
 * Rule #41: this migration needs NO shared includes, so it hardcodes no
 * `/public_html/…` path — it defines its own tiny probe helper below.
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-song-media-visibility.php
 *   Web:  /manage/setup-database → "Song media visibility (#1968 P4)" button
 *
 * @requires PHP 8.1+ with mysqli
 * @see .claude/propresenter-interop-1968-plan.md §6.1, §6.3
 * @see appWeb/public_html/includes/song_media_visibility.php  the vocabulary + serving gate (commit 3)
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

function _migSongMediaVis_output(string $m): void { global $isCli; echo $m . ($isCli ? "\n" : "<br>\n"); if (!$isCli) flush(); }
function _migSongMediaVis_colExists(\mysqli $db, string $t, string $c): bool { $r = $db->query("SHOW COLUMNS FROM {$t} LIKE '{$c}'"); return (bool)($r && $r->num_rows > 0); }

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) { _migSongMediaVis_output("ERROR: MySQL credentials not found. Run install.php first."); return; }
require_once $credFile;

_migSongMediaVis_output("");
_migSongMediaVis_output("=== iHymns — tblSongMedia.Visibility publish-state column (#1968 P4) ===");
_migSongMediaVis_output("");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    $mysql->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
} catch (\mysqli_sql_exception $e) {
    _migSongMediaVis_output("ERROR: MySQL connection failed: " . $e->getMessage()); return;
}
_migSongMediaVis_output("Connected to MySQL: " . DB_NAME);

try {
    _migSongMediaVis_output("--- tblSongMedia.Visibility ---");
    if (_migSongMediaVis_colExists($mysql, 'tblSongMedia', 'Visibility')) {
        _migSongMediaVis_output("  [SKIP] tblSongMedia.Visibility already present.");
    } else {
        $mysql->query(
            "ALTER TABLE tblSongMedia
                ADD COLUMN Visibility VARCHAR(20) NOT NULL DEFAULT 'public'
                    COMMENT 'Publish state (#1968 P4): public | admin. App-validated via IHYMNS_SONG_MEDIA_VISIBILITIES in includes/song_media_visibility.php; VARCHAR not ENUM (rule #20 — org / pending are reserved future values, each a one-line map addition, never an ALTER). admin = curator-only: stripped from every public list emit and denied bytes at song-media.php; imported ProPresenter media lands admin until a curator publishes it (owner decision D1).'
                AFTER Annotation"
        );
        _migSongMediaVis_output("  [OK] Added tblSongMedia.Visibility (VARCHAR(20) NOT NULL DEFAULT 'public').");
    }

    _migSongMediaVis_output("");
    _migSongMediaVis_output("Migration complete.");
} catch (\Throwable $e) {
    _migSongMediaVis_output("  [ERROR] " . $e->getMessage());
}
$mysql->close();
return;
