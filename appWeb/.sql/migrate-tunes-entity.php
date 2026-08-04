<?php

declare(strict_types=1);

/**
 * iHymns — Tune + meter as a first-class entity (#1090 P4)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Promote the hymn TUNE from free-text tblSongs.TuneName to an entity:
 *   - tblTunes (Name, Slug, MeterCode, MusicBrainzWorkMBID, HymnaryTuneId) —
 *     enables common-metre interchange ("sing this text to a tune they know")
 *     and de-dupes the same tune across 30+ hymnals.
 *   - tblTuneAliases — alternate names for a tune.
 *   - tblSongs.TuneId + fk_Songs_Tune (SET NULL) — link; TuneName kept as a
 *     JOIN-free denorm display mirror.
 * Backfills tblTunes from DISTINCT TuneName and sets TuneId (re-runnable).
 *
 * STRICTLY ADDITIVE + IDEMPOTENT.
 *
 * NOT DESTRUCTIVE. Nothing is dropped or overwritten: TuneName is left in place
 * as the denorm display mirror (so every existing reader keeps working with no
 * JOIN), and the backfill only ever writes TuneId where it is currently NULL.
 * The "undo" is DROP the two tables + the column; no song data is at risk.
 *
 * HOW IDEMPOTENCY IS ACHIEVED — five independent guards, because this migration
 * has five separately-failable steps and a re-run must resume from wherever the
 * last one stopped:
 *   1. tblTunes        — _migTunes_tableExists()
 *   2. tblTuneAliases  — _migTunes_tableExists()
 *   3. tblSongs.TuneId — _migTunes_colExists()
 *   4. idx_TuneId      — SHOW INDEX … WHERE Key_name
 *   5. fk_Songs_Tune   — _migTunes_fkExists() (INFORMATION_SCHEMA)
 * The BACKFILL has no guard and does not need one: it is INSERT IGNORE against
 * `UNIQUE KEY uq_Name`, then an UPDATE restricted to `TuneId IS NULL`. Running
 * it a second time creates 0 tunes and links 0 songs; running it after new songs
 * have been imported picks up exactly the new ones. That is why it is labelled
 * "re-runnable" rather than "skipped" — re-running is the intended repair.
 * The registry probe is the multi-object OR form (table AND column), per
 * CLAUDE.md rule #19, so a partial apply never shows the card green.
 *
 * OPERATOR VIEW: /manage/setup-database → card "Tune + meter entity (#1090 P4)"
 * → button "Run Tune Entity Migration". A first run ends with a line like
 * "Created 412 tunes; linked 5 130 songs to a TuneId"; a repeat run reports
 * "Created 0 tunes; linked 0 songs".
 *
 * SCHEMA MIRROR: tblTunes / tblTuneAliases / tblSongs.TuneId + idx_TuneId +
 * fk_Songs_Tune are all mirrored in appWeb/.sql/schema.sql, which is what a
 * FRESH install reads instead of running this script. The declarations must
 * stay byte-identical (COMMENT text included) or fresh and migrated installs
 * diverge and the Schema Audit page (#518) flags it — CLAUDE.md rule #19. Note
 * schema.sql has to add fk_Songs_Tune as a TRAILING ALTER because tblSongs is
 * declared before tblTunes in that file; here the ordering is natural.
 *
 * @migration-adds tblSongs.TuneId
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-tunes-entity.php
 *   Web:  /manage/setup-database → "Tune + meter entity" button
 *
 * @requires PHP 8.1+ with mysqli
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

function _migTunes_output(string $msg): void {
    global $isCli;
    echo $msg . ($isCli ? "\n" : "<br>\n");
    if (!$isCli) flush();
}
function _migTunes_tableExists(\mysqli $db, string $t): bool {
    $r = $db->query("SHOW TABLES LIKE '{$t}'");
    return (bool)($r && $r->num_rows > 0);
}
function _migTunes_colExists(\mysqli $db, string $t, string $c): bool {
    $r = $db->query("SHOW COLUMNS FROM {$t} LIKE '{$c}'");
    return (bool)($r && $r->num_rows > 0);
}
function _migTunes_fkExists(\mysqli $db, string $name): bool {
    $r = $db->query(
        "SELECT 1 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
          WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = '" . $db->real_escape_string($name) . "' LIMIT 1"
    );
    return (bool)($r && $r->num_rows > 0);
}
/* Tune name → URL handle ("HYFRYDOL" → "hyfrydol", "St. Anne" → "st-anne").
   Deliberately NOT ihymns_normalize_title(): that fold is the exact-dedup key
   for song titles and keeps spaces, whereas this has to produce something
   URL-safe and unique-able.

   Note iconv() here is plain ASCII//TRANSLIT with no //IGNORE, so on a name it
   cannot transliterate it returns false rather than a partial string; the
   `$s === false ? $name : $s` line falls back to the raw name and the
   [^a-z0-9]+ strip then removes whatever was left. A name made entirely of
   non-Latin characters therefore collapses to '' and is renamed 'tune' — the
   caller's collision loop turns the second such name into 'tune-2', so this
   degrades to ugly-but-unique rather than to a duplicate-key failure.
   The 130-char cap leaves headroom inside Slug's VARCHAR(140) for that
   '-N' suffix. */
