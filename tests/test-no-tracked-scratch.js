/**
 * tests/test-no-tracked-scratch.js — nothing tracked by git should be
 * something .gitignore says must never be tracked (#temp-copyrighted-samples)
 *
 * PURPOSE
 * -------
 * ELI5: `.gitignore` is a list of "don't track this" rules. But telling git
 * a rule exists does NOT make git forget about a file it is already
 * following — a rule only stops a file being added for the FIRST time.
 * If a file was committed before the rule existed (or someone force-added
 * it with `git add -f`), it stays tracked forever, quietly, no matter how
 * many `.gitignore` lines are added afterwards. This test asks git, on
 * every build, "is there any file you are currently following that your
 * own ignore rules say should never have been followed?" — and fails loudly
 * if the answer is yes, naming the file and the exact rule it contradicts.
 *
 * WHY THIS EXISTS
 * ----------------
 * Twelve ProPresenter export files containing copyrighted worship lyrics
 * (including a Hillsong song under CCLI licence) were committed to this
 * PUBLIC repository and stayed tracked for months. The project had already
 * decided, in writing, twice, that this must never happen:
 *   - tools/pp7-sanitise-fixture.js says the real files "live in `_temp/`
 *     on alpha and are NOT committed".
 *   - tests/fixtures/propresenter/README.md has a section headed
 *     "Deliberately NOT committed" naming these exact files.
 * `.gitignore` even had a rule for the folder the whole time. None of that
 * stopped it, because a comment and an ignore rule are not a mechanism
 * (CLAUDE.md rule #35) — nothing ever checked whether the two agreed with
 * reality. This file is that check.
 *
 * WHAT IS ACTUALLY CHECKED
 * -------------------------
 *   1. TRACKED-BUT-IGNORED (fails the build): every file `git` is
 *      currently following is compared against git's own ignore rules
 *      (.gitignore, `.git/info/exclude`, the global excludes file — the
 *      same "standard" set `git add` itself consults). Any tracked file
 *      that a rule would exclude, and that a LATER `!`-negation rule does
 *      not deliberately un-exclude, is reported by name together with the
 *      exact rule (file:line:pattern) that contradicts it. See MECHANISM
 *      below for how this is derived from git itself rather than typed out
 *      by hand (rule #34).
 *   2. OVERSIZED TRACKED FILES (fails the build): a separate, forward-
 *      looking guard against the OTHER way this folder could hurt the
 *      repository — sheer bulk. Being accurate about this matters, because
 *      it is easy to assume this check would have caught the incident and
 *      it would not have: the twelve files that were actually committed
 *      came to 11.4 MB in total, the largest a single 5.6 MB file, so a
 *      50 MB threshold would not have fired once. The genuinely enormous
 *      files DO exist — the same folder on the owner's disk holds a 382 MB
 *      and an 813 MB `.proplaylist`, a 122 MB `.protheme` and an 86 MB
 *      `.probundle` — but every one of those was, by luck rather than by
 *      any mechanism, never committed. Check 1 above is what actually
 *      addresses the incident; this one exists so that the next near-miss
 *      of that kind is stopped by something other than luck. A `.gitignore`
 *      rule only ever prevents a NEW oversized file being added and does
 *      nothing about one already tracked, so this looks at the real byte
 *      size of every currently tracked file on disk.
 *   3. NON-VACUITY (fails the build): both checks above only mean anything
 *      if they actually looked at a realistic number of files. A broken
 *      git invocation that silently returns nothing would otherwise leave
 *      this test permanently, wrongly green — exactly the failure mode
 *      rule #34 warns about ("a guard that finds nothing and prints green
 *      is the exact failure this whole task is about"). So this file
 *      asserts it examined at least a few hundred tracked files before it
 *      is willing to call either check clean. That floor is only HALF the
 *      story though, and the weaker half: it proves `git ls-files` returned
 *      a real list of files, which is what check 2 consumes, but it says
 *      nothing about whether check 1's ignored-file question is still being
 *      answered correctly. Blanking that one answer while leaving the file
 *      count real makes this whole file print a clean bill of health with a
 *      genuinely copyrighted file staged. So there is a second, stronger
 *      layer in front of it — the Step 0 POSITIVE CONTROL below, which
 *      re-proves the detection on a throwaway repo every run.
 *
 * MECHANISM — how "tracked but should be ignored" is derived from git,
 * not typed out by hand
 * --------------------------------------------------------------------
 * `git ls-files -c -i --exclude-standard` is git's OWN answer to "which
 * currently-tracked files does the exclude configuration also match?"
 * (https://git-scm.com/docs/git-ls-files — the `-i`/`--ignored` flag
 * combined with `-c`/`--cached`, the default view of files already in the
 * index). Crucially, this correctly leaves out files a later `!`-negation
 * rule deliberately un-excludes — `.claude/settings.local.json` and
 * `.vscode/settings.json` are both tracked ON PURPOSE (see the "DELIBERATE
 * EXCEPTION" comment in .gitignore) via exactly that mechanism, and git's
 * own resolution of the rules already knows they are not really "ignored"
 * — so nothing here has to special-case them, or any future negated file,
 * by name.
 *
 * To then report WHICH rule a genuinely contradictory file matches (for an
 * actionable failure message), each flagged path is re-checked with
 * `git check-ignore -v -z --no-index --stdin`
 * (https://git-scm.com/docs/git-check-ignore). Two non-obvious details,
 * confirmed by actually running them against this repository rather than
 * assumed (rule #34's "verify by running it, not by reasoning about it"):
 *
 *   - `--no-index` is REQUIRED. Without it, `git check-ignore` on a file
 *     that is already tracked reports "not ignored" regardless of any
 *     matching rule — which is exactly backwards for what this test needs
 *     to detect (a tracked file a rule says should be ignored). `--no-index`
 *     makes it a pure pattern match against the pathname, independent of
 *     whether git happens to already be following it — so it correctly
 *     reports the contradiction instead of hiding it.
 *   - In `-v`/`--verbose` mode specifically, `check-ignore`'s exit status
 *     stops meaning "is this path ignored" and starts meaning "did any rule
 *     match at all" — a file saved by a `!`-negation rule still gets a
 *     verbose line printed (showing the negating pattern, prefixed with
 *     `!`) even though it is NOT actually ignored. This script only ever
 *     feeds `check-ignore` paths that `git ls-files -i` has ALREADY
 *     confirmed are genuinely ignored (not negated) — the pattern lookup
 *     is for the human-readable message, not for the yes/no decision — but
 *     it still defensively asserts the returned pattern never starts with
 *     `!`, so a future git version behaving differently here fails loudly
 *     instead of mis-reporting.
 *
 * FIXING A REAL FAILURE
 * ----------------------
 * A `.gitignore` rule does not undo tracking that already happened. The
 * fix this test's failure message gives is the actual fix:
 *   git rm --cached <path>
 * (removes it from git's index without touching the file on disk) followed
 * by a commit. Adding or already having the ignore rule is not enough on
 * its own — that is the exact gap this whole file exists to close.
 *
 * A GENUINE, ON-PURPOSE EXCEPTION
 * ---------------------------------
 * Some files ARE deliberately tracked despite an otherwise-broad ignore
 * rule matching them (a folder-structure `.gitkeep`, a directory-listing
 * `.htaccess`, a vendored library's own placeholder file that happens to
 * sit in a folder named `tmp/` or `dist/`). The correct way to record that
 * on-purpose intent is a `!`-negation rule in `.gitignore` next to the rule
 * it exempts, explaining why — never a per-file allow-list typed into this
 * test (that would be the same "a list you typed" anti-pattern rule #34
 * bans for the detection itself, just moved one file over).
 *
 * SIZE THRESHOLD
 * ---------------
 * 50 MB. GitHub warns in the push output above 50 MB and hard-rejects any
 * single file over 100 MB at the pre-receive hook
 * (https://docs.github.com/repositories/working-with-files/managing-large-files/about-large-files-on-github).
 * 50 MB is chosen — rather than waiting for the 100 MB hard block — because
 * a push that GitHub merely warns about still succeeds, so a rule any
 * looser than GitHub's OWN warning line would let exactly this kind of
 * bulky file through quietly again. It also comfortably clears every
 * legitimately large file already tracked in this repository today (the
 * biggest, a Claude session transcript, is ~36 MB) with headroom to grow.
 *
 * SCOPE
 * ------
 * Every file `git ls-files` reports (the whole tracked tree), checked
 * against `.gitignore` plus `.git/info/exclude` plus the global excludes
 * file — i.e. exactly the exclude configuration `git add` itself already
 * honours (`--exclude-standard`). Nothing here re-implements `.gitignore`'s
 * pattern syntax; every judgement about what is or is not ignored is asked
 * of git directly, never re-derived in JS.
 *
 *   node tests/test-no-tracked-scratch.js
 *
 * Auto-discovered by tools/run-node-tests.js's `tests/*.js` glob — no
 * registration needed (confirmed by reading that runner: non-recursive,
 * sorted, every top-level `tests/*.js` file runs automatically).
 *
 * MUTATION PROOF (rule #34 — run by hand this session, recorded in the PR):
 *   (i)   `git add -f` a throwaway file under `_temp/` -> the
 *         TRACKED-BUT-IGNORED check goes RED and names the file plus the
 *         `_temp/` rule that contradicts it. Unstaging it (`git restore
 *         --staged <path>` + delete the file) -> GREEN again.
 *   (ii)  Confirmed GREEN on the real, current tree, with
 *         `.claude/settings.local.json`, `.vscode/settings.json` and the
 *         other genuinely negated files still tracked and NOT reported.
 *   (iii) Broke the detection on purpose (temporarily hard-coding the
 *         tracked-file list to `[]`) -> the NON-VACUITY floor check goes
 *         RED rather than the whole file silently reporting "0 problems,
 *         all green" — proving this can't pass by accident from finding
 *         nothing. Restored -> GREEN.
 *   (iv)  Broke the OTHER half of the detection — hard-coded the
 *         ignored-file list to `[]`, leaving the tracked-file count real —
 *         WITH one of the twelve real copyrighted files staged. Before the
 *         Step 0 positive control existed this printed "OK: no tracked file
 *         contradicts a .gitignore rule" and exited 0, i.e. a completely
 *         vacuous pass over a live violation. With Step 0 in place the same
 *         mutation goes RED at the primitive. Restored -> GREEN.
 *
 * Exit 0 = every tracked file is clean and within size, 1 = a violation
 * (or the detection itself looks broken).
 */

