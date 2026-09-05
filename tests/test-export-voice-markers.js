/**
 * iHymns — voice-part MARKERS in the plain-text-shaped exports (#2073 commit 13)
 * ================================================================================
 *
 * ELI5: OpenLyrics already got a real `part="…"` attribute for "these lines
 * are the women's part" (#2071). Proclaim, ChordPro and OpenSong are just
 * PLAIN TEXT — there is nowhere to hang a structured attribute — so the
 * only honest way to say the same thing is to print the voice's name as a
 * line of its own, right where a real hymn sheet would print "WOMEN" above
 * a verse. This file proves `manage/editor/format-export.js` now does that
 * for all three formats, proves the shared, PURE detector
 * (`includes/vocal_part_detect.php`) reads the exact marker text straight
 * back, and — where the plan's own wording turned out not to match the
 * REAL, already-landed importer code — proves and documents what actually
 * happens instead, rather than silently assuming the plan was right.
 *
 * THE FOUR-FORMAT DECISION TABLE THE TASK ASKED FOR (reasoned out from the
 * ACTUAL, ALREADY-LANDED #2075/#2071 importer code, not just the plan —
 * every claim below is proven by a test in this file, not merely asserted):
 *
 *   PLAIN TEXT   — `format-export.js` has NO separate "plain text" builder.
 *                  Proclaim's OWN interchange format ALREADY IS plain text
 *                  (a title line, then blank-line-separated labelled
 *                  sections — see this format's own header comment in
 *                  format-export.js); there is no second exporter to add a
 *                  marker to. "Design pass 6" §8's own export table already
 *                  lists "Proclaim / plain text" as ONE row for exactly this
 *                  reason. So the Proclaim marker (below) IS the plain-text
 *                  marker — there is nothing else in this file to change.
 *                  (The BULK-IMPORT-only `.txt` reader, `_bulkImport_parseTxt()`
 *                  in includes/song_importers.php, has no export counterpart
 *                  at all — it only ever reads a file, never writes one.)
 *
 *   PROCLAIM     — YES, a marker is emitted: the canonical UPPER-CASE word
 *                  (e.g. "WOMEN") on its own line, directly before the run
 *                  it covers, no blank line inserted (a blank line would end
 *                  the whole section on reimport — see #5 below). The
 *                  shared detector reads it back via its STANDALONE form —
 *                  proven in §2. The real Proclaim/plain-text importer
 *                  (`_bulkImport_easyWorshipSplitComponents()`) keeps the
 *                  word as an ordinary lyric line inside the same section —
 *                  proven in §5.
 *
 *   CHORDPRO     — YES, a marker is emitted, as `{comment: WOMEN}` — the
 *                  format's one general-purpose "print this, it isn't sung"
 *                  directive. The shared detector reads the WORD back fine
 *                  (§2). ⚠️ BUT iHymns' OWN ChordPro reimporter
 *                  (`_bulkImport_parseChordPro()`) does NOT recover it: its
 *                  `{comment:}` handling calls the OLDER, un-#2075-fixed
 *                  `_bulkImport_componentTypeFor()` (never the shared,
 *                  patched `_bulkImport_classifyMarker()` the other four
 *                  sites use), so re-importing this marker starts a fresh,
 *                  UNLABELLED `refrain` component and the word is gone —
 *                  proven, not assumed, in §6. This is a real gap the plan
 *                  did not call out; it is documented here and in
 *                  format-export.js rather than silently worked around
 *                  (`song_importers.php` is out of this commit's scope).
 *
 *   OPENSONG     — YES, a marker is emitted, but as a `[WOMEN]` BRACKET TAG,
 *                  NOT the `;WOMEN` comment-row the design plan proposed.
 *                  Reason, proven in §7: the ACTUAL #2075 fix
 *                  (`_bulkImport_parseOpenSongLyrics()`) added voice-cue
 *                  preservation to the BRACKET-TAG branch (any `[Word]` it
 *                  doesn't recognise as a section letter) — a `;`-prefixed
 *                  comment row is, in the landed code, still
 *                  UNCONDITIONALLY DROPPED with no voice-cue check at all
 *                  (`includes/song_importers.php` around the `';'` early
 *                  `continue`). Writing `;WOMEN` per the plan's original
 *                  wording would be dropped outright on reimport — a
 *                  genuinely worse outcome than the bracket form, which the
 *                  real importer already preserves as a labelled component
 *                  (proven in §7). This is the loud, evidence-based
 *                  deviation from the plan's literal text that this
 *                  commit's own report calls out.
 *
 * WHAT NEVER SURVIVES, IN ANY OF THE THREE FORMATS (matching the ALREADY
 * SHIPPED OpenLyrics precedent, #2071 — never re-invented per format):
 *   - a sub-line echo SPAN (`comp.voiceSpans`) — none of these formats can
 *     mark up part of one line, only whole lines;
 *   - a ROUND/canon (`song.rounds`) — there is no flat-text way to say
 *     "these two voices sing the same words staggered";
 *   - the `bg` (background/echo) FLAG on an otherwise-kinded run — the
 *     marker word for the run's KIND is still written (e.g. a `female`
 *     run marked `bg:true` still prints "WOMEN"), but the echo-ness itself
 *     is not represented, exactly like OpenLyrics already drops it;
 *   - a DUET's second part — only `parts[0]` of a run is ever consulted
 *     (`voiceLineSegments()`, shared with OpenLyrics).
 * None of this is a silent gap: `%NONE OF THIS IS REPRESENTED%` is stated
 * here, in format-export.js's own comments, and in this file's own tests
 * (§4), so a reader of the exported file is never told something was kept
 * when it was in fact dropped.
 *
 *   USAGE:  node tests/test-export-voice-markers.js
 *   Exit status 0 = all pass (or gracefully skipped), 1 = at least one
 *   assertion genuinely failed.
 *
 * @see appWeb/public_html/manage/editor/format-export.js    the file under test (markerKeyword(), buildProclaim/buildChordPro/buildOpenSong)
 * @see appWeb/public_html/includes/vocal_part_detect.php    the shared, PURE detector this file proves reads every marker back
 * @see appWeb/public_html/includes/vocal_parts.php          IHYMNS_VOCAL_PART_KINDS, vocalPartsExportKeyword() — the PHP twin markerKeyword() mirrors
 * @see appWeb/public_html/includes/song_importers.php       read-only in this commit; §5-§7 prove what its REAL importers do with these markers
 * @see tests/test-openlyrics-export-parts.js                 the sibling test this file's structure and lockstep pattern are modelled on
 * @see .claude/vocal-parts-2073-plan.md                      "Design pass 7" §10 (commit 13) / "Design pass 6" §8
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
const DETECT_PHP_PATH      = path.resolve(__dirname, '..', 'appWeb/public_html/includes/vocal_part_detect.php');
const IMPORTERS_PHP_PATH   = path.resolve(__dirname, '..', 'appWeb/public_html/includes/song_importers.php');

/* format-export.js is a plain global script: requiring it for side effect sets
   globalThis.iHymnsFormatExport (the same contract the browser consumes, and
   the exact pattern tests/test-chordpro-export.js and
   tests/test-openlyrics-export-parts.js both already use). */
