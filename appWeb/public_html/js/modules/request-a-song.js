/**
 * iHymns — "Request a Song" form controller (#337, #711, #1572)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * ELI5: this is the code that runs the /request page — it takes over the
 * Send Request button so the form submits in the background (no page
 * reload), shows a "thank you" / "saved offline" / "something went wrong"
 * banner, and re-sends anything that was saved while the phone had no
 * signal.
 *
 * DETAILED:
 * Consumes ONE endpoint, `POST /api?action=song_request_submit`, in two
 * shapes that the endpoint distinguishes by `Content-Type`:
 *   - `application/json` (this module)          → JSON `{ok, trackingId}`
 *   - `application/x-www-form-urlencoded` (the  → 302 back to /request with
 *     no-JS `<form action=…>` fallback, #711)      `?submitted=1&id=…`
 * Both surfaces work independently and neither is a prerequisite for the
 * other, so a browser with JS disabled — or one that fails to fetch this
 * module — still gets a working form and a server-rendered banner.
 * `X-Requested-With: XMLHttpRequest` is sent so the write clears api.php's
 * same-origin CSRF gate (`validateCsrfRequest()`, rule #29 in
 * .claude/CLAUDE.md) without this page baking a per-render session token.
 *
 * ── WHY THIS IS A MODULE AND NOT AN INLINE <script> (#1565 / #1569 / #1572)
 * This logic used to live as a `<script type="module">` at the bottom of
 * `includes/pages/request-a-song.php`. It never ran for ANY visitor:
 *   1. `index.php` sends an ENFORCING nonce CSP — `script-src 'self'
 *      'nonce-<per-request>'`, no `unsafe-inline` (#117). An inline
 *      `<script>` without that exact nonce is refused.
 *      https://developer.mozilla.org/docs/Web/HTTP/Headers/Content-Security-Policy/script-src
 *   2. The fragment is a SEPARATE HTTP response (`/api?page=request`)
 *      fetched after the document already rendered, so there is no live
 *      nonce left to stamp it with — and `request` sits in api.php's
 *      `$_cacheablePages` (ETag + `Cache-Control: private, max-age=300`),
 *      so the same bytes are replayed for up to 5 minutes and could not
 *      carry any single request's nonce even in principle (rule #6).
 *   3. `router.js`'s `_executeInlineScripts()` re-creates injected
 *      `<script>` nodes copying attributes VERBATIM, so the re-created node
 *      has no nonce either — the browser refuses it SILENTLY. Console
 *      violation only: no exception, no toast, nothing the surrounding
 *      Bootstrap markup stops doing. The form still *looked* alive because
 *      the #711 no-JS fallback quietly took every submit.
 * The one correct fix is the `home-page.js` / `export-ui.js` shape: a real
 * ES module (allowed by `script-src 'self'`), imported by the SPA router's
 * `afterPageLoad()` once the fragment is in the DOM, reading its inputs
 * DOM-first from `data-*` the fragment already emits. Never "fix" the class
 * by injecting a nonce into a fragment, adding `unsafe-inline`, or relaxing
 * `_executeInlineScripts()`. CI guard:
 * `tests/php/test-fragment-inline-scripts.php` (its allowlist is now empty
 * — this file was the last entry).
 *
 * NOT TO BE CONFUSED WITH `js/modules/request.js` (#107), the older
 * `mailto:`-based "report a missing song" class wired into app.js. This
 * module owns the DB-backed /request page (`tblSongRequests`).
 */

import { offlineQueue } from './offline-queue.js';
import { apiFetch } from '../utils/api-client.js';

/** Queue bucket name. MUST match the SW's `ihymns-queue-song-requests`
 *  Background Sync tag (offline-queue.js builds the tag from this). */
const QUEUE_TYPE = 'song-requests';

/** The single write endpoint, shared with the no-JS `<form action>`. */
const SUBMIT_ENDPOINT = '/api?action=song_request_submit';

/* Prefill caps. Deliberately identical to the inputs' `maxlength` and to
   the `mb_substr()` caps in includes/pages/request-a-song.php, so a crafted
   deep link can't push a longer value in through the client path than the
   server path would have allowed. */
