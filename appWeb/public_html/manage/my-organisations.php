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

/* Resolve which org IDs to show.
   - system-admin / global_admin → every org.
   - otherwise → only orgs where the current user has admin/owner role. */
$ownedOrgIds = $systemAdmin ? null : userIsOrgAdminOf($userId);

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
            'SELECT u.Username, u.DisplayName, u.Role AS SystemRole,
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
            'SELECT LicenceType, LicenceNumber, IsActive, ExpiresAt, Notes
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
        Organisations where you hold an admin or owner role. Read-only for now —
        the member-management + licence-edit endpoints land in the next PR
        (#707 follow-up). System administrators see every organisation here
        because they can manage any of them.
    </p>

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
                    <p class="text-muted small mb-0">No members yet.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-dark mb-0 small">
                            <thead><tr>
                                <th>Username</th><th>Display Name</th>
                                <th>System role</th><th>Org role</th>
                                <th>Joined</th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($orgMembers[$orgId] as $m): ?>
                                <tr>
                                    <td><?= htmlspecialchars((string)$m['Username']) ?></td>
                                    <td><?= htmlspecialchars((string)($m['DisplayName'] ?? '')) ?></td>
                                    <td><code><?= htmlspecialchars((string)($m['SystemRole'] ?? 'user')) ?></code></td>
                                    <td><span class="badge bg-info text-dark"><?= htmlspecialchars((string)$m['OrgRole']) ?></span></td>
                                    <td class="text-muted"><?= htmlspecialchars(substr((string)$m['JoinedAt'], 0, 10)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <?php if (!empty($orgLicences[$orgId])): ?>
                    <h3 class="h6 mt-3 mb-2">Licences</h3>
                    <ul class="small mb-0">
                        <?php foreach ($orgLicences[$orgId] as $l): ?>
                            <li>
                                <code><?= htmlspecialchars((string)$l['LicenceType']) ?></code>
                                <?php if ($l['LicenceNumber']): ?>
                                    — <?= htmlspecialchars((string)$l['LicenceNumber']) ?>
                                <?php endif; ?>
                                <?php if (empty($l['IsActive'])): ?>
                                    <span class="badge bg-secondary ms-1">inactive</span>
                                <?php endif; ?>
                                <?php if ($l['ExpiresAt']): ?>
                                    <span class="text-muted ms-1">(expires <?= htmlspecialchars(substr((string)$l['ExpiresAt'], 0, 10)) ?>)</span>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <p class="text-muted small mt-4">
            <i class="bi bi-info-circle me-1"></i>
            Editing org members + licences from this page is on the
            #707 follow-up. For now, ask a system administrator to make
            changes via /manage/organisations, or wait for the next PR.
        </p>
    <?php endif; ?>
</div>

<?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>
</body>
</html>
