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
requireEditor();

$currentUser = getCurrentUser();
$activePage  = 'dashboard';

/* Gather stats */
$db = getDb();
$totalUsers   = (int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn();
$activeUsers  = (int)$db->query('SELECT COUNT(*) FROM users WHERE is_active = 1')->fetchColumn();

/* User counts by role */
$roleStmt = $db->query('SELECT role, COUNT(*) as cnt FROM users WHERE is_active = 1 GROUP BY role');
$roleCounts = [];
while ($row = $roleStmt->fetch(PDO::FETCH_ASSOC)) {
    $roleCounts[$row['role']] = (int)$row['cnt'];
}

/* Recent users (last 10) */
$recentUsers = $db->query('SELECT id, username, display_name, role, is_active, created_at FROM users ORDER BY created_at DESC LIMIT 10')->fetchAll(PDO::FETCH_ASSOC);

/* Active API tokens count */
$activeTokens = (int)$db->prepare('SELECT COUNT(*) FROM api_tokens WHERE expires_at > ?')->execute([gmdate('c')]) ? (int)$db->query('SELECT COUNT(*) FROM api_tokens WHERE expires_at > \'' . gmdate('c') . '\'')->fetchColumn() : 0;

/* Total setlists stored */
$totalSetlists = 0;
try {
    $totalSetlists = (int)$db->query('SELECT COUNT(*) FROM user_setlists')->fetchColumn();
} catch (\Exception $e) { /* table may not exist yet */ }

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
</head>
<body>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-nav.php'; ?>

    <div class="container py-4" style="max-width: 960px;">

        <h1 class="h4 mb-4"><i class="bi bi-speedometer2 me-2"></i>Dashboard</h1>

        <!-- Stats Cards -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <div class="card-admin stat-card">
                    <div class="stat-number"><?= $activeUsers ?></div>
                    <div class="stat-label">Active Users</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card-admin stat-card">
                    <div class="stat-number"><?= $totalUsers ?></div>
                    <div class="stat-label">Total Users</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card-admin stat-card">
                    <div class="stat-number"><?= $activeTokens ?></div>
                    <div class="stat-label">Active Tokens</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card-admin stat-card">
                    <div class="stat-number"><?= $totalSetlists ?></div>
                    <div class="stat-label">Synced Setlists</div>
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

        <!-- Quick Links -->
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="card-admin">
                    <a href="/manage/editor/" class="quick-link">
                        <i class="bi bi-pencil-square d-block mb-2"></i>
                        <strong>Song Editor</strong>
                        <div class="small text-muted">Edit songs, metadata, and arrangements</div>
                    </a>
                </div>
            </div>
            <?php if (hasRole($currentUser['role'], 'admin')): ?>
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
            <?php if (hasRole($currentUser['role'], 'admin')): ?>
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
            <div class="col-md-4">
                <div class="card-admin">
                    <a href="/" class="quick-link" target="_blank">
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
</body>
</html>
