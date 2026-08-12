<?php

declare(strict_types=1);

/**
 * iHymns — Hardened SVG sanitiser (#1830)
 * ================================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * A church can upload its logo as an SVG. An SVG file is really a small
 * computer program wearing a picture's clothes — it can legally carry a
 * `<script>` tag, "when this loads, run this" handlers, and requests to
 * fetch OTHER files or web addresses. This file is the ONE gate every
 * uploaded SVG must pass through: it reads the file as a strict XML
 * document, throws away everything except a small, named list of drawing
 * shapes and colours, and hands back either a clean, safe copy or nothing
 * at all — there is no "half safe" outcome.
 *
 * DETAIL / WHY A NEW, SEPARATE, STRICTER MODULE
 * ----------------------------------------------------------------------------
 * `includes/html_sanitizer.php` (#1767 remainder P2) deliberately BLOCKS
 * `<svg>` outright, twice, with stated rationale (its
 * `IHYMNS_HTML_ALWAYS_BANNED_TAGS` list and its `img_src` doc-block both say
 * so — SVG is a script vector, `<svg><script>…</script></svg>` is valid
 * SVG). THAT posture is correct and stays untouched by this file. This
 * module is a DIFFERENT, STRICTER sanitiser for a DIFFERENT job: turning an
 * untrusted SVG UPLOAD into safe bytes to store — never for admitting SVG
 * markup into an HTML page. Its output is stored in
 * `tblOrganisationLogos.ContentSanitised` and served ONLY as an isolated
 * `<img src="/org-logo.php?…">` byte-stream (`org-logo.php`,
 * `Content-Security-Policy: default-src 'none'; sandbox`) — never inlined
 * into any page's DOM. See `.claude/org-logos-1830-plan.md` §3 for the full
 * threat table this file implements; follow it exactly — this is a
 * permanently-maintained attack surface.
 *
 * SAME REBUILD PHILOSOPHY, DIFFERENT MECHANICS: like `html_sanitizer.php`,
 * this file is default-DENY and REBUILDS a brand-new document node-by-node
 * from an allow-list — it never patches or strips substrings out of the
 * original bytes (a regex-based stripper is a well-known bypass magnet).
 * Where it differs: `html_sanitizer.php` UNWRAPS an unrecognised HTML tag
 * (keeps its sanitised children, drops only the wrapper) because that is
 * meaningful for prose; this file DROPS an unrecognised SVG element WHOLE,
 * children and all — unwrapping `<script>`'s text into an SVG rendering
 * context has no meaning, and "drop the whole thing" is the posture that
 * needs no argument. This file is also XML-namespace-aware (`loadXML`, not
 * `loadHTML`) and REJECT-NOT-DEGRADE on anything unparseable — a corrupt or
 * hostile-shaped upload gets `null`, never a best-effort partial repair.
 *
 * XXE / ENTITY SAFETY (mirrors `includes/marcxml.php`'s stated posture,
 * itself mirroring `MusicXmlImporter::parseString()`, #1096)
 * ----------------------------------------------------------------------------
 *   1. A pre-parse BYTE scan refuses any `<!DOCTYPE` or `<!ENTITY` occurring
 *      ANYWHERE in the input, case-insensitively, before libxml ever sees
 *      it — a logo SVG never legitimately carries an internal DTD subset,
 *      so refusing the SHAPE outright removes the whole XXE / "billion
 *      laughs" entity-expansion class before parsing begins.
 *   2. `libxml_set_external_entity_loader()` is nulled around the parse
 *      (and restored after) — belt-and-braces on top of (1); PHP ≥ 8.0
 *      already disables external entity loading by default
 *      (`libxml_disable_entity_loader()` is deprecated precisely because
 *      it is redundant), this makes the intent explicit and local rather
 *      than relying on a global default nobody in this file controls.
 *   3. `DOMDocument::loadXML()` is called with `LIBXML_NONET` (never fetch
 *      anything over the network) and **deliberately never** `LIBXML_NOENT`
 *      (never substitute entities) and **never** `LIBXML_PARSEHUGE` (keep
 *      libxml's own built-in depth/size guards active).
 *   4. AFTER a successful parse, `$doc->doctype !== null` is checked AGAIN
 *      — so a DTD can never survive to influence anything downstream
 *      regardless of what a future libxml/PHP default does.
 * A throwaway prototype run against `<!DOCTYPE svg [<!ENTITY xxe SYSTEM
 * "file:///etc/passwd">]>` confirms `&xxe;` survives the parse as the
 * INERT LITERAL TEXT `&xxe;` — never expanded — even without the pre-parse
 * byte reject in play; the pre-parse reject removes the DTD before libxml
 * is even asked to try, which is the stronger, cheaper guarantee.
 *
 * REJECT-NOT-REPAIR: `ihymnsSanitizeSvg()` returns `?array` — a populated
 * array on success, `null` to REJECT. There is no partial-success return
 * shape and no exception crosses this file's public boundary: empty input,
 * over the byte cap, a DOCTYPE/ENTITY sighting, unparseable XML, a
 * non-`<svg>`/non-SVG-namespace root, or a node-count/depth budget breach
 * all produce `null`. Disallowed-but-PARSEABLE content (a `<script>` child,
 * an `onload=` attribute) is STRIPPED, not a rejection — the caller
 * (`includes/org_logo_admin.php`, a later commit) decides whether "survived
 * but altered" is worth telling the uploader about.
 *
 * NOT YET WIRED TO A CONSUMER in this commit — mirrors the
 * `html_sanitizer.php` precedent ("this module + its guard land first, the
 * consumer wires it in a later commit"). `tests/php/test-svg-sanitizer.php`
 * is the mutation-proven functional truth table.
 *
 * @see .claude/org-logos-1830-plan.md §3           the full design this file implements EXACTLY
 * @see tests/php/test-svg-sanitizer.php             the mutation-proven functional truth table
 * @see appWeb/public_html/includes/html_sanitizer.php  the sibling module this one is deliberately STRICTER than
 * @see appWeb/public_html/includes/marcxml.php         the house XXE-safety posture this mirrors
 * @link https://www.php.net/manual/en/book.dom.php     DOMDocument reference
 * @link https://www.php.net/manual/en/domdocument.loadxml.php
 * @link https://www.php.net/manual/en/function.libxml-set-external-entity-loader.php
 * @link https://owasp.org/www-community/vulnerabilities/XML_External_Entity_(XXE)_Processing
 * @link https://www.w3.org/TR/SVG11/                    the SVG 1.1 spec this allow-list draws its element/attribute names from
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

/* Reused, never forked (rule #35): ihymnsCssSplitDeclarations() (the
   quote/paren-aware `;`-splitter) and ihymnsCssValueSafe() (the
   url(/expression(/@import/@charset/backslash/`</` banned-substring core)
   are html_sanitizer.php's exported helpers — this file reuses the
   TOKENIZER and the VALUE-SAFETY CORE for a `style="…"` attribute's
   declarations, not a copy of either. Requiring this sibling file is
   side-effect-free (it only declares consts/functions). */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'html_sanitizer.php';

