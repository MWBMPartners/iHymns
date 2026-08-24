<?php

declare(strict_types=1);

/**
 * iHymns — Copyright Holder registry link schema batch (#1864, epic #1863)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * Adds a "which publisher registry row is this Work's Copyright Holder"
 * link column, so the Copyright Holder picker (#1864) can point at a real
 * `tblPublishers` row instead of only ever storing a free-text name. The
 * same column is planted on `tblSongs` too, dormant, so the Editor2 song-
 * level Copyright Holder field (#1862, same epic) doesn't need a SECOND
 * migration for the same family later (rule #20).
 *
 * DETAILED — WHY THIS SHAPE
 * ----------------------------------------------------------------------------
 * `tblWorks.CopyrightHolderId` / `tblSongs.CopyrightHolderId` — INT UNSIGNED
 * NULL, mirroring the tblWorks.TuneId / tblSongs.TuneId pair exactly
 * (denorm display string kept as-is + FK to the registry entity). The
 * existing free-text `CopyrightHolder` VARCHAR(255) column on both tables
 * is UNTOUCHED and stays the JOIN-free display mirror — this is additive,
 * never a storage merge (rule #37).
 *
 * `tblWorks.CopyrightHolderId` is READ/WRITTEN starting with this same
 * #1864 commit (works.php's $persistWorkExtraFields lockstep write,
 * publisherResolvePickedOrCreate()). `tblSongs.CopyrightHolderId` is
 * planted DORMANT — nothing reads or writes it yet; it waits for #1862.
 *
 * FKs -> tblPublishers(Id), ON DELETE SET NULL ON UPDATE CASCADE — deleting
 * a publisher must never delete/clobber a Work or Song, exactly the
 * fk_Works_Tune / fk_Works_OriginCity precedent. Each FK is skipped (with a
 * clear [SKIP] note, not a fatal error) when tblPublishers does not yet
 * exist on this install — this migration's own registry entry orders it
 * after 'publishers-entity' so "Apply all pending" resolves the ordering,
 * but a curator can still click cards individually or run this script
 * standalone from the CLI before publishers-entity has landed; the two
 * columns + their indexes still apply either way, same as
 * migrate-works-identity.php's fk_Works_Tune tolerance.
 *
 * NO BACKFILL (owner decision D2, #1864 spec §2): existing free-text
 * CopyrightHolder strings are NOT swept into tblPublishers by this script.
 * Copyright-holder strings are messier than the songbook-Publisher backfill
 * corpus ("Public Domain", "© 1978 Hope Publishing Co.", …) — a blind sweep
 * would mint junk registry rows. Existing rows resolve lazily the next time
 * a curator saves them through the picker.
 *
 * STRICTLY ADDITIVE + IDEMPOTENT. Every ADD COLUMN / ADD INDEX / ADD
 * CONSTRAINT is existence-guarded. Dormant on tblSongs; live on tblWorks.
 *
 * @migration-adds tblWorks.CopyrightHolderId
 * @migration-adds tblSongs.CopyrightHolderId
 *
 * SCHEMA MIRROR: both columns + indexes are mirrored byte-identical in
 * appWeb/.sql/schema.sql (inline in the tblWorks / tblSongs CREATE TABLE
 * blocks); both FKs are mirrored as trailing ALTERs placed after
 * tblPublishers' CREATE TABLE block — rule #19.
 *
 * NO literal `/public_html/` require at column 0 (rule #41) — this script
 * needs no shared includes at all, only the DB credentials file.
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-copyright-holder-registry.php
 *   Web:  /manage/setup-database -> "Copyright Holder registry (#1864)" button
 *
 * @requires PHP 8.1+ with mysqli
 * @see appWeb/public_html/includes/publisher_helpers.php  publisherResolvePickedOrCreate()
 * @see appWeb/.sql/migrate-works-identity.php              the fk_Works_Tune precedent this mirrors
 * @see /tmp/.../pickers-1864-spec.md §1.5                  the spec this implements
 * @see #1864, epic #1863
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

function _migCopyHolder_output(string $m): void { global $isCli; echo $m . ($isCli ? "\n" : "<br>\n"); if (!$isCli) flush(); }
function _migCopyHolder_tableExists(\mysqli $db, string $t): bool { $r = $db->query("SHOW TABLES LIKE '{$t}'"); return (bool)($r && $r->num_rows > 0); }
function _migCopyHolder_colExists(\mysqli $db, string $t, string $c): bool { $r = $db->query("SHOW COLUMNS FROM {$t} LIKE '{$c}'"); return (bool)($r && $r->num_rows > 0); }
function _migCopyHolder_idxExists(\mysqli $db, string $t, string $idx): bool { $r = $db->query("SHOW INDEX FROM {$t} WHERE Key_name = '" . $db->real_escape_string($idx) . "'"); return (bool)($r && $r->num_rows > 0); }
function _migCopyHolder_fkExists(\mysqli $db, string $name): bool {
    $r = $db->query(
        "SELECT 1 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
          WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = '" . $db->real_escape_string($name) . "' LIMIT 1"
    );
    return (bool)($r && $r->num_rows > 0);
}

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) { _migCopyHolder_output("ERROR: MySQL credentials not found. Run install.php first."); return; }
require_once $credFile;

_migCopyHolder_output("");
_migCopyHolder_output("=== iHymns — Copyright Holder registry link schema batch (#1864) ===");
_migCopyHolder_output("");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    $mysql->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
} catch (\mysqli_sql_exception $e) {
    _migCopyHolder_output("ERROR: MySQL connection failed: " . $e->getMessage()); return;
}
_migCopyHolder_output("Connected to MySQL: " . DB_NAME);

try {
    /* ---- tblWorks.CopyrightHolderId ---- */
    _migCopyHolder_output("--- tblWorks.CopyrightHolderId ---");
    if (_migCopyHolder_colExists($mysql, 'tblWorks', 'CopyrightHolderId')) {
        _migCopyHolder_output("  [SKIP] tblWorks.CopyrightHolderId already present.");
    } else {
        $mysql->query(
            "ALTER TABLE tblWorks
                ADD COLUMN CopyrightHolderId INT UNSIGNED NULL DEFAULT NULL
                    COMMENT 'FK to tblPublishers.Id (#1864); mirrors TuneId. CopyrightHolder VARCHAR stays the JOIN-free denorm display mirror, written in lockstep by publisherResolvePickedOrCreate(). FK added via trailing ALTER — tblPublishers may not exist yet when this card runs'
                AFTER CopyrightHolder"
        );
        _migCopyHolder_output("  [OK] Added tblWorks.CopyrightHolderId.");
    }
    if (_migCopyHolder_idxExists($mysql, 'tblWorks', 'idx_CopyrightHolderId')) {
        _migCopyHolder_output("  [SKIP] tblWorks.idx_CopyrightHolderId present.");
    } else {
        $mysql->query("ALTER TABLE tblWorks ADD INDEX idx_CopyrightHolderId (CopyrightHolderId)");
        _migCopyHolder_output("  [OK] Added index tblWorks.idx_CopyrightHolderId.");
    }

    /* ---- tblSongs.CopyrightHolderId (dormant — #1862 will consume it) ---- */
    _migCopyHolder_output("--- tblSongs.CopyrightHolderId (dormant, #1862) ---");
    if (_migCopyHolder_colExists($mysql, 'tblSongs', 'CopyrightHolderId')) {
        _migCopyHolder_output("  [SKIP] tblSongs.CopyrightHolderId already present.");
    } else {
        $mysql->query(
            "ALTER TABLE tblSongs
                ADD COLUMN CopyrightHolderId INT UNSIGNED NULL DEFAULT NULL
                    COMMENT 'FK to tblPublishers.Id (#1864 one-pass rider for the #1862 family, rule #20); DORMANT — no reader/writer yet. CopyrightHolder VARCHAR stays the JOIN-free denorm display mirror. FK added via trailing ALTER — tblPublishers may not exist yet when this card runs'
                AFTER CopyrightHolder"
        );
        _migCopyHolder_output("  [OK] Added tblSongs.CopyrightHolderId.");
    }
    if (_migCopyHolder_idxExists($mysql, 'tblSongs', 'idx_CopyrightHolderId')) {
        _migCopyHolder_output("  [SKIP] tblSongs.idx_CopyrightHolderId present.");
    } else {
        $mysql->query("ALTER TABLE tblSongs ADD INDEX idx_CopyrightHolderId (CopyrightHolderId)");
        _migCopyHolder_output("  [OK] Added index tblSongs.idx_CopyrightHolderId.");
    }

    /* ---- Trailing FKs: only if tblPublishers exists ----
       Skip-and-warn rather than fatally erroring, same tolerance as
       migrate-works-identity.php's fk_Works_Tune: the publishers-entity
       card is this one's dependency and sits earlier in the migration
       registry, but an operator can still click cards out of order (or run
       this script standalone from the CLI). Everything else in this
       migration is unaffected by tblPublishers' absence — only the FK
       constraints need it. */
    _migCopyHolder_output("--- fk_Works_CopyrightHolder / fk_Songs_CopyrightHolder ---");
    if (!_migCopyHolder_tableExists($mysql, 'tblPublishers')) {
        _migCopyHolder_output("  [SKIP][WARN] tblPublishers does not exist yet — run migrate-publishers-entity.php");
        _migCopyHolder_output("               ('Publishers registry' card) first, then re-run this migration to");
        _migCopyHolder_output("               add both FKs. Both columns were still added above.");
    } else {
        if (_migCopyHolder_fkExists($mysql, 'fk_Works_CopyrightHolder')) {
            _migCopyHolder_output("  [SKIP] fk_Works_CopyrightHolder present.");
        } else {
            $mysql->query(
                "ALTER TABLE tblWorks
                    ADD CONSTRAINT fk_Works_CopyrightHolder
                        FOREIGN KEY (CopyrightHolderId) REFERENCES tblPublishers(Id) ON DELETE SET NULL ON UPDATE CASCADE"
            );
            _migCopyHolder_output("  [OK] Added FK fk_Works_CopyrightHolder.");
        }
        if (_migCopyHolder_fkExists($mysql, 'fk_Songs_CopyrightHolder')) {
            _migCopyHolder_output("  [SKIP] fk_Songs_CopyrightHolder present.");
        } else {
            $mysql->query(
                "ALTER TABLE tblSongs
                    ADD CONSTRAINT fk_Songs_CopyrightHolder
                        FOREIGN KEY (CopyrightHolderId) REFERENCES tblPublishers(Id) ON DELETE SET NULL ON UPDATE CASCADE"
            );
            _migCopyHolder_output("  [OK] Added FK fk_Songs_CopyrightHolder.");
        }
    }

    _migCopyHolder_output("");
    _migCopyHolder_output("Migration complete.");
} catch (\Throwable $e) {
    _migCopyHolder_output("  [ERROR] " . $e->getMessage());
}
$mysql->close();
return;
