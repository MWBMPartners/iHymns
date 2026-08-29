<?php

declare(strict_types=1);

/**
 * iHymns — Service Projection (Service Mode Phase 2b, #1335)
 *
 * The web-runnable PROJECTOR page (operator is web-only on shared DreamHost —
 * this is a browser tab the worship leader opens on the projector / foldback
 * laptop). It starts a venue/service session and displays a large, rotating
 * JOIN CODE that congregants enter in iHymns to follow the service (Phase 2c)
 * — and, once CCLI-gated, get a temporary presence unlock (Phase 3).
 *
 * Auth: a manage operator carries the same-origin `ihymns_auth` cookie, which
 * api.php's getAuthBearerToken() accepts — so this page's JS calls the
 * service_* operator endpoints (api.php) with just the X-Requested-With CSRF
 * header (the #293 pattern); the cookie authenticates. The page itself is gated
 * to global_admin/admin or an org-admin/owner (userIsOrgAdminOf).
 *
 * Reuses the shared admin chrome for the SETUP view; projection mode is a
 * full-bleed, high-contrast overlay. Beside the large rotating join code it
 * now also renders a scannable QR of the join URL (#1339, the "buildable
 * half" — see renderQr() below), served by the same-origin /qr endpoint
 * which generates it via our CueRCode service server-side (owner directive
 * 2026-08-05 — QR via CueRCode; the secret key never reaches this page). The QR
 * is an ACCELERATOR, not a replacement: the typed code stays the primary,
 * always-visible fallback for a congregant without a working camera (or a
 * screen reader — the QR carries no information the code text doesn't
 * already, so it's marked aria-hidden), and any QR generation failure
 * degrades to a plain-text notice beside the still-working code — never a
 * blank box. NOT covered here: the live multi-device SCAN verification
 * (the other half of #1339) — that needs real phones and has never once
 * been run; do not treat this file as proof it works on real hardware.
 *
 * Broadcaster (#1335): once a session is live, a dockable OPERATOR CONSOLE
 * (the shared js/modules/service-broadcast.js — same core the leader-device
 * page uses) lets the worship leader drive the congregation's current song +
 * section from this laptop. It's collapsible for a clean code-only screen; a
 * handheld leader who'd rather not show controls on the projector uses the
 * separate /manage/service-lead page instead (both POST `service_broadcast`).
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_mysql.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'entitlements.php';
/* #1840 — Projector corner bug (Option B): ihymnsOrgLogoResolveThemedAsset()
   + orgLogoListForOrg()/orgLogoTableExists(), used below to resolve each
   venue's org logo ONCE, server-side (org + theme are both known here —
   the overlay's ground is fixed dark, rule #16 does not even arise). */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'org_logo_helpers.php';

if (!isAuthenticated()) {
    header('Location: /manage/login');
    exit;
}
$currentUser = getCurrentUser();
$role   = (string)($currentUser['role'] ?? '');
$userId = (int)($currentUser['id'] ?? $currentUser['Id'] ?? 0);
/* Entitlement, not a hardcoded role list (#1648 item 1, rule #1587).
   ELI5: check the permission the menu advertises, not two role names.
   Detail: `manage_organisations` defaults to ['admin','global_admin'] — exactly
   the list this replaces — so behaviour is unchanged until an admin overrides
   it via /manage/entitlements, which is the point of having that UI.
   NOTE the org-admin branch below is deliberately KEPT: someone who administers
   an organisation but holds neither super role still needs this page, and the
   entitlement alone cannot express that. Replacing the whole gate with the
   entitlement would have locked those users out — a functional regression
   dressed as a security fix. */
$isSuper = userHasEntitlement('manage_organisations', $role);
$adminOrgIds = $isSuper ? [] : userIsOrgAdminOf($userId);
if (!$isSuper && empty($adminOrgIds)) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><body><h1>403 — you must manage an organisation to run a service projection</h1></body></html>';
    exit;
}
$activePage = 'service-projection';
$db = getDbMysqli();

/* Schema-presence guard — Service Mode tables may not be migrated on this env
   yet (migrations aren't auto-applied). Themed card instead of a white-screen. */
function _svcProjTableExists(\mysqli $db, string $t): bool
{
    try {
        $stmt = $db->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1");
        $stmt->bind_param('s', $t);
        $stmt->execute();
        $ok = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();
        return $ok;
    } catch (\Throwable $e) { return false; }
}
$schemaReady = _svcProjTableExists($db, 'tblOrgVenues') && _svcProjTableExists($db, 'tblLiveFollowJoinCodes');

/* Load venues (+ their schedules) the operator may run, for the picker. */
$venues = [];
if ($schemaReady) {
    try {
        if ($isSuper) {
            $res = $db->query('SELECT Id, OrgId, Name, TimeZone FROM tblOrgVenues WHERE IsActive = 1 ORDER BY Name ASC');
            $venues = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
        } else {
            /* Constant-count placeholder string from the admin-org id list (rule #5). */
            $ph = implode(',', array_fill(0, count($adminOrgIds), '?'));
            $types = str_repeat('i', count($adminOrgIds));
            $stmt = $db->prepare("SELECT Id, OrgId, Name, TimeZone FROM tblOrgVenues WHERE IsActive = 1 AND OrgId IN ($ph) ORDER BY Name ASC");
            $stmt->bind_param($types, ...$adminOrgIds);
            $stmt->execute();
            $venues = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }
        /* Attach each venue's active schedules. */
        foreach ($venues as &$v) {
            $sstmt = $db->prepare("SELECT Id, Title, DayOfWeek, StartTime, DurationMins FROM tblOrgServiceSchedules WHERE VenueId = ? AND IsActive = 1 ORDER BY DayOfWeek, StartTime");
            $vid = (int)$v['Id'];
            $sstmt->bind_param('i', $vid);
            $sstmt->execute();
            $v['schedules'] = $sstmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $sstmt->close();
        }
        unset($v);
    } catch (\Throwable $e) {
        error_log('[manage/service-projection.php] load: ' . $e->getMessage());
        $schemaReady = false;
    }
}

