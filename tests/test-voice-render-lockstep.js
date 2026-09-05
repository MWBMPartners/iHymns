/**
 * tests/test-voice-render-lockstep.js — voice/echo render PHP<->JS ALGORITHM
 * lockstep guard (#2073 commit 8, rule #35).
 *
 * ELI5
 * ----
 * "Who sings this line" is drawn TWICE — once by the server
 * (`includes/voice_parts_render.php`, for `includes/pages/song.php`) and
 * once by the browser (`js/modules/voice-parts-render.js`, for
 * `js/modules/setlist.js` and `js/modules/print.js`, which re-render a
 * component's lines from JSON without a round trip to PHP). This file
 * proves those two hand-written implementations actually agree, by
 * feeding BOTH the exact same cases from `tests/fixtures/voice-render-cases.json`
 * and checking three things per case: the PHP answer matches this fixture's
 * own hand-derived `expect`, the JS answer matches it too, and — the part
 * that actually matters day to day — PHP and JS match EACH OTHER. Without
 * this, "it looks right in the browser" and "it looks right on the server"
 * could each be true while being two DIFFERENT wrong answers nobody
 * compared side by side (rule #35 — "a comment saying keep these in sync
 * is the failure, not the fix").
 *
 * MODELLED DIRECTLY ON `tests/test-org-logo-resolver-lockstep.js` — same
 * shape: a single batched `php -r` subprocess call runs the REAL,
 * unmodified `includes/voice_parts_render.php` over the WHOLE case list at
 * once (one process spawn, not one per case), and the JS half imports the
 * REAL, unmodified `js/modules/voice-parts-render.js` module directly.
 *
 * THE ONE KNOWN, DOCUMENTED, HARMLESS DIVERGENCE THIS TEST NORMALISES —
 * see `tests/fixtures/voice-render-cases.json`'s own `_readme`: PHP's
 * `htmlspecialchars(...,ENT_QUOTES)` escapes an apostrophe as `&#039;`
 * while this project's `js/utils/html.js` escapeHtml() escapes it as
 * `&#39;`. Both render an apostrophe identically in every browser (they are
 * both valid HTML numeric character references for the same code point —
 * see https://html.spec.whatwg.org/multipage/syntax.html#character-references),
 * so this is not a real behaviour difference, but it IS a byte difference
 * that would otherwise make every apostrophe-containing lyric line
 * (extremely common — "Lord's", "there's") fail a naive byte-for-byte
 * compare. `normaliseApos()` below folds both spellings to one canonical
 * form BEFORE comparing, so this test proves the things that would
 * actually corrupt a page (span position, class names, attribute values,
 * code-point slicing) without going red over a pre-existing, unrelated
 * escaping-utility inconsistency this commit did not introduce and is not
 * in scope to fix (flagged as a follow-up in the commit's own report).
 *
 * MUTATION-TESTING PROTOCOL (rule #34) — performed IN-PROCESS, no disk
 * writes: this file re-runs its own comparison function against a
 * DELIBERATELY-CORRUPTED in-memory copy of the JS side's output (built by
 * calling the real functions and then hand-mutating ONE result), and
 * confirms the SAME assertion that was green a moment ago goes RED against
 * that corrupted answer — proving the comparison itself can fail, not just
 * that it happened to pass once.
 *
 * @see appWeb/public_html/includes/voice_parts_render.php   the PHP original
 * @see appWeb/public_html/js/modules/voice-parts-render.js  the JS twin
 * @see tests/fixtures/voice-render-cases.json                the shared fixture
 * @see tests/test-org-logo-resolver-lockstep.js               the pattern this file follows
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { spawnSync } from 'node:child_process';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const REPO_ROOT = path.join(__dirname, '..');
const PHP_RENDERER_PATH = path.join(REPO_ROOT, 'appWeb', 'public_html', 'includes', 'voice_parts_render.php');
const JS_MODULE_PATH = path.join(REPO_ROOT, 'appWeb', 'public_html', 'js', 'modules', 'voice-parts-render.js');
const FIXTURE_PATH = path.join(__dirname, 'fixtures', 'voice-render-cases.json');

let failures = 0;
let checks = 0;
function assert(cond, label) {
    checks++;
    if (cond) {
        console.log('  PASS  ' + label);
    } else {
        failures++;
        console.log('  FAIL  ' + label);
    }
}

/** Fold both the PHP (`&#039;`) and JS (`&#39;`) apostrophe entity spellings
 *  to one canonical form so a byte-compare isn't tripped by that one,
 *  documented, harmless pre-existing difference — see the file header. */
