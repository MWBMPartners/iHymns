<?php

declare(strict_types=1);

/**
 * iHymns — OpenLyrics `<lines part="…" repeat="…">` is honoured on import,
 * not silently discarded (#2071)
 *
 * ELI5: OpenLyrics lets one `<verse>` carry SEVERAL `<lines>` blocks, and
 * lets any one of them say WHO sings it (`part="women"`) or how many times
 * it repeats (`repeat="3"`). Before this fix, iHymns stripped the whole
 * opening `<lines …>` tag — attributes and all — before it ever looked at
 * it, so three voice groups in one verse (issue #2071's own worked
 * example: women / men / all) silently flattened into one undifferentiated
 * block with no trace either word ever existed. This file proves that no
 * longer happens: a `part=` attribute becomes a real voice-part assignment
 * on the affected lines, a `repeat=` attribute survives as a human-readable
 * note (never as N literal copies of the text — that would change what the
 * song says), an attribute-less `<lines>` block — the shape iHymns' OWN
 * exporter writes for an ordinary slide chunk — is untouched, and a
 * document that never uses either attribute parses to the EXACT
 * byte-identical shape it always did.
 *
 * TWO KINDS OF PROOF, matching this task's own house style
 * (tests/php/test-importer-voice-markers.php, #2075):
 *   1. STRUCTURAL (rule #34) — the resolver function this fix introduces is
 *      derived from the real file (not hand-typed) and is proven to
 *      actually get called from the real `<verse>` loop, with a MUTATION
 *      check proving the extractor itself is not vacuously true.
 *   2. FUNCTIONAL — the real parser is fed real OpenLyrics XML (including
 *      the issue's own women/men/all example) and the resulting component
 *      array is asserted field-by-field.
 *
 *   php tests/php/test-openlyrics-voice-parts.php
 *
 * Exit status 0 = all assertions pass, 1 = at least one failed.
 *
 * @see appWeb/public_html/includes/song_importers.php   the file under test
 * @see appWeb/public_html/includes/vocal_parts.php       vocalPartsKindFromWord() — the ONE shared resolver this fix reuses
 * @see https://github.com/MWBMPartners/iHymns/issues/2071  the bug this file proves fixed
 * @see https://docs.openlyrics.org/en/latest/dataformat.html#lines  the `part`/`repeat` attributes, straight from the spec
 * @see .claude/vocal-parts-2073-plan.md                  the plan of record ("Design pass 7" §10, commit 11 / "Design pass 6" §3)
 */

$root = dirname(__DIR__, 2);
require $root . '/appWeb/public_html/includes/song_importers.php';

$passed = 0;
$failed = 0;

function ok(string $label, bool $cond, string $detail = ''): void
{
    global $passed, $failed;
    if ($cond) {
        echo "  PASS  $label\n";
        $passed++;
    } else {
        echo "  FAIL  $label\n";
        if ($detail !== '') {
            echo "        $detail\n";
        }
        $failed++;
    }
}

function assertEq($actual, $expected, string $label): void
{
    ok($label, $actual === $expected, 'expected: ' . var_export($expected, true) . ' | actual: ' . var_export($actual, true));
}

/**
 * Pull ONE top-level `function <name>(...) { ... }` body out of the
 * importer source by brace-counting — the exact same "derive the span,
 * don't hand-copy it" helper `test-importer-voice-markers.php` already
 * uses, duplicated here (rather than required from that file) so this
 * test has no load-order dependency on a sibling test file (rule #34: a
 * guard must stand on its own).
 */
function extractFunctionBody(string $source, string $functionName): ?string
{
    $needle = 'function ' . $functionName . '(';
    $start  = strpos($source, $needle);
    if ($start === false) {
        return null;
    }
    $braceOpen = strpos($source, '{', $start);
    if ($braceOpen === false) {
        return null;
    }
    $depth = 0;
    $len   = strlen($source);
    for ($i = $braceOpen; $i < $len; $i++) {
        if ($source[$i] === '{') {
            $depth++;
        } elseif ($source[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($source, $start, $i - $start + 1);
            }
        }
    }
    return null;
}

