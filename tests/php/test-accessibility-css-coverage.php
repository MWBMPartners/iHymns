<?php

declare(strict_types=1);

/**
 * iHymns — Accessibility-mode stylesheet coverage guard (#1643)
 *
 * ELI5: if a page tells the browser "this user wants high contrast", it also
 * has to send the stylesheet that knows what high contrast looks like. This
 * checks that those two things never drift apart again.
 *
 * Detail: the accessibility modes are a THREE-part contract, and every part
 * lives in a different file:
 *
 *   1. `js/modules/settings.js` writes the user's choice to localStorage.
 *   2. A theme-init script reads it and stamps `data-ihymns-contrast` /
 *      `data-ihymns-cvd` on `<html>` before any CSS loads.
 *   3. `css/accessibility.css` is the ONLY file with rules keyed on those
 *      attributes.
 *
 * Parts 1 and 2 shipped for the admin surface in #955. Part 3 did not: the
 * `<link>` existed solely in the public shell (`index.php`), so for every page
 * under `/manage` — and the editor, which has its own bespoke `<head>` — the
 * attributes were stamped onto a document that carried none of the CSS
 * responding to them. High contrast and all four CVD modes were silently dead
 * across the whole admin surface for the users who had deliberately chosen
 * them.
 *
 * Nothing errored. Light/dark still worked, because that is Bootstrap's own
 * `data-bs-theme` handled by `admin.css` — so a sighted developer toggling the
 * theme saw it working. This is the project's recurring signature (#1565,
 * #1581, #1654): the code LOOKS complete, and the failure is the absence of an
 * effect rather than the presence of an error.
 *
 * This is also the handoff's "two lists that must agree with nothing enforcing
 * it" pattern — the set of documents that stamp the attributes, and the set
 * that load the stylesheet. Nothing tied them together, so they diverged and
 * stayed diverged. This guard IS the missing tie.
 *
 * WHAT IT ASSERTS
 *   (1) Every document shell that loads `admin.css` or is the public shell also
 *       links `accessibility.css`. Derived from the tree, not hardcoded, so a
 *       NEW admin shell is caught the day it is added.
 *   (2) `accessibility.css` never regrows a `.songbook-badge-<ABBR>` suffix
 *       selector. Those matched nothing: the only emission site renders
 *       `<span class="badge songbook-badge" data-songbook="MP">`, so the CVD
 *       songbook cue was dead on arrival. CSS fails silently — an unmatched
 *       selector is not an error — which is why it survived. The correct form
 *       is the `[data-songbook="…"]` attribute selector.
 *   (3) `accessibility.css` keeps rules for the admin components that dominate
 *       `/manage` — tables, form controls, alerts and the sidebar. Linking the
 *       file without these produces a visibly HALF-applied mode, which is
 *       arguably worse than one that plainly does nothing.
 *
 * No DB, no network — a source-tree scan that slots into the CI lint step,
 * same shape as test-fragment-inline-scripts.php and test-component-json-guard.php.
 *
 * Usage: php tests/php/test-accessibility-css-coverage.php
 * Exit 0 = pass, 1 = fail.
 */

/**
 * Does this source ACTUALLY require head-libs.php, as opposed to merely
 * mentioning it?
 *
 * A bare str_contains() is wrong here, and wrong in a way that hides exactly the
 * bug this guard exists to find. `manage/editor/editor2.php` contains the words
 * "head-libs.php" only inside a comment explaining that it does NOT use
 * head-libs.php — so a substring test reads that disclaimer as compliance and
 * passes the one file that most needs failing. (test-fragment-inline-scripts.php
 * documents the same trap for `<script>` in comments; it is worth stating twice.)
 *
 * Matching a real require/include statement instead is both stricter and honest
 * about what it is checking: the presence of the CALL, not of the NAME.
 */
function _acssRequiresHeadLibs(string $src): bool
{
    return preg_match('~\b(require|include)(_once)?\b[^;]{0,200}head-libs\.php~i', $src) === 1;
}

$root   = dirname(__DIR__, 2);
$public = $root . '/appWeb/public_html';
$cssRel = 'css/accessibility.css';
$cssAbs = $public . '/' . $cssRel;

$failures = [];

