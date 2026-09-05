<?php

declare(strict_types=1);

/**
 * iHymns — Public-site cold-start theme-flash guard
 * ==================================================
 *
 * ELI5: when someone opens iHymns for the first time in a while (a "cold
 * start"), the page used to always paint LIGHT for a moment, no matter what
 * theme they had actually chosen — dark, high contrast, or a colour-blind
 * friendly palette. Then, once the app's JavaScript finished loading a
 * moment later, it would suddenly switch to their real choice. That switch
 * is the "flash" this fix removes: the reader's real choice is now applied
 * to the page BEFORE any stylesheet loads, exactly the way the admin side
 * (`/manage/*`) has done since #955.
 *
 * WHY THIS SHAPE (rule #35 — two files that must agree need a mechanism,
 * not a comment): this fix is a hand-off between two files with nothing in
 * the language enforcing that they agree —
 *
 *   1. `index.php` no longer hardcodes `data-bs-theme="light"` /
 *      `data-ihymns-theme="light"` on `<html>` (that was the bug).
 *   2. `index.php` requires the SAME theme-init script the admin side
 *      already uses (`manage/includes/admin-theme-init.php`) — no second,
 *      forked copy of the theme-resolution logic.
 *   3. Because the public site sends a strict nonce-based Content-Security-
 *      Policy (#117 — `script-src 'self' 'nonce-…'`, no `'unsafe-inline'`)
 *      that silently refuses any inline `<script>` without a matching
 *      nonce (the CLAUDE.md rule #30 failure shape — the page still looks
 *      fine, the fix just never runs), the hand-off MUST carry that
 *      request's nonce through to the partial, and the partial must
 *      actually use it.
 *   4. The include must happen BEFORE the first stylesheet `<link>` is
 *      emitted — that ordering is the entire point of the fix; moving it
 *      below the CSS would silently reopen the flash.
 *   5. The storage keys the partial reads (`THEME_KEY` / `CVD_KEY` /
 *      `LINKCUE_KEY`) must still be the exact strings `js/modules/
 *      settings.js` writes (`STORAGE_THEME` / `STORAGE_CVD_MODE` /
 *      `STORAGE_LINK_EMPHASIS`) — this is the same lockstep
 *      `tests/php/test-link-emphasis-mode.php` already proves for the
 *      link-emphasis key; this guard extends it to the theme + CVD keys,
 *      which until now had nothing checking them at all.
 *
 * Mutation-proven (rule #34): every assertion below was checked to go RED
 * by reverting the corresponding piece of the fix in turn (re-adding the
 * hardcoded `<html>` attributes, deleting the `require` line, moving the
 * include after the stylesheet block, dropping the nonce hand-off, and
 * changing one of the three storage-key strings) and confirming this file
 * failed, then restoring it.
 *
 * Usage: php tests/php/test-public-theme-init-flash.php
 * Exit 0 = all pass, 1 = at least one failure.
 */

$root   = dirname(__DIR__, 2);
$public = $root . '/appWeb/public_html';

$failures = 0;
$passed   = 0;
function ptif(bool $cond, string $label): void
{
    global $failures, $passed;
    if ($cond) { $passed++; echo "PASS: $label\n"; }
    else       { $failures++; fwrite(STDERR, "FAIL: $label\n"); }
}

/* ---- Files under test ---------------------------------------------------- */
$indexPath       = $public . '/index.php';
$adminInitPath   = $public . '/manage/includes/admin-theme-init.php';
$constantsPath   = $public . '/js/constants.js';

foreach ([
    'index.php'                             => $indexPath,
    'manage/includes/admin-theme-init.php'  => $adminInitPath,
    'js/constants.js'                       => $constantsPath,
] as $rel => $path) {
    ptif(is_readable($path), "0. {$rel} exists and is readable");
}
if ($failures > 0) {
    echo "\n{$passed} passed, {$failures} failed\n";
    exit(1);
}

$indexRaw     = (string) file_get_contents($indexPath);
$adminInitRaw = (string) file_get_contents($adminInitPath);
$constantsRaw = (string) file_get_contents($constantsPath);

/* ---------------------------------------------------------------------------
 * 1. The opening <html> tag no longer hardcodes a theme.
 *
 * Scoped to the tag itself (not a blanket "does this string appear
 * anywhere in the file" search) so a legitimate mention elsewhere — a
 * doc-comment, or admin-theme-init.php's own JS setting the attribute at
 * runtime — can never make this assertion pass or fail for the wrong
 * reason.
 * ------------------------------------------------------------------------- */
$htmlTag = null;
if (preg_match('/<html\b[^>]*>/i', $indexRaw, $m)) {
    $htmlTag = $m[0];
}
ptif($htmlTag !== null, '1.1 index.php has an <html ...> opening tag');
ptif($htmlTag !== null && stripos($htmlTag, 'data-bs-theme') === false,
    '1.2 the <html> tag no longer hardcodes data-bs-theme (was ="light")');
ptif($htmlTag !== null && stripos($htmlTag, 'data-ihymns-theme') === false,
    '1.3 the <html> tag no longer hardcodes data-ihymns-theme (was ="light")');

/* ---------------------------------------------------------------------------
 * 2. index.php hands its own CSP nonce to admin-theme-init.php, and the
 *    require happens in one traceable statement (not "the variable
 *    happens to be in scope somewhere upstream").
 * ------------------------------------------------------------------------- */
$noncePos = strpos($indexRaw, '$cspNonce = base64_encode(random_bytes(16));');
ptif($noncePos !== false, '2.1 index.php still generates $cspNonce (the per-request CSP nonce)');

