<?php

declare(strict_types=1);

/**
 * iHymns — QR cache read-through guard (#1920 C3)
 * ============================================================================
 *
 * ELI5: makes sure the "remember QR pictures we already drew" feature is
 * wired up correctly and safely: the same request always gets the same
 * cache key, a DIFFERENT request always gets a DIFFERENT key, only the ONE
 * cached wrapper ever calls the raw CueRCode HTTP function, and the cache
 * module itself never crashes a page or leaves a SQL injection hole.
 *
 * WHAT IS ASSERTED
 *   1. FUNCTIONAL (calls the real, side-effect-free-to-require
 *      cuercode_client.php): cuercodeNormaliseOptions() with an empty
 *      options array equals the explicit-all-defaults array (the exact
 *      latent bug found + fixed during this commit's extraction — the
 *      original inline code re-read `$opts['format']` a second time in each
 *      ternary's true-branch instead of the coalesced local, which silently
 *      resolved to null-with-a-warning whenever a key was omitted); option
 *      key ORDER never changes the key (ksort); each axis (payload / size /
 *      format / ecc / type / fg_color) varied ALONE produces a DIFFERENT
 *      cuercodeCacheKey().
 *   2. TREE-DERIVED consumer check: every `cuercodeGenerate(` call site
 *      under appWeb/public_html is enumerated (grep, never a typed list);
 *      the ONLY one allowed OUTSIDE includes/cuercode_client.php itself is
 *      NONE — qr.php and includes/pdf_renderer.php must both call
 *      `cuercodeGenerateCached(` instead. (Mutation: revert either call
 *      site back to the raw function -> red.)
 *   3. STRUCTURAL on includes/qr_cache.php (comment-stripped via
 *      token_get_all — the test-rate-limit-pairing.php technique, so a
 *      docblock that MENTIONS "try" or "INFORMATION_SCHEMA" in prose can't
 *      false-positive): an INFORMATION_SCHEMA existence probe exists; every
 *      `->prepare(`/`->query(` call sits inside a `try { ... } catch` in its
 *      enclosing function; no SQL string literal (single- OR double-quoted)
 *      contains a `$` (bound-only, rule #5); qrCacheStore() contains the
 *      `ON DUPLICATE KEY UPDATE CacheKey = CacheKey` keep-existing marker
 *      and compares against `QR_CACHE_MAX_ROWS`.
 *   4. DORMANCY ORDER — in cuercode_client.php's cuercodeGenerateCached(),
 *      the `cuercodeConfigured()` gate textually precedes the `qrCacheFetch(`
 *      call, so an unkeyed install answers null BEFORE the cache is ever
 *      consulted (rule #38 — dormancy is a property of the INSTALL).
 *   5. REGISTRY — the 'qr-cache' slug exists in migration-registry.php (a
 *      dropped registry entry is a named failure here, not a diffuse one;
 *      the registry's OWN shape — script/card/probe present, probe not
 *      always-true — is covered by the existing
 *      tests/php/test-migration-registry.php, not re-asserted here).
 *
 *   php tests/php/test-qr-cache.php
 *
 * Exit status 0 = all assertions pass, 1 = at least one failure.
 *
 * @see appWeb/public_html/includes/cuercode_client.php
 * @see appWeb/public_html/includes/qr_cache.php
 * @see tests/php/test-rate-limit-pairing.php   (the comment-stripping technique)
 * @see tests/test-qr-cuercode.js               (the sibling JS-side QR wiring guard)
 */

$repo = dirname(__DIR__, 2);

$failures = 0;
function check(string $name, bool $cond, string $detail = ''): void
{
    global $failures;
    if ($cond) {
        echo "  \xE2\x9C\x93 $name\n";
        return;
    }
    $failures++;
    echo "  \xE2\x9C\x97 $name" . ($detail !== '' ? "\n      $detail" : '') . "\n";
}

/* ---------------------------------------------------------------------- *
 * 1 — FUNCTIONAL: cuercodeNormaliseOptions() / cuercodeCacheKey().
 * Requiring cuercode_client.php is side-effect-free (no DB connection, no
 * network call — its own doc-block guarantees this, mirroring
 * includes/intapps_client.php); none of the calls below touch the network
 * or a database.
 * ---------------------------------------------------------------------- */