require(FORMAT_EXPORT_PATH);
const fmt = globalThis.iHymnsFormatExport;
const buildProclaim = fmt.proclaim.build;
const buildChordPro = fmt.chordPro.build;
const buildOpenSong = fmt.openSong.build;
const { markerKeyword, markerKeywordMap, makeGroupOrdinalResolver } = fmt._internal;

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
 * 1 — markerKeyword(): kind -> the canonical UPPER-CASE plain-text marker
 * ========================================================================== */
console.log('\n1 — markerKeyword()');

test('female -> "WOMEN" (the marker word every one of the three formats writes)', () => {
    assert.equal(markerKeyword({ kind: 'female' }, () => 1), 'WOMEN');
});
test('male -> "MEN"', () => {
    assert.equal(markerKeyword({ kind: 'male' }, () => 1), 'MEN');
});
test('backing -> "ECHO" (the FIRST marker word for that kind, not "BACKING")', () => {
    assert.equal(markerKeyword({ kind: 'backing' }, () => 1), 'ECHO');
});
test('named-singer WITH a label -> the singer\'s own name, UPPER-CASED (unlike OpenLyrics\' openLyricsPartToken(), which keeps case — a plain-text CUE reads as a cue only in caps)', () => {
    assert.equal(markerKeyword({ kind: 'named-singer', label: 'Fred Bloggs' }, () => 1), 'FRED BLOGGS');
});
test('named-singer with NO label -> "SOLO" (matches the PHP twin vocalPartsExportKeyword()\'s own fallback)', () => {
    assert.equal(markerKeyword({ kind: 'named-singer', label: null }, () => 1), 'SOLO');
});
test('group -> "GROUP " + whatever ordinal the resolver hands back — NEVER the curator\'s Label (structure, not cosmetics, rule #45)', () => {
    assert.equal(markerKeyword({ kind: 'group', label: 'Youth' }, () => 3), 'GROUP 3');
});
test('a null part -> null (no marker line emitted at all)', () => {
    assert.equal(markerKeyword(null, () => 1), null);
});
test('a part object with no kind at all -> null, defensively (never throws an export over it)', () => {
    assert.equal(markerKeyword({}, () => 1), null);
});

