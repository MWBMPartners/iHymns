<?php

declare(strict_types=1);

/**
 * iHymns — the ONE place that emits the Bootstrap CDN <link>/<script> tags (#1676)
 *
 * ELI5
 * ----
 * Every page that wants Bootstrap asks this file for the tags instead of typing
 * the CDN address itself. That way the version and the security hash can only
 * ever be written down once, so two pages can never disagree about them.
 *
 * WHY THIS EXISTS — the same drift, twice
 * ---------------------------------------
 * #527 already fixed a Bootstrap version drift: setup-database.php was loading
 * 5.3.6 CSS while admin-footer.php loaded 5.3.3 JS. The fix was to source both
 * from `APP_CONFIG['libraries']['bootstrap']`, and head-libs.php's doc-block
 * duly records that CSS and JS "can never drift again".
 *
 * They did drift again — just not between those two files. Four pages build a
 * bespoke <head> and so never adopted the shared partial:
 *
 *   manage/editor/index.php    5.3.3, SRI present
 *   manage/editor/editor2.php  5.3.3, NO SRI      <- becomes the default editor (#1601)
 *   manage/editor/import2.php  5.3.3, NO SRI
 *   includes/channel_gate.php  5.3.6, NO SRI
 *
 * The registry meanwhile says 5.3.6. So three versions of "the pinned version"
 * were live at once, and the two newest pages had silently dropped `integrity`
 * altogether — third-party script running inside an authenticated admin session
 * with no way to detect a substituted file. That is precisely the hazard rule
 * #1587 exists to prevent, on the very page epic #1601 is about to promote to
 * the default song editor.
 *
 * THE LESSON, which is why this is a function and not another partial
 * -------------------------------------------------------------------
 * A shared partial only helps the pages that include it. `head-libs.php` bundles
 * the CDN loads together with the theme resolver, three local stylesheets, four
 * JS modules and the error monitor — so a page with a bespoke <head> that wants
 * only the CDN tags cannot adopt it without also adopting the load order of
 * everything else. Faced with that, each of these four pages copied the two
 * lines it needed. Nobody was careless; the shared thing was too coarse to reuse.
 *
 * So the reusable unit here is deliberately the SMALLEST one that can drift: the
 * tags themselves. head-libs.php and admin-footer.php now call these helpers too,
 * so there is exactly one copy of the markup and one copy of the version.
 *
 * @see appWeb/public_html/includes/config.php               APP_CONFIG['libraries']
 * @see appWeb/public_html/manage/includes/head-libs.php     shared admin <head>
 * @see appWeb/public_html/manage/includes/admin-footer.php  shared admin footer
 * @see tests/test-vendor-sri.js                             CI guard, derives the file list from the tree
 * @link https://developer.mozilla.org/docs/Web/Security/Subresource_Integrity
 */

/* Direct access prevention — this file only ever emits markup for a caller. */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

if (!function_exists('ihymns_bootstrap_css_links')) {

    /**
     * Bootstrap (and optionally Bootstrap-Icons) stylesheet tags.
     *
     * ELI5: gives you the two <link> lines for Bootstrap's CSS.
     *
     * Detail: reads the pinned URL + SRI hash out of APP_CONFIG so the caller
     * cannot pin its own. `crossorigin="anonymous"` is REQUIRED alongside
     * `integrity` — without it the browser makes a no-CORS request whose body it
     * is not allowed to read, so it cannot compute the hash and refuses the file
     * outright. That pairing is the single most common way an SRI attempt turns
     * into a dead stylesheet.
     * https://developer.mozilla.org/docs/Web/HTML/Attributes/crossorigin
     *
     * Returns '' rather than throwing if the registry entry is missing: a page
     * that renders unstyled is a visible, self-explaining failure, whereas a
     * fatal error on a sign-in gate locks everybody out.
     *
     * @param bool $withIcons Include the Bootstrap-Icons font stylesheet.
     *                        The public sign-in gate does not use bi-* classes,
     *                        so it opts out rather than pulling a webfont it
     *                        never renders.
     * @return string HTML, already escaped, safe to echo.
     */
    function ihymns_bootstrap_css_links(bool $withIcons = true): string
    {
        $out = '';

        /* `defined()` first, not just `?? null`: APP_CONFIG is a CONSTANT, and
           `UNDEFINED_CONST['k'] ?? null` raises Error in PHP 8 rather than
           yielding null — the null-coalescing operator suppresses undefined
           *index*, never undefined *constant*. Every caller happens to load
           config.php transitively today, but this helper is now reached from a
           public page (channel_gate.php) as well as admin, and a fatal on the
           sign-in gate would lock people out of alpha entirely.
           https://www.php.net/manual/en/language.constants.php */
        if (!defined('APP_CONFIG')) {
            return $out;
        }

        $bs = APP_CONFIG['libraries']['bootstrap'] ?? null;
        if ($bs && !empty($bs['css_cdn'])) {
            $out .= '    <link rel="stylesheet"'
                . ' href="' . htmlspecialchars((string)$bs['css_cdn'], ENT_QUOTES) . '"'
                . ' integrity="' . htmlspecialchars((string)($bs['css_sri'] ?? ''), ENT_QUOTES) . '"'
                . ' crossorigin="anonymous">' . "\n";
        }

        if ($withIcons) {
            $out .= ihymns_bootstrap_icons_css_link();
        }

        return $out;
    }
}

