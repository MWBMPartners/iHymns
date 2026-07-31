<?php

declare(strict_types=1);

/**
 * iHymns — the namespaced user-settings store BEHAVES correctly (#1671 F5)
 * ========================================================================
 *
 * ELI5
 * ----
 * One box of settings per person, now with shelves. Saving to a shelf must
 * leave every other shelf exactly as it was, and saving a shelf without one of
 * its keys must actually delete that key. This file puts things on shelves and
 * checks what is on them afterwards.
 *
 * WHY THIS FILE READS NO SOURCE
 * -----------------------------
 * Four consecutive rounds on this branch ended with a guard green while the
 * thing it guarded was broken, and the root cause was always the same:
 *
 *   > source inspection used as the primary evidence for a property that has a
 *   > runtime handle.
 *
 * `test-transaction-fatal.php` is the worked example — a predicate was guarded
 * by asserting its body *mentioned* `1213`, and a reviewer replaced the body
 * with `return false;` while leaving the vocabulary in a dead array. The whole
 * suite stayed green while every call site resumed swallowing deadlocks.
 *
 * Namespace isolation, root-not-deep merge, key deletion, the legacy
 * whole-blob path and the cap REFUSALS are all behaviours. They live in pure
 * functions in `includes/user_settings.php`, so this file CALLS them and
 * asserts the values that come back. A function cannot pass here by containing
 * the right words, only by answering correctly. No database is needed: nothing
 * under test touches one.
 *
 * WHAT IT DELIBERATELY DOES NOT COVER
 * -----------------------------------
 * The HTTP wiring in `api.php` — which status a reason code maps to, that the
 * legacy branch is still reached when `namespace` is absent. Those have no pure
 * handle without a live server, and asserting them by pattern-matching api.php
 * would be exactly the mistake above. Stated here so this file's green tick is
 * not read as covering the endpoint.
 *
 *   php tests/php/test-user-settings-namespace.php
 *
 * Exit status 0 = all pass, 1 = a failure.
 *
 * @see appWeb/public_html/includes/user_settings.php
 * @see appWeb/public_html/api.php  case 'user_settings'
 * @see .claude/remediation-plan-2026-07-30.md §4.5
 */

require_once dirname(__DIR__, 2) . '/appWeb/public_html/includes/user_settings.php';

$fail = 0;

function ok(string $label, bool $cond, string $detail = ''): void
{
    global $fail;
    echo ($cond ? "  PASS  " : "  FAIL  ") . $label . "\n";
    if (!$cond) {
        $fail++;
        if ($detail !== '') { echo "        $detail\n"; }
    }
}

/** Round-trip a merge result through the store's own encoder, as the handler does. */
function enc($v): string
{
    return userSettingsEncode($v);
}

/* =========================================================================
 * 1 — NAMESPACE GRAMMAR
 *
 * The name is the isolation key. Two names that differ only by case, or by an
 * invisible trailing newline, would be two DIFFERENT shelves that look
 * identical in every log and JSON dump — so the grammar is asserted, not
 * assumed.
 * ========================================================================= */
echo "\n1. userSettingsNamespaceValid()\n";

foreach (['ihymns', 'ilyricsdb', 'apple', 'ihymns.web', 'a1', 'a_b', 'x9.y9',
          str_repeat('a', 32), str_repeat('a', 32) . '.' . str_repeat('b', 32)] as $good) {
    ok("accepts '" . (strlen($good) > 20 ? strlen($good) . ' chars' : $good) . "'",
       userSettingsNamespaceValid($good) === true);
}

