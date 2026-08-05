<?php

declare(strict_types=1);

/**
 * iHymns — CueRCode QR-generation gateway client (owner directive 2026-08-05)
 *
 * ELI5: this file asks our separate CueRCode website (https://cuercode.net) to
 * draw a QR code for a given link, and hands back the picture bytes. iHymns
 * itself never draws QR codes any more, and the browser NEVER sees CueRCode's
 * secret key — this server does the talking.
 *
 * DETAIL:
 * -------
 * Server-proxied (never browser-facing — the `X-API-Key` is a secret; a browser
 * calling CueRCode directly would leak it), fail-SOFT QR client. One shared
 * module, side-effect-free to require: including this file opens no connection
 * and makes no HTTP call — it only declares functions/constants (mirrors the
 * discipline of `includes/intapps_client.php`, the outbound-service precedent
 * this is modelled on — rule #22).
 *
 * DORMANT UNTIL KEYED: `cuercodeGenerate()` returns null the instant the API
 * key isn't configured, the base URL is refused (SSRF guard), curl is missing,
 * the response is oversized/malformed, or CueRCode answers non-2xx. The one
 * consumer (`qr.php`) turns a null into an HTTP 503, and every QR surface then
 * degrades to the always-present URL/code text — never an error, never a blank
 * that pretends to be a QR.
 *
 * CueRCode API contract (its `api/v1/openapi.json`):
 *   POST {base}/api/v1/generate
 *     headers: X-API-Key: cuercode_<40 hex>, Content-Type/Accept: application/json
 *     body:    {"type":"url","input":{"url":"…"},"customization":{format,size,ecc,…}}
 *     200:     {"success":true,"data":{"image":"data:<mime>;base64,…","mime_type","format",…}}
 *     err:     {"success":false,"message","error_code"} with 400/401/422/429
 *
 * @see .claude/qr-cuercode-integration-plan.md (full design)
 * @see includes/intapps_client.php (the mirrored outbound-service precedent)
 * @link https://github.com/MWBMPartners/CueRCode
 * @link https://www.php.net/manual/en/book.curl.php
 */

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'maintenance.php';    /* getAppSetting() */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'secret_crypto.php';  /* transparent decrypt of cuercode_api_key inside getAppSetting() */

/* -------------------------------------------------------------------------
 * SETTINGS KEYS — defined ONCE here so /manage/configuration.php and any
 * status surface never re-type a literal and drift (rule #35).
 * ---------------------------------------------------------------------- */
const CUERCODE_SETTING_BASE_URL       = 'cuercode_base_url';
const CUERCODE_SETTING_API_KEY        = 'cuercode_api_key';
const CUERCODE_SETTING_ALLOW_LOOPBACK = 'cuercode_allow_loopback'; /* '1' only on a local/test install so a stub can be reached over http:// */

const CUERCODE_DEFAULT_BASE_URL = 'https://cuercode.net';
const CUERCODE_GENERATE_PATH    = '/api/v1/generate';

/* Code constants (not settings rows — fewer knobs, no way to misconfigure the
 * fail-soft bound). The house curl band matches ip_geolocation.php / intapps. */
const CUERCODE_CURL_CONNECT_TIMEOUT = 2;
const CUERCODE_CURL_TIMEOUT         = 4;   /* a QR render is a touch heavier than a flag fetch */
const CUERCODE_MAX_RESPONSE_BYTES   = 2097152; /* 2 MiB — a large SVG/PNG QR with a logo, generously capped */

/* Payload + customisation bounds, enforced here AND mirrored by qr.php's own
 * input validation (defence in depth). */
const CUERCODE_MAX_PAYLOAD_LEN = 1024;
const CUERCODE_MIN_SIZE        = 100;
const CUERCODE_MAX_SIZE        = 1000;
const CUERCODE_FORMATS         = ['svg', 'png'];        /* the two iHymns embeds; CueRCode supports more */
const CUERCODE_ECC_LEVELS      = ['L', 'M', 'Q', 'H'];

