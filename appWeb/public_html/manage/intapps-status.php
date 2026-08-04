<?php

declare(strict_types=1);

/**
 * iHymns — Admin: IntAppsAPI Gateway Status (#1725/#1732) — manage_configuration only
 *
 * ELI5: a read-only dashboard for the (dormant-by-default) MWBM-IntAppsAPI
 * gateway integration — "is it on, what did it last hear from the gateway,
 * and did that go well?" — plus two buttons: one that rings the gateway
 * right now and shows exactly what came back, and one that forces a
 * refresh of the cached feature-flag snapshot.
 *
 * DETAIL:
 * Credentials + the per-channel enablement allow-list live on the
 * IntAppsAPI card on `/manage/configuration` (this page never writes
 * credentials — it only reads `tblIntAppsSync` + the resolved config state
 * and, on demand, makes ONE live call). Gated on the SAME entitlement the
 * nav entry advertises (`manage_configuration`, `admin-links.php` — rule
 * #1587: a page's own gate must never diverge from what the menu promises).
 *
 * Two POST actions, both same-origin form submits (plain `validateCsrf()`
 * is the correct tool here, not `validateCsrfRequest()` — this is a
 * synchronous full-page reload on a page that is never left open for a
 * long-lived AJAX session, so the baked session-token staleness class
 * rule #29 exists for does not apply):
 *   - `test_connection` — `intappsRequest('GET', '/v1/heartbeat')` — the
 *     ONLY place in the whole integration a human-triggered SYNCHRONOUS
 *     gateway call happens (design §7). Renders the typed result.
 *   - `refresh_now` — `intappsForceRefresh()` (stress-test remedy 7:
 *     bypasses TTL AND backoff, honours only the single-flight lock, so
 *     this button can never be a silent no-op during a failure streak —
 *     it always renders either the fetch result or an explicit "another
 *     refresh is already in flight").
 *
 * Copy convention (delta §3d): the UNCONFIGURED state reads "Dormant —
 * awaiting gateway registration (#1726)", never red/error styling — a
 * production install can sit in this state for weeks before the owner-only
 * gateway-registration prerequisite (#1726) closes, and an operator seeing
 * red on a healthy install would "fix" a non-problem.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_mysql.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'intapps_client.php';

if (!isAuthenticated()) {
    header('Location: /manage/login');
    exit;
}
$currentUser = getCurrentUser();
if (!$currentUser || !userHasEntitlement('manage_configuration', $currentUser['role'] ?? null)) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><body><h1>403 — manage_configuration required</h1></body></html>';
    exit;
}
$activePage = 'intapps-status';

$db   = getDbMysqli();
$csrf = csrfToken();

$actionResult = null; /* ['action' => string, 'result' => array]|null — rendered below */

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!validateCsrf((string)($_POST['csrf_token'] ?? ''))) {
        $actionResult = ['action' => 'error', 'result' => ['errorMessage' => 'CSRF token invalid — refresh the page and try again.']];
    } else {
        $postAction = (string)($_POST['action'] ?? '');
        if ($postAction === 'test_connection') {
            $result = intappsRequest('GET', '/v1/heartbeat');
            if (function_exists('logActivity')) {
                logActivity('intappsapi.test_connection', 'app_setting', 'intappsapi', [
                    'transport' => $result['transport'], 'ok' => $result['ok'], 'httpStatus' => $result['httpStatus'],
                ], $result['ok'] ? 'success' : 'failure');
            }
            $actionResult = ['action' => 'test_connection', 'result' => $result];
        } elseif ($postAction === 'refresh_now') {
            $result = intappsForceRefresh($db, 'features');
            if (function_exists('logActivity')) {
                logActivity('intappsapi.refresh_now', 'app_setting', 'intappsapi', [
                    'transport' => $result['transport'], 'ok' => $result['ok'],
                ], $result['ok'] ? 'success' : 'failure');
            }
            $actionResult = ['action' => 'refresh_now', 'result' => $result];
        }
    }
}

/* Resolved state — the SAME functions every consumer calls (rule #35: no
   second copy of the enabled/configured decision on this page). */
$configured   = intappsConfig() !== null;
$hasChannels  = ((string)(getAppSetting(INTAPPS_SETTING_ENABLED_CHANNELS, '') ?? '')) !== '';
$resolvedOn   = intappsEnabled();
$currentChannel = function_exists('ihymns_environment') ? ihymns_environment() : 'production';

