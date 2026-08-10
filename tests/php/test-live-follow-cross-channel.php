<?php

declare(strict_types=1);

/**
 * iHymns — Live Follow / Service Mode cross-channel join hint (#1792)
 * ============================================================================
 * ELI5: the three iHymns web addresses (dev, preview, live) share one database
 * but each only "sees" its own live sessions — the Channel wall (rule #26). The
 * instinctive test of Go Live / Join Live (start on the laptop's dev URL, join
 * on the phone's live PWA) is therefore always cross-channel and always failed
 * with a generic "wrong code", reading as "the feature is broken". #1792 adds a
 * clearer hint: when a well-formed code is live on ANOTHER channel, both join
 * endpoints say "that code is for a different environment" instead. This guard
 * proves the hint is wired into BOTH endpoints from the ONE message constant,
 * and that the detection helper's Channel semantics are exactly right — because
 * a `<>`→`=` slip there would either never fire the hint (test always broken) or
 * fire it for the caller's OWN channel (re-opening the anti-probe leak the
 * generic message protects — rule #26 I6).
 *
 * PART A (always, no DB) — source scan:
 *   A1. `SERVICE_MODE_WRONG_CHANNEL_MESSAGE` is defined EXACTLY ONCE, in
 *       includes/service_mode.php (rule #35 — one definition, so the two entry
 *       points can never drift apart on the wording).
 *   A2. `serviceMode_codeOnOtherChannel()` is defined exactly once there too.
 *   A3. BOTH the `live_follow_join` and `service_join` case blocks in api.php
 *       reference `serviceMode_codeOnOtherChannel(` AND
 *       `SERVICE_MODE_WRONG_CHANNEL_MESSAGE` — i.e. each entry point actually
 *       performs the cross-channel check and emits the shared message. Removing
 *       the check (or the message) from either endpoint turns this red.
 *
 * PART B (DB, else SKIP) — the helper's Channel semantics, behaviourally, for
 * BOTH live-session families sharing the #1268 spine:
 *   B1. A QUICK (Live Follow) SessionCode live on ANOTHER channel → true.
 *   B2. That same code, asked from the OTHER channel's own perspective → false
 *       (it is not cross-channel to itself). Load-bearing: a `<>`→`=` slip
 *       flips B1 and/or B2.
 *   B3. A QUICK code live on the CURRENT channel → false (a code on your own
 *       address must NEVER trigger the wrong-environment hint — that would leak
 *       a current-channel session's liveness, the anti-probe concern).
 *   B4. An unknown code → false.
 *   B5. A SERVICE (rotating join-code) live on ANOTHER channel → true (the
 *       second helper branch, over tblLiveFollowJoinCodes).
 * All fixture rows are inserted in ONE transaction, ROLLED BACK at the end.
 *
 * MUTATION PROOF (rule #34): see the commit body — A1/A2 (delete the const/fn),
 * A3 (drop the call from one endpoint), and the helper's `<>` (swap to `=`,
 * which reddens B1/B2/B3) were each broken → red → restored → green.
 *
 * @see appWeb/public_html/includes/service_mode.php  serviceMode_codeOnOtherChannel()
 * @see appWeb/public_html/api.php                    live_follow_join / service_join
 * @see https://github.com/MWBMPartners/iHymns/issues/1792
 */

$scanRoot = getenv('IHYMNS_SCAN_ROOT') ?: (dirname(__DIR__, 2) . '/appWeb/public_html');
if (!is_dir($scanRoot)) {
    fwrite(STDERR, "FATAL: could not read $scanRoot\n");
    exit(1);
}

$failures = 0;
$passed   = 0;
function xcc(bool $cond, string $label): void
{
    global $failures, $passed;
    if ($cond) { $passed++; echo "PASS: $label\n"; }
    else       { $failures++; fwrite(STDERR, "FAIL: $label\n"); }
}

/** Blank out comments/doc-blocks so a mention in prose never counts as code. */
function xccStrip(string $php): string
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

/** The flat `case '<action>':` block in api.php up to the next top-level case. */
function xccCaseBlock(string $src, string $action): ?string
{
    if (!preg_match('/case\s+\'' . preg_quote($action, '/') . '\'\s*:/', $src, $m, PREG_OFFSET_CAPTURE)) {
        return null;
    }
    $start = $m[0][1];
    $nextPos = null;
    if (preg_match('/case\s+\'[a-z0-9_]+\'\s*:/', $src, $mNext, PREG_OFFSET_CAPTURE, $start + 10)) {
        $nextPos = $mNext[0][1];
    }
    return substr($src, $start, ($nextPos ?? strlen($src)) - $start);
}

/* =========================================================================
 * PART A — source scan
 * ========================================================================= */
$smSrc  = xccStrip((string)file_get_contents($scanRoot . '/includes/service_mode.php'));
$apiSrc = xccStrip((string)file_get_contents($scanRoot . '/api.php'));

