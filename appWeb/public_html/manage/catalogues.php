<?php

declare(strict_types=1);

/**
 * iHymns — Admin: Catalogues (#941)
 *
 * CRUD surface for tblCatalogues + tblCatalogueSongs.
 *
 * A Catalogue is a free-form many-to-many grouping of songs that
 * doesn't fit the Songbook / Songbook Series hierarchy — themed
 * collections, worship-leader curations, denominational groupings,
 * Public-Domain-only views, etc. One song can belong to many
 * catalogues; catalogues compose with songbooks rather than replace
 * them.
 *
 * Gated by manage_songbooks (same coarse entitlement as Songbook
 * Series — catalogues are a flavour of catalogue-level metadata, not
 * a separate domain). Future: a dedicated manage_catalogues
 * entitlement when curatorial scopes need finer slicing.
 *
 * Pre-migration safe: schema-probes tblCatalogues on every page
 * load and surfaces a friendly "run the migration" page if missing.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_mysql.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'song_soft_delete.php';   /* #1694 */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'activity_log.php';

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
$activePage = 'catalogues';

$error   = '';
$success = '';
$db      = getDbMysqli();
$csrf    = csrfToken();

$slugFor = static function (string $name): string {
    $ascii = (string)preg_replace('/[^A-Za-z0-9]+/u', '-', $name);
    return trim(strtolower($ascii), '-');
};

/* Schema probe. */
$hasSchema = false;
try {
    $probe = $db->prepare(
        "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblCatalogues' LIMIT 1"
    );
    $probe->execute();
    $hasSchema = $probe->get_result()->fetch_row() !== null;
    $probe->close();
} catch (\Throwable $e) {
    error_log('[catalogues] schema probe failed: ' . $e->getMessage());
}

/* ---- GET ?action=song_search ----
 * JSON typeahead used by the manage-members panel. Returns matching
 * songs from tblSongs ranked by title. Optional `exclude_ids` keeps
 * already-added members out of the suggestion list. */
