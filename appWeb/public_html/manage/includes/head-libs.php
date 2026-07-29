<?php

declare(strict_types=1);

/**
 * iHymns — Shared <head> library loads for /manage/* (#527)
 *
 * Centralised <link> stylesheet loads for every admin page. Pulls the
 * Bootstrap version + URL + SRI from APP_CONFIG['libraries']['bootstrap']
 * so a version bump propagates to every admin page in one place. The
 * JS counterpart is loaded by admin-footer.php from the same config
 * entry, so CSS and JS versions can never drift again — the cause of
 * the setup-database.php 5.3.6 vs admin-footer.php 5.3.3 mismatch
 * the audit flagged.
 *
 * USAGE
 *   require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-libs.php';
 * Place inside <head> after <title> (so per-page CSS overrides can
 * still take effect by appending after this require).
 *
 * EXPECTED CALLER STATE
 *   - APP_CONFIG['libraries']['bootstrap'] defined (loaded by
 *     auth.php's bootstrap, available globally on every admin page).
 *
 * Bootstrap-Icons is currently pinned here (no APP_CONFIG entry yet).
 * If a future PR centralises icons into APP_CONFIG['libraries']
 * ['bootstrap_icons'], swap the inline literal for the config lookup
 * the same way bootstrap is treated below.
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

/* #955 — synchronous theme resolver MUST run before any CSS link
   so the browser has the correct data-bs-theme / data-ihymns-theme
   attributes set before computing layout. No FOUC. */
require __DIR__ . DIRECTORY_SEPARATOR . 'admin-theme-init.php';

$_bs = APP_CONFIG['libraries']['bootstrap'] ?? null;
if ($_bs && !empty($_bs['css_cdn'])):
    ?>
    <link rel="stylesheet"
          href="<?= htmlspecialchars((string)$_bs['css_cdn'], ENT_QUOTES) ?>"
          integrity="<?= htmlspecialchars((string)($_bs['css_sri'] ?? ''), ENT_QUOTES) ?>"
          crossorigin="anonymous">
    <?php
endif;
?>
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
          integrity="sha384-XGjxtQfXaH2tnPFa9x+ruJTuLE3Aa6LhHSWRr1XeTyhezb4abCG4ccI5AkVDxqC+"
          crossorigin="anonymous">
<?php
/* Shared app + admin CSS — paths relative to public_html root.
   `dirname(__DIR__, 2)` from manage/includes/head-libs.php = public_html/. */
$_publicRoot = dirname(__DIR__, 2);
?>
    <link rel="stylesheet" href="/css/app.css?v=<?= filemtime($_publicRoot . '/css/app.css') ?>">
    <link rel="stylesheet" href="/css/admin.css?v=<?= filemtime($_publicRoot . '/css/admin.css') ?>">
<?php
/* Accessibility modes — MUST load on admin too (#1643).
   ELI5: admin pages were telling the browser "this user wants high contrast"
   and then never shipping the stylesheet that knows what high contrast means.
   Detail: admin-theme-init.php stamps data-ihymns-contrast / data-ihymns-cvd on
   <html> for admin pages exactly as the public site does, but accessibility.css
   — the ONLY file with rules for those attributes — was linked solely from the
   public shell (index.php). So the two accessibility-specific modes were dead
   across all of /manage and the editor, silently: light/dark still worked,
   because that is Bootstrap's own data-bs-theme handled by admin.css, which is
   why nobody noticed. Same class as #955 — attribute plumbing landed, the
   stylesheet did not follow. Loaded AFTER admin.css so its !important overrides
   win over the admin palette. */
?>
    <link rel="stylesheet" href="/css/accessibility.css?v=<?= filemtime($_publicRoot . '/css/accessibility.css') ?>">
<?php
/* External-link provider auto-detect (#841) — single source of truth
   for URL → tblExternalLinkTypes.Slug mapping, exposed on
   window.iHymnsLinkDetect. Loaded on every admin page so each
   page's external-links row builder can call attachAutoDetect()
   without an extra <script> tag of its own. Eager (no `defer`)
   so the global is ready before any inline page script that
   constructs rows on initial page load. */
$_extLinkDetectPath = $_publicRoot . '/js/modules/external-link-detect.js';
$_extLinkDetectVer  = is_file($_extLinkDetectPath) ? (string)filemtime($_extLinkDetectPath) : '1';
$_extLinkEditorPath = $_publicRoot . '/js/modules/external-links-editor.js';
$_extLinkEditorVer  = is_file($_extLinkEditorPath) ? (string)filemtime($_extLinkEditorPath) : '1';
/* #1594 part 2 — shared combobox keyboard + ARIA helper (window.iHymnsComboboxA11y).
   Loaded globally, eager, same pattern as external-link-detect.js above, so every
   admin typeahead/popover (works.php add-song, renderEntityPicker.js, editor.js's
   tag + credit-person popovers, …) can call it without its own extra <script> tag.
   The two pages with a bespoke <head> that skip head-libs.php entirely
   (manage/editor/index.php, which loads editor.js as a classic non-module script)
   load this file explicitly instead, right alongside their own place-search.js load. */
