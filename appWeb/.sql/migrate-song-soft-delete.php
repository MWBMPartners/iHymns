<?php

declare(strict_types=1);

/**
 * iHymns — Song soft delete (#1694, stage 2 of epic #1692)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Adds the five soft-delete columns + one index + one FK to tblSongs, then
 * redefines the SongCount triggers (#793) so the cached per-songbook count
 * means VISIBLE songs (owner decision D1a). A soft-deleted song's row is NEVER
 * hard-deleted: 38 of the 41 FKs referencing tblSongs(SongId) are ON DELETE
 * CASCADE, so the moment the row goes, components, lyric lines, credits,
 * media links, tags, external links, work membership and the entire revision
 * history go with it — restore must be PREVENTION, not repair. Every column
 * here is additive and DORMANT until the read-path sweep + write cores land
 * (#1694 commits 2-5); applying this card changes the behaviour of zero
 * existing reads.
 *
 * One-pass forward-looking DDL (rule #20): the adversarial pass in
 * .claude/plan-song-soft-delete-2026-07-31.md §1 stress-tested this shape
 * against the #1695 review-queue follow-on (WHERE IsDeleted = 1 IS the queue;
 * the four companion columns cover who/when/why), a future "proposed for
 * deletion" workflow (its own queue table, not a third flag value — visibility
 * is genuinely binary), scheduled auto-purge (computable from DeletedAt), and
 * account erasure (#1698 maps the SET NULL FK to 'keep'). DeletedReason is
 * VARCHAR + an app map, never ENUM (rule #20).
 *
 * Seven IDEMPOTENT guarded stages (the migrate-song-public-id.php pattern —
 * probe before each ALTER), then the trigger redefinition:
 *   1-5. ADD COLUMN IsDeleted / DeletedAt / DeletedBy / DeletedReason /
 *        DeleteNote — each individually guarded (instant metadata ops).
 *   6.   ADD INDEX idx_IsDeleted (IsDeleted, DeletedAt) — the deleted-songs
 *        admin list orders by DeletedAt within the flag.
 *   7.   ADD CONSTRAINT fk_Songs_DeletedBy → tblUsers(Id) ON DELETE SET NULL
 *        (attribution survives an account erasure as the tombstone, #1698).
 *   8.   DROP/CREATE the three SongCount triggers via the SHARED lib
 *        (lib/songcount-triggers.php — the one copy of the DDL, also used by
 *        migrate-songcount-triggers.php) + a filtered recompute. Tolerant of
 *        trigger-denied hosts exactly as #793 was; the recompute carries the
 *        same IsDeleted = 0 predicate so denied hosts still get D1a-correct
 *        numbers. Triggers are deliberately NOT part of this card's pending
 *        probe — they are host-optional, and a probe they can never satisfy
 *        would pin the card pending forever on trigger-denied hosts (#793
 *        solved the same problem with a sentinel).
 * Re-running is a no-op once every object exists.
 *
 * @migration-adds tblSongs.IsDeleted
 * @migration-adds tblSongs.DeletedAt
 * @migration-adds tblSongs.DeletedBy
 * @migration-adds tblSongs.DeletedReason
 * @migration-adds tblSongs.DeleteNote
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-song-soft-delete.php
 *   Web:  /manage/setup-database → "Song soft delete (#1694)" button
 *
 * @requires PHP 8.1+ with mysqli
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}
function _migSSD_output(string $m): void { global $isCli; echo $m . ($isCli ? "\n" : "<br>\n"); if (!$isCli) flush(); }
function _migSSD_columnExists(\mysqli $db, string $t, string $c): bool
{
    $st = $db->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
    $st->bind_param('ss', $t, $c); $st->execute();
    $ok = $st->get_result()->fetch_row() !== null; $st->close(); return $ok;
}
function _migSSD_indexExists(\mysqli $db, string $t, string $idx): bool
{
    $st = $db->prepare("SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1");
    $st->bind_param('ss', $t, $idx); $st->execute();
    $ok = $st->get_result()->fetch_row() !== null; $st->close(); return $ok;
}
function _migSSD_constraintExists(\mysqli $db, string $t, string $c): bool
{
    $st = $db->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? LIMIT 1");
    $st->bind_param('ss', $t, $c); $st->execute();
    $ok = $st->get_result()->fetch_row() !== null; $st->close(); return $ok;
}

/* The ONE copy of the SongCount trigger DDL + recompute, shared with
   migrate-songcount-triggers.php (#793). require_once so a bulk "Apply all"
   that requires both cards in one request defines the lib functions once. */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'songcount-triggers.php';

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) { _migSSD_output("ERROR: MySQL credentials not found. Run install.php first."); return; }
require_once $credFile;

_migSSD_output("");
_migSSD_output("=== iHymns — Song soft delete (#1694) ===");

try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $mysql->set_charset('utf8mb4');
} catch (\mysqli_sql_exception $e) { _migSSD_output("ERROR: MySQL connection failed: " . $e->getMessage()); return; }
_migSSD_output("Connected to MySQL: " . DB_NAME);

