<?php

declare(strict_types=1);

/**
 * iHymns — Activity Log async IP-geolocation backfill core (API-coverage
 * Batch 5, A16, `.claude/api-coverage-2026-08-28.md` §4.3/§9).
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * The Activity Log page shows a little flag next to each IP address. The
 * flag isn't looked up until the row actually scrolls into view — the
 * browser POSTs a batch of IPs it needs and this is the code that resolves
 * them (cache -> MaxMind -> API chain, via the existing `ihymnsGeoLookup()`,
 * #1208) and snapshots the result onto matching `tblActivityLog` rows so
 * the NEXT view needs no lookup at all. This file pulls that batch-resolve
 * loop out of the page so `api.php`'s new `admin_ip_geolocate` action can
 * offer the SAME resolve-and-snapshot behaviour to a native admin client.
 *
 * DETAILED
 * --------
 * `activityLogObsColumnsExist()` is the SAME `tblActivityLog.Environment`
 * existence probe (#1207) the page always ran to decide whether the
 * observability columns — including `Country`, the column this backfill
 * writes — exist yet on this install; both callers gate on it so a
 * pre-migration deploy degrades the same way either surface reaches it
 * (page: the geo AJAX endpoint is unreachable; API: 503).
 *
 * `activityLogGeoResolveIps()` is a byte-identical extraction of the
 * page's former `?action=geo` handler's resolve loop: same 25-IP-per-call
 * cap (external lookup rate limits), same `allowExternal=true` lookup, same
 * best-effort snapshot UPDATE onto rows that don't have a Country yet.
 *
 * Direct access is blocked (the same guard every other includes/*.php
 * helper carries).
 *
 * @see appWeb/public_html/manage/activity-log.php  the page this was extracted from (?action=geo)
 * @see appWeb/public_html/includes/ip_geolocation.php ihymnsGeoLookup() — the actual cache/MaxMind/API chain
 * @see appWeb/public_html/api.php                    admin_ip_geolocate
 * @see .claude/api-coverage-2026-08-28.md §4.3/§9 A16  the plan this implements
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'ip_geolocation.php'; /* ihymnsGeoLookup() */

/**
 * iHymns — does `tblActivityLog` carry the #1207 observability columns yet
 * (`Environment`/`RequestPath`/`Referrer`/`Country`)? Cached per-request,
 * mirroring the codebase's other INFORMATION_SCHEMA existence probes
 * (rule #9 — an un-migrated install must degrade, never STRICT-throw).
 */
function activityLogObsColumnsExist(\mysqli $db): bool
{
    static $cached = null;
    if ($cached !== null) { return $cached; }
    try {
        $stmt = $db->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = 'tblActivityLog'
                AND COLUMN_NAME  = 'Environment' LIMIT 1"
        );
        $stmt->execute();
        $cached = (bool)$stmt->get_result()->fetch_row();
        $stmt->close();
    } catch (\Throwable $_e) {
        $cached = false; // pre-migration deploy
    }
    return $cached;
}

/**
 * iHymns — resolve a batch of IPs to ISO-3166-1 alpha-2 country codes and
 * snapshot the result onto matching un-resolved `tblActivityLog` rows.
 * Byte-identical extraction of `manage/activity-log.php`'s former
 * `?action=geo` handler body (#1208).
 *
 * Capped to 25 IPs per call (external free-API rate limits) — a caller
 * with more IPs to resolve makes more than one call, exactly as the page's
 * own infinite-scroll batching already does.
 *
 * @param list<string> $ips Raw candidate IP strings — trimmed,
 *        de-duplicated, and capped internally.
 * @return array<string,string> ip => ISO-3166-1 alpha-2 code, for every IP
 *         that resolved to a non-empty code (an IP that didn't resolve is
 *         simply absent, matching the page's original `$countries` shape).
 */
function activityLogGeoResolveIps(\mysqli $db, array $ips): array
{
    $ips = array_values(array_unique(array_filter(array_map('trim', $ips))));
    $ips = array_slice($ips, 0, 25); // cap external lookups per call (rate limits)

    $countries = [];
    foreach ($ips as $oneIp) {
        $geo = ihymnsGeoLookup($oneIp, true); // allow external providers here
        if ($geo !== null && $geo['code'] !== '') {
            $countries[$oneIp] = $geo['code'];
            /* Snapshot onto rows from this IP that have no country yet, so
               the next view needs no lookup at all. Bound; best-effort. */
            try {
                $u = $db->prepare("UPDATE tblActivityLog SET Country = ? WHERE IpAddress = ? AND (Country IS NULL OR Country = '')");
                $u->bind_param('ss', $countries[$oneIp], $oneIp);
                $u->execute();
                $u->close();
            } catch (\Throwable $_e) { /* best-effort backfill */ }
        }
    }
    return $countries;
}
