<?php

/**
 * iHymns — set-list service plans / template slots (#301, #1671 F4)
 *
 * THE ONE PROPERTY THIS FILE EXISTS TO PROVE
 * ------------------------------------------
 * A service plan carried into a set list must survive a sync ROUND TRIP. #1671
 * Batch 8 (commit 2f0d9445) refused to build the templates UI precisely because
 * it could not: `user_setlists_sync` had nowhere to put slots, so a plan went
 * to the server and came back GONE — silently, and for signed-in users only.
 *
 * So the load-bearing assertions here do not describe the code. They take a
 * realistic plan, push it through the REAL sanitiser + encoder + decoder, and
 * assert that what comes out equals what went in, slot for slot, field for
 * field. That is a behaviour, it has a runtime handle, and this file uses it.
 *
 * WHY IT IS NOT A SOURCE SCAN
 * ---------------------------
 * `tests/php/test-transaction-fatal.php` is the worked example this follows: a
 * predicate that had been guarded by source inspection was gutted to
 * `return false;`, kept every word the guard looked for, and the whole suite
 * stayed green while every call site resumed swallowing deadlocks. Vocabulary
 * survives any mutation that keeps the words. Only calling the function proves
 * it still answers correctly.
 *
 * The ONE structural section (§7) is deliberately narrow: it asserts things
 * with NO runtime handle in this container — that api.php's four bind_param
 * type strings match the placeholder counts of the statements they bind (a
 * mismatch is an ArgumentCountError no unit test here can reach without MySQL),
 * and that the doomed `array_slice` truncation really is gone from the template
 * save path.
 *
 *   php tests/php/test-setlist-templates.php
 *
 * Exit status 0 = all assertions pass, 1 = at least one failure.
 *
 * ⚠️ NOT COVERED, and the file says so rather than implying otherwise: there is
 * no MySQL in this container, so `setlistSlotsColumnReady()`, the four
 * prepared statements, the 413 responses and the two new endpoints' HTTP paths
 * are exercised by nothing here.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/appWeb/public_html/includes/setlist_templates.php';

$failures = 0;
$passed = 0;

function _sltAssert(bool $cond, string $label): void
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
 * 1. THE ROUND TRIP — the #1671 F4 blocker, asserted as a behaviour
 * ====================================================================== */

echo "\n-- plan round trip (the blocker Batch 8 refused to build over) --\n";

$realisticPlan = [
    'templateId'   => 4,
    'templateName' => 'Sunday Morning',
    'slots' => [
        ['id' => 'a1', 'label' => 'Welcome',        'type' => 'welcome'],
        ['id' => 'a2', 'label' => 'Opening song',   'type' => 'song', 'songId' => 'MP-1008'],
        ['id' => 'a3', 'label' => 'Praise',         'type' => 'song', 'songId' => 'CP-0042', 'note' => 'verse 1 only'],
        ['id' => 'a4', 'label' => 'Reading',        'type' => 'reading'],
        ['id' => 'a5', 'label' => 'Sermon',         'type' => 'sermon'],
        ['id' => 'a6', 'label' => 'Closing',        'type' => 'song'],
    ],
];

$sanitised = setlistTemplateSanitisePlan($realisticPlan);
$encoded   = setlistTemplateEncodePlan($sanitised);
$decoded   = setlistTemplateDecodePlan($encoded);

_sltAssert($decoded === $sanitised, 'a realistic plan survives sanitise -> encode -> decode unchanged');
_sltAssert(is_array($decoded) && count($decoded['slots']) === 6, 'all six slots survive the round trip (none dropped)');
_sltAssert($decoded['templateId'] === 4, 'templateId survives the round trip');
_sltAssert($decoded['templateName'] === 'Sunday Morning', 'templateName survives the round trip');

/* Field-by-field, because "the array matches" can hide a systematic loss if the
   comparison is against an equally-lossy expectation. These are the values a
   worship leader typed. */
_sltAssert($decoded['slots'][1]['songId'] === 'MP-1008', 'an assigned songId survives the round trip');
_sltAssert($decoded['slots'][2]['note'] === 'verse 1 only', 'a per-slot note survives the round trip');
_sltAssert($decoded['slots'][3]['label'] === 'Reading', 'a non-song slot label survives the round trip');
_sltAssert($decoded['slots'][3]['type'] === 'reading', 'a non-song slot type survives the round trip');
_sltAssert(!array_key_exists('songId', $decoded['slots'][3]), 'an unfilled slot carries NO songId key at all');

