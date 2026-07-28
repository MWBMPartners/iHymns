/**
 * iHymns — Global Client-Side Error Monitor Guard (#1582)
 *
 * ELI5: this test builds a fake, minimal browser (just enough `window` /
 * `navigator` / `sessionStorage` / `location` for the module to run) in
 * plain Node — no real browser, no jsdom — then throws a bunch of made-up
 * errors and rejections at `js/modules/error-monitor.js` and checks it
 * behaves: ignores noise, redacts secrets, never spams the server or the
 * user, and never crashes itself even when the code IT calls (the toast)
 * throws.
 *
 * Detail: `error-monitor.js` exports two test-only helpers alongside its
 * real `bootErrorMonitor()` entry point — `__handleErrorForTests()` (the
 * exact same internal handler real DOM events reach, callable here with
 * plain object stand-ins for ErrorEvent/PromiseRejectionEvent, since
 * neither this module nor this test needs any REAL DOM API) and
 * `__resetForTests()` (clears the module's in-memory throttle counters
 * between assertions, matching how a fresh page load would). See that
 * file's own "TEST-ONLY EXPORTS" section for why these exist and why
 * production code never calls them.
 *
 * A test that cannot fail is worthless (hard project rule — see the
 * #1581 precedent in test-event-names.js). This file's PR description
 * records a deliberate red run for two of the assertions below (the
 * per-fingerprint dedupe key and the secret scrubber), each broken one
 * at a time, then restored.
 *
 * USAGE:
 *   node tests/test-error-monitor.js
 *
 * Exit status 0 = all tests pass, 1 = at least one assertion failed.
 */

import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname  = path.dirname(__filename);
const MODULE_PATH = path.resolve(
    __dirname, '..', 'appWeb', 'public_html', 'js', 'modules', 'error-monitor.js'
);

/* ======================================================================
 * Minimal browser shim — set up BEFORE the module's functions are ever
 * CALLED. Safe to import first (below) because error-monitor.js's own
 * top-level code never touches window/navigator/sessionStorage/location
 * — only the function bodies do, and none of those run until this test
 * calls them.
 * ==================================================================== */

/** In-memory sessionStorage stand-in — same 2-method surface the module uses. */
function makeSessionStorageMock() {
    const store = new Map();
    return {
        getItem: (k) => (store.has(k) ? store.get(k) : null),
        setItem: (k, v) => { store.set(k, String(v)); },
    };
}

/** Records every navigator.sendBeacon() call; always "succeeds" (returns
 *  true) so the module never falls through to the fetch() fallback —
 *  keeping this test free of any real/stubbed network dependency. */
let beaconCalls = [];
function mockSendBeacon(url, blob) {
    beaconCalls.push({ url, blob });
    return true;
}

/* Node 22 ships its OWN experimental read-only global `navigator` (a
   getter-only property on globalThis) — a plain `globalThis.navigator =
   …` therefore throws. `defineProperty` replaces the accessor with a
   plain, writable, configurable data property so the rest of this file
   (and every `resetAll()` between test groups) can keep reassigning it
   the same way it reassigns `location`/`sessionStorage`/`window`. */
Object.defineProperty(globalThis, 'navigator', {
    value: { sendBeacon: mockSendBeacon },
    writable: true,
    configurable: true,
});
globalThis.location = {
    pathname: '/song/MP-1008',
    href: 'https://ihymns.app/song/MP-1008',
};
globalThis.sessionStorage = makeSessionStorageMock();
globalThis.window = {
    iHymnsConfig: { version: '3.4.0', devStatus: 'Alpha' },
    /* iHymnsApp starts undefined — set per test group below, matching how
       error-monitor.js boots BEFORE iHymnsApp exists in the real app
       (see app.js's #1582 comment) and must tolerate that. */
};

const {
    bootErrorMonitor,
    __handleErrorForTests: handleError,
    __resetForTests: resetErrorMonitor,
} = await import(MODULE_PATH);

/* ======================================================================
 * Test helpers
 * ==================================================================== */

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

/** Reset ALL state between test groups: module counters + the fake
 *  sessionStorage backing store + the recorded beacon calls. Mirrors a
 *  fresh page load (module counters) PLUS a fresh session (storage). */
