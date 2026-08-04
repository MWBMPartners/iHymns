<?php

declare(strict_types=1);

/**
 * iHymns — Account lifecycle status: disabled vs deleted (#1698)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * TWO additive columns on `tblUsers` that let the app tell a DISABLED account
 * (switched off, fully reversible) apart from a DELETED one (an anonymised
 * tombstone). Both are inactive; only one can come back.
 *
 * WHY `IsActive` WAS NOT ENOUGH — AND WHY IT STAYS LOAD-BEARING.
 * `tblUsers.IsActive` has existed since #229 and is THE lock: `getAuthenticatedUser()`
 * (api.php) filters `u.IsActive = 1` on every bearer request, and
 * manage/includes/auth.php carries thirteen more enforcement points across token
 * auth, session resolution, login, password reset, email login and verification.
 * Both inactive states set `IsActive = 0`, so every one of those queries locks
 * the account out with NO CHANGE AT ALL. `Status` adds no lock and is not
 * consulted for authentication. Its ONLY job is to say WHICH kind of inactive
 * this is, for:
 *   - the admin list's status badge (Active / Disabled / Deleted);
 *   - the reactivation guard — `setUserActive(true)` must REFUSE a tombstone,
 *     because a scrubbed identity cannot be handed back;
 *   - the lock LABEL on shared surfaces, where "the owner's account is
 *     unavailable" and "has been closed" read differently;
 *   - the erasure core's own idempotency ("already erased" = Status 'deleted').
 * The invariant `IsActive = (Status = 'active')` is enforced in ONE writer,
 * `setUserActive()`.
 *
 * DESIGN NOTES — the rule #20 adversarial pass ("what would force a second
 * migration?"), answered before writing the DDL:
 *   - `VARCHAR(20)`, NEVER an ENUM. A future `suspended` /
 *     `pending_verification` / `pending_deletion` state is then a new value plus
 *     ONE line in `userStatusVocabulary()` — whereas an ENUM value-add is an
 *     `ALTER`, i.e. exactly the second migration rule #20 forbids. The value is
 *     app-validated against that central map, the same shape as
 *     `tblSetlistCollaborators.Permission` and `tblLyricsConflicts.Status`.
 *   - "WHEN was it disabled/erased?" is `StatusChangedAt`, so a retention window
 *     or a scheduled purge needs no further column. `DATETIME`, not `TIMESTAMP`:
 *     these rows outlive the session that wrote them and a TIMESTAMP would be
 *     re-interpreted against whatever session zone read it. Same convention as
 *     the #1066 idempotency TTLs.
 *   - "WHO did it?" needs nothing — `tblActivityLog` has carried
 *     `user.activate` / `user.deactivate` / `user.delete` since #535.
 *   - `INDEX idx_Status` because the admin user list will filter and count by
 *     it, and because a future purge job's `WHERE Status = 'deleted' AND
 *     StatusChangedAt < ?` should not table-scan every account.
 *
 * THE BACKFILL. Existing `IsActive = 0` rows become `Status = 'disabled'`, which
 * is exactly what they are: accounts an admin switched off, none of which have
 * ever been through an anonymising erasure (there was no such thing before this
 * change). It is written as a guarded UPDATE keyed on `Status = 'active'` so
 * re-running the migration can never re-stamp an account that has since been
 * erased. `StatusChangedAt` is left NULL for those rows on purpose — we do not
 * know when they were disabled, and inventing "now" would be a false audit fact.
 *
 * ⚠️ APPLYING THIS IS NOT AUTOMATIC. Migrations are web-run from
 * /manage/setup-database and the three docroots share ONE MySQL, so every reader
 * is existence-gated behind `userStatusColumnReady()`
 * (includes/user_status.php). An un-migrated install behaves EXACTLY as before:
 * `userStateFromRow()` falls back to `IsActive`, so accounts still read as
 * active or disabled — the one thing it cannot say is "deleted", and on such an
 * install nothing has written a tombstone. The erasure core deliberately does
 * NOT degrade: it REFUSES, naming this card, rather than falling back to the old
 * irreversible hard delete (see includes/account_lifecycle.php).
 *
 * Additive + IDEMPOTENT (existence-guarded). Safe to re-run.
 *
 * @migration-adds tblUsers.Status
 * @migration-adds tblUsers.StatusChangedAt
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-user-account-status.php
 *   Web:  /manage/setup-database → "Account lifecycle status (#1698)" button
 *
 * @requires PHP 8.1+ with mysqli
 * @link https://dev.mysql.com/doc/refman/8.0/en/datetime.html
 * @link https://dev.mysql.com/doc/refman/8.0/en/alter-table.html
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

function _migUAS_output(string $m): void
{
    global $isCli;
    echo $m . ($isCli ? "\n" : "<br>\n");
    if (!$isCli) { flush(); }
}

function _migUAS_columnExists(\mysqli $db, string $t, string $c): bool
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

function _migUAS_indexExists(\mysqli $db, string $t, string $i): bool
{
    $stmt = $db->prepare(
        "SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1"
    );
    $stmt->bind_param('ss', $t, $i);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $exists;
}

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) {
    _migUAS_output("ERROR: MySQL credentials not found. Run install.php first.");
    return;
}
require_once $credFile;

_migUAS_output("");
_migUAS_output("=== iHymns — Account lifecycle status: disabled vs deleted (#1698) ===");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $mysql->set_charset('utf8mb4');
} catch (\mysqli_sql_exception $e) {
    _migUAS_output("ERROR: MySQL connection failed: " . $e->getMessage());
    return;
}
_migUAS_output("Connected to MySQL: " . DB_NAME);

try {
    /* ------------------------------------------------------------------
     * Each column is added SEPARATELY rather than in one multi-column
     * ALTER. Two reasons, both learned the hard way in this repo:
     *   - a partial apply (connection dropped between the two) leaves a
     *     recoverable state that the OR-probe in migration-registry.php
     *     correctly still reports as pending;
     *   - the schema-coverage scanner reads one `ADD COLUMN` per ALTER,
     *     which is why rule #19 demands a `@migration-adds` doctag per
     *     column; separate statements keep DDL and doctags one-to-one.
     *
     * The DDL below must stay BYTE-IDENTICAL to its mirror in schema.sql
     * (rule #19), COMMENT text included, so a fresh install and a migrated
     * install are structurally the same database and /manage/schema-audit
     * (#518) has nothing to report.
     * ------------------------------------------------------------------ */
    if (_migUAS_columnExists($mysql, 'tblUsers', 'Status')) {
        _migUAS_output("  [SKIP] tblUsers.Status already present.");
    } else {
        $mysql->query(
            "ALTER TABLE tblUsers
                ADD COLUMN Status VARCHAR(20) NOT NULL DEFAULT 'active' COMMENT 'active | disabled | deleted — app-validated vocabulary, VARCHAR not ENUM (rule #20). deleted = anonymised tombstone (#1698)'"
        );
        _migUAS_output("  [OK] Added tblUsers.Status.");
    }

    if (_migUAS_columnExists($mysql, 'tblUsers', 'StatusChangedAt')) {
        _migUAS_output("  [SKIP] tblUsers.StatusChangedAt already present.");
    } else {
        $mysql->query(
            "ALTER TABLE tblUsers
                ADD COLUMN StatusChangedAt DATETIME NULL DEFAULT NULL COMMENT 'UTC instant Status last changed; DATETIME not TIMESTAMP so it is not re-read against a session zone (#1698)'"
        );
        _migUAS_output("  [OK] Added tblUsers.StatusChangedAt.");
    }

    if (_migUAS_indexExists($mysql, 'tblUsers', 'idx_Status')) {
        _migUAS_output("  [SKIP] tblUsers.idx_Status already present.");
    } else {
        $mysql->query("ALTER TABLE tblUsers ADD INDEX idx_Status (Status)");
        _migUAS_output("  [OK] Added index tblUsers.idx_Status.");
    }

    /* ------------------------------------------------------------------
     * BACKFILL — an already-deactivated account is a DISABLED one.
     *
     * Narrowed by `Status = 'active'` so this is safe to re-run and can
     * never re-stamp an account erased after the first run (an erased
     * account is also IsActive = 0, and a blind
     * `SET Status='disabled' WHERE IsActive=0` would resurrect it into a
     * reactivatable state with its identity already scrubbed).
     * ------------------------------------------------------------------ */
    $mysql->query("UPDATE tblUsers SET Status = 'disabled' WHERE IsActive = 0 AND Status = 'active'");
    _migUAS_output("  [OK] Backfilled " . $mysql->affected_rows . " already-deactivated account(s) to Status = 'disabled'.");

    _migUAS_output("Migration complete.");
} catch (\mysqli_sql_exception $e) {
    _migUAS_output("ERROR: " . $e->getMessage());
}
