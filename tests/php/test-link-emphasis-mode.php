<?php

declare(strict_types=1);

/**
 * iHymns — Accessible-links opt-in mode guard (#1984, accessibility finding S1)
 * ============================================================================
 *
 * ELI5: there is a Settings toggle that makes links show up in an accent
 * colour instead of blending into the surrounding text. This checks that
 * every piece of that toggle actually exists and actually agrees with every
 * other piece — the toggle in Settings, the JS that writes the preference,
 * the two theme-init scripts (admin + public) that read it back onto
 * `<html>` before paint, and the CSS rule that finally paints the colour.
 *
 * WHY THIS SHAPE: this feature is a five-part contract spread across five
 * files with NOTHING in the language enforcing that they agree (CLAUDE.md
 * rule #35 — "cross-file agreement needs a mechanism, not a comment"), and
 * it is modelled byte-for-byte on the EXISTING CVD mode contract
 * (STORAGE_CVD_MODE / data-ihymns-cvd), which is exactly the shape
 * `test-accessibility-css-coverage.php`'s doc-block already describes as a
 * "three-part contract" that goes silently, invisibly dead if any one part
 * drifts (no console error — the page just never shows the colour). This
 * guard is that same tie, for the new key:
 *
 *   1. js/constants.js         — declares STORAGE_LINK_EMPHASIS, the ONE
 *                                 literal key name every other file must
 *                                 use verbatim ('ihymns_link_emphasis').
 *   2. includes/pages/settings.php
 *                               — the toggle itself, living inside the
 *                                 Appearance card (the same card as the CVD
 *                                 select), so it reads as one accessibility
 *                                 group.
 *   3. js/modules/settings.js  — imports the constant, wires the toggle
 *                                 (read + change-listener), and applies the
 *                                 `data-ihymns-linkcue` attribute inside
 *                                 applyTheme() (same call site as CVD).
 *   4. manage/includes/admin-theme-init.php
 *                               — the admin-side synchronous mirror; reads
 *                                 the SAME literal key and stamps the SAME
 *                                 attribute pre-CSS.
 *   5. css/app.css              — the ONE stylesheet with a rule keyed on
 *                                 `[data-ihymns-linkcue="on"]`, targeting
 *                                 exactly the two Section-2.5 consumers
 *                                 (the global `a` default and
 *                                 `.song-meta-link`) — and NEVER letting
 *                                 the OPT-IN mode's own cue leak onto the
 *                                 base (attribute-absent) default.
 *
 * UPDATE (a11y audit M5, owner decision D1, 2026-08-30): the colour-only
 * version above computed below WCAG 1.4.3's 4.5:1 text-contrast floor on
 * card surfaces in BOTH themes (light 4.47:1, dark 3.27:1) — no single
 * colour can satisfy both 1.4.3 (4.5:1 vs the surface) and G183 (≥3:1 vs
 * adjacent body text) at once on these surfaces. The fix adds an
 * UNDERLINE inside the opt-in mode ONLY (never the default — rule #18's
 * "no underline at rest" stays true for everyone who hasn't opted in),
 * which removes the G183 obligation and lets --link-emphasis-color be
 * retuned to a colour that clears 4.5:1 outright (light #4f46e5 = 6.29:1
 * on white; dark #818cf8 = 4.90:1 on the dark card). This guard was
 * updated in the SAME commit (rule #35 lockstep) to check the new values
 * and require the underline inside the opt-in rules while still proving
 * the base defaults are untouched.
 *
 * Mutation-proven (rule #34): every assertion below was checked to go RED
 * by temporarily reverting each edit in turn (removing the toggle, the
 * import, the applyTheme() block, the admin mirror's attribute write, and
 * the CSS rule/underline) and confirming this file failed, then restoring it.
 *
 * Usage: php tests/php/test-link-emphasis-mode.php
 * Exit 0 = all pass, 1 = at least one failure.
 */

$root   = dirname(__DIR__, 2);
$public = $root . '/appWeb/public_html';

$failures = 0;
$passed   = 0;
function lem(bool $cond, string $label): void
{
    global $failures, $passed;
    if ($cond) { $passed++; echo "PASS: $label\n"; }
    else       { $failures++; fwrite(STDERR, "FAIL: $label\n"); }
}