foreach ([
    ''            => 'empty',
    'a'           => 'single character (min segment is 2)',
    'A'           => 'upper case',
    'Apple'       => 'leading upper case',
    'ihymns.Web'  => 'upper case in the second segment',
    '1abc'        => 'leading digit',
    '_abc'        => 'leading underscore',
    'a.b.c'       => 'two dots',
    'a-b'         => 'hyphen',
    'a b'         => 'space',
    'ihymns.'     => 'trailing dot',
    '.ihymns'     => 'leading dot',
    'ihymns web'  => 'inner space',
    'ihymns/web'  => 'slash',
] as $bad => $why) {
    ok("rejects " . $why, userSettingsNamespaceValid($bad) === false, "value: " . json_encode($bad));
}

/* The `$`-vs-`\z` trap, called out on its own because it is the one that would
   silently create a duplicate shelf rather than erroring. PHP's `$` matches
   before a trailing newline; `\z` does not. */
ok("rejects a trailing newline (\$ vs \\z)",
   userSettingsNamespaceValid("ilyricsdb\n") === false,
   'a trailing-newline name would be stored as a SECOND, visually identical namespace');
ok('rejects an embedded newline',
   userSettingsNamespaceValid("ily\nricsdb") === false);
ok('rejects a 33-character segment',
   userSettingsNamespaceValid(str_repeat('a', 33)) === false);

/* =========================================================================
 * 2 — THE PROPERTY THE WHOLE FEATURE EXISTS FOR: SIBLING ISOLATION
 *
 * iLyricsDB writes its subtree with zero coordination against iHymns' keys.
 * If this fails, the feature is worthless and the old whole-blob replace was
 * no worse.
 * ========================================================================= */
echo "\n2. Namespace isolation\n";

$blob = [
    'ihymns.web' => ['theme' => 'dark', 'fontSize' => 18],
    'ilyricsdb'  => ['sortOrder' => 'title'],
    'apple'      => ['haptics' => true],
];

$after = userSettingsMergeNamespace($blob, 'ilyricsdb', ['sortOrder' => 'artist', 'grid' => 3]);

ok('the written namespace holds exactly what was written',
   $after['ilyricsdb'] === ['sortOrder' => 'artist', 'grid' => 3],
   enc($after['ilyricsdb']));
ok('sibling namespace ihymns.web is byte-identical afterwards',
   enc($after['ihymns.web']) === enc($blob['ihymns.web']));
ok('sibling namespace apple is byte-identical afterwards',
   enc($after['apple']) === enc($blob['apple']));
ok('no namespace is created or destroyed',
   array_keys($after) === ['ihymns.web', 'ilyricsdb', 'apple'],
   enc(array_keys($after)));

/* Writing a namespace that does not exist yet ADDS it and disturbs nothing —
   the first-run case for a brand-new product. */
$added = userSettingsMergeNamespace($blob, 'newproduct', ['x' => 1]);
ok('an unknown namespace is created',
   $added['newproduct'] === ['x' => 1]);
ok('creating one leaves all three existing namespaces untouched',
   enc($added['ihymns.web']) === enc($blob['ihymns.web'])
   && enc($added['ilyricsdb']) === enc($blob['ilyricsdb'])
   && enc($added['apple']) === enc($blob['apple']));

/* Flat legacy keys at the top level (what settings.js writes today) survive a
   namespaced write — otherwise the first namespaced client would wipe the web
   app's settings on its first save. */
$mixed = ['theme' => 'dark', 'fontSize' => 18, 'ilyricsdb' => ['a' => 1]];
$mixedAfter = userSettingsMergeNamespace($mixed, 'ilyricsdb', ['a' => 2]);
ok('legacy top-level keys survive a namespaced write',
   $mixedAfter['theme'] === 'dark' && $mixedAfter['fontSize'] === 18);

/* =========================================================================
 * 3 — ROOT MERGE, NOT DEEP MERGE  (the reason deletion works at all)
 *
 * A deep recursive merge reads well until you try to DELETE a key: the client
 * sends the subtree without it, the merge sees "absent", keeps the old value,
 * and the key becomes permanently un-deletable with no error anywhere. These
 * assertions fail immediately against any recursive implementation.
 * ========================================================================= */
echo "\n3. Root-level replace (deletion is possible)\n";

