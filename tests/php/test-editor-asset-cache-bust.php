<?php

declare(strict_types=1);

/**
 * iHymns — Editor local-asset cache-bust guard (#1950)
 *
 * ELI5: `index.php` (the main app) forces a returning browser to fetch fresh
 * copies of `app.css`/`app.js` after every deploy by appending `?v=<version>`
 * to their `<script>`/`<link>` tags — the query string changes, so the URL is
 * "new" and the browser's cache can't serve the stale bytes. The two
 * hand-rolled editor pages (`editor2.php`, `manage/editor/index.php`) mostly
 * copy that convention for their own local scripts/styles, but several tags
 * were plain `<script src="propresenter-export.js">` with no `?v=` at all.
 * A fix landed in that exact file (#1950 — the ProPresenter export) and a
 * returning curator's browser kept serving its cached pre-fix copy forever,
 * because nothing ever told it the file had changed. This guard makes sure
 * every LOCAL script/stylesheet tag on these two pages carries a cache-bust
 * query param, so that class of "the deploy shipped but nobody got it" bug
 * cannot silently regress here again.
 *
 * SCOPE (why these two files, not "every *.php under manage/"): editor2.php
 * and manage/editor/index.php are the two "bespoke `<head>`" editor entry
 * points (rule #36 in .claude/CLAUDE.md) that load their export scripts
 * (`vendor/protobuf.min.js`, `protos/pp7-proto-static.js`,
 * `propresenter-export.js`, `format-export.js`, `editor.js`) as classic
 * `<script src>` tags outside `head-libs.php`'s shared load order — the
 * exact place #1950 found the gap, and the exact place the task that added
 * this guard was scoped to. A local tag elsewhere in `/manage/*` that shares
 * this bug is a DIFFERENT finding (tracked separately if one turns up) —
 * widening this specific guard to the whole tree would risk failing on pages
 * nobody has audited yet, which is the "guard so blunt it fails on correct
 * code" trap rule #34 warns against.
 *
 * WHAT COUNTS AS "LOCAL" (the narrow part, rule #34): a `src=`/`href=` value
 * that does NOT start with `http://`, `https://`, or `//` (a same-origin
 * relative or root-relative path). A genuine CDN tag — none exist on these
 * two pages today (Bootstrap is loaded via the shared `bootstrap_assets.php`
 * emitter, rule #36) — is deliberately never flagged, so a legitimate
 * absolute URL can never make this guard fail on correct code.
 *
 * ALGORITHM (mirrors test-fragment-inline-scripts.php's shape — source-tree
 * scan, no DB, slots into the same CI lint step):
 *   1. Strip HTML `<!-- -->` and PHP `/* *\/` comments BEFORE matching, line
 *      count preserved. LOAD-BEARING, not a nicety: editor2.php:201 has the
 *      literal doc-comment text "these two <link>s were pinned to 5.3.3..."
 *      — matching the raw source would misreport that prose as a real tag.
 *   2. Match every `<script …>` tag that carries a `src=` attribute, and
 *      every `<link …>` tag whose `rel=` contains "stylesheet" and carries
 *      an `href=` attribute (a `<link rel="icon">`/manifest/etc. tag is not
 *      a cache-busted asset in this codebase and is correctly never
 *      matched — narrow on purpose).
 *   3. Attribute-value extraction uses a quote-aware backreference
 *      (`(["'])...\1`) rather than a `[^'"]` character class, because the
 *      PHP embedded inside these attributes (`<?= filemtime($_pubRoot .
 *      '/css/app.css') ?>`) itself contains single quotes inside a
 *      double-quoted attribute — a naive "stop at any quote" scan would
 *      truncate the match early and silently miss real violations.
 *   4. A LOCAL tag's URL value must contain the literal substring `?v=`.
 *      No opinion on what supplies the version (`filemtime()` — the
 *      convention this page already uses everywhere else, #1594 — or the
 *      per-deploy app version, `urlencode($assetVersion)`, per index.php's
 *      marketing-version+build-number cache-buster) — only that SOME
 *      cache-bust query param is present.
 *
 *   php tests/php/test-editor-asset-cache-bust.php
 *
 * Exit status 0 = every local tag is cache-busted, 1 = at least one is not.
 *
 * MUTATION PROOF (run by hand, recorded in the #1950 PR body):
 *   (i)   strip `?v=...` from editor2.php's `propresenter-export.js` tag
 *         (leave the bare filename) => RED, naming that exact file:line
 *   (ii)  restore it                                                => GREEN
 *   See the PR body for the actual before/after transcript.
 */

/**
 * Blank comment bodies while preserving line count (identical technique to
 * test-fragment-inline-scripts.php's stripCommentsPreservingLines() — kept
 * as a local copy rather than a shared require: this file is a standalone
 * CI probe like its sibling, and the two guards' comment grammars already
 * happen to coincide, not because one depends on the other).
 *
 * @param string $src Raw file contents.
 * @return string Same length in lines; comment bodies blanked.
 */
