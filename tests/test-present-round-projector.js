/**
 * tests/test-present-round-projector.js — round/canon projector guard (#2073).
 *
 * ELI5
 * ----
 * A "round" is several groups singing the SAME words, each starting later
 * than the one before ("Row, row, row your boat" with three groups). The
 * schedule ("who's on which line, right now") is worked out ONCE, in PHP —
 * `lyricRoundTimeline()` in `includes/lyric_rounds.php` — and worked out
 * AGAIN, independently, in JavaScript — `roundTimeline()` in
 * `js/modules/present-mode.js` — because the browser needs to step/play a
 * round without a round trip to the server on every click. Two hand-written
 * copies of the same maths drift apart over time if nothing ever checks
 * they agree (rule #35 in `.claude/CLAUDE.md` — "a comment saying keep
 * these in sync is the failure, not the fix"). This file is that check.
 *
 * It proves three separate things, in three sections:
 *
 *   1. LOCKSTEP — feeds the EXACT scenarios that
 *      `tests/php/test-lyric-rounds-timeline.php` already hand-derived
 *      (three-voice canon, uneven offsets, the "beats" basis, the "ms"
 *      basis with its documented rounding, "together", "coda" under
 *      "lines"/"beats"/"ms" with the ms-degrade corner) into BOTH the real
 *      PHP function (one batched `php -r` subprocess, modelled on
 *      `tests/test-org-logo-resolver-lockstep.js`) and the real JS
 *      function, and checks all three of: PHP matches the hand-derived
 *      expectation, JS matches it too, and PHP matches JS. Reusing the
 *      SAME hand-derived numbers as the PHP-only test (rather than
 *      re-deriving them, or worse, trusting either function's own output
 *      as "the answer") is deliberate — it is independently-authored
 *      ground truth, not a comparison against itself.
 *
 *   2. TIMER DISCIPLINE — `createRoundAutoAdvance()` is the ONE clock a
 *      playing round ever starts. This repo has no jsdom (see
 *      `tests/test-voice-render-sites.js`'s own comment on the same
 *      point), so rather than fake a whole browser to click a Play button,
 *      this section drives that scheduler DIRECTLY with fake
 *      `scheduleFn`/`cancelFn` stubs and counts calls — proving every
 *      `start()` is eventually matched by a `stop()`, that `stop()` is
 *      idempotent, and that a MUTATED (deliberately broken) version of the
 *      same shape is caught by the same counting technique (rule #34 — a
 *      guard must be proven able to go red, not just happen to be green).
 *
 *   3. THE OVERLAY'S OWN TEARDOWN — a structural check (comment-stripped,
 *      so a mention of the call inside a doc-comment can't satisfy it) that
 *      `close()` in `present-mode.js` calls the round-stopping function as
 *      its very first statement, before any early return — rule #32's
 *      sibling in `.claude/CLAUDE.md` ("any timer you start MUST be
 *      cleared when the overlay closes … as its first action"). Deleting
 *      that one line is the exact mutation this check exists to catch.
 *
 * @see appWeb/public_html/includes/lyric_rounds.php    lyricRoundTimeline(), the PHP original
 * @see appWeb/public_html/js/modules/present-mode.js   roundTimeline() + createRoundAutoAdvance(), the JS twins
 * @see tests/php/test-lyric-rounds-timeline.php         the independently-authored ground truth this file reuses
 * @see tests/test-voice-render-lockstep.js              the PHP<->JS lockstep pattern this file follows
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { spawnSync } from 'node:child_process';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const REPO_ROOT = path.join(__dirname, '..');
const PHP_ROUNDS_PATH = path.join(REPO_ROOT, 'appWeb', 'public_html', 'includes', 'lyric_rounds.php');
const JS_MODULE_PATH = path.join(REPO_ROOT, 'appWeb', 'public_html', 'js', 'modules', 'present-mode.js');

let failures = 0;
let checks = 0;
function assert(cond, label, detail) {
    checks++;
    if (cond) {
        console.log('  PASS  ' + label);
    } else {
        failures++;
        console.log('  FAIL  ' + label);
        if (detail !== undefined) { console.log('        ' + detail); }
    }
}

/* ========================================================================
 * Shared test scenarios — transcribed from tests/php/test-lyric-rounds-timeline.php
 * section 3, so the "expected" numbers here are independently hand-derived,
 * not produced by either function under test.
 * ====================================================================== */

