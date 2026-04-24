<?php

declare(strict_types=1);

/**
 * iHymns — Admin: Content Restrictions
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * CRUD over `tblContentRestrictions` — the rule-based lockout system that
 * decides, per song / songbook / feature, whether a given user on a given
 * platform can access the entity. Pairs with the evaluator in
 * appWeb/public_html/includes/content_access.php.
 *
 * Rule model (see schema.sql:438):
 *   EntityType      — song | songbook | feature
 *   EntityId        — concrete ID, or '*' to apply to every entity of the type
 *   RestrictionType — block_platform | block_user | block_org
 *                     | require_licence | require_org
 *   TargetType/Id   — what the rule applies against
 *   Effect          — allow | deny
 *   Priority        — higher wins; deny beats allow at equal priority
 *
 * Gated by the `manage_content_restrictions` entitlement.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'entitlements.php';

if (!isAuthenticated()) {
    header('Location: /manage/login');
    exit;
}
$currentUser = getCurrentUser();
if (!$currentUser || !userHasEntitlement('manage_content_restrictions', $currentUser['role'] ?? null)) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><body><h1>403 — manage_content_restrictions required</h1></body></html>';
    exit;
}
$activePage = 'restrictions';

$error   = '';
$success = '';
$db      = getDb();

/* Allowed vocabularies — kept tight so the UI can't POST rubbish the
   evaluator will silently ignore. Keep in sync with content_access.php. */
const RESTRICTIONS_ENTITY_TYPES = ['song', 'songbook', 'feature'];
const RESTRICTIONS_TYPES = [
    'block_platform' => 'Block platform',
    'block_user'     => 'Block user',
    'block_org'      => 'Block organisation',
    'require_licence'=> 'Require licence',
    'require_org'    => 'Require organisation',
];
const RESTRICTIONS_EFFECTS = ['deny', 'allow'];

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
                $entityType      = trim((string)($_POST['entity_type']      ?? ''));
                $entityId        = trim((string)($_POST['entity_id']        ?? ''));
                $restrictionType = trim((string)($_POST['restriction_type'] ?? ''));
                $targetType      = trim((string)($_POST['target_type']      ?? ''));
                $targetId        = trim((string)($_POST['target_id']        ?? ''));
                $effect          = trim((string)($_POST['effect']           ?? 'deny'));
                $priority        = (int)  ($_POST['priority']               ?? 0);
                $reason          = trim((string)($_POST['reason']           ?? ''));

                if (!in_array($entityType, RESTRICTIONS_ENTITY_TYPES, true)) {
                    $error = 'Invalid entity type.'; break;
                }
                if ($entityId === '') {
                    $error = 'Entity ID is required (use "*" to target every entity of the type).'; break;
                }
                if (!array_key_exists($restrictionType, RESTRICTIONS_TYPES)) {
                    $error = 'Invalid restriction type.'; break;
                }
                if (!in_array($effect, RESTRICTIONS_EFFECTS, true)) {
                    $error = 'Effect must be allow or deny.'; break;
                }
                if ($priority < 0 || $priority > 1000) {
                    $error = 'Priority must be between 0 and 1000.'; break;
                }

                $stmt = $db->prepare(
                    'INSERT INTO tblContentRestrictions
                        (EntityType, EntityId, RestrictionType, TargetType, TargetId, Effect, Priority, Reason)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $entityType, $entityId, $restrictionType,
                    $targetType, $targetId, $effect, $priority, $reason,
                ]);
                $success = 'Restriction created.';
                break;
            }

            case 'delete': {
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) { $error = 'Invalid request.'; break; }
                $stmt = $db->prepare('DELETE FROM tblContentRestrictions WHERE Id = ?');
                $stmt->execute([$id]);
                $success = 'Restriction removed.';
                break;
            }

            default:
                $error = 'Unknown action.';
        }
    } catch (\Throwable $e) {
        error_log('[manage/restrictions.php] ' . $e->getMessage());
        $error = $error ?: 'Database error — check server logs for details.';
    }
}

/* ----- GET: filters + rows ----- */
$filterEntity = (string)($_GET['entity_type'] ?? '');
$filterType   = (string)($_GET['restriction_type'] ?? '');

