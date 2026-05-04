<?php

declare(strict_types=1);

/**
 * iHymns — Admin: External-Link Types & URL Patterns (#845)
 *
 * CRUD surface for the external-link controlled vocabulary:
 *   - tblExternalLinkTypes  — the registry rows (Wikipedia, Spotify, …)
 *   - tblExternalLinkPatterns — the URL → provider rules that drive
 *     the JS auto-detect module.
 *
 * Curator-edits to either are picked up by every admin edit-modal
 * that ships the registry to its row builder via the
 * `attachExternalLinkPatterns()` helper from
 * /includes/external_link_helpers.php.
 *
 * Gated by `manage_external_link_types`. Pre-migration safe — probes
 * for both tables on every page load.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_mysql.php';

if (!isAuthenticated()) {
    header('Location: /manage/login');
    exit;
}
$currentUser = getCurrentUser();
if (!$currentUser || !userHasEntitlement('manage_external_link_types', $currentUser['role'] ?? null)) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><body><h1>403 — manage_external_link_types required</h1></body></html>';
    exit;
}
$activePage = 'external-link-types';

$error   = '';
$success = '';
$db      = getDbMysqli();
$csrf    = csrfToken();

/* Schema probes */
$hasTypesSchema    = false;
$hasPatternsSchema = false;
try {
    $r = $db->query("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblExternalLinkTypes' LIMIT 1");
    $hasTypesSchema = $r && $r->fetch_row() !== null;
    if ($r) $r->close();
    $r = $db->query("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblExternalLinkPatterns' LIMIT 1");
    $hasPatternsSchema = $r && $r->fetch_row() !== null;
    if ($r) $r->close();
} catch (\Throwable $e) {
    error_log('[external-link-types] schema probe failed: ' . $e->getMessage());
}

