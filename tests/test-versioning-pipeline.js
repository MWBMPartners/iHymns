/**
 * tests/test-versioning-pipeline.js — tag-derived version pipeline guard (#1899)
 *
 * PURPOSE
 * ELI5: the pieces that turn a production tag into the version number the site
 * shows are spread across three workflow files and one PHP file. If any two
 * silently disagree — a sed anchor renamed, a tag-filter regex tweaked in one
 * file but not the other, a dispatch trigger dropped — the deploy still goes
 * GREEN and prod just shows a stale or NULL version. That is the worst failure
 * class this repo produces (rule #34/#35). This guard reads the REAL files and
 * asserts they agree, so a silent disagreement goes red in CI instead.
 *
 * DETAIL — every assertion is DERIVED from the tree (no typed lists) and each
 * is mutation-proven (break the thing, watch it go red — see the foot):
 *   1. Anchor pairing — every `sed ... = NULL` anchor deploy.yml injects has a
 *      matching `... = NULL` line in infoAppVer.php (also covers PR1's SHA/date
 *      /build seds — an anchor rename silently no-ops the injection).
 *   2. Version-sed pairing — deploy.yml has a sed writing Version.Number, and
 *      the COMMITTED value is three plain integers ("X.Y.Z"), the contract
 *      sync-version.sh's regex and the sed both rely on.
 *   3. Tag-filter agreement — the `^v[0-9]+\.[0-9]+\.[0-9]+$` filter is present
 *      in BOTH promotion-deploy-bridge.yml and deploy.yml and byte-identical
 *      (two files, one contract — the test IS the mechanism, rule #35).
 *   4. release.yml dispatchability — its `on:` declares workflow_dispatch (else
 *      the bridge's `gh workflow run` 422s and no GitHub Release is created).
 *   5. Bridge step order — "Cut release tag" appears BEFORE the deploy dispatch
 *      (else the release deploy injects the PREVIOUS release — off-by-one).
 *   6. fetch-tags — deploy.yml's checkout asks for tags (the injection can't
 *      parse a tag it never fetched).
 *   7. Retirement holds — version-bump.yml is gone, and no workflow file except
 *      deploy.yml writes Version.Number with sed.
 *
 *   node tests/test-versioning-pipeline.js
 *
 * Exit 0 = pipeline agrees, 1 = drift.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const REPO = path.join(__dirname, '..');
const WF = path.join(REPO, '.github', 'workflows');
const INFO = path.join(REPO, 'appWeb', 'public_html', 'includes', 'infoAppVer.php');

let failures = 0;
function check(name, cond, detail) {
    if (cond) { console.log('  ✓ ' + name); return; }
    failures++;
    console.error('  ✗ ' + name + (detail ? '\n      ' + detail : ''));
}

const read = (p) => fs.existsSync(p) ? fs.readFileSync(p, 'utf8') : '';

const deployYml = read(path.join(WF, 'deploy.yml'));
const bridgeYml = read(path.join(WF, 'promotion-deploy-bridge.yml'));
const releaseYml = read(path.join(WF, 'release.yml'));
const infoSrc = read(INFO);

console.log('Tag-derived version pipeline:');

check('deploy.yml exists', deployYml !== '');
check('promotion-deploy-bridge.yml exists', bridgeYml !== '');
check('release.yml exists', releaseYml !== '');
check('infoAppVer.php exists', infoSrc !== '');

/* Turn sed-regex/shell escaping (`\[`, `\"`, `\]`, …) into the literal text the
   anchor actually is, so we compare against the real PHP source. */
const deEscape = (s) => s.replace(/\\/g, '');

/* Isolate a named workflow step's body (from its `- name:` to the next one). */
function stepBody(src, nameSubstr) {
    const lines = src.split('\n');
    const start = lines.findIndex((l) => /^\s*- name:/.test(l) && l.includes(nameSubstr));
    if (start < 0) { return ''; }
    let end = lines.length;
    for (let i = start + 1; i < lines.length; i++) {
        if (/^\s*- name:/.test(lines[i])) { end = i; break; }
    }
    return lines.slice(start, end).join('\n');
}

