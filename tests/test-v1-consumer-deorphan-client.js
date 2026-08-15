/**
 * iHymns — v1 editor api.php consumer de-orphan: CLIENT behaviour pins (#1629)
 *
 * ELI5: the PHP-side guard (tests/php/test-v1-consumer-deorphan.php) checks
 * that four things stopped pointing at the retiring v1 editor API. This file
 * is its client-behaviour companion — it pins WHAT the browser actually does
 * once it gets there: which URL a picker source function builds, what a
 * click handler's fetch payload looks like, so a future refactor that keeps
 * the right URL string somewhere in the file but disconnects it from the
 * actual call site still fails a test.
 *
 * Source-analysis only (same shape as tests/test-event-names.js and
 * tests/test-api-client-usage.js) — no DOM, no jsdom, no browser. Every
 * assertion strips comments before matching, for the same reason those
 * files' own doc-comments state: this file's own #1629 history prose quotes
 * OLD strings ("bulk_import_status", "in the editor") for context, and a
 * naive raw-source match would either falsely fail on that quoted history or
 * falsely pass a regression hidden behind an unrelated comment mention.
 *
 * @see tests/php/test-v1-consumer-deorphan.php  the server-side companion guard
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const REPO_ROOT = path.resolve(__dirname, '..');
const WEB_ROOT = path.join(REPO_ROOT, 'appWeb', 'public_html');

let passed = 0;
let failed = 0;
function check(name, ok, detail) {
    if (ok) {
        passed++;
        console.log(`  PASS  ${name}`);
    } else {
        failed++;
        console.log(`  FAIL  ${name}`);
        if (detail) console.log(`        ${detail}`);
    }
}

/**
 * Strip `//` and `/* *\/` comments while preserving string-literal CONTENT
 * (several assertions below need to find their target text INSIDE a quote),
 * with a regex-literal-aware `/` handler so a pattern containing a quote
 * character (bulk-import-progress.js's `.replace(/"/g, …)`) can't be
 * mistaken for the start of a string and derail everything after it —
 * this is the exact bug this scanner was built to survive; see the
 * self-test at the bottom of this file for the pinned regression case, and
 * the PHP companion guard's identical function for the fuller writeup.
 *
 * @param {string} src
 * @returns {string}
 */
function stripComments(src) {
    let out = '';
    const n = src.length;
    let i = 0;
    let state = 'code'; // code | block | line | sq | dq | tpl
    let prevSig = '';
    const regexPrecedingChars = new Set(['(', ',', '=', ':', '[', '!', '&', '|', '?', '{', '}', ';', '\n', '']);

    while (i < n) {
        const c = src[i];
        const c2 = i + 1 < n ? src[i + 1] : '';

        if (state === 'code') {
            if (c === '/' && c2 === '*') { state = 'block'; out += '  '; i += 2; continue; }
            if (c === '/' && c2 === '/') { state = 'line';  out += '  '; i += 2; continue; }
            if (c === '/' && regexPrecedingChars.has(prevSig)) {
                let j = i + 1;
                let inClass = false;
                while (j < n) {
                    const rc = src[j];
                    if (rc === '\\' && j + 1 < n) { j += 2; continue; }
                    if (rc === '[') { inClass = true; j++; continue; }
                    if (rc === ']') { inClass = false; j++; continue; }
                    if (rc === '/' && !inClass) { j++; break; }
                    if (rc === '\n') { break; } // malformed / not actually a regex — bail
                    j++;
                }
                while (j < n && /[a-zA-Z]/.test(src[j])) { j++; }
                out += src.slice(i, j);
                prevSig = src[j - 1];
                i = j;
                continue;
            }
            if (c === "'") { state = 'sq';  out += c; i++; continue; }
            if (c === '"') { state = 'dq';  out += c; i++; continue; }
            if (c === '`') { state = 'tpl'; out += c; i++; continue; }
            out += c;
            if (!/\s/.test(c)) { prevSig = c; }
            i++;
            continue;
        }

        if (state === 'block') {
            if (c === '*' && c2 === '/') { state = 'code'; out += '  '; i += 2; continue; }
            out += c === '\n' ? '\n' : ' ';
            i++;
            continue;
        }

        if (state === 'line') {
            if (c === '\n') { state = 'code'; out += '\n'; i++; continue; }
            out += ' ';
            i++;
            continue;
        }

        // sq / dq / tpl — copy string content verbatim; only escapes + the closer are special.
        const closers = { sq: "'", dq: '"', tpl: '`' };
        const closer = closers[state];
        if (c === '\\' && i + 1 < n) { out += c + src[i + 1]; i += 2; continue; }
        if (c === closer) { state = 'code'; out += c; prevSig = c; i++; continue; }
        out += c;
        i++;
    }
    return out;
}

function readStripped(relPath) {
    const full = path.join(WEB_ROOT, relPath);
    if (!fs.existsSync(full)) {
        console.error(`FATAL: expected file not found: ${full}`);
        process.exit(1);
    }
    return stripComments(fs.readFileSync(full, 'utf8'));
}

/* ======================================================================
 * 1. Content Restrictions picker — PICKER_SOURCES url builders
 * ====================================================================== */
console.log('1. renderEntityPicker.js PICKER_SOURCES (user / organisation):');

const pickerJs = readStripped('manage/includes/renderEntityPicker.js');

