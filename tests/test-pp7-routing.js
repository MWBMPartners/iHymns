/**
 * tests/test-pp7-routing.js — ProPresenter 7+ `.pro` / `.probundle` routing
 * guard (epic #1968 / #885, PR-1 `.pro` routing + PR-2 `.probundle` routing)
 *
 * PURPOSE
 * ELI5: makes sure a `.pro` file picked up anywhere in the editor actually
 * gets a chance to be recognised as a real ProPresenter 7+ file, instead of
 * being silently treated as a plain ChordPro text file the way it always
 * used to be — and, since P2, that a `.probundle` upload reaches its own
 * real importer rather than the "coming in a future update" toast every
 * `.probundle`/`.proplaylist` upload used to show before P2 landed.
 *
 * DETAIL
 * `.pro` is genuinely ambiguous — ChordPro's own documentation blesses the
 * extension, AND ProPresenter 6/7+ both use it natively — so before this
 * fix EVERY `.pro` upload, on EVERY surface, was routed straight to the
 * ChordPro text parser with no sniff at all:
 *   - `editor.js`'s `importJSON()` change handler lumped `.pro` into the
 *     same `endsWith(...)` chain as `.cho`/`.chopro`/`.crd`/`.chord` →
 *     `importChordPro(file)`.
 *   - `api2.php`'s `import_file` `format=auto` extension map mapped `'pro'`
 *     straight to `'chordpro'` alongside the same four ChordPro extensions.
 *   - `api.php` had no `bulk_import_pro7` case at all — the parser
 *     (`_bulkImport_parsePro7()`/`_bulkImport_processPro7()` in
 *     `includes/song_importers.php`) existed but nothing server-side could
 *     ever reach it.
 * A real PP7 `.pro` is a binary protobuf; fed to the ChordPro line parser
 * it either fails outright or — worse — silently produces garbage "lyrics".
 *
 * This guard checks the FOUR files the fix touches for the shape plan
 * §3.1 specifies:
 *   (a) `editor.js`     — the `.pro` branch is no longer bare
 *                         `endsWith('.pro') → importChordPro`; it content-
 *                         sniffs via `sniffProContent()` and can reach the
 *                         new `importProPresenter7()` wrapper.
 *   (b) `api2.php`      — the `format=auto` extension map no longer lists
 *                         `'pro'` inside the `'chordpro'` arm, and instead
 *                         resolves it to an internal `'proauto'` target that
 *                         content-sniffs via the ONE shared, authoritative
 *                         sniff `_bulkImport_sniffProDialect()`
 *                         (`includes/song_importers.php`); `'pro7'` is
 *                         wired into `$bodyFormats` + the processor match.
 *   (c) `api.php`       — has a `case 'bulk_import_pro7':` that calls
 *                         `_bulkImport_processPro7()`.
 *   (d) accept lists    — both `editor.js`'s file-picker `accept` and
 *                         `import2.php`'s `<input accept>` still carry
 *                         `.pro` (so the upload dialog doesn't hide it).
 * Plus: `import2.php`'s format dropdown carries an explicit `'pro7'` entry,
 * and `includes/song_importers.php`'s `_bulkImport_processZip()` ZIP-entry
 * router resolves a `.pro` entry through the SAME shared sniff rather than
 * silently skipping it or mis-routing it to ChordPro.
 *
 * `.probundle` (epic #1968 P2, plan §4.2) needs NO content sniff — unlike
 * bare `.pro` it is unambiguously ProPresenter's own ZIP container — so its
 * wiring is simpler and checked separately: editor.js's `.probundle` branch
 * (now SPLIT from `.proplaylist`, which still shows the P3-not-landed toast)
 * calls a real `importProbundle()` wrapper; api2.php's extension map/
 * `$bodyFormats`/match arm route `'probundle'` straight to
 * `_bulkImport_processProbundle()`; api.php has a `case
 * 'bulk_import_probundle':`; import2.php's accept list + dropdown carry it.
 *
 * MUTATION PROOF (performed 2026-08-28 against the real working tree, each
 * mutation applied, this test re-run and confirmed RED, then reverted with
 * the Edit tool back to the exact original text before moving on — the
 * rule #34 discipline: a guard's first green run means nothing until it has
 * been proven able to fail):
 *   m1 — editor.js: restored the OLD lumped condition
 *        `lower.endsWith('.chord') || lower.endsWith('.pro')) { … importChordPro(file);`
 *        in place of the split chordpro/pro branches → check (a1) went RED.
 *   m2 — api2.php: restored `'cho', 'chopro', 'crd', 'chord', 'pro' =>
 *        'chordpro',` (removing the separate `'pro' => 'proauto'` line) →
 *        check (b1) went RED.
 *   m3 — api.php: deleted the `case 'bulk_import_pro7':` block entirely →
 *        check (c1) went RED.
 *   m4 — editor.js: deleted the `.probundle`/`.proplaylist` accept entries →
 *        check (d2) went RED (forward-wiring coverage).
 *   m5 — editor.js: reverted the `.probundle` branch to its pre-P2 shape
 *        (lumped back into ONE `else if` with `.proplaylist`, both showing
 *        the "coming in a future update" toast, no `importProbundle(file)`
 *        call anywhere) → 6 of the (a') checks went RED — the dedicated-
 *        .probundle-branch check, the importProbundle() dispatch check, the
 *        "no toast on .probundle" check, the dedicated-.proplaylist-branch
 *        check, the "toast on .proplaylist" check, AND the "no importer
 *        call in .proplaylist" check, since the lumped shape's single
 *        combined branch matches neither bounding regex cleanly.
 *   m6 — api.php: deleted the `case 'bulk_import_probundle':` block entirely
 *        → 5 checks went RED: "api.php has case 'bulk_import_probundle':"
 *        and the four checks bounded by it (body found, calls the
 *        processor, reads the field, no bespoke gate).
 *   m7 — api2.php: changed `'probundle' => 'probundle'` to
 *        `'probundle' => 'chordpro'` in the extension map → the (b5)
 *        "routes 'probundle' -> 'probundle'" check went RED.
 * Every mutation was reverted immediately after confirming red; the tree
 * this test ships against is unmodified.
 *
 *   node tests/test-pp7-routing.js
 *
 * Exit 0 = wired correctly, 1 = drift.
 */

