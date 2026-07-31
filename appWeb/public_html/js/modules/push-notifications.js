/**
 * iHymns — Push notifications opt-in (#311 server; UI #1671 F6)
 *
 * ELI5: turns notifications on or off for THIS device, and lets you choose
 * which kinds of message you want.
 *
 * ============================================================================
 * WHY THIS FILE EXISTS
 * ============================================================================
 *
 * `tblPushSubscriptions`, `?action=push_subscribe` and `?action=push_unsubscribe`
 * have existed since #311 and NOTHING HAS EVER SENT A PUSH. There was no VAPID
 * keypair, no RFC 8291 payload encryption, and no service-worker `push` handler
 * — three missing links in a four-link chain, so the two endpoints sat as a
 * permanently untested surface that read as working. This module is the first
 * caller they have ever had; `includes/web_push.php` and the service worker's
 * `push` / `notificationclick` listeners are the other two links.
 *
 * ============================================================================
 * THE STANDING CONSTRAINTS THIS FILE OBEYS
 * ============================================================================
 *
 *  - **rule #30** — the settings fragment carries no executable inline
 *    `<script>` and never can (enforcing nonce CSP #117 + a fragment that never
 *    sees the nonce, refused SILENTLY). This is a real ES module imported from
 *    `router.js`'s `afterPageLoad()`, reading its inputs from `data-*` the
 *    fragment already emits.
 *  - **rule #31** — requests go through `apiFetch`, never bare `fetch()`.
 *  - **rule #35** — the kind list is not duplicated here. It arrives from the
 *    server's `webPushKinds()` registry via `data-push-kinds`, so adding a kind
 *    stays ONE line of PHP and this file needs no edit at all. Failure kinds are
 *    told apart by HTTP status, never by matching the server's prose.
 *
 * ============================================================================
 * WHAT MAKES PUSH DIFFERENT FROM EVERY OTHER TOGGLE IN SETTINGS
 * ============================================================================
 *
 * There are FOUR independent pieces of state and they can disagree:
 *
 *   1. does this browser support the Push API at all;
 *   2. `Notification.permission` — granted / denied / default;
 *   3. whether a `PushSubscription` exists in this browser's service worker;
 *   4. whether the SERVER still has a row for that subscription.
 *
 * A user who granted permission and then cleared site data has (2) granted and
 * (3) absent. A user whose subscription the push service revoked has (3) absent
 * and (4) present. So the button's label is derived from the LIVE subscription
 * every time the card is shown, never from a stored flag — a stored flag is how
 * a settings screen ends up confidently saying "on" about something that is off.
 *
 * `permission === 'denied'` is a DEAD END that no button can fix: browsers do
 * not re-prompt, and the only way back is the site-settings panel. Saying that
 * plainly is the difference between a user changing it in ten seconds and
 * concluding the feature is broken.
 *
 * @see appWeb/public_html/includes/web_push.php     — the sender + the kind registry
 * @see appWeb/public_html/service-worker.js.php     — the push / notificationclick handlers
 * @link https://developer.mozilla.org/docs/Web/API/PushManager/subscribe
 * @link https://developer.mozilla.org/docs/Web/API/Notification/permission_static
 * @link https://www.w3.org/WAI/WCAG21/Understanding/status-messages.html
 */

import { escapeHtml } from '../utils/html.js';
import { announce } from '../utils/announce.js';
import { apiFetch } from '../utils/api-client.js';
import { EVT_AUTH_CHANGED } from '../constants.js';

/* ==========================================================================
 * PURE HELPERS — imported and CALLED by tests/test-push-helpers.js
 * ========================================================================== */

/**
 * Convert a base64url VAPID public key into the `Uint8Array` `subscribe()` wants.
 *
 * ELI5: the key arrives as text and the browser wants raw bytes.
 *
 * `PushManager.subscribe()` accepts a string in some browsers and a BufferSource
 * everywhere, so converting is the portable choice rather than a preference.
 * The padding step matters: `atob` rejects an unpadded string in some engines,
 * and base64url is emitted WITHOUT padding by every VAPID tool including this
 * project's own `_appleSiwaB64UrlEncode()`.
 *
 * @param {string} base64url
 * @returns {Uint8Array}
 * @throws {Error} when the input is not decodable — a silent empty array here
 *   would produce a subscription bound to the wrong key, which fails much later
 *   and much more confusingly (every push rejected by the push service).
 */
