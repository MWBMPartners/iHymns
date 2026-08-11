<?php

declare(strict_types=1);

/**
 * iHymns — Admin: Musician Duplicates Review (#1785 §7)
 * ======================================================================
 *
 * The musician-registry sibling of `/manage/duplicate-songs` (#1215) —
 * ONE dedicated review surface for "are these two registry rows the same
 * person?", scored live on every page load by the C6 scan helper
 * (`includes/musician_duplicates.php`). Two sections:
 *
 *   §1 Same name, different bytes — Bucket A fold-equal groups PLUS
 *      Bucket C alias-match pairs. High confidence: the two spellings
 *      denote one person by construction (identical modulo whitespace/
 *      punctuation/case, or a curator already recorded the alias link).
 *      Rendered as cards (mirrors duplicate-songs.php's cluster cards).
 *   §2 Similar names — Bucket B fuzzy pairs, score-ranked. Rendered as a
 *      sortable `admin-table-responsive` table (rule #13/#14).
 *
 * Every merge is proportionate to danger:
 *   - byte-variant / alias-match: a plain confirm() dialog.
 *   - fuzzy (genuinely different words): a richer confirm() rendering
 *     both sides' payload.
 *   - EITHER kind, if the two sides' recorded lifespans actively
 *     disagree (musicianDuplicatesLifespanConflict()): the server
 *     requires `force=1` AND the client requires typing MERGE — the
 *     musician-registry analogue of #1218's same-official-songbook
 *     guard on the song side.
 *
 * Merge itself NEVER re-implements the mutation — it delegates to the
 * ONE shared core, `musicianMergeExecute()` (includes/musician_helpers.
 * php, #1785 C4/C5), passing every one of the source's OWN link/IPI ids
 * as "keep" (default keep-ALL child rows — this flow doesn't re-ask the
 * fine-grained checkbox question the /manage/musicians Merge modal
 * offers; that modal remains the precise path for a curator who wants
 * to drop specific child rows).
 *
 * Dismiss / undismiss persist to `tblMusicianDuplicatesDismissed`
 * (#1785 C1) — a cluster (group OR pair) is suppressed from the default
 * view only once EVERY one of its internal pairs is dismissed (the
 * `tblSongLinkSuggestionsDismissed` precedent, rule #22's "keep the
 * pattern, don't re-invent it" instinct applied to a sibling domain).
 * The dismissals table is web-run-migration-gated (rule #9) — an
 * un-migrated install still SCANS and renders (Bucket A/B/C all work
 * off `tblMusicians`/`tblMusicianAliases` alone), it just hides the
 * Dismiss/Undismiss controls and shows a "run the migration" card; a
 * `dismiss`/`undismiss` POST against that install answers 409 (status
 * is the contract, rule #35 — never string-match the error prose).
 *
 * Gating (plan D4 — no new entitlement): page view + Dismiss + Merge all
 * `manage_musicians`, the SAME gate `/manage/musicians` and `/manage/
 * musicians-bulk-promote` already use for exactly this class of action —
 * merge is already reachable to this exact user set via the Merge modal
 * on the parent page, so this page creates no new privilege.
 *
 * @see .claude/musicians-dedup-1785-plan.md §7
 * @see manage/duplicate-songs.php   the #1215 song-side precedent this mirrors
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'config.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_mysql.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'musician_helpers.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'musician_duplicates.php';

if (!isAuthenticated()) {
    header('Location: /manage/login');
    exit;
}
$currentUser = getCurrentUser();
if (!$currentUser || !userHasEntitlement('manage_musicians', $currentUser['role'] ?? null)) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><body><h1>403 — manage_musicians required</h1></body></html>';
    exit;
}
/* No admin-links.php nav entry (mirrors musicians-bulk-promote.php — a
   companion surface reached via a CTA, not a sidebar item); $activePage
   simply matches nothing so admin-nav.php highlights nothing, which is
   the same graceful degradation bulk-promote already relies on. */
$activePage = 'musician-duplicates';

$db   = getDbMysqli();
$csrf = csrfToken();

$logDupe = static function (string $action, string $entityId, array $details): void {
    if (function_exists('logActivity')) {
        try {
            logActivity('admin.musicians.' . $action, 'musician', $entityId, $details);
        } catch (\Throwable $_e) { /* audit is best-effort */ }
    }
};

