<?php

declare(strict_types=1);

/**
 * iHymns — Live Follow leader-idle guard (#1770 G2)
 * ============================================================================
 * ELI5: this checks four things about the "close a Go Live session after
 * nobody's touched it for a while" feature: (a) the maths for "has it gone
 * idle?" is written down in exactly the places it's ALLOWED to be, never a
 * third hand-typed copy; (b) every place that reads or writes session
 * liveness actually USES that maths; (c) each of the three settings that
 * feed the idle-timeout DECISION (app default, org override, user
 * preference) is read by exactly the ONE function that resolves them, so a
 * second reader can never drift out of step with it; (d) that resolver
 * actually picks the right answer for every combination the plan specifies.
 *
 * PART A (always, no DB) — source scan:
 *   A1. `TIMESTAMPDIFF(MINUTE, LastLeaderSeenAt, UTC_TIMESTAMP())`-shaped
 *       text appears ONLY inside `serviceMode_idleFreshSql()` (the shared
 *       "is fresh" fragment) and `serviceMode_pruneIdleQuickSessions()`
 *       (the ONE documented exception — a prune SELECT needs the logical
 *       NEGATION of "fresh", i.e. "is idle", which is De Morgan's dual of
 *       the fragment's OR-of-three-conditions shape and therefore cannot
 *       literally BE a call to it). Nowhere else, in either service_mode.php
 *       or api.php.
 *   A2. Every consumer — `live_follow_join` / `_poll` / `_update` /
 *       `_heartbeat`, the CCLI gate's host branch, AND the prune function —
 *       actually calls `serviceMode_idleFreshSql(` (the prune function is
 *       exempted here too, per A1, since its own inline negation IS its use
 *       of the idle predicate).
 *   A3. Each of the three precedence-layer identifiers
 *       (`LIVE_FOLLOW_IDLE_TIMEOUT_APP_SETTING_KEY`, the org columns
 *       `LiveIdleTimeoutMins`/`EnforceIdleTimeout`, and
 *       `LIVE_FOLLOW_IDLE_TIMEOUT_USER_SETTING_KEY`) is referenced, within
 *       service_mode.php + api.php (the resolution/enforcement path — the
 *       admin SETTINGS-SURFACE pages that read/write the current value to
 *       pre-fill a form are the plan's own documented exemption, "plus
 *       their settings-surface writers", and are out of this scan's scope),
 *       ONLY inside `serviceMode_resolveIdleTimeoutMins()` (or the
 *       identifier's own `const` declaration line) — PLUS, since #1969
 *       API-coverage batch 3 (O1), the `org_admin_settings_update` case
 *       body in api.php itself: that action IS a settings-surface WRITER
 *       (the native/API twin of manage/my-organisations.php's own
 *       idle_timeout_update handler, which the "plus their settings-surface
 *       writers" clause above already exempted for the web page) — it just
 *       happens to live inside one of the two files this scan otherwise
 *       treats as the resolution/enforcement path, so it gets its own
 *       named, range-scoped exemption rather than a blanket "ignore
 *       api.php" that would defeat the rest of this check.
 *
 * PART B (DB, else SKIP) — the resolver matrix, §5's own precedence formula
 * `enforced ?? (user ?? (orgDefault ?? appDefault))`, clamped [5,240]:
 *   B1. An ENFORCING org's value wins over a conflicting user preference.
 *   B2. A user preference wins over a non-enforcing org default.
 *   B3. A non-enforcing org default wins over the app default (no user pref).
 *   B4. Two ENFORCING orgs → the MINIMUM enforcing value wins (§10 S3).
 *   B5. Clamped to [5,240] at both ends when nothing else constrains it.
 * All fixture rows are inserted in ONE transaction, ROLLED BACK at the end —
 * nothing is left behind.
 *
 * MUTATION PROOF (rule #34): see the commit body for the exact mutations run
 * against each of A1-A3 and B1-B5 (temporarily breaking the resolver/guard
 * and confirming red, then restoring and confirming green).
 *
 * @see appWeb/public_html/includes/service_mode.php
 * @see .claude/live-follow-1770-plan.md §5, §9 G2
 */

