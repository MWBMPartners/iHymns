/**
 * iHymns — the SPA must show a server's error explanation (#1705)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * WHAT IS BEING PINNED
 * --------------------
 * `router.js`'s `loadPage()` used to do `if (!response.ok) throw` without ever
 * reading the body, so its catch block rendered:
 *
 *     "Failed to load page. Please check your connection and try again."
 *
 * Six surfaces send a themed, accessible error card with a helpful message and
 * action buttons, and a truthful status — `pages/song.php` (410 for a removed
 * song, 404 otherwise), `songbook.php`, `person.php`, `work.php`, `tag.php`, and
 * `maintenance.php`'s 503. **Not one had ever been seen by a user.** They render
 * perfectly in curl and the client discarded every one.
 *
 * The old fallback was not merely generic, it was WRONG: it blamed the reader's
 * network for a server that had answered clearly. Someone following an old link
 * to a merged song was told to check their wifi.
 *
 * WHY THIS FILE CALLS A FUNCTION INSTEAD OF READING SOURCE
 * -------------------------------------------------------
 * Because "should this body be injected?" is a pure function of the status, the
 * content type and whether a body exists — so it IS one, and this file calls it.
 * Five consecutive rounds on this branch shipped a guard that was green while the
 * thing it guarded was broken, every one because source inspection was used as
 * evidence for something a test could have called
 * (`tests/php/test-transaction-fatal.php` documents the regress in full).
 *
 * THE CASE THAT MATTERS MOST is the errored **JSON** response. Injecting that
 * into the DOM would show a reader `{"error":"..."}` — worse than the generic
 * alert it replaced. A well-meaning simplification to "any error body with
 * content" would reintroduce exactly that, so it is asserted from several angles.
 *
 * USAGE: node tests/test-error-response.js
 *
 * @see appWeb/public_html/js/utils/error-response.js  the decision
 * @see appWeb/public_html/js/modules/router.js        the one consumer
 */

import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath } from 'node:url';
import { shouldRenderErrorBody } from '../appWeb/public_html/js/utils/error-response.js';

const __filename = fileURLToPath(import.meta.url);
const __dirname  = path.dirname(__filename);
const ROOT       = path.resolve(__dirname, '..');

let passed = 0;
let failed = 0;
const failures = [];

function check(name, ok, detail) {
    if (ok) {
        passed++;
        console.log(`  PASS  ${name}`);
    } else {
        failed++;
        failures.push({ name, detail });
        console.log(`  FAIL  ${name}`);
        if (detail) console.log(`        ${detail}`);
    }
}

const HTML = 'text/html; charset=UTF-8';
const JSON_CT = 'application/json';

console.log('1 — a server that explained itself gets shown');

check('a 404 with an HTML body IS rendered',
    shouldRenderErrorBody(404, HTML, 900) === true,
    'the "Song not found" card is discarded again, and the reader is told to check their connection');

check('a 410 with an HTML body IS rendered — the #1694 tombstone case',
    shouldRenderErrorBody(410, HTML, 900) === true,
    'a removed song answers 410 with a card explaining it was merged or withdrawn; without this the '
    + 'reader sees a network warning instead');

check('a 503 with an HTML body IS rendered — the maintenance page',
    shouldRenderErrorBody(503, HTML, 400) === true);

check('a 500 with an HTML body IS rendered',
    shouldRenderErrorBody(500, HTML, 400) === true);

check('the whole 4xx/5xx range is eligible, not a hardcoded list of statuses',
    [400, 401, 403, 405, 409, 413, 422, 429, 451, 502, 599]
        .every(s => shouldRenderErrorBody(s, HTML, 100) === true),
    'a status the app starts emitting later must not need this file edited — that is the '
    + 'hardcoded-list failure rule #34 exists to prevent');

console.log('\n2 — the cases that must NOT be injected');

check('an errored JSON response is NOT rendered',
    shouldRenderErrorBody(404, JSON_CT, 60) === false,
    'THE most important assertion here: injecting a JSON body shows the reader {"error":"…"}, '
    + 'which is worse than the generic alert it replaced');

check('…nor a JSON 500',
    shouldRenderErrorBody(500, JSON_CT, 120) === false);

check('…nor any other non-HTML type',
    ['text/plain', 'application/xml', 'application/octet-stream', 'image/png']
        .every(ct => shouldRenderErrorBody(404, ct, 100) === false),
    'only HTML is a renderable page fragment');

check('an EMPTY html body is NOT rendered — there is no explanation in it',
    shouldRenderErrorBody(404, HTML, 0) === false,
    'a zero-length 404 carries nothing to show, so the generic alert is the honest answer');

check('a missing Content-Type is NOT rendered',
    shouldRenderErrorBody(404, null, 500) === false,
    'no header means no claim that this is a page; guessing is how a JSON body ends up in the DOM');

check('an empty Content-Type is NOT rendered',
    shouldRenderErrorBody(404, '', 500) === false);

console.log('\n3 — the boundaries, exactly');

check('399 is not an error status',
    shouldRenderErrorBody(399, HTML, 100) === false);

check('400 is (lower bound, inclusive)',
    shouldRenderErrorBody(400, HTML, 100) === true);

check('599 is (upper bound, inclusive)',
    shouldRenderErrorBody(599, HTML, 100) === true);

check('600 is not',
    shouldRenderErrorBody(600, HTML, 100) === false);

check('a 200 never reaches this decision, and answers false if it does',
    shouldRenderErrorBody(200, HTML, 100) === false,
    'success is the caller\'s path; a true here would mean two code paths could both claim a response');

check('status 0 (an opaque response) is false, not a crash',
    shouldRenderErrorBody(0, HTML, 100) === false);