/**
 * ELI5: do we have what we need to call CueRCode at all?
 * WHY: single resolution point — every caller sees the SAME complete-or-null
 * answer. The API key arrives already decrypted (getAppSetting() transparently
 * decrypts the `enc:v1:` envelope once `cuercode_api_key` is registered in
 * secretSettingKeys()). Memoized per request.
 *
 * @return array{base_url:string,api_key:string,user_agent:string}|null
 */
function cuercodeConfig(): ?array
{
    static $cached = false; /* false = unresolved; null = resolved-and-incomplete */
    if ($cached !== false) {
        return $cached;
    }
    $baseUrl = trim((string)(getAppSetting(CUERCODE_SETTING_BASE_URL, CUERCODE_DEFAULT_BASE_URL) ?? ''));
    $apiKey  = trim((string)(getAppSetting(CUERCODE_SETTING_API_KEY, '') ?? ''));
    if ($baseUrl === '' || $apiKey === '') {
        return $cached = null;
    }
    return $cached = [
        'base_url'   => $baseUrl,
        'api_key'    => $apiKey,
        'user_agent' => 'iHymns-QR/1.0',
    ];
}

/** ELI5: is CueRCode set up (a key is saved)? WHY: cheap gate for qr.php. */
function cuercodeConfigured(): bool
{
    return cuercodeConfig() !== null;
}

/** ELI5: is the local-only http:// carve-out on? WHY: reach a test stub. */
function _cuercodeAllowLoopback(): bool
{
    return (string)(getAppSetting(CUERCODE_SETTING_ALLOW_LOOPBACK, '0') ?? '0') === '1';
}

/**
 * ELI5: is this CueRCode URL safe to actually dial?
 * WHY: SSRF hardening as a PURE function (identical shape to intapps
 * `_intappsResolveUrl()`): https:// always allowed; http:// only for a loopback
 * host when the knob is on. The dialled URL is rebuilt from ONLY the parsed
 * scheme/host/port + the fixed `$path`, never the raw base string re-concatenated
 * with anything, so the request is host-bound to the configured host.
 *
 * @return array{0:string,1:string}|null [$fullUrl, $host] or null if refused.
 */
function _cuercodeResolveUrl(string $baseUrl, string $path, bool $allowLoopback): ?array
{
    $parts = parse_url($baseUrl);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        return null;
    }
    $scheme = strtolower((string)$parts['scheme']);
    $host   = strtolower((string)$parts['host']);
    $isLoopback = in_array($host, ['127.0.0.1', '::1', 'localhost'], true);
    if ($scheme === 'https') {
        /* always allowed */
    } elseif ($scheme === 'http' && $allowLoopback && $isLoopback) {
        /* local/test carve-out */
    } else {
        return null;
    }
    $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
    if ($path === '' || $path[0] !== '/') {
        $path = '/' . $path;
    }
    return [$scheme . '://' . $host . $port . $path, $host];
}

/**
 * ELI5: ask CueRCode to draw a QR for a link, and give back the picture bytes —
 * or null if anything at all goes wrong (never throw).
 * WHY: the ONE HTTP round trip. Every failure mode (no key, refused URL, no
 * curl, transport error, oversized/malformed body, non-2xx, success:false, a
 * data URI we can't parse) is a null RETURN, so qr.php branches on null, never a
 * try/catch. No redirects + host-bound URL (SSRF); response read through an
 * aborting write-callback so an oversized body is never fully buffered.
 *
 * @param string $payloadUrl The URL/text to encode (e.g. a song permalink).
 * @param array  $opts       { format:'svg'|'png', size:int, ecc:'L'|'M'|'Q'|'H',
 *                             fg_color?:'#rrggbb', bg_color?:'#rrggbb', type?:string }
 * @return array{bytes:string,mime:string,format:string}|null
 */
