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
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_mysql.php';
/* #1867 (epic #1863, rule #43) — songVisibleSql() backs both the new
   ?action=song_search typeahead and the server-side existence check on
   the "Resolved SongId" picker below; matches the require catalogues.php /
   works.php already carry for the same helper (rule #22 — reuse, not
   re-fork). */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'song_soft_delete.php';

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

$activePage = 'requests';

/* ---- GET ?action=song_search&q= — typeahead for the "Resolved SongId"
 * picker (#1867, epic #1863, rule #43). Mirrors the shape already proven
 * on manage/works.php:379-425 / manage/catalogues.php:80-135 (rule #22 —
 * reuse, don't re-fork): search-select only, no create arm — a curator
 * links an EXISTING song, never mints one from this page. Extensionless
 * per #1855; the page's own `review_song_requests` gate above is this
 * action's gate too (a same-page action, not a separate endpoint —
 * no #1587 entitlement-mismatch risk). Read-only GET ⇒ no CSRF, matching
 * every other *_search action in the app.
 */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && ($_GET['action'] ?? '') === 'song_search') {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    $q     = trim((string)($_GET['q'] ?? ''));
    $limit = max(1, min(50, (int)($_GET['limit'] ?? 20)));
    try {
        $db   = getDbMysqli();
        $like = '%' . $q . '%';
        if ($q === '') {
            echo json_encode(['rows' => []]);
            exit;
        }
        /* #1694 — hidden/soft-deleted songs are not offered as a resolution
           target either (songVisibleSql() below).
           @disabled-visible: admin surface (#1765) — disabled songbooks stay
           fully visible/editable in /manage (owner decision, same as
           works.php's/catalogues.php's own song_search handlers); a curator
           can still resolve a request to a song in a disabled book. */
        $sql = "SELECT s.SongId AS id, s.Title AS title, s.Number AS number,
                       s.SongbookAbbr AS songbook, sb.Name AS songbookName
                  FROM tblSongs s
                  LEFT JOIN tblSongbooks sb ON sb.Abbreviation = s.SongbookAbbr
                 WHERE (s.Title LIKE ? OR s.SongId LIKE ?)
                   AND " . songVisibleSql($db, 's') . "
                 ORDER BY s.Title ASC
                 LIMIT ?";
        $stmt = $db->prepare($sql);
        $stmt->bind_param('ssi', $like, $like, $limit);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        echo json_encode(['rows' => $rows]);
    } catch (\Throwable $e) {
        error_log('[requests song_search] ' . $e->getMessage());
        echo json_encode(['rows' => [], 'error' => 'search failed']);
    }
    exit;
}

$statuses = ['pending', 'reviewed', 'added', 'declined'];
$filter   = (string)($_GET['status'] ?? 'pending');
if (!in_array($filter, $statuses, true) && $filter !== 'all') $filter = 'pending';

$flash = '';
$err   = '';

