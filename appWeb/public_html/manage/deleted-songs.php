<?php

declare(strict_types=1);

/**
 * iHymns — Admin: Deleted Songs (#1694, stage 2 of epic #1692)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * The soft-delete queue: every song a curator has deleted sits here, hidden
 * from all public and editorial read surfaces but fully intact — components,
 * credits, media links, tags and the complete revision history included.
 * `WHERE IsDeleted = 1` IS the queue (plan §1's adversarial table: no extra
 * workflow schema needed); the four companion columns answer who/when/why.
 *
 * Two actions, deliberately unequal:
 *   - RESTORE (`delete_songs`, the page gate) — clears the five soft-delete
 *     columns via songRestore(). Because a soft delete writes NOTHING else
 *     anywhere (no redirect rows, no cascades fired), this one UPDATE is the
 *     entire recovery: favourites, work membership, revisions and redirect
 *     chains were never touched, so nothing needs repair.
 *   - PURGE (`purge_songs`, per-action gate — owner decision D3) — the real,
 *     irreversible cascade delete via songPurge(), reachable ONLY from the
 *     deleted state (two-step by construction: nothing on this page or
 *     anywhere else purges a live song). Guarded by a TYPE-TO-CONFIRM that is
 *     enforced SERVER-SIDE (the #1218 §2 pattern, hardened: the client arms
 *     the button, but the POST itself must carry the typed id — a replayed or
 *     hand-crafted request cannot skip the ceremony). The optional relink
 *     target is chosen HERE, at purge time, because that is when the redirect
 *     dance actually runs (#1679: a soft delete never writes redirects, so
 *     restore stays a no-op on redirect state).
 *
 * WHY `purge_songs` IS A SEPARATE ENTITLEMENT: #1695 (stage 3) re-widens
 * `delete_songs` to editor+ once deletion is recoverable. Without its own
 * key, the irreversible purge would silently widen with it — or need a
 * hardcoded role check, the exact red-flag pattern the review list bans.
 *
 * POSTs are JSON-in/JSON-out under validateCsrfRequest() (rule #29 — the
 * same-origin X-Requested-With route never goes stale on a long-open tab;
 * a still-valid session token also passes). Refusal statuses come from the
 * ONE songSoftDeleteVerdict() core and are relayed as-is: 400 / 404 / 409
 * un-migrated-or-wrong-state / 422. Status is the contract (rule #35).
 *
 * The listing deliberately reads hidden rows — that is its entire job.
 * @deleted-visible: THIS PAGE IS THE SOFT-DELETE QUEUE (#1694) — the one
 * surface that must show soft-deleted songs, so its SELECT filters
 * IsDeleted = 1 IN rather than OUT.
 *
 * @see appWeb/public_html/includes/song_soft_delete.php  the lifecycle cores
 * @see appWeb/public_html/manage/duplicate-songs.php     the #1218 type-to-confirm this copies
 * @see tests/php/test-admin-gate-parity.php              derives the nav↔gate pairing incl. this page
 * @see .claude/plan-song-soft-delete-2026-07-31.md       §4 (this screen)
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_mysql.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'song_soft_delete.php';

if (!isAuthenticated()) {
    header('Location: /manage/login');
    exit;
}
$currentUser = getCurrentUser();
$role        = $currentUser['role'] ?? null;
/* Page gate = the entitlement the nav row advertises (admin-links.php) —
   test-admin-gate-parity.php fails the build if the two ever drift (#1587). */
if (!$currentUser || !userHasEntitlement('delete_songs', $role)) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><body><h1>403 — delete_songs required</h1></body></html>';
    exit;
}
/* The destructive purge is gated PER-ACTION, like duplicate-songs' Merge:
   curators with delete_songs can restore; only purge_songs holders destroy. */
$canPurge = userHasEntitlement('purge_songs', $role);

$activePage = 'deleted-songs';
$db         = getDbMysqli();
$csrf       = csrfToken();
$userId     = (int)($currentUser['id'] ?? 0) ?: null;

