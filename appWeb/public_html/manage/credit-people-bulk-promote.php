<?php

declare(strict_types=1);

/**
 * iHymns — Admin: Credit People Bulk Promote (#846)
 *
 * Companion surface to /manage/credit-people. Lists every distinct
 * name used on a song-credit row that doesn't yet have a matching
 * tblCreditPeople row, runs fuzzy-similarity against the registry +
 * the candidate set itself, and lets the curator promote dozens /
 * hundreds of in-use names in one submit.
 *
 * Each candidate gets one of three actions:
 *
 *   register — create a fresh tblCreditPeople row.
 *   merge    — re-point every credit on every song to the existing
 *              registry row's Name (uses the same UPDATE pattern the
 *              single-row merge action uses on /manage/credit-people).
 *   skip     — leave the credit rows alone.
 *
 * Auto-resolve pre-picks the highest-scoring registry match above a
 * configurable threshold; the curator reviews + adjusts before
 * submitting.
 *
 * Gated by `manage_credit_people` (same as the parent page).
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_mysql.php';

if (!isAuthenticated()) {
    header('Location: /manage/login');
    exit;
}
$currentUser = getCurrentUser();
if (!$currentUser || !userHasEntitlement('manage_credit_people', $currentUser['role'] ?? null)) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><body><h1>403 — manage_credit_people required</h1></body></html>';
    exit;
}
$activePage = 'credit-people-bulk-promote';

$error   = '';
$success = '';
$db      = getDbMysqli();
$csrf    = csrfToken();

/* Five song-credit tables — same set the parent page reads. */
const _CP_BULK_CREDIT_TABLES = [
    'writer'     => 'tblSongWriters',
    'composer'   => 'tblSongComposers',
    'arranger'   => 'tblSongArrangers',
    'adaptor'    => 'tblSongAdaptors',
    'translator' => 'tblSongTranslators',
];

/**
 * Cheap normalised-name similarity in [0, 1]. Mirrors the scoring
 * shape from tools/build-song-link-suggestions.php (#808): lowercase,
 * strip punctuation, collapse whitespace, then 1 - (edit-distance /
 * max-length). Token-set bonus boosts "John Newton" vs "Newton, John".
 *
 * Pure-PHP — no external libraries. Runs O(n²) over candidate × registry
 * pairs but the row counts are typically a few hundred each, so the
 * full scan is sub-second on a modern host.
 */
function _cpBulkNormalise(string $s): string
{
    $s = mb_strtolower($s, 'UTF-8');
    $s = (string)preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $s);
    $s = (string)preg_replace('/\s+/u', ' ', $s);
    return trim($s);
}
function _cpBulkTokens(string $s): array
{
    $n = _cpBulkNormalise($s);
    if ($n === '') return [];
    return array_filter(explode(' ', $n), static fn($t) => $t !== '');
}
function _cpBulkSimilarity(string $a, string $b): float
{
    $na = _cpBulkNormalise($a);
    $nb = _cpBulkNormalise($b);
    if ($na === '' || $nb === '') return 0.0;
    if ($na === $nb) return 1.0;

    /* PHP's levenshtein is byte-based and capped at 255 chars per
       string — fine for personal-name lengths. Fall back to
       similar_text for anything longer (covers organisation-style
       group names that occasionally appear). */
    if (strlen($na) <= 255 && strlen($nb) <= 255) {
        $dist = levenshtein($na, $nb);
        $max  = max(strlen($na), strlen($nb));
        $editScore = $max > 0 ? 1.0 - ($dist / $max) : 0.0;
    } else {
        similar_text($na, $nb, $pct);
        $editScore = $pct / 100.0;
    }

    /* Token-set bonus — names that are anagrams of word-tokens
       ("Newton, John" vs "John Newton") get a high overlap score
       even when raw edit distance penalises the comma + spacing. */
    $ta = _cpBulkTokens($a);
    $tb = _cpBulkTokens($b);
    if (!$ta || !$tb) return $editScore;
    $inter = count(array_intersect($ta, $tb));
    $union = count(array_unique(array_merge($ta, $tb)));
    $tokenScore = $union > 0 ? $inter / $union : 0.0;

    /* Blend — favour token overlap when one input is short, edit when
       both are long. The 0.6 / 0.4 weights are tuned to score
       "J. Newton" vs "John Newton" at ≈ 0.85 (the default threshold). */
    return 0.6 * $tokenScore + 0.4 * $editScore;
}