$handoffPattern = '/\$ihymnsThemeInitNonce\s*=\s*\$cspNonce\s*;\s*'
    . 'require\s+__DIR__\s*\.\s*DIRECTORY_SEPARATOR\s*\.\s*\'manage\'\s*\.\s*DIRECTORY_SEPARATOR\s*\.\s*'
    . '\'includes\'\s*\.\s*DIRECTORY_SEPARATOR\s*\.\s*\'admin-theme-init\.php\'\s*;/';
$handoffMatched = (bool) preg_match($handoffPattern, $indexRaw, $hm, PREG_OFFSET_CAPTURE);
ptif($handoffMatched,
    '2.2 index.php sets $ihymnsThemeInitNonce = $cspNonce immediately before requiring '
    . 'manage/includes/admin-theme-init.php');

$requirePos = $handoffMatched ? $hm[0][1] : false;
ptif($noncePos !== false && $requirePos !== false && $noncePos < $requirePos,
    '2.3 $cspNonce is generated before it is handed to admin-theme-init.php');

/* ---------------------------------------------------------------------------
 * 3. The include happens BEFORE the first stylesheet <link> — otherwise the
 *    theme is still resolved too late to prevent the flash, even though
 *    every other assertion here would still pass.
 * ------------------------------------------------------------------------- */
$firstStylesheetPos = strpos($indexRaw, '<link rel="stylesheet"');
ptif($firstStylesheetPos !== false, '3.1 index.php has at least one <link rel="stylesheet"> (sanity check)');
ptif($requirePos !== false && $firstStylesheetPos !== false && $requirePos < $firstStylesheetPos,
    '3.2 the admin-theme-init.php include appears before the first stylesheet <link> in <head>');

/* ---------------------------------------------------------------------------
 * 4. admin-theme-init.php actually supports the nonce it is being handed —
 *    a hand-off into a partial that ignores it would leave the <script>
 *    tag with no nonce, which the enforcing CSP then silently refuses
 *    (rule #30's failure shape: the page looks fine, the fix never runs).
 * ------------------------------------------------------------------------- */
ptif((bool) preg_match('/isset\(\s*\$ihymnsThemeInitNonce\s*\)/', $adminInitRaw),
    '4.1 admin-theme-init.php checks for an incoming $ihymnsThemeInitNonce');
ptif((bool) preg_match('/<script\s*<\?=\s*\$ihymnsThemeInitNonceAttr\s*\?>\s*>/', $adminInitRaw),
    '4.2 the <script> tag itself emits the built nonce attribute (not a bare, static <script>)');
/* The nonce value is only ever base64_encode(random_bytes(...)) server-side
   (never attacker input), but the partial should still not trust its
   caller blindly — htmlspecialchars() before it lands in an HTML
   attribute is the defensive-by-default posture the rest of this codebase
   uses everywhere else a PHP variable reaches markup. */
ptif((bool) preg_match('/htmlspecialchars\(\s*\$ihymnsThemeInitNonce\s*,/', $adminInitRaw),
    '4.3 the nonce is escaped with htmlspecialchars() before being placed in the attribute');

/* ---------------------------------------------------------------------------
 * 5. Storage-key lockstep with js/modules/settings.js.
 *
 * test-link-emphasis-mode.php already proves this for LINKCUE_KEY /
 * STORAGE_LINK_EMPHASIS. This extends the same mechanism to THEME_KEY and
 * CVD_KEY, which had nothing checking them before this fix touched the
 * file they live in.
 * ------------------------------------------------------------------------- */
function ptifExtractSingleQuoted(string $src, string $pattern): ?string
{
    return preg_match($pattern, $src, $m) ? $m[1] : null;
}

$themeKeyAdmin = ptifExtractSingleQuoted($adminInitRaw, "/THEME_KEY\\s*=\\s*'([^']+)'/");
$cvdKeyAdmin   = ptifExtractSingleQuoted($adminInitRaw, "/CVD_KEY\\s*=\\s*'([^']+)'/");

$storageTheme = ptifExtractSingleQuoted($constantsRaw, "/export\\s+const\\s+STORAGE_THEME\\s*=\\s*'([^']+)'/");
$storageCvd   = ptifExtractSingleQuoted($constantsRaw, "/export\\s+const\\s+STORAGE_CVD_MODE\\s*=\\s*'([^']+)'/");

ptif($themeKeyAdmin !== null, '5.1 admin-theme-init.php declares THEME_KEY');
ptif($storageTheme !== null, '5.2 constants.js exports STORAGE_THEME');
ptif($themeKeyAdmin !== null && $storageTheme !== null && $themeKeyAdmin === $storageTheme,
    '5.3 THEME_KEY matches STORAGE_THEME verbatim (admin=' . var_export($themeKeyAdmin, true)
    . ', constants.js=' . var_export($storageTheme, true) . ')');

ptif($cvdKeyAdmin !== null, '5.4 admin-theme-init.php declares CVD_KEY');
ptif($storageCvd !== null, '5.5 constants.js exports STORAGE_CVD_MODE');
ptif($cvdKeyAdmin !== null && $storageCvd !== null && $cvdKeyAdmin === $storageCvd,
    '5.6 CVD_KEY matches STORAGE_CVD_MODE verbatim (admin=' . var_export($cvdKeyAdmin, true)
    . ', constants.js=' . var_export($storageCvd, true) . ')');

/* ------------------------------------------------------------------------ */
echo "\n{$passed} passed, {$failures} failed\n";
exit($failures > 0 ? 1 : 0);