/* MUTATION-PROOF PAIR (rule #34): an UNKNOWN kind with no map entry falls
   back to the curator's own label, UPPER-CASED — but only when the map
   genuinely has nothing for that kind. Prove the fallback is reachable
   (first case) and prove it is NOT taken when a real map entry exists
   (second case) — a version of this function that always fell through to
   the label would pass the first case but wrongly break the second. */
test('an unrecognised kind (no map entry) falls back to its own label, upper-cased, rather than throwing', () => {
    assert.equal(markerKeyword({ kind: 'not-a-real-kind', label: 'Custom cue' }, () => 1), 'CUSTOM CUE');
});
test('…and a REAL, registered kind never takes that fallback path even when a label is ALSO present — proving the previous test actually exercised the fallback branch, not a function that always reads the label', () => {
    assert.equal(markerKeyword({ kind: 'female', label: 'Ladies of the choir' }, () => 1), 'WOMEN');
});

/* ==========================================================================
 * 2 — the shared detector reads every marker word straight back
 * ========================================================================== */
console.log('\n2 — includes/vocal_part_detect.php reads the canonical marker straight back');

const phpProbe = spawnSync('php', ['-v'], { encoding: 'utf8' });
const phpAvailable = !(phpProbe.error && phpProbe.error.code === 'ENOENT');

