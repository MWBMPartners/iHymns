/**
 * iHymns - "Your data": download everything we hold, and delete the account (PR 2c, epic #2180)
 * =========================================================================================
 *
 * ELI5
 * ----
 * A card on the Settings page with a button that downloads one file holding everything the site keeps
 * about you (your account details, favourites, set lists, settings and so on). Passwords, sign-in codes
 * and keys are never in it. The same card holds "Delete my account": a panel that explains what will
 * happen, asks for your password (or an email code) AND for you to type the word DELETE, and only then
 * sends the request. Nothing is deleted on a mis-click.
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
import { EVT_AUTH_CHANGED, ACCOUNT_WRITES_OFF_REASON } from '../constants.js';

const EXPORT_ENDPOINT = '/api?action=account_export';
const DELETE_ENDPOINT = '/api?action=account_delete';
const EMAIL_CODE_ENDPOINT = '/api?action=auth_email_login_request';
/** The word a person types to confirm. The server also accepts it alone for an account with no other way to prove who it is. */
export const DELETE_CONFIRM_WORD = 'DELETE';

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


/* ---------- deleting the account ---------- */

/**
 * PURE: did the person type the confirmation word? Exact, apart from surrounding spaces. The person is asked to
 * type it themselves, so a pasted lower-case word does not count.
 *
 * @param {unknown} typed
 * @returns {boolean}
 */
export function deleteConfirmationOk(typed) {
    return typeof typed === 'string' && typed.trim() === DELETE_CONFIRM_WORD;
}

/**
 * PURE: the request body for account_delete. The password is sent when there is one, else the email code;
 * the typed word always goes along (the server uses it on its own only for an account that has no password,
 * no email and no Apple link, where there is nothing else to prove).
 *
 * @param {{password?: string, emailCode?: string, typed?: string}} input
 * @returns {object}
 */
export function buildDeleteBody({ password = '', emailCode = '', typed = '' } = {}) {
    const body = {};
    if (password !== '') body.password = password;
    else if (emailCode.trim() !== '') body.email_code = emailCode.trim();
    if (deleteConfirmationOk(typed)) body.confirm = DELETE_CONFIRM_WORD;
    return body;
}

/**
 * PURE: the plain-English line for a refused delete, chosen by the HTTP status and the reason code,
 * never by the server's wording (rule #35).
 *
 * @param {number} status
 * @param {string|null} [reason] the `reason` field of the reply, when there is one
 * @returns {string}
 */
export function deleteMessageForResponse(status, reason = null) {
    switch (status) {
        case 400:
            return 'Please enter your password, or ask for an email code, so we know it is really you.';
        case 401:
            return 'That did not match. Check your password or code and try again. If you were signed out, sign in again first.';
        case 403:
            return 'That could not be done from this page. Reload the page and try again.';
        case 409:
            return reason === ACCOUNT_WRITES_OFF_REASON
                ? 'Saving to accounts is paused on this site right now, so your account cannot be deleted from here yet. Please try again later.'
                : 'You are the only administrator. Make someone else an administrator first, then delete your account.';
        case 429:
            return 'Too many attempts. Please wait a little while and try again.';
        case 503:
            return 'Deleting accounts is not available right now. Nothing has been changed. Please try again later.';
        default:
            return 'Your account could not be deleted. Nothing has been changed. Please try again.';
    }
}

/**
 * Ask for an email code (for an account that has no password). The reply is always the same polite
 * "if an account exists ..." so nothing is learned about other addresses.
 *
 * @param {string} email
 * @param {{request?: Function}} [deps]
 * @returns {Promise<{ok: boolean, status: number, message: string}>}
 */
export async function requestEmailCode(email, deps = {}) {
    const request = deps.request || apiFetch;
    if (typeof email !== 'string' || !email.includes('@')) {
        return { ok: false, status: 0, message: 'Enter the email address on your account first.' };
    }
    try {
        const res = await request(EMAIL_CODE_ENDPOINT, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ email: email.trim() }),
        });
        if (res.ok) return { ok: true, status: res.status, message: 'If that address is on an account, a code has been sent. It lasts 10 minutes.' };
        if (res.status === 429) return { ok: false, status: 429, message: 'Too many codes have been asked for. Please wait a while and try again.' };
        if (res.status === 503) return { ok: false, status: 503, message: 'Email codes are not available on this site.' };
        return { ok: false, status: res.status, message: 'A code could not be sent from here. Try again later.' };
    } catch (_e) {
        return { ok: false, status: 0, message: 'You seem to be offline. Please try again when you are back online.' };
    }
}

