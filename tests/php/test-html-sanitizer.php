<?php

declare(strict_types=1);

/**
 * iHymns — Shared HTML/CSS sanitiser functional truth table (#1767 remainder P2)
 *
 * ELI5: a big table of "give it this nasty input, expect this safe output"
 * checks for `includes/html_sanitizer.php` — the one bouncer that decides
 * what markup is allowed to reach a printed page or a PDF. Modelled on
 * `test-ia-reconcile-guards.php`'s SSRF truth table (rule #34): real inputs
 * through the REAL function, exact expected outputs — never a mock, never
 * an eyeballed "looks fine".
 *
 * DERIVATION: every vector below is named explicitly in the plan's §5.5
 * ("the functional truth table"), so this file is a direct transcription
 * of that spec, not a guess at what might matter:
 * `.claude/print-templates-1767-remainder-plan.md` §5.5.
 *
 * MUTATION-PROVEN (rule #34): after this file's commit, each assertion
 * GROUP below was broken on purpose in the working tree (a one-line change
 * to `includes/html_sanitizer.php` that defeats exactly that defence),
 * re-run to confirm the expected assertions — and ONLY those — went red,
 * then reverted via `git checkout -- includes/html_sanitizer.php` before
 * moving to the next mutation. The specific mutation + the exact failing
 * assertions for each are recorded in the commit body / session report —
 * this file itself does not re-perform the mutations at test time.
 *
 *   php tests/php/test-html-sanitizer.php
 *
 * Exit status 0 = all pass, 1 = at least one failure.
 */

$failures = 0;
function check(string $label, bool $cond): void
{
    global $failures;
    echo ($cond ? '  PASS  ' : '  FAIL  ') . $label . "\n";
    if (!$cond) {
        $failures++;
    }
}

$repoRoot = dirname(__DIR__, 2);
$sanitizerFile = $repoRoot . '/appWeb/public_html/includes/html_sanitizer.php';
require_once $sanitizerFile;

/* =============================================================================
 * 0. Sanity — the module's own shape.
 * ============================================================================= */

check('IHYMNS_HTML_SANITISER_VERSION is defined and is an int >= 1',
    defined('IHYMNS_HTML_SANITISER_VERSION') && is_int(IHYMNS_HTML_SANITISER_VERSION) && IHYMNS_HTML_SANITISER_VERSION >= 1);

check('ihymnsSanitizeHtml("", "print") returns empty string (no DOM overhead on empty input)',
    ihymnsSanitizeHtml('', 'print') === '');

$threwOnUnknownProfile = false;
try {
    ihymnsSanitizeHtml('<p>x</p>', 'not-a-real-profile');
} catch (\InvalidArgumentException $e) {
    $threwOnUnknownProfile = true;
}
check('ihymnsSanitizeHtml() throws InvalidArgumentException on an unknown profile (fail LOUD, not open)', $threwOnUnknownProfile);

/* =============================================================================
 * 1. BLOCKS <script> — unwrapped to inert text, never survives as an element.
 * ============================================================================= */

foreach (['print', 'layout'] as $profile) {
    $out = ihymnsSanitizeHtml('<div class="print-title">before</div><script>alert(document.cookie)</script><div class="print-title">after</div>', $profile);
    check("[$profile] <script> element never survives in the output", !str_contains(strtolower($out), '<script'));
    check("[$profile] <script>'s own JS source text survives only as INERT TEXT (proves unwrap-not-drop, not a lucky no-op)",
        str_contains($out, 'alert(document.cookie)'));
    check("[$profile] sibling content around the <script> is preserved", str_contains($out, 'before') && str_contains($out, 'after'));
}

/* =============================================================================
 * 2. BLOCKS event-handler attributes (onerror, onload, …) on every profile.
 * ============================================================================= */

foreach (['print', 'layout'] as $profile) {
    $out = ihymnsSanitizeHtml('<img src="/qr.php?data=x" onerror="alert(1)" onload="alert(2)" alt="q">', $profile);
    check("[$profile] onerror= is stripped from <img>", !str_contains(strtolower($out), 'onerror'));
    check("[$profile] onload= is stripped from <img>", !str_contains(strtolower($out), 'onload'));
    check("[$profile] the img itself (with its allowed src) survives the attribute strip", str_contains($out, '<img') && str_contains($out, '/qr.php?data=x'));
}

