<?php

/**
 * iHymns — Service/Live broadcast state cleaner guard (#1405)
 *
 * Exercises includes/service_mode.php::serviceMode_cleanState() — the ONE
 * allow-list + bidirectional blank<->displayState bridge that every live/service
 * broadcaster writes through (rule #26: never re-fork the helper core). The bridge
 * is load-bearing: web congregants read `blank`, native clients read `displayState`,
 * and they watch the SAME screen — so writing one field must always derive the other,
 * or the two client generations desync on the most visible state (blackout/logo).
 *
 * Asserts:
 *   - non-array / empty -> null
 *   - displayState drives blank: blackout/logo -> blank:true, live -> blank:false
 *   - blank drives displayState: true -> blackout, false -> live
 *   - displayState takes precedence when both are present
 *   - an invalid displayState is ignored (falls back to blank, else dropped)
 *   - lineIndex clamped 0..9999; scrollPct 0..1; transposeOffset -12..12
 *   - unknown keys are stripped
 *
 *   php tests/php/test-service-state-clean.php
 *
 * Exit status 0 = clean, 1 = at least one failure.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/appWeb/public_html/includes/service_mode.php';

$failures = 0;
$passed = 0;

function _sscAssert(bool $cond, string $label): void
{
    global $failures, $passed;
    if ($cond) { $passed++; echo "PASS: $label\n"; }
    else { $failures++; fwrite(STDERR, "FAIL: $label\n"); }
}

/** Decode the cleaner's JSON result to an assoc array (order-independent compare); null stays null. */
function _sscClean(mixed $in): mixed
{
    $out = serviceMode_cleanState($in);
    return $out === null ? null : json_decode($out, true);
}

/* ---- 1. null / empty -------------------------------------------------- */
_sscAssert(serviceMode_cleanState('not-an-array') === null, 'non-array -> null');
_sscAssert(serviceMode_cleanState(null) === null, 'null -> null');
_sscAssert(serviceMode_cleanState([]) === null, 'empty array -> null');
_sscAssert(serviceMode_cleanState(['unknownKey' => 'x']) === null, 'only-unknown-keys -> null (stripped)');

/* ---- 2. displayState drives blank (the v2 -> legacy direction) -------- */
_sscAssert(_sscClean(['displayState' => 'blackout']) === ['displayState' => 'blackout', 'blank' => true], 'displayState blackout -> blank:true');
_sscAssert(_sscClean(['displayState' => 'logo'])     === ['displayState' => 'logo', 'blank' => true],     'displayState logo -> blank:true');
_sscAssert(_sscClean(['displayState' => 'live'])     === ['displayState' => 'live', 'blank' => false],    'displayState live -> blank:false');

/* ---- 3. blank drives displayState (the legacy -> v2 direction) -------- */
_sscAssert(_sscClean(['blank' => true])  === ['blank' => true, 'displayState' => 'blackout'], 'blank:true -> displayState blackout');
_sscAssert(_sscClean(['blank' => false]) === ['blank' => false, 'displayState' => 'live'],    'blank:false -> displayState live');

/* ---- 4. precedence + invalid input ----------------------------------- */
_sscAssert(_sscClean(['displayState' => 'logo', 'blank' => false]) === ['displayState' => 'logo', 'blank' => true], 'displayState wins over a contradictory blank');
_sscAssert(_sscClean(['displayState' => 'bogus']) === null, 'invalid displayState + nothing else -> null');
_sscAssert(_sscClean(['displayState' => 'bogus', 'blank' => true]) === ['blank' => true, 'displayState' => 'blackout'], 'invalid displayState falls back to blank');

/* ---- 5. clamps -------------------------------------------------------- */
_sscAssert(_sscClean(['blank' => true, 'lineIndex' => 12000])['lineIndex'] === 9999, 'lineIndex clamps high -> 9999');
_sscAssert(_sscClean(['blank' => true, 'lineIndex' => -5])['lineIndex'] === 0, 'lineIndex clamps low -> 0');
_sscAssert(_sscClean(['blank' => true, 'lineIndex' => 42])['lineIndex'] === 42, 'lineIndex in range preserved');
_sscAssert((float)_sscClean(['blank' => true, 'scrollPct' => 2.0])['scrollPct'] === 1.0, 'scrollPct clamps to 1.0');
_sscAssert(_sscClean(['blank' => true, 'transposeOffset' => 99])['transposeOffset'] === 12, 'transposeOffset clamps to 12');
_sscAssert(_sscClean(['blank' => true, 'transposeOffset' => -99])['transposeOffset'] === -12, 'transposeOffset clamps to -12');

/* ---- 6. unknown keys stripped alongside valid ones ------------------- */
$mixed = _sscClean(['displayState' => 'live', 'evil' => '</script>', 'nope' => 1]);
_sscAssert($mixed === ['displayState' => 'live', 'blank' => false], 'unknown keys stripped, valid kept');

/* ----------------------------------------------------------------------- */
echo "\n";
echo "service-state-clean: {$passed} passed, {$failures} failed\n";
exit($failures > 0 ? 1 : 0);
