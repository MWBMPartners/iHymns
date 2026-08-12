/**
 * tests/test-whats-new-extraction.js — regression guard for #1589
 *
 * ELI5: checks that the "What's New" page gets a short, tidy list of recent
 * changes covering several releases — not one enormous blob that stops in the
 * middle of a word.
 *
 * WHY THIS EXISTS
 * ---------------
 * `.github/workflows/deploy.yml` builds `public_html/data/whats-new.md` from
 * `CHANGELOG.md` on every deploy, and `includes/pages/whats-new.php` renders
 * it. The original extractor took the first THREE top-level `## ` sections and
 * hard-capped the result at 49152 bytes:
 *
 *     awk '/^## / { section++; if (section > 3) exit }
 *          section >= 1 { print }' CHANGELOG.md | head -c 49152
 *
 * That is a section-count budget guarding a byte-count problem, and a section
 * has no bounded size. `## [unreleased] — alpha` alone reached 70,627 bytes,
 * so `head -c` cut *inside section one*: the deployed page was one giant blob,
 * truncated mid-sentence, that never reached a second release at all. Nothing
 * failed — the workflow was green, the file was written, the page rendered.
 * The only symptom was a tester saying it looked wrong. Same silent-no-op
 * class as CLAUDE.md rules #30 and #1581.
 *
 * WHAT IS ACTUALLY UNDER TEST
 * ---------------------------
 * The awk program is LIFTED OUT OF deploy.yml AND EXECUTED — this suite does
 * not keep a copy of the logic. A copy would drift, and a test that exercises
 * its own private reimplementation is the "54/54 passing, zero real coverage"
 * failure this project has already shipped once. The workflow step is located
 * by name, its `run:` body is dedented, the one `${{ }}` expression is
 * substituted with a scratch directory, and the result is run under `bash -e`
 * (matching the Actions default shell) from the repo root, against the REAL
 * `CHANGELOG.md` in the working tree.
 *
 * PROVEN ABLE TO FAIL: the historical three-section program is run through the
 * exact same assertions as a control and MUST violate them — see section 5,
 * "the guard's own guard". Observed failing output for the pre-fix workflow is
 * recorded in the #1589 PR description. To re-run it against a real pre-fix
 * file rather than the embedded control:
 *
 *     git show <sha>:.github/workflows/deploy.yml > /tmp/old-deploy.yml
 *     WHATS_NEW_WORKFLOW=/tmp/old-deploy.yml node tests/test-whats-new-extraction.js
 *
 * @see .github/workflows/deploy.yml            step "Extract What's New from CHANGELOG.md"
 * @see appWeb/public_html/includes/pages/whats-new.php
 * @see https://docs.github.com/en/actions/using-workflows/workflow-syntax-for-github-actions#jobsjob_idstepsrun
 */

