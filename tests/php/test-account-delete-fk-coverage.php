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
 * ⚠ WHAT #1698 CHANGED, AND WHY ASSERTION 1 IS *MORE* LOAD-BEARING NOW.
 * `account_delete` no longer runs `DELETE FROM tblUsers` at all — the account
 * becomes an anonymised TOMBSTONE, and the per-table work is derived from these
 * very declarations by `accountEraseActionFor()`
 * (includes/account_lifecycle.php). So the CASCADE-or-SET-NULL property is no
 * longer "what stops MySQL throwing"; it is the INPUT to the erasure. A RESTRICT
 * FK now makes `accountErase()` refuse outright rather than throw mid-statement,
 * which is better behaviour and exactly the same requirement. The FKs are
 * deliberately left as declared (no FK DDL in #1698) so a raw hard delete still
 * cascades correctly if a legal demand ever requires the row itself to go.
 *
 * Also asserts:
 *   - tblUserAuthProviders' FK to tblUsers is specifically CASCADE (its
 *     RefreshToken must never survive the account it belongs to).
 *   - the PII scrubs the FK graph does NOT reach (tblSongRequests.ContactEmail /
 *     IpAddress, and tblLoginAttempts, which is keyed by username STRING with no
 *     FK at all) are performed by `includes/account_lifecycle.php`. They MOVED
 *     there in #1698, out of the api.php case body, because three funnels need
 *     them and three copies of an irreversible operation is the worst case the
 *     modularity rule exists for. This is a drift alarm: if a future column keyed
 *     by UserId needs the same treatment, that file and this list both need it.
 *   - all THREE erasure funnels reach `accountErase()`. A source scan is the
 *     honest tool for this one and only this one — "every call site delegates"
 *     is a property of the call GRAPH, which has no runtime handle without a
 *     database. Everything with a runtime handle is asserted by CALLING it, in
 *     tests/php/test-account-lifecycle.php.
 *
 * Pure source-tree scan — no DB — so it slots into the CI lint step
 * alongside test-schema-coverage.php.
 *
 *   php tests/php/test-account-delete-fk-coverage.php
 *
 * Exit status 0 = clean, 1 = at least one violation.
 */

declare(strict_types=1);

$schemaFile    = dirname(__DIR__, 2) . '/appWeb/.sql/schema.sql';
$apiFile       = dirname(__DIR__, 2) . '/appWeb/public_html/api.php';
$lifecycleFile = dirname(__DIR__, 2) . '/appWeb/public_html/includes/account_lifecycle.php';
$authFile      = dirname(__DIR__, 2) . '/appWeb/public_html/manage/includes/auth.php';

foreach ([$schemaFile, $apiFile, $lifecycleFile, $authFile] as $f) {
    if (!is_readable($f)) {
        fwrite(STDERR, "FATAL: could not read $f\n");
        exit(1);
    }
}

$schemaSql     = (string)file_get_contents($schemaFile);
$apiSrc        = (string)file_get_contents($apiFile);
$lifecycleSrc  = (string)file_get_contents($lifecycleFile);
$authSrc       = (string)file_get_contents($authFile);

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
 * 2. The PII scrubs the FK graph does NOT reach (§3.2) live in the ONE
 *    erasure core. They were in api.php's case body until #1698 moved them.
 *    If this drifts (a table renamed, or the scrub statements removed), it is
 *    a signal the cascade-completeness story has a gap — re-verify against
 *    .claude/apple-backend-auth-plan.md §3.2 before updating either this list
 *    or account_lifecycle.php.
 * ---------------------------------------------------------------------- */
$piiScrubTables = ['tblSongRequests', 'tblLoginAttempts'];
foreach ($piiScrubTables as $tbl) {
    _tafcAssert(
        str_contains($lifecycleSrc, $tbl),
        "account_lifecycle.php scrubs the FK-unreachable PII table $tbl"
    );
}
_tafcAssert(str_contains($lifecycleSrc, 'ContactEmail'), 'account_lifecycle.php scrubs tblSongRequests.ContactEmail');
_tafcAssert(str_contains($lifecycleSrc, 'IpAddress'), 'account_lifecycle.php scrubs tblSongRequests.IpAddress');

/* The self-service endpoint keeps its own surrounding guarantees — they are
   NOT part of the erasure core and must not quietly migrate into it. */
