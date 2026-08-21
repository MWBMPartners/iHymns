<?php

/**
 * iHymns — user-data sync deletion guards (#1649)
 *
 * The three per-user sync handlers in api.php (user_setlists_sync,
 * favorites_sync, custom_tags_sync) share one merge/replace contract. All
 * three used to cap the incoming payload with array_slice() and THEN delete
 * every server row absent from that CAPPED list — so a user with 60 set lists
 * sent 50 and permanently lost the other 10 on the next per-edit auto-sync.
 * Silent, immediate, unlogged.
 *
 * includes/user_sync.php is the one shared helper that now owns the "may this
 * replace delete anything?" decision. It is pure and framework-free, so unlike
 * the 17k-line api.php dispatcher it can be require()'d directly here — no DB,
 * no request, no superglobals.
 *
 * SYNC PROTOCOL 2 (#1661) — the owner removed the setlist cap entirely and
 * made deletion EXPLICIT, because "absent from the payload" was still doing
 * the work of "the user deleted it" and those are different facts. This file
 * now locks both protocols at once, which is the point: the legacy inference
 * must keep working UNCHANGED for the native apps (which never send a
 * `deleted` list) while being retired for clients that do.
 *
 * This test has two halves:
 *   1. BEHAVIOURAL — the helper's own contract, including the boundary cases
 *      that decide whether a row lives or dies.
 *   2. STRUCTURAL — source-greps proving each of the three case bodies in
 *      api.php actually routes through the helpers, that no raw
 *      `array_slice($body[...])` survives, that each response carries the
 *      expected metadata, and that the caps are still exactly 500/200 for
 *      favourites/tags and ABSENT for setlists (a silent cap change in either
 *      direction is a data-visibility change, so it must fail the build).
 *
 * ⚠️ EVERY source assertion runs on COMMENT-STRIPPED source. Two guards in
 * this repo have shipped wrong-but-green by matching prose — one satisfied by
 * a doc-comment that merely NAMED the function it was checking for. The
 * handlers below legitimately discuss `userSyncCap()` in comments while no
 * longer calling it, which is exactly that trap.
 *
 *   php tests/php/test-user-sync-guard.php
 *
 * Exit status 0 = all assertions pass, 1 = at least one failure.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/appWeb/public_html/includes/user_sync.php';

$apiFile = dirname(__DIR__, 2) . '/appWeb/public_html/api.php';
if (!is_readable($apiFile)) {
    fwrite(STDERR, "FATAL: could not read $apiFile\n");
    exit(1);
}
$apiSrc = (string)file_get_contents($apiFile);

$failures = 0;
$passed = 0;

function _usgAssert(bool $cond, string $label): void
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

/* ====================================================================== *
 * 1. userSyncCap() — the slice AND the pre-slice truth about it
 * ====================================================================== */

echo "\n-- userSyncCap --\n";

$under = userSyncCap(['a', 'b', 'c'], 5);
_usgAssert($under['items'] === ['a', 'b', 'c'], 'under cap: every item survives');
_usgAssert($under['truncated'] === false, 'under cap: truncated is false');

/* The exact-cap boundary is the one most likely to be got wrong by a
   `>=` slip, and getting it wrong means a complete payload is treated as
   clipped (deletes stop working entirely) — so pin it. */
$atCap = userSyncCap(['a', 'b', 'c'], 3);
_usgAssert($atCap['items'] === ['a', 'b', 'c'], 'exactly at cap: every item survives');
_usgAssert($atCap['truncated'] === false, 'exactly at cap: truncated is FALSE (boundary)');

$overCap = userSyncCap(['a', 'b', 'c', 'd'], 3);
_usgAssert($overCap['items'] === ['a', 'b', 'c'], 'cap+1: list is clipped to the cap');
_usgAssert($overCap['truncated'] === true, 'cap+1: truncated is TRUE (boundary)');
_usgAssert(count($overCap['items']) === 3, 'cap+1: exactly $cap items are kept');

/* Order is load-bearing: "the first 50" must be a stable subset. */
$ordered = userSyncCap(['z', 'y', 'x', 'w'], 2);
_usgAssert($ordered['items'] === ['z', 'y'], 'order is preserved (not sorted / reversed)');

_usgAssert(userSyncCap([], 50) === ['items' => [], 'truncated' => false], 'empty payload: no items, not truncated');

/* ====================================================================== *
 * 2. userSyncParseSince() — accept only what we minted, reject the rest
 * ====================================================================== */

echo "\n-- userSyncParseSince --\n";

_usgAssert(userSyncParseSince('2026-07-29 12:34:56') === '2026-07-29 12:34:56', 'valid space form passes through unchanged');
_usgAssert(userSyncParseSince('2026-07-29T12:34:56') === '2026-07-29 12:34:56', 'valid ISO T form normalises to the space form');

/* Both spellings MUST collapse to one, because ' ' (0x20) sorts below every
   digit while 'T' (0x54) sorts above them — mixing them would invert the
   lexicographic comparison at the date/time boundary. */
_usgAssert(
    userSyncParseSince('2026-07-29T12:34:56') === userSyncParseSince('2026-07-29 12:34:56'),
    'the two spellings of one instant compare identically after parsing'
);

foreach ([
    'null'                    => null,
    'empty string'            => '',
    'garbage'                 => 'garbage',
    'SQL injection attempt'   => '2026-01-01; DROP TABLE tblUserSetlists',
    'fractional seconds'      => '2026-07-29 12:34:56.123456',
    'date only'               => '2026-07-29',
    'trailing timezone'       => '2026-07-29T12:34:56+00:00',
    'two-digit year'          => '26-07-29 12:34:56',
    'an integer'              => 1753800000,
    'an array'                => ['2026-07-29 12:34:56'],
    'a boolean'               => true,
] as $label => $bad) {
    _usgAssert(userSyncParseSince($bad) === null, "rejects $label → null (falls back to legacy behaviour)");
}

/* ====================================================================== *
 * 3. userSyncDeletableIds() — the actual life-or-death decision
 * ====================================================================== */

echo "\n-- userSyncDeletableIds --\n";

/** Three server rows, all comfortably older than the watermark used below. */
$rows = [
    ['id' => 'a', 'ts' => '2026-07-01 00:00:00'],
    ['id' => 'b', 'ts' => '2026-07-02 00:00:00'],
    ['id' => 'c', 'ts' => '2026-07-03 00:00:00'],
];

