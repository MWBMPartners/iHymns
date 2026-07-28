/**
 * error-monitor.js — global uncaught-error / unhandled-rejection surfacing (#1582)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * ELI5: this is the app's smoke detector. Whenever ANY JavaScript on the
 * page throws an error nobody caught — or a Promise rejects and nobody
 * handled it — this file notices, tells the user (once, gently), and
 * quietly mails a small, scrubbed report back to the server so curators
 * can see it in /manage/activity-log without the user having to file a
 * bug.
 *
 * DETAIL:
 * Before this module, an uncaught client-side error was invisible unless
 * a user happened to open devtools and complain — the #1565/#1566 class
 * of "silent no-op" bugs (killed the whole Export feature for ~7 weeks)
 * is exactly what this closes the loop on. `bootErrorMonitor()` installs
 * two listeners — `window.addEventListener('error', …)` for synchronous
 * throws and `('unhandledrejection', …)` for rejected Promises nobody
 * `.catch()`-ed — and for every event it runs through, in order:
 *   1. Noise filter    — drop third-party/browser-quirk noise outright.
 *   2. Scrub           — strip anything token-shaped from message/stack.
 *   3. Build payload   — small, capped, privacy-safe JSON shape.
 *   4. Throttle        — three independent layers (see THROTTLE below).
 *   5. Toast           — at most once, generic copy, best-effort.
 *   6. Beacon          — `navigator.sendBeacon()` to the server, so the
 *                         report survives even if the tab is closing.
 *
 * RE-ENTRANCY (load-bearing — read before touching this file):
 *   Step 5 calls into `window.iHymnsApp.showToast()`, which manipulates
 *   the DOM (creates a Bootstrap Toast). If THAT throws, the exception
 *   would otherwise propagate — and because we are already inside a
 *   global error handler, an uncaught throw here could re-trigger this
 *   very handler, which could throw again while building/sending ITS
 *   toast, forever. The whole handler body therefore runs inside one
 *   `try { … } catch { /* swallow *\/ }`, wrapped by a `let inHandler`
 *   flag checked at entry and always cleared in `finally` — belt AND
 *   braces, because a `try/catch` alone doesn't stop a handler from
 *   being called twice for the SAME synchronous failure (see the
 *   `inHandler` check below for why that matters).
 *
 * PRIVACY (security requirement, not a nicety):
 *   The payload NEVER includes `location.search`, `location.hash`,
 *   cookies, or localStorage — only `location.pathname`. Every string
 *   field is run through `_scrub()`, which strips anything shaped like a
 *   64-hex-char token (session ids, API keys), a `Bearer <token>` header
 *   value, or a `?token=…`/`&password=…`-shaped query-string secret (M2,
 *   #1582 adversarial-review follow-up — `m`, the free-text error
 *   message, has no other query-string handling, unlike `r`/`s` which are
 *   pathname-only by construction), mirroring the server-side backstop in
 *   `includes/client_error_report.php::clientErrorScrub()` (defence in
 *   depth — a client can be tampered with, so the server scrubs again).
 *   A non-`Error` rejection reason (`Promise.reject(anything)`) is NEVER
 *   serialised wholesale — `_safeStringify()` reduces it to a shape-only
 *   summary (its `.name` if it has one, else `typeof`) so an arbitrary
 *   object's fields (a token, a cookie, user-typed content) can never
 *   reach the network via `JSON.stringify()`, which the string-shaped
 *   scrub patterns above cannot see into.
 *
 * THROTTLE — three independent layers, all must pass before a beacon is
 * sent (see `_shouldBeacon()`):
 *   1. Per-fingerprint (`message|source|line`), at most once per 10
 *      minutes, persisted in `sessionStorage` — survives a reload, so a
 *      crash-reload loop can't re-beacon on every reload.
 *   2. A hard cap of 10 beacons for the lifetime of THIS page load
 *      (in-memory — resets on navigation/reload; the sessionStorage
 *      layer above is what still protects a reload loop).
 *   3. After the first 3 beacons, a minimum 30 s gap between sends.
 * The toast has its own, separate throttle: at most once per 60 s AND
 * at most once per fingerprint (module-scope `Set`, resets on reload).
 *
 * TRANSPORT:
 * `navigator.sendBeacon()` first (survives page unload; the spec says it
 * MUST be a simple request, so it can never carry a custom header — see
 * the CSRF note in `api.php`'s `case 'client_error_report'`), falling
 * back to `fetch(…, {keepalive:true})` — the exact pattern
 * `js/modules/analytics.js`'s `_sendToEndpoint()` already uses.
 *
 * @see appWeb/public_html/includes/client_error_report.php  server-side validate/scrub/dedupe
 * @see appWeb/public_html/js/modules/analytics.js:334-353    the sendBeacon/fetch pattern this mirrors
 * @see https://developer.mozilla.org/docs/Web/API/Window/error_event
 * @see https://developer.mozilla.org/docs/Web/API/Window/unhandledrejection_event
 * @see https://developer.mozilla.org/docs/Web/API/Navigator/sendBeacon
 */

