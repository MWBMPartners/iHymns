/**
 * tests/test-org-logo-resolver-lockstep.js — org-logo themed-resolver
 * PHP<->JS ALGORITHM lockstep guard (SHOULD-harness §4 item 7, 2026-08-30
 * correctness review).
 *
 * ELI5
 * ----
 * Three screens (header, projector, share-card) each ask "given the logos
 * this church has actually uploaded, which ONE picture fits THIS screen in
 * THIS theme?" — and there are two separate implementations of the answer,
 * one in PHP (`ihymnsOrgLogoResolveThemedAsset()`, used server-side by the
 * projector and share-card) and one in JS (`resolveThemedAsset()`, used
 * client-side by the header). Both files' own doc-blocks say they are a
 * "byte-for-byte port" of each other. This file is what actually PROVES
 * that, by feeding the REAL, unmodified copy of both functions the exact
 * SAME `{available, surface, theme}` inputs and asserting they return
 * byte-identical `{kind,variant}|null` answers.
 *
 * WHY THIS GAP WASN'T ALREADY CLOSED
 * ------------------------------------
 * `tests/php/test-org-logo-themed-resolver.php` truth-tables the PHP
 * function alone, against cases hand-derived from the design plan.
 * `tests/php/test-org-logo-surfaces.php` check (g) compares the two files'
 * `SURFACE_PREFS` DATA TABLE (the kind lists + darkCapableOnly flags) —
 * but never calls EITHER resolver function. So today: the PHP function is
 * checked against a human's expectation, and the two files' input DATA is
 * checked for lockstep — but the two ALGORITHMS themselves are never run
 * side-by-side. If the JS twin's loop order, its `darkCapableOnly &&
 * kind !== 'reversed'` skip, or its two-step (exact-theme-then-default)
 * sequencing ever drifted from the PHP original — while the SURFACE_PREFS
 * data tables stayed byte-identical — every existing guard would stay
 * green while the header (client-resolved) and projector/share-card
 * (server-resolved) surfaces silently showed DIFFERENT logos for the same
 * org. This is the rule-#35 mechanism that closes that gap.
 *
 * HOW: this is a Node file, but the PHP half of the comparison runs the
 * REAL `includes/org_logo_helpers.php` unmodified, via a single batched
 * `php -r` subprocess call — the whole matrix is sent once as JSON on
 * stdin and the whole set of answers comes back once as JSON on stdout, so
 * a large matrix costs exactly one process spawn, not N.
 *
 * MUTATION-TESTING PROTOCOL (rule #34)
 * -------------------------------------
 * Proven able to fail by re-running the SAME PHP-side batch call against a
 * MUTATED SIBLING COPY of org_logo_helpers.php (written into the same
 * `includes/` directory as a throwaway `zz_ihymns_test_*` file — since the
 * real file has no relative requires this needs no other adjustment — and
 * always deleted in a `finally`; the tracked file is never touched) with
 * the `darkCapableOnly` skip condition inverted, and confirming the
 * PHP/JS comparison for an og-card case then disagrees.
 *
 * @see appWeb/public_html/includes/org_logo_helpers.php   ihymnsOrgLogoResolveThemedAsset() — the PHP original
 * @see appWeb/public_html/js/modules/org-logo.js           resolveThemedAsset() — the JS twin
 * @see tests/php/test-org-logo-themed-resolver.php         the PHP-only truth table (kept, not duplicated here)
 * @see tests/php/test-org-logo-surfaces.php                 check (g), the DATA-table lockstep (kept, not duplicated here)
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { spawnSync } from 'node:child_process';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const REPO_ROOT = path.join(__dirname, '..');
const PHP_HELPERS_PATH = path.join(REPO_ROOT, 'appWeb', 'public_html', 'includes', 'org_logo_helpers.php');
const JS_MODULE_PATH = path.join(REPO_ROOT, 'appWeb', 'public_html', 'js', 'modules', 'org-logo.js');

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

/** A representative matrix — NOT an exhaustive re-derivation of the plan
 *  (tests/php/test-org-logo-themed-resolver.php already owns that); this
 *  matrix's job is to exercise every DISTINCT branch the shared algorithm
 *  takes (exact-theme match, default fallback, darkCapableOnly skip, the
 *  darkCapableOnly 'reversed' exemption, ladder-order-beats-exactness,
 *  unknown surface, empty input, a garbage theme string) so a divergence in
 *  ANY branch is caught, not just the branches the plan happened to write
 *  worked examples for. */