$before = ['ns' => ['keep' => 1, 'drop' => 2, 'nested' => ['a' => 1, 'b' => 2]]];

$dropped = userSettingsMergeNamespace($before, 'ns', ['keep' => 1]);
ok('a key omitted from the payload is DELETED',
   !array_key_exists('drop', $dropped['ns']),
   'deep-merge implementations keep it: ' . enc($dropped['ns']));
ok('the subtree equals the payload exactly',
   $dropped['ns'] === ['keep' => 1], enc($dropped['ns']));

$nested = userSettingsMergeNamespace($before, 'ns', ['nested' => ['a' => 9]]);
ok('nested structures are REPLACED, not merged key-by-key',
   $nested['ns'] === ['nested' => ['a' => 9]],
   'a deep merge would leave nested.b in place: ' . enc($nested['ns']));

/* An empty payload clears the namespace, and must round-trip as `{}` — not
   `[]`. PHP cannot tell an empty map from an empty list, and json_encode([])
   emits an ARRAY, so a client that cleared its namespace would read back a
   different type than it wrote. */
$cleared = userSettingsMergeNamespace($before, 'ns', []);
ok('an empty payload clears the namespace',
   enc($cleared) === '{"ns":{}}', enc($cleared));
ok('the cleared namespace encodes as {} and not []',
   str_contains(enc($cleared), '"ns":{}') && !str_contains(enc($cleared), '"ns":[]'));

/* =========================================================================
 * 4 — READING A SUBTREE BACK
 * ========================================================================= */
echo "\n4. userSettingsSubtree()\n";

ok('returns the stored subtree',
   enc(userSettingsSubtree($blob, 'ilyricsdb')) === enc(['sortOrder' => 'title']));
ok('an absent namespace reads as {} (not an error, not null)',
   enc(userSettingsSubtree($blob, 'nothere')) === '{}');
ok('an empty stored subtree reads as {} and not []',
   enc(userSettingsSubtree(['ns' => []], 'ns')) === '{}');
ok('round-trip: what you POST is what you GET',
   enc(userSettingsSubtree(
       userSettingsMergeNamespace($blob, 'ilyricsdb', ['a' => 1, 'b' => [2, 3]]),
       'ilyricsdb'
   )) === enc(['a' => 1, 'b' => [2, 3]]));

/* =========================================================================
 * 5 — CAPS ARE REFUSALS, NEVER TRUNCATIONS
 *
 * #1649 / #1662: the set-list cap sliced the payload and then treated the
 * sliced version as the truth, destroying users' data silently. The rule
 * established the hard way is that an over-size write is REJECTED and the
 * stored value is left alone.
 * ========================================================================= */
echo "\n5. Cap decisions (userSettingsRejectReason)\n";

ok('a normal write is accepted (null reason)',
   userSettingsRejectReason($blob, 'ilyricsdb', ['a' => 1]) === null);

ok('a malformed namespace is refused with invalid_namespace',
   userSettingsRejectReason($blob, 'Bad Name', ['a' => 1]) === 'invalid_namespace');

/* Just under / just over the per-namespace cap. Sized against the store's own
   encoder so the boundary is the real one, not an estimate. */
$fits = ['v' => str_repeat('x', USER_SETTINGS_NS_MAX_BYTES - 20)];
ok('a payload just under the per-namespace cap is accepted',
   strlen(userSettingsEncode($fits)) <= USER_SETTINGS_NS_MAX_BYTES
   && userSettingsRejectReason([], 'ns', $fits) === null,
   'encoded ' . strlen(userSettingsEncode($fits)) . ' bytes');

$over = ['v' => str_repeat('x', USER_SETTINGS_NS_MAX_BYTES + 1)];
ok('a payload over the per-namespace cap is refused',
   userSettingsRejectReason([], 'ns', $over) === 'namespace_too_large',
   'encoded ' . strlen(userSettingsEncode($over)) . ' bytes');