export function vapidKeyToBytes(base64url) {
    const s = String(base64url || '').trim();
    if (s === '') { throw new Error('empty VAPID key'); }
    const padded = (s + '='.repeat((4 - (s.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/');
    const raw = atob(padded);
    const bytes = new Uint8Array(raw.length);
    for (let i = 0; i < raw.length; i++) { bytes[i] = raw.charCodeAt(i); }
    /* An uncompressed P-256 point is exactly 65 bytes starting 0x04. Checking
       it here turns "every push is silently rejected by the push service" into
       "the settings card says the key is wrong". */
    if (bytes.length !== 65 || bytes[0] !== 4) {
        throw new Error('VAPID key is not a 65-byte uncompressed P-256 point');
    }
    return bytes;
}

/**
 * Turn a `PushSubscription` into the body `push_subscribe` expects.
 *
 * ELI5: unpack the browser's subscription into the three fields the server
 * stores.
 *
 * The field NAMES are the server's, not the browser's (`p256dh_key` /
 * `auth_key`, not `p256dh` / `auth`). That mismatch is exactly the kind of
 * cross-file agreement this codebase keeps breaking, so the mapping lives in
 * ONE exported function a test can call rather than being spelled out inline at
 * the call site.
 *
 * @param {PushSubscription} sub
 * @returns {{endpoint: string, p256dh_key: string, auth_key: string}|null}
 *   null when the subscription is missing either key, which is a browser
 *   returning something unusable — better to report it than to POST a row the
 *   sender can never encrypt for.
 */
export function subscriptionToBody(sub) {
    const json = typeof sub?.toJSON === 'function' ? sub.toJSON() : sub;
    const endpoint = String(json?.endpoint ?? '');
    const p256dh = String(json?.keys?.p256dh ?? '');
    const auth = String(json?.keys?.auth ?? '');
    if (endpoint === '' || p256dh === '' || auth === '') { return null; }
    return { endpoint, p256dh_key: p256dh, auth_key: auth };
}

/**
 * What should the card SAY, given the four pieces of state?
 *
 * ELI5: work out the one sentence and the one button label that match the
 * situation the user is actually in.
 *
 * PURE, and this is the function most worth testing: every combination below
 * exists in the wild and getting one wrong shows the user a button that cannot
 * do anything.
 *
 * @param {{supported: boolean, permission: string, subscribed: boolean, configured: boolean}} state
 * @returns {{message: string, button: (string|null), tone: string}}
 *   `button` is null when there is nothing useful to offer.
 */
export function pushCardState(state) {
    if (!state.supported) {
        return {
            message: 'This browser does not support notifications. iOS needs the app '
                + 'added to your Home Screen first.',
            button: null,
            tone: 'muted',
        };
    }
    if (!state.configured) {
        return {
            message: 'Notifications are not switched on for this site yet.',
            button: null,
            tone: 'muted',
        };
    }
    if (state.permission === 'denied') {
        /* A dead end no button can fix — browsers do not re-prompt after a
           denial. Offering an "Enable" button here produces a click that does
           nothing at all, with no error, which reads as a broken app. */
        return {
            message: 'You have blocked notifications for this site. To turn them back on, '
                + 'use your browser\'s site settings (the padlock or ⓘ icon in the address bar).',
            button: null,
            tone: 'warning',
        };
    }
    if (state.subscribed) {
        return {
            message: 'Notifications are on for this device.',
            button: 'Turn off on this device',
            tone: 'success',
        };
    }
    return {
        message: 'Notifications are off for this device.',
        button: 'Turn on notifications',
        tone: 'muted',
    };
}

/**
 * Parse the `data-push-kinds` payload into a render-ready list.
 *
 * ELI5: read the server's list of notification types.
 *
 * A malformed or absent attribute yields an EMPTY list rather than throwing:
 * the on/off switch is the important half of this card and must keep working
 * even if the kind list cannot be read.
 *
 * @param {string} json
 * @returns {Array<{key: string, label: string, description: string, default: boolean}>}
 */
export function parsePushKinds(json) {
    let parsed;
    try { parsed = JSON.parse(String(json || '{}')); } catch { return []; }
    if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) { return []; }
    return Object.keys(parsed).map((key) => ({
        key,
        label: String(parsed[key]?.label ?? key),
        description: String(parsed[key]?.description ?? ''),
        default: parsed[key]?.default === true,
    }));
}

/**
 * Is this kind currently on for this user?
 *
 * ELI5: has the user made a choice about this type of message? If not, use the
 * type's default.
 *
 * MUST agree with the server's `webPushKindEnabled()`: only the exact value
 * `true` counts as opted in. Reading a stored `"0"` or `0` as truthy is how an
 * opt-OUT silently becomes an opt-in, and the two sides disagreeing would mean
 * the checkbox says one thing while the sender does another.
 *
 * @param {object} prefs The `ihymns.push` subtree.
 * @param {{key: string, default: boolean}} kind
 * @returns {boolean}
 */
export function kindEnabled(prefs, kind) {
    if (!prefs || typeof prefs !== 'object' || !Object.prototype.hasOwnProperty.call(prefs, kind.key)) {
        return kind.default === true;
    }
    return prefs[kind.key] === true;
}

/**
 * Map an HTTP status onto a sentence. Branch on the NUMBER (rule #35).
 *
 * @param {number} status
 * @param {string} serverMessage
 * @returns {string}
 */
export function pushErrorForStatus(status, serverMessage = '') {
    if (serverMessage) { return serverMessage; }
    if (status === 401) { return 'Sign in to manage notifications.'; }
    if (status === 400) { return 'This browser produced a subscription the server could not accept.'; }
    if (status === 429) { return 'Too many requests — wait a moment and try again.'; }
    if (status >= 500) { return 'The server had a problem. Notifications were not changed.'; }
    return 'Could not change notification settings.';
}

/* ==========================================================================
 * DOM WIRING
 *
 * ⚠️ NOTHING BELOW THIS LINE IS EXERCISED BY ANY TEST IN THIS REPO. There is no
 * browser in the build container, so the permission prompt, the subscription
 * and both request paths are covered by no assertion — the pure half above is.
 * A green suite does NOT mean a push has ever been received.
 * ========================================================================== */

let boundCard = null;
let authListener = null;

/**
 * Entry point — call after the settings fragment is in the DOM.
 *
 * Idempotent: `afterPageLoad()` runs on every navigation. A cheap no-op on any
 * other page, because the hook is absent there.
 */
export function bootPushCard() {
    const card = document.querySelector('[data-push-card]');
    if (!card) { boundCard = null; return; }

    if (boundCard !== card) {
        boundCard = card;
        card.querySelector('#push-toggle-btn')?.addEventListener('click', () => onToggle(card));
        card.querySelector('#push-kinds')?.addEventListener('change', (e) => {
            const box = e.target instanceof HTMLInputElement ? e.target : null;
            if (box?.dataset.pushKind) { savePreferences(card); }
        });

        /* The card is meaningless signed out, and auth can flip while the page
           is open. REMOVED BEFORE RE-ADDING: this listener hangs off `document`,
           which outlives every navigation, unlike the two above which go away
           with the fragment. Without the remove, settings -> home -> settings
           accumulates one listener per visit, each holding a detached card —
           the same species as the #1533 stranded playback bar. */
        if (authListener) { document.removeEventListener(EVT_AUTH_CHANGED, authListener); }
        authListener = () => syncVisibility(card);
        document.addEventListener(EVT_AUTH_CHANGED, authListener);
    }

    syncVisibility(card);
}

/**
 * Show the card only when signed in, and refresh it when it becomes visible.
 *
 * The sign-in probe is `window.iHymnsApp.userAuth.isLoggedIn()` — the SAME one
 * devices.js uses three cards above. There is no `data-*` marker for auth state
 * on this fragment (I looked for one before assuming), and inventing a second
 * way to answer "is this user signed in?" would be a second thing to keep in
 * step with nothing enforcing it.
 *
 * The `isConnected` check mirrors devices.js too: the router can discard the
 * fragment between a navigation and a deferred call, and touching a detached
 * card is a silent no-op that reads as the feature not working.
 */
function syncVisibility(card) {
    if (!card.isConnected) { boundCard = null; return; }
    const signedIn = !!window.iHymnsApp?.userAuth?.isLoggedIn?.();
    card.classList.toggle('d-none', !signedIn);
    if (signedIn) { refresh(card); }
}

/** Read the LIVE state and render it. Never trusts a stored flag. */
async function refresh(card) {
    const statusEl = card.querySelector('#push-status');
    const btn = card.querySelector('#push-toggle-btn');
    const supported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
    const configured = (card.getAttribute('data-vapid-key') || '') !== '';

    let subscribed = false;
    if (supported && configured) {
        try {
            const reg = await navigator.serviceWorker.ready;
            subscribed = !!(await reg.pushManager.getSubscription());
        } catch { subscribed = false; }
    }

    const view = pushCardState({
        supported,
        configured,
        permission: supported ? Notification.permission : 'default',
        subscribed,
    });
    if (statusEl) {
        statusEl.innerHTML = '<p class="small mb-0 text-' + escapeHtml(view.tone === 'success' ? 'success' : 'muted')
            + '">' + escapeHtml(view.message) + '</p>';
    }
    if (btn) {
        btn.classList.toggle('d-none', view.button === null);
        btn.textContent = view.button ?? '';
    }
    renderKinds(card, subscribed);
}

/** Render one checkbox per registry entry — never a hardcoded list. */
async function renderKinds(card, subscribed) {
    const host = card.querySelector('#push-kinds');
    if (!host) { return; }
    const kinds = parsePushKinds(card.getAttribute('data-push-kinds'));
    if (kinds.length === 0 || !subscribed) { host.innerHTML = ''; return; }

    let prefs = {};
    try {
        const res = await apiFetch('/api?action=user_settings&namespace=ihymns.push', { auth: true });
        if (res.ok) {
            const data = await res.json();
            prefs = (data && typeof data.settings === 'object' && data.settings) || {};
        }
    } catch { /* an unreadable preference reads as "never chosen" — the defaults */ }

    host.innerHTML = '<fieldset><legend class="form-label small mb-1">Send me</legend>'
        + kinds.map((kind) => {
            const id = 'push-kind-' + escapeHtml(kind.key);
            return '<div class="form-check">'
                + '<input class="form-check-input" type="checkbox" id="' + id + '"'
                + ' data-push-kind="' + escapeHtml(kind.key) + '"'
                + (kindEnabled(prefs, kind) ? ' checked' : '') + '>'
                + '<label class="form-check-label" for="' + id + '">'
                + escapeHtml(kind.label)
                + (kind.description ? '<span class="d-block text-muted small">' + escapeHtml(kind.description) + '</span>' : '')
                + '</label></div>';
        }).join('')
        + '</fieldset>';
}

/** Persist the checkbox state into the `ihymns.push` settings namespace. */
async function savePreferences(card) {
    const boxes = card.querySelectorAll('[data-push-kind]');
    const prefs = {};
    boxes.forEach((b) => { prefs[b.dataset.pushKind] = b.checked === true; });
    try {
        /* The NAMESPACED write replaces only the ihymns.push subtree, so a
           preference save here cannot clobber the rest of the user's settings
           (#1671 F5). Sending the WHOLE subtree every time is deliberate: the
           server's merge is at the namespace root, so "what you POST is what you
           GET" — a partial post would delete the boxes it omitted. */
        const res = await apiFetch('/api?action=user_settings', {
            method: 'POST',
            auth: true,
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ namespace: 'ihymns.push', settings: prefs }),
        });
        if (!res.ok) { throw Object.assign(new Error('save failed'), { status: res.status }); }
        announce('Notification preferences saved.');
    } catch (err) {
        showMessage(card, pushErrorForStatus(err?.status ?? 0, ''), 'danger');
    }
}

/** Subscribe or unsubscribe this device. */
async function onToggle(card) {
    const btn = card.querySelector('#push-toggle-btn');
    if (btn) { btn.disabled = true; }
    try {
        const reg = await navigator.serviceWorker.ready;
        const existing = await reg.pushManager.getSubscription();

        if (existing) {
            const endpoint = existing.endpoint;
            await existing.unsubscribe();
            await apiFetch('/api?action=push_unsubscribe', {
                method: 'POST',
                auth: true,
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ endpoint }),
            });
            announce('Notifications turned off on this device.');
        } else {
            /* Ask for permission BEFORE subscribing: subscribe() prompts too,
               but doing it explicitly means a refusal is a clean, reported
               outcome rather than a rejected promise from deep inside the
               push manager. */
            const permission = await Notification.requestPermission();
            if (permission !== 'granted') {
                showMessage(card, 'Notifications were not allowed. Nothing has changed.', 'warning');
                return;
            }
            const sub = await reg.pushManager.subscribe({
                userVisibleOnly: true,   /* required by Chrome; we never send silent pushes */
                applicationServerKey: vapidKeyToBytes(card.getAttribute('data-vapid-key') || ''),
            });
            const body = subscriptionToBody(sub);
            if (!body) { throw new Error('This browser returned an unusable subscription.'); }

            const res = await apiFetch('/api?action=push_subscribe', {
                method: 'POST',
                auth: true,
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body),
            });
            if (!res.ok) {
                /* The SERVER refused, so do not leave a browser subscription
                   nothing will ever send to — that is the state where a user
                   has "notifications on" and never receives one. */
                await sub.unsubscribe().catch(() => {});
                throw Object.assign(new Error('subscribe failed'), { status: res.status });
            }
            announce('Notifications turned on for this device.');
        }
    } catch (err) {
        showMessage(card, err?.status ? pushErrorForStatus(err.status, '') : String(err?.message || 'Something went wrong.'), 'danger');
    } finally {
        if (btn) { btn.disabled = false; }
        refresh(card);
    }
}

/** Show an inline, ANNOUNCED message (WCAG 4.1.3 — the region is role="alert"). */
function showMessage(card, text, tone) {
    const el = card.querySelector('#push-msg');
    if (!el) { return; }
    el.className = 'alert alert-' + tone + ' py-2 small';
    el.textContent = text;
}
