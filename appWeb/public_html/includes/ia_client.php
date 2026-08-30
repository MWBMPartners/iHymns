<?php

declare(strict_types=1);

/**
 * iHymns — Internet Archive (archive.org) read client (#94 Phase 1)
 *
 * ELI5: this file asks the Internet Archive's public website for two things —
 * "what do you know about this scanned book?" (metadata) and "what did your
 * OCR software read off its pages?" (the full-text file) — and hands back
 * plain data. It never changes anything on archive.org (GET requests only)
 * and it never guesses at a server to talk to: every URL it dials is checked
 * first against an allow-list so a hostile or corrupted answer from
 * archive.org can never redirect this server somewhere else (SSRF).
 *
 * DETAIL:
 * -------
 * Modelled line-for-line on the two existing outbound-service clients
 * (`includes/intapps_client.php`, `includes/cuercode_client.php` — rule #22):
 * `declare(strict_types=1)`, side-effect-free to require (functions +
 * constants only — requiring this file opens no DB connection and makes no
 * HTTP call), returns null/typed-array on ANY failure, NEVER throws into a
 * page render. Unlike those two clients this one needs no API key —
 * archive.org's metadata + full-text reads are public/anonymous — so there
 * is no `*Enabled()` dormancy gate; the gate here is purely "did the request
 * succeed", answered by a null return.
 *
 * archive.org read endpoints:
 *
 *   Metadata:  GET https://archive.org/metadata/<identifier>
 *              -> JSON: { "metadata": {title, language, ...},
 *                         "files": [ {name, format, size, sha1|md5, ...}, ... ],
 *                         "server": "ia601409.us.archive.org",
 *                         "d1": "...", "d2": "...",       <- fallback datanodes
 *                         "dir": "/12/items/<identifier>" }
 *              A NONEXISTENT identifier answers HTTP 200 with body "{}" —
 *              treated as null (no 'files' array present).
 *
 *   Full text: the derived plain-text OCR file, normally named
 *              "<identifier>_djvu.txt" (a files[] entry with format
 *              "DjVuTXT"). The friendly URL
 *              https://archive.org/download/<identifier>/<identifier>_djvu.txt
 *              answers HTTP 302 to a datanode — and this client NEVER
 *              follows redirects (house SSRF rule). So it builds the DIRECT
 *              datanode URL from the metadata response instead:
 *                  https://{server}{dir}/{fileName}
 *              validating {server} against the ".archive.org" host-suffix
 *              allowlist BEFORE dialling — a hostile/tampered metadata body
 *              must not be able to steer this server at an arbitrary host
 *              (SSRF *via* the metadata response, not just the config).
 *              d1/d2 are legitimate fallback datanode hosts, same
 *              validation.
 *
 *   (Alternative NOT used in Phase 1: the BookReader search-inside API —
 *   per-page hit coordinates, but paginated + rate-limited; noted here for
 *   Phase 2's page-anchoring, never built in this file.)
 *
 * SANDBOX CONSTRAINT: no live archive.org fetch is possible in the build/CI
 * sandbox (no network). Everything here is proven against the PURE URL
 * resolvers (`_iaResolveUrl()` / `_iaDatanodeUrl()`, unit-tested with a full
 * truth table in `tests/php/test-ia-reconcile-guards.php`) plus the
 * fixture-injection seam described on `iaFetchFulltext()` below — never a
 * real fetch.
 *
 * @see .claude/ia-ocr-94-plan.md §1  (the full design this file implements)
 * @link https://archive.org/developers/metadata-schema/  archive.org metadata API docs
 * @link https://www.php.net/manual/en/book.curl.php
 * @see includes/intapps_client.php  the outbound-service precedent this mirrors (rule #22)
 * @see includes/cuercode_client.php the second outbound-service precedent
 */

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'maintenance.php'; /* getAppSetting() */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'network_guard.php'; /* L-2 security-audit follow-up — ihymnsHostResolvesPrivate() */

/* -------------------------------------------------------------------------
 * SETTINGS KEYS + CONSTANTS — defined ONCE here so no other file re-types a
 * literal and drifts (rule #35).
 * ---------------------------------------------------------------------- */
const IA_SETTING_BASE_URL       = 'ia_base_url';        /* default https://archive.org */
/* '1' only on a local/test install so the fixture-injection stub is
   reachable over http:// — the intapps/cuercode loopback carve-out,
   verbatim (see _iaResolveUrl()). */
