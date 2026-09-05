<?php

declare(strict_types=1);

/**
 * iHymns — Reconcile tblOrganisationLicences's shape with schema.sql (#2078)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * `migrate-organisation-licences.php` (#640) and `schema.sql` describe two
 * DIFFERENT shapes for the same table — a long-running install (which ran
 * that migration) and a fresh install (which reads schema.sql directly)
 * ended up with genuinely different columns. The worst of the differences:
 * an install that ran the migration stores a licence's expiry as a plain
 * DATE (no time of day), while a fresh install stores it as a full
 * date-and-time. `ExpiresAt` feeds the CCLI licence gate (`ccli_validator.php`,
 * `licences.php`, `service_mode.php`, `print_usage.php` all compare it
 * against `NOW()`), so a licence expiring "today" was answering that
 * comparison differently depending on which install you asked. This script
 * brings an already-migrated install's table into line with schema.sql's
 * shape, carefully, without losing or truncating anything it finds there.
 *
 * WHY THIS IS ITS OWN SCRIPT, NOT AN EDIT TO migrate-organisation-licences.php
 * ----------------------------------------------------------------------------
 * That script's own `CREATE TABLE` is a historical record of the shape it
 * actually built on the day it ran (#640) — editing it to read like
 * schema.sql today would make it lie about what an install that already ran
 * it actually has. The precedent for "a later migration narrows an earlier
 * one's shape to match schema.sql's current, evolved shape" is
 * `migrate-musicians-rename.php`, and this script copies its idempotency
 * shape directly: every step re-checks the LIVE schema before acting (via
 * this file's own `_migOrgLicSchema_*` probes, mirroring that script's
 * `_migMusRename_*` ones), so a second run — or a run against a database
 * that is already partway there — is a clean no-op for whatever has already
 * landed. `tests/php/test-schema-ddl-parity.php` recognises this same shape
 * (an `ALTER TABLE tblOrganisationLicences` containing `MODIFY`/`CHANGE`,
 * `RENAME INDEX`, or the `DROP FOREIGN KEY x, ADD CONSTRAINT y` pattern,
 * registered AFTER the original CREATE) and stops holding the original
 * migration's CREATE TABLE to schema.sql's current shape once this script
 * exists — see that file's "LATER-TRANSFORM-EXEMPT" doc-block section.
 *
 * THE THREE DATA-SAFETY JUDGEMENT CALLS THE ISSUE ASKS FOR
 * ----------------------------------------------------------------------------
 * 1. What should a date-only expiry become when it gains a time of day?
 *    END OF THAT DAY (23:59:59) — an expiry recorded only as a date has
 *    always been understood to mean "valid through the end of that day".
 *    Storing it at 00:00:00 the moment the column gains a time component
 *    would make a licence expiring "today" already read as expired from
 *    midnight onward — silently shortening every existing licence's cover
 *    by up to 24 hours, which is exactly the access-decision regression
 *    the underlying issue (#2078) is about. This is a product judgement,
 *    not a mechanical one, which is why it is written out here rather than
 *    just done.
 * 2. Can narrowing a column truncate or lose an existing value? For BOTH
 *    risky narrows this script performs (LicenceNumber 255->100 chars,
 *    and ExpiresAt/CreatedAt/UpdatedAt DATETIME->TIMESTAMP, which can only
 *    hold 1970-01-01 00:00:01..2038-01-19 03:14:07 UTC) — YES, in
 *    principle. There is no live database in this environment to check by
 *    hand (see the issue's own "First step" instruction), so this script
 *    does not guess: it COUNTS the actual rows that would be truncated on
 *    whichever database it is actually run against, and skips ONLY the
 *    narrowing step for a column where that count is above zero, leaving
 *    that one column at its current (wider, safe) width/type and printing
 *    a clear, actionable message naming the row count. Every OTHER,
 *    genuinely safe part of the reconciliation (nullability, defaults,
 *    comments, index/constraint names) still completes on that same run —
 *    one column needing a manual data trim first does not block the rest.
 * 3. Renaming an index/constraint must tolerate either name already being
 *    present and be safe to run twice — copied directly from
 *    `migrate-musicians-rename.php`'s `_migMusRename_renameIndex()` /
 *    `_migMusRename_renameFk()` shape (see `_migOrgLicSchema_renameIndex()`
 *    / `_migOrgLicSchema_renameFk()` below): skip if the NEW name is
 *    already there (done), warn-skip if NEITHER name is found (unexpected,
 *    never fatal).
 *
 * WHAT THIS SCRIPT RECONCILES (see schema.sql's tblOrganisationLicences for
 * the exact target shape it copies every literal from):
 *   - LicenceType    : add the `tblLicenceTypes` registry COMMENT (no data
 *                      risk — a comment change, not a value change).
 *   - LicenceNumber  : NULL -> '' (the two have always meant the same thing
 *                      here — "no number recorded"), then NOT NULL DEFAULT
 *                      '', then narrow VARCHAR(255) -> VARCHAR(100) ONLY
 *                      when nothing currently stored is longer than 100
 *                      characters (see judgement call 2 above).
 *   - ExpiresAt      : DATE -> DATETIME (existing dates land at 00:00:00,
 *                      nothing lost — a DATE has no time to lose) -> every
 *                      value still sitting at midnight is pushed to
 *                      23:59:59 the SAME day (judgement call 1) -> DATETIME
 *                      -> TIMESTAMP, but ONLY once every value actually fits
 *                      TIMESTAMP's range (judgement call 2).
 *   - CreatedAt/UpdatedAt: DATETIME -> TIMESTAMP, same range check as
 *                      ExpiresAt (belt-and-braces only — these are always
 *                      set at INSERT time by an app that has never run
 *                      before 1970 or after 2038, so this is not expected
 *                      to ever actually skip, but the check runs anyway
 *                      rather than assuming).
 *   - Indexes        : `uk_OrgLicence` -> `uniq_OrgLicence` (rename); the
 *                      redundant `idx_OrganisationId` is dropped (the
 *                      renamed unique key already covers `OrganisationId`
 *                      as its LEFTMOST column, so InnoDB's "an FK column
 *                      needs a covering index" requirement stays satisfied
 *                      without it — dropping it removes a duplicate, never
 *                      an only copy); `idx_IsActive` is added.
 *   - Constraint     : `fk_OrgLicences_Org` -> `fk_OrgLicence_Org` (same
 *                      columns/reference/actions — only the name changes).
 *
 * DELIBERATELY LEFT UNTOUCHED: `Notes TEXT NULL` vs schema.sql's
 * `Notes TEXT NULL DEFAULT NULL` — semantically identical (a nullable
 * column's implicit default is already NULL), and MySQL/MariaDB versions
 * before 8.0.13 reject ANY explicit `DEFAULT` clause on a TEXT column, even
 * `DEFAULT NULL` — adding one here for a purely cosmetic gain isn't worth
 * risking a failed ALTER on an older host. This is the one drift this
 * script does not chase; it does not affect the CCLI gate or anything else
 * that reads `Notes`.
 *
 * USAGE:
 *   Web:  /manage/setup-database -> "Reconcile organisation-licences schema (#2078)"
 *   CLI:  php appWeb/.sql/migrate-reconcile-organisation-licences-schema.php
 *   Prerequisite: the "Multiple licence types per organisation (#640)" card
 *   (creates `tblOrganisationLicences` in the first place). A no-op, not a
 *   fatal, when that table doesn't exist yet.
 *
 * Idempotent — every step re-checks the live schema before acting.
 *
 * @see appWeb/.sql/migrate-organisation-licences.php  the original CREATE (kept as historical record)
 * @see appWeb/.sql/migrate-musicians-rename.php        the idempotent rename shape this copies
 * @see appWeb/.sql/schema.sql                          the target shape, copied literal-for-literal
 * @see tests/php/test-schema-ddl-parity.php            the guard this closes out (#2078)
 * @see #2078
 */