/* THE SECOND ROUND TRIP. A plan read back and re-saved must be a fixed point;
   if it is not, every load-then-save mutates the data a little and a dirty-check
   reports unsaved changes forever. */
$second = setlistTemplateDecodePlan(setlistTemplateEncodePlan($decoded));
_sltAssert($second === $decoded, 'a decoded plan is a FIXED POINT — re-saving it changes nothing');

/* UTF-8 must survive intact: labels are user prose in whatever language the
   congregation speaks, and the encoder uses JSON_UNESCAPED_UNICODE. */
$utf8 = setlistTemplateDecodePlan(setlistTemplateEncodePlan(
    setlistTemplateSanitisePlan(['slots' => [['id' => 'u1', 'label' => 'Lofsöngur — 讚美 🙏', 'type' => 'song']]])
));
_sltAssert($utf8['slots'][0]['label'] === 'Lofsöngur — 讚美 🙏', 'non-ASCII labels survive the round trip byte-for-byte');

/* ====================================================================== *
 * 2. THE ENVELOPE — both accepted request shapes, and clearing
 * ====================================================================== */

echo "\n-- envelope shapes + clearing --\n";

/* A bare list is what tblSetlistTemplates.SlotsJson itself stores, so
   "apply this template" can hand its own value straight through. */
$bare = setlistTemplateSanitisePlan([
    ['label' => 'Opening', 'type' => 'song'],
    ['label' => 'Prayer',  'type' => 'prayer'],
]);
_sltAssert(is_array($bare) && count($bare['slots']) === 2, 'a BARE slot list is accepted and wrapped in an envelope');
_sltAssert($bare['templateId'] === null && $bare['templateName'] === null,
    'a bare list gets null provenance rather than an invented templateId');

/* Clearing. Sending an empty plan must CLEAR, not preserve — the isset()
   clear-semantics trap is a value nothing can ever delete. */
_sltAssert(setlistTemplateSanitisePlan(['slots' => []]) === null, 'an EMPTY slots array clears the plan (returns null)');
_sltAssert(setlistTemplateSanitisePlan([]) === null, 'an empty array clears the plan');
_sltAssert(setlistTemplateSanitisePlan(null) === null, 'null is no plan');
_sltAssert(setlistTemplateSanitisePlan('nonsense') === null, 'a non-array is no plan (never a fatal)');
_sltAssert(setlistTemplateEncodePlan(null) === null, 'encoding "no plan" yields SQL NULL, not the string "null"');

/* An envelope whose slots are all unusable is ALSO no plan — otherwise a
   client could store `{templateId: 9, slots: []}` and see a plan header with
   nothing under it. */
_sltAssert(
    setlistTemplateSanitisePlan(['templateId' => 9, 'templateName' => 'X', 'slots' => [['label' => '']]]) === null,
    'an envelope whose every slot is unusable is no plan, not an empty-headed one'
);

/* Decode fail-safes. A set list whose plan is unreadable must still LOAD:
   the songs are irreplaceable, the plan is re-appliable. */
_sltAssert(setlistTemplateDecodePlan(null) === null, 'a NULL column decodes to no plan');
_sltAssert(setlistTemplateDecodePlan('') === null, 'an empty column decodes to no plan');
_sltAssert(setlistTemplateDecodePlan('   ') === null, 'a whitespace column decodes to no plan');
_sltAssert(setlistTemplateDecodePlan('{not json') === null, 'unparseable JSON decodes to no plan rather than throwing');
_sltAssert(setlistTemplateDecodePlan('"a string"') === null, 'a scalar JSON document decodes to no plan');

/* A stored document written by some OTHER writer (a restored backup, iLyricsDB,
   a native client) is re-sanitised on the way out, so a malformed slot can
   never reach a renderer. */
$hostile = setlistTemplateDecodePlan('{"slots":[{"label":"Ok"},{"nope":1},{"label":""}],"templateId":"7"}');
_sltAssert(is_array($hostile) && count($hostile['slots']) === 1,
    'a stored document is RE-SANITISED on read — malformed slots never reach a renderer');
_sltAssert($hostile['templateId'] === 7, 'a numeric-string templateId in storage is coerced, not dropped');

/* ====================================================================== *
 * 3. SLOT SANITISATION — what is kept, what is dropped, what is bounded
 * ====================================================================== */

echo "\n-- slot sanitisation --\n";

_sltAssert(setlistTemplateSanitiseSlots('nope') === [], 'a non-array slots value sanitises to the empty list');
_sltAssert(setlistTemplateSanitiseSlots([]) === [], 'an empty slots array sanitises to the empty list');
_sltAssert(setlistTemplateSanitiseSlots([null, 5, 'x']) === [], 'non-array slot entries are dropped');

