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
 * _whatsNewRenderExcerpt() — the "missing file / never throws" boundary
 * includes/pages/whats-new.php relies on to show its fallback card.
 *
 * Requiring the page template both DEFINES that function (so it can be
 * called directly below) and exercises its real top-of-file resolution
 * against the actual repo path. That path is deploy-generated + git-
 * ignored (CLAUDE.md rule #19/#6) — it should already be absent in a
 * checkout that never ran the deploy pipeline, but it is deleted first if
 * present so this assertion is deterministic rather than depending on
 * whatever a previous local run happened to leave behind.
 * ========================================================================= */

$realWhatsNewFile = dirname(__DIR__, 2) . '/appWeb/public_html/data/whats-new.md';
if (is_file($realWhatsNewFile)) {
    unlink($realWhatsNewFile);
}

ob_start();
require_once dirname(__DIR__, 2) . '/appWeb/public_html/includes/pages/whats-new.php';
$pageOutputNoFile = (string)ob_get_clean();

ok('_whatsNewRenderExcerpt() is defined by the page template', function_exists('_whatsNewRenderExcerpt'));
ok(
    'with no data/whats-new.md present, the page renders the fallback card text',
    str_contains($pageOutputNoFile, 'Change details are published with each deploy.')
);
ok(
    'the fallback render is a themed alert, not a PHP error/warning',
    !str_contains($pageOutputNoFile, 'Fatal error') && !str_contains($pageOutputNoFile, 'Warning:') && !str_contains($pageOutputNoFile, 'Deprecated:')
);

/* Direct calls against synthetic paths — deterministic, independent of any
   real file that may or may not exist on disk. */
$missingPath = sys_get_temp_dir() . '/ihymns-whats-new-test-' . bin2hex(random_bytes(8)) . '.md';
ok('the synthetic missing-file path really does not exist (test precondition)', !file_exists($missingPath));
ok('_whatsNewRenderExcerpt() on a missing file returns null, never throws', _whatsNewRenderExcerpt($missingPath) === null);

$emptyPath = tempnam(sys_get_temp_dir(), 'ihymns-whats-new-empty-');
file_put_contents($emptyPath, "   \n\t\n");
ok('_whatsNewRenderExcerpt() on a whitespace-only file also returns null', _whatsNewRenderExcerpt($emptyPath) === null);
unlink($emptyPath);

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
