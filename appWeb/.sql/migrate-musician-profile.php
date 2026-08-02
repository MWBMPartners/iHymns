<?php

declare(strict_types=1);

/**
 * iHymns — Musician profile schema batch (epic #1741 P1)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * One-pass forward-looking schema (rule #20) for the MusicBrainz-shaped
 * "Musicians" profile work — landing AHEAD of the P2 table/route/log-key
 * rename and the P4a profile page, per .claude/catalogue-expansion-1741-plan.md
 * §2.1. Entirely additive/idempotent/dormant: nothing in the app reads or
 * writes any of these new columns yet, so applying this card changes zero
 * observable behaviour.
 *
 *   - tblCreditPeople.Type VARCHAR(20) DEFAULT 'person' — an entity-type
 *     vocabulary (person|group|character|orchestra|other) wider than the two
 *     existing TINYINT flags can express. IsGroup/IsSpecialCase are NOT
 *     dropped or stopped-being-written here — they stay authoritative for
 *     every existing reader until Phase 3's creditPersonTypeApply() flips
 *     them to derived mirrors. This migration only BACKFILLS Type from the
 *     flags' current values (M1), once, guarded so it never overwrites a
 *     Type a curator (or a later run) has already set away from the
 *     'person' default.
 *   - tblCreditPeople.Disambiguation VARCHAR(255) DEFAULT '' — short
 *     parenthetical to distinguish same-named musicians.
 *   - tblCreditPeople.Biography MEDIUMTEXT NULL — the future dedicated bio
 *     field (plan M2). person.php TODAY renders the legacy `Notes` column as
 *     the bio (includes/pages/person.php:548-555) — that read path is NOT
 *     touched by this migration. We only COPY existing non-empty Notes
 *     content into Biography (never clear Notes) so Phase 4a can switch the
 *     read to Biography-with-Notes-fallback without a second migration and
 *     without losing anything a curator already wrote. Copying (not
 *     "moving" in the destructive sense) is deliberate: clearing Notes here
 *     would blank every public bio the instant this card is applied, weeks
 *     before P4a ships the code that reads Biography — a real behaviour
 *     change this batch must not cause.
 *   - tblCreditPersonMembers +7 columns (RelationType, DateFrom/Precision,
 *     DateTo/Precision, Note, UpdatedAt) — generalises the group/member link
 *     into a dated RELATION (plan M3: 'member' today, 'portrays' for a
 *     person credited as portraying a historical figure in a dramatisation).
 *     The table is re-keyed: `uq_group_member_rel
 *     (GroupPersonId,MemberPersonId,RelationType,DateFrom)` is ADDED before
 *     the old `uq_group_member (GroupPersonId,MemberPersonId)` is DROPPED
 *     (add-new-before-drop-old, rule #19) — a group/member pair can now
 *     legitimately repeat under a different relation or date range (e.g. a
 *     member who left and later rejoined). DateFrom sits inside the UNIQUE
 *     key and is nullable; MySQL/MariaDB treat every NULL as distinct inside
 *     a UNIQUE key (rule #20 pattern used throughout this schema, e.g.
 *     tblSongbookEntries.uq_book_number), so undated relations still collide
 *     on (Group,Member,RelationType) alone while multiple DATED relations
 *     for the same pair+type coexist.
 *   - tblCreditPersonAliases.Type ENUM->VARCHAR(20) (plan M4 rider) — a
 *     growable alias-classification vocabulary should never need an ALTER
 *     to add a value (rule #20). Data-preserving MODIFY; existing ENUM
 *     string values carry over unchanged.
 *
 * Column-name rename to SubjectMusicianId/ObjectMusicianId, and the
 * IsGroup/IsSpecialCase -> derived-mirror flip, are explicitly OUT of scope
 * here — they ride the Phase-2 lockstep rename (plan §2.6/§3-A) so the new
 * columns free-ride that RENAME TABLE rather than needing their own ALTER.
 *
 * STRICTLY ADDITIVE + IDEMPOTENT. Every ADD COLUMN / ADD INDEX / MODIFY is
 * existence- or DATA_TYPE-guarded; every backfill UPDATE is WHERE-guarded so
 * a re-run touches zero rows once the first run has completed.
 *
 * @migration-adds tblCreditPeople.Type
 * @migration-adds tblCreditPeople.Disambiguation
 * @migration-adds tblCreditPeople.Biography
 * @migration-adds tblCreditPersonMembers.RelationType
 * @migration-adds tblCreditPersonMembers.DateFrom
 * @migration-adds tblCreditPersonMembers.DateFromPrecision
 * @migration-adds tblCreditPersonMembers.DateTo
 * @migration-adds tblCreditPersonMembers.DateToPrecision
 * @migration-adds tblCreditPersonMembers.Note
 * @migration-adds tblCreditPersonMembers.UpdatedAt
 *
 * SCHEMA MIRROR: every column/index above is mirrored byte-identical (types,
 * defaults, COMMENT text) in appWeb/.sql/schema.sql inside the tblCreditPeople
 * / tblCreditPersonMembers / tblCreditPersonAliases CREATE TABLE blocks —
 * rule #19. tests/php/test-schema-coverage.php enforces the direction
 * migrations ⊆ schema.sql.
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-musician-profile.php
 *   Web:  /manage/setup-database → "Musician profile schema (#1741 P1)" button
 *
 * @requires PHP 8.1+ with mysqli
 * @see .claude/catalogue-expansion-1741-plan.md §2.1
 */

