<?php

declare(strict_types=1);

/**
 * iHymns — Gated Audio Streaming Route for legacy /data/audio MP3s (#1358).
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * Routed via .htaccess:  /audio/<SongId>.mp3  →  audio-media.php?id=<SongId>
 *
 * PURPOSE:
 * The legacy accompaniment audio lives as static files at
 * `data/audio/<SongId>.mp3`, served directly by Apache. That static serve
 * cannot be access-controlled — anyone who guesses the URL gets the file even
 * when content gating is ON. This route is the gated front door for that same
 * file: it streams the bytes through PHP so a copyrighted song's audio can be
 * protected behind a signed URL + the song's access rules.
 *
 * DORMANCY (the #1 requirement):
 * When audioSigningEnabled() is FALSE (the default on every live env — gating
 * off, or the signing flag off, or no key) this route serves the file with the
 * SAME 200/206 Range semantics Apache's static handler uses, so it is
 * byte-equivalent to today's static serve. Nothing emits `/audio/<id>.mp3` URLs
 * until signing is on (bulk_audio keeps emitting the legacy `/data/audio/…`
 * literal), so in practice this route is not even hit until rollout — and even
 * if hit directly, it just streams the file. ENABLING signing flips it to the
 * verify-then-stream branch.
 *
 * SECURITY:
 *   1. SongId shape is validated FIRST (`^[A-Za-z0-9]+-\d+$`, rule #1332) — a
 *      non-conforming id 400s before any filesystem touch.
 *   2. Path traversal is hard-stopped: realpath() the resolved file and assert
 *      it lives under realpath(data/audio) + a separator. `../` / symlink
 *      escapes 404.
 *   3. On the enabled branch the signature is verified with hash_equals
 *      (constant-time) AND the song's access rules are re-checked server-side.
 *
 * RANGE REQUESTS:
 * Honoured identically to song-media.php so the <audio> seek bar works (206
 * Partial Content; fopen/fseek so a large MP3 never lives wholly in memory).
 *
 * @see includes/audio_signing.php  audioSigningEnabled / audioSignatureValid
 * @see song-media.php              the tblSongMedia streaming model this mirrors
 * @link https://developer.mozilla.org/en-US/docs/Web/HTTP/Range_requests
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_mysql.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'content_access.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'audio_signing.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'read_rate_limit.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'activity_log.php';
/* Per-channel search-engine visibility (#2024/#2025) — same reasoning as
   song-media.php's sibling gated-media stream. */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'search_visibility.php';
searchVisibilityEmitNoindexHeader();
/* Mirror any uncaught \Throwable / fatal into tblActivityLog so a broken audio
   stream (missing file, range-parse fail) surfaces in /manage/activity-log —
   same discipline as song-media.php. */
installGlobalActivityLogHandlers('audio_media');

/**
 * Best-effort Bearer-token → user id (anonymous → null). A verbatim copy of
 * song-media.php's slim resolver: we only need the Id for the gating call, not
 * the full $user payload. Kept local rather than shared because both routes are
 * thin streaming endpoints and the copy is 20 lines (the shared-module rule
 * applies to UI/business logic, not a leaf auth probe that must not pull in the
 * heavier api.php getAuthenticatedUser()).
 */
function _audioMedia_resolveUserId(): ?int
{
    $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\s+(.+)$/i', $hdr, $m)) {
        return null;
    }
    $token = trim($m[1]);
    if ($token === '') {
        return null;
    }
    try {
        $db     = getDbMysqli();
        $hashed = hash('sha256', $token);
        $stmt   = $db->prepare(
            'SELECT t.UserId FROM tblApiTokens t
               JOIN tblUsers u ON u.Id = t.UserId
              WHERE t.Token = ? AND t.ExpiresAt > UTC_TIMESTAMP()
                AND u.IsActive = 1
              LIMIT 1'
        );
        $stmt->bind_param('s', $hashed);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? (int)$row['UserId'] : null;
    } catch (\Throwable $_e) {
        return null;
    }
}

/* -------------------------------------------------------------------- */

/* (1) Validate the SongId shape BEFORE any filesystem touch (rule #1332). */
$id = isset($_GET['id']) ? (string)$_GET['id'] : '';
if (preg_match('/^[A-Za-z0-9]+-\d+$/D', $id) !== 1) {   /* /D: '$' = true end-of-string (no trailing-newline slip) */
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Bad request.';
    exit;
}