/**
 * Delete the signed-in account. Refuses to send anything unless the person typed the confirmation word.
 *
 * @param {{password?: string, emailCode?: string, typed?: string}} input
 * @param {{request?: Function}} [deps] test hook
 * @returns {Promise<{ok: boolean, status: number, message?: string}>}
 */
export async function deleteAccount(input, deps = {}) {
    const request = deps.request || apiFetch;
    if (!deleteConfirmationOk(input?.typed)) {
        return { ok: false, status: 0, message: `Type the word ${DELETE_CONFIRM_WORD} to confirm.` };
    }
    let res;
    try {
        res = await request(DELETE_ENDPOINT, {
            method: 'POST', auth: true,
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(buildDeleteBody(input)),
        });
    } catch (_e) {
        return { ok: false, status: 0, message: 'You seem to be offline. Nothing has been changed. Please try again when you are back online.' };
    }
    if (res.ok) return { ok: true, status: res.status };
    let reason = null;
    try { reason = (await res.clone().json())?.reason ?? null; } catch (_e) { /* not JSON */ }
    return { ok: false, status: res.status, message: deleteMessageForResponse(res.status, reason) };
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
        bindDeletePanel(card);
        /* The document outlives every page swap, so the previous listener is removed first (same care as devices.js). */
        if (authListener) document.removeEventListener(EVT_AUTH_CHANGED, authListener);
        authListener = () => syncVisibility(card);
        document.addEventListener(EVT_AUTH_CHANGED, authListener);
    }
    syncVisibility(card);
}

/** Wire the "Delete my account" panel inside the card. */
function bindDeletePanel(card) {
    const open = card.querySelector('#account-delete-open');
    const panel = card.querySelector('#account-delete-panel');
    if (!open || !panel) return;
    const typed = panel.querySelector('#account-delete-typed');
    const go = panel.querySelector('#account-delete-go');
    const password = panel.querySelector('#account-delete-password');
    const email = panel.querySelector('#account-delete-email');
    const code = panel.querySelector('#account-delete-code');
    const sendCode = panel.querySelector('#account-delete-send-code');
    const msg = card.querySelector('#account-delete-msg');
    const say = (text, kind) => { if (msg) { msg.textContent = text; msg.className = 'alert py-2 small alert-' + kind; announce(text); } };
    const refresh = () => { if (go) go.disabled = !deleteConfirmationOk(typed?.value); };

    open.addEventListener('click', () => {
        panel.classList.remove('d-none');
        open.setAttribute('aria-expanded', 'true');
        const known = window.iHymnsApp?.userAuth?.getUser?.()?.email;
        if (email && !email.value && typeof known === 'string') email.value = known;
        (password || typed)?.focus();
    });
    panel.querySelector('#account-delete-cancel')?.addEventListener('click', () => {
        panel.classList.add('d-none');
        open.setAttribute('aria-expanded', 'false');
        if (password) password.value = '';
        if (code) code.value = '';
        if (typed) typed.value = '';
        refresh();
        open.focus();
    });
    typed?.addEventListener('input', refresh);
    refresh();

    sendCode?.addEventListener('click', async () => {
        sendCode.disabled = true;
        const r = await requestEmailCode(email?.value || '');
        say(r.message, r.ok ? 'info' : 'danger');
        sendCode.disabled = false;
        if (r.ok) code?.focus();
    });

    go?.addEventListener('click', async () => {
        go.disabled = true;
        go.setAttribute('aria-busy', 'true');
        try {
            const r = await deleteAccount({ password: password?.value || '', emailCode: code?.value || '', typed: typed?.value || '' });
            if (!r.ok) { say(r.message, 'danger'); return; }
            say('Your account has been deleted.', 'success');
            window.iHymnsApp?.userAuth?.afterAccountDeleted?.();
            window.iHymnsApp?.showToast?.('Your account has been deleted', 'info', 4000);
            window.iHymnsApp?.router?.navigate?.('/');
        } finally {
            go.removeAttribute('aria-busy');
            refresh();
        }
    });
}