function cacheBustStripComments(string $src): string
{
    $src = preg_replace_callback('/<!--.*?-->/s', static function (array $m): string {
        return str_repeat("\n", substr_count($m[0], "\n"));
    }, $src);

    $src = preg_replace_callback('~/\*.*?\*/~s', static function (array $m): string {
        return str_repeat("\n", substr_count($m[0], "\n"));
    }, $src);

    return $src;
}

/**
 * Is this src/href value a same-origin LOCAL asset (as opposed to an
 * absolute/protocol-relative CDN URL, which is out of this guard's scope)?
 *
 * @param string $url The raw attribute value (may still contain embedded
 *                     PHP, e.g. "/css/app.css?v=<?= filemtime(...) ?>").
 */
function cacheBustIsLocalUrl(string $url): bool
{
    $url = trim($url);
    if ($url === '') { return false; }
    // Absolute (http:/https:) or protocol-relative (//host/...) — a real CDN
    // tag, never in scope for this project's own deploy cache-bust.
    if (preg_match('#^(https?:)?//#i', $url) === 1) { return false; }
    return true;
}

/**
 * Scan one file for local `<script src>` / `<link rel="stylesheet" href>`
 * tags missing a `?v=` cache-bust param.
 *
 * @return array<int, string> "relativepath:line  <tag text>" violation rows.
 */
function cacheBustScanFile(string $path, string $repoRoot): array
{
    $src = file_get_contents($path);
    if ($src === false) {
        return ["{$path}: could not read file"];
    }
    $stripped = cacheBustStripComments($src);
    $rel = ltrim(str_replace($repoRoot, '', $path), '/\\');

    $violations = [];

    // <script ...src="...">  — quote-aware backreference (see doc-block §3).
    if (preg_match_all('/<script\b[^>]*\bsrc\s*=\s*(["\'])(.*?)\1[^>]*>/is', $stripped, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[0] as $i => [$tagText, $offset]) {
            $url = $m[2][$i][0];
            if (!cacheBustIsLocalUrl($url)) { continue; }
            if (str_contains($url, '?v=')) { continue; }
            $line = substr_count($stripped, "\n", 0, $offset) + 1;
            $violations[] = sprintf('%s:%d  %s', $rel, $line, trim($tagText));
        }
    }

    // <link rel="stylesheet" ...href="...">  — same quote-aware extraction,
    // gated on rel= containing "stylesheet" so icon/manifest/etc links
    // (never cache-busted anywhere in this codebase) are never flagged.
    if (preg_match_all('/<link\b[^>]*>/is', $stripped, $lm, PREG_OFFSET_CAPTURE)) {
        foreach ($lm[0] as [$tagText, $offset]) {
            if (!preg_match('/\brel\s*=\s*(["\'])[^"\']*stylesheet[^"\']*\1/i', $tagText)) { continue; }
            if (!preg_match('/\bhref\s*=\s*(["\'])(.*?)\1/is', $tagText, $hm)) { continue; }
            $url = $hm[2];
            if (!cacheBustIsLocalUrl($url)) { continue; }
            if (str_contains($url, '?v=')) { continue; }
            $line = substr_count($stripped, "\n", 0, $offset) + 1;
            $violations[] = sprintf('%s:%d  %s', $rel, $line, trim($tagText));
        }
    }

    return $violations;
}

$repoRoot = dirname(__DIR__, 2);
$targets = [
    $repoRoot . '/appWeb/public_html/manage/editor/editor2.php',
    $repoRoot . '/appWeb/public_html/manage/editor/index.php',
];

$failures = [];
$scanned = 0;
foreach ($targets as $file) {
    if (!is_file($file)) {
        fwrite(STDERR, "FATAL: $file not found\n");
        exit(1);
    }
    $scanned++;
    foreach (cacheBustScanFile($file, $repoRoot) as $v) {
        $failures[] = $v;
    }
}

if ($failures) {
    fwrite(STDERR, "FAIL: local editor asset tag(s) with no `?v=` cache-bust param (#1950):\n\n");
    foreach ($failures as $f) { fwrite(STDERR, "  $f\n"); }
    fwrite(STDERR, "\nA local <script src> / <link rel=stylesheet href> with no cache-bust query\n");
    fwrite(STDERR, "param means a deploy that changes that file never reaches a returning\n");
    fwrite(STDERR, "browser's cache — the #1950 class of bug (a shipped fix that silently never\n");
    fwrite(STDERR, "takes effect). Append `?v=<?= filemtime(...) ?>` matching this page's own\n");
    fwrite(STDERR, "established convention (see the css/app.css tags a few lines above any\n");
    fwrite(STDERR, "flagged tag), or `?v=<?= urlencode(\$version) ?>` matching index.php's\n");
    fwrite(STDERR, "\$_appJsVersion convention if that source is more appropriate for the tag.\n");
    exit(1);
}

echo "PASS: every local editor asset tag carries a cache-bust param ({$scanned} files scanned).\n";
exit(0);