const CASES = [
    { label: 'header/light: exact emblem|light match',
        available: [{ kind: 'emblem', variant: 'light' }], surface: 'header', theme: 'light' },
    { label: 'header/dark: no exact match, falls to emblem|default',
        available: [{ kind: 'emblem', variant: 'default' }], surface: 'header', theme: 'dark' },
    { label: 'header/dark: no emblem at all, ladder falls through to favicon|dark',
        available: [{ kind: 'favicon', variant: 'dark' }], surface: 'header', theme: 'dark' },
    { label: 'header/light: ladder order beats exactness (emblem default wins over favicon exact)',
        available: [{ kind: 'favicon', variant: 'light' }, { kind: 'emblem', variant: 'default' }], surface: 'header', theme: 'light' },
    { label: 'projector/dark: reversed|default matches (darkCapableOnly=false, default always allowed)',
        available: [{ kind: 'reversed', variant: 'default' }], surface: 'projector', theme: 'dark' },
    { label: 'projector/light: nothing available -> null',
        available: [], surface: 'projector', theme: 'light' },
    { label: 'og-card/light (darkCapableOnly): emblem|default is SKIPPED (only reversed is exempt) -> null',
        available: [{ kind: 'emblem', variant: 'default' }], surface: 'og-card', theme: 'light' },
    { label: 'og-card/dark (darkCapableOnly): reversed|default IS allowed (the one exemption)',
        available: [{ kind: 'reversed', variant: 'default' }], surface: 'og-card', theme: 'dark' },
    { label: 'og-card/dark: reversed|dark exact match beats reversed|default',
        available: [{ kind: 'reversed', variant: 'dark' }, { kind: 'reversed', variant: 'default' }], surface: 'og-card', theme: 'dark' },
    { label: 'unknown surface -> null, never throws',
        available: [{ kind: 'emblem', variant: 'light' }], surface: 'banner-nobody-defined', theme: 'light' },
    { label: 'garbage theme string is treated as light',
        available: [{ kind: 'emblem', variant: 'light' }], surface: 'header', theme: 'sepia' },
    { label: 'a row missing variant is ignored, not a crash',
        available: [{ kind: 'emblem' }, { kind: 'favicon', variant: 'light' }], surface: 'header', theme: 'light' },
];

/** Run the REAL org_logo_helpers.php (or a mutated sibling of it) against
 *  every case in one batched `php -r` call — never DB-coupled, since
 *  ihymnsOrgLogoResolveThemedAsset() is pure per its own doc-block.
 *  @param {string} phpFilePath absolute path to the PHP file to require.
 *  @returns {Array<{kind:string,variant:string}|null>} one answer per case, in order. */
function runPhpBatch(phpFilePath) {
    const script =
        'require ' + JSON.stringify(phpFilePath) + ';' +
        '$cases = json_decode(file_get_contents("php://stdin"), true);' +
        '$out = [];' +
        'foreach ($cases as $c) {' +
        '  $out[] = ihymnsOrgLogoResolveThemedAsset($c["available"], $c["surface"], $c["theme"]);' +
        '}' +
        'echo json_encode($out);';
    const result = spawnSync('php', ['-r', script], {
        input: JSON.stringify(CASES.map((c) => ({ available: c.available, surface: c.surface, theme: c.theme }))),
        encoding: 'utf8',
    });
    if (result.status !== 0) {
        throw new Error('PHP batch call failed (exit ' + result.status + '): ' + result.stderr);
    }
    return JSON.parse(result.stdout);
}

