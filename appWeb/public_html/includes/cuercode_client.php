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
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'qr_cache.php';       /* #1920 C3 — qrCacheFetch()/qrCacheStore(), side-effect-free to require */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'network_guard.php';  /* L-2 security-audit finding — ihymnsHostResolvesPrivate() */

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
 * #2003 — the "Connect a service" wizard's CueRCode connectivity probe
 * (`cuercodeProbe()` below) needs SOME text to ask CueRCode to draw, and it
 * must never be user input (this call runs with no request context — an
 * admin clicking "Test connection", not a song/QR render). A fixed, tiny,
 * always-valid URL keeps the probe request byte-identical on every call, so
 * a failure can only ever mean "the credentials/network are the problem",
 * never "this particular payload confused CueRCode".
 */
const CUERCODE_PROBE_PAYLOAD = 'https://ihymns.app/';

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
 * SECURITY AUDIT L-2 FIX (2026-08-30) — "https:// always allowed" is about
 * the WIRE PROTOCOL, not about WHERE the wire actually goes: an admin could
 * still point this at an internal address — the cloud metadata endpoint
 * (169.254.169.254), a loopback admin panel, an internal 10.x host — over a
 * perfectly valid `https://` URL, and the pre-existing http-only loopback
 * carve-out just above never caught that (it only special-cases the three
 * literal loopback spellings, and only inside the `http://` branch). Mirrors
 * `manage/configuration.php`'s own `$smtpHostIsPrivate()` (#1304) via the
 * shared `ihymnsHostResolvesPrivate()` (`includes/network_guard.php`,
 * rule #22 — one core, not a third hand-copied check): resolve the host to
 * its IP(s) and refuse if ANY resolves into a private/reserved range
 * (which also covers the 169.254.0.0/16 link-local block the cloud-metadata
 * address sits in). The SAME `$allowLoopback` knob that unlocks the
 * http+loopback carve-out above ALSO skips this new check — it exists
 * precisely for a local/test install talking to a CueRCode stub on an
 * internal address, and forcing that install to defeat this check some
 * OTHER way would just relocate the escape hatch, not remove it.
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
    /* F-1 (2026-08-30 correctness review): parse_url() returns an IPv6
       literal host WITH its brackets (`[::1]`), but both checks below
       compare/classify the BARE address — without this, a bracketed
       loopback or private literal (`[::1]`, `[fd00:ec2::254]`) matched
       neither the carve-out below NOR the SSRF guard and slipped through
       as "not private". $host itself stays bracket-intact (it's what
       builds the dialled URL for curl below); only this classification
       copy is normalised. */
    $hostForCheck = ihymnsNormalizeHostLiteral($host);
    $isLoopback = in_array($hostForCheck, ['127.0.0.1', '::1', 'localhost'], true);
    if ($scheme === 'https') {
        /* always allowed */
    } elseif ($scheme === 'http' && $allowLoopback && $isLoopback) {
        /* local/test carve-out */
    } else {
        return null;
    }
    /* L-2 — destination-restriction check, skipped only by the SAME knob
       that already unlocks the http+loopback carve-out above (see this
       function's own doc-block for why). An unresolvable host (a typo) is
       not refused HERE — that's not this check's job; it simply never
       matches "private" either way. */
    if (!$allowLoopback && ihymnsHostResolvesPrivate($hostForCheck)) {
        return null;
    }
    $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
    if ($path === '' || $path[0] !== '/') {
        $path = '/' . $path;
    }
    return [$scheme . '://' . $host . $port . $path, $host];
}

