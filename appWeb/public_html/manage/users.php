<?php

declare(strict_types=1);

/**
 * iHymns — Admin User Management (#229, #260)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Full user management for admins: create, edit roles, activate/deactivate,
 * reset passwords, and delete user accounts. Enforces role hierarchy so
 * admins can only manage users below their own privilege level.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'entitlements.php';
requireAdmin();

$currentUser = getCurrentUser();
$activePage  = 'users';

/* =========================================================================
 * HANDLE POST ACTIONS
 * ========================================================================= */

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token  = $_POST['csrf_token'] ?? '';
    $action = $_POST['action'] ?? 'create';

    if (!validateCsrf($token)) {
        $error = 'Invalid form submission. Please try again.';
    } else {
        switch ($action) {

            /* ----- Create new user ----- */
            case 'create':
                $username    = $_POST['username'] ?? '';
                $displayName = $_POST['display_name'] ?? '';
                $password    = $_POST['password'] ?? '';
                $confirm     = $_POST['password_confirm'] ?? '';
                $role        = $_POST['role'] ?? 'editor';

                if (strlen(trim($username)) < 3) {
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
                        $success = 'User "' . $username . '" created successfully.';
                    } catch (\RuntimeException $e) {
                        $error = $e->getMessage();
                    }
                }
                break;

            /* ----- Change role ----- */
            case 'change_role':
                $targetId = (int)($_POST['user_id'] ?? 0);
                $newRole  = $_POST['new_role'] ?? '';
                try {
                    updateUserRole($targetId, $newRole, $currentUser);
                    $success = 'Role updated successfully.';
                } catch (\RuntimeException $e) {
                    $error = $e->getMessage();
                }
                break;

            /* ----- Toggle active status ----- */
            case 'toggle_active':
                $targetId  = (int)($_POST['user_id'] ?? 0);
                $target    = getUserById($targetId);
                if (!$target) {
                    $error = 'User not found.';
                } elseif ($targetId === $currentUser['id']) {
                    $error = 'Cannot deactivate your own account.';
                } elseif (roleLevel($target['role']) >= roleLevel($currentUser['role']) && $currentUser['role'] !== 'global_admin') {
                    $error = 'Cannot modify a user at or above your role level.';
                } else {
                    $newState = !$target['is_active'];
                    setUserActive($targetId, $newState);
                    $success = 'User ' . ($newState ? 'activated' : 'deactivated') . ' successfully.';
                }
                break;

            /* ----- Reset password ----- */
            case 'reset_password':
                $targetId    = (int)($_POST['user_id'] ?? 0);
                $newPassword = $_POST['new_password'] ?? '';
                $target      = getUserById($targetId);
                if (!$target) {
                    $error = 'User not found.';
                } elseif (strlen($newPassword) < 8) {
                    $error = 'Password must be at least 8 characters.';
                } elseif (roleLevel($target['role']) >= roleLevel($currentUser['role']) && $currentUser['role'] !== 'global_admin' && $targetId !== $currentUser['id']) {
                    $error = 'Cannot reset password for a user at or above your role level.';
                } else {
                    changeUserPassword($targetId, $newPassword);
                    $success = 'Password reset successfully for "' . $target['username'] . '".';
                }
                break;

            /* ----- Update profile ----- */
            case 'update_profile':
                $targetId    = (int)($_POST['user_id'] ?? 0);
                $displayName = trim($_POST['display_name'] ?? '');
                $email       = trim($_POST['email'] ?? '');
                $target      = getUserById($targetId);
                if (!$target) {
                    $error = 'User not found.';
                } elseif (roleLevel($target['role']) >= roleLevel($currentUser['role']) && $currentUser['role'] !== 'global_admin' && $targetId !== $currentUser['id']) {
                    $error = 'Cannot edit a user at or above your role level.';
                } elseif ($displayName === '') {
                    $error = 'Display name cannot be empty.';
                } else {
                    updateUserProfile($targetId, $displayName, $email);
                    $success = 'Profile updated for "' . $target['username'] . '".';
                }
                break;

            /* ----- Rename (username change) ----- */
            case 'rename_user':
                $targetId    = (int)($_POST['user_id'] ?? 0);
                $newUsername = trim($_POST['new_username'] ?? '');
                $target      = getUserById($targetId);
                if (!$target) {
                    $error = 'User not found.';
                } elseif (roleLevel($target['role']) >= roleLevel($currentUser['role']) && $currentUser['role'] !== 'global_admin' && $targetId !== $currentUser['id']) {
                    $error = 'Cannot rename a user at or above your role level.';
                } else {
                    $renameError = null;
                    if (renameUser($targetId, $newUsername, $renameError)) {
                        $success = 'User "' . $target['username']
                                 . '" renamed to "' . mb_strtolower(trim($newUsername)) . '".';
                    } else {
                        $error = $renameError ?? 'Could not rename user.';
                    }
                }
                break;

            /* ----- Change access tier ----- */
            case 'change_tier':
                $targetId = (int)($_POST['user_id']    ?? 0);
                $newTier  = trim((string)($_POST['new_tier'] ?? ''));
                $target   = getUserById($targetId);
                if (!userHasEntitlement('assign_user_tier', $currentUser['role'] ?? null)) {
                    $error = 'You do not have permission to change access tiers.';
                } elseif (!$target) {
                    $error = 'User not found.';
                } elseif ($newTier === '') {
                    $error = 'Pick a tier.';
                } else {
                    $db = getDb();
                    $stmt = $db->prepare('SELECT 1 FROM tblAccessTiers WHERE Name = ?');
                    $stmt->execute([$newTier]);
                    if (!$stmt->fetch()) {
                        $error = 'Unknown access tier.';
                    } else {
                        $stmt = $db->prepare('UPDATE tblUsers SET AccessTier = ?, UpdatedAt = CURRENT_TIMESTAMP WHERE Id = ?');
                        $stmt->execute([$newTier, $targetId]);
                        $success = 'Access tier updated for "' . $target['username'] . '".';
                    }
                }
                break;

            /* ----- Delete user ----- */
            case 'delete':
                $targetId = (int)($_POST['user_id'] ?? 0);
                $target   = getUserById($targetId);
                if (!$target) {
                    $error = 'User not found.';
                } elseif ($targetId === $currentUser['id']) {
                    $error = 'Cannot delete your own account.';
                } elseif (roleLevel($target['role']) >= roleLevel($currentUser['role']) && $currentUser['role'] !== 'global_admin') {
                    $error = 'Cannot delete a user at or above your role level.';
                } else {
                    deleteUser($targetId);
                    $success = 'User "' . $target['username'] . '" deleted permanently.';
                }
                break;
        }
    }

    /* Refresh current user in case they edited themselves */
    $currentUser = getCurrentUser();
}

