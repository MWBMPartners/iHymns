<?php

declare(strict_types=1);

/**
 * test-admin-error-pages.php — admin pages never answer with bare, unstyled
 * error text; they use the one shared themed helper instead
 * ============================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * If someone opens an admin page they are not allowed to use, or a form has
 * timed out, they should see a proper iHymns error page that explains what
 * happened in plain words and gives them a way back. They used to get a blank
 * page with a line like "403 — manage_tunes required" or "Invalid CSRF token".
 * This check makes sure those bare replies never creep back in, and that the
 * shared helper that replaced them really does what it says.
 *
 * WHAT IS GUARDED
 * ----------------
 * A  No file under appWeb/public_html/manage/ contains any of the three bare
 *    patterns (comments ignored):
 *      - an inline `<h1>403` page,
 *      - `exit('Access denied…')` (a refusal with a raw message),
 *      - `echo 'Invalid CSRF token'` (also exit / die / print, either quote).
 *    ONE shape is deliberately allowed: the direct-access guard that every
 *    include opens with —
 *        if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
 *            http_response_code(403);
 *            exit('Access denied.');
 *        }
 *    That guard is not an admin refusing a signed-in person; it only fires if
 *    someone types the URL of an include file directly (and none of the shared
 *    helpers are loaded at that point, so it cannot use them). It is matched
 *    as that EXACT whole block and removed before scanning — any other
 *    `exit('Access denied…')`, including a lone `exit('Access denied.')`
 *    outside that block, still fails.
 *
 * B  The helper exists: manage/includes/admin-error.php defines adminDeny(),
 *    adminDenyEntitlement(), adminDenyRole() and adminDenyCsrf() (each ending
 *    the request, `: never`), and auth.php loads it — so every page that loads
 *    the admin bootstrap has it.
 *
 * C  The keys agree (rule #35 — a mechanism, not a comment):
 *      - every key a page passes to adminDenyEntitlement('…') is a real
 *        entitlement in includes/entitlements.php (a typo would otherwise
 *        produce a nonsense sentence and nothing red anywhere);
 *      - every key in the helper's plain-words override list is a real
 *        entitlement too, so that list cannot quietly go stale.
 *
 * D  The helper behaves — it is RUN, not just read (rule #34): in a separate
 *    PHP process (it ends with exit) the real admin bootstrap is loaded and
 *    each helper is called, then the output is checked:
 *      - a missing permission gives status 403, a human sentence, the two
 *        buttons, and NEVER the raw key (`manage_tunes`);
 *      - a CSRF failure gives status 403, "Your session expired", a "Go back"
 *        button, and a hostile request path (`//evil.example/x`) cannot turn
 *        that button into a link off the site;
 *      - the same calls from a script (X-Requested-With header) give JSON
 *        {"ok":false,"error":"…"} with status 403 instead of a page.
 *
 * DERIVED, NOT TYPED (rule #34)
 * ------------------------------
 * The file list is a recursive walk of manage/; the entitlement keys are read
 * from the call sites and from ENTITLEMENTS itself. Nothing here is a hand-typed
 * list of pages. Two floors (a minimum number of files scanned and of helper
 * call sites found) make the scan fail loudly if it ever silently stops seeing
 * the tree, instead of passing because it looked at nothing.
 *
 * The scanner is proven able to fail in two ways, both recorded below:
 *   1. SECTION 0 runs it over small fixtures — each bare pattern must be flagged,
 *      and comments / the exact direct-access guard must not be.
 *   2. MUTATION LOG (done by hand against the real tree, then restored from a
 *      backup copy):
 *        M1  put `echo '<!DOCTYPE html><html><body><h1>403 — x required</h1>…'`
 *            back into manage/tunes.php          -> A went RED (names the file)
 *        M2  put `echo 'Invalid CSRF token';` back into manage/tiers.php
 *                                                 -> A went RED
 *        M3  put `exit('Access denied. Admin role required.');` back into
 *            manage/includes/auth.php            -> A went RED
 *        M4  changed one include's direct-access guard to a lone
 *            `exit('Access denied.');` outside the guard block
 *                                                 -> A went RED
 *        M5  renamed adminDenyCsrf() in admin-error.php -> B went RED
 *        M6  typed `adminDenyEntitlement('manage_tunez')` in a page
 *                                                 -> C went RED
 *
 *   php tests/php/test-admin-error-pages.php
 *
 * Exit status 0 = pass, 1 = fail.
 *
 * @see appWeb/public_html/manage/includes/admin-error.php
 * @see appWeb/public_html/includes/error_page.php
 * @see tests/php/test-admin-gate-parity.php  (sibling guard: same style, same exit codes)
 */