/* ---------------------------------------------------------------------------
 * Assertion 0 — the stylesheet itself exists. Everything below is meaningless
 * if it was renamed or removed; fail loudly rather than vacuously passing.
 * ------------------------------------------------------------------------- */
if (!is_file($cssAbs)) {
    fwrite(STDERR, "FAIL: {$cssRel} not found — the accessibility modes have no stylesheet at all.\n");
    exit(1);
}
$css = (string) file_get_contents($cssAbs);

/* Strip CSS comments BEFORE any selector matching. LOAD-BEARING, not tidiness —
   the doc-comment above the songbook-badge rules NAMES the dead selectors it
   replaced (`.songbook-badge-CP` and friends) so a future reader understands
   what was wrong. Matching raw source would flag that prose as a violation and
   make the guard fail on the very fix it is meant to protect. Newlines are
   preserved so any future line-numbered diagnostic stays truthful.
   (test-fragment-inline-scripts.php learned this the same way.) */
$cssNoComments = preg_replace_callback(
    '~/\*.*?\*/~s',
    static fn(array $m): string => str_repeat("\n", substr_count($m[0], "\n")),
    $css
) ?? $css;

/* ---------------------------------------------------------------------------
 * Assertion 1 — every document shell links the stylesheet.
 *
 * "Document shell" is DERIVED, not a hardcoded list: any .php that emits a
 * <link> to admin.css is an admin shell, plus the public index.php. Deriving it
 * is the whole point — a hardcoded list would have the same drift problem this
 * guard exists to catch, and a new admin shell would be missed exactly the way
 * the editor's bespoke <head> was.
 * ------------------------------------------------------------------------- */
$shells = [];

$rii = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($public, FilesystemIterator::SKIP_DOTS)
);
foreach ($rii as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') { continue; }
    $path = $file->getPathname();

    /* Skip the service worker template: it PRECACHES the stylesheet by URL
       rather than linking it into a document, so the <link> assertion does not
       apply. It is checked separately in assertion 1b. */
    if (str_ends_with($path, 'service-worker.js.php')) { continue; }

    $src = (string) file_get_contents($path);

    /* A document SHELL emits its own <head>. A page fragment or an include does
       not, and must not be judged for missing a <link> it has no business
       carrying. */
    if (stripos($src, '<head') === false) { continue; }

    /* …and is a THEMED shell if it pulls the admin stylesheet in either of the
       two ways this tree does it: linking it directly, or delegating to
       head-libs.php. Both count — the question this guard asks is whether the
       RENDERED document ends up with accessibility.css, not how it got there. */
    $linksAdminCss = preg_match('~<link[^>]+href=["\']?/css/admin\.css~i', $src) === 1;
    $usesHeadLibs  = _acssRequiresHeadLibs($src);

    if ($linksAdminCss || $usesHeadLibs) {
        $shells[] = $path;
    }
}

/* The public shell is a shell too, and it is where the stylesheet was already
   correctly linked — include it so a regression there is caught as well. */
$publicShell = $public . '/index.php';
if (is_file($publicShell)) { $shells[] = $publicShell; }

$shells = array_values(array_unique($shells));

if (count($shells) < 2) {
    $failures[] = sprintf(
        'only %d document shell(s) discovered — the detection heuristic (a <link> to /css/admin.css) '
        . 'has probably stopped matching, which would make this whole guard vacuous',
        count($shells)
    );
}

foreach ($shells as $shell) {
    $src = (string) file_get_contents($shell);
    $rel = ltrim(str_replace($root, '', $shell), '/');

    /* Two legitimate ways to satisfy the contract:
         (a) link accessibility.css directly — what the public shell and the two
             bespoke editor heads do; or
         (b) require head-libs.php, which links it on the page's behalf — what
             every ordinary /manage page does.
       Accepting (b) is what makes this guard describe the RENDERED document
       rather than one spelling of the source. Demanding (a) everywhere would
       report ~8 false positives and push someone to "fix" them by duplicating a
       <link> that head-libs.php already emits. */
    $linksDirectly = preg_match('~<link[^>]+href=["\']?/css/accessibility\.css~i', $src) === 1;
    $viaHeadLibs   = _acssRequiresHeadLibs($src);

    if (!$linksDirectly && !$viaHeadLibs) {
        $failures[] = sprintf(
            '%s renders its own <head> for a themed document but neither links /css/accessibility.css '
            . 'nor includes head-libs.php — high-contrast and CVD modes are silently dead on every '
            . 'page it serves',
            $rel
        );
    }
}

