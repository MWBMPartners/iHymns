<?php

declare(strict_types=1);

/**
 * iHymns — Admin: Songbooks
 *
 * CRUD surface for `tblSongbooks`. Gated by the `manage_songbooks`
 * entitlement. Safe-guards:
 *   - Abbreviation is the natural key on tblSongs.SongbookAbbr — renaming
 *     it is opt-in and cascades via an explicit "also rename song refs"
 *     checkbox.
 *   - Delete refuses if any song still references the abbreviation.
 *   - DisplayOrder is seeded by migrate-account-sync.php; the UI writes
 *     back whole-table updates so reordering is atomic.
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
$activePage = 'songbooks';

$error   = '';
$success = '';
$db      = getDbMysqli();

/* Helpers */
$validateAbbr = function (string $abbr): ?string {
    $abbr = trim($abbr);
    if ($abbr === '') return 'Abbreviation is required.';
    if (strlen($abbr) > 10) return 'Abbreviation must be 10 characters or fewer.';
    if (!preg_match('/^[A-Za-z0-9]+$/', $abbr)) return 'Abbreviation must be letters/numbers only (no spaces or punctuation).';
    return null;
};
$validateColour = function (string $c): ?string {
    if ($c === '') return null;
    return preg_match('/^#[0-9A-Fa-f]{6}$/', $c) ? null : 'Colour must be a #RRGGBB hex value (or blank).';
};

