<?php

declare(strict_types=1);

/**
 * iHymns — Musician duplicate-review dismissals (#1785 C1)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * ELI5: when a curator looks at two registry rows the duplicate-finder
 * flagged as "maybe the same person" and says "no, these really are two
 * different people", this table remembers that decision so the pair
 * doesn't keep popping back up on every visit to the review page.
 *
 * DETAILED: tblMusicianDuplicatesDismissed is a single, self-contained
 * table — mirroring tblSongLinkSuggestionsDismissed (schema.sql, the
 * song-side precedent, #808) byte-for-byte in shape: one row per
 * dismissed PAIR of tblMusicians rows, normalised so MusicianIdA is
 * always the numerically smaller id (the app layer enforces the
 * ordering on write; the UNIQUE key just needs ONE canonical
 * representation per pair so the same pair can't be dismissed twice
 * under swapped columns). Both FKs point at tblMusicians(Id) ON DELETE
 * CASCADE, so merging or deleting either side of a dismissed pair
 * auto-cleans the dismissal row — no orphaned "dismissed" rows to sweep
 * separately, and a merge's registry-row DELETE (musicianMergeExecute(),
 * includes/musician_helpers.php) already does this for free.
 *
 * Deliberately NOT storing the score/confidence the curator saw at
 * dismissal time (#1785 plan §3.5) — the registry-duplicate scan is
 * LIVE-computed per page load (no precompute table, §3.2-§3.4 of the
 * plan), so a stored score could only go stale relative to a re-scored
 * pair after further registry edits. `Reason` is free text so a curator
 * can leave a note ("confirmed via CCLI SongSelect — different people")
 * without needing a schema change later.
 *
 * This table is a PURE NO-OP on its own: nothing in #1785 C1-C5 reads or
 * writes it yet. The review page that consumes it (`/manage/musician-
 * duplicates.php`, plan §7) is a later commit (C7) in the same epic —
 * shipping the table now, ahead of its reader, follows the one-pass
 * forward-looking-schema convention (rule #20): design the final DDL up
 * front, ship it additive + idempotent + dormant, avoid a second
 * migration later purely to widen this family.
 *
 * Additive + IDEMPOTENT (table-existence guarded, CREATE TABLE IF NOT
 * EXISTS). Safe to re-run.
 *
 * @migration-adds tblMusicianDuplicatesDismissed
 *
 * SCHEMA MIRROR: the CREATE TABLE below is mirrored byte-identical
 * (incl. every COMMENT) in appWeb/.sql/schema.sql — rule #19.
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-musician-duplicates-dismissed.php
 *   Web:  /manage/setup-database → "Musicians: duplicate-review dismissals (#1785)"
 *
 * @requires PHP 8.1+ with mysqli
 * @see .claude/musicians-dedup-1785-plan.md §3.5
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}
function _migMusDupDism_out(string $m): void { global $isCli; echo $m . ($isCli ? "\n" : "<br>\n"); if (!$isCli) flush(); }
function _migMusDupDism_tableExists(\mysqli $db, string $t): bool
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
if (!file_exists($credFile)) { _migMusDupDism_out("ERROR: MySQL credentials not found. Run install.php first."); return; }
require_once $credFile;

_migMusDupDism_out("");
_migMusDupDism_out("=== iHymns — Musician duplicate-review dismissals (#1785 C1) ===");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)(defined('DB_PORT') ? DB_PORT : 3306));
    $mysql->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
} catch (\mysqli_sql_exception $e) { _migMusDupDism_out("ERROR: MySQL connection failed: " . $e->getMessage()); return; }
_migMusDupDism_out("Connected to MySQL: " . DB_NAME);

try {
    if (!_migMusDupDism_tableExists($mysql, 'tblMusicians')) {
        _migMusDupDism_out("  [SKIP] tblMusicians not present — run the musicians-rename migration family first.");
    } elseif (_migMusDupDism_tableExists($mysql, 'tblMusicianDuplicatesDismissed')) {
        _migMusDupDism_out("  [SKIP] tblMusicianDuplicatesDismissed already present.");
    } else {
        $mysql->query(
            "CREATE TABLE IF NOT EXISTS tblMusicianDuplicatesDismissed (
                Id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                MusicianIdA  INT UNSIGNED NOT NULL COMMENT 'Always numerically < MusicianIdB (#1785)',
                MusicianIdB  INT UNSIGNED NOT NULL,
                DismissedBy  INT UNSIGNED NULL DEFAULT NULL COMMENT 'tblUsers.Id; NULL = user since deleted',
                DismissedAt  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                Reason       VARCHAR(255) NOT NULL DEFAULT '',
                UNIQUE KEY uk_pair (MusicianIdA, MusicianIdB),
                KEY idx_B (MusicianIdB),
                CONSTRAINT fk_MusDupDism_A FOREIGN KEY (MusicianIdA) REFERENCES tblMusicians(Id)
                    ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT fk_MusDupDism_B FOREIGN KEY (MusicianIdB) REFERENCES tblMusicians(Id)
                    ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        _migMusDupDism_out("  [OK] Created tblMusicianDuplicatesDismissed.");
    }

    _migMusDupDism_out("Migration complete.");
} catch (\mysqli_sql_exception $e) {
    _migMusDupDism_out("ERROR: " . $e->getMessage());
}
