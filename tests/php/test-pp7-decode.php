<?php

declare(strict_types=1);

/**
 * iHymns — ProPresenter 7+ decoder cross-validation + field-table lockstep guard (#1968 PR-1)
 * ================================================================================================
 *
 * ELI5
 * ----
 * Two completely different pieces of code both try to read the same real ProPresenter files:
 * a hand-written PHP byte-reader (`includes/propresenter7_decode.php`) and a Node script driven
 * off Google's own protobuf reflection library (`tools/pp7-gen-expected.js`, whose output is
 * committed at `tests/fixtures/propresenter/expected/*.decode.json`). This file's first job is
 * simply: do they agree, on every real fixture? If yes, the PHP decoder is very unlikely to be
 * silently wrong — two independent implementations agreeing on real third-party files is a much
 * stronger signal than "our own code round-trips its own output," which is exactly the
 * false-positive trap the owner flagged for this epic (`.claude/propresenter-interop-1968-plan.md`
 * §0/§8: two prior export fixes shipped green on a circular same-schema round-trip, then failed
 * in real ProPresenter).
 *
 * This file's SECOND job is a "does the map still match the territory?" check: every field
 * number in `includes/propresenter7_decode.php`'s constant tables carries an inline
 * `// file.proto:line` citation. This test re-opens each cited `.proto` file, reads the cited
 * line, and confirms the field name + number the decoder claims are still what that exact line
 * of the vendored schema says — so an accidental edit to a field-number table (or the vendored
 * schema being regenerated out from under it) is caught here, not by a silently wrong decode
 * three layers downstream. See plan §11.3.
 *
 * NEITHER job needs a database — both the decoder and this test are pure functions of bytes on
 * disk (mirrors `song_similarity.php`'s "framework-free" posture).
 *
 * MUTATION-PROVEN (rule #34) — performed once, by hand, at authoring time, then reverted:
 *   - Changed PP7_FIELDS_GRAPHICS_TEXT's 'rtf_data' entry from `5` to `6` in
 *     includes/propresenter7_decode.php (both the field-number value AND left it citing the
 *     same "graphicsData.proto:208" comment, so only the DECODE behaviour changed, not the
 *     lockstep citation) -> section (a) went RED on every fixture with non-empty lyric text
 *     (rtfBase64 came back '' instead of the real RTF, since field 6 is a different field
 *     entirely) AND section (b) went RED independently ("PP7_FIELDS_GRAPHICS_TEXT.rtf_data = 6
 *     but graphicsData.proto:208 declares rtf_data = 5" — the lockstep guard catching the SAME
 *     mutation from the schema-citation side, proving the two sections are independently
 *     load-bearing, not the same assertion in disguise). Reverted -> both green again.
 *   - Separately (lockstep-only): edited ONE trailing citation comment (Action.ActionType's
 *     'ACTION_TYPE_MEDIA' entry) from `action.proto:33` to `action.proto:34` (a real line in
 *     the file, `ACTION_TYPE_TIMER = 3;`) without touching the array's own value -> section (a)
 *     stayed GREEN (decode behaviour is untouched — the constant's VALUE didn't change) while
 *     section (b) went RED ("action.proto:34 declares 'ACTION_TYPE_TIMER' = 3, but
 *     PP7_ACTION_TYPE_VALUES cites it for 'ACTION_TYPE_MEDIA' => 2" — a citation drifting from
 *     its own value with nothing else catching it is exactly the failure mode this guard
 *     exists for). Reverted -> green.
 *
 * Usage:
 *   php tests/php/test-pp7-decode.php
 *
 * Exit status: 0 = all pass, 1 = at least one failure.
 *
 * @see .claude/propresenter-interop-1968-plan.md   §8.3(a) (this test's brief), §11.3 (the lockstep guard)
 * @see includes/propresenter7_decode.php            the decoder under test
 * @see tools/pp7-gen-expected.js                     generates tests/fixtures/propresenter/expected/*.decode.json
 * @see tests/fixtures/propresenter/README.md         fixture provenance + licensing
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 */

require_once dirname(__DIR__, 2) . '/appWeb/public_html/includes/propresenter7_decode.php';

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