import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const REPO = path.join(__dirname, '..');
const EDITOR_DIR = path.join(REPO, 'appWeb', 'public_html', 'manage', 'editor');
const INCLUDES_DIR = path.join(REPO, 'appWeb', 'public_html', 'includes');

let failures = 0;
function check(name, cond, detail) {
    if (cond) { console.log('  ✓ ' + name); return; }
    failures++;
    console.error('  ✗ ' + name + (detail ? '\n      ' + detail : ''));
}

const read = (p) => fs.existsSync(p) ? fs.readFileSync(p, 'utf8') : '';

/* Blank `/* … *\/` block comments and `// …` line comments before scanning —
   the same rule-#34/test-fragment-inline-scripts.php lesson every other
   tree-derived guard in this repo follows: a doc-block explaining the FIX
   necessarily mentions the OLD buggy shape in prose ("used to lump '.pro'
   into ChordPro"), and a raw scan would false-positive on that prose. We
   ban the CODE SHAPE, not the words describing it. */
function stripBlockComments(src) {
    return src.replace(/\/\*[\s\S]*?\*\//g, (m) => m.replace(/[^\n]/g, ' '));
}
const stripJsComments  = stripBlockComments;
const stripPhpComments = stripBlockComments;

const editorJsRaw = read(path.join(EDITOR_DIR, 'editor.js'));
const api2PhpRaw  = read(path.join(EDITOR_DIR, 'api2.php'));
const apiPhpRaw   = read(path.join(EDITOR_DIR, 'api.php'));
const import2Php  = read(path.join(EDITOR_DIR, 'import2.php'));
const importersPhp = read(path.join(INCLUDES_DIR, 'song_importers.php'));

const editorJs = stripJsComments(editorJsRaw);
const api2Php  = stripPhpComments(api2PhpRaw);
const apiPhp   = stripPhpComments(apiPhpRaw);
const importers = stripPhpComments(importersPhp);

console.log('ProPresenter 7+ .pro routing:');

/* ---------------------------------------------------------------------
 * (a) editor.js — client-side routing
 * ------------------------------------------------------------------- */

check('editor.js exists', editorJsRaw !== '');

/* (a1) The ChordPro branch's own `if` condition must NOT also test
   endsWith('.pro') — that combined condition, calling importChordPro(), IS
   the exact pre-fix bug shape. Find the ChordPro branch's condition (the
   `if (...)` immediately preceding a body that calls importChordPro as its
   ONLY import, keyed on the presence of '.chord') and assert '.pro' is
   absent from it. */
const chordProBranch = editorJs.match(/if\s*\(([^{]*?endsWith\('\.chord'\)[^{]*?)\)\s*\{/);
check('editor.js ChordPro branch condition exists (.chord present)', !!chordProBranch);
check('editor.js ChordPro branch condition does NOT also match bare .pro (the pre-fix lumped shape)',
    !!chordProBranch && !/endsWith\('\.pro'\)/.test(chordProBranch[1]),
    chordProBranch ? chordProBranch[1] : '(branch not found)');

/* (a2) A dedicated '.pro' branch exists, separate from the ChordPro one,
   and it defers to the content sniff rather than calling importChordPro()
   directly. Bounded by the NEXT known branch ('.probundle'/'.proplaylist',
   added by this same fix) rather than a generic "next `} else`" — the .pro
   branch's OWN body legitimately contains nested `if/else if/else` (the
   sniff result dispatch), so a generic bound would truncate at the first
   INNER `} else` instead of the branch's real end. */
const proBranch = editorJs.match(/else if\s*\(\s*lower\.endsWith\('\.pro'\)\s*\)\s*\{([\s\S]*?)\n\s*\}\s*else if\s*\(\s*lower\.endsWith\('\.probundle'\)/);
check('editor.js has a dedicated .pro branch (not lumped with ChordPro)', !!proBranch);
/* The pre-fix bug shape was the WHOLE branch body reduced to nothing but a
   single `importChordPro(file);` call with no sniff in front of it. The
   fixed branch legitimately still calls importChordPro() as one of THREE
   sniff-result outcomes (plus a catch-block fallback) — so the correctness
   signal isn't "importChordPro is absent" (it must still be reachable),
   it's "sniffProContent(file) runs BEFORE any importChordPro(file) call". */
check('editor.js .pro branch calls sniffProContent(file) before any importChordPro(file) call',
    !!proBranch
        && /sniffProContent\s*\(\s*file\s*\)/.test(proBranch[1])
        && proBranch[1].indexOf('sniffProContent(file)') < proBranch[1].indexOf('importChordPro(file)'),
    proBranch ? proBranch[1] : '(branch not found)');

/* (a3) sniffProContent() is defined, decodes text, and checks for both the
   pro6 XML signature and control-byte binary detection. */
check('editor.js defines sniffProContent()', /function\s+sniffProContent\s*\(\s*file\s*\)/.test(editorJs));
check('sniffProContent() checks for the ProPresenter 6 XML signature',
    /RVPresentationDocument/.test(editorJs) && /<\\?\?xml/.test(editorJs),
    'expected both an <RVPresentationDocument> substring check and an <?xml (possibly regex-escaped as <\\?xml) prefix check');

/* (a4) importProPresenter7() wrapper exists and posts to the right
   action/field, matching the api.php case added below. */
const importP7Fn = editorJs.match(/function\s+importProPresenter7\s*\([^)]*\)\s*\{([\s\S]*?)\n\}/);
check('editor.js defines importProPresenter7()', !!importP7Fn);
check("importProPresenter7() posts action:'bulk_import_pro7', field:'pro7'",
    !!importP7Fn
        && /action:\s*'bulk_import_pro7'/.test(importP7Fn[1])
        && /field:\s*'pro7'/.test(importP7Fn[1]));

/* (a5) The .pro branch can actually reach importProPresenter7 (dispatched
   on the sniff's 'pro7' result) and importPro6 (on 'pro6') — not just
   defined in isolation and never called. */
check('editor.js .pro branch dispatches to importProPresenter7 on a pro7 sniff result',
    !!proBranch && /importProPresenter7\(file\)/.test(proBranch[1]));
check('editor.js .pro branch dispatches to importPro6 on a pro6 sniff result',
    !!proBranch && /importPro6\(file\)/.test(proBranch[1]));

/* ---------------------------------------------------------------------
 * (a') editor.js — .probundle routes to a REAL importer, .proplaylist still
 *      shows the "coming in a future update" toast (epic #1968 P2 landed
 *      bundle import; P3/playlist has not). '.probundle' is unambiguously
 *      ProPresenter's own ZIP container (unlike bare '.pro' it needs no
 *      content sniff), so its branch must call importProbundle(file)
 *      directly — never the toast, and never lumped into the .proplaylist
 *      branch the way both extensions used to share one `else if` before
 *      P2 landed.
 * ------------------------------------------------------------------- */

const probundleBranch = editorJs.match(/else if\s*\(\s*lower\.endsWith\('\.probundle'\)\s*\)\s*\{([\s\S]*?)\n\s*\}\s*else if\s*\(\s*lower\.endsWith\('\.proplaylist'\)/);
check('editor.js has a dedicated .probundle branch, SEPARATE from .proplaylist', !!probundleBranch);
check('editor.js .probundle branch calls importProbundle(file)',
    !!probundleBranch && /importProbundle\(file\)/.test(probundleBranch[1]),
    probundleBranch ? probundleBranch[1] : '(branch not found)');
check('editor.js .probundle branch does NOT show the "coming in a future update" toast (the pre-P2 shape)',
    !!probundleBranch && !/coming in a future update/.test(probundleBranch[1]),
    probundleBranch ? probundleBranch[1] : '(branch not found)');

/* .proplaylist (P3, not yet landed) must STILL show the toast — this guards
   against .probundle's fix accidentally also giving .proplaylist a working
   importer it doesn't have yet, which would 400 on every real upload. */
const proplaylistBranch = editorJs.match(/else if\s*\(\s*lower\.endsWith\('\.proplaylist'\)\s*\)\s*\{([\s\S]*?)\n\s*\}\s*else if\s*\(\s*lower\.endsWith\('\.rtf'\)/);
check('editor.js has a dedicated .proplaylist branch, SEPARATE from .probundle', !!proplaylistBranch);
check('editor.js .proplaylist branch still shows the "coming in a future update" toast (P3 not yet landed)',
    !!proplaylistBranch && /coming in a future update/.test(proplaylistBranch[1]),
    proplaylistBranch ? proplaylistBranch[1] : '(branch not found)');
check('editor.js .proplaylist branch does NOT call an importer function (no server handler exists yet)',
    !!proplaylistBranch && !/import[A-Za-z]*\(file\)/.test(proplaylistBranch[1]),
    proplaylistBranch ? proplaylistBranch[1] : '(branch not found)');

/* (a'2) importProbundle() wrapper exists and posts to the right action/field,
   matching the api.php case + api2.php format wiring checked below. */
const importProbundleFn = editorJs.match(/function\s+importProbundle\s*\([^)]*\)\s*\{([\s\S]*?)\n\}/);
check('editor.js defines importProbundle()', !!importProbundleFn);
check("importProbundle() posts action:'bulk_import_probundle', field:'probundle'",
    !!importProbundleFn
        && /action:\s*'bulk_import_probundle'/.test(importProbundleFn[1])
        && /field:\s*'probundle'/.test(importProbundleFn[1]),
    importProbundleFn ? importProbundleFn[1] : '(function not found)');

/* ---------------------------------------------------------------------
 * (b) api2.php — server-side auto-router
 * ------------------------------------------------------------------- */

check('api2.php exists', api2PhpRaw !== '');

/* (b1) The extension map's chordpro arm no longer lists 'pro'. Find the
   line(s) whose target is 'chordpro' inside the format=auto extension map
   and assert the token 'pro' (with its own quotes) is absent from each —
   the substring "'pro'" cannot appear inside "'chopro'"/"'crd'"/etc. by
   construction (no quote immediately precedes "pro" in any of those). */
const chordproMapLines = api2Php.split('\n').filter((l) => /=>\s*'chordpro'\s*,/.test(l));
check('api2.php extension map has a chordpro arm', chordproMapLines.length >= 1,
    'expected at least one line of the form `... => \'chordpro\',`');
check("api2.php extension map's chordpro arm no longer includes bare 'pro' (the pre-fix mis-routing)",
    chordproMapLines.length >= 1 && chordproMapLines.every((l) => !l.includes("'pro'")),
    chordproMapLines.join('\n      '));

/* (b2) 'pro' now resolves to the internal 'proauto' target. */
check("api2.php extension map routes 'pro' -> 'proauto'",
    /'pro'\s*=>\s*'proauto'/.test(api2Php));

/* (b3) The 'proauto' resolution block calls the ONE shared, authoritative
   sniff — never a second, forked sniff inline. */
const proautoBlock = api2Php.match(/if\s*\(\s*\$format\s*===\s*'proauto'\s*\)\s*\{([\s\S]*?)\n\s*\}/);
check("api2.php has a 'proauto' resolution block", !!proautoBlock);
check("api2.php's 'proauto' block calls the shared _bulkImport_sniffProDialect()",
    !!proautoBlock && /_bulkImport_sniffProDialect\s*\(/.test(proautoBlock[1]));

/* (b4) 'pro7' is wired into $bodyFormats and the processor match arm. */
check("api2.php \$bodyFormats includes 'pro7'",
    /\$bodyFormats\s*=\s*\[[^\]]*'pro7'[^\]]*\]/.test(api2Php));
check("api2.php import_file dispatches 'pro7' -> _bulkImport_processPro7()",
    /'pro7'\s*=>\s*_bulkImport_processPro7\(/.test(api2Php));

/* (b5) '.probundle' (epic #1968 P2) — unlike bare '.pro', a bundle needs NO
   content sniff (it's unambiguously ProPresenter's own ZIP container), so
   'probundle' maps straight to itself in the extension map, is wired into
   $bodyFormats, and dispatches to _bulkImport_processProbundle(). */
check("api2.php extension map routes 'probundle' -> 'probundle'",
    /'probundle'\s*=>\s*'probundle'/.test(api2Php));
check("api2.php \$bodyFormats includes 'probundle'",
    /\$bodyFormats\s*=\s*\[[^\]]*'probundle'[^\]]*\]/.test(api2Php));
check("api2.php import_file dispatches 'probundle' -> _bulkImport_processProbundle()",
    /'probundle'\s*=>\s*_bulkImport_processProbundle\(/.test(api2Php));

/* ---------------------------------------------------------------------
 * (c) api.php — the new server handler
 * ------------------------------------------------------------------- */

check('api.php exists', apiPhpRaw !== '');
check("api.php has case 'bulk_import_pro7':", /case\s+'bulk_import_pro7'\s*:/.test(apiPhp));

const pro7Case = apiPhp.match(/case\s+'bulk_import_pro7'\s*:([\s\S]*?)\n\s*case\s+'/);
check('api.php bulk_import_pro7 case body found (bounded by the next case)', !!pro7Case);
check('api.php bulk_import_pro7 case calls _bulkImport_processPro7()',
    !!pro7Case && /_bulkImport_processPro7\s*\(/.test(pro7Case[1]));
check("api.php bulk_import_pro7 case reads the 'pro7' upload field",
    !!pro7Case && /\$_FILES\['pro7'\]/.test(pro7Case[1]));

/* No bespoke gate should have been added to the new case ITSELF — the
   file-wide session/role gate (L39-52) and the file-wide POST CSRF gate
   (L131-137) sit ABOVE the switch and already cover every case, this one
   included. (api.php does have ONE pre-existing, unrelated case elsewhere
   with its own belt-and-suspenders validateCsrfRequest() call and an
   explanatory comment admitting it's redundant — this guard does not
   touch that; it only asserts the NEW bulk_import_pro7 case body doesn't
   re-invent either check.) */
check('api.php bulk_import_pro7 case does NOT add its own validateCsrfRequest()/hasRole() call',
    !!pro7Case
        && !/validateCsrfRequest\s*\(/.test(pro7Case[1])
        && !/hasRole\s*\(/.test(pro7Case[1]),
    pro7Case ? pro7Case[1] : '(case not found)');

/* (c') api.php — bulk_import_probundle (epic #1968 P2), mirroring the
   bulk_import_pro7 checks immediately above. */
check("api.php has case 'bulk_import_probundle':", /case\s+'bulk_import_probundle'\s*:/.test(apiPhp));

const probundleCase = apiPhp.match(/case\s+'bulk_import_probundle'\s*:([\s\S]*?)\n\s*case\s+'/);
check('api.php bulk_import_probundle case body found (bounded by the next case)', !!probundleCase);
check('api.php bulk_import_probundle case calls _bulkImport_processProbundle()',
    !!probundleCase && /_bulkImport_processProbundle\s*\(/.test(probundleCase[1]));
check("api.php bulk_import_probundle case reads the 'probundle' upload field",
    !!probundleCase && /\$_FILES\['probundle'\]/.test(probundleCase[1]));
check('api.php bulk_import_probundle case does NOT add its own validateCsrfRequest()/hasRole() call',
    !!probundleCase
        && !/validateCsrfRequest\s*\(/.test(probundleCase[1])
        && !/hasRole\s*\(/.test(probundleCase[1]),
    probundleCase ? probundleCase[1] : '(case not found)');

/* ---------------------------------------------------------------------
 * (d) accept lists + import2.php dropdown
 * ------------------------------------------------------------------- */

/* Scoped to the actual `input.accept = '...'` string literal (NOT a
   file-wide substring search) — this file's own P2/P3 "coming in a future
   update" toast text legitimately mentions ".probundle"/".proplaylist" in
   prose too, so a file-wide search would still pass even with those
   extensions missing from the accept list itself. */
const acceptListMatch = editorJs.match(/input\.accept\s*=\s*'([^']*)'/);
check('editor.js file-picker accept list found', !!acceptListMatch);
check('editor.js file-picker accept list carries .pro',
    !!acceptListMatch && /(^|,)\.pro(,|$)/.test(acceptListMatch[1]),
    acceptListMatch ? acceptListMatch[1] : '(accept list not found)');
check('editor.js file-picker accept list carries .probundle (P2, working) and .proplaylist (P3, forward-wired only)',
    !!acceptListMatch && /(^|,)\.probundle(,|$)/.test(acceptListMatch[1]) && /(^|,)\.proplaylist(,|$)/.test(acceptListMatch[1]),
    acceptListMatch ? acceptListMatch[1] : '(accept list not found)');

check('import2.php exists', import2Php !== '');
check('import2.php <input accept> carries .pro',
    /accept="[^"]*\.pro[,"]/.test(import2Php));
check("import2.php format dropdown has an explicit 'pro7' entry",
    /'pro7'\s*=>\s*'ProPresenter 7\+/.test(import2Php));
check("import2.php's ChordPro dropdown label no longer claims bare .pro",
    (() => {
        const m = import2Php.match(/'chordpro'\s*=>\s*'ChordPro \(([^)]*)\)'/);
        return !!m && !/(^|[^\w.])\.pro([^\w6]|$)/.test(m[1]);
    })());

/* (d') import2.php — .probundle (epic #1968 P2). */
check('import2.php <input accept> carries .probundle',
    /accept="[^"]*\.probundle[,"]/.test(import2Php));
check("import2.php format dropdown has an explicit 'probundle' entry",
    /'probundle'\s*=>\s*'ProPresenter 7\+ Bundle/.test(import2Php));

/* ---------------------------------------------------------------------
 * ZIP path rider — includes/song_importers.php
 * ------------------------------------------------------------------- */

check('includes/song_importers.php exists', importersPhp !== '');
check('song_importers.php defines the shared _bulkImport_sniffProDialect()',
    /function\s+_bulkImport_sniffProDialect\s*\(/.test(importers));

/* The ZIP router's extension detector must route '.pro' to the internal
   'proauto' kind (not silently `continue`, and not straight to 'chordpro'
   or 'pro6'). */
check("song_importers.php ZIP extension router maps ext 'pro' -> kind 'proauto'",
    /\$ext\s*===\s*'pro'\s*\)\s*\{\s*\$kind\s*=\s*'proauto'/.test(importers));

/* And the ZIP loop must actually resolve 'proauto' through the SAME shared
   sniff before falling into a per-kind branch — not a second, forked sniff.
   Bounded by the next known code line ('videopsalm' kind handling, which
   immediately follows) rather than a generic "next `}`" — the resolution
   block itself contains a nested `if ($proSniffBody === false) { … }`,
   which a generic bound would truncate at instead of the block's real end
   (the exact same nested-brace trap check (a2) above hit). */
const zipProautoBlock = importers.match(/if\s*\(\s*\$kind\s*===\s*'proauto'\s*\)\s*\{([\s\S]*?)\}\s*if\s*\(\s*\$kind\s*===\s*'videopsalm'/);
check("song_importers.php ZIP router's 'proauto' resolution calls _bulkImport_sniffProDialect()",
    !!zipProautoBlock && /_bulkImport_sniffProDialect\s*\(/.test(zipProautoBlock[1]),
    zipProautoBlock ? zipProautoBlock[1] : '(block not found)');

if (failures) {
    console.error(`\nFAIL: ${failures} ProPresenter 7+ routing check(s) failed.`);
    process.exit(1);
}
console.log('\nOK: .pro is content-sniffed (client convenience + authoritative server re-sniff) ' +
    'and routes to ProPresenter 7+ / ProPresenter 6 / ChordPro correctly on every surface ' +
    '(editor.js, api.php, api2.php, import2.php, the ZIP importer).');
