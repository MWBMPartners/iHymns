<?php

declare(strict_types=1);

/**
 * iHymns — the shared language rules reach the server and load there (#2137, #2138)
 *
 * ELI5: iHymns refuses to save a song's language unless its shared language
 * rules (includes/vendor/media-language/) are on the server. So those files must be
 * among the files the deploy uploads, and the site must find them where they
 * land. This test builds the folder the deploy would build — the docroot
 * under its renamed alpha name, with nothing beside it — copies the files
 * the way the deploy does, and loads the rules from that copy.
 *
 * WHY THIS TEST EXISTS
 * --------------------
 * The rules were first put in appWeb/private_html/lib/media-language/, beside
 * the PDF engine. The deploy never uploads private_html: that step needs the
 * SFTP_PRIVATE_PATH secret, which is not set, and the deploy logs say
 * "SFTP_PRIVATE_PATH not set — skipping private_html deployment" (#2138).
 * Every test here passed anyway, because on a developer's machine the whole
 * repository is present. Only a test that copies the DEPLOYED layout can see
 * that difference.
 *
 * WHAT IT CHECKS (each read from the real files, not typed in here — rule #34)
 * ----------------------------------------------------------------------------
 *  1. The folder the loader uses (mediaLanguageLibraryDir()) lies inside the
 *     folder deploy.yml uploads as the docroot (its `source_dir=` line).
 *  2. None of deploy.yml's `--exclude` patterns (the LFTP_EXCLUDES block)
 *     drops any file in that folder, or any folder above it.
 *  3. A copy of the docroot's includes/ folder, made with those same
 *     exclusions, under the renamed alpha docroot name (public_html_dev,
 *     rule #41) and with NO private_html beside it, loads the rules in a
 *     separate PHP process and tidies `pt-br` to `pt-BR`.
 *  4. The same copy WITHOUT includes/vendor/media-language/ refuses — so check 3 is
 *     able to fail, and is not passing for some other reason.
 *
 * WHAT IT CANNOT CHECK
 * --------------------
 * That the server's web server obeys `.htaccess` (the `^includes/` block that
 * keeps these files from being fetched by a browser), or that a deploy
 * actually ran. It checks what the deploy workflow is written to upload.
 *
 * Mutation-proven: putting the folder back under appWeb/private_html/ (and
 * the loader with it) turned checks 1 and 3 red; adding
 * `--exclude ^includes/vendor/` to deploy.yml turned checks 2 and 3 red.
 *
 *   php tests/php/test-media-language-deploy-layout.php
 *
 * Exit status 0 = the rules ship and load, 1 = anything else.
 *
 * @see appWeb/public_html/includes/media_language.php  mediaLanguageLibraryDir()
 * @see .github/workflows/deploy.yml                    source_dir, LFTP_EXCLUDES
 */

$repoRoot = dirname(__DIR__, 2);
require_once $repoRoot . '/appWeb/public_html/includes/media_language.php';