/**
 * ELI5: turn "whatever drawing options were passed in" into ONE tidy,
 * complete, predictably-ordered list of the values CueRCode will actually
 * draw with — so two requests that LOOK different (different key order, or
 * one omitting a default) but mean the SAME picture always normalise to the
 * exact same map.
 * WHY EXTRACTED (#1920 C3): this was inlined at the top of
 * cuercodeGenerate() until the QR cache needed a SECOND consumer of the
 * identical fold — the cache-key deriver (cuercodeCacheKey()). Rule #22
 * ("one core per concern") says that fold lives in exactly one place;
 * cuercodeGenerate() below now calls this function too, so its own
 * behaviour is byte-identical to before (same values, same clamps, same
 * order of use) and the key can never drift from the request it describes
 * (#1920 §A.3 — the ONLY way two spellings of one request could ever
 * disagree is if this fold itself had a bug, never a second copy of it).
 * `ksort()` makes the returned map's later `json_encode()` (in
 * cuercodeCacheKey()) canonical regardless of what order `$opts` arrived in.
 *
 * @param array $opts { format?:'svg'|'png', size?:int, ecc?:'L'|'M'|'Q'|'H',
 *                      type?:string, fg_color?:'#rrggbb', bg_color?:'#rrggbb' }
 * @return array{ecc:string,format:string,size:int,type:string,fg_color?:string,bg_color?:string}
 *         Fully-defaulted, clamped, ksorted. Absent colour keys STAY absent
 *         (never defaulted to an empty string) so the cache key doesn't
 *         grow an extra axis nobody asked about.
 */
function cuercodeNormaliseOptions(array $opts): array
{
    /* NOTE (found during #1920 C3 extraction): the ORIGINAL inline code read
       `$opts['format']` a SECOND time in each ternary's true-branch instead
       of reusing the already-coalesced local — harmless for every CURRENT
       caller (qr.php / pdf_renderer.php always pass every key explicitly),
       but it emits an "undefined array key" warning AND silently resolves
       to null (not the intended default) the moment any caller omits a key
       — exactly the "defaults-filled vs explicit-equal -> SAME key"
       invariant this function exists to guarantee, and exactly what
       test-qr-cache.php calls this function WITH (an empty $opts, to prove
       the defaults path). Fixed here by resolving each coalesced value into
       a local ONCE and reusing it on both branches — same result for every
       defined-key input (byte-identical to today's real callers), correct
       for an omitted one. */
    $formatIn = $opts['format'] ?? 'svg';
    $format   = in_array($formatIn, CUERCODE_FORMATS, true) ? $formatIn : 'svg';
    $size     = (int)($opts['size'] ?? 512);
    $size     = max(CUERCODE_MIN_SIZE, min(CUERCODE_MAX_SIZE, $size));
    $eccIn    = $opts['ecc'] ?? 'M';
    $ecc      = in_array($eccIn, CUERCODE_ECC_LEVELS, true) ? $eccIn : 'M';
    $typeIn   = (string)($opts['type'] ?? 'url');
    $type     = preg_match('/^[a-z][a-z0-9_]{0,20}$/', $typeIn) ? $typeIn : 'url';

    $norm = ['format' => $format, 'size' => $size, 'ecc' => $ecc, 'type' => $type];
    foreach (['fg_color', 'bg_color'] as $ck) {
        if (isset($opts[$ck]) && preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', (string)$opts[$ck])) {
            $norm[$ck] = (string)$opts[$ck];
        }
    }
    ksort($norm);
    return $norm;
}

/**
 * ELI5: turn a request (the text to encode + its drawing options) into ONE
 * short, unique fingerprint — the primary key `tblQrCache` looks answers up
 * by.
 * WHY sha256-of-JSON: `cuercodeNormaliseOptions()` already guarantees the
 * SAME logical request always produces the SAME normalised (ksorted) map, so
 * hashing `{payload, opts}` together (payload riding INSIDE the JSON, so no
 * delimiter could ever let two different payload/options pairs collide onto
 * one string) turns that into a fixed-length, collision-resistant cache key.
 * Any future drawing option is automatically part of the key the moment
 * `cuercodeNormaliseOptions()` starts returning it — no second place to
 * remember to update (rule #20).
 *
 * @param string $payloadUrl The (already trimmed) text/URL being encoded.
 * @param array  $normOpts   The output of cuercodeNormaliseOptions() — NOT raw $opts.
 * @return string 64-char sha256 hex.
 */
function cuercodeCacheKey(string $payloadUrl, array $normOpts): string
{
    return hash('sha256', json_encode(
        ['payload' => $payloadUrl, 'opts' => $normOpts],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    ));
}

