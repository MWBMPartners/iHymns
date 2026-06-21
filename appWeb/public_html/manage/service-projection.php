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
 * full-bleed, high-contrast overlay. The rotating QR is a planned drop-in
 * (no QR lib is bundled yet) — for now the large typeable code + join URL are
 * shown, which is the accessible fallback we'd keep beside a QR anyway.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_mysql.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'entitlements.php';

if (!isAuthenticated()) {
    header('Location: /manage/login');
    exit;
}
$currentUser = getCurrentUser();
$role   = (string)($currentUser['role'] ?? '');
$userId = (int)($currentUser['id'] ?? $currentUser['Id'] ?? 0);
$isSuper = ($role === 'global_admin' || $role === 'admin');
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

/* Public join base — the host the congregant app lives on (same origin). */
$joinBase = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://')
          . preg_replace('/[^a-zA-Z0-9.\-:]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'ihymns.app'));
$DOW = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Projection — iHymns Admin</title>
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
        .svc-proj-url { font-size: 4vmin; margin-top: 4vmin; opacity: .85; }
        .svc-proj-foot { position: absolute; bottom: 3vmin; font-size: 2.4vmin; opacity: .6; }
        #svc-end-btn { position: absolute; top: 3vmin; right: 3vmin; }
    </style>
</head>
<body>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-nav.php'; ?>

    <div class="container-admin py-4">
        <h1 class="h4 mb-2"><i class="bi bi-projector me-2"></i>Service Projection</h1>
        <p class="text-secondary small mb-4" style="max-width: 62ch;">
            Start a live service and project a join code your congregation enters in iHymns to follow along.
            Open this on the screen the congregation can see. The code rotates automatically.
        </p>

        <?php if (!$schemaReady): ?>
            <div class="card border-warning"><div class="card-body">
                <h2 class="h6 text-warning-emphasis"><i class="bi bi-database-exclamation me-1"></i>Service Mode not migrated yet</h2>
                <p class="small mb-2">The Service Mode tables don't exist on this environment yet (migrations aren't auto-applied).</p>
                <a class="btn btn-sm btn-amber-solid" href="/manage/setup-database"><i class="bi bi-database-gear me-1"></i>Run “Service Mode sessions” in Database Setup</a>
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
                    <button type="button" id="svc-start-btn" class="btn btn-amber-solid"><i class="bi bi-play-fill me-1"></i>Start &amp; project</button>
                    <div id="svc-start-error" class="text-danger small mt-2" role="alert"></div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Full-bleed projection overlay -->
    <div id="svc-projection" role="dialog" aria-label="Service join code">
        <button type="button" id="svc-end-btn" class="btn btn-outline-light btn-sm"><i class="bi bi-x-lg me-1"></i>End service</button>
        <div class="svc-proj-venue" id="svc-proj-venue"></div>
        <div class="svc-proj-instr">In iHymns, tap <strong>Join service</strong> and enter:</div>
        <div class="svc-proj-code" id="svc-proj-code">······</div>
        <div class="svc-proj-url" id="svc-proj-url"></div>
        <div class="svc-proj-foot">The code changes regularly — the latest one always works.</div>
    </div>

    <?php if ($schemaReady && $venues): ?>
    <script>
    (function () {
        var VENUES = <?= json_encode($venues, JSON_UNESCAPED_UNICODE) ?>;
        var DOW = <?= json_encode($DOW) ?>;
        var JOIN_BASE = <?= json_encode($joinBase) ?>;
        var ROTATE_MS = 30000;
        var api = '/api';
        var session = null, rotateTimer = null;

        var venueSel = document.getElementById('svc-venue');
        var schedSel = document.getElementById('svc-schedule');
        var dateInp  = document.getElementById('svc-date');
        var startBtn = document.getElementById('svc-start-btn');
        var startErr = document.getElementById('svc-start-error');
        var overlay  = document.getElementById('svc-projection');

        // Default date = today (local).
        dateInp.value = new Date().toISOString().slice(0, 10);

        VENUES.forEach(function (v, i) {
            var o = document.createElement('option');
            o.value = String(v.Id); o.textContent = v.Name;
            venueSel.appendChild(o);
        });
        function fillSchedules() {
            schedSel.innerHTML = '';
            var v = VENUES.find(function (x) { return String(x.Id) === venueSel.value; });
            var none = document.createElement('option'); none.value = ''; none.textContent = '— ad-hoc (no set time) —'; schedSel.appendChild(none);
            (v && v.schedules || []).forEach(function (s) {
                var o = document.createElement('option'); o.value = String(s.Id);
                var d = DOW[String(s.DayOfWeek)] || '';
                o.textContent = (s.Title || 'Service') + (d ? ' · ' + d : '') + ' ' + String(s.StartTime || '').slice(0, 5);
                schedSel.appendChild(o);
            });
        }
        venueSel.addEventListener('change', fillSchedules);
        fillSchedules();

        function apiCall(action, method, body) {
            var opts = { method: method, headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' };
            if (body) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
            return fetch(api + '?action=' + action, opts).then(function (r) { return r.json().catch(function () { return {}; }); });
        }

        function showCode(code) {
            document.getElementById('svc-proj-code').textContent = code || '······';
            document.getElementById('svc-proj-url').textContent = JOIN_BASE.replace(/^https?:\/\//, '') + '  ·  Join service';
        }
        function startRotate() {
            if (rotateTimer) clearInterval(rotateTimer);
            rotateTimer = setInterval(function () {
                if (!session) return;
                apiCall('service_code_rotate', 'POST', { sessionId: session.sessionId }).then(function (d) {
                    if (d && d.ok && d.code) showCode(d.code);
                });
            }, ROTATE_MS);
        }

        startBtn.addEventListener('click', function () {
            startErr.textContent = '';
            startBtn.disabled = true;
            var v = VENUES.find(function (x) { return String(x.Id) === venueSel.value; });
            apiCall('service_session_start', 'POST', {
                venueId: parseInt(venueSel.value, 10),
                scheduleId: schedSel.value ? parseInt(schedSel.value, 10) : 0,
                occurrenceDate: dateInp.value
            }).then(function (d) {
                startBtn.disabled = false;
                if (!d || !d.ok) { startErr.textContent = (d && d.error) || 'Could not start the service.'; return; }
                session = d;
                document.getElementById('svc-proj-venue').textContent = (v ? v.Name : '');
                showCode(d.code);
                overlay.classList.add('active');
                startRotate();
            }).catch(function () { startBtn.disabled = false; startErr.textContent = 'Network error.'; });
        });

        document.getElementById('svc-end-btn').addEventListener('click', function () {
            if (rotateTimer) clearInterval(rotateTimer);
            if (session) apiCall('service_session_end', 'POST', { sessionId: session.sessionId });
            session = null;
            overlay.classList.remove('active');
        });

        // Re-fetch the current code when the tab regains focus (timers throttle when hidden).
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden && session) {
                fetch(api + '?action=service_code_current&sessionId=' + session.sessionId, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); }).then(function (d) { if (d && d.ok && d.code) showCode(d.code); }).catch(function () {});
            }
        });
    })();
    </script>
    <?php endif; ?>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>
</body>
</html>