$source = (string)file_get_contents($root . '/appWeb/public_html/includes/song_importers.php');
ok('the importer source file was actually read (guard against a silently-empty scan)', strlen($source) > 10000);

/* ====================================================================== *
 * 1 — STRUCTURAL: the <verse> loop inside _bulkImport_parseOpenLyrics()
 *     actually reads part=/repeat= off the SimpleXML node and routes an
 *     unrecognised part= through the shared resolver — derived from the
 *     real function body, never hand-asserted.
 * ====================================================================== */
echo "1 — structural: the real verse loop wires part=/repeat=\n";

$parseBody = extractFunctionBody($source, '_bulkImport_parseOpenLyrics');
ok('_bulkImport_parseOpenLyrics() was found in the source at all', $parseBody !== null);
ok(
    '…and its body reads the `part` attribute off the <lines> SimpleXML node',
    $parseBody !== null && strpos($parseBody, "linesNode['part']") !== false
);
ok(
    '…and its body reads the `repeat` attribute off the SAME node',
    $parseBody !== null && strpos($parseBody, "linesNode['repeat']") !== false
);
ok(
    '…and an attribute value routes through the ONE shared resolver, never a second word list',
    $parseBody !== null && strpos($parseBody, '_bulkImport_openLyricsResolveVoicePart(') !== false
);
ok(
    '…and a resolved voice actually reaches the component as a `voices` cell',
    $parseBody !== null && strpos($parseBody, "\$comp['voices']") !== false
);

/* MUTATION self-test (rule #34): prove extractFunctionBody() is not
   vacuously true — a made-up function name must come back null, and a
   string search against a body that plainly lacks the needle must fail. */
ok(
    'MUTATION: a made-up function name is correctly reported as NOT found',
    extractFunctionBody($source, '_bulkImport_thisFunctionDoesNotExist_2071') === null
);
ok(
    'MUTATION: searching the WRONG function\'s body for `linesNode[\'part\']` correctly fails',
    strpos((string)extractFunctionBody($source, '_bulkImport_openLyricsLetterMap'), "linesNode['part']") === false
);

$resolverBody = extractFunctionBody($source, '_bulkImport_openLyricsResolveVoicePart');
ok('_bulkImport_openLyricsResolveVoicePart() was found in the source at all', $resolverBody !== null);
ok(
    '…and it calls vocalPartsKindFromWord() (the ONE shared word->kind resolver, rule #22) rather than re-deriving a marker table',
    $resolverBody !== null && strpos($resolverBody, 'vocalPartsKindFromWord(') !== false
);
ok(
    '…and it never writes its own marker/keyword array literal (no duplicated vocabulary, rule #35)',
    $resolverBody !== null && strpos($resolverBody, "'MEN'") === false && strpos($resolverBody, "'WOMEN'") === false
);

/* ====================================================================== *
 * 2 — FUNCTIONAL: the issue's own worked example (women / men / all in
 *     one <verse>) resolves correctly and nothing is silently flattened.
 * ====================================================================== */
echo "\n2 — the issue's own women/men/all example\n";

$olVoices = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<song xmlns="http://openlyrics.info/namespace/2009/song">
  <properties><titles><title>Psalm 91</title></titles></properties>
  <lyrics>
    <verse name="v1">
      <lines part="women">He who dwells, he who dwells<br/>in the shelter of the Most High,</lines>
      <lines part="men">he who dwells, he who dwells<br/>in the shelter of the Most High</lines>
      <lines part="all">And I'll say of the Lord, 'He is my refuge';</lines>
    </verse>
  </lyrics>
</song>
XML;
[$voicesParsed, $voicesErr] = _bulkImport_parseOpenLyrics($olVoices);
ok('the women/men/all document parses without error', $voicesErr === null && $voicesParsed !== null);

$comps = $voicesParsed['components'] ?? [];
/* Count only the NUMERIC component entries — `$comps` also carries the
   non-numeric `_voiceSource` stamp once any part= resolved (see below), and
   plain count() would include it, over-reporting by one. This distinction
   is exactly what caught a real bug during this fix's own development: see
   the <verseOrder>+part= interaction test further down. */