$root   = dirname(__DIR__, 2);
$pub    = $root . '/appWeb/public_html';
$manage = $pub . '/manage';

$passed   = 0;
$failed   = 0;
$failures = [];

function check(string $name, bool $ok, string $detail = ''): void
{
    global $passed, $failed, $failures;
    if ($ok) {
        $passed++;
        echo "  PASS  $name\n";
    } else {
        $failed++;
        $failures[] = ['name' => $name, 'detail' => $detail];
        echo "  FAIL  $name\n";
        if ($detail !== '') {
            echo "        $detail\n";
        }
    }
}

/* ==========================================================================
 * THE SCANNER — shared by the fixture self-test (section 0) and the real tree
 * walk (section 1).
 * ========================================================================== */

/**
 * Source text with every PHP comment removed (block comments, doc-blocks and
 * `//` / `#` line comments), so a doc-block that DESCRIBES the old bare output ("it used
 * to echo 'Invalid CSRF token'") is never mistaken for code that does it.
 * String literals and inline HTML are kept — they are exactly what we scan.
 */
function adminErrStripComments(string $src): string
{
    $out = '';
    foreach (token_get_all($src) as $tok) {
        if (is_array($tok)) {
            if ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT) {
                /* Keep the line breaks so a reported position stays sensible. */
                $out .= str_repeat("\n", substr_count($tok[1], "\n"));
                continue;
            }
            $out .= $tok[1];
        } else {
            $out .= $tok;
        }
    }
    return $out;
}

/**
 * The ONE allowed `exit('Access denied.')`: the direct-access guard block.
 * Matched as the whole block (condition + status + exit), whitespace-tolerant,
 * so nothing else can hide behind it.
 */
function adminErrDirectAccessGuardRegex(): string
{
    return '~if\s*\(\s*basename\(\s*\$_SERVER\[\s*\'SCRIPT_FILENAME\'\s*\]\s*\?\?\s*\'\'\s*\)'
         . '\s*===\s*basename\(\s*__FILE__\s*\)\s*\)\s*\{'
         . '\s*http_response_code\(\s*403\s*\)\s*;'
         . '\s*exit\(\s*\'Access denied\.\'\s*\)\s*;\s*\}~';
}

/**
 * Find the bare patterns in one PHP source string.
 *
 * @return list<string> Human labels, one per distinct pattern found
 */
function adminErrFindBare(string $src): array
{
    $code = adminErrStripComments($src);
    /* Take out the exact direct-access guard block(s) first (see the file
       header for why that one shape is allowed). */
    $code = preg_replace(adminErrDirectAccessGuardRegex(), '', $code) ?? $code;

    $found = [];
    if (preg_match('~<h1>\s*403~i', $code)) {
        $found[] = 'inline "<h1>403" page';
    }
    if (preg_match('~exit\s*\(\s*[\'"]Access denied~i', $code)) {
        $found[] = "exit('Access denied…')";
    }
    if (preg_match('~\b(?:echo|exit|die|print)\b\s*\(?\s*[\'"]Invalid CSRF token~i', $code)) {
        $found[] = "bare 'Invalid CSRF token'";
    }
    return $found;
}

/* ==========================================================================
 * SECTION 0 — the scanner can fail, and does not over-fire
 * ========================================================================== */

echo "0 — scanner self-test (fixtures)\n";

