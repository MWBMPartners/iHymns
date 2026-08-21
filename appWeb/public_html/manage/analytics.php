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
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_mysql.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'csv_safe.php'; // ihymns_fputcsv() — CSV formula-injection neutraliser
requireAuth();
$currentUser = getCurrentUser();
/* Gate on the SAME entitlement the nav advertises (#1648 item 1).
   ELI5: the menu says this page needs the "view_analytics" permission, so the page must
   check that exact permission rather than a role that happens to match it today.
   Detail: the gate here was role-based while admin-links.php advertises
   `view_analytics`, which is admin-editable at runtime via /manage/entitlements. A
   narrowed override hid the nav link while the page still admitted the old
   role — the UI presented a revocation that never happened. The default map for
   `view_analytics` is exactly the role set this replaced, so behaviour is unchanged
   until someone overrides it. Rule #1587's red flag. */
if (!$currentUser || !userHasEntitlement('view_analytics', $currentUser['role'] ?? null)) {
    http_response_code(403);
    exit('Access denied. The view_analytics entitlement is required.');
}

$activePage  = 'analytics';
$currentUser = getCurrentUser();

$range = (int)($_GET['range'] ?? 30);
if (!in_array($range, [7, 30, 90], true)) {
    $range = 30;
}
$since = (new DateTime("-{$range} days"))->format('Y-m-d H:i:s');

$db = getDbMysqli();

/* -----------------------------------------------------------------
 * CSV export (#404). Any panel can be requested as download by
 * adding ?export=<panel>&range=<n>. We short-circuit before the HTML
 * render so the browser gets a CSV file.
 * ----------------------------------------------------------------- */
$exportPanel = (string)($_GET['export'] ?? '');
if ($exportPanel !== '') {
    $since = (new DateTime("-{$range} days"))->format('Y-m-d H:i:s');
    /* SECURITY: $exportPanel is raw $_GET reflected into a response header below.
       header() blocks literal CR/LF, but we still strip to a safe filename charset
       so no unvalidated request value lands in Content-Disposition (security audit).
       The switch() further constrains which panel actually exports. */
    $safePanel = preg_replace('/[^A-Za-z0-9_-]/', '', $exportPanel);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="ihymns-' . $safePanel . '-' . $range . 'd.csv"');
    /* #1908 Commit 4 — shared emitter: opens the stream + writes the UTF-8
       BOM so a downloaded .csv opens un-mojibaked in Excel-on-Windows. */
    $fp = ihymns_csv_output_begin();
    try {
        switch ($exportPanel) {
            case 'top_songs':
                ihymns_fputcsv($fp, ['SongId', 'Title', 'SongbookAbbr', 'Number', 'Views']);
                /* @deleted-visible: historical analytics (#1694) — a view that
                   happened, happened; the tblSongs join only labels/groups it.
                   Hiding a soft-deleted song here would misstate real usage.
                   @disabled-visible: admin reporting (#1765) — counts/stats
                   over all books; a book being disabled today must not erase
                   its past view history from this admin-only export. */
                $stmt = $db->prepare(
                    'SELECT h.SongId, s.Title, s.SongbookAbbr, s.Number, COUNT(*) AS views
                       FROM tblSongHistory h
                       LEFT JOIN tblSongs s ON s.SongId = h.SongId
                      WHERE h.ViewedAt >= ?
                      GROUP BY h.SongId
                      ORDER BY views DESC'
                );
                $stmt->bind_param('s', $since);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_row()) { ihymns_fputcsv($fp, $row); }
                $stmt->close();
                break;
            case 'top_books':
                ihymns_fputcsv($fp, ['SongbookAbbr', 'Views']);
                /* @deleted-visible: historical analytics (#1694) — a view that
                   happened, happened; the tblSongs join only labels/groups it.
                   Hiding a soft-deleted song here would misstate real usage.
                   @disabled-visible: admin reporting (#1765) — same reasoning
                   as the top_songs export above. */
                $stmt = $db->prepare(
                    'SELECT s.SongbookAbbr, COUNT(*) AS views
                       FROM tblSongHistory h
                       JOIN tblSongs s ON s.SongId = h.SongId
                      WHERE h.ViewedAt >= ?
                      GROUP BY s.SongbookAbbr
                      ORDER BY views DESC'
                );
                $stmt->bind_param('s', $since);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_row()) { ihymns_fputcsv($fp, $row); }
                $stmt->close();
                break;
            case 'searches':
                ihymns_fputcsv($fp, ['Query', 'ResultCount', 'Hits']);
                try {
                    $stmt = $db->prepare(
                        'SELECT Query, ResultCount, COUNT(*) AS hits
                           FROM tblSearchQueries
                          WHERE SearchedAt >= ?
                          GROUP BY Query, ResultCount
                          ORDER BY hits DESC'
                    );
                    $stmt->bind_param('s', $since);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    while ($row = $res->fetch_row()) { ihymns_fputcsv($fp, $row); }
                    $stmt->close();
                } catch (\Throwable $_e) { /* table absent */ }
                break;
            default:
                ihymns_fputcsv($fp, ['Unknown panel: ' . $exportPanel]);
        }
    } finally {
        fclose($fp);
    }
    exit;
}