const IA_SETTING_ALLOW_LOOPBACK = 'ia_allow_loopback';

const IA_DEFAULT_BASE_URL = 'https://archive.org';
/* Datanode host allowlist: a resolved datanode host must END WITH this
   suffix (case-insensitive, checked in _iaDatanodeUrl()). */
const IA_DATANODE_SUFFIX  = '.archive.org';

const IA_USER_AGENT = 'iHymns-Reconcile/1.0 (+https://ihymns.app)';

/* Timeouts — a DOCUMENTED deviation from the 2s/3s house band
   (ip_geolocation/intapps/cuercode): those bound PUBLIC hot paths. This
   client is reachable ONLY from an admin-triggered, entitlement-gated POST
   (tests/php/test-ia-reconcile-guards.php's "not-public" assertion proves
   this tree-derived, not just claimed), and a hymnal `_djvu.txt` is
   megabytes — a 2-3s timeout would fail every real fetch. */
const IA_CURL_CONNECT_TIMEOUT  = 5;
const IA_CURL_TIMEOUT_METADATA = 15;
const IA_CURL_TIMEOUT_FULLTEXT = 60;

/* 2 MiB — files[] can be long on multi-file items. */
const IA_MAX_METADATA_BYTES = 2097152;
/* 15 MiB — deliberately UNDER MEDIUMTEXT's 16,777,215-byte cap so a cached
   payload can never overflow tblIaFetchCache.Payload. */
const IA_MAX_FULLTEXT_BYTES = 15728640;

const IA_METADATA_TTL_SECONDS = 86400;    /* 24 h */
const IA_FULLTEXT_TTL_SECONDS = 2592000;  /* 30 days — a finished scan's OCR is effectively immutable */

/* Design-for-later, NOT built in Phase 1 (no consumer, no schema impact):
   if authorized fetches are ever needed, add `ia_s3_access_key` +
   `ia_s3_secret_key` settings, register `ia_s3_secret_key` in
   secretSettingKeys() (includes/secret_crypto.php — the
   `apple_apns_private_key` comment there is the precedent for registering a
   not-yet-used key), and send `Authorization: LOW <access>:<secret>`. */

/**
 * ELI5: does this look like a real archive.org item identifier?
 * WHY: archive.org's own identifier rule (1-100 chars, [A-Za-z0-9._-],
 * starts alphanumeric) — the FIRST gate before any URL is even built, so a
 * garbage/hostile identifier never reaches path construction.
 *
 * DUPLICATED (deliberately, as a literal) in
 * `ihymns_canonical_ia_identifier()` (includes/identifier_normalize.php) —
 * that module is dependency-free by design (rule #22's "one fold" instinct
 * applied to identifier shape, not the fold logic itself). The two literals
 * are mechanically held in sync by
 * `tests/php/test-ia-reconcile-guards.php` (rule #35).
 */
function iaIdentifierValid(string $identifier): bool
{
    return (bool)preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/', $identifier);
}

/**
 * ELI5: is the "test on a local machine only" http:// exception switched on?
 */
function _iaAllowLoopback(): bool
{
    return (string)(getAppSetting(IA_SETTING_ALLOW_LOOPBACK, '0') ?? '0') === '1';
}

/**
 * ELI5: which base URL should we be talking to? (real archive.org, or a
 * local test stub).
 */
function _iaBaseUrl(): string
{
    $base = trim((string)(getAppSetting(IA_SETTING_BASE_URL, IA_DEFAULT_BASE_URL) ?? ''));
    return $base !== '' ? $base : IA_DEFAULT_BASE_URL;
}

/**
 * ELI5: is this a URL we're actually allowed to dial?
 * WHY: PURE SSRF gate — IDENTICAL shape to `_cuercodeResolveUrl()` /
 * `_intappsResolveUrl()` (rule #22 — one pattern, not a fresh one per
 * client): `https://` is always allowed; `http://` is allowed ONLY when the
 * loopback knob is on AND the host is literally `127.0.0.1` / `::1` /
 * `localhost` (the fixture-injection seam is the one legitimate reason this
 * carve-out exists — it can never reach a real host on the public
 * internet). The returned URL is rebuilt from ONLY the parsed
 * scheme/host/port + the caller's fixed `$path` — never the raw `$baseUrl`
 * string re-concatenated with anything — so the request stays host-bound to
 * the CONFIGURED host on the CONFIGURED scheme.
 *
 * @return array{0:string,1:string}|null [$fullUrl, $host] or null if refused.
 */