/* (2) Resolve the file under data/audio with a path-traversal hard stop. The
   id is already shape-validated, but realpath + the str_starts_with prefix
   assertion is the belt-and-braces guard: a resolved path that escapes the
   audio base (symlink / unexpected `..`) 404s rather than serving anything. */
$base = realpath(__DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'audio');
$path = realpath(__DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'audio' . DIRECTORY_SEPARATOR . $id . '.mp3');
if ($base === false
    || $path === false
    || !str_starts_with($path, $base . DIRECTORY_SEPARATOR)
    || !is_file($path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Not found.';
    exit;
}

/* (3) When signing is ENABLED, require a valid signature AND a server-side
   access check before streaming. When it's OFF (the default), skip straight to
   the stream — byte-equivalent to Apache's static serve (dormancy).
   NOTE (rollout): on the OFF branch this route enforces NO entity/tier check — it is
   exposure-equivalent to the still-open static /data/audio/<id>.mp3 path. ENTITY
   restrictions only bind here once signing is enabled; the .htaccess SEAL (shipped
   commented) closes the raw /data/audio path at the SAME rollout step, so both paths
   gate together. Do NOT treat /audio/ as a security boundary until signing is on AND the
   seal is applied on that env. */
if (audioSigningEnabled()) {
    $exp = isset($_GET['exp']) ? (int)$_GET['exp'] : 0;
    $sig = isset($_GET['sig']) ? (string)$_GET['sig'] : '';

    /* Signature first (cheap, constant-time, no DB). */
    if (!audioSignatureValid($id, $exp, $sig)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Access restricted: invalid or expired link.';
        exit;
    }

    /* Then the song's access rules — a present congregant rides the org CCLI
       licence via the same presence token cookie song.php reads. Anonymous →
       null user → anonymous entity gate. checkContentAccess never throws (it is
       fail-open internally); a deny → 403. */
    $presenceToken = null;
    if (isset($_COOKIE['ihymns_sf_presence_token'])
        && preg_match('/^[A-Za-z0-9_\-]{43}$/D', (string)$_COOKIE['ihymns_sf_presence_token'])) {
        $presenceToken = (string)$_COOKIE['ihymns_sf_presence_token'];
    }
    $userId = _audioMedia_resolveUserId();
    $gate   = checkContentAccess('song', $id, $userId, 'PWA', $presenceToken);
    if (empty($gate['allowed'])) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Access restricted: ' . ((string)($gate['reason'] ?? '') ?: 'gated content.');
        exit;
    }
}

/* ---------- Conditional GET (#1452-style, #1962) --------------------
   ELI5: this legacy MP3 route never told the browser "you already have
   this file — no need to re-download it", so the offline Service Worker's
   background revalidation (swRevalidateMediaEntry(), service-worker.js.php)
   had nothing to conditionally ask about and had to treat every check as
   "fetch the whole file again" — quietly violating CLAUDE.md rule #6
   invariant (B): downloaded content should refresh ONLY when the backend
   content itself actually changed.

   DETAILED: song-media.php (the tblSongMedia-backed sibling route) has
   carried a strong ETag off `Sha256` — a REAL content hash computed at
   upload time — since #1452. This route serves a bare static file with no
   content-hash column to read at all, so the cheapest HONEST validator
   available is the file's own size + modification time, the same
   inode-less shape Apache's own mod_headers FileETag directive falls back
   to. This is intentionally a WEAKER validator than song-media.php's
   Sha256 (a same-size-and-mtime-but-different-bytes replacement would go
   undetected) — acceptable here because this route is the LEGACY static
   file family that #1358 documents as being phased out in favour of
   tblSongMedia, not a place to invest in a new content-hashing pipeline.

   A `304` carries NO body (RFC 7232 §4.1), so it MUST be sent before any
   Content-Length/Range/body work below. And — same rule as song-media.php
   — this block runs only AFTER every access gate above: confirming
   "unchanged" to a caller who was never allowed to see the 200 body in the
   first place would leak the file's mere existence/freshness to them.
   Per RFC 7232 §6, If-None-Match takes priority over If-Modified-Since
   when a client sends both — mirrors song-media.php's own conditional
   block exactly, so the two media routes behave identically to any client.
   https://developer.mozilla.org/en-US/docs/Web/HTTP/Conditional_requests
   https://developer.mozilla.org/en-US/docs/Web/HTTP/Status/304
   ---------------------------------------------------------------------- */