if (!phpAvailable) {
    skip(
        'every markerKeywordMap word round-trips through vocalPartDetectClassifyLine()',
        'no `php` binary on PATH in this environment — the SAME documented gap '
        + 'tests/test-openlyrics-export-parts.js and tests/test-org-logo-resolver-lockstep.js already have '
        + '(see either file\'s own header). Runs for real wherever php is installed (CI, production, a dev '
        + 'box with php on PATH) — not silently vacuous there, only genuinely unreachable here.'
    );
    skip('markerKeywordMap is in lockstep with the live PHP vocabulary\'s FIRST marker word per kind', 'same reason.');
    skip('SOLO is forced to LOW confidence (the section/voice-cue ambiguity floor, #2073)', 'same reason.');
    skip('the exported OpenSong [WOMEN] bracket tag survives through the REAL, unmodified OpenSong importer as a labelled component', 'same reason.');
    skip('iHymns\' OWN ChordPro reimporter currently DROPS a {comment: WOMEN} marker (documented gap, not fixed here)', 'same reason.');
    skip('the exported Proclaim/plain-text WOMEN/MEN/ALL markers survive as ordinary lyric lines through the real Proclaim importer', 'same reason.');
} else {
    /* Dump the live vocabulary's per-kind marker word + `markers` list
       length straight from PHP — never re-typed by hand here (rule #34 —
       derive from the tree, don't hardcode the list this test checks). */
    const kindsScript =
        'require ' + JSON.stringify(VOCAL_PARTS_PHP_PATH) + ';' +
        '$out = [];' +
        'foreach (IHYMNS_VOCAL_PART_KINDS as $key => $def) {' +
        '  $out[$key] = ["first" => array_key_first($def["markers"]), "count" => count($def["markers"])];' +
        '}' +
        'echo json_encode($out);';
    const kindsResult = spawnSync('php', ['-r', kindsScript], { encoding: 'utf8' });

    test('the PHP vocabulary probe actually ran and returned data (guard against a silently-empty comparison)', () => {
        assert.equal(kindsResult.status, 0, 'php -r exited ' + kindsResult.status + ': ' + kindsResult.stderr);
        assert.ok(kindsResult.stdout && kindsResult.stdout.trim().length > 10, 'php -r printed nothing usable');
    });

    if (kindsResult.status === 0) {
        const phpKinds = JSON.parse(kindsResult.stdout);

        test('markerKeywordMap has EXACTLY the same kind keys as the live PHP vocabulary — no drift in either direction', () => {
            assert.deepEqual(Object.keys(markerKeywordMap).sort(), Object.keys(phpKinds).sort());
        });
        test('every markerKeywordMap word matches the live PHP array_key_first(markers) per kind (group/named-singer excepted — special-cased, not map-driven)', () => {
            for (const key of Object.keys(phpKinds)) {
                if (key === 'group' || key === 'named-singer') { continue; }
                assert.equal(
                    markerKeywordMap[key] ?? null,
                    phpKinds[key].first ?? null,
                    `kind "${key}": JS=${JSON.stringify(markerKeywordMap[key] ?? null)} PHP=${JSON.stringify(phpKinds[key].first ?? null)}`
                );
            }
        });
        test('named-singer genuinely has ZERO marker words in the live vocabulary — the one-way-trip limitation documented above is a REAL vocabulary fact, not this file\'s own assumption', () => {
            assert.equal(phpKinds['named-singer'].count, 0);
        });

        /* THE REAL ROUND TRIP: for every kind except named-singer (see above),
           export its canonical marker word and feed it straight through the
           REAL, unmodified detector — proving the exact claim the task asked
           for, kind by kind, derived from the live vocabulary rather than a
           hand-typed list of 21 words that could silently go stale. */
        const roundTripScript =
            'require ' + JSON.stringify(DETECT_PHP_PATH) + ';' +
            '$in = json_decode(file_get_contents("php://stdin"), true);' +
            '$out = [];' +
            'foreach ($in as $word) { $out[$word] = vocalPartDetectClassifyLine($word); }' +
            'echo json_encode($out);';

        const groupOrdinalOf = makeGroupOrdinalResolver();
        const wordsToKinds = {};   // exported marker word -> the kind it should resolve back to
        for (const key of Object.keys(phpKinds)) {
            if (key === 'named-singer') { continue; }   // proven separately, on purpose, above/below
            const word = markerKeyword({ kind: key, id: 1, label: null }, groupOrdinalOf);
            if (word) { wordsToKinds[word] = key; }
        }

        const rtResult = spawnSync('php', ['-r', roundTripScript], { input: JSON.stringify(Object.keys(wordsToKinds)), encoding: 'utf8' });

        test('the detector round-trip probe actually ran (guard against a silently-empty comparison)', () => {
            assert.equal(rtResult.status, 0, 'php -r exited ' + rtResult.status + ': ' + rtResult.stderr);
        });

        if (rtResult.status === 0) {
            const findings = JSON.parse(rtResult.stdout);
            test('EVERY exported kind\'s canonical marker word (all 20 non-named-singer kinds, derived from the live vocabulary) round-trips through vocalPartDetectClassifyLine() to the SAME kind', () => {
                for (const [word, expectedKind] of Object.entries(wordsToKinds)) {
                    const found = findings[word];
                    assert.ok(found !== null, `"${word}" (expected kind "${expectedKind}") was not detected as a voice cue at all`);
                    assert.equal(found.form, 'standalone', `"${word}" matched form "${found.form}", expected "standalone"`);
                    assert.equal(found.kind, expectedKind, `"${word}" resolved to kind "${found.kind}", expected "${expectedKind}"`);
                }
            });
            test('SOLO (the soloist marker) is forced to LOW confidence — the ambiguity with the structural "Solo" SECTION type, #2073\'s own documented policy', () => {
                assert.equal(findings['SOLO'].confidence, 'low');
            });
            test('every OTHER kind\'s marker is high confidence (a real curator-typed all-caps cue is a strong signal) — proving SOLO\'s low confidence is the ambiguity floor doing something, not every finding defaulting to low', () => {
                const nonSolo = Object.entries(wordsToKinds).filter(([, kind]) => kind !== 'soloist');
                assert.ok(nonSolo.length > 5, 'expected several non-soloist kinds to check');
                for (const [word] of nonSolo) {
                    assert.equal(findings[word].confidence, 'high', `"${word}" was not high confidence`);
                }
            });
        } else {
            skip('detector round-trip assertions', 'the PHP round-trip probe above failed to run — see that assertion\'s failure for detail');
        }

        /* named-singer, PROVEN not to round-trip — pinning the documented
           limitation as a tested fact. An arbitrary human name is outside
           the detector's closed marker-word vocabulary BY DESIGN (the
           vocabulary count assertion above proves the list is genuinely
           empty, not just under-populated). */
        const nameScript =
            'require ' + JSON.stringify(DETECT_PHP_PATH) + ';' +
            'echo json_encode(vocalPartDetectClassifyLine($argv[1]));';
        const nameResult = spawnSync('php', ['-r', nameScript, '--', 'FRED BLOGGS'], { encoding: 'utf8' });
        test('a named-singer marker ("FRED BLOGGS", an arbitrary human name) does NOT round-trip through the detector — a genuine, pre-existing, documented one-way trip, not a regression this commit introduces', () => {
            assert.equal(nameResult.status, 0, 'php -r exited ' + nameResult.status + ': ' + nameResult.stderr);
            assert.equal(nameResult.stdout.trim(), 'null');
        });
    } else {
        skip('markerKeywordMap lockstep + detector round-trip', 'the PHP vocabulary probe above failed to run — see that assertion\'s failure for detail');
    }

    /* ======================================================================
     * §5-§7 — what each format's REAL, unmodified importer actually does
     *          with the marker this commit now writes (not just the
     *          detector in isolation) — the "one-way trip" question the
     *          task asked for, answered against the real tree.
     * ====================================================================== */
    console.log('\n3 — the REAL importers, exercised end-to-end (not just the detector)');

    /* §5 — Proclaim: the marker word survives as an ordinary lyric line,
       inside the SAME section, because Proclaim's importer only ever ends
       a block on a genuine blank line, and this export never inserts one
       before a marker. */
    const proclaimSong = {
        title: 'Psalm 91',
        components: [{
            type: 'verse', number: 1,
            lines: ['line one', 'line two', 'line three'],
            voices: [{ from: 1, to: 1, parts: [{ id: 1, kind: 'female', label: 'Women' }] }],
        }],
    };
    const proclaimText = buildProclaim(proclaimSong);
    const proclaimImportScript =
        'require ' + JSON.stringify(IMPORTERS_PHP_PATH) + ';' +
        '$body = file_get_contents("php://stdin");' +
        '[$parsed, $err] = _bulkImport_parseProclaimText($body, "test.txt");' +
        'echo json_encode(["err" => $err, "components" => $parsed["components"] ?? null]);';
    const proclaimResult = spawnSync('php', ['-r', proclaimImportScript], { input: proclaimText, encoding: 'utf8' });
    test('the exported Proclaim/plain-text file re-imports through the REAL, unmodified Proclaim importer without error', () => {
        assert.equal(proclaimResult.status, 0, 'php -r exited ' + proclaimResult.status + ': ' + proclaimResult.stderr);
    });
    if (proclaimResult.status === 0) {
        const reimported = JSON.parse(proclaimResult.stdout);
        test('…and the WOMEN marker survives, verbatim, as an ordinary lyric line inside the SAME "Verse 1" section — no data loss, no fake section, exactly like the fixed .txt bulk importer\'s own #2075 behaviour', () => {
            assert.equal(reimported.err, null);
            assert.equal(reimported.components.length, 1, 'expected the marker to stay INSIDE one section, not split it into two');
            assert.deepEqual(reimported.components[0].lines, ['line one', 'WOMEN', 'line two', 'line three']);
        });
    } else {
        skip('Proclaim round-trip assertion', 'the PHP re-import above failed to run — see that assertion\'s failure for detail');
    }

    /* §6 — ChordPro: PROVE, not merely claim, that iHymns' own reimporter
       currently drops the word (the documented gap in format-export.js's
       own comment on buildChordPro()). This pins today's REAL behaviour so
       a future fix to _bulkImport_chordProSectionFromLabel() is a visible,
       deliberate change to this assertion, never a silent regression back
       to it. */
    const chordProSong = {
        title: 'Psalm 91',
        components: [{
            type: 'verse', number: 1,
            lines: ['line one', 'line two', 'line three'],
            voices: [{ from: 1, to: 1, parts: [{ id: 1, kind: 'female', label: 'Women' }] }],
        }],
    };
    const chordProText = buildChordPro(chordProSong, {});
    const chordProImportScript =
        'require ' + JSON.stringify(IMPORTERS_PHP_PATH) + ';' +
        '$body = file_get_contents("php://stdin");' +
        '[$song, $err] = _bulkImport_parseChordPro($body, "PC", "ChordPro Import", 1);' +
        'echo json_encode(["err" => $err, "components" => $song["components"] ?? null]);';
    const chordProResult = spawnSync('php', ['-r', chordProImportScript], { input: chordProText, encoding: 'utf8' });
    test('the exported ChordPro file re-imports through the REAL, unmodified ChordPro importer without error', () => {
        assert.equal(chordProResult.status, 0, 'php -r exited ' + chordProResult.status + ': ' + chordProResult.stderr);
    });
    if (chordProResult.status === 0) {
        const reimported = JSON.parse(chordProResult.stdout);
        test('⚠️ DOCUMENTED GAP, proven not assumed: the WOMEN marker is currently LOST on reimport through iHymns\' OWN ChordPro importer — it starts a fresh, UNLABELLED refrain component (the exact #2075 bug, at a fifth, un-patched site: _bulkImport_chordProSectionFromLabel() still calls the old _bulkImport_componentTypeFor(), never the shared _bulkImport_classifyMarker()). The marker still displays correctly in any REAL ChordPro reader and still round-trips through the shared detector on its own (§2) — only iHymns\' own reimport of its own export is affected. See format-export.js\'s buildChordPro() for the full note and the recommended follow-up.', () => {
            assert.equal(reimported.err, null);
            assert.equal(reimported.components.length, 2, 'expected the {comment: WOMEN} to split off its own component (today\'s real, if unfortunate, behaviour)');
            assert.equal(reimported.components[1].type, 'refrain');
            assert.equal(reimported.components[1].label ?? null, null, 'expected the word to be LOST — no label at all — pinning the gap so a future fix is a visible, deliberate diff to this assertion');
            assert.deepEqual(reimported.components[1].lines, ['line two', 'line three']);
        });
    } else {
        skip('ChordPro round-trip assertion', 'the PHP re-import above failed to run — see that assertion\'s failure for detail');
    }

    /* §7 — OpenSong: the [WOMEN] BRACKET tag (not the plan's originally
       proposed `;WOMEN` comment row — see this file's own header for why)
       survives through the REAL, unmodified OpenSong importer as a
       labelled component, via the ALREADY-SHIPPED #2075 bracket-tag fix. */
    const openSongSong = {
        title: 'Psalm 91',
        components: [{
            type: 'verse', number: 1,
            lines: ['line one', 'line two', 'line three'],
            voices: [{ from: 1, to: 1, parts: [{ id: 1, kind: 'female', label: 'Women' }] }],
        }],
    };
    const openSongXml = buildOpenSong(openSongSong, {});
    /* Pull the <lyrics> text out of the built document the same way a real
       OpenSong reader would (via the DOM), then feed exactly that text
       through the real parser — proving the FULL export -> import path,
       not just a hand-typed fragment. */
    const openSongImportScript =
        'require ' + JSON.stringify(IMPORTERS_PHP_PATH) + ';' +
        '$xml = file_get_contents("php://stdin");' +
        '$doc = new SimpleXMLElement($xml);' +
        '$comps = _bulkImport_parseOpenSongLyrics((string)$doc->lyrics);' +
        'echo json_encode($comps);';
    const openSongResult = spawnSync('php', ['-r', openSongImportScript], { input: openSongXml, encoding: 'utf8' });
    test('the exported OpenSong file\'s <lyrics> text re-imports through the REAL, unmodified OpenSong importer without error', () => {
        assert.equal(openSongResult.status, 0, 'php -r exited ' + openSongResult.status + ': ' + openSongResult.stderr);
    });
    if (openSongResult.status === 0) {
        const comps = JSON.parse(openSongResult.stdout);
        test('…and the [WOMEN] bracket tag survives as a LABELLED component ("refrain", label "WOMEN") via the already-shipped #2075 fix — the word is never lost, even though it lands as its own small section rather than being merged back into "verse 1"', () => {
            assert.equal(comps.length, 2, 'expected the bracket tag to split into its own labelled component (today\'s real, #2075-fixed behaviour)');
            assert.equal(comps[0].lines[0], 'line one');
            assert.equal(comps[1].type, 'refrain');
            assert.equal(comps[1].label, 'WOMEN');
            assert.deepEqual(comps[1].lines, ['line two', 'line three']);
        });
    } else {
        skip('OpenSong round-trip assertion', 'the PHP re-import above failed to run — see that assertion\'s failure for detail');
    }
}

