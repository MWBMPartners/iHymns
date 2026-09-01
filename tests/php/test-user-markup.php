<?php
/**
 * iHymns — per-user song markup pure-validator unit test (#1266 Phase 1)
 *
 * Exercises the PURE, DB-free validators in
 * appWeb/public_html/includes/user_markup.php — the shared vocab +
 * validation layer the three dormant api.php actions
 * (user_markup_list/_upsert/_delete) call. The DB-touching read/write
 * functions (userMarkupUpsert, userMarkupDelete, userMarkupListForSong, …)
 * need a live mysqli and are covered by manual / staging verification, same
 * split as tests/php/test-line-enrichment.php for the sibling #1088 family;
 * these pure guards are the CI-enforced contract.
 *
 *   php tests/php/test-user-markup.php
 *
 * Exit status 0 = all tests pass, 1 = at least one assertion failed.
 *
 * MUTATION RECORD (rule #34 — a guard whose first green run was never
 * challenged has, in this repo's history, repeatedly been silently wrong).
 * Each mutant below was applied to a SCRATCH COPY of user_markup.php (never
 * the tracked file — `git status --porcelain` confirmed clean before and
 * after), pointed at by a throwaway include shim, run against this same
 * assertion list, and restored:
 *
 *  M1  widen USER_MARKUP_BODY_MAX_LEN from 5000 to 6000
 *      → GREEN on the FIRST attempt — an under-reporting guard, caught
 *        before this file was trusted. The boundary fixtures originally
 *        built $atCap/$overCap FROM the constant itself
 *        (str_repeat('é', USER_MARKUP_BODY_MAX_LEN)), so widening the
 *        constant moved the fixtures right along with it: "at the cap" and
 *        "one over the cap" were both still true at 6000/6001, and the
 *        assertion never noticed the contract had changed. Fixed by
 *        hardcoding the LITERAL 5000/5001 in the fixtures plus a direct
 *        `assertEq(USER_MARKUP_BODY_MAX_LEN, 5000, …)` pin — re-run against
 *        the same M1 mutant afterwards → RED (both boundary assertions and
 *        the literal-pin assertion fail). This is the exact "sourcing a
 *        value from the same place asserted against it proves nothing"
 *        trap rule #34's own worked examples (M10, M17) describe.
 *  M2  add a bogus 'sketch' entry to USER_MARKUP_KINDS
 *      → RED: "kind: unknown kind rejected (null)" fails ('sketch' now
 *        normalises instead of returning null).
 *  M3  invert userMarkupRowCapExceeded() to `$existingCount < USER_MARKUP_ROW_CAP`
 *      → RED: every "cap:" assertion fails (exceeded reports as not-exceeded
 *        and vice versa).
 *  M4  drop the `$kind === 'note'` guard in userMarkupValidateBody() (a
 *      highlight and a note are then validated identically)
 *      → RED: "body: null/empty/whitespace-only body on a note throws"
 *        all three fail (no exception).
 * Every mutant was applied to a SCRATCH COPY under the session scratchpad
 * (never the tracked file), run through a throwaway wrapper that required
 * only the mutant, and discarded afterwards; `git status --porcelain --
 * appWeb/public_html/includes/user_markup.php` showed no diff at any point
 * during this exercise (the file was untracked-new throughout, never
 * modified-then-reverted).
 *
 * @see appWeb/public_html/includes/user_markup.php
 * @see tests/php/test-line-enrichment.php   the sibling #1088 test this mirrors
 */

declare(strict_types=1);

/* db_mysql.php is required by user_markup.php for bindParamSafe()/getDbMysqli;
   it defines functions only (no connection on include). */
require dirname(__DIR__, 2) . '/appWeb/public_html/includes/db_mysql.php';
require dirname(__DIR__, 2) . '/appWeb/public_html/includes/user_markup.php';

$passed = 0;
$failed = 0;
function assertEq($actual, $expected, string $label): void
{
    global $passed, $failed;
    if ($actual === $expected) { echo "  PASS  $label\n"; $passed++; }
    else {
        echo "  FAIL  $label\n";
        echo "        expected: " . var_export($expected, true) . "\n";
        echo "        actual:   " . var_export($actual, true) . "\n";
        $failed++;
    }
}
function assertThrows(callable $fn, string $label, ?string $expectClass = null): void
{
    global $passed, $failed;
    try {
        $fn();
        echo "  FAIL  $label (no exception)\n";
        $failed++;
    } catch (\Throwable $e) {
        if ($expectClass !== null && !($e instanceof $expectClass)) {
            echo "  FAIL  $label (threw " . get_class($e) . ", expected $expectClass)\n";
            $failed++;
            return;
        }
        echo "  PASS  $label\n";
        $passed++;
    }
}
function assertNoThrow(callable $fn, string $label): void
{
    global $passed, $failed;
    try {
        $fn();
        echo "  PASS  $label\n";
        $passed++;
    } catch (\Throwable $e) {
        echo "  FAIL  $label (threw " . get_class($e) . ": " . $e->getMessage() . ")\n";
        $failed++;
    }
}

/* ==================================================================== */
/* Vocabulary allow-lists (rule #20 — VARCHAR validated, never ENUM)     */
/* ==================================================================== */
assertEq(userMarkupNormalizeVocab('note', USER_MARKUP_KINDS), 'note',
    'vocab: exact kind passes');
assertEq(userMarkupNormalizeVocab('  Highlight ', USER_MARKUP_KINDS), 'highlight',
    'vocab: trims + lower-cases');
