<?php

declare(strict_types=1);

/**
 * iHymns — server-side PDF engine wrapper (#1767 remainder P3)
 *
 * ELI5: this file is the ONE place in the whole app that knows how to turn
 * finished, already-safe print HTML into PDF bytes. Nothing else is allowed
 * to know that trick — if we ever swap the PDF-drawing library for a
 * different one, this is the only file that changes.
 *
 * DETAIL
 * ------
 * `.claude/print-templates-1767-remainder-plan.md` §3.2 names this the "ONE
 * swappable engine wrapper", modelled on the same fail-soft shape as
 * `includes/cuercode_client.php` / `includes/intapps_client.php` (rule #22):
 * every failure mode returns `null` (or, for the availability probe,
 * `false`) — this file NEVER throws out to its caller and NEVER white-screens
 * a request. The RESOLVED engine (owner decision, 2026-08-05) is **mPDF
 * ~8.2**, vendored — outside every web docroot — at
 * `appWeb/private_html/lib/pdf/vendor/` (a sibling of `public_html`, the
 * same "non-web-served directory that deploys" `manage/setup-database.php`
 * already relies on for `private_html/editor`).
 *
 * DORMANT-503 DISCIPLINE (mirrors `qr.php`'s CueRCode gate): the vendored
 * mPDF tree is a large third-party dependency that a given install may not
 * yet carry (a fresh checkout before the vendoring step ran, or a deploy
 * that deliberately excludes it). `ihymnsPdfEngineAvailable()` is the ONE
 * place that answers "can we even try?" — `manage/print-pdf.php` calls it
 * BEFORE doing any sanitise/adapt work and answers HTTP 503 (no body) when
 * it is false, exactly like `qr.php` degrades to 503 when CueRCode isn't
 * configured. A caller then falls back to the browser Print path, which
 * never depends on this file at all.
 *
 * WHAT THIS FILE DOES — AND DOES NOT — RENDER
 * ---------------------------------------------
 * `ihymnsPdfRender()` accepts HTML that ALREADY came from the client's ONE
 * renderer (`renderTemplateBodyHtml()` in `js/modules/print.js`) and has
 * ALREADY been sanitised by the caller (`ihymnsSanitizeHtml($html, 'print')`,
 * `includes/html_sanitizer.php`) before it ever reaches this file. This
 * module performs three, and only three, kinds of work on that HTML/CSS:
 *
 *   1. CONVERT it to PDF bytes via mPDF — the whole reason this file exists.
 *   2. ADAPT it for mPDF's specific quirks (§4.2/§4.5 of the plan) — a
 *      literal, tiny, DOCUMENTED set of deterministic transforms (a 2-entry
 *      font-family map, a DOM rewrite of one known CSS shape into mPDF's
 *      own proprietary tag, and inlining the one image src shape the
 *      sanitiser ever lets through). NONE of this is block-rendering — it
 *      never inspects a block "type", never re-implements
 *      `renderTemplateBodyHtml()`, and structurally cannot add content that
 *      wasn't already in the POSTed HTML (`tests/php/test-print-one-renderer.php`
 *      is the mutation-proven guard that keeps this true).
 *   3. STAMP minimal, server-resolved PDF metadata (Title/Creator — AC) —
 *      strings, not markup.
 *
 * @see .claude/print-templates-1767-remainder-plan.md §3.2, §4.2, §4.5  the design this file implements EXACTLY
 * @see appWeb/public_html/manage/print-pdf.php     the ONE caller (the PDF endpoint)
 * @see appWeb/public_html/includes/html_sanitizer.php  sanitises the HTML/CSS BEFORE it reaches this file
 * @see appWeb/public_html/includes/cuercode_client.php the QR-image adapter's one dependency
 * @see appWeb/public_html/js/modules/print.js       the ONE HTML/CSS renderer this file never re-implements
 * @see tests/php/test-print-one-renderer.php        the mutation-proven "never a second renderer" guard
 * @link https://mpdf.github.io/                     mPDF documentation
 * @link https://github.com/mpdf/mpdf                mPDF source (GPL-2.0-only — see LICENSING.md)
 */

/* Side-effect-free to require (mirrors cuercode_client.php's own discipline):
   including this file opens no connection, spawns no process, and does not
   even attempt to load the mPDF autoloader — that happens lazily, exactly
   once, inside ihymnsPdfEngineAvailable(). getAppSetting()/cuercodeGenerate()
   (the QR-inlining adapter's one dependency, §4.5) come along for free. */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'cuercode_client.php';
/* #1830 — orgLogoFetchServeRow()/ihymnsOrgLogoKindKeys()/IHYMNS_ORG_LOGO_VARIANTS
   for _pdfInlineOrgLogo() below. db_mysql.php for getDbMysqli() — side-effect-free
   to require (mirrors this file's own cuercode_client.php discipline; no
   connection is opened until _pdfInlineOrgLogo() actually calls it). */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'org_logo_helpers.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'db_mysql.php';

/* =============================================================================
 * ENGINE AVAILABILITY (dormant-503 discipline)
 * ========================================================================== */

/**
 * Absolute path to the vendored mPDF Composer autoloader.
 *
 * ELI5: where the PDF-drawing toolbox lives on disk.
 *
 * DETAIL: `appWeb/private_html/` is a SIBLING of `appWeb/public_html/` (this
 * file's own `includes/` lives inside the latter), outside every web
 * docroot — `dirname(__DIR__, 2)` walks `includes/` → `public_html/` →
 * `appWeb/`, then descends into `private_html/lib/pdf/vendor/autoload.php`.
 * A plain function (not a `const`) because PHP's compile-time `const`
 * initialiser cannot call `dirname()`; this is cheap enough to not need
 * memoising on its own (the one caller, `ihymnsPdfEngineAvailable()`,
 * memoises the RESULT of using it).
 */
