<?php

declare(strict_types=1);

/**
 * iHymns — Service Lead (Service Mode broadcaster #2, #1335).
 *
 * The SEPARATE LEADER-DEVICE broadcaster (the second of the two front-ends in
 * CLAUDE.md rule #26). Where /manage/service-projection runs ON the projection
 * laptop (and shows the join code to the room), THIS page is what a worship
 * leader opens on a handheld they hold while leading: it CONNECTS to a service
 * session that's already running and drives the congregation's current song —
 * without putting song controls on the projected screen.
 *
 * Both front-ends POST the same `service_broadcast` endpoint and mount the same
 * shared driver (js/modules/service-broadcast.js) — the api.php comment notes
 * the endpoint "serves BOTH broadcaster mechanisms". This page adds only: the
 * list of joinable (active, channel-scoped) sessions the operator may drive, a
 * small read-out of the current join code, and an idle keepalive heartbeat.
 *
 * Auth: a manage operator carries the same-origin `ihymns_auth` cookie, which
 * api.php accepts; the page itself gates to global_admin/admin OR an org-admin
 * (userIsOrgAdminOf), exactly like the projection page.
 *
 * CHANNEL INVARIANT (rule #26): the session list is filtered on the env channel
 * (serviceMode_channel) so an alpha/beta session never appears on production —
 * the prod-stale class of cross-env leak.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_mysql.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'entitlements.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'service_mode.php';

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
    echo '<!DOCTYPE html><html><body><h1>403 — you must manage an organisation to lead a service</h1></body></html>';
    exit;
}
$activePage = 'service-lead';
$db = getDbMysqli();
$channel = serviceMode_channel();

/* Schema-presence guard — Service Mode tables may not be migrated on this env
   yet (migrations aren't auto-applied — rule from project-rules §9). Themed card
   instead of a white-screen. */
function _svcLeadTableExists(\mysqli $db, string $t): bool
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
$schemaReady = _svcLeadTableExists($db, 'tblLiveFollowSessions') && _svcLeadTableExists($db, 'tblServicePresence');

/* The active, drivable service sessions for this operator — CHANNEL-scoped
   (rule #26) and (for non-supers) restricted to the orgs they admin. We show
   IsActive sessions whose occurrence hasn't ended; a session that went stale
   (projection laptop closed) is still revivable — connecting + the keepalive
   heartbeats it fresh — so we do NOT filter on LastHeartbeatAt here. */
$sessions = [];
if ($schemaReady) {
    try {
        $sql = "SELECT s.Id, s.OrgId, s.VenueId, s.OccurrenceDate, s.StartedAt,
                       v.Name AS VenueName, sc.Title AS ScheduleTitle
                  FROM tblLiveFollowSessions s
                  LEFT JOIN tblOrgVenues v          ON v.Id = s.VenueId
                  LEFT JOIN tblOrgServiceSchedules sc ON sc.Id = s.ScheduleId
                 WHERE s.SessionKind = 'service' AND s.IsActive = 1
                   AND s.Channel = ? AND s.ExpiresAt > UTC_TIMESTAMP()";
        if ($isSuper) {
            $stmt = $db->prepare($sql . ' ORDER BY s.StartedAt DESC');
            $stmt->bind_param('s', $channel);
        } else {
            /* Constant-count placeholder string from the admin-org id list (rule #5). */
            $ph    = implode(',', array_fill(0, count($adminOrgIds), '?'));
            $types = 's' . str_repeat('i', count($adminOrgIds));
            $stmt  = $db->prepare($sql . " AND s.OrgId IN ($ph) ORDER BY s.StartedAt DESC");
            $stmt->bind_param($types, $channel, ...$adminOrgIds);
        }
        $stmt->execute();
        $sessions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } catch (\Throwable $e) {
        error_log('[manage/service-lead.php] load: ' . $e->getMessage());
        $schemaReady = false;
    }
}

/* Build the friendly picker labels server-side (so the JSON the JS gets is
   display-ready). Channel/org scoping already applied above. */
$DOW = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
$sessionList = [];
foreach ($sessions as $s) {
    $label = (string)($s['VenueName'] ?? 'Venue');
    if (!empty($s['ScheduleTitle'])) { $label .= ' · ' . (string)$s['ScheduleTitle']; }
    if (!empty($s['OccurrenceDate'])) { $label .= ' · ' . (string)$s['OccurrenceDate']; }
    /* #1425 — expose the numeric session id in the picker label so an operator
       can read it off and type it into the iHymns app (TV Remote → Congregant
       Mirror, PR-14) to mirror the LAN TV state to this Service session.
       Display-only: no endpoint/contract change, the JS already carries
       `sessionId` separately for its own calls. */
    $label .= ' · #' . (int)$s['Id'];
    $sessionList[] = ['sessionId' => (int)$s['Id'], 'label' => $label];
}

