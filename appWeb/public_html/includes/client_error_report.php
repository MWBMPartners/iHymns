<?php

declare(strict_types=1);

/**
 * client_error_report.php — validation, scrubbing + fingerprinting for the
 * global JS error-beacon endpoint (#1582)
 * ============================================================================
 *
 * WHY:
 *   `js/modules/error-monitor.js` (`bootErrorMonitor()`) turns every uncaught
 *   client-side error/rejection into a small JSON beacon POSTed to
 *   `?action=client_error_report` (api.php). This file is the PURE, DB-free
 *   validation core that handler runs on every beacon before it's allowed
 *   anywhere near `logActivity()` / `tblActivityLog` — mirrors the split
 *   `includes/analytics_ingest.php` already established for #1448 (pure
 *   validators here, DB/rate-limit wiring in api.php).
 *
 * NO NEW TABLE:
 *   Per CLAUDE.md rule #19/#20, this reuses the EXISTING `tblActivityLog`
 *   (`EntityType='client'`, `EntityId=<fingerprint>`, `Result='error'`) —
 *   no migration, no new schema. See api.php's `case 'client_error_report'`
 *   for the dedupe SELECT against `tblActivityLog`'s existing
 *   `idx_Entity (EntityType, EntityId)` index.
 *
 * PRIVACY / SCRUB (security requirement — read before touching this file):
 *   `clientErrorScrub()` strips anything shaped like a 64-hex-char token
 *   (session ids, API keys — the same shape `activityLogScrubQuery()` in
 *   `activity_log.php` treats as credential-like) or a `Bearer <token>`
 *   header value. This is a DELIBERATE SECOND scrub on top of the client's
 *   own `_scrub()` (js/modules/error-monitor.js) — the client can be
 *   tampered with (a malicious actor can POST directly to this endpoint,
 *   bypassing the browser entirely), so the server never trusts a payload
 *   is already clean just because it came from the app's own JS.
 *   Neither this file nor its caller ever logs a bearer token, a
 *   session id, or a password — matching `activity_log.php`'s own header
 *   policy ("NEVER log … bearer tokens").
 *
 * VALIDATION / CAPS (all load-bearing):
 *   - `k` (kind) is checked against a CLOSED 2-value allow-list
 *     ('error' | 'rejection') — matches the client's only two dispatch
 *     sites (window 'error' / 'unhandledrejection'). Anything else is
 *     rejected outright (`clientErrorValidate()` returns `null`).
 *   - `m` (message) is required, scrubbed, then capped — a server-side
 *     backstop for the client's own 300-char slice, since a bypassing
 *     caller doesn't have to respect that.
 *   - `s` / `r` (source path / current-page path) are defensively
 *     stripped of any query string or fragment that slipped through —
 *     the client never sends `location.search`/`location.hash`, but the
 *     server does not trust that promise either (same "don't trust the
 *     client" posture as the message cap above).
 *   - `l` / `c` (line / column) are clamped to non-negative, plausible
 *     integers; anything absurd (or non-numeric) degrades to `null`
 *     rather than rejecting the whole report.
 *   - `st` (stack frames) is capped to the top few short strings, each
 *     independently scrubbed + length-capped.
 *
 * FINGERPRINT:
 *   `clientErrorFingerprint()` hashes `{env}|{message}|{source}|{line}` to
 *   a 16-char sha256 prefix — the SAME three fields (`m|s|l`) the client
 *   fingerprints for its own sessionStorage throttle
 *   (js/modules/error-monitor.js), plus the deploy environment so alpha /
 *   beta / production (which share ONE `tblActivityLog` — rule #1207)
 *   never collide on the same fingerprint for what are, in practice,
 *   different deployments' bugs.
 *
 * @see appWeb/public_html/js/modules/error-monitor.js   client-side counterpart
 * @see appWeb/public_html/includes/analytics_ingest.php the sibling pure-validator file this mirrors
 * @see appWeb/public_html/includes/activity_log.php     the write API this feeds (logActivity())
 * @see https://www.php.net/manual/en/function.hash.php
 * @see https://cwe.mitre.org/data/definitions/532.html  CWE-532: Insertion of Sensitive Information into Log File
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 */

/** Server-side backstop on the (already client-capped) message length. */
const CLIENT_ERROR_MAX_MESSAGE_LENGTH = 300;

/** Cap for `s` (source path) / `r` (current-page path). */
const CLIENT_ERROR_MAX_PATH_LENGTH = 255;

/** Cap for one `st` stack-frame string ("pathname:line"). */
const CLIENT_ERROR_MAX_FRAME_LENGTH = 120;

