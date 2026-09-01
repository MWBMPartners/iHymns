<?php

declare(strict_types=1);

/**
 * iHymns — Per-user song markup / notes: dormant backend (#1266 Phase 1)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * ONE new additive table, `tblUserSongMarkup`, for a congregant's PRIVATE
 * per-song markup: a note anchored to a lyric line (or the whole song) and/or
 * a highlight span. Modelled on the #1088 per-line enrichment pair
 * (tblLyricLineTranslations / tblLyricLineAnnotations) but scoped to ONE
 * user rather than published to everyone — this is a personal margin note,
 * not a curated/shared annotation, so there is no Status/moderation column.
 *
 *   - Kind        = note | highlight (VARCHAR app-validated central map,
 *                   rule #20 — never ENUM, see includes/user_markup.php's
 *                   USER_MARKUP_KINDS). `drawing` is a foreseeable third kind
 *                   (freehand pen strokes over the lyric sheet); MetaJson
 *                   already gives it a home so adding it later is a vocab
 *                   entry, never a second migration.
 *   - StartLineId / EndLineId = optional anchor into tblLyricLines. NULL
 *                   means song-level (a note about the whole song, not one
 *                   line). Both FKs are ON DELETE SET NULL — deliberately
 *                   NOT CASCADE — so a user's own note degrades to
 *                   song-level when the line it was pinned to is edited
 *                   away, rather than being silently deleted out from under
 *                   them (mirrors tblSongScriptureRefs.StartLineId, #1112).
 *   - StartOffset / EndOffset = DORMANT v1 — 0-based UTF-8 code-point
 *     indices (rule #21) reserved for a future phrase-level highlight; no
 *     writer sets them in Phase 1 (includes/user_markup.php doesn't expose
 *     them), so adding that later is a read/write-path change against an
 *     already-shaped column, never a second migration.
 *   - Colour      = highlight colour token, VARCHAR app-validated central
 *                   map (USER_MARKUP_COLOURS); NULL = no colour.
 *   - Body        = the note text; NULL for a pure highlight (no text).
 *   - MetaJson    = forward-looking growth (pen-stroke geometry, etc.)
 *                   inside JSON so a later Kind never needs a second ALTER
 *                   (rule #20). DORMANT — nothing reads or writes it yet.
 *
 * Ownership: UserId FK CASCADEs — deleting the account deletes its private
 * notes with it (there is no "who else can see this" question to preserve;
 * this is a personal layer, never published to other users). SongId FK
 * CASCADEs — a deleted song takes its markup with it.
 *
 * DORMANT ON ARRIVAL: this migration creates ONE new table that nothing
 * reads or writes yet. The three api.php actions this same commit adds
 * (user_markup_list / user_markup_upsert / user_markup_delete) are brand
 * new action names nobody has ever called — no client exists until Phase 2
 * (a SEPARATE later commit) wires one — so applying this migration is a
 * verified no-op for every existing page.
 *
 * STRICTLY ADDITIVE + IDEMPOTENT: `CREATE TABLE IF NOT EXISTS` plus the
 * existence-guarded [SKIP] path below, so a re-run is a single SHOW-style
 * probe and one [SKIP] line.
 *
 * Rule #41: this migration needs NO shared includes (a bare CREATE TABLE
 * needs nothing from includes/), so — mirroring
 * migrate-pp7-timeline-groundwork.php / migrate-song-media-visibility.php
 * exactly — it never hardcodes a `/public_html/…` require at all; it opens
 * its own raw mysqli connection from `.auth/db_credentials.php`.
 *
 * SCHEMA MIRROR: this CREATE TABLE is mirrored byte-identically (COMMENT
 * strings included) in appWeb/.sql/schema.sql, so a fresh install matches a
 * migrated one (CLAUDE.md rule #19; tests/php/test-schema-coverage.php).
 *
 * SONG-RELOCATE COVERAGE: tblUserSongMarkup.SongId is registered in
 * SONG_RELOCATE_EXPECTED_SONGID_FKS (includes/song_relocate.php) with a
 * `null` fix-migration slug — this migration IS what creates the FK, so an
 * install that hasn't run it simply hasn't run this card yet, same as every
 * other post-#1064 table on that list (tests/php/
 * test-song-relocate-cascade-verdict.php pins this).
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-user-song-markup.php
 *   Web:  /manage/setup-database → "Per-user song markup / notes" button
 *
 * @requires PHP 8.1+ with mysqli
 * @see https://github.com/MWBMPartners/iHymns/issues/1266
 * @see appWeb/public_html/includes/user_markup.php   the validators + read/write layer this backs
 * @see tests/php/test-user-markup.php                the pure-validator CI guard
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

function _migUserMarkup_output(string $m): void { global $isCli; echo $m . ($isCli ? "\n" : "<br>\n"); if (!$isCli) flush(); }
function _migUserMarkup_tableExists(\mysqli $db, string $t): bool
{
    $stmt = $db->prepare(
        "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1"
    );
    $stmt->bind_param('s', $t);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $exists;
}

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) { _migUserMarkup_output("ERROR: MySQL credentials not found. Run install.php first."); return; }
require_once $credFile;

_migUserMarkup_output("");
_migUserMarkup_output("=== iHymns — Per-user song markup / notes (#1266 Phase 1) ===");
_migUserMarkup_output("");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    $mysql->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
} catch (\mysqli_sql_exception $e) {
    _migUserMarkup_output("ERROR: MySQL connection failed: " . $e->getMessage());
    return;
}
_migUserMarkup_output("Connected to MySQL: " . DB_NAME);

try {
    _migUserMarkup_output("--- tblUserSongMarkup ---");
    if (_migUserMarkup_tableExists($mysql, 'tblUserSongMarkup')) {
        _migUserMarkup_output("  [SKIP] tblUserSongMarkup already present.");
    } else {
        $mysql->query(
            "CREATE TABLE IF NOT EXISTS tblUserSongMarkup (
                Id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                UserId      INT UNSIGNED    NOT NULL COMMENT 'FK to tblUsers.Id — the owning user. This is a PRIVATE layer: never published, never readable by another user.',
                SongId      VARCHAR(20)     NOT NULL COMMENT 'FK to tblSongs.SongId — the song this markup belongs to.',
                Kind        VARCHAR(20)     NOT NULL DEFAULT 'note' COMMENT 'note | highlight — app-validated central map (VARCHAR never ENUM, rule #20); future: drawing',
                StartLineId BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'Anchor line; NULL = song-level. SET NULL on line death: a user note degrades to song-level, never silently deleted',
                EndLineId   BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'Optional span end line; NULL = single-line/song-level anchor (same StartLineId as EndLineId is not stored — mirrors tblLyricLineAnnotations)',
                StartOffset INT UNSIGNED    NULL DEFAULT NULL COMMENT 'DORMANT v1 — 0-based UTF-8 code-point (rule #21); phrase-level later, no second migration',
                EndOffset   INT UNSIGNED    NULL DEFAULT NULL COMMENT 'DORMANT v1 — exclusive code-point end',
                Colour      VARCHAR(20)     NULL DEFAULT NULL COMMENT 'highlight colour token, app-validated central map',
                Body        MEDIUMTEXT      NULL DEFAULT NULL COMMENT 'note text; NULL for a pure highlight',
                MetaJson    JSON            NULL DEFAULT NULL COMMENT 'forward-looking growth (pen strokes etc.) inside JSON, never a second ALTER',
                CreatedAt   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UpdatedAt   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                INDEX idx_UserSong (UserId, SongId),
                INDEX idx_StartLine (StartLineId),

                CONSTRAINT fk_UserMarkup_User
                    FOREIGN KEY (UserId) REFERENCES tblUsers(Id) ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT fk_UserMarkup_Song
                    FOREIGN KEY (SongId) REFERENCES tblSongs(SongId) ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT fk_UserMarkup_StartLine
                    FOREIGN KEY (StartLineId) REFERENCES tblLyricLines(Id) ON DELETE SET NULL ON UPDATE CASCADE,
                CONSTRAINT fk_UserMarkup_EndLine
                    FOREIGN KEY (EndLineId) REFERENCES tblLyricLines(Id) ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            COMMENT='Per-user private song markup — notes + highlights, dormant until Phase 2 UI (#1266).'"
        );
        _migUserMarkup_output("  [OK] Created tblUserSongMarkup.");
    }

    _migUserMarkup_output("");
    _migUserMarkup_output("--- Summary ---");
    _migUserMarkup_output("  Signed-in users can now (once Phase 2 lands a client) attach private notes");
    _migUserMarkup_output("  and highlights to a song or a specific lyric line. Entirely dormant: no");
    _migUserMarkup_output("  reader or writer exists in this codebase before Phase 2.");
    _migUserMarkup_output("");
    _migUserMarkup_output("Migration complete.");
} catch (\Throwable $e) {
    _migUserMarkup_output("  [ERROR] " . $e->getMessage());
}

$mysql->close();
return;
