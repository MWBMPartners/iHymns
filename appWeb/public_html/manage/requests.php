<?php

declare(strict_types=1);

/**
 * iHymns — Admin Song-Request Queue (#403)
 *
 * Admin triage view for user-submitted song requests in
 * tblSongRequests. Supports filtering by status, per-row status
 * change, and "Start editing" which generates a new song-id draft
 * and jumps into the editor with a query parameter linking back.
 *
 * Access: `review_song_requests` entitlement (editor / admin /
 * global_admin by default).
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'entitlements.php';

if (!isAuthenticated()) {
    header('Location: /manage/login');
    exit;
}
$currentUser = getCurrentUser();
if (!$currentUser || !userHasEntitlement('review_song_requests', $currentUser['role'] ?? null)) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><body><h1>403 — review_song_requests required</h1></body></html>';
    exit;
}

$statuses = ['pending', 'reviewed', 'added', 'declined'];
$filter   = (string)($_GET['status'] ?? 'pending');
if (!in_array($filter, $statuses, true) && $filter !== 'all') $filter = 'pending';

$flash = '';
$err   = '';

/* --- POST: update status / notes --- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrf((string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo 'Invalid CSRF token';
        exit;
    }
    $id           = (int)($_POST['id']            ?? 0);
    $newStatus    = (string)($_POST['new_status'] ?? '');
    $adminNotes   = trim((string)($_POST['admin_notes'] ?? ''));
    $resolvedSong = trim((string)($_POST['resolved_song_id'] ?? ''));

    if ($id <= 0 || !in_array($newStatus, $statuses, true)) {
        $err = 'Invalid request.';
    } else {
        try {
            $db = getDb();
            $stmt = $db->prepare(
                'UPDATE tblSongRequests
                    SET Status = ?, AdminNotes = ?, ResolvedSongId = ?
                  WHERE Id = ?'
            );
            $stmt->execute([$newStatus, $adminNotes, $resolvedSong ?: null, $id]);
            $flash = 'Request #' . $id . ' updated.';
        } catch (\Throwable $e) {
            error_log('[manage/requests.php] ' . $e->getMessage());
            $err = 'Database error — check server logs for details.';
        }
    }
}

/* --- GET: fetch rows --- */
$rows = [];
try {
    $db = getDb();
    if ($filter === 'all') {
        $stmt = $db->query(
            'SELECT r.*, u.Username AS requested_by
               FROM tblSongRequests r
               LEFT JOIN tblUsers u ON u.Id = r.UserId
              ORDER BY r.CreatedAt DESC
              LIMIT 500'
        );
    } else {
        $stmt = $db->prepare(
            'SELECT r.*, u.Username AS requested_by
               FROM tblSongRequests r
               LEFT JOIN tblUsers u ON u.Id = r.UserId
              WHERE r.Status = ?
              ORDER BY r.CreatedAt DESC
              LIMIT 500'
        );
        $stmt->execute([$filter]);
    }
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    error_log('[manage/requests.php] ' . $e->getMessage());
    $err = 'Could not load requests — check server logs for details.';
}

$counts = [];
try {
    $cs = $db->query('SELECT Status, COUNT(*) AS cnt FROM tblSongRequests GROUP BY Status');
    while ($row = $cs->fetch(PDO::FETCH_ASSOC)) {
        $counts[$row['Status']] = (int)$row['cnt'];
    }
} catch (\Throwable $_e) {}

?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Song Requests — iHymns Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
          integrity="sha384-XGjxtQfXaH2tnPFa9x+ruJTuLE3Aa6LhHSWRr1XeTyhezb4abCG4ccI5AkVDxqC+" crossorigin="anonymous">
    <link rel="stylesheet" href="/css/app.css?v=<?= filemtime(dirname(__DIR__) . '/css/app.css') ?>">
    <link rel="stylesheet" href="/css/admin.css?v=<?= filemtime(dirname(__DIR__) . '/css/admin.css') ?>">
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-favicon.php'; ?>
</head>
<body>

<nav class="navbar-admin d-flex align-items-center justify-content-between">
    <a class="navbar-brand" href="/manage/"><i class="bi bi-lightbulb me-2"></i>Song Requests</a>
    <div class="d-flex align-items-center gap-2">
        <a href="/manage/" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-speedometer2 me-1"></i>Dashboard
        </a>
        <a href="/" class="btn btn-sm btn-outline-secondary" title="Back to the iHymns app home">
            <i class="bi bi-house me-1"></i>Home
        </a>
    </div>
</nav>

