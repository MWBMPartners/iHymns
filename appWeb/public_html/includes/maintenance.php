<?php

declare(strict_types=1);

/**
 * iHymns — Maintenance mode + app-settings read helpers (WS-K #1021)
 *
 * Central, DB-SAFE place to read tblAppSettings flags and to enforce
 * system maintenance mode at the two PUBLIC entry points (index.php and
 * api.php). The settings read returns its default on ANY DB error, so the
 * maintenance check itself can never throw during an outage — the DB-down
 * case is handled separately by each entry point's bootstrap 503 handler.
 *
 * /manage/* is a SEPARATE entry point (served directly by .htaccess, not
 * routed through index.php / api.php), so admins can ALWAYS reach the
 * dashboard to toggle maintenance off and run setup-database. That means no
 * per-request admin check is needed here — the exemption is structural.
 *
 * Requires getDbMysqli() (includes/db_mysql.php) to be loaded first.
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

/* #1233 — maintenance mode is PER-ENVIRONMENT (alpha/beta/production share one
   DB), so every flag is keyed by the current environment. */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'environment.php';

/** Env-suffixed settings key, e.g. maintenance_mode_alpha / _beta / _production. */
function maintenanceSettingKey(string $base): string
{
    return $base . '_' . ihymns_environment();
}

/**
 * Read a tblAppSettings value, memoized per request. Returns $default on ANY
 * DB error so a maintenance/flag check never throws on a DB outage.
 *
 * @param string      $key
 * @param string|null $default
 * @return string|null
 */
function getAppSetting(string $key, ?string $default = null): ?string
{
    static $cache = [];
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    try {
        $db   = getDbMysqli();
        $stmt = $db->prepare('SELECT SettingValue FROM tblAppSettings WHERE SettingKey = ?');
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_row();
        $stmt->close();
        $cache[$key] = $row !== null ? (string)$row[0] : $default;
    } catch (\Throwable $_e) {
        /* DB unavailable — the caller treats the default as authoritative;
           the DB-down 503 is rendered by the entry point's bootstrap. */
        $cache[$key] = $default;
    }
    return $cache[$key];
}

/** Is THIS environment in admin-triggered maintenance mode? (false on a DB error.) */
function isMaintenanceMode(): bool
{
    return getAppSetting(maintenanceSettingKey('maintenance_mode'), '0') === '1';
}

/** May regular admins (not just global admins) bypass maintenance on THIS env?
 *  Off by default — the global admin opts in per environment. (#1233) */
function maintenanceAllowAdmins(): bool
{
    return getAppSetting(maintenanceSettingKey('maintenance_allow_admins'), '0') === '1';
}

/**
 * Is the CURRENT request from a user allowed to bypass the maintenance page
 * (WordPress-style "logged-in admins still see the live site")? Global admins
 * always; regular admins only when this env's allow-admins flag is on. Everyone
 * else (logged-out, regular users) is gated. Default-DENY on any uncertainty.
 *
 * Auth is loaded lazily here — only reached when maintenance is ON — so the
 * normal (session-less) public hot path pays nothing. It reuses isAuthenticated()
 * / getCurrentUser(), which resolve the cross-surface `ihymns_auth` API token
 * (the /manage/ PHP-session cookie is path-scoped and never reaches the public
 * site), so the bypass is the user's REAL authenticated identity, not spoofable.
 */
function maintenanceUserMayBypass(): bool
{
    try {
        require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'manage'
            . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';
        if (!function_exists('isAuthenticated') || !isAuthenticated()) {
            return false;
        }
        $user = function_exists('getCurrentUser') ? getCurrentUser() : null;
        $role = is_array($user) ? (string)($user['role'] ?? '') : '';
        if ($role === 'global_admin') {
            return true;   // global admins always get through
        }
        if ($role === 'admin' && maintenanceAllowAdmins()) {
            return true;   // regular admins only if the global admin enabled it for this env
        }
        return false;
    } catch (\Throwable $_e) {
        return false;      // any error → show the maintenance page (fail safe)
    }
}

