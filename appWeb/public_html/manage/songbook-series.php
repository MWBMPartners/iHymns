<?php

declare(strict_types=1);

/**
 * iHymns — Admin: Songbook Series (#782 phase C)
 *
 * CRUD surface for `tblSongbookSeries` + the membership join table
 * `tblSongbookSeriesMembership`. Series capture peer-to-peer
 * collection identity for songbooks that don't fit the hierarchical
 * parent FK shape from phase B — primarily Songs of Fellowship
 * volumes 1/2/3/4 and themed compilations where every member is
 * an equal (no canonical root to point upward at).
 *
 * Gated by the `manage_songbooks` entitlement — same as
 * /manage/songbooks. Series are a flavour of songbook metadata, not
 * a separate domain, so the same role gate covers both.
 *
 * Pre-migration safe: probes for tblSongbookSeries on every page
 * load. Deployments that haven't run migrate-parent-songbooks.php
 * (#782 phase A) get a one-line CTA pointing at /manage/setup-database
 * instead of a fatal SQL error.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_mysql.php';

if (!isAuthenticated()) {
    header('Location: /manage/login');
    exit;
}
$currentUser = getCurrentUser();
if (!$currentUser || !userHasEntitlement('manage_songbooks', $currentUser['role'] ?? null)) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><body><h1>403 — manage_songbooks required</h1></body></html>';
    exit;
}
$activePage = 'songbook-series';

$error   = '';
$success = '';
$db      = getDbMysqli();
$csrf    = csrfToken();

/* ----- Helpers ----- */

/**
 * Generate a URL-safe lowercase slug from a free-text name. ASCII-only;
 * non-ASCII characters are dropped (the slug is a routing key, not a
 * display field — the original Name keeps the unicode). Multiple
 * separator chars collapse to one hyphen; leading/trailing hyphens
 * are stripped.
 */
$slugFor = static function (string $name): string {
    $ascii = (string)preg_replace('/[^A-Za-z0-9]+/u', '-', $name);
    return trim(strtolower($ascii), '-');
};

/* Schema probe — if tblSongbookSeries isn't live yet, render a friendly
   "run the migration" page instead of letting every prepared statement
   blow up. The nav still renders so the curator can navigate away. */
$hasSchema = false;
try {
    $probe = $db->prepare(
        "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblSongbookSeries' LIMIT 1"
    );
    $probe->execute();
    $hasSchema = $probe->get_result()->fetch_row() !== null;
    $probe->close();
} catch (\Throwable $e) {
    error_log('[songbook-series] schema probe failed: ' . $e->getMessage());
}

/* ---- GET ?action=songbook_search&q=… (#782 phase C) -------------------
 * JSON typeahead for the edit-modal's "Add a member" input. Returns
 * matching rows from tblSongbooks ranked by name. Excludes songbooks
 * already in the series via `exclude_ids` (comma-separated) so a
 * curator never sees a duplicate add suggestion.
 *
 * Same auth gate as the page itself — already enforced by the require
 * blocks above; we don't re-check here.
 * ----------------------------------------------------------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
    && ($_GET['action'] ?? '') === 'songbook_search'
) {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    $q     = trim((string)($_GET['q'] ?? ''));
    $limit = max(1, min(50, (int)($_GET['limit'] ?? 20)));
    /* exclude_ids is the comma-separated list of songbook ids already
       in the series being edited. Sanitised to ints so a hand-crafted
       query string can't smuggle SQL through the IN clause. */
    $exclRaw = (string)($_GET['exclude_ids'] ?? '');
    $excl    = array_values(array_filter(array_map('intval', explode(',', $exclRaw))));
    $exclPh  = $excl ? implode(',', array_fill(0, count($excl), '?')) : '0';
    try {
        $like = '%' . $q . '%';
        if ($q === '') {
            $sql = "SELECT Id, Abbreviation, Name
                      FROM tblSongbooks
                     WHERE Id NOT IN ($exclPh)
                     ORDER BY Name ASC
                     LIMIT ?";
            $stmt  = $db->prepare($sql);
            $types = str_repeat('i', count($excl) ?: 1) . 'i';
            $args  = $excl ?: [0];
            $args[] = $limit;
            $stmt->bind_param($types, ...$args);
        } else {
            $sql = "SELECT Id, Abbreviation, Name
                      FROM tblSongbooks
                     WHERE Id NOT IN ($exclPh)
                       AND (Name LIKE ? OR Abbreviation LIKE ?)
                     ORDER BY Name ASC
                     LIMIT ?";
            $stmt  = $db->prepare($sql);
            $types = str_repeat('i', count($excl) ?: 1) . 'ssi';
            $args  = $excl ?: [0];
            $args[] = $like;
            $args[] = $like;
            $args[] = $limit;
            $stmt->bind_param($types, ...$args);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        $suggestions = [];
        while ($row = $res->fetch_assoc()) {
            $suggestions[] = [
                'id'           => (int)$row['Id'],
                'abbreviation' => (string)$row['Abbreviation'],
                'name'         => (string)$row['Name'],
            ];
        }
        $stmt->close();
        echo json_encode(['suggestions' => $suggestions], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        error_log('[songbook-series songbook_search] ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Search failed.']);
    }
    exit;
}

