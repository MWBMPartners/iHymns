<?php

declare(strict_types=1);

/**
 * iHymns — ProPresenter auto-advance timeline capture: DORMANT groundwork (#1968)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Owner steer (2026-08-28): "build the groundwork/schema now, but auto-advance
 * usage is OFF by default with a toggle, fully fleshed out later." This
 * migration is that groundwork — ONE new additive table plus ONE dormant
 * app-setting toggle, both inert until later playback work exists:
 *
 *   - tblSongPresentationCues — one row per (song, arrangement, position)
 *     auto-advance cue captured from an imported ProPresenter `.pro`/
 *     `.probundle` file's `Presentation.timeline.cues` (decoded by
 *     `pp7DecodeTimeline()`/`pp7DecodeTimelineCue()` in
 *     includes/propresenter7_decode.php). `ComponentId` stays NULL — mapping
 *     a captured cue onto an iHymns `tblSongComponents` row is deliberately a
 *     LATER step (the playback/auto-advance UI this task explicitly does not
 *     build); this table only CAPTURES + STORES what the import saw.
 *     One-pass forward-looking (rule #20): `ArrangementName` is the
 *     multiplicity discriminator up front (a song can carry several imported
 *     arrangements/timelines) so a future multi-arrangement importer never
 *     needs a second ALTER to add it. `Source` is VARCHAR (a growable
 *     provenance vocabulary — 'propresenter' today, never an ENUM per rule
 *     #20: a second import source would otherwise need an ENUM value-add
 *     ALTER, exactly the second-migration class this repo forbids).
 *
 *   - tblAppSettings.pp7_timeline_import_enabled = '0' — the usage toggle.
 *     While '0' (the default), `includes/pp7_timeline.php`'s
 *     `pp7TimelineImportEnabled()` returns false and the whole capture path
 *     (wired into `_bulkImport_parsePro7()`'s decode carry-through + the
 *     ingest-time `pp7TimelineStore()` call, both in
 *     includes/song_importers.php) is a verified no-op — import behaviour is
 *     byte-identical to before this task. NOTE for anyone comparing this to
 *     `pp7_media_ingest_enabled` (#1968 P4): that flag is NOT actually seeded
 *     as a settings row anywhere in this codebase — it relies solely on
 *     `getAppSetting('pp7_media_ingest_enabled', '0')`'s code-level default
 *     parameter, with no INSERT ever run. This migration instead follows the
 *     more common seed-a-toggle-row pattern used by
 *     migrate-add-audio-signing-setting.php / migrate-captcha-outage-settings.php
 *     (INSERT IGNORE, idempotent, never resets an operator's later '1' back
 *     to '0') so the toggle is discoverable in tblAppSettings from the
 *     moment this migration runs, not only once later code happens to read
 *     it.
 *
 * VERIFIED NO-OP: a brand-new table nothing yet writes to, plus a settings
 * row defaulting '0' that nothing yet reads a non-default value from. Safe
 * to run on any env immediately — no behavioural change until BOTH the
 * capture code (this same PR) AND the toggle are flipped by the owner.
 *
 * IDEMPOTENT: `CREATE TABLE IF NOT EXISTS` + `INSERT IGNORE`; re-running is a
 * no-op both times.
 *
 * Rule #41: this migration needs NO shared includes (a bare CREATE TABLE +
 * an INSERT IGNORE need nothing from includes/), so — mirroring
 * migrate-song-media-visibility.php exactly — it never hardcodes a
 * `/public_html/…` require at all; it opens its own raw mysqli connection
 * from `.auth/db_credentials.php`.
 *
 * SCHEMA MIRROR: tblSongPresentationCues is mirrored byte-identical (incl.
 * COMMENT text) in appWeb/.sql/schema.sql — rule #19.
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-pp7-timeline-groundwork.php
 *   Web:  /manage/setup-database → "ProPresenter timeline groundwork (#1968)" button
 *
 * @requires PHP 8.1+ with mysqli
 * @see appWeb/public_html/includes/propresenter7_decode.php   pp7DecodeTimeline()/pp7DecodeTimelineCue()
 * @see appWeb/public_html/includes/pp7_timeline.php            the gated capture helpers (this task, commit 3)
 * @see appWeb/public_html/includes/song_importers.php          _bulkImport_parsePro7() timeline carry-through
 * @see tests/php/test-pp7-timeline.php                         the decode + capture guard
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

function _migPp7Timeline_output(string $m): void { global $isCli; echo $m . ($isCli ? "\n" : "<br>\n"); if (!$isCli) flush(); }
function _migPp7Timeline_tableExists(\mysqli $db, string $t): bool { $r = $db->query("SHOW TABLES LIKE '{$t}'"); return (bool)($r && $r->num_rows > 0); }

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) { _migPp7Timeline_output("ERROR: MySQL credentials not found. Run install.php first."); return; }
require_once $credFile;

_migPp7Timeline_output("");
_migPp7Timeline_output("=== iHymns — ProPresenter auto-advance timeline capture: DORMANT groundwork (#1968) ===");
_migPp7Timeline_output("");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    $mysql->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
} catch (\mysqli_sql_exception $e) {
    _migPp7Timeline_output("ERROR: MySQL connection failed: " . $e->getMessage()); return;
}
_migPp7Timeline_output("Connected to MySQL: " . DB_NAME);

try {
    _migPp7Timeline_output("--- tblSongPresentationCues ---");
    if (_migPp7Timeline_tableExists($mysql, 'tblSongPresentationCues')) {
        _migPp7Timeline_output("  [SKIP] tblSongPresentationCues already present.");
    } else {
        $mysql->query(
            "CREATE TABLE IF NOT EXISTS tblSongPresentationCues (
                Id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                SongId          VARCHAR(20)   NOT NULL COMMENT 'FK to tblSongs.SongId — the song this captured auto-advance timeline belongs to.',
                ArrangementName VARCHAR(100)  NOT NULL DEFAULT '' COMMENT 'Multiplicity discriminator (#1968): a song may carry several imported arrangements/timelines; empty string = the default/primary timeline. Part of the (SongId, ArrangementName, SortOrder) uniqueness key so re-importing the same arrangement is idempotent.',
                SortOrder       INT UNSIGNED  NOT NULL COMMENT 'Ordinal position of this cue within its (SongId, ArrangementName) timeline, 0-based — the order ProPresenter played the cues in.',
                TriggerSeconds  DECIMAL(12,4) NOT NULL COMMENT 'Auto-advance trigger time in seconds from the start of the timeline (ProPresenter Timeline.Cue.trigger_time). DECIMAL, not FLOAT/DOUBLE, for exact stored precision — no float rounding drift across re-reads.',
                SourceCueUuid   CHAR(36)      NULL COMMENT 'The source ProPresenter cue UUID (Timeline.Cue.cue_id), when the cue carried one — for later mapping to an iHymns tblSongComponents row. NULL for a media-triggering timeline entry (the cue_id/action oneof took the action branch instead).',
                ComponentId     INT UNSIGNED  NULL COMMENT 'The mapped iHymns tblSongComponents.Id, once mapping exists. NULL until the mapping/playback work (deliberately not built by this dormant-groundwork task) is fleshed out later — no FK yet, by design.',
                CueName         VARCHAR(100)  NULL COMMENT 'The source cue name as ProPresenter labelled it, e.g. \"Cue 2\" — display/debugging aid only, not authoritative for anything.',
                Source          VARCHAR(30)   NOT NULL DEFAULT 'propresenter' COMMENT 'Provenance vocabulary (today: propresenter). VARCHAR, not ENUM, per rule #20 — a future non-ProPresenter timeline source needs one new value, never an ALTER.',
                CreatedAt       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UpdatedAt       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                UNIQUE KEY uq_song_arr_sort (SongId, ArrangementName, SortOrder),
                INDEX idx_SongId (SongId),

                CONSTRAINT fk_pres_cues_song
                    FOREIGN KEY (SongId) REFERENCES tblSongs(SongId) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            COMMENT='ProPresenter auto-advance timeline capture (#1968 dormant groundwork) — one row per captured (song, arrangement, position) cue. Entirely inert until tblAppSettings.pp7_timeline_import_enabled=1 AND the (not-yet-built) playback UI exists.'"
        );
        _migPp7Timeline_output("  [OK] Created tblSongPresentationCues.");
    }

    _migPp7Timeline_output("");
    _migPp7Timeline_output("--- tblAppSettings.pp7_timeline_import_enabled ---");
    /* INSERT IGNORE — idempotent + non-destructive. SettingKey is UNIQUE, so a
       second run (or one after an operator flips it to '1') never resets a
       live value back to '0'. */
    $key  = 'pp7_timeline_import_enabled';
    $val  = '0';
    $desc = 'Capture ProPresenter auto-advance timeline cues into tblSongPresentationCues on import (#1968). '
          . '0=off (byte-identical to before this feature), 1=on (capture only - no playback UI exists yet).';

    $stmt = $mysql->prepare(
        'INSERT IGNORE INTO tblAppSettings (SettingKey, SettingValue, Description) VALUES (?, ?, ?)'
    );
    $stmt->bind_param('sss', $key, $val, $desc);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected > 0) {
        _migPp7Timeline_output("  [OK] Seeded tblAppSettings.pp7_timeline_import_enabled = '0'.");
    } else {
        _migPp7Timeline_output("  [SKIP] tblAppSettings.pp7_timeline_import_enabled already present — left untouched.");
    }

    _migPp7Timeline_output("");
    _migPp7Timeline_output("Migration complete.");
} catch (\Throwable $e) {
    _migPp7Timeline_output("  [ERROR] " . $e->getMessage());
}
$mysql->close();
return;
