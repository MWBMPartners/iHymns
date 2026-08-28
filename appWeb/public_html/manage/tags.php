<?php

declare(strict_types=1);

/**
 * iHymns — Admin: Tags & Themes (#770)
 *
 * Catalogue-wide CRUD for tblSongTags + a merge flow for collapsing
 * duplicate-meaning tags into one canonical row. Mirrors the
 * /manage/languages scaffolding (PR #742) — list + filter + paginated
 * table + create / edit / delete / merge — plus the per-row usage
 * count that's specific to tags.
 *
 * Bug companion: the editor's tag-add path was firing a misleading
 * success toast even when the bulk_tag INSERT was no-op'd by the
 * tblSongTagMap → tblSongs FK on an unsaved song. That's fixed in
 * editor.js in the same PR; this page is the "where to merge a tag I
 * notice has a duplicate" surface that tag normalisation
 * (PR #763 / #762) feeds into.
 *
 * Auth: manage_tags entitlement (admin + global_admin).
 *
 * Database access: mysqli prepared statements (project policy);
 * mutating actions write a tblActivityLog row with EntityType='tag'.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'config.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_mysql.php';
/* #1969 API-coverage batch 4b-i (A3) — the tag admin CRUD shared cores.
   The admin_tag_* API actions in api.php call these SAME functions this
   page's POST handlers call — one validation/write core, two thin callers
   (rule #22/#35). Also pulls in song_similarity.php (ihymns_sim_normalise()/
   ihymns_sim_text(), #1216/#1222) transitively for the canonicalisation
   suggestions below — never re-forked. */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'tag_admin.php';

if (!isAuthenticated()) {
    header('Location: /manage/login');
    exit;
}
$currentUser = getCurrentUser();
if (!$currentUser || !userHasEntitlement('manage_tags', $currentUser['role'] ?? null)) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><body><h1>403 — manage_tags required</h1></body></html>';
    exit;
}
$activePage = 'tags';

$db   = getDbMysqli();
$csrf = csrfToken();

/* #1152 — the standard-vocabulary columns (Source / ParentId / CcliThemeId)
   land via migrate-seed-theme-vocabulary.php. Probe so this page degrades
   gracefully on a long-running install that hasn't applied it yet. */
$hasThemeCols = tagAdminThemeColumnsReady($db);

$logTag = static function (string $action, string $entityId, array $details, string $result = 'success'): void {
    if (function_exists('logActivity')) {
        try { logActivity('tag.' . $action, 'tag', $entityId, $details, $result); }
        catch (\Throwable $_e) { /* audit best-effort */ }
    }
};

