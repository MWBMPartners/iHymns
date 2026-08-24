/**
 * iHymns — ESLint coverage guard for the /manage/** tree (#1874)
 *
 * ELI5
 * ----
 * We have a set of "catch real bugs" ESLint rules. They only run on the files
 * ESLint's config actually points at. For a long time the config pointed ONLY
 * at the public `js/` tree, so the entire admin `manage/` tree — all of the
 * Editor2 v2 modules and the rebuilt metadata tab — was linted by NOTHING, and
 * nobody noticed because a file matching no config block is skipped in silence.
 * This test proves the rules really do reach every (non-vendored) manage file.
 *
 * WHY THIS EXISTS — the wrong-but-green class, applied to the linter itself
 * ------------------------------------------------------------------------
 * `eslint.config.js`'s own doc-block warns that a lint step that looks like a
 * gate but runs zero checks is worse than none, "because the tick reads as
 * coverage." The config then did exactly that to the manage tree: its single
 * `files` glob (`appWeb/public_html/js/**`) silently excluded ~23 real admin
 * modules. Running the same rules over the manage tree immediately surfaced a
 * genuine `no-undef` bug in metadata-tab.js (an out-of-scope `song`). #1874
 * extended the net; this guard makes sure it can never silently retract again.
 *
 * WHY IT IS DERIVED FROM THE TREE, NOT A TYPED LIST (rule #34)
 * -----------------------------------------------------------
 * Every hardcoded-list check in this repo has, at some point, passed while the
 * thing it named was broken elsewhere. So this does NOT hardcode the manage
 * file list: it WALKS `appWeb/public_html/manage/` for every `*.js`, asks the
 * live ESLint config (via the same resolution `eslint` itself uses) what rules
 * apply to each, and fails if any non-ignored file resolves to an empty rule
 * set or is missing `no-undef`. Add a new manage module tomorrow and it is
 * covered automatically; shrink the `files` glob back and every manage file
 * goes uncovered at once — that is the mutation this guard is built to catch.
 *
 * MUTATION PROOF (performed, rule #34)
 * ------------------------------------
 * Shrink the manage `files` glob in eslint.config.js back to the public tree
 * only → every walked manage file resolves to `rules: {}` → this test FAILS
 * (RED). Revert → GREEN. Verified 2026-08-18 before landing.
 *
 *   node tests/test-eslint-coverage.js
 *
 * Exit status 0 = all pass, 1 = at least one failure.
 */

import { readdirSync, statSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join, relative, sep } from 'node:path';
import { ESLint } from 'eslint';

const __dirname = dirname(fileURLToPath(import.meta.url));
const PROJECT_ROOT = join(__dirname, '..');
const MANAGE_DIR = join(PROJECT_ROOT, 'appWeb', 'public_html', 'manage');

/* Bug-catching rules that MUST be live on every covered manage file. `no-undef`
   is the sentinel: it is the rule that caught the #1874 metadata-tab bug, and a
   file matching no config block resolves with it absent (rules: {}). */
const REQUIRED_RULE = 'no-undef';

/* The two directories eslint.config.js deliberately ignores: vendored minified
   protobuf and generated proto output. Listed here as project-relative POSIX
   paths only to ASSERT they stay ignored (below) — they are NOT how the covered
   set is built; that comes from `isPathIgnored`, so if the config's ignore list
   changes this guard follows it rather than contradicting it. */
const EXPECTED_IGNORED_DIRS = [
    'appWeb/public_html/manage/editor/vendor',
    'appWeb/public_html/manage/editor/protos',
];

/** Recursively collect every `*.js` path under `dir` (absolute paths). */
function walkJs(dir) {
    const out = [];
    for (const entry of readdirSync(dir)) {
        const full = join(dir, entry);
        const st = statSync(full);
        if (st.isDirectory()) {
            out.push(...walkJs(full));
        } else if (st.isFile() && entry.endsWith('.js')) {
            out.push(full);
        }
    }
    return out;
}

/** project-relative POSIX path, for stable messages across platforms. */
function rel(p) {
    return relative(PROJECT_ROOT, p).split(sep).join('/');
}

const failures = [];
let coveredCount = 0;
let ignoredCount = 0;

/* One ESLint instance, resolving config exactly as the CLI does from the repo
   root — so this test cannot pass against a different config than CI runs. */
const eslint = new ESLint({ cwd: PROJECT_ROOT });

const allManageJs = walkJs(MANAGE_DIR);

/* Guard against a silent empty walk (rule #34: a scanner that under-reports is
   worse than no scanner). If the tree walk finds nothing, the whole test is
   meaningless — fail loudly rather than report a confident green over zero files. */
if (allManageJs.length === 0) {
    console.error(`✖ Walked ${rel(MANAGE_DIR)} and found ZERO .js files — the walk is broken, not the config.`);
    process.exit(1);
}

for (const file of allManageJs) {
    /* Skip whatever the CONFIG says to ignore — derived from the config, not a
       typed list, so vendored/generated files never count against coverage even
       if the ignore globs are later reshaped. */
    if (await eslint.isPathIgnored(file)) {
        ignoredCount++;
        continue;
    }
    const cfg = await eslint.calculateConfigForFile(file);
    const rule = cfg && cfg.rules ? cfg.rules[REQUIRED_RULE] : undefined;
    /* A covered file resolves `no-undef` to an array/string whose level is 2
       ("error") — e.g. [2, {...}]. An UNCOVERED file (matching no `files`
       block) resolves to an empty rules object, so `rule` is undefined. That is
       precisely the difference the mutation flips. */
    const level = Array.isArray(rule) ? rule[0] : rule;
    const isError = level === 2 || level === 'error';
    if (!isError) {
        failures.push(`${rel(file)} — ${REQUIRED_RULE} resolves to ${JSON.stringify(rule)} (expected "error"); file is outside the lint net`);
    } else {
        coveredCount++;
    }
}

/* The vendored/generated dirs must genuinely be ignored — assert it, so an
   accidental removal of the ignore globs (which would flood CI with hundreds of
   minified-bundle errors) is caught here as a clear message rather than as a
   wall of noise in the lint step. Each expected-ignored dir is checked via a
   representative file discovered in the walk (if the dir has no .js, it is
   simply absent and there is nothing to assert). */
for (const relDir of EXPECTED_IGNORED_DIRS) {
    const abs = join(PROJECT_ROOT, relDir);
    let sample = null;
    try {
        sample = walkJs(abs)[0] || null;
    } catch { /* dir may not exist on a given checkout — nothing to assert */ }
    if (sample) {
        const ignored = await eslint.isPathIgnored(sample);
        if (!ignored) {
            failures.push(`${relDir} is expected to be IGNORED by eslint.config.js but ${rel(sample)} is not — vendored/generated JS must not be linted`);
        }
    }
}

console.log(`ESLint coverage: ${coveredCount} manage/*.js file(s) covered, ${ignoredCount} ignored (vendored/generated).`);

if (failures.length > 0) {
    console.error('');
    console.error('✖ ESLint coverage guard FAILED:');
    for (const f of failures) { console.error('  - ' + f); }
    console.error('');
    console.error('The /manage/** tree must be inside the ESLint `files` net (eslint.config.js). See #1874.');
    process.exit(1);
}

console.log('✓ Every non-vendored /manage/**/*.js file is inside the ESLint net.');
process.exit(0);