/* Assertion 1b — the service worker must still precache it, or an offline
   visit loses the accessibility modes even though the online page has them. */
$swPath = $public . '/service-worker.js.php';
if (is_file($swPath)) {
    $sw = (string) file_get_contents($swPath);
    if (!str_contains($sw, '/css/accessibility.css')) {
        $failures[] = 'service-worker.js.php no longer precaches /css/accessibility.css '
            . '— the modes would work online and silently die offline';
    }
}

/* ---------------------------------------------------------------------------
 * Assertion 2 — no dead `.songbook-badge-<ABBR>` suffix selectors.
 * ------------------------------------------------------------------------- */
if (preg_match_all('~\.songbook-badge-[A-Za-z0-9_]+~', $cssNoComments, $m) > 0) {
    $failures[] = sprintf(
        'accessibility.css contains dead suffix selector(s) %s — nothing emits that class form. '
        . 'The badge renders as `class="badge songbook-badge" data-songbook="<abbr>"`, so the rule '
        . 'must be written `.songbook-badge[data-songbook="<abbr>"]`',
        implode(', ', array_unique($m[0]))
    );
}

/* The generic fallback must survive: it is what gives a songbook OUTSIDE the
   named set any non-colour cue at all, and the catalogue keeps growing (#1223). */
if (preg_match('~\[data-ihymns-cvd\]\s+\.songbook-badge\s*\{~', $cssNoComments) !== 1) {
    $failures[] = 'the generic `[data-ihymns-cvd] .songbook-badge` fallback rule is gone — '
        . 'songbooks outside the named set would be distinguished by colour alone, which is '
        . 'the WCAG 1.4.1 failure the named patterns exist to avoid';
}

/* ---------------------------------------------------------------------------
 * Assertion 3 — admin-surface coverage.
 *
 * A survey of manage/*.php at the time of writing: tables in 49 files,
 * .form-control in 36, .alert in 41, and the left navigation is
 * .admin-sidebar (NOT .navbar, which the shared public rules already cover).
 * Losing any of these turns "High contrast" into a half-painted page.
 * ------------------------------------------------------------------------- */
$requiredAdminSelectors = [
    '.admin-sidebar' => 'the entire left navigation (admin.css owns it; it is not a .navbar)',
    '.table'         => 'admin data tables — Bootstrap paints these from --bs-table-*, which the '
                        . ':root variable overrides do NOT reach',
    '.form-control'  => 'admin forms — admin.css paints these from --surface-elevated',
    '.alert'         => 'admin alerts',
];

foreach ($requiredAdminSelectors as $selector => $why) {
    $needle = '[data-ihymns-contrast="high"] ' . $selector;
    if (!str_contains($cssNoComments, $needle)) {
        $failures[] = sprintf(
            'accessibility.css has no high-contrast rule for `%s` (%s) — the mode would apply '
            . 'to only part of each admin page',
            $selector,
            $why
        );
    }
}

/* ------------------------------------------------------------------------- */
if ($failures) {
    fwrite(STDERR, "FAIL: accessibility-mode stylesheet coverage (#1643):\n\n");
    foreach ($failures as $f) { fwrite(STDERR, "  - {$f}\n"); }
    fwrite(STDERR, "\nThe accessibility modes are a three-part contract: settings.js writes the\n");
    fwrite(STDERR, "preference, a theme-init script stamps data-ihymns-contrast / data-ihymns-cvd on\n");
    fwrite(STDERR, "<html>, and css/accessibility.css is the ONLY file with rules for those\n");
    fwrite(STDERR, "attributes. Break any part and the mode dies SILENTLY — no error, and light/dark\n");
    fwrite(STDERR, "keeps working because that is Bootstrap's own data-bs-theme.\n");
    exit(1);
}

printf(
    "PASS: accessibility.css linked from all %d document shell(s), precached by the service worker, "
    . "no dead badge selectors, admin surface covered.\n",
    count($shells)
);
exit(0);
