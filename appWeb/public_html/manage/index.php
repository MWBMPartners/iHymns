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
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'entitlements.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'card_layout.php';

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
$db = getDb();

$tryInt = function (string $sql, array $params = []) use ($db): int {
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    } catch (\Throwable $_e) {
        return 0;
    }
};

$totalUsers    = $tryInt('SELECT COUNT(*) FROM tblUsers');
$activeUsers   = $tryInt('SELECT COUNT(*) FROM tblUsers WHERE IsActive = 1');
$activeTokens  = $tryInt('SELECT COUNT(*) FROM tblApiTokens WHERE ExpiresAt > ?', [gmdate('c')]);
$totalSetlists = $tryInt('SELECT COUNT(*) FROM tblUserSetlists');
$totalSongs    = $tryInt('SELECT COUNT(*) FROM tblSongs');
$totalSongbooks= $tryInt('SELECT COUNT(*) FROM tblSongbooks WHERE SongCount > 0');
$pendingReqs   = $tryInt("SELECT COUNT(*) FROM tblSongRequests WHERE Status = 'pending'");
$logins24h     = $tryInt('SELECT COUNT(*) FROM tblLoginAttempts WHERE Success = 1 AND AttemptedAt >= (NOW() - INTERVAL 1 DAY)');
$views24h      = $tryInt('SELECT COUNT(*) FROM tblSongHistory WHERE ViewedAt >= (NOW() - INTERVAL 1 DAY)');

/* User counts by role */
$roleCounts = [];
try {
    $rs = $db->query('SELECT Role, COUNT(*) AS cnt FROM tblUsers WHERE IsActive = 1 GROUP BY Role');
    while ($row = $rs->fetch(PDO::FETCH_ASSOC)) {
        $roleCounts[$row['Role']] = (int)$row['cnt'];
    }
} catch (\Throwable $_e) {}

/* Recent users (last 10) */
$recentUsers = [];
try {
    $recentUsers = $db->query(
        'SELECT Id AS id, Username AS username, DisplayName AS display_name,
                Role AS role, IsActive AS is_active, CreatedAt AS created_at
           FROM tblUsers
          ORDER BY CreatedAt DESC
          LIMIT 10'
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $_e) {}

$csrf = csrfToken();
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard — iHymns Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH"
          crossorigin="anonymous">
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
          integrity="sha384-XGjxtQfXaH2tnPFa9x+ruJTuLE3Aa6LhHSWRr1XeTyhezb4abCG4ccI5AkVDxqC+"
          crossorigin="anonymous">
    <!-- Shared iHymns palette + admin styles -->
    <link rel="stylesheet" href="/css/app.css?v=<?= filemtime(dirname(__DIR__) . "/css/app.css") ?>">
    <link rel="stylesheet" href="/css/admin.css?v=<?= filemtime(dirname(__DIR__) . "/css/admin.css") ?>">
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-favicon.php'; ?>
</head>
<body>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-nav.php'; ?>

    <div class="container-admin py-4">

        <h1 class="h4 mb-1"><i class="bi bi-speedometer2 me-2"></i>Admin Portal</h1>
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
            <h2 class="h6 mb-3"><i class="bi bi-shield-check me-2"></i>Users by Role</h2>
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
            ['data-health',  'drop_legacy_tables',          '/manage/data-health',   'bi-activity',      'Data Health',          'Confirm MySQL is authoritative; disconnect legacy fallbacks',             true],
            ['setup-db',     'run_db_install',              '/manage/setup-database','bi-database-gear', 'Database Setup',       'Install, migrate, backup, restore, cleanup',                              true],
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
                [$id, , $href, $icon, $title, $sub, $sameTab] = $dashboardById[$cardId];
                $isHidden = isset($hiddenSet[$id]);
                $target = $sameTab ? '' : 'target="_blank" rel="noopener"';
                ?>
                <div class="col-md-4 card-layout-item<?= $isHidden ? ' d-none' : '' ?>"
                     data-card-id="<?= htmlspecialchars($id) ?>"
                     data-hidden="<?= $isHidden ? '1' : '0' ?>">
                    <div class="card-admin position-relative">
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
                <h2 class="h6 mb-0"><i class="bi bi-clock-history me-2"></i>Recent Users</h2>
                <a href="/manage/users" class="btn btn-sm btn-outline-secondary">View All</a>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-borderless mb-0">
                    <thead>
                        <tr class="text-muted small">
                            <th>Username</th>
                            <th>Display Name</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Created</th>
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

        <!-- System Info -->
        <div class="card-admin p-3">
            <h2 class="h6 mb-3"><i class="bi bi-info-circle me-2"></i>System Info</h2>
            <table class="table table-sm table-borderless mb-0 small">
                <tr><td class="text-muted" style="width:40%">PHP Version</td><td><?= phpversion() ?></td></tr>
                <tr><td class="text-muted">Database Driver</td><td><?= ucfirst(DB_CONFIG['driver']) ?></td></tr>
                <?php if (DB_CONFIG['driver'] === 'sqlite'): ?>
                <tr><td class="text-muted">Database File</td><td><code class="small"><?= htmlspecialchars(basename(DB_CONFIG['sqlite']['path'])) ?></code></td></tr>
                <?php endif; ?>
                <tr><td class="text-muted">Your Role</td><td><?= htmlspecialchars(roleLabel($currentUser['role'])) ?></td></tr>
                <tr><td class="text-muted">Your Username</td><td><code><?= htmlspecialchars($currentUser['username']) ?></code></td></tr>
            </table>
        </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
            integrity="sha384-zKzgIZcXU99qF1nNW9g+x1znB5NhCPs9qZeGzUnnFOaHJF9jCCKySBjq3vIKabk/"
            crossorigin="anonymous"></script>

    <script type="module">
        import { bootCardLayout } from '/js/modules/card-layout.js?v=<?= filemtime(dirname(__DIR__) . '/js/modules/card-layout.js') ?>';
        bootCardLayout();
    </script>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>
</body>
</html>