function _pdfVendorAutoloadPath(): string
{
    return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR
        . 'lib' . DIRECTORY_SEPARATOR . 'pdf' . DIRECTORY_SEPARATOR
        . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
}

/**
 * Is the mPDF engine usable on THIS install?
 *
 * ELI5: "do we even have the PDF toolbox here?" — the ONE question
 * `manage/print-pdf.php` asks before doing any other work, so a checkout
 * without the (large, third-party) vendored tree degrades to a clean 503
 * instead of a fatal `require` error.
 *
 * DETAIL: memoised per request (a PHP process never gains or loses the
 * vendored tree mid-request). Existence-guarded exactly like every other
 * "did the deploy step that isn't auto-applied actually run?" check in this
 * codebase (CLAUDE.md rule #19's migration-probe discipline, applied here to
 * a vendored dependency instead of a DB migration) — `is_file()` first, then
 * a guarded `require_once`, then a `class_exists()` sanity check so a
 * corrupted/partial vendor tree (autoloader present, classes missing) still
 * reports unavailable rather than fataling on first use.
 */
function ihymnsPdfEngineAvailable(): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $autoload = _pdfVendorAutoloadPath();
    if (!is_file($autoload)) {
        return $cached = false;
    }
    try {
        require_once $autoload;
    } catch (\Throwable $e) {
        error_log('[pdf_renderer] vendored autoload failed to load: ' . $e->getMessage());
        return $cached = false;
    }
    return $cached = class_exists(\Mpdf\Mpdf::class);
}

/* =============================================================================
 * §4.2 / §4.5 — THE CSS-FIDELITY ADAPTATION LAYER
 * (kept deliberately tiny — a documented set of literal transforms, never a
 * second renderer; see the file doc-block's "what this file does — and does
 * not — render")
 * ========================================================================== */

/**
 * The DejaVu font-family map (§4.2 point 1) — a LITERAL two-entry
 * substitution, not a CSS engine.
 *
 * ELI5: `printCss()` (js/modules/print.js) asks the browser for Georgia and
 * Courier New, two fonts mPDF doesn't ship. This swaps those two EXACT
 * strings for the closest DejaVu equivalents mPDF DOES ship inside its own
 * package (`vendor/mpdf/mpdf/ttfonts/DejaVuSerif*.ttf` /
 * `DejaVuSansMono*.ttf`) — no custom `fontDir` configuration needed, mPDF
 * resolves the family name to those files on its own.
 *
 * DETAIL: matched against `printCss()`'s TWO known literal font stacks —
 * `body { font-family: Georgia, 'Times New Roman', serif; }` and
 * `.print-chord`/`.print-permalink-url { font-family: 'Courier New',
 * monospace; }` — after `ihymnsSanitizeCss()` has already run (the sanitiser
 * lower-cases the PROPERTY name but preserves the VALUE verbatim, so the
 * two shapes below are exactly what survives sanitisation, modulo the
 * caller's own whitespace which this regex tolerates). Anything the client
 * did NOT ask for (a curator's future custom font-family) is left
 * untouched — mPDF's own `serif_fonts`/`mono_fonts` substitution list
 * (`vendor/mpdf/mpdf/src/Config/FontVariables.php`) already falls back to a
 * DejaVu variant for the bare CSS generic keywords `serif`/`monospace` if
 * every named font in a stack fails to resolve, so an unmapped stack still
 * degrades reasonably rather than erroring.
 *
 * VERIFIED against the vendored mPDF 8.3.x in this commit (both quoted
 * family names resolve correctly — see the P3 commit message for the smoke
 * test transcript); re-verify by hand on any future mPDF version bump.
 *
 * @param  string $css Already-sanitised stylesheet text (`ihymnsSanitizeCss()` output).
 * @return string Adapted stylesheet text.
 */
function _pdfAdaptCss(string $css): string
{
    if ($css === '') {
        return '';
    }
    $css = (string)preg_replace(
        '/font-family\s*:\s*Georgia\s*,\s*[\'"]Times New Roman[\'"]\s*,\s*serif/i',
        "font-family:'DejaVu Serif'",
        $css
    );
    $css = (string)preg_replace(
        '/font-family\s*:\s*[\'"]Courier New[\'"]\s*,\s*monospace/i',
        "font-family:'DejaVu Sans Mono'",
        $css
    );

    /* THIRD adaptation, discovered empirically (VERIFIED against the
       vendored mPDF 8.3.1 — see the P3 commit message for the full
       before/after page-count transcript): drop the `@page { size: … }`
       rule `printCss()` emits ENTIRELY. Two independent reasons converge on
       the same fix:
       1. It is REDUNDANT — `_pdfMpdfFormatForPageSize()` already sets the
          real page dimensions via the `Mpdf` CONSTRUCTOR's `format` option,
          which is what this vendored mPDF version actually honours (its
          `@page` CSS support did not change the rendered page box in
          testing, matching that function's own doc-block finding).
       2. It is ACTIVELY HARMFUL — combining ANY `@page { size: … }` rule
          with a substituted `font-family` (this file's OWN font map,
          immediately above) sent this vendored mPDF into a page-metrics
          runaway: a trivial one-line lyrics snippet inflated from 1 page to
          **104–111 pages**, reproduced identically whether the `@page` size
          MATCHED or MISMATCHED the constructor `format`, and regardless of
          whether the body carried an mPDF `<columns>` block at all — the
          font substitution + the `@page` rule ALONE were sufficient to
          trigger it. Removing the `@page` rule (this fix) restored the
          correct 1-page output in every combination re-tested.
       A future mPDF version bump MUST re-verify this bug is still present
       before this workaround is ever relaxed (a version where mPDF fixed
       the underlying page-metrics interaction could safely drop this
       strip and rely on `@page` again) — do not remove this without
       re-running the same before/after page-count check. */
    $css = (string)preg_replace('/@page\s*\{[^}]*\}/i', '', $css);

    return trim($css);
}

