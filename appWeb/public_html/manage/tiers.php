<?php

declare(strict_types=1);

/**
 * iHymns — Admin: Access Tiers
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * Manage the `tblAccessTiers` catalogue — the tier catalogue controls which
 * regular users can view copyrighted lyrics, play audio, download MIDI,
 * download sheet-music PDFs, and save songs offline. Each user carries an
 * `AccessTier` name on `tblUsers`; the client-side checks use
 * ?action=tier_check in api.php.
 *
 * Defaults seeded in schema.sql: public / free / ccli / premium / pro.
 *
 * Gated by the `manage_access_tiers` entitlement.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_mysql.php';
/* Shared tier-validation include (#719 PR 2b) — exports the TIER_CAPS
   const + validateTierName() / validateTierLevel(). Same helpers used
   by the new admin_tier_* API endpoints in /api.php so a tweak to the
   capability set or the name grammar lands on both surfaces. */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'access_tier_validation.php';

if (!isAuthenticated()) {
    header('Location: /manage/login');
    exit;
}
$currentUser = getCurrentUser();
if (!$currentUser || !userHasEntitlement('manage_access_tiers', $currentUser['role'] ?? null)) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><body><h1>403 — manage_access_tiers required</h1></body></html>';
    exit;
}
$activePage = 'tiers';

$error   = '';
$success = '';
$db      = getDbMysqli();

/* TIER_CAPS const + validateTierName() / validateTierLevel() now
   live in access_tier_validation.php (#719 PR 2b). Closure kept as a
   thin wrapper so the existing call sites below continue to work. */
$validName = fn(string $n): ?string => validateTierName($n);

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
                /* #639 — preserve the admin's casing on disk. The
                   reserved 5 (public/free/ccli/premium/pro) stay
                   lowercase by convention; custom tier names like
                   'MWBMInsiders' or 'mwbm-insiders' keep their
                   original form. */
                $name        = trim((string)($_POST['name']                    ?? ''));
                $displayName = trim((string)($_POST['display_name']            ?? ''));
                $level       = (int)($_POST['level']                           ?? 0);
                $description = trim((string)($_POST['description']             ?? ''));

                if ($e = $validName($name))       { $error = $e; break; }
                if ($displayName === '')          { $error = 'Display name is required.'; break; }
                if ($level < 0 || $level > 1000)  { $error = 'Level must be between 0 and 1000.'; break; }

                $stmt = $db->prepare('SELECT Id FROM tblAccessTiers WHERE Name = ?');
                $stmt->bind_param('s', $name);
                $stmt->execute();
                $exists = $stmt->get_result()->fetch_row() !== null;
                $stmt->close();
                if ($exists) { $error = 'A tier with that name already exists.'; break; }

                $caps = [];
                foreach (array_keys(TIER_CAPS) as $col) {
                    $caps[$col] = !empty($_POST['cap_' . $col]) ? 1 : 0;
                }

                $cols = array_merge(['Name','DisplayName','Level','Description'], array_keys(TIER_CAPS));
                $placeholders = implode(', ', array_fill(0, count($cols), '?'));
                $sql = 'INSERT INTO tblAccessTiers (' . implode(',', $cols) . ') VALUES (' . $placeholders . ')';
                /* Type string: Name(s) DisplayName(s) Level(i) Description(s) +
                   each TIER_CAPS column as int. Built dynamically so a new
                   capability column auto-extends the bind without code change. */
                $types  = 'ssis' . str_repeat('i', count(TIER_CAPS));
                $values = array_merge([$name, $displayName, $level, $description], array_values($caps));
                $stmt = $db->prepare($sql);
                $stmt->bind_param($types, ...$values);
                $stmt->execute();
                $stmt->close();
                $success = "Tier '{$name}' created.";
                break;
            }

            case 'update': {
                $id          = (int)($_POST['id']                              ?? 0);
                $displayName = trim((string)($_POST['display_name']            ?? ''));
                $level       = (int)($_POST['level']                           ?? 0);
                $description = trim((string)($_POST['description']             ?? ''));

                if ($id <= 0)                    { $error = 'Tier id missing.'; break; }
                if ($displayName === '')         { $error = 'Display name is required.'; break; }
                if ($level < 0 || $level > 1000) { $error = 'Level must be between 0 and 1000.'; break; }

                $caps = [];
                foreach (array_keys(TIER_CAPS) as $col) {
                    $caps[$col] = !empty($_POST['cap_' . $col]) ? 1 : 0;
                }

                $sets = ['DisplayName = ?', 'Level = ?', 'Description = ?'];
                $args = [$displayName, $level, $description];
                foreach ($caps as $col => $val) {
                    $sets[] = "$col = ?";
                    $args[] = $val;
                }
                $args[] = $id;

                /* Type string: DisplayName(s), Level(i), Description(s),
                   each TIER_CAPS column as int, and finally Id(i). */
                $types = 'sis' . str_repeat('i', count(TIER_CAPS)) . 'i';
                $stmt  = $db->prepare(
                    'UPDATE tblAccessTiers SET ' . implode(', ', $sets) . ' WHERE Id = ?'
                );
                $stmt->bind_param($types, ...$args);
                $stmt->execute();
                $stmt->close();
                $success = 'Tier updated.';
                break;
            }

            case 'delete': {
                $id = (int)($_POST['id'] ?? 0);
                $stmt = $db->prepare('SELECT Name FROM tblAccessTiers WHERE Id = ?');
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_row();
                $stmt->close();
                $name = (string)($row[0] ?? '');
                if ($name === '') { $error = 'Tier not found.'; break; }

                $stmt = $db->prepare('SELECT COUNT(*) FROM tblUsers WHERE AccessTier = ?');
                $stmt->bind_param('s', $name);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_row();
                $stmt->close();
                $inUse = (int)($row[0] ?? 0);
                if ($inUse > 0) {
                    $error = "Cannot delete '{$name}': {$inUse} user(s) are currently on this tier. Reassign them first.";
                    break;
                }

                $stmt = $db->prepare('DELETE FROM tblAccessTiers WHERE Id = ?');
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $stmt->close();
                $success = "Tier '{$name}' deleted.";
                break;
            }

            default:
                $error = 'Unknown action.';
        }
    } catch (\Throwable $e) {
        error_log('[manage/tiers.php] ' . $e->getMessage());
        logActivityError('admin.tiers.save', 'access_tier',
            (string)($_POST['id'] ?? ''), $e, [
                'action' => $_POST['action'] ?? null,
            ]);
        $where = $e->getFile() ? (' (' . basename($e->getFile()) . ':' . $e->getLine() . ')') : '';
        $error = $error ?: 'Database error: ' . $e->getMessage() . $where;
    }
}