if (PHP_SAPI === 'cli') {
    /* Guarded require — see migrate-organisation-licences.php:56 (#652) and
       CLAUDE.md rule #41. The dashboard has already loaded db_mysql.php via
       auth.php's bootstrap, so the function already exists at this point in
       dashboard mode; the guard skips the re-open that some hosts block from
       outside public_html/, and the literal path is only ever reached from a
       standalone CLI/test run (where public_html is the real folder name),
       never from the renamed-per-channel dashboard runner. */
    if (!function_exists('getDbMysqli')) {
        require_once dirname(__DIR__) . '/public_html/includes/db_mysql.php';
    }
    $isCli = true;
} else {
    if (!defined('IHYMNS_SETUP_DASHBOARD')) {
        /* Guarded: dashboard mode pre-loads auth.php transitively (#652/#41). */
        if (!function_exists('isAuthenticated')) {
            require_once dirname(__DIR__) . '/public_html/manage/includes/auth.php';
        }
        if (!isAuthenticated()) {
            http_response_code(401);
            exit('Authentication required.');
        }
        $u = getCurrentUser();
        if (!$u || $u['role'] !== 'global_admin') {
            http_response_code(403);
            exit('Global admin required.');
        }
    }
    if (!function_exists('getDbMysqli')) {
        require_once dirname(__DIR__) . '/public_html/includes/db_mysql.php';
    }
    $isCli = false;
}

