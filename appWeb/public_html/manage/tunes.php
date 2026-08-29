<?php

declare(strict_types=1);

/**
 * iHymns — Admin: Tunes (#1748)
 *
 * CRUD surface for the tblTunes registry (#1090/#1741 P1) — the hymn TUNE
 * as a first-class entity (HYFRYDOL, OLD HUNDREDTH, …), with its aliases
 * (tblTuneAliases), composer/arranger/harmoniser/source credits
 * (tblTuneCredits) and external links (tblTuneExternalLinks). Models
 * `manage/works.php` end-to-end (page shape, schema-tolerance idiom,
 * inline-script-after-DOMContentLoaded convention) and
 * `manage/musicians.php` (validateCsrfRequest() same-origin CSRF,
 * merge conventions).
 *
 * All the actual create/update/merge/delete/alias/credit logic lives in
 * `includes/tune_admin.php` — this page's POST handlers are THIN WRAPPERS
 * over those shared cores, and `api.php`'s `admin_tune_*` actions call the
 * SAME functions (rule #22/#35 — one core, two thin callers, nothing to
 * drift).
 *
 * Gated by `manage_tunes`; pre-migration safe — probes `tblTunes` on every
 * page load and renders a friendly CTA pointing at `/manage/setup-database`
 * when missing. Every optional child table
 * (tblTuneAliases/tblTuneCredits/tblTuneExternalLinks/tblMusicians) and the
 * tblSongs/tblWorks.TuneId columns are probed independently via
 * `tuneAdminProbeGates()` — a pre-enrichment install with only
 * `tblTunes`+`tblTuneAliases` still works (rule #9).
 *
 * @link .claude/catalogue-1741-1748-plan.md §3   the build spec this file implements
 * @link appWeb/public_html/manage/works.php       page-shape model
 * @link appWeb/public_html/manage/musicians.php   merge + validateCsrfRequest() model
 * @link appWeb/public_html/manage/tags.php        merge-modal shape model (case 'merge':, :216)
 * @link appWeb/public_html/includes/tune_admin.php the shared cores this page delegates to
 * @see #1748
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'slug-field.php';   /* #1870 — ihymns_slug_advanced_field() */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_mysql.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'external_link_helpers.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'media_identifiers.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'tune_helpers.php';
/* #1748 — the shared validate/persist/merge/delete cores. Both this page's
   POST handlers AND api.php's admin_tune_* actions call these SAME
   functions (rule #22/#35). */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'tune_admin.php';

/* ---- Gate FIRST, before any output (rule #1587 / test-tune-admin-surface.php
   assertion 1 — the ordering hole test-admin-gate-parity.php's own doc-block
   admits it does not check). ---- */
if (!isAuthenticated()) {
    header('Location: /manage/login');
    exit;
}
$currentUser = getCurrentUser();
if (!$currentUser || !userHasEntitlement('manage_tunes', $currentUser['role'] ?? null)) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><body><h1>403 — manage_tunes required</h1></body></html>';
    exit;
}
$activePage = 'tunes';

$error   = '';
$success = '';
$db      = getDbMysqli();
$csrf    = csrfToken();

/* ---- §3.2 Probes ---- */
$hasSchema = tuneTunesTableExists($db);
$gates = $hasSchema
    ? tuneAdminProbeGates($db)
    : ['hasAliases' => false, 'hasCredits' => false, 'hasLinks' => false, 'hasMusicians' => false, 'hasSongsTuneId' => false, 'hasWorksTuneCols' => false, 'hasTuneEnrichCols' => false];

/* External links registry — loaded only when the table exists; an EMPTY
   array (table exists, no type has ticked 'tune' in AppliesTo yet) still
   renders the panel with a curator-facing hint rather than hiding it. */
$linkTypesForTune = $gates['hasLinks'] ? loadExternalLinkTypesFor($db, 'tune') : [];

/* ---- §3.3 GET ?action=musician_search — typeahead for the credit rows'
   musician picker. Only rendered/used when tblMusicians exists. ---- */
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
            $out[] = [
                'id'   => (int)$row['Id'],
                'name' => (string)$row['Name'],
                'slug' => (string)$row['Slug'],
            ];
        }
        $stmt->close();
        echo json_encode(['suggestions' => $out], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        error_log('[tunes musician_search] ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Search failed.']);
    }
    exit;
}