import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const REPO = path.join(__dirname, '..');

/* A tracked file below this many bytes never trips the size check.
   50 MB — see the "SIZE THRESHOLD" doc-comment above for why. */
const MAX_TRACKED_BYTES = 50 * 1024 * 1024;

/* Below this many tracked files, treat the whole run as suspicious rather
   than trusting either check's "found nothing wrong" result (rule #34 —
   a passing guard that examined nothing is the failure this file exists to
   prevent). This repository currently tracks ~2,800 files; 500 is a floor
   comfortably below that but nowhere near zero/broken. */
const MIN_EXPECTED_TRACKED_FILES = 500;

let failures = 0;
function check(name, cond, detail) {
    if (cond) { console.log('  ✓ ' + name); return; }
    failures++;
    console.error('  ✗ ' + name + (detail ? '\n' + detail : ''));
}

/** Run a git subcommand in the repo root and return its result. Throws
 *  (not just fails a check) if git itself could not even be spawned — a
 *  missing/broken git is not "no problems found", it's a broken test. */
function git(args, input) {
    const r = spawnSync('git', args, { cwd: REPO, encoding: 'utf8', input });
    if (r.error) {
        throw new Error(`could not run "git ${args.join(' ')}": ${r.error.message}`);
    }
    return r;
}

