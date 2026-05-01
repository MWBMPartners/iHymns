<?php

declare(strict_types=1);

/**
 * iHymns — My Organisations (#707)
 *
 * READ-ONLY view for users who hold an `admin` or `owner` row in
 * `tblOrganisationMembers` for at least one organisation. They see
 * the orgs they manage + each org's member list + each org's
 * licence rows.
 *
 * This file is the foundation laid in the first PR for #707; the
 * member add/remove/role-change + licence add/change/remove POST
 * endpoints are scheduled for a follow-up PR (each needs a
 * row-level org-ownership server-side check that this read-only
 * page doesn't yet exercise).
 *
 * Distinct from `/manage/organisations.php` which is system-admin-
 * only. That page covers EVERY organisation in the system; this
 * page is scoped to the orgs the current user holds an org-admin
 * row on.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_mysql.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'entitlements.php';
/* Shared org helpers (#719 PR 2c) — ORG_MEMBER_ROLES + userCanActOnOrg().
   The local $canActOnOrg closure below remains for the page's session
   shape; the API layer uses userCanActOnOrg() with the bearer-token
   user shape. */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'organisation_validation.php';

if (!isAuthenticated()) {
    header('Location: /manage/login');
    exit;
}
$currentUser = getCurrentUser();
if (!$currentUser) {
    http_response_code(403);
    exit('Access denied.');
}

/* Page-level gate (#707):
   1. The system-level entitlement check confirms the role is allowed
      to hold an org-admin position at all (kept open by default for
      every signed-in user — the actual restriction is data-driven).
   2. The data-driven check looks at tblOrganisationMembers to find
      the orgs this specific user holds admin/owner role on.
   3. system-admin / global_admin shortcut to "see every org" because
      they can manage any org via /manage/organisations anyway. */
$systemAdmin = in_array(($currentUser['role'] ?? ''), ['admin', 'global_admin'], true);
$userId      = (int)($currentUser['id'] ?? $currentUser['Id'] ?? 0);

if (!$systemAdmin) {
    if (!userHasEntitlement('manage_own_organisation', $currentUser['role'] ?? null)) {
        http_response_code(403);
        exit('Access denied. The manage_own_organisation entitlement is required.');
    }
    if (!userHasOwnOrganisation($userId)) {
        http_response_code(403);
        echo '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:2rem;">';
        echo '<h1>403 — Not an organisation admin</h1>';
        echo '<p>You don\'t hold an admin or owner role on any organisation. ';
        echo 'A system administrator can grant you that role from /manage/organisations.</p>';
        echo '<p><a href="/manage/">Back to Dashboard</a></p>';
        echo '</body></html>';
        exit;
    }
}

$activePage = 'my-organisations';

$db = getDbMysqli();
$error   = '';
$success = '';

/* Member-role allowlist now from the shared include (#719 PR 2c).
   Licence-type list stays page-local — this surface accepts a
   different set than organisations.php (per-row vs primary-type). */
$MEMBER_ROLES  = ORG_MEMBER_ROLES;
$LICENCE_TYPES = ['ccli', 'mrl', 'ihymns_basic', 'ihymns_pro', 'custom'];

/* Resolve which org IDs to show.
   - system-admin / global_admin → every org.
   - otherwise → only orgs where the current user has admin/owner role. */
$ownedOrgIds = $systemAdmin ? null : userIsOrgAdminOf($userId);

/* Row-level org-ownership gate for every action. system-admin /
   global_admin can act on any org; everyone else can only act on
   orgs they hold admin/owner role on. Returns true if allowed,
   false otherwise. (#707) */
$canActOnOrg = function (int $orgId) use ($systemAdmin, $userId): bool {
    if ($orgId <= 0) return false;
    if ($systemAdmin) return true;
    return in_array($orgId, userIsOrgAdminOf($userId), true);
};