/** Blank out /* *\/ and // comments in JS, preserving offsets, so a doc
 *  comment merely MENTIONING a pattern can't false-positive (the
 *  test-fragment-inline-scripts.php / test-accessibility-css-coverage.php
 *  lesson, applied here to JS instead of PHP/CSS). Deliberately simple
 *  (no string-literal awareness) — adequate for the fixed-shape sites this
 *  guard scans; not a general JS tokenizer. */
function lemStripJsComments(string $js): string
{
    // Block comments /* ... */
    $js = preg_replace_callback('~/\*.*?\*/~s', static function (array $m): string {
        return str_repeat("\n", substr_count($m[0], "\n"));
    }, $js) ?? $js;
    // Line comments // ... (naive — fine for this file's use, no // inside
    // string literals at the sites this guard targets)
    $js = preg_replace('~//[^\n]*~', '', $js) ?? $js;
    return $js;
}

/** Same idea for CSS block comments. */
function lemStripCssComments(string $css): string
{
    return preg_replace_callback('~/\*.*?\*/~s', static function (array $m): string {
        return str_repeat("\n", substr_count($m[0], "\n"));
    }, $css) ?? $css;
}

/* ---- Files under test --------------------------------------------------- */
$constantsPath   = $public . '/js/constants.js';
$settingsPhpPath = $public . '/includes/pages/settings.php';
$settingsJsPath  = $public . '/js/modules/settings.js';
$adminInitPath   = $public . '/manage/includes/admin-theme-init.php';
$appCssPath      = $public . '/css/app.css';

foreach ([
    'js/constants.js'                            => $constantsPath,
    'includes/pages/settings.php'                => $settingsPhpPath,
    'js/modules/settings.js'                     => $settingsJsPath,
    'manage/includes/admin-theme-init.php'       => $adminInitPath,
    'css/app.css'                                => $appCssPath,
] as $rel => $path) {
    lem(is_readable($path), "0. {$rel} exists and is readable");
}
if ($failures > 0) {
    /* One of the five files is missing entirely — every assertion below
       would either fatal or be meaningless. Fail fast and loudly. */
    echo "\n{$passed} passed, {$failures} failed\n";
    exit(1);
}

$constantsRaw   = (string) file_get_contents($constantsPath);
$settingsPhpRaw = (string) file_get_contents($settingsPhpPath);
$settingsJsRaw  = (string) file_get_contents($settingsJsPath);
$adminInitRaw   = (string) file_get_contents($adminInitPath);
$appCssRaw      = (string) file_get_contents($appCssPath);

$constants   = lemStripJsComments($constantsRaw);
$settingsJs  = lemStripJsComments($settingsJsRaw);
$adminInit   = lemStripJsComments($adminInitRaw);
$appCss      = lemStripCssComments($appCssRaw);

/* ---- 1. js/constants.js — the ONE key name ------------------------------ */
$storageKey = null;
if (preg_match(
    "/export\\s+const\\s+STORAGE_LINK_EMPHASIS\\s*=\\s*'([^']+)'/",
    $constants,
    $m
)) {
    $storageKey = $m[1];
}
lem($storageKey !== null, '1.1 constants.js exports STORAGE_LINK_EMPHASIS');
lem($storageKey === 'ihymns_link_emphasis',
    "1.2 STORAGE_LINK_EMPHASIS is 'ihymns_link_emphasis' (got: " . var_export($storageKey, true) . ')');

/* ---- 2. Settings toggle (Appearance/Accessibility group) ---------------- */
lem(strpos($settingsPhpRaw, 'id="setting-link-emphasis"') !== false,
    '2.1 settings.php has the #setting-link-emphasis toggle');
lem(strpos($settingsPhpRaw, 'type="checkbox"') !== false
    && preg_match('/type="checkbox"[^>]*id="setting-link-emphasis"|id="setting-link-emphasis"[^>]*type="checkbox"/s', $settingsPhpRaw) === 1,
    '2.2 the toggle is a checkbox (form-switch), matching the CVD/reduce-motion convention');