/* A1 — the message constant is declared exactly once, in service_mode.php. */
$constDecls = preg_match_all('/\bconst\s+SERVICE_MODE_WRONG_CHANNEL_MESSAGE\b/', $smSrc);
xcc($constDecls === 1, "A1 SERVICE_MODE_WRONG_CHANNEL_MESSAGE is declared exactly once in service_mode.php (got {$constDecls})");

/* A2 — the detection helper is defined exactly once, in service_mode.php. */
$fnDecls = preg_match_all('/\bfunction\s+serviceMode_codeOnOtherChannel\s*\(/', $smSrc);
xcc($fnDecls === 1, "A2 serviceMode_codeOnOtherChannel() is defined exactly once in service_mode.php (got {$fnDecls})");

/* A3 — BOTH join endpoints perform the check AND emit the shared message. */
foreach (['live_follow_join', 'service_join'] as $action) {
    $block = xccCaseBlock($apiSrc, $action);
    if ($block === null) {
        xcc(false, "A3 found a case '$action': block in api.php to inspect");
        continue;
    }
    xcc(
        strpos($block, 'serviceMode_codeOnOtherChannel(') !== false,
        "A3 case '$action' calls serviceMode_codeOnOtherChannel() before its generic failure message (#1792)"
    );
    xcc(
        strpos($block, 'SERVICE_MODE_WRONG_CHANNEL_MESSAGE') !== false,
        "A3 case '$action' emits the shared SERVICE_MODE_WRONG_CHANNEL_MESSAGE on a cross-channel hit"
    );
}

/* =========================================================================
 * PART B — helper Channel semantics (DB, else SKIP)
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
    } catch (\Throwable $e) {
        $db = null;
    }

    if ($db !== null) {
        $current = serviceMode_channel();
        /* A fixed channel guaranteed distinct from any real docroot channel
           (dev/alpha/beta/www/local), <=16 chars (VARCHAR(16)). */
        $other = ($current === 'zzOtherChan') ? 'zzOtherCh2' : 'zzOtherChan';

        $rand = static fn(string $p): string => $p . strtoupper(bin2hex(random_bytes(3))); /* <=12 chars */

        $mkSession = function (string $code, string $channel, string $kind) use ($db): int {
            $stmt = $db->prepare(
                'INSERT INTO tblLiveFollowSessions
                    (SessionCode, HostUserId, SessionKind, Channel, IsActive, StartedAt, LastHeartbeatAt)
                 VALUES (?, NULL, ?, ?, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
            );
            $stmt->bind_param('sss', $code, $kind, $channel);
            $stmt->execute();
            $id = (int)$db->insert_id;
            $stmt->close();
            return $id;
        };
        $mkJoinCode = function (int $sessionId, string $code) use ($db): void {
            /* current status + a future ExpiresAt so it is a LIVE code. */
            $stmt = $db->prepare(
                'INSERT INTO tblLiveFollowJoinCodes
                    (SessionId, Code, Generation, Status, IssuedAt, ExpiresAt)
                 VALUES (?, ?, 0, \'current\', UTC_TIMESTAMP(), DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 HOUR))'
            );
            $stmt->bind_param('is', $sessionId, $code);
            $stmt->execute();
            $stmt->close();
        };

        $db->begin_transaction();
        try {
            /* B1/B2 — a QUICK code live on the OTHER channel. */
            $codeQ = $rand('ZQ');
            $mkSession($codeQ, $other, 'host');
            xcc(
                serviceMode_codeOnOtherChannel($db, $codeQ, $current) === true,
                "B1 a Quick SessionCode live on another channel is detected from the current channel"
            );
            xcc(
                serviceMode_codeOnOtherChannel($db, $codeQ, $other) === false,
                "B2 the same code, asked from ITS OWN channel, is not cross-channel (the <> filter is load-bearing)"
            );

            /* B3 — a QUICK code live on the CURRENT channel must NOT hint. */
            $codeC = $rand('ZC');
            $mkSession($codeC, $current, 'host');
            xcc(
                serviceMode_codeOnOtherChannel($db, $codeC, $current) === false,
                "B3 a code live on the caller's OWN channel never triggers the wrong-environment hint (anti-probe)"
            );

            /* B4 — an unknown code. */
            xcc(
                serviceMode_codeOnOtherChannel($db, $rand('ZN'), $current) === false,
                "B4 an unknown code returns false (no hint)"
            );

            /* B5 — a SERVICE rotating join-code live on the OTHER channel. */
            $codeS = $rand('ZS');
            $svcId = $mkSession($rand('ZH'), $other, 'service');
            $mkJoinCode($svcId, $codeS);
            xcc(
                serviceMode_codeOnOtherChannel($db, $codeS, $current) === true,
                "B5 a Service rotating join-code live on another channel is detected (the join-code branch)"
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
    ? " (Part B behavioural checks ran against a live DB).\n"
    : " (Part B SKIPPED: no reachable database with a dbname).\n";
exit(0);