/* A realistic fixture: print.js's OWN qr block literally emits an onerror=
   handler (print.js L339, browser-print-window only) — proves the sanitiser
   strips even the renderer's OWN emitted markup, not just contrived attacks
   (§5.2 of the plan: "the endpoint must hold even if print.js never ran"). */
$qrBlockFromPrintJs = '<div class="print-qr"><img class="print-qr-img" '
    . 'src="/qr.php?data=https%3A%2F%2Fexample.test%2Fsong%2FABC123&format=svg&size=512" '
    . 'alt="QR code linking to this song online" width="128" height="128" '
    . 'style="width:128px;height:128px" onerror="this.style.display=\'none\'">'
    . '<div class="print-qr-caption">https://example.test/song/ABC123</div></div>';
$qrOut = ihymnsSanitizeHtml($qrBlockFromPrintJs, 'print');
check('print.js\'s own qr-block onerror= is stripped even though it is print.js\'s OWN output', !str_contains(strtolower($qrOut), 'onerror'));
check('print.js\'s own qr-block src (/qr.php?…) survives (it is the one allowed src shape)', str_contains($qrOut, '/qr.php?data='));
check('print.js\'s own qr-block width/height/style/alt survive', str_contains($qrOut, 'width="128"') && str_contains($qrOut, 'style="width:128px;height:128px"') && str_contains($qrOut, 'alt="QR code linking to this song online"'));

/* =============================================================================
 * 3. BLOCKS javascript:/vbscript: URLs — no <a>/href exists in either
 *    profile's allow-list, so this is proven via the one URL-bearing
 *    attribute either profile has: <img src>.
 * ============================================================================= */

foreach (['print', 'layout'] as $profile) {
    foreach (['javascript:alert(1)', 'JaVaScRiPt:alert(1)', 'vbscript:msgbox(1)'] as $scheme) {
        $out = ihymnsSanitizeHtml('<img src="' . $scheme . '" alt="x">', $profile);
        check("[$profile] <img src=\"$scheme\"> — the scheme text never survives as a src value", !str_contains($out, 'src="' . $scheme));
    }
    /* No <a>/href anywhere in either allow-list — structural, not a value check. */
    $anchorOut = ihymnsSanitizeHtml('<a href="javascript:alert(1)">click</a>', $profile);
    check("[$profile] <a>/href is not in the allow-list at all — the tag unwraps, href never appears anywhere in the output", !str_contains(strtolower($anchorOut), 'href') && str_contains($anchorOut, 'click'));
}

/* =============================================================================
 * 4. BLOCKS data:text/html (both profiles) and SVG data-URIs (layout — the
 *    only profile that accepts any data: URI at all).
 * ============================================================================= */

