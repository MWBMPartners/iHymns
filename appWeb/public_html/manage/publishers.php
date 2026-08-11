<?php

declare(strict_types=1);

/**
 * iHymns — Admin: Publishers (#93 / epic #1765)
 *
 * CRUD surface for the tblPublishers registry — the songbook PUBLISHER as a
 * first-class entity (persons + companies), with imprint / catalogue grouping
 * (self-FK ParentId), an optional link to a tblMusicians person, a place-FK
 * city, and its aliases (tblPublisherAliases). Models manage/tunes.php
 * end-to-end (page shape, schema-tolerance idiom, validateCsrfRequest() CSRF,
 * merge conventions, inline-script-after-DOMContentLoaded — this is a full
 * admin page with its own <head>, NOT an api.php SPA fragment, so rule #30
 * does not apply here).
 *
 * All create/update/merge/delete/alias logic lives in
 * includes/publisher_admin.php — this page's POST handlers are THIN WRAPPERS
 * over those shared cores; the future admin_publisher_* API actions call the
 * SAME functions (rule #22/#35 — one core, nothing to drift).
 *
 * Gated by manage_publishers; pre-migration safe — probes tblPublishers on
 * every load and renders a CTA to /manage/setup-database when missing. Every
 * optional child table (tblPublisherAliases / tblMusicians / tblPlaces) is
 * probed independently via publisherAdminProbeGates() (rule #9).
 *
 * @link appWeb/public_html/manage/tunes.php               page-shape model
 * @link appWeb/public_html/includes/publisher_admin.php   shared cores this page delegates to
 * @link appWeb/public_html/includes/publisher_helpers.php IHYMNS_PUBLISHER_KINDS, slug + find-or-create
 * @see #93
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_mysql.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'publisher_helpers.php';
/* The shared validate/persist/merge/delete cores. Both this page's POST
   handlers AND the future admin_publisher_* API actions call these SAME
   functions (rule #22/#35). */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'publisher_admin.php';

/* ---- Gate FIRST, before any output (rule #1587) — the page's own gate MUST
   equal the entitlement admin-links.php advertises for it. ---- */
if (!isAuthenticated()) {
    header('Location: /manage/login');
    exit;
}
$currentUser = getCurrentUser();
if (!$currentUser || !userHasEntitlement('manage_publishers', $currentUser['role'] ?? null)) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><body><h1>403 — manage_publishers required</h1></body></html>';
    exit;
}
$activePage = 'publishers';

$error   = '';
$success = '';
$db      = getDbMysqli();
$csrf    = csrfToken();

/* ---- Probes ---- */
$hasSchema = publisherTableExists($db);
$gates = $hasSchema
    ? publisherAdminProbeGates($db)
    : ['hasPublishers' => false, 'hasSongbookPublishers' => false, 'hasAliases' => false, 'hasLinks' => false, 'hasMusicians' => false, 'hasPlaces' => false, 'hasSongbookPublisherCol' => false];

/* ---- GET ?action=musician_search — typeahead for the person-link picker.
   Only used when tblMusicians exists. ---- */
