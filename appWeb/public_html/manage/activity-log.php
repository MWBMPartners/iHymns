<?php

declare(strict_types=1);

/**
 * iHymns — Admin: Activity Log Viewer (#535)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * Filters tblActivityLog by action, user, entity, request id,
 * result, time window, and free-text. Pagination by limit/offset.
 * Optional CSV export.
 *
 * Admin / global_admin only — even though the rows themselves are
 * largely non-sensitive (see project-rules.md §10), the IP address
 * + UA + email columns warrant the gate.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_mysql.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'environment.php'; // #1315 — ihymns_environment() for the per-env log default
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'csv_safe.php'; // ihymns_fputcsv() — CSV formula-injection neutraliser
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'activity_log_geo.php'; // #1969 API-coverage Batch 5 — shared with api.php's admin_ip_geolocate

requireAuth();
$currentUser = getCurrentUser();
/* Gate on the SAME entitlement the nav advertises (#1648 item 1).
   ELI5: the menu says this page needs "view activity log" permission, so the
   page itself has to check that exact permission — not a role that happens to
   match it today.
   Detail: this used to hardcode the roles admin|global_admin while
   admin-links.php:104 advertised `view_activity_log`, which is admin-editable
   at runtime via /manage/entitlements. Widening the entitlement showed a nav
   link that then 403'd (confusing but harmless); NARROWING it was the real
   problem — revoking `admin` from view_activity_log, which exposes usernames,
   emails and IPs, hid the link while the page still admitted every admin-role
   user. The override UI presented a revocation that did not happen.
   The default map (`['admin','global_admin']`) is identical to the old
   hardcoded list, so this changes nothing until someone actually overrides it —
   which is the entire point. Rule #1587's red flag: "an admin page whose own
   gate differs from the entitlement its nav entry advertises". */
if (!$currentUser || !userHasEntitlement('view_activity_log', $currentUser['role'] ?? null)) {
    http_response_code(403);
    exit('Access denied. The view_activity_log entitlement is required.');
}

$activePage = 'activity-log';
$db = getDbMysqli();

/* #1207 — do the observability columns (Environment / RequestPath / Referrer /
   Country) exist yet? Probed once; gates the env filter, the path search, the
   SELECT tail, and the display so a pre-migration deploy still renders.
   #1969 API-coverage Batch 5 — the probe itself now lives in the shared core
   (activityLogObsColumnsExist()), reused by api.php's admin_ip_geolocate. */
$hasObsCols = activityLogObsColumnsExist($db);
/* SELECT tail for the observability columns — empty on a pre-migration deploy
   so the query still compiles. Reused by the CSV export + the main SELECT. */
$obsColsSql = $hasObsCols ? ', a.Environment, a.RequestPath, a.Referrer, a.Country' : '';

/* Proxy/VPN columns (migrate-activity-log-proxy-vpn.php) — probed ONCE here so
   the CSV export, the paginated SELECT, AND the #1302 infinite-scroll fragment
   endpoint can all share the same flag + SELECT tail rather than re-probing
   INFORMATION_SCHEMA three times. Pre-migration deploys skip the columns so the
   SELECT still compiles. */
$hasProxyCols = false;
try {
    $probe = $db->prepare(
        "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME   = 'tblActivityLog'
            AND COLUMN_NAME  = 'ProxyVpnIndicator' LIMIT 1"
    );
    $probe->execute();
    $hasProxyCols = (bool)$probe->get_result()->fetch_row();
    $probe->close();
} catch (\Throwable $_e) { /* pre-migration deploy → stays false */ }
$proxyColsSql = $hasProxyCols
    ? ', a.IpProxyChain, a.ProxyVpnIndicator, a.ProxyVpnDetail'
    : '';

/* #1208 — render an ISO-3166-1 alpha-2 country code as its flag emoji (two
   Unicode regional-indicator letters). No image assets; empty for blank/invalid
   codes. The geo resolver (#1208) populates tblActivityLog.Country. */
function _activityFlagEmoji(string $cc): string
{
    $cc = strtoupper(trim($cc));
    if (strlen($cc) !== 2 || !ctype_alpha($cc)) {
        return '';
    }
    $base = 0x1F1E6; // 🇦 regional indicator A
    return mb_chr($base + (ord($cc[0]) - 65), 'UTF-8') . mb_chr($base + (ord($cc[1]) - 65), 'UTF-8');
}

/* #1208 — async geo backfill endpoint. The viewer POSTs the IPs of rows that
   have no country yet; we resolve them (cache → MaxMind → API chain), snapshot
   the country onto matching tblActivityLog rows + the IP cache, and return
   { ip: "GB" } so the page injects flags without a reload. CSRF-guarded (it
   UPDATEs rows) + capped per call to respect free-API rate limits. Admin gate is
   already enforced at the top of the page. */
if ($hasObsCols && ($_GET['action'] ?? '') === 'geo') {
    header('Content-Type: application/json');
    $token = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf'] ?? '');
    /* Robust same-origin check — the client sends X-Requested-With, so a stale
       baked token on a long-open Activity Log no longer 403s the AJAX calls. */
    if (!validateCsrfRequest($token)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'csrf']);
        exit;
    }
    /* #1969 API-coverage Batch 5 — the resolve+snapshot loop now lives in
       the ONE shared core (activityLogGeoResolveIps()), reused verbatim by
       api.php's admin_ip_geolocate. */
    $ipsRaw = (string)($_POST['ips'] ?? '');
    $countries = activityLogGeoResolveIps($db, explode(',', $ipsRaw));
    echo json_encode(['ok' => true, 'countries' => $countries]);
    exit;
}

/* Filter parsing — every filter is optional. Defaults give the
   admin "last 24 h, every action, every user" landing view. */
$filterAction     = trim((string)($_GET['action']      ?? ''));
$filterUser       = trim((string)($_GET['user']        ?? ''));   /* free-text: matches Username or Email */
$filterResult     = trim((string)($_GET['result']      ?? ''));
$filterEntityType = trim((string)($_GET['entity_type'] ?? ''));
$filterEntityId   = trim((string)($_GET['entity_id']   ?? ''));
$filterRequestId  = trim((string)($_GET['request_id']  ?? ''));
$filterDays       = (int)($_GET['days'] ?? 1);
if (!in_array($filterDays, [1, 7, 30, 90, 365], true)) { $filterDays = 1; }
$filterSearch     = trim((string)($_GET['q'] ?? ''));
/* #1315 — default the Env filter to the CURRENT environment on a BARE load (no
   ?env= param) so each env's log shows its OWN rows instead of the cross-env
   firehose that masked the real origin of errors (the prod/beta s.SongbookName
   saga). An explicit ?env= — including ''=the "— any —" option — is always
   respected: once the filter form is submitted the param is present, so this only
   changes the first bare visit. Safe on un-migrated installs: the env WHERE (~200)
   is gated on $hasObsCols + an allow-list. (#1207 — alpha | beta | production) */
$filterEnv = array_key_exists('env', $_GET)
    ? trim((string)$_GET['env'])
    : (function_exists('ihymns_environment') ? ihymns_environment() : '');

$since = (new DateTime("-{$filterDays} days"))->format('Y-m-d H:i:s');

/* mysqli only supports positional `?` placeholders. The PDO version
   used named params + reused them across multiple positions
   (`:user` appears twice in the Username/Email LIKE clause; same
   for `:search` in the Action/EntityId LIKE clause). For mysqli we
   bind the value once per `?` position, so $params and $types stay
   in lock-step with the order the ?s appear. The same arrays are
   reused for the COUNT, paginated SELECT, and CSV export queries —
   they all share the WHERE clause. */
$where  = ['a.CreatedAt >= ?'];
$params = [$since];
$types  = 's';