function _iaResolveUrl(string $baseUrl, string $path, bool $allowLoopback): ?array
{
    $parts = parse_url($baseUrl);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        return null;
    }
    $scheme = strtolower((string)$parts['scheme']);
    $host   = strtolower((string)$parts['host']);
    $isLoopbackHost = in_array($host, ['127.0.0.1', '::1', 'localhost'], true);

    if ($scheme === 'https') {
        /* always allowed */
    } elseif ($scheme === 'http' && $allowLoopback && $isLoopbackHost) {
        /* the local/test-only carve-out */
    } else {
        return null;
    }

    /* L-2 (2026-08-30 security-audit follow-up) — destination-restriction
       check, identical in shape to _cuercodeResolveUrl()/_intappsResolveUrl():
       refuse a private/reserved resolved host (including the
       169.254.169.254 cloud-metadata address) so a mistaken or hostile
       ia_base_url can't turn this outbound client into an SSRF probe of the
       internal network. Skipped ONLY by the same ia_allow_loopback knob that
       already unlocks the http+loopback local-test carve-out above. Shared
       core: ihymnsHostResolvesPrivate() in includes/network_guard.php. */
    if (!$allowLoopback && ihymnsHostResolvesPrivate($host)) {
        return null;
    }

    $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
    if ($path === '' || $path[0] !== '/') {
        $path = '/' . $path;
    }
    return [$scheme . '://' . $host . $port . $path, $host];
}

/**
 * ELI5: one URL path segment, made safe to drop into a URL.
 * WHY: shared by `_iaDatanodeUrl()`'s $dir/$fileName sanitisation — rejects
 * `.` / `..` / empty segments (path traversal) and percent-encodes
 * everything else.
 */
function _iaSanitizePathSegment(string $segment): ?string
{
    if ($segment === '' || $segment === '.' || $segment === '..') {
        return null;
    }
    return rawurlencode($segment);
}

/**
 * ELI5: clean up a whole path (like "/12/items/foo") into something safe to
 * paste into a URL — no `..` allowed anywhere in it.
 *
 * @return string|null Leading-slash path (e.g. "/12/items/foo"), or null
 *                      when the path is empty or contains a refused segment.
 */
function _iaSanitizePath(string $path): ?string
{
    $path = str_replace('\\', '/', $path);
    $clean = [];
    foreach (explode('/', $path) as $segment) {
        if ($segment === '') {
            continue; /* collapses leading/trailing/repeated slashes */
        }
        $enc = _iaSanitizePathSegment($segment);
        if ($enc === null) {
            return null;
        }
        $clean[] = $enc;
    }
    if (!$clean) {
        return null;
    }
    return '/' . implode('/', $clean);
}

/**
 * ELI5: is this a real archive.org "datanode" (the machine that actually
 * holds the file bytes), and is the file path safe?
 * WHY: the SECOND SSRF gate — for a host that arrives in DATA (the metadata
 * response's `server`/`d1`/`d2` fields), not config. `$server` must
 * (lowercased, no scheme, no port, no slash, no `@`, no `..`) end with
 * `IA_DATANODE_SUFFIX`; `$dir` and `$fileName` are path-sanitised via
 * `_iaSanitizePath()` / `_iaSanitizePathSegment()` — leading-slash
 * normalised, percent-encoded per segment, `..` segments refused. Always
 * `https://` — datanodes are never the loopback stub (see
 * `iaFetchFulltext()`'s stub branch, which never calls this function).
 *
 * @return string|null the full https:// URL, or null if refused.
 */
function _iaDatanodeUrl(string $server, string $dir, string $fileName): ?string
{
    $server = strtolower(trim($server));
    if ($server === ''
        || !preg_match('/^[a-z0-9.-]+$/', $server)
        || str_contains($server, '..')
        || !str_ends_with($server, IA_DATANODE_SUFFIX)
    ) {
        return null;
    }

    $dirPath = _iaSanitizePath($dir);
    if ($dirPath === null) {
        return null;
    }

    /* fileName must be a single path segment — a slash inside it would be a
       second, unaccounted-for path element smuggled past the dir check. */
    if (str_contains($fileName, '/')) {
        return null;
    }
    $fileSeg = _iaSanitizePathSegment($fileName);
    if ($fileSeg === null) {
        return null;
    }

    return 'https://' . $server . $dirPath . '/' . $fileSeg;
}