$mustFlag = [
    'inline 403 page, single quotes' =>
        "<?php\nhttp_response_code(403);\necho '<!DOCTYPE html><html><body><h1>403 — manage_x required</h1></body></html>';\nexit;\n",
    'inline 403 page, with lang attr + spacing' =>
        "<?php\necho '<html lang=\"en\"><body><h1> 403 — nope</h1></body></html>';\n",
    "exit('Access denied. …')" =>
        "<?php\nhttp_response_code(403);\nexit('Access denied. The foo entitlement is required.');\n",
    "exit('Access denied — …') em dash" =>
        "<?php\nexit('Access denied — report requires the foo entitlement.');\n",
    "a LONE exit('Access denied.') outside the guard block" =>
        "<?php\nif (!\$currentUser) {\n    http_response_code(403);\n    exit('Access denied.');\n}\n",
    "guard-LIKE block with a different condition" =>
        "<?php\nif (\$_GET['x'] === 'y') {\n    http_response_code(403);\n    exit('Access denied.');\n}\n",
    "echo 'Invalid CSRF token'" =>
        "<?php\nhttp_response_code(403);\necho 'Invalid CSRF token';\nexit;\n",
    'echo "Invalid CSRF token" (double quotes)' =>
        "<?php\nhttp_response_code(403);\necho \"Invalid CSRF token\";\nexit;\n",
    "exit('Invalid CSRF token')" =>
        "<?php\nexit('Invalid CSRF token');\n",
];
foreach ($mustFlag as $label => $src) {
    check("flags: $label", adminErrFindBare($src) !== [], 'the scanner missed a bare pattern it must catch');
}

$mustNotFlag = [
    'the three phrases inside // and /* */ comments' =>
        "<?php\n// it used to echo 'Invalid CSRF token'\n/* <h1>403 — x required</h1> and exit('Access denied.') */\n"
        . "/**\n * exit('Access denied. Admin role required.');\n */\nadminDenyCsrf();\n",
    'the helper calls themselves' =>
        "<?php\nadminDenyEntitlement('manage_tunes');\nadminDenyCsrf();\nadminDenyRole('admin');\n",
    'the exact direct-access guard block' =>
        "<?php\nif (basename(\$_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {\n    http_response_code(403);\n    exit('Access denied.');\n}\n",
    'the guard block, reformatted (extra spaces / line breaks)' =>
        "<?php\nif (  basename( \$_SERVER[ 'SCRIPT_FILENAME' ] ?? '' )\n    === basename( __FILE__ )  ) {\n  http_response_code( 403 ) ;\n  exit( 'Access denied.' ) ;\n}\n",
];
foreach ($mustNotFlag as $label => $src) {
    check("does not flag: $label", adminErrFindBare($src) === [], 'false positive: ' . implode(', ', adminErrFindBare($src)));
}

/* A guard block must not LAUNDER a real violation sitting next to it. */
check(
    'a real violation next to a valid guard block is still flagged',
    adminErrFindBare(
        "<?php\nif (basename(\$_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {\n    http_response_code(403);\n    exit('Access denied.');\n}\n"
        . "exit('Access denied. Admin role required.');\n"
    ) !== [],
    'the guard exemption swallowed a genuine violation'
);

/* ==========================================================================
 * SECTION 1 — A: no bare patterns anywhere under manage/
 * ========================================================================== */

echo "\n1 — A: no bare 403 / Access denied / Invalid CSRF output under manage/\n";

$files = [];
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($manage, FilesystemIterator::SKIP_DOTS)
);
foreach ($it as $f) {
    /** @var SplFileInfo $f */
    if ($f->isFile() && strtolower($f->getExtension()) === 'php') {
        $files[] = $f->getPathname();
    }
}
sort($files);

check(
    'scanned a realistic number of manage/ files (floor 60 — a broken walk must not pass vacuously)',
    count($files) >= 60,
    'only ' . count($files) . ' files found under ' . $manage
);

$violations = [];
$sources    = [];   /* path => comment-stripped source, reused below */
foreach ($files as $path) {
    $raw = (string) file_get_contents($path);
    $rel = substr($path, strlen($manage) + 1);
    $sources[$rel] = adminErrStripComments($raw);
    foreach (adminErrFindBare($raw) as $label) {
        $violations[] = "$rel — $label";
    }
}
check(
    'no file under manage/ answers with a bare 403 page, "Access denied" text or "Invalid CSRF token"',
    $violations === [],
    "use adminDenyEntitlement() / adminDenyRole() / adminDenyCsrf() / adminDeny() instead:\n          - "
    . implode("\n          - ", $violations)
);