const MAX_SONGBOOK = 100;
const MAX_NUMBER   = 500;

/**
 * Has `offlineQueue.bindAutoDrain()` already been wired for this page load?
 *
 * ELI5: the drain listeners live on the browser window, not on the form, so
 * they survive moving to another page — wire them once and only once.
 * Detail: `initRequestASong()` is called by the router on EVERY navigation
 * to /request, but `bindAutoDrain()` attaches a `window` `online` listener
 * plus a `navigator.serviceWorker` `message` listener, neither of which is
 * removed when the fragment is swapped out. Re-calling it per visit would
 * accumulate listeners and run N concurrent drains over the same IndexedDB
 * rows. Module-level state is the right scope: an ES module is evaluated
 * once per document, so this resets exactly when the listeners do (on a
 * real page load).
 * https://developer.mozilla.org/docs/Web/JavaScript/Guide/Modules
 */
let _autoDrainBound = false;

/**
 * Collect the banner/button elements, scoped to the page section.
 *
 * Returns nulls rather than throwing when an element is missing — every
 * caller null-checks, so a fragment that was trimmed (or a stale
 * service-worker copy predating one of these ids) degrades instead of
 * throwing an uncaught rejection into the console.
 *
 * @param {ParentNode} root The `.page-request-a-song` section.
 */
function collectUi(root) {
    return {
        ok:     root.querySelector('#request-success'),
        queued: root.querySelector('#request-queued'),
        err:    root.querySelector('#request-error'),
        count:  root.querySelector('#request-queued-count'),
        btn:    root.querySelector('#request-submit-btn'),
    };
}

/** Hide all three result banners before a new submit attempt. */
function hideAll(ui) {
    ui.ok?.classList.add('d-none');
    ui.queued?.classList.add('d-none');
    ui.err?.classList.add('d-none');
}

/** Show the danger banner with `message`. */
function showError(ui, message) {
    if (!ui.err) return;
    ui.err.textContent = message;
    ui.err.classList.remove('d-none');
}

/**
 * POST one request payload as JSON.
 *
 * Reused by BOTH the live submit and the offline drain, so the two paths
 * can never drift in what they send. Resolves with the parsed body on
 * success; throws on transport failure (fetch's own `TypeError`) or on an
 * application-level failure, which lets the caller tell the two apart.
 *
 * @param {object} payload
 * @returns {Promise<object>} `{ok: true, trackingId?: number}`
 */
async function send(payload) {
    const res = await apiFetch(SUBMIT_ENDPOINT, {
        method:  'POST',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body:    JSON.stringify(payload),
    });
    /* A 500 can return an HTML error page, so tolerate a JSON parse
       failure and fall through to the generic message below. */
    const data = await res.json().catch(() => ({}));
    if (!res.ok || !data.ok) {
        throw new Error(data.error || 'Request failed.');
    }
    return data;
}

/**
 * Drain adapter for `offlineQueue.drain()`.
 *
 * The queue's contract: resolve truthy → the row is deleted; resolve falsy
 * or throw → the row is KEPT for the next attempt. Letting `send()`'s throw
 * propagate is therefore the correct retry behaviour, not an oversight.
 */
async function drainSend(payload) {
    await send(payload);
    return true;
}

/**
 * Persist a payload to the offline queue and report the outcome in the UI.
 *
 * ELI5: try to save the request on the device; only say "saved offline" if
 * it really was saved.
 * Detail: `offlineQueue.enqueue()` resolves to `null` (it does NOT throw)
 * when IndexedDB is unusable — Firefox private browsing, Safari inside a
 * cross-origin iframe, or a locked-down extension (#354). The inline
 * version this module replaces only had a `try/catch`, so a `null` id fell
 * through to the success path and told the user "Saved offline — we'll send
 * this as soon as you're back online" about a request that had been
 * silently dropped. Checking the return value explicitly is the fix.
 *
 * @returns {Promise<boolean>} true if the payload is durably queued.
 */