/**
 * Map the (already server-re-validated, `$PAGE_OPTION_SCHEMA`-coerced)
 * `pageOptions.pageSize` value to mPDF's `format` constructor option.
 *
 * ELI5: "what page size should the PDF be" — A4, US Letter or Legal.
 *
 * DETAIL: `printCss()` also emits a CSS `@page { size: … }` rule carrying
 * the SAME choice, which survives `ihymnsSanitizeCss()` (`size` is an
 * allow-listed property) — but empirically, against the vendored mPDF in
 * this commit, `@page { size: letter }` / `size: legal` parsed via
 * `WriteHTML(…, HTMLParserMode::HEADER_CSS)` did NOT change the rendered
 * page dimensions (mPDF's CSS `@page` support is narrower than the CSS
 * spec here), while passing `format` to the `Mpdf` CONSTRUCTOR reliably
 * produced the correct A4/Letter/Legal page box. This is therefore a
 * genuine, VERIFIED (not assumed) fidelity gap the adapter must close —
 * the CSS rule is harmless to also send (documentation/forward-compat if a
 * future mPDF version honours it) but the constructor option is what
 * actually governs the output in the vendored version.
 *
 * @param  mixed $pageSize 'A4' | 'Letter' | 'Legal' | anything else/absent.
 * @return string An mPDF `format` value ('A4' default for anything unknown).
 */
function _pdfMpdfFormatForPageSize($pageSize): string
{
    $map = ['A4' => 'A4', 'Letter' => 'LETTER', 'Legal' => 'LEGAL'];
    return $map[$pageSize] ?? 'A4';
}

/**
 * Resolve ONE `/qr.php?...` image src (the ONLY `<img src>` shape the
 * `print` sanitiser profile ever admits, `includes/html_sanitizer.php`) to
 * an inlined `data:` URI, by calling the SAME CueRCode client `/qr.php`
 * itself uses (`cuercodeGenerate()`, rule #38) — DIRECTLY, never by mPDF
 * self-requesting the host over HTTP (slow, fragile on shared hosting, and
 * a needless SSRF-shaped surface this file's own security posture would
 * then have to re-justify).
 *
 * ELI5: the printed HTML says "put the QR code picture here, fetched from
 * our own /qr.php address" — but mPDF isn't a web browser sitting on that
 * address, so instead of asking it to go fetch the picture over the
 * network, we generate the SAME picture directly (the same code /qr.php
 * itself would run) and hand mPDF the finished bytes baked right into the
 * HTML.
 *
 * @param  string $src The sanitised `<img src>` value (must already match `^/qr\.php\?`).
 * @return string|null A `data:<mime>;base64,<bytes>` URI, or null on ANY failure
 *                       (dormant CueRCode, malformed query, oversized/failed fetch) —
 *                       the caller then DROPS the `<img>` entirely (§4.5 — the
 *                       caption text beside it, always emitted by print.js, is
 *                       the fallback, same principle as the browser path).
 */
function _pdfInlineQrImage(string $src): ?string
{
    if (preg_match('#^/qr\.php\?#', $src) !== 1) {
        /* Reached today ONLY via a data:image/… src, which the caller
           (_pdfAdaptHtml()) already intercepts and skips before ever
           calling this function (#1767 remainder P7 — the 'layout'
           profile's other self-contained img_src shape) — kept as a
           defensive floor rather than trusted-by-construction, so ANY
           other unrecognised src that somehow reaches this function is
           refused, never passed through. */
        return null;
    }
    if (!function_exists('cuercodeGenerateCached')) {
        return null; // should be unreachable — required at the top of this file
    }
    $query = parse_url($src, PHP_URL_QUERY);
    if (!is_string($query) || $query === '') {
        return null;
    }
    parse_str($query, $params);
    $data = isset($params['data']) ? (string)$params['data'] : '';
    if ($data === '') {
        return null;
    }
    $opts = [
        'format' => isset($params['format']) ? (string)$params['format'] : 'svg',
        'size'   => isset($params['size']) ? (int)$params['size'] : 512,
        'ecc'    => isset($params['ecc']) ? (string)$params['ecc'] : 'M',
    ];
    /* #1920 — cached read-through: same call shape as before, but a repeat
       render of the SAME QR (a set-list PDF re-printed, a template preview
       re-generated) now hits tblQrCache instead of round-tripping CueRCode
       every single time mPDF needs this picture inlined. */
    $qr = cuercodeGenerateCached($data, $opts);
    if ($qr === null || empty($qr['bytes']) || empty($qr['mime'])) {
        return null; // CueRCode dormant/unreachable — caller drops the <img>
    }
    return 'data:' . $qr['mime'] . ';base64,' . base64_encode($qr['bytes']);
}