/* ====================================================================
 * POST handlers — six edit endpoints (#707)
 *
 * Each handler:
 *   1. Validates CSRF.
 *   2. Calls $canActOnOrg($orgId) for the row-level gate. A forged POST
 *      against an org the current user doesn't admin returns 403 even
 *      if CSRF is valid.
 *   3. Performs the action (INSERT / UPDATE / DELETE).
 *   4. Writes an Activity Log row under org_admin.<verb>.
 *   5. Surfaces success / error banner.
 * ==================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrf((string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo 'Invalid CSRF token';
        exit;
    }
    $action = (string)($_POST['action'] ?? '');
    $orgId  = (int)($_POST['org_id'] ?? 0);

    if (!$canActOnOrg($orgId)) {
        http_response_code(403);
        echo '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:2rem;">';
        echo '<h1>403 — Not authorised on this organisation</h1>';
        echo '<p>You don\'t hold an admin or owner role on the target organisation. ';
        echo 'This action was rejected at the server-side row-level check.</p>';
        echo '<p><a href="/manage/my-organisations">Back to My Organisations</a></p>';
        echo '</body></html>';
        exit;
    }

    try {
        switch ($action) {
            case 'member_add': {
                /* Add a user to the org by username or email. The form
                   posts a free-text identifier; we resolve it to a
                   tblUsers.Id so a curator can paste either form. */
                $identifier = trim((string)($_POST['user_identifier'] ?? ''));
                $role       = (string)($_POST['member_role'] ?? 'member');
                if ($identifier === '') { $error = 'Username or email is required.'; break; }
                if (!in_array($role, $MEMBER_ROLES, true)) { $error = 'Unknown member role.'; break; }

                $stmt = $db->prepare(
                    'SELECT Id FROM tblUsers WHERE Username = ? OR Email = ? LIMIT 1'
                );
                $stmt->bind_param('ss', $identifier, $identifier);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$row) { $error = "User '{$identifier}' not found."; break; }
                $targetUserId = (int)$row['Id'];

                $stmt = $db->prepare(
                    'INSERT INTO tblOrganisationMembers (UserId, OrgId, Role)
                     VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE Role = VALUES(Role)'
                );
                $stmt->bind_param('iis', $targetUserId, $orgId, $role);
                $stmt->execute();
                $stmt->close();
                logActivity('org_admin.member_add', 'organisation', (string)$orgId, [
                    'user_id'    => $targetUserId,
                    'identifier' => $identifier,
                    'role'       => $role,
                ]);
                $success = "Added {$identifier} as {$role}.";
                break;
            }

            case 'member_role_change': {
                $targetUserId = (int)($_POST['user_id'] ?? 0);
                $role         = (string)($_POST['member_role'] ?? 'member');
                if ($targetUserId <= 0) { $error = 'Invalid user.'; break; }
                if (!in_array($role, $MEMBER_ROLES, true)) { $error = 'Unknown member role.'; break; }

                $stmt = $db->prepare('UPDATE tblOrganisationMembers SET Role = ? WHERE OrgId = ? AND UserId = ?');
                $stmt->bind_param('sii', $role, $orgId, $targetUserId);
                $stmt->execute();
                $stmt->close();
                logActivity('org_admin.member_role_change', 'organisation', (string)$orgId, [
                    'user_id' => $targetUserId,
                    'role'    => $role,
                ]);
                $success = 'Member role updated.';
                break;
            }

            case 'member_remove': {
                $targetUserId = (int)($_POST['user_id'] ?? 0);
                if ($targetUserId <= 0) { $error = 'Invalid user.'; break; }
                /* Self-removal guard — an admin must never lock themselves
                   out of the org by accident. They have to ask a sibling
                   admin / owner / system admin to remove them. */
                if ($targetUserId === $userId && !$systemAdmin) {
                    $error = 'You cannot remove yourself from an organisation. Ask a co-admin or system admin to remove you.';
                    break;
                }
                $stmt = $db->prepare('DELETE FROM tblOrganisationMembers WHERE OrgId = ? AND UserId = ?');
                $stmt->bind_param('ii', $orgId, $targetUserId);
                $stmt->execute();
                $stmt->close();
                logActivity('org_admin.member_remove', 'organisation', (string)$orgId, [
                    'user_id' => $targetUserId,
                ]);
                $success = 'Member removed.';
                break;
            }

            case 'licence_add': {
                $licenceType   = (string)($_POST['licence_type']    ?? '');
                $licenceNumber = trim((string)($_POST['licence_number'] ?? ''));
                $expiresAt     = trim((string)($_POST['expires_at']  ?? '')) ?: null;
                $isActive      = !empty($_POST['is_active']) ? 1 : 0;
                $notes         = trim((string)($_POST['notes']       ?? '')) ?: null;
                if (!in_array($licenceType, $LICENCE_TYPES, true)) { $error = 'Unknown licence type.'; break; }

                $stmt = $db->prepare(
                    'INSERT INTO tblOrganisationLicences
                        (OrganisationId, LicenceType, LicenceNumber, IsActive, ExpiresAt, Notes)
                     VALUES (?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE
                        LicenceNumber = VALUES(LicenceNumber),
                        IsActive      = VALUES(IsActive),
                        ExpiresAt     = VALUES(ExpiresAt),
                        Notes         = VALUES(Notes)'
                );
                $stmt->bind_param('ississ',
                    $orgId, $licenceType, $licenceNumber, $isActive, $expiresAt, $notes);
                $stmt->execute();
                $stmt->close();
                logActivity('org_admin.licence_add', 'organisation', (string)$orgId, [
                    'licence_type'   => $licenceType,
                    'licence_number' => $licenceNumber,
                    'is_active'      => (bool)$isActive,
                ]);
                $success = "Licence '{$licenceType}' saved.";
                break;
            }

            case 'licence_change': {
                $licenceId     = (int)($_POST['licence_id'] ?? 0);
                $licenceNumber = trim((string)($_POST['licence_number'] ?? ''));
                $expiresAt     = trim((string)($_POST['expires_at']  ?? '')) ?: null;
                $isActive      = !empty($_POST['is_active']) ? 1 : 0;
                $notes         = trim((string)($_POST['notes']       ?? '')) ?: null;
                if ($licenceId <= 0) { $error = 'Invalid licence row.'; break; }

                /* Belt-and-braces: confirm the licence row actually
                   belongs to the org we already authorised on. Stops a
                   crafted POST that mixes a licence_id from one org with
                   an org_id the user CAN admin. */
                $stmt = $db->prepare(
                    'SELECT 1 FROM tblOrganisationLicences WHERE Id = ? AND OrganisationId = ?'
                );
                $stmt->bind_param('ii', $licenceId, $orgId);
                $stmt->execute();
                $owns = $stmt->get_result()->fetch_row() !== null;
                $stmt->close();
                if (!$owns) { $error = 'Licence row does not belong to that organisation.'; break; }

                $stmt = $db->prepare(
                    'UPDATE tblOrganisationLicences
                        SET LicenceNumber = ?, IsActive = ?, ExpiresAt = ?, Notes = ?
                      WHERE Id = ?'
                );
                $stmt->bind_param('sissi', $licenceNumber, $isActive, $expiresAt, $notes, $licenceId);
                $stmt->execute();
                $stmt->close();
                logActivity('org_admin.licence_change', 'organisation', (string)$orgId, [
                    'licence_id'   => $licenceId,
                    'licence_number' => $licenceNumber,
                    'is_active'    => (bool)$isActive,
                ]);
                $success = 'Licence updated.';
                break;
            }

            case 'licence_remove': {
                $licenceId = (int)($_POST['licence_id'] ?? 0);
                if ($licenceId <= 0) { $error = 'Invalid licence row.'; break; }

                /* Same belt-and-braces check as licence_change. */
                $stmt = $db->prepare(
                    'SELECT 1 FROM tblOrganisationLicences WHERE Id = ? AND OrganisationId = ?'
                );
                $stmt->bind_param('ii', $licenceId, $orgId);
                $stmt->execute();
                $owns = $stmt->get_result()->fetch_row() !== null;
                $stmt->close();
                if (!$owns) { $error = 'Licence row does not belong to that organisation.'; break; }

                $stmt = $db->prepare('DELETE FROM tblOrganisationLicences WHERE Id = ?');
                $stmt->bind_param('i', $licenceId);
                $stmt->execute();
                $stmt->close();
                logActivity('org_admin.licence_remove', 'organisation', (string)$orgId, [
                    'licence_id' => $licenceId,
                ]);
                $success = 'Licence removed.';
                break;
            }

            default:
                $error = 'Unknown action.';
        }
    } catch (\Throwable $e) {
        error_log('[manage/my-organisations.php] ' . $e->getMessage());
        if (function_exists('logActivityError')) {
            logActivityError('admin.my_organisations.save', 'organisation',
                (string)$orgId, $e, ['action' => $action]);
        }
        $where = $e->getFile() ? (' (' . basename($e->getFile()) . ':' . $e->getLine() . ')') : '';
        $error = $error ?: 'Database error: ' . $e->getMessage() . $where;
    }
}