async function enqueueAndReport(ui, form, payload, failMessage) {
    let id = null;
    try {
        id = await offlineQueue.enqueue(QUEUE_TYPE, payload);
    } catch (_err) {
        /* enqueue() fail-softs internally, so reaching here means something
           unexpected — treated identically to a null id. */
        id = null;
    }
    if (id === null || id === undefined) {
        showError(ui, failMessage);
        return false;
    }
    if (ui.count) ui.count.textContent = `Reference: offline-#${id}`;
    ui.queued?.classList.remove('d-none');
    form.reset();
    return true;
}

/**
 * Wire the "replay queued requests once we're back online" triggers, once.
 *
 * The result callback re-queries the DOM on every invocation instead of
 * closing over the elements from the first visit: a drain can complete long
 * after the user navigated away, at which point the original nodes have
 * been discarded by the router's `innerHTML` swap and writing to them would
 * update a detached tree nobody can see. If the /request page isn't on
 * screen we simply skip the banner — the rows are already sent either way.
 */
function bindAutoDrainOnce() {
    if (_autoDrainBound) return;
    _autoDrainBound = true;

    offlineQueue.bindAutoDrain(QUEUE_TYPE, drainSend, (r) => {
        if (!r || !r.sent) return;
        const queuedEl = document.getElementById('request-queued');
        const countEl  = document.getElementById('request-queued-count');
        if (!queuedEl || !countEl) return;   // navigated away — nothing to show
        countEl.textContent = `Flushed ${r.sent} offline request${r.sent === 1 ? '' : 's'}.`;
        queuedEl.classList.remove('d-none');
    });
}

/**
 * Resolve one deep-link prefill value, DOM-first.
 *
 * ELI5: prefer the value the server already worked out; only fall back to
 * reading it out of the address bar if the server didn't provide one.
 * Detail: order is (1) the `data-prefill-*` attribute the fragment emits,
 * (2) the router's parsed route params, (3) `window.location.search`. (1)
 * is authoritative because the server RESOLVES `?songbook=MP` to "Mission
 * Praise" via tblSongbooks (#683) — reading the raw query param first would
 * overwrite a friendly name with an abbreviation. (2) and (3) exist for the
 * one case where the attribute is absent: a service-worker-cached fragment
 * that predates the server-side bake (#666). This mirrors the router's
 * `.page-song[data-song-id] || params.id` precedence for export-ui.
 *
 * @param {HTMLElement} root    `.page-request-a-song`
 * @param {object}      params  Router route params
 * @param {string}      key     Query-string / param key
 * @param {string}      dataKey `dataset` key on `root`
 * @param {number}      max     Length cap, mirroring the input's maxlength
 * @returns {string}
 */
function resolvePrefill(root, params, key, dataKey, max) {
    const fromDom = (root.dataset[dataKey] || '').trim();
    if (fromDom) return fromDom.slice(0, max);

    const fromParams = String(params?.[key] ?? '').trim();
    if (fromParams) return fromParams.slice(0, max);

    try {
        const qp = new URLSearchParams(window.location.search || '');
        return (qp.get(key) || '').trim().slice(0, max);
    } catch {
        /* Ancient/hostile URL — prefill is a nicety, never fail the page. */
        return '';
    }
}

/**
 * Fill still-empty fields from the deep link (#660, #666, #683).
 *
 * Never overwrites a value that is already present: by the time this runs
 * the PHP partial has normally baked the values straight into the inputs'
 * `value=""` attributes, and on a re-visit the user may have typed their
 * own. This is defence-in-depth for the stale-cache case only.
 *
 * Note the deliberate cross-wiring: `?number=` prefills the TITLE field.
 * The deep link comes from the editor's Find-Missing-Numbers panel, where
 * the only thing known about the absent song is its number — putting it in
 * the title box is what the server-side bake already does, and the two must
 * agree.
 */
function applyPrefill(root, form, params) {
    const songbookEl = form.elements['songbook'];
    const titleEl    = form.elements['title'];

    const songbook = resolvePrefill(root, params, 'songbook', 'prefillSongbook', MAX_SONGBOOK);
    if (songbook && songbookEl && !songbookEl.value) songbookEl.value = songbook;

    const number = resolvePrefill(root, params, 'number', 'prefillNumber', MAX_NUMBER);
    if (number && titleEl && !titleEl.value) titleEl.value = number;
}