/* ---- 1. Anchor pairing (derived from deploy.yml's inject step) ----------- */
const injectStep = stepBody(deployYml, 'Inject build info into infoAppVer.php');
check('found the "Inject build info" step in deploy.yml', injectStep !== '');

/* Every `sed -i "s|LHS|...` whose LHS ends with `= NULL`. Non-greedy up to the
   first `|` (no NULL anchor contains a literal pipe). */
const nullAnchors = [];
{
    const re = /sed\s+-i\s+"s\|(.+?)\|/g;
    let m;
    while ((m = re.exec(injectStep)) !== null) {
        const lhs = m[1];
        if (/=\s*NULL\s*$/.test(lhs)) { nullAnchors.push(deEscape(lhs)); }
    }
}
check('deploy.yml inject step has NULL-anchor seds (>=5: 4 commit fields + build)',
    nullAnchors.length >= 5, `found ${nullAnchors.length}`);
for (const anchor of nullAnchors) {
    // The anchor is a substring of the full PHP line (e.g. `...["Repo"]["Commit"]
    // ["SHA"]["Full"] = NULL;`), so a substring match is correct.
    check(`infoAppVer.php carries anchor: ${anchor}`, infoSrc.includes(anchor),
        'PR1/PR2 injection sed would silently no-op — the anchor line was renamed');
}

/* ---- 2. Version-sed pairing --------------------------------------------- */
// deploy.yml must contain a sed writing Version.Number. `\bsed\b` (word
// boundary), NOT a bare "sed" substring — the latter matches inside
// "Unreleased" (…ea-sed) and other words.
const deployHasVersionSed = deployYml
    .split('\n')
    .some((l) => /\bsed\b/.test(l) && deEscape(l).includes('["Version"]["Number"]') && deEscape(l).includes('= '));
check('deploy.yml has a sed writing ["Version"]["Number"]', deployHasVersionSed);

// The committed value must be three plain integers "X.Y.Z".
const vm = infoSrc.match(/\["Version"\]\["Number"\]\s*=\s*"([^"]+)"/);
const committed = vm ? vm[1] : null;
check('infoAppVer.php Version.Number is parseable', committed !== null);
check(`committed Version.Number is three plain integers (got "${committed}")`,
    committed !== null && /^\d+\.\d+\.\d+$/.test(committed),
    'sync-version.sh\'s regex + the deploy sed both require plain X.Y.Z (no suffix)');

/* ---- 3. Tag-filter agreement -------------------------------------------- */
// Extract every `grep -E '<pattern>'` whose pattern is a version-tag filter
// (starts with ^v). Compare the deduped set across the two files byte-for-byte.
function tagFilters(src) {
    const out = new Set();
    const re = /grep\s+-E\s+'([^']*)'/g;
    let m;
    while ((m = re.exec(src)) !== null) {
        if (m[1].startsWith('^v')) { out.add(m[1]); }
    }
    return out;
}
const bridgeFilters = tagFilters(bridgeYml);
const deployFilters = tagFilters(deployYml);
check('promotion-deploy-bridge.yml has a v-tag filter', bridgeFilters.size >= 1);
check('deploy.yml has a v-tag filter', deployFilters.size >= 1);
check('each file uses exactly ONE v-tag filter (no internal divergence)',
    bridgeFilters.size === 1 && deployFilters.size === 1,
    `bridge=${[...bridgeFilters].join('|')} deploy=${[...deployFilters].join('|')}`);
{
    const b = [...bridgeFilters][0];
    const d = [...deployFilters][0];
    check('the tag-filter regex is byte-identical across bridge and deploy',
        b !== undefined && b === d, `bridge=${JSON.stringify(b)} deploy=${JSON.stringify(d)}`);
}

