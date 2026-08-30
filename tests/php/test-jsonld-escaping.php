<?php

declare(strict_types=1);

/**
 * iHymns — JSON-LD script-embedding escape guard (2026-08-30 security audit)
 *
 * ELI5: some public pages print a `<script type="application/ld+json">` block
 * whose body is built from database text (a musician's name, a publisher's
 * name/city/alias). If that text contained the literal `</script>`, it could
 * close the script element early and inject its own HTML into the page — a
 * "stored XSS" hole. PHP's `json_encode()` stops this ONLY if you pass the
 * `JSON_HEX_TAG` flag, which turns `<` and `>` into `<` / `>` so a
 * `</script>` in the data can never break out. This guard makes sure every
 * such block keeps that flag, so the fix can't quietly regress or be forgotten
 * on a newly-added block (it was: publisher.php shipped without it while
 * musician.php had it — the exact "two files must agree by hand" shape the
 * project bans; see rule #35).
 *
 * Detail / why a flag and not output-escaping: inside a `<script>` element the
 * browser is in "raw text" mode — normal HTML-entity escaping does NOT apply,
 * so `&lt;` would be read literally, not as `<`. The only thing that reliably
 * prevents an early `</script>` is encoding the `<`/`>` characters themselves,
 * which is what `JSON_HEX_TAG` does. The enforcing nonce CSP (rule #30) already
 * refuses any injected `<script>`/inline handler, so a miss here is markup/CSS
 * injection (defence-in-depth), not script execution — but it is still a real
 * defect and cheap to lock down.
 * @see https://www.php.net/manual/en/json.constants.php  (JSON_HEX_TAG)
 * @see includes/pages/musician.php  / includes/pages/publisher.php  (the two sites)
 *
 * Algorithm (mirrors test-fragment-inline-scripts.php's shape — source-tree
 * scan, no DB, slots into the CI lint step; tree-derived, never a typed file
 * list, so a NEW ld+json block anywhere is covered automatically):
 *   1. Strip `<!-- -->` and PHP `/* *\/` comment bodies first, preserving every
 *      newline so reported line numbers stay true. LOAD-BEARING: index.php has a
 *      doc-comment that mentions `<script type="application/ld+json">` in prose,
 *      and both entity pages carry a SECURITY comment that names the flags — all
 *      would false-match without this.
 *   2. Find every real `<script type="application/ld+json"…>…</script>` block.
 *   3. A block that builds its body with `json_encode(` MUST also contain
 *      `JSON_HEX_TAG` in that same block. (A static JSON literal with no
 *      json_encode has no dynamic data and is left alone.)
 *   4. Non-vacuity floor: at least 2 json_encode-bearing ld+json blocks must be
 *      found, so a regex regression that silently matches nothing fails loudly
 *      rather than passing as "coverage" (rule #34 — a scanner that under-reports
 *      is worse than none).
 *
 *   php tests/php/test-jsonld-escaping.php
 *
 * Exit status 0 = clean, 1 = a block missing JSON_HEX_TAG (or the floor tripped).
 */

/**
 * Blank out `<!-- ... -->` and `/* ... *\/` comment bodies while keeping every
 * newline they contained, so a match found afterwards still reports against its
 * correct ORIGINAL line number.
 */
function jsonldStripComments(string $src): string
{
    // PHP/C block comments.
    $src = preg_replace_callback('#/\*.*?\*/#s', static function (array $m): string {
        return preg_replace('/[^\n]/', ' ', $m[0]);
    }, $src);
    // HTML comments.
    $src = preg_replace_callback('#<!--.*?-->#s', static function (array $m): string {
        return preg_replace('/[^\n]/', ' ', $m[0]);
    }, $src);
    return $src;
}

$root = dirname(__DIR__, 2) . '/appWeb/public_html';
if (!is_dir($root)) {
    fwrite(STDERR, "FAIL: cannot find docroot at $root\n");
    exit(1);
}

$rii = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);

$failures       = [];
$blocksWithEncode = 0;
$scannedFiles   = 0;

foreach ($rii as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }
    $path = $file->getPathname();
    $raw  = file_get_contents($path);
    if ($raw === false || strpos($raw, 'application/ld+json') === false) {
        continue;
    }
    $scannedFiles++;
    $src = jsonldStripComments($raw);

    // Every <script type="application/ld+json" …> … </script> block (case-
    // insensitive; the body may span many lines, so `s` lets `.` cross newlines).
    if (!preg_match_all(
        '#<script\b[^>]*\btype\s*=\s*(["\'])application/ld\+json\1[^>]*>(.*?)</script>#is',
        $src,
        $matches,
        PREG_OFFSET_CAPTURE
    )) {
        continue;
    }

    foreach ($matches[2] as $i => $bodyCapture) {
        $body   = $bodyCapture[0];
        $offset = $matches[0][$i][1];
        // Only blocks whose body is produced by json_encode() carry dynamic data.
        if (strpos($body, 'json_encode') === false) {
            continue;
        }
        $blocksWithEncode++;
        if (strpos($body, 'JSON_HEX_TAG') === false) {
            $line = substr_count(substr($src, 0, $offset), "\n") + 1;
            $rel  = substr($path, strlen($root) + 1);
            $failures[] = "$rel:$line — <script type=\"application/ld+json\"> body uses json_encode() "
                . "without JSON_HEX_TAG (a </script> in the data could break out — stored XSS).";
        }
    }
}

if ($blocksWithEncode < 2) {
    fwrite(STDERR, "FAIL: expected at least 2 json_encode-backed application/ld+json blocks, found "
        . "$blocksWithEncode — the matcher likely regressed and is silently finding nothing "
        . "(a scanner that under-reports is worse than none, rule #34).\n");
    exit(1);
}

if ($failures) {
    fwrite(STDERR, "FAIL: JSON-LD script block(s) missing JSON_HEX_TAG:\n\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  $f\n");
    }
    fwrite(STDERR, "\nAdd JSON_HEX_TAG (with JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT to match the\n");
    fwrite(STDERR, "established sites) to the json_encode() flags so DB text containing </script>\n");
    fwrite(STDERR, "cannot break out of the <script> element. See musician.php / publisher.php.\n");
    exit(1);
}

echo "PASS: all {$blocksWithEncode} json_encode-backed application/ld+json blocks carry JSON_HEX_TAG "
    . "({$scannedFiles} candidate file(s) scanned).\n";
exit(0);