/* ----- GET: tiers + per-tier user counts ----- */
$tiers = [];
try {
    /* $capsCols is built from TIER_CAPS keys (a file-scope const), so the
       interpolated identifier list cannot be user-influenced. SQL identifiers
       can't be parameterised — this is the correct pattern for trusted
       internal-source identifier lists. */
    $capsCols = implode(', ', array_keys(TIER_CAPS));
    $stmt = $db->prepare(
        "SELECT t.Id, t.Name, t.DisplayName, t.Level, t.Description, $capsCols,
                (SELECT COUNT(*) FROM tblUsers u WHERE u.AccessTier = t.Name) AS UserCount
           FROM tblAccessTiers t
          ORDER BY t.Level ASC, t.Name ASC"
    );
    $stmt->execute();
    $tiers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} catch (\Throwable $e) {
    error_log('[manage/tiers.php] ' . $e->getMessage());
    logActivityError('admin.tiers.list', 'access_tier', '', $e);
    $where = $e->getFile() ? (' (' . basename($e->getFile()) . ':' . $e->getLine() . ')') : '';
    $error = $error ?: 'Could not load tiers: ' . $e->getMessage() . $where;
}

$csrf = csrfToken();

/* Total column count for the tier matrix table — used for colspans on
   the description row and empty-state row. Static columns:
     Name, Display, Level, ...TIER_CAPS..., Users, Actions */
