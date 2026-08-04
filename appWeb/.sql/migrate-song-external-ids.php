<?php

declare(strict_types=1);

/**
 * iHymns — Song external-ID key/value store schema batch (epic #1741 D5)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * One-pass forward-looking schema (rule #20) for the "comprehensive external/
 * catalogue IDs" decision (D5, media-identifiers-spec.md §4c "B (recording-ID
 * storage) = yes"). Creates `tblSongExternalIds`, a key/value successor to
 * the one-column-per-provider `tblSongIdentityMap` shape (rule #28's named
 * anti-pattern — MusicBrainzRecordingMBID / SpotifyTrackId / GeniusTrackId /
 * IsrcCode each cost an ALTER to add). Luminate's own entity model is
 * Song -> many Recordings -> many provider IDs (~13 DSP + ~10 database +
 * several legacy codes, spec §4b) — a column-per-provider table cannot
 * absorb that; this generic (SongId, IdType, IdValue) table can, at zero
 * further ALTER cost per new provider (growing the vocabulary is a one-line
 * addition to includes/media_identifiers.php's RECORDING_EXTERNAL_ID_TYPES
 * map, never a migration).
 *
 * Additive + dormant: nothing reads or writes this table yet — the P3
 * alias-URL resolver (/isrc/ /iswc/ /ccli/ /ipi/ /isni/ /bowi/ routes) is
 * what starts consuming it, and is EXPLICITLY OUT OF SCOPE for this
 * storage-layer commit — so applying this card changes zero observable
 * behaviour.
 *
 *   - IdScope VARCHAR(20) — recording | song | work | release | product |
 *     party (app-validated against includes/media_identifiers.php's
 *     SONG_EXTERNAL_ID_SCOPES; VARCHAR not ENUM, rule #20). `work`/`party`
 *     are reserved slugs this phase never writes (they already have
 *     zero-schema-cost homes: WORK_IDENTIFIER_TYPES rides tblWorks columns /
 *     tblSongRoyaltyIds.Authority; party rides musician_helpers.php's
 *     CREDIT_IDENTIFIER_TYPES / tblMusicianIdentifiers).
 *   - IdType VARCHAR(40) — the provider/database/legacy-code key
 *     (app-validated against RECORDING_EXTERNAL_ID_TYPES). Decision A=c
 *     (spec §4c): release/product IdTypes (icpn/grid/upc/ean/catalog-number/
 *     mc-release/mc-release-group/mc-product) are stored on this SAME
 *     recording-grain row as ingest provenance (IdScope='release'|'product'),
 *     NOT a new tblReleases entity — iHymns is a hymn-lyrics catalogue, not a
 *     commercial release database.
 *   - IdValue VARCHAR(191) — 191 (not 255) so a utf8mb4 UNIQUE index on it
 *     stays under the legacy InnoDB 767-byte-per-column limit (191*4=764),
 *     the same reasoning that makes 191 the conventional "safe max length
 *     for a unique-indexed utf8mb4 column" in this ecosystem.
 *   - Source / SourceRef — free-text provenance (which system supplied this
 *     row, and that system's own primary id for it), mirroring the
 *     Source/SourceRef shape already used across the #1066/#1088 table
 *     families; NOT part of a UNIQUE key here (unlike some of those
 *     siblings) because Deliverable 1's exact spec calls for exactly ONE
 *     UNIQUE — (SongId, IdType, IdValue) — a re-import from a different
 *     Source for the SAME (SongId, IdType, IdValue) is expected to collide
 *     on that key and be treated as confirming the same fact, not a new row.
 *   - The 4 existing tblSongIdentityMap columns (MusicBrainzRecordingMBID /
 *     SpotifyTrackId / GeniusTrackId / IsrcCode) are GRANDFATHERED READS —
 *     UNTOUCHED by this migration. No data is copied into the new table and
 *     no reader is switched over; a later backfill MAY populate
 *     tblSongExternalIds from them, but that migration + the read-path
 *     switch are both out of scope here (this batch is purely additive).
 *
 * STRICTLY ADDITIVE + IDEMPOTENT. The CREATE TABLE is existence-guarded.
 *
 * SCHEMA MIRROR: the whole table is mirrored byte-identical in
 * appWeb/.sql/schema.sql, placed next to tblSongIdentityMap — rule #19.
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-song-external-ids.php
 *   Web:  /manage/setup-database → "Song external IDs (#1741 D5)" button
 *
 * @requires PHP 8.1+ with mysqli
 * @see .claude/media-identifiers-spec.md §4b (taxonomy), §4c (decisions A=c, B=yes)
 * @see .claude/catalogue-expansion-1741-plan.md §2 (schema-convention precedent)
 * @see appWeb/public_html/includes/media_identifiers.php (the IdScope/IdType vocabulary)
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

function _migSongExtIds_output(string $m): void { global $isCli; echo $m . ($isCli ? "\n" : "<br>\n"); if (!$isCli) flush(); }
function _migSongExtIds_tableExists(\mysqli $db, string $t): bool { $r = $db->query("SHOW TABLES LIKE '{$t}'"); return (bool)($r && $r->num_rows > 0); }

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) { _migSongExtIds_output("ERROR: MySQL credentials not found. Run install.php first."); return; }
require_once $credFile;

_migSongExtIds_output("");
_migSongExtIds_output("=== iHymns — Song external-ID key/value store schema batch (#1741 D5) ===");
_migSongExtIds_output("");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    $mysql->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
} catch (\mysqli_sql_exception $e) {
    _migSongExtIds_output("ERROR: MySQL connection failed: " . $e->getMessage()); return;
}
_migSongExtIds_output("Connected to MySQL: " . DB_NAME);

try {
    _migSongExtIds_output("--- tblSongExternalIds ---");
    if (_migSongExtIds_tableExists($mysql, 'tblSongExternalIds')) {
        _migSongExtIds_output("  [SKIP] tblSongExternalIds already present.");
    } else {
        $mysql->query(
            "CREATE TABLE tblSongExternalIds (
                Id          INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
                SongId      VARCHAR(20)   NOT NULL COMMENT 'FK to tblSongs.SongId — the recording-grain row this identifier belongs to (or its release/product context under decision A=c, media-identifiers-spec.md §4c)',
                IdScope     VARCHAR(20)   NOT NULL COMMENT 'recording | song | work | release | product | party (app-validated against includes/media_identifiers.php SONG_EXTERNAL_ID_SCOPES; VARCHAR not ENUM, rule #20). work/party are reserved — not populated by this phase',
                IdType      VARCHAR(40)   NOT NULL COMMENT 'Provider/database/legacy identifier key, e.g. isrc | spotify | musicbrainz-recording | icpn | mc-release | … — app-validated against includes/media_identifiers.php RECORDING_EXTERNAL_ID_TYPES (VARCHAR not ENUM, rule #20; #1741 D5)',
                IdValue     VARCHAR(191)  NOT NULL COMMENT 'The identifier value as issued by the provider/authority. VARCHAR(191) keeps a utf8mb4 UNIQUE index under the legacy 767-byte-per-column InnoDB limit',
                Source      VARCHAR(40)   NULL DEFAULT NULL COMMENT 'Provenance system that supplied this row, e.g. ihymns | musicbrainz | luminate | manual (free text, mirrors tblLyrics.Source style)',
                SourceRef   VARCHAR(191)  NULL DEFAULT NULL COMMENT 'External primary id from Source for idempotent re-import / dedup; NULL for manual entry',
                CreatedAt   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UpdatedAt   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                UNIQUE KEY uq_Song_Type_Value (SongId, IdType, IdValue),
                INDEX      idx_Type_Value     (IdType, IdValue),
                INDEX      idx_SongId         (SongId),

                CONSTRAINT fk_SongExternalIds_Song
                    FOREIGN KEY (SongId) REFERENCES tblSongs(SongId) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Key/value recording/release/product external-ID store (#1741 D5) — the Q5 successor absorbing DSP/database/legacy IDs without a per-provider ALTER.'"
        );
        _migSongExtIds_output("  [OK] Created tblSongExternalIds.");
    }

    _migSongExtIds_output("");
    _migSongExtIds_output("Migration complete.");
} catch (\Throwable $e) {
    _migSongExtIds_output("  [ERROR] " . $e->getMessage());
}
$mysql->close();
return;
