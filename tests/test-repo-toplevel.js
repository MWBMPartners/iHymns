/**
 * tests/test-repo-toplevel.js — no unexpected folder at the top of the
 * repository (companion to tests/test-no-tracked-scratch.js)
 *
 * PURPOSE
 * -------
 * ELI5: this project has a small, deliberate set of folders at its very top
 * level — one per platform, plus tests, tools, data and so on. This test
 * asks git "which top-level folders are you actually following?" and fails
 * the build if a folder nobody decided on has appeared among them. Adding a
 * real new top-level folder stays perfectly possible; it just has to be a
 * decision somebody wrote down here, rather than something that turned up.
 *
 * WHY THIS EXISTS
 * ----------------
 * A folder called `_temp/` — the owner's own working scratch space, holding
 * ProPresenter exports of copyrighted worship lyrics — was committed to this
 * PUBLIC repository and stayed there for months. Its sibling test,
 * tests/test-no-tracked-scratch.js, now catches that *specific* shape of the
 * mistake: a file that git is following even though `.gitignore` says it
 * should never have been.
 *
 * But that check can only fire once somebody has written the ignore rule.
 * The genuinely dangerous version of this mistake is the one where nobody
 * ever thought to: a scratch folder, an export dump, a "notes" folder, a
 * downloaded dataset — committed straight in at the top level with no rule
 * anywhere saying it should not be. Nothing about that trips a single
 * existing check. It simply becomes part of the repository, and stays.
 *
 * This test closes that gap from the other direction. Instead of "does any
 * tracked file contradict a rule we wrote?", it asks "is the shape of the
 * top level still what we agreed it was?" — which needs no rule to have been
 * written in advance, and which notices a stray folder on the very first
 * build after it is committed rather than months later.
 *
 * WHAT IS ACTUALLY CHECKED
 * -------------------------
 *   1. NO UNEXPECTED TRACKED FOLDER (fails the build). Every folder git is
 *      currently following at the top level must be one of the agreed ones
 *      listed in EXPECTED_TOP_LEVEL_DIRS below. This is the real check —
 *      it is about what is IN the repository, so it behaves identically on
 *      every machine and in CI, and it cannot be tripped by anything a
 *      developer happens to have lying around locally.
 *
 *   2. NO MISSING FOLDER (fails the build). Every folder in that same list
 *      must still be tracked. This is what stops the list quietly rotting
 *      into a description of a repository that no longer exists: delete or
 *      rename `wiki/` and this goes red until the list is updated to match,
 *      so the list can never drift far from reality unnoticed.
 *
 *   3. STRAY UNCOMMITTED FOLDER (a note, NOT a failure). A top-level folder
 *      that is on disk, is not tracked, and is not ignored either, is one
 *      `git add .` away from becoming exactly the incident above. It is
 *      worth pointing out — but it is a purely local condition (CI's
 *      checkout never has one) and a developer may perfectly reasonably
 *      have a scratch folder open mid-task. Failing on it would make this
 *      test go red on correct work, and rule #34 is explicit that a guard
 *      which fails on correct code gets weakened or deleted rather than
 *      fixed. So it prints a clearly-worded note and leaves the exit status
 *      alone.
 *
 *   4. NON-VACUITY (fails the build). Both real checks above only mean
 *      something if git actually answered. A broken or mis-directed git
 *      call returning an empty list would otherwise leave this test
 *      permanently, wrongly green — the exact failure rule #34 warns about.
 *      So it requires a realistic number of tracked files before it is
 *      willing to trust either answer, and re-proves the detection itself
 *      in Step 0 below.
 *
 * MECHANISM — where the OBSERVED list comes from
 * -----------------------------------------------
 * `git ls-files -z` is git's own list of every file it is currently
 * following (https://git-scm.com/docs/git-ls-files). Take the part of each
 * path before the first `/` and you have every top-level folder that
 * actually contains tracked content — derived from the repository itself,
 * never typed out, and never read off the disk (so an ignored folder such
 * as `node_modules/` or `_temp/` is absent by construction, with no naming
 * of them here needed). Paths with no `/` in them are top-level FILES, not
 * folders, and are ignored.
 *
 * ABOUT THE EXPECTED LIST
 * ------------------------
 * Rule #34 says a guard's list must be derived from the tree rather than
 * typed from memory. The list below was produced by running exactly the
 * command this test runs, against the real repository, and writing down
 * what came back — not from recollection. It then has to be a written-down
 * constant, because that IS the thing being checked: "the agreed shape".
 * A check with no agreed shape to compare against could not detect a new
 * folder at all.
 *
 * What keeps it honest is check 2. A one-directional allow-list would rot
 * silently as folders were renamed; requiring every named folder to still
 * exist means any drift shows up as a failure the same day.
 *
 * ADDING A NEW TOP-LEVEL FOLDER
 * ------------------------------
 * Add it to the list below, with a few words saying what it holds. That is
 * the entire process, and the deliberateness is the point: a new folder at
 * the top of the repository is a structural decision, and this makes it one
 * somebody consciously made and explained rather than one that happened.
 *
 * MUTATION PROOF (rule #34 — run by hand, pasted into the PR):
 *   (i)  Created a throwaway top-level folder with a tracked file in it
 *        (`git add -f`) -> check 1 went RED and named it. Removed it ->
 *        GREEN again. Both runs are in the pull request.
 *   (ii) Step 0's positive control re-proves, on every run, that the
 *        "which top-level folders are tracked?" question still works, by
 *        asking it of a throwaway repository whose answer is known.
 *
 *   node tests/test-repo-toplevel.js
 *
 * Auto-discovered by tools/run-node-tests.js's `tests/*.js` glob — no
 * registration needed.
 *
 * Exit 0 = the top level is the agreed shape, 1 = it is not (or the
 * detection itself looks broken).
 */

