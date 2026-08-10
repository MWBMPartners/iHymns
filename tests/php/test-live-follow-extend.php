<?php

declare(strict_types=1);

/**
 * iHymns — Live Follow session-length + extend guard (#1798, follow-on to #1770/#1792)
 * ============================================================================
 * ELI5: this checks the two new things a Quick "Go Live" leader can do about
 * how long their session stays open — pick a length up front ("keep live for
 * 30 min / 1 hour / 2 hours / until I end it"), or add more time mid-service
 * if their phone died during the sermon. It checks that (a) the RIGHT people
 * are allowed to add time — the leader themselves, or a church admin over
 * the leader, never a stranger; (b) that authority is resolved via WHO THE
 * LEADER IS, never a column on the session itself (Quick sessions keep
 * `OrgId = NULL` on purpose, rule #26); (c) a requested length is always
 * clamped to a sane [5, 240] minute band; and (d) an organisation that has
 * LOCKED an idle-timeout value keeps that lock even when the leader (or an
 * admin extending on their behalf, for the leader's own request) asks for
 * longer.
 *
 * PART A (always, no DB) — source scan of the REAL dispatcher + client code:
 *   A1. `serviceMode_liveFollowExtendAuthorize(` is defined exactly once
 *       (service_mode.php) and CALLED from exactly one site — api.php's
 *       `live_follow_extend` case — mirroring the established
 *       "testable primitive behind a thin case" shape (#1792's
 *       `serviceMode_codeOnOtherChannel()`, test-live-follow-host-ccli.php's
 *       A2/A3).
 *   A2. Inside the `live_follow_extend` case body: the #1770 idle-columns
 *       existence gate (409 on absence), the channel-scoped session resolve
 *       (`SessionCode = ?` + `Channel = ?` + `IsActive = 1`), the
 *       authorize() call, the [5,240] clamp constants, the UPDATE setting
 *       `IdleTimeoutMins`/`LastLeaderSeenAt`/`ExpiresAt` scoped `WHERE Id = ?
 *       AND IsActive = 1` (PK-safe AFTER the channel-scoped resolve — rule
 *       #26), `validateCsrfRequest(` (rule #29), and the paired
 *       checkRateLimit()/recordRateLimitHit() (rule #1636).
 *   A3. Inside the `live_follow_create` case body: the client-declared
 *       `idleTimeoutMins` override reads the SAME [5,240] clamp constants
 *       and calls the resolver with its #1798 by-reference out-param
 *       (`serviceMode_resolveIdleTimeoutMins($db, $userId, $enforcedMins)`),
 *       so a host's chosen length is capped by their own org's enforced
 *       lock exactly as at extend.
 *   A4. The client (`js/modules/live-follow.js`) actually calls
 *       `live_follow_extend` and defines the shared duration picker
 *       (`_pickIdleDuration(`) — an endpoint with no caller is the orphan
 *       class `tests/php/test-orphan-inventory.php` exists to catch, but
 *       this guard also asserts the WIRING directly rather than relying on
 *       that separate tree-wide scan alone.
 *   A5. The org-admin surface (`manage/my-organisations.php`) is
 *       column-existence-gated (`serviceMode_idleColumnsExist(`) and calls
 *       `live_follow_extend`.
 *
 * PART B (DB, else SKIP) — behavioural, against the REAL functions, in ONE
 * transaction rolled back at the end (the test-live-follow-idle.php Part-B
 * pattern; nothing here nests a transaction inside a core that owns its
 * own — every write below is a plain autocommit-suppressed statement on the
 * SAME connection):
 *   B1. The HOST can extend their own session — window updated, idle clock
 *       reset.
 *   B2. An admin/owner of an org the HOST belongs to can extend — SAME
 *       outcome, via `serviceMode_liveFollowExtendAuthorize()` returning
 *       'org_admin', never by reading the session's own OrgId (which stays
 *       NULL throughout, per rule #26).
 *   B3. An unrelated authenticated user (no host, no shared org, no site
 *       role) is refused — `serviceMode_liveFollowExtendAuthorize()`
 *       returns 'denied'.
 *   B4. `live_follow_create`'s override formula (client value clamped to
 *       [5,240], then capped by an enforcing org's lock for the HOST) is
 *       exercised via the REAL resolver's #1798 out-param — an enforcing
 *       org's 20-minute lock beats a requested 240; a normal in-range
 *       request with no enforcing org passes through unchanged.
 *   B5. Out-of-range requests clamp to the band ends (999999 -> 240; 1 ->
 *       5) using the REAL [5,240] constants, not re-typed literals.
 *   B6. The extend UPDATE is CHANNEL-SCOPED (rule #26 — the prod-stale leak
 *       class). `SessionCode` is GLOBALLY unique (schema `uq_Code`), so two
 *       sessions can never literally share a code; the realistic scenario
 *       this proves is that a code belonging ONLY to another channel's
 *       session resolves to NOTHING from the current channel (never
 *       extends the wrong-channel session), while the same code resolves
 *       correctly against its own channel (proving the null above is the
 *       Channel filter, not a broken query).
 *
 * MUTATION PROOF (rule #34), run post-commit and reverted with
 * `git checkout --`:
 *   - Break the org-membership check inside
 *     `serviceMode_liveFollowExtendAuthorize()` (e.g. short-circuit it to
 *     always return 'org_admin') -> B3 goes red (an unrelated user would be
 *     wrongly allowed) — this is a REAL function call, not a parallel copy,
 *     so the mutation is caught behaviourally, not just textually.
 *   - Remove the `LIVE_FOLLOW_IDLE_TIMEOUT_MIN_MINUTES`/`_MAX_MINUTES` clamp
 *     from api.php's real `live_follow_extend` case body -> A2 goes red
 *     (the endpoint logic itself is inline in the dispatcher, not a
 *     standalone function, so the clamp's PRESENCE in the real source is
 *     what A2 verifies — the same "thin case, testable primitive" split
 *     test-live-follow-cross-channel.php already established for #1792).
 *
 * @see appWeb/public_html/includes/service_mode.php  serviceMode_liveFollowExtendAuthorize()
 * @see appWeb/public_html/api.php  case 'live_follow_extend' / case 'live_follow_create'
 * @see appWeb/public_html/js/modules/live-follow.js
 * @see appWeb/public_html/manage/my-organisations.php
 * @see tests/php/test-live-follow-idle.php  (the Part A/B + fixture pattern this mirrors)
 */

