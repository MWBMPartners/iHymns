<?php

declare(strict_types=1);

/**
 * iHymns — Admin: Licence Types (#459 / #1769 P4)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * CRUD over `tblLicenceTypes` — the licence vocabulary (CCLI, MRL, iHymns Basic/
 * Pro, custom …), what each legally COVERS (lyrics display/print, music
 * reproduction, audio playback), and any access TIER it confers. The ONE source
 * of that vocabulary is `includes/licence_registry.php`; this page + the
 * `admin_licence_type_*` API are its write path, both thin wrappers over the
 * shared core `includes/licence_type_admin.php` (rule #22).
 *
 * Gated by the `manage_licence_types` entitlement.
 *
 * ⚠ Editing a licence's Confers-tier / Enabled acts on members' effective tier
 * IMMEDIATELY — even while content gating is off — because resolveEffectiveTier()
 * overlays ConfersTier regardless of the master switch (#1769 P2-F). Surfaced in
 * the page copy; never hidden.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_mysql.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'licence_registry.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'licence_type_admin.php';

if (!isAuthenticated()) {
    header('Location: /manage/login');
    exit;
}
$currentUser = getCurrentUser();
if (!$currentUser || !userHasEntitlement('manage_licence_types', $currentUser['role'] ?? null)) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><body><h1>403 — manage_licence_types required</h1></body></html>';
    exit;
}
$activePage = 'licence-types';

$error   = '';
$success = '';
$db      = getDbMysqli();
$actorId = isset($currentUser['id']) ? (int)$currentUser['id'] : (isset($currentUser['Id']) ? (int)$currentUser['Id'] : null);
$gates   = licenceTypeAdminGates($db);

/* ----- POST actions (only meaningful once the table exists) ----- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfRequest((string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo 'Invalid CSRF token';
        exit;
    }
    if (!$gates['hasTable']) {
        $error = 'The licence-type registry table has not been created yet — run the “Gating facts + licence-type registry” card on Setup Database first.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        try {
            switch ($action) {
                case 'create': {
                    $v = licenceTypeAdminValidate($db, $_POST, true);
                    if ($v['errors']) { $error = implode(' ', $v['errors']); break; }
                    $id = licenceTypeAdminCreate($db, $v['norm'], $actorId);
                    logActivity('admin.licence_types.create', 'licence_type', (string)$id, [
                        'key' => $v['norm']['key'], 'label' => $v['norm']['label'],
                        'confersTier' => $v['norm']['confers_tier'], 'covers' => $v['norm']['covers'],
                    ]);
                    $success = "Licence type “{$v['norm']['label']}” created.";
                    break;
                }
                case 'update': {
                    $id = (int)($_POST['id'] ?? 0);
                    $v  = licenceTypeAdminValidate($db, $_POST, false);
                    if ($id <= 0)      { $error = 'Missing licence-type id.'; break; }
                    if ($v['errors'])  { $error = implode(' ', $v['errors']); break; }
                    licenceTypeAdminUpdate($db, $id, $v['norm'], $actorId);
                    logActivity('admin.licence_types.update', 'licence_type', (string)$id, [
                        'label' => $v['norm']['label'], 'confersTier' => $v['norm']['confers_tier'],
                        'covers' => $v['norm']['covers'], 'enabled' => $v['norm']['enabled'],
                    ]);
                    $success = 'Licence type updated.';
                    break;
                }
                case 'toggle': {
                    $id = (int)($_POST['id'] ?? 0);
                    if ($id <= 0) { $error = 'Missing licence-type id.'; break; }
                    $state = licenceTypeAdminToggle($db, $id, $actorId);
                    logActivity('admin.licence_types.toggle', 'licence_type', (string)$id, ['enabled' => $state]);
                    $success = $state ? 'Licence type enabled.' : 'Licence type disabled.';
                    break;
                }
                case 'delete': {
                    $id = (int)($_POST['id'] ?? 0);
                    if ($id <= 0) { $error = 'Missing licence-type id.'; break; }
                    licenceTypeAdminDelete($db, $id);
                    logActivity('admin.licence_types.delete', 'licence_type', (string)$id, []);
                    $success = 'Licence type deleted.';
                    break;
                }
                default:
                    $error = 'Unknown action.';
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            logActivityError('admin.licence_types.save', 'licence_type', (string)($_POST['id'] ?? ''), $e);
        }
    }
}

/* ----- Load for render (registry reader — degrades to the byte-exact fallback
   when the table is absent; explicit $db bypasses the request cache so a
   just-saved row shows immediately, finding 3). ----- */