require_once $repo . '/appWeb/public_html/includes/cuercode_client.php';

echo "QR cache — functional (cuercodeNormaliseOptions / cuercodeCacheKey):\n";

$defaultsEmpty   = cuercodeNormaliseOptions([]);
$defaultsExplicit = cuercodeNormaliseOptions(['format' => 'svg', 'size' => 512, 'ecc' => 'M', 'type' => 'url']);
check('empty $opts normalises to the same map as explicit-all-defaults',
    $defaultsEmpty === $defaultsExplicit,
    'got ' . json_encode($defaultsEmpty) . ' vs ' . json_encode($defaultsExplicit));

$orderA = cuercodeNormaliseOptions(['size' => 300, 'format' => 'png']);
$orderB = cuercodeNormaliseOptions(['format' => 'png', 'size' => 300]);
check('option key ORDER does not change the normalised map (ksort)', $orderA === $orderB);

$baseNorm = cuercodeNormaliseOptions([]);
$key1 = cuercodeCacheKey('https://ihymns.app/song/CP-0001', $baseNorm);
$key2 = cuercodeCacheKey('https://ihymns.app/song/CP-0001', $baseNorm);
check('cuercodeCacheKey() is stable across repeated calls with the same input', $key1 === $key2);
check('cuercodeCacheKey() returns a 64-char sha256 hex string',
    is_string($key1) && strlen($key1) === 64 && ctype_xdigit($key1));

$baseKey = cuercodeCacheKey('https://ihymns.app/song/CP-0001', $baseNorm);
$variants = [
    'payload' => cuercodeCacheKey('https://ihymns.app/song/CP-0002', $baseNorm),
    'size'    => cuercodeCacheKey('https://ihymns.app/song/CP-0001', cuercodeNormaliseOptions(['size' => 300])),
    'format'  => cuercodeCacheKey('https://ihymns.app/song/CP-0001', cuercodeNormaliseOptions(['format' => 'png'])),
    'ecc'     => cuercodeCacheKey('https://ihymns.app/song/CP-0001', cuercodeNormaliseOptions(['ecc' => 'H'])),
    'type'    => cuercodeCacheKey('https://ihymns.app/song/CP-0001', cuercodeNormaliseOptions(['type' => 'wifi'])),
    'fg_color'=> cuercodeCacheKey('https://ihymns.app/song/CP-0001', cuercodeNormaliseOptions(['fg_color' => '#ff0000'])),
];
foreach ($variants as $axis => $variantKey) {
    check("varying '$axis' alone produces a DIFFERENT cache key", $variantKey !== $baseKey);
}

/* ---------------------------------------------------------------------- *
 * 2 — TREE-DERIVED: enumerate every cuercodeGenerate( call site.
 * ---------------------------------------------------------------------- */
echo "\nQR cache — tree-derived cuercodeGenerate() consumer check:\n";

function walkPhpFiles(string $dir, array &$acc): void
{
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..' || $entry === 'vendor' || $entry === 'node_modules') {
            continue;
        }
        $full = $dir . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($full)) {
            walkPhpFiles($full, $acc);
        } elseif (str_ends_with($entry, '.php')) {
            $acc[] = $full;
        }
    }
}

$pub = $repo . '/appWeb/public_html';
$phpFiles = [];
walkPhpFiles($pub, $phpFiles);
check('scanned a plausible number of PHP files (parser sanity)', count($phpFiles) >= 50,
    'only ' . count($phpFiles) . ' .php files walked — the tree walk under-read');

$rawCallSites = [];
foreach ($phpFiles as $file) {
    /* Comment-stripped (qrcStripComments(), defined below — PHP hoists
       top-level function declarations, so calling it here is fine): a
       doc-block mentioning "cuercodeGenerate()" in PROSE (several files do,
       to explain the relationship to the cached wrapper) must never be
       mistaken for a real call — the exact false-positive class
       tests/test-qr-cuercode.js's own stripComments() guards against. */
    $src = qrcStripComments((string)file_get_contents($file));
    if (preg_match_all('/cuercodeGenerate\s*\(/', $src, $m)) {
        $rawCallSites[$file] = count($m[0]);
    }
}
$rawOutsideClient = array_filter(
    $rawCallSites,
    static fn(string $f) => basename($f) !== 'cuercode_client.php',
    ARRAY_FILTER_USE_KEY
);
check('no file OTHER than cuercode_client.php calls the raw cuercodeGenerate()',
    count($rawOutsideClient) === 0,
    'raw call(s) found in: ' . implode(', ', array_map(fn($f) => str_replace($repo . '/', '', $f), array_keys($rawOutsideClient))));
