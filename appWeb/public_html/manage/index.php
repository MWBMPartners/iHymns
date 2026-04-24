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

    <div class="container py-4" style="max-width: 960px;">

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

        <!-- Quick Links — every card is gated by the same entitlement
             that controls visibility of the corresponding menu item, so
             the dashboard surfaces exactly the areas the user can act
             on. Gate helper: userHasEntitlement($ent, $role). -->
        <div class="row g-3 mb-4">
            <?php if (userHasEntitlement('edit_songs', $_role)): ?>
            <div class="col-md-4">
                <div class="card-admin">
                    <a href="/manage/editor/" class="quick-link">
                        <i class="bi bi-pencil-square d-block mb-2"></i>
                        <strong>Song Editor</strong>
                        <div class="small text-muted">Edit songs, metadata, and arrangements</div>
                    </a>
                </div>
            </div>
            <?php endif; ?>
            <?php if (userHasEntitlement('review_song_requests', $_role)): ?>
            <div class="col-md-4">
                <div class="card-admin">
                    <a href="/manage/requests" class="quick-link">
                        <i class="bi bi-lightbulb d-block mb-2"></i>
                        <strong>Song Requests</strong>
                        <div class="small text-muted">
                            <?= $pendingReqs ?> pending · triage &amp; resolve
                        </div>
                    </a>
                </div>
            </div>
            <?php endif; ?>
            <?php if (userHasEntitlement('verify_songs', $_role)): ?>
            <div class="col-md-4">
                <div class="card-admin">
                    <a href="/manage/revisions" class="quick-link">
                        <i class="bi bi-clock-history d-block mb-2"></i>
                        <strong>Revisions Audit</strong>
                        <div class="small text-muted">Audit song edits; open any row in the editor to diff / restore</div>
                    </a>
                </div>
            </div>
            <?php endif; ?>
            <?php if (userHasEntitlement('view_users', $_role)): ?>
            <div class="col-md-4">
                <div class="card-admin">
                    <a href="/manage/users" class="quick-link">
                        <i class="bi bi-people d-block mb-2"></i>
                        <strong>User Management</strong>
                        <div class="small text-muted">Manage accounts, roles, and permissions</div>
                    </a>
                </div>
            </div>
            <?php endif; ?>
            <?php if (userHasEntitlement('manage_user_groups', $_role)): ?>
            <div class="col-md-4">
                <div class="card-admin">
                    <a href="/manage/groups" class="quick-link">
                        <i class="bi bi-people-fill d-block mb-2"></i>
                        <strong>User Groups</strong>
                        <div class="small text-muted">Group users for shared access settings</div>
                    </a>
                </div>
            </div>
            <?php endif; ?>
            <?php if (userHasEntitlement('manage_organisations', $_role)): ?>
            <div class="col-md-4">
                <div class="card-admin">
                    <a href="/manage/organisations" class="quick-link">
                        <i class="bi bi-building d-block mb-2"></i>
                        <strong>Organisations</strong>
                        <div class="small text-muted">Manage organisations &amp; their members</div>
                    </a>
                </div>
            </div>
            <?php endif; ?>
            <?php if (userHasEntitlement('manage_songbooks', $_role)): ?>
            <div class="col-md-4">
                <div class="card-admin">
                    <a href="/manage/songbooks" class="quick-link">
                        <i class="bi bi-book d-block mb-2"></i>
                        <strong>Songbook Management</strong>
                        <div class="small text-muted">Create, rename, reorder the songbook catalogue</div>
                    </a>
                </div>
            </div>
            <?php endif; ?>
            <?php if (userHasEntitlement('manage_content_restrictions', $_role)): ?>
            <div class="col-md-4">
                <div class="card-admin">
                    <a href="/manage/restrictions" class="quick-link">
                        <i class="bi bi-shield-lock d-block mb-2"></i>
                        <strong>Content Restrictions</strong>
                        <div class="small text-muted">Gate songs, songbooks &amp; features per user, org, platform or licence</div>
                    </a>
                </div>
            </div>
            <?php endif; ?>
            <?php if (userHasEntitlement('manage_access_tiers', $_role)): ?>
            <div class="col-md-4">
                <div class="card-admin">
                    <a href="/manage/tiers" class="quick-link">
                        <i class="bi bi-stars d-block mb-2"></i>
                        <strong>Access Tiers</strong>
                        <div class="small text-muted">Define tiers controlling lyrics, audio, MIDI, sheet music &amp; offline</div>
                    </a>
                </div>
            </div>
            <?php endif; ?>
            <?php if (userHasEntitlement('manage_entitlements', $_role)): ?>
            <div class="col-md-4">
                <div class="card-admin">
                    <a href="/manage/entitlements" class="quick-link">
                        <i class="bi bi-key d-block mb-2"></i>
                        <strong>Entitlements &amp; Gating</strong>
                        <div class="small text-muted">Assign capabilities to roles</div>
                    </a>
                </div>
            </div>
            <?php endif; ?>
            <?php if (userHasEntitlement('view_analytics', $_role)): ?>
            <div class="col-md-4">
                <div class="card-admin">
                    <a href="/manage/analytics" class="quick-link">
                        <i class="bi bi-graph-up d-block mb-2"></i>
                        <strong>Analytics</strong>
                        <div class="small text-muted">Top songs, searches, and user activity</div>
                    </a>
                </div>
            </div>
            <?php endif; ?>
            <?php if (userHasEntitlement('drop_legacy_tables', $_role)): ?>
            <div class="col-md-4">
                <div class="card-admin">
                    <a href="/manage/data-health" class="quick-link">
                        <i class="bi bi-activity d-block mb-2"></i>
                        <strong>Data Health</strong>
                        <div class="small text-muted">Confirm MySQL is authoritative; disconnect legacy fallbacks</div>
                    </a>
                </div>
            </div>
            <?php endif; ?>
            <?php if (userHasEntitlement('run_db_install', $_role)): ?>
            <div class="col-md-4">
                <div class="card-admin">
                    <a href="/manage/setup-database" class="quick-link">
                        <i class="bi bi-database-gear d-block mb-2"></i>
                        <strong>Database Setup</strong>
                        <div class="small text-muted">Install, migrate, backup, restore, cleanup</div>
                    </a>
                </div>
            </div>
            <?php endif; ?>
            <div class="col-md-4">
                <div class="card-admin">
                    <a href="/" class="quick-link" target="_blank" rel="noopener">
                        <i class="bi bi-globe d-block mb-2"></i>
                        <strong>View Website</strong>
                        <div class="small text-muted">Open iHymns in a new tab</div>
                    </a>
                </div>
            </div>
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

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>
</body>
</html>