/* --- Top songs last $range days --- */
$topSongs = [];
try {
    /* @deleted-visible: historical analytics (#1694) — see the CSV export
       marker above; same reasoning for the dashboard panels.
       @disabled-visible: admin reporting (#1765) — see the CSV export
       marker above; same reasoning for the dashboard panels. */
    $stmt = $db->prepare(
        'SELECT h.SongId, COUNT(*) AS views, s.Title, s.SongbookAbbr, s.Number
           FROM tblSongHistory h
           LEFT JOIN tblSongs s ON s.SongId = h.SongId
          WHERE h.ViewedAt >= ?
          GROUP BY h.SongId
          ORDER BY views DESC
          LIMIT 15'
    );
    $stmt->bind_param('s', $since);
    $stmt->execute();
    $topSongs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} catch (\Throwable $e) { /* table may be empty */ }

/* --- Top songbooks --- */
$topBooks = [];
try {
    /* @deleted-visible: historical analytics (#1694) — see the CSV export
       marker above; same reasoning for the dashboard panels. */
    $stmt = $db->prepare(
        'SELECT s.SongbookAbbr, COUNT(*) AS views
           FROM tblSongHistory h
           JOIN tblSongs s ON s.SongId = h.SongId
          WHERE h.ViewedAt >= ?
          GROUP BY s.SongbookAbbr
          ORDER BY views DESC'
    );
    $stmt->bind_param('s', $since);
    $stmt->execute();
    $topBooks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} catch (\Throwable $e) {}

/* --- New logins + active users --- */
$loginCount = 0;
$activeUsers = 0;
try {
    $stmt = $db->prepare('SELECT COUNT(*) FROM tblLoginAttempts WHERE Success = 1 AND AttemptedAt >= ?');
    $stmt->bind_param('s', $since);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    $loginCount = (int)($row[0] ?? 0);
    $stmt->close();

    $stmt = $db->prepare('SELECT COUNT(DISTINCT UserId) FROM tblSongHistory WHERE ViewedAt >= ? AND UserId IS NOT NULL');
    $stmt->bind_param('s', $since);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    $activeUsers = (int)($row[0] ?? 0);
    $stmt->close();
} catch (\Throwable $e) {}

/* --- Totals --- */
$totalUsers = 0;
try {
    $stmt = $db->prepare('SELECT COUNT(*) FROM tblUsers WHERE IsActive = 1');
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    $totalUsers = (int)($row[0] ?? 0);
    $stmt->close();
} catch (\Throwable $e) {}

$totalRequests = 0;
try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM tblSongRequests WHERE Status = 'pending'");
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    $totalRequests = (int)($row[0] ?? 0);
    $stmt->close();
} catch (\Throwable $e) {}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics — iHymns Admin</title>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-libs.php'; ?>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-favicon.php'; ?>
</head>
<body>

<?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-nav.php'; ?>

<div class="container-admin py-4">

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
                $stmt->bind_param('s', $since);
                $stmt->execute();
                $topSearches = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();

                $stmt = $db->prepare(
                    'SELECT Query, COUNT(*) AS hits
                       FROM tblSearchQueries
                      WHERE SearchedAt >= ? AND ResultCount = 0 AND Query <> ""
                      GROUP BY Query
                      ORDER BY hits DESC
                      LIMIT 10'
                );
                $stmt->bind_param('s', $since);
                $stmt->execute();
                $zeroResults = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
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