/* ---- POST actions ---- */
if ($hasPatternsSchema && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!validateCsrf((string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo 'Invalid CSRF token';
        exit;
    }
    $action = (string)($_POST['action'] ?? '');
    try {
        switch ($action) {
            case 'save_type_patterns': {
                $typeId = (int)($_POST['type_id'] ?? 0);
                if ($typeId <= 0) { $error = 'Type id is required.'; break; }

                /* Toggle the parent type's IsActive at the same time —
                   the form ships an `is_active` flag (1/0). */
                $isActive = !empty($_POST['is_active']) ? 1 : 0;

                /* Patterns posted as parallel arrays. */
                $pHosts   = $_POST['pattern_host']     ?? [];
                $pPaths   = $_POST['pattern_path']     ?? [];
                $pSubs    = $_POST['pattern_subdomain'] ?? [];
                $pPrios   = $_POST['pattern_priority'] ?? [];
                $pNotes   = $_POST['pattern_note']     ?? [];
                $pActive  = $_POST['pattern_active']   ?? [];
                if (!is_array($pHosts))  $pHosts  = [];
                if (!is_array($pPaths))  $pPaths  = [];
                if (!is_array($pSubs))   $pSubs   = [];
                if (!is_array($pPrios))  $pPrios  = [];
                if (!is_array($pNotes))  $pNotes  = [];
                if (!is_array($pActive)) $pActive = [];

                $db->begin_transaction();
                try {
                    /* Update parent type's IsActive flag. */
                    $stmt = $db->prepare('UPDATE tblExternalLinkTypes SET IsActive = ? WHERE Id = ?');
                    $stmt->bind_param('ii', $isActive, $typeId);
                    $stmt->execute();
                    $stmt->close();

                    /* Replace patterns wholesale: delete existing rows
                       for the type then re-insert from the posted list.
                       Cheap on the small per-type list sizes we expect
                       (typically 1–10 patterns per provider). */
                    $stmt = $db->prepare('DELETE FROM tblExternalLinkPatterns WHERE LinkTypeId = ?');
                    $stmt->bind_param('i', $typeId);
                    $stmt->execute();
                    $stmt->close();

                    $insertCount = 0;
                    $count = max(count($pHosts), count($pPaths));
                    $insert = $db->prepare(
                        'INSERT INTO tblExternalLinkPatterns
                             (LinkTypeId, Host, PathPrefix, MatchSubdomains, Priority, IsActive, Note)
                         VALUES (?, ?, NULLIF(?, ""), ?, ?, ?, NULLIF(?, ""))'
                    );
                    for ($i = 0; $i < $count; $i++) {
                        $host = trim((string)($pHosts[$i] ?? ''));
                        if ($host === '') continue;
                        /* Strip protocol / leading wildcard / trailing
                           slash a curator might include by accident. */
                        $host = preg_replace('#^https?://#i', '', $host);
                        $host = ltrim((string)$host, '*.');
                        $host = rtrim((string)$host, '/');
                        $host = mb_substr($host, 0, 255);
                        if ($host === '' || strpos($host, '.') === false) continue;

                        $path = trim((string)($pPaths[$i] ?? ''));
                        if ($path !== '' && $path[0] !== '/') $path = '/' . $path;
                        $path = mb_substr($path, 0, 255);

                        $sd   = !empty($pSubs[$i])    ? 1 : 0;
                        $prio = isset($pPrios[$i])    ? max(0, min(65535, (int)$pPrios[$i])) : 100;
                        $act  = !empty($pActive[$i])  ? 1 : 0;
                        $note = mb_substr((string)($pNotes[$i] ?? ''), 0, 255);

                        $insert->bind_param('issiiis', $typeId, $host, $path, $sd, $prio, $act, $note);
                        $insert->execute();
                        $insertCount++;
                    }
                    $insert->close();
                    $db->commit();

                    if (function_exists('logActivity')) {
                        logActivity('external_link_type.save_patterns', 'external_link_type', (string)$typeId, [
                            'is_active'     => (bool)$isActive,
                            'pattern_count' => $insertCount,
                        ]);
                    }
                    $success = "Saved {$insertCount} pattern" . ($insertCount === 1 ? '' : 's') . '.';
                } catch (\Throwable $tx) {
                    $db->rollback();
                    throw $tx;
                }
                break;
            }
        }
    } catch (\Throwable $e) {
        error_log('[external-link-types POST] ' . $e->getMessage());
        $error = 'Could not save changes: ' . $e->getMessage();
    }
}

/* ---- Read ---- */
$types = [];
if ($hasTypesSchema) {
    try {
        $res = $db->query(
            'SELECT Id, Slug, Name, Category, IconClass, AppliesTo, AllowMultiple,
                    IsActive, DisplayOrder
               FROM tblExternalLinkTypes
              ORDER BY Category ASC, DisplayOrder ASC, Name ASC'
        );
        while ($row = $res->fetch_assoc()) {
            $types[(int)$row['Id']] = [
                'id'             => (int)$row['Id'],
                'slug'           => (string)$row['Slug'],
                'name'           => (string)$row['Name'],
                'category'       => (string)$row['Category'],
                'iconClass'      => (string)($row['IconClass'] ?? ''),
                'appliesTo'      => (string)$row['AppliesTo'],
                'allowMultiple'  => (int)$row['AllowMultiple'],
                'isActive'       => (int)$row['IsActive'],
                'displayOrder'   => (int)$row['DisplayOrder'],
                'patterns'       => [],
            ];
        }
        $res->close();

        if ($hasPatternsSchema && $types) {
            $res = $db->query(
                'SELECT Id, LinkTypeId, Host, PathPrefix, MatchSubdomains,
                        Priority, IsActive, Note
                   FROM tblExternalLinkPatterns
                  ORDER BY LinkTypeId ASC, Priority ASC, Host ASC'
            );
            while ($row = $res->fetch_assoc()) {
                $tid = (int)$row['LinkTypeId'];
                if (!isset($types[$tid])) continue;
                $types[$tid]['patterns'][] = [
                    'id'              => (int)$row['Id'],
                    'host'            => (string)$row['Host'],
                    'pathPrefix'      => (string)($row['PathPrefix'] ?? ''),
                    'matchSubdomains' => (int)$row['MatchSubdomains'],
                    'priority'        => (int)$row['Priority'],
                    'isActive'        => (int)$row['IsActive'],
                    'note'            => (string)($row['Note'] ?? ''),
                ];
            }
            $res->close();
        }
    } catch (\Throwable $e) {
        error_log('[external-link-types read] ' . $e->getMessage());
    }
}

/* Group types by Category for the page render. */
$typesByCategory = [];
foreach ($types as $t) {
    $cat = (string)$t['category'];
    $typesByCategory[$cat][] = $t;
}
$categoryLabels = [
    'official'    => 'Official',
    'information' => 'Information',
    'read'        => 'Read',
    'sheet-music' => 'Sheet music',
    'listen'      => 'Listen',
    'watch'       => 'Watch',
    'purchase'    => 'Purchase',
    'authority'   => 'Authority',
    'social'      => 'Social',
    'other'       => 'Other',
];

?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrf) ?>">
    <title>External-Link Types — iHymns Admin</title>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-libs.php'; ?>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-favicon.php'; ?>
