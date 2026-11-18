/**
 * tests/test-no-brotli.js — iHymns never unpacks Brotli data it did not make itself
 *
 * PURPOSE
 * ELI5: Brotli is a way of squashing web data small. The part of Brotli that
 * UNPACKS it had a serious flaw (CVE-2026-111497, nicknamed "HTTP2Shell",
 * #2157): a deliberately broken piece of Brotli data could make a program
 * write outside its own memory, and in the worst case run an attacker's code.
 * The flaw only matters when a program unpacks Brotli that someone else
 * controls. iHymns has no reason to do that, so this check makes sure nothing
 * in the repository ever starts doing it, by accident or on purpose.
 *
 * DETAIL
 * The review for #2157 found one real way in: the PHP song importers told cURL
 * `CURLOPT_ENCODING => ''`, which means "accept every compression this cURL
 * was built with". If that cURL includes Brotli, it unpacks Brotli replies from
 * the other website automatically. They now ask for `'gzip, deflate'` only.
 * This guard keeps it that way:
 *
 *   1. EVERY tracked PHP file: a `CURLOPT_ENCODING` / `CURLOPT_ACCEPT_ENCODING`
 *      value must be a plain string literal, must not be empty (empty = accept
 *      everything), and must not name `br`. A value that is not a literal
 *      (a variable, a constant) fails too, because the guard cannot read it.
 *      Not setting the option at all is fine: cURL then asks for nothing and
 *      unpacks nothing.
 *   2. NO tracked PHP / JavaScript / Python / PowerShell source calls a Brotli
 *      unpacker (`brotli_uncompress`, `brotliDecompress`, `createBrotliDecompress`,
 *      `brotli.decompress`, `DecompressionStream('br')` ...). Brotli CREATING
 *      (compressing) is not banned; the flaw is only in unpacking.
 *
 * What is NOT covered, and why: a browser, an operating system or a runtime
 * (Node.js, .NET, Apple's URLSession) unpacking Brotli on its own is outside
 * this repository's code. The fix there is to keep those updated. See
 * `.claude/analysis/cve-2026-111497-review-2026-11-18.md`.
 *
 * Derived from the tree (`git ls-files`), never from a typed list of files,
 * and mutation-proven: set an importer's option back to '' or add a
 * `brotliDecompress(` call and this goes red; the current tree is green.
 *
 *   node tests/test-no-brotli.js
 *
 * Exit 0 = clean, 1 = a Brotli unpacking path (or an unreadable option) exists.
 *
 * @see https://nvd.nist.gov/vuln/detail/CVE-2026-111497
 * @see https://www.php.net/manual/en/function.curl-setopt.php  (CURLOPT_ENCODING)
 * @see #2157
 */

import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const REPO = path.join(__dirname, '..');

let failures = 0;
function check(name, cond, detail) {
    if (cond) { console.log('  ✓ ' + name); return; }
    failures++;
    console.error('  ✗ ' + name + (detail ? '\n      ' + detail : ''));
}

/* The files git tracks. `-z` so a name with spaces or accents cannot split. */
const tracked = execFileSync('git', ['ls-files', '-z'], { cwd: REPO, encoding: 'utf8' })
    .split('\0').filter(Boolean);

/* Third-party libraries we ship but did not write, notes and docs that talk
   ABOUT Brotli, and this file itself. Only these are skipped. */
const SKIP = [
    /^appWeb\/private_html\/lib\//,   /* vendored PDF library (outside every docroot) */
    /(^|\/)vendor\//,
    /(^|\/)node_modules\//,
    /^\.claude\//, /^\.OpenAI\//, /^wiki\//,
    /\.md$/,
    /^tests\/test-no-brotli\.js$/,
];
const scanned = tracked.filter((f) => !SKIP.some((re) => re.test(f)));

const read = (f) => {
    try { return fs.readFileSync(path.join(REPO, f), 'utf8'); } catch { return ''; }
};

/* 1. Every PHP cURL encoding option is a literal that does not accept Brotli. */
console.log('cURL encoding options (every tracked .php file):');
/* A quoted string is read whole (it may contain a comma: 'gzip, deflate'); anything else
   is read up to the next , ) ] or ; so a variable or constant is still seen and reported. */
const OPTION = /CURLOPT_(?:ACCEPT_)?ENCODING\s*(?:=>|,)\s*((['"])[^'"\n]*\2|[^,)\]\n;]*)/g;
let optionCount = 0;
for (const f of scanned.filter((p) => p.endsWith('.php'))) {
    const src = read(f);
    let m;
    OPTION.lastIndex = 0;
    while ((m = OPTION.exec(src)) !== null) {
        optionCount++;
        const raw = m[1].trim();
        const lit = /^(['"])(.*)\1$/.exec(raw);
        const line = src.slice(0, m.index).split('\n').length;
        const where = f + ':' + line;
        if (!lit) {
            check(where + ' value is a plain string', false,
                'Found `' + raw + '`. Use a string literal such as \'gzip, deflate\' so this guard can read it.');
        } else if (lit[2].trim() === '') {
            check(where + ' is not empty', false,
                "An empty value means \"accept every compression cURL knows\", which includes Brotli. Use 'gzip, deflate'.");
        } else if (/(^|[\s,;])br(?=[\s,;]|$)/i.test(lit[2])) {
            check(where + ' does not ask for br', false, 'Found `' + lit[2] + '`. Remove `br`.');
        } else {
            check(where + ' asks only for ' + lit[2], true);
        }
    }
}
check('found at least one cURL encoding option to check (the importers set them)', optionCount > 0,
    'Zero options found: the pattern no longer matches the tree, so this guard would pass while checking nothing.');

/* 2. No Brotli unpacker is called from our own source. */
console.log('Brotli unpackers in our own source:');
const UNPACKERS = [
    /\bbrotli_uncompress(?:_init|_add)?\s*\(/i,
    /\bbrotli[_.]?decompress\w*\s*\(/i,
    /\bcreateBrotliDecompress\b/,
    /\bBrotliDecoder\w*/,
    /\bDecompressionStream\s*\(\s*['"]br['"]/,
    /\bbrotli\.decompress\b/i,
];
const sources = scanned.filter((p) => /\.(php|js|mjs|cjs|py|ps1)$/.test(p));
const hits = [];
for (const f of sources) {
    const src = read(f);
    for (const re of UNPACKERS) {
        const m = re.exec(src);
        if (m) hits.push(f + ':' + (src.slice(0, m.index).split('\n').length) + '  ' + m[0]);
    }
}
check('no source file calls a Brotli unpacker (' + sources.length + ' files scanned)', hits.length === 0,
    hits.join('\n      '));
check('the scan covered a real number of files', sources.length > 50,
    'Only ' + sources.length + ' files were scanned, so the file list is probably wrong.');

if (failures > 0) {
    console.error('\n' + failures + ' check(s) failed.');
    process.exit(1);
}
console.log('\nAll checks passed.');