/* ==========================================================================
 * 4 — buildProclaim() / buildChordPro() / buildOpenSong(): byte-identical
 *     no-op for a voice-less song (PINNED, captured from the UNMODIFIED
 *     pre-#2073-commit-13 exporter — the same captured-before discipline
 *     tests/test-chordpro-export.js and tests/test-openlyrics-export-parts.js
 *     both already document), so a regression here is a STRING mismatch,
 *     not just "looks about right".
 * ========================================================================== */
console.log('\n4 — byte-identical no-op for a voice-less song');

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

const PINNED_PROCLAIM =
    'Amazing Grace\n' +
    '\nVerse 1\n' +
    'Amazing grace, how sweet the sound\nThat saved a wretch like me\n' +
    '\nChorus\n' +
    'Chorus line one\nChorus line two\nChorus line three\nChorus line four\n';
test('buildProclaim() on a voice-less song is BYTE-IDENTICAL to the pre-commit-13 output', () => {
    assert.equal(buildProclaim(PLAIN_SONG), PINNED_PROCLAIM);
});

const PINNED_CHORDPRO =
    '{title: Amazing Grace}\n{artist: John Newton}\n{ccli: 12345}\n{copyright: Public Domain}\n' +
    '\n{comment: Verse 1}\n' +
    'Amazing grace, how sweet the sound\nThat saved a wretch like me\n' +
    '\n{comment: Chorus}\n' +
    'Chorus line one\nChorus line two\nChorus line three\nChorus line four\n';