/* =========================================================================
 * FETCH DATA
 * ========================================================================= */

$db = getDb();
$users = $db->query('SELECT Id AS id, Username AS username, DisplayName AS display_name, Email AS email, Role AS role, IsActive AS is_active, AccessTier AS access_tier, CreatedAt AS created_at FROM tblUsers ORDER BY CreatedAt ASC')->fetchAll(PDO::FETCH_ASSOC);

/* Available access tiers for the Change-tier modal. Falls back to an empty
   list if the table is missing (e.g. pre-migration DB) — in that case the
   Tier button is hidden rather than breaking the page. */
$accessTiers = [];
try {
    $accessTiers = $db->query(
        'SELECT Name, DisplayName, Level FROM tblAccessTiers ORDER BY Level ASC, Name ASC'
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $_e) { /* tier table not installed yet — hide control */ }

$canAssignTier = !empty($accessTiers) && userHasEntitlement('assign_user_tier', $currentUser['role'] ?? null);

$csrf = csrfToken();

/* Helper: can the current user manage this target user? */
function canManage(array $target, array $actor): bool {
    if ((int)$target['id'] === (int)$actor['id']) return true; /* can edit self (limited) */
    if ($actor['role'] === 'global_admin') return true;
    return roleLevel($target['role']) < roleLevel($actor['role']);
}
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
    <!-- Shared iHymns palette + admin styles -->
    <link rel="stylesheet" href="/css/app.css?v=<?= filemtime(dirname(__DIR__) . "/css/app.css") ?>">
    <link rel="stylesheet" href="/css/admin.css?v=<?= filemtime(dirname(__DIR__) . "/css/admin.css") ?>">
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-favicon.php'; ?>
</head>
<body>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-nav.php'; ?>

    <div class="container-admin py-4">

        <h1 class="h4 mb-4"><i class="bi bi-people me-2"></i>User Management</h1>

        <?php if ($success): ?>
            <div class="alert alert-success py-2 alert-dismissible fade show">
                <i class="bi bi-check-circle me-1"></i><?= htmlspecialchars($success) ?>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger py-2 alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle me-1"></i><?= htmlspecialchars($error) ?>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Existing users -->
        <div class="card-admin p-3 mb-4">
            <h2 class="h6 mb-3">All Users <span class="badge bg-secondary ms-1"><?= count($users) ?></span></h2>
            <div class="table-responsive">
                <table class="table table-sm table-borderless mb-0">
                    <thead>
                        <tr class="text-muted small">
                            <th>Username</th>
                            <th>Display Name</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th title="Access tier — controls lyrics / audio / MIDI / PDF / offline access for regular users">Tier</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $u): ?>
                        <?php $manageable = canManage($u, $currentUser); $isSelf = ((int)$u['id'] === $currentUser['id']); ?>
                        <tr class="user-row">
                            <td>
                                <code><?= htmlspecialchars($u['username']) ?></code>
                                <?php if ($isSelf): ?><span class="badge bg-info text-dark" style="font-size:0.6rem">You</span><?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($u['display_name']) ?></td>
                            <td class="text-muted small"><?= htmlspecialchars($u['email'] ?? '') ?></td>
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
                            <td>
                                <span class="badge bg-dark border border-secondary text-light" style="font-size: 0.7rem;">
                                    <?= htmlspecialchars((string)($u['access_tier'] ?? 'free')) ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($u['is_active']): ?>
                                    <span class="text-success small"><i class="bi bi-circle-fill" style="font-size:0.5rem"></i> Active</span>
                                <?php else: ?>
                                    <span class="text-danger small"><i class="bi bi-circle-fill" style="font-size:0.5rem"></i> Disabled</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end user-actions">
                                <?php if ($manageable): ?>
                                    <!-- Edit Profile -->
                                    <button class="btn btn-outline-info" title="Edit profile"
                                            onclick="openEditModal(<?= (int)$u['id'] ?>, '<?= htmlspecialchars($u['display_name'], ENT_QUOTES) ?>', '<?= htmlspecialchars($u['email'] ?? '', ENT_QUOTES) ?>')">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <!-- Rename (change username) -->
                                    <button class="btn btn-outline-info" title="Rename user"
                                            onclick="openRenameModal(<?= (int)$u['id'] ?>, '<?= htmlspecialchars($u['username'], ENT_QUOTES) ?>')">
                                        <i class="bi bi-at"></i>
                                    </button>
                                    <!-- Change Role (not for self) -->
                                    <?php if (!$isSelf): ?>
                                    <button class="btn btn-outline-warning" title="Change role"
                                            onclick="openRoleModal(<?= (int)$u['id'] ?>, '<?= htmlspecialchars($u['username'], ENT_QUOTES) ?>', '<?= htmlspecialchars((string)$u['role'], ENT_QUOTES) ?>')">
                                        <i class="bi bi-shield"></i>
                                    </button>
                                    <?php endif; ?>
                                    <!-- Change Access Tier -->
                                    <?php if ($canAssignTier): ?>
                                    <button type="button" class="btn btn-outline-info"
                                            title="Change access tier"
                                            aria-label="Change access tier for <?= htmlspecialchars($u['username'], ENT_QUOTES) ?>"
                                            onclick="openTierModal(<?= (int)$u['id'] ?>, '<?= htmlspecialchars($u['username'], ENT_QUOTES) ?>', '<?= htmlspecialchars((string)($u['access_tier'] ?? ''), ENT_QUOTES) ?>')">
                                        <i class="bi bi-stars" aria-hidden="true"></i>
                                    </button>
                                    <?php endif; ?>
                                    <!-- Reset Password -->
                                    <button class="btn btn-outline-secondary" title="Reset password"
                                            onclick="openPasswordModal(<?= (int)$u['id'] ?>, '<?= htmlspecialchars($u['username'], ENT_QUOTES) ?>')">
                                        <i class="bi bi-key"></i>
                                    </button>
                                    <!-- Toggle Active (not for self) -->
                                    <?php if (!$isSelf): ?>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('<?= $u['is_active'] ? 'Deactivate' : 'Activate' ?> user <?= htmlspecialchars($u['username'], ENT_QUOTES) ?>?')">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                        <input type="hidden" name="action" value="toggle_active">
                                        <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                        <button class="btn <?= $u['is_active'] ? 'btn-outline-warning' : 'btn-outline-success' ?>"
                                                title="<?= $u['is_active'] ? 'Deactivate' : 'Activate' ?>">
                                            <i class="bi <?= $u['is_active'] ? 'bi-pause-circle' : 'bi-play-circle' ?>"></i>
                                        </button>
                                    </form>
                                    <!-- Delete -->
                                    <form method="POST" class="d-inline" onsubmit="return confirm('PERMANENTLY delete user <?= htmlspecialchars($u['username'], ENT_QUOTES) ?>? This cannot be undone.')">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                        <button class="btn btn-outline-danger" title="Delete user">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted small">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Create new user form -->
        <div class="card-admin p-3">
            <h2 class="h6 mb-3"><i class="bi bi-person-plus me-2"></i>Create New User</h2>

            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action" value="create">

                <div class="row mb-3">
                    <div class="col-md-6">
                        <label for="username" class="form-label">Username <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="username" name="username"
                               value="<?= htmlspecialchars($_POST['action'] ?? '') === 'create' ? htmlspecialchars($_POST['username'] ?? '') : '' ?>" minlength="3" required>
                    </div>
                    <div class="col-md-6">
                        <label for="display_name" class="form-label">Display Name</label>
                        <input type="text" class="form-control" id="display_name" name="display_name"
                               value="<?= htmlspecialchars($_POST['action'] ?? '') === 'create' ? htmlspecialchars($_POST['display_name'] ?? '') : '' ?>" placeholder="Defaults to username">
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

    <!-- ================================================================
         MODALS
         ================================================================ -->

    <!-- Edit Profile Modal -->
    <div class="modal fade" id="editModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content" style="background: var(--ih-surface); color: var(--ih-text); border-color: var(--ih-border);">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action" value="update_profile">
                    <input type="hidden" name="user_id" id="edit-user-id">
                    <div class="modal-header" style="border-color: var(--ih-border);">
                        <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit Profile</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Display Name</label>
                            <input type="text" class="form-control" name="display_name" id="edit-display-name" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" name="email" id="edit-email" placeholder="Optional">
                        </div>
                    </div>
                    <div class="modal-footer" style="border-color: var(--ih-border);">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-amber">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Change Role Modal -->
    <div class="modal fade" id="roleModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content" style="background: var(--ih-surface); color: var(--ih-text); border-color: var(--ih-border);">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action" value="change_role">
                    <input type="hidden" name="user_id" id="role-user-id">
                    <div class="modal-header" style="border-color: var(--ih-border);">
                        <h5 class="modal-title"><i class="bi bi-shield me-2"></i>Change Role — <span id="role-username"></span></h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <select class="form-select" name="new_role" id="role-select">
                            <option value="user">User — can save setlists centrally</option>
                            <option value="editor">Curator / Editor — can edit songs</option>
                            <option value="admin">Admin — full access including user management</option>
                            <?php if ($currentUser['role'] === 'global_admin'): ?>
                            <option value="global_admin">Global Admin — unrestricted access</option>
                            <?php endif; ?>
                        </select>
                        <div class="form-text" style="color: var(--ih-text-muted);">
                            You cannot assign a role higher than your own.
                        </div>
                    </div>
                    <div class="modal-footer" style="border-color: var(--ih-border);">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-amber">Update Role</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Rename User Modal -->
    <div class="modal fade" id="renameModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content" style="background: var(--ih-surface); color: var(--ih-text); border-color: var(--ih-border);">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action" value="rename_user">
                    <input type="hidden" name="user_id" id="rename-user-id">
                    <div class="modal-header" style="border-color: var(--ih-border);">
                        <h5 class="modal-title"><i class="bi bi-at me-2"></i>Rename — <span id="rename-current-username"></span></h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">New username</label>
                            <input type="text" class="form-control" name="new_username" id="rename-new-username"
                                   minlength="3" maxlength="100" pattern="[A-Za-z0-9_.\-]+"
                                   autocomplete="off" autocapitalize="none" spellcheck="false" required>
                            <div class="form-text" style="color: var(--ih-text-muted);">
                                Letters (any case), numbers, dots, dashes and underscores. 3–100 characters.
                                Usernames are unique case-insensitively — "Alice" and "alice" cannot coexist.
                            </div>
                        </div>
                        <div class="alert alert-info py-2 small mb-0">
                            <i class="bi bi-info-circle me-1"></i>
                            Existing tokens, setlists, favourites and revisions stay tied to the
                            user. Old login attempts logged under the previous username remain in
                            the audit history.
                        </div>
                    </div>
                    <div class="modal-footer" style="border-color: var(--ih-border);">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-amber">Rename</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Change Access Tier Modal -->
    <?php if ($canAssignTier): ?>
    <div class="modal fade" id="tierModal" tabindex="-1" aria-labelledby="tierModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content" style="background: var(--ih-surface); color: var(--ih-text); border-color: var(--ih-border);">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action" value="change_tier">
                    <input type="hidden" name="user_id" id="tier-user-id">
                    <div class="modal-header" style="border-color: var(--ih-border);">
                        <h5 class="modal-title" id="tierModalLabel"><i class="bi bi-stars me-2" aria-hidden="true"></i>Change Access Tier — <span id="tier-username"></span></h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <label class="form-label small">Access tier</label>
                        <select class="form-select" name="new_tier" id="tier-select">
                            <?php foreach ($accessTiers as $at): ?>
                                <option value="<?= htmlspecialchars($at['Name']) ?>">
                                    <?= htmlspecialchars($at['DisplayName']) ?>
                                    — <code><?= htmlspecialchars($at['Name']) ?></code>
                                    (level <?= (int)$at['Level'] ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text" style="color: var(--ih-text-muted);">
                            Controls whether the user can view copyrighted lyrics, play audio,
                            download MIDI / sheet music, and save songs offline. Tiers are defined in
                            <a href="/manage/tiers" class="text-info">Access Tiers</a>.
                        </div>
                    </div>
                    <div class="modal-footer" style="border-color: var(--ih-border);">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-amber-solid">Update tier</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Reset Password Modal -->
    <div class="modal fade" id="passwordModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content" style="background: var(--ih-surface); color: var(--ih-text); border-color: var(--ih-border);">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action" value="reset_password">
                    <input type="hidden" name="user_id" id="pw-user-id">
                    <div class="modal-header" style="border-color: var(--ih-border);">
                        <h5 class="modal-title"><i class="bi bi-key me-2"></i>Reset Password — <span id="pw-username"></span></h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">New Password</label>
                            <input type="password" class="form-control" name="new_password" minlength="8" required
                                   placeholder="Minimum 8 characters">
                        </div>
                        <div class="alert alert-warning py-2 small mb-0">
                            <i class="bi bi-exclamation-triangle me-1"></i>
                            This will invalidate all active sessions and API tokens for this user.
                        </div>
                    </div>
                    <div class="modal-footer" style="border-color: var(--ih-border);">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Reset Password</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
            integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz"
            crossorigin="anonymous"></script>
    <script>
        /* Open Edit Profile modal */
        function openEditModal(userId, displayName, email) {
            document.getElementById('edit-user-id').value = userId;
            document.getElementById('edit-display-name').value = displayName;
            document.getElementById('edit-email').value = email;
            new bootstrap.Modal(document.getElementById('editModal')).show();
        }

        /* Open Change Role modal */
        function openRoleModal(userId, username, currentRole) {
            document.getElementById('role-user-id').value = userId;
            document.getElementById('role-username').textContent = username;
            document.getElementById('role-select').value = currentRole;
            new bootstrap.Modal(document.getElementById('roleModal')).show();
        }

        /* Open Reset Password modal */
        function openPasswordModal(userId, username) {
            document.getElementById('pw-user-id').value = userId;
            document.getElementById('pw-username').textContent = username;
            new bootstrap.Modal(document.getElementById('passwordModal')).show();
        }

        /* Open Change-tier modal */
        function openTierModal(userId, username, currentTier) {
            document.getElementById('tier-user-id').value = userId;
            document.getElementById('tier-username').textContent = username;
            const sel = document.getElementById('tier-select');
            if (sel && currentTier) {
                const match = Array.from(sel.options).find(o => o.value === currentTier);
                if (match) sel.value = currentTier;
            }
            new bootstrap.Modal(document.getElementById('tierModal')).show();
        }

        /* Open Rename modal */
        function openRenameModal(userId, currentUsername) {
            document.getElementById('rename-user-id').value = userId;
            document.getElementById('rename-current-username').textContent = currentUsername;
            const input = document.getElementById('rename-new-username');
            input.value = currentUsername;
            new bootstrap.Modal(document.getElementById('renameModal')).show();
            setTimeout(() => { input.focus(); input.select(); }, 200);
        }
    </script>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>
</body>
</html>
