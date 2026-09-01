<?php

declare(strict_types=1);

/**
 * iHymns — Rate Limit Middleware Helper
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Provides a reusable rate-limiting function that checks tblLoginAttempts
 * (or any time-based counter) to throttle requests per IP and action.
 *
 * USAGE:
 *   require_once __DIR__ . DIRECTORY_SEPARATOR . 'rate_limit.php';
 *
 *   // Returns true if allowed, false if rate limited
 *   $allowed = checkRateLimit('auth_login', $clientIp, 10, 900);
 *
 *   // Auto-respond with 429 if rate limited (exits on failure)
 *   checkRateLimit('auth_login', $clientIp, 10, 900, true);
 *
 * @requires PHP 8.1+ with mysqli
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'db_mysql.php';

/* =========================================================================
 * DIRECT ACCESS PREVENTION
 * ========================================================================= */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

/* =========================================================================
 * ACTION-SCOPED LOGIN LOCKOUT — write primitive + read scope (#1929/#1930)
 * =========================================================================
 * ELI5: `tblLoginAttempts` is ONE shared table, but it holds rows from LOTS
 * of unrelated things — registering, verifying an email code, Sign in with
 * Apple, deleting an account, even a congregant guessing a Service Mode join
 * code. Until now nothing recorded WHICH of those a row was for in a way the
 * real login-lockout could read, so a flood of failures from any one of them
 * could lock an IP out of the LOGIN form even though nobody actually
 * mistyped a password. `Action` fixes that: every writer stamps a real
 * action name, and the login-lockout readers count `Action = 'login'` only.
 *
 * DETAIL — WHY THIS LIVES HERE, NOT INLINE AT EACH CALL SITE. Before #1929,
 * TWO call sites (api.php's `auth_login` case and manage/includes/auth.php's
 * attemptLogin()) each ran their OWN byte-for-byte copy of the same
 * `SELECT COUNT(*) … WHERE IpAddress = ? AND Success = 0 …` query, and ~40
 * more call sites wrote through recordRateLimitHit()'s own INSERT. Adding an
 * `Action` column and scoping it correctly is exactly the kind of change
 * rule #35 warns about forking twice: get the scope subtly different between
 * the two login readers (or between a reader and the writers actually
 * stamping the value) and the lockout silently drifts apart again — the same
 * failure class #1929 itself reports. ONE write primitive
 * (`loginAttemptsInsert()`) and ONE read-scope builder
 * (`authLoginIpFailureCountSql()` + `authLoginIpFailureCount()`) close that
 * by construction: every login surface calls the SAME function, so they
 * cannot disagree.
 *
 * NEVER-WEAKER BY CONSTRUCTION. `loginAttemptsActionReady()` gates BOTH
 * halves on whether the migrated `Action` column actually exists yet:
 *   - loginAttemptsInsert() falls back to the historical 3-column INSERT
 *     (Action absent) so an un-migrated install writes EXACTLY what it
 *     always wrote — never a STRICT-mode throw on a missing column.
 *   - authLoginIpFailureCountSql(false) is the query every login surface ran
 *     before #1929, unchanged — so an un-migrated install's login lockout is
 *     behaviourally IDENTICAL to today, never weaker and never a 500.
 * The migration (appWeb/.sql/migrate-add-login-attempts-action.php) also
 * DEFAULTs every pre-existing row to Action='login' — the conservative
 * choice — so a row written before this column existed stays counted by the
 * login lockout the moment the ready branch switches on, rather than
 * quietly falling out of it.
 *
 * @see appWeb/.sql/migrate-add-login-attempts-action.php
 * @see #1929 #1930
 * ========================================================================= */

/** Per-IP login-lockout threshold — extracted from the literal `10` every
 *  login surface compared against before #1929 (api.php's auth_login case,
 *  manage/includes/auth.php's attemptLogin()). Value UNCHANGED. */
const IHYMNS_AUTH_IP_MAX = 10;

/** Per-IP login-lockout sliding window, in seconds — extracted from the
 *  literal `15 MINUTE` every login surface hardcoded before #1929. Value
 *  UNCHANGED (900 seconds = 15 minutes); authLoginIpFailureCountSql() derives
 *  its `INTERVAL … MINUTE` clause from this constant rather than repeating
 *  the number, so the two can never drift apart (rule #35). */