/** Split a NUL-separated (`-z`) git output string into an array, dropping
 *  the empty trailing element the final terminating NUL leaves behind. */
function splitNul(s) {
    if (!s) return [];
    const parts = s.split('\0');
    if (parts.length && parts[parts.length - 1] === '') parts.pop();
    return parts;
}

/** THE detection, in one place: ask git which files it is currently
 *  following that its own exclude configuration also matches.
 *
 *  ELI5: this is the single question the whole test is built on. It lives
 *  in one function with TWO callers on purpose — the real repository, where
 *  the right answer is "none", and a throwaway repository built in Step 0
 *  where the right answer is a specific known file. Because both callers go
 *  through this same code, the throwaway one acts as a live proof that the
 *  question is still being asked properly. If this stops working, Step 0
 *  goes red immediately, instead of the real-repository call quietly
 *  returning "none" and being mistaken for good news.
 *
 *  `-i`/`--ignored` with `-c`/`--cached` and `--exclude-standard`
 *  (.gitignore + .git/info/exclude + the global excludes file — the same
 *  set `git add` itself consults) is git answering this directly; a file a
 *  later `!` rule deliberately un-excludes is correctly absent already,
 *  because git resolved the negation itself.
 *  https://git-scm.com/docs/git-ls-files
 */
