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
require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'slug-field.php';   /* #1870 — ihymns_slug_advanced_field() */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_mysql.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'song_soft_delete.php';   /* #1694 */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'activity_log.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'media_identifiers.php';   /* #1765 — mediaIdentifierPublicationClean(), the ONE validator */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'places.php';             /* #1765 — placeColumnExists() */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'ilyrics_id.php';   /* #1860 go-live — ilidStampNewRow() for the marcxml_import action below (create's own call now lives inside catalogueAdminCreate()) */
/* #1969 API-coverage batch 4b-i (A4) — the catalogue ("Collection") admin
   CRUD shared cores. The admin_catalogue_* API actions in api.php call
   these SAME functions this page's `add`/`update`/`delete`/`add_member`/
   `remove_member` POST handlers call — one validation/write core, two thin
   callers (rule #22/#35). `marcxml_import` stays page-only (out of scope —
   see includes/catalogue_admin.php's doc-block); it does NOT delegate here. */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'catalogue_admin.php';

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

/* Schema probe — delegates to the shared core (rule #22) so the API's
   admin_catalogue_* 503 gate reads the identical answer. */
$hasSchema = catalogueAdminTableExists($db);

/* #1765 Feature 3 — ArkId/OpenLibraryWorkId/OpenLibraryEditionId on
   tblCatalogues (migrate-publication-metadata.php Stage 3, three columns in
   one ALTER loop; probed as one unit — degrades to "fields not offered /
   not written" on a pre-migration install). Collections carry no ISBN/ISSN
   (those are a series/songbook concern), so the shared identifier partial is
   rendered with $pifShowIsbnIssn = false below. */
$hasPubIdCols = $hasSchema && catalogueAdminPubIdColumnsReady($db);

/* ---- GET ?action=song_search ----
 * JSON typeahead for the "Add a song" picker on the members panel
 * (#1866, epic #1863, rule #43 — wired to window.iHymnsPlaceSearch
 * below; this handler already existed as a scaffold but was never
 * actually attached to any input until now). Returns matching songs
 * from tblSongs ranked by title. Optional `exclude_ids` keeps
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

/* ---- GET ?action=marcxml_export&id=N (#1765 Feature 5) ------------------
 * Streams the Collection as a downloadable MARCXML file via the shared
 * helper. Admin-gated (the manage_songbooks entitlement above); read-only;
 * emitted before any HTML. Collections carry only ARK + OpenLibrary, and
 * name their title column 'Title' (the marcxml 'catalogue' field map knows). */
if ($hasSchema
    && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
    && ($_GET['action'] ?? '') === 'marcxml_export'
) {
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'marcxml_admin.php';
    $id = (int)($_GET['id'] ?? 0);
    $stmt = $db->prepare('SELECT * FROM tblCatalogues WHERE Id = ? LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Collection not found.';
        exit;
    }
    marcxmlAdmin_sendExport([
        'Title'                => $row['Title'] ?? '',
        'ArkId'                => $row['ArkId'] ?? '',
        'OpenLibraryWorkId'    => $row['OpenLibraryWorkId'] ?? '',
        'OpenLibraryEditionId' => $row['OpenLibraryEditionId'] ?? '',
    ], [], [], 'catalogue', (string)($row['Slug'] ?? $row['Title'] ?? 'collection'));
}