/* ----- POST actions ----- */
if ($hasSchema && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!validateCsrf((string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo 'Invalid CSRF token';
        exit;
    }
    $action = (string)($_POST['action'] ?? '');
    try {
        switch ($action) {
            case 'create': {
                $name        = trim((string)($_POST['name']        ?? ''));
                $description = trim((string)($_POST['description'] ?? ''));
                $slug        = trim((string)($_POST['slug']        ?? ''));
                if ($name === '') { $error = 'Name is required.'; break; }
                if ($slug === '') { $slug  = $slugFor($name); }
                if ($slug === '') { $error = 'Name has no usable slug characters — provide one explicitly.'; break; }
                /* Cap to schema widths (Name 120, Slug 120, Description 255). */
                $name        = mb_substr($name, 0, 120);
                $slug        = mb_substr($slug, 0, 120);
                $description = mb_substr($description, 0, 255);

                /* UNIQUE on Slug → check before insert for a friendly error. */
                $stmt = $db->prepare('SELECT Id FROM tblSongbookSeries WHERE Slug = ?');
                $stmt->bind_param('s', $slug);
                $stmt->execute();
                $exists = $stmt->get_result()->fetch_row() !== null;
                $stmt->close();
                if ($exists) { $error = "Slug '{$slug}' already taken — pick another."; break; }

                $stmt = $db->prepare(
                    'INSERT INTO tblSongbookSeries (Name, Slug, Description) VALUES (?, ?, ?)'
                );
                $stmt->bind_param('sss', $name, $slug, $description);
                $stmt->execute();
                $newId = (int)$db->insert_id;
                $stmt->close();
                if (function_exists('logActivity')) {
                    logActivity('songbook_series.create', 'songbook_series', (string)$newId, [
                        'name' => $name, 'slug' => $slug, 'description' => $description,
                    ]);
                }
                $success = "Series '{$name}' created.";
                break;
            }

            case 'update': {
                $id          = (int)($_POST['id']          ?? 0);
                $name        = trim((string)($_POST['name']        ?? ''));
                $description = trim((string)($_POST['description'] ?? ''));
                $slug        = trim((string)($_POST['slug']        ?? ''));
                if ($id <= 0)     { $error = 'Series id is required.'; break; }
                if ($name === '') { $error = 'Name is required.'; break; }
                if ($slug === '') { $slug  = $slugFor($name); }
                if ($slug === '') { $error = 'Name has no usable slug characters — provide one explicitly.'; break; }
                $name        = mb_substr($name, 0, 120);
                $slug        = mb_substr($slug, 0, 120);
                $description = mb_substr($description, 0, 255);

                /* Pull the before-row for the audit log + collision check. */
                $stmt = $db->prepare('SELECT Name, Slug, Description FROM tblSongbookSeries WHERE Id = ?');
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $before = $stmt->get_result()->fetch_assoc() ?: null;
                $stmt->close();
                if (!$before) { $error = 'Series not found.'; break; }

                /* Slug uniqueness check (excluding self). */
                $dup = $db->prepare('SELECT Id FROM tblSongbookSeries WHERE Slug = ? AND Id <> ?');
                $dup->bind_param('si', $slug, $id);
                $dup->execute();
                $dupExists = $dup->get_result()->fetch_row() !== null;
                $dup->close();
                if ($dupExists) { $error = "Slug '{$slug}' already taken by another series."; break; }

                /* Reconcile membership rows. The edit modal posts:
                     member_ids[]            = [12, 47, 99]
                     member_sort[<id>]       = '10'
                   Anything in the DB but not in member_ids is removed;
                   the rest is upserted with the posted SortOrder. Note
                   field is intentionally not exposed in v1 — the schema
                   carries it for future use; v2 can light up an inline
                   editor if curators ask. */
                $postedIds = $_POST['member_ids']  ?? [];
                $postedSrt = $_POST['member_sort'] ?? [];
                if (!is_array($postedIds)) $postedIds = [];
                if (!is_array($postedSrt)) $postedSrt = [];
                $postedIds = array_values(array_unique(array_map('intval', $postedIds)));
                $postedIds = array_values(array_filter($postedIds, static fn(int $v): bool => $v > 0));

                $db->begin_transaction();
                try {
                    $stmt = $db->prepare(
                        'UPDATE tblSongbookSeries
                            SET Name = ?, Slug = ?, Description = ?
                          WHERE Id = ?'
                    );
                    $stmt->bind_param('sssi', $name, $slug, $description, $id);
                    $stmt->execute();
                    $stmt->close();

                    /* Step 1: remove rows not in $postedIds. Schema is
                       composite-PK (SeriesId, SongbookId) so DELETE is
                       cheap and safe even on a large series. */
                    if ($postedIds) {
                        $ph = implode(',', array_fill(0, count($postedIds), '?'));
                        $sql = "DELETE FROM tblSongbookSeriesMembership
                                 WHERE SeriesId = ?
                                   AND SongbookId NOT IN ($ph)";
                        $stmt = $db->prepare($sql);
                        $types = 'i' . str_repeat('i', count($postedIds));
                        $args  = array_merge([$id], $postedIds);
                        $stmt->bind_param($types, ...$args);
                        $stmt->execute();
                        $stmt->close();
                    } else {
                        $stmt = $db->prepare(
                            'DELETE FROM tblSongbookSeriesMembership WHERE SeriesId = ?'
                        );
                        $stmt->bind_param('i', $id);
                        $stmt->execute();
                        $stmt->close();
                    }

                    /* Step 2: upsert each posted membership with its
                       SortOrder. ON DUPLICATE KEY UPDATE keeps the
                       statement single-shot and idempotent on re-saves. */
                    if ($postedIds) {
                        $stmt = $db->prepare(
                            'INSERT INTO tblSongbookSeriesMembership
                                 (SeriesId, SongbookId, SortOrder)
                             VALUES (?, ?, ?)
                             ON DUPLICATE KEY UPDATE SortOrder = VALUES(SortOrder)'
                        );
                        foreach ($postedIds as $sbId) {
                            $sortOrder = isset($postedSrt[$sbId])
                                ? max(0, min(32767, (int)$postedSrt[$sbId]))
                                : 0;
                            $stmt->bind_param('iii', $id, $sbId, $sortOrder);
                            $stmt->execute();
                        }
                        $stmt->close();
                    }

                    $db->commit();

                    if (function_exists('logActivity')) {
                        $changed = [];
                        if ((string)$before['Name']        !== $name)        $changed[] = 'Name';
                        if ((string)$before['Slug']        !== $slug)        $changed[] = 'Slug';
                        if ((string)$before['Description'] !== $description) $changed[] = 'Description';
                        logActivity('songbook_series.edit', 'songbook_series', (string)$id, [
                            'fields'           => $changed,
                            'before'           => array_intersect_key($before, array_flip($changed)),
                            'after'            => array_intersect_key([
                                'Name' => $name, 'Slug' => $slug, 'Description' => $description,
                            ], array_flip($changed)),
                            'member_count'     => count($postedIds),
                        ]);
                    }
                    $success = "Series '{$name}' updated.";
                } catch (\Throwable $e) {
                    $db->rollback();
                    throw $e;
                }
                break;
            }

            case 'delete': {
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) { $error = 'Series id is required.'; break; }
                /* FK on tblSongbookSeriesMembership is ON DELETE CASCADE
                   so removing the series cleans up its membership rows in
                   the same transaction. */
                $stmt = $db->prepare('SELECT Name FROM tblSongbookSeries WHERE Id = ?');
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$row) { $error = 'Series not found.'; break; }
                $oldName = (string)$row['Name'];

                $stmt = $db->prepare('DELETE FROM tblSongbookSeries WHERE Id = ?');
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $stmt->close();
                if (function_exists('logActivity')) {
                    logActivity('songbook_series.delete', 'songbook_series', (string)$id, [
                        'name' => $oldName,
                    ]);
                }
                $success = "Series '{$oldName}' deleted.";
                break;
            }
            default:
                $error = "Unknown action '{$action}'.";
        }
    } catch (\Throwable $e) {
        $error = $error ?: 'Database error: ' . $e->getMessage();
        error_log('[songbook-series POST ' . $action . '] ' . $e->getMessage());
    }
}

