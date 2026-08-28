/**
 * tests/test-pp7-label-roundtrip.js — ProPresenter 7+ section-label PHP<->JS lockstep guard
 * (epic #1968 / #885, PR-1, plan §11.2)
 *
 * PURPOSE
 * ELI5: iHymns has TWO maps that must always agree about section names: one turns an iHymns
 * component ("chorus") into the name ProPresenter shows in its group palette ("Chorus", or the
 * short form "C") when we EXPORT a song; the other turns that same PP group name back into an
 * iHymns component when we IMPORT one. If someone edits either map without updating the other, a
 * song exported as "Chorus" could come back in as "refrain" — the section survives, but its TYPE
 * (which drives CSS styling, chorus-highlighting, and every machine-export keyword per rule #45)
 * silently changes. This guard reads BOTH maps straight out of source and proves every name (and
 * every unambiguous single-letter shorthand) the exporter can produce folds back to the SAME
 * iHymns type through the importer's map.
 *
 * DETAIL
 * `manage/editor/propresenter-export.js`'s `COMPONENT_LABEL_MAP` is the FORWARD direction:
 * `{type: {letter, name}}` — e.g. `chorus -> {letter:'C', name:'Chorus'}`. `includes/song_importers.php`'s
 * `_bulkImport_pro7GroupType()` is the REVERSE direction: given a raw PP group name (or letter
 * shorthand), fold it back to `{type, number, label}` via a static `$wordMap` (case-insensitive
 * word -> type). Both are read STRAIGHT FROM SOURCE (comment-stripped first, the
 * `test-qr-cuercode.js`/`test-pp7-routing.js` model) — this guard never hardcodes either map's
 * contents, so it stays accurate as either file grows.
 *
 * THE AMBIGUOUS-LETTER SUBTLETY (documented in `_bulkImport_pro7GroupType()`'s own doc-block, and
 * NOT a bug this guard flags): `COMPONENT_LABEL_MAP` gives 'chorus' AND 'refrain' the SAME letter
 * 'C' (similarly 'tag'/'coda' share 'T', 'intro'/'interlude' share 'I') — a single letter cannot
 * carry two meanings, so the reverse map can only pick ONE type per letter. Both sides pick the
 * SAME rule: whichever type is listed FIRST in `COMPONENT_LABEL_MAP`'s own iteration order (chorus
 * before refrain, tag before coda, intro before interlude) is the "canonical" meaning for that
 * letter; the non-canonical type (refrain/coda/interlude) simply has NO letter-only round trip —
 * exporting "refrain" and reading back only its bare letter "C" legitimately, unavoidably, comes
 * back as "chorus". This guard's letter-closure check is therefore scoped to each letter's
 * CANONICAL (first-listed) type only, computed FROM the parsed JS map's own iteration order — never
 * a hardcoded assumption of which type "wins" a letter. The FULL `name` form has no such ambiguity
 * (all ten names are distinct strings) and is checked for every single type, no exceptions.
 *
 * Both maps are parsed as plain key/value PAIRS from source text — never executed (no `eval`, no
 * `require()` of the PHP file, no spawning `php`) — because the values being compared here
 * (bare words with no digits/suffixes attached) never need the REGEX/number/suffix-extraction
 * machinery `_bulkImport_pro7GroupType()` also does at runtime; that richer behaviour (numbers,
 * `(SDAH)`-style suffixes, unknown-word fallback) is covered against REAL fixtures by
 * `tests/php/test-pp7-parse.php` instead. This guard's only job is the STATIC map agreement.
 *
 * MUTATION-PROVEN (rule #34), performed once by hand against the real working tree, this test
 * re-run and confirmed RED, then reverted (`git diff --stat` empty before moving on):
 *   m1 — deleted the `'coda' => 'coda',` entry from `includes/song_importers.php`'s `$wordMap`
 *        (plan's own prescribed mutation) → RED: "PHP $wordMap has an entry for JS name 'Coda'
 *        (type 'coda')" — `_bulkImport_pro7GroupType('Coda')` would now fall through to the
 *        importer's documented 'refrain' fallback for any unrecognised word, silently re-typing
 *        every "Coda" section on import.
 *   m2 — changed the JS `COMPONENT_LABEL_MAP`'s `'tag'` entry's `letter` from `'T'` to `'C'`
 *        (colliding it with chorus/refrain's existing 'C', and vacating 'T') → RED, TWO assertions
 *        at once: (b) "JS canonical letter 'T' (type 'coda') resolves back … to 'coda'" fails
 *        (`PHP $wordMap['t'] = 'tag'`) — with 'tag' no longer claiming 'T', 'coda' (still letter
 *        'T', listed right after 'tag') becomes the new first-listed/canonical owner of 'T', but
 *        PHP's `'t' => 'tag'` entry was never updated to match; and (b2) the inverse check flags
 *        the same drift from the PHP side ("PHP letter 't' (-> tag) … JS canonical type for 't' is
 *        'coda', not 'tag'"). ('C' itself stays correctly canonical to 'chorus' throughout — chorus
 *        is still listed before both refrain AND the now-relettered tag, so this mutation's actual
 *        blast radius is narrower than a first guess suggests: it silently orphans 'T', not 'C'.)
 * Every mutation was reverted immediately after confirming red; the tree this test ships against is
 * unmodified.
 *
 *   node tests/test-pp7-label-roundtrip.js
 *
 * Exit 0 = the two maps agree, 1 = drift.
 *
 * @see appWeb/public_html/manage/editor/propresenter-export.js   COMPONENT_LABEL_MAP (the forward map)
 * @see appWeb/public_html/includes/song_importers.php             _bulkImport_pro7GroupType() (the reverse map)
 * @see tests/php/test-pp7-parse.php                                the richer real-fixture coverage (numbers/suffixes/unknown words)
 * @see .claude/propresenter-interop-1968-plan.md                  §11.2 (this guard's brief)
 */