/* --- POST: update status / notes --- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    /* #1867 rule #29 — validateCsrfRequest() is a strict superset of the
       validateCsrf()-only check this replaced: arm (a) still accepts the
       form's own baked token (unchanged behaviour for this classic POST
       form), arm (b) additionally covers a same-origin AJAX submit if this
       page ever grows one later. Matches manage/works.php:412's precedent. */
    if (!validateCsrfRequest((string)($_POST['csrf_token'] ?? ''))) {
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
            $db = getDbMysqli();

            /* #1867 (epic #1863, rule #43) — SEARCH-SELECT ONLY: the picker
               below resolves to a SongId, but it arrives over POST like any
               other client-supplied value, so it is untrusted until checked
               here — mirrors manage/catalogues.php's add_member verification
               (#1866) and manage/groups.php's add-user verification (#1868).
               tblSongRequests.ResolvedSongId already carries an FK to
               tblSongs(SongId) (schema.sql fk_Requests_ResolvedSong), so a
               bad id would previously surface as an uncaught FK-constraint
               mysqli_sql_exception at UPDATE time (caught by the generic
               try/catch below, but as an opaque "Database error"); checking
               first turns that into the same friendly, actionable message
               every other picker on this page's pattern gives, and — same
               as the FK — a soft-deleted/hidden song is not a valid
               resolution target either (songVisibleSql()). No create arm:
               curators link an EXISTING song, never mint one here. */
            $resolved = null;
            if ($resolvedSong !== '') {
                $stmt = $db->prepare(
                    'SELECT SongId FROM tblSongs WHERE SongId = ? AND ' . songVisibleSql($db, '')
                );
                $stmt->bind_param('s', $resolvedSong);
                $stmt->execute();
                $foundSong = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$foundSong) {
                    throw new \RuntimeException('song_not_found');
                }
                $resolved = $resolvedSong;
            }

            $stmt = $db->prepare(
                'UPDATE tblSongRequests
                    SET Status = ?, AdminNotes = ?, ResolvedSongId = ?
                  WHERE Id = ?'
            );
            $stmt->bind_param('sssi', $newStatus, $adminNotes, $resolved, $id);
            $stmt->execute();
            $stmt->close();
            $flash = 'Request #' . $id . ' updated.';
        } catch (\RuntimeException $e) {
            /* Sentinel from the existence check above — not a DB failure,
               so it skips the error_log/Activity-Log noise the generic
               \Throwable branch below produces for genuine failures. */
            $err = 'That song could not be found — pick one from the search results.';
        } catch (\Throwable $e) {
            error_log('[manage/requests.php] ' . $e->getMessage());
            /* Mirror the failure into the in-app Activity Log so a
               curator can self-diagnose without server-log access (#695). */
            logActivityError('admin.requests.update', 'song_request', (string)$id, $e, [
                'status' => $newStatus,
            ]);
            $err = 'Database error — check server logs for details.';
        }
    }
}

/* --- GET: fetch rows --- */
$rows = [];
try {
    $db = getDbMysqli();
    if ($filter === 'all') {
        $stmt = $db->prepare(
            'SELECT r.*, u.Username AS requested_by
               FROM tblSongRequests r
               LEFT JOIN tblUsers u ON u.Id = r.UserId
              ORDER BY r.CreatedAt DESC
              LIMIT 500'
        );
        $stmt->execute();
    } else {
        $stmt = $db->prepare(
            'SELECT r.*, u.Username AS requested_by
               FROM tblSongRequests r
               LEFT JOIN tblUsers u ON u.Id = r.UserId
              WHERE r.Status = ?
              ORDER BY r.CreatedAt DESC
              LIMIT 500'
        );
        $stmt->bind_param('s', $filter);
        $stmt->execute();
    }
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} catch (\Throwable $e) {
    error_log('[manage/requests.php] ' . $e->getMessage());
    /* The Activity Log surfaces this so the curator sees the exact
       cause inline, not just the generic banner (#695). */
    logActivityError('admin.requests.list', 'song_request', '', $e, [
        'filter' => $filter,
    ]);
    $err = 'Could not load requests — check server logs for details.';
}

$counts = [];
try {
    $cs = $db->prepare('SELECT Status, COUNT(*) AS cnt FROM tblSongRequests GROUP BY Status');
    $cs->execute();
    $res = $cs->get_result();
    while ($row = $res->fetch_assoc()) {
        $counts[$row['Status']] = (int)$row['cnt'];
    }
    $cs->close();
} catch (\Throwable $_e) {}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Song Requests — iHymns Admin</title>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-libs.php'; ?>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-favicon.php'; ?>
</head>
<body>

