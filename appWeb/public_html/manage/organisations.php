<?php

declare(strict_types=1);

/**
 * iHymns — Admin: Organisations
 *
 * Minimal CRUD over `tblOrganisations` + member management via
 * `tblOrganisationMembers`. Gated by `manage_organisations`.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'entitlements.php';

if (!isAuthenticated()) {
    header('Location: /manage/login');
    exit;
}
$currentUser = getCurrentUser();
if (!$currentUser || !userHasEntitlement('manage_organisations', $currentUser['role'] ?? null)) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><body><h1>403 — manage_organisations required</h1></body></html>';
    exit;
}
$activePage = 'organisations';

$error   = '';
$success = '';
$db      = getDb();

$LICENCE_TYPES  = ['none', 'ihymns_basic', 'ihymns_pro', 'ccli'];
$MEMBER_ROLES   = ['member', 'admin', 'owner'];

$slugify = function (string $s): string {
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim((string)$s, '-');
};

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
                $name        = trim((string)($_POST['name']         ?? ''));
                $slugInput   = trim((string)($_POST['slug']         ?? ''));
                $parent      = (int)($_POST['parent_org_id']        ?? 0);
                $desc        = trim((string)($_POST['description']  ?? ''));
                $licenceType = (string)($_POST['licence_type']      ?? 'none');
                $licenceNum  = trim((string)($_POST['licence_number'] ?? ''));
                $active      = !empty($_POST['is_active']) ? 1 : 0;

                if ($name === '') { $error = 'Name is required.'; break; }
                $slug = $slugInput !== '' ? $slugify($slugInput) : $slugify($name);
                if ($slug === '') { $error = 'Slug could not be derived — supply one explicitly.'; break; }
                if (!in_array($licenceType, $LICENCE_TYPES, true)) { $error = 'Unknown licence type.'; break; }

                $stmt = $db->prepare('SELECT Id FROM tblOrganisations WHERE Slug = ?');
                $stmt->execute([$slug]);
                if ($stmt->fetch()) { $error = 'That slug is already in use.'; break; }

                $stmt = $db->prepare(
                    'INSERT INTO tblOrganisations
                        (Name, Slug, ParentOrgId, Description, LicenceType, LicenceNumber, IsActive)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([$name, $slug, $parent ?: null, $desc, $licenceType, $licenceNum, $active]);
                $success = "Organisation '{$name}' created.";
                break;
            }

            case 'update': {
                $id          = (int)($_POST['id'] ?? 0);
                $name        = trim((string)($_POST['name']         ?? ''));
                $slug        = $slugify((string)($_POST['slug']     ?? ''));
                $parent      = (int)($_POST['parent_org_id']        ?? 0);
                $desc        = trim((string)($_POST['description']  ?? ''));
                $licenceType = (string)($_POST['licence_type']      ?? 'none');
                $licenceNum  = trim((string)($_POST['licence_number'] ?? ''));
                $active      = !empty($_POST['is_active']) ? 1 : 0;
                if ($id <= 0) { $error = 'Organisation id missing.'; break; }
                if ($name === '') { $error = 'Name is required.'; break; }
                if ($slug === '') { $error = 'Slug is required.'; break; }
                if (!in_array($licenceType, $LICENCE_TYPES, true)) { $error = 'Unknown licence type.'; break; }
                if ($parent === $id) { $error = 'An organisation cannot be its own parent.'; break; }

                $stmt = $db->prepare('SELECT Id FROM tblOrganisations WHERE Slug = ? AND Id <> ?');
                $stmt->execute([$slug, $id]);
                if ($stmt->fetch()) { $error = 'That slug is already in use.'; break; }

                $stmt = $db->prepare(
                    'UPDATE tblOrganisations
                        SET Name = ?, Slug = ?, ParentOrgId = ?, Description = ?,
                            LicenceType = ?, LicenceNumber = ?, IsActive = ?
                      WHERE Id = ?'
                );
                $stmt->execute([$name, $slug, $parent ?: null, $desc, $licenceType, $licenceNum, $active, $id]);
                $success = "Organisation updated.";
                break;
            }

            case 'delete': {
                $id = (int)($_POST['id'] ?? 0);

                $stmt = $db->prepare('SELECT Name FROM tblOrganisations WHERE Id = ?');
                $stmt->execute([$id]);
                $name = (string)($stmt->fetchColumn() ?: '');
                if ($name === '') { $error = 'Organisation not found.'; break; }

                $stmt = $db->prepare('SELECT COUNT(*) FROM tblOrganisationMembers WHERE OrgId = ?');
                $stmt->execute([$id]);
                $members = (int)$stmt->fetchColumn();
                if ($members > 0) { $error = "Cannot delete '{$name}': {$members} member(s) still listed."; break; }

                $stmt = $db->prepare('SELECT COUNT(*) FROM tblOrganisations WHERE ParentOrgId = ?');
                $stmt->execute([$id]);
                $children = (int)$stmt->fetchColumn();
                if ($children > 0) { $error = "Cannot delete '{$name}': {$children} sub-organisation(s) still reference it as parent."; break; }

                $stmt = $db->prepare('DELETE FROM tblOrganisations WHERE Id = ?');
                $stmt->execute([$id]);
                $success = "Organisation '{$name}' deleted.";
                break;
            }

            case 'add_member': {
                $orgId  = (int)($_POST['org_id'] ?? 0);
                $userId = (int)($_POST['user_id'] ?? 0);
                $role   = (string)($_POST['member_role'] ?? 'member');
                if ($orgId <= 0 || $userId <= 0) { $error = 'Invalid request.'; break; }
                if (!in_array($role, $MEMBER_ROLES, true)) { $error = 'Unknown member role.'; break; }

                $stmt = $db->prepare(
                    'INSERT INTO tblOrganisationMembers (UserId, OrgId, Role)
                     VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE Role = VALUES(Role)'
                );
                $stmt->execute([$userId, $orgId, $role]);
                $success = 'Member added / updated.';
                break;
            }

            case 'update_member_role': {
                $orgId  = (int)($_POST['org_id'] ?? 0);
                $userId = (int)($_POST['user_id'] ?? 0);
                $role   = (string)($_POST['member_role'] ?? 'member');
                if ($orgId <= 0 || $userId <= 0) { $error = 'Invalid request.'; break; }
                if (!in_array($role, $MEMBER_ROLES, true)) { $error = 'Unknown member role.'; break; }

                $stmt = $db->prepare('UPDATE tblOrganisationMembers SET Role = ? WHERE OrgId = ? AND UserId = ?');
                $stmt->execute([$role, $orgId, $userId]);
                $success = 'Member role updated.';
                break;
            }

            case 'remove_member': {
                $orgId  = (int)($_POST['org_id'] ?? 0);
                $userId = (int)($_POST['user_id'] ?? 0);
                if ($orgId <= 0 || $userId <= 0) { $error = 'Invalid request.'; break; }
                $stmt = $db->prepare('DELETE FROM tblOrganisationMembers WHERE OrgId = ? AND UserId = ?');
                $stmt->execute([$orgId, $userId]);
                $success = 'Member removed.';
                break;
            }

            default:
                $error = 'Unknown action.';
        }
    } catch (\Throwable $e) {
        error_log('[manage/organisations.php] ' . $e->getMessage());
        $error = $error ?: 'Database error — check server logs for details.';
    }
}

/* ----- Fetch list ----- */
$orgs = [];
try {
    $rs = $db->query(
        'SELECT o.*, p.Name AS ParentName,
                (SELECT COUNT(*) FROM tblOrganisationMembers WHERE OrgId = o.Id) AS MemberCount
           FROM tblOrganisations o
           LEFT JOIN tblOrganisations p ON p.Id = o.ParentOrgId
          ORDER BY o.Name ASC'
    );
    $orgs = $rs->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    error_log('[manage/organisations.php] ' . $e->getMessage());
    $error = $error ?: 'Could not load organisations.';
}