/* ── Tunables ──────────────────────────────────────────────────────────
   ELI5: these are the knobs that stop one bad bug from spamming the
   server (or the user) with reports.
   Detail: kept as named constants (not inline magic numbers) so the
   #1582 fail-proof tests can assert against them directly rather than
   re-deriving the same numbers. */
const DEDUPE_WINDOW_MS  = 10 * 60 * 1000; /* layer 1: per-fingerprint, sessionStorage-persisted */
const HARD_CAP          = 10;             /* layer 2: absolute beacons per page lifetime */
const SPACING_AFTER_N   = 3;              /* layer 3 kicks in after this many beacons... */
const MIN_SPACING_MS    = 30 * 1000;      /* ...requiring at least this long between sends */
const TOAST_WINDOW_MS   = 60 * 1000;      /* toast: at most one per this many ms */
const MAX_MESSAGE_CHARS = 300;            /* payload.m cap — mirrors the server's own cap */
const MAX_STACK_FRAMES  = 3;              /* payload.st — top-N frames only */

/* sessionStorage key for the per-fingerprint dedupe map (layer 1). Local
   module constant rather than a js/constants.js entry — matches the
   existing convention for sessionStorage keys (see LF_HOST_KEY in
   live-follow.js, SF_PRESENCE_KEY in service-follow.js, ACK_KEY in
   external-link-interstitial.js): constants.js's own doc-block scopes it
   to localStorage keys specifically ("prevent key-name drift #139"). */
const SEEN_KEY = 'ihymns_error_monitor_seen';

/* Secret-shaped substrings to strip from anything that might reach the
   server or a log line. Matches the server-side trio in
   includes/client_error_report.php::clientErrorScrub() byte-for-byte so
   "what the client redacts" and "what the server redacts" never drift. */
const BEARER_RE = /Bearer\s+\S+/gi;
const HEX64_RE  = /[0-9a-f]{64}/gi;

/* M2 (adversarial-review finding, OBS-VERIFY.md): `m` (the error message)
 * is free text — anything a `throw new Error(...)` call site happens to
 * interpolate ends up here, and a URL embedded in that text (e.g.
 * `throw new Error('Failed to fetch ' + url)` where `url` still carries
 * its query string) can carry exactly the kind of secret the `r`/`s`
 * fields deliberately strip (see the PRIVACY section above) — but `m`
 * had NO query-string handling at all before this pattern existed.
 * ELI5: looks for the part of a URL that says "...?token=xyz" or
 * "&password=xyz" and blanks out just the secret-looking VALUE, keeping
 * the parameter NAME so the report stays diagnostically useful (a
 * curator can still see "there was a token param", just not its value).
 * DETAIL: a closed allow-list of parameter-name keywords (not a deny-list
 * of "things that look secret") — token/auth/key/secret/password/sid/
 * session/code cover this app's own query-string vocabulary
 * (`X-Preferred-Languages` aside, grep the tree for `?token=`, `&sid=`,
 * `auth_token`, session ids, OAuth `code=` callbacks) plus the common
 * third-party shapes a `throw new Error(url)` might carry. `[^&\s]+`
 * (not `.+`) stops at the next `&` or whitespace so only the ONE
 * parameter's value is redacted, not the rest of the string. Mirrors
 * `includes/client_error_report.php::clientErrorScrub()`'s identical
 * pattern byte-for-byte (same drift-prevention rule as BEARER_RE/HEX64_RE
 * above). */
