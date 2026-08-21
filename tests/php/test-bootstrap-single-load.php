<?php

declare(strict_types=1);

/**
 * iHymns — Bootstrap JS/CSS single-load guard (#1858)
 *
 * ELI5: `admin-footer.php` already puts the Bootstrap JAVASCRIPT bundle on
 * every `/manage/*` page once, and `head-libs.php` already puts the
 * Bootstrap CSS on every `/manage/*` page once (rule #36 in .claude/CLAUDE.md
 * — `includes/bootstrap_assets.php` is the ONE source of the CDN tags). If a
 * page ALSO calls the shared emitter itself while still requiring the
 * partial that already emits it, the browser downloads and RUNS the bundle
 * twice. `ihymns_bootstrap_js_script()`/`ihymns_bootstrap_css_links()` are
 * pure, non-idempotent emitters — nothing stops a second call from printing
 * a second `<script>`/`<link>` tag — so a duplicate call is a duplicate load
 * every time the page renders, not a rare race.
 *
 * Detail: the concrete #1858 bug was `manage/editor/index.php` requiring
 * `admin-footer.php` (which emits the JS bundle at its own line 75) AND
 * separately calling `ihymns_bootstrap_js_script()` itself higher up the
 * page. The second execution re-registers Bootstrap's document-level
 * delegated listeners (the `data-bs-toggle="dropdown"` / `data-bs-dismiss`
 * handlers it attaches once on `document`), so every dropdown/modal open or
 * close fires its handler TWICE — the classic "click a dropdown item and it
 * fires twice" / "modal flashes and re-shows" symptom class.
 *
 * This guard checks BOTH directions of the same drift:
 *   1. JS:  a file that `require`s `admin-footer.php` must not ALSO call
 *      `ihymns_bootstrap_js_script(` itself.
 *   2. CSS: a file that `require`s `head-libs.php` must not ALSO call
 *      `ihymns_bootstrap_css_links(` itself.
 * It deliberately does NOT ban either helper call outright — the four
 * bespoke-`<head>` pages named in rule #36 (`manage/editor/index.php`,
 * `manage/editor/editor2.php`, `manage/editor/import2.php`,
 * `includes/channel_gate.php`) correctly call `ihymns_bootstrap_css_links()`
 * directly BECAUSE they don't include `head-libs.php` at all — banning the
 * call outright would fail correct code (rule #34: "keep a guard NARROW
 * enough not to fail on correct code"). The assertion is scoped to
 * CO-PRESENCE of the partial-require and the helper-call, never a blanket
 * ban on the helper call by itself.
 *
 * A third assertion pins the other end of the contract: `admin-footer.php`
 * itself must still contain EXACTLY ONE `ihymns_bootstrap_js_script(` call,
 * so a future "fix" that removes the footer's own canonical emit (instead
 * of removing the offending page's duplicate) fails loudly rather than
 * silently deleting Bootstrap from every `/manage/*` page.
 *
 * MECHANISM (why this isn't a regex-over-raw-source scan, #34/#35)
 * ------------------------------------------------------------------
 * A plain string search for "admin-footer.php" / "head-libs.php" /
 * "ihymns_bootstrap_js_script(" false-positives badly in this tree: dozens
 * of pages mention "admin-footer.php" in prose — inside HTML `<!-- -->`
 * doc-comments (e.g. `missing-numbers.php`: "<!-- Bootstrap JS loaded by
 * admin-footer.php -->"), inside PHP `/* … *\/` doc-blocks (e.g.
 * `admin-nav.php`, `ia-reconcile.php`), and this very commit's own new
 * comment on `manage/editor/index.php` names the filename in exactly that
 * way. An unstripped grep would count every one of those as "this file
 * requires admin-footer.php" and misfire.
 *
 * Rather than hand-writing an HTML-comment-stripping regex (the
 * `test-fragment-inline-scripts.php` approach — needed there because its
 * target, a literal `<script>` tag, IS markup), this guard uses PHP's own
 * tokenizer (`token_get_all()`, the `test-component-json-guard.php`
 * pattern) and restricts every match to REAL CODE token types:
 *   - "does this file require admin-footer.php / head-libs.php?" is
 *     answered by scanning ONLY `T_CONSTANT_ENCAPSED_STRING` /
 *     `T_ENCAPSED_AND_WHITESPACE` tokens (actual PHP string literals) for
 *     the filename. A `require __DIR__ . DIRECTORY_SEPARATOR . 'includes' .
 *     DIRECTORY_SEPARATOR . 'admin-footer.php';` statement's final
 *     concatenation segment IS one such string-literal token — matched. An
 *     HTML `<!-- admin-footer.php -->` comment is plain markup text inside a
 *     `T_INLINE_HTML` token, never a string literal — NOT matched, with no
 *     separate HTML-comment stripping pass needed. A PHP `/* … *\/` or `//`
 *     comment naming the file is a `T_COMMENT`/`T_DOC_COMMENT` token — also
 *     never a string literal — NOT matched. One filter, both comment forms
 *     excluded by construction.
 *   - "does this file call the helper function?" is answered by scanning
 *     ONLY `T_STRING` tokens (identifiers used as code, e.g. a function
 *     name at a call site) for an exact match on the function name, and
 *     confirming the next non-whitespace/non-comment token is `(` — i.e. it
 *     really is being CALLED, not merely named. A comment or string that
 *     merely mentions the function's name in prose is never a `T_STRING`
 *     token, so it can never be mistaken for a call.
 * This is stricter than a comment-stripped regex would be (it can't be
 * fooled by the function name appearing inside a string constant either)
 * and needed no bespoke HTML-comment stripper to write or maintain.
 *
 * SCOPE (tree-derived, rule #34): every `*.php` under
 * `appWeb/public_html/manage/` (recursive — mirrors
 * `test-component-json-guard.php`'s `RecursiveDirectoryIterator` scan) plus
 * `appWeb/public_html/includes/channel_gate.php` (the one bespoke-`<head>`
 * page for the public site's admin-gate wall, outside `manage/`, that also
 * legitimately calls `ihymns_bootstrap_css_links()` directly per rule #36).
 *
 * Pure source-tree scan — no DB — so it slots into the CI lint step:
 *
 *   php tests/php/test-bootstrap-single-load.php
 *
 * Exit status 0 = clean, 1 = at least one double-load / missing-emit finding.
 */