$numericComps = array_filter($comps, 'is_int', ARRAY_FILTER_USE_KEY);
assertEq(count($numericComps), 1, 'the three <lines> blocks fold into ONE component (still one <verse>), not three');
$comp = $comps[0];
assertEq($comp['lines'], [
    'He who dwells, he who dwells',
    'in the shelter of the Most High,',
    'he who dwells, he who dwells',
    'in the shelter of the Most High',
    "And I'll say of the Lord, 'He is my refuge';",
], 'the lyric TEXT is exactly the pre-#2071 flattened five lines — this fix adds a channel, it does not change what is sung');

ok('the component carries a `voices` cell array (the loss #2071 reports is fixed)', array_key_exists('voices', $comp));
$voices = $comp['voices'] ?? [];
assertEq(count($voices), 5, 'one voices[] cell per lyric line');
assertEq($voices[0], [['kind' => 'female', 'label' => null]], 'line 0 ("He who dwells…") is assigned the FEMALE kind (part="women")');
assertEq($voices[1], [['kind' => 'female', 'label' => null]], 'line 1 (still inside the women block) carries the SAME assignment');
assertEq($voices[2], [['kind' => 'male', 'label' => null]], 'line 2 ("he who dwells…", part="men") is assigned the MALE kind');
assertEq($voices[3], [['kind' => 'male', 'label' => null]], 'line 3 (still inside the men block) carries the SAME assignment');
assertEq($voices[4], [['kind' => 'all', 'label' => null]], "line 4 (part=\"all\") is assigned the ALL kind");

ok(
    'the top-level components array is stamped _voiceSource = "openlyrics" (the STRUCTURED-source contract '
    . 'includes/vocal_parts.php IHYMNS_VOCAL_SOURCES_STRUCTURED already reserves)',
    ($voicesParsed['components']['_voiceSource'] ?? null) === 'openlyrics'
);

/* ====================================================================== *
 * 3 — the exporter's OWN shape (attribute-less <lines> chunk blocks) is
 *     completely unaffected — #2071's own "the complication" section.
 * ====================================================================== */
echo "\n3 — the exporter's attribute-less multi-<lines> shape is untouched\n";

$olChunked = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<song xmlns="http://openlyrics.info/namespace/2009/song">
  <properties><titles><title>Chunked</title></titles></properties>
  <lyrics>
    <verse name="v1">
      <lines>Line one<br/>Line two</lines>
      <lines>Line three<br/>Line four</lines>
    </verse>
  </lyrics>
</song>
XML;
[$chunkedParsed] = _bulkImport_parseOpenLyrics($olChunked);
$chunkedComp = ($chunkedParsed['components'] ?? [])[0] ?? null;
ok('an attribute-less multi-<lines> verse still parses to one component', $chunkedComp !== null);
assertEq(
    $chunkedComp['lines'] ?? null,
    ['Line one', 'Line two', 'Line three', 'Line four'],
    'attribute-less <lines> blocks still concatenate exactly as before this fix — discriminating on PART PRESENCE, not on "several <lines> blocks", is the whole point of #2071\'s own "complication" note'
);
ok('an attribute-less component carries NO `voices` key at all (sparse — never present-but-empty)', !array_key_exists('voices', $chunkedComp));
ok(
    '…and the top-level components array carries NO `_voiceSource` stamp either — a part=-free file is byte-identical to today',
    !array_key_exists('_voiceSource', $chunkedParsed['components'] ?? [])
);
assertEq($chunkedParsed['components'] ?? null, [
    ['type' => 'verse', 'number' => 1, 'lines' => ['Line one', 'Line two', 'Line three', 'Line four']],
], 'the WHOLE components array is byte-for-byte identical to the pre-#2071 shape — no stray keys anywhere');

/* ====================================================================== *
 * 4 — repeat="N": survives as a note, never expands the text
 * ====================================================================== */
echo "\n4 — repeat=\"N\" survives as a note, never as N copies of the text\n";

