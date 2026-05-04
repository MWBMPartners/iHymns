<?php

declare(strict_types=1);

/**
 * iHymns — Admin: Cross-book song link suggestions (#808)
 *
 * Lists pre-computed pairwise similarity candidates so a curator
 * can one-click link them as the same hymn (writing into
 * tblSongLinks) or dismiss them (writing into
 * tblSongLinkSuggestionsDismissed). The producer side of the
 * pipeline is tools/build-song-link-suggestions.php — this page
 * only consumes that table.
 *
 * Entitlement: edit_songs (curators who can fix song metadata
 * are the same set who should triage candidate links).
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'config.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_mysql.php';

requireAuth();
$currentUser = getCurrentUser();
if (!userHasEntitlement('edit_songs', $currentUser['role'] ?? null)) {
    http_response_code(403);
    exit('Access denied — song-link suggestions require the edit_songs entitlement.');
}

$activePage = 'song-link-suggestions';

$flash = '';
$error = '';

$db = null;
try {
    $db = getDbMysqli();
} catch (\Throwable $e) {
    error_log('[song-link-suggestions] getDbMysqli failed: ' . $e->getMessage());
    $error = 'Database is currently unreachable. ' . $e->getMessage();
}

/* Quick probe — if the migration hasn't been applied yet, we surface
   a hint instead of crashing on the SELECT below. */
$tablesPresent = false;
if ($db) {
    $probe = $db->query(
        "SELECT COUNT(*) AS n FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME IN ('tblSongLinks','tblSongLinkSuggestions','tblSongLinkSuggestionsDismissed')"
    );
    if ($probe) {
        $row = $probe->fetch_assoc();
        $tablesPresent = ((int)($row['n'] ?? 0)) === 3;
        $probe->close();
    }
}