/* ---- §3.4 POST actions — all gated by validateCsrfRequest(). ---- */
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
                [$fields, $fieldError] = tuneAdminValidateFields($_POST);
                if ($fieldError !== null) { $error = $fieldError; break; }

                $uniqError = tuneAdminCheckUniqueness($db, $fields, null);
                if ($uniqError !== null) { $error = $uniqError; break; }

                $db->begin_transaction();
                try {
                    /* tuneAdminCreate() pairs THE ONE find-or-create funnel
                       (tuneFindOrCreateByName(), rule #22) with the
                       scalar-fields UPDATE — this reference is also what
                       keeps test-tune-lockstep.php's derived write-site
                       sweep green for tune_admin.php (see that function's
                       doc-block). Name uniqueness was already confirmed
                       above, so the funnel always CREATES (never finds) in
                       the normal case; a genuine TOCTOU race degrades to
                       reusing the concurrently-created row, same as every
                       other caller of this funnel. */
                    $newId = tuneAdminCreate($db, $fields);
                    $db->commit();
                } catch (\Throwable $tx) {
                    $db->rollback();
                    throw $tx;
                }

                if (function_exists('logActivity')) {
                    logActivity('tune.create', 'tune', (string)$newId, [
                        'name'       => $fields['name'],
                        'meterCode'  => $fields['meterCode'],
                        'musicBrainzWorkMbid' => $fields['musicBrainzWorkMbid'],
                    ]);
                }
                $success = "Tune '{$fields['name']}' created.";
                break;
            }

            case 'update': {
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) { $error = 'Tune id is required.'; break; }

                [$fields, $fieldError] = tuneAdminValidateFields($_POST);
                if ($fieldError !== null) { $error = $fieldError; break; }

                $uniqError = tuneAdminCheckUniqueness($db, $fields, $id);
                if ($uniqError !== null) { $error = $uniqError; break; }

                $stmt = $db->prepare('SELECT Name FROM tblTunes WHERE Id = ?');
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $before = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$before) { $error = 'Tune not found.'; break; }
                $oldName = (string)$before['Name'];

                /* Alias/credit rows — parallel POST arrays. */
                $aliasNames = $_POST['alias_names'] ?? [];
                if (!is_array($aliasNames)) $aliasNames = [];
                $creditRoles      = $_POST['credit_roles']       ?? [];
                $creditNames      = $_POST['credit_names']       ?? [];
                $creditMusicianIds = $_POST['credit_musician_ids'] ?? [];
                if (!is_array($creditRoles))       $creditRoles       = [];
                if (!is_array($creditNames))       $creditNames       = [];
                if (!is_array($creditMusicianIds)) $creditMusicianIds = [];
                $creditRows = [];
                $creditCount = max(count($creditRoles), count($creditNames));
                for ($i = 0; $i < $creditCount; $i++) {
                    $creditRows[] = [
                        'role'        => $creditRoles[$i]        ?? '',
                        'name'        => $creditNames[$i]        ?? '',
                        'musician_id' => $creditMusicianIds[$i]  ?? null,
                    ];
                }

                $db->begin_transaction();
                try {
                    /* Step 1 — scalar fields (Name/Slug/… in one UPDATE). */
                    tuneAdminPersistFields($db, $id, $fields);

                    /* Step 2 — rename cascade, gated. Tunes need no
                       separate `rename` action (unlike name-keyed
                       musicians) — the FK makes this cascade precise. */
                    tuneAdminRenameCascade($db, $id, $oldName, $fields['name'], $gates);

                    /* Step 3 — aliases, gated. */
                    if ($gates['hasAliases']) {
                        tuneAdminReplaceAliases($db, $id, $aliasNames, $fields['name']);
                    }

                    /* Step 4 — credits, gated. */
                    if ($gates['hasCredits']) {
                        tuneAdminReplaceCredits($db, $id, $creditRows, $gates);
                    }

                    /* Step 5 — external links, gated. Requires the §5.1
                       whitelist widening (it throws InvalidArgumentException
                       otherwise). */
                    $insertedLinks = 0;
                    if ($gates['hasLinks']) {
                        $insertedLinks = saveExternalLinksForRow(
                            $db,
                            'tblTuneExternalLinks',
                            'TuneId',
                            $id,
                            $_POST['ext_link_type_ids'] ?? [],
                            $_POST['ext_link_urls']     ?? [],
                            $_POST['ext_link_notes']    ?? [],
                            $_POST['ext_link_verified'] ?? []
                        );
                    }

                    $db->commit();
                } catch (\Throwable $tx) {
                    $db->rollback();
                    throw $tx;
                }

                if (function_exists('logActivity')) {
                    logActivity('tune.edit', 'tune', (string)$id, [
                        'name'          => $fields['name'],
                        'renamed'       => $oldName !== $fields['name'],
                        'alias_count'   => count($aliasNames),
                        'credit_count'  => count($creditRows),
                        'link_count'    => $gates['hasLinks'] ? $insertedLinks : null,
                    ]);
                }
                $success = "Tune '{$fields['name']}' updated.";
                break;
            }

            case 'delete': {
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) { $error = 'Tune id is required.'; break; }

                $usage = tuneAdminUsageCounts($db, $id, $gates);
                $name  = tuneAdminDelete($db, $id);
                if ($name === null) { $error = 'Tune not found.'; break; }

                if (function_exists('logActivity')) {
                    logActivity('tune.delete', 'tune', (string)$id, [
                        'name'  => $name,
                        'usage' => $usage,
                    ]);
                }
                $success = "Tune '{$name}' deleted.";
                break;
            }

            case 'merge': {
                $sourceId = (int)($_POST['source_id'] ?? 0);
                $targetId = (int)($_POST['target_id'] ?? 0);
                if ($sourceId <= 0 || $targetId <= 0 || $sourceId === $targetId) {
                    $error = 'Source and target must be different tunes.';
                    break;
                }

                $db->begin_transaction();
                try {
                    $result = tuneAdminMerge($db, $sourceId, $targetId, $gates);
                    $db->commit();
                } catch (\Throwable $tx) {
                    $db->rollback();
                    throw $tx;
                }

                if (function_exists('logActivity')) {
                    logActivity('tune.merge', 'tune', (string)$targetId, [
                        'source'          => $result['source'],
                        'target'          => $result['target'],
                        'songs_repointed' => $result['songsRepointed'],
                        'works_repointed' => $result['worksRepointed'],
                        'aliases_moved'   => $result['aliasesMoved'],
                        'credits_moved'   => $result['creditsMoved'],
                        'links_moved'     => $result['linksMoved'],
                    ]);
                }
                $success = "Merged '{$result['source']['name']}' → '{$result['target']['name']}' "
                         . "({$result['songsRepointed']} song(s), {$result['worksRepointed']} work(s) repointed).";
                break;
            }
        }
    } catch (\Throwable $e) {
        error_log('[tunes POST] ' . $e->getMessage());
        $error = 'Could not save changes: ' . $e->getMessage();
    }
}

/* ---- §3.5 Read + list ---- */
$rows           = [];
$tuneAliasesMap = [];
$tuneCreditsMap = [];
$tuneLinksMap   = [];

