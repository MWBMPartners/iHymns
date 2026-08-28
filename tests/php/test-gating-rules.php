<?php

declare(strict_types=1);

/**
 * iHymns — Admin-configurable feature gating: composable enforcement (#1481 P2)
 *
 * DB-FREE by construction (mirrors tests/php/test-gating-noop.php and
 * tests/php/test-gating-registry.php): `.github/workflows/test.yml` runs no
 * MySQL service container. Every assertion here either (a) calls a PURE
 * function directly with hand-built arrays — no I/O at all — or (b) relies
 * on the documented degrade path: with no appWeb/.auth/db_credentials.php
 * present, getDbMysqli() throws and every wrapping try/catch in
 * includes/gating_rules.php degrades to its fail-open default, so the REAL
 * production functions run their OFF/un-migrated path with zero stubbing.
 *
 * Covers:
 *   1. gatingRuleValidateStripPayloadKeys() / gatingRuleValidateDropMediaKinds()
 *      — accept a valid params shape, reject everything outside the
 *      code-owned allow-lists (empty / non-array / non-string / duplicate /
 *      unknown key or kind / too many entries).
 *   2. gatingRuleEnforceStripPayloadKeys() — strips ONLY the allow-listed
 *      keys named in params, including the two component-level sub-payload
 *      forms (components.chords / components.notes), and leaves every other
 *      key (incl. the must-never-strip set) untouched.
 *   3. gatingRuleEnforceDropMediaKinds() — drops ONLY media rows of the
 *      named kind(s), leaves every other kind's rows in place.
 *   4. gatingRuleAssertNoEscalation() — the subset invariant: a compliant
 *      (narrowing) transform passes; a transform that ADDS a key or GROWS
 *      the media array is caught.
 *   5. GATING_STRIPPABLE_KEYS ∩ GATING_NEVER_STRIP_KEYS === ∅ — a static
 *      guard so a future edit can never accidentally make id/title/
 *      copyright/credit fields strippable.
 *   6. gatingRuleCapKeyIsBuiltIn() — every TIER_CAPS key is caught (the
 *      predicate manage/feature-gating.php's rule-create handler uses to
 *      REJECT a rule on a built-in cap at save time); a plausible new
 *      DB-defined key is not.
 *   7. Dormancy no-op — gatingRulesEnabled() is false and gatingRulesApply()
 *      returns the SAME array (identity, not just equal) with no DB present
 *      (both flags effectively off); contentGatingApply() itself is still a
 *      byte-identical no-op end to end (redundant with test-gating-noop.php,
 *      kept here too so this file stands alone as proof the P2 wiring never
 *      broke the P1/#1353 no-op contract).
 *   8. Catalog structural guard — every GATING_BEHAVIOR_KINDS entry has a
 *      callable validator + enforce callable and an effectString with
 *      exactly one %s placeholder (sprintf safety).
 *
 *   php tests/php/test-gating-rules.php
 *
 * Exit status 0 = all pass, 1 = at least one failure.
 */

$failures = 0;
$passed   = 0;
function _tgrlAssert(bool $cond, string $label): void
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

$root = dirname(__DIR__, 2) . '/appWeb/public_html/includes';

/* Direct-access guards key on SCRIPT_FILENAME matching the file's own
   basename — under CLI that's this test script, so they pass. No DB
   connection happens at require time (lazy — only on first getDbMysqli()
   call inside a try/catch). */
require_once $root . '/content_gating.php';   /* pulls in gating_rules.php too */
require_once $root . '/gating_rules.php';

/* ------------------------------------------------------------------------ *
 * 1. Validators.
 * ------------------------------------------------------------------------ */