/**
 * §6.4 of the plan — inline an `/org-logo.php?org=…&kind=…[&variant=…]`
 * `<img src>` (the print `logo` block's own emission) as a `data:` URI,
 * mirroring `_pdfInlineQrImage()`'s "never mPDF self-requesting over HTTP"
 * doctrine exactly: this calls `orgLogoFetchServeRow()` DIRECTLY rather than
 * having mPDF fetch the URL itself — a needless SSRF-shaped surface, and
 * mPDF has no base URL configured for this render anyway. `kind`/`variant`
 * are re-validated against the ONE registry here (never trusted from the
 * query string alone — a crafted POST cannot probe an unvalidated column
 * value through this path any more than through `org-logo.php` itself). No
 * server-side KIND CHOICE happens here: the client's `renderBlock('logo')`
 * ladder resolution already baked the chosen kind into the src BEFORE the
 * HTML was POSTed (§6.3) — this function only resolves BYTES for a src the
 * client already decided on.
 *
 * @param  string $src The sanitised `<img src>` value (must already match `^/org-logo\.php\?`).
 * @return string|null A `data:<mime>;base64,<bytes>` URI, or null on ANY failure
 *                       (pre-migration install, no active logo, DB unreachable,
 *                       malformed query) — the caller then DROPS the `<img>`
 *                       entirely, same "absence renders as absence" principle
 *                       as the QR adapter and `org-logo.php` itself.
 */
function _pdfInlineOrgLogo(string $src): ?string
{
    if (preg_match('#^/org-logo\.php\?#', $src) !== 1) {
        return null; // defensive floor — see _pdfInlineQrImage()'s identical comment
    }
    $query = parse_url($src, PHP_URL_QUERY);
    if (!is_string($query) || $query === '') {
        return null;
    }
    parse_str($query, $params);
    $orgId   = isset($params['org']) ? (int)$params['org'] : 0;
    $kind    = isset($params['kind']) ? (string)$params['kind'] : '';
    $variant = isset($params['variant']) ? (string)$params['variant'] : 'default';
    if ($orgId <= 0
        || !in_array($kind, ihymnsOrgLogoKindKeys(), true)
        || !in_array($variant, IHYMNS_ORG_LOGO_VARIANTS, true)) {
        return null;
    }

    try {
        $db = getDbMysqli();
    } catch (\Throwable $e) {
        return null; // DB unreachable — degrade like a dormant install
    }
    if (!orgLogoTableExists($db)) {
        return null;
    }
    $row = orgLogoFetchServeRow($db, $orgId, $kind, $variant);
    if ($row === null || empty($row['ContentSanitised']) || empty($row['Mime'])) {
        return null; // no active logo for this (org, kind[, variant]) — caller drops the <img>
    }
    return 'data:' . $row['Mime'] . ';base64,' . base64_encode((string)$row['ContentSanitised']);
}

/**
 * Adapt ALREADY-SANITISED print HTML for mPDF (§4.2 point 2, §4.5, and
 * #1767 remainder P7 §7.1 point 4) — a SINGLE DOM pass performing three
 * deterministic, documented rewrites:
 *
 *   1. `<div class="print-lyrics" style="…columns:2…">` → the SAME div,
 *      now wrapping an mPDF-proprietary `<columns column-count="2">…
 *      </columns>` around its children (mPDF has no CSS multi-column
 *      support; this is its own tag for the same visual effect — VERIFIED
 *      against the vendored mPDF version in this commit, see the P3 commit
 *      message). A 1-column template never carries `columns:2` at all, so
 *      the cheap `str_contains()` pre-check below skips the whole DOM
 *      parse for the common case.
 *   2. Every `/qr.php?…`-sourced `<img>` — the ONE src shape BOTH sanitiser
 *      profiles admit that isn't self-contained (`includes/html_sanitizer.php`)
 *      — is either inlined via `_pdfInlineQrImage()` or REMOVED outright. An
 *      `<img>` is NEVER passed through to mPDF with its original
 *      (relative, unreachable-by-mPDF) `/qr.php?…` src: mPDF has no base
 *      URL configured for this render, so it would not resolve anyway, and
 *      removing it outright — rather than leaving a broken-image glyph —
 *      matches the "the caption text is the fallback" principle §4.5
 *      states for the browser path too.
 *   3. Every `data:image/…;base64,…`-sourced `<img>` — the OTHER src shape
 *      the endpoint's (now-widened, P7) 'layout' sanitiser profile admits,
 *      for a curator's own logo/background embedded IN their custom
 *      layout upload — is left COMPLETELY UNTOUCHED. mPDF resolves a
 *      `data:` URI directly (no network fetch, nothing to adapt), so
 *      routing it through `_pdfInlineQrImage()` (which only recognises
 *      `/qr.php?…` and returns null for anything else) would have silently
 *      DELETED every embedded image a custom layout carries — caught
 *      before it shipped by re-reading this function against the widened
 *      profile, not by a failing test (a `data:` `<img>` with no
 *      surrounding caption text has no "the URL beside it is the fallback"
 *      safety net the QR case relies on, so a silent drop here would be a
 *      real regression for feature E, not merely a divergence).
 *
 * WHY THIS IS NOT "A SECOND RENDERER": all three rewrites are STRUCTURAL
 * transforms of markup the client's `renderTemplateBodyHtml()` ALREADY
 * produced and the sanitiser ALREADY approved — this function adds no new
 * TEXT content, makes no block-type decision, and cannot be reached by any
 * HTML this file didn't receive from its caller. `tests/php/test-print-one-renderer.php`
 * is the mutation-proven guard that keeps this true (rule #35 — a
 * mechanism, not this paragraph).
 *
 * @param  string $html Already-sanitised print HTML — `manage/print-pdf.php`
 *                        calls `ihymnsSanitizeHtml($x, 'layout')` on every
 *                        document (P7 widened this from 'print'; 'layout' is
 *                        a strict superset, §7.1 point 4), so this may be a
 *                        plain block-rendered document OR one wrapped in a
 *                        curator's custom full-page layout.
 * @return string Adapted HTML, safe to hand to `Mpdf::WriteHTML()`.
 */
