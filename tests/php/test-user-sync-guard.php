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
 * This test has two halves:
 *   1. BEHAVIOURAL — the helper's own contract, including the boundary cases
 *      that decide whether a row lives or dies.
 *   2. STRUCTURAL — source-greps proving each of the three case bodies in
 *      api.php actually routes through the helper, that no raw
 *      `array_slice($body[...])` survives, that each response carries the new
 *      metadata, and that the caps are still exactly 50/500/200 (a silent cap
 *      change is itself a data-loss event, so it must fail the build).
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

/* The three handlers, each with the cap the store is documented to enforce.
   Pinning the number here means a silent cap change fails the build — a cap
   change IS a data-visibility change and must be a deliberate, reviewed edit. */
$handlers = [
    'user_setlists_sync' => ['payloadKey' => 'setlists',  'cap' => 50],
    'favorites_sync'     => ['payloadKey' => 'favorites', 'cap' => 500],
    'custom_tags_sync'   => ['payloadKey' => 'tags',      'cap' => 200],
];

foreach ($handlers as $case => $meta) {
    $body = _usgCaseBody($apiSrc, $case);
    _usgAssert($body !== null, "case '{$case}' found in api.php");
    if ($body === null) {
        continue;
    }

    /* Both halves of the guard must be present: the cap that REPORTS
       truncation, and the shared decision that ACTS on it. One without the
       other is the bug. */
    _usgAssert(str_contains($body, 'userSyncCap('), "case '{$case}' calls userSyncCap()");
    _usgAssert(str_contains($body, 'userSyncDeletableIds('), "case '{$case}' calls userSyncDeletableIds()");
    _usgAssert(str_contains($body, 'userSyncParseSince('), "case '{$case}' parses the `since` watermark");
    _usgAssert(str_contains($body, 'userSyncNow('), "case '{$case}' mints a syncedAt watermark");

    /* The regression itself: a raw slice of the payload, which throws the
       pre-slice count away and makes truncation undetectable downstream. */
    _usgAssert(
        !str_contains($body, "array_slice(\$body['{$meta['payloadKey']}']"),
        "case '{$case}' has NO raw array_slice(\$body['{$meta['payloadKey']}'] …) left"
    );

    /* The cap is still the documented number. Matched per LINE (the calls are
       one-liners) rather than with [^)]*, because an argument may itself
       contain parentheses — e.g. userSyncCap(array_keys($localTags), 200). */
    _usgAssert(
        (bool)preg_match('/userSyncCap\([^\n]*,\s*' . $meta['cap'] . '\s*\)/', $body),
        "case '{$case}' still caps at exactly {$meta['cap']}"
    );

    /* The response must carry the metadata the client needs to supply a
       watermark next time and to warn about truncation. */
    _usgAssert(str_contains($body, "'syncedAt'"), "case '{$case}' response includes 'syncedAt'");
    _usgAssert(str_contains($body, "'truncated'"), "case '{$case}' response includes 'truncated'");
    _usgAssert(str_contains($body, "'cap'"), "case '{$case}' response includes 'cap'");

    /* The old hand-rolled diff must be gone — it is what userSyncDeletableIds()
       replaced, and a surviving copy would bypass both guards. Comments are
       stripped first so the handlers can still explain what they replaced. */
    _usgAssert(
        !str_contains(_usgStripComments($body), 'array_diff('),
        "case '{$case}' no longer hand-rolls its delete set with array_diff()"
    );

    /* Deletes stay bound (CLAUDE.md: never interpolate a value into SQL). */
    if (str_contains($body, 'DELETE FROM')) {
        _usgAssert(
            (bool)preg_match('/DELETE FROM \w+ WHERE UserId = \? AND \w+ = \?/', $body),
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

echo "\n$passed passed, $failures failed.\n";
exit($failures > 0 ? 1 : 0);
