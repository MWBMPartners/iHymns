<?php

declare(strict_types=1);

/**
 * iHymns — Admin User Management (#229)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Allows admins to create new user accounts. Only accessible to
 * authenticated users with the 'admin' role. The public setup page
 * is disabled after the first admin is created — all subsequent
 * user creation goes through this page.
 */

require_once __DIR__ . '/includes/auth.php';
requireAdmin();

$currentUser = getCurrentUser();

/* Handle form submission */
$error   = '';
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token       = $_POST['csrf_token'] ?? '';
    $username    = $_POST['username'] ?? '';
    $displayName = $_POST['display_name'] ?? '';
    $password    = $_POST['password'] ?? '';
    $confirm     = $_POST['password_confirm'] ?? '';
    $role        = $_POST['role'] ?? 'editor';

    if (!validateCsrf($token)) {
        $error = 'Invalid form submission. Please try again.';
    } elseif (strlen(trim($username)) < 3) {
        $error = 'Username must be at least 3 characters.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } elseif (!in_array($role, allRoles(), true)) {
        $error = 'Invalid role.';
    } elseif ($role === 'global_admin' && $currentUser['role'] !== 'global_admin') {
        $error = 'Only Global Admin can assign the Global Admin role.';
    } elseif (roleLevel($role) > roleLevel($currentUser['role'])) {
        $error = 'Cannot assign a role higher than your own.';
    } else {
        try {
            createUser($username, $password, $displayName ?: $username, $role);
            $success = 'User "' . htmlspecialchars($username) . '" created successfully.';
        } catch (\RuntimeException $e) {
            $error = $e->getMessage();
        }
    }
}

/* Fetch existing users for the list */
$db = getDb();
$users = $db->query('SELECT id, username, display_name, role, is_active, created_at FROM users ORDER BY created_at ASC')->fetchAll(PDO::FETCH_ASSOC);

$csrf = csrfToken();
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Users — iHymns Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH"
          crossorigin="anonymous">
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
          integrity="sha384-XGjxtQfXaH2tnPFa9x+ruJTuLE3Aa6LhHSWRr1XeTyhezb4abCG4ccI5AkVDxqC+"
          crossorigin="anonymous">
    <style>
        :root {
            --ih-bg: #1a1a2e;
            --ih-surface: #16213e;
            --ih-amber: #f59e0b;
            --ih-amber-hover: #d97706;
            --ih-text: #e2e8f0;
            --ih-text-muted: #94a3b8;
            --ih-border: #334155;
        }
        body { background: var(--ih-bg); color: var(--ih-text); }
        .card-admin { background: var(--ih-surface); border: 1px solid var(--ih-border); border-radius: 12px; }
        .btn-amber { background: var(--ih-amber); border-color: var(--ih-amber); color: #1a1a2e; font-weight: 600; }
        .btn-amber:hover { background: var(--ih-amber-hover); border-color: var(--ih-amber-hover); color: #1a1a2e; }
        .form-control:focus, .form-select:focus { border-color: var(--ih-amber); box-shadow: 0 0 0 0.2rem rgba(245, 158, 11, 0.25); }
        .navbar-admin { background: var(--ih-surface); border-bottom: 1px solid var(--ih-border); padding: 0.75rem 1rem; }
        .navbar-admin .nav-link { color: var(--ih-text-muted); }
        .navbar-admin .nav-link:hover { color: var(--ih-amber); }
        .table { color: var(--ih-text); }
        .badge-global-admin { background: #dc2626; }
        .badge-admin { background: var(--ih-amber); color: #1a1a2e; }
        .badge-editor { background: #3b82f6; }
        .badge-user { background: #6b7280; }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar-admin d-flex align-items-center">
        <a href="/manage/editor/" class="text-decoration-none me-auto d-flex align-items-center gap-2" style="color: var(--ih-amber);">
            <i class="bi bi-music-note-beamed"></i>
            <strong>iHymns Admin</strong>
        </a>
        <a href="/manage/editor/" class="nav-link d-inline"><i class="bi bi-pencil-square me-1"></i>Editor</a>
        <span class="text-muted mx-2">|</span>
        <span class="text-muted small"><?= htmlspecialchars($currentUser['display_name'] ?? $currentUser['username']) ?></span>
        <a href="/manage/logout" class="btn btn-sm btn-outline-secondary ms-2">
            <i class="bi bi-box-arrow-right me-1"></i>Logout
        </a>
    </nav>

    <div class="container py-4" style="max-width: 800px;">

        <h1 class="h4 mb-4"><i class="bi bi-people me-2"></i>User Management</h1>

        <!-- Existing users -->
        <div class="card-admin p-3 mb-4">
            <h2 class="h6 mb-3">Existing Users</h2>
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
                    <?php foreach ($users as $u): ?>
                    <tr>
                        <td><code><?= htmlspecialchars($u['username']) ?></code></td>
                        <td><?= htmlspecialchars($u['display_name']) ?></td>
                        <td>
                            <span class="badge <?= match($u['role']) {
                                'global_admin' => 'badge-global-admin',
                                'admin'        => 'badge-admin',
                                'editor'       => 'badge-editor',
                                default        => 'badge-user',
                            } ?>">
                                <?= htmlspecialchars(roleLabel($u['role'])) ?>
                            </span>
                        </td>
                        <td><?= $u['is_active'] ? '<span class="text-success">Active</span>' : '<span class="text-danger">Disabled</span>' ?></td>
                        <td class="text-muted small"><?= htmlspecialchars($u['created_at']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Create new user form -->
        <div class="card-admin p-3">
            <h2 class="h6 mb-3">Create New User</h2>

            <?php if ($success): ?>
                <div class="alert alert-success py-2"><?= $success ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger py-2"><i class="bi bi-exclamation-triangle me-1"></i><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">

                <div class="row mb-3">
                    <div class="col-md-6">
                        <label for="username" class="form-label">Username <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="username" name="username"
                               value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" minlength="3" required>
                    </div>
                    <div class="col-md-6">
                        <label for="display_name" class="form-label">Display Name</label>
                        <input type="text" class="form-control" id="display_name" name="display_name"
                               value="<?= htmlspecialchars($_POST['display_name'] ?? '') ?>" placeholder="Defaults to username">
                    </div>
                </div>

                <div class="row mb-3">
                    <div class="col-md-6">
                        <label for="password" class="form-label">Password <span class="text-danger">*</span></label>
                        <input type="password" class="form-control" id="password" name="password" minlength="8" required>
                    </div>
                    <div class="col-md-6">
                        <label for="password_confirm" class="form-label">Confirm Password <span class="text-danger">*</span></label>
                        <input type="password" class="form-control" id="password_confirm" name="password_confirm" minlength="8" required>
                    </div>
                </div>

                <div class="mb-3">
                    <label for="role" class="form-label">Role</label>
                    <select class="form-select" id="role" name="role">
                        <option value="user">User — can save setlists centrally</option>
                        <option value="editor" selected>Curator / Editor — can edit songs</option>
                        <option value="admin">Admin — full access including user management</option>
                        <?php if ($currentUser['role'] === 'global_admin'): ?>
                        <option value="global_admin">Global Admin — unrestricted access</option>
                        <?php endif; ?>
                    </select>
                </div>

                <button type="submit" class="btn btn-amber">
                    <i class="bi bi-person-plus me-1"></i>Create User
                </button>
            </form>
        </div>
    </div>
</body>
</html>