import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const REPO = path.join(__dirname, '..');
const EXPORTER_PATH = path.join(REPO, 'appWeb', 'public_html', 'manage', 'editor', 'propresenter-export.js');
const IMPORTERS_PATH = path.join(REPO, 'appWeb', 'public_html', 'includes', 'song_importers.php');

let failures = 0;
function check(name, cond, detail) {
    if (cond) { console.log('  ✓ ' + name); return; }
    failures++;
    console.error('  ✗ ' + name + (detail ? '\n      ' + detail : ''));
}

const read = (p) => fs.existsSync(p) ? fs.readFileSync(p, 'utf8') : '';

/* Blank `/* … *\/` and `// …` comment bodies before scanning — the same rule-#34/
   test-fragment-inline-scripts.php lesson every tree-derived guard in this repo follows: this
   very file's own doc-block, and the PHP function's own doc-block, both MENTION map entries in
   prose ("the 'coda' entry"), and a raw scan would false-positive on that prose. We parse the
   CODE shape, not words describing it. Newlines preserved so line numbers in any error stay
   accurate. */
function stripComments(src) {
    src = src.replace(/\/\*[\s\S]*?\*\//g, (m) => m.replace(/[^\n]/g, ' '));
    src = src.replace(/(^|[^:])\/\/.*$/gm, (m) => m.replace(/\/\/.*$/, (c) => c.replace(/[^\n]/g, ' ')));
    return src;
}

console.log('ProPresenter 7+ section-label PHP<->JS lockstep:');

/* ---------------------------------------------------------------------
 * Parse the FORWARD map: COMPONENT_LABEL_MAP (propresenter-export.js)
 * ------------------------------------------------------------------- */

const exporterRaw = read(EXPORTER_PATH);
check('propresenter-export.js exists', exporterRaw !== '');
const exporterSrc = stripComments(exporterRaw);

const jsBlockMatch = exporterSrc.match(/COMPONENT_LABEL_MAP\s*=\s*\{([\s\S]*?)\n\s*\};/);
check('COMPONENT_LABEL_MAP block found in propresenter-export.js', !!jsBlockMatch);

/** @type {Array<{type:string, letter:string, name:string}>} in the map's own SOURCE iteration order */
const jsEntries = [];
if (jsBlockMatch) {
    const entryRe = /'([a-z-]+)'\s*:\s*\{\s*letter:\s*'([A-Za-z])'\s*,\s*name:\s*'([^']+)'\s*\}/g;
    let m;
    while ((m = entryRe.exec(jsBlockMatch[1])) !== null) {
        jsEntries.push({ type: m[1], letter: m[2], name: m[3] });
    }
}
check(`COMPONENT_LABEL_MAP parsed with a plausible entry count (found ${jsEntries.length}, expect >= 8)`,
    jsEntries.length >= 8,
    'parser anchor likely drifted from the map\'s real shape — see the entryRe pattern above');

/* ---------------------------------------------------------------------
 * Parse the REVERSE map: $wordMap inside _bulkImport_pro7GroupType() (song_importers.php)
 * ------------------------------------------------------------------- */

const importersRaw = read(IMPORTERS_PATH);
check('song_importers.php exists', importersRaw !== '');
const importersSrc = stripComments(importersRaw);

const phpFnMatch = importersSrc.match(/function\s+_bulkImport_pro7GroupType\s*\([^)]*\)\s*:\s*array\s*\{([\s\S]*?)\n\}/);
check('_bulkImport_pro7GroupType() function body found', !!phpFnMatch);

