/**
 * tests/test-midi-input.js — truth table + mutation proof for #1267's two
 * pure hands-free-navigation helpers.
 *
 * PURPOSE
 * -------
 * ELI5: #1267 lets a Bluetooth foot pedal or a MIDI controller move to the
 * next/previous lyric section without touching the mouse or keyboard. Two
 * small PURE functions do the actual decision-making — "what does this raw
 * MIDI message mean?" and "given where I've scrolled to, which section is
 * next?" — and this file feeds each one every case its own doc-comment
 * promises, straight from the real source files (no reimplementation of
 * either function's logic here — rule #35: the test IS the mechanism).
 *
 * WHAT IS COVERED
 * ----------------
 *   midiEventToAction(status, data1, data2)   — js/modules/midi-input.js
 *     NoteOn vel>0 -> 'next'; NoteOff / NoteOn vel=0 -> null;
 *     CC64 (sustain) >=64 -> 'next', <64 -> null; CC67 (soft) -> 'prev';
 *     any other CC/status -> null; channel nibble is ignored.
 *
 *   nextSectionIndex(tops, scrollY, dir)      — js/modules/display.js
 *     mid-section "down" -> the NEXT section's index;
 *     already at/after the last section, "down" -> clamps (stays last);
 *     already at the first section, "up" -> clamps (stays first);
 *     empty tops -> null (no-op).
 *
 * MUTATION-PROVING (rule #34 in .claude/CLAUDE.md)
 * -------------------------------------------------
 * A truth table that has never been shown able to go red is not trusted
 * here — every prior guard in this repo's history that skipped this step
 * was later found silently wrong. Section 3 below runs the SAME two
 * mutations the task brief names against the REAL truth-table cases
 * collected in sections 1 and 2, and asserts each mutation produces at
 * least one row that no longer matches its expected value — i.e. that
 * these exact assertions WOULD have failed had the real source shipped
 * with that bug. This is not a byte-patch of the source file; it is a
 * small local wrapper that perturbs the REAL function's output, which is
 * enough to prove the comparison in sections 1/2 is discriminating rather
 * than a tautology.
 *
 *   Mutation A — "swap next/prev in the mapper": every 'next'
 *     midiEventToAction() would have returned becomes 'prev' and vice
 *     versa (null is left alone — swapping null with anything isn't the
 *     bug this guards against).
 *   Mutation B — "off-by-one the section picker": nextSectionIndex()'s
 *     result is shifted by +1 before clamping is (deliberately NOT)
 *     re-applied, so every case is wrong by exactly one section.
 *
 * Auto-discovered by tools/run-node-tests.js's `tests/*.js` glob — no
 * registration needed.
 *
 *   node tests/test-midi-input.js
 *
 * Exit 0 = every case (and every mutation-proof) passes, 1 = drift.
 */

import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const DISPLAY_MODULE = pathToFileURL(
    path.join(__dirname, '..', 'appWeb', 'public_html', 'js', 'modules', 'display.js'),
).href;
const MIDI_MODULE = pathToFileURL(
    path.join(__dirname, '..', 'appWeb', 'public_html', 'js', 'modules', 'midi-input.js'),
).href;

let passed = 0;
let failed = 0;
function check(name, cond, detail) {
    if (cond) {
        passed++;
        console.log('  ✓ ' + name);
    } else {
        failed++;
        console.error('  ✗ ' + name + (detail ? '\n      ' + detail : ''));
    }
}