$rows = [];
try {
    $sql = 'SELECT Id, EntityType, EntityId, RestrictionType, TargetType, TargetId,
                   Effect, Priority, Reason, CreatedAt
              FROM tblContentRestrictions';
    $where = [];
    $args  = [];
    if ($filterEntity !== '' && in_array($filterEntity, RESTRICTIONS_ENTITY_TYPES, true)) {
        $where[] = 'EntityType = ?';
        $args[]  = $filterEntity;
    }
    if ($filterType !== '' && array_key_exists($filterType, RESTRICTIONS_TYPES)) {
        $where[] = 'RestrictionType = ?';
        $args[]  = $filterType;
    }
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY Priority DESC, CreatedAt DESC LIMIT 500';
    $stmt = $db->prepare($sql);
    $stmt->execute($args);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    error_log('[manage/restrictions.php] ' . $e->getMessage());
    $error = $error ?: 'Could not load restrictions.';
}

/* Summary counts per entity type for the header pills */
$counts = ['song' => 0, 'songbook' => 0, 'feature' => 0, 'total' => 0];
try {
    $rs = $db->query(
        'SELECT EntityType, COUNT(*) AS n
           FROM tblContentRestrictions
          GROUP BY EntityType'
    );
    foreach ($rs->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $t = (string)$r['EntityType'];
        if (isset($counts[$t])) $counts[$t] = (int)$r['n'];
        $counts['total'] += (int)$r['n'];
    }
} catch (\Throwable $e) { /* ignore */ }

/* Is the master gating switch enabled? Surface it prominently so admins
   don't wonder why their restrictions have no effect. */
$gatingEnabled = false;
try {
    $stmt = $db->prepare("SELECT SettingValue FROM tblAppSettings WHERE SettingKey = 'content_gating_enabled'");
    $stmt->execute();
    $gatingEnabled = ((string)($stmt->fetchColumn() ?: '0')) === '1';
} catch (\Throwable $e) { /* ignore */ }