/* ----- POST: bulk promote ----- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!validateCsrf((string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo 'Invalid CSRF token';
        exit;
    }
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'bulk_promote') {
        $rowAction = $_POST['row_action'] ?? [];   /* keyed by candidate name → 'register'|'merge'|'skip' */
        $mergeTo   = $_POST['merge_to']   ?? [];   /* candidate name → existing tblCreditPeople.Id */
        if (!is_array($rowAction)) $rowAction = [];
        if (!is_array($mergeTo))   $mergeTo   = [];

        $bulkRunId  = bin2hex(random_bytes(6));
        $registered = 0;
        $merged     = 0;
        $skipped    = 0;
        $failed     = 0;

        $db->begin_transaction();
        try {
            foreach ($rowAction as $name => $act) {
                $name = trim((string)$name);
                $act  = (string)$act;
                if ($name === '' || $name === 'skip' && $act === 'skip') {
                    $skipped++;
                    continue;
                }
                if ($act === 'skip') {
                    $skipped++;
                    continue;
                }
                if ($act === 'register') {
                    /* Idempotent: if someone else registered the same
                       name between the page render and this POST, the
                       UNIQUE-on-Name constraint would throw — guard
                       with an upfront SELECT. */
                    $stmt = $db->prepare('SELECT Id FROM tblCreditPeople WHERE Name = ?');
                    $stmt->bind_param('s', $name);
                    $stmt->execute();
                    $existing = $stmt->get_result()->fetch_row();
                    $stmt->close();
                    if ($existing) { $skipped++; continue; }

                    $stmt = $db->prepare('INSERT INTO tblCreditPeople (Name) VALUES (?)');
                    $stmt->bind_param('s', $name);
                    $stmt->execute();
                    $newId = (int)$db->insert_id;
                    $stmt->close();

                    if (function_exists('logActivity')) {
                        logActivity('credit_person.bulk_register', 'credit_person', (string)$newId, [
                            'name'         => $name,
                            'bulk_run_id'  => $bulkRunId,
                        ]);
                    }
                    $registered++;
                } elseif ($act === 'merge') {
                    $targetId = (int)($mergeTo[$name] ?? 0);
                    if ($targetId <= 0) { $failed++; continue; }

                    $stmt = $db->prepare('SELECT Name FROM tblCreditPeople WHERE Id = ?');
                    $stmt->bind_param('i', $targetId);
                    $stmt->execute();
                    $row = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    if (!$row) { $failed++; continue; }
                    $targetName = (string)$row['Name'];
                    if ($targetName === $name) { $skipped++; continue; }

                    /* Re-point every song-credit row from the typed
                       source name to the registry target name across
                       the five join tables. Mirrors the single-row
                       `merge` action's cascade in /manage/credit-people. */
                    $rowsAffected = 0;
                    foreach (_CP_BULK_CREDIT_TABLES as $tbl) {
                        $stmt = $db->prepare("UPDATE {$tbl} SET Name = ? WHERE Name = ?");
                        $stmt->bind_param('ss', $targetName, $name);
                        $stmt->execute();
                        $rowsAffected += $stmt->affected_rows;
                        $stmt->close();
                    }

                    if (function_exists('logActivity')) {
                        logActivity('credit_person.bulk_merge', 'credit_person', (string)$targetId, [
                            'source_name'   => $name,
                            'target_id'     => $targetId,
                            'target_name'   => $targetName,
                            'rows_repointed'=> $rowsAffected,
                            'bulk_run_id'   => $bulkRunId,
                        ]);
                    }
                    $merged++;
                } else {
                    $skipped++;
                }
            }
            $db->commit();
            $success = "Bulk run {$bulkRunId} done: {$registered} registered, {$merged} merged, {$skipped} skipped"
                . ($failed > 0 ? ", {$failed} failed (see audit log)." : '.');
        } catch (\Throwable $tx) {
            $db->rollback();
            error_log('[credit-people-bulk-promote] ' . $tx->getMessage());
            $error = 'Bulk run rolled back: ' . $tx->getMessage();
        }
    }
}

