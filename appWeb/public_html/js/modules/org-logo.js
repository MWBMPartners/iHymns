/* ============================================================================
 * iHymns — Shared client-side org-logo helpers (#1840)
 *
 * ELI5
 * ----
 * Three places on screen — the app's top header, the projector, and the
 * social "share this" picture — all want to show a church's logo, and all
 * three need to answer the SAME two questions: "which of the org's uploaded
 * logo shapes fits here?" and "does this org even have one I can show?".
 * This file is the ONE place that answers both, so no page has to invent
 * its own copy.
 *
 * DETAILED
 * --------
 * `ORG_LOGO_SURFACE_PREFS` is the exact client-side twin of
 * `IHYMNS_ORG_LOGO_SURFACE_PREFS` (includes/org_logo_helpers.php, #1840) —
 * kept in LOCKSTEP by `tests/php/test-org-logo-surfaces.php` check (g), the
 * SAME "a mechanism, not a comment" discipline (rule #35) that guard already
 * enforces for the print `logo` block's kind registry (check f). Only
 * `header` is ever actually CONSUMED client-side today (the header co-brand
 * module, #1840 commit 6) — `projector` and `og-card` are resolved
 * server-side (VENUES JSON / og-image.php) and appear here purely so the
 * lockstep guard can assert the WHOLE map agrees, which is what stops a
 * future client surface from forking its own copy.
 *
 * `resolveThemedAsset()` is a byte-for-byte port of
 * `ihymnsOrgLogoResolveThemedAsset()` — same per-kind, two-step algorithm,
 * same `darkCapableOnly` skip, same "nothing resolved => null, never a
 * substituted kind" contract. See that PHP function's doc-block
 * (includes/org_logo_helpers.php) for the full worked examples; this is
 * intentionally NOT re-derived independently — a second, drifted copy of
 * the same fallback maths is exactly what rule #35 forbids.
 *
 * `fetchMyOrgs()` is the FETCH half of what was previously print.js's
 * private `fetchPrintOrgLogos()` (#1830 §6.3) — extracted here the moment a
 * second consumer (the header co-brand module) needed the same
 * session-cached `my_organisations` lookup (the modularity rule's "extract
 * on the second use" trigger). print.js keeps its own print-specific FOLD
 * (byKind, filtered to the 'default' variant — §3.4 of the #1840 plan) but
 * now delegates the actual network call to this shared promise, so the two
 * consumers share ONE in-flight request instead of two.
 *
 * @link appWeb/public_html/includes/org_logo_helpers.php  the PHP twin this mirrors
 * @link appWeb/public_html/js/modules/print.js             the original fetch this was extracted from
 * @link appWeb/public_html/js/utils/api-client.js           apiFetch() — rule #31, never bare fetch()
 * @see .claude/org-logo-surfaces-1840-plan.md §3.2/§3.3
 * @see #1840
 * ========================================================================== */

import { apiFetch } from '../utils/api-client.js';
import { STORAGE_AUTH_TOKEN } from '../constants.js';

/* #1840 — client mirror of IHYMNS_ORG_LOGO_SURFACE_PREFS
   (includes/org_logo_helpers.php). Key order within each `kinds` array IS
   that surface's resolution ladder (the SAME key-order-is-the-ladder
   doctrine ORG_LOGO_KINDS in print.js already follows) — kept in lockstep
   with the PHP registry by tests/php/test-org-logo-surfaces.php check (g).
   Never type a surface's kind list a second time anywhere else. */
export const ORG_LOGO_SURFACE_PREFS = {
    header:    { kinds: ['emblem', 'favicon'],             darkCapableOnly: false },
    projector: { kinds: ['emblem', 'reversed', 'favicon'], darkCapableOnly: false },
    'og-card': { kinds: ['reversed', 'emblem'],            darkCapableOnly: true },
};

/**
 * Resolve which (kind, variant) a themed surface should render, given the
 * org's ACTIVE (kind, variant) rows and the viewer's current theme. Pure —
 * no fetch, no DOM. Exact JS twin of `ihymnsOrgLogoResolveThemedAsset()`
 * (includes/org_logo_helpers.php) — see that function's doc-block for the
 * full per-kind, two-step algorithm and worked examples.
 *
 * @param {Array<{kind:string,variant:string}>} logos  ACTIVE logo rows
 *   (the `my_organisations` API's `logos` field shape already matches this —
 *   pass it straight through, extra keys like `v`/`alt` are ignored here).
 * @param {string} surface  One of ORG_LOGO_SURFACE_PREFS's keys. An unknown
 *   surface resolves to `null`, never throws (mirrors the PHP twin).
 * @param {string} theme  'light' or 'dark'; anything else is treated as
 *   'light' (defensive default, mirrors the PHP twin).
 * @returns {{kind:string,variant:string}|null}  null => render nothing.
 */