const SUBJECT = [10, 20, 30, 40];

const CASES = [
    {
        name: '3a: three-voice canon, entryLines 0/2/4, complete',
        round: { timesThrough: 2, endingMode: 'complete', bpm: null, beatsPerLine: null },
        voices: [
            { number: 1, entryBasis: 'lines', entryLines: 0, entryBeats: null, entryMs: null, timesThrough: null },
            { number: 2, entryBasis: 'lines', entryLines: 2, entryBeats: null, entryMs: null, timesThrough: null },
            { number: 3, entryBasis: 'lines', entryLines: 4, entryBeats: null, entryMs: null, timesThrough: null },
        ],
        lineStartMs: {}, lineEndMs: {},
        expectBasis: 'lines',
        expectStepMs: null,
        expectStepCount: 12,
        expectStepLines: {
            0: { 1: 0, 2: -1, 3: -1 }, 1: { 1: 1, 2: -1, 3: -1 },
            2: { 1: 2, 2: 0, 3: -1 }, 3: { 1: 3, 2: 1, 3: -1 },
            4: { 1: 0, 2: 2, 3: 0 }, 5: { 1: 1, 2: 3, 3: 1 },
            6: { 1: 2, 2: 0, 3: 2 }, 7: { 1: 3, 2: 1, 3: 3 },
            8: { 1: -2, 2: 2, 3: 0 }, 9: { 1: -2, 2: 3, 3: 1 },
            10: { 1: -2, 2: -2, 3: 2 }, 11: { 1: -2, 2: -2, 3: 3 },
        },
    },
    {
        name: '3b: uneven entry offsets (0, 1, 3), complete',
        round: { timesThrough: 2, endingMode: 'complete', bpm: null, beatsPerLine: null },
        voices: [
            { number: 1, entryBasis: 'lines', entryLines: 0, entryBeats: null, entryMs: null, timesThrough: null },
            { number: 2, entryBasis: 'lines', entryLines: 1, entryBeats: null, entryMs: null, timesThrough: null },
            { number: 3, entryBasis: 'lines', entryLines: 3, entryBeats: null, entryMs: null, timesThrough: null },
        ],
        lineStartMs: {}, lineEndMs: {},
        expectBasis: 'lines',
        expectStepMs: null,
        expectStepCount: 11,
        expectStepLines: {
            0: { 1: 0, 2: -1, 3: -1 },
            1: { 1: 1, 2: 0, 3: -1 },
            3: { 1: 3, 2: 2, 3: 0 },
            8: { 1: -2, 2: 3, 3: 1 },
            10: { 1: -2, 2: -2, 3: 3 },
        },
    },
    {
        name: '3c: beats basis, bpm=100 beatsPerLine=4 -> stepMs=2400',
        round: { timesThrough: 2, endingMode: 'complete', bpm: 100.0, beatsPerLine: 4.0 },
        voices: [
            { number: 1, entryBasis: 'lines', entryLines: 0, entryBeats: null, entryMs: null, timesThrough: null },
            { number: 2, entryBasis: 'beats', entryLines: 0, entryBeats: 8.0, entryMs: null, timesThrough: null },
            { number: 3, entryBasis: 'beats', entryLines: 0, entryBeats: 16.0, entryMs: null, timesThrough: null },
        ],
        lineStartMs: {}, lineEndMs: {},
        expectBasis: 'beats',
        expectStepMs: 2400,
        expectStepCount: 12,
        expectStepLines: {
            0: { 1: 0, 2: -1, 3: -1 },
            8: { 1: -2, 2: 2, 3: 0 },
        },
        expectAtMs: { 0: 0, 1: 2400, 11: 26400 },
    },
    {
        name: '3d: ms basis, four equal 1000ms subject lines',
        round: { timesThrough: 2, endingMode: 'complete', bpm: null, beatsPerLine: null },
        voices: [
            { number: 1, entryBasis: 'lines', entryLines: 0, entryBeats: null, entryMs: null, timesThrough: null },
            { number: 2, entryBasis: 'ms', entryLines: 0, entryBeats: null, entryMs: 2000, timesThrough: null },
            { number: 3, entryBasis: 'ms', entryLines: 0, entryBeats: null, entryMs: 4000, timesThrough: null },
        ],
        lineStartMs: { 10: 0, 20: 1000, 30: 2000, 40: 3000 },
        lineEndMs: { 10: 1000, 20: 2000, 30: 3000, 40: 4000 },
        expectBasis: 'ms',
        expectStepMs: null,
        expectStepCount: 12,
        expectStepLines: { 8: { 1: -2, 2: 2, 3: 0 } },
        expectAtMs: { 0: 0, 4: 4000, 11: 11000 },
    },
    {
        name: '3d-rounding: an entryMs between two line boundaries rounds UP to the next whole line-step',
        round: { timesThrough: 2, endingMode: 'complete', bpm: null, beatsPerLine: null },
        voices: [
            { number: 1, entryBasis: 'lines', entryLines: 0, entryBeats: null, entryMs: null, timesThrough: null },
            { number: 2, entryBasis: 'ms', entryLines: 0, entryBeats: null, entryMs: 1500, timesThrough: null },
            { number: 3, entryBasis: 'ms', entryLines: 0, entryBeats: null, entryMs: 4000, timesThrough: null },
        ],
        lineStartMs: { 10: 0, 20: 1000, 30: 2000, 40: 3000 },
        lineEndMs: { 10: 1000, 20: 2000, 30: 3000, 40: 4000 },
        expectBasis: 'ms',
        expectStepMs: null,
        expectStepCount: 12,
        expectStepLines: {
            1: { 1: 1, 2: -1, 3: -1 },
            2: { 1: 2, 2: 0, 3: -1 },
        },
    },
    {
        name: '3e: "together" ending — a longer per-voice override is cut mid-phrase, never allowed to finish',
        round: { timesThrough: 2, endingMode: 'together', bpm: null, beatsPerLine: null },
        voices: [
            { number: 1, entryBasis: 'lines', entryLines: 0, entryBeats: null, entryMs: null, timesThrough: null },
            { number: 2, entryBasis: 'lines', entryLines: 2, entryBeats: null, entryMs: null, timesThrough: null },
            { number: 3, entryBasis: 'lines', entryLines: 4, entryBeats: null, entryMs: null, timesThrough: 3 },
        ],
        lineStartMs: {}, lineEndMs: {},
        expectBasis: 'lines',
        expectStepMs: null,
        expectStepCount: 8,
        expectStepLines: { 7: { 1: 3, 2: 1, 3: 3 } },
        expectNeverFinishes: 3,
    },
    {
        name: '3f: "coda" ending under "lines" — every voice shows the SAME coda position together',
        round: { timesThrough: 2, endingMode: 'coda', bpm: null, beatsPerLine: null, codaLineIds: [50, 60] },
        voices: [
            { number: 1, entryBasis: 'lines', entryLines: 0, entryBeats: null, entryMs: null, timesThrough: null },
            { number: 2, entryBasis: 'lines', entryLines: 2, entryBeats: null, entryMs: null, timesThrough: null },
            { number: 3, entryBasis: 'lines', entryLines: 4, entryBeats: null, entryMs: null, timesThrough: null },
        ],
        lineStartMs: {}, lineEndMs: {},
        expectBasis: 'lines',
        expectStepMs: null,
        expectStepCount: 10,
        expectStepLines: { 8: { 1: 4, 2: 4, 3: 4 }, 9: { 1: 5, 2: 5, 3: 5 } },
    },
    {
        name: '3f-beats: "coda" under "beats" — atMs continues the same grid for the coda steps too',
        round: { timesThrough: 2, endingMode: 'coda', bpm: 100.0, beatsPerLine: 4.0, codaLineIds: [50, 60] },
        voices: [
            { number: 1, entryBasis: 'lines', entryLines: 0, entryBeats: null, entryMs: null, timesThrough: null },
            { number: 2, entryBasis: 'lines', entryLines: 2, entryBeats: null, entryMs: null, timesThrough: null },
            { number: 3, entryBasis: 'lines', entryLines: 4, entryBeats: null, entryMs: null, timesThrough: null },
        ],
        lineStartMs: {}, lineEndMs: {},
        expectBasis: 'beats',
        expectStepMs: 2400,
        expectStepCount: 10,
        expectStepLines: {},
        expectAtMs: { 8: 19200, 9: 21600 },
    },
    {
        name: '3f-ms: "coda" under "ms" — degrades a coda step (and every one after it) to null atMs when its OWN timing is missing, never a guess',
        round: { timesThrough: 2, endingMode: 'coda', bpm: null, beatsPerLine: null, codaLineIds: [50, 60] },
        voices: [
            { number: 1, entryBasis: 'lines', entryLines: 0, entryBeats: null, entryMs: null, timesThrough: null },
            { number: 2, entryBasis: 'ms', entryLines: 0, entryBeats: null, entryMs: 2000, timesThrough: null },
            { number: 3, entryBasis: 'ms', entryLines: 0, entryBeats: null, entryMs: 4000, timesThrough: null },
        ],
        lineStartMs: { 10: 0, 20: 1000, 30: 2000, 40: 3000, 50: 8000 },
        /* Line 60's own end-ms is deliberately OMITTED — the second coda
           line's own timing is simply not supplied (matches the PHP test's
           own "3f-ms degrade" scenario exactly). */
        lineEndMs: { 10: 1000, 20: 2000, 30: 3000, 40: 4000, 50: 8500 },
        expectBasis: 'ms',
        expectStepMs: null,
        expectStepCount: 10,
        expectStepLines: {},
        expectAtMs: { 8: 8000, 9: null },
    },
    {
        name: '3g: zero subject lines -> the empty timeline, never a crash',
        round: { timesThrough: 2, endingMode: 'complete', bpm: null, beatsPerLine: null },
        voices: [
            { number: 1, entryBasis: 'lines', entryLines: 0, entryBeats: null, entryMs: null, timesThrough: null },
        ],
        subject: [],
        lineStartMs: {}, lineEndMs: {},
        expectBasis: 'lines',
        expectStepMs: null,
        expectStepCount: 0,
        expectStepLines: {},
    },
];

