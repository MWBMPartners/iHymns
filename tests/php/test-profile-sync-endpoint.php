<?php

declare(strict_types=1);

/**
 * iHymns - the profile_sync endpoint: who may call it, what it answers, and that it is documented (PR 2b step 2b-3, epic #2180)
 * ============================================================================
 *
 * ELI5
 * ----
 * `?action=profile_sync` is how a phone or browser sends the small personal choices it changed and
 * learns what changed elsewhere. This test checks the door: nobody gets in without signing in, a
 * cross-site request is turned away, the answers carry the right status and reason (a client reads the
 * number and the reason, never the sentence), a paused site still reads but refuses to save, and the
 * request limits (64 KiB, 200 changes) are enforced.
 *
 * Detailed (rule #34)
 * -------------------
 * api.php cannot be loaded without a whole site, so the test cuts the REAL `case 'profile_sync'` block
 * out of the source and runs it in a separate PHP process against a real database, with stand-ins ONLY
 * for the things around it (sendJson, the sign-in lookup, the cross-site check, the rate limiter). The
 * channel switch, the pause switch, the table check, the saving and the reading are the real code. Each
 * answer is judged by status and reason. A pure test also calls the request-shape checker with awkward
 * bodies. Mutation proofs are in the 2b-3 commit: the switch check removed, the pause check removed and
 * the size check removed each turn this red.
 *
 *   IHYMNS_TEST_DSN="host=127.0.0.1;user=root;pass=" php tests/php/test-profile-sync-endpoint.php
 */

$repo = dirname(__DIR__, 2);
$web  = $repo . '/appWeb/public_html';
$_SERVER['SCRIPT_FILENAME'] = 'test';
require_once $web . '/includes/profile_sync.php';

$fail = 0;
function chk(string $label, bool $cond, string $detail = ''): void
{
    global $fail;
    echo ($cond ? '  PASS  ' : '  FAIL  ') . $label . ($cond || $detail === '' ? '' : ' - ' . substr($detail, 0, 400)) . "\n";
    if (!$cond) { $fail++; }
}

/* ---- the request shape, called ---- */
echo "the request shape\n";
$ok = fn(array $b) => profileSyncParseRequest(json_encode($b));
chk('a normal request is accepted and unpacked', ($r = $ok(['schemaVersion' => 2, 'client' => ['platform' => 'apple', 'appVersion' => '1.0'], 'cursor' => 7, 'changes' => [['section' => 'a', 'key' => 'b', 'op' => 'set', 'value' => 1]]]))['status'] === 200 && $r['cursor'] === 7 && $r['platform'] === 'apple' && count($r['changes']) === 1 && $r['schemaVersion'] === 2);
chk('everything is optional: an empty object is a plain read', ($r = $ok([]))['status'] === 200 && $r['cursor'] === 0 && $r['changes'] === [] && $r['platform'] === null);
chk('an unknown platform is dropped, not an error', ($r = $ok(['client' => ['platform' => 'toaster']]))['status'] === 200 && $r['platform'] === null);
foreach ([
    'an empty body' => '', 'text that is not JSON' => 'nope', 'a JSON list at the top' => '[1,2]', 'a bare number' => '5',
    'changes that is an object' => '{"changes":{"a":1}}', 'changes that is a string' => '{"changes":"x"}',
    'a negative cursor' => '{"cursor":-1}', 'a text cursor' => '{"cursor":"5"}', 'a fractional cursor' => '{"cursor":1.5}',
    'client as a list' => '{"client":[1]}', 'client as a string' => '{"client":"web"}', 'schemaVersion as text' => '{"schemaVersion":"2"}',
] as $label => $raw) {
    $r = profileSyncParseRequest($raw);
    chk("400 invalid_request for $label", $r['status'] === 400 && $r['reason'] === 'invalid_request');
}
$r = profileSyncParseRequest(json_encode(['changes' => array_fill(0, PROFILE_SYNC_MAX_CHANGES, ['section' => 'a'])]));
chk('exactly 200 changes is accepted', $r['status'] === 200);
$r = profileSyncParseRequest(json_encode(['changes' => array_fill(0, PROFILE_SYNC_MAX_CHANGES + 1, ['section' => 'a'])]));
chk('201 changes is 413 too_many_changes', $r['status'] === 413 && $r['reason'] === 'too_many_changes');
$r = profileSyncParseRequest(json_encode(['changes' => [['section' => 'a', 'key' => 'b', 'op' => 'set', 'value' => str_repeat('x', PROFILE_SYNC_MAX_BODY_BYTES)]]]));
chk('a body over 64 KiB is 413 body_too_large (checked before it is even decoded)', $r['status'] === 413 && $r['reason'] === 'body_too_large');
chk('the size is checked before the shape (a huge garbage body is 413, not 400)', profileSyncParseRequest(str_repeat('x', PROFILE_SYNC_MAX_BODY_BYTES + 1))['status'] === 413);
chk('a cursor in a query string: missing or empty is 0; digits are read; anything else is refused',
    profileSyncParseCursor(null) === 0 && profileSyncParseCursor('') === 0 && profileSyncParseCursor('42') === 42
    && profileSyncParseCursor('-1') === null && profileSyncParseCursor('4x') === null && profileSyncParseCursor("4\n") === null && profileSyncParseCursor(['1']) === null && profileSyncParseCursor('9999999999999999') === null);

