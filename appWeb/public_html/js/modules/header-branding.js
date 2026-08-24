/* ============================================================================
 * iHymns — App-header org co-brand (#1840, App Header Option A)
 *
 * ELI5
 * ----
 * If you're signed in and your church has uploaded a small logo, this
 * quietly adds it to the top-left of every page, right beside the iHymns
 * name — "this church, on iHymns". If you're signed out, or your church
 * hasn't uploaded anything suitable, the header looks exactly like it
 * always has.
 *
 * DETAILED
 * --------
 * The app shell (`#app-header` / `#logo-nav-btn` in index.php) is ONE
 * document served identically to every visitor and pre-cached by the
 * service worker — it is NOT per-user personalised server-side. So the
 * co-brand can only be a CLIENT-SIDE progressive enhancement: this module
 * injects an `<img>` + a hairline divider into the existing `#logo-nav-btn`
 * button, exactly the way `user-auth.js` toggles `#nav-manage-li` for
 * signed-in state. No change to index.php's markup is required.
 *
 * Booted ONCE from app.js (not the router — the header lives OUTSIDE the
 * SPA's swapped page content, so rule #32's "torn down on every navigation"
 * concern does not apply here: the emblem sits INSIDE the persistent
 * header, not a fixed element floating over swapped content).
 *
 * WHAT DECIDES THE ORG: the first org (the `my_organisations` API's own
 * `Name ASC` order — the same "first org" precedent print.js's
 * `fetchPrintOrgLogos()` already established) whose `logos` meta resolves a
 * `header` asset for the CURRENT theme via `resolveThemedAsset()`
 * (js/modules/org-logo.js — the exact twin of
 * `ihymnsOrgLogoResolveThemedAsset()`, includes/org_logo_helpers.php).
 * Multi-org membership therefore always shows the SAME org's emblem
 * deterministically; see the plan's §B.1 for the (non-blocking, cheaply
 * changeable) recommended default this locks in.
 *
 * WHAT DECIDES THE THEME: `document.documentElement.getAttribute(
 * 'data-bs-theme')` — the ONE theme signal every stylesheet already reads
 * (rule #16), covering user choice, system-follow, and high-contrast-on-
 * light in a single read. Re-resolved on a `MutationObserver` watching that
 * attribute — deliberately NOT a new `ihymns:*` event (rule #35's "a
 * mechanism, not a comment"): the attribute IS the source of truth, so the
 * observer can never miss a dispatcher the way two differently-spelled
 * event names can drift apart (#1581's failure class).
 *
 * DEGRADATION: anonymous / no org / no org with a resolvable `header` asset
 * / the `my_organisations` fetch failing / an un-migrated install (`logos`
 * field simply absent) all render the header exactly as it was before this
 * feature — never a broken image (an `<img onerror>` handler removes it),
 * never a layout reservation for a logo that never arrives.
 *
 * @link appWeb/public_html/index.php               #logo-nav-btn — unchanged markup, injected into
 * @link appWeb/public_html/js/modules/org-logo.js   resolveThemedAsset()/fetchMyOrgs()/orgLogoUrl() this module consumes
 * @link appWeb/public_html/js/modules/user-auth.js  EVT_AUTH_CHANGED dispatcher this module listens to
 * @see .claude/org-logo-surfaces-1840-plan.md §5
 * @see #1840
 * ========================================================================== */

import { EVT_AUTH_CHANGED } from '../constants.js';
import { fetchMyOrgs, resolveThemedAsset, orgLogoUrl } from './org-logo.js';

const HEADER_BUTTON_ID = 'logo-nav-btn';
const EMBLEM_CLASS = 'header-org-emblem';
const DIVIDER_CLASS = 'header-brand-divider';

/** Tracks the currently-shown rendition so a same-theme/same-org re-resolve
 *  (e.g. an unrelated attribute mutation on <html>) is a no-op rather than
 *  tearing down and rebuilding identical DOM on every call. */
let _shownKey = null;
let _observer = null;

/** The ONE theme signal every stylesheet already reads (rule #16). */
function currentTheme() {
    return document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'dark' : 'light';
}

/** Remove any injected emblem/divider — idempotent, safe to call when
 *  nothing is present. ALWAYS the first thing a re-render does (mirrors
 *  rule #32's "teardown before any early return" discipline, even though
 *  this element isn't the fixed-position class that rule targets — the
 *  same "never strand stale UI" reasoning applies). */
function teardown() {
    const btn = document.getElementById(HEADER_BUTTON_ID);
    _shownKey = null;
    if (!btn) { return; }
    const img = btn.querySelector('.' + EMBLEM_CLASS);
    const divider = btn.querySelector('.' + DIVIDER_CLASS);
    if (img) { img.remove(); }
    if (divider) { divider.remove(); }
}