/** The admin-configured maintenance message, or a sensible default. */
function maintenanceMessage(): string
{
    $msg = trim((string)getAppSetting(maintenanceSettingKey('maintenance_message'), ''));
    return $msg !== ''
        ? $msg
        : "iHymns is undergoing scheduled maintenance. We'll be back shortly — please check again in a few minutes.";
}

/* Auto-refresh interval bounds for the maintenance 503 page (#1276 feature A).
   30s floor keeps a stuck-on-maintenance browser from hammering the origin;
   3600s (1h) ceiling keeps the page from sitting stale forever. */
const MAINTENANCE_REFRESH_DEFAULT = 300;
const MAINTENANCE_REFRESH_MIN     = 30;
const MAINTENANCE_REFRESH_MAX      = 3600;

/**
 * Admin-configured auto-refresh interval (seconds) for the maintenance 503
 * page, read from the per-env `maintenance_refresh_seconds_<env>` setting.
 * Parsed to an int, clamped to [MIN, MAX], and falls back to the default on
 * ANY DB/parse error — getAppSetting() already returns the default string on
 * a DB outage, and a non-numeric / out-of-range stored value can never push
 * the timer below the floor. The maintenance page must never throw, so this
 * is total: every code path returns a sane positive integer.
 */
function maintenanceRefreshSeconds(): int
{
    $raw = getAppSetting(maintenanceSettingKey('maintenance_refresh_seconds'), (string)MAINTENANCE_REFRESH_DEFAULT);
    /* filter_var returns false on a non-numeric / empty value → default. */
    $n = filter_var((string)$raw, FILTER_VALIDATE_INT);
    if ($n === false) {
        return MAINTENANCE_REFRESH_DEFAULT;
    }
    return max(MAINTENANCE_REFRESH_MIN, min(MAINTENANCE_REFRESH_MAX, (int)$n));
}

/** Shared 503 headers for maintenance + DB-down responses. */
function maintenanceSend503Headers(): void
{
    if (headers_sent()) return;
    http_response_code(503);
    header('Retry-After: 120');
    header('Cache-Control: no-store');
}

/**
 * Render the branded maintenance landing page (HTML). Fully self-contained —
 * no DB reads beyond the already-resolved message, no external assets — so it
 * renders even mid-outage. The PWA service worker treats this 503 like a
 * network error and serves the cached shell, so returning visitors keep their
 * offline-capable experience while new visitors see this page.
 *
 * Does NOT exit (so callers can decide); enforceMaintenanceForPublicSite()
 * calls it then exits.
 */
function renderMaintenancePageHtml(): void
{
    /* Themed, self-contained 503 via the shared error-page renderer so the
       maintenance page respects the user's light / dark / high-contrast / CVD
       theme instead of the old hardcoded-dark flash. */
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'error_page.php';

    /* Admin-configurable auto-refresh interval (seconds), DB-safe + clamped. */
    $refreshSeconds = maintenanceRefreshSeconds();

    renderErrorPage(503, [
        'title'      => 'Under maintenance',
        'message'    => maintenanceMessage(),
        'emoji'      => '&#128295;',
        'code'       => '',                /* show the wrench + title, not "503" */
        'retryAfter' => $refreshSeconds,   /* HTTP Retry-After mirrors the JS timer */
        'actions'    => [
            /* Padlock-to-login: a logged-out admin can sign in here then
               bypass maintenance. /manage is a SEPARATE entry point served
               directly by .htaccess (RewriteRule ^manage/ - [L]) so it is
               always reachable during maintenance — confirmed in the file
               header above. The padlock icon is CLOSED at rest and OPENS on
               hover/focus (CSS swap of the two glyphs), an affordance hint
               that this unlocks the live site for an admin. */
            ['label' => 'Admin sign-in', 'href' => '/manage/login', 'icon' => 'padlock'],
            ['label' => 'Try again',     'href' => '/',             'primary' => true],
        ],
        'extraHead'      => maintenancePageExtraHead(),
        'extraBody'      => maintenancePageExtraBody($refreshSeconds),
        /* The single aria-live="polite" announcement, placed OUTSIDE the
           <main role="alert"> so the assertive alert can't double-announce it. */
        'extraBodyAfter' => maintenancePageExtraBodyAfter($refreshSeconds),
    ]);
}