/* Edit mode */
$editOrg     = null;
$editMembers = [];
$candidates  = [];
$editId = (int)($_GET['edit'] ?? 0);
if ($editId > 0) {
    try {
        $stmt = $db->prepare('SELECT * FROM tblOrganisations WHERE Id = ?');
        $stmt->execute([$editId]);
        $editOrg = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($editOrg) {
            $stmt = $db->prepare(
                'SELECT u.Id, u.Username, u.DisplayName, u.Role AS SystemRole,
                        m.Role AS OrgRole, m.JoinedAt
                   FROM tblOrganisationMembers m
                   JOIN tblUsers u ON u.Id = m.UserId
                  WHERE m.OrgId = ?
                  ORDER BY m.JoinedAt DESC'
            );
            $stmt->execute([$editId]);
            $editMembers = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $stmt = $db->prepare(
                'SELECT u.Id, u.Username, u.DisplayName
                   FROM tblUsers u
                   LEFT JOIN tblOrganisationMembers m ON m.UserId = u.Id AND m.OrgId = ?
                  WHERE u.IsActive = 1 AND m.UserId IS NULL
                  ORDER BY u.Username ASC
                  LIMIT 500'
            );
            $stmt->execute([$editId]);
            $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (\Throwable $e) {
        error_log('[manage/organisations.php] ' . $e->getMessage());
    }
}

$csrf = csrfToken();
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Organisations — iHymns Admin</title>
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

        <h1 class="h4 mb-3"><i class="bi bi-building me-2"></i>Organisations</h1>
        <p class="text-secondary small mb-4">
            Add and edit organisations (churches / groups), manage their members,
            and maintain licence metadata.
        </p>

        <?php if ($success): ?>
            <div class="alert alert-success py-2"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if (!$editOrg): ?>

            <div class="card-admin p-3 mb-4">
                <h2 class="h6 mb-3">All organisations</h2>
                <table class="table table-sm mb-0 align-middle">
                    <thead>
                        <tr class="text-muted small">
                            <th>Name</th>
                            <th>Slug</th>
                            <th>Parent</th>
                            <th>Licence</th>
                            <th class="text-center">Active</th>
                            <th class="text-center">Members</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orgs as $o): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($o['Name']) ?></strong></td>
                                <td><code class="small"><?= htmlspecialchars($o['Slug']) ?></code></td>
                                <td class="text-muted small"><?= htmlspecialchars($o['ParentName'] ?? '—') ?></td>
                                <td>
                                    <span class="badge bg-secondary" style="font-size: 0.7rem"><?= htmlspecialchars($o['LicenceType']) ?></span>
                                    <?php if ($o['LicenceNumber']): ?>
                                        <small class="text-muted ms-1"><?= htmlspecialchars($o['LicenceNumber']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?= (int)$o['IsActive'] ? '<i class="bi bi-check-circle text-success"></i>' : '<i class="bi bi-x-circle text-muted"></i>' ?>
                                </td>
                                <td class="text-center"><?= (int)$o['MemberCount'] ?></td>
                                <td class="text-end">
                                    <a href="?edit=<?= (int)$o['Id'] ?>" class="btn btn-sm btn-outline-info" title="Edit and manage members">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <?php if ((int)$o['MemberCount'] === 0): ?>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Delete organisation <?= htmlspecialchars($o['Name'], ENT_QUOTES) ?>?')">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int)$o['Id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete (empty org)"><i class="bi bi-trash"></i></button>
                                        </form>
                                    <?php else: ?>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" disabled title="Org has members — remove them first"><i class="bi bi-trash"></i></button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$orgs): ?>
                            <tr><td colspan="7" class="text-muted text-center py-4">No organisations yet. Add one below.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <form method="POST" class="card-admin p-3 mb-4">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action" value="create">
                <h2 class="h6 mb-3"><i class="bi bi-plus-circle me-2"></i>Add an organisation</h2>
                <div class="row g-2 mb-2">
                    <div class="col-sm-5">
                        <label class="form-label small">Name</label>
                        <input type="text" name="name" class="form-control form-control-sm" maxlength="255" required>
                    </div>
                    <div class="col-sm-3">
                        <label class="form-label small">Slug (optional, auto-derived)</label>
                        <input type="text" name="slug" class="form-control form-control-sm" maxlength="100" placeholder="auto">
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label small">Parent organisation</label>
                        <select name="parent_org_id" class="form-select form-select-sm">
                            <option value="">— None —</option>
                            <?php foreach ($orgs as $o): ?>
                                <option value="<?= (int)$o['Id'] ?>"><?= htmlspecialchars($o['Name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="mb-2">
                    <label class="form-label small">Description</label>
                    <input type="text" name="description" class="form-control form-control-sm">
                </div>
                <div class="row g-2 mb-2">
                    <div class="col-sm-4">
                        <label class="form-label small">Licence type</label>
                        <select name="licence_type" class="form-select form-select-sm">
                            <?php foreach ($LICENCE_TYPES as $lt): ?>
                                <option value="<?= $lt ?>"><?= $lt ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label small">Licence number</label>
                        <input type="text" name="licence_number" class="form-control form-control-sm" maxlength="100">
                    </div>
                    <div class="col-sm-4 d-flex align-items-end">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_active" id="new-is-active" value="1" checked>
                            <label class="form-check-label" for="new-is-active">Active</label>
                        </div>
                    </div>
                </div>
                <button type="submit" class="btn btn-amber-solid btn-sm mt-2">
                    <i class="bi bi-plus me-1"></i>Create organisation
                </button>
            </form>

        <?php else: ?>

            <div class="mb-3">
                <a href="/manage/organisations" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i>Back to organisation list
                </a>
            </div>

            <form method="POST" class="card-admin p-3 mb-4">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" value="<?= (int)$editOrg['Id'] ?>">
                <h2 class="h6 mb-3"><i class="bi bi-sliders me-2"></i>Settings — <?= htmlspecialchars($editOrg['Name']) ?></h2>
                <div class="row g-2 mb-2">
                    <div class="col-sm-5">
                        <label class="form-label small">Name</label>
                        <input type="text" name="name" class="form-control form-control-sm" maxlength="255" required
                               value="<?= htmlspecialchars($editOrg['Name']) ?>">
                    </div>
                    <div class="col-sm-3">
                        <label class="form-label small">Slug</label>
                        <input type="text" name="slug" class="form-control form-control-sm" maxlength="100" required
                               value="<?= htmlspecialchars($editOrg['Slug']) ?>">
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label small">Parent organisation</label>
                        <select name="parent_org_id" class="form-select form-select-sm">
                            <option value="">— None —</option>
                            <?php foreach ($orgs as $o): ?>
                                <?php if ((int)$o['Id'] === (int)$editOrg['Id']) continue; /* no self-parent */ ?>
                                <option value="<?= (int)$o['Id'] ?>" <?= (int)$o['Id'] === (int)$editOrg['ParentOrgId'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($o['Name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="mb-2">
                    <label class="form-label small">Description</label>
                    <input type="text" name="description" class="form-control form-control-sm"
                           value="<?= htmlspecialchars($editOrg['Description']) ?>">
                </div>
                <div class="row g-2 mb-2">
                    <div class="col-sm-4">
                        <label class="form-label small">Licence type</label>
                        <select name="licence_type" class="form-select form-select-sm">
                            <?php foreach ($LICENCE_TYPES as $lt): ?>
                                <option value="<?= $lt ?>" <?= $editOrg['LicenceType'] === $lt ? 'selected' : '' ?>><?= $lt ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label small">Licence number</label>
                        <input type="text" name="licence_number" class="form-control form-control-sm" maxlength="100"
                               value="<?= htmlspecialchars($editOrg['LicenceNumber']) ?>">
                    </div>
                    <div class="col-sm-4 d-flex align-items-end">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_active" id="edit-is-active" value="1" <?= (int)$editOrg['IsActive'] ? 'checked' : '' ?>>
                            <label class="form-check-label" for="edit-is-active">Active</label>
                        </div>
                    </div>
                </div>
                <button type="submit" class="btn btn-amber-solid btn-sm mt-2">
                    <i class="bi bi-save me-1"></i>Save settings
                </button>
            </form>

            <div class="row g-3">
                <div class="col-md-7">
                    <div class="card-admin p-3 h-100">
                        <h2 class="h6 mb-3"><i class="bi bi-people me-2"></i>Members (<?= count($editMembers) ?>)</h2>
                        <?php if (!$editMembers): ?>
                            <p class="text-muted small mb-0">No members yet.</p>
                        <?php else: ?>
                            <table class="table table-sm align-middle mb-0">
                                <thead>
                                    <tr class="text-muted small">
                                        <th>User</th>
                                        <th>Role</th>
                                        <th>Joined</th>
                                        <th class="text-end"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($editMembers as $m): ?>
                                        <tr>
                                            <td>
                                                <code><?= htmlspecialchars($m['Username']) ?></code>
                                                <small class="text-muted ms-1"><?= htmlspecialchars($m['DisplayName']) ?></small>
                                            </td>
                                            <td>
                                                <form method="POST" class="d-flex gap-1">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                                    <input type="hidden" name="action" value="update_member_role">
                                                    <input type="hidden" name="org_id"  value="<?= (int)$editOrg['Id'] ?>">
                                                    <input type="hidden" name="user_id" value="<?= (int)$m['Id'] ?>">
                                                    <select name="member_role" class="form-select form-select-sm" onchange="this.form.submit()">
                                                        <?php foreach ($MEMBER_ROLES as $mr): ?>
                                                            <option value="<?= $mr ?>" <?= $m['OrgRole'] === $mr ? 'selected' : '' ?>><?= $mr ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </form>
                                            </td>
                                            <td class="text-muted small"><?= htmlspecialchars(substr((string)$m['JoinedAt'], 0, 10)) ?></td>
                                            <td class="text-end">
                                                <form method="POST" class="d-inline" onsubmit="return confirm('Remove <?= htmlspecialchars($m['Username'], ENT_QUOTES) ?> from this organisation?')">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                                    <input type="hidden" name="action"  value="remove_member">
                                                    <input type="hidden" name="org_id"  value="<?= (int)$editOrg['Id'] ?>">
                                                    <input type="hidden" name="user_id" value="<?= (int)$m['Id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-x"></i></button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-md-5">
                    <div class="card-admin p-3 h-100">
                        <h2 class="h6 mb-3"><i class="bi bi-person-plus me-2"></i>Add a member</h2>
                        <?php if (!$candidates): ?>
                            <p class="text-muted small mb-0">Every active user is already a member.</p>
                        <?php else: ?>
                            <form method="POST" class="d-flex gap-2 flex-wrap">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                <input type="hidden" name="action"     value="add_member">
                                <input type="hidden" name="org_id"     value="<?= (int)$editOrg['Id'] ?>">
                                <select name="user_id" class="form-select form-select-sm" style="min-width: 180px;" required>
                                    <option value="">— pick a user —</option>
                                    <?php foreach ($candidates as $u): ?>
                                        <option value="<?= (int)$u['Id'] ?>">
                                            <?= htmlspecialchars($u['Username']) ?>
                                            <?php if ($u['DisplayName']): ?> — <?= htmlspecialchars($u['DisplayName']) ?><?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <select name="member_role" class="form-select form-select-sm" style="width: 8rem;">
                                    <?php foreach ($MEMBER_ROLES as $mr): ?>
                                        <option value="<?= $mr ?>"><?= $mr ?></option>
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
