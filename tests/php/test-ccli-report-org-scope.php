<?php

declare(strict_types=1);

/**
 * iHymns — CCLI report org-scope isolation guard (#1861)
 * ============================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * The org-facing CCLI report lets a church admin pull THEIR church's
 * printed-copy usage. This test proves the one thing that must never break:
 * church A's admin can see church A's numbers and ONLY church A's numbers —
 * never church B's, never the copies nobody could attribute to any org. It
 * proves it two ways: (Part A) by reading the real source and confirming the
 * org page has no code path that could even ASK the all-orgs question, and
 * (Part B) by seeding two churches' usage in a throwaway transaction and
 * checking A's query returns exactly A's rows.
 *
 * WHY BOTH HALVES
 * ---------------
 * Modelled on `tests/php/test-live-follow-extend.php` (Part A source scan
 * always; Part B behavioural against a live DB, one transaction rolled back
 * at the end, SKIP when no reachable dbname). A behavioural test alone can
 * be defeated by a future refactor that quietly swaps the org page's row
 * source for the system query on a code path the fixtures happen not to
 * exercise; a source scan alone can't prove the SQL actually isolates. The
 * two together make an org-data leak structurally hard to introduce without
 * a RED here (rule #34 — derived from the tree, mutation-proven).
 *
 * PART A — structural (source scan of the REAL files, comment-stripped via
 * the tokenizer so a doc-block MENTION of the system query can never vouch
 * for or falsely trip a check — the test-print-usage-ccli-gate.php discipline):
 *   A1. `manage/my-ccli-report.php` contains `ccliReportOrgRows(` and contains
 *       NEITHER `ccliReportSystemRows(` NOR any `tblSongHistory` reference —
 *       the org page is INCAPABLE of the unscoped/all-orgs query, and there
 *       is no org-scoped "Views" (decision O3).
 *   A2. The page derives scope from membership: it calls `userIsOrgAdminOf(`
 *       and `ccliReportResolveOrgScope(`; `$_GET['org']` is read ONCE, into
 *       `$rawOrg`, which — after an `is_array` guard (a non-scalar `?org[]=`
 *       is a graceful denial, not a strict_types TypeError → 500) — is the
 *       only value fed to the resolver (never to a query builder directly).
 *   A3. In `includes/ccli_report.php`, `ccliReportOrgRows` early-returns on
 *       `$orgIds === []` BEFORE any `prepare(`; its SQL contains `OrgId IN (`
 *       with placeholders built from `array_fill(0, count($orgIds), '?')`
 *       (rule #5) — never a raw `$orgIds` value spliced into the SQL string.
 *   A4. Gate parity: the page's own entitlement gate names
 *       `view_org_ccli_report` (also enforced independently by
 *       test-admin-gate-parity.php, but asserted HERE too so THIS suite fails
 *       atomically with a pointed message if the gate string drifts).
 *   A5. W1: `printUsageResolveCcliLicence()`'s body PREFERS an `'org'`-sourced
 *       row — the `=== 'org'` branch returns BEFORE the user-sourced fallback.
 *   A6. Both entitlement maps (PHP + JS) contain `view_org_ccli_report`.
 *
 * PART B — behavioural isolation (live DB, one rolled-back transaction, else
 * SKIP). Seeds orgs A and B; users uA (owner on A), uB (admin on B), uM
 * (member on A — must NOT qualify), uN (no rows); one song with a CCLI
 * number; `tblSongUsageEvents` rows: 3 printed copies for OrgId=A, 5 for
 * OrgId=B, 2 with OrgId=NULL, all `UsedAt` inside the window, plus one A-row
 * OUTSIDE the window:
 *   B1. THE isolation property — `ccliReportOrgRows($db, userIsOrgAdminOf(uA),
 *       …)` returns printed_copies EXACTLY 3 (B's 5 absent, the NULL-org 2
 *       absent, the out-of-window row absent). Symmetrically uB → exactly 5.
 *   B2. `userIsOrgAdminOf(uM) === []` (a member is not an admin) and `=== []`
 *       for uN; `ccliReportOrgRows($db, [], …) === []` (the structural
 *       refusal — no query issued on an empty scope).
 *   B3. `ccliReportResolveOrgScope('<B>', [A])` → denied (forged ?org=);
 *       `ccliReportResolveOrgScope(null, [A,B])` → [A]; `…('<B>', [A,B])`→[B].
 *   B4. System parity — `ccliReportSystemRows(…, orgFilter:A)` printed=3;
 *       `unattributed:true` printed=2; unfiltered printed=10 (3+5+2).
 *   B5. W1 functional — uA holds BOTH a well-formed personal
 *       `tblUsers.CcliNumber` AND org A holds an active `ccli`
 *       `tblOrganisationLicences` row; `printUsageResolveCcliLicence(uA)`
 *       resolves the ORG row (`source==='org'`, `source_id===A`), and
 *       `printUsageLog(uA, songId, 1, [])` writes a row with `OrgId = A`
 *       (read back inside the transaction).
 *
 * MUTATION PROOFS (each applied to the REAL tree, observed RED, reverted —
 * recorded in the commit body):
 *   - Delete `AND u.OrgId IN (…)` from `ccliReportOrgRows` → B1 RED (A's
 *     report suddenly contains B's 5 → printed 10, not 3).
 *   - Change the empty-`$orgIds` early return to fall through → B2 RED (and
 *     A3 RED — the return no longer precedes prepare()).
 *   - Make `ccliReportResolveOrgScope` fall back to `$allowedOrgIds[0]` on a
 *     non-member `?org=` instead of denying → B3 RED.
 *   - Swap the page's row source to `ccliReportSystemRows(` → A1 RED.
 *   - Change the page's gate string to `view_ccli_report` → A4 RED (and
 *     test-admin-gate-parity.php RED).
 *   - Revert the W1 preference (org branch after the fallback) → A5 + B5 RED.
 *
 * Usage:
 *   php tests/php/test-ccli-report-org-scope.php
 *      (Part B needs IHYMNS_TEST_DSN with a dbname, or
 *       appWeb/.auth/db_credentials.php; else Part A still runs.)
 * Exit: 0 = pass (Part B may SKIP), 1 = any failure.
 *
 * @see appWeb/public_html/includes/ccli_report.php        the query core under test
 * @see appWeb/public_html/manage/my-ccli-report.php        the org-facing page
 * @see appWeb/public_html/includes/print_usage.php         printUsageResolveCcliLicence() (W1)
 * @see appWeb/public_html/includes/entitlements.php        userIsOrgAdminOf() — the membership lookup
 * @see tests/php/test-live-follow-extend.php               the Part A/B + fixture pattern this mirrors
 * @see tests/php/test-print-usage-ccli-gate.php            the comment-strip + one-writer sibling
 * @link #1861
 */

