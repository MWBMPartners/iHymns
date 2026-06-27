<?php

declare(strict_types=1);

/**
 * iHymns — Signed-URL helper for sealing /data/audio MP3s (#1358).
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * WHAT THIS IS:
 * Today the offline audio manifest (bulk_audio) and the legacy SW media path
 * emit the LITERAL static path `/data/audio/<SongId>.mp3`, which Apache serves
 * directly — so a copyrighted song's audio is fetchable by anyone who guesses
 * the URL, even when content gating is ON. This module mints short-lived
 * HMAC-signed URLs (`/audio/<SongId>.mp3?exp=…&sig=…`) so the gated streaming
 * route `audio-media.php` can verify a request was issued by the server to an
 * authorised reader within the TTL window.
 *
 * THREE LOCKED RULES (mirrors content_gating.php):
 *
 *   (A) TRIPLE-DORMANT MASTER SWITCH. Signing is OFF — every public function is
 *       a no-op — unless ALL THREE are true:
 *         1. content_gating_enabled === '1'  (the global gate)
 *         2. audio_signing_enabled  === '1'  (this feature's own flag)
 *         3. AUDIO_SIGNING_KEY is defined    (the operator-provisioned secret)
 *       audioSignedUrlFor() returns null when OFF, and EVERY caller falls back
 *       to the legacy literal `/data/audio/<id>.mp3` URL — so shipping this
 *       changes NOTHING on any live env until an operator opts in per env.
 *
 *   (B) NEVER THROWS. audioSigningEnabled() only reads getAppSetting (itself
 *       try/catch'd, DB-safe) + a defined() check; it cannot raise. A caller can
 *       call it on the hot read path without a guard.
 *
 *   (C) CONSTANT-TIME VERIFY. audioSignatureValid() compares with hash_equals so
 *       the signature check is not vulnerable to a timing oracle, and returns
 *       false (not "valid") when the key is undefined — fail-closed on verify,
 *       fail-open on mint, both the safe direction.
 *
 * THE KEY:
 * AUDIO_SIGNING_KEY is provisioned by the OPERATOR in the NON-docroot
 * `.auth/` directory (same place as the DB credentials), NEVER committed. It is
 * a long random secret; the HMAC keyed on it means a signed URL cannot be
 * forged without it. Absent → audioSigningEnabled() is false → dormant.
 *
 * Direct HTTP access is denied so this can't be loaded as an endpoint.
 *
 * @see includes/content_gating.php  contentGatingEnabled() — rule (A) gate 1
 * @see audio-media.php              the gated streaming route that verifies
 * @link https://www.php.net/manual/en/function.hash-hmac.php
 * @link https://www.php.net/manual/en/function.hash-equals.php
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'maintenance.php';        /* getAppSetting() */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'content_gating.php';     /* contentGatingEnabled() */

/* The operator-provisioned signing secret lives in the NON-docroot .auth/ directory
   (the same root db_mysql.php reads db_credentials.php from), NEVER committed. Load it
   here if present so AUDIO_SIGNING_KEY is defined for BOTH the mint (api.php bulk_audio)
   and the verify (audio-media.php) sides — every signing entry point require_once's THIS
   file, so one load site covers them all. ABSENT → the constant stays undefined →
   audioSigningEnabled() is false → fully dormant (the off-path no-op is preserved). */
$audioSigningKeyFile = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'audio_signing_key.php';
if (is_file($audioSigningKeyFile)) {
    require_once $audioSigningKeyFile;
}
unset($audioSigningKeyFile);

/** SongId shape — `<letters/digits>-<digits>` (rule #1332). The `/D` modifier makes `$`
 *  match only the true end of string, so a trailing newline ("CP-1\n") can't slip past.
 *  Used by both the mint and the route so a non-conforming id never enters a path/HMAC. */
const AUDIO_SIGNING_SONGID_RE = '/^[A-Za-z0-9]+-\d+$/D';

/**
 * Is audio-URL signing switched ON for this environment?
 *
 * The master gate for everything here. TRIPLE-dormant (rule A): needs the global
 * content-gating flag, this feature's own flag, AND the operator-provisioned key.
 * Never throws (rule B) — getAppSetting is DB-safe and defined() can't raise.
 *
 * @return bool true only when gating is on, signing is on, and the key exists.
 */
function audioSigningEnabled(): bool
{
    /* ELI5: three switches all have to be ON before we sign anything.
       WHY: rule (A) — dormant unless every layer (global gate + feature flag +
       secret key) is in place, so the default is byte-identical to today. */
    return contentGatingEnabled()
        && function_exists('getAppSetting')
        && getAppSetting('audio_signing_enabled', '0') === '1'
        && defined('AUDIO_SIGNING_KEY');
}

/**
 * Mint a short-lived signed URL for a song's audio, or null when signing is off.
 *
 * Returning null (when !audioSigningEnabled()) is the dormancy contract: every
 * caller treats null as "use the legacy literal `/data/audio/<id>.mp3`", so the
 * off-path is unchanged. When on, the URL carries an `exp` expiry + an HMAC `sig`
 * over `<songId>|<exp>` so audio-media.php can verify it within the TTL.
 *
 * @param string $songId The song id (validated against the SongId shape).
 * @param int    $ttl    Seconds the URL stays valid (default 1h).
 * @return string|null   `/audio/<id>.mp3?exp=…&sig=…` when on; null when off / invalid id.
 */
function audioSignedUrlFor(string $songId, int $ttl = 3600): ?string
{
    /* ELI5: off → return nothing so the caller keeps the old plain URL.
       WHY: rule (A) dormancy — null is the "do the legacy thing" sentinel. */
    if (!audioSigningEnabled()) {
        return null;
    }
    /* A malformed id never enters a URL or an HMAC. */
    if (preg_match(AUDIO_SIGNING_SONGID_RE, $songId) !== 1) {
        return null;
    }
    $exp = time() + max(1, $ttl);
    /* HMAC over "<songId>|<exp>" keyed on the operator secret. The pipe is a
       delimiter so ("AB", "1-2") and ("AB-1", "2") can't collide. */
    $sig = hash_hmac('sha256', $songId . '|' . $exp, AUDIO_SIGNING_KEY);
    return '/audio/' . rawurlencode($songId) . '.mp3?exp=' . $exp . '&sig=' . $sig;
}

/**
 * Verify a signed-audio request: the signature matches AND it hasn't expired.
 *
 * Used by audio-media.php on the enabled path. Constant-time (rule C) via
 * hash_equals; returns false when the key is undefined (fail-closed on verify).
 * The expiry is checked first (cheap) but BOTH conditions must hold.
 *
 * @param string $songId The song id from the route.
 * @param int    $exp    The `exp` query param (unix seconds).
 * @param string $sig    The `sig` query param (hex HMAC).
 * @return bool true only when not expired, key defined, and HMAC matches.
 */
function audioSignatureValid(string $songId, int $exp, string $sig): bool
{
    /* ELI5: the link is valid only if it's not stale AND the fingerprint matches.
       WHY: rule (C) — hash_equals is constant-time so an attacker can't byte-guess
       the signature via response timing; an undefined key short-circuits to false. */
    if ($exp < time()) {
        return false;
    }
    if (!defined('AUDIO_SIGNING_KEY')) {
        return false;
    }
    $expected = hash_hmac('sha256', $songId . '|' . $exp, AUDIO_SIGNING_KEY);
    return hash_equals($expected, $sig);
}