/* #1840 §6.1 — resolve each DISTINCT org's projector corner-bug logo ONCE,
   server-side (org known per venue; the overlay's ground is fixed dark
   #0b1020, so theme is hardcoded 'dark' — never a client-side resolve, rule
   #16 does not even arise here). Attached to every venue row as
   `OrgLogoUrl`, riding the existing VENUES JSON below. Un-migrated / no
   active logo for that org -> null -> the client shows nothing (§9's
   dormancy matrix); a DB blip here degrades the SAME way, never a fatal on
   the projector's setup screen. */
if ($schemaReady && $venues) {
    $orgLogoUrlByOrg = [];
    foreach ($venues as $v) {
        $vOrgId = (int)$v['OrgId'];
        if (array_key_exists($vOrgId, $orgLogoUrlByOrg)) {
            continue; // already resolved this org for an earlier venue
        }
        $orgLogoUrlByOrg[$vOrgId] = null;
        if (!orgLogoTableExists($db)) {
            continue;
        }
        try {
            $activeRows = array_values(array_filter(
                orgLogoListForOrg($db, $vOrgId),
                static fn(array $r): bool => (int)$r['IsActive'] === 1
            ));
            $shaByKindVariant = [];
            foreach ($activeRows as $r) {
                $shaByKindVariant[$r['Kind'] . '|' . $r['Variant']] = (string)$r['Sha256'];
            }
            $asset = ihymnsOrgLogoResolveThemedAsset(
                array_map(
                    static fn(array $r): array => ['kind' => (string)$r['Kind'], 'variant' => (string)$r['Variant']],
                    $activeRows
                ),
                'projector',
                'dark'
            );
            if ($asset !== null) {
                $sha = $shaByKindVariant[$asset['kind'] . '|' . $asset['variant']] ?? '';
                /* "/org-logo", never "/org-logo.php" — the extensionless
                   .htaccess alias (rules #33/#38/#42) is the ONLY reachable
                   route: a literal ".php" in the raw request line trips the
                   "block direct PHP access" rule before org-logo.php's own
                   code ever runs, so a ".php" src here would 404 silently. */
                $orgLogoUrlByOrg[$vOrgId] = '/org-logo?org=' . $vOrgId
                    . '&kind=' . rawurlencode($asset['kind'])
                    . '&variant=' . rawurlencode($asset['variant'])
                    . '&v=' . rawurlencode($sha);
            }
        } catch (\Throwable $e) {
            error_log('[manage/service-projection.php] org-logo resolve: ' . $e->getMessage());
            /* leave null — the client simply shows no corner bug */
        }
    }
    foreach ($venues as &$v) {
        $v['OrgLogoUrl'] = $orgLogoUrlByOrg[(int)$v['OrgId']] ?? null;
    }
    unset($v);
}

