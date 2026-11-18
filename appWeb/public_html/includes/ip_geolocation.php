<?php

declare(strict_types=1);

/**
 * iHymns — IP → country geolocation (#1208)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * The activity log likes to show a little flag next to each sign-in —
 * "signed in from United Kingdom" — worked out purely from the visitor's
 * IP address, the same way a postal address tells you roughly where a
 * letter came from. This file is how that lookup happens WITHOUT ever
 * slowing down the actual sign-in: it tries the cheapest option first
 * (has this IP been looked up before? → check memory, then the database
 * cache) and only reaches for a slower option (a local MaxMind file, or —
 * for a background catch-up job only, never on the live sign-in path — an
 * external website) when it has to. A private IP (like 192.168.x.x) or one
 * that can't be resolved just quietly gives no country, never an error.
 *
 * Resolves a client IP to an ISO-3166-1 alpha-2 country for the activity log
 * (#1207's tblActivityLog.Country snapshot + the flag in the viewer). Layered so
 * the hot write path never blocks on the network:
 *
 *   1. Per-request memo   — same IP resolved once per request.
 *   2. DB cache           — tblIpReputation.{CountryCode,CountryName,GeoLookedUpAt}
 *                           (90-day TTL). Shared across requests + environments.
 *   3. MaxMind GeoLite2   — a LOCAL .mmdb lookup (fast, offline, no rate limit).
 *                           Used on BOTH the write path + backfill when present.
 *                           Seam only here — the reader + the db file land with
 *                           the MaxMind follow-up; absent → this step is skipped.
 *   4. External API chain — ip-api.com → ipwho.is → ipapi.co, fail-soft, SHORT
 *                           timeout. ONLY when $allowExternal (never on the write
 *                           path — the activity-log viewer backfills async).
 *
 * Private / reserved / invalid IPs resolve to null (no geo, not cached).
 *
 * @requires PHP 8.4+ — project targets 8.5.
 */

/* #2137 review round 7 (the sixth independent review) — songRelocateIsTransactionFatal(),
   the ONE list of database errors that have already ended the caller's whole
   transaction (a deadlock, a lock wait timeout, MariaDB's 1020). Every catch in
   this file that a song save, the v2 editor, the works or songbooks admin page
   or an importer can reach from inside its transaction starts by passing those
   back to its caller (the full list is in DEV_NOTES.md). Loaded here, at the
   top, so that check can always be a catch's first line. It lives in
   transaction_fatal.php, which loads nothing else (song_relocate.php, its old
   home, also loads the database layer). */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'transaction_fatal.php';

if (!function_exists('getDbMysqli')) {
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'db_mysql.php';
}

/** Cache freshness window for a resolved (or definitively-unknown) IP. */
const IHYMNS_GEO_TTL_DAYS = 90;

/** Path to the MaxMind GeoLite2-Country database, when the follow-up has
 *  installed it (kept OUTSIDE the web root; the deploy/cron drops it here).
 *  #2137 review round 8: a deploy or a test may point it elsewhere by
 *  defining IHYMNS_GEO_MMDB_PATH before this file loads (tests/php/
 *  test-activity-log-outside-transaction.php and test-song-save-whole-
 *  rollback.php point it at a stand-in, so the cache write below can be
 *  tested without the real database file). */
if (!defined('IHYMNS_GEO_MMDB_PATH')) {
    define('IHYMNS_GEO_MMDB_PATH', __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR
        . '..' . DIRECTORY_SEPARATOR . '.geoip' . DIRECTORY_SEPARATOR . 'GeoLite2-Country.mmdb');
}

/**
 * Resolve an IP to ['code' => 'GB', 'name' => 'United Kingdom', 'source' => …]
 * or null. $allowExternal gates the network providers (false on the write path).
 *
 * ELI5: the main entry point — "where is this IP address roughly located?"
 * Tries memory, then the database cache, then a local file, and only
 * reaches out to the internet when $allowExternal says that's OK (never
 * true on the hot sign-in path — a slow/unreachable external service must
 * never make someone's sign-in hang).
 *
 * @return array{code:string,name:string,source:string}|null
 */
