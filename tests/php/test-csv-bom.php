<?php

declare(strict_types=1);

/**
 * test-csv-bom.php — CSV UTF-8 BOM: one shared emitter, no exceptions (#1908 Commit 4)
 * =======================================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * Proves the "D" gap from the #1908 Unicode audit is closed for good, in three
 * parts that each catch a different regression class (the test-search-fold.php
 * / rule #34 lesson — a static enumeration, a static ban, and a functional
 * check each cover ground the others cannot):
 *
 *   1. TREE-DERIVED coverage guard — every .php file under appWeb/public_html
 *      whose comment-stripped source mentions `text/csv` (i.e. every CSV
 *      exporter, present or future) must ALSO call ihymns_csv_output_begin().
 *      The set is DERIVED from the tree, never a typed list, so a NEW CSV
 *      exporter that forgets the shared emitter fails the build the moment it
 *      is added — not months later when someone opens the file in Excel and
 *      sees mojibake. A loud floor (>= 6) guards against the scanner itself
 *      silently under-matching and reporting a false "all clear" (rule #34 —
 *      "a scanner that under-reports is worse than no scanner").
 *
 *   2. DOUBLE-BOM ban — no file except includes/csv_safe.php itself may
 *      contain a raw `fopen('php://output'` or the literal `echo
 *      "\xEF\xBB\xBF"`. Two of the six exporters (manage/editor/api.php,
 *      manage/editor/api2.php) already hand-wrote the BOM inline before this
 *      commit; the ban stops either of them (or a NEW exporter) from
 *      re-introducing an inline BOM ALONGSIDE a call to the shared helper,
 *      which would silently double the BOM bytes and mojibake the first
 *      visible cell instead of fixing it.
 *
 *   3. FUNCTIONAL half (no DB, no HTTP) — calling ihymns_csv_output_begin()
 *      actually writes the three BOM bytes (EF BB BF) before anything else,
 *      captured via output buffering since php://output writes into the
 *      active ob_start() buffer rather than straight to stdout.
 *
 *   php tests/php/test-csv-bom.php
 *
 * @see appWeb/public_html/includes/csv_safe.php   ihymns_csv_output_begin()
 * @see .claude/unicode-nonlatin-1908-plan.md       §4 (Commit 4 spec)
 * @link https://en.wikipedia.org/wiki/Byte_order_mark
 */

$root    = dirname(__DIR__, 2);
$docroot = $root . '/appWeb/public_html';

$fail = 0;
function ok(string $label, bool $cond, string $detail = ''): void
{
    global $fail;
    echo ($cond ? "  PASS  " : "  FAIL  ") . $label . "\n";
    if (!$cond) {
        $fail++;
        if ($detail !== '') { echo "        $detail\n"; }
    }
}
function skip(string $label): void { echo "  SKIP  " . $label . "\n"; }

/** Strip PHP comments so a comment merely MENTIONING "text/csv", a raw
 *  fopen('php://output', or the BOM literal (as this very test file's own
 *  doc-block above does, repeatedly, in prose) can never masquerade as a
 *  real site (rule #34 — the test-search-fold.php fold_stripComments
 *  precedent, renamed here to avoid any cross-suite symbol clash even
 *  though each tests/php/*.php file already runs in its own process). */
function csvbom_stripComments(string $src): string
{
    $out = '';
    foreach (token_get_all($src) as $t) {
        if (is_array($t)) {
            if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) { $out .= ' '; continue; }
            $out .= $t[1];
        } else {
            $out .= $t;
        }
    }
    return $out;
}

/* Walk the docroot ONCE, build a map of path => comment-stripped source, so
   both static parts below scan the identical file set + content. */
$allPhp = [];
$scanned = 0;
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($docroot, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if ($f->getExtension() !== 'php') { continue; }
    $scanned++;
    $path = $f->getPathname();
    $allPhp[$path] = csvbom_stripComments((string)file_get_contents($path));
}

echo "Part 1 — every text/csv exporter calls ihymns_csv_output_begin()\n";

ok('the docroot walk actually scanned files (parser not broken)', $scanned > 200,
   "only scanned $scanned .php files");

$csvSafePath = $docroot . '/includes/csv_safe.php';

