<?php

declare(strict_types=1);

/**
 * iHymns — Admin: User Groups
 *
 * Minimal CRUD over `tblUserGroups` plus a two-pane member picker that
 * writes back to `tblUsers.GroupId`. Gated by `manage_user_groups`.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_mysql.php';

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
$db      = getDbMysqli();

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
                $stmt->bind_param('s', $name);
                $stmt->execute();
                $exists = $stmt->get_result()->fetch_row() !== null;
                $stmt->close();
                if ($exists) { $error = 'A group with that name already exists.'; break; }

                $stmt = $db->prepare(
                    'INSERT INTO tblUserGroups (Name, Description, AccessAlpha, AccessBeta, AccessRc, AccessRtw)
                     VALUES (?, ?, ?, ?, ?, ?)'
                );
                $stmt->bind_param('ssiiii', $name, $desc, $aA, $aB, $aR, $aW);
                $stmt->execute();
                $stmt->close();
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
                $stmt->bind_param('si', $name, $id);
                $stmt->execute();
                $exists = $stmt->get_result()->fetch_row() !== null;
                $stmt->close();
                if ($exists) { $error = 'Another group already uses that name.'; break; }

                $stmt = $db->prepare(
                    'UPDATE tblUserGroups
                        SET Name = ?, Description = ?,
                            AccessAlpha = ?, AccessBeta = ?, AccessRc = ?, AccessRtw = ?
                      WHERE Id = ?'
                );
                $stmt->bind_param('ssiiiii', $name, $desc, $aA, $aB, $aR, $aW, $id);
                $stmt->execute();
                $stmt->close();
                $success = "Group '{$name}' updated.";
                break;
            }

            case 'delete': {
                $id = (int)($_POST['id'] ?? 0);

                $stmt = $db->prepare('SELECT Name FROM tblUserGroups WHERE Id = ?');
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_row();
                $stmt->close();
                $name = (string)($row[0] ?? '');
                if ($name === '') { $error = 'Group not found.'; break; }

                $stmt = $db->prepare('SELECT COUNT(*) FROM tblUsers WHERE GroupId = ?');
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_row();
                $stmt->close();
                $members = (int)($row[0] ?? 0);
                if ($members > 0) {
                    $error = "Cannot delete '{$name}': {$members} user(s) still belong to it. Move them to another group first.";
                    break;
                }

                $stmt = $db->prepare('DELETE FROM tblUserGroups WHERE Id = ?');
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $stmt->close();
                $success = "Group '{$name}' deleted.";
                break;
            }

            case 'add_member': {
                $groupId = (int)($_POST['group_id'] ?? 0);
                $userId  = (int)($_POST['user_id']  ?? 0);
                if ($groupId <= 0 || $userId <= 0) { $error = 'Invalid request.'; break; }

                /* #1868 (epic #1863, rule #43) — SEARCH-SELECT ONLY: the
                   picker below resolves to an id, but that id arrives over
                   POST like any other client-supplied value, so it is
                   untrusted until checked. Free-typing in place-search.js
                   clears the hidden id, so a mismatched/forged id here is
                   the exception rather than the rule — but this endpoint
                   must still verify it names a REAL row before writing it.
                   There is deliberately NO find-or-create fallback: this
                   surface never invents a user (unlike the Tune/Publisher
                   pickers in #1864, which fall back to a create funnel when
                   nothing matches). Without this check, a bad id would
                   silently affect 0 rows in the UPDATE below while still
                   reporting "Member added." — worse than a clear error. */
                $stmt = $db->prepare('SELECT Username FROM tblUsers WHERE Id = ?');
                $stmt->bind_param('i', $userId);
                $stmt->execute();
                $foundUser = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$foundUser) { $error = 'That user could not be found — pick one from the search results.'; break; }

                $stmt = $db->prepare('UPDATE tblUsers SET GroupId = ?, UpdatedAt = CURRENT_TIMESTAMP WHERE Id = ?');
                $stmt->bind_param('ii', $groupId, $userId);
                $stmt->execute();
                $stmt->close();
                $success = 'Added ' . $foundUser['Username'] . ' to the group.';
                break;
            }

            case 'remove_member': {
                $userId = (int)($_POST['user_id'] ?? 0);
                if ($userId <= 0) { $error = 'Invalid request.'; break; }
                $stmt = $db->prepare('UPDATE tblUsers SET GroupId = NULL, UpdatedAt = CURRENT_TIMESTAMP WHERE Id = ?');
                $stmt->bind_param('i', $userId);
                $stmt->execute();
                $stmt->close();
                $success = 'Member removed from group.';
                break;
            }

            default:
                $error = 'Unknown action.';
        }
    } catch (\Throwable $e) {
        error_log('[manage/groups.php] ' . $e->getMessage());
        logActivityError('admin.groups.save', 'group',
            (string)($_POST['id'] ?? ''), $e, [
                'action' => $_POST['action'] ?? null,
            ]);
        $error = $error ?: 'Database error — check server logs for details.';
    }
}