/**
 * Inline CSS for the maintenance-page extras (countdown widget + padlock
 * affordance + standalone-PWA safe-area). Self-contained — appended to the
 * error page's own <style> via the renderer's extraHead, so it paints with
 * no dependency on app.css. All animation is gated behind
 * prefers-reduced-motion so it stops dead when the user asks for less motion.
 */
function maintenancePageExtraHead(): string
{
    return <<<'CSS'
<style>
/* Standalone-PWA safe area: when the maintenance page is loaded inside an
   installed PWA, env(safe-area-inset-*) clears the iPhone Dynamic Island /
   notch and the rounded landscape corners. 0px fallback = no-op in a normal
   browser tab. */
@media (display-mode: standalone){
  body{
    padding-top:max(1.5rem,env(safe-area-inset-top,0px));
    padding-bottom:max(1.5rem,env(safe-area-inset-bottom,0px));
    padding-left:max(1.5rem,env(safe-area-inset-left,0px));
    padding-right:max(1.5rem,env(safe-area-inset-right,0px));
  }
}
/* Corner countdown chip — small, semi-transparent, pinned to a page corner
   and clearing any notch in standalone mode. */
.mtn-countdown{
  position:fixed;
  top:calc(0.75rem + env(safe-area-inset-top,0px));
  right:calc(0.75rem + env(safe-area-inset-right,0px));
  display:inline-flex;align-items:center;gap:.4rem;
  padding:.35rem .7rem;border-radius:50px;
  font-size:.8rem;font-weight:600;line-height:1;
  background:var(--ep-card);color:var(--ep-muted);
  border:1px solid var(--ep-border);
  opacity:.6;                       /* semi-transparent at rest */
  box-shadow:0 2px 8px rgba(0,0,0,.06);
  transition:opacity .3s ease;
}
.mtn-countdown:hover{opacity:.95}
/* Subtle pulse on the dot so the eye reads it as a live timer. Disabled
   below for reduced-motion. */
.mtn-countdown-dot{
  width:.5rem;height:.5rem;border-radius:50%;
  background:var(--ep-accent);
  animation:mtn-pulse 2s ease-in-out infinite;
}
@keyframes mtn-pulse{0%,100%{opacity:.4}50%{opacity:1}}
/* Padlock affordance: show the CLOSED lock at rest, swap to the OPEN lock on
   hover/focus of the button, hinting that signing in unlocks the site. */
.ep-btn .mtn-lock-open{display:none}
.ep-btn:hover .mtn-lock-closed,
.ep-btn:focus-visible .mtn-lock-closed{display:none}
.ep-btn:hover .mtn-lock-open,
.ep-btn:focus-visible .mtn-lock-open{display:inline}
.mtn-lock{font-style:normal}
@media (prefers-reduced-motion: reduce){
  /* No pulse, no opacity transition — static, calm indicator. */
  .mtn-countdown{transition:none;opacity:.85}
  .mtn-countdown-dot{animation:none;opacity:1}
}
</style>
CSS;
}

/**
 * The countdown widget markup + the auto-refresh timer script, appended to the
 * page body via the renderer's extraBody. A single setTimeout drives the hard
 * reload at exactly $refreshSeconds; a 1-second setInterval updates the visible
 * countdown so it stays in sync with that one timer (NOT a meta-refresh — that
 * would fire independently and the countdown could never line up).
 *
 * Accessibility: the visible countdown chip is ENTIRELY aria-hidden (its dot
 * and per-second numeric tick never reach a screen reader, so no once-a-second
 * spam). The single aria-live="polite" announcement is NOT emitted here — it is
 * returned by maintenancePageExtraBodyAfter() and placed by the renderer OUTSIDE
 * the <main role="alert"> (via the extraBodyAfter hook) so the assertive alert
 * cannot double-announce it. There is now exactly ONE live region on the page
 * (no nested / overlapping polite-inside-status-inside-alert stack).
 *
 * @param int $refreshSeconds Already clamped to [30, 3600] by the caller.
 */