/** Max number of stack frames kept — mirrors the client's own top-3 reduction. */
const CLIENT_ERROR_MAX_FRAMES = 3;

/** Cap for the `v` (app version) / `ch` (deploy channel) labels. */
const CLIENT_ERROR_MAX_LABEL_LENGTH = 32;

/** Defensive upper bound on `l` / `c` — no real source file/column is this
 *  large; anything past it is treated as "not a usable number". */
const CLIENT_ERROR_MAX_LINE_NUMBER = 10_000_000;

/**
 * The CLOSED set of `k` (kind) values this endpoint accepts — matches the
 * two `window.addEventListener()` sites in `bootErrorMonitor()` exactly
 * ('error' | 'rejection'). Anything else is rejected outright.
 */
const CLIENT_ERROR_ALLOWED_KINDS = ['error', 'rejection'];

/**
 * Strip secret-shaped substrings from a string before it is stored
 * anywhere (a log row, an error_log line, a response).
 *
 * ELI5: goes looking for anything that LOOKS like a password or an API
 * key hiding inside an error message or stack trace, and blanks it out.
 *
 * DETAIL: mirrors the two patterns the CLIENT already applies
 * (`js/modules/error-monitor.js::_scrub()`) byte-for-byte, so "what gets
 * redacted" can never quietly drift between the two layers:
 *   - `Bearer\s+\S+` — an `Authorization: Bearer <token>` value that ended
 *     up embedded in an error message (e.g. a fetch-wrapper that logs its
 *     own request headers on failure).
 *   - a 64-hex-char run — the shape of a sha256 hex digest, which is also
 *     the shape of this app's own session/API-key material in several
 *     places (see `activityLogScrubQuery()`'s docblock in
 *     `activity_log.php` for the same threat model).
 * Pure (no I/O), so it's directly unit-testable — see
 * `tests/php/test-client-error-report.php`.
 *
 * @param string $s Raw (untrusted) string.
 * @return string   Same string with secret-shaped substrings replaced.
 */
function clientErrorScrub(string $s): string
{
    if ($s === '') {
        return '';
    }
    $s = preg_replace('/Bearer\s+\S+/i', 'Bearer [redacted]', $s);
    if ($s === null) $s = ''; /* preg_replace() returns null only on a regex engine error */
    $s = preg_replace('/[0-9a-f]{64}/i', '[redacted]', $s);
    if ($s === null) $s = '';
    return $s;
}

/**
 * Validate + normalise one raw client-error beacon body into a small,
 * capped, scrubbed shape — or `null` if it's too malformed to be useful.
 *
 * ELI5: "is this a real-looking error report, and if so, trim it down to
 * something safe and small enough to store?"
 *
 * DETAIL: unlike `analyticsIngestValidateEvent()`'s all-or-nothing per
 * event, most fields here are OPTIONAL and independently normalised —
 * only `k` (must be in the closed allow-list) and `m` (must be a
 * non-empty string) can fail the whole report; everything else degrades
 * to a safe default (empty string / null / capped) rather than rejecting
 * an otherwise-useful report over one odd field. The caller (api.php)
 * treats a `null` return as "nothing to log" but still answers the
 * beacon with `{ok:true}` — see that file's case body for why.
 *
 * @param array<mixed> $raw Decoded JSON body (already confirmed to be an array).
 * @return array{m:string,s:string,l:?int,c:?int,r:string,v:string,ch:string,k:string,st:string[]}|null
 */
function clientErrorValidate(array $raw): ?array
{
    /* k (kind) — required, closed allow-list. This is the one field that,
       if wrong, means the payload isn't shaped like this endpoint's
       contract at all (a stray/malicious POST), so reject outright. */
    $kind = is_string($raw['k'] ?? null) ? $raw['k'] : '';
    if (!in_array($kind, CLIENT_ERROR_ALLOWED_KINDS, true)) {
        return null;
    }

    /* m (message) — required, non-empty. An error report with no message
       carries no diagnostic value, so treat it the same as malformed. */
    $message = is_string($raw['m'] ?? null) ? trim($raw['m']) : '';
    if ($message === '') {
        return null;
    }
    $message = mb_substr(clientErrorScrub($message), 0, CLIENT_ERROR_MAX_MESSAGE_LENGTH);

    return [
        'm'  => $message,
        's'  => _clientErrorCleanPath(is_string($raw['s'] ?? null) ? $raw['s'] : ''),
        'l'  => _clientErrorCleanLineNumber($raw['l'] ?? null),
        'c'  => _clientErrorCleanLineNumber($raw['c'] ?? null),
        'r'  => _clientErrorCleanPath(is_string($raw['r'] ?? null) ? $raw['r'] : ''),
        'v'  => is_string($raw['v'] ?? null) ? mb_substr(trim($raw['v']), 0, CLIENT_ERROR_MAX_LABEL_LENGTH) : '',
        'ch' => is_string($raw['ch'] ?? null) ? mb_substr(trim($raw['ch']), 0, CLIENT_ERROR_MAX_LABEL_LENGTH) : '',
        'k'  => $kind,
        'st' => _clientErrorCleanFrames($raw['st'] ?? null),
    ];
}