/* ----- GET: fetch groups + members ----- */
$groups = [];
try {
    $stmt = $db->prepare(
        'SELECT g.Id, g.Name, g.Description,
                g.AccessAlpha, g.AccessBeta, g.AccessRc, g.AccessRtw,
                COUNT(u.Id) AS MemberCount
           FROM tblUserGroups g
           LEFT JOIN tblUsers u ON u.GroupId = g.Id
          GROUP BY g.Id
          ORDER BY g.Name ASC'
    );
    $stmt->execute();
    $groups = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} catch (\Throwable $e) {
    error_log('[manage/groups.php] ' . $e->getMessage());
    logActivityError('admin.groups.list', 'group', '', $e);
    $error = $error ?: 'Could not load groups.';
}

/* If ?edit=<id>, pull the editing group + members. #1868 (rule #43) — the
   "add a member" candidate list used to be a `LIMIT 500` SELECT rendered
   into one giant <select>: it doesn't scale past a few hundred users, and
   (being capped) could silently omit a real candidate near the tail of the
   alphabet. Replaced with a live-search picker (below) that reuses the
   EXISTING api2 user_search action, so there is no candidate list to
   precompute here any more. */
$editGroup = null;
$editMembers = [];
$editId = (int)($_GET['edit'] ?? 0);
if ($editId > 0) {
    try {
        $stmt = $db->prepare('SELECT * FROM tblUserGroups WHERE Id = ?');
        $stmt->bind_param('i', $editId);
        $stmt->execute();
        $editGroup = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();

        if ($editGroup) {
            $stmt = $db->prepare(
                'SELECT Id, Username, DisplayName, Role, IsActive
                   FROM tblUsers WHERE GroupId = ? ORDER BY Username ASC'
            );
            $stmt->bind_param('i', $editId);
            $stmt->execute();
            $editMembers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }
    } catch (\Throwable $e) {
        error_log('[manage/groups.php] ' . $e->getMessage());
        logActivityError('admin.groups.edit_load', 'group', (string)$editId, $e);
    }
}

