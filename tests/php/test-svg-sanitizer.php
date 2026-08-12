<?php

declare(strict_types=1);

/**
 * iHymns — Hardened SVG sanitiser functional truth table (#1830)
 *
 * ELI5: a big table of "give it this nasty SVG, expect it stripped or
 * rejected" checks for `includes/svg_sanitizer.php` — the one bouncer that
 * decides what an uploaded organisation-logo SVG is allowed to keep.
 * Modelled on `test-html-sanitizer.php`'s functional-truth-table shape
 * (rule #34): real bytes through the REAL function, exact expected
 * outcomes — never a mock, never an eyeballed "looks fine".
 *
 * DERIVATION: every vector below is named explicitly in
 * `.claude/org-logos-1830-plan.md` §9.1 ("Booby-trapped inputs come back
 * stripped" / "Rejects" / "Survivors" bullets), so this file is a direct
 * transcription of that spec, not a guess at what might matter.
 *
 * MUTATION-PROVEN (rule #34): after this file's commit, each mutation below
 * was actually applied (via a scripted patch, never by hand) to a working
 * copy of `includes/svg_sanitizer.php`, this file re-run to confirm the
 * exact expected assertions — and ONLY those — went red, then the file was
 * restored byte-for-byte before the next mutation. Recorded here, with the
 * REAL observed result (not the expected one), so a reviewer doesn't have
 * to re-derive it — two of these runs surfaced genuine redundancy between
 * layers that the first draft of this comment claimed incorrectly, exactly
 * the "a guard's first green run was never challenged" trap rule #34 warns
 * about, caught here by actually running the mutation instead of assuming:
 *
 *   - ADDED `foreignObject` to `IHYMNS_SVG_ALLOWED_ELEMENTS` -> RED: "<foreignObject>
 *     is dropped whole" (element + its nested <iframe> text both survived).
 *     Restored -> green. Proves the element allow-list is load-bearing.
 *   - Widened `ihymnsSvgUrlRefValid()` to accept ANY `url(...)`, not just
 *     `url(#ID)` -> RED: "fill=\"url(http://evil)\" attribute is dropped".
 *     Restored -> green. Proves the same-document-only carve-out is
 *     load-bearing (this one function backs BOTH the direct-attribute paint
 *     check and the `style=` declaration check, so one mutation covers both
 *     call sites — rule #35's "one validator, not a fork" made concrete).
 *   - Commenting out ONLY the `on*`-prefix ban, or ONLY the `href`-by-name
 *     ban, in `ihymnsSvgCopyAttributes()` — BOTH runs stayed FULLY GREEN.
 *     Root cause, confirmed by inspection: neither `on*`-shaped nor
 *     `href`-shaped names ever appear as a key in
 *     `ihymnsSvgAttrPatterns()`/the paint-property list, so the SEPARATE
 *     default-deny fallthrough in `ihymnsSvgValidateAttrValue()` (its final
 *     `return null`) already drops them even with the named ban disabled —
 *     genuine, verified redundancy, not a dead assertion. To actually prove
 *     each named ban is a REAL, INDEPENDENTLY-effective layer (not dead
 *     code), it was mutated TOGETHER with disabling that fallthrough
 *     (`return $value;` instead of `return null;`) — i.e. "assume the other
 *     layer is also compromised":
 *       - default-deny fallthrough + `on*` ban both disabled -> RED: all
 *         three "[svg|path|g] onload/onclick/onerror all stripped" checks.
 *       - default-deny fallthrough + `href`-by-name ban both disabled ->
 *         RED: "bare href on an ALLOW-LISTED <linearGradient> is stripped".
 *     Each combination restored -> green.
 *   - Disabled ONLY the pre-parse `<!DOCTYPE`/`<!ENTITY` byte reject
 *     (leaving the post-parse `$doc->doctype !== null` check in place) ->
 *     STAYED green — confirms the byte pre-check is genuine belt-and-braces,
 *     not the only line of defence. Disabling BOTH the pre-parse reject AND
 *     the post-parse `doctype !== null` check together -> RED: the
 *     XXE-DOCTYPE "rejected outright" case (loadXML tolerates the DTD, and
 *     with neither check the reject never fires). Restored both -> green.
 *
 * The exact patch script used for each run is reproducible from this file's
 * PR description / commit message; the takeaway worth keeping is
 * methodological: "we ADDED an unconditional ban, so it must be provable
 * solo" is not always true when a second, independent layer (default-deny)
 * already covers the same case — the mutation-proof step exists precisely
 * to catch that gap before trusting the comment.
 *
 *   php tests/php/test-svg-sanitizer.php
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
require_once $repoRoot . '/appWeb/public_html/includes/svg_sanitizer.php';

$SVG_OPEN = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">';

/* =============================================================================
 * 0. Sanity — the module's own shape.
 * ============================================================================= */

