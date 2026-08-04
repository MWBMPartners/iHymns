<?php

declare(strict_types=1);

/**
 * iHymns — shared ArrangementJson validator (#1627 item 2)
 *
 * ELI5
 * ----
 * A song's arrangement is a running order — "section 0, then 1, then 1 again".
 * Two different parts of the app decide whether one is valid: the code that
 * SAVES it, and the cutover gate that AUDITS it. This checks they apply the
 * same rule, by running identical inputs through both and comparing.
 *
 * WHY IT MATTERS MORE THAN IT LOOKS
 * ---------------------------------
 * The maths existed twice — `_sanitiseArrangement()` (write) and gate G4's
 * inline loop (audit, #1618). If those ever drifted with the WRITER looser than
 * the GATE, the consequence is not a wrong answer, it is a deadlock: curators
 * quietly persist arrangements the gate rejects, then the #1618 cutover
 * verification goes red on real curator data and the destructive C6 drop that
 * epic #1601 exists to unblock stays blocked — months later, with nothing
 * pointing back at the editor that caused it.
 *
 * So section 3 is the load-bearing one. Sections 1-2 check each function; only
 * section 3 checks the property the extraction was FOR.
 *
 * THE ASYMMETRY IS INTENTIONAL AND IS ASSERTED, NOT ASSUMED
 * ---------------------------------------------------------
 * arrangementSanitise() accepts digit-strings (it handles untrusted INPUT and
 * coerces); arrangementViolations() requires strict ints (it judges data ALREADY
 * STORED, where a digit-string means something bypassed the writer). A future
 * reader "tidying" these into one rule would break one of the two callers, so
 * both halves are pinned below.
 *
 *   php tests/php/test-arrangement-validator.php
 *
 * Exit status 0 = all pass, 1 = at least one failure.
 */

require_once dirname(__DIR__, 2) . '/appWeb/public_html/includes/arrangement.php';

$passed = 0;
$failed = 0;
$failures = [];

function ok(string $label, bool $cond): void
{
    global $passed, $failed, $failures;
    if ($cond) {
        $passed++;
        echo "  ✅ {$label}\n";
    } else {
        $failed++;
        $failures[] = $label;
        echo "  ❌ {$label}\n";
    }
}

echo "\n#1627 — shared ArrangementJson validator\n\n";

/* ---- 1. arrangementSanitise(): coerce + validate INPUT ----------------- */

ok('a simple order round-trips',
    arrangementSanitise([0, 1, 2], 3) === '[0,1,2]');

/* Repetition is the POINT — verse/chorus/verse/chorus is an arrangement.
   The schema's own example is [0,1,1,2,3]. Neither uniqueness nor full
   coverage of the section list is required. */
ok('REPETITION is legal (a running order repeats sections by design)',
    arrangementSanitise([0, 1, 1, 2], 3) === '[0,1,1,2]');

ok('a subset of sections is legal (an arrangement need not use them all)',
    arrangementSanitise([2], 3) === '[2]');

ok('digit-STRINGS are coerced to ints (JSON bodies + imports carry them)',
    arrangementSanitise(['0', '2'], 3) === '[0,2]');

/* All-or-nothing: one bad ordinal discards the WHOLE arrangement rather than
   silently dropping an entry, which would reorder the song without telling
   anyone. Preserved byte-identically from the original _sanitiseArrangement. */
ok('one out-of-range ordinal discards the whole arrangement, not just that entry',
    arrangementSanitise([0, 1, 9], 3) === null);

ok('a negative ordinal is rejected',       arrangementSanitise([0, -1], 3) === null);
ok('a non-numeric entry is rejected',      arrangementSanitise([0, 'x'], 3) === null);
ok('a float is rejected (not an index)',   arrangementSanitise([0, 1.5], 3) === null);
ok('an empty list yields null, not "[]"',  arrangementSanitise([], 3) === null);
ok('a non-array yields null',              arrangementSanitise('nope', 3) === null);
ok('componentCount 0 yields null (no sections to index)',
    arrangementSanitise([0], 0) === null);
ok('the upper bound is EXCLUSIVE (n=3 means max index 2)',
    arrangementSanitise([3], 3) === null && arrangementSanitise([2], 3) === '[2]');