/**
 * Clean a path-shaped field (`s` / `r`): strip any query string or
 * fragment that slipped through (defence in depth — the client never
 * sends `location.search`/`location.hash`, but the server does not
 * trust that promise from a caller that could be bypassing the browser
 * entirely), scrub, then cap the length.
 *
 * @param string $s
 * @return string
 */
function _clientErrorCleanPath(string $s): string
{
    $s = trim($s);
    if ($s === '') {
        return '';
    }
    $qPos = strpos($s, '?');
    if ($qPos !== false) $s = substr($s, 0, $qPos);
    $hPos = strpos($s, '#');
    if ($hPos !== false) $s = substr($s, 0, $hPos);
    return mb_substr(clientErrorScrub($s), 0, CLIENT_ERROR_MAX_PATH_LENGTH);
}

/**
 * Clean a line/column-number field: accept an int, a float, or a numeric
 * string; clamp to a plausible non-negative range; anything else (incl.
 * a non-numeric string, a bool, an array) degrades to `null` rather than
 * rejecting the whole report over one cosmetic field.
 *
 * @param mixed $raw
 * @return int|null
 */
function _clientErrorCleanLineNumber(mixed $raw): ?int
{
    if (!is_int($raw) && !is_float($raw) && !(is_string($raw) && is_numeric($raw))) {
        return null;
    }
    $n = (int)$raw;
    if ($n < 0 || $n > CLIENT_ERROR_MAX_LINE_NUMBER) {
        return null;
    }
    return $n;
}

/**
 * Clean the `st` (stack frames) field: must be an array; each entry must
 * be a string; keep at most `CLIENT_ERROR_MAX_FRAMES`, each scrubbed and
 * length-capped. A malformed `st` (not an array, or an array of
 * non-strings) degrades to an empty list rather than rejecting the
 * report — the message alone is still useful.
 *
 * @param mixed $raw
 * @return string[]
 */
function _clientErrorCleanFrames(mixed $raw): array
{
    if (!is_array($raw)) {
        return [];
    }
    $frames = [];
    foreach ($raw as $frame) {
        if (count($frames) >= CLIENT_ERROR_MAX_FRAMES) break;
        if (!is_string($frame) || trim($frame) === '') continue;
        $frames[] = mb_substr(clientErrorScrub($frame), 0, CLIENT_ERROR_MAX_FRAME_LENGTH);
    }
    return $frames;
}

/**
 * Derive the dedupe/grouping fingerprint for a validated payload —
 * 16 hex characters (the first 16 of a sha256 hex digest).
 *
 * ELI5: turns "this exact bug" into one short, stable code, so the
 * server can recognise "we already logged this one recently" without
 * comparing whole messages against each other.
 *
 * DETAIL: hashes `{env}|{m}|{s}|{l}` — the SAME three payload fields
 * (`m`/`s`/`l`) the CLIENT already fingerprints for its own
 * sessionStorage throttle (js/modules/error-monitor.js), so a curator
 * correlating a client-side "already reported" skip with a server-side
 * dedupe skip is looking at conceptually the same key. `$env` is
 * REQUIRED and prefixed first — three environments (alpha/beta/
 * production) share one `tblActivityLog` (CLAUDE.md rule #1207), and the
 * exact same bug reported from two different environments should NOT
 * collapse into one fingerprint (they're different deployments, possibly
 * different code). 16 hex chars (64 bits) is short enough to fit
 * `tblActivityLog.EntityId` (VARCHAR(50)) with room to spare, and
 * collision-improbable for this table's realistic row volume.
 *
 * @param array{m:string,s:string,l:?int} $payload A `clientErrorValidate()` result (or any array with at least m/s/l).
 * @param string $env Deploy environment — pass `ihymns_environment()`'s
 *                     result (this file stays DB/superglobal-free so it
 *                     is directly unit-testable without stubbing $_SERVER).
 * @return string 16-char lowercase hex.
 */
function clientErrorFingerprint(array $payload, string $env): string
{
    $key = $env . '|' . ($payload['m'] ?? '') . '|' . ($payload['s'] ?? '') . '|' . ($payload['l'] ?? '');
    return substr(hash('sha256', $key), 0, 16);
}
