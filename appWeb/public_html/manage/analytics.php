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

/* -----------------------------------------------------------------
 * CSV export (#404). Any panel can be requested as download by
 * adding ?export=<panel>&range=<n>. We short-circuit before the HTML
 * render so the browser gets a CSV file.
 * ----------------------------------------------------------------- */
$exportPanel = (string)($_GET['export'] ?? '');
if ($exportPanel !== '') {
    $since = (new DateTime("-{$range} days"))->format('Y-m-d H:i:s');
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="ihymns-' . $exportPanel . '-' . $range . 'd.csv"');
    $fp = fopen('php://output', 'w');
    try {
        switch ($exportPanel) {
            case 'top_songs':
                fputcsv($fp, ['SongId', 'Title', 'SongbookAbbr', 'Number', 'Views']);
                $stmt = $db->prepare(
                    'SELECT h.SongId, s.Title, s.SongbookAbbr, s.Number, COUNT(*) AS views
                       FROM tblSongHistory h
                       LEFT JOIN tblSongs s ON s.SongId = h.SongId
                      WHERE h.ViewedAt >= ?
                      GROUP BY h.SongId
                      ORDER BY views DESC'
                );
                $stmt->execute([$since]);
                while ($row = $stmt->fetch(PDO::FETCH_NUM)) { fputcsv($fp, $row); }
                break;
            case 'top_books':
                fputcsv($fp, ['SongbookAbbr', 'Views']);
                $stmt = $db->prepare(
                    'SELECT s.SongbookAbbr, COUNT(*) AS views
                       FROM tblSongHistory h
                       JOIN tblSongs s ON s.SongId = h.SongId
                      WHERE h.ViewedAt >= ?
                      GROUP BY s.SongbookAbbr
                      ORDER BY views DESC'
                );
                $stmt->execute([$since]);
                while ($row = $stmt->fetch(PDO::FETCH_NUM)) { fputcsv($fp, $row); }
                break;
            case 'searches':
                fputcsv($fp, ['Query', 'ResultCount', 'Hits']);
                try {
                    $stmt = $db->prepare(
                        'SELECT Query, ResultCount, COUNT(*) AS hits
                           FROM tblSearchQueries
                          WHERE SearchedAt >= ?
                          GROUP BY Query, ResultCount
                          ORDER BY hits DESC'
                    );
                    $stmt->execute([$since]);
                    while ($row = $stmt->fetch(PDO::FETCH_NUM)) { fputcsv($fp, $row); }
                } catch (\Throwable $_e) { /* table absent */ }
                break;
            default:
                fputcsv($fp, ['Unknown panel: ' . $exportPanel]);
        }
    } finally {
        fclose($fp);
    }
    exit;
}

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
        <span class="text-muted small d-none d-md-inline"><?= htmlspecialchars($currentUser['username'] ?? '') ?></span>
        <a href="/manage/" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-speedometer2 me-1"></i>Dashboard
        </a>
        <a href="/" class="btn btn-sm btn-outline-secondary" title="Back to the iHymns app home">
            <i class="bi bi-house me-1"></i>Home
        </a>
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
        <div class="d-flex align-items-center justify-content-between mb-3">
            <h2 class="h6 mb-0"><i class="bi bi-fire me-2"></i>Top songs</h2>
            <a class="btn btn-sm btn-outline-secondary" href="?range=<?= (int)$range ?>&export=top_songs">CSV</a>
        </div>
        <?php if (!$topSongs): ?>
            <p class="text-muted small mb-0">No song views in this period yet.</p>
        <?php else: ?>
            <table class="table table-sm table-hover mb-0">
                <thead><tr><th>Song</th><th>Songbook</th><th class="text-end">Views</th></tr></thead>
                <tbody>
                <?php foreach ($topSongs as $s):
                    $num = $s['Number'] ?? null;
                    $numLabel = ($num !== null && (int)$num > 0) ? ' #' . (int)$num : '';
                ?>
                    <tr>
                        <td><?= htmlspecialchars($s['Title'] ?? $s['SongId']) ?></td>
                        <td class="text-muted"><?= htmlspecialchars(($s['SongbookAbbr'] ?? '—') . $numLabel) ?></td>
                        <td class="text-end"><?= number_format((int)$s['views']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- Top search queries -->
    <div class="card-admin p-3 mb-4">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <h2 class="h6 mb-0"><i class="bi bi-search me-2"></i>Top search queries</h2>
            <a class="btn btn-sm btn-outline-secondary" href="?range=<?= (int)$range ?>&export=searches">CSV</a>
        </div>
        <?php
            $topSearches = [];
            $zeroResults = [];
            try {
                $stmt = $db->prepare(
                    'SELECT Query, COUNT(*) AS hits, MAX(ResultCount) AS top_count
                       FROM tblSearchQueries
                      WHERE SearchedAt >= ? AND Query <> ""
                      GROUP BY Query
                      ORDER BY hits DESC
                      LIMIT 15'
                );
                $stmt->execute([$since]);
                $topSearches = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $stmt = $db->prepare(
                    'SELECT Query, COUNT(*) AS hits
                       FROM tblSearchQueries
                      WHERE SearchedAt >= ? AND ResultCount = 0 AND Query <> ""
                      GROUP BY Query
                      ORDER BY hits DESC
                      LIMIT 10'
                );
                $stmt->execute([$since]);
                $zeroResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (\Throwable $_e) {}
        ?>
        <?php if (!$topSearches): ?>
            <p class="text-muted small mb-0">No search activity yet (or the tblSearchQueries table hasn't been created — re-run install).</p>
        <?php else: ?>
            <table class="table table-sm table-hover mb-0">
                <thead><tr><th>Query</th><th class="text-end">Hits</th></tr></thead>
                <tbody>
                <?php foreach ($topSearches as $s): ?>
                    <tr>
                        <td><code><?= htmlspecialchars($s['Query']) ?></code></td>
                        <td class="text-end"><?= number_format((int)$s['hits']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- Zero-result queries -->
    <?php if ($zeroResults): ?>
    <div class="card-admin p-3 mb-4 border-warning">
        <h2 class="h6 mb-3"><i class="bi bi-question-circle me-2"></i>Zero-result queries — candidates for tagging or new songs</h2>
        <ul class="list-unstyled mb-0 small">
            <?php foreach ($zeroResults as $z): ?>
                <li><code><?= htmlspecialchars($z['Query']) ?></code> <span class="text-muted">× <?= (int)$z['hits'] ?></span></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <!-- Top songbooks -->
    <div class="card-admin p-3 mb-4">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <h2 class="h6 mb-0"><i class="bi bi-book me-2"></i>Songbook opens</h2>
            <a class="btn btn-sm btn-outline-secondary" href="?range=<?= (int)$range ?>&export=top_books">CSV</a>
        </div>
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

<?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>
</body>
</html>