$repoRoot   = dirname(__DIR__, 2);
$publicRoot = $repoRoot . '/appWeb/public_html';

$failures = 0;
$passed   = 0;
function cros(bool $cond, string $label): void
{
    global $failures, $passed;
    if ($cond) { $passed++; echo "PASS: $label\n"; }
    else       { $failures++; fwrite(STDERR, "FAIL: $label\n"); }
}

/** Strip comments via the real tokenizer (never a slash-star regex — a naive
 *  regex would match the very prose in a doc-block that EXPLAINS the gate;
 *  the test-print-usage-ccli-gate.php precedent). Line numbers preserved. */
function crosStripComments(string $src): string
{
    $out = '';
    foreach (token_get_all($src) as $tok) {
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

/** Extract a top-level function's body (braces BALANCED, so nested
 *  if/foreach spans correctly — unlike a lazy regex). Returns null if absent. */
function crosFunctionBody(string $src, string $name): ?string
{
    if (!preg_match('/function\s+' . preg_quote($name, '/') . '\s*\([^)]*\)[^{]*\{/', $src, $m, PREG_OFFSET_CAPTURE)) {
        return null;
    }
    $bracePos = $m[0][1] + strlen($m[0][0]) - 1; // the opening '{' itself
    $depth = 0;
    $len = strlen($src);
    for ($i = $bracePos; $i < $len; $i++) {
        if ($src[$i] === '{') { $depth++; }
        elseif ($src[$i] === '}') {
            $depth--;
            if ($depth === 0) { return substr($src, $bracePos + 1, $i - $bracePos - 1); }
        }
    }
    return null;
}

/* =============================================================================
 * PART A — structural source scan (always runs, no DB)
 * ============================================================================= */

$orgPagePath   = $publicRoot . '/manage/my-ccli-report.php';
$corePath      = $publicRoot . '/includes/ccli_report.php';
$printUsagePath = $publicRoot . '/includes/print_usage.php';
$entPhpPath    = $publicRoot . '/includes/entitlements.php';
$entJsPath     = $publicRoot . '/js/modules/entitlements.js';

cros(is_file($orgPagePath),   'A0.1 manage/my-ccli-report.php exists');
cros(is_file($corePath),      'A0.2 includes/ccli_report.php exists');
cros(is_file($printUsagePath), 'A0.3 includes/print_usage.php exists');
cros(is_file($entPhpPath),    'A0.4 includes/entitlements.php exists');
cros(is_file($entJsPath),     'A0.5 js/modules/entitlements.js exists');
if ($failures > 0) {
    fwrite(STDERR, "\nFATAL: one or more #1861 files are missing — cannot continue.\n");
    exit(1);
}

$orgPageSrc    = crosStripComments((string)file_get_contents($orgPagePath));
$coreSrc       = crosStripComments((string)file_get_contents($corePath));
$printUsageSrc = crosStripComments((string)file_get_contents($printUsagePath));
$entPhpSrc     = (string)file_get_contents($entPhpPath); // maps are code, but a comment mention is harmless here
$entJsSrc      = (string)file_get_contents($entJsPath);

/* ---- A1: the org page is incapable of the all-orgs / views query ---------- */
cros(str_contains($orgPageSrc, 'ccliReportOrgRows('),
    'A1.1 my-ccli-report.php calls ccliReportOrgRows() (its only row source)');
cros(!str_contains($orgPageSrc, 'ccliReportSystemRows('),
    'A1.2 my-ccli-report.php NEVER references ccliReportSystemRows() (the all-orgs query is unreachable from here)');
cros(!str_contains($orgPageSrc, 'tblSongHistory'),
    'A1.3 my-ccli-report.php NEVER references tblSongHistory (there is no org-scoped Views — decision O3)');

/* ---- A2: scope derived from membership; $_GET[org] only reaches resolver -- */
cros(str_contains($orgPageSrc, 'userIsOrgAdminOf('),
    'A2.1 my-ccli-report.php derives scope from userIsOrgAdminOf() (the membership lookup)');
cros(str_contains($orgPageSrc, 'ccliReportResolveOrgScope('),
    'A2.2 my-ccli-report.php validates the requested org via ccliReportResolveOrgScope()');
/* $_GET['org'] is read exactly once, captured into $rawOrg; only $rawOrg —
   after an is_array guard — reaches the validating resolver, so a raw ?org=
   never flows to a query builder directly (A2.3). A non-scalar ?org[]= is a
   graceful denial rather than a strict_types TypeError -> HTTP 500 (A2.4). */
$getOrgCount   = preg_match_all('/\$_GET\[[\'"]org[\'"]\]/', $orgPageSrc);
$rawOrgCapture = preg_match('/\$rawOrg\s*=\s*\$_GET\[[\'"]org[\'"]\]/', $orgPageSrc);
cros(
    $getOrgCount === 1 && $rawOrgCapture === 1,
    "A2.3 \$_GET['org'] is read exactly once ({$getOrgCount}), captured into \$rawOrg — a raw ?org= never reaches a query builder directly"
);
cros(
    (bool)preg_match('/is_array\(\s*\$rawOrg\s*\)/', $orgPageSrc)
        && (bool)preg_match('/ccliReportResolveOrgScope\(\s*\$rawOrg\b/', $orgPageSrc),
    'A2.4 a non-scalar ?org[]= is guarded (is_array($rawOrg) → denial) before $rawOrg reaches the ?string resolver — a malformed selector 403s, never TypeErrors to a 500'
);

/* ---- A3: the core's structural refusal + rule-#5 placeholders ------------- */
$orgRowsBody = crosFunctionBody($coreSrc, 'ccliReportOrgRows');
cros($orgRowsBody !== null, 'A3.0 extracted ccliReportOrgRows() body from the core');
if ($orgRowsBody !== null) {
    $emptyGuardPos = strpos($orgRowsBody, '$orgIds === []');
    $preparePos    = strpos($orgRowsBody, 'prepare(');
    cros(
        $emptyGuardPos !== false && $preparePos !== false && $emptyGuardPos < $preparePos,
        'A3.1 ccliReportOrgRows() early-returns on `$orgIds === []` BEFORE any prepare() — the structural refusal (no unscoped query path exists)'
    );
    /* The empty guard actually RETURNS (not just tests) before the query. */
    cros(
        (bool)preg_match('/\$orgIds\s*===\s*\[\]\s*\)\s*\{\s*return\s+\[\]\s*;/', $orgRowsBody),
        'A3.2 the empty-$orgIds branch returns [] (refuses to run), never falls through to a query'
    );
    cros(
        str_contains($orgRowsBody, 'OrgId IN ('),
        'A3.3 the org query carries an `OrgId IN (…)` predicate (the per-org filter)'
    );
    cros(
        str_contains($orgRowsBody, "array_fill(0, count(\$orgIds), '?')")
            && str_contains($orgRowsBody, "str_repeat('i', count(\$orgIds))"),
        'A3.4 placeholders + bind-types are built from count($orgIds) via array_fill/str_repeat (rule #5) — never a raw $orgIds value spliced into the SQL'
    );
}

/* ---- A4: the page's own gate names the org entitlement -------------------- */
cros(
    (bool)preg_match('/userHasEntitlement\(\s*[\'"]view_org_ccli_report[\'"]/', $orgPageSrc),
    'A4 my-ccli-report.php gates on userHasEntitlement(\'view_org_ccli_report\') (== the admin-links.php entitlement, #1587)'
);

/* ---- A5: W1 — printUsageResolveCcliLicence prefers an org-sourced row ------ */
$resolveBody = crosFunctionBody($printUsageSrc, 'printUsageResolveCcliLicence');
cros($resolveBody !== null, 'A5.0 extracted printUsageResolveCcliLicence() body');
if ($resolveBody !== null) {
    $orgBranchPos = strpos($resolveBody, "=== 'org'");
    $fallbackPos  = strpos($resolveBody, 'return $fallback');
    cros(
        (bool)preg_match('/===\s*[\'"]org[\'"]\s*\)\s*\{\s*return\s+\$l\s*;/', $resolveBody),
        'A5.1 an `=== \'org\'` branch returns $l directly (the org-held licence wins)'
    );
    cros(
        $orgBranchPos !== false && $fallbackPos !== false && $orgBranchPos < $fallbackPos,
        'A5.2 the org-preference branch appears BEFORE the user-sourced `return $fallback` (W1 — org attribution, not the personal number)'
    );
}

/* ---- A6: both entitlement maps carry the new key -------------------------- */
cros(str_contains($entPhpSrc, "'view_org_ccli_report'"),
    'A6.1 includes/entitlements.php registers view_org_ccli_report');
cros(str_contains($entJsSrc, 'view_org_ccli_report'),
    'A6.2 js/modules/entitlements.js mirrors view_org_ccli_report (parity)');

/* =============================================================================
 * PART B — behavioural isolation (DB, else SKIP)
 * ============================================================================= */

$host = '127.0.0.1'; $user = 'root'; $pass = ''; $sock = null; $port = 3306; $dbName = null;
$credFile = $repoRoot . '/appWeb/.auth/db_credentials.php';
if (is_readable($credFile)) {
    require $credFile;
    if (defined('DB_HOST')) { $host = DB_HOST; }
    if (defined('DB_USER')) { $user = DB_USER; }
    if (defined('DB_PASS')) { $pass = DB_PASS; }
    if (defined('DB_PORT')) { $port = (int)DB_PORT; }
    if (defined('DB_NAME')) { $dbName = DB_NAME; }
} else {
    $dsn = getenv('IHYMNS_TEST_DSN') ?: '';
    if ($dsn !== '') {
        foreach (explode(';', $dsn) as $kv) {
            [$k, $v] = array_pad(explode('=', $kv, 2), 2, '');
            if ($k === 'host')   { $host = $v; }
            if ($k === 'user')   { $user = $v; }
            if ($k === 'pass')   { $pass = $v; }
            if ($k === 'socket') { $sock = $v; }
            if ($k === 'port')   { $port = (int)$v; }
            if ($k === 'dbname' || $k === 'db') { $dbName = $v; }
        }
    }
}

$behaviouralRan = false;
if ($dbName !== null && $sock === null) {
    if (!defined('DB_HOST')) { define('DB_HOST', $host); }
    if (!defined('DB_USER')) { define('DB_USER', $user); }
    if (!defined('DB_PASS')) { define('DB_PASS', $pass); }
    if (!defined('DB_PORT')) { define('DB_PORT', (string)$port); }
    if (!defined('DB_NAME')) { define('DB_NAME', $dbName); }

    require_once $publicRoot . '/includes/db_mysql.php';
    require_once $publicRoot . '/includes/entitlements.php';   // userIsOrgAdminOf()
    require_once $publicRoot . '/includes/ccli_report.php';    // pulls print_usage.php + licences.php + csv_safe.php

    try {
        $db = getDbMysqli();
        /* Every table Part B seeds must exist (schema-only DBs are fine —
           the transaction seeds its own rows). */
        $need = ['tblOrganisations', 'tblOrganisationMembers', 'tblUsers',
                 'tblSongbooks', 'tblSongs', 'tblSongUsageEvents', 'tblOrganisationLicences'];
        $haveAll = true;
        foreach ($need as $t) {
            if ((int)$db->query("SELECT COUNT(*) c FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$t'")->fetch_assoc()['c'] < 1) {
                $haveAll = false;
                break;
            }
        }
    } catch (\Throwable $e) {
        $db = null; $haveAll = false;
    }

    if ($db !== null && $haveAll) {
        $db->begin_transaction();
        try {
            $tag = substr((string)mt_rand(), 0, 6);

            /* ---- fixture builders (STRICT mysqli — every NOT-NULL-no-default
                    column supplied; rows rolled back at the end) ------------- */
            $mkUser = function (string $who, string $ccli = '') use ($db, $tag): int {
                $u = 'zzcros_' . $who . $tag;
                $e = $u . '@example.invalid';
                $stmt = $db->prepare(
                    "INSERT INTO tblUsers (Username, Email, EmailVerified, PasswordHash, DisplayName, Role, CcliNumber, IsActive)
                     VALUES (?, ?, 0, 'x', ?, 'user', ?, 1)"
                );
                $stmt->bind_param('ssss', $u, $e, $u, $ccli);
                $stmt->execute();
                $id = (int)$db->insert_id;
                $stmt->close();
                return $id;
            };
            $mkOrg = function (string $who) use ($db, $tag): int {
                $n = 'ZZcros ' . $who . ' ' . $tag;
                $s = 'zzcros-' . $who . '-' . $tag . '-' . bin2hex(random_bytes(2));
                $stmt = $db->prepare('INSERT INTO tblOrganisations (Name, Slug, IsActive) VALUES (?, ?, 1)');
                $stmt->bind_param('ss', $n, $s);
                $stmt->execute();
                $id = (int)$db->insert_id;
                $stmt->close();
                return $id;
            };
            $join = function (int $userId, int $orgId, string $role) use ($db): void {
                $stmt = $db->prepare('INSERT INTO tblOrganisationMembers (UserId, OrgId, Role) VALUES (?, ?, ?)');
                $stmt->bind_param('iis', $userId, $orgId, $role);
                $stmt->execute();
                $stmt->close();
            };
            $mkSongbook = function (string $abbr) use ($db, $tag): void {
                $name = 'ZZcros book ' . $tag;
                $stmt = $db->prepare('INSERT INTO tblSongbooks (Abbreviation, Name) VALUES (?, ?)');
                $stmt->bind_param('ss', $abbr, $name);
                $stmt->execute();
                $stmt->close();
            };
            $mkSong = function (string $songId, string $abbr, string $ccli) use ($db, $tag): void {
                $title = 'ZZcros song ' . $tag;
                $stmt = $db->prepare('INSERT INTO tblSongs (SongId, Title, SongbookAbbr, Ccli) VALUES (?, ?, ?, ?)');
                $stmt->bind_param('ssss', $songId, $title, $abbr, $ccli);
                $stmt->execute();
                $stmt->close();
            };
            /* One printed-usage row. $orgId null => the "Unattributed" slice.
               $usedAt is an explicit UTC datetime so window in/out is exact. */
            $mkUsage = function (string $songId, ?int $orgId, string $usedAt, int $qty) use ($db): void {
                $stmt = $db->prepare(
                    "INSERT INTO tblSongUsageEvents (SongId, OrgId, UsedAt, UsageContext, Quantity)
                     VALUES (?, ?, ?, 'printed', ?)"
                );
                $stmt->bind_param('sisi', $songId, $orgId, $usedAt, $qty);
                $stmt->execute();
                $stmt->close();
            };
            $mkOrgLicence = function (int $orgId, string $number) use ($db): void {
                $stmt = $db->prepare(
                    "INSERT INTO tblOrganisationLicences (OrganisationId, LicenceType, LicenceNumber, IsActive)
                     VALUES (?, 'ccli', ?, 1)"
                );
                $stmt->bind_param('is', $orgId, $number);
                $stmt->execute();
                $stmt->close();
            };

            /* ---- seed: two orgs, four users, one song, five usage rows ----- */
            $orgA = $mkOrg('A');
            $orgB = $mkOrg('B');
            $uA = $mkUser('ownerA', '1234567');   // owner on A + a well-formed PERSONAL ccli (for W1/B5)
            $uB = $mkUser('adminB');              // admin on B
            $uM = $mkUser('memberA');             // member on A — must NOT qualify as an org admin
            $uN = $mkUser('none');                // no memberships at all
            $join($uA, $orgA, 'owner');
            $join($uB, $orgB, 'admin');
            $join($uM, $orgA, 'member');
            $mkOrgLicence($orgA, '7654321');      // org A's own active ccli licence (for W1/B5)

            $abbr   = 'ZC' . strtoupper(bin2hex(random_bytes(2))); // <=10 chars, unique
            $songId = $abbr . '-9001';
            $mkSongbook($abbr);
            $mkSong($songId, $abbr, '1122334');

            $window  = ccliReportWindow([]);      // the SAME default window the pages use
            $from    = $window['from'];
            $to      = $window['to'];
            $inWin   = gmdate('Y-m-d H:i:s', time() - 5 * 86400);   // clearly inside (5 days ago)
            $outWin  = gmdate('Y-m-d H:i:s', time() - 60 * 86400);  // clearly outside (60 days ago)

            $mkUsage($songId, $orgA, $inWin, 3);   // org A: 3 in window
            $mkUsage($songId, $orgB, $inWin, 5);   // org B: 5 in window
            $mkUsage($songId, null, $inWin, 2);    // unattributed: 2 in window
            $mkUsage($songId, $orgA, $outWin, 99); // org A: OUTSIDE window — must never count

            /* Helper: printed_copies for our song in a result set, or null. */
            $printedFor = function (array $rows, string $songId): ?int {
                foreach ($rows as $r) {
                    if ((string)($r['song_id'] ?? '') === $songId) {
                        return (int)$r['printed_copies'];
                    }
                }
                return null;
            };

            /* ---- B1: THE isolation property -------------------------------- */
            $adminOrgsA = userIsOrgAdminOf($uA);
            cros($adminOrgsA === [$orgA], 'B1.0 fixture sanity: userIsOrgAdminOf(uA) === [orgA]');
            $rowsA = ccliReportOrgRows($db, $adminOrgsA, $from, $to, false);
            cros(
                count($rowsA) === 1 && $printedFor($rowsA, $songId) === 3,
                "B1.1 org A's admin sees EXACTLY its own 3 printed copies — B's 5, the NULL-org 2 and the out-of-window row are all absent "
                    . '(got ' . count($rowsA) . ' row(s), printed=' . var_export($printedFor($rowsA, $songId), true) . ') — DELETE the OrgId IN clause to see this go red'
            );
            $rowsB = ccliReportOrgRows($db, userIsOrgAdminOf($uB), $from, $to, false);
            cros(
                count($rowsB) === 1 && $printedFor($rowsB, $songId) === 5,
                'B1.2 symmetrically, org B\'s admin sees EXACTLY its own 5 (got printed=' . var_export($printedFor($rowsB, $songId), true) . ')'
            );

            /* ---- B2: non-admins do not qualify; empty scope refuses -------- */
            cros(userIsOrgAdminOf($uM) === [], 'B2.1 a plain member of org A does NOT qualify (userIsOrgAdminOf(uM) === [])');
            cros(userIsOrgAdminOf($uN) === [], 'B2.2 a user with no memberships does NOT qualify (userIsOrgAdminOf(uN) === [])');
            cros(ccliReportOrgRows($db, [], $from, $to, false) === [],
                'B2.3 ccliReportOrgRows() on an empty scope returns [] and issues NO query (the structural refusal) — make it fall through to see this go red');

            /* ---- B3: the scope resolver's deny / default / select ---------- */
            $forged = ccliReportResolveOrgScope((string)$orgB, [$orgA]);
            cros($forged['denied'] === true && $forged['orgIds'] === [],
                'B3.1 a forged ?org=B against a member-of-A-only user is DENIED (never a silent fallback) — make it fall back to $allowedOrgIds[0] to see this go red');
            $defaulted = ccliReportResolveOrgScope(null, [$orgA, $orgB]);
            cros($defaulted['denied'] === false && $defaulted['orgIds'] === [$orgA],
                'B3.2 an absent ?org= defaults to the caller\'s FIRST org (one org at a time)');
            $selected = ccliReportResolveOrgScope((string)$orgB, [$orgA, $orgB]);
            cros($selected['denied'] === false && $selected['orgIds'] === [$orgB],
                'B3.3 a ?org=B the caller DOES administer resolves to exactly [B]');

            /* ---- B4: system-query parity (the narrowing selector) ---------- */
            $sysA = ccliReportSystemRows($db, $from, $to, false, $orgA, false);
            cros($printedFor($sysA, $songId) === 3,
                'B4.1 ccliReportSystemRows(orgFilter=A) printed=3 (got ' . var_export($printedFor($sysA, $songId), true) . ')');
            $sysU = ccliReportSystemRows($db, $from, $to, false, null, true);
            cros($printedFor($sysU, $songId) === 2,
                'B4.2 ccliReportSystemRows(unattributed) printed=2 (the OrgId IS NULL slice)');
            $sysAll = ccliReportSystemRows($db, $from, $to, false, null, false);
            cros($printedFor($sysAll, $songId) === 10,
                'B4.3 ccliReportSystemRows(unfiltered) printed=10 (3+5+2, out-of-window row still excluded)');

            /* ---- B5: W1 — org licence wins over the personal one ----------- */
            $lic = printUsageResolveCcliLicence($uA);
            cros(
                is_array($lic) && (string)($lic['source'] ?? '') === 'org' && (int)($lic['source_id'] ?? 0) === $orgA,
                'B5.1 printUsageResolveCcliLicence(uA) resolves the ORG licence over uA\'s personal CCLI number '
                    . '(source=' . var_export($lic['source'] ?? null, true) . ', source_id=' . var_export($lic['source_id'] ?? null, true) . ') — revert W1 to see this go red'
            );
            $wrote = printUsageLog($uA, $songId, 1, []);
            cros($wrote === true, 'B5.2 printUsageLog(uA, …) writes a row (uA holds a qualifying CCLI licence)');
            $back = $db->prepare("SELECT OrgId FROM tblSongUsageEvents WHERE UserId = ? AND SongId = ? ORDER BY Id DESC LIMIT 1");
            $back->bind_param('is', $uA, $songId);
            $back->execute();
            $backRow = $back->get_result()->fetch_assoc();
            $back->close();
            cros(
                $backRow !== null && (int)($backRow['OrgId'] ?? 0) === $orgA,
                'B5.3 …and that row carries OrgId = A (W1 — the print is attributed to the org, not logged as Unattributed) '
                    . '(got OrgId=' . var_export($backRow['OrgId'] ?? null, true) . ')'
            );

            $behaviouralRan = true;
        } finally {
            $db->rollback();
        }
    }
}

if ($failures > 0) {
    fwrite(STDERR, "\n{$passed} passed, {$failures} failed\n");
    exit(1);
}
echo "\n{$passed} passed, 0 failed";
echo $behaviouralRan
    ? " (Part B isolation matrix ran against a live DB).\n"
    : " (Part B SKIPPED: no reachable database with a dbname, or a required table is absent — Part A still ran).\n";
exit(0);