function _pdfAdaptHtml(string $html): string
{
    if ($html === '') {
        return '';
    }
    $needsColumns = str_contains($html, 'columns:2');
    $needsImgPass = str_contains($html, '<img');
    if (!$needsColumns && !$needsImgPass) {
        return $html; // common case (no 2-column lyrics block, no QR block) — skip the DOM parse entirely
    }

    $doc = new \DOMDocument('1.0', 'UTF-8');
    $prevErrorState = libxml_use_internal_errors(true);
    $doc->loadHTML(
        '<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>',
        LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
    );
    libxml_clear_errors();
    libxml_use_internal_errors($prevErrorState);

    $body = $doc->getElementsByTagName('body')->item(0);
    if ($body === null) {
        return $html; // couldn't parse — pass through; mPDF will attempt its own parse
    }

    if ($needsColumns) {
        $xpath = new \DOMXPath($doc);
        /* Exactly print.js's own emitted shape (print.js L208): a
           `print-lyrics` class TOKEN (never a substring of a longer class,
           hence the padded-space `contains()` idiom) carrying an inline
           `style` whose value contains the literal `columns:2` declaration
           (the '1'-column case never emits this substring at all). */
        $lyricsNodes = $xpath->query(
            '//*[contains(concat(" ", normalize-space(@class), " "), " print-lyrics ")'
            . ' and contains(@style, "columns:2")]'
        );
        if ($lyricsNodes !== false) {
            foreach ($lyricsNodes as $el) {
                /** @var \DOMElement $el */
                $wrapper = $doc->createElement('columns');
                $wrapper->setAttribute('column-count', '2');
                while ($el->firstChild !== null) {
                    $wrapper->appendChild($el->firstChild);
                }
                $el->appendChild($wrapper);
            }
        }
    }

    if ($needsImgPass) {
        foreach (iterator_to_array($doc->getElementsByTagName('img')) as $img) {
            /** @var \DOMElement $img */
            $src = $img->getAttribute('src');
            if ($src === '') {
                $img->parentNode?->removeChild($img);
                continue;
            }
            /* #1767 remainder P7 — a data: URI (only possible via the
               'layout' sanitiser profile, a custom layout's own embedded
               image) is left ALONE: mPDF resolves it directly, and running
               it through _pdfInlineQrImage() below would misread it as
               "not /qr.php?, so drop it" and silently delete it (see this
               function's own doc-block, point 3). */
            if (str_starts_with($src, 'data:image/')) {
                continue;
            }
            /* #1830 §6.4 — /org-logo.php?… srcs go through the org-logo
               resolver BEFORE the QR resolver (the two prefixes are
               mutually exclusive, so order is not load-bearing for
               correctness — kept in the plan's stated order for
               readability). Either resolver returning null means "no
               bytes for this src", and the shared fallthrough below drops
               the <img> — never a broken image in a PDF. */
            $dataUri = _pdfInlineOrgLogo($src);
            if ($dataUri === null) {
                $dataUri = _pdfInlineQrImage($src);
            }
            if ($dataUri !== null) {
                $img->setAttribute('src', $dataUri);
            } else {
                $img->parentNode?->removeChild($img);
            }
        }
    }

    $out = '';
    foreach (iterator_to_array($body->childNodes) as $node) {
        $piece = $doc->saveHTML($node);
        if ($piece !== false) {
            $out .= $piece;
        }
    }
    return $out;
}

/* =============================================================================
 * §4.3 — THE H-FAMILY: serverOnly PAGE FURNITURE (running header + page
 * numbers). Page FURNITURE, never block content — the mutation-proven guard
 * (tests/php/test-print-one-renderer.php §A(c)) allow-lists exactly the
 * class names these two helpers emit (`print-running-header`,
 * `print-running-footer`) alongside the #1767 remainder P5 `print-ccli-notice`
 * class. Both helpers are pure string-builders: no DB, no network, nothing
 * that can fail — the caller decides WHETHER to apply their output.
 * ========================================================================== */

/**
 * §4.3 (U/H-family) — build the mPDF running-HEADER HTML string from
 * SERVER-VALIDATED `$meta` + the already re-validated `runningHeader` page
 * option ('none' | 'title' | 'titleBook', `ptSanitisePageOptions()` output —
 * an unrecognised value is IMPOSSIBLE here, not merely unexpected). Returns
 * '' when the option is 'none'/absent, or `$meta` carries no title — never
 * leaves a blank header bar sitting on the page.
 *
 * ELI5: this is the small, quiet line printed along the very top margin of
 * every page — "Amazing Grace" or "Amazing Grace — Sample Hymnal" — built
 * from data the SERVER already trusts (the title/book strings the endpoint
 * capped and control-character-stripped into `$meta`), never from anything
 * inside the POSTed `bodyHtml`.
 *
 * DETAIL — WHY THIS IS PAGE FURNITURE, NOT A SECOND RENDERER: the ONLY
 * inputs are the coerced `runningHeader` enum and two already-capped
 * strings from `$meta` (`_pdfSanitiseMetaString()`, the SAME helper AC's
 * PDF-metadata stamp uses below) — never a block `type`, never anything
 * parsed out of `bodyHtml`. The emitted markup carries exactly ONE class,
 * `print-running-header`, the allow-listed server-chrome class
 * `tests/php/test-print-one-renderer.php` §A(c) exists to police.
 *
 * @param  array $pageOptions Already `ptSanitisePageOptions()`-coerced.
 * @param  array $meta        Already-capped request meta (the endpoint's `$safeMeta`).
 * @return string HTML for `Mpdf::SetHTMLHeader()`, or '' to leave the default (blank) header.
 */