assertEq(userMarkupNormalizeVocab('sketch', USER_MARKUP_KINDS), null,
    'vocab: unknown kind rejected (null)');
assertEq(userMarkupNormalizeVocab('YELLOW', USER_MARKUP_COLOURS), 'yellow',
    'vocab: colour token case-folds');
assertEq(userMarkupNormalizeVocab('chartreuse', USER_MARKUP_COLOURS), null,
    'vocab: unknown colour rejected (null)');

/* ==================================================================== */
/* userMarkupValidateKind() — throws \InvalidArgumentException (->422)   */
/* ==================================================================== */
assertEq(userMarkupValidateKind('note'), 'note', 'kind: note passes through');
assertEq(userMarkupValidateKind('Highlight'), 'highlight', 'kind: highlight case-folds');
assertThrows(static fn() => userMarkupValidateKind('drawing'),
    'kind: unrecognised value (drawing, not yet a real kind) throws', \InvalidArgumentException::class);
assertThrows(static fn() => userMarkupValidateKind(''),
    'kind: empty string throws', \InvalidArgumentException::class);

/* ==================================================================== */
/* userMarkupValidateColour() — null/'' allowed; else allow-listed only  */
/* ==================================================================== */
assertEq(userMarkupValidateColour(null), null, 'colour: null is allowed (no colour)');
assertEq(userMarkupValidateColour(''), null, 'colour: empty string normalises to null');
assertEq(userMarkupValidateColour('  '), null, 'colour: whitespace-only normalises to null');
assertEq(userMarkupValidateColour('green'), 'green', 'colour: allow-listed value passes');
assertEq(userMarkupValidateColour('BLUE'), 'blue', 'colour: case-folds');
assertThrows(static fn() => userMarkupValidateColour('purple'),
    'colour: not in the allow-list throws', \InvalidArgumentException::class);

/* ==================================================================== */
/* userMarkupValidateBody() — note requires non-empty; highlight may be  */
/* null; both are capped at USER_MARKUP_BODY_MAX_LEN CODE POINTS         */
/* (mb_strlen, never bytes — rule #21's discipline applied here too)     */
/* ==================================================================== */
assertEq(userMarkupValidateBody('note', 'Remember to slow down here'), 'Remember to slow down here',
    'body: a normal note body passes through trimmed');
assertEq(userMarkupValidateBody('note', '  padded  '), 'padded',
    'body: leading/trailing whitespace is trimmed');
assertThrows(static fn() => userMarkupValidateBody('note', null),
    'body: null body on a note throws', \InvalidArgumentException::class);
assertThrows(static fn() => userMarkupValidateBody('note', ''),
    'body: empty body on a note throws', \InvalidArgumentException::class);
assertThrows(static fn() => userMarkupValidateBody('note', '   '),
    'body: whitespace-only body on a note throws (trims to empty)', \InvalidArgumentException::class);
assertEq(userMarkupValidateBody('highlight', null), null,
    'body: null body on a highlight is VALID (pure highlight, no text)');
assertEq(userMarkupValidateBody('highlight', ''), null,
    'body: empty body on a highlight normalises to null');
assertEq(userMarkupValidateBody('highlight', 'also has a note'), 'also has a note',
    'body: a highlight MAY also carry text');

/* The cap constant itself, pinned to a LITERAL — not read back symmetrically
   by the boundary fixtures below. Building $atCap/$overCap from
   USER_MARKUP_BODY_MAX_LEN itself (rather than the literal 5000) was tried
   first and is exactly the self-referential trap rule #34 warns about: a
   mutant that WIDENS the constant to 6000 still passed, because "at the
   (now-6000) cap" and "one over the (now-6000) cap" moved right along with
   it — the test measured the constant against itself, never against the
   documented contract. Hardcoding 5000/5001 here is what makes M1 (widen to
   6000) actually go red. */
assertEq(USER_MARKUP_BODY_MAX_LEN, 5000, 'cap constant: USER_MARKUP_BODY_MAX_LEN is exactly 5000');

/* Length boundary — exactly at the cap passes, one over is rejected.
   Built from a multi-byte character (é, U+00E9, 2 bytes in UTF-8) so this
   also proves the cap counts CODE POINTS, not bytes: 5000 code points here
   is 10000 bytes, and a byte-length check would reject it wrongly. */
$atCap   = str_repeat('é', 5000);
$overCap = str_repeat('é', 5001);
assertEq(mb_strlen($atCap, 'UTF-8'), 5000,
    'body: sanity — the fixture is exactly 5000 CODE POINTS (10000 bytes)');
assertNoThrow(static fn() => userMarkupValidateBody('note', $atCap),
    'body: exactly 5000 chars is the last accepted length');
assertThrows(static fn() => userMarkupValidateBody('note', $overCap),
    'body: 5001 chars is rejected', \InvalidArgumentException::class);

/* ==================================================================== */
/* userMarkupRowCapExceeded() — PURE, takes a plain int (no DB handle)   */
/* ==================================================================== */
assertEq(userMarkupRowCapExceeded(0), false, 'cap: zero existing rows is not exceeded');
assertEq(userMarkupRowCapExceeded(USER_MARKUP_ROW_CAP - 1), false,
    'cap: one below the cap is not exceeded');
assertEq(userMarkupRowCapExceeded(USER_MARKUP_ROW_CAP), true,
    'cap: exactly at the cap IS exceeded (the cap counts rows already held before a new insert)');
assertEq(userMarkupRowCapExceeded(USER_MARKUP_ROW_CAP + 50), true,
    'cap: comfortably over the cap is exceeded');

echo "\n  ----------------------------------------\n";
echo "  $passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