/* ----------------------------------------------------------------------
 * POST dispatcher — JSON in / JSON out, validateCsrfRequest() (rule #29 —
 * same-origin X-Requested-With, never a baked session token alone on this
 * kind of long-open review page).
 * ---------------------------------------------------------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    header('Content-Type: application/json; charset=UTF-8');

    if (!validateCsrfRequest((string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['error' => 'CSRF check failed — please retry.']);
        exit;
    }
    $action = (string)($_POST['action'] ?? '');

    /* -------- merge -------- */
    if ($action === 'merge') {
        $survivorId  = (int)($_POST['survivor_id']  ?? 0);
        $duplicateId = (int)($_POST['duplicate_id'] ?? 0);
        $force       = (string)($_POST['force'] ?? '') === '1';

        if ($survivorId <= 0 || $duplicateId <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'survivor_id and duplicate_id are required.']);
            exit;
        }
        if ($survivorId === $duplicateId) {
            http_response_code(400);
            echo json_encode(['error' => 'A person cannot be merged into themselves.']);
            exit;
        }

        try {
            $payloads = musicianDisambiguationPayloadBulk($db, [$survivorId, $duplicateId]);
            if (!isset($payloads[$survivorId]) || !isset($payloads[$duplicateId])) {
                http_response_code(404);
                echo json_encode(['error' => 'Both people must exist.']);
                exit;
            }

            /* The musician-registry sibling of #1218's same-official-
               songbook guard — a disagreeing recorded lifespan is the
               "these might actually be two different people" signal
               Disambiguation exists to capture. Applied uniformly
               (byte-variant AND fuzzy pairs alike) and re-checked HERE
               regardless of which section the client's confirm dialog
               came from — never trust the client's own force flag as
               proof the check already happened. */
            if (!$force && musicianDuplicatesLifespanConflict($payloads[$survivorId], $payloads[$duplicateId])) {
                http_response_code(409);
                echo json_encode(['error' =>
                    'These two people have DIFFERING recorded birth or death years — possibly two different '
                    . 'people who share a name, not one person spelled two ways. Merge is blocked here; '
                    . 'use the type-to-confirm control if you are certain.']);
                exit;
            }

            /* Default keep-ALL child rows (plan §7) — fetch the
               duplicate's own actual link/IPI ids right now so
               musicianMergeExecute() migrates every one of them rather
               than re-asking the fine-grained checkbox question the
               /manage/musicians Merge modal offers. */
            $keepLinkIds = [];
            $lstmt = $db->prepare('SELECT Id FROM tblMusicianExternalLinks WHERE MusicianId = ?');
            $lstmt->bind_param('i', $duplicateId);
            $lstmt->execute();
            foreach ($lstmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) { $keepLinkIds[] = (int)$r['Id']; }
            $lstmt->close();

            $keepIpiIds = [];
            $istmt = $db->prepare('SELECT Id FROM tblMusicianIdentifiers WHERE MusicianId = ?');
            $istmt->bind_param('i', $duplicateId);
            $istmt->execute();
            foreach ($istmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) { $keepIpiIds[] = (int)$r['Id']; }
            $istmt->close();

            try {
                $report = musicianMergeExecute($db, $duplicateId, $survivorId, [
                    'keepLinkIds' => $keepLinkIds,
                    'keepIpiIds'  => $keepIpiIds,
                ]);
            } catch (\InvalidArgumentException $e) {
                http_response_code(404);
                echo json_encode(['error' => $e->getMessage()]);
                exit;
            }

            /* SAME activity-log key /manage/musicians' own Merge modal
               uses (plan §7 — "the existing key") so every merge, from
               either surface, shows up under one audit trail. */
            $logDupe('merge', (string)$survivorId, [
                'source' => ['id' => $duplicateId, 'name' => $report['sourceName']],
                'target' => ['id' => $survivorId,  'name' => $report['targetName']],
                'via'    => 'musician-duplicates-review',
                'affected' => [
                    'writers'     => $report['affected']['tblSongWriters'],
                    'composers'   => $report['affected']['tblSongComposers'],
                    'arrangers'   => $report['affected']['tblSongArrangers'],
                    'adaptors'    => $report['affected']['tblSongAdaptors'],
                    'translators' => $report['affected']['tblSongTranslators'],
                    'artists'     => $report['affected']['tblSongArtists'],
                ],
                'child_rows' => [
                    'links_kept'          => $report['linksKept'],
                    'links_dropped'       => $report['linksDropped'],
                    'ipi_kept'            => $report['ipiKept'],
                    'ipi_dropped'         => $report['ipiDropped'],
                    'aliases_moved'       => $report['aliasesMoved'],
                    'relations_moved'     => $report['relationsMoved'],
                    'source_name_aliased' => $report['sourceNameAliased'],
                    // #1800 C2 — COALESCE-fill: which empty survivor fields got backfilled from the source.
                    'fields_filled'       => $report['fieldsFilled'],
                ],
            ]);

            echo json_encode([
                'success'    => true,
                'survivorId' => $survivorId,
                'mergedFrom' => $duplicateId,
                'report'     => $report,
            ]);
            exit;
        } catch (\Throwable $e) {
            error_log('[musician-duplicates merge] ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Merge failed: ' . $e->getMessage()]);
            exit;
        }
    }

    /* -------- dismiss (whole cluster — one or more pairs among the given ids) -------- */
    if ($action === 'dismiss') {
        if (!musicianDuplicatesDismissedTableExists($db)) {
            http_response_code(409);
            echo json_encode(['error' => "The duplicate-review dismissals table hasn't been migrated on this install yet. Run it from Setup Database."]);
            exit;
        }
        $ids = array_values(array_unique(array_filter(
            array_map('intval', explode(',', (string)($_POST['ids'] ?? ''))),
            static fn(int $v): bool => $v > 0
        )));
        if (count($ids) < 2) {
            http_response_code(400);
            echo json_encode(['error' => 'Need at least two people to dismiss as a group.']);
            exit;
        }
        $reasonIn = trim((string)($_POST['reason'] ?? ''));
        $reason   = $reasonIn !== '' ? mb_substr($reasonIn, 0, 255) : 'reviewed: not the same person';

        try {
            $by = (int)($currentUser['id'] ?? 0) ?: null;
            $ins = $db->prepare(
                'INSERT INTO tblMusicianDuplicatesDismissed (MusicianIdA, MusicianIdB, DismissedBy, Reason)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE DismissedBy = VALUES(DismissedBy),
                                         Reason = VALUES(Reason),
                                         DismissedAt = CURRENT_TIMESTAMP'
            );
            $n = count($ids);
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    [$a, $b] = $ids[$i] < $ids[$j] ? [$ids[$i], $ids[$j]] : [$ids[$j], $ids[$i]];
                    $ins->bind_param('iiis', $a, $b, $by, $reason);
                    $ins->execute();
                }
            }
            $ins->close();

            $logDupe('dupe_dismiss', (string)$ids[0], ['ids' => $ids, 'reason' => $reason]);
            echo json_encode(['success' => true]);
            exit;
        } catch (\Throwable $e) {
            error_log('[musician-duplicates dismiss] ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Dismiss failed: ' . $e->getMessage()]);
            exit;
        }
    }

    /* -------- undismiss (one specific pair — the ?show=dismissed view) -------- */
    if ($action === 'undismiss') {
        if (!musicianDuplicatesDismissedTableExists($db)) {
            http_response_code(409);
            echo json_encode(['error' => "The duplicate-review dismissals table hasn't been migrated on this install yet."]);
            exit;
        }
        $idA = (int)($_POST['id_a'] ?? 0);
        $idB = (int)($_POST['id_b'] ?? 0);
        if ($idA <= 0 || $idB <= 0 || $idA === $idB) {
            http_response_code(400);
            echo json_encode(['error' => 'id_a and id_b are required and must differ.']);
            exit;
        }
        [$lo, $hi] = $idA < $idB ? [$idA, $idB] : [$idB, $idA];
        try {
            $del = $db->prepare('DELETE FROM tblMusicianDuplicatesDismissed WHERE MusicianIdA = ? AND MusicianIdB = ?');
            $del->bind_param('ii', $lo, $hi);
            $del->execute();
            $deleted = $del->affected_rows;
            $del->close();

            $logDupe('dupe_undismiss', (string)$lo, ['id_a' => $lo, 'id_b' => $hi]);
            echo json_encode(['success' => true, 'deleted' => $deleted]);
            exit;
        } catch (\Throwable $e) {
            error_log('[musician-duplicates undismiss] ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Undismiss failed: ' . $e->getMessage()]);
            exit;
        }
    }

    http_response_code(400);
    echo json_encode(['error' => 'Unknown action.']);
    exit;
}

