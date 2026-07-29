/**
 * tests/test-lang-filter-fetch-url.js — regression guard for #1593
 *
 * ELI5: makes sure the language filter can read a web address no matter
 * which of the three shapes `fetch()` was handed.
 *
 * WHY THIS EXISTS
 * ---------------
 * `songbook-language-filter.js` monkey-patches `window.fetch` to attach the
 * `X-Preferred-Languages` header. It resolved the request URL with:
 *
 *     typeof input === 'string' ? input : input.url
 *
 * which is right for a string and right for a `Request` — and silently wrong
 * for a `URL` object, whose property is `href`, not `url`. That yielded
 * `undefined`, and the next line called `.startsWith` on it and threw a
 * TypeError. Because the wrapper fronts EVERY fetch on the site, the throw
 * surfaced as a failed REQUEST in whichever caller happened to catch it.
 *
 * Song of the Day catches and blanks its container, so the card silently
 * vanished — but only while a language filter was active, because an empty
 * filter early-returns before this code. Nine other callers that pass a URL
 * object were broken the same way, including the header search autocomplete.
 *
 * PROVEN ABLE TO FAIL: run against the pre-fix resolver (the `legacyResolve`
 * shape below) and the URL-object case throws. That check is part of this
 * suite — see "the old implementation must fail" — so the guard cannot rot
 * into a test that passes no matter what.
 *
 * #1031 — requestUrlOf() moved from songbook-language-filter.js (which no
 * longer needs it, now that the fetch monkey-patch it served is deleted)
 * into js/utils/api-client.js, the shared client that now attaches
 * X-Preferred-Languages on every request. Same function, same guarantees —
 * only the import path changed.
 *
 * @see appWeb/public_html/js/utils/api-client.js  requestUrlOf()
 * @see https://developer.mozilla.org/en-US/docs/Web/API/fetch
 */

import { requestUrlOf } from '../appWeb/public_html/js/utils/api-client.js';

let passed = 0;
let failed = 0;

function check(label, actual, expected) {
    if (actual === expected) {
        passed++;
    } else {
        failed++;
        console.error(`  FAIL ${label}\n       expected: ${JSON.stringify(expected)}\n       actual:   ${JSON.stringify(actual)}`);
    }
}

/* The exact expression the pre-fix code used, kept so this suite can prove
   it is capable of catching the regression it was written for. */
function legacyResolve(input) {
    let url = '';
    try { url = typeof input === 'string' ? input : input.url; } catch (_e) {}
    return !url.startsWith('http');   // throws on undefined
}

console.log('#1593 — language-filter fetch URL resolution\n');

/* 1. The three shapes fetch() accepts. */
check('string passes through',
    requestUrlOf('/api?action=song_of_the_day'),
    '/api?action=song_of_the_day');

check('URL object resolves to href (THE REGRESSION)',
    requestUrlOf(new URL('https://dev.ihymns.app/api?action=song_of_the_day')),
    'https://dev.ihymns.app/api?action=song_of_the_day');

check('Request-like object resolves to .url',
    requestUrlOf({ url: 'https://dev.ihymns.app/api' }),
    'https://dev.ihymns.app/api');

/* 2. Degenerate inputs must return a string, never throw — the wrapper
      fronts every request on the site, so "return something safe" beats
      "be correct and explode". */
check('null returns empty string', requestUrlOf(null), '');
check('undefined returns empty string', requestUrlOf(undefined), '');
check('number is stringified', requestUrlOf(42), '42');

/* 3. Always a string, for every input — that is the invariant the caller
      depends on when it calls .startsWith(). */
for (const [label, input] of [
    ['string', '/api'],
    ['URL', new URL('https://example.test/x')],
    ['Request-like', { url: '/api' }],
    ['null', null],
    ['undefined', undefined],
    ['object', {}],
]) {
    check(`returns a string for ${label}`, typeof requestUrlOf(input), 'string');
}

/* 4. The guard's own guard: the OLD implementation must fail the URL case.
      If this ever stops throwing, the test above has stopped testing
      anything and this suite is lying. */
let legacyThrew = false;
try {
    legacyResolve(new URL('https://dev.ihymns.app/api'));
} catch (err) {
    legacyThrew = err instanceof TypeError;
}
check('the old implementation still throws on a URL object', legacyThrew, true);

console.log(`\n${passed} passed, ${failed} failed`);
process.exit(failed === 0 ? 0 : 1);