/** Voice-number -> line for one step, the JS mirror of the PHP test's own
 *  `stepLines()` helper — used only to COMPARE, never to assert on its own. */
function stepLinesOf(timeline, i) {
    const out = {};
    (timeline.steps[i] ? timeline.steps[i].voices : []).forEach((v) => { out[v.n] = v.line; });
    return out;
}

/** Canonicalise a plain {number: number} map for a stable string compare —
 *  object key order is insertion order in practice but this removes any
 *  doubt, matching the PHP test's own `ksort()` before comparing. */
function canon(map) {
    const keys = Object.keys(map).map(Number).sort((a, b) => a - b);
    return JSON.stringify(keys.map((k) => [k, map[k]]));
}

/** One batched `php -r` call: requires the REAL lyric_rounds.php and runs
 *  every case's `lyricRoundTimeline()` at once, returning one timeline per
 *  case — one process spawn for the whole suite, not one per case. */
function runPhpBatch(cases) {
    const script = [
        'require ' + JSON.stringify(PHP_ROUNDS_PATH) + ';',
        '$in = json_decode(file_get_contents("php://stdin"), true);',
        '$out = [];',
        'foreach ($in as $c) {',
        '  $out[] = lyricRoundTimeline($c["round"], $c["voices"], $c["subject"], $c["lineStartMs"], $c["lineEndMs"]);',
        '}',
        'echo json_encode($out);',
    ].join('');
    const payload = cases.map((c) => ({
        round: c.round,
        voices: c.voices,
        subject: c.subject || SUBJECT,
        lineStartMs: c.lineStartMs,
        lineEndMs: c.lineEndMs,
    }));
    const result = spawnSync('php', ['-r', script], {
        input: JSON.stringify(payload),
        encoding: 'utf8',
        maxBuffer: 10 * 1024 * 1024,
    });
    if (result.status !== 0) {
        throw new Error('PHP batch call failed (exit ' + result.status + '): ' + result.stderr);
    }
    return JSON.parse(result.stdout);
}