check('IHYMNS_SVG_SANITISER_VERSION is defined and is an int >= 1',
    defined('IHYMNS_SVG_SANITISER_VERSION') && is_int(IHYMNS_SVG_SANITISER_VERSION) && IHYMNS_SVG_SANITISER_VERSION >= 1);

check('ihymnsSanitizeSvg("") returns null (empty input rejected)',
    ihymnsSanitizeSvg('') === null);

check('ihymnsSanitizeSvg() over the byte cap returns null',
    ihymnsSanitizeSvg($SVG_OPEN . str_repeat('a', IHYMNS_SVG_SANITISER_MAX_BYTES) . '</svg>') === null);

/* =============================================================================
 * 1. BOOBY-TRAPPED INPUTS COME BACK STRIPPED (survive parsing, output cleaned).
 * ============================================================================= */

/* 1a. <script> child — dropped whole (not unwrapped to text, unlike html_sanitizer.php). */
$out = ihymnsSanitizeSvg($SVG_OPEN . '<script>alert(document.cookie)</script><rect width="1" height="1"/></svg>');
check('<script> element never survives', $out !== null && !str_contains(strtolower($out['bytes']), '<script'));
check('<script>\'s JS source text is GONE too (dropped whole, not unwrapped)', $out !== null && !str_contains($out['bytes'], 'alert(document.cookie)'));
check('sibling <rect> survives the drop', $out !== null && str_contains($out['bytes'], '<rect'));

/* 1b. onload/onclick/onerror on svg/path/g — stripped, element survives. */
foreach (['svg', 'path', 'g'] as $tag) {
    $body = $tag === 'svg'
        ? '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)" onclick="alert(2)" onerror="alert(3)"><rect width="1" height="1"/></svg>'
        : $SVG_OPEN . "<{$tag} onload=\"alert(1)\" onclick=\"alert(2)\" onerror=\"alert(3)\"><rect width=\"1\" height=\"1\"/></{$tag}></svg>";
    $out = ihymnsSanitizeSvg($body);
    check("[$tag] onload/onclick/onerror all stripped", $out !== null && !str_contains(strtolower($out['bytes']), 'on'));
    check("[$tag] the element itself survives the attribute strip", $out !== null && str_contains($out['bytes'], "<{$tag}"));
}

/* 1c. <foreignObject> with a nested <iframe> — dropped whole. */
$out = ihymnsSanitizeSvg($SVG_OPEN . '<foreignObject><iframe src="http://evil.test"></iframe></foreignObject><rect width="1" height="1"/></svg>');
check('<foreignObject> is dropped whole', $out !== null && !str_contains(strtolower($out['bytes']), 'foreignobject'));
check('the nested <iframe> is gone too (dropped with its parent)', $out !== null && !str_contains(strtolower($out['bytes']), 'iframe'));
check('sibling <rect> survives', $out !== null && str_contains($out['bytes'], '<rect'));

/* 1d. <use xlink:href="#x"> (same-doc, benign shape) and <use href="https://evil"> —
       <use> itself is NOT in the element allow-list, so BOTH are dropped whole
       regardless of the href's shape (§3.3 excludes `use` entirely). */
$out = ihymnsSanitizeSvg($SVG_OPEN . '<use xlink:href="#x"/><use href="https://evil.test/x.svg#y"/><rect width="1" height="1"/></svg>');
check('<use xlink:href="#x"> is dropped (use is not allow-listed at all)', $out !== null && !str_contains(strtolower($out['bytes']), '<use'));
check('<use href="https://evil…"> is dropped too', $out !== null && !str_contains($out['bytes'], 'evil.test'));
check('no "href" text survives anywhere in the output', $out !== null && !str_contains(strtolower($out['bytes']), 'href'));

/* 1d-2. `href` on an ALLOW-LISTED element — `linearGradient` legitimately
   carries `xlink:href`/(SVG2) bare `href` in real SVG (to inherit stops from
   another gradient), so this is the one case that actually exercises the
   ATTRIBUTE-level href ban rather than being pre-empted by the element-level
   drop above (1d used `<use>`, which is dropped before its attributes are
   ever inspected). A BARE (non-namespaced) `href` is used deliberately: its
   `namespaceURI` is null, so it is NOT also caught by the separate
   "any namespaced attribute is banned" rule — this isolates the
   href-by-NAME ban as its own, independently-provable layer. */