/* ------------------------------------------------------------------ *
 * Small local helpers — self-contained (never reaches for
 * setup-database.php's _migProbe_* functions, which are not guaranteed
 * loaded when this file runs standalone under CLI). Mirrors
 * migrate-musicians-rename.php's _migMusRename_* naming/shape exactly.
 * ------------------------------------------------------------------ */

function _migOrgLicSchema_out(string $line): void
{
    global $isCli;
    echo $line . ($isCli ? "\n" : "<br>\n");
    if ($isCli) {
        flush();
    }
}

function _migOrgLicSchema_tableExists(\mysqli $db, string $table): bool
{
    $stmt = $db->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
    );
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $exists;
}

function _migOrgLicSchema_columnDataType(\mysqli $db, string $table, string $column): string
{
    $stmt = $db->prepare(
        'SELECT DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
    );
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? strtolower((string)$row['DATA_TYPE']) : '';
}

function _migOrgLicSchema_columnCharLength(\mysqli $db, string $table, string $column): int
{
    $stmt = $db->prepare(
        'SELECT CHARACTER_MAXIMUM_LENGTH FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
    );
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row && $row['CHARACTER_MAXIMUM_LENGTH'] !== null ? (int)$row['CHARACTER_MAXIMUM_LENGTH'] : 0;
}

function _migOrgLicSchema_columnIsNullable(\mysqli $db, string $table, string $column): bool
{
    $stmt = $db->prepare(
        'SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
    );
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    /* Absent column: treat as nullable so callers don't wrongly skip a fix
       they can't actually assess — the surrounding table-existence guard
       is what decides whether to run at all. */
    return !$row || strtoupper((string)$row['IS_NULLABLE']) === 'YES';
}

/** Current COLUMN_DEFAULT, normalised to '' when the default IS the empty
 *  string (MariaDB reports a string column's DEFAULT as the literal
 *  `'value'`, quotes included; MySQL reports the bare `value` — see
 *  migrate-musicians-rename.php's _migMusRename_colDefault() doc-block for
 *  the same normalisation, copied here). Returns null when there is no
 *  default at all (distinct from an empty-string default). */
function _migOrgLicSchema_columnDefault(\mysqli $db, string $table, string $column): ?string
{
    $stmt = $db->prepare(
        'SELECT COLUMN_DEFAULT FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
    );
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row || $row['COLUMN_DEFAULT'] === null) {
        return null;
    }
    $v = (string)$row['COLUMN_DEFAULT'];
    if (strlen($v) >= 2 && $v[0] === "'" && $v[strlen($v) - 1] === "'") {
        $v = substr($v, 1, -1);
    }
    return $v;
}

function _migOrgLicSchema_columnComment(\mysqli $db, string $table, string $column): string
{
    $stmt = $db->prepare(
        'SELECT COLUMN_COMMENT FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
    );
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (string)$row['COLUMN_COMMENT'] : '';
}

function _migOrgLicSchema_idxExists(\mysqli $db, string $table, string $idx): bool
{
    $r = $db->query(
        "SHOW INDEX FROM {$table} WHERE Key_name = '" . $db->real_escape_string($idx) . "'"
    );
    return (bool)($r && $r->num_rows > 0);
}

/** Is a named FK constraint present anywhere in this schema? Constraint
 *  names are unique per-schema in MySQL/MariaDB, so no table qualifier is
 *  needed (mirrors migrate-musicians-rename.php's _migMusRename_fkExists()). */
function _migOrgLicSchema_fkExists(\mysqli $db, string $name): bool
{
    $stmt = $db->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
          WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = ? LIMIT 1'
    );
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $exists;
}