/** Read the form into the JSON payload shape the endpoint expects. */
function readPayload(form) {
    const val = (name) => (form.elements[name]?.value ?? '');
    return {
        title:    val('title').trim(),
        songbook: val('songbook').trim(),
        details:  val('details').trim(),
        email:    val('email').trim(),
        website:  val('website'),   /* honeypot — sent untrimmed, on purpose:
                                       the server only checks it is empty. */
    };
}

/**
 * Entry point — called by `router.js`'s `afterPageLoad()` once the
 * `/api?page=request` fragment has been injected into the DOM.
 *
 * Idempotent in both directions:
 *   - the window-level drain triggers are wired at most once per document
 *     (see `_autoDrainBound`);
 *   - the submit handler is marked on the form node itself, so a
 *     double-init against the SAME node can't double-bind, while a genuine
 *     re-navigation (which injects a FRESH node) rebinds correctly.
 * No-ops when the section isn't present, e.g. the router fired for a
 * different page or the fetch returned an error card instead.
 *
 * @param {object} [params] Router route params (`{songbook?, number?}`).
 */
export function initRequestASong(params = {}) {
    const root = document.querySelector('.page-request-a-song');
    if (!root) return;

    const form = root.querySelector('#request-form');
    if (!form) return;

    /* Wired even if the form was already bound: a drain must stay possible
       across every visit to the page. */
    bindAutoDrainOnce();

    if (form.dataset.requestBound === '1') return;
    form.dataset.requestBound = '1';

    const ui = collectUi(root);
    applyPrefill(root, form, params);

    form.addEventListener('submit', async (e) => {
        /* Suppress the native form POST — the #711 `action="…"` attribute is
           the no-JS fallback and must not double-submit alongside fetch(). */
        e.preventDefault();
        hideAll(ui);
        if (ui.btn) ui.btn.disabled = true;

        const payload = readPayload(form);

        try {
            /* Offline → queue directly rather than burning a doomed request.
               `navigator.onLine` is only advisory (it reports link state, not
               reachability, and some browsers lie — #524), so the catch below
               treats a fetch TypeError as a second offline signal.
               https://developer.mozilla.org/docs/Web/API/Navigator/onLine */
            if (!navigator.onLine) {
                await enqueueAndReport(
                    ui, form, payload,
                    'Could not save your request offline. Please try again when connected.'
                );
                return;
            }

            try {
                const data = await send(payload);
                const idEl = ui.ok?.querySelector('#request-tracking-id');
                if (idEl) idEl.textContent = data.trackingId ? `Reference: #${data.trackingId}` : '';
                ui.ok?.classList.remove('d-none');
                form.reset();

                /* #1671 F2 — refresh "Your requests" so the submission the user
                   just made appears immediately. A user who sends a request and
                   does not see it in the list directly below reasonably
                   concludes the list is broken.
                   A direct call through a dynamic import rather than a custom
                   DOM event: an event name is a second thing two files have to
                   agree about with nothing enforcing it (rule #35 / #1581), and
                   there is exactly one caller. Dynamic so the module is not
                   pulled in for a signed-out visitor who never sees the list,
                   and `.catch` so a chunk-load blip can never turn a SUCCESSFUL
                   submission into a visible error. */
                import('./my-song-requests.js')
                    .then(m => m.refreshMySongRequests())
                    .catch(() => { /* the router will render it on next visit */ });
            } catch (err) {
                /* fetch() rejects with a TypeError when the request never left
                   the device (DNS failure, or going offline between the
                   navigator.onLine check above and the socket opening). Any
                   other Error is an application-level rejection the server
                   already answered — don't queue those, they'd fail again.
                   https://developer.mozilla.org/docs/Web/API/Window/fetch#exceptions */
                if (err instanceof TypeError) {
                    await enqueueAndReport(
                        ui, form, payload,
                        'Could not reach the server and could not save offline.'
                    );
                } else {
                    showError(ui, err.message || 'Could not submit — please try again later.');
                }
            }
        } finally {
            /* Always re-enable, including on the early offline return above —
               otherwise a queued submit leaves the button dead for the rest
               of the visit. */
            if (ui.btn) ui.btn.disabled = false;
        }
    });
}
