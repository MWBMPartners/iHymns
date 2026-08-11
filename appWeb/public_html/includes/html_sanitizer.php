<?php

declare(strict_types=1);

/**
 * iHymns — Shared HTML/CSS sanitiser (#1767 remainder, P2)
 *
 * ELI5: this file is the one bouncer at the door for any HTML a curator or
 * an attacker hands us that we might later show on a page or bake into a
 * PDF. It never trusts what comes in — it looks at every tag and every
 * attribute and only lets through the small, named set of things we
 * actually need (a title, a paragraph, a picture with a safe web address),
 * throwing away — or unwrapping to plain, harmless text — anything else,
 * including things that look like code (`<script>`), things that react to
 * events (`onerror=`), and CSS tricks that can fetch a picture from
 * somewhere we didn't intend.
 *
 * DETAIL
 * ------
 * Two consumers share this ONE module (rule: never fork a second allow-list
 * for the "same kind of problem" — CLAUDE.md red-flag list):
 *   1. Feature E (#1767) — a curator uploads a full-page HTML "layout" that
 *      wraps `{{content}}` (`manage/print-templates.php`, landing in a later
 *      commit of this remainder).
 *   2. The server-PDF endpoint (`manage/print-pdf.php`, landing in a later
 *      commit) — the client always POSTs the finished print HTML (the
 *      one-renderer invariant, `.claude/print-templates-1767-remainder-plan.md`
 *      §2), and a POST body is attacker-writable independently of whether
 *      `js/modules/print.js` ever ran, so the endpoint must sanitise even
 *      though its OWN renderer already escapes everything it emits (§5.2 of
 *      the plan).
 *
 * DEFAULT-DENY, DATA-DRIVEN PROFILES (`IHYMNS_SANITIZER_PROFILES`): a tag not
 * named in the active profile's allow-list is never dropped outright —
 * it is UNWRAPPED, keeping its (recursively sanitised) children but
 * discarding the tag itself. `<script>alert(1)</script>` therefore survives
 * only as the harmless, inert TEXT "alert(1)" — never as a `<script>`
 * element, so it can never execute. An attribute not named for that
 * element is simply dropped. Every `on*` event-handler attribute and `id`
 * are banned in EVERY profile, unconditionally, before the per-profile
 * allow-list is even consulted.
 *
 * WHY REBUILD RATHER THAN REGEX/STRIP: this module parses the input with
 * `DOMDocument` and REBUILDS a brand-new document node-by-node from the
 * allow-list — it never patches or strips substrings out of the original
 * HTML string. A regex-based stripper is a well-known bypass magnet
 * (mismatched/nested tags, encoded entities, attribute-value tricks); a
 * default-deny tree rebuild structurally cannot let an unrecognised node
 * "ride through" unparsed, because the OUTPUT tree only ever contains nodes
 * this file explicitly created.
 *
 * WHY IT STRUCTURALLY BLOCKS A GATED-MEDIA LEAK (rule M): neither profile's
 * `<img src>` allow-list can ever match a `/song-media/<id>` URL — `print`
 * only accepts `^/qr\.php\?`, `layout` only accepts that OR a same-request
 * `data:image/...;base64,` payload — and CSS `url(...)` is banned
 * OUTRIGHT in every declaration value regardless of property or profile.
 * So a PDF/layout render can never smuggle a gated media byte-stream
 * through either the HTML or the CSS surface, independent of whatever tier
 * gate `includes/content_gating.php` applies elsewhere.
 *
 * NOT YET WIRED TO A CONSUMER: this commit lands the module + its guard
 * (`tests/php/test-html-sanitizer.php`) only. `manage/print-templates.php`
 * (E's upload path) and `manage/print-pdf.php` (the PDF endpoint) are later
 * commits of the same remainder (P3/P7 in the plan's §10 commit table).
 *
 * @see .claude/print-templates-1767-remainder-plan.md §5  the full design this file implements (follow it EXACTLY — permanently-maintained attack surface)
 * @see tests/php/test-html-sanitizer.php               the mutation-proven functional truth table (§5.5)
 * @see appWeb/public_html/includes/marcxml.php           the house XXE-safety posture (LIBXML_NONET, no LIBXML_NOENT) this mirrors
 * @link https://www.php.net/manual/en/book.dom.php       DOMDocument reference
 * @link https://www.php.net/manual/en/domdocument.loadhtml.php
 * @link https://owasp.org/www-community/attacks/xss/     the general threat model this file defends against
 * @link https://developer.mozilla.org/en-US/docs/Web/CSS/CSS_syntax/Syntax  CSS declaration/at-rule grammar this file's tokenizer follows loosely
 */