/* ----------------------------------------------------------------------
 * POST dispatcher — JSON in / JSON out (the duplicate-songs shape).
 * ---------------------------------------------------------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    header('Content-Type: application/json; charset=UTF-8');

    /* Rule #29: the robust same-origin check, never a baked token compared
       alone — a long-open queue tab must not sporadically 403 a legitimate
       restore. (The client sends X-Requested-With AND the token; either
       route passing is enough.) */
    if (!validateCsrfRequest((string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['error' => 'CSRF check failed — please reload and retry.']);
        exit;
    }

    $action = (string)($_POST['action'] ?? '');
    $songId = trim((string)($_POST['song_id'] ?? ''));

    /* -------- restore (delete_songs — the page gate already passed) -------- */
    if ($action === 'restore') {
        /* Un-migrated / wrong state / absent all refuse inside the ONE core;
           the status is relayed untouched (rule #35). */
        $verdict = songRestore($db, $songId, $userId);
        if (!$verdict['ok']) {
            http_response_code($verdict['status']);
            echo json_encode(['error' => $verdict['error']]);
            exit;
        }
        logActivity('song.restore', 'song', $songId, [
            'title'    => (string)($verdict['title'] ?? ''),
            'songbook' => (string)($verdict['songbook'] ?? ''),
        ]);
        echo json_encode(['success' => true, 'songId' => $songId]);
        exit;
    }

    /* -------- purge (purge_songs only; server-enforced type-to-confirm) ---- */
    if ($action === 'purge') {
        if (!$canPurge) {
            http_response_code(403);
            echo json_encode(['error' => 'Purge requires the purge_songs entitlement.']);
            exit;
        }
        /* The typed id must arrive WITH the request (#1218 §2, hardened):
           client-side arming alone would let a replayed/hand-built POST skip
           the ceremony. Case-insensitive, matching the client comparison. */
        $confirm = trim((string)($_POST['confirm'] ?? ''));
        if ($songId === '' || strcasecmp($confirm, $songId) !== 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Type the exact song id to confirm the purge.']);
            exit;
        }
        /* The relink target is chosen HERE — the one place the redirect dance
           runs (#1679). Blank / self / unknown → tombstone, resolved by the core. */
        $redirectTo = trim((string)($_POST['redirect_to'] ?? ''));
        $verdict = songPurge($db, $songId, $userId, $redirectTo === '' ? null : $redirectTo);
        if (!$verdict['ok']) {
            http_response_code($verdict['status']);
            echo json_encode(['error' => $verdict['error']]);
            exit;
        }
        logActivity('song.purge', 'song', $songId, [
            'title'       => (string)($verdict['title'] ?? ''),
            'songbook'    => (string)($verdict['songbook'] ?? ''),
            'redirect_to' => $verdict['redirectTo'] ?? '(tombstone)',
        ]);
        echo json_encode([
            'success'    => true,
            'songId'     => $songId,
            'deleted'    => (int)($verdict['deleted'] ?? 0),
            'redirectTo' => $verdict['redirectTo'] ?? null,
        ]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Unknown action. Use: restore, purge.']);
    exit;
}

/* ----------------------------------------------------------------------
 * GET — load the queue. Column-existence-gated (#1228): on an un-migrated
 * install the SELECT below would THROW under STRICT, so it never runs and
 * the page renders a "migration pending" card instead of a white screen.
 * ---------------------------------------------------------------------- */
$deleted   = [];
$ready     = false;
$loadError = '';
try {
    $ready = songSoftDeleteReady($db);
    if ($ready) {
        /* LEFT JOIN because fk_Songs_DeletedBy is ON DELETE SET NULL — the
           deleting account may since have been removed.
           @disabled-visible: admin surface (#1765) — disabled songbooks stay
           fully visible/editable in /manage (owner decision); a deleted song
           whose book is ALSO disabled must still show in this review queue
           so a curator can restore it. */
        $q = $db->prepare(
            'SELECT s.SongId, s.Title, s.SongbookAbbr, s.DeletedAt, s.DeletedReason, s.DeleteNote,
                    u.Username AS DeletedByName
               FROM tblSongs s
               LEFT JOIN tblUsers u ON u.Id = s.DeletedBy
              WHERE s.IsDeleted = 1
              ORDER BY s.DeletedAt DESC, s.SongId ASC'
        );
        $q->execute();
        $deleted = $q->get_result()->fetch_all(MYSQLI_ASSOC);
        $q->close();
    }
} catch (\Throwable $e) {
    error_log('[deleted-songs] ' . $e->getMessage());
    $loadError = 'Could not load the deleted-songs queue — see server logs.';
}
$reasonLabels = songDeleteReasons();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Deleted Songs — iHymns Admin</title>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-libs.php'; ?>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-favicon.php'; ?>
</head>
<body>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-nav.php'; ?>

    <main class="container-admin py-4">
        <h1 class="h4 mb-1">
            <i class="bi bi-trash3 me-2" aria-hidden="true"></i>
            Deleted Songs
        </h1>
        <p class="text-secondary small mb-4">
            Songs a curator has deleted. They are hidden from every listing, search and export,
            but everything about them — sections, credits, media, tags and the full revision
            history — is intact. <strong>Restore</strong> brings a song back exactly as it was.
            <?php if ($canPurge): ?>
                <strong>Purge</strong> permanently destroys it (and can point its old link at
                another song); that cannot be undone.
            <?php else: ?>
                Permanent removal (purge) needs the <code>purge_songs</code> entitlement.
            <?php endif; ?>
        </p>

        <?php if ($loadError !== ''): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($loadError) ?></div>
        <?php elseif (!$ready): ?>
            <div class="alert alert-warning">
                <i class="bi bi-database-exclamation me-1" aria-hidden="true"></i>
                The <strong>Song soft delete (#1694)</strong> migration has not been applied on
                this install, so there is no deleted-songs queue yet — and the editor's Delete
                refuses (HTTP 409) rather than falling back to a permanent hard delete.
                Apply the card at <a href="/manage/setup-database">Database Setup</a>.
            </div>
        <?php elseif ($deleted === []): ?>
            <div class="alert alert-info small mb-0" role="status">
                <i class="bi bi-check-circle me-1" aria-hidden="true"></i>
                The queue is empty — no songs are currently deleted.
            </div>
        <?php else: ?>
            <div class="card-admin p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0 cp-sortable admin-table-responsive" id="deleted-songs-table">
                        <thead>
                            <tr>
                                <th scope="col" data-col-priority="primary"   data-sort-key="id"       data-sort-type="text">Song ID</th>
                                <th scope="col" data-col-priority="primary"   data-sort-key="title"    data-sort-type="text">Title</th>
                                <th scope="col" data-col-priority="secondary" data-sort-key="songbook" data-sort-type="text">Songbook</th>
                                <th scope="col" data-col-priority="secondary" data-sort-key="when"     data-sort-type="text">Deleted (UTC)</th>
                                <th scope="col" data-col-priority="tertiary"  data-sort-key="who"      data-sort-type="text">By</th>
                                <th scope="col" data-col-priority="tertiary"  data-sort-key="reason"   data-sort-type="text">Reason / note</th>
                                <th scope="col" data-col-priority="primary">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($deleted as $row):
                                $sid    = (string)$row['SongId'];
                                $reason = (string)($row['DeletedReason'] ?? '');
                                $note   = (string)($row['DeleteNote'] ?? '');
                            ?>
                            <tr data-song-row="<?= htmlspecialchars($sid, ENT_QUOTES) ?>">
                                <td data-col-priority="primary" class="font-monospace"><?= htmlspecialchars($sid) ?></td>
                                <td data-col-priority="primary"><?= htmlspecialchars((string)$row['Title']) ?></td>
                                <td data-col-priority="secondary"><?= htmlspecialchars((string)$row['SongbookAbbr']) ?></td>
                                <td data-col-priority="secondary" class="small text-secondary"><?= htmlspecialchars((string)($row['DeletedAt'] ?? '')) ?></td>
                                <td data-col-priority="tertiary" class="small"><?= htmlspecialchars((string)($row['DeletedByName'] ?? '—')) ?></td>
                                <td data-col-priority="tertiary" class="small">
                                    <?php if ($reason !== ''): ?>
                                        <span class="badge bg-body-secondary text-body"><?= htmlspecialchars($reasonLabels[$reason] ?? $reason) ?></span>
                                    <?php endif; ?>
                                    <?php if ($note !== ''): ?>
                                        <span class="text-secondary"><?= htmlspecialchars($note) ?></span>
                                    <?php endif; ?>
                                    <?php if ($reason === '' && $note === ''): ?>
                                        <span class="text-secondary">—</span>
                                    <?php endif; ?>
                                </td>
                                <td data-col-priority="primary">
                                    <button type="button" class="btn btn-sm btn-outline-success ds-restore-btn"
                                            data-song-id="<?= htmlspecialchars($sid, ENT_QUOTES) ?>"
                                            title="Bring this song back exactly as it was — nothing was lost">
                                        <i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Restore
                                    </button>
                                    <?php if ($canPurge): ?>
                                        <button type="button" class="btn btn-sm btn-outline-danger ds-purge-toggle"
                                                data-song-id="<?= htmlspecialchars($sid, ENT_QUOTES) ?>"
                                                aria-expanded="false"
                                                title="Permanently destroy this song — opens a type-to-confirm">
                                            <i class="bi bi-x-octagon me-1" aria-hidden="true"></i>Purge…
                                        </button>
                                        <!-- Type-to-confirm (#1218 §2): typing the id arms the button;
                                             the server independently re-checks the typed id. -->
                                        <div class="ds-purge-confirm d-none mt-2 p-2 border border-danger-subtle rounded">
                                            <div class="small text-danger-emphasis mb-1">
                                                Purging destroys this song, its data and its whole revision
                                                history. Type <strong class="font-monospace"><?= htmlspecialchars($sid) ?></strong> to confirm:
                                            </div>
                                            <div class="d-flex flex-wrap gap-2 align-items-center">
                                                <input type="text" class="form-control form-control-sm ds-confirm-input"
                                                       style="max-width: 11rem" autocomplete="off" spellcheck="false"
                                                       placeholder="<?= htmlspecialchars($sid, ENT_QUOTES) ?>"
                                                       aria-label="Type the song id to confirm the purge">
                                                <input type="text" class="form-control form-control-sm ds-redirect-input"
                                                       style="max-width: 13rem" autocomplete="off" spellcheck="false"
                                                       placeholder="Redirect to… (optional)"
                                                       aria-label="Optional song id the old link should redirect to"
                                                       title="Optional: another Song ID the purged song's old link should point at; blank leaves a friendly removed page">
                                                <button type="button" class="btn btn-sm btn-danger ds-purge-btn" disabled
                                                        data-song-id="<?= htmlspecialchars($sid, ENT_QUOTES) ?>">
                                                    <i class="bi bi-x-octagon me-1" aria-hidden="true"></i>Purge forever
                                                </button>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <p class="text-secondary small mt-2 mb-0">
                <?= count($deleted) ?> deleted song<?= count($deleted) === 1 ? '' : 's' ?>.
            </p>
        <?php endif; ?>
    </main>

    <script>
    (function () {
        'use strict';
        var CSRF = <?= json_encode($csrf) ?>;
        function toast(msg, ok) { if (window.showToast) { window.showToast(msg, ok ? 'success' : 'error'); } else { alert(msg); } }

        /* POST helper — rule #29's client half: X-Requested-With makes the
           same-origin route pass even after the baked token goes stale. */
        function post(params) {
            params.csrf_token = CSRF;
            return fetch('/manage/deleted-songs', {
                method: 'POST',
                body: new URLSearchParams(params),
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (r) {
                return r.json().then(function (j) {
                    if (!r.ok || !j.success) { throw new Error(j.error || 'Request failed (HTTP ' + r.status + ')'); }
                    return j;
                });
            });
        }

        function dropRow(songId) {
            var row = document.querySelector('tr[data-song-row="' + CSS.escape(songId) + '"]');
            if (row) { row.remove(); }
        }

        /* ---- Restore ---- */
        document.querySelectorAll('.ds-restore-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var id = btn.dataset.songId;
                if (!confirm('Restore ' + id + '? It reappears everywhere, exactly as it was.')) { return; }
                btn.disabled = true;
                post({ action: 'restore', song_id: id }).then(function () {
                    toast('Restored ' + id + '.', true);
                    dropRow(id);
                }).catch(function (e) { btn.disabled = false; toast(e.message || 'Restore failed.', false); });
            });
        });

        /* ---- Purge: reveal the confirm area ---- */
        document.querySelectorAll('.ds-purge-toggle').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var area = btn.parentElement.querySelector('.ds-purge-confirm');
                if (!area) { return; }
                /* classList.toggle returns true when the class is NOW PRESENT —
                   i.e. the area is now HIDDEN. Named accordingly (the inverse
                   reading is an easy silent a11y bug). */
                var nowHidden = area.classList.toggle('d-none');
                btn.setAttribute('aria-expanded', nowHidden ? 'false' : 'true');
                if (!nowHidden) { var inp = area.querySelector('.ds-confirm-input'); if (inp) { inp.focus(); } }
            });
        });

        /* ---- Purge: type-to-confirm arms the button (#1218 §2) ---- */
        document.querySelectorAll('.ds-purge-confirm').forEach(function (area) {
            var input = area.querySelector('.ds-confirm-input');
            var btn   = area.querySelector('.ds-purge-btn');
            if (!input || !btn) { return; }
            input.addEventListener('input', function () {
                btn.disabled = input.value.trim().toLowerCase() !== btn.dataset.songId.toLowerCase();
            });
            btn.addEventListener('click', function () {
                var id = btn.dataset.songId;
                var redirectTo = (area.querySelector('.ds-redirect-input') || { value: '' }).value.trim();
                btn.disabled = true;
                /* The typed id rides the POST — the server re-checks it, so a
                   replayed request without it is refused (400). */
                post({ action: 'purge', song_id: id, confirm: input.value.trim(), redirect_to: redirectTo })
                    .then(function (j) {
                        toast('Purged ' + id + (j.redirectTo ? ' — old link now redirects to ' + j.redirectTo + '.' : ' — old link shows a "removed" page.'), true);
                        dropRow(id);
                    })
                    .catch(function (e) { btn.disabled = false; toast(e.message || 'Purge failed.', false); });
            });
        });
    })();
    </script>

    <!-- Sortable table headers (#644 / #844). -->
    <script type="module">
        import { bootSortableTables } from '/js/modules/admin-table-sort.js?v=<?= filemtime(dirname(__DIR__) . '/js/modules/admin-table-sort.js') ?>';
        bootSortableTables();
    </script>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>
</body>
</html>