echo "\n#1968 PR-1 — ProPresenter 7+ decoder cross-validation + field-table lockstep\n\n";

/* ============================================================================================
 * (a) DECODE CROSS-VALIDATION — the PHP walker vs. protobufjs, on real third-party files
 * ============================================================================================ */

echo "-- (a) decoder cross-validation against protobufjs-derived expected output --\n";

$fixturesDir = dirname(__DIR__, 2) . '/tests/fixtures/propresenter';
$expectedDir = $fixturesDir . '/expected';

/**
 * Project pp7DecodePresentation()'s native return shape (parallel slideRtf[]/
 * slideElementInfos[] arrays per cue, per the §2.1 contract) into the SAME reduced shape
 * tools/pp7-gen-expected.js emits, so the two can be compared directly:
 *   { name, selectedArrangement, arrangements, cueGroups, cues:[{uuid,elements:[{info,rtfBase64}]}], ccli }
 */
function pp7ProjectForComparison(array $decoded): array
{
    $cues = [];
    foreach ($decoded['cues'] as $cue) {
        $elements = [];
        $n = count($cue['slideRtf']);
        for ($i = 0; $i < $n; $i++) {
            $elements[] = [
                'info'      => $cue['slideElementInfos'][$i] ?? 0,
                'rtfBase64' => base64_encode($cue['slideRtf'][$i]),
            ];
        }
        $cues[] = ['uuid' => $cue['uuid'], 'elements' => $elements];
    }

    $arrangements = array_map(
        static fn(array $a): array => ['uuid' => $a['uuid'], 'name' => $a['name'], 'groupIdentifiers' => $a['groupIdentifiers']],
        $decoded['arrangements']
    );

    $cueGroups = array_map(
        static fn(array $g): array => ['groupUuid' => $g['groupUuid'], 'groupName' => $g['groupName'], 'cueIdentifiers' => $g['cueIdentifiers']],
        $decoded['cueGroups']
    );

    return [
        'name'                => $decoded['name'],
        'selectedArrangement' => $decoded['selectedArrangement'],
        'arrangements'        => $arrangements,
        'cueGroups'           => $cueGroups,
        'cues'                => $cues,
        'ccli'                => $decoded['ccli'],
    ];
}

/**
 * Find the first point at which two (already-decoded) values disagree, as a human-readable
 * dotted/bracketed path — so a failure prints something actionable instead of two enormous
 * JSON blobs. Returns null when the values are equal.
 */
function pp7FirstDiffPath($a, $b, string $path = '$'): ?string
{
    if (is_array($a) && is_array($b)) {
        $keysA = array_keys($a);
        $keysB = array_keys($b);
        if ($keysA !== $keysB) {
            return "{$path} (keys differ: [" . implode(',', $keysA) . '] vs [' . implode(',', $keysB) . '])';
        }
        foreach ($a as $k => $v) {
            $sub = is_int($k) ? "{$path}[{$k}]" : "{$path}.{$k}";
            $diff = pp7FirstDiffPath($v, $b[$k], $sub);
            if ($diff !== null) {
                return $diff;
            }
        }
        return null;
    }
    if ($a === $b) {
        return null;
    }
    $av = is_string($a) && strlen($a) > 80 ? substr($a, 0, 80) . '…' : var_export($a, true);
    $bv = is_string($b) && strlen($b) > 80 ? substr($b, 0, 80) . '…' : var_export($b, true);
    return "{$path} (got {$av}, expected {$bv})";
}

$proFixtures = glob($fixturesDir . '/*.pro') ?: [];
sort($proFixtures);

// Coverage floor (rule #34's under-report clause): guards against the glob silently matching
// fewer files than the corpus actually has (e.g. a bad extension filter, a moved directory).
ok('at least 11 committed .pro fixtures exist to cross-validate against (found ' . count($proFixtures) . ')',
    count($proFixtures) >= 11);