/** Inject the resolved emblem + hairline as the button's FIRST children —
 *  the existing <i class="fa-music"> / wordmark content follows unchanged. */
function inject(orgId, asset) {
    const btn = document.getElementById(HEADER_BUTTON_ID);
    if (!btn) { return; }

    const key = orgId + '|' + asset.kind + '|' + asset.variant;
    if (key === _shownKey) { return; } // already showing this exact rendition
    teardown();
    _shownKey = key;

    const img = document.createElement('img');
    img.className = EMBLEM_CLASS;
    /* Decorative co-brand signage, not the button's accessible name — the
       button already has an explicit aria-label (index.php), which WCAG
       accname computation prefers over any descendant content anyway. */
    img.alt = '';
    img.setAttribute('aria-hidden', 'true');
    /* onerror -> remove: absence renders as absence, never a broken-image
       glyph in the header on every single page load. */
    img.addEventListener('error', teardown);
    img.src = orgLogoUrl(orgId, asset);

    const divider = document.createElement('span');
    divider.className = DIVIDER_CLASS;
    divider.setAttribute('aria-hidden', 'true');

    /* Order matters: insert the divider first (becomes the new first
       child, ahead of the existing <i> icon), then the img ahead of THAT —
       final order is [img, divider, <i>, wordmark, ...], matching the
       plan's §5.2 worked markup exactly. */
    btn.insertBefore(divider, btn.firstChild);
    btn.insertBefore(img, btn.firstChild);
}

/**
 * Fetch the signed-in user's orgs and (re-)render the header co-brand for
 * the CURRENT theme. Safe to call unconditionally (on boot, on every
 * EVT_AUTH_CHANGED, on every theme-attribute mutation) — `fetchMyOrgs()`
 * itself degrades to `null` for an anonymous/failed lookup, and this
 * function just tears down when that happens.
 */
async function resolveAndRender() {
    const btn = document.getElementById(HEADER_BUTTON_ID);
    if (!btn) { return; } // defensive — the shell always has this button today

    const orgs = await fetchMyOrgs();
    if (!Array.isArray(orgs)) {
        teardown(); // anonymous / no orgs / fetch failure
        return;
    }

    const theme = currentTheme();
    for (const org of orgs) {
        const logos = Array.isArray(org.logos) ? org.logos : [];
        if (!logos.length) { continue; }
        const asset = resolveThemedAsset(logos, 'header', theme);
        if (!asset) { continue; }
        const meta = logos.find((l) => l && l.kind === asset.kind && l.variant === asset.variant);
        inject(org.id, { kind: asset.kind, variant: asset.variant, v: meta ? meta.v : '' });
        return;
    }
    teardown(); // signed in, but no org has a header-resolvable logo
}

/** Watch <html data-bs-theme> so a live theme change (Settings toggle, or
 *  the system-follow listener flipping it) re-resolves without a new event
 *  name — this ALSO catches the admin-theme-init.php path for free, should
 *  this module ever be loaded there. */
function observeThemeChanges() {
    if (_observer) { return; }
    _observer = new MutationObserver((mutations) => {
        for (const m of mutations) {
            if (m.type === 'attributes' && m.attributeName === 'data-bs-theme') {
                resolveAndRender();
                return;
            }
        }
    });
    _observer.observe(document.documentElement, { attributes: true, attributeFilter: ['data-bs-theme'] });
}

/**
 * Boot once from app.js. Idempotent (a `data-*` flag on <body> guards a
 * double-call, mirroring the other `boot*()` module entry points).
 */
export function bootHeaderBranding() {
    if (document.body && document.body.dataset.headerBrandingBooted === '1') { return; }
    if (document.body) { document.body.dataset.headerBrandingBooted = '1'; }

    observeThemeChanges();

    /* EVT_AUTH_CHANGED's detail carries {loggedIn, user} (user-auth.js's
       own _broadcastAuthChanged() shape) — a signed-out transition tears
       down immediately without a wasted fetch; a signed-in transition
       (login, or the router's re-dispatch on navigation) re-resolves. */
    document.addEventListener(EVT_AUTH_CHANGED, (e) => {
        if (e && e.detail && e.detail.loggedIn === false) {
            teardown();
            return;
        }
        resolveAndRender();
    });

    /* Initial boot attempt — fetchMyOrgs() itself resolves to null for an
       anonymous visitor (it short-circuits on a missing auth token WITHOUT
       touching the network, so the auth-only my_organisations endpoint is
       never fired — and never 401s — on an anonymous page load), so no
       separate isLoggedIn() pre-check is needed here (mirrors print.js's
       fetchPrintOrgLogos() calling convention). */
    resolveAndRender();
}
