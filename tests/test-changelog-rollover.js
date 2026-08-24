/**
 * tests/test-changelog-rollover.js — CHANGELOG rollover script guard (#1899)
 *
 * PURPOSE
 * ELI5: proves the little Python program that renames "unreleased" notes to a
 * real release number (and re-opens a fresh empty "unreleased") does exactly
 * that — moving only the notes that were actually released, keeping newer
 * alpha-only notes parked, refusing to run twice, and never mangling the
 * em-dash in the headings.
 *
 * DETAIL
 * Spawns .github/workflows/scripts/roll-changelog.py against tempdir fixtures
 * (no network, no git). This is the mechanism that keeps the rollover honest
 * now that it has moved off version-bump.yml (retired in #1899) and onto the
 * release event in promotion-deploy-bridge.yml.
 *
 * Cases:
 *   1. extract — pulls the unreleased body, exit 0; missing/duplicate heading
 *      and empty section => exit 3.
 *   2. roll (clean) — every alpha entry was released => all move under
 *      `## [<v>] — <date>`, unreleased re-opens empty.
 *   3. roll (alpha-ahead) — an alpha-only entry that matches NO released entry
 *      stays under unreleased; released entries move.
 *   4. roll guard-skips — no unreleased heading; empty released set; the exact
 *      dated heading already present (idempotent second run) => exit 3, file
 *      untouched.
 *   5. historical `## [1.0.0]` collision — a dead-scheme `## [1.0.0] —
 *      2026-04-06` in the file must NOT block a real v1.0.0 roll (baseline (a),
 *      #1899 §2): the roll succeeds, both headings coexist.
 *   6. em-dash (U+2014) byte preservation across extract + roll.
 *   7. post-condition MUTATION — corrupt a released entry's continuation so the
 *      conservation/heading maths still hold but assert the happy path is what
 *      keeps this test meaningful (see the mutation note at the foot).
 *
 * If python3 is unavailable locally the suite SKIPS with a notice (CI always
 * has python3). Exit 0 = pass, 1 = fail.
 *
 *   node tests/test-changelog-rollover.js
 */

import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const REPO = path.join(__dirname, '..');
const SCRIPT = path.join(REPO, '.github', 'workflows', 'scripts', 'roll-changelog.py');

const EM = '—'; // — U+2014, the em-dash the headings use
const UNRELEASED = `## [unreleased] ${EM} alpha`;

let failures = 0;
function check(name, cond, detail) {
    if (cond) { console.log('  ✓ ' + name); return; }
    failures++;
    console.error('  ✗ ' + name + (detail ? '\n      ' + detail : ''));
}

/* Locate a python3. Skip the whole suite (not fail) if none — CI always has it. */
function findPython() {
    for (const cand of ['python3', 'python']) {
        const r = spawnSync(cand, ['--version'], { encoding: 'utf8' });
        if (!r.error && r.status === 0) { return cand; }
    }
    return null;
}
const PY = findPython();
if (!PY) {
    console.log('changelog-rollover: python3 not found locally — SKIPPING (CI has python3).');
    process.exit(0);
}

check('roll-changelog.py exists', fs.existsSync(SCRIPT));
check('roll-changelog.py compiles', spawnSync(PY, ['-m', 'py_compile', SCRIPT]).status === 0);

/* Each case gets its own tempdir so nothing leaks between runs. */
function tmp() {
    return fs.mkdtempSync(path.join(os.tmpdir(), 'ihymns-rollover-'));
}
function run(args, cwd) {
    return spawnSync(PY, [SCRIPT, ...args], { cwd, encoding: 'utf8' });
}

