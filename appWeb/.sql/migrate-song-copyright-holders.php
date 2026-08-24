<?php

declare(strict_types=1);

/**
 * iHymns — Multi-holder song copyright: tblSongCopyrightHolders (#1900, Wave 4 Commit C7)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * Today a song has ONE copyright-holder name (`tblSongs.CopyrightHolder`) and,
 * dormantly, one holder ID (`tblSongs.CopyrightHolderId`, #1864). Real hymns
 * often have MORE than one holder — words by one publisher, music by another,
 * or a holder plus an administering agency. This migration adds ONE new
 * table, `tblSongCopyrightHolders`, that lets a song point at an ORDERED LIST
 * of publisher rows instead of just one. Nothing reads or writes it yet —
 * that is Wave 4 Commit C8 (the picker UI + api2.php endpoints). This commit
 * only lays the additive, dormant schema + the shared read/write core.
 *
 * DETAILED / WHY (#1900, plan .claude/wave4-actionable-remainder-plan.md §C7)
 * ------------------------------------------------------------------------
 * `tblSongCopyrightHolders` is a song<->publisher many-to-many, mirroring
 * `tblSongbookPublishers` (#93, rule #37) EXACTLY in shape — same column set,
 * same UNIQUE-on-(entity,publisher,role) discipline, same VARCHAR-not-ENUM
 * Role vocabulary (rule #20) — with two deliberate differences the spec calls
 * out:
 *
 *   1. `SongId VARCHAR(20)` (not an INT UNSIGNED FK like SongbookId) because a
 *      song's primary key IS its SongId string (`MP-1008`). The FK is
 *      `ON DELETE CASCADE ON UPDATE CASCADE` — NOT the plain `ON DELETE
 *      CASCADE`-only shape some sibling tables use, but crucially it MUST be
 *      `ON UPDATE CASCADE`: rule #1690 / #1695 (the songbook-relocate
 *      discipline) re-keys a song's SongId in place when a curator moves it
 *      to a different songbook, and any non-CASCADE FK on SongId freezes
 *      every song that has rows in that child table out of ever being moved.
 *      Skipping this step is not optional — see includes/song_relocate.php's
 *      `SONG_RELOCATE_EXPECTED_SONGID_FKS`, which this migration's sibling
 *      commit also updates so the CI-pinned relocate-cascade guard stays
 *      correct in both directions.
 *   2. `PublisherId` is `ON DELETE RESTRICT` (not CASCADE like
 *      `tblSongbookPublishers.PublisherId`) — a copyright holder that is
 *      actively cited on a song must not silently vanish if someone deletes
 *      the publisher row; the registry's own delete path already checks
 *      usage before allowing a delete (rule #37), so RESTRICT here is a
 *      second, cheap belt-and-braces guard at the DB layer.
 *
 * `Role` defaults to `'holder'` (a plain copyright holder is the common
 * case) and is validated app-side against the NEW `IHYMNS_COPYRIGHT_HOLDER_ROLES`
 * map in includes/publisher_helpers.php — a DISTINCT vocabulary from
 * `IHYMNS_PUBLISHER_ROLES` (that one is about a publisher's role ON A
 * SONGBOOK — distributor/imprint/printer; this one is about ownership of a
 * SONG's copyright — holder/co-holder/administrator/publisher). VARCHAR, not
 * ENUM (rule #20): a new role is a one-line map edit here, never an ALTER.
 *
 * DEPENDENCY: this migration's `PublisherId` FK requires `tblPublishers` to
 * already exist (created by the `'publishers-entity'` migration / registry
 * entry). On the shared install both have long since applied; a fresh
 * install reads both tables straight out of schema.sql, in the SAME
 * dependency order they appear in the file, so this is a non-issue there
 * too. Running this specific script standalone on an install that has never
 * run `migrate-publishers-entity.php` throws a clear mysqli FK error rather
 * than doing anything silently wrong — the registry's array-key ORDER
 * ('publishers-entity' runs far earlier than 'song-copyright-holders')
 * keeps "Apply all pending" correctly sequenced.
 *
 * No shared `includes/` file is required by this script — it is a
 * self-contained DDL migration (same as migrate-bulk-import-dryrun.php),
 * so rule #41's `IHYMNS_INCLUDES_DIR` resolution is N/A here: there is no
 * `require`/`include` of any file under a docroot's `includes/` directory
 * for a future editor to have to thread through that constant. Credentials
 * are loaded via `dirname(__DIR__) . '/.auth/db_credentials.php'` — never a
 * literal `/public_html/` path (rule #41).
 *
 * STRICTLY ADDITIVE + IDEMPOTENT. The single CREATE TABLE is
 * existence-guarded (`_migSongCopyHolders_tableExists()`), so re-running this
 * script after a successful apply is a no-op [SKIP], and re-running after a
 * failed apply resumes cleanly (there is only one object to create, so
 * "resume" here just means "try the CREATE again").
 *
 * @migration-adds tblSongCopyrightHolders
 *
 * SCHEMA MIRROR: the CREATE TABLE below is mirrored byte-identical (incl.
 * every COMMENT) in `appWeb/.sql/schema.sql`, immediately after the
 * `tblSongbookPublishers` block — rule #19.
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-song-copyright-holders.php
 *   Web:  /manage/setup-database → "Run Multi-Holder Copyright Migration" button
 *
 * @requires PHP 8.1+ with mysqli
 * @see .claude/wave4-actionable-remainder-plan.md §C7  the spec this migration implements
 * @see appWeb/public_html/manage/includes/migration-registry.php  'song-copyright-holders' entry
 * @see appWeb/public_html/includes/song_copyright_holders.php  the shared read/write core (dormant until C8 wires a caller)
 * @see appWeb/public_html/includes/publisher_helpers.php  IHYMNS_COPYRIGHT_HOLDER_ROLES + publisherResolvePickedOrCreate()
 * @see appWeb/public_html/includes/song_relocate.php  SONG_RELOCATE_EXPECTED_SONGID_FKS (fk_CopyHolders_Song entry)
 * @see appWeb/.sql/migrate-publishers-entity.php  the tblSongbookPublishers precedent this mirrors (#93)
 * @see #1900 #93 #1690
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

function _migSongCopyHolders_output(string $m): void { global $isCli; echo $m . ($isCli ? "\n" : "<br>\n"); if (!$isCli) flush(); }
function _migSongCopyHolders_tableExists(\mysqli $db, string $t): bool { $r = $db->query("SHOW TABLES LIKE '{$t}'"); return (bool)($r && $r->num_rows > 0); }

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) { _migSongCopyHolders_output("ERROR: MySQL credentials not found. Run install.php first."); return; }
require_once $credFile;

_migSongCopyHolders_output("");
_migSongCopyHolders_output("=== iHymns — Multi-holder song copyright (#1900) ===");
_migSongCopyHolders_output("");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    $mysql->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
} catch (\mysqli_sql_exception $e) {
    _migSongCopyHolders_output("ERROR: MySQL connection failed: " . $e->getMessage()); return;
}
_migSongCopyHolders_output("Connected to MySQL: " . DB_NAME);

try {
    _migSongCopyHolders_output("--- tblSongCopyrightHolders ---");
    if (_migSongCopyHolders_tableExists($mysql, 'tblSongCopyrightHolders')) {
        _migSongCopyHolders_output("  [SKIP] tblSongCopyrightHolders already present.");
    } else {
        $mysql->query(
            "CREATE TABLE IF NOT EXISTS tblSongCopyrightHolders (
                Id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                SongId       VARCHAR(20)  NOT NULL,
                PublisherId  INT UNSIGNED NOT NULL,
                Role         VARCHAR(30)  NOT NULL DEFAULT 'holder' COMMENT 'Role this publisher plays in this song''s copyright — app-validated, VARCHAR not ENUM (rule #20): holder | co-holder | administrator | publisher. Default holder.',
                SortOrder    INT UNSIGNED NOT NULL DEFAULT 0,
                Note         VARCHAR(255) NULL DEFAULT NULL,
                ValidFrom    DATE         NULL DEFAULT NULL COMMENT 'Reserved (#1900): start of this holder''s rights window for this song. Dormant until a rights-window feature lands.',
                ValidTo      DATE         NULL DEFAULT NULL COMMENT 'Reserved (#1900): end of this holder''s rights window for this song. Dormant until a rights-window feature lands.',
                CreatedAt    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

                UNIQUE KEY uq_song_pub_role (SongId, PublisherId, Role),
                INDEX      idx_sch_song (SongId),
                INDEX      idx_sch_pub  (PublisherId),

                CONSTRAINT fk_CopyHolders_Song
                    FOREIGN KEY (SongId)      REFERENCES tblSongs(SongId)   ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT fk_CopyHolders_Pub
                    FOREIGN KEY (PublisherId) REFERENCES tblPublishers(Id)  ON DELETE RESTRICT ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Song<->publisher many-to-many for multi-holder copyright (#1900).'"
        );
        _migSongCopyHolders_output("  [OK] Created tblSongCopyrightHolders.");
    }

    _migSongCopyHolders_output("");
    _migSongCopyHolders_output("=== Done. Multi-holder copyright table is in place (dormant until Wave 4 Commit C8 wires the picker). ===");
} catch (\mysqli_sql_exception $e) {
    _migSongCopyHolders_output("ERROR: migration failed: " . $e->getMessage());
    return;
}
