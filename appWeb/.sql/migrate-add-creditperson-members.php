<?php

declare(strict_types=1);

/**
 * iHymns — Credit-person Group membership (#1502)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * ELI5: a credit person can be flagged as a "Group / band / collective"
 * (tblCreditPeople.IsGroup, #585) — e.g. "Hillsong United" or "Bethel
 * Music". This adds a way to record WHO is in that group, by linking to
 * the individual people already in the registry (each an ordinary
 * tblCreditPeople row of their own, e.g. "Reuben Morgan").
 *
 * DETAILED: tblCreditPersonMembers is a thin join table, one row per
 * (GroupPersonId, MemberPersonId) pair — deliberately NOT a generic
 * many-to-many "relationships" table, because the ONLY relationship
 * modelled today is group membership (rule #20 — build the schema the
 * feature actually needs, not a speculative generalisation). Both FKs
 * point at tblCreditPeople(Id) with ON DELETE CASCADE, so deleting
 * either the group or a member row cleans up the membership link
 * automatically — no orphaned rows to sweep separately. The UNIQUE key
 * on (GroupPersonId, MemberPersonId) is the "no duplicate membership"
 * guard; a group cannot list itself as a member — that check lives in
 * the application layer (includes/credit_people_helpers.php ::
 * addCreditPersonGroupMember()), not the schema, since a CHECK
 * constraint comparing two columns isn't portably enforceable pre-
 * MySQL 8.0.16 and this install's MySQL version isn't guaranteed to be
 * that recent everywhere the app runs.
 *
 * SortOrder is a plain INT UNSIGNED, append-order only for v1 (no
 * drag-reorder UI yet) — admin adds always land at the end of the
 * list; a future reorder UI can update SortOrder in place without a
 * further migration.
 *
 * Column types deliberately mirror tblCreditPeople.Id's declared type
 * (INT UNSIGNED AUTO_INCREMENT) rather than the plain signed INT a
 * first draft of this feature sketched — MySQL requires an FK column's
 * type to match its referenced column, so GroupPersonId / MemberPersonId
 * are INT UNSIGNED here.
 *
 * Additive + IDEMPOTENT (table-existence guarded). Dormant-safe: the
 * admin UI's "Members" card-list (credit-people.php) and the public
 * /people/<slug> members panel (person.php) both probe
 * creditPersonMembersTableExists() before reading/writing, so this
 * table being absent pre-migration is a clean no-op, not a 500.
 *
 * @migration-adds tblCreditPersonMembers
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-add-creditperson-members.php
 *   Web:  /manage/setup-database → "Credit-person Group membership" button
 *
 * @requires PHP 8.1+ with mysqli
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}
function _migCPM_output(string $m): void { global $isCli; echo $m . ($isCli ? "\n" : "<br>\n"); if (!$isCli) flush(); }
function _migCPM_tableExists(\mysqli $db, string $t): bool
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
if (!file_exists($credFile)) { _migCPM_output("ERROR: MySQL credentials not found. Run install.php first."); return; }
require_once $credFile;

_migCPM_output("");
_migCPM_output("=== iHymns — Credit-person Group membership (#1502) ===");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $mysql->set_charset('utf8mb4');
} catch (\mysqli_sql_exception $e) { _migCPM_output("ERROR: MySQL connection failed: " . $e->getMessage()); return; }
_migCPM_output("Connected to MySQL: " . DB_NAME);

try {
    if (!_migCPM_tableExists($mysql, 'tblCreditPeople')) {
        _migCPM_output("  [SKIP] tblCreditPeople not present — run migrate-credit-people.php (#545) first.");
    } elseif (_migCPM_tableExists($mysql, 'tblCreditPersonMembers')) {
        _migCPM_output("  [SKIP] tblCreditPersonMembers already present.");
    } else {
        $mysql->query(
            "CREATE TABLE tblCreditPersonMembers (
                Id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                GroupPersonId  INT UNSIGNED NOT NULL COMMENT 'FK to tblCreditPeople.Id — the Group/band/collective person',
                MemberPersonId INT UNSIGNED NOT NULL COMMENT 'FK to tblCreditPeople.Id — an individual member of the group',
                SortOrder      INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Admin-controlled display order within the group; append-order by default',
                CreatedAt      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

                UNIQUE KEY uq_group_member (GroupPersonId, MemberPersonId),
                INDEX      idx_group  (GroupPersonId),
                INDEX      idx_member (MemberPersonId),

                CONSTRAINT fk_creditpersonmembers_group
                    FOREIGN KEY (GroupPersonId)  REFERENCES tblCreditPeople(Id) ON DELETE CASCADE,
                CONSTRAINT fk_creditpersonmembers_member
                    FOREIGN KEY (MemberPersonId) REFERENCES tblCreditPeople(Id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Group/band/collective -> individual member people (#1502).'"
        );
        _migCPM_output("  [OK] Created tblCreditPersonMembers.");
    }

    _migCPM_output("Migration complete.");
} catch (\mysqli_sql_exception $e) {
    _migCPM_output("ERROR: " . $e->getMessage());
}
