<?php

declare(strict_types=1);

/**
 * privacy_legacy_blank.php - the ONE description of "old personal links to blank"
 * ==============================================================================
 * Account/profile rewrite step B2 (epic #2180). Used by BOTH the manual
 * migration (appWeb/.sql/migrate-privacy-blank-legacy-links.php) and its
 * dashboard probe in migration-registry.php, so "what counts as still pending"
 * and "what the run actually changes" can never drift apart (rule #22/#35).
 *
 * ELI5
 * ----
 * Before step A4, every search a signed-in person made was saved next to their
 * account number. And the audit log of an account that has since been erased
 * still holds that person's internet address, browser, country and sometimes
 * their username. This file knows how to find those leftovers, count them, and
 * blank them in small batches. It changes nothing by itself: the migration
 * script calls it, and only when the owner confirms.
 *
 * Detailed
 * --------
 *  - Searches: `tblSearchQueries.UserId` is set to NULL. The search TEXT stays
 *    (it is used for aggregate "top searches" and is not tied to anyone once the
 *    link is gone).
 *  - Audit log: only rows whose account is already erased (`tblUsers.Status =
 *    'deleted'`, the tombstone the erasure leaves behind). For those rows the
 *    address, browser, referrer, country, proxy details are blanked and
 *    `username` / `email` are removed from `Details`. The rows themselves stay
 *    (the audit trail of WHAT happened is kept; WHO is not).
 *  - Every optional column is checked against the live schema first, so an
 *    install that lacks one (it came from a later migration) is skipped rather
 *    than throwing under MYSQLI_REPORT_STRICT (rule #19).
 *  - Batches are capped (PRIVACY_LEGACY_BATCH) so a huge table never holds one
 *    long lock. The SQL is built from fixed column names in this file; the only
 *    values (batch limit) are bound.
 *  - IDEMPOTENT: once a row is blanked it no longer matches, so a re-run finds
 *    nothing.
 *
 * DESTRUCTIVE, so it is a MANUAL card: excluded from "Apply all", dry-run by
 * default, and `confirm=1` is required to change a single row.
 *
 * @see appWeb/.sql/migrate-privacy-blank-legacy-links.php
 * @see tests/php/test-privacy-blank-legacy-links.php
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

/** Rows changed per statement. */
const PRIVACY_LEGACY_BATCH = 5000;

/** Optional tblActivityLog columns that carry personal details, and the value that blanks each. */
const PRIVACY_LEGACY_AUDIT_BLANKS = [
    'IpAddress'    => "''",     /* NOT NULL, so blank text rather than NULL */
    'UserAgent'    => "''",     /* NOT NULL */
    'Referrer'     => 'NULL',
    'Country'      => 'NULL',
    'IpProxyChain' => 'NULL',   /* holds forwarded client addresses */
    'ProxyVpnDetail' => 'NULL', /* provider/score detail derived from the address */
];

/**
 * PURE: build the audit-log SET list and WHERE condition for the columns that
 * exist on this install. Columns not in `$existing` are left out.
 *
 * @param string[] $existing names of tblActivityLog columns that exist
 * @return array{set:string, where:string}
 */
function privacyLegacyAuditSql(array $existing): array
{
    $set = [];
    $needs = [];
    foreach (PRIVACY_LEGACY_AUDIT_BLANKS as $col => $blank) {
        if (!in_array($col, $existing, true)) { continue; }
        $set[] = "{$col} = {$blank}";
        /* "still holds something": NOT NULL text columns hold non-empty text; nullable ones hold non-NULL. */
        $needs[] = $blank === 'NULL' ? "{$col} IS NOT NULL" : "{$col} <> ''";
    }
    $hasDetails = in_array('Details', $existing, true);
    if ($hasDetails) {
        $set[] = "Details = JSON_REMOVE(Details, '\$.username', '\$.email')";
        $needs[] = "JSON_CONTAINS_PATH(Details, 'one', '\$.username', '\$.email')";
    }
    /* Only the account is erased when its tombstone says so. */
    $where = "UserId IN (SELECT Id FROM tblUsers WHERE Status = 'deleted')"
           . ($needs ? ' AND (' . implode(' OR ', $needs) . ')' : ' AND 0');
    return ['set' => implode(', ', $set), 'where' => $where];
}