/* ---- 2. arrangementViolations(): judge STORED data -------------------- */

ok('a valid stored order has no violations',
    arrangementViolations([0, 1, 1], 2) === []);

ok('a non-array is reported as malformed',
    arrangementViolations('nope', 3) === [['reason' => 'malformed']]);

ok('a digit-STRING is a violation here (strict — this judges stored data)',
    arrangementViolations(['0'], 3) === [['reason' => 'not-int', 'position' => 0, 'value' => '0']]);

ok('out-of-range reports position AND value, so G4 can keep its payload',
    arrangementViolations([0, 7], 3) === [['reason' => 'out-of-range', 'position' => 1, 'value' => 7]]);

ok('EVERY bad ordinal is reported, not just the first (G4 lists them all)',
    count(arrangementViolations([9, 1, -1], 3)) === 2);

/* ---- 3. THE POINT: both entry points agree ---------------------------- */

/* Everything arrangementSanitise() ACCEPTS must pass arrangementViolations()
   once coerced. If this ever fails, the editor can persist data gate G4 will
   reject — the deadlock described in the header. */
$agreeCases = [
    [[0, 1, 2], 3],
    [[0, 1, 1, 2], 3],
    [['0', '2'], 3],
    [[2], 3],
    [[0], 1],
    [[0, 1, 9], 3],
    [[0, -1], 3],
    [[], 3],
    [[0], 0],
    [[3], 3],
];
$agree = true;
$firstDisagreement = '';
foreach ($agreeCases as [$input, $n]) {
    $stored = arrangementSanitise($input, $n);
    if ($stored === null) {
        continue;   // rejected on the write side — nothing reaches storage to audit
    }
    $viol = arrangementViolations(json_decode($stored, true), $n);
    if ($viol !== []) {
        $agree = false;
        $firstDisagreement = json_encode($input) . " with n={$n} stored as {$stored}";
        break;
    }
}
ok('ANYTHING the writer stores passes the gate — the property this extraction exists for'
    . ($agree ? '' : " [{$firstDisagreement}]"),
    $agree);

/* ---- 4. the old name still works, identically ------------------------- */

/* _sanitiseArrangement() is now a delegate. Its callers (save_song_core.php,
   the ZIP importer) were deliberately left untouched, so its behaviour must be
   indistinguishable from before — and from the function it delegates to. */
require_once dirname(__DIR__, 2) . '/appWeb/public_html/includes/song_importers.php';

$delegateSame = true;
foreach ($agreeCases as [$input, $n]) {
    if (_sanitiseArrangement($input, $n) !== arrangementSanitise($input, $n)) {
        $delegateSame = false;
        break;
    }
}
ok('_sanitiseArrangement() is a faithful delegate (its callers are untouched)',
    $delegateSame);

/* ---- 5. the gate cannot silently regrow its own copy ------------------ */

/* A source assertion, and a deliberate one: the failure mode is not "G4 breaks"
   — it is "G4 keeps working while quietly applying a DIFFERENT rule". That is
   invisible to every behavioural test above, because both halves would still
   pass on their own. Only the wiring can be checked here. */
$gateSrc = (string)file_get_contents(dirname(__DIR__, 2) . '/appWeb/.sql/verify-lyrics-cutover.php');
$gateCode = preg_replace('#/\*[\s\S]*?\*/#', '', $gateSrc) ?? $gateSrc;

ok('gate G4 calls the shared arrangementViolations()',
    str_contains($gateCode, 'arrangementViolations('));

ok('gate G4 no longer carries its own inline ordinal loop',
    !preg_match('/is_int\(\$g4Val\)/', $gateCode));

ok('the gate REQUIREs includes/arrangement.php (it calls it unconditionally)',
    str_contains($gateCode, 'includes/arrangement.php'));

echo "\n{$passed} passed, {$failed} failed\n";
if ($failed > 0) {
    echo "\nFailures:\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    echo "\nThe write side and gate G4 MUST apply one rule. If they drift with the\n";
    echo "writer looser, curators persist data the #1618 gate rejects and the C6\n";
    echo "drop stays blocked with nothing pointing back at the cause.\n";
    exit(1);
}
echo "\nAll arrangement validator assertions passed.\n";