/**
 * Bumped on ANY rule change (a tag, attribute, class pattern, or CSS
 * property added/removed/tightened). Stored per-row alongside a saved
 * layout (`tblPrintTemplateCustomLayout.SanitiserVersion`) so a future
 * TIGHTENING can find and re-sanitise every row produced under an older,
 * looser version — see the plan's §5.4. Not read by this file itself; the
 * caller (the save/re-sanitise path, a later commit) is responsible for
 * stamping it.
 */
const IHYMNS_HTML_SANITISER_VERSION = 1;

/**
 * Tags ALWAYS unwrapped (never allow-listed by either profile), named here
 * purely for documentation/readability — enforcement is the default-deny
 * walk itself (any tag not in a profile's `tags` list is unwrapped), so
 * this list is not consulted by the code; it exists so a reader doesn't
 * have to reconstruct "everything dangerous" by diffing the two allow-lists.
 * ELI5: "the greatest hits of tags a sanitiser must never trust" — parsers,
 * executors, styling/network fetchers, and interactive form controls.
 *
 * @see https://developer.mozilla.org/en-US/docs/Web/HTML/Reference/Elements
 */
const IHYMNS_HTML_ALWAYS_BANNED_TAGS = [
    'script', 'style', 'link', 'meta', 'base', 'iframe', 'frame', 'object',
    'embed', 'form', 'input', 'button', 'select', 'textarea', 'svg', 'math',
    'template', 'slot', 'audio', 'video', 'source',
];

/**
 * The two sanitiser profiles, as DATA (never a forked code path — CLAUDE.md
 * rule #22/#37's "one core, profiles as configuration" pattern, applied
 * here to markup instead of a DB entity). Keys:
 *   tags             — allow-listed element names (lower-case).
 *   attrs['*']       — attribute names allowed on EVERY allow-listed tag.
 *   attrs[<tag>]     — additional attribute names allowed ONLY on <tag>.
 *   class_pattern    — a class TOKEN (one word inside `class="a b c"`) must
 *                       match this regex to survive; non-matching tokens are
 *                       dropped individually (the rest of the class list
 *                       survives).
 *   img_src          — see ihymnsSanitizeImgSrcAllowed()'s doc-block.
 *
 * `print`  = exactly what `js/modules/print.js`'s `renderTemplateBodyHtml()`
 *            can emit (div/span/h1/p/br/img + the print- and lyric- class
 *            vocabulary) — this is the PDF-endpoint's input profile.
 * `layout` = the richer formatting superset feature E's uploaded full-page
 *            skins may use — everything `print` allows plus headings,
 *            inline emphasis, lists and tables, a free class-token charset,
 *            and a same-request `data:image/...` src in addition to
 *            `/qr.php`.
 */
const IHYMNS_SANITIZER_PROFILES = [
    'print' => [
        'tags' => ['div', 'span', 'h1', 'p', 'br', 'img'],
        'attrs' => [
            '*'   => ['class', 'style', 'dir', 'lang'],
            'img' => ['src', 'alt', 'width', 'height'],
        ],
        /* Exactly the two families renderBlock() emits — print-<name> for
           block wrapper/part classes, lyric-<component-type> for the
           per-verse/chorus/bridge/etc. type class (print.js L196). */
        'class_pattern' => '/^(?:print|lyric)-[a-z0-9]+(?:-[a-z0-9]+)*$/',
        'img_src' => [
            /* The ONLY src shape print.js ever emits (the CueRCode-backed
               QR image, print.js L335) — structurally excludes
               /song-media/<id> (rule M) and any javascript:/data: scheme. */
            'patterns' => ['#^/qr\.php\?#'],
        ],
    ],
    'layout' => [
        'tags' => [
            'div', 'span', 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'br', 'hr',
            'strong', 'em', 'b', 'i', 'u', 'small', 'sup', 'sub', 'blockquote',
            'ul', 'ol', 'li', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td',
            'img', 'header', 'footer', 'section',
        ],
        'attrs' => [
            '*'    => ['class', 'style', 'dir', 'lang'],
            'th'   => ['colspan', 'rowspan'],
            'td'   => ['colspan', 'rowspan'],
            'img'  => ['src', 'alt', 'width', 'height'],
        ],
        /* Free class-token charset (a curator's own CSS naming) — still one
           TOKEN at a time, still whitespace-delimited, still no other
           character class (no combinators, no attribute-selector shapes
           can ride in via a class value). */
        'class_pattern' => '/^[A-Za-z0-9_-]+$/',
        'img_src' => [
            /* A layout may also emit its own QR image via the same helper. */
            'patterns' => ['#^/qr\.php\?#'],
            /* Self-contained only — no network fetch, no SVG (SVG is a
               script vector: <svg><script>…</script></svg> is valid SVG).
               200 KiB decoded cap keeps a curator's logo/background small
               enough that a template row never becomes a multi-MB blob. */
            'data_uri_mimes'     => ['image/png', 'image/jpeg', 'image/jpg', 'image/gif', 'image/webp'],
            'data_uri_max_bytes' => 204800,
        ],
    ],
];