const GUARD_JS_HELPER  = 'ihymns_bootstrap_js_script';
const GUARD_CSS_HELPER = 'ihymns_bootstrap_css_links';
const GUARD_FOOTER_PARTIAL_NEEDLE   = 'admin-footer.php';
const GUARD_HEAD_LIBS_PARTIAL_NEEDLE = 'head-libs.php';

/**
 * Tokenise a PHP source string and return the three signals this guard
 * needs from it, each computed from the appropriate token type so a
 * doc-comment or HTML comment mentioning any of the four needles can never
 * be mistaken for the real thing (see the file-level MECHANISM doc-block).
 *
 * @param string $src Full contents of a `.php` file.
 * @return array{requiresFooter: bool, requiresHeadLibs: bool,
 *               jsHelperCallLines: int[], cssHelperCallLines: int[]}
 */
function bootstrapGuardScan(string $src): array
{
    $toks = @token_get_all($src);

    $requiresFooter    = false;
    $requiresHeadLibs   = false;
    $jsHelperCallLines  = [];
    $cssHelperCallLines = [];

    $n = count($toks);
    for ($i = 0; $i < $n; $i++) {
        $tok = $toks[$i];
        if (!is_array($tok)) {
            continue;   // a single-char token ('(', ';', '.', …) can't be any of our needles
        }
        [$id, $text, $lineNo] = [$tok[0], $tok[1], $tok[2]];

        // --- Signal 1/2: real PHP string literals only (never a comment,
        //     never raw HTML markup — see the file doc-block). ---
        if ($id === T_CONSTANT_ENCAPSED_STRING || $id === T_ENCAPSED_AND_WHITESPACE) {
            if (!$requiresFooter && strpos($text, GUARD_FOOTER_PARTIAL_NEEDLE) !== false) {
                $requiresFooter = true;
            }
            if (!$requiresHeadLibs && strpos($text, GUARD_HEAD_LIBS_PARTIAL_NEEDLE) !== false) {
                $requiresHeadLibs = true;
            }
            continue;
        }

        // --- Signal 3/4: a real call site — the identifier token IS the
        //     function name AND the next non-whitespace/non-comment token
        //     is '(' (i.e. it's being invoked, not just named in prose
        //     inside a string or comment, which are already excluded by
        //     token type at this point anyway). ---
        if ($id === T_STRING && ($text === GUARD_JS_HELPER || $text === GUARD_CSS_HELPER)) {
            $j = $i + 1;
            while ($j < $n && is_array($toks[$j]) && in_array($toks[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $j++;
            }
            $nextIsCall = $j < $n && $toks[$j] === '(';
            if ($nextIsCall) {
                if ($text === GUARD_JS_HELPER) {
                    $jsHelperCallLines[] = $lineNo;
                } else {
                    $cssHelperCallLines[] = $lineNo;
                }
            }
        }
    }

    return [
        'requiresFooter'     => $requiresFooter,
        'requiresHeadLibs'    => $requiresHeadLibs,
        'jsHelperCallLines'   => $jsHelperCallLines,
        'cssHelperCallLines'  => $cssHelperCallLines,
    ];
}

$repoRoot   = dirname(__DIR__, 2);
$publicRoot = $repoRoot . '/appWeb/public_html';
$manageDir  = $publicRoot . '/manage';
$channelGateFile = $publicRoot . '/includes/channel_gate.php';

if (!is_dir($manageDir)) {
    fwrite(STDERR, "FATAL: $manageDir not found\n");
    exit(1);
}
if (!is_file($channelGateFile)) {
    fwrite(STDERR, "FATAL: $channelGateFile not found\n");
    exit(1);
}

/* Tree-derived file list (rule #34) — every *.php under manage/, recursive,
   plus the one named sibling outside it. Never a hand-typed page list: the
   #1858 bug itself lived on a page nobody would have thought to list by
   hand if this guard had been written as a fixed enumeration. */
$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($manageDir, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if ($f->isFile() && strtolower($f->getExtension()) === 'php') {
        $files[] = $f->getPathname();
    }
}
$files[] = $channelGateFile;
sort($files);

$footerAbsPath = realpath($manageDir . '/includes/admin-footer.php');
if ($footerAbsPath === false) {
    fwrite(STDERR, "FATAL: manage/includes/admin-footer.php not found\n");
    exit(1);
}

$failures = [];
$scanned  = 0;
$footerJsCallCount = null;   // set once we scan admin-footer.php itself

foreach ($files as $file) {
    $src = file_get_contents($file);
    if ($src === false) {
        continue;
    }
    $scanned++;
    $rel = substr($file, strlen($repoRoot) + 1);

    $scan = bootstrapGuardScan($src);

    if ($scan['requiresFooter'] && $scan['jsHelperCallLines']) {
        foreach ($scan['jsHelperCallLines'] as $ln) {
            $failures[] = sprintf(
                '%s:%d  calls %s() AND the file also requires admin-footer.php (which already ' .
                'emits the bundle at its own line) — Bootstrap JS bundle loads twice',
                $rel,
                $ln,
                GUARD_JS_HELPER
            );
        }
    }

    if ($scan['requiresHeadLibs'] && $scan['cssHelperCallLines']) {
        foreach ($scan['cssHelperCallLines'] as $ln) {
            $failures[] = sprintf(
                '%s:%d  calls %s() AND the file also requires head-libs.php (which already ' .
                'emits the CSS <link>s) — Bootstrap CSS loads twice',
                $rel,
                $ln,
                GUARD_CSS_HELPER
            );
        }
    }

    if (realpath($file) === $footerAbsPath) {
        $footerJsCallCount = count($scan['jsHelperCallLines']);
    }
}

// Companion assertion: admin-footer.php is the ONE canonical emitter — it
// must carry EXACTLY one call, so a future "fix" that deletes the footer's
// own emit (instead of the duplicating page's) fails loudly rather than
// silently removing Bootstrap JS from every /manage/* page.
if ($footerJsCallCount === null) {
    $failures[] = 'manage/includes/admin-footer.php was not scanned (file-list bug in this guard)';
} elseif ($footerJsCallCount !== 1) {
    $failures[] = sprintf(
        'manage/includes/admin-footer.php calls %s() %d time(s), expected exactly 1 — ' .
        'either the canonical emit was removed (Bootstrap JS would vanish from every /manage/* ' .
        'page) or a second one was added there (same double-load bug, one level up)',
        GUARD_JS_HELPER,
        $footerJsCallCount
    );
}

if ($failures) {
    fwrite(STDERR, "FAIL: Bootstrap JS/CSS single-load guard violation(s) (#1858):\n\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  $f\n");
    }
    fwrite(STDERR, "\nihymns_bootstrap_js_script() / ihymns_bootstrap_css_links() (includes/bootstrap_assets.php,\n");
    fwrite(STDERR, "#1676) are pure, non-idempotent emitters. A page that already requires admin-footer.php\n");
    fwrite(STDERR, "(JS) or head-libs.php (CSS) must not ALSO call the matching helper itself — that is a\n");
    fwrite(STDERR, "second <script>/<link> tag on every render, and for the JS bundle specifically, a second\n");
    fwrite(STDERR, "execution that re-registers Bootstrap's delegated document listeners (dropdown/modal\n");
    fwrite(STDERR, "double-fire). Delete the page's own duplicate call; leave the partial's canonical emit\n");
    fwrite(STDERR, "alone. See .claude/CLAUDE.md rule #36 and the #1858 commit.\n");
    exit(1);
}

echo "PASS: no Bootstrap JS/CSS double-load found; admin-footer.php's canonical emit intact " .
     "({$scanned} files scanned).\n";
exit(0);
