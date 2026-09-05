/**
 * iHymns — OpenLyrics export honours voice-part RUNS (#2071)
 * ============================================================
 *
 * Exercises the exporter half of #2071: `manage/editor/format-export.js`
 * `openLyrics.build(song)` now emits ONE `<lines part="…">` block per voice
 * RUN (the folded shape `SongData::getSongById()` already produces on
 * `comp.voices`, #2073 "Design pass 7" §5.1/§5.2 — `lyricLinesFoldVoiceRuns()`)
 * instead of always writing attribute-less `<lines>` chunk blocks.
 *
 * THE COMPLICATION THIS FILE PROVES IS HANDLED: the exporter had ALREADY
 * spent the "several `<lines>` blocks in one `<verse>`" construct on a
 * DIFFERENT meaning — one block per slide chunk (`chunkLines()`), never
 * writing `part=` at all. So this fix must (a) leave a voice-less
 * component's output BYTE-IDENTICAL to before (pinned, captured-before
 * regression test — the `test-chordpro-export.js` precedent), and
 * (b) chunk ONLY the attribute-less gaps around a voiced run, never split
 * the run itself.
 *
 * FOUR LAYERS OF PROOF:
 *   1. `voiceLineSegments()` — the pure fold from `comp.voices` runs into
 *      ordered part/gap segments — truth-tabled directly, including a
 *      MUTATION-PROOF pair (a malformed run is skipped; the identical
 *      well-formed run is NOT, proving the guard actually filters
 *      something rather than being a no-op that happens to pass).
 *   2. `openLyricsPartToken()` / `makeGroupOrdinalResolver()` — the
 *      kind->keyword mapping, truth-tabled (including the `group` ordinal
 *      and `named-singer` special cases, mirroring the ALREADY-SHIPPED PHP
 *      twin `vocalPartsExportKeyword()`'s own rule that the keyword comes
 *      from the KIND, never a curator's Label).
 *   3. `buildOpenLyrics()` end-to-end: the pinned byte-identical no-op, the
 *      issue's own worked example (women/men/all), and a mixed
 *      gap+run+gap component under slide-chunking.
 *   4. PHP<->JS agreement + a REAL round-trip: `OL_PART_KEYWORD` is diffed
 *      against the LIVE `IHYMNS_VOCAL_PART_KINDS` `openlyrics` column via a
 *      `php -r` subprocess (rule #35 — a mechanism, not a comment), and the
 *      exported XML is fed back through the REAL, unmodified
 *      `_bulkImport_parseOpenLyrics()` to prove the closure the task asks
 *      for. Gracefully SKIPPED (not failed) when no `php` binary is on
 *      PATH — the exact same environment gap
 *      `tests/test-org-logo-resolver-lockstep.js` already has; this file
 *      must not turn into a SECOND baseline failure in an environment with
 *      no PHP installed, while still running for real wherever one is
 *      (CI, production, any dev box with `php` on PATH).
 *
 *   USAGE:  node tests/test-openlyrics-export-parts.js
 *   Exit status 0 = all pass (or gracefully skipped), 1 = at least one
 *   assertion genuinely failed.
 *
 * @see appWeb/public_html/manage/editor/format-export.js   the file under test
 * @see appWeb/public_html/includes/song_importers.php       the import half (#2071, tests/php/test-openlyrics-voice-parts.php)
 * @see appWeb/public_html/includes/vocal_parts.php           IHYMNS_VOCAL_PART_KINDS, vocalPartsExportKeyword() — the PHP twin this file mirrors
 * @see https://github.com/MWBMPartners/iHymns/issues/2071   the bug this file proves fixed
 * @see .claude/vocal-parts-2073-plan.md                     "Design pass 7" §10 (commit 11) / "Design pass 6" §3.3
 */
import assert from 'node:assert/strict';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';
import { spawnSync } from 'node:child_process';