/* MERGE never deletes — the first-login backfill is purely additive. */
_usgAssert(userSyncDeletableIds($rows, [], 'merge', false, null) === [], 'merge + nothing sent: deletes nothing');
_usgAssert(userSyncDeletableIds($rows, ['a'], 'merge', false, '2026-07-29 00:00:00') === [], 'merge + a watermark: still deletes nothing');
_usgAssert(userSyncDeletableIds($rows, [], 'merge', true, null) === [], 'merge + truncated: deletes nothing');

/* THE #1649 FIX: a truncated replace must delete NOTHING. This is the
   assertion that would have failed before the fix and caught the bug. */
_usgAssert(
    userSyncDeletableIds($rows, ['a'], 'replace', true, null) === [],
    'replace + TRUNCATED: deletes NOTHING (the #1649 fix — a clipped payload is not authoritative)'
);
_usgAssert(
    userSyncDeletableIds($rows, [], 'replace', true, '2026-07-29 00:00:00') === [],
    'replace + truncated + watermark: still deletes nothing (truncation wins outright)'
);

/* LEGACY LOCK: no watermark → exactly today's absence-only deletion. A client
   that never sends `since` (the native apps) must be completely unaffected. */
_usgAssert(
    userSyncDeletableIds($rows, ['a'], 'replace', false, null) === ['b', 'c'],
    'replace + no watermark: deletes every absent id (legacy behaviour preserved)'
);
_usgAssert(
    userSyncDeletableIds($rows, [], 'replace', false, null) === ['a', 'b', 'c'],
    'replace + no watermark + empty payload: deletes all (a genuine "clear all")'
);
_usgAssert(
    userSyncDeletableIds($rows, ['a', 'b', 'c'], 'replace', false, null) === [],
    'replace + full payload: nothing absent, nothing deleted'
);

/* WATERMARK: only rows the client can actually have SEEN are deletable. */
_usgAssert(
    userSyncDeletableIds($rows, [], 'replace', false, '2026-07-03 00:00:00') === ['a', 'b'],
    'replace + watermark: deletes only absent ids OLDER than the watermark'
);

/* THE BOUNDARY — ts == since is KEPT. Second-resolution timestamps make a
   same-second row genuinely ambiguous, and the safe reading of ambiguity is
   "do not destroy". A `<=` slip here silently re-opens the cross-device race. */
_usgAssert(
    !in_array('c', userSyncDeletableIds($rows, [], 'replace', false, '2026-07-03 00:00:00'), true),
    'BOUNDARY: a row whose ts EQUALS the watermark is KEPT, not deleted'
);
_usgAssert(
    userSyncDeletableIds($rows, [], 'replace', false, '2026-07-03 00:00:01') === ['a', 'b', 'c'],
    'BOUNDARY: one second past that ts, the row becomes deletable'
);

/* Everything newer than the watermark is another device's work. */
_usgAssert(
    userSyncDeletableIds($rows, [], 'replace', false, '2026-06-01 00:00:00') === [],
    'replace + watermark older than every row: deletes nothing (all rows unseen)'
);

/* A present id is never deletable, regardless of how old it is. */
_usgAssert(
    userSyncDeletableIds($rows, ['a', 'b', 'c'], 'replace', false, '2026-07-29 00:00:00') === [],
    'ids PRESENT in the payload are never returned, however old'
);
_usgAssert(
    userSyncDeletableIds($rows, ['b'], 'replace', false, '2026-07-29 00:00:00') === ['a', 'c'],
    'only the absent ids are returned when all are older than the watermark'
);

/* Fractional seconds coming back from the driver must not make every row look
   newer than the 19-char watermark (which would silently disable deletion). */
_usgAssert(
    userSyncDeletableIds(
        [['id' => 'a', 'ts' => '2026-07-01 00:00:00.000000']],
        [],
        'replace',
        false,
        '2026-07-29 00:00:00'
    ) === ['a'],
    'a fractional-second row timestamp is truncated to 19 chars before comparing'
);

/* Defensive: malformed rows must not become an empty-string DELETE target. */
_usgAssert(
    userSyncDeletableIds([['id' => '', 'ts' => '2026-07-01 00:00:00']], [], 'replace', false, null) === [],
    'a blank row id is skipped, never returned as a delete target'
);

/* Numeric-looking ids must still match by value (array_flip stringifies). */
_usgAssert(
    userSyncDeletableIds([['id' => '12', 'ts' => '2026-07-01 00:00:00']], [12], 'replace', false, null) === [],
    'an int payload id matches a string row id (no type-juggling miss)'
);

/* ---------------------------------------------------------------------- *
 * 3b. #1661 — $explicitProtocol RETIRES absence-based deletion
 *
 * A protocol-2 client says what it deleted, so silence carries no meaning
 * for it. The pair of assertions that matter are the SAME inputs producing
 * different answers depending only on this flag: that is the whole contract,
 * and it is what keeps the native apps on the legacy path.
 * ---------------------------------------------------------------------- */

echo "\n-- userSyncDeletableIds + \$explicitProtocol (#1661) --\n";

/* Back-compat: the parameter defaults to false, so every pre-#1661 call site
   (favorites_sync, custom_tags_sync) keeps its exact behaviour. */
_usgAssert(
    userSyncDeletableIds($rows, ['a'], 'replace', false, null)
        === userSyncDeletableIds($rows, ['a'], 'replace', false, null, false),
    'the new parameter DEFAULTS to false — omitting it is identical to passing false'
);

_usgAssert(
    userSyncDeletableIds($rows, ['a'], 'replace', false, null, true) === [],
    'protocol 2 + replace + absent ids: deletes NOTHING (absence no longer implies deletion)'
);
_usgAssert(
    userSyncDeletableIds($rows, [], 'replace', false, null, true) === [],
    'protocol 2 + an EMPTY payload: still deletes nothing (no accidental clear-all)'
);
_usgAssert(
    userSyncDeletableIds($rows, [], 'replace', false, '2026-07-29 00:00:00', true) === [],
    'protocol 2 outranks the watermark path too'
);

/* THE CONTRAST — identical inputs, opposite answers. If this pair ever agrees,
   either the flag stopped being consulted or the legacy path was broken. */
_usgAssert(
    userSyncDeletableIds($rows, ['a'], 'replace', false, null, false) === ['b', 'c']
    && userSyncDeletableIds($rows, ['a'], 'replace', false, null, true) === [],
    'LEGACY vs PROTOCOL 2: same inputs, the flag alone decides (native apps keep today\'s behaviour)'
);

/* ====================================================================== *
 * 3c. #1661 — protocol detection: PRESENCE of the key, not a version
 * ====================================================================== */