export function resolveThemedAsset(logos, surface, theme) {
    const prefs = ORG_LOGO_SURFACE_PREFS[surface];
    if (!prefs) { return null; }
    const t = theme === 'dark' ? 'dark' : 'light';

    /* A fast Set of "kind|variant" strings — mirrors the PHP $have lookup. */
    const have = new Set();
    (Array.isArray(logos) ? logos : []).forEach((l) => {
        if (l && l.kind && l.variant) { have.add(l.kind + '|' + l.variant); }
    });

    for (const kind of prefs.kinds) {
        /* Step 1 — the exact theme-paired rendition. */
        if (have.has(kind + '|' + t)) { return { kind, variant: t }; }
        /* Step 2 — the 'default' rendition, skipped on a darkCapableOnly
           surface for every kind except 'reversed'. */
        if (prefs.darkCapableOnly && kind !== 'reversed') { continue; }
        if (have.has(kind + '|default')) { return { kind, variant: 'default' }; }
    }
    return null;
}

/**
 * Build the ONE serving-endpoint URL for a resolved (kind, variant) —
 * always `<img src>`, NEVER inlined markup or a data-URI (rule #42). Every
 * caller of this module emits the returned string as a plain `<img src>`
 * attribute; `tests/php/test-org-logo-surfaces.php` check (k) asserts the
 * consumer files that build a src this way actually do so via
 * `/org-logo.php?`.
 *
 * @param {number|string} orgId
 * @param {{kind:string, variant:string, v?:string}} asset
 * @returns {string}
 */
export function orgLogoUrl(orgId, asset) {
    const { kind, variant, v } = asset || {};
    return '/org-logo.php?org=' + encodeURIComponent(orgId)
        + '&kind=' + encodeURIComponent(kind || '')
        + '&variant=' + encodeURIComponent(variant || 'default')
        + '&v=' + encodeURIComponent(v || '');
}

/* Session-cached my_organisations lookup — ONE shared in-flight/settled
   promise for the whole page (print.js's own #1830 rationale, carried
   over unchanged: my_organisations is a small, rarely-changing list, so a
   single shared promise is simpler and cheaper than a per-caller Map).
   Anonymous user / no orgs / a failed fetch all resolve to `null` — every
   caller then renders nothing, never a broken image. NOT reset between
   navigations on purpose: worst case is one stale/missing logo until the
   next full page load, the same trade-off print.js already accepted. */
let _myOrgsPromise = null;

/**
 * Fetch the signed-in user's organisations (id/name/logos meta only — no
 * blobs), via the shared `apiFetch()` (rule #31, never a bare `fetch()`).
 *
 * ANONYMOUS SHORT-CIRCUIT: `my_organisations` is an authenticated-only
 * endpoint — a visitor with no bearer token has no orgs to fetch, and firing
 * it anyway 401s. The app-layer try/catch below turns that 401 into a clean
 * `null`, but the BROWSER still logs "Failed to load resource: 401" as a
 * console error at the network level on every anonymous page load (the shell
 * is one document served to every visitor). That is both a wasted request and
 * the exact console noise the Browser-smoke boot gate counts (tests/browser/
 * smoke.spec.js). So when there is no token we resolve to `null` WITHOUT ever
 * touching the network — and WITHOUT memoising it, so a later in-page sign-in
 * (EVT_AUTH_CHANGED → header re-resolve; or a print action after login) makes
 * a fresh, now-authenticated attempt. Mirrors user-auth.js's `isLoggedIn()`
 * (`!!getToken()`); read directly from localStorage to avoid importing the
 * UserAuth class here (rule #31's load-order-cycle caution). Rule #42:
 * "signed-in members only".
 *
 * @returns {Promise<Array<{id:number,name:string,logos?:Array}>|null>}
 *   null on anonymous (no token — no request made) / a 401 / network failure /
 *   un-migrated install (`logos` simply absent per-org in that last case, not
 *   a null return).
 */
export function fetchMyOrgs() {
    let token = null;
    try { token = localStorage.getItem(STORAGE_AUTH_TOKEN); } catch (_e) { token = null; }
    if (!token) { return Promise.resolve(null); } // anonymous — never fire the auth-only endpoint

    if (_myOrgsPromise) { return _myOrgsPromise; }
    _myOrgsPromise = (async () => {
        try {
            const res = await apiFetch('/api?action=my_organisations',
                { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin', auth: true });
            if (!res.ok) { return null; }
            const json = await res.json();
            return Array.isArray(json.organisations) ? json.organisations : null;
        } catch (_e) {
            return null; // anonymous (401) or any network/parse failure — fail to "no org"
        }
    })();
    return _myOrgsPromise;
}