if ($hasSchema
    && $gates['hasMusicians']
    && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
    && ($_GET['action'] ?? '') === 'musician_search'
) {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    $q     = trim((string)($_GET['q'] ?? ''));
    $limit = max(1, min(50, (int)($_GET['limit'] ?? 20)));
    try {
        if ($q === '') {
            $stmt = $db->prepare('SELECT Id, Name, Slug FROM tblMusicians ORDER BY Name ASC LIMIT ?');
            $stmt->bind_param('i', $limit);
        } else {
            $like = '%' . $q . '%';
            $stmt = $db->prepare('SELECT Id, Name, Slug FROM tblMusicians WHERE Name LIKE ? ORDER BY Name ASC LIMIT ?');
            $stmt->bind_param('si', $like, $limit);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        $out = [];
        while ($row = $res->fetch_assoc()) {
            $out[] = ['id' => (int)$row['Id'], 'name' => (string)$row['Name'], 'slug' => (string)$row['Slug']];
        }
        $stmt->close();
        echo json_encode(['suggestions' => $out], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        error_log('[publishers musician_search] ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Search failed.']);
    }
    exit;
}

/* ---- GET ?action=publisher_search — typeahead for the PARENT picker (and,
   in commit 3, the songbook editor's multi-publisher picker). Returns active
   publishers; excludes the row being edited (?exclude=) so a publisher can't
   pick itself as parent. ---- */
if ($hasSchema
    && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
    && ($_GET['action'] ?? '') === 'publisher_search'
) {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    $q       = trim((string)($_GET['q'] ?? ''));
    $limit   = max(1, min(50, (int)($_GET['limit'] ?? 20)));
    $exclude = (int)($_GET['exclude'] ?? 0);
    try {
        $sql = 'SELECT Id, Name, Slug, Kind FROM tblPublishers WHERE IsActive = 1';
        $params = [];
        $types  = '';
        if ($q !== '') { $sql .= ' AND Name LIKE ?'; $params[] = '%' . $q . '%'; $types .= 's'; }
        if ($exclude > 0) { $sql .= ' AND Id <> ?'; $params[] = $exclude; $types .= 'i'; }
        $sql .= ' ORDER BY Name ASC LIMIT ?'; $params[] = $limit; $types .= 'i';
        $stmt = $db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        $out = [];
        while ($row = $res->fetch_assoc()) {
            $out[] = ['id' => (int)$row['Id'], 'name' => (string)$row['Name'], 'slug' => (string)$row['Slug'], 'kind' => (string)$row['Kind']];
        }
        $stmt->close();
        echo json_encode(['suggestions' => $out], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        error_log('[publishers publisher_search] ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Search failed.']);
    }
    exit;
}

/* ---- POST actions — all gated by validateCsrfRequest(). ---- */
if ($hasSchema && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!validateCsrfRequest((string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo 'Invalid CSRF token';
        exit;
    }
    $action = (string)($_POST['action'] ?? '');

    try {
        switch ($action) {
            case 'create': {
                [$fields, $fieldError] = publisherAdminValidateFields($_POST);
                if ($fieldError !== null) { $error = $fieldError; break; }
                $uniqError = publisherAdminCheckUniqueness($db, $fields, null);
                if ($uniqError !== null) { $error = $uniqError; break; }

                $db->begin_transaction();
                try {
                    $newId = publisherAdminCreate($db, $fields, $gates);
                    $db->commit();
                } catch (\Throwable $tx) {
                    $db->rollback();
                    throw $tx;
                }

                if (function_exists('logActivity')) {
                    logActivity('publisher.create', 'publisher', (string)$newId, [
                        'name' => $fields['name'],
                        'kind' => $fields['kind'],
                    ]);
                }
                $success = "Publisher '{$fields['name']}' created.";
                break;
            }

            case 'update': {
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) { $error = 'Publisher id is required.'; break; }

                [$fields, $fieldError] = publisherAdminValidateFields($_POST);
                if ($fieldError !== null) { $error = $fieldError; break; }
                $uniqError = publisherAdminCheckUniqueness($db, $fields, $id);
                if ($uniqError !== null) { $error = $uniqError; break; }

                $stmt = $db->prepare('SELECT Name FROM tblPublishers WHERE Id = ?');
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $before = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$before) { $error = 'Publisher not found.'; break; }
                $oldName = (string)$before['Name'];

                $aliasNames = $_POST['alias_names'] ?? [];
                if (!is_array($aliasNames)) $aliasNames = [];

                $db->begin_transaction();
                try {
                    publisherAdminPersistFields($db, $id, $fields, $gates);
                    /* Denorm cascade: keep the free-text tblSongbooks.Publisher
                       mirror on this publisher's linked books in step with a
                       rename (the tune TuneName-cascade equivalent). */
                    publisherAdminRenameCascade($db, $id, $oldName, $fields['name'], $gates);
                    if ($gates['hasAliases']) {
                        publisherAdminReplaceAliases($db, $id, $aliasNames, $fields['name']);
                    }
                    $db->commit();
                } catch (\Throwable $tx) {
                    $db->rollback();
                    throw $tx;
                }

                if (function_exists('logActivity')) {
                    logActivity('publisher.edit', 'publisher', (string)$id, [
                        'name'        => $fields['name'],
                        'renamed'     => $oldName !== $fields['name'],
                        'alias_count' => count($aliasNames),
                    ]);
                }
                $success = "Publisher '{$fields['name']}' updated.";
                break;
            }

            case 'delete': {
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) { $error = 'Publisher id is required.'; break; }

                $usage = publisherAdminUsageCounts($db, $id, $gates);
                $name  = publisherAdminDelete($db, $id);
                if ($name === null) { $error = 'Publisher not found.'; break; }

                if (function_exists('logActivity')) {
                    logActivity('publisher.delete', 'publisher', (string)$id, [
                        'name'  => $name,
                        'usage' => $usage,
                    ]);
                }
                $success = "Publisher '{$name}' deleted.";
                break;
            }

            case 'merge': {
                $sourceId = (int)($_POST['source_id'] ?? 0);
                $targetId = (int)($_POST['target_id'] ?? 0);
                if ($sourceId <= 0 || $targetId <= 0 || $sourceId === $targetId) {
                    $error = 'Source and target must be different publishers.';
                    break;
                }

                $db->begin_transaction();
                try {
                    $result = publisherAdminMerge($db, $sourceId, $targetId, $gates);
                    $db->commit();
                } catch (\Throwable $tx) {
                    $db->rollback();
                    throw $tx;
                }

                if (function_exists('logActivity')) {
                    logActivity('publisher.merge', 'publisher', (string)$targetId, [
                        'source'             => $result['source'],
                        'target'             => $result['target'],
                        'books_repointed'    => $result['booksRepointed'],
                        'children_repointed' => $result['childrenRepointed'],
                        'aliases_moved'      => $result['aliasesMoved'],
                        'links_moved'        => $result['linksMoved'],
                    ]);
                }
                $success = "Merged '{$result['source']['name']}' → '{$result['target']['name']}' "
                         . "({$result['booksRepointed']} songbook link(s) repointed).";
                break;
            }
        }
    } catch (\Throwable $e) {
        error_log('[publishers POST] ' . $e->getMessage());
        $error = 'Could not save changes: ' . $e->getMessage();
    }
}