$dropped = setlistTemplateSanitiseSlots([
    ['label' => 'Kept'],
    ['label' => ''],
    ['label' => '   '],
    ['type' => 'song'],          /* no label at all */
]);
_sltAssert(count($dropped) === 1, 'a slot with no usable label is DROPPED (it cannot be rendered)');
_sltAssert($dropped[0]['label'] === 'Kept', 'the labelled slot is the one that survives');
_sltAssert($dropped[0]['type'] === 'song', 'a slot with no type defaults to song');

/* Unknown keys are discarded rather than stored — the same "keep only what the
   shape allows" rule setlistCollabSanitiseSongs() applies to songs. */
$extra = setlistTemplateSanitiseSlots([['label' => 'A', 'type' => 'song', 'evil' => '<script>', 'duration' => 90]]);
_sltAssert(array_keys($extra[0]) === ['id', 'label', 'type'], 'unknown slot keys are discarded, not stored');

/* Bounds. Over-long values are trimmed to the declared widths — that is a
   per-FIELD bound on a single value, not a cap on how many things you sent, so
   it is a truncation this codebase does allow (the forbidden kind silently
   discards whole ITEMS). */
$long = setlistTemplateSanitiseSlots([[
    'id'     => str_repeat('x', 200),
    'label'  => str_repeat('L', 400),
    'type'   => str_repeat('T', 200),
    'songId' => str_repeat('S', 400),
    'note'   => str_repeat('N', 900),
]]);
_sltAssert(mb_strlen($long[0]['id']) === 40,     'slot id is bounded to 40 characters');
_sltAssert(mb_strlen($long[0]['label']) === 100, 'slot label is bounded to 100 characters');
_sltAssert(mb_strlen($long[0]['type']) === 50,   'an unknown slot type is bounded to 50 characters');
_sltAssert(mb_strlen($long[0]['songId']) === 100, 'slot songId is bounded to 100 characters');
_sltAssert(mb_strlen($long[0]['note']) === 500,  'slot note is bounded to 500 characters');

/* Ids. A client that mints none still gets a usable, distinct key per slot. */
$noIds = setlistTemplateSanitiseSlots([['label' => 'A'], ['label' => 'B'], ['label' => 'C']]);
_sltAssert(count(array_unique(array_column($noIds, 'id'))) === 3,
    'slots with no client id get DISTINCT fallback ids (a duplicate key would collapse DOM rows)');

/* The id charset must match userSyncSanitiseId()'s, because the two ids travel
   in the same payload and a reader should not have to learn two rules. */
_sltAssert(setlistTemplateSanitiseSlotId('a-b_C9') === 'a-b_C9', 'a legal slot id passes through untouched');
_sltAssert(setlistTemplateSanitiseSlotId("dro'p; TABLE") === 'dropTABLE', 'punctuation is stripped from a slot id');
_sltAssert(setlistTemplateSanitiseSlotId('') === '', 'an empty slot id stays empty (the caller substitutes)');
_sltAssert(setlistTemplateSanitiseSlotId(['x']) === '', 'a non-scalar slot id is rejected');
_sltAssert(setlistTemplateSanitiseSlotId(7) === '7', 'an integer slot id is accepted');

/* Blank optional fields are OMITTED, never stored as null — see the sanitiser's
   docblock: `{"songId": null}` vs no key at all is a difference a dirty-check
   sees on every load. */
$blankOpts = setlistTemplateSanitiseSlots([['label' => 'A', 'songId' => '  ', 'note' => '   ']]);
_sltAssert(!array_key_exists('songId', $blankOpts[0]), 'a blank songId is OMITTED, not stored as null');
_sltAssert(!array_key_exists('note', $blankOpts[0]), 'a blank note is OMITTED, not stored as null');

/* ====================================================================== *
 * 4. THE SLOT-TYPE REGISTRY — one line to add a type, and case-folding
 * ====================================================================== */

echo "\n-- slot type registry --\n";

$types = setlistTemplateSlotTypes();
_sltAssert(count($types) >= 5, 'the slot-type registry is populated');
_sltAssert(array_key_exists('song', $types), "the registry contains 'song'");
foreach (array_keys($types) as $key) {
    _sltAssert(
        $key === mb_strtolower($key) && preg_match('/^[a-z][a-z0-9_]*\z/', $key) === 1,
        "registry key '$key' is lower-case ASCII (normalise() case-folds before matching)"
    );
}
foreach ($types as $key => $label) {
    _sltAssert(is_string($label) && trim($label) !== '', "registry key '$key' has a human label a picker can show");
}

