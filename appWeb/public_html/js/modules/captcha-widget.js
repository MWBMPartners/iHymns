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
 * @see includes/captcha.php (the server core + the app_status emit)
 * @see .claude/account-security-1027-947-340-plan.md §3.8
 * @link https://developers.cloudflare.com/turnstile/get-started/client-side-rendering/
 */

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
        return null;   /* script blocked / offline — no token; the server 403s if it's gated */
    }
    const g = window[_config.renderGlobal];
    if (!g || typeof g.render !== 'function') return null;

    hostEl.innerHTML = '';
    const el = document.createElement('div');
    hostEl.appendChild(el);

    let widgetId = null;
    try {
        widgetId = g.render(el, { sitekey: _config.siteKey });
    } catch {
        hostEl.innerHTML = '';
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