import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const REPO = path.join(__dirname, '..');

/**
 * The agreed top-level folders, each with what it is for.
 *
 * Read off the real repository with the same command this test uses, not
 * from memory — see "ABOUT THE EXPECTED LIST" above. Adding a genuinely new
 * one is a one-line edit here plus a few words of explanation.
 */
const EXPECTED_TOP_LEVEL_DIRS = new Map([
    ['.agents',     'shared instructions for coding assistants other than Claude'],
    ['.claude',     'Claude Code project context, rules, plans and session history'],
    ['.github',     'GitHub Actions workflows and repository configuration'],
    ['.importers',  'the song-scraper and importer scripts'],
    ['.vscode',     'the shared VS Code settings that travel with the project'],
    ['appAndroid',  'the Android app (and Amazon FireOS, which is a target of it)'],
    ['appApple',    'the iOS / iPadOS / tvOS apps and their shared Swift code'],
    ['appWeb',      'the website and progressive web app (PHP + JavaScript)'],
    ['data',        'source song data and seed data'],
    ['help',        'the user-facing help articles'],
    ['tests',       'the test suites, for every platform'],
    ['tools',       'build, data-preparation and developer scripts'],
    ['wiki',        'the project wiki pages kept alongside the code']
]);

/* Below this many tracked files, treat the whole run as suspicious rather
   than trusting a "found nothing wrong" answer (rule #34 — a guard that
   examined nothing and printed green is the failure this file exists to
   prevent). This repository tracks a few thousand files; 500 is a floor
   comfortably below that but nowhere near zero/broken. */
const MIN_EXPECTED_TRACKED_FILES = 500;

let failures = 0;
function check(name, cond, detail) {
    if (cond) { console.log('  ✓ ' + name); return; }
    failures++;
    console.error('  ✗ ' + name + (detail ? '\n' + detail : ''));
}

/** Split a NUL-separated (`-z`) git output string into an array, dropping
 *  the empty trailing element the final terminating NUL leaves behind. */
function splitNul(s) {
    if (!s) return [];
    const parts = s.split('\0');
    if (parts.length && parts[parts.length - 1] === '') parts.pop();
    return parts;
}

/** THE detection, in one place: which top-level folders is git following?
 *
 *  ELI5: ask git for every file it tracks, then keep the bit of each path
 *  before the first slash. A path with no slash is a file sitting at the
 *  top level, not a folder, so it is skipped.
 *
 *  This lives in one function with TWO callers on purpose — the real
 *  repository, where the right answer is the agreed list, and a throwaway
 *  repository built in Step 0 whose answer is known in advance. Because
 *  both go through this same code, the throwaway one is a live proof that
 *  the question is still being asked properly. If it ever stops working,
 *  Step 0 goes red at once, instead of the real answer quietly coming back
 *  empty and being mistaken for good news.
 *
 *  https://git-scm.com/docs/git-ls-files
 */
function trackedTopLevelDirs(cwd, env) {
    const opts = { cwd, encoding: 'utf8' };
    if (env) opts.env = env;
    const r = spawnSync('git', ['ls-files', '-z'], opts);
    if (r.error) {
        throw new Error(`could not run "git ls-files -z" in ${cwd}: ${r.error.message}`);
    }
    const files = splitNul(r.stdout);
    const dirs = new Set();
    for (const p of files) {
        const slash = p.indexOf('/');
        if (slash > 0) dirs.add(p.slice(0, slash));
    }
    return { status: r.status, stderr: r.stderr, files, dirs };
}

