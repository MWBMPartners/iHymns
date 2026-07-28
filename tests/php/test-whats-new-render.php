<?php

declare(strict_types=1);

/**
 * iHymns — What's New markdown-lite render test (#1583)
 *
 * Exercises `markdown_lite.php`'s `markdownLiteRender()` (the escape-first
 * transform `includes/pages/whats-new.php` renders the deploy-time
 * CHANGELOG.md excerpt through) plus that page's extracted
 * `_whatsNewRenderExcerpt()` helper — the "missing file / never throws"
 * boundary the spec calls out separately from the escaping guarantees.
 *
 * A test that cannot fail is worthless (hard project rule). This file's
 * PR description records a deliberate red run: `markdownLiteRender()`
 * temporarily rewritten to a "transform-then-escape" variant (interpret
 * the markdown into real tags FIRST, `htmlspecialchars()` the whole
 * result SECOND) — exactly the regression the file's doc-block warns
 * against — with the captured red output, then restored.
 *
 *   php tests/php/test-whats-new-render.php
 *
 * Exit status 0 = all pass, 1 = a failure.
 *
 * @see appWeb/public_html/includes/markdown_lite.php
 * @see appWeb/public_html/includes/pages/whats-new.php
 */

require_once dirname(__DIR__, 2) . '/appWeb/public_html/includes/markdown_lite.php';

$fail = 0;
function ok(string $label, bool $cond): void
{
    global $fail;
    echo ($cond ? "  PASS  " : "  FAIL  ") . $label . "\n";
    if (!$cond) { $fail++; }
}

/* =========================================================================
 * markdownLiteRender() — escape-first is the load-bearing security model
 * ========================================================================= */

$scriptInjection = markdownLiteRender("Some text\n\n<script>alert(1)</script>\n\nmore text");
ok('a raw <script> tag never appears literally in the output', !str_contains($scriptInjection, '<script>'));
ok(
    'the <script> tag renders as inert escaped text instead',
    str_contains($scriptInjection, '&lt;script&gt;alert(1)&lt;/script&gt;')
);

$imgOnError = markdownLiteRender('<img src=x onerror=alert(1)>');
ok('a raw <img onerror=...> tag never appears literally in the output', !str_contains($imgOnError, '<img '));
ok('the <img> tag renders as inert escaped text instead', str_contains($imgOnError, '&lt;img'));

/* Escaping must win even INSIDE constructs the renderer DOES interpret
   (bold/code/headings) — the markdown syntax characters (**, `, ##) are
   still acted on, but any literal HTML nested inside them must not
   become a live tag. */
$boldWithHtml = markdownLiteRender('**<b>not a real bold tag</b>**');
ok(
    'HTML nested inside a **bold** span is escaped, not live',
    str_contains($boldWithHtml, '&lt;b&gt;') && !str_contains($boldWithHtml, '<b>')
);
$headingWithHtml = markdownLiteRender('## <svg onload=alert(1)>');
ok(
    'HTML nested inside a ## heading is escaped, not live',
    str_contains($headingWithHtml, '&lt;svg') && !str_contains($headingWithHtml, '<svg')
);

/* =========================================================================
 * [text](url) — scheme allow-list (http/https only)
 * ========================================================================= */

$jsLink = markdownLiteRender('[Click me](javascript:alert(1))');
ok('a javascript: link never becomes a clickable <a> tag', !str_contains($jsLink, '<a '));
ok('the javascript: url text remains present but inert (already escaped)', str_contains($jsLink, 'javascript:alert(1)'));

$dataLink = markdownLiteRender('[Click me](data:text/html,%3Cscript%3Ealert(1)%3C/script%3E)');
ok('a data: link never becomes a clickable <a> tag', !str_contains($dataLink, '<a '));

$protocolRelative = markdownLiteRender('[Click me](//evil.example.com/x)');
ok('a protocol-relative //host link never becomes a clickable <a> tag (not on the allow-list)', !str_contains($protocolRelative, '<a '));

$httpsLink = markdownLiteRender('[the repo](https://github.com/example/ihymns)');
ok(
    'a plain https:// link DOES become a clickable <a> tag',
    str_contains($httpsLink, '<a href="https://github.com/example/ihymns" rel="noopener" target="_blank">the repo</a>')
);

$httpLink = markdownLiteRender('[old link](http://example.com)');
ok(
    'a plain http:// link is also accepted',
    str_contains($httpLink, '<a href="http://example.com" rel="noopener" target="_blank">old link</a>')
);

