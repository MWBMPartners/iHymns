<?php

declare(strict_types=1);

/**
 * iHymns - there is ONE sign-in token maker (C3, epic #2180)
 *
 * ELI5
 * ----
 * Every way of signing in ends by making one sign-in token. Six places used to
 * make their own, and only three wrote the device's name and kind, so the
 * device list could not label some sessions. Now one function, apiTokenMint(),
 * makes them all, and this guard fails if anything else inserts a token, if a
 * route calls the maker wrongly, or if the maker itself stops doing its job.
 *
 * Detailed (rule #34)
 * -------------------
 *  1. The whole of appWeb/ is scanned (PHP's tokenizer, strings only, so
 *     comments cannot trip it) for any write into tblApiTokens that creates a
 *     row. The only one allowed is inside apiTokenMint() in includes/api_tokens.php.
 *  2. Every call to apiTokenMint() is found and must pass a literal "how it was
 *     made" word from the one vocabulary; the vocabulary words in use are
 *     derived, and every word must be used by some route ("enrol" is used by the
 *     device sign-in core since PR 3 step H5).
 *  3. The maker is CALLED against a pretend database: 64 hex characters, only the
 *     hash stored, 30 days, the new columns written only when they exist, an
 *     unknown word stored as NULL, details written only when given.
 * Mutation proofs are in the C3 commit: adding an INSERT to api.php, and making
 * the maker store the raw token, each turn this red.
 *
 *   php tests/php/test-single-token-mint.php
 */

$repo = dirname(__DIR__, 2);
$web = $repo . '/appWeb/public_html';
require_once $web . '/includes/api_tokens.php';

$fail = 0;
function chk(string $label, bool $cond, string $detail = ''): void
{
    global $fail;
    echo ($cond ? '  PASS  ' : '  FAIL  ') . $label . ($cond || $detail === '' ? '' : " - $detail") . "\n";
    if (!$cond) { $fail++; }
}

/* ---- 1. nobody else inserts a token ---- */
echo "1. only apiTokenMint() inserts into tblApiTokens\n";
$hits = [];
$files = 0;
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($repo . '/appWeb', FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (!$f->isFile() || substr($f->getFilename(), -4) !== '.php' || strpos($f->getPathname(), '/private_html/lib/') !== false) { continue; }
    $files++;
    $src = (string)file_get_contents($f->getPathname());
    $buf = [];
    foreach (token_get_all($src) as $t) {
        if (is_array($t) && in_array($t[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) { $buf[] = $t[1]; }
    }
    if (preg_match('/(INSERT\s+(IGNORE\s+)?INTO|REPLACE\s+INTO)\s+tblApiTokens\b/i', implode(' ', $buf))) {
        $hits[] = substr($f->getPathname(), strlen($repo) + 1);
    }
}
chk("the scan covered the tree ($files PHP files)", $files > 300);
chk('exactly one file inserts a token: includes/api_tokens.php (' . implode(', ', $hits) . ')', $hits === ['appWeb/public_html/includes/api_tokens.php']);
$tokSrc = '';
foreach (token_get_all((string)file_get_contents($web . '/includes/api_tokens.php')) as $t) {
    if (is_array($t)) { $tokSrc .= in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]; } else { $tokSrc .= $t; }
}
$mintAt = (int)strpos($tokSrc, 'function apiTokenMint(');
$nextFn = strpos($tokSrc, "\nfunction ", $mintAt + 20);
$mintEnd = $nextFn === false ? strlen($tokSrc) : $nextFn;
$allInserts = [];
$off = 0;
while (($p = stripos($tokSrc, 'into tblApiTokens', $off)) !== false) { $allInserts[] = $p; $off = $p + 5; }
chk('the maker contains at least one insert (the scan sees it)', count($allInserts) >= 1);
chk('and EVERY insert in that file sits inside apiTokenMint()', count(array_filter($allInserts, fn($p) => $p < $mintAt || $p > $mintEnd)) === 0);

/* ---- 2. every call site ---- */
echo "2. every sign-in route calls the maker with a word from the vocabulary\n";
$used = [];
$calls = 0;
/* The files are found from the tree (rule #34), not from a typed list: every .php under appWeb/ that names the maker. */
$mintFiles = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($repo . '/appWeb', FilesystemIterator::SKIP_DOTS)) as $f) {
    $pth = $f->getPathname();
    if (!$f->isFile() || substr($pth, -4) !== '.php' || str_contains($pth, 'private_html/lib/')) { continue; }
    if (strpos((string)file_get_contents($pth), 'apiTokenMint(') !== false) { $mintFiles[] = $pth; }
}
chk('the tree scan found the known callers (api.php, the login helpers, the device sign-in core)', count(array_filter($mintFiles, fn($m) => preg_match('#/(api|auth|device_signin)\.php$#', $m))) >= 3, implode(', ', array_map('basename', $mintFiles)));
foreach ($mintFiles as $file) {
    $src = (string)file_get_contents($file);
    $code = '';
    foreach (token_get_all($src) as $t) {
        if (is_array($t)) { $code .= in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]; } else { $code .= $t; }
    }
    if (preg_match_all('/=\s*apiTokenMint\(([^;]*?)\);/s', $code, $m)) {
        foreach ($m[1] as $args) {
            $calls++;
            if (preg_match("/,\s*'([a-z_]+)'\s*(?:,|\$)/", $args, $vm)) {
                $used[$vm[1]] = true;
                chk("a call in " . basename($file) . " passes the known word '{$vm[1]}'", in_array($vm[1], API_TOKEN_ISSUED_VIA, true));
            } else {
                chk('a call in ' . basename($file) . ' passes a literal how-made word', false, trim($args));
            }
        }
    }
}
chk("the six sign-in routes were found ($calls calls)", $calls >= 6);
foreach (API_TOKEN_ISSUED_VIA as $word) {
    chk("the word '$word' is used by a route (or the vocabulary has a dead word)", isset($used[$word]));
}

