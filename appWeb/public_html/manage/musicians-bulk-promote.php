<?php

declare(strict_types=1);

/**
 * iHymns — Admin: Add Musicians in Bulk (formerly "Bulk Promote Credit People", #846; display renamed #1784)
 *
 * Companion surface to /manage/musicians. Lists every distinct
 * name used on a song-credit row that doesn't yet have a matching
 * tblMusicians row, runs fuzzy-similarity against the registry +
 * the candidate set itself, and lets the curator promote dozens /
 * hundreds of in-use names in one submit.
 *
 * Each candidate gets one of three actions:
 *
 *   register — create a fresh tblMusicians row.
 *   merge    — re-point every credit on every song to the existing
 *              registry row's Name (uses the same UPDATE pattern the
 *              single-row merge action uses on /manage/musicians).
 *   skip     — leave the credit rows alone.
 *
 * Auto-resolve pre-picks the highest-scoring registry match above a
 * configurable threshold; the curator reviews + adjusts before
 * submitting.
 *
 * Gated by `manage_musicians` (same as the parent page).
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_mysql.php';
/* Shared duplicate/counterpart similarity scorer (#1216, rule #22) — this
   page used to carry its own private _musBulkNormalise()/_musBulkTokens()/
   _musBulkSimilarity() fork of the same maths (its own doc-comment even
   said "mirrors the scoring shape from build-song-link-suggestions.php" —
   i.e. it already knew it was a fork). #1785 C3 deletes that fork and
   points every call site at the shared ihymns_sim_name_*() functions
   instead (#1785 C2 added the NAME-scoring section this page needs). */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'song_similarity.php';
/* #1785 C5 — MUSICIAN_CREDIT_ROLE_TABLES (the six-role credit-table map,
   rule #22/#35) + registerMusicianByName(). Hoisted here (was previously
   require_once'd only inside the POST 'register' branch below) because
   the GET candidate scan and the POST 'merge' branch both need the
   shared constant now that this page's own private _CP_BULK_CREDIT_TABLES
   five-table copy is retired in favour of it. require_once is idempotent. */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'musician_helpers.php';
/* #1785 C8 — musicianDisambiguationPayloadBulk(), the ONE shared
   disambiguation-payload builder every #1785 merge affordance consumes
   (rule #22 — never re-forked per surface); this page's fuzzy-match
   option labels use it to show #id/lifespan/use-count (plan §8.3).
   require_once is idempotent. */
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
$activePage = 'musicians-bulk-promote';

$error   = '';
$success = '';
$db      = getDbMysqli();
$csrf    = csrfToken();