foreach (['print', 'layout'] as $profile) {
    $out = ihymnsSanitizeHtml('<img src="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==" alt="x">', $profile);
    check("[$profile] data:text/html src is dropped", !str_contains($out, 'data:text/html'));
}
$svgB64 = base64_encode('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
$svgOut = ihymnsSanitizeHtml('<img src="data:image/svg+xml;base64,' . $svgB64 . '" alt="x">', 'layout');
check('[layout] data:image/svg+xml (SVG data-URI) src is dropped — SVG is a script vector, not in data_uri_mimes', !str_contains($svgOut, 'data:image/svg'));
check('[layout] the <img> tag survives (just without a src) when only its src is rejected', str_contains($svgOut, '<img') && str_contains($svgOut, 'alt="x"'));

/* A legitimate small PNG data-URI must survive (positive case — proves the
   rejection above is about the MIME/shape, not "no data: URI ever works"). */
$pngB64 = base64_encode(str_repeat('P', 40)); /* not a real PNG, but the sanitiser only cares about the declared mime + decoded size */
$pngOut = ihymnsSanitizeHtml('<img src="data:image/png;base64,' . $pngB64 . '" alt="logo">', 'layout');
check('[layout] a legitimate data:image/png;base64 src (within the size cap) survives', str_contains($pngOut, 'data:image/png;base64,'));

/* Oversized data:image payload (> 200 KiB decoded) is dropped even though the mime is allowed. */
$bigB64 = base64_encode(str_repeat('X', 204801));
$bigOut = ihymnsSanitizeHtml('<img src="data:image/png;base64,' . $bigB64 . '" alt="huge">', 'layout');
check('[layout] a data:image/png src whose DECODED payload exceeds the 200 KiB cap is dropped', !str_contains($bigOut, 'data:image/png;base64,'));

/* =============================================================================
 * 5. RULE M — /song-media/<id> can never survive as an <img src> OR as a CSS
 *    url(), in either profile.
 * ============================================================================= */

foreach (['print', 'layout'] as $profile) {
    $out = ihymnsSanitizeHtml('<img src="/song-media/123" alt="gated">', $profile);
    check("[$profile] /song-media/123 as <img src> is dropped (rule M)", !str_contains($out, '/song-media/123'));

    $cssOut = ihymnsSanitizeHtml('<div style="background:url(/song-media/123)">x</div>', $profile);
    check("[$profile] /song-media/123 as CSS url() inside style= is dropped entirely (the whole declaration is rejected)", !str_contains($cssOut, 'song-media') && !str_contains($cssOut, 'url('));
    check("[$profile] the div + its text content survive even though its style was emptied", str_contains($cssOut, '<div') && str_contains($cssOut, '>x<'));
}

/* Same rule M vector, exercised directly through ihymnsSanitizeStyleAttr(). */
check('ihymnsSanitizeStyleAttr(): background:url(/song-media/123) → the declaration is dropped entirely',
    ihymnsSanitizeStyleAttr('background:url(/song-media/123)') === '');
check('ihymnsSanitizeStyleAttr(): background-image:url(/song-media/123) is ALSO dropped (url() ban is unconditional, not property-specific)',
    ihymnsSanitizeStyleAttr('color:red;background-image:url(/song-media/123);font-weight:bold') === 'color:red;font-weight:bold');

/* =============================================================================
 * 5b. #1830 — /org-logo.php?… is the print `logo` block's ONE admitted src
 *     shape, in BOTH profiles (v1 -> v2 widening). Positive: the exact shape
 *     print.js's `case 'logo'` emits survives. Negative: a lookalike path
 *     that merely STARTS the same (an attacker's own endpoint hosted at a
 *     path prefixed with "org-logo.php" but not actually matching the
 *     anchored `^/org-logo\.php\?` pattern) does NOT survive, and neither
 *     does the gated `/song-media/<id>` shape via this new pattern.
 * ============================================================================= */

foreach (['print', 'layout'] as $profile) {
    $logoOut = ihymnsSanitizeHtml(
        '<img src="/org-logo.php?org=7&kind=primary&v=abc123" alt="Church logo" onerror="alert(1)">',
        $profile
    );
    check("[$profile] /org-logo.php?… survives as an <img src> (the ONE new admitted shape, #1830)",
        str_contains($logoOut, '/org-logo.php?org=7&amp;kind=primary&amp;v=abc123')
        || str_contains($logoOut, '/org-logo.php?org=7&kind=primary&v=abc123'));
    check("[$profile] onerror= is still stripped from the logo <img> (unconditional ban unaffected by the new pattern)",
        !str_contains(strtolower($logoOut), 'onerror'));

    $lookalike = ihymnsSanitizeHtml('<img src="/org-logo.phpEVIL?x=1" alt="x">', $profile);
    check("[$profile] a lookalike '/org-logo.phpEVIL?…' path (NOT the anchored shape) is dropped",
        !str_contains($lookalike, 'org-logo.phpEVIL'));

    $gatedOut = ihymnsSanitizeHtml('<img src="/song-media/123" alt="gated">', $profile);
    check("[$profile] /song-media/123 STILL never matches the widened img_src allow-list (rule M unaffected by #1830)",
        !str_contains($gatedOut, '/song-media/123'));
}

/* =============================================================================
 * 6. BLOCKS every mPDF-proprietary tag (default-deny — none of these are in
 *    EITHER profile's allow-list).
 * ============================================================================= */

foreach (['barcode', 'pagebreak', 'indexentry', 'annotation', 'columns'] as $mpdfTag) {
    foreach (['print', 'layout'] as $profile) {
        $out = ihymnsSanitizeHtml("<{$mpdfTag} type=\"x\">payload</{$mpdfTag}>", $profile);
        check("[$profile] <{$mpdfTag}> (mPDF-proprietary) never survives as an element", !str_contains(strtolower($out), '<' . $mpdfTag));
    }
}

/* =============================================================================
 * 7. {{content}} template token — a plain-text token must survive VERBATIM;
 *    it is never special-cased, it simply is text the walker never touches.
 * ============================================================================= */

foreach (['print', 'layout'] as $profile) {
    $out = ihymnsSanitizeHtml('<div class="print-title">Header</div>{{content}}<div class="print-footer">Footer</div>', $profile);
    check("[$profile] a bare {{content}} token between two allowed elements survives verbatim", str_contains($out, '{{content}}'));
}
/* Attacker HTML surrounding the token with junk the sanitiser WILL strip —
   the token itself must still come through untouched. */
$tokenAttack = '<script>evil()</script>{{content}}<img onerror="x()" src="/song-media/1">';
$tokenOut = ihymnsSanitizeHtml($tokenAttack, 'layout');
check('{{content}} survives even sandwiched between a <script> and a gated-media <img> attack', str_contains($tokenOut, '{{content}}'));
check('…while the surrounding attack payload is still neutralised in the SAME output', !str_contains(strtolower($tokenOut), '<script') && !str_contains(strtolower($tokenOut), 'onerror') && !str_contains($tokenOut, 'song-media'));

/* =============================================================================
 * 8. Nested / malformed markup — must not throw, must return a string.
 * ============================================================================= */

$malformed = '<div class="print-title"><span><div>unclosed<img src="/qr.php?x=1"></div>';
$malformedOut = null;
try {
    $malformedOut = ihymnsSanitizeHtml($malformed, 'print');
} catch (\Throwable $e) {
    $malformedOut = null;
}
check('malformed/unclosed nested markup does not throw and returns a string', is_string($malformedOut));
check('malformed markup: the text content is still recovered', $malformedOut !== null && str_contains($malformedOut, 'unclosed'));

/* =============================================================================
 * 9. ALLOWS the legitimate print.js-shaped fragment — byte-meaningful survival.
 * ============================================================================= */

$legitPrint = '<h1 class="print-title">Amazing Grace</h1>'
    . '<div class="print-subtitle">Sample Hymnal &middot; #1</div>'
    . '<div class="print-lyrics" style="columns:2;column-gap:1.5em;text-align:left;font-size:1em">'
    . '<div class="print-component lyric-verse"><div class="print-label">Verse 1</div>'
    . '<div class="print-line">Amazing grace! how sweet the sound</div></div>'
    . '<div class="print-component lyric-chorus"><div class="print-label">Chorus</div>'
    . '<div class="print-line">Praise God, praise God</div></div>'
    . '</div>'
    . '<div class="print-footer">CCLI Song #22025</div>';
$legitOut = ihymnsSanitizeHtml($legitPrint, 'print');
check('legit fragment: <h1 class="print-title"> survives', str_contains($legitOut, '<h1 class="print-title">Amazing Grace</h1>'));
check('legit fragment: print-subtitle div + its text survive', str_contains($legitOut, 'class="print-subtitle"') && str_contains($legitOut, 'Sample Hymnal'));
check('legit fragment: the inline columns/column-gap/text-align/font-size style survives INTACT', str_contains($legitOut, 'style="columns:2;column-gap:1.5em;text-align:left;font-size:1em"'));
check('legit fragment: the two-token class "print-component lyric-verse" survives (both tokens match the print/lyric pattern)', str_contains($legitOut, 'class="print-component lyric-verse"'));
check('legit fragment: "print-component lyric-chorus" survives too', str_contains($legitOut, 'class="print-component lyric-chorus"'));
check('legit fragment: lyric text content survives', str_contains($legitOut, 'Amazing grace! how sweet the sound') && str_contains($legitOut, 'Praise God, praise God'));
check('legit fragment: print-footer + CCLI text survive', str_contains($legitOut, 'class="print-footer"') && str_contains($legitOut, 'CCLI Song #22025'));

/* A class token that does NOT match the print-/lyric- pattern is dropped,
   but a co-occurring VALID token in the same attribute survives. */
$mixedClassOut = ihymnsSanitizeHtml('<div class="print-title evil-hack">x</div>', 'print');
check('[print] class="print-title evil-hack": the invalid token "evil-hack" is dropped', !str_contains($mixedClassOut, 'evil-hack'));
check('[print] class="print-title evil-hack": the valid token "print-title" survives alongside it', str_contains($mixedClassOut, 'print-title'));

/* =============================================================================
 * 10. ALLOWS the legitimate layout-profile tag/attribute superset.
 * ============================================================================= */

$legitLayout = '<header class="layout-head"><h2>Cover</h2></header>'
    . '<table><thead><tr><th colspan="2">Title</th></tr></thead>'
    . '<tbody><tr><td>{{content}}</td><td>&nbsp;</td></tr></tbody></table>'
    . '<blockquote>Sample quote</blockquote>'
    . '<ul><li>One</li><li>Two</li></ul>'
    . '<footer dir="ltr" lang="en-GB">Footer text</footer>';
$layoutOut = ihymnsSanitizeHtml($legitLayout, 'layout');
foreach (['<header', '<h2>Cover</h2>', '<table>', '<thead>', 'colspan="2"', '<blockquote>Sample quote</blockquote>', '<ul>', '<li>One</li>', '<footer', 'dir="ltr"', 'lang="en-GB"', '{{content}}'] as $needle) {
    check('[layout] legitimate fragment retains: ' . $needle, str_contains($layoutOut, $needle));
}

/* Free class-token charset (layout) — any [A-Za-z0-9_-]+ token, not just print-/lyric-. */
$layoutClassOut = ihymnsSanitizeHtml('<div class="curator-cover-page bg-navy">x</div>', 'layout');
check('[layout] an arbitrary curator class token survives (free charset, unlike print profile)', str_contains($layoutClassOut, 'class="curator-cover-page bg-navy"'));

/* A print-profile-only class token is STILL just a token match in layout —
   layout's pattern is charset-only, not print/lyric-restricted, so it also
   passes; the point of this vector is the two profiles genuinely differ
   (proven by the two checks above/below using patterns that only ONE profile accepts). */
$printOnlyClassInLayout = ihymnsSanitizeHtml('<div class="not_a_print_class!!">x</div>', 'layout');
check('[layout] a token containing "!" is dropped (outside [A-Za-z0-9_-]+)', !str_contains($printOnlyClassInLayout, '!!'));

/* colspan/rowspan validated as positive integers only. */
check('ihymnsSanitizeCopyAttributes via HTML: colspan="2" survives on <td>', str_contains(ihymnsSanitizeHtml('<table><tr><td colspan="2">x</td></tr></table>', 'layout'), 'colspan="2"'));
check('a non-numeric colspan is dropped', !str_contains(ihymnsSanitizeHtml('<table><tr><td colspan="2;DROP">x</td></tr></table>', 'layout'), 'colspan'));

/* =============================================================================
 * 11. Comments are dropped entirely (both profiles).
 * ============================================================================= */

foreach (['print', 'layout'] as $profile) {
    $out = ihymnsSanitizeHtml('<div class="print-title">a</div><!-- [if IE]><script>evil()</script><![endif] --><div class="print-title">b</div>', $profile);
    check("[$profile] an HTML comment (incl. an IE conditional-comment shape) is dropped entirely", !str_contains($out, '<!--') && !str_contains($out, 'evil()'));
}

/* =============================================================================
 * 12. ihymnsSanitizeStyleAttr() — direct property/value allow-list checks.
 * ============================================================================= */

check('style: allowed properties all survive together', ihymnsSanitizeStyleAttr('color:red;font-weight:bold;margin-top:1em;text-align:center') === 'color:red;font-weight:bold;margin-top:1em;text-align:center');
check('style: an unknown property is dropped, known ones survive', ihymnsSanitizeStyleAttr('color:red;behavior:url(evil.htc);width:10px') === 'color:red;width:10px');
check('style: expression(...) is rejected outright (old-IE CSS-expression XSS)', ihymnsSanitizeStyleAttr('width:expression(alert(1))') === '');
check('style: expression (...) with an inserted space still rejected (whitespace-collapsed ban check)', ihymnsSanitizeStyleAttr('width:expression (alert(1))') === '');
check('style: @import is rejected as a VALUE substring too', ihymnsSanitizeStyleAttr('color:"@import evil"') === '');
check('style: a backslash CSS-escape sequence is rejected outright (defeats \\75 rl( style bypasses)', ihymnsSanitizeStyleAttr('background:\\75 rl(javascript:alert(1))') === '');
check('style: a "</" breakout attempt in a value is rejected', ihymnsSanitizeStyleAttr('content:"</style><script>x</script>"') === '');
check('style: position is restricted to static|relative|absolute — "fixed" is rejected', ihymnsSanitizeStyleAttr('position:fixed') === '');
check('style: position:absolute IS allowed (owner directive — full-page layout flexibility, no interactive-overlay risk in print/PDF)', ihymnsSanitizeStyleAttr('position:absolute') === 'position:absolute');
check('style: position:relative is allowed', ihymnsSanitizeStyleAttr('position:relative') === 'position:relative');
check('style: display is restricted to the enum — a junk value is rejected', ihymnsSanitizeStyleAttr('display:run-in-junk') === '');
check('style: display:flex is allowed', ihymnsSanitizeStyleAttr('display:flex') === 'display:flex');
check('style: filter is restricted to grayscale(...) — an arbitrary filter function is rejected', ihymnsSanitizeStyleAttr('filter:blur(5px)') === '');
check('style: filter:grayscale(1) IS allowed (the ink-saver QR use case, §4.2)', ihymnsSanitizeStyleAttr('filter:grayscale(1)') === 'filter:grayscale(1)');
check('style: filter:grayscale(100%) is allowed', ihymnsSanitizeStyleAttr('filter:grayscale(100%)') === 'filter:grayscale(100%)');
check('style: font-*, text-*, margin*, padding*, border*, max-*, min-*, break-*, page-break-*, column-* prefixes all resolve',
    ihymnsSanitizeStyleAttr('font-size:12pt;text-align:left;margin-top:1em;padding-left:2px;border-color:red;max-width:10em;min-height:2em;break-after:page;page-break-inside:avoid;column-gap:1em')
        === 'font-size:12pt;text-align:left;margin-top:1em;padding-left:2px;border-color:red;max-width:10em;min-height:2em;break-after:page;page-break-inside:avoid;column-gap:1em');
check('style: -webkit-print-color-adjust and print-color-adjust both survive (printCss() literally emits both, §4.1)',
    ihymnsSanitizeStyleAttr('-webkit-print-color-adjust:exact;print-color-adjust:exact') === '-webkit-print-color-adjust:exact;print-color-adjust:exact');
check('style: a declaration with no colon is silently dropped, not fatal', ihymnsSanitizeStyleAttr('not-a-declaration;color:red') === 'color:red');
check('style: empty input yields empty output', ihymnsSanitizeStyleAttr('') === '');
check('style: a value containing ";" INSIDE quotes is not mis-split (font-family with a quoted list-like string)',
    ihymnsSanitizeStyleAttr('font-family:"A;B";color:blue') === 'font-family:"A;B";color:blue');

/* =============================================================================
 * 13. ihymnsSanitizeCss() — whole-stylesheet checks.
 * ============================================================================= */

$printCssSample = '* { box-sizing: border-box; } @page { size: A4; } '
    . 'body { font-family: Georgia, "Times New Roman", serif; color: #000; margin: 1.5cm; } '
    . '.print-title { font-size: 18pt; font-weight: bold; } '
    . '.lyric-chorus .print-line, .lyric-refrain .print-line { font-style: italic; } '
    . '@media print { body { margin: 1.2cm; -webkit-print-color-adjust: exact; print-color-adjust: exact; } }';
$sheetOut = ihymnsSanitizeCss($printCssSample);
check('sheet: "*" universal selector rule survives', str_contains($sheetOut, '*{box-sizing:border-box}') || str_contains($sheetOut, '*{box-sizing:border-box;}'));
check('sheet: @page{size:A4} survives', str_contains($sheetOut, '@page{size:A4}'));
check('sheet: body{...} rule survives with its font-family/color/margin', str_contains($sheetOut, 'body{') && str_contains($sheetOut, 'color:#000'));
check('sheet: .print-title{...} rule survives', str_contains($sheetOut, '.print-title{'));
check('sheet: the comma+descendant selector ".lyric-chorus .print-line, .lyric-refrain .print-line" survives verbatim', str_contains($sheetOut, '.lyric-chorus .print-line, .lyric-refrain .print-line{'));
check('sheet: @media print{...} wrapper survives, nesting the body rule inside it', str_contains($sheetOut, '@media print{') && str_contains($sheetOut, 'margin:1.2cm'));

check('sheet: @font-face is banned outright (no @font-face string anywhere in output)', !str_contains(ihymnsSanitizeCss('@font-face { font-family: "Evil"; src: url(evil.woff); } .a { color: red; }'), '@font-face'));
check('sheet: @import is banned outright', !str_contains(ihymnsSanitizeCss('@import url(evil.css); .a { color: red; }'), '@import'));
check('sheet: @keyframes is banned outright', !str_contains(ihymnsSanitizeCss('@keyframes spin { from { top: 0; } to { top: 100px; } } .a { color: red; }'), '@keyframes'));
check('sheet: an attribute selector rule is dropped entirely (no attribute selectors allowed)', !str_contains(ihymnsSanitizeCss('.print-lyrics[style*="columns:2"] { color: red; }'), 'color:red'));
check('sheet: an id selector rule is dropped entirely (class/element only, no id selectors)', !str_contains(ihymnsSanitizeCss('#evil { color: red; }'), 'color:red'));
check('sheet: a pseudo-class selector rule is dropped entirely', !str_contains(ihymnsSanitizeCss('.a:hover { color: red; }'), 'color:red'));
check('sheet: a rule whose ONLY declaration is unsafe (url()) contributes nothing to the output', ihymnsSanitizeCss('.a { background: url(//evil.example/x.png); }') === '');
check('sheet: empty input yields empty output', ihymnsSanitizeCss('') === '');
check('sheet: comments are stripped before parsing (a comment hiding "url(" cannot smuggle it past the ban)', !str_contains(ihymnsSanitizeCss('.a { background: u/**/rl(evil); color: red; }'), 'url('));

/* =============================================================================
 * 14. dir / lang validation.
 * ============================================================================= */

check('dir="rtl" survives (valid enum)', str_contains(ihymnsSanitizeHtml('<div dir="rtl">x</div>', 'print'), 'dir="rtl"'));
check('dir="sideways" is dropped (not a valid enum value)', !str_contains(ihymnsSanitizeHtml('<div dir="sideways">x</div>', 'print'), 'dir='));
check('lang="zh-Hans" survives (script-subtag BCP-47 shape, rule #21 precedent — free-text tags never RESTRICT-fail)', str_contains(ihymnsSanitizeHtml('<div lang="zh-Hans">x</div>', 'print'), 'lang="zh-Hans"'));
check('lang="<script>" is dropped (not a valid BCP-47-shaped tag)', !str_contains(ihymnsSanitizeHtml('<div lang="<script>">x</div>', 'print'), 'lang='));

echo "\n";
if ($failures > 0) {
    fwrite(STDERR, "FAILED: {$failures} assertion(s) failed.\n");
    exit(1);
}
echo "All html-sanitizer truth-table assertions passed.\n";
exit(0);