/* Public join base — the host the congregant app lives on (same origin). */
$joinBase = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://')
          . preg_replace('/[^a-zA-Z0-9.\-:]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'ihymns.app'));
$DOW = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

/* #1770 §4.6 — "Presentation-app control" card: mint/list/revoke durable
   driver keys for an external presentation app (ProPresenter-class shim,
   a Stream Deck script, a Companion webhook) to drive THIS org's Service
   Mode sessions via the api.php `service_drive` action. Own probe — the
   #1770 C1 batch is a SEPARATE migration from the #1335 Phase-2 tables
   $schemaReady already gates on, so a $schemaReady install can still be
   pre-#1770 (the page's existing pattern: probe-gate, don't assume). */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'service_driver_keys.php';
$driverKeysReady = _svcProjTableExists($db, 'tblServiceDriverKeys');
/* Orgs to offer in the picker: every org that has at least one venue THIS
   operator can already see above (never a superset of what they can drive —
   the server-side org-admin gate on the mint/revoke/list endpoints is the
   real authority, this is just what the picker offers). */
$driverKeyOrgs = [];
if ($driverKeysReady && $venues) {
    $orgIdsForKeys = array_values(array_unique(array_map(static fn(array $v): int => (int)$v['OrgId'], $venues)));
    if ($orgIdsForKeys) {
        $ph = implode(',', array_fill(0, count($orgIdsForKeys), '?'));
        $types = str_repeat('i', count($orgIdsForKeys));
        $stmt = $db->prepare("SELECT Id, Name FROM tblOrganisations WHERE Id IN ($ph) ORDER BY Name ASC");
        $stmt->bind_param($types, ...$orgIdsForKeys);
        $stmt->execute();
        $driverKeyOrgs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Projector Screen — iHymns Admin</title>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-libs.php'; ?>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-favicon.php'; ?>
    <style>
        /* Full-bleed projection overlay — high-contrast, theme-independent. */
        #svc-projection { position: fixed; inset: 0; z-index: 2000; background: #0b1020; color: #fff;
            display: none; flex-direction: column; align-items: center; justify-content: center; text-align: center; padding: 4vmin; }
        #svc-projection.active { display: flex; }
        .svc-proj-venue { font-size: 3.2vmin; opacity: .8; margin-bottom: 1vmin; }
        .svc-proj-instr { font-size: 3.6vmin; opacity: .9; margin-bottom: 3vmin; }
        .svc-proj-code { font-size: 22vmin; font-weight: 800; letter-spacing: .08em; line-height: 1;
            font-family: ui-monospace, "SFMono-Regular", Menlo, Consolas, monospace; }
        /* Code + QR sit side by side on a wide projector, stack on a narrow
           preview — flex-wrap does the responsive work for free. The QR
           always gets its own solid-white card: the overlay's dark
           background (#0b1020, fixed regardless of the admin's own
           data-bs-theme — this is a projection surface, not admin chrome)
           would otherwise sit directly behind the QR's transparent SVG
           corners and starve it of the light quiet-zone real scanners
           expect around the dark modules. */
        .svc-proj-join { display: flex; align-items: center; justify-content: center;
            gap: 4vmin; flex-wrap: wrap; }
        #svc-proj-qr-wrap { flex: 0 0 auto; width: 26vmin; height: 26vmin; min-width: 160px; min-height: 160px;
            background: #fff; border-radius: 1.2vmin; padding: 1.6vmin; box-shadow: 0 6px 22px rgba(0,0,0,.4);
            display: flex; align-items: center; justify-content: center; }
        #svc-proj-qr-wrap.d-none { display: none; }
        #svc-proj-qr { width: 100%; height: 100%; }
        #svc-proj-qr svg { display: block; width: 100%; height: 100%; }
        .svc-proj-url { font-size: 4vmin; margin-top: 4vmin; opacity: .85; }
        .svc-proj-qr-fallback { font-size: 2.6vmin; opacity: .85; margin-top: 1.5vmin; max-width: 70ch; }
        .svc-proj-qr-fallback.d-none { display: none; }
        .svc-proj-foot { position: absolute; bottom: 3vmin; font-size: 2.4vmin; opacity: .6; }
        #svc-end-btn { position: absolute; top: 3vmin; right: 3vmin; }
        /* #1840 §6.2/§6.3 — org corner bug: TOP-LEFT is the one always-free
           corner (End button owns top-right, foot text bottom-centre, the
           operator console bottom-centre up to 720px wide). Low opacity so it
           reads as signage, never content; pointer-events:none so it can
           never eat a click aimed at anything layered nearby. */
        #svc-proj-logo { position: absolute; top: 3vmin; left: 3vmin;
            max-height: 8vmin; max-width: 20vmin; object-fit: contain;
            opacity: .35; pointer-events: none; }
        /* Operator console — docked bottom-left of the projection overlay so the
           worship leader can drive songs from the projection laptop. Light card
           on the dark overlay so the Bootstrap controls keep normal contrast.
           Toggleable to hidden for a clean code-only screen. */
        #svc-op-console { position: absolute; left: 2vmin; right: 2vmin; bottom: 2vmin;
            max-width: 720px; margin: 0 auto; background: rgba(255,255,255,.96); color: #1b1f29;
            border-radius: .6rem; padding: .75rem .9rem; box-shadow: 0 6px 24px rgba(0,0,0,.45);
            text-align: left; max-height: 62vh; overflow-y: auto; font-size: 1rem; }
        #svc-op-console.collapsed #svc-op-body { display: none; }
        #svc-op-console .svc-op-head { display: flex; align-items: center; justify-content: space-between; gap: .5rem; }
        #svc-op-console .svc-op-title { font-weight: 600; font-size: .95rem; }
        .sb-now-title { font-size: 1.05rem; }
    </style>