/* =============================================================================
 * PUBLIC ENTRY POINTS
 * ========================================================================== */

/**
 * Sanitise an HTML fragment against one of `IHYMNS_SANITIZER_PROFILES`.
 *
 * ELI5: hand this a blob of HTML you don't trust and the name of which
 * "shape of document" it's supposed to be, and get back a smaller, safe
 * blob of HTML that only contains the tags/attributes/CSS that shape is
 * allowed to use.
 *
 * DETAIL: parses `$html` as an HTML FRAGMENT (wrapped in a throwaway
 * `<body>` so DOMDocument has somewhere to park it), walks the resulting
 * tree, and REBUILDS a fresh tree containing only allow-listed
 * nodes/attributes (see the file doc-block for why rebuild-not-strip
 * matters). `LIBXML_NONET` blocks any network fetch libxml might otherwise
 * attempt; `LIBXML_NOENT` is deliberately never passed (mirrors
 * `includes/marcxml.php`'s XXE posture) — though `loadHTML()`'s simplified
 * HTML parser does not process an internal DTD subset the way `loadXML()`
 * would, a literal `<!DOCTYPE` occurring mid-fragment is stripped before
 * parsing as a defence-in-depth belt-and-braces (a legitimate print/layout
 * fragment never legitimately contains one).
 *
 * @param  string $html    Untrusted HTML fragment (no `<html>`/`<body>`).
 * @param  string $profile One of the keys of `IHYMNS_SANITIZER_PROFILES`
 *                          ('print' | 'layout'). NOT attacker-controlled in
 *                          any current call site — an unknown profile is a
 *                          caller bug, so it throws rather than fails open.
 * @return string Sanitised HTML fragment (may be '').
 *
 * @link https://www.php.net/manual/en/domdocument.loadhtml.php
 */
function ihymnsSanitizeHtml(string $html, string $profile): string
{
    if (!isset(IHYMNS_SANITIZER_PROFILES[$profile])) {
        throw new \InvalidArgumentException("ihymnsSanitizeHtml: unknown profile '{$profile}'");
    }
    if (trim($html) === '') {
        return '';
    }

    /* Defence-in-depth: a DOCTYPE has no legitimate place mid-fragment: strip
       any occurrence before it ever reaches libxml (mirrors marcxml.php's
       "refuse the shape outright" posture — cheap, and a print/layout
       fragment never needs one). */
    $html = (string)preg_replace('/<!DOCTYPE[^>]*>/i', '', $html);

    $doc = new \DOMDocument('1.0', 'UTF-8');
    $prevErrorState = libxml_use_internal_errors(true);
    /* The XML encoding prologue prepended below is the standard trick that makes
       DOMDocument::loadHTML() honour UTF-8 input without libxml's default
       Latin-1 mis-detection mangling multi-byte characters; it never
       survives into the parsed tree as a real node, so nothing downstream
       needs to skip it. LIBXML_NONET: never fetch anything over the
       network while parsing (belt-and-braces; nothing in either profile's
       allow-list can reach this file's input from the network anyway). */
    $doc->loadHTML(
        '<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>',
        LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
    );
    libxml_clear_errors();
    libxml_use_internal_errors($prevErrorState);

    $body = $doc->getElementsByTagName('body')->item(0);
    if ($body === null) {
        return '';
    }

    $profileDef = IHYMNS_SANITIZER_PROFILES[$profile];
    $fragment   = $doc->createDocumentFragment();
    ihymnsSanitizeWalkChildren($doc, $body, $fragment, $profileDef);

    $out = '';
    foreach (iterator_to_array($fragment->childNodes) as $node) {
        $piece = $doc->saveHTML($node);
        if ($piece !== false) {
            $out .= $piece;
        }
    }
    return $out;
}

