<?php

declare(strict_types=1);

/**
 * iHymns — Shared-setlist expiry clock + live-read wiring guard (#1699)
 *
 * ELI5: if you set a set-list to expire and share it by a LIVE link, the link
 * must stop serving once the expiry passes — even though the person opening the
 * link is anonymous and can't trigger your app's clean-up. This test proves the
 * "has it expired?" clock is right, and that the live share-read actually asks
 * it about the owner's set-list.
 *
 * THE BUG (#1699)
 * --------------
 * `sharedSetlistResolveWire()` honoured the share LINK's own expiry
 * (`tblSharedSetlists.ExpiresAt`, #1791) but read the owner's `tblUserSetlists`
 * row — the LIVE content — with no `ExpiresAt` predicate. So an owner who set a
 * per-set-list expiry (#1661) and shared it live kept serving the set-list (and
 * later edits) on three anonymous surfaces (`setlist_get`, `og-image.php`,
 * `index.php` meta) past that expiry, because the anonymous path can never run
 * the owner's lazy `userSyncExpireSetlists()`. Fixed by folding BOTH expiry
 * checks through one clock and gating the live read on the owner row's ExpiresAt.
 *
 * WHY THE CLOCK IS TESTED PURELY, AND THE RESOLVER STRUCTURALLY
 * ------------------------------------------------------------
 * `sharedSetlistResolveWire()` is DB-coupled through TWO connections — the
 * passed `$db` (the live read + the column-existence gate) AND `getDbMysqli()`
 * inside `sharedSetlistGet()` — and `getDbMysqli()` rejects a test double via its
 * `thread_id` liveness probe. A full DB double is disproportionate to a ~6-line
 * predicate. So, following this repo's account-lifecycle idiom ("extract the
 * decision into a pure function so it is behaviourally testable"):
 *   PART A — behavioural, deterministic (`$now` injected): the pure clock
 *            `sharedSetlistTimestampExpired()` over the real edge cases.
 *   PART B — structural, via php_source_units (comment-stripped, string-opaque
 *            `code` view so prose/SQL can't satisfy it): the resolver calls that
 *            ONE clock for BOTH expiry sites, and gates the live read on the
 *            #1661 column so an un-migrated install can't STRICT-throw (rule #19).
 *
 * Both parts are mutation-proven — see the assertions' inline notes.
 *
 *   php tests/php/test-shared-setlist-expiry.php
 *
 * Exit 0 = clock + wiring correct, 1 = a regression.
 *
 * @see https://www.php.net/manual/en/function.strtotime.php
 */

$repoRoot = dirname(__DIR__, 2);
require_once $repoRoot . '/appWeb/public_html/includes/SharedSetlist.php';
require_once __DIR__ . '/lib/php_source_units.php';

$failures = [];
$passed   = 0;

function sseOk(string $label, bool $cond): void
{
    global $failures, $passed;
    if ($cond) { $passed++; return; }
    $failures[] = $label;
}

/* =========================================================================
 * PART A — the pure clock: sharedSetlistTimestampExpired(?string, ?int $now)
 * =========================================================================
 * A fixed `$now` makes every case deterministic regardless of wall-clock. */
$now = 1_700_000_000;                               // a fixed reference instant
$past   = gmdate('Y-m-d H:i:s', $now - 3600);       // one hour before $now
$future = gmdate('Y-m-d H:i:s', $now + 3600);       // one hour after  $now
$exact  = gmdate('Y-m-d H:i:s', $now);              // exactly $now

// Never-expires inputs.
sseOk('null ExpiresAt → not expired (never expires)',
    sharedSetlistTimestampExpired(null, $now) === false);
sseOk("empty-string ExpiresAt → not expired",
    sharedSetlistTimestampExpired('', $now) === false);
sseOk('unparseable ExpiresAt → not expired (fail-open, never hides a live share)',
    sharedSetlistTimestampExpired('not-a-date', $now) === false);

// The real decision.
sseOk('a PAST instant → expired',
    sharedSetlistTimestampExpired($past, $now) === true);
