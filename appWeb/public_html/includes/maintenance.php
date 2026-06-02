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

/** Is the site in admin-triggered maintenance mode? (false on a DB error.) */
function isMaintenanceMode(): bool
{
    return getAppSetting('maintenance_mode', '0') === '1';
}

/** The admin-configured maintenance message, or a sensible default. */
function maintenanceMessage(): string
{
    $msg = trim((string)getAppSetting('maintenance_message', ''));
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
    maintenanceSend503Headers();
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=UTF-8');
    }
    $msg = htmlspecialchars(maintenanceMessage(), ENT_QUOTES, 'UTF-8');
    echo <<<HTML
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>iHymns — Maintenance</title>
<style>
 body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#1a1d21;color:#e8e6e3;
      margin:0;padding:2rem;display:flex;min-height:100vh;align-items:center;justify-content:center}
 main{max-width:480px;text-align:center}
 .icon{font-size:2.5rem;margin-bottom:.5rem;line-height:1}
 h1{font-size:1.5rem;margin:0 0 .75rem}
 p{line-height:1.6;margin:.5rem 0;color:#cfd2d6}
</style></head>
<body><main>
 <div class="icon" aria-hidden="true">&#128295;</div>
 <h1>Under maintenance</h1>
 <p>{$msg}</p>
</main></body></html>
HTML;
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