/* strip_payload_keys */
_tgrlAssert(
    gatingRuleValidateStripPayloadKeys(['keys' => ['components']]) === null,
    '(1a) a single valid strippable key validates'
);
_tgrlAssert(
    gatingRuleValidateStripPayloadKeys(['keys' => ['components', 'components.chords', 'translations']]) === null,
    '(1a) multiple valid keys (incl. a dotted sub-payload form) validate'
);
_tgrlAssert(
    gatingRuleValidateStripPayloadKeys([]) !== null,
    '(1b) missing "keys" is rejected'
);
_tgrlAssert(
    gatingRuleValidateStripPayloadKeys(['keys' => []]) !== null,
    '(1b) empty "keys" array is rejected'
);
_tgrlAssert(
    gatingRuleValidateStripPayloadKeys(['keys' => 'components']) !== null,
    '(1b) a non-array "keys" is rejected'
);
_tgrlAssert(
    gatingRuleValidateStripPayloadKeys(['keys' => ['id']]) !== null,
    '(1c) a must-never-strip key ("id") is rejected — not in the allow-list'
);
_tgrlAssert(
    gatingRuleValidateStripPayloadKeys(['keys' => ['copyright']]) !== null,
    '(1c) a credit/attribution key ("copyright") is rejected — not in the allow-list'
);
_tgrlAssert(
    gatingRuleValidateStripPayloadKeys(['keys' => ['not_a_real_key']]) !== null,
    '(1c) an unrecognised key is rejected'
);
_tgrlAssert(
    gatingRuleValidateStripPayloadKeys(['keys' => [123]]) !== null,
    '(1d) a non-string entry is rejected'
);
_tgrlAssert(
    gatingRuleValidateStripPayloadKeys(['keys' => ['components', 'components']]) !== null,
    '(1d) a duplicate key is rejected'
);
_tgrlAssert(
    gatingRuleValidateStripPayloadKeys(['keys' => array_fill(0, count(GATING_STRIPPABLE_KEYS) + 1, 'components')]) !== null,
    '(1d) more keys than the allow-list size is rejected'
);

/* drop_media_kinds */
_tgrlAssert(
    gatingRuleValidateDropMediaKinds(['kinds' => ['audio']]) === null,
    '(1e) a single valid media kind validates'
);
_tgrlAssert(
    gatingRuleValidateDropMediaKinds(['kinds' => ['audio', 'midi', 'sheet-music', 'musicxml']]) === null,
    '(1e) all four valid media kinds validate together'
);
_tgrlAssert(
    gatingRuleValidateDropMediaKinds([]) !== null,
    '(1f) missing "kinds" is rejected'
);
_tgrlAssert(
    gatingRuleValidateDropMediaKinds(['kinds' => ['hologram']]) !== null,
    '(1f) an unrecognised media kind ("hologram") is rejected'
);
_tgrlAssert(
    gatingRuleValidateDropMediaKinds(['kinds' => ['audio', 'audio']]) !== null,
    '(1f) a duplicate media kind is rejected'
);

/* gatingRuleValidateParams() dispatch */
_tgrlAssert(
    gatingRuleValidateParams('strip_payload_keys', ['keys' => ['components']]) === null,
    '(1g) gatingRuleValidateParams() dispatches to the right validator (strip)'
);
_tgrlAssert(
    gatingRuleValidateParams('drop_media_kinds', ['kinds' => ['audio']]) === null,
    '(1g) gatingRuleValidateParams() dispatches to the right validator (media)'
);
_tgrlAssert(
    gatingRuleValidateParams('not_a_real_kind', []) !== null,
    '(1h) gatingRuleValidateParams() reports an error for an unknown kind'
);

/* ------------------------------------------------------------------------ *
 * 2. gatingRuleEnforceStripPayloadKeys() — strips ONLY the named keys.
 * ------------------------------------------------------------------------ */
$sampleSong = [
    'id'                 => 'CP-1',
    'title'              => 'A Copyrighted Hymn',
    'number'             => 1,
    'songbook'           => 'CP',
    'copyright'          => '© Example Publisher',
    'writers'            => ['Jane Writer'],
    'components'         => [
        ['type' => 'verse', 'lines' => ['Line one'], 'chords' => ['G', 'C'], 'notes' => 'Slow'],
        ['type' => 'chorus', 'lines' => ['Line two'], 'chords' => ['D'], 'notes' => null],
    ],
    'translations'       => [['lang' => 'es', 'text' => 'uno']],
    'annotations'        => [['text' => 'a note']],
    'media'              => [
        ['kind' => 'audio',       'streamUrl' => '/song-media/10'],
        ['kind' => 'sheet-music', 'streamUrl' => '/song-media/11'],
        ['kind' => 'midi',        'streamUrl' => '/song-media/12'],
    ],
];

$strippedBody = gatingRuleEnforceStripPayloadKeys($sampleSong, ['keys' => ['components', 'translations']]);
_tgrlAssert(!array_key_exists('components', $strippedBody), '(2a) "components" is removed when named');
_tgrlAssert(!array_key_exists('translations', $strippedBody), '(2a) "translations" is removed when named');
_tgrlAssert(array_key_exists('annotations', $strippedBody), '(2a) "annotations" is UNTOUCHED when not named');
_tgrlAssert($strippedBody['id'] === 'CP-1', '(2a) "id" is never touched');
_tgrlAssert($strippedBody['copyright'] === '© Example Publisher', '(2a) "copyright" (attribution) is never touched');
_tgrlAssert($strippedBody['writers'] === ['Jane Writer'], '(2a) "writers" (credit) is never touched');
_tgrlAssert(count($strippedBody['media']) === 3, '(2a) media is untouched by strip_payload_keys');