/* ---- 3. the maker itself, against a pretend database ---- */
echo "3. the maker's behaviour\n";
final class MintRes { public function __construct(private array $rows) {} public function fetch_row() { return $this->rows[0] ?? null; } /* H1: the same-install lookup reads a list; the pretend database holds no older sign-ins */ public function fetch_all(int $mode = 2) { return []; } }
final class MintStmt
{
    public int $affected_rows = 1;
    private array $vals = [];
    public function __construct(private MintDb $db, private string $sql) {}
    public function bind_param(string $t, &...$v) { $this->vals = $v; return true; }
    public function execute() { if (!str_starts_with(ltrim($this->sql), 'SELECT')) { $this->db->writes[] = [$this->sql, $this->vals]; } return true; }
    public function get_result() { return new MintRes([[str_contains($this->sql, "'IssuedVia', 'DeviceInstallId'") ? ($this->db->foundation ? 2 : 0) : 4]]); }
    public function close() {}
}
final class MintDb extends \mysqli
{
    public array $writes = [];
    public function __construct(public bool $foundation) {}
    public function prepare(string $q): mixed { return new MintStmt($this, $q); }
}
$withCols = new MintDb(true);
$exp = null;
$tok = apiTokenMint($withCols, 7, ['deviceName' => 'Foyer TV', 'platform' => 'apple', 'appVersion' => '1.2.3', 'deviceInstallId' => 'abcd-1234-efgh'], 'apple', $exp);
chk('the raw token is 64 hex characters', (bool)preg_match('/^[a-f0-9]{64}$/', $tok));
[$sql, $vals] = $withCols->writes[0];
chk('only the sha256 of the token is stored, never the token', $vals[0] === hash('sha256', $tok) && $vals[0] !== $tok);
chk('the person id is stored', $vals[1] === 7);
chk('it expires 30 days from now (out-parameter set to match)', is_int($exp) && abs($exp - (time() + 30 * 86400)) <= 3 && $vals[2] === gmdate('Y-m-d H:i:s', $exp));
chk('with the new columns present, IssuedVia and DeviceInstallId are written', str_contains($sql, 'IssuedVia') && $vals[3] === 'apple' && $vals[4] === 'abcd-1234-efgh');
chk('device details given: they are written (a second statement)', count($withCols->writes) === 2 && str_contains($withCols->writes[1][0], 'UPDATE tblApiTokens SET DeviceName'));
$withCols->writes = [];
$tok2 = apiTokenMint($withCols, 7, null, 'device_code');
chk('null details: NO device-details statement is sent (only the insert)', count($withCols->writes) === 1);
chk('two tokens are never equal', $tok !== $tok2);
$withCols->writes = [];
apiTokenMint($withCols, 7, null, 'carrier-pigeon');
chk('an unknown how-made word is stored as NULL, never failing the sign-in', $withCols->writes[0][1][3] === null);
$withCols->writes = [];
apiTokenMint($withCols, 7, ['deviceInstallId' => "bad id; DROP"], 'password');
chk('a junk install id is dropped (NULL), never stored', $withCols->writes[0][1][4] === null);
$without = new MintDb(false);
$tok3 = apiTokenMint($without, 9, null, 'password');
chk('without the new columns the INSERT is exactly the old three-column one', $without->writes[0][0] === 'INSERT INTO tblApiTokens (Token, UserId, ExpiresAt) VALUES (?, ?, ?)' && count($without->writes[0][1]) === 3);
chk('apiTokenCleanInstallId: good ids pass, junk does not', apiTokenCleanInstallId('abcdef12') === 'abcdef12' && apiTokenCleanInstallId('short') === null && apiTokenCleanInstallId(str_repeat('a', 65)) === null && apiTokenCleanInstallId(42) === null);

exit($fail === 0 ? 0 : 1);