$scanRoot = getenv('IHYMNS_SCAN_ROOT') ?: (dirname(__DIR__, 2) . '/appWeb/public_html');
if (!is_dir($scanRoot)) {
    fwrite(STDERR, "FATAL: could not read $scanRoot\n");
    exit(1);
}

$failures = 0;
$passed   = 0;
function lfi(bool $cond, string $label): void
{
    global $failures, $passed;
    if ($cond) { $passed++; echo "PASS: $label\n"; }
    else       { $failures++; fwrite(STDERR, "FAIL: $label\n"); }
}

function lfiStripComments(string $php): string
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
 * found / unbalanced.
 *
 * @return array{0:int,1:int}|null
 */
function lfiFunctionBodyRange(string $src, string $name): ?array
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

function lfiInRange(int $offset, ?array $range): bool
{
    return $range !== null && $offset >= $range[0] && $offset < $range[1];
}

/* =========================================================================
 * PART A — source scan
 * ========================================================================= */

$smPath  = $scanRoot . '/includes/service_mode.php';
$apiPath = $scanRoot . '/api.php';
$smSrc   = is_readable($smPath)  ? lfiStripComments((string)file_get_contents($smPath))  : '';
$apiSrc  = is_readable($apiPath) ? lfiStripComments((string)file_get_contents($apiPath)) : '';

lfi($smSrc !== '', "A0.1 read includes/service_mode.php ({$smPath})");
lfi($apiSrc !== '', "A0.2 read api.php ({$apiPath})");

$freshRange = lfiFunctionBodyRange($smSrc, 'serviceMode_idleFreshSql');
$pruneRange = lfiFunctionBodyRange($smSrc, 'serviceMode_pruneIdleQuickSessions');
$resolverRange = lfiFunctionBodyRange($smSrc, 'serviceMode_resolveIdleTimeoutMins');
lfi($freshRange !== null, 'A0.3 found serviceMode_idleFreshSql() function body');
lfi($pruneRange !== null, 'A0.4 found serviceMode_pruneIdleQuickSessions() function body');
lfi($resolverRange !== null, 'A0.5 found serviceMode_resolveIdleTimeoutMins() function body');

/* A1 — the TIMESTAMPDIFF(...LastLeaderSeenAt...) shape, wherever it occurs. */
$tdRe = '/TIMESTAMPDIFF\(\s*MINUTE\s*,\s*(?:[a-zA-Z_][a-zA-Z0-9_]*\.)?LastLeaderSeenAt\s*,\s*UTC_TIMESTAMP\(\)\s*\)/i';
$stray = [];
foreach (['service_mode.php' => $smSrc, 'api.php' => $apiSrc] as $label => $src) {
    if (!preg_match_all($tdRe, $src, $mAll, PREG_OFFSET_CAPTURE)) { continue; }
    foreach ($mAll[0] as $hit) {
        $pos = $hit[1];
        $ok = ($label === 'service_mode.php') && (lfiInRange($pos, $freshRange) || lfiInRange($pos, $pruneRange));
        if (!$ok) {
            $line = substr_count(substr($src, 0, $pos), "\n") + 1;
            $stray[] = "$label:$line";
        }
    }
}
lfi(
    $stray === [],
    'A1 the idle TIMESTAMPDIFF(...) shape appears ONLY inside serviceMode_idleFreshSql() or '
        . 'serviceMode_pruneIdleQuickSessions() (its documented De Morgan negation)'
        . ($stray === [] ? '' : ' — found elsewhere at: ' . implode(', ', $stray))
);

/* A2 — every consumer applies the idle predicate, but via the RIGHT helper.
   #1792 — join/poll now gate on serviceMode_liveFollowAliveSql() (the "session
   still alive?" wrapper: idle predicate on a migrated install, 180s heartbeat
   fallback un-migrated) rather than the raw 180s window; update/heartbeat keep
   calling serviceMode_idleFreshSql() directly (the host is demonstrably present
   when it drives/beats, so they never had a heartbeat gate). The wrapper itself
   is asserted to apply the idle predicate just below, so the idle rule is still
   single-sourced through the indirection (rule #26). */
