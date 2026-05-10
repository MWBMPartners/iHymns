<?php

declare(strict_types=1);

/**
 * iHymns — Admin theme-init resolver (#955)
 *
 * Synchronous inline <script> that runs early in <head> on every
 * admin page, BEFORE any CSS loads. Reads the user's theme
 * preference from localStorage (same key the public site uses) and
 * sets the matching attributes on <html> so the page paints with
 * the correct theme — no flash of wrong content.
 *
 * Sets the same attributes the public site's settings.js applyTheme()
 * sets:
 *   - data-bs-theme         ∈ {light, dark}
 *   - data-ihymns-theme     ∈ {light, dark, high-contrast}
 *   - data-ihymns-contrast="high" when ihymns-theme === 'high-contrast'
 *   - data-ihymns-cvd       ∈ {protanopia, deuteranopia, tritanopia, achromatopsia} or absent
 *
 * Also exposes `window.iHymnsAdminApplyTheme(theme)` so the admin-nav
 * theme dropdown can re-apply after writing to storage.
 *
 * USAGE
 *   <head>
 *     ... <title> ...
 *     <?php require __DIR__ . '/admin-theme-init.php'; ?>
 *     ... <link rel="stylesheet" ...> ...
 *
 * Loaded from `head-libs.php` (every admin page) and from
 * `manage/editor/index.php` (which has its own bespoke <head>
 * that doesn't go through head-libs.php).
 *
 * Replaces the per-page hardcoded `<html data-bs-theme="dark">`
 * convention that ignored user preference (#953 / #955).
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}
?>
<script>
(function () {
    /* Mirror of the storage keys used by js/modules/settings.js +
       js/constants.js so admin pages share the SAME preference the
       public site reads/writes. Keep in lock-step if those keys ever
       change. */
    var THEME_KEY = 'ihymns_theme';
    var CVD_KEY   = 'ihymns_cvd_mode';

    /**
     * Apply a chosen theme by setting the four <html> attributes the
     * stylesheets read. Mirrors settings.js applyTheme() semantics so
     * admin pages and the public site stay visually consistent.
     *
     * @param {string} rawTheme One of 'light' | 'dark' | 'high-contrast' | 'system'
     */
    function applyTheme(rawTheme) {
        rawTheme = rawTheme || 'system';
        var ihymnsTheme = rawTheme;
        var bsTheme     = 'light';

        if (rawTheme === 'system') {
            var prefersDark = window.matchMedia
                && window.matchMedia('(prefers-color-scheme: dark)').matches;
            ihymnsTheme = prefersDark ? 'dark' : 'light';
            bsTheme = ihymnsTheme;
        } else if (rawTheme === 'dark') {
            bsTheme = 'dark';
        } else if (rawTheme === 'high-contrast') {
            /* High-contrast uses light Bootstrap base + the overrides
               in accessibility.css. Same convention as settings.js. */
            bsTheme = 'light';
        }

        var html = document.documentElement;
        html.setAttribute('data-bs-theme', bsTheme);
        html.setAttribute('data-ihymns-theme', ihymnsTheme);
        if (ihymnsTheme === 'high-contrast') {
            html.setAttribute('data-ihymns-contrast', 'high');
        } else {
            html.removeAttribute('data-ihymns-contrast');
        }

        /* CVD palette is independent of theme. */
        try {
            var cvd = localStorage.getItem(CVD_KEY);
            if (cvd) {
                html.setAttribute('data-ihymns-cvd', cvd);
            } else {
                html.removeAttribute('data-ihymns-cvd');
            }
        } catch (_e) { /* localStorage unavailable — leave CVD alone */ }
    }

    /* Synchronous first-paint resolution. */
    try {
        var raw = (window.localStorage && localStorage.getItem(THEME_KEY)) || 'system';
        applyTheme(raw);
    } catch (_e) {
        /* localStorage blocked (private browsing, third-party context).
           Fall back to OS pref alone — that's still better than the old
           hardcoded `dark` for users who actually want light. */
        try {
            var dark = window.matchMedia
                && window.matchMedia('(prefers-color-scheme: dark)').matches;
            document.documentElement.setAttribute('data-bs-theme', dark ? 'dark' : 'light');
            document.documentElement.setAttribute('data-ihymns-theme', dark ? 'dark' : 'light');
        } catch (_e2) { /* nothing else we can do */ }
    }

    /* Live-update on OS theme change for users on 'system' preference.
       Mirrors settings.js init()'s matchMedia listener so admin pages
       respond to OS toggles without a manual reload. */
    try {
        if (window.matchMedia) {
            var mql = window.matchMedia('(prefers-color-scheme: dark)');
            var onChange = function () {
                var current = (window.localStorage && localStorage.getItem(THEME_KEY)) || 'system';
                if (current === 'system') applyTheme('system');
            };
            if (mql.addEventListener) {
                mql.addEventListener('change', onChange);
            } else if (mql.addListener) {
                /* Safari < 14 / older WebKit */
                mql.addListener(onChange);
            }
        }
    } catch (_e) { /* swallow — listener is a nice-to-have */ }

    /* Expose so the admin-nav dropdown can re-apply after persisting. */
    window.iHymnsAdminApplyTheme = applyTheme;
})();
</script>
