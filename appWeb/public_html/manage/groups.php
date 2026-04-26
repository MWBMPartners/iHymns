<?php

declare(strict_types=1);

/**
 * iHymns — Admin: User Groups
 *
 * Minimal CRUD over `tblUserGroups` plus a two-pane member picker that
 * writes back to `tblUsers.GroupId`. Gated by `manage_user_groups`.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';

if (!isAuthenticated()) {
    header('Location: /manage/login');
    exit;
}
$currentUser = getCurrentUser();
if (!$currentUser || !userHasEntitlement('manage_user_groups', $currentUser['role'] ?? null)) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><body><h1>403 — manage_user_groups required</h1></body></html>';
    exit;
}
$activePage = 'groups';

$error   = '';
$success = '';
$db      = getDb();

/* ----- POST actions ----- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrf((string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo 'Invalid CSRF token';
        exit;
    }

    $action = (string)($_POST['action'] ?? '');
    try {
        switch ($action) {
            case 'create': {
                $name  = trim((string)($_POST['name']         ?? ''));
                $desc  = trim((string)($_POST['description']  ?? ''));
                $aA    = !empty($_POST['access_alpha']) ? 1 : 0;
                $aB    = !empty($_POST['access_beta'])  ? 1 : 0;
                $aR    = !empty($_POST['access_rc'])    ? 1 : 0;
                $aW    = !empty($_POST['access_rtw'])   ? 1 : 0;
                if ($name === '') { $error = 'Name is required.'; break; }
                if (strlen($name) > 100) { $error = 'Name must be 100 characters or fewer.'; break; }

                $stmt = $db->prepare('SELECT Id FROM tblUserGroups WHERE Name = ?');
                $stmt->execute([$name]);
                if ($stmt->fetch()) { $error = 'A group with that name already exists.'; break; }

                $stmt = $db->prepare(
                    'INSERT INTO tblUserGroups (Name, Description, AccessAlpha, AccessBeta, AccessRc, AccessRtw)
                     VALUES (?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([$name, $desc, $aA, $aB, $aR, $aW]);
                $success = "Group '{$name}' created.";
                break;
            }

            case 'update': {
                $id   = (int)($_POST['id'] ?? 0);
                $name = trim((string)($_POST['name']        ?? ''));
                $desc = trim((string)($_POST['description'] ?? ''));
                $aA   = !empty($_POST['access_alpha']) ? 1 : 0;
                $aB   = !empty($_POST['access_beta'])  ? 1 : 0;
                $aR   = !empty($_POST['access_rc'])    ? 1 : 0;
                $aW   = !empty($_POST['access_rtw'])   ? 1 : 0;
                if ($id <= 0) { $error = 'Group id missing.'; break; }
                if ($name === '') { $error = 'Name is required.'; break; }

                $stmt = $db->prepare('SELECT Id FROM tblUserGroups WHERE Name = ? AND Id <> ?');
                $stmt->execute([$name, $id]);
                if ($stmt->fetch()) { $error = 'Another group already uses that name.'; break; }

                $stmt = $db->prepare(
                    'UPDATE tblUserGroups
                        SET Name = ?, Description = ?,
                            AccessAlpha = ?, AccessBeta = ?, AccessRc = ?, AccessRtw = ?
                      WHERE Id = ?'
                );
                $stmt->execute([$name, $desc, $aA, $aB, $aR, $aW, $id]);
                $success = "Group '{$name}' updated.";
                break;
            }

            case 'delete': {
                $id = (int)($_POST['id'] ?? 0);

                $stmt = $db->prepare('SELECT Name FROM tblUserGroups WHERE Id = ?');
                $stmt->execute([$id]);
                $name = (string)($stmt->fetchColumn() ?: '');
                if ($name === '') { $error = 'Group not found.'; break; }

                $stmt = $db->prepare('SELECT COUNT(*) FROM tblUsers WHERE GroupId = ?');
                $stmt->execute([$id]);
                $members = (int)$stmt->fetchColumn();
                if ($members > 0) {
                    $error = "Cannot delete '{$name}': {$members} user(s) still belong to it. Move them to another group first.";
                    break;
                }

                $stmt = $db->prepare('DELETE FROM tblUserGroups WHERE Id = ?');
                $stmt->execute([$id]);
                $success = "Group '{$name}' deleted.";
                break;
            }

            case 'add_member': {
                $groupId = (int)($_POST['group_id'] ?? 0);
                $userId  = (int)($_POST['user_id']  ?? 0);
                if ($groupId <= 0 || $userId <= 0) { $error = 'Invalid request.'; break; }
                $stmt = $db->prepare('UPDATE tblUsers SET GroupId = ?, UpdatedAt = CURRENT_TIMESTAMP WHERE Id = ?');
                $stmt->execute([$groupId, $userId]);
                $success = 'Member added.';
                break;
            }

            case 'remove_member': {
                $userId = (int)($_POST['user_id'] ?? 0);
                if ($userId <= 0) { $error = 'Invalid request.'; break; }
                $stmt = $db->prepare('UPDATE tblUsers SET GroupId = NULL, UpdatedAt = CURRENT_TIMESTAMP WHERE Id = ?');
                $stmt->execute([$userId]);
                $success = 'Member removed from group.';
                break;
            }

            default:
                $error = 'Unknown action.';
        }
    } catch (\Throwable $e) {
        error_log('[manage/groups.php] ' . $e->getMessage());
        $error = $error ?: 'Database error — check server logs for details.';
    }
}

/* ----- GET: fetch groups + members ----- */
$groups = [];
try {
    $rs = $db->query(
        'SELECT g.Id, g.Name, g.Description,
                g.AccessAlpha, g.AccessBeta, g.AccessRc, g.AccessRtw,
                COUNT(u.Id) AS MemberCount
           FROM tblUserGroups g
           LEFT JOIN tblUsers u ON u.GroupId = g.Id
          GROUP BY g.Id
          ORDER BY g.Name ASC'
    );
    $groups = $rs->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    error_log('[manage/groups.php] ' . $e->getMessage());
    $error = $error ?: 'Could not load groups.';
}