const __filename = fileURLToPath(import.meta.url);
const __dirname  = path.dirname(__filename);
const require    = createRequire(import.meta.url);

const FORMAT_EXPORT_PATH   = path.resolve(__dirname, '..', 'appWeb/public_html/manage/editor/format-export.js');
const VOCAL_PARTS_PHP_PATH = path.resolve(__dirname, '..', 'appWeb/public_html/includes/vocal_parts.php');
const IMPORTERS_PHP_PATH   = path.resolve(__dirname, '..', 'appWeb/public_html/includes/song_importers.php');

/* format-export.js is a plain global script: requiring it for side effect sets
   globalThis.iHymnsFormatExport (the same contract the browser consumes,
   and the exact pattern tests/test-chordpro-export.js already uses — do
   NOT use `require()`'s own return value here, it comes back empty under
   this repo's ESM "type":"module" root package.json). */
require(FORMAT_EXPORT_PATH);
const fmt = globalThis.iHymnsFormatExport;
const buildOpenLyrics = fmt.openLyrics.build;
const { olPartKeyword, openLyricsPartToken, voiceLineSegments, makeGroupOrdinalResolver } = fmt._internal;

let passed = 0, failed = 0, skipped = 0;
const failures = [];
function test(name, fn) {
    try { fn(); passed++; console.log(`  PASS  ${name}`); }
    catch (err) { failed++; failures.push({ name, error: err.message }); console.log(`  FAIL  ${name}`); }
}
function skip(name, reason) {
    skipped++;
    console.log(`  SKIP  ${name}`);
    console.log(`        ${reason}`);
}

/* ==========================================================================
 * 1 — voiceLineSegments(): fold comp.voices RUNS into ordered part/gap
 *     segments covering every line position exactly once.
 * ========================================================================== */
console.log('\n1 — voiceLineSegments()');

test('no comp.voices at all -> one null segment spanning every line (the pre-#2071 shape)', () => {
    const segs = voiceLineSegments({ lines: ['a', 'b', 'c'] });
    assert.deepEqual(segs, [{ part: null, from: 0, to: 2 }]);
});

test('an EMPTY comp.voices array -> the same single null segment (sparse means absent, never present-but-empty)', () => {
    const segs = voiceLineSegments({ lines: ['a', 'b'], voices: [] });
    assert.deepEqual(segs, [{ part: null, from: 0, to: 1 }]);
});

test('a run covering EVERY line -> exactly one part segment, no gaps at all', () => {
    const part = { id: 1, kind: 'female' };
    const segs = voiceLineSegments({ lines: ['a', 'b'], voices: [{ from: 0, to: 1, parts: [part] }] });
    assert.deepEqual(segs, [{ part, from: 0, to: 1 }]);
});

test('a run in the MIDDLE produces gap / part / gap, covering every position exactly once', () => {
    const part = { id: 1, kind: 'male' };
    const segs = voiceLineSegments({ lines: ['a', 'b', 'c', 'd', 'e'], voices: [{ from: 2, to: 2, parts: [part] }] });
    assert.deepEqual(segs, [
        { part: null, from: 0, to: 1 },
        { part,       from: 2, to: 2 },
        { part: null, from: 3, to: 4 },
    ]);
});

test('several runs interleave with gaps in document order, using ONLY the FIRST part of a multi-part (duet) cell', () => {
    const p1    = { id: 1, kind: 'female' };
    const p2    = { id: 2, kind: 'male' };
    const pDuet = { id: 3, kind: 'all' };
    const segs = voiceLineSegments({
        lines: ['a', 'b', 'c', 'd', 'e', 'f'],
        voices: [
            { from: 0, to: 0, parts: [p1] },
            /* a duet line: OpenLyrics has no way to say "two parts, one
               line" — the plan is explicit that the second is DROPPED. */
            { from: 2, to: 3, parts: [p2, pDuet] },
        ],
    });
    assert.deepEqual(segs, [
        { part: p1,   from: 0, to: 0 },
        { part: null, from: 1, to: 1 },
        { part: p2,   from: 2, to: 3 },
        { part: null, from: 4, to: 5 },
    ]);
});

