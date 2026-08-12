<?php

declare(strict_types=1);

/**
 * iHymns — Missing Song Numbers Report (#285)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * Admin page that surfaces gaps in every songbook's numbering. Per
 * songbook, shows:
 *   - the highest number present
 *   - the count of songs present
 *   - the count of gaps
 *   - the list of gaps, collapsed into ranges so a long trailing
 *     gap doesn't flood the page
 *
 * Mirrors the in-editor "Find missing numbers" tool (#285 editor
 * commit) but from a catalogue-wide angle so an admin can audit
 * every book without clicking through each one in the editor.
 *
 * Entitlement: reuses `edit_songs` — the same role that can fix the
 * gaps can see the report.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';

requireAuth();
$currentUser = getCurrentUser();
if (!userHasEntitlement('edit_songs', $currentUser['role'] ?? null)) {
    http_response_code(403);
    exit('Access denied — missing-numbers report requires the edit_songs entitlement.');
}

$activePage = 'missing-numbers';

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_mysql.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'SongData.php';

/* ========================================================================
 * Build the report — one row per songbook with its gap list
 * ======================================================================== */
try {
    /* #1765 Feature 1 — admin surface: a disabled songbook's missing-number
       gaps must stay visible/actionable in /manage/*, so this instance opts
       out of the public visibility filter via SongData::forAdmin(). */
    $songData     = SongData::forAdmin();
    $allSongbooks = $songData->getSongbooks();
    $reports      = [];

    foreach ($allSongbooks as $book) {
        $id   = (string)($book['id']   ?? '');
        $name = (string)($book['name'] ?? $id);
        if ($id === '') continue;

        $result = $songData->getMissingSongNumbers($id);
        $reports[] = [
            'id'                => $id,
            'name'              => $name,
            'max_number'        => (int)($result['maxNumber']     ?? 0),
            'total_existing'    => (int)($result['totalExisting'] ?? 0),
            'missing'           => array_map('intval', $result['missing'] ?? []),
            'missing_count'     => count($result['missing'] ?? []),
            /* #1829 — numbers that look present but whose only song is hidden
               (soft-deleted); surfaced so a curator can restore or purge. */
            'hidden_held'       => array_map('intval', $result['hiddenHeld'] ?? []),
            'hidden_held_count' => (int)($result['hiddenHeldCount'] ?? 0),
        ];
    }
} catch (\Throwable $e) {
    error_log('[missing-numbers] ' . $e->getMessage());
    logActivityError('admin.missing_numbers.load', 'songbook', '', $e);
    $reports  = [];
    $loadError = 'Could not load the missing-numbers report — see server logs.';
}

/* Group consecutive gaps into runs so a big trailing gap renders as
   "#400–#500 · 101 songs missing" rather than 101 badges. */
function groupGapRuns(array $nums): array
{
    if (empty($nums)) return [];
    sort($nums);
    $out = [];
    $run = [$nums[0]];
    for ($i = 1; $i < count($nums); $i++) {
        if ($nums[$i] === end($run) + 1) {
            $run[] = $nums[$i];
        } else {
            $out[] = $run;
            $run   = [$nums[$i]];
        }
    }
    $out[] = $run;
    return $out;
}

