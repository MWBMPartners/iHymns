<?php

declare(strict_types=1);

/**
 * iHymns — Admin Dashboard (#260)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Central admin dashboard showing system overview, quick stats,
 * and navigation. Accessible to editor role and above.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'card_layout.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_mysql.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'song_soft_delete.php';   /* #1694 */

/* Dashboard is now the single landing page for every management
   surface, so admit any signed-in user who holds at least one
   curator/admin entitlement. Each card below is individually
   gated, so unauthorised users see a subset — or, if they hold
   none, are bounced to /. */
requireAuth();
$currentUser = getCurrentUser();
$_role = $currentUser['role'] ?? null;
$_manageEntitlements = [
    'edit_songs', 'review_song_requests', 'verify_songs',
    'view_admin_dashboard', 'view_users', 'manage_user_groups',
    'manage_organisations', 'manage_songbooks',
    'manage_entitlements', 'view_analytics',
    'manage_content_restrictions', 'manage_access_tiers',
    'run_db_install', 'drop_legacy_tables',
];
$_canManage = false;
foreach ($_manageEntitlements as $_e) {
    if (userHasEntitlement($_e, $_role)) { $_canManage = true; break; }
}
if (!$_canManage) {
    http_response_code(403);
    exit('Access denied. A management entitlement is required.');
}

$activePage  = 'dashboard';

/* Gather stats — all queries updated for the v0.10 PascalCase schema
   (#407). Each is wrapped in try/catch so a missing table during early
   setup doesn't blank the whole dashboard. */
$db = getDbMysqli();

$tryInt = function (string $sql, array $params = []) use ($db): int {
    try {
        $stmt = $db->prepare($sql);
        /* All current callers pass string params; default to 's'×N
           and skip bind_param when there are no params (mysqli rejects
           an empty type string). */
        if (!empty($params)) {
            $stmt->bind_param(str_repeat('s', count($params)), ...$params);
        }
        $stmt->execute();
        $row = $stmt->get_result()->fetch_row();
        $stmt->close();
        return (int)($row[0] ?? 0);
    } catch (\Throwable $_e) {
        return 0;
    }
};

$totalUsers    = $tryInt('SELECT COUNT(*) FROM tblUsers');
$activeUsers   = $tryInt('SELECT COUNT(*) FROM tblUsers WHERE IsActive = 1');
$activeTokens  = $tryInt('SELECT COUNT(*) FROM tblApiTokens WHERE ExpiresAt > ?', [gmdate('Y-m-d H:i:s')]);
$totalSetlists = $tryInt('SELECT COUNT(*) FROM tblUserSetlists');
/* #1694 D1 — the dashboard's headline count means VISIBLE songs, matching the
   public tiles; the Deleted-songs screen (commit 5) reports the hidden ones.
   songVisibleSql() is composed OUTSIDE $tryInt's try, so its (rare) probe
   failure gets the same degrade-not-blank treatment every stat here enjoys.
   #1765 — "matching the public tiles" now also means excluding songs whose
   songbook has been disabled, so songServableSql() rides alongside; both
   fail-degrade to '1=1' identically, so the try/catch's contract is unchanged. */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'songbook_visibility.php';
try {
    $totalSongs = $tryInt(
        'SELECT COUNT(*) FROM tblSongs WHERE ' . songVisibleSql($db, '') . ' AND ' . songServableSql($db, '')
    );
} catch (\Throwable $_e) {
    $totalSongs = 0;   /* the $tryInt convention: a failed stat reads 0, never a white screen */
}
/* #1765 — same "matches the public tiles" contract: a disabled songbook's
   SongCount does NOT shrink (rule: disabling hides the book, it does not
   change the number on the tile) but the book itself must not be counted
   among the dashboard's "N Songbooks" any more than it is on the public
   home tiles. */