function lsTrackedButIgnored(cwd, env) {
    const opts = { cwd, encoding: 'utf8' };
    if (env) opts.env = env;
    const r = spawnSync('git', ['ls-files', '-c', '-i', '--exclude-standard', '-z'], opts);
    if (r.error) {
        throw new Error(`could not run "git ls-files -c -i --exclude-standard" in ${cwd}: ${r.error.message}`);
    }
    return { status: r.status, stderr: r.stderr, found: splitNul(r.stdout) };
}

console.log('tests/test-no-tracked-scratch.js — nothing tracked should be something .gitignore forbids:');

/* ---------------------------------------------------------------------
 * Step 0 — POSITIVE CONTROL: prove the detection actually still works,
 * before trusting a single thing it reports.
 *
 * ELI5: the main check below asks git one question — "which files are you
 * following that your own ignore rules say you should not be?" — and it
 * treats an empty answer as good news. But an empty answer is ALSO exactly
 * what you get if the question itself has stopped working. From the
 * outside those two look identical, and one of them is a broken test that
 * will wave the next copyrighted file straight through while printing a
 * tick.
 *
 * Detail: the "realistic number of tracked files" floor further down does
 * NOT cover this. That floor only proves `git ls-files` returned a real
 * list, which is what the SIZE check consumes; it says nothing about
 * whether `ls-files -c -i` is still correctly answering the ignored
 * question. Verified by mutation: blanking the ignored-list result while
 * leaving the file count real makes this whole file report "OK: no tracked
 * file contradicts a .gitignore rule" and exit 0 — even with one of the
 * twelve real copyrighted files staged. These flags have also genuinely
 * moved before: git 2.32 changed `git ls-files -i` to require an explicit
 * `-c`/`-o` (https://git-scm.com/docs/git-ls-files), so "the command still
 * behaves the way it did when this was written" is an assumption with a
 * counter-example, not a safe bet.
 *
 * So rather than assume, this builds a throwaway repository in a temporary
 * folder containing one file that IS tracked-but-ignored and one perfectly
 * ordinary file, runs the very same command against it, and requires it to
 * pick out the first and leave the second alone. If a future git stops
 * reporting that, the failure lands HERE, at the primitive, instead of
 * being disguised as a clean tree that was never actually examined. The
 * temporary folder is removed either way.
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

    const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'ihymns-ignore-probe-'));
    try {
        const run = (args) => spawnSync('git', args, { cwd: dir, encoding: 'utf8', env });
        run(['init', '-q']);
        fs.writeFileSync(path.join(dir, '.gitignore'), 'ignored-probe.bin\n');
        fs.writeFileSync(path.join(dir, 'ignored-probe.bin'), 'x');
        fs.writeFileSync(path.join(dir, 'ordinary-probe.txt'), 'x');
        /* `-f` on purpose: forcing an ignored file into the index is
           precisely the mistake this whole test exists to catch, so the
           probe has to recreate it deliberately to have something to find. */
        run(['add', '-f', '.gitignore', 'ignored-probe.bin', 'ordinary-probe.txt']);
        /* Deliberately the SAME function the real repository is checked
           with below — that shared path is what makes this a live proof
           rather than a separate, parallel implementation that could drift
           away from the thing it is supposed to be vouching for. */
        return lsTrackedButIgnored(dir, env);
    } finally {
        fs.rmSync(dir, { recursive: true, force: true });
    }
}

const probe = probeDetectionPrimitive();
check(
    'detection works: "git ls-files -c -i --exclude-standard" still finds a known tracked-but-ignored file',
    probe.status === 0 && probe.found.includes('ignored-probe.bin'),
    `    Ran it against a throwaway repo holding one deliberately ignored-but-tracked file and got\n` +
    `    exit ${probe.status}, files [${probe.found.join(', ')}]${(probe.stderr || '').trim() ? ' (stderr: ' + probe.stderr.trim() + ')' : ''}.\n` +
    '    It should have reported "ignored-probe.bin". Until it does, the main check below cannot tell\n' +
    '    "nothing is wrong" apart from "the question stopped working", so a tick from it means nothing.'
);
check(
    'detection is not over-broad: it leaves an ordinary tracked file alone',
    !probe.found.includes('ordinary-probe.txt'),
    '    It also flagged "ordinary-probe.txt", which no ignore rule matches — the command is reporting\n' +
    '    more than "tracked but ignored", so anything it reports below may be noise rather than a real\n' +
    '    problem.'
);