/* ---- cut the real block out of api.php ---- */
echo "the real block\n";
$src = (string)file_get_contents($web . '/api.php');
$start = strpos($src, "case 'profile_sync': {");
chk('found the profile_sync case in api.php', $start !== false);
$tokens = token_get_all('<?php ' . substr($src, (int)$start + strlen("case 'profile_sync':")));
$depth = 0; $block = '';
foreach ($tokens as $t) {
    $txt = is_array($t) ? $t[1] : $t;
    if ($txt === '{' || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) { $depth++; }
    if ($txt === '}') { $depth--; }
    $block .= $txt;
    if ($depth === 0 && $block !== '<?php ' && str_contains($block, '{') && $txt === '}') { break; }
}
$block = substr($block, strlen('<?php '));
$codeOnly = '';
foreach (token_get_all('<?php ' . $block) as $t) { if (is_array($t)) { $codeOnly .= in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]; } else { $codeOnly .= $t; } }
chk('the cut block is the whole case (ends at its own closing brace, then break)', str_contains($codeOnly, 'profileSyncReply(') && str_contains($codeOnly, 'break;') && substr_count($codeOnly, '{') === substr_count($codeOnly, '}'));
$pos = fn(string $needle) => strpos($codeOnly, $needle);
chk('the order is: method, cross-site check, sign-in, switch, pause (POST only), limit, then the body',
    $pos("'GET' && \$psMethod !== 'POST'") < $pos('validateCsrfRequest(null)') && $pos('validateCsrfRequest(null)') < $pos('getAuthenticatedUser()') && $pos('getAuthenticatedUser()') < $pos('profileSyncEnabledHere()')
    && $pos('profileSyncEnabledHere()') < $pos('accountWritesRefuseIfOff()') && $pos('accountWritesRefuseIfOff()') < $pos('enforceReadRateLimit(') && $pos('enforceReadRateLimit(') < $pos('php://input'));
chk('the cross-site check and the pause check apply to POST only', str_contains($codeOnly, "\$psMethod === 'POST' && !validateCsrfRequest(null)") && str_contains($codeOnly, "\$psMethod === 'POST' && accountWritesRefuseIfOff()"));
chk('the rate limit is per signed-in person, not per address', str_contains($codeOnly, "'uid:' . \$psUserId"));
chk('the body is read with a size bound', str_contains($codeOnly, 'PROFILE_SYNC_MAX_BODY_BYTES + 1'));
chk('no value is placed in SQL by this block (all SQL is in the shared core)', !preg_match('/\b(SELECT|INSERT|UPDATE|DELETE)\b/', $codeOnly));

