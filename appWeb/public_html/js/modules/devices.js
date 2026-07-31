/**
 * iHymns — Signed-in devices (#1671 F1; server #1409 / #1511)
 *
 * ELI5: shows every phone, tablet and computer that is signed in to your
 * account, and lets you sign any one of them out.
 *
 * ============================================================================
 * WHY THIS FILE EXISTS
 * ============================================================================
 *
 * `?action=devices_list` and `?action=device_signout` shipped complete and
 * hardened — a CSRF gate, a per-USER (never per-IP) rate limit, and a DELETE
 * whose `UserId = ?` sits INSIDE the WHERE so it can only ever reach the
 * caller's own tokens — and then had zero callers on web, Apple and Android
 * alike. A user could sign in on a borrowed laptop and had no way to revoke it.
 * This module is the first caller those endpoints have ever had.
 *
 * ============================================================================
 * THE FOUR STANDING CONSTRAINTS, AND WHY EACH IS HERE
 * ============================================================================
 *
 *  - **rule #30** — the settings fragment carries NO executable inline
 *    `<script>`. `index.php` sends an enforcing nonce CSP (#117), the fragment
 *    is a separate HTTP response that never sees the per-request nonce, and the
 *    browser refuses a nonce-less inline script with a console-only violation:
 *    no exception, no toast, a feature that looks alive and dies on click. That
 *    killed the whole public Export feature for ~7 weeks (#1565). So this is a
 *    real ES module, imported by `router.js`'s `afterPageLoad()`, reading its
 *    one input (`[data-devices-card]`) out of the DOM.
 *  - **rule #31** — every request goes through `apiFetch`, never bare `fetch()`
 *    and never a `window.fetch` override. `apiFetch` attaches
 *    `X-Requested-With` and (with `auth: true`) the bearer token BY STRUCTURE,
 *    so it cannot be half-installed the way the deleted language-filter patch
 *    was (#1031 / #1593).
 *  - **rule #29** — `device_signout` calls `validateCsrfRequest()`, which is
 *    satisfied by that same `X-Requested-With` header. Nothing here bakes a
 *    session CSRF token into the page: a token baked into a long-lived settings
 *    page goes stale and produces the sporadic "CSRF error" this codebase keeps
 *    paying for.
 *  - **rule #35** — failure KINDS are told apart by HTTP STATUS, never by
 *    regex-matching the server's sentence. See `signOutMessageForStatus()`; the
 *    server's own prose is still shown when it sends one, but the branch is on
 *    the number.
 *
 * ACCESSIBILITY. Signing a device out is destructive and irreversible, so it is
 * behind a confirm, the outcome is spoken through the shared announcer
 * (`js/utils/announce.js`, WCAG 4.1.3 Status Messages), each row's button
 * carries an accessible name that says WHICH device it signs out, and focus is
 * moved somewhere real after the row it was sitting on is removed — otherwise
 * focus falls to `<body>` and a keyboard user loses their place entirely.
 * https://www.w3.org/WAI/WCAG21/Understanding/status-messages.html
 *
 * @see appWeb/public_html/api.php — case 'devices_list' / case 'device_signout'
 * @see appWeb/public_html/includes/api_tokens.php — what a device "id" is
 */

import { escapeHtml } from '../utils/html.js';
import { announce } from '../utils/announce.js';
import { apiFetch } from '../utils/api-client.js';
import { serverTimestampToMs } from '../utils/datetime.js';
import { EVT_AUTH_CHANGED } from '../constants.js';

const LIST_ENDPOINT    = '/api?action=devices_list';
const SIGNOUT_ENDPOINT = '/api?action=device_signout';

/* =========================================================================
 * PURE HELPERS
 *
 * Everything that makes a DECISION lives here, takes its inputs as arguments
 * and returns a value. `tests/test-devices-ui.js` CALLS these and asserts what
 * comes back — it does not read this file's source looking for the right words.
 * That distinction is the whole lesson of `test-transaction-fatal.php`: a
 * predicate guarded by source inspection kept its vocabulary, lost its
 * behaviour, and the suite stayed green.
 * ========================================================================= */