echo "\n-- userSyncExplicitProtocol --\n";

_usgAssert(
    userSyncExplicitProtocol(['setlists' => [], 'deleted' => []]) === true,
    'THE LOAD-BEARING CASE: `deleted: []` counts as protocol 2 (an empty list is still a statement)'
);
_usgAssert(
    userSyncExplicitProtocol(['setlists' => [], 'deleted' => ['a', 'b']]) === true,
    'a populated `deleted` list counts as protocol 2'
);
_usgAssert(
    userSyncExplicitProtocol(['setlists' => []]) === false,
    'NO `deleted` key at all = legacy client (the native apps)'
);
_usgAssert(
    userSyncExplicitProtocol(['setlists' => [], 'mode' => 'replace', 'since' => '2026-07-29 00:00:00']) === false,
    'the other protocol-1 fields do not accidentally opt a client in'
);

/* A malformed value still counts — failing toward "delete nothing" is the
   #1649-safe direction; reading it as legacy would re-enable absence-based
   deletion for a client that had just proved it is confused. */
foreach ([
    'null'      => null,
    'a string'  => 'yes',
    'an int'    => 1,
    'false'     => false,
    'an object' => ['x' => 1],
] as $label => $weird) {
    _usgAssert(
        userSyncExplicitProtocol(['setlists' => [], 'deleted' => $weird]) === true,
        "a `deleted` key holding $label still marks protocol 2 (fails toward deleting nothing)"
    );
}

_usgAssert(userSyncExplicitProtocol(null) === false, 'a non-array body is not protocol 2');
_usgAssert(userSyncExplicitProtocol('deleted') === false, 'a bare string body is not protocol 2');

/* ====================================================================== *
 * 3d. #1661 — the `deleted` list is sanitised before it can tombstone
 * ====================================================================== */

echo "\n-- userSyncSanitiseId / userSyncSanitiseDeletedIds --\n";

_usgAssert(userSyncSanitiseId('slist-abc_123') === 'slist-abc_123', 'a valid id passes through unchanged');
_usgAssert(userSyncSanitiseId('a b/c') === 'abc', 'disallowed characters are stripped');
_usgAssert(userSyncSanitiseId("x'; DROP TABLE tblUserSetlists; --") === 'xDROPTABLEtblUserSetlists--', 'an injection attempt reduces to inert characters');
_usgAssert(userSyncSanitiseId('!!!') === '', 'an id of only disallowed characters becomes empty');
_usgAssert(userSyncSanitiseId(12) === '12', 'an integer id stringifies');
_usgAssert(userSyncSanitiseId(null) === '', 'null is not an id');
_usgAssert(userSyncSanitiseId(['a']) === '', 'an array is not an id');

_usgAssert(userSyncSanitiseDeletedIds(['a', 'b']) === ['a', 'b'], 'a clean list passes through in order');
_usgAssert(userSyncSanitiseDeletedIds(['a', 'a', 'b']) === ['a', 'b'], 'duplicates collapse (one tombstone per id)');
_usgAssert(userSyncSanitiseDeletedIds(['a', '!!!', 'b']) === ['a', 'b'], 'unusable entries are dropped, not turned into empty ids');
_usgAssert(userSyncSanitiseDeletedIds([]) === [], 'an empty list stays empty');
_usgAssert(userSyncSanitiseDeletedIds(null) === [], 'a non-array `deleted` sanitises to nothing');
_usgAssert(userSyncSanitiseDeletedIds('a,b') === [], 'a string `deleted` sanitises to nothing (never split)');

/* THE LENGTH RULE — dropped, never truncated. Truncating would mint a
   tombstone for a DIFFERENT id, which could kill an innocent set list; the
   column is VARCHAR(100) and MySQL runs STRICT, so an over-length id cannot
   name a real row anyway. */
$long = str_repeat('a', 101);
_usgAssert(
    userSyncSanitiseDeletedIds([$long]) === [],
    'an over-length id is DROPPED (never truncated into a different, innocent id)'
);
_usgAssert(
    userSyncSanitiseDeletedIds([str_repeat('a', 100)]) === [str_repeat('a', 100)],
    'BOUNDARY: exactly the column width is still accepted'
);
_usgAssert(
    userSyncSanitiseDeletedIds([$long, 'keep-me']) === ['keep-me'],
    'one bad id does not discard the rest of the list'
);

/* ====================================================================== *
 * 3e. #1661 — tombstone Reason is an app-level allow-list, not an ENUM
 * ====================================================================== */

echo "\n-- userSyncTombstoneReason --\n";

_usgAssert(userSyncTombstoneReasons() === ['user', 'expired', 'admin'], 'the vocabulary is the documented three');
foreach (userSyncTombstoneReasons() as $reason) {
    _usgAssert(userSyncTombstoneReason($reason) === $reason, "'$reason' is accepted verbatim");
}
_usgAssert(userSyncTombstoneReason('nonsense') === 'user', 'an unknown reason coerces to user (metadata never blocks a delete)');
_usgAssert(userSyncTombstoneReason(null) === 'user', 'null coerces to user');
_usgAssert(userSyncTombstoneReason(['expired']) === 'user', 'an array coerces to user');

/* ====================================================================== *
 * 3f. #1661 — the cap is replaced by a REJECTION, never a truncation
 * ====================================================================== */

echo "\n-- userSyncBodyExceeds --\n";

_usgAssert(userSyncMaxBodyBytes() === 4 * 1024 * 1024, 'the ceiling is 4 MiB');
_usgAssert(userSyncBodyExceeds('', 10) === false, 'an empty body is under any ceiling');
_usgAssert(userSyncBodyExceeds('1234567890', 10) === false, 'BOUNDARY: exactly the ceiling is ACCEPTED');
_usgAssert(userSyncBodyExceeds('12345678901', 10) === true, 'BOUNDARY: one byte over is rejected');
_usgAssert(userSyncBodyExceeds(str_repeat('x', 100)) === false, 'a realistic body is nowhere near the default ceiling');
/* Bytes, not characters: a multi-byte payload must be measured as it is
   transmitted, otherwise the ceiling silently triples for non-Latin content. */
_usgAssert(
    userSyncBodyExceeds('é', 1) === true,
    'measured in BYTES not characters (a 2-byte character exceeds a 1-byte ceiling)'
);

/* ====================================================================== *
 * 3g. #1661 — expiry, decided purely and converging on the tombstone
 * ====================================================================== */

echo "\n-- userSyncExpiredIds --\n";

