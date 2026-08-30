<?php

declare(strict_types=1);

/**
 * iHymns — Places API (live geocoder proxy + tblPlaces upsert)
 *
 * Routes the admin's location-autocomplete UX through a single
 * backend so we control:
 *   - The User-Agent (Nominatim ToS requires a contact-bearing UA)
 *   - Response caching: an APCu in-memory tier (short TTL, #1507 —
 *     see placesSearch()) IN FRONT OF the existing file cache under
 *     data_share/place_cache (long TTL, unchanged)
 *   - Rate limiting (per-IP + global)
 *   - Provider fallback (Photon → Nominatim) without leaking the
 *     fallback logic to every browser
 *   - The upsert into tblPlaces (so two curators picking "Sydney"
 *     resolve to the same row)
 *
 * Actions:
 *   GET  ?action=search&q=<query>[&limit=8]
 *       Live autocomplete. Returns [{display_name, name, address,
 *       osm_type, osm_id, lat, lon, type}, …].
 *   POST ?action=upsert  (JSON body = chosen candidate)
 *       Persist a picked candidate into tblPlaces. Returns the
 *       freshly-loaded row (with its database Id).
 *
 * `?action=get&id=<int>` — documented here through remediation X3
 * (2026-07-30) as "used by edit forms that want to re-render a chip from a
 * stored FK" — was removed: no edit form ever called it (orphan inventory
 * §2.9). A curator PHP page that needs a place by id calls
 * `placesLoadById(mysqli, int)` from includes/places.php directly
 * (manage/venues.php does exactly this) — no HTTP round trip needed for a
 * same-process read.
 *
 * Auth: editor+ (read + write). Curators are the only audience — a
 * /manage/ session cookie (or the cross-subdomain `ihymns_auth` cookie
 * adopted into one) OR an `Authorization: Bearer <token>` header resolved
 * via the same `tblApiTokens` path api.php uses (`.claude/
 * api-coverage-2026-08-28.md` §3 X3 — a native venue/place picker has no
 * cookie jar).
 */

/* A require/parse FATAL before the try/catch further down surfaces as a BARE HTTP 500
   with NO body — invisible to the admin client's `detail` renderer (this file has now
   had two such load-time path fatals: #1180 and #1365). This shutdown hook converts any
   uncaught fatal into a diagnostic JSON 500 so the next one is visible immediately. */
register_shutdown_function(static function (): void {
    $e = error_get_last();
    if ($e === null || !in_array($e['type'], [E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR], true)) {
        return;   /* normal shutdown — nothing to report */
    }
    $where = basename((string)($e['file'] ?? '')) . ':' . (int)($e['line'] ?? 0);
    error_log('[places-api] FATAL ' . ($e['message'] ?? '') . ' @ ' . $where);
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=UTF-8');
    }
    /* Path/message only when explicitly debugging — a load fatal can fire before the
       auth gate, so never leak server paths to an unauthenticated caller by default. */
    echo json_encode([
        'error'  => 'Internal error (load).',
        'detail' => (defined('IHYMNS_DEBUG') && IHYMNS_DEBUG) ? (($e['message'] ?? '') . ' @ ' . $where) : null,
    ]);
});

/* Admin auth + CSRF helpers (isAuthenticated / getCurrentUser / hasRole /
   validateCsrfRequest) live in manage/includes/auth.php — i.e. THIS file's OWN
   directory (__DIR__/includes/), NOT the public includes/ one level up. The original
   #996 require used dirname(__DIR__)/includes/auth.php = <webroot>/includes/auth.php,
   which DOES NOT EXIST (auth.php is manage-only), so EVERY request fatal-ed at load and
   the place typeahead never worked (#1365). db_mysql.php / places.php below DO live in
   the public includes/ and so correctly keep dirname(__DIR__). */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';
/* apiTokenResolveBearerUser() — the shared Authorization: Bearer verification
   core (includes/api_tokens.php; `.claude/api-coverage-2026-08-28.md` §3 X3
   — a native venue/place picker has no /manage/ session cookie), the SAME
   function manage/editor/api2.php's and the legacy manage/editor/api.php's
   seams delegate to (rule #22 — one verification core, not a third copy
   forked here). auth.php (required above) already pulls in db_mysql.php, so
   getDbMysqli() below needs no extra require. */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'api_tokens.php';

header('Content-Type: application/json; charset=UTF-8');