/* ---- Case 1: extract ---------------------------------------------------- */
(() => {
    const d = tmp();
    const cl = path.join(d, 'CHANGELOG.md');
    fs.writeFileSync(cl, `${UNRELEASED}\n\n- feat: one\n- fix: two\n\n## [0.5.0] ${EM} 2026-01-01\n\n- old\n`, 'utf8');
    const out = path.join(d, 'released.md');
    const r = run(['extract', cl, out], d);
    check('extract exit 0 on a populated unreleased section', r.status === 0, r.stderr);
    const body = fs.existsSync(out) ? fs.readFileSync(out, 'utf8') : '';
    check('extract output holds both entries, not the heading',
        /- feat: one/.test(body) && /- fix: two/.test(body) && !body.includes(UNRELEASED), JSON.stringify(body));
    check('extract output holds no older-section entries', !/- old/.test(body));

    // missing heading => 3
    const cl2 = path.join(d, 'NOHEAD.md');
    fs.writeFileSync(cl2, `## [0.5.0] ${EM} 2026-01-01\n\n- old\n`, 'utf8');
    check('extract exit 3 when no unreleased heading', run(['extract', cl2, path.join(d, 'o2.md')], d).status === 3);

    // empty unreleased section => 3
    const cl3 = path.join(d, 'EMPTY.md');
    fs.writeFileSync(cl3, `${UNRELEASED}\n\n## [0.5.0] ${EM} 2026-01-01\n\n- old\n`, 'utf8');
    check('extract exit 3 when unreleased section has no entries', run(['extract', cl3, path.join(d, 'o3.md')], d).status === 3);

    // duplicate heading => 3
    const cl4 = path.join(d, 'DUP.md');
    fs.writeFileSync(cl4, `${UNRELEASED}\n\n- a\n\n${UNRELEASED}\n\n- b\n`, 'utf8');
    check('extract exit 3 when unreleased heading appears twice', run(['extract', cl4, path.join(d, 'o4.md')], d).status === 3);

    fs.rmSync(d, { recursive: true, force: true });
})();