/**
 * Bumped on ANY rule change (an element, attribute, or value-shape
 * added/removed/tightened). Stored per-row (`tblOrganisationLogos.
 * SanitiserVersion`) so a future TIGHTENING can find and re-sanitise every
 * SVG row produced under an older, looser version from its dormant
 * `ContentOriginal` copy — mirrors `IHYMNS_HTML_SANITISER_VERSION`'s
 * exact role for the print-layout sanitiser.
 */
const IHYMNS_SVG_SANITISER_VERSION = 1;

/** The SVG 1.1/2 namespace URI — the ONE namespace any surviving element or
 *  (non-`xml:space`) attribute must live in. @link https://www.w3.org/2000/svg */
const IHYMNS_SVG_NAMESPACE_URI = 'http://www.w3.org/2000/svg';

/** The XML namespace URI — the ONE non-SVG namespace with a single admitted
 *  attribute (`xml:space`). This prefix is implicit in every XML document
 *  (never needs its own `xmlns:xml=` declaration), unlike `xlink:`/
 *  `inkscape:`/`sodipodi:`, which DO and are therefore banned by the
 *  general "any other namespace" rule below. */
const IHYMNS_SVG_XML_NAMESPACE_URI = 'http://www.w3.org/XML/1998/namespace';

/** Upload byte cap enforced BY THE SANITISER ITSELF (defence in depth — the
 *  caller, `includes/org_logo_admin.php`, applies its own coarser pre-sniff
 *  cap from `IHYMNS_ORG_LOGO_MAX_SVG_BYTES` before this function is even
 *  called, but this file must never trust a caller to have done so). */