const QUERY_SECRET_RE = /([?&](?:token|auth|key|secret|password|sid|session|code)=)[^&\s]+/gi;

/* Browser-extension URL schemes an 'error' event's `filename` can carry
   when the failure is actually inside a browser extension's injected
   script, not this app's own code — noise this app can't fix and
   shouldn't report on. */
const EXTENSION_SCHEME_RE = /^(chrome|moz|safari)-extension:\/\//i;

/* ── Module-level state ──────────────────────────────────────────────
   ELI5: a few counters that remember "how many reports has THIS page
   load already sent" so the throttle layers above have something to
   check against. All reset to zero on the next page load/reload — the
   sessionStorage-backed layer 1 dedupe is the only piece that survives
   a reload (see SEEN_KEY above). */
let booted        = false; /* bootErrorMonitor() idempotency guard */
let inHandler      = false; /* re-entrancy guard — see file header */
let beaconCount     = 0;    /* layer 2: hard cap */
let lastBeaconAt    = 0;    /* layer 3: minimum spacing */
let lastToastAt     = 0;    /* toast throttle: time-based */
let toastedFingerprints = new Set(); /* toast throttle: per-fingerprint */

/**
 * Install the global error/rejection listeners. Safe to call more than
 * once — only the first call does anything (module-level `booted` flag).
 *
 * ELI5: turns the smoke detector on. Call this once, as early as
 * possible, so it's listening before anything else has a chance to fail.
 *
 * @returns {void}
 */
export function bootErrorMonitor() {
    if (booted) return;
    booted = true;

    /* `error` fires for synchronous throws AND for resource-load failures
       (e.g. a broken <img>); the noise filter below only inspects
       `event.message`, which resource-load ErrorEvents don't carry the
       same way — in practice they end up filtered as "empty source" or
       simply produce an unhelpful-but-harmless blank report, capped by
       the same throttle layers as everything else, so no special-casing
       is needed here. */
    window.addEventListener('error', (event) => handleError(event, 'error'));
    window.addEventListener('unhandledrejection', (event) => handleError(event, 'rejection'));
}

/**
 * Handle one 'error' or 'unhandledrejection' event end to end: filter,
 * scrub, build the payload, throttle, beacon, toast.
 *
 * ELI5: this is the one function that does everything above, in order,
 * wrapped so that NOTHING it does can ever cause another error.
 *
 * Detail: `inHandler` + the outer try/catch are deliberately redundant
 * (see the file header's RE-ENTRANCY section) — the flag stops
 * SYNCHRONOUS re-entrancy (a call made from inside this very call stack,
 * e.g. a toast library re-dispatching an error event synchronously
 * during construction), while the try/catch stops the exception from
 * ever becoming a NEW uncaught error that the listeners above would
 * pick up on a later tick.
 *
 * @param {ErrorEvent|PromiseRejectionEvent} event
 * @param {'error'|'rejection'} kind
 * @returns {void}
 */
