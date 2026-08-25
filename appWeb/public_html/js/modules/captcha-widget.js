/**
 * iHymns — CAPTCHA widget loader / mounter (#947 / #340)
 *
 * ELI5: when an admin has switched a "prove you're human" challenge on for a
 * form, this module loads the provider's widget script, draws the challenge,
 * hands back the answer token to attach to the request, and knows how to reset
 * it if the server says the answer was wrong.
 *
 * DETAIL:
 * -------
 * The ONE client-side CAPTCHA surface. It carries NO provider table, NO
 * hostnames and NO site key — EVERYTHING arrives from the server emit
 * (`app_status.captcha`, produced by includes/captcha.php::captchaClientConfig()),
 * so there is no PHP<->JS provider table to keep in lockstep (rule #35: the
 * response IS the contract). Turnstile / hCaptcha / reCAPTCHA v2 share one
 * browser API shape — `window[renderGlobal].render(el,{sitekey})` /
 * `.getResponse(id)` / `.reset(id)` — which is what lets this one module drive
 * any of them.
 *
 * DORMANT by default: with no `app_status.captcha` key (an unconfigured
 * install, the byte-identical dormancy case), captchaRequired() is false for
 * every form and mountCaptcha() is a no-op — nothing loads, nothing renders.
 *
 * The provider script is injected as a dynamic `<script src>` whose origin the
 * server has already added to the page CSP (index.php, gated on the same
 * config) — so it loads under the enforcing nonce CSP without needing a nonce
 * (an external `src` is allowed by `script-src <origin>`, unlike an inline
 * script — rule #30).
 *
 * WHEN THE WIDGET WON'T LOAD, IT SAYS SO — AND THAT IS ALL IT SAYS. Every
 * failure path below fires one anonymous, fire-and-forget hint at
 * `?action=captcha_widget_health`. That hint is TELEMETRY AND A NUDGE, never
 * evidence: all it can do server-side is bump a counter and ask the server to
 * probe the provider itself, sooner than it otherwise would. It cannot let
 * anybody through — a claim carried in a request would be a universal bypass
 * the moment a bot copied it, so the server's grace-window decision reads only
 * the server's OWN observations. Nothing here reads the reply, and no code path
 * branches on it (guard-enforced: tests/test-captcha-client.js §4).
 *
 * Why bother at all: the widget dies in the BROWSER seconds before the first
 * token-less submission arrives, and during a pure widget-load outage the
 * server would otherwise see nothing whatsoever (a token-less request is
 * refused with no network call). The hint turns "the outage window opens after
 * someone has already been refused" into "it is usually open before they
 * submit".
 *
 * @see includes/captcha.php (the server core + the app_status emit)
 * @see .claude/account-security-1027-947-340-plan.md §3.8
 * @see .claude/captcha-native-and-outage-plan.md §3.4-D (the hint's ONE job)
 * @link https://developers.cloudflare.com/turnstile/get-started/client-side-rendering/
 */

/* rule #31 — same-origin requests go through the shared client, NEVER bare
   fetch() and NEVER a window.fetch override, so cross-cutting request concerns
   are attached by structure rather than by a side effect that may or may not
   have been installed on this route. */
import { apiFetch } from '../utils/api-client.js';

/* The body key the token travels under on every gated JSON endpoint. Mirrors
   IHYMNS_CAPTCHA_BODY_KEY in includes/captcha.php — PHP<->JS lockstep-guarded
   (tests/test-captcha-client.js). */
export const CAPTCHA_BODY_KEY = 'captcha_token';

/* The machine-readable refusal reason (mirrors IHYMNS_CAPTCHA_REASON). Clients
   branch on HTTP 403 + this value, NEVER on the human error prose (rule #35). */
export const CAPTCHA_REASON = 'captcha_required';

/** @type {null | {provider:string,siteKey:string,scriptUrl:string,renderGlobal:string,field:string,forms:string[]}} */
let _config = null;

/** @type {Promise<void> | null} Memoised provider-script load. */
let _scriptPromise = null;

/** @type {boolean} One hint per page load — see _reportWidgetFailure(). */
let _hintSent = false;

/**
 * Tell the server this browser could not put a challenge on screen.
 *
 * FIRE AND FORGET, LITERALLY: the promise is discarded, rejections are
 * swallowed, and the caller does not await it. There is deliberately no return
 * value, because there is no answer worth having — the server replies the same
 * `{ok:true}` in every state (telling the client whether the grace window is
 * open would be telling a bot), and no client behaviour may depend on it. The
 * challenge simply does not render, the form submits without a token, and the
 * SERVER decides what that means.
 *
 * ONE PER PAGE LOAD: a page with several gated forms, or a mount retried after
 * a failed submit, would otherwise send a burst of identical hints. The server
 * caps them per IP anyway; this keeps a normal page from ever reaching that cap
 * and turning a real signal into a throttled one.
 *
 * @param {string} form the captchaFormKeys() value that failed to render
 */