check('cuercode_client.php itself still defines/calls the raw cuercodeGenerate() (sanity)',
    ($rawCallSites[$repo . '/appWeb/public_html/includes/cuercode_client.php'] ?? 0) >= 2,
    'expected >=2 occurrences (the function definition + cuercodeGenerateCached()\'s internal call)');

$qrPhpSrc = (string)file_get_contents($repo . '/appWeb/public_html/qr.php');
check('qr.php calls cuercodeGenerateCached(', (bool)preg_match('/cuercodeGenerateCached\s*\(/', $qrPhpSrc));

$pdfRendererSrc = (string)file_get_contents($repo . '/appWeb/public_html/includes/pdf_renderer.php');
check('pdf_renderer.php calls cuercodeGenerateCached(', (bool)preg_match('/cuercodeGenerateCached\s*\(/', $pdfRendererSrc));

/* ---------------------------------------------------------------------- *
 * 3 — STRUCTURAL on includes/qr_cache.php (comment-stripped).
 * ---------------------------------------------------------------------- */
echo "\nQR cache — structural checks (includes/qr_cache.php):\n";

/** Blank out T_COMMENT/T_DOC_COMMENT token bodies, keeping newlines, so a
 *  docblock that MENTIONS "try"/"INFORMATION_SCHEMA"/"$var" in prose can
 *  never be mistaken for the real code (the test-rate-limit-pairing.php
 *  technique). */
function qrcStripComments(string $php): string
{
    $out = '';
    foreach (token_get_all($php) as $tok) {
        if (is_array($tok)) {
            if ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT) {
                $out .= preg_replace('/[^\n]/', ' ', $tok[1]);
                continue;
            }
            $out .= $tok[1];
            continue;
        }
        $out .= $tok;
    }
    return $out;
}

/** Extract one top-level function's whole body (including its braces) by
 *  brace-depth counting from its opening '{' — safe here because, once
 *  comments are stripped, none of this file's string literals contain a
 *  literal brace character. */
function qrcFunctionBody(string $src, string $fnName): ?string
{
    if (!preg_match('/function\s+' . preg_quote($fnName, '/') . '\s*\([^)]*\)[^{]*\{/', $src, $m, PREG_OFFSET_CAPTURE)) {
        return null;
    }
    $openPos = $m[0][1] + strlen($m[0][0]) - 1;
    $depth = 0;
    for ($i = $openPos, $n = strlen($src); $i < $n; $i++) {
        if ($src[$i] === '{') { $depth++; }
        elseif ($src[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($src, $openPos, $i - $openPos + 1);
            }
        }
    }
    return null;
}

$qrCachePath = $repo . '/appWeb/public_html/includes/qr_cache.php';
$qrCacheRaw  = (string)file_get_contents($qrCachePath);
$qrCacheSrc  = qrcStripComments($qrCacheRaw);

check('qr_cache.php has an INFORMATION_SCHEMA existence probe',
    (bool)preg_match('/INFORMATION_SCHEMA\.TABLES/', $qrCacheSrc));