/* ----- GET render: candidates + suggestions ----- */
$threshold = max(0.5, min(1.0, (float)($_GET['threshold'] ?? 0.85)));
$minUses   = max(1, (int)($_GET['min_uses'] ?? 1));
$searchQ   = trim((string)($_GET['q'] ?? ''));

/* Q1 — every distinct name across the five song-credit tables, with
   per-role counts. Matches the parent page's usage SQL. */
$candidates = [];
try {
    $usageSql = "
        SELECT Name,
               SUM(IF(kindLabel = 'writer',     cnt, 0)) AS WriterCount,
               SUM(IF(kindLabel = 'composer',   cnt, 0)) AS ComposerCount,
               SUM(IF(kindLabel = 'arranger',   cnt, 0)) AS ArrangerCount,
               SUM(IF(kindLabel = 'adaptor',    cnt, 0)) AS AdaptorCount,
               SUM(IF(kindLabel = 'translator', cnt, 0)) AS TranslatorCount,
               SUM(cnt) AS TotalUsage
          FROM (
              SELECT Name, 'writer'     AS kindLabel, COUNT(*) AS cnt FROM tblSongWriters     GROUP BY Name
              UNION ALL
              SELECT Name, 'composer'   AS kindLabel, COUNT(*) AS cnt FROM tblSongComposers   GROUP BY Name
              UNION ALL
              SELECT Name, 'arranger'   AS kindLabel, COUNT(*) AS cnt FROM tblSongArrangers   GROUP BY Name
              UNION ALL
              SELECT Name, 'adaptor'    AS kindLabel, COUNT(*) AS cnt FROM tblSongAdaptors    GROUP BY Name
              UNION ALL
              SELECT Name, 'translator' AS kindLabel, COUNT(*) AS cnt FROM tblSongTranslators GROUP BY Name
          ) u
         GROUP BY Name
    ";
    $usageRows = $db->query($usageSql)->fetch_all(MYSQLI_ASSOC);

    /* Q2 — registry rows (id + name only — bulk page doesn't need the bio bits). */
    $registryRows = $db->query('SELECT Id, Name FROM tblCreditPeople')->fetch_all(MYSQLI_ASSOC);
    $registryByName = [];
    foreach ($registryRows as $r) {
        $registryByName[(string)$r['Name']] = (int)$r['Id'];
    }

    /* Filter usage rows down to in-use-but-unregistered. */
    foreach ($usageRows as $u) {
        $name = (string)$u['Name'];
        if ($name === '') continue;
        if (isset($registryByName[$name])) continue;
        if ((int)$u['TotalUsage'] < $minUses) continue;
        if ($searchQ !== '' && stripos($name, $searchQ) === false) continue;
        $candidates[] = [
            'name'        => $name,
            'writers'     => (int)$u['WriterCount'],
            'composers'   => (int)$u['ComposerCount'],
            'arrangers'   => (int)$u['ArrangerCount'],
            'adaptors'    => (int)$u['AdaptorCount'],
            'translators' => (int)$u['TranslatorCount'],
            'total'       => (int)$u['TotalUsage'],
            'matches'     => [],     /* fuzzy → existing registry rows */
            'twins'       => [],     /* fuzzy → other candidates */
        ];
    }

    /* Sort candidates by total usage DESC so high-impact names lead. */
    usort($candidates, static fn($a, $b) => $b['total'] <=> $a['total']);

    /* Compute fuzzy matches against the registry. */
    foreach ($candidates as &$c) {
        foreach ($registryRows as $r) {
            $score = _cpBulkSimilarity($c['name'], (string)$r['Name']);
            if ($score >= $threshold) {
                $c['matches'][] = [
                    'id'    => (int)$r['Id'],
                    'name'  => (string)$r['Name'],
                    'score' => round($score, 3),
                ];
            }
        }
        usort($c['matches'], static fn($a, $b) => $b['score'] <=> $a['score']);
    }
    unset($c);

    /* Compute candidate-vs-candidate twin matches (sub-quadratic on the
       row count we expect in practice; cheap to skip if huge — guarded
       below for catastrophic cases). */
    $candCount = count($candidates);
    if ($candCount <= 2000) {
        for ($i = 0; $i < $candCount; $i++) {
            for ($j = $i + 1; $j < $candCount; $j++) {
                $score = _cpBulkSimilarity($candidates[$i]['name'], $candidates[$j]['name']);
                if ($score >= $threshold) {
                    $candidates[$i]['twins'][] = ['name' => $candidates[$j]['name'], 'score' => round($score, 3)];
                    $candidates[$j]['twins'][] = ['name' => $candidates[$i]['name'], 'score' => round($score, 3)];
                }
            }
        }
    }
} catch (\Throwable $e) {
    error_log('[credit-people-bulk-promote read] ' . $e->getMessage());
    $error = 'Could not load candidates: ' . $e->getMessage();
}