/* ----------------------------------------------------------------------
 * POST dispatcher — JSON in / JSON out. CSRF-checked.
 * ---------------------------------------------------------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    header('Content-Type: application/json; charset=UTF-8');
    if (!validateCsrfRequest((string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['error' => 'CSRF check failed — please retry.']);
        exit;
    }
    $action = (string)($_POST['action'] ?? '');
    try {
        switch ($action) {
            case 'create': {
                [$fields, $fieldError] = tagAdminValidateFields(
                    (string)($_POST['name'] ?? ''),
                    (string)($_POST['description'] ?? '')
                );
                if ($fieldError !== null) {
                    http_response_code(400);
                    echo json_encode(['error' => $fieldError]);
                    exit;
                }
                $newId = tagAdminCreate($db, $fields['name'], $fields['slug'], $fields['description']);
                $logTag('create', (string)$newId, ['name' => $fields['name'], 'slug' => $fields['slug']]);
                echo json_encode(['success' => true, 'id' => $newId, 'name' => $fields['name']]);
                exit;
            }

            case 'update': {
                $id = (int)($_POST['id'] ?? 0);
                /* tagAdminNormaliseName() is called directly (rather than
                   tagAdminValidateFields()) so the combined "id and name are
                   required." message below stays byte-identical to before
                   this extraction — see includes/tag_admin.php's doc-block. */
                $name = tagAdminNormaliseName((string)($_POST['name'] ?? ''));
                $description = trim((string)($_POST['description'] ?? ''));
                if ($id <= 0 || $name === '') {
                    http_response_code(400);
                    echo json_encode(['error' => 'id and name are required.']);
                    exit;
                }
                $slug = tagAdminSlugify($name);
                if ($slug === '') {
                    http_response_code(400);
                    echo json_encode(['error' => 'Name has no usable slug characters.']);
                    exit;
                }
                /* Capture the before-state for the diff. */
                $before = tagAdminFetch($db, $id);
                if (!$before) {
                    http_response_code(404);
                    echo json_encode(['error' => 'Tag not found.']);
                    exit;
                }
                tagAdminUpdate($db, $id, $name, $slug, $description);

                $diff = [];
                if (($before['Name'] ?? '') !== $name) $diff['name'] = ['from' => $before['Name'], 'to' => $name];
                if (($before['Slug'] ?? '') !== $slug) $diff['slug'] = ['from' => $before['Slug'], 'to' => $slug];
                if (($before['Description'] ?? '') !== $description) $diff['description'] = ['from' => $before['Description'], 'to' => $description];
                if ($diff) {
                    $logTag('edit', (string)$id, ['diff' => $diff]);
                }
                echo json_encode(['success' => true, 'id' => $id, 'name' => $name, 'changed' => count($diff)]);
                exit;
            }

            case 'delete': {
                $id    = (int)($_POST['id'] ?? 0);
                $force = !empty($_POST['force']);
                if ($id <= 0) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Missing id.']);
                    exit;
                }
                /* Cite count via the mapping table. Refuse with 409
                   when non-zero; second click sends force=1. */
                $useCount = tagAdminUsageCount($db, $id);
                if (!$force && $useCount > 0) {
                    http_response_code(409);
                    echo json_encode([
                        'error' => 'Tag is in use.',
                        'songs' => $useCount,
                        'requires_force' => true,
                    ]);
                    exit;
                }

                $db->begin_transaction();
                try {
                    /* Unmap first (CASCADE FK should handle this but
                       being explicit makes the audit row's count
                       accurate). */
                    $result = tagAdminDelete($db, $id);
                    $db->commit();
                    $logTag('delete', (string)$id, ['forced' => $force ? 1 : 0, 'mappings' => $result['unmapped']]);
                    if ($result['deleted'] === 0) {
                        http_response_code(404);
                        echo json_encode(['error' => 'Tag not found.']);
                        exit;
                    }
                    echo json_encode(['success' => true]);
                } catch (\Throwable $e) {
                    $db->rollback();
                    throw $e;
                }
                exit;
            }

            case 'merge': {
                $sourceId = (int)($_POST['source_id'] ?? 0);
                $targetId = (int)($_POST['target_id'] ?? 0);
                if ($sourceId <= 0 || $targetId <= 0 || $sourceId === $targetId) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Source and target must be different tag ids.']);
                    exit;
                }
                /* Fetch both for the audit row. */
                $byId = tagAdminFetchNamesByIds($db, [$sourceId, $targetId]);
                if (count($byId) !== 2) {
                    http_response_code(404);
                    echo json_encode(['error' => 'One or both tags not found.']);
                    exit;
                }

                $db->begin_transaction();
                try {
                    /* Conflict resolution: any (SongId, sourceId) row
                       whose SongId is already mapped to targetId would
                       collide on the unique key when we UPDATE the
                       sourceId to targetId. Pre-flight DELETE those
                       conflicts first so the UPDATE becomes the no-op
                       it deserves to be — the union is preserved
                       (the SongId already has targetId, that's what
                       merge means). */
                    $result = tagAdminMerge($db, $sourceId, $targetId);

                    $db->commit();
                    $logTag('merge', (string)$targetId, [
                        'source_id'   => $sourceId,
                        'source_name' => $byId[$sourceId] ?? '',
                        'target_name' => $byId[$targetId] ?? '',
                        'repointed'   => $result['repointed'],
                        'conflicts'   => $result['conflicts'],
                    ]);
                    echo json_encode([
                        'success'    => true,
                        'repointed'  => $result['repointed'],
                        'conflicts'  => $result['conflicts'],
                        'target_id'  => $targetId,
                    ]);
                } catch (\Throwable $e) {
                    $db->rollback();
                    throw $e;
                }
                exit;
            }

            default:
                http_response_code(400);
                echo json_encode(['error' => 'Unknown action: ' . $action]);
                exit;
        }
    } catch (\Throwable $e) {
        error_log('[manage tags] action=' . $action . ' failed: ' . $e->getMessage());
        if (function_exists('logActivityError')) {
            logActivityError('tag.action_failed', 'tag', '', $e, ['action' => $action]);
        }
        http_response_code(500);
        echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
        exit;
    }
}