if (!function_exists('ihymns_bootstrap_icons_css_link')) {

    /**
     * The Bootstrap-Icons stylesheet tag, on its own.
     *
     * ELI5: the public site wants the bi-* icon font but NOT the Bootstrap
     * framework CSS (it has its own design system and Font Awesome), so it needs
     * a way to ask for just this one.
     *
     * Detail: public_html/index.php had this link hardcoded, with
     * `crossorigin="anonymous"` but no `integrity` — the one external load in
     * the whole public shell that was not sourced from APP_CONFIG. Its
     * neighbours (Font Awesome, Animate.css) already do this correctly, so it
     * read as deliberate rather than as the oversight it was.
     *
     * The `onerror` fallback copies the shape index.php already uses for
     * Animate.css: on a hash mismatch or CDN outage the browser fires `error` on
     * the <link>, and the handler strips `integrity`/`crossorigin` (they would
     * fail again against the same-origin copy, which has no CORS headers and a
     * different byte-for-byte build) and re-points at the vendored file. That
     * fallback is what makes keeping the hash safe: without it, a bad hash means
     * no stylesheet, which is how the #1647 placeholder-hash incident ended with
     * somebody DELETING the integrity attribute to get a feature working again.
     * `this.onerror=null` first, so a missing vendored copy cannot loop.
     * https://developer.mozilla.org/docs/Web/Security/Subresource_Integrity
     *
     * @param bool $inlineFallback  true (default) emits the inline `onerror=`
     *        fallback, which is correct for /manage/* pages — they send NO CSP,
     *        so inline event handlers run. A caller under the public shell's
     *        enforcing nonce CSP (index.php, whose script-src has no
     *        'unsafe-inline'/'unsafe-hashes') passes false and wires the
     *        CDN→/vendor fallback itself via a nonce'd <script>, because that CSP
     *        refuses inline event handlers outright (#1832).
     * @return string HTML, already escaped, safe to echo.
     */
    function ihymns_bootstrap_icons_css_link(bool $inlineFallback = true): string
    {
        if (!defined('APP_CONFIG')) {
            return '';
        }

        $ic = APP_CONFIG['libraries']['bootstrap_icons'] ?? null;
        if (!$ic || empty($ic['css_cdn'])) {
            return '';
        }

        $local = (string)($ic['css_local'] ?? '');
        /* #1832 — only emit the inline `onerror=` when the caller wants it AND a
           vendored copy exists. On the public index.php shell the caller passes
           $inlineFallback=false (the enforcing nonce CSP would refuse this
           handler); index.php's own nonce'd <script> covers #bootstrap-icons-css
           instead. On CSP-less /manage/* it stays the inline fallback as before. */
        $onerr = ($inlineFallback && $local !== '')
            ? ' onerror="this.onerror=null;this.removeAttribute(\'integrity\');'
              . 'this.removeAttribute(\'crossorigin\');'
              . 'this.href=\'/' . htmlspecialchars($local, ENT_QUOTES) . '\';"'
            : '';

        return '    <link rel="stylesheet" id="bootstrap-icons-css"'
            . ' href="' . htmlspecialchars((string)$ic['css_cdn'], ENT_QUOTES) . '"'
            . ' integrity="' . htmlspecialchars((string)($ic['css_sri'] ?? ''), ENT_QUOTES) . '"'
            . ' crossorigin="anonymous"' . $onerr . '>' . "\n";
    }
}

if (!function_exists('ihymns_bootstrap_js_script')) {

    /**
     * The Bootstrap bundle <script> tag.
     *
     * ELI5: gives you the one <script> line for Bootstrap's JavaScript.
     *
     * Detail: same registry, same SRI + crossorigin pairing as the CSS above.
     * The bundle (not the bare bootstrap.js) is what every consumer here relies
     * on, because it carries Popper — dropdowns, tooltips and popovers all fail
     * silently without it, and the admin nav is built from dropdowns.
     *
     * Deliberately NOT `defer`: several admin pages construct a
     * `new bootstrap.Modal(...)` from a classic inline script further down the
     * body (editor2.php's New-song modal is one), which requires the global to
     * exist by the time that inline script runs.
     *
     * @return string HTML, already escaped, safe to echo.
     */
    function ihymns_bootstrap_js_script(): string
    {
        /* See the note in ihymns_bootstrap_css_links(): undefined CONSTANT, not
           undefined index, so `??` alone would not save us. */
        if (!defined('APP_CONFIG')) {
            return '';
        }

        $bs = APP_CONFIG['libraries']['bootstrap'] ?? null;
        if (!$bs || empty($bs['js_cdn'])) {
            return '';
        }

        return '<script src="' . htmlspecialchars((string)$bs['js_cdn'], ENT_QUOTES) . '"'
            . ' integrity="' . htmlspecialchars((string)($bs['js_sri'] ?? ''), ENT_QUOTES) . '"'
            . ' crossorigin="anonymous"></script>' . "\n";
    }
}