$featuresRow = intappsSyncRow($db, 'features'); /* null when disabled, table absent, or cold */

if ($featuresRow !== null && $featuresRow['fetchedAt'] !== null) {
    $ageSeconds = time() - (int)strtotime($featuresRow['fetchedAt'] . ' UTC');
    $isStale = $ageSeconds > INTAPPS_TTL_SECONDS;
} else {
    $ageSeconds = null;
    $isStale = true;
}

require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-favicon.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IntApps Gateway Status — iHymns Admin</title>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-libs.php'; ?>
</head>
<body>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-nav.php'; ?>

    <div class="container-admin py-4">

        <h1 class="h4 mb-3"><i class="bi bi-broadcast-pin me-2"></i>IntApps Gateway Status</h1>
        <p class="text-secondary small mb-4">
            Read-only view of the MWBM-IntAppsAPI gateway integration's runtime state.
            Credentials + the per-channel enabled-channels allow-list are set on
            <a href="/manage/configuration" class="link-light">Configuration</a>.
            This page never edits gateway-side flags — that stays on the gateway's own
            admin UI; iHymns only ever consumes.
        </p>

        <?php if (!$configured): ?>
            <div class="alert alert-secondary d-flex align-items-start gap-2 py-2 mb-4">
                <i class="bi bi-info-circle mt-1"></i>
                <div>
                    <strong>Dormant — awaiting gateway registration (#1726).</strong>
                    No credentials are saved yet, so the integration performs zero HTTP calls
                    and zero <code>tblIntAppsSync</code> reads. This is the expected state on
                    every install until the owner-only gateway-registration prerequisite
                    closes and credentials are pasted on
                    <a href="/manage/configuration" class="link-light">Configuration</a> —
                    not an error to fix.
                </div>
            </div>
        <?php elseif (!$hasChannels): ?>
            <div class="alert alert-secondary d-flex align-items-start gap-2 py-2 mb-4">
                <i class="bi bi-info-circle mt-1"></i>
                <div>
                    <strong>Dormant — credentials are set, but no channel is enabled.</strong>
                    Add <code><?= htmlspecialchars($currentChannel, ENT_QUOTES, 'UTF-8') ?></code>
                    (this channel) to the enabled-channels field on
                    <a href="/manage/configuration" class="link-light">Configuration</a> to
                    start using this integration here.
                </div>
            </div>
        <?php elseif (!$resolvedOn): ?>
            <div class="alert alert-warning d-flex align-items-start gap-2 py-2 mb-4">
                <i class="bi bi-exclamation-triangle mt-1"></i>
                <div>
                    <strong>Enabled-channels lists a channel, but the credential set is still
                    incomplete.</strong> The integration behaves EXACTLY as if it were
                    disabled until every field on
                    <a href="/manage/configuration" class="link-light">Configuration</a> is
                    set — this is the documented fail-open default, not a crash.
                </div>
            </div>
        <?php else: ?>
            <div class="alert alert-success d-flex align-items-start gap-2 py-2 mb-4">
                <i class="bi bi-check-circle mt-1"></i>
                <div>
                    <strong>Active</strong> on channel
                    <code><?= htmlspecialchars($currentChannel, ENT_QUOTES, 'UTF-8') ?></code>.
                </div>
            </div>
        <?php endif; ?>

        <?php if ($actionResult !== null && $actionResult['action'] === 'error'): ?>
            <div class="alert alert-danger py-2">
                <?= htmlspecialchars((string)$actionResult['result']['errorMessage'], ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php elseif ($actionResult !== null): ?>
            <?php $r = $actionResult['result']; ?>
            <div class="alert <?= $r['ok'] ? 'alert-success' : 'alert-warning' ?> py-2">
                <strong><?= $actionResult['action'] === 'test_connection' ? 'Test connection' : 'Refresh now' ?>:</strong>
                transport=<code><?= htmlspecialchars((string)$r['transport'], ENT_QUOTES, 'UTF-8') ?></code>
                <?php if ($r['httpStatus'] !== null): ?>
                    · HTTP <?= (int)$r['httpStatus'] ?>
                <?php endif; ?>
                <?php if ($r['errorCode'] !== null): ?>
                    · code <code><?= htmlspecialchars((string)$r['errorCode'], ENT_QUOTES, 'UTF-8') ?></code>
                <?php endif; ?>
                <?php if ($r['errorMessage'] !== null): ?>
                    · <?= htmlspecialchars((string)$r['errorMessage'], ENT_QUOTES, 'UTF-8') ?>
                <?php endif; ?>
                · <?= number_format((float)$r['durationMs'], 1) ?>&nbsp;ms
            </div>
        <?php endif; ?>

        <div class="card bg-dark border-secondary mb-4">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h2 class="h6 mb-0"><i class="bi bi-clock-history me-1"></i>Scope: features</h2>
                <?php if ($featuresRow !== null): ?>
                    <span class="badge <?= $isStale ? 'bg-warning text-dark' : 'bg-success' ?>">
                        <?= $isStale ? 'stale' : 'fresh' ?>
                    </span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if ($featuresRow === null): ?>
                    <p class="text-secondary small mb-0">
                        No snapshot yet — either the integration is dormant, the migration
                        hasn't been run on this install yet, or nothing has fetched from the
                        gateway since it was enabled. Click <strong>Refresh now</strong> below
                        to warm the cache deliberately.
                    </p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-3">
                            <tbody>
                                <tr>
                                    <th class="text-secondary" style="width:220px;">Last fetched</th>
                                    <td>
                                        <?= $featuresRow['fetchedAt'] !== null ? htmlspecialchars($featuresRow['fetchedAt'], ENT_QUOTES, 'UTF-8') . ' UTC' : '<span class="text-secondary">never (cold)</span>' ?>
                                        <?php if ($ageSeconds !== null): ?>
                                            <span class="text-secondary small">(<?= (int)$ageSeconds ?>s ago, TTL <?= INTAPPS_TTL_SECONDS ?>s)</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th class="text-secondary">Last attempted</th>
                                    <td><?= $featuresRow['attemptedAt'] !== null ? htmlspecialchars((string)$featuresRow['attemptedAt'], ENT_QUOTES, 'UTF-8') . ' UTC' : '<span class="text-secondary">never</span>' ?></td>
                                </tr>
                                <tr>
                                    <th class="text-secondary">Last HTTP status</th>
                                    <td><?= $featuresRow['lastHttpStatus'] !== null ? (int)$featuresRow['lastHttpStatus'] : '<span class="text-secondary">—</span>' ?></td>
                                </tr>
                                <tr>
                                    <th class="text-secondary">Last error code</th>
                                    <td><?= $featuresRow['lastErrorCode'] !== null ? '<code>' . htmlspecialchars((string)$featuresRow['lastErrorCode'], ENT_QUOTES, 'UTF-8') . '</code>' : '<span class="text-secondary">none</span>' ?></td>
                                </tr>
                                <tr>
                                    <th class="text-secondary">Consecutive failures</th>
                                    <td><?= (int)$featuresRow['consecutiveFailures'] ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <?php $snapFeatures = is_array($featuresRow['payload']['features'] ?? null) ? $featuresRow['payload']['features'] : []; ?>
                    <?php if ($snapFeatures === []): ?>
                        <p class="text-secondary small mb-0">Snapshot decoded, but the gateway's <code>features</code> list is empty.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm admin-table-responsive mb-0">
                                <thead>
                                    <tr class="text-muted small">
                                        <th data-col-priority="primary">Feature key</th>
                                        <th data-col-priority="primary">Enabled</th>
                                        <th data-col-priority="secondary">Label</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($snapFeatures as $f): ?>
                                        <?php if (!is_array($f)) { continue; } ?>
                                        <tr>
                                            <td><code><?= htmlspecialchars((string)($f['feature_key'] ?? ''), ENT_QUOTES, 'UTF-8') ?></code></td>
                                            <td>
                                                <span class="badge <?= !empty($f['enabled']) ? 'bg-success' : 'bg-secondary' ?>">
                                                    <?= !empty($f['enabled']) ? 'on' : 'off' ?>
                                                </span>
                                            </td>
                                            <td class="small text-secondary"><?= htmlspecialchars((string)($f['label'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <div class="card-footer d-flex gap-2">
                <form method="post" class="d-inline">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="test_connection">
                    <button type="submit" class="btn btn-outline-info btn-sm">
                        <i class="bi bi-wifi me-1"></i>Test connection
                    </button>
                </form>
                <form method="post" class="d-inline">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="refresh_now">
                    <button type="submit" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-arrow-clockwise me-1"></i>Refresh now
                    </button>
                </form>
            </div>
        </div>

    </div>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>
</body>
</html>