$out = ihymnsSanitizeSvg($SVG_OPEN . '<defs><linearGradient id="g2" href="https://evil.test/x.svg#g1"><stop offset="0" stop-color="red"/></linearGradient></defs><rect fill="url(#g2)" width="1" height="1"/></svg>');
check('bare href on an ALLOW-LISTED <linearGradient> is stripped (attribute-level ban, not element drop, not the namespace rule)',
    $out !== null && str_contains($out['bytes'], '<linearGradient') && !str_contains(strtolower($out['bytes']), 'href') && !str_contains($out['bytes'], 'evil.test'));

/* 1e. <image href="http://evil/p.png"> — image not allow-listed, dropped whole. */
$out = ihymnsSanitizeSvg($SVG_OPEN . '<image href="http://evil.test/p.png" width="10" height="10"/><rect width="1" height="1"/></svg>');
check('<image> is dropped whole', $out !== null && !str_contains(strtolower($out['bytes']), '<image'));
check('the external URL text is gone', $out !== null && !str_contains($out['bytes'], 'evil.test'));

/* 1f. <a href="javascript:alert(1)"> — a is not allow-listed, dropped whole (children too). */
$out = ihymnsSanitizeSvg($SVG_OPEN . '<a href="javascript:alert(1)"><rect width="1" height="1"/></a></svg>');
check('<a href="javascript:…"> is dropped whole, including its <rect> child', $out !== null && trim($out['bytes']) === '<svg xmlns="http://www.w3.org/2000/svg"/>');

/* 1g. <animate attributeName="href" to="javascript:…"> — SMIL not allow-listed. */
$out = ihymnsSanitizeSvg($SVG_OPEN . '<rect width="1" height="1"><animate attributeName="href" to="javascript:alert(1)"/></rect></svg>');
check('<animate> (SMIL) is dropped whole', $out !== null && !str_contains(strtolower($out['bytes']), 'animate'));
check('the javascript: payload text is gone', $out !== null && !str_contains($out['bytes'], 'javascript:'));

/* 1h. style="fill:url(http://evil)" — banned url(), not the #ID carve-out. Uses
   `fill` (a real SVG paint property) rather than a non-SVG property like
   `background`, so the assertion exercises the VALUE check, not just the
   (separate) property-name allow-list. */
$out = ihymnsSanitizeSvg($SVG_OPEN . '<rect width="1" height="1" style="stroke:black;fill:url(http://evil.test/x.png)"/></svg>');
check('style="…fill:url(http://evil)…" — the url(...) declaration is stripped', $out !== null && !str_contains($out['bytes'], 'evil.test') && !str_contains($out['bytes'], 'url('));
check('the OTHER, safe declaration in the same style attr survives', $out !== null && str_contains($out['bytes'], 'stroke:black'));

/* 1i. fill="url(http://evil)" as a direct attribute — same ban, not the #ID carve-out. */
$out = ihymnsSanitizeSvg($SVG_OPEN . '<rect width="1" height="1" fill="url(http://evil.test/x.png)"/></svg>');
check('fill="url(http://evil)" attribute is dropped', $out !== null && !str_contains($out['bytes'], 'evil.test') && !str_contains($out['bytes'], 'fill='));

/* 1j. A `\XX ` backslash-escape smuggle attempt in style — banned by ihymnsCssValueSafe(). */
$out = ihymnsSanitizeSvg($SVG_OPEN . '<rect width="1" height="1" style="fill:\\75 rl(http://evil.test)"/></svg>');
check('a backslash-escape smuggle in style="…" is stripped (no backslash survives)', $out !== null && !str_contains($out['bytes'], '\\'));

/* 1k. <style> BLOCK element — style is not in the element allow-list at all. */
$out = ihymnsSanitizeSvg($SVG_OPEN . '<style>.a{fill:red}</style><rect class="a" width="1" height="1"/></svg>');
check('<style> element is dropped whole', $out !== null && !str_contains(strtolower($out['bytes']), '<style'));
check('its CSS text content is gone too', $out !== null && !str_contains($out['bytes'], 'fill:red'));
check('sibling <rect> (minus the now-meaningless class) survives', $out !== null && str_contains($out['bytes'], '<rect'));

/* =============================================================================
 * 2. REJECTS — the whole document is refused (null), not partially cleaned.
 * ============================================================================= */

