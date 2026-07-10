/**
 * iHymns — Sign in with Apple (Web) — front-end orchestration (#1470 W2 / #1479)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Drives Apple's JS SDK (`AppleID.auth`) in POPUP mode and hands the
 * resulting credential to the existing `?action=auth_apple` backend
 * (already live, dormant, on `platform=native` — #1402/#1470 W1). This
 * module owns ONLY the Apple-JS + network round-trip; it never touches
 * localStorage or app state — the caller (js/modules/user-auth.js)
 * persists the returned token/user via the SAME saveCredentials() path
 * password/email login already use, so every downstream sync/header/toast
 * behaviour is identical regardless of which sign-in method produced the
 * token.
 *
 * WHY POPUP, NOT REDIRECT: `ihymns_auth` is a SameSite=Lax cookie
 * (includes/auth_cookie.php) — a cross-site `form_post` redirect (Apple's
 * other supported mode, required whenever `scope` includes `name`/`email`)
 * would arrive WITHOUT that cookie attached, so a "link Apple to my
 * signed-in account" flow could never see the session. Popup mode instead
 * hands `id_token` + `code` directly to THIS window's JS via
 * `AppleID.auth.signIn()`, which then does a same-origin fetch() carrying
 * the current bearer/cookie normally. See `.claude/siwa-web-signula-strategy.md`
 * §4 for the full design.
 *
 * NONCE CONTRACT (must match `?action=auth_apple`'s server-side gate,
 * api.php ~3087): the RAW nonce sent in the POST body must be
 * `^[A-Za-z0-9._~-]{16,255}$`; the value handed to `AppleID.auth.init()` is
 * the LOWERCASE HEX SHA-256 of that raw value (the JWT's own `nonce` claim
 * is what Apple signs, and the server recomputes `hash('sha256', $rawNonce)`
 * to compare against it — see appleSiwaVerifyIdentityToken()'s docblock).
 *
 * @link https://developer.apple.com/documentation/sign_in_with_apple/configuring_your_webpage_for_sign_in_with_apple  Apple's JS SDK reference
 * @link https://developer.apple.com/documentation/sign_in_with_apple/errorhandling  AppleID.auth.signIn() rejection shapes
 */

/** Apple's hosted JS SDK — same CDN URL for every consumer worldwide. */
const APPLE_JS_SDK_URL = 'https://appleid.cdn-apple.com/appleauth/static/jsapi/appleid/1/en_US/appleid.auth.js';

/** Module-level singleton so a second sign-in attempt (or the Settings
 *  "Link" button) never injects the <script> tag twice. */
let _sdkPromise = null;

/**
 * Lazy-load Apple's JS SDK exactly once. Resolves once `window.AppleID` is
 * usable; rejects (never throws synchronously) on a network/script failure
 * so callers can show a friendly "couldn't load" message instead of an
 * unhandled exception.
 *
 * @returns {Promise<void>}
 */
function _loadAppleJsSdk() {
    if (typeof window !== 'undefined' && window.AppleID) return Promise.resolve();
    if (_sdkPromise) return _sdkPromise;

    _sdkPromise = new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = APPLE_JS_SDK_URL;
        script.async = true;
        script.onload = () => {
            if (window.AppleID) {
                resolve();
            } else {
                reject(new Error('Apple JS SDK loaded but window.AppleID is unavailable.'));
            }
        };
        script.onerror = () => reject(new Error('Could not load the Sign in with Apple script.'));
        document.head.appendChild(script);
    });
    return _sdkPromise;
}

/**
 * 32 cryptographically-random bytes, base64url-encoded with no padding
 * (43 chars — `A-Za-z0-9-_` only, a strict subset of the server's allowed
 * `[A-Za-z0-9._~-]{16,255}` nonce charset). This is the RAW nonce sent in
 * the POST body; the server re-hashes it and compares against the JWT's
 * `nonce` claim (see this file's header docblock).
 *
 * @returns {string}
 */
