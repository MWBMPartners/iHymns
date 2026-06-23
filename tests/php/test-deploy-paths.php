<?php

declare(strict_types=1);

/**
 * iHymns — Deploy-path smell guard (#1196)
 *
 * Catches the filesystem-path bug class that bit request-a-song.php (a DOUBLE
 * `public_html`) and setup-database.php (a wrong dirname() level): a file UNDER
 * appWeb/public_html/ that builds a filesystem path with `dirname(__DIR__, …)`
 * and then re-appends a literal `public_html` segment. Code already inside
 * public_html never needs to walk back DOWN into public_html, so any
 * `dirname(__DIR__ …) … 'public_html'` concatenation is almost certainly a
 * doubled / wrong-level path that silently fails (filemtime/is_file → false →
 * cache-bust fallback, or a missing require).
 *
 * The legitimate `public_html_dev` / `public_html_beta` env-channel checks use
 * str_contains($serverPath, …) and do NOT combine with dirname(__DIR__ on the
 * same line, so they don't trip this guard.
 *
 *   php tests/php/test-deploy-paths.php
 *
 * Exit status 0 = clean, 1 = a smell was found.
 */

$root = dirname(__DIR__, 2) . '/appWeb/public_html';
if (!is_dir($root)) {
    fwrite(STDERR, "FATAL: cannot find $root\n");
    exit(1);
}

$smells = [];
$rii = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);
foreach ($rii as $file) {
    if ($file->getExtension() !== 'php') { continue; }
    $lines = file($file->getPathname(), FILE_IGNORE_NEW_LINES) ?: [];
    foreach ($lines as $i => $line) {
        /* Skip comment lines — a doc-block may legitimately mention the old buggy
           pattern (e.g. places-api.php documents a since-fixed instance). */
        $t = ltrim($line);
        if ($t === '' || $t[0] === '*' || str_starts_with($t, '//') || str_starts_with($t, '/*') || $t[0] === '#') {
            continue;
        }
        /* dirname(__DIR__ …) on the same line as a quoted public_html literal. */
        if (preg_match('/dirname\s*\(\s*__DIR__/', $line)
            && preg_match('/[\'"][^\'"]*public_html/', $line)
            && !str_contains($line, 'str_contains')) {
            $rel = substr($file->getPathname(), strlen($root) + 1);
            $smells[] = $rel . ':' . ($i + 1) . '  ' . trim($line);
        }
    }
}

if ($smells === []) {
    echo "PASS: no dirname(__DIR__)+public_html path smell under appWeb/public_html (#1196).\n";
    exit(0);
}
fwrite(STDERR, "FAIL: " . count($smells) . " deploy-path smell(s) — a dirname(__DIR__) path re-appends 'public_html' (#1196):\n");
foreach ($smells as $s) { fwrite(STDERR, "  $s\n"); }
fwrite(STDERR, "Code inside public_html should not walk back into public_html; check the dirname level.\n");
exit(1);
