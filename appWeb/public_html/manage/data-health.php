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
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_mysql.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'data_health_admin.php'; /* #1969 API-coverage Batch 5 — shared with api.php's admin_data_health_fix */

requireAuth();
$currentUser = getCurrentUser();
/* Gate on the SAME entitlement the nav advertises (#1648 item 1).
   ELI5: the menu says this page needs the "drop_legacy_tables" permission, so the page must
   check that exact permission rather than a role that happens to match it today.
   Detail: the gate here was role-based while admin-links.php advertises
   `drop_legacy_tables`, which is admin-editable at runtime via /manage/entitlements. A
   narrowed override hid the nav link while the page still admitted the old
   role — the UI presented a revocation that never happened. The default map for
   `drop_legacy_tables` is exactly the role set this replaced, so behaviour is unchanged
   until someone overrides it. Rule #1587's red flag. */
if (!$currentUser || !userHasEntitlement('drop_legacy_tables', $currentUser['role'] ?? null)) {
    http_response_code(403);
    exit('Access denied. The drop_legacy_tables entitlement is required.');
}
$activePage  = 'data-health';

$flash   = '';
$error   = '';

/* Allowlist of tables this page reports counts for. Used both in the
   loop that drives the report AND as the runtime guard at the query
   site (#558). Lifting this out of an inline array literal keeps the
   safety locally provable: the in_array() check at line ~95 doesn't
   have to trust that the loop variable came from this list — it can
   prove it. If the loop is ever refactored into a function that
   accepts a $tbl parameter, the guard remains in place. */
const HEALTH_TABLES = [
    'tblSongs', 'tblSongbooks', 'tblUsers', 'tblUserSetlists',
    'tblSharedSetlists', 'tblSongRequests', 'tblSongRevisions',
    'tblUserGroups', 'tblOrganisations',
];

/* getDbMysqli() can throw if MySQL credentials are wrong or the
   server is unreachable — previously that fatal killed the page
   output before a single byte reached the browser, so admins saw a
   blank screen with no clue what went wrong. Catch, record, and
   render the admin layout anyway with the error surfaced. */
try {
    $db = getDbMysqli();
} catch (\Throwable $e) {
    error_log('[manage/data-health.php] getDbMysqli failed: ' . $e->getMessage());
    $db = null;
    $error = 'Database is currently unreachable. ' . $e->getMessage();
}

/* Legacy paths to inspect / optionally disable. #1969 API-coverage Batch 5 —
   sourced from the ONE shared registry (dataHealthLegacyPaths()) so the
   page and api.php's admin_data_health_fix agree on what "legacy" means. */
$legacyPaths  = dataHealthLegacyPaths();
$songsJsonPath = $legacyPaths['songs_json'];
$shareDirPath  = $legacyPaths['setlist_dir'];
$sqliteDbPath  = $legacyPaths['sqlite_db'];

/* ---- POST: disconnect-legacy-fallbacks action ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrf((string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo 'Invalid CSRF token';
        exit;
    }
    if (($_POST['action'] ?? '') === 'disconnect_fallbacks') {
        /* #1969 API-coverage Batch 5 — the rename loop now lives in the ONE
           shared core (dataHealthDisconnectFallbacks()), reused verbatim by
           api.php's admin_data_health_fix. */
        $fix     = dataHealthDisconnectFallbacks($legacyPaths);
        $renamed = $fix['renamed'];
        $skipped = $fix['skipped'];
        $failed  = $fix['failed'];
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
foreach (HEALTH_TABLES as $tbl) {
    if ($db === null) {
        $tableCounts[$tbl] = null;
        continue;
    }
    /* Belt-and-braces allowlist guard. Technically redundant given the
       loop iterates HEALTH_TABLES, but it makes the safety provable at
       the query site without the reader having to trace control flow.
       SQL identifiers (table names) cannot be parameterised — `?` only
       binds values — so the guard + backticks is the correct pattern. */
    if (!in_array($tbl, HEALTH_TABLES, true)) { continue; }
    try {
        $stmt = $db->prepare('SELECT COUNT(*) FROM `' . $tbl . '`');
        $stmt->execute();
        $row = $stmt->get_result()->fetch_row();
        $tableCounts[$tbl] = (int)($row[0] ?? 0);
        $stmt->close();
    } catch (\Throwable $_e) {
        /* "Table missing" is the expected reason on a fresh deploy —
           the UI surfaces it as null. Log anyway so non-missing
           failures (permission, syntax) leave a trail. */
        error_log("[manage/data-health.php] COUNT({$tbl}) failed: " . $_e->getMessage());
        $tableCounts[$tbl] = null;
    }
}