/* ----------------------------------------------------------------------
 * GET — run the scan (rule #9: wrapped in try/catch so an un-migrated
 * dependency degrades to a themed error card, never a white screen).
 * ---------------------------------------------------------------------- */
$threshold = max(0.5, min(1.0, (float)($_GET['threshold'] ?? MUSDUP_DEFAULT_THRESHOLD)));
$minUses   = max(0, (int)($_GET['min_uses'] ?? 0));
$searchQ   = trim((string)($_GET['q'] ?? ''));
$showDismissed = (string)($_GET['show'] ?? '') === 'dismissed';

$dismissedTableReady = musicianDuplicatesDismissedTableExists($db);

$scanError = null;
$byteCards = [];   // §1 — groups + alias-match pairs, normalised to one card shape
$fuzzyRows = [];   // §2 — fuzzy pairs
$stats = ['registryRows' => 0, 'foldGroups' => 0, 'fuzzyPairsScored' => 0, 'skippedBuckets' => [], 'elapsedMs' => 0.0];

try {
    /* Always request BOTH active and dismissed candidates (each carrying
       an accurate `dismissed` flag) — the PHP loops below pick the right
       subset for $showDismissed. Harmless on an un-migrated install: the
       dismissed-pairs lookup is empty there, so every flag is simply
       false and the active view shows everything, exactly as it should. */
    $result = musicianFindRegistryDuplicates($db, [
        'threshold'        => $threshold,
        'includeDismissed' => true,
    ]);
    $stats = $result['stats'];

    /* Filter predicate shared by both sections — min_uses (max useCount
       across the cluster) + q (substring match on any member's name). */
    $passesFilters = static function (array $members) use ($minUses, $searchQ): bool {
        if ($minUses > 0) {
            $maxUse = 0;
            foreach ($members as $m) { $maxUse = max($maxUse, (int)($m['useCount'] ?? 0)); }
            if ($maxUse < $minUses) { return false; }
        }
        if ($searchQ !== '') {
            $hit = false;
            foreach ($members as $m) {
                if (stripos((string)($m['name'] ?? ''), $searchQ) !== false) { $hit = true; break; }
            }
            if (!$hit) { return false; }
        }
        return true;
    };

    foreach ($result['groups'] as $g) {
        if ($showDismissed) {
            if (!$g['dismissed']) { continue; }
        } else {
            if ($g['dismissed']) { continue; }
        }
        if (!$passesFilters($g['members'])) { continue; }
        $byteCards[] = [
            'kind'      => 'group',
            'members'   => $g['members'],
            'pairs'     => $g['pairs'],
            'variant'   => $g['pairs'][0][2] ?? null, // representative — a group's members share ONE fold, so every internal pair classifies the same way
            'signal'    => 'fold-equal',
            'dismissed' => $g['dismissed'],
        ];
    }
    foreach ($result['pairs'] as $p) {
        if ($showDismissed) {
            if (!$p['dismissed']) { continue; }
        } else {
            if ($p['dismissed']) { continue; }
        }
        if (!$passesFilters([$p['a'], $p['b']])) { continue; }
        $conflict = musicianDuplicatesLifespanConflict($p['a'], $p['b']);
        if ($p['signal'] === 'alias-match') {
            $byteCards[] = [
                'kind'      => 'alias',
                'members'   => [$p['a'], $p['b']],
                'pairs'     => [[$p['a']['id'], $p['b']['id'], $p['variant']]],
                'variant'   => $p['variant'],
                'signal'    => 'alias-match',
                'suggestedSurvivor' => $p['suggestedSurvivor'],
                'conflict'  => $conflict,
                'dismissed' => $p['dismissed'],
            ];
        } else {
            $fuzzyRows[] = $p + ['conflict' => $conflict];
        }
    }
} catch (\Throwable $e) {
    $scanError = $e->getMessage();
    error_log('[musician-duplicates] scan failed: ' . $scanError);
}