/**
 * The human name for a device row.
 *
 * `DeviceName` / `Platform` / `AppVersion` are all nullable — they shipped
 * live-dormant in #1511 and only a client that volunteers them ever fills them
 * in, so the web PWA's own token typically has all three NULL. A row with no
 * name still has to be identifiable, hence the fallback chain ending in a
 * generic label rather than an empty string.
 *
 * @param {{deviceName?:string|null, platform?:string|null}} device
 * @returns {string} Never empty.
 */
export function deviceLabel(device) {
    const name = typeof device?.deviceName === 'string' ? device.deviceName.trim() : '';
    if (name !== '') return name;
    const platform = typeof device?.platform === 'string' ? device.platform.trim() : '';
    if (platform !== '') return platform + ' device';
    return 'Unnamed device';
}

/**
 * The muted second line: platform, app version and when it was last seen.
 *
 * Returns '' when there is genuinely nothing to say, so the caller can omit the
 * element entirely rather than render an empty one.
 *
 * @param {object} device            A row from `devices_list`.
 * @param {number} [nowMs]           Injected clock — a test must be able to pin
 *                                   "now", and a helper that reads Date.now()
 *                                   internally cannot be asserted about.
 * @returns {string} Plain text (the caller escapes it).
 */
export function deviceSecondaryLine(device, nowMs = Date.now()) {
    const parts = [];
    const platform = typeof device?.platform === 'string' ? device.platform.trim() : '';
    const version  = typeof device?.appVersion === 'string' ? device.appVersion.trim() : '';
    if (platform !== '') parts.push(platform);
    if (version !== '')  parts.push('v' + version);

    /* `lastSeenAt` is NULL until a client has been seen since the #1511
       migration ran, so fall back to when the token was created. */
    const seen = relativeTime(device?.lastSeenAt || device?.createdAt || null, nowMs);
    if (seen !== '') parts.push(seen);

    return parts.join(' · ');
}

/**
 * "3 days ago" for a server timestamp, or '' if it cannot be read.
 *
 * Deliberately tolerant: the server sends MySQL DATETIME text for
 * `LastSeenAt`/`CreatedAt` and ISO-8601 for `ExpiresAt`. Both shapes are
 * handled by the shared `serverTimestampToMs()` — see `js/utils/datetime.js`
 * for why that normalisation is a separate, individually-testable function
 * rather than three inline lines here. An unreadable value yields '' and the
 * line simply omits the age; "NaN days ago" on screen is worse than nothing.
 *
 * @param {string|null|undefined} value
 * @param {number} nowMs
 * @returns {string}
 */
export function relativeTime(value, nowMs) {
    const ms = serverTimestampToMs(value);
    if (ms === null) return '';

    const secs = Math.round((nowMs - ms) / 1000);
    /* A future timestamp (clock skew between the device and the server) lands
       here as a NEGATIVE, and `secs < 90` catches it — so there is deliberately
       no separate `secs < 0` arm. An earlier draft had one; it was unreachable,
       and a mutation test proved it by deleting it without changing a single
       answer. Dead code in a guard-relevant path is worse than none: it reads
       as protection that is not there. */
    if (secs < 90)     return 'just now';
    const mins = Math.round(secs / 60);
    if (mins < 60)     return mins + (mins === 1 ? ' minute ago' : ' minutes ago');
    const hours = Math.round(mins / 60);
    if (hours < 24)    return hours + (hours === 1 ? ' hour ago' : ' hours ago');
    const days = Math.round(hours / 24);
    if (days < 30)     return days + (days === 1 ? ' day ago' : ' days ago');
    const months = Math.round(days / 30);
    if (months < 12)   return months + (months === 1 ? ' month ago' : ' months ago');
    const years = Math.round(months / 12);
    return years + (years === 1 ? ' year ago' : ' years ago');
}