try {
    /* Stages 1-5 — the five columns, each individually guarded so a partial
       prior run (connection dropped mid-way) resumes cleanly. The declarations
       are byte-identical to their schema.sql mirrors (rule #19) so a fresh
       install equals a migrated one. */
    $columns = [
        /* column => [DDL, human note] — hardcoded PHP constants (rule #5a). */
        'IsDeleted' => "ALTER TABLE tblSongs
                ADD COLUMN IsDeleted TINYINT(1) NOT NULL DEFAULT 0
                COMMENT 'Soft-delete flag (#1694): 1 = hidden from every filtered read, restorable via /manage/deleted-songs. The row is never hard-deleted on this path (38 of 41 inbound FKs CASCADE — restore must be prevention, not repair); purge is the separate, gated hard delete'
                AFTER UpdatedAt",
        'DeletedAt' => "ALTER TABLE tblSongs
                ADD COLUMN DeletedAt DATETIME NULL DEFAULT NULL
                COMMENT 'UTC instant of the soft delete (#1694); DATETIME not TIMESTAMP so it is never re-read against a session zone (rule #20). NULL while the song is live'
                AFTER IsDeleted",
        'DeletedBy' => "ALTER TABLE tblSongs
                ADD COLUMN DeletedBy INT UNSIGNED NULL DEFAULT NULL
                COMMENT 'FK to tblUsers.Id — who soft-deleted it (#1694). ON DELETE SET NULL so attribution resolves to the account tombstone if the deleter is later erased (#1698)'
                AFTER DeletedAt",
        'DeletedReason' => "ALTER TABLE tblSongs
                ADD COLUMN DeletedReason VARCHAR(50) NULL DEFAULT NULL
                COMMENT 'Why it was deleted (#1694) — app-validated vocabulary from songDeleteReasons() in includes/song_soft_delete.php; VARCHAR not ENUM so the vocabulary grows without an ALTER (rule #20)'
                AFTER DeletedBy",
        'DeleteNote' => "ALTER TABLE tblSongs
                ADD COLUMN DeleteNote VARCHAR(255) NOT NULL DEFAULT ''
                COMMENT 'Free-text curator note accompanying the delete (#1694). Empty string when none — NOT NULL so reads never juggle NULL vs empty'
                AFTER DeletedReason",
    ];
    foreach ($columns as $col => $ddl) {
        if (_migSSD_columnExists($mysql, 'tblSongs', $col)) {
            _migSSD_output("  [SKIP] tblSongs.$col already present.");
        } else {
            $mysql->query($ddl);
            _migSSD_output("  [OK] Added tblSongs.$col.");
        }
    }

    /* Stage 6 — the composite index. IsDeleted leads so the visibility filter
       can use it; DeletedAt second so the deleted-songs admin list gets its
       newest-first order from the same index. Deliberately NON-unique. */
    if (_migSSD_indexExists($mysql, 'tblSongs', 'idx_IsDeleted')) {
        _migSSD_output("  [SKIP] idx_IsDeleted index already present.");
    } else {
        $mysql->query("ALTER TABLE tblSongs ADD INDEX idx_IsDeleted (IsDeleted, DeletedAt)");
        _migSSD_output("  [OK] Added index idx_IsDeleted.");
    }

    /* Stage 7 — the attribution FK. SET NULL, never CASCADE: erasing the
       deleting curator's account must not resurrect or purge the song, only
       anonymise the attribution (#1698's accountEraseActionFor() maps a
       SET NULL FK outside the behavioural set to 'keep'). */
    if (_migSSD_constraintExists($mysql, 'tblSongs', 'fk_Songs_DeletedBy')) {
        _migSSD_output("  [SKIP] fk_Songs_DeletedBy constraint already present.");
    } else {
        $mysql->query(
            "ALTER TABLE tblSongs
                ADD CONSTRAINT fk_Songs_DeletedBy
                    FOREIGN KEY (DeletedBy) REFERENCES tblUsers(Id) ON DELETE SET NULL ON UPDATE CASCADE"
        );
        _migSSD_output("  [OK] Added constraint fk_Songs_DeletedBy.");
    }

    /* Stage 8 — redefine the SongCount triggers so the cached count means
       VISIBLE songs (D1a), via the ONE shared lib. The lib re-probes
       tblSongs.IsDeleted (added above in this same request — the probe is
       deliberately un-memoised) and installs the filtered flavour; a
       trigger-denied host gets the friendly skip + the filtered recompute,
       exactly the #793/#815 behaviour. Immediately after this card the
       recompute is a provable no-op (every row is IsDeleted = 0), so applying
       it changes zero displayed numbers today. */
    _migSSD_output("");
    _migSSD_output("Redefining SongCount triggers for soft delete (D1a)…");
    $install = ihymnsSongCountTriggersInstall($mysql, '_migSSD_output');
    if ($install['denied']) {
        _migSSD_output("  [INFO] Triggers denied on this host — the filtered recompute below (and the app-side recompute, PR #792) keep the counts honest.");
    }
    ihymnsSongCountRecompute($mysql, '_migSSD_output');

    _migSSD_output("Migration complete.");
} catch (\mysqli_sql_exception $e) {
    _migSSD_output("ERROR: " . $e->getMessage());
}
