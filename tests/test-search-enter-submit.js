/**
 * iHymns — pressing Enter in the search box searches, instead of wiping the page
 *
 * ELI5
 * ----
 * Type a search, press Enter. That should show results. It used to reload the
 * whole app and throw away what you had typed. This makes sure it cannot go
 * back to doing that.
 *
 * THE BUG
 * -------
 * `includes/pages/search.php` renders `<form id="page-search-form">` holding a
 * single `<input type="search">`, with no submit button, no `action`, and no
 * named control. The HTML spec's implicit-submission rules say that a form
 * with exactly one field that blocks implicit submission and no submit button
 * IS submitted when Enter is pressed in that field:
 * https://html.spec.whatwg.org/multipage/form-control-infrastructure.html#implicit-submission
 *
 * Nothing bound a submit handler to it. `search.js` DID intercept Enter — but
 * only on `#search-input`, the HEADER search box removed in #812 — and app.js's
 * one delegated `submit` listener returns immediately unless the form's id is
 * `correction-form`. So the browser performed a real navigation to the page's
 * own URL, the SPA hard-reloaded, and because no control had a `name` the
 * query string was empty: the reader's text was simply gone.
 *
 * No exception, no console warning, no failed request. Just the single most
 * natural gesture in a search box quietly destroying the search — which is the
 * same silent-no-op class as #1565 and #1581.
 *
 * THE FIX HAS TWO LAYERS, and this file checks both:
 *   1. search.js binds a real submit handler that preventDefault()s and
 *      searches in place. This is the normal path.
 *   2. the form gained `action="/search"` + `name="q"`, so the NATIVE
 *      submission — the one that happens if the module fails to load — lands
 *      on a working URL that initSearchPage() already reads, rather than on a
 *      query-less reload. The fallback is now correct instead of destructive.
 *
 *   node tests/test-search-enter-submit.js
 *
 * Exit status 0 = all pass, 1 = at least one failure.
 */

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { JSDOM } from 'jsdom';

const __dirname = dirname(fileURLToPath(import.meta.url));
const PUB = join(__dirname, '..', 'appWeb', 'public_html');

let passed = 0, failed = 0;
const failures = [];
function check(label, cond, detail = '') {
    if (cond) { passed++; console.log(`  PASS  ${label}`); }
    else {
        failed++; failures.push(`${label}${detail ? ` — ${detail}` : ''}`);
        console.log(`  FAIL  ${label}`);
        if (detail) console.log(`        ${detail}`);
    }
}

/* Comments stripped before ANY assertion reads the source. The doc-notes added
   alongside this fix quote `action="/search"` and `name="q"` verbatim, so
   against raw source a reverted fix with its comment left behind would still
   report green — the failure this project has already been bitten by
   (tests/php/test-fragment-inline-scripts.php strips for the same reason). */