const phpBlockMatch = phpFnMatch
    ? phpFnMatch[1].match(/\$wordMap\s*=\s*\[([\s\S]*?)\n\s*\];/)
    : null;
check('$wordMap array literal found inside _bulkImport_pro7GroupType()', !!phpBlockMatch);

/** @type {Map<string,string>} lowercase word/letter -> iHymns type */
const phpWordMap = new Map();
if (phpBlockMatch) {
    const pairRe = /'([^']+)'\s*=>\s*'([^']+)'/g;
    let m;
    while ((m = pairRe.exec(phpBlockMatch[1])) !== null) {
        phpWordMap.set(m[1].toLowerCase(), m[2]);
    }
}
check(`$wordMap parsed with a plausible entry count (found ${phpWordMap.size}, expect >= 15)`,
    phpWordMap.size >= 15,
    'parser anchor likely drifted from the array\'s real shape — see the pairRe pattern above');

/* ---------------------------------------------------------------------
 * (a) NAME closure — every full name the exporter can emit must fold back to its OWN type.
 *     Unambiguous: COMPONENT_LABEL_MAP's ten `name` strings are all distinct, so there is exactly
 *     ONE JS type per name and this check has no "which one wins" question at all.
 * ------------------------------------------------------------------- */

console.log('\n-- (a) full `name` form closure --');
for (const { type, name } of jsEntries) {
    const key = name.toLowerCase();
    const resolved = phpWordMap.get(key);
    check(`JS name '${name}' (type '${type}') resolves back through PHP $wordMap to '${type}'`,
        resolved === type,
        `PHP $wordMap['${key}'] = ${resolved === undefined ? '(missing)' : `'${resolved}'`}`);
}

/* ---------------------------------------------------------------------
 * (b) LETTER closure — the CANONICAL (first-listed in JS source order) type per letter must fold
 *     back to that same type. Built FROM the parsed JS entries' own order — never a hardcoded
 *     "chorus/tag/intro win" assumption — so a reordering of COMPONENT_LABEL_MAP itself changes
 *     which type this check expects, exactly matching what _bulkImport_pro7GroupType()'s own
 *     doc-block says PHP's single-letter entries encode ("first-listed (canonical) meaning").
 * ------------------------------------------------------------------- */

console.log('\n-- (b) canonical single-letter form closure --');

/** @type {Map<string,string>} letter (lowercase) -> canonical iHymns type, first-seen wins */
const jsCanonicalLetter = new Map();
for (const { type, letter } of jsEntries) {
    const lower = letter.toLowerCase();
    if (!jsCanonicalLetter.has(lower)) {
        jsCanonicalLetter.set(lower, type);
    }
}
check(`at least 5 distinct canonical letters derived from COMPONENT_LABEL_MAP (found ${jsCanonicalLetter.size})`,
    jsCanonicalLetter.size >= 5);

for (const [letter, type] of jsCanonicalLetter) {
    const resolved = phpWordMap.get(letter);
    check(`JS canonical letter '${letter.toUpperCase()}' (type '${type}') resolves back through PHP $wordMap to '${type}'`,
        resolved === type,
        `PHP $wordMap['${letter}'] = ${resolved === undefined ? '(missing)' : `'${resolved}'`}`);
}

/* The inverse direction too: every PHP single-letter entry (key length 1) must correspond to a
   canonical JS letter — a PHP letter shortcut with no JS-emittable counterpart is an orphan
   (harmless to a curator typing it by hand, but a signal the two maps have drifted apart, e.g. a
   letter reassigned on the JS side with the PHP side never updated — see mutation m2 above). */
console.log('\n-- (b2) inverse — every PHP single-letter entry has a live JS-canonical counterpart --');
let phpLetterCount = 0;
for (const [key, type] of phpWordMap) {
    if (key.length !== 1) continue;
    phpLetterCount++;
    const jsType = jsCanonicalLetter.get(key);
    check(`PHP letter '${key}' (-> ${type}) has a corresponding canonical JS letter`,
        jsType === type,
        jsType === undefined
            ? `no JS type emits letter '${key}' at all`
            : `JS canonical type for '${key}' is '${jsType}', not '${type}'`);
}
check(`at least 5 PHP single-letter entries were found to cross-check (found ${phpLetterCount})`,
    phpLetterCount >= 5);

if (failures) {
    console.error(`\nFAIL: ${failures} ProPresenter 7+ label lockstep check(s) failed.`);
    process.exit(1);
}
console.log('\nOK: every name (and canonical single-letter shorthand) the ProPresenter 7+ exporter ' +
    'can emit folds back to its original iHymns section type through the importer\'s map.');