foreach ($proFixtures as $proPath) {
    $base = basename($proPath, '.pro');
    $expectedPath = $expectedDir . '/' . $base . '.decode.json';

    // A fixture without a matching expected file is a coverage gap, not a skip — fail loudly
    // rather than silently decoding nothing for it (rule #34: "a fixture without one FAILS").
    if (!is_file($expectedPath)) {
        ok("{$base}.pro has a matching expected/{$base}.decode.json", false);
        continue;
    }

    $bytes = file_get_contents($proPath);
    if ($bytes === false) {
        ok("{$base}.pro is readable", false);
        continue;
    }

    $expectedRaw = file_get_contents($expectedPath);
    $expected = $expectedRaw !== false ? json_decode($expectedRaw, true) : null;
    if (!is_array($expected)) {
        ok("{$base}.decode.json parses as JSON", false);
        continue;
    }

    try {
        $decoded = pp7DecodePresentation($bytes);
    } catch (\Throwable $e) {
        ok("{$base}.pro decodes without throwing (" . $e->getMessage() . ')', false);
        continue;
    }

    $actual = pp7ProjectForComparison($decoded);
    $diff = pp7FirstDiffPath($actual, $expected);
    ok("{$base}.pro: PHP decoder matches protobufjs-derived expected output"
        . ($diff !== null ? " [first diff at {$diff}]" : ''),
        $diff === null);
}

/* ============================================================================================
 * (b) FIELD-TABLE LOCKSTEP — every cited field number still matches the vendored .proto source
 * ============================================================================================ */

echo "\n-- (b) decoder field-table <-> vendored .proto lockstep (plan §11.3) --\n";

$decoderPath = dirname(__DIR__, 2) . '/appWeb/public_html/includes/propresenter7_decode.php';
$decoderSrc = file_get_contents($decoderPath);
if ($decoderSrc === false) {
    ok('propresenter7_decode.php is readable', false);
} else {
    // Tree-derived (rule #34): the SET of tables + entries to check comes from parsing the
    // decoder's own source, never from a hand-typed list in this test. Each entry line has the
    // shape  'name' => NUMBER, // file.proto:LINE  — see the file-level doc-block in
    // propresenter7_decode.php for why this exact format was chosen (it doubles as a citation
    // AND a machine-checkable claim).
    preg_match_all('/define\(\'([A-Z0-9_]+)\',\s*\[(.*?)\]\);/s', $decoderSrc, $tables, PREG_SET_ORDER);

    ok('at least 15 field/enum-value tables were found in propresenter7_decode.php (found ' . count($tables) . ')',
        count($tables) >= 15);

    $protoDir = dirname(__DIR__, 2) . '/appWeb/public_html/manage/editor/protos/proto-7.16';
    $protoFileCache = [];
    $totalEntries = 0;

    foreach ($tables as [, $tableName, $body]) {
        preg_match_all(
            "/'([A-Za-z0-9_]+)'\\s*=>\\s*(\\d+),\\s*\\/\\/\\s*([A-Za-z0-9_]+\\.proto):(\\d+)/",
            $body,
            $entries,
            PREG_SET_ORDER
        );
        foreach ($entries as [, $name, $number, $protoFile, $lineNo]) {
            $totalEntries++;
            $number = (int)$number;
            $lineNo = (int)$lineNo;

            if (!isset($protoFileCache[$protoFile])) {
                $path = $protoDir . '/' . $protoFile;
                $protoFileCache[$protoFile] = is_file($path)
                    ? (file($path, FILE_IGNORE_NEW_LINES) ?: [])
                    : null;
            }
            $lines = $protoFileCache[$protoFile];

            if ($lines === null) {
                ok("{$tableName}.{$name}: cited proto file {$protoFile} exists under protos/proto-7.16/", false);
                continue;
            }
            if (!isset($lines[$lineNo - 1])) {
                ok("{$tableName}.{$name}: {$protoFile}:{$lineNo} exists (file has " . count($lines) . ' lines)', false);
                continue;
            }

            $protoLine = $lines[$lineNo - 1];
            if (!preg_match('/(\w+)\s*=\s*(\d+)\s*;/', $protoLine, $pm)) {
                ok("{$tableName}.{$name}: {$protoFile}:{$lineNo} contains a '<name> = <number>;' declaration (got: "
                    . trim($protoLine) . ')', false);
                continue;
            }
            $protoName = $pm[1];
            $protoNumber = (int)$pm[2];

            ok("{$tableName}.{$name} => {$number} matches {$protoFile}:{$lineNo} ({$protoName} = {$protoNumber})",
                $protoName === $name && $protoNumber === $number);
        }
    }

    ok('at least 40 field/enum-value entries were lockstep-checked against the vendored .proto source (checked ' . $totalEntries . ')',
        $totalEntries >= 40);
}