<?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-nav.php'; ?>

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
            <table class="table table-sm table-hover mb-0 cp-sortable admin-table-responsive">
                <thead>
                    <tr class="text-muted small">
                        <th scope="col" data-sort-key="id"         data-sort-type="number">#</th>
                        <th scope="col" data-sort-key="title"      data-sort-type="text">Title</th>
                        <th scope="col" data-sort-key="songbook"   data-sort-type="text">Songbook</th>
                        <th scope="col" data-sort-key="submitted"  data-sort-type="text">Submitted</th>
                        <th scope="col" data-sort-key="by"         data-sort-type="text">By</th>
                        <th scope="col" data-sort-key="status"     data-sort-type="text">Status</th>
                        <th scope="col"></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td class="text-muted"><?= (int)$r['Id'] ?></td>
                        <td>
                            <?php $isCorrection = (($r['RequestType'] ?? 'missing_song') === 'correction'); ?>
                            <?php if ($isCorrection): ?>
                                <span class="badge bg-info text-dark me-1">Correction</span>
                            <?php endif; ?>
                            <strong><?= htmlspecialchars($r['Title']) ?></strong>
                            <?php if ($isCorrection): ?>
                                <div class="small mt-1">
                                    <div class="text-muted">
                                        Field: <code><?= htmlspecialchars($r['FieldName'] ?? '') ?: '—' ?></code>
                                        <?php if (!empty($r['SongId'])): ?>
                                            · Song <code><?= htmlspecialchars($r['SongId']) ?></code>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (($r['OriginalValue'] ?? '') !== '' || ($r['ProposedValue'] ?? '') !== ''): ?>
                                        <div class="mt-1">
                                            <span class="text-danger text-decoration-line-through"><?= htmlspecialchars(mb_substr((string)($r['OriginalValue'] ?? ''), 0, 200)) ?></span>
                                            <span class="text-muted mx-1">&rarr;</span>
                                            <span class="text-success"><?= htmlspecialchars(mb_substr((string)($r['ProposedValue'] ?? ''), 0, 200)) ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php elseif (!empty($r['Details'])): ?>
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
                            <form method="post" class="row g-2 req-update-form">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                                <input type="hidden" name="id" value="<?= (int)$r['Id'] ?>">
                                <div class="col-md-3">
                                    <label class="form-label small" for="req-status-<?= (int)$r['Id'] ?>">Status</label>
                                    <select name="new_status" id="req-status-<?= (int)$r['Id'] ?>" class="form-select form-select-sm">
                                        <?php foreach ($statuses as $s): ?>
                                            <option value="<?= $s ?>" <?= $r['Status'] === $s ? 'selected' : '' ?>>
                                                <?= htmlspecialchars(ucfirst($s)) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label small" for="req-resolved-song-name-<?= (int)$r['Id'] ?>">Resolved SongId (optional)</label>
                                    <!-- #1867 (epic #1863, rule #43) — search-select ONLY: this box is
                                         a live search over tblSongs (reusing the ?action=song_search
                                         handler above), wired via the shared window.iHymnsPlaceSearch
                                         typeahead (see the script block near the end of this page).
                                         Picking a candidate fills the hidden req-resolved-song-id
                                         below with the REAL tblSongs.SongId; the POST handler above
                                         re-verifies it server-side before writing — a free-typed value
                                         that doesn't match a real, visible song is rejected there, not
                                         trusted here. No create arm: curators link an existing song,
                                         never mint one from the requests queue. -->
                                    <input type="text" class="form-control form-control-sm req-resolved-song-name"
                                           id="req-resolved-song-name-<?= (int)$r['Id'] ?>"
                                           value="<?= htmlspecialchars((string)($r['ResolvedSongId'] ?? '')) ?>"
                                           placeholder="Search by title or song id…" autocomplete="off">
                                    <input type="hidden" name="resolved_song_id" class="req-resolved-song-id"
                                           value="<?= htmlspecialchars((string)($r['ResolvedSongId'] ?? '')) ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small" for="req-admin-notes-<?= (int)$r['Id'] ?>">Admin notes</label>
                                    <input type="text" name="admin_notes" id="req-admin-notes-<?= (int)$r['Id'] ?>" class="form-control form-control-sm"
                                           value="<?= htmlspecialchars($r['AdminNotes'] ?? '') ?>">
                                </div>
                                <div class="col-12 d-flex gap-2 mt-2">
                                    <button type="submit" class="btn btn-sm btn-amber-solid">Save</button>
                                    <a href="/manage/editor/" class="btn btn-sm btn-outline-primary">
                                        <i aria-hidden="true" class="bi bi-pencil-square me-1"></i>Open editor
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