function resetAll() {
    resetErrorMonitor();
    globalThis.sessionStorage = makeSessionStorageMock();
    beaconCalls = [];
    globalThis.window.iHymnsApp = undefined;
}

function makeErrorEvent(message, overrides = {}) {
    return {
        message,
        filename: 'https://ihymns.app/js/app.js',
        lineno: 42,
        colno: 7,
        error: {
            stack: `Error: ${message}\n`
                + '    at foo (https://ihymns.app/js/app.js:42:7)\n'
                + '    at bar (https://ihymns.app/js/other.js:10:2)',
        },
        ...overrides,
    };
}

function makeRejectionEvent(reason) {
    return { reason };
}

/* ======================================================================
 * bootErrorMonitor() sanity — exported per spec, idempotent
 * ==================================================================== */
console.log('bootErrorMonitor():');
{
    resetAll();
    const listeners = [];
    globalThis.window.addEventListener = (type, fn) => listeners.push(type);
    bootErrorMonitor();
    bootErrorMonitor(); /* second call must be a no-op (module `booted` guard) */
    check(
        'registers exactly one "error" and one "unhandledrejection" listener, even called twice',
        listeners.filter(t => t === 'error').length === 1
            && listeners.filter(t => t === 'unhandledrejection').length === 1,
        `listeners=${JSON.stringify(listeners)}`
    );
    delete globalThis.window.addEventListener;
}

/* ======================================================================
 * Assertion — noise filter (drop and never beacon)
 * ==================================================================== */
console.log('');
console.log('Noise filter:');
{
    resetAll();
    handleError(makeErrorEvent('Script error.'), 'error');
    check('exact "Script error." message is dropped', beaconCalls.length === 0, `beaconCalls=${beaconCalls.length}`);

    resetAll();
    handleError(makeErrorEvent('ResizeObserver loop completed with undelivered notifications.'), 'error');
    check('a message containing "ResizeObserver loop" is dropped', beaconCalls.length === 0, `beaconCalls=${beaconCalls.length}`);

    resetAll();
    handleError(makeErrorEvent('TypeError: real bug', { filename: '' }), 'error');
    check('an empty source is dropped', beaconCalls.length === 0, `beaconCalls=${beaconCalls.length}`);

    resetAll();
    handleError(makeErrorEvent('TypeError: real bug', { filename: 'chrome-extension://abcdefg/content.js' }), 'error');
    check('a chrome-extension:// source is dropped', beaconCalls.length === 0, `beaconCalls=${beaconCalls.length}`);

    resetAll();
    handleError(makeErrorEvent('TypeError: a genuinely reportable bug'), 'error');
    check('a normal same-origin error is NOT dropped by the noise filter (sanity check)', beaconCalls.length === 1, `beaconCalls=${beaconCalls.length}`);
}

/* ======================================================================
 * Assertion — token scrubbed (message + stack), fail-proofed
 *
 * PROVE-FAIL RECORD: before accepting this assertion, the module's
 * `_scrub()` regexes were temporarily neutered (Bearer + hex64 patterns
 * replaced with a pattern that can never match) and this block was
 * re-run — it went red, reporting the raw "Bearer super-secret-token-xyz"
 * string surviving into the beacon payload. Restored immediately after;
 * see the PR description for the literal captured output.
 * ==================================================================== */
console.log('');
console.log('Scrub (secrets never reach the network):');
{
    resetAll();
    const hex64 = 'a'.repeat(64);
    const message = `AuthError: Authorization: Bearer super-secret-token-xyz failed for session ${hex64}`;
    handleError(makeErrorEvent(message), 'error');
    check('a scrubbed report was actually sent (sanity check)', beaconCalls.length === 1, `beaconCalls=${beaconCalls.length}`);

    if (beaconCalls.length === 1) {
        const sentJson = await beaconCalls[0].blob.text();
        check('the raw Bearer token never reaches the payload', !sentJson.includes('super-secret-token-xyz'), sentJson);
        check('the raw 64-hex-char string never reaches the payload', !sentJson.includes(hex64), sentJson);
        check('the Bearer value is replaced with the redaction marker', sentJson.includes('Bearer [redacted]'), sentJson);
        check('the hex-64 value is replaced with the redaction marker', sentJson.includes('[redacted]'), sentJson);
    }
}