/* MUTATION-PROOF PAIR (rule #34): the same run shape with `parts` present
   vs absent must produce DIFFERENT segmentations — proving the defensive
   `!Array.isArray(run.parts) || !run.parts.length` guard actually filters
   something, rather than a no-op that merely happens to look correct. */
test('a malformed run (no `parts` array) is skipped defensively — degrades to one plain gap, never throws', () => {
    const segs = voiceLineSegments({ lines: ['a', 'b', 'c'], voices: [{ from: 1, to: 1 }] });
    assert.deepEqual(segs, [{ part: null, from: 0, to: 2 }]);
});
test('…and the IDENTICAL shape WITH a parts array IS included — proving the previous test exercised the guard, not a vacuous pass', () => {
    const part = { id: 9, kind: 'cantor' };
    const segs = voiceLineSegments({ lines: ['a', 'b', 'c'], voices: [{ from: 1, to: 1, parts: [part] }] });
    assert.deepEqual(segs, [
        { part: null, from: 0, to: 0 },
        { part,       from: 1, to: 1 },
        { part: null, from: 2, to: 2 },
    ]);
});

/* ==========================================================================
 * 2 — openLyricsPartToken(): kind -> the OpenLyrics part= attribute value
 * ========================================================================== */
console.log('\n2 — openLyricsPartToken()');

test('female -> "women" (the OpenLyrics 0.8 keyword)', () => {
    assert.equal(openLyricsPartToken({ kind: 'female' }, () => 1), 'women');
});
test('male -> "men"', () => {
    assert.equal(openLyricsPartToken({ kind: 'male' }, () => 1), 'men');
});
test('cantor -> "cantor"', () => {
    assert.equal(openLyricsPartToken({ kind: 'cantor' }, () => 1), 'cantor');
});
test('named-singer WITH a label -> the singer\'s own name, case preserved (matches the PHP twin vocalPartsExportKeyword())', () => {
    assert.equal(openLyricsPartToken({ kind: 'named-singer', label: 'Fred Bloggs' }, () => 1), 'Fred Bloggs');
});
test('named-singer with NO label -> "solo" (the PHP twin\'s own documented fallback)', () => {
    assert.equal(openLyricsPartToken({ kind: 'named-singer', label: null }, () => 1), 'solo');
});
test('group -> "group" + whatever ordinal the resolver hands back — NEVER the curator\'s Label (structure, not cosmetics, rule #45)', () => {
    assert.equal(openLyricsPartToken({ kind: 'group', label: 'Youth' }, () => 3), 'group3');
});
test('a null part -> null (no attribute emitted at all)', () => {
    assert.equal(openLyricsPartToken(null, () => 1), null);
});
test('a part object with no kind at all -> null, defensively (never throws an export over it)', () => {
    assert.equal(openLyricsPartToken({}, () => 1), null);
});

/* ==========================================================================
 * 3 — makeGroupOrdinalResolver(): stable per-song group numbering
 * ========================================================================== */
console.log('\n3 — makeGroupOrdinalResolver()');

test('the SAME part id always resolves to the SAME ordinal, across repeated calls', () => {
    const resolve = makeGroupOrdinalResolver();
    assert.equal(resolve({ id: 5 }), 1);
    assert.equal(resolve({ id: 7 }), 2);
    assert.equal(resolve({ id: 5 }), 1);
});
test('a part with no id falls back to its label as the identity key', () => {
    const resolve = makeGroupOrdinalResolver();
    assert.equal(resolve({ label: 'Left side' }), 1);
    assert.equal(resolve({ label: 'Right side' }), 2);
    assert.equal(resolve({ label: 'Left side' }), 1);
});

/* ==========================================================================
 * 4 — buildOpenLyrics(): byte-identical no-op + the issue's own example
 * ========================================================================== */
