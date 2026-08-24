/**
 * iHymns — copyright-statement PHP<->JS lockstep guard (#1862, epic #1863)
 *
 * ELI5
 * ----
 * The song page and the Editor2 metadata tab must show the EXACT same
 * "Displayed as: …" sentence for a given (years, holder, legacy) triple. One
 * side is PHP (`ihymns_copyright_statement()`), the other is JavaScript
 * (`ihymnsCopyrightPreview()`, exported from metadata-tab.js). This file
 * proves the JS side against the SAME fixture list the PHP side's own truth
 * table (tests/php/test-editor2-metadata-1862.php) replays — a shared JSON
 * file, not two people typing the same cases twice (rule #35 — "a comment
 * saying keep these in sync is the failure, not the fix").
 *
 * WHY A SEPARATE FILE, NOT A DUPLICATE FOLD
 * ------------------------------------------
 * metadata-tab.js's `ihymnsCopyrightPreview()` IS the fold this file
 * imports and runs — there is no second implementation here to drift from
 * the real one. If a future edit changes the precedence in metadata-tab.js
 * without updating includes/copyright_display.php (or vice versa), EITHER
 * this test (against the shared fixtures) or the PHP truth table goes red,
 * because both sides replay the identical case list.
 *
 * PROVEN ABLE TO FAIL: flip the precedence in metadata-tab.js's
 * ihymnsCopyrightPreview() (legacy-wins instead of split-wins) and re-run —
 * every "both present" / "legacy alone" case mismatches. Reverted after
 * confirming (see the session report for the transcript).
 *
 *   node tests/test-copyright-preview-lockstep.js
 *
 * @see appWeb/public_html/manage/editor/v2/metadata-tab.js  ihymnsCopyrightPreview()
 * @see appWeb/public_html/includes/copyright_display.php    ihymns_copyright_statement() — the PHP twin
 * @see tests/fixtures/copyright-statement-cases.json         the ONE shared truth table
 * @see tests/php/test-editor2-metadata-1862.php              the PHP side of this same guard
 * @see #1862, epic #1863
 */

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { ihymnsCopyrightPreview } from '../appWeb/public_html/manage/editor/v2/metadata-tab.js';

const __dirname = dirname(fileURLToPath(import.meta.url));
const ROOT = join(__dirname, '..');

let passed = 0;
let failed = 0;

function check(label, cond) {
    if (cond) {
        passed++;
    } else {
        failed++;
        console.error('  FAIL: ' + label);
    }
}

console.log('#1862 — copyright-statement PHP<->JS lockstep\n');

const fixturesPath = join(ROOT, 'tests', 'fixtures', 'copyright-statement-cases.json');
const fixtures = JSON.parse(readFileSync(fixturesPath, 'utf8'));
const cases = Array.isArray(fixtures.cases) ? fixtures.cases : [];

check('parsed at least 8 fixture cases (per the #1862 test plan)', cases.length >= 8);

for (const c of cases) {
    const got = ihymnsCopyrightPreview(c.years, c.holder, c.legacy);
    check('copyright fold: ' + c.label + ' (got ' + JSON.stringify(got) + ', want ' + JSON.stringify(c.expected) + ')', got === c.expected);
}

console.log('\n' + passed + ' passed, ' + failed + ' failed');
if (failed > 0) {
    console.error('\nThe JS fold (metadata-tab.js ihymnsCopyrightPreview()) disagrees with the');
    console.error('shared fixtures the PHP fold (ihymns_copyright_statement()) also replays.');
    console.error('Fix the JS side to match the PHP precedence — never the reverse (song.php');
    console.error('and the public/native API contract are the source of truth, #1750/#4).');
    process.exit(1);
}
console.log('\nBoth folds agree on every shared fixture case.');
process.exit(0);
