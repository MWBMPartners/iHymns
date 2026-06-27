<?php

declare(strict_types=1);

/**
 * iHymns — Live-link a shared setlist to the owner's server-stored setlist (#1380)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Setlist sharing has always been SNAPSHOT-based: the share API froze a copy of
 * the songs into tblSharedSetlists.Data at share time, so the owner's later edits
 * to their LIVE setlist (tblUserSetlists) never reached link-holders. This migration
 * adds the two columns that let a share LINK to the owner's live row instead of
 * freezing a copy:
 *
 *   tblSharedSetlists.OwnerUserId     — FK -> tblUsers.Id of the authenticated owner.
 *   tblSharedSetlists.SourceSetlistId — the owner's tblUserSetlists.SetlistId.
 *
 * (OwnerUserId, SourceSetlistId) resolves the CURRENT setlist at read time. When
 * either is NULL the share stays snapshot-only (legacy / anonymous create), so the
 * change is fully backward-compatible: existing share rows keep working unchanged.
 *
 * Additive + IDEMPOTENT — each ADD is column-existence-gated and the FK add is
 * INFORMATION_SCHEMA.TABLE_CONSTRAINTS-gated (mysqli runs under
 * MYSQLI_REPORT_ERROR|STRICT, so an unguarded duplicate-ADD would THROW). The read
 * path (SharedSetlist.php) is column-existence-gated too, so the app works whether
 * or not this has run — an un-migrated install simply never live-links.
 *
 * @migration-adds tblSharedSetlists.OwnerUserId
 * @migration-adds tblSharedSetlists.SourceSetlistId
 *
 * USAGE:
 *   Web:  /manage/setup-database → "Live-link shared setlists (#1380)" button
 *   CLI:  php appWeb/.sql/migrate-shared-setlist-live-link.php
 *
 * @requires PHP 8.1+ with mysqli
 */

/* ELI5: are we on the command line, or clicked in the web dashboard?
   WHY: a web run needs plain-text headers; the dashboard sets a sentinel constant so
   the migration runner captures our echo output instead of leaking raw HTTP headers
   (mirrors every other migrate-*.php). */
$isCli = (PHP_SAPI === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

/* ELI5: print one progress line, CLI- or HTML-formatted depending on where we run.
   WHY: a single helper keeps the migration legible in the terminal AND in the
   dashboard's live <pre> capture. */
function _migSSLL_out(string $m): void { global $isCli; echo $m . ($isCli ? "\n" : "<br>\n"); if (!$isCli) { @flush(); } }

/* ELI5: does this column already exist on this table?
   WHY: idempotency — re-running the migration is a safe no-op. Bound param (rule #5),
   never string-interpolation.
   https://dev.mysql.com/doc/refman/8.0/en/information-schema-columns-table.html */
function _migSSLL_columnExists(\mysqli $db, string $t, string $c): bool
{
    $stmt = $db->prepare(
        "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1"
    );
    $stmt->bind_param('ss', $t, $c);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $exists;
}

/* ELI5: does a named FK / constraint already exist on this table?
   WHY: the FK ADD is not column-gated — guard it against its own constraint name so a
   re-run never trips ER_DUP_KEYNAME under STRICT.
   https://dev.mysql.com/doc/refman/8.0/en/information-schema-table-constraints-table.html */
function _migSSLL_constraintExists(\mysqli $db, string $t, string $name): bool
{
    $stmt = $db->prepare(
        "SELECT 1 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
          WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? LIMIT 1"
    );
    $stmt->bind_param('ss', $t, $name);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $exists;
}

/* ELI5: load the MySQL login details written by install.php.
   WHY: the migration runs standalone (CLI or web) so it needs its own connection; it
   cannot assume the app bootstrap has run. Bail clearly if the install step is missing. */
$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) { _migSSLL_out("ERROR: MySQL credentials not found. Run install.php first."); return; }
require_once $credFile;

_migSSLL_out("");
_migSSLL_out("=== iHymns — Live-link shared setlists (tblSharedSetlists, #1380) ===");

/* ELI5: open the database connection.
   WHY: mysqli runs under MYSQLI_REPORT_ERROR|STRICT app-wide, so a bad statement THROWS;
   catch the connect failure to print a friendly line instead of a stack trace. */
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $mysql->set_charset('utf8mb4');
} catch (\mysqli_sql_exception $e) { _migSSLL_out("ERROR: MySQL connection failed: " . $e->getMessage()); return; }
_migSSLL_out("Connected to MySQL: " . DB_NAME);