/** Rename an index, guarded both ways — same shape as
 *  migrate-musicians-rename.php's _migMusRename_renameIndex(). $sql is the
 *  FULL literal `ALTER TABLE tblX RENAME INDEX old TO new` statement, passed
 *  in (not built here from $table/$old/$new) so it appears VERBATIM in this
 *  file's SOURCE TEXT — tests/php/test-schema-ddl-parity.php's
 *  ddlScanTransformFiles() regex-scans migration files for exactly that
 *  literal shape to recognise "this migration restructures this table"
 *  (see this file's own doc-block, and migrate-musicians-rename.php's
 *  _migMusRename_renameColumn() doc-block for the same reasoning applied to
 *  RENAME COLUMN). A statement built only inside this function from its
 *  $table/$old/$new PARAMETERS would read as `{$table}` etc. in the source
 *  text, not as the real table/index names, and would be invisible to that
 *  scan. $table/$old/$new stay separate params purely for the idempotency
 *  guard checks above. */
function _migOrgLicSchema_renameIndex(\mysqli $db, string $table, string $old, string $new, string $sql): void
{
    if (_migOrgLicSchema_idxExists($db, $table, $new)) {
        _migOrgLicSchema_out("  [SKIP] index {$table}.{$new} already present.");
        return;
    }
    if (!_migOrgLicSchema_idxExists($db, $table, $old)) {
        _migOrgLicSchema_out("  [WARN] Neither index {$table}.{$old} nor {$table}.{$new} found — skipping.");
        return;
    }
    $db->query($sql);
    _migOrgLicSchema_out("  [OK] index {$table}.{$old} -> {$new}.");
}

/** Swap an FK constraint's NAME (MySQL/MariaDB has no RENAME for
 *  constraints — DROP the old, ADD the new with the SAME columns/reference/
 *  actions, in one statement so there is never a moment without the FK).
 *  Mirrors migrate-musicians-rename.php's _migMusRename_renameFk(). $sql is
 *  the FULL literal statement, passed in verbatim for the same
 *  static-scan reason documented on _migOrgLicSchema_renameIndex() above. */
function _migOrgLicSchema_renameFk(\mysqli $db, string $table, string $old, string $new, string $sql): void
{
    if (_migOrgLicSchema_fkExists($db, $new)) {
        _migOrgLicSchema_out("  [SKIP] constraint {$new} already present.");
        return;
    }
    if (!_migOrgLicSchema_fkExists($db, $old)) {
        _migOrgLicSchema_out("  [WARN] Neither constraint {$old} nor {$new} found on {$table} — skipping.");
        return;
    }
    $db->query($sql);
    _migOrgLicSchema_out("  [OK] constraint {$table}.{$old} -> {$new}.");
}

/* ------------------------------------------------------------------ *
 * Main
 * ------------------------------------------------------------------ */

_migOrgLicSchema_out('Reconcile tblOrganisationLicences schema (#2078) starting…');

$mysql = getDbMysqli();
if (!$mysql) {
    _migOrgLicSchema_out('ERROR: could not connect.');
    exit(1);
}

$tbl = 'tblOrganisationLicences';

if (!_migOrgLicSchema_tableExists($mysql, $tbl)) {
    _migOrgLicSchema_out("[SKIP] {$tbl} does not exist yet — run the \"Multiple licence types per organisation (#640)\" card first, then re-run this one.");
    _migOrgLicSchema_out('Reconcile tblOrganisationLicences schema (#2078) finished.');
    /* Don't close $mysql — see the closing note at the bottom of this file. */
    exit(0);
}

/* ==== LicenceType: add the tblLicenceTypes registry COMMENT ==== */
_migOrgLicSchema_out('--- LicenceType: add the registry COMMENT ---');
if (str_contains(_migOrgLicSchema_columnComment($mysql, $tbl, 'LicenceType'), 'tblLicenceTypes')) {
    _migOrgLicSchema_out('  [SKIP] LicenceType comment already references the registry.');
} else {
    $mysql->query(
        "ALTER TABLE tblOrganisationLicences MODIFY COLUMN LicenceType VARCHAR(30) NOT NULL
            COMMENT 'Licence vocabulary token -- registry: tblLicenceTypes (includes/licence_registry.php, #459/#1769 P2). e.g. ccli | mrl | ihymns_basic | ihymns_pro | custom'"
    );
    _migOrgLicSchema_out('  [OK] LicenceType comment now references the tblLicenceTypes registry.');
}