/* Case-folding is what stops one plan containing two slots that differ only by
   case — MySQL JSON keys are case-sensitive, humans are not. */
_sltAssert(setlistTemplateNormaliseType('Song') === 'song', 'a known type is case-folded to its registry key');
_sltAssert(setlistTemplateNormaliseType('  PRAYER ') === 'prayer', 'a known type is trimmed and case-folded');
_sltAssert(setlistTemplateNormaliseType('') === 'song', 'an empty type defaults to song');
_sltAssert(setlistTemplateNormaliseType(null) === 'song', 'a null type defaults to song');
_sltAssert(setlistTemplateNormaliseType([]) === 'song', 'a non-scalar type defaults to song');

/* UNKNOWN TYPES ARE KEPT. The #301 endpoint has been publicly documented as
   accepting a free 50-character `type`; narrowing a shipped contract to a
   closed list would 400 an out-of-repo caller that has been correct all along. */
_sltAssert(setlistTemplateNormaliseType('Baptism') === 'Baptism',
    'an UNKNOWN type is kept verbatim (the registry is a suggestion list, not an allow-list)');

/* ====================================================================== *
 * 5. CAPS ARE REJECTIONS — the property, not the wording
 * ====================================================================== */

echo "\n-- caps are rejections, never truncations --\n";

$cap = setlistTemplateMaxSlots();
_sltAssert($cap > 0, 'the slot cap is a positive number');

$atCap   = array_fill(0, $cap,     ['label' => 'x']);
$overCap = array_fill(0, $cap + 1, ['label' => 'x']);

_sltAssert(setlistTemplateSlotsExceedCap($atCap) === false, 'exactly at cap is NOT over cap (the boundary)');
_sltAssert(setlistTemplateSlotsExceedCap($overCap) === true, 'cap+1 IS over cap');
_sltAssert(setlistTemplateSlotsExceedCap([]) === false, 'an empty list is not over cap');
_sltAssert(setlistTemplateSlotsExceedCap(null) === false,
    'a NON-ARRAY is not over cap — it means "no plan", which must never 413');
_sltAssert(setlistTemplateSlotsExceedCap($overCap, 5000) === false, 'the cap override is honoured (test seam works)');

/* Whole-envelope cap, asked once per set list regardless of which shape came in. */
_sltAssert(setlistTemplatePlanExceedsCap(['slots' => $overCap]) === true, 'an over-cap ENVELOPE is detected');
_sltAssert(setlistTemplatePlanExceedsCap($overCap) === true, 'an over-cap BARE list is detected');
_sltAssert(setlistTemplatePlanExceedsCap(['slots' => $atCap]) === false, 'an at-cap envelope is accepted');
_sltAssert(setlistTemplatePlanExceedsCap(null) === false, 'a null plan is not over cap');
_sltAssert(setlistTemplatePlanExceedsCap(['templateId' => 1]) === false, 'an envelope with no slots key is not over cap');

/* THE CENTRAL PROPERTY: this module owns no array_slice. An over-cap payload
   sanitises to a LONG list, so the caller cannot accidentally store a
   silently-shortened one — it has to make a decision. */
_sltAssert(
    count(setlistTemplateSanitiseSlots($overCap)) === $cap + 1,
    'the sanitiser does NOT slice an over-cap list — the caller must reject it (the #1649/#1662 lesson)'
);

/* ====================================================================== *
 * 6. AUTHORISATION — asked as a question, not grepped for as a word
 * ====================================================================== */

echo "\n-- setlistTemplateCanEdit() --\n";

_sltAssert(setlistTemplateCanEdit(7, 7) === true,  'the author may edit their own template');
_sltAssert(setlistTemplateCanEdit(7, 8) === false, 'another user may NOT edit it');
_sltAssert(setlistTemplateCanEdit(null, 7) === false,
    'an orphaned template (author deleted, CreatedBy NULL) is editable by NOBODY — never by everybody');
_sltAssert(setlistTemplateCanEdit(0, 0) === false, 'a zero author id is not a match for a zero caller id');
_sltAssert(setlistTemplateCanEdit(7, 0) === false, 'an anonymous caller (id 0) may not edit anything');
_sltAssert(setlistTemplateCanEdit(-1, -1) === false, 'negative ids never match');

/* ====================================================================== *
 * 7. STRUCTURAL — only the things with NO runtime handle here
 * ====================================================================== */

