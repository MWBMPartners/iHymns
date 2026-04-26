<?php

declare(strict_types=1);

/**
 * iHymns — Admin: Data Health Check (global_admin only)
 *
 * Lets a global admin confirm MySQL is authoritative for every data
 * surface before pulling the plug on legacy fallbacks.
 *
 * Read-only report plus an opt-in "disconnect legacy fallbacks" action
 * that renames (not deletes) the on-disk legacy sources to .disabled
 * so the code paths that fall back to them short-circuit. Fully
 * reversible — rename back by hand.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'config.php';

requireGlobalAdmin();
$currentUser = getCurrentUser();
$activePage  = 'data-health';

$flash   = '';
$error   = '';

/* getDb() can throw if MySQL credentials are wrong or the server is
   unreachable — previously that fatal killed the page output before a
   single byte reached the browser, so admins saw a blank screen with
   no clue what went wrong. Catch, record, and render the admin layout
   anyway with the error surfaced. */
try {
    $db = getDb();
} catch (\Throwable $e) {
    error_log('[manage/data-health.php] getDb failed: ' . $e->getMessage());
    $db = null;
    $error = 'Database is currently unreachable. ' . $e->getMessage();
}

/* Legacy paths to inspect / optionally disable */
$songsJsonPath    = defined('APP_DATA_FILE')          ? APP_DATA_FILE          : '';
$shareDirPath     = defined('APP_SETLIST_SHARE_DIR')  ? APP_SETLIST_SHARE_DIR  : '';
$sqliteDbPath     = dirname(APP_ROOT) . DIRECTORY_SEPARATOR . 'data_share'
                  . DIRECTORY_SEPARATOR . 'SQLite'
                  . DIRECTORY_SEPARATOR . 'ihymns.db';

/* ---- POST: disconnect-legacy-fallbacks action ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrf((string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo 'Invalid CSRF token';
        exit;
    }
    if (($_POST['action'] ?? '') === 'disconnect_fallbacks') {
        $renamed = [];
        $skipped = [];
        $failed  = [];
        foreach ([
            'songs_json'   => $songsJsonPath,
            'setlist_dir'  => $shareDirPath,
            'sqlite_db'    => $sqliteDbPath,
        ] as $k => $path) {
            if ($path === '') { $skipped[] = "{$k} (no path configured)"; continue; }
            if (!file_exists($path)) { $skipped[] = "{$k} (not present)"; continue; }
            $target = $path . '.disabled';
            if (file_exists($target)) { $skipped[] = "{$k} (already disabled)"; continue; }
            if (@rename($path, $target)) {
                $renamed[] = "{$k} → " . basename($target);
            } else {
                $failed[] = "{$k} (rename failed — check permissions)";
            }
        }
        $parts = [];
        if ($renamed) $parts[] = 'Renamed: ' . implode('; ', $renamed);
        if ($skipped) $parts[] = 'Skipped: ' . implode('; ', $skipped);
        if ($failed)  $parts[] = 'Failed: '  . implode('; ', $failed);
        if ($failed) {
            $error = implode(' · ', $parts);
        } else {
            $flash = implode(' · ', $parts) ?: 'Nothing to do.';
        }
    }
}

/* ---- Gather health ---- */
$tableCounts = [];
foreach (['tblSongs', 'tblSongbooks', 'tblUsers', 'tblUserSetlists',
          'tblSharedSetlists', 'tblSongRequests', 'tblSongRevisions',
          'tblUserGroups', 'tblOrganisations'] as $tbl) {
    if ($db === null) {
        $tableCounts[$tbl] = null;
        continue;
    }
    try {
        $tableCounts[$tbl] = (int)$db->query('SELECT COUNT(*) FROM ' . $tbl)->fetchColumn();
    } catch (\Throwable $_e) {
        /* "Table missing" is the expected reason on a fresh deploy —
           the UI surfaces it as null. Log anyway so non-missing
           failures (permission, syntax) leave a trail. */
        error_log("[manage/data-health.php] COUNT({$tbl}) failed: " . $_e->getMessage());
        $tableCounts[$tbl] = null;
    }
}

/* Is SongData on the JSON fallback? Instantiating it runs the probe. */
$songDataJsonFallback = null;
try {
    require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'SongData.php';
    if (class_exists('\\SongData')) {
        $probe = new \SongData();
        $songDataJsonFallback = $probe->isJsonFallback();
    }
} catch (\Throwable $e) {
    error_log('[manage/data-health.php] SongData probe: ' . $e->getMessage());
}

