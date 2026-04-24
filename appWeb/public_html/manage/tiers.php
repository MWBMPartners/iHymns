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
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'entitlements.php';

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
$db      = getDb();

/* Ordered list of capability columns — used for both the table header
   and as the authoritative input/update list, so adding a new capability
   on the schema is a one-line change here. */
const TIER_CAPS = [
    'CanViewLyrics'      => ['Lyrics',       'View song lyrics'],
    'CanViewCopyrighted' => ['Copyrighted',  'View copyrighted songs'],
    'CanPlayAudio'       => ['Audio',        'Play MIDI / audio in-app'],
    'CanDownloadMidi'    => ['MIDI',         'Download MIDI files'],
    'CanDownloadPdf'     => ['PDF',          'Download sheet-music PDFs'],
    'CanOfflineSave'     => ['Offline',      'Save songs for offline use'],
    'RequiresCcli'       => ['Needs CCLI',   'Tier requires a valid CCLI licence number'],
];

$validName = function (string $n): ?string {
    $n = trim($n);
    if ($n === '')                         return 'Name is required.';
    if (strlen($n) > 30)                   return 'Name must be 30 characters or fewer.';
    if (!preg_match('/^[a-z0-9_]+$/', $n)) return 'Name must be lowercase letters, digits, or underscore.';
    return null;
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
                $name        = strtolower(trim((string)($_POST['name']         ?? '')));
                $displayName = trim((string)($_POST['display_name']            ?? ''));
                $level       = (int)($_POST['level']                           ?? 0);
                $description = trim((string)($_POST['description']             ?? ''));

                if ($e = $validName($name))       { $error = $e; break; }
                if ($displayName === '')          { $error = 'Display name is required.'; break; }
                if ($level < 0 || $level > 1000)  { $error = 'Level must be between 0 and 1000.'; break; }

                $stmt = $db->prepare('SELECT Id FROM tblAccessTiers WHERE Name = ?');
                $stmt->execute([$name]);
                if ($stmt->fetch()) { $error = 'A tier with that name already exists.'; break; }

                $caps = [];
                foreach (array_keys(TIER_CAPS) as $col) {
                    $caps[$col] = !empty($_POST['cap_' . $col]) ? 1 : 0;
                }

                $cols = array_merge(['Name','DisplayName','Level','Description'], array_keys(TIER_CAPS));
                $placeholders = implode(', ', array_fill(0, count($cols), '?'));
                $sql = 'INSERT INTO tblAccessTiers (' . implode(',', $cols) . ') VALUES (' . $placeholders . ')';
                $stmt = $db->prepare($sql);
                $stmt->execute(array_merge([$name, $displayName, $level, $description], array_values($caps)));
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

                $stmt = $db->prepare(
                    'UPDATE tblAccessTiers SET ' . implode(', ', $sets) . ' WHERE Id = ?'
                );
                $stmt->execute($args);
                $success = 'Tier updated.';
                break;
            }

            case 'delete': {
                $id = (int)($_POST['id'] ?? 0);
                $stmt = $db->prepare('SELECT Name FROM tblAccessTiers WHERE Id = ?');
                $stmt->execute([$id]);
                $name = (string)($stmt->fetchColumn() ?: '');
                if ($name === '') { $error = 'Tier not found.'; break; }

                $stmt = $db->prepare('SELECT COUNT(*) FROM tblUsers WHERE AccessTier = ?');
                $stmt->execute([$name]);
                $inUse = (int)$stmt->fetchColumn();
                if ($inUse > 0) {
                    $error = "Cannot delete '{$name}': {$inUse} user(s) are currently on this tier. Reassign them first.";
                    break;
                }

                $stmt = $db->prepare('DELETE FROM tblAccessTiers WHERE Id = ?');
                $stmt->execute([$id]);
                $success = "Tier '{$name}' deleted.";
                break;
            }

            default:
                $error = 'Unknown action.';
        }
    } catch (\Throwable $e) {
        error_log('[manage/tiers.php] ' . $e->getMessage());
        $error = $error ?: 'Database error — check server logs for details.';
    }
}

/* ----- GET: tiers + per-tier user counts ----- */
$tiers = [];
try {
    $capsCols = implode(', ', array_keys(TIER_CAPS));
    $rs = $db->query(
        "SELECT t.Id, t.Name, t.DisplayName, t.Level, t.Description, $capsCols,
                (SELECT COUNT(*) FROM tblUsers u WHERE u.AccessTier = t.Name) AS UserCount
           FROM tblAccessTiers t
          ORDER BY t.Level ASC, t.Name ASC"
    );
    $tiers = $rs->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    error_log('[manage/tiers.php] ' . $e->getMessage());
    $error = $error ?: 'Could not load tiers.';
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

    <div class="container py-4" style="max-width: 1200px;">

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
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr class="text-muted small">
                            <th>Name</th>
                            <th>Display</th>
                            <th class="text-center">Level</th>
                            <?php foreach (TIER_CAPS as $col => [$lbl, $hint]): ?>
                                <th class="text-center" title="<?= htmlspecialchars($hint) ?>"><?= htmlspecialchars($lbl) ?></th>
                            <?php endforeach; ?>
                            <th class="text-center">Users</th>
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
                           placeholder="e.g. premium_plus" pattern="[a-z0-9_]+">
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
            integrity="sha384-zKzgIZcXU99qF1nNW9g+x1znB5NhCPs9qZeGzUnnFOaHJF9jCCKySBjq3vIKabk/"
            crossorigin="anonymous"></script>
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

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>
</body>
</html>