function handleError(event, kind) {
    if (inHandler) return;
    inHandler = true;
    try {
        const extracted = _extract(event, kind);

        /* --- 1. Noise filter — drop and return, nothing further happens --- */
        if (_isExtensionOrEmpty(extracted.source)
            || extracted.message === 'Script error.'
            || extracted.message.includes('ResizeObserver loop')) {
            return;
        }

        /* --- 2. Scrub + 3. Build payload --- */
        const scrubbedMessage = _scrub(extracted.message).slice(0, MAX_MESSAGE_CHARS);
        const frames = _extractFrames(_scrub(extracted.stack));
        const cfg = window.iHymnsConfig || {}; /* degrade gracefully if absent (spec) */

        const payload = {
            m:  scrubbedMessage,
            s:  _pathOnly(extracted.source),
            l:  extracted.line,
            c:  extracted.col,
            r:  location.pathname, /* NEVER location.search / location.hash — see file header */
            v:  cfg.version ?? null,
            ch: cfg.devStatus ?? 'production', /* null on production (index.php's Development.Status) */
            k:  kind,
            st: frames,
        };

        /* --- 4. Throttle --- */
        const fp = `${payload.m}|${payload.s}|${payload.l}`;
        if (!_shouldBeacon(fp)) return;
        /* L10 (adversarial-review finding, OBS-VERIFY.md): mark the
           fingerprint sent / count it toward the hard cap ONLY once
           _sendReport() confirms a send was actually attempted — see
           that function's doc-block. Marking these BEFORE the send (the
           pre-fix order) meant a synchronous transport failure would
           suppress this exact fingerprint for 10 minutes (sessionStorage
           layer 1) and permanently burn one of the 10 hard-cap slots
           (layer 2) despite nothing having reached the server. */
        if (_sendReport(payload)) {
            _markFingerprintSent(fp);
            beaconCount += 1;
            lastBeaconAt = Date.now();
        }

        /* --- 5. Toast — AFTER the beacon attempt, so a throwing toast
           (see file header) can never prevent the report itself from
           being sent. Fires regardless of send success/failure — this
           is a user-facing "something broke" notice, independent of
           whether the diagnostic report actually reached the server. --- */
        _maybeToast(fp);
    } catch (_e) {
        /* Swallow. See file header RE-ENTRANCY note — this handler must
           never be the source of a new uncaught error. */
    } finally {
        inHandler = false;
    }
}

/* =========================================================================
 * EXTRACTION — normalise the two very different event shapes
 * (ErrorEvent vs PromiseRejectionEvent) into one common record.
 * ========================================================================= */

/**
 * @param {ErrorEvent|PromiseRejectionEvent} event
 * @param {'error'|'rejection'} kind
 * @returns {{message:string, source:string, line:number, col:number, stack:string}}
 */
function _extract(event, kind) {
    if (kind === 'error') {
        return {
            message: typeof event.message === 'string' ? event.message : String(event.message ?? ''),
            source:  typeof event.filename === 'string' ? event.filename : '',
            line:    Number.isFinite(event.lineno) ? event.lineno : 0,
            col:     Number.isFinite(event.colno) ? event.colno : 0,
            stack:   (event.error && typeof event.error.stack === 'string') ? event.error.stack : '',
        };
    }

    /* 'rejection' — a PromiseRejectionEvent's `reason` can be literally
       anything a Promise was rejected with (an Error, a string, undefined,
       a plain object, …), not just an Error. There is no filename/line/col
       for a rejection, so `source` falls back to the current page path —
       always same-origin, so it never trips the noise filter's
       extension/empty check below. */
    const reason = event.reason;
    const isError = reason instanceof Error;
    return {
        message: isError ? (reason.message || String(reason)) : _safeStringify(reason),
        source:  location.pathname,
        line:    0,
        col:     0,
        stack:   isError && typeof reason.stack === 'string' ? reason.stack : '',
    };
}

/**
 * Best-effort string form of an arbitrary rejection reason. Never throws.
 *
 * M2 (adversarial-review finding, OBS-VERIFY.md): this used to fall
 * through to `JSON.stringify(reason)` for any non-Error, non-primitive
 * reason. `Promise.reject()` accepts literally anything, and
 * `JSON.stringify()` serialises EVERY enumerable field of an arbitrary
 * object wholesale — `Promise.reject({token, password, email})`,
 * `Promise.reject({url, body: userTypedLyrics})`, even a `Response`
 * object with fields that happen to stringify to something sensitive —
 * straight into the beacon's `m` field. `_scrub()`'s patterns (BEARER_RE
 * / HEX64_RE / QUERY_SECRET_RE) only match STRING SHAPES; they cannot
 * protect against a field name they've never seen, so a wholesale object
 * dump was a standing bypass of the scrub layer entirely, not merely an
 * unscrubbed edge case.
 *
 * ELI5: if a promise was rejected with a real error, or a plain string/
 * number, that's still safe to report as-is (and still gets scrubbed
 * downstream). But if it was rejected with some ARBITRARY OBJECT, this
 * never looks inside it — it just says roughly "an object of this shape
 * was thrown", never what's actually in it.
 *
 * @param {*} reason
 * @returns {string}
 */