/* ---- POST handlers ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrf((string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        exit('Invalid CSRF token');
    }
    $postAction = (string)($_POST['action'] ?? '');
    if ($postAction === 'rebuild') {
        /* Run the builder script in-process. The script writes to
           stdout via _bsls_out(), which we capture and surface as a
           flash so the curator sees what was rebuilt. */
        ob_start();
        try {
            require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'build-song-link-suggestions.php';
            $output = ob_get_clean();
            $flash = 'Rebuilt suggestion list. ' . strip_tags(str_replace('<br>', ' · ', $output));
        } catch (\Throwable $e) {
            ob_end_clean();
            $error = 'Rebuild failed: ' . $e->getMessage();
        }
    } elseif ($postAction === 'link' && $tablesPresent) {
        /* The Link button ultimately POSTs to the editor's
           add_song_link endpoint — we replicate the minimum logic
           here to keep the page self-contained, since the editor API
           insists on editor+ role and this surface is already gated
           by edit_songs. */
        $a = trim((string)($_POST['songIdA'] ?? ''));
        $b = trim((string)($_POST['songIdB'] ?? ''));
        if ($a !== '' && $b !== '' && $a !== $b) {
            try {
                $createdBy = (int)($currentUser['id'] ?? 0) ?: null;
                /* Find any existing groups for either side. */
                $stmt = $db->prepare('SELECT SongId, GroupId FROM tblSongLinks WHERE SongId IN (?, ?)');
                $stmt->bind_param('ss', $a, $b);
                $stmt->execute();
                $existing = [];
                $res = $stmt->get_result();
                while ($r = $res->fetch_assoc()) $existing[$r['SongId']] = (int)$r['GroupId'];
                $stmt->close();

                $aG = $existing[$a] ?? 0;
                $bG = $existing[$b] ?? 0;
                if ($aG > 0 && $bG > 0 && $aG !== $bG) {
                    $error = 'Both songs are already in different counterpart groups. Unlink one in the editor first.';
                } elseif ($aG > 0 && $bG > 0) {
                    $flash = 'Already linked.';
                } else {
                    if ($aG === 0 && $bG === 0) {
                        $r = $db->query('SELECT COALESCE(MAX(GroupId), 0) + 1 AS NextId FROM tblSongLinks');
                        $newGroup = $r ? (int)$r->fetch_assoc()['NextId'] : 1;
                        if ($r) $r->close();
                        $emptyNote = '';
                        $ins = $db->prepare(
                            'INSERT INTO tblSongLinks (GroupId, SongId, Note, CreatedBy)
                             VALUES (?, ?, ?, ?), (?, ?, ?, ?)'
                        );
                        $ins->bind_param(
                            'issiisis',
                            $newGroup, $a, $emptyNote, $createdBy,
                            $newGroup, $b, $emptyNote, $createdBy
                        );
                        $ins->execute();
                        $ins->close();
                        $flash = 'Linked as same hymn (new group).';
                    } else {
                        $joinGroup = $aG > 0 ? $aG : $bG;
                        $newSong   = $aG > 0 ? $b  : $a;
                        $emptyNote = '';
                        $ins = $db->prepare(
                            'INSERT INTO tblSongLinks (GroupId, SongId, Note, CreatedBy)
                             VALUES (?, ?, ?, ?)'
                        );
                        $ins->bind_param('issi', $joinGroup, $newSong, $emptyNote, $createdBy);
                        $ins->execute();
                        $ins->close();
                        $flash = 'Linked as same hymn (joined existing group).';
                    }
                    /* Drop the suggestion now that it's resolved. */
                    /* Canonical order matches the build-script invariant. */
                    $idA = $a; $idB = $b;
                    if ($idA > $idB) { [$idA, $idB] = [$idB, $idA]; }
                    $del = $db->prepare(
                        'DELETE FROM tblSongLinkSuggestions WHERE SongIdA = ? AND SongIdB = ?'
                    );
                    $del->bind_param('ss', $idA, $idB);
                    $del->execute();
                    $del->close();
                }
            } catch (\Throwable $e) {
                $error = 'Failed to link: ' . $e->getMessage();
            }
        }
    } elseif ($postAction === 'dismiss' && $tablesPresent) {
        $a = trim((string)($_POST['songIdA'] ?? ''));
        $b = trim((string)($_POST['songIdB'] ?? ''));
        $reason = trim((string)($_POST['reason'] ?? ''));
        if ($a !== '' && $b !== '' && $a !== $b) {
            if ($a > $b) { [$a, $b] = [$b, $a]; }
            try {
                $by = (int)($currentUser['id'] ?? 0) ?: null;
                $stmt = $db->prepare(
                    'INSERT INTO tblSongLinkSuggestionsDismissed (SongIdA, SongIdB, DismissedBy, Reason)
                     VALUES (?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE Reason = VALUES(Reason),
                                             DismissedBy = VALUES(DismissedBy),
                                             DismissedAt = CURRENT_TIMESTAMP'
                );
                $stmt->bind_param('ssis', $a, $b, $by, $reason);
                $stmt->execute();
                $stmt->close();
                $del = $db->prepare(
                    'DELETE FROM tblSongLinkSuggestions WHERE SongIdA = ? AND SongIdB = ?'
                );
                $del->bind_param('ss', $a, $b);
                $del->execute();
                $del->close();
                $flash = 'Suggestion dismissed.';
            } catch (\Throwable $e) {
                $error = 'Failed to dismiss: ' . $e->getMessage();
            }
        }
    }
    /* PRG so refresh doesn't re-submit. */
    $qs = ['flash' => $flash, 'error' => $error];
    header('Location: /manage/song-link-suggestions?' . http_build_query(array_filter($qs)));
    exit;
}

if (!empty($_GET['flash'])) $flash = (string)$_GET['flash'];
if (!empty($_GET['error'])) $error = (string)$_GET['error'];

/* ---- Read pending suggestions ---- */
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 30;
$offset  = ($page - 1) * $perPage;