$olRepeat = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<song xmlns="http://openlyrics.info/namespace/2009/song">
  <properties><titles><title>Repeat</title></titles></properties>
  <lyrics>
    <verse name="c">
      <lines repeat="3">Hallelujah<br/>Hallelujah</lines>
    </verse>
  </lyrics>
</song>
XML;
[$repeatParsed] = _bulkImport_parseOpenLyrics($olRepeat);
$repeatComp = ($repeatParsed['components'] ?? [])[0] ?? null;
ok('a repeat="3" verse parses to one component', $repeatComp !== null);
assertEq($repeatComp['lines'] ?? null, ['Hallelujah', 'Hallelujah'], 'repeat="3" does NOT expand into three copies of the text — the exact regression this fix must not introduce');
ok('the component carries a `notes` cell array', array_key_exists('notes', $repeatComp));
assertEq($repeatComp['notes'][0] ?? null, 'Repeat ×3', 'the FIRST line of the block carries the human-readable "Repeat ×3" note');
assertEq($repeatComp['notes'][1] ?? null, '', 'the SECOND line of the SAME block carries no note of its own (the count is stated once, on the block)');
ok('a repeat-only verse carries NO `voices` key (repeat is not a voice assignment)', !array_key_exists('voices', $repeatComp));
ok('a repeat-only DOCUMENT carries no `_voiceSource` stamp (that stamp is for `part=`, not `repeat=`)', !array_key_exists('_voiceSource', $repeatParsed['components'] ?? []));

/* repeat= out-of-range values are ignored, never guessed at. */
foreach (['1' => 'below the 2..99 floor', '100' => 'above the 2..99 ceiling', 'abc' => 'not a digit string', '' => 'empty attribute'] as $bad => $why) {
    $badXml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<song xmlns="http://openlyrics.info/namespace/2009/song">
  <properties><titles><title>Bad repeat</title></titles></properties>
  <lyrics><verse name="c"><lines repeat="{$bad}">Only line</lines></verse></lyrics>
</song>
XML;
    [$badParsed] = _bulkImport_parseOpenLyrics($badXml);
    $badComp = ($badParsed['components'] ?? [])[0] ?? null;
    ok(
        "repeat=\"{$bad}\" ({$why}) is ignored — no note is fabricated from it",
        $badComp !== null && !array_key_exists('notes', $badComp)
    );
}

/* ====================================================================== *
 * 5 — part= resolution: markers, ordinals, and the never-drop fallback
 * ====================================================================== */
echo "\n5 — _bulkImport_openLyricsResolveVoicePart() resolution table\n";

assertEq(_bulkImport_openLyricsResolveVoicePart('women'), ['kind' => 'female', 'label' => null], 'the OpenLyrics 0.8 spec keyword "women" resolves to the female kind');
assertEq(_bulkImport_openLyricsResolveVoicePart('MEN'), ['kind' => 'male', 'label' => null], 'case is irrelevant — "MEN" resolves the same as "men"');
assertEq(_bulkImport_openLyricsResolveVoicePart('all'), ['kind' => 'all', 'label' => null], 'the spec keyword "all" resolves to the all kind');
assertEq(_bulkImport_openLyricsResolveVoicePart('solo'), ['kind' => 'soloist', 'label' => 'Solo'], 'the spec keyword "solo" resolves to soloist WITH its marker override label "Solo"');
assertEq(_bulkImport_openLyricsResolveVoicePart('Cantor'), ['kind' => 'cantor', 'label' => null], '"Cantor" (issue #2071\'s own example of arbitrary text) resolves to the cantor kind');
assertEq(_bulkImport_openLyricsResolveVoicePart('Part 1'), ['kind' => 'group', 'label' => 'Group 1'], '"Part 1" (issue #2071\'s own example) resolves to an ordinal group');
assertEq(_bulkImport_openLyricsResolveVoicePart('Group 2'), ['kind' => 'group', 'label' => 'Group 2'], '"Group 2" resolves to the second ordinal group');

/* Genuinely unrecognised text: NEVER dropped, NEVER null — the weakest,
   still-lossless claim (kind=group, the raw word as the label). */