<div class="container-admin py-4">

    <?php if ($flash): ?>
        <div class="alert alert-success py-2"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>
    <?php if ($err): ?>
        <div class="alert alert-danger py-2"><?= htmlspecialchars($err) ?></div>
    <?php endif; ?>

    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <h1 class="h4 mb-0">User-submitted song requests</h1>
        <div class="btn-group btn-group-sm" role="group">
            <?php foreach (array_merge($statuses, ['all']) as $s): ?>
                <?php $active = $filter === $s ? 'btn-amber-solid' : 'btn-outline-secondary'; ?>
                <a class="btn <?= $active ?>" href="?status=<?= htmlspecialchars($s) ?>">
                    <?= htmlspecialchars(ucfirst($s)) ?>
                    <?php if (isset($counts[$s])): ?>
                        <span class="badge bg-body-secondary ms-1"><?= $counts[$s] ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if (!$rows): ?>
        <div class="card-admin p-4 text-center text-muted">No requests in this bucket.</div>
    <?php else: ?>
        <div class="card-admin p-0">
            <table class="table table-sm table-hover mb-0">
                <thead>
                    <tr class="text-muted small">
                        <th>#</th>
                        <th>Title</th>
                        <th>Songbook</th>
                        <th>Submitted</th>
                        <th>By</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td class="text-muted"><?= (int)$r['Id'] ?></td>
                        <td>
                            <strong><?= htmlspecialchars($r['Title']) ?></strong>
                            <?php if (!empty($r['Details'])): ?>
                                <div class="small text-muted"><?= nl2br(htmlspecialchars(mb_substr($r['Details'], 0, 160))) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="text-muted"><?= htmlspecialchars($r['Songbook'] ?: '—') ?></td>
                        <td class="text-muted small">
                            <?= htmlspecialchars(substr((string)$r['CreatedAt'], 0, 16)) ?>
                        </td>
                        <td class="text-muted">
                            <?php if (!empty($r['requested_by'])): ?>
                                @<?= htmlspecialchars($r['requested_by']) ?>
                            <?php elseif (!empty($r['ContactEmail'])): ?>
                                <a href="mailto:<?= htmlspecialchars($r['ContactEmail']) ?>"><?= htmlspecialchars($r['ContactEmail']) ?></a>
                            <?php else: ?>
                                anon
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge bg-<?= [
                                'pending' => 'warning text-dark',
                                'reviewed' => 'info',
                                'added' => 'success',
                                'declined' => 'secondary',
                            ][$r['Status']] ?? 'secondary' ?>">
                                <?= htmlspecialchars($r['Status']) ?>
                            </span>
                        </td>
                        <td class="text-end">
                            <button class="btn btn-sm btn-outline-primary" data-bs-toggle="collapse"
                                    data-bs-target="#row-<?= (int)$r['Id'] ?>">
                                Review
                            </button>
                        </td>
                    </tr>
                    <tr class="collapse" id="row-<?= (int)$r['Id'] ?>">
                        <td colspan="7" class="bg-body-secondary p-3">
                            <form method="post" class="row g-2">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                                <input type="hidden" name="id" value="<?= (int)$r['Id'] ?>">
                                <div class="col-md-3">
                                    <label class="form-label small">Status</label>
                                    <select name="new_status" class="form-select form-select-sm">
                                        <?php foreach ($statuses as $s): ?>
                                            <option value="<?= $s ?>" <?= $r['Status'] === $s ? 'selected' : '' ?>>
                                                <?= htmlspecialchars(ucfirst($s)) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label small">Resolved SongId (optional)</label>
                                    <input type="text" name="resolved_song_id" class="form-control form-control-sm"
                                           value="<?= htmlspecialchars((string)($r['ResolvedSongId'] ?? '')) ?>"
                                           placeholder="e.g. MP-1234">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small">Admin notes</label>
                                    <input type="text" name="admin_notes" class="form-control form-control-sm"
                                           value="<?= htmlspecialchars($r['AdminNotes'] ?? '') ?>">
                                </div>
                                <div class="col-12 d-flex gap-2 mt-2">
                                    <button type="submit" class="btn btn-sm btn-amber-solid">Save</button>
                                    <a href="/manage/editor/" class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-pencil-square me-1"></i>Open editor
                                    </a>
                                </div>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-zKzgIZcXU99qF1nNW9g+x1znB5NhCPs9qZeGzUnnFOaHJF9jCCKySBjq3vIKabk/"
        crossorigin="anonymous"></script>
<?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>
</body>
</html>