/* ==== LicenceNumber: NULL -> '', NOT NULL DEFAULT '', narrow width only if safe ==== */
_migOrgLicSchema_out("--- LicenceNumber: NULL -> '', NOT NULL DEFAULT '', narrow width only if safe ---");
$lnType     = _migOrgLicSchema_columnDataType($mysql, $tbl, 'LicenceNumber');
$lnLen      = _migOrgLicSchema_columnCharLength($mysql, $tbl, 'LicenceNumber');
$lnNullable = _migOrgLicSchema_columnIsNullable($mysql, $tbl, 'LicenceNumber');
$lnDefault  = _migOrgLicSchema_columnDefault($mysql, $tbl, 'LicenceNumber');

if ($lnType === 'varchar' && $lnLen === 100 && !$lnNullable && $lnDefault === '') {
    _migOrgLicSchema_out("  [SKIP] LicenceNumber already VARCHAR(100) NOT NULL DEFAULT ''.");
} else {
    if ($lnNullable) {
        $mysql->query("UPDATE {$tbl} SET LicenceNumber = '' WHERE LicenceNumber IS NULL");
        $n = $mysql->affected_rows;
        if ($n > 0) {
            _migOrgLicSchema_out("  [OK] Converted {$n} NULL LicenceNumber value(s) to '' ahead of the NOT NULL tightening (the two have always meant the same thing here).");
        }
    }

    /* Judgement call 2 (see file doc-block): narrowing VARCHAR(255) to
       VARCHAR(100) would truncate any value already longer than 100
       characters. There is no live database in this environment to check
       by hand, so the check runs here, against whichever database this
       migration is actually applied to — never assumed. The target width is
       MAX(100, longest value actually stored) — computed from the DATA
       itself, never from the column's own currently-declared width, so this
       stays provably non-truncating even if that declared width were ever
       something unexpected (e.g. hand-altered outside this migration). */
    $r = $mysql->query("SELECT COALESCE(MAX(CHAR_LENGTH(LicenceNumber)), 0) AS maxlen FROM {$tbl}");
    $maxLen = (int)(($r ? $r->fetch_assoc() : null)['maxlen'] ?? 0);
    $targetWidth = max(100, $maxLen);

    if ($maxLen > 100) {
        _migOrgLicSchema_out(
            "  [WARN] The longest existing LicenceNumber value is {$maxLen} characters —" .
            " keeping the column at VARCHAR({$targetWidth}) instead of narrowing to VARCHAR(100)," .
            ' so nothing is truncated. Nullability and default are still fixed below.' .
            ' Trim the long value(s) by hand, then re-run this migration to finish narrowing the column.'
        );
    }
    /* $targetWidth is an int computed above from the live data — never user
       input, but the ALTER text below is still necessarily variable-built
       since the WIDTH itself is the thing being decided at runtime. That's
       fine: this particular ALTER doesn't need to be statically detected by
       ddlScanTransformFiles() (the LicenceType and index/FK statements
       elsewhere in this file already are, which is all the detection
       needs — see this file's doc-block). */
    $mysql->query("ALTER TABLE tblOrganisationLicences MODIFY COLUMN LicenceNumber VARCHAR({$targetWidth}) NOT NULL DEFAULT ''");
    _migOrgLicSchema_out(
        "  [OK] LicenceNumber -> VARCHAR({$targetWidth}) NOT NULL DEFAULT ''" .
        ($maxLen > 100 ? ' (width kept wider than 100, see WARNING above).' : '.')
    );
}

/* ==== ExpiresAt: DATE -> TIMESTAMP (existing values become end of that day) ==== */
_migOrgLicSchema_out('--- ExpiresAt: DATE -> TIMESTAMP (existing dates become end of that day) ---');
$expType = _migOrgLicSchema_columnDataType($mysql, $tbl, 'ExpiresAt');