$now = '2026-07-30 12:00:00';

_usgAssert(userSyncExpiredIds([], $now) === [], 'no rows, nothing expires');
_usgAssert(
    userSyncExpiredIds([['id' => 'a', 'expiresAt' => null]], $now) === [],
    'NULL expiry = never expires (the owner-stated default)'
);
_usgAssert(
    userSyncExpiredIds([['id' => 'a', 'expiresAt' => '2026-07-30 11:59:59']], $now) === ['a'],
    'an expiry one second in the past has expired'
);
_usgAssert(
    userSyncExpiredIds([['id' => 'a', 'expiresAt' => '2026-07-30 12:00:01']], $now) === [],
    'an expiry one second in the future has not'
);

/* BOUNDARY — `<=`, deliberately the OPPOSITE of userSyncDeletableIds()'s `<`.
   Expiry is an instruction ("this ends at 18:00"), not an ambiguity, so at the
   stated instant it HAS ended. A `<` slip here silently postpones every expiry
   by a second, which is invisible; the assertion is the only thing that would
   notice. */
_usgAssert(
    userSyncExpiredIds([['id' => 'a', 'expiresAt' => $now]], $now) === ['a'],
    'BOUNDARY: an expiry EQUAL to now HAS expired (unlike the watermark comparison, which keeps)'
);

_usgAssert(
    userSyncExpiredIds([
        ['id' => 'a', 'expiresAt' => '2026-01-01 00:00:00'],
        ['id' => 'b', 'expiresAt' => null],
        ['id' => 'c', 'expiresAt' => '2027-01-01 00:00:00'],
        ['id' => 'd', 'expiresAt' => '2026-07-30 00:00:00'],
    ], $now) === ['a', 'd'],
    'a mixed set returns only the genuinely expired ids, in order'
);

/* A malformed value must read as "never expires", NOT as "expired now" —
   the wrong way round would delete a set list the user never dated. */
foreach ([
    'garbage'            => 'soon',
    'a date with no time' => '2026-07-30',
    'an empty string'    => '',
    'a number'           => 1753876800,
    'an array'           => ['2026-07-30 00:00:00'],
] as $label => $bad) {
    _usgAssert(
        userSyncExpiredIds([['id' => 'a', 'expiresAt' => $bad]], $now) === [],
        "an unparseable expiry ($label) means NEVER EXPIRES, never 'expired now'"
    );
}

/* The ISO 'T' spelling is normalised by the shared parser, so a client that
   round-trips the value through a JS Date still expires on time rather than
   never (a bare string compare would put 'T' above every digit). */
_usgAssert(
    userSyncExpiredIds([['id' => 'a', 'expiresAt' => '2026-07-30T11:00:00']], $now) === ['a'],
    "the ISO 'T' spelling is normalised before comparing (not silently never-expiring)"
);

_usgAssert(
    userSyncExpiredIds([['id' => '', 'expiresAt' => '2020-01-01 00:00:00']], $now) === [],
    'a blank row id is skipped, never returned as a delete target'
);

/* One parser, two names — a second regex would be the drift #1581 named. */
_usgAssert(
    userSyncParseSince('2026-07-29T12:34:56') === userSyncParseTimestamp('2026-07-29T12:34:56'),
    'userSyncParseSince() and userSyncParseTimestamp() are the same implementation'
);

/* ====================================================================== *
 * 4. STRUCTURAL — api.php actually routes through the helper
 * ====================================================================== */

echo "\n-- api.php wiring --\n";

/**
 * Slice one `case 'X':` body out of the dispatcher, ending at the next case
 * at the same indentation (8 spaces — this file's switch style). Same
 * approach as tests/php/test-auth-response-shape.php.
 */
function _usgCaseBody(string $source, string $caseLabel): ?string
{
    $needle = "case '{$caseLabel}':";
    $start = strpos($source, $needle);
    if ($start === false) {
        return null;
    }
    $bodyStart = $start + strlen($needle);
    $nextCase = strpos($source, "\n        case '", $bodyStart);
    return substr($source, $bodyStart, ($nextCase !== false ? $nextCase : strlen($source)) - $bodyStart);
}

/**
 * Strip PHP block + line comments so a check for a banned CALL isn't tripped
 * by a comment that merely NAMES it. The #1649 handlers legitimately explain
 * what they replaced ("… was the old array_diff() delete pass …"), and a test
 * that forbids discussing the old code would push authors toward deleting the
 * explanation rather than the code. Same technique as
 * tests/php/test-fragment-inline-scripts.php.
 */
function _usgStripComments(string $src): string
{
    $src = preg_replace('!/\*.*?\*/!s', '', $src) ?? $src;
    return preg_replace('!//[^\n]*!', '', $src) ?? $src;
}

_usgAssert(
    str_contains($apiSrc, "'user_sync.php'"),
    'api.php require_once\'s includes/user_sync.php'
);

/* The three handlers and the cap each store is documented to enforce.
   Pinning the number here means a silent cap change fails the build — a cap
   change IS a data-visibility change and must be a deliberate, reviewed edit.
   `cap => null` means the cap has been REMOVED on purpose (#1661, setlists):
   that is asserted just as strictly, so quietly reinstating a slice fails too. */
$handlers = [
    'user_setlists_sync' => ['payloadKey' => 'setlists',  'cap' => null],
    'favorites_sync'     => ['payloadKey' => 'favorites', 'cap' => 500],
    'custom_tags_sync'   => ['payloadKey' => 'tags',      'cap' => 200],
];