const IHYMNS_AUTH_IP_WINDOW = 900;

/**
 * Whether the migrated `tblLoginAttempts.Action` column exists yet (#1929).
 *
 * Static-cached per request (the column either exists or it doesn't for the
 * lifetime of one request) — mirrors tierCapsColumnExists()
 * (includes/access_tier_validation.php), the established pattern for a
 * dormant-migration existence probe in this codebase.
 *
 * @param \mysqli $db Live connection.
 * @return bool True once appWeb/.sql/migrate-add-login-attempts-action.php
 *              has run on this database.
 */
function loginAttemptsActionReady(\mysqli $db): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    try {
        $stmt = $db->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'tblLoginAttempts'
                AND COLUMN_NAME = 'Action' LIMIT 1"
        );
        $stmt->execute();
        $cached = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();
    } catch (\Throwable $_e) {
        /* The safe degrade is "not migrated" — every caller below then takes
           the historical, always-safe, byte-identical-to-today branch. */
        $cached = false;
    }
    return $cached;
}

/**
 * The SOLE write primitive for `tblLoginAttempts` (#1929) — every INSERT in
 * the app funnels through here, including recordRateLimitHit()'s.
 *
 * ELI5: "write one row, and if the database has learned the new Action
 * column, fill it in — otherwise write exactly what we always wrote."
 *
 * THROW-TRANSPARENT BY DESIGN: this does NOT catch exceptions. Every
 * existing caller already owns its own try/catch (the four api.php
 * action-specific failure recorders each wrap their own INSERT in a
 * best-effort try/catch; recordRateLimitHit() has its own top-level catch;
 * the two login-fail sites are unguarded today and stay unguarded — see the
 * call sites). Swallowing here too would just duplicate that, and could hide
 * a genuine failure from a caller that specifically wants to know.
 *
 * @param \mysqli $db       Live connection.
 * @param string  $ip       IpAddress column value — a real client IP, a
 *                          resolved rate-limit bucket key (e.g. an 'acct:'
 *                          or 'user:' key), or '' for the isolated
 *                          setup-database.php secret_rotate sentinel (#1466
 *                          — deliberately NOT routed through this primitive,
 *                          see that file's own doc-block for why).
 * @param string  $username Username column value — UNCHANGED meaning, the
 *                          long-standing overload: a real submitted username
 *                          for a login row, or an action-name sentinel for
 *                          every non-login caller.
 * @param bool    $success  Success column value.
 * @param string  $action   The Action namespace value (#1929) — 'login' for
 *                          a genuine login attempt (success or failure),
 *                          else the real action name. Truncated to 40 chars
 *                          (the column width) so an oversized action name is
 *                          merely truncated, never a STRICT-mode throw.
 */
function loginAttemptsInsert(\mysqli $db, string $ip, string $username, bool $success, string $action): void
{
    $successInt = $success ? 1 : 0;

    if (loginAttemptsActionReady($db)) {
        $actionVal = substr($action, 0, 40);
        $stmt = $db->prepare(
            'INSERT INTO tblLoginAttempts (IpAddress, Username, Success, Action) VALUES (?, ?, ?, ?)'
        );
        $stmt->bind_param('ssis', $ip, $username, $successInt, $actionVal);
        $stmt->execute();
        $stmt->close();
        return;
    }

    /* Un-migrated fallback — BYTE-IDENTICAL 3-column insert to every call
       site's own INSERT before #1929, so an un-migrated install writes
       exactly what it always wrote. */
    $stmt = $db->prepare(
        'INSERT INTO tblLoginAttempts (IpAddress, Username, Success) VALUES (?, ?, ?)'
    );
    $stmt->bind_param('ssi', $ip, $username, $successInt);
    $stmt->execute();
    $stmt->close();
}

/**
 * PURE builder for the login-lockout per-IP failure-count query (#1929) —
 * the testable seam (tests/php/test-auth-rate-limit.php evals this function
 * in isolation, no DB required). Not itself a DB call.
 *
 * $actionReady=false returns the query every login surface ran before
 * #1929 — functionally unchanged (same columns, same operator, same 15-
 * minute window), so an un-migrated install's login lockout behaves
 * identically to today. $actionReady=true adds ONLY `AND Action = 'login'`,
 * so a failure from any OTHER action (auth_register, service_join, …) can no
 * longer inflate this counter — the #1929 fix.
 *
 * @param bool $actionReady Whether tblLoginAttempts.Action exists yet (from
 *                           loginAttemptsActionReady()).
 * @return string A parameterised SQL string with exactly one `?` (IpAddress).
 */