async function main() {
    console.log('\nRound/canon projector guard (#2073)\n');
    console.log('1 — LOCKSTEP: PHP lyricRoundTimeline() vs its JS twin roundTimeline()\n');

    const { roundTimeline, createRoundAutoAdvance } = await import(pathToFileURL(JS_MODULE_PATH).href);

    const jsResults = CASES.map((c) => roundTimeline(c.round, c.voices, c.subject || SUBJECT, c.lineStartMs, c.lineEndMs));
    const phpResults = runPhpBatch(CASES);

    assert(phpResults.length === CASES.length, `PHP returned one timeline per case (${phpResults.length} of ${CASES.length})`);
    assert(jsResults.length === CASES.length, `JS returned one timeline per case (${jsResults.length} of ${CASES.length})`);

    CASES.forEach((c, idx) => {
        const php = phpResults[idx];
        const js = jsResults[idx];

        assert(php.basis === c.expectBasis, `${c.name} — PHP basis is "${c.expectBasis}"`, 'got: ' + php.basis);
        assert(js.basis === c.expectBasis, `${c.name} — JS basis is "${c.expectBasis}"`, 'got: ' + js.basis);
        assert(php.basis === js.basis, `${c.name} — PHP and JS agree on basis`);

        assert(php.stepMs === c.expectStepMs, `${c.name} — PHP stepMs is ${c.expectStepMs}`, 'got: ' + php.stepMs);
        assert(js.stepMs === c.expectStepMs, `${c.name} — JS stepMs is ${c.expectStepMs}`, 'got: ' + js.stepMs);

        assert(php.steps.length === c.expectStepCount, `${c.name} — PHP produced ${c.expectStepCount} steps`, 'got: ' + php.steps.length);
        assert(js.steps.length === c.expectStepCount, `${c.name} — JS produced ${c.expectStepCount} steps`, 'got: ' + js.steps.length);
        assert(php.steps.length === js.steps.length, `${c.name} — PHP and JS agree on step count`);

        Object.keys(c.expectStepLines || {}).forEach((iStr) => {
            const i = Number(iStr);
            const expected = canon(c.expectStepLines[i]);
            const phpLine = canon(stepLinesOf(php, i));
            const jsLine = canon(stepLinesOf(js, i));
            assert(phpLine === expected, `${c.name} — PHP step ${i} matches the hand-derived schedule`, `expected ${expected}, got ${phpLine}`);
            assert(jsLine === expected, `${c.name} — JS step ${i} matches the hand-derived schedule`, `expected ${expected}, got ${jsLine}`);
            assert(phpLine === jsLine, `${c.name} — PHP and JS agree on step ${i}`, `PHP ${phpLine}, JS ${jsLine}`);
        });

        Object.keys(c.expectAtMs || {}).forEach((iStr) => {
            const i = Number(iStr);
            const expected = c.expectAtMs[i];
            const phpAt = php.steps[i] ? php.steps[i].atMs : undefined;
            const jsAt = js.steps[i] ? js.steps[i].atMs : undefined;
            assert(phpAt === expected, `${c.name} — PHP atMs at step ${i} is ${expected}`, 'got: ' + phpAt);
            assert(jsAt === expected, `${c.name} — JS atMs at step ${i} is ${expected}`, 'got: ' + jsAt);
            assert(phpAt === jsAt, `${c.name} — PHP and JS agree on atMs at step ${i}`, `PHP ${phpAt}, JS ${jsAt}`);
        });

        if (c.expectNeverFinishes !== undefined) {
            const phpNever = !php.steps.some((s) => s.voices.some((v) => v.n === c.expectNeverFinishes && v.line === -2));
            const jsNever = !js.steps.some((s) => s.voices.some((v) => v.n === c.expectNeverFinishes && v.line === -2));
            assert(phpNever, `${c.name} — PHP: voice ${c.expectNeverFinishes} is cut mid-phrase, never shown "finished"`);
            assert(jsNever, `${c.name} — JS: voice ${c.expectNeverFinishes} is cut mid-phrase, never shown "finished"`);
        }
    });

    console.log('\n--- MUTATION PROOF: the lockstep comparison itself can fail ---');
    {
        const idx = 0;
        const real = canon(stepLinesOf(phpResults[idx], 0));
        const corrupted = canon(stepLinesOf({ steps: [{ voices: [{ n: 1, line: 99 }] }] }, 0));
        assert(real !== corrupted, 'MUTATION PROOF: a corrupted step no longer equals the real PHP step (the comparison can fail)');
    }
    {
        assert(CASES.length >= 2, 'MUTATION PROOF setup: at least two cases exist to compare');
        const a = JSON.stringify(phpResults[0]);
        const b = JSON.stringify(phpResults[1]);
        assert(a !== b, 'MUTATION PROOF: two genuinely different cases do not compare equal');
    }

    /* ====================================================================
     * 2 — TIMER DISCIPLINE: createRoundAutoAdvance() always balances every
     *     scheduled timer with a cancel, with NO real browser/jsdom involved
     *     — a fake clock (stub scheduleFn/cancelFn) stands in for setTimeout/
     *     clearTimeout, matching this repo's established no-jsdom pattern
     *     (see tests/test-voice-render-lockstep.js's own header note).
     * ================================================================== */
    console.log('\n2 — createRoundAutoAdvance(): every started timer is cleared\n');

    function fakeClock() {
        let nextHandle = 1;
        const pending = new Set();
        const scheduled = [];
        const cancelled = [];
        return {
            scheduleFn(fn, delay) {
                const handle = nextHandle++;
                pending.add(handle);
                scheduled.push({ handle, delay, fn });
                return handle;
            },
            cancelFn(handle) {
                cancelled.push(handle);
                pending.delete(handle);
            },
            fireNext() {
                const next = scheduled.find((s) => pending.has(s.handle));
                if (!next) { return false; }
                pending.delete(next.handle);
                next.fn();
                return true;
            },
            scheduled, cancelled, pending,
        };
    }

    {
        const steps = [{ i: 0, atMs: 0 }, { i: 1, atMs: 100 }, { i: 2, atMs: 250 }];
        const clock = fakeClock();
        const seenSteps = [];
        const player = createRoundAutoAdvance(steps, {
            onStep: (i) => seenSteps.push(i),
            scheduleFn: clock.scheduleFn,
            cancelFn: clock.cancelFn,
        });
        player.start(0);
        assert(clock.scheduled.length === 1, 'start() schedules exactly one timer for the first hop');
        player.stop();
        assert(clock.cancelled.length === 1, 'stop() cancels the pending timer');
        assert(clock.cancelled[0] === clock.scheduled[0].handle, 'stop() cancels the SAME handle scheduleFn returned');
        assert(clock.pending.size === 0, 'no timer is left pending after stop()');

        /* Idempotent: stopping an already-stopped player must not touch the
           clock again (a second, spurious cancelFn(null)-style call would be
           its own bug class). */
        const cancelledBefore = clock.cancelled.length;
        player.stop();
        assert(clock.cancelled.length === cancelledBefore, 'a second stop() is a no-op — does not call cancelFn again');
    }

    {
        /* Firing every hop in turn (a real "play through the whole round")
           must end with NOTHING pending — the controller stops arming once
           it reaches the last step, with no dangling final timer. */
        const steps = [{ i: 0, atMs: 0 }, { i: 1, atMs: 50 }, { i: 2, atMs: 120 }];
        const clock = fakeClock();
        const seenSteps = [];
        const player = createRoundAutoAdvance(steps, {
            onStep: (i) => seenSteps.push(i),
            scheduleFn: clock.scheduleFn,
            cancelFn: clock.cancelFn,
        });
        player.start(0);
        while (clock.fireNext()) { /* drive the fake clock to completion */ }
        assert(seenSteps.join(',') === '1,2', 'onStep fired for every hop after step 0, in order', 'got: ' + seenSteps.join(','));
        assert(clock.pending.size === 0, 'no timer is left pending once the round has played through to its last step');
    }

    {
        /* start() called a second time (e.g. Play pressed again after a
           manual step) must not leave the FIRST timer running alongside a
           second one — start() begins with an implicit stop(). */
        const steps = [{ i: 0, atMs: 0 }, { i: 1, atMs: 100 }, { i: 2, atMs: 200 }];
        const clock = fakeClock();
        const player = createRoundAutoAdvance(steps, { onStep: () => {}, scheduleFn: clock.scheduleFn, cancelFn: clock.cancelFn });
        player.start(0);
        player.start(0);
        assert(clock.scheduled.length === 2, 'a second start() schedules a fresh timer', 'scheduled: ' + clock.scheduled.length);
        assert(clock.cancelled.length === 1, 'a second start() cancels the FIRST timer before scheduling its own', 'cancelled: ' + clock.cancelled.length);
        player.stop();
        assert(clock.pending.size === 0, 'stop() after a re-start still leaves nothing pending');
    }

    console.log('\n--- MUTATION PROOF: a controller that forgets to cancel on stop() IS caught by this technique ---');
    {
        /* A deliberately BROKEN sibling shape — same start()/stop() surface,
           but stop() never calls cancelFn. If the counting technique above
           could not tell this apart from the real (fixed) implementation,
           it would be worthless — rule #34's "prove the guard can fail". */
        function brokenAutoAdvance(steps, opts) {
            let index = 0;
            return {
                start(from) {
                    index = from || 0;
                    opts.scheduleFn(() => { index++; opts.onStep(index); }, 100);
                },
                stop() { /* BUG: forgot to call opts.cancelFn — the #1568/rule-#32 class of leak */ },
            };
        }
        const clock = fakeClock();
        const broken = brokenAutoAdvance([{ i: 0 }, { i: 1 }], { onStep: () => {}, scheduleFn: clock.scheduleFn, cancelFn: clock.cancelFn });
        broken.start(0);
        broken.stop();
        assert(clock.pending.size !== 0, 'MUTATION PROOF: the broken sibling leaves a timer pending after stop() — the counting check correctly tells them apart');
    }

    /* ====================================================================
     * 3 — THE OVERLAY'S OWN TEARDOWN: close() in present-mode.js must call
     *     the round-stopping function as its FIRST statement, before any
     *     early return (rule #32's sibling). Structural, comment-stripped
     *     (a doc-comment MENTIONING the call must not satisfy this), and
     *     proven able to fail by re-checking against a mutated in-memory
     *     copy of the same source text — no file on disk is touched.
     * ================================================================== */
    console.log('\n3 — close() clears the round timer as its first statement\n');

    /* String/template-literal/regex-AWARE JS comment stripper — identical
       to the one in tests/test-event-names.js (see that file's header for
       the full rationale). Copied rather than imported — each
       tests/test-*.js file is self-contained per house style. */
    function stripJsComments(src) {
        let out = '';
        let i = 0;
        const n = src.length;
        let mode = null; // null | 'sq' | 'dq' | 'tpl' | 'line' | 'block' | 'regex'
        let inCharClass = false;
        let lastSig = '';
        const REGEX_PRECEDERS = new Set(['(', ',', '=', ':', ';', '!', '&', '|', '?', '{', '[', '+', '-', '*', '%', '<', '>', '\n', '']);

        while (i < n) {
            const c = src[i];
            const c2 = i + 1 < n ? src[i + 1] : '';

            if (mode === 'line') {
                if (c === '\n') { out += '\n'; mode = null; } else { out += ' '; }
                i++; continue;
            }
            if (mode === 'block') {
                if (c === '*' && c2 === '/') { out += '  '; i += 2; mode = null; continue; }
                out += (c === '\n' ? '\n' : ' ');
                i++; continue;
            }
            if (mode === 'sq' || mode === 'dq' || mode === 'tpl') {
                if (c === '\\') { out += c + c2; i += 2; continue; }
                const closer = mode === 'sq' ? '\'' : mode === 'dq' ? '"' : '`';
                out += c;
                if (c === closer) { mode = null; lastSig = closer; }
                i++; continue;
            }
            if (mode === 'regex') {
                if (c === '\\') { out += c + c2; i += 2; continue; }
                if (c === '[') { inCharClass = true; out += c; i++; continue; }
                if (c === ']') { inCharClass = false; out += c; i++; continue; }
                if (c === '/' && !inCharClass) {
                    out += c; i++;
                    while (i < n && /[a-z]/i.test(src[i])) { out += src[i]; i++; }
                    mode = null; lastSig = '/';
                    continue;
                }
                if (c === '\n') { mode = null; out += c; i++; continue; }
                out += c; i++; continue;
            }

            if (c === '/' && c2 === '/') { mode = 'line'; out += '  '; i += 2; continue; }
            if (c === '/' && c2 === '*') { mode = 'block'; out += '  '; i += 2; continue; }
            if (c === '/' && REGEX_PRECEDERS.has(lastSig)) { mode = 'regex'; inCharClass = false; out += c; i++; continue; }
            if (c === '\'') { mode = 'sq'; out += c; i++; continue; }
            if (c === '"') { mode = 'dq'; out += c; i++; continue; }
            if (c === '`') { mode = 'tpl'; out += c; i++; continue; }
            out += c;
            if (!/\s/.test(c)) { lastSig = c; }
            i++;
        }
        return out;
    }

    /** Extract a top-level `function <name>(...) { ... }` BODY (the text
     *  between its outermost braces) by brace-counting from the first match
     *  — robust to nested braces inside the function, which a non-greedy
     *  regex alone cannot handle correctly. */
    function extractFunctionBody(strippedSrc, fnName) {
        const marker = 'function ' + fnName + '(';
        const at = strippedSrc.indexOf(marker);
        if (at === -1) { return null; }
        const openBrace = strippedSrc.indexOf('{', at);
        if (openBrace === -1) { return null; }
        let depth = 1;
        let i = openBrace + 1;
        while (i < strippedSrc.length && depth > 0) {
            if (strippedSrc[i] === '{') { depth++; }
            else if (strippedSrc[i] === '}') { depth--; }
            i++;
        }
        return strippedSrc.slice(openBrace + 1, i - 1);
    }

    const presentModeSrc = fs.readFileSync(JS_MODULE_PATH, 'utf8');
    const strippedSrc = stripJsComments(presentModeSrc);

    const closeBody = extractFunctionBody(strippedSrc, 'close');
    assert(closeBody !== null, 'close() is found in present-mode.js');
    if (closeBody !== null) {
        assert(
            closeBody.trim().startsWith('stopRoundPlayback();'),
            'close() calls stopRoundPlayback() as its very FIRST statement, before any early return',
            'close() body (comments blanked) starts with: ' + JSON.stringify(closeBody.trim().slice(0, 60))
        );
    }

    /* next()/prev() (ordinary slide navigation) also stop a playing round —
       a round's clock belongs to the slide it is on, so leaving a slide
       must not leave its clock running either. Less strict than close()
       (anywhere in the body is enough — these functions have a guard
       clause before their own state change, unlike close()). */
    ['next', 'prev'].forEach((fnName) => {
        const body = extractFunctionBody(strippedSrc, fnName);
        assert(body !== null, `${fnName}() is found in present-mode.js`);
        if (body !== null) {
            assert(body.includes('stopRoundPlayback()'), `${fnName}() stops a playing round before moving to another slide`);
        }
    });

    console.log('\n--- MUTATION PROOF: this structural check goes RED if the line is removed (in-memory only, no file touched) ---');
    {
        const mutatedBody = closeBody.replace('stopRoundPlayback();', '');
        const stillPasses = mutatedBody.trim().startsWith('stopRoundPlayback();');
        assert(stillPasses === false, 'MUTATION PROOF: removing the call from an in-memory copy makes the SAME assertion fail');
    }

    console.log(`\n=== ${checks} checks, ${failures} failed ===`);
    process.exit(failures === 0 ? 0 : 1);
}

main().catch((err) => {
    console.error('HARNESS CRASHED:', err);
    process.exit(1);
});