/* ---------------------------------------------------------------------
 * Step 1 — every currently tracked file, straight from git's index.
 * ------------------------------------------------------------------- */
const lsFiles = git(['ls-files', '-z']);
assert.equal(lsFiles.status, 0, `"git ls-files -z" exited ${lsFiles.status}, stderr: ${lsFiles.stderr}`);
const trackedFiles = splitNul(lsFiles.stdout);

check(
    `git ls-files found a realistic number of tracked files (>= ${MIN_EXPECTED_TRACKED_FILES})`,
    trackedFiles.length >= MIN_EXPECTED_TRACKED_FILES,
    `    found ${trackedFiles.length} — a count this low almost certainly means the git command ran\n` +
    '    against the wrong directory, an empty checkout, or failed silently, NOT that the\n' +
    '    repository genuinely shrank. Both checks below are meaningless until this is real.'
);
console.log(`  (checked ${trackedFiles.length} tracked files)`);

/* ---------------------------------------------------------------------
 * Step 2 — which of those does git's OWN exclude configuration also
 * match? `-i`/`--ignored` combined with `-c`/`--cached` (the default,
 * named explicitly here) and `--exclude-standard` (.gitignore +
 * .git/info/exclude + the global excludes file — the same set `git add`
 * itself consults) is git answering this directly; a file a later `!`
 * rule deliberately un-excludes is correctly absent from this list
 * already, because git resolved the negation itself. See the MECHANISM
 * doc-comment above for how this was verified, not just assumed.
 * ------------------------------------------------------------------- */
const lsIgnored = lsTrackedButIgnored(REPO);
assert.equal(lsIgnored.status, 0, `"git ls-files -c -i --exclude-standard -z" exited ${lsIgnored.status}, stderr: ${lsIgnored.stderr}`);
const trackedAndIgnored = lsIgnored.found;

if (trackedAndIgnored.length === 0) {
    check('every tracked file is free of a matching .gitignore rule (deliberate negations aside)', true);
} else {
    /* Get the exact matching rule for each, for an actionable message.
       --no-index is load-bearing here — see the MECHANISM doc-comment:
       without it, an already-tracked file is reported "not ignored"
       regardless of any matching rule, which would silently hide the
       exact contradiction this test exists to catch. */
    const ciResult = git(
        ['check-ignore', '-v', '-z', '--no-index', '--stdin'],
        trackedAndIgnored.map((p) => p + '\0').join('')
    );
    /* check-ignore exits 0 when at least one fed path is excluded, 1 when
       none are. Every path here came from ls-files -i, so at least one
       excluded path is guaranteed — anything else means the two git
       commands disagree with each other, which is itself a bug worth
       throwing on rather than quietly reporting nothing. */
    assert.equal(ciResult.status, 0,
        `"git check-ignore -v -z --no-index --stdin" exited ${ciResult.status} for paths ls-files -i just ` +
        `reported as ignored — the two git views of "is this ignored" disagree, stderr: ${ciResult.stderr}`);

    const fields = splitNul(ciResult.stdout);
    assert.equal(fields.length % 4, 0,
        `"git check-ignore -v -z" output was not a multiple of 4 fields (got ${fields.length}) — ` +
        'its --stdin -v record shape (source\\0linenum\\0pattern\\0pathname\\0) may have changed.');

    const records = [];
    for (let i = 0; i < fields.length; i += 4) {
        records.push({ source: fields[i], lineNum: fields[i + 1], pattern: fields[i + 2], pathname: fields[i + 3] });
    }

    /* Defensive: every one of these paths was independently confirmed
       ignored (not negated) by ls-files -i. If check-ignore -v ever
       reports a `!` pattern for one of them, the two commands' views of
       "ignored" have come apart — treat that as a bug in THIS test's
       assumptions, not a clean result. */
    for (const rec of records) {
        assert.ok(!rec.pattern.startsWith('!'),
            `"${rec.pathname}" was reported genuinely ignored by ls-files -i, but check-ignore -v's matching ` +
            `pattern "${rec.pattern}" is a negation — the two views disagree, which the MECHANISM doc-comment ` +
            'says should never happen.');
    }

    failures++;
    console.error(`  ✗ ${records.length} tracked file(s) contradict a .gitignore rule that should have kept them out:`);
    for (const rec of records) {
        console.error(`      ${rec.pathname}`);
        console.error(`          matches "${rec.pattern}" at ${rec.source}:${rec.lineNum}`);
    }
    console.error('    A .gitignore rule never untracks a file git is already following — adding the rule, or');
    console.error('    already having it, is not the fix. Remove each file from tracking with:');
    console.error('      git rm --cached <path>');
    console.error('    (this only stops git following it; the file itself stays on disk) and commit that removal.');
    console.error('    If a file above is tracked ON PURPOSE despite the rule (a placeholder, a vendored file),');
    console.error('    say so in .gitignore with a `!`-negation next to the rule it exempts — do not add it to');
    console.error('    this test as a typed exception.');
}