$csrf = csrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Groups — iHymns Admin</title>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-libs.php'; ?>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-favicon.php'; ?>
</head>
<body>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-nav.php'; ?>

    <div class="container-admin py-4">

        <h1 class="h4 mb-3"><i class="bi bi-people-fill me-2"></i>User Groups</h1>
        <p class="text-secondary small mb-4">
            Group users together to control which early-access builds of iHymns they can use —
            alpha, beta, release candidate, and the public release. Each user belongs to one group at a time.
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
                <table class="table table-sm mb-0 align-middle cp-sortable admin-table-responsive">
                    <thead>
                        <tr class="text-muted small">
                            <th data-sort-key="name"        data-sort-type="text">Name</th>
                            <th data-sort-key="description" data-sort-type="text">Description</th>
                            <th class="text-center" title="Alpha" data-sort-key="alpha" data-sort-type="number">α</th>
                            <th class="text-center" title="Beta" data-sort-key="beta" data-sort-type="number">β</th>
                            <th class="text-center" title="Release Candidate" data-sort-key="rc" data-sort-type="number">RC</th>
                            <th class="text-center" title="Release to Web" data-sort-key="rtw" data-sort-type="number">RTW</th>
                            <th class="text-center" data-sort-key="members" data-sort-type="number">Members</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($groups as $g): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($g['Name']) ?></strong></td>
                                <td class="text-muted small"><?= htmlspecialchars(mb_substr((string)$g['Description'], 0, 120)) ?></td>
                                <?php foreach (['AccessAlpha', 'AccessBeta', 'AccessRc', 'AccessRtw'] as $k): ?>
                                    <td class="text-center" data-sort-value="<?= (int)$g[$k] ?>">
                                        <?= (int)$g[$k] ? '<i class="bi bi-check-circle text-success"></i>' : '<i class="bi bi-dash text-muted"></i>' ?>
                                    </td>
                                <?php endforeach; ?>
                                <td class="text-center"><?= (int)$g['MemberCount'] ?></td>
                                <td class="text-end">
                                    <a href="?edit=<?= (int)$g['Id'] ?>" class="btn btn-sm btn-outline-info" title="Edit and manage members"
                                       aria-label="Edit and manage members of <?= htmlspecialchars($g['Name'], ENT_QUOTES) ?>">
                                        <i class="bi bi-pencil" aria-hidden="true"></i>
                                    </a>
                                    <?php if ((int)$g['MemberCount'] === 0): ?>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Delete group <?= htmlspecialchars($g['Name'], ENT_QUOTES) ?>?')">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int)$g['Id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete (empty group)"
                                                    aria-label="Delete group <?= htmlspecialchars($g['Name'], ENT_QUOTES) ?>"><i class="bi bi-trash" aria-hidden="true"></i></button>
                                        </form>
                                    <?php else: ?>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" disabled title="Group has members — move them first"
                                                aria-label="Delete group <?= htmlspecialchars($g['Name'], ENT_QUOTES) ?> — disabled, it has members, move them first"><i class="bi bi-trash" aria-hidden="true"></i></button>
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
                        <label class="form-label small" for="new-group-name">Name</label>
                        <input type="text" name="name" id="new-group-name" class="form-control form-control-sm" maxlength="100" required>
                    </div>
                    <div class="col-sm-8">
                        <label class="form-label small" for="new-group-description">Description</label>
                        <input type="text" name="description" id="new-group-description" class="form-control form-control-sm">
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
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Remove from group"
                                                    aria-label="Remove <?= htmlspecialchars($u['Username'], ENT_QUOTES) ?> from this group">
                                                <i class="bi bi-x" aria-hidden="true"></i>
                                            </button>
                                        </form>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Add a member: live-search picker (#1868, rule #43) — search-select
                     ONLY, no create arm (this surface never invents a user). The
                     picked id is submitted and add_member (above) verifies it names
                     a real tblUsers row before writing it. -->
                <div class="col-md-6">
                    <div class="card-admin p-3 h-100">
                        <h2 class="h6 mb-3"><i class="bi bi-person-plus me-2"></i>Add a member</h2>
                        <form method="POST" class="d-flex gap-2" id="add-member-form">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                            <input type="hidden" name="action"     value="add_member">
                            <input type="hidden" name="group_id"   value="<?= (int)$editGroup['Id'] ?>">
                            <input type="hidden" name="user_id" id="add-member-user-id" value="">
                            <input type="text" id="add-member-user-name" class="form-control form-control-sm"
                                   placeholder="Search by username or display name…" autocomplete="off" required
                                   aria-label="Search for a user to add to this group">
                            <button type="submit" class="btn btn-sm btn-amber-solid" aria-label="Add selected user to this group">
                                <i class="bi bi-plus" aria-hidden="true"></i>
                            </button>
                        </form>
                        <p class="form-text small mt-2 mb-0">
                            Type at least 2 characters and pick a match — the account behind your pick is what's
                            saved, not the typed text.
                        </p>
                    </div>
                </div>
            </div>

        <?php endif; ?>

    </div>

    <?php if ($editGroup): ?>
    <!-- Live user search for the Add-a-member picker (#1868, rule #43). -->
    <script src="/js/modules/place-search.js?v=<?= filemtime(dirname(__DIR__) . '/js/modules/place-search.js') ?>"></script>
    <script>
        (function () {
            if (!window.iHymnsPlaceSearch) return;
            const nameInput = document.getElementById('add-member-user-name');
            const hiddenId  = document.getElementById('add-member-user-id');
            if (!nameInput || !hiddenId) return;

            /* #1868 — pickMode:'value': the pick fills the input + hidden id
               with NO network call of its own; the form's own POST is the
               ONE commit, and add_member (server-side, above) VERIFIES the
               id names a real tblUsers row before writing it. Reuses the
               EXISTING api2 user_search action (rule #22) — this page adds
               no local search handler. That endpoint's own gate is
               hasRole(..., 'editor'); manage_user_groups defaults to
               admin/global_admin (both above editor level), so every
               default-config curator of this page passes it. If a custom
               install ever grants manage_user_groups below editor level,
               the picker degrades to "User lookup unavailable (HTTP 403).
               Your text is saved as typed." — but since this field is
               search-select ONLY (no create-on-submit funnel), the
               submit-guard below still blocks an unresolved pick, and the
               server's own existence check is the final backstop either way. */
            window.iHymnsPlaceSearch.attach(nameInput, {
                hiddenIdInput: hiddenId,
                minChars: 2,
                pickMode: 'value',
                noun: { singular: 'user', plural: 'users' },
                searchUrl: (q) => '/manage/editor/api2?action=user_search&q=' + encodeURIComponent(q) + '&limit=10',
                parseResults: (d) => (d.suggestions || []).map((s) => ({
                    id: s.id,
                    display_name: s.label,
                    hint: s.hint || '',
                })),
            });

            /* Require an actual resolved pick before submit — a free-typed
               name with no hidden id must never submit (search-select only;
               there is no create-on-submit funnel for this field to fall
               back to, unlike the Tune/Publisher pickers in #1864). This is
               a UX guard only — the server-side existence check above is
               the real guarantee. */
            const form = document.getElementById('add-member-form');
            if (form) {
                form.addEventListener('submit', (ev) => {
                    if (!hiddenId.value) {
                        ev.preventDefault();
                        nameInput.setCustomValidity('Pick a user from the search results first.');
                        nameInput.reportValidity();
                    }
                });
                nameInput.addEventListener('input', () => nameInput.setCustomValidity(''));
            }
        })();
    </script>
    <?php endif; ?>

    <!-- Sortable table headers (#644). -->
    <script type="module">
        import { bootSortableTables } from '/js/modules/admin-table-sort.js?v=<?= filemtime(dirname(__DIR__) . '/js/modules/admin-table-sort.js') ?>';
        bootSortableTables();
    </script>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>
</body>
</html>