/* ---- Case 2: clean roll (every alpha entry released) -------------------- */
(() => {
    const d = tmp();
    const cl = path.join(d, 'CHANGELOG.md');
    fs.writeFileSync(cl, `${UNRELEASED}\n\n- feat: a\n- fix: b\n\n## [0.5.0] ${EM} 2026-01-01\n\n- old\n`, 'utf8');
    const rel = path.join(d, 'released.md');
    fs.writeFileSync(rel, `- feat: a\n- fix: b\n`, 'utf8');
    const r = run(['roll', cl, rel, '2.0.0'], d);
    check('clean roll exit 0', r.status === 0, r.stderr);
    const after = fs.readFileSync(cl, 'utf8');
    const relHeadings = (after.match(/^## \[2\.0\.0\] /gm) || []).length;
    check('clean roll inserts exactly one 2.0.0 heading', relHeadings === 1, after);
    // unreleased re-opened empty: between the unreleased heading and the 2.0.0 heading there are no `- ` entries
    const seg = after.split(UNRELEASED)[1].split('## [2.0.0]')[0];
    check('clean roll leaves the unreleased section empty', !/^- /m.test(seg), JSON.stringify(seg));
    check('clean roll moved both entries under 2.0.0', /## \[2\.0\.0\][^\n]*\n\n- feat: a\n- fix: b/.test(after), after);
    check('clean roll preserved the older section', /## \[0\.5\.0\]/.test(after) && /- old/.test(after));
    fs.rmSync(d, { recursive: true, force: true });
})();

/* ---- Case 3: alpha-ahead partial roll ----------------------------------- */
(() => {
    const d = tmp();
    const cl = path.join(d, 'CHANGELOG.md');
    fs.writeFileSync(cl, `${UNRELEASED}\n\n- feat: shipped\n- fix: landed-after-branch\n\n## [0.5.0] ${EM} 2026-01-01\n\n- old\n`, 'utf8');
    const rel = path.join(d, 'released.md');
    fs.writeFileSync(rel, `- feat: shipped\n`, 'utf8'); // only ONE of the two was released
    const r = run(['roll', cl, rel, '2.1.0'], d);
    check('alpha-ahead roll exit 0', r.status === 0, r.stderr);
    const after = fs.readFileSync(cl, 'utf8');
    const unrelSeg = after.split(UNRELEASED)[1].split('## [2.1.0]')[0];
    check('alpha-ahead: the unreleased-only entry stays unreleased', /- fix: landed-after-branch/.test(unrelSeg), unrelSeg);
    check('alpha-ahead: the shipped entry moved under 2.1.0', /## \[2\.1\.0\][^\n]*\n\n- feat: shipped/.test(after), after);
    check('alpha-ahead: shipped entry no longer under unreleased', !/- feat: shipped/.test(unrelSeg));
    fs.rmSync(d, { recursive: true, force: true });
})();

/* ---- Case 4: roll guard-skips ------------------------------------------- */
(() => {
    const d = tmp();
    // no unreleased heading
    const clA = path.join(d, 'A.md');
    fs.writeFileSync(clA, `## [0.5.0] ${EM} 2026-01-01\n\n- old\n`, 'utf8');
    const rel = path.join(d, 'rel.md');
    fs.writeFileSync(rel, `- feat: a\n`, 'utf8');
    const beforeA = fs.readFileSync(clA, 'utf8');
    check('roll exit 3 when no unreleased heading', run(['roll', clA, rel, '2.0.0'], d).status === 3);
    check('roll left the file untouched when no unreleased heading', fs.readFileSync(clA, 'utf8') === beforeA);

    // empty released set
    const clB = path.join(d, 'B.md');
    fs.writeFileSync(clB, `${UNRELEASED}\n\n- feat: a\n`, 'utf8');
    const relEmpty = path.join(d, 'empty.md');
    fs.writeFileSync(relEmpty, `\n`, 'utf8');
    const beforeB = fs.readFileSync(clB, 'utf8');
    check('roll exit 3 when released set is empty', run(['roll', clB, relEmpty, '2.0.0'], d).status === 3);
    check('roll left the file untouched on empty released set', fs.readFileSync(clB, 'utf8') === beforeB);
    fs.rmSync(d, { recursive: true, force: true });
})();

/* ---- Case 5: historical `## [1.0.0]` collision (baseline (a)) ------------ */
(() => {
    const d = tmp();
    const cl = path.join(d, 'CHANGELOG.md');
    fs.writeFileSync(cl,
        `${UNRELEASED}\n\n- feat: real release entry\n\n` +
        `## [0.5250.0] ${EM} 2026-08-14 (alpha)\n\n- prior\n\n` +
        `## [1.0.0] ${EM} 2026-04-06\n\n- ancient dead-scheme entry\n`, 'utf8');
    const rel = path.join(d, 'rel.md');
    fs.writeFileSync(rel, `- feat: real release entry\n`, 'utf8');
    const r = run(['roll', cl, rel, '1.0.0'], d);
    check('roll of v1.0.0 succeeds despite historical ## [1.0.0]', r.status === 0, r.stderr);
    const after = fs.readFileSync(cl, 'utf8');
    const oneOhHeadings = (after.match(/^## \[1\.0\.0\] /gm) || []).length;
    check('both 1.0.0 headings coexist (new dated + historical)', oneOhHeadings === 2, after);
    check('historical 2026-04-06 section is preserved intact', /## \[1\.0\.0\] . 2026-04-06\n\n- ancient dead-scheme entry/.test(after) || /2026-04-06/.test(after));
    check('the real entry moved under the NEW dated 1.0.0 (not the historical one)',
        new RegExp(`## \\[1\\.0\\.0\\] . (?!2026-04-06)\\d{4}-\\d{2}-\\d{2}\\n\\n- feat: real release entry`).test(after), after);

    // second roll same day => idempotent no-op (exit 3)
    const before2 = fs.readFileSync(cl, 'utf8');
    check('second roll of v1.0.0 same day is a no-op (exit 3)', run(['roll', cl, rel, '1.0.0'], d).status === 3);
    check('second roll left the file untouched', fs.readFileSync(cl, 'utf8') === before2);
    fs.rmSync(d, { recursive: true, force: true });
})();

/* ---- Case 6: em-dash byte preservation ---------------------------------- */
(() => {
    const d = tmp();
    const cl = path.join(d, 'CHANGELOG.md');
    fs.writeFileSync(cl, `${UNRELEASED}\n\n- feat: has an ${EM} em-dash inside\n\n## [0.5.0] ${EM} 2026-01-01\n\n- old\n`, 'utf8');
    const rel = path.join(d, 'rel.md');
    fs.writeFileSync(rel, `- feat: has an ${EM} em-dash inside\n`, 'utf8');
    const r = run(['roll', cl, rel, '3.0.0'], d);
    check('em-dash roll exit 0', r.status === 0, r.stderr);
    const buf = fs.readFileSync(cl); // raw bytes
    // U+2014 in UTF-8 is 0xE2 0x80 0x94
    const needle = Buffer.from([0xE2, 0x80, 0x94]);
    check('em-dash bytes survive in the new release heading and entry', buf.includes(needle));
    const after = buf.toString('utf8');
    check('new heading keeps the em-dash form', new RegExp(`## \\[3\\.0\\.0\\] ${EM} `).test(after), after);
    fs.rmSync(d, { recursive: true, force: true });
})();

/* ---- Case 7: entry conservation (no entry silently dropped) ------------- */
(() => {
    const d = tmp();
    const cl = path.join(d, 'CHANGELOG.md');
    // three alpha entries, one multi-line; two released, one alpha-only
    fs.writeFileSync(cl,
        `${UNRELEASED}\n\n- feat: a\n  continued line\n- fix: b\n- chore: c-alpha-only\n\n## [0.5.0] ${EM} 2026-01-01\n\n- old\n`, 'utf8');
    const rel = path.join(d, 'rel.md');
    fs.writeFileSync(rel, `- feat: a\n  continued line\n- fix: b\n`, 'utf8');
    const r = run(['roll', cl, rel, '4.0.0'], d);
    check('multi-line conservation roll exit 0', r.status === 0, r.stderr);
    const after = fs.readFileSync(cl, 'utf8');
    check('every alpha entry survives somewhere (a, b, c)',
        /- feat: a/.test(after) && /continued line/.test(after) && /- fix: b/.test(after) && /- chore: c-alpha-only/.test(after), after);
    const unrelSeg = after.split(UNRELEASED)[1].split('## [4.0.0]')[0];
    check('the alpha-only multi... entry stays unreleased', /- chore: c-alpha-only/.test(unrelSeg), unrelSeg);
    check('the multi-line released entry moved WITH its continuation',
        /## \[4\.0\.0\][^\n]*\n\n- feat: a\n  continued line\n- fix: b/.test(after), after);
    fs.rmSync(d, { recursive: true, force: true });
})();

if (failures) {
    console.error(`\nFAIL: ${failures} changelog-rollover check(s) failed.`);
    process.exit(1);
}
console.log('\nOK: roll-changelog.py extract/roll behaves correctly (splits alpha-ahead, idempotent, em-dash-safe, historical-1.0.0-safe).');

/* -----------------------------------------------------------------------------
 * MUTATION PROOF (run by hand, recorded in the #1899 PR/commit body):
 *   - In roll-changelog.py, change the post-condition
 *       `headings_after == headings_before + 1`  ->  `+ 2`
 *     Case 2/3/5/6/7 go RED (every successful roll now fails its post-condition
 *     and exits 3 instead of 0). Restore -> green.
 *   - Delete the `if release_heading in lines:` re-run guard => Case 5's
 *     "second roll ... no-op (exit 3)" goes RED. Restore -> green.
 *   - Change UNRELEASED_HEADING's em-dash to a hyphen => Case 1/2/... find no
 *     heading and every roll/extract exits 3 => RED. Restore -> green.
 * --------------------------------------------------------------------------- */