function authLoginIpFailureCountSql(bool $actionReady): string
{
    $windowMinutes = (int) (IHYMNS_AUTH_IP_WINDOW / 60);
    if ($actionReady) {
        return "SELECT COUNT(*) FROM tblLoginAttempts
             WHERE IpAddress = ? AND Action = 'login' AND Success = 0
             AND AttemptedAt > DATE_SUB(NOW(), INTERVAL {$windowMinutes} MINUTE)";
    }
    return "SELECT COUNT(*) FROM tblLoginAttempts
             WHERE IpAddress = ? AND Success = 0
             AND AttemptedAt > DATE_SUB(NOW(), INTERVAL {$windowMinutes} MINUTE)";
}

/**
 * Count recent LOGIN failures for one IP (#1929) — the shared read half of
 * the per-IP login lockout, called identically by api.php's `auth_login`
 * case and manage/includes/auth.php's attemptLogin(). De-duplicates what
 * used to be two byte-identical inline `SELECT COUNT(*)` queries.
 *
 * THROW-TRANSPARENT BY DESIGN, matching both call sites' pre-#1929
 * behaviour exactly: neither wrapped this query in its own try/catch, so a
 * genuine DB error propagated to the request's normal error handling rather
 * than silently permitting (or silently blocking) the login. Preserving that
 * is the never-weaker choice — see the GIRFT note in this section's header.
 *
 * @param string $ip Client IP address (REMOTE_ADDR — never a spoofable
 *                    forwarded header).
 * @return int Number of Action='login' Success=0 rows for this IP within
 *             the last IHYMNS_AUTH_IP_WINDOW seconds.
 */
function authLoginIpFailureCount(string $ip): int
{
    $db = getDbMysqli();
    $stmt = $db->prepare(authLoginIpFailureCountSql(loginAttemptsActionReady($db)));
    $stmt->bind_param('s', $ip);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    $stmt->close();
    return (int)($row[0] ?? 0);
}

/**
 * Resolve the bucket key a rate-limit check/record pair must share.
 *
 * ELI5: "which counter does this request belong in? — the signed-in user's
 * own counter if we know who they are, otherwise their IP address."
 *
 * WHY THIS IS A SHARED FUNCTION AND NOT AN INLINE EXPRESSION (#1636).
 * checkRateLimit() derives this key internally from ($ip, $userId), but
 * recordRateLimitHit() takes an ALREADY-RESOLVED key. Every call site
 * therefore had to re-derive the same string by hand, e.g.
 *
 *     checkRateLimit('live_follow_create', $liveIp, 20, 3600, true, $userId);
 *     recordRateLimitHit('live_follow_create', 'user:' . $userId);
 *
 * Two hand-written copies of one rule is exactly the shape that rots: get the
 * derivation subtly wrong (say `'user:' . $userId` where $userId can be NULL,
 * as it can on service_broadcast's delegated control-token path) and the
 * WRITER files rows under `user:` while the READER counts rows under the IP —
 * a counter that silently never trips. That is the same class of failure as
 * #1636 itself, just one layer down, so the derivation now lives in ONE place
 * that both halves of the contract call.
 *
 * @param string   $ip     Client IP address (the anonymous fallback key).
 * @param int|null $userId Authenticated user ID, or null/0 when anonymous.
 * @return string The bucket key — 'user:<id>' when identified, else the IP.
 */
function rateLimitKey(string $ip, ?int $userId = null): string
{
    return $userId !== null && $userId > 0 ? 'user:' . $userId : $ip;
}