try {
    if ($systemAdmin) {
        $stmt = $db->prepare(
            'SELECT Id, Name, Slug, Description, LicenceType, LicenceNumber, IsActive
               FROM tblOrganisations
              ORDER BY Name ASC'
        );
        $stmt->execute();
    } else {
        if (empty($ownedOrgIds)) {
            $orgs = [];
            goto render;
        }
        $placeholders = implode(',', array_fill(0, count($ownedOrgIds), '?'));
        $stmt = $db->prepare(
            "SELECT Id, Name, Slug, Description, LicenceType, LicenceNumber, IsActive
               FROM tblOrganisations
              WHERE Id IN ({$placeholders})
              ORDER BY Name ASC"
        );
        $types  = str_repeat('i', count($ownedOrgIds));
        $stmt->bind_param($types, ...$ownedOrgIds);
        $stmt->execute();
    }
    $orgs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} catch (\Throwable $e) {
    error_log('[manage/my-organisations.php] orgs load failed: ' . $e->getMessage());
    if (function_exists('logActivityError')) {
        logActivityError('admin.my_organisations.list', 'organisation', '', $e);
    }
    $orgs = [];
}

/* Per-org members + licences. Two extra queries per org — fine for
   the scale we expect (one user is admin of maybe 1-3 orgs). */