$consumers = [
    'live_follow_join'      => 'serviceMode_liveFollowAliveSql(',
    'live_follow_poll'      => 'serviceMode_liveFollowAliveSql(',
    'live_follow_update'    => 'serviceMode_idleFreshSql(',
    'live_follow_heartbeat' => 'serviceMode_idleFreshSql(',
];
foreach ($consumers as $action => $expectedHelper) {
    if (!preg_match('/case\s+\'' . preg_quote($action, '/') . '\'\s*:/', $apiSrc, $m, PREG_OFFSET_CAPTURE)) {
        lfi(false, "A2 found a case '$action': block in api.php to inspect");
        continue;
    }
    $start = $m[0][1];
    /* Up to the NEXT top-level `case '...':` (or EOF) — good enough here
       since these case blocks are flat (no nested switch). */
    $nextPos = null;
    if (preg_match('/case\s+\'[a-z0-9_]+\'\s*:/', $apiSrc, $mNext, PREG_OFFSET_CAPTURE, $start + 10)) {
        $nextPos = $mNext[0][1];
    }
    $block = substr($apiSrc, $start, ($nextPos ?? strlen($apiSrc)) - $start);
    lfi(
        strpos($block, $expectedHelper) !== false,
        "A2 case '$action' applies the idle predicate via {$expectedHelper}) (rule #26 — the idle predicate is never re-typed)"
    );
}
/* #1792 — the join/poll wrapper MUST itself route through serviceMode_idleFreshSql(),
   so relaxing the heartbeat gate did not drop the idle predicate (rule #26 — one
   idle predicate, applied through the wrapper). */
$aliveRange = lfiFunctionBodyRange($smSrc, 'serviceMode_liveFollowAliveSql');
lfi(
    $aliveRange !== null
        && strpos(substr($smSrc, $aliveRange[0], $aliveRange[1] - $aliveRange[0]), 'serviceMode_idleFreshSql(') !== false,
    'A2 serviceMode_liveFollowAliveSql() applies the idle predicate via serviceMode_idleFreshSql() (#1792 indirection preserves rule #26)'
);
/* The gate's host branch (serviceMode_presenceCcliNumber) + the prune
   function must each reference the helper/negation too. */
lfi(
    $resolverRange !== null /* placeholder true-guard so ccliRange below always evaluates */
        && ($ccliRange = lfiFunctionBodyRange($smSrc, 'serviceMode_presenceCcliNumber')) !== null
        && strpos(substr($smSrc, $ccliRange[0], $ccliRange[1] - $ccliRange[0]), 'serviceMode_idleFreshSql(') !== false,
    'A2 serviceMode_presenceCcliNumber() (the CCLI gate) calls serviceMode_idleFreshSql() on its host branch'
);
lfi(
    $pruneRange !== null && preg_match($tdRe, substr($smSrc, $pruneRange[0], $pruneRange[1] - $pruneRange[0])) === 1,
    'A2 serviceMode_pruneIdleQuickSessions() applies the idle predicate (its own negated form, per A1)'
);

/* A3 — each precedence-layer identifier is read ONLY by the resolver
   (or on its own `const` declaration line) within service_mode.php + api.php. */
$layerIdentifiers = [
    'LIVE_FOLLOW_IDLE_TIMEOUT_APP_SETTING_KEY',
    'LiveIdleTimeoutMins',
    'EnforceIdleTimeout',
    'LIVE_FOLLOW_IDLE_TIMEOUT_USER_SETTING_KEY',
];
/* serviceMode_orgIdleColumnsExist() checks COLUMN EXISTENCE (an
   INFORMATION_SCHEMA probe naming the two org columns as literal strings)
   — it never reads the VALUE, so it is not a second reader of the
   precedence layer in the sense this guard cares about, only a schema
   gate every column-existence-gated helper in this codebase needs. */
$orgProbeRange = lfiFunctionBodyRange($smSrc, 'serviceMode_orgIdleColumnsExist');