/* =========================================================================
 * PER-ACCOUNT LOGIN BUCKET (#1027) — the ONE shared derivation
 * =========================================================================
 * ELI5: a second lock on the login door that counts bad guesses aimed at
 * ONE account no matter which address they come from — so a botnet giving
 * each node only nine tries (just under the per-IP cap of 10) can no longer
 * grind a single password forever.
 *
 * WHY THIS DERIVATION LIVES HERE, NOT INLINE AT EACH LOGIN SURFACE.
 * There are TWO login surfaces — api.php `auth_login` (the public app + the
 * native clients) and manage/includes/auth.php `attemptLogin()` (the /manage
 * admin form) — and #1027's own scope said "apply identically in both". The
 * api.php half shipped 2026-08-18; the /manage half did not, so the exact
 * attack #1027 was filed to stop still worked against the highest-value
 * accounts. The remainder (this constant trio + helper + the manage-side
 * check/record) closes it.
 *
 * The trap a second inline copy would fall into: api.php normalises the
 * submitted username with mb_strtolower(trim()) and attemptLogin() with
 * strtolower(trim()). Those are identical for the registered [a-z0-9_.\-]
 * charset but NOT for arbitrary submitted bytes — and a submitted username
 * IS arbitrary (an attacker sends whatever they like). Two surfaces deriving
 * the bucket key from differently-folded inputs would fill two DIFFERENT
 * buckets for the same target account, so an attacker splitting guesses
 * across /api and /manage would get two independent allowances instead of
 * one shared 20/15-min budget. ONE function, ONE fold (mb_strtolower(trim())),
 * closes that gap by construction.
 *
 * The bucket rides tblLoginAttempts with NO schema change: the shared
 * checkRateLimit()/recordRateLimitHit() convention is "bucket key in the
 * IpAddress column, action name in Username". Keying on
 *   'acct:' . substr(sha256(folded username), 0, 40)   → 45 chars, fits VARCHAR(45)
 * rides the existing idx_IpTime index (a cheap indexed lookup, not a table
 * scan) and cannot collide with a real account name written under the raw IP.
 * It is NOT a secrecy measure — usernames are low-entropy and tblLoginAttempts
 * already stores them plainly in the Username column of the per-IP rows.
 */
const IHYMNS_AUTH_ACCT_ACTION = 'auth_login_acct';
const IHYMNS_AUTH_ACCT_MAX    = 20;   /* 2x the per-IP 10 — same 15-min window, so the two compose */
const IHYMNS_AUTH_ACCT_WINDOW = 900;  /* 15 minutes, a self-healing sliding window */

/**
 * Canonical per-account login-bucket key (#1027).
 *
 * The FOLD LIVES HERE deliberately (see the block comment above): both login
 * surfaces MUST derive the bucket from an identically-folded username or they
 * fill two buckets for one account. mb_strtolower is the multibyte-correct
 * fold (matching api.php's auth_login handler); trim() drops surrounding
 * whitespace an attacker could vary to dodge the counter.
 *
 * @param string $submittedUsername The RAW submitted username (pre-lookup).
 * @return string 'acct:' + first 40 hex chars of sha256 → 45 chars (VARCHAR(45)).
 */
function authLoginAcctKey(string $submittedUsername): string
{
    return 'acct:' . substr(hash('sha256', mb_strtolower(trim($submittedUsername))), 0, 40);
}

/**
 * Check whether a request is within the rate limit for a given action.
 *
 * Uses tblLoginAttempts as the counter table (the `Username` column is
 * repurposed to carry the action identifier for non-login checks). When
 * an authenticated user ID is supplied, the IP-based key is replaced
 * with `user:<id>` so a single user can't side-step the limit by
 * cycling addresses, and a per-user signed-in budget can be set
 * larger than the per-IP unauthenticated one.
 *
 * TWO-PART CONTRACT (#1636). This function only ever READS the counter.
 * Nothing is capped unless a matching recordRateLimitHit() WRITES to the same
 * (action, key) bucket — a check without its paired record is a cap that can
 * never be reached, which is precisely the bug #1636 found on thirteen
 * actions. tests/php/test-rate-limit-pairing.php now fails the build on an
 * unpaired action name. Derive the shared key with rateLimitKey() rather than
 * re-deriving 'user:' . $id by hand.
 *
 * Sliding-window — counts rows whose AttemptedAt is within the last
 * `windowSeconds` seconds, so the cap glides rather than reset on a
 * fixed boundary. Fails open on DB errors (logged) so a blip in the
 * counter table doesn't lock everyone out.
 *
 * @param string   $action        Identifier for the action being rate
 *                                 limited (e.g., 'auth_login',
 *                                 'song_request', 'og_image').
 * @param string   $ip            Client IP address (empty string is a
 *                                 no-op — cannot rate limit without a
 *                                 key).
 * @param int      $maxAttempts   Maximum allowed attempts within the
 *                                 window.
 * @param int      $windowSeconds Time window in seconds (e.g., 900
 *                                 for 15 minutes).
 * @param bool     $autoRespond   If true, sends a 429 JSON response
 *                                 and exits when the limit is hit.
 * @param int|null $userId        Optional authenticated user ID. If
 *                                 supplied, keys the bucket by user
 *                                 instead of IP — pass for endpoints
 *                                 where the per-user budget is the
 *                                 thing you actually want to cap.
 *
 * @return bool True if the request is allowed, false if rate limited.
 */