if ($hasSchema) {
    try {
        $songCountSelect = $gates['hasSongsTuneId']
            ? '(SELECT COUNT(*) FROM tblSongs WHERE TuneId = t.Id) AS SongCount'
            : '0 AS SongCount';
        $aliasCountSelect = $gates['hasAliases']
            ? '(SELECT COUNT(*) FROM tblTuneAliases WHERE TuneId = t.Id) AS AliasCount'
            : '0 AS AliasCount';
        $creditCountSelect = $gates['hasCredits']
            ? '(SELECT COUNT(*) FROM tblTuneCredits WHERE TuneId = t.Id) AS CreditCount'
            : '0 AS CreditCount';
        $linkCountSelect = $gates['hasLinks']
            ? '(SELECT COUNT(*) FROM tblTuneExternalLinks WHERE TuneId = t.Id) AS LinkCount'
            : '0 AS LinkCount';
        /* #1748 (rule #9) — Subtitle/Disambiguation exist only after the
           tune-enrichment card. Selecting them on a base (tunes-entity-only)
           install throws under mysqli STRICT, which the outer catch would then
           swallow into an EMPTY list — the page would falsely read "No tunes
           yet" over a table the backfill already populated. Gate the fragment;
           the row read below is null-safe ($row['Subtitle'] ?? ''), so an
           absent column just yields '' downstream. Constant fragments only
           (rule #5 carve-out). */
        $enrichColsSelect = $gates['hasTuneEnrichCols']
            ? 't.Subtitle, t.Disambiguation,'
            : '';

        $res = $db->query(
            "SELECT t.Id, t.Name, t.Slug, {$enrichColsSelect} t.MeterCode,
                    t.MusicBrainzWorkMBID, t.HymnaryTuneId, t.Notes,
                    {$songCountSelect}, {$aliasCountSelect}, {$creditCountSelect}, {$linkCountSelect}
               FROM tblTunes t
              ORDER BY t.Name ASC"
        );
        while ($row = $res->fetch_assoc()) {
            $row['Id']          = (int)$row['Id'];
            $row['SongCount']   = (int)$row['SongCount'];
            $row['AliasCount']  = (int)$row['AliasCount'];
            $row['CreditCount'] = (int)$row['CreditCount'];
            $row['LinkCount']   = (int)$row['LinkCount'];
            $rows[] = $row;
        }
        $res->close();

        if ($rows && $gates['hasAliases']) {
            $res = $db->query('SELECT TuneId, Name FROM tblTuneAliases ORDER BY TuneId, Name ASC');
            while ($row = $res->fetch_assoc()) {
                $tuneAliasesMap[(int)$row['TuneId']][] = (string)$row['Name'];
            }
            $res->close();
        }

        if ($rows && $gates['hasCredits']) {
            $res = $db->query(
                'SELECT c.TuneId, c.Role, c.Name, c.MusicianId, m.Name AS MusicianName
                   FROM tblTuneCredits c
                   LEFT JOIN tblMusicians m ON m.Id = c.MusicianId
                  ORDER BY c.TuneId, c.SortOrder ASC, c.Id ASC'
            );
            while ($row = $res->fetch_assoc()) {
                $tuneCreditsMap[(int)$row['TuneId']][] = [
                    'role'         => (string)$row['Role'],
                    'name'         => (string)$row['Name'],
                    'musicianId'   => $row['MusicianId'] !== null ? (int)$row['MusicianId'] : null,
                    'musicianName' => (string)($row['MusicianName'] ?? ''),
                ];
            }
            $res->close();
        }

        if ($rows && $gates['hasLinks']) {
            $res = $db->query(
                'SELECT el.TuneId, el.LinkTypeId, el.Url, el.Note, el.Verified
                   FROM tblTuneExternalLinks el
                  ORDER BY el.TuneId, el.SortOrder ASC'
            );
            while ($row = $res->fetch_assoc()) {
                $tuneLinksMap[(int)$row['TuneId']][] = [
                    'typeId'   => (int)$row['LinkTypeId'],
                    'url'      => (string)$row['Url'],
                    'note'     => (string)($row['Note'] ?? ''),
                    'verified' => (bool)$row['Verified'],
                ];
            }
            $res->close();
        }
    } catch (\Throwable $e) {
        error_log('[tunes read] ' . $e->getMessage());
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
    <title>Tunes — iHymns Admin</title>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-libs.php'; ?>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-favicon.php'; ?>
</head>
<body>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-nav.php'; ?>

    <div class="container-admin py-4">
        <h1 class="h4 mb-3"><i aria-hidden="true" class="bi bi-music-note-beamed me-2"></i>Tunes</h1>
        <p class="text-secondary small mb-4">
            A <strong>Tune</strong> is the melody a hymn is sung to — like HYFRYDOL or OLD HUNDREDTH —
            kept as its own entry, separate from the lyrics that use it. Adding a tune's meter,
            composer credits and links here improves the public tune page shown for every song that
            names it.
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
                    The <code>tblTunes</code> table hasn't been created on this database yet (#1090).
                </p>
                <a href="/manage/setup-database" class="btn btn-amber btn-sm">
                    <i aria-hidden="true" class="bi bi-database-gear me-1"></i>Run /manage/setup-database
                </a>
            </div>
        <?php else: ?>

        <?php if (!$gates['hasLinks']): ?>
            <!-- External-links table absent — the aliases/credits panels
                 below may still be usable; nothing to render here. -->
        <?php elseif (empty($linkTypesForTune)): ?>
            <div class="alert alert-info py-2 small">
                No link types apply to tunes yet — tick "tune" on the relevant providers in
                <a href="/manage/external-link-types">External-Link Types</a>.
            </div>
        <?php endif; ?>

        <!-- Tunes list -->
        <div class="card-admin p-3 mb-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h2 class="h6 mb-0"><i aria-hidden="true" class="bi bi-list-ul me-2"></i>Existing tunes</h2>
                <button type="button" class="btn btn-outline-warning btn-sm" data-bs-toggle="modal" data-bs-target="#tuneMergeModal">
                    <i aria-hidden="true" class="bi bi-shuffle me-1"></i>Merge tunes
                </button>
            </div>
            <table class="table table-sm align-middle cp-sortable mb-0 admin-table-responsive">
                <thead>
                    <tr class="text-muted small">
                        <th scope="col" data-col-priority="primary"   data-sort-key="name"    data-sort-type="text">Name</th>
                        <th scope="col" data-col-priority="secondary" data-sort-key="meter"   data-sort-type="text">Meter</th>
                        <th scope="col" data-col-priority="primary"   data-sort-key="songs"   data-sort-type="number" class="text-center">Songs</th>
                        <th scope="col" data-col-priority="tertiary"  data-sort-key="aliases" data-sort-type="number" class="text-center">Aliases</th>
                        <th scope="col" data-col-priority="tertiary"  data-sort-key="credits" data-sort-type="number" class="text-center">Credits</th>
                        <th scope="col" data-col-priority="tertiary"  data-sort-key="links"   data-sort-type="number" class="text-center">Links</th>
                        <th scope="col" data-col-priority="primary"   class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                        <?php
                            $tid = (int)$r['Id'];
                            $rowPayload = [
                                'id'                    => $tid,
                                'name'                  => (string)$r['Name'],
                                'slug'                  => (string)$r['Slug'],
                                'subtitle'              => (string)($r['Subtitle'] ?? ''),
                                'disambiguation'        => (string)($r['Disambiguation'] ?? ''),
                                'meter_code'            => (string)($r['MeterCode'] ?? ''),
                                'musicbrainz_work_mbid' => (string)($r['MusicBrainzWorkMBID'] ?? ''),
                                'hymnary_tune_id'       => (string)($r['HymnaryTuneId'] ?? ''),
                                'notes'                 => (string)($r['Notes'] ?? ''),
                                'aliases'               => $tuneAliasesMap[$tid] ?? [],
                                'credits'               => $tuneCreditsMap[$tid] ?? [],
                                'links'                 => $tuneLinksMap[$tid]   ?? [],
                            ];
                            $rowJson = json_encode($rowPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                            $deleteJson = json_encode([
                                'id' => $tid, 'name' => (string)$r['Name'],
                                'songCount' => (int)$r['SongCount'],
                            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        ?>
                        <tr>
                            <td data-col-priority="primary">
                                <a href="/tune/<?= htmlspecialchars((string)$r['Slug']) ?>"
                                   target="_blank" rel="noopener noreferrer"
                                   class="text-decoration-none">
                                    <?= htmlspecialchars((string)$r['Name']) ?>
                                </a>
                                <?php if (!empty($r['MeterCode'])): ?>
                                    <span class="d-md-none ms-2 text-muted small"><code><?= htmlspecialchars((string)$r['MeterCode']) ?></code></span>
                                <?php endif; ?>
                            </td>
                            <td data-col-priority="secondary">
                                <?php if (!empty($r['MeterCode'])): ?>
                                    <code><?= htmlspecialchars((string)$r['MeterCode']) ?></code>
                                <?php else: ?>
                                    <span class="text-muted small">—</span>
                                <?php endif; ?>
                            </td>
                            <td data-col-priority="primary" class="text-center"><?= (int)$r['SongCount'] ?></td>
                            <td data-col-priority="tertiary" class="text-center"><?= (int)$r['AliasCount'] ?></td>
                            <td data-col-priority="tertiary" class="text-center"><?= (int)$r['CreditCount'] ?></td>
                            <td data-col-priority="tertiary" class="text-center"><?= (int)$r['LinkCount'] ?></td>
                            <td data-col-priority="primary" class="text-end text-nowrap">
                                <button type="button" class="btn btn-sm btn-outline-info"
                                        onclick="openTuneEditModal(<?= htmlspecialchars((string)$rowJson, ENT_QUOTES, 'UTF-8') ?>)"
                                        title="Edit tune + aliases + credits + links">
                                    <i aria-hidden="true" class="bi bi-pencil"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-danger"
                                        onclick="openTuneDeleteModal(<?= htmlspecialchars((string)$deleteJson, ENT_QUOTES, 'UTF-8') ?>)"
                                        title="Delete tune (songs/works keep their free-text TuneName)">
                                    <i aria-hidden="true" class="bi bi-trash"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$rows): ?>
                        <tr><td colspan="7" class="text-muted text-center py-4">No tunes yet. Add one below.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Create -->
        <form method="POST" class="card-admin p-3 mb-4">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="create">
            <h2 class="h6 mb-3"><i aria-hidden="true" class="bi bi-plus-circle me-2"></i>Add a tune</h2>
            <div class="row g-2">
                <div class="col-sm-5">
                    <label class="form-label small" for="create-tune-name">Name</label>
                    <input type="text" name="name" id="create-tune-name"
                           class="form-control form-control-sm"
                           maxlength="120" required
                           placeholder="e.g. HYFRYDOL">
                </div>
                <div class="col-sm-3">
                    <?= ihymns_slug_advanced_field([
                        'value'       => '',
                        'maxlength'   => 140,
                        'pattern'     => '[a-z0-9-]+',
                        'placeholder' => 'hyfrydol',
                        'small'       => true,
                    ]) ?>
                </div>
                <div class="col-sm-4">
                    <label class="form-label small" for="create-tune-meter">Meter <small class="text-muted">(optional)</small></label>
                    <input type="text" name="meter_code" id="create-tune-meter"
                           class="form-control form-control-sm"
                           maxlength="60"
                           placeholder="87.87 D">
                    <div class="form-text small">As printed — CM, 87.87 D, 86.86…</div>
                </div>
            </div>
            <div class="row g-2 mt-2">
                <div class="col-sm-4">
                    <label class="form-label small" for="create-tune-musicbrainz-work-mbid">MusicBrainz Work MBID <small class="text-muted">(optional)</small></label>
                    <input type="text" name="musicbrainz_work_mbid" id="create-tune-musicbrainz-work-mbid"
                           class="form-control form-control-sm"
                           maxlength="50"
                           placeholder="e.g. 0a1b2c3d-...">
                </div>
                <div class="col-sm-4">
                    <label class="form-label small" for="create-tune-hymnary-id">Hymnary.org tune id <small class="text-muted">(optional)</small></label>
                    <input type="text" name="hymnary_tune_id" id="create-tune-hymnary-id"
                           class="form-control form-control-sm"
                           maxlength="64">
                </div>
            </div>
            <?php /* #1748 (rule #9) — Subtitle/Disambiguation exist only after the tune-enrichment card;
                     showing inputs the save path would silently drop on a pre-enrichment install is the
                     silent-no-op class this codebase forbids, so gate them on the same column probe the
                     read/write paths use. */ ?>
            <?php if ($gates['hasTuneEnrichCols']): ?>
            <div class="row g-2 mt-2">
                <div class="col-sm-6">
                    <label class="form-label small" for="create-tune-subtitle">Subtitle <small class="text-muted">(optional)</small></label>
                    <input type="text" name="subtitle" id="create-tune-subtitle"
                           class="form-control form-control-sm"
                           maxlength="255">
                </div>
                <div class="col-sm-6">
                    <label class="form-label small" for="create-tune-disambiguation">Disambiguation <small class="text-muted">(optional)</small></label>
                    <input type="text" name="disambiguation" id="create-tune-disambiguation"
                           class="form-control form-control-sm"
                           maxlength="255"
                           placeholder="Short parenthetical distinguishing same-named tunes">
                </div>
            </div>
            <?php endif; ?>
            <div class="row g-2 mt-2">
                <div class="col-12">
                    <label class="form-label small" for="create-tune-notes">Notes <small class="text-muted">(optional)</small></label>
                    <textarea name="notes" id="create-tune-notes" class="form-control form-control-sm" rows="2" maxlength="2000"></textarea>
                </div>
            </div>
            <button type="submit" class="btn btn-amber btn-sm mt-3">
                <i aria-hidden="true" class="bi bi-plus me-1"></i>Create tune
            </button>
            <p class="form-text small mt-2 mb-0">
                Add aliases, credits and external links via the <em>Edit</em> button after creating.
            </p>
        </form>

        <!-- Edit Modal -->
        <div class="modal fade" id="tuneEditModal" tabindex="-1">
            <div class="modal-dialog modal-xl">
                <div class="modal-content" style="background: var(--ih-surface); color: var(--ih-text); border-color: var(--ih-border);">
                    <form method="POST" id="tune-edit-form">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="id" id="edit-tune-id">
                        <div class="modal-header" style="border-color: var(--ih-border);">
                            <h5 class="modal-title">
                                <i aria-hidden="true" class="bi bi-pencil me-2"></i>Edit tune — <span id="edit-tune-name-label"></span>
                            </h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="row g-2 mb-3">
                                <div class="col-md-5">
                                    <label class="form-label" for="edit-tune-name">Name</label>
                                    <input type="text" name="name" id="edit-tune-name"
                                           class="form-control" maxlength="120" required>
                                </div>
                                <div class="col-md-3">
                                    <?= ihymns_slug_advanced_field([
                                        'id'        => 'edit-tune-slug',
                                        'value'     => '',
                                        'maxlength' => 140,
                                        'pattern'   => '[a-z0-9-]+',
                                        'small'     => false,
                                        'help'      => 'Changing this changes <code>/tune/&lt;slug&gt;</code> — old links still resolve via the name/alias fallback.',
                                    ]) ?>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="edit-tune-meter-code">Meter</label>
                                    <input type="text" name="meter_code" id="edit-tune-meter-code"
                                           class="form-control" maxlength="60" placeholder="87.87 D">
                                </div>
                            </div>
                            <div class="row g-2 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label" for="edit-tune-musicbrainz-work-mbid">MusicBrainz Work MBID <small class="text-muted">(opt.)</small></label>
                                    <input type="text" name="musicbrainz_work_mbid" id="edit-tune-musicbrainz-work-mbid"
                                           class="form-control" maxlength="50">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="edit-tune-hymnary-tune-id">Hymnary.org tune id <small class="text-muted">(opt.)</small></label>
                                    <input type="text" name="hymnary_tune_id" id="edit-tune-hymnary-tune-id"
                                           class="form-control" maxlength="64">
                                </div>
                            </div>
                            <?php if ($gates['hasTuneEnrichCols']): /* #1748 — see the create-form note above (rule #9) */ ?>
                            <div class="row g-2 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label" for="edit-tune-subtitle">Subtitle <small class="text-muted">(opt.)</small></label>
                                    <input type="text" name="subtitle" id="edit-tune-subtitle"
                                           class="form-control" maxlength="255">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="edit-tune-disambiguation">Disambiguation <small class="text-muted">(opt.)</small></label>
                                    <input type="text" name="disambiguation" id="edit-tune-disambiguation"
                                           class="form-control" maxlength="255">
                                </div>
                            </div>
                            <?php endif; ?>
                            <div class="row g-2 mb-3">
                                <div class="col-12">
                                    <label class="form-label" for="edit-tune-notes">Notes <small class="text-muted">(opt.)</small></label>
                                    <textarea name="notes" id="edit-tune-notes" class="form-control" rows="2" maxlength="2000"></textarea>
                                </div>
                            </div>

                            <?php if ($gates['hasAliases']): ?>
                            <hr>
                            <h6 class="mb-2"><i aria-hidden="true" class="bi bi-tags me-2"></i>Aliases</h6>
                            <p class="form-text small mt-0 mb-2">
                                Alternate spellings this tune is known by — each one resolves
                                <code>/tune/&lt;alias-slug&gt;</code> to this same tune.
                            </p>
                            <div id="edit-tune-aliases-rows" class="vstack gap-2 mb-2">
                                <!-- populated by openTuneEditModal -->
                            </div>
                            <button type="button" class="btn btn-outline-info btn-sm mb-3" id="edit-tune-alias-add-btn">
                                <i aria-hidden="true" class="bi bi-plus-lg me-1"></i>Add alias
                            </button>
                            <?php endif; ?>

                            <?php if ($gates['hasCredits']): ?>
                            <hr>
                            <h6 class="mb-2"><i aria-hidden="true" class="bi bi-pen me-2"></i>Credits</h6>
                            <p class="form-text small mt-0 mb-2">
                                Composer / arranger / harmoniser / source, in curator-chosen display order.
                            </p>
                            <div id="edit-tune-credits-rows" class="vstack gap-2 mb-2">
                                <!-- populated by openTuneEditModal -->
                            </div>
                            <button type="button" class="btn btn-outline-info btn-sm mb-3" id="edit-tune-credit-add-btn">
                                <i aria-hidden="true" class="bi bi-plus-lg me-1"></i>Add credit
                            </button>
                            <?php endif; ?>

                            <?php if ($gates['hasLinks']): ?>
                            <hr>
                            <?php
                                $containerId    = 'edit-tune-ext-links-rows';
                                $addBtnId       = 'edit-tune-ext-link-add-btn';
                                $heading        = 'External links';
                                $helpText       = 'Provider auto-detects from the URL — paste e.g. a Hymnary.org link and the dropdown selects it automatically.';
                                $useCardHeading = true;
                                require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'external-links-section.php';
                            ?>
                            <?php endif; ?>
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
        <div class="modal fade" id="tuneDeleteModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content" style="background: var(--ih-surface); color: var(--ih-text); border-color: var(--ih-border);">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" id="delete-tune-id">
                        <div class="modal-header" style="border-color: var(--ih-border);">
                            <h5 class="modal-title">
                                <i aria-hidden="true" class="bi bi-trash me-2"></i>Delete tune — <span id="delete-tune-name-label"></span>
                            </h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <p class="text-warning mb-2" id="delete-tune-usage-label"></p>
                            <p class="text-muted small">
                                Aliases, credits and external links cascade away with the tune.
                                Songs and works that cited it keep their free-text tune name and simply
                                revert to the un-curated heuristic state — nothing about the song itself changes.
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

        <!-- Merge Modal (tags.php:216/:630 shape) -->
        <div class="modal fade" id="tuneMergeModal" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content" style="background: var(--ih-surface); color: var(--ih-text); border-color: var(--ih-border);">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                        <input type="hidden" name="action" value="merge">
                        <div class="modal-header" style="border-color: var(--ih-border);">
                            <h5 class="modal-title">Merge tunes</h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <p class="small text-secondary">
                                Every song and work citing <strong>Source</strong> re-points to
                                <strong>Target</strong> (same statement updates the free-text tune-name
                                mirror too); Source's aliases, credits and external links move to Target;
                                Source's own name becomes a new alias of Target so old
                                <code>/tune/&lt;source-slug&gt;</code> links keep resolving. Source is
                                then deleted. The merge is transactional — if anything fails, nothing
                                changes.
                            </p>
                            <div class="mb-3">
                                <label class="form-label" for="tune-mg-source">Source tune (merged away)</label>
                                <select class="form-select" id="tune-mg-source" name="source_id" required>
                                    <option value="">— pick a source —</option>
                                    <?php foreach ($rows as $r): ?>
                                        <option value="<?= (int)$r['Id'] ?>"><?= htmlspecialchars((string)$r['Name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="tune-mg-target">Target tune (kept)</label>
                                <select class="form-select" id="tune-mg-target" name="target_id" required>
                                    <option value="">— pick a target —</option>
                                    <?php foreach ($rows as $r): ?>
                                        <option value="<?= (int)$r['Id'] ?>"><?= htmlspecialchars((string)$r['Name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="alert alert-warning small mb-0">
                                <i aria-hidden="true" class="bi bi-exclamation-triangle me-1"></i>
                                Merging is irreversible. Make sure the Source genuinely duplicates the Target.
                            </div>
                        </div>
                        <div class="modal-footer" style="border-color: var(--ih-border);">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-warning">Merge</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <script>
        /* Seeded link-type registry for the tune-ext-links row builder. */
        window._iHymnsLinkTypes = <?= json_encode($linkTypesForTune, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        /* #1748 — the four-role vocab, PHP-rendered from IHYMNS_TUNE_CREDIT_ROLES
           (never a JS literal list — rule #35/#20) for the credit-row
           <select> the inline script below builds. */
        window._iHymnsTuneCreditRoles = <?= json_encode(IHYMNS_TUNE_CREDIT_ROLES, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        window._iHymnsTuneMusicianSearchAvailable = <?= $gates['hasMusicians'] ? 'true' : 'false' ?>;
        </script>
        <?php endif; /* hasSchema */ ?>
    </div>

    <script>
    /* The inline page script runs BEFORE admin-footer.php loads Bootstrap
       JS — see works.php's identical doc-comment (#974 follow-up) for why
       this is wrapped in DOMContentLoaded with lazy modal instantiation.
       Admin pages are full documents, not SPA fragments, so this
       DOMContentLoaded-guarded inline <script> is the CORRECT pattern here
       (rule #30 governs shared-cache page FRAGMENTS under includes/pages/*
       and includes/partials/*, not /manage/* admin pages, which always
       ship a per-request nonce-free document the browser executes
       normally). */
    document.addEventListener('DOMContentLoaded', function () {
        const modalEl  = document.getElementById('tuneEditModal');
        const delModal = document.getElementById('tuneDeleteModal');
        if (!modalEl) return;
        let editModal = null;
        let deleteModal = null;
        function ensureModal(el, slot) {
            if (slot === 'edit'   && editModal)   return editModal;
            if (slot === 'delete' && deleteModal) return deleteModal;
            if (typeof bootstrap === 'undefined' || !bootstrap.Modal) {
                console.error('[tunes] Bootstrap JS not loaded yet; modal cannot open.');
                return null;
            }
            const inst = bootstrap.Modal.getOrCreateInstance(el);
            if (slot === 'edit')   editModal   = inst;
            if (slot === 'delete') deleteModal = inst;
            return inst;
        }

        function escapeHtml(s) {
            return String(s)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }

        /* === Aliases sub-panel — simple repeated-row clone, no typeahead. === */
        const aliasRowsEl = document.getElementById('edit-tune-aliases-rows');
        const aliasAddBtn = document.getElementById('edit-tune-alias-add-btn');
        function aliasRowHtml(name) {
            const v = escapeHtml(name || '');
            return '<div class="input-group input-group-sm">'
                 + '<input type="text" class="form-control" name="alias_names[]" maxlength="120" '
                 + 'value="' + v + '" aria-label="Alias name" placeholder="Alternate spelling">'
                 + '<button type="button" class="btn btn-outline-danger" data-action="remove-alias" '
                 + 'aria-label="Remove this alias"><i class="bi bi-x-lg" aria-hidden="true"></i></button>'
                 + '</div>';
        }
        function rebindAliasRemove() {
            if (!aliasRowsEl) return;
            aliasRowsEl.querySelectorAll('[data-action="remove-alias"]').forEach((btn) => {
                btn.onclick = () => btn.closest('.input-group')?.remove();
            });
        }
        aliasAddBtn?.addEventListener('click', () => {
            if (!aliasRowsEl) return;
            aliasRowsEl.insertAdjacentHTML('beforeend', aliasRowHtml(''));
            rebindAliasRemove();
        });

        /* === Credits sub-panel — role <select> (from window._iHymnsTuneCreditRoles,
           PHP-rendered, never a JS literal list) + name input + a musician
           typeahead per row (window.iHymnsComboboxA11y against
           ?action=musician_search, the works.php:1432-1552 combobox shape,
           narrowed to a single shared suggestion box that follows
           whichever row's input currently has focus). === */
        const creditRowsEl   = document.getElementById('edit-tune-credits-rows');
        const creditAddBtn   = document.getElementById('edit-tune-credit-add-btn');
        const roleOptions    = window._iHymnsTuneCreditRoles || {};
        const musicianSearch = !!window._iHymnsTuneMusicianSearchAvailable;

        function roleSelectHtml(selected) {
            let html = '';
            for (const key in roleOptions) {
                if (!Object.prototype.hasOwnProperty.call(roleOptions, key)) continue;
                const sel = key === selected ? ' selected' : '';
                html += '<option value="' + escapeHtml(key) + '"' + sel + '>' + escapeHtml(roleOptions[key]) + '</option>';
            }
            return html;
        }
        function creditRowHtml(c) {
            c = c || {};
            const role = c.role || Object.keys(roleOptions)[0] || '';
            const name = c.name || '';
            const mid  = c.musicianId ? String(c.musicianId) : '';
            let html = '<div class="row g-2 align-items-center credit-row">'
                 + '<div class="col-md-3">'
                 + '<select class="form-select form-select-sm" name="credit_roles[]" aria-label="Credit role">'
                 + roleSelectHtml(role)
                 + '</select></div>'
                 + '<div class="col-md-4">'
                 + '<input type="text" class="form-control form-control-sm" name="credit_names[]" '
                 + 'maxlength="255" value="' + escapeHtml(name) + '" aria-label="Credited name" placeholder="Name">'
                 + '</div>';
            if (musicianSearch) {
                html += '<div class="col-md-4 position-relative">'
                      + '<input type="text" class="form-control form-control-sm credit-musician-search" '
                      + 'autocomplete="off" placeholder="Link to a musician (optional)" '
                      + 'aria-label="Search for a musician to link this credit to">'
                      + '<input type="hidden" name="credit_musician_ids[]" class="credit-musician-id" value="' + escapeHtml(mid) + '">'
                      + '<div class="list-group small credit-musician-suggestions" style="display:none; position:absolute; z-index:20; max-height:220px; overflow-y:auto; width:100%;"></div>'
                      + '</div>';
            } else {
                html += '<input type="hidden" name="credit_musician_ids[]" value="">';
            }
            html += '<div class="col-md-1 text-end">'
                 + '<button type="button" class="btn btn-sm btn-outline-danger" data-action="remove-credit" '
                 + 'aria-label="Remove this credit"><i class="bi bi-x-lg" aria-hidden="true"></i></button>'
                 + '</div></div>';
            return html;
        }
        function rebindCreditRemove() {
            if (!creditRowsEl) return;
            creditRowsEl.querySelectorAll('[data-action="remove-credit"]').forEach((btn) => {
                btn.onclick = () => btn.closest('.credit-row')?.remove();
            });
        }

        /* One shared suggestion box + debounce, driven by whichever
           .credit-musician-search input last received focus/input — the
           works.php song-search shape (runSongSearch/renderSuggestionRows),
           narrowed to a single instance reused across N repeated rows. */
        let musicianSearchAbort = null;
        let musicianItems = [];
        let musicianActiveIndex = -1;
        let musicianActiveInput = null;
        let musicianActiveHidden = null;
        let musicianActiveBox = null;
        async function runMusicianSearch(q) {
            try {
                if (musicianSearchAbort) musicianSearchAbort.abort();
                musicianSearchAbort = new AbortController();
                const res = await fetch('/manage/tunes?action=musician_search&q=' + encodeURIComponent(q),
                    { signal: musicianSearchAbort.signal });
                if (!res.ok) return;
                const data = await res.json();
                musicianItems = (data && data.suggestions) || [];
                musicianActiveIndex = musicianItems.length ? 0 : -1;
                renderMusicianSuggestions();
            } catch (e) { /* aborted */ }
        }
        function musicianOptionEls() {
            return musicianActiveBox ? Array.from(musicianActiveBox.querySelectorAll('a[data-payload]')) : [];
        }
        function pickMusician(payload) {
            if (musicianActiveInput) musicianActiveInput.value = payload.name || '';
            if (musicianActiveHidden) musicianActiveHidden.value = payload.id ? String(payload.id) : '';
            if (musicianActiveBox) { musicianActiveBox.style.display = 'none'; musicianActiveBox.innerHTML = ''; }
        }
        function renderMusicianSuggestions() {
            if (!musicianActiveBox) return;
            const items = musicianItems;
            if (!items.length) {
                musicianActiveBox.style.display = 'none';
                musicianActiveBox.innerHTML = '';
                return;
            }
            musicianActiveBox.style.display = '';
            musicianActiveBox.innerHTML = items.map((it) =>
                '<a href="#" class="list-group-item list-group-item-action small" data-payload=\''
                + escapeHtml(JSON.stringify({ id: it.id, name: it.name })) + '\'>'
                + escapeHtml(it.name) + '</a>'
            ).join('');
            const optionEls = musicianOptionEls();
            optionEls.forEach((a, i) => {
                a.classList.toggle('active', i === musicianActiveIndex);
                a.onclick = (ev) => {
                    ev.preventDefault();
                    let payload;
                    try { payload = JSON.parse(a.getAttribute('data-payload')); } catch (_e) { return; }
                    pickMusician(payload);
                };
            });
            if (window.iHymnsComboboxA11y && musicianActiveInput) {
                window.iHymnsComboboxA11y.applyComboboxAria({
                    input: musicianActiveInput, panel: musicianActiveBox, items: optionEls,
                    activeIndex: musicianActiveIndex, idPrefix: 'tune-credit-musician-suggestions',
                });
            }
        }
        if (musicianSearch && creditRowsEl) {
            let t = null;
            creditRowsEl.addEventListener('focusin', (ev) => {
                const input = ev.target.closest('.credit-musician-search');
                if (!input) return;
                musicianActiveInput  = input;
                musicianActiveHidden = input.closest('.credit-row')?.querySelector('.credit-musician-id') || null;
                musicianActiveBox    = input.closest('.credit-row')?.querySelector('.credit-musician-suggestions') || null;
            });
            creditRowsEl.addEventListener('input', (ev) => {
                const input = ev.target.closest('.credit-musician-search');
                if (!input) return;
                if (musicianActiveHidden) musicianActiveHidden.value = '';
                clearTimeout(t);
                const q = input.value.trim();
                if (q === '') { if (musicianActiveBox) { musicianActiveBox.style.display = 'none'; } return; }
                t = setTimeout(() => runMusicianSearch(q), 180);
            });
            creditRowsEl.addEventListener('keydown', (ev) => {
                const input = ev.target.closest('.credit-musician-search');
                if (!input || !window.iHymnsComboboxA11y) return;
                window.iHymnsComboboxA11y.handleComboboxKeydown(ev, {
                    isOpen: () => musicianActiveBox && musicianActiveBox.style.display !== 'none',
                    getItems: musicianOptionEls,
                    getActiveIndex: () => musicianActiveIndex,
                    setActiveIndex: (i) => { musicianActiveIndex = i; },
                    render: renderMusicianSuggestions,
                    onCommit: (i, el) => el.click(),
                    onClose: () => { if (musicianActiveBox) musicianActiveBox.style.display = 'none'; },
                });
            });
            document.addEventListener('click', (ev) => {
                if (musicianActiveBox && !musicianActiveBox.contains(ev.target) && ev.target !== musicianActiveInput) {
                    musicianActiveBox.style.display = 'none';
                }
            });
        }
        creditAddBtn?.addEventListener('click', () => {
            if (!creditRowsEl) return;
            creditRowsEl.insertAdjacentHTML('beforeend', creditRowHtml({}));
            rebindCreditRemove();
        });

        /* External-links editor — the shared module (#833/#841), never
           forked. */
        const linkRowsEl = document.getElementById('edit-tune-ext-links-rows');
        const addLinkBtn = document.getElementById('edit-tune-ext-link-add-btn');
        let tuneLinksEditor = null;
        if (linkRowsEl && window.iHymnsExtLinksEditor) {
            tuneLinksEditor = window.iHymnsExtLinksEditor.mount({
                rowsEl:   linkRowsEl,
                addBtnEl: addLinkBtn,
            });
        }

        /* === Public openers === */
        window.openTuneEditModal = function (row) {
            document.getElementById('edit-tune-id').value = row.id;
            document.getElementById('edit-tune-name').value = row.name || '';
            document.getElementById('edit-tune-name-label').textContent = row.name || '';
            document.getElementById('edit-tune-slug').value = row.slug || '';
            document.getElementById('edit-tune-meter-code').value = row.meter_code || '';
            document.getElementById('edit-tune-musicbrainz-work-mbid').value = row.musicbrainz_work_mbid || '';
            document.getElementById('edit-tune-hymnary-tune-id').value = row.hymnary_tune_id || '';
            /* #1748 — these two inputs are gated out on a pre-enrichment install (their columns
               don't exist yet), so null-guard the populate; the others are base columns, always present. */
            const subtitleEl = document.getElementById('edit-tune-subtitle');
            if (subtitleEl) subtitleEl.value = row.subtitle || '';
            const disambigEl = document.getElementById('edit-tune-disambiguation');
            if (disambigEl) disambigEl.value = row.disambiguation || '';
            document.getElementById('edit-tune-notes').value = row.notes || '';

            if (aliasRowsEl) {
                aliasRowsEl.innerHTML = (row.aliases || []).map((a) => aliasRowHtml(a)).join('');
                rebindAliasRemove();
            }
            if (creditRowsEl) {
                creditRowsEl.innerHTML = (row.credits || []).map((c) => creditRowHtml({
                    role: c.role, name: c.name, musicianId: c.musicianId,
                })).join('');
                rebindCreditRemove();
                if (musicianSearch) {
                    creditRowsEl.querySelectorAll('.credit-row').forEach((rowEl, i) => {
                        const input = rowEl.querySelector('.credit-musician-search');
                        const c = (row.credits || [])[i];
                        if (input && c && c.musicianId) {
                            /* Pre-fill the visible search box with the
                               already-linked musician's name so the field
                               doesn't read as empty just because nothing
                               was re-typed this edit. */
                            input.value = c.musicianName || '';
                        }
                    });
                }
            }
            if (tuneLinksEditor) {
                tuneLinksEditor.setRows((row.links || []).map((l) => ({
                    typeId: l.typeId, url: l.url, note: l.note, verified: l.verified,
                })));
            }

            const inst = ensureModal(modalEl, 'edit');
            if (inst) inst.show();
        };
        window.openTuneDeleteModal = function (row) {
            document.getElementById('delete-tune-id').value = row.id;
            document.getElementById('delete-tune-name-label').textContent = row.name || '';
            const usageEl = document.getElementById('delete-tune-usage-label');
            if (usageEl) {
                usageEl.textContent = row.songCount > 0
                    ? row.songCount + ' song(s) currently cite this tune.'
                    : 'No songs currently cite this tune.';
            }
            const inst = ensureModal(delModal, 'delete');
            if (inst) inst.show();
        };
    });
    </script>

    <!-- Sortable table headers (#1786 sweep — tagged cp-sortable but never
         booted; every header click was a silent no-op until now). -->
    <script type="module">
        import { bootSortableTables } from '/js/modules/admin-table-sort.js?v=<?= filemtime(dirname(__DIR__) . '/js/modules/admin-table-sort.js') ?>';
        bootSortableTables();
    </script>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>
</body>
</html>
