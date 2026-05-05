<?php

declare(strict_types=1);

/**
 * iHymns — Song Media Streaming Endpoint (#853)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * Routed via .htaccess:  /song-media/<id>  →  song-media.php?id=<id>
 *
 * PURPOSE:
 * Single PHP route that serves the bytes for a tblSongMedia row,
 * regardless of which backend stores them (filesystem for audio,
 * MEDIUMBLOB for sheet-music / midi / musicxml).
 *
 * GATING:
 * Every request runs through checkContentAccess('song', <SongId>) so
 * a song-level restriction transitively gates its media. When a
 * Bearer token is present we resolve the user; otherwise the gate
 * runs anonymously. Note: HTML5 <audio> elements do NOT send custom
 * Authorization headers cross-request, so authenticated-only gating
 * for browser-played audio needs a query-param signed-URL mechanism
 * (deliberately out of scope for this PR — anonymous-public is the
 * supported default; everything else falls back to public-or-blocked).
 *
 * RANGE REQUESTS:
 * Audio players issue HTTP Range to drive the seek bar. We honour
 * `Range: bytes=…` with a 206 Partial Content response. For FS-backed
 * rows the SongMediaStorage::streamRange helper uses fopen/fseek so a
 * 50MB MP3 doesn't briefly live in PHP's memory in full.
 *
 * CACHING:
 * Cache-Control: private — gated content must not be shared by
 * intermediaries even when the response is 200 + cacheable. Browser
 * cache lifetime is one day (re-validation cost is negligible vs the
 * benefit of the audio element NOT re-fetching the whole file when
 * the user clicks elsewhere on the song page).
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_mysql.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'content_access.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'SongMediaStorage.php';

/**
 * Best-effort Bearer-token → user lookup. Returns null for anonymous.
 *
 * Slimmer than api.php's getAuthenticatedUser() — no avatar columns,
 * no sliding expiry write, no $user payload. We only need the Id for
 * the gating call.
 */
function _songMedia_resolveUserId(): ?int
{
    $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\s+(.+)$/i', $hdr, $m)) return null;
    $token = trim($m[1]);
    if ($token === '') return null;

    try {
        $db = getDbMysqli();
        $hashed = hash('sha256', $token);
        $stmt = $db->prepare(
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

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Bad request.';
    exit;
}

try {
    $db = getDbMysqli();
} catch (\Throwable $_e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Database unavailable.';
    exit;
}

/* Schema-probe so a pre-migration deploy returns 404 cleanly rather
   than an SQL-not-found error. Cached per request via static. */
$hasSchema = (function () use ($db): bool {
    static $cached = null;
    if ($cached !== null) return $cached;
    try {
        $r = $db->query(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblSongMedia' LIMIT 1"
        );
        $cached = ($r && $r->fetch_row() !== null);
        if ($r) $r->close();
    } catch (\Throwable $_e) {
        $cached = false;
    }
    return $cached;
})();
if (!$hasSchema) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Not found.';
    exit;
}

try {
    $stmt = $db->prepare(
        'SELECT Id, SongId, Kind, StorageBackend, FileName, MimeType,
                SizeBytes, Content, StoragePath
           FROM tblSongMedia WHERE Id = ? LIMIT 1'
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
} catch (\Throwable $e) {
    error_log('[song-media] fetch failed: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Internal error.';
    exit;
}

if (!$row) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Not found.';
    exit;
}

/* Gate via the parent song's access rules. checkContentAccess returns
   ['allowed' => bool, 'reason' => string]; deny → 403 with a brief
   plaintext reason so a curator inspecting Network can see why. */
$userId = _songMedia_resolveUserId();
$gate   = checkContentAccess('song', (string)$row['SongId'], $userId, 'PWA');
if (!$gate['allowed']) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Access restricted: ' . ($gate['reason'] ?: 'gated content.');
    exit;
}

$totalSize = (int)$row['SizeBytes'];
$mime      = (string)$row['MimeType'];
$kind      = (string)$row['Kind'];
$fileName  = (string)$row['FileName'];

/* Audio plays inline (the <audio> tag wants the bytes); everything
   else is offered as a download with the original filename. The
   filename is RFC 5987 encoded so non-ASCII characters survive
   round-trips through Content-Disposition. */
$disposition = ($kind === 'audio') ? 'inline' : 'attachment';
$cdAscii     = preg_replace('/[^\x20-\x7e]/', '_', $fileName) ?: 'file';
$cdUtf8      = rawurlencode($fileName);

/* ---------- Range parsing ----------------------------------------- */

$rangeHeader = $_SERVER['HTTP_RANGE'] ?? '';
$rangeStart  = 0;
$rangeEnd    = $totalSize - 1;
$isPartial   = false;

if ($rangeHeader !== '' && preg_match('/^bytes=(\d*)-(\d*)$/i', $rangeHeader, $rm)) {
    $rs = ($rm[1] === '') ? null : (int)$rm[1];
    $re = ($rm[2] === '') ? null : (int)$rm[2];

    if ($rs === null && $re !== null) {
        /* Suffix-form `bytes=-N` — return the LAST N bytes. */
        $rangeStart = max(0, $totalSize - $re);
        $rangeEnd   = $totalSize - 1;
    } elseif ($rs !== null && $re === null) {
        /* Open-ended `bytes=N-` — start at N, run to EOF. */
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

header('Content-Type: ' . $mime);
header('Accept-Ranges: bytes');
header('Cache-Control: private, max-age=86400');
header('Content-Length: ' . $length);
header(
    'Content-Disposition: ' . $disposition
    . '; filename="' . str_replace('"', '\"', $cdAscii) . '"'
    . "; filename*=UTF-8''" . $cdUtf8
);
header('X-Content-Type-Options: nosniff');

if ($isPartial) {
    http_response_code(206);
    header('Content-Range: bytes ' . $rangeStart . '-' . $rangeEnd . '/' . $totalSize);
}

/* HEAD requests get headers only (Apache discards the body but PHP
   needn't actually read any bytes for them — saves an unnecessary
   FS open / BLOB substr on every <audio> probe). */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
    exit;
}

/* ---------- Stream the body --------------------------------------- */

/* Flush any buffered output (PHP usually buffers up to 4KB) so the
   first chunk reaches the browser without delay. ob_clean only acts
   on the topmost buffer; calling it in a loop is paranoid but cheap. */
while (ob_get_level() > 0) {
    @ob_end_clean();
}

$ok = SongMediaStorage::streamRange(
    [
        'StorageBackend' => (string)$row['StorageBackend'],
        'StoragePath'    => (string)($row['StoragePath'] ?? ''),
        'Content'        => $row['Content'] ?? null,
    ],
    $rangeStart,
    $rangeEnd,
    static function (string $chunk): void {
        echo $chunk;
        @flush();
    }
);

if (!$ok) {
    /* Storage missing under the row — log + return a sad-trombone
       210 Gone so the player surfaces a "couldn't load" rather than
       silently looping. We've already sent the headers above, so the
       best we can do is exit cleanly. */
    error_log('[song-media] storage missing for media id=' . $id);
}
exit;