</head>
<body>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-nav.php'; ?>

    <div class="container-admin py-4">
        <h1 class="h4 mb-3"><i class="bi bi-link-45deg me-2"></i>External-Link Types &amp; URL Patterns</h1>
        <p class="text-secondary small mb-4">
            The controlled-vocabulary registry that drives every <strong>Find this … elsewhere</strong>
            panel on the public site, plus the URL → provider patterns the auto-detect
            module uses. Curator-edits land immediately — no code deploy needed. Each
            link type can carry many patterns; sub-domain matching covers
            <code>en.wikipedia.org</code> from a single <code>wikipedia.org</code> rule, and
            an optional path-prefix (e.g. <code>/work/</code>) discriminates same-host providers
            (MusicBrainz Work vs Recording vs Artist).
        </p>

        <?php if ($success): ?>
            <div class="alert alert-success py-2"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if (!$hasTypesSchema || !$hasPatternsSchema): ?>
            <div class="card-admin p-4 text-center">
                <p class="mb-2">
                    <i class="bi bi-database-exclamation text-warning fs-1" aria-hidden="true"></i>
                </p>
                <h2 class="h6 mb-2">Schema not yet installed</h2>
                <p class="text-muted small mb-3">
                    The <code>tblExternalLinkTypes</code> (#833) and
                    <code>tblExternalLinkPatterns</code> (#845) tables aren't both present yet.
                </p>
                <a href="/manage/setup-database" class="btn btn-amber btn-sm">
                    <i class="bi bi-database-gear me-1"></i>Run /manage/setup-database
                </a>
            </div>
        <?php else: ?>

        <?php foreach ($categoryLabels as $catKey => $catLabel): ?>
            <?php if (empty($typesByCategory[$catKey])) continue; ?>
            <div class="card-admin p-3 mb-4">
                <h2 class="h6 mb-3 text-uppercase text-muted"><?= htmlspecialchars($catLabel) ?></h2>
                <div class="vstack gap-3">
                    <?php foreach ($typesByCategory[$catKey] as $t): ?>
                        <details class="card bg-dark border-secondary">
                            <summary class="card-header d-flex align-items-center gap-2 user-select-none" style="cursor:pointer; list-style-position: outside;">
                                <?php if (!empty($t['iconClass'])): ?>
                                    <i class="<?= htmlspecialchars($t['iconClass']) ?>" aria-hidden="true"></i>
                                <?php endif; ?>
                                <strong><?= htmlspecialchars($t['name']) ?></strong>
                                <code class="small text-muted"><?= htmlspecialchars($t['slug']) ?></code>
                                <span class="badge bg-secondary-subtle text-secondary-emphasis ms-1" title="Applies to entity types">
                                    <?= htmlspecialchars($t['appliesTo']) ?>
                                </span>
                                <span class="ms-auto small">
                                    <span class="badge <?= $t['isActive'] ? 'bg-success-subtle text-success-emphasis' : 'bg-secondary-subtle text-secondary-emphasis' ?>">
                                        <?= $t['isActive'] ? 'active' : 'inactive' ?>
                                    </span>
                                    <span class="text-muted ms-2">
                                        <?= count($t['patterns']) ?> pattern<?= count($t['patterns']) === 1 ? '' : 's' ?>
                                    </span>
                                </span>
                            </summary>
                            <div class="card-body">
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                    <input type="hidden" name="action" value="save_type_patterns">
                                    <input type="hidden" name="type_id" value="<?= (int)$t['id'] ?>">

                                    <div class="form-check form-switch mb-3">
                                        <input class="form-check-input" type="checkbox"
                                               name="is_active" value="1"
                                               id="is-active-<?= (int)$t['id'] ?>"
                                               <?= $t['isActive'] ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="is-active-<?= (int)$t['id'] ?>">
                                            Active — show this provider on public pages and offer it in edit-modal dropdowns
                                        </label>
                                    </div>

                                    <h3 class="h6 mb-2"><i class="bi bi-link me-2"></i>URL patterns</h3>
                                    <p class="form-text small mt-0 mb-2">
                                        Each row matches a URL by hostname (and optionally path prefix). Lower
                                        priority numbers win, so put more-specific patterns first.
                                    </p>

                                    <div class="vstack gap-2 patterns-rows" data-rows>
                                        <?php foreach ($t['patterns'] as $p): ?>
                                            <div class="card bg-secondary-subtle border-secondary">
                                                <div class="card-body py-2">
                                                    <div class="row g-2 align-items-center">
                                                        <div class="col-md-4">
                                                            <label class="form-label small mb-0">Host</label>
                                                            <input type="text" class="form-control form-control-sm" name="pattern_host[]"
                                                                   value="<?= htmlspecialchars($p['host']) ?>"
                                                                   placeholder="wikipedia.org" required maxlength="255">
                                                        </div>
                                                        <div class="col-md-3">
                                                            <label class="form-label small mb-0">Path prefix</label>
                                                            <input type="text" class="form-control form-control-sm" name="pattern_path[]"
                                                                   value="<?= htmlspecialchars($p['pathPrefix']) ?>"
                                                                   placeholder="/work/  (optional)" maxlength="255">
                                                        </div>
                                                        <div class="col-md-2">
                                                            <label class="form-label small mb-0">Priority</label>
                                                            <input type="number" class="form-control form-control-sm" name="pattern_priority[]"
                                                                   value="<?= (int)$p['priority'] ?>" min="0" max="65535">
                                                        </div>
                                                        <div class="col-md-3 d-flex flex-column align-items-start gap-1 mt-3">
                                                            <div class="form-check small">
                                                                <input class="form-check-input" type="checkbox" name="pattern_subdomain[]" value="1" <?= $p['matchSubdomains'] ? 'checked' : '' ?>>
                                                                <label class="form-check-label">Match sub-domains</label>
                                                            </div>
                                                            <div class="form-check small">
                                                                <input class="form-check-input" type="checkbox" name="pattern_active[]" value="1" <?= $p['isActive'] ? 'checked' : '' ?>>
                                                                <label class="form-check-label">Active</label>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="row g-2 mt-1">
                                                        <div class="col-md-9">
                                                            <input type="text" class="form-control form-control-sm" name="pattern_note[]"
                                                                   value="<?= htmlspecialchars($p['note']) ?>"
                                                                   placeholder="Optional note (curator's reference)" maxlength="255">
                                                        </div>
                                                        <div class="col-md-3 text-end">
                                                            <button type="button" class="btn btn-sm btn-outline-danger" data-action="remove-pattern">
                                                                <i class="bi bi-x-lg"></i> Remove
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>

                                    <div class="d-flex gap-2 mt-3">
                                        <button type="button" class="btn btn-outline-info btn-sm" data-action="add-pattern" data-type-id="<?= (int)$t['id'] ?>">
                                            <i class="bi bi-plus-lg me-1"></i>Add pattern
                                        </button>
                                        <button type="submit" class="btn btn-amber btn-sm ms-auto">
                                            <i class="bi bi-save me-1"></i>Save
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </details>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <?php endif; ?>
    </div>

    <script>
    (function () {
        function escapeHtml(s) {
            return String(s)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }
        const blankRowHtml =
          '<div class="card bg-secondary-subtle border-secondary">' +
            '<div class="card-body py-2">' +
              '<div class="row g-2 align-items-center">' +
                '<div class="col-md-4">' +
                  '<label class="form-label small mb-0">Host</label>' +
                  '<input type="text" class="form-control form-control-sm" name="pattern_host[]" placeholder="wikipedia.org" required maxlength="255">' +
                '</div>' +
                '<div class="col-md-3">' +
                  '<label class="form-label small mb-0">Path prefix</label>' +
                  '<input type="text" class="form-control form-control-sm" name="pattern_path[]" placeholder="/work/  (optional)" maxlength="255">' +
                '</div>' +
                '<div class="col-md-2">' +
                  '<label class="form-label small mb-0">Priority</label>' +
                  '<input type="number" class="form-control form-control-sm" name="pattern_priority[]" value="100" min="0" max="65535">' +
                '</div>' +
                '<div class="col-md-3 d-flex flex-column align-items-start gap-1 mt-3">' +
                  '<div class="form-check small"><input class="form-check-input" type="checkbox" name="pattern_subdomain[]" value="1" checked><label class="form-check-label">Match sub-domains</label></div>' +
                  '<div class="form-check small"><input class="form-check-input" type="checkbox" name="pattern_active[]" value="1" checked><label class="form-check-label">Active</label></div>' +
                '</div>' +
              '</div>' +
              '<div class="row g-2 mt-1">' +
                '<div class="col-md-9"><input type="text" class="form-control form-control-sm" name="pattern_note[]" placeholder="Optional note" maxlength="255"></div>' +
                '<div class="col-md-3 text-end"><button type="button" class="btn btn-sm btn-outline-danger" data-action="remove-pattern"><i class="bi bi-x-lg"></i> Remove</button></div>' +
              '</div>' +
            '</div>' +
          '</div>';

        document.addEventListener('click', (ev) => {
            const btn = ev.target.closest('[data-action]');
            if (!btn) return;
            const a = btn.getAttribute('data-action');
            if (a === 'add-pattern') {
                const rows = btn.closest('form').querySelector('[data-rows]');
                if (!rows) return;
                const tmp = document.createElement('div');
                tmp.innerHTML = blankRowHtml;
                rows.appendChild(tmp.firstElementChild);
            } else if (a === 'remove-pattern') {
                btn.closest('.card.bg-secondary-subtle')?.remove();
            }
        });
    })();
    </script>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>
</body>
</html>