/* ---- Read + list ---- */
$rows              = [];
$publisherAliasMap = [];

if ($hasSchema) {
    try {
        $songbookCountSelect = $gates['hasSongbookPublishers']
            ? '(SELECT COUNT(*) FROM tblSongbookPublishers WHERE PublisherId = p.Id) AS SongbookCount'
            : '0 AS SongbookCount';
        $aliasCountSelect = $gates['hasAliases']
            ? '(SELECT COUNT(*) FROM tblPublisherAliases WHERE PublisherId = p.Id) AS AliasCount'
            : '0 AS AliasCount';
        $musicianJoin = $gates['hasMusicians'] ? 'LEFT JOIN tblMusicians m ON m.Id = p.MusicianId' : '';
        $musicianSelect = $gates['hasMusicians'] ? 'm.Name AS MusicianName,' : 'NULL AS MusicianName,';

        $res = $db->query(
            "SELECT p.Id, p.Name, p.Slug, p.Kind, p.Subtitle, p.Disambiguation,
                    p.Ipi, p.Isni, p.CityName, p.CityId, p.MusicianId, p.ParentId,
                    p.Notes, p.IsActive,
                    parent.Name AS ParentName,
                    {$musicianSelect}
                    {$songbookCountSelect}, {$aliasCountSelect}
               FROM tblPublishers p
               LEFT JOIN tblPublishers parent ON parent.Id = p.ParentId
               {$musicianJoin}
              ORDER BY p.Name ASC"
        );
        while ($row = $res->fetch_assoc()) {
            $row['Id']            = (int)$row['Id'];
            $row['IsActive']      = (int)$row['IsActive'];
            $row['SongbookCount'] = (int)$row['SongbookCount'];
            $row['AliasCount']    = (int)$row['AliasCount'];
            $rows[] = $row;
        }
        $res->close();

        if ($rows && $gates['hasAliases']) {
            $res = $db->query('SELECT PublisherId, Name FROM tblPublisherAliases ORDER BY PublisherId, Name ASC');
            while ($row = $res->fetch_assoc()) {
                $publisherAliasMap[(int)$row['PublisherId']][] = (string)$row['Name'];
            }
            $res->close();
        }
    } catch (\Throwable $e) {
        error_log('[publishers read] ' . $e->getMessage());
    }
}

/* ----- Page render ----- */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrf) ?>">
    <title>Publishers — iHymns Admin</title>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-libs.php'; ?>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-favicon.php'; ?>
