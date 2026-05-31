<?php

declare(strict_types=1);

/**
 * iHymns — Places API (live geocoder proxy + tblPlaces upsert)
 *
 * Routes the admin's location-autocomplete UX through a single
 * backend so we control:
 *   - The User-Agent (Nominatim ToS requires a contact-bearing UA)
 *   - Response caching (file cache under data_share/place_cache)
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
 *   GET  ?action=get&id=<int>
 *       Fetch a single place row. Used by edit forms that want to
 *       re-render a chip from a stored FK.
 *
 * Auth: editor+ (read + write). Curators are the only audience.
 */

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';

header('Content-Type: application/json; charset=UTF-8');

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required.']);
    exit;
}

$currentUser = getCurrentUser();
if (!$currentUser || !hasRole($currentUser['role'], 'editor')) {
    http_response_code(403);
    echo json_encode(['error' => 'Editor access required.']);
    exit;
}

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'public_html' . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_mysql.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'public_html' . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'places.php';

/* =========================================================================
 * Configuration
 * ========================================================================= */

const PLACES_PHOTON_URL    = 'https://photon.komoot.io/api/';
const PLACES_NOMINATIM_URL = 'https://nominatim.openstreetmap.org/search';
const PLACES_SEARCH_TTL    = 86400 * 7;   // 7 days — search results are stable
const PLACES_HTTP_TIMEOUT  = 6;            // seconds per upstream call
const PLACES_MAX_LIMIT     = 12;
const PLACES_DEFAULT_LIMIT = 8;

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

    if ($method === 'GET' && $action === 'get') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'id is required.']);
            exit;
        }
        $db = getDbMysqli();
        if (!$db) {
            http_response_code(500);
            echo json_encode(['error' => 'Database connection failed.']);
            exit;
        }
        $row = placesLoadById($db, $id);
        if ($row === null) {
            http_response_code(404);
            echo json_encode(['error' => 'Place not found.']);
            exit;
        }
        echo json_encode(['place' => $row]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Unknown action.']);
    exit;
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Internal error.',
        /* Detail only when the global debug flag is on — matches
           the convention used elsewhere in /manage/ APIs. */
        'detail' => (defined('IHYMNS_DEBUG') && IHYMNS_DEBUG) ? $e->getMessage() : null,
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
    $cacheFile = $cacheDir . DIRECTORY_SEPARATOR . substr($cacheKey, 0, 2) . DIRECTORY_SEPARATOR . $cacheKey . '.json';
    $cached    = _placesCacheRead($cacheFile);
    if ($cached !== null) return $cached;

    /* Photon first. */
    $results = _placesFetchPhoton($query, $limit, $userAgent);
    if (!$results) {
        $results = _placesFetchNominatim($query, $limit, $userAgent);
    }

    _placesCacheWrite($cacheFile, $results);
    return $results;
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

function _placesPhotonDisplayName(array $props): string
{
    $parts = array_filter([
        $props['name']    ?? null,
        $props['city']    ?? null,
        $props['state']   ?? null,
        $props['country'] ?? null,
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
