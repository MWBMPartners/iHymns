/**
 * tests/test-bump-classifier.js — classify-bump.sh functional truth table (#1963)
 *
 * PURPOSE
 * ELI5: classify-bump.sh reads a batch of commit messages and decides, in one
 * word, whether the code since the last version-line edit deserves a bigger
 * marketing version — "major", "minor", a deliberate "patch" — or none at all
 * ("none" — just a build, the commit-count number keeps climbing but the
 * marketing version doesn't move). This test feeds it every commit shape the
 * real classifier has to get right and checks the one word it prints back.
 *
 * FOUR LEVELS (marketing-version/build-number split + deliberate patch
 * mechanism, #<this-issue> — see .claude/CLAUDE.md rule #46 for the full
 * contract): a `feat:` subject -> "minor"; a `!` subject marker or a
 * line-anchored `BREAKING CHANGE:`/`BREAKING-CHANGE:` body footer -> "major";
 * a WHOLE-LINE, case-insensitive `Release: patch` body footer -> "patch" (a
 * deliberate bug-fix release — put the line at the end of the PR description
 * so it lands in the squash-merge body); everything else -> "none" (the web
 * deploys continuously, so an ordinary fix/chore/docs must not churn the
 * visible version — only the separate build number moves). Precedence across
 * a multi-commit range: major > minor > patch > none.
 *
 * DETAIL — this is the standalone functional counterpart to
 * tests/test-versioning-pipeline.js (which checks the WORKFLOW WIRING around
 * the classifier — that deploy.yml actually calls it, in the right order, with
 * the right format string). This file checks the classifier's OWN logic in
 * isolation, spawning the real script against piped fixtures — never a
 * reimplementation of its regex in JS (rule #35: the test IS the mechanism,
 * not a second copy of the rule it's checking).
 *
 * FIXTURE SHAPE: the script's stdin contract (see its own header) is
 * `git log --format='%s%x1f%b%x1e'` — one record per commit, subject and body
 * separated by 0x1F, records separated by 0x1E. `rec(subject, body)` below
 * builds exactly that byte shape so a fixture is indistinguishable from what
 * deploy.yml's own `git log` invocation would actually pipe in.
 *
 * Conventional Commits spec (what "feat"/"fix"/"!"/"BREAKING CHANGE:" mean):
 * https://www.conventionalcommits.org/en/v1.0.0/
 *
 *   node tests/test-bump-classifier.js
 *
 * Auto-discovered by tools/run-node-tests.js's `tests/*.js` glob — no
 * registration needed (confirmed by reading that runner: it globs
 * non-recursively and sorts, so a new top-level tests/*.js file is picked up
 * automatically the next time `npm test` / CI's "Node unit tests" step runs).
 *
 * Exit 0 = every case matches, 1 = drift.
 */

import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const REPO = path.join(__dirname, '..');
const SCRIPT = path.join(REPO, '.github', 'workflows', 'scripts', 'classify-bump.sh');

const US = '\x1f'; // unit separator — between a record's subject and body
const RS = '\x1e'; // record separator — between commit records

let failures = 0;
function check(name, cond, detail) {
    if (cond) { console.log('  ✓ ' + name); return; }
    failures++;
    console.error('  ✗ ' + name + (detail ? '\n      ' + detail : ''));
}

/** Build one `%s%x1f%b%x1e` record. body defaults to "" (no commit body). */
function rec(subject, body = '') {
    return subject + US + body + RS;
}

/** Concatenate records into the exact byte stream `git log --format=...` emits. */
function input(...records) {
    return records.join('');
}

/** Run the real classifier against `stdinText`, return its trimmed stdout. */
function classify(stdinText) {
    const r = spawnSync('bash', [SCRIPT], { input: stdinText, encoding: 'utf8' });
    assert.equal(r.status, 0, `classify-bump.sh exited ${r.status}, stderr: ${r.stderr}`);
    return r.stdout.trim();
}

check('classify-bump.sh exists', fs.existsSync(SCRIPT));
check('classify-bump.sh is executable',
    fs.existsSync(SCRIPT) && (fs.statSync(SCRIPT).mode & 0o111) !== 0,
    'the deploy.yml step invokes it as `bash .../classify-bump.sh` (works either way) but +x is the documented contract');

console.log('classify-bump.sh truth table:');

/* ---- none: everyday conventional-commit types that are never a release --- */
for (const subject of ['fix: patch a bug', 'chore(deps): bump terser', 'docs: fix a typo',
    'perf: speed up search', 'refactor: extract a helper', 'ci: pin an action']) {
    check(`"${subject}" -> none`, classify(input(rec(subject))) === 'none');
}

/* ---- minor: an explicit feat, with or without a scope -------------------- */
check('"feat: add X" -> minor', classify(input(rec('feat: add X'))) === 'minor');
check('"feat(scope): add X" -> minor', classify(input(rec('feat(scope): add X'))) === 'minor');