/**
 * ELI5: strip a UTF-8 byte-order-mark and any stray non-UTF-8 / control
 * bytes out of a fetched text blob.
 * WHY: mysqli STRICT + utf8mb4 THROWS on the first malformed byte at
 * cache-INSERT time (#94 plan §8-G) — this step is load-bearing, not
 * cosmetic. Keeps `\n` `\t` `\f` (the segmenter's own structural markers);
 * strips every other C0 control byte + DEL.
 */
function _iaSanitizeText(string $raw): string
{
    if (str_starts_with($raw, "\xEF\xBB\xBF")) {
        $raw = substr($raw, 3);
    }
    mb_substitute_character(0xFFFD);
    $clean = @mb_convert_encoding($raw, 'UTF-8', 'UTF-8');
    if (!is_string($clean)) {
        $clean = $raw;
    }
    /* Strip control chars except \t (0x09) \n (0x0A) \f (0x0C). */
    return (string)preg_replace('/[\x00-\x08\x0B\x0D\x0E-\x1F\x7F]/', '', $clean);
}

/**
 * ELI5: the one actual network round trip this whole file makes.
 * WHY: GET-only (this client has no mutating verb at all — the CI guard
 * asserts zero `CURLOPT_POST`/`CURLOPT_CUSTOMREQUEST`), no redirects
 * (`CURLOPT_FOLLOWLOCATION => false` — house SSRF rule), SSL verified,
 * protocol pinned to the resolved scheme, response read through an
 * ABORTING size-capped write-callback (copies the cuercode/intapps
 * `$writeFn` shape — never buffer an oversized/hostile body first and check
 * its length afterwards). Returns null on ANY transport failure; otherwise
 * the raw status + bytes so callers can distinguish "404" from "timeout"
 * from "200 but empty".
 *
 * @return array{status:int,bytes:string}|null
 */
function _iaHttpGet(string $url, int $timeoutSeconds, int $maxBytes): ?array
{
    if (!function_exists('curl_init')) {
        return null;
    }
    $ch = curl_init();
    if ($ch === false) {
        return null;
    }

    $buf = '';
    $writeFn = static function ($handle, string $chunk) use (&$buf, $maxBytes): int {
        $buf .= $chunk;
        if (strlen($buf) > $maxBytes) {
            return -1; /* aborts the transfer (CURLE_WRITE_ERROR) — the real bound */
        }
        return strlen($chunk);
    };

    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_HTTPGET        => true,
        CURLOPT_HTTPHEADER     => ['Accept: application/json, text/plain, */*'],
        CURLOPT_USERAGENT      => IA_USER_AGENT,
        CURLOPT_CONNECTTIMEOUT => IA_CURL_CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT        => $timeoutSeconds,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false, /* never follow a redirect (SSRF) */
        CURLOPT_PROTOCOLS      => str_starts_with($url, 'https://') ? CURLPROTO_HTTPS : CURLPROTO_HTTP,
        CURLOPT_MAXFILESIZE    => $maxBytes, /* best-effort (Content-Length-aware); the write-callback is the real bound */
        CURLOPT_WRITEFUNCTION  => $writeFn,
    ]);

    curl_exec($ch);
    $errno      = curl_errno($ch);
    $httpStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0) {
        return null; /* transport failure, incl. CURLE_FILESIZE_EXCEEDED / write-callback abort */
    }
    return ['status' => $httpStatus, 'bytes' => $buf];
}

/**
 * ELI5: "what does archive.org know about this item?"
 * WHY: GET {base}/metadata/{identifier}. Returns null on: invalid
 * identifier, refused URL, no curl, transport failure, non-200, oversized,
 * body not a JSON object, or the "{}" / no-'files' nonexistent-item shape.
 *
 * @return array|null the decoded metadata array, or null.
 */
