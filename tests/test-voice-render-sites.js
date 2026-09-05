/**
 * tests/test-voice-render-sites.js — voice/echo render-SITE guard (#2073
 * commit 8, rules #33/#34).
 *
 * ELI5
 * ----
 * "Who sings this line" (chips, echo styling, spans) needs to show up
 * EVERYWHERE a component's lines get turned into a page — the server song
 * page, the set-list arrangement preview, the print/PDF output — not just
 * on whichever ONE of those a developer happened to test by eye. This file
 * finds every place in the tree that builds that kind of markup and checks
 * each one actually reads the voice data, rather than trusting a hand-typed
 * "here are the three files I touched" list that silently rots the moment a
 * fourth one is added and forgotten.
 *
 * WHY THIS GUARD IS TREE-DERIVED, AND HOW IT AVOIDS THE test-component-
 * label-sites.js LESSON (stated honestly, per this commit's own hand-off
 * instructions — .claude/CLAUDE.md rule #45's own history): that guard
 * scans `.js` under `appWeb/public_html` PLUS two named PHP files — so
 * Apple/Android silently never got checked for component `Label`, and
 * nobody noticed until it was pointed out by name. This file's walker
 * covers every `.js` AND `.php` under `appWeb/public_html` (not a
 * two-extension split), which is a genuine improvement — but it does NOT
 * walk `appAndroid/` or `appApple/` either, and says so explicitly rather
 * than silently: native voice-chip RENDERING (as opposed to the already-
 * landed, decode-only `voices`/`voiceSpans` field tolerance, commit
 * `c2985fef`) is a real, tracked gap. See "KNOWN, EXPLICITLY STATED GAPS"
 * printed at the bottom of this file's own output — the whole point of
 * saying it here, in the guard itself, is that it cannot go stale silently
 * the way a claim buried in a chat reply or a hand-off document can.
 *
 * TWO FINGERPRINTS, NOT ONE (a correction to Design-pass-5 §5.1's own
 * regex, discovered while actually wiring this commit's three sites):
 * pass 5 proposed detecting a "line renderer" by (a) a literal
 * `lyric-line`/`print-line`/`preview-line` class-token STRING, or (b) any
 * `.lines` property turned into UI via `.join/.map/.forEach`. Fingerprint
 * (b), tried against the REAL tree, matches a dozen `manage/editor/**`
 * files that assign a component's lines to a `<textarea>`'s plain-text
 * `.value` — which cannot possibly host an HTML chip `<span>` in the first
 * place, so flagging them as incomplete "voice render sites" would be
 * false alarms rule #34 warns against ("keep a guard NARROW enough not to
 * fail on correct code"). Fingerprint (a) alone also has a subtler problem:
 * `js/modules/setlist.js`'s two sites, once wired to call the shared
 * renderer instead of hand-rolling `<p class="lyric-line …">` themselves,
 * no longer contain that literal string AT ALL — the class name moved
 * entirely into `js/modules/voice-parts-render.js`. A fingerprint built
 * only around "hand-rolls the class literally" would therefore LOSE
 * setlist.js from the derived set the moment it was correctly fixed — the
 * exact "wrong-but-green" trap rule #34 exists to catch, just aimed at
 * itself. So this file uses (a) OR (b'): does the file call one of the
 * shared renderer's own exported names (`renderComponentLinesHtml`,
 * `ihymnsVoiceRunOpenTag`/`voiceRunOpenTag`, etc.) — the ONE reuse point
 * rule #22 wants every consumer plugged into, which by definition cannot be
 * derived any other way than by its own name (the same reasoning
 * `test-print-one-renderer.php` and `test-org-logo-surfaces.php` already
 * apply to THEIR shared cores).
 *
 * MUTATION PROOF (performed while writing this file, restored, re-verified
 * green): (1) temporarily removed the `renderComponentLinesHtml` import
 * from `js/modules/setlist.js` → the completeness/floor check went RED
 * ("setlist.js" dropped out of the discovered set entirely, exactly the
 * silent-drop failure mode this file exists to catch). (2) temporarily
 * added a `<span class="lyric-voice-chip">` literally between `<p
 * class="lyric-line…">` and its `</p>` in `includes/pages/song.php` → the
 * textContent-purity check went RED. (3) temporarily changed
 * `ihymnsVoiceRunOpenTag()`'s opening tag from `<div` to `<p` in
 * `includes/voice_parts_render.php` → the live-invocation "never a `<p>`"
 * check went RED. Each mutation was reverted and this file re-run green
 * before being relied on.
 *
 * @see appWeb/public_html/includes/voice_parts_render.php   the PHP renderer
 * @see appWeb/public_html/js/modules/voice-parts-render.js  the JS renderer
 * @see appWeb/public_html/includes/pages/song.php            site 1 (server)
 * @see appWeb/public_html/js/modules/setlist.js               site 2 (arrangement preview + playback)
 * @see appWeb/public_html/js/modules/print.js                 site 3 (print/PDF)
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { spawnSync } from 'node:child_process';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const REPO = path.join(__dirname, '..');
const PUB = path.join(REPO, 'appWeb', 'public_html');

let failures = 0;
let checks = 0;
function check(name, cond, detail) {
    checks++;
    if (cond) {
        console.log('  PASS  ' + name);
    } else {
        failures++;
        console.log('  FAIL  ' + name + (detail ? '\n      ' + detail : ''));
    }
}

const read = (p) => (fs.existsSync(p) ? fs.readFileSync(p, 'utf8') : '');

/* Blank `/* … *\/` and `<!-- … -->` comment BODIES (newlines preserved) —
   the exact test-component-label-sites.js / test-qr-cuercode.js technique.
   Every explanatory doc-comment in this commit's own new files freely
   MENTIONS function names like `renderComponentLinesHtml` and class names
   like `lyric-line` in prose; without this, the guard would count its own
   documentation as evidence of wiring. */