/* ----------------------------------------------------------------------
 * Read-side: paginate the catalogue with usage counts.
 * ---------------------------------------------------------------------- */
$pageSize = 50;
$pageNum  = max(1, (int)($_GET['p']  ?? 1));
$qFilter  = trim((string)($_GET['q'] ?? ''));

$where  = '';
$types  = '';
$params = [];
if ($qFilter !== '') {
    $where = ' WHERE (t.Name LIKE ? OR t.Slug LIKE ?)';
    $like  = '%' . $qFilter . '%';
    $types = 'ss';
    $params = [$like, $like];
}

$stmt = $db->prepare('SELECT COUNT(*) FROM tblSongTags t' . $where);
if ($types) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$totalRows = (int)$stmt->get_result()->fetch_row()[0];
$stmt->close();
$totalPages = max(1, (int)ceil($totalRows / $pageSize));
$pageNum    = min($pageNum, $totalPages);
$offset     = ($pageNum - 1) * $pageSize;

/* Source + ParentId are functionally dependent on t.Id (PK) so they're safe
   under ONLY_FULL_GROUP_BY; only select them once the migration has run. */
$themeCols = $hasThemeCols ? ', t.Source AS Source, t.ParentId AS ParentId' : '';
$listSql = 'SELECT t.Id, t.Name, t.Slug, t.Description, COUNT(m.TagId) AS UseCount' . $themeCols . '
            FROM tblSongTags t
            LEFT JOIN tblSongTagMap m ON m.TagId = t.Id'
         . $where
         . ' GROUP BY t.Id
             ORDER BY UseCount DESC, t.Name ASC
             LIMIT ? OFFSET ?';
$stmt = $db->prepare($listSql);
$bindTypes  = $types . 'ii';
$bindParams = array_merge($params, [$pageSize, $offset]);
$stmt->bind_param($bindTypes, ...$bindParams);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* Also fetch every tag id+name (no pagination) for the merge target
   dropdown — admins need to be able to merge into a row that's not
   on the current page. With ~thousands of rows max this is small. */
$res = $db->query('SELECT Id, Name FROM tblSongTags ORDER BY Name ASC');
$allTagsForMerge = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
if ($res) $res->close();
/* Id → Name map so the list can show a child theme's parent without a
   GROUP BY-unfriendly self-join (#1152). */
$tagNameById = [];
foreach ($allTagsForMerge as $t) { $tagNameById[(int)$t['Id']] = (string)$t['Name']; }

/* ----------------------------------------------------------------------
 * Canonicalisation suggestions (#1222) — fold curator-added tags whose
 * spelling closely matches a seeded standard theme into that theme.
 * Exact case/spacing/diacritic variants were already promoted at seed
 * time (the upsert is collation-insensitive on Name); this catches the
 * FUZZY remainder (typos: "Resurrection"/"Resurection", "Thankfulness"/
 * "Thanksgiving"-ish). Curator-confirmed via the existing Merge action —
 * never auto-applied. Skipped entirely until the migration has run.
 * ---------------------------------------------------------------------- */