if ($filterAction !== '') {
    $where[]  = 'a.Action = ?';
    $params[] = $filterAction;
    $types   .= 's';
}
if ($filterUser !== '') {
    $where[]  = '(u.Username LIKE ? OR u.Email LIKE ?)';
    $userLike = '%' . $filterUser . '%';
    $params[] = $userLike;
    $params[] = $userLike;
    $types   .= 'ss';
}
if (in_array($filterResult, ['success', 'failure', 'error'], true)) {
    $where[]  = 'a.Result = ?';
    $params[] = $filterResult;
    $types   .= 's';
}
if ($filterEntityType !== '') {
    /* #1741 P2-B — a renamed entity type (today: only 'musician') must
       also surface its pre-rename historical rows ('credit_person'),
       which are never rewritten. activityLogEntityTypeVariants() is the
       ONE shared old↔new map (includes/activity_log.php, rule #35) — an
       entity type with no rename history returns as a single-element
       list, so this degrades to the old exact-match behaviour for
       everything else. */
    $entityTypeVariants = activityLogEntityTypeVariants($filterEntityType);
    $where[]  = 'a.EntityType IN (' . implode(',', array_fill(0, count($entityTypeVariants), '?')) . ')';
    foreach ($entityTypeVariants as $entityTypeVariant) {
        $params[] = $entityTypeVariant;
        $types   .= 's';
    }
}
if ($filterEntityId !== '') {
    $where[]  = 'a.EntityId = ?';
    $params[] = $filterEntityId;
    $types   .= 's';
}
if ($filterRequestId !== '') {
    $where[]  = 'a.RequestId = ?';
    $params[] = $filterRequestId;
    $types   .= 's';
}
if ($filterSearch !== '') {
    $searchLike = '%' . $filterSearch . '%';
    /* #1207 — also search the requested path so "find every hit on /sitemap.xml"
       works (only when the column exists). */
    if ($hasObsCols) {
        $where[]  = '(a.Action LIKE ? OR a.EntityId LIKE ? OR a.RequestPath LIKE ?)';
        $params[] = $searchLike;
        $params[] = $searchLike;
        $params[] = $searchLike;
        $types   .= 'sss';
    } else {
        $where[]  = '(a.Action LIKE ? OR a.EntityId LIKE ?)';
        $params[] = $searchLike;
        $params[] = $searchLike;
        $types   .= 'ss';
    }
}
/* #1207 — environment filter (the shared DB serves alpha / beta / production). */
if ($hasObsCols && in_array($filterEnv, ['alpha', 'beta', 'production'], true)) {
    $where[]  = 'a.Environment = ?';
    $params[] = $filterEnv;
    $types   .= 's';
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

$pageSize = 50;

$resultBadgeClass = [
    'success' => 'bg-success',
    'failure' => 'bg-warning text-dark',
    'error'   => 'bg-danger',
];

/* Helper for keeping all current filters when generating the
   "next page" / "export csv" links — saves writing them out
   manually at every callsite. Defined up here (rather than after the
   main query) so the #1302 fragment endpoint below can reuse it. */
$buildQuery = function (array $overrides = []) use ($filterEnv) {
    $merged = array_filter(array_merge([
        'action'      => $_GET['action']      ?? '',
        'user'        => $_GET['user']        ?? '',
        'result'      => $_GET['result']      ?? '',
        'entity_type' => $_GET['entity_type'] ?? '',
        'entity_id'   => $_GET['entity_id']   ?? '',
        'request_id'  => $_GET['request_id']  ?? '',
        'days'        => $_GET['days']        ?? '',
        'q'           => $_GET['q']           ?? '',
        'env'         => $filterEnv,          /* #1315 — effective env (defaults to current) so paging/export links stay consistent */
        'page'        => $_GET['page']        ?? '',
    ], $overrides), fn($v) => $v !== '' && $v !== null);
    return http_build_query($merged);
};

/* #1302 — fetch ONE page of activity rows by OFFSET. Used by the full-page
   render AND the no-JS ?page= fallback so there's exactly ONE OFFSET-paginated
   query (same WHERE / $params / $types, same ORDER, same LIMIT/OFFSET). The page
   number is the only thing that varies; $pageSize is fixed and the OFFSET is
   computed from a bound-safe integer cast, so no filter value is ever
   interpolated into SQL. Returns [$rows, $total].

   NOTE: OFFSET pagination is provably unstable on tblActivityLog — the table is
   append-heavy and self-logging, so rows inserted between two fetches shift the
   window and the same row reappears at the next page's seam. That's why the
   JS infinite-scroll path uses $fetchActivityKeyset() (SEEK pagination) below
   instead of this. This OFFSET path is retained ONLY as the no-JS fallback,
   where each ?page= load is a fresh independent snapshot and a one-row seam
   overlap is immaterial. */
$fetchActivityPage = function (int $pageNum) use ($db, $whereSql, $params, $types, $pageSize, $proxyColsSql, $obsColsSql): array {
    $pageNum = max(1, $pageNum);
    $offsetN = ($pageNum - 1) * $pageSize;

    $countStmt = $db->prepare(
        'SELECT COUNT(*)
           FROM tblActivityLog a
           LEFT JOIN tblUsers u ON u.Id = a.UserId ' . $whereSql
    );
    $countStmt->bind_param($types, ...$params);
    $countStmt->execute();
    $cRow = $countStmt->get_result()->fetch_row();
    $totalN = (int)($cRow[0] ?? 0);
    $countStmt->close();

    /* #805 — UNIX_TIMESTAMP(a.CreatedAt) feeds the unambiguous-UTC cell + the
       data-ts attribute the JS converter reads. LIMIT/OFFSET use (int)-cast
       constants only — never a bound user value. */
    $stmt = $db->prepare(
        'SELECT a.Id, a.CreatedAt, UNIX_TIMESTAMP(a.CreatedAt) AS CreatedAtTs,
                a.Action, a.EntityType, a.EntityId, a.Result,
                a.Details, a.IpAddress' . $proxyColsSql . $obsColsSql . ', a.UserAgent, a.RequestId, a.Method,
                a.DurationMs, u.Username
           FROM tblActivityLog a
           LEFT JOIN tblUsers u ON u.Id = a.UserId
           ' . $whereSql . '
           ORDER BY a.CreatedAt DESC, a.Id DESC
           LIMIT ' . (int)$pageSize . ' OFFSET ' . (int)$offsetN
    );
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $pageRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return [$pageRows, $totalN];
};

/* #1302 — fetch ONE page of activity rows by KEYSET (SEEK) for the JS
   infinite-scroll fragment path. The client carries the last-appended row's
   (CreatedAt, Id) forward as an opaque cursor; we add an extra
   `(a.CreatedAt < ? OR (a.CreatedAt = ? AND a.Id < ?))` predicate that selects
   strictly OLDER rows than the cursor, REUSING the existing filter binds. Because
   the seek is relative to a fixed anchor row (not an absolute OFFSET), rows
   inserted between fetches never shift the window — so the page-boundary
   duplicate that OFFSET pagination produces on this append-heavy, self-logging
   table cannot occur (the MAJOR finding). ALL values are bound via bind_param;
   the cursor strings/int are appended to a COPY of $params/$types so the shared
   filter binds stay untouched for the OFFSET path + COUNT + CSV export.

   $cursorTs is the raw TIMESTAMP(6) string from the cursor row's data-created
   (compared against a.CreatedAt for sub-second-exact seeking); $cursorId is the
   tie-breaker. With neither supplied the first keyset page is returned (no seek
   predicate), so an initial fragment fetch with no cursor still works. Returns
   just $rows — there is no $total for a keyset page; end-of-data is "fewer rows
   than $pageSize came back" (handled by the caller). */
$fetchActivityKeyset = function (string $cursorTs, int $cursorId) use ($db, $whereSql, $params, $types, $pageSize, $proxyColsSql, $obsColsSql): array {
    /* Copy the shared filter binds, then append the seek binds so the canonical
       $params/$types (reused by COUNT, OFFSET SELECT, CSV) are never mutated. */
    $kParams = $params;
    $kTypes  = $types;
    $seekSql = '';
    if ($cursorTs !== '' && $cursorId > 0) {
        $seekSql  = ' AND (a.CreatedAt < ? OR (a.CreatedAt = ? AND a.Id < ?))';
        $kParams[] = $cursorTs;   // a.CreatedAt < ?
        $kParams[] = $cursorTs;   // a.CreatedAt = ?
        $kParams[] = $cursorId;   // a.Id < ?
        $kTypes   .= 'ssi';
    }

    $stmt = $db->prepare(
        'SELECT a.Id, a.CreatedAt, UNIX_TIMESTAMP(a.CreatedAt) AS CreatedAtTs,
                a.Action, a.EntityType, a.EntityId, a.Result,
                a.Details, a.IpAddress' . $proxyColsSql . $obsColsSql . ', a.UserAgent, a.RequestId, a.Method,
                a.DurationMs, u.Username
           FROM tblActivityLog a
           LEFT JOIN tblUsers u ON u.Id = a.UserId
           ' . $whereSql . $seekSql . '
           ORDER BY a.CreatedAt DESC, a.Id DESC
           LIMIT ' . (int)$pageSize
    );
    $stmt->bind_param($kTypes, ...$kParams);
    $stmt->execute();
    $pageRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $pageRows;
};

/* #1302 — render the <tr> markup for a set of rows. The full-page table body
   and the infinite-scroll fragment endpoint BOTH call this, so the row markup
   (badges, geo-flags, TZ data attributes, proxy/VPN indicator, expandable
   detail row) lives in exactly ONE place — no divergent client-side rebuild
   of the row HTML. Echoes directly (used inside output buffering by the
   fragment endpoint, inline by the page). */
$renderActivityRows = function (array $rowsToRender) use ($hasObsCols, $resultBadgeClass, $buildQuery): void {
    foreach ($rowsToRender as $r):
        $detailsRaw = $r['Details'];
        $detailsArr = $detailsRaw !== null ? json_decode((string)$detailsRaw, true) : null;
        $detailsPretty = $detailsArr !== null
            ? json_encode($detailsArr, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : '';
        /* #805 — render the cell text as UTC built from UNIX_TIMESTAMP via
           gmdate() so the value is correct regardless of MySQL session TZ or
           PHP date.timezone. The data-ts attribute carries the Unix seconds
           for the JS converter to format in the visitor's local TZ. */
        $createdTs    = (int)($r['CreatedAtTs'] ?? 0);
        $createdUtcStr = $createdTs > 0
            ? gmdate('Y-m-d H:i:s', $createdTs)
            : (string)$r['CreatedAt'];
        /* Sub-second fraction (#1287) — ms (3 digits) from the raw TIMESTAMP(6)
           value; empty on a pre-migration second column. */
        $createdMs = '';
        if (preg_match('/\.(\d{1,6})/', (string)($r['CreatedAt'] ?? ''), $msMatch)) {
            $createdMs = substr(str_pad($msMatch[1], 3, '0'), 0, 3);
        }
        $createdUtcFull = $createdUtcStr . ($createdMs !== '' ? '.' . $createdMs : '');
        /* #1302 keyset — the raw TIMESTAMP(6) string is the cursor's CreatedAt
           half (the seek query compares against a.CreatedAt, NOT the int Unix
           seconds, so sub-second precision is preserved and the comparison is
           byte-for-byte identical to what MySQL stored). The Id is the
           tie-breaker. Both are emitted on the row so the client can carry the
           last-seen (CreatedAt, Id) forward as an opaque cursor AND skip any
           row id it already has appended (belt-and-braces against a seam dup). */
        $rowId        = (int)($r['Id'] ?? 0);
        $rowCreatedAt = (string)($r['CreatedAt'] ?? '');
    ?>
        <tr class="activity-row"
            data-row-id="<?= $rowId ?>"
            data-created="<?= htmlspecialchars($rowCreatedAt, ENT_QUOTES, 'UTF-8') ?>">
            <td class="text-muted small activity-when"
                data-ts="<?= (int)$createdTs ?>"
                data-ms="<?= htmlspecialchars($createdMs, ENT_QUOTES, 'UTF-8') ?>"
                title="<?= htmlspecialchars($createdUtcFull, ENT_QUOTES, 'UTF-8') ?> UTC">
                <?= htmlspecialchars($createdUtcStr, ENT_QUOTES, 'UTF-8') ?><?php if ($createdMs !== ''): ?><span class="text-muted">.<?= htmlspecialchars($createdMs, ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?> <span class="text-muted">UTC</span>
            </td>
            <?php if ($hasObsCols): ?>
            <td>
                <?php
                $env = (string)($r['Environment'] ?? '');
                if ($env !== ''):
                    $envCls = match ($env) {
                        'production' => 'bg-success',
                        'beta'       => 'bg-info text-dark',
                        'alpha'      => 'bg-secondary',
                        default      => 'bg-secondary-subtle text-body',
                    };
                ?>
                    <span class="badge <?= $envCls ?>"><?= htmlspecialchars($env, ENT_QUOTES, 'UTF-8') ?></span>
                <?php endif; ?>
            </td>
            <?php endif; ?>
            <td>
                <?php if (!empty($r['Username'])): ?>
                    <span><?= htmlspecialchars((string)$r['Username'], ENT_QUOTES, 'UTF-8') ?></span>
                <?php else: ?>
                    <em class="text-muted">— anon —</em>
                <?php endif; ?>
            </td>
            <td class="activity-action">
                <?= htmlspecialchars((string)$r['Action'], ENT_QUOTES, 'UTF-8') ?>
            </td>
            <td class="small">
                <?php if ($r['EntityType'] !== '' || $r['EntityId'] !== ''): ?>
                    <code><?= htmlspecialchars((string)$r['EntityType'], ENT_QUOTES, 'UTF-8') ?></code>
                    <span class="text-muted">/</span>
                    <code><?= htmlspecialchars((string)$r['EntityId'], ENT_QUOTES, 'UTF-8') ?></code>
                <?php endif; ?>
            </td>
            <?php if ($hasObsCols): ?>
            <td class="small text-muted">
                <?php $rp = (string)($r['RequestPath'] ?? ''); if ($rp !== ''): ?>
                    <a class="text-decoration-none"
                       href="?<?= htmlspecialchars($buildQuery(['q' => $rp]), ENT_QUOTES, 'UTF-8') ?>"
                       title="Filter to hits on this path">
                        <code class="d-inline-block text-truncate" style="max-width: 18rem; vertical-align: bottom;"><?= htmlspecialchars($rp, ENT_QUOTES, 'UTF-8') ?></code>
                    </a>
                <?php endif; ?>
            </td>
            <?php endif; ?>
            <td>
                <span class="badge <?= $resultBadgeClass[$r['Result']] ?? 'bg-secondary' ?>">
                    <?= htmlspecialchars((string)$r['Result'], ENT_QUOTES, 'UTF-8') ?>
                </span>
            </td>
            <td class="text-muted small">
                <?php
                /* #1208 — every IP gets a .geo-flag span (filled
                   server-side from the Country snapshot; empty
                   ones are backfilled async by the script below). */
                if ($hasObsCols):
                    $cc = (string)($r['Country'] ?? '');
                ?><span class="geo-flag" data-ip="<?= htmlspecialchars((string)$r['IpAddress'], ENT_QUOTES, 'UTF-8') ?>"<?php if ($cc !== ''): ?> title="<?= htmlspecialchars($cc, ENT_QUOTES, 'UTF-8') ?>"<?php endif; ?>><?= _activityFlagEmoji($cc) ?></span> <?php endif; ?><?= htmlspecialchars((string)$r['IpAddress'], ENT_QUOTES, 'UTF-8') ?>
                <?php
                /* Proxy/VPN indicator badge — shown inline under
                   the IP when classification is something other
                   than 'none'. Pre-migration deploys (column not
                   selected) skip the badge entirely. */
                $pind = (string)($r['ProxyVpnIndicator'] ?? '');
                if ($pind !== '' && $pind !== 'none'):
                    $pcls = match ($pind) {
                        'vpn', 'tor'    => 'bg-warning text-dark',
                        'datacentre',
                        'proxy'         => 'bg-secondary',
                        'cloudflare',
                        'xff'           => 'bg-info text-dark',
                        default          => 'bg-secondary',
                    };
                    $ptitle = trim((string)($r['IpProxyChain'] ?? ''));
                ?>
                    <br>
                    <span class="badge <?= $pcls ?>"
                          title="<?= htmlspecialchars($ptitle !== '' ? "Proxy chain: $ptitle" : '', ENT_QUOTES, 'UTF-8') ?>">
                        <?= htmlspecialchars($pind, ENT_QUOTES, 'UTF-8') ?>
                    </span>
                <?php endif; ?>
            </td>
            <td>
                <a class="request-id-pill"
                   href="?<?= htmlspecialchars($buildQuery(['request_id' => $r['RequestId']]), ENT_QUOTES, 'UTF-8') ?>"
                   title="Show every row from this HTTP request">
                    <?= htmlspecialchars(substr((string)$r['RequestId'], 0, 8), ENT_QUOTES, 'UTF-8') ?>
                </a>
            </td>
        </tr>
        <?php if ($detailsPretty !== '' || ($r['UserAgent'] ?? '') !== '' || $r['DurationMs'] !== null): ?>
        <tr class="activity-row">
            <td colspan="<?= $hasObsCols ? 9 : 7 ?>" class="bg-black-soft border-0 py-2">
                <div class="small text-muted mb-1">
                    <?php if ($r['Method'] !== ''): ?>
                        <code><?= htmlspecialchars((string)$r['Method'], ENT_QUOTES, 'UTF-8') ?></code>
                    <?php endif; ?>
                    <?php if ($r['DurationMs'] !== null): ?>
                        · <?= (int)$r['DurationMs'] ?> ms
                    <?php endif; ?>
                    <?php if (!empty($r['UserAgent'])): ?>
                        <?php /* Render the full UA inline (no 80-char substr cap)
                                 — long UAs wrap to a second line via the
                                 .activity-ua wrapper class. (#721) */ ?>
                        · UA: <span class="activity-ua" title="<?= htmlspecialchars((string)$r['UserAgent'], ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars((string)$r['UserAgent'], ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    <?php endif; ?>
                    <?php if ($hasObsCols && !empty($r['Referrer'])): ?>
                        <br>· Referrer: <span class="activity-ua"><?= htmlspecialchars((string)$r['Referrer'], ENT_QUOTES, 'UTF-8') ?></span>
                    <?php endif; ?>
                </div>
                <?php if ($detailsPretty !== ''): ?>
                    <div class="activity-details"><?= htmlspecialchars($detailsPretty, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
            </td>
        </tr>
        <?php endif; ?>
    <?php endforeach;
};

/* #1302 — infinite-scroll fragment endpoint. Returns ONLY the <tr> rows OLDER
   than the supplied keyset cursor (same bound filters + ALL active filters as
   the full view), so the client IntersectionObserver can append the next batch
   without a reload. KEYSET (SEEK), not OFFSET — see $fetchActivityKeyset above
   for why (page-boundary duplicates on this append-heavy table). The existing
   ?page=N OFFSET pagination remains the complete no-JS fallback. Read-only GET;
   the admin gate at the top of the file already applies.

   Cursor: `cursor_ts` = the last appended row's data-created (raw TIMESTAMP(6)
   string); `cursor_id` = its data-row-id. Both arrive only from rows this same
   endpoint (or the initial page) emitted, and both are bound — never
   interpolated. With neither present the first keyset batch is returned. */
if (($_GET['action'] ?? '') === 'next-page') {
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');
    $cursorTs = trim((string)($_GET['cursor_ts'] ?? ''));
    $cursorId = (int)($_GET['cursor_id'] ?? 0);
    try {
        $fpRows = $fetchActivityKeyset($cursorTs, $cursorId);
    } catch (\Throwable $e) {
        error_log('[manage/activity-log.php#next-page] ' . $e->getMessage());
        http_response_code(503);
        echo '<!-- activity-log fragment error -->';
        exit;
    }
    /* "Fewer rows than a full page" => this was the last batch; tell the client
       so it stops observing. A 200 with an EMPTY <tbody> (zero rows) is also a
       valid end-of-data signal the client treats the same way (MINOR finding:
       no tight-loop). */
    $fpAtEnd = count($fpRows) < $pageSize ? 1 : 0;
    echo '<tbody data-activity-fragment="1"'
        . ' data-row-count="' . count($fpRows) . '"'
        . ' data-at-end="' . $fpAtEnd . '">';
    $renderActivityRows($fpRows);
    echo '</tbody>';
    exit;
}

/* CSV export — same filters, but streams every matching row to
   the browser instead of paginating. Capped at 10 000 rows so an
   accidental "everything" export doesn't OOM the worker. */
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="ihymns-activity-' . date('Ymd-His') . '.csv"');
    header('Cache-Control: no-store');

    /* #1908 Commit 4 — the shared emitter opens the stream AND writes the
       UTF-8 BOM Excel needs to decode a downloaded .csv correctly; see the
       doc-block on ihymns_csv_output_begin() for the full why. */
    $out = ihymns_csv_output_begin();
    /* #805 — CreatedAtUtc is an explicit UTC ISO-8601 string built
       from UNIX_TIMESTAMP() via gmdate() so downloaded CSVs are
       timezone-unambiguous regardless of where the spreadsheet ends
       up. The legacy `CreatedAt` column is preserved for back-compat
       with any existing report tooling but should be considered
       deprecated — `CreatedAtUtc` is the new canonical value. */
    /* New columns from migrate-activity-log-proxy-vpn.php — $hasProxyCols was
       probed once near the top of the file (shared with the paginated SELECT +
       the #1302 fragment endpoint), so a pre-migration deploy keeps exporting
       cleanly with the legacy header set. */
    $headerRow = [
        'Id', 'CreatedAt', 'CreatedAtUtc', 'Username', 'Action', 'EntityType', 'EntityId',
        'Result', 'IpAddress', 'UserAgent', 'RequestId', 'Method', 'DurationMs', 'Details',
    ];
    if ($hasProxyCols) {
        array_splice($headerRow, 9, 0, ['IpProxyChain', 'ProxyVpnIndicator', 'ProxyVpnDetail']);
    }
    if ($hasObsCols) {
        $headerRow[] = 'Environment';
        $headerRow[] = 'RequestPath';
        $headerRow[] = 'Referrer';
        $headerRow[] = 'Country';
    }
    ihymns_fputcsv($out, $headerRow);
    $stmt = $db->prepare(
        'SELECT a.Id, a.CreatedAt, UNIX_TIMESTAMP(a.CreatedAt) AS CreatedAtTs,
                u.Username, a.Action, a.EntityType, a.EntityId,
                a.Result, a.IpAddress' . $proxyColsSql . $obsColsSql . ', a.UserAgent, a.RequestId, a.Method,
                a.DurationMs, a.Details
           FROM tblActivityLog a
           LEFT JOIN tblUsers u ON u.Id = a.UserId
           ' . $whereSql . '
           ORDER BY a.CreatedAt DESC, a.Id DESC
           LIMIT 10000'
    );
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $ts        = (int)($row['CreatedAtTs'] ?? 0);
        /* Sub-second fraction (#1287) — from the raw TIMESTAMP(6) string when present
           (UNIX_TIMESTAMP is cast to int, so the fraction lives on the raw value). */
        $frac = '';
        if (preg_match('/\.(\d{1,6})/', (string)($row['CreatedAt'] ?? ''), $fm)) {
            $frac = '.' . substr(str_pad($fm[1], 3, '0'), 0, 3);
        }
        $createdUtc = $ts > 0 ? gmdate('Y-m-d\TH:i:s', $ts) . $frac . 'Z' : '';
        $csvRow = [
            $row['Id'], $row['CreatedAt'], $createdUtc, $row['Username'] ?? '', $row['Action'],
            $row['EntityType'], $row['EntityId'], $row['Result'],
            $row['IpAddress'],
        ];
        if ($hasProxyCols) {
            $csvRow[] = $row['IpProxyChain']      ?? '';
            $csvRow[] = $row['ProxyVpnIndicator'] ?? '';
            $csvRow[] = $row['ProxyVpnDetail']    ?? '';
        }
        $csvRow[] = $row['UserAgent']  ?? '';
        $csvRow[] = $row['RequestId']  ?? '';
        $csvRow[] = $row['Method']     ?? '';
        $csvRow[] = $row['DurationMs'] ?? '';
        $csvRow[] = $row['Details']    ?? '';
        if ($hasObsCols) {
            $csvRow[] = $row['Environment'] ?? '';
            $csvRow[] = $row['RequestPath'] ?? '';
            $csvRow[] = $row['Referrer']    ?? '';
            $csvRow[] = $row['Country']     ?? '';
        }
        ihymns_fputcsv($out, $csvRow);
    }
    $stmt->close();
    fclose($out);
    exit;
}

/* Paginated list query for the UI — delegates to the shared $fetchActivityPage
   closure so the full page and the #1302 fragment endpoint run the identical
   bound query. */
$page  = max(1, (int)($_GET['page'] ?? 1));
$total = 0;
$rows  = [];
try {
    [$rows, $total] = $fetchActivityPage($page);
} catch (\Throwable $e) {
    error_log('[manage/activity-log.php] ' . $e->getMessage());
    /* If the Activity Log viewer itself can't load, recording an
       Activity Log row about the failure is admittedly recursive —
       but logActivityError() short-circuits if the table isn't
       writable, so it's safe to call. (#713) */
    if (function_exists('logActivityError')) {
        logActivityError('admin.activity_log.list', 'activity_log', '', $e);
    }
}

$totalPages = $total > 0 ? (int)ceil($total / $pageSize) : 1;

/* Distinct action names for the action-filter dropdown — keeps
   the dropdown self-populating as new event types appear, no
   hand-maintained list to drift. */
$distinctActions = [];
try {
    $stmt = $db->prepare(
        'SELECT DISTINCT Action
           FROM tblActivityLog
          WHERE CreatedAt >= ?
          ORDER BY Action ASC'
    );
    $stmt->bind_param('s', $since);
    $stmt->execute();
    /* PDO::FETCH_COLUMN returned a flat array of column-0 values;
       mysqli has no direct equivalent — pull all rows then array_column. */
    $distinctActions = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'Action');
    $stmt->close();
} catch (\Throwable $_e) { /* empty list is fine */ }

/* $resultBadgeClass + $buildQuery were defined earlier (before the fragment
   endpoint) so the shared render closure could reuse them. */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activity Log — iHymns Admin</title>
    <meta name="csrf-token" content="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>"><!-- #1208 geo backfill POST -->
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-libs.php'; ?>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-favicon.php'; ?>
    <style>
        .activity-row td { vertical-align: middle; font-size: 0.875rem; }
        .activity-action { font-family: ui-monospace, SFMono-Regular, monospace; font-size: 0.85rem; }
        .activity-details {
            background: rgba(255, 255, 255, 0.03);
            border-radius: 4px;
            padding: 0.5rem;
            font-family: ui-monospace, SFMono-Regular, monospace;
            font-size: 0.75rem;
            max-height: 240px;
            overflow: auto;
            white-space: pre-wrap;
            word-break: break-word;
        }
        .request-id-pill {
            font-family: ui-monospace, SFMono-Regular, monospace;
            font-size: 0.7rem;
            opacity: 0.6;
        }
    </style>
</head>
<body>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-nav.php'; ?>

    <div class="container-admin py-4">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <h1 class="h4 mb-0"><i aria-hidden="true" class="bi bi-activity me-2"></i>Activity Log</h1>
            <a class="btn btn-sm btn-outline-secondary"
               href="?<?= htmlspecialchars($buildQuery(['export' => 'csv']), ENT_QUOTES, 'UTF-8') ?>">
                <i aria-hidden="true" class="bi bi-download me-1"></i>Export CSV
            </a>
        </div>
        <p class="text-secondary small mb-4">
            A record of the important things that happen across the site —
            people signing in, admins adding or changing content, requests
            from the apps, and errors. Entries are kept indefinitely by
            default; automatic clean-up after a set number of days stays off
            unless an admin turns it on, because auditing and troubleshooting
            usually call for a long history.
        </p>
        <p class="text-secondary small mb-4">
            <i aria-hidden="true" class="bi bi-info-circle me-1"></i>All three environments
            (alpha&nbsp;·&nbsp;beta&nbsp;·&nbsp;production) share one database, so this log
            spans them all — use the <strong>Env</strong> column / filter to tell them apart.
            The <strong>When</strong> column shows your <strong>local</strong> time
            (<span data-activity-tz>detecting…</span>); CSV export is in UTC.
        </p>

        <form method="GET" class="row g-2 mb-3 small">
            <div class="col-md-3">
                <label class="form-label mb-1" for="filter-action">Action</label>
                <select class="form-select form-select-sm" id="filter-action" name="action">
                    <option value="">— any —</option>
                    <?php foreach ($distinctActions as $a): ?>
                        <option value="<?= htmlspecialchars($a, ENT_QUOTES, 'UTF-8') ?>"
                                <?= $filterAction === $a ? 'selected' : '' ?>>
                            <?= htmlspecialchars($a, ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label mb-1" for="filter-user">User</label>
                <input class="form-control form-control-sm" id="filter-user" type="text" name="user"
                       value="<?= htmlspecialchars($filterUser, ENT_QUOTES, 'UTF-8') ?>"
                       placeholder="username or email">
            </div>
            <div class="col-md-2">
                <label class="form-label mb-1" for="filter-result">Result</label>
                <select class="form-select form-select-sm" id="filter-result" name="result">
                    <option value="">— any —</option>
                    <option value="success" <?= $filterResult === 'success' ? 'selected' : '' ?>>success</option>
                    <option value="failure" <?= $filterResult === 'failure' ? 'selected' : '' ?>>failure</option>
                    <option value="error"   <?= $filterResult === 'error'   ? 'selected' : '' ?>>error</option>
                </select>
            </div>
            <?php if ($hasObsCols): ?>
            <div class="col-md-2">
                <label class="form-label mb-1" for="filter-env">Env</label>
                <select class="form-select form-select-sm" id="filter-env" name="env">
                    <option value="">— any —</option>
                    <option value="alpha"      <?= $filterEnv === 'alpha'      ? 'selected' : '' ?>>alpha</option>
                    <option value="beta"       <?= $filterEnv === 'beta'       ? 'selected' : '' ?>>beta</option>
                    <option value="production" <?= $filterEnv === 'production' ? 'selected' : '' ?>>production</option>
                </select>
            </div>
            <?php endif; ?>
            <div class="col-md-2">
                <label class="form-label mb-1" for="filter-entity-type">Entity</label>
                <input class="form-control form-control-sm" id="filter-entity-type" type="text" name="entity_type"
                       value="<?= htmlspecialchars($filterEntityType, ENT_QUOTES, 'UTF-8') ?>"
                       placeholder="song, user, songbook, …">
            </div>
            <div class="col-md-2">
                <label class="form-label mb-1" for="filter-entity-id">Entity ID</label>
                <input class="form-control form-control-sm" id="filter-entity-id" type="text" name="entity_id"
                       value="<?= htmlspecialchars($filterEntityId, ENT_QUOTES, 'UTF-8') ?>"
                       placeholder="CP-0001, 42, …">
            </div>
            <div class="col-md-1">
                <label class="form-label mb-1" for="filter-days">Window</label>
                <select class="form-select form-select-sm" id="filter-days" name="days">
                    <option value="1"   <?= $filterDays === 1   ? 'selected' : '' ?>>24 h</option>
                    <option value="7"   <?= $filterDays === 7   ? 'selected' : '' ?>>7 d</option>
                    <option value="30"  <?= $filterDays === 30  ? 'selected' : '' ?>>30 d</option>
                    <option value="90"  <?= $filterDays === 90  ? 'selected' : '' ?>>90 d</option>
                    <option value="365" <?= $filterDays === 365 ? 'selected' : '' ?>>1 yr</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label mb-1" for="filter-search">Search</label>
                <input class="form-control form-control-sm" id="filter-search" type="text" name="q"
                       value="<?= htmlspecialchars($filterSearch, ENT_QUOTES, 'UTF-8') ?>"
                       placeholder="action or entity id substring">
            </div>
            <div class="col-md-3">
                <label class="form-label mb-1" for="filter-request-id">Request ID</label>
                <input class="form-control form-control-sm" id="filter-request-id" type="text" name="request_id"
                       value="<?= htmlspecialchars($filterRequestId, ENT_QUOTES, 'UTF-8') ?>"
                       placeholder="16-char hex">
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button class="btn btn-amber btn-sm w-100" type="submit">
                    <i aria-hidden="true" class="bi bi-funnel me-1"></i>Apply
                </button>
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <a class="btn btn-outline-secondary btn-sm w-100" href="/manage/activity-log">
                    <i aria-hidden="true" class="bi bi-x-circle me-1"></i>Reset
                </a>
            </div>
        </form>

        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
            <p class="text-secondary small mb-0">
                <?= number_format($total) ?> entr<?= $total === 1 ? 'y' : 'ies' ?> match —
                <span data-activity-pagelabel>page <?= $page ?> of <?= $totalPages ?></span>.
            </p>
            <?php /* #1302 — infinite-scroll opt-in. Hidden for no-JS users (the
                     enhancement script un-hides it); the ?page= pagination below
                     stays as the complete fallback either way. The choice is
                     remembered in localStorage so it survives reloads. */ ?>
            <div class="form-check form-switch small mb-0" data-activity-scroll-toggle hidden>
                <input class="form-check-input" type="checkbox" role="switch" id="activity-infinite-scroll">
                <label class="form-check-label text-secondary" for="activity-infinite-scroll">
                    Infinite scroll
                </label>
            </div>
        </div>

        <?php if (empty($rows)): ?>
            <div class="alert alert-info py-2" role="status">
                No matching activity in the selected window.
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-dark table-hover align-middle cp-sortable admin-table-responsive"
                       data-activity-table
                       data-page="<?= (int)$page ?>"
                       data-total-pages="<?= (int)$totalPages ?>">
                    <thead>
                        <tr>
                            <?php /* Header label flips between (local) and (UTC) at runtime
                                     once the inline script below has rewritten the cells.
                                     If the script doesn't run (CSP block, ancient browser),
                                     the cells stay UTC and the header keeps its initial
                                     "(UTC)" suffix. (#723) */ ?>
                            <th scope="col" style="width: 11rem;" id="activity-when-header" data-sort-key="when"   data-sort-type="text">When (UTC)</th>
                            <?php if ($hasObsCols): ?><th scope="col" style="width: 5rem;" data-sort-key="env" data-sort-type="text">Env</th><?php endif; ?>
                            <th scope="col" style="width: 10rem;" data-sort-key="user"   data-sort-type="text">User</th>
                            <th scope="col" data-sort-key="action" data-sort-type="text">Action</th>
                            <th scope="col" data-sort-key="entity" data-sort-type="text">Entity</th>
                            <?php if ($hasObsCols): ?><th scope="col" data-sort-key="path" data-sort-type="text">Path</th><?php endif; ?>
                            <th scope="col" style="width: 5rem;"  data-sort-key="result" data-sort-type="text">Result</th>
                            <th scope="col" style="width: 9rem;"  data-sort-key="ip"     data-sort-type="text">IP</th>
                            <th scope="col" style="width: 5rem;"  data-sort-key="req"    data-sort-type="text">Req</th>
                        </tr>
                    </thead>
                    <tbody data-activity-rows>
                    <?php /* #1302 — rows rendered via the shared $renderActivityRows
                             closure so the full page and the infinite-scroll
                             fragment endpoint emit byte-identical markup. */ ?>
                    <?php $renderActivityRows($rows); ?>
                    </tbody>
                </table>
            </div>

            <?php /* #1302 — infinite-scroll status line. The sentinel the
                     IntersectionObserver watches lives here (a div, not a <tr>,
                     so it sits outside the table semantics and never confuses a
                     screen-reader's row count). The VISIBLE status text gives
                     sighted users feedback; it is NOT a scroll-only instruction
                     (a focusable "Load more" button below is the keyboard/AT
                     trigger, so the message names the button, not "scroll").

                     a11y (MINOR #3): the polite live region is a SEPARATE,
                     ALWAYS-RENDERED .visually-hidden node — never `hidden`. A
                     region with the `hidden` attribute is removed from the
                     accessibility tree, so the FIRST mutation can be missed
                     (the region must already be in the tree when its text
                     changes for the announcement to fire). We mutate its text;
                     we never toggle its presence.
                     https://developer.mozilla.org/en-US/docs/Web/Accessibility/ARIA/ARIA_Live_Regions */ ?>
            <div class="activity-scroll-status text-secondary small mt-2"
                 data-activity-scroll-status hidden></div>
            <div class="visually-hidden" role="status" aria-live="polite"
                 data-activity-scroll-announce></div>

            <?php /* #1302 — focusable, non-scroll trigger (MINOR #2). Hidden for
                     no-JS users; the enhancement reveals it only when infinite
                     scroll is active so keyboard / AT users have a button they
                     can Tab to and activate instead of relying on a scroll
                     gesture an IntersectionObserver can't detect. */ ?>
            <div class="mt-2" data-activity-loadmore-wrap hidden>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-activity-loadmore>
                    <i aria-hidden="true" class="bi bi-arrow-down-circle me-1"></i>Load more
                </button>
            </div>
            <div data-activity-sentinel aria-hidden="true"></div>

            <?php if ($totalPages > 1): ?>
            <nav aria-label="Activity log pagination" class="mt-3" data-activity-pagination>
                <ul class="pagination pagination-sm">
                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link"
                           href="?<?= htmlspecialchars($buildQuery(['page' => max(1, $page - 1)]), ENT_QUOTES, 'UTF-8') ?>">
                            « Prev
                        </a>
                    </li>
                    <li class="page-item disabled">
                        <span class="page-link">Page <?= $page ?> / <?= $totalPages ?></span>
                    </li>
                    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                        <a class="page-link"
                           href="?<?= htmlspecialchars($buildQuery(['page' => min($totalPages, $page + 1)]), ENT_QUOTES, 'UTF-8') ?>">
                            Next »
                        </a>
                    </li>
                </ul>
            </nav>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <style>
        .activity-ua {
            display: inline-block;
            max-width: 100%;
            word-break: break-word;
            overflow-wrap: anywhere;
        }
    </style>
    <script>
    /* #1302 — the visitor-TZ converter + the geo backfill are now exposed on a
       small shared namespace so the infinite-scroll script can re-run BOTH over
       just-appended rows (a `root` element scopes each to a row subtree;
       defaults to document for the initial page render). The behaviour for the
       initial render is unchanged — these are the same two routines, factored
       into reusable functions. */
    (function () {
        var NS = (window.iHymnsActivityLog = window.iHymnsActivityLog || {});

        /* ---- Visitor-local time conversion (#805, replaces #721 / #723). ----
           Each .activity-when cell carries data-ts="<unix-seconds-utc>". We
           multiply by 1000, build a Date, format via Intl.DateTimeFormat in the
           visitor's resolved timezone, and replace the cell's text. The
           server-rendered "YYYY-MM-DD HH:MM:SS UTC" fallback stays if Intl is
           unavailable OR the TZ can't be resolved. */
        var visitorTz = '';
        var fmt = null;
        try {
            if (typeof Intl !== 'undefined' && Intl.DateTimeFormat) {
                var resolved = Intl.DateTimeFormat().resolvedOptions();
                visitorTz = (resolved && resolved.timeZone) ? resolved.timeZone : '';
                if (visitorTz) {
                    fmt = new Intl.DateTimeFormat(undefined, {
                        year:    'numeric', month:  '2-digit', day:    '2-digit',
                        hour:    '2-digit', minute: '2-digit', second: '2-digit',
                        hour12:  false,
                        timeZone: visitorTz,
                    });
                }
            }
        } catch (_e) { fmt = null; }

        NS.localiseTimes = function (root) {
            if (!fmt) return; /* fall back to server-rendered UTC */
            var scope = root || document;
            var cells = scope.querySelectorAll('.activity-when[data-ts]');
            Array.prototype.forEach.call(cells, function (cell) {
                if (cell.getAttribute('data-tz-done')) return; /* idempotent re-runs */
                var ts = parseInt(cell.getAttribute('data-ts') || '0', 10);
                if (!ts) return;
                var d = new Date(ts * 1000);
                if (isNaN(d.getTime())) return;
                /* Reformat to "YYYY-MM-DD HH:MM:SS" — matches the table's visual
                   cadence; some locales would otherwise produce
                   "30/04/2026, 14:53:42" which jars in a tabular layout. */
                var parts = fmt.formatToParts(d).reduce(function (acc, p) {
                    if (p.type !== 'literal') acc[p.type] = p.value; return acc;
                }, {});
                /* #1287 — re-append the sub-second fraction (timezone-invariant). */
                var ms = cell.getAttribute('data-ms') || '';
                cell.textContent = parts.year + '-' + parts.month + '-' + parts.day
                    + ' ' + parts.hour + ':' + parts.minute + ':' + parts.second
                    + (ms ? '.' + ms : '');
                cell.setAttribute('title', cell.textContent + ' (' + visitorTz + ')');
                cell.setAttribute('data-tz-done', '1');
            });
        };

        /* ---- #1208 async country flags. ---- Collect IPs whose flag isn't
           rendered yet, resolve them via the CSRF-guarded geo backfill endpoint,
           inject the flag emoji. The page never waits on this; flags pop in as
           the lookups return. */
        function flagEmoji(cc) {
            cc = (cc || '').toUpperCase();
            if (!/^[A-Z]{2}$/.test(cc)) { return ''; }
            return String.fromCodePoint(0x1F1E6 + cc.charCodeAt(0) - 65, 0x1F1E6 + cc.charCodeAt(1) - 65);
        }
        NS.backfillFlags = function (root) {
            try {
                var meta = document.querySelector('meta[name="csrf-token"]');
                var csrf = meta ? (meta.getAttribute('content') || '') : '';
                /* #1302 — `root` may be a single element / document (initial
                   render) OR an array / NodeList of elements (the just-appended
                   rows, scoped per MINOR #5). Normalise to a list of scopes and
                   gather the unresolved .geo-flag nodes across them in ONE pass,
                   so an appended batch is still ONE POST — not one per row. */
                var scopes;
                if (!root) {
                    scopes = [document];
                } else if (root.nodeType) {
                    scopes = [root];
                } else {
                    scopes = Array.prototype.slice.call(root);
                }
                var pending = [];
                scopes.forEach(function (scope) {
                    if (!scope || !scope.querySelectorAll) { return; }
                    Array.prototype.forEach.call(scope.querySelectorAll('.geo-flag'), function (el) {
                        if (el.textContent.trim() === '' && el.getAttribute('data-ip')) { pending.push(el); }
                    });
                });
                if (!pending.length) { return; }
                var ips = [];
                pending.forEach(function (el) {
                    var ip = el.getAttribute('data-ip');
                    if (ip && ips.indexOf(ip) === -1) { ips.push(ip); }
                });
                ips = ips.slice(0, 25);   /* server caps at 25/call too */
                if (!ips.length) { return; }
                // #1855: extensionless — a literal .php URL here is
                // 301'd by .htaccess, and a browser replays a 301'd POST as
                // a body-less GET, which would silently drop the ips list.
                fetch('/manage/activity-log?action=geo', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-Token': csrf,
                    },
                    body: 'ips=' + encodeURIComponent(ips.join(',')),
                }).then(function (r) { return r.ok ? r.json() : null; }).then(function (j) {
                    if (!j || !j.countries) { return; }
                    pending.forEach(function (el) {
                        var code = j.countries[el.getAttribute('data-ip')];
                        if (code && /^[A-Za-z]{2}$/.test(code)) {   // defense-in-depth: validate before DOM
                            var fl = flagEmoji(code);
                            if (fl) { el.textContent = fl; el.setAttribute('title', code.toUpperCase()); }
                        }
                    });
                }).catch(function () { /* fail-soft — no flags, page still fine */ });
            } catch (_e) { /* fail-soft */ }
        };

        /* Initial-render pass (same effect as before #1302). */
        try { NS.localiseTimes(document); } catch (_e) {}
        try { NS.backfillFlags(document); } catch (_e) {}

        /* #1207 — keep the header short ("When (local)"); surface the actual
           timezone in the blurb instead. Done once, not per row. */
        if (fmt && visitorTz) {
            var header = document.getElementById('activity-when-header');
            if (header) header.textContent = 'When (local)';
            var tzLabel = document.querySelector('[data-activity-tz]');
            if (tzLabel) tzLabel.textContent = visitorTz;
        }
    })();
    </script>

    <!-- #1302 — Activity-Log infinite scroll (PROGRESSIVE ENHANCEMENT).
         An IntersectionObserver watches a sentinel below the table; when it
         scrolls into view the next BATCH is fetched from the ?action=next-page
         fragment endpoint and its rows are appended to the tbody. The fetch uses
         KEYSET (SEEK) pagination, NOT OFFSET: the client carries the last
         appended row's (data-created, data-row-id) forward as an opaque cursor,
         so rows inserted between fetches never cause a page-boundary duplicate
         on this append-heavy, self-logging table. As belt-and-braces, the client
         also tracks every row id it has appended and skips any the server
         returns again. OPT-IN via a remembered toggle. Accessibility: a focusable
         "Load more" button is the non-scroll trigger (keyboard / AT users don't
         rely on a scroll gesture); the no-JS ?page= pagination stays reachable;
         each batch + end-of-log are announced via an always-rendered
         .visually-hidden role="status" aria-live="polite" region (never `hidden`,
         so the first announce isn't lost). -->
    <script>
    (function () {
        var NS = window.iHymnsActivityLog || {};
        var table = document.querySelector('[data-activity-table]');
        var tbody = table ? table.querySelector('[data-activity-rows]') : null;
        var sentinel = document.querySelector('[data-activity-sentinel]');
        var statusEl = document.querySelector('[data-activity-scroll-status]');
        var announceEl = document.querySelector('[data-activity-scroll-announce]');
        var toggleWrap = document.querySelector('[data-activity-scroll-toggle]');
        var toggle = document.getElementById('activity-infinite-scroll');
        var pagination = document.querySelector('[data-activity-pagination]');
        var pageLabel = document.querySelector('[data-activity-pagelabel]');
        var loadMoreWrap = document.querySelector('[data-activity-loadmore-wrap]');
        var loadMoreBtn = document.querySelector('[data-activity-loadmore]');

        /* Bail to the no-JS pagination if the browser lacks the APIs we need or
           the table is absent (e.g. the empty-results state). */
        if (!table || !tbody || !sentinel || !toggle || !toggleWrap
            || typeof IntersectionObserver === 'undefined'
            || typeof fetch === 'undefined'
            || typeof URLSearchParams === 'undefined') {
            return;
        }

        var STORAGE_KEY = 'ihymns_activity_infinite_scroll';
        var loading = false;
        /* Seed end-of-data from the server's initial page-count: if the first
           render already held every matching row (one page), there's nothing to
           seek for and we never fire a fetch. Keyset takes over from there. */
        var initialTotalPages = parseInt(table.getAttribute('data-total-pages') || '1', 10) || 1;
        var reachedEnd = initialTotalPages <= 1;
        var observer = null;
        var loadedCount = tbody.querySelectorAll('tr.activity-row[data-row-id]').length;

        /* Belt-and-braces de-dup: every row id already present in the DOM. Even
           though keyset pagination should never re-emit a row, a row id the
           server returns that's already here is skipped on append. */
        var seenIds = Object.create(null);
        Array.prototype.forEach.call(
            tbody.querySelectorAll('tr.activity-row[data-row-id]'),
            function (tr) { seenIds[tr.getAttribute('data-row-id')] = true; }
        );

        /* The keyset cursor = the last appended primary row's (data-created,
           data-row-id). Re-derived from the DOM after every append so we always
           seek from the genuinely oldest row currently shown. Returns null when
           there are no rows (nothing to seek from). */
        function readCursor() {
            var rows = tbody.querySelectorAll('tr.activity-row[data-row-id]');
            if (!rows.length) { return null; }
            var last = rows[rows.length - 1];
            return {
                ts: last.getAttribute('data-created') || '',
                id: last.getAttribute('data-row-id') || '',
            };
        }

        /* The toggle is hidden for no-JS users; reveal it now that JS is live —
           but only when there's actually more than one page to scroll through
           (otherwise infinite scroll is a no-op). */
        if (!reachedEnd) {
            toggleWrap.removeAttribute('hidden');
        }

        /* Visible (sighted) status. The polite live region is mutated separately
           via announce() so AT hears the same text without us toggling the
           region's presence (which would drop the first announce). */
        function setStatus(msg) {
            if (statusEl) {
                if (msg) {
                    statusEl.textContent = msg;
                    statusEl.removeAttribute('hidden');
                } else {
                    statusEl.textContent = '';
                    statusEl.setAttribute('hidden', '');
                }
            }
        }
        /* Mutate (never replace/hide) the always-rendered live region. */
        function announce(msg) {
            if (announceEl) { announceEl.textContent = msg || ''; }
        }

        /* Re-run the shared TZ + geo enhancements over ONLY the appended rows so
           new timestamps localise and new IP flags backfill, exactly like the
           initial page. TZ-localise is per row; geo backfill is ONE call scoped
           to the appended rows ARRAY (MINOR #5) — not the whole tbody — so flags
           resolved on a previous batch are never re-scanned, and the batch is
           still a single geo POST rather than one per row. */
        function enhanceAppendedRows(rows) {
            rows.forEach(function (tr) {
                try { if (NS.localiseTimes) NS.localiseTimes(tr); } catch (_e) {}
            });
            try { if (NS.backfillFlags) NS.backfillFlags(rows); } catch (_e) {}
        }

        function finish(endMsg) {
            reachedEnd = true;
            setStatus(endMsg);
            announce(endMsg);
            if (observer) { observer.disconnect(); }
            if (loadMoreWrap) { loadMoreWrap.setAttribute('hidden', ''); }
        }

        function loadNextPage() {
            if (loading || reachedEnd) { return; }
            var cursor = readCursor();
            if (!cursor || !cursor.id) {
                /* No anchor row to seek from — nothing more we can fetch. */
                finish('End of log — all entries loaded.');
                return;
            }
            loading = true;
            setStatus('Loading more…');
            if (loadMoreBtn) { loadMoreBtn.disabled = true; }
            var params = new URLSearchParams(window.location.search);
            params.set('action', 'next-page');
            params.set('cursor_ts', cursor.ts);
            params.set('cursor_id', cursor.id);
            /* page/export have no meaning for the keyset fragment — strip them. */
            params.delete('page');
            params.delete('export');

            fetch(window.location.pathname + '?' + params.toString(), {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            }).then(function (r) {
                if (!r.ok) { throw new Error('HTTP ' + r.status); }
                return r.text();
            }).then(function (html) {
                loading = false;
                if (loadMoreBtn) { loadMoreBtn.disabled = false; }
                /* Parse the returned <tbody> fragment in a detached table so the
                   <tr> rows are valid (a bare <tr> in a <div> would be dropped by
                   the HTML parser). */
                var tmp = document.createElement('table');
                tmp.innerHTML = html;
                var frag = tmp.querySelector('[data-activity-fragment]');
                /* MINOR #4: a 200 with NO fragment wrapper at all is treated as
                   end-of-data (not retried), removing the latent tight-loop where
                   an empty-but-OK body would re-arm the observer forever. */
                if (!frag) {
                    finish('End of log — all entries loaded.');
                    return;
                }
                var allRows = Array.prototype.slice.call(frag.children);
                /* Skip any row id already in the DOM (belt-and-braces dedup). */
                var newRows = allRows.filter(function (tr) {
                    if (tr.nodeType !== 1) { return false; }
                    var id = tr.getAttribute && tr.getAttribute('data-row-id');
                    if (id) {
                        if (seenIds[id]) { return false; }
                        seenIds[id] = true;
                    }
                    return true;
                });
                var atEnd = frag.getAttribute('data-at-end') === '1';
                if (newRows.length) {
                    /* Append into the live tbody, then enhance ONLY these rows
                       (TZ-localise + geo-backfill scoped per row subtree). */
                    newRows.forEach(function (tr) { tbody.appendChild(tr); });
                    loadedCount += newRows.filter(function (tr) {
                        return tr.classList && tr.classList.contains('activity-row')
                            && tr.getAttribute('data-row-id');
                    }).length;
                    enhanceAppendedRows(newRows);
                    if (pageLabel) {
                        pageLabel.textContent = loadedCount + ' entr'
                            + (loadedCount === 1 ? 'y' : 'ies') + ' loaded';
                    }
                }
                /* End-of-data when the server says so OR it returned no usable
                   rows (covers a full page of pure duplicates, which keyset makes
                   essentially impossible but we still guard). */
                if (atEnd || allRows.length === 0) {
                    finish('End of log — all entries loaded.');
                } else {
                    setStatus('Scroll down or use “Load more” to load more.');
                }
            }).catch(function () {
                /* Network / server error — stop trying and leave the no-JS
                   pagination + Load more visible as a manual fallback. */
                loading = false;
                if (loadMoreBtn) { loadMoreBtn.disabled = false; }
                var msg = 'Could not load more — use the page links below.';
                setStatus(msg);
                announce(msg);
                if (pagination) { pagination.removeAttribute('hidden'); }
                if (observer) { observer.disconnect(); }
            });
        }

        function activate() {
            /* The Prev/Next pagination is hidden while infinite scroll is active,
               BUT keyboard / AT users are NOT left without a non-scroll trigger:
               the focusable "Load more" button (revealed just below) replaces it
               1:1 as a Tab-reachable, Enter/Space-activatable control. An
               IntersectionObserver alone can't be driven by keyboard, so this
               button is the accessibility fallback (MINOR #2). */
            if (pagination) { pagination.setAttribute('hidden', ''); }
            if (loadMoreWrap && !reachedEnd) { loadMoreWrap.removeAttribute('hidden'); }
            if (!observer) {
                /* rootMargin pre-fetches the next batch ~400px before the sentinel
                   reaches the viewport, so the append feels seamless. */
                observer = new IntersectionObserver(function (entries) {
                    entries.forEach(function (entry) {
                        if (entry.isIntersecting) { loadNextPage(); }
                    });
                }, { rootMargin: '400px' });
            }
            observer.observe(sentinel);
            if (!reachedEnd) { setStatus('Scroll down or use “Load more” to load more.'); }
        }

        function deactivate() {
            if (observer) { observer.disconnect(); }
            if (pagination) { pagination.removeAttribute('hidden'); }
            if (loadMoreWrap) { loadMoreWrap.setAttribute('hidden', ''); }
            setStatus('');
        }

        /* The focusable button is the keyboard/AT trigger (MINOR #2). */
        if (loadMoreBtn) {
            loadMoreBtn.addEventListener('click', function () { loadNextPage(); });
        }

        /* Persisted preference (default OFF — the user asked for it as an
           OPTION). Restore it, then react to changes. */
        var saved = null;
        try { saved = window.localStorage.getItem(STORAGE_KEY); } catch (_e) {}
        if (saved === '1') { toggle.checked = true; }

        toggle.addEventListener('change', function () {
            try { window.localStorage.setItem(STORAGE_KEY, toggle.checked ? '1' : '0'); } catch (_e) {}
            if (toggle.checked) { activate(); } else { deactivate(); }
        });

        if (toggle.checked) { activate(); }
    })();
    </script>

    <!-- Sortable table headers (#1786 sweep — table was tagged cp-sortable
         but this page never loaded the module, so every header click was
         a silent no-op; #1786 audit caught it). -->
    <script type="module">
        import { bootSortableTables } from '/js/modules/admin-table-sort.js?v=<?= filemtime(dirname(__DIR__) . '/js/modules/admin-table-sort.js') ?>';
        bootSortableTables();
    </script>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>
</body>
</html>