/**
 * Sanitise a WHOLE stylesheet string (the `css` field a PDF-endpoint POST
 * carries — `printCss()`'s output, §4.1 of the plan). Only `@page { … }`
 * and `@media print { … }` wrappers plus plain class/element selector
 * rulesets survive; every other at-rule (`@import`, `@charset`,
 * `@font-face`, `@keyframes`, …) is dropped outright, and every surviving
 * ruleset's declaration BODY goes through the same per-declaration filter
 * `ihymnsSanitizeStyleAttr()` uses for a `style="…"` attribute — one filter,
 * shared, never forked (rule #35).
 *
 * Selector grammar accepted: element names, class selectors (`.foo`), the
 * universal selector (`*`), descendant combinators (a bare space) and
 * comma-separated lists — i.e. exactly what `printCss()` emits today. NO
 * attribute selectors, NO id selectors, NO pseudo-classes/elements, NO
 * combinators other than a descendant space — the character class alone
 * (`ihymnsCssSelectorSafe()`) structurally excludes all of those.
 *
 * KNOWN, ACCEPTABLE LIMITATION: a bare (brace-less) at-rule STATEMENT
 * (`@import url(x);` with no `{ }` block) is not a shape `printCss()` ever
 * emits; this parser is brace-driven, so such a statement is consumed as
 * part of the "head" text up to the NEXT `{`, and — because that head
 * starts with `@` — the whole thing (including whatever real ruleset
 * follows) is dropped. That is a false NEGATIVE for usability, never a
 * false positive for safety: the banned at-rule still never survives.
 *
 * @param  string $css Untrusted stylesheet text.
 * @return string Sanitised stylesheet text (may be '').
 */
function ihymnsSanitizeCss(string $css): string
{
    /* Blind comment strip. Known, accepted limitation: a comment-looking
       sequence INSIDE a quoted string (e.g. a font-family value) would also
       be stripped — harmless in practice because no property this filter
       allows legitimately needs a quoted value containing "/*"; see
       ihymnsSanitizeStyleAttr()'s doc-block for the identical trade-off. */
    $css = (string)preg_replace('#/\*.*?\*/#s', '', $css);

    $out = '';
    $len = strlen($css);
    $i   = 0;
    while ($i < $len) {
        while ($i < $len && ctype_space($css[$i])) {
            $i++;
        }
        if ($i >= $len) {
            break;
        }
        $braceIdx = ihymnsCssFindTopLevelChar($css, '{', $i);
        if ($braceIdx === false) {
            break; /* trailing garbage with no rule body — nothing left to keep */
        }
        $head = trim(substr($css, $i, $braceIdx - $i));
        $closeIdx = ihymnsCssFindMatchingBrace($css, $braceIdx);
        if ($closeIdx === false) {
            break; /* unterminated block — nothing safely extractable */
        }
        $bodyStr = substr($css, $braceIdx + 1, $closeIdx - $braceIdx - 1);
        $i = $closeIdx + 1;

        if (preg_match('/^@page\s*$/i', $head) === 1) {
            $decl = ihymnsSanitizeStyleAttr($bodyStr);
            if ($decl !== '') {
                $out .= '@page{' . $decl . "}\n";
            }
            continue;
        }
        if (preg_match('/^@media\s+print\s*$/i', $head) === 1) {
            $inner = ihymnsSanitizeCss($bodyStr); /* recurse — the body is itself a mini-stylesheet */
            if ($inner !== '') {
                $out .= '@media print{' . $inner . "}\n";
            }
            continue;
        }
        if ($head === '' || $head[0] === '@') {
            continue; /* every other at-rule (@import/@charset/@font-face/@keyframes/…) is banned outright */
        }
        if (!ihymnsCssSelectorSafe($head)) {
            continue;
        }
        $decl = ihymnsSanitizeStyleAttr($bodyStr);
        if ($decl !== '') {
            $out .= $head . '{' . $decl . "}\n";
        }
    }
    return trim($out);
}

/**
 * Sanitise ONE `style="…"` attribute VALUE (a single flat declaration
 * list — no selectors, no at-rules) into a filtered `prop:value;prop:value`
 * string. Shared by `ihymnsSanitizeCopyAttributes()` (an HTML `style=`
 * attribute) and `ihymnsSanitizeCss()` (a `{ … }` ruleset/at-rule body) —
 * ONE declaration filter, never forked per caller (rule #35).
 *
 * Declarations are split on `;` OUTSIDE quotes/parens (so a value like
 * `font-family:"A;B"` or `grayscale(1)` is never mis-split), then each
 * `prop:value` pair must pass BOTH:
 *   1. `ihymnsCssPropertyAllowed()`      — the property name is on the list.
 *   2. `ihymnsCssValueAllowedForProperty()` — the value is free of banned
 *      substrings (`url(`, `expression(`, `@import`, `@charset`, a
 *      backslash, `</`) and, for the few properties with a restricted
 *      value shape (`position`, `display`, `filter`), matches that shape.
 *
 * @param  string $decl Untrusted declaration-list text (attribute value or
 *                       ruleset/at-rule body).
 * @return string Filtered `prop:value;…` string (may be '').
 */