/* Public join base — same origin; shown so the leader can read out where to go. */
$joinBase = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://')
          . preg_replace('/[^a-zA-Z0-9.\-:]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'ihymns.app'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lead a Service — iHymns Admin</title>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-libs.php'; ?>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-favicon.php'; ?>
    <style>
        /* The driving panel is a handheld-first card; the code read-out is a
           compact monospace badge (the projector shows the big code, not this). */
        .svc-lead-code { font-family: ui-monospace, "SFMono-Regular", Menlo, Consolas, monospace;
            font-weight: 700; letter-spacing: .08em; font-size: 1.6rem; }
        .sb-now-title { font-size: 1.1rem; }
        .sb-chip, .sb-results .list-group-item { min-height: 2.4rem; } /* comfortable tap targets */
    </style>
</head>
<body>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-nav.php'; ?>

    <div class="container-admin py-4">
        <h1 class="h4 mb-2"><i aria-hidden="true" class="bi bi-music-note-list me-2"></i>Lead a Service</h1>
        <p class="text-secondary small mb-4" style="max-width: 62ch;">
            Connect to a live service running on your projector and drive the songs from this device.
            Congregants following the service see whatever you choose here. To start a service (and show
            the join code), use <a href="/manage/service-projection">Service Projection</a>.
        </p>

        <?php if (!$schemaReady): ?>
            <div class="card border-warning"><div class="card-body">
                <h2 class="h6 text-warning-emphasis"><i aria-hidden="true" class="bi bi-database-exclamation me-1"></i>Service Mode not migrated yet</h2>
                <p class="small mb-2">The Service Mode tables don't exist on this environment yet (migrations aren't auto-applied).</p>
                <a class="btn btn-sm btn-amber-solid" href="/manage/setup-database"><i aria-hidden="true" class="bi bi-database-gear me-1"></i>Run “Service Mode sessions” in Database Setup</a>
            </div></div>
        <?php elseif (!$sessionList): ?>
            <div class="alert alert-info">
                No live service is running right now. Start one on
                <a href="/manage/service-projection">Service Projection</a> first, then come back here to drive the songs.
            </div>
        <?php else: ?>
            <!-- Connect view: pick a running session to drive -->
            <div class="card" id="svc-lead-connect" style="max-width: 560px;">
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold" for="svc-lead-session">Live service</label>
                        <select id="svc-lead-session" class="form-select"></select>
                    </div>
                    <button type="button" id="svc-lead-connect-btn" class="btn btn-amber-solid"><i aria-hidden="true" class="bi bi-broadcast me-1"></i>Connect &amp; drive</button>
                    <div id="svc-lead-connect-error" class="text-danger small mt-2" role="alert"></div>
                </div>
            </div>

            <!-- Drive view: the shared broadcaster console + code read-out -->
            <div class="card d-none" id="svc-lead-drive" style="max-width: 720px;">
                <div class="card-body">
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                        <div>
                            <div class="small text-secondary" id="svc-lead-label"></div>
                            <div class="d-flex align-items-center gap-2">
                                <span class="text-secondary small">Join code:</span>
                                <span class="svc-lead-code" id="svc-lead-code">······</span>
                            </div>
                            <div class="text-secondary small">Congregants: open <strong><?= htmlspecialchars(preg_replace('#^https?://#', '', $joinBase)) ?></strong> → Join service</div>
                        </div>
                        <div class="d-flex gap-2">
                            <button type="button" id="svc-lead-stop" class="btn btn-sm btn-outline-secondary"><i aria-hidden="true" class="bi bi-box-arrow-left me-1"></i>Stop driving</button>
                            <button type="button" id="svc-lead-end" class="btn btn-sm btn-outline-danger"><i aria-hidden="true" class="bi bi-stop-circle me-1"></i>End service</button>
                        </div>
                    </div>
                    <div id="svc-lead-console"></div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($schemaReady && $sessionList): ?>
    <script type="module">
        /* Shared broadcaster core (#1335 rule #26) — identical driver to the
           projection laptop; this page just connects to an existing session. */
        import { ServiceBroadcaster } from '/js/modules/service-broadcast.js';

        /* JSON_HEX_* so an org-admin-controlled venue/schedule name containing a
           script-closing sequence (or < > & ' ") in a session label can't break
           out of this inline module — the established convention (tiers.php).
           NOTE: this comment must not write that closing sequence literally
           either; the HTML parser terminates the <script> on the raw bytes
           regardless of JS comment syntax. */
        const SESSIONS = <?= json_encode($sessionList, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const KEEPALIVE_MS = 45000;   // idle heartbeat — comfortably inside the 180s freshness window
        let session = null, keepaliveTimer = null, broadcaster = null;

        const sessionSel = document.getElementById('svc-lead-session');
        const connectBtn = document.getElementById('svc-lead-connect-btn');
        const connectErr = document.getElementById('svc-lead-connect-error');
        const connectCard = document.getElementById('svc-lead-connect');
        const driveCard  = document.getElementById('svc-lead-drive');
        const labelEl    = document.getElementById('svc-lead-label');
        const codeEl     = document.getElementById('svc-lead-code');
        const consoleEl  = document.getElementById('svc-lead-console');

        SESSIONS.forEach(function (s) {
            const o = document.createElement('option');
            o.value = String(s.sessionId); o.textContent = s.label;
            sessionSel.appendChild(o);
        });

        function apiCall(action, opts) {
            opts = opts || {};
            const init = { method: opts.method || 'GET', headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin', cache: 'no-store' };
            if (opts.body) { init.headers['Content-Type'] = 'application/json'; init.body = JSON.stringify(opts.body); }
            return fetch('/api?action=' + action + (opts.query || ''), init).then(function (r) { return r.json().catch(function () { return {}; }); });
        }

        function showCode(code) { codeEl.textContent = code || '······'; }

        /* Keepalive: rotating the code doubles as a session heartbeat (per the
           api.php service_code_rotate comment), keeping the session fresh even if
           no projection laptop is open and no song has changed in a while. */
        function startKeepalive() {
            if (keepaliveTimer) clearInterval(keepaliveTimer);
            keepaliveTimer = setInterval(function () {
                if (!session) return;
                apiCall('service_code_rotate', { method: 'POST', body: { sessionId: session.sessionId } })
                    .then(function (d) { if (d && d.ok && d.code) showCode(d.code); })
                    .catch(function () {});
            }, KEEPALIVE_MS);
        }

        function teardown(message) {
            if (keepaliveTimer) { clearInterval(keepaliveTimer); keepaliveTimer = null; }
            if (broadcaster) { broadcaster.destroy(); broadcaster = null; }
            session = null;
            driveCard.classList.add('d-none');
            connectCard.classList.remove('d-none');
            connectErr.textContent = message || '';
        }

        connectBtn.addEventListener('click', function () {
            connectErr.textContent = '';
            const sid = parseInt(sessionSel.value, 10);
            if (!sid) { connectErr.textContent = 'Pick a service to drive.'; return; }
            connectBtn.disabled = true;
            /* Verify the session is still live + grab the current code. */
            apiCall('service_code_current', { method: 'GET', query: '&sessionId=' + sid }).then(function (d) {
                connectBtn.disabled = false;
                if (!d || !d.ok) { connectErr.textContent = (d && d.error) || 'That service is no longer active.'; return; }
                session = { sessionId: sid };
                const opt = SESSIONS.find(function (x) { return x.sessionId === sid; });
                labelEl.textContent = opt ? opt.label : '';
                showCode(d.code);
                connectCard.classList.add('d-none');
                driveCard.classList.remove('d-none');
                startKeepalive();
                broadcaster = new ServiceBroadcaster({
                    apiCall: apiCall,
                    sessionId: sid,
                    mount: consoleEl,
                    onSessionEnded: function () { teardown('The service has ended.'); },
                });
                broadcaster.init();
            }).catch(function () { connectBtn.disabled = false; connectErr.textContent = 'Network error.'; });
        });

        /* Stop driving = leave the console locally; the service keeps running
           (the projection / another device may still drive it). */
        document.getElementById('svc-lead-stop').addEventListener('click', function () { teardown(''); });

        /* End service = end it for everyone (revokes all presence). Confirmed. */
        document.getElementById('svc-lead-end').addEventListener('click', function () {
            if (!session) { return; }
            if (!window.confirm('End the service for everyone following it?')) { return; }
            const sid = session.sessionId;
            apiCall('service_session_end', { method: 'POST', body: { sessionId: sid } }).catch(function () {});
            teardown('Service ended.');
            /* Drop the ended session from the picker so it can't be re-selected. */
            const opt = sessionSel.querySelector('option[value="' + sid + '"]');
            if (opt) { opt.remove(); }
            if (!sessionSel.options.length) { window.location.reload(); }
        });

        // Refresh the displayed code when the tab regains focus.
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden && session) {
                apiCall('service_code_current', { method: 'GET', query: '&sessionId=' + session.sessionId })
                    .then(function (d) { if (d && d.ok && d.code) showCode(d.code); }).catch(function () {});
            }
        });
    </script>
    <?php endif; ?>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>
</body>
</html>
