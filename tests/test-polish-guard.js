/**
 * tests/test-polish-guard.js — keep the "looks unfinished" details from coming back
 *
 * ELI5: a 2026-10-05 review went through the site looking for the small things
 * that make an app look unfinished: link previews showing "&#039;" instead of an
 * apostrophe, every page sharing the home page's title, blank screenshots in
 * the install dialog, a menu label reading "Modern (#865)", developer notes
 * visible in "View source", buttons whose click code the browser silently
 * refuses to run. They were fixed. This test runs on every pull request so the
 * same kinds of mistake fail the build instead of reaching visitors.
 *
 * WHAT IS CHECKED (each list is read from the real files, never typed here)
 *   1. No inline event-handler attributes (onclick=, onerror=, …) in the public
 *      page templates or the public JavaScript. The site's security policy makes
 *      the browser refuse them without any visible error (CLAUDE.md rule #30),
 *      so a button looks fine and does nothing. The offline page inside the
 *      service worker is exempt: it is built in the worker with no policy.
 *   2. Every <img> written in a public page template or public JavaScript has
 *      an alt attribute (alt="" for decoration is fine).
 *   3. No issue numbers like "#865" inside visible labels (label=, title=,
 *      placeholder=, aria-label=) in the public page templates.
 *   4. No placeholder text ("lorem ipsum", "TODO", "coming soon") in the public
 *      page templates or in public JSON files people can fetch.
 *   5. The titles index.php gives fixed pages (for link previews and search
 *      results) match the titles router.js gives the same pages after loading.
 *   6. HTML comments (developer notes) are removed from what is sent: the page
 *      shell and the page-fragment path both run the shared filter.
 *   7. Every file manifest.json points at exists, and the manifest makes no
 *      promise the app doesn't keep (file / link handlers need code that
 *      receives them).
 *   8. The home page description has no song or songbook counts (they go out
 *      of date) and fits in a search-result snippet (160 characters).
 *   9. The deploy step that copies the app name into manifest.json edits JSON
 *      keys, not lines: a line-by-line edit renamed every home-screen shortcut.
 *
 * Each check was proven able to fail by putting the mistake back and watching
 * it go red (CLAUDE.md rule #34). Runs under `node tools/run-node-tests.js`.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(__dirname, '..');
const WEB = path.join(ROOT, 'appWeb/public_html');

let failures = 0;
let passes = 0;
const fail = (msg) => { failures++; console.log('  FAIL  ' + msg); };
const pass = (msg) => { passes++; console.log('  ok    ' + msg); };
const read = (p) => fs.readFileSync(p, 'utf8');
const rel = (p) => path.relative(ROOT, p);

/** Every file under `dir` (recursively) whose name matches `re`. */
function walk(dir, re) {
    const out = [];
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) { out.push(...walk(full, re)); }
        else if (re.test(entry.name)) { out.push(full); }
    }
    return out;
}

/** Remove PHP blocks and HTML comments, leaving the markup a visitor receives. */
function phpTemplateMarkup(src) {
    return src
        .replace(/<\?php[\s\S]*?(\?>|$)/g, ' ')
        .replace(/<\?=[\s\S]*?\?>/g, 'X')
        .replace(/<!--[\s\S]*?-->/g, ' ');
}

/** Remove JavaScript comments (block and line), keeping strings intact enough
 *  for the simple attribute checks below. A `//` inside a string such as a URL
 *  is protected by requiring it not to follow a colon. */