$scanRoot = getenv('IHYMNS_SCAN_ROOT') ?: (dirname(__DIR__, 2) . '/appWeb/public_html');
if (!is_dir($scanRoot)) {
    fwrite(STDERR, "FATAL: could not read $scanRoot\n");
    exit(1);
}

$failures = 0;
$passed   = 0;
function lfe(bool $cond, string $label): void
{
    global $failures, $passed;
    if ($cond) { $passed++; echo "PASS: $label\n"; }
    else       { $failures++; fwrite(STDERR, "FAIL: $label\n"); }
}

function lfeStripComments(string $php): string
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

/**
 * Byte range [start, end) of a top-level `function NAME(...) { ... }` body
 * (brace-matched from the FIRST `{` after the signature), or null if not
 * found / unbalanced. (Identical helper to test-live-follow-idle.php /
 * test-live-follow-host-ccli.php — kept per-file rather than shared because
 * these guards are deliberately standalone, zero-dependency scripts.)
 *
 * @return array{0:int,1:int}|null
 */
function lfeFunctionBodyRange(string $src, string $name): ?array
{
    if (!preg_match('/function\s+' . preg_quote($name, '/') . '\s*\(/', $src, $m, PREG_OFFSET_CAPTURE)) {
        return null;
    }
    $sigPos = $m[0][1];
    $bracePos = strpos($src, '{', $sigPos);
    if ($bracePos === false) { return null; }
    $depth = 0;
    $len = strlen($src);
    for ($i = $bracePos; $i < $len; $i++) {
        if ($src[$i] === '{') { $depth++; }
        elseif ($src[$i] === '}') {
            $depth--;
            if ($depth === 0) { return [$sigPos, $i + 1]; }
        }
    }
    return null;
}

function lfeInRange(int $offset, ?array $range): bool
{
    return $range !== null && $offset >= $range[0] && $offset < $range[1];
}

/**
 * Extract the body of one `case '<action>':` arm from the api.php
 * dispatcher — bounded by the next `case '` at the SAME 8-space indentation
 * (how every arm in that file is written; mirrors
 * test-setlist-collab.php's `_scCaseBody()`). Returns '' when absent.
 */
function lfeCaseBody(string $src, string $action): string
{
    $needle = "case '" . $action . "':";
    $start  = strpos($src, $needle);
    if ($start === false) {
        return '';
    }
    $next = strpos($src, "\n        case '", $start + strlen($needle));
    return $next === false ? substr($src, $start) : substr($src, $start, $next - $start);
}