/* #1969 API-coverage batch 4b-i (A3) — delegates to the shared
   tagAdminCanonicalSuggestions() core (includes/tag_admin.php), which the
   new admin_tag_canonical_suggestions API action also calls (rule #22).
   Returns [] on an un-migrated install (its own tagAdminThemeColumnsReady()
   gate) so this stays a no-op wrapper, not a behaviour change. */
$canonSuggestions = tagAdminCanonicalSuggestions($db);

require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-favicon.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tags &amp; Themes — iHymns Admin</title>
    <?php /* #955 — shared head bundle: runs admin-theme-init.php (the
       synchronous theme resolver) BEFORE any CSS so this page honours the
       user's Light/Dark/High-contrast/CVD/System preference with no FOUC,
       and loads Bootstrap/Icons with SRI + cache-busted app.css/admin.css.
       Replaces the previous inline, SRI-less, resolver-less CSS block that
       left this page stuck in the default theme regardless of preference. */ ?>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-libs.php'; ?>
</head>
<body>
<?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-nav.php'; ?>

<main class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h1 class="h3 mb-1">
                <i class="bi bi-tags me-2"></i>Tags &amp; Themes
                <?= entitlementLockChipHtml('manage_tags') ?>
            </h1>
            <p class="text-secondary small mb-0">
                The words and themes you can tag songs with. These power the Browse by Theme section on
                the home page and the theme listing pages readers can open. Tags badged <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle">standard</span>
                come from a ready-made, standard list of song themes — the editor suggests them as you type,
                so everyone uses the same spelling instead of re-typing variants. Use
                <strong>Merge</strong> to fold a duplicate tag into the one you want to keep.
            </p>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-outline-warning" data-bs-toggle="modal" data-bs-target="#mergeModal">
                <i class="bi bi-shuffle me-1"></i>Merge tags
            </button>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#tagModal" data-mode="create">
                <i class="bi bi-plus-lg me-1"></i>Add tag
            </button>
        </div>
    </div>

    <form method="get" class="row g-2 mb-3">
        <div class="col-md-6">
            <input type="search" class="form-control form-control-sm" name="q"
                   value="<?= htmlspecialchars($qFilter, ENT_QUOTES, 'UTF-8') ?>"
                   placeholder="Search by name or slug">
        </div>
        <div class="col-md-2"><button class="btn btn-secondary btn-sm w-100"><i class="bi bi-funnel me-1"></i>Filter</button></div>
    </form>

    <p class="small text-secondary mb-2">
        <strong><?= count($rows) ?></strong> of <strong><?= number_format($totalRows) ?></strong> tag(s). Page <?= $pageNum ?>/<?= $totalPages ?>.
    </p>

    <?php if (!empty($canonSuggestions)): ?>
    <div class="card mb-3 border-info">
        <div class="card-body py-2">
            <h2 class="h6 mb-1"><i class="bi bi-magic me-1"></i>Canonicalisation suggestions (<?= count($canonSuggestions) ?>)</h2>
            <p class="small text-secondary mb-2">
                Curator tags whose spelling closely matches a
                <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle">standard</span>
                theme. <strong>Fold</strong> re-points every song to the standard theme and removes the
                variant (the existing Merge — irreversible). Review each before folding.
            </p>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0 cp-sortable admin-table-responsive">
                    <thead>
                        <tr>
                            <th data-sort-key="variant" data-sort-type="text">Variant tag</th>
                            <th class="text-end" data-sort-key="uses" data-sort-type="number">Songs</th><th></th>
                            <th data-sort-key="standard" data-sort-type="text">Standard theme</th>
                            <th data-sort-key="match" data-sort-type="number">Match</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($canonSuggestions as $s): ?>
                        <tr>
                            <td data-sort-value="<?= htmlspecialchars($s['curName'], ENT_QUOTES, 'UTF-8') ?>"><strong><?= htmlspecialchars($s['curName'], ENT_QUOTES, 'UTF-8') ?></strong></td>
                            <td class="text-end"><?= number_format($s['uses']) ?></td>
                            <td class="text-secondary">→</td>
                            <td data-sort-value="<?= htmlspecialchars($s['stdName'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($s['stdName'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td data-sort-value="<?= (int)round($s['score'] * 100) ?>"><span class="badge bg-secondary"><?= (int)round($s['score'] * 100) ?>%</span></td>
                            <td class="text-end">
                                <button class="btn btn-sm btn-outline-warning tag-canon-btn"
                                        data-source="<?= (int)$s['curId'] ?>"
                                        data-target="<?= (int)$s['stdId'] ?>"
                                        data-source-name="<?= htmlspecialchars($s['curName'], ENT_QUOTES, 'UTF-8') ?>"
                                        data-target-name="<?= htmlspecialchars($s['stdName'], ENT_QUOTES, 'UTF-8') ?>">
                                    <i class="bi bi-arrow-down-up me-1"></i>Fold into standard
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="table-responsive">
        <table class="table table-dark table-striped table-hover align-middle cp-sortable admin-table-responsive">
            <thead>
                <tr>
                    <th data-sort-key="name" data-sort-type="text">Name</th>
                    <th data-sort-key="slug" data-sort-type="text">Slug</th>
                    <th data-sort-key="description" data-sort-type="text">Description</th>
                    <th class="text-end" data-sort-key="songs" data-sort-type="number">Songs</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="5" class="text-center text-secondary py-4">No tags match.</td></tr>
            <?php else: foreach ($rows as $r): ?>
                <tr data-tag-id="<?= (int)$r['Id'] ?>">
                    <td>
                        <strong><?= htmlspecialchars($r['Name'], ENT_QUOTES, 'UTF-8') ?></strong>
                        <?php if (($r['Source'] ?? '') === 'ccli-openlyrics'): ?>
                            <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle ms-1"
                                  title="Standard CCLI / OpenLyrics theme (#1152)">standard</span>
                        <?php endif; ?>
                        <?php $pid = (int)($r['ParentId'] ?? 0); if ($pid > 0 && isset($tagNameById[$pid])): ?>
                            <div class="small text-muted"><i class="bi bi-diagram-2 me-1"></i>under <?= htmlspecialchars($tagNameById[$pid], ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>
                    </td>
                    <td><code><?= htmlspecialchars($r['Slug'], ENT_QUOTES, 'UTF-8') ?></code></td>
                    <td class="small text-secondary">
                        <?= $r['Description'] !== ''
                            ? htmlspecialchars($r['Description'], ENT_QUOTES, 'UTF-8')
                            : '<span class="text-muted">—</span>' ?>
                    </td>
                    <td class="text-end"><?= number_format((int)$r['UseCount']) ?></td>
                    <td class="text-end">
                        <button class="btn btn-sm btn-outline-info tag-edit-btn"
                                data-bs-toggle="modal" data-bs-target="#tagModal" data-mode="edit"
                                data-id="<?= (int)$r['Id'] ?>"
                                data-name="<?= htmlspecialchars($r['Name'], ENT_QUOTES, 'UTF-8') ?>"
                                data-description="<?= htmlspecialchars($r['Description'], ENT_QUOTES, 'UTF-8') ?>">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <button class="btn btn-sm btn-outline-danger tag-delete-btn"
                                data-id="<?= (int)$r['Id'] ?>"
                                data-name="<?= htmlspecialchars($r['Name'], ENT_QUOTES, 'UTF-8') ?>"
                                data-uses="<?= (int)$r['UseCount'] ?>">
                            <i class="bi bi-trash"></i>
                        </button>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
        <nav aria-label="Pagination">
            <ul class="pagination pagination-sm justify-content-center">
                <?php
                    $qp = $_GET; $linkFor = static fn(int $p) => '?' . http_build_query(array_merge($qp, ['p' => $p]));
                    $w0 = max(1, $pageNum - 2); $w1 = min($totalPages, $pageNum + 2);
                ?>
                <li class="page-item <?= $pageNum === 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= $linkFor(1) ?>">«</a></li>
                <li class="page-item <?= $pageNum === 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= $linkFor(max(1, $pageNum - 1)) ?>">‹</a></li>
                <?php for ($p = $w0; $p <= $w1; $p++): ?>
                    <li class="page-item <?= $p === $pageNum ? 'active' : '' ?>"><a class="page-link" href="<?= $linkFor($p) ?>"><?= $p ?></a></li>
                <?php endfor; ?>
                <li class="page-item <?= $pageNum === $totalPages ? 'disabled' : '' ?>"><a class="page-link" href="<?= $linkFor(min($totalPages, $pageNum + 1)) ?>">›</a></li>
                <li class="page-item <?= $pageNum === $totalPages ? 'disabled' : '' ?>"><a class="page-link" href="<?= $linkFor($totalPages) ?>">»</a></li>
            </ul>
        </nav>
    <?php endif; ?>
</main>

<!-- Add / Edit modal -->
<div class="modal fade" id="tagModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
        <form id="tagForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" id="tm-action" value="create">
            <input type="hidden" name="id" id="tm-id" value="">
            <div class="modal-header">
                <h5 class="modal-title" id="tagModalTitle">Add tag</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label" for="tm-name">Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="tm-name" name="name" maxlength="50" required>
                    <div class="form-text small">Title-Cased on save (\"worship\" → \"Worship\"); duplicates collapse via the unique index.</div>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="tm-description">Description</label>
                    <textarea class="form-control" id="tm-description" name="description" maxlength="255" rows="2"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Save</button>
            </div>
        </form>
    </div></div>
</div>

<!-- Merge modal -->
<div class="modal fade" id="mergeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
        <form id="mergeForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="merge">
            <div class="modal-header">
                <h5 class="modal-title">Merge tags</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="small text-secondary">
                    Every song mapped to <strong>Source</strong> will be remapped to <strong>Target</strong>;
                    songs already carrying both end up with one mapping (no duplicates). The Source tag is then deleted.
                    The merge is transactional — if anything fails, nothing changes.
                </p>
                <div class="mb-3">
                    <label class="form-label" for="mg-source">Source tag (gets merged + deleted)</label>
                    <select class="form-select" id="mg-source" name="source_id" required>
                        <option value="">— pick a source —</option>
                        <?php foreach ($allTagsForMerge as $t): ?>
                            <option value="<?= (int)$t['Id'] ?>"><?= htmlspecialchars($t['Name'], ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="mg-target">Target tag (kept)</label>
                    <select class="form-select" id="mg-target" name="target_id" required>
                        <option value="">— pick a target —</option>
                        <?php foreach ($allTagsForMerge as $t): ?>
                            <option value="<?= (int)$t['Id'] ?>"><?= htmlspecialchars($t['Name'], ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="alert alert-warning small mb-0">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    Merging is irreversible. Make sure the Source genuinely duplicates the Target.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-warning">Merge</button>
            </div>
        </form>
    </div></div>
</div>

<!-- Sortable table headers (#1786 sweep — tagged cp-sortable but never
     booted; every header click was a silent no-op until now). -->
<script type="module">
    import { bootSortableTables } from '/js/modules/admin-table-sort.js?v=<?= filemtime(dirname(__DIR__) . '/js/modules/admin-table-sort.js') ?>';
    bootSortableTables();
</script>

<?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>

<script>
(function () {
    'use strict';
    const CSRF = <?= json_encode($csrf, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const URL_ = window.location.pathname;

    /* Add / Edit modal */
    const tagModalEl = document.getElementById('tagModal');
    const tmTitle = document.getElementById('tagModalTitle');
    const tmAction = document.getElementById('tm-action');
    const tmId = document.getElementById('tm-id');
    const tmName = document.getElementById('tm-name');
    const tmDesc = document.getElementById('tm-description');
    tagModalEl.addEventListener('show.bs.modal', (e) => {
        const trigger = e.relatedTarget;
        const mode = trigger?.dataset?.mode || 'create';
        if (mode === 'edit') {
            tmTitle.textContent = 'Edit tag';
            tmAction.value = 'update';
            tmId.value = trigger.dataset.id || '';
            tmName.value = trigger.dataset.name || '';
            tmDesc.value = trigger.dataset.description || '';
        } else {
            tmTitle.textContent = 'Add tag';
            tmAction.value = 'create';
            tmId.value = '';
            tmName.value = '';
            tmDesc.value = '';
        }
    });
    document.getElementById('tagForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const body = new URLSearchParams(new FormData(e.target));
        const r = await fetch(URL_, { method: 'POST', body, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const d = await r.json().catch(() => ({}));
        if (!r.ok) { alert(d?.error || 'Save failed.'); return; }
        window.location.reload();
    });

    /* Delete with two-step force */
    document.querySelectorAll('.tag-delete-btn').forEach((btn) => {
        btn.addEventListener('click', async () => {
            const uses = parseInt(btn.dataset.uses || '0', 10);
            const name = btn.dataset.name || '';
            const id = btn.dataset.id;
            if (uses > 0) {
                if (!confirm('"' + name + '" is used by ' + uses + ' song(s). Delete anyway? Mappings will be removed.')) return;
            } else {
                if (!confirm('Delete "' + name + '"?')) return;
            }
            const body = new URLSearchParams({
                csrf_token: CSRF, action: 'delete', id, force: uses > 0 ? '1' : '',
            });
            const r = await fetch(URL_, { method: 'POST', body, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            const d = await r.json().catch(() => ({}));
            if (!r.ok) { alert(d?.error || 'Delete failed.'); return; }
            window.location.reload();
        });
    });

    /* Merge */
    document.getElementById('mergeForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const fd = new FormData(e.target);
        if (fd.get('source_id') === fd.get('target_id')) {
            alert('Source and target must be different tags.');
            return;
        }
        if (!confirm('Merge "' + e.target.querySelector('#mg-source option:checked').textContent
            + '" into "' + e.target.querySelector('#mg-target option:checked').textContent
            + '"? This is irreversible.')) return;
        const body = new URLSearchParams(fd);
        const r = await fetch(URL_, { method: 'POST', body, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const d = await r.json().catch(() => ({}));
        if (!r.ok) { alert(d?.error || 'Merge failed.'); return; }
        alert('Merged. Repointed ' + d.repointed + ' mapping(s); ' + d.conflicts + ' duplicate(s) collapsed.');
        window.location.reload();
    });

    /* Canonicalisation (#1222): fold a variant tag into its standard theme.
       Reuses the merge action — source = variant (deleted), target = standard. */
    document.querySelectorAll('.tag-canon-btn').forEach((btn) => {
        btn.addEventListener('click', async () => {
            if (!confirm('Fold "' + (btn.dataset.sourceName || '') + '" into the standard theme "'
                + (btn.dataset.targetName || '') + '"? Every song is re-pointed to the standard theme and '
                + 'the variant is deleted. This is irreversible.')) return;
            btn.disabled = true;
            const body = new URLSearchParams({
                csrf_token: CSRF, action: 'merge',
                source_id: btn.dataset.source, target_id: btn.dataset.target,
            });
            const r = await fetch(URL_, { method: 'POST', body, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            const d = await r.json().catch(() => ({}));
            if (!r.ok || !d.success) { btn.disabled = false; alert(d?.error || 'Fold failed.'); return; }
            const row = btn.closest('tr'); if (row) row.remove();
        });
    });
})();
</script>
</body>
</html>