$totalSongbooks= $tryInt('SELECT COUNT(*) FROM tblSongbooks WHERE SongCount > 0 AND ' . songbookVisibleSql($db, ''));
$pendingReqs   = $tryInt("SELECT COUNT(*) FROM tblSongRequests WHERE Status = 'pending'");
$logins24h     = $tryInt('SELECT COUNT(*) FROM tblLoginAttempts WHERE Success = 1 AND AttemptedAt >= (NOW() - INTERVAL 1 DAY)');
$views24h      = $tryInt('SELECT COUNT(*) FROM tblSongHistory WHERE ViewedAt >= (NOW() - INTERVAL 1 DAY)');

/* User counts by role */
$roleCounts = [];
try {
    $stmt = $db->prepare('SELECT Role, COUNT(*) AS cnt FROM tblUsers WHERE IsActive = 1 GROUP BY Role');
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $roleCounts[$row['Role']] = (int)$row['cnt'];
    }
    $stmt->close();
} catch (\Throwable $_e) {}

/* Recent users (last 10) */
$recentUsers = [];
try {
    $stmt = $db->prepare(
        'SELECT Id AS id, Username AS username, DisplayName AS display_name,
                Role AS role, IsActive AS is_active, CreatedAt AS created_at
           FROM tblUsers
          ORDER BY CreatedAt DESC
          LIMIT 10'
    );
    $stmt->execute();
    $recentUsers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} catch (\Throwable $_e) {}

/* CAPTCHA provider health (#947/#340 outage fallback) — a banner when the
   challenge is configured but this server has observed the provider failing.
   ⚠️ READ-ONLY AND FREE: two memoized getAppSetting() reads, no probe, no
   outbound call. A dashboard must never be able to trigger a network request to
   a third party, and the verdict itself comes from the SAME pure function the
   gate uses, so this banner can never claim something enforcement disagrees
   with (rule #35). Wrapped defensively so a dormant/blank install, or one whose
   settings row does not exist yet, renders exactly as before. */
$captchaHealthBanner = null;
try {
    require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'captcha.php';
    if (captchaConfigured()) {
        $_chState = captchaHealthState();
        if ((string)($_chState['status'] ?? 'up') !== 'up') {
            $captchaHealthBanner = [
                'status' => (string)$_chState['status'],
                'open'   => captchaOutageDecision($_chState, time()) === 'admit',
            ];
        }
    }
} catch (\Throwable $_e) {
    $captchaHealthBanner = null;   /* never let a status read break the dashboard */
}