console.log('tests/test-repo-toplevel.js — the top level of the repository is the agreed shape:');

/* ---------------------------------------------------------------------
 * Step 0 — POSITIVE CONTROL: prove the detection still works before
 * trusting anything it says.
 *
 * ELI5: the main check treats "git named no unexpected folder" as good
 * news. But that is ALSO exactly what you get if the question has stopped
 * working — an empty answer and a clean answer look identical from the
 * outside, and one of them is a broken test that will wave the next stray
 * folder straight through while printing a tick.
 *
 * Detail: the tracked-file floor further down does not cover this. It
 * proves `git ls-files` returned a list; it says nothing about whether the
 * folding of those paths into folder names still works. And these commands
 * do genuinely change: git 2.32 altered what `git ls-files -i` requires
 * (https://git-scm.com/docs/git-ls-files), so "it still behaves the way it
 * did when this was written" is an assumption with a counter-example.
 *
 * So this builds a throwaway repository containing one file inside a
 * folder and one file at the top level, runs the very same function
 * against it, and requires it to report the folder and not the loose file.
 * The temporary folder is removed either way.
 * ------------------------------------------------------------------- */
function probeDetectionPrimitive() {
    /* A stray GIT_DIR / GIT_WORK_TREE / GIT_INDEX_FILE left in the
       environment would make every git call below quietly operate on the
       REAL repository instead of the throwaway one — both wrong and, if it
       ever happened, extremely confusing. Drop them for the probe only. */
    const env = { ...process.env };
    delete env.GIT_DIR;
    delete env.GIT_WORK_TREE;
    delete env.GIT_INDEX_FILE;

    const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'ihymns-toplevel-probe-'));
    try {
        const run = (args) => spawnSync('git', args, { cwd: dir, encoding: 'utf8', env });
        run(['init', '-q']);
        fs.mkdirSync(path.join(dir, 'probe-folder'));
        fs.writeFileSync(path.join(dir, 'probe-folder', 'inside.txt'), 'x');
        fs.writeFileSync(path.join(dir, 'loose-file.txt'), 'x');
        run(['add', 'probe-folder/inside.txt', 'loose-file.txt']);
        /* Deliberately the SAME function the real repository is checked
           with below — that shared path is what makes this a live proof
           rather than a parallel implementation that could drift away from
           the thing it is supposed to be vouching for. */
        return trackedTopLevelDirs(dir, env);
    } finally {
        fs.rmSync(dir, { recursive: true, force: true });
    }
}

const probe = probeDetectionPrimitive();
check(
    'detection works: folding "git ls-files" paths still finds a known tracked top-level folder',
    probe.status === 0 && probe.dirs.has('probe-folder'),
    `    Ran it against a throwaway repo holding one tracked file inside "probe-folder" and got\n` +
    `    exit ${probe.status}, folders [${[...probe.dirs].join(', ')}]` +
    `${(probe.stderr || '').trim() ? ' (stderr: ' + probe.stderr.trim() + ')' : ''}.\n` +
    '    It should have reported "probe-folder". Until it does, the checks below cannot tell\n' +
    '    "nothing is wrong" apart from "the question stopped working", so a tick from them means nothing.'
);
check(
    'detection is not over-broad: a top-level FILE is not mistaken for a folder',
    !probe.dirs.has('loose-file.txt'),
    '    It also reported "loose-file.txt", which is a file sitting at the top level, not a folder —\n' +
    '    so anything reported below may be noise rather than a real problem.'
);

/* ---------------------------------------------------------------------
 * Step 1 — what git is actually following, right now.
 * ------------------------------------------------------------------- */
const tracked = trackedTopLevelDirs(REPO);
assert.equal(tracked.status, 0, `"git ls-files -z" exited ${tracked.status}, stderr: ${tracked.stderr}`);

check(
    `git ls-files found a realistic number of tracked files (>= ${MIN_EXPECTED_TRACKED_FILES})`,
    tracked.files.length >= MIN_EXPECTED_TRACKED_FILES,
    `    found ${tracked.files.length} — a count this low almost certainly means the git command ran\n` +
    '    against the wrong directory, an empty checkout, or failed silently, NOT that the\n' +
    '    repository genuinely shrank. The checks below are meaningless until this is real.'
);
console.log(`  (checked ${tracked.files.length} tracked files -> ${tracked.dirs.size} top-level folder(s))`);

