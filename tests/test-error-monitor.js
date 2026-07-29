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
 * WIRING GUARD (#1599): the behavioural groups above all exercise the
 * module in isolation — every one of them passed for weeks while the
 * module was loaded on the PUBLIC site ONLY and no `/manage/*` page had
 * any crash surfacing at all. That is the #1565/#1581 failure shape in
 * miniature: a green suite proving a mechanism works, next to a surface
 * that never invokes it. The final two groups below therefore assert the
 * WIRING as source text, not behaviour — that
 * `manage/includes/head-libs.php` really does import and boot the module,
 * and that every admin page that emits a document really does go through
 * that one partial.
 *
 * USAGE:
 *   node tests/test-error-monitor.js
 *
 * Exit status 0 = all tests pass, 1 = at least one assertion failed.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname  = path.dirname(__filename);
const PUBLIC_HTML = path.resolve(__dirname, '..', 'appWeb', 'public_html');
const MODULE_PATH = path.join(PUBLIC_HTML, 'js', 'modules', 'error-monitor.js');
/* The ONE shared <head> partial every /manage/* page includes (modularity
   rule — see .claude/CLAUDE.md "Web/PWA specific checkpoints"). */
const HEAD_LIBS_PATH = path.join(PUBLIC_HTML, 'manage', 'includes', 'head-libs.php');
const MANAGE_ROOT    = path.join(PUBLIC_HTML, 'manage');

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

/* Kept as the whole namespace object (not only the three destructured
   bindings) because the #1599 wiring guard below cross-references the
   names head-libs.php IMPORTS against what this module actually EXPORTS
   — a rename of `bootErrorMonitor` that forgot the admin partial would
   otherwise be a silent runtime-only break. */
const errorMonitorModule = await import(MODULE_PATH);
const {
    bootErrorMonitor,
    __handleErrorForTests: handleError,
    __resetForTests: resetErrorMonitor,
} = errorMonitorModule;

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
 * Assertion — M2 (adversarial-review finding, OBS-VERIFY.md): a
 * query-string secret embedded in the free-text `m` field is redacted.
 *
 * ELI5: `m` is whatever text a `throw new Error(...)` call site wrote —
 * unlike `r`/`s` (which are reduced to a bare pathname), `m` had NO
 * query-string handling at all before this fix, so a message built from
 * a URL (`throw new Error('Failed to fetch ' + url)`) could carry a
 * live `?token=...`/`&sid=...` straight into the beacon.
 *
 * PROVE-FAIL RECORD: before accepting this assertion, QUERY_SECRET_RE
 * was temporarily neutered (replaced with a pattern that can never
 * match, `/$never-matches^/`) and this block was re-run — it went red,
 * reporting the raw "SUPERSECRETVALUE" / "abc123session" strings
 * surviving into the beacon payload:
 *   FAIL  a query-string token= value never reaches the payload
 *   FAIL  a query-string sid= value never reaches the payload
 *   FAIL  the token/sid param names survive with redacted values
 * Restored immediately after; see the PR description for the literal
 * captured output.
 * ==================================================================== */
console.log('');
console.log('Scrub — query-string secrets in the message field (M2):');
{
    resetAll();
    const urlSecretMessage =
        'TypeError: Failed to fetch https://ihymns.app/api?action=x&token=SUPERSECRETVALUE&sid=abc123session';
    handleError(makeErrorEvent(urlSecretMessage), 'error');
    check('a scrubbed report was actually sent (sanity check)', beaconCalls.length === 1, `beaconCalls=${beaconCalls.length}`);

    if (beaconCalls.length === 1) {
        const sentJson = await beaconCalls[0].blob.text();
        check('a query-string token= value never reaches the payload', !sentJson.includes('SUPERSECRETVALUE'), sentJson);
        check('a query-string sid= value never reaches the payload', !sentJson.includes('abc123session'), sentJson);
        check(
            'the token/sid param names survive with redacted values (still diagnostically useful)',
            sentJson.includes('token=[redacted]') && sentJson.includes('sid=[redacted]'),
            sentJson
        );
    }
}

/* ======================================================================
 * Assertion — M2 (adversarial-review finding, OBS-VERIFY.md): a
 * non-Error `Promise.reject(object)` reason is never serialised
 * wholesale via JSON.stringify(), which would bypass every string-shaped
 * scrub pattern above (they match SHAPES, not object structure).
 *
 * PROVE-FAIL RECORD: before accepting this assertion, `_safeStringify()`
 * was temporarily reverted to its pre-fix `JSON.stringify(reason)`
 * fallback and this block was re-run — it went red, reporting the raw
 * "hunter2-secret-password" / "leak-me@example.com" field VALUES
 * surviving into the beacon payload (the whole rejected object had been
 * dumped verbatim into `m`):
 *   FAIL  an object rejection's field VALUES never reach the payload
 * Restored immediately after; see the PR description for the literal
 * captured output.
 * ==================================================================== */
console.log('');
console.log('Scrub — object rejection reasons never serialised wholesale (M2):');
{
    resetAll();
    handleError(
        makeRejectionEvent({
            token: 'a-live-bearer-style-token',
            password: 'hunter2-secret-password',
            email: 'leak-me@example.com',
        }),
        'rejection'
    );
    check('a report was sent for the object-reason rejection (sanity check)', beaconCalls.length === 1, `beaconCalls=${beaconCalls.length}`);

    if (beaconCalls.length === 1) {
        const sentJson = await beaconCalls[0].blob.text();
        check(
            "an object rejection's field VALUES never reach the payload",
            !sentJson.includes('hunter2-secret-password') && !sentJson.includes('leak-me@example.com') && !sentJson.includes('a-live-bearer-style-token'),
            sentJson
        );
        check('the payload still records SOME diagnostic shape for the rejection, not an empty message', sentJson.includes('"m":"[non-Error rejection'), sentJson);
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
 * Assertion — L10 (adversarial-review finding, OBS-VERIFY.md): a send
 * that fails synchronously must NOT mark the fingerprint as sent.
 *
 * ELI5: if the "mail this report" step itself blows up before anything
 * left the browser, the app must not act like it succeeded — otherwise
 * the SAME bug goes unreported for a full 10 minutes (the sessionStorage
 * dedupe window) even though the server never heard about it even once.
 *
 * PROVE-FAIL RECORD: before accepting this assertion, `handleError()`'s
 * throttle step was temporarily reverted to mark the fingerprint sent /
 * increment the hard-cap counter BEFORE calling `_sendReport()` (the
 * pre-fix order) and this block was re-run — it went red:
 *   FAIL  the SAME fingerprint beacons successfully once a working
 *         transport is available (not falsely suppressed by a prior
 *         failed attempt)
 *         beaconCalls=0 (expected exactly 1)
 * i.e. the retry with a WORKING transport was silently swallowed by the
 * layer-1 dedupe window, because the failed first attempt had already
 * (wrongly) marked that exact fingerprint as delivered. Restored
 * immediately after; see the PR description for the literal captured
 * output.
 * ==================================================================== */
console.log('');
console.log('L10 — a synchronously-failing send does not falsely mark the fingerprint as sent:');
{
    resetAll();
    const originalSendBeacon = globalThis.navigator.sendBeacon;
    const originalFetch = globalThis.fetch;
    /* Simulate BOTH transports throwing synchronously — the "everything
       failed before anything left the browser" case _sendReport() must
       report back to the caller as "not sent". */
    globalThis.navigator.sendBeacon = () => {
        throw new Error('sendBeacon boom — simulated synchronous failure (e.g. Blob unsupported)');
    };
    globalThis.fetch = () => {
        throw new Error('fetch boom — simulated synchronous failure');
    };

    let threw = false;
    try {
        handleError(makeErrorEvent('TypeError: L10 send-failure probe'), 'error');
    } catch (_e) {
        threw = true;
    }
    check('a synchronously-throwing sendBeacon AND fetch never escapes handleError', !threw);
    check('nothing was actually beaconed when every transport threw synchronously', beaconCalls.length === 0, `beaconCalls=${beaconCalls.length}`);

    /* Restore a WORKING transport and retry the EXACT SAME error. If the
       failed attempt above had (incorrectly) marked this fingerprint as
       sent, this retry would be silently suppressed by the layer-1
       sessionStorage dedupe window for 10 minutes despite nothing ever
       having reached the server. */
    globalThis.navigator.sendBeacon = originalSendBeacon;
    globalThis.fetch = originalFetch;
    handleError(makeErrorEvent('TypeError: L10 send-failure probe'), 'error');
    check(
        'the SAME fingerprint beacons successfully once a working transport is available (not falsely suppressed by a prior failed attempt)',
        beaconCalls.length === 1,
        `beaconCalls=${beaconCalls.length} (expected exactly 1)`
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
 * Assertion — M3 (adversarial-review finding, OBS-VERIFY.md): the
 * `inHandler` re-entrancy guard is load-bearing on its own, separately
 * from the outer try/catch group above.
 *
 * ELI5: the group above proves a THROWING toast can't crash the handler
 * or double-beacon. This group proves something narrower: a toast that
 * doesn't throw at all, but instead calls straight back into this app's
 * error handler WHILE the first error is still being processed (e.g. a
 * toast-construction step that itself reports its own error
 * synchronously) — must be dropped outright, not run to completion as a
 * second, independent report.
 *
 * DETAIL: a `try/catch` alone cannot stop this — the re-entrant call
 * here never throws, so there's nothing for the catch to swallow; it's
 * `inHandler` at the top of `handleError()` (checked BEFORE anything
 * else runs) that recognises "we are already inside a handler call" and
 * bails out immediately.
 *
 * PROVE-FAIL RECORD: before accepting this assertion, the module's
 * `inHandler` guard (the `if (inHandler) return; inHandler = true;` pair
 * at the top of `handleError()`, and the `inHandler = false;` in its
 * `finally`) was temporarily deleted — leaving ONLY the outer try/catch
 * — and this block was re-run. It went red:
 *   FAIL  a synchronous re-entrant call from inside showToast() is
 *         dropped by the inHandler guard (only the outer beacon fires)
 *         beaconCalls=2 (expected exactly 1 — the re-entrant call must
 *         be silently ignored, not beacon under its own, different
 *         fingerprint)
 * — the re-entrant call ran to completion and sent its OWN beacon for a
 * DIFFERENT fingerprint, so one user-visible failure was reported twice.
 * Confirms the guard is genuinely load-bearing, not merely redundant
 * with the try/catch. Restored immediately after; see the PR description
 * for the literal captured output.
 * ==================================================================== */
console.log('');
console.log('Re-entrancy (synchronous re-entrant call from inside showToast, M3):');
{
    resetAll();
    globalThis.window.iHymnsApp = {
        showToast() {
            /* Re-enters the handler SYNCHRONOUSLY, mid-call, with a
               DISTINCT error — never throws, so the try/catch above has
               nothing to do with stopping this; only `inHandler` can. */
            handleError(makeErrorEvent('TypeError: re-entrant bug fired from inside showToast'), 'error');
        },
    };
    handleError(makeErrorEvent('TypeError: outer bug that triggers the toast'), 'error');
    check(
        'a synchronous re-entrant call from inside showToast() is dropped by the inHandler guard (only the outer beacon fires)',
        beaconCalls.length === 1,
        `beaconCalls=${beaconCalls.length} (expected exactly 1 — the re-entrant call must be silently ignored, not beacon under its own, different fingerprint)`
    );
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
 * Assertion — #1599: the ADMIN surface actually loads and boots the
 * module.
 *
 * ELI5: every test above proves the smoke detector WORKS. This one
 * proves it is actually screwed to the ceiling in the admin area — for
 * #1582's whole life it was wired only into the public site's `app.js`,
 * so `/manage/*` had no crash surfacing whatsoever while this very file
 * reported all-green.
 *
 * DETAIL: a source-text assertion (there is no admin page to boot in
 * Node, and pretending to render PHP here would test the pretence, not
 * the page). It reads the real `manage/includes/head-libs.php`, strips
 * the PHP so what is left is the literal HTML the browser receives, and
 * checks the five things that each independently make this a silent
 * no-op if wrong:
 *
 *   1. the partial references the module at all;
 *   2. it is loaded as `type="module"` — error-monitor.js uses `export`,
 *      so a classic <script> is an outright SyntaxError;
 *      https://developer.mozilla.org/docs/Web/HTML/Element/script#module
 *   3. the boot function is CALLED — importing the module alone installs
 *      nothing, the listeners only exist after `bootErrorMonitor()`;
 *   4. the specifier is ROOT-absolute. A relative one resolves against
 *      the DOCUMENT, so it would break for admin pages at another depth
 *      (`/manage/editor/…`) — and this app's `.htaccess` catch-all
 *      answers an unmatched path with 200 + the HTML shell, so the
 *      failure surfaces as a confusing MIME-type refusal, not a 404
 *      (the #1566 class, in CLAUDE.md's red-flag list);
 *   5. every imported binding is a REAL export of error-monitor.js —
 *      cross-referenced against the live module namespace, so renaming
 *      the export without updating the partial fails here rather than in
 *      an admin's console.
 *
 * PROVE-FAIL RECORD: this group was written and run BEFORE
 * head-libs.php was touched — i.e. against the unfixed #1599 code — and
 * went red, while all 40 pre-existing behavioural assertions stayed
 * green (which is precisely the blindness it exists to remove):
 *   Admin wiring — manage/includes/head-libs.php boots the monitor (#1599):
 *     FAIL  head-libs.php contains exactly one <script> referencing error-monitor.js
 *           matching <script> blocks=0 (0 = the admin area has no crash
 *           surfacing at all — the #1599 bug)
 *   40 passed, 1 failed
 * It went green (47 passed, 0 failed) only once the partial actually
 * imported and called bootErrorMonitor().
 * ==================================================================== */
console.log('');
console.log('Admin wiring — manage/includes/head-libs.php boots the monitor (#1599):');

/** Remove `<?php … ?>` / `<?= … ?>` regions, leaving the literal HTML+JS
 *  the browser is actually served. Same "strip the server language first,
 *  then assert on what ships" approach as
 *  tests/php/test-fragment-inline-scripts.php (#1565). */
function stripPhp(src) {
    return src.replace(/<\?(?:php\b|=)?[\s\S]*?\?>/g, '');
}

/** Every `<script …>…</script>` in a chunk of HTML, as {attrs, body}. */
function extractScripts(html) {
    const out = [];
    const re = /<script\b([^>]*)>([\s\S]*?)<\/script>/gi;
    let m;
    while ((m = re.exec(html)) !== null) out.push({ attrs: m[1], body: m[2] });
    return out;
}

{
    const headLibsSrc  = fs.readFileSync(HEAD_LIBS_PATH, 'utf8');
    const headLibsHtml = stripPhp(headLibsSrc);
    const monitorScripts = extractScripts(headLibsHtml)
        .filter(s => `${s.attrs}${s.body}`.includes('error-monitor.js'));

    check(
        'head-libs.php contains exactly one <script> referencing error-monitor.js',
        monitorScripts.length === 1,
        `matching <script> blocks=${monitorScripts.length} (0 = the admin area has no crash surfacing at all — the #1599 bug)`
    );

    if (monitorScripts.length === 1) {
        const { attrs, body } = monitorScripts[0];

        check(
            'it is loaded as type="module" (error-monitor.js uses `export` — a classic <script> would be a SyntaxError)',
            /type\s*=\s*["']module["']/i.test(attrs),
            `attrs=${JSON.stringify(attrs)}`
        );

        const importMatch = /import\s*\{([^}]*)\}\s*from\s*['"]([^'"]+)['"]/.exec(body);
        check(
            'it uses a named `import { … } from …` of the module',
            importMatch !== null,
            `body=${JSON.stringify(body)}`
        );

        if (importMatch) {
            const specifier = importMatch[2];
            const names = importMatch[1]
                .split(',')
                .map(n => n.trim().split(/\s+as\s+/)[0].trim())
                .filter(Boolean);

            check(
                'the module specifier is root-absolute, never relative (#1566 — a relative URL resolves against the DOCUMENT and the .htaccess catch-all masks the 404)',
                specifier.startsWith('/js/modules/error-monitor.js'),
                `specifier=${JSON.stringify(specifier)}`
            );

            check(
                'it imports bootErrorMonitor',
                names.includes('bootErrorMonitor'),
                `imported names=${JSON.stringify(names)}`
            );

            check(
                'it CALLS bootErrorMonitor() (importing alone installs no listeners)',
                /\bbootErrorMonitor\s*\(\s*\)/.test(body),
                `body=${JSON.stringify(body)}`
            );

            const unknown = names.filter(n => typeof errorMonitorModule[n] !== 'function');
            check(
                'every name head-libs.php imports is a real exported function of error-monitor.js (rename drift guard)',
                unknown.length === 0,
                `not exported by the module: ${JSON.stringify(unknown)}; module exports=${JSON.stringify(Object.keys(errorMonitorModule))}`
            );
        }
    }
}

/* ======================================================================
 * Assertion — #1599: every admin page that emits a document goes through
 * that one partial.
 *
 * ELI5: the check above proves the shared admin <head> boots the
 * monitor. This one proves the admin pages actually USE that shared
 * <head>, so nobody gets crash surfacing by accident and nobody loses it
 * by writing a page with its own hand-rolled <head>.
 *
 * DETAIL: this is the modularity rule expressed as a test (.claude/
 * CLAUDE.md — "every /manage/*.php page MUST use the shared partials").
 * BESPOKE_HEAD_ADMIN_PAGES is a count-exact, SELF-CLEANING allowlist, the
 * same convention as tests/php/test-fragment-inline-scripts.php: a listed
 * page that has since started requiring head-libs.php fails here so the
 * entry gets deleted rather than quietly outliving the exception. The
 * three editor pages on it today are a REAL, KNOWN GAP — they each build
 * their own <head> and so still have no error monitor; see #1599's
 * follow-up note.
 *
 * PROVE-FAIL RECORD: run with 'editor/index.php' removed from
 * BESPOKE_HEAD_ADMIN_PAGES, this block went red exactly as intended:
 *   FAIL  every /manage/* page that emits an HTML document includes head-libs.php
 *         uncovered pages (no head-libs.php, not allow-listed): ["editor/index.php"]
 * Restored immediately after.
 * ==================================================================== */
console.log('');
console.log('Admin coverage — every admin page that emits a document includes head-libs.php (#1599):');

/* Admin pages that deliberately build their own <head> and therefore do
   NOT pick the monitor up from head-libs.php. Paths are relative to
   appWeb/public_html/manage/. Keep this list shrinking, never growing. */
const BESPOKE_HEAD_ADMIN_PAGES = [
    'editor/editor2.php',
    'editor/import2.php',
    'editor/index.php',
];

/** Recursively collect every *.php file under `dir` (relative paths). */
function collectPhpFiles(dir, base = dir) {
    const out = [];
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) out.push(...collectPhpFiles(full, base));
        else if (entry.isFile() && entry.name.endsWith('.php')) {
            out.push(path.relative(base, full).split(path.sep).join('/'));
        }
    }
    return out;
}

{
    /* A page "emits a document" when it opens <html> at the start of a
       line — deliberately narrower than a bare /<html/ search, which also
       matches the many doc-block sentences that merely MENTION `<html>`
       (admin-theme-init.php has three) and the one-line 403 `echo
       '<!DOCTYPE html><html …>'` bail-outs, neither of which is a page
       whose <head> we can wire anything into. */
    const EMITS_DOCUMENT_RE = /^[ \t]*<html[\s>]/m;
    const REQUIRES_HEAD_LIBS_RE = /\b(?:require|include)(?:_once)?\b[^;\n]*head-libs\.php/;

    const uncovered = [];
    const staleAllowlistEntries = [];

    for (const rel of collectPhpFiles(MANAGE_ROOT)) {
        const src = fs.readFileSync(path.join(MANAGE_ROOT, rel), 'utf8');
        if (!EMITS_DOCUMENT_RE.test(src)) continue;
        const hasHeadLibs = REQUIRES_HEAD_LIBS_RE.test(src);
        const allowed = BESPOKE_HEAD_ADMIN_PAGES.includes(rel);
        if (!hasHeadLibs && !allowed) uncovered.push(rel);
        if (hasHeadLibs && allowed) staleAllowlistEntries.push(rel);
    }

    check(
        'every /manage/* page that emits an HTML document includes head-libs.php (and so boots the error monitor)',
        uncovered.length === 0,
        `uncovered pages (no head-libs.php, not allow-listed): ${JSON.stringify(uncovered)}`
    );

    const missingAllowlistFiles = BESPOKE_HEAD_ADMIN_PAGES
        .filter(rel => !fs.existsSync(path.join(MANAGE_ROOT, rel)));
    check(
        'the bespoke-<head> allowlist is self-cleaning (no entry that has since been fixed or deleted)',
        staleAllowlistEntries.length === 0 && missingAllowlistFiles.length === 0,
        `now include head-libs.php (delete from the list): ${JSON.stringify(staleAllowlistEntries)}; `
        + `no longer exist (delete from the list): ${JSON.stringify(missingAllowlistFiles)}`
    );
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
