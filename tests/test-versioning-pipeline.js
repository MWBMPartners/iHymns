/**
 * tests/test-versioning-pipeline.js — committed-anchor version pipeline guard
 * (#1899, tag-based #1963, made TAG-FREE by #1965, split into a marketing
 * version + a separate build number with a deliberate patch mechanism —
 * rule #46 in .claude/CLAUDE.md is the full contract)
 *
 * PURPOSE
 * ELI5: the pieces that turn the version number committed in infoAppVer.php
 * into the version number the site actually shows are spread across two
 * workflow files, one shell script and several PHP files. If any two silently
 * disagree — a sed anchor renamed, a step reordered, a dispatch trigger
 * re-added, a stray tag creeping back in, the build number leaking into the
 * version string it's supposed to stay separate from — the deploy still goes
 * GREEN and prod just shows a stale or wrong version. That is the worst
 * failure class this repo produces (rule #34/#35). This guard reads the REAL
 * files and asserts they agree, so a silent disagreement goes red in CI
 * instead.
 *
 * #1965 CHANGE OF SHAPE: iHymns deploys direct via SFTP — there is no GitHub
 * Releases-driven rollout and never will be (owner directive). #1899 minted a
 * release tag on every beta->main promotion, unconditionally; #1963 moved
 * minting to deploy.yml, running on alpha, gated on a Conventional Commits
 * classifier (classify-bump.sh); #1965 keeps that SAME classifier but removes
 * the tag entirely — deploy.yml now edits the COMMITTED MAJOR.MINOR.PATCH in
 * infoAppVer.php (plus its lockstep mirrors, api-docs.yaml and
 * manifest.json) and commits the edit straight back to alpha, `[skip ci]`.
 * There is no `v*` tag, no GitHub Release, and no `gh workflow run
 * release.yml` dispatch anywhere in the automated pipeline any more —
 * release.yml itself is now dormant/manual-only (see its own header).
 *
 * LATER CHANGE OF SHAPE — marketing version split from the build number, plus
 * a deliberate patch level: `Version.Number` used to be deployed as
 * `MAJOR.MINOR.<commit count>` (the commit count masquerading as a semver
 * patch — e.g. "1.1.1017"). Now `Version.Number` stays clean marketing
 * semver `MAJOR.MINOR.PATCH` end-to-end (the commit count moves ONLY into
 * the separate `Version.Build.Number`), and PATCH becomes a real,
 * deliberate release level — minted only by an explicit `Release: patch`
 * merge-message footer, never by an ordinary fix/chore/docs commit. Checks
 * 5/6 grew to cover the three-branch bump arithmetic and the full
 * `next_version` output; NEW check 6b is the separation invariant itself
 * (the marketing-version sed must never see BUILD_NUMBER); NEW checks 12/13
 * cover the display sites (index.php, admin-footer.php, settings.php) and
 * the asset cache-buster regression this split would otherwise cause (CSS
 * `?v=` busters relied on Version.Number changing every deploy, which stops
 * being true).
 *
 * classify-bump.sh's OWN functional truth table (none/minor/major/patch,
 * scopes, `!`, BREAKING CHANGE / Release: patch footers, near-misses,
 * multi-commit ranges) lives in tests/test-bump-classifier.js; this file
 * only checks the WORKFLOW WIRING around it.
 *
 * DETAIL — every assertion is DERIVED from the tree (no typed lists) and each
 * is mutation-proven (break the thing, watch it go red — see the foot):
 *   1. Anchor pairing — every `sed ... = NULL` anchor deploy.yml's "Inject
 *      build info" step injects has a matching `... = NULL` line in
 *      infoAppVer.php (commit SHA/date/URL/build seds — an anchor rename
 *      silently no-ops the injection). Unaffected by #1965 (these are commit
 *      metadata anchors, not version-tag anchors).
 *   2. Version-sed pairing — deploy.yml has a sed writing Version.Number, and
 *      the COMMITTED value is three plain integers ("X.Y.Z"), the contract
 *      sync-version.sh's regex and the sed both rely on. The patch digit is
 *      now a REAL release level — do NOT tighten this to `\d+\.\d+\.0`.
 *   3. Tag-free invariant (#1965, REPLACES #1963's tag-filter-singularity
 *      check) — deploy.yml contains NO `git tag` creation, NO `refs/tags/`
 *      push, and no reference to `release.yml` anywhere (the retired
 *      dispatch). Scanned as a single mutation-proven invariant rather than
 *      pinning a tag-filter regex that no longer exists anywhere in the tree.
 *   4. Committed anchor read — deploy.yml has a `relanchor` step that reads
 *      the committed Version.Number via the `["Number"] = "` grep pattern
 *      into a step output (both the two-part `mm` AND the full three-part
 *      `mmp` — the latter is the patch digit's source).
 *   5. Bump step — deploy.yml has an alpha-gated (`github.ref_name ==
 *      'alpha'`) `versionbump` step that invokes classify-bump.sh, computes a
 *      `NEXT` version across THREE arithmetic branches (major/minor/patch),
 *      outputs the FULL `next_version`, and commits it with a
 *      `chore(version): bump` message carrying `[skip ci]`.
 *   6. Inject reads outputs, not tags — the "Inject build info" step reads
 *      the marketing version from `steps.versionbump.outputs.next_version` /
 *      `steps.relanchor.outputs.mmp`, never re-resolves anything itself, and
 *      carries no `LATEST_TAG`-shaped variable or dormant "no tag yet"
 *      branch — it always injects.
 *   6b. The separation invariant — the sed that writes `["Version"]["Number"]`
 *      NEVER interpolates `BUILD_NUMBER`, while `BUILD_NUMBER` still reaches
 *      the SEPARATE `["Build"]["Number"]` sed. This is the one check that
 *      would catch the pre-split "1.1.1017" shape creeping back.
 *   7. deploy.yml step order — "Resolve committed version anchor" precedes
 *      "Classify and bump committed version" precedes "Inject build info"
 *      (else the injection reads an anchor that was never resolved, or
 *      resolves it before the classifier had a chance to bump it — the same
 *      off-by-one #1899/#1963 guarded against, now guarded on the new steps).
 *   8. Retirement holds — version-bump.yml is gone, and no workflow file
 *      except deploy.yml writes Version.Number with sed.
 *   9. deploy.yml permissions — carries `contents: write` (the bump commit
 *      push) and NOT `actions: write` (nothing left to dispatch).
 *  10. Bridge stays tag-free — promotion-deploy-bridge.yml has no
 *      `git tag -a` call, no "Cut release tag"-shaped step, and still keeps
 *      its one remaining job, the deploy dispatch.
 *  11. Classifier call contract — deploy.yml carries the classifier's exact
 *      stdin contract (`--format='%s%x1f%b%x1e'`) AND actually invokes
 *      classify-bump.sh, together inside the "Classify and bump committed
 *      version" step, so a format-string edit on one side without the other
 *      goes red (rule #35).
 *  12. Display lockstep — index.php and admin-footer.php both read
 *      Version.Build.Number, both render the literal " · build " label, and
 *      neither folds the raw commit-date stamp into its display string any
 *      more; settings.php's About card keeps its Build row and grows Channel
 *      + Date rows.
 *  13. Asset cache-buster regression guard — index.php's CSS `?v=` busters
 *      key off a per-deploy `$assetVersion` (marketing version + build
 *      number) instead of the bare marketing version, which no longer
 *      changes every deploy.
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
const INDEX = path.join(REPO, 'appWeb', 'public_html', 'index.php');
const ADMIN_FOOTER = path.join(REPO, 'appWeb', 'public_html', 'manage', 'includes', 'admin-footer.php');
const SETTINGS = path.join(REPO, 'appWeb', 'public_html', 'includes', 'pages', 'settings.php');

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
const indexSrc = read(INDEX);
const adminFooterSrc = read(ADMIN_FOOTER);
const settingsSrc = read(SETTINGS);

console.log('Committed-anchor (tag-free) version pipeline:');

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

/* Strip full-line `#` comments before scanning for an ACTIVE shell
   invocation — several steps below legitimately NARRATE the retired tag
   scheme in prose/doc-comments (e.g. "this used to git tag -a here"), and a
   bare substring ban would be exactly the over-broad guard rule #34 warns
   against (a guard that fails on correct, explanatory code gets weakened or
   deleted rather than fixed). Only code lines count. */