$orgMembers  = [];
$orgLicences = [];
foreach ($orgs as $o) {
    $orgId = (int)$o['Id'];
    try {
        $stmt = $db->prepare(
            'SELECT u.Id AS UserId, u.Username, u.DisplayName, u.Role AS SystemRole,
                    m.Role AS OrgRole, m.JoinedAt
               FROM tblOrganisationMembers m
               JOIN tblUsers u ON u.Id = m.UserId
              WHERE m.OrgId = ?
              ORDER BY m.JoinedAt DESC'
        );
        $stmt->bind_param('i', $orgId);
        $stmt->execute();
        $orgMembers[$orgId] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } catch (\Throwable $_e) {
        $orgMembers[$orgId] = [];
    }

    try {
        $stmt = $db->prepare(
            'SELECT Id, LicenceType, LicenceNumber, IsActive, ExpiresAt, Notes
               FROM tblOrganisationLicences
              WHERE OrganisationId = ?
              ORDER BY LicenceType ASC'
        );
        $stmt->bind_param('i', $orgId);
        $stmt->execute();
        $orgLicences[$orgId] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } catch (\Throwable $_e) {
        /* tblOrganisationLicences may not exist on a pre-migration deployment.
           Fall through to no licences shown. */
        $orgLicences[$orgId] = [];
    }
}

render:
$csrf = csrfToken();
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Organisations — iHymns Admin</title>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-libs.php'; ?>
    <link rel="stylesheet" href="/css/app.css?v=<?= filemtime(dirname(__DIR__) . "/css/app.css") ?>">
    <link rel="stylesheet" href="/css/admin.css?v=<?= filemtime(dirname(__DIR__) . "/css/admin.css") ?>">
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-favicon.php'; ?>
</head>
<body>

<?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-nav.php'; ?>