check('<!DOCTYPE svg [<!ENTITY x "y">]> (XXE shape) is REJECTED outright',
    ihymnsSanitizeSvg('<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY x "y">]><svg xmlns="http://www.w3.org/2000/svg"><text>&x;</text></svg>') === null);

check('a non-SVG root (<html>) is REJECTED',
    ihymnsSanitizeSvg('<html xmlns="http://www.w3.org/1999/xhtml"><body>hi</body></html>') === null);

check('an <svg> root with NO namespace declared is REJECTED (strict namespace check)',
    ihymnsSanitizeSvg('<svg><rect width="1" height="1"/></svg>') === null);

check('truncated / malformed XML is REJECTED',
    ihymnsSanitizeSvg('<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1"') === null);

check('over-budget node count (> 10,000 elements) is REJECTED',
    ihymnsSanitizeSvg($SVG_OPEN . str_repeat('<rect width="1" height="1"/>', IHYMNS_SVG_SANITISER_MAX_NODES + 50) . '</svg>') === null);

/* Over-budget DEPTH (> 64 levels) — build a deeply nested <g> chain. */
$deepOpen  = str_repeat('<g>', IHYMNS_SVG_SANITISER_MAX_DEPTH + 10);
$deepClose = str_repeat('</g>', IHYMNS_SVG_SANITISER_MAX_DEPTH + 10);
check('over-budget nesting depth (> 64 levels) is REJECTED',
    ihymnsSanitizeSvg($SVG_OPEN . $deepOpen . '<rect width="1" height="1"/>' . $deepClose . '</svg>') === null);

check('oversize bytes (> the sanitiser\'s own byte cap) is REJECTED',
    ihymnsSanitizeSvg($SVG_OPEN . str_repeat('a', IHYMNS_SVG_SANITISER_MAX_BYTES + 1) . '</svg>') === null);

/* =============================================================================
 * 3. SURVIVORS — a realistic logo round-trips with its legitimate content intact.
 * ============================================================================= */

$gradientLogo = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 50">
  <defs>
    <linearGradient id="g1" x1="0" y1="0" x2="1" y2="1" gradientUnits="objectBoundingBox">
      <stop offset="0" stop-color="#ff0000"/>
      <stop offset="1" stop-color="#0000ff"/>
    </linearGradient>
  </defs>
  <g transform="translate(2,3) scale(1.2)">
    <path d="M0 0 L50 0 L25 40 Z" fill="url(#g1)" stroke="#333333" stroke-width="1.5"/>
    <text x="10" y="45" font-family="Arial, sans-serif" font-size="8" fill="currentColor">Church Name</text>
  </g>
</svg>';
$out = ihymnsSanitizeSvg($gradientLogo);
check('a realistic gradient logo is NOT rejected', $out !== null);
if ($out !== null) {
    check('  — the gradient definition survives (linearGradient + stop-color)', str_contains($out['bytes'], 'linearGradient') && str_contains($out['bytes'], 'stop-color'));
    check('  — the same-document url(#g1) fill reference survives', str_contains($out['bytes'], 'fill="url(#g1)"'));
    check('  — the transform survives', str_contains($out['bytes'], 'transform="translate(2,3) scale(1.2)"'));
    check('  — the path "d" data survives', str_contains($out['bytes'], 'M0 0 L50 0 L25 40 Z'));
    check('  — the text label survives', str_contains($out['bytes'], 'Church Name'));
    check('  — dimensions extracted from viewBox (100x50)', $out['width'] === 100 && $out['height'] === 50);
}

/* A minimal, valid PNG-alternative-shaped logo: plain shapes, no gradient. */
$plainLogo = '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64"><circle cx="32" cy="32" r="30" fill="#4a90d9" stroke="black" stroke-width="2"/></svg>';
$out = ihymnsSanitizeSvg($plainLogo);
check('a minimal plain-shape logo survives intact', $out !== null && str_contains($out['bytes'], '<circle') && str_contains($out['bytes'], 'fill="#4a90d9"'));
check('  — dimensions extracted from width/height when no viewBox', $out !== null && $out['width'] === 64 && $out['height'] === 64);

if ($failures) {
    echo "\nFAIL: {$failures} SVG-sanitiser truth-table check(s) failed.\n";
    exit(1);
}
echo "\nOK: SVG sanitiser blocks every booby-trapped vector, rejects every malformed/over-budget shape, and a realistic logo survives with its legitimate content intact.\n";
exit(0);