/* ==========================================================================
 * SECTION 2 — B: the helper exists and is loaded by the admin bootstrap
 * ========================================================================== */

echo "\n2 — B: the shared helper exists and auth.php loads it\n";

$helperPath = $manage . '/includes/admin-error.php';
check('manage/includes/admin-error.php exists', is_file($helperPath));
$helperSrc = is_file($helperPath) ? adminErrStripComments((string) file_get_contents($helperPath)) : '';

foreach (['adminDeny', 'adminDenyEntitlement', 'adminDenyRole', 'adminDenyCsrf'] as $fn) {
    check(
        "$fn() is defined and ends the request (`: never`)",
        (bool) preg_match('~function\s+' . $fn . '\s*\([^)]*\)\s*:\s*never\b~', $helperSrc),
        "expected `function $fn(...): never` in admin-error.php"
    );
}
check(
    'the helper renders through the shared themed page (renderErrorPage), not its own markup',
    str_contains($helperSrc, 'renderErrorPage('),
    'adminDeny() must reuse includes/error_page.php (modularity rule)'
);

$authSrc = adminErrStripComments((string) file_get_contents($manage . '/includes/auth.php'));
check(
    'auth.php requires admin-error.php (so every admin page has the helper)',
    (bool) preg_match("~require_once\s+__DIR__\s*\.\s*DIRECTORY_SEPARATOR\s*\.\s*'admin-error\.php'~", $authSrc),
    'the bootstrap no longer loads the helper'
);
foreach (['requireAdmin' => 'admin', 'requireEditor' => 'editor', 'requireGlobalAdmin' => 'global_admin'] as $fn => $role) {
    $ok = (bool) preg_match('~function\s+' . $fn . '\s*\(\)\s*:\s*void\s*\{.*?adminDenyRole\(\s*\'' . $role . '\'\s*\)~s', $authSrc);
    check("$fn() refuses through adminDenyRole('$role')", $ok, "$fn() in auth.php no longer uses the shared helper");
}

/* ==========================================================================
 * SECTION 3 — C: the entitlement keys agree
 * ========================================================================== */

echo "\n3 — C: entitlement keys passed to the helper are real\n";

require_once $pub . '/includes/entitlements.php';   /* defines ENTITLEMENTS (pure data + functions) */
require_once $helperPath;                            /* pulls in includes/error_page.php; no side effects */

$callKeys  = [];   /* key => list of files */
$entCalls  = 0;
$csrfCalls = 0;
foreach ($sources as $rel => $code) {
    if ($rel === 'includes/admin-error.php') {
        continue;   /* the helper's own definitions are not call sites */
    }
    if (preg_match_all("~adminDenyEntitlement\(\s*'([a-z0-9_]+)'~", $code, $m)) {
        foreach ($m[1] as $k) {
            $callKeys[$k][] = $rel;
            $entCalls++;
        }
    }
    $csrfCalls += preg_match_all('~adminDenyCsrf\(~', $code);
}

check(
    "found a realistic number of adminDenyEntitlement('…') call sites (floor 30)",
    $entCalls >= 30,
    "only $entCalls found — either pages stopped using the helper or this scan stopped seeing them"
);
check(
    'found a realistic number of adminDenyCsrf() call sites (floor 20)',
    $csrfCalls >= 20,
    "only $csrfCalls found — either pages stopped using the helper or this scan stopped seeing them"
);

$unknown = [];
foreach ($callKeys as $k => $where) {
    if (!isset(ENTITLEMENTS[$k])) {
        $unknown[] = "'$k' (in " . implode(', ', array_unique($where)) . ')';
    }
}
check(
    'every key passed to adminDenyEntitlement() is a real entitlement',
    $unknown === [],
    'not in ENTITLEMENTS: ' . implode('; ', $unknown)
);