<div class="container-admin py-4">
    <h1 class="h4 mb-3">
        <i class="bi bi-building me-2"></i>My Organisations
    </h1>
    <p class="text-muted small">
        Organisations where you hold an admin or owner role. You can add or remove members, change their org-role, and edit licence rows here. System administrators see every organisation because they can manage any of them.
    </p>

    <?php if ($success): ?>
        <div class="alert alert-success py-2 small"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger py-2 small"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if (empty($orgs)): ?>
        <div class="alert alert-info">
            You're not currently an admin or owner of any organisation.
            A system administrator can grant you that role from
            <a href="/manage/organisations">Manage &rsaquo; Organisations</a>.
        </div>
    <?php else: ?>
        <?php foreach ($orgs as $o): $orgId = (int)$o['Id']; ?>
            <div class="card-admin p-3 mb-3">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <div>
                        <h2 class="h5 mb-0">
                            <?= htmlspecialchars((string)$o['Name']) ?>
                            <?php if (empty($o['IsActive'])): ?>
                                <span class="badge bg-secondary ms-1">inactive</span>
                            <?php endif; ?>
                        </h2>
                        <div class="text-muted small">
                            <code><?= htmlspecialchars((string)$o['Slug']) ?></code>
                            <?php if ($o['LicenceType']): ?>
                                · primary licence:
                                <code><?= htmlspecialchars((string)$o['LicenceType']) ?></code>
                                <?php if ($o['LicenceNumber']): ?>
                                    (<?= htmlspecialchars((string)$o['LicenceNumber']) ?>)
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if ($systemAdmin): ?>
                        <a href="/manage/organisations?edit=<?= $orgId ?>"
                           class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-pencil me-1"></i>System edit
                        </a>
                    <?php endif; ?>
                </div>

                <?php if ($o['Description']): ?>
                    <p class="small mb-2"><?= htmlspecialchars((string)$o['Description']) ?></p>
                <?php endif; ?>

                <h3 class="h6 mt-3 mb-2">Members (<?= count($orgMembers[$orgId] ?? []) ?>)</h3>
                <?php if (empty($orgMembers[$orgId])): ?>
                    <p class="text-muted small">No members yet — use the Add member form below.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-dark mb-2 small align-middle">
                            <thead><tr>
                                <th>Username</th><th>Display Name</th>
                                <th>System role</th><th>Org role</th>
                                <th class="text-end">Actions</th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($orgMembers[$orgId] as $m): ?>
                                <tr>
                                    <td><?= htmlspecialchars((string)$m['Username']) ?></td>
                                    <td><?= htmlspecialchars((string)($m['DisplayName'] ?? '')) ?></td>
                                    <td><code><?= htmlspecialchars((string)($m['SystemRole'] ?? 'user')) ?></code></td>
                                    <td>
                                        <form method="POST" class="d-inline-flex align-items-center gap-1">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                            <input type="hidden" name="action" value="member_role_change">
                                            <input type="hidden" name="org_id"  value="<?= $orgId ?>">
                                            <input type="hidden" name="user_id" value="<?= (int)($m['UserId'] ?? 0) ?>">
                                            <select name="member_role" class="form-select form-select-sm py-0" style="width:auto;">
                                                <?php foreach ($MEMBER_ROLES as $mr): ?>
                                                    <option value="<?= $mr ?>" <?= $m['OrgRole'] === $mr ? 'selected' : '' ?>><?= $mr ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit" class="btn btn-sm btn-outline-info py-0 px-2" title="Change role">
                                                <i class="bi bi-check2"></i>
                                            </button>
                                        </form>
                                    </td>
                                    <td class="text-end">
                                        <form method="POST" class="d-inline"
                                              onsubmit="return confirm('Remove <?= htmlspecialchars($m['Username'], ENT_QUOTES) ?> from this organisation?');">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                            <input type="hidden" name="action" value="member_remove">
                                            <input type="hidden" name="org_id"  value="<?= $orgId ?>">
                                            <input type="hidden" name="user_id" value="<?= (int)($m['UserId'] ?? 0) ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2" title="Remove from organisation">
                                                <i class="bi bi-x"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <!-- Add member form -->
                <form method="POST" class="row g-2 align-items-end small mb-3">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action" value="member_add">
                    <input type="hidden" name="org_id" value="<?= $orgId ?>">
                    <div class="col-md-5">
                        <label class="form-label small mb-0">Add member (username or email)</label>
                        <input type="text" name="user_identifier" class="form-control form-control-sm"
                               placeholder="username or email" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small mb-0">Role</label>
                        <select name="member_role" class="form-select form-select-sm">
                            <?php foreach ($MEMBER_ROLES as $mr): ?>
                                <option value="<?= $mr ?>" <?= $mr === 'member' ? 'selected' : '' ?>><?= $mr ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-auto">
                        <button type="submit" class="btn btn-sm btn-amber-solid">
                            <i class="bi bi-plus-circle me-1"></i>Add member
                        </button>
                    </div>
                </form>

                <h3 class="h6 mt-3 mb-2">Licences</h3>
                <?php if (empty($orgLicences[$orgId])): ?>
                    <p class="text-muted small">No licences attached. Use the Add licence form below.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-dark mb-2 small align-middle">
                            <thead><tr>
                                <th>Type</th><th>Number</th><th>Expires</th><th>Active</th><th>Notes</th>
                                <th class="text-end">Actions</th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($orgLicences[$orgId] as $l): ?>
                                <tr>
                                    <td><code><?= htmlspecialchars((string)$l['LicenceType']) ?></code></td>
                                    <td>
                                        <form method="POST" class="d-inline-flex align-items-center gap-1">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                            <input type="hidden" name="action" value="licence_change">
                                            <input type="hidden" name="org_id"     value="<?= $orgId ?>">
                                            <input type="hidden" name="licence_id" value="<?= (int)($l['Id'] ?? 0) ?>">
                                            <input type="text" name="licence_number"
                                                   class="form-control form-control-sm py-0"
                                                   value="<?= htmlspecialchars((string)$l['LicenceNumber']) ?>"
                                                   style="width: 10rem;">
                                            <input type="date" name="expires_at"
                                                   class="form-control form-control-sm py-0"
                                                   value="<?= htmlspecialchars(substr((string)($l['ExpiresAt'] ?? ''), 0, 10)) ?>"
                                                   style="width: 9rem;">
                                            <div class="form-check form-check-inline mb-0">
                                                <input class="form-check-input" type="checkbox" name="is_active" value="1"
                                                       <?= !empty($l['IsActive']) ? 'checked' : '' ?>>
                                                <label class="form-check-label small">active</label>
                                            </div>
                                            <input type="text" name="notes"
                                                   class="form-control form-control-sm py-0"
                                                   value="<?= htmlspecialchars((string)($l['Notes'] ?? '')) ?>"
                                                   placeholder="notes" style="width: 11rem;">
                                            <button type="submit" class="btn btn-sm btn-outline-info py-0 px-2" title="Save">
                                                <i class="bi bi-check2"></i>
                                            </button>
                                        </form>
                                    </td>
                                    <td colspan="3"></td>
                                    <td class="text-end">
                                        <form method="POST" class="d-inline"
                                              onsubmit="return confirm('Remove the <?= htmlspecialchars($l['LicenceType'], ENT_QUOTES) ?> licence row?');">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                            <input type="hidden" name="action" value="licence_remove">
                                            <input type="hidden" name="org_id"     value="<?= $orgId ?>">
                                            <input type="hidden" name="licence_id" value="<?= (int)($l['Id'] ?? 0) ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2" title="Remove licence">
                                                <i class="bi bi-x"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <!-- Add licence form -->
                <form method="POST" class="row g-2 align-items-end small">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action" value="licence_add">
                    <input type="hidden" name="org_id" value="<?= $orgId ?>">
                    <div class="col-md-2">
                        <label class="form-label small mb-0">Type</label>
                        <select name="licence_type" class="form-select form-select-sm" required>
                            <option value="">— pick —</option>
                            <?php foreach ($LICENCE_TYPES as $lt): ?>
                                <option value="<?= $lt ?>"><?= $lt ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small mb-0">Licence number</label>
                        <input type="text" name="licence_number" class="form-control form-control-sm"
                               placeholder="e.g. CCLI 1234567">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small mb-0">Expires</label>
                        <input type="date" name="expires_at" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-1 form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="is_active" value="1" checked>
                        <label class="form-check-label small">active</label>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small mb-0">Notes</label>
                        <input type="text" name="notes" class="form-control form-control-sm" placeholder="optional">
                    </div>
                    <div class="col-md-auto">
                        <button type="submit" class="btn btn-sm btn-amber-solid">
                            <i class="bi bi-plus-circle me-1"></i>Add licence
                        </button>
                    </div>
                </form>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>
</body>
</html>