function ihymnsGeoLookup(string $ip, bool $allowExternal = false): ?array
{
    static $memo = [];   // per-request memo (logActivity may fire many times)
    $ip = trim($ip);
    if ($ip === '') {
        return null;
    }
    $memoKey = ($allowExternal ? 'x:' : 'c:') . $ip;
    if (array_key_exists($memoKey, $memo)) {
        return $memo[$memoKey];
    }

    /* Only public, routable IPs have a meaningful country. */
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return $memo[$memoKey] = null;
    }

    $db = getDbMysqli();

    /* 1/2 — DB cache (fresh within TTL). A non-null GeoLookedUpAt means we've
       resolved (or definitively failed to) recently; trust it. */
    $cached = ihymnsGeoCacheGet($db, $ip);
    if ($cached !== null) {
        return $memo[$memoKey] = ($cached['code'] !== '' ? $cached : null);
    }

    /* 3 — MaxMind local (always allowed — it's a fast file read). */
    $hit = ihymnsGeoViaMaxmind($ip);
    if ($hit !== null) {
        ihymnsGeoCachePut($db, $ip, $hit['code'], $hit['name'], 'maxmind');
        return $memo[$memoKey] = $hit;
    }

    /* 4 — external API chain (backfill / on-demand only). */
    if ($allowExternal) {
        $hit = ihymnsGeoViaApiChain($ip);
        if ($hit !== null) {
            ihymnsGeoCachePut($db, $ip, $hit['code'], $hit['name'], $hit['source']);
            return $memo[$memoKey] = ['code' => $hit['code'], 'name' => $hit['name'], 'source' => $hit['source']];
        }
        /* All providers failed/timed out → DON'T cache (so a transient outage
           retries next time), just return null for this request. */
    }

    return $memo[$memoKey] = null;
}

/**
 * Read the geo cache for an IP. Returns ['code','name','source'] when a fresh
 * row exists (code '' = "looked up, no country"), or null when absent/stale.
 *
 * @return array{code:string,name:string,source:string}|null
 */
function ihymnsGeoCacheGet(mysqli $db, string $ip): ?array
{
    try {
        $stmt = $db->prepare(
            'SELECT CountryCode, CountryName, GeoLookedUpAt
               FROM tblIpReputation
              WHERE IpAddress = ?
                AND GeoLookedUpAt IS NOT NULL
                AND GeoLookedUpAt > (NOW() - INTERVAL ? DAY)
              LIMIT 1'
        );
        $ttl = IHYMNS_GEO_TTL_DAYS;
        $stmt->bind_param('si', $ip, $ttl);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return null;
        }
        return [
            'code'   => (string)($row['CountryCode'] ?? ''),
            'name'   => (string)($row['CountryName'] ?? ''),
            'source' => 'cache',
        ];
    } catch (\Throwable $_e) {
        if (songRelocateIsTransactionFatal($_e)) { throw $_e; }   /* #2137 review round 7: never swallow an error that has ended the transaction */
        return null;   // pre-migration / probe failure → treat as a miss
    }
}

/**
 * Upsert a geo result into tblIpReputation (IpAddress PK; other columns keep
 * their defaults / existing proxy values). Best-effort.
 *
 * NEVER INSIDE A TRANSACTION (#2137 review round 8 — the seventh independent
 * review's L2, the lead's decision). This row is shared: any request from the
 * same address may write it at the same moment. logActivity() looks the
 * country up while it writes its row, and a song save writes its activity row
 * INSIDE its own transaction — so the cache write used to run inside the save.
 * On MariaDB, when another request wrote the same address's row after the
 * save had started reading, the save's write met error 1020 ("Record has
 * changed since last read"), which ends the WHOLE transaction: the curator was
 * told "Failed to save song" over a best-effort cache. (On MySQL the write
 * waited for the other request's lock instead, inside the save.) So while a
 * transaction is open on this connection — or when that cannot be told — this
 * writes nothing; the lookup still answered from the local country database.
 * Asked with dbTransactionIsOpen() (includes/transaction_fatal.php, which says
 * how and why), BEFORE the write: after a 1020 the transaction has already
 * ended, so asking afterwards would answer "not open".
 *
 * WHEN THE SKIPPED ROW IS WRITTEN (#2137 review round 9 — the eighth review's
 * L10 corrected what this used to say): by a LATER request from that address
 * whose first lookup of it is made outside a transaction. Not by a later
 * lookup in the same request — ihymnsGeoLookup() remembers its answer for the
 * rest of the request and writes nothing more. And not by the activity-log
 * viewer's catch-up (activityLogGeoResolveIps()): the viewer sends only the
 * addresses whose shown rows have no country, and a row written inside the
 * save already has one, from the local country database.
 *
 * NEVER FAILS THE REQUEST (#2137 review round 9 — the eighth review's L1, the
 * lead's decision 1). Because of the early return above, the write below runs
 * only when no transaction is open, so a deadlock or a lock wait timeout on
 * it has ended nothing but this one cache row (with autocommit switched off,
 * the transaction it would open holds nothing but this row). So its catch
 * logs every error and carries on; it does not start with the shared
 * "pass back an error that has ended the transaction" guard, and it is on the
 * allow-list of tests/php/test-transaction-catch-audit.php with this reason.
 * Before round 9 that guard passed such an error back: outside any
 * transaction, another request holding the same address's cache row for a
 * moment cost logActivity() its whole activity row, and failed the admin
 * "geolocate" request (tests/php/test-activity-log-outside-transaction.php,
 * C4 and C5).
 */