function _migTunes_slugify(string $name): string {
    $s = iconv('UTF-8', 'ASCII//TRANSLIT', $name);
    $s = $s === false ? $name : $s;
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
    $s = trim($s, '-');
    return $s === '' ? 'tune' : substr($s, 0, 130);
}

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) { _migTunes_output("ERROR: MySQL credentials not found. Run install.php first."); return; }
require_once $credFile;

_migTunes_output("");
_migTunes_output("=== iHymns — Tune + meter entity (#1090 P4) ===");
_migTunes_output("");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    $mysql->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
} catch (\mysqli_sql_exception $e) {
    _migTunes_output("ERROR: MySQL connection failed: " . $e->getMessage()); return;
}
_migTunes_output("Connected to MySQL: " . DB_NAME);

try {
    _migTunes_output("--- tblTunes ---");
    if (_migTunes_tableExists($mysql, 'tblTunes')) {
        _migTunes_output("  [SKIP] tblTunes already present.");
    } else {
        $mysql->query(
            "CREATE TABLE tblTunes (
                Id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                Name                VARCHAR(120) NOT NULL COMMENT 'Canonical tune name, e.g. HYFRYDOL',
                Slug                VARCHAR(140) NOT NULL COMMENT 'URL-safe handle',
                MeterCode           VARCHAR(60)  NULL DEFAULT NULL COMMENT 'Hymn metre, e.g. 87.87 D | CM | LM | 86.86 (VARCHAR not ENUM)',
                MusicBrainzWorkMBID VARCHAR(50)  NULL DEFAULT NULL COMMENT 'MusicBrainz Work MBID — a tune is a composition (mirrors tblWorks)',
                HymnaryTuneId       VARCHAR(64)  NULL DEFAULT NULL COMMENT 'Hymnary.org tune identifier for enrichment cross-link',
                Notes               TEXT         NULL DEFAULT NULL,
                CreatedAt           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UpdatedAt           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_Name   (Name),
                UNIQUE KEY uq_Slug   (Slug),
                UNIQUE KEY uq_MbWork (MusicBrainzWorkMBID),
                INDEX      idx_Meter (MeterCode)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Hymn tunes as first-class entities (#1090 P4).'"
        );
        _migTunes_output("  [OK] Created tblTunes.");
    }

    _migTunes_output("--- tblTuneAliases ---");
    if (_migTunes_tableExists($mysql, 'tblTuneAliases')) {
        _migTunes_output("  [SKIP] tblTuneAliases already present.");
    } else {
        $mysql->query(
            "CREATE TABLE tblTuneAliases (
                Id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                TuneId    INT UNSIGNED NOT NULL,
                Name      VARCHAR(120) NOT NULL,
                CreatedAt TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_TuneName (TuneId, Name),
                INDEX      idx_Tune    (TuneId),
                INDEX      idx_Name    (Name),
                CONSTRAINT fk_TuneAlias_Tune
                    FOREIGN KEY (TuneId) REFERENCES tblTunes(Id) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        _migTunes_output("  [OK] Created tblTuneAliases.");
    }

    _migTunes_output("--- tblSongs.TuneId + FK ---");
    if (_migTunes_colExists($mysql, 'tblSongs', 'TuneId')) {
        _migTunes_output("  [SKIP] tblSongs.TuneId already present.");
    } else {
        $mysql->query("ALTER TABLE tblSongs ADD COLUMN TuneId INT UNSIGNED NULL DEFAULT NULL COMMENT 'FK to tblTunes.Id (#1090 P4)' AFTER TuneName");
        _migTunes_output("  [OK] Added tblSongs.TuneId.");
    }
    $idx = $mysql->query("SHOW INDEX FROM tblSongs WHERE Key_name = 'idx_TuneId'");
    if (!($idx && $idx->num_rows > 0)) {
        $mysql->query("ALTER TABLE tblSongs ADD INDEX idx_TuneId (TuneId)");
        _migTunes_output("  [OK] Added index idx_TuneId.");
    } else { _migTunes_output("  [SKIP] idx_TuneId present."); }
    if (!_migTunes_fkExists($mysql, 'fk_Songs_Tune')) {
        $mysql->query("ALTER TABLE tblSongs ADD CONSTRAINT fk_Songs_Tune FOREIGN KEY (TuneId) REFERENCES tblTunes(Id) ON DELETE SET NULL ON UPDATE CASCADE");
        _migTunes_output("  [OK] Added FK fk_Songs_Tune.");
    } else { _migTunes_output("  [SKIP] fk_Songs_Tune present."); }

    _migTunes_output("--- Backfill tblTunes from DISTINCT TuneName (re-runnable) ---");
    /* ── The one non-obvious data-shaping decision in this file ─────────────
       The whole promote-to-entity step turns on the table COLLATION, not on
       anything visible in this loop.

       tblTunes is utf8mb4_unicode_ci — case- AND accent-INSENSITIVE. So:
         · DISTINCT TuneName below is itself CI, and the UNIQUE KEY uq_Name is
           CI, which is what folds "HYFRYDOL" / "Hyfrydol" / "hyfrydol" —
           thirty hymnals' worth of inconsistent capitalisation — into ONE tune
           row. That de-duplication is the entire point of #1090 P4; a binary
           collation here would create three tunes and de-duplicate nothing.
         · The `JOIN tblTunes t ON s.TuneName = t.Name` further down matches
           under the same CI rules, so every capitalisation variant links to
           the single surviving row.
       Which variant WINS is whichever the DISTINCT scan reaches first — i.e.
       arbitrary. That is acceptable because Name is a display string a curator
       can correct once, in one place, which is the improvement over 30 rows of
       free text. https://dev.mysql.com/doc/refman/8.0/en/charset-collation-names.html

       On a RE-RUN the slug-collision loop below still runs for every existing
       tune and computes a throwaway '-2' slug; the INSERT IGNORE then discards
       the row on uq_Name before that slug is ever used. Wasted work, not a bug
       — no orphan slug is created, and no duplicate tune. */
    $sel = $mysql->query("SELECT DISTINCT TuneName FROM tblSongs WHERE TuneName IS NOT NULL AND TuneName <> ''");
    $insTune = $mysql->prepare("INSERT IGNORE INTO tblTunes (Name, Slug) VALUES (?, ?)");
    $slugTaken = $mysql->prepare("SELECT 1 FROM tblTunes WHERE Slug = ? LIMIT 1");
    $created = 0;
    while ($row = $sel->fetch_assoc()) {
        $name = (string)$row['TuneName'];
        $base = _migTunes_slugify($name);
        $slug = $base; $n = 1;
        while (true) {
            $slugTaken->bind_param('s', $slug); $slugTaken->execute();
            $taken = $slugTaken->get_result();
            if (!($taken && $taken->num_rows > 0)) break;
            $n++; $slug = substr($base, 0, 134) . '-' . $n;
        }
        $insTune->bind_param('ss', $name, $slug);
        $insTune->execute();
        if ($insTune->affected_rows > 0) $created++;
    }
    $insTune->close(); $slugTaken->close();
    /* `WHERE s.TuneId IS NULL` is what makes the link step re-runnable AND
       non-destructive in the same clause: it fills in songs that have no tune
       yet, and never overwrites a TuneId a curator has since re-pointed by hand
       to a different tune than its stale TuneName text implies. */
    $mysql->query("UPDATE tblSongs s JOIN tblTunes t ON s.TuneName = t.Name SET s.TuneId = t.Id WHERE s.TuneId IS NULL AND s.TuneName IS NOT NULL AND s.TuneName <> ''");
    $linked = $mysql->affected_rows;
    _migTunes_output("  [OK] Created {$created} tunes; linked {$linked} songs to a TuneId.");

    _migTunes_output("");
    _migTunes_output("Migration complete.");
} catch (\Throwable $e) {
    _migTunes_output("  [ERROR] " . $e->getMessage());
}
$mysql->close();
return;