/* Is SongData on the JSON fallback? Instantiating it runs the probe.
   #1631 item 6: on THIS codebase isJsonFallback() is structurally
   inert — jsonMode is set false once in SongData.php and never
   reassigned (WS-J #1020 removed the fallback entirely; see
   SongData.php:118,143,208-210), so this can only ever come back
   `false` or `null` (probe threw / class missing), never `true`.
   Kept anyway, deliberately: this page is what an operator reaches
   for when a docroot looks broken, and (a) a docroot can be running
   an older SongData.php than the one in this working copy — a
   pre-WS-J-#1020 install genuinely could still be on the fallback —
   and (b) the "legacy file on disk" check just below is a distinct
   concern (the old songs.json may still physically exist even though
   nothing reads it) that this card is the one place surfacing. */
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
            $stmt = $db->prepare('SELECT ShareId FROM tblSharedSetlists');
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            $inDb = array_fill_keys(array_column($rows, 'ShareId'), true);
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

/* Build a precise list of what's blocking the disconnect (#710). The
   earlier "Resolve the amber / red items above first" copy made
   curators guess which row was the culprit — instead, enumerate
   exactly what's still keeping MySQL non-authoritative. Each entry
   includes a short reason + the section anchor so the warning links
   straight to the row that needs attention. */
$disconnectBlockers = [];
if ($songDataJsonFallback !== false) {
    $disconnectBlockers[] = [
        'reason' => 'data_share/song_data/songs.json fallback is still active',
        'anchor' => '#songs-json-fallback',
    ];
}
/* A blocker must be something DISCONNECTING CANNOT FIX (#1650).
 *
 * ELI5: don't refuse to tidy the room on the grounds that the room is untidy.
 *
 * These two gates used to test mere FILE EXISTENCE — and the disconnect action
 * renames exactly those files (see the `disconnect_fallbacks` handler above,
 * which renames $songsJsonPath, $shareDirPath and $sqliteDbPath). So the button
 * could only enable once the files were already gone, at which point it had
 * nothing left to do: unreachable by construction. The panel contradicted
 * itself too, printing "Safe to disconnect" beside the very item it listed as
 * a blocker.
 *
 * The real question is whether the DATA still exists only on disk. That is what
 * $unimportedShareIds measures — and it was already computed. The old condition
 * simply OR'd it with `$shareFileCount > 0`, which made the meaningful half
 * unreachable as well.
 */
if (count($unimportedShareIds) > 0) {
    $disconnectBlockers[] = [
        'reason' => sprintf(
            '%d of %d share JSON file(s) not yet imported into MySQL — disconnecting now '
            . 'would orphan those share URLs',
            count($unimportedShareIds),
            $shareFileCount
        ),
        'anchor' => '#shared-setlist-json',
    ];
}
/* No blocker for the SQLite file merely existing, deliberately. It is read ONLY
   by migrate-users.php during the one-off user migration — no runtime path
   opens it — so its presence proves nothing about whether the data landed. The
   meaningful check is "did the users actually arrive", which is the tblUsers
   blocker below and fires on its own. Gating on the file was both circular (the
   action renames it) and redundant. */
if (($tableCounts['tblSongs'] ?? 0) === 0) {
    $disconnectBlockers[] = [
        'reason' => 'tblSongs is empty — MySQL has no song data to fall back to',
        'anchor' => '#table-tblSongs',
    ];
}
if (($tableCounts['tblUsers'] ?? 0) === 0) {
    $disconnectBlockers[] = [
        'reason' => 'tblUsers is empty — no migrated user accounts',
        'anchor' => '#table-tblUsers',
    ];
}

