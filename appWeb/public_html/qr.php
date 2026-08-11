<?php

declare(strict_types=1);

/**
 * iHymns — QR image endpoint (owner directive 2026-08-05: QR via CueRCode)
 *
 * PURPOSE:
 * The ONE same-origin QR image source for the whole app. Clients embed a plain
 * `<img src="/qr.php?data=…">`; this endpoint calls the CueRCode API
 * server-side (so the secret X-API-Key never reaches a browser — see
 * includes/cuercode_client.php) and streams back the QR picture bytes.
 *
 * This replaces the old client-side vendored `qrcode-generator` approach on
 * BOTH QR surfaces (the print-template QR block #1767 R, and the Service
 * Projection join QR, rule #26). Mirrors og-image.php's standalone
 * image-streaming shape.
 *
 * MODES:
 *   /qr.php?data=<url-or-text>          — encode this text (required)
 *   &size=<100..1000>                   — pixel size (default 512)
 *   &format=<svg|png>                   — output format (default svg)
 *   &ecc=<L|M|Q|H>                      — error-correction level (default M)
 *
 * OUTPUT:
 *   200 + the QR bytes (Content-Type from CueRCode; Cache-Control immutable —
 *   a QR for a fixed payload never changes).
 *   503 (no body) when CueRCode isn't configured/reachable — the caller's
 *   `<img>` then fails and the always-present URL/code text is the fallback.
 *
 * @see includes/cuercode_client.php
 * @see .claude/qr-cuercode-integration-plan.md
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'config.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_mysql.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'maintenance.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'read_rate_limit.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'cuercode_client.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

/* Small helper — end the request with a status and no body (the client
   `<img>` treats any non-image response as a load failure → text fallback). */
function _qrFail(int $status): never
{
    http_response_code($status);
    exit;
}

/* Rate-limit the heavy path (fail-open, per-token/IP — a NAT-shared
   congregation isn't capped as one IP). Generous: a printed booklet or a
   projector may request several QR images in a burst. */
try {
    enforceReadRateLimitKeyed('qr', 240);
} catch (\Throwable $e) {
    /* fail-open — never let the limiter itself break the endpoint */
}

/* ---- Validate + clamp inputs (defence in depth; the client also bounds) ---- */
$data = isset($_GET['data']) ? trim((string)$_GET['data']) : '';
if ($data === '' || strlen($data) > CUERCODE_MAX_PAYLOAD_LEN) {
    _qrFail(400);
}
$size   = isset($_GET['size']) ? (int)$_GET['size'] : 512;
$size   = max(CUERCODE_MIN_SIZE, min(CUERCODE_MAX_SIZE, $size));
$format = in_array(($_GET['format'] ?? 'svg'), CUERCODE_FORMATS, true) ? (string)$_GET['format'] : 'svg';
$ecc    = in_array(($_GET['ecc'] ?? 'M'), CUERCODE_ECC_LEVELS, true) ? (string)$_GET['ecc'] : 'M';

/* Not configured yet (no API key) → 503, so the caller degrades to text.
   Kept explicit so a dormant install is a clean, cheap answer, not a curl
   attempt. */
if (!cuercodeConfigured()) {
    _qrFail(503);
}

$qr = cuercodeGenerate($data, ['format' => $format, 'size' => $size, 'ecc' => $ecc]);
if ($qr === null) {
    _qrFail(503);
}

/* Success — stream the bytes. A QR for a fixed (data,size,format,ecc) is
   immutable, so cache hard: browsers/proxies fetch it once. */
header('Content-Type: ' . $qr['mime']);
header('Cache-Control: public, max-age=31536000, immutable');
header('Content-Length: ' . strlen($qr['bytes']));
echo $qr['bytes'];