function ihymnsGeoCachePut(mysqli $db, string $ip, string $code, string $name, string $source): void
{
    if (dbTransactionIsOpen($db) !== false) {
        return;
    }
    try {
        $code = strtoupper(substr($code, 0, 2));
        $name = substr($name, 0, 100);
        $src  = substr($source, 0, 50);
        $stmt = $db->prepare(
            'INSERT INTO tblIpReputation (IpAddress, CountryCode, CountryName, GeoLookedUpAt, Source)
                 VALUES (?, ?, ?, NOW(), ?)
             ON DUPLICATE KEY UPDATE
                 CountryCode = VALUES(CountryCode),
                 CountryName = VALUES(CountryName),
                 GeoLookedUpAt = NOW()'
        );
        $stmt->bind_param('ssss', $ip, $code, $name, $src);
        $stmt->execute();
        $stmt->close();
    } catch (\Throwable $_e) {
        /* Best-effort cache write, run only outside a transaction (see "NEVER
           FAILS THE REQUEST" above): log it and carry on, whatever it was. */
        error_log('[ip_geolocation] cache write failed: ' . $_e->getMessage());
    }
}

/**
 * MaxMind GeoLite2-Country local lookup. SEAM: returns null unless both the
 * .mmdb file AND a reader are present (they land with the MaxMind follow-up).
 * Supports the maxminddb PHP extension if installed; otherwise no-ops until a
 * pure-PHP reader is vendored.
 *
 * @return array{code:string,name:string,source:string}|null
 */
function ihymnsGeoViaMaxmind(string $ip): ?array
{
    if (!is_file(IHYMNS_GEO_MMDB_PATH)) {
        return null;
    }
    /* Native extension path (fastest); the pure-PHP reader vendor lands later. */
    if (class_exists('\\MaxMind\\Db\\Reader')) {
        try {
            static $reader = null;
            if ($reader === null) {
                $reader = new \MaxMind\Db\Reader(IHYMNS_GEO_MMDB_PATH);
            }
            $rec = $reader->get($ip);
            $code = is_array($rec) ? (string)($rec['country']['iso_code'] ?? '') : '';
            if ($code === '') {
                return null;
            }
            $name = is_array($rec) ? (string)($rec['country']['names']['en'] ?? $code) : $code;
            return ['code' => strtoupper($code), 'name' => $name, 'source' => 'maxmind'];
        } catch (\Throwable $_e) {
            if (songRelocateIsTransactionFatal($_e)) { throw $_e; }   /* #2137 review round 8: the guard here too, although nothing in this try touches the database today — so a later change to it cannot make this catch swallow an error that has ended the transaction */
            return null;
        }
    }
    return null;
}

/**
 * External provider chain — first success wins. Each provider is fail-soft with
 * a short timeout so one slow/dead service can't stall a backfill.
 *
 * @return array{code:string,name:string,source:string}|null
 */
function ihymnsGeoViaApiChain(string $ip): ?array
{
    $providers = [
        ['source' => 'ip-api',  'url' => 'http://ip-api.com/json/' . rawurlencode($ip) . '?fields=status,countryCode,country',
         'code' => 'countryCode', 'name' => 'country',      'ok' => ['status' => 'success']],
        ['source' => 'ipwho',   'url' => 'https://ipwho.is/' . rawurlencode($ip) . '?fields=success,country_code,country',
         'code' => 'country_code', 'name' => 'country',     'ok' => ['success' => true]],
        ['source' => 'ipapi',   'url' => 'https://ipapi.co/' . rawurlencode($ip) . '/json/',
         'code' => 'country_code', 'name' => 'country_name', 'ok' => []],
    ];
    foreach ($providers as $p) {
        $json = ihymnsGeoHttpGetJson($p['url']);
        if ($json === null) {
            continue;
        }
        foreach ($p['ok'] as $k => $v) {
            if (($json[$k] ?? null) !== $v) {
                continue 2;   // provider-level failure marker → next provider
            }
        }
        $code = strtoupper((string)($json[$p['code']] ?? ''));
        if (strlen($code) !== 2 || !ctype_alpha($code)) {
            continue;
        }
        $name = (string)($json[$p['name']] ?? $code);
        return ['code' => $code, 'name' => $name, 'source' => $p['source']];
    }
    return null;
}

/**
 * Fail-soft JSON GET with a short timeout. Returns the decoded array or null.
 */
function ihymnsGeoHttpGetJson(string $url): ?array
{
    $body = null;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT        => 3,
            CURLOPT_USERAGENT      => 'iHymns-geo/1.0 (+https://ihymns.app)',
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $resp = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($resp !== false && $httpCode >= 200 && $httpCode < 300) {
            $body = (string)$resp;
        }
    } else {
        $ctx = stream_context_create(['http' => [
            'timeout' => 3,
            'header'  => "User-Agent: iHymns-geo/1.0 (+https://ihymns.app)\r\n",
        ]]);
        $resp = @file_get_contents($url, false, $ctx);
        if ($resp !== false) {
            $body = (string)$resp;
        }
    }
    if ($body === null || $body === '') {
        return null;
    }
    $decoded = json_decode($body, true);
    return is_array($decoded) ? $decoded : null;
}