/* ---- major: subject `!` marker, with/without scope, on feat OR fix ------- */
check('"feat!: X" -> major', classify(input(rec('feat!: X'))) === 'major');
check('"feat(scope)!: X" -> major', classify(input(rec('feat(scope)!: X'))) === 'major');
check('"fix!: X" -> major', classify(input(rec('fix!: X'))) === 'major');

/* ---- major: a line-anchored BREAKING CHANGE / BREAKING-CHANGE footer ----- */
check('body "BREAKING CHANGE:" footer -> major',
    classify(input(rec('chore: routine', 'Refactor internals.\n\nBREAKING CHANGE: dropped the old API'))) === 'major');
check('body "BREAKING-CHANGE:" footer -> major',
    classify(input(rec('chore: routine', 'BREAKING-CHANGE: hyphen spelling per the spec'))) === 'major');

/* ---- none: a BREAKING CHANGE mention that is NOT line-anchored, plus a
   bulleted `* feat!:` in the body — proves the grep is genuinely line-
   anchored (only the START of a line counts) and that body text is never
   mistaken for a conventional-commit SUBJECT (only `%s` is ever classified
   against re_major/re_minor; `%b` is only ever checked for the literal
   line-anchored BREAKING CHANGE footer). ------------------------------------ */
check('mid-line "see the BREAKING CHANGE:" + bulleted "* feat!:" in body -> none',
    classify(input(rec('fix: normal patch',
        'See the BREAKING CHANGE: section in the linked doc for context.\n* feat!: not a real subject, just a bullet'))) === 'none');

/* ---- none: the real #1955 commit subject (title-case prose with a colon
   inside it, not a conventional-commit prefix) --------------------------- */
check('real "Dormant-features audit: four silent failures fixed (#1955)" -> none',
    classify(input(rec('Dormant-features audit: four silent failures fixed (#1955)'))) === 'none');

/* ---- none: near-miss spellings that must NOT be recognised --------------- */
check('"Feat:" (capitalised) -> none', classify(input(rec('Feat: add X'))) === 'none');
check('"feature:" (not the "feat" token) -> none', classify(input(rec('feature: add X'))) === 'none');
check('"feat :" (space before colon) -> none', classify(input(rec('feat : add X'))) === 'none');

/* ---- multi-commit ranges: the highest bump across all records wins ------- */
check('fix + feat -> minor (feat anywhere lifts the range)',
    classify(input(rec('fix: a'), rec('feat: b'))) === 'minor');
check('feat + fix -> minor (order does not matter)',
    classify(input(rec('feat: a'), rec('fix: b'))) === 'minor');
check('fix + feat! -> major (a later major still wins)',
    classify(input(rec('fix: a'), rec('feat!: b'))) === 'major');
check('feat! + fix -> major (an earlier major short-circuits and is not downgraded)',
    classify(input(rec('feat!: a'), rec('fix: b'))) === 'major');
check('feat! + feat (no !) -> major, NOT downgraded to minor by the later record',
    classify(input(rec('feat!: a'), rec('feat: b'))) === 'major');

/* ---- empty range: no commits since the anchor => none --------------------- */
check('empty stdin -> none', classify('') === 'none');

/* ---- patch: an explicit, whole-line `Release: patch` body footer ---------- */
check('fix + body "Release: patch" footer -> patch',
    classify(input(rec('fix: correct the sort order', 'Small bug.\n\nRelease: patch'))) === 'patch');
check('chore + "Release: patch" -> patch (the TYPE does not matter, only the footer)',
    classify(input(rec('chore: routine', 'Release: patch'))) === 'patch');
check('case-insensitive: "release: PATCH" -> patch (house marker, not the spec-exact BREAKING token)',
    classify(input(rec('fix: x', 'release: PATCH'))) === 'patch');
check('"Release:patch" (no space after the colon) -> patch',
    classify(input(rec('fix: x', 'Release:patch'))) === 'patch');

/* ---- none: near-misses that must NOT cut a patch release ------------------ */
check('mid-line "…see Release: patch…" -> none (line-anchored)',
    classify(input(rec('fix: x', 'For details see Release: patch in the docs.'))) === 'none');
check('"Release: patch notes are in the wiki" -> none (WHOLE-line anchored — trailing prose kills it)',
    classify(input(rec('fix: x', 'Release: patch notes are in the wiki'))) === 'none');
check('"Release: patches" -> none', classify(input(rec('fix: x', 'Release: patches'))) === 'none');
check('"See the Release: patch" -> none (marker sits at line-END but NOT line-START — isolates the'
    + ' `^` anchor specifically: the other near-miss cases above all have trailing prose after'
    + ' "patch" too, so the `$` anchor alone would already block them even with `^` missing)',
    classify(input(rec('fix: x', 'See the Release: patch'))) === 'none');