/**
 * What to tell the user about a failed sign-out, chosen BY HTTP STATUS.
 *
 * Rule #35 / #1677: a client that distinguishes failure kinds by matching the
 * server's error sentence degrades to a generic toast the moment somebody
 * rewords a string, with nothing red anywhere to say so. The status code is the
 * contract; the prose is not.
 *
 * `serverMessage` is preferred where the server sent one AND the status has no
 * more specific explanation of its own — the server knows things this client
 * does not (which device, which limit).
 *
 * @param {number} status
 * @param {string} [serverMessage]
 * @returns {string}
 */
export function signOutMessageForStatus(status, serverMessage = '') {
    const msg = typeof serverMessage === 'string' ? serverMessage.trim() : '';
    switch (status) {
        case 401:
            return 'You are no longer signed in. Sign in again, then retry.';
        case 403:
            /* validateCsrfRequest() refused — this is the same-origin gate, not
               a permissions problem, and telling the user "access denied" would
               send them looking for the wrong thing. */
            return 'That request was rejected as cross-origin. Reload the page and try again.';
        case 404:
            return 'That device is already signed out.';
        case 429:
            return 'Too many sign-out attempts. Please wait a while and try again.';
        default:
            if (msg !== '') return msg;
            return 'Could not sign that device out (HTTP ' + status + '). Please try again.';
    }
}

/**
 * Remove one device from a list by id, returning a NEW array.
 *
 * Extracted so the "did the row actually go?" behaviour is assertable without a
 * DOM. Unknown ids are a no-op rather than an error: the server has already
 * told us whether the revoke happened (404 vs 200), so this is only ever
 * reconciling local state with a decision already made.
 *
 * @param {Array<{id?:string}>} devices
 * @param {string} id
 * @returns {Array}
 */
export function withoutDevice(devices, id) {
    if (!Array.isArray(devices)) return [];
    return devices.filter((d) => d && d.id !== id);
}

/**
 * Is this list worth showing a "sign out the others" story about?
 *
 * A single device — the one you are reading this on — is the normal state, and
 * the card should say so plainly rather than presenting a one-row table.
 *
 * @param {Array} devices
 * @returns {boolean}
 */
export function hasOtherDevices(devices) {
    if (!Array.isArray(devices)) return false;
    return devices.filter((d) => d && d.isCurrent !== true).length > 0;
}

/* =========================================================================
 * DOM WIRING
 * ========================================================================= */

/** Cached so a re-render after a sign-out does not need a second round trip. */
let devices = [];
/** Guards against double-binding when the router re-mounts the settings page. */
let boundCard = null;
/** The live EVT_AUTH_CHANGED subscription, so a re-mount can replace it rather
 *  than stack a second one on `document`. See bootDevicesCard(). */
let authListener = null;
/**
 * In-flight guard.
 *
 * `afterPageLoad()` calls `bootDevicesCard()` and then, a few statements later,
 * dispatches EVT_AUTH_CHANGED — which this module listens for. Without this
 * flag every arrival on /settings would fire TWO identical `devices_list`
 * requests. Harmless but wasteful, and it makes the network tab lie about what
 * the feature costs.
 */
let loading = false;

/**
 * Entry point — call after the settings fragment is in the DOM.
 *
 * Idempotent, because `afterPageLoad()` runs on EVERY navigation and a user can
 * bounce off and back onto /settings without a document load. If the card is
 * absent (any other page) this is a cheap no-op.
 */
export function bootDevicesCard() {
    const card = document.querySelector('[data-devices-card]');
    if (!card) { boundCard = null; return; }

    if (boundCard !== card) {
        boundCard = card;
        card.querySelector('#devices-refresh-btn')?.addEventListener('click', () => {
            loadDevices({ announceResult: true });
        });
        /* One delegated handler on the container rather than one per row, so a
           re-render cannot leak listeners (the bug pattern setlist.js works
           around by cloning nodes). Bound to the card, which the router replaces
           wholesale, so it goes away with the fragment. */
        card.querySelector('#devices-list')?.addEventListener('click', (e) => {
            const btn = e.target instanceof Element ? e.target.closest('[data-device-signout]') : null;
            if (btn) { onSignOutClick(btn); }
        });

        /* The card only makes sense signed in, and auth can flip while the page
           is open (sign in from the Account card directly above this one).
         *
         * REMOVED BEFORE RE-ADDING, and this is the part that is easy to get
         * wrong. The two listeners above hang off `card`, which the router
         * discards with the fragment — but THIS one hangs off `document`, which
         * outlives every navigation. Without the remove, settings → home →
         * settings would leave a second listener holding a detached card, and a
         * long session would accumulate one per visit, each doing a redundant
         * `devices_list` fetch on every auth change. Same species as the #1533
         * stranded playback bar: an element-scoped cleanup does not cover a
         * document-scoped subscription. */
        if (authListener) { document.removeEventListener(EVT_AUTH_CHANGED, authListener); }
        authListener = () => syncVisibility(card);
        document.addEventListener(EVT_AUTH_CHANGED, authListener);
    }

    syncVisibility(card);
}

