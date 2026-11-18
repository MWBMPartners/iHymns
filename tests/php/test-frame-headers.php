<?php

declare(strict_types=1);

/**
 * tests/php/test-frame-headers.php — iHymns pages can only be framed by themselves (#2143)
 *
 * ELI5: a "frame" lets one web page show another page inside it. The settings
 * sync used to need a special hidden frame, which meant punching a hole in the
 * site-wide rule that stops other sites framing iHymns (a trick used for
 * "clickjacking", where an attacker hides our page under a fake button). That
 * frame never worked, and the rebuilt sync does not need one, so the hole was
 * closed again. This test keeps it closed: every frame rule is checked, and
 * any new exception anywhere fails the build.
 *
 * Derived from the tree (rule #34), not from a typed list:
 *   (a) across EVERY .htaccess under appWeb/, every X-Frame-Options that is SET
 *       is strict (SAMEORIGIN or DENY) and none sits inside <Files>,
 *       <FilesMatch>, <If>, <Location> or <Directory> (which is how a single
 *       page would be exempted); and the public docroot's .htaccess sets it
 *       exactly once, at its top level (inside only an <IfModule>). The other
 *       one in the tree is private_html's stricter DENY, which is fine;
 *   (b) it is never UNSET or removed anywhere: no `Header unset X-Frame-Options`
 *       in any .htaccess and no header_remove('X-Frame-Options') in any PHP file;
 *   (c) index.php's `frame-src` allows no iHymns origin (no `*.ihymns.app`, no
 *       sync host): the only frame left is the CAPTCHA one;
 *   (d) `frame-ancestors 'self'` is still in index.php and manage/includes/auth.php;
 *   (e) no file called bridge.html exists any more.
 *
 * Mutation-proven: add a top-level `Header always unset X-Frame-Options` (b
 * fails); put `https://*.ihymns.app` back in frame-src (c fails); change
 * index.php to `frame-ancestors *` (d fails); wrap the set in <Files "x.html">
 * (a fails). The current tree is green.
 *
 *   php tests/php/test-frame-headers.php
 *
 * @see .claude/settings-sync-2143-plan.md  (section 5, guard G4)
 * @link https://developer.mozilla.org/docs/Web/HTTP/Headers/X-Frame-Options
 * @link https://developer.mozilla.org/docs/Web/HTTP/Headers/Content-Security-Policy/frame-ancestors
 */

$repo = dirname(__DIR__, 2);
$passed = 0;
$failed = 0;

function fh(string $label, bool $cond, string $detail = ''): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "PASS: {$label}\n";
    } else {
        $failed++;
        fwrite(STDERR, "FAIL: {$label}" . ($detail !== '' ? "\n      {$detail}" : '') . "\n");
    }
}

$tracked = array_values(array_filter(explode("\0", (string)shell_exec('git -C ' . escapeshellarg($repo) . ' ls-files -z'))));
$htaccess = array_values(array_filter($tracked, static fn($f) => str_starts_with($f, 'appWeb/') && basename($f) === '.htaccess'));
$phpFiles = array_values(array_filter($tracked, static fn($f) => str_starts_with($f, 'appWeb/') && str_ends_with($f, '.php') && !str_contains($f, 'private_html/lib/')));

fh('the scan found the .htaccess files (' . count($htaccess) . ')', count($htaccess) >= 5, 'Too few found: the file list is wrong.');

/* ---- (a) one SET, at the top level ---- */
echo "(a) X-Frame-Options is set exactly once, at the top level\n";
$sets = [];
$unsets = [];
foreach ($htaccess as $rel) {
    $stack = [];
    foreach (explode("\n", (string)file_get_contents($repo . '/' . $rel)) as $n => $line) {
        $trim = trim($line);
        if ($trim === '' || $trim[0] === '#') { continue; }
        if (preg_match('/^<\/(\w+)/', $trim, $m)) { array_pop($stack); continue; }
        if (preg_match('/^<(\w+)/', $trim, $m)) { $stack[] = strtolower($m[1]); continue; }
        if (preg_match('/^Header\s+(?:always\s+)?(set|append|merge|add)\s+X-Frame-Options\s+"?([A-Za-z-]+)/i', $trim, $sm)) {
            $sets[] = ['file' => $rel, 'line' => $n + 1, 'stack' => $stack, 'value' => strtoupper($sm[2])];
        }
        if (preg_match('/^Header\s+(?:always\s+)?(unset|echo)\s+X-Frame-Options/i', $trim)) {
            $unsets[] = $rel . ':' . ($n + 1);
        }
    }
}
$public = array_values(array_filter($sets, static fn($s) => $s['file'] === 'appWeb/public_html/.htaccess'));
fh('the public docroot\'s .htaccess sets X-Frame-Options exactly once (found ' . count($public) . ')', count($public) === 1);
foreach ($sets as $s) {
    $where = $s['file'] . ':' . $s['line'];
    fh($where . ' is strict (SAMEORIGIN or DENY), not a loosened value', in_array($s['value'], ['SAMEORIGIN', 'DENY'], true), $s['value']);
    $narrow = array_values(array_intersect($s['stack'], ['files', 'filesmatch', 'if', 'ifdefine', 'location', 'locationmatch', 'directory', 'directorymatch', 'else', 'elseif']));
    fh($where . ' is not inside a block that exempts a page', $narrow === [], implode(',', $narrow));
}

/* ---- (b) never unset ---- */
echo "(b) never unset or removed\n";
fh('no .htaccess unsets or echoes X-Frame-Options', $unsets === [], implode(', ', $unsets));
$removers = [];
foreach ($phpFiles as $rel) {
    $src = (string)file_get_contents($repo . '/' . $rel);
    if (preg_match('/header_remove\(\s*[\'"]X-Frame-Options[\'"]/i', $src)) { $removers[] = $rel; }
}
fh('no PHP file calls header_remove(\'X-Frame-Options\')', $removers === [], implode(', ', $removers));

/* ---- (c) frame-src ---- */
echo "(c) frame-src allows no iHymns origin\n";
$index = (string)file_get_contents($repo . '/appWeb/public_html/index.php');
$frameSrc = '';
foreach (explode("\n", $index) as $line) {
    if (preg_match('/"frame-src\b[^"]*"/', $line, $m)) { $frameSrc = $m[0]; break; }
}
fh('index.php has a frame-src directive', $frameSrc !== '');
fh('it names no ihymns origin (no *.ihymns.app, no sync host, no .net)', $frameSrc !== '' && !preg_match('/ihymns|sync\./i', $frameSrc), $frameSrc);
fh('it still allows only itself plus the CAPTCHA token', (bool)preg_match("/^\"frame-src 'self'\{\\\$cspCaptchaFrame\}\"\$/", $frameSrc), $frameSrc);

/* ---- (d) frame-ancestors ---- */
echo "(d) frame-ancestors 'self' stays\n";
fh("index.php sends frame-ancestors 'self'", (bool)preg_match('/"frame-ancestors \'self\'"/', $index));
$auth = (string)file_get_contents($repo . '/appWeb/public_html/manage/includes/auth.php');
fh("the admin area sends frame-ancestors 'self'", (bool)preg_match("/frame-ancestors 'self'/", $auth));

/* ---- (e) no bridge page ---- */
echo "(e) no bridge page\n";
$bridges = array_values(array_filter($tracked, static fn($f) => strtolower(basename($f)) === 'bridge.html'));
fh('no file called bridge.html exists', $bridges === [], implode(', ', $bridges));

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