/* If ?edit=<id>, pull the editing group + members + candidates */
$editGroup = null;
$editMembers = [];
$candidates  = [];
$editId = (int)($_GET['edit'] ?? 0);
if ($editId > 0) {
    try {
        $stmt = $db->prepare('SELECT * FROM tblUserGroups WHERE Id = ?');
        $stmt->execute([$editId]);
        $editGroup = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($editGroup) {
            $stmt = $db->prepare(
                'SELECT Id, Username, DisplayName, Role, IsActive
                   FROM tblUsers WHERE GroupId = ? ORDER BY Username ASC'
            );
            $stmt->execute([$editId]);
            $editMembers = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $stmt = $db->prepare(
                'SELECT Id, Username, DisplayName, Role
                   FROM tblUsers
                  WHERE (GroupId IS NULL OR GroupId <> ?) AND IsActive = 1
                  ORDER BY Username ASC
                  LIMIT 500'
            );
            $stmt->execute([$editId]);
            $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (\Throwable $e) {
        error_log('[manage/groups.php] ' . $e->getMessage());
    }
}

$csrf = csrfToken();
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Groups — iHymns Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
          integrity="sha384-XGjxtQfXaH2tnPFa9x+ruJTuLE3Aa6LhHSWRr1XeTyhezb4abCG4ccI5AkVDxqC+" crossorigin="anonymous">
    <link rel="stylesheet" href="/css/app.css?v=<?= filemtime(dirname(__DIR__) . '/css/app.css') ?>">
    <link rel="stylesheet" href="/css/admin.css?v=<?= filemtime(dirname(__DIR__) . '/css/admin.css') ?>">
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-favicon.php'; ?>
</head>
<body>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-nav.php'; ?>

    <div class="container-admin py-4">

        <h1 class="h4 mb-3"><i class="bi bi-people-fill me-2"></i>User Groups</h1>
        <p class="text-secondary small mb-4">
            Define groups for shared access settings (alpha / beta / RC / RTW).
            Each user belongs to at most one group via <code>tblUsers.GroupId</code>.
        </p>

        <?php if ($success): ?>
            <div class="alert alert-success py-2"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if (!$editGroup): ?>
            <!-- List of groups -->
            <div class="card-admin p-3 mb-4">
                <h2 class="h6 mb-3">All groups</h2>
                <table class="table table-sm mb-0 align-middle">
                    <thead>
                        <tr class="text-muted small">
                            <th>Name</th>
                            <th>Description</th>
                            <th class="text-center" title="Alpha">α</th>
                            <th class="text-center" title="Beta">β</th>
                            <th class="text-center" title="Release Candidate">RC</th>
                            <th class="text-center" title="Release to Web">RTW</th>
                            <th class="text-center">Members</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($groups as $g): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($g['Name']) ?></strong></td>
                                <td class="text-muted small"><?= htmlspecialchars(mb_substr((string)$g['Description'], 0, 120)) ?></td>
                                <?php foreach (['AccessAlpha', 'AccessBeta', 'AccessRc', 'AccessRtw'] as $k): ?>
                                    <td class="text-center">
                                        <?= (int)$g[$k] ? '<i class="bi bi-check-circle text-success"></i>' : '<i class="bi bi-dash text-muted"></i>' ?>
                                    </td>
                                <?php endforeach; ?>
                                <td class="text-center"><?= (int)$g['MemberCount'] ?></td>
                                <td class="text-end">
                                    <a href="?edit=<?= (int)$g['Id'] ?>" class="btn btn-sm btn-outline-info" title="Edit and manage members">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <?php if ((int)$g['MemberCount'] === 0): ?>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Delete group <?= htmlspecialchars($g['Name'], ENT_QUOTES) ?>?')">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int)$g['Id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete (empty group)"><i class="bi bi-trash"></i></button>
                                        </form>
                                    <?php else: ?>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" disabled title="Group has members — move them first"><i class="bi bi-trash"></i></button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$groups): ?>
                            <tr><td colspan="8" class="text-muted text-center py-4">No groups yet. Add one below.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Create -->
            <form method="POST" class="card-admin p-3 mb-4">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action" value="create">
                <h2 class="h6 mb-3"><i class="bi bi-plus-circle me-2"></i>Add a group</h2>
                <div class="row g-2 mb-2">
                    <div class="col-sm-4">
                        <label class="form-label small">Name</label>
                        <input type="text" name="name" class="form-control form-control-sm" maxlength="100" required>
                    </div>
                    <div class="col-sm-8">
                        <label class="form-label small">Description</label>
                        <input type="text" name="description" class="form-control form-control-sm">
                    </div>
                </div>
                <div class="d-flex flex-wrap gap-3">
                    <?php foreach ([
                        'access_alpha' => ['Alpha',  false],
                        'access_beta'  => ['Beta',   false],
                        'access_rc'    => ['RC',     false],
                        'access_rtw'   => ['RTW',    true],
                    ] as $k => [$lbl, $def]): ?>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="<?= $k ?>" id="new-<?= $k ?>" value="1" <?= $def ? 'checked' : '' ?>>
                            <label class="form-check-label" for="new-<?= $k ?>"><?= $lbl ?></label>
                        </div>
                    <?php endforeach; ?>
                </div>
                <button type="submit" class="btn btn-amber-solid btn-sm mt-3">
                    <i class="bi bi-plus me-1"></i>Create group
                </button>
            </form>

        <?php else: ?>

            <!-- Edit Group: settings + members -->
            <div class="mb-3">
                <a href="/manage/groups" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i>Back to group list
                </a>
            </div>

            <form method="POST" class="card-admin p-3 mb-4">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" value="<?= (int)$editGroup['Id'] ?>">
                <h2 class="h6 mb-3"><i class="bi bi-sliders me-2"></i>Settings — <?= htmlspecialchars($editGroup['Name']) ?></h2>
                <div class="row g-2 mb-2">
                    <div class="col-sm-4">
                        <label class="form-label small">Name</label>
                        <input type="text" name="name" class="form-control form-control-sm" maxlength="100" required
                               value="<?= htmlspecialchars($editGroup['Name']) ?>">
                    </div>
                    <div class="col-sm-8">
                        <label class="form-label small">Description</label>
                        <input type="text" name="description" class="form-control form-control-sm"
                               value="<?= htmlspecialchars($editGroup['Description']) ?>">
                    </div>
                </div>
                <div class="d-flex flex-wrap gap-3">
                    <?php foreach ([
                        'access_alpha' => ['Alpha', 'AccessAlpha'],
                        'access_beta'  => ['Beta',  'AccessBeta'],
                        'access_rc'    => ['RC',    'AccessRc'],
                        'access_rtw'   => ['RTW',   'AccessRtw'],
                    ] as $k => [$lbl, $col]): ?>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="<?= $k ?>" id="edit-<?= $k ?>" value="1"
                                   <?= (int)$editGroup[$col] ? 'checked' : '' ?>>
                            <label class="form-check-label" for="edit-<?= $k ?>"><?= $lbl ?></label>
                        </div>
                    <?php endforeach; ?>
                </div>
                <button type="submit" class="btn btn-amber-solid btn-sm mt-3">
                    <i class="bi bi-save me-1"></i>Save settings
                </button>
            </form>

            <div class="row g-3">
                <!-- Current members -->
                <div class="col-md-6">
                    <div class="card-admin p-3 h-100">
                        <h2 class="h6 mb-3"><i class="bi bi-people me-2"></i>Members (<?= count($editMembers) ?>)</h2>
                        <?php if (!$editMembers): ?>
                            <p class="text-muted small mb-0">No members yet — add from the list on the right.</p>
                        <?php else: ?>
                            <ul class="list-group list-group-flush">
                                <?php foreach ($editMembers as $u): ?>
                                    <li class="list-group-item d-flex align-items-center justify-content-between"
                                        style="background: transparent; color: inherit;">
                                        <span>
                                            <code><?= htmlspecialchars($u['Username']) ?></code>
                                            <small class="text-muted ms-1"><?= htmlspecialchars($u['DisplayName']) ?></small>
                                            <span class="badge bg-secondary ms-1" style="font-size: 0.65rem"><?= htmlspecialchars($u['Role']) ?></span>
                                        </span>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Remove <?= htmlspecialchars($u['Username'], ENT_QUOTES) ?> from this group?')">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                            <input type="hidden" name="action"     value="remove_member">
                                            <input type="hidden" name="user_id"    value="<?= (int)$u['Id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Remove from group">
                                                <i class="bi bi-x"></i>
                                            </button>
                                        </form>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Add candidates -->
                <div class="col-md-6">
                    <div class="card-admin p-3 h-100">
                        <h2 class="h6 mb-3"><i class="bi bi-person-plus me-2"></i>Add a member</h2>
                        <?php if (!$candidates): ?>
                            <p class="text-muted small mb-0">Every active user is already in this group.</p>
                        <?php else: ?>
                            <form method="POST" class="d-flex gap-2">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                <input type="hidden" name="action"     value="add_member">
                                <input type="hidden" name="group_id"   value="<?= (int)$editGroup['Id'] ?>">
                                <select name="user_id" class="form-select form-select-sm" required>
                                    <option value="">— pick a user —</option>
                                    <?php foreach ($candidates as $u): ?>
                                        <option value="<?= (int)$u['Id'] ?>">
                                            <?= htmlspecialchars($u['Username']) ?>
                                            <?php if ($u['DisplayName']): ?> — <?= htmlspecialchars($u['DisplayName']) ?><?php endif; ?>
                                            (<?= htmlspecialchars($u['Role']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="btn btn-sm btn-amber-solid">
                                    <i class="bi bi-plus"></i>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        <?php endif; ?>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
            integrity="sha384-zKzgIZcXU99qF1nNW9g+x1znB5NhCPs9qZeGzUnnFOaHJF9jCCKySBjq3vIKabk/"
            crossorigin="anonymous"></script>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>
</body>
</html>