/* Pre-render registry select options once (shared across rows). */
$registryOptions = [];
if (!empty($registryByName)) {
    $registryOptions = $registryByName;
    asort($registryOptions, SORT_NATURAL | SORT_FLAG_CASE);
    $registryOptions = array_keys($registryOptions);
}

?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrf) ?>">
    <title>Bulk Promote Credit People — iHymns Admin</title>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-libs.php'; ?>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-favicon.php'; ?>
</head>
<body>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-nav.php'; ?>

    <div class="container-admin py-4">
        <h1 class="h4 mb-1">
            <i class="bi bi-people me-2"></i>Bulk Promote Credit People
        </h1>
        <p class="text-secondary small mb-3">
            Names that appear on a song-credit row but don't yet have a <code>tblCreditPeople</code> registry entry.
            Bulk-promote in one submit. Fuzzy matches against existing registry rows are flagged so you can
            <em>merge</em> typos / spelling-variants into the canonical row instead of registering duplicates.
            <a href="/manage/credit-people" class="link-secondary">← back to Credit People</a>
        </p>

        <?php if ($success): ?>
            <div class="alert alert-success py-2"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <!-- Filters -->
        <form method="GET" class="card-admin p-3 mb-3">
            <div class="row g-2 align-items-end">
                <div class="col-sm-4">
                    <label class="form-label small">Search candidate name</label>
                    <input type="search" name="q" value="<?= htmlspecialchars($searchQ) ?>"
                           class="form-control form-control-sm" placeholder="e.g. Newton, J">
                </div>
                <div class="col-sm-3">
                    <label class="form-label small">Match threshold</label>
                    <input type="number" name="threshold" value="<?= htmlspecialchars((string)$threshold) ?>"
                           min="0.5" max="1.0" step="0.01"
                           class="form-control form-control-sm">
                    <div class="form-text small">Higher = stricter. Default 0.85.</div>
                </div>
                <div class="col-sm-3">
                    <label class="form-label small">Min total uses</label>
                    <input type="number" name="min_uses" value="<?= (int)$minUses ?>"
                           min="1" max="9999"
                           class="form-control form-control-sm">
                </div>
                <div class="col-sm-2">
                    <button type="submit" class="btn btn-secondary btn-sm w-100">
                        <i class="bi bi-funnel me-1"></i>Apply
                    </button>
                </div>
            </div>
        </form>

        <!-- Stats line -->
        <p class="text-muted small mb-2">
            <?= number_format(count($candidates)) ?> candidate name<?= count($candidates) === 1 ? '' : 's' ?>.
            <?= number_format(count(array_filter($candidates, static fn($c) => !empty($c['matches'])))) ?> with a possible registry match.
            <?= number_format(count(array_filter($candidates, static fn($c) => !empty($c['twins'])))) ?> with twins among candidates.
        </p>

        <?php if (empty($candidates)): ?>
            <div class="card-admin p-4 text-center text-muted">
                <i class="bi bi-check2-circle fs-1 text-success" aria-hidden="true"></i>
                <p class="mb-0 mt-2">Every in-use credit name is already registered.</p>
            </div>
        <?php else: ?>

        <form method="POST" id="bulk-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="bulk_promote">

            <div class="d-flex flex-wrap gap-2 mb-3">
                <button type="button" class="btn btn-outline-info btn-sm" data-action="auto-resolve"
                        title="Pre-pick the highest-scoring registry match for every candidate that has one above the threshold.">
                    <i class="bi bi-magic me-1"></i>Auto-resolve flagged matches
                </button>
                <button type="button" class="btn btn-outline-secondary btn-sm" data-action="set-all" data-value="register">
                    <i class="bi bi-plus-circle me-1"></i>Set all to <em>Register as new</em>
                </button>
                <button type="button" class="btn btn-outline-secondary btn-sm" data-action="set-all" data-value="skip">
                    <i class="bi bi-dash-circle me-1"></i>Set all to <em>Skip</em>
                </button>
                <button type="submit" class="btn btn-amber btn-sm ms-auto">
                    <i class="bi bi-check2-square me-1"></i>Promote selected
                </button>
            </div>

            <div class="card-admin p-0">
                <table class="table table-sm table-hover align-middle mb-0 cp-sortable admin-table-responsive">
                    <thead>
                        <tr class="text-muted small">
                            <th data-col-priority="primary"   data-sort-key="name"    data-sort-type="text">Candidate name</th>
                            <th data-col-priority="primary"   class="text-center" data-sort-key="total" data-sort-type="number">Uses</th>
                            <th data-col-priority="secondary" class="text-center">Roles</th>
                            <th data-col-priority="tertiary"  data-sort-key="best" data-sort-type="number">Best match</th>
                            <th data-col-priority="primary"   style="min-width:18rem;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($candidates as $c):
                            $hasMatch = !empty($c['matches']);
                            $bestScore = $hasMatch ? $c['matches'][0]['score'] : 0;
                        ?>
                            <tr data-candidate-name="<?= htmlspecialchars($c['name']) ?>">
                                <td data-col-priority="primary">
                                    <strong><?= htmlspecialchars($c['name']) ?></strong>
                                    <?php if (!empty($c['twins'])): ?>
                                        <div class="small text-warning" title="Other candidates that look similar — fold them in this run rather than registering both">
                                            <i class="bi bi-arrows-collapse me-1" aria-hidden="true"></i>
                                            Similar candidate<?= count($c['twins']) === 1 ? '' : 's' ?>:
                                            <?= htmlspecialchars(implode(', ', array_map(static fn($t) => $t['name'] . ' ' . round($t['score'] * 100) . '%', $c['twins']))) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td data-col-priority="primary"   class="text-center" data-sort-value="<?= (int)$c['total'] ?>">
                                    <strong><?= number_format($c['total']) ?></strong>
                                </td>
                                <td data-col-priority="secondary" class="text-center small">
                                    <?php $roleChip = static function (string $label, int $n): string {
                                        if ($n <= 0) return '';
                                        return '<span class="badge bg-secondary-subtle text-secondary-emphasis me-1">' . htmlspecialchars($label) . '·' . $n . '</span>';
                                    }; ?>
                                    <?= $roleChip('W',  $c['writers']) ?>
                                    <?= $roleChip('C',  $c['composers']) ?>
                                    <?= $roleChip('Ar', $c['arrangers']) ?>
                                    <?= $roleChip('Ad', $c['adaptors']) ?>
                                    <?= $roleChip('T',  $c['translators']) ?>
                                </td>
                                <td data-col-priority="tertiary" data-sort-value="<?= number_format($bestScore, 3, '.', '') ?>">
                                    <?php if ($hasMatch): ?>
                                        <span class="text-warning small">
                                            <i class="bi bi-question-diamond me-1" aria-hidden="true"></i>
                                            <?= htmlspecialchars($c['matches'][0]['name']) ?>
                                            <small class="text-muted">(<?= round($c['matches'][0]['score'] * 100) ?>%)</small>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted small">no match</span>
                                    <?php endif; ?>
                                </td>
                                <td data-col-priority="primary">
                                    <div class="d-flex flex-column gap-1">
                                        <select name="row_action[<?= htmlspecialchars($c['name']) ?>]"
                                                class="form-select form-select-sm row-action-select"
                                                data-default-value="<?= $hasMatch ? 'merge' : 'register' ?>">
                                            <option value="register" <?= $hasMatch ? '' : 'selected' ?>>Register as new</option>
                                            <option value="merge"   <?= $hasMatch ? 'selected' : '' ?>>Merge into existing…</option>
                                            <option value="skip">Skip</option>
                                        </select>
                                        <select name="merge_to[<?= htmlspecialchars($c['name']) ?>]"
                                                class="form-select form-select-sm row-merge-select"
                                                <?= $hasMatch ? '' : 'disabled' ?>>
                                            <option value="">— pick target —</option>
                                            <?php foreach ($c['matches'] as $m): ?>
                                                <option value="<?= (int)$m['id'] ?>" data-score="<?= number_format($m['score'], 3, '.', '') ?>">
                                                    <?= htmlspecialchars($m['name']) ?> (<?= round($m['score'] * 100) ?>%)
                                                </option>
                                            <?php endforeach; ?>
                                            <?php if ($hasMatch): ?>
                                                <option disabled>──────────</option>
                                            <?php endif; ?>
                                            <?php foreach ($registryOptions as $regName):
                                                /* Skip rows already shown above as fuzzy matches. */
                                                $alreadyShown = false;
                                                foreach ($c['matches'] as $m) { if ($m['name'] === $regName) { $alreadyShown = true; break; } }
                                                if ($alreadyShown) continue;
                                            ?>
                                                <option value="<?= (int)($registryByName[$regName] ?? 0) ?>">
                                                    <?= htmlspecialchars($regName) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="d-flex mt-3">
                <button type="submit" class="btn btn-amber ms-auto">
                    <i class="bi bi-check2-square me-1"></i>Promote selected
                </button>
            </div>
        </form>

        <?php endif; ?>
    </div>

    <script>
    (function () {
        /* Toggle the merge-target select disabled state with the row's
           action picker. */
        document.querySelectorAll('.row-action-select').forEach(sel => {
            const row = sel.closest('tr');
            const target = row?.querySelector('.row-merge-select');
            const sync = () => {
                if (!target) return;
                target.disabled = (sel.value !== 'merge');
                if (sel.value === 'merge' && !target.value) {
                    /* Pre-pick the first option that isn't the placeholder. */
                    for (const opt of target.options) {
                        if (opt.value && !opt.disabled) { target.value = opt.value; break; }
                    }
                }
            };
            sel.addEventListener('change', sync);
            sync();
        });

        /* Bulk-action buttons. */
        document.addEventListener('click', (ev) => {
            const btn = ev.target.closest('[data-action]');
            if (!btn) return;
            const action = btn.getAttribute('data-action');
            if (action === 'auto-resolve') {
                document.querySelectorAll('.row-action-select').forEach(sel => {
                    const row = sel.closest('tr');
                    const merge = row?.querySelector('.row-merge-select');
                    if (merge && merge.options.length > 1) {
                        sel.value = 'merge';
                        sel.dispatchEvent(new Event('change'));
                    }
                });
            } else if (action === 'set-all') {
                const value = btn.getAttribute('data-value');
                document.querySelectorAll('.row-action-select').forEach(sel => {
                    sel.value = value;
                    sel.dispatchEvent(new Event('change'));
                });
            }
        });
    })();
    </script>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>
</body>
</html>