function ihymnsSanitizeStyleAttr(string $decl): string
{
    /* Same blind-strip trade-off as ihymnsSanitizeCss() — see its doc-block. */
    $decl = (string)preg_replace('#/\*.*?\*/#s', '', $decl);

    $kept = [];
    foreach (ihymnsCssSplitDeclarations($decl) as [$prop, $value]) {
        if (!ihymnsCssPropertyAllowed($prop)) {
            continue;
        }
        if (!ihymnsCssValueAllowedForProperty($prop, $value)) {
            continue;
        }
        $kept[] = strtolower(trim($prop)) . ':' . trim($value);
    }
    return implode(';', $kept);
}

/* =============================================================================
 * HTML TREE WALK (default-deny rebuild)
 * ========================================================================== */

/**
 * Walk every child of `$source`, appending a sanitised counterpart (or, for
 * an unwrapped element, that element's own sanitised children) onto
 * `$target`. `$target` may be a `DOMDocumentFragment` (top call) or a freshly
 * created `DOMElement` (recursive call, once an allow-listed wrapper has
 * been created for it).
 */
function ihymnsSanitizeWalkChildren(\DOMDocument $doc, \DOMNode $source, \DOMNode $target, array $profile): void
{
    foreach (iterator_to_array($source->childNodes) as $child) {
        ihymnsSanitizeWalkNode($doc, $child, $target, $profile);
    }
}

/**
 * Sanitise ONE node from the untrusted source tree and append its safe
 * counterpart onto `$target`. This is the default-deny decision point:
 *   - Text            → copied verbatim (DOM handles entity-encoding on
 *                        serialisation; this is the ONLY way literal text
 *                        like a `{{content}}` template token survives — it
 *                        is never special-cased, it simply is never
 *                        anything this function would strip).
 *   - Comment         → dropped entirely (comments are not in ANY
 *                        allow-list; nothing reads them, and IE-style
 *                        "conditional comments" are just comments to every
 *                        engine that matters here).
 *   - Element, KNOWN   → a fresh element of the same tag name is created,
 *                        its attributes filtered
 *                        (`ihymnsSanitizeCopyAttributes()`), and its
 *                        children walked recursively INTO the new element.
 *   - Element, UNKNOWN → UNWRAPPED: no wrapper element is created; its
 *                        children are walked recursively directly onto
 *                        `$target`. This is what turns
 *                        `<script>alert(1)</script>` into the inert text
 *                        "alert(1)" and an mPDF-proprietary `<barcode>…
 *                        </barcode>` into whatever harmless text (if any)
 *                        it wrapped.
 *   - Anything else    → dropped (processing instructions, CDATA sections,
 *                        etc. — none of these are legitimate print/layout
 *                        content).
 */
function ihymnsSanitizeWalkNode(\DOMDocument $doc, \DOMNode $node, \DOMNode $target, array $profile): void
{
    if ($node instanceof \DOMText) {
        $target->appendChild($doc->createTextNode($node->data));
        return;
    }
    if (!($node instanceof \DOMElement)) {
        return; /* comments, processing instructions, CDATA, … — never kept */
    }

    $tag = strtolower($node->tagName);
    if (!in_array($tag, $profile['tags'], true)) {
        ihymnsSanitizeWalkChildren($doc, $node, $target, $profile); /* unwrap */
        return;
    }

    $newEl = $doc->createElement($tag);
    ihymnsSanitizeCopyAttributes($node, $newEl, $tag, $profile);
    ihymnsSanitizeWalkChildren($doc, $node, $newEl, $profile);
    $target->appendChild($newEl);
}

/**
 * Filter `$src`'s attributes onto `$dst` (both already known to share the
 * same allow-listed `$tag`). Default-deny at the attribute level too: an
 * attribute name not resolved by one of the branches below is dropped by
 * falling through to the end of the loop with nothing written.
 */