/* Share directory file vs DB row count */
$shareFileCount = null;
$unimportedShareIds = [];
if ($shareDirPath && is_dir($shareDirPath)) {
    $files = glob($shareDirPath . DIRECTORY_SEPARATOR . '*.json') ?: [];
    $shareFileCount = count($files);
    if ($shareFileCount > 0 && isset($tableCounts['tblSharedSetlists'])) {
        $idsOnDisk = array_filter(array_map(
            fn($f) => preg_match('/^[a-f0-9]{6,32}$/i', basename($f, '.json')) ? basename($f, '.json') : null,
            $files
        ));
        try {
            $stmt = $db->query('SELECT ShareId FROM tblSharedSetlists');
            $inDb = array_fill_keys(array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'ShareId'), true);
            foreach ($idsOnDisk as $id) {
                if (!isset($inDb[$id])) $unimportedShareIds[] = $id;
            }
        } catch (\Throwable $_e) {
            /* Table absent on fresh deploy is expected; log so any
               other failure mode (permissions, schema drift) is
               visible to admins. */
            error_log('[manage/data-health.php] tblSharedSetlists scan: ' . $_e->getMessage());
        }
    }
}
$sqliteExists = $sqliteDbPath && file_exists($sqliteDbPath);
$sqliteSize   = $sqliteExists ? @filesize($sqliteDbPath) : 0;

/* Overall green light: MySQL authoritative, no unimported shares, SQLite gone */
$allGreen = (
    $songDataJsonFallback === false
    && $shareFileCount === 0
    && count($unimportedShareIds) === 0
    && !$sqliteExists
    && ($tableCounts['tblSongs'] ?? 0) > 0
    && ($tableCounts['tblUsers'] ?? 0) > 0
);

function health_badge(string $state, string $label): string {
    $cls = match ($state) {
        'green'  => 'bg-success',
        'amber'  => 'bg-warning text-dark',
        'red'    => 'bg-danger',
        default  => 'bg-secondary',
    };
    return '<span class="badge ' . $cls . '">' . htmlspecialchars($label) . '</span>';
}

