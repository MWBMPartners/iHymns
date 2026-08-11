<?php

declare(strict_types=1);

/**
 * iHymns — Admin: Internet Archive OCR reconcile audit (#94 Phase 1) — edit_songs
 *
 * ELI5: pick a songbook and an archive.org scan, click "Run reconcile", and
 * this page shows you a report — which hymns in the scan look like an exact
 * or close match to something already in iHymns, which ones might be
 * MISSING from the songbook (the "gap" list, the whole point of this
 * tool), and which songs in the songbook were never found anywhere in the
 * scan (maybe a bad OCR, maybe missing pages). It never adds, edits or
 * deletes a single song — Phase 2 (curator-approved import) is a
 * completely separate, not-yet-built follow-up.
 *
 * DETAIL:
 * -------
 * Copies the skeleton of `manage/intapps-status.php` (the house template
 * for a diagnostic admin page): `includes/auth.php`, `isAuthenticated()`
 * redirect, entitlement 403, `$activePage`, no hardcoded `data-bs-theme`
 * (rule #16 — `head-libs.php` runs `admin-theme-init.php`), `head-libs.php`
 * in `<head>`, `admin-nav.php` at the top of `<body>`, `admin-footer.php`
 * at the bottom.
 *
 * Gate: `edit_songs` (#94 Phase 1 plan D2, resolved by the owner
 * 2026-08-11) — curators, the people who will actually work the gap list,
 * can run audits themselves; no destructive action exists in Phase 1 at
 * all, so there is nothing to gate more tightly. The `admin-links.php` nav
 * row for `ia-reconcile` uses the SAME entitlement (rule #1587;
 * `tests/php/test-admin-gate-parity.php` derives + enforces the pairing).
 *
 * ZERO WRITES TO SONG CONTENT. The only writes on this whole page are the
 * two calls into `iaRecPersistRun()` (audit bookkeeping tables only) —
 * mechanically proven by `tests/php/test-ia-reconcile-guards.php`'s
 * read-only scan, which bans any write statement naming a song-content
 * table from this file.
 *
 * Every one of the two new tables is accessed ONLY through
 * `includes/ia_client.php` / `includes/ia_reconcile.php`'s own
 * INFORMATION_SCHEMA-gated + try/catch helpers — this page itself never
 * queries `tblIaFetchCache` / `tblIaImportCandidates` directly, so an
 * un-migrated install degrades to "fetch direct, not persisted" instead of
 * a white-screen (the #1228 lesson, rule #9).
 *
 * @see .claude/ia-ocr-94-plan.md §5  the full design this page implements
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_mysql.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'ia_client.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'ia_reconcile.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'identifier_normalize.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'songbook_display.php';
/* Reuse, never re-fork (rule #22) — required directly here too, even though
   ia_reconcile.php already pulls them in, per the CI guard's explicit
   "both files require the two shared modules" assertion. */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'title_normalize.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'song_similarity.php';

if (!isAuthenticated()) {
    header('Location: /manage/login');
    exit;
}
$currentUser = getCurrentUser();
if (!$currentUser || !userHasEntitlement('edit_songs', $currentUser['role'] ?? null)) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><body><h1>403 — edit_songs required</h1></body></html>';
    exit;
}
$activePage = 'ia-reconcile';

$db   = getDbMysqli();
$csrf = csrfToken();

/* ---------------------------------------------------------------------
 * Songbook picker source — Id/Abbreviation/DisplayAbbr/Name/InternetArchiveUrl
 * for every songbook, oldest-first display order (matches the rest of
 * /manage's songbook pickers).
 * ------------------------------------------------------------------- */
$songbooks = [];
try {
    $res = $db->query(
        'SELECT Id, Abbreviation, DisplayAbbr, Name, InternetArchiveUrl
           FROM tblSongbooks
          ORDER BY DisplayOrder, Name'
        /* @disabled-visible: admin surface (#1765) — disabled songbooks stay
           fully visible/editable in /manage (owner decision); a curator
           auditing a disabled songbook against an archive.org scan is
           exactly as legitimate as auditing any other book. */
    );
    while ($res && ($row = $res->fetch_assoc())) {
        $songbooks[] = $row;
    }
    if ($res) { $res->close(); }
} catch (\Throwable $e) {
    $songbooks = [];
}