$csrf = csrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard — iHymns Admin</title>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-libs.php'; ?>
    <!-- Shared iHymns palette + admin styles -->
    <link rel="stylesheet" href="/css/app.css?v=<?= filemtime(dirname(__DIR__) . "/css/app.css") ?>">
    <link rel="stylesheet" href="/css/admin.css?v=<?= filemtime(dirname(__DIR__) . "/css/admin.css") ?>">
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-favicon.php'; ?>
</head>
<body>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-nav.php'; ?>

    <div class="container-admin py-4">

        <?php if ($captchaHealthBanner !== null): ?>
            <div class="alert <?= $captchaHealthBanner['status'] === 'misconfig' ? 'alert-danger' : 'alert-warning' ?> alert-dismissible fade show" role="alert">
                <?php if ($captchaHealthBanner['status'] === 'misconfig'): ?>
                    <i aria-hidden="true" class="bi bi-key me-1"></i>
                    <strong>The bot-protection provider is rejecting our secret key.</strong>
                    This is a settings mistake, not an outage &mdash; waiting will not fix it. Guarded forms are
                    currently letting people through on the ordinary rate limits so nobody is locked out.
                <?php else: ?>
                    <i aria-hidden="true" class="bi bi-exclamation-triangle me-1"></i>
                    <strong>The bot-protection provider is not answering this server.</strong>
                    <?php if ($captchaHealthBanner['open']): ?>
                        Guarded forms are temporarily letting people through on the ordinary rate limits, so
                        nobody is locked out. This clears itself as soon as the provider answers again.
                    <?php else: ?>
                        The last check failed, but it is now out of date &mdash; the next guarded request will
                        re-check before deciding anything.
                    <?php endif; ?>
                <?php endif; ?>
                <a href="/manage/configuration#captcha" class="alert-link">Open CAPTCHA settings</a>.
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <h1 class="h4 mb-1"><i aria-hidden="true" class="bi bi-speedometer2 me-2"></i>Admin Portal</h1>
        <p class="text-secondary small mb-4">
            Welcome back, <strong><?= htmlspecialchars($currentUser['display_name'] ?? $currentUser['username'] ?? 'admin') ?></strong>.
            Quick snapshot of the app + shortcuts to every admin surface.
        </p>

        <!-- Library snapshot -->
        <h2 class="h6 text-uppercase text-muted small mb-2">Library</h2>
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <div class="card-admin stat-card">
                    <div class="stat-number"><?= number_format($totalSongs) ?></div>
                    <div class="stat-label">Songs</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card-admin stat-card">
                    <div class="stat-number"><?= number_format($totalSongbooks) ?></div>
                    <div class="stat-label">Songbooks</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card-admin stat-card">
                    <div class="stat-number"><?= number_format($totalSetlists) ?></div>
                    <div class="stat-label">Synced setlists</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card-admin stat-card">
                    <div class="stat-number"><?= number_format($pendingReqs) ?></div>
                    <div class="stat-label">Pending requests</div>
                </div>
            </div>
        </div>

        <!-- People + activity -->
        <h2 class="h6 text-uppercase text-muted small mb-2">People &amp; activity</h2>
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <div class="card-admin stat-card">
                    <div class="stat-number"><?= number_format($activeUsers) ?></div>
                    <div class="stat-label">Active users</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card-admin stat-card">
                    <div class="stat-number"><?= number_format($totalUsers) ?></div>
                    <div class="stat-label">Total users</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card-admin stat-card">
                    <div class="stat-number"><?= number_format($logins24h) ?></div>
                    <div class="stat-label">Logins (24 h)</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card-admin stat-card">
                    <div class="stat-number"><?= number_format($views24h) ?></div>
                    <div class="stat-label">Song views (24 h)</div>
                </div>
            </div>
        </div>

        <!-- Users by Role -->
        <?php if (hasRole($currentUser['role'], 'admin')): ?>
        <div class="card-admin p-3 mb-4">
            <h2 class="h6 mb-3"><i aria-hidden="true" class="bi bi-shield-check me-2"></i>Users by Role</h2>
            <div class="row g-2">
                <?php foreach (allRoles() as $role): ?>
                <div class="col-6 col-md-3">
                    <div class="d-flex align-items-center gap-2 p-2 rounded" style="background: rgba(255,255,255,0.03);">
                        <span class="badge <?= match($role) {
                            'global_admin' => 'bg-danger',
                            'admin'        => 'bg-warning text-dark',
                            'editor'       => 'bg-primary',
                            default        => 'bg-secondary',
                        } ?>"><?= htmlspecialchars(roleLabel($role)) ?></span>
                        <strong><?= $roleCounts[$role] ?? 0 ?></strong>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php
        /* Every card is gated by the same entitlement that controls
           visibility of the corresponding menu item, so the dashboard
           surfaces exactly the areas the user can act on. A single
           $cards array drives rendering so reorder / hide (#448) is a
           matter of sorting + filtering this list. */
        $pendingReqsLabel = $pendingReqs . ' pending · triage &amp; resolve';
        $dashboardCards = [
            ['editor',       'edit_songs',                  '/manage/editor/',       'bi-pencil-square', 'Song Editor',          'Edit songs, metadata, and arrangements',                                  true],
            ['requests',     'review_song_requests',        '/manage/requests',      'bi-lightbulb',     'Song Requests',        $pendingReqsLabel,                                                         false],
            ['revisions',    'verify_songs',                '/manage/revisions',     'bi-clock-history', 'Revisions Audit',      'Audit song edits; open any row in the editor to diff / restore',           true],
            ['users',        'view_users',                  '/manage/users',         'bi-people',        'User Management',      'Manage accounts, roles, and permissions',                                 true],
            ['groups',       'manage_user_groups',          '/manage/groups',        'bi-people-fill',   'User Groups',          'Group users for shared access settings',                                  true],
            ['organisations','manage_organisations',        '/manage/organisations', 'bi-building',      'Organisations',        'Manage organisations &amp; their members',                                 true],
            ['songbooks',    'manage_songbooks',            '/manage/songbooks',     'bi-book',          'Songbook Management',  'Create, rename, reorder the songbook catalogue',                          true],
            ['restrictions', 'manage_content_restrictions', '/manage/restrictions',  'bi-shield-lock',   'Content Restrictions', 'Gate songs, songbooks &amp; features per user, org, platform or licence', true],
            ['tiers',        'manage_access_tiers',         '/manage/tiers',         'bi-stars',         'Access Tiers',         'Define tiers controlling lyrics, audio, MIDI, sheet music &amp; offline', true],
            ['entitlements', 'manage_entitlements',         '/manage/entitlements',  'bi-key',           'Entitlements &amp; Gating','Assign capabilities to roles',                                          true],
            ['analytics',    'view_analytics',              '/manage/analytics',     'bi-graph-up',      'Analytics',            'Top songs, searches, and user activity',                                  true],
            ['ccli-report',  'view_ccli_report',            '/manage/ccli-report',   'bi-receipt',       'CCLI Usage Report',    'Per-song view counts + CSV export for the annual CCLI usage return',      true],
            ['missing-numbers','edit_songs',                '/manage/missing-numbers','bi-binoculars',   'Missing Numbers',      'Catalogue-wide report of songbook number gaps',                           true],
            ['data-health',  'drop_legacy_tables',          '/manage/data-health',   'bi-activity',      'Data Health',          'Confirm MySQL is authoritative; disconnect legacy fallbacks',             true],
            ['schema-audit', 'drop_legacy_tables',          '/manage/schema-audit',  'bi-clipboard2-data','Schema Audit',        'Diff schema.sql vs live DB vs migrations; spot drift before it bites',    true],
            ['setup-database','run_db_install',             '/manage/setup-database','bi-database-gear', 'Database Setup',       'Install, migrate, backup, restore, cleanup',                              true],
            ['view-site',    null,                          '/',                     'bi-globe',         'View Website',         'Open iHymns in a new tab',                                                true],
        ];

        /* Filter out cards the viewer can't see, then apply the layout
           resolver (system default merged with per-user override). */
        $dashboardCards = array_values(array_filter(
            $dashboardCards,
            static fn(array $c): bool => $c[1] === null || userHasEntitlement($c[1], $_role)
        ));
        $dashboardBaseline = array_map(static fn(array $c) => $c[0], $dashboardCards);
        $dashboardLayout   = cardLayoutResolve($dashboardBaseline, 'dashboard', [
            'id'       => $currentUser['id'] ?? null,
            'role'     => $_role,
            'group_id' => $currentUser['group_id'] ?? null,
        ]);
        $dashboardById = [];
        foreach ($dashboardCards as $c) { $dashboardById[$c[0]] = $c; }

        $canCustomiseOwn = cardLayoutUserCanCustomise([
            'id'       => $currentUser['id'] ?? null,
            'role'     => $_role,
            'group_id' => $currentUser['group_id'] ?? null,
        ]);
        $canSetDefault = userHasEntitlement('manage_default_card_layout', $_role);
        $hiddenSet = array_flip($dashboardLayout['hidden']);
        ?>

        <!-- Customise toolbar — only rendered if the viewer has at
             least one relevant entitlement. -->
        <?php if ($canCustomiseOwn || $canSetDefault): ?>
        <div class="d-flex align-items-center gap-2 mb-3" id="card-layout-toolbar">
            <button type="button" class="btn btn-sm btn-outline-info" id="btn-card-layout-edit">
                <i class="bi bi-grid-3x3-gap me-1" aria-hidden="true"></i>Customise layout
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary d-none" id="btn-card-layout-done">
                <i class="bi bi-check2 me-1" aria-hidden="true"></i>Done
            </button>
            <button type="button" class="btn btn-sm btn-outline-warning d-none" id="btn-card-layout-reset">
                <i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Reset to default
            </button>
            <?php if ($canSetDefault): ?>
            <button type="button" class="btn btn-sm btn-outline-danger d-none" id="btn-card-layout-save-default"
                    title="Save the current order as the system-wide default for all users">
                <i class="bi bi-save me-1" aria-hidden="true"></i>Save as site default
            </button>
            <?php endif; ?>
            <span class="small text-muted d-none" id="card-layout-help">
                Drag the handle to reorder; click × to hide a card. Hidden cards reappear from
                <a href="/settings#tab-profile" class="text-info">Settings → Profile</a>.
            </span>
        </div>
        <?php endif; ?>

        <!-- Quick Links — rendered from $dashboardLayout. data-card-id
             keys each card so the client-side reorder module can move
             them around without touching the DOM beyond the grid. -->
        <div class="row g-3 mb-4"
             id="dashboard-card-grid"
             data-layout-surface="dashboard"
             data-can-customise="<?= $canCustomiseOwn ? '1' : '0' ?>"
             data-can-set-default="<?= $canSetDefault ? '1' : '0' ?>">
            <?php foreach ($dashboardLayout['order'] as $cardId): ?>
                <?php
                if (!isset($dashboardById[$cardId])) continue;
                [$id, $entitlement, $href, $icon, $title, $sub, $sameTab] = $dashboardById[$cardId];
                $isHidden = isset($hiddenSet[$id]);
                $target = $sameTab ? '' : 'target="_blank" rel="noopener"';
                ?>
                <div class="col-md-4 card-layout-item<?= $isHidden ? ' d-none' : '' ?>"
                     data-card-id="<?= htmlspecialchars($id) ?>"
                     data-hidden="<?= $isHidden ? '1' : '0' ?>">
                    <div class="card-admin position-relative">
                        <?php
                            /* Padlock chip moved to the top-right corner of the
                               card (#785) — was inline next to the title and
                               crowded the heading. The .lock-chip-corner class
                               keeps the same red/yellow tier colours but
                               position:absolutes the chip so it doesn't push
                               anything around. */
                            $tier = entitlementHighestRole($entitlement);
                            if ($tier !== null):
                                $tierCls   = $tier === 'global_admin' ? 'lock-chip-global-admin' : 'lock-chip-admin';
                                $tierLabel = $tier === 'global_admin' ? 'Requires Global Admin' : 'Requires Admin';
                        ?>
                            <i aria-hidden="true" class="bi bi-lock-fill lock-chip lock-chip-corner <?= $tierCls ?>"
                               aria-label="<?= htmlspecialchars($tierLabel, ENT_QUOTES, 'UTF-8') ?>"
                               title="<?= htmlspecialchars($tierLabel, ENT_QUOTES, 'UTF-8') ?>"></i>
                        <?php endif; ?>
                        <a href="<?= htmlspecialchars($href) ?>" class="quick-link" <?= $target ?>>
                            <i class="bi <?= htmlspecialchars($icon) ?> d-block mb-2" aria-hidden="true"></i>
                            <strong><?= $title /* some titles contain &amp; entity */ ?></strong>
                            <div class="small text-muted"><?= $sub /* same */ ?></div>
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Recent Users (admin+ only) -->
        <?php if (hasRole($currentUser['role'], 'admin')): ?>
        <div class="card-admin p-3 mb-4">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <h2 class="h6 mb-0"><i aria-hidden="true" class="bi bi-clock-history me-2"></i>Recent Users</h2>
                <a href="/manage/users" class="btn btn-sm btn-outline-secondary">View All</a>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-borderless mb-0">
                    <thead>
                        <tr class="text-muted small">
                            <th scope="col">Username</th>
                            <th scope="col">Display Name</th>
                            <th scope="col">Role</th>
                            <th scope="col">Status</th>
                            <th scope="col">Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentUsers as $u): ?>
                        <tr>
                            <td><code><?= htmlspecialchars($u['username']) ?></code></td>
                            <td><?= htmlspecialchars($u['display_name']) ?></td>
                            <td>
                                <span class="badge <?= match($u['role']) {
                                    'global_admin' => 'bg-danger',
                                    'admin'        => 'bg-warning text-dark',
                                    'editor'       => 'bg-primary',
                                    default        => 'bg-secondary',
                                } ?>" style="font-size: 0.7rem;">
                                    <?= htmlspecialchars(roleLabel($u['role'])) ?>
                                </span>
                            </td>
                            <td><?= $u['is_active'] ? '<span class="text-success small">Active</span>' : '<span class="text-danger small">Disabled</span>' ?></td>
                            <td class="text-muted small"><?= htmlspecialchars($u['created_at']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- System Info — infrastructure-level; only Global Admin sees
             the PHP + DB-driver details. Regular admins / curators see a
             lightweight "Your session" card instead. -->
        <?php if (($currentUser['role'] ?? '') === 'global_admin'): ?>
        <div class="card-admin p-3">
            <h2 class="h6 mb-3 d-flex align-items-center gap-2">
                <i aria-hidden="true" class="bi bi-info-circle"></i>
                System Info
                <!-- Audience cue (#641) — global_admin gating is enforced
                     by the surrounding `if`; the badge makes it
                     self-documenting when curators see screenshots. -->
                <span class="badge bg-danger text-light ms-auto" style="font-size: 0.6rem; font-weight: 600;">
                    Global Admin only
                </span>
            </h2>
            <table class="table table-sm table-borderless mb-0 small">
                <tr><td class="text-muted" style="width:40%">PHP Version</td><td><?= phpversion() ?></td></tr>
                <tr><td class="text-muted">Database Driver</td><td>MySQL</td></tr>
                <?php if (defined('DB_HOST') && defined('DB_NAME')): ?>
                <tr><td class="text-muted">Database</td><td><code class="small"><?= htmlspecialchars(DB_NAME . '@' . DB_HOST) ?></code></td></tr>
                <?php endif; ?>
                <tr><td class="text-muted">Your Role</td><td><?= htmlspecialchars(roleLabel($currentUser['role'])) ?></td></tr>
                <tr><td class="text-muted">Your Username</td><td><code><?= htmlspecialchars($currentUser['username']) ?></code></td></tr>
            </table>
        </div>
        <?php else: ?>
        <div class="card-admin p-3">
            <h2 class="h6 mb-3"><i aria-hidden="true" class="bi bi-person-badge me-2"></i>Your session</h2>
            <table class="table table-sm table-borderless mb-0 small">
                <tr><td class="text-muted" style="width:40%">Your Role</td><td><?= htmlspecialchars(roleLabel($currentUser['role'])) ?></td></tr>
                <tr><td class="text-muted">Your Username</td><td><code><?= htmlspecialchars($currentUser['username']) ?></code></td></tr>
            </table>
        </div>
        <?php endif; ?>

    </div>

    <?php /* Bootstrap bundle is loaded once, centrally, by admin-footer.php
             (CLAUDE.md modularity rule: "A <script> loading Bootstrap ...
             on a page that also includes admin-footer.php (double-load)"
             is an explicit red flag). The `type="module"` block below uses
             native ES-module semantics, not Bootstrap, so it does not need
             the bundle to be loaded first. */ ?>
    <script type="module">
        import { bootCardLayout } from '/js/modules/card-layout.js?v=<?= filemtime(dirname(__DIR__) . '/js/modules/card-layout.js') ?>';
        bootCardLayout();
    </script>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>
</body>
</html>
