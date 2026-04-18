<?php

declare(strict_types=1);

/**
 * iHymns — Admin Analytics Dashboard (#404)
 *
 * Read-only view of app-wide usage metrics. Pulls from the tables we
 * already populate (tblSongHistory, tblActivityLog, tblLoginAttempts)
 * so there's no new tracking surface.
 *
 * Access: admin or global_admin (see hasRole()). Time range is a GET
 * param `range` (7, 30 or 90 days; defaults to 30).
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';
requireAdmin();

$currentUser = getCurrentUser();

$range = (int)($_GET['range'] ?? 30);
if (!in_array($range, [7, 30, 90], true)) {
    $range = 30;
}
$since = (new DateTime("-{$range} days"))->format('Y-m-d H:i:s');

$db = getDb();

/* --- Top songs last $range days --- */
$topSongs = [];
try {
    $stmt = $db->prepare(
        'SELECT h.SongId, COUNT(*) AS views, s.Title, s.SongbookAbbr, s.Number
           FROM tblSongHistory h
           LEFT JOIN tblSongs s ON s.SongId = h.SongId
          WHERE h.ViewedAt >= ?
          GROUP BY h.SongId
          ORDER BY views DESC
          LIMIT 15'
    );
    $stmt->execute([$since]);
    $topSongs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) { /* table may be empty */ }

/* --- Top songbooks --- */
$topBooks = [];
try {
    $stmt = $db->prepare(
        'SELECT s.SongbookAbbr, COUNT(*) AS views
           FROM tblSongHistory h
           JOIN tblSongs s ON s.SongId = h.SongId
          WHERE h.ViewedAt >= ?
          GROUP BY s.SongbookAbbr
          ORDER BY views DESC'
    );
    $stmt->execute([$since]);
    $topBooks = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {}

/* --- New logins + active users --- */
$loginCount = 0;
$activeUsers = 0;
try {
    $stmt = $db->prepare('SELECT COUNT(*) FROM tblLoginAttempts WHERE Success = 1 AND AttemptedAt >= ?');
    $stmt->execute([$since]);
    $loginCount = (int)$stmt->fetchColumn();

    $stmt = $db->prepare('SELECT COUNT(DISTINCT UserId) FROM tblSongHistory WHERE ViewedAt >= ? AND UserId IS NOT NULL');
    $stmt->execute([$since]);
    $activeUsers = (int)$stmt->fetchColumn();
} catch (\Throwable $e) {}

/* --- Totals --- */
$totalUsers = 0;
try {
    $totalUsers = (int)$db->query('SELECT COUNT(*) FROM tblUsers WHERE IsActive = 1')->fetchColumn();
} catch (\Throwable $e) {}

$totalRequests = 0;
try {
    $totalRequests = (int)$db->query("SELECT COUNT(*) FROM tblSongRequests WHERE Status = 'pending'")->fetchColumn();
} catch (\Throwable $e) {}

?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics — iHymns Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
          integrity="sha384-XGjxtQfXaH2tnPFa9x+ruJTuLE3Aa6LhHSWRr1XeTyhezb4abCG4ccI5AkVDxqC+" crossorigin="anonymous">
    <link rel="stylesheet" href="/css/app.css?v=<?= filemtime(dirname(__DIR__) . '/css/app.css') ?>">
    <link rel="stylesheet" href="/css/admin.css?v=<?= filemtime(dirname(__DIR__) . '/css/admin.css') ?>">
</head>
<body>

<nav class="navbar-admin d-flex align-items-center justify-content-between">
    <a class="navbar-brand" href="/manage/"><i class="bi bi-chart-line me-2"></i>iHymns Analytics</a>
    <div class="d-flex align-items-center gap-2">
        <span class="text-muted small"><?= htmlspecialchars($currentUser['username'] ?? '') ?></span>
        <a href="/manage/" class="btn btn-sm btn-outline-secondary">Back to Dashboard</a>
    </div>
</nav>

<div class="container py-4" style="max-width: 1100px;">

    <div class="d-flex flex-wrap align-items-end justify-content-between mb-3 gap-2">
        <h1 class="h4 mb-0">Analytics — last <?= (int)$range ?> days</h1>
        <div class="btn-group" role="group" aria-label="Time range">
            <?php foreach ([7, 30, 90] as $r): ?>
                <a href="?range=<?= $r ?>" class="btn btn-sm <?= $r === $range ? 'btn-amber-solid' : 'btn-outline-secondary' ?>">
                    <?= $r ?>d
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Headline stats -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card-admin stat-card">
                <div class="stat-number"><?= number_format($totalUsers) ?></div>
                <div class="stat-label">Active users</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card-admin stat-card">
                <div class="stat-number"><?= number_format($activeUsers) ?></div>
                <div class="stat-label">Logged-in readers (<?= (int)$range ?>d)</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card-admin stat-card">
                <div class="stat-number"><?= number_format($loginCount) ?></div>
                <div class="stat-label">Logins (<?= (int)$range ?>d)</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card-admin stat-card">
                <div class="stat-number"><?= number_format($totalRequests) ?></div>
                <div class="stat-label">Pending song requests</div>
            </div>
        </div>
    </div>

    <!-- Top songs -->
    <div class="card-admin p-3 mb-4">
        <h2 class="h6 mb-3"><i class="bi bi-fire me-2"></i>Top songs</h2>
        <?php if (!$topSongs): ?>
            <p class="text-muted small mb-0">No song views in this period yet.</p>
        <?php else: ?>
            <table class="table table-sm table-hover mb-0">
                <thead><tr><th>Song</th><th>Songbook</th><th class="text-end">Views</th></tr></thead>
                <tbody>
                <?php foreach ($topSongs as $s): ?>
                    <tr>
                        <td><?= htmlspecialchars($s['Title'] ?? $s['SongId']) ?></td>
                        <td class="text-muted"><?= htmlspecialchars(($s['SongbookAbbr'] ?? '—') . ($s['Number'] ? ' #' . $s['Number'] : '')) ?></td>
                        <td class="text-end"><?= number_format((int)$s['views']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- Top songbooks -->
    <div class="card-admin p-3 mb-4">
        <h2 class="h6 mb-3"><i class="bi bi-book me-2"></i>Songbook opens</h2>
        <?php if (!$topBooks): ?>
            <p class="text-muted small mb-0">No data yet.</p>
        <?php else: ?>
            <table class="table table-sm table-hover mb-0">
                <thead><tr><th>Songbook</th><th class="text-end">Views</th></tr></thead>
                <tbody>
                <?php foreach ($topBooks as $b): ?>
                    <tr>
                        <td><?= htmlspecialchars($b['SongbookAbbr']) ?></td>
                        <td class="text-end"><?= number_format((int)$b['views']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <p class="text-secondary text-center small mt-4">
        iHymns Analytics · pulled live from <code>tblSongHistory</code>,
        <code>tblLoginAttempts</code>, <code>tblSongRequests</code>, <code>tblUsers</code>.
        No new tracking surface — no cookies beyond existing auth.
    </p>

</div>
</body>
</html>
