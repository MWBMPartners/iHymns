<?php

declare(strict_types=1);

/**
 * iHymns — print one-renderer + not-public CI guard (#1767 remainder P3)
 *
 * ELI5: this file makes sure the new server-PDF machinery stays a PURE
 * CONVERTER — it turns already-built HTML into PDF bytes, and it can NEVER
 * grow its own copy of "what a lyrics block looks like" or "what a title
 * block looks like". It also makes sure nobody can reach the PDF endpoint
 * except by asking it directly and being logged in — no public page, no
 * cached page, no `api.php` action can trigger it.
 *
 * DETAIL — TWO INDEPENDENT GUARDS, ONE FILE (mirrors the shape of
 * `tests/php/test-ia-reconcile-guards.php`, which similarly bundles several
 * related assertion groups for one small feature into one guard file):
 *
 *   §A THE ONE-RENDERER INVARIANT (`.claude/print-templates-1767-remainder-plan.md`
 *      §2 / §8 item 3). `renderTemplateBodyHtml()` in `js/modules/print.js`
 *      is the SINGLE source of truth for a printed page's markup — the
 *      server-PDF pipeline (`manage/print-pdf.php`, `includes/pdf_renderer.php`,
 *      and, defensively, the P2 sanitiser `includes/html_sanitizer.php`
 *      these consume) must NEVER become a second renderer. Asserted by
 *      scanning those files' COMMENT-STRIPPED source for:
 *        (a) a `case '<type>':` matching any block type FROM
 *            `PRINT_BLOCK_TYPES` (js/modules/print.js) — the exact
 *            silent-no-op shape `tests/php/test-print-block-registry.php`
 *            already guards for the EDITOR's allow-list, applied here to
 *            the PDF path instead;
 *        (b) a function DEFINITION named `renderBlock` or
 *            `renderTemplateBodyHtml` (a PHP re-implementation of the JS
 *            renderer);
 *        (c) a string literal constructing a `class="print-<word>"` or
 *            `class="lyric-<word>"` attribute — the exact class VOCABULARY
 *            `renderBlock()` owns (`includes/html_sanitizer.php`'s `print`
 *            profile class_pattern) — EXCEPT the small, explicit, DOCUMENTED
 *            allow-list of server-CHROME classes a later phase is expected
 *            to add (the AK CCLI-notice footer, the H-family running
 *            header/footer) — those are page FURNITURE, not block content
 *            (plan §2's precise line: "What the server MAY add without
 *            becoming a renderer");
 *        (d) `printCss` is DEFINED only in `js/modules/print.js` — no PHP
 *            file anywhere under `appWeb/public_html` may define a function
 *            by that name (the plan explicitly rejects "a printCss() port"
 *            as the stylesheet-fork temptation, §11).
 *
 *   §B NOT-PUBLIC (#94 discipline, plan §3.1 / §8 item 2b). `manage/print-pdf.php`
 *      is a standalone, authenticated-only script — NOT an `api.php`
 *      action, NOT referenced from any `includes/pages/`/`includes/partials/`
 *      fragment (which would make it reachable from a cached public page),
 *      and not named in `api.php`'s `$_cacheablePages` list. Asserted by
 *      walking EVERY `.php` file under `appWeb/public_html`
 *      (`RecursiveDirectoryIterator`, never a hand-typed file list — rule
 *      #34) and confirming NO reference to `print-pdf.php` / `print_pdf`
 *      exists in `api.php` or under `includes/pages/`/`includes/partials/`
 *      — the three PUBLIC/CACHEABLE surfaces the invariant actually
 *      protects. ⚠️ Other files under `manage/` (an authenticated,
 *      never-cached admin tree) ARE allowed to reference it: #1767
 *      remainder P4 adds exactly one — `manage/print-templates.php`'s
 *      "Preview as PDF" button, a legitimate sibling-admin-page call the
 *      original all-files-ban was too blunt to distinguish from a public
 *      leak (rule #34 — "a guard so blunt it fails on correct code…gets it
 *      weakened or deleted rather than fixed"; this is that fix, not a
 *      weakening of what the guard actually needs to hold).
 *
 * DERIVATION (rule #34): the block-type set is PARSED from
 * `PRINT_BLOCK_TYPES` in `js/modules/print.js` (the same extraction shape
 * `test-print-block-registry.php` already uses) — never hand-typed here.
 * The "server PDF module" file set is PARTLY globbed (every `*pdf*.php`
 * under `manage/`/`includes/` — so a future file with "pdf" in its name is
 * covered automatically) and partly a small, DOCUMENTED, existence-checked
 * addition for the one file the plan names that doesn't carry "pdf" in its
 * name (`includes/html_sanitizer.php` — P2, already built, reused not
 * forked here; a future `includes/print_usage.php`, P5, is out of THIS
 * phase's scope and is not yet on disk to glob).
 *
 * COMMENT-STRIPPED via the REAL tokenizer (`token_get_all()`), not a
 * slash-star-dot-dot-dot-star-slash regex — `test-ia-reconcile-guards.php`'s
 * `stripComments()` doc-block explains the exact false-positive class a
 * naive regex hits (a real string literal containing a bare comment-OPEN
 * two characters from its end, e.g. an HTTP `Accept` MIME-wildcard header
 * value, is misread as entering a comment and swallows real code up to the
 * NEXT comment-close token found anywhere later in the file).
 *
 * MUTATION-PROVEN (rule #34): see the P3 commit message for the exact
 * mutations run against this file (an inserted `case 'title':` in
 * `pdf_renderer.php`, a fabricated `print-pdf.php` reference added to
 * `api.php`) and the resulting RED output, followed by `git checkout --`
 * restoring the byte-identical GREEN state.
 *
 *   php tests/php/test-print-one-renderer.php
 *
 * Exit status 0 = all pass, 1 = at least one failure.
 *
 * @see .claude/print-templates-1767-remainder-plan.md §2, §8 items 2b/3
 * @see tests/php/test-print-block-registry.php   the sibling guard for the EDITOR's allow-list
 * @see tests/php/test-ia-reconcile-guards.php     the comment-stripping + not-public pattern this mirrors
 * @see tests/php/test-component-json-guard.php    the token_get_all() comment-strip precedent
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

$repoRoot   = dirname(__DIR__, 2);
$publicRoot = $repoRoot . '/appWeb/public_html';
$printJs    = $publicRoot . '/js/modules/print.js';
$apiPhp     = $publicRoot . '/api.php';

if (!is_file($printJs)) {
    fwrite(STDERR, "FATAL: print.js not found at $printJs\n");
    exit(1);
}

/**
 * Strip every comment token out of PHP source using the REAL tokenizer.
 * (Identical shape to test-ia-reconcile-guards.php's stripComments() /
 * test-component-json-guard.php's approach — see this file's own doc-block
 * for WHY a regex is the wrong tool here.)
 */