try {
    /* ELI5: add the owner-user link column only if missing.
       WHY: idempotency — a guarded ADD COLUMN keeps re-runs a clean no-op rather than a
       1060 error. Byte-identical to the schema.sql mirror so a fresh install equals a
       migrated one. NULL = legacy/anonymous snapshot-only share. */
    if (_migSSLL_columnExists($mysql, 'tblSharedSetlists', 'OwnerUserId')) {
        _migSSLL_out("  [SKIP] tblSharedSetlists.OwnerUserId already present.");
    } else {
        $mysql->query(
            "ALTER TABLE tblSharedSetlists
                ADD COLUMN OwnerUserId INT UNSIGNED NULL DEFAULT NULL
                COMMENT 'Live-share link: FK to tblUsers.Id of the authenticated owner. NULL = legacy/anonymous snapshot-only share (#1380).'
                AFTER Data"
        );
        _migSSLL_out("  [OK] Added tblSharedSetlists.OwnerUserId.");
    }

    /* ELI5: add the source-setlist-id column only if missing.
       WHY: (OwnerUserId, SourceSetlistId) is what resolves the owner's CURRENT setlist at
       read time. VARCHAR(100) matches tblUserSetlists.SetlistId. NULL = snapshot-only. */
    if (_migSSLL_columnExists($mysql, 'tblSharedSetlists', 'SourceSetlistId')) {
        _migSSLL_out("  [SKIP] tblSharedSetlists.SourceSetlistId already present.");
    } else {
        $mysql->query(
            "ALTER TABLE tblSharedSetlists
                ADD COLUMN SourceSetlistId VARCHAR(100) NULL DEFAULT NULL
                COMMENT 'Live-share link: the owner''s tblUserSetlists.SetlistId. (OwnerUserId, SourceSetlistId) resolves the CURRENT setlist at read time. NULL = snapshot-only (#1380).'
                AFTER OwnerUserId"
        );
        _migSSLL_out("  [OK] Added tblSharedSetlists.SourceSetlistId.");
    }

    /* ELI5: add the (OwnerUserId, SourceSetlistId) lookup index only if missing.
       WHY: setlist_get resolves the live row by this exact pair; the index keeps that
       read cheap. Guarded by its key name so a re-run is a no-op under STRICT. */
    if (_migSSLL_constraintExists($mysql, 'tblSharedSetlists', 'idx_LiveSource')) {
        _migSSLL_out("  [SKIP] index idx_LiveSource already present.");
    } else {
        /* A KEY is not in TABLE_CONSTRAINTS — probe STATISTICS for the index name. */
        $idxStmt = $mysql->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblSharedSetlists'
                AND INDEX_NAME = 'idx_LiveSource' LIMIT 1"
        );
        $idxStmt->execute();
        $idxExists = $idxStmt->get_result()->fetch_row() !== null;
        $idxStmt->close();
        if ($idxExists) {
            _migSSLL_out("  [SKIP] index idx_LiveSource already present.");
        } else {
            $mysql->query("ALTER TABLE tblSharedSetlists ADD KEY idx_LiveSource (OwnerUserId, SourceSetlistId)");
            _migSSLL_out("  [OK] Added index idx_LiveSource.");
        }
    }

    /* ELI5: add the FK to tblUsers only if the named constraint is missing.
       WHY: ON DELETE SET NULL means deleting a user demotes their shares to snapshot-only
       rather than vanishing them; ON UPDATE CASCADE keeps the link valid if a user id ever
       moves. Guarded by constraint name so a re-run never trips ER_FK_DUP_NAME. */
    if (_migSSLL_constraintExists($mysql, 'tblSharedSetlists', 'fk_SharedSetlists_OwnerUser')) {
        _migSSLL_out("  [SKIP] FK fk_SharedSetlists_OwnerUser already present.");
    } else {
        $mysql->query(
            "ALTER TABLE tblSharedSetlists
                ADD CONSTRAINT fk_SharedSetlists_OwnerUser
                    FOREIGN KEY (OwnerUserId) REFERENCES tblUsers(Id)
                    ON DELETE SET NULL ON UPDATE CASCADE"
        );
        _migSSLL_out("  [OK] Added FK fk_SharedSetlists_OwnerUser.");
    }

    _migSSLL_out("Migration complete.");
} catch (\mysqli_sql_exception $e) {
    _migSSLL_out("ERROR: " . $e->getMessage());
}
