/**
 * iHymns - "Your data": download everything we hold, and delete the account (PR 2c, epic #2180)
 * =========================================================================================
 *
 * ELI5
 * ----
 * A card on the Settings page with a button that downloads one file holding everything the site keeps
 * about you (your account details, favourites, set lists, settings and so on). Passwords, sign-in codes
 * and keys are never in it. The same card will also hold "Delete my account" (added in the next step).
 *
 * Detailed
 * --------
 *  - A real ES module imported by router.js's afterPageLoad() (rule #30): the settings page is a fragment
 *    that can never carry an inline script. The card is found in the DOM by `[data-account-data-card]`
 *    and this is a cheap no-op on any other page.
 *  - Requests use apiFetch() with `auth: true` (rule #31). It adds `X-Requested-With`, which is what the
 *    server's same-origin check (validateCsrfRequest, rule #29) looks for, so no token is baked into the page.
 *  - What went wrong is told apart by the HTTP STATUS, never by the wording of the server's message
 *    (rule #35): 401 not signed in, 403 not accepted from this page, 429 too many downloads, 503 the
 *    server could not build it right now.
 *  - Not behind the account-sync switch (owner decision): the file only holds the person's own data.
 *  - The pure helpers are exported so tests/test-account-export-ui.js can call them.
 *
 * @module modules/account-data
 * @see includes/account_export.php  what goes into the file
 */

import { apiFetch } from '../utils/api-client.js';
import { announce } from '../utils/announce.js';
import { EVT_AUTH_CHANGED } from '../constants.js';

const EXPORT_ENDPOINT = '/api?action=account_export';

/**
 * PURE: the plain-English line for a failed download, chosen by status.
 *
 * @param {number} status
 * @returns {string}
 */
export function exportMessageForStatus(status) {
    switch (status) {
        case 401:
            return 'You are no longer signed in. Sign in again, then try the download again.';
        case 403:
            return 'That could not be done from this page. Reload the page and try again.';
        case 429:
            return 'You have asked for your data a few times already. Please wait an hour and try again.';
        case 503:
            return 'Your data file could not be built just now. Please try again in a few minutes.';
        default:
            return 'Your data could not be downloaded. Please try again.';
    }
}

/**
 * PURE: the file name to save under. Uses the one the server suggests when it is a plain safe name;
 * otherwise builds one from today's date.
 *
 * @param {string|null} contentDisposition the response's Content-Disposition header
 * @param {Date} [now]
 * @returns {string}
 */
export function exportFilenameFrom(contentDisposition, now = new Date()) {
    const m = /filename="([A-Za-z0-9._-]{1,80})"/.exec(contentDisposition || '');
    if (m && m[1].toLowerCase().endsWith('.json')) return m[1];
    const y = now.getUTCFullYear();
    const mo = String(now.getUTCMonth() + 1).padStart(2, '0');
    const d = String(now.getUTCDate()).padStart(2, '0');
    return `ihymns-account-export-${y}${mo}${d}.json`;
}

/**
 * Ask for the file and hand it to the browser as a download.
 *
 * @param {{doc?: Document, win?: Window, request?: Function}} [deps] test hooks
 * @returns {Promise<{ok: boolean, status: number, filename?: string, message?: string}>}
 */
export async function downloadAccountExport(deps = {}) {
    const doc = deps.doc || document;
    const win = deps.win || window;
    const request = deps.request || apiFetch;
    let res;
    try {
        res = await request(EXPORT_ENDPOINT, { method: 'POST', auth: true });
    } catch (_e) {
        return { ok: false, status: 0, message: 'You seem to be offline. Please try again when you are back online.' };
    }
    if (!res.ok) return { ok: false, status: res.status, message: exportMessageForStatus(res.status) };
    const blob = await res.blob();
    const filename = exportFilenameFrom(res.headers?.get?.('Content-Disposition') ?? null);
    const href = win.URL.createObjectURL(blob);
    try {
        const a = doc.createElement('a');
        a.href = href;
        a.download = filename;
        a.rel = 'noopener';
        a.hidden = true;
        doc.body.appendChild(a);
        a.click();
        a.remove();
    } finally {
        /* Give the browser a moment to start the save before the address is released. */
        win.setTimeout(() => { try { win.URL.revokeObjectURL(href); } catch (_e) { /* nothing to release */ } }, 10000);
    }
    return { ok: true, status: res.status, filename };
}

/* ---------- the card ---------- */

let boundCard = null;
let authListener = null;

function showMessage(card, text, kind) {
    const el = card.querySelector('#account-data-msg');
    if (!el) return;
    el.textContent = text;
    el.className = 'alert py-2 small alert-' + kind;
    announce(text);
}

function syncVisibility(card) {
    if (!card.isConnected) { boundCard = null; return; }
    const loggedIn = !!window.iHymnsApp?.userAuth?.isLoggedIn?.();
    card.classList.toggle('d-none', !loggedIn);
}

/**
 * Entry point: call after the settings fragment is in the DOM. Safe to call again on every visit.
 */
export function bootAccountDataCard() {
    const card = document.querySelector('[data-account-data-card]');
    if (!card) { boundCard = null; return; }
    if (boundCard !== card) {
        boundCard = card;
        const btn = card.querySelector('#account-export-btn');
        btn?.addEventListener('click', async () => {
            btn.disabled = true;
            btn.setAttribute('aria-busy', 'true');
            showMessage(card, 'Preparing your data. This can take a moment.', 'info');
            try {
                const r = await downloadAccountExport();
                if (r.ok) showMessage(card, 'Your data file has been downloaded.', 'success');
                else showMessage(card, r.message || exportMessageForStatus(r.status), 'danger');
            } finally {
                btn.disabled = false;
                btn.removeAttribute('aria-busy');
            }
        });
        /* The document outlives every page swap, so the previous listener is removed first (same care as devices.js). */
        if (authListener) document.removeEventListener(EVT_AUTH_CHANGED, authListener);
        authListener = () => syncVisibility(card);
        document.addEventListener(EVT_AUTH_CHANGED, authListener);
    }
    syncVisibility(card);
}