function ihymnsSanitizeCopyAttributes(\DOMElement $src, \DOMElement $dst, string $tag, array $profile): void
{
    $allowedNames = array_merge($profile['attrs']['*'] ?? [], $profile['attrs'][$tag] ?? []);

    foreach (iterator_to_array($src->attributes) as $attr) {
        $name  = strtolower($attr->name);
        $value = $attr->value;

        /* Unconditional bans — checked BEFORE the per-profile allow-list,
           so no profile can ever accidentally re-admit them. */
        if (str_starts_with($name, 'on') || $name === 'id') {
            continue;
        }
        if (!in_array($name, $allowedNames, true)) {
            continue;
        }

        if ($name === 'style') {
            $clean = ihymnsSanitizeStyleAttr($value);
            if ($clean !== '') {
                $dst->setAttribute('style', $clean);
            }
            continue;
        }

        if ($name === 'class') {
            $pattern = $profile['class_pattern'];
            $tokens  = preg_split('/\s+/', trim($value)) ?: [];
            $kept    = array_values(array_filter(
                $tokens,
                static fn($t) => $t !== '' && preg_match($pattern, $t) === 1
            ));
            if ($kept) {
                $dst->setAttribute('class', implode(' ', $kept));
            }
            continue;
        }

        if ($name === 'src' && $tag === 'img') {
            if (ihymnsSanitizeImgSrcAllowed($value, $profile['img_src'] ?? [])) {
                $dst->setAttribute('src', $value);
            }
            continue;
        }

        if ($name === 'dir') {
            $v = strtolower(trim($value));
            if (in_array($v, ['ltr', 'rtl', 'auto'], true)) {
                $dst->setAttribute('dir', $v);
            }
            continue;
        }

        if ($name === 'lang') {
            /* Loose BCP-47-shaped check (language[-subtag]*) — good enough
               to reject junk while never rejecting a real language tag,
               including script subtags like zh-Hans (rule #21 precedent:
               free-text language tags must never RESTRICT-fail). */
            $v = trim($value);
            if (preg_match('/^[A-Za-z]{1,8}(-[A-Za-z0-9]{1,8})*$/', $v) === 1) {
                $dst->setAttribute('lang', $v);
            }
            continue;
        }

        if ($name === 'colspan' || $name === 'rowspan') {
            $v = trim($value);
            if (preg_match('/^[1-9][0-9]*$/', $v) === 1) {
                $dst->setAttribute($name, $v);
            }
            continue;
        }

        if ($name === 'width' || $name === 'height') {
            $v = trim($value);
            if (preg_match('/^[0-9]+$/', $v) === 1) {
                $dst->setAttribute($name, $v);
            }
            continue;
        }

        if ($name === 'alt') {
            $dst->setAttribute('alt', $value); /* free text — DOM escapes it on serialise */
            continue;
        }
        /* Any other allow-listed-by-name-but-unhandled attribute (should not
           occur given the branches above cover every name either profile
           lists) is dropped rather than copied blind. */
    }
}

/**
 * Is this `<img src="…">` value allowed under `$cfg` (a profile's
 * `img_src` sub-array)? Two independent shapes, either is sufficient:
 *   - `patterns`           — a list of regexes; ANY match allows.
 *   - `data_uri_mimes` +
 *     `data_uri_max_bytes`  — a `layout`-only extra: a `data:image/<mime>;
 *     base64,<payload>` URL whose mime is in the allow-list AND whose
 *     DECODED byte length is within budget. Whitespace inside the base64
 *     payload (some editors line-wrap it) is stripped before a STRICT
 *     `base64_decode()` — strict mode rejects any character outside the
 *     base64 alphabet outright rather than silently skipping it.
 *
 * Structurally excludes `/song-media/<id>` (matches neither shape) and
 * `data:image/svg+xml` (SVG is not in `data_uri_mimes` — SVG can carry its
 * own `<script>`, so it is never treated as "just an image").
 */
function ihymnsSanitizeImgSrcAllowed(string $src, array $cfg): bool
{
    foreach ($cfg['patterns'] ?? [] as $re) {
        if (preg_match($re, $src) === 1) {
            return true;
        }
    }
    $mimes = $cfg['data_uri_mimes'] ?? null;
    if ($mimes && preg_match('#^data:(image/[a-zA-Z0-9.+-]+);base64,(.*)$#is', $src, $m) === 1) {
        $mime = strtolower($m[1]);
        if (in_array($mime, $mimes, true)) {
            $payload = preg_replace('/\s+/', '', $m[2]) ?? '';
            $decoded = base64_decode($payload, true);
            $maxBytes = $cfg['data_uri_max_bytes'] ?? PHP_INT_MAX;
            if ($decoded !== false && strlen($decoded) <= $maxBytes) {
                return true;
            }
        }
    }
    return false;
}