/* The refusal must not mutate anything — a rejected write leaves the previous
   value intact. Asserted by checking the input array is unchanged (PHP arrays
   are value types, but this pins the contract against a future by-reference
   "optimisation"). */
$guarded = $blob;
userSettingsRejectReason($guarded, 'ilyricsdb', $over);
ok('a refused write leaves the caller\'s blob untouched',
   enc($guarded) === enc($blob));

/* The total backstop. 17 near-full namespaces put the blob just over the
   ceiling, and — this is the point of the sizing — emptying ONE of them puts
   it back under. A first draft of this used 20 namespaces, which is over the
   ceiling by so much that no single-namespace shrink can recover it; the
   "shrinking write" assertion below then failed for a reason that had nothing
   to do with the property it was testing. Worth recording: an assertion that
   fails because its FIXTURE is wrong is indistinguishable, at a glance, from
   one that fails because the code is. */
$bigBlob = [];
$chunk   = ['v' => str_repeat('x', 15900)];
for ($i = 0; $i < 17; $i++) {
    $bigBlob['ns' . $i] = $chunk;
}
$bigLen = strlen(userSettingsEncode($bigBlob));
ok('the constructed blob really is over the total backstop',
   $bigLen > USER_SETTINGS_TOTAL_MAX_BYTES,
   $bigLen . ' bytes vs ' . USER_SETTINGS_TOTAL_MAX_BYTES);
ok('emptying one namespace really would bring it back under (fixture sanity)',
   strlen(userSettingsEncode(userSettingsMergeNamespace($bigBlob, 'ns0', ['a' => 1])))
       < USER_SETTINGS_TOTAL_MAX_BYTES);
ok('a write that would exceed the total backstop is refused',
   userSettingsRejectReason($bigBlob, 'onemore', ['a' => 1]) === 'total_too_large');

/* ...and the cap must not TRAP an account above it: a write that SHRINKS an
   oversized namespace has to be allowed, or the account can never recover. */
ok('a shrinking write is accepted even when the current blob is oversized',
   userSettingsRejectReason($bigBlob, 'ns0', ['a' => 1]) === null,
   'a cap measured against the INPUT rather than the RESULT would trap this account forever');

/* Reason codes are the contract, not the prose (rule #35 / #1677). Assert the
   exact set a caller may branch on. */
ok('reason codes are exactly the three documented values',
   in_array(userSettingsRejectReason($blob, 'Bad Name', []), ['invalid_namespace'], true)
   && in_array(userSettingsRejectReason([], 'ns', $over), ['namespace_too_large'], true)
   && in_array(userSettingsRejectReason($bigBlob, 'onemore', ['a' => 1]), ['total_too_large'], true));

/* =========================================================================
 * 6 — ENCODER AGREEMENT
 *
 * The bytes a cap is measured against must be the bytes that get stored. If
 * the encoder and the measurement ever disagree, a cap check passes and the
 * write then fails (or vice versa), which is unfixable from the outside.
 * ========================================================================= */
echo "\n6. userSettingsEncode()\n";

ok('slashes are not escaped',
   userSettingsEncode(['u' => 'https://ihymns.app/x']) === '{"u":"https://ihymns.app/x"}',
   userSettingsEncode(['u' => 'https://ihymns.app/x']));
ok('unicode is not escaped (so byte length is the real stored length)',
   userSettingsEncode(['t' => 'Kärnten']) === '{"t":"Kärnten"}',
   userSettingsEncode(['t' => 'Kärnten']));
ok('multi-byte characters are measured in BYTES, matching what MySQL stores',
   strlen(userSettingsEncode(['t' => 'ä'])) === strlen('{"t":"ä"}'));

echo "\n";
if ($fail > 0) {
    fwrite(STDERR, "$fail assertion(s) failed.\n");
    exit(1);
}
echo "All user-settings namespace behaviours pass.\n";