/* ============================================================================================
 * (c) CHORD CUSTOM-ATTRIBUTE DECODE — synthetic unit rows (#1968 P6, plan §3.1)
 * ============================================================================================
 * All 12 real fixtures under tests/fixtures/propresenter/ are chord-FREE (the plan's §5 finding —
 * no real chord-bearing sample exists yet; see the plan's "owner checklist D4"), so section (a)
 * above cannot exercise a single custom_attributes[] row. These rows instead hand-build minimal
 * synthetic `rv.data.Graphics.Text.Attributes` bytes (same hand-rolled tag/varint/length-delimited
 * helpers as this file's sibling test-pp7-parse.php §(b) — NOT protobufjs, NOT this repo's own
 * exporter, so this is not the circular same-schema round-trip the owner's #1 rule forbids) and
 * assert the NEW pp7DecodeIntRange()/pp7DecodeCustomAttribute()/pp7DecodeTextAttributesChords()
 * decode exactly what their own field-number tables (lockstep-checked in section (b) above) say
 * they should.
 */

echo "\n-- (c) chord custom-attribute decode — synthetic unit rows (#1968 P6) --\n";

function pp7DecTestVarint(int $v): string
{
    if ($v < 0) {
        /* A negative int32 is protobuf-encoded as the FULL 10-byte sign-extended 64-bit
           two's-complement varint (protobuf's signed-int-via-int64 convention —
           https://protobuf.dev/programming-guides/encoding/#signed-ints); a plain "shift until
           zero" loop (the positive-value branch below) NEVER terminates for a negative PHP int,
           because `>>` is an ARITHMETIC shift that keeps re-filling the top bit with 1 forever.
           PHP ints are already 64-bit two's complement, so this walks exactly 9 groups of 7 bits
           off that bit pattern plus a 10th byte carrying bit 63 alone (mirrors pp7ReadVarint()'s
           own 10-byte cap and its OR-accumulation, which is exactly what makes this round-trip —
           empirically verified against the real pp7ReadVarint()/pp7Int32FromVarint() below, not
           merely asserted). */
        $out = '';
        for ($i = 0; $i < 9; $i++) {
            $out .= chr(($v & 0x7F) | 0x80);
            $v >>= 7;
        }
        $out .= chr($v & 0x01); // the 10th byte: only bit 63 of the original 64-bit value remains
        return $out;
    }
    $out = '';
    while (true) {
        $byte = $v & 0x7F;
        $v >>= 7;
        if ($v !== 0) {
            $out .= chr($byte | 0x80);
        } else {
            $out .= chr($byte);
            break;
        }
    }
    return $out;
}
function pp7DecTestTag(int $field, int $wireType): string { return pp7DecTestVarint(($field << 3) | $wireType); }
function pp7DecTestLenDelim(string $bytes): string { return pp7DecTestVarint(strlen($bytes)) . $bytes; }
function pp7DecTestStrField(int $field, string $s): string { return pp7DecTestTag($field, 2) . pp7DecTestLenDelim($s); }
function pp7DecTestMsgField(int $field, string $sub): string { return pp7DecTestTag($field, 2) . pp7DecTestLenDelim($sub); }
function pp7DecTestVarintField(int $field, int $v): string { return pp7DecTestTag($field, 0) . pp7DecTestVarint($v); }