function _safeStringify(reason) {
    if (reason === undefined) return 'undefined';
    if (reason === null) return 'null';
    if (typeof reason === 'string') return reason;
    if (typeof reason === 'number' || typeof reason === 'boolean' || typeof reason === 'bigint') {
        return String(reason);
    }
    /* Object / array / function / symbol reason — SHAPE ONLY, never field
       contents. `.name` is read defensively (a getter on an untrusted
       object could itself throw) and only trusted if it's already a
       short-ish string, matching how DOMException/error-like objects
       self-describe (e.g. 'AbortError'). Anything else degrades to
       `typeof reason` ('object' / 'function' / 'symbol'). */
    try {
        const name = (typeof reason === 'object' && reason !== null && typeof reason.name === 'string' && reason.name !== '')
            ? reason.name
            : typeof reason;
        return `[non-Error rejection: ${name}]`.slice(0, 100);
    } catch {
        return `[non-Error rejection: ${typeof reason}]`;
    }
}

/* =========================================================================
 * NOISE FILTER
 * ========================================================================= */

/**
 * ELI5: "did this come from a browser extension, or nowhere at all?" —
 * if so, it's not this app's bug to report.
 * Detail: a same-origin script always has a real `https://…` filename; an
 * empty filename or an extension-scheme URL means the failing code isn't
 * ours (a content script, or a cross-origin script the browser refuses
 * to disclose details for — those surface as message === 'Script error.',
 * filtered separately above).
 */
function _isExtensionOrEmpty(source) {
    if (!source) return true;
    return EXTENSION_SCHEME_RE.test(source);
}

/* =========================================================================
 * SCRUB
 * ========================================================================= */

/**
 * Strip anything token-shaped from a string before it can reach the
 * network or sessionStorage. Mirrors
 * includes/client_error_report.php::clientErrorScrub() — see that file's
 * doc-block for why the server scrubs AGAIN on top of this.
 *
 * @param {string} s
 * @returns {string}
 */
function _scrub(s) {
    if (typeof s !== 'string' || s === '') return '';
    return s
        .replace(BEARER_RE, 'Bearer [redacted]')
        .replace(HEX64_RE, '[redacted]')
        .replace(QUERY_SECRET_RE, '$1[redacted]'); /* M2 — see QUERY_SECRET_RE above */
}

/**
 * Reduce a raw stack trace string to at most `MAX_STACK_FRAMES` frames,
 * each rendered as `pathname:line` — no function names, no columns, no
 * query strings, no full URL (host is implicit — this app never reports
 * on a stack frame belonging to a different origin's script; those are
 * already filtered above).
 *
 * ELI5: turns a big scary multi-line stack trace into 3 short lines like
 * `/js/app.js:1204`, safe to store and small enough to read at a glance.
 *
 * Detail: browsers format stack frames differently (Chrome:
 * `at fn (https://host/path.js:10:5)`, Firefox: `fn@https://host/path.js:10:5`)
 * but both always contain a `https://host/path:line:col` substring, so one
 * regex over the whole string — rather than a per-browser parser — finds
 * every frame regardless of engine.
 *
 * @param {string} stack
 * @returns {string[]}
 */
function _extractFrames(stack) {
    if (typeof stack !== 'string' || stack === '') return [];
    const frameRe = /(https?:\/\/[^\s)]+):(\d+):(\d+)/g;
    const frames = [];
    let match;
    while (frames.length < MAX_STACK_FRAMES && (match = frameRe.exec(stack)) !== null) {
        const path = _pathOnly(match[1]);
        if (path) frames.push(`${path}:${match[2]}`);
    }
    return frames;
}

/**
 * Resolve any URL-ish string down to just its pathname (dropping origin,
 * query string, and hash — the query-string omission is what keeps
 * secrets like `?token=…` out of stack frames, mirroring the `r` field's
 * own location.search ban above).
 *
 * @param {string} urlLike
 * @returns {string} Pathname, or '' if `urlLike` isn't parseable.
 */
function _pathOnly(urlLike) {
    if (!urlLike) return '';
    try {
        return new URL(urlLike, location.href).pathname;
    } catch {
        return '';
    }
}