$strippedChords = gatingRuleEnforceStripPayloadKeys($sampleSong, ['keys' => ['components.chords']]);
_tgrlAssert(array_key_exists('components', $strippedChords), '(2b) "components.chords" keeps the components array itself');
_tgrlAssert(
    !array_key_exists('chords', $strippedChords['components'][0]) && !array_key_exists('chords', $strippedChords['components'][1]),
    '(2b) "components.chords" removes chords from EVERY component'
);
_tgrlAssert(
    $strippedChords['components'][0]['lines'] === ['Line one'] && array_key_exists('notes', $strippedChords['components'][0]),
    '(2b) "components.chords" leaves lines + notes untouched'
);

$strippedNotes = gatingRuleEnforceStripPayloadKeys($sampleSong, ['keys' => ['components.notes']]);
_tgrlAssert(
    !array_key_exists('notes', $strippedNotes['components'][0]) && array_key_exists('chords', $strippedNotes['components'][0]),
    '(2c) "components.notes" removes only notes, leaves chords'
);

/* Defensive re-check: a key NOT in GATING_STRIPPABLE_KEYS is silently
   ignored even if it somehow reached the enforce callable directly
   (never trust the caller already validated). */
$stillIntact = gatingRuleEnforceStripPayloadKeys($sampleSong, ['keys' => ['copyright', 'id']]);
_tgrlAssert(
    $stillIntact === $sampleSong,
    '(2d) an unvalidated (never-strippable) key passed directly is a no-op — defensive re-check holds'
);

/* ------------------------------------------------------------------------ *
 * 3. gatingRuleEnforceDropMediaKinds() — drops ONLY the named kind(s).
 * ------------------------------------------------------------------------ */
$droppedAudio = gatingRuleEnforceDropMediaKinds($sampleSong, ['kinds' => ['audio']]);
_tgrlAssert(count($droppedAudio['media']) === 2, '(3a) dropping "audio" leaves the other two rows');
_tgrlAssert(
    !in_array('audio', array_column($droppedAudio['media'], 'kind'), true),
    '(3a) no audio-kind row remains'
);
_tgrlAssert(
    in_array('midi', array_column($droppedAudio['media'], 'kind'), true)
    && in_array('sheet-music', array_column($droppedAudio['media'], 'kind'), true),
    '(3a) midi + sheet-music rows are untouched'
);

$droppedTwo = gatingRuleEnforceDropMediaKinds($sampleSong, ['kinds' => ['audio', 'midi']]);
_tgrlAssert(count($droppedTwo['media']) === 1 && $droppedTwo['media'][0]['kind'] === 'sheet-music', '(3b) dropping two kinds leaves only the third');

$droppedNone = gatingRuleEnforceDropMediaKinds($sampleSong, ['kinds' => ['hologram']]);
_tgrlAssert(count($droppedNone['media']) === 3, '(3c) an unrecognised kind (defensive re-check) drops nothing');

$noMediaSong = $sampleSong;
unset($noMediaSong['media']);
_tgrlAssert(
    gatingRuleEnforceDropMediaKinds($noMediaSong, ['kinds' => ['audio']]) === $noMediaSong,
    '(3d) a song with no media array is an untouched no-op'
);

/* ------------------------------------------------------------------------ *
 * 4. gatingRuleAssertNoEscalation() — the subset invariant.
 * ------------------------------------------------------------------------ */
_tgrlAssert(
    gatingRuleAssertNoEscalation($sampleSong, $strippedBody) === true,
    '(4a) a compliant strip (fewer keys) passes the no-escalation check'
);
_tgrlAssert(
    gatingRuleAssertNoEscalation($sampleSong, $droppedAudio) === true,
    '(4a) a compliant media drop (fewer media rows) passes'
);
_tgrlAssert(
    gatingRuleAssertNoEscalation($sampleSong, $sampleSong) === true,
    '(4a) an unchanged payload trivially passes (identical keys, identical media count)'
);

$escalatedKey = $sampleSong;
$escalatedKey['brandNewKey'] = 'should never happen';
_tgrlAssert(
    gatingRuleAssertNoEscalation($sampleSong, $escalatedKey) === false,
    '(4b) a transform that ADDS a top-level key is caught'
);