// rv.data.IntRange{start=1,end=2}
function pp7DecTestIntRange(int $start, int $end): string
{
    return pp7DecTestVarintField(1, $start) . pp7DecTestVarintField(2, $end);
}
// rv.data.Graphics.Text.Attributes.CustomAttribute{range=1, chord=7 (oneof)}
function pp7DecTestChordAttribute(int $start, int $end, string $chord): string
{
    return pp7DecTestMsgField(1, pp7DecTestIntRange($start, $end)) . pp7DecTestStrField(7, $chord);
}
// A NON-chord CustomAttribute: the oneof selects `capitalization` (field 2, varint) instead —
// pp7DecodeCustomAttribute() must return null for this row (§1.1's "skip every other oneof branch").
function pp7DecTestCapitalizationAttribute(int $start, int $end, int $capValue): string
{
    return pp7DecTestMsgField(1, pp7DecTestIntRange($start, $end)) . pp7DecTestVarintField(2, $capValue);
}

// --- pp7DecodeIntRange() -------------------------------------------------------------------
ok('pp7DecodeIntRange(): a normal {start,end} pair decodes both fields',
    pp7DecodeIntRange(pp7DecTestIntRange(5, 9), 0) === ['start' => 5, 'end' => 9]);
ok('pp7DecodeIntRange(): start=0 written EXPLICITLY as a literal zero byte decodes correctly',
    pp7DecodeIntRange(pp7DecTestIntRange(0, 3), 0) === ['start' => 0, 'end' => 3]);
// #1968 P6 CORRECTNESS FIX (found empirically against this feature's own synthetic fixture,
// see pp7DecodeIntRange()'s doc-block): proto3 NEVER writes a plain scalar field that holds its
// own type's default value (0 for int32) — a genuine, real-world `IntRange{start:0, end:21}`
// (a chord at the very first character of a line — the single most common chord position)
// therefore encodes with `start` COMPLETELY ABSENT from the wire, indistinguishable from "start
// was never set at all". Treating that absence as malformed (an earlier version of this
// function did) SILENTLY DROPS every column-0 chord. This is THE regression-proof row.
ok('pp7DecodeIntRange(): `start` OMITTED from the wire (only `end` present — protobuf\'s implicit-default omission for a genuine start=0) decodes to start=0, NOT null',
    pp7DecodeIntRange(pp7DecTestVarintField(2, 9), 0) === ['start' => 0, 'end' => 9]);
ok('pp7DecodeIntRange(): `end` likewise omitted (only `start` present) decodes to end=0 (never used for placement anyway — plan §1.2)',
    pp7DecodeIntRange(pp7DecTestVarintField(1, 4), 0) === ['start' => 4, 'end' => 0]);
ok('pp7DecodeIntRange(): BOTH omitted (a genuinely empty IntRange submessage) decodes to {0,0}, not null',
    pp7DecodeIntRange('', 0) === ['start' => 0, 'end' => 0]);
ok('pp7DecodeIntRange(): a negative `start` (plan §3.1 point 1 — "negative => treat row invalid") decodes to null',
    pp7DecodeIntRange(pp7DecTestIntRange(-1, 5), 0) === null);

// --- pp7DecodeCustomAttribute() ------------------------------------------------------------
ok('pp7DecodeCustomAttribute(): a chord row decodes {start,end,chord}',
    pp7DecodeCustomAttribute(pp7DecTestChordAttribute(2, 5, 'G'), 0) === ['start' => 2, 'end' => 5, 'chord' => 'G']);
ok('pp7DecodeCustomAttribute(): an EXPLICITLY EMPTY chord string still decodes (oneof presence, not value, selects the branch)',
    pp7DecodeCustomAttribute(pp7DecTestChordAttribute(0, 1, ''), 0) === ['start' => 0, 'end' => 1, 'chord' => '']);
ok('pp7DecodeCustomAttribute(): a NON-chord attribute (capitalization branch) decodes to null — the oneof filter',
    pp7DecodeCustomAttribute(pp7DecTestCapitalizationAttribute(0, 4, 1), 0) === null);
ok('pp7DecodeCustomAttribute(): a chord field with no `range` at all decodes to null',
    pp7DecodeCustomAttribute(pp7DecTestStrField(7, 'C'), 0) === null);