if ($hasSchema
    && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
    && ($_GET['action'] ?? '') === 'song_search'
) {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    $q     = trim((string)($_GET['q'] ?? ''));
    $limit = max(1, min(50, (int)($_GET['limit'] ?? 20)));
    $excl  = array_values(array_filter(array_map('strval',
        explode(',', (string)($_GET['exclude_ids'] ?? '')))));
    try {
        $like = '%' . $q . '%';
        if ($q === '') {
            echo json_encode(['rows' => []]);
            exit;
        }
        /* Excluded-IDs filter happens in PHP rather than dynamic SQL.
           The exclusion list is tiny (current catalogue's existing
           members), so the simple shape avoids ref-bind awkwardness
           with mysqli's variadic bind_param. */
        $sql = "SELECT s.SongId AS id, s.Title AS title, s.Number AS number,
                       s.SongbookAbbr AS songbook, sb.Name AS songbookName
                  FROM tblSongs s
                  LEFT JOIN tblSongbooks sb ON sb.Abbreviation = s.SongbookAbbr
                 WHERE (s.Title LIKE ? OR s.SongId LIKE ?)
                   AND " . songVisibleSql($db, 's') . "
                 ORDER BY s.Title ASC
                 LIMIT ?";   /* #1694 — hidden songs are not offered for membership.
                                @disabled-visible: admin surface (#1765) — disabled
                                songbooks stay fully visible/editable in /manage
                                (owner decision); a curator can still add a song from
                                a disabled book into a Collection. */
        $stmt = $db->prepare($sql);
        $stmt->bind_param('ssi', $like, $like, $limit);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        if (!empty($excl)) {
            $exclSet = array_flip($excl);
            $rows = array_values(array_filter($rows,
                static fn($r) => !isset($exclSet[$r['id']])));
        }
        echo json_encode(['rows' => $rows]);
    } catch (\Throwable $e) {
        error_log('[catalogues] song_search failed: ' . $e->getMessage());
        echo json_encode(['rows' => [], 'error' => 'search failed']);
    }
    exit;
}

/* ---- POST handlers ---- */
if ($hasSchema && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrf((string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo 'Invalid CSRF token';
        exit;
    }
    $action = (string)($_POST['action'] ?? '');

    try {
        switch ($action) {
            case 'add': {
                $title       = trim((string)($_POST['title']       ?? ''));
                $slugRaw     = trim((string)($_POST['slug']        ?? ''));
                $description = trim((string)($_POST['description'] ?? '')) ?: null;
                $visibility  = trim((string)($_POST['visibility']  ?? 'public'));
                $sortOrder   = (int)($_POST['sort_order'] ?? 0);

                if ($title === '')                                       { $error = 'Title is required.'; break; }
                if (mb_strlen($title) > 255)                             { $error = 'Title must be 255 characters or fewer.'; break; }
                if (!in_array($visibility, ['public','curated','admin_only'], true)) {
                    $error = 'Invalid visibility.'; break;
                }
                $slug = $slugRaw !== '' ? $slugFor($slugRaw) : $slugFor($title);
                if ($slug === '')                                        { $error = 'Slug could not be derived — provide one explicitly.'; break; }
                if (mb_strlen($slug) > 255)                              { $error = 'Slug must be 255 characters or fewer.'; break; }
                /* #1181 — optional badge colour (blank = theme default; validated hex). */
                $colour = strtoupper(trim((string)($_POST['colour'] ?? '')));
                if ($colour !== '' && !preg_match('/^#[0-9A-F]{6}$/', $colour)) {
                    $error = 'Colour must be a #RRGGBB hex value or left blank.'; break;
                }

                $stmt = $db->prepare('SELECT Id FROM tblCatalogues WHERE Slug = ?');
                $stmt->bind_param('s', $slug);
                $stmt->execute();
                $exists = $stmt->get_result()->fetch_row() !== null;
                $stmt->close();
                if ($exists) { $error = "A catalogue with slug '{$slug}' already exists."; break; }

                $stmt = $db->prepare(
                    'INSERT INTO tblCatalogues (Slug, Title, Description, SortOrder, Visibility, Colour)
                     VALUES (?, ?, ?, ?, ?, ?)'
                );
                $stmt->bind_param('sssiss',
                    $slug, $title, $description, $sortOrder, $visibility, $colour);
                $stmt->execute();
                $newId = (int)$db->insert_id;
                $stmt->close();

                logActivity('admin.catalogues.add', 'catalogue', (string)$newId, [
                    'slug' => $slug, 'title' => $title, 'visibility' => $visibility,
                ]);
                $success = "Collection '{$title}' created.";
                break;
            }

            case 'update': {
                $id          = (int)($_POST['id']          ?? 0);
                $title       = trim((string)($_POST['title']       ?? ''));
                $description = trim((string)($_POST['description'] ?? '')) ?: null;
                $visibility  = trim((string)($_POST['visibility']  ?? 'public'));
                $sortOrder   = (int)($_POST['sort_order'] ?? 0);
                if ($id <= 0)                                            { $error = 'Collection id missing.'; break; }
                if ($title === '')                                       { $error = 'Title is required.'; break; }
                if (!in_array($visibility, ['public','curated','admin_only'], true)) {
                    $error = 'Invalid visibility.'; break;
                }

                /* #1181 — optional badge colour (blank = theme default; validated hex). */
                $colour = strtoupper(trim((string)($_POST['colour'] ?? '')));
                if ($colour !== '' && !preg_match('/^#[0-9A-F]{6}$/', $colour)) {
                    $error = 'Colour must be a #RRGGBB hex value or left blank.'; break;
                }

                $stmt = $db->prepare(
                    'UPDATE tblCatalogues
                        SET Title = ?, Description = ?, SortOrder = ?, Visibility = ?, Colour = ?
                      WHERE Id = ?'
                );
                $stmt->bind_param('ssissi', $title, $description, $sortOrder, $visibility, $colour, $id);
                $stmt->execute();
                $stmt->close();

                logActivity('admin.catalogues.update', 'catalogue', (string)$id, [
                    'title' => $title, 'visibility' => $visibility,
                ]);
                $success = "Collection updated.";
                break;
            }

            case 'delete': {
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) { $error = 'Collection id missing.'; break; }
                $stmt = $db->prepare('SELECT Title FROM tblCatalogues WHERE Id = ?');
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$row) { $error = 'Collection not found.'; break; }
                $stmt = $db->prepare('DELETE FROM tblCatalogues WHERE Id = ?');
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $stmt->close();
                /* tblCatalogueSongs cascades via FK ON DELETE CASCADE. */
                logActivity('admin.catalogues.delete', 'catalogue', (string)$id, [
                    'title' => $row['Title'],
                ]);
                $success = "Collection '{$row['Title']}' deleted.";
                break;
            }

            case 'add_member': {
                $catalogueId = (int)($_POST['catalogue_id'] ?? 0);
                $songId      = trim((string)($_POST['song_id'] ?? ''));
                if ($catalogueId <= 0 || $songId === '') {
                    $error = 'catalogue_id and song_id required.'; break;
                }
                $userId = (int)($currentUser['id'] ?? 0) ?: null;
                $stmt = $db->prepare(
                    'INSERT IGNORE INTO tblCatalogueSongs
                        (CatalogueId, SongId, AddedBy, AddedAt)
                     VALUES (?, ?, ?, NOW())'
                );
                $stmt->bind_param('isi', $catalogueId, $songId, $userId);
                $stmt->execute();
                $added = $stmt->affected_rows > 0;
                $stmt->close();
                logActivity('admin.catalogues.add_member', 'catalogue', (string)$catalogueId, [
                    'song_id' => $songId, 'added' => $added,
                ]);
                $success = $added
                    ? "Added {$songId} to catalogue."
                    : "{$songId} was already in the catalogue.";
                break;
            }

            case 'remove_member': {
                $catalogueId = (int)($_POST['catalogue_id'] ?? 0);
                $songId      = trim((string)($_POST['song_id'] ?? ''));
                if ($catalogueId <= 0 || $songId === '') {
                    $error = 'catalogue_id and song_id required.'; break;
                }
                $stmt = $db->prepare(
                    'DELETE FROM tblCatalogueSongs WHERE CatalogueId = ? AND SongId = ?'
                );
                $stmt->bind_param('is', $catalogueId, $songId);
                $stmt->execute();
                $removed = $stmt->affected_rows > 0;
                $stmt->close();
                logActivity('admin.catalogues.remove_member', 'catalogue', (string)$catalogueId, [
                    'song_id' => $songId, 'removed' => $removed,
                ]);
                $success = $removed
                    ? "Removed {$songId} from catalogue."
                    : "{$songId} wasn't in the catalogue.";
                break;
            }

            default:
                $error = 'Unknown action: ' . $action;
        }
    } catch (\Throwable $e) {
        error_log('[catalogues] action=' . $action . ' failed: ' . $e->getMessage());
        $error = 'Database error: ' . $e->getMessage();
    }
}