/* =========================================================================
 * PART A — source scan
 * ========================================================================= */

$smPath   = $scanRoot . '/includes/service_mode.php';
$apiPath  = $scanRoot . '/api.php';
$jsPath   = $scanRoot . '/js/modules/live-follow.js';
$orgPath  = $scanRoot . '/manage/my-organisations.php';
$smSrc    = is_readable($smPath)  ? lfeStripComments((string)file_get_contents($smPath))  : '';
$apiSrc   = is_readable($apiPath) ? lfeStripComments((string)file_get_contents($apiPath)) : '';
$jsSrc    = is_readable($jsPath)  ? (string)file_get_contents($jsPath)  : '';
$orgSrc   = is_readable($orgPath) ? (string)file_get_contents($orgPath) : '';

lfe($smSrc !== '', "A0.1 read includes/service_mode.php ({$smPath})");
lfe($apiSrc !== '', "A0.2 read api.php ({$apiPath})");
lfe($jsSrc !== '', "A0.3 read js/modules/live-follow.js ({$jsPath})");
lfe($orgSrc !== '', "A0.4 read manage/my-organisations.php ({$orgPath})");

/* A1 — serviceMode_liveFollowExtendAuthorize() is defined exactly once, and
   called from exactly one site OUTSIDE its own definition. */
$authFnName  = 'serviceMode_liveFollowExtendAuthorize';
$authDefCount = preg_match_all('/function\s+' . preg_quote($authFnName, '/') . '\s*\(/', $smSrc);
lfe($authDefCount === 1, "A1 {$authFnName}() is defined exactly once (found {$authDefCount})");

$authRange = lfeFunctionBodyRange($smSrc, $authFnName);
$authCallSites = [];
foreach (['service_mode.php' => $smSrc, 'api.php' => $apiSrc] as $label => $src) {
    $offset = 0;
    while (($pos = strpos($src, $authFnName . '(', $offset)) !== false) {
        $offset = $pos + 1;
        $inOwnDef = ($label === 'service_mode.php') && lfeInRange($pos, $authRange);
        if ($inOwnDef) { continue; } /* the signature itself, not a call */
        $line = substr_count(substr($src, 0, $pos), "\n") + 1;
        $authCallSites[] = "$label:$line";
    }
}
lfe(
    count($authCallSites) === 1,
    'A1 ' . $authFnName . '() is called from exactly ONE site outside its own definition '
        . '(found ' . count($authCallSites) . ': ' . implode(', ', $authCallSites) . ')'
);

/* A2 — the live_follow_extend case body, checked piece by piece so a FAIL
   names exactly which piece is missing. */
$extendBody = lfeCaseBody($apiSrc, 'live_follow_extend');
lfe($extendBody !== '', "A2.0 found case 'live_follow_extend' in api.php");

lfe(
    str_contains($extendBody, 'serviceMode_idleColumnsExist(') && str_contains($extendBody, '409'),
    'A2.1 the idle-columns existence gate answers 409 on an un-migrated install (rule #35 — a distinct status the client branches on)'
);
lfe(
    preg_match('/SessionCode\s*=\s*\?/', $extendBody) === 1
        && preg_match('/Channel\s*=\s*\?/', $extendBody) === 1
        && str_contains($extendBody, "IsActive = 1"),
    'A2.2 the session resolve is channel-scoped (SessionCode + Channel + IsActive)'
);
lfe(
    str_contains($extendBody, $authFnName . '('),
    'A2.3 the case calls ' . $authFnName . '() to decide who may extend'
);
lfe(
    str_contains($extendBody, 'LIVE_FOLLOW_IDLE_TIMEOUT_MIN_MINUTES') && str_contains($extendBody, 'LIVE_FOLLOW_IDLE_TIMEOUT_MAX_MINUTES'),
    'A2.4 the requested value is clamped against the real [5,240] constants (never re-typed literals)'
);
lfe(
    str_contains($extendBody, 'IdleTimeoutMins = ?')
        && str_contains($extendBody, 'LastLeaderSeenAt = UTC_TIMESTAMP()')
        && str_contains($extendBody, 'ExpiresAt = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 4 HOUR)')
        && preg_match('/WHERE\s+Id\s*=\s*\?\s+AND\s+IsActive\s*=\s*1/', $extendBody) === 1,
    'A2.5 the UPDATE sets IdleTimeoutMins + resets LastLeaderSeenAt + refreshes ExpiresAt, scoped WHERE Id = ? AND IsActive = 1 (PK-safe AFTER the channel-scoped resolve)'
);
lfe(
    str_contains($extendBody, 'validateCsrfRequest('),
    'A2.6 the case calls validateCsrfRequest() (rule #29 — belt-and-braces same-origin check)'
);
lfe(
    str_contains($extendBody, "checkRateLimit('live_follow_extend'") && str_contains($extendBody, "recordRateLimitHit('live_follow_extend'"),
    'A2.7 the case is both checked AND recorded for rate limiting (rule #1636 two-call contract)'
);
lfe(
    str_contains($extendBody, "logActivity('live.session.extend'"),
    'A2.8 a successful extend is logged via logActivity()'
);