/* ----- POST actions ----- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrf((string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo 'Invalid CSRF token';
        exit;
    }

    $action = (string)($_POST['action'] ?? '');

    try {
        switch ($action) {
            case 'create': {
                $abbr    = trim((string)($_POST['abbreviation']    ?? ''));
                $name    = trim((string)($_POST['name']            ?? ''));
                $colour  = trim((string)($_POST['colour']          ?? ''));
                $order   = (int)($_POST['display_order']           ?? 0);
                /* #502 — new metadata columns. All nullable; empty
                   input normalises to null so the UNIQUE/null-group
                   semantics work as expected. */
                $isOfficial = !empty($_POST['is_official']) ? 1 : 0;
                $publisher  = trim((string)($_POST['publisher']        ?? '')) ?: null;
                $pubYear    = trim((string)($_POST['publication_year'] ?? '')) ?: null;
                $copyright  = trim((string)($_POST['copyright']        ?? '')) ?: null;
                $affiliation= trim((string)($_POST['affiliation']      ?? '')) ?: null;

                if ($e = $validateAbbr($abbr))   { $error = $e; break; }
                if ($name === '')                { $error = 'Name is required.'; break; }
                if ($e = $validateColour($colour)) { $error = $e; break; }

                $stmt = $db->prepare('SELECT Id FROM tblSongbooks WHERE Abbreviation = ?');
                $stmt->bind_param('s', $abbr);
                $stmt->execute();
                $exists = $stmt->get_result()->fetch_row() !== null;
                $stmt->close();
                if ($exists) { $error = 'Abbreviation already exists.'; break; }

                $stmt = $db->prepare(
                    'INSERT INTO tblSongbooks
                        (Abbreviation, Name, DisplayOrder, Colour,
                         IsOfficial, Publisher, PublicationYear, Copyright, Affiliation)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                /* Types: Abbr(s), Name(s), DisplayOrder(i), Colour(s),
                   IsOfficial(i), Publisher(s nullable), PublicationYear(s nullable),
                   Copyright(s nullable), Affiliation(s nullable). mysqli passes
                   NULL correctly when a bound variable is null even with type 's'. */
                $orderInt = (int)($order ?: 0);
                $stmt->bind_param(
                    'ssisissss',
                    $abbr, $name, $orderInt, $colour,
                    $isOfficial, $publisher, $pubYear, $copyright, $affiliation
                );
                $stmt->execute();
                $newId = (int)$db->insert_id;
                $stmt->close();
                logActivity('songbook.create', 'songbook', (string)$newId, [
                    'abbreviation'    => $abbr,
                    'name'            => $name,
                    'display_order'   => $order ?: 0,
                    'colour'          => $colour,
                    'is_official'     => (bool)$isOfficial,
                    'publisher'       => $publisher,
                    'publication_year'=> $pubYear,
                    'copyright'       => $copyright,
                    'affiliation'     => $affiliation,
                ]);
                $success = "Songbook '{$abbr}' created.";
                break;
            }

            case 'update': {
                $id          = (int)($_POST['id'] ?? 0);
                $name        = trim((string)($_POST['name']         ?? ''));
                $colour      = trim((string)($_POST['colour']       ?? ''));
                $order       = (int)($_POST['display_order']        ?? 0);
                $newAbbr     = trim((string)($_POST['new_abbreviation'] ?? ''));
                $alsoRename  = !empty($_POST['rename_song_refs']);
                /* #502 — new metadata columns. */
                $isOfficial  = !empty($_POST['is_official']) ? 1 : 0;
                $publisher   = trim((string)($_POST['publisher']        ?? '')) ?: null;
                $pubYear     = trim((string)($_POST['publication_year'] ?? '')) ?: null;
                $copyright   = trim((string)($_POST['copyright']        ?? '')) ?: null;
                $affiliation = trim((string)($_POST['affiliation']      ?? '')) ?: null;

                /* Fetch the full before-row so the audit log carries
                   a complete diff of which fields actually changed
                   (#535) — otherwise the timeline reader has to
                   guess. */
                $existing = $db->prepare(
                    'SELECT Abbreviation, Name, DisplayOrder, Colour, IsOfficial,
                            Publisher, PublicationYear, Copyright, Affiliation
                       FROM tblSongbooks WHERE Id = ?'
                );
                $existing->bind_param('i', $id);
                $existing->execute();
                $beforeRow = $existing->get_result()->fetch_assoc() ?: null;
                $existing->close();
                $oldAbbr = $beforeRow ? (string)$beforeRow['Abbreviation'] : '';
                if ($oldAbbr === '') { $error = 'Songbook not found.'; break; }

                if ($name === '')                  { $error = 'Name is required.'; break; }
                if ($e = $validateColour($colour)) { $error = $e; break; }

                /* Handle optional abbreviation change */
                $abbrChanged = $newAbbr !== '' && $newAbbr !== $oldAbbr;
                if ($abbrChanged) {
                    if ($e = $validateAbbr($newAbbr)) { $error = $e; break; }
                    $dup = $db->prepare('SELECT Id FROM tblSongbooks WHERE Abbreviation = ? AND Id <> ?');
                    $dup->bind_param('si', $newAbbr, $id);
                    $dup->execute();
                    $dupExists = $dup->get_result()->fetch_row() !== null;
                    $dup->close();
                    if ($dupExists) { $error = 'That abbreviation is already taken.'; break; }
                }

                $db->begin_transaction();
                try {
                    $stmt = $db->prepare(
                        'UPDATE tblSongbooks
                            SET Name = ?, Colour = ?, DisplayOrder = ?,
                                IsOfficial = ?, Publisher = ?,
                                PublicationYear = ?, Copyright = ?, Affiliation = ?
                          WHERE Id = ?'
                    );
                    /* Types: Name(s), Colour(s), DisplayOrder(i),
                       IsOfficial(i), Publisher(s), PublicationYear(s),
                       Copyright(s), Affiliation(s), Id(i). */
                    $orderInt = (int)($order ?: 0);
                    $stmt->bind_param(
                        'ssiissssi',
                        $name, $colour, $orderInt,
                        $isOfficial, $publisher, $pubYear, $copyright, $affiliation,
                        $id
                    );
                    $stmt->execute();
                    $stmt->close();

                    if ($abbrChanged) {
                        $stmt = $db->prepare('UPDATE tblSongbooks SET Abbreviation = ? WHERE Id = ?');
                        $stmt->bind_param('si', $newAbbr, $id);
                        $stmt->execute();
                        $stmt->close();
                        if ($alsoRename) {
                            $stmt = $db->prepare('UPDATE tblSongs SET SongbookAbbr = ? WHERE SongbookAbbr = ?');
                            $stmt->bind_param('ss', $newAbbr, $oldAbbr);
                            $stmt->execute();
                            $stmt->close();
                        }
                    }
                    $db->commit();

                    /* Audit (#535) — compute the changed-fields list
                       explicitly so the row stays small (the full
                       before-row is on $beforeRow and we don't need
                       to dump every key). */
                    $afterRow = [
                        'Abbreviation'   => $abbrChanged ? $newAbbr : $oldAbbr,
                        'Name'           => $name,
                        'DisplayOrder'   => $order ?: 0,
                        'Colour'         => $colour,
                        'IsOfficial'     => $isOfficial,
                        'Publisher'      => $publisher,
                        'PublicationYear'=> $pubYear,
                        'Copyright'      => $copyright,
                        'Affiliation'    => $affiliation,
                    ];
                    $changed = [];
                    foreach ($afterRow as $k => $v) {
                        if (!array_key_exists($k, $beforeRow ?? [])) continue;
                        if ((string)$beforeRow[$k] !== (string)$v) $changed[] = $k;
                    }
                    logActivity('songbook.edit', 'songbook', (string)$id, [
                        'fields'             => $changed,
                        'before'             => array_intersect_key($beforeRow, array_flip($changed)),
                        'after'              => array_intersect_key($afterRow,  array_flip($changed)),
                        'songs_renamed_too'  => $alsoRename && $abbrChanged,
                    ]);

                    $success = $abbrChanged
                        ? "Songbook '{$oldAbbr}' → '{$newAbbr}'" . ($alsoRename ? ' (song references updated).' : ' (song references kept — resolve manually).')
                        : "Songbook '{$oldAbbr}' updated.";
                } catch (\Throwable $e) {
                    $db->rollback();
                    throw $e;
                }
                break;
            }

            case 'reorder': {
                /* Posted as display_order[id] = integer */
                $orders = $_POST['display_order'] ?? [];
                if (!is_array($orders)) { $error = 'Invalid reorder payload.'; break; }

                $db->begin_transaction();
                try {
                    $stmt = $db->prepare('UPDATE tblSongbooks SET DisplayOrder = ? WHERE Id = ?');
                    foreach ($orders as $id => $value) {
                        $valueInt = (int)$value;
                        $idInt    = (int)$id;
                        $stmt->bind_param('ii', $valueInt, $idInt);
                        $stmt->execute();
                    }
                    $stmt->close();
                    $db->commit();

                    /* Single audit row for the bulk reorder rather
                       than one per row — the activity-log viewer
                       wants the high-level operation, not 6
                       near-identical entries. (#535) */
                    logActivity('songbook.reorder', 'songbook', '', [
                        'count' => count($orders),
                        'order' => array_map(fn($v) => (int)$v, (array)$orders),
                    ]);

                    $success = 'Display order saved.';
                } catch (\Throwable $e) {
                    $db->rollback();
                    throw $e;
                }
                break;
            }

            case 'delete': {
                $id = (int)($_POST['id'] ?? 0);

                $stmt = $db->prepare('SELECT Abbreviation FROM tblSongbooks WHERE Id = ?');
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_row();
                $stmt->close();
                $abbr = (string)($row[0] ?? '');
                if ($abbr === '') { $error = 'Songbook not found.'; break; }

                $stmt = $db->prepare('SELECT COUNT(*) FROM tblSongs WHERE SongbookAbbr = ?');
                $stmt->bind_param('s', $abbr);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_row();
                $stmt->close();
                $songCount = (int)($row[0] ?? 0);
                if ($songCount > 0) {
                    $error = "Cannot delete '{$abbr}': {$songCount} song(s) still reference it. Reassign them first.";
                    break;
                }

                $stmt = $db->prepare('DELETE FROM tblSongbooks WHERE Id = ?');
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $stmt->close();

                /* Audit (#535) — capturing abbreviation in Details
                   means the row remains useful even after the FK
                   nulls out / the songbook is gone. */
                logActivity('songbook.delete', 'songbook', (string)$id, [
                    'abbreviation' => $abbr,
                ]);

                $success = "Songbook '{$abbr}' deleted.";
                break;
            }

            default:
                $error = 'Unknown action.';
        }
    } catch (\Throwable $e) {
        error_log('[manage/songbooks.php] ' . $e->getMessage());
        $error = $error ?: 'Database error — check server logs for details.';
    }
}