$noopenerCheck = markdownLiteRender('[x](https://example.com)');
ok('every accepted link carries rel="noopener" (reverse-tabnabbing guard)', str_contains($noopenerCheck, 'rel="noopener"'));

/* =========================================================================
 * Everyday markdown subset renders as expected (CHANGELOG.md's actual shape)
 * ========================================================================= */

ok('## becomes an <h2>', markdownLiteRender('## Release 1.0') === "<h2>Release 1.0</h2>\n");
ok('### becomes an <h3>', markdownLiteRender('### Fixed') === "<h3>Fixed</h3>\n");
ok('**x** becomes <strong>x</strong>', str_contains(markdownLiteRender('this is **important**'), '<strong>important</strong>'));
ok('`x` becomes <code>x</code>', str_contains(markdownLiteRender('run `composer install`'), '<code>composer install</code>'));
ok(
    'a run of "- " lines becomes one <ul>',
    markdownLiteRender("- first\n- second\n- third") === "<ul>\n<li>first</li>\n<li>second</li>\n<li>third</li>\n</ul>\n"
);
ok(
    'consecutive non-blank lines join into one <p> (soft-wrap)',
    markdownLiteRender("line one\nline two") === "<p>line one line two</p>\n"
);
ok(
    'a blank line separates two <p> blocks',
    markdownLiteRender("first\n\nsecond") === "<p>first</p>\n<p>second</p>\n"
);
ok(
    'a heading between two bullet runs starts a fresh <ul> rather than merging them',
    markdownLiteRender("- a\n## Mid\n- b") === "<ul>\n<li>a</li>\n</ul>\n<h2>Mid</h2>\n<ul>\n<li>b</li>\n</ul>\n"
);
ok('an empty string renders as empty output', markdownLiteRender('') === '');
ok('whitespace-only input renders as empty output', markdownLiteRender("   \n\t\n  ") === '');

/* =========================================================================
 * H1 (adversarial-review finding, OBS-VERIFY.md) — malformed UTF-8 must
 * NEVER silently blank the whole rendered card.
 *
 * `.github/workflows/deploy.yml`'s "Extract What's New from CHANGELOG.md"
 * step hard-caps the excerpt with `head -c $WHATS_NEW_MAX_BYTES` — a BYTE
 * boundary that can (and, on the real CHANGELOG.md at the time this was
 * found, reliably does on the very first deploy) land in the middle of a
 * multi-byte UTF-8 character. `markdownLiteRender()`'s first operation is
 * `htmlspecialchars($md, ..., 'UTF-8')`; without `ENT_SUBSTITUTE` in the
 * flags, PHP's `htmlspecialchars()` returns an EMPTY STRING for the whole
 * input the moment it hits malformed UTF-8 (not a warning, not a partial
 * result) — see markdown_lite.php's Step 1 comment for the full mechanism.
 *
 * PROVE-FAIL RECORD: before accepting this assertion, markdown_lite.php's
 * `htmlspecialchars($md, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')` call was
 * temporarily reverted to the exact pre-fix `htmlspecialchars($md,
 * ENT_QUOTES, 'UTF-8')` and this block re-run. It went RED:
 *
 *   string(0) ""
 *     FAIL  malformed UTF-8 does not blank the whole page (H1)
 *
 * i.e. markdownLiteRender() returned '' for genuinely non-empty,
 * non-whitespace input — precisely the silent-blank-card failure mode H1
 * describes. Restored immediately after (see the commit this test ships
 * in — the fix and the test land together).
 * ========================================================================= */

/* A truncated 3-byte UTF-8 sequence (the first 2 of 3 bytes of U+2014 EM
   DASH, 0xE2 0x80 0x94) with its final byte missing — exactly the shape a
   byte-oriented `head -c` cut leaves behind when it lands mid-character. */
$malformedUtf8 = "Fixed the thing \xE2\x80";
$malformedRender = markdownLiteRender($malformedUtf8);
ok('malformed UTF-8 does not blank the whole page (H1)', $malformedRender !== '');
ok(
    'malformed UTF-8 degrades to the Unicode replacement character rather than vanishing',
    str_contains($malformedRender, "\u{FFFD}")
);
ok(
    'the still-valid text around the truncated character survives the render',
    str_contains($malformedRender, 'Fixed the thing')
);