/* ---- Render helpers ---- */
$personLink = static function (array $p): string {
    return '<a href="/manage/musicians?id=' . (int)$p['id'] . '" target="_blank" rel="noopener" class="link-secondary">'
         . '<i class="bi bi-box-arrow-up-right small" aria-hidden="true"></i></a>';
};
$lifespanLabel = static function (array $p): string {
    $born = $p['born'] ?? null; $died = $p['died'] ?? null;
    if (!$born && !$died) { return ''; }
    return htmlspecialchars(trim(($born ?? '?') . '–' . ($died ?? '')), ENT_QUOTES);
};
$variantBadge = static function (?string $variant): string {
    if ($variant === null) { return '<span class="badge bg-secondary-subtle text-secondary-emphasis">different words</span>'; }
    $label = MUSICIAN_NAME_VARIANT_LABELS[$variant] ?? $variant;
    return '<span class="badge bg-info-subtle text-info-emphasis" title="' . htmlspecialchars($label, ENT_QUOTES) . '">'
         . htmlspecialchars($variant, ENT_QUOTES) . '</span>';
};
$personChip = static function (array $p) use ($lifespanLabel, $personLink): string {
    $bits = [];
    $bits[] = '<strong>' . htmlspecialchars($p['name']) . '</strong>';
    $bits[] = $personLink($p);
    $meta = [];
    $meta[] = '#' . (int)$p['id'];
    if ($p['type'] !== 'person') { $meta[] = htmlspecialchars(MUSICIAN_TYPES[$p['type']] ?? $p['type'], ENT_QUOTES); }
    $meta[] = $p['useCount'] . ' credit' . ($p['useCount'] === 1 ? '' : 's');
    $ls = $lifespanLabel($p);
    if ($ls !== '') { $meta[] = $ls; }
    if (!empty($p['disambiguation'])) { $meta[] = htmlspecialchars($p['disambiguation'], ENT_QUOTES); }
    return implode(' ', $bits) . '<div class="small text-secondary">' . implode(' · ', $meta) . '</div>';
};

require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-favicon.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Musician Duplicates Review — iHymns Admin</title>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-libs.php'; ?>
</head>
<body>
<?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-nav.php'; ?>