// The end-to-end regression proof: a chord genuinely at column 0, encoded the way a REAL proto3
// writer would (start OMITTED — a hand-rolled builder that always writes both fields, like
// pp7DecTestChordAttribute() above, could never have caught this; this row deliberately builds
// the range with ONLY `end` on the wire, exactly like protobufjs's own encoder would for
// `{start:0, end:5}`).
ok('pp7DecodeCustomAttribute(): a chord at column 0 (start OMITTED from the wire, matching a real proto3 encoder) still decodes with start=0',
    pp7DecodeCustomAttribute(
        pp7DecTestMsgField(1, pp7DecTestVarintField(2, 5)) . pp7DecTestStrField(7, 'G'),
        0
    ) === ['start' => 0, 'end' => 5, 'chord' => 'G']);

// --- pp7DecodeTextAttributesChords() -------------------------------------------------------
$mixedAttrs = pp7DecTestMsgField(13, pp7DecTestChordAttribute(0, 3, 'G'))
    . pp7DecTestMsgField(13, pp7DecTestCapitalizationAttribute(3, 8, 1)) // skipped — not a chord
    . pp7DecTestMsgField(13, pp7DecTestChordAttribute(8, 12, 'C'));
$chordRows = pp7DecodeTextAttributesChords($mixedAttrs, 0);
ok('pp7DecodeTextAttributesChords(): returns ONLY the chord rows, skipping the interleaved non-chord attribute',
    $chordRows === [
        ['start' => 0, 'end' => 3, 'chord' => 'G'],
        ['start' => 8, 'end' => 12, 'chord' => 'C'],
    ]);

// --- End-to-end through pp7DecodeGraphicsText() -> pp7DecodeGraphicsTextChords() ----------
// rv.data.Graphics.Text{attributes=3, rtf_data=5} — proves the chord walk reaches all the way
// down from the SAME submessage bytes rtf_data is read from, independently (#1968 P6 §3.1: two
// separate walks of the one Graphics.Text buffer, not a widened pp7DecodeGraphicsText() return).
$graphicsTextBytes = pp7DecTestMsgField(3, $mixedAttrs) . pp7DecTestStrField(5, 'plain rtf bytes');
ok('pp7DecodeGraphicsText(): rtf_data is unaffected by the sibling attributes field (signature/behaviour stability)',
    pp7DecodeGraphicsText($graphicsTextBytes, 0) === 'plain rtf bytes');
ok('pp7DecodeGraphicsTextChords(): reaches the same Graphics.Text bytes and returns the chord rows',
    pp7DecodeGraphicsTextChords($graphicsTextBytes, 0) === $chordRows);

// --- pp7Int32FromVarint() (the signed-int32 helper IntRange relies on) --------------------
// A negative int32's WIRE form is its 64-bit two's-complement bit pattern (protobuf's signed-int
// encoding, https://protobuf.dev/programming-guides/encoding/#signed-ints) — which is exactly
// what a native PHP negative int ALREADY is (PHP ints are 64-bit two's complement), so passing
// pp7ReadVarint()'s raw reading straight through as a PHP int and re-deriving the low 32 bits is
// the correct simulation of "the value pp7ReadVarint() would have handed back for that wire form".
ok('pp7Int32FromVarint(): a small positive value round-trips unchanged',
    pp7Int32FromVarint(42) === 42);
ok('pp7Int32FromVarint(): -1\'s 64-bit two\'s-complement bit pattern decodes back to -1',
    pp7Int32FromVarint(-1) === -1);
ok('pp7Int32FromVarint(): the largest positive int32 (0x7FFFFFFF) stays positive',
    pp7Int32FromVarint(0x7FFFFFFF) === 0x7FFFFFFF);
ok('pp7Int32FromVarint(): the smallest negative int32 (-2147483648, wire form 0xFFFFFFFF80000000) round-trips',
    pp7Int32FromVarint(-2147483648) === -2147483648);

/* ============================================================================================ */

echo "\n{$passed} passed, {$failed} failed\n";
if ($failed > 0) {
    echo "\nFailures:\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    echo "\nA decoder cross-validation failure means the PHP walker and protobufjs (an\n";
    echo "independent implementation) disagree on a REAL third-party ProPresenter file — the\n";
    echo "owner's #1 rule for this epic is that this must never ship. A lockstep failure means\n";
    echo "a field-number table has drifted from the vendored .proto schema it claims to mirror.\n";
    exit(1);
}
echo "\nAll ProPresenter 7+ decoder assertions passed.\n";
