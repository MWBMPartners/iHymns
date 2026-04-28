<?php

declare(strict_types=1);

/**
 * iHymns — Activity Log Helper (#535)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Single canonical write API for `tblActivityLog`. Every meaningful
 * action in the system — auth events, admin CRUD, user activity, API
 * requests, system events — calls `logActivity()` so we end up with
 * one consistently-shaped audit trail across the whole codebase.
 *
 * The table is described in `appWeb/.sql/schema.sql`; the columns
 * supporting Result/UserAgent/RequestId/Method/DurationMs were added
 * in `migrate-activity-log-expand.php`.
 *
 * USAGE:
 *   require_once __DIR__ . '/activity_log.php';
 *
 *   logActivity('auth.login', 'user', $userId, ['username' => $u]);
 *   logActivity('song.edit',  'song', $songId, [
 *       'fields' => ['Title','Copyright'],
 *       'before' => ['Title' => 'Old', 'Copyright' => '(c) 2010'],
 *       'after'  => ['Title' => 'New', 'Copyright' => '(c) 2026'],
 *   ]);
 *   logActivity('auth.login', 'user', $username, [
 *       'reason' => 'wrong_password',
 *   ], 'failure');
 *
 * BEST-EFFORT GUARANTEE:
 *   logActivity() never throws and never blocks the caller. On
 *   any failure it writes a one-line `error_log()` entry prefixed
 *   `[activity_log]` and returns. A logging outage will not
 *   degrade a live request.
 *
 * REQUEST CORRELATION:
 *   Every PHP request gets one auto-generated 16-char hex
 *   RequestId on first call. Every subsequent call within the
 *   same request shares it, so admins can pull `WHERE RequestId =
 *   ?` to reconstruct exactly what one HTTP request did.
 *
 * PER-REQUEST FLOOD CAP:
 *   A runaway loop calling `logActivity()` thousands of times
 *   could flood the table. After IHYMNS_LOG_PER_REQUEST_CAP rows
 *   the helper short-circuits silently for the rest of the
 *   request and writes a single warning to error_log.
 *
 * PRIVACY:
 *   Callers are responsible for keeping sensitive data out of
 *   `$details`. Project-rules.md documents the policy:
 *     - NEVER log password hashes, plaintext passwords, bearer
 *       tokens, magic-link tokens, or password-reset tokens.
 *     - DO log user_id, username, email, IP, UA — these are
 *       already in the user record.
 *     - For edits, log the field-name list + before/after values,
 *       NOT the entire row.
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

/** Cap on activity-log writes per HTTP request — guards against runaway loops. */
const IHYMNS_LOG_PER_REQUEST_CAP = 200;

/**
 * Get (or lazily mint) the per-request correlation ID. Stable for
 * the lifetime of the PHP request; new on the next request.
 *
 * @return string 16-char hex
 */
function activityLogRequestId(): string
{
    static $rid = null;
    if ($rid === null) {
        try {
            $rid = bin2hex(random_bytes(8));
        } catch (\Throwable $_e) {
            /* random_bytes() should not fail on a working PHP; fall
               back to a less-strong but always-available source. */
            $rid = substr(hash('sha256', uniqid('', true) . microtime(true)), 0, 16);
        }
    }
    return $rid;
}

/**
 * Per-request write counter — increments on every successful insert.
 * Used by the flood cap above. Exposed (read-only) for tests.
 */
function activityLogWriteCount(?int $set = null): int
{
    static $count = 0;
    if ($set !== null) $count = $set;
    return $count;
}

/**
 * Resolve the acting user ID for a log row.
 *
 * Order of precedence:
 *   1. explicit `$userId` argument from the caller
 *   2. /manage/ session: $_SESSION['user_id']
 *   3. main-app bearer-token auth: getAuthenticatedUser() if available
 *   4. NULL (unauthenticated / system)
 */
function activityLogResolveUserId(?int $userId): ?int
{
    if ($userId !== null) return $userId > 0 ? $userId : null;

    if (session_status() === PHP_SESSION_ACTIVE
        && isset($_SESSION['user_id'])
        && (int)$_SESSION['user_id'] > 0) {
        return (int)$_SESSION['user_id'];
    }

    if (function_exists('getAuthenticatedUser')) {
        try {
            $u = getAuthenticatedUser();
            if (is_array($u) && !empty($u['Id'])) {
                return (int)$u['Id'];
            }
        } catch (\Throwable $_e) {
            /* Best-effort — ignore and fall through. */
        }
    }

    return null;
}

