/**
 * iHymns — shared API client (#1031)
 *
 * ELI5: one place that knows how to talk to our server. Everything that needs
 * data asks this, instead of each screen inventing its own way.
 *
 * ============================================================================
 * WHY THIS EXISTS — the thing it replaces
 * ============================================================================
 *
 * Before this module, ~80 `fetch()` calls across ~35 modules each re-invented
 * headers, error handling and offline signalling. Worse, ONE cross-cutting
 * concern — the `X-Preferred-Languages` header — was solved by REPLACING THE
 * GLOBAL `window.fetch` (`songbook-language-filter.js`).
 *
 * A global fetch override is a poor trade for two reasons this codebase learned
 * the hard way:
 *
 *  1. IT SITS IN FRONT OF EVERY REQUEST ON THE SITE. A bug in the header logic
 *     is not a missing header — it is a FAILED REQUEST, surfacing wherever that
 *     caller happens to catch it. #1593 was exactly this: the patch read
 *     `input.url` on a `URL` object (which exposes `href`), got `undefined`,
 *     called `.startsWith` on it and threw. Ten unrelated call sites broke and
 *     it presented as "Song of the Day disappears when I pick two languages".
 *
 *  2. IT ONLY APPLIES IF SOMETHING INSTALLED IT. The patch was installed by
 *     `bootSongbookLanguageFilter()`, which the router imports only on
 *     `home` / `songbooks` / `settings`. On a cold load of `/search`, nothing
 *     installed it, `search.js` sends no `lang=` param, and an ANONYMOUS user's
 *     saved language filter was silently ignored. Signed-in users never noticed
 *     because the server falls back to their account preference.
 *
 * Both failure modes are the same species: a cross-cutting concern attached by
 * side effect rather than by structure. This module attaches it by structure —
 * every request reads the preference itself, so it cannot be half-installed.
 *
 * ============================================================================
 * WHAT IT DOES NOT DO
 * ============================================================================
 *
 * It does not wrap `window.fetch`, and installing it must never become
 * "call `installApiClient()` early enough". Callers import `apiFetch` and use
 * it. Anything still calling bare `fetch()` keeps working exactly as it does
 * today — this is additive, never a global override.
 *
 * The service worker deliberately keeps native `fetch`: it runs in a different
 * global scope, has no `localStorage`, and its requests are not user-scoped.
 *
 * @see https://developer.mozilla.org/en-US/docs/Web/API/fetch
 * @see https://fetch.spec.whatwg.org/#request-class
 */

import { EVT_FETCH_FAILED, EVT_FETCH_SUCCEEDED, STORAGE_LANGUAGE_FILTER } from '../constants.js';

/**
 * Resolve the request URL from any shape `fetch()` accepts, ALWAYS returning
 * a string (#1593).
 *
 * ELI5: work out the web address, whatever form it was handed to us in.
 *
 * `fetch()` accepts `string | URL | Request`. These expose the URL under three
 * DIFFERENT property names — a bare string, `URL.href`, and `Request.url` — and
 * getting it wrong does not throw where you'd notice. Reading a missing
 * property returns `undefined`, so the failure lands one line later, outside
 * whatever `try` you wrapped the access in. That is precisely how #1593 escaped
 * its own error handling.
 *
 * The `URL`-object case is the regression test.
 *
 * @param {string|URL|Request} input
 * @returns {string} The URL, or '' if it could not be determined.
 */
export function requestUrlOf(input) {
    if (typeof input === 'string') return input;
    if (typeof URL !== 'undefined' && input instanceof URL) return input.href;
    /* Request, or anything else exposing a string `url`. */
    if (input && typeof input.url === 'string') return input.url;
    /* Last resort — stringify rather than throw. An unrecognised value yields
       '', which the caller treats as same-origin: the safe default is
       attaching our own header to our own request. */
    try { return String(input ?? ''); } catch (_e) { return ''; }
}