echo "\n-- api.php wiring (no runtime handle without MySQL) --\n";

$apiSrc = (string)file_get_contents(dirname(__DIR__, 2) . '/appWeb/public_html/api.php');
_sltAssert(strlen($apiSrc) > 100000, 'api.php was read (a failed read must not read as "all clean")');

/**
 * Strip comments so no assertion below can be satisfied by PROSE. Two guards in
 * this repo shipped wrong-but-green by matching a doc-comment that merely NAMED
 * the function being checked for (#1668, #1679); this file legitimately
 * discusses `array_slice` in its own comments while no longer calling it, which
 * is exactly that trap.
 */
function _sltStrip(string $src): string
{
    $out = '';
    foreach (token_get_all($src) as $tok) {
        if (is_array($tok)) {
            if ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT) {
                $out .= "\n";
                continue;
            }
            $out .= $tok[1];
        } else {
            $out .= $tok;
        }
    }
    return $out;
}
$apiNoComments = _sltStrip($apiSrc);

/* THE BIND_PARAM ARITY CHECK. A wrong type-string length is an
   ArgumentCountError the moment a real client syncs, and nothing in this
   container can reach it — no MySQL, no prepare(). So it is derived from the
   source: for every bind_param on the plan-writing statement, the number of
   type characters must equal the number of bound values.
   ⚠️ Derived by COUNTING, not by naming the expected strings — a hardcoded
   expectation would pass while the statement it describes changed underneath. */
$bindHits = [];
preg_match_all(
    '/\$upsertPlan->bind_param\(\s*\'([a-z]+)\'\s*,((?:[^;]|\n)*?)\)\s*;/',
    $apiNoComments,
    $bindHits,
    PREG_SET_ORDER
);
_sltAssert(count($bindHits) === 2, 'both $upsertPlan->bind_param() calls were found (found ' . count($bindHits) . ')');
foreach ($bindHits as $i => $hit) {
    $typeLen  = strlen($hit[1]);
    $valueCnt = count(array_filter(array_map('trim', explode(',', $hit[2])), static fn($s) => $s !== ''));
    _sltAssert(
        $typeLen === $valueCnt,
        "\$upsertPlan bind_param #$i: '{$hit[1]}' has $typeLen type chars for $valueCnt values"
    );
}

/* …and the type string must match the statement's own placeholder count, which
   is the other half of the same mistake. The INSERT column list and the VALUES
   list must agree too, or MySQL rejects the prepare outright. */
preg_match_all(
    '/INSERT INTO tblUserSetlists \(([^)]*)\)\s*\n\s*VALUES \(([^)]*)\)/',
    $apiNoComments,
    $stmtHits,
    PREG_SET_ORDER
);
_sltAssert(count($stmtHits) >= 2, 'the tblUserSetlists upsert statements were found (found ' . count($stmtHits) . ')');
foreach ($stmtHits as $i => $hit) {
    $cols   = count(array_filter(array_map('trim', explode(',', $hit[1])), static fn($s) => $s !== ''));
    $marks  = substr_count($hit[2], '?');
    _sltAssert($cols === $marks, "tblUserSetlists INSERT #$i binds $marks placeholders for $cols columns");
}

/* THE TRUNCATION MUST BE GONE from the template save path. This is the change
   that makes the cap a rejection, and its absence is not visible from any pure
   function — the handler could quietly re-grow one. */
$tplSave = null;
if (preg_match("/case 'setlist_template_save':([\s\S]*?)\n            break;/", $apiNoComments, $m)) {
    $tplSave = $m[1];
}
_sltAssert($tplSave !== null, "the setlist_template_save case body was located");
if ($tplSave !== null) {
    _sltAssert(!str_contains($tplSave, 'array_slice'),
        'setlist_template_save no longer array_slice()s the payload (the #1649/#1662 shape)');
    _sltAssert(str_contains($tplSave, 'setlistTemplateSlotsExceedCap('),
        'setlist_template_save asks the shared cap question');
    _sltAssert(str_contains($tplSave, 'setlistTemplateSanitiseSlots('),
        'setlist_template_save uses the ONE shared slot sanitiser, not a local copy');
    _sltAssert(str_contains($tplSave, '413'),
        'setlist_template_save answers 413 rather than storing a shortened plan');
}

/* PRESENCE-OF-KEY, not isset(). This is the decision that stops a native app —
   which will never send `plan` — from wiping a plan built on the web. isset()
   would make `plan: null` and an absent `plan` the same message, which is both
   halves of the bug at once. */