/* #1969 API-coverage batch 3 (O1) — the org_admin_settings_update case in
   api.php IS a settings-surface WRITER (see the doc-block above): it
   writes the org's own LiveIdleTimeoutMins/EnforceIdleTimeout, exactly as
   manage/my-organisations.php's idle_timeout_update handler does (that
   page is entirely outside this scan's scope; this action happens to live
   inside api.php, one of the two files the scan otherwise polices, so it
   needs its own named, range-scoped carve-out).
   BRACE-MATCHED (like lfiFunctionBodyRange()), NOT the "up to the next
   top-level case label" shape A2 uses above — this action's body contains
   its OWN nested `switch ($audienceChoice) { case 'require': … }` for the
   three-way edit-audience mapping, and a naive "next `case '...':`" scan
   would stop at that NESTED case label, truncating the range and wrongly
   flagging everything after it (this is exactly how the first version of
   this carve-out reported false positives at the SELECT-echo lines below
   the nested switch — caught immediately by running this guard, not
   inferred). This case's body is written `case '…': { … }` (the SAME
   explicit-brace shape as every other case in this switch), so brace-
   depth matching from the FIRST `{` after the label to its balanced `}`
   is exact regardless of what nests inside. */
$settingsUpdateRange = null;
if (preg_match('/case\s+\'org_admin_settings_update\'\s*:\s*\{/', $apiSrc, $mSu, PREG_OFFSET_CAPTURE)) {
    $suBracePos = strpos($apiSrc, '{', $mSu[0][1]);
    if ($suBracePos !== false) {
        $depth = 0;
        $len = strlen($apiSrc);
        for ($i = $suBracePos; $i < $len; $i++) {
            if ($apiSrc[$i] === '{') { $depth++; }
            elseif ($apiSrc[$i] === '}') {
                $depth--;
                if ($depth === 0) { $settingsUpdateRange = [$suBracePos, $i + 1]; break; }
            }
        }
    }
}
lfi($settingsUpdateRange !== null, "A3.0 found case 'org_admin_settings_update': block in api.php to exempt");

foreach ($layerIdentifiers as $ident) {
    $strayReads = [];
    foreach (['service_mode.php' => $smSrc, 'api.php' => $apiSrc] as $label => $src) {
        $offset = 0;
        while (($pos = strpos($src, $ident, $offset)) !== false) {
            $offset = $pos + 1;
            $lineStart = strrpos(substr($src, 0, $pos), "\n");
            $lineText = substr($src, ($lineStart === false ? 0 : $lineStart + 1), 200);
            $isDeclaration = (bool)preg_match('/^\s*const\s+' . preg_quote($ident, '/') . '\b/', $lineText);
            $inResolver = ($label === 'service_mode.php') && lfiInRange($pos, $resolverRange);
            $inOrgProbe = ($label === 'service_mode.php') && lfiInRange($pos, $orgProbeRange);
            $inSettingsUpdate = ($label === 'api.php') && lfiInRange($pos, $settingsUpdateRange);
            if (!$isDeclaration && !$inResolver && !$inOrgProbe && !$inSettingsUpdate) {
                $line = substr_count(substr($src, 0, $pos), "\n") + 1;
                $strayReads[] = "$label:$line";
            }
        }
    }
    lfi(
        $strayReads === [],
        "A3 '$ident' is referenced only by its const declaration, serviceMode_resolveIdleTimeoutMins(), "
            . "and the org_admin_settings_update settings-surface writer within service_mode.php/api.php "
            . '(other settings-surface admin pages are the documented exemption)'
            . ($strayReads === [] ? '' : ' — found elsewhere at: ' . implode(', ', $strayReads))
    );
}