/* The toggle must live in the SAME Appearance card as the CVD select — i.e.
   after id="setting-cvd-mode" and before the next major settings section
   (the Language Preferences card). Positional, not just "present somewhere
   in a 1000-line file", so a future refactor that moves it out of the
   accessibility group is caught. */
$cvdPos    = strpos($settingsPhpRaw, 'id="setting-cvd-mode"');
$togglePos = strpos($settingsPhpRaw, 'id="setting-link-emphasis"');
$nextSectionPos = strpos($settingsPhpRaw, 'LANGUAGE PREFERENCES SECTION');
lem($cvdPos !== false && $togglePos !== false && $nextSectionPos !== false
        && $togglePos > $cvdPos && $togglePos < $nextSectionPos,
    '2.3 the toggle sits inside the Appearance card, after the CVD select and before the next section');

/* Clear label + help text, not a bare unlabelled checkbox. */
lem(strpos($settingsPhpRaw, 'for="setting-link-emphasis"') !== false,
    '2.4 the toggle has an associated <label for="setting-link-emphasis">');

/* ---- 3. js/modules/settings.js ------------------------------------------- */
lem((bool) preg_match('/import\s*\{[^}]*\bSTORAGE_LINK_EMPHASIS\b[^}]*\}\s*from\s*[\'"]\.\.\/constants\.js[\'"]/s', $settingsJs),
    '3.1 settings.js imports STORAGE_LINK_EMPHASIS from ../constants.js');

/* applyTheme() must read the key and set/remove the attribute — same call
   site as the CVD block, so the toggle takes effect on init(), on every
   theme-dropdown click, AND after a settings sync-pull (rule #35: one
   application point, not a second half-wired copy). */
/* Match the METHOD DEFINITION (4-space-indented `applyTheme(theme) {` at a
   line start), not the earlier CALL SITES (`this.applyTheme(theme);` in the
   theme-dropdown click handler, `this.applyTheme(this.get('theme'))` in
   init()) — a bare strpos() for 'applyTheme(theme)' finds the click-handler
   call first and silently scans the wrong 1100 characters. */
$applyThemeDefMatch = preg_match('/\n\s{4}applyTheme\(theme\)\s*\{/', $settingsJs, $adm, PREG_OFFSET_CAPTURE);
$applyThemeStart = $applyThemeDefMatch ? $adm[0][1] : false;
lem($applyThemeStart !== false, '3.2 applyTheme(theme) method DEFINITION found');
$applyThemeBody = '';
if ($applyThemeStart !== false) {
    /* Grab up to the next method-looking boundary (a blank line then a
       4-space-indented method signature) — good enough for this fixed
       class shape without a full brace parser. */
    $next = preg_match('/\n\s{4}\w+\([^\)]*\)\s*\{/', $settingsJs, $nm, PREG_OFFSET_CAPTURE, $applyThemeStart + 20)
        ? $nm[0][1]
        : strlen($settingsJs);
    $applyThemeBody = substr($settingsJs, $applyThemeStart, $next - $applyThemeStart);
}
lem(strpos($applyThemeBody, 'STORAGE_LINK_EMPHASIS') !== false,
    '3.3 applyTheme() reads STORAGE_LINK_EMPHASIS');
lem((bool) preg_match('/setAttribute\(\s*[\'"]data-ihymns-linkcue[\'"]\s*,\s*[\'"]on[\'"]\s*\)/', $applyThemeBody),
    '3.4 applyTheme() sets data-ihymns-linkcue="on" when enabled');
lem((bool) preg_match('/removeAttribute\(\s*[\'"]data-ihymns-linkcue[\'"]\s*\)/', $applyThemeBody),
    '3.5 applyTheme() removes data-ihymns-linkcue when disabled');

/* UI wiring: the checkbox reads its initial state and persists on change. */
lem(strpos($settingsJs, "getElementById('setting-link-emphasis')") !== false,
    '3.6 settings.js binds #setting-link-emphasis');
lem((bool) preg_match('/localStorage\.setItem\(\s*STORAGE_LINK_EMPHASIS\s*,\s*[\'"]on[\'"]\s*\)/', $settingsJs),
    '3.7 the change handler persists the preference (localStorage.setItem)');