_sltAssert(
    (bool)preg_match('/\$planStatement\s*=\s*\(\$upsertPlan !== null && array_key_exists\(\'plan\', \$list\)\)/', $apiNoComments),
    'the sync loop chooses the plan-writing statement by array_key_exists(), never isset()'
);

/* The plan-agnostic $upsert must NEVER name SlotsJson: that is what makes it
   PRESERVE a stored plan for a client that said nothing about plans. If
   somebody "tidies" SlotsJson into the shared statement, every native-app sync
   silently clears the web client's service plans — and nothing would go red
   without this.

   Scoped to the user_setlists_sync CASE BODY, because `$upsert` is also the
   variable name in the favourites handler; a whole-file scan would be reading
   the wrong statement and reporting confidently about it. The SQL literal is
   captured directly rather than bounded by whatever happens to follow, which is
   the mistake test-editor-deep-links.js made twice while showing green (#1680). */
$syncBodyStart = strpos($apiNoComments, "case 'user_setlists_sync':");
_sltAssert($syncBodyStart !== false, "the user_setlists_sync case body was located");
if ($syncBodyStart !== false) {
    $nextCase  = strpos($apiNoComments, "\n        case '", $syncBodyStart + 10);
    $syncBody  = substr($apiNoComments, $syncBodyStart, ($nextCase !== false ? $nextCase : strlen($apiNoComments)) - $syncBodyStart);

    preg_match_all("/\\\$upsert = \\\$db->prepare\(\s*'([^']*)'\s*\)/", $syncBody, $plainHits, PREG_SET_ORDER);
    _sltAssert(count($plainHits) > 0,
        'the plan-agnostic $upsert prepare statements were found (found ' . count($plainHits) . ')');
    foreach ($plainHits as $i => $hit) {
        _sltAssert(!str_contains($hit[1], 'SlotsJson'),
            "plan-agnostic \$upsert #$i never names SlotsJson (so it PRESERVES a stored plan)");
    }

    /* The sync path's own cap must be ENFORCED, not merely present. A mutation
       wrapping it in `if (false && …)` left the call, the constant and the 413
       branch all in the file and this section GREEN until this assertion
       existed — the same "vocabulary survived, behaviour did not" shape as the
       authorisation check above. The condition must therefore start with the
       predicate rather than merely contain it. */
    _sltAssert(
        (bool)preg_match(
            '/if\s*\(\s*is_array\(\$planCheck\)\s*&&\s*setlistTemplatePlanExceedsCap\([\s\S]{0,600}?413\s*\)/',
            $syncBody
        ),
        'user_setlists_sync ENFORCES the slot cap and answers 413 (not a disabled branch)'
    );
    /* …and it must run BEFORE anything is written, so an over-size plan leaves
       every stored set list untouched. Position, because "we rejected it after
       writing half the rows" is a different and much worse behaviour. */
    $capPos    = strpos($syncBody, 'setlistTemplatePlanExceedsCap(');
    $upsertPos = strpos($syncBody, '$db->prepare(');
    _sltAssert(
        $capPos !== false && $upsertPos !== false && $capPos < $upsertPos,
        'the slot-cap rejection happens BEFORE any statement is prepared or executed'
    );

    /* …and it must be asked ABOUT THE PAYLOAD. A mutation that changed the loop
       to `foreach ([] as $planCheck)` left the predicate, the 413 and the
       ordering all intact — a cap enforced over an empty collection is not
       enforced at all.
       ⚠️ THE FIRST TWO DRAFTS OF THIS ASSERTION WERE BOTH GREEN AGAINST THAT
       MUTATION. "Does `$localLists` appear nearby?" passed because the
       assignment `$localLists = $body['setlists'];` sits ~150 characters above,
       inside the same window. Proximity is not containment. So this walks back
       to the NEAREST enclosing iteration and asks what IT iterates — which is
       the actual question — while still accepting an equivalent array_filter /
       array_map rewrite over the same variable, because a guard so tight it
       fails on correct code gets weakened or deleted rather than fixed. */
    $beforeCap = substr($syncBody, 0, (int)$capPos);
    preg_match_all('/(?:foreach|array_filter|array_map)\s*\(\s*([^\s,;)]+)/', $beforeCap, $iterHits);
    $nearestIterated = $iterHits[1] ? end($iterHits[1]) : '(none)';
    /* Matched as "names $localLists", not "equals $localLists": an equivalent
       rewrite iterating `array_filter($localLists, …)` is correct code and
       must stay green, while `[]` — the mutation — names nothing. */
    _sltAssert(
        (bool)preg_match('/\$localLists\b/', $nearestIterated),
        'the slot cap iterates the PAYLOAD ($localLists); nearest enclosing iteration reads: ' . $nearestIterated
    );

    preg_match_all("/\\\$upsertPlan = \\\$db->prepare\(\s*'([^']*)'\s*\)/", $syncBody, $planHits, PREG_SET_ORDER);
    _sltAssert(count($planHits) > 0,
        'the plan-writing $upsertPlan prepare statements were found (found ' . count($planHits) . ')');
    foreach ($planHits as $i => $hit) {
        _sltAssert(str_contains($hit[1], 'SlotsJson = VALUES(SlotsJson)'),
            "\$upsertPlan #$i writes SlotsJson on duplicate key (otherwise a plan can never be UPDATED)");
    }
}