foreach ($handlers as $case => $meta) {
    $body = _usgCaseBody($apiSrc, $case);
    _usgAssert($body !== null, "case '{$case}' found in api.php");
    if ($body === null) {
        continue;
    }
    /* ⚠️ EVERY assertion below reads the COMMENT-STRIPPED body. The setlists
       handler explains in prose that userSyncCap() "survives for
       favorites_sync / custom_tags_sync" — an unstripped check for the
       ABSENCE of that call would be satisfied by the sentence describing it,
       which is precisely how a previous guard in this repo shipped green
       while the thing it named was broken. */
    $code = _usgStripComments($body);

    _usgAssert(str_contains($code, 'userSyncDeletableIds('), "case '{$case}' calls userSyncDeletableIds()");
    _usgAssert(str_contains($code, 'userSyncParseSince('), "case '{$case}' parses the `since` watermark");
    _usgAssert(str_contains($code, 'userSyncNow('), "case '{$case}' mints a syncedAt watermark");

    /* The regression itself: a raw slice of the payload, which throws the
       pre-slice count away and makes truncation undetectable downstream. */
    _usgAssert(
        !str_contains($code, "array_slice(\$body['{$meta['payloadKey']}']"),
        "case '{$case}' has NO raw array_slice(\$body['{$meta['payloadKey']}'] …) left"
    );

    if ($meta['cap'] === null) {
        /* #1661 — the cap is gone, and BOTH halves of that must hold: no
           slicing helper is called, and the response says so. A handler that
           dropped the call but kept emitting a number would leave clients
           warning about a limit that no longer exists. */
        _usgAssert(
            !str_contains($code, 'userSyncCap('),
            "case '{$case}' does NOT call userSyncCap() — the cap was removed (#1661)"
        );
        _usgAssert(
            (bool)preg_match("/'cap'\s*=>\s*null/", $code),
            "case '{$case}' emits 'cap' => null (key retained for native decoders, value says 'no limit')"
        );
        _usgAssert(
            (bool)preg_match("/'truncated'\s*=>\s*\\\$truncated/", $code)
            && (bool)preg_match('/\$truncated\s*=\s*false/', $code),
            "case '{$case}' reports truncated as structurally FALSE (nothing is ever sliced)"
        );
        /* The replacement guard must be a REJECTION. Silent truncation as
           data loss is the entire reason #1649/#1661 exist. */
        _usgAssert(
            str_contains($code, 'userSyncBodyExceeds('),
            "case '{$case}' bounds the request with the whole-body byte check"
        );
        _usgAssert(
            (bool)preg_match('/userSyncBodyExceeds\([^\n]*\)[\s\S]{0,400}?\],\s*413\)/', $code),
            "case '{$case}' answers an over-size body with 413 — a REJECTION, never a silent slice"
        );
    } else {
        /* Both halves of the #1649 guard must be present: the cap that REPORTS
           truncation, and the shared decision that ACTS on it. */
        _usgAssert(str_contains($code, 'userSyncCap('), "case '{$case}' calls userSyncCap()");
        /* Matched per LINE (the calls are one-liners) rather than with [^)]*,
           because an argument may itself contain parentheses — e.g.
           userSyncCap(array_keys($localTags), 200). */
        _usgAssert(
            (bool)preg_match('/userSyncCap\([^\n]*,\s*' . $meta['cap'] . '\s*\)/', $code),
            "case '{$case}' still caps at exactly {$meta['cap']}"
        );
        _usgAssert(str_contains($code, "'cap'"), "case '{$case}' response includes 'cap'");
    }

    /* The response must carry the metadata the client needs to supply a
       watermark next time and to reason about truncation. */
    _usgAssert(str_contains($code, "'syncedAt'"), "case '{$case}' response includes 'syncedAt'");
    _usgAssert(str_contains($code, "'truncated'"), "case '{$case}' response includes 'truncated'");

    /* The old hand-rolled diff must be gone — it is what userSyncDeletableIds()
       replaced, and a surviving copy would bypass both guards. */
    _usgAssert(
        !str_contains($code, 'array_diff('),
        "case '{$case}' no longer hand-rolls its delete set with array_diff()"
    );

    /* Deletes stay bound (CLAUDE.md: never interpolate a value into SQL). */
    if (str_contains($code, 'DELETE FROM')) {
        _usgAssert(
            (bool)preg_match('/DELETE FROM \w+ WHERE UserId = \? AND \w+ = \?/', $code),
            "case '{$case}' DELETE still uses bound placeholders"
        );
    }
}

/* The watermark must never reach SQL as a literal — the whole design is that
   it is compared PHP-side, so no handler should be splicing it into a query. */
_usgAssert(
    !preg_match('/(WHERE|AND)[^;\']*\$since/', $apiSrc),
    'the `since` watermark is never interpolated into a SQL string'
);

/* ====================================================================== *
 * 5. STRUCTURAL — #1661 protocol-2 wiring in api.php
 * ====================================================================== */

echo "\n-- api.php protocol-2 wiring (#1661) --\n";

$setlistSync = _usgStripComments((string)_usgCaseBody($apiSrc, 'user_setlists_sync'));
$setlistRead = _usgStripComments((string)_usgCaseBody($apiSrc, 'user_setlists'));

/* Detection, sanitisation, tombstoning, anti-resurrection, expiry, response —
   each is a distinct link and a missing one fails silently rather than
   loudly, so each is asserted separately rather than as one blob. */
foreach ([
    'userSyncExplicitProtocol('  => 'detects a protocol-2 client by the presence of `deleted`',
    'userSyncSanitiseDeletedIds(' => 'sanitises the `deleted` list before it can tombstone anything',
    'userSyncTombstoneWrite('    => 'writes tombstones for explicit deletions',
    'userSyncTombstonedIds('     => 'loads the anti-resurrection set',
    'userSyncExpireSetlists('    => 'converts expired set lists lazily, server-side',
    'userSyncTombstoneList('     => 'returns tombstones so other devices can prune',
    "'tombstones'"               => "response carries 'tombstones'",
    "'expiresAt'"                => "response carries 'expiresAt'",
] as $needle => $what) {
    _usgAssert(str_contains($setlistSync, $needle), "user_setlists_sync $what");
}

/* The flag must actually REACH the helper. Calling userSyncExplicitProtocol()
   and then not passing the result is a perfect silent no-op: every test of the
   helper passes, and absence-based deletion quietly stays on.
   ⚠️ Written as an ARGUMENT-POSITION match, not "appears somewhere near the
   call". The loose version passed mutation M3 — the flag was still present in
   the call's text while no longer being a distinct argument — which is the
   wrong-but-green shape rule #34 names. `$explicitProtocol` must be the
   argument immediately after `$since`. */
_usgAssert(
    (bool)preg_match('/userSyncDeletableIds\([^;]*\$since,\s*\$explicitProtocol\s*\)\s*;/', $setlistSync),
    'user_setlists_sync PASSES $explicitProtocol as the argument after $since (not merely computes it)'
);

/* PROTOCOL 2 MUST BE QUALIFIED BY THE SCHEMA GATE. Migrations are web-run, so
   there is a real window in which this install has protocol-2 clients and no
   tombstone table. Honouring the protocol then would retire absence-based
   deletion while being unable to record the explicit deletes — a user's
   delete would silently come back, which is a fresh instance of the bug this
   work removes. The fallback must be the legacy rule. */