assertEq(
    _bulkImport_openLyricsResolveVoicePart('Femmes'),
    ['kind' => 'group', 'label' => 'Femmes'],
    'a non-English term (issue #2071\'s own example) is NEVER dropped — it survives verbatim as a group label'
);
assertEq(
    _bulkImport_openLyricsResolveVoicePart('john smith'),
    ['kind' => 'group', 'label' => 'John smith'],
    'an arbitrary person\'s name (free text the spec explicitly allows) survives as a group label, first letter upper-cased'
);
$longWord = str_repeat('A', 130);
$resolvedLong = _bulkImport_openLyricsResolveVoicePart($longWord);
ok('an absurdly long part= value is clipped to 120 code points, never overflowed into the DB column', mb_strlen((string)($resolvedLong['label'] ?? ''), 'UTF-8') === 120);
assertEq(_bulkImport_openLyricsResolveVoicePart(''), ['kind' => 'group', 'label' => null], 'an empty string resolves to a bare group with no label (defensive — callers never actually pass this)');

/* ====================================================================== *
 * 6 — <verseOrder> identity-suppression (#2062) still works once a
 *     document ALSO uses part=. This is a real bug this fix's own
 *     development caught: the _voiceSource stamp is a NON-numeric key
 *     sitting inside the same array count($components) sees, and
 *     count($components) is genuinely part of #2062's identity-suppression
 *     maths (`range(0, count($components) - 1)`) — stamping it before that
 *     block ran made count() over-report by one and broke suppression for
 *     any song combining <verseOrder> with part=. Proven by regression
 *     here so it can never silently come back.
 * ====================================================================== */
echo "\n6 — <verseOrder> identity suppression survives a part=-bearing document\n";

$olOrderAndVoice = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<song xmlns="http://openlyrics.info/namespace/2009/song">
  <properties>
    <titles><title>Order + voice</title></titles>
    <verseOrder>v1 c</verseOrder>
  </properties>
  <lyrics>
    <verse name="v1"><lines part="women">Verse line</lines></verse>
    <verse name="c"><lines>Chorus line</lines></verse>
  </lyrics>
</song>
XML;
[$orderVoiceParsed] = _bulkImport_parseOpenLyrics($olOrderAndVoice);
ok(
    'a NATURAL-order <verseOrder> ("v1 c" over exactly 2 components, in order) is still IDENTITY-SUPPRESSED '
    . '(no arrangement key) even though the document also uses part= — this is the exact bug this fix\'s own '
    . 'development caught and had to correct',
    !array_key_exists('arrangement', $orderVoiceParsed)
);
ok(
    '…and the part= assignment itself still resolved correctly alongside the fix',
    ($orderVoiceParsed['components'][0]['voices'][0][0]['kind'] ?? null) === 'female'
);

/* A NON-identity order (chorus before verse) alongside part= must still
   produce a real, correctly-indexed arrangement — proving the fix did not
   overcorrect into "never emit an arrangement when voices are present". */
$olNonIdentityOrderAndVoice = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<song xmlns="http://openlyrics.info/namespace/2009/song">
  <properties>
    <titles><title>Reordered + voice</title></titles>
    <verseOrder>c v1</verseOrder>
  </properties>
  <lyrics>
    <verse name="v1"><lines part="women">Verse line</lines></verse>
    <verse name="c"><lines>Chorus line</lines></verse>
  </lyrics>
</song>
XML;
[$nonIdentityParsed] = _bulkImport_parseOpenLyrics($olNonIdentityOrderAndVoice);
assertEq(
    $nonIdentityParsed['arrangement'] ?? null,
    [1, 0],
    'a genuinely non-natural <verseOrder> ("c v1") alongside part= still produces the correct [1,0] arrangement — the fix did not overcorrect into suppressing every arrangement'
);

echo "\n" . ($failed === 0 ? "All {$passed} OpenLyrics voice-part assertions passed.\n" : "{$passed} passed, {$failed} FAILED.\n");
exit($failed === 0 ? 0 : 1);