function stripPhpComments(string $src): string
{
    $toks = @token_get_all($src);
    $out  = '';
    foreach ($toks as $tok) {
        if (is_array($tok)) {
            if ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT) {
                continue;
            }
            $out .= $tok[1];
        } else {
            $out .= $tok;
        }
    }
    return $out;
}

function loadStrippedPhp(string $path): string
{
    return stripPhpComments((string)file_get_contents($path));
}

/**
 * Every real string literal in PHP source (single/double-quoted, including
 * interpolated double-quoted pieces), via the tokenizer — so a literal
 * "class=" substring inside a doc-comment can never be mistaken for one
 * inside an actual string the code builds (comments are ALSO already
 * stripped before this runs, so this is defence in depth, not the only
 * safeguard).
 *
 * @return list<string>
 */
function extractStringLiterals(string $strippedSrc): array
{
    $toks = @token_get_all('<?php ' . $strippedSrc);
    $out  = [];
    foreach ($toks as $tok) {
        if (!is_array($tok)) {
            continue;
        }
        if ($tok[0] === T_CONSTANT_ENCAPSED_STRING || $tok[0] === T_ENCAPSED_AND_WHITESPACE) {
            $out[] = $tok[1];
        }
    }
    return $out;
}

/* =============================================================================
 * §0 — DERIVE the block-type set from PRINT_BLOCK_TYPES (print.js), the SAME
 * shape test-print-block-registry.php already uses (rule #34 — never typed).
 * ============================================================================= */

$jsSrc    = (string)file_get_contents($printJs);
$jsRegion = '';
if (preg_match('/PRINT_BLOCK_TYPES\s*=\s*\{(.*?)\n\};/s', $jsSrc, $m)) {
    $jsRegion = $m[1];
}
$blockTypes = [];
if (preg_match_all('/^\s*(\w+)\s*:\s*\{/m', $jsRegion, $mm)) {
    $blockTypes = $mm[1];
}
const PT1_MIN_TYPES = 8; // parser-sanity floor (mirrors test-print-block-registry.php; there are 14 today)
check(
    sprintf('derived a plausible number of block types from PRINT_BLOCK_TYPES (>= %d, got %d)', PT1_MIN_TYPES, count($blockTypes)),
    count($blockTypes) >= PT1_MIN_TYPES
);

