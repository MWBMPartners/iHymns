<?php

declare(strict_types=1);

/**
 * iHymns — Deploy-path smell guard (#1196 + #1695)
 *
 * TWO deploy-path bug classes, both invisible in the repo and only fatal on a
 * deployed server, where the docroot folder is RENAMED per channel
 * (public_html_dev on alpha, public_html_beta on beta; only main keeps the
 * literal public_html):
 *
 *  A. (#1196) A file UNDER appWeb/public_html/ that builds a filesystem path
 *     with dirname(__DIR__, …) and then re-appends a literal `public_html`
 *     segment. Code already inside public_html never needs to walk back DOWN
 *     into public_html, so any `dirname(__DIR__ …) … 'public_html'` concat is
 *     almost certainly a doubled / wrong-level path that silently fails
 *     (filemtime/is_file → false → cache-bust fallback, or a missing require).
 *     Bit request-a-song.php (a DOUBLE public_html) and setup-database.php (a
 *     wrong dirname() level).
 *
 *  B. (#1695) A migration under appWeb/.sql/ that UNCONDITIONALLY require()s a
 *     shared include via a hardcoded `dirname(__DIR__) . '/public_html/…'`
 *     literal. The migration itself lives in the un-renamed sibling .sql/, so
 *     dirname(__DIR__) is …/appWeb — correct — but the literal `/public_html/`
 *     it appends is the DOCROOT name, which off main is public_html_dev/_beta.
 *     So the path does not exist and the require fatals inside the
 *     setup-database runner, surfacing as a bare "HTTP error" (Apply-all) or
 *     "Failed opening required '…/public_html/includes/X.php'" (single-run).
 *     This stranded delete-songs-rewiden (#1695), reconcile-credit-name-bytes
 *     (#1784), derive-rights-facts (#1769) et al. the first time they were
 *     web-run on alpha.
 *
 *     The FIX is IHYMNS_INCLUDES_DIR — the runner (which physically lives in
 *     the real docroot) defines it to its own <docroot>/includes, and the
 *     migration resolves shared deps through it with a repo-path fallback for a
 *     standalone/CLI or test run. A require that is GUARDED so it never runs in
 *     the runner — indented inside `if (!defined('IHYMNS_SETUP_DASHBOARD'))` or
 *     `if (!function_exists(...))` — is safe (the runner already has the dep),
 *     so ONLY an UNCONDITIONAL, column-0 require of a `/public_html/` literal is
 *     a smell. (The repo-fallback ASSIGNMENT `$x = … : dirname(__DIR__) .
 *     '/public_html/includes'` is not a require, so it never trips this.)
 *
 * Why this guard exists at all: the #1196 pass only ever scanned UNDER
 * public_html, so the whole .sql/ migration tree was a blind spot — the exact
 * gap that let class B ship. A guard derived from one subtree cannot catch a
 * bug in a sibling subtree (CLAUDE.md rule #34).
 *
 *   php tests/php/test-deploy-paths.php
 *
 * Exit status 0 = clean, 1 = a smell was found.
 */

$repo = dirname(__DIR__, 2);

/**
 * Return a file's lines with /* … *​/ block comments blanked out (each replaced
 * by the SAME number of newlines so line numbers are preserved). This is what
 * lets a doc-block legitimately DESCRIBE the very pattern this guard bans — e.g.
 * this file, and the IHYMNS_INCLUDES_DIR comment in setup-database.php, both
 * spell out `dirname(__DIR__) . '/public_html/includes/…'` in prose — without
 * the guard flagging its own explanation (CLAUDE.md rule #34: a guard that
 * fails on correct code gets weakened or deleted rather than fixed).
 *
 * @return list<string>
 */
$loadCodeLines = static function (string $path): array {
    $src = (string)file_get_contents($path);
    $src = (string)preg_replace_callback(
        '#/\*.*?\*/#s',
        static fn(array $m): string => str_repeat("\n", substr_count($m[0], "\n")),
        $src
    );
    return explode("\n", $src);
};

/** A single-line comment can still legitimately mention a buggy pattern. */
$isComment = static function (string $line): bool {
    $t = ltrim($line);
    return $t === '' || $t[0] === '*' || str_starts_with($t, '//')
        || str_starts_with($t, '/*') || $t[0] === '#';
};

$smells = [];

/* ---------------------------------------------------------------------------
 * Pass A (#1196) — files UNDER public_html re-appending a public_html segment.
 * ------------------------------------------------------------------------- */
$root = $repo . '/appWeb/public_html';
if (!is_dir($root)) {
    fwrite(STDERR, "FATAL: cannot find $root\n");
    exit(1);
}
$rii = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);
foreach ($rii as $file) {
    if ($file->getExtension() !== 'php') { continue; }
    $lines = $loadCodeLines($file->getPathname());
    foreach ($lines as $i => $line) {
        if ($isComment($line)) { continue; }
        /* dirname(__DIR__ …) on the same line as a quoted public_html literal. */
        if (preg_match('/dirname\s*\(\s*__DIR__/', $line)
            && preg_match('/[\'"][^\'"]*public_html/', $line)
            && !str_contains($line, 'str_contains')) {
            $rel = 'appWeb/public_html/' . substr($file->getPathname(), strlen($root) + 1);
            $smells[] = "[A #1196] $rel:" . ($i + 1) . '  ' . trim($line);
        }
    }
}

/* ---------------------------------------------------------------------------
 * Pass B (#1695) — a migration in .sql/ with an UNCONDITIONAL (column-0)
 * require/include of a hardcoded '/public_html/…' literal.
 * ------------------------------------------------------------------------- */
$sqlDir = $repo . '/appWeb/.sql';
foreach (glob($sqlDir . '/migrate-*.php') ?: [] as $path) {
    $lines = $loadCodeLines($path);
    foreach ($lines as $i => $line) {
        if ($isComment($line)) { continue; }
        /* Column 0 = the require runs unconditionally, INCLUDING inside the
           setup-database runner where the docroot is renamed. An indented
           require (inside an if-guard) is skipped there and is fine. */
        if (!preg_match('/^(require|include)(_once)?\b/', $line)) { continue; }
        if (preg_match('/dirname\s*\(\s*__DIR__/', $line)
            && preg_match('#[\'"][^\'"]*/public_html/#', $line)) {
            $rel = 'appWeb/.sql/' . basename($path);
            $smells[] = "[B #1695] $rel:" . ($i + 1) . '  ' . trim($line);
        }
    }
}

if ($smells === []) {
    echo "PASS: no deploy-path smell — no dirname(__DIR__)+public_html under public_html (#1196),\n";
    echo "      no unconditional /public_html/ require in a .sql migration (#1695).\n";
    exit(0);
}
fwrite(STDERR, 'FAIL: ' . count($smells) . " deploy-path smell(s):\n");
foreach ($smells as $s) { fwrite(STDERR, "  $s\n"); }
fwrite(STDERR, "A (#1196): code inside public_html must not walk back into public_html — check the dirname level.\n");
fwrite(STDERR, "B (#1695): a migration must resolve shared includes via IHYMNS_INCLUDES_DIR (runner) with a\n");
fwrite(STDERR, "           repo fallback — the deployed docroot is renamed public_html_dev/_beta, so a hardcoded\n");
fwrite(STDERR, "           '/public_html/includes/X.php' require fatals off main. Guard it or use IHYMNS_INCLUDES_DIR.\n");
exit(1);