function iaFetchMetadata(string $identifier): ?array
{
    if (!iaIdentifierValid($identifier)) {
        return null;
    }
    $resolved = _iaResolveUrl(_iaBaseUrl(), '/metadata/' . rawurlencode($identifier), _iaAllowLoopback());
    if ($resolved === null) {
        return null;
    }
    [$url] = $resolved;

    $resp = _iaHttpGet($url, IA_CURL_TIMEOUT_METADATA, IA_MAX_METADATA_BYTES);
    if ($resp === null || $resp['status'] !== 200) {
        return null;
    }
    $decoded = json_decode($resp['bytes'], true);
    if (!is_array($decoded) || !isset($decoded['files']) || !is_array($decoded['files'])) {
        /* Nonexistent item ("{}") or a malformed body — both null. */
        return null;
    }
    return $decoded;
}

/**
 * ELI5: out of everything archive.org has for this item, which file is the
 * plain-text OCR?
 * WHY: prefers the files[] entry named exactly "<identifier>_djvu.txt";
 * else the first entry with format === 'DjVuTXT'; else the first name
 * ending "_djvu.txt". Validates the eventual host via `_iaDatanodeUrl()`
 * (tries `server`, then `d1`, then `d2`). Skips (returns null) when the
 * declared size exceeds `IA_MAX_FULLTEXT_BYTES` — fail early, never start a
 * doomed transfer.
 *
 * @return array{fileName:string,url:string}|null
 */
function _iaPickFulltextFile(string $identifier, array $metadata): ?array
{
    $files = is_array($metadata['files'] ?? null) ? $metadata['files'] : [];
    if (!$files) {
        return null;
    }

    $exactName = $identifier . '_djvu.txt';
    $picked = null;
    foreach ($files as $f) {
        if (is_array($f) && (string)($f['name'] ?? '') === $exactName) {
            $picked = $f;
            break;
        }
    }
    if ($picked === null) {
        foreach ($files as $f) {
            if (is_array($f) && (string)($f['format'] ?? '') === 'DjVuTXT') {
                $picked = $f;
                break;
            }
        }
    }
    if ($picked === null) {
        foreach ($files as $f) {
            $name = is_array($f) ? (string)($f['name'] ?? '') : '';
            if ($name !== '' && str_ends_with($name, '_djvu.txt')) {
                $picked = $f;
                break;
            }
        }
    }
    if ($picked === null) {
        return null;
    }

    $fileName = (string)($picked['name'] ?? '');
    if ($fileName === '') {
        return null;
    }
    $declaredSize = isset($picked['size']) ? (int)$picked['size'] : 0;
    if ($declaredSize > 0 && $declaredSize > IA_MAX_FULLTEXT_BYTES) {
        return null;
    }

    $dir = (string)($metadata['dir'] ?? '');
    foreach (['server', 'd1', 'd2'] as $hostKey) {
        $host = (string)($metadata[$hostKey] ?? '');
        if ($host === '') {
            continue;
        }
        $url = _iaDatanodeUrl($host, $dir, $fileName);
        if ($url !== null) {
            return ['fileName' => $fileName, 'url' => $url];
        }
    }
    return null;
}

/**
 * ELI5: actually download the OCR text.
 * WHY: TWO dial branches, both host-bound:
 *   - real archive.org  -> the `_iaDatanodeUrl()` built from metadata (no
 *     redirect needed — it's already the direct file URL).
 *   - fixture-injection seam -> when the configured base URL resolves to a
 *     loopback host AND the loopback knob is on, GET
 *     {base}/download/{identifier}/{fileName} on the SAME stub base (the
 *     stub serves bytes directly, no 302). A loopback stub can never mint
 *     an `.archive.org` datanode name, so this branch is the ONLY way
 *     Phase 1's tests exercise a fetch end-to-end — no live archive.org
 *     call is ever attempted from CI or this sandbox.
 *
 * @return array{text:string,fileName:string,sha256:string,byteSize:int}|null
 */
