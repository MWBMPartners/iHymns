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
 *
 * CONDITIONAL GET (#1452):
 * ELI5 — every media row already carries a fingerprint (Sha256) and a
 * "last touched" timestamp (UpdatedAt); we hand those back as ETag /
 * Last-Modified so a client that already has the bytes can ask "is this
 * still current?" instead of re-downloading.
 * DETAILED — #1450 (native offline media cache) shipped a client-side
 * sizeBytes+TTL heuristic specifically because this route sent no
 * validators, which misses the narrow case of a same-size replacement
 * file. `tblSongMedia.Sha256` is a real content hash computed at upload
 * time (SongMediaStorage::store(), every INSERT site) — a stronger,
 * cheaper-to-check validator than re-hashing the body per request, so
 * it's used as-is for a strong ETag; `UpdatedAt` (ON UPDATE
 * CURRENT_TIMESTAMP) backs Last-Modified as a fallback for clients that
 * only implement date-based revalidation. Mirrors the ETag/304 pattern
 * already used for cacheable SPA fragments in api.php (queue the
 * validator headers unconditionally, then short-circuit to 304 with no
 * body when the client's cached copy still matches) — see api.php's
 * `$_shouldCachePage` block. The conditional check runs AFTER
 * checkContentAccess() below, never before: a 304 short-circuit must
 * never leak "yes, this exists and is unchanged" to a caller who isn't
 * even allowed to see it turn into a 403.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_mysql.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'content_access.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'content_gating.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'SongMediaStorage.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'activity_log.php';
/* Mirror every uncaught \Throwable + PHP fatal into tblActivityLog
   so a broken song-media stream (storage backend down, missing
   row, range-header parse fail) surfaces in /manage/activity-log. */
installGlobalActivityLogHandlers('song_media');

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

/* #1860 Phase 4 — dual-addressing pre-step: an IL internal id ('ILD…')
   resolves to the tblSongMedia row's numeric Id BEFORE the (int) cast
   below, so everything downstream (incl. contentGatingMediaAllowed(),
   rule #28) runs on the SAME resolved numeric row regardless of which
   address form the caller used — the gate sees the same row either way.
   A miss (not an IL id, the column doesn't exist yet, or no row carries
   it) falls through to `$id = (int)$rawId`, byte-identical to today's
   behaviour (a non-numeric id casts to 0, which the guard below rejects
   exactly as before). try/catch-swallowed + column-probe-gated so this
   endpoint can never white-screen on an un-migrated install (the #1228
   lesson). */
$rawId = (string)($_GET['id'] ?? '');
$id    = null;
try {
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'ilyrics_id.php';
    $ilParsed = ilidParse($rawId);
    if ($ilParsed !== null && $ilParsed['entityType'] === 'document') {
        $ilColProbe = getDbMysqli()->query(
            "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblSongMedia' AND COLUMN_NAME = 'IlId' LIMIT 1"
        );
        $ilColExists = $ilColProbe && $ilColProbe->fetch_row() !== null;
        if ($ilColProbe) { $ilColProbe->free(); }
        if ($ilColExists) {
            $ilStmt = getDbMysqli()->prepare('SELECT Id FROM tblSongMedia WHERE IlId = ? LIMIT 1');
            $ilStmt->bind_param('s', $ilParsed['canonical']);
            $ilStmt->execute();
            $ilRow = $ilStmt->get_result()->fetch_assoc();
            $ilStmt->close();
            if ($ilRow !== null) {
                $id = (int)$ilRow['Id'];
            }
        }
    }
} catch (\Throwable $_ilE) {
    // dormant-by-design — fall through to the numeric cast unchanged
}
if ($id === null) {
    $id = (int)$rawId;
}
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
                SizeBytes, Content, StoragePath, Sha256, UpdatedAt
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

/* --- Access gates (#853 entity gate + #1388 tier gate) ---------------------

   TWO INDEPENDENT GATES, both must pass:

     1. ENTITY  — does a tblContentRestrictions rule cover this song?
     2. TIER    — does the caller's access tier permit this media KIND?

   They answer different questions and neither implies the other. A song with
   no restriction row still holds premium audio; a `public`-tier visitor still
   has to clear the entity gate.

   Service-Mode presence (#1335 / rule #26): a congregant following a live
   service carries an opaque presence token in a same-origin cookie. It feeds
   BOTH gates — checkContentAccess() injects the 'ccli' entitlement from it, and
   the tier gate ORs it into the audio decision. Before #1388 this endpoint
   passed the 4-arg form and so DENIED a present congregant the very media that
   song.php and contentGatingApply() deliberately grant them. Shape-validated
   here (43 url-safe base64 chars) exactly as api.php:857/974 do, so a junk
   cookie never reaches a query. */
$userId   = _songMedia_resolveUserId();
$presence = null;
if (isset($_COOKIE['ihymns_sf_presence_token'])
    && preg_match('/^[A-Za-z0-9_\-]{43}$/', (string)$_COOKIE['ihymns_sf_presence_token'])) {
    $presence = (string)$_COOKIE['ihymns_sf_presence_token'];
}

/* Gate 1 — entity. Returns ['allowed' => bool, 'reason' => string]; deny → 403
   with a brief plaintext reason so a curator inspecting Network can see why. */
$gate = checkContentAccess('song', (string)$row['SongId'], $userId, 'PWA', $presence);
if (!$gate['allowed']) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Access restricted: ' . ($gate['reason'] ?: 'gated content.');
    exit;
}