$_comboboxA11yPath = $_publicRoot . '/js/modules/combobox-a11y.js';
$_comboboxA11yVer  = is_file($_comboboxA11yPath) ? (string)filemtime($_comboboxA11yPath) : '1';
?>
    <script src="/js/modules/external-link-detect.js?v=<?= htmlspecialchars($_extLinkDetectVer, ENT_QUOTES) ?>"></script>
    <script src="/js/modules/external-links-editor.js?v=<?= htmlspecialchars($_extLinkEditorVer, ENT_QUOTES) ?>"></script>
    <script src="/js/modules/combobox-a11y.js?v=<?= htmlspecialchars($_comboboxA11yVer, ENT_QUOTES) ?>"></script>
<?php
/* #1599 — global client-side error monitor, on EVERY admin page.
 *
 * ELI5: this is the admin area's smoke detector. If any JavaScript on a
 * /manage/* page throws and nobody catches it, this notices and quietly
 * posts a small, privacy-scrubbed report to the server, so the failure
 * turns up in /manage/activity-log instead of dying in a console nobody
 * had open.
 *
 * DETAIL: `js/modules/error-monitor.js` (#1582) was booted from ONE place
 * — `js/app.js`, the public SPA — so every admin page had no crash
 * surfacing whatsoever. That gap is the hazard this project's own
 * red-flag list already records (added while fixing #1587: "there is no
 * client error monitor under /manage/*, so the admin just sees a blank
 * pane"), and the admin area is where a silent JS failure costs most: a
 * curator loses work, or believes a save succeeded. It is loaded HERE,
 * in the single shared <head> partial, and never per page — modularity
 * rule, exactly as the theme resolver / combobox helper above are.
 *
 * Load-bearing details if you touch this block:
 *
 *  - `type="module"` is mandatory, not stylistic. error-monitor.js is an
 *    ES module (it uses `export`), so loading it with a classic
 *    `<script src>` would be an immediate SyntaxError and the monitor
 *    would never install — the module/classic distinction is why this
 *    one script is shaped differently from the three above.
 *    https://developer.mozilla.org/docs/Web/HTML/Element/script#module
 *    This mirrors the pattern admin-footer.php already uses for
 *    bulk-import-progress.js and reading-progress.js.
 *  - The specifier is ROOT-absolute. A relative one resolves against the
 *    DOCUMENT, not this partial, so it would break the moment an admin
 *    page sits at another depth — and the site's .htaccess catch-all
 *    answers an unmatched path with 200 + the HTML shell, so the failure
 *    would surface as a baffling MIME-type refusal rather than a clean
 *    404 (the #1566 class, in CLAUDE.md's red-flag list).
 *  - Module scripts are DEFERRED by definition, so the listeners install
 *    once the document has been parsed. Errors thrown by the blocking
 *    classic scripts above, or by a classic inline <script> earlier in
 *    the page, are therefore NOT captured; everything that runs on user
 *    interaction — which is nearly all admin JS — is. Position within
 *    <head> does not change that, so this sits with the other loads.
 *  - No CSP is involved. Only public_html/index.php sends one (the
 *    enforcing nonce CSP, #117); /manage/* sends none, and the module
 *    depends on no nonce being present. CLAUDE.md rule #30 is about SPA
 *    fragments served by api.php — this is a real <head>, not a fragment.
 *  - The module degrades gracefully with no public shell around it, so
 *    nothing else has to be added to admin pages: the toast step is
 *    guarded on `window.iHymnsApp?.showToast` (undefined here, so no
 *    toast host is needed and the beacon still sends), and
 *    `window.iHymnsConfig` is optional — its absence only makes the
 *    payload's `v`/`ch` labels fall back to null/'production'. The
 *    AUTHORITATIVE environment label is the server's own
 *    `ihymns_environment()`, written to tblActivityLog.Environment and
 *    folded into the dedupe fingerprint (includes/client_error_report.php),
 *    so an admin-side report is still attributed to the right deployment.
 *  - The beacon endpoint needs no auth and no CSRF token: sendBeacon()
 *    cannot carry custom headers, so api.php allow-lists
 *    `client_error_report` for its Origin/Referer same-origin check.
 *    Nothing extra is required to make it work from /manage/*.
 *
 * KNOWN GAP: manage/editor/{index,editor2,import2}.php each build a
 * bespoke <head> and so skip this partial entirely — they still have no
 * error monitor. tests/test-error-monitor.js holds them in a count-exact,
 * self-cleaning allowlist so the gap stays visible until closed.
 *
 * @see appWeb/public_html/js/modules/error-monitor.js   the module itself
 * @see appWeb/public_html/includes/client_error_report.php  server-side validate/scrub
 * @see appWeb/public_html/js/app.js                     the public-site boot this mirrors
 */
$_errorMonitorPath = $_publicRoot . '/js/modules/error-monitor.js';
$_errorMonitorVer  = is_file($_errorMonitorPath) ? (string)filemtime($_errorMonitorPath) : '1';
?>
    <script type="module">
        import { bootErrorMonitor } from '/js/modules/error-monitor.js?v=<?= htmlspecialchars($_errorMonitorVer, ENT_QUOTES) ?>';
        bootErrorMonitor();
    </script>