function _pdfRunningHeaderHtml(array $pageOptions, array $meta): string
{
    $mode = $pageOptions['runningHeader'] ?? 'none';
    if (!in_array($mode, ['title', 'titleBook'], true)) {
        return '';
    }
    $title = _pdfSanitiseMetaString($meta['title'] ?? '');
    if ($title === '') {
        return '';
    }
    $bits = [$title];
    if ($mode === 'titleBook') {
        $book = _pdfSanitiseMetaString($meta['book'] ?? '', 120);
        if ($book !== '') {
            $bits[] = $book;
        }
    }
    $text = implode(' — ', $bits);
    return '<div class="print-running-header" style="font-size:9pt;color:#666;'
        . 'border-bottom:0.5pt solid #ccc;padding-bottom:2pt;">'
        . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</div>';
}

/**
 * §4.3 (U) — the page-number footer HTML mPDF stamps when the caller's
 * `pageNumbers` page option is true. `{PAGENO}`/`{nbpg}` are mPDF's OWN
 * documented footer placeholders, substituted internally by mPDF at output
 * time — never PHP string interpolation, so there is nothing here for a
 * crafted request to influence (the function takes no arguments at all).
 *
 * @link https://mpdf.github.io/headers-footers/headers-and-footers.html
 */
function _pdfPageNumberFooterHtml(): string
{
    return '<div class="print-running-footer" style="font-size:9pt;color:#666;text-align:center;">'
        . '{PAGENO} / {nbpg}</div>';
}

/* =============================================================================
 * §3.2 — HARDENED mPDF CONFIG + THE ONE CONVERT ENTRY POINT
 * ========================================================================== */

/**
 * Absolute path to mPDF's own scratch directory, created on demand.
 *
 * ELI5: mPDF needs somewhere on disk to keep temporary working files while
 * it draws a PDF. This gives it a folder of its own, inside the same
 * not-web-served tree the library itself lives in, instead of sharing the
 * system-wide temp folder every other process on the host also uses.
 *
 * DETAIL: falls back to `sys_get_temp_dir()` (mPDF's own upstream default)
 * if the preferred directory cannot be created/written — e.g. restrictive
 * shared-hosting permissions — so a permissions hiccup degrades to "works,
 * less tidily" rather than a fatal.
 */
function _pdfTempDir(): string
{
    $dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR
        . 'tmp' . DIRECTORY_SEPARATOR . 'mpdf';
    if (!is_dir($dir)) {
        @mkdir($dir, 0770, true);
    }
    if (is_dir($dir) && is_writable($dir)) {
        return $dir;
    }
    return sys_get_temp_dir();
}

/**
 * Trim, strip control characters, and length-cap a string bound for PDF
 * DOCUMENT METADATA (Title/Creator/…) — these are PDF INFO-DICTIONARY
 * strings, never rendered as markup, but still attacker-influenced (the
 * `meta.title` field rides in on the same POST body `manage/print-pdf.php`
 * otherwise sanitises as HTML) so a bare `(string)` cast is not enough.
 *
 * @param  mixed $v
 * @param  int   $max
 * @return string
 */
function _pdfSanitiseMetaString($v, int $max = 200): string
{
    $s = trim((string)$v);
    $s = (string)preg_replace('/[\x00-\x1F\x7F]/', '', $s); // strip control/NUL bytes
    return mb_substr($s, 0, $max);
}

/**
 * Convert already-sanitised print HTML(+CSS) to PDF bytes.
 *
 * ELI5: hand this the finished, safe print page(s) and get back the actual
 * PDF file as raw bytes — or `null` if anything at all went wrong, in which
 * case the caller (`manage/print-pdf.php`) turns that into an HTTP error
 * response rather than ever exposing a stack trace.
 *
 * DETAIL — CALLER CONTRACT (enforced by the caller, not re-validated here
 * beyond basic shape checks): `$html`/`$css` inside `$docs`/`$css` MUST
 * already be the output of `ihymnsSanitizeHtml($x, 'print')` /
 * `ihymnsSanitizeCss($x)` — this function performs NO sanitisation of its
 * own (that would be a second, divergent copy of the sanitiser's rules,
 * rule #35). `$pageOptions` MUST already be the output of
 * `ptSanitisePageOptions()` (`includes/print_template_schema.php`).
 *
 * @param  list<array{bodyHtml:string,meta?:array<string,mixed>,ccliNotice?:string}> $docs
 *         One (`song`/`preview`) or, since #1767 remainder P6, several
 *         (`batch` — a whole set-list/songbook) print documents to
 *         concatenate into ONE PDF. Each `bodyHtml` is one already-sanitised
 *         `renderTemplateBodyHtml()` output. `meta` (per-document — songId/
 *         title/lang/dir/book) and `ccliNotice` (per-document, already a
 *         ready-built, un-escaped string — see the loop below) drive that
 *         SPECIFIC document's own running header + CCLI footer; a document
 *         with neither key falls back to the request-level `$opts['meta']`
 *         (kept for a hypothetical caller that only ever sends one shared
 *         meta — `manage/print-pdf.php` itself always sets both keys on
 *         every document, single or batch, so this fallback is dormant for
 *         its actual caller).
 * @param  string $css          Already-sanitised `printCss()` output, shared by every doc.
 * @param  array  $pageOptions  Already re-validated `ptSanitisePageOptions()` output (may be []).
 * @param  array  $opts         Optional: `meta` (array{title?,songId?,...}) for PDF metadata (AC)
 *                               and as the per-document meta FALLBACK described above.
 * @return string|null PDF bytes (always starts `%PDF-`), or null on ANY failure.
 */