/* =============================================================================
 * CSS — property/value allow-list + tokenizers
 * ========================================================================== */

/**
 * Properties allowed VERBATIM (no prefix matching needed) — §5.3 of the
 * plan, "the full set `printCss()` uses today + layout-useful additions".
 * `-webkit-print-color-adjust` is included alongside the unprefixed
 * `print-color-adjust` because `printCss()` (print.js L403) emits BOTH —
 * §4.1 promises everything that function emits stays inside this allow-list.
 */
const IHYMNS_CSS_ALLOWED_EXACT = [
    'color', 'background', 'background-color',
    'line-height', 'letter-spacing',
    'width', 'height', 'display', 'float', 'clear',
    'position', 'top', 'left', 'right', 'bottom', 'z-index',
    'columns', 'white-space', 'vertical-align', 'opacity',
    'border-radius', 'box-sizing', 'size', 'filter',
    'word-break', 'print-color-adjust', '-webkit-print-color-adjust',
];

/** Property-name PREFIXES allowed (covers the `font-*`/`text-*`/`margin*`/… families). */
const IHYMNS_CSS_ALLOWED_PREFIXES = [
    'font-', 'text-', 'margin', 'padding', 'border',
    'max-', 'min-', 'page-break-', 'break-', 'column-',
];

/** Restricted `display` values — a small, deliberately unsurprising set. */
const IHYMNS_CSS_DISPLAY_ENUM = [
    'block', 'inline', 'inline-block', 'flex', 'inline-flex', 'grid', 'inline-grid',
    'table', 'table-row', 'table-cell', 'table-row-group', 'table-header-group',
    'table-footer-group', 'list-item', 'none',
];

/** Restricted `position` values — deliberately EXCLUDES `fixed`/`sticky`. */
const IHYMNS_CSS_POSITION_ENUM = ['static', 'relative', 'absolute'];

function ihymnsCssPropertyAllowed(string $prop): bool
{
    $prop = strtolower(trim($prop));
    if ($prop === '') {
        return false;
    }
    if (in_array($prop, IHYMNS_CSS_ALLOWED_EXACT, true)) {
        return true;
    }
    foreach (IHYMNS_CSS_ALLOWED_PREFIXES as $prefix) {
        if (str_starts_with($prop, $prefix)) {
            return true;
        }
    }
    return false;
}

/**
 * Is `$value` free of the substrings §5.3 bans outright, REGARDLESS of
 * property: `url(`, `expression(`, `@import`, `@charset`, a backslash
 * (CSS escape sequences, e.g. `\75 rl(` spelling "url(" past a naive
 * substring check — rejecting ANY backslash sidesteps decoding it), or
 * `</` (a tag-close breakout attempt). The `url(`/`expression(`/`@import`/
 * `@charset` check runs against a WHITESPACE-COLLAPSED copy so
 * `expression (…)` (space-inserted) cannot dodge the plain substring test —
 * the original value is untouched; only this ban-detection copy is collapsed.
 */
function ihymnsCssValueSafe(string $value): bool
{
    if (str_contains($value, '\\')) {
        return false;
    }
    $lower = strtolower($value);
    if (str_contains($lower, '</')) {
        return false;
    }
    $collapsed = (string)preg_replace('/\s+/', '', $lower);
    foreach (['url(', 'expression(', '@import', '@charset'] as $bad) {
        if (str_contains($collapsed, $bad)) {
            return false;
        }
    }
    return true;
}

/**
 * `ihymnsCssValueSafe()` plus the handful of properties whose VALUE shape
 * is itself restricted: `position` to static|relative|absolute (never
 * `fixed`/`sticky` — no interactive-overlay shape), `display` to a small
 * enum, `filter` to `grayscale(<number-or-percent>)` only (ink-saver, §4.2).
 */
function ihymnsCssValueAllowedForProperty(string $prop, string $value): bool
{
    if (!ihymnsCssValueSafe($value)) {
        return false;
    }
    $prop = strtolower(trim($prop));
    $v    = trim($value);
    if ($prop === 'position') {
        return in_array(strtolower($v), IHYMNS_CSS_POSITION_ENUM, true);
    }
    if ($prop === 'display') {
        return in_array(strtolower($v), IHYMNS_CSS_DISPLAY_ENUM, true);
    }
    if ($prop === 'filter') {
        return preg_match('/^grayscale\(\s*(?:0|1|0?\.\d+|\d{1,3}%)\s*\)$/i', $v) === 1;
    }
    return true;
}