console.log('\n4 — buildOpenLyrics()');

/* A completely voice-less song — pinned EXACT output, captured from the
   REAL unmodified pre-#2071 exporter (verified byte-for-byte against
   `git show HEAD~:...` before this fix touched the file, the same
   captured-before discipline test-chordpro-export.js's own header
   documents), so a regression here is caught as a STRING mismatch, not
   just "looks about right". */
const PLAIN_SONG = {
    title: 'Amazing Grace',
    writers: ['John Newton'],
    ccli: '12345',
    copyright: 'Public Domain',
    number: 12,
    songbookName: 'Test Book',
    components: [
        { type: 'verse', number: 1, lines: ['Amazing grace, how sweet the sound', 'That saved a wretch like me'] },
        { type: 'chorus', number: 0, lines: ['Chorus line one', 'Chorus line two', 'Chorus line three', 'Chorus line four'] },
    ],
};
const PINNED_NO_CHUNK =
    '<?xml version="1.0" encoding="UTF-8"?>\n' +
    '<song xmlns="http://openlyrics.info/namespace/2009/song" version="0.8" createdIn="iHymns">\n' +
    '  <properties>\n' +
    '    <titles><title>Amazing Grace</title></titles>\n' +
    '    <authors>\n' +
    '      <author>John Newton</author>\n' +
    '    </authors>\n' +
    '    <copyright>Public Domain</copyright>\n' +
    '    <ccliNo>12345</ccliNo>\n' +
    '    <songbooks><songbook name="Test Book" entry="12"/></songbooks>\n' +
    '    <verseOrder>v1 c</verseOrder>\n' +
    '  </properties>\n' +
    '  <lyrics>\n' +
    '    <verse name="v1">\n' +
    '      <lines>Amazing grace, how sweet the sound<br/>That saved a wretch like me</lines>\n' +
    '    </verse>\n' +
    '    <verse name="c">\n' +
    '      <lines>Chorus line one<br/>Chorus line two<br/>Chorus line three<br/>Chorus line four</lines>\n' +
    '    </verse>\n' +
    '  </lyrics>\n' +
    '</song>\n';
const PINNED_CHUNK_2 =
    '<?xml version="1.0" encoding="UTF-8"?>\n' +
    '<song xmlns="http://openlyrics.info/namespace/2009/song" version="0.8" createdIn="iHymns">\n' +
    '  <properties>\n' +
    '    <titles><title>Amazing Grace</title></titles>\n' +
    '    <authors>\n' +
    '      <author>John Newton</author>\n' +
    '    </authors>\n' +
    '    <copyright>Public Domain</copyright>\n' +
    '    <ccliNo>12345</ccliNo>\n' +
    '    <songbooks><songbook name="Test Book" entry="12"/></songbooks>\n' +
    '    <verseOrder>v1 c</verseOrder>\n' +
    '  </properties>\n' +
    '  <lyrics>\n' +
    '    <verse name="v1">\n' +
    '      <lines>Amazing grace, how sweet the sound<br/>That saved a wretch like me</lines>\n' +
    '    </verse>\n' +
    '    <verse name="c">\n' +
    '      <lines>Chorus line one<br/>Chorus line two</lines>\n' +
    '      <lines>Chorus line three<br/>Chorus line four</lines>\n' +
    '    </verse>\n' +
    '  </lyrics>\n' +
    '</song>\n';

test('a voice-less song is BYTE-IDENTICAL to the pre-#2071 output (no chunking option)', () => {
    assert.equal(buildOpenLyrics(PLAIN_SONG, {}), PINNED_NO_CHUNK);
});
test('…and BYTE-IDENTICAL with maxLinesPerSlide chunking too — this fix adds a channel, it does not change the old one', () => {
    assert.equal(buildOpenLyrics(PLAIN_SONG, { maxLinesPerSlide: 2 }), PINNED_CHUNK_2);
});