function _reportWidgetFailure(form) {
    if (!_config || _hintSent) return;
    _hintSent = true;
    try {
        apiFetch('/api?action=captcha_widget_health', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ form: form || '' }),
        }).catch(() => { /* the report failing is not worth reporting */ });
    } catch {
        /* Never let telemetry break the form it is describing. */
    }
}

/**
 * Wire the module from the server's app_status payload. Called once at boot
 * (app.js). Absent `captcha` key ⇒ dormant.
 * @param {object | null} appStatus The parsed ?action=app_status response.
 */
export function initCaptcha(appStatus) {
    _config = (appStatus && appStatus.captcha && typeof appStatus.captcha === 'object')
        ? appStatus.captcha
        : null;
    _scriptPromise = null;
    _hintSent = false;
}

/**
 * Is a challenge live for THIS form? (config present AND the form is ticked)
 * @param {string} form a captchaFormKeys() value
 * @returns {boolean}
 */
export function captchaRequired(form) {
    return !!(_config && Array.isArray(_config.forms) && _config.forms.includes(form));
}

/**
 * Does this response mean "solve the challenge"? Branches on the STATUS + the
 * machine reason only (rule #35) — never the prose.
 * @param {number} status HTTP status
 * @param {object|null} data parsed JSON body
 * @returns {boolean}
 */
export function isCaptchaRefusal(status, data) {
    return status === 403 && !!data && data.reason === CAPTCHA_REASON;
}

/** Load the provider widget script once. Rejects if unconfigured / load fails. */
function _loadProviderScript() {
    if (_scriptPromise) return _scriptPromise;
    if (!_config || !_config.scriptUrl || !_config.renderGlobal) {
        return Promise.reject(new Error('captcha not configured'));
    }
    _scriptPromise = new Promise((resolve, reject) => {
        /* Already present (a second form on the same page) — reuse it. */
        if (window[_config.renderGlobal]) { resolve(); return; }
        const s = document.createElement('script');
        s.src = _config.scriptUrl;   /* CSP-allowed: index.php added this origin to script-src */
        s.async = true;
        s.defer = true;
        s.addEventListener('load', () => resolve());
        s.addEventListener('error', () => { _scriptPromise = null; reject(new Error('captcha script failed to load')); });
        document.head.appendChild(s);
    });
    return _scriptPromise;
}

/**
 * Render a challenge widget into `hostEl` and return a small handle. No-op
 * (returns null) when the form isn't gated, the host is missing, or the
 * provider API can't be reached — the caller then simply sends no token, and a
 * gated server refuses with a branchable 403 (never a silent pass).
 *
 * @param {HTMLElement} hostEl a container the widget owns
 * @param {string} form a captchaFormKeys() value
 * @returns {Promise<null | {getToken:()=>string, reset:()=>void, remove:()=>void}>}
 */
export async function mountCaptcha(hostEl, form) {
    if (!captchaRequired(form) || !hostEl) return null;
    try {
        await _loadProviderScript();
    } catch {
        /* Script blocked / provider unreachable / offline — no token. The
           server 403s if it's gated, UNLESS its own probes confirm the provider
           is genuinely down, in which case the grace window admits on the
           ordinary rate limits. Either way that is the server's call: all we do
           is say what we saw. */
        _reportWidgetFailure(form);
        return null;
    }
    const g = window[_config.renderGlobal];
    if (!g || typeof g.render !== 'function') {
        /* The script loaded but its global never appeared — a partially-served
           or truncated bundle, which is an outage shape too. */
        _reportWidgetFailure(form);
        return null;
    }

    hostEl.innerHTML = '';
    const el = document.createElement('div');
    hostEl.appendChild(el);

    let widgetId = null;
    try {
        widgetId = g.render(el, { sitekey: _config.siteKey });
    } catch {
        /* The provider's own render() threw — its back end is reachable enough
           to serve the script but not to draw a challenge. */
        hostEl.innerHTML = '';
        _reportWidgetFailure(form);
        return null;
    }

    return {
        /** The current answer token, or '' if the user hasn't solved it yet. */
        getToken() {
            try {
                return (typeof g.getResponse === 'function') ? (g.getResponse(widgetId) || '') : '';
            } catch {
                return '';
            }
        },
        /** Clear the solved state so the user can try again (after a 403). */
        reset() {
            try { if (typeof g.reset === 'function') { g.reset(widgetId); } } catch { /* ignore */ }
        },
        /** Tear the widget out of the DOM. */
        remove() {
            try { hostEl.innerHTML = ''; } catch { /* ignore */ }
        },
    };
}