const IHYMNS_SVG_SANITISER_MAX_BYTES = 524288; // 512 KiB

/** Render-bomb budget (§3.2.4 of the plan) — a real logo is hundreds of
 *  nodes at a handful of levels deep; 10,000 nodes / 64 levels is generous
 *  headroom for a legitimate complex logo while refusing a crafted
 *  decompression/nesting bomb. */
const IHYMNS_SVG_SANITISER_MAX_NODES = 10000;
const IHYMNS_SVG_SANITISER_MAX_DEPTH = 64;

/**
 * The element allow-list (SVG namespace only — §3.3). EXACT case matters:
 * XML element/attribute names are case-SENSITIVE, unlike HTML, so this list
 * uses the real SVG spec casing (`linearGradient`, not `lineargradient`). A
 * foreign-namespace element (anything not in `IHYMNS_SVG_NAMESPACE_URI`) or
 * an unlisted local name is DROPPED WHOLE, never unwrapped (§3.2.5).
 *
 * Deliberately EXCLUDED (each a named threat in the plan's §3.5 table):
 * `script`, `style` (the ELEMENT — a `style=` ATTRIBUTE is handled
 * separately, see below), `foreignObject`, `use`, `image`, `a`,
 * `animate`/`set`/`animateMotion`/`animateTransform` (all SMIL), every
 * `fe*` filter primitive + `filter`, `marker`, `mask`, `pattern`, `switch`,
 * `view`, `metadata`, and font/glyph elements.
 */
const IHYMNS_SVG_ALLOWED_ELEMENTS = [
    'svg', 'g', 'defs', 'title', 'desc',
    'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon',
    'text', 'tspan',
    'linearGradient', 'radialGradient', 'stop',
    'clipPath', 'symbol',
];

/**
 * The SVG "paint & presentation" property set admitted inside a `style="…"`
 * attribute (§3.4's style-attribute bullet). Deliberately a SUBSET of the
 * full attribute allow-list below — structural attributes (`viewBox`,
 * `transform`, `id`, `d`, `points`, …) have no legitimate CSS-property
 * form and are never reachable via `style=`.
 */
const IHYMNS_SVG_STYLE_PROPERTIES = [
    'fill', 'stroke', 'stop-color', 'color',
    'fill-opacity', 'stroke-opacity', 'opacity', 'stop-opacity',
    'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'stroke-miterlimit',
    'stroke-dasharray', 'stroke-dashoffset', 'fill-rule', 'clip-rule', 'clip-path',
    'font-family', 'font-size', 'font-weight', 'font-style', 'text-anchor', 'letter-spacing',
];

/**
 * Sanitise an untrusted SVG upload into a safe, standalone SVG document.
 *
 * @param  string $bytes  Untrusted SVG upload, as received.
 * @return array{bytes:string, width:?int, height:?int}|null
 *         Sanitised standalone SVG document bytes (+ intrinsic dimensions
 *         from `viewBox` or `width`/`height`), or NULL to REJECT. See the
 *         file doc-block's "REJECT-NOT-REPAIR" section for the exact
 *         reject/strip split.
 */