function checkRateLimit(
    string $action,
    string $ip,
    int $maxAttempts,
    int $windowSeconds,
    bool $autoRespond = false,
    ?int $userId = null
): bool {
    /* Per-user buckets are keyed off `user:<id>` in the IpAddress
       column. Cannot rate limit without either a user or an IP.
       Shared with every recordRateLimitHit() caller via rateLimitKey()
       so the reader and the writer can never disagree (#1636). */
    $key = rateLimitKey($ip, $userId);
    if ($key === '') {
        return true;
    }

    try {
        $db = getDbMysqli();

        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM tblLoginAttempts
             WHERE IpAddress = ? AND Username = ?
             AND AttemptedAt > DATE_SUB(NOW(), INTERVAL ? SECOND)'
        );
        $stmt->bind_param('ssi', $key, $action, $windowSeconds);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_row();
        $stmt->close();
        $count = (int)($row[0] ?? 0);

        if ($count >= $maxAttempts) {
            if ($autoRespond) {
                http_response_code(429);
                header('Content-Type: application/json; charset=UTF-8');
                header('X-Content-Type-Options: nosniff');
                header('Cache-Control: no-cache, must-revalidate');
                header('Retry-After: ' . $windowSeconds);
                echo json_encode([
                    'error' => 'Too many requests. Please try again later.',
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit;
            }
            return false;
        }

        return true;
    } catch (\Throwable $e) {
        /* Fail open on DB error — better to over-serve a few requests
           than to lock everyone out of an endpoint because the
           counter table briefly went away. Logged so a sustained
           outage is visible. \Throwable catches mysqli_sql_exception
           plus any other unexpected error from get_result()/fetch_row(). */
        error_log('[iHymns] Rate limit check failed: ' . $e->getMessage());
        return true;
    }
}

/**
 * Record a rate limit hit for tracking purposes.
 *
 * Inserts a row into tblLoginAttempts with the action as the Username field
 * (the long-standing overload) AND, since #1929, the SAME action name into
 * the new Action column — via the shared loginAttemptsInsert() primitive, so
 * this ONE change is what stops every one of this function's ~40 callers
 * (service_join among them — the NAT-shared-congregation case: several
 * congregants behind one router IP, each guessing a join code, previously
 * inflated the shared IP's LOGIN lockout too) from being countable by the
 * login-lockout readers, which now filter `Action = 'login'` exclusively.
 * This allows checkRateLimit() to count the attempt in future checks.
 *
 * The $ip argument is the ALREADY-RESOLVED bucket key, not necessarily a raw
 * address — pass rateLimitKey($ip, $userId) so it matches whatever key the
 * paired checkRateLimit() will read (#1636). Note that checkRateLimit()
 * counts rows WITHOUT regard to $success, so a caller that wants to budget
 * only failures (auth_login_acct, service_join) simply records nothing on the
 * success path; $success is an audit signal, not a filter.
 *
 * @param string $action  Action identifier (stored in both the Username
 *                         column, unchanged, and — #1929 — the Action column)
 * @param string $ip      Resolved bucket key (IP address or 'user:<id>' etc.)
 * @param bool   $success Whether the action succeeded (default: true)
 */
function recordRateLimitHit(string $action, string $ip, bool $success = true): void
{
    try {
        $db = getDbMysqli();
        loginAttemptsInsert($db, $ip, $action, $success, $action);
    } catch (\Throwable $e) {
        error_log('[iHymns] Rate limit recording failed: ' . $e->getMessage());
    }
}