/* ---------------------------------------------------------------------
 * Result state — populated either by a POST run or by a GET revisit of a
 * previously persisted (identifier, songbook) pair.
 * ------------------------------------------------------------------- */
$errorMessage   = null;
$amberMessage   = null;
$reportResult   = null;   /* iaRecScoreCandidates() shape, or null */
$reportMeta     = null;   /* ['identifier'=>string,'songbookAbbr'=>string,'fileName'=>?string,'fetchedAt'=>?string,'persisted'=>bool] */

/**
 * ELI5: load a previously persisted run's candidates + verdicts back out of
 * tblIaImportCandidates for GET-revisit rendering, WITHOUT re-fetching or
 * re-scoring anything.
 * WHY: table-existence-gated + try/catch, matching the D1 discipline every
 * other tblIaFetchCache/tblIaImportCandidates access uses — an un-migrated
 * install simply has nothing to show here (returns null), never a throw.
 */
function _iaReconcilePageLoadPersisted(\mysqli $db, string $identifier, string $songbookAbbr): ?array
{
    try {
        $probe = $db->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblIaImportCandidates' LIMIT 1"
        );
        $probe->execute();
        $exists = $probe->get_result()->fetch_row() !== null;
        $probe->close();
        if (!$exists) { return null; }

        $stmt = $db->prepare(
            "SELECT SegmentIndex AS `index`, HeadingNumber AS headingNumber, PageGuess AS pageGuess,
                    RawTitle AS rawTitle, FirstLine AS firstLine, BodyExcerpt AS bodyExcerpt,
                    LineStart AS lineStart, LineEnd AS lineEnd,
                    BestSongId AS bestSongId, BestScore AS bestScore, Verdict AS verdict
               FROM tblIaImportCandidates
              WHERE Identifier = ? AND SongbookAbbr = ?
              ORDER BY SegmentIndex"
        );
        $stmt->bind_param('ss', $identifier, $songbookAbbr);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $row['index'] = (int)$row['index'];
            $row['lineStart'] = (int)$row['lineStart'];
            $row['lineEnd'] = (int)$row['lineEnd'];
            $row['bestScore'] = $row['bestScore'] !== null ? (float)$row['bestScore'] : null;
            $rows[] = $row;
        }
        $stmt->close();
        if (!$rows) { return null; }

        $counts = ['exact' => 0, 'strong' => 0, 'review' => 0, 'gap' => 0, 'unscorable' => 0];
        foreach ($rows as $r) { if (isset($counts[$r['verdict']])) { $counts[$r['verdict']]++; } }

        return [
            'candidates'    => $rows,
            'notFoundSongs' => [], /* not persisted — only recomputed on a fresh run */
            'summary'       => ['counts' => $counts, 'coveragePct' => null],
        ];
    } catch (\Throwable $e) {
        return null;
    }
}