/* Gate 2 — tier cap for this media kind. A no-op returning true whenever
   content_gating_enabled='0' (rule #28A), so this is byte-identical to the
   pre-#1388 endpoint until an operator flips the master switch. The reason
   string is deliberately generic — it must not disclose which tier would be
   sufficient, since that is an unauthenticated response. */
if (!contentGatingMediaAllowed((string)$row['Kind'], $userId, $presence)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Access restricted: gated content.';
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

/* ---------- Conditional GET (#1452) -------------------------------- */

/* ELI5: turn the row's fingerprint + last-touched time into the two
   standard "have you already got this?" headers.
   DETAILED: Sha256 is a real content hash computed once at upload time
   (SongMediaStorage::store()), so it's used verbatim as a STRONG ETag —
   no re-hashing the blob/file per request. UpdatedAt backs Last-Modified
   for clients that only implement date-based revalidation. Both are
   queued unconditionally (same pattern as api.php's cacheable-page ETag
   block) so they're present on the 200/206 response too, and on the 304
   short-circuit below. */
$etag = '"' . (string)$row['Sha256'] . '"';
$lastModifiedTs   = strtotime((string)$row['UpdatedAt'] . ' UTC') ?: null;
$lastModifiedHttp = ($lastModifiedTs !== null) ? gmdate('D, d M Y H:i:s', $lastModifiedTs) . ' GMT' : null;

header('ETag: ' . $etag);
if ($lastModifiedHttp !== null) {
    header('Last-Modified: ' . $lastModifiedHttp);
}
header('Cache-Control: private, max-age=86400');

/* Per RFC 7232 §6, a GET/HEAD with If-None-Match present ignores
   If-Modified-Since entirely — only fall back to the date check when
   the client sent no ETag validator at all. */
$ifNoneMatch = trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
$notModified = false;
if ($ifNoneMatch !== '') {
    $notModified = ($ifNoneMatch === '*') || ($ifNoneMatch === $etag);
} elseif ($lastModifiedTs !== null) {
    $ifModifiedSince = trim((string)($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? ''));
    if ($ifModifiedSince !== '') {
        $imsTs = strtotime($ifModifiedSince);
        $notModified = ($imsTs !== false && $lastModifiedTs <= $imsTs);
    }
}

/* checkContentAccess() above has ALREADY run — a 304 here only ever
   confirms "unchanged" to a caller who was just proven allowed to see
   the 200 body in the first place. Never move this check earlier. */
if ($notModified) {
    http_response_code(304);
    exit;
}

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

/* Cache-Control / ETag / Last-Modified were already queued in the
   Conditional GET block above (#1452) — set once, applies to this
   200/206 response the same as it would have to a 304. */
header('Content-Type: ' . $mime);
header('Accept-Ranges: bytes');
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