_usgAssert(
    (bool)preg_match('/\$explicitProtocol\s*=\s*\$clientProtocol2\s*&&\s*\$tombstonesReady\s*;/', $setlistSync),
    'protocol 2 is honoured ONLY when the tombstone table exists (un-migrated installs fall back to legacy)'
);
_usgAssert(
    (bool)preg_match('/\$clientProtocol2\s*=\s*userSyncExplicitProtocol\(/', $setlistSync),
    'the client-supported protocol and the effective protocol are separate variables'
);

/* Anti-resurrection must be applied in the upsert loop, not just loaded. */
_usgAssert(
    (bool)preg_match('/isset\(\$tombstoned\[\$setlistId\]\)/', $setlistSync),
    'user_setlists_sync SKIPS a tombstoned id in the upsert loop (anti-resurrection is applied)'
);

/* Anti-resurrection is deliberately deterministic. A timestamp comparison
   against the incoming updatedAt is the plausible-looking wrong answer — the
   client clock is not evidence — so the handler must not grow one. */
_usgAssert(
    !preg_match('/\$tombstone[^\n]*(updatedAt|UpdatedAt)|DeletedAt[^\n]*[<>][^\n]*updatedAt/i', $setlistSync),
    'anti-resurrection compares NO timestamps (a client clock is not evidence)'
);

/* The GET path must expire too, otherwise an expired set list keeps being
   SERVED to any client that only ever reads. */
_usgAssert(
    str_contains($setlistRead, 'userSyncExpireSetlists('),
    'user_setlists (GET) also converts expiries, so an expired set list is never served'
);
_usgAssert(
    str_contains($setlistRead, "'expiresAt'"),
    "user_setlists (GET) returns 'expiresAt' so the client can render the badge"
);

/* ====================================================================== *
 * 6. STRUCTURAL — every new schema object is reached through ONE gated
 *    module, derived from the tree rather than from a list typed here.
 * ====================================================================== */

echo "\n-- #1661 schema gating (tree-derived) --\n";

/**
 * Every first-party PHP file under appWeb/public_html, walked with plain PHP
 * (no shell-out, dot-directories included) so a new file is covered the day
 * it appears rather than when someone remembers to add it to a list.
 */
function _usgPhpFiles(string $root): array
{
    $out = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $f) {
        if ($f->isFile() && strtolower($f->getExtension()) === 'php') {
            $out[] = $f->getPathname();
        }
    }
    sort($out);
    return $out;
}

$publicRoot = dirname(__DIR__, 2) . '/appWeb/public_html';
$phpFiles   = _usgPhpFiles($publicRoot);
_usgAssert(count($phpFiles) > 50, 'the PHP corpus scan found files (a broken walk must not read as "all clean")');

/* mysqli runs STRICT: touching a table that this install has not migrated
   THROWS. Keeping every raw reference inside files that own an existence gate
   is what makes an un-migrated install degrade instead of white-screening
   (the #1228 class).
   COUNT-EXACT + NAME-EXACT, and therefore self-cleaning: a third file
   referencing the table fails here and must justify itself by being added
   with a reason, and a file that stops referencing it fails too. */
$tombstoneAllowed = [
    /* The ONE module that reads/writes the table, behind userSyncTombstonesReady(). */
    'includes/user_sync.php',
    /* The migration card's pending-probe. Safe by construction: it asks
       INFORMATION_SCHEMA whether the table exists — that IS the gate. */
    'manage/includes/migration-registry.php',
];
$tombstoneRefFiles = [];
foreach ($phpFiles as $file) {
    if (str_contains(_usgStripComments((string)file_get_contents($file)), 'tblUserSetlistTombstones')) {
        $tombstoneRefFiles[] = substr($file, strlen($publicRoot) + 1);
    }
}
sort($tombstoneRefFiles);
sort($tombstoneAllowed);
_usgAssert(
    $tombstoneRefFiles === $tombstoneAllowed,
    'tblUserSetlistTombstones is referenced ONLY by the gated module + the migration probe; found: '
        . (empty($tombstoneRefFiles) ? '(none)' : implode(', ', $tombstoneRefFiles))
);

/* Same rule for the optional COLUMN — but scoping it needs care, and the
   obvious version is wrong twice over:
   `ExpiresAt` is a common column name across a dozen unrelated tables
   (tblApiTokens, tblLiveFollowSessions, tblOrganisationLicences, …), so a bare
   name scan flags two dozen correct lines. FILE-level co-occurrence with
   'tblUserSetlists' is no better — manage/index.php counts setlists on one
   line and API tokens on the next, and matches while touching neither.
   So the match is PROXIMITY within one statement-ish window, which is what
   "this reference is about that table" actually means. A guard that fails on
   correct code gets weakened or deleted rather than fixed (rule #34). */
$expiryAllowed = [
    'api.php',                                /* the two setlist handlers, gate-branched */
    'includes/user_sync.php',                 /* the gated module */
    'manage/includes/migration-registry.php', /* _migProbe_columnExists — INFORMATION_SCHEMA, i.e. the gate */
];
$expiryRefFiles = [];
foreach ($phpFiles as $file) {
    $src = _usgStripComments((string)file_get_contents($file));
    if (preg_match('/tblUserSetlists[^;]{0,400}ExpiresAt|ExpiresAt[^;]{0,400}tblUserSetlists/', $src)) {
        $expiryRefFiles[] = substr($file, strlen($publicRoot) + 1);
    }
}
sort($expiryRefFiles);
sort($expiryAllowed);
_usgAssert(
    $expiryRefFiles === $expiryAllowed,
    'tblUserSetlists.ExpiresAt is touched only by api.php, the gated module and the migration probe; found: '
        . (empty($expiryRefFiles) ? '(none)' : implode(', ', $expiryRefFiles))
);

/* …and in api.php each of the THREE places it appears is gated. Named
   individually because a missing gate on any one of them throws under STRICT
   on an un-migrated install, and the other two passing would hide it. */
_usgAssert(
    (bool)preg_match('/\$expiryReady\s*=\s*userSyncExpiryReady\(/', $apiNoComments = _usgStripComments($apiSrc)),
    'api.php derives $expiryReady from the schema probe, never from a constant'
);
_usgAssert(
    (bool)preg_match("/\\\$expiresSelect\s*=\s*(userSyncExpiryReady\([^\n]*\)|\\\$expiryReady)\s*\?\s*', ExpiresAt'/", $apiNoComments),
    'the SELECT column list adds ExpiresAt only under the gate (and from a hardcoded constant, rule #5)'
);
_usgAssert(
    (bool)preg_match('/if\s*\(\$expiryReady\)\s*\{[\s\S]{0,800}?INSERT INTO tblUserSetlists[^;]*ExpiresAt/', $apiNoComments),
    'the ExpiresAt-writing upsert is inside an if ($expiryReady) branch, with a non-expiry fallback'
);