$tierTableCols = 3 + count(TIER_CAPS) + 2;
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Access Tiers — iHymns Admin</title>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-libs.php'; ?>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-favicon.php'; ?>
</head>
<body>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-nav.php'; ?>

    <div class="container-admin py-4">

        <h1 class="h4 mb-3"><i class="bi bi-stars me-2"></i>Access Tiers</h1>
        <p class="text-secondary small mb-4">
            Each regular user carries an access tier (<code>tblUsers.AccessTier</code>) that controls
            whether they see copyrighted lyrics, play audio, or download MIDI / sheet music / offline
            content. Higher <em>Level</em> values are treated as more privileged.
            Assign tiers per user from <a href="/manage/users" class="text-info">User Management</a>.
        </p>

        <?php if ($success): ?>
            <div class="alert alert-success py-2"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <!-- Tier matrix -->
        <div class="card-admin p-3 mb-4">
            <h2 class="h6 mb-3">All tiers</h2>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0 cp-sortable" data-default-sort-key="level" data-default-sort-dir="asc">
                    <thead>
                        <tr class="text-muted small">
                            <th data-sort-key="name"    data-sort-type="text">Name</th>
                            <th data-sort-key="display" data-sort-type="text">Display</th>
                            <th class="text-center" data-sort-key="level" data-sort-type="number">Level</th>
                            <?php foreach (TIER_CAPS as $col => [$lbl, $hint]): ?>
                                <th class="text-center" title="<?= htmlspecialchars($hint) ?>"><?= htmlspecialchars($lbl) ?></th>
                            <?php endforeach; ?>
                            <th class="text-center" data-sort-key="users" data-sort-type="number">Users</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tiers as $t): ?>
                            <tr>
                                <td><code><?= htmlspecialchars($t['Name']) ?></code></td>
                                <td><?= htmlspecialchars($t['DisplayName']) ?></td>
                                <td class="text-center"><?= (int)$t['Level'] ?></td>
                                <?php foreach (array_keys(TIER_CAPS) as $col): ?>
                                    <td class="text-center">
                                        <?= (int)$t[$col]
                                            ? '<i class="bi bi-check-circle text-success"></i>'
                                            : '<i class="bi bi-dash text-muted"></i>' ?>
                                    </td>
                                <?php endforeach; ?>
                                <td class="text-center"><?= (int)$t['UserCount'] ?></td>
                                <td class="text-end">
                                    <button type="button" class="btn btn-sm btn-outline-info"
                                            title="Edit tier"
                                            aria-label="Edit tier <?= htmlspecialchars($t['Name'], ENT_QUOTES) ?>"
                                            onclick='openEditTier(<?= json_encode($t, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>)'>
                                        <i class="bi bi-pencil" aria-hidden="true"></i>
                                    </button>
                                    <?php if ((int)$t['UserCount'] === 0): ?>
                                        <form method="POST" class="d-inline"
                                              onsubmit="return confirm('Delete tier <?= htmlspecialchars($t['Name'], ENT_QUOTES) ?>?')">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int)$t['Id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger"
                                                    title="Delete tier"
                                                    aria-label="Delete tier <?= htmlspecialchars($t['Name'], ENT_QUOTES) ?>">
                                                <i class="bi bi-trash" aria-hidden="true"></i>
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" disabled
                                                title="In use — reassign users first"
                                                aria-label="Delete tier <?= htmlspecialchars($t['Name'], ENT_QUOTES) ?> (disabled: users still on this tier)">
                                            <i class="bi bi-trash" aria-hidden="true"></i>
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php if (!empty($t['Description'])): ?>
                                <tr><td colspan="<?= $tierTableCols ?>" class="small text-muted pt-0">
                                    <?= htmlspecialchars($t['Description']) ?>
                                </td></tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <?php if (!$tiers): ?>
                            <tr><td colspan="<?= $tierTableCols ?>" class="text-muted text-center py-4">
                                No tiers defined. Run the DB installer or add one below.
                            </td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Create -->
        <form method="POST" class="card-admin p-3 mb-4">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="create">
            <h2 class="h6 mb-3"><i class="bi bi-plus-circle me-2"></i>Add a tier</h2>
            <div class="row g-2 mb-2">
                <div class="col-sm-3">
                    <label class="form-label small">Name (machine)</label>
                    <input type="text" name="name" class="form-control form-control-sm" maxlength="30" required
                           placeholder="e.g. premium_plus, mwbm-insiders" pattern="[A-Za-z0-9_\-]+"
                           title="Letters, digits, hyphen or underscore">
                </div>
                <div class="col-sm-3">
                    <label class="form-label small">Display name</label>
                    <input type="text" name="display_name" class="form-control form-control-sm" maxlength="50" required
                           placeholder="e.g. Premium Plus">
                </div>
                <div class="col-sm-2">
                    <label class="form-label small">Level</label>
                    <input type="number" name="level" class="form-control form-control-sm" min="0" max="1000" value="50">
                </div>
                <div class="col-sm-4">
                    <label class="form-label small">Description</label>
                    <input type="text" name="description" class="form-control form-control-sm"
                           placeholder="What does this tier unlock?">
                </div>
            </div>
            <div class="d-flex flex-wrap gap-3 mt-2">
                <?php foreach (TIER_CAPS as $col => [$lbl, $hint]): ?>
                    <div class="form-check" title="<?= htmlspecialchars($hint) ?>">
                        <input class="form-check-input" type="checkbox" name="cap_<?= $col ?>" id="new-cap-<?= $col ?>" value="1">
                        <label class="form-check-label" for="new-cap-<?= $col ?>"><?= htmlspecialchars($lbl) ?></label>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="submit" class="btn btn-amber-solid btn-sm mt-3">
                <i class="bi bi-plus me-1"></i>Create tier
            </button>
        </form>

    </div>

    <!-- Edit Tier Modal -->
    <div class="modal fade" id="editTierModal" tabindex="-1" aria-labelledby="editTierModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content" style="background: var(--ih-surface); color: var(--ih-text); border-color: var(--ih-border);">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="id" id="edit-tier-id">
                    <div class="modal-header" style="border-color: var(--ih-border);">
                        <h5 class="modal-title" id="editTierModalLabel">
                            <i class="bi bi-pencil me-2" aria-hidden="true"></i>Edit tier — <code id="edit-tier-name"></code>
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-2 mb-3">
                            <div class="col-sm-6">
                                <label class="form-label small">Display name</label>
                                <input type="text" name="display_name" id="edit-tier-display" class="form-control form-control-sm" required>
                            </div>
                            <div class="col-sm-3">
                                <label class="form-label small">Level</label>
                                <input type="number" name="level" id="edit-tier-level" class="form-control form-control-sm" min="0" max="1000">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small">Description</label>
                            <input type="text" name="description" id="edit-tier-description" class="form-control form-control-sm">
                        </div>
                        <div class="d-flex flex-wrap gap-3">
                            <?php foreach (TIER_CAPS as $col => [$lbl, $hint]): ?>
                                <div class="form-check" title="<?= htmlspecialchars($hint) ?>">
                                    <input class="form-check-input edit-cap" type="checkbox"
                                           name="cap_<?= $col ?>" id="edit-cap-<?= $col ?>"
                                           data-cap="<?= htmlspecialchars($col) ?>" value="1">
                                    <label class="form-check-label" for="edit-cap-<?= $col ?>"><?= htmlspecialchars($lbl) ?></label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <p class="form-text mt-3 mb-0">
                            Tier name (machine key) cannot be changed — it's referenced by
                            <code>tblUsers.AccessTier</code> and the <code>tier_check</code> API.
                        </p>
                    </div>
                    <div class="modal-footer" style="border-color: var(--ih-border);">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-amber-solid">Save changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function openEditTier(t) {
            document.getElementById('edit-tier-id').value          = t.Id;
            document.getElementById('edit-tier-name').textContent  = t.Name;
            document.getElementById('edit-tier-display').value     = t.DisplayName ?? '';
            document.getElementById('edit-tier-level').value       = t.Level ?? 0;
            document.getElementById('edit-tier-description').value = t.Description ?? '';
            document.querySelectorAll('.edit-cap').forEach(cb => {
                const col = cb.dataset.cap;
                cb.checked = Number(t[col]) === 1;
            });
            new bootstrap.Modal(document.getElementById('editTierModal')).show();
        }
    </script>

    <!-- Sortable table headers (#644). -->
    <script type="module">
        import { bootSortableTables } from '/js/modules/admin-table-sort.js?v=<?= filemtime(dirname(__DIR__) . '/js/modules/admin-table-sort.js') ?>';
        bootSortableTables();
    </script>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>
</body>
</html>