</head>
<body>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-nav.php'; ?>

    <div class="container-admin py-4">
        <h1 class="h4 mb-2"><i aria-hidden="true" class="bi bi-projector me-2"></i>Projector Screen</h1>
        <p class="text-secondary small mb-4" style="max-width: 62ch;">
            Start a live service and project a join code your congregation enters in iHymns to follow along.
            Open this on the screen the congregation can see. The code rotates automatically.
        </p>

        <?php if (!$schemaReady): ?>
            <div class="card border-warning"><div class="card-body">
                <h2 class="h6 text-warning-emphasis"><i aria-hidden="true" class="bi bi-database-exclamation me-1"></i>Service Mode not migrated yet</h2>
                <p class="small mb-2">The Service Mode tables don't exist on this environment yet (migrations aren't auto-applied).</p>
                <a class="btn btn-sm btn-amber-solid" href="/manage/setup-database"><i aria-hidden="true" class="bi bi-database-gear me-1"></i>Run “Service Mode sessions” in Database Setup</a>
            </div></div>
        <?php elseif (!$venues): ?>
            <div class="alert alert-info">No venues to run yet. Add a venue + service times under <a href="/manage/venues">Venues</a> first.</div>
        <?php else: ?>
            <div class="card" style="max-width: 560px;">
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold" for="svc-venue">Venue</label>
                        <select id="svc-venue" class="form-select"></select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold" for="svc-schedule">Service</label>
                        <select id="svc-schedule" class="form-select"></select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold" for="svc-date">Date</label>
                        <input type="date" id="svc-date" class="form-control">
                    </div>
                    <!-- #1840 §6.4 — projector corner-bug operator toggle. Per-DEVICE
                         preference (localStorage on THIS projection screen, not an
                         org setting) — a projection laptop is stable and venue-bound,
                         so "this screen, this room" is how the preference is
                         actually exercised. Default ON (§6.4's justification). -->
                    <div class="mb-3 form-check">
                        <input class="form-check-input" type="checkbox" id="svc-logo-toggle-setup" checked>
                        <label class="form-check-label small" for="svc-logo-toggle-setup">
                            Show your organisation's logo on the projection
                        </label>
                    </div>
                    <button type="button" id="svc-start-btn" class="btn btn-amber-solid"><i aria-hidden="true" class="bi bi-play-fill me-1"></i>Start &amp; project</button>
                    <div id="svc-start-error" class="text-danger small mt-2" role="alert"></div>
                </div>
            </div>
        <?php endif; ?>

        <!-- ===========================
             PRESENTATION-APP CONTROL (#1770 §4.6) — durable driver keys for
             an external presentation app (ProPresenter-class shim, a Stream
             Deck script, a Companion webhook) to drive Service Mode sessions
             without a human clicking here. Own probe from $schemaReady above
             — #1770 C1 is a separate migration.
             =========================== -->
        <?php if ($schemaReady): ?>
        <div class="card mt-4" style="max-width: 720px;">
            <div class="card-body">
                <h2 class="h6 mb-2"><i aria-hidden="true" class="bi bi-key me-2"></i>Presentation-app control</h2>
                <p class="small text-secondary mb-3">
                    Mint a durable key so an external presentation app can drive this organisation's
                    live session — advance the song and section — without a person clicking here.
                    The key is shown once; paste it straight into the shim's config.
                </p>
                <?php if (!$driverKeysReady): ?>
                    <div class="alert alert-warning small mb-0">
                        <i aria-hidden="true" class="bi bi-database-exclamation me-1"></i>Not migrated yet on this environment.
                        <a href="/manage/setup-database">Run “Live Follow: capable Quick sessions” in Database Setup</a>.
                    </div>
                <?php elseif (!$driverKeyOrgs): ?>
                    <div class="alert alert-info small mb-0">
                        Add a venue under <a href="/manage/venues">Venues</a> first — a driver key belongs to the
                        organisation that owns a venue.
                    </div>
                <?php else: ?>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold" for="dk-org">Organisation</label>
                        <select id="dk-org" class="form-select form-select-sm" style="max-width: 320px;">
                            <?php foreach ($driverKeyOrgs as $o): ?>
                                <option value="<?= (int)$o['Id'] ?>"><?= htmlspecialchars((string)$o['Name'], ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div id="dk-list-wrap" class="mb-3">
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead>
                                    <tr class="text-secondary small">
                                        <th scope="col">Label</th><th scope="col">Prefix</th><th scope="col">Venue</th><th scope="col">Protocol</th>
                                        <th scope="col">Last used</th><th scope="col" class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="dk-list-body">
                                    <tr><td colspan="6" class="text-secondary small">Loading…</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <form id="dk-mint-form" class="row g-2 align-items-end">
                        <div class="col-sm-4">
                            <label class="form-label small" for="dk-label">Label</label>
                            <input type="text" id="dk-label" class="form-control form-control-sm"
                                   placeholder="e.g. Sanctuary ProPresenter" maxlength="120" required>
                        </div>
                        <div class="col-sm-3">
                            <label class="form-label small" for="dk-venue">Venue <span class="text-secondary">(optional)</span></label>
                            <select id="dk-venue" class="form-select form-select-sm">
                                <option value="">Any venue of this org</option>
                            </select>
                        </div>
                        <div class="col-sm-3">
                            <label class="form-label small" for="dk-protocol">Protocol</label>
                            <select id="dk-protocol" class="form-select form-select-sm">
                                <?php foreach (SERVICE_DRIVER_KEY_PROTOCOLS as $proto): ?>
                                    <option value="<?= htmlspecialchars($proto, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(ucfirst($proto), ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-sm-2">
                            <button type="submit" class="btn btn-sm btn-amber-solid w-100"><i aria-hidden="true" class="bi bi-key me-1"></i>Mint</button>
                        </div>
                    </form>
                    <div id="dk-mint-result" class="small mt-2"></div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Full-bleed projection overlay -->
    <div id="svc-projection" role="dialog" aria-label="Service join code">
        <button type="button" id="svc-end-btn" class="btn btn-outline-light btn-sm"><i aria-hidden="true" class="bi bi-x-lg me-1"></i>End service</button>
        <!-- #1840 §6.2 — org corner bug. alt="" + aria-hidden: purely decorative
             signage, mirrors the QR's own accessibility posture above. Hidden by
             default (d-none) until a src is set AND loads successfully — never a
             broken-image glyph on a wall (§6.2/§A "Projector <img> failure"). -->
        <img id="svc-proj-logo" class="d-none" alt="" aria-hidden="true">
        <div class="svc-proj-venue" id="svc-proj-venue"></div>
        <div class="svc-proj-instr">Scan the QR, or in iHymns tap <strong>Join service</strong> and enter:</div>
        <div class="svc-proj-join">
            <!-- aria-hidden: purely a visual accelerator for a phone camera —
                 the typed code alongside it (and read via svc-proj-code below)
                 already conveys everything a screen reader needs (#1339). -->
            <div id="svc-proj-qr-wrap" class="d-none" aria-hidden="true">
                <div id="svc-proj-qr"></div>
            </div>
            <div class="svc-proj-code" id="svc-proj-code">······</div>
        </div>
        <div class="svc-proj-url" id="svc-proj-url"></div>
        <!-- Shown only when QR generation fails (both CDN + vendored copy) —
             the typed code above keeps working on its own either way. -->
        <div id="svc-proj-qr-fallback" class="svc-proj-qr-fallback d-none" role="status"></div>
        <div class="svc-proj-foot">The code changes regularly — the latest one always works.</div>

        <!-- Operator console (#1335): drive the congregation's current song from
             the projection laptop. Mounts the shared ServiceBroadcaster. -->
        <div id="svc-op-console">
            <div class="svc-op-head">
                <span class="svc-op-title"><i aria-hidden="true" class="bi bi-music-note-list me-1"></i>Drive songs</span>
                <!-- #1425 — the numeric session id, for typing into the iHymns
                     app (TV Remote → Congregant Mirror, PR-14) so the LAN TV
                     state also mirrors to this Service session. Set on start;
                     display-only. -->
                <span id="svc-op-session" class="small text-secondary" title="Enter this number in the iHymns app (TV Remote → Congregant Mirror) to mirror the TV to congregants"></span>
                <!-- #1840 §6.4 — flip the corner-bug preference mid-service without
                     ending the session. Drives the SAME localStorage state as the
                     setup-card checkbox above. -->
                <button type="button" id="svc-op-logo-toggle" class="btn btn-sm btn-outline-dark">Logo</button>
                <button type="button" id="svc-op-toggle" class="btn btn-sm btn-outline-dark">Hide</button>
            </div>
            <div id="svc-op-body"></div>
        </div>
    </div>

    <?php if ($schemaReady && $venues): ?>
    <script type="module">
        /* Module so it can import the shared broadcaster (#1335 rule #26 — the
           projection laptop + the leader device share ONE driver core). */
        import { ServiceBroadcaster } from '/js/modules/service-broadcast.js';

        /* JSON_HEX_* so an org-admin-controlled venue/schedule Name containing a
           script-closing sequence (or < > & ' ") can't break out of this inline
           module — the established convention (tiers.php, musicians.php).
           NOTE: this comment must not write that closing sequence literally
           either; the HTML parser terminates the <script> on the raw bytes
           regardless of JS comment syntax. */
        const VENUES = <?= json_encode($venues, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const DOW = <?= json_encode($DOW, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const JOIN_BASE = <?= json_encode($joinBase, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        /* QR is now served by the same-origin /qr endpoint (CueRCode-backed,
           owner directive 2026-08-05) — no client-side QR library is loaded here
           any more; renderQr() below just points an <img> at it. */
        const ROTATE_MS = 30000;
        let session = null, rotateTimer = null, broadcaster = null;

        const venueSel = document.getElementById('svc-venue');
        const schedSel = document.getElementById('svc-schedule');
        const dateInp  = document.getElementById('svc-date');
        const startBtn = document.getElementById('svc-start-btn');
        const startErr = document.getElementById('svc-start-error');
        const overlay  = document.getElementById('svc-projection');
        const console_ = document.getElementById('svc-op-console');
        const consoleBody = document.getElementById('svc-op-body');
        const toggleBtn = document.getElementById('svc-op-toggle');

        /* #1840 §6.4 — projector corner-bug operator toggle. PER-DEVICE
           localStorage (this projection screen, not an org setting) — a
           projection laptop is stable/venue-bound, so "this screen, this
           room" matches how the preference is actually exercised. Default
           ON: uploading a resolvable logo is already the org's deliberate
           opt-in (no logo = the toggle changes nothing), and a default-OFF
           toggle on a screen operators rarely explore is the
           undiscovered-feature failure class this repo documents
           elsewhere (rule #30's lesson, generalised). Wrapped in try/catch
           (private-window safe), falling back to ON on any storage error. */
        const LOGO_PREF_KEY = 'ihymns_svcproj_logo';
        function logoPrefGet() {
            try {
                const v = localStorage.getItem(LOGO_PREF_KEY);
                return v === null ? true : v === '1';
            } catch (_e) { return true; }
        }
        function logoPrefSet(on) {
            try { localStorage.setItem(LOGO_PREF_KEY, on ? '1' : '0'); } catch (_e) { /* private window — no-op */ }
        }

        const logoImg = document.getElementById('svc-proj-logo');
        const logoSetupCheck = document.getElementById('svc-logo-toggle-setup');
        const logoOpToggle = document.getElementById('svc-op-logo-toggle');
        /* Set by the start handler below to whichever venue is live, so the
           operator-console toggle (which has no venue context of its own)
           can re-apply it. null while no session is active. */
        let currentVenueForLogo = null;

        /** Reflect the CURRENT stored preference onto both controls (setup
         *  checkbox + operator button) — called on boot and after either
         *  control changes it, so the two never disagree. */
        function logoPrefReflectControls() {
            const on = logoPrefGet();
            if (logoSetupCheck) { logoSetupCheck.checked = on; }
            if (logoOpToggle) {
                logoOpToggle.classList.toggle('active', on);
                logoOpToggle.setAttribute('aria-pressed', on ? 'true' : 'false');
            }
        }

        /** Show/hide #svc-proj-logo for the CURRENT session's venue, honouring
         *  the toggle. `<img onerror>`/`onload` — never a broken-image glyph
         *  on a wall (§6.2/§A of the plan); called on session start AND
         *  whenever the operator flips the toggle mid-service. */
        function logoApplyForVenue(venue) {
            if (!logoImg) { return; }
            const url = venue && venue.OrgLogoUrl;
            if (!logoPrefGet() || !url) {
                logoImg.classList.add('d-none');
                logoImg.removeAttribute('src');
                return;
            }
            if (logoImg.getAttribute('src') !== url) {
                logoImg.src = url;
            }
            /* If already loaded (cached), the load/error handlers below have
               already fired once — re-check readiness directly. */
            if (logoImg.complete && logoImg.naturalWidth > 0) {
                logoImg.classList.remove('d-none');
            }
        }
        if (logoImg) {
            logoImg.addEventListener('load', function () { logoImg.classList.remove('d-none'); });
            logoImg.addEventListener('error', function () {
                logoImg.classList.add('d-none');
                logoImg.removeAttribute('src');
            });
        }
        logoPrefReflectControls();
        if (logoSetupCheck) {
            logoSetupCheck.addEventListener('change', function () {
                logoPrefSet(logoSetupCheck.checked);
                logoPrefReflectControls();
            });
        }
        if (logoOpToggle) {
            logoOpToggle.addEventListener('click', function () {
                logoPrefSet(!logoPrefGet());
                logoPrefReflectControls();
                logoApplyForVenue(currentVenueForLogo);
            });
        }

        /* Default date = today (LOCAL, #1576). ELI5: `toISOString()` always
           reports the UTC calendar date, not the operator's own "today" —
           for a venue WEST of UTC (most of the Americas), local evening
           already falls on the NEXT UTC calendar day (e.g. 7pm US Eastern
           is already after midnight UTC), so an operator starting an
           evening service saw TOMORROW's date pre-filled instead of
           today's. DETAILED: `toLocaleDateString('en-CA')` happens to
           render as 'YYYY-MM-DD' (the en-CA locale's format, and exactly
           the shape an <input type="date"> value needs) but — unlike
           `toISOString()` — it's computed from the browser's LOCAL
           clock/timezone, not UTC. See
           https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/Date/toLocaleDateString */
        dateInp.value = new Date().toLocaleDateString('en-CA');

        VENUES.forEach(function (v) {
            const o = document.createElement('option');
            o.value = String(v.Id); o.textContent = v.Name;
            venueSel.appendChild(o);
        });
        function fillSchedules() {
            schedSel.innerHTML = '';
            const v = VENUES.find(function (x) { return String(x.Id) === venueSel.value; });
            const none = document.createElement('option'); none.value = ''; none.textContent = '— ad-hoc (no set time) —'; schedSel.appendChild(none);
            (v && v.schedules || []).forEach(function (s) {
                const o = document.createElement('option'); o.value = String(s.Id);
                const d = DOW[String(s.DayOfWeek)] || '';
                o.textContent = (s.Title || 'Service') + (d ? ' · ' + d : '') + ' ' + String(s.StartTime || '').slice(0, 5);
                schedSel.appendChild(o);
            });
        }
        venueSel.addEventListener('change', fillSchedules);
        fillSchedules();

        /* Shared call shape the ServiceBroadcaster expects: (action, {method,query,body}).
           Cookie-authed (same-origin); X-Requested-With satisfies the CSRF guard. */
        function apiCall(action, opts) {
            opts = opts || {};
            const init = { method: opts.method || 'GET', headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin', cache: 'no-store' };
            if (opts.body) { init.headers['Content-Type'] = 'application/json'; init.body = JSON.stringify(opts.body); }
            return fetch('/api?action=' + action + (opts.query || ''), init).then(function (r) { return r.json().catch(function () { return {}; }); });
        }

        /* ---- QR join code (#1339 "buildable half") -------------------------
           Renders a scannable QR of the join URL beside the typed code — an
           ACCELERATOR, never a replacement (rule from the issue brief): the
           typed code is the authoritative, always-visible, screen-reader
           fallback whether or not the QR ever renders.

           QR source: the same-origin /qr endpoint, which generates the code
           via our CueRCode service server-side (owner directive 2026-08-05 — QR
           via CueRCode across iHymns; the secret key never reaches this page).
           This replaced the old client-side vendored `qrcode-generator` dynamic
           import. /qr answers 503 when CueRCode isn't configured/reachable,
           so the <img> simply fails to load and the typed-code fallback shows —
           and a new code rotation (30s later) issues a fresh <img> that retries. */
        const qrWrap = document.getElementById('svc-proj-qr-wrap');
        const qrBox = document.getElementById('svc-proj-qr');
        const qrFallback = document.getElementById('svc-proj-qr-fallback');

        /* The real join route (js/modules/service-follow.js's joinService() +
           the anonymous POST-only `service_join` action in api.php) never
           auto-joins from a URL — a congregant types the code into the
           "Join a live service" prompt. Scanning this URL saves the trip to
           find the site + the button: it opens straight to the app (same
           origin this session is bound to, so the #1268/rule-#26 Channel
           wall is automatic). #1770 C6 — `service-follow.js`'s init() now
           READS this `svc_code` param (it didn't for the QR's first ~5
           weeks live, #1339→#1770 — the standing rule-#33 gap the #1770
           plan's analysis flagged): it strips the param, validates it with
           the same fold a typed code goes through, and opens a one-tap
           CONFIRM prompt pre-filled with the code — never an auto-join
           (anti-probe + no join-on-prefetch/link-preview). */
        function joinUrlFor(code) {
            return JOIN_BASE + '/?svc_code=' + encodeURIComponent(code);
        }

        function renderQr(code) {
            if (!qrBox || !qrWrap || !qrFallback || !code) return;
            /* SVG scales, so a fixed 512 canvas is crisp at any projector size. */
            /* "/qr", never "/qr.php" — the extensionless .htaccess alias
               (rules #33/#38) is the only reachable route; see .htaccess. */
            const src = '/qr?data=' + encodeURIComponent(joinUrlFor(code)) + '&format=svg&size=512';
            const img = document.createElement('img');
            img.alt = 'QR code to join the service';
            img.className = 'img-fluid';
            /* Listeners attached BEFORE src is set (below) so a cached instant
               load can't fire before we're listening. */
            img.addEventListener('load', function () {
                qrWrap.classList.remove('d-none');
                qrFallback.classList.add('d-none');
                qrFallback.textContent = '';
            });
            img.addEventListener('error', function () {
                /* Never a blank box — hide the QR card and point at the still-
                   working typed code. /qr answers 503 when CueRCode isn't
                   configured/reachable. console.error so /manage/*'s error
                   monitor (#1599) surfaces it even though nobody watches the
                   projector's console. */
                console.error('[service-projection] QR image failed to load from /qr');
                qrBox.innerHTML = '';
                qrWrap.classList.add('d-none');
                qrFallback.classList.remove('d-none');
                qrFallback.textContent = 'QR code unavailable right now — use the code above.';
            });
            qrBox.innerHTML = '';
            qrBox.appendChild(img);
            img.src = src;
        }

        function showCode(code) {
            document.getElementById('svc-proj-code').textContent = code || '······';
            document.getElementById('svc-proj-url').textContent = JOIN_BASE.replace(/^https?:\/\//, '') + '  ·  Join service';
            renderQr(code);
        }
        function startRotate() {
            if (rotateTimer) clearInterval(rotateTimer);
            rotateTimer = setInterval(function () {
                if (!session) return;
                apiCall('service_code_rotate', { method: 'POST', body: { sessionId: session.sessionId } }).then(function (d) {
                    if (d && d.ok && d.code) showCode(d.code);
                });
            }, ROTATE_MS);
        }

        function teardown() {
            if (rotateTimer) { clearInterval(rotateTimer); rotateTimer = null; }
            if (broadcaster) { broadcaster.destroy(); broadcaster = null; }
            session = null;
            overlay.classList.remove('active');
            /* #1840 — clear the corner bug with everything else; the NEXT
               session re-applies logoApplyForVenue() for its own venue. */
            currentVenueForLogo = null;
            if (logoImg) { logoImg.classList.add('d-none'); logoImg.removeAttribute('src'); }
        }

        startBtn.addEventListener('click', function () {
            startErr.textContent = '';
            startBtn.disabled = true;
            const v = VENUES.find(function (x) { return String(x.Id) === venueSel.value; });
            apiCall('service_session_start', { method: 'POST', body: {
                venueId: parseInt(venueSel.value, 10),
                scheduleId: schedSel.value ? parseInt(schedSel.value, 10) : 0,
                occurrenceDate: dateInp.value
            } }).then(function (d) {
                startBtn.disabled = false;
                if (!d || !d.ok) { startErr.textContent = (d && d.error) || 'Could not start the service.'; return; }
                session = d;
                document.getElementById('svc-proj-venue').textContent = (v ? v.Name : '');
                /* #1425 — surface the numeric session id for the app mirror. */
                document.getElementById('svc-op-session').textContent = 'Session #' + session.sessionId;
                showCode(d.code);
                overlay.classList.add('active');
                /* #1840 §6.1/§6.4 — apply the server-resolved corner-bug URL
                   for THIS venue, honouring the operator's current toggle. */
                currentVenueForLogo = v || null;
                logoApplyForVenue(currentVenueForLogo);
                startRotate();
                /* Mount the song-driver console (the projection-laptop broadcaster). */
                broadcaster = new ServiceBroadcaster({
                    apiCall: apiCall,
                    sessionId: session.sessionId,
                    mount: consoleBody,
                    onSessionEnded: function () { teardown(); },
                });
                broadcaster.init();
            }).catch(function () { startBtn.disabled = false; startErr.textContent = 'Network error.'; });
        });

        toggleBtn.addEventListener('click', function () {
            const collapsed = console_.classList.toggle('collapsed');
            toggleBtn.textContent = collapsed ? 'Drive songs' : 'Hide';
        });

        document.getElementById('svc-end-btn').addEventListener('click', function () {
            if (session) apiCall('service_session_end', { method: 'POST', body: { sessionId: session.sessionId } });
            teardown();
        });

        // Re-fetch the current code when the tab regains focus (timers throttle when hidden).
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden && session) {
                apiCall('service_code_current', { method: 'GET', query: '&sessionId=' + session.sessionId })
                    .then(function (d) { if (d && d.ok && d.code) showCode(d.code); }).catch(function () {});
            }
        });

        /* ---- Presentation-app control (#1770 §4.6) ------------------------
           Only present in the DOM when $driverKeysReady && $driverKeyOrgs
           (PHP-gated above), so this whole block is a no-op elsewhere.
           Same-origin AJAX via the SAME apiCall() helper above — the
           X-Requested-With header alone satisfies validateCsrfRequest()
           (rule #29) for the two writers (mint/revoke). */
        const dkOrgSel = document.getElementById('dk-org');
        if (dkOrgSel) {
            const dkVenueSel   = document.getElementById('dk-venue');
            const dkListBody   = document.getElementById('dk-list-body');
            const dkMintForm   = document.getElementById('dk-mint-form');
            const dkMintResult = document.getElementById('dk-mint-result');

            const dkEsc = function (s) {
                return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
                    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
                });
            };
            const dkFmtDate = function (s) {
                if (!s) return '—';
                try { return new Date(String(s).replace(' ', 'T') + 'Z').toLocaleString(); } catch (_e) { return dkEsc(s); }
            };

            function dkFillVenues() {
                const orgId = dkOrgSel.value;
                dkVenueSel.innerHTML = '<option value="">Any venue of this org</option>';
                VENUES.filter(function (v) { return String(v.OrgId) === orgId; }).forEach(function (v) {
                    const o = document.createElement('option');
                    o.value = String(v.Id); o.textContent = v.Name;
                    dkVenueSel.appendChild(o);
                });
            }

            function dkRenderList(keys) {
                dkListBody.innerHTML = '';
                if (!keys.length) {
                    dkListBody.innerHTML = '<tr><td colspan="6" class="text-secondary small">No driver keys yet.</td></tr>';
                    return;
                }
                keys.forEach(function (k) {
                    const venue = k.venueId ? (VENUES.find(function (v) { return v.Id === k.venueId; }) || {}).Name || ('#' + k.venueId) : 'Any';
                    const tr = document.createElement('tr');
                    if (!k.active) { tr.classList.add('text-secondary'); }
                    tr.innerHTML = '<td>' + dkEsc(k.label) + '</td>'
                        + '<td><code>' + dkEsc(k.prefix) + '…</code></td>'
                        + '<td>' + dkEsc(venue) + '</td>'
                        + '<td>' + dkEsc(k.protocol) + '</td>'
                        + '<td>' + dkFmtDate(k.lastUsedAt) + '</td>'
                        + '<td class="text-end"></td>';
                    if (k.active) {
                        const revokeBtn = document.createElement('button');
                        revokeBtn.type = 'button';
                        revokeBtn.className = 'btn btn-sm btn-outline-danger';
                        revokeBtn.textContent = 'Revoke';
                        revokeBtn.addEventListener('click', function () { dkRevoke(k.id); });
                        tr.lastElementChild.appendChild(revokeBtn);
                    } else {
                        tr.lastElementChild.textContent = 'revoked';
                    }
                    dkListBody.appendChild(tr);
                });
            }

            function dkLoadList() {
                dkListBody.innerHTML = '<tr><td colspan="6" class="text-secondary small">Loading…</td></tr>';
                apiCall('service_driver_key_list', { method: 'GET', query: '&orgId=' + encodeURIComponent(dkOrgSel.value) })
                    .then(function (d) { dkRenderList((d && d.keys) || []); })
                    .catch(function () { dkListBody.innerHTML = '<tr><td colspan="6" class="text-danger small">Could not load keys.</td></tr>'; });
            }

            function dkRevoke(id) {
                if (!window.confirm('Revoke this driver key? Anything using it stops working immediately.')) { return; }
                apiCall('service_driver_key_revoke', { method: 'POST', body: { id: id, orgId: parseInt(dkOrgSel.value, 10) } })
                    .then(function () { dkLoadList(); });
            }

            dkOrgSel.addEventListener('change', function () { dkFillVenues(); dkLoadList(); });
            dkFillVenues();
            dkLoadList();

            dkMintForm.addEventListener('submit', function (e) {
                e.preventDefault();
                dkMintResult.innerHTML = '';
                const label = document.getElementById('dk-label').value.trim();
                if (!label) { return; }
                const body = {
                    orgId: parseInt(dkOrgSel.value, 10),
                    label: label,
                    protocol: document.getElementById('dk-protocol').value,
                };
                const venueVal = dkVenueSel.value;
                if (venueVal) { body.venueId = parseInt(venueVal, 10); }
                apiCall('service_driver_key_mint', { method: 'POST', body: body }).then(function (d) {
                    if (!d || !d.ok) {
                        dkMintResult.innerHTML = '<span class="text-danger">' + dkEsc((d && d.error) || 'Could not mint the key.') + '</span>';
                        return;
                    }
                    /* The RAW key is shown here ONCE — the server never stores
                       it (only its SHA-256 hash), so this is the only chance
                       to copy it. user-select:all makes triple-click select
                       the whole thing. */
                    dkMintResult.innerHTML = '<div class="alert alert-warning py-2 px-3 mb-0">'
                        + '<strong>Copy this key now — it will not be shown again:</strong><br>'
                        + '<code style="user-select:all;">' + dkEsc(d.key) + '</code></div>';
                    document.getElementById('dk-label').value = '';
                    dkLoadList();
                }).catch(function () {
                    dkMintResult.innerHTML = '<span class="text-danger">Network error.</span>';
                });
            });
        }
    </script>
    <?php endif; ?>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>
</body>
</html>