/* ---------------------------------------------------------------------
 * Step 2 — anything tracked that nobody agreed to. THE check.
 * ------------------------------------------------------------------- */
const unexpected = [...tracked.dirs].filter((d) => !EXPECTED_TOP_LEVEL_DIRS.has(d)).sort();

if (unexpected.length === 0) {
    check('every tracked top-level folder is one of the agreed ones', true);
} else {
    failures++;
    console.error(`  ✗ ${unexpected.length} top-level folder(s) are tracked by git that nobody agreed to:`);
    for (const d of unexpected) {
        console.error(`      ${d}/`);
    }
    console.error('    A folder at the top of the repository is a structural decision. If one of these is a');
    console.error('    scratch, export or dump folder that should never have been committed, stop tracking it:');
    console.error('      git rm -r --cached <folder>');
    console.error('    (this leaves the folder alone on your own disk) and add it to .gitignore so it cannot');
    console.error('    come back. That is exactly how the copyrighted `_temp/` exports got in — see');
    console.error('    tests/test-no-tracked-scratch.js for that story.');
    console.error('    If it IS meant to be here, add it to EXPECTED_TOP_LEVEL_DIRS in this file with a few');
    console.error('    words saying what it holds.');
}

/* ---------------------------------------------------------------------
 * Step 3 — has an agreed folder disappeared? This is what stops the list
 * above rotting into a description of a repository that no longer exists.
 * ------------------------------------------------------------------- */
const missing = [...EXPECTED_TOP_LEVEL_DIRS.keys()].filter((d) => !tracked.dirs.has(d)).sort();

if (missing.length === 0) {
    check('every agreed top-level folder is still tracked (the list has not gone stale)', true);
} else {
    failures++;
    console.error(`  ✗ ${missing.length} folder(s) named in EXPECTED_TOP_LEVEL_DIRS are no longer tracked:`);
    for (const d of missing) {
        console.error(`      ${d}/  — listed as: ${EXPECTED_TOP_LEVEL_DIRS.get(d)}`);
    }
    console.error('    Either the folder was deliberately removed or renamed — in which case update the list');
    console.error('    in this file to match — or something has gone wrong with the checkout. An allow-list');
    console.error('    that names folders which no longer exist stops describing anything real, which is why');
    console.error('    this direction is checked too.');
}

/* ---------------------------------------------------------------------
 * Step 4 — a NOTE, deliberately not a failure: a top-level folder sitting
 * on disk that is neither tracked nor ignored. It is one `git add .` away
 * from being the incident this file exists to prevent, so it is worth
 * saying out loud — but it is a purely local condition (CI's checkout
 * never has one) and a developer may quite reasonably be mid-task with a
 * scratch folder open. Failing here would make this test go red on
 * correct work, and rule #34 is explicit that such a guard gets weakened
 * or deleted rather than fixed.
 *
 * `git ls-files --others --exclude-standard --directory` is git's own
 * answer to "what is here that you are not following and have not been
 * told to ignore?", with `--directory` collapsing a whole untracked folder
 * to a single entry instead of listing every file in it.
 * https://git-scm.com/docs/git-ls-files
 * ------------------------------------------------------------------- */
const others = spawnSync(
    'git',
    ['ls-files', '--others', '--exclude-standard', '--directory', '-z'],
    { cwd: REPO, encoding: 'utf8' }
);
if (!others.error && others.status === 0) {
    const strays = new Set();
    for (const p of splitNul(others.stdout)) {
        /* --directory reports an untracked folder with a trailing slash. */
        if (!p.endsWith('/')) continue;
        const name = p.replace(/\/+$/, '');
        if (name.includes('/')) continue;          // not at the top level
        if (EXPECTED_TOP_LEVEL_DIRS.has(name)) continue;
        strays.add(name);
    }
    if (strays.size > 0) {
        console.log(`  ℹ ${strays.size} top-level folder(s) are on your disk but are neither committed nor ignored:`);
        for (const name of [...strays].sort()) {
            console.log(`      ${name}/`);
        }
        console.log('    This is a note about your own working copy, not a failure — nothing is in the');
        console.log('    repository. But a folder in this state is one `git add .` away from being committed');
        console.log('    by accident. If it is scratch space, add it to .gitignore now.');
    }
}

if (failures) {
    console.error(`\nFAIL: ${failures} check(s) failed.`);
    process.exit(1);
}
console.log('\nOK: the top level of the repository holds exactly the agreed folders.');