function maintenancePageExtraBody(int $refreshSeconds): string
{
    /* (int) cast belt-and-braces — the value is interpolated into JS, so it
       must be a bare integer literal and nothing else. */
    $n = (int)$refreshSeconds;
    /* The visible chip is fully aria-hidden (no role="status" — that role was an
       implicit polite live region nested inside the explicit polite span AND
       inside <main role="alert">, the overlap this fix removes). */
    return '<div class="mtn-countdown" aria-hidden="true">'
         . '<span class="mtn-countdown-dot"></span>'
         . '<span>Refreshing in <strong id="mtn-secs">' . $n . '</strong>s</span>'
         . '</div>'
         . '<script>(function(){'
         . 'var n=' . $n . ',el=document.getElementById("mtn-secs"),left=n;'
         . '/* One hard reload at the interval; the SW serves cache on a 503 so this is cheap. */'
         . 'setTimeout(function(){location.reload();},n*1000);'
         . '/* Visible tick — chip is aria-hidden so it never reaches the SR. */'
         . 'var iv=setInterval(function(){left-=1;if(left<0)left=0;if(el)el.textContent=left;'
         . 'if(left<=0)clearInterval(iv);},1000);'
         . '})();</script>';
}

/**
 * The ONE screen-reader announcement for the auto-refresh, placed by the
 * renderer AFTER </main> (extraBodyAfter) — i.e. OUTSIDE the assertive
 * <main role="alert"> — so it is announced once by the polite region alone and
 * is never double-announced by the alert. Announced once on load; the visible
 * per-second tick is aria-hidden (in maintenancePageExtraBody()).
 *
 * @param int $refreshSeconds Already clamped to [30, 3600] by the caller.
 */
function maintenancePageExtraBodyAfter(int $refreshSeconds): string
{
    $n = (int)$refreshSeconds;
    return '<div class="visually-hidden" aria-live="polite">This page refreshes automatically every '
         . $n . ' seconds.</div>';
}

/**
 * Public-site maintenance gate (index.php). If maintenance is on, render the
 * landing page and exit. No-op otherwise, and a no-op on a DB error (leaving
 * index.php's bootstrap handler to surface a DB-down 503).
 */
function enforceMaintenanceForPublicSite(): void
{
    if (!isMaintenanceMode()) {
        return;
    }
    if (maintenanceUserMayBypass()) {
        /* Admin bypass — see the live site; index.php renders a "maintenance is
           on" banner so the admin knows visitors are being gated. */
        $GLOBALS['_ihymnsMaintenanceBypass'] = true;
        return;
    }
    renderMaintenancePageHtml();
    exit;
}

/**
 * API maintenance gate (api.php). Emits a 503 for public requests while
 * maintenance is on, EXCEPT a small allow-list the PWA + auth need:
 *   - app_status   — so the client can learn the flag + show its banner
 *                    (and the service worker keeps serving cached content);
 *   - auth_*        — so users (including admins) can still sign in.
 * Admin curation runs through the separate /manage/* entry point, which never
 * reaches here. JSON for ?action= requests, an inline HTML alert for the
 * SPA's ?page= fragment requests.
 */
function enforceMaintenanceForApi(): void
{
    if (!isMaintenanceMode()) {
        return;
    }

    $action = (string)($_GET['action'] ?? '');
    if ($action === 'app_status' || strncmp($action, 'auth_', 5) === 0) {
        return;
    }
    if (maintenanceUserMayBypass()) {
        return;   // authenticated admin — let their API calls through during maintenance
    }

    maintenanceSend503Headers();
    if ($action !== '') {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=UTF-8');
            header('X-Content-Type-Options: nosniff');
        }
        echo json_encode(
            ['error' => 'maintenance', 'maintenance' => true, 'message' => maintenanceMessage()],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    } else {
        /* ?page= fragment — the SPA injects the response into a <div>. */
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=UTF-8');
        }
        echo '<div class="alert alert-warning" role="status"><strong>Under maintenance.</strong> '
           . htmlspecialchars(maintenanceMessage(), ENT_QUOTES, 'UTF-8') . '</div>';
    }
    exit;
}