function iaFetchFulltext(string $identifier, ?array $metadata = null): ?array
{
    if (!iaIdentifierValid($identifier)) {
        return null;
    }
    if ($metadata === null) {
        $metadata = iaFetchMetadata($identifier);
        if ($metadata === null) {
            return null;
        }
    }
    $picked = _iaPickFulltextFile($identifier, $metadata);
    if ($picked === null) {
        return null;
    }

    $baseUrl       = _iaBaseUrl();
    $allowLoopback = _iaAllowLoopback();
    $baseParts     = parse_url($baseUrl);
    $baseScheme    = is_array($baseParts) ? strtolower((string)($baseParts['scheme'] ?? '')) : '';
    $baseHost      = is_array($baseParts) ? strtolower((string)($baseParts['host'] ?? '')) : '';
    $baseIsLoopbackStub = $allowLoopback
        && $baseScheme === 'http'
        && in_array($baseHost, ['127.0.0.1', '::1', 'localhost'], true);

    if ($baseIsLoopbackStub) {
        $path = '/download/' . rawurlencode($identifier) . '/' . rawurlencode($picked['fileName']);
        $resolved = _iaResolveUrl($baseUrl, $path, true);
        if ($resolved === null) {
            return null;
        }
        [$url] = $resolved;
    } else {
        $url = $picked['url']; /* already host-bound to .archive.org via _iaDatanodeUrl() */
    }

    $resp = _iaHttpGet($url, IA_CURL_TIMEOUT_FULLTEXT, IA_MAX_FULLTEXT_BYTES);
    if ($resp === null || $resp['status'] !== 200) {
        return null;
    }

    $text = _iaSanitizeText($resp['bytes']);
    return [
        'text'     => $text,
        'fileName' => $picked['fileName'],
        'sha256'   => hash('sha256', $text),
        'byteSize' => strlen($text),
    ];
}

/* =========================================================================
 * CACHE LAYER (D1 option A — RESOLVED, see .claude/ia-ocr-94-plan.md banner)
 *
 * Same discipline as `includes/intapps_client.php`'s D1 handling:
 * `_iaCacheTableExists()` + try/catch `\mysqli_sql_exception` around EVERY
 * `tblIaFetchCache` access. Migrations are web-run, the 3 docroots share ONE
 * MySQL, so "this table doesn't exist yet on this install" is a NORMAL
 * state, and mysqli STRICT (includes/db_mysql.php) makes a missing-table
 * SELECT throw — every access here is gated so an un-migrated install
 * degrades to "fetch direct, uncached", never a 500.
 * ========================================================================= */

/**
 * ELI5: does `tblIaFetchCache` actually exist on this database right now?
 * WHY: memoized per request; fails closed (false) on ANY error.
 */
function _iaCacheTableExists(\mysqli $db): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    try {
        $stmt = $db->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblIaFetchCache' LIMIT 1"
        );
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();
        return $cached = $exists;
    } catch (\Throwable $e) {
        return $cached = false;
    }
}

/**
 * ELI5: write down the result of a fetch attempt, WITHOUT ever erasing a
 * previous good payload just because this attempt failed.
 * WHY: `$payload === null` marks a failed/absent attempt — only
 * `HttpStatus` is touched on that branch. A successful fetch (`$payload`
 * non-null) upserts the full row. Mirrors intapps'
 * `_intappsCommitFetchResult()` invariant. Best-effort — never throws.
 */
function _iaCacheCommit(
    \mysqli $db,
    string $identifier,
    string $kind,
    string $fileName,
    ?string $payload,
    ?int $httpStatus,
    ?string $sha256 = null,
    int $byteSize = 0
): void {
    try {
        if ($payload !== null) {
            $stmt = $db->prepare(
                "INSERT INTO tblIaFetchCache (Identifier, Kind, FileName, HttpStatus, ByteSize, Sha256, Payload, FetchedAt)
                 VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE
                    HttpStatus = VALUES(HttpStatus), ByteSize = VALUES(ByteSize),
                    Sha256 = VALUES(Sha256), Payload = VALUES(Payload), FetchedAt = VALUES(FetchedAt)"
            );
            $sha = $sha256 ?? hash('sha256', $payload);
            $stmt->bind_param('sssiiss', $identifier, $kind, $fileName, $httpStatus, $byteSize, $sha, $payload);
            $stmt->execute();
            $stmt->close();
            return;
        }

        /* Failure: bump bookkeeping ONLY — Payload/FetchedAt/ByteSize/Sha256
           are deliberately absent from this UPDATE, so a stale-but-good
           payload from a prior success is never clobbered. */
        $stmt = $db->prepare(
            "INSERT INTO tblIaFetchCache (Identifier, Kind, FileName, HttpStatus)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE HttpStatus = VALUES(HttpStatus)"
        );
        $stmt->bind_param('sssi', $identifier, $kind, $fileName, $httpStatus);
        $stmt->execute();
        $stmt->close();
    } catch (\mysqli_sql_exception $e) {
        /* Cache bookkeeping is best-effort — never blocks the read path. */
    }
}