function cuercodeGenerate(string $payloadUrl, array $opts = []): ?array
{
    $payloadUrl = trim($payloadUrl);
    if ($payloadUrl === '' || strlen($payloadUrl) > CUERCODE_MAX_PAYLOAD_LEN) {
        return null;
    }
    $config = cuercodeConfig();
    if ($config === null || !function_exists('curl_init')) {
        return null;
    }
    $resolved = _cuercodeResolveUrl($config['base_url'], CUERCODE_GENERATE_PATH, _cuercodeAllowLoopback());
    if ($resolved === null) {
        return null;
    }
    [$url] = $resolved;

    /* Normalise + clamp the customisation (defence in depth; qr.php also validates). */
    $format = in_array(($opts['format'] ?? 'svg'), CUERCODE_FORMATS, true) ? $opts['format'] : 'svg';
    $size   = (int)($opts['size'] ?? 512);
    $size   = max(CUERCODE_MIN_SIZE, min(CUERCODE_MAX_SIZE, $size));
    $ecc    = in_array(($opts['ecc'] ?? 'M'), CUERCODE_ECC_LEVELS, true) ? $opts['ecc'] : 'M';
    $type   = preg_match('/^[a-z][a-z0-9_]{0,20}$/', (string)($opts['type'] ?? 'url')) ? (string)$opts['type'] : 'url';

    $customization = ['format' => $format, 'size' => $size, 'ecc' => $ecc];
    foreach (['fg_color', 'bg_color'] as $ck) {
        if (isset($opts[$ck]) && preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', (string)$opts[$ck])) {
            $customization[$ck] = (string)$opts[$ck];
        }
    }
    /* CueRCode's 'url' type takes input.url; other types take input.text. */
    $inputKey = ($type === 'url') ? 'url' : 'text';
    $body = json_encode([
        'type'          => $type,
        'input'         => [$inputKey => $payloadUrl],
        'customization' => $customization,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $ch = curl_init();
    if ($ch === false) {
        return null;
    }
    $buf = '';
    $cap = CUERCODE_MAX_RESPONSE_BYTES;
    $writeFn = static function ($handle, string $chunk) use (&$buf, $cap): int {
        $buf .= $chunk;
        if (strlen($buf) > $cap) {
            return -1; /* abort the transfer (CURLE_WRITE_ERROR) — never buffer an oversized body */
        }
        return strlen($chunk);
    };
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => [
            'X-API-Key: ' . $config['api_key'],
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_USERAGENT      => $config['user_agent'],
        CURLOPT_CONNECTTIMEOUT => CUERCODE_CURL_CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT        => CUERCODE_CURL_TIMEOUT,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false, /* never follow a redirect (SSRF) */
        CURLOPT_PROTOCOLS      => str_starts_with($url, 'https://') ? CURLPROTO_HTTPS : CURLPROTO_HTTP,
        CURLOPT_WRITEFUNCTION  => $writeFn,
    ]);
    curl_exec($ch);
    $errno      = curl_errno($ch);
    $httpStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0 || $httpStatus < 200 || $httpStatus >= 300) {
        return null;
    }
    $decoded = json_decode($buf, true);
    if (!is_array($decoded) || ($decoded['success'] ?? false) !== true || !is_array($decoded['data'] ?? null)) {
        return null;
    }
    $dataUri = (string)($decoded['data']['image'] ?? '');
    /* Parse a `data:<mime>;base64,<payload>` URI into raw bytes + mime. */
    if (!preg_match('#^data:([a-z0-9.+/-]+);base64,(.+)$#is', $dataUri, $m)) {
        return null;
    }
    $mime  = strtolower($m[1]);
    $bytes = base64_decode($m[2], true);
    if ($bytes === false || $bytes === '') {
        return null;
    }
    return [
        'bytes'  => $bytes,
        'mime'   => $mime,
        'format' => (string)($decoded['data']['format'] ?? $format),
    ];
}