$condMtime = @filemtime($path);
$condSize  = @filesize($path);
$condEtag  = '"' . $condSize . '-' . $condMtime . '"';
header('ETag: ' . $condEtag);
if ($condMtime !== false) {
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $condMtime) . ' GMT');
}

$condIfNoneMatch = trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
$condNotModified = false;
if ($condIfNoneMatch !== '') {
    $condNotModified = ($condIfNoneMatch === '*') || ($condIfNoneMatch === $condEtag);
} elseif ($condMtime !== false) {
    $condIfModifiedSince = trim((string)($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? ''));
    if ($condIfModifiedSince !== '') {
        $condImsTs = strtotime($condIfModifiedSince);
        $condNotModified = ($condImsTs !== false && $condMtime <= $condImsTs);
    }
}
if ($condNotModified) {
    http_response_code(304);
    exit;
}

/* #1906 — cap media BYTE volume BEFORE streaming (bandwidth exhaustion). Runs
   after the shape/traversal validation and the (signing-gated) access check, so
   a bad id / denied caller is never counted, and before any 200/206 header or
   byte so a 429 is a clean JSON reply, not a truncated MP3. Shares the 'media'
   scope + 240/min ceiling with song-media.php (an <audio> element issues several
   Range requests per track; generous for playback, tight for a scraper).
   Fail-open + per-token (rule #26). */
enforceReadRateLimitKeyed('media', 240);

/* -------------------------------------------------------------------- */
/* Stream the file with Range support (200 full / 206 partial), mirroring
   song-media.php's FS path so the <audio> seek bar behaves identically. */

$totalSize = (int)filesize($path);
$rangeHeader = $_SERVER['HTTP_RANGE'] ?? '';
$rangeStart  = 0;
$rangeEnd    = $totalSize > 0 ? $totalSize - 1 : 0;
$isPartial   = false;

if ($rangeHeader !== '' && $totalSize > 0
    && preg_match('/^bytes=(\d*)-(\d*)$/i', $rangeHeader, $rm)) {
    $rs = ($rm[1] === '') ? null : (int)$rm[1];
    $re = ($rm[2] === '') ? null : (int)$rm[2];

    if ($rs === null && $re !== null) {
        /* Suffix `bytes=-N` — last N bytes. */
        $rangeStart = max(0, $totalSize - $re);
        $rangeEnd   = $totalSize - 1;
    } elseif ($rs !== null && $re === null) {
        /* Open-ended `bytes=N-` — N to EOF. */
        $rangeStart = $rs;
        $rangeEnd   = $totalSize - 1;
    } elseif ($rs !== null && $re !== null) {
        $rangeStart = $rs;
        $rangeEnd   = min($re, $totalSize - 1);
    }

    if ($rangeStart > $rangeEnd || $rangeStart >= $totalSize) {
        http_response_code(416);
        header('Content-Range: bytes */' . $totalSize);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Requested range not satisfiable.';
        exit;
    }
    $isPartial = true;
}

$length = $rangeEnd - $rangeStart + 1;

/* ---------- Headers ---------------------------------------------- */

header('Content-Type: audio/mpeg');
header('Accept-Ranges: bytes');
/* private — gated content must not be shared by intermediaries even on the
   dormant path (it could become gated the moment signing is flipped on). */
header('Cache-Control: private, max-age=86400');
header('Content-Length: ' . $length);
header('X-Content-Type-Options: nosniff');

if ($isPartial) {
    http_response_code(206);
    header('Content-Range: bytes ' . $rangeStart . '-' . $rangeEnd . '/' . $totalSize);
}

/* HEAD → headers only (no body read — saves the FS open on every <audio> probe). */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
    exit;
}

/* ---------- Stream the body ---------------------------------------- */

/* Drop any output buffering so the first chunk reaches the client promptly. */
while (ob_get_level() > 0) {
    @ob_end_clean();
}

$fh = @fopen($path, 'rb');
if ($fh === false) {
    /* Headers already sent; log + exit cleanly so the player shows its own error. */
    error_log('[audio-media] fopen failed for ' . $path);
    exit;
}
if ($rangeStart > 0) {
    fseek($fh, $rangeStart);
}
$remaining = $length;
$chunkSize = 8192;
while ($remaining > 0 && !feof($fh)) {
    $read = ($remaining > $chunkSize) ? $chunkSize : $remaining;
    $buf  = fread($fh, $read);
    if ($buf === false) {
        break;
    }
    echo $buf;
    @flush();
    $remaining -= strlen($buf);
}
fclose($fh);
exit;