/* DERIVE the exporter set from the tree — never a typed list. Every file
   whose comment-stripped source contains the literal `text/csv` is, by
   construction, a place that sets a CSV Content-Type header (or at minimum
   talks about one for real, since comments were already stripped above). */
$csvSites = [];
foreach ($allPhp as $path => $code) {
    if (str_contains($code, 'text/csv')) {
        $csvSites[] = $path;
    }
}
sort($csvSites);

/* Anti-under-report floor (rule #34): the #1908 plan names exactly six real
   exporters (activity-log.php, analytics.php, ccli_report.php, api.php,
   editor api.php, editor api2.php). A scanner that silently matched fewer
   would report a false "all clear" instead of a missing site — fail loud
   instead. (csv_safe.php's own doc-block only mentions "text/csv" inside a
   comment, which is stripped above, so it is correctly NOT a member of this
   set — it defines the helper, it doesn't call it.) */
ok('found >= 6 text/csv site(s) under appWeb/public_html (anti-under-report floor)',
   count($csvSites) >= 6,
   'found ' . count($csvSites) . ': ' . implode(', ', array_map(fn($p) => str_replace($root . '/', '', $p), $csvSites)));

foreach ($csvSites as $path) {
    $rel = str_replace($root . '/', '', $path);
    ok("calls ihymns_csv_output_begin():  $rel",
       str_contains($allPhp[$path], 'ihymns_csv_output_begin('));
}

echo "\nPart 2 — double-BOM ban: no inline fopen('php://output' or echo BOM outside csv_safe.php\n";

/* The literal PHP source text to search for. Written via chr() concatenation
   (not the escaped string itself) so this TEST FILE'S OWN source never
   contains the literal `echo "\xEF\xBB\xBF"` substring either — keeping the
   ban airtight against a future version of this very test being scanned by
   itself or a sibling guard. */
$bomEchoLiteral = 'echo "' . '\\x' . 'EF' . '\\x' . 'BB' . '\\x' . 'BF' . '"';
ok('sanity: the BOM-echo literal under test is built correctly',
   $bomEchoLiteral === 'echo "\xEF\xBB\xBF"');

$violations = [];
foreach ($allPhp as $path => $code) {
    if ($path === $csvSafePath) { continue; } // the one legitimate home for both patterns
    if (str_contains($code, "fopen('php://output'")) {
        $violations[] = str_replace($root . '/', '', $path) . " — raw fopen('php://output'";
    }
    if (str_contains($code, $bomEchoLiteral)) {
        $violations[] = str_replace($root . '/', '', $path) . ' — inline echo BOM literal';
    }
}
ok('no file outside includes/csv_safe.php inlines fopen(\'php://output\' or the BOM echo literal',
   $violations === [],
   implode("\n        ", $violations));

echo "\nPart 3 — functional: ihymns_csv_output_begin() actually writes the UTF-8 BOM first\n";

require_once $docroot . '/includes/csv_safe.php';
ok('ihymns_csv_output_begin() is defined', function_exists('ihymns_csv_output_begin'));

if (function_exists('ihymns_csv_output_begin')) {
    /* php://output writes into the currently-active output buffer rather
       than straight to stdout, so ob_start()/ob_get_clean() is how a CLI
       test captures what a real HTTP response would have streamed to the
       browser. */
    ob_start();
    $stream = ihymns_csv_output_begin();
    $isResource = is_resource($stream);
    if ($isResource) {
        fwrite($stream, 'a,b,c'); // a token payload after the BOM, like a real caller's first fputcsv() row
        fclose($stream);
    }
    $captured = (string)ob_get_clean();

    ok('ihymns_csv_output_begin() returns a writable stream resource', $isResource);
    ok('first three bytes are the UTF-8 BOM (EF BB BF)',
       substr($captured, 0, 3) === "\xEF\xBB\xBF",
       'got hex: ' . bin2hex(substr($captured, 0, 3)));
    ok('payload written after the BOM survives untouched',
       substr($captured, 3) === 'a,b,c',
       'got: ' . substr($captured, 3));
} else {
    skip('functional BOM-bytes check — ihymns_csv_output_begin() is not defined');
}

echo "\n";
if ($fail > 0) {
    echo "$fail assertion(s) failed.\n";
    exit(1);
}
echo "All CSV-BOM assertions passed.\n";