<!-- Sortable table headers (#1786 sweep — tagged cp-sortable but never
     booted; every header click was a silent no-op until now). -->
<script type="module">
    import { bootSortableTables } from '/js/modules/admin-table-sort.js?v=<?= filemtime(dirname(__DIR__) . '/js/modules/admin-table-sort.js') ?>';
    bootSortableTables();
</script>

<?php if (!empty($rows)): ?>
<!-- Live song search for the "Resolved SongId" picker (#1867, epic #1863, rule #43). -->
<script src="/js/modules/place-search.js?v=<?= filemtime(dirname(__DIR__) . '/js/modules/place-search.js') ?>"></script>
<script>
    (function () {
        if (!window.iHymnsPlaceSearch) return;
        /* #1867 — one attach() per row's collapsible edit form; every row's
           form is in the DOM at load (Bootstrap .collapse only hides the
           inactive ones via CSS), so this loops over all of them rather than
           hardcoding one row's ids. pickMode:'value': the pick fills the
           input + hidden id with NO network call of its own — the form's own
           POST is the ONE commit, and the server-side check added to the
           POST handler above VERIFIES the id names a real, visible tblSongs
           row before writing it. Reuses the EXISTING ?action=song_search
           handler on THIS page (rule #22) — no new search endpoint was
           written for this picker; mirrors manage/catalogues.php's #1866
           "Add a song" wiring almost verbatim. */
        document.querySelectorAll('form.req-update-form').forEach((form) => {
            const nameInput = form.querySelector('.req-resolved-song-name');
            const hiddenId  = form.querySelector('.req-resolved-song-id');
            if (!nameInput || !hiddenId) return;

            window.iHymnsPlaceSearch.attach(nameInput, {
                hiddenIdInput: hiddenId,
                minChars: 2,
                pickMode: 'value',
                noun: { singular: 'song', plural: 'songs' },
                searchUrl: (q) => '/manage/requests?action=song_search&q=' + encodeURIComponent(q) + '&limit=10',
                parseResults: (d) => (d.rows || []).map((r) => ({
                    id: r.id,
                    display_name: r.title,
                    hint: [r.songbook || '', r.number ? '#' + r.number : ''].filter(Boolean).join(' '),
                })),
            });

            /* Optional field — an EMPTY box is a legitimate "not resolved
               yet" and must submit cleanly (resolved_song_id='' → NULL,
               unchanged from before this picker existed). What must NOT
               happen is a NON-empty box silently submitting an empty hidden
               id: place-search.js's onInput() clears the hidden id on every
               keystroke (free-typing invalidates a stale pick), so an admin
               who types a SongId by hand and submits WITHOUT clicking a
               suggestion would otherwise have their edit silently discarded
               — the exact "looks alive, does nothing" failure class CLAUDE.md
               rule #30 warns about, here in a different mechanism (a cleared
               hidden field, not a CSP block). This guard only fires when
               there IS typed text but NO confirmed pick, mirroring
               catalogues.php's #1866 guard but conditional rather than
               required (this field, unlike that one, may be intentionally
               empty). The server-side existence check remains the real
               guarantee either way — this is a UX guard only. */
            form.addEventListener('submit', (ev) => {
                if (nameInput.value.trim() !== '' && !hiddenId.value) {
                    ev.preventDefault();
                    nameInput.setCustomValidity('Pick a song from the search results, or clear this box to leave it unresolved.');
                    nameInput.reportValidity();
                }
            });
            nameInput.addEventListener('input', () => nameInput.setCustomValidity(''));
        });
    })();
</script>
<?php endif; ?>

<?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>
</body>
</html>