if ($expType === '') {
    _migOrgLicSchema_out('  [WARN] ExpiresAt column not found — skipping.');
} elseif ($expType === 'timestamp') {
    _migOrgLicSchema_out('  [SKIP] ExpiresAt already TIMESTAMP.');
} else {
    if ($expType === 'date') {
        /* A DATE column has no time to lose — MySQL pads the existing value
           with 00:00:00 on the same calendar day. */
        $mysql->query("ALTER TABLE tblOrganisationLicences MODIFY COLUMN ExpiresAt DATETIME NULL DEFAULT NULL");
        _migOrgLicSchema_out('  [OK] ExpiresAt DATE -> DATETIME (existing dates padded to 00:00:00, same calendar day, nothing lost).');
    }

    /* Judgement call 1 (see file doc-block): push every value still sitting
       at midnight to the LAST second of that same day — an expiry recorded
       only as a date has always meant "valid through the end of that day".
       The TIME(...) = '00:00:00' guard is what makes this safe to re-run: a
       row this step already bumped no longer reads midnight, so a second
       pass leaves it alone. */
    $mysql->query(
        "UPDATE {$tbl} SET ExpiresAt = ADDTIME(ExpiresAt, '23:59:59')
          WHERE ExpiresAt IS NOT NULL AND TIME(ExpiresAt) = '00:00:00'"
    );
    $bumped = $mysql->affected_rows;
    if ($bumped > 0) {
        _migOrgLicSchema_out("  [OK] Moved {$bumped} date-only expiry value(s) to 23:59:59 on the same day.");
    }

    /* Judgement call 2: TIMESTAMP can only hold 1970-01-01 00:00:01 ..
       2038-01-19 03:14:07 (UTC); DATETIME has no such ceiling. Checked, not
       assumed, for the same "no live database here" reason as above. */
    $r = $mysql->query(
        "SELECT COUNT(*) AS c FROM {$tbl}
          WHERE ExpiresAt IS NOT NULL
            AND (ExpiresAt < '1970-01-01 00:00:01' OR ExpiresAt > '2038-01-19 03:14:07')"
    );
    $outOfRange = (int)(($r ? $r->fetch_assoc() : null)['c'] ?? 0);
    if ($outOfRange > 0) {
        _migOrgLicSchema_out(
            "  [WARN] {$outOfRange} ExpiresAt value(s) fall outside what TIMESTAMP can hold" .
            ' (1970-01-01..2038-01-19) — leaving the column as DATETIME rather than risk' .
            ' corrupting a real expiry date. This is the one part of the ExpiresAt fix that' .
            ' cannot complete automatically; move the offending row(s) to a nearer date (or' .
            ' NULL for "no expiry"), then re-run this migration.'
        );
    } else {
        $mysql->query("ALTER TABLE tblOrganisationLicences MODIFY COLUMN ExpiresAt TIMESTAMP NULL DEFAULT NULL");
        _migOrgLicSchema_out('  [OK] ExpiresAt DATETIME -> TIMESTAMP.');
    }
}

/* ==== CreatedAt / UpdatedAt: DATETIME -> TIMESTAMP ====
   Written out twice rather than looped: each ALTER needs to appear as a
   LITERAL statement in this file's source text (table AND column name, not
   a variable) for the same static-scan reason documented on
   _migOrgLicSchema_renameIndex() above — a loop that builds the SQL from a
   `$col` variable would read as `MODIFY COLUMN {$col} ...` in the source,
   never as the real column name. */