/* =========================================================================
 * THROTTLE — layers 1-3 (see file header)
 * ========================================================================= */

/**
 * @param {string} fp Fingerprint (`message|source|line`).
 * @returns {boolean} True if all three throttle layers allow a send.
 */
function _shouldBeacon(fp) {
    if (_wasFingerprintSentRecently(fp)) return false;          /* layer 1 */
    if (beaconCount >= HARD_CAP) return false;                  /* layer 2 */
    if (beaconCount >= SPACING_AFTER_N
        && (Date.now() - lastBeaconAt) < MIN_SPACING_MS) return false; /* layer 3 */
    return true;
}

/** Read+parse the sessionStorage dedupe map. Never throws; degrades to `{}`. */
function _loadSeenMap() {
    try {
        const raw = sessionStorage.getItem(SEEN_KEY);
        const parsed = raw ? JSON.parse(raw) : {};
        return (parsed && typeof parsed === 'object') ? parsed : {};
    } catch {
        return {};
    }
}

/** Persist the dedupe map. Best-effort — a full/unavailable sessionStorage
 *  (e.g. some private-browsing modes) just means layer 1 stops working;
 *  layers 2 and 3 (in-memory) still bound the damage. */
function _saveSeenMap(map) {
    try {
        sessionStorage.setItem(SEEN_KEY, JSON.stringify(map));
    } catch {
        /* best-effort — see comment above */
    }
}

/** @returns {boolean} True if `fp` was already sent within DEDUPE_WINDOW_MS. */
function _wasFingerprintSentRecently(fp) {
    const seenAt = _loadSeenMap()[fp];
    return typeof seenAt === 'number' && (Date.now() - seenAt) < DEDUPE_WINDOW_MS;
}

/** Record that `fp` was just sent, pruning entries outside the window so
 *  the sessionStorage map can't grow unboundedly across a long tab
 *  lifetime (a stale entry is, by definition, no longer useful for the
 *  "sent in the last 10 minutes?" check). */
function _markFingerprintSent(fp) {
    const now = Date.now();
    const map = _loadSeenMap();
    map[fp] = now;
    for (const key of Object.keys(map)) {
        if ((now - map[key]) > DEDUPE_WINDOW_MS) delete map[key];
    }
    _saveSeenMap(map);
}

/* =========================================================================
 * TOAST — separate throttle from the beacon (time-based AND per-fingerprint)
 * ========================================================================= */

/**
 * Show a generic "something broke" toast, at most once per 60s and at
 * most once per fingerprint, and ONLY if the host app actually exposes a
 * toast function (defensive — this module boots before `iHymnsApp` has
 * necessarily finished constructing itself; see app.js).
 *
 * ELI5: never shows the scary real error text to the user — just a
 * friendly, generic heads-up, and never spams them with more than one.
 *
 * @param {string} fp
 * @returns {void}
 */
function _maybeToast(fp) {
    if (typeof window.iHymnsApp?.showToast !== 'function') return;
    if (toastedFingerprints.has(fp)) return;
    const now = Date.now();
    if ((now - lastToastAt) < TOAST_WINDOW_MS) return;

    toastedFingerprints.add(fp);
    lastToastAt = now;
    /* Generic copy only — NEVER the raw error message (rule: never expose
       error internals to the end user; also keeps the toast readable —
       raw stack-trace text is not something a user can act on). */
    window.iHymnsApp.showToast(
        'Something went wrong. If this keeps happening, try reloading the page.',
        'warning',
        4000
    );
}

/* =========================================================================
 * TRANSPORT — sendBeacon with fetch(keepalive) fallback
 * ========================================================================= */

/** The one server-side action this module ever POSTs to. */
const REPORT_ENDPOINT = '/api?action=client_error_report';