/* ---- POST handlers ---- */
if ($hasSchema && $_SERVER['REQUEST_METHOD'] === 'POST') {
    /* #1765 rule #29 — validateCsrfRequest() (same-origin: X-Requested-With +
       Origin/Referer host match, OR a still-valid session token) instead of
       validateCsrf() alone, which goes stale on long-lived multi-tab admin
       sessions. These forms are plain POSTs, so the session-token branch is
       what fires today — the upgrade avoids the flaky single-path check. */
    if (!validateCsrfRequest((string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo 'Invalid CSRF token';
        exit;
    }
    $action = (string)($_POST['action'] ?? '');

    try {
        switch ($action) {
            case 'add': {
                [$fields, $fieldError] = catalogueAdminValidateCreateFields($_POST);
                if ($fieldError !== null) { $error = $fieldError; break; }

                /* #1765 Feature 3 — publication identifiers, validated via the ONE
                   shared validator (mediaIdentifierPublicationClean); persisted in
                   a schema-tolerant secondary UPDATE after the INSERT below. */
                [$pubIds, $pubError] = catalogueAdminValidatePublicationIds($_POST);
                if ($pubError !== null) { $error = $pubError; break; }

                if (catalogueAdminSlugTaken($db, $fields['slug'])) {
                    $error = "A catalogue with slug '{$fields['slug']}' already exists."; break;
                }

                /* catalogueAdminCreate() pairs the INSERT with minting this
                   Collection's permanent IL-id (ILC…, #1860 go-live). */
                $newId = catalogueAdminCreate($db, $fields);

                if ($hasPubIdCols) {
                    catalogueAdminPersistPublicationIds($db, $newId, $pubIds);
                }

                logActivity('admin.catalogues.add', 'catalogue', (string)$newId, [
                    'slug' => $fields['slug'], 'title' => $fields['title'], 'visibility' => $fields['visibility'],
                    'publication_ids' => $hasPubIdCols ? array_filter([
                        'ark_id' => $pubIds['ark'], 'openlibrary_work_id' => $pubIds['olWork'],
                        'openlibrary_edition_id' => $pubIds['olEdition'],
                    ], fn($v) => $v !== null) : null,
                ]);
                $success = "Collection '{$fields['title']}' created.";
                break;
            }

            case 'marcxml_import': {
                /* #1765 Feature 5 — create a Collection from an uploaded
                   MARCXML file. Imported hidden (Visibility=admin_only) so a
                   curator reviews it before it goes public. A slightly-off
                   identifier is skipped, not fatal; the slug auto-suffixes. */
                require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'marcxml_admin.php';
                $parsed = marcxmlAdmin_parseUpload($_FILES['marcxml_file'] ?? [], 'catalogue');
                if (!$parsed['ok']) { $error = $parsed['error']; break; }
                $title = trim((string)($parsed['fields']['Title'] ?? ''));
                if ($title === '') { $error = 'The MARCXML record has no title (245 $a) to create a Collection from.'; break; }
                $title = mb_substr($title, 0, 255);
                [$ids, $skipped] = marcxmlAdmin_cleanPublicationIdentifiers($parsed['fields'], [
                    'ArkId' => 'ark', 'OpenLibraryWorkId' => 'openlibrary-work', 'OpenLibraryEditionId' => 'openlibrary-edition',
                ]);
                $idArk = $ids['ArkId']; $idOlW = $ids['OpenLibraryWorkId']; $idOlE = $ids['OpenLibraryEditionId'];

                $base = $slugFor($title); if ($base === '') { $base = 'collection'; }
                $base = mb_substr($base, 0, 250);
                $slug = $base; $suffix = 1;
                while (true) {
                    $chk = $db->prepare('SELECT Id FROM tblCatalogues WHERE Slug = ?');
                    $chk->bind_param('s', $slug);
                    $chk->execute();
                    $taken = $chk->get_result()->fetch_row() !== null;
                    $chk->close();
                    if (!$taken) { break; }
                    $suffix++; $slug = $base . '-' . $suffix;
                }

                $desc = null; $sortOrder = 0; $visibility = 'admin_only'; $colour = '';
                $ins = $db->prepare(
                    'INSERT INTO tblCatalogues (Slug, Title, Description, SortOrder, Visibility, Colour) VALUES (?, ?, ?, ?, ?, ?)'
                );
                $ins->bind_param('sssiss', $slug, $title, $desc, $sortOrder, $visibility, $colour);
                $ins->execute();
                $newId = (int)$db->insert_id;
                $ins->close();
                /* #1860 go-live — mint this Collection's permanent IL-id (ILC…). */
                ilidStampNewRow($db, 'catalogue', $newId);

                if ($hasPubIdCols) {
                    $upd = $db->prepare('UPDATE tblCatalogues SET ArkId = ?, OpenLibraryWorkId = ?, OpenLibraryEditionId = ? WHERE Id = ?');
                    $upd->bind_param('sssi', $idArk, $idOlW, $idOlE, $newId);
                    $upd->execute();
                    $upd->close();
                }

                logActivity('admin.catalogues.marcxml_import', 'catalogue', (string)$newId, [
                    'title' => $title, 'slug' => $slug,
                ]);
                $notes = [];
                if ($skipped) { $notes[] = 'skipped invalid identifier(s): ' . implode(', ', $skipped); }
                if (!empty($parsed['unmapped'])) { $notes[] = 'unmapped MARC tag(s): ' . implode(', ', array_slice($parsed['unmapped'], 0, 12)); }
                $success = "Collection '{$title}' created from MARCXML (slug '{$slug}') — hidden until you set its visibility."
                    . ($notes ? ' — ' . implode('; ', $notes) : '');
                break;
            }

            case 'update': {
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) { $error = 'Collection id missing.'; break; }

                [$fields, $fieldError] = catalogueAdminValidateUpdateFields($_POST);
                if ($fieldError !== null) { $error = $fieldError; break; }

                /* #1765 Feature 3 — publication identifiers, validated via the ONE
                   shared validator; written in a schema-tolerant secondary UPDATE. */
                [$pubIds, $pubError] = catalogueAdminValidatePublicationIds($_POST);
                if ($pubError !== null) { $error = $pubError; break; }

                catalogueAdminUpdate($db, $id, $fields);

                if ($hasPubIdCols) {
                    catalogueAdminPersistPublicationIds($db, $id, $pubIds);
                }

                logActivity('admin.catalogues.update', 'catalogue', (string)$id, [
                    'title' => $fields['title'], 'visibility' => $fields['visibility'],
                    'publication_ids' => $hasPubIdCols ? array_filter([
                        'ark_id' => $pubIds['ark'], 'openlibrary_work_id' => $pubIds['olWork'],
                        'openlibrary_edition_id' => $pubIds['olEdition'],
                    ], fn($v) => $v !== null) : null,
                ]);
                $success = "Collection updated.";
                break;
            }

            case 'delete': {
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) { $error = 'Collection id missing.'; break; }
                $title = catalogueAdminFetchTitle($db, $id);
                if ($title === null) { $error = 'Collection not found.'; break; }
                catalogueAdminDelete($db, $id);
                /* tblCatalogueSongs cascades via FK ON DELETE CASCADE. */
                logActivity('admin.catalogues.delete', 'catalogue', (string)$id, [
                    'title' => $title,
                ]);
                $success = "Collection '{$title}' deleted.";
                break;
            }

            case 'add_member': {
                $catalogueId = (int)($_POST['catalogue_id'] ?? 0);
                $songId      = trim((string)($_POST['song_id'] ?? ''));
                if ($catalogueId <= 0 || $songId === '') {
                    $error = 'catalogue_id and song_id required.'; break;
                }

                /* #1866 (epic #1863, rule #43) — SEARCH-SELECT ONLY: the
                   picker below (the members panel's "Add a song" box)
                   resolves to a SongId, but that id arrives over POST
                   like any other client-supplied value, so it is
                   untrusted until checked. Free-typing in place-search.js
                   clears the hidden id, so a mismatched/forged id here is
                   the exception rather than the rule — but this endpoint
                   must still verify it names a REAL tblSongs row before
                   writing it. There is deliberately NO find-or-create
                   fallback: songs are authored in the editor, never
                   minted from a catalogue form (unlike the Tune/Publisher
                   pickers in #1864, which fall back to a create funnel
                   when nothing matches). Without this check, a bad id
                   would fall through to tblCatalogueSongs' FK + INSERT
                   IGNORE below and silently affect 0 rows while still
                   reporting the misleading "already in the catalogue"
                   message — mirrors #1868's groups.php add_member fix.
                   #1694 — a soft-deleted song must not be addable via a
                   forged SongId either (catalogueAdminFindVisibleSongTitle()
                   uses the same songVisibleSql() filter the song_search
                   picker uses, so a hidden song is neither offered NOR
                   accepted). @disabled-visible: admin surface (#1765) —
                   disabled songbooks stay fully visible/editable in
                   /manage, so a curator can still add a song from a
                   disabled book into a Collection. */
                $songTitle = catalogueAdminFindVisibleSongTitle($db, $songId);
                if ($songTitle === null) { $error = 'That song could not be found — pick one from the search results.'; break; }

                $userId = (int)($currentUser['id'] ?? 0) ?: null;
                $added = catalogueAdminAddMember($db, $catalogueId, $songId, $userId);
                logActivity('admin.catalogues.add_member', 'catalogue', (string)$catalogueId, [
                    'song_id' => $songId, 'added' => $added,
                ]);
                $success = $added
                    ? "Added \"{$songTitle}\" ({$songId}) to catalogue."
                    : "{$songId} was already in the catalogue.";
                break;
            }

            case 'remove_member': {
                $catalogueId = (int)($_POST['catalogue_id'] ?? 0);
                $songId      = trim((string)($_POST['song_id'] ?? ''));
                if ($catalogueId <= 0 || $songId === '') {
                    $error = 'catalogue_id and song_id required.'; break;
                }
                $removed = catalogueAdminRemoveMember($db, $catalogueId, $songId);
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
        /* #1765 Feature 3 — hardcoded PHP constant columns (rule #5a),
           appended only when they exist so a pre-migration install runs
           exactly the old query. */
        $pubIdSelect = $hasPubIdCols
            ? ', c.ArkId, c.OpenLibraryWorkId, c.OpenLibraryEditionId'
            : '';
        $stmt = $db->prepare(
            'SELECT c.Id, c.Slug, c.Title, c.Description, c.SortOrder, c.Visibility,
                    c.Colour, c.CreatedAt, c.UpdatedAt' . $pubIdSelect . ',
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
        A <strong>Collection</strong> is a flexible way to group songs, separate from which songbook
        each song lives in. Use it for themed sets (Christmas, Easter), worship-leader picks (Modern,
        Traditional), denominational groupings, or Public-Domain-only lists — anywhere one song should
        appear in several groupings at once.
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
                    <label class="form-label small mb-0" for="create-cat-title">Title <span class="text-danger">*</span></label>
                    <input type="text" name="title" id="create-cat-title" class="form-control form-control-sm" required maxlength="255"
                           placeholder="e.g. Christmas / Advent">
                </div>
                <div class="col-md-3">
                    <?= ihymns_slug_advanced_field([
                        'value'       => '',
                        'maxlength'   => 255,
                        'placeholder' => 'christmas-advent',
                        'small'       => true,
                    ]) ?>
                </div>
                <div class="col-md-3">
                    <label class="form-label small mb-0" for="create-cat-description">Description</label>
                    <input type="text" name="description" id="create-cat-description" class="form-control form-control-sm" maxlength="500">
                </div>
                <div class="col-md-1">
                    <label class="form-label small mb-0" for="create-cat-sort">Sort</label>
                    <input type="number" name="sort_order" id="create-cat-sort" class="form-control form-control-sm" min="0" value="0">
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-0" for="create-cat-visibility">Visibility</label>
                    <select name="visibility" id="create-cat-visibility" class="form-select form-select-sm">
                        <option value="public">Public</option>
                        <option value="curated">Curated only</option>
                        <option value="admin_only">Admin only</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <!-- #1181 — optional catalogue badge colour; swatch writes its
                         hex into the text field (the submitted value). Blank = default. -->
                    <label class="form-label small mb-0" for="create-cat-colour">Colour <small class="text-muted">(optional)</small></label>
                    <div class="input-group input-group-sm">
                        <input type="color" class="form-control form-control-color" value="#888888"
                               title="Pick a colour" aria-label="Collection colour swatch"
                               oninput="this.nextElementSibling.value = this.value.toUpperCase()">
                        <input type="text" name="colour" id="create-cat-colour" class="form-control" maxlength="7"
                               pattern="#?[0-9A-Fa-f]{6}" placeholder="#RRGGBB — blank = default">
                    </div>
                </div>
                <?php if ($hasPubIdCols): ?>
                <div class="col-12">
                    <?php
                        /* #1765 Feature 3 — shared publication-identifier fieldset
                           (ARK + OpenLibrary; no ISBN/ISSN for Collections). The
                           SAME partial /manage/songbook-series uses (rule #22). */
                        $pifMode = 'create'; $pifShowIsbnIssn = false; $pifHasOpenLibraryCols = true;
                        require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'publication-identifiers-fields.php';
                        unset($pifMode, $pifShowIsbnIssn, $pifHasOpenLibraryCols);
                    ?>
                </div>
                <?php endif; ?>
                <div class="col-12">
                    <button type="submit" class="btn btn-sm btn-info">
                        <i class="bi bi-plus me-1"></i>Create catalogue
                    </button>
                </div>
            </form>
        </div>

        <!-- #1765 Feature 5 — create a Collection from an uploaded MARCXML file. -->
        <div class="card-admin p-3 mb-3">
            <h2 class="h6 mb-2"><i class="bi bi-upload me-1" aria-hidden="true"></i>Import from MARCXML</h2>
            <form method="POST" class="row g-2 align-items-end small" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action" value="marcxml_import">
                <div class="col-md-8">
                    <label class="form-label small mb-0" for="collection-marcxml-file">MARCXML file</label>
                    <input type="file" class="form-control form-control-sm" id="collection-marcxml-file"
                           name="marcxml_file" accept=".xml,application/xml,application/marcxml+xml" required>
                    <div class="form-text small">Reads the title (245 $a) + ARK / OpenLibrary identifiers; imported hidden for review.</div>
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-sm btn-info w-100">
                        <i class="bi bi-upload me-1" aria-hidden="true"></i>Import Collection
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
                    <table class="table table-sm table-dark mb-0 small align-middle cp-sortable admin-table-responsive">
                        <thead><tr>
                            <th data-sort-key="title" data-sort-type="text">Title</th>
                            <th data-sort-key="slug" data-sort-type="text">Slug</th>
                            <th data-sort-key="visibility" data-sort-type="text">Visibility</th>
                            <th class="text-end" data-sort-key="songs" data-sort-type="number">Songs</th>
                            <th data-sort-key="description" data-sort-type="text">Description</th>
                            <th class="text-end">Actions</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($catalogues as $c): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($c['Title']) ?></strong></td>
                                <td><code class="small"><?= htmlspecialchars($c['Slug']) ?></code></td>
                                <td data-sort-value="<?= htmlspecialchars($c['Visibility'], ENT_QUOTES) ?>">
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
                                            title="Edit"
                                            aria-label="Edit collection &quot;<?= htmlspecialchars($c['Title'], ENT_QUOTES) ?>&quot;">
                                        <i class="bi bi-pencil" aria-hidden="true"></i>
                                    </button>
                                    <!-- #1765 Feature 5 — export this Collection as a MARCXML file. -->
                                    <a class="btn btn-sm btn-outline-secondary"
                                       href="?action=marcxml_export&amp;id=<?= (int)$c['Id'] ?>"
                                       title="Export this Collection as MARCXML" download>
                                        <i class="bi bi-filetype-xml" aria-hidden="true"></i>
                                        <span class="visually-hidden">Export MARCXML</span>
                                    </a>
                                    <button type="button" class="btn btn-sm btn-outline-secondary"
                                            data-bs-toggle="collapse"
                                            data-bs-target="#cat-members-<?= (int)$c['Id'] ?>"
                                            title="Members"
                                            aria-label="Members of collection &quot;<?= htmlspecialchars($c['Title'], ENT_QUOTES) ?>&quot;">
                                        <i class="bi bi-music-note-list" aria-hidden="true"></i>
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
                                            <label class="form-label small mb-0" for="edit-cat-title-<?= (int)$c['Id'] ?>">Title</label>
                                            <input type="text" name="title" id="edit-cat-title-<?= (int)$c['Id'] ?>" class="form-control form-control-sm"
                                                   value="<?= htmlspecialchars($c['Title']) ?>" required maxlength="255">
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label small mb-0" for="edit-cat-description-<?= (int)$c['Id'] ?>">Description</label>
                                            <input type="text" name="description" id="edit-cat-description-<?= (int)$c['Id'] ?>" class="form-control form-control-sm"
                                                   value="<?= htmlspecialchars((string)($c['Description'] ?? '')) ?>" maxlength="500">
                                        </div>
                                        <div class="col-md-1">
                                            <label class="form-label small mb-0" for="edit-cat-sort-<?= (int)$c['Id'] ?>">Sort</label>
                                            <input type="number" name="sort_order" id="edit-cat-sort-<?= (int)$c['Id'] ?>" class="form-control form-control-sm"
                                                   min="0" value="<?= (int)$c['SortOrder'] ?>">
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label small mb-0" for="edit-cat-visibility-<?= (int)$c['Id'] ?>">Visibility</label>
                                            <select name="visibility" id="edit-cat-visibility-<?= (int)$c['Id'] ?>" class="form-select form-select-sm">
                                                <?php foreach (['public','curated','admin_only'] as $v): ?>
                                                    <option value="<?= $v ?>" <?= $c['Visibility'] === $v ? 'selected' : '' ?>><?= $v ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-3">
                                            <!-- #1181 — catalogue badge colour (blank = default). -->
                                            <label class="form-label small mb-0" for="edit-cat-colour-<?= (int)$c['Id'] ?>">Colour</label>
                                            <div class="input-group input-group-sm">
                                                <input type="color" class="form-control form-control-color"
                                                       value="<?= htmlspecialchars(($c['Colour'] ?? '') !== '' ? (string)$c['Colour'] : '#888888') ?>"
                                                       title="Pick a colour" aria-label="Collection colour swatch"
                                                       oninput="this.nextElementSibling.value = this.value.toUpperCase()">
                                                <input type="text" name="colour" id="edit-cat-colour-<?= (int)$c['Id'] ?>" class="form-control"
                                                       value="<?= htmlspecialchars((string)($c['Colour'] ?? '')) ?>"
                                                       maxlength="7" pattern="#?[0-9A-Fa-f]{6}" placeholder="#RRGGBB">
                                            </div>
                                        </div>
                                        <?php if ($hasPubIdCols): ?>
                                        <div class="col-12">
                                            <?php
                                                /* #1765 Feature 3 — shared partial, server-pre-filled
                                                   from this row's values ($pifValues). Same partial the
                                                   create form + series page use (rule #22). */
                                                $pifMode = 'edit'; $pifShowIsbnIssn = false; $pifHasOpenLibraryCols = true;
                                                /* #1765 review — this partial is rendered once PER Collection row
                                                   (all edit forms are in the DOM at load; Bootstrap .collapse only
                                                   hides via CSS), so give each row's fields a unique id suffix to
                                                   avoid duplicate element ids + broken <label for> association. */
                                                $pifIdSuffix = '-' . (int)$c['Id'];
                                                $pifValues = [
                                                    'ark_id'                 => $c['ArkId'] ?? '',
                                                    'openlibrary_work_id'    => $c['OpenLibraryWorkId'] ?? '',
                                                    'openlibrary_edition_id' => $c['OpenLibraryEditionId'] ?? '',
                                                ];
                                                require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'publication-identifiers-fields.php';
                                                unset($pifMode, $pifShowIsbnIssn, $pifHasOpenLibraryCols, $pifValues, $pifIdSuffix);
                                            ?>
                                        </div>
                                        <?php endif; ?>
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
                                    <form method="POST" class="row g-2 align-items-end small cat-add-song-form">
                                        <input type="hidden" name="csrf_token"   value="<?= htmlspecialchars($csrf) ?>">
                                        <input type="hidden" name="action"       value="add_member">
                                        <input type="hidden" name="catalogue_id" value="<?= (int)$c['Id'] ?>">
                                        <!-- #1866 (epic #1863, rule #43) — search-select ONLY: the
                                             visible box below is a live search over tblSongs (reusing
                                             the ?action=song_search handler above, wired via the
                                             shared window.iHymnsPlaceSearch typeahead — see the script
                                             block near the end of this page). Picking a candidate fills
                                             this hidden song_id with the REAL tblSongs.SongId, which
                                             add_member (above) re-verifies server-side before the
                                             insert — a free-typed name never submits (no create arm;
                                             songs are authored in the editor, never minted here). -->
                                        <input type="hidden" name="song_id" class="cat-add-song-id" value="">
                                        <div class="col-md-4">
                                            <label class="form-label small mb-0" for="cat-add-song-name-<?= (int)$c['Id'] ?>">Add a song</label>
                                            <input type="text" class="form-control form-control-sm cat-add-song-name"
                                                   id="cat-add-song-name-<?= (int)$c['Id'] ?>"
                                                   placeholder="Search by title or song id…" autocomplete="off" required
                                                   aria-label="Search for a song to add to this collection">
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

<!-- Sortable table headers (#1786 sweep). -->
<script type="module">
    import { bootSortableTables } from '/js/modules/admin-table-sort.js?v=<?= filemtime(dirname(__DIR__) . '/js/modules/admin-table-sort.js') ?>';
    bootSortableTables();
</script>

<?php if ($hasSchema && !empty($catalogues)): ?>
<!-- Live song search for the "Add a song" picker (#1866, epic #1863, rule #43). -->
<script src="/js/modules/place-search.js?v=<?= filemtime(dirname(__DIR__) . '/js/modules/place-search.js') ?>"></script>
<script>
    (function () {
        if (!window.iHymnsPlaceSearch) return;
        /* #1866 — one attach() per catalogue's "Add a song" form. Every
           catalogue row's members panel is in the DOM at load (Bootstrap
           .collapse only hides the inactive ones via CSS), so this loops
           over all of them rather than hardcoding one catalogue's ids.
           pickMode:'value': the pick fills the input + hidden id with NO
           network call of its own — the form's own POST is the ONE
           commit, and add_member (server-side, above) VERIFIES the id
           names a real tblSongs row before writing it. Reuses the
           EXISTING ?action=song_search handler on THIS page (rule #22)
           — no new search endpoint was written for this picker. */
        document.querySelectorAll('form.cat-add-song-form').forEach((form) => {
            const nameInput = form.querySelector('.cat-add-song-name');
            const hiddenId  = form.querySelector('.cat-add-song-id');
            if (!nameInput || !hiddenId) return;

            window.iHymnsPlaceSearch.attach(nameInput, {
                hiddenIdInput: hiddenId,
                minChars: 2,
                pickMode: 'value',
                noun: { singular: 'song', plural: 'songs' },
                searchUrl: (q) => '/manage/catalogues?action=song_search&q=' + encodeURIComponent(q) + '&limit=10',
                parseResults: (d) => (d.rows || []).map((r) => ({
                    id: r.id,
                    display_name: r.title,
                    hint: [r.songbook || '', r.number ? '#' + r.number : ''].filter(Boolean).join(' '),
                })),
            });

            /* Require an actual resolved pick before submit — search-select
               ONLY; there is no create-on-submit funnel for this field to
               fall back to (songs are authored in the editor, never minted
               from a catalogue form). This is a UX guard only — the
               server-side existence check in add_member above is the real
               guarantee. */
            form.addEventListener('submit', (ev) => {
                if (!hiddenId.value) {
                    ev.preventDefault();
                    nameInput.setCustomValidity('Pick a song from the search results first.');
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