/* Both gates must exist and both must probe INFORMATION_SCHEMA — a gate that
   was "simplified" into a constant would defeat the whole arrangement.
   ⚠️ The probe must also FILTER BY NAME. Checking only that the query mentions
   INFORMATION_SCHEMA passed mutation M10, where the WHERE clause had been
   reduced to `1=1` — a gate that always answers "yes" while still naming the
   right catalogue table. Asserting the predicate is what makes this real. */
$syncSrc = _usgStripComments(
    (string)file_get_contents($publicRoot . '/includes/user_sync.php')
);
foreach ([
    'userSyncTombstonesReady' => ['TABLES',  "TABLE_NAME = 'tblUserSetlistTombstones'"],
    'userSyncExpiryReady'     => ['COLUMNS', "COLUMN_NAME = 'ExpiresAt'"],
] as $fn => [$schemaTable, $predicate]) {
    $ok = (bool)preg_match(
        '/function ' . $fn . '\([\s\S]{0,900}?INFORMATION_SCHEMA\.' . $schemaTable
            . '[\s\S]{0,400}?' . preg_quote($predicate, '/') . '/',
        $syncSrc
    );
    _usgAssert(
        $ok,
        "$fn() probes INFORMATION_SCHEMA.$schemaTable AND filters on $predicate (a gate that always says yes is not a gate)"
    );
}
/* Both gates must also filter by DATABASE() — without it a probe can be
   satisfied by a same-named object in another schema on the shared server. */
_usgAssert(
    substr_count($syncSrc, 'TABLE_SCHEMA = DATABASE()') >= 2,
    'both schema gates scope their probe to the current DATABASE()'
);

/* ====================================================================== *
 * 7. STRUCTURAL — the migration, its schema.sql mirror and its registry
 *    entry are a matched set (rule #19). test-schema-coverage.php checks
 *    column coverage; these check the parts it cannot see.
 * ====================================================================== */

echo "\n-- #1661 migration / schema.sql / registry --\n";

$sqlDir      = dirname(__DIR__, 2) . '/appWeb/.sql';
$migFile     = $sqlDir . '/migrate-setlist-tombstones.php';
$schemaSql   = (string)file_get_contents($sqlDir . '/schema.sql');
$registrySrc = (string)file_get_contents($publicRoot . '/manage/includes/migration-registry.php');

_usgAssert(is_readable($migFile), 'migrate-setlist-tombstones.php exists');
$migSrc = is_readable($migFile) ? (string)file_get_contents($migFile) : '';

_usgAssert(
    str_contains($registrySrc, "'migrate-setlist-tombstones.php'"),
    'the migration is registered in migration-registry.php'
);

/* Multi-object migration → OR-probe, so a partial apply never shows green.
   With a single-object probe the "Apply all pending" counter would read zero
   while half the objects were missing, which is unfixable from the UI. */
_usgAssert(
    (bool)preg_match(
        "/'setlist-tombstones'[\s\S]{0,3000}?'probe'[\s\S]{0,600}?tblUserSetlistTombstones[\s\S]{0,300}?\|\|[\s\S]{0,300}?'ExpiresAt'/",
        $registrySrc
    ),
    'the registry probe is a multi-object OR-probe covering BOTH the table and the column'
);

/* Byte-identical mirror (rule #19): a fresh install must land the same
   database as a migrated one, COMMENT text included. Compared line by line on
   the column declarations, which is where drift actually happens. */
foreach ([
    "SetlistId  VARCHAR(100)  NOT NULL COMMENT 'The client-generated id of the deleted setlist',",
    "DeletedAt  DATETIME      NOT NULL COMMENT 'DB clock (userSyncNow frame) at deletion',",
    "Reason     VARCHAR(20)   NOT NULL DEFAULT 'user' COMMENT 'user | expired | admin — VARCHAR not ENUM (rule #20)',",
    'PRIMARY KEY (UserId, SetlistId),',
    'CONSTRAINT fk_SetlistTomb_User',
] as $decl) {
    _usgAssert(
        str_contains($migSrc, $decl) && str_contains($schemaSql, $decl),
        'migration and schema.sql agree byte-for-byte on: ' . substr($decl, 0, 44) . '…'
    );
}
$expiresDecl = "ExpiresAt DATETIME NULL DEFAULT NULL COMMENT 'Optional expiry (#1661), UTC; NULL = never expires. DATETIME not TIMESTAMP (rule #20 TTL convention)'";
_usgAssert(
    str_contains($migSrc, $expiresDecl) && str_contains($schemaSql, $expiresDecl),
    'migration and schema.sql agree byte-for-byte on the ExpiresAt column declaration'
);

/* Rule #20 — a growable vocabulary is VARCHAR, never ENUM. An ENUM value-add
   is an ALTER, i.e. the second migration the rule exists to forbid. */
_usgAssert(
    !preg_match('/Reason\s+ENUM/i', $migSrc) && !preg_match('/Reason\s+ENUM/i', $schemaSql),
    'Reason is VARCHAR, not ENUM (rule #20 — a growable vocabulary must not need an ALTER)'
);
/* Rule #20 — TTL columns are DATETIME, not TIMESTAMP (2038, and silent
   session-time-zone conversion of a value compared against an explicit clock). */
_usgAssert(
    !preg_match('/(DeletedAt|ExpiresAt)\s+TIMESTAMP/i', $migSrc),
    'DeletedAt/ExpiresAt are DATETIME, not TIMESTAMP (rule #20 TTL convention)'
);

/* Both objects need a doctag or test-schema-coverage.php only sees the first
   ADD COLUMN per ALTER and silently under-reports (rule #19). */
_usgAssert(
    str_contains($migSrc, '@migration-adds tblUserSetlistTombstones.')
    && str_contains($migSrc, '@migration-adds tblUserSetlists.ExpiresAt'),
    'the migration carries a @migration-adds doctag for BOTH objects'
);

/* ====================================================================== *
 * #1675 — the per-row conflict predicate + no-op skip (CALLED, truth table)
 *
 * These two pure helpers ARE the conflict-safe upsert. Being framework-free
 * they are exercised directly here rather than grepped — a truth table over
 * every boundary that decides whether an overwrite is refused or a write is
 * skipped. The plan-fold path needs the setlist template encoder.
 * ====================================================================== */

echo "\n-- #1675 userSyncRowNewerThanWatermark (the conflict predicate) --\n";