<main class="container-fluid py-4">
    <div class="mb-3 d-flex flex-wrap justify-content-between align-items-start gap-2">
        <div>
            <h1 class="h3 mb-1"><i class="bi bi-people-fill me-2"></i>Musician Duplicates Review</h1>
            <p class="text-secondary small mb-0" style="max-width:60rem;">
                Two registry entries that are probably the same person — either the <strong>exact same
                spelling</strong> minus invisible bytes (a curly apostrophe, an extra space), or names that
                are <strong>similar enough</strong> to be worth a look. Scored live on every visit — nothing
                here is precomputed or can go stale.
                <a href="/manage/musicians" class="link-secondary">← back to Musicians</a>
                &middot; <a href="/manage/musicians-bulk-promote" class="link-secondary">Add Musicians in Bulk</a>
                (a different job — names cited on a song that <em>aren't in the registry at all</em>)
            </p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="?<?= http_build_query(array_filter(['threshold' => $threshold !== MUSDUP_DEFAULT_THRESHOLD ? $threshold : null, 'min_uses' => $minUses ?: null, 'q' => $searchQ ?: null, 'show' => $showDismissed ? null : 'dismissed'])) ?>"
               class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-<?= $showDismissed ? 'eye' : 'eye-slash' ?> me-1"></i><?= $showDismissed ? 'Back to active' : 'Show dismissed' ?>
            </a>
            <button type="button" class="btn btn-sm btn-outline-primary" id="mdup-legend-btn" aria-keyshortcuts="?" title="Keyboard shortcuts (?)">
                <i class="bi bi-keyboard me-1"></i>Shortcuts
            </button>
        </div>
    </div>

    <div id="mdup-legend" class="alert alert-secondary small d-none" role="dialog" aria-label="Keyboard shortcuts">
        <strong>Keyboard shortcuts</strong> (active while a row/card has focus):
        <span class="ms-2"><kbd>j</kbd>/<kbd>k</kbd> or <kbd>↓</kbd>/<kbd>↑</kbd> move</span>
        <span class="ms-2"><kbd>Enter</kbd> merge</span>
        <span class="ms-2"><kbd>d</kbd> dismiss</span>
        <span class="ms-2"><kbd>s</kbd> swap survivor</span>
        <span class="ms-2"><kbd>?</kbd> toggle this legend</span>
    </div>

    <?php if ($scanError !== null): ?>
        <div class="alert alert-danger" role="alert">
            <i class="bi bi-exclamation-octagon me-1"></i>
            <strong>Duplicate detection couldn't run.</strong>
            This usually means a database migration hasn't been applied on this environment yet. Open
            <a href="/manage/setup-database" class="alert-link">Setup&nbsp;Database</a>, apply any pending
            migrations, then reload this page.
            <div class="small text-muted mt-2">Detail: <code><?= htmlspecialchars($scanError, ENT_QUOTES) ?></code></div>
        </div>
    <?php else: ?>

    <?php if (!$dismissedTableReady): ?>
        <div class="alert alert-warning py-2 small">
            <i class="bi bi-exclamation-triangle me-1"></i>
            The duplicate-review dismissals table hasn't been migrated on this install yet — the scan below
            still works, but Dismiss/Undismiss are unavailable until you
            <a href="/manage/setup-database" class="alert-link">run the migration</a>.
        </div>
    <?php endif; ?>

    <!-- Stats line (no silent caps — rule #35) -->
    <p class="text-muted small mb-2">
        Scanned <?= number_format($stats['registryRows']) ?> registry row<?= $stats['registryRows'] === 1 ? '' : 's' ?>
        in <?= number_format($stats['elapsedMs'], 1) ?>ms.
        <?= number_format($stats['foldGroups']) ?> byte-variant group<?= $stats['foldGroups'] === 1 ? '' : 's' ?>,
        <?= number_format($stats['fuzzyPairsScored']) ?> fuzzy pair<?= $stats['fuzzyPairsScored'] === 1 ? '' : 's' ?> scored.
        <?php if (!empty($stats['skippedBuckets'])): ?>
            <span class="text-warning-emphasis">
                <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
                <?= count($stats['skippedBuckets']) ?> block(s) skipped as over-cap (too large to score safely) —
                <?= htmlspecialchars(implode('; ', array_map(
                    static fn(array $sb) => $sb['key'] . ' (' . $sb['size'] . ' members)',
                    $stats['skippedBuckets']
                )), ENT_QUOTES) ?>
            </span>
        <?php endif; ?>
    </p>

    <!-- Filters -->
    <form method="GET" class="card-admin p-3 mb-3">
        <?php if ($showDismissed): ?><input type="hidden" name="show" value="dismissed"><?php endif; ?>
        <div class="row g-2 align-items-end">
            <div class="col-sm-4">
                <label class="form-label small">Search name</label>
                <input type="search" name="q" value="<?= htmlspecialchars($searchQ) ?>"
                       class="form-control form-control-sm" placeholder="e.g. Newton">
            </div>
            <div class="col-sm-3">
                <label class="form-label small">Fuzzy threshold</label>
                <input type="number" name="threshold" value="<?= htmlspecialchars((string)$threshold) ?>"
                       min="0.5" max="1.0" step="0.01" class="form-control form-control-sm">
            </div>
            <div class="col-sm-3">
                <label class="form-label small">Min credits (either side)</label>
                <input type="number" name="min_uses" value="<?= (int)$minUses ?>" min="0" max="9999"
                       class="form-control form-control-sm">
            </div>
            <div class="col-sm-2">
                <button type="submit" class="btn btn-secondary btn-sm w-100"><i class="bi bi-funnel me-1"></i>Apply</button>
            </div>
        </div>
    </form>

    <?php if ($showDismissed): ?>
        <h2 class="h5 mt-3"><i class="bi bi-eye-slash me-1"></i>Dismissed (<?= count($byteCards) + count($fuzzyRows) ?>)</h2>
        <?php if (!$byteCards && !$fuzzyRows): ?>
            <div class="alert alert-secondary">Nothing has been dismissed yet.</div>
        <?php endif; ?>
    <?php elseif (!$byteCards && !$fuzzyRows): ?>
        <div class="alert alert-success"><i class="bi bi-check-circle me-1"></i>No probable duplicates detected.</div>
    <?php endif; ?>

    <?php
    /* ---------------------------------------------------------------
     * §1 — cluster cards (byte-variant groups + alias-match pairs).
     * ------------------------------------------------------------- */
    $renderCard = function (array $c) use ($personChip, $variantBadge, $personLink): void {
        $members = $c['members'];
        $pairKeys = array_map(static fn(array $p) => $p[0] . ',' . $p[1], $c['pairs']);
        $idList = implode(',', array_map(static fn(array $m) => $m['id'], $members));
        $conflict = !empty($c['conflict']);
        echo '<div class="card mb-2 mdup-cluster" tabindex="-1" data-mdup-row data-kind="' . htmlspecialchars($c['kind'], ENT_QUOTES) . '" '
           . 'data-ids="' . htmlspecialchars($idList, ENT_QUOTES) . '" data-conflict="' . ($conflict ? '1' : '0') . '" '
           . 'aria-keyshortcuts="j k Enter d s">';
        echo '<div class="card-body py-2">';
        echo '<div class="d-flex flex-wrap align-items-center gap-2 mb-2">';
        echo $c['signal'] === 'alias-match'
            ? '<span class="badge bg-success-subtle text-success-emphasis">curated alias match</span>'
            : '<span class="badge bg-danger">100% · high</span>';
        echo $variantBadge($c['variant']);
        if ($conflict) {
            echo '<span class="text-warning-emphasis small"><i class="bi bi-exclamation-triangle me-1"></i>Recorded lifespans disagree — merge is guarded.</span>';
        }
        echo '</div>';

        echo '<table class="table table-sm mb-2"><thead><tr>'
           . '<th style="width:2.5rem;">Keep</th><th style="width:2.5rem;">Pick</th><th>Person</th></tr></thead><tbody>';
        $first = true;
        foreach ($members as $m) {
            $isSuggested = isset($c['suggestedSurvivor'])
                ? ($first && $c['suggestedSurvivor'] === 'a') || (!$first && $c['suggestedSurvivor'] === 'b')
                : $first;
            echo '<tr>';
            echo '<td><input type="radio" name="mdup-keep-' . (int)$m['id'] . '-grp" class="mdup-keep" value="' . (int)$m['id'] . '"'
               . ($isSuggested ? ' checked' : '') . ' aria-label="Keep ' . htmlspecialchars($m['name'], ENT_QUOTES) . ' as the survivor"></td>';
            echo '<td><input type="checkbox" class="mdup-pick" value="' . (int)$m['id'] . '"' . ($first ? '' : ' checked') . ' aria-label="Include ' . htmlspecialchars($m['name'], ENT_QUOTES) . ' in this action"></td>';
            echo '<td>' . $personChip($m) . '</td>';
            echo '</tr>';
            $first = false;
        }
        echo '</tbody></table>';

        echo '<div class="d-flex flex-wrap gap-2 align-items-center">';
        if ($conflict) {
            echo '<span class="small text-secondary">Type MERGE to enable:</span>'
               . '<input type="text" class="form-control form-control-sm mdup-confirm-input" style="max-width:9rem" autocomplete="off" spellcheck="false" aria-label="Type MERGE to confirm">'
               . '<button type="button" class="btn btn-sm btn-outline-danger mdup-merge-btn" data-force="1" disabled><i class="bi bi-union me-1"></i>Merge</button>';
        } else {
            echo '<button type="button" class="btn btn-sm btn-outline-danger mdup-merge-btn"><i class="bi bi-union me-1"></i>Merge picked into kept</button>';
        }
        echo '<button type="button" class="btn btn-sm btn-outline-secondary mdup-dismiss-btn ms-auto" title="Not the same person">'
           . '<i class="bi bi-x-lg me-1"></i>Not the same person</button>';
        echo '</div>';
        echo '</div></div>';
    };
    if ($byteCards) {
        echo '<h2 class="h5 mt-4"><i class="bi bi-fonts me-1"></i>Same name, different bytes (' . count($byteCards) . ')</h2>';
        echo '<p class="text-secondary small mb-2">Byte-identical spellings, or a curated alias link — high confidence, one-click merge.</p>';
        foreach ($byteCards as $c) { $renderCard($c); }
    }

    /* ---------------------------------------------------------------
     * §2 — Similar names, sortable table.
     * ------------------------------------------------------------- */
    if ($fuzzyRows): ?>
        <h2 class="h5 mt-4"><i class="bi bi-search me-1"></i>Similar names (<?= count($fuzzyRows) ?>)</h2>
        <p class="text-secondary small mb-2">Genuinely different spellings that scored above the fuzzy threshold — review before merging.</p>
        <div class="card-admin p-0">
            <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0 cp-sortable admin-table-responsive" data-default-sort-key="score" data-default-sort-dir="desc">
                <thead>
                    <tr class="text-muted small">
                        <th data-col-priority="primary" data-sort-key="score" data-sort-type="number">Score</th>
                        <th data-col-priority="primary" data-sort-key="a" data-sort-type="text">Name A</th>
                        <th data-col-priority="primary" data-sort-key="b" data-sort-type="text">Name B</th>
                        <th data-col-priority="tertiary" data-sort-key="signal" data-sort-type="text">Signal</th>
                        <th data-col-priority="primary" style="min-width:16rem;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($fuzzyRows as $p):
                    $pct = (int)round($p['score'] * 100);
                    $conflict = !empty($p['conflict']);
                    $idList = $p['a']['id'] . ',' . $p['b']['id'];
                ?>
                    <tr class="mdup-cluster" tabindex="-1" data-mdup-row data-kind="fuzzy" data-ids="<?= htmlspecialchars($idList, ENT_QUOTES) ?>"
                        data-conflict="<?= $conflict ? '1' : '0' ?>" data-survivor="<?= $p['suggestedSurvivor'] === 'a' ? $p['a']['id'] : $p['b']['id'] ?>"
                        aria-keyshortcuts="j k Enter d s">
                        <td data-col-priority="primary" data-sort-value="<?= number_format($p['score'], 4, '.', '') ?>"><?= $pct ?>%</td>
                        <td data-col-priority="primary" class="mdup-name-a" data-sort-value="<?= htmlspecialchars($p['a']['name'], ENT_QUOTES) ?>"><?= $personChip($p['a']) ?></td>
                        <td data-col-priority="primary" class="mdup-name-b" data-sort-value="<?= htmlspecialchars($p['b']['name'], ENT_QUOTES) ?>"><?= $personChip($p['b']) ?></td>
                        <td data-col-priority="tertiary">
                            <?php if ($conflict): ?><span class="badge bg-warning text-dark" title="Recorded lifespans disagree">lifespan conflict</span><?php endif; ?>
                        </td>
                        <td data-col-priority="primary">
                            <div class="d-flex flex-wrap gap-1 align-items-center">
                                <button type="button" class="btn btn-sm btn-outline-secondary mdup-swap-btn" title="Swap which side survives (s)">
                                    <i class="bi bi-arrow-left-right"></i>
                                </button>
                                <?php if ($conflict): ?>
                                    <input type="text" class="form-control form-control-sm mdup-confirm-input" style="max-width:7rem" autocomplete="off" spellcheck="false" aria-label="Type MERGE to confirm">
                                    <button type="button" class="btn btn-sm btn-outline-danger mdup-merge-btn" data-force="1" disabled>Merge</button>
                                <?php else: ?>
                                    <button type="button" class="btn btn-sm btn-outline-danger mdup-merge-btn">Merge</button>
                                <?php endif; ?>
                                <?php if ($showDismissed): ?>
                                    <button type="button" class="btn btn-sm btn-outline-success mdup-undismiss-btn">Undismiss</button>
                                <?php else: ?>
                                    <button type="button" class="btn btn-sm btn-outline-secondary mdup-dismiss-btn">Dismiss</button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($showDismissed): ?>
        <script>window.__mdupUndismissMode = true;</script>
    <?php endif; ?>

    <?php endif /* $scanError check */ ?>
</main>

<script>
(function () {
    'use strict';
    var CSRF = <?= json_encode($csrf) ?>;

    function toast(msg, ok) { if (window.showToast) { window.showToast(msg, ok ? 'success' : 'error'); } else { alert(msg); } }

    function postAction(params) {
        params.csrf_token = CSRF;
        return fetch('/manage/musician-duplicates', {
            method: 'POST', body: new URLSearchParams(params), credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (r) {
            return r.json().then(function (j) {
                if (!r.ok || !j.success) { var e = new Error(j.error || 'Action failed'); e.status = r.status; throw e; }
                return j;
            });
        });
    }

    /* ---- Merge ---- */
    document.querySelectorAll('.mdup-merge-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var row = btn.closest('[data-mdup-row]');
            if (!row) return;
            var force = btn.dataset.force === '1';
            var kind = row.dataset.kind;
            var pairs = [];

            if (kind === 'fuzzy') {
                var survivor = row.dataset.survivor;
                var ids = row.dataset.ids.split(',');
                var dup = ids.filter(function (id) { return id !== survivor; })[0];
                if (dup) pairs.push({ survivor: survivor, dup: dup });
            } else {
                var keep = row.querySelector('.mdup-keep:checked');
                if (!keep) { toast('Choose which person to keep.', false); return; }
                var picked = Array.prototype.map.call(row.querySelectorAll('.mdup-pick:checked'), function (c) { return c.value; })
                    .filter(function (id) { return id !== keep.value; });
                if (!picked.length) { toast('Tick at least one other person to merge in.', false); return; }
                picked.forEach(function (id) { pairs.push({ survivor: keep.value, dup: id }); });
            }
            if (!pairs.length) { return; }

            var msg = force
                ? 'These two people have DIFFERING recorded birth/death years. Merge anyway? This cannot be undone.'
                : 'Merge ' + pairs.length + ' person(s)? This re-points every song credit and deletes the merged row(s). This cannot be undone.';
            if (!confirm(msg)) return;

            btn.disabled = true;
            var chain = Promise.resolve();
            pairs.forEach(function (p) {
                chain = chain.then(function () {
                    var params = { action: 'merge', survivor_id: p.survivor, duplicate_id: p.dup };
                    if (force) { params.force = '1'; }
                    return postAction(params);
                });
            });
            chain.then(function () {
                toast('Merged.', true);
                row.remove();
            }).catch(function (e) {
                btn.disabled = false;
                if (e.status === 409) { toast(e.message, false); }
                else { toast(e.message || 'Merge failed.', false); }
            });
        });
    });

    /* ---- Type-to-confirm arming (lifespan-conflict rows) ---- */
    document.querySelectorAll('.mdup-confirm-input').forEach(function (input) {
        input.addEventListener('input', function () {
            var row = input.closest('[data-mdup-row]');
            var btn = row.querySelector('.mdup-merge-btn[data-force="1"]');
            if (btn) { btn.disabled = input.value.trim().toUpperCase() !== 'MERGE'; }
        });
    });

    /* ---- Dismiss (whole cluster) ---- */
    document.querySelectorAll('.mdup-dismiss-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var row = btn.closest('[data-mdup-row]');
            if (!row) return;
            if (!confirm('Mark reviewed — not the same person? This stops the pair appearing here.')) return;
            btn.disabled = true;
            postAction({ action: 'dismiss', ids: row.dataset.ids }).then(function () {
                toast('Dismissed.', true);
                row.remove();
            }).catch(function (e) { btn.disabled = false; toast(e.message || 'Dismiss failed.', false); });
        });
    });

    /* ---- Undismiss (?show=dismissed view) ---- */
    document.querySelectorAll('.mdup-undismiss-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var row = btn.closest('[data-mdup-row]');
            if (!row) return;
            var ids = row.dataset.ids.split(',');
            if (ids.length !== 2) { return; }
            btn.disabled = true;
            postAction({ action: 'undismiss', id_a: ids[0], id_b: ids[1] }).then(function () {
                toast('Restored.', true);
                row.remove();
            }).catch(function (e) { btn.disabled = false; toast(e.message || 'Undismiss failed.', false); });
        });
    });

    /* ---- Swap survivor (§2 rows) ---- */
    document.querySelectorAll('.mdup-swap-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var row = btn.closest('[data-mdup-row]');
            if (!row) return;
            var ids = row.dataset.ids.split(',');
            var current = row.dataset.survivor;
            var other = ids.filter(function (id) { return id !== current; })[0];
            if (!other) return;
            row.dataset.survivor = other;
            row.classList.add('table-active');
            setTimeout(function () { row.classList.remove('table-active'); }, 400);
        });
    });

    /* ---- Keyboard nav (rule: accelerators only while a row has focus,
       every action equally reachable by click — WCAG 2.1.1/2.1.4). ---- */
    var legend = document.getElementById('mdup-legend');
    var legendBtn = document.getElementById('mdup-legend-btn');
    function toggleLegend() { if (legend) legend.classList.toggle('d-none'); }
    if (legendBtn) legendBtn.addEventListener('click', toggleLegend);

    function rows() { return Array.prototype.slice.call(document.querySelectorAll('[data-mdup-row]')); }
    function focusRow(r) {
        rows().forEach(function (x) { x.tabIndex = -1; });
        r.tabIndex = 0;
        r.focus();
    }
    var allRows = rows();
    if (allRows.length) { allRows[0].tabIndex = 0; }

    document.addEventListener('keydown', function (ev) {
        if (ev.key === '?') { toggleLegend(); return; }
        var active = document.activeElement;
        var row = active ? active.closest('[data-mdup-row]') : null;
        if (!row) return;
        var list = rows();
        var idx = list.indexOf(row);
        if (ev.key === 'j' || ev.key === 'ArrowDown') {
            ev.preventDefault();
            if (idx < list.length - 1) focusRow(list[idx + 1]);
        } else if (ev.key === 'k' || ev.key === 'ArrowUp') {
            ev.preventDefault();
            if (idx > 0) focusRow(list[idx - 1]);
        } else if (ev.key === 'Enter') {
            var mergeBtn = row.querySelector('.mdup-merge-btn:not(:disabled)');
            if (mergeBtn) mergeBtn.click();
        } else if (ev.key === 'd') {
            var dismissBtn = row.querySelector('.mdup-dismiss-btn, .mdup-undismiss-btn');
            if (dismissBtn) dismissBtn.click();
        } else if (ev.key === 's') {
            var swapBtn = row.querySelector('.mdup-swap-btn');
            if (swapBtn) swapBtn.click();
        }
    });
})();
</script>

<script type="module">
    import { bootSortableTables } from '/js/modules/admin-table-sort.js?v=<?= filemtime(dirname(__DIR__) . '/js/modules/admin-table-sort.js') ?>';
    bootSortableTables();
</script>

<?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>
</body>
</html>