/**
 * Send the (already scrubbed + capped) payload to the server. Mirrors
 * Analytics#_sendToEndpoint() (js/modules/analytics.js:334-353) exactly —
 * sendBeacon first (works even as the tab/page is unloading), fetch as
 * the fallback for browsers/situations where sendBeacon is unavailable
 * or refuses the send.
 *
 * L10 (adversarial-review finding, OBS-VERIFY.md): now returns whether a
 * send was actually ATTEMPTED without throwing, so the caller
 * (`handleError()`) can gate marking the fingerprint as sent / counting
 * it toward the hard cap on that — see this function's call site.
 * ELI5: this used to just fire-and-forget with no way for the caller to
 * know "did that even get past the starting line?" Now it hands back a
 * yes/no so the throttle bookkeeping isn't lied to.
 * DETAIL: `new Blob()` / `navigator.sendBeacon()` are wrapped in their own
 * try/catch — the spec says `sendBeacon()` should never throw, but a
 * broken polyfill, a hardened/sandboxed embed, or `Blob` being
 * unavailable could still make either throw SYNCHRONOUSLY. Before this
 * fix, that throw would propagate out of `_sendReport()` into
 * `handleError()`'s own try/catch (swallowed there), but only AFTER the
 * caller had already marked the fingerprint sent and incremented the
 * hard-cap counter — so a report that never left the browser would still
 * suppress that exact bug from being reported again for the next 10
 * minutes (sessionStorage layer 1) and permanently eat one slot of the
 * 10-beacon hard cap (layer 2). A synchronous sendBeacon/Blob throw now
 * falls through to the fetch fallback instead of being treated as
 * success. The fetch fallback itself stays fire-and-forget (its actual
 * network outcome is inherently asynchronous — `.catch()` swallows a
 * LATER delivery failure exactly as before, since there is no way to
 * know that outcome synchronously); only a SYNCHRONOUS throw from the
 * `fetch()` call itself (not its returned Promise rejecting) counts as
 * "not even attempted" here.
 *
 * @param {object} payload
 * @returns {boolean} True if a send was attempted (queued) without a
 *                     synchronous throw; false if every transport threw.
 */
function _sendReport(payload) {
    const json = JSON.stringify(payload);

    if (navigator.sendBeacon) {
        try {
            const blob = new Blob([json], { type: 'application/json' });
            if (navigator.sendBeacon(REPORT_ENDPOINT, blob)) return true;
        } catch (_e) {
            /* new Blob()/sendBeacon() threw synchronously (L10) — fall
               through to the fetch fallback below rather than treating
               this as a successful send. */
        }
    }

    try {
        fetch(REPORT_ENDPOINT, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                /* sendBeacon() CANNOT set custom headers at all (it's a "simple
                   request" by spec) — this fetch fallback can, so it still
                   sends the app's usual same-origin marker for defence in
                   depth. The server doesn't strictly require it for THIS
                   action though: api.php's no-JS same-origin allow-list
                   includes 'client_error_report' precisely because the
                   PRIMARY transport (sendBeacon) can never carry it — see
                   that file's CSRF policy block. */
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: json,
            keepalive: true,
        }).catch(() => {
            /* Best-effort — a network failure while reporting an error must
               not itself become a new error (would defeat the whole point of
               this module and could cascade through the throttle layers). */
        });
        return true;
    } catch (_e) {
        /* fetch() itself threw SYNCHRONOUSLY (defensive — real browsers
           don't do this, but a broken polyfill or a CSP-blocked global
           might) — nothing was even queued for delivery. */
        return false;
    }
}

/* =========================================================================
 * TEST-ONLY EXPORTS
 *
 * tests/test-error-monitor.js drives the full filter → scrub → throttle →
 * toast → beacon pipeline directly (constructing plain objects that look
 * like ErrorEvent/PromiseRejectionEvent — no real DOM/browser needed) by
 * stubbing `window`/`navigator`/`sessionStorage`/`location` as globals and
 * calling these two functions. Production code (bootErrorMonitor(), called
 * from app.js) never uses these — real events reach `handleError` only via
 * the addEventListener calls above.
 * ========================================================================= */

/** @see handleError — same function, exported under a test-only name so
 *  production call sites never accidentally invoke it directly. */
export function __handleErrorForTests(event, kind) {
    return handleError(event, kind);
}

/** Reset all module-level counters/guards between test cases. */
export function __resetForTests() {
    booted = false;
    inHandler = false;
    beaconCount = 0;
    lastBeaconAt = 0;
    lastToastAt = 0;
    toastedFingerprints = new Set();
}