/* ----- GET: list ----- */
$rows = [];
try {
    $stmt = $db->prepare(
        'SELECT b.Id, b.Abbreviation, b.Name, b.SongCount, b.DisplayOrder, b.Colour,
                b.IsOfficial, b.Publisher, b.PublicationYear,
                b.Copyright, b.Affiliation,
                COUNT(s.Id) AS ActualSongCount
           FROM tblSongbooks b
           LEFT JOIN tblSongs s ON s.SongbookAbbr = b.Abbreviation
          GROUP BY b.Id
          ORDER BY b.DisplayOrder ASC, b.Name ASC'
    );
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} catch (\Throwable $e) {
    error_log('[manage/songbooks.php] ' . $e->getMessage());
    $error = $error ?: 'Could not load songbooks — check server logs for details.';
}

$csrf = csrfToken();
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Songbooks — iHymns Admin</title>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-libs.php'; ?>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-favicon.php'; ?>
</head>
<body>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-nav.php'; ?>

    <div class="container-admin py-4">

        <h1 class="h4 mb-3"><i class="bi bi-book me-2"></i>Songbooks</h1>
        <p class="text-secondary small mb-4">
            Add, rename, reorder and remove the songbooks users see in filters,
            search and the Song Editor. Abbreviation is the natural key on each
            song (<code>tblSongs.SongbookAbbr</code>), so renaming is opt-in.
        </p>

        <?php if ($success): ?>
            <div class="alert alert-success py-2"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <!-- List + reorder -->
        <form method="POST" class="card-admin p-3 mb-4">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="reorder">
            <table class="table table-sm mb-2 align-middle cp-sortable">
                <thead>
                    <tr class="text-muted small">
                        <th style="width:6rem">Order</th>
                        <th data-sort-key="abbr" data-sort-type="text">Abbr</th>
                        <th data-sort-key="name" data-sort-type="text">Name</th>
                        <th class="text-center" data-sort-key="official" data-sort-type="text" title="Official published hymnal (#502)">Official</th>
                        <th class="text-center" data-sort-key="songs" data-sort-type="number">Songs</th>
                        <th data-sort-key="colour" data-sort-type="text">Colour</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td>
                                <input type="number" min="0" step="10"
                                       class="form-control form-control-sm"
                                       name="display_order[<?= (int)$r['Id'] ?>]"
                                       value="<?= (int)$r['DisplayOrder'] ?>">
                            </td>
                            <td><code><?= htmlspecialchars($r['Abbreviation']) ?></code></td>
                            <td><?= htmlspecialchars($r['Name']) ?></td>
                            <td class="text-center">
                                <?php if ((int)$r['IsOfficial'] === 1): ?>
                                    <span class="badge bg-info" title="Official published hymnal">
                                        <i class="bi bi-patch-check-fill" aria-hidden="true"></i> Yes
                                    </span>
                                <?php else: ?>
                                    <small class="text-muted" title="Curated grouping / pseudo-songbook">—</small>
                                <?php endif; ?>
                            </td>
                            <td class="text-center"><?= number_format((int)$r['ActualSongCount']) ?></td>
                            <td>
                                <?php if ($r['Colour']): ?>
                                    <span class="d-inline-block me-1" style="width:1rem;height:1rem;border-radius:50%;background:<?= htmlspecialchars($r['Colour']) ?>"></span>
                                    <small class="text-muted"><?= htmlspecialchars($r['Colour']) ?></small>
                                <?php else: ?>
                                    <small class="text-muted">—</small>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <button type="button" class="btn btn-sm btn-outline-info"
                                        onclick='openEditModal(<?= json_encode([
                                            'id'               => (int)$r['Id'],
                                            'abbreviation'     => $r['Abbreviation'],
                                            'name'             => $r['Name'],
                                            'colour'           => $r['Colour'],
                                            'display_order'    => (int)$r['DisplayOrder'],
                                            'song_count'       => (int)$r['ActualSongCount'],
                                            'is_official'      => (int)$r['IsOfficial'] === 1,
                                            'publisher'        => $r['Publisher']       ?? '',
                                            'publication_year' => $r['PublicationYear'] ?? '',
                                            'copyright'        => $r['Copyright']       ?? '',
                                            'affiliation'      => $r['Affiliation']     ?? '',
                                        ]) ?>)'
                                        title="Edit songbook">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <?php if ((int)$r['ActualSongCount'] === 0): ?>
                                <button type="button" class="btn btn-sm btn-outline-danger"
                                        onclick='openDeleteModal(<?= json_encode(['id' => (int)$r['Id'], 'abbreviation' => $r['Abbreviation']]) ?>)'
                                        title="Delete songbook (no songs reference it)">
                                    <i class="bi bi-trash"></i>
                                </button>
                                <?php else: ?>
                                <button type="button" class="btn btn-sm btn-outline-secondary" disabled
                                        title="<?= (int)$r['ActualSongCount'] ?> song(s) still reference this abbreviation — reassign them first">
                                    <i class="bi bi-trash"></i>
                                </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$rows): ?>
                        <tr><td colspan="7" class="text-muted text-center py-4">No songbooks yet. Add one below.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
            <?php if ($rows): ?>
                <button type="submit" class="btn btn-sm btn-amber-solid">
                    <i class="bi bi-save me-1"></i>Save display order
                </button>
                <small class="text-muted ms-2">Lower numbers render first. Step of 10 lets you insert between two rows.</small>
            <?php endif; ?>
        </form>

        <!-- Create -->
        <form method="POST" class="card-admin p-3 mb-4">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="create">
            <h2 class="h6 mb-3"><i class="bi bi-plus-circle me-2"></i>Add a songbook</h2>

            <div class="row g-2">
                <div class="col-sm-3">
                    <label class="form-label small">Abbreviation</label>
                    <input type="text" name="abbreviation" class="form-control form-control-sm"
                           pattern="[A-Za-z0-9]+" maxlength="10" required
                           placeholder="e.g. CP">
                </div>
                <div class="col-sm-5">
                    <label class="form-label small">Name</label>
                    <input type="text" name="name" class="form-control form-control-sm"
                           maxlength="255" required placeholder="e.g. Church Praise">
                </div>
                <div class="col-sm-2">
                    <label class="form-label small">Colour (hex)</label>
                    <input type="text" name="colour" class="form-control form-control-sm"
                           pattern="#[0-9A-Fa-f]{6}" placeholder="#1a73e8">
                </div>
                <div class="col-sm-2">
                    <label class="form-label small">Display order</label>
                    <input type="number" name="display_order" class="form-control form-control-sm"
                           min="0" step="10" value="0">
                </div>
            </div>

            <!-- #502 metadata -->
            <div class="row g-2 mt-2">
                <div class="col-sm-3 d-flex align-items-end">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="is_official" id="create-is-official" value="1">
                        <label class="form-check-label small" for="create-is-official">
                            Official published hymnal
                        </label>
                        <div class="form-text small">
                            Unticked by default — tick for a published hymnal; leave unticked for a curated grouping.
                        </div>
                    </div>
                </div>
                <div class="col-sm-5">
                    <label class="form-label small">Publisher</label>
                    <input type="text" name="publisher" class="form-control form-control-sm"
                           maxlength="255" placeholder="e.g. Praise Trust">
                </div>
                <div class="col-sm-4">
                    <label class="form-label small">Publication year / edition</label>
                    <input type="text" name="publication_year" class="form-control form-control-sm"
                           maxlength="50" placeholder="e.g. 1986, 1986–2003, 2nd edition 2011">
                </div>
            </div>
            <div class="row g-2 mt-2">
                <div class="col-sm-8">
                    <label class="form-label small">Copyright</label>
                    <input type="text" name="copyright" class="form-control form-control-sm"
                           maxlength="500" placeholder="e.g. © 2012 Praise Trust, All Rights Reserved">
                </div>
                <div class="col-sm-4">
                    <label class="form-label small">Affiliation</label>
                    <input type="text" name="affiliation" class="form-control form-control-sm"
                           maxlength="120" placeholder="e.g. Seventh-day Adventist, Non-denominational">
                </div>
            </div>

            <button type="submit" class="btn btn-amber-solid btn-sm mt-3">
                <i class="bi bi-plus me-1"></i>Create songbook
            </button>
        </form>

    </div>

    <!-- Edit Modal -->
    <div class="modal fade" id="editModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content" style="background: var(--ih-surface); color: var(--ih-text); border-color: var(--ih-border);">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="id" id="edit-id">
                    <div class="modal-header" style="border-color: var(--ih-border);">
                        <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit songbook — <code id="edit-abbr-label"></code></h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Name</label>
                            <input type="text" class="form-control" name="name" id="edit-name" maxlength="255" required>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-sm-6">
                                <label class="form-label">Colour (hex)</label>
                                <input type="text" class="form-control" name="colour" id="edit-colour"
                                       pattern="#[0-9A-Fa-f]{6}" placeholder="#1a73e8">
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label">Display order</label>
                                <input type="number" class="form-control" name="display_order" id="edit-order"
                                       min="0" step="10">
                            </div>
                        </div>

                        <!-- #502 metadata block -->
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox"
                                   name="is_official" id="edit-is-official" value="1">
                            <label class="form-check-label" for="edit-is-official">
                                Official published hymnal
                            </label>
                            <div class="form-text small">
                                Unticked means this is a curated grouping / pseudo-songbook.
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Publisher</label>
                            <input type="text" class="form-control" name="publisher" id="edit-publisher"
                                   maxlength="255" placeholder="e.g. Praise Trust">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Publication year / edition</label>
                            <input type="text" class="form-control" name="publication_year" id="edit-publication-year"
                                   maxlength="50" placeholder="e.g. 1986, 1986–2003, 2nd edition 2011">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Copyright</label>
                            <input type="text" class="form-control" name="copyright" id="edit-copyright"
                                   maxlength="500" placeholder="e.g. © 2012 Praise Trust, All Rights Reserved">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Affiliation</label>
                            <input type="text" class="form-control" name="affiliation" id="edit-affiliation"
                                   maxlength="120" placeholder="e.g. Seventh-day Adventist, Non-denominational">
                            <div class="form-text small">
                                Free-text for now; a controlled lookup table is planned (#502).
                            </div>
                        </div>

                        <hr>
                        <div class="mb-3">
                            <label class="form-label">New abbreviation (optional)</label>
                            <input type="text" class="form-control" name="new_abbreviation" id="edit-new-abbr"
                                   pattern="[A-Za-z0-9]+" maxlength="10"
                                   placeholder="Leave blank to keep current">
                            <div class="form-text">
                                Abbreviation is the natural key. Renaming will <strong>not</strong> update songs by default.
                            </div>
                        </div>
                        <div class="form-check" id="edit-rename-refs-wrap">
                            <input class="form-check-input" type="checkbox" name="rename_song_refs" id="edit-rename-refs" value="1">
                            <label class="form-check-label" for="edit-rename-refs">
                                Also update <span id="edit-song-count">0</span> song(s) that reference the old abbreviation.
                            </label>
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
    <div class="modal fade" id="deleteModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content" style="background: var(--ih-surface); color: var(--ih-text); border-color: var(--ih-border);">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" id="delete-id">
                    <div class="modal-header" style="border-color: var(--ih-border);">
                        <h5 class="modal-title"><i class="bi bi-trash me-2"></i>Delete — <code id="delete-abbr-label"></code></h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p>Remove this songbook? This is only allowed if no songs reference the abbreviation.</p>
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
        function openEditModal(row) {
            document.getElementById('edit-id').value                = row.id;
            document.getElementById('edit-abbr-label').textContent  = row.abbreviation;
            document.getElementById('edit-name').value              = row.name;
            document.getElementById('edit-colour').value            = row.colour || '';
            document.getElementById('edit-order').value             = row.display_order || 0;

            /* #502 metadata fields */
            document.getElementById('edit-is-official').checked     = !!row.is_official;
            document.getElementById('edit-publisher').value         = row.publisher        || '';
            document.getElementById('edit-publication-year').value  = row.publication_year || '';
            document.getElementById('edit-copyright').value         = row.copyright        || '';
            document.getElementById('edit-affiliation').value       = row.affiliation      || '';

            document.getElementById('edit-new-abbr').value          = '';
            document.getElementById('edit-rename-refs').checked     = false;
            document.getElementById('edit-song-count').textContent  = row.song_count;
            document.getElementById('edit-rename-refs-wrap').style.display = row.song_count > 0 ? '' : 'none';
            new bootstrap.Modal(document.getElementById('editModal')).show();
        }
        function openDeleteModal(row) {
            document.getElementById('delete-id').value = row.id;
            document.getElementById('delete-abbr-label').textContent = row.abbreviation;
            new bootstrap.Modal(document.getElementById('deleteModal')).show();
        }
    </script>

    <!-- Sortable table headers (#644). -->
    <script type="module">
        import { bootSortableTables } from '/js/modules/admin-table-sort.js?v=<?= filemtime(dirname(__DIR__) . '/js/modules/admin-table-sort.js') ?>';
        bootSortableTables();
    </script>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>
</body>
</html>