$rows = [];
$totalRows = 0;
if ($db && $tablesPresent && !$error) {
    try {
        $cnt = $db->query(
            'SELECT COUNT(*) AS n FROM tblSongLinkSuggestions s
             WHERE NOT EXISTS (
                 SELECT 1 FROM tblSongLinkSuggestionsDismissed d
                  WHERE d.SongIdA = s.SongIdA AND d.SongIdB = s.SongIdB
             )'
        );
        if ($cnt) {
            $totalRows = (int)$cnt->fetch_assoc()['n'];
            $cnt->close();
        }

        $stmt = $db->prepare(
            'SELECT s.SongIdA, s.SongIdB, s.Score, s.TitleScore, s.LyricsScore, s.AuthorsScore,
                    a.Title AS TitleA, a.Number AS NumberA, a.SongbookAbbr AS SongbookA, a.Language AS LangA,
                    b.Title AS TitleB, b.Number AS NumberB, b.SongbookAbbr AS SongbookB, b.Language AS LangB,
                    sa.Name AS SongbookNameA,
                    sb.Name AS SongbookNameB
               FROM tblSongLinkSuggestions s
               JOIN tblSongs     a  ON a.SongId = s.SongIdA
               JOIN tblSongs     b  ON b.SongId = s.SongIdB
               JOIN tblSongbooks sa ON sa.Abbreviation = a.SongbookAbbr
               JOIN tblSongbooks sb ON sb.Abbreviation = b.SongbookAbbr
              WHERE NOT EXISTS (
                  SELECT 1 FROM tblSongLinkSuggestionsDismissed d
                   WHERE d.SongIdA = s.SongIdA AND d.SongIdB = s.SongIdB
              )
              ORDER BY s.Score DESC, s.SongIdA ASC
              LIMIT ? OFFSET ?'
        );
        $stmt->bind_param('ii', $perPage, $offset);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } catch (\Throwable $e) {
        $error = 'Failed to load suggestions: ' . $e->getMessage();
    }
}

$totalPages = max(1, (int)ceil($totalRows / $perPage));
$csrf = csrfToken();