check('a NaN status is false, not a crash',
    shouldRenderErrorBody(Number.NaN, HTML, 100) === false,
    'Number.isFinite, not a truthiness test — NaN passes `!status ? no : yes` in the wrong direction');

check('a non-numeric body length is false',
    shouldRenderErrorBody(404, HTML, undefined) === false);

console.log('\n4 — real header spellings, not just the tidy one');

check('a bare text/html with no charset works',
    shouldRenderErrorBody(404, 'text/html', 100) === true);

check('an uppercased type works',
    shouldRenderErrorBody(404, 'TEXT/HTML; charset=utf-8', 100) === true,
    'HTTP header values are case-insensitive in practice and some servers capitalise');

check('a charset parameter does not break the match',
    shouldRenderErrorBody(404, 'text/html;charset=ISO-8859-1', 100) === true,
    'no space after the semicolon is legal and does occur');

check('text/htmlx is NOT matched (prefix, but a different type)',
    shouldRenderErrorBody(404, 'application/xhtml+xml', 100) === false,
    'the check is a type-prefix match, so a type that merely CONTAINS text/html elsewhere '
    + 'must not slip through');

console.log('\n5 — the decision is actually consulted by the router');

/* Source-shaped, and legitimately so: "loadPage calls this, and injects rather
   than throwing" is a call-graph property with no runtime handle short of a DOM
   and a live server. The DECISION itself is covered above by being called. */
const routerSrc = fs.readFileSync(
    path.join(ROOT, 'appWeb/public_html/js/modules/router.js'), 'utf8');

check('router.js imports the decision',
    /import\s*\{[^}]*shouldRenderErrorBody[^}]*\}\s*from\s*['"][^'"]*error-response\.js['"]/.test(routerSrc),
    'the helper exists but nothing consumes it — a decision nobody asks is decoration');

check('…and calls it',
    routerSrc.includes('shouldRenderErrorBody('),
    'imported but never invoked');

/* The #1679 A12 shape — "a gate whose answer is DISCARDED is decoration" — has
   been reproduced twice in freshly-written files on this branch, so the fact
   that the answer actually gates something is asserted rather than assumed. */
/* Brace-BALANCED, not a fixed-width regex window. The first version of this
   allowed 200 characters between `)) {` and the `throw` and went red against
   correct code, because the explanatory comment inside the block is 231. Widening
   the number would only rot again the next time someone adds a line — so the
   QUESTION changed instead: find the block this `if` guards, and ask whether the
   throw is inside it. Exactly the fix that ended the same problem in
   tests/php/test-song-redirect-claim.php. */
function guardedBlock(src, needle) {
    const at = src.indexOf(needle);
    if (at === -1) { return null; }
    /* Walk to the `{` that opens the guarded block, then to its match. */
    let i = src.indexOf('{', at + needle.length);
    if (i === -1) { return null; }
    let depth = 0;
    for (let k = i; k < src.length; k++) {
        if (src[k] === '{') { depth++; }
        else if (src[k] === '}') {
            depth--;
            if (depth === 0) { return src.slice(i, k + 1); }
        }
    }
    return null;
}

const negatedBlock = guardedBlock(routerSrc, 'if (!shouldRenderErrorBody(');
check('…with its answer gating a throw, not merely computed',
    negatedBlock !== null && negatedBlock.includes('throw new Error'),
    'the call is present but its result does not decide anything — decoration, the #1679 A12 shape. '
    + `Block found: ${negatedBlock === null ? '(none)' : JSON.stringify(negatedBlock.slice(0, 120))}`);

check('the errored body is injected into the page container',
    /content\.innerHTML = errorBody;/.test(routerSrc),
    'the decision says "render it" and nothing renders it');

/* The old behaviour must be gone, not merely bypassed: an unconditional
   `if (!response.ok) throw` left anywhere above would short-circuit all of this. */
check('the unconditional !response.ok throw is gone',
    !/if \(!response\.ok\) \{\s*throw new Error\(`HTTP \$\{response\.status\}`\);\s*\}/.test(routerSrc),
    'the pre-#1705 line is still present, so every error body is still discarded before the new '
    + 'code can see it');

console.log('\n6 — the server really does send what this exists to show');

/* Derived from the tree, not typed: find the page fragments that set an error
   status, and assert each also emits a themed body. If a fragment stops doing
   that, this feature is pointless for it and somebody should know. */
const PAGES_DIR = path.join(ROOT, 'appWeb/public_html/includes/pages');
const emitters = fs.readdirSync(PAGES_DIR)
    .filter(f => f.endsWith('.php'))
    .map(f => ({ file: f, src: fs.readFileSync(path.join(PAGES_DIR, f), 'utf8') }))
    .filter(x => /http_response_code\(\s*(?:\$\w+\s*\?\s*)?[45]\d\d/.test(x.src));

check('found page fragments that set a 4xx/5xx status',
    emitters.length >= 5,
    `expected at least 5, found ${emitters.length} (${emitters.map(e => e.file).join(', ')}) — `
    + 'if this dropped, either the fragments changed or this scan is under-reporting, and an '
    + 'under-reporting scan reads as coverage (#1701)');

for (const e of emitters) {
    check(`${e.file} sends a themed body with its error status`,
        e.src.includes('renderErrorFragment('),
        'it sets an error status but emits no themed card, so the reader gets the generic alert '
        + 'even after #1705 — the status is honest and the page is blank');
}

console.log('');
console.log(`${passed} passed, ${failed} failed`);
if (failed > 0) {
    console.log('');
    console.log('Failures:');
    for (const f of failures) console.log(`  - ${f.name}${f.detail ? '\n    ' + f.detail : ''}`);
    process.exit(1);
}