$requestedBook = $_GET['songbook'] ?? '';
$grandMissing  = 0;
$grandPresent  = 0;
$grandHidden   = 0;   // #1829 — numbers held only by a hidden (deleted) song
foreach ($reports as $r) {
    $grandMissing += $r['missing_count'];
    $grandPresent += $r['total_existing'];
    $grandHidden  += $r['hidden_held_count'];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Missing Song Numbers — iHymns Admin</title>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-libs.php'; ?>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-favicon.php'; ?>
</head>
<body>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-nav.php'; ?>

    <div class="container-admin py-4">
        <h1 class="h4 mb-1">
            <i class="bi bi-binoculars me-2" aria-hidden="true"></i>
            Missing Song Numbers
        </h1>
        <p class="text-secondary small mb-4">
            Gaps in each songbook's numbering — useful for spotting songs
            that haven't been added yet, or misnumbered rows. The editor
            has a per-songbook view on the Song Editor sidebar; this page
            shows every songbook at a glance.
        </p>

        <?php if (!empty($loadError)): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($loadError) ?></div>
        <?php endif; ?>

        <!-- Summary cards -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-lg-3">
                <div class="card-admin">
                    <div class="text-muted text-uppercase small">Songbooks audited</div>
                    <div class="h4 mb-0"><?= number_format(count($reports)) ?></div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="card-admin">
                    <div class="text-muted text-uppercase small">Total songs present</div>
                    <div class="h4 mb-0"><?= number_format($grandPresent) ?></div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="card-admin">
                    <div class="text-muted text-uppercase small">Total gaps</div>
                    <div class="h4 mb-0 <?= $grandMissing > 0 ? 'text-warning' : 'text-success' ?>">
                        <?= number_format($grandMissing) ?>
                    </div>
                </div>
            </div>
            <!-- #1829 — numbers that look present but are held only by a hidden
                 (deleted) song; a curator should review whether to restore or
                 purge each so its number is genuinely free. -->
            <div class="col-6 col-lg-3">
                <div class="card-admin">
                    <div class="text-muted text-uppercase small">Held by hidden songs</div>
                    <div class="h4 mb-0 <?= $grandHidden > 0 ? 'text-warning-emphasis' : '' ?>">
                        <?= number_format($grandHidden) ?>
                    </div>
                </div>
            </div>
        </div>

        <?php if (empty($reports)): ?>
            <div class="alert alert-info small mb-0">No songbooks in the catalogue yet.</div>
        <?php else: ?>
            <!-- Per-songbook accordion: expanded for the book in ?songbook=,
                 otherwise the first one with gaps. -->
            <?php
            $autoExpand = $requestedBook !== '' ? $requestedBook : '';
            if ($autoExpand === '') {
                foreach ($reports as $r) {
                    if ($r['missing_count'] > 0) { $autoExpand = $r['id']; break; }
                }
            }
            ?>
            <div class="accordion" id="missing-numbers-acc">
                <?php foreach ($reports as $r):
                    $runs    = groupGapRuns($r['missing']);
                    $accId   = 'acc-' . preg_replace('/[^A-Za-z0-9_-]/', '-', $r['id']);
                    $expanded = $r['id'] === $autoExpand;
                ?>
                    <div class="accordion-item">
                        <h2 class="accordion-header" id="h-<?= htmlspecialchars($accId) ?>">
                            <button class="accordion-button<?= $expanded ? '' : ' collapsed' ?>"
                                    type="button"
                                    data-bs-toggle="collapse"
                                    data-bs-target="#b-<?= htmlspecialchars($accId) ?>"
                                    aria-expanded="<?= $expanded ? 'true' : 'false' ?>"
                                    aria-controls="b-<?= htmlspecialchars($accId) ?>">
                                <span class="badge bg-body-secondary me-2" style="min-width: 3.5rem;">
                                    <?= htmlspecialchars($r['id']) ?>
                                </span>
                                <span class="me-auto"><?= htmlspecialchars($r['name']) ?></span>
                                <span class="small text-muted me-3">
                                    <?= number_format($r['total_existing']) ?> present ·
                                    highest #<?= number_format($r['max_number']) ?>
                                </span>
                                <?php if ($r['hidden_held_count'] > 0): ?>
                                    <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle me-2"
                                          title="Numbers held only by a hidden (deleted) song">
                                        <i class="bi bi-eye-slash me-1" aria-hidden="true"></i>
                                        <?= number_format($r['hidden_held_count']) ?> hidden
                                    </span>
                                <?php endif; ?>
                                <span class="badge <?= $r['missing_count'] > 0 ? 'bg-warning text-dark' : 'bg-success' ?>">
                                    <?= $r['missing_count'] > 0
                                        ? number_format($r['missing_count']) . ' missing'
                                        : 'complete' ?>
                                </span>
                            </button>
                        </h2>
                        <div id="b-<?= htmlspecialchars($accId) ?>"
                             class="accordion-collapse collapse<?= $expanded ? ' show' : '' ?>"
                             aria-labelledby="h-<?= htmlspecialchars($accId) ?>"
                             data-bs-parent="#missing-numbers-acc">
                            <div class="accordion-body">
                                <?php if (empty($runs)): ?>
                                    <div class="alert alert-success small mb-0" role="status">
                                        <i class="bi bi-check-circle me-1" aria-hidden="true"></i>
                                        No gaps — every number from 1 to
                                        <?= (int)$r['max_number'] ?> is present.
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-sm align-middle mb-0 cp-sortable admin-table-fixed">
                                            <thead>
                                                <tr>
                                                    <th scope="col" style="width: 200px;" data-sort-key="range" data-sort-type="text">Range</th>
                                                    <th scope="col" style="width: 100px;" class="text-end" data-sort-key="missing" data-sort-type="number">Missing</th>
                                                    <th scope="col">Action</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($runs as $run):
                                                    $first = $run[0];
                                                    $last  = $run[count($run) - 1];
                                                    $label = ($first === $last)
                                                        ? '#' . $first
                                                        : '#' . $first . '–#' . $last;
                                                ?>
                                                    <tr>
                                                        <td class="fw-semibold"><?= htmlspecialchars($label) ?></td>
                                                        <td class="text-end">
                                                            <?= count($run) ?>
                                                            song<?= count($run) === 1 ? '' : 's' ?>
                                                        </td>
                                                        <td>
                                                            <a class="btn btn-sm btn-outline-primary"
                                                               href="/request?songbook=<?= htmlspecialchars(urlencode($r['id'])) ?>&number=<?= (int)$first ?>"
                                                               target="_blank" rel="noopener">
                                                                <i class="bi bi-lightbulb me-1" aria-hidden="true"></i>
                                                                Log request
                                                            </a>
                                                            <a class="btn btn-sm btn-outline-secondary"
                                                               href="/manage/editor/?songbook=<?= htmlspecialchars(urlencode($r['id'])) ?>#number=<?= (int)$first ?>"
                                                               target="_blank" rel="noopener">
                                                                <i class="bi bi-pencil-square me-1" aria-hidden="true"></i>
                                                                Open in editor
                                                            </a>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>

                                <?php /* #1829 — numbers held only by a hidden (deleted) song. Shown
                                         whether or not the book has gaps: a book can be "complete"
                                         yet still have a number quietly occupied by a hidden song. */ ?>
                                <?php if (!empty($r['hidden_held'])): ?>
                                    <div class="rounded border border-warning-subtle bg-warning-subtle text-warning-emphasis p-3 small mt-3 mb-0 d-flex flex-wrap gap-2 justify-content-between align-items-start" role="status">
                                        <div>
                                            <i class="bi bi-eye-slash me-1" aria-hidden="true"></i>
                                            <strong>Held by hidden songs.</strong>
                                            These numbers look present, but the only song on each has
                                            been deleted (hidden), so the number isn't offered as a
                                            gap. Restore the song, or purge it to genuinely free the
                                            number.
                                            <div class="mt-2">
                                                <?php foreach (groupGapRuns($r['hidden_held']) as $hRun):
                                                    $hFirst = $hRun[0];
                                                    $hLast  = $hRun[count($hRun) - 1];
                                                    $hLabel = ($hFirst === $hLast)
                                                        ? '#' . $hFirst
                                                        : '#' . $hFirst . '–#' . $hLast;
                                                ?>
                                                    <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle me-1 mb-1"><?= htmlspecialchars($hLabel) ?></span>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                        <a class="btn btn-sm btn-outline-warning flex-shrink-0"
                                           href="/manage/deleted-songs">
                                            <i class="bi bi-trash me-1" aria-hidden="true"></i>
                                            Review in Deleted Songs
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Sortable table headers (#1786 sweep — tagged cp-sortable but never
         booted; every header click was a silent no-op until now). -->
    <script type="module">
        import { bootSortableTables } from '/js/modules/admin-table-sort.js?v=<?= filemtime(dirname(__DIR__) . '/js/modules/admin-table-sort.js') ?>';
        bootSortableTables();
    </script>

    <!-- Bootstrap JS loaded by admin-footer.php -->
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>
</body>
</html>
