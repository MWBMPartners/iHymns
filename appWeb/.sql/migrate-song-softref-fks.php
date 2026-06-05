<?php

declare(strict_types=1);

/**
 * iHymns — Harden soft SongId references with real FK constraints (#1064)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Three columns referenced tblSongs.SongId WITHOUT a foreign key, so the DB
 * couldn't enforce integrity and the duplicate-songs merge had to repoint them
 * by hand (a #1064 review finding). Give them real FK constraints so the
 * database guarantees no dangling references and cascades correctly on delete:
 *
 *   tblSongRequests.ResolvedSongId               (nullable) → ON DELETE SET NULL
 *   tblSongRevisions.SongId                      (NOT NULL) → ON DELETE CASCADE
 *   tblSongLinkSuggestionsDismissed.SongIdA/B    (NOT NULL) → ON DELETE CASCADE
 *
 * A FK add FAILS if existing rows violate it, so each is preceded by a
 * data-cleaning pass (NULL the danglers where nullable; delete the orphan rows
 * where NOT NULL — those reference songs that no longer exist).
 *
 * IDEMPOTENT (each FK is added only if absent, by constraint name).
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-song-softref-fks.php
 *   Web:  /manage/setup-database → "Harden soft SongId references (FKs)" button
 *
 * @requires PHP 8.1+ with mysqli
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

function _migSoftFk_output(string $msg): void {
    global $isCli;
    echo $msg . ($isCli ? "\n" : "<br>\n");
    if (!$isCli) flush();
}

/** Add a FK constraint only if a constraint of that name doesn't already exist. */
function _migSoftFk_addFk(\mysqli $db, string $name, string $alterSql): void {
    $stmt = $db->prepare(
        "SELECT 1 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
          WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = ? LIMIT 1"
    );
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    if ($exists) {
        _migSoftFk_output("  [SKIP] constraint {$name} already present.");
        return;
    }
    $db->query($alterSql);
    _migSoftFk_output("  [OK] Added constraint {$name}.");
}

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) {
    _migSoftFk_output("ERROR: MySQL credentials not found. Run install.php first.");
    return;
}
require_once $credFile;

_migSoftFk_output("");
_migSoftFk_output("=== iHymns — Harden soft SongId references with FKs (#1064) ===");
_migSoftFk_output("");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    $mysql->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
} catch (\mysqli_sql_exception $e) {
    _migSoftFk_output("ERROR: MySQL connection failed: " . $e->getMessage());
    return;
}
_migSoftFk_output("Connected to MySQL: " . DB_NAME);

try {
    /* 1. tblSongRequests.ResolvedSongId — NULL the danglers, then SET NULL FK. */
    _migSoftFk_output("");
    _migSoftFk_output("--- tblSongRequests.ResolvedSongId ---");
    $n = $mysql->query(
        "UPDATE tblSongRequests t
            SET t.ResolvedSongId = NULL
          WHERE t.ResolvedSongId IS NOT NULL
            AND NOT EXISTS (SELECT 1 FROM tblSongs s WHERE s.SongId = t.ResolvedSongId)"
    );
    _migSoftFk_output("  [OK] Nulled " . $mysql->affected_rows . " dangling ResolvedSongId value(s).");
    _migSoftFk_addFk($mysql, 'fk_Requests_ResolvedSong',
        "ALTER TABLE tblSongRequests
            ADD CONSTRAINT fk_Requests_ResolvedSong
            FOREIGN KEY (ResolvedSongId) REFERENCES tblSongs(SongId)
            ON DELETE SET NULL ON UPDATE CASCADE");

    /* 2. tblSongRevisions.SongId — NOT NULL: delete orphan revisions, then CASCADE FK. */
    _migSoftFk_output("");
    _migSoftFk_output("--- tblSongRevisions.SongId ---");
    $mysql->query(
        "DELETE t FROM tblSongRevisions t
          WHERE NOT EXISTS (SELECT 1 FROM tblSongs s WHERE s.SongId = t.SongId)"
    );
    _migSoftFk_output("  [OK] Removed " . $mysql->affected_rows . " orphan revision(s).");
    _migSoftFk_addFk($mysql, 'fk_Revisions_Song',
        "ALTER TABLE tblSongRevisions
            ADD CONSTRAINT fk_Revisions_Song
            FOREIGN KEY (SongId) REFERENCES tblSongs(SongId)
            ON DELETE CASCADE ON UPDATE CASCADE");

    /* 3. tblSongLinkSuggestionsDismissed.SongIdA/B — NOT NULL: delete orphans, CASCADE FKs. */
    _migSoftFk_output("");
    _migSoftFk_output("--- tblSongLinkSuggestionsDismissed.SongIdA / SongIdB ---");
    $mysql->query(
        "DELETE t FROM tblSongLinkSuggestionsDismissed t
          WHERE NOT EXISTS (SELECT 1 FROM tblSongs s WHERE s.SongId = t.SongIdA)
             OR NOT EXISTS (SELECT 1 FROM tblSongs s WHERE s.SongId = t.SongIdB)"
    );
    _migSoftFk_output("  [OK] Removed " . $mysql->affected_rows . " orphan dismissed-suggestion(s).");
    _migSoftFk_addFk($mysql, 'fk_DismissedSugg_A',
        "ALTER TABLE tblSongLinkSuggestionsDismissed
            ADD CONSTRAINT fk_DismissedSugg_A
            FOREIGN KEY (SongIdA) REFERENCES tblSongs(SongId)
            ON DELETE CASCADE ON UPDATE CASCADE");
    _migSoftFk_addFk($mysql, 'fk_DismissedSugg_B',
        "ALTER TABLE tblSongLinkSuggestionsDismissed
            ADD CONSTRAINT fk_DismissedSugg_B
            FOREIGN KEY (SongIdB) REFERENCES tblSongs(SongId)
            ON DELETE CASCADE ON UPDATE CASCADE");

    _migSoftFk_output("");
    _migSoftFk_output("--- Summary ---");
    _migSoftFk_output("  All SongId references are now FK-enforced. The DB guarantees no");
    _migSoftFk_output("  dangling references; the duplicate-songs merge still repoints them");
    _migSoftFk_output("  to the survivor first (preserving data) before the delete cascades.");
    _migSoftFk_output("");
    _migSoftFk_output("Migration complete.");
} catch (\Throwable $e) {
    _migSoftFk_output("  [ERROR] " . $e->getMessage());
}

$mysql->close();
return;