check('SUBJECT "Release: patch" with empty body -> none (footer lives in the BODY, mirroring BREAKING CHANGE)',
    classify(input(rec('Release: patch'))) === 'none');
check('plain "fix:" with NO footer stays none (build-only — the deliberate default)',
    classify(input(rec('fix: an ordinary bug fix'))) === 'none');

/* ---- precedence: major > minor > patch ------------------------------------ */
check('feat subject + patch footer in the SAME record -> minor (minor outranks patch)',
    classify(input(rec('feat: add X', 'Release: patch'))) === 'minor');
check('feat commit + later patch-footer commit -> minor (a footer never downgrades the range)',
    classify(input(rec('feat: a'), rec('fix: b', 'Release: patch'))) === 'minor');
check('patch-footer commit + later feat -> minor (order does not matter)',
    classify(input(rec('fix: a', 'Release: patch'), rec('feat: b'))) === 'minor');
check('patch footer + BREAKING CHANGE footer in range -> major',
    classify(input(rec('fix: a', 'Release: patch'), rec('chore: b', 'BREAKING CHANGE: gone'))) === 'major');
check('patch + fix -> patch (a patch signal survives unrelated build-only commits)',
    classify(input(rec('fix: a', 'Release: patch'), rec('fix: b'))) === 'patch');

if (failures) {
    console.error(`\nFAIL: ${failures} classify-bump.sh check(s) failed.`);
    process.exit(1);
}
console.log('\nOK: classify-bump.sh classifies every case correctly (none/minor/major/patch, scopes, `!`, BREAKING CHANGE footers, Release: patch footers, near-misses, multi-commit ranges, precedence, empty input).');

/* -----------------------------------------------------------------------------
 * MUTATION PROOF (run by hand, recorded in the #1963 commit/PR body):
 *   (i)   change `bump="none"` to `bump="minor"`           => every "-> none"
 *         case goes RED (they now print "minor" instead of "none").
 *   (ii)  loosen re_minor from '^feat(\([^)]*\))?:' to '^feat' (drop the
 *         colon/paren requirement) => the "feature:" near-miss case goes RED
 *         (it now wrongly classifies as minor).
 *   (iii) drop the `^` from the BREAKING grep (`'BREAKING[ -]CHANGE:'`
 *         instead of `'^BREAKING[ -]CHANGE:'`) => the mid-line/bulleted case
 *         goes RED (the "See the BREAKING CHANGE:" mid-line mention now wrongly
 *         fires major).
 *   (iv)  remove the `break` after `bump="major"` => the "feat! + feat (no !)
 *         -> major" case goes RED: without `break`, the loop keeps running
 *         after the major hit, reaches the second record ("feat: b"), and
 *         falls through to `[[ "$subject" =~ $re_minor ]] && bump="minor"` —
 *         which DOES match "feat: b", silently downgrading the range from
 *         "major" back to "minor". The `fix: b`-only combo above does NOT
 *         catch this (a bare "fix:" never matches re_minor either), which is
 *         why the "feat! + feat" combo is the one that actually exercises the
 *         `break`.
 *   (v)   restore all three                                => GREEN.
 *
 * MUTATION PROOF — the `patch` level (#<this-issue>, run by hand this
 * session, recorded in the PR body):
 *   (vi)   make bare `fix:` classify as patch (e.g. add
 *          `[[ "$subject" =~ ^fix ]] && bump="patch"` unconditionally) =>
 *          'plain "fix:" … stays none' goes RED, and the whole "none" block
 *          above it goes RED too (every bare fix: now prints "patch").
 *   (vii)  drop the `$` end anchor from re_patch (leave `^release:[[:space:]]
 *          *patch[[:space:]]*` unterminated) => '"Release: patch notes are in
 *          the wiki" -> none' goes RED (now wrongly fires patch on trailing
 *          prose).
 *   (viii) drop the `^` start anchor from re_patch => '"See the Release:
 *          patch" -> none' goes RED (now wrongly fires patch). NOTE: this
 *          mutation does NOT redden the other "mid-line"/"…in the wiki" near-
 *          miss cases above — checked directly against a scratch copy while
 *          writing this suite — because every one of THOSE bodies also has
 *          trailing prose AFTER "patch", so the `$` end anchor alone already
 *          blocks them with or without `^`. Only a body where the marker
 *          sits at the line's END but not its START (no trailing prose)
 *          isolates the start anchor specifically — that's what the new
 *          "See the Release: patch" case above is for (rule #34: a mutation
 *          claim must be verified against the real cases, not assumed).
 *   (ix)   remove the `[[ "$bump" == "none" ]]` guard on the patch branch =>
 *          'feat commit + later patch-footer commit -> minor' goes RED (the
 *          later patch-footer record now downgrades an already-classified
 *          "minor" down to "patch").
 *   (x)    restore all                                     => GREEN.
 * --------------------------------------------------------------------------- */