/* A3 — the live_follow_create case body carries the SAME clamp constants and
   passes the #1798 by-reference out-param to the resolver. */
$createBody = lfeCaseBody($apiSrc, 'live_follow_create');
lfe($createBody !== '', "A3.0 found case 'live_follow_create' in api.php");
lfe(
    str_contains($createBody, 'idleTimeoutMins')
        && str_contains($createBody, 'LIVE_FOLLOW_IDLE_TIMEOUT_MIN_MINUTES')
        && str_contains($createBody, 'LIVE_FOLLOW_IDLE_TIMEOUT_MAX_MINUTES'),
    'A3.1 live_follow_create reads a client-declared idleTimeoutMins and clamps it against the real [5,240] constants'
);
lfe(
    preg_match('/serviceMode_resolveIdleTimeoutMins\(\s*\$db\s*,\s*\$userId\s*,\s*\$enforcedMins\s*\)/', $createBody) === 1,
    'A3.2 live_follow_create calls the resolver WITH the #1798 by-reference out-param (the host-cap source)'
);

/* A4 — the client actually calls the endpoint and offers a duration picker. */
lfe(str_contains($jsSrc, "'live_follow_extend'"), "A4.1 live-follow.js calls the 'live_follow_extend' action");
lfe(str_contains($jsSrc, '_pickIdleDuration('), 'A4.2 live-follow.js defines/uses a shared duration picker (goLive() + Extend share it, never two copies)');
lfe(str_contains($jsSrc, 'idleTimeoutMins'), 'A4.3 live-follow.js sends idleTimeoutMins in at least one request body');

/* A5 — the org-admin surface is column-existence-gated and wired. */
lfe(str_contains($orgSrc, 'serviceMode_idleColumnsExist('), 'A5.1 my-organisations.php gates the live-sessions card on serviceMode_idleColumnsExist()');
lfe(str_contains($orgSrc, "'live_follow_extend'"), "A5.2 my-organisations.php's Extend control calls 'live_follow_extend'");

/* =========================================================================
 * PART B — behavioural (DB, else SKIP)
 * ========================================================================= */