$method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'POST' && (string)($_POST['action'] ?? '') === 'run') {
    if (!validateCsrf((string)($_POST['csrf_token'] ?? ''))) {
        $errorMessage = 'CSRF token invalid — refresh the page and try again.';
    } else {
        $identifierRaw = (string)($_POST['identifier'] ?? '');
        $songbookAbbr  = trim((string)($_POST['songbook_abbr'] ?? ''));
        $forceRefresh  = (string)($_POST['force_refresh'] ?? '') === '1';

        $identifier = ihymns_canonical_ia_identifier($identifierRaw);

        /* Validate songbook existence via a bound lookup — never trust the
           posted abbreviation blindly (rule #5). */
        $songbookValid = false;
        foreach ($songbooks as $sb) {
            if ((string)$sb['Abbreviation'] === $songbookAbbr) { $songbookValid = true; break; }
        }

        if ($identifier === null || $identifier === '') {
            $errorMessage = 'That doesn\'t look like a valid archive.org identifier or URL.';
        } elseif (!$songbookValid) {
            $errorMessage = 'Choose a songbook from the list.';
        } else {
            /* A cold fetch + segment + score of a large book can exceed the
               default 30s on shared hosting; cached re-runs are seconds. */
            @set_time_limit(300);

            $metadata = iaCachedMetadata($db, $identifier, $forceRefresh);
            $fulltext = $metadata !== null ? iaCachedFulltext($db, $identifier, $forceRefresh) : null;

            if ($metadata === null || $fulltext === null) {
                $errorMessage = 'archive.org did not answer for "' . $identifier . '", the item has no OCR '
                    . 'text file (<code>_djvu.txt</code>), or the response exceeded the size cap. '
                    . 'Nothing was changed.';
            } else {
                $candidates = iaRecSegmentOcr($fulltext['text']);
                if (!$candidates) {
                    $errorMessage = 'The OCR text was fetched (' . number_format(strlen($fulltext['text'])) . ' bytes) '
                        . 'but could not be segmented into any hymn-shaped candidates.';
                } else {
                    $songFeatures = iaRecSongFeatures($db, $songbookAbbr);
                    $reportResult = iaRecScoreCandidates($candidates, $songFeatures);

                    $persisted = iaRecPersistRun($db, $identifier, $songbookAbbr, $fulltext['sha256'], $reportResult['candidates']);
                    if (!$persisted) {
                        $amberMessage = 'Results were computed but NOT persisted — run the "IA Reconcile" '
                            . 'migration card on <a href="/manage/setup-database" class="link-light">Database Setup</a> '
                            . 'to keep results across page loads.';
                    }

                    $reportMeta = [
                        'identifier'   => $identifier,
                        'songbookAbbr' => $songbookAbbr,
                        'fileName'     => $fulltext['fileName'],
                        'fetchedAt'    => $fulltext['fetchedAt'],
                        'persisted'    => $persisted,
                    ];

                    if (function_exists('logActivity')) {
                        logActivity('ia_reconcile.run', 'songbook', $songbookAbbr, [
                            'identifier' => $identifier,
                            'candidates' => count($reportResult['candidates']),
                            'gaps'       => $reportResult['summary']['counts']['gap'] ?? 0,
                        ], 'success');
                    }
                }
            }
        }
    }
} elseif ($method === 'GET') {
    $getIdentifier = ihymns_canonical_ia_identifier((string)($_GET['identifier'] ?? ''));
    $getSongbookAbbr = trim((string)($_GET['songbook_abbr'] ?? ''));
    if ($getIdentifier !== null && $getIdentifier !== '' && $getSongbookAbbr !== '') {
        $persistedResult = _iaReconcilePageLoadPersisted($db, $getIdentifier, $getSongbookAbbr);
        if ($persistedResult !== null) {
            $reportResult = $persistedResult;
            $reportMeta = [
                'identifier'   => $getIdentifier,
                'songbookAbbr' => $getSongbookAbbr,
                'fileName'     => null,
                'fetchedAt'    => null,
                'persisted'    => true,
            ];
        }
    }
}

$verdictBadgeClass = [
    'exact'      => 'text-bg-success-subtle',
    'strong'     => 'text-bg-success-subtle',
    'review'     => 'text-bg-warning-subtle',
    'gap'        => 'text-bg-danger-subtle',
    'unscorable' => 'text-bg-secondary-subtle',
];