/* Both new endpoints must exist, and each must REFUSE on the shared
   authorisation question.
   ⚠️ THIS WAS WRONG-BUT-GREEN ON ITS FIRST DRAFT. It counted occurrences of
   `setlistTemplateCanEdit(` and passed a mutation that changed the guard to
   `if (false && !setlistTemplateCanEdit(…))` — the call was still THERE, so the
   count was still right, while every caller could edit every template. Counting
   a call proves vocabulary; the shape of the refusal is the behaviour. Now the
   negation and the 403 are both required, inside the case body that owns them. */
foreach (['setlist_template_update', 'setlist_template_delete'] as $case) {
    $start = strpos($apiNoComments, "case '$case':");
    _sltAssert($start !== false, "api.php dispatches $case");
    if ($start === false) {
        continue;
    }
    $next = strpos($apiNoComments, "\n        case '", $start + 10);
    $body = substr($apiNoComments, $start, ($next !== false ? $next : strlen($apiNoComments)) - $start);

    /* #1698 — the refusal grew a SECOND disjunct: an admin holding
       `manage_setlist_templates` may edit or delete a template they did not
       create. That is the one case `setlistTemplateCanEdit()` structurally
       cannot answer — its own doc-block recorded it as a known, deliberately-
       unbuilt gap, because `fk_Template_User` is ON DELETE SET NULL and a public
       template whose author's account closed was editable by NOBODY.

       So the shape asserted is now "refuse unless the override OR authorship
       says yes", with BOTH halves required. Asserting only the authorship half
       would pass a build that had dropped the override (an admin left unable to
       clean up an orphan); asserting only the override half would pass a build
       where every caller can edit every template. */
    _sltAssert(
        str_contains($body, 'setlistTemplateCanEdit('),
        "$case still asks the shared authorship question"
    );
    _sltAssert(
        (bool)preg_match(
            '/if\s*\(\s*!\$tplCanManageAny\s*&&\s*!\s*(?:setlistTemplateCanEdit\(|\$tplIsOwnRow)/',
            $body
        ),
        "$case REFUSES on !override && !author — not merely mentions either"
    );
    _sltAssert(
        (bool)preg_match(
            '/if\s*\(\s*!\$tplCanManageAny\s*&&\s*!\s*(?:setlistTemplateCanEdit\(|\$tplIsOwnRow)[\s\S]{0,400}?\]\s*,\s*403\s*\)/',
            $body
        ),
        "$case answers 403 when both halves of the authorisation question say no"
    );
    /* The override must be RESOLVED from the entitlement, not from a role — a
       role check is not editable at /manage/entitlements (rule #1587). */
    _sltAssert(
        str_contains($body, "userHasEntitlement('manage_setlist_templates'"),
        "$case resolves the override from the manage_setlist_templates entitlement"
    );
    _sltAssert(str_contains($body, '404'), "$case answers 404 for a template that does not exist");
    /* The write is narrowed by CreatedBy as well as by Id, so the check and the
       write cannot be separated by anything that slips between them. */
    _sltAssert(
        (bool)preg_match('/(UPDATE|DELETE FROM) tblSetlistTemplates[\s\S]{0,400}?WHERE Id = \? AND CreatedBy = \?/', $body),
        "$case's write is narrowed by CreatedBy in the WHERE, not by the earlier check alone"
    );
}
/* The list handler states canEdit so a client never re-derives the policy. */
_sltAssert(
    (bool)preg_match('/\$tpl\[\'canEdit\'\]\s*=\s*setlistTemplateCanEdit\(/', $apiNoComments),
    'setlist_templates emits canEdit from the shared predicate (the client never re-derives the policy)'
);