check(
    "PICKER_SOURCES.user's url() builds an api2 user_search URL (extensionless — #1855)",
    /user\s*:\s*\{[\s\S]{0,400}?\/manage\/editor\/api2\?action=user_search/.test(pickerJs),
    "expected the 'user' source's url() function body to reference '/manage/editor/api2?action=user_search' within ~200 chars of its 'user:' key"
);
check(
    "PICKER_SOURCES.organisation's url() builds an api2 org_search URL (extensionless — #1855)",
    /organisation\s*:\s*\{[\s\S]{0,400}?\/manage\/editor\/api2\?action=org_search/.test(pickerJs),
    "expected the 'organisation' source's url() function body to reference '/manage/editor/api2?action=org_search' within ~200 chars of its 'organisation:' key"
);
check(
    'no PICKER_SOURCES entry still targets the bare (v1) /manage/editor/api path',
    !/\/manage\/editor\/api\?action=(user|org)_search/.test(pickerJs),
    'found a v1-shaped URL (no ".php", no "2") still wired into a picker source'
);

/* ======================================================================
 * 2. Bulk-import stale-job poller fallback URL
 * ====================================================================== */
console.log('\n2. bulk-import-progress.js pollOnce() fallback URL:');

const bulkImportJs = readStripped('js/modules/bulk-import-progress.js');

check(
    'pollOnce() fallback URL template targets api2 import_zip_status with a job_id param (extensionless — #1855)',
    /activeJob\.pollUrl[\s\S]{0,200}?\/manage\/editor\/api2\?action=import_zip_status&job_id=/.test(bulkImportJs),
    "expected the `activeJob.pollUrl || (...)` fallback expression to build '/manage/editor/api2?action=import_zip_status&job_id=' + activeJob.jobId"
);
check(
    'the retired v1 action name is not reachable from code (comments stripped)',
    !bulkImportJs.includes('bulk_import_status'),
    'bulk_import_status still appears outside of a stripped comment'
);
check(
    'apiFetch (not a bare fetch) is what issues the poll request',
    /apiFetch\s*\(\s*url\s*,/.test(bulkImportJs),
    'expected pollOnce() to call apiFetch(url, …) — CLAUDE.md rule #31 bans a bare fetch() for a cross-cutting request'
);

/* ======================================================================
 * 3. /manage/duplicate-songs — Unlink button wiring + payload shape
 * ====================================================================== */
console.log('\n3. duplicate-songs.php inline client script — Unlink wiring:');

const dupSongsPhp = readStripped('manage/duplicate-songs.php');

check(
    'a .dup-unlink-btn click listener is wired',
    /document\s*\.\s*querySelectorAll\s*\(\s*['"]\.dup-unlink-btn['"]\s*\)\s*\.\s*forEach/.test(dupSongsPhp),
    "expected `document.querySelectorAll('.dup-unlink-btn').forEach(...)` in the inline <script>"
);
check(
    "the Unlink click handler posts { action: 'unlink', song_id: songId } via the shared postAction() helper",
    /postAction\s*\(\s*\{\s*action\s*:\s*['"]unlink['"]\s*,\s*song_id\s*:\s*songId\s*\}\s*\)/.test(dupSongsPhp),
    "expected postAction({ action: 'unlink', song_id: songId }) inside the .dup-unlink-btn handler — payload shape must match what duplicate-songs.php's PHP dispatcher reads ($_POST['song_id'])"
);
check(
    'the Unlink handler reads the song id off btn.dataset.songId (matching the data-song-id attribute the PHP row-renderer emits)',
    /btn\s*\.\s*dataset\s*\.\s*songId/.test(dupSongsPhp),
    'expected the handler to read btn.dataset.songId'
);
check(
    'the PHP row-renderer emits a data-song-id attribute for the Unlink button (so dataset.songId above actually resolves)',
    /data-song-id=/.test(dupSongsPhp),
    'expected a data-song-id="…" attribute in the PHP-rendered row markup'
);

/* ======================================================================
 * 4. Admin Songbooks export button — repointed fetch
 * ====================================================================== */
console.log('\n4. songbooks.php inline client script — bundle-export fetch:');

const songbooksPhp = readStripped('manage/songbooks.php');

check(
    'the .songbook-export-btn click handler fetches the public /api?action=songbook_export endpoint',
    /fetch\s*\(\s*\n?\s*['"]\/api\?action=songbook_export&abbr=['"]/.test(songbooksPhp),
    "expected fetch('/api?action=songbook_export&abbr=' + encodeURIComponent(abbr), …) in the export click handler"
);
check(
    'the export handler no longer fetches the retiring editor endpoint',
    !songbooksPhp.includes('/manage/editor/api.php?action=songbook_export')
        && !songbooksPhp.includes('/manage/editor/api2.php?action=songbook_export'),
    'found a fetch() still targeting an editor-hosted songbook_export endpoint (v1 or v2)'
);

/* ======================================================================
 * Self-test — stripComments() can both fire and stay silent (the #1581
 * precedent this repo's own guards cite: an assertion that can't fail
 * proves nothing).
 * ====================================================================== */
console.log('\nSelf-test — stripComments() correctness:');

check('a /* */ comment is blanked', !stripComments('/* mentions bulk_import_status */ x = 1;').includes('bulk_import_status'));
check('a // line comment is blanked', !stripComments('// says in the editor\nx = 1;').includes('in the editor'));
check('a string literal survives stripping', stripComments("var u = '/manage/editor/api2.php?action=user_search';").includes('/manage/editor/api2.php?action=user_search'));
check(
    'a regex literal containing a quote (.replace(/"/g, …)) does not derail subsequent string tracking — '
        + "the exact bug this scanner was built against (bulk-import-progress.js's escapeHtml())",
    (() => {
        const sample = '.replace(/"/g, \'&quot;\');\n/* mentions bulk_import_status here, AFTER the tricky regex */\nx = 1;';
        const stripped = stripComments(sample);
        return !stripped.includes('bulk_import_status') && stripped.includes("'&quot;'");
    })()
);

console.log('');
console.log(`${passed} passed, ${failed} failed`);
process.exit(failed === 0 ? 0 : 1);