require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-favicon.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scan Import — iHymns Admin</title>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-libs.php'; ?>
</head>
<body>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-nav.php'; ?>

    <div class="container-admin py-4">

        <h1 class="h4 mb-3"><i class="bi bi-archive me-2"></i>Scan Import</h1>
        <p class="text-secondary small mb-4">
            Compare a scanned songbook from the Internet Archive (archive.org) against one of your
            songbooks in iHymns. Pick a songbook and a scan, then run the check to see which hymns in
            the scan already match songs you have, which look like they are missing, and which of your
            songs weren't found in the scan. This only shows you a report — it never adds, changes or
            deletes any song. The main thing to look for is the <strong>Gap</strong> list: hymns that
            appear in the scan but aren't in iHymns yet.
        </p>
        <p class="text-secondary small mb-4">
            Known limitations, honestly stated: prose front-matter and two-column layouts segment
            poorly and skew toward Gap/Unscorable; non-Latin scripts score Unscorable rather than a
            fabricated Gap; responsive-verse/psalter books without numbers or ALL-CAPS titles may
            under-segment. Treat a heavy Gap/Unscorable skew as a signal the SCAN is the problem, not
            the catalogue — the reverse "in songbook, not found in OCR" table below is the tell.
        </p>

        <?php if ($errorMessage !== null): ?>
            <div class="alert alert-danger py-2 mb-4"><?= $errorMessage /* pre-composed, own strings only */ ?></div>
        <?php endif; ?>
        <?php if ($amberMessage !== null): ?>
            <div class="alert alert-warning py-2 mb-4"><?= $amberMessage /* pre-composed, own strings only */ ?></div>
        <?php endif; ?>

        <div class="card bg-dark border-secondary mb-4">
            <div class="card-header"><h2 class="h6 mb-0">Run a reconcile</h2></div>
            <div class="card-body">
                <form method="post" class="row gy-3 align-items-end" id="ia-reconcile-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="run">

                    <div class="col-md-4">
                        <label class="form-label" for="ia-songbook-select">Songbook</label>
                        <select class="form-select" id="ia-songbook-select" name="songbook_abbr" required>
                            <option value="">Choose a songbook…</option>
                            <?php foreach ($songbooks as $sb): ?>
                                <?php
                                    $label = ihymns_songbook_abbr_label((string)$sb['Abbreviation'], $sb['DisplayAbbr'] ?? null);
                                    $iaPrefill = ihymns_canonical_ia_identifier((string)($sb['InternetArchiveUrl'] ?? '')) ?? '';
                                ?>
                                <option value="<?= htmlspecialchars((string)$sb['Abbreviation'], ENT_QUOTES, 'UTF-8') ?>"
                                        data-ia-identifier="<?= htmlspecialchars($iaPrefill, ENT_QUOTES, 'UTF-8') ?>"
                                        <?= (($reportMeta['songbookAbbr'] ?? null) === (string)$sb['Abbreviation']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?> — <?= htmlspecialchars((string)$sb['Name'], ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-5">
                        <label class="form-label" for="ia-identifier-input">archive.org identifier or URL</label>
                        <input type="text" class="form-control" id="ia-identifier-input" name="identifier"
                               placeholder="e.g. commonpraise00unse or a details/download URL"
                               value="<?= htmlspecialchars((string)($reportMeta['identifier'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required>
                    </div>

                    <div class="col-md-2">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" value="1" id="ia-force-refresh" name="force_refresh">
                            <label class="form-check-label small" for="ia-force-refresh">
                                Force re-fetch from archive.org
                            </label>
                        </div>
                    </div>

                    <div class="col-md-1">
                        <button type="submit" class="btn btn-primary w-100">Run</button>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($reportResult !== null): ?>
            <?php
                $counts = $reportResult['summary']['counts'] ?? [];
                $totalCandidates = array_sum($counts);
                $coveragePct = $reportResult['summary']['coveragePct'] ?? null;
            ?>
            <div class="d-flex flex-wrap gap-3 mb-4">
                <?php foreach ([
                    ['Candidates', $totalCandidates, 'text-bg-dark'],
                    ['Exact', $counts['exact'] ?? 0, 'text-bg-success-subtle'],
                    ['Strong', $counts['strong'] ?? 0, 'text-bg-success-subtle'],
                    ['Review', $counts['review'] ?? 0, 'text-bg-warning-subtle'],
                    ['Gaps', $counts['gap'] ?? 0, 'text-bg-danger-subtle'],
                    ['Unscorable', $counts['unscorable'] ?? 0, 'text-bg-secondary-subtle'],
                ] as [$label, $value, $cls]): ?>
                    <div class="card bg-dark border-secondary" style="min-width:8rem;">
                        <div class="card-body py-2 px-3 text-center">
                            <div class="small text-secondary"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="h5 mb-0"><span class="badge <?= $cls ?>"><?= (int)$value ?></span></div>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if ($coveragePct !== null): ?>
                    <div class="card bg-dark border-secondary" style="min-width:10rem;">
                        <div class="card-body py-2 px-3 text-center">
                            <div class="small text-secondary">Songbook coverage</div>
                            <div class="h5 mb-0"><?= htmlspecialchars(number_format((float)$coveragePct, 1), ENT_QUOTES, 'UTF-8') ?>%</div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <div class="table-responsive mb-4">
                <table class="table table-sm table-hover align-middle mb-0 cp-sortable admin-table-responsive"
                       id="ia-reconcile-table" data-default-sort-key="verdict" data-default-sort-dir="asc">
                    <thead>
                        <tr>
                            <th data-col-priority="secondary" data-sort-key="num" data-sort-type="text">#</th>
                            <th data-col-priority="primary" data-sort-key="title" data-sort-type="text">OCR candidate title</th>
                            <th data-col-priority="tertiary" data-sort-key="first" data-sort-type="text">First line</th>
                            <th data-col-priority="primary" data-sort-key="match" data-sort-type="text">Best iHymns match</th>
                            <th data-col-priority="primary" data-sort-key="score" data-sort-type="number">Score</th>
                            <th data-col-priority="primary" data-sort-key="verdict" data-sort-type="text">Verdict</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reportResult['candidates'] as $c): ?>
                            <?php
                                $badgeCls = $verdictBadgeClass[$c['verdict']] ?? 'text-bg-secondary-subtle';
                                $scoreText = $c['bestScore'] !== null ? number_format((float)$c['bestScore'], 3) : '—';
                            ?>
                            <tr>
                                <td data-col-priority="secondary" data-sort-value="<?= htmlspecialchars((string)($c['headingNumber'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars((string)($c['headingNumber'] ?? '—'), ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td data-col-priority="primary"><?= htmlspecialchars((string)$c['rawTitle'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td data-col-priority="tertiary" class="small text-secondary"><?= htmlspecialchars((string)$c['firstLine'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td data-col-priority="primary">
                                    <?php if (!empty($c['bestSongId'])): ?>
                                        <a href="/song/<?= htmlspecialchars((string)$c['bestSongId'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string)$c['bestSongId'], ENT_QUOTES, 'UTF-8') ?></a>
                                        <a class="small ms-1" href="/manage/editor/?song=<?= htmlspecialchars((string)$c['bestSongId'], ENT_QUOTES, 'UTF-8') ?>">(edit)</a>
                                    <?php else: ?>
                                        <span class="text-secondary">—</span>
                                    <?php endif; ?>
                                </td>
                                <td data-col-priority="primary" data-sort-value="<?= htmlspecialchars((string)($c['bestScore'] ?? 0), ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars($scoreText, ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td data-col-priority="primary">
                                    <span class="badge <?= $badgeCls ?>"><?= htmlspecialchars((string)$c['verdict'], ENT_QUOTES, 'UTF-8') ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if (!empty($reportResult['notFoundSongs'])): ?>
                <div class="card bg-dark border-secondary mb-4">
                    <div class="card-header"><h2 class="h6 mb-0">In songbook, not found in OCR</h2></div>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead><tr><th>SongId</th><th>Number</th><th>Title</th></tr></thead>
                            <tbody>
                                <?php foreach ($reportResult['notFoundSongs'] as $s): ?>
                                    <tr>
                                        <td><a href="/song/<?= htmlspecialchars((string)$s['songId'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string)$s['songId'], ENT_QUOTES, 'UTF-8') ?></a></td>
                                        <td><?= $s['number'] !== null ? (int)$s['number'] : '—' ?></td>
                                        <td><?= htmlspecialchars((string)$s['title'], ENT_QUOTES, 'UTF-8') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>

    </div>

    <!-- Songbook -> IA identifier prefill (#94 Phase 1). Admin pages are NOT
         under the public nonce CSP (rule #30 governs SPA fragments, not
         /manage) — the inline module convention here matches
         manage/songbooks.php's own <script type="module"> blocks. -->
    <script type="module">
        const select = document.getElementById('ia-songbook-select');
        const identifierInput = document.getElementById('ia-identifier-input');
        if (select && identifierInput) {
            select.addEventListener('change', () => {
                const opt = select.options[select.selectedIndex];
                const prefill = opt ? opt.getAttribute('data-ia-identifier') : '';
                if (prefill && identifierInput.value.trim() === '') {
                    identifierInput.value = prefill;
                }
            });
        }
    </script>

    <!-- Sortable table headers (#644/#1786). -->
    <script type="module">
        import { bootSortableTables } from '/js/modules/admin-table-sort.js?v=<?= filemtime(dirname(__DIR__) . '/js/modules/admin-table-sort.js') ?>';
        bootSortableTables();
    </script>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>
</body>
</html>