$csrf = csrfToken();
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Content Restrictions — iHymns Admin</title>
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

        <h1 class="h4 mb-3"><i class="bi bi-shield-lock me-2"></i>Content Restrictions</h1>
        <p class="text-secondary small mb-3">
            Rule-based lockout for regular users: hide specific songs, whole songbooks, or app features
            based on platform, user, organisation, or licence. Rules evaluate by <code>Priority</code>
            (highest first); at equal priority, <em>deny</em> beats <em>allow</em>.
        </p>

        <?php if (!$gatingEnabled): ?>
            <div class="alert alert-warning py-2 mb-3">
                <i class="bi bi-exclamation-triangle me-1"></i>
                The master switch <code>content_gating_enabled</code> is <strong>OFF</strong>.
                Rules here are saved but currently have no runtime effect.
                Flip it in <a href="/manage/entitlements" class="alert-link">Entitlements &amp; Gating</a>
                (or directly in <code>tblAppSettings</code>).
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success py-2"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <!-- Summary pills -->
        <div class="d-flex flex-wrap gap-2 small mb-3">
            <span class="badge bg-secondary">Total <strong class="ms-1"><?= (int)$counts['total'] ?></strong></span>
            <span class="badge bg-info text-dark">Songs <strong class="ms-1"><?= (int)$counts['song'] ?></strong></span>
            <span class="badge bg-primary">Songbooks <strong class="ms-1"><?= (int)$counts['songbook'] ?></strong></span>
            <span class="badge bg-warning text-dark">Features <strong class="ms-1"><?= (int)$counts['feature'] ?></strong></span>
        </div>

        <!-- Filters -->
        <form method="GET" class="card-admin p-3 mb-3">
            <div class="row g-2 align-items-end">
                <div class="col-sm-4">
                    <label class="form-label small mb-1">Entity type</label>
                    <select name="entity_type" class="form-select form-select-sm">
                        <option value="">All</option>
                        <?php foreach (RESTRICTIONS_ENTITY_TYPES as $et): ?>
                            <option value="<?= htmlspecialchars($et) ?>" <?= $filterEntity === $et ? 'selected' : '' ?>>
                                <?= htmlspecialchars(ucfirst($et)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-sm-5">
                    <label class="form-label small mb-1">Restriction type</label>
                    <select name="restriction_type" class="form-select form-select-sm">
                        <option value="">All</option>
                        <?php foreach (RESTRICTIONS_TYPES as $k => $lbl): ?>
                            <option value="<?= htmlspecialchars($k) ?>" <?= $filterType === $k ? 'selected' : '' ?>>
                                <?= htmlspecialchars($lbl) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-sm-3 d-grid">
                    <button type="submit" class="btn btn-sm btn-outline-info">
                        <i class="bi bi-funnel me-1"></i>Apply filter
                    </button>
                </div>
            </div>
        </form>

        <!-- Rules list -->
        <div class="card-admin p-3 mb-4">
            <h2 class="h6 mb-3">Rules <span class="text-muted small">(<?= count($rows) ?> shown, newest first within priority)</span></h2>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr class="text-muted small">
                            <th>Entity</th>
                            <th>Restriction</th>
                            <th>Target</th>
                            <th class="text-center">Effect</th>
                            <th class="text-center">Priority</th>
                            <th>Reason</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                            <tr>
                                <td>
                                    <span class="badge bg-secondary"><?= htmlspecialchars($r['EntityType']) ?></span>
                                    <code class="ms-1"><?= htmlspecialchars($r['EntityId']) ?></code>
                                </td>
                                <td>
                                    <?= htmlspecialchars(RESTRICTIONS_TYPES[$r['RestrictionType']] ?? $r['RestrictionType']) ?>
                                </td>
                                <td class="small text-muted">
                                    <?php if ($r['TargetType'] !== '' || $r['TargetId'] !== ''): ?>
                                        <?= htmlspecialchars($r['TargetType']) ?>
                                        <?php if ($r['TargetId'] !== ''): ?>
                                            = <code><?= htmlspecialchars($r['TargetId']) ?></code>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($r['Effect'] === 'deny'): ?>
                                        <span class="badge bg-danger">deny</span>
                                    <?php else: ?>
                                        <span class="badge bg-success">allow</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center"><?= (int)$r['Priority'] ?></td>
                                <td class="small"><?= htmlspecialchars(mb_substr((string)$r['Reason'], 0, 140)) ?></td>
                                <td class="text-end">
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Delete this restriction?')">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int)$r['Id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger"
                                                title="Remove rule"
                                                aria-label="Remove restriction rule">
                                            <i class="bi bi-trash" aria-hidden="true"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$rows): ?>
                            <tr><td colspan="7" class="text-muted text-center py-4">
                                No restrictions match. Add one below to start gating content.
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
            <h2 class="h6 mb-3"><i class="bi bi-plus-circle me-2"></i>Add a restriction</h2>
            <div class="row g-2 mb-2">
                <div class="col-sm-3">
                    <label class="form-label small">Entity type</label>
                    <select name="entity_type" class="form-select form-select-sm" required>
                        <?php foreach (RESTRICTIONS_ENTITY_TYPES as $et): ?>
                            <option value="<?= htmlspecialchars($et) ?>"><?= htmlspecialchars(ucfirst($et)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-sm-3">
                    <label class="form-label small">Entity ID</label>
                    <input type="text" name="entity_id" class="form-control form-control-sm" maxlength="50" required
                           placeholder="e.g. CP-0001, MP, audio_playback, *">
                </div>
                <div class="col-sm-3">
                    <label class="form-label small">Restriction type</label>
                    <select name="restriction_type" class="form-select form-select-sm" required>
                        <?php foreach (RESTRICTIONS_TYPES as $k => $lbl): ?>
                            <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-sm-3">
                    <label class="form-label small">Effect</label>
                    <select name="effect" class="form-select form-select-sm">
                        <option value="deny" selected>deny</option>
                        <option value="allow">allow</option>
                    </select>
                </div>
            </div>
            <div class="row g-2 mb-2">
                <div class="col-sm-3">
                    <label class="form-label small">Target type</label>
                    <input type="text" name="target_type" class="form-control form-control-sm" maxlength="20"
                           placeholder="platform / user / org / licence_type">
                </div>
                <div class="col-sm-3">
                    <label class="form-label small">Target ID</label>
                    <input type="text" name="target_id" class="form-control form-control-sm" maxlength="50"
                           placeholder="PWA / Apple / Android / user-ID / org-ID / ccli">
                </div>
                <div class="col-sm-2">
                    <label class="form-label small">Priority</label>
                    <input type="number" name="priority" class="form-control form-control-sm"
                           min="0" max="1000" value="100">
                </div>
                <div class="col-sm-4">
                    <label class="form-label small">Reason (shown to user)</label>
                    <input type="text" name="reason" class="form-control form-control-sm" maxlength="255"
                           placeholder="e.g. Subscription required for this songbook">
                </div>
            </div>
            <button type="submit" class="btn btn-amber-solid btn-sm mt-2">
                <i class="bi bi-plus me-1"></i>Add rule
            </button>
            <p class="text-muted small mt-3 mb-0">
                <strong>Tips.</strong>
                Entity ID <code>*</code> matches every entity of the selected type.
                For <em>Require licence</em> set Target ID to the licence type (e.g. <code>ccli</code>).
                For <em>Require organisation</em> leave Target ID blank to require any org, or set an org ID to require a specific one.
            </p>
        </form>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
            integrity="sha384-zKzgIZcXU99qF1nNW9g+x1znB5NhCPs9qZeGzUnnFOaHJF9jCCKySBjq3vIKabk/"
            crossorigin="anonymous"></script>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>
</body>
</html>