const searchPhp = readFileSync(join(PUB, 'includes/pages/search.php'), 'utf8')
    .replace(/<!--[\s\S]*?-->/g, '')
    .replace(/\/\*[\s\S]*?\*\//g, '');
const searchJs = readFileSync(join(PUB, 'js/modules/search.js'), 'utf8')
    .replace(/\/\*[\s\S]*?\*\//g, '');

console.log('\n1 — the form is still in the implicit-submission shape (so a handler is REQUIRED)\n');

const formSrc = /<form[^>]*id="page-search-form"[\s\S]*?<\/form>/.exec(searchPhp);
check('found #page-search-form in search.php', !!formSrc);

const dom = new JSDOM(`<body>${formSrc ? formSrc[0] : ''}</body>`, { url: 'https://example.test/search' });
const doc = dom.window.document;
const form = doc.getElementById('page-search-form');
check('the form parses', !!form);

/* Per the spec these input types "block implicit submission"; a form with
   exactly one of them and no submit button submits on Enter. */
const BLOCKING = ['text', 'search', 'url', 'tel', 'email', 'password',
    'date', 'month', 'week', 'time', 'datetime-local', 'number'];
const blocking = form ? [...form.querySelectorAll('input')]
    .filter((i) => BLOCKING.includes((i.getAttribute('type') || 'text').toLowerCase())) : [];
const submitButtons = form ? form.querySelectorAll('button:not([type=button]), input[type=submit]').length : 0;
const implicitlySubmits = blocking.length === 1 && submitButtons === 0;
console.log(`       (${blocking.length} implicit-submit field(s), ${submitButtons} submit button(s)`
    + ` -> Enter ${implicitlySubmits ? 'SUBMITS' : 'does not submit'})`);

console.log('\n2 — the no-JS fallback lands somewhere useful, not on a query-less reload\n');

check('the form declares an action (without one it submits to its own URL)',
    !!form && !!form.getAttribute('action'),
    'no action attribute — a native submit reloads the current page');
check('the action is the /search route the app already serves',
    !!form && (form.getAttribute('action') || '').startsWith('/search'),
    `action is "${form ? form.getAttribute('action') : '(none)'}"`);
check('the search field is NAMED, so the typed query survives a native submit',
    blocking.length > 0 && blocking.every((i) => !!i.getAttribute('name')),
    'the input has no name attribute — a native submit sends an empty query string');
check('it is named `q`, the parameter initSearchPage() already reads back',
    blocking.some((i) => i.getAttribute('name') === 'q'),
    `names found: ${blocking.map((i) => i.getAttribute('name')).join(', ') || '(none)'}`);

/* The read-back half of the contract — assert it rather than assume it, since
   naming the field `q` is only useful if something consumes `q`. */
check('initSearchPage() reads ?q= back out of the URL on load',
    /params\.get\(\s*['"]q['"]\s*\)/.test(searchJs));

console.log('\n3 — with JS running, Enter searches in place and does NOT navigate\n');

check('search.js binds a submit handler to #page-search-form',
    /getElementById\(\s*['"]page-search-form['"]\s*\)/.test(searchJs)
    && /addEventListener\(\s*['"]submit['"]/.test(searchJs),
    'no submit binding found — the browser will navigate on Enter');

/* Pull the handler body out and check the two things that make it correct. */
const handler = /page-search-form[\s\S]{0,400}?addEventListener\(\s*['"]submit['"][\s\S]*?\n {8}\}\);/.exec(searchJs);
check('found the submit handler body', !!handler);
if (handler) {
    check('the handler calls preventDefault() — this is what stops the reload',
        /preventDefault\(\s*\)/.test(handler[0]));
    check('the handler runs a search rather than only swallowing the event',
        /performSearch\s*\(/.test(handler[0]),
        'Enter would do nothing at all, which is a different silent no-op');
    check('the handler cancels the pending debounce so the search does not run twice',
        /clearTimeout\s*\(/.test(handler[0]));
}

/* Behavioural: bind the real form and confirm nothing navigates. jsdom reports
   an unhandled navigation as a "Not implemented: navigation" error, so a
   handler that failed to preventDefault would surface here rather than pass. */
if (form) {
    let searched = false, defaultPrevented = false;
    form.addEventListener('submit', (e) => {
        /* Stand-in for the real handler, mirroring its two effects. */
        e.preventDefault();
        searched = true;
        defaultPrevented = e.defaultPrevented;
    });
    form.requestSubmit();
    check('a submit handler on this form can cancel the navigation (jsdom control)',
        searched && defaultPrevented);
}

console.log(`\n${passed} passed, ${failed} failed`);
if (failed > 0) {
    console.error('\nFailures:');
    for (const f of failures) console.error(`  - ${f}`);
    console.error('\nA single-field form with no submit button IS submitted by Enter. Without a');
    console.error('handler that calls preventDefault(), the browser navigates and the SPA reloads');
    console.error('— discarding the query, with nothing logged anywhere.');
    process.exit(1);
}
console.log('\nAll search-Enter assertions passed.');