/**
 * Show or hide the whole card, and load its contents when it becomes visible.
 *
 * @param {Element} card
 */
function syncVisibility(card) {
    if (!card.isConnected) { boundCard = null; return; }
    const loggedIn = !!window.iHymnsApp?.userAuth?.isLoggedIn?.();
    card.classList.toggle('d-none', !loggedIn);
    if (loggedIn) {
        loadDevices({ announceResult: false });
    } else {
        devices = [];
    }
}

/**
 * Fetch the device list and render it.
 *
 * An un-migrated docroot answers `{devices: []}` rather than an error (the
 * server degrades to a graceful empty list for a read-only lookup), so "no
 * devices" and "this install has not run the #1511 migration" look the same
 * here on purpose — neither is an error state worth alarming a user with.
 *
 * @param {{announceResult?: boolean}} opts
 */
async function loadDevices({ announceResult = false } = {}) {
    const list = document.getElementById('devices-list');
    if (!list) return;
    if (loading) return;
    loading = true;

    try {
        const res = await apiFetch(LIST_ENDPOINT, { auth: true });
        if (res.status === 401) {
            /* Token expired while the page sat open. Not an error to shout
               about — the Account card above is already telling the truth. */
            devices = [];
            renderDevices();
            return;
        }
        if (!res.ok) {
            showMessage(signOutMessageForStatus(res.status), 'warning');
            return;
        }
        const data = await res.json();
        devices = Array.isArray(data?.devices) ? data.devices : [];
        clearMessage();
        renderDevices();
        if (announceResult) {
            announce(devices.length === 1
                ? '1 signed-in device.'
                : devices.length + ' signed-in devices.');
        }
    } catch (_e) {
        /* Network-level failure; apiFetch has already dispatched the offline
           signal, so all this needs to do is not leave "Loading…" forever. */
        showMessage('Could not load your devices. Check your connection and try Refresh.', 'warning');
    } finally {
        /* `finally`, not the end of `try` — an early `return` on the 401 path
           would otherwise leave the flag stuck on and the Refresh button dead
           for the rest of the session. */
        loading = false;
    }
}

/** Paint the current `devices` array into #devices-list. */
function renderDevices() {
    const list = document.getElementById('devices-list');
    if (!list) return;

    if (devices.length === 0) {
        list.innerHTML = '<p class="text-muted small mb-0">No signed-in devices found.</p>';
        return;
    }

    const now = Date.now();
    const rows = devices.map((d) => renderRow(d, now)).join('');
    const footnote = hasOtherDevices(devices)
        ? ''
        : '<p class="text-muted small mb-0 mt-2">This is the only device signed in to your account.</p>';

    list.innerHTML = '<ul class="list-group list-group-flush mb-0">' + rows + '</ul>' + footnote;
}

/**
 * One device row.
 *
 * EVERY interpolated value is escaped. `deviceName`, `platform` and
 * `appVersion` are client-volunteered strings stored verbatim, so they are
 * attacker-controlled in exactly the same sense a display name is.
 *
 * @param {object} device
 * @param {number} now
 * @returns {string} HTML
 */
