/**
 * tests/test-component-label-sites.js — component.label display-sweep guard (#1860 Phase 5, rules #33/#34)
 *
 * PURPOSE
 * ELI5: a curator can now type a custom name for a song section ("Kyrie",
 * "isiZulu") instead of the auto-generated "Verse 1" / "Chorus" heading.
 * Every place in the app that BUILDS that auto-generated heading from
 * scratch has to check for the custom name FIRST — this guard finds every
 * such place in the tree and makes sure none of them forgot. It also makes
 * sure the two machine-export files (which round-trip through other
 * software and MUST stay structural) never grew a reference to the custom
 * name, and that the two canonical PHP read sites still carry it end to end.
 *
 * DETAIL
 * #1860 Phase 5 Commit 8 (68a04838) added `tblSongComponents.Label`
 * (optional display override) and swept every known display site to prefer
 * it — "custom-first, then derived" (rule #33's completeness requirement).
 * Commit 10 is the CI guard that keeps that sweep honest as the tree grows:
 * a NEW file that derives "Verse 1"/"Chorus" the same way (capitalise the
 * `type` token) is exactly the kind of site the original sweep would have
 * missed, so this guard is tree-derived (glob + fingerprint), not a typed
 * file list (rule #34) — a typed list only ever proves the files someone
 * remembered to type.
 *
 * THREE ASSERTIONS
 *   1. Display-deriver completeness. Walk every `.js` under
 *      appWeb/public_html (skip vendor/, *.min.js, and the two machine
 *      exporters — see #3). A file's (comment-stripped) source is a
 *      "structural deriver" when it capitalises a `type`-derived string
 *      near the literal token `type` — both idioms this codebase actually
 *      uses are covered:
 *        (a) `X.charAt(0).toUpperCase() + X.slice(1)`      (print.js, …)
 *        (b) `X.replace(/^\w/, (c) => c.toUpperCase())`     (structure-tab.js, …)
 *      where X is `type` or ends in `.type`. Every file the fingerprint
 *      catches MUST also reference `.label` somewhere (the custom-first
 *      check) — a match with no `.label` is a site the label sweep missed.
 *      A FLOOR list (the six sites #8.1 named) must always be part of the
 *      derived set; if the fingerprint ever under-matches and drops one of
 *      them, THAT assertion fails loudly on its own — a scanner that
 *      silently stops finding known sites is worse than no scanner
 *      (rule #34's "under-matching" clause), so this never degrades to a
 *      quiet pass.
 *   2. PHP canonical sites. `includes/pages/song.php` (the public song page
 *      — every other display site's stated reference point) reads
 *      `$component['label']`. `includes/lyric_lines_read.php` SELECTs
 *      `Label` in BOTH row fetchers (`lyricLinesFetchPrimary`,
 *      `lyricLinesFetchPrimaryMap`) and in the editor/editable shape
 *      (`lyricLinesEditableComponents`) — a string-contains check inside
 *      each function's own extracted body (the technique
 *      test-lyric-lines-read.php already uses for this file), so a Label
 *      reference somewhere ELSE in the file doesn't paper over one function
 *      losing it.
 *   3. Machine-export abstinence (SD7). `manage/editor/format-export.js`
 *      and `manage/editor/propresenter-export.js` round-trip through other
 *      software (OpenLyrics/OpenSong/VideoPsalm/ProPresenter/Proclaim
 *      import back into type+number) — a free-text Label leaking into a
 *      structural export token breaks that round-trip. Comment-stripped
 *      source must contain ZERO `.label` references, full stop.
 *
 * Comment-stripped first (the test-qr-cuercode.js model: blank `/* … *\/`
 * bodies, preserving newlines, before every scan — a doc-comment that
 * merely MENTIONS `comp.label` in prose must never count as a real
 * reference).
 *
 * MUTATION PROOF (performed 2026-08-21, each restored + reverified GREEN
 * before moving to the next; every restore confirmed byte-identical to the
 * pre-mutation file — `git diff` empty for the two tracked sources, a
 * `cmp`/backup-file diff empty for this then-untracked test file itself):
 *   m1 — deleted the custom-first `const custom = …` check out of
 *        js/modules/print.js's componentLabel() (left only the derived
 *        path) -> assertion 1 went RED (print.js now fingerprint-matched,
 *        `.label` reference gone). Restored; green again.
 *   m2 — added a stray `comp.label` reference into format-export.js's
 *        olVerseName() -> assertion 3 went RED (non-zero `.label` count).
 *        Restored; green again.
 *   m3 — temporarily replaced both fingerprint regexes with one that can
 *        never match (`/(?!)/`) -> the derived set emptied and the FLOOR
 *        assertion went RED (none of the six known sites found), proving
 *        the floor check independently catches an under-matching regex
 *        rather than silently reporting "0 sites, 0 problems". Restored;
 *        green again.
 *
 * KNOWN FINDING (not fixed here — see the session report / follow-up
 * issue): this guard's assertion 1 is honestly RED on the unmodified tree
 * for a FOURTH, non-mutation reason — `manage/editor/v2/preview-tab.js`
 * (the Editor2 read-only Preview tab, #1200) independently derives the same
 * "Verse 1"/"Chorus" heading via idiom (b) above and has NO custom-first
 * `.label` check. `git log` confirms Commit 8 (68a04838) never touched this
 * file and it is not in §8.1's site table — a genuine site the original
 * sweep missed, structurally indistinguishable by regex from the correct
 * structure-tab.js site it's copied from (both read `(x.type || 'verse')
 * .replace(/^\w/, …)`), so narrowing the fingerprint to dodge it would also
 * drop the real structure-tab.js floor site. This is the guard doing
 * exactly its job (rule #34) — not weakened to force a false green.
 *
 *   node tests/test-component-label-sites.js
 *
 * Exit 0 = every display site honours component.label and both exporters
 * stay structural; 1 = drift (see KNOWN FINDING above for today's cause).
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const REPO = path.join(__dirname, '..');
const PUB = path.join(REPO, 'appWeb', 'public_html');

let failures = 0;
function check(name, cond, detail) {
    if (cond) { console.log('  ✓ ' + name); return; }
    failures++;
    console.error('  ✗ ' + name + (detail ? '\n      ' + detail : ''));
}

const read = (p) => fs.existsSync(p) ? fs.readFileSync(p, 'utf8') : '';

/* Blank `/* … *\/` and `<!-- … -->` comment bodies before every scan below —
   LOAD-BEARING (rule #34 / the test-qr-cuercode.js precedent): several of the
   files this guard reads carry doc-comments that MENTION `comp.label` or
   `charAt(0).toUpperCase()` in prose (explaining what the code below does),
   and a raw scan would count those explanations as if they were the code
   itself. We scan USAGE, not the word. Newlines are preserved so nothing in
   any reported line context shifts. */
