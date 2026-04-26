<?php

declare(strict_types=1);

/**
 * iHymns — Admin: Activity Log Viewer (#535)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * Filters tblActivityLog by action, user, entity, request id,
 * result, time window, and free-text. Pagination by limit/offset.
 * Optional CSV export.
 *
 * Admin / global_admin only — even though the rows themselves are
 * largely non-sensitive (see project-rules.md §10), the IP address
 * + UA + email columns warrant the gate.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';

requireAuth();
$currentUser = getCurrentUser();
if (!$currentUser || !in_array(($currentUser['role'] ?? ''), ['admin', 'global_admin'], true)) {
    http_response_code(403);
    exit('Access denied. Admin role required.');
}

$activePage = 'activity-log';
$db = getDb();

/* Filter parsing — every filter is optional. Defaults give the
   admin "last 24 h, every action, every user" landing view. */
$filterAction     = trim((string)($_GET['action']      ?? ''));
$filterUser       = trim((string)($_GET['user']        ?? ''));   /* free-text: matches Username or Email */
$filterResult     = trim((string)($_GET['result']      ?? ''));
$filterEntityType = trim((string)($_GET['entity_type'] ?? ''));
$filterEntityId   = trim((string)($_GET['entity_id']   ?? ''));
$filterRequestId  = trim((string)($_GET['request_id']  ?? ''));
$filterDays       = (int)($_GET['days'] ?? 1);
if (!in_array($filterDays, [1, 7, 30, 90, 365], true)) { $filterDays = 1; }
$filterSearch     = trim((string)($_GET['q'] ?? ''));

$since = (new DateTime("-{$filterDays} days"))->format('Y-m-d H:i:s');

$where  = ['a.CreatedAt >= :since'];
$params = [':since' => $since];