$licences = [];
try {
    $licences = licenceTypesAll($db);
} catch (\Throwable $e) {
    $error = $error ?: ('Could not load licence types: ' . $e->getMessage());
}

/* Live access-tier names for the Confers-tier picker (never a hardcoded list). */
$tierNames = [];
try {
    $res = $db->query('SELECT Name FROM tblAccessTiers ORDER BY Level ASC, Name ASC');
    if ($res) { foreach ($res->fetch_all(MYSQLI_ASSOC) as $r) { $tierNames[] = (string)$r['Name']; } }
} catch (\Throwable $_e) { /* degrade: empty picker → only “— none —” */ }

$csrf = csrfToken();
$coverageKinds = LICENCE_COVERAGE_KINDS; // slug => human label
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Licence Types — iHymns Admin</title>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-libs.php'; ?>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-favicon.php'; ?>
</head>
<body>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-nav.php'; ?>

    <div class="container-admin py-4">

        <h1 class="h4 mb-3"><i class="bi bi-patch-check me-2"></i>Licence Types</h1>
        <p class="text-secondary small mb-3">
            The list of licence types the site knows about — such as CCLI or MRL — with what each one
            legally <em>allows</em> (showing or printing lyrics, reproducing music, playing audio) and any
            membership level it grants. Organisations, songs and content rules all point to these licences.
            A licence's short code can't be changed once it's created; everything else can.
        </p>
        <div class="alert alert-warning py-2 small" role="note">
            <i class="bi bi-exclamation-triangle me-1"></i>
            Changing a licence’s <strong>Confers-tier</strong> or <strong>Enabled</strong> state affects members’
            effective access tier <strong>immediately</strong> — even while content gating is switched off.
        </div>

        <?php if (!$gates['hasTable']): ?>
            <div class="alert alert-info py-2">
                The licence-type registry table isn’t installed on this environment yet. Run the
                <strong>“Gating facts + licence-type registry”</strong> card on
                <a href="/manage/setup-database" class="text-info">Setup Database</a>, then reload.
                The built-in defaults below are shown read-only from the fallback registry.
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success py-2"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <!-- Licence-type list -->
        <div class="card-admin p-3 mb-4">
            <h2 class="h6 mb-3">All licence types</h2>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0 admin-table-responsive cp-sortable" data-default-sort-key="sort" data-default-sort-dir="asc">
                    <thead>
                        <tr class="text-muted small">
                            <th data-col-priority="primary"   data-sort-key="key">Key</th>
                            <th data-col-priority="primary"   data-sort-key="label">Label</th>
                            <th data-col-priority="secondary" data-sort-key="confers">Confers tier</th>
                            <th data-col-priority="secondary" data-sort-key="covers" data-sort-type="text">Covers</th>
                            <th data-col-priority="tertiary"  data-sort-key="number">Needs number</th>
                            <th data-col-priority="tertiary"  data-sort-key="source">Source</th>
                            <th data-col-priority="primary"   data-sort-key="enabled">Enabled</th>
                            <th data-col-priority="primary" class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$licences): ?>
                            <tr><td colspan="8" class="text-center text-muted py-3">No licence types.</td></tr>
                        <?php else: foreach ($licences as $key => $lt):
                            $covers = is_array($lt['covers'] ?? null) ? array_keys($lt['covers']) : [];
                            $coversLabel = $covers ? implode(', ', $covers) : '—';
                        ?>
                            <tr>
                                <td data-col-priority="primary" data-sort-value="<?= htmlspecialchars($key) ?>"><code><?= htmlspecialchars($key) ?></code></td>
                                <td data-col-priority="primary" data-sort-value="<?= htmlspecialchars((string)$lt['label']) ?>"><?= htmlspecialchars((string)$lt['label']) ?></td>
                                <td data-col-priority="secondary" data-sort-value="<?= htmlspecialchars((string)($lt['confersTier'] ?? '')) ?>"><?= $lt['confersTier'] ? htmlspecialchars((string)$lt['confersTier']) : '<span class="text-muted">—</span>' ?></td>
                                <td data-col-priority="secondary"><span class="small"><?= htmlspecialchars($coversLabel) ?></span></td>
                                <td data-col-priority="tertiary" data-sort-value="<?= $lt['requiresNumber'] ? 1 : 0 ?>"><?= $lt['requiresNumber'] ? 'Yes' : 'No' ?></td>
                                <td data-col-priority="tertiary" data-sort-value="<?= htmlspecialchars((string)$lt['source']) ?>"><span class="badge bg-secondary-subtle text-secondary-emphasis"><?= htmlspecialchars((string)$lt['source']) ?></span></td>
                                <td data-col-priority="primary" data-sort-value="<?= $lt['enabled'] ? 1 : 0 ?>">
                                    <?= $lt['enabled'] ? '<span class="badge bg-success-subtle text-success-emphasis">Enabled</span>' : '<span class="badge bg-secondary-subtle text-secondary-emphasis">Disabled</span>' ?>
                                </td>
                                <td data-col-priority="primary" class="text-end">
                                    <?php if ($gates['hasTable'] && isset($lt['id'])): ?>
                                        <button type="button" class="btn btn-sm btn-outline-info lt-edit"
                                                data-lt='<?= htmlspecialchars((string)json_encode($lt), ENT_QUOTES) ?>'>Edit</button>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                            <input type="hidden" name="action" value="toggle">
                                            <input type="hidden" name="id" value="<?= (int)$lt['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-secondary"><?= $lt['enabled'] ? 'Disable' : 'Enable' ?></button>
                                        </form>
                                        <?php if (($lt['source'] ?? '') !== 'system'): ?>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Delete this licence type? This is refused if anything still references it.');">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= (int)$lt['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                            </form>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted small">read-only</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if ($gates['hasTable']): ?>
        <!-- Create / edit form -->
        <div class="card-admin p-3 mb-4">
            <h2 class="h6 mb-3" id="lt-form-title">Add a licence type</h2>
            <form method="POST" id="lt-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action" id="lt-action" value="create">
                <input type="hidden" name="id" id="lt-id" value="">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label small" for="lt-key">Key</label>
                        <input type="text" class="form-control form-control-sm" id="lt-key" name="key"
                               pattern="[a-z][a-z0-9_]{1,29}" maxlength="30" required
                               aria-describedby="lt-key-help">
                        <div class="form-text" id="lt-key-help">Lowercase, immutable once created.</div>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label small" for="lt-label">Label</label>
                        <input type="text" class="form-control form-control-sm" id="lt-label" name="label" maxlength="100" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small" for="lt-confers">Confers tier</label>
                        <select class="form-select form-select-sm" id="lt-confers" name="confers_tier">
                            <option value="">— none —</option>
                            <?php foreach ($tierNames as $tn): ?>
                                <option value="<?= htmlspecialchars($tn) ?>"><?= htmlspecialchars($tn) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label small" for="lt-description">Description</label>
                        <input type="text" class="form-control form-control-sm" id="lt-description" name="description" maxlength="255">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small d-block">Covers</label>
                        <?php foreach ($coverageKinds as $slug => $human): ?>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input lt-cover" type="checkbox" name="covers[]"
                                       value="<?= htmlspecialchars($slug) ?>" id="lt-cover-<?= htmlspecialchars($slug) ?>">
                                <label class="form-check-label small" for="lt-cover-<?= htmlspecialchars($slug) ?>"
                                       title="<?= htmlspecialchars($human) ?>"><?= htmlspecialchars($slug) ?></label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small" for="lt-sort">Sort order</label>
                        <input type="number" class="form-control form-control-sm" id="lt-sort" name="sort_order" value="0" min="0">
                    </div>
                    <div class="col-md-3 d-flex align-items-end gap-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="requires_number" id="lt-number" value="1">
                            <label class="form-check-label small" for="lt-number">Needs licence number</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="enabled" id="lt-enabled" value="1" checked>
                            <label class="form-check-label small" for="lt-enabled">Enabled</label>
                        </div>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-info btn-sm" id="lt-submit">Add licence type</button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="lt-reset">Clear</button>
                    </div>
                </div>
            </form>
        </div>
        <?php endif; ?>

    </div>

    <?php if ($gates['hasTable']): ?>
    <!-- Edit-button → populate the form (DOMContentLoaded-guarded inline script:
         the correct pattern for a full admin page with its own <head>, not a
         nonce-CSP SPA fragment — rule #30 does not apply here). -->
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        var form  = document.getElementById('lt-form');
        if (!form) { return; }
        var keyEl = document.getElementById('lt-key');

        function toCreate() {
            document.getElementById('lt-form-title').textContent = 'Add a licence type';
            document.getElementById('lt-action').value = 'create';
            document.getElementById('lt-id').value = '';
            document.getElementById('lt-submit').textContent = 'Add licence type';
            keyEl.readOnly = false; keyEl.value = '';
            document.getElementById('lt-label').value = '';
            document.getElementById('lt-description').value = '';
            document.getElementById('lt-confers').value = '';
            document.getElementById('lt-sort').value = '0';
            document.getElementById('lt-number').checked = false;
            document.getElementById('lt-enabled').checked = true;
            document.querySelectorAll('.lt-cover').forEach(function (cb) { cb.checked = false; });
        }

        document.querySelectorAll('.lt-edit').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var lt;
                try { lt = JSON.parse(btn.getAttribute('data-lt') || '{}'); } catch (e) { return; }
                document.getElementById('lt-form-title').textContent = 'Edit “' + (lt.label || lt.key) + '”';
                document.getElementById('lt-action').value = 'update';
                document.getElementById('lt-id').value = lt.id || '';
                document.getElementById('lt-submit').textContent = 'Save changes';
                /* The key is immutable once created (it is referenced across many
                   tables) — show it read-only on edit. */
                keyEl.value = lt.key || '';
                keyEl.readOnly = true;
                document.getElementById('lt-label').value = lt.label || '';
                document.getElementById('lt-description').value = lt.description || '';
                document.getElementById('lt-confers').value = lt.confersTier || '';
                document.getElementById('lt-sort').value = (lt.sortOrder != null ? lt.sortOrder : 0);
                document.getElementById('lt-number').checked = !!lt.requiresNumber;
                document.getElementById('lt-enabled').checked = !!lt.enabled;
                var covered = (lt.covers && typeof lt.covers === 'object') ? Object.keys(lt.covers) : [];
                document.querySelectorAll('.lt-cover').forEach(function (cb) {
                    cb.checked = covered.indexOf(cb.value) !== -1;
                });
                form.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
        });

        var resetBtn = document.getElementById('lt-reset');
        if (resetBtn) { resetBtn.addEventListener('click', toCreate); }
    });
    </script>

    <!-- Sortable table headers (#644 / #844). -->
    <script type="module">
        import { bootSortableTables } from '/js/modules/admin-table-sort.js?v=<?= filemtime(dirname(__DIR__) . '/js/modules/admin-table-sort.js') ?>';
        bootSortableTables();
    </script>
    <?php endif; ?>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>
</body>
</html>