/* Overall green light = no blockers. */
$allGreen = empty($disconnectBlockers);

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
<html lang="en">
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

        <h1 class="h4 mb-3"><i aria-hidden="true" class="bi bi-activity me-2"></i>Data Health Check</h1>
        <p class="text-secondary small mb-4">
            Checks that the live database now holds every song, user and
            shared set list, so the old backup files kept from earlier
            versions of the site can be safely switched off. Nothing here
            deletes anything — switching a file off just renames it so it
            stops being used, and you can rename it back at any time.
        </p>

        <?php if ($flash): ?>
            <div class="alert alert-success py-2"><?= htmlspecialchars($flash) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <!-- MySQL table counts -->
        <div class="card-admin p-3 mb-3">
            <h2 class="h6 mb-3"><i aria-hidden="true" class="bi bi-database me-2"></i>MySQL table counts</h2>
            <table class="table table-sm mb-0 align-middle cp-sortable admin-table-responsive">
                <thead>
                    <tr class="text-muted small">
                        <th scope="col" data-sort-key="table" data-sort-type="text">Table</th>
                        <th scope="col" class="text-end" data-sort-key="rows" data-sort-type="number">Rows</th>
                        <th scope="col" data-sort-key="status" data-sort-type="text">Status</th>
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
            <h2 class="h6 mb-3"><i aria-hidden="true" class="bi bi-file-earmark-code me-2"></i><code>songs.json</code> fallback</h2>
            <p class="mb-2 small text-secondary">
                Retired (WS-J #1020): <code>SongData</code> is MySQL-only now — a
                DB outage is a clean 503, never stale JSON. The probe below is a
                residual check, kept in case this docroot is ever running an
                older, pre-#1020 <code>SongData.php</code>; the "legacy file on
                disk" line further down is the separate, still-useful check for
                whether the old <code>data_share/song_data/songs.json</code> file
                is still physically present even though nothing on this build
                reads it.
            </p>
            <?php if ($songDataJsonFallback === true): ?>
                <?= health_badge('red', 'SongData is currently using the JSON fallback') ?>
                <p class="small text-secondary mt-2 mb-0">
                    This usually means the MySQL song data is missing or
                    unreachable. Run <a href="/manage/setup-database?action=install">Install</a>,
                    then apply pending migrations and import content via the
                    editor's bulk importers (#1614).
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
            <h2 class="h6 mb-3"><i aria-hidden="true" class="bi bi-link-45deg me-2"></i>Shared setlist JSON files</h2>
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
            <h2 class="h6 mb-3"><i aria-hidden="true" class="bi bi-hdd-stack me-2"></i>Legacy SQLite database</h2>
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

        <!-- Songs corpus cache removed in WS-J #1020: all reads are now live
             MySQL (slim songs_index for the PWA, load_index/load_song for the
             editor, songbook_export for songbook bundles). No file cache to
             report or regenerate. The legacy songs.json file, if still on
             disk, is inert and can be cleared via the disconnect action below. -->

        <!-- Disconnect action -->
        <div class="card-admin p-3 mb-3 <?= $allGreen ? '' : 'opacity-75' ?>">
            <h2 class="h6 mb-3"><i aria-hidden="true" class="bi bi-plug me-2"></i>Disconnect legacy fallbacks</h2>
            <p class="small mb-3">
                Renames (does not delete) each legacy source by appending
                <code>.disabled</code>. The runtime fallbacks now short-circuit
                and MySQL becomes the only path. Reversible — rename back by hand.
            </p>
            <?php if (!$allGreen): ?>
                <div class="alert alert-warning py-2 small mb-3">
                    <strong>Disconnect blocked by <?= count($disconnectBlockers) ?> item<?= count($disconnectBlockers) === 1 ? '' : 's' ?>:</strong>
                    <ul class="mb-0 mt-1">
                        <?php foreach ($disconnectBlockers as $b): ?>
                            <li>
                                <?= htmlspecialchars($b['reason']) ?>
                                <?php if (!empty($b['anchor'])): ?>
                                    <a href="<?= htmlspecialchars($b['anchor']) ?>" class="ms-1">[jump]</a>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            <form method="POST" onsubmit="return confirm('Disconnect legacy fallbacks by renaming them to *.disabled?')">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action"     value="disconnect_fallbacks">
                <button type="submit" class="btn btn-danger btn-sm" <?= $allGreen ? '' : 'disabled' ?>>
                    <i aria-hidden="true" class="bi bi-plug me-1"></i>
                    <?php if ($allGreen): ?>
                        Disconnect legacy fallbacks (all clear)
                    <?php else: ?>
                        Disconnect legacy fallbacks (<?= count($disconnectBlockers) ?> blocker<?= count($disconnectBlockers) === 1 ? '' : 's' ?> remaining)
                    <?php endif; ?>
                </button>
            </form>
        </div>

    </div>

    <!-- Sortable table headers (#1786 sweep — tagged cp-sortable but never
         booted; every header click was a silent no-op until now). -->
    <script type="module">
        import { bootSortableTables } from '/js/modules/admin-table-sort.js?v=<?= filemtime(dirname(__DIR__) . '/js/modules/admin-table-sort.js') ?>';
        bootSortableTables();
    </script>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>
</body>
</html>