/* ======================================================================
 * Assertion — dedupe suppresses a repeat, fail-proofed
 *
 * PROVE-FAIL RECORD: before accepting this assertion, the module's
 * per-fingerprint dedupe key (`_shouldBeacon`'s call to
 * `_wasFingerprintSentRecently`) was temporarily short-circuited to
 * always return `false` (i.e. "never seen before") and this block was
 * re-run — it went red, reporting 2 beacon calls for the identical
 * repeated error instead of 1. Restored immediately after; see the PR
 * description for the literal captured output.
 * ==================================================================== */
console.log('');
console.log('Dedupe (per-fingerprint throttle, layer 1):');
{
    resetAll();
    const event = () => makeErrorEvent('TypeError: dedupe me');
    handleError(event(), 'error');
    handleError(event(), 'error');
    handleError(event(), 'error');
    check(
        'the exact same error reported 3x in a row beacons only once',
        beaconCalls.length === 1,
        `beaconCalls=${beaconCalls.length}`
    );

    /* A DIFFERENT message must NOT be suppressed by the first one's
       fingerprint — proves the throttle is keyed per-fingerprint, not a
       single global "already sent something" flag. */
    handleError(makeErrorEvent('TypeError: a totally different bug'), 'error');
    check(
        'a different fingerprint is NOT suppressed by an unrelated one',
        beaconCalls.length === 2,
        `beaconCalls=${beaconCalls.length}`
    );

    /* Dedupe must survive a fresh module load (a page reload) — this is
       WHY layer 1 is sessionStorage-backed rather than in-memory (a
       crash-reload loop must not re-beacon on every reload). Simulate a
       reload by resetting ONLY the in-memory counters, keeping the same
       sessionStorage backing store. */
    resetErrorMonitor();
    beaconCalls = [];
    handleError(event(), 'error');
    check(
        'the fingerprint stays suppressed across a simulated reload (sessionStorage persists)',
        beaconCalls.length === 0,
        `beaconCalls=${beaconCalls.length}`
    );
}

/* ======================================================================
 * Assertion — hard cap holds under a burst
 * ==================================================================== */
console.log('');
console.log('Hard cap (layer 2, 10 beacons per page lifetime):');
{
    resetAll();
    /* Advance a FAKE clock by 40s per iteration so the layer-3 minimum-
       spacing rule (30s after the first 3 beacons) can never be the
       reason sends stop — isolates the hard cap specifically, rather
       than conflating it with the separate spacing throttle. */
    const realNow = Date.now;
    let fakeNow = realNow();
    Date.now = () => fakeNow;
    try {
        for (let i = 0; i < 50; i++) {
            fakeNow += 40000;
            handleError(makeErrorEvent(`TypeError: burst bug #${i}`), 'error');
        }
    } finally {
        Date.now = realNow;
    }
    check(
        'a 50-error burst of DISTINCT fingerprints sends at most 10 beacons',
        beaconCalls.length === 10,
        `beaconCalls=${beaconCalls.length} (expected exactly 10 — spacing was neutralised above so only the hard cap should bind)`
    );
}

/* ======================================================================
 * Assertion — minimum spacing after the first 3 (layer 3), real clock
 * ==================================================================== */
console.log('');
console.log('Minimum spacing (layer 3, 30s after the first 3 beacons):');
{
    resetAll();
    for (let i = 0; i < 6; i++) {
        handleError(makeErrorEvent(`TypeError: rapid-fire bug #${i}`), 'error');
    }
    check(
        'without an advancing clock, only the first 3 beacons of a rapid-fire burst send',
        beaconCalls.length === 3,
        `beaconCalls=${beaconCalls.length} (beacons 4-6 arrived under 30s after beacon 3 and should be held)`
    );
}

/* ======================================================================
 * Assertion — re-entrancy: a throwing toast never rethrows and never
 * double-beacons
 * ==================================================================== */