/* Normal shape: the watermark is minted just before the final read, so it
   trails the DB's own NOW() slightly. */
$W   = '2026-08-21 12:00:00';
$NOW = '2026-08-21 12:00:05';

_usgAssert(userSyncRowNewerThanWatermark(null, $W, $NOW) === false,
    'a client with NO watermark (native) never conflicts — the guard is inert (byte-identical path)');
_usgAssert(userSyncRowNewerThanWatermark($W, '2026-08-21 11:59:59', $NOW) === false,
    'a row OLDER than the watermark is overwritable');
_usgAssert(userSyncRowNewerThanWatermark($W, '2026-08-21 12:00:00', $NOW) === false,
    'a row stamped the SAME second as the watermark is overwritable (strict >, no self-conflict loop)');
_usgAssert(userSyncRowNewerThanWatermark($W, '2026-08-21 12:00:03', $NOW) === true,
    'a row NEWER than the watermark (within the clamp) conflicts');
_usgAssert(userSyncRowNewerThanWatermark($W, '2026-08-21 12:10:00', $NOW) === false,
    'a row far in the FUTURE (past the 300s clamp) is frame-poisoned → allowed (degrade to LWW, never a refusal loop)');
_usgAssert(
    userSyncRowNewerThanWatermark($W, '2026-08-21 12:05:05', $NOW) === true
    && userSyncRowNewerThanWatermark($W, '2026-08-21 12:05:06', $NOW) === false,
    'the future-stamp clamp boundary is exactly dbNow + 300s (inclusive)'
);

echo "\n-- #1675 userSyncSetlistRowUnchanged (the no-op skip) --\n";

require_once dirname(__DIR__, 2) . '/appWeb/public_html/includes/setlist_templates.php';

$SRV = [
    'Name'      => 'My List',
    'SongsJson' => '[{"id":"MP-1"}]',
    'ExpiresAt' => null,
    'SlotsJson' => null,
    'UpdatedAt' => '2026-08-21 12:00:00',
];

_usgAssert(userSyncSetlistRowUnchanged($SRV, 'My List', '[{"id":"MP-1"}]', false, null, false, null) === true,
    'identical name+songs (no expiry, no plan) → unchanged (skip)');
_usgAssert(userSyncSetlistRowUnchanged($SRV, 'Renamed', '[{"id":"MP-1"}]', false, null, false, null) === false,
    'a changed name alone → changed');
_usgAssert(userSyncSetlistRowUnchanged($SRV, 'My List', '[{"id":"MP-2"}]', false, null, false, null) === false,
    'changed songs bytes → changed');
_usgAssert(userSyncSetlistRowUnchanged($SRV, 'My List', '[{"id":"MP-1"}]', true, '2026-12-25 00:00:00', false, null) === false,
    'expiryReady + a new expiry against stored NULL → changed');
_usgAssert(userSyncSetlistRowUnchanged($SRV, 'My List', '[{"id":"MP-1"}]', true, null, false, null) === true,
    'expiryReady + matching NULL expiry → unchanged');
_usgAssert(
    userSyncSetlistRowUnchanged(
        ['Name' => 'My List', 'SongsJson' => '[{"id":"MP-1"}]', 'ExpiresAt' => '2026-12-25 00:00:00', 'SlotsJson' => null],
        'My List', '[{"id":"MP-1"}]', false, null, false, null
    ) === true,
    'an un-gated ExpiresAt column is IGNORED when expiryReady is false (the write would not touch it)'
);

/* Plan is compared through the canonical encode∘decode fold, NEVER bytes — a
   byte compare of the JSON column would read every planned list as "changed"
   on every push and manufacture a permanent spurious-conflict loop. */
$plan          = setlistTemplateSanitisePlan([['label' => 'Opening', 'type' => 'song']]);
$canonicalPlan = setlistTemplateEncodePlan($plan);
_usgAssert(
    userSyncSetlistRowUnchanged(
        ['Name' => 'My List', 'SongsJson' => '[{"id":"MP-1"}]', 'ExpiresAt' => null, 'SlotsJson' => ' ' . $canonicalPlan],
        'My List', '[{"id":"MP-1"}]', false, null, true, $canonicalPlan
    ) === true,
    'a plan that DECODES equal but has different bytes (leading space) is unchanged — the fold is canonical, not a byte compare'
);
_usgAssert(
    userSyncSetlistRowUnchanged(
        ['Name' => 'My List', 'SongsJson' => '[{"id":"MP-1"}]', 'ExpiresAt' => null, 'SlotsJson' => $canonicalPlan],
        'My List', '[{"id":"MP-1"}]', false, null, false, null
    ) === true,
    'an absent plan key (planProvided=false) is IGNORED even when the row already stores a plan'
);

echo "\n-- #1675 the conflict branch is wired into the sync handler --\n";

/* $setlistSync is the comment-stripped user_setlists_sync body computed above. */
_usgAssert(str_contains($setlistSync, "'newer_on_server'"),
    'the sync handler emits the newer_on_server conflict reason');
_usgAssert(str_contains($setlistSync, 'userSyncRowNewerThanWatermark('),
    'the conflict decision is the shared predicate, not a re-inlined timestamp compare');
_usgAssert(str_contains($setlistSync, 'userSyncSetlistRowUnchanged('),
    'the no-op skip is the shared predicate');
_usgAssert(str_contains($setlistSync, "'conflicts' => \$conflicts") || str_contains($setlistSync, "'conflicts'  => \$conflicts"),
    "the response carries the 'conflicts' array (always present, [] when none)");
/* BOTH the skip and the conflict branch must record the id as present, or the
   deletion pass would treat a skipped/conflicted row as a deletion. Count the
   `$payloadIds[] = $setlistId;` appends: one in the loop tail (fall-through) +
   one in the skip branch + one in the conflict branch = at least 3. */
_usgAssert(
    substr_count($setlistSync, '$payloadIds[] = $setlistId;') >= 3,
    'both the no-op-skip and conflict branches append $payloadIds (a skipped/conflicted row is NOT counted as deleted)'
);
/* The guard is watermark-gated so a native (no since) takes the identical old
   path — byte-identical proof obligation (§4). */
_usgAssert(
    (bool)preg_match('/if \(\$since !== null && \$srv !== null\)/', $setlistSync),
    'the whole conflict branch is gated on $since !== null (a native, no watermark, skips it entirely)'
);

echo "\n$passed passed, $failures failed.\n";
exit($failures > 0 ? 1 : 0);