/* =========================================================================
 * PART B — the resolver matrix (DB, else SKIP)
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

    require_once $scanRoot . '/includes/service_mode.php';

    try {
        $db = getDbMysqli();
        $hasOrgCols = serviceMode_orgIdleColumnsExist($db);
        $hasSessCols = serviceMode_idleColumnsExist($db);
    } catch (\Throwable $e) {
        $db = null; $hasOrgCols = false; $hasSessCols = false;
    }

    if ($db !== null && $hasOrgCols && $hasSessCols) {
        $db->begin_transaction();
        try {
            $mkUser = function (string $tag, ?int $prefMins) use ($db): int {
                $settings = $prefMins === null ? null : json_encode(['liveIdleTimeoutMins' => $prefMins]);
                $username = 'zz1770_' . $tag;
                $email = $username . '@example.invalid';
                $stmt = $db->prepare(
                    'INSERT INTO tblUsers (Username, Email, EmailVerified, PasswordHash, DisplayName, Role, CcliNumber, Settings)
                     VALUES (?, ?, 0, \'x\', ?, \'user\', \'\', ?)'
                );
                $stmt->bind_param('ssss', $username, $email, $username, $settings);
                $stmt->execute();
                $id = (int)$db->insert_id;
                $stmt->close();
                return $id;
            };
            $mkOrg = function (string $tag, ?int $mins, bool $enforce) use ($db): int {
                $name = 'ZZ #1770 ' . $tag;
                $slug = 'zz1770-' . $tag . '-' . bin2hex(random_bytes(3));
                $stmt = $db->prepare(
                    'INSERT INTO tblOrganisations (Name, Slug, IsActive, LiveIdleTimeoutMins, EnforceIdleTimeout)
                     VALUES (?, ?, 1, ?, ?)'
                );
                $enf = $enforce ? 1 : 0;
                $stmt->bind_param('ssii', $name, $slug, $mins, $enf);
                $stmt->execute();
                $id = (int)$db->insert_id;
                $stmt->close();
                return $id;
            };
            $join = function (int $userId, int $orgId) use ($db): void {
                $stmt = $db->prepare('INSERT INTO tblOrganisationMembers (UserId, OrgId, Role) VALUES (?, ?, \'member\')');
                $stmt->bind_param('ii', $userId, $orgId);
                $stmt->execute();
                $stmt->close();
            };

            /* B1 — enforced beats a conflicting user preference. */
            $uB1 = $mkUser('b1', 999);
            $oB1 = $mkOrg('b1', 10, true);
            $join($uB1, $oB1);
            $rB1 = serviceMode_resolveIdleTimeoutMins($db, $uB1);
            lfi($rB1 === 10, "B1 an ENFORCING org's value (10) wins over a conflicting user preference (999) — got {$rB1}");

            /* B2 — user preference wins over a non-enforcing org default. */
            $uB2 = $mkUser('b2', 20);
            $oB2 = $mkOrg('b2', 30, false);
            $join($uB2, $oB2);
            $rB2 = serviceMode_resolveIdleTimeoutMins($db, $uB2);
            lfi($rB2 === 20, "B2 a user preference (20) wins over a non-enforcing org default (30) — got {$rB2}");

            /* B3 — org default wins over the app default when no user pref is set. */
            $uB3 = $mkUser('b3', null);
            $oB3 = $mkOrg('b3', 45, false);
            $join($uB3, $oB3);
            $rB3 = serviceMode_resolveIdleTimeoutMins($db, $uB3);
            lfi($rB3 === 45, "B3 a non-enforcing org default (45) wins over the app default with no user pref — got {$rB3}");

            /* B4 — two ENFORCING orgs: the MINIMUM enforcing value wins (§10 S3). */
            $uB4 = $mkUser('b4', null);
            $oB4a = $mkOrg('b4a', 25, true);
            $oB4b = $mkOrg('b4b', 15, true);
            $join($uB4, $oB4a);
            $join($uB4, $oB4b);
            $rB4 = serviceMode_resolveIdleTimeoutMins($db, $uB4);
            lfi($rB4 === 15, "B4 the MINIMUM of two enforcing orgs' values (25, 15) wins — got {$rB4}, expected 15");

            /* B5 — clamp at both ends [5, 240], nothing else constraining. */
            $uB5hi = $mkUser('b5hi', 999999);
            $rB5hi = serviceMode_resolveIdleTimeoutMins($db, $uB5hi);
            lfi($rB5hi === 240, "B5 an out-of-range HIGH user preference (999999) clamps to 240 — got {$rB5hi}");
            $uB5lo = $mkUser('b5lo', 1);
            $rB5lo = serviceMode_resolveIdleTimeoutMins($db, $uB5lo);
            lfi($rB5lo === 5, "B5 an out-of-range LOW user preference (1) clamps to 5 — got {$rB5lo}");

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
    : " (Part B SKIPPED: no reachable database with dbname, or the #1770 C1 migration is not applied).\n";
exit(0);