function jsWithoutComments(src) {
    return src
        .replace(/\/\*[\s\S]*?\*\//g, ' ')
        .replace(/(^|[^:\\'"`])\/\/[^\n]*/g, '$1');
}

/* The public surface: page templates, partials and the public JavaScript. */
const TEMPLATE_FILES = [
    ...walk(path.join(WEB, 'includes/pages'), /\.php$/),
    ...walk(path.join(WEB, 'includes/partials'), /\.php$/),
];
const PUBLIC_JS_FILES = [
    ...walk(path.join(WEB, 'js'), /\.m?js$/),
];
if (TEMPLATE_FILES.length < 20 || PUBLIC_JS_FILES.length < 20) {
    fail(`expected the public templates and scripts to be found (got ${TEMPLATE_FILES.length} templates, ${PUBLIC_JS_FILES.length} scripts) — has the folder moved?`);
}

/* ---- 1. inline event handlers ------------------------------------------------ */
console.log('1 — no inline event-handler attributes in public templates or scripts');
{
    const HANDLER = /<[a-z][^<>]*\son[a-z]+\s*=\s*(["'`]|\\["'])/gi;
    const hits = [];
    for (const f of TEMPLATE_FILES) {
        const m = phpTemplateMarkup(read(f)).match(HANDLER);
        if (m) { hits.push(`${rel(f)}: ${m[0].slice(0, 80)}`); }
    }
    for (const f of PUBLIC_JS_FILES) {
        const m = jsWithoutComments(read(f)).match(HANDLER);
        if (m) { hits.push(`${rel(f)}: ${m[0].slice(0, 80)}`); }
    }
    if (hits.length) { hits.forEach((h) => fail('inline handler (the browser will refuse it): ' + h)); }
    else { pass(`${TEMPLATE_FILES.length} templates and ${PUBLIC_JS_FILES.length} scripts carry no on…= attributes`); }
}

/* ---- 2. images have alt text ------------------------------------------------- */
console.log('2 — every <img> in public templates and scripts has an alt attribute');
{
    const IMG = /<img\b[^>]*>/gi;
    const hits = [];
    const scan = (file, text) => {
        for (const tag of text.match(IMG) || []) {
            /* A tag built from a variable we can't see into (e.g. `<img ${attrs}>`)
               is skipped rather than guessed at. */
            if (/\$\{\s*[a-z_]*attr/i.test(tag)) { continue; }
            if (!/\salt\s*=/i.test(tag)) { hits.push(`${rel(file)}: ${tag.slice(0, 90)}`); }
        }
    };
    /* PHP values are swapped for a placeholder first: a "?>" inside an
       attribute would otherwise end the tag early and hide its alt. */
    TEMPLATE_FILES.forEach((f) => scan(f, phpTemplateMarkup(read(f))));
    PUBLIC_JS_FILES.forEach((f) => scan(f, jsWithoutComments(read(f))));
    if (hits.length) { hits.forEach((h) => fail('image without alt text: ' + h)); }
    else { pass('all <img> tags carry alt'); }
}

/* ---- 3. no issue numbers in visible labels ----------------------------------- */
console.log('3 — no issue numbers inside visible labels in public templates');
{
    const LABEL_WITH_ISSUE = /\s(?:label|title|placeholder|aria-label)\s*=\s*"[^"]*#\d{3,}[^"]*"/gi;
    const hits = [];
    for (const f of TEMPLATE_FILES) {
        const m = phpTemplateMarkup(read(f)).match(LABEL_WITH_ISSUE);
        if (m) { hits.push(`${rel(f)}: ${m[0].trim().slice(0, 80)}`); }
    }
    if (hits.length) { hits.forEach((h) => fail('issue number shown to visitors: ' + h)); }
    else { pass('no "#123"-style issue numbers in labels'); }
}

/* ---- 4. no placeholder text ------------------------------------------------- */
console.log('4 — no placeholder text in public templates or public JSON');
{
    const PLACEHOLDER = /lorem ipsum|\bTODO\b|coming soon/i;
    const hits = [];
    for (const f of TEMPLATE_FILES) {
        const m = phpTemplateMarkup(read(f)).match(PLACEHOLDER);
        if (m) { hits.push(`${rel(f)}: "${m[0]}"`); }
    }
    const publicJson = [
        path.join(WEB, 'manifest.json'),
        ...walk(path.join(WEB, '.well-known'), /\.json$/),
    ];
    for (const f of publicJson) {
        const m = read(f).match(PLACEHOLDER);
        if (m) { hits.push(`${rel(f)}: "${m[0]}"`); }
    }
    if (hits.length) { hits.forEach((h) => fail('placeholder text a visitor can see: ' + h)); }
    else { pass(`${TEMPLATE_FILES.length} templates and ${publicJson.length} public JSON files are free of placeholders`); }
}

/* ---- 5. server titles match the router's titles ------------------------------ */
console.log('5 — fixed-page titles agree between index.php and router.js');
{
    const indexSrc = read(path.join(WEB, 'index.php'));
    const routerSrc = read(path.join(WEB, 'js/modules/router.js'));
    const serverTitles = {};
    for (const block of ['_ogStaticPages', '_ogPrivatePages']) {
        const m = indexSrc.match(new RegExp('\\$' + block + '\\s*=\\s*\\[([\\s\\S]*?)\\];'));
        if (!m) { fail(`index.php no longer defines $${block}`); continue; }
        for (const row of m[1].matchAll(/'\/([a-z-]+)'\s*=>\s*(?:\[\s*)?(['"])(.*?)\2/g)) {
            serverTitles[row[1]] = row[3];
        }
    }
    const routerTitles = {};
    for (const row of routerSrc.matchAll(/'([a-z-]+)':\s*(['"])(.*?)\2\s*\+\s*appName/g)) {
        routerTitles[row[1]] = row[3].replace(/\s+—\s*$/, '');
    }
    const routerPage = { favorites: 'favorites', setlist: 'setlist' };
    const keys = Object.keys(serverTitles);
    if (keys.length < 8) { fail(`expected the server title maps to list the fixed pages (found ${keys.length})`); }
    let mismatches = 0;
    for (const k of keys) {
        const want = routerTitles[routerPage[k] || k];
        if (want === undefined) { fail(`router.js has no title for "${k}" but index.php does`); mismatches++; continue; }
        if (want !== serverTitles[k]) { fail(`title for "${k}": index.php says "${serverTitles[k]}", router.js says "${want}"`); mismatches++; }
    }
    if (!mismatches && keys.length >= 8) { pass(`${keys.length} fixed-page titles agree`); }
}

/* ---- 6. developer comments are removed from what is sent --------------------- */
console.log('6 — HTML comments are filtered out of the shell and the fragments');
{
    const indexSrc = read(path.join(WEB, 'index.php'));
    const apiSrc = read(path.join(WEB, 'api.php'));
    const helper = path.join(WEB, 'includes/html_comment_strip.php');
    if (!fs.existsSync(helper) || !/function\s+ihymnsStripHtmlComments\s*\(/.test(read(helper))) {
        fail('includes/html_comment_strip.php must define ihymnsStripHtmlComments()');
    } else if (/\?>/.test(read(helper).replace(/^<\?php/, ''))) {
        /* A "?>" anywhere in that file (even inside a comment) ends PHP mode
           and prints the rest of the file into every page. */
        fail('includes/html_comment_strip.php contains "?>", which would print the file into every page');
    } else {
        pass('shared filter exists');
    }
    if (/ob_start\(\s*'ihymnsStripHtmlComments'\s*\)/.test(indexSrc)) { pass('index.php filters the page shell'); }
    else { fail("index.php must call ob_start('ihymnsStripHtmlComments') before it outputs anything"); }
    if (/\$body\s*=\s*ihymnsStripHtmlComments\(/.test(apiSrc)) { pass('api.php filters page fragments'); }
    else { fail('api.php must pass every page fragment through ihymnsStripHtmlComments()'); }
}

/* ---- 7. the manifest only points at real files and real features ------------- */
console.log('7 — manifest.json points at real files and promises nothing unimplemented');
{
    const manifest = JSON.parse(read(path.join(WEB, 'manifest.json')));
    const srcs = [];
    const collect = (v) => {
        if (Array.isArray(v)) { v.forEach(collect); }
        else if (v && typeof v === 'object') { for (const [k, x] of Object.entries(v)) { if (k === 'src') { srcs.push(x); } else { collect(x); } } }
    };
    collect(manifest);
    const missing = srcs.filter((s) => s.startsWith('/') && !fs.existsSync(path.join(WEB, s.split('?')[0])));
    if (missing.length) { missing.forEach((s) => fail('manifest.json points at a missing file: ' + s)); }
    else { pass(`${srcs.length} manifest image paths exist`); }

    const allJs = PUBLIC_JS_FILES.map(read).join('\n');
    if (manifest.file_handlers && !/launchQueue/.test(allJs)) {
        fail('manifest.json offers to open files ("file_handlers") but no script reads window.launchQueue');
    } else { pass('file handling is only offered if implemented'); }
    if (manifest.protocol_handlers && !/web\+ihymns/.test(allJs.replace(/manifest/g, ''))) {
        fail('manifest.json registers a link type ("protocol_handlers") that no script handles');
    } else { pass('link-type handling is only offered if implemented'); }
    for (const key of ['_comment', 'iarc_rating_id']) {
        if (key in manifest && (key === '_comment' || manifest[key] === '')) { fail(`manifest.json carries "${key}", a leftover that isn't part of the standard`); }
    }
}

/* ---- 8. the home description stays accurate and short ------------------------ */
console.log('8 — the home page description has no stale counts and fits a snippet');
{
    const ver = read(path.join(WEB, 'includes/infoAppVer.php'));
    const m = ver.match(/\["Synopsis"\]\s*=\s*"([^"]+)"/);
    if (!m) { fail('infoAppVer.php Synopsis not found'); }
    else {
        const text = m[1];
        if (/\d[\d,]*\s+(songs|songbooks|hymns)/i.test(text)) { fail(`Synopsis states a count that will go out of date: "${text}"`); }
        else { pass('no song or songbook counts'); }
        if (text.length > 160) { fail(`Synopsis is ${text.length} characters; search results cut it at about 160`); }
        else { pass(`${text.length} characters`); }
    }
}

/* ---- 9. the deploy step edits manifest keys, not lines ----------------------- */
console.log('9 — deploy copies the app name into manifest.json by key, not by line');
{
    const deploy = read(path.join(ROOT, '.github/workflows/deploy.yml'));
    const step = deploy.split(/\n\s*- name: /).find((s) => s.startsWith('Sync manifest.json from infoAppVer.php'));
    if (!step) { fail('deploy.yml no longer has the "Sync manifest.json from infoAppVer.php" step — update this check'); }
    else if (/sed\s+-i[^\n]*\$MANIFEST/.test(step)) {
        fail('the manifest sync step uses sed on manifest.json; sed edits every matching line, including each home-screen shortcut');
    } else if (!/json\.load/.test(step)) {
        fail('the manifest sync step should read manifest.json as JSON and set the top-level keys by name');
    } else { pass('manifest keys are set by name'); }
}

/* ---- verdict ------------------------------------------------------------------ */
console.log('');
if (failures) {
    console.log(`polish-guard: ${failures} check(s) failed, ${passes} passed.`);
    process.exit(1);
}
console.log(`polish-guard: all ${passes} checks passed.`);
process.exit(0);
