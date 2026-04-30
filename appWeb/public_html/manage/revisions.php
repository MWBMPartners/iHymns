<?php

declare(strict_types=1);

/**
 * iHymns — Admin Revisions Audit (#400)
 *
 * Global revision log across every song. Filters by user, date range,
 * song id, and action. Each row links into the editor with the song
 * pre-selected (the existing History modal there supplies the full
 * diff + restore flow).
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_mysql.php';

requireAuth();
$currentUser = getCurrentUser();
if (!$currentUser || !userHasEntitlement('verify_songs', $currentUser['role'] ?? null)) {
    http_response_code(403);
    exit('Access denied. The verify_songs entitlement is required.');
}

$activePage = 'revisions';
$db = getDbMysqli();

$filterUser   = trim((string)($_GET['user']   ?? ''));
$filterSong   = trim((string)($_GET['song']   ?? ''));
$filterAction = trim((string)($_GET['action'] ?? ''));
$filterDays   = (int)($_GET['days']   ?? 30);
if (!in_array($filterDays, [7, 30, 90, 365], true)) { $filterDays = 30; }
$since = (new DateTime("-{$filterDays} days"))->format('Y-m-d H:i:s');

/* mysqli takes positional `?` placeholders, not named ones — and a
   placeholder repeated in the SQL needs the value bound twice (PDO
   allowed reusing :user across multiple positions; mysqli doesn't).
   Build $params + $types in lock-step with the order the ?s appear
   in the WHERE clause. The same array is then passed to both the
   COUNT(*) and the main SELECT — they share the WHERE clause. */
$where  = ['r.CreatedAt >= ?'];
$params = [$since];
$types  = 's';
if ($filterUser !== '') {
    $where[]  = '(u.Username LIKE ? OR u.Email LIKE ?)';
    $userLike = '%' . $filterUser . '%';
    $params[] = $userLike;
    $params[] = $userLike;
    $types   .= 'ss';
}
if ($filterSong !== '') {
    $where[]  = 'r.SongId LIKE ?';
    $params[] = '%' . $filterSong . '%';
    $types   .= 's';
}
if (in_array($filterAction, ['create', 'edit', 'restore', 'delete'], true)) {
    $where[]  = 'r.Action = ?';
    $params[] = $filterAction;
    $types   .= 's';
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

$rows = [];
$total = 0;
try {
    $countStmt = $db->prepare(
        'SELECT COUNT(*)
           FROM tblSongRevisions r
           LEFT JOIN tblUsers u ON u.Id = r.UserId ' . $whereSql
    );
    $countStmt->bind_param($types, ...$params);
    $countStmt->execute();
    $row = $countStmt->get_result()->fetch_row();
    $total = (int)($row[0] ?? 0);
    $countStmt->close();

    $stmt = $db->prepare(
        'SELECT r.Id, r.SongId, r.Action, r.CreatedAt, r.UserId, u.Username,
                s.Title AS SongTitle, s.SongbookAbbr, s.Number
           FROM tblSongRevisions r
           LEFT JOIN tblUsers u ON u.Id = r.UserId
           LEFT JOIN tblSongs s ON s.SongId = r.SongId ' . $whereSql . '
           ORDER BY r.CreatedAt DESC, r.Id DESC
           LIMIT 200'
    );
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} catch (\Throwable $e) {
    error_log('[manage revisions] ' . $e->getMessage());
    logActivityError('admin.revisions.list', 'song_revision', '', $e);
    $rows = [];
}

?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Revisions — iHymns Admin</title>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-libs.php'; ?>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-favicon.php'; ?>
</head>
<body>

<?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-nav.php'; ?>

<div class="container-admin py-4">

    <div class="d-flex flex-wrap align-items-end justify-content-between mb-3 gap-2">
        <h1 class="h4 mb-0">Song edit revisions</h1>
        <span class="text-muted small">
            <?= number_format(count($rows)) ?> showing (of <?= number_format($total) ?> in this range)
        </span>
    </div>

    <form method="get" class="card-admin p-3 mb-3">
        <div class="row g-2 align-items-end">
            <div class="col-md-3">
                <label for="f-user" class="form-label small mb-1">User (name/email contains)</label>
                <input id="f-user" name="user" type="text" class="form-control form-control-sm"
                       value="<?= htmlspecialchars($filterUser) ?>" placeholder="e.g. salem">
            </div>
            <div class="col-md-3">
                <label for="f-song" class="form-label small mb-1">Song ID contains</label>
                <input id="f-song" name="song" type="text" class="form-control form-control-sm"
                       value="<?= htmlspecialchars($filterSong) ?>" placeholder="e.g. CP-">
            </div>
            <div class="col-md-2">
                <label for="f-action" class="form-label small mb-1">Action</label>
                <select id="f-action" name="action" class="form-select form-select-sm">
                    <option value="">All</option>
                    <?php foreach (['create', 'edit', 'restore', 'delete'] as $a): ?>
                        <option value="<?= $a ?>" <?= $filterAction === $a ? 'selected' : '' ?>><?= $a ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label for="f-days" class="form-label small mb-1">Last N days</label>
                <select id="f-days" name="days" class="form-select form-select-sm">
                    <?php foreach ([7, 30, 90, 365] as $d): ?>
                        <option value="<?= $d ?>" <?= $filterDays === $d ? 'selected' : '' ?>><?= $d ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-sm btn-amber-solid w-100">Filter</button>
                <a href="/manage/revisions" class="btn btn-sm btn-outline-secondary">Reset</a>
            </div>
        </div>
    </form>

    <div class="card-admin p-0">
        <?php if (!$rows): ?>
            <p class="text-muted p-4 mb-0">No revisions match these filters.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-dark table-hover mb-0 align-middle small">
                    <thead>
                        <tr>
                            <th scope="col">When</th>
                            <th scope="col">Action</th>
                            <th scope="col">Song</th>
                            <th scope="col">User</th>
                            <th scope="col" class="text-end">Revision</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r):
                            $badgeClass = match ($r['Action']) {
                                'create'  => 'bg-success',
                                'restore' => 'bg-info',
                                'delete'  => 'bg-danger',
                                default   => 'bg-secondary',
                            };
                        ?>
                            <tr>
                                <td class="text-muted"><?= htmlspecialchars($r['CreatedAt']) ?></td>
                                <td><span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($r['Action']) ?></span></td>
                                <td>
                                    <code><?= htmlspecialchars($r['SongId']) ?></code>
                                    <?php if ($r['SongTitle']): ?>
                                        <span class="text-muted">— <?= htmlspecialchars($r['SongTitle']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($r['Username'] ?? '—') ?></td>
                                <td class="text-end">
                                    <a class="btn btn-sm btn-outline-info"
                                       href="/manage/editor/?open=<?= urlencode($r['SongId']) ?>&tab=history"
                                       title="Open this song in the editor — the History modal will show every revision in full">
                                        Open in editor
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <p class="small text-muted mt-3 mb-0">
        <i class="bi bi-info-circle me-1"></i>
        This table shows the 200 most recent matching rows. Full diffs and
        the restore action live in the editor's History modal (click
        <strong>Open in editor</strong> on any row).
    </p>

</div>

<?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>

</body>
</html>