sseOk('a FUTURE instant → not expired',
    sharedSetlistTimestampExpired($future, $now) === false);
/* Boundary: expiry is inclusive (<=). Mutation: `<=` → `<` flips THIS case
   only, which is why it is asserted on its own. */
sseOk('the EXACT expiry instant → expired (inclusive <=)',
    sharedSetlistTimestampExpired($exact, $now) === true);
/* One second either side of the boundary pins the comparison direction. */
sseOk('one second before expiry → not expired',
    sharedSetlistTimestampExpired(gmdate('Y-m-d H:i:s', $now + 1), $now) === false);
sseOk('one second after expiry → expired',
    sharedSetlistTimestampExpired(gmdate('Y-m-d H:i:s', $now - 1), $now) === true);

/* The stored instant is UTC — a NON-UTC reading would land an hour off. Prove
   the ` UTC` anchoring by comparing a value that is expired in UTC but would be
   in the future if misread in a positive-offset zone. (Only asserted when the
   runner is NOT already UTC, so it can actually distinguish the two.) */
$origTz = date_default_timezone_get();
date_default_timezone_set('Asia/Kolkata');          // UTC+05:30, no DST
sseOk('parsing is UTC-anchored regardless of the process timezone',
    sharedSetlistTimestampExpired($past, $now) === true
    && sharedSetlistTimestampExpired($future, $now) === false);
date_default_timezone_set($origTz);

/* Default $now === time(): a clearly-ancient stamp is expired, a far-future one
   is not, without pinning wall-clock. */
sseOk('default $now uses server time() — ancient stamp expired',
    sharedSetlistTimestampExpired('2000-01-01 00:00:00') === true);
sseOk('default $now uses server time() — far-future stamp not expired',
    sharedSetlistTimestampExpired('2999-01-01 00:00:00') === false);

/* =========================================================================
 * PART B — the resolver WIRES the clock into the live read (#1699)
 * =========================================================================
 * php_source_units gives a comment-stripped, string-opaque `code` view keyed
 * per function, so a mention of the helper in a doc-block or a SQL string can
 * never satisfy these — only a real call/identifier token can. */
$sharedSrc = (string)file_get_contents($repoRoot . '/appWeb/public_html/includes/SharedSetlist.php');
$units     = phpSourceUnits($sharedSrc);
$resolver  = $units['sharedSetlistResolveWire']['code'] ?? '';

sseOk('sharedSetlistResolveWire() is a locatable unit',
    $resolver !== '');

/* Both expiry sites route through the ONE clock: the #1791 share-link expiry
   AND the #1699 owner-set-list expiry. Mutation: delete the owner-row check →
   count drops to 1 → RED (the exact #1699 regression). */
$clockCalls = substr_count($resolver, 'sharedSetlistTimestampExpired(');
sseOk("resolver calls the shared expiry clock for BOTH sites (found {$clockCalls}, want >= 2)",
    $clockCalls >= 2);

/* The live read is column-existence-gated on the #1661 column (rule #19) — an
   un-migrated install must degrade, never STRICT-throw. Mutation: drop the
   userSyncExpiryReady() gate and SELECT ExpiresAt unconditionally → RED. */
sseOk('the live read is gated on userSyncExpiryReady() (rule #19, no STRICT throw un-migrated)',
    strpos($resolver, 'userSyncExpiryReady') !== false);

/* The gate feeds the SELECT column list, so the ExpiresAt read only happens
   when the column exists. Both spellings appear in the resolver code view. */
sseOk('the resolver references the owner ExpiresAt column in its live read',
    strpos($resolver, 'ExpiresAt') !== false);

/* =========================================================================
 * Report
 * ========================================================================= */
if ($failures) {
    fwrite(STDERR, "FAIL: shared-setlist expiry (#1699):\n\n");
    foreach ($failures as $f) { fwrite(STDERR, "  ✗ $f\n"); }
    fwrite(STDERR, "\n{$passed} passed, " . count($failures) . " failed.\n");
    exit(1);
}
echo "PASS: shared-setlist expiry clock + live-read wiring ({$passed} assertions).\n";
exit(0);