function stripComments(src) {
    return src.split('\n').filter((l) => !/^\s*#/.test(l)).join('\n');
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

/* ---- 3. Tag-free invariant (#1965) --------------------------------------- */
// The whole point of #1965: NO git tag is ever created, pushed, or dispatched
// from the automated pipeline. Scanned on deploy.yml's CODE lines only (doc-
// comments narrating the retired #1963 tag scheme are exempt — see
// stripComments's own rationale) so this stays a guard against LIVE
// behaviour, not a ban on explaining history.
{
    const deployCode = stripComments(deployYml);
    check('deploy.yml has NO `git tag` creation',
        !/\bgit\s+tag\s+-a\b/.test(deployCode) && !/\bgit\s+tag\s+\S/.test(deployCode),
        'a live `git tag` invocation would mean #1965\'s tag-free model was reintroduced');
    check('deploy.yml has NO `refs/tags/` push',
        !/refs\/tags\//.test(deployCode));
    check('deploy.yml never references release.yml (dispatch retired, #1965)',
        !deployCode.includes('release.yml'),
        'the "Dispatch release.yml" step and its `gh workflow run release.yml` call must be fully gone');
}

/* ---- 4. Committed anchor read -------------------------------------------- */
{
    const relanchorStep = stepBody(deployYml, 'Resolve committed version anchor');
    check('deploy.yml has the "Resolve committed version anchor" step', relanchorStep !== '');
    check('the anchor step declares id: relanchor', /^\s*id:\s*relanchor\s*$/m.test(relanchorStep));
    check('the anchor step greps the committed Version.Number into an output',
        deEscape(relanchorStep).includes('["Number"]') && /echo\s+"mm=\$MM"\s*>>\s*"?\$GITHUB_OUTPUT"?/.test(relanchorStep),
        'expected a grep on the ["Number"] = "..." pattern feeding `echo "mm=$MM" >> $GITHUB_OUTPUT`');
    // Marketing-version/build-number split: the anchor step ALSO resolves
    // the FULL three-part committed value (`mmp`), the patch digit's
    // source for a patch bump — a separate grep + a separate output line,
    // both required (deleting either one silently drops the patch digit
    // the bump step later reads via CURP).
    check('the anchor step greps the FULL committed X.Y.Z anchor (mmp) with a three-part pattern',
        relanchorStep.includes('[0-9]+\\.[0-9]+\\.[0-9]+'));
    check('the anchor step feeds mmp into an output',
        /echo\s+"mmp=\$MMP"\s*>>\s*"?\$GITHUB_OUTPUT"?/.test(relanchorStep));
}

/* ---- 5. Bump step --------------------------------------------------------- */
{
    const bumpStep = stepBody(deployYml, 'Classify and bump committed version');
    check('deploy.yml has the "Classify and bump committed version" step', bumpStep !== '');
    check('the bump step declares id: versionbump', /^\s*id:\s*versionbump\s*$/m.test(bumpStep));
    check("the bump step is gated on github.ref_name == 'alpha'",
        /if:\s*github\.ref_name\s*==\s*'alpha'/.test(bumpStep));
    check('the bump step invokes classify-bump.sh', bumpStep.includes('classify-bump.sh'));
    check('the bump step computes a NEXT version',
        /\bNEXT="/.test(bumpStep) || /\bNEXT=\$/.test(bumpStep));
    check('the bump step commits with a "chore(version): bump" message carrying [skip ci]',
        /chore\(version\):\s*bump/.test(bumpStep) && bumpStep.includes('[skip ci]'));

    /* Marketing-version/build-number split + deliberate patch releases: the
       bump step grew a THIRD arithmetic branch (major/minor/patch) and now
       outputs the FULL next_version (never a two-part next_mm — a two-part
       output plus a hardcoded ".0" in the inject step would deploy "X.Y.0"
       for an "X.Y.1" patch release, which is wrong). See rule #46 in
       .claude/CLAUDE.md. */
    check('the bump step has all three arithmetic branches (major/minor/patch)',
        /\$\(\(MAJOR \+ 1\)\)\.0\.0/.test(bumpStep)
        && /\$\(\(MINOR \+ 1\)\)\.0/.test(bumpStep)
        && /\$\(\(PATCH \+ 1\)\)/.test(bumpStep));
    check('the bump step outputs the FULL next_version (not a two-part next_mm)',
        /echo\s+"next_version=\$NEXT"\s*>>\s*"?\$GITHUB_OUTPUT"?/.test(bumpStep)
        && !/next_mm=/.test(bumpStep));
    check('the bump step reads the full committed anchor (CURP) for the patch digit',
        /CURP:\s*\$\{\{\s*steps\.relanchor\.outputs\.mmp\s*\}\}/.test(bumpStep));
}

/* ---- 6. Inject reads outputs, not tags ------------------------------------ */
{
    check('the "Inject build info" step reads steps.versionbump.next_version and steps.relanchor.mmp',
        /NEXT_VERSION="\$\{\{\s*steps\.versionbump\.outputs\.next_version\s*\}\}"/.test(injectStep)
        && /MARKETING_VERSION="\$\{\{\s*steps\.relanchor\.outputs\.mmp\s*\}\}"/.test(injectStep)
        && /MARKETING_VERSION="\$NEXT_VERSION"/.test(injectStep));
    const injectStepCode = stripComments(injectStep);
    check('the "Inject build info" step does NOT re-resolve a tag with its own git tag -l grep',
        !/git\s+tag\s+-l/.test(injectStepCode),
        'a live git-tag-l invocation would mean it stopped reading steps.*.outputs and started re-asking the question rule #35 bans re-asking');
    check('the "Inject build info" step carries no LATEST_TAG-shaped variable',
        !/LATEST_TAG/.test(injectStepCode),
        'checked on comment-stripped code — the step\'s own doc-comment may legitimately NARRATE the retired variable name (rule #34: a guard must not ban correct explanatory prose)');
    check('the "Inject build info" step always injects (no dormant "no release yet" else-branch)',
        !/No release tags yet/i.test(injectStepCode) && !/release scheme dormant/i.test(injectStepCode));
}

/* ---- 6b. The separation invariant (the heart of the marketing-version /
   build-number split): the sed that writes ["Version"]["Number"] must NEVER
   interpolate BUILD_NUMBER, while BUILD_NUMBER must still reach the
   SEPARATE ["Build"]["Number"] sed — checked on comment-stripped code so the
   step's own doc-comment (which legitimately narrates the RETIRED
   "${MM}.${BUILD_NUMBER}" shape in prose) can't false-positive this. ------- */
{
    const injectCode = stripComments(injectStep);
    const versionSedLines = injectCode.split('\n').filter((l) =>
        /\bsed\b/.test(l) && deEscape(l).includes('["Version"]["Number"]'));
    check('a Version.Number sed exists in the inject step', versionSedLines.length >= 1);
    check('the marketing-version sed NEVER interpolates BUILD_NUMBER (the separation invariant)',
        versionSedLines.length >= 1 && versionSedLines.every((l) => !l.includes('BUILD_NUMBER')));
    check('BUILD_NUMBER still reaches the ["Build"]["Number"] sed',
        injectCode.split('\n').some((l) => /\bsed\b/.test(l)
            && deEscape(l).includes('["Build"]["Number"]') && l.includes('BUILD_NUMBER')));
    check('no version-dot-BUILD_NUMBER composition survives',
        !/\.\$\{BUILD_NUMBER\}"/.test(injectCode));
}

/* ---- 7. deploy.yml step order --------------------------------------------- */
// Line-anchored on an ACTUAL `- name:` declaration (mirroring stepBody's own
// technique), not a raw substring search — several of these steps' own
// doc-comments legitimately mention a LATER step's name in prose ("...then
// 'Inject build info' below reads..."), and a raw indexOf would find that
// earlier in-comment mention instead of the real step declaration, silently
// misjudging the order. This bit us once already writing this very test —
// see the mutation-proof foot.
{
    function stepLineIndex(src, nameSubstr) {
        return src.split('\n').findIndex((l) => /^\s*- name:/.test(l) && l.includes(nameSubstr));
    }
    const relIdx = stepLineIndex(deployYml, 'Resolve committed version anchor');
    const bumpIdx = stepLineIndex(deployYml, 'Classify and bump committed version');
    const injectIdx = stepLineIndex(deployYml, 'Inject build info');
    check('deploy.yml has the "Resolve committed version anchor" step', relIdx >= 0);
    check('deploy.yml has the "Classify and bump committed version" step', bumpIdx >= 0);
    check('deploy.yml has an "Inject build info" step', injectIdx >= 0);
    check('deploy.yml orders: Resolve committed version anchor < Classify and bump committed version < Inject build info',
        relIdx >= 0 && bumpIdx >= 0 && injectIdx >= 0 && relIdx < bumpIdx && bumpIdx < injectIdx,
        `relanchor@line${relIdx} versionbump@line${bumpIdx} inject@line${injectIdx}`);
}

/* ---- 8. Retirement holds --------------------------------------------------- */
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

/* ---- 9. deploy.yml permissions (#1965 — actions: write dropped) ---------- */
// Isolated the same way release.yml's `on:` block used to be isolated (still
// the right technique): slice up to the next top-level key so a permission
// mentioned in a step body (e.g. an explanatory comment) can't false-positive
// this check.
{
    const permSlice = deployYml.split(/\n(?:jobs)\s*:/)[0];
    const permBlock = permSlice.slice(permSlice.indexOf('\npermissions:'));
    check('deploy.yml permissions: includes contents: write',
        /\n\s*contents:\s*write\s*$/m.test(permBlock),
        'the version-bump commit push needs this scope');
    check('deploy.yml permissions: does NOT include actions: write (#1965 — nothing left to dispatch)',
        !/\n\s*actions:\s*write\s*$/m.test(permBlock),
        'actions: write existed only for the retired "Dispatch release.yml" step — its presence now would be a stale over-broad grant');
}

/* ---- 10. Bridge stays tag-free -------------------------------------------- */
{
    check('promotion-deploy-bridge.yml has NO "Cut release tag" step',
        !bridgeYml.includes('- name: Cut release tag'));
    check('promotion-deploy-bridge.yml has NO `git tag -a` call',
        !/git\s+tag\s+-a/.test(stripComments(bridgeYml)));
    check('promotion-deploy-bridge.yml STILL has the deploy-dispatch step (its one remaining job)',
        bridgeYml.includes('- name: Dispatch deploy.yml for the base branch'));
}

/* ---- 11. Classifier call contract (rule #35) ------------------------------ */
// The exact stdin format classify-bump.sh's own header documents, AND an
// actual invocation of the script — checked together so a format-string edit
// on one side without the other (or a call to some OTHER script) goes red.
{
    check("deploy.yml carries the exact classifier format string --format='%s%x1f%b%x1e'",
        deployYml.includes("--format='%s%x1f%b%x1e'"));
    check('deploy.yml actually invokes classify-bump.sh',
        deployYml.includes('classify-bump.sh'));
    const bumpStep = stepBody(deployYml, 'Classify and bump committed version');
    check('classify-bump.sh is invoked from WITHIN the "Classify and bump committed version" step',
        bumpStep.includes('classify-bump.sh') && bumpStep.includes("--format='%s%x1f%b%x1e'"));
}

/* ---- 12. Display lockstep (replaces admin-footer.php's old "mirror the
   $versionDisplay composition" COMMENT as the mechanism — rule #35: a
   comment saying "keep these in sync" is the failure, not the fix). Both
   index.php and admin-footer.php must (a) read Build.Number, (b) render the
   literal " · build " label, and (c) no longer fold the raw commit-date
   stamp into the display string — the two retired stamp-fold fingerprints
   are checked by NAME so a legitimate Date read elsewhere (settings.php's
   About card) isn't caught by a broad ban. settings.php keeps its own Build
   row and grows Channel + Date rows. --------------------------------------- */
{
    // Accept single or double quotes around the array keys — index.php and
    // admin-footer.php use different quoting conventions for this array.
    const readsBuildNumber = (src) => /\[["']Version["']\]\s*\]?\s*\[["']Build["']\]\[["']Number["']\]/.test(src)
        || (src.includes('["Version"]') && src.includes('["Build"]["Number"]'))
        || (src.includes("['Version']") && src.includes("['Build']['Number']"));

    // Anchored on the actual CODE SHAPE (a `.=` concatenation immediately
    // followed by the quoted label), NOT a bare substring search — both
    // index.php's and admin-footer.php's own doc-comments legitimately QUOTE
    // the exact display string ("iHymns v<MAJOR.MINOR.PATCH> · build …") as
    // worked examples, and a bare `.includes(' · build ')` would pass on
    // comment prose alone even if the real concatenation line were mutated
    // away — caught for real running this suite's own mutation proof (xiii)
    // against a scratch copy (rule #34: comment-adjacent code needs a
    // code-shaped assertion, not a plain substring one).
    const rendersBuildLabel = (src) => /\.=\s*['"]\s*·\s*build\s*['"]/.test(src);

    check('index.php reads Version.Build.Number', readsBuildNumber(indexSrc));
    check('index.php renders the literal " · build " label (as a real `.=` concatenation, not just in a comment)',
        rendersBuildLabel(indexSrc));
    check('index.php no longer folds the raw commit-date stamp into the display string (retired substr($buildStamp, 0, 14) fingerprint absent)',
        !indexSrc.includes('substr($buildStamp, 0, 14)'));

    check('admin-footer.php reads Version.Build.Number', readsBuildNumber(adminFooterSrc));
    check('admin-footer.php renders the literal " · build " label (as a real `.=` concatenation, not just in a comment)',
        rendersBuildLabel(adminFooterSrc));
    check('admin-footer.php no longer folds the raw commit-date stamp into the display string (retired substr($_adminFooterBuildStamp… fingerprint absent)',
        !adminFooterSrc.includes('substr($_adminFooterBuildStamp'));

    check('settings.php About card retains a Build row (reads Version.Build.Number)',
        readsBuildNumber(settingsSrc) && settingsSrc.includes('>Build<'));
    check('settings.php About card gained a Channel row', settingsSrc.includes('>Channel<'));
    check('settings.php About card gained a Date row', settingsSrc.includes('>Date<'));
}

/* ---- 13. Asset cache-buster regression guard ------------------------------
   The marketing version no longer changes on every deploy (only on a real
   feat/major/patch release), so the CSS/app.js `?v=` busters MUST key off
   something that STILL changes every deploy (the injected build number) or
   styling goes stale for up to .htaccess' max-age after a build-only
   deploy — the silent-failure class rule #34 warns about. Asserts index.php
   defines a per-deploy $assetVersion folding Build.Number, that each of the
   three stylesheet busters uses it, and that the retired bare-version buster
   shape is gone. ------------------------------------------------------------ */
{
    check('index.php defines $assetVersion folding Version.Build.Number',
        /\$assetVersion\s*=\s*\$app\[["']Application["']\]\[["']Version["']\]\[["']Number["']\]/.test(indexSrc)
        && /\$assetVersion\s*\.=.*Build.*Number/.test(indexSrc.replace(/\s+/g, ' ')));
    check('app.css buster uses $assetVersion', /app\.css\?v=<\?=\s*urlencode\(\$assetVersion\)/.test(indexSrc));
    check('accessibility.css buster uses $assetVersion', /accessibility\.css\?v=<\?=\s*urlencode\(\$assetVersion\)/.test(indexSrc));
    check('print.css buster uses $assetVersion', /print\.css\?v=<\?=\s*urlencode\(\$assetVersion\)/.test(indexSrc));
    check('no bare-Version.Number CSS buster survives (the retired, stale-CSS-after-build-only-deploy shape)',
        !/css\?v=<\?=\s*urlencode\(\$app\[["']Application["']\]\[["']Version["']\]\[["']Number["']\]\)/.test(indexSrc));
}

if (failures) {
    console.error(`\nFAIL: ${failures} versioning-pipeline check(s) failed.`);
    process.exit(1);
}
console.log('\nOK: committed-anchor (tag-free) version pipeline agrees end-to-end (anchors, tag-free invariant, anchor read, three-branch bump step, outputs-not-tags, the marketing-version/build-number separation invariant, step order, retirement, permissions, bridge tag-freedom, classifier contract, display lockstep, asset cache-buster).');

/* -----------------------------------------------------------------------------
 * MUTATION PROOF (#1899/#1963 checks — historical, recorded in those PR bodies):
 *   (i)    rename a NULL anchor in infoAppVer.php (e.g. ["Build"]["Number"]) => (1) RED
 *   (ii)   remove `fetch-tags: true` from deploy.yml's checkout               => n/a (check retired, #1965 — no tag is ever parsed any more)
 *   (iii)  restore all                                                        => GREEN
 *
 * ALSO CAUGHT WRITING THIS FILE (not a deliberate mutation — a genuine
 * wrong-but-green false-positive rule #34 warns about, worth recording
 * because it happened for real): check 7's ORIGINAL implementation used
 * `deployYml.indexOf('Inject build info')` on the whole file, and this same
 * file's own "Classify and bump committed version" doc-comment narrates
 * "...then 'Inject build info' below reads..." — that in-comment mention
 * sits BEFORE the real `- name: Inject build info...` line, so indexOf
 * silently found the WRONG occurrence and check 7 went red on entirely
 * correct code. Fixed by requiring the match sit on an actual `- name:`
 * line (stepLineIndex), same technique stepBody() already used elsewhere in
 * this file. The `LATEST_TAG` and "release scheme dormant" checks in
 * section 6 had the identical trap (this file's OWN doc-comments narrate
 * both retired strings) and are fixed the same way, comment-stripped first.
 *
 * MUTATION PROOF (#1965 checks — run by hand this session, recorded in the
 * #1965 PR body):
 *   (iv)   add `git tag -a v9.9.9 -m x` as a live line inside deploy.yml's
 *          "Classify and bump committed version" step                        => (3) RED ("NO `git tag` creation")
 *          — restore                                                         => GREEN
 *   (v)    change ONLY deploy.yml's classifier format string (leave
 *          classify-bump.sh's own header contract alone)                     => (11) RED
 *          — restore                                                         => GREEN
 *   (vi)   remove the `classify-bump.sh` invocation from the "Classify and
 *          bump committed version" step (replace with a hardcoded `BUMP=minor`) => (5) RED ("the bump step invokes classify-bump.sh"), and (11) RED too
 *          — restore                                                         => GREEN
 *   (vii)  make the "Inject build info" step re-introduce a `git tag -l`
 *          grep (e.g. `LEGACY=$(git tag -l | tail -1)`)                      => (6) RED ("does NOT re-resolve a tag with its own git tag -l grep")
 *          — restore                                                         => GREEN
 *
 * MUTATION PROOF (marketing-version/build-number split + deliberate patch
 * releases — run by hand this session on TEMP COPIES, results recorded in
 * the commit/PR body; rule #46 in .claude/CLAUDE.md is the full contract):
 *   (viii) change the inject step's marketing-version sed to write
 *          `${MARKETING_VERSION}.${BUILD_NUMBER}` (reintroduce the
 *          pre-split composition)                                           => (6b) RED ("the marketing-version sed NEVER interpolates BUILD_NUMBER")
 *          — restore                                                        => GREEN
 *   (ix)   revert the whole inject-step composition to the retired
 *          `MM="${{ steps.versionbump.outputs.next_mm || steps.relanchor
 *          .outputs.mm }}"` / `DEPLOY_VERSION="${MM}.${BUILD_NUMBER}"` shape => (6) RED (the NEXT_VERSION/MARKETING_VERSION pattern is gone) AND (6b) RED (BUILD_NUMBER is back in the version sed)
 *          — restore                                                        => GREEN
 *   (x)    delete `echo "mmp=$MMP"` from the relanchor step                 => (4) RED ("the anchor step feeds mmp into an output"). NOTE: check 5's
 *          own CURP assertion does NOT catch this — it only checks the
 *          BUMP step's own text (`CURP: ${{ steps.relanchor.outputs.mmp
 *          }}`), which is unchanged by this mutation; only check 4, which
 *          reads the RELANCHOR step's text, notices the missing producer.
 *          Verified against a scratch copy while writing this suite (rule
 *          #34: a mutation claim must be checked, not assumed — this file's
 *          own check 4 originally had NO mmp-output assertion at all despite
 *          the header prose claiming one, until this exact mutation run
 *          caught the gap).
 *          — restore                                                        => GREEN
 *   (xi)   delete the bump step's patch branch (the `$((PATCH + 1))`
 *          else-arm, collapsing to a two-branch if/else)                    => (5) RED ("the bump step has all three arithmetic branches")
 *          — restore                                                        => GREEN
 *   (xii)  rename the bump step's own output back to `next_mm=$NEXT`        => (5) RED ("outputs the FULL next_version … not a two-part next_mm").
 *          NOTE: check 6 does NOT also go red here — like (x) above, it only
 *          checks the INJECT step's own text (which still literally reads
 *          `steps.versionbump.outputs.next_version`, unchanged by this
 *          mutation); it does not verify that output is actually PRODUCED
 *          upstream. Verified against a scratch copy while writing this
 *          suite, same rule-#34 lesson as (x)'s note above.
 *          — restore                                                        => GREEN
 *   (xiii) remove the ' · build ' literal from admin-footer.php ONLY (leave
 *          index.php alone)                                                 => (12) RED ("admin-footer.php renders the literal ' · build ' label") — index.php's own (12) checks stay GREEN, proving the guard checks each file independently
 *          — restore                                                        => GREEN
 *   (xiv)  restore index.php's app.css buster to the bare
 *          `urlencode($app["Application"]["Version"]["Number"])` shape      => (13) RED ("app.css buster uses $assetVersion") AND (13) RED ("no bare-Version.Number CSS buster survives")
 *          — restore                                                        => GREEN
 *   (xv)   re-add a `substr($buildStamp, 0, 14)` raw-commit-date-stamp fold
 *          to index.php's $versionDisplay composition                       => (12) RED ("index.php no longer folds the raw commit-date stamp")
 *          — restore                                                        => GREEN
 *   (xvi)  restore all                                                      => GREEN
 * --------------------------------------------------------------------------- */
