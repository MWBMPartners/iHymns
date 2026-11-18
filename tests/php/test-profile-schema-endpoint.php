<?php

declare(strict_types=1);

/**
 * iHymns - the profile_schema endpoint is public, cacheable and leaks nothing (C5, epic #2180)
 *
 * ELI5
 * ----
 * `GET /api?action=profile_schema` hands out the list of personal settings and
 * their rules. This checks it: anyone may ask (no sign-in), it can be cached,
 * asking again with the tag you were given gets an empty "unchanged" answer, only
 * GET works, and the server's private fields never appear in the reply.
 *
 * Detailed: api.php cannot be loaded without a database, so the test cuts the
 * real `case 'profile_schema':` block out of the source and RUNS it in a separate
 * PHP process with stand-ins for sendJson() and apiContractVersion(). That way the
 * answer comes from the real code, not from a pattern match. (CLI PHP does not
 * record header() calls, so "304" is shown by the block printing nothing and
 * stopping, which is the only way it can end without calling sendJson().)
 * Mutation proofs are in the C5 commit: calling getAuthenticatedUser() in the
 * block, and leaving out the 304 branch, each turn this red.
 *
 *   php tests/php/test-profile-schema-endpoint.php
 */

$repo = dirname(__DIR__, 2);
$web = $repo . '/appWeb/public_html';
require_once $web . '/includes/profile_registry.php';

$fail = 0;
function chk(string $label, bool $cond, string $detail = ''): void
{
    global $fail;
    echo ($cond ? '  PASS  ' : '  FAIL  ') . $label . ($cond || $detail === '' ? '' : ' - ' . substr($detail, 0, 300)) . "\n";
    if (!$cond) { $fail++; }
}

/* ---- cut the real block out of api.php ---- */
$src = (string)file_get_contents($web . '/api.php');
$a = strpos($src, "case 'profile_schema':");
$b = strpos($src, "case 'stats':", (int)$a);
chk('found the profile_schema case in api.php', $a !== false && $b !== false);
$block = substr($src, (int)$a + strlen("case 'profile_schema':"), (int)$b - (int)$a - strlen("case 'profile_schema':"));
/* The block ends with a comment banner for the next case; drop trailing comment text. */
$block = preg_replace('~/\*[^*]*(?:\*(?!/)[^*]*)*\*/\s*$~', '', rtrim($block)) ?? $block;

$codeOnly = '';
foreach (token_get_all('<?php ' . $block) as $t) {
    if (is_array($t)) { $codeOnly .= in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]; } else { $codeOnly .= $t; }
}
chk('it is public: no sign-in lookup in the block', !str_contains($codeOnly, 'getAuthenticatedUser') && !str_contains($codeOnly, 'isAuthenticated'));
chk('it only answers GET and HEAD (405 otherwise)', str_contains($codeOnly, "!== 'GET'") && str_contains($codeOnly, "!== 'HEAD'") && str_contains($codeOnly, '405'));
chk('it asks for a shared cache of a few minutes', str_contains($codeOnly, "public, max-age=300, must-revalidate"));
chk('it sends an ETag and answers 304 + exit on a match', str_contains($codeOnly, "header('ETag: '") && str_contains($codeOnly, '304') && str_contains($codeOnly, 'exit;'));

/* ---- run it ---- */
function runBlock(string $block, string $web, string $method, string $ifNoneMatch): array
{
    $harness = <<<'PHP'
<?php
$_SERVER['REQUEST_METHOD'] = getenv('T_METHOD');
if (getenv('T_INM') !== '') { $_SERVER['HTTP_IF_NONE_MATCH'] = getenv('T_INM'); }
function apiContractVersion(): int { return 1; }
function sendJson(array $d, int $status = 200, ?string $cache = null): void {
    echo json_encode(['__sendJson' => true, 'status' => $status, 'cache' => $cache, 'data' => $d]);
}
require getenv('T_REG');
switch (true) { case true:
PHP;
    /* The block says __DIR__ (api.php's folder); in the temporary file that would be wrong. */
    $harness .= "\n" . str_replace('__DIR__', var_export($web, true), $block) . "\n}\n";
    $file = tempnam(sys_get_temp_dir(), 'psc') . '.php';
    file_put_contents($file, $harness);
    $env = ['T_METHOD' => $method, 'T_INM' => $ifNoneMatch, 'T_REG' => $web . '/includes/profile_registry.php', 'PATH' => getenv('PATH') ?: ''];
    $p = proc_open([PHP_BINARY, $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    @unlink($file);
    return [$out, $err];
}

[$out, $err] = runBlock($block, $web, 'GET', '');
$r = json_decode($out, true);
chk('a GET answers through sendJson with status 200', is_array($r) && ($r['status'] ?? 0) === 200, $out . $err);
chk('with a short shared cache', ($r['cache'] ?? '') === 'public, max-age=300, must-revalidate');
chk('the data is exactly the public projection (version, entries, an aliases map)', ($r['data']['schemaVersion'] ?? 0) === profileRegistryVersion() && count($r['data']['entries'] ?? []) === count(profileRegistry()));
$leaks = [];
foreach (($r['data']['entries'] ?? []) as $e) { foreach (PROFILE_SERVER_ONLY_FIELDS as $f) { if (array_key_exists($f, $e)) { $leaks[] = $e['id'] . '.' . $f; } } }
chk('no storage/export/erase field in the reply', $leaks === [], implode(',', $leaks));
chk('the empty alias map travels as an object, not a list', str_contains($out, '"aliases":{}'));
chk('an open-object shape travels as {} (a map), never [] (a list)', str_contains($out, '"shape":{}') && !str_contains($out, '"shape":[]'));

/* the tag: computed by the same function the endpoint uses */
$pub = profileRegistryPublic();
$pub['aliases'] = new \stdClass();
$etag = profileRegistryEtag($pub, 1);
chk('the tag is a quoted 32-character fingerprint', (bool)preg_match('/^"[a-f0-9]{32}"$/', $etag));
$changed = $pub;
$changed['schemaVersion'] = 99;
chk('a different answer has a different tag', profileRegistryEtag($changed, 1) !== $etag);
chk('a different response-shape version has a different tag', profileRegistryEtag($pub, 2) !== $etag);

[$out304, $err304] = runBlock($block, $web, 'GET', $etag);
chk('asking again with the current tag: no body is sent (the 304 path)', $out304 === '', $out304 . $err304);
[$outStale] = runBlock($block, $web, 'GET', '"stale"');
chk('asking with an old tag: the full answer again', str_contains($outStale, '__sendJson'));
[$outPost] = runBlock($block, $web, 'POST', '');
$rp = json_decode($outPost, true);
chk('a POST is refused with 405', ($rp['status'] ?? 0) === 405);

/* ---- documented ---- */
$docs = (string)file_get_contents($web . '/api-docs.yaml');
chk('api-docs.yaml documents the action', str_contains($docs, '/api.php?action=profile_schema:') && str_contains($docs, 'operationId: getProfileSchema'));

exit($fail === 0 ? 0 : 1);