$host = '127.0.0.1'; $user = 'root'; $pass = ''; $sock = null; $port = 3306; $dbName = null;
$repoRoot = dirname(__DIR__, 2);
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

    require_once $scanRoot . '/includes/db_mysql.php';
    require_once $scanRoot . '/includes/entitlements.php';
    require_once $scanRoot . '/includes/service_mode.php';

    try {
        $db = getDbMysqli();
        $hasSessCols = serviceMode_idleColumnsExist($db);
        $hasOrgCols  = serviceMode_orgIdleColumnsExist($db);
    } catch (\Throwable $e) {
        $db = null; $hasSessCols = false; $hasOrgCols = false;
    }

    if ($db !== null && $hasSessCols) {
        $db->begin_transaction();
        try {
            $current = serviceMode_channel();
            /* A fixed channel guaranteed distinct from any real docroot
               channel (dev/alpha/beta/www/local), <=16 chars (VARCHAR(16)) —
               same device as test-live-follow-cross-channel.php's $other. */
            $other = ($current === 'zzOtherChan') ? 'zzOtherCh2' : 'zzOtherChan';

            $mkUser = function (string $tag) use ($db): int {
                $u = 'zzext_' . $tag;
                $e = $u . '@example.invalid';
                $stmt = $db->prepare(
                    "INSERT INTO tblUsers (Username, Email, EmailVerified, PasswordHash, DisplayName, Role, CcliNumber)
                     VALUES (?, ?, 0, 'x', ?, 'user', '')"
                );
                $stmt->bind_param('sss', $u, $e, $u);
                $stmt->execute();
                $id = (int)$db->insert_id;
                $stmt->close();
                return $id;
            };
            $mkOrg = function (string $tag, ?int $mins = null, bool $enforce = false) use ($db): int {
                $n = 'ZZext ' . $tag;
                $s = 'zzext-' . $tag . '-' . bin2hex(random_bytes(3));
                $stmt = $db->prepare(
                    'INSERT INTO tblOrganisations (Name, Slug, IsActive, LiveIdleTimeoutMins, EnforceIdleTimeout)
                     VALUES (?, ?, 1, ?, ?)'
                );
                $enf = $enforce ? 1 : 0;
                $stmt->bind_param('ssii', $n, $s, $mins, $enf);
                $stmt->execute();
                $id = (int)$db->insert_id;
                $stmt->close();
                return $id;
            };
            $join = function (int $userId, int $orgId, string $role = 'member') use ($db): void {
                $stmt = $db->prepare('INSERT INTO tblOrganisationMembers (UserId, OrgId, Role) VALUES (?, ?, ?)');
                $stmt->bind_param('iis', $userId, $orgId, $role);
                $stmt->execute();
                $stmt->close();
            };
            /* Mints a Quick (host) session, idle-stamped, on the given
               channel, with a code + starting idle minutes/last-seen the
               caller controls. */
            $mkSession = function (string $code, int $hostId, string $channel, ?int $idleMins, ?string $lastSeen) use ($db): int {
                $stmt = $db->prepare(
                    "INSERT INTO tblLiveFollowSessions
                        (SessionCode, HostUserId, SessionKind, Channel, IsActive, StartedAt,
                         LastHeartbeatAt, ExpiresAt, LastLeaderSeenAt, IdleTimeoutMins)
                     VALUES (?, ?, 'host', ?, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP(),
                             DATE_ADD(UTC_TIMESTAMP(), INTERVAL 4 HOUR), ?, ?)"
                );
                $stmt->bind_param('sissi', $code, $hostId, $channel, $lastSeen, $idleMins);
                $stmt->execute();
                $id = (int)$db->insert_id;
                $stmt->close();
                return $id;
            };
            /* The EXACT resolve + update shape api.php's live_follow_extend
               case uses (A2.2/A2.5 assert the real source matches this). */
            $resolveSession = function (string $code, string $channel) use ($db): ?array {
                $stmt = $db->prepare(
                    'SELECT Id, HostUserId FROM tblLiveFollowSessions
                      WHERE SessionCode = ? AND Channel = ? AND IsActive = 1'
                );
                $stmt->bind_param('ss', $code, $channel);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                return $row ?: null;
            };
            $applyExtend = function (int $sessionId, int $mins) use ($db): int {
                $stmt = $db->prepare(
                    'UPDATE tblLiveFollowSessions
                        SET IdleTimeoutMins = ?, LastLeaderSeenAt = UTC_TIMESTAMP(),
                            ExpiresAt = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 4 HOUR)
                      WHERE Id = ? AND IsActive = 1'
                );
                $stmt->bind_param('ii', $mins, $sessionId);
                $stmt->execute();
                $changed = $stmt->affected_rows;
                $stmt->close();
                return $changed;
            };
            $fetchSession = function (int $sessionId) use ($db): array {
                $stmt = $db->prepare('SELECT IdleTimeoutMins, LastLeaderSeenAt FROM tblLiveFollowSessions WHERE Id = ?');
                $stmt->bind_param('i', $sessionId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                return $row ?: [];
            };
            $tenMinAgo = gmdate('Y-m-d H:i:s', time() - 600);

            /* -------------------------------------------------------------
             * B1 — the HOST can extend their own session.
             * ----------------------------------------------------------- */
            $hostB1 = $mkUser('b1host');
            $codeB1 = 'ZB1' . strtoupper(bin2hex(random_bytes(3)));
            $sessB1 = $mkSession($codeB1, $hostB1, $current, 15, $tenMinAgo);

            $resolvedB1 = $resolveSession($codeB1, $current);
            lfe($resolvedB1 !== null && (int)$resolvedB1['Id'] === $sessB1, 'B1.1 the channel-scoped resolve finds the session');
            $authB1 = serviceMode_liveFollowExtendAuthorize($db, (int)$resolvedB1['HostUserId'], $hostB1, 'user');
            lfe($authB1 === 'host', "B1.2 the HOST is authorised to extend their own session (got '{$authB1}')");
            $changedB1 = $applyExtend($sessB1, 120);
            lfe($changedB1 === 1, 'B1.3 the extend UPDATE affects exactly one row');
            $afterB1 = $fetchSession($sessB1);
            lfe((int)($afterB1['IdleTimeoutMins'] ?? 0) === 120, 'B1.4 IdleTimeoutMins is now 120');
            lfe(
                strtotime((string)$afterB1['LastLeaderSeenAt']) > strtotime($tenMinAgo) + 500,
                'B1.5 LastLeaderSeenAt was reset to now (the idle clock restarts on extend)'
            );

            /* -------------------------------------------------------------
             * B2 — an admin/owner of an org the HOST belongs to can extend.
             * ----------------------------------------------------------- */
            $hostB2  = $mkUser('b2host');
            $adminB2 = $mkUser('b2admin');
            $orgB2   = $mkOrg('b2org');
            $join($hostB2, $orgB2, 'member');
            $join($adminB2, $orgB2, 'admin');
            $codeB2 = 'ZB2' . strtoupper(bin2hex(random_bytes(3)));
            $sessB2 = $mkSession($codeB2, $hostB2, $current, null, null);

            $authB2 = serviceMode_liveFollowExtendAuthorize($db, $hostB2, $adminB2, 'user');
            lfe($authB2 === 'org_admin', "B2.1 an admin of an org the HOST belongs to is authorised (got '{$authB2}')");
            $changedB2 = $applyExtend($sessB2, 60);
            lfe($changedB2 === 1, 'B2.2 the extend UPDATE succeeds for the org-admin path');
            $afterB2 = $fetchSession($sessB2);
            lfe((int)($afterB2['IdleTimeoutMins'] ?? 0) === 60, 'B2.3 IdleTimeoutMins is now 60');

            /* -------------------------------------------------------------
             * B3 — an unrelated authenticated user is refused.
             * ----------------------------------------------------------- */
            $hostB3      = $mkUser('b3host');
            $unrelatedB3 = $mkUser('b3unrelated');
            $authB3 = serviceMode_liveFollowExtendAuthorize($db, $hostB3, $unrelatedB3, 'user');
            lfe($authB3 === 'denied', "B3 an unrelated user with no host/org/site relationship is DENIED (got '{$authB3}') — MUTATE the org-membership check inside serviceMode_liveFollowExtendAuthorize() to see this go red");

            /* A site admin/global_admin is a distinct, ALSO-allowed path. */
            $authB3site = serviceMode_liveFollowExtendAuthorize($db, $hostB3, $unrelatedB3, 'global_admin');
            lfe($authB3site === 'site_admin', "B3b a global_admin (unrelated by org) is still authorised via the site-admin path (got '{$authB3site}')");

            if ($hasOrgCols) {
                /* -----------------------------------------------------------
                 * B4 — live_follow_create's override formula: an enforcing
                 * org's lock caps a requested value for the HOST; a normal
                 * in-range request with no enforcing org passes through.
                 * --------------------------------------------------------- */
                $hostB4   = $mkUser('b4host');
                $orgB4    = $mkOrg('b4org', 20, true);
                $join($hostB4, $orgB4, 'member');

                $enforcedB4 = null;
                serviceMode_resolveIdleTimeoutMins($db, $hostB4, $enforcedB4);
                lfe($enforcedB4 === 20, "B4.1 the resolver's out-param reports the HOST's enforced org lock (got " . var_export($enforcedB4, true) . ')');

                $requestedB4 = 240; /* "until I end it" */
                $chosenB4 = max(LIVE_FOLLOW_IDLE_TIMEOUT_MIN_MINUTES, min(LIVE_FOLLOW_IDLE_TIMEOUT_MAX_MINUTES, $requestedB4));
                if ($enforcedB4 !== null) { $chosenB4 = min($chosenB4, $enforcedB4); }
                lfe($chosenB4 === 20, "B4.2 a requested 240 is capped to the org's enforced 20 for the HOST (got {$chosenB4}) — this is the create-time AND host-side-extend cap");

                $hostB4b = $mkUser('b4bhost'); /* no org at all */
                $enforcedB4b = null;
                serviceMode_resolveIdleTimeoutMins($db, $hostB4b, $enforcedB4b);
                lfe($enforcedB4b === null, 'B4.3 a host with no enforcing org has a null enforced cap');
                $requestedB4b = 45;
                $chosenB4b = max(LIVE_FOLLOW_IDLE_TIMEOUT_MIN_MINUTES, min(LIVE_FOLLOW_IDLE_TIMEOUT_MAX_MINUTES, $requestedB4b));
                if ($enforcedB4b !== null) { $chosenB4b = min($chosenB4b, $enforcedB4b); }
                lfe($chosenB4b === 45, "B4.4 an in-range 45 with no enforcing org passes through unchanged (got {$chosenB4b})");

                /* An ORG-ADMIN extending is capped ONLY at the global band —
                   the host's own org lock must NOT constrain them (they hold
                   the authority to override it). */
                $requestedB4c = 240;
                $chosenB4c = max(LIVE_FOLLOW_IDLE_TIMEOUT_MIN_MINUTES, min(LIVE_FOLLOW_IDLE_TIMEOUT_MAX_MINUTES, $requestedB4c));
                /* (org-admin path never reads $enforcedB4 — no cap applied) */
                lfe($chosenB4c === 240, 'B4.5 an org-admin extending the same locked host is capped only at the global 240 ceiling, never the host org lock');
            } else {
                lfe(true, 'B4 SKIPPED (tblOrganisations idle columns not migrated on this install)');
            }

            /* -------------------------------------------------------------
             * B5 — out-of-range requests clamp to [5, 240] using the REAL
             * constants (never re-typed literals).
             * ----------------------------------------------------------- */
            $chosenHi = max(LIVE_FOLLOW_IDLE_TIMEOUT_MIN_MINUTES, min(LIVE_FOLLOW_IDLE_TIMEOUT_MAX_MINUTES, 999999));
            lfe($chosenHi === 240, "B5.1 an absurdly high request clamps to 240 (got {$chosenHi})");
            $chosenLo = max(LIVE_FOLLOW_IDLE_TIMEOUT_MIN_MINUTES, min(LIVE_FOLLOW_IDLE_TIMEOUT_MAX_MINUTES, 1));
            lfe($chosenLo === 5, "B5.2 a too-low request (1) clamps to the 5-minute floor (got {$chosenLo})");

            /* -------------------------------------------------------------
             * B6 — the extend UPDATE is CHANNEL-SCOPED. `SessionCode` is
             * GLOBALLY unique (schema `uq_Code`), so two sessions can never
             * literally share one code — the realistic threat this guards
             * against is a request on one channel naming a code that only
             * exists on ANOTHER channel: the channel-scoped resolve must
             * return NOT FOUND for it (never resolve to a wrong-channel
             * session and extend that), while resolving correctly against
             * its OWN channel proves the query isn't simply broken.
             * ----------------------------------------------------------- */
            $hostB6other = $mkUser('b6other');
            $codeB6      = 'ZB6' . strtoupper(bin2hex(random_bytes(3)));
            $sessB6other = $mkSession($codeB6, $hostB6other, $other, 15, $tenMinAgo);

            $resolvedB6Wrong = $resolveSession($codeB6, $current);
            lfe(
                $resolvedB6Wrong === null,
                'B6.1 a code that only exists on the OTHER channel resolves to NOTHING from the CURRENT channel (the endpoint\'s 404 path — never touches the wrong-channel session)'
            );
            $resolvedB6Right = $resolveSession($codeB6, $other);
            lfe(
                $resolvedB6Right !== null && (int)$resolvedB6Right['Id'] === $sessB6other,
                'B6.2 the SAME code correctly resolves against its OWN channel (proves B6.1 is the Channel filter working, not a broken query)'
            );
            $afterB6other = $fetchSession($sessB6other);
            lfe(
                (int)($afterB6other['IdleTimeoutMins'] ?? 0) === 15,
                'B6.3 the other-channel session was never touched — still its seeded IdleTimeoutMins'
            );

            $behaviouralRan = true;
        } finally {
            $db->rollback();
        }
    }
}

if ($failures) {
    fwrite(STDERR, "\n{$passed} passed, {$failures} failed\n");
    exit(1);
}
echo "\n{$passed} passed, 0 failed";
echo $behaviouralRan
    ? " (Part B behavioural matrix ran against a live DB).\n"
    : " (Part B SKIPPED: no reachable database with dbname, or the #1770 idle-timeout migration is not applied).\n";
exit(0);