/* ----- GET: list ----- */
$rows = [];
if ($hasSchema) {
    try {
        $stmt = $db->prepare(
            'SELECT s.Id, s.Name, s.Slug, s.Description, s.CreatedAt,
                    (SELECT COUNT(*) FROM tblSongbookSeriesMembership m WHERE m.SeriesId = s.Id) AS MemberCount
               FROM tblSongbookSeries s
              ORDER BY s.Name ASC'
        );
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        /* Pull every membership row in one query — keyed by SeriesId
           on the PHP side so the row payload for the edit modal can
           include them inline. Cheap: catalogues will have <100 series
           and <500 memberships in practice. */
        $allMembers = [];
        $res = $db->query(
            'SELECT m.SeriesId, m.SongbookId, m.SortOrder,
                    b.Abbreviation, b.Name
               FROM tblSongbookSeriesMembership m
               JOIN tblSongbooks b ON b.Id = m.SongbookId
              ORDER BY m.SortOrder ASC, b.Name ASC'
        );
        if ($res) {
            while ($mrow = $res->fetch_assoc()) {
                $sid = (int)$mrow['SeriesId'];
                if (!isset($allMembers[$sid])) $allMembers[$sid] = [];
                $allMembers[$sid][] = [
                    'songbook_id'  => (int)$mrow['SongbookId'],
                    'sort_order'   => (int)$mrow['SortOrder'],
                    'abbreviation' => (string)$mrow['Abbreviation'],
                    'name'         => (string)$mrow['Name'],
                ];
            }
            $res->close();
        }
    } catch (\Throwable $e) {
        error_log('[songbook-series list] ' . $e->getMessage());
        $error = $error ?: 'Could not load series: ' . $e->getMessage();
    }
} else {
    $allMembers = [];
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Songbook Series — iHymns Admin</title>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-libs.php'; ?>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-favicon.php'; ?>
</head>
<body>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-nav.php'; ?>

    <div class="container-admin py-4">

        <h1 class="h4 mb-3"><i class="bi bi-collection me-2"></i>Songbook Series</h1>
        <p class="text-secondary small mb-4">
            Group songbooks that share collection identity but have no canonical parent —
            Songs of Fellowship volumes 1/2/3/4, themed compilations, etc. For
            translation / edition relationships use the <strong>Parent songbook</strong>
            field on <a href="/manage/songbooks">/manage/songbooks</a> instead (#782).
        </p>

        <?php if ($success): ?>
            <div class="alert alert-success py-2"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if (!$hasSchema): ?>
            <div class="card-admin p-4 text-center">
                <p class="mb-2">
                    <i class="bi bi-database-exclamation text-warning fs-1" aria-hidden="true"></i>
                </p>
                <h2 class="h6 mb-2">Schema not yet installed</h2>
                <p class="text-muted small mb-3">
                    The <code>tblSongbookSeries</code> + <code>tblSongbookSeriesMembership</code>
                    tables haven't been created on this database yet (#782 phase A schema).
                </p>
                <a href="/manage/setup-database" class="btn btn-amber btn-sm">
                    <i class="bi bi-database-gear me-1"></i>Run /manage/setup-database
                </a>
            </div>
        <?php else: ?>

        <!-- Series list -->
        <div class="card-admin p-3 mb-4">
            <h2 class="h6 mb-3"><i class="bi bi-list-ul me-2"></i>Existing series</h2>
            <table class="table table-sm align-middle cp-sortable mb-0 admin-table-responsive">
                <thead>
                    <tr class="text-muted small">
                        <th data-col-priority="primary"   data-sort-key="name" data-sort-type="text">Name</th>
                        <th data-col-priority="secondary" data-sort-key="slug" data-sort-type="text">Slug</th>
                        <th data-col-priority="primary"   data-sort-key="members" data-sort-type="number" class="text-center">Members</th>
                        <th data-col-priority="tertiary"  data-sort-key="description" data-sort-type="text">Description</th>
                        <th data-col-priority="primary"   class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                        <?php
                            $sid     = (int)$r['Id'];
                            $members = $allMembers[$sid] ?? [];
                            /* Build the row payload for the edit modal once,
                               then escape for HTML attribute embedding — same
                               apostrophe-safe pattern as #774 used on
                               /manage/songbooks. */
                            $rowJson = json_encode([
                                'id'          => $sid,
                                'name'        => (string)$r['Name'],
                                'slug'        => (string)$r['Slug'],
                                'description' => (string)$r['Description'],
                                'members'     => $members,
                            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                            $deleteJson = json_encode([
                                'id'   => $sid,
                                'name' => (string)$r['Name'],
                            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        ?>
                        <tr>
                            <td data-col-priority="primary"><?= htmlspecialchars((string)$r['Name']) ?></td>
                            <td data-col-priority="secondary"><code><?= htmlspecialchars((string)$r['Slug']) ?></code></td>
                            <td data-col-priority="primary" class="text-center"><?= count($members) ?></td>
                            <td data-col-priority="tertiary"><small class="text-muted"><?= htmlspecialchars((string)$r['Description']) ?></small></td>
                            <td data-col-priority="primary" class="text-end">
                                <button type="button" class="btn btn-sm btn-outline-info"
                                        onclick="openSeriesEditModal(<?= htmlspecialchars((string)$rowJson, ENT_QUOTES, 'UTF-8') ?>)"
                                        title="Edit series + members">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-danger"
                                        onclick="openSeriesDeleteModal(<?= htmlspecialchars((string)$deleteJson, ENT_QUOTES, 'UTF-8') ?>)"
                                        title="Delete series (memberships cascade)">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$rows): ?>
                        <tr><td colspan="5" class="text-muted text-center py-4">No series yet. Add one below.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Create -->
        <form method="POST" class="card-admin p-3 mb-4">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="create">
            <h2 class="h6 mb-3"><i class="bi bi-plus-circle me-2"></i>Add a series</h2>
            <div class="row g-2">
                <div class="col-sm-5">
                    <label class="form-label small">Name</label>
                    <input type="text" name="name" id="create-name"
                           class="form-control form-control-sm"
                           maxlength="120" required
                           placeholder="e.g. Songs of Fellowship">
                </div>
                <div class="col-sm-3">
                    <label class="form-label small">Slug
                        <small class="text-muted">(auto)</small>
                    </label>
                    <input type="text" name="slug" id="create-slug"
                           class="form-control form-control-sm"
                           maxlength="120" pattern="[a-z0-9-]+"
                           placeholder="songs-of-fellowship">
                </div>
                <div class="col-sm-4">
                    <label class="form-label small">Description (optional)</label>
                    <input type="text" name="description"
                           class="form-control form-control-sm"
                           maxlength="255"
                           placeholder="Brief context for curators">
                </div>
            </div>
            <button type="submit" class="btn btn-amber btn-sm mt-3">
                <i class="bi bi-plus me-1"></i>Create series
            </button>
        </form>

        <!-- Edit Modal -->
        <div class="modal fade" id="seriesEditModal" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <div class="modal-content" style="background: var(--ih-surface); color: var(--ih-text); border-color: var(--ih-border);">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="id" id="edit-id">
                        <div class="modal-header" style="border-color: var(--ih-border);">
                            <h5 class="modal-title">
                                <i class="bi bi-pencil me-2"></i>Edit series — <span id="edit-title-label"></span>
                            </h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="row g-2 mb-3">
                                <div class="col-sm-7">
                                    <label class="form-label">Name</label>
                                    <input type="text" name="name" id="edit-name"
                                           class="form-control" maxlength="120" required>
                                </div>
                                <div class="col-sm-5">
                                    <label class="form-label">Slug</label>
                                    <input type="text" name="slug" id="edit-slug"
                                           class="form-control" maxlength="120"
                                           pattern="[a-z0-9-]+">
                                    <div class="form-text small">URL-safe lowercase id used by /series/&lt;slug&gt; pages.</div>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Description (optional)</label>
                                <input type="text" name="description" id="edit-description"
                                       class="form-control" maxlength="255">
                            </div>

                            <hr>

                            <h6 class="mb-2"><i class="bi bi-collection me-2"></i>Members</h6>
                            <p class="form-text small mt-0 mb-2">
                                Each row is a member songbook. Sort-order controls display order
                                within the series (10-spaced steps suggested — leaves room to slot
                                in volumes later).
                            </p>

                            <table class="table table-sm align-middle mb-2" id="edit-members-table">
                                <thead>
                                    <tr class="text-muted small">
                                        <th style="width:6rem">Sort</th>
                                        <th style="width:6rem">Abbr</th>
                                        <th>Name</th>
                                        <th class="text-end" style="width:3rem"></th>
                                    </tr>
                                </thead>
                                <tbody id="edit-members-tbody">
                                    <!-- Populated by openSeriesEditModal() -->
                                </tbody>
                                <tfoot>
                                    <tr id="edit-members-empty-row" style="display:none;">
                                        <td colspan="4" class="text-muted text-center py-3 small">
                                            No members yet — add one below.
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>

                            <div class="d-flex gap-2 align-items-end">
                                <div class="flex-grow-1">
                                    <label class="form-label small mb-1">Add a member songbook</label>
                                    <input type="text" id="edit-add-search"
                                           class="form-control form-control-sm"
                                           list="edit-add-datalist"
                                           autocomplete="off"
                                           placeholder="Type to search by name or abbreviation…">
                                    <datalist id="edit-add-datalist"></datalist>
                                </div>
                                <button type="button" class="btn btn-outline-info btn-sm" id="edit-add-btn">
                                    <i class="bi bi-plus-lg me-1"></i>Add
                                </button>
                            </div>
                        </div>
                        <div class="modal-footer" style="border-color: var(--ih-border);">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-amber-solid">Save changes</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Delete Modal -->
        <div class="modal fade" id="seriesDeleteModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content" style="background: var(--ih-surface); color: var(--ih-text); border-color: var(--ih-border);">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" id="delete-id">
                        <div class="modal-header" style="border-color: var(--ih-border);">
                            <h5 class="modal-title">
                                <i class="bi bi-trash me-2"></i>Delete series — <span id="delete-name-label"></span>
                            </h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <p class="text-warning mb-2">
                                <strong>The series will be removed.</strong> Membership rows
                                cascade-delete with it; the underlying songbooks are untouched.
                            </p>
                            <p class="text-muted small">
                                Songbooks that were in this series will simply no longer be grouped.
                                Their <code>ParentSongbookId</code> (if any) is independent of series
                                membership and stays intact.
                            </p>
                        </div>
                        <div class="modal-footer" style="border-color: var(--ih-border);">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-danger">Delete</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <script>
        /* ============================================================
           Edit-modal state + helpers (vanilla JS — same shape as the
           songbooks.php inline scripts, no external module).
           ============================================================ */
        (function () {
            const tbody       = document.getElementById('edit-members-tbody');
            const emptyRow    = document.getElementById('edit-members-empty-row');
            const search      = document.getElementById('edit-add-search');
            const datalist    = document.getElementById('edit-add-datalist');
            const addBtn      = document.getElementById('edit-add-btn');
            if (!tbody) return;

            /* Last-fetched suggestion list, keyed by visible label so the
               Add button can resolve a typed value back to a songbook id
               without an extra round-trip. */
            let labelToSuggestion = new Map();
            let inflight  = null;
            let debounce  = null;

            const refreshEmptyRow = () => {
                emptyRow.style.display = tbody.children.length === 0 ? '' : 'none';
            };

            const escapeHtml = (s) =>
                String(s).replace(/[&<>"']/g, c => ({
                    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
                })[c]);

            window.seriesAddMember = function (id, abbr, name, sortOrder) {
                /* Skip if this id is already in the table — defensive
                   guard so a curator double-clicking Add can't post two
                   identical entries (the schema would reject the second
                   on the composite PK, but the friendly behaviour is
                   silent dedupe). */
                if (tbody.querySelector('[data-member-id="' + id + '"]')) return;
                const sort = (typeof sortOrder === 'number' && sortOrder >= 0)
                    ? sortOrder
                    : (tbody.children.length + 1) * 10;
                const tr = document.createElement('tr');
                tr.dataset.memberId = String(id);
                tr.innerHTML =
                    '<td>'
                  + '  <input type="number" class="form-control form-control-sm"'
                  + '         min="0" max="32767"'
                  + '         name="member_sort[' + id + ']"'
                  + '         value="' + sort + '">'
                  + '  <input type="hidden" name="member_ids[]" value="' + id + '">'
                  + '</td>'
                  + '<td><code>' + escapeHtml(abbr) + '</code></td>'
                  + '<td>' + escapeHtml(name) + '</td>'
                  + '<td class="text-end">'
                  + '  <button type="button" class="btn btn-sm btn-outline-danger"'
                  + '          title="Remove from series" data-remove-member="' + id + '">'
                  + '    <i class="bi bi-x-lg"></i>'
                  + '  </button>'
                  + '</td>';
                tbody.appendChild(tr);
                refreshEmptyRow();
            };

            tbody.addEventListener('click', (e) => {
                const btn = e.target.closest('[data-remove-member]');
                if (!btn) return;
                const tr = btn.closest('tr');
                if (tr) tr.remove();
                refreshEmptyRow();
            });

            const currentExcludeIds = () =>
                Array.from(tbody.querySelectorAll('[data-member-id]'))
                     .map(tr => tr.dataset.memberId)
                     .join(',');

            const lookup = (q) => {
                if (inflight) inflight.abort();
                const ac = new AbortController();
                inflight = ac;
                const url = '/manage/songbook-series?action=songbook_search'
                          + '&q=' + encodeURIComponent(q)
                          + '&exclude_ids=' + encodeURIComponent(currentExcludeIds())
                          + '&limit=20';
                fetch(url, { credentials: 'same-origin', signal: ac.signal })
                    .then(r => r.ok ? r.json() : { suggestions: [] })
                    .then(data => {
                        const list = Array.isArray(data.suggestions) ? data.suggestions : [];
                        labelToSuggestion = new Map();
                        datalist.innerHTML = list.map(s => {
                            const a   = (s.abbreviation || '').replace(/"/g, '&quot;');
                            const n   = (s.name         || '').replace(/"/g, '&quot;');
                            const lbl = a + (n ? ' — ' + n : '');
                            labelToSuggestion.set(lbl, s);
                            return '<option value="' + lbl + '"></option>';
                        }).join('');
                    })
                    .catch(err => { if (err.name !== 'AbortError') { /* silent */ } });
            };

            search.addEventListener('input', () => {
                const v = search.value.trim();
                if (v === '') { datalist.innerHTML = ''; return; }
                clearTimeout(debounce);
                debounce = setTimeout(() => lookup(v), 200);
            });
            search.addEventListener('focus', () => lookup(search.value.trim()));

            addBtn.addEventListener('click', () => {
                const v = search.value.trim();
                if (v === '') return;
                const s = labelToSuggestion.get(v);
                if (!s) {
                    /* User typed something that doesn't match a suggestion.
                       Cheaper UX: re-run the search and pick the first
                       result if there's a single match; otherwise alert. */
                    alert('Pick a songbook from the dropdown — typed value did not match a known songbook.');
                    return;
                }
                window.seriesAddMember(s.id, s.abbreviation, s.name);
                search.value = '';
                datalist.innerHTML = '';
                search.focus();
            });
        })();

        /* ============================================================
           Modal openers — populate from the row payload baked into the
           Edit / Delete buttons by PHP above.
           ============================================================ */
        function openSeriesEditModal(row) {
            document.getElementById('edit-id').value           = row.id;
            document.getElementById('edit-name').value         = row.name || '';
            document.getElementById('edit-slug').value         = row.slug || '';
            document.getElementById('edit-description').value  = row.description || '';
            document.getElementById('edit-title-label').textContent = row.name || '';
            const tbody = document.getElementById('edit-members-tbody');
            tbody.innerHTML = '';
            (row.members || []).forEach(m => {
                window.seriesAddMember(m.songbook_id, m.abbreviation, m.name, m.sort_order);
            });
            document.getElementById('edit-members-empty-row').style.display =
                (row.members || []).length === 0 ? '' : 'none';
            new bootstrap.Modal(document.getElementById('seriesEditModal')).show();
        }

        function openSeriesDeleteModal(row) {
            document.getElementById('delete-id').value             = row.id;
            document.getElementById('delete-name-label').textContent = row.name || '';
            new bootstrap.Modal(document.getElementById('seriesDeleteModal')).show();
        }

        /* Slug auto-fill on the Create form. Only writes into the slug
           field when it's empty — once the curator has typed a slug
           manually, we stop overwriting it on every keystroke in Name. */
        (function () {
            const nm = document.getElementById('create-name');
            const sl = document.getElementById('create-slug');
            if (!nm || !sl) return;
            const slugify = (s) => String(s).toLowerCase()
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/^-+|-+$/g, '');
            let userTouched = false;
            sl.addEventListener('input', () => { userTouched = true; });
            nm.addEventListener('input', () => {
                if (!userTouched) sl.value = slugify(nm.value);
            });
        })();
        </script>

        <!-- Sortable table headers (#644). -->
        <script type="module">
            import { bootSortableTables } from '/js/modules/admin-table-sort.js?v=<?= filemtime(dirname(__DIR__) . '/js/modules/admin-table-sort.js') ?>';
            bootSortableTables();
        </script>

        <?php endif; ?>

    </div>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>
</body>
</html>