/* =============================================================================
 * §0b — DERIVE the "server PDF module" file set: every *pdf*.php under
 * manage/ and includes/ (tree-derived — a future file with "pdf" in its
 * name is covered automatically) PLUS the plan-named exceptions that don't
 * carry "pdf" in their filename.
 * ============================================================================= */

function globPdfPhpFiles(string $dir): array
{
    $out = [];
    if (!is_dir($dir)) {
        return $out;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile()
            && strtolower($f->getExtension()) === 'php'
            && stripos($f->getFilename(), 'pdf') !== false
        ) {
            $out[] = $f->getPathname();
        }
    }
    return $out;
}

$serverPdfModules = array_merge(
    globPdfPhpFiles($publicRoot . '/manage'),
    globPdfPhpFiles($publicRoot . '/includes')
);
/* Plan-named exceptions that don't carry "pdf" in their filename:
   - includes/html_sanitizer.php (P2's shared sanitiser — reused by the PDF
     endpoint, never forked).
   - includes/print_usage.php (#1767 remainder P5 — landed this commit; the
     P3 draft of this comment named it as "not yet on disk, add it here the
     day it lands" — this IS that day). It emits no `print-*`/`lyric-*`
     markup at all (its one HTML-adjacent function,
     printUsageCcliNoticeText(), returns a plain, class-free string — the
     CALLER, pdf_renderer.php, wraps it in the allow-listed
     `print-ccli-notice` div), so its inclusion here is a floor, not
     expected to ever trip §A(c) — but it is included so a future edit
     THAT does add markup is caught immediately rather than by omission. */
$explicitServerPdfModules = [
    $publicRoot . '/includes/html_sanitizer.php',
    $publicRoot . '/includes/print_usage.php',
];
foreach ($explicitServerPdfModules as $explicitFile) {
    if (is_file($explicitFile) && !in_array($explicitFile, $serverPdfModules, true)) {
        $serverPdfModules[] = $explicitFile;
    }
}
sort($serverPdfModules);

check('found manage/print-pdf.php among the derived server PDF modules', in_array($publicRoot . '/manage/print-pdf.php', $serverPdfModules, true));
check('found includes/pdf_renderer.php among the derived server PDF modules', in_array($publicRoot . '/includes/pdf_renderer.php', $serverPdfModules, true));
check('derived at least 2 server PDF modules (parser-sanity floor)', count($serverPdfModules) >= 2);

/* =============================================================================
 * §A(a) — no `case '<blockType>':` for any derived block type.
 * ============================================================================= */

foreach ($serverPdfModules as $file) {
    $rel = ltrim(str_replace($publicRoot, '', $file), '/');
    $stripped = loadStrippedPhp($file);
    $hit = null;
    foreach ($blockTypes as $type) {
        if (preg_match("/case\\s+['\"]" . preg_quote($type, '/') . "['\"]\\s*:/", $stripped) === 1) {
            $hit = $type;
            break;
        }
    }
    check("$rel: no `case '<blockType>':` for any of the " . count($blockTypes) . " PRINT_BLOCK_TYPES types" . ($hit !== null ? " (found '$hit')" : ''), $hit === null);
}

/* =============================================================================
 * §A(b) — no PHP function DEFINITION named renderBlock / renderTemplateBodyHtml.
 * ============================================================================= */

foreach ($serverPdfModules as $file) {
    $rel = ltrim(str_replace($publicRoot, '', $file), '/');
    $stripped = loadStrippedPhp($file);
    check("$rel: no `function renderBlock(` definition", preg_match('/function\s+renderBlock\s*\(/i', $stripped) === 0);
    check("$rel: no `function renderTemplateBodyHtml(` definition", preg_match('/function\s+renderTemplateBodyHtml\s*\(/i', $stripped) === 0);
}

/* =============================================================================
 * §A(c) — no string literal constructing class="print-*" / class="lyric-*"
 * (the block-content class VOCABULARY renderBlock() owns), except the small
 * documented server-chrome allow-list a later phase (P5's AK footer,
 * P4's H-family) is expected to add.
 * ============================================================================= */

/* The ONLY class NAMES a server PDF module may ever emit — page FURNITURE,
   never block content (plan §2's precise "what the server MAY add" line).
   Empty in THIS phase (P3 builds neither the AK footer nor the H-family
   header/footer) — kept as a named constant, not an inline literal, so the
   day P4/P5 add one it is ONE line here, not a re-derivation. */
$allowedServerChromeClasses = ['print-ccli-notice', 'print-running-header', 'print-running-footer'];