_migOrgLicSchema_out('--- CreatedAt: DATETIME -> TIMESTAMP ---');
$caType = _migOrgLicSchema_columnDataType($mysql, $tbl, 'CreatedAt');
if ($caType === '') {
    _migOrgLicSchema_out('  [WARN] CreatedAt column not found — skipping.');
} elseif ($caType === 'timestamp') {
    _migOrgLicSchema_out('  [SKIP] CreatedAt already TIMESTAMP.');
} else {
    /* Belt-and-braces range check, mirroring ExpiresAt's — CreatedAt is
       always set at INSERT time by an app that has never run before 1970 or
       after 2038, so this is not expected to ever actually trip, but it
       runs anyway rather than assuming (judgement call 2). */
    $r = $mysql->query(
        "SELECT COUNT(*) AS c FROM {$tbl}
          WHERE CreatedAt < '1970-01-01 00:00:01' OR CreatedAt > '2038-01-19 03:14:07'"
    );
    $outOfRange = (int)(($r ? $r->fetch_assoc() : null)['c'] ?? 0);
    if ($outOfRange > 0) {
        _migOrgLicSchema_out(
            "  [WARN] {$outOfRange} CreatedAt value(s) fall outside what TIMESTAMP can hold" .
            ' (1970-01-01..2038-01-19) — leaving the column as DATETIME rather than risk corrupting a value.'
        );
    } else {
        $mysql->query('ALTER TABLE tblOrganisationLicences MODIFY COLUMN CreatedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
        _migOrgLicSchema_out('  [OK] CreatedAt DATETIME -> TIMESTAMP.');
    }
}

_migOrgLicSchema_out('--- UpdatedAt: DATETIME -> TIMESTAMP ---');
$uaType = _migOrgLicSchema_columnDataType($mysql, $tbl, 'UpdatedAt');
if ($uaType === '') {
    _migOrgLicSchema_out('  [WARN] UpdatedAt column not found — skipping.');
} elseif ($uaType === 'timestamp') {
    _migOrgLicSchema_out('  [SKIP] UpdatedAt already TIMESTAMP.');
} else {
    $r = $mysql->query(
        "SELECT COUNT(*) AS c FROM {$tbl}
          WHERE UpdatedAt < '1970-01-01 00:00:01' OR UpdatedAt > '2038-01-19 03:14:07'"
    );
    $outOfRange = (int)(($r ? $r->fetch_assoc() : null)['c'] ?? 0);
    if ($outOfRange > 0) {
        _migOrgLicSchema_out(
            "  [WARN] {$outOfRange} UpdatedAt value(s) fall outside what TIMESTAMP can hold" .
            ' (1970-01-01..2038-01-19) — leaving the column as DATETIME rather than risk corrupting a value.'
        );
    } else {
        $mysql->query('ALTER TABLE tblOrganisationLicences MODIFY COLUMN UpdatedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
        _migOrgLicSchema_out('  [OK] UpdatedAt DATETIME -> TIMESTAMP.');
    }
}

/* ==== Indexes: rename uk_OrgLicence -> uniq_OrgLicence, drop the redundant
   idx_OrganisationId, add idx_IsActive ==== */
_migOrgLicSchema_out('--- Indexes: uk_OrgLicence -> uniq_OrgLicence, drop redundant idx_OrganisationId, add idx_IsActive ---');
_migOrgLicSchema_renameIndex(
    $mysql,
    'tblOrganisationLicences',
    'uk_OrgLicence',
    'uniq_OrgLicence',
    'ALTER TABLE tblOrganisationLicences RENAME INDEX uk_OrgLicence TO uniq_OrgLicence'
);

if (_migOrgLicSchema_idxExists($mysql, $tbl, 'idx_OrganisationId')) {
    /* Safe to drop: uniq_OrgLicence (OrganisationId, LicenceType) has
       OrganisationId as its LEFTMOST column, so it already satisfies
       InnoDB's "an FK column needs a covering index" requirement on its
       own — dropping this one removes a duplicate, it never leaves
       OrganisationId unindexed. */
    $mysql->query('ALTER TABLE tblOrganisationLicences DROP INDEX idx_OrganisationId');
    _migOrgLicSchema_out('  [OK] Dropped redundant index idx_OrganisationId (uniq_OrgLicence already covers OrganisationId).');
} else {
    _migOrgLicSchema_out('  [SKIP] index idx_OrganisationId already absent.');
}

if (_migOrgLicSchema_idxExists($mysql, $tbl, 'idx_IsActive')) {
    _migOrgLicSchema_out('  [SKIP] index idx_IsActive already present.');
} else {
    $mysql->query('ALTER TABLE tblOrganisationLicences ADD INDEX idx_IsActive (IsActive)');
    _migOrgLicSchema_out('  [OK] Added index idx_IsActive.');
}

/* ==== Constraint: fk_OrgLicences_Org -> fk_OrgLicence_Org ==== */
_migOrgLicSchema_out('--- Constraint: fk_OrgLicences_Org -> fk_OrgLicence_Org ---');
_migOrgLicSchema_renameFk(
    $mysql,
    'tblOrganisationLicences',
    'fk_OrgLicences_Org',
    'fk_OrgLicence_Org',
    'ALTER TABLE tblOrganisationLicences DROP FOREIGN KEY fk_OrgLicences_Org, ADD CONSTRAINT fk_OrgLicence_Org
        FOREIGN KEY (OrganisationId) REFERENCES tblOrganisations(Id)
        ON DELETE CASCADE ON UPDATE CASCADE'
);

_migOrgLicSchema_out('Reconcile tblOrganisationLicences schema (#2078) finished.');
/* Don't close $mysql — it's the shared singleton from getDbMysqli(). The
   bulk migration runner in /manage/setup-database.php iterates many
   migrations in one PHP request; closing here would invalidate the handle
   for every subsequent migration that calls getDbMysqli(). PHP closes the
   connection on script exit anyway (see migrate-organisation-licences.php's
   own closing note, copied here). */