/* ---------------------------------------------------------------------
 * Step 3 — is any currently tracked file unusually large? A .gitignore
 * rule only stops a NEW oversized file being added; it does nothing for
 * one already tracked. Note this is a forward-looking guard, NOT a
 * re-statement of the incident: the twelve committed files totalled
 * 11.4 MB (largest 5.6 MB) and would all have passed this threshold
 * comfortably. The 82-813 MB files in the same folder were never
 * committed. See check 2 in the header comment for the full reasoning.
 * ------------------------------------------------------------------- */
const sized = [];
let unstatable = 0;
for (const p of trackedFiles) {
    try {
        sized.push({ path: p, size: fs.statSync(path.join(REPO, p)).size });
    } catch {
        /* A tracked path missing from disk (e.g. mid git-mv) isn't a size
           problem — count it so the sanity check below can still notice
           if this happens to nearly everything, which would mean the
           checkout itself is broken rather than one stray file. */
        unstatable++;
    }
}

check(
    'nearly every tracked path could be measured on disk (sanity: this is a real, populated checkout)',
    sized.length >= trackedFiles.length * 0.9,
    `    only ${sized.length} of ${trackedFiles.length} tracked paths exist on disk (${unstatable} missing) — ` +
    'that is too many to be normal churn; the checkout this ran against may be incomplete.'
);

const tooLarge = sized.filter((f) => f.size > MAX_TRACKED_BYTES).sort((a, b) => b.size - a.size);

if (tooLarge.length === 0) {
    check(`every tracked file is under the ${(MAX_TRACKED_BYTES / (1024 * 1024)).toFixed(0)} MB threshold`, true);
} else {
    failures++;
    const mb = (n) => (n / (1024 * 1024)).toFixed(1);
    console.error(`  ✗ ${tooLarge.length} tracked file(s) are over the ${mb(MAX_TRACKED_BYTES)} MB threshold ` +
        '(GitHub itself warns above this size and hard-rejects anything over 100 MB):');
    for (const f of tooLarge) {
        console.error(`      ${mb(f.size)} MB  ${f.path}`);
    }
    console.error('    A .gitignore rule does not shrink or untrack a file already this size. If it should');
    console.error('    never have been committed, remove it with `git rm --cached <path>` (plus a .gitignore');
    console.error('    rule so it is not re-added) and commit the removal. If it is genuinely meant to be');
    console.error('    this large, that is itself worth a second look before it reaches GitHub\'s own limits.');
}

/* For context on a failure, always show the largest tracked files overall
   — not just the ones over threshold — so a file that is CLOSE to the
   limit and about to become a problem is visible too ("List the largest
   tracked files when it fails"). */
if (failures > 0) {
    const top = [...sized].sort((a, b) => b.size - a.size).slice(0, 10);
    console.error('\n  Largest tracked files in this checkout, for context:');
    for (const f of top) {
        console.error(`      ${(f.size / (1024 * 1024)).toFixed(1)} MB  ${f.path}`);
    }
}

if (failures) {
    console.error(`\nFAIL: ${failures} check(s) failed.`);
    process.exit(1);
}
console.log('\nOK: no tracked file contradicts a .gitignore rule, and no tracked file is unusually large.');