async function main() {
    const { midiEventToAction } = await import(MIDI_MODULE);
    const { nextSectionIndex } = await import(DISPLAY_MODULE);

    check('midi-input.js exports midiEventToAction', typeof midiEventToAction === 'function');
    check('display.js exports nextSectionIndex', typeof nextSectionIndex === 'function');

    /* ======================================================================
     * 1 — midiEventToAction() truth table
     * ==================================================================== */
    console.log('\nmidiEventToAction() truth table:');

    /* Each row: [label, status, data1, data2, expected] */
    const midiCases = [
        /* --- Note On, velocity > 0 -> next (channels 1 and 2) --------- */
        ['NoteOn ch1 vel=127 -> next', 0x90, 60, 127, 'next'],
        ['NoteOn ch1 vel=1 -> next (any nonzero velocity counts)', 0x90, 60, 1, 'next'],
        ['NoteOn ch2 vel=100 -> next (channel nibble ignored)', 0x91, 60, 100, 'next'],

        /* --- Note On vel=0 (running-status Note Off) -> null ----------- */
        ['NoteOn vel=0 -> null (running-status Note Off)', 0x90, 60, 0, null],

        /* --- Note Off -> null, regardless of the "velocity" byte ------- */
        ['NoteOff vel=0 -> null', 0x80, 60, 0, null],
        ['NoteOff vel=64 -> null (release velocity is not a trigger)', 0x80, 60, 64, null],

        /* --- CC64 (sustain): threshold at value 64 --------------------- */
        ['CC64 value=127 -> next', 0xb0, 64, 127, 'next'],
        ['CC64 value=64 -> next (threshold is inclusive)', 0xb0, 64, 64, 'next'],
        ['CC64 value=63 -> null (just below threshold)', 0xb0, 64, 63, null],
        ['CC64 value=0 -> null (pedal released)', 0xb0, 64, 0, null],
        ['CC64 ch5 value=100 -> next (channel nibble ignored)', 0xb4, 64, 100, 'next'],

        /* --- CC67 (soft): unconditional -> prev ------------------------- */
        ['CC67 value=127 -> prev', 0xb0, 67, 127, 'prev'],
        ['CC67 value=0 -> prev (spec gives CC67 no value threshold)', 0xb0, 67, 0, 'prev'],

        /* --- Unrelated controller numbers / status bytes -> null -------- */
        ['CC1 (mod wheel) -> null (unrelated controller)', 0xb0, 1, 127, null],
        ['CC7 (channel volume) -> null (unrelated controller)', 0xb0, 7, 100, null],
        ['Program Change (0xC0) -> null (unrelated status)', 0xc0, 5, 0, null],
        ['Pitch Bend (0xE0) -> null (unrelated status)', 0xe0, 0, 64, null],
        ['Polyphonic Aftertouch (0xA0) -> null (unrelated status)', 0xa0, 60, 100, null],
    ];

    for (const [label, status, data1, data2, expected] of midiCases) {
        check(label, midiEventToAction(status, data1, data2) === expected);
    }

    /* ======================================================================
     * 2 — nextSectionIndex() truth table
     * ==================================================================== */
    console.log('\nnextSectionIndex() truth table:');

    /* Four sections whose lyric-component tops sit at 0 / 100 / 250 / 400
       (arbitrary but ascending, mirroring real getBoundingClientRect() +
       scrollY output). */
    const tops = [0, 100, 250, 400];

    check(
        'mid-section, dir=+1 (down) -> the NEXT section (currently in #1 [100,250), lands on #2)',
        nextSectionIndex(tops, 150, 1) === 2,
    );
    check(
        'mid-section, dir=+1 (down) is independent of HOW FAR into the section scroll is',
        nextSectionIndex(tops, 240, 1) === nextSectionIndex(tops, 110, 1),
    );
    check(
        'already at/after the last section, dir=+1 (down) -> clamps (stays last)',
        nextSectionIndex(tops, 400, 1) === 3 && nextSectionIndex(tops, 9999, 1) === 3,
    );
    check(
        'already at the first section, dir=-1 (up) -> clamps (stays first)',
        nextSectionIndex(tops, 0, -1) === 0 && nextSectionIndex(tops, -50, -1) === 0,
    );
    check(
        'mid-section, dir=-1 (up) -> the section BEFORE the current one',
        nextSectionIndex(tops, 150, -1) === 0,
    );
    check('empty tops -> null (no-op)', nextSectionIndex([], 100, 1) === null);
    check('single-section tops, either direction -> clamps to the only index (0)',
        nextSectionIndex([0], 0, 1) === 0 && nextSectionIndex([0], 0, -1) === 0);
    check('dir must be exactly +1/-1 -> null for anything else',
        nextSectionIndex(tops, 150, 0) === null && nextSectionIndex(tops, 150, 2) === null);
    check('non-array tops -> null (no-op, never throws)',
        nextSectionIndex(null, 100, 1) === null && nextSectionIndex(undefined, 100, 1) === null);

    /* ======================================================================
     * 3 — mutation proofs (rule #34): show these EXACT assertions would go
     *     red against the two bug shapes the task brief names.
     * ==================================================================== */
    console.log('\nMutation proofs:');

    /* Mutation A — "swap next/prev in the mapper". Wraps the REAL function
       (never a reimplementation) and flips its non-null answers. */
    function swappedMidiEventToAction(status, data1, data2) {
        const real = midiEventToAction(status, data1, data2);
        if (real === 'next') return 'prev';
        if (real === 'prev') return 'next';
        return real;
    }
    {
        let mutationCaughtByTestA = false;
        for (const [, status, data1, data2, expected] of midiCases) {
            if (swappedMidiEventToAction(status, data1, data2) !== expected) {
                mutationCaughtByTestA = true;
                break;
            }
        }
        check(
            'mutation A self-test: swapping next/prev in the mapper makes the truth table go red',
            mutationCaughtByTestA,
            'if this fails, the truth table above could not tell a swapped mapper from a correct one',
        );
    }

    /* Mutation B — "off-by-one the section picker". Wraps the REAL
       function and shifts its answer by one WITHOUT re-clamping — exactly
       the shape of bug an off-by-one in the internal loop/clamp would
       produce (a picker that points one section too far). */
    function offByOneNextSectionIndex(t, scrollY, dir) {
        const real = nextSectionIndex(t, scrollY, dir);
        return real === null ? null : real + 1;
    }
    {
        const sectionCases = [
            [tops, 150, 1, 2],
            [tops, 400, 1, 3],
            [tops, 9999, 1, 3],
            [tops, 0, -1, 0],
            [tops, -50, -1, 0],
            [tops, 150, -1, 0],
            [[], 100, 1, null],
            [[0], 0, 1, 0],
        ];
        let mutationCaughtByTestB = false;
        for (const [t, scrollY, dir, expected] of sectionCases) {
            if (offByOneNextSectionIndex(t, scrollY, dir) !== expected) {
                mutationCaughtByTestB = true;
                break;
            }
        }
        check(
            'mutation B self-test: off-by-one-ing the section picker makes the truth table go red',
            mutationCaughtByTestB,
            'if this fails, the truth table above could not tell an off-by-one picker from a correct one',
        );
    }

    console.log(`\n${passed} passed, ${failed} failed`);
    process.exit(failed > 0 ? 1 : 0);
}

main().catch((err) => {
    console.error('test-midi-input.js crashed:', err);
    process.exit(1);
});