/* EVERY read of the optional column must sit behind the schema gate, or an
   un-migrated install white-screens under MYSQLI_REPORT_STRICT (#1228).
   ⚠️ ALSO WRONG-BUT-GREEN ON ITS FIRST DRAFT: there are TWO `$slotsSelect`
   assignments (the read handler's and the sync handler's) and a single
   preg_match was satisfied by whichever one was still correct — so ungating the
   OTHER one passed. Derived and checked exhaustively now; a third assignment
   added tomorrow is covered automatically. */
preg_match_all('/\$slotsSelect\s*=\s*([^;]*);/', $apiNoComments, $selHits, PREG_SET_ORDER);
_sltAssert(count($selHits) >= 2, 'both $slotsSelect assignments were found (found ' . count($selHits) . ')');
foreach ($selHits as $i => $hit) {
    $rhs = $hit[1];
    _sltAssert(
        (bool)preg_match('/^\s*(setlistSlotsColumnReady\([^\n]*\)|\$slotsReady)\s*\?\s*\', SlotsJson\'\s*:\s*\'\'\s*$/', $rhs),
        "\$slotsSelect assignment #$i adds ', SlotsJson' ONLY under the schema gate, from a hardcoded constant (rule #5)"
    );
}
_sltAssert(
    (bool)preg_match('/if\s*\(\$slotsReady\)\s*\{[\s\S]{0,600}?INSERT INTO tblUserSetlists[^;]*SlotsJson/', $apiNoComments),
    'the SlotsJson-writing upsert is prepared only inside an if ($slotsReady) branch'
);

/* The gate itself must be a real INFORMATION_SCHEMA probe scoped to this
   database AND filtered by name — a probe reduced to `1=1` still mentions the
   catalogue table while always answering yes (mutation M10's lesson, #1661). */
$modSrc = _sltStrip((string)file_get_contents(
    dirname(__DIR__, 2) . '/appWeb/public_html/includes/setlist_templates.php'
));
_sltAssert(
    (bool)preg_match(
        '/function setlistSlotsColumnReady\([\s\S]{0,900}?INFORMATION_SCHEMA\.COLUMNS[\s\S]{0,400}?'
        . preg_quote("COLUMN_NAME = 'SlotsJson'", '/') . '/',
        $modSrc
    ),
    'setlistSlotsColumnReady() probes INFORMATION_SCHEMA.COLUMNS AND filters on COLUMN_NAME'
);
_sltAssert(str_contains($modSrc, 'TABLE_SCHEMA = DATABASE()'),
    'the schema gate is scoped to the current DATABASE()');

/* The module must own no slicer. This is the file-level version of §5's
   behavioural assertion, and it is worth both: the behaviour proves today's
   sanitiser does not truncate, this proves nobody adds one tomorrow. */
_sltAssert(!str_contains($modSrc, 'array_slice'),
    'includes/setlist_templates.php contains no array_slice (caps are rejections)');

/* ====================================================================== *
 * 8. SCHEMA / MIGRATION — the byte-identical mirror (rule #19)
 * ====================================================================== */

echo "\n-- migration / schema.sql mirror --\n";

$sqlDir    = dirname(__DIR__, 2) . '/appWeb/.sql';
$migSrc    = (string)@file_get_contents($sqlDir . '/migrate-setlist-slots.php');
$schemaSql = (string)file_get_contents($sqlDir . '/schema.sql');

_sltAssert($migSrc !== '', 'migrate-setlist-slots.php exists');

$decl = "SlotsJson JSON NULL DEFAULT NULL COMMENT 'Optional service plan (#301/#1671 F4): {templateId, templateName, slots[]}. NULL = no plan'";
_sltAssert(
    str_contains($migSrc, $decl) && str_contains($schemaSql, $decl),
    'migration and schema.sql agree BYTE-FOR-BYTE on the SlotsJson declaration (rule #19)'
);
_sltAssert(str_contains($migSrc, '@migration-adds tblUserSetlists.SlotsJson'),
    'the migration carries its @migration-adds doctag');

$registrySrc = (string)file_get_contents(
    dirname(__DIR__, 2) . '/appWeb/public_html/manage/includes/migration-registry.php'
);
_sltAssert(str_contains($registrySrc, "'migrate-setlist-slots.php'"),
    'the migration is registered in migration-registry.php');
_sltAssert(
    (bool)preg_match("/'setlist-slots'[\s\S]{0,3000}?'probe'[\s\S]{0,400}?'SlotsJson'/", $registrySrc),
    'the registry probe names the column it is probing for (never a static true)'
);

echo "\n$passed passed, $failures failed.\n";
exit($failures > 0 ? 1 : 0);