test('buildChordPro() on a voice-less song is BYTE-IDENTICAL to the pre-commit-13 output', () => {
    assert.equal(buildChordPro(PLAIN_SONG, {}), PINNED_CHORDPRO);
});
const CHORDS_SONG = {
    title: 'Chords Song',
    components: [
        { type: 'verse', number: 1, lines: ['Amazing grace how sweet', 'the sound'], chords: [['G', 'C'], null] },
    ],
};
const PINNED_CHORDPRO_WITH_CHORDS =
    '{title: Chords Song}\n\n{comment: Verse 1}\n[G]Amazing [C]grace how sweet\nthe sound\n';
test('buildChordPro() with real chords but NO voices is ALSO byte-identical (the marker walk never disturbs the existing chord-interleave path)', () => {
    assert.equal(buildChordPro(CHORDS_SONG, {}), PINNED_CHORDPRO_WITH_CHORDS);
});

const PINNED_OPENSONG =
    '<?xml version="1.0" encoding="UTF-8"?>\n<song>\n' +
    '  <title>Amazing Grace</title>\n' +
    '  <author>John Newton</author>\n' +
    '  <copyright>Public Domain</copyright>\n' +
    '  <ccli>12345</ccli>\n' +
    '  <hymn_number>12</hymn_number>\n' +
    '  <lyrics>[V1]\n Amazing grace, how sweet the sound\n That saved a wretch like me\n[C0]\n Chorus line one\n Chorus line two\n Chorus line three\n Chorus line four\n</lyrics>\n' +
    '</song>\n';