function stripComments(src) {
    src = src.replace(/\/\*[\s\S]*?\*\//g, (m) => m.replace(/[^\n]/g, ' '));
    src = src.replace(/<!--[\s\S]*?-->/g, (m) => m.replace(/[^\n]/g, ' '));
    return src;
}

/* ------------------------------------------------------------------------
 * Assertion 1 — display-deriver completeness (tree-derived, not a typed list)
 * ------------------------------------------------------------------------ */

/* Tree walk, mirroring test-qr-cuercode.js's walker: skip vendor/node_modules/
   .git dirs, keep only non-minified .js files. */
function walkJs(dir, acc = []) {
    for (const ent of fs.readdirSync(dir, { withFileTypes: true })) {
        if (ent.name === 'vendor' || ent.name === 'node_modules' || ent.name === '.git') { continue; }
        const full = path.join(dir, ent.name);
        if (ent.isDirectory()) { walkJs(full, acc); }
        else if (ent.name.endsWith('.js') && !ent.name.endsWith('.min.js')) { acc.push(full); }
    }
    return acc;
}

/* The two machine exporters are excluded from the deriver scan on purpose —
   assertion 3 covers them with the OPPOSITE requirement (zero .label), so
   including them here would make assertions 1 and 3 fight over the same
   file. */
const EXPORTER_FILES = new Set([
    path.join('manage', 'editor', 'format-export.js'),
    path.join('manage', 'editor', 'propresenter-export.js'),
]);

/* Structural-derivation fingerprint: both idioms this codebase actually
   uses to turn a `type` string into a capitalised display word.
     (a) charAt(0).toUpperCase() + …slice(1)   — print.js, components.js, …
     (b) replace(/^\w/, (c) => c.toUpperCase()) — structure-tab.js, …
   Calibrated against the real tree (not guessed): a 40-character look-back
   window from the start of the capitalisation call, requiring the literal
   token `type` (word-bounded, so `prototype`/`datatype` don't count) inside
   that window, was measured against every real hit in the current tree —
   the six known deriver sites land the `type` token 5-17 chars back
   (`type.charAt(`, `comp.type.charAt(`, `(comp.type || 'verse').replace(`),
   comfortably inside 40; every OTHER charAt(0)/replace(/^\w/ hit in the tree
   (letter-index helpers, language-script title-casing, a generic
   capitalise(word) helper, badge-status labels, preset-name formatting) has
   no `type` token within 80 chars at all. 40 is therefore wide enough to
   never truncate a real site and narrow enough that none of those unrelated
   capitalisers false-positive (verified file-by-file below in DERIVED). */
const FINGERPRINT_WINDOW = 40;
let idiomA = /charAt\(0\)\.toUpperCase\(\)/g;
let idiomB = /replace\(\/\^\\w\/,\s*\(?\s*\w*\s*\)?\s*=>\s*\w+\.toUpperCase\(\)\)/g;

function isStructuralDeriver(strippedSrc) {
    for (const re of [idiomA, idiomB]) {
        re.lastIndex = 0;
        let m;
        while ((m = re.exec(strippedSrc))) {
            const winStart = Math.max(0, m.index - FINGERPRINT_WINDOW);
            const before = strippedSrc.slice(winStart, m.index);
            if (/\btype\b/.test(before)) { return true; }
        }
    }
    return false;
}

const allJsFiles = walkJs(PUB);
const DERIVED = [];        // { rel, hasLabel } for every fingerprint-matched file
for (const f of allJsFiles) {
    const rel = path.relative(PUB, f);
    if (EXPORTER_FILES.has(rel)) { continue; }
    const stripped = stripComments(read(f));
    if (isStructuralDeriver(stripped)) {
        DERIVED.push({ rel, hasLabel: /\.label\b/.test(stripped) });
    }
}

console.log('Component-label display-sweep completeness:');

check(`scanned a plausible number of files (parser sanity, ${allJsFiles.length} walked)`,
    allJsFiles.length >= 50, `only ${allJsFiles.length} .js files walked under appWeb/public_html — the tree walk under-read`);

const missingLabel = DERIVED.filter((d) => !d.hasLabel);
check('every structural-deriver site also references .label (custom-first)',
    missingLabel.length === 0,
    missingLabel.map((d) => d.rel + '  — fingerprint-matched (derives "Type Number") but no .label reference found').join('\n      '));

/* FLOOR — the six sites §8.1 named. This does NOT assert the derived set is
   EXACTLY these six (new genuine sites are meant to show up here and be
   required to carry .label, per the assertion above) — it only asserts the
   fingerprint never DROPS a known one. An under-matching regex would
   otherwise silently shrink the derived set and this whole file would
   report "0 problems found" instead of the truth (rule #34's own worked
   example: "the admin-gate audit named five pages and the derived version
   found eight" — under-matching is invisible unless something asserts a
   floor). */
const FLOOR = [
    path.join('js', 'utils', 'components.js'),
    path.join('js', 'modules', 'print.js'),
    path.join('js', 'modules', 'service-broadcast.js'),
    path.join('manage', 'editor', 'v2', 'arrangement-editor.js'),
    path.join('manage', 'editor', 'v2', 'structure-tab.js'),
    path.join('manage', 'editor', 'editor.js'),
];
const derivedRels = new Set(DERIVED.map((d) => d.rel));
const missingFloor = FLOOR.filter((f) => !derivedRels.has(f));
check('fingerprint floor: all six known deriver sites are in the derived set',
    missingFloor.length === 0,
    missingFloor.length
        ? 'MISSING from the derived set — the fingerprint regex is under-matching:\n      ' + missingFloor.join('\n      ')
        : undefined);

/* ------------------------------------------------------------------------
 * Assertion 2 — PHP canonical sites (song.php + lyric_lines_read.php)
 * ------------------------------------------------------------------------ */

console.log('\nPHP canonical read/display sites:');

const songPhp = stripComments(read(path.join(PUB, 'includes', 'pages', 'song.php')));
check("includes/pages/song.php reads $component['label']",
    /\$component\s*\[\s*'label'\s*\]/.test(songPhp),
    'the public song page (every other display site\'s stated reference point) must read the custom label');

/* Extract one top-level PHP function's body as "from its `function NAME(`
   line to the next top-level `function` declaration (or EOF)" — the same
   coarse-but-reliable technique test-lyric-lines-read.php's own fixtures
   rely on this file's function boundaries for; every function this guard
   inspects is declared at column 0 (`^function …`), confirmed against the
   real file, so this never needs balanced-brace parsing. */
function phpFunctionBody(src, name) {
    const startRe = new RegExp('^function\\s+' + name + '\\s*\\(', 'm');
    const startMatch = startRe.exec(src);
    if (!startMatch) { return null; }
    const bodyStart = startMatch.index;
    const afterStart = bodyStart + startMatch[0].length;
    const nextFnRe = /^function\s+\w+\s*\(/m;
    nextFnRe.lastIndex = 0;
    const rest = src.slice(afterStart);
    const nextMatch = nextFnRe.exec(rest);
    const bodyEnd = nextMatch ? afterStart + nextMatch.index : src.length;
    return src.slice(bodyStart, bodyEnd);
}

const lyricLinesReadSrc = stripComments(read(path.join(PUB, 'includes', 'lyric_lines_read.php')));

const fetchPrimary = phpFunctionBody(lyricLinesReadSrc, 'lyricLinesFetchPrimary');
check('lyricLinesFetchPrimary() SELECTs sc.Label',
    fetchPrimary !== null && /sc\.Label\b/.test(fetchPrimary),
    fetchPrimary === null ? 'function not found in includes/lyric_lines_read.php' : 'no sc.Label reference inside the function body');

const fetchPrimaryMap = phpFunctionBody(lyricLinesReadSrc, 'lyricLinesFetchPrimaryMap');
check('lyricLinesFetchPrimaryMap() SELECTs sc.Label',
    fetchPrimaryMap !== null && /sc\.Label\b/.test(fetchPrimaryMap),
    fetchPrimaryMap === null ? 'function not found in includes/lyric_lines_read.php' : 'no sc.Label reference inside the function body');

const editableComponents = phpFunctionBody(lyricLinesReadSrc, 'lyricLinesEditableComponents');
check("lyricLinesEditableComponents() SELECTs Label (gated extraCols)",
    editableComponents !== null && /'Label'/.test(editableComponents) && /,\s*Label\b/.test(editableComponents),
    editableComponents === null ? 'function not found in includes/lyric_lines_read.php' : 'no gated Label column reference inside the function body');

/* ------------------------------------------------------------------------
 * Assertion 3 — machine-export abstinence (SD7)
 * ------------------------------------------------------------------------ */

console.log('\nMachine-export abstinence (SD7 — round-trip tokens stay structural):');

const formatExportSrc = stripComments(read(path.join(PUB, 'manage', 'editor', 'format-export.js')));
const proExportSrc = stripComments(read(path.join(PUB, 'manage', 'editor', 'propresenter-export.js')));

const formatExportLabelHits = (formatExportSrc.match(/\.label\b/g) || []).length;
check('manage/editor/format-export.js has ZERO .label references',
    formatExportLabelHits === 0, `found ${formatExportLabelHits}`);

const proExportLabelHits = (proExportSrc.match(/\.label\b/g) || []).length;
check('manage/editor/propresenter-export.js has ZERO .label references',
    proExportLabelHits === 0, `found ${proExportLabelHits}`);

/* ------------------------------------------------------------------------ */

if (failures) {
    console.error(`\nFAIL: ${failures} component-label site check(s) failed.`);
    process.exit(1);
}
console.log(`\nOK: ${DERIVED.length} structural-deriver site(s) all honour component.label; both machine exporters stay structural.`);