function renderRow(device, now) {
    const label     = deviceLabel(device);
    const secondary = deviceSecondaryLine(device, now);
    const isCurrent = device?.isCurrent === true;
    const id        = typeof device?.id === 'string' ? device.id : '';

    const currentBadge = isCurrent
        ? ' <span class="badge text-bg-success align-middle">This device</span>'
        : '';

    /* The current device gets no sign-out button. It would work — the server
       happily revokes the caller's own token — but it is indistinguishable from
       "Sign Out" three inches up the page, and a user who does not realise that
       is left wondering why Settings logged them out. */
    const action = (isCurrent || id === '')
        ? ''
        : '<button type="button" class="btn btn-outline-danger btn-sm flex-shrink-0"'
          + ' data-device-signout="' + escapeHtml(id) + '"'
          + ' aria-label="Sign out ' + escapeHtml(label) + '">'
          + '<i class="fa-solid fa-right-from-bracket me-1" aria-hidden="true"></i>Sign out</button>';

    return '<li class="list-group-item d-flex align-items-center gap-3 px-0"'
         + ' data-device-row="' + escapeHtml(id) + '">'
         + '<div class="flex-grow-1">'
         + '<strong>' + escapeHtml(label) + '</strong>' + currentBadge
         + (secondary !== ''
             ? '<small class="text-muted d-block">' + escapeHtml(secondary) + '</small>'
             : '')
         + '</div>'
         + action
         + '</li>';
}

/**
 * Handle a click on one row's "Sign out" button.
 *
 * @param {Element} btn
 */
async function onSignOutClick(btn) {
    const id = btn.getAttribute('data-device-signout') || '';
    if (id === '') return;

    const device = devices.find((d) => d && d.id === id);
    const label  = device ? deviceLabel(device) : 'this device';

    /* Destructive and irreversible from the user's point of view — that device
       has to sign in again — so it asks first. */
    if (!window.confirm('Sign out "' + label + '"? It will need to sign in again.')) {
        return;
    }

    btn.disabled = true;
    try {
        const res = await apiFetch(SIGNOUT_ENDPOINT, {
            method: 'POST',
            auth: true,
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id }),
        });

        if (!res.ok) {
            /* Read the server's own sentence where there is one, but branch on
               the STATUS (rule #35). A non-JSON body is fine — the status alone
               is enough to choose a message. */
            let serverMessage = '';
            try {
                const data = await res.json();
                if (data && typeof data.error === 'string') serverMessage = data.error;
            } catch (_e) { /* no JSON body */ }

            const text = signOutMessageForStatus(res.status, serverMessage);
            showMessage(text, 'danger');
            announce(text);
            /* A 404 means the token is already gone — reconcile rather than
               leave a row the server does not have. */
            if (res.status === 404) {
                devices = withoutDevice(devices, id);
                renderDevices();
                focusAfterRemoval();
            } else {
                btn.disabled = false;
            }
            return;
        }

        devices = withoutDevice(devices, id);
        renderDevices();
        clearMessage();
        announce('Signed out ' + label + '.');
        window.iHymnsApp?.showToast?.('Signed out ' + label, 'success', 2500);
        focusAfterRemoval();
    } catch (_e) {
        const text = 'Could not reach the server. Check your connection and try again.';
        showMessage(text, 'danger');
        announce(text);
        btn.disabled = false;
    }
}

/**
 * Put focus somewhere real after the row it was on has been removed.
 *
 * Without this, removing the focused button drops focus to `<body>`: a screen
 * reader stops mid-context and a keyboard user has to tab from the top of the
 * document again. The Refresh button is the nearest still-existing control that
 * belongs to this card.
 */
function focusAfterRemoval() {
    const fallback = document.getElementById('devices-refresh-btn');
    if (fallback instanceof HTMLElement) fallback.focus();
}

/**
 * @param {string} text
 * @param {'warning'|'danger'|'success'} kind
 */
function showMessage(text, kind) {
    const el = document.getElementById('devices-msg');
    if (!el) return;
    el.className = 'alert alert-' + kind + ' py-2 small';
    el.textContent = text;
}

function clearMessage() {
    const el = document.getElementById('devices-msg');
    if (!el) return;
    el.className = 'alert d-none py-2 small';
    el.textContent = '';
}
