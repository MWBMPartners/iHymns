/**
 * iHymns — Node Test Suite Runner
 * ================================
 *
 * Runs every top-level `tests/*.js` file under `node` and fails the
 * process if any of them do. This is the ONE list of "which node tests
 * exist" — `npm test` (local) and CI's "Node unit tests" step both call
 * this same script, so they can never drift apart again.
 *
 * HISTORY (#1631 item 5):
 * Before this script, `npm test` ran exactly one file
 * (tests/test-song-fixture-shape.js) and .github/workflows/test.yml's
 * "Node unit tests" step hardcoded a DIFFERENT seven files. Neither list
 * was a subset of the other, so "tests pass locally" and "tests pass in
 * CI" verified disjoint things, and — because both lists were hand-
 * maintained — new test files (15 of them, by the time this was found)
 * were added to tests/ without ever being wired into either runner. A
 * glob has no such "forgot to add the new file" failure mode: any file
 * matching `tests/*.js` runs, full stop.
 *
 * SCOPE: only `tests/*.js` (this directory, non-recursive). PHP tests
 * live under tests/php/*.php and run separately via `php -l` +
 * dedicated `php tests/php/test-*.php` invocations (see test.yml) —
 * this script does not touch those. Fixtures under tests/fixtures/ are
 * data, not tests, and the glob's `*.js` (not `**\/*.js`) already
 * excludes tests/php/ by construction.
 *
 * Usage:
 *   node tools/run-node-tests.js
 *   npm test
 *
 * Exit status: 0 if every test file exits 0, 1 if any file exits
 * non-zero (or fails to spawn).
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const PROJECT_ROOT = path.resolve(__dirname, '..');
const TESTS_DIR = path.join(PROJECT_ROOT, 'tests');

/* Non-recursive: only files directly inside tests/, matching *.js.
   Sorted for a stable, reproducible run order. */
const testFiles = fs.readdirSync(TESTS_DIR)
    .filter((name) => name.endsWith('.js'))
    .sort();

if (testFiles.length === 0) {
    console.error(`No test files found in ${TESTS_DIR} — that's almost certainly a bug in this runner, not an empty suite.`);
    process.exit(1);
}

console.log('');
console.log('🧪 iHymns — Node Test Suite');
console.log('══════════════════════════════════════════════════');
console.log(`Found ${testFiles.length} file(s) in tests/*.js`);
console.log('');

let passed = 0;
let failed = 0;
const failures = [];

for (const name of testFiles) {
    const fullPath = path.join(TESTS_DIR, name);
    console.log(`▶ ${name}`);

    const result = spawnSync(process.execPath, [fullPath], {
        stdio: 'inherit',
        cwd: PROJECT_ROOT
    });

    /* `status` is null when the process was killed by a signal rather
       than exiting normally — treat that as a failure too. */
    const ok = result.status === 0 && !result.error;

    if (ok) {
        passed++;
    } else {
        failed++;
        failures.push(name);
        if (result.error) {
            console.log(`  ❌ ${name} failed to spawn: ${result.error.message}`);
        }
    }
    console.log('');
}

console.log('══════════════════════════════════════════════════');
console.log(`✅ Passed: ${passed}`);
console.log(`❌ Failed: ${failed}`);
console.log(`📊 Total:  ${testFiles.length}`);

if (failures.length > 0) {
    console.log('');
    console.log('Failing files:');
    for (const name of failures) {
        console.log(`  ❌ ${name}`);
    }
}

console.log('');

process.exit(failed > 0 ? 1 : 0);
