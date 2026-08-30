/**
 * tests/test-pp7-newline-units-lockstep.js — PP7 chord-offset NEWLINE_UNITS
 * cross-file lockstep guard (SHOULD-harness §4 item 6, 2026-08-30
 * correctness review).
 *
 * ELI5
 * ----
 * A ProPresenter chord's position is a single character-offset number that
 * has to mean the SAME thing on both sides of a round trip: the number
 * `js/…/propresenter-export.js` WRITES when it turns "chord C at code-point
 * column 12" into a raw offset, and the number `includes/song_importers.php`
 * READS when it turns that same raw offset back into a code-point column,
 * must agree on how much a LINE BREAK itself counts for. Both files encode
 * that as "1 extra unit per newline" via their own numeric constant
 * (`NEWLINE_UNITS` in the JS exporter, `PP7_CHORD_NEWLINE_UNITS` in the PHP
 * importer) — this file proves those two numbers are actually the SAME
 * number, on every run, rather than merely both currently reading "1".
 *
 * WHY THIS GAP WASN'T ALREADY CLOSED
 * ------------------------------------
 * `tests/test-propresenter-export.js` and `tests/php/test-pp7-chord-import.php`
 * each independently exercise a chord placed after a supplementary-plane
 * emoji (correctly — see both files) — but each one compares its OWN side's
 * output against a value the TEST ITSELF hard-codes as "correct", never
 * against what the OTHER language's file actually contains. Two files each
 * independently matching their own author's expectation is not the same
 * claim as "the two files agree with each other" (rule #35's exact point:
 * cross-file agreement needs a MECHANISM, not two people separately getting
 * their own sum right). If a future edit changed ONLY one of the two
 * constants — say, from a real chord-bearing sample surfacing evidence that
 * the newline convention should be 0 instead of 1 (the "one open D4
 * question" both files' own doc-blocks flag) — and that edit updated only
 * the file it was touching, EACH existing test would keep passing (each
 * still matches ITS OWN hard-coded expectation), while every real chord
 * placed after a line break in an actual .pro file would silently land one
 * column off. This file is the missing mechanism.
 *
 * MUTATION-TESTING PROTOCOL (rule #34)
 * -------------------------------------
 * Proven able to fail by re-running the SAME extraction/comparison against
 * an in-memory MUTATED COPY of one file's source text (the tracked files are
 * never touched) with its constant's value changed — the guard must flip
 * from PASS to FAIL.
 *
 * @see appWeb/public_html/manage/editor/propresenter-export.js  NEWLINE_UNITS
 * @see appWeb/public_html/includes/song_importers.php            PP7_CHORD_NEWLINE_UNITS
 * @see tests/test-propresenter-export.js                          the export-side emoji/offset tests
 * @see tests/php/test-pp7-chord-import.php                        the import-side emoji/offset tests
 * @see .claude/CLAUDE.md rule #47                                 "the one open newline-weight question"
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const REPO_ROOT = path.join(__dirname, '..');
const EXPORT_JS_PATH = path.join(REPO_ROOT, 'appWeb', 'public_html', 'manage', 'editor', 'propresenter-export.js');
const IMPORT_PHP_PATH = path.join(REPO_ROOT, 'appWeb', 'public_html', 'includes', 'song_importers.php');

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

/** Extract the exporter's `var NEWLINE_UNITS = <n>;` numeric literal.
 *  @param {string} src the export.js source text (real or mutated).
 *  @returns {number|null} the literal value, or null if the declaration isn't found. */
function extractExportNewlineUnits(src) {
    const m = src.match(/var\s+NEWLINE_UNITS\s*=\s*(-?\d+)\s*;/);
    return m ? Number(m[1]) : null;
}

/** Extract the importer's `const PP7_CHORD_NEWLINE_UNITS = <n>;` numeric literal.
 *  @param {string} src the song_importers.php source text (real or mutated).
 *  @returns {number|null} the literal value, or null if the declaration isn't found. */
function extractImportNewlineUnits(src) {
    const m = src.match(/const\s+PP7_CHORD_NEWLINE_UNITS\s*=\s*(-?\d+)\s*;/);
    return m ? Number(m[1]) : null;
}

function main() {
    console.log('\nPP7 chord-offset NEWLINE_UNITS cross-file lockstep guard\n');

    const exportSrc = fs.readFileSync(EXPORT_JS_PATH, 'utf8');
    const importSrc = fs.readFileSync(IMPORT_PHP_PATH, 'utf8');

    const exportVal = extractExportNewlineUnits(exportSrc);
    const importVal = extractImportNewlineUnits(importSrc);

    assert(exportVal !== null, 'propresenter-export.js declares `var NEWLINE_UNITS = <n>;` (sanity: the pattern this guard looks for still exists)');
    assert(importVal !== null, 'song_importers.php declares `const PP7_CHORD_NEWLINE_UNITS = <n>;` (sanity: the pattern this guard looks for still exists)');
    assert(exportVal !== null && importVal !== null && exportVal === importVal,
        `the two files' newline-unit constants agree (export=${exportVal}, import=${importVal})`);

    console.log('\n--- MUTATION PROOF: a lone edit to ONE file\'s constant is caught ---');

    // Mutate ONLY the exporter's constant (never touching the tracked file —
    // this is a string held in memory) and re-run the SAME extraction +
    // comparison against it, mirroring what a real one-sided edit would do.
    const mutatedExportSrc = exportSrc.replace(
        /var\s+NEWLINE_UNITS\s*=\s*-?\d+\s*;/,
        'var NEWLINE_UNITS = 0; // MUTATED: newline-unit convention changed on only ONE side'
    );
    assert(mutatedExportSrc !== exportSrc,
        'MUTATION setup sanity: the exporter constant replacement actually matched real source');
    const mutatedExportVal = extractExportNewlineUnits(mutatedExportSrc);
    assert(mutatedExportVal !== null && mutatedExportVal !== importVal,
        `MUTATION PROOF: changing ONLY the exporter's constant (now ${mutatedExportVal}) while the importer stays ${importVal} is detected as a disagreement`);

    // And the symmetric case: mutate ONLY the importer's constant.
    const mutatedImportSrc = importSrc.replace(
        /const\s+PP7_CHORD_NEWLINE_UNITS\s*=\s*-?\d+\s*;/,
        'const PP7_CHORD_NEWLINE_UNITS = 2; // MUTATED: newline-unit convention changed on only ONE side'
    );
    assert(mutatedImportSrc !== importSrc,
        'MUTATION setup sanity: the importer constant replacement actually matched real source');
    const mutatedImportVal = extractImportNewlineUnits(mutatedImportSrc);
    assert(mutatedImportVal !== null && mutatedImportVal !== exportVal,
        `MUTATION PROOF: changing ONLY the importer's constant (now ${mutatedImportVal}) while the exporter stays ${exportVal} is detected as a disagreement`);

    console.log(`\n=== ${checks} checks, ${failures} failed ===`);
    process.exit(failures === 0 ? 0 : 1);
}

main();