if ($filterAction !== '') {
    $where[] = 'a.Action = :action';
    $params[':action'] = $filterAction;
}
if ($filterUser !== '') {
    $where[] = '(u.Username LIKE :user OR u.Email LIKE :user)';
    $params[':user'] = '%' . $filterUser . '%';
}
if (in_array($filterResult, ['success', 'failure', 'error'], true)) {
    $where[] = 'a.Result = :result';
    $params[':result'] = $filterResult;
}
if ($filterEntityType !== '') {
    $where[] = 'a.EntityType = :entity_type';
    $params[':entity_type'] = $filterEntityType;
}
if ($filterEntityId !== '') {
    $where[] = 'a.EntityId = :entity_id';
    $params[':entity_id'] = $filterEntityId;
}
if ($filterRequestId !== '') {
    $where[] = 'a.RequestId = :request_id';
    $params[':request_id'] = $filterRequestId;
}
if ($filterSearch !== '') {
    $where[] = '(a.Action LIKE :search OR a.EntityId LIKE :search)';
    $params[':search'] = '%' . $filterSearch . '%';
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

/* CSV export — same filters, but streams every matching row to
   the browser instead of paginating. Capped at 10 000 rows so an
   accidental "everything" export doesn't OOM the worker. */
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="ihymns-activity-' . date('Ymd-His') . '.csv"');
    header('Cache-Control: no-store');

    $out = fopen('php://output', 'w');
    fputcsv($out, [
        'Id', 'CreatedAt', 'Username', 'Action', 'EntityType', 'EntityId',
        'Result', 'IpAddress', 'UserAgent', 'RequestId', 'Method', 'DurationMs', 'Details',
    ]);
    $stmt = $db->prepare(
        'SELECT a.Id, a.CreatedAt, u.Username, a.Action, a.EntityType, a.EntityId,
                a.Result, a.IpAddress, a.UserAgent, a.RequestId, a.Method,
                a.DurationMs, a.Details
           FROM tblActivityLog a
           LEFT JOIN tblUsers u ON u.Id = a.UserId
           ' . $whereSql . '
           ORDER BY a.CreatedAt DESC
           LIMIT 10000'
    );
    $stmt->execute($params);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($out, [
            $row['Id'], $row['CreatedAt'], $row['Username'] ?? '', $row['Action'],
            $row['EntityType'], $row['EntityId'], $row['Result'],
            $row['IpAddress'], $row['UserAgent'] ?? '', $row['RequestId'] ?? '',
            $row['Method'] ?? '', $row['DurationMs'] ?? '', $row['Details'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

/* Paginated list query for the UI. */
$pageSize = 50;
$page     = max(1, (int)($_GET['page'] ?? 1));
$offset   = ($page - 1) * $pageSize;

$total = 0;
$rows  = [];
try {
    $countStmt = $db->prepare(
        'SELECT COUNT(*)
           FROM tblActivityLog a
           LEFT JOIN tblUsers u ON u.Id = a.UserId ' . $whereSql
    );
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $db->prepare(
        'SELECT a.Id, a.CreatedAt, a.Action, a.EntityType, a.EntityId, a.Result,
                a.Details, a.IpAddress, a.UserAgent, a.RequestId, a.Method,
                a.DurationMs, u.Username
           FROM tblActivityLog a
           LEFT JOIN tblUsers u ON u.Id = a.UserId
           ' . $whereSql . '
           ORDER BY a.CreatedAt DESC
           LIMIT ' . (int)$pageSize . ' OFFSET ' . (int)$offset
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    error_log('[manage/activity-log.php] ' . $e->getMessage());
}

$totalPages = $total > 0 ? (int)ceil($total / $pageSize) : 1;

/* Distinct action names for the action-filter dropdown — keeps
   the dropdown self-populating as new event types appear, no
   hand-maintained list to drift. */
$distinctActions = [];
try {
    $stmt = $db->query("SELECT DISTINCT Action FROM tblActivityLog WHERE CreatedAt >= '" . addslashes($since) . "' ORDER BY Action ASC");
    $distinctActions = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (\Throwable $_e) { /* empty list is fine */ }

$resultBadgeClass = [
    'success' => 'bg-success',
    'failure' => 'bg-warning text-dark',
    'error'   => 'bg-danger',
];

/* Helper for keeping all current filters when generating the
   "next page" / "export csv" links — saves writing them out
   manually at every callsite. */
$buildQuery = function (array $overrides = []) {
    $merged = array_filter(array_merge([
        'action'      => $_GET['action']      ?? '',
        'user'        => $_GET['user']        ?? '',
        'result'      => $_GET['result']      ?? '',
        'entity_type' => $_GET['entity_type'] ?? '',
        'entity_id'   => $_GET['entity_id']   ?? '',
        'request_id'  => $_GET['request_id']  ?? '',
        'days'        => $_GET['days']        ?? '',
        'q'           => $_GET['q']           ?? '',
        'page'        => $_GET['page']        ?? '',
    ], $overrides), fn($v) => $v !== '' && $v !== null);
    return http_build_query($merged);
};
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activity Log — iHymns Admin</title>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-libs.php'; ?>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-favicon.php'; ?>
    <style>
        .activity-row td { vertical-align: middle; font-size: 0.875rem; }
        .activity-action { font-family: ui-monospace, SFMono-Regular, monospace; font-size: 0.85rem; }
        .activity-details {
            background: rgba(255, 255, 255, 0.03);
            border-radius: 4px;
            padding: 0.5rem;
            font-family: ui-monospace, SFMono-Regular, monospace;
            font-size: 0.75rem;
            max-height: 240px;
            overflow: auto;
            white-space: pre-wrap;
            word-break: break-word;
        }
        .request-id-pill {
            font-family: ui-monospace, SFMono-Regular, monospace;
            font-size: 0.7rem;
            opacity: 0.6;
        }
    </style>
</head>
<body>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-nav.php'; ?>

    <div class="container-admin py-4">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <h1 class="h4 mb-0"><i class="bi bi-activity me-2"></i>Activity Log</h1>
            <a class="btn btn-sm btn-outline-secondary"
               href="?<?= htmlspecialchars($buildQuery(['export' => 'csv']), ENT_QUOTES, 'UTF-8') ?>">
                <i class="bi bi-download me-1"></i>Export CSV
            </a>
        </div>
        <p class="text-secondary small mb-4">
            Audit trail (#535) of every meaningful action — auth events,
            admin CRUD, user activity, API requests, system events.
            Rows are kept indefinitely by default — the daily cleanup
            job only prunes if an admin sets
            <code>tblAppSettings.activity_log_retention_days</code> to
            a positive integer (1..3650). Useful for storage-pressure
            relief or GDPR-style compliance windows; left unset, the
            full history is preserved.
        </p>

        <form method="GET" class="row g-2 mb-3 small">
            <div class="col-md-3">
                <label class="form-label mb-1">Action</label>
                <select class="form-select form-select-sm" name="action">
                    <option value="">— any —</option>
                    <?php foreach ($distinctActions as $a): ?>
                        <option value="<?= htmlspecialchars($a, ENT_QUOTES, 'UTF-8') ?>"
                                <?= $filterAction === $a ? 'selected' : '' ?>>
                            <?= htmlspecialchars($a, ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label mb-1">User</label>
                <input class="form-control form-control-sm" type="text" name="user"
                       value="<?= htmlspecialchars($filterUser, ENT_QUOTES, 'UTF-8') ?>"
                       placeholder="username or email">
            </div>
            <div class="col-md-2">
                <label class="form-label mb-1">Result</label>
                <select class="form-select form-select-sm" name="result">
                    <option value="">— any —</option>
                    <option value="success" <?= $filterResult === 'success' ? 'selected' : '' ?>>success</option>
                    <option value="failure" <?= $filterResult === 'failure' ? 'selected' : '' ?>>failure</option>
                    <option value="error"   <?= $filterResult === 'error'   ? 'selected' : '' ?>>error</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label mb-1">Entity</label>
                <input class="form-control form-control-sm" type="text" name="entity_type"
                       value="<?= htmlspecialchars($filterEntityType, ENT_QUOTES, 'UTF-8') ?>"
                       placeholder="song, user, songbook, …">
            </div>
            <div class="col-md-2">
                <label class="form-label mb-1">Entity ID</label>
                <input class="form-control form-control-sm" type="text" name="entity_id"
                       value="<?= htmlspecialchars($filterEntityId, ENT_QUOTES, 'UTF-8') ?>"
                       placeholder="CP-0001, 42, …">
            </div>
            <div class="col-md-1">
                <label class="form-label mb-1">Window</label>
                <select class="form-select form-select-sm" name="days">
                    <option value="1"   <?= $filterDays === 1   ? 'selected' : '' ?>>24 h</option>
                    <option value="7"   <?= $filterDays === 7   ? 'selected' : '' ?>>7 d</option>
                    <option value="30"  <?= $filterDays === 30  ? 'selected' : '' ?>>30 d</option>
                    <option value="90"  <?= $filterDays === 90  ? 'selected' : '' ?>>90 d</option>
                    <option value="365" <?= $filterDays === 365 ? 'selected' : '' ?>>1 yr</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label mb-1">Search</label>
                <input class="form-control form-control-sm" type="text" name="q"
                       value="<?= htmlspecialchars($filterSearch, ENT_QUOTES, 'UTF-8') ?>"
                       placeholder="action or entity id substring">
            </div>
            <div class="col-md-3">
                <label class="form-label mb-1">Request ID</label>
                <input class="form-control form-control-sm" type="text" name="request_id"
                       value="<?= htmlspecialchars($filterRequestId, ENT_QUOTES, 'UTF-8') ?>"
                       placeholder="16-char hex">
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button class="btn btn-amber btn-sm w-100" type="submit">
                    <i class="bi bi-funnel me-1"></i>Apply
                </button>
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <a class="btn btn-outline-secondary btn-sm w-100" href="/manage/activity-log">
                    <i class="bi bi-x-circle me-1"></i>Reset
                </a>
            </div>
        </form>

        <p class="text-secondary small mb-2">
            <?= number_format($total) ?> entr<?= $total === 1 ? 'y' : 'ies' ?> match —
            page <?= $page ?> of <?= $totalPages ?>.
        </p>

        <?php if (empty($rows)): ?>
            <div class="alert alert-info py-2" role="status">
                No matching activity in the selected window.
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-dark table-hover align-middle">
                    <thead>
                        <tr>
                            <th style="width: 11rem;">When (UTC)</th>
                            <th style="width: 10rem;">User</th>
                            <th>Action</th>
                            <th>Entity</th>
                            <th style="width: 5rem;">Result</th>
                            <th style="width: 9rem;">IP</th>
                            <th style="width: 5rem;">Req</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $r):
                        $detailsRaw = $r['Details'];
                        $detailsArr = $detailsRaw !== null ? json_decode((string)$detailsRaw, true) : null;
                        $detailsPretty = $detailsArr !== null
                            ? json_encode($detailsArr, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                            : '';
                        $rowId = (int)$r['Id'];
                    ?>
                        <tr class="activity-row">
                            <td class="text-muted small">
                                <?= htmlspecialchars((string)$r['CreatedAt'], ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td>
                                <?php if (!empty($r['Username'])): ?>
                                    <span><?= htmlspecialchars((string)$r['Username'], ENT_QUOTES, 'UTF-8') ?></span>
                                <?php else: ?>
                                    <em class="text-muted">— anon —</em>
                                <?php endif; ?>
                            </td>
                            <td class="activity-action">
                                <?= htmlspecialchars((string)$r['Action'], ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td class="small">
                                <?php if ($r['EntityType'] !== '' || $r['EntityId'] !== ''): ?>
                                    <code><?= htmlspecialchars((string)$r['EntityType'], ENT_QUOTES, 'UTF-8') ?></code>
                                    <span class="text-muted">/</span>
                                    <code><?= htmlspecialchars((string)$r['EntityId'], ENT_QUOTES, 'UTF-8') ?></code>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge <?= $resultBadgeClass[$r['Result']] ?? 'bg-secondary' ?>">
                                    <?= htmlspecialchars((string)$r['Result'], ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </td>
                            <td class="text-muted small">
                                <?= htmlspecialchars((string)$r['IpAddress'], ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td>
                                <a class="request-id-pill"
                                   href="?<?= htmlspecialchars($buildQuery(['request_id' => $r['RequestId']]), ENT_QUOTES, 'UTF-8') ?>"
                                   title="Show every row from this HTTP request">
                                    <?= htmlspecialchars(substr((string)$r['RequestId'], 0, 8), ENT_QUOTES, 'UTF-8') ?>
                                </a>
                            </td>
                        </tr>
                        <?php if ($detailsPretty !== '' || ($r['UserAgent'] ?? '') !== '' || $r['DurationMs'] !== null): ?>
                        <tr class="activity-row">
                            <td colspan="7" class="bg-black-soft border-0 py-2">
                                <div class="small text-muted mb-1">
                                    <?php if ($r['Method'] !== ''): ?>
                                        <code><?= htmlspecialchars((string)$r['Method'], ENT_QUOTES, 'UTF-8') ?></code>
                                    <?php endif; ?>
                                    <?php if ($r['DurationMs'] !== null): ?>
                                        · <?= (int)$r['DurationMs'] ?> ms
                                    <?php endif; ?>
                                    <?php if (!empty($r['UserAgent'])): ?>
                                        · UA: <span title="<?= htmlspecialchars((string)$r['UserAgent'], ENT_QUOTES, 'UTF-8') ?>">
                                            <?= htmlspecialchars(substr((string)$r['UserAgent'], 0, 80), ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($detailsPretty !== ''): ?>
                                    <div class="activity-details"><?= htmlspecialchars($detailsPretty, ENT_QUOTES, 'UTF-8') ?></div>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($totalPages > 1): ?>
            <nav aria-label="Activity log pagination" class="mt-3">
                <ul class="pagination pagination-sm">
                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link"
                           href="?<?= htmlspecialchars($buildQuery(['page' => max(1, $page - 1)]), ENT_QUOTES, 'UTF-8') ?>">
                            « Prev
                        </a>
                    </li>
                    <li class="page-item disabled">
                        <span class="page-link">Page <?= $page ?> / <?= $totalPages ?></span>
                    </li>
                    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                        <a class="page-link"
                           href="?<?= htmlspecialchars($buildQuery(['page' => min($totalPages, $page + 1)]), ENT_QUOTES, 'UTF-8') ?>">
                            Next »
                        </a>
                    </li>
                </ul>
            </nav>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>
</body>
</html>