/**
 * Is this URL same-origin?
 *
 * Relative URLs ('/api?…', 'data/x.json') are ours by definition. Absolute ones
 * must match `location.origin` exactly — we must never leak a user's language
 * preference or Authorization header to a third-party endpoint.
 *
 * @param {string} url
 * @returns {boolean}
 */
function isSameOrigin(url) {
    if (!url) return true;                       /* unresolvable → treat as ours */
    if (!/^[a-z][a-z0-9+.-]*:/i.test(url)) return true;  /* relative → ours */
    try {
        return new URL(url, window.location.href).origin === window.location.origin;
    } catch (_e) {
        return false;                            /* unparseable absolute → not ours */
    }
}

/**
 * Read the active language-filter subtags — the SINGLE source of truth.
 *
 * Read fresh on every request rather than cached at install time. That is the
 * structural fix for failure mode (2) above: there is no install step to miss,
 * so a cold load of any route sends the header the user actually chose.
 *
 * Returns '' when "All" is selected or anything is malformed, which callers
 * treat as "send no header".
 *
 * @returns {string} Comma-separated subtags, or ''.
 */
function preferredLanguagesCsv() {
    try {
        const raw = localStorage.getItem(STORAGE_LANGUAGE_FILTER);
        if (!raw) return '';
        const parsed = JSON.parse(raw);
        if (!Array.isArray(parsed)) return '';
        return parsed
            .filter((s) => typeof s === 'string' && /^[a-z]{2,3}$/.test(s))
            .join(',');
    } catch (_e) {
        /* Private-mode localStorage throw, or corrupt JSON — no filter. */
        return '';
    }
}

/**
 * Auth-header provider, injected rather than imported.
 *
 * `user-auth.js` owns the token, but importing it here would create a cycle
 * (user-auth → api-client → user-auth) that bundlers resolve in load order —
 * i.e. sometimes `undefined` at call time, non-deterministically. Injection
 * makes the dependency one-directional and the failure mode impossible.
 *
 * @type {null | (() => Object)}
 */
let authHeaderProvider = null;

/**
 * Register the function that supplies auth headers. Called once from app boot.
 *
 * @param {() => Object} provider Returns e.g. { Authorization: 'Bearer …' }
 */
export function setAuthHeaderProvider(provider) {
    authHeaderProvider = typeof provider === 'function' ? provider : null;
}

/**
 * Build the merged Headers for a request.
 *
 * THE `Request` TRAP. When `input` is a `Request` and you pass an `init` with
 * `headers`, the init's header list **replaces** the Request's own — it does
 * not merge. The old monkey-patch did exactly that, so `fetch(new Request(url,
 * {headers: {Authorization: …}}))` would have silently lost its Authorization
 * header. Nothing passed a `Request` yet, so it never fired — but "latent" and
 * "fixed" are different states. We seed from the Request's headers first.
 *
 * @param {string|URL|Request} input
 * @param {RequestInit} init
 * @param {boolean} sameOrigin
 * @param {boolean} withAuth
 * @returns {Headers}
 */
function buildHeaders(input, init, sameOrigin, withAuth) {
    const headers = new Headers();

    /* 1. Seed from the Request object, if that's what we were given. */
    if (typeof Request !== 'undefined' && input instanceof Request) {
        for (const [k, v] of input.headers.entries()) headers.set(k, v);
    }
    /* 2. Overlay anything the caller passed in init — caller wins. */
    if (init && init.headers) {
        for (const [k, v] of new Headers(init.headers).entries()) headers.set(k, v);
    }

    /* 3. Our additions, same-origin only, and never clobbering the caller. */
    if (sameOrigin) {
        /* Marks the request as same-origin AJAX for validateCsrfRequest()
           (#1590 / rule #29) — a browser cannot set a custom header
           cross-origin without a CORS preflight this server never grants. */
        if (!headers.has('X-Requested-With')) {
            headers.set('X-Requested-With', 'XMLHttpRequest');
        }
        if (!headers.has('X-Preferred-Languages')) {
            const csv = preferredLanguagesCsv();
            if (csv !== '') headers.set('X-Preferred-Languages', csv);
        }
        if (withAuth && authHeaderProvider) {
            try {
                for (const [k, v] of Object.entries(authHeaderProvider() || {})) {
                    if (!headers.has(k)) headers.set(k, v);
                }
            } catch (_e) {
                /* A broken provider must not break the request. */
            }
        }
    }

    return headers;
}