console.log('');
console.log('Re-entrancy (throwing toast):');
{
    resetAll();
    globalThis.window.iHymnsApp = {
        showToast() { throw new Error('toast boom — simulated failure while building the toast'); },
    };

    let rethrew = false;
    try {
        handleError(makeErrorEvent('TypeError: triggers a throwing toast'), 'error');
    } catch (_e) {
        rethrew = true;
    }
    check('a throwing showToast() never escapes handleError', !rethrew);
    check('the report was still beaconed exactly once (not zero — sent before the toast; not twice — no retry loop)', beaconCalls.length === 1, `beaconCalls=${beaconCalls.length}`);

    /* Calling it again immediately must also not wedge — the `inHandler`
       flag must have been cleared in `finally` despite the throw. */
    let secondRethrew = false;
    try {
        handleError(makeErrorEvent('TypeError: a second, different bug'), 'error');
    } catch (_e) {
        secondRethrew = true;
    }
    check('the handler is usable again immediately after a swallowed throw (inHandler cleared in finally)', !secondRethrew);
    check('the second, different bug still beacons (handler is not permanently wedged)', beaconCalls.length === 2, `beaconCalls=${beaconCalls.length}`);
}

/* ======================================================================
 * Assertion — toast throttle (separate from the beacon throttle):
 * at most once per 60s AND once per fingerprint
 * ==================================================================== */
console.log('');
console.log('Toast throttle:');
{
    resetAll();
    const toasts = [];
    globalThis.window.iHymnsApp = { showToast: (msg) => toasts.push(msg) };

    handleError(makeErrorEvent('TypeError: toast me once'), 'error');
    handleError(makeErrorEvent('TypeError: toast me once'), 'error'); /* same fingerprint — beacon itself is deduped too */
    handleError(makeErrorEvent('TypeError: a different bug entirely'), 'error'); /* different fingerprint, but within 60s */
    check('at most one toast fires across 3 rapid errors (60s + per-fingerprint throttle)', toasts.length === 1, `toasts=${JSON.stringify(toasts)}`);
    check('the toast never contains the raw error text (generic copy only)', toasts.length === 1 && !toasts[0].includes('toast me once'));
}

/* ======================================================================
 * Assertion — unhandledrejection shape (non-Error reasons handled)
 * ==================================================================== */
console.log('');
console.log('unhandledrejection handling:');
{
    resetAll();
    handleError(makeRejectionEvent(new Error('a rejected promise with a real Error')), 'rejection');
    check('a rejection with an Error reason beacons', beaconCalls.length === 1, `beaconCalls=${beaconCalls.length}`);

    resetAll();
    handleError(makeRejectionEvent('a plain string rejection reason'), 'rejection');
    check('a rejection with a plain-string reason also beacons (never throws building the payload)', beaconCalls.length === 1, `beaconCalls=${beaconCalls.length}`);

    resetAll();
    let threw = false;
    try {
        handleError(makeRejectionEvent(undefined), 'rejection');
    } catch (_e) {
        threw = true;
    }
    check('a rejection with an undefined reason never throws building the payload', !threw);
}

/* ======================================================================
 * Assertion — privacy: never location.search / location.hash
 * ==================================================================== */
console.log('');
console.log('Privacy — never location.search/hash in the payload:');
{
    resetAll();
    globalThis.location = {
        pathname: '/song/MP-1008',
        href: 'https://ihymns.app/song/MP-1008?token=leak-me#fragment-leak',
        search: '?token=leak-me',
        hash: '#fragment-leak',
    };
    handleError(makeErrorEvent('TypeError: privacy check', { filename: 'https://ihymns.app/js/app.js?v=leak-me' }), 'error');
    if (beaconCalls.length === 1) {
        const sentJson = await beaconCalls[0].blob.text();
        check('the beacon payload never contains the query string', !sentJson.includes('leak-me'), sentJson);
        check('the beacon payload never contains a "?" (no query string anywhere in path fields)', !sentJson.includes('?'), sentJson);
    } else {
        check('a report was sent for the privacy check (sanity check)', false, `beaconCalls=${beaconCalls.length}`);
    }
    /* Restore the shared location shim for anything appended below. */
    globalThis.location = { pathname: '/song/MP-1008', href: 'https://ihymns.app/song/MP-1008' };
}

/* ======================================================================
 * Summary
 * ==================================================================== */
console.log('');
console.log(`${passed} passed, ${failed} failed`);
if (failed > 0) {
    console.log('');
    console.log('Failures:');
    for (const f of failures) {
        console.log(`  - ${f.name}`);
        if (f.detail) console.log(`    ${f.detail}`);
    }
}
process.exit(failed === 0 ? 0 : 1);