function stripComments(src) {
    src = src.replace(/\/\*[\s\S]*?\*\//g, (m) => m.replace(/[^\n]/g, ' '));
    src = src.replace(/<!--[\s\S]*?-->/g, (m) => m.replace(/[^\n]/g, ' '));
    return src;
}

/* Recursive walk of appWeb/public_html — `.js` AND `.php` together (not a
   two-extension split), skipping vendor/node_modules/.git and minified
   bundles. This is the "covers appWeb fully" half of this file's own
   honestly-stated scope limit (see the file header — native apps are the
   other half, and are NOT walked). */
function walk(dir, acc = []) {
    for (const ent of fs.readdirSync(dir, { withFileTypes: true })) {
        if (ent.name === 'vendor' || ent.name === 'node_modules' || ent.name === '.git') { continue; }
        const full = path.join(dir, ent.name);
        if (ent.isDirectory()) { walk(full, acc); continue; }
        if (ent.name.endsWith('.min.js')) { continue; }
        if (ent.name.endsWith('.js') || ent.name.endsWith('.php')) { acc.push(full); }
    }
    return acc;
}

const allFiles = walk(PUB);

console.log('\nVoice/echo render-site guard (#2073 commit 8)\n');

check(`scanned a plausible number of files (parser sanity, ${allFiles.length} walked)`,
    allFiles.length >= 200,
    `only ${allFiles.length} files walked under appWeb/public_html — the tree walk under-read`);

/* ---------------------------------------------------------------------
 * DISCOVERY — see the file header for why TWO fingerprints, not one.
 * ------------------------------------------------------------------ */

/* (a) hand-rolls the class attribute literally. */
const CLASS_ATTR_RE = /class\s*=\s*["'`][^"'`]{0,120}\b(?:lyric-line|print-line|preview-line)\b/;
/* (b) delegates to the ONE shared renderer instead. */
const RENDERER_CALL_RE = /\b(?:renderComponentLinesHtml|ihymnsVoiceRunOpenTag|ihymnsVoiceChipsHtml|ihymnsVoiceLineHtml|voiceRunOpenTag|voiceChipsHtml|voiceLineHtml)\s*\(/;

const RENDERER_FILES = new Set([
    path.join('includes', 'voice_parts_render.php'),
    path.join('js', 'modules', 'voice-parts-render.js'),
]);
/* The two machine exporters + the third one that isn't JS: they round-trip
   through OTHER software and must carry ZERO voice reference, structural
   or otherwise (assertion 3 below covers them with the OPPOSITE
   requirement, so they are excluded from the completeness pool the same
   way test-component-label-sites.js excludes them from ITS deriver scan). */
const EXPORTER_FILES = new Set([
    path.join('manage', 'editor', 'format-export.js'),
    path.join('manage', 'editor', 'propresenter-export.js'),
    path.join('includes', 'easyworship_export.php'),
]);

const discovered = [];
for (const f of allFiles) {
    const rel = path.relative(PUB, f);
    if (EXPORTER_FILES.has(rel)) { continue; }
    const stripped = stripComments(read(f));
    const viaClassAttr = CLASS_ATTR_RE.test(stripped);
    const viaRendererCall = RENDERER_CALL_RE.test(stripped);
    if (viaClassAttr || viaRendererCall) {
        discovered.push({ rel, stripped, viaClassAttr, viaRendererCall });
    }
}
const discoveredRels = new Set(discovered.map((d) => d.rel));

console.log(`Discovered ${discovered.length} render site(s):`);
discovered.forEach((d) => console.log(`  - ${d.rel}${d.viaClassAttr ? ' [class-attr]' : ''}${d.viaRendererCall ? ' [renderer-call]' : ''}`));
console.log('');

/* ---------------------------------------------------------------------
 * 1 — FLOOR: this commit's three assigned sites must be found. An
 * under-matching fingerprint would otherwise silently shrink the derived
 * set and this whole file would report "0 problems" instead of the truth.
 * ------------------------------------------------------------------ */
const FLOOR = [
    path.join('includes', 'pages', 'song.php'),
    path.join('js', 'modules', 'setlist.js'),
    path.join('js', 'modules', 'print.js'),
];
const missingFloor = FLOOR.filter((f) => !discoveredRels.has(f));
check('floor: song.php, setlist.js and print.js are all discovered render sites',
    missingFloor.length === 0,
    missingFloor.length ? 'MISSING — a fingerprint is under-matching:\n      ' + missingFloor.join('\n      ') : undefined);

/* ---------------------------------------------------------------------
 * 2 — COMPLETENESS: every discovered site EITHER calls the ONE shared
 * renderer (which reads `comp.voices`/`comp.voiceSpans` internally — a
 * caller that delegates has NOTHING further to reference; requiring the
 * literal word "voices" to ALSO appear in, say, js/modules/print.js would
 * be exactly the over-strict guard rule #34 warns against, since print.js
 * calls `voiceRunsByLineIndex(comp)`/`voiceSpansByLineIndex(comp)` and
 * never needs to spell out the property name itself) OR, for a file that
 * hand-rolls the class literally WITHOUT delegating, references
 * `voices`/`voiceSpans` directly. The only thing genuinely incomplete is a
 * NEW hand-rolled `class="lyric-line…">`-style site that does neither —
 * exactly the class of regression this check exists to catch.
 * ------------------------------------------------------------------ */
const VOICES_DATA_RE = /\bvoices\b|\bvoiceSpans\b/;
const incomplete = discovered.filter((d) =>
    !RENDERER_FILES.has(d.rel) && !d.viaRendererCall && !VOICES_DATA_RE.test(d.stripped));
check('every discovered render site either delegates to the shared renderer or references voice data directly',
    incomplete.length === 0,
    incomplete.map((d) => d.rel + ' — hand-rolls lyric/print/preview-line markup but neither calls the shared renderer nor mentions voices/voiceSpans anywhere in the file').join('\n      '));

/* ---------------------------------------------------------------------
 * 3 — EXPORTER ABSTINENCE: machine exports round-trip through OTHER
 * software and must never carry a voice reference that would break re-
 * import, EXCEPT format-export.js's OpenLyrics <lines part="…"> writer
 * (#2071), which is REQUIRED to carry voices+.kind but forbidden from
 * ever emitting the free-text `label`/`displayLabel` (already separately
 * guarded by tests/test-component-label-sites.js for `.label`).
 * ------------------------------------------------------------------ */
console.log('\nExporter abstinence:');
const propresenterExport = stripComments(read(path.join(PUB, 'manage', 'editor', 'propresenter-export.js')));
check('manage/editor/propresenter-export.js carries ZERO voice references',
    !/\bvoices\b/.test(propresenterExport));

const easyworshipExport = stripComments(read(path.join(PUB, 'includes', 'easyworship_export.php')));
check('includes/easyworship_export.php carries ZERO voice references',
    !/\bvoices\b/.test(easyworshipExport));

const formatExport = stripComments(read(path.join(PUB, 'manage', 'editor', 'format-export.js')));
check('manage/editor/format-export.js DOES carry voices (the OpenLyrics part= writer, #2071)',
    /\bvoices\b/.test(formatExport) && /\.kind\b/.test(formatExport));
check('manage/editor/format-export.js carries ZERO displayLabel (structural kind only, never a free-text label)',
    !/\bdisplayLabel\b/.test(formatExport));

/* ---------------------------------------------------------------------
 * 4 — SHARE STAYS CLEAN: the "share this song" 2-line snippet scrapes
 * `.lyric-line` textContent and must NEVER also read the chip — that
 * would leak "Women"/"Men" into a share message nobody asked for.
 * ------------------------------------------------------------------ */
console.log('\nShare-snippet cleanliness:');
const shareJs = stripComments(read(path.join(PUB, 'js', 'modules', 'share.js')));
check('js/modules/share.js contains no lyric-voice reference',
    !/lyric-voice/.test(shareJs));

/* ---------------------------------------------------------------------
 * 5 — TEXTCONTENT PURITY (the single most important check in this file —
 * see includes/voice_parts_render.php's file header): a chip/round-note
 * must never end up NESTED INSIDE a `.lyric-line`/`.print-line` element's
 * own opening/closing tag pair, which would corrupt every scraper that
 * reads that element's textContent as pure lyric text.
 *
 * Targeted at the TWO sites that hand-roll the actual line tag inline
 * (song.php's `<p class="lyric-line">`, print.js's `<div class="print-
 * line">`) — `js/modules/voice-parts-render.js`'s composite
 * `renderComponentLinesHtml()` builds its line tag via a DYNAMIC template
 * (`<${o.lineTag} class="${cls}">`), which this literal-substring scan
 * cannot see textually; that function's own "never nests" guarantee is
 * instead proven at RUNTIME below (check 6, the live-invocation check on
 * the shared renderer's OWN output — which everything, including this
 * composite function, is built out of).
 * ------------------------------------------------------------------ */
console.log('\ntextContent purity (chip/note must be a SIBLING, never nested inside the line tag):');

/**
 * Scan `src` for every occurrence of `openRe` (a line's own opening tag,
 * anchored so a LOOKALIKE class like "lyric-line-translation" never
 * matches "lyric-line") and check the text up to the next `closeTag`
 * contains none of `bannedTokens`.
 * @returns {string[]} problems found (empty = clean)
 */
function findNestedTokens(src, openRe, closeTag, bannedTokens) {
    const problems = [];
    const re = new RegExp(openRe.source, openRe.flags.includes('g') ? openRe.flags : openRe.flags + 'g');
    let m;
    while ((m = re.exec(src))) {
        const closeAt = src.indexOf(closeTag, m.index);
        const windowEnd = closeAt === -1 ? Math.min(src.length, m.index + 2000) : closeAt;
        const between = src.slice(m.index, windowEnd);
        for (const banned of bannedTokens) {
            if (between.includes(banned)) {
                problems.push(`"${banned}" found between an opening line tag at offset ${m.index} and its "${closeTag}"`);
            }
        }
    }
    return problems;
}

const songPhpStripped = stripComments(read(path.join(PUB, 'includes', 'pages', 'song.php')));
const songPurityProblems = findNestedTokens(
    songPhpStripped,
    /<p class="lyric-line(?![\w-])/g,
    '</p>',
    ['lyric-voice-chip', 'lyric-round-note']
);
check('includes/pages/song.php never nests a chip/round-note inside a <p class="lyric-line"> line',
    songPurityProblems.length === 0,
    songPurityProblems.join('\n      '));

const printJsStripped = stripComments(read(path.join(PUB, 'js', 'modules', 'print.js')));
const printPurityProblems = findNestedTokens(
    printJsStripped,
    /<div class="print-line(?![\w-])/g,
    '</div>',
    ['print-voice-chip']
);
check('js/modules/print.js never nests a chip inside a <div class="print-line"> line',
    printPurityProblems.length === 0,
    printPurityProblems.join('\n      '));

/* ---------------------------------------------------------------------
 * 6 — LIVE-INVOCATION CHECK on the shared renderer itself: the ONE thing
 * every discovered site's correctness ultimately rests on is that
 * ihymnsVoiceRunOpenTag()/voiceRunOpenTag() NEVER open a `<p>` — only a
 * `<div>`. Proven by actually CALLING both (real PHP process, real JS
 * import) rather than regexing their source, because the composite JS
 * renderer builds its tag through a dynamic `${o.lineTag}` template a
 * static scan cannot see through (see check 5's note above).
 * ------------------------------------------------------------------ */
console.log('\nLive-invocation check on the shared renderer (the chip-is-a-sibling guarantee):');

const PHP_RENDERER_PATH = path.join(PUB, 'includes', 'voice_parts_render.php');
const phpScript = [
    'require ' + JSON.stringify(PHP_RENDERER_PATH) + ';',
    'echo ihymnsVoiceRunOpenTag([["id"=>1,"kind"=>"female","label"=>"Women","bg"=>false]]);',
].join('');
const phpResult = spawnSync('php', ['-r', phpScript], { encoding: 'utf8' });
const phpOpenTag = phpResult.status === 0 ? phpResult.stdout : '';
check('PHP ihymnsVoiceRunOpenTag() invocation succeeded',
    phpResult.status === 0, phpResult.stderr);
check('PHP ihymnsVoiceRunOpenTag() opens a <div>, never a <p>',
    /^<div\b/.test(phpOpenTag) && !/<p\b/.test(phpOpenTag),
    `got: ${phpOpenTag}`);

const JS_MODULE_PATH = path.join(PUB, 'js', 'modules', 'voice-parts-render.js');
const { voiceRunOpenTag, renderComponentLinesHtml } = await import(pathToFileURL(JS_MODULE_PATH).href);
const jsOpenTag = voiceRunOpenTag([{ id: 1, kind: 'female', label: 'Women', bg: false }]);
check('JS voiceRunOpenTag() opens a <div>, never a <p>',
    /^<div\b/.test(jsOpenTag) && !/<p\b/.test(jsOpenTag),
    `got: ${jsOpenTag}`);

/* Belt-and-braces on the COMPOSITE function too (the one setlist.js
   actually calls): the chip must precede the line, not be inside it, for
   a real two-line, two-run component. */
const composite = renderComponentLinesHtml({
    lines: ['You are holy,', 'You are mighty,'],
    lineIds: [501, 502],
    voices: [{ from: 0, to: 1, parts: [{ id: 10, kind: 'female', label: 'Women', bg: false }] }],
});
const pOpenIdx = composite.indexOf('<p class="lyric-line');
const chipIdx = composite.indexOf('lyric-voice-chip');
const pCloseIdx = composite.indexOf('</p>', pOpenIdx);
check('renderComponentLinesHtml(): the chip appears BEFORE the first <p>, never inside it',
    chipIdx !== -1 && pOpenIdx !== -1 && chipIdx < pOpenIdx,
    `chipIdx=${chipIdx}, pOpenIdx=${pOpenIdx}`);
check('renderComponentLinesHtml(): the first line\'s own <p>…</p> contains no chip token',
    !composite.slice(pOpenIdx, pCloseIdx + 4).includes('lyric-voice-chip'));

/* ---------------------------------------------------------------------
 * KNOWN, EXPLICITLY STATED GAPS — not silently skipped (rule #34). Neither
 * currently matches EITHER fingerprint above (verified: `preview-tab.js`
 * assigns to `.textContent`, never `class="…lyric-line…"` nor a call to the
 * shared renderer; `present-mode.js` only QUERIES `.lyric-line` via
 * `querySelectorAll`, which is not a class-attribute construction either)
 * — so this guard cannot yet enforce anything on them one way or the
 * other. Saying so here, in the guard's own output, is what keeps this
 * from becoming the exact silent gap test-component-label-sites.js's own
 * history warns about.
 * ------------------------------------------------------------------ */
console.log('\nKNOWN, EXPLICITLY STATED GAPS (not silently skipped):');
const KNOWN_GAPS = [
    { rel: path.join('manage', 'editor', 'v2', 'preview-tab.js'),
      reason: 'Editor2 live preview — manage/editor/* is a separate stage\'s assigned files, not this commit\'s. Still uses textContent (plain text), so it cannot show a chip until it is rewritten to build HTML.' },
    { rel: path.join('js', 'modules', 'present-mode.js'),
      reason: 'The round PROJECTOR + slide model — an explicitly separate, later commit\'s assigned file. Still scrapes .lyric-line via textContent only; wiring it is that commit\'s job.' },
    { rel: path.join('appAndroid', 'app', 'src', 'main', 'java', 'ltd', 'mwbmpartners', 'ihymns', 'ui', 'screens', 'SongDetailScreen.kt'),
      reason: 'Android UI does not render a chip row yet — only DECODES the voices field (commit c2985fef). This guard does not walk appAndroid/ at all (stated limit, not a silent one).' },
    { rel: path.join('appApple', 'Packages', 'iHymnsKit', 'Sources', 'IHFeatures', 'SongComponentView.swift'),
      reason: 'Apple UI does not render a chip row yet — same decode-only state as Android. This guard does not walk appApple/ at all (stated limit, not a silent one).' },
];
for (const g of KNOWN_GAPS) {
    console.log(`  - ${g.rel}\n      ${g.reason}`);
}
check('KNOWN GAPS list only names files that genuinely exist (sanity — a stale gap entry pointing at a moved/renamed file is worse than no note)',
    KNOWN_GAPS.every((g) => fs.existsSync(path.join(REPO, g.rel.startsWith('app') ? g.rel : path.join('appWeb', 'public_html', g.rel)))),
    KNOWN_GAPS.filter((g) => !fs.existsSync(path.join(REPO, g.rel.startsWith('app') ? g.rel : path.join('appWeb', 'public_html', g.rel)))).map((g) => g.rel).join(', '));

console.log(`\n=== ${checks} checks, ${failures} failed ===`);
process.exit(failures === 0 ? 0 : 1);