/** Normalise a resolver's return value for comparison: PHP's json_encode of
 *  an associative array and JS's own object literal both compare fine via
 *  JSON.stringify as long as key order matches — both resolvers build the
 *  object in the SAME `{kind, variant}` order, so this is a safe, exact
 *  comparison rather than a key-order-blind deep-equal reimplementation. */
function j(value) {
    return JSON.stringify(value);
}

/** Write a throwaway MUTATED SIBLING of org_logo_helpers.php with the
 *  darkCapableOnly skip condition INVERTED, into the same includes/
 *  directory (the real file has no relative requires, so no other path
 *  adjustment is needed) — the tracked file is never touched, and the
 *  mutant is deleted by the caller.
 *  @returns {string} absolute path to the mutant file. */
function writeMutantHelpers() {
    const realSrc = fs.readFileSync(PHP_HELPERS_PATH, 'utf8');
    const needle = "if (\$prefs['darkCapableOnly'] && \$kind !== 'reversed') {";
    const mutated = "if (\$prefs['darkCapableOnly'] && \$kind === 'reversed') { // MUTATED: skip condition inverted";
    if (!realSrc.includes(needle)) {
        throw new Error('MUTATION setup sanity failed: the darkCapableOnly skip line was not found in real source — the file changed shape, update this test.');
    }
    const mutatedSrc = realSrc.replace(needle, mutated);
    const mutantPath = path.join(path.dirname(PHP_HELPERS_PATH), `zz_ihymns_test_org_logo_helpers_mutant_${Date.now()}_${Math.random().toString(16).slice(2)}.php`);
    fs.writeFileSync(mutantPath, mutatedSrc);
    return { mutantPath, changed: mutatedSrc !== realSrc };
}

async function main() {
    console.log('\nOrg-logo themed-resolver PHP<->JS ALGORITHM lockstep guard\n');

    const { resolveThemedAsset } = await import(pathToFileURL(JS_MODULE_PATH).href);

    const jsResults = CASES.map((c) => resolveThemedAsset(c.available, c.surface, c.theme));
    const phpResults = runPhpBatch(PHP_HELPERS_PATH);

    assert(phpResults.length === CASES.length, `PHP batch returned one answer per case (${phpResults.length} of ${CASES.length})`);

    CASES.forEach((c, i) => {
        assert(j(phpResults[i]) === j(jsResults[i]),
            `${c.label} — PHP and JS agree (PHP=${j(phpResults[i])}, JS=${j(jsResults[i])})`);
    });

    console.log('\n--- MUTATION PROOF: an algorithm divergence (not just a data-table drift) is caught ---');
    let mutantPath = null;
    try {
        const mutant = writeMutantHelpers();
        mutantPath = mutant.mutantPath;
        assert(mutant.changed, 'MUTATION setup sanity: the darkCapableOnly skip replacement actually matched real source');

        const mutantResults = runPhpBatch(mutantPath);
        // The og-card/light case (emblem|default, non-reversed kind) is the
        // one this specific mutation flips: real PHP returns null (skipped);
        // the mutant now WRONGLY resolves it because the skip fires only for
        // 'reversed' instead of every OTHER kind.
        const idx = CASES.findIndex((c) => c.label.startsWith('og-card/light'));
        assert(j(mutantResults[idx]) !== j(jsResults[idx]),
            `MUTATION PROOF: inverting the darkCapableOnly skip makes the og-card/light case disagree with the (unmutated) JS twin (mutant PHP=${j(mutantResults[idx])}, JS=${j(jsResults[idx])})`);
    } finally {
        if (mutantPath) {
            fs.rmSync(mutantPath, { force: true });
        }
    }

    console.log(`\n=== ${checks} checks, ${failures} failed ===`);
    process.exit(failures === 0 ? 0 : 1);
}

main().catch((err) => {
    console.error('HARNESS CRASHED:', err);
    process.exit(1);
});