/**
 * ELI5: turn "the text to encode + the drawing options" into the exact JSON
 * body CueRCode's `POST /api/v1/generate` expects.
 * WHY EXTRACTED (#2003, rule #22's `editorSaveSongCore()` move): this was
 * inlined at the top of `cuercodeGenerate()`'s HTTP section until the
 * "Connect a service" wizard's `cuercodeProbe()` needed the IDENTICAL body
 * shape for a throwaway test payload instead of a real QR request. Pulling
 * it out means both callers build a body CueRCode's API can never tell
 * apart from "a real request" — no second, drifting copy of the
 * `type`/`input`/`customization` envelope shape.
 *
 * @param string $payloadUrl The (already trimmed) text/URL being encoded.
 * @param array  $norm       cuercodeNormaliseOptions()'s OUTPUT — fully
 *                            defaulted/clamped, never raw caller $opts.
 * @return string JSON body, or `false` on the (practically unreachable,
 *                since every value here is already validated) case that
 *                json_encode() itself fails — matches this function's
 *                pre-extraction behaviour exactly: the caller never checked
 *                for that either, so this is a byte-identical move, not a
 *                new guarantee.
 */
function _cuercodeBuildRequestBody(string $payloadUrl, array $norm): string
{
    $customization = ['format' => $norm['format'], 'size' => $norm['size'], 'ecc' => $norm['ecc']];
    foreach (['fg_color', 'bg_color'] as $ck) {
        if (isset($norm[$ck])) {
            $customization[$ck] = $norm[$ck];
        }
    }
    /* CueRCode's 'url' type takes input.url; other types take input.text. */
    $inputKey = ($norm['type'] === 'url') ? 'url' : 'text';
    return (string)json_encode([
        'type'          => $norm['type'],
        'input'         => [$inputKey => $payloadUrl],
        'customization' => $customization,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/**
 * ELI5: the ONE curl round trip to CueRCode — send this body to this URL
 * with this API key, and hand back exactly what came back (or an
 * unreachable-shaped result), never deciding what it MEANS.
 * WHY EXTRACTED (#2003, same move as the body-builder above): `cuercodeProbe()`
 * needs the SAME SSRF-hardened transport (host-bound URL from the caller,
 * no redirects, SSL verify, size-capped aborting write-callback, house
 * timeouts) but reads the raw `errno`/`httpStatus` itself instead of letting
 * `cuercodeGenerate()`'s success/failure collapse hide them — a probe's
 * whole job is telling "wrong key" (401/403) apart from "network
 * unreachable" apart from "CueRCode is fine but sent something we can't
 * parse", distinctions `cuercodeGenerate()` deliberately erases (it only
 * ever needs to know yes/no). One curl call site, two different questions
 * asked of its answer — never two curl call sites.
 *
 * @param array{base_url:string,api_key:string,user_agent:string} $config
 * @param string $url  the ALREADY host-bound, SSRF-checked URL (from
 *                       `_cuercodeResolveUrl()` — this function does no
 *                       validation of its own).
 * @param string $body the JSON request body (from `_cuercodeBuildRequestBody()`).
 * @return array{errno:int,httpStatus:int,body:string} `errno` is a real
 *         `curl_errno()` value, EXCEPT the sentinel `-1` (which `curl_errno()`
 *         never returns) meaning "curl_init() itself failed" — both are
 *         "non-zero = something went wrong", so every caller's existing
 *         `$errno !== 0` check keeps working unchanged.
 */
function _cuercodeHttpExec(array $config, string $url, string $body): array
{
    $ch = curl_init();
    if ($ch === false) {
        return ['errno' => -1, 'httpStatus' => 0, 'body' => ''];
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

    return ['errno' => $errno, 'httpStatus' => $httpStatus, 'body' => $buf];
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
 * #2003 — the body-building and curl-transport steps now delegate to
 * `_cuercodeBuildRequestBody()` / `_cuercodeHttpExec()` (extracted so the
 * "Connect a service" wizard's `cuercodeProbe()` can reuse the identical
 * SSRF-hardened transport — see those functions' own doc-blocks). This
 * function's OWN behaviour is unchanged: same trims/guards, same
 * normalise-then-send order, same null-on-any-failure collapse, same
 * data-URI decode.
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

    /* Normalise + clamp the customisation (defence in depth; qr.php also
       validates) — via the ONE shared fold (#1920 C3) so cuercodeCacheKey()
       can never disagree with what this request actually sends. */
    $norm   = cuercodeNormaliseOptions($opts);
    $format = $norm['format'];

    $body = _cuercodeBuildRequestBody($payloadUrl, $norm);
    $exec = _cuercodeHttpExec($config, $url, $body);
    $errno      = $exec['errno'];
    $httpStatus = $exec['httpStatus'];
    $buf        = $exec['body'];

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

/**
 * ELI5: the "smart" version of cuercodeGenerate() — check our own saved
 * copy FIRST, and only bother CueRCode if we don't have one yet.
 * WHY THIS IS THE ONE COMPOSITION POINT (#1920 C3, rule #22): BOTH QR
 * surfaces (qr.php AND pdf_renderer.php's _pdfInlineQrImage()) call cached
 * generation, so the read-through logic lives here exactly once rather than
 * being duplicated at each call site.
 *
 * ORDER IS LOAD-BEARING (#1920 §3.1d / §4's dormancy proofs):
 *   1. Dormancy gate FIRST — `cuercodeConfigured()` is checked BEFORE the
 *      cache is ever consulted, so "dormant until keyed" (rule #38) stays a
 *      property of the INSTALL (unkeyed = null, always, regardless of what
 *      the cache table holds), not of what happens to be cached.
 *   2. Cache read — a hit returns immediately, WITHOUT ever touching the
 *      network (the entire point: no CueRCode round trip for a picture that
 *      never changes).
 *   3. On a miss, the UNTOUCHED cuercodeGenerate() HTTP path runs exactly as
 *      before this feature existed — byte-identical request, byte-identical
 *      response.
 *   4. Only a REAL success (`$qr !== null`) is ever stored — a failure is
 *      NEVER cached, so a CueRCode outage can never freeze into a permanent
 *      cached 503 (#1920 §A.1).
 *
 * @param string $payloadUrl The URL/text to encode.
 * @param array  $opts       Same shape as cuercodeGenerate()'s $opts.
 * @return array{bytes:string,mime:string,format:string}|null
 */
function cuercodeGenerateCached(string $payloadUrl, array $opts = []): ?array
{
    $payloadUrl = trim($payloadUrl);
    if ($payloadUrl === '' || strlen($payloadUrl) > CUERCODE_MAX_PAYLOAD_LEN) {
        return null;
    }
    /* DORMANCY FIRST (see doc-block point 1) — this call MUST textually
       precede the cache read below; a CI guard (test-qr-cache.php)
       mutation-tests this ordering. */
    if (!cuercodeConfigured()) {
        return null;
    }
    $norm = cuercodeNormaliseOptions($opts);
    $key  = cuercodeCacheKey($payloadUrl, $norm);

    $hit = qrCacheFetch($key);
    if ($hit !== null) {
        return $hit;
    }

    $qr = cuercodeGenerate($payloadUrl, $opts); /* the untouched HTTP path */
    if ($qr !== null) {
        qrCacheStore($key, $payloadUrl, $norm, $qr); /* never caches a failure */
    }
    return $qr;
}

/**
 * ELI5: "does my saved CueRCode key actually work RIGHT NOW?" — the
 * question `manage/configuration.php`'s CueRCode card has never been able
 * to answer for itself (#2003, the "Connect a service" wizard's honest
 * delta — see that plan's §1: this integration never had a live test
 * before). Sends ONE tiny, throwaway probe request and reports back which
 * of five things happened, never the picture bytes themselves.
 *
 * DETAIL — SAME SSRF DISCIPLINE, DELIBERATELY NOT CACHE-MEDIATED:
 * this function goes through the exact same `cuercodeConfig()` +
 * `_cuercodeResolveUrl()` (host-bound, SSRF-checked) + `_cuercodeHttpExec()`
 * (no redirects, SSL verify, size-capped write-callback, house timeouts)
 * transport `cuercodeGenerate()` uses — never a second curl block (rule
 * #22). It deliberately calls neither `cuercodeGenerate()` NOR
 * `cuercodeGenerateCached()`: the cached wrapper would let a STALE cache
 * hit "pass" the test without the live service ever being asked anything
 * (the whole point of a connectivity probe is asking it *now*), and the
 * raw `cuercodeGenerate()` collapses every failure mode into a single
 * `null` — exactly the distinction (wrong key vs. unreachable vs. a
 * response we can't parse) this probe exists to preserve. It never writes
 * to `tblQrCache` either way.
 *
 * Never throws; never returns a secret — only structural status/numbers
 * (rule #35's "no secret ever reaches a diagnostic response" applied to
 * CueRCode).
 *
 * @return array{status:'ok'|'unconfigured'|'unreachable'|'rejected'|'bad_response', errno:int, httpStatus:int, durationMs:float}
 */
function cuercodeProbe(): array
{
    $start = microtime(true);

    $config = cuercodeConfig();
    if ($config === null || !function_exists('curl_init')) {
        /* No key saved (or no curl extension at all) — there is nothing to
           dial, so this is a CONFIGURATION state, not a network failure. */
        return ['status' => 'unconfigured', 'errno' => 0, 'httpStatus' => 0, 'durationMs' => 0.0];
    }
    $resolved = _cuercodeResolveUrl($config['base_url'], CUERCODE_GENERATE_PATH, _cuercodeAllowLoopback());
    if ($resolved === null) {
        /* The saved base URL doesn't pass the SSRF host-binding check
           (e.g. an http:// URL with the loopback carve-out off) — again a
           configuration problem, not a network one. */
        return ['status' => 'unconfigured', 'errno' => 0, 'httpStatus' => 0, 'durationMs' => 0.0];
    }
    [$url] = $resolved;

    /* A fixed, tiny, always-valid probe request — see CUERCODE_PROBE_PAYLOAD's
       own doc-block for why this must never be user input. */
    $norm = cuercodeNormaliseOptions(['format' => 'svg', 'size' => CUERCODE_MIN_SIZE, 'ecc' => 'L']);
    $body = _cuercodeBuildRequestBody(CUERCODE_PROBE_PAYLOAD, $norm);
    $exec = _cuercodeHttpExec($config, $url, $body);
    $durationMs = round((microtime(true) - $start) * 1000, 2);

    $errno      = $exec['errno'];
    $httpStatus = $exec['httpStatus'];

    if ($errno !== 0) {
        /* Transport never completed at all (DNS/connect/timeout/curl_init
           failure via the -1 sentinel) — the server itself couldn't be
           reached, distinct from it answering and refusing us. */
        return ['status' => 'unreachable', 'errno' => $errno, 'httpStatus' => $httpStatus, 'durationMs' => $durationMs];
    }
    if ($httpStatus === 401 || $httpStatus === 403) {
        /* CueRCode answered and REJECTED the key — the "your key is wrong,
           no amount of waiting fixes it" verdict (mirrors captcha.php's
           'misconfig' remedy-naming doctrine: name what to DO, not just
           what happened). */
        return ['status' => 'rejected', 'errno' => $errno, 'httpStatus' => $httpStatus, 'durationMs' => $durationMs];
    }
    if ($httpStatus < 200 || $httpStatus >= 300) {
        /* Some other non-2xx (5xx, 429, an unexpected 4xx) — the service
           answered but something else is wrong; carry the status code so
           the wizard can say which. */
        return ['status' => 'unreachable', 'errno' => $errno, 'httpStatus' => $httpStatus, 'durationMs' => $durationMs];
    }
    /* 2xx — but is the body actually a well-formed success response with a
       data: URI, the same shape cuercodeGenerate() itself requires? A
       CueRCode deploy that answers 200 with something unexpected (a proxy
       error page, a future API version) must not read as "ok". */
    $decoded = json_decode($exec['body'], true);
    $imageOk = is_array($decoded)
        && ($decoded['success'] ?? false) === true
        && is_array($decoded['data'] ?? null)
        && (bool)preg_match('#^data:[a-z0-9.+/-]+;base64,.+$#is', (string)($decoded['data']['image'] ?? ''));
    if (!$imageOk) {
        return ['status' => 'bad_response', 'errno' => $errno, 'httpStatus' => $httpStatus, 'durationMs' => $durationMs];
    }
    return ['status' => 'ok', 'errno' => $errno, 'httpStatus' => $httpStatus, 'durationMs' => $durationMs];
}