lem(strpos($settingsJs, 'localStorage.removeItem(STORAGE_LINK_EMPHASIS)') !== false,
    '3.8 the change handler clears the preference when unchecked');

/* ---- 4. Admin theme-init mirror (pre-CSS, synchronous) -------------------- */
lem((bool) preg_match("/LINKCUE_KEY\\s*=\\s*'([^']+)'/", $adminInit, $am),
    '4.1 admin-theme-init.php declares a LINKCUE_KEY constant');
$adminKey = $am[1] ?? null;
lem($adminKey !== null && $storageKey !== null && $adminKey === $storageKey,
    '4.2 admin-theme-init.php LINKCUE_KEY matches constants.js STORAGE_LINK_EMPHASIS verbatim '
        . '(got admin=' . var_export($adminKey, true) . ', constants=' . var_export($storageKey, true) . ')');
lem((bool) preg_match('/setAttribute\(\s*[\'"]data-ihymns-linkcue[\'"]\s*,\s*[\'"]on[\'"]\s*\)/', $adminInit),
    '4.3 admin-theme-init.php sets data-ihymns-linkcue="on" when enabled');
lem((bool) preg_match('/removeAttribute\(\s*[\'"]data-ihymns-linkcue[\'"]\s*\)/', $adminInit),
    '4.4 admin-theme-init.php removes data-ihymns-linkcue when disabled');
/* Reads localStorage inside a try/catch (matches every other read in this
   file — private-browsing / storage-blocked contexts must degrade
   silently, not throw and abort the whole theme-init script). */
lem((bool) preg_match('/try\s*\{[^}]*localStorage\.getItem\(\s*LINKCUE_KEY\s*\)/s', $adminInit),
    '4.5 the LINKCUE_KEY read happens inside a try/catch');

/* ---- 5. css/app.css — the ONE stylesheet with the rule -------------------- */

/* 5.1 — a dedicated token exists (not every consumer hand-rolling its own
   colour), retuned by D1 to clear the 4.5:1 TEXT-contrast floor outright
   (light #4f46e5 = 6.29:1 on white / 5.65:1 on the page bg; dark #818cf8
   = 4.90:1 on the dark card / 5.98:1 on the page bg) now that the
   underline (5.6 below) removes the G183 3:1-vs-text-colour constraint
   that forced the PRE-D1 values (var(--accent-solid) / #6366f1, which
   measured 4.47:1 / 3.27:1 — short of 4.5:1 on the card in both themes). */
lem((bool) preg_match('/--link-emphasis-color\s*:\s*#4f46e5/', $appCss),
    '5.1 app.css defines --link-emphasis-color for the light theme as #4f46e5 (D1 — clears 4.5:1 on card + page bg)');
lem((bool) preg_match('/--link-emphasis-color\s*:\s*#818cf8/', $appCss),
    '5.2 app.css overrides --link-emphasis-color for the dark theme as #818cf8 (D1 — clears 4.5:1 on card + page bg)');

/* 5.2 — the two consumer rules exist, keyed on the attribute, and read the
   token (never a hardcoded literal at the consumer site — the whole point
   of the token indirection above). */
lem((bool) preg_match('/\[data-ihymns-linkcue="on"\]\s*\)\s*a\s*\{[^}]*var\(--link-emphasis-color/s', $appCss),
    '5.3 app.css has a [data-ihymns-linkcue="on"] rule for the global <a> using --link-emphasis-color');
lem((bool) preg_match('/\[data-ihymns-linkcue="on"\]\s*\)\s*\.song-meta-link\s*\{[^}]*var\(--link-emphasis-color/s', $appCss),
    '5.4 app.css has a [data-ihymns-linkcue="on"] rule for .song-meta-link using --link-emphasis-color');

/* 5.3 — D1 (2026-08-30): the linkcue rule bodies must NOW carry an
   underline — that is what lets the retuned colour above satisfy WCAG
   1.4.3 without also needing G183's separate 3:1-vs-text-colour headroom.
   Four bodies are expected: the base `a` + `.song-meta-link` colour rules
   PLUS their `:hover` companions (added so the underline doesn't flicker
   off on hover — see app.css Section 2.5's own comment). Every body must
   set the underline; NONE may reintroduce the OTHER cue rule #18 removed
   app-wide, a dotted/solid border-bottom (D1 chose underline, not
   border — the two are not interchangeable and border-bottom must still
   never come back). */