/** Does a table exist? (INFORMATION_SCHEMA, bound.) */
function _privacyLegacyTableExists(\mysqli $db, string $table): bool
{
    $st = $db->prepare('SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
    $st->bind_param('s', $table);
    $st->execute();
    $exists = $st->get_result()->fetch_row() !== null;
    $st->close();
    return $exists;
}

/** The names of the table's columns that exist, restricted to a fixed list. @param string[] $wanted @return string[] */
function _privacyLegacyColumns(\mysqli $db, string $table, array $wanted): array
{
    $st = $db->prepare('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $st->bind_param('s', $table);
    $st->execute();
    $have = array_column($st->get_result()->fetch_all(MYSQLI_NUM), 0);
    $st->close();
    return array_values(array_intersect($wanted, $have));
}

/** Do the pieces for the search-log step exist on this install? */
function privacyLegacySearchReady(\mysqli $db): bool
{
    return _privacyLegacyTableExists($db, 'tblSearchQueries')
        && in_array('UserId', _privacyLegacyColumns($db, 'tblSearchQueries', ['UserId']), true);
}

/** Do the pieces for the audit-log step exist on this install? */
function privacyLegacyAuditReady(\mysqli $db): bool
{
    return _privacyLegacyTableExists($db, 'tblActivityLog')
        && _privacyLegacyTableExists($db, 'tblUsers')
        && in_array('Status', _privacyLegacyColumns($db, 'tblUsers', ['Status']), true)
        && in_array('UserId', _privacyLegacyColumns($db, 'tblActivityLog', ['UserId']), true);
}

/** The audit-log SET/WHERE for THIS install's real columns. @return array{set:string, where:string} */
function privacyLegacyAuditSqlForDb(\mysqli $db): array
{
    $wanted = array_merge(array_keys(PRIVACY_LEGACY_AUDIT_BLANKS), ['Details']);
    return privacyLegacyAuditSql(_privacyLegacyColumns($db, 'tblActivityLog', $wanted));
}

/** How many search rows still name a person. */
function privacyLegacySearchPendingCount(\mysqli $db): int
{
    if (!privacyLegacySearchReady($db)) { return 0; }
    $r = $db->query('SELECT COUNT(*) FROM tblSearchQueries WHERE UserId IS NOT NULL');
    $n = (int)($r->fetch_row()[0] ?? 0);
    $r->close();
    return $n;
}

/** How many audit rows of erased accounts still hold personal details. */
function privacyLegacyAuditPendingCount(\mysqli $db): int
{
    if (!privacyLegacyAuditReady($db)) { return 0; }
    $sql = privacyLegacyAuditSqlForDb($db);
    $r = $db->query('SELECT COUNT(*) FROM tblActivityLog WHERE ' . $sql['where']);   /* fixed column names, no input */
    $n = (int)($r->fetch_row()[0] ?? 0);
    $r->close();
    return $n;
}

/** The dashboard probe: is anything still waiting to be blanked? */
function privacyLegacyPending(\mysqli $db): bool
{
    try {
        return privacyLegacySearchPendingCount($db) > 0 || privacyLegacyAuditPendingCount($db) > 0;
    } catch (\Throwable $_e) {
        return false;   /* un-migrated install: not pending, not a STRICT throw */
    }
}

/** Unlink ONE batch of searches from people. @return int rows changed */
function privacyLegacyBlankSearchBatch(\mysqli $db, int $limit = PRIVACY_LEGACY_BATCH): int
{
    $st = $db->prepare('UPDATE tblSearchQueries SET UserId = NULL WHERE UserId IS NOT NULL LIMIT ?');
    $st->bind_param('i', $limit);
    $st->execute();
    $n = $st->affected_rows;
    $st->close();
    return max(0, (int)$n);
}

/** Blank ONE batch of erased accounts' audit rows. @return int rows changed */
function privacyLegacyBlankAuditBatch(\mysqli $db, int $limit = PRIVACY_LEGACY_BATCH): int
{
    $sql = privacyLegacyAuditSqlForDb($db);
    if ($sql['set'] === '') { return 0; }
    /* SET and WHERE come from the fixed list above; only the batch size is bound. */
    $st = $db->prepare('UPDATE tblActivityLog SET ' . $sql['set'] . ' WHERE ' . $sql['where'] . ' LIMIT ?');
    $st->bind_param('i', $limit);
    $st->execute();
    $n = $st->affected_rows;
    $st->close();
    return max(0, (int)$n);
}