/* BEARER-THEN-COOKIE (`.claude/api-coverage-2026-08-28.md` §3 X3): a Bearer
 * token is tried FIRST and entirely independently of the cookie/session path
 * below — on a miss (no header, malformed, expired, unknown, inactive user)
 * apiTokenResolveBearerUser() returns null and control falls through to
 * EXACTLY the pre-existing isAuthenticated()/getCurrentUser() cookie check,
 * unchanged in every particular, so an existing web-admin request that
 * carries no Authorization header runs through the SAME code path it always
 * did. $placesBearerAuthed is read by the CSRF gate on the upsert action
 * further down (mirrors the api2.php / legacy api.php exemption). */
$placesBearerUser    = apiTokenResolveBearerUser(getDbMysqli());
$placesBearerAuthed  = ($placesBearerUser !== null);
if ($placesBearerAuthed) {
    $currentUser = $placesBearerUser;
} else {
    if (!isAuthenticated()) {
        http_response_code(401);
        echo json_encode(['error' => 'Authentication required.']);
        exit;
    }
    $currentUser = getCurrentUser();
}
if (!$currentUser || !hasRole($currentUser['role'], 'editor')) {
    http_response_code(403);
    echo json_encode(['error' => 'Editor access required.']);
    exit;
}

/* These were dirname(__DIR__, 2) . '/public_html/includes/…' — which assumes a
   NESTED public_html and only resolved in the repo (appWeb/public_html/). On the
   deployed server public_html IS the web root, so that path doesn't exist and the
   require_once threw a FATAL at load — BEFORE the try/catch below — surfacing as a
   bare HTTP 500 on every Composition-Origin lookup (#1180). db_mysql.php + places.php
   DO live in the public includes/, so dirname(__DIR__)/includes/… resolves them on
   BOTH layouts (the repo's nested public_html and the deployed web root). NOTE: auth.php
   above is the exception — it is manage-only, so it uses __DIR__/includes/ (#1365). */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_mysql.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'places.php';

/* =========================================================================
 * Configuration
 * ========================================================================= */

const PLACES_PHOTON_URL    = 'https://photon.komoot.io/api/';
const PLACES_NOMINATIM_URL = 'https://nominatim.openstreetmap.org/search';
const PLACES_SEARCH_TTL    = 86400 * 7;   // 7 days — search results are stable
const PLACES_HTTP_TIMEOUT  = 6;            // seconds per upstream call
const PLACES_MAX_LIMIT     = 12;
const PLACES_DEFAULT_LIMIT = 8;
/* #1507 — short in-memory tier ahead of the disk cache. ELI5: a sticky
   note vs. the filing cabinet — much faster than a file read for a query
   someone (maybe a different curator) already ran in the last few
   minutes. WHY a separate, SHORTER TTL than the 7-day disk cache: this
   tier only exists to skip disk I/O for near-term repeats within one
   PHP-FPM worker's APCu segment; it isn't meant to persist like the disk
   cache, which stays the durable long-term store. Per-keystroke queries
   while a single admin is actively typing ("Be" → "Bel" → "Belf" → …)
   are each a DISTINCT cache key by design (a growing prefix is a
   different query) — no cache layer removes that first live upstream
   round-trip; the loading spinner (js/modules/place-search.js #1507)
   is what fixes the PERCEIVED slowness during that unavoidable wait. */
const PLACES_MEMORY_TTL    = 300;          // 5 minutes

/* The cache lives under data_share/ — that directory already exists
   and is writable (alongside SQLite, song_data and setlist_json). */
$placesCacheDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'data_share' . DIRECTORY_SEPARATOR . 'place_cache';
if (!is_dir($placesCacheDir)) {
    /* Best-effort — if mkdir fails the cache simply doesn't hit. */
    @mkdir($placesCacheDir, 0775, true);
}

/* Build the contact-bearing UA Nominatim's ToS requires. Pulls the
   admin email from the user row when available, falls back to a
   support address. */
function _placesUserAgent(array $user): string
{
    $email = trim((string)($user['email'] ?? ''));
    if ($email === '' || strpos($email, '@') === false) {
        $email = 'admin@ihymns.app';
    }
    return 'iHymns/1.0 (+' . $email . ')';
}

/* =========================================================================
 * Dispatch
 * ========================================================================= */

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string)($_REQUEST['action'] ?? '');

