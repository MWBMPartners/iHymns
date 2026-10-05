<?php

declare(strict_types=1);

/**
 * tests/php/test-html-comment-strip.php — what the comment filter removes and keeps
 *
 * ELI5: every page iHymns sends goes through ihymnsStripHtmlComments(), which
 * removes the developer notes written as HTML comments. A mistake in it would
 * either leak those notes again or, much worse, delete real page content. This
 * table runs the filter on small pieces of HTML and checks the exact result.
 *
 * Covers: ordinary and multi-line comments; script, style, pre and textarea
 * kept untouched (where "<!--" can be real content); a "<script>" that is only
 * mentioned inside a comment; old IE conditional comments kept; the empty
 * comments "<!-->" and "<!--->", which must not swallow the content after them;
 * and pages with no comments coming back unchanged.
 *
 * @see appWeb/public_html/includes/html_comment_strip.php
 * @see #2142
 */

require_once dirname(__DIR__, 2) . '/appWeb/public_html/includes/html_comment_strip.php';

$passed = 0;
$failed = 0;
$failures = [];

$cases = [
    'ordinary comment removed'
        => ['<p>a</p><!-- note #12 --><p>b</p>', '<p>a</p><p>b</p>'],
    'multi-line comment removed'
        => ["<div><!-- one\ntwo --></div>", '<div></div>'],
    'script contents kept, including a "<!--" string'
        => ['<script>var s = "<!-- keep -->";</script>', '<script>var s = "<!-- keep -->";</script>'],
    'JSON-LD block kept'
        => ['<script type="application/ld+json">{"a":"<!--x-->"}</script>', '<script type="application/ld+json">{"a":"<!--x-->"}</script>'],
    'style contents kept'
        => ['<style>/*<!--*/a{}</style>', '<style>/*<!--*/a{}</style>'],
    'pre contents kept'
        => ['<pre><!-- code sample --></pre>', '<pre><!-- code sample --></pre>'],
    'textarea contents kept'
        => ['<textarea><!-- typed --></textarea>', '<textarea><!-- typed --></textarea>'],
    'a "<script>" mentioned inside a comment is not treated as a script'
        => ['<!-- this blocks the following <script> until it loads --><p>x</p><script>y()</script>', '<p>x</p><script>y()</script>'],
    'IE conditional comment kept'
        => ['<!--[if IE]><p>old</p><![endif]-->', '<!--[if IE]><p>old</p><![endif]-->'],
    'empty comment "<!-->" does not swallow what follows'
        => ['a<!-->b<p>keep</p><!-- c -->d', 'ab<p>keep</p>d'],
    'empty comment "<!--->" does not swallow what follows'
        => ['a<!--->b<!-- c -->d', 'abd'],
    'upper-case script tag kept'
        => ['<SCRIPT>if (a<!--b) {}</SCRIPT><!--x-->', '<SCRIPT>if (a<!--b) {}</SCRIPT>'],
    'page with no comments comes back unchanged'
        => ['<p>plain</p>', '<p>plain</p>'],
];

foreach ($cases as $name => [$input, $expected]) {
    $actual = ihymnsStripHtmlComments($input);
    if ($actual === $expected) {
        $passed++;
        echo "  ✅ $name\n";
    } else {
        $failed++;
        $failures[] = ['name' => $name, 'detail' => 'expected ' . json_encode($expected) . ' got ' . json_encode($actual)];
        echo "  ❌ $name\n";
    }
}

echo "\n$passed passed, $failed failed\n";
if ($failed > 0) {
    echo "\nFailures:\n";
    foreach ($failures as $f) {
        echo "  - {$f['name']}\n    {$f['detail']}\n";
    }
    exit(1);
}
exit(0);