/* ---- Load catalogue list + member counts ---- */
$catalogues = [];
if ($hasSchema) {
    try {
        $stmt = $db->prepare(
            'SELECT c.Id, c.Slug, c.Title, c.Description, c.SortOrder, c.Visibility,
                    c.Colour, c.CreatedAt, c.UpdatedAt,
                    (SELECT COUNT(*) FROM tblCatalogueSongs cs WHERE cs.CatalogueId = c.Id) AS SongCount
               FROM tblCatalogues c
              ORDER BY c.SortOrder ASC, c.Title ASC'
        );
        $stmt->execute();
        $catalogues = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } catch (\Throwable $e) {
        error_log('[catalogues] list failed: ' . $e->getMessage());
        $error = 'Could not load catalogues: ' . $e->getMessage();
    }
}

/* ---- Per-catalogue members (for the expand panels) ---- */
$membersByCatalogueId = [];
if ($hasSchema && !empty($catalogues)) {
    try {
        $stmt = $db->prepare(
            'SELECT cs.CatalogueId, cs.SongId, cs.SortOrder,
                    s.Title AS SongTitle, s.Number AS SongNumber,
                    s.SongbookAbbr, sb.Name AS SongbookName
               FROM tblCatalogueSongs cs
               JOIN tblSongs s ON s.SongId = cs.SongId
               LEFT JOIN tblSongbooks sb ON sb.Abbreviation = s.SongbookAbbr
              WHERE ' . songVisibleSql($db, 's') . '
              ORDER BY cs.CatalogueId ASC, cs.SortOrder ASC, s.Title ASC'
        );   /* #1694 — membership survives; the panel hides hidden members */
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
            $membersByCatalogueId[(int)$r['CatalogueId']][] = $r;
        }
        $stmt->close();
    } catch (\Throwable $e) {
        error_log('[catalogues] members load failed: ' . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Collections — iHymns Admin</title>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-libs.php'; ?>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-favicon.php'; ?>
</head>
<body>
<?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-nav.php'; ?>

<main class="container py-3">

    <h1 class="h4 mb-3">
        <i class="bi bi-collection me-1" aria-hidden="true"></i>Collections
    </h1>
    <p class="small text-muted mb-3">
        A <strong>Collection</strong> is a free-form many-to-many grouping of songs that sits alongside
        the Songbook hierarchy. Use it for thematic collections (Christmas, Easter), worship-leader
        curations (Modern, Traditional), denominational groupings, Public-Domain-only views — anything
        where one song should appear in many groupings without duplicating data.
    </p>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger small"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if (!empty($success)): ?>
        <div class="alert alert-success small"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <?php if (!$hasSchema): ?>
        <div class="alert alert-warning small">
            <i class="bi bi-database-gear me-1" aria-hidden="true"></i>
            The <code>tblCatalogues</code> table hasn't been created yet. Run the
            <a href="/manage/setup-database">Collections migration</a> to enable this page.
        </div>
    <?php else: ?>

        <!-- Add catalogue form -->
        <div class="card-admin p-3 mb-3">
            <h2 class="h6 mb-2"><i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Add a collection</h2>
            <form method="POST" class="row g-2 align-items-end small">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action" value="add">
                <div class="col-md-3">
                    <label class="form-label small mb-0">Title <span class="text-danger">*</span></label>
                    <input type="text" name="title" class="form-control form-control-sm" required maxlength="255"
                           placeholder="e.g. Christmas / Advent">
                </div>
                <div class="col-md-3">
                    <label class="form-label small mb-0">Slug <small class="text-muted">(auto if blank)</small></label>
                    <input type="text" name="slug" class="form-control form-control-sm" maxlength="255"
                           placeholder="christmas-advent">
                </div>
                <div class="col-md-3">
                    <label class="form-label small mb-0">Description</label>
                    <input type="text" name="description" class="form-control form-control-sm" maxlength="500">
                </div>
                <div class="col-md-1">
                    <label class="form-label small mb-0">Sort</label>
                    <input type="number" name="sort_order" class="form-control form-control-sm" min="0" value="0">
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-0">Visibility</label>
                    <select name="visibility" class="form-select form-select-sm">
                        <option value="public">Public</option>
                        <option value="curated">Curated only</option>
                        <option value="admin_only">Admin only</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <!-- #1181 — optional catalogue badge colour; swatch writes its
                         hex into the text field (the submitted value). Blank = default. -->
                    <label class="form-label small mb-0">Colour <small class="text-muted">(optional)</small></label>
                    <div class="input-group input-group-sm">
                        <input type="color" class="form-control form-control-color" value="#888888"
                               title="Pick a colour" aria-label="Collection colour swatch"
                               oninput="this.nextElementSibling.value = this.value.toUpperCase()">
                        <input type="text" name="colour" class="form-control" maxlength="7"
                               pattern="#?[0-9A-Fa-f]{6}" placeholder="#RRGGBB — blank = default">
                    </div>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-sm btn-info">
                        <i class="bi bi-plus me-1"></i>Create catalogue
                    </button>
                </div>
            </form>
        </div>

        <!-- Existing catalogues -->
        <?php if (empty($catalogues)): ?>
            <div class="text-muted small">No collections yet — use the form above to create the first one.</div>
        <?php else: ?>
            <div class="card-admin p-0 mb-3">
                <div class="table-responsive">
                    <table class="table table-sm table-dark mb-0 small align-middle">
                        <thead><tr>
                            <th>Title</th><th>Slug</th><th>Visibility</th>
                            <th class="text-end">Songs</th><th>Description</th>
                            <th class="text-end">Actions</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($catalogues as $c): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($c['Title']) ?></strong></td>
                                <td><code class="small"><?= htmlspecialchars($c['Slug']) ?></code></td>
                                <td>
                                    <span class="badge bg-body-secondary text-body-emphasis">
                                        <?= htmlspecialchars($c['Visibility']) ?>
                                    </span>
                                </td>
                                <td class="text-end"><?= (int)$c['SongCount'] ?></td>
                                <td class="text-muted small"><?= htmlspecialchars((string)($c['Description'] ?? '')) ?></td>
                                <td class="text-end">
                                    <button type="button" class="btn btn-sm btn-outline-info"
                                            data-bs-toggle="collapse"
                                            data-bs-target="#cat-edit-<?= (int)$c['Id'] ?>"
                                            title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary"
                                            data-bs-toggle="collapse"
                                            data-bs-target="#cat-members-<?= (int)$c['Id'] ?>"
                                            title="Members">
                                        <i class="bi bi-music-note-list"></i>
                                    </button>
                                    <form method="POST" class="d-inline"
                                          onsubmit="return confirm('Delete collection \'<?= htmlspecialchars($c['Title'], ENT_QUOTES) ?>\'? This unlinks every member song; the songs themselves are NOT deleted.');">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id"     value="<?= (int)$c['Id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"
                                                aria-label="Delete collection &quot;<?= htmlspecialchars($c['Title'], ENT_QUOTES) ?>&quot;">
                                            <i class="bi bi-trash" aria-hidden="true"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <!-- Edit row -->
                            <tr class="collapse" id="cat-edit-<?= (int)$c['Id'] ?>">
                                <td colspan="6" class="bg-body-secondary">
                                    <form method="POST" class="row g-2 align-items-end small">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                        <input type="hidden" name="action" value="update">
                                        <input type="hidden" name="id"     value="<?= (int)$c['Id'] ?>">
                                        <div class="col-md-3">
                                            <label class="form-label small mb-0">Title</label>
                                            <input type="text" name="title" class="form-control form-control-sm"
                                                   value="<?= htmlspecialchars($c['Title']) ?>" required maxlength="255">
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label small mb-0">Description</label>
                                            <input type="text" name="description" class="form-control form-control-sm"
                                                   value="<?= htmlspecialchars((string)($c['Description'] ?? '')) ?>" maxlength="500">
                                        </div>
                                        <div class="col-md-1">
                                            <label class="form-label small mb-0">Sort</label>
                                            <input type="number" name="sort_order" class="form-control form-control-sm"
                                                   min="0" value="<?= (int)$c['SortOrder'] ?>">
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label small mb-0">Visibility</label>
                                            <select name="visibility" class="form-select form-select-sm">
                                                <?php foreach (['public','curated','admin_only'] as $v): ?>
                                                    <option value="<?= $v ?>" <?= $c['Visibility'] === $v ? 'selected' : '' ?>><?= $v ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-3">
                                            <!-- #1181 — catalogue badge colour (blank = default). -->
                                            <label class="form-label small mb-0">Colour</label>
                                            <div class="input-group input-group-sm">
                                                <input type="color" class="form-control form-control-color"
                                                       value="<?= htmlspecialchars(($c['Colour'] ?? '') !== '' ? (string)$c['Colour'] : '#888888') ?>"
                                                       title="Pick a colour" aria-label="Collection colour swatch"
                                                       oninput="this.nextElementSibling.value = this.value.toUpperCase()">
                                                <input type="text" name="colour" class="form-control"
                                                       value="<?= htmlspecialchars((string)($c['Colour'] ?? '')) ?>"
                                                       maxlength="7" pattern="#?[0-9A-Fa-f]{6}" placeholder="#RRGGBB">
                                            </div>
                                        </div>
                                        <div class="col-md-3 text-end">
                                            <button type="submit" class="btn btn-sm btn-info">
                                                <i class="bi bi-check2 me-1"></i>Save changes
                                            </button>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                            <!-- Members row -->
                            <?php $members = $membersByCatalogueId[(int)$c['Id']] ?? []; ?>
                            <tr class="collapse" id="cat-members-<?= (int)$c['Id'] ?>">
                                <td colspan="6" class="bg-body-secondary">
                                    <h3 class="h6 mb-2"><i class="bi bi-music-note-list me-1"></i>Members of "<?= htmlspecialchars($c['Title']) ?>"</h3>
                                    <?php if (empty($members)): ?>
                                        <p class="text-muted small mb-2">No songs in this collection yet — use the form below to add some.</p>
                                    <?php else: ?>
                                        <ul class="list-group list-group-flush small mb-2">
                                            <?php foreach ($members as $m): ?>
                                                <li class="list-group-item bg-transparent d-flex align-items-center gap-2">
                                                    <span class="badge bg-body-secondary text-body-emphasis"><?= htmlspecialchars($m['SongbookAbbr']) ?></span>
                                                    <span class="fw-semibold flex-grow-1">
                                                        <a href="/song/<?= htmlspecialchars($m['SongId']) ?>" target="_blank" rel="noopener">
                                                            <?= htmlspecialchars($m['SongTitle']) ?>
                                                        </a>
                                                        <small class="text-muted ms-2">#<?= (int)$m['SongNumber'] ?></small>
                                                    </span>
                                                    <form method="POST" class="d-inline"
                                                          onsubmit="return confirm('Remove this song from the collection?');">
                                                        <input type="hidden" name="csrf_token"   value="<?= htmlspecialchars($csrf) ?>">
                                                        <input type="hidden" name="action"       value="remove_member">
                                                        <input type="hidden" name="catalogue_id" value="<?= (int)$c['Id'] ?>">
                                                        <input type="hidden" name="song_id"      value="<?= htmlspecialchars($m['SongId']) ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2" title="Remove"
                                                                aria-label="Remove <?= htmlspecialchars($m['SongId'], ENT_QUOTES) ?> from this collection">
                                                            <i class="bi bi-x" aria-hidden="true"></i>
                                                        </button>
                                                    </form>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                    <form method="POST" class="row g-2 align-items-end small">
                                        <input type="hidden" name="csrf_token"   value="<?= htmlspecialchars($csrf) ?>">
                                        <input type="hidden" name="action"       value="add_member">
                                        <input type="hidden" name="catalogue_id" value="<?= (int)$c['Id'] ?>">
                                        <div class="col-md-4">
                                            <label class="form-label small mb-0">Add a song (paste a song id, e.g. <code>CP-0001</code>)</label>
                                            <input type="text" name="song_id" class="form-control form-control-sm"
                                                   placeholder="CP-0001" pattern="[A-Za-z]+-\d+" required>
                                        </div>
                                        <div class="col-md-2">
                                            <button type="submit" class="btn btn-sm btn-info">
                                                <i class="bi bi-plus me-1"></i>Add
                                            </button>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

    <?php endif; /* hasSchema */ ?>

</main>

<?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>
</body>
</html>
