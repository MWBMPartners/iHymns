/**
 * iHymns — Device-Code Pairing "Link a device" page controller (#1407)
 *
 * PURPOSE:
 * ELI5: this is the code that runs the /link page — checking a code the
 * user typed in against the server, showing "yes, a device is waiting" (or
 * an opaque "that code isn't active"), and wiring up the Approve / Deny
 * buttons.
 *
 * DETAILED:
 * Mirrors settings-language-filter.js's self-contained module shape (no
 * dependency on the Router's `this.app` — reads the bearer token straight
 * from localStorage like that module does) rather than the class-based
 * modules (favorites.js, setlist.js) since this page has no per-navigation
 * state to retain between visits.
 *
 * Endpoints consumed (api.php, includes/device_code.php):
 *   GET  ?action=auth_device_code_link_lookup&user_code=XXXX-XXXX
 *   POST ?action=auth_device_code_approve  { user_code }
 *   POST ?action=auth_device_code_deny     { user_code }
 * All three require an Authorization: Bearer header (session-based
 * `isAuthenticated()` doesn't apply here — this is the main-app bearer/
 * cookie auth surface, api.php's own getAuthenticatedUser(), same as every
 * other main-app account action e.g. auth_provider_unlink). The approve/deny
 * POSTs additionally rely on api.php's blanket X-Requested-With CSRF gate +
 * validateCsrfRequest()'s same-origin fallback (rule #29) — this module
 * never bakes/sends a csrf_token field, matching unlinkProvider()'s
 * established precedent (user-auth.js).
 */

import { apiFetch } from '../utils/api-client.js';

const STORAGE_AUTH_TOKEN = 'ihymns_auth_token';

function getToken() {
    try { return localStorage.getItem(STORAGE_AUTH_TOKEN) || null; } catch { return null; }
}

function authHeaders() {
    const token = getToken();
    return token ? { 'Authorization': `Bearer ${token}` } : {};
}

/** Normalise a typed code the SAME way the server does, purely for the
 *  "does this even look like a code" UX check before spending a network
 *  round-trip — the server re-validates authoritatively regardless. */
function looksLikeCode(raw) {
    const stripped = String(raw || '').replace(/[^A-Za-z0-9]/g, '').toUpperCase();
    return stripped.length === 8;
}

async function lookupCode(userCode) {
    const url = new URL('/api', window.location.origin);
    url.searchParams.set('action', 'auth_device_code_link_lookup');
    url.searchParams.set('user_code', userCode);
    const res = await apiFetch(url.toString(), {
        headers: { 'X-Requested-With': 'XMLHttpRequest', ...authHeaders() },
    });
    if (res.status === 401) return { ok: false, error: 'not_authenticated' };
    if (res.status === 429) return { ok: false, error: 'rate_limited' };
    try { return await res.json(); } catch { return { ok: false, error: 'network' }; }
}

async function resolveCode(userCode, approve) {
    const action = approve ? 'auth_device_code_approve' : 'auth_device_code_deny';
    const res = await apiFetch(`/api?action=${action}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...authHeaders() },
        body: JSON.stringify({ user_code: userCode }),
    });
    if (res.status === 401) return { ok: false, error: 'not_authenticated' };
    if (res.status === 429) return { ok: false, error: 'rate_limited' };
    try { return await res.json(); } catch { return { ok: false, error: 'network' }; }
}

function renderResult(resultEl, html) {
    if (resultEl) resultEl.innerHTML = html;
}

/**
 * Boot the /link page. Idempotent (safe to call once per page load; the SPA
 * router replaces the whole fragment on every navigation so there's no
 * "already booted" state to guard here, unlike a persistent-DOM module).
 */
export function bootDeviceLinkPage() {
    const signedOut = document.getElementById('link-signed-out');
    const signedInWrap = document.getElementById('link-signed-in-wrap');
    const form = document.getElementById('link-code-form');
    const input = document.getElementById('link-user-code');
    const resultEl = document.getElementById('link-result');
    const loginBtn = document.getElementById('link-login-btn');
    if (!form || !input || !resultEl || !signedOut || !signedInWrap) return;

    const app = window.iHymnsApp;
    const loggedIn = !!getToken();

    signedOut.classList.toggle('d-none', loggedIn);
    signedInWrap.classList.toggle('d-none', !loggedIn);

    if (!loggedIn) {
        loginBtn?.addEventListener('click', () => {
            app?.userAuth?.showAuthModal?.('login');
        });
        return;
    }

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const raw = input.value;
        if (!looksLikeCode(raw)) {
            renderResult(resultEl, '<div class="alert alert-warning py-2" role="alert">Enter the 8-character code exactly as shown.</div>');
            return;
        }
        renderResult(resultEl, '<p class="text-muted small mb-0"><i class="fa-solid fa-spinner fa-spin me-1" aria-hidden="true"></i>Checking…</p>');

        const lookup = await lookupCode(raw);
        if (!lookup.ok) {
            const msg = lookup.error === 'not_authenticated'
                ? 'Your sign-in expired — please sign in again.'
                : lookup.error === 'rate_limited'
                    ? 'Too many attempts — please wait a few minutes and try again.'
                    : 'That code isn’t active. Check the device and try again.';
            renderResult(resultEl, `<div class="alert alert-warning py-2" role="alert">${msg}</div>`);
            return;
        }

        const userCode = lookup.userCode;
        const minutesLeft = Math.max(1, Math.ceil((lookup.expiresIn || 0) / 60));
        renderResult(resultEl, `
            <div class="alert alert-info" role="alert">
                <p class="mb-2">A device is waiting to sign in with code <strong>${userCode}</strong> (expires in about ${minutesLeft} minute${minutesLeft === 1 ? '' : 's'}).</p>
                <button type="button" class="btn btn-success btn-sm me-2" id="link-approve-btn">
                    <i class="fa-solid fa-check me-1" aria-hidden="true"></i>Approve
                </button>
                <button type="button" class="btn btn-outline-danger btn-sm" id="link-deny-btn">
                    <i class="fa-solid fa-xmark me-1" aria-hidden="true"></i>Deny
                </button>
            </div>`);

        const finish = async (approve) => {
            renderResult(resultEl, '<p class="text-muted small mb-0"><i class="fa-solid fa-spinner fa-spin me-1" aria-hidden="true"></i>Working…</p>');
            const result = await resolveCode(userCode, approve);
            if (result.ok) {
                renderResult(resultEl, approve
                    ? '<div class="alert alert-success py-2" role="alert"><i class="fa-solid fa-circle-check me-1" aria-hidden="true"></i>Device approved — it should sign in within a few seconds.</div>'
                    : '<div class="alert alert-secondary py-2" role="alert">Device sign-in denied.</div>');
                form.reset();
            } else {
                renderResult(resultEl, '<div class="alert alert-warning py-2" role="alert">That code is no longer active. It may have expired or already been resolved.</div>');
            }
        };

        document.getElementById('link-approve-btn')?.addEventListener('click', () => finish(true));
        document.getElementById('link-deny-btn')?.addEventListener('click', () => finish(false));
    });
}