?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Song Link Suggestions — iHymns Admin</title>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-libs.php'; ?>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-favicon.php'; ?>
</head>
<body>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-nav.php'; ?>

    <div class="container-admin py-4">
        <h1 class="h4 mb-1">
            <i class="bi bi-lightbulb me-2" aria-hidden="true"></i>
            Cross-book Song Link Suggestions
        </h1>
        <p class="text-secondary small mb-4">
            Pairs of songs flagged by the similarity engine as likely the
            same hymn appearing in different songbooks. Confirm each pair
            with <strong>Link</strong> (extends or creates a counterpart
            group) or <strong>Dismiss</strong> (marks the pair as a
            different-hymn false positive — never reappears). Tracked in
            #807 / #808.
        </p>

        <?php if ($flash !== ''): ?>
            <div class="alert alert-success small"><?= htmlspecialchars($flash) ?></div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <div class="alert alert-danger small"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if (!$tablesPresent): ?>
            <div class="alert alert-warning small">
                <strong>Migration not applied.</strong> Run
                <code>migrate-song-links.php</code> from the
                <a href="/manage/setup-database">Database Setup</a>
                page first — the suggestions table doesn't exist yet.
            </div>
        <?php else: ?>
            <div class="row g-3 mb-4">
                <div class="col-sm-4">
                    <div class="card-admin">
                        <div class="text-muted text-uppercase small">Pending suggestions</div>
                        <div class="h4 mb-0"><?= number_format($totalRows) ?></div>
                    </div>
                </div>
                <div class="col-sm-8">
                    <form method="post" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                        <input type="hidden" name="action" value="rebuild">
                        <button type="submit" class="btn btn-outline-primary">
                            <i class="bi bi-arrow-clockwise me-1"></i>
                            Rebuild suggestion list
                        </button>
                        <span class="text-muted small ms-2">
                            Re-runs the similarity engine across the whole catalogue.
                            Existing dismissals are preserved.
                        </span>
                    </form>
                </div>
            </div>

            <?php if (empty($rows)): ?>
                <div class="alert alert-info small mb-0">
                    No pending suggestions. Either the catalogue has no
                    near-duplicate-titled songs across songbooks, or the
                    suggestion table hasn't been built yet — click
                    <strong>Rebuild</strong> above to generate it.
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle cp-sortable">
                        <thead>
                            <tr>
                                <th scope="col" data-sort-key="score" data-sort-type="number">Score</th>
                                <th scope="col" data-sort-key="songA" data-sort-type="text">Song A</th>
                                <th scope="col" data-sort-key="songB" data-sort-type="text">Song B</th>
                                <th scope="col" class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $r): ?>
                                <tr>
                                    <td>
                                        <span class="badge bg-info-subtle text-info-emphasis"
                                              title="Title <?= round($r['TitleScore'] * 100) ?>% · lyrics <?= round($r['LyricsScore'] * 100) ?>% · authors <?= round($r['AuthorsScore'] * 100) ?>%">
                                            <?= round($r['Score'] * 100) ?>%
                                        </span>
                                    </td>
                                    <td>
                                        <div class="small">
                                            <span class="badge bg-body-secondary me-1"><?= htmlspecialchars($r['SongbookA']) ?></span>
                                            <strong><?= htmlspecialchars($r['SongIdA']) ?></strong>
                                            <?php if ($r['NumberA'] !== null): ?>
                                                <span class="text-muted">#<?= (int)$r['NumberA'] ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div><?= htmlspecialchars($r['TitleA']) ?></div>
                                        <div class="text-muted small"><?= htmlspecialchars($r['SongbookNameA']) ?></div>
                                    </td>
                                    <td>
                                        <div class="small">
                                            <span class="badge bg-body-secondary me-1"><?= htmlspecialchars($r['SongbookB']) ?></span>
                                            <strong><?= htmlspecialchars($r['SongIdB']) ?></strong>
                                            <?php if ($r['NumberB'] !== null): ?>
                                                <span class="text-muted">#<?= (int)$r['NumberB'] ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div><?= htmlspecialchars($r['TitleB']) ?></div>
                                        <div class="text-muted small"><?= htmlspecialchars($r['SongbookNameB']) ?></div>
                                    </td>
                                    <td class="text-end">
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                            <input type="hidden" name="action"  value="link">
                                            <input type="hidden" name="songIdA" value="<?= htmlspecialchars($r['SongIdA']) ?>">
                                            <input type="hidden" name="songIdB" value="<?= htmlspecialchars($r['SongIdB']) ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-success" title="Link as same hymn">
                                                <i class="bi bi-link-45deg me-1"></i>Link
                                            </button>
                                        </form>
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                            <input type="hidden" name="action"  value="dismiss">
                                            <input type="hidden" name="songIdA" value="<?= htmlspecialchars($r['SongIdA']) ?>">
                                            <input type="hidden" name="songIdB" value="<?= htmlspecialchars($r['SongIdB']) ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-secondary" title="Dismiss — different hymns">
                                                <i class="bi bi-x-lg me-1"></i>Dismiss
                                            </button>
                                        </form>
                                        <a class="btn btn-sm btn-outline-primary"
                                           href="/manage/editor/?id=<?= htmlspecialchars(urlencode($r['SongIdA'])) ?>"
                                           target="_blank" rel="noopener"
                                           title="Open Song A in editor">
                                            <i class="bi bi-pencil-square"></i>
                                        </a>
                                        <a class="btn btn-sm btn-outline-primary"
                                           href="/manage/editor/?id=<?= htmlspecialchars(urlencode($r['SongIdB'])) ?>"
                                           target="_blank" rel="noopener"
                                           title="Open Song B in editor">
                                            <i class="bi bi-pencil-square"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($totalPages > 1): ?>
                    <nav aria-label="Suggestion pagination">
                        <ul class="pagination pagination-sm">
                            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                                <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                                    <a class="page-link" href="?page=<?= $p ?>"><?= $p ?></a>
                                </li>
                            <?php endfor; ?>
                        </ul>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>
</body>
</html>