function ihymnsPdfRender(array $docs, string $css, array $pageOptions, array $opts = []): ?string
{
    if ($docs === []) {
        return null;
    }
    if (!ihymnsPdfEngineAvailable()) {
        return null; // caller maps this to HTTP 503 — never reached mPDF at all
    }

    try {
        $mpdf = new \Mpdf\Mpdf([
            'tempDir' => _pdfTempDir(),
            'format'  => _pdfMpdfFormatForPageSize($pageOptions['pageSize'] ?? null),

            /* #1908 Commit 7 — mPDF non-Latin AUTOFONT.
               ELI5: without these two flags, mPDF draws EVERY character with
               ONE font (DejaVu), and DejaVu simply has no glyphs for CJK,
               Arabic, Hebrew, Thai, or most other non-Latin scripts — those
               songs render as blank space where the lyrics should be. These
               two flags tell mPDF "look at each run of text, work out what
               SCRIPT it's actually written in, and pick one of your OWN
               already-shipped fonts for that script automatically".
               DETAIL:
               - `autoScriptToLang` — per text run, detect the Unicode SCRIPT
                 (Han, Hebrew, Arabic, Thai, …) and map it to a language code.
               - `autoLangToFont`   — map that language code to one of mPDF's
                 own vendored fonts (see `ttfonts/`): Sun-ExtA/B for CJK,
                 Garuda for Thai, KhmerOS for Khmer, Padauk for Burmese,
                 XB Riyaz / LateefRegOT for Arabic, TaameyDavidCLM for Hebrew,
                 UnBatang for Korean. NO new font files and NO custom
                 `fontDir` are added by this change — every one of those
                 fonts already ships inside the vendored
                 `private_html/lib/pdf/vendor/mpdf/mpdf/ttfonts/` tree; these
                 two flags only turn ON mPDF's own built-in resolver that
                 picks among them per script. The existing 2-entry DejaVu
                 family map in `_pdfAdaptCss()` above is untouched and still
                 applies first for the two Latin font substitutions it
                 handles (Georgia/Times → DejaVu Serif, Courier New → DejaVu
                 Sans Mono) — autofont only engages for scripts that map
                 doesn't already cover.
               ⚠️ INTERACTS WITH THE `@page`-STRIP WORKAROUND ABOVE
               (`_pdfAdaptCss()` — see its own doc-block at roughly :196-221):
               that `preg_replace` removes `printCss()`'s `@page { size: … }`
               rule because combining it with FONT SUBSTITUTION previously
               sent this vendored mPDF into a page-metrics runaway (a
               1-line snippet inflating to 104-111 pages). Turning on
               per-script font substitution here is the SAME class of change
               (font resolution), so that strip MUST stay in place — do NOT
               remove or relax it without re-running the exact before/after
               page-count check that workaround's doc-block describes.
               ONE-TIME CACHE COST: the FIRST render that actually uses a
               non-Latin script (in a given `tempDir`, `_pdfTempDir()` above)
               makes mPDF generate and cache that font's `ttfontdata` cache
               file — Sun-ExtA (CJK) is the largest of the vendored fonts, so
               that specific first render is measurably slower/heavier than
               steady-state; every later render in the same tempDir reuses
               the cache.
               @see #1908 (epic: full non-Latin Unicode support)
               @see .claude/unicode-nonlatin-1908-plan.md §7 (Commit 7 — this exact change)
               @link https://mpdf.github.io/reference/mpdf-functions/construct.html            mPDF constructor options (autoScriptToLang / autoLangToFont)
               @link https://mpdf.github.io/multi-language-support/language-support.html        mPDF's per-script automatic font selection */
            'autoScriptToLang' => true,
            'autoLangToFont'   => true,

            /* Everything else stays mPDF's own upstream default — its
               shipped DejaVu/Free* font set (vendor/mpdf/mpdf/ttfonts/) is
               used as-is; no custom fontDir is needed for the font map
               above to resolve. */
        ]);

        /* AC — minimal server-resolved PDF metadata. `meta` is caller
           (endpoint)-supplied, itself built from a server-fetched song row,
           never parsed out of the POSTed HTML (the plan's §2 "what the
           server MAY add" line). For a batch, this is the FIRST document's
           meta (manage/print-pdf.php) — good enough for a single PDF Info-
           dictionary Title/Author/Creator, which has no per-page concept at
           all (unlike the running header/footer below, which DOES vary per
           document). */
        $meta  = is_array($opts['meta'] ?? null) ? $opts['meta'] : [];
        $title = _pdfSanitiseMetaString($meta['title'] ?? '');
        $mpdf->SetTitle($title !== '' ? $title : 'iHymns');
        $mpdf->SetCreator('iHymns');
        $mpdf->SetAuthor('iHymns');

        $adaptedCss = _pdfAdaptCss($css);
        if ($adaptedCss !== '') {
            $mpdf->WriteHTML('<style>' . $adaptedCss . '</style>', \Mpdf\HTMLParserMode::HEADER_CSS);
        }

        /* T (onePerPage) — whether the SECOND and later documents (#1767
           remainder P6 — a batch routinely has several) start on a fresh
           page. Absent/true reproduces the only behaviour this pipeline has
           ever had (every prior document unconditionally AddPage()'d);
           explicit false is the forward-looking opt-out a set-list-as-one-
           continuous-flow render would want. */
        $onePerPage = !array_key_exists('onePerPage', $pageOptions) || (bool)$pageOptions['onePerPage'];
        $wroteAny = false;
        foreach ($docs as $i => $doc) {
            $bodyHtml = (string)($doc['bodyHtml'] ?? '');
            if ($bodyHtml === '') {
                continue;
            }

            /* §4.3 H-family (running header + page numbers) + §6.3 CCLI
               notice — PER-DOCUMENT page furniture (#1767 remainder P6,
               plan §4.4). mPDF's own documented technique for "varying
               headers and footers" mid-document is to re-call
               SetHTMLHeader()/SetHTMLFooter() between WriteHTML() calls —
               re-calling them here per document changes the page furniture
               going forward from exactly THAT document's own page(s), never
               retroactively touching a page already written for an earlier
               document in the same batch. A single-document request runs
               this exactly once (i === 0 below), reproducing the P3-P5
               behaviour byte-for-byte.
               @link https://mpdf.github.io/headers-footers/varying-headers-footers.html

               VERIFIED ORDERING QUIRK (this vendored mPDF version, tested
               with a 4-document batch + a natural-overflow single document —
               see the P6 commit message for the full before/after
               transcript): the HEADER must be set BEFORE the page it
               applies to is added (AddPage()), but the FOOTER must be set
               AFTER — setting both before AddPage() (the naive symmetric
               approach) silently DROPS document 0's footer specifically
               (it renders correctly for every later document, and for
               document 0 alone with no later AddPage() at all — the bug
               only manifests once a SUBSEQUENT AddPage()+SetHTMLFooter()
               pair exists). The fix that empirically produces the correct
               footer AND header on every page, for every document
               including the first, is: set the header, THEN AddPage()
               (document 0's own — see below), THEN set the footer, THEN
               WriteHTML(). Header and the page-number footer are
               independent knobs; the CCLI notice is a THIRD, separate
               footer source — all footer pieces are combined into ONE
               SetHTMLFooter() call per document, because mPDF's footer is
               a single slot and a second call would silently replace the
               first. */
            $docMeta = (is_array($doc['meta'] ?? null) && $doc['meta'] !== []) ? $doc['meta'] : $meta;
            $mpdf->SetHTMLHeader(_pdfRunningHeaderHtml($pageOptions, $docMeta));

            /* Document 0 ALWAYS gets its own explicit AddPage() call here —
               NOT left to mPDF's implicit "page 1 already exists" behaviour
               — because that explicit call is what makes the ordering fix
               above take effect for the very first page too (verified
               empirically; the implicit first page does not go through the
               same header/footer-capture step AddPage() performs). This
               does NOT insert a spurious blank page — mPDF's very first
               AddPage() call on a fresh instance becomes page 1, it does
               not create page 2 (verified: doc-count in equals page-count
               out for every batch size tested). Document 1+ still obeys
               `onePerPage` (T) exactly as before: a fresh page only when it
               is true (default); false means continuous flow, so no
               AddPage() call and the header/footer above take effect
               whenever the CURRENT page naturally advances next. */
            if (!$wroteAny || $onePerPage) {
                $mpdf->AddPage();
            }

            $footerParts = [];
            if (!empty($pageOptions['pageNumbers'])) {
                $footerParts[] = _pdfPageNumberFooterHtml();
            }
            /* #1767 remainder P5 (single doc) / P6 (per doc, batch) —
               server-ENFORCED CCLI notice. `$doc['ccliNotice']` (falling
               back to `$opts['ccliNotice']` for a hypothetical single-meta
               caller — manage/print-pdf.php always sets the per-document
               key, so this fallback is dormant for its actual caller) is a
               ready-built, already-esc'd-by-THIS-function string (the
               VALUE itself is resolved by the caller from ITS OWN
               qualifying licence + the song's REAL CCLI number — never
               trusts the POSTed HTML/meta for that). Absent/empty for
               every document without a qualifying licence or whose own
               song has no CCLI number, so this is a byte-identical no-op
               for the overwhelming majority of documents/renders. */
            $ccliNotice = trim((string)($doc['ccliNotice'] ?? ($opts['ccliNotice'] ?? '')));
            if ($ccliNotice !== '') {
                $footerParts[] = '<div class="print-ccli-notice" style="font-size:8pt;color:#666;text-align:center;">'
                    . htmlspecialchars($ccliNotice, ENT_QUOTES, 'UTF-8') . '</div>';
            }
            $mpdf->SetHTMLFooter(implode('', $footerParts));

            $mpdf->WriteHTML(_pdfAdaptHtml($bodyHtml), \Mpdf\HTMLParserMode::HTML_BODY);
            $wroteAny = true;
        }
        if (!$wroteAny) {
            return null; // every document was empty after sanitisation — nothing to render
        }

        $pdfBytes = $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
        if (!is_string($pdfBytes) || !str_starts_with($pdfBytes, '%PDF-')) {
            return null;
        }
        return $pdfBytes;
    } catch (\Throwable $e) {
        error_log('[pdf_renderer] render failed: ' . $e->getMessage());
        return null;
    }
}