import { execFileSync } from 'node:child_process';
import { mkdtempSync, readFileSync, writeFileSync, rmSync, existsSync, copyFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const REPO_ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const WORKFLOW = process.env.WHATS_NEW_WORKFLOW
    || join(REPO_ROOT, '.github', 'workflows', 'deploy.yml');
const CHANGELOG = join(REPO_ROOT, 'CHANGELOG.md');
const WHATS_NEW = join(REPO_ROOT, 'WHATS-NEW.md');
const STEP_NAME = "Extract What's New for the app";

/* The cap deploy.yml enforces. Asserted against the workflow text below so a
   future edit to one number cannot silently desync this suite from reality. */
const MAX_BYTES = 49152;

let passed = 0;
let failed = 0;

function check(label, actual, expected) {
    if (actual === expected) {
        passed++;
    } else {
        failed++;
        console.error(`  FAIL ${label}\n       expected: ${JSON.stringify(expected)}\n       actual:   ${JSON.stringify(actual)}`);
    }
}

function ok(label, condition, detail) {
    check(label, condition === true, true);
    if (condition !== true && detail) console.error(`       ${detail}`);
}

/* ------------------------------------------------------------------------
   1. Lift the step's shell body out of the workflow.

   Hand-rolled rather than YAML-parsed on purpose: this repo's test runner
   (`node tests/test-*.js`, see .github/workflows/test.yml) has no dependency
   install step, so the suite must run on a bare Node with zero packages.
   The scan is deliberately strict — it throws rather than guessing — because
   a silently-empty script would make every assertion below vacuously pass,
   which is precisely the failure mode this file exists to prevent.
   ------------------------------------------------------------------------ */
function extractRunBlock(workflowPath, stepName) {
    const lines = readFileSync(workflowPath, 'utf8').split('\n');

    const nameIdx = lines.findIndex(l => l.trim() === `- name: ${stepName}`);
    if (nameIdx === -1) throw new Error(`step "${stepName}" not found in ${workflowPath}`);

    // `run: |` (or `run: |-`) somewhere in this step's key block.
    let runIdx = -1;
    for (let i = nameIdx + 1; i < lines.length; i++) {
        if (/^\s*- name:/.test(lines[i])) break;          // next step — gone too far
        if (/^\s*run:\s*\|-?\s*$/.test(lines[i])) { runIdx = i; break; }
    }
    if (runIdx === -1) throw new Error(`no block-scalar "run: |" under step "${stepName}"`);

    // A YAML block scalar ends at the first non-blank line indented no more
    // than its key. https://yaml.org/spec/1.2.2/#8111-block-indentation-indicator
    const keyIndent = lines[runIdx].match(/^\s*/)[0].length;
    const body = [];
    for (let i = runIdx + 1; i < lines.length; i++) {
        const line = lines[i];
        if (line.trim() === '') { body.push(''); continue; }
        if (line.match(/^\s*/)[0].length <= keyIndent) break;
        body.push(line);
    }
    if (body.length === 0) throw new Error(`empty run body for step "${stepName}"`);

    // Dedent by the block's own indentation.
    const indent = Math.min(...body.filter(l => l !== '').map(l => l.match(/^\s*/)[0].length));
    return body.map(l => l.slice(indent)).join('\n');
}

/* ------------------------------------------------------------------------
   2. Run it exactly as Actions would.

   `${{ steps.target.outputs.source_dir }}` is the only expression in this
   step; deploy.yml appends "data" to it directly (`"${SOURCE_DIR}data"`), so
   the substitute MUST carry its trailing slash. The default Actions shell is
   `bash -e {0}` with no `-o pipefail`, and this step's pipeline relies on
   that (`... | iconv ... || true`), so the harness must not add pipefail —
   doing so would test a stricter shell than the one that actually deploys.
   https://docs.github.com/en/actions/using-workflows/workflow-syntax-for-github-actions#defaultsrunshell
   ------------------------------------------------------------------------ */
function runExtractor(script, { srcName, changelogText } = {}) {
    const scratch = mkdtempSync(join(tmpdir(), 'whats-new-'));
    try {
        /* When a specific source is requested, place ONLY that file in the
           scratch cwd and run there — so the step's "prefer WHATS-NEW.md, else
           CHANGELOG.md" selection resolves deterministically to it. Without a
           srcName the block runs at REPO_ROOT (both files present → it picks
           WHATS-NEW.md, same as a real deploy); the legacy control below relies
           on that REPO_ROOT behaviour. */
        if (srcName) {
            copyFileSync(join(REPO_ROOT, srcName), join(scratch, srcName));
        }
        /* An inline CHANGELOG.md — used by the legacy control (section 5) to feed
           the historical extractor a deterministic #1589 shape (a first section
           that alone overflows the cap) regardless of where the live CHANGELOG's
           bulk currently sits, mirroring 4b's largest-section robustness. */
        if (changelogText !== undefined) {
            writeFileSync(join(scratch, 'CHANGELOG.md'), changelogText);
        }
        const runInScratch = srcName !== undefined || changelogText !== undefined;
        const shell = script.replace(
            /\$\{\{\s*steps\.target\.outputs\.source_dir\s*\}\}/g,
            `${scratch}/`,
        );
        if (shell.includes('${{')) {
            throw new Error(`unsubstituted workflow expression remains: ${shell.match(/\$\{\{[^}]*\}\}/)[0]}`);
        }
        const stdout = execFileSync('bash', ['-e', '-c', shell], {
            cwd: runInScratch ? scratch : REPO_ROOT,
            encoding: 'utf8',
            env: { ...process.env, GITHUB_OUTPUT: join(scratch, 'gh-output') },
        });
        const out = join(scratch, 'data', 'whats-new.md');
        return {
            log: stdout,
            exists: existsSync(out),
            // Read as BYTES. Every budget in the extractor is a byte budget
            // (the changelog is dense with em-dashes and emoji), and a
            // JS string length would silently measure UTF-16 code units.
            bytes: existsSync(out) ? readFileSync(out) : Buffer.alloc(0),
        };
    } finally {
        rmSync(scratch, { recursive: true, force: true });
    }
}

/* ------------------------------------------------------------------------
   3. The assertions, applied to whatever extractor produced `result`.
      Returns the number of failures so the control in section 5 can demand
      that the OLD extractor produces some.
   ------------------------------------------------------------------------ */
const changelogLines = new Set(
    readFileSync(CHANGELOG, 'utf8').split('\n'),
);

function assertExcerpt(result, label, record) {
    const before = failed;
    const text = result.bytes.toString('utf8');
    const lines = text.split('\n');
    const nonEmpty = lines.filter(l => l.trim() !== '');
    const headings = nonEmpty.filter(l => l.startsWith('## '));
    const entries = nonEmpty.filter(l => l.startsWith('- '));

    record(`${label}: ${result.bytes.length} bytes, ${headings.length} releases, ${entries.length} entries`);

    ok(`${label}: writes a non-empty file`, result.bytes.length > 0);

    /* (a) Under the cap. This one already held before #1589 — it is the
       *only* one that did, which is exactly why the bug shipped. */
    ok(`${label}: excerpt is within the ${MAX_BYTES}-byte cap`,
        result.bytes.length <= MAX_BYTES,
        `got ${result.bytes.length} bytes`);

    /* (b) More than one release. THE #1589 REGRESSION: a byte cap reached
       inside section one means the reader never sees anything older. */
    ok(`${label}: covers more than one release heading`,
        headings.length > 1,
        `got ${headings.length} heading(s): ${headings.join(' | ') || '(none)'}`);

    /* (c) Ends at an entry boundary, not mid-word. Checked structurally
       rather than by sniffing for sentence punctuation: EVERY line in the
       output must exist verbatim as a whole line in CHANGELOG.md. A byte
       cut mid-line produces a prefix that matches nothing. */
    const orphans = nonEmpty.filter(l => !changelogLines.has(l));
    ok(`${label}: every emitted line is a complete line from CHANGELOG.md`,
        orphans.length === 0,
        orphans.length ? `first truncated/foreign line: ${JSON.stringify(orphans[0].slice(-90))}` : '');

    /* (d) The last thing on the page is a finished entry — not a heading
       introducing a release whose entries were all budgeted away, and not
       a dangling continuation. */
    const lastLine = nonEmpty[nonEmpty.length - 1] || '';
    ok(`${label}: last line is not an orphan heading`,
        !lastLine.startsWith('## '),
        `last line: ${JSON.stringify(lastLine.slice(0, 90))}`);
    ok(`${label}: output ends with a newline`, text.endsWith('\n'));

    /* (e) Valid UTF-8 — markdown_lite.php's ENT_SUBSTITUTE depends on never
       being handed an incomplete sequence, which is what the `iconv -f UTF-8
       -t UTF-8 -c` stage in the pipeline is there to guarantee.
       https://www.php.net/manual/en/function.htmlspecialchars.php */
    let utf8Clean = true;
    try {
        new TextDecoder('utf-8', { fatal: true }).decode(result.bytes);
    } catch (_e) {
        utf8Clean = false;
    }
    ok(`${label}: output is well-formed UTF-8`, utf8Clean);

    /* (f) No heading without at least one entry under it. */
    let orphanHeading = '';
    for (let i = 0; i < nonEmpty.length; i++) {
        if (!nonEmpty[i].startsWith('## ')) continue;
        const next = nonEmpty[i + 1];
        if (!next || next.startsWith('## ')) { orphanHeading = nonEmpty[i]; break; }
    }
    ok(`${label}: no release heading is emitted without entries`,
        orphanHeading === '',
        orphanHeading && `orphan: ${orphanHeading}`);

    return failed - before;
}

/* ======================================================================== */

console.log('#1589 — What\'s New extraction from CHANGELOG.md\n');

const notes = [];
const record = m => notes.push(m);

/* 4. The real, current deploy.yml extractor. */
const script = extractRunBlock(WORKFLOW, STEP_NAME);

ok('the lifted step still enforces the byte cap this suite asserts',
    script.includes(String(MAX_BYTES)),
    `MAX_BYTES=${MAX_BYTES} not found in the step body — update the constant or the workflow`);
ok('the lifted step still normalises UTF-8 with iconv (rule: ENT_SUBSTITUTE needs valid input)',
    script.includes('iconv -f UTF-8 -t UTF-8 -c'));
ok('the lifted step still keeps head -c as a byte backstop',
    script.includes('head -c'));

/* Exercise the TRUNCATION LOGIC (the #1589 guard) against CHANGELOG.md — the
   large fallback source. WHATS-NEW.md is small by design and can't reproduce the
   byte-cap-mid-section-one scenario, so it can't stand in for this. */
const current = runExtractor(script, { srcName: 'CHANGELOG.md' });
assertExcerpt(current, 'current extractor', record);

/* 4b. Sanity on the *source*: this test is only meaningful while the real
   CHANGELOG.md contains a single section bigger than the cap — that is the
   exact #1589 shape (one `## ` section whose bytes alone overflow `head -c`,
   cutting mid-section). If no section is that big, the truncation assertions
   above become trivially satisfiable and this suite would protect nothing —
   fail loudly instead of quietly passing.

   Measured over the LARGEST section, NOT section 1: the top of CHANGELOG.md is
   whatever release was cut most recently, which may be a small follow-up entry
   (e.g. a version-bump-only release), while the reproducing bulk sits lower in
   the file. Keying this on position once made the guard fail the moment a small
   release landed on top (rule #34 — derive the check from the tree, not from an
   assumed ordering). */
const { largestSectionBytes, largestSectionHeading, largestSectionStart } = (() => {
    const text = readFileSync(CHANGELOG).toString('utf8');
    const starts = [];
    const re = /^## /gm;
    let m;
    while ((m = re.exec(text)) !== null) starts.push(m.index);
    let maxBytes = 0;
    let maxHeading = '(none)';
    let maxStart = 0;
    for (let i = 0; i < starts.length; i++) {
        const end = i + 1 < starts.length ? starts[i + 1] : text.length;
        const bytes = Buffer.byteLength(text.slice(starts[i], end), 'utf8');
        if (bytes > maxBytes) {
            maxBytes = bytes;
            maxHeading = text.slice(starts[i], text.indexOf('\n', starts[i]));
            maxStart = starts[i];
        }
    }
    return { largestSectionBytes: maxBytes, largestSectionHeading: maxHeading, largestSectionStart: maxStart };
})();
record(`CHANGELOG.md largest section is ${largestSectionBytes} bytes (${largestSectionHeading})`);
ok('CHANGELOG.md still has a section that exceeds the cap (so this test is not vacuous)',
    largestSectionBytes > MAX_BYTES,
    `largest section is only ${largestSectionBytes} bytes — the #1589 scenario can no longer be reproduced from this fixture`);

/* ------------------------------------------------------------------------
   4c. The REAL user-facing source — WHATS-NEW.md, through the SAME lifted step
   (which prefers it). It is small and curated, so the truncation asserts above
   don't apply; instead it MUST (i) produce a valid non-empty excerpt and (ii)
   leak NO technical internals into what users see. (ii) is the whole reason the
   dedicated file exists (owner directive 2026-08-11; house style
   .claude/whats-new-style.md) — mechanise it so a future edit can't quietly
   reintroduce file names, table names, issue numbers or code paths.
   ------------------------------------------------------------------------ */
ok('the lifted step prefers WHATS-NEW.md as its source',
    /WHATS_NEW_SRC=/.test(script) && script.includes('WHATS-NEW.md'),
    'the step no longer selects WHATS-NEW.md — user-facing notes would fall back to the technical CHANGELOG');

const wn = runExtractor(script, { srcName: 'WHATS-NEW.md' });
ok('WHATS-NEW.md: extractor writes a non-empty excerpt', wn.bytes.length > 0);
const wnText = wn.bytes.toString('utf8');
const wnHeadings = wnText.split('\n').filter(l => l.startsWith('## '));
ok('WHATS-NEW.md: at least one release heading', wnHeadings.length >= 1,
    `got ${wnHeadings.length}`);
ok('WHATS-NEW.md: within the byte cap', wn.bytes.length <= MAX_BYTES);

/* No-internals scan on the EXTRACTED, user-visible text (the `## ` headings +
   `- ` bullets only — the awk already drops the developer note at the top of
   WHATS-NEW.md, which intentionally names files). Patterns are chosen to catch
   leakage without tripping on ordinary prose. */
const LEAKS = [
    [/\btbl[A-Z]\w+/, 'a database table name'],
    [/[\w-]+\.(?:php|js|css|md|json|ya?ml|sql)\b/i, 'a source/asset file name'],
    [/(?:^|\s)#\d{2,}\b/, 'an issue/PR number'],
    [/\b(?:includes|manage|appWeb|js\/modules|js\/utils)\//, 'a code path'],
    [/\bIHYMNS_[A-Z_]+\b/, 'an internal constant'],
];
let leak = '';
for (const line of wnText.split('\n')) {
    for (const [re, what] of LEAKS) {
        if (re.test(line)) { leak = `${what}: ${JSON.stringify(line.trim().slice(0, 80))}`; break; }
    }
    if (leak) break;
}
ok('WHATS-NEW.md: the user-facing excerpt leaks no technical internals', leak === '', leak);

/* ------------------------------------------------------------------------
   5. THE GUARD'S OWN GUARD.

   The historical three-section extractor, verbatim from deploy.yml before
   #1589, run through the identical assertions. It MUST fail some of them.
   If it ever stops failing, the assertions above have stopped discriminating
   and this suite is lying about its coverage.
   ------------------------------------------------------------------------ */
const LEGACY_STEP = [
    'SOURCE_DIR="${{ steps.target.outputs.source_dir }}"',
    'WHATS_NEW_DIR="${SOURCE_DIR}data"',
    'WHATS_NEW_FILE="${WHATS_NEW_DIR}/whats-new.md"',
    'WHATS_NEW_MAX_BYTES=49152',
    '',
    'if [[ ! -f "CHANGELOG.md" ]]; then',
    '  echo "CHANGELOG.md not found"',
    'else',
    '  mkdir -p "$WHATS_NEW_DIR"',
    '  awk \'',
    '    /^## / { section++; if (section > 3) exit }',
    '    section >= 1 { print }',
    '  \' "CHANGELOG.md" | head -c "$WHATS_NEW_MAX_BYTES" \\',
    '    | iconv -f UTF-8 -t UTF-8 -c > "$WHATS_NEW_FILE" || true',
    'fi',
].join('\n');

/* Run the legacy extractor with the counters swapped out (and console.error
   muted), so its EXPECTED failures are tallied separately and reported as
   evidence instead of being mistaken for real ones. */
const realPassed = passed;
const realFailed = failed;
const realConsoleError = console.error;
passed = 0;
failed = 0;
const legacyNotes = [];
let legacyFailures;
let legacyChecks;
try {
    console.error = () => {};
    /* Feed the historical extractor the CHANGELOG FROM ITS LARGEST SECTION
       onward, so that section is section 1 for the legacy `head -c` and alone
       overflows the cap — the exact #1589 shape (a mid-section byte cut) — no
       matter where that bulk currently sits in the file. A small release
       landing on top must not silently push the reproducing section past the
       legacy extractor's 3-section window and make this control stop failing
       (rule #34 — the same section-ordering robustness 4b above already uses;
       the slice is real CHANGELOG text, so the emitted lines are genuine lines
       and the failure is a true truncation, not a foreign-line artefact). */
    const legacyChangelog = readFileSync(CHANGELOG, 'utf8').slice(largestSectionStart);
    legacyFailures = assertExcerpt(
        runExtractor(LEGACY_STEP, { changelogText: legacyChangelog }),
        'legacy 3-section extractor',
        m => legacyNotes.push(m),
    );
    legacyChecks = passed + failed;
} finally {
    console.error = realConsoleError;
    passed = realPassed;
    failed = realFailed;
}

check('the legacy 3-section extractor still fails these assertions', legacyFailures > 0, true);
record(`legacy extractor: ${legacyFailures} of ${legacyChecks} assertions violated (expected — that is #1589)`);
legacyNotes.forEach(record);

console.log(notes.map(n => `  · ${n}`).join('\n'));
console.log(`\n${passed} passed, ${failed} failed`);
process.exit(failed === 0 ? 0 : 1);