$escalatedMedia = $sampleSong;
$escalatedMedia['media'][] = ['kind' => 'audio', 'streamUrl' => '/song-media/999'];
_tgrlAssert(
    gatingRuleAssertNoEscalation($sampleSong, $escalatedMedia) === false,
    '(4b) a transform that GROWS the media array is caught'
);

/* ------------------------------------------------------------------------ *
 * 5. GATING_STRIPPABLE_KEYS ∩ GATING_NEVER_STRIP_KEYS === ∅ (static guard).
 * ------------------------------------------------------------------------ */
$intersects = false;
foreach (array_keys(GATING_STRIPPABLE_KEYS) as $k) {
    $parent = explode('.', $k, 2)[0];
    if (in_array($k, GATING_NEVER_STRIP_KEYS, true) || in_array($parent, GATING_NEVER_STRIP_KEYS, true)) {
        $intersects = true;
        fwrite(STDERR, "  -> '$k' intersects the never-strip set\n");
    }
}
_tgrlAssert(!$intersects, '(5) GATING_STRIPPABLE_KEYS never intersects GATING_NEVER_STRIP_KEYS');
_tgrlAssert(!empty(GATING_NEVER_STRIP_KEYS), '(5) GATING_NEVER_STRIP_KEYS is non-empty (the guard has teeth)');

/* ------------------------------------------------------------------------ *
 * 6. gatingRuleCapKeyIsBuiltIn() — the save-time rejection predicate.
 * ------------------------------------------------------------------------ */
foreach (array_keys(TIER_CAPS) as $builtIn) {
    _tgrlAssert(
        gatingRuleCapKeyIsBuiltIn($builtIn) === true,
        "(6) built-in cap '$builtIn' is caught (a rule on it must be REJECTED at save)"
    );
}
_tgrlAssert(
    gatingRuleCapKeyIsBuiltIn('CanRequestSongs') === false,
    "(6) a plausible new DB-defined cap ('CanRequestSongs') is NOT flagged as built-in"
);

/* ------------------------------------------------------------------------ *
 * 7. Dormancy no-op — no DB present in this CI run.
 * ------------------------------------------------------------------------ */
_tgrlAssert(gatingRulesEnabled() === false, '(7a) gatingRulesEnabled() is false with no DB present (default \'0\')');
_tgrlAssert(gatingRulesFetchEnabled() === [], '(7a) gatingRulesFetchEnabled() degrades to an empty list with no DB present');

$songForLoop = $sampleSong;
$loopResult  = gatingRulesApply($songForLoop, 'public');
_tgrlAssert($loopResult === $songForLoop, '(7b) gatingRulesApply() is a byte-identical no-op with no DB present');

/* End-to-end: contentGatingApply() (which now calls gatingRulesApply() at
   the very end, inside the same try block) is STILL a no-op when the master
   switch itself resolves off — proving the P2 wiring didn't disturb the
   P1/#1353 no-op contract (redundant with test-gating-noop.php by design —
   this file should stand alone). */
$gatingOn = function_exists('contentGatingEnabled') && contentGatingEnabled();
if ($gatingOn) {
    echo "  SKIP  content_gating_enabled resolves to ON in this env — end-to-end no-op assertion skipped.\n";
} else {
    $e2eSong = $sampleSong;
    $e2eOut  = contentGatingApply($e2eSong, null);
    _tgrlAssert($e2eOut === $e2eSong, '(7c) contentGatingApply() end-to-end is still a byte-identical no-op (P2 wiring did not disturb P1)');
}

/* ------------------------------------------------------------------------ *
 * 8. Catalog structural guard.
 * ------------------------------------------------------------------------ */
foreach (GATING_BEHAVIOR_KINDS as $kind => $tuple) {
    _tgrlAssert(count($tuple) === 5, "(8) '$kind' catalog entry has exactly 5 elements");
    _tgrlAssert(is_string($tuple[0]) && $tuple[0] !== '', "(8) '$kind' has a non-empty label");
    _tgrlAssert(is_string($tuple[1]) && $tuple[1] !== '', "(8) '$kind' has a non-empty description");
    _tgrlAssert(substr_count($tuple[2], '%s') === 1, "(8) '$kind' effectString has exactly one %s placeholder");
    _tgrlAssert(is_callable($tuple[3]), "(8) '$kind' validator is callable");
    _tgrlAssert(is_callable($tuple[4]), "(8) '$kind' enforce callable is callable");
}

/* ----------------------------------------------------------------------- */
echo "\n";
echo "gating-rules: {$passed} passed, {$failures} failed\n";
exit($failures > 0 ? 1 : 0);