$start = strpos($apiSrc, "case 'account_delete':");
_tafcAssert($start !== false, "case 'account_delete' found in api.php");
if ($start !== false) {
    $nextCase = strpos($apiSrc, "\n        case '", $start + 1);
    $body = substr($apiSrc, $start, ($nextCase !== false ? $nextCase : strlen($apiSrc)) - $start);

    _tafcAssert(str_contains($body, 'FOR UPDATE'), "case 'account_delete' takes a FOR UPDATE lock (idempotency/race-safety, §3.3)");
    _tafcAssert(str_contains($body, 'begin_transaction'), "case 'account_delete' runs the destructive work in a transaction");
    /* The re-auth rung and the last-global-admin guard are the two things whose
       removal would be catastrophic and invisible: the endpoint would still
       "work". */
    _tafcAssert(str_contains($body, '_accountDeleteReauth'), "case 'account_delete' still re-authenticates the caller");
    _tafcAssert(str_contains($body, 'global_admin'), "case 'account_delete' still guards the last global admin");
}

/* ---------------------------------------------------------------------- *
 * 3. REACH — all three erasure funnels go through the ONE core (#1698).
 *
 *    Source-scanned deliberately, and this is the ONLY assertion in either
 *    account test for which source inspection is the right tool: "every call
 *    site delegates" is a property of the call graph, and there is no runtime
 *    handle for it without a live database to erase an account in. Every
 *    property that DOES have a runtime handle is CALLED in
 *    tests/php/test-account-lifecycle.php, because four consecutive rounds on
 *    this branch shipped a guard that was green while the thing it guarded was
 *    broken, each time from exactly that substitution.
 *
 *    LIMIT, so the tick is not over-read: this proves the funnels NAME the
 *    core, not that they reach it on every path.
 * ---------------------------------------------------------------------- */
_tafcAssert(
    str_contains($apiSrc, 'accountErase($db, $authUserId)'),
    "funnel 1/3: the self-service account_delete endpoint delegates to accountErase()"
);
_tafcAssert(
    str_contains($authSrc, 'accountErase($db, $userId)'),
    'funnel 2/3: deleteUser() delegates to accountErase()'
);
/* Funnel 3 is admin_user_delete, which calls deleteUser() rather than the core
   directly — asserted as reaching funnel 2, since a copy of the erasure inline
   there is precisely what this checks for. */
$adminStart = strpos($apiSrc, "case 'admin_user_delete'");
_tafcAssert($adminStart !== false, "case 'admin_user_delete' found in api.php");
if ($adminStart !== false) {
    $adminEnd  = strpos($apiSrc, "\n        case '", $adminStart + 1);
    $adminBody = substr($apiSrc, $adminStart, ($adminEnd !== false ? $adminEnd : strlen($apiSrc)) - $adminStart);
    _tafcAssert(
        str_contains($adminBody, 'deleteUser($targetId)'),
        'funnel 3/3: admin_user_delete delegates to deleteUser() (and thence to accountErase())'
    );
    _tafcAssert(
        !preg_match('/DELETE\s+FROM\s+`?tblUsers`?\b/i', $adminBody),
        'admin_user_delete does not hard-delete the user row itself'
    );
}

/* NOTHING may hard-delete a tblUsers row any more. This is the assertion that
   would catch a well-meaning "fix" adding the old behaviour back as a fallback
   when the migration has not run — the exact thing account_lifecycle.php's
   header refuses to do, because pressing a button labelled "anonymise" and
   getting a purge is unrecoverable. */
foreach ([
    'api.php'              => $apiSrc,
    'manage/includes/auth.php' => $authSrc,
    'includes/account_lifecycle.php' => $lifecycleSrc,
] as $label => $src) {
    /* Comments are stripped first — this repo's own explanatory prose names the
       old statement repeatedly, and prose satisfying (or defeating) a guard is
       the trap it keeps re-shipping. */
    $stripped = '';
    foreach (token_get_all($src) as $tok) {
        if (is_array($tok)) {
            if ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT) { continue; }
            $stripped .= $tok[1];
            continue;
        }
        $stripped .= $tok;
    }
    /* `\b` IS LOAD-BEARING, and its absence was a real defect this guard shipped
       with for about ninety seconds. Without it, `/i` made `tblUsers` match
       inside `tblUserSetlists` — whose lower-cased spelling literally begins
       `tblusers` — so `DELETE FROM tblUserSetlists`, a perfectly correct
       statement in user_setlists_sync, failed the assertion. A guard that fails
       on correct code is the OTHER half of rule #34: it gets weakened or deleted
       rather than fixed. */
    _tafcAssert(
        !preg_match('/DELETE\s+FROM\s+`?tblUsers`?\b/i', $stripped),
        "$label contains no DELETE FROM tblUsers (erasure is a tombstone, never a row removal)"
    );
}

echo "\n$passed passed, $failures failed.\n";
exit($failures > 0 ? 1 : 0);