/* The issue's own worked example — women / men / all in one <verse>. */
const VOICED_SONG = {
    title: 'Psalm 91',
    components: [{
        type: 'verse', number: 1,
        lines: [
            'He who dwells, he who dwells',
            'in the shelter of the Most High,',
            'he who dwells, he who dwells',
            'in the shelter of the Most High',
            "And I'll say of the Lord, 'He is my refuge';",
        ],
        voices: [
            { from: 0, to: 1, parts: [{ id: 1, kind: 'female', label: 'Women' }] },
            { from: 2, to: 3, parts: [{ id: 2, kind: 'male', label: 'Men' }] },
            { from: 4, to: 4, parts: [{ id: 3, kind: 'all', label: 'All' }] },
        ],
    }],
};

test('the issue\'s own women/men/all example exports ONE <lines part="…"> block per run — never split, never merged', () => {
    const xml = buildOpenLyrics(VOICED_SONG, {});
    assert.match(xml, /<lines part="women">He who dwells, he who dwells<br\/>in the shelter of the Most High,<\/lines>/);
    assert.match(xml, /<lines part="men">he who dwells, he who dwells<br\/>in the shelter of the Most High<\/lines>/);
    assert.match(xml, /<lines part="all">And I&apos;ll say of the Lord, &apos;He is my refuge&apos;;<\/lines>/);
    /* exactly three <lines> blocks total — no chunk split inside any run */
    assert.equal((xml.match(/<lines[ >]/g) || []).length, 3);
});

/* A mixed component: attribute-less lines BEFORE and AFTER a voiced run,
   under a maxLinesPerSlide that WOULD split a longer gap — proving
   chunking still applies to the gaps but never touches the run itself. */
const MIXED_SONG = {
    title: 'Mixed',
    components: [{
        type: 'verse', number: 1,
        lines: ['Gap one', 'Gap two', 'Gap three', 'Voiced line', 'Tail one', 'Tail two'],
        voices: [{ from: 3, to: 3, parts: [{ id: 10, kind: 'cantor' }] }],
    }],
};
test('chunking still applies WITHIN the attribute-less gaps around a run, and NEVER splits the run itself', () => {
    const xml = buildOpenLyrics(MIXED_SONG, { maxLinesPerSlide: 2 });
    assert.match(xml, /<lines>Gap one<br\/>Gap two<\/lines>\s*<lines>Gap three<\/lines>\s*<lines part="cantor">Voiced line<\/lines>\s*<lines>Tail one<br\/>Tail two<\/lines>/);
});

test('a component with an EMPTY voices array behaves exactly like one with none at all', () => {
    const song = { title: 'Empty voices', components: [{ type: 'verse', number: 1, lines: ['One line'], voices: [] }] };
    const xml = buildOpenLyrics(song, {});
    assert.match(xml, /<lines>One line<\/lines>/);
    assert.equal(xml.includes('part='), false);
});

/* ==========================================================================
 * 5 — PHP<->JS lockstep + a REAL export -> import round-trip closure
 * ========================================================================== */
console.log('\n5 — PHP<->JS lockstep and a real round-trip through the PHP importer');

const phpProbe = spawnSync('php', ['-v'], { encoding: 'utf8' });
const phpAvailable = !(phpProbe.error && phpProbe.error.code === 'ENOENT');