test('buildOpenSong() on a voice-less song is BYTE-IDENTICAL to the pre-commit-13 output (no chunking option)', () => {
    assert.equal(buildOpenSong(PLAIN_SONG, {}), PINNED_OPENSONG);
});
const PINNED_OPENSONG_CHUNK2 =
    '<?xml version="1.0" encoding="UTF-8"?>\n<song>\n' +
    '  <title>Amazing Grace</title>\n' +
    '  <author>John Newton</author>\n' +
    '  <copyright>Public Domain</copyright>\n' +
    '  <ccli>12345</ccli>\n' +
    '  <hymn_number>12</hymn_number>\n' +
    '  <lyrics>[V1]\n Amazing grace, how sweet the sound\n That saved a wretch like me\n[C0]\n Chorus line one\n Chorus line two\n\n Chorus line three\n Chorus line four\n</lyrics>\n' +
    '</song>\n';
test('…and BYTE-IDENTICAL with maxLinesPerSlide chunking too — this commit adds a channel, it does not change the old one', () => {
    assert.equal(buildOpenSong(PLAIN_SONG, { maxLinesPerSlide: 2 }), PINNED_OPENSONG_CHUNK2);
});

test('a component with an EMPTY voices array behaves exactly like one with none at all, in all three formats', () => {
    const song = { title: 'Empty voices', components: [{ type: 'verse', number: 1, lines: ['One line'], voices: [] }] };
    assert.equal(buildProclaim(song), 'Empty voices\n\nVerse 1\nOne line\n');
    assert.equal((buildChordPro(song, {}).match(/\{comment:/g) || []).length, 1, 'only the section {comment:} — no marker {comment:} for an empty voices array');
    assert.equal((buildOpenSong(song, {}).match(/\[[A-Z0-9]*\]/g) || []).length, 1, 'only the [V1] section tag — no bracket MARKER tag for an empty voices array');
});

/* ==========================================================================
 * 5 — the issue's own worked example, exercised end-to-end for all three
 *     formats: EXACT expected output, not just "contains" — proves marker
 *     placement (immediately before its run, no blank line) and that gaps
 *     around a run are untouched.
 * ========================================================================== */
console.log('\n5 — the women/men/all worked example, exact output, all three formats');

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

test('buildProclaim(): WOMEN/MEN/ALL each print as their OWN line, directly before the two/two/one lines they cover, with no blank line between a marker and its run', () => {
    assert.equal(
        buildProclaim(VOICED_SONG),
        'Psalm 91\n\nVerse 1\n'
        + 'WOMEN\nHe who dwells, he who dwells\nin the shelter of the Most High,\n'
        + 'MEN\nhe who dwells, he who dwells\nin the shelter of the Most High\n'
        + "ALL\nAnd I'll say of the Lord, 'He is my refuge';\n"
    );
});

test('buildChordPro(): each run gets its OWN {comment: WOMEN}/{comment: MEN}/{comment: ALL} directive, right before its lines', () => {
    assert.equal(
        buildChordPro(VOICED_SONG, {}),
        '{title: Psalm 91}\n\n{comment: Verse 1}\n'
        + '{comment: WOMEN}\nHe who dwells, he who dwells\nin the shelter of the Most High,\n'
        + '{comment: MEN}\nhe who dwells, he who dwells\nin the shelter of the Most High\n'
        + "{comment: ALL}\nAnd I'll say of the Lord, 'He is my refuge';\n"
    );
});

test('buildOpenSong(): each run gets its OWN [WOMEN]/[MEN]/[ALL] bracket tag, right before its lines, inside the SAME [V1] section', () => {
    const xml = buildOpenSong(VOICED_SONG, {});
    assert.match(xml, /<lyrics>\[V1\]\n\[WOMEN\]\n He who dwells, he who dwells\n in the shelter of the Most High,\n\[MEN\]\n he who dwells, he who dwells\n in the shelter of the Most High\n\[ALL\]\n And I&apos;ll say of the Lord, &apos;He is my refuge&apos;;\n<\/lyrics>/);
    /* Only ONE [V1] section tag — the voice runs are markers WITHIN it in
       the exported text, even though (§7 above) the real importer happens
       to split them into separate components on reimport. */
    assert.equal((xml.match(/\[V\d*\]/g) || []).length, 1);
});

/* A mixed component: an unvoiced gap BEFORE and AFTER a voiced run, under a
   maxLinesPerSlide that WOULD split a longer gap — proving OpenSong's
   chunking still applies to the gaps but never touches the run itself
   (mirrors the ALREADY-SHIPPED OpenLyrics precedent, #2071). */
const MIXED_SONG = {
    title: 'Mixed',
    components: [{
        type: 'verse', number: 1,
        lines: ['Gap one', 'Gap two', 'Gap three', 'Voiced line', 'Tail one', 'Tail two'],
        voices: [{ from: 3, to: 3, parts: [{ id: 10, kind: 'cantor' }] }],
    }],
};
test('OpenSong chunking still applies WITHIN the gaps around a run, and NEVER splits the run itself', () => {
    const xml = buildOpenSong(MIXED_SONG, { maxLinesPerSlide: 2 });
    assert.match(xml, / Gap one\n Gap two\n\n Gap three\n\[CANTOR\]\n Voiced line\n Tail one\n Tail two\n/);
});

console.log(`\nExport voice markers: ${passed} passed, ${failed} failed, ${skipped} skipped`);
if (failed) {
    failures.forEach((f) => console.log(`  - ${f.name}: ${f.error}`));
    process.exit(1);
}