/* ---- 4. release.yml dispatchability ------------------------------------- */
// workflow_dispatch must be declared in the `on:` block (before permissions/jobs).
{
    const onSlice = releaseYml.split(/\n(?:permissions|jobs)\s*:/)[0];
    check('release.yml declares workflow_dispatch in on:',
        /\n\s*workflow_dispatch\s*:/.test(onSlice),
        'the bridge\'s `gh workflow run release.yml` 422s without it — no GitHub Release is ever created');
    check('release.yml keeps the on:push:tags trigger too',
        /\n\s*tags\s*:/.test(onSlice) && /'v\*'/.test(onSlice),
        'a human-pushed tag must still fire release.yml directly');
}

/* ---- 5. Bridge step order ----------------------------------------------- */
{
    const cutIdx = bridgeYml.indexOf('- name: Cut release tag');
    const deployIdx = bridgeYml.indexOf('- name: Dispatch deploy.yml for the base branch');
    check('bridge has the "Cut release tag" step', cutIdx >= 0);
    check('bridge has the "Dispatch deploy.yml" step', deployIdx >= 0);
    check('bridge cuts the tag BEFORE dispatching deploy (avoids the off-by-one)',
        cutIdx >= 0 && deployIdx >= 0 && cutIdx < deployIdx,
        'if deploy is dispatched first, the release deploy injects the PREVIOUS release\'s number');
}

/* ---- 6. fetch-tags ------------------------------------------------------ */
{
    const checkoutStep = stepBody(deployYml, 'Checkout code');
    check('deploy.yml checkout requests fetch-tags: true',
        /fetch-tags:\s*true/.test(checkoutStep),
        'the injection parses the latest v* tag — it must be fetched');
}

/* ---- 7. Retirement holds ------------------------------------------------ */
check('version-bump.yml is deleted', !fs.existsSync(path.join(WF, 'version-bump.yml')));
{
    // Derive the workflow list from the tree; no file except deploy.yml may
    // WRITE Version.Number with sed (changelog.yml READS it with grep — allowed).
    const wfFiles = fs.readdirSync(WF).filter((n) => /\.ya?ml$/.test(n));
    check('scanned a plausible number of workflow files', wfFiles.length >= 4, `found ${wfFiles.length}`);
    const offenders = [];
    for (const name of wfFiles) {
        if (name === 'deploy.yml') { continue; }
        const src = read(path.join(WF, name));
        // `\bsed\b`, NOT a bare "sed" substring — the latter matches inside
        // "Unreleased" (…ea-sed), which changelog.yml's grep-READ line contains.
        const writes = src.split('\n').some((l) =>
            /\bsed\b/.test(l) && deEscape(l).includes('["Version"]["Number"]'));
        if (writes) { offenders.push(name); }
    }
    check('only deploy.yml writes Version.Number with sed',
        offenders.length === 0, 'offending workflow(s): ' + offenders.join(', '));
}

if (failures) {
    console.error(`\nFAIL: ${failures} versioning-pipeline check(s) failed.`);
    process.exit(1);
}
console.log('\nOK: tag-derived version pipeline agrees end-to-end (anchors, tag filter, dispatch, order, fetch-tags, retirement).');

/* -----------------------------------------------------------------------------
 * MUTATION PROOF (run by hand, recorded in the #1899 commit/PR body):
 *   (i)   rename a NULL anchor in infoAppVer.php (e.g. ["Build"]["Number"]) => (1) RED
 *   (ii)  change one char of the tag filter in ONE file (bridge OR deploy)   => (3) RED
 *   (iii) delete `workflow_dispatch:` from release.yml                       => (4) RED
 *   (iv)  swap the bridge's Cut-release-tag / Dispatch-deploy step order     => (5) RED
 *   (v)   remove `fetch-tags: true` from deploy.yml's checkout               => (6) RED
 *   (vi)  restore all                                                        => GREEN
 * --------------------------------------------------------------------------- */