/**
 * Split a flat declaration-list string into `[propertyName, value]` pairs,
 * cutting on `;` ONLY outside quotes and parens (so `font-family:"A;B"` or
 * a `grayscale(1)` argument list is never mis-split) — §5.3's exact
 * instruction. A declaration missing a `:` is dropped (nothing to keep).
 *
 * @return list<array{0:string,1:string}>
 */
function ihymnsCssSplitDeclarations(string $css): array
{
    $len   = strlen($css);
    $buf   = '';
    $depth = 0;
    $quote = null;
    $decls = [];

    for ($i = 0; $i < $len; $i++) {
        $ch = $css[$i];
        if ($quote !== null) {
            $buf .= $ch;
            if ($ch === $quote && ($i === 0 || $css[$i - 1] !== '\\')) {
                $quote = null;
            }
            continue;
        }
        if ($ch === '"' || $ch === "'") {
            $quote = $ch;
            $buf  .= $ch;
            continue;
        }
        if ($ch === '(') {
            $depth++;
            $buf .= $ch;
            continue;
        }
        if ($ch === ')') {
            $depth = max(0, $depth - 1);
            $buf  .= $ch;
            continue;
        }
        if ($ch === ';' && $depth === 0) {
            $decls[] = $buf;
            $buf = '';
            continue;
        }
        $buf .= $ch;
    }
    if (trim($buf) !== '') {
        $decls[] = $buf;
    }

    $pairs = [];
    foreach ($decls as $d) {
        $d = trim($d);
        if ($d === '') {
            continue;
        }
        $colonPos = strpos($d, ':');
        if ($colonPos === false) {
            continue;
        }
        $prop = trim(substr($d, 0, $colonPos));
        $val  = trim(substr($d, $colonPos + 1));
        if ($prop === '' || $val === '') {
            continue;
        }
        $pairs[] = [$prop, $val];
    }
    return $pairs;
}

/**
 * A stylesheet SELECTOR is safe only if it is built ENTIRELY from element
 * names, class tokens (`.foo`), the universal selector (`*`), a descendant
 * combinator (bare whitespace) and comma-separated lists — the character
 * class below structurally excludes attribute selectors (`[…]`), id
 * selectors (`#…`), pseudo-classes/elements (`:…`), combinators
 * (`> + ~`), and any at-rule marker (`@`). Matches exactly the shapes
 * `printCss()` emits (print.js L380-403): `*`, `body`, `.print-title`,
 * `.lyric-chorus .print-line, .lyric-refrain .print-line`, …
 */
function ihymnsCssSelectorSafe(string $selector): bool
{
    $selector = trim($selector);
    if ($selector === '') {
        return false;
    }
    return preg_match('/^[A-Za-z0-9 \t\r\n_.,*-]+$/', $selector) === 1;
}

/**
 * Find the first occurrence of `$char` at bracket/quote depth 0, starting
 * at `$from`. Quote-aware so a `{`/`}` inside a quoted selector/value
 * fragment is never mistaken for a structural brace.
 *
 * @return int|false byte offset, or false if not found.
 */
function ihymnsCssFindTopLevelChar(string $css, string $char, int $from)
{
    $len   = strlen($css);
    $quote = null;
    for ($i = $from; $i < $len; $i++) {
        $ch = $css[$i];
        if ($quote !== null) {
            if ($ch === $quote && ($i === 0 || $css[$i - 1] !== '\\')) {
                $quote = null;
            }
            continue;
        }
        if ($ch === '"' || $ch === "'") {
            $quote = $ch;
            continue;
        }
        if ($ch === $char) {
            return $i;
        }
    }
    return false;
}

/**
 * Given the offset of an OPENING `{`, find its matching `}` — tracking
 * nesting depth (so an `@media print { .foo { … } }` body is captured
 * whole) and quote state (so a `}` inside a quoted value is never mistaken
 * for the close).
 *
 * @return int|false byte offset of the matching `}`, or false if unterminated.
 */
function ihymnsCssFindMatchingBrace(string $css, int $openIdx)
{
    $len   = strlen($css);
    $depth = 0;
    $quote = null;
    for ($i = $openIdx; $i < $len; $i++) {
        $ch = $css[$i];
        if ($quote !== null) {
            if ($ch === $quote && ($i === 0 || $css[$i - 1] !== '\\')) {
                $quote = null;
            }
            continue;
        }
        if ($ch === '"' || $ch === "'") {
            $quote = $ch;
            continue;
        }
        if ($ch === '{') {
            $depth++;
            continue;
        }
        if ($ch === '}') {
            $depth--;
            if ($depth === 0) {
                return $i;
            }
        }
    }
    return false;
}