</head>
<body>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-nav.php'; ?>

    <div class="container-admin py-4">
        <h1 class="h4 mb-3"><i class="bi bi-building me-2"></i>Publishers</h1>
        <p class="text-secondary small mb-4">
            A <strong>Publisher</strong> is the person or company that published a songbook. Keep
            publishers here — with imprints grouped under their parent company, an optional link to a
            person in Musicians, and alternate names — so a songbook can name one or more publishers
            exactly instead of a single typed-in name.
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
                    The <code>tblPublishers</code> table hasn't been created on this database yet (#93).
                </p>
                <a href="/manage/setup-database" class="btn btn-amber btn-sm">
                    <i class="bi bi-database-gear me-1"></i>Run /manage/setup-database
                </a>
            </div>
        <?php else: ?>

        <!-- Publishers list -->
        <div class="card-admin p-3 mb-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h2 class="h6 mb-0"><i class="bi bi-list-ul me-2"></i>Existing publishers</h2>
                <button type="button" class="btn btn-outline-warning btn-sm" data-bs-toggle="modal" data-bs-target="#publisherMergeModal">
                    <i class="bi bi-shuffle me-1"></i>Merge publishers
                </button>
            </div>
            <table class="table table-sm align-middle cp-sortable mb-0 admin-table-responsive">
                <thead>
                    <tr class="text-muted small">
                        <th data-col-priority="primary"   data-sort-key="name"      data-sort-type="text">Name</th>
                        <th data-col-priority="secondary" data-sort-key="kind"      data-sort-type="text">Kind</th>
                        <th data-col-priority="tertiary"  data-sort-key="parent"    data-sort-type="text">Parent</th>
                        <th data-col-priority="tertiary"  data-sort-key="city"      data-sort-type="text">City</th>
                        <th data-col-priority="primary"   data-sort-key="songbooks" data-sort-type="number" class="text-center">Songbooks</th>
                        <th data-col-priority="tertiary"  data-sort-key="aliases"   data-sort-type="number" class="text-center">Aliases</th>
                        <th data-col-priority="secondary" data-sort-key="active"    data-sort-type="text" class="text-center">Active</th>
                        <th data-col-priority="primary"   class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                        <?php
                            $pid = (int)$r['Id'];
                            $rowPayload = [
                                'id'             => $pid,
                                'name'           => (string)$r['Name'],
                                'slug'           => (string)$r['Slug'],
                                'kind'           => (string)$r['Kind'],
                                'subtitle'       => (string)($r['Subtitle'] ?? ''),
                                'disambiguation' => (string)($r['Disambiguation'] ?? ''),
                                'ipi'            => (string)($r['Ipi'] ?? ''),
                                'isni'           => (string)($r['Isni'] ?? ''),
                                'city_name'      => (string)($r['CityName'] ?? ''),
                                'city_id'        => $r['CityId'] !== null ? (int)$r['CityId'] : '',
                                'musician_id'    => $r['MusicianId'] !== null ? (int)$r['MusicianId'] : '',
                                'musician_name'  => (string)($r['MusicianName'] ?? ''),
                                'parent_id'      => $r['ParentId'] !== null ? (int)$r['ParentId'] : '',
                                'parent_name'    => (string)($r['ParentName'] ?? ''),
                                'notes'          => (string)($r['Notes'] ?? ''),
                                'is_active'      => (int)$r['IsActive'],
                                'aliases'        => $publisherAliasMap[$pid] ?? [],
                            ];
                            $rowJson = json_encode($rowPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                            $deleteJson = json_encode([
                                'id' => $pid, 'name' => (string)$r['Name'],
                                'songbookCount' => (int)$r['SongbookCount'],
                            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        ?>
                        <tr<?= $r['IsActive'] ? '' : ' class="opacity-50"' ?>>
                            <td data-col-priority="primary">
                                <a href="/publisher/<?= htmlspecialchars((string)$r['Slug']) ?>"
                                   target="_blank" rel="noopener noreferrer" class="text-decoration-none">
                                    <?= htmlspecialchars((string)$r['Name']) ?>
                                </a>
                                <?php if (!empty($r['Disambiguation'])): ?>
                                    <span class="text-muted small">(<?= htmlspecialchars((string)$r['Disambiguation']) ?>)</span>
                                <?php endif; ?>
                            </td>
                            <td data-col-priority="secondary">
                                <span class="badge bg-secondary-subtle text-secondary-emphasis"><?= htmlspecialchars(IHYMNS_PUBLISHER_KINDS[$r['Kind']] ?? (string)$r['Kind']) ?></span>
                            </td>
                            <td data-col-priority="tertiary">
                                <?= !empty($r['ParentName']) ? htmlspecialchars((string)$r['ParentName']) : '<span class="text-muted small">—</span>' ?>
                            </td>
                            <td data-col-priority="tertiary">
                                <?= !empty($r['CityName']) ? htmlspecialchars((string)$r['CityName']) : '<span class="text-muted small">—</span>' ?>
                            </td>
                            <td data-col-priority="primary" class="text-center"><?= (int)$r['SongbookCount'] ?></td>
                            <td data-col-priority="tertiary" class="text-center"><?= (int)$r['AliasCount'] ?></td>
                            <td data-col-priority="secondary" class="text-center">
                                <?php if ($r['IsActive']): ?>
                                    <i class="bi bi-check-circle-fill text-success" title="Active"></i>
                                <?php else: ?>
                                    <i class="bi bi-slash-circle text-muted" title="Hidden from pickers"></i>
                                <?php endif; ?>
                            </td>
                            <td data-col-priority="primary" class="text-end text-nowrap">
                                <button type="button" class="btn btn-sm btn-outline-info"
                                        onclick="openPublisherEditModal(<?= htmlspecialchars((string)$rowJson, ENT_QUOTES, 'UTF-8') ?>)"
                                        title="Edit publisher + aliases">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-danger"
                                        onclick="openPublisherDeleteModal(<?= htmlspecialchars((string)$deleteJson, ENT_QUOTES, 'UTF-8') ?>)"
                                        title="Delete publisher (books keep their free-text Publisher)">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$rows): ?>
                        <tr><td colspan="8" class="text-muted text-center py-4">No publishers yet. Add one below (or run the Publishers migration on <a href="/manage/setup-database">/manage/setup-database</a> to backfill from existing songbooks).</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Create -->
        <form method="POST" class="card-admin p-3 mb-4">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="create">
            <h2 class="h6 mb-3"><i class="bi bi-plus-circle me-2"></i>Add a publisher</h2>
            <div class="row g-2">
                <div class="col-sm-5">
                    <label class="form-label small">Name</label>
                    <input type="text" name="name" class="form-control form-control-sm" maxlength="255" required placeholder="e.g. Praise Trust">
                </div>
                <div class="col-sm-3">
                    <label class="form-label small">Slug <small class="text-muted">(auto)</small></label>
                    <input type="text" name="slug" class="form-control form-control-sm" maxlength="120" pattern="[a-z0-9-]+" placeholder="praise-trust">
                </div>
                <div class="col-sm-4">
                    <label class="form-label small">Kind</label>
                    <select name="kind" class="form-select form-select-sm">
                        <?php foreach (IHYMNS_PUBLISHER_KINDS as $k => $label): ?>
                            <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="row g-2 mt-2">
                <div class="col-sm-6">
                    <label class="form-label small">Subtitle <small class="text-muted">(optional)</small></label>
                    <input type="text" name="subtitle" class="form-control form-control-sm" maxlength="255">
                </div>
                <div class="col-sm-6">
                    <label class="form-label small">Disambiguation <small class="text-muted">(optional)</small></label>
                    <input type="text" name="disambiguation" class="form-control form-control-sm" maxlength="255" placeholder="Distinguish same-named publishers">
                </div>
            </div>
            <div class="row g-2 mt-2">
                <div class="col-sm-3">
                    <label class="form-label small">IPI <small class="text-muted">(optional)</small></label>
                    <input type="text" name="ipi" class="form-control form-control-sm" maxlength="20">
                </div>
                <div class="col-sm-3">
                    <label class="form-label small">ISNI <small class="text-muted">(optional)</small></label>
                    <input type="text" name="isni" class="form-control form-control-sm" maxlength="20">
                </div>
                <div class="col-sm-6 d-flex align-items-end">
                    <div class="form-check">
                        <input type="checkbox" name="is_active" id="create-publisher-active" class="form-check-input" value="1" checked>
                        <label class="form-check-label small" for="create-publisher-active">Active (shown in pickers)</label>
                    </div>
                </div>
            </div>
            <div class="row g-2 mt-2">
                <div class="col-12">
                    <label class="form-label small">Notes <small class="text-muted">(optional)</small></label>
                    <textarea name="notes" class="form-control form-control-sm" rows="2" maxlength="2000"></textarea>
                </div>
            </div>
            <button type="submit" class="btn btn-amber btn-sm mt-3">
                <i class="bi bi-plus me-1"></i>Create publisher
            </button>
            <p class="form-text small mt-2 mb-0">
                Link a person, set a parent/imprint, a city and aliases via the <em>Edit</em> button after creating.
            </p>
        </form>

        <!-- Edit Modal -->
        <div class="modal fade" id="publisherEditModal" tabindex="-1">
            <div class="modal-dialog modal-xl">
                <div class="modal-content" style="background: var(--ih-surface); color: var(--ih-text); border-color: var(--ih-border);">
                    <form method="POST" id="publisher-edit-form">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="id" id="edit-publisher-id">
                        <div class="modal-header" style="border-color: var(--ih-border);">
                            <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit publisher — <span id="edit-publisher-name-label"></span></h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="row g-2 mb-3">
                                <div class="col-md-5">
                                    <label class="form-label">Name</label>
                                    <input type="text" name="name" id="edit-publisher-name" class="form-control" maxlength="255" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Slug</label>
                                    <input type="text" name="slug" id="edit-publisher-slug" class="form-control" maxlength="120" pattern="[a-z0-9-]+">
                                    <div class="form-text small">Changing this changes <code>/publisher/&lt;slug&gt;</code> — old links still resolve via the name/alias fallback.</div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Kind</label>
                                    <select name="kind" id="edit-publisher-kind" class="form-select">
                                        <?php foreach (IHYMNS_PUBLISHER_KINDS as $k => $label): ?>
                                            <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="row g-2 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label">Subtitle <small class="text-muted">(opt.)</small></label>
                                    <input type="text" name="subtitle" id="edit-publisher-subtitle" class="form-control" maxlength="255">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Disambiguation <small class="text-muted">(opt.)</small></label>
                                    <input type="text" name="disambiguation" id="edit-publisher-disambiguation" class="form-control" maxlength="255">
                                </div>
                            </div>
                            <div class="row g-2 mb-3">
                                <div class="col-md-3">
                                    <label class="form-label">IPI <small class="text-muted">(opt.)</small></label>
                                    <input type="text" name="ipi" id="edit-publisher-ipi" class="form-control" maxlength="20">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">ISNI <small class="text-muted">(opt.)</small></label>
                                    <input type="text" name="isni" id="edit-publisher-isni" class="form-control" maxlength="20">
                                </div>
                                <div class="col-md-6 d-flex align-items-end">
                                    <div class="form-check">
                                        <input type="checkbox" name="is_active" id="edit-publisher-active" class="form-check-input" value="1">
                                        <label class="form-check-label" for="edit-publisher-active">Active (shown in pickers)</label>
                                    </div>
                                </div>
                            </div>

                            <div class="row g-2 mb-3">
                                <?php if ($gates['hasMusicians']): ?>
                                <div class="col-md-6">
                                    <label class="form-label">Link to person <small class="text-muted">(opt.)</small></label>
                                    <input type="text" id="edit-publisher-musician-name" class="form-control"
                                           list="edit-publisher-musician-datalist" autocomplete="off"
                                           placeholder="Type a musician's name…">
                                    <input type="hidden" name="musician_id" id="edit-publisher-musician-id">
                                    <datalist id="edit-publisher-musician-datalist"></datalist>
                                    <div class="form-text small">For a person-publisher — links to the Musicians registry so they're not duplicated.</div>
                                </div>
                                <?php endif; ?>
                                <div class="col-md-6">
                                    <label class="form-label">Parent publisher / imprint of <small class="text-muted">(opt.)</small></label>
                                    <input type="text" id="edit-publisher-parent-name" class="form-control"
                                           list="edit-publisher-parent-datalist" autocomplete="off"
                                           placeholder="Type a parent publisher's name…">
                                    <input type="hidden" name="parent_id" id="edit-publisher-parent-id">
                                    <datalist id="edit-publisher-parent-datalist"></datalist>
                                    <div class="form-text small">Groups this imprint under a parent publisher's catalogue.</div>
                                </div>
                            </div>

                            <?php if ($gates['hasPlaces']): ?>
                            <div class="row g-2 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label">City <small class="text-muted">(opt.)</small></label>
                                    <input type="text" name="city_name" id="edit-publisher-city" class="form-control js-place-search" autocomplete="off" maxlength="255" placeholder="e.g. London">
                                    <input type="hidden" name="city_id" id="edit-publisher-city-id">
                                    <div class="form-text small">Place of publication.</div>
                                </div>
                            </div>
                            <?php endif; ?>

                            <div class="row g-2 mb-3">
                                <div class="col-12">
                                    <label class="form-label">Notes <small class="text-muted">(opt.)</small></label>
                                    <textarea name="notes" id="edit-publisher-notes" class="form-control" rows="2" maxlength="2000"></textarea>
                                </div>
                            </div>

                            <?php if ($gates['hasAliases']): ?>
                            <hr>
                            <h6 class="mb-2"><i class="bi bi-tags me-2"></i>Aliases</h6>
                            <p class="form-text small mt-0 mb-2">
                                Former or alternate names this publisher is known by — each resolves
                                <code>/publisher/&lt;alias-slug&gt;</code> to this same publisher.
                            </p>
                            <div id="edit-publisher-aliases-rows" class="vstack gap-2 mb-2"></div>
                            <button type="button" class="btn btn-outline-info btn-sm mb-3" id="edit-publisher-alias-add-btn">
                                <i class="bi bi-plus-lg me-1"></i>Add alias
                            </button>
                            <?php endif; ?>
                        </div>
                        <div class="modal-footer" style="border-color: var(--ih-border);">
                            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-amber btn-sm"><i class="bi bi-check-lg me-1"></i>Save changes</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Delete Modal -->
        <div class="modal fade" id="publisherDeleteModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content" style="background: var(--ih-surface); color: var(--ih-text); border-color: var(--ih-border);">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" id="delete-publisher-id">
                        <div class="modal-header" style="border-color: var(--ih-border);">
                            <h5 class="modal-title text-danger"><i class="bi bi-exclamation-triangle me-2"></i>Delete publisher</h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <p>Delete <strong id="delete-publisher-name-label"></strong>?</p>
                            <p class="small text-muted mb-0" id="delete-publisher-usage-label"></p>
                            <p class="small text-muted mb-0">Songbooks keep their free-text <code>Publisher</code> string; any imprints under this publisher become top-level.</p>
                        </div>
                        <div class="modal-footer" style="border-color: var(--ih-border);">
                            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-danger btn-sm"><i class="bi bi-trash me-1"></i>Delete</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Merge Modal -->
        <div class="modal fade" id="publisherMergeModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content" style="background: var(--ih-surface); color: var(--ih-text); border-color: var(--ih-border);">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                        <input type="hidden" name="action" value="merge">
                        <div class="modal-header" style="border-color: var(--ih-border);">
                            <h5 class="modal-title"><i class="bi bi-shuffle me-2"></i>Merge publishers</h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <p class="small text-muted">
                                Fold the <strong>source</strong> into the <strong>target</strong>: every songbook link,
                                imprint, and alias moves to the target; the source's name becomes an alias of the
                                target; the source is deleted. This cannot be undone.
                            </p>
                            <div class="mb-3">
                                <label class="form-label small">Source (merged away)</label>
                                <select name="source_id" class="form-select form-select-sm" required>
                                    <option value="">— choose —</option>
                                    <?php foreach ($rows as $r): ?>
                                        <option value="<?= (int)$r['Id'] ?>"><?= htmlspecialchars((string)$r['Name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label small">Target (survives)</label>
                                <select name="target_id" class="form-select form-select-sm" required>
                                    <option value="">— choose —</option>
                                    <?php foreach ($rows as $r): ?>
                                        <option value="<?= (int)$r['Id'] ?>"><?= htmlspecialchars((string)$r['Name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="modal-footer" style="border-color: var(--ih-border);">
                            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-warning btn-sm"><i class="bi bi-shuffle me-1"></i>Merge</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <?php endif; /* $hasSchema */ ?>
    </div>

    <?php if ($hasSchema): ?>
    <script src="/js/modules/place-search.js?v=<?= filemtime(dirname(__DIR__) . '/js/modules/place-search.js') ?>"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        /* ---- Datalist typeahead helper: wires a visible <input list> + a
           <datalist> + a hidden id input to a JSON search endpoint. Resolves
           the typed label to the hidden id from the last fetched batch (the
           compiler/parent pattern from songbooks.php). ---- */
        function wireTypeahead(nameInputId, hiddenInputId, datalistId, searchUrl) {
            const nameInput = document.getElementById(nameInputId);
            const hidden    = document.getElementById(hiddenInputId);
            const datalist  = document.getElementById(datalistId);
            if (!nameInput || !hidden || !datalist) return;
            let labelToId = {};
            let timer = null;
            function refresh(q) {
                fetch(searchUrl + (searchUrl.indexOf('?') >= 0 ? '&' : '?') + 'q=' + encodeURIComponent(q), {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                    .then((r) => r.ok ? r.json() : { suggestions: [] })
                    .then((data) => {
                        labelToId = {};
                        datalist.innerHTML = '';
                        (data.suggestions || []).forEach((s) => {
                            labelToId[s.name] = s.id;
                            const opt = document.createElement('option');
                            opt.value = s.name;
                            datalist.appendChild(opt);
                        });
                        resolve();
                    })
                    .catch(() => {});
            }
            function resolve() {
                const v = nameInput.value.trim();
                hidden.value = (v !== '' && labelToId[v] !== undefined) ? labelToId[v] : '';
            }
            nameInput.addEventListener('input', function () {
                if (timer) clearTimeout(timer);
                const q = nameInput.value.trim();
                resolve();
                timer = setTimeout(() => refresh(q), 200);
            });
            nameInput.addEventListener('change', resolve);
            /* expose a preset so openEditModal can seed the label+id without a fetch */
            nameInput._presetTypeahead = function (id, name) {
                nameInput.value = name || '';
                hidden.value = id || '';
                if (name) { labelToId[name] = id; }
            };
        }

        <?php if ($gates['hasMusicians']): ?>
        wireTypeahead('edit-publisher-musician-name', 'edit-publisher-musician-id', 'edit-publisher-musician-datalist', '?action=musician_search');
        <?php endif; ?>
        wireTypeahead('edit-publisher-parent-name', 'edit-publisher-parent-id', 'edit-publisher-parent-datalist', '?action=publisher_search');

        <?php if ($gates['hasPlaces']): ?>
        if (window.iHymnsPlaceSearch) {
            const cityVisible = document.getElementById('edit-publisher-city');
            const cityHidden  = document.getElementById('edit-publisher-city-id');
            if (cityVisible && cityHidden) {
                window.iHymnsPlaceSearch.attach(cityVisible, { hiddenIdInput: cityHidden });
            }
        }
        <?php endif; ?>

        <?php if ($gates['hasAliases']): ?>
        /* ---- Alias rows ---- */
        const aliasRows   = document.getElementById('edit-publisher-aliases-rows');
        const aliasAddBtn = document.getElementById('edit-publisher-alias-add-btn');
        function buildAliasRow(value) {
            const row = document.createElement('div');
            row.className = 'input-group input-group-sm';
            const input = document.createElement('input');
            input.type = 'text';
            input.name = 'alias_names[]';
            input.className = 'form-control';
            input.maxLength = 255;
            input.placeholder = 'Alternate name';
            input.value = value || '';
            const rm = document.createElement('button');
            rm.type = 'button';
            rm.className = 'btn btn-outline-danger';
            rm.innerHTML = '<i class="bi bi-x-lg"></i>';
            rm.addEventListener('click', () => row.remove());
            row.appendChild(input);
            row.appendChild(rm);
            return row;
        }
        if (aliasAddBtn) aliasAddBtn.addEventListener('click', () => aliasRows.appendChild(buildAliasRow('')));
        <?php endif; ?>

        window.openPublisherEditModal = function (row) {
            document.getElementById('edit-publisher-id').value = row.id;
            document.getElementById('edit-publisher-name-label').textContent = row.name || '';
            document.getElementById('edit-publisher-name').value = row.name || '';
            document.getElementById('edit-publisher-slug').value = row.slug || '';
            document.getElementById('edit-publisher-kind').value = row.kind || 'company';
            document.getElementById('edit-publisher-subtitle').value = row.subtitle || '';
            document.getElementById('edit-publisher-disambiguation').value = row.disambiguation || '';
            document.getElementById('edit-publisher-ipi').value = row.ipi || '';
            document.getElementById('edit-publisher-isni').value = row.isni || '';
            document.getElementById('edit-publisher-notes').value = row.notes || '';
            document.getElementById('edit-publisher-active').checked = !!row.is_active;

            const musicianName = document.getElementById('edit-publisher-musician-name');
            if (musicianName && musicianName._presetTypeahead) {
                musicianName._presetTypeahead(row.musician_id || '', row.musician_name || '');
            }
            const parentName = document.getElementById('edit-publisher-parent-name');
            if (parentName && parentName._presetTypeahead) {
                parentName._presetTypeahead(row.parent_id || '', row.parent_name || '');
            }
            const cityVisible = document.getElementById('edit-publisher-city');
            const cityHidden  = document.getElementById('edit-publisher-city-id');
            if (cityVisible) cityVisible.value = row.city_name || '';
            if (cityHidden)  cityHidden.value = row.city_id || '';

            <?php if ($gates['hasAliases']): ?>
            if (aliasRows) {
                aliasRows.innerHTML = '';
                (row.aliases || []).forEach((a) => aliasRows.appendChild(buildAliasRow(a)));
            }
            <?php endif; ?>

            new bootstrap.Modal(document.getElementById('publisherEditModal')).show();
        };

        window.openPublisherDeleteModal = function (row) {
            document.getElementById('delete-publisher-id').value = row.id;
            document.getElementById('delete-publisher-name-label').textContent = row.name || '';
            const usageEl = document.getElementById('delete-publisher-usage-label');
            if (usageEl) {
                usageEl.textContent = row.songbookCount > 0
                    ? row.songbookCount + ' songbook(s) currently link to this publisher.'
                    : 'No songbooks currently link to this publisher.';
            }
            new bootstrap.Modal(document.getElementById('publisherDeleteModal')).show();
        };
    });
    </script>
    <?php endif; ?>

    <!-- Sortable table headers (#1786 sweep — tagged cp-sortable but never
         booted; every header click was a silent no-op until now). -->
    <script type="module">
        import { bootSortableTables } from '/js/modules/admin-table-sort.js?v=<?= filemtime(dirname(__DIR__) . '/js/modules/admin-table-sort.js') ?>';
        bootSortableTables();
    </script>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>
</body>
</html>