/* ELI5: are we being run from the command line, or clicked in the web dashboard?
   WHY: a web run needs plain-text headers; the dashboard sets a sentinel constant so
   the migration runner can capture our echo output instead of leaking raw HTTP headers. */
$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

/* ELI5: print one progress line, in CLI or HTML form depending on where we run.
   WHY: mirrors every other migrate-*.php's output helper so the dashboard's
   live <pre> capture and a terminal both render this legibly. */
function _migMusProf_output(string $m): void { global $isCli; echo $m . ($isCli ? "\n" : "<br>\n"); if (!$isCli) flush(); }

/* ELI5: does this table exist yet?
   WHY: nothing here actually depends on another table's existence (unlike
   works-identity/tune-enrichment's tblTunes dependency), but the helper is
   kept for symmetry with the sibling P1 scripts and any future guard. */
function _migMusProf_tableExists(\mysqli $db, string $t): bool { $r = $db->query("SHOW TABLES LIKE '{$t}'"); return (bool)($r && $r->num_rows > 0); }

/* ELI5: does this column already exist on this table?
   WHY: makes every ADD COLUMN idempotent — a re-run is a safe no-op rather
   than a 1060 "Duplicate column name" error. */
function _migMusProf_colExists(\mysqli $db, string $t, string $c): bool { $r = $db->query("SHOW COLUMNS FROM {$t} LIKE '{$c}'"); return (bool)($r && $r->num_rows > 0); }

/* ELI5: does this named index (or unique key) already exist?
   WHY: guards ADD/DROP INDEX so the re-key step (add uq_group_member_rel,
   drop uq_group_member) is safe to re-run from any partially-applied state. */
function _migMusProf_idxExists(\mysqli $db, string $t, string $idx): bool { $r = $db->query("SHOW INDEX FROM {$t} WHERE Key_name = '" . $db->real_escape_string($idx) . "'"); return (bool)($r && $r->num_rows > 0); }

/* ELI5: what MySQL type does this column currently have ('enum', 'varchar', …)?
   WHY: guards the ENUM->VARCHAR MODIFY so re-running after it has already
   widened the column is a no-op, mirroring migrate-identifier-media-hardening.php. */
function _migMusProf_dataType(\mysqli $db, string $t, string $c): string {
    $r = $db->query(
        "SELECT DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . $db->real_escape_string($t) . "'
            AND COLUMN_NAME = '" . $db->real_escape_string($c) . "' LIMIT 1"
    );
    $row = $r ? $r->fetch_assoc() : null;
    return $row ? strtolower((string)$row['DATA_TYPE']) : '';
}

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) { _migMusProf_output("ERROR: MySQL credentials not found. Run install.php first."); return; }
require_once $credFile;

_migMusProf_output("");
_migMusProf_output("=== iHymns — Musician profile schema batch (#1741 P1) ===");
_migMusProf_output("");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    $mysql->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
} catch (\mysqli_sql_exception $e) {
    _migMusProf_output("ERROR: MySQL connection failed: " . $e->getMessage()); return;
}
_migMusProf_output("Connected to MySQL: " . DB_NAME);