/**
 * ELI5: get the metadata, from the cache if we have a fresh enough copy,
 * otherwise for real — and remember what we learned either way.
 * WHY: cache-first with a TTL. `$forceRefresh` skips the cache read (the
 * page's "Force re-fetch" checkbox). Un-migrated install (table absent)
 * degrades straight through to `iaFetchMetadata()`, uncached — never throws.
 */
function iaCachedMetadata(\mysqli $db, string $identifier, bool $forceRefresh = false): ?array
{
    if (!iaIdentifierValid($identifier)) {
        return null;
    }

    $tableExists = _iaCacheTableExists($db);

    if ($tableExists && !$forceRefresh) {
        try {
            $kind = 'metadata';
            $stmt = $db->prepare(
                "SELECT Payload, FetchedAt FROM tblIaFetchCache
                  WHERE Identifier = ? AND Kind = ? AND FileName = ''
                  LIMIT 1"
            );
            $stmt->bind_param('ss', $identifier, $kind);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row !== null && $row['Payload'] !== null && $row['FetchedAt'] !== null) {
                $age = time() - (int)strtotime((string)$row['FetchedAt'] . ' UTC');
                if ($age < IA_METADATA_TTL_SECONDS) {
                    $decoded = json_decode((string)$row['Payload'], true);
                    if (is_array($decoded)) {
                        return $decoded;
                    }
                }
            }
        } catch (\mysqli_sql_exception $e) {
            $tableExists = false; /* degrade to uncached for the rest of this call */
        }
    }

    $fresh = iaFetchMetadata($identifier);
    if ($tableExists) {
        $json = $fresh !== null ? json_encode($fresh, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        _iaCacheCommit($db, $identifier, 'metadata', '', is_string($json) ? $json : null, $fresh !== null ? 200 : null);
    }
    return $fresh;
}

/**
 * ELI5: same idea as `iaCachedMetadata()` but for the (much bigger) OCR
 * full-text file, with its own longer TTL.
 *
 * @return array{text:string,fileName:string,sha256:string,fetchedAt:?string}|null
 */
function iaCachedFulltext(\mysqli $db, string $identifier, bool $forceRefresh = false): ?array
{
    if (!iaIdentifierValid($identifier)) {
        return null;
    }

    $tableExists = _iaCacheTableExists($db);

    if ($tableExists && !$forceRefresh) {
        try {
            $kind = 'fulltext';
            $stmt = $db->prepare(
                "SELECT FileName, Payload, FetchedAt FROM tblIaFetchCache
                  WHERE Identifier = ? AND Kind = ? AND Payload IS NOT NULL
                  ORDER BY FetchedAt DESC LIMIT 1"
            );
            $stmt->bind_param('ss', $identifier, $kind);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row !== null && $row['Payload'] !== null && $row['FetchedAt'] !== null) {
                $age = time() - (int)strtotime((string)$row['FetchedAt'] . ' UTC');
                if ($age < IA_FULLTEXT_TTL_SECONDS) {
                    return [
                        'text'      => (string)$row['Payload'],
                        'fileName'  => (string)$row['FileName'],
                        'sha256'    => hash('sha256', (string)$row['Payload']),
                        'fetchedAt' => (string)$row['FetchedAt'],
                    ];
                }
            }
        } catch (\mysqli_sql_exception $e) {
            $tableExists = false;
        }
    }

    $fresh = iaFetchFulltext($identifier);
    if ($tableExists) {
        _iaCacheCommit(
            $db,
            $identifier,
            'fulltext',
            $fresh !== null ? $fresh['fileName'] : '',
            $fresh !== null ? $fresh['text'] : null,
            $fresh !== null ? 200 : null,
            $fresh !== null ? $fresh['sha256'] : null,
            $fresh !== null ? $fresh['byteSize'] : 0
        );
    }
    if ($fresh === null) {
        return null;
    }
    return [
        'text'      => $fresh['text'],
        'fileName'  => $fresh['fileName'],
        'sha256'    => $fresh['sha256'],
        'fetchedAt' => null, /* just fetched this request — no stored FetchedAt to report */
    ];
}