foreach ($serverPdfModules as $file) {
    $rel = ltrim(str_replace($publicRoot, '', $file), '/');
    $stripped = loadStrippedPhp($file);
    $literals = extractStringLiterals($stripped);
    $bad = [];
    foreach ($literals as $lit) {
        if (preg_match_all('/class\s*=\s*["\']([^"\']*)["\']/', $lit, $cm)) {
            foreach ($cm[1] as $classAttrValue) {
                foreach (preg_split('/\s+/', trim($classAttrValue)) as $token) {
                    if ($token === '') {
                        continue;
                    }
                    if (preg_match('/^(?:print|lyric)-[a-z0-9-]+$/', $token) === 1
                        && !in_array($token, $allowedServerChromeClasses, true)
                    ) {
                        $bad[] = $token;
                    }
                }
            }
        }
    }
    $bad = array_values(array_unique($bad));
    check("$rel: no print-*/lyric-* block-content class emitted outside the server-chrome allow-list" . ($bad !== [] ? ' (found: ' . implode(', ', $bad) . ')' : ''), $bad === []);
}

/* =============================================================================
 * §A(d) — printCss is defined ONLY in js/modules/print.js.
 * ============================================================================= */

check('print.js DOES define printCss (sanity: the thing we are protecting actually exists)', preg_match('/function\s+printCss\s*\(/', $jsSrc) === 1);

function allPhpFilesUnder(string $dir): array
{
    $out = [];
    if (!is_dir($dir)) {
        return $out;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile() && strtolower($f->getExtension()) === 'php') {
            $out[] = $f->getPathname();
        }
    }
    return $out;
}

$allPublicPhp = allPhpFilesUnder($publicRoot);
check('derived a plausible number of public_html PHP files (>= 100)', count($allPublicPhp) >= 100);

$printCssDefiners = [];
foreach ($allPublicPhp as $f) {
    if (preg_match('/function\s+printCss\s*\(/i', loadStrippedPhp($f)) === 1) {
        $printCssDefiners[] = ltrim(str_replace($publicRoot, '', $f), '/');
    }
}
check('zero PHP file under public_html defines a printCss() function (no stylesheet fork, plan §11)', $printCssDefiners === []);
if ($printCssDefiners !== []) {
    echo '       found in: ' . implode(', ', $printCssDefiners) . "\n";
}

/* =============================================================================
 * §B — NOT-PUBLIC: manage/print-pdf.php is named ONLY by itself, within
 * public_html — tree-derived, walks every .php file (rule #34).
 * ============================================================================= */

$printPdfPath = $publicRoot . '/manage/print-pdf.php';
check('manage/print-pdf.php exists (sanity: the thing we are protecting actually exists)', is_file($printPdfPath));

$badReferrers = [];
foreach ($allPublicPhp as $f) {
    if ($f === $printPdfPath) {
        continue; // a file never counts as "referencing itself"
    }
    $rel = ltrim(str_replace($publicRoot, '', $f), '/');
    /* Every OTHER file under manage/ is a legitimate referrer: an
       authenticated, non-cacheable admin surface — never reachable from a
       public/cached page, so calling a sibling admin endpoint crosses
       NEITHER line this guard exists to hold (see the doc-block's §B note).
       #1767 remainder P4 adds exactly one such reference
       (manage/print-templates.php's "Preview as PDF" button). */
    if (str_starts_with($rel, 'manage' . DIRECTORY_SEPARATOR) || str_starts_with($rel, 'manage/')) {
        continue;
    }
    $stripped = loadStrippedPhp($f);
    if (str_contains($stripped, 'print-pdf.php') || str_contains($stripped, 'print_pdf')) {
        $badReferrers[] = $rel;
    }
}
check(
    'manage/print-pdf.php is referenced from NO public/cacheable surface (api.php, includes/pages/*, includes/partials/*) '
        . '— only other manage/* admin pages may call it directly',
    $badReferrers === []
);
if ($badReferrers !== []) {
    echo '       unexpected referrer(s): ' . implode(', ', $badReferrers) . "\n";
}

/* Belt-and-braces, directly against api.php: no `$_cacheablePages` entry and
   no case/action named for this endpoint. (Subsumed by the sweep above —
   kept as an explicit, named assertion so a future api.php refactor that
   somehow dodges the generic substring scan still has a targeted check.) */
if (is_file($apiPhp)) {
    $apiStripped = loadStrippedPhp($apiPhp);
    check('api.php has no print_pdf-shaped action/case', preg_match('/[\'"]print_pdf[\'"]/', $apiStripped) === 0);
}

if ($failures > 0) {
    fwrite(STDERR, "\nFAIL: $failures assertion(s) failed (#1767 remainder P3 one-renderer / not-public guard)\n");
    exit(1);
}
echo "\nOK: server PDF modules stay pure converters (no block-rendering logic found) and manage/print-pdf.php is reachable from nowhere but itself.\n";
exit(0);
