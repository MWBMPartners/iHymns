/**
 * tests/test-versioning-pipeline.js — tag-derived version pipeline guard
 * (#1899, extended #1963)
 *
 * PURPOSE
 * ELI5: the pieces that turn a production tag into the version number the site
 * shows are spread across three workflow files, one shell script and one PHP
 * file. If any two silently disagree — a sed anchor renamed, a tag-filter
 * regex tweaked in one file but not the other, a dispatch trigger dropped, a
 * step reordered — the deploy still goes GREEN and prod just shows a stale or
 * NULL version. That is the worst failure class this repo produces
 * (rule #34/#35). This guard reads the REAL files and asserts they agree, so a
 * silent disagreement goes red in CI instead.
 *
 * #1963 CHANGE OF SHAPE: #1899 minted the release tag in
 * promotion-deploy-bridge.yml, on every beta->main promotion, unconditionally.
 * #1963 moves minting to deploy.yml, running on alpha, gated on a Conventional
 * Commits classifier (classify-bump.sh — see tests/test-bump-classifier.js for
 * its OWN functional truth table; this file only checks the WORKFLOW WIRING
 * around it). The single-source-of-truth invariant this file now enforces is
 * therefore SHARPER than #1899's "two files must agree" — the tag-filter
 * regex, the tag-cutting step, and the CHANGELOG rollover MUST exist in
 * deploy.yml alone and be ABSENT from promotion-deploy-bridge.yml, which goes
 * back to being nothing but the #1007 deploy-dispatch bridge.
 *
 * DETAIL — every assertion is DERIVED from the tree (no typed lists) and each
 * is mutation-proven (break the thing, watch it go red — see the foot):
 *   1. Anchor pairing — every `sed ... = NULL` anchor deploy.yml injects has a
 *      matching `... = NULL` line in infoAppVer.php (also covers PR1's SHA/date
 *      /build seds — an anchor rename silently no-ops the injection).
 *   2. Version-sed pairing — deploy.yml has a sed writing Version.Number, and
 *      the COMMITTED value is three plain integers ("X.Y.Z"), the contract
 *      sync-version.sh's regex and the sed both rely on.
 *   3'. Tag-filter singularity (#1963, supersedes #1899's two-file agreement
 *      check) — the `^v[0-9]+\.[0-9]+\.[0-9]+$` filter is scanned across EVERY
 *      `.github/workflows/*.yml` file: every occurrence found anywhere must be
 *      byte-identical, it must occur at least once in deploy.yml, and it must
 *      occur ZERO times in promotion-deploy-bridge.yml (the retired minter).
 *   4. release.yml dispatchability — its `on:` declares workflow_dispatch (else
 *      the alpha minter's `gh workflow run` 422s and no GitHub Release is
 *      created).
 *   5'. deploy.yml step order (#1963, supersedes #1899's bridge-step-order
 *      check, which no longer applies — the bridge doesn't cut tags any more)
 *      — "Resolve release anchor" precedes "Classify and cut release tag"
 *      precedes "Inject build info" (else the injection reads an anchor that
 *      was never resolved, or resolves it before the classifier had a chance
 *      to mint a fresher one — the same off-by-one #1899 guarded against, now
 *      guarded within one file instead of across two).
 *   6. fetch-tags — deploy.yml's checkout asks for tags (the injection can't
 *      parse a tag it never fetched).
 *   7. Retirement holds — version-bump.yml is gone, and no workflow file except
 *      deploy.yml writes Version.Number with sed.
 *   8. `--merged HEAD` — deploy.yml's tag-listing line is ancestry-scoped, not
 *      a raw `git tag -l` (else a tag cut on an unrelated branch could be
 *      picked up as "latest" on this one — see promotion-deploy-bridge.yml's
 *      "OPERATIONAL INVARIANT" note on squash-merged promotions).
 *   9. deploy.yml requests `actions: write` (else the "Dispatch release.yml"
 *      step 403s while the rest of the job — including the tag push itself,
 *      which only needs `contents: write` — still goes green).
 *   10. The bridge carries no "Cut release tag" step name and no `git tag -a`
 *      call anywhere (the minter is fully retired from this file), while
 *      still keeping its one remaining job — the deploy dispatch.
 *   11. deploy.yml carries the classifier's exact stdin contract
 *      (`--format='%s%x1f%b%x1e'`) AND actually invokes classify-bump.sh — the
 *      two halves of one call, checked together so a format-string edit on
 *      one side without the other goes red (rule #35).
 *   12. The "Inject build info" step reads a prior step's `steps.*.outputs`
 *      for the tag anchor rather than re-resolving it with a second
 *      `git tag -l` grep — the exact re-ask-the-same-question drift rule #35
 *      exists to ban.
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

/* ---- 3'. Tag-filter singularity (#1963) --------------------------------- */
// Extract every `grep -E '<pattern>'` whose pattern is a version-tag filter
// (starts with ^v). #1963 collapses this to ONE canonical copy, living solely
// in deploy.yml's "Resolve release anchor" step — scan EVERY workflow file
// (tree-derived, not a typed two-file list — rule #34) so a filter that
// resurfaces ANYWHERE, not just in the two files #1899 used to pair, is caught.
function tagFilters(src) {
    const out = new Set();
    const re = /grep\s+-E\s+'([^']*)'/g;
    let m;
    while ((m = re.exec(src)) !== null) {
        if (m[1].startsWith('^v')) { out.add(m[1]); }
    }
    return out;
}
{
    const wfFiles = fs.readdirSync(WF).filter((n) => /\.ya?ml$/.test(n));
    const allFilters = new Set();
    const perFile = new Map();
    for (const name of wfFiles) {
        const filters = tagFilters(read(path.join(WF, name)));
        if (filters.size > 0) { perFile.set(name, filters); }
        for (const f of filters) { allFilters.add(f); }
    }
    check('exactly one distinct v-tag filter exists across every workflow file',
        allFilters.size === 1,
        `found ${allFilters.size} distinct filter(s): ${[...allFilters].join(' | ')} (in: ${[...perFile.keys()].join(', ')})`);
    // #1963 leaves exactly ONE copy of this regex standing (deploy.yml's own),
    // so byte-identity against a SECOND copy — #1899's original mechanism — no
    // longer exists to catch a tweak to it. The regex's own correct shape is
    // pinned here as the one thing nothing else in the tree can derive it
    // from any more (classify-bump.sh never touches tags, only commit
    // subjects) — the same kind of fixed-shape pin check 2 already uses for
    // "three plain integers" and release.yml's own check for the `'v\*'`
    // on:push:tags literal, not the typed-list-of-things-to-check rule #34
    // actually warns against.
    const CANONICAL_TAG_FILTER = '^v[0-9]+\\.[0-9]+\\.[0-9]+$';
    check('the tag-filter regex is the canonical `^v[0-9]+\\.[0-9]+\\.[0-9]+$` pattern',
        allFilters.size === 1 && [...allFilters][0] === CANONICAL_TAG_FILTER,
        `found: ${[...allFilters].join(' | ')}`);
    check('deploy.yml carries the v-tag filter at least once',
        (perFile.get('deploy.yml') ?? new Set()).size >= 1);
    check('promotion-deploy-bridge.yml carries the v-tag filter ZERO times (minter retired, #1963)',
        !perFile.has('promotion-deploy-bridge.yml'),
        perFile.has('promotion-deploy-bridge.yml')
            ? `found: ${[...perFile.get('promotion-deploy-bridge.yml')].join(' | ')}`
            : undefined);
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

/* ---- 5'. deploy.yml step order (#1963) ----------------------------------- */
// The tag scheme now lives entirely inside deploy.yml: resolve the anchor,
// THEN (alpha only) classify commits and maybe mint a fresher one, THEN read
// whichever anchor resulted when injecting build info. Any other order either
// injects a stale anchor or resolves one before the classifier could act.
{
    const relIdx = deployYml.indexOf('Resolve release anchor');
    const classifyIdx = deployYml.indexOf('Classify and cut release tag');
    const injectIdx = deployYml.indexOf('Inject build info');
    check('deploy.yml has the "Resolve release anchor" step', relIdx >= 0);
    check('deploy.yml has the "Classify and cut release tag" step', classifyIdx >= 0);
    check('deploy.yml has an "Inject build info" step', injectIdx >= 0);
    check('deploy.yml orders: Resolve release anchor < Classify and cut release tag < Inject build info',
        relIdx >= 0 && classifyIdx >= 0 && injectIdx >= 0 && relIdx < classifyIdx && classifyIdx < injectIdx,
        `relanchor@${relIdx} classify@${classifyIdx} inject@${injectIdx}`);
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

/* ---- 8. --merged HEAD is load-bearing (#1963) ---------------------------- */
// A raw `git tag -l` lists every tag reachable in the fetched refs regardless
// of ancestry; `--merged HEAD` scopes the anchor to tags that are actual
// ancestors of the commit being deployed. Checked on the SAME line as the
// tag-filter grep (the "Resolve release anchor" step), not merely "somewhere
// in the file", so this can't accidentally pass on an unrelated --merged use.
{
    const relanchorStep = stepBody(deployYml, 'Resolve release anchor');
    check('deploy.yml has the "Resolve release anchor" step body', relanchorStep !== '');
    const tagListLine = relanchorStep.split('\n').find((l) => /git\s+tag\s+-l/.test(l)) ?? '';
    check('the tag-listing line uses `--merged HEAD` (ancestry-scoped, not a raw tag list)',
        /--merged\s+HEAD/.test(tagListLine), `line: ${JSON.stringify(tagListLine)}`);
}

/* ---- 9. deploy.yml requests actions: write (#1963) ----------------------- */
// Isolated the same way release.yml's `on:` block is isolated above: slice up
// to the next top-level key so a permission mentioned in a step body (e.g. an
// explanatory comment) can't false-positive this check.
{
    const permSlice = deployYml.split(/\n(?:jobs)\s*:/)[0];
    const permBlock = permSlice.slice(permSlice.indexOf('\npermissions:'));
    check('deploy.yml permissions: includes actions: write',
        /\n\s*actions:\s*write\s*$/m.test(permBlock),
        'the "Dispatch release.yml" step calls `gh workflow run` and needs this scope');
    check('deploy.yml permissions: still includes contents: write',
        /\n\s*contents:\s*write\s*$/m.test(permBlock),
        'the tag push (git push origin refs/tags/...) needs this scope');
}

/* ---- 10. Bridge no longer mints (#1963) ---------------------------------- */
{
    check('promotion-deploy-bridge.yml has NO "Cut release tag" step',
        !bridgeYml.includes('- name: Cut release tag'));
    check('promotion-deploy-bridge.yml has NO `git tag -a` call',
        !/git\s+tag\s+-a/.test(bridgeYml));
    check('promotion-deploy-bridge.yml STILL has the deploy-dispatch step (its one remaining job)',
        bridgeYml.includes('- name: Dispatch deploy.yml for the base branch'));
}

/* ---- 11. Classifier call contract (#1963, rule #35) ----------------------- */
// The exact stdin format classify-bump.sh's own header documents, AND an
// actual invocation of the script — checked together so a format-string edit
// on one side without the other (or a call to some OTHER script) goes red.
{
    check("deploy.yml carries the exact classifier format string --format='%s%x1f%b%x1e'",
        deployYml.includes("--format='%s%x1f%b%x1e'"));
    check('deploy.yml actually invokes classify-bump.sh',
        deployYml.includes('classify-bump.sh'));
    const classifyStep = stepBody(deployYml, 'Classify and cut release tag');
    check('classify-bump.sh is invoked from WITHIN the "Classify and cut release tag" step',
        classifyStep.includes('classify-bump.sh') && classifyStep.includes("--format='%s%x1f%b%x1e'"));
}

/* ---- 12. Inject step reads steps.*.outputs, not a second tag grep -------- */
{
    check('the "Inject build info" step reads a prior step\'s outputs for the tag anchor',
        /LATEST_TAG="\$\{\{\s*steps\.\w+\.outputs\.\w+/.test(injectStep));
    // Comment-strip before scanning for an ACTIVE `git tag -l` invocation — the
    // step's own doc-comment legitimately narrates the #1963 change ("this
    // used to re-grep `git tag -l` right here"), and banning the phrase
    // outright would be exactly the over-broad guard rule #34 warns against
    // (a guard that fails on correct, explanatory code gets weakened or
    // deleted rather than fixed). Only a REAL shell line counts.
    const injectStepCode = injectStep.split('\n')
        .filter((l) => !/^\s*#/.test(l))
        .join('\n');
    check('the "Inject build info" step does NOT re-resolve the tag with its own git tag -l grep',
        !/git\s+tag\s+-l/.test(injectStepCode),
        'a live git-tag-l invocation would mean it stopped reading steps.*.outputs and started re-asking the question rule #35 bans re-asking');
}

if (failures) {
    console.error(`\nFAIL: ${failures} versioning-pipeline check(s) failed.`);
    process.exit(1);
}
console.log('\nOK: tag-derived version pipeline agrees end-to-end (anchors, tag-filter singularity, dispatch, step order, fetch-tags, retirement, --merged HEAD, permissions, bridge retirement, classifier contract, outputs-not-regrep).');

/* -----------------------------------------------------------------------------
 * MUTATION PROOF (#1899 checks, run by hand and recorded in the #1899 PR body):
 *   (i)   rename a NULL anchor in infoAppVer.php (e.g. ["Build"]["Number"]) => (1) RED
 *   (ii)  delete `workflow_dispatch:` from release.yml                      => (4) RED
 *   (iii) remove `fetch-tags: true` from deploy.yml's checkout              => (6) RED
 *   (iv)  restore all                                                       => GREEN
 *
 * MUTATION PROOF (#1963 checks, run by hand and recorded in the #1963 PR body):
 *   (v)    change one char of deploy.yml's tag-filter regex                 => (3') RED
 *   (vi)   re-add a `grep -E '^v...'` tag filter into
 *          promotion-deploy-bridge.yml                                     => (3') RED
 *   (vii)  move "Inject build info" above "Classify and cut release tag"
 *          in deploy.yml                                                   => (5') RED
 *   (viii) delete `--merged HEAD` from deploy.yml's tag-listing line        => (8) RED
 *   (ix)   remove `actions: write` from deploy.yml's permissions:           => (9) RED
 *   (x)    re-add a `- name: Cut release tag` step name to
 *          promotion-deploy-bridge.yml                                     => (10) RED
 *   (xi)   change ONLY deploy.yml's classifier format string (leave
 *          classify-bump.sh's own header contract alone)                   => (11) RED
 *   (xii)  restore all                                                      => GREEN
 * --------------------------------------------------------------------------- */