function ihymnsSanitizeSvg(string $bytes): ?array
{
    /* ---- 1. Byte pre-checks (reject) — §3.2.1 ---- */
    if ($bytes === '' || strlen($bytes) > IHYMNS_SVG_SANITISER_MAX_BYTES) {
        return null;
    }
    if (preg_match('/<!DOCTYPE/i', $bytes) === 1 || preg_match('/<!ENTITY/i', $bytes) === 1) {
        return null;
    }

    /* ---- 2/3. Entity loader nulled + parse (§3.2.2/§3.2.3) ---- */
    $prevErrState = libxml_use_internal_errors(true);
    libxml_set_external_entity_loader(static function () {
        return null; // refuse every external entity resolution attempt
    });
    try {
        $doc = new \DOMDocument('1.0', 'UTF-8');
        /* LIBXML_NONET: never fetch anything over the network. Deliberately
           NEVER LIBXML_NOENT (no entity substitution) and NEVER
           LIBXML_PARSEHUGE (keep libxml's own depth/size guards). */
        $loaded = @$doc->loadXML($bytes, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    } catch (\Throwable $e) {
        $loaded = false;
        $doc = null;
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($prevErrState);
        libxml_set_external_entity_loader(null); // restore the default (no custom loader)
    }

    /* ---- 4. Post-parse rejects (§3.2.4) ---- */
    if (!$loaded || $doc === null) {
        return null;
    }
    if ($doc->doctype !== null) {
        return null; // a DTD survived parsing — refuse, never trust libxml's leniency
    }
    $root = $doc->documentElement;
    if ($root === null
        || $root->namespaceURI !== IHYMNS_SVG_NAMESPACE_URI
        || strtolower((string)$root->localName) !== 'svg') {
        return null;
    }

    $nodeCount = 0;
    $maxDepth  = 0;
    if (!ihymnsSvgCountAndDepth($root, 1, $nodeCount, $maxDepth)) {
        return null; // over the node-count / depth render-bomb budget
    }

    /* ---- 5/6. Rebuild (default-deny, drop-not-unwrap) + serialise ---- */
    try {
        $newDoc  = new \DOMDocument('1.0', 'UTF-8');
        $newRoot = $newDoc->createElementNS(IHYMNS_SVG_NAMESPACE_URI, 'svg');
        $newDoc->appendChild($newRoot);
        ihymnsSvgCopyAttributes($root, $newRoot);
        $emitted = 1; // the root itself counts toward the node budget
        ihymnsSvgWalkChildren($newDoc, $root, $newRoot, $emitted, IHYMNS_SVG_SANITISER_MAX_NODES);
        $out = $newDoc->saveXML($newRoot);
    } catch (\Throwable $e) {
        return null; // any internal failure is a reject, never a partial output
    }
    if ($out === false || trim($out) === '') {
        return null;
    }

    [$width, $height] = ihymnsSvgExtractDimensions($newRoot);

    return ['bytes' => $out, 'width' => $width, 'height' => $height];
}

/* =============================================================================
 * BUDGET WALK — pre-rebuild node-count / depth check (short-circuits early)
 * ========================================================================== */

/**
 * Count every DOMElement descendant of `$el` (inclusive) and track the
 * maximum depth reached, short-circuiting the moment EITHER budget in
 * `IHYMNS_SVG_SANITISER_MAX_NODES`/`_MAX_DEPTH` is exceeded — a crafted
 * 100k-node or 1000-deep document is refused before the (more expensive)
 * rebuild walk ever starts.
 *
 * @param  int $depth      1-based depth of `$el` itself.
 * @param  int &$count     Running node count (by reference).
 * @param  int &$maxDepth  Running max depth (by reference).
 * @return bool  false the moment either budget is breached, true otherwise.
 */
function ihymnsSvgCountAndDepth(\DOMElement $el, int $depth, int &$count, int &$maxDepth): bool
{
    $count++;
    if ($depth > $maxDepth) {
        $maxDepth = $depth;
    }
    if ($count > IHYMNS_SVG_SANITISER_MAX_NODES || $maxDepth > IHYMNS_SVG_SANITISER_MAX_DEPTH) {
        return false;
    }
    foreach ($el->childNodes as $child) {
        if ($child instanceof \DOMElement) {
            if (!ihymnsSvgCountAndDepth($child, $depth + 1, $count, $maxDepth)) {
                return false;
            }
        }
    }
    return true;
}

/* =============================================================================
 * TREE REBUILD (default-deny, DROP-not-unwrap — §3.2.5)
 * ========================================================================== */

/**
 * Walk every child of `$source`, appending a sanitised counterpart onto
 * `$target` (a freshly created element in `$newDoc`, or `$newDoc`'s root).
 */
function ihymnsSvgWalkChildren(\DOMDocument $newDoc, \DOMNode $source, \DOMNode $target, int &$emitted, int $maxNodes): void
{
    foreach (iterator_to_array($source->childNodes) as $child) {
        ihymnsSvgWalkNode($newDoc, $child, $target, $emitted, $maxNodes);
    }
}

/**
 * Sanitise ONE node from the untrusted source tree and append its safe
 * counterpart onto `$target`:
 *   - Text                     → copied verbatim (DOM escapes on
 *                                 serialisation; this is how `<text>`/
 *                                 `<tspan>` label content survives).
 *   - Element, KNOWN + SVG-NS  → a fresh namespaced element is created,
 *                                 its attributes filtered
 *                                 (`ihymnsSvgCopyAttributes()`), and its
 *                                 children walked recursively INTO it.
 *   - Element, UNKNOWN/foreign → DROPPED WHOLE — unlike
 *                                 `html_sanitizer.php`'s HTML unwrap, an
 *                                 SVG element that isn't allow-listed is
 *                                 discarded together with everything
 *                                 inside it (§3.2.5 — "needs no argument").
 *   - Anything else            → dropped (comments, processing
 *                                 instructions, CDATA sections).
 */
function ihymnsSvgWalkNode(\DOMDocument $newDoc, \DOMNode $node, \DOMNode $target, int &$emitted, int $maxNodes): void
{
    if ($node instanceof \DOMText) {
        $target->appendChild($newDoc->createTextNode($node->data));
        return;
    }
    if (!($node instanceof \DOMElement)) {
        return; // comments, PIs, CDATA — never kept
    }

    $localName = $node->localName ?? $node->nodeName;
    if ($node->namespaceURI !== IHYMNS_SVG_NAMESPACE_URI
        || !in_array($localName, IHYMNS_SVG_ALLOWED_ELEMENTS, true)) {
        return; // disallowed / foreign-namespace element — DROP WHOLE, children included
    }
    if ($emitted >= $maxNodes) {
        return; // hard floor — the pre-count above already rejected anything over budget
    }
    $emitted++;

    $newEl = $newDoc->createElementNS(IHYMNS_SVG_NAMESPACE_URI, $localName);
    ihymnsSvgCopyAttributes($node, $newEl);
    ihymnsSvgWalkChildren($newDoc, $node, $newEl, $emitted, $maxNodes);
    $target->appendChild($newEl);
}

/* =============================================================================
 * ATTRIBUTE FILTER (§3.4 — unconditional bans FIRST, then per-name value shape)
 * ========================================================================== */

/**
 * Filter `$src`'s attributes onto `$dst`. Unconditional bans are checked
 * BEFORE any allow-list is consulted, so no per-attribute rule can ever
 * accidentally re-admit them:
 *   1. any local name starting with `on` (case-insensitive) — event
 *      handlers, on ANY element.
 *   2. any local name equal to `href` (case-insensitive) — this catches
 *      BOTH a bare `href=` AND `xlink:href=` (whose `localName` is
 *      `href` regardless of the `xlink:` prefix) — no allow-listed
 *      element needs either.
 *   3. any attribute in a non-null, NON-SVG namespace — kills `xlink:*`
 *      (everything except `href`, already caught by #2), `xml:base`,
 *      editor junk like `inkscape:*`/`sodipodi:*` wholesale. `xml:space`
 *      is the ONE namespaced attribute admitted.
 */
function ihymnsSvgCopyAttributes(\DOMElement $src, \DOMElement $dst): void
{
    foreach (iterator_to_array($src->attributes) as $attr) {
        /** @var \DOMAttr $attr */
        $localName = $attr->localName ?? $attr->nodeName;
        $ns        = $attr->namespaceURI;
        $lower     = strtolower($localName);

        if (str_starts_with($lower, 'on')) {
            continue;
        }
        /* Belt-and-braces: also scan the FULL nodeName (not just localName)
           for "href" — covers a pathological namespace-resolution edge case
           where a prefixed attribute's localName didn't resolve cleanly. */
        if ($lower === 'href' || str_contains(strtolower($attr->nodeName), 'href')) {
            continue;
        }

        $isXmlSpace = ($ns === IHYMNS_SVG_XML_NAMESPACE_URI && $lower === 'space');
        if ($ns !== null && $ns !== '' && !$isXmlSpace) {
            continue; // xlink:*, xml:base, inkscape:*, sodipodi:*, xmlns[:*], … — all banned
        }

        if ($lower === 'style') {
            $clean = ihymnsSvgSanitizeStyleAttr($attr->value);
            if ($clean !== '') {
                $dst->setAttribute('style', $clean);
            }
            continue;
        }

        $lookupName = $isXmlSpace ? 'xml:space' : $localName; // exact XML case; synthetic key for xml:space
        $clean = ihymnsSvgValidateAttrValue($lookupName, $attr->value);
        if ($clean === null) {
            continue;
        }
        if ($isXmlSpace) {
            $dst->setAttributeNS(IHYMNS_SVG_XML_NAMESPACE_URI, 'xml:space', $clean);
        } else {
            $dst->setAttribute($localName, $clean);
        }
    }
}

/**
 * Validate + return a cleaned value for ONE allow-listed attribute/style
 * property name, or `null` to drop it. Structural numeric/enum shapes are
 * table-driven (`ihymnsSvgAttrPatterns()`); paint values (`fill`/`stroke`/
 * `stop-color`/`color`), `clip-path`, and `transform`/`gradientTransform`
 * get their own grammar-aware validators because a single regex can't
 * express "one of several alternative shapes" cleanly. This is the SAME
 * function used both for a direct XML attribute AND for one declaration
 * inside a `style="…"` attribute (`ihymnsSvgSanitizeStyleAttr()` below) —
 * one validator, never forked per caller (rule #35).
 *
 * @return string|null  The (unmodified) value if it validates, else null.
 */
function ihymnsSvgValidateAttrValue(string $name, string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    if (in_array($name, ['fill', 'stroke', 'stop-color', 'color'], true)) {
        return ihymnsSvgPaintValueValid($value) ? $value : null;
    }
    if ($name === 'clip-path') {
        return ihymnsSvgUrlRefValid($value) ? $value : null;
    }
    if ($name === 'transform' || $name === 'gradientTransform') {
        return ihymnsSvgTransformValid($value) ? $value : null;
    }
    $patterns = ihymnsSvgAttrPatterns();
    if (isset($patterns[$name]) && preg_match($patterns[$name], $value) === 1) {
        return $value;
    }
    return null; // not an allow-listed name at all, or failed its shape check
}

/**
 * The table-driven structural/enum/numeric attribute patterns (§3.4's
 * "Structure" + numeric/enum "Paint & presentation" bullets). Built once
 * per request (a function, not a `const`, because PCRE pattern strings are
 * interpolated from the shared number grammar below — cheap, and this is
 * called at most once per attribute).
 *
 * @return array<string,string>  attribute/property name => PCRE pattern.
 */
function ihymnsSvgAttrPatterns(): array
{
    static $patterns = null;
    if ($patterns !== null) {
        return $patterns;
    }

    /* A signed decimal, optionally in scientific notation — the one number
       grammar every numeric attribute below is built from. */
    $num     = '-?\d*\.?\d+(?:[eE][+-]?\d+)?';
    $numUnit = $num . '(?:px|%|em)?'; // + the three unit suffixes a logo plausibly uses
    $opacity = '(?:0|1|0?\.\d+|\d{1,3}%)'; // 0..1 or a percentage — mirrors html_sanitizer's grayscale() shape

    $patterns = [
        /* Structure. */
        'viewBox'              => "/^\s*{$num}(?:[\s,]+{$num}){3}\s*$/",
        'width'                => "/^{$numUnit}$/",
        'height'               => "/^{$numUnit}$/",
        'x'                    => "/^{$numUnit}$/",
        'y'                    => "/^{$numUnit}$/",
        'x1'                   => "/^{$numUnit}$/",
        'y1'                   => "/^{$numUnit}$/",
        'x2'                   => "/^{$numUnit}$/",
        'y2'                   => "/^{$numUnit}$/",
        'cx'                   => "/^{$numUnit}$/",
        'cy'                   => "/^{$numUnit}$/",
        'r'                    => "/^{$numUnit}$/",
        'rx'                   => "/^{$numUnit}$/",
        'ry'                   => "/^{$numUnit}$/",
        'dx'                   => "/^{$numUnit}$/",
        'dy'                   => "/^{$numUnit}$/",
        'points'               => '/^[0-9.\-+,\s]+$/',
        'd'                    => '/^[A-Za-z0-9 ,.eE+\-]*$/',
        'preserveAspectRatio'  => '/^(?:none|x(?:Min|Mid|Max)Y(?:Min|Mid|Max))(?:\s+(?:meet|slice))?$/',
        'version'              => '/^[0-9.]{1,10}$/',

        /* Identity for same-document refs. */
        'id'                   => '/^[A-Za-z][A-Za-z0-9_-]{0,63}$/',

        /* Presentation — numeric / enum. */
        'fill-opacity'         => "/^{$opacity}$/",
        'stroke-opacity'       => "/^{$opacity}$/",
        'opacity'              => "/^{$opacity}$/",
        'stop-opacity'         => "/^{$opacity}$/",
        'offset'               => "/^{$opacity}$/",
        'stroke-width'         => "/^{$numUnit}$/",
        'stroke-linecap'       => '/^(?:butt|round|square)$/',
        'stroke-linejoin'      => '/^(?:miter|round|bevel)$/',
        'stroke-miterlimit'    => "/^{$num}$/",
        'stroke-dasharray'     => '/^(?:none|[0-9.,\s%]+)$/',
        'stroke-dashoffset'    => "/^{$numUnit}$/",
        'fill-rule'            => '/^(?:nonzero|evenodd)$/',
        'clip-rule'            => '/^(?:nonzero|evenodd)$/',
        'gradientUnits'        => '/^(?:userSpaceOnUse|objectBoundingBox)$/',
        'spreadMethod'         => '/^(?:pad|reflect|repeat)$/',
        'font-family'          => '/^[A-Za-z0-9 ,\-]{1,100}$/',
        'font-size'            => "/^{$numUnit}$/",
        'font-weight'          => '/^(?:normal|bold|bolder|lighter|100|200|300|400|500|600|700|800|900)$/',
        'font-style'           => '/^(?:normal|italic|oblique)$/',
        'text-anchor'          => '/^(?:start|middle|end)$/',
        'letter-spacing'       => "/^(?:normal|{$numUnit})$/",

        /* The one namespaced attribute admitted (synthetic lookup key). */
        'xml:space'            => '/^(?:default|preserve)$/',
    ];
    return $patterns;
}

/**
 * A same-document fragment reference: `url(#ID)` where ID matches the `id`
 * charset — the ONLY `url()` FORM that survives ANYWHERE in this file
 * (attribute or `style=` value), and it can only ever point INSIDE the
 * sanitised document (§3.4/§3.5 — the "external fetch / SSRF" row).
 */
function ihymnsSvgUrlRefValid(string $value): bool
{
    return preg_match('/^url\(#[A-Za-z][A-Za-z0-9_-]{0,63}\)$/', trim($value)) === 1;
}

/**
 * A paint value (`fill`/`stroke`/`stop-color`/`color`) — EXACTLY one of:
 * `none` | `currentColor` | `transparent` | `#rgb`/`#rrggbb`/`#rrggbbaa` |
 * `rgb()`/`rgba()`/`hsl()`/`hsla()` with numeric args | a CSS named colour
 * | the same-document `url(#ID)` carve-out above.
 */
function ihymnsSvgPaintValueValid(string $value): bool
{
    $v = trim($value);
    if ($v === '') {
        return false;
    }
    if (in_array($v, ['none', 'currentColor', 'transparent'], true)) {
        return true;
    }
    if (preg_match('/^#[0-9a-fA-F]{3}$/', $v) === 1
        || preg_match('/^#[0-9a-fA-F]{6}$/', $v) === 1
        || preg_match('/^#[0-9a-fA-F]{8}$/', $v) === 1) {
        return true;
    }
    if (preg_match('/^(?:rgba?|hsla?)\(\s*-?[\d.]+%?(?:\s*,\s*-?[\d.]+%?){2,3}\s*\)$/', $v) === 1) {
        return true;
    }
    if (preg_match('/^[a-zA-Z]{3,20}$/', $v) === 1) {
        return true; // CSS named colour ("red", "cornflowerblue", …)
    }
    return ihymnsSvgUrlRefValid($v);
}

/**
 * `transform` / `gradientTransform` — one or more of
 * `matrix|translate|scale|rotate|skewX|skewY` with ONLY numeric
 * (comma/whitespace-separated) arguments. No other function name, and no
 * argument that isn't a plain number, can ever match.
 */
function ihymnsSvgTransformValid(string $value): bool
{
    $num  = '-?\d*\.?\d+(?:[eE][+-]?\d+)?';
    $args = "{$num}(?:[\s,]+{$num})*";
    $fn   = '(?:matrix|translate|scale|rotate|skewX|skewY)';
    return preg_match("/^\s*(?:{$fn}\s*\(\s*{$args}\s*\)[\s,]*)+$/", trim($value)) === 1;
}

/**
 * Filter a `style="…"` attribute VALUE declaration-by-declaration, reusing
 * `html_sanitizer.php`'s `ihymnsCssSplitDeclarations()` tokenizer and
 * `ihymnsCssValueSafe()` banned-substring core (`url(`, `expression(`,
 * `@import`, `@charset`, any backslash, `</`) — ONE declaration filter
 * core, never forked (rule #35). The ONLY exception to
 * `ihymnsCssValueSafe()`'s blanket `url(` ban is the identical
 * same-document `url(#ID)` carve-out `ihymnsSvgUrlRefValid()` admits for a
 * direct XML attribute — checked FIRST, so `style="fill:url(#g1)"`
 * survives while `style="fill:url(http://evil)"` and every other `url(`
 * shape does not. A property must ALSO be in `IHYMNS_SVG_STYLE_PROPERTIES`
 * and pass the SAME per-property shape validator
 * (`ihymnsSvgValidateAttrValue()`) a direct attribute would.
 *
 * @return string  Filtered `prop:value;prop:value` string (may be '').
 */
function ihymnsSvgSanitizeStyleAttr(string $decl): string
{
    $kept = [];
    foreach (ihymnsCssSplitDeclarations($decl) as [$prop, $value]) {
        $prop = strtolower(trim($prop));
        if (!in_array($prop, IHYMNS_SVG_STYLE_PROPERTIES, true)) {
            continue;
        }
        $v = trim($value);
        if (!ihymnsSvgUrlRefValid($v) && !ihymnsCssValueSafe($v)) {
            continue; // any url(...) OTHER than the same-document #ID carve-out is banned here
        }
        $clean = ihymnsSvgValidateAttrValue($prop, $v);
        if ($clean !== null) {
            $kept[] = $prop . ':' . $clean;
        }
    }
    return implode(';', $kept);
}

/* =============================================================================
 * DIMENSIONS — viewBox preferred, width/height fallback
 * ========================================================================== */

/**
 * Extract intrinsic pixel dimensions from the SANITISED root element's
 * (already-validated) `viewBox` or `width`/`height` attributes.
 *
 * @return array{0:?int,1:?int}  [width, height], either possibly null when
 *                                 underivable.
 */
function ihymnsSvgExtractDimensions(\DOMElement $root): array
{
    $viewBox = $root->getAttribute('viewBox');
    if ($viewBox !== '') {
        $parts = preg_split('/[\s,]+/', trim($viewBox));
        if (is_array($parts) && count($parts) === 4) {
            $w = (float)$parts[2];
            $h = (float)$parts[3];
            if ($w > 0 && $h > 0) {
                return [(int)round($w), (int)round($h)];
            }
        }
    }
    $w = ihymnsSvgParseLength($root->getAttribute('width'));
    $h = ihymnsSvgParseLength($root->getAttribute('height'));
    return [$w, $h];
}

/** Parse the leading numeric portion of a `width`/`height` value (e.g.
 *  `"200px"` → 200); returns null when there is no usable positive number. */
function ihymnsSvgParseLength(string $v): ?int
{
    $v = trim($v);
    if ($v === '') {
        return null;
    }
    if (preg_match('/^(\d*\.?\d+)/', $v, $m) === 1) {
        $n = (float)$m[1];
        return $n > 0 ? (int)round($n) : null;
    }
    return null;
}