/**
 * The shared fetch.
 *
 * Signature-compatible with `fetch()` plus one extra option, so migrating a
 * call site is usually just changing the function name.
 *
 * @param {string|URL|Request} input
 * @param {RequestInit & {auth?: boolean}} [init]
 * @returns {Promise<Response>}
 */
export async function apiFetch(input, init = {}) {
    const { auth = false, ...rest } = init || {};
    const url = requestUrlOf(input);
    const sameOrigin = isSameOrigin(url);

    const nextInit = { ...rest };
    nextInit.headers = buildHeaders(input, rest, sameOrigin, auth);

    let response;
    try {
        response = await fetch(input, nextInit);
    } catch (err) {
        /* Network-level failure (DNS, offline, captive portal, CORS block).
           A more reliable offline signal than navigator.onLine, which reports
           "online" on a captive-portal Wi-Fi that answers nothing. The
           offline indicator listens for this (#1031 gives it sitewide reach;
           previously only search.js dispatched it). */
        dispatch(EVT_FETCH_FAILED, { url, error: String(err) });
        throw err;
    }

    /* A 503 from this app is a deliberate, documented state — maintenance mode
       or a DB outage (WS-K #1021) — carrying Retry-After and, for actions, a
       JSON {error, request_id}. It is NOT a network failure, so it must not
       flip the offline indicator: the server is plainly reachable. Surfaced as
       its own signal so a caller can show the real reason. */
    if (response.status === 503) {
        dispatch(EVT_FETCH_FAILED, {
            url,
            maintenance: true,
            retryAfter: Number(response.headers.get('Retry-After')) || null,
        });
        return response;
    }

    dispatch(EVT_FETCH_SUCCEEDED, { url, status: response.status });
    return response;
}

/**
 * Convenience: fetch and parse JSON, with the SPA-shaped 404 trap handled.
 *
 * THE TRAP (#1566): this app's `.htaccess` catch-all answers any unmatched path
 * with **HTTP 200 + the HTML shell**. So a wrong URL does not 404 — `response.ok`
 * is true, and the failure surfaces as a baffling `SyntaxError` from `.json()`
 * pointing at "Unexpected token '<'". We detect the HTML and say what actually
 * happened.
 *
 * @param {string|URL|Request} input
 * @param {RequestInit & {auth?: boolean}} [init]
 * @returns {Promise<any>} Parsed JSON.
 * @throws {Error} On network failure, non-OK status, or an HTML response.
 */
export async function apiFetchJson(input, init = {}) {
    const response = await apiFetch(input, init);
    const contentType = response.headers.get('Content-Type') || '';

    if (!contentType.includes('json')) {
        const url = requestUrlOf(input);
        throw new Error(
            `Expected JSON from ${url} but received "${contentType || 'no content-type'}" `
            + `(HTTP ${response.status}). On this SPA an unmatched path returns the HTML `
            + `shell with status 200 — check the URL is root-absolute (#1566).`
        );
    }
    if (!response.ok) {
        throw new Error(`Request to ${requestUrlOf(input)} failed with HTTP ${response.status}`);
    }
    return response.json();
}

/**
 * Dispatch a window event, never letting a listener's throw reach the caller.
 *
 * @param {string} name Event name — always from constants.js (#1581).
 * @param {Object} detail
 */
function dispatch(name, detail) {
    try {
        window.dispatchEvent(new CustomEvent(name, { detail }));
    } catch (_e) {
        /* A listener throwing must not turn a successful request into a
           failed one. */
    }
}