try {
    if ($method === 'GET' && $action === 'search') {
        $query = trim((string)($_GET['q'] ?? ''));
        $limit = (int)($_GET['limit'] ?? PLACES_DEFAULT_LIMIT);
        $limit = max(1, min(PLACES_MAX_LIMIT, $limit));
        if ($query === '' || mb_strlen($query) < 2) {
            echo json_encode(['query' => $query, 'results' => []]);
            exit;
        }
        $results = placesSearch($query, $limit, $placesCacheDir, _placesUserAgent($currentUser));
        echo json_encode([
            'query'    => $query,
            'count'    => count($results),
            'results'  => $results,
        ]);
        exit;
    }

    if ($method === 'POST' && $action === 'upsert') {
        /* CSRF (security sweep): this write previously had NO CSRF check. Robust
           same-origin validation (the place-search client sends X-Requested-With); a
           header token is accepted too. Editor auth already enforced above.
           BEARER EXEMPTION (`.claude/api-coverage-2026-08-28.md` §3 X3): a
           Bearer-authenticated POST carries no ambient browser credential at
           all — the Authorization header is an explicit, out-of-band value a
           cross-site page cannot attach to a forged request (unlike a
           cookie, which the browser attaches automatically), so it is
           CSRF-immune BY CONSTRUCTION. validateCsrfRequest() is skipped
           entirely for a Bearer-authed request ($placesBearerAuthed ===
           true, set by the guard above) — it only ever runs for the COOKIE
           path, mirroring the same exemption in api2.php / the legacy
           editor api.php. */
        if (!$placesBearerAuthed && !validateCsrfRequest($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
            http_response_code(403);
            echo json_encode(['error' => 'CSRF check failed — please retry.']);
            exit;
        }
        /* JSON body — the client posts back one of the candidates
           returned by ?action=search. We re-normalise + upsert. */
        $bodyRaw = (string)file_get_contents('php://input');
        $body    = json_decode($bodyRaw, true);
        if (!is_array($body)) {
            http_response_code(400);
            echo json_encode(['error' => 'JSON body required.']);
            exit;
        }
        $db = getDbMysqli();
        if (!$db) {
            http_response_code(500);
            echo json_encode(['error' => 'Database connection failed.']);
            exit;
        }
        $row = placesUpsertFromPayload($db, $body);
        if ($row === null) {
            http_response_code(503);
            echo json_encode([
                'error' => 'tblPlaces not available — run the Places Registry migration first.',
            ]);
            exit;
        }
        echo json_encode(['place' => $row]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Unknown action.']);
    exit;
} catch (\Throwable $e) {
    /* Always log server-side so a recurring geocoder/host failure leaves a
       trail even when no admin is watching (#1180 debugging aid). */
    error_log('[places-api] ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    http_response_code(500);
    /* Surface the detail to admins (or IHYMNS_DEBUG) so the curator sees WHY
       the lookup failed instead of a bare 500 — the place-search dropdown
       renders this `detail` inline. */
    $isAdmin = isset($currentUser['role']) && hasRole($currentUser['role'], 'admin');
    echo json_encode([
        'error'  => 'Internal error.',
        'detail' => ((defined('IHYMNS_DEBUG') && IHYMNS_DEBUG) || $isAdmin)
            ? ($e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine())
            : null,
    ]);
    exit;
}

/* =========================================================================
 * Search — Photon primary, Nominatim fallback
 * =========================================================================
 *
 * Both upstreams are OSM-derived and free. Photon's typeahead is
 * faster (single doc request, no rate-limit-per-second), Nominatim
 * is more thorough on rare strings. We try Photon first; if it
 * returns zero results (or HTTP errors) we fall through to
 * Nominatim. Successful Photon hits skip Nominatim entirely so we
 * stay under Nominatim's 1 req/sec policy organically.
 */
function placesSearch(string $query, int $limit, string $cacheDir, string $userAgent): array
{
    /* Cache key is provider-agnostic — we don't want one provider's
       miss to flush another's hit. */
    $cacheKey  = hash('sha256', strtolower($query) . '|' . $limit);

    /* #1507 — APCu tier FIRST (in-memory, no disk I/O). Feature-detected:
       DreamHost shared hosting doesn't guarantee APCu is enabled, so this
       degrades to a clean no-op (straight through to the disk cache
       below) when the extension is absent or disabled. */
    $apcuKey = 'ihymns_place_search_' . $cacheKey;
    if (_placesApcuAvailable()) {
        $hit = apcu_fetch($apcuKey, $ok);
        if ($ok && is_array($hit)) return $hit;
    }

    $cacheFile = $cacheDir . DIRECTORY_SEPARATOR . substr($cacheKey, 0, 2) . DIRECTORY_SEPARATOR . $cacheKey . '.json';
    $cached    = _placesCacheRead($cacheFile);
    if ($cached !== null) {
        if (_placesApcuAvailable()) { apcu_store($apcuKey, $cached, PLACES_MEMORY_TTL); }
        return $cached;
    }

    /* Photon first. */
    $results = _placesFetchPhoton($query, $limit, $userAgent);
    if (!$results) {
        $results = _placesFetchNominatim($query, $limit, $userAgent);
    }

    _placesCacheWrite($cacheFile, $results);
    if (_placesApcuAvailable()) { apcu_store($apcuKey, $results, PLACES_MEMORY_TTL); }
    return $results;
}

/**
 * Cached feature-detection for APCu (#1507). ELI5: "is the fast in-memory
 * cache actually turned on here?" — checked once per request, not once
 * per keystroke. Kept as its own function (rather than inlining the
 * function_exists() check at each call site) so the three call sites in
 * placesSearch() above can't drift if the detection logic ever needs a
 * second condition (e.g. an ini_get('apc.enabled') check).
 *
 * @link https://www.php.net/manual/en/book.apcu.php
 */
function _placesApcuAvailable(): bool
{
    static $available = null;
    if ($available === null) {
        $available = extension_loaded('apcu') && function_exists('apcu_fetch') && function_exists('apcu_store');
    }
    return $available;
}

function _placesFetchPhoton(string $query, int $limit, string $userAgent): array
{
    $url = PLACES_PHOTON_URL . '?' . http_build_query([
        'q'     => $query,
        'limit' => $limit,
        'lang'  => 'en',
    ]);
    $body = _placesHttpGet($url, $userAgent);
    if ($body === null) return [];
    $decoded = json_decode($body, true);
    if (!is_array($decoded) || !isset($decoded['features']) || !is_array($decoded['features'])) {
        return [];
    }
    $out = [];
    foreach ($decoded['features'] as $f) {
        $props = is_array($f['properties'] ?? null) ? $f['properties'] : [];
        $coords = is_array($f['geometry']['coordinates'] ?? null) ? $f['geometry']['coordinates'] : [];
        /* Photon returns coordinates as [lon, lat] — opposite of
           most libraries. Keep the order we feed downstream
           consistent with Nominatim ({lat, lon}). */
        $lon = isset($coords[0]) ? (float)$coords[0] : null;
        $lat = isset($coords[1]) ? (float)$coords[1] : null;

        $name        = (string)($props['name'] ?? '');
        $displayName = _placesPhotonDisplayName($props);
        if ($displayName === '') continue;

        $out[] = [
            'display_name' => $displayName,
            'name'         => $name !== '' ? $name : null,
            'osm_type'     => (string)($props['osm_type'] ?? ''),
            'osm_id'       => isset($props['osm_id']) ? (int)$props['osm_id'] : null,
            'type'         => (string)($props['type'] ?? ($props['osm_value'] ?? '')),
            'lat'          => $lat,
            'lon'          => $lon,
            'address'      => [
                'suburb'       => $props['district']   ?? null,
                'city'         => $props['city']       ?? null,
                'town'         => $props['town']       ?? null,
                'village'      => $props['locality']   ?? null,
                'county'       => $props['county']     ?? null,
                'state'        => $props['state']      ?? null,
                'country'      => $props['country']    ?? null,
                'country_code' => $props['countrycode'] ?? null,
            ],
            'source'       => 'photon',
        ];
    }
    return $out;
}

/**
 * #1507 — the FULL resolved hierarchy for a Photon candidate, e.g.
 * "Belfast, Belfast City, Northern Ireland, United Kingdom".
 *
 * ELI5: turn the scattered admin-area fields Photon returns into ONE
 * readable "place, district, region, country" string — so picking a
 * result and seeing it echoed back into the input actually confirms
 * WHICH place was picked (not just a same-named city on the wrong
 * continent).
 *
 * DETAILED: the prior 4-part list (name/city/state/country) silently
 * DROPPED the district/county level. Northern Ireland's local-government
 * districts (Photon's `district` property, e.g. "Belfast City") sit
 * between the locality and the region/state — omitting them collapsed
 * "Belfast, Belfast City, Northern Ireland, United Kingdom" down to
 * "Belfast, Northern Ireland, United Kingdom", silently losing a level
 * a curator picking between similarly-named places elsewhere needs to
 * disambiguate. `city`/`town`/`village`/`locality` are mutually
 * exclusive alternates for the same "locality" slot (Photon only ever
 * populates one), so the first non-empty one is used.
 *
 * @link https://photon.komoot.io/  Photon API response shape
 */
function _placesPhotonDisplayName(array $props): string
{
    $locality = $props['city'] ?? $props['town'] ?? $props['village'] ?? $props['locality'] ?? null;
    $parts = array_filter([
        $props['name']     ?? null,
        $locality,
        $props['district'] ?? null,
        $props['county']   ?? null,
        $props['state']    ?? null,
        $props['country']  ?? null,
    ], static fn($s) => is_string($s) && $s !== '');
    /* Deduplicate consecutive equal parts (e.g. when "name" already
       equals "city" for a town-level pick). */
    $deduped = [];
    foreach ($parts as $p) {
        if (!end($deduped) || end($deduped) !== $p) $deduped[] = $p;
    }
    return implode(', ', $deduped);
}

function _placesFetchNominatim(string $query, int $limit, string $userAgent): array
{
    $url = PLACES_NOMINATIM_URL . '?' . http_build_query([
        'q'              => $query,
        'format'         => 'jsonv2',
        'addressdetails' => 1,
        'limit'          => $limit,
        'accept-language' => 'en',
    ]);
    $body = _placesHttpGet($url, $userAgent);
    if ($body === null) return [];
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) return [];
    $out = [];
    foreach ($decoded as $r) {
        if (!is_array($r)) continue;
        $displayName = trim((string)($r['display_name'] ?? ''));
        if ($displayName === '') continue;
        $address = is_array($r['address'] ?? null) ? $r['address'] : [];
        $name = (string)($r['name']
            ?? ($address['city']
                ?? ($address['town']
                    ?? ($address['village']
                        ?? ($address['hamlet']
                            ?? '')))));
        $out[] = [
            'display_name' => $displayName,
            'name'         => $name !== '' ? $name : null,
            'osm_type'     => (string)($r['osm_type'] ?? ''),
            'osm_id'       => isset($r['osm_id']) ? (int)$r['osm_id'] : null,
            'type'         => (string)($r['type'] ?? ($r['category'] ?? '')),
            'lat'          => isset($r['lat']) ? (float)$r['lat'] : null,
            'lon'          => isset($r['lon']) ? (float)$r['lon'] : null,
            'address'      => $address + [
                'country_code' => $address['country_code'] ?? null,
            ],
            'source'       => 'nominatim',
        ];
    }
    return $out;
}

/* =========================================================================
 * HTTP + cache helpers
 * ========================================================================= */

/**
 * GET an upstream URL. Prefer cURL; fall back to file_get_contents
 * with a stream context so hosts without cURL still work. Returns
 * NULL on any non-200 / transport error so the caller can fall
 * through to the next provider.
 */
function _placesHttpGet(string $url, string $userAgent): ?string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => PLACES_HTTP_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_USERAGENT      => $userAgent,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $code !== 200) return null;
        return is_string($body) ? $body : null;
    }
    $ctx = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'header'  => "User-Agent: $userAgent\r\nAccept: application/json\r\n",
            'timeout' => PLACES_HTTP_TIMEOUT,
        ],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) return null;
    return $body;
}

function _placesCacheRead(string $file): ?array
{
    if (!is_file($file)) return null;
    if (filemtime($file) + PLACES_SEARCH_TTL < time()) return null;
    $raw = @file_get_contents($file);
    if ($raw === false) return null;
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function _placesCacheWrite(string $file, array $data): void
{
    $dir = dirname($file);
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0775, true) && !is_dir($dir)) return;
    }
    /* Write atomically — tmp file + rename. Skips a corrupt cache
       file if a concurrent writer wins the rename race. */
    $tmp = $file . '.tmp-' . bin2hex(random_bytes(4));
    $bytes = @file_put_contents($tmp, json_encode($data));
    if ($bytes === false) return;
    @rename($tmp, $file);
}