try {
    /* ---- tblCreditPeople: Type / Disambiguation / Biography ---- */
    _migMusProf_output("--- tblCreditPeople ---");

    if (_migMusProf_colExists($mysql, 'tblCreditPeople', 'Type')) {
        _migMusProf_output("  [SKIP] tblCreditPeople.Type already present.");
    } else {
        $mysql->query(
            "ALTER TABLE tblCreditPeople
                ADD COLUMN Type VARCHAR(20) NOT NULL DEFAULT 'person'
                    COMMENT 'person | group | character | orchestra | other (app-validated; VARCHAR not ENUM, rule #20). Authoritative once Phase 3 lands; IsGroup/IsSpecialCase stay the read/write source until then (#1741 P1)'
                AFTER IsGroup"
        );
        _migMusProf_output("  [OK] Added tblCreditPeople.Type.");
    }

    if (_migMusProf_colExists($mysql, 'tblCreditPeople', 'Disambiguation')) {
        _migMusProf_output("  [SKIP] tblCreditPeople.Disambiguation already present.");
    } else {
        $mysql->query(
            "ALTER TABLE tblCreditPeople
                ADD COLUMN Disambiguation VARCHAR(255) NOT NULL DEFAULT ''
                    COMMENT 'Short parenthetical to distinguish same-named musicians, e.g. \"(the elder)\" (#1741 P1)'
                AFTER Type"
        );
        _migMusProf_output("  [OK] Added tblCreditPeople.Disambiguation.");
    }

    if (_migMusProf_colExists($mysql, 'tblCreditPeople', 'Biography')) {
        _migMusProf_output("  [SKIP] tblCreditPeople.Biography already present.");
    } else {
        $mysql->query(
            "ALTER TABLE tblCreditPeople
                ADD COLUMN Biography MEDIUMTEXT NULL DEFAULT NULL
                    COMMENT 'Musician biography (#1741 P1). Backfilled once from legacy Notes (copy, not move — Notes stays the live read path on person.php until Phase 4a switches over); new writes go here once that phase ships'
                AFTER Notes"
        );
        _migMusProf_output("  [OK] Added tblCreditPeople.Biography.");
    }

    /* ---- M1: backfill Type from the existing IsGroup/IsSpecialCase flags ----
       WHERE Type = 'person' is the guard: it only ever touches a row that is
       still at the column default, so re-running (or running after a curator
       has since hand-picked 'character'/'orchestra') is a no-op for that row.
       IsGroup checked first so a row with both flags set (a special-case
       group) lands on 'group' — the more specific UI-facing distinction. */
    _migMusProf_output("--- Backfill tblCreditPeople.Type from IsGroup / IsSpecialCase ---");
    $mysql->query("UPDATE tblCreditPeople SET Type = 'group' WHERE Type = 'person' AND IsGroup = 1");
    $groupBackfilled = $mysql->affected_rows;
    $mysql->query("UPDATE tblCreditPeople SET Type = 'other' WHERE Type = 'person' AND IsSpecialCase = 1 AND IsGroup = 0");
    $otherBackfilled = $mysql->affected_rows;
    _migMusProf_output("  [OK] Type='group' backfilled for {$groupBackfilled} row(s); Type='other' backfilled for {$otherBackfilled} row(s).");

    /* ---- M2: copy legacy Notes into the new Biography column (never clears
       Notes — see the file doc-block for why this must stay additive-only). ---- */
    _migMusProf_output("--- Backfill tblCreditPeople.Biography from Notes (copy, Notes untouched) ---");
    $mysql->query(
        "UPDATE tblCreditPeople
            SET Biography = Notes
          WHERE Biography IS NULL AND Notes IS NOT NULL AND Notes <> ''"
    );
    $bioBackfilled = $mysql->affected_rows;
    _migMusProf_output("  [OK] Biography copied from Notes for {$bioBackfilled} row(s).");

    /* ---- tblCreditPersonMembers: +7 columns, re-key UNIQUE ---- */
    _migMusProf_output("--- tblCreditPersonMembers ---");

    $memberCols = [
        'RelationType' => "ADD COLUMN RelationType VARCHAR(30) NOT NULL DEFAULT 'member'
            COMMENT 'member | portrays (app-validated; VARCHAR not ENUM, rule #20) — portrays models e.g. a person credited as portraying a historical figure in a dramatisation (#1741 P1)'
            AFTER MemberPersonId",
        'DateFrom' => "ADD COLUMN DateFrom DATE NULL DEFAULT NULL
            COMMENT 'Start of this relation (member-since / portrayed-from); partial-date precision in DateFromPrecision (#1741 P1)'
            AFTER RelationType",
        'DateFromPrecision' => "ADD COLUMN DateFromPrecision VARCHAR(5) NULL DEFAULT NULL
            COMMENT 'How much of DateFrom is real: year | month | day (partial historical dates) (#1741 P1)'
            AFTER DateFrom",
        'DateTo' => "ADD COLUMN DateTo DATE NULL DEFAULT NULL
            COMMENT 'End of this relation (member-until / portrayed-until); NULL = ongoing/current (#1741 P1)'
            AFTER DateFromPrecision",
        'DateToPrecision' => "ADD COLUMN DateToPrecision VARCHAR(5) NULL DEFAULT NULL
            COMMENT 'How much of DateTo is real: year | month | day (partial historical dates) (#1741 P1)'
            AFTER DateTo",
        'Note' => "ADD COLUMN Note VARCHAR(255) NULL DEFAULT NULL
            COMMENT 'Free-text curator note about this relation (#1741 P1)'
            AFTER DateToPrecision",
        'UpdatedAt' => "ADD COLUMN UpdatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            AFTER CreatedAt",
    ];
    foreach ($memberCols as $col => $clause) {
        if (_migMusProf_colExists($mysql, 'tblCreditPersonMembers', $col)) {
            _migMusProf_output("  [SKIP] tblCreditPersonMembers.{$col} already present.");
        } else {
            $mysql->query("ALTER TABLE tblCreditPersonMembers {$clause}");
            _migMusProf_output("  [OK] Added tblCreditPersonMembers.{$col}.");
        }
    }

    /* Add-new-before-drop-old (rule #19): the new UNIQUE key must exist
       before the old one is dropped, so the table is never briefly without
       any uniqueness guard on (Group,Member). DateFrom sits inside the key
       and is nullable — NULL is distinct-per-row under InnoDB/MariaDB, so
       undated relations still collide on (Group,Member,RelationType) alone. */
    if (!_migMusProf_idxExists($mysql, 'tblCreditPersonMembers', 'uq_group_member_rel')) {
        $mysql->query(
            "ALTER TABLE tblCreditPersonMembers
                ADD UNIQUE KEY uq_group_member_rel (GroupPersonId, MemberPersonId, RelationType, DateFrom)"
        );
        _migMusProf_output("  [OK] Added unique key uq_group_member_rel.");
    } else {
        _migMusProf_output("  [SKIP] uq_group_member_rel present.");
    }

    if (_migMusProf_idxExists($mysql, 'tblCreditPersonMembers', 'uq_group_member')) {
        $mysql->query("ALTER TABLE tblCreditPersonMembers DROP INDEX uq_group_member");
        _migMusProf_output("  [OK] Dropped legacy unique key uq_group_member.");
    } else {
        _migMusProf_output("  [SKIP] uq_group_member already absent.");
    }

    /* ---- M4 rider: tblCreditPersonAliases.Type ENUM -> VARCHAR(20) ---- */
    _migMusProf_output("--- tblCreditPersonAliases.Type ENUM->VARCHAR ---");
    if (_migMusProf_dataType($mysql, 'tblCreditPersonAliases', 'Type') === 'varchar') {
        _migMusProf_output("  [SKIP] already VARCHAR.");
    } else {
        $mysql->query(
            "ALTER TABLE tblCreditPersonAliases
                MODIFY Type VARCHAR(20) NOT NULL DEFAULT 'other'
                    COMMENT 'legal | artist | pseudonym | nickname | maiden | search-hint | misspelling | other (app-validated; widened from ENUM, rule #20, #1741 P1)'"
        );
        _migMusProf_output("  [OK] Widened Type to VARCHAR(20).");
    }

    _migMusProf_output("");
    _migMusProf_output("Migration complete.");
} catch (\Throwable $e) {
    _migMusProf_output("  [ERROR] " . $e->getMessage());
}
$mysql->close();
return;