function _randomNonce() {
    const bytes = new Uint8Array(32);
    crypto.getRandomValues(bytes);
    let binary = '';
    bytes.forEach((b) => { binary += String.fromCharCode(b); });
    return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

/**
 * 16 random bytes as lowercase hex — an opaque `state` value for
 * `AppleID.auth.init()`. Apple's own popup flow already binds `state`
 * internally; this is defence-in-depth so a future redirect-mode fallback
 * (W4) reusing this same function inherits the check for free.
 *
 * @returns {string}
 */
function _randomState() {
    const bytes = new Uint8Array(16);
    crypto.getRandomValues(bytes);
    return Array.from(bytes).map((b) => b.toString(16).padStart(2, '0')).join('');
}

/**
 * Lowercase hex SHA-256 of a UTF-8 string, via SubtleCrypto — the exact
 * transform the server applies to the raw nonce (`hash('sha256', $rawNonce)`
 * in PHP) so the two sides agree on what the JWT's `nonce` claim should be.
 *
 * @param {string} input
 * @returns {Promise<string>}
 */
async function _sha256HexLower(input) {
    const data = new TextEncoder().encode(input);
    const digest = await crypto.subtle.digest('SHA-256', data);
    return Array.from(new Uint8Array(digest)).map((b) => b.toString(16).padStart(2, '0')).join('');
}

/**
 * Map an `AppleID.auth.signIn()` rejection to a short, non-technical
 * message. Apple's documented error codes are sparse/loosely specified, so
 * this is intentionally a small, generic switch rather than an exhaustive
 * list — every unrecognised shape falls through to one safe default.
 *
 * @param {*} err
 * @returns {string}
 */
function _friendlyAppleJsError(err) {
    const code = (err && (err.error || err.code)) || '';
    switch (code) {
        case 'popup_closed_by_user':
        case 'user_trigger_new_signin_flow':
        case 'user_cancelled_authorize':
            return 'Sign-in was cancelled.';
        default:
            return 'Sign in with Apple is temporarily unavailable. Please try again.';
    }
}

/**
 * Map an `?action=auth_apple` non-2xx HTTP status to a friendly fallback
 * message (used only when the server didn't send its own `error` string).
 *
 * @param {number} status
 * @returns {string}
 */
function _friendlyAppleHttpError(status) {
    switch (status) {
        case 401: return 'Invalid Apple sign-in response. Please try again.';
        case 409: return 'This Apple ID is already linked to a different account.';
        case 429: return 'Too many attempts. Please try again later.';
        case 503: return 'Sign in with Apple is temporarily unavailable.';
        default:  return 'Sign in with Apple failed. Please try again.';
    }
}

/**
 * Run the full web Sign in with Apple popup flow, then POST the resulting
 * credential to `?action=auth_apple`.
 *
 * Does NOT touch localStorage or broadcast any app event — the caller is
 * responsible for persisting `token`/`user` (via UserAuth.saveCredentials())
 * on success, exactly as the password/email login paths already do.
 *
 * @param {object} opts
 * @param {string} opts.apiUrl        Base API URL, e.g. `this.app.config.apiUrl` ('/api').
 * @param {string} opts.clientId      Apple Services ID (`app_status.appleSiwaServicesId`).
 * @param {boolean} [opts.link=false] true = attach Apple to the CURRENT bearer
 *                                      instead of signing in as whichever
 *                                      account the Apple identity resolves to.
 * @param {object} [opts.authHeaders] Extra headers to send — pass the current
 *                                      `Authorization: Bearer ...` header for
 *                                      link mode (auth_apple's link=1 path
 *                                      requires an already-authenticated bearer).
 * @returns {Promise<
 *   { ok: true, token: string, user: object, flow: string } |
 *   { ok: false, error: string }
 * >}
 */
export async function performAppleSignIn({ apiUrl, clientId, link = false, authHeaders = {} } = {}) {
    if (!clientId) {
        return { ok: false, error: 'Sign in with Apple is not configured.' };
    }
    if (typeof window === 'undefined' || typeof crypto === 'undefined' || !crypto.subtle
        || typeof crypto.getRandomValues !== 'function') {
        return { ok: false, error: 'This browser does not support Sign in with Apple.' };
    }

    try {
        await _loadAppleJsSdk();
    } catch {
        return { ok: false, error: 'Could not load Sign in with Apple. Check your connection and try again.' };
    }

    const rawNonce = _randomNonce();
    const state = _randomState();
    let hashedNonce;
    try {
        hashedNonce = await _sha256HexLower(rawNonce);
    } catch {
        return { ok: false, error: 'This browser does not support Sign in with Apple.' };
    }

    try {
        window.AppleID.auth.init({
            clientId,
            scope: 'name email',
            redirectURI: window.location.origin + '/',
            state,
            nonce: hashedNonce,
            usePopup: true,
        });
    } catch {
        return { ok: false, error: 'Could not initialise Sign in with Apple.' };
    }

    let credential;
    try {
        credential = await window.AppleID.auth.signIn();
    } catch (err) {
        return { ok: false, error: _friendlyAppleJsError(err) };
    }

    const authorization = credential && credential.authorization;
    if (!authorization || !authorization.id_token) {
        return { ok: false, error: 'Sign in with Apple did not return a valid credential.' };
    }
    /* Defence in depth — see this function's docblock. Apple's popup
       response may omit `state` entirely (undocumented either way), so only
       reject on an EXPLICIT mismatch, never on absence. */
    if (authorization.state !== undefined && authorization.state !== state) {
        return { ok: false, error: 'Sign in with Apple response could not be verified. Please try again.' };
    }

    const body = {
        identityToken: authorization.id_token,
        nonce: rawNonce,
        platform: 'web',
        link: link ? 1 : 0,
    };
    if (authorization.code) body.authorizationCode = authorization.code;

    /* `user` (name) is present ONLY on Apple's first authorization for this
       account — snapshot it now, the server never sees it again. */
    const userName = credential.user && credential.user.name;
    if (userName && (userName.firstName || userName.lastName)) {
        body.fullName = { givenName: userName.firstName || '', familyName: userName.lastName || '' };
    }

    let res;
    try {
        res = await fetch(`${apiUrl}?action=auth_apple`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...authHeaders,
            },
            body: JSON.stringify(body),
        });
    } catch {
        return { ok: false, error: 'Network error. Please check your connection and try again.' };
    }

    let data = null;
    try {
        data = await res.json();
    } catch {
        return { ok: false, error: `Server returned an unreadable response (HTTP ${res.status}).` };
    }

    if (!res.ok) {
        return { ok: false, error: (data && data.error) || _friendlyAppleHttpError(res.status) };
    }

    return { ok: true, token: data.token, user: data.user, flow: data.flow || (link ? 'linked' : 'matched') };
}