if (!phpAvailable) {
    skip(
        'OL_PART_KEYWORD matches the live PHP IHYMNS_VOCAL_PART_KINDS `openlyrics` column',
        'no `php` binary on PATH in this environment — the SAME documented gap tests/test-org-logo-resolver-lockstep.js '
        + 'already has (see that file\'s own header). This check runs for real wherever php is installed (CI, production, '
        + 'a dev box with php on PATH) — it is not silently vacuous there, only genuinely unreachable here.'
    );
    skip(
        'the exported women/men/all XML re-imports through the REAL, unmodified _bulkImport_parseOpenLyrics() (export -> import closure)',
        'same reason — no `php` binary on PATH in this environment.'
    );
} else {
    const kindsScript =
        'require ' + JSON.stringify(VOCAL_PARTS_PHP_PATH) + ';' +
        '$out = [];' +
        'foreach (IHYMNS_VOCAL_PART_KINDS as $key => $def) { $out[$key] = $def[\'openlyrics\']; }' +
        'echo json_encode($out);';
    const kindsResult = spawnSync('php', ['-r', kindsScript], { encoding: 'utf8' });

    test('the PHP vocabulary probe actually ran and returned data (guard against a silently-empty comparison)', () => {
        assert.equal(kindsResult.status, 0, 'php -r exited ' + kindsResult.status + ': ' + kindsResult.stderr);
        assert.ok(kindsResult.stdout && kindsResult.stdout.trim().length > 10, 'php -r printed nothing usable');
    });

    if (kindsResult.status === 0) {
        const phpMap = JSON.parse(kindsResult.stdout);
        test('OL_PART_KEYWORD has EXACTLY the same kind keys as the live PHP vocabulary — no drift in either direction', () => {
            assert.deepEqual(Object.keys(olPartKeyword).sort(), Object.keys(phpMap).sort());
        });
        test('every OL_PART_KEYWORD value matches the live PHP `openlyrics` column, kind by kind', () => {
            for (const key of Object.keys(phpMap)) {
                assert.equal(
                    olPartKeyword[key] ?? null,
                    phpMap[key] ?? null,
                    `kind "${key}": JS=${JSON.stringify(olPartKeyword[key] ?? null)} PHP=${JSON.stringify(phpMap[key] ?? null)}`
                );
            }
        });
    } else {
        skip('OL_PART_KEYWORD lockstep comparison', 'the PHP probe above failed to run — see that assertion\'s failure for detail');
    }

    /* The real round-trip closure the task explicitly asks for: feed the
       EXPORTED XML back through the REAL, unmodified PHP importer and
       check the voices it resolves match what was exported. */
    const roundTripXml = buildOpenLyrics(VOICED_SONG, {});
    const importScript =
        'require ' + JSON.stringify(IMPORTERS_PHP_PATH) + ';' +
        '$xml = file_get_contents("php://stdin");' +
        '[$parsed, $err] = _bulkImport_parseOpenLyrics($xml);' +
        'echo json_encode(["err" => $err, "voices" => $parsed["components"][0]["voices"] ?? null]);';
    const importResult = spawnSync('php', ['-r', importScript], { input: roundTripXml, encoding: 'utf8' });

    test('the exported XML re-imports through the REAL PHP parser without error', () => {
        assert.equal(importResult.status, 0, 'php -r exited ' + importResult.status + ': ' + importResult.stderr);
    });

    if (importResult.status === 0) {
        const reimported = JSON.parse(importResult.stdout);
        test('…and the RE-IMPORTED voice assignments match what was exported — the actual export -> import closure', () => {
            assert.equal(reimported.err, null);
            assert.deepEqual(reimported.voices[0], [{ kind: 'female', label: null }]);
            assert.deepEqual(reimported.voices[1], [{ kind: 'female', label: null }]);
            assert.deepEqual(reimported.voices[2], [{ kind: 'male', label: null }]);
            assert.deepEqual(reimported.voices[3], [{ kind: 'male', label: null }]);
            assert.deepEqual(reimported.voices[4], [{ kind: 'all', label: null }]);
        });
    } else {
        skip('export -> import closure assertion', 'the PHP re-import above failed to run — see that assertion\'s failure for detail');
    }
}

console.log(`\nOpenLyrics export voice-parts: ${passed} passed, ${failed} failed, ${skipped} skipped`);
if (failed) {
    failures.forEach((f) => console.log(`  - ${f.name}: ${f.error}`));
    process.exit(1);
}
