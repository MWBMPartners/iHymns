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
requireAdmin();

$currentUser = getCurrentUser();
$db = getDb();

$filterUser   = trim((string)($_GET['user']   ?? ''));
$filterSong   = trim((string)($_GET['song']   ?? ''));
$filterAction = trim((string)($_GET['action'] ?? ''));
$filterDays   = (int)($_GET['days']   ?? 30);
if (!in_array($filterDays, [7, 30, 90, 365], true)) { $filterDays = 30; }
$since = (new DateTime("-{$filterDays} days"))->format('Y-m-d H:i:s');

$where  = ['r.CreatedAt >= :since'];
$params = [':since' => $since];
if ($filterUser !== '') {
    $where[] = '(u.Username LIKE :user OR u.Email LIKE :user)';
    $params[':user'] = '%' . $filterUser . '%';
}
if ($filterSong !== '') {
    $where[] = 'r.SongId LIKE :song';
    $params[':song'] = '%' . $filterSong . '%';
}
if (in_array($filterAction, ['create', 'edit', 'restore', 'delete'], true)) {
    $where[] = 'r.Action = :action';
    $params[':action'] = $filterAction;
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
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $db->prepare(
        'SELECT r.Id, r.SongId, r.Action, r.CreatedAt, r.UserId, u.Username,
                s.Title AS SongTitle, s.SongbookAbbr, s.Number
           FROM tblSongRevisions r
           LEFT JOIN tblUsers u ON u.Id = r.UserId
           LEFT JOIN tblSongs s ON s.SongId = r.SongId ' . $whereSql . '
           ORDER BY r.CreatedAt DESC, r.Id DESC
           LIMIT 200'
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    error_log('[manage revisions] ' . $e->getMessage());
    $rows = [];
}

?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Revisions — iHymns Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
          integrity="sha384-XGjxtQfXaH2tnPFa9x+ruJTuLE3Aa6LhHSWRr1XeTyhezb4abCG4ccI5AkVDxqC+" crossorigin="anonymous">
    <link rel="stylesheet" href="/css/app.css?v=<?= filemtime(dirname(__DIR__) . '/css/app.css') ?>">
    <link rel="stylesheet" href="/css/admin.css?v=<?= filemtime(dirname(__DIR__) . '/css/admin.css') ?>">
</head>
<body>

<nav class="navbar-admin d-flex align-items-center justify-content-between">
    <a class="navbar-brand" href="/manage/"><i class="bi bi-clock-history me-2"></i>iHymns Revisions</a>
    <div class="d-flex align-items-center gap-2">
        <span class="text-muted small d-none d-md-inline"><?= htmlspecialchars($currentUser['username'] ?? '') ?></span>
        <a href="/manage/" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-speedometer2 me-1"></i>Dashboard
        </a>
        <a href="/" class="btn btn-sm btn-outline-secondary" title="Back to the iHymns app home">
            <i class="bi bi-house me-1"></i>Home
        </a>
    </div>
</nav>

<div class="container py-4" style="max-width: 1200px;">

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

</body>
</html>