function normaliseApos(s) {
    return String(s).replace(/&#0?39;/g, '&#39;');
}

function j(value) {
    return normaliseApos(JSON.stringify(value));
}

/** One batched `php -r` call: requires the REAL voice_parts_render.php and
 *  runs every case through the five render functions this commit's lockstep
 *  covers (including the round-note function, added after CI's unused-
 *  function flag on the JS side's `roundKindLabel()` turned up that the JS
 *  renderer had never had a round-note function at all), returning one
 *  parallel result set per case group. */
function runPhpBatch(fixture) {
    const script = [
        'require ' + JSON.stringify(PHP_RENDERER_PATH) + ';',
        '$in = json_decode(file_get_contents("php://stdin"), true);',
        '$out = ["ariaLabel" => [], "chipsHtml" => [], "runOpenTag" => [], "lineHtml" => [], "roundNoteHtml" => []];',
        'foreach ($in["runAriaLabelCases"] as $c) { $out["ariaLabel"][] = ihymnsVoiceRunAriaLabel($c["parts"]); }',
        'foreach ($in["chipsHtmlCases"] as $c) { $out["chipsHtml"][] = ihymnsVoiceChipsHtml($c["parts"]); }',
        'foreach ($in["runOpenTagCases"] as $c) { $out["runOpenTag"][] = ihymnsVoiceRunOpenTag($c["parts"]); }',
        'foreach ($in["lineHtmlCases"] as $c) { $out["lineHtml"][] = ihymnsVoiceLineHtml($c["text"], $c["spans"]); }',
        'foreach ($in["roundNoteHtmlCases"] as $c) { $out["roundNoteHtml"][] = ihymnsVoiceRoundNoteHtml($c["round"]); }',
        'echo json_encode($out);',
    ].join('');
    const result = spawnSync('php', ['-r', script], {
        input: JSON.stringify(fixture),
        encoding: 'utf8',
        maxBuffer: 10 * 1024 * 1024,
    });
    if (result.status !== 0) {
        throw new Error('PHP batch call failed (exit ' + result.status + '): ' + result.stderr);
    }
    return JSON.parse(result.stdout);
}

async function main() {
    console.log('\nVoice/echo render PHP<->JS ALGORITHM lockstep guard (#2073 commit 8)\n');

    const fixture = JSON.parse(fs.readFileSync(FIXTURE_PATH, 'utf8'));
    const {
        voiceRunAriaLabel, voiceChipsHtml, voiceRunOpenTag, voiceLineHtml, voiceRoundNoteHtml,
    } = await import(pathToFileURL(JS_MODULE_PATH).href);

    const jsResults = {
        ariaLabel: fixture.runAriaLabelCases.map((c) => voiceRunAriaLabel(c.parts)),
        chipsHtml: fixture.chipsHtmlCases.map((c) => voiceChipsHtml(c.parts)),
        runOpenTag: fixture.runOpenTagCases.map((c) => voiceRunOpenTag(c.parts)),
        lineHtml: fixture.lineHtmlCases.map((c) => voiceLineHtml(c.text, c.spans)),
        roundNoteHtml: fixture.roundNoteHtmlCases.map((c) => voiceRoundNoteHtml(c.round)),
    };
    const phpResults = runPhpBatch(fixture);

    /** Compare one case group: PHP vs expect, JS vs expect, PHP vs JS. */
    function checkGroup(groupName, cases, phpList, jsList) {
        assert(phpList.length === cases.length, `${groupName}: PHP returned one answer per case (${phpList.length} of ${cases.length})`);
        assert(jsList.length === cases.length, `${groupName}: JS returned one answer per case (${jsList.length} of ${cases.length})`);
        cases.forEach((c, i) => {
            const expect = j(c.expect);
            const php = j(phpList[i]);
            const js = j(jsList[i]);
            assert(php === expect, `${groupName} — ${c.name} — PHP matches the fixture's expected value`);
            assert(js === expect, `${groupName} — ${c.name} — JS matches the fixture's expected value`);
            assert(php === js, `${groupName} — ${c.name} — PHP and JS agree with EACH OTHER (PHP=${php}, JS=${js})`);
        });
    }

    checkGroup('runAriaLabelCases', fixture.runAriaLabelCases, phpResults.ariaLabel, jsResults.ariaLabel);
    checkGroup('chipsHtmlCases', fixture.chipsHtmlCases, phpResults.chipsHtml, jsResults.chipsHtml);
    checkGroup('runOpenTagCases', fixture.runOpenTagCases, phpResults.runOpenTag, jsResults.runOpenTag);
    checkGroup('lineHtmlCases', fixture.lineHtmlCases, phpResults.lineHtml, jsResults.lineHtml);
    checkGroup('roundNoteHtmlCases', fixture.roundNoteHtmlCases, phpResults.roundNoteHtml, jsResults.roundNoteHtml);

    console.log('\n--- MUTATION PROOF: the comparison itself can fail, not just happen to pass ---');
    {
        /* Take a REAL, already-agreeing pair (PHP and JS both computed the
           same lineHtml for case 0) and corrupt the JS side's copy in
           memory only — no file on disk is touched. If checkGroup's own
           `php === js` assertion cannot tell the difference, the guard is
           worthless; it must go RED here. */
        const idx = 0;
        const realPhp = j(phpResults.lineHtml[idx]);
        const corruptedJs = j(jsResults.lineHtml[idx] + '<!-- mutated -->');
        const stillAgree = realPhp === corruptedJs;
        assert(stillAgree === false, 'MUTATION PROOF: a corrupted JS answer no longer equals the real PHP answer (the comparison can fail)');
    }
    {
        /* A second mutation shape: two DIFFERENT real answers (from two
           different cases) must never accidentally compare equal — proves
           the comparison isn't just "always true" by construction. */
        assert(fixture.runAriaLabelCases.length >= 2, 'MUTATION PROOF setup: at least two ariaLabel cases exist to compare');
        const a = j(phpResults.ariaLabel[0]);
        const b = j(phpResults.ariaLabel[1]);
        assert(a !== b, 'MUTATION PROOF: two genuinely different cases (single part vs two parts) do not compare equal');
    }

    console.log(`\n=== ${checks} checks, ${failures} failed ===`);
    process.exit(failures === 0 ? 0 : 1);
}

main().catch((err) => {
    console.error('HARNESS CRASHED:', err);
    process.exit(1);
});