$passed = 0;
$failed = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$passed, &$failed): void {
    if ($ok) {
        $passed++;
        echo "  PASS  {$label}\n";
    } else {
        $failed++;
        echo "  FAIL  {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
};

echo "tests/php/test-media-language-deploy-layout.php — the shared language rules reach the server and load there\n";

$deployYml = (string)@file_get_contents($repoRoot . '/.github/workflows/deploy.yml');
$check('deploy.yml was read', $deployYml !== '');

/* ---- 1. the library is inside the folder the deploy uploads as the docroot */
preg_match_all('/echo "source_dir=([^"]+)"/', $deployYml, $m);
$sourceDirs = array_values(array_unique($m[1]));
$check('deploy.yml names exactly one docroot source folder', count($sourceDirs) === 1, implode(', ', $sourceDirs));
$sourceDir = rtrim($sourceDirs[0] ?? '', '/') . '/';                      // e.g. appWeb/public_html/

$libAbs = str_replace('\\', '/', mediaLanguageLibraryDir());
$root   = str_replace('\\', '/', $repoRoot) . '/';
$libRel = str_starts_with($libAbs, $root) ? substr($libAbs, strlen($root)) . '/' : $libAbs;
$check("the rules folder ({$libRel}) is inside the docroot the deploy uploads ({$sourceDir})",
    $sourceDir !== '/' && str_starts_with($libRel, $sourceDir));

/* The loader's includes/ folder — what step 3 copies. */
$includesRel = 'includes/';
$check('the rules folder is under the docroot\'s includes/ (the part the site\'s .htaccess keeps from the web)',
    str_starts_with($libRel, $sourceDir . $includesRel));

/* ---- 2. no deploy exclusion drops a file of it --------------------------- */
$excludes = [];
if (preg_match('/^(\s*)LFTP_EXCLUDES:\s*>-\s*\n((?:\1\s+--exclude\s+\S+\s*\n)+)/m', $deployYml, $blk)) {
    preg_match_all('/--exclude\s+(\S+)/', $blk[2], $xm);
    $excludes = $xm[1];
}
$check('the deploy\'s exclusion list was found and read (' . count($excludes) . ' patterns)', count($excludes) >= 5);

/** lftp tests each path relative to the uploaded folder; a folder is tested with a trailing "/". */
$excludedBy = static function (string $relPath, bool $isDir) use ($excludes): ?string {
    $subject = $isDir ? rtrim($relPath, '/') . '/' : $relPath;
    foreach ($excludes as $rx) {
        if (@preg_match('#' . str_replace('#', '\\#', $rx) . '#', $subject) === 1) {
            return $rx;
        }
    }
    return null;
};

$libFiles = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($libAbs, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $libFiles[] = substr(str_replace('\\', '/', $f->getPathname()), strlen($root . $sourceDir));
}
sort($libFiles);
$check('the rules folder holds the code and its data file',
    in_array(substr($libRel, strlen($sourceDir)) . 'MediaLanguagePolicy.php', $libFiles, true)
    && in_array(substr($libRel, strlen($sourceDir)) . 'bcp47-language-data-v1.json', $libFiles, true),
    implode(', ', $libFiles));
$dropped = [];
foreach ($libFiles as $rel) {
    $parts = explode('/', $rel);
    for ($i = 1; $i < count($parts); $i++) {                             // every folder above the file
        $hit = $excludedBy(implode('/', array_slice($parts, 0, $i)), true);
        if ($hit !== null) { $dropped[] = "{$rel} (folder, by {$hit})"; continue 2; }
    }
    $hit = $excludedBy($rel, false);
    if ($hit !== null) { $dropped[] = "{$rel} (by {$hit})"; }
}
$check('no deploy exclusion drops any of those files', $dropped === [], implode('; ', $dropped));

/* ---- 3 + 4. copy the deployed layout and load the rules from it ---------- */
$tmp = sys_get_temp_dir() . '/ihymns-deploy-layout-' . bin2hex(random_bytes(4));
$docroot = $tmp . '/appWeb/public_html_dev';                              // alpha's name on the server (rule #41)
$copied = 0;
$srcIncludes = $root . $sourceDir . $includesRel;
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($srcIncludes, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);
foreach ($it as $f) {
    $rel = $includesRel . substr(str_replace('\\', '/', $f->getPathname()), strlen($srcIncludes));
    if ($excludedBy($rel, $f->isDir()) !== null) {
        continue;                                                          // lftp does not descend into an excluded folder; the loop below skips its files
    }
    $parts = explode('/', $rel);
    for ($i = 1; $i < count($parts); $i++) {
        if ($excludedBy(implode('/', array_slice($parts, 0, $i)), true) !== null) { continue 2; }
    }
    $dest = $docroot . '/' . $rel;
    if ($f->isDir()) {
        @mkdir($dest, 0777, true);
    } else {
        @mkdir(dirname($dest), 0777, true);
        if (@copy($f->getPathname(), $dest)) { $copied++; }
    }
}
$check("the deployed includes/ folder was copied ({$copied} files)", $copied > 10);
$check('nothing but the docroot exists in the copy (no private_html beside it)',
    array_values(array_diff((array)@scandir($tmp . '/appWeb'), ['.', '..'])) === ['public_html_dev']);

/** Load the copied loader in a fresh PHP process and report what it sees. */
$probe = static function (string $docroot): array {
    $code = 'require $argv[1] . "/includes/media_language.php";'
          . '$ready = mediaLanguageReady();'
          . '$tag = null; $refused = false;'
          . 'try { $tag = mediaLanguageTagForStorage("pt-br"); } catch (\Throwable $e) { $refused = true; }'
          . 'echo json_encode(["ready" => $ready, "dir" => mediaLanguageLibraryDir(), "tag" => $tag, "refused" => $refused]);';
    $proc = proc_open([PHP_BINARY, '-d', 'error_log=/dev/null', '-r', $code, '--', $docroot],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) {
        return ['error' => 'could not start PHP'];
    }
    $out = (string)stream_get_contents($pipes[1]);
    $err = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($proc);
    $j = json_decode($out, true);
    return is_array($j) ? $j + ['exit' => $exit] : ['error' => "exit {$exit}: " . trim($out . ' ' . $err)];
};

$got = $probe($docroot);
$check('from the deployed copy the rules load (mediaLanguageReady() is true)', ($got['ready'] ?? null) === true, json_encode($got));
$check('the loader looked inside the deployed docroot, not the repository',
    /* realpath(): on macOS the temp folder is reached through a link (/var → /private/var). */
    str_starts_with(str_replace('\\', '/', (string)($got['dir'] ?? '')), str_replace('\\', '/', (string)realpath($docroot)) . '/'),
    (string)($got['dir'] ?? ''));
$check('from the deployed copy a language is tidied (pt-br → pt-BR)', ($got['tag'] ?? null) === 'pt-BR', json_encode($got));

/* Control: without the folder, the same copy must refuse — proves the check above can fail. */
$removeTree = static function (string $dir) use (&$removeTree): void {
    foreach ((array)@scandir($dir) as $e) {
        if ($e === '.' || $e === '..' || $e === false) { continue; }
        $p = $dir . '/' . $e;
        is_dir($p) && !is_link($p) ? $removeTree($p) : @unlink($p);
    }
    @rmdir($dir);
};
$removeTree($docroot . '/' . $includesRel . 'vendor/media-language');
$got = $probe($docroot);
$check('control: with includes/vendor/media-language/ missing, the rules do not load and a save is refused',
    ($got['ready'] ?? null) === false && ($got['refused'] ?? null) === true, json_encode($got));

$removeTree($tmp);
$check('the temporary copy was removed', !file_exists($tmp));

echo "\n  {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
