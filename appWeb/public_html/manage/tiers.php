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
    /* #1769 P0, rule #29: same-origin-aware CSRF. Still accepts the baked
       session token (unchanged for a normal form POST) but ALSO the never-stale
       X-Requested-With + host-match route, so a long-lived tiers page whose
       token has rotated/GC'd no longer throws a spurious CSRF error on save. */
    if (!validateCsrfRequest((string)($_POST['csrf_token'] ?? ''))) {
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

                /* Split caps by storage (#1352), mirroring api.php's
                   admin_tier_create. Column caps (the 7 originals) bind to
                   their own TINYINT columns; json caps go into the single
                   Capabilities JSON column (only when registered AND the
                   migration has landed). */
                $columnCaps = [];
                foreach (tierCapColumnKeys() as $col) {
                    $columnCaps[$col] = !empty($_POST['cap_' . $col]) ? 1 : 0;
                }
                $jsonKeys  = tierCapJsonKeys();
                $writeJson = $jsonKeys && tierCapsColumnExists($db);
                $jsonCaps  = [];
                foreach ($jsonKeys as $col) {
                    $jsonCaps[$col] = !empty($_POST['cap_' . $col]) ? 1 : 0;
                }

                $cols   = array_merge(['Name','DisplayName','Level','Description'], array_keys($columnCaps));
                $types  = 'ssis' . str_repeat('i', count($columnCaps));
                $values = array_merge([$name, $displayName, $level, $description], array_values($columnCaps));
                if ($writeJson) {
                    /* One extra column + one bound 's' param — placeholder /
                       type / value counts stay in lockstep (rule #5). */
                    $cols[]   = 'Capabilities';
                    $types   .= 's';
                    $values[] = json_encode($jsonCaps, JSON_UNESCAPED_SLASHES);
                }
                $placeholders = implode(', ', array_fill(0, count($cols), '?'));
                $sql = 'INSERT INTO tblAccessTiers (' . implode(',', $cols) . ') VALUES (' . $placeholders . ')';
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

                /* Split caps by storage (#1352), mirroring api.php's
                   admin_tier_update. Column caps SET their own column; json
                   caps merge into the Capabilities JSON column. */
                $columnCaps = [];
                foreach (tierCapColumnKeys() as $col) {
                    $columnCaps[$col] = !empty($_POST['cap_' . $col]) ? 1 : 0;
                }
                $jsonKeys  = tierCapJsonKeys();
                $writeJson = $jsonKeys && tierCapsColumnExists($db);

                $sets  = ['DisplayName = ?', 'Level = ?', 'Description = ?'];
                $args  = [$displayName, $level, $description];
                $types = 'sis';
                foreach ($columnCaps as $col => $val) {
                    $sets[]  = "$col = ?";
                    $args[]  = $val;
                    $types  .= 'i';
                }
                if ($writeJson) {
                    /* Merge submitted json caps over the row's existing
                       Capabilities so caps the form didn't carry aren't lost. */
                    $existing = [];
                    try {
                        $stmtCap = $db->prepare('SELECT Capabilities FROM tblAccessTiers WHERE Id = ?');
                        $stmtCap->bind_param('i', $id);
                        $stmtCap->execute();
                        $rowCap = $stmtCap->get_result()->fetch_row();
                        $stmtCap->close();
                        $rawCap = $rowCap[0] ?? null;
                        if ($rawCap !== null && $rawCap !== '') {
                            $decodedCap = json_decode((string)$rawCap, true);
                            if (is_array($decodedCap)) { $existing = $decodedCap; }
                        }
                    } catch (\Throwable $_e) { /* degrade to empty existing */ }
                    foreach ($jsonKeys as $col) {
                        $existing[$col] = !empty($_POST['cap_' . $col]) ? 1 : 0;
                    }
                    $sets[]  = 'Capabilities = ?';
                    $args[]  = json_encode($existing, JSON_UNESCAPED_SLASHES);
                    $types  .= 's';
                }
                $args[]  = $id;
                $types  .= 'i';
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
    /* $capsCols is built from tierCapColumnKeys() (file-scope const-derived,
       column-backed caps only), so the interpolated identifier list cannot be
       user-influenced. SQL identifiers can't be parameterised — this is the
       correct pattern for trusted internal-source identifier lists. JSON-backed
       caps are NOT columns, so they're read from the Capabilities blob (selected
       only when migrated) via tierCapRead() in the render below. */
    $capColumnKeys = tierCapColumnKeys();
    $capsCols      = implode(', ', $capColumnKeys);
    $hasCapsCol    = tierCapsColumnExists($db);
    $capsJsonSel   = $hasCapsCol ? ', t.Capabilities' : '';
    $stmt = $db->prepare(
        "SELECT t.Id, t.Name, t.DisplayName, t.Level, t.Description, $capsCols$capsJsonSel,
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
     Name, Display, Level, ...tierCapsEffective()..., Users, Actions
   tierCapsEffective() (#1481) = TIER_CAPS ∪ enabled tblGatingCapabilities
   rows, so the matrix auto-grows a column the moment a Global Admin defines
   a new capability on /manage/feature-gating — dormant/empty table ⇒
   identical to count(TIER_CAPS) as before #1481. */
$tierTableCols = 3 + count(tierCapsEffective()) + 2;
?>
<!DOCTYPE html>
<html lang="en">
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
            <?php if ($currentUser && userHasEntitlement('manage_feature_gating', $currentUser['role'] ?? null)): ?>
                Add or gate <strong>additional</strong> capabilities (beyond the built-in seven) on
                <a href="/manage/feature-gating" class="text-info">Feature Gating</a>.
            <?php endif; ?>
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
                            <?php /* tierCapsEffective() (#1481) = TIER_CAPS ∪ enabled
                                     tblGatingCapabilities rows — the matrix auto-grows a
                                     column for any Global-Admin-defined capability with
                                     no structural change; dormant/empty table renders
                                     identically to the pre-#1481 bare TIER_CAPS. */ ?>
                            <?php foreach (tierCapsEffective() as $col => [$lbl, $hint]): ?>
                                <th class="text-center" title="<?= htmlspecialchars($hint) ?>"><?= htmlspecialchars($lbl) ?></th>
                            <?php endforeach; ?>
                            <th class="text-center" data-sort-key="users" data-sort-type="number">Users</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tiers as $t): ?>
                            <?php
                                /* Resolve every cap (column + json) to a flat 0/1 map
                                   so the edit modal's JS reads one uniform object
                                   regardless of storage (#1352) — json caps live inside
                                   t.Capabilities, which the JS can't index by cap key.
                                   array_keys(tierCapsEffective()) (#1481) includes any
                                   admin-defined capability alongside the 7 built-ins. */
                                $t['_caps'] = [];
                                foreach (array_keys(tierCapsEffective()) as $capCol) {
                                    $t['_caps'][$capCol] = tierCapRead($t, $capCol);
                                }
                            ?>
                            <tr>
                                <td><code><?= htmlspecialchars($t['Name']) ?></code></td>
                                <td><?= htmlspecialchars($t['DisplayName']) ?></td>
                                <td class="text-center"><?= (int)$t['Level'] ?></td>
                                <?php foreach (array_keys(tierCapsEffective()) as $col): ?>
                                    <td class="text-center">
                                        <?php /* tierCapRead() reads the column for column-backed caps,
                                                 else decodes Capabilities — so json caps render too. */ ?>
                                        <?= tierCapRead($t, $col)
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
                <?php /* tierCapsEffective() (#1481) — the create form auto-grows a
                         checkbox for any Global-Admin-defined capability. $col is
                         htmlspecialchars()'d below (belt-and-braces #1481 review
                         finding — the CapKey grammar ^Can[A-Z][A-Za-z0-9]{1,29}$ is
                         the primary defence, this is defence-in-depth). */ ?>
                <?php foreach (tierCapsEffective() as $col => [$lbl, $hint]): ?>
                    <div class="form-check" title="<?= htmlspecialchars($hint) ?>">
                        <input class="form-check-input" type="checkbox" name="cap_<?= htmlspecialchars($col) ?>" id="new-cap-<?= htmlspecialchars($col) ?>" value="1">
                        <label class="form-check-label" for="new-cap-<?= htmlspecialchars($col) ?>"><?= htmlspecialchars($lbl) ?></label>
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
                            <?php /* tierCapsEffective() (#1481) — the edit modal auto-grows a
                                     checkbox for any Global-Admin-defined capability. $col is
                                     htmlspecialchars()'d below (belt-and-braces #1481 review
                                     finding — the CapKey grammar is the primary defence). */ ?>
                            <?php foreach (tierCapsEffective() as $col => [$lbl, $hint]): ?>
                                <div class="form-check" title="<?= htmlspecialchars($hint) ?>">
                                    <input class="form-check-input edit-cap" type="checkbox"
                                           name="cap_<?= htmlspecialchars($col) ?>" id="edit-cap-<?= htmlspecialchars($col) ?>"
                                           data-cap="<?= htmlspecialchars($col) ?>" value="1">
                                    <label class="form-check-label" for="edit-cap-<?= htmlspecialchars($col) ?>"><?= htmlspecialchars($lbl) ?></label>
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
                /* Read the server-resolved flat caps map (#1352) so json-backed
                   caps (stored inside t.Capabilities, not as a top-level key)
                   set their checkbox correctly. Falls back to the legacy
                   top-level key for safety. */
                const caps = (t && t._caps) ? t._caps : t;
                cb.checked = Number(caps[col]) === 1;
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