$qrcFunctions = ['_qrCacheTableExists', 'qrCacheFetch', 'qrCacheStore', 'qrCachePrune'];
foreach ($qrcFunctions as $fn) {
    $body = qrcFunctionBody($qrCacheSrc, $fn);
    check("qr_cache.php defines $fn()", $body !== null);
    if ($body === null) {
        continue;
    }
    $hasTry   = (bool)preg_match('/\btry\s*\{/', $body);
    $hasCatch = (bool)preg_match('/\}\s*catch\s*\(/', $body);
    check("$fn() wraps its body in try/catch", $hasTry && $hasCatch);

    if ($hasTry && preg_match_all('/->(?:prepare|query)\s*\(/', $body, $callMatches, PREG_OFFSET_CAPTURE)) {
        $tryPos = strpos($body, 'try {') !== false ? strpos($body, 'try {') : strpos($body, 'try{');
        $catchPos = null;
        if (preg_match('/\}\s*catch\s*\(/', $body, $cm, PREG_OFFSET_CAPTURE)) {
            $catchPos = $cm[0][1];
        }
        $allInside = $tryPos !== false && $catchPos !== null;
        foreach ($callMatches[0] as $cmatch) {
            if ($cmatch[1] < $tryPos || $cmatch[1] > $catchPos) {
                $allInside = false;
            }
        }
        check("$fn()'s every ->prepare(/->query( call sits inside its try block", $allInside);
    }
}

/* No SQL string (single- or double-quoted literal) interpolates a
   variable — bound-only (rule #5). Scans the WHOLE comment-stripped file
   for any quoted literal that both names an SQL verb and contains `$`. */
if (preg_match_all('/\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"/', $qrCacheSrc, $stringMatches)) {
    $badSqlStrings = array_filter($stringMatches[0], static function (string $s): bool {
        return preg_match('/\b(SELECT|INSERT|UPDATE|DELETE)\b/i', $s) === 1 && str_contains($s, '$');
    });
    check('no SQL string literal in qr_cache.php interpolates a $variable',
        count($badSqlStrings) === 0,
        'offending literal(s): ' . implode(' | ', $badSqlStrings));
} else {
    check('found at least one quoted string literal to scan (parser sanity)', false);
}

$storeBody = qrcFunctionBody($qrCacheSrc, 'qrCacheStore');
if ($storeBody !== null) {
    check('qrCacheStore() contains the ON DUPLICATE KEY UPDATE CacheKey = CacheKey keep-existing marker',
        (bool)preg_match('/ON\s+DUPLICATE\s+KEY\s+UPDATE\s+CacheKey\s*=\s*CacheKey/i', $storeBody));
    check('qrCacheStore() compares against QR_CACHE_MAX_ROWS (the row-count belt)',
        (bool)preg_match('/QR_CACHE_MAX_ROWS/', $storeBody));
}

/* ---------------------------------------------------------------------- *
 * 4 — DORMANCY ORDER: cuercodeConfigured() must precede qrCacheFetch(
 * inside cuercodeGenerateCached().
 * ---------------------------------------------------------------------- */
echo "\nQR cache — dormancy-first ordering (cuercode_client.php):\n";

$clientPath = $repo . '/appWeb/public_html/includes/cuercode_client.php';
$clientRaw  = (string)file_get_contents($clientPath);
$clientSrc  = qrcStripComments($clientRaw);

$cachedBody = qrcFunctionBody($clientSrc, 'cuercodeGenerateCached');
check('cuercode_client.php defines cuercodeGenerateCached()', $cachedBody !== null);
if ($cachedBody !== null) {
    $configuredPos = strpos($cachedBody, 'cuercodeConfigured(');
    $fetchPos      = strpos($cachedBody, 'qrCacheFetch(');
    check('cuercodeConfigured() gate textually precedes qrCacheFetch() (dormancy-first)',
        $configuredPos !== false && $fetchPos !== false && $configuredPos < $fetchPos,
        'cuercodeConfigured() at ' . var_export($configuredPos, true) . ', qrCacheFetch( at ' . var_export($fetchPos, true));
}

/* ---------------------------------------------------------------------- *
 * 5 — REGISTRY: the 'qr-cache' slug exists.
 * ---------------------------------------------------------------------- */
echo "\nQR cache — registry entry:\n";
$registrySrc = (string)file_get_contents($repo . '/appWeb/public_html/manage/includes/migration-registry.php');
check("migration-registry.php has a 'qr-cache' entry", (bool)preg_match('/[\'"]qr-cache[\'"]\s*=>/', $registrySrc));

if ($failures) {
    fwrite(STDERR, "\nFAIL: $failures QR cache check(s) failed.\n");
    exit(1);
}
echo "\nOK: QR cache wired correctly (" . count($phpFiles) . " PHP files scanned for raw cuercodeGenerate() calls).\n";