/* ----- POST: bulk promote ----- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    /* #1785 C3 — validateCsrfRequest() (rule #29) replaces the baked-
       token-only validateCsrf(). Strict widening: this form still posts
       csrf_token on every submit (unchanged below), so the existing
       session-token path keeps working byte-for-byte; the same-origin
       X-Requested-With path is additive should a future fetch()-based
       submit ever replace the plain <form method="POST"> this page uses
       today. */
    if (!validateCsrfRequest((string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo 'Invalid CSRF token';
        exit;
    }
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'bulk_promote') {
        $rowAction = $_POST['row_action'] ?? [];   /* keyed by candidate name → 'register'|'merge'|'skip' */
        $mergeTo   = $_POST['merge_to']   ?? [];   /* candidate name → existing tblMusicians.Id */
        if (!is_array($rowAction)) $rowAction = [];
        if (!is_array($mergeTo))   $mergeTo   = [];

        $bulkRunId  = bin2hex(random_bytes(6));
        $registered = 0;
        $merged     = 0;
        $skipped    = 0;
        $failed     = 0;

        $db->begin_transaction();
        try {
            foreach ($rowAction as $rawName => $act) {
                /* #1784 — keep the EXACT bytes the form posted ($origName) as
                   well as the trimmed identity ($name). The candidate may carry
                   stray whitespace ("Eddie James " with a trailing space); the
                   merge branch below needs the original bytes to re-point the
                   real credit rows, while register/skip use the tidy form. */
                $origName = (string)$rawName;
                $name = trim($origName);
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
                    $stmt = $db->prepare('SELECT Id FROM tblMusicians WHERE Name = ?');
                    $stmt->bind_param('s', $name);
                    $stmt->execute();
                    $existing = $stmt->get_result()->fetch_row();
                    $stmt->close();
                    if ($existing) { $skipped++; continue; }

                    /* Route through the shared registry helper so the
                       new row carries a Slug — direct
                       `INSERT (Name)` would default Slug='' and
                       collide on uk_Slug after the first such row.
                       (musician_helpers.php is now require_once'd at the
                       top of this file — #1785 C5 — so no per-branch
                       require needed here any more.) */
                    $newId = registerMusicianByName($db, $name);

                    if (function_exists('logActivity')) {
                        logActivity('musician.bulk_register', 'musician', (string)$newId, [
                            'name'         => $name,
                            'bulk_run_id'  => $bulkRunId,
                        ]);
                    }
                    $registered++;
                } elseif ($act === 'merge') {
                    /* Tolerate either key form for the target lookup — the
                       browser may or may not have preserved stray whitespace in
                       the merge_to[] field name (#1784). */
                    $targetId = (int)($mergeTo[$origName] ?? $mergeTo[$name] ?? 0);
                    if ($targetId <= 0) { $failed++; continue; }

                    $stmt = $db->prepare('SELECT Name FROM tblMusicians WHERE Id = ?');
                    $stmt->bind_param('i', $targetId);
                    $stmt->execute();
                    $row = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    if (!$row) { $failed++; continue; }
                    $targetName = (string)$row['Name'];

                    /* Re-point every song-credit row from the candidate name to
                       the registry target's EXACT spelling across the SIX join
                       tables (#1785 C5 — MUSICIAN_CREDIT_ROLE_TABLES; was five,
                       missing tblSongArtists). #1784: match on the collation
                       (`Name = ?`) so a stray-byte variant ("Eddie James " with
                       a trailing space) IS caught — the old code trimmed the
                       candidate, saw it now equalled the target, and skipped as
                       "nothing to do", which is exactly why the "Eddie James →
                       Eddie James" merge could never clear the counter.
                       `BINARY Name <> BINARY ?` excludes rows already
                       byte-correct so a genuine no-op merge still counts as
                       "skipped" rather than "merged". */
                    $rowsAffected = 0;
                    foreach (MUSICIAN_CREDIT_ROLE_TABLES as $tbl) {
                        $stmt = $db->prepare(
                            "UPDATE {$tbl} SET Name = ? WHERE Name = ? AND BINARY Name <> BINARY ?"
                        );
                        $stmt->bind_param('sss', $targetName, $origName, $targetName);
                        $stmt->execute();
                        $rowsAffected += $stmt->affected_rows;
                        $stmt->close();
                    }
                    if ($rowsAffected === 0) { $skipped++; continue; }

                    if (function_exists('logActivity')) {
                        logActivity('musician.bulk_merge', 'musician', (string)$targetId, [
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
            error_log('[musicians-bulk-promote] ' . $tx->getMessage());
            $error = 'Bulk run rolled back: ' . $tx->getMessage();
        }
    }
}

/* ----- GET render: candidates + suggestions ----- */
$threshold = max(0.5, min(1.0, (float)($_GET['threshold'] ?? 0.85)));
$minUses   = max(1, (int)($_GET['min_uses'] ?? 1));
$searchQ   = trim((string)($_GET['q'] ?? ''));

/* Q1 — every distinct name across the six song-credit tables (#1785 C5 —
   MUSICIAN_CREDIT_ROLE_TABLES; was five, missing tblSongArtists), with
   per-role counts. Matches the parent page's usage SQL. */
$candidates = [];
try {
    $creditUnion = implode("\n              UNION ALL\n              ", array_map(
        static fn(string $role, string $tbl): string =>
            "SELECT Name, '{$role}' AS kindLabel, COUNT(*) AS cnt FROM {$tbl} GROUP BY Name",
        array_keys(MUSICIAN_CREDIT_ROLE_TABLES),
        array_values(MUSICIAN_CREDIT_ROLE_TABLES)
    ));
    $usageSql = "
        SELECT Name,
               SUM(IF(kindLabel = 'writer',     cnt, 0)) AS WriterCount,
               SUM(IF(kindLabel = 'composer',   cnt, 0)) AS ComposerCount,
               SUM(IF(kindLabel = 'arranger',   cnt, 0)) AS ArrangerCount,
               SUM(IF(kindLabel = 'adaptor',    cnt, 0)) AS AdaptorCount,
               SUM(IF(kindLabel = 'translator', cnt, 0)) AS TranslatorCount,
               SUM(IF(kindLabel = 'artist',     cnt, 0)) AS ArtistCount,
               SUM(cnt) AS TotalUsage
          FROM (
              {$creditUnion}
          ) u
         GROUP BY Name
    ";
    $usageRows = $db->query($usageSql)->fetch_all(MYSQLI_ASSOC);

    /* Q2 — registry rows (id + name only — bulk page doesn't need the bio bits). */
    $registryRows = $db->query('SELECT Id, Name FROM tblMusicians')->fetch_all(MYSQLI_ASSOC);
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
            'artists'     => (int)$u['ArtistCount'], // #1785 C5
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
            $score = ihymns_sim_name_score($c['name'], (string)$r['Name']);
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

    /* #1785 C8 (plan §8.3) — disambiguate every fuzzy-match option so a
       curator can tell "which registry row is this?" instead of a bare
       name: #id, born/died, use-count (the SAME shared payload builder
       every other #1785 disambiguation surface uses, rule #22 — never
       re-forked), plus the byte-variant classification of the match
       AGAINST THIS CANDIDATE's own name (musicianNameVariantClass()) so
       the option can also say WHY the two look alike. ONE bulk round-trip
       for every matched registry id across the whole page, not per-row. */
    $matchedIds = [];
    foreach ($candidates as $c) {
        foreach ($c['matches'] as $m) { $matchedIds[$m['id']] = true; }
    }
    $matchPayloads = $matchedIds ? musicianDisambiguationPayloadBulk($db, array_keys($matchedIds)) : [];
    foreach ($candidates as &$c) {
        foreach ($c['matches'] as &$m) {
            $p = $matchPayloads[$m['id']] ?? null;
            $m['born']           = $p['born']           ?? null;
            $m['died']           = $p['died']            ?? null;
            $m['disambiguation'] = $p['disambiguation']  ?? '';
            $m['useCount']       = $p['useCount']         ?? 0;
            $m['variant']        = musicianNameVariantClass($c['name'], $m['name']);
        }
        unset($m);
    }
    unset($c);

    /* Compute candidate-vs-candidate twin matches (sub-quadratic on the
       row count we expect in practice; cheap to skip if huge — guarded
       below for catastrophic cases). */
    $candCount = count($candidates);
    if ($candCount <= 2000) {
        for ($i = 0; $i < $candCount; $i++) {
            for ($j = $i + 1; $j < $candCount; $j++) {
                $score = ihymns_sim_name_score($candidates[$i]['name'], $candidates[$j]['name']);
                if ($score >= $threshold) {
                    $candidates[$i]['twins'][] = ['name' => $candidates[$j]['name'], 'score' => round($score, 3)];
                    $candidates[$j]['twins'][] = ['name' => $candidates[$i]['name'], 'score' => round($score, 3)];
                }
            }
        }
    }
} catch (\Throwable $e) {
    error_log('[musicians-bulk-promote read] ' . $e->getMessage());
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
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrf) ?>">
    <title>Add Musicians in Bulk — iHymns Admin</title>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-libs.php'; ?>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-favicon.php'; ?>
</head>
<body>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-nav.php'; ?>

    <div class="container-admin py-4">
        <h1 class="h4 mb-1">
            <i class="bi bi-people me-2"></i>Add Musicians in Bulk
        </h1>
        <p class="text-secondary small mb-3">
            Names that appear on a song credit but aren't in your Musicians list yet.
            Add them all in one submit. Where a name closely matches one you already have, it's flagged so you can
            <em>merge</em> typos / spelling-variants into the existing musician instead of creating a duplicate.
            <a href="/manage/musicians" class="link-secondary">← back to Musicians</a>
            <!-- #1785 §7 — cross-link to the registry-duplicate review page. A
                 DIFFERENT job: this page finds cited-but-UNREGISTERED names;
                 /manage/musician-duplicates finds registry rows that are
                 probably the same person spelled two ways. -->
            &middot; Looking for duplicates <em>already in</em> the registry (not cited names waiting to be added)?
            <a href="/manage/musician-duplicates" class="link-secondary">Review musician duplicates →</a>
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
                            <th data-col-priority="secondary" class="text-center" data-sort-key="roles" data-sort-type="number">Roles</th>
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
                                <td data-col-priority="secondary" class="text-center small" data-sort-value="<?= (int)($c['writers'] + $c['composers'] + $c['arrangers'] + $c['adaptors'] + $c['translators'] + $c['artists']) ?>">
                                    <?php $roleChip = static function (string $label, int $n): string {
                                        if ($n <= 0) return '';
                                        return '<span class="badge bg-secondary-subtle text-secondary-emphasis me-1">' . htmlspecialchars($label) . '·' . $n . '</span>';
                                    }; ?>
                                    <?= $roleChip('W',  $c['writers']) ?>
                                    <?= $roleChip('C',  $c['composers']) ?>
                                    <?= $roleChip('Ar', $c['arrangers']) ?>
                                    <?= $roleChip('Ad', $c['adaptors']) ?>
                                    <?= $roleChip('T',  $c['translators']) ?>
                                    <?= $roleChip('Art', $c['artists']) ?>
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
                                    <?php
                                        /* Single-dropdown UX (replaces the legacy dual-select that
                                           pre-#claude/bulk-promote-ux silently auto-merged every
                                           no-match row into whichever registry person sorted first
                                           alphabetically — a data-destruction footgun, fixed in PR #980
                                           and then folded down to this single picker so the curator's
                                           choice is always concrete and explicit).

                                           Option values:
                                             register      — INSERT a brand-new tblMusicians row.
                                             merge:<id>    — fold this candidate into the named registry
                                                             row. The numeric Id is split out by the
                                                             form-submit JS into the legacy `merge_to[]`
                                                             POST shape the server still consumes
                                                             (kept unchanged so this PR is UX-only).
                                             other         — reveal the secondary picker showing the
                                                             full registry list for rows where none of
                                                             the auto-surfaced fuzzy matches is the
                                                             right target.
                                             skip          — leave this candidate alone this run.

                                           Default pre-selection:
                                             - With a fuzzy match → merge:<best-match-id> (the
                                               highest-scoring suggestion is the obvious default).
                                             - Without a fuzzy match → register (the safe answer for
                                               unknown names — never silently inherit a registry
                                               person the curator didn't see). */
                                        $rawName    = $c['name'];
                                        $bestId     = $hasMatch ? (int)$c['matches'][0]['id'] : 0;
                                        $defaultVal = $hasMatch ? "merge:{$bestId}" : 'register';

                                        /* Pre-compute the "other registry person" list — every
                                           registry row that ISN'T already surfaced as a fuzzy
                                           match. Rendered into the secondary picker so a curator
                                           CAN reach the full registry when none of the suggested
                                           merge targets is right. Hidden by default. */
                                        $otherRegistry = [];
                                        foreach ($registryOptions as $regName) {
                                            $alreadyShown = false;
                                            foreach ($c['matches'] as $m) {
                                                if ($m['name'] === $regName) { $alreadyShown = true; break; }
                                            }
                                            if (!$alreadyShown) $otherRegistry[] = $regName;
                                        }
                                    ?>
                                    <div class="d-flex flex-column gap-1">
                                        <select class="form-select form-select-sm row-action-select"
                                                data-row-default="<?= htmlspecialchars($defaultVal, ENT_QUOTES, 'UTF-8') ?>">
                                            <option value="register" <?= $defaultVal === 'register' ? 'selected' : '' ?>>
                                                Register as new
                                            </option>
                                            <?php foreach ($c['matches'] as $m):
                                                $optVal = 'merge:' . (int)$m['id'];
                                                /* #1785 C8 (plan §8.3) — "#id · b. 1961 · 12 credits" so a curator
                                                   can tell WHICH registry row this is, not just its bare name —
                                                   the same class of fix as the parent page's typeahead labels. An
                                                   <option> can't hold markup, so the byte-variant classification
                                                   (musicianNameVariantClass()) rides a data-variant attribute the
                                                   JS below surfaces as a small text hint under the select once
                                                   this option is chosen, rather than inline in the label itself. */
                                                $matchBits = ['#' . (int)$m['id']];
                                                if (!empty($m['born'])) { $matchBits[] = 'b. ' . $m['born']; }
                                                if (!empty($m['died'])) { $matchBits[] = 'd. ' . $m['died']; }
                                                if (!empty($m['useCount'])) { $matchBits[] = $m['useCount'] . ' credit' . ($m['useCount'] === 1 ? '' : 's'); }
                                                if (!empty($m['disambiguation'])) { $matchBits[] = $m['disambiguation']; }
                                            ?>
                                                <option value="<?= htmlspecialchars($optVal, ENT_QUOTES, 'UTF-8') ?>"
                                                        data-score="<?= number_format($m['score'], 3, '.', '') ?>"
                                                        data-variant="<?= htmlspecialchars((string)($m['variant'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                                        <?= $optVal === $defaultVal ? 'selected' : '' ?>>
                                                    Merge into <?= htmlspecialchars($m['name']) ?>
                                                    — <?= htmlspecialchars(implode(' · ', $matchBits), ENT_QUOTES, 'UTF-8') ?>
                                                    (<?= round($m['score'] * 100) ?>%)
                                                </option>
                                            <?php endforeach; ?>
                                            <?php if ($otherRegistry): ?>
                                                <option value="other">Merge into other registry person…</option>
                                            <?php endif; ?>
                                            <option value="skip">Skip</option>
                                        </select>

                                        <!-- #1785 C8 (plan §8.3) — variant hint for the currently-chosen
                                             merge-target option, populated from its data-variant attribute
                                             (an <option> can't hold markup, so the badge lives here instead). -->
                                        <div class="small text-info row-variant-hint d-none"></div>

                                        <!-- Secondary registry picker. Only shown after the curator
                                             picks "Merge into other registry person…" in the main
                                             select. Renders the full registry list (excluding
                                             names already surfaced as fuzzy matches above) so the
                                             curator can reach an unsuggested target without
                                             leaving the page. -->
                                        <select class="form-select form-select-sm row-other-select d-none"
                                                aria-label="Choose other registry person">
                                            <option value="">— pick registry person —</option>
                                            <?php foreach ($otherRegistry as $regName): ?>
                                                <option value="<?= (int)($registryByName[$regName] ?? 0) ?>">
                                                    <?= htmlspecialchars($regName) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>

                                        <!-- Hidden inputs that carry the POST shape the server
                                             expects (row_action + merge_to). The JS keeps them
                                             in sync with the visible select on every change so
                                             form-submit doesn't need a serialise step. -->
                                        <input type="hidden" name="row_action[<?= htmlspecialchars($rawName) ?>]"
                                               class="row-action-hidden"
                                               value="<?= $defaultVal === 'register' ? 'register' : ($defaultVal === 'skip' ? 'skip' : 'merge') ?>">
                                        <input type="hidden" name="merge_to[<?= htmlspecialchars($rawName) ?>]"
                                               class="row-merge-hidden"
                                               value="<?= $hasMatch ? (int)$c['matches'][0]['id'] : '' ?>">
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
        /* Single-dropdown bulk-promote UX (#claude/bulk-promote-ux).
           Replaces the pre-#980 dual-select that silently auto-merged
           every no-match row into whichever registry person sorted
           first alphabetically — a data-destruction footgun. PR #980
           added defensive gating; this rewrite folds the merge-target
           list into the action select so every option the curator sees
           is concrete and explicit.

           Each row has:
             - one visible <select class="row-action-select"> whose
               options carry one of:
                   register
                   merge:<targetId>   (one per fuzzy match, sorted by score)
                   other              (reveals .row-other-select)
                   skip
             - one hidden <select class="row-other-select"> with the
               full registry list, shown only after picking 'other'.
             - two hidden <input> fields:
                   .row-action-hidden  (name="row_action[<name>]")
                   .row-merge-hidden   (name="merge_to[<name>]")
               that carry the POST shape the server still consumes
               unchanged (kept stable so this PR is UX-only). */
        const isFuzzyMatchOption = (opt) =>
            opt && opt.value && opt.value.startsWith('merge:') && opt.hasAttribute('data-score');

        /* #1785 C8 (plan §8.3) — human-readable label for a
           musicianNameVariantClass() key, mirroring MUSICIAN_NAME_
           VARIANT_LABELS (includes/musician_helpers.php) so the wording
           matches every other #1785 surface that renders this badge —
           never re-worded per call site. */
        const VARIANT_LABELS = {
            'identical': 'identical bytes',
            'unicode-normalisation': 'differs only by Unicode form (combining vs. precomposed accent)',
            'whitespace': 'differs only by invisible spacing',
            'case': 'differs only by letter case',
            'punctuation': 'differs only by punctuation/accents',
        };

        function rowParts(rowOrSel) {
            const row = rowOrSel.closest ? rowOrSel.closest('tr') : rowOrSel;
            return {
                row,
                main:   row.querySelector('.row-action-select'),
                other:  row.querySelector('.row-other-select'),
                hAct:   row.querySelector('.row-action-hidden'),
                hMerge: row.querySelector('.row-merge-hidden'),
                hint:   row.querySelector('.row-variant-hint'),
            };
        }

        /* #1785 C8 — show/hide the variant hint for whichever merge-target
           option is currently selected. A "different words" fuzzy match
           (no variant) or a non-merge action (register/skip/other) both
           hide the hint — it only makes sense next to an actual byte-
           variant merge candidate. */
        function syncVariantHint(p) {
            if (!p.hint) return;
            const opt = p.main.selectedOptions && p.main.selectedOptions[0];
            const variant = opt ? opt.getAttribute('data-variant') : '';
            if (!variant) { p.hint.classList.add('d-none'); p.hint.textContent = ''; return; }
            p.hint.textContent = variant + ' — ' + (VARIANT_LABELS[variant] || variant);
            p.hint.classList.remove('d-none');
        }

        /* Sync hidden POST fields to whatever the visible selects
           currently carry. Runs on every change of either select and
           on initial page load so the first form-submit always reflects
           the curator's actual choice. */
        function syncRow(p) {
            syncVariantHint(p);
            const val = p.main.value;
            if (val === 'register' || val === 'skip') {
                p.hAct.value = val;
                p.hMerge.value = '';
                p.other.classList.add('d-none');
                return;
            }
            if (val === 'other') {
                p.other.classList.remove('d-none');
                /* Until the curator picks a target, leave the hidden
                   merge_to empty — the server's per-row merge handler
                   rejects merge with targetId<=0 as 'failed' so the
                   row simply doesn't promote. Far better than silently
                   merging into the first registry option. */
                p.hAct.value = 'merge';
                p.hMerge.value = p.other.value || '';
                return;
            }
            if (val.startsWith('merge:')) {
                p.hAct.value = 'merge';
                p.hMerge.value = val.slice('merge:'.length);
                p.other.classList.add('d-none');
                return;
            }
            /* Defensive fallback — unknown shape collapses to Skip
               rather than to a random merge. */
            p.hAct.value = 'skip';
            p.hMerge.value = '';
            p.other.classList.add('d-none');
        }

        document.querySelectorAll('.row-action-select').forEach(sel => {
            const p = rowParts(sel);
            sel.addEventListener('change', () => syncRow(p));
            if (p.other) {
                p.other.addEventListener('change', () => {
                    if (p.main.value === 'other') syncRow(p);
                });
            }
            syncRow(p);
        });

        /* Bulk action buttons. */
        document.addEventListener('click', (ev) => {
            const btn = ev.target.closest('[data-action]');
            if (!btn) return;
            const action = btn.getAttribute('data-action');
            if (action === 'auto-resolve') {
                /* Pick the highest-scoring fuzzy-match option on every
                   row that has one. Rows with no fuzzy match are NOT
                   touched (they stay on whatever the curator had —
                   typically Register-as-new) so the no-data-destruction
                   guarantee from PR #980 holds even after this rewrite. */
                let flipped = 0;
                document.querySelectorAll('.row-action-select').forEach(sel => {
                    let bestOpt = null;
                    let bestScore = -1;
                    for (const opt of sel.options) {
                        if (!isFuzzyMatchOption(opt)) continue;
                        const s = parseFloat(opt.getAttribute('data-score') || '0');
                        if (s > bestScore) { bestScore = s; bestOpt = opt; }
                    }
                    if (bestOpt) {
                        sel.value = bestOpt.value;
                        sel.dispatchEvent(new Event('change'));
                        flipped++;
                    }
                });
                if (flipped === 0) {
                    window.alert(
                        'No candidate rows have a flagged fuzzy match to auto-resolve.\n\n'
                        + 'Lower the Match threshold (or raise Min total uses) to surface '
                        + 'more potential matches, or set the candidates individually.'
                    );
                }
            } else if (action === 'set-all') {
                /* "Set all to Register as new" / "Set all to Skip"
                   buttons just dispatch a value-set + change event. */
                const value = btn.getAttribute('data-value');
                document.querySelectorAll('.row-action-select').forEach(sel => {
                    sel.value = value;
                    sel.dispatchEvent(new Event('change'));
                });
            }
        });
    })();
    </script>

    <!-- Sortable table headers (#1786 sweep / #1799 fix — this table was
         tagged `mus-sortable`, which the module never matches (default
         selector is `table.cp-sortable`), AND the page never booted the
         module at all, so header clicks were a silent no-op either way. -->
    <script type="module">
        import { bootSortableTables } from '/js/modules/admin-table-sort.js?v=<?= filemtime(dirname(__DIR__) . '/js/modules/admin-table-sort.js') ?>';
        bootSortableTables();
    </script>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>
</body>
</html>
