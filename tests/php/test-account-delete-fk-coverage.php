<?php
/**
 * iHymns — account_delete cascade completeness guard (#1403)
 *
 * account_delete's entire per-user cleanup relies on ONE property holding
 * for EVERY foreign key that references tblUsers(Id): it must be
 * `ON DELETE CASCADE` (erase with the account) or `ON DELETE SET NULL`
 * (anonymise, keep the row) — NEVER the default RESTRICT/NO ACTION, which
 * would make `DELETE FROM tblUsers WHERE Id = ?` throw a
 * \mysqli_sql_exception mid-transaction instead of completing the delete.
 * A future migration that adds a new tblUsers-referencing FK without an
 * explicit ON DELETE clause would silently defeat account_delete on exactly
 * that table — this test catches it at CI time, before it ever reaches a
 * user trying to exercise their App-Review-mandated right to delete.
 *
 * Also asserts:
 *   - tblUserAuthProviders' FK to tblUsers is specifically CASCADE (its
 *     RefreshToken must never survive the account it belongs to).
 *   - api.php's `case 'account_delete'` body names the two PII-scrub tables
 *     the FK graph does NOT clean (tblSongRequests, tblLoginAttempts) — a
 *     drift alarm: if a future column keyed by UserId needs the same
 *     treatment, this test's own table list (below) needs a matching
 *     update, and the comment here is the pointer future FK-adders should
 *     find.
 *
 * Pure source-tree scan — no DB — so it slots into the CI lint step
 * alongside test-schema-coverage.php.
 *
 *   php tests/php/test-account-delete-fk-coverage.php
 *
 * Exit status 0 = clean, 1 = at least one violation.
 */

declare(strict_types=1);

$schemaFile = dirname(__DIR__, 2) . '/appWeb/.sql/schema.sql';
$apiFile    = dirname(__DIR__, 2) . '/appWeb/public_html/api.php';

foreach ([$schemaFile, $apiFile] as $f) {
    if (!is_readable($f)) {
        fwrite(STDERR, "FATAL: could not read $f\n");
        exit(1);
    }
}

$schemaSql = (string)file_get_contents($schemaFile);
$apiSrc    = (string)file_get_contents($apiFile);

$failures = 0;
$passed = 0;

function _tafcAssert(bool $cond, string $label): void
{
    global $failures, $passed;
    if ($cond) {
        $passed++;
        echo "PASS: $label\n";
    } else {
        $failures++;
        fwrite(STDERR, "FAIL: $label\n");
    }
}

/* ---------------------------------------------------------------------- *
 * 1. Every FK referencing tblUsers(Id) is CASCADE or SET NULL.
 *
 * Matches across newlines (constraints in this schema conventionally put
 * `FOREIGN KEY (...) REFERENCES tblUsers(Id)` on one line and the
 * `ON DELETE ...` clause on the next), so `s` flag + a bounded lookahead
 * that stops at the next comma/close-paren that ends the constraint clause.
 * ---------------------------------------------------------------------- */
preg_match_all(
    '/CONSTRAINT\s+(\w+)\s+FOREIGN KEY\s*\(([^)]*)\)\s*REFERENCES\s+tblUsers\(Id\)\s*(ON DELETE\s+\w+(?:\s+\w+)?)?/is',
    $schemaSql,
    $fkMatches,
    PREG_SET_ORDER
);

_tafcAssert(count($fkMatches) > 0, 'schema.sql contains at least one FK referencing tblUsers(Id)');

$badFks = [];
$tblUserAuthProvidersFkFound = false;
foreach ($fkMatches as $fk) {
    $constraintName = $fk[1];
    $onDelete = strtoupper(trim($fk[3] ?? ''));
    $isSafe = str_starts_with($onDelete, 'ON DELETE CASCADE') || str_starts_with($onDelete, 'ON DELETE SET NULL');
    if (!$isSafe) {
        $badFks[] = "$constraintName ($onDelete)";
    }
    if ($constraintName === 'fk_AuthProviders_User') {
        $tblUserAuthProvidersFkFound = true;
        _tafcAssert(str_starts_with($onDelete, 'ON DELETE CASCADE'), 'tblUserAuthProviders.fk_AuthProviders_User is specifically ON DELETE CASCADE');
    }
}

if ($badFks) {
    $failures++;
    fwrite(STDERR, "FAIL: " . count($badFks) . " FK(s) referencing tblUsers(Id) are NEITHER CASCADE NOR SET NULL:\n");
    foreach ($badFks as $b) {
        fwrite(STDERR, "  - $b\n");
    }
    fwrite(STDERR, "  account_delete's DELETE FROM tblUsers would THROW mid-transaction on one of these.\n");
} else {
    $passed++;
    echo "PASS: all " . count($fkMatches) . " FK(s) referencing tblUsers(Id) are ON DELETE CASCADE or SET NULL (never RESTRICT/NO ACTION)\n";
}

_tafcAssert($tblUserAuthProvidersFkFound, 'tblUserAuthProviders.fk_AuthProviders_User constraint found in schema.sql');

/* ---------------------------------------------------------------------- *
 * 2. api.php's account_delete block names the two PII-scrub tables the FK
 *    graph does NOT clean (§3.2). If this drifts (a table renamed, or the
 *    scrub statements moved/removed), it's a signal the cascade-completeness
 *    story has a gap — re-verify against .claude/apple-backend-auth-plan.md
 *    §3.2 before updating either this list or the handler.
 * ---------------------------------------------------------------------- */
$start = strpos($apiSrc, "case 'account_delete':");
_tafcAssert($start !== false, "case 'account_delete' found in api.php");
if ($start !== false) {
    $nextCase = strpos($apiSrc, "\n        case '", $start + 1);
    $body = substr($apiSrc, $start, ($nextCase !== false ? $nextCase : strlen($apiSrc)) - $start);

    $piiScrubTables = ['tblSongRequests', 'tblLoginAttempts'];
    foreach ($piiScrubTables as $tbl) {
        _tafcAssert(str_contains($body, $tbl), "case 'account_delete' references the PII-scrub table $tbl");
    }
    _tafcAssert(str_contains($body, 'ContactEmail'), "case 'account_delete' scrubs tblSongRequests.ContactEmail");
    _tafcAssert(str_contains($body, 'FOR UPDATE'), "case 'account_delete' takes a FOR UPDATE lock (idempotency/race-safety, §3.3)");
    _tafcAssert(str_contains($body, 'begin_transaction'), "case 'account_delete' runs the destructive work in a transaction");
}

echo "\n$passed passed, $failures failed.\n";
exit($failures > 0 ? 1 : 0);