$csrf = csrfToken();
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Health — iHymns Admin</title>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-libs.php'; ?>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-favicon.php'; ?>
</head>
<body>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-nav.php'; ?>

    <div class="container-admin py-4">

        <h1 class="h4 mb-3"><i class="bi bi-activity me-2"></i>Data Health Check</h1>
        <p class="text-secondary small mb-4">
            Confirm MySQL is fully authoritative before retiring the legacy
            <code>songs.json</code>, shared-setlist JSON files and SQLite
            database used in earlier iterations. Nothing on this page is
            destructive — "disconnect" renames the legacy sources to
            <code>*.disabled</code>, which is trivially reversible.
        </p>

        <?php if ($flash): ?>
            <div class="alert alert-success py-2"><?= htmlspecialchars($flash) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <!-- MySQL table counts -->
        <div class="card-admin p-3 mb-3">
            <h2 class="h6 mb-3"><i class="bi bi-database me-2"></i>MySQL table counts</h2>
            <table class="table table-sm mb-0 align-middle">
                <thead>
                    <tr class="text-muted small">
                        <th>Table</th>
                        <th class="text-end">Rows</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tableCounts as $tbl => $count):
                        if ($count === null) { $state = 'red';    $lbl = 'missing'; }
                        elseif ($count === 0 && in_array($tbl, ['tblSongs', 'tblSongbooks', 'tblUsers'], true)) {
                                              $state = 'red';    $lbl = 'empty (expected data)'; }
                        elseif ($count === 0){ $state = 'amber';  $lbl = 'empty'; }
                        else                 { $state = 'green';  $lbl = 'ok'; }
                    ?>
                        <tr>
                            <td><code><?= htmlspecialchars($tbl) ?></code></td>
                            <td class="text-end"><?= $count === null ? '—' : number_format($count) ?></td>
                            <td><?= health_badge($state, $lbl) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- SongData fallback probe -->
        <div class="card-admin p-3 mb-3">
            <h2 class="h6 mb-3"><i class="bi bi-file-earmark-code me-2"></i><code>songs.json</code> fallback</h2>
            <p class="mb-2 small text-secondary">
                <code>SongData</code> prefers MySQL. It only reads
                <code>data_share/song_data/songs.json</code> if the DB query
                fails to return any songs.
            </p>
            <?php if ($songDataJsonFallback === true): ?>
                <?= health_badge('red', 'SongData is currently using the JSON fallback') ?>
                <p class="small text-secondary mt-2 mb-0">
                    This usually means the MySQL song data is missing or
                    unreachable. Run <a href="/manage/setup-database?action=install">Install</a> /
                    <a href="/manage/setup-database?action=migrate">Migrate Songs</a>.
                </p>
            <?php elseif ($songDataJsonFallback === false): ?>
                <?= health_badge('green', 'MySQL is authoritative for songs') ?>
            <?php else: ?>
                <?= health_badge('amber', 'Could not probe SongData — see logs') ?>
            <?php endif; ?>
            <p class="small text-secondary mt-2 mb-0">
                Legacy file on disk:
                <?php if ($songsJsonPath && file_exists($songsJsonPath)): ?>
                    <code><?= htmlspecialchars($songsJsonPath) ?></code> (<?= number_format((int)@filesize($songsJsonPath)) ?> bytes)
                <?php elseif ($songsJsonPath && file_exists($songsJsonPath . '.disabled')): ?>
                    <em>already disabled</em>
                <?php else: ?>
                    <em>not present</em>
                <?php endif; ?>
            </p>
        </div>

        <!-- Shared setlist JSON files -->
        <div class="card-admin p-3 mb-3">
            <h2 class="h6 mb-3"><i class="bi bi-link-45deg me-2"></i>Shared setlist JSON files</h2>
            <p class="mb-2 small text-secondary">
                <code>SharedSetlist.php</code> prefers <code>tblSharedSetlists</code>
                and only falls back to disk when a share isn't present in the DB.
            </p>
            <?php if ($shareFileCount === null): ?>
                <?= health_badge('green', 'Legacy directory not present') ?>
            <?php elseif ($shareFileCount === 0): ?>
                <?= health_badge('green', 'Directory exists but is empty') ?>
            <?php elseif (count($unimportedShareIds) === 0): ?>
                <?= health_badge('amber', $shareFileCount . ' file(s), all imported into MySQL') ?>
                <p class="small text-secondary mt-2 mb-0">
                    Safe to disconnect. Every share URL still resolves from
                    <code>tblSharedSetlists</code>.
                </p>
            <?php else: ?>
                <?= health_badge('red', count($unimportedShareIds) . ' of ' . $shareFileCount . ' share file(s) NOT yet in MySQL') ?>
                <p class="small text-secondary mt-2 mb-0">
                    Run
                    <a href="/manage/setup-database?action=account-sync">Account Sync Migration</a>
                    to import them. Unimported IDs:
                    <code class="small"><?= htmlspecialchars(implode(', ', array_slice($unimportedShareIds, 0, 25))) ?></code>
                    <?php if (count($unimportedShareIds) > 25): ?>…<?php endif; ?>
                </p>
            <?php endif; ?>
            <p class="small text-secondary mt-2 mb-0">
                Legacy directory:
                <?php if ($shareDirPath && is_dir($shareDirPath)): ?>
                    <code><?= htmlspecialchars($shareDirPath) ?></code>
                <?php elseif ($shareDirPath && is_dir($shareDirPath . '.disabled')): ?>
                    <em>already disabled</em>
                <?php else: ?>
                    <em>not present</em>
                <?php endif; ?>
            </p>
        </div>

        <!-- Legacy SQLite -->
        <div class="card-admin p-3 mb-3">
            <h2 class="h6 mb-3"><i class="bi bi-hdd-stack me-2"></i>Legacy SQLite database</h2>
            <p class="mb-2 small text-secondary">
                Used only by <code>migrate-users.php</code> during the one-off
                user migration — no runtime code path reads from it.
            </p>
            <?php if (!$sqliteExists): ?>
                <?= health_badge('green', 'SQLite database not present') ?>
            <?php else: ?>
                <?= health_badge('amber', 'Still present at ' . basename($sqliteDbPath) . ' · ' . number_format((int)$sqliteSize) . ' bytes') ?>
                <p class="small text-secondary mt-2 mb-0">
                    Safe to disconnect once you've confirmed all users /
                    setlists / shared setlists are imported into MySQL.
                </p>
            <?php endif; ?>
        </div>

        <!-- Disconnect action -->
        <div class="card-admin p-3 mb-3 <?= $allGreen ? '' : 'opacity-75' ?>">
            <h2 class="h6 mb-3"><i class="bi bi-plug me-2"></i>Disconnect legacy fallbacks</h2>
            <p class="small mb-3">
                Renames (does not delete) each legacy source by appending
                <code>.disabled</code>. The runtime fallbacks now short-circuit
                and MySQL becomes the only path. Reversible — rename back by hand.
            </p>
            <?php if (!$allGreen): ?>
                <div class="alert alert-warning py-2 small mb-3">
                    Resolve the amber / red items above first. Disconnect is
                    disabled until every surface is fully served by MySQL.
                </div>
            <?php endif; ?>
            <form method="POST" onsubmit="return confirm('Disconnect legacy fallbacks by renaming them to *.disabled?')">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action"     value="disconnect_fallbacks">
                <button type="submit" class="btn btn-danger btn-sm" <?= $allGreen ? '' : 'disabled' ?>>
                    <i class="bi bi-plug me-1"></i>Disconnect legacy fallbacks
                </button>
            </form>
        </div>

    </div>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>
</body>
</html>