/* =========================================================================
 * _whatsNewRenderExcerpt() — the "missing file / never throws" boundary
 * includes/pages/whats-new.php relies on to show its fallback card.
 *
 * Requiring the page template DEFINES that function (so it can be called
 * directly below) and, as a side effect, renders the page once against
 * whatever the REAL `appWeb/public_html/data/whats-new.md` path happens to
 * hold in THIS checkout (present after a real deploy pipeline run;
 * git-ignored + absent otherwise — CLAUDE.md rule #19/#6).
 *
 * L5 (adversarial-review finding, OBS-VERIFY.md): this block used to
 * unconditionally `unlink()` that real path first "to make the assertion
 * deterministic" — a destructive side effect on a file this test does not
 * own, silently deleting a locally-generated excerpt outside any temp
 * directory. Removed: the block below only makes assertions that hold
 * EITHER way (no error/warning leaks) plus one assertion GATED on whether
 * the real file already existed before this test ran, and the synthetic-
 * path assertions further down cover the same "missing vs. present"
 * contract deterministically without touching real checkout state at all.
 * ========================================================================= */

$realWhatsNewFile = dirname(__DIR__, 2) . '/appWeb/public_html/data/whats-new.md';
$realFileExistedBeforeTest = is_file($realWhatsNewFile);

ob_start();
require_once dirname(__DIR__, 2) . '/appWeb/public_html/includes/pages/whats-new.php';
$pageOutput = (string)ob_get_clean();

ok('_whatsNewRenderExcerpt() is defined by the page template', function_exists('_whatsNewRenderExcerpt'));
ok(
    'the page render never leaks a PHP error/warning, regardless of whether a real deploy-generated excerpt is present in this checkout',
    !str_contains($pageOutput, 'Fatal error') && !str_contains($pageOutput, 'Warning:') && !str_contains($pageOutput, 'Deprecated:')
);
if ($realFileExistedBeforeTest) {
    echo "  SKIP  with no data/whats-new.md present, the page renders the fallback card text"
        . " (a real deploy-generated excerpt already exists in this checkout —"
        . " see the synthetic missing-file assertion below for deterministic coverage of the same contract)\n";
} else {
    ok(
        'with no data/whats-new.md present, the page renders the fallback card text',
        str_contains($pageOutput, 'Change details are published with each deploy.')
    );
}

/* Direct calls against synthetic paths — deterministic, independent of any
   real file that may or may not exist on disk, and never touch the real
   checkout path (the L5 fix above). */
$missingPath = sys_get_temp_dir() . '/ihymns-whats-new-test-' . bin2hex(random_bytes(8)) . '.md';
ok('the synthetic missing-file path really does not exist (test precondition)', !file_exists($missingPath));
ok('_whatsNewRenderExcerpt() on a missing file returns null, never throws', _whatsNewRenderExcerpt($missingPath) === null);

$emptyPath = tempnam(sys_get_temp_dir(), 'ihymns-whats-new-empty-');
file_put_contents($emptyPath, "   \n\t\n");
ok('_whatsNewRenderExcerpt() on a whitespace-only file also returns null', _whatsNewRenderExcerpt($emptyPath) === null);
unlink($emptyPath);

/* H1 end-to-end (adversarial-review finding): the SAME truncated-UTF-8
   input used against markdownLiteRender() directly above, this time routed
   through the full _whatsNewRenderExcerpt() boundary — proves the fix
   holds at the level includes/pages/whats-new.php actually calls, not just
   at the raw renderer. Uses a synthetic temp path (never the real
   checkout path — see the L5 fix above). */
$malformedUtf8Path = tempnam(sys_get_temp_dir(), 'ihymns-whats-new-malformed-');
file_put_contents($malformedUtf8Path, "## Release\n\n{$malformedUtf8}");
ok(
    '_whatsNewRenderExcerpt() on a file truncated mid-UTF-8-character still returns renderable HTML, not null (H1 end-to-end)',
    _whatsNewRenderExcerpt($malformedUtf8Path) !== null
);
unlink($malformedUtf8Path);

$realPath = tempnam(sys_get_temp_dir(), 'ihymns-whats-new-real-');
file_put_contents($realPath, "## Hello\n\nSome **bold** text.");
$realResult = _whatsNewRenderExcerpt($realPath);
ok(
    '_whatsNewRenderExcerpt() on a real file returns rendered HTML, not null',
    $realResult !== null && str_contains($realResult, '<h2>Hello</h2>') && str_contains($realResult, '<strong>bold</strong>')
);
unlink($realPath);

if ($fail === 0) {
    echo "\nAll What's New render assertions passed.\n";
    exit(0);
}
fwrite(STDERR, "\n{$fail} assertion(s) failed.\n");
exit(1);