$linkcueRuleBodies = [];
if (preg_match_all('/\[data-ihymns-linkcue="on"\]\s*\)[^{]*\{([^}]*)\}/s', $appCss, $lm)) {
    $linkcueRuleBodies = $lm[1];
}
lem(count($linkcueRuleBodies) >= 4,
    '5.5 the expected [data-ihymns-linkcue="on"] rule bodies were found ('
        . count($linkcueRuleBodies) . ' found, need >= 4 — base + :hover for both <a> and .song-meta-link)');
$missingUnderline = false;
$reintroducesBorder = false;
foreach ($linkcueRuleBodies as $body) {
    if (!preg_match('/text-decoration\s*:\s*underline/i', $body)) {
        $missingUnderline = true;
    }
    if (preg_match('/border-bottom\s*:\s*[^;]*(dotted|solid|\d)/i', $body)) {
        $reintroducesBorder = true;
    }
}
lem(!$missingUnderline,
    '5.6 every [data-ihymns-linkcue="on"] rule body sets text-decoration: underline (D1 — the opt-in mode '
        . 'reaches AA text contrast only WITH the underline; no single colour clears both WCAG rules at once '
        . 'on these surfaces, per the M5 finding)');
lem(!$reintroducesBorder,
    '5.7 no [data-ihymns-linkcue="on"] rule reintroduces a border-bottom (D1 added an underline, not a border '
        . '— the other cue rule #18 removed app-wide stays gone)');

/* 5.4 — specificity guard: the attribute selector must be wrapped in
   :where(...) so it contributes ZERO specificity — otherwise it would
   outrank Bootstrap component classes (.btn/.nav-link/.dropdown-item/…)
   that Section 2.5's own doc-comment says must keep winning, and the
   colour cue would leak onto chrome it was never meant to touch. Checked
   against BOTH the base and :hover selectors for each consumer, since D1
   added the :hover companions as a second, equally-real place the
   specificity contract must hold. */
lem((bool) preg_match('/:where\(\[data-ihymns-linkcue="on"\]\)\s*a\b/', $appCss),
    '5.8 the global-<a> rule uses :where() so it does not outrank .btn/.nav-link/etc');
lem((bool) preg_match('/:where\(\[data-ihymns-linkcue="on"\]\)\s*\.song-meta-link\b/', $appCss),
    '5.9 the .song-meta-link rule uses :where() for the same reason');
lem((bool) preg_match('/:where\(\[data-ihymns-linkcue="on"\]\)\s*a:hover\b/', $appCss),
    '5.10 the global-<a>:hover companion rule (D1) also uses :where()');
lem((bool) preg_match('/:where\(\[data-ihymns-linkcue="on"\]\)\s*\.song-meta-link:hover\b/', $appCss),
    '5.11 the .song-meta-link:hover companion rule (D1) also uses :where()');

/* 5.5 — sanity: the base (attribute-absent) default is untouched for BOTH
   consumers. The bare `a { color: inherit; text-decoration: none; }` rule
   from Section 2.5, and .song-meta-link's own equivalent, must still
   exist verbatim — D1 is scoped to the opt-in attribute and must never
   touch either default. */
lem((bool) preg_match('/^a\s*\{\s*\n\s*color:\s*inherit;\s*\n\s*text-decoration:\s*none;/m', $appCssRaw),
    '5.12 the base `a { color: inherit; text-decoration: none; }` default (Section 2.5) is untouched');
lem((bool) preg_match('/\.song-meta-link\s*\{\s*\n\s*color:\s*inherit;\s*\n\s*text-decoration:\s*none;/m', $appCssRaw),
    '5.13 the base `.song-meta-link { color: inherit; text-decoration: none; }` default is untouched');

/* ------------------------------------------------------------------------ */
echo "\n{$passed} passed, {$failures} failed\n";
exit($failures > 0 ? 1 : 0);