$badOverrides = [];
foreach (array_keys(adminEntitlementPhraseOverrides()) as $k) {
    if (!isset(ENTITLEMENTS[$k])) {
        $badOverrides[] = $k;
    }
}
check(
    'every key in the plain-words override list names a real entitlement',
    $badOverrides === [],
    'stale override(s): ' . implode(', ', $badOverrides)
);

/* ==========================================================================
 * SECTION 4 — D: the helper behaves (run in a separate process; it exits)
 * ========================================================================== */

echo "\n4 — D: the helper really produces the pages and JSON described\n";

/**
 * Run one helper call in a fresh PHP process with the real admin bootstrap
 * loaded, and return what it printed plus the status code it set.
 *
 * @return array{out:string,status:int}|null null when a process cannot be started here
 */
function adminErrRun(string $callCode, bool $asScript, string $requestUri, string $authPath): ?array
{
    if (!function_exists('proc_open')) {
        return null;
    }
    $code = '$_SERVER["REQUEST_URI"] = ' . var_export($requestUri, true) . ';'
          . ($asScript ? '$_SERVER["HTTP_X_REQUESTED_WITH"] = "XMLHttpRequest";' : '')
          . 'require ' . var_export($authPath, true) . ';'
          . 'register_shutdown_function(function () { echo "\n@@STATUS=" . http_response_code(); });'
          . $callCode;
    $proc = @proc_open(
        [PHP_BINARY, '-d', 'display_errors=0', '-r', $code],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (!is_resource($proc)) {
        return null;
    }
    $out = (string) stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    $status = 0;
    if (preg_match('~\n@@STATUS=(\d+)\s*$~', $out, $m)) {
        $status = (int) $m[1];
        $out = substr($out, 0, -strlen($m[0]));
    }
    return ['out' => $out, 'status' => $status];
}

$authPath = $manage . '/includes/auth.php';
$probe = adminErrRun("adminDenyEntitlement('manage_tunes');", false, '/manage/tunes', $authPath);

if ($probe === null) {
    echo "  SKIP  could not start a PHP subprocess here — behaviour checks skipped\n";
} else {
    /* The page text, with the big <style>/<script> blocks removed so a match
       means "a person can read this", not "it appears in the CSS". */
    $visible = static function (string $html): string {
        $html = preg_replace('~<(style|script)\b.*?</\1>~is', '', $html) ?? $html;
        return html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8');
    };

    /* --- missing permission, as a page ------------------------------- */
    $text = $visible($probe['out']);
    check('permission refusal: status 403', $probe['status'] === 403, 'got ' . $probe['status']);
    check(
        'permission refusal: a human sentence ("You don\'t have permission to manage tunes.")',
        str_contains($text, "You don't have permission to manage tunes."),
        'visible text was: ' . trim(preg_replace('~\s+~', ' ', $text) ?? $text)
    );
    check(
        'permission refusal: the raw key manage_tunes is NOT shown anywhere on the page',
        !str_contains($probe['out'], 'manage_tunes'),
        'the internal key leaked into the page'
    );
    check(
        'permission refusal: offers "Back to dashboard" (/manage/) and "Go to iHymns" (/)',
        str_contains($probe['out'], 'href="/manage/"') && str_contains($probe['out'], 'href="/"')
            && str_contains($text, 'Back to dashboard') && str_contains($text, 'Go to iHymns'),
        'one of the two way-out buttons is missing'
    );

    /* --- an awkward key reads as words, not as a key ------------------ */
    $p2 = adminErrRun("adminDenyEntitlement('run_db_install');", false, '/manage/setup-database', $authPath);
    check(
        'an awkward key uses its plain-words phrase ("install or upgrade the database")',
        $p2 !== null && str_contains($visible($p2['out']), 'permission to install or upgrade the database.')
            && !str_contains($p2['out'], 'run_db_install'),
        $p2 === null ? 'no output' : 'visible text was: ' . trim($visible($p2['out']))
    );

    /* --- CSRF failure, as a page ------------------------------------- */
    $csrf = adminErrRun('adminDenyCsrf();', false, '/manage/tiers?tab=2', $authPath);
    $ctext = $csrf === null ? '' : $visible($csrf['out']);
    check('CSRF failure: status 403', $csrf !== null && $csrf['status'] === 403, 'got ' . ($csrf['status'] ?? 'null'));
    check(
        'CSRF failure: says the session expired and that nothing was saved',
        str_contains($ctext, 'Your session expired') && str_contains($ctext, "your changes weren't saved"),
        'visible text was: ' . trim(preg_replace('~\s+~', ' ', $ctext) ?? $ctext)
    );
    check(
        'CSRF failure: "Go back" returns to the same page without the query string',
        $csrf !== null && str_contains($csrf['out'], 'href="/manage/tiers"') && str_contains($ctext, 'Go back'),
        'expected a Go back link to /manage/tiers'
    );
    check(
        'CSRF failure: the raw words "Invalid CSRF token" are gone',
        $csrf !== null && stripos($csrf['out'], 'Invalid CSRF token') === false,
        'old bare wording still shown'
    );

    /* --- a hostile request path cannot make "Go back" leave the site --- */
    foreach (['//evil.example/x', '/elsewhere', '/manage/../etc', '/manage//evil.example/x'] as $bad) {
        $h = adminErrRun('adminDenyCsrf();', false, $bad, $authPath);
        check(
            "\"Go back\" ignores a hostile request path ($bad) and falls back to /manage/",
            $h !== null && str_contains($h['out'], 'ep-btn-primary" href="/manage/">Go back'),
            $h === null ? 'no output' : 'the Go back link was not the safe fallback'
        );
    }

    /* A full URL in the request line is cut down to its path: the host part is
       never carried into the link. */
    $full = adminErrRun('adminDenyCsrf();', false, 'https://evil.example/manage/x', $authPath);
    check(
        '"Go back" never carries a host name (a full URL is cut down to its /manage path)',
        $full !== null && str_contains($full['out'], 'href="/manage/x"') && !str_contains($full['out'], 'evil.example'),
        $full === null ? 'no output' : 'the host leaked into the page'
    );

    /* --- the same calls from a script get JSON ----------------------- */
    $j = adminErrRun("adminDenyEntitlement('manage_tunes');", true, '/manage/tunes', $authPath);
    $jd = $j === null ? null : json_decode(trim($j['out']), true);
    check(
        'from a script: permission refusal is JSON {"ok":false,"error":"…"} with status 403 (not HTML)',
        $j !== null && $j['status'] === 403 && is_array($jd) && ($jd['ok'] ?? null) === false
            && is_string($jd['error'] ?? null) && $jd['error'] !== '' && !str_contains($j['out'], '<html'),
        'output was: ' . substr(trim($j['out'] ?? ''), 0, 200)
    );
    $jc = adminErrRun('adminDenyCsrf();', true, '/manage/tunes', $authPath);
    $jcd = $jc === null ? null : json_decode(trim($jc['out']), true);
    check(
        'from a script: CSRF failure is JSON {"ok":false,"error":"Your session expired…"} with status 403',
        $jc !== null && $jc['status'] === 403 && is_array($jcd) && ($jcd['ok'] ?? null) === false
            && str_contains((string) ($jcd['error'] ?? ''), 'Your session expired'),
        'output was: ' . substr(trim($jc['out'] ?? ''), 0, 200)
    );
    $jf = adminErrRun("adminDenyCsrf(['format' => 'json', 'json' => ['error' => 'x']]);", false, '/manage/tunes', $authPath);
    $jfd = $jf === null ? null : json_decode(trim($jf['out']), true);
    check(
        "an endpoint can keep its own JSON shape via the 'json' option (here {\"error\":\"x\"})",
        $jf !== null && $jfd === ['error' => 'x'] && $jf['status'] === 403,
        'output was: ' . substr(trim($jf['out'] ?? ''), 0, 200)
    );
}

/* ==========================================================================
 * VERDICT
 * ========================================================================== */

echo "\n$passed passed, $failed failed\n";
if ($failed > 0) {
    echo "\nFailures:\n";
    foreach ($failures as $f) {
        echo "  - {$f['name']}" . ($f['detail'] !== '' ? "\n    {$f['detail']}" : '') . "\n";
    }
    exit(1);
}
exit(0);