/**
 * Persist one row to tblActivityLog.
 *
 * @param string  $action      Dotted lowercase verb. e.g. 'auth.login',
 *                              'song.edit', 'setlist.share'. Max 50 chars.
 * @param string  $entityType  Logical entity type. e.g. 'song', 'user',
 *                              'songbook', 'setlist'. Max 50 chars; '' OK.
 * @param string  $entityId    Primary key of the affected entity, as a
 *                              string. Max 50 chars; '' OK.
 * @param array   $details     Arbitrary JSON-serialisable context. Common
 *                              shapes:
 *                                edits:    ['fields'=>[],'before'=>[],'after'=>[]]
 *                                failures: ['reason'=>'wrong_password']
 *                                errors:   ['error'=>$e->getMessage(),'code'=>500]
 * @param string  $result      One of 'success' | 'failure' | 'error'.
 * @param int|null $userId     Override acting user (defaults to session/auth).
 * @param int|null $durationMs Wall-clock duration of the logged op.
 *
 * @return void Best-effort — never throws.
 */
function logActivity(
    string $action,
    string $entityType = '',
    string $entityId   = '',
    array  $details    = [],
    string $result     = 'success',
    ?int   $userId     = null,
    ?int   $durationMs = null
): void {
    /* Per-request flood cap. A logging-induced infinite loop would
       otherwise drown the table; one warning is enough. */
    $count = activityLogWriteCount();
    if ($count >= IHYMNS_LOG_PER_REQUEST_CAP) {
        if ($count === IHYMNS_LOG_PER_REQUEST_CAP) {
            error_log('[activity_log] per-request cap reached (' . IHYMNS_LOG_PER_REQUEST_CAP
                . '); further log calls in this request are dropped.');
            activityLogWriteCount($count + 1); /* push past cap so the warn fires once */
        }
        return;
    }

    /* Validate Result up-front — anything outside the ENUM would
       trigger a strict-mode error on insert. */
    if (!in_array($result, ['success', 'failure', 'error'], true)) {
        error_log('[activity_log] invalid Result "' . $result . '" — coerced to error');
        $result = 'error';
    }

    if ($action === '' || strlen($action) > 50) {
        error_log('[activity_log] invalid action "' . $action . '" — skipped');
        return;
    }

    /* JSON-encode Details. Failed encode (e.g. unicode that doesn't
       round-trip) shouldn't kill the write — fall back to a sentinel. */
    if (!empty($details)) {
        $detailsJson = json_encode(
            $details,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR
        );
        if ($detailsJson === false) {
            $detailsJson = '{"_error":"json_encode_failed"}';
        }
    } else {
        $detailsJson = null;
    }

    $resolvedUserId = activityLogResolveUserId($userId);
    $ip             = (string)($_SERVER['REMOTE_ADDR']     ?? '');
    $ua             = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
    $method         = strtoupper((string)($_SERVER['REQUEST_METHOD']  ?? ''));
    $rid            = activityLogRequestId();

    try {
        $db = getDbMysqli();
        $stmt = $db->prepare(
            'INSERT INTO tblActivityLog
                (UserId, Action, EntityType, EntityId, Result, Details,
                 IpAddress, UserAgent, RequestId, Method, DurationMs, CreatedAt)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        /* Types: UserId(i nullable), Action(s), EntityType(s), EntityId(s),
           Result(s), Details(s nullable), IpAddress(s), UserAgent(s),
           RequestId(s), Method(s), DurationMs(i nullable). mysqli passes
           NULL correctly when the bound variable is null even with type
           'i' or 's'. */
        $actionTrim   = substr($action,     0, 50);
        $entityTrim   = substr($entityType, 0, 50);
        $entityIdTrim = substr($entityId,   0, 50);
        $methodTrim   = substr($method,     0, 10);
        $duration     = $durationMs !== null ? max(0, $durationMs) : null;
        $stmt->bind_param(
            'isssssssssi',
            $resolvedUserId,
            $actionTrim,
            $entityTrim,
            $entityIdTrim,
            $result,
            $detailsJson,
            $ip,
            $ua,
            $rid,
            $methodTrim,
            $duration
        );
        $stmt->execute();
        $stmt->close();
        activityLogWriteCount($count + 1);
    } catch (\Throwable $e) {
        /* Logging is best-effort. A failure here must not propagate.
           One error_log so admins can spot a sustained outage. */
        error_log('[activity_log] write failed for "' . $action . '": ' . $e->getMessage());
    }
}

/**
 * Convenience wrapper for the common "log this server-side
 * exception" pattern. Captures the exception's message + class
 * into Details and tags Result='error' automatically.
 */
function logActivityError(
    string $action,
    string $entityType,
    string $entityId,
    \Throwable $e,
    array $extra = []
): void {
    logActivity(
        $action,
        $entityType,
        $entityId,
        array_merge(
            [
                'error'      => $e->getMessage(),
                'class'      => get_class($e),
                'file'       => basename($e->getFile()),
                'line'       => $e->getLine(),
            ],
            $extra
        ),
        'error'
    );
}
