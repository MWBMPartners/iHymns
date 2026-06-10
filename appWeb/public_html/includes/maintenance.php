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
    renderErrorPage(503, [
        'title'      => 'Under maintenance',
        'message'    => maintenanceMessage(),
        'emoji'      => '&#128295;',
        'code'       => '',                /* show the wrench + title, not "503" */
        'retryAfter' => 120,
        'actions'    => [['label' => 'Try again', 'href' => '/', 'primary' => true]],
    ]);
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