/* ---- a real database, the block run in a separate process ---- */
echo "a real database\n";
$dsn = getenv('IHYMNS_TEST_DSN') ?: '';
$hh = '127.0.0.1'; $pt = 3306; $us = 'root'; $pw = '';
foreach (explode(';', $dsn) as $kv) {
    [$k, $val] = array_pad(explode('=', $kv, 2), 2, '');
    if ($k === 'host') { $hh = $val; } if ($k === 'port') { $pt = (int)$val; } if ($k === 'user') { $us = $val; } if ($k === 'pass') { $pw = $val; }
}
$db = null;
if ($dsn !== '') {
    try { mysqli_report(MYSQLI_REPORT_OFF); $db = @new mysqli($hh, $us, $pw, '', $pt); if ($db->connect_errno) { $db = null; } } catch (\Throwable $_e) { $db = null; }
}
if ($db === null) {
    echo "  SKIP  no database - the answers were NOT exercised. Set IHYMNS_TEST_DSN; this is a gap, not a pass.\n";
} else {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $name = 'ihymns_t2b3_endpoint';
    $db->query("DROP DATABASE IF EXISTS `{$name}`");
    $db->query("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $db->select_db($name);
    $db->set_charset('utf8mb4');
    $schema = (string)file_get_contents($repo . '/appWeb/.sql/schema.sql');
    try {
        $db->query('CREATE TABLE tblUsers (Id INT UNSIGNED NOT NULL PRIMARY KEY, Settings JSON NULL, UpdatedAt DATETIME NULL) ENGINE=InnoDB');
        $db->query('INSERT INTO tblUsers (Id) VALUES (1), (2)');
        chk('tblAppSettings is found in schema.sql', (bool)preg_match('/CREATE TABLE IF NOT EXISTS tblAppSettings \(.*?\) ENGINE=InnoDB[^;]*;/s', $schema, $ma));
        $db->query($ma[0]);

        /** Run the real block as one request. $o: method, query (array), body (string), user (int|null), csrf (bool). */
        $call = function (array $o) use ($block, $web, $hh, $us, $pw, $name, $pt): array {
            $harness = <<<'PHP'
<?php
$_SERVER['REQUEST_METHOD'] = getenv('T_METHOD');
$_SERVER['DOCUMENT_ROOT'] = '/srv/appWeb/public_html_dev';   /* the dev copy of the site: channel "alpha" */
$_SERVER['SCRIPT_FILENAME'] = 'api.php';
$_GET = json_decode(getenv('T_QUERY'), true) ?: [];
define('DB_HOST', getenv('T_HOST')); define('DB_USER', getenv('T_USER')); define('DB_PASS', (string)getenv('T_PASS')); define('DB_NAME', getenv('T_DB')); define('DB_PORT', (int)getenv('T_PORT'));
$GLOBALS['__out'] = null; $GLOBALS['__limit'] = null;
function sendJson(array $d, int $status = 200, ?string $cache = null): void { $GLOBALS['__out'] = ['status' => $status, 'data' => $d]; }
function getAuthenticatedUser() { return (string)getenv('T_USER_ID') === '' ? null : ['Id' => (int)getenv('T_USER_ID')]; }
function validateCsrfRequest(?string $t = null): bool { return getenv('T_CSRF') === '1'; }
function enforceReadRateLimit(string $scope, int $perMin, int $perDay = 0, string $key = ''): void { $GLOBALS['__limit'] = [$scope, $perMin, $key]; }
require getenv('T_CORE');
require getenv("T_GATE");
$action = 'profile_sync';
switch ($action) { case 'profile_sync':
PHP;
            /* The sign-in file and the core are loaded by the harness (the sign-in file as stand-ins); everything else is real. */
            $b = preg_replace('~require_once [^;]*?(?:auth|profile_sync)\.php\';~', '', str_replace('__DIR__', var_export($web, true), $block)) ?? '';
            $b = str_replace("'php://input'", 'getenv(\'T_BODY_FILE\')', $b);
            $harness .= "\n" . $b . "\n}\n" . 'echo json_encode(["out" => $GLOBALS["__out"], "limit" => $GLOBALS["__limit"]]);' . "\n";
            $file = tempnam(sys_get_temp_dir(), 'psep') . '.php';
            $bodyFile = tempnam(sys_get_temp_dir(), 'psbd');
            file_put_contents($file, $harness);
            file_put_contents($bodyFile, $o['body'] ?? '');
            $env = [
                'T_METHOD' => $o['method'] ?? 'GET', 'T_QUERY' => json_encode($o['query'] ?? []), 'T_BODY_FILE' => $bodyFile,
                'T_USER_ID' => isset($o['user']) ? (string)$o['user'] : '', 'T_CSRF' => ($o['csrf'] ?? true) ? '1' : '0',
                'T_HOST' => $hh, 'T_USER' => $us, 'T_PASS' => $pw, 'T_DB' => $name, 'T_PORT' => (string)$pt,
                'T_CORE' => $web . '/includes/profile_sync.php', 'T_GATE' => $web . '/includes/account_write_gate.php', 'PATH' => getenv('PATH') ?: '',
            ];
            $p = proc_open([PHP_BINARY, $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
            $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
            if (getenv('T_KEEP')) { fwrite(STDERR, "harness: $file\n"); } else { @unlink($file); }
            @unlink($bodyFile);
            $j = json_decode($out, true);
            return is_array($j) ? $j + ['raw' => $out] : ['out' => null, 'limit' => null, 'raw' => $out];
        };
        $setSwitch = function (string $key, string $val) use ($db): void {
            $db->query("DELETE FROM tblAppSettings WHERE SettingKey = '" . $db->real_escape_string($key) . "'");
            $db->query("INSERT INTO tblAppSettings (SettingKey, SettingValue) VALUES ('" . $db->real_escape_string($key) . "', '" . $db->real_escape_string($val) . "')");
        };
        $status = fn(array $r) => $r['out']['status'] ?? -1;
        $reason = fn(array $r) => $r['out']['data']['reason'] ?? null;
        $change = fn(string $s, string $k, mixed $v) => ['section' => $s, 'key' => $k, 'op' => 'set', 'value' => $v];
        $post = fn(array $body, array $extra = []) => $call($extra + ['method' => 'POST', 'user' => 1, 'body' => json_encode($body)]);

        $r = $call(['method' => 'GET', 'user' => 1]);
        chk('switch off (the default): 409 profile_sync_disabled', $status($r) === 409 && $reason($r) === 'profile_sync_disabled', $r['raw']);
        $r = $post(['changes' => [$change('appearance', 'theme', 'dark')]]);
        chk('...and a POST is refused the same way, with nothing stored', $status($r) === 409 && $reason($r) === 'profile_sync_disabled');

        $setSwitch('profile_sync_channels', 'alpha');
        $r = $call(['method' => 'GET', 'user' => 1]);
        chk('switch on, but the table does not exist yet: 409 profile_sync_not_ready (no exception)', $status($r) === 409 && $reason($r) === 'profile_sync_not_ready', $r['raw']);

        chk('the table is found in schema.sql', (bool)preg_match('/CREATE TABLE IF NOT EXISTS tblUserProfileItems \(.*?\) ENGINE=InnoDB[^;]*;/s', $schema, $mt));
        $db->query($mt[0]);

        chk('405 for a method that is not GET or POST', $status($call(['method' => 'PUT', 'user' => 1])) === 405);
        $r = $call(['method' => 'GET']);
        chk('401 when not signed in', $status($r) === 401);
        $r = $call(['method' => 'POST', 'user' => 1, 'csrf' => false, 'body' => '{}']);
        chk('403 for a cross-site POST', $status($r) === 403);
        $r = $call(['method' => 'GET', 'user' => 1, 'csrf' => false]);
        chk('...but a GET needs no cross-site check', $status($r) === 200);

        $r = $call(['method' => 'GET', 'user' => 1]);
        chk('200 with an empty list for a person with nothing yet', $status($r) === 200 && ($r['out']['data']['ok'] ?? null) === true && $r['out']['data']['changes'] === [] && $r['out']['data']['cursor'] === 0 && $r['out']['data']['more'] === false && ($r['out']['data']['schemaVersion'] ?? 0) === profileRegistryVersion(), $r['raw']);
        chk('the limit is counted per signed-in person', ($r['limit'] ?? null) === ['profile_sync', PROFILE_SYNC_RATE_PER_MINUTE, 'uid:1'], json_encode($r['limit'] ?? null));

        $r = $post(['client' => ['platform' => 'web'], 'cursor' => 0, 'changes' => [$change('appearance', 'theme', 'dark'), $change('nope', 'x', 1), $change('songPrefs', 'MP-7', ['capo' => 3])]]);
        $d = $r['out']['data'] ?? [];
        chk('a POST stores the good changes, reports the refused one, and reads back in the same reply',
            $status($r) === 200 && ($d['applied'] ?? -1) === 2 && count($d['rejected'] ?? []) === 1 && $d['rejected'][0]['reason'] === 'unknown_key' && count($d['changes']) === 2 && $d['cursor'] === 2, $r['raw']);
        $r = $call(['method' => 'GET', 'user' => 2]);
        chk('another person sees none of it', $status($r) === 200 && $r['out']['data']['changes'] === []);
        $r = $call(['method' => 'GET', 'user' => 1, 'query' => ['cursor' => '1']]);
        chk('a GET with a cursor returns only what is above it', $status($r) === 200 && count($r['out']['data']['changes']) === 1 && $r['out']['data']['changes'][0]['version'] === 2);
        $r = $call(['method' => 'GET', 'user' => 1, 'query' => ['cursor' => 'abc']]);
        chk('a malformed cursor is 400 invalid_request', $status($r) === 400 && $reason($r) === 'invalid_request');

        $r = $post(['changes' => 'nope']);
        chk('a malformed POST body is 400 invalid_request', $status($r) === 400 && $reason($r) === 'invalid_request');
        $r = $call(['method' => 'POST', 'user' => 1, 'body' => str_repeat('x', PROFILE_SYNC_MAX_BODY_BYTES + 100)]);
        chk('a body over 64 KiB is 413 body_too_large', $status($r) === 413 && $reason($r) === 'body_too_large');
        $r = $post(['changes' => array_fill(0, PROFILE_SYNC_MAX_CHANGES + 1, $change('appearance', 'theme', 'dark'))]);
        chk('201 changes is 413 too_many_changes, and none of them was stored', $status($r) === 413 && $reason($r) === 'too_many_changes' && (int)$db->query("SELECT COUNT(*) FROM tblUserProfileItems WHERE UserId = 1 AND Section <> '_meta'")->fetch_row()[0] === 2);

        /* a paused site: reads work, saving is refused */
        $setSwitch('account_writes_channels', 'none');
        $r = $post(['changes' => [$change('appearance', 'theme', 'light')]]);
        chk('a paused site refuses a POST: 409 account_writes_off_here, nothing stored', $status($r) === 409 && $reason($r) === 'account_writes_off_here' && $db->query("SELECT ValueJson FROM tblUserProfileItems WHERE UserId = 1 AND ItemKey = 'theme'")->fetch_row()[0] === '"dark"', $r['raw']);
        $r = $call(['method' => 'GET', 'user' => 1]);
        chk('...but a GET still reads', $status($r) === 200 && count($r['out']['data']['changes']) === 2);
        $setSwitch('account_writes_channels', 'alpha');
        $r = $post(['changes' => [$change('appearance', 'theme', 'light')]]);
        chk('with saving allowed again the same POST works', $status($r) === 200 && ($r['out']['data']['applied'] ?? 0) === 1);

        /* the switch can be turned off again at once */
        $setSwitch('profile_sync_channels', 'beta,production');
        $r = $call(['method' => 'GET', 'user' => 1]);
        chk('a site that is not ticked answers 409 profile_sync_disabled (the switch is per site)', $status($r) === 409 && $reason($r) === 'profile_sync_disabled');
    } finally {
        $db->query("DROP DATABASE IF EXISTS `{$name}`");
    }
}

/* ---- documented ---- */
echo "documented\n";
$docs = (string)file_get_contents($web . '/api-docs.yaml');
chk('api-docs.yaml documents GET and POST of the action', str_contains($docs, '/api.php?action=profile_sync:') && str_contains($docs, 'operationId: profileSyncRead') && str_contains($docs, 'operationId: profileSyncWrite'));
foreach (PROFILE_SYNC_REJECT_REASONS as $why) {
    chk("api-docs.yaml lists the refusal reason $why", str_contains($docs, $why));
}
/* The cross-site rule must be described as it is (2b review fix 12): every POST needs X-Requested-With, a Bearer one too. */
$docBlock = (string)substr($docs, (int)strpos($docs, 'operationId: profileSyncRead'), 9000);
$docBlock = substr($docBlock, 0, (int)strpos($docBlock, '/api.php?action=tags:') ?: 9000);
chk('api-docs.yaml does not claim a Bearer request is exempt from the same-origin check', !preg_match('/exempt from|is exempt|are exempt/i', $docBlock) && str_contains($docBlock, 'Bearer request included'));
chk('api-docs.yaml documents the 405 for BOTH methods of the action', substr_count($docBlock, '"405":') === 2);
$apiCodeOnly = ''; foreach (token_get_all('<?php ' . $block) as $t) { if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) { $apiCodeOnly .= $t[1]; } }
chk('the comment in api.php does not claim a Bearer request is exempt, and says why a native app passes', !preg_match('/\bis exempt\b|so it is exempt/i', $apiCodeOnly) && str_contains($apiCodeOnly, 'NO exemption for a Bearer request'));
/* (That the real validateCsrfRequest() refuses a request without the header, whatever else it carries, is checked in test-csrf-same-origin.php.) */
foreach (['body_too_large', 'too_many_changes', 'invalid_request', 'profile_sync_not_ready', 'profile_sync_disabled', 'account_writes_off_here'] as $why) {
    chk("api-docs.yaml names the status reason $why", str_contains($docs, $why));
}

exit($fail === 0 ? 0 : 1);
