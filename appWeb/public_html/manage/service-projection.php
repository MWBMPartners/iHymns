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
 * half" — see renderQr() below), via the vendored `qrcode-generator`
 * library (kazuhikoarase, MIT; pinned + SRI-recorded per rule #1587 —
 * APP_CONFIG['libraries']['qrcodegen'] in includes/config.php). The QR is
 * an ACCELERATOR, not a replacement: the typed code stays the primary,
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
                <span class="svc-op-title"><i class="bi bi-music-note-list me-1"></i>Drive songs</span>
                <!-- #1425 — the numeric session id, for typing into the iHymns
                     app (TV Remote → Congregant Mirror, PR-14) so the LAN TV
                     state also mirrors to this Service session. Set on start;
                     display-only. -->
                <span id="svc-op-session" class="small text-secondary" title="Enter this number in the iHymns app (TV Remote → Congregant Mirror) to mirror the TV to congregants"></span>
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
        /* Pinned CDN URL + SRI (reference-only, see includes/config.php) + local
           vendored fallback path for the QR renderer (#1339, rule #1587). Same
           registry index.php/api-docs.php read for Bootstrap/jQuery/Swagger UI. */
        const QR_LIB = <?= json_encode(APP_CONFIG['libraries']['qrcodegen'] ?? null, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
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

           Library: the vendored `qrcode-generator` (kazuhikoarase, MIT — see
           includes/config.php's QR_LIB entry for the full pinning rationale),
           loaded via dynamic import() from the pinned CDN URL with a fallback
           to the local vendored copy — the SAME pattern sheet-music.js
           already uses for PDF.js, because browsers don't support the
           `integrity` attribute on dynamic import() the way they do for
           `<script src>`. qrModulePromise memoises a SUCCESSFUL load; a
           failure clears it so the next code rotation (30s later) retries
           rather than staying broken for the rest of the service. */
        const qrWrap = document.getElementById('svc-proj-qr-wrap');
        const qrBox = document.getElementById('svc-proj-qr');
        const qrFallback = document.getElementById('svc-proj-qr-fallback');
        let qrModulePromise = null;

        function loadQrModule() {
            if (qrModulePromise) return qrModulePromise;
            const attempt = (async function () {
                if (!QR_LIB || !QR_LIB.js_cdn || !QR_LIB.js_local) {
                    throw new Error('QR library not registered in APP_CONFIG.');
                }
                let mod;
                try {
                    mod = await import(/* webpackIgnore: true */ QR_LIB.js_cdn);
                } catch (cdnErr) {
                    console.warn('[service-projection] QR CDN import failed, trying local vendor copy:', cdnErr);
                    mod = await import('/' + QR_LIB.js_local);
                }
                const factory = mod && (mod.default || mod.qrcode);
                if (typeof factory !== 'function') { throw new Error('Unexpected QR module shape.'); }
                return factory;
            })();
            attempt.catch(function () { qrModulePromise = null; });
            qrModulePromise = attempt;
            return attempt;
        }

        /* The real join route (js/modules/service-follow.js's joinService() +
           the anonymous POST-only `service_join` action in api.php) has no
           URL-based auto-join today — a congregant always lands on the home
           page and types the code into the "Join a live service" prompt.
           Scanning this URL still saves the trip to find the site + the
           button: it opens straight to the app (same origin this session is
           bound to, so the #1268/rule-#26 Channel wall is automatic), where
           the code is displayed prominently either way. The `svc_code` query
           param is otherwise inert today (the home route ignores unknown
           query strings) but forward-compatible with a future auto-fill —
           see the doc-comment on serviceMode_resolveJoin()'s "QR deep-link"
           remark in includes/service_mode.php. */
        function joinUrlFor(code) {
            return JOIN_BASE + '/?svc_code=' + encodeURIComponent(code);
        }

        async function renderQr(code) {
            if (!qrBox || !qrWrap || !qrFallback || !code) return;
            try {
                const qrcodeFactory = await loadQrModule();
                /* 0 = auto-pick the smallest version that fits the URL; 'M'
                   (~15% recovery) trades some error-correction headroom for
                   larger, more camera-friendly modules at a fixed box size —
                   this is a short static URL, not a noisy environment, so 'H'
                   would only shrink modules for no real robustness gain. */
                const qr = qrcodeFactory(0, 'M');
                qr.addData(joinUrlFor(code), 'Byte');
                qr.make();
                qrBox.innerHTML = qr.createSvgTag({ scalable: true, alt: 'QR code to join the service' });
                qrWrap.classList.remove('d-none');
                qrFallback.classList.add('d-none');
                qrFallback.textContent = '';
            } catch (e) {
                /* Never a blank box — hide the (possibly half-drawn) QR card
                   and say so in plain text next to the still-working typed
                   code. console.error so /manage/*'s error monitor (#1599)
                   can surface this in the activity log even though nobody's
                   watching the projector's console. */
                console.error('[service-projection] QR generation failed:', e);
                qrBox.innerHTML = '';
                qrWrap.classList.add('d-none');
                qrFallback.classList.remove('d-none');
                qrFallback.textContent = 'QR code unavailable right now — use the code above.';
            }
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
    </script>
    <?php endif; ?>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>
</body>
</html>
