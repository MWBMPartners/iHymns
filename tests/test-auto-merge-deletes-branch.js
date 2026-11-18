/**
 * tests/test-auto-merge-deletes-branch.js — a merged pull request's branch really gets deleted
 *
 * PURPOSE
 * ELI5: when a pull request into `alpha` merges, its branch should be deleted so
 * old branches do not pile up. GitHub's own "Automatically delete head branches"
 * setting never did this for our merges (auto-merge is switched on by a workflow,
 * so the merge is done by github-actions[bot]; across 38 merged pull requests not
 * one branch was deleted by the setting — #2173). So `auto-merge-alpha.yml` now
 * deletes the branch itself. This test actually RUNS that shell function against
 * a pretend `gh`, so it proves what the function does, not just what it says.
 *
 * DETAIL
 * The function `delete_merged_branch` is cut out of the workflow file (so the test
 * always checks the real text, never a copy) and run under bash with a stub `gh`
 * that records every call. Cases:
 *   1. a normal same-repository branch      -> exactly one DELETE of that ref;
 *   2. long-lived names (alpha, beta, main, release-candidate-x, archive/x,
 *      backup/x, future/x)                   -> NO delete;
 *   3. a branch from a fork                  -> NO delete;
 *   4. `gh pr view` fails                    -> NO delete, and the function still
 *      returns success (a failure here must never stop the deploy dispatch);
 *   5. the DELETE itself fails               -> still returns success (best effort).
 * Plus structure checks: the function is CALLED in the MERGED branch before the
 * deploy dispatch, and the job has `contents: write` (deleting a ref needs it).
 *
 * Mutation-proven (rule #34): remove the protected-name check, the fork check, or
 * the call, and this goes red; the current file is green.
 *
 *   node tests/test-auto-merge-deletes-branch.js
 *
 * @see .github/workflows/auto-merge-alpha.yml
 * @see https://docs.github.com/en/rest/git/refs#delete-a-reference
 * @see #2173
 */

import { execFileSync, spawnSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const WORKFLOW = path.join(__dirname, '..', '.github', 'workflows', 'auto-merge-alpha.yml');

let failures = 0;
function check(name, cond, detail) {
    if (cond) { console.log('  ✓ ' + name); return; }
    failures++;
    console.error('  ✗ ' + name + (detail ? '\n      ' + detail : ''));
}

const src = fs.readFileSync(WORKFLOW, 'utf8');

/* Cut the function out: from `delete_merged_branch() {` to its closing brace, which
   is the first line that is exactly the same indentation followed by `}`. */
const lines = src.split('\n');
const start = lines.findIndex((l) => /^\s*delete_merged_branch\(\)\s*\{\s*$/.test(l));
check('the workflow defines delete_merged_branch()', start >= 0);
let fn = '';
if (start >= 0) {
    const indent = lines[start].match(/^\s*/)[0];
    let end = -1;
    for (let i = start + 1; i < lines.length; i++) {
        if (lines[i] === indent + '}') { end = i; break; }
    }
    check('the function has a closing brace', end > start);
    if (end > start) fn = lines.slice(start, end + 1).map((l) => l.slice(indent.length)).join('\n');
}

/* Run the function with a stub `gh`. `viewReply` is what `gh pr view` prints (or null
   to make it fail); `deleteFails` makes the DELETE call fail. Returns what happened. */
function run({ viewReply, deleteFails = false }) {
    const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'ghstub-'));
    const log = path.join(dir, 'calls.log');
    const stub = path.join(dir, 'gh');
    fs.writeFileSync(stub, [
        '#!/bin/bash',
        'echo "$*" >> "' + log + '"',
        'if [[ "$1" == "pr" && "$2" == "view" ]]; then',
        viewReply === null ? '  echo "network blip" >&2; exit 1' : '  echo "' + viewReply + '"; exit 0',
        'fi',
        'if [[ "$1" == "api" ]]; then',
        deleteFails ? '  exit 1' : '  exit 0',
        'fi',
        'exit 0',
    ].join('\n'), { mode: 0o755 });
    const script = fn + '\ndelete_merged_branch\necho "exit=$?"\n';
    const r = spawnSync('bash', ['-c', script], {
        env: { ...process.env, PATH: dir + ':' + process.env.PATH, PR_NUMBER: '7', REPO: 'o/r' },
        encoding: 'utf8',
    });
    const calls = fs.existsSync(log) ? fs.readFileSync(log, 'utf8').split('\n').filter(Boolean) : [];
    fs.rmSync(dir, { recursive: true, force: true });
    return { out: r.stdout + r.stderr, calls, deletes: calls.filter((c) => c.startsWith('api -X DELETE')) };
}

if (fn) {
    console.log('Running the real function against a pretend `gh`:');
    const ok = run({ viewReply: 'claude/some-work false' });
    check('a normal same-repository branch is deleted, exactly once',
        ok.deletes.length === 1 && ok.deletes[0] === 'api -X DELETE repos/o/r/git/refs/heads/claude/some-work',
        JSON.stringify(ok.calls));
    check('...and the function reports success', /exit=0/.test(ok.out));

    for (const name of ['alpha', 'beta', 'main', 'master', 'release-candidate-1', 'archive/alpha', 'backup/x', 'future/y']) {
        const r = run({ viewReply: name + ' false' });
        check('long-lived branch "' + name + '" is NEVER deleted', r.deletes.length === 0, JSON.stringify(r.calls));
    }
    /* Look-alikes that must still be deleted: names that merely START with a protected word. */
    for (const name of ['alpha-fix', 'main-menu-fix', 'beta/feature', 'dependabot/npm_and_yarn/alpha/x']) {
        const r = run({ viewReply: name + ' false' });
        check('look-alike "' + name + '" IS deleted (only exact long-lived names are protected)',
            r.deletes.length === 1, JSON.stringify(r.calls));
    }

    const fork = run({ viewReply: 'someone-elses-branch true' });
    check('a branch from a fork is never deleted', fork.deletes.length === 0, JSON.stringify(fork.calls));

    const blip = run({ viewReply: null });
    check('when `gh pr view` fails, nothing is deleted', blip.deletes.length === 0);
    check('...and the function still reports success (never stops the deploy dispatch)', /exit=0/.test(blip.out));

    const delFail = run({ viewReply: 'claude/x false', deleteFails: true });
    check('when the DELETE fails, the function still reports success', /exit=0/.test(delFail.out));
}

console.log('Where it is called:');
const merged = src.indexOf('MERGED)');
const call = src.indexOf('delete_merged_branch\n', merged);
const dispatch = src.indexOf('gh workflow run deploy.yml', merged);
check('the function is called in the MERGED case', merged >= 0 && call > merged);
check('...before the deploy is dispatched (and its failure cannot block it)', call > 0 && dispatch > call);
check('the workflow has `contents: write` (deleting a branch needs it)', /permissions:[\s\S]*?contents:\s*write/.test(src));

if (failures > 0) {
    console.error('\n' + failures + ' check(s) failed.');
    process.exit(1);
}
console.log('\nAll checks passed.');
