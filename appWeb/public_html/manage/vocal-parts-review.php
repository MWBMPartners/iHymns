<?php

declare(strict_types=1);

/**
 * iHymns — Admin: Voice-part suggestions review queue (#2073 commit 15)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * A lot of OLD lyrics have a line like "WOMEN" or "MEN: You are holy,"
 * typed straight into the words, because whoever typed the song up years
 * ago had no other way to say "the women sing this bit". A batch job
 * (`appWeb/.sql/migrate-backfill-vocal-part-suggestions.php`, #2073 commit
 * 14) already read every song and wrote each one it found into
 * `tblVocalPartSuggestions` as a SUGGESTION — a guess, not a change. THIS
 * page is where a person looks at each guess and says yes (Accept — really
 * assign the voice part and tidy the marker text), no (Dismiss), or "wait,
 * put that back" (Undo, only after an Accept).
 *
 * WHY A PERSON HAS TO LOOK, NOT THE COMPUTER ALONE: a plain-text guess from
 * the SHAPE of a line can be wrong — "SOLO" could mean a solo singer or it
 * could be the section heading of a genuinely solo-shaped verse; a curator
 * with the whole song in front of them can tell the two apart in a second,
 * a heuristic cannot. This is the SAME "wrong is worse than missing"
 * principle the #2073 programme states for every other per-line decision it
 * makes — see `includes/vocal_part_review.php`'s own doc-block.
 *
 * WHAT THIS PAGE DOES, AND WHAT IT DELIBERATELY DOES NOT DO
 * ----------------------------------------------------------
 * This page does NO database writing of its own, and (beyond the ONE
 * read below) no database reading of its own either — every decision
 * (list, Accept, Dismiss, Undo, Rescan) is made by calling a function in
 * `includes/vocal_part_review.php`, which this page is instructed to CALL
 * and never edit or re-implement (CLAUDE.md rule #22 — one shared core, no
 * forked SQL). The one exception is display-only: to show a curator "the
 * line in context" (rule from the task brief — "a curator cannot judge
 * 'assign Women to lines 3-4' without seeing lines 3 and 4"), this page
 * reads a song's current lines via the ALREADY-SHARED, ALREADY-EXISTING
 * `lyricLinesEditableComponents()` (`includes/lyric_lines_read.php`, the
 * ONE lyric-line read path — rule #25) and locates the marker line inside
 * it with the review core's OWN exported pure helper,
 * `vocalPartReviewLocateLine()` — never a second lyric-line reader, never a
 * raw `$db->query()` against `tblLyricLines`/`tblSongComponents` here.
 *
 * WHY UNDO LIVES HERE, NOT IN THE SONG'S REVISION HISTORY
 * --------------------------------------------------------
 * The v2 editor's Revisions tab can restore an OLD snapshot of a song's
 * TEXT. That sounds like it should also undo an Accept — it does not,
 * and using it to try would make things WORSE, not better: a revision
 * snapshot has no idea a voice-part assignment exists at all (it is
 * api2-local, and voice-part rows live in a completely different table,
 * `tblLyricLineVocalParts`). Restoring an old revision would put the
 * marker TEXT back (e.g. re-insert the "WOMEN" line) while leaving the
 * voice-part ROWS Accept created BEHIND — a half-undo nobody would notice
 * happened, and now the song has both the marker line AND the voice
 * assignment, which is a worse mess than either on its own. The Undo
 * button on THIS page instead replays, in reverse, the EXACT steps Accept
 * itself recorded (`AppliedJson` — which lines were assigned to which
 * part, whether that part was newly created, and exactly what the marker
 * line used to say) — see `vocalPartReviewUndo()`'s own doc-block for the
 * full reversal.
 *
 * GATING
 * ------
 * Page view AND every action (Accept / Dismiss / Undo / Rescan) are all
 * gated on the SAME entitlement, `edit_songs` — the curator-level key this
 * page's own `admin-links.php` nav row advertises, so
 * `tests/php/test-admin-gate-parity.php`'s derived pairing holds (#1587).
 * There is no separate destructive/admin-only tier here: unlike a Merge or
 * a Purge, nothing this page does is irreversible — Undo exists precisely
 * so a curator-level Accept is always reversible by a curator, not just an
 * admin.
 *
 * @see appWeb/public_html/includes/vocal_part_review.php     the ONE core this page calls — list/accept/dismiss/undo/refresh
 * @see appWeb/public_html/includes/lyric_lines_read.php       lyricLinesEditableComponents() — the ONE lyric-line read path this page's preview reuses (rule #25)
 * @see appWeb/public_html/api.php                             admin_vocal_suggestion_list/_accept/_dismiss/_undo/_refresh_song — the native-app API twins of every action below (rule #48)
 * @see appWeb/.sql/migrate-backfill-vocal-part-suggestions.php the batch that fills the queue this page reviews
 * @see .claude/vocal-parts-2073-plan.md                        "Design pass 7" §11 (page spec) / "Design pass 6" §9 (page spec)
 * @see tests/php/test-vocal-parts-review-page.php              the structural + mutation-proven guard over this page
 * @see #2073, #2075, #1260
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_mysql.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'vocal_part_review.php';

if (!isAuthenticated()) {
    header('Location: /manage/login');
    exit;
}
$currentUser = getCurrentUser();
$role        = $currentUser['role'] ?? null;
/* Page gate = the entitlement the nav row advertises (admin-links.php) —
   test-admin-gate-parity.php fails the build if the two ever drift (#1587). */
if (!$currentUser || !userHasEntitlement('edit_songs', $role)) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><body><h1>403 — edit_songs required</h1></body></html>';
    exit;
}

$activePage = 'vocal-parts-review';
$db         = getDbMysqli();
$csrf       = csrfToken();
$userId     = (int)($currentUser['id'] ?? 0) ?: null;

/**
 * ELI5: build the little "here is what's actually written nearby" preview
 * for one suggestion — the marker line itself, plus the lines either side
 * of it, plus (when the proposal covers a RUN of lines, the `standalone`
 * form) the lines that would actually get the voice part.
 *
 * Composes ONLY existing, already-shared functions:
 *  - `lyricLinesEditableComponents()` (the ONE lyric-line read path) —
 *    called ONCE per song and cached by the caller, never per-row.
 *  - `vocalPartReviewLocateLine()` (the review core's OWN exported pure
 *    line-finder) — finds the marker by its real `tblLyricLines.Id`, never
 *    by remembering "the line used to be at position N" (the task brief's
 *    own per-line-identity warning: a curator's edit since the backfill
 *    ran can move or delete a line, and this page must never guess).
 *
 * @param list<array<string,mixed>> $editableComponents  one song's own `lyricLinesEditableComponents()` result.
 * @param array<string,mixed>       $s                    one `vocalPartReviewShape()` row.
 * @return array{stale:bool,lines:list<array{text:string,role:string}>,componentType:?string,componentNumber:?int}
 */
function vprReviewPreview(array $editableComponents, array $s): array
{
    $markerLineId = $s['markerLineId'];
    if ($markerLineId === null || $editableComponents === []) {
        return ['stale' => true, 'lines' => [], 'componentType' => null, 'componentNumber' => null];
    }

    $loc = vocalPartReviewLocateLine($editableComponents, $markerLineId);
    if ($loc === null) {
        return ['stale' => true, 'lines' => [], 'componentType' => null, 'componentNumber' => null];
    }
    [$ci, $li] = $loc;
    $comp    = $editableComponents[$ci];
    $lines   = is_array($comp['lines'] ?? null) ? $comp['lines'] : [];
    $lineIds = is_array($comp['lineIds'] ?? null) ? $comp['lineIds'] : [];
    $n       = count($lines);

    /* The TARGET run (may be null for a `standalone` marker that governs
       nothing, or equal to the marker line itself for `prefix`/`paren` —
       see vocalPartReviewBuildRow()'s own doc-block for why those two
       forms always set startLineId === endLineId === the marker line). */
    $si = null;
    $ei = null;
    if ($s['startLineId'] !== null) {
        $found = array_search($s['startLineId'], $lineIds, true);
        $si = $found !== false ? (int)$found : null;
    }
    if ($s['endLineId'] !== null) {
        $found = array_search($s['endLineId'], $lineIds, true);
        $ei = $found !== false ? (int)$found : null;
    }

    $lo = $li;
    $hi = $li;
    if ($si !== null) {
        $lo = min($lo, $si);
    }
    if ($ei !== null) {
        $hi = max($hi, $ei);
    }
    $windowStart = max(0, $lo - 1);
    $windowEnd   = min($n - 1, $hi + 1);
    /* Cap the window — a run that spans dozens of lines (rare, but possible
       for a "the choir sings the whole rest of the song" marker) should
       still render a SHORT, judgeable preview rather than dumping the
       entire remaining song onto the row. */
    if ($windowEnd - $windowStart > 12) {
        $windowEnd = min($n - 1, $windowStart + 12);
    }

    $out = [];
    for ($i = $windowStart; $i <= $windowEnd; $i++) {
        $role = 'context';
        if ($i === $li) {
            $role = 'marker';
        } elseif ($si !== null && $ei !== null && $i >= $si && $i <= $ei) {
            $role = 'target';
        }
        $out[] = ['text' => (string)($lines[$i] ?? ''), 'role' => $role];
    }

    return [
        'stale'           => false,
        'lines'           => $out,
        'componentType'   => isset($comp['type']) ? (string)$comp['type'] : null,
        'componentNumber' => isset($comp['number']) ? (int)$comp['number'] : null,
    ];
}

/** Plain-English label for a detector `form` — the raw code word means
 *  nothing to a curator; what matters is WHERE the marker sat. */
function vprFormLabel(string $form): string
{
    return match ($form) {
        'standalone' => 'On its own line',
        'prefix'     => 'At the start of a line',
        'paren'      => 'In brackets',
        default      => $form,
    };
}

/* ----------------------------------------------------------------------
 * POST dispatcher — JSON in / JSON out (the duplicate-songs.php /
 * deleted-songs.php shape: form-encoded body in, `validateCsrfRequest()`
 * same-origin check, one action name, JSON out). No database work of its
 * own — every branch below hands straight off to `includes/
 * vocal_part_review.php`; this file only decides HTTP status + shape.
 * ---------------------------------------------------------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    header('Content-Type: application/json; charset=UTF-8');

    /* Rule #29: the robust same-origin check (X-Requested-With OR a still-
       valid session token), never a baked token alone — a long-open queue
       tab must not sporadically 403 a legitimate Accept/Dismiss/Undo. */
    if (!validateCsrfRequest((string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['error' => 'CSRF check failed — please retry.']);
        exit;
    }

    $action   = (string)($_POST['action'] ?? '');
    $songId   = trim((string)($_POST['song_id'] ?? ''));
    $suggId   = (int)($_POST['id'] ?? 0);

    if (!vocalPartReviewReady($db)) {
        http_response_code(409);
        echo json_encode(['error' => 'The voice-part suggestions queue is not migrated on this install yet.']);
        exit;
    }

    if ($action === 'accept') {
        if ($songId === '' || $suggId <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'song_id and id are required.']);
            exit;
        }
        /* The <select>/text input on the row are always pre-filled with the
           CURRENT proposed kind/label, so sending them unconditionally is
           harmless when the curator changed nothing and is exactly the
           point when they DID correct the detector's guess before
           clicking Accept — every override key is OMITTED-MEANS-KEEP on
           the core's side (array_key_exists), but this page always has an
           opinion (the form always shows one), so it always sends one. */
        $overrides = [
            'kind'         => (string)($_POST['kind'] ?? ''),
            'label'        => (string)($_POST['label'] ?? ''),
            'isBackground' => (string)($_POST['is_background'] ?? '0') === '1',
        ];
        try {
            $db->begin_transaction();
            $result = vocalPartReviewAccept($db, $songId, $suggId, $userId, $overrides);
            $db->commit();
            if (function_exists('logActivity')) {
                try {
                    logActivity('vocal.suggestion_accepted', 'song', $songId, ['suggestionId' => $suggId]);
                } catch (\Throwable $_e) {
                    /* audit best-effort */
                }
            }
            echo json_encode(['success' => true, 'suggestion' => $result]);
        } catch (VocalPartReviewConflictException $e) {
            try {
                $db->rollback();
            } catch (\Throwable $_e) {
            }
            http_response_code(409);
            echo json_encode(['error' => $e->getMessage(), 'reason' => $e->reason]);
        } catch (\InvalidArgumentException $e) {
            try {
                $db->rollback();
            } catch (\Throwable $_e) {
            }
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
        } catch (\RuntimeException $e) {
            try {
                $db->rollback();
            } catch (\Throwable $_e) {
            }
            http_response_code(404);
            echo json_encode(['error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            try {
                $db->rollback();
            } catch (\Throwable $_e) {
            }
            error_log('[vocal-parts-review:accept] ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Accept failed.']);
        }
        exit;
    }

    if ($action === 'dismiss') {
        if ($songId === '' || $suggId <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'song_id and id are required.']);
            exit;
        }
        try {
            $result = vocalPartReviewDismiss($db, $songId, $suggId, $userId);
            if (function_exists('logActivity')) {
                try {
                    logActivity('vocal.suggestion_dismissed', 'song', $songId, ['suggestionId' => $suggId]);
                } catch (\Throwable $_e) {
                }
            }
            echo json_encode(['success' => true, 'suggestion' => $result]);
        } catch (VocalPartReviewConflictException $e) {
            http_response_code(409);
            echo json_encode(['error' => $e->getMessage(), 'reason' => $e->reason]);
        } catch (\RuntimeException $e) {
            http_response_code(404);
            echo json_encode(['error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            error_log('[vocal-parts-review:dismiss] ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Dismiss failed.']);
        }
        exit;
    }

    if ($action === 'undo') {
        if ($songId === '' || $suggId <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'song_id and id are required.']);
            exit;
        }
        try {
            $db->begin_transaction();
            $result = vocalPartReviewUndo($db, $songId, $suggId, $userId);
            $db->commit();
            if (function_exists('logActivity')) {
                try {
                    logActivity('vocal.suggestion_undone', 'song', $songId, ['suggestionId' => $suggId]);
                } catch (\Throwable $_e) {
                }
            }
            echo json_encode(['success' => true, 'suggestion' => $result]);
        } catch (VocalPartReviewConflictException $e) {
            try {
                $db->rollback();
            } catch (\Throwable $_e) {
            }
            http_response_code(409);
            echo json_encode(['error' => $e->getMessage(), 'reason' => $e->reason]);
        } catch (\RuntimeException $e) {
            try {
                $db->rollback();
            } catch (\Throwable $_e) {
            }
            http_response_code(404);
            echo json_encode(['error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            try {
                $db->rollback();
            } catch (\Throwable $_e) {
            }
            error_log('[vocal-parts-review:undo] ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Undo failed.']);
        }
        exit;
    }

    /* Rescan ONE song — re-runs the same shared detector this page's
       backfill batch used, refreshing anything still pending/stale and
       flagging a no-longer-findable pending row as 'stale' (never touches
       anything a curator already Accepted/Dismissed). Useful after a
       curator has just hand-edited a song's lyrics elsewhere and wants the
       queue to catch up immediately, rather than waiting for the next
       whole-catalogue batch run. */
    if ($action === 'refresh') {
        if ($songId === '') {
            http_response_code(400);
            echo json_encode(['error' => 'song_id is required.']);
            exit;
        }
        try {
            $counts = vocalPartReviewRefreshSong($db, $songId);
            echo json_encode(['success' => true, 'counts' => $counts]);
        } catch (\Throwable $e) {
            error_log('[vocal-parts-review:refresh] ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Rescan failed.']);
        }
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Unknown action. Use: accept, dismiss, undo, refresh.']);
    exit;
}

/* ----------------------------------------------------------------------
 * GET — load the queue. `vocalPartReviewReady()` itself never throws (its
 * own doc-block: memoised, catches, degrades to false) so this needs no
 * extra try/catch of its own beyond the one already wrapping the list read.
 * ---------------------------------------------------------------------- */
$ready       = vocalPartReviewReady($db);
$loadError   = '';
$suggestions = [];
$counts      = ['pending' => 0];

/* Filters — GET query params so a filtered view is a shareable/bookmarkable
   URL, matching this codebase's other admin list pages. Default view is
   the ACTIVE queue (status=pending); '?status=all' clears the filter. */
$statusParam = (string)($_GET['status'] ?? 'pending');
$confParam   = (string)($_GET['confidence'] ?? '');
$formParam   = (string)($_GET['form'] ?? '');
$bookParam   = trim((string)($_GET['songbook'] ?? ''));
$limit       = max(1, min(200, (int)($_GET['limit'] ?? 50)));
$offset      = max(0, (int)($_GET['offset'] ?? 0));

$filter = [];
if ($statusParam !== '' && $statusParam !== 'all' && in_array($statusParam, IHYMNS_VOCAL_REVIEW_STATUSES, true)) {
    $filter['status'] = $statusParam;
}
if ($confParam !== '' && in_array($confParam, IHYMNS_VOCAL_REVIEW_CONFIDENCES, true)) {
    $filter['confidence'] = $confParam;
}
if ($formParam !== '' && in_array($formParam, IHYMNS_VOCAL_DETECT_FORMS, true)) {
    $filter['form'] = $formParam;
}
if ($bookParam !== '') {
    $filter['songbook'] = $bookParam;
}

/* Per-song line preview cache — `lyricLinesEditableComponents()` is called
   AT MOST once per DISTINCT song on this page of results, never per row
   (several suggestions commonly share one song). */
$componentsBySong = [];
$previews         = [];

if ($ready) {
    try {
        $suggestions = vocalPartReviewList($db, $filter, $limit + 1, $offset);
        $hasMore     = count($suggestions) > $limit;
        if ($hasMore) {
            array_pop($suggestions);
        }
        foreach ($suggestions as $s) {
            $sid = $s['songId'];
            if (!array_key_exists($sid, $componentsBySong)) {
                try {
                    $componentsBySong[$sid] = lyricLinesEditableComponents($db, $sid);
                } catch (\Throwable $_e) {
                    $componentsBySong[$sid] = [];
                }
            }
            $previews[$s['id']] = vprReviewPreview($componentsBySong[$sid], $s);
        }
    } catch (\InvalidArgumentException $e) {
        $loadError = $e->getMessage();
    } catch (\Throwable $e) {
        error_log('[vocal-parts-review] ' . $e->getMessage());
        $loadError = 'Could not load the voice-part suggestions queue — see server logs.';
    }
} else {
    $hasMore = false;
}

$kindsProjection = vocalPartsKindsProjection();
$kindLabelByKey  = array_column($kindsProjection, 'label', 'key');

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Voice-part Suggestions — iHymns Admin</title>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-libs.php'; ?>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-favicon.php'; ?>
    <style>
        /* Text-cue-not-colour-alone (task requirement): every role below
           carries its own WORD ("removed", "assigned to …") — the colour
           is a bonus for sighted users skimming, never the only signal. */
        .vpr-line { padding: .05rem .35rem; border-radius: .2rem; }
        .vpr-line--marker { text-decoration: line-through; color: var(--bs-secondary-color); font-style: italic; }
        .vpr-line--target { background: var(--bs-warning-bg-subtle); border-left: 3px solid var(--bs-warning-border-subtle); padding-left: .5rem; }
        .vpr-line--context { color: var(--bs-secondary-color); }
        .vpr-preview { font-size: .85rem; line-height: 1.5; }
    </style>
</head>
<body>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-nav.php'; ?>

    <main class="container-admin py-4">
        <h1 class="h4 mb-1">
            <i class="bi bi-people me-2" aria-hidden="true"></i>
            Voice-part Suggestions
        </h1>
        <p class="text-secondary small mb-2">
            Old lyric text sometimes has a line like <span class="font-monospace">WOMEN</span> or
            <span class="font-monospace">MEN: You are holy,</span> typed straight into the words —
            a leftover from before this app had a proper way to say "the women sing this bit". A
            batch scan already found lines like these and listed each one below as a
            <strong>suggestion</strong>: a guess, never a change made on its own.
            <strong>Accept</strong> assigns the real voice part and tidies the marker text;
            <strong>Dismiss</strong> throws the suggestion away (nothing about the song changes).
        </p>
        <p class="text-secondary small mb-4">
            <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
            <strong>Undo</strong> lives here, not in the song's own Revision History. Restoring an
            old revision only puts the marker text back — it has no idea a voice-part assignment
            was ever made, so the song would end up with BOTH the old marker line and the new
            (now wrong) voice assignment. Undo here reverses exactly what Accept did, using its own
            record of what happened, so it always leaves the song exactly as it was before.
        </p>

        <?php if ($loadError !== ''): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($loadError) ?></div>
        <?php elseif (!$ready): ?>
            <div class="alert alert-warning">
                <i class="bi bi-database-exclamation me-1" aria-hidden="true"></i>
                The <strong>Vocal parts: echo spans, rounds/canon, review queue (#2073)</strong> and/or
                <strong>Vocal / singing parts (#1137)</strong> migrations have not been applied on this
                install yet, so there is no review queue. Apply the cards at
                <a href="/manage/setup-database">Database Setup</a>, then run
                <strong>Detect Voice Markers</strong> to fill the queue.
            </div>
        <?php else: ?>

        <form method="get" class="row row-cols-lg-auto g-2 align-items-end mb-3" aria-label="Filter suggestions">
            <div class="col-12">
                <label for="vpr-status" class="form-label small mb-1">Status</label>
                <select id="vpr-status" name="status" class="form-select form-select-sm">
                    <option value="all" <?= $statusParam === 'all' ? 'selected' : '' ?>>All</option>
                    <?php foreach (IHYMNS_VOCAL_REVIEW_STATUSES as $st): ?>
                        <option value="<?= htmlspecialchars($st) ?>" <?= $statusParam === $st ? 'selected' : '' ?>><?= htmlspecialchars(ucfirst($st)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12">
                <label for="vpr-confidence" class="form-label small mb-1">Confidence</label>
                <select id="vpr-confidence" name="confidence" class="form-select form-select-sm">
                    <option value="">Any</option>
                    <?php foreach (IHYMNS_VOCAL_REVIEW_CONFIDENCES as $c): ?>
                        <option value="<?= htmlspecialchars($c) ?>" <?= $confParam === $c ? 'selected' : '' ?>><?= htmlspecialchars(ucfirst($c)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12">
                <label for="vpr-form" class="form-label small mb-1">Where it sat</label>
                <select id="vpr-form" name="form" class="form-select form-select-sm">
                    <option value="">Any</option>
                    <?php foreach (IHYMNS_VOCAL_DETECT_FORMS as $f): ?>
                        <option value="<?= htmlspecialchars($f) ?>" <?= $formParam === $f ? 'selected' : '' ?>><?= htmlspecialchars(vprFormLabel($f)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12">
                <label for="vpr-songbook" class="form-label small mb-1">Songbook</label>
                <input type="text" id="vpr-songbook" name="songbook" class="form-control form-control-sm" style="max-width: 8rem" value="<?= htmlspecialchars($bookParam, ENT_QUOTES) ?>" placeholder="e.g. MP" aria-describedby="vpr-songbook-help">
                <span id="vpr-songbook-help" class="visually-hidden">Songbook abbreviation prefix</span>
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-sm btn-outline-secondary">Filter</button>
            </div>
        </form>

        <?php if ($suggestions === []): ?>
            <div class="alert alert-info small mb-0" role="status">
                <i class="bi bi-check-circle me-1" aria-hidden="true"></i>
                Nothing matches this filter.
            </div>
        <?php else: ?>
            <div class="card-admin p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0 cp-sortable admin-table-responsive" id="vpr-table">
                        <thead>
                            <tr>
                                <th scope="col" data-col-priority="primary"   data-sort-key="song"   data-sort-type="text">Song</th>
                                <th scope="col" data-col-priority="primary">Line in context</th>
                                <th scope="col" data-col-priority="secondary" data-sort-key="form"   data-sort-type="text">Where it sat</th>
                                <th scope="col" data-col-priority="primary">Proposal</th>
                                <th scope="col" data-col-priority="secondary" data-sort-key="conf"   data-sort-type="text">Confidence</th>
                                <th scope="col" data-col-priority="secondary" data-sort-key="status" data-sort-type="text">Status</th>
                                <th scope="col" data-col-priority="primary">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($suggestions as $s):
                                $preview = $previews[$s['id']] ?? ['stale' => true, 'lines' => []];
                                $songLabel = ($s['songTitle'] !== null)
                                    ? ($s['songTitle'] . ' (' . $s['songId'] . ')')
                                    : $s['songId'];
                            ?>
                            <tr data-suggestion-row="<?= (int)$s['id'] ?>" data-song-id="<?= htmlspecialchars($s['songId'], ENT_QUOTES) ?>">
                                <td data-col-priority="primary" data-sort-value="<?= htmlspecialchars($songLabel, ENT_QUOTES) ?>">
                                    <a href="/manage/editor/?song=<?= urlencode($s['songId']) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($songLabel) ?></a>
                                    <?php if ($s['songbookAbbr'] !== null): ?>
                                        <div class="small text-secondary"><?= htmlspecialchars($s['songbookAbbr']) ?></div>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-link btn-sm p-0 vpr-rescan-btn" data-song-id="<?= htmlspecialchars($s['songId'], ENT_QUOTES) ?>" title="Re-scan this one song's lyrics right now">Rescan</button>
                                </td>
                                <td data-col-priority="primary" class="vpr-preview">
                                    <?php if ($preview['stale']): ?>
                                        <span class="badge bg-body-secondary text-body">stale — line moved or gone; Rescan to refresh</span>
                                    <?php else: ?>
                                        <?php if ($preview['componentType'] !== null): ?>
                                            <div class="text-secondary text-uppercase" style="font-size: .7rem; letter-spacing: .03em;">
                                                <?= htmlspecialchars($preview['componentType']) ?><?= $preview['componentNumber'] ? ' ' . (int)$preview['componentNumber'] : '' ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php foreach ($preview['lines'] as $ln): ?>
                                            <div class="vpr-line vpr-line--<?= htmlspecialchars($ln['role']) ?>">
                                                <?= htmlspecialchars($ln['text'] !== '' ? $ln['text'] : '(blank line)') ?>
                                                <?php if ($ln['role'] === 'marker'): ?>
                                                    <span class="text-secondary"> — marker, removed on Accept</span>
                                                <?php elseif ($ln['role'] === 'target'): ?>
                                                    <span class="text-secondary"> — assigned on Accept</span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                        <?php if ($s['form'] === 'prefix'):
                                            $rewrite = null;
                                            foreach (($s['proposed'] ?? []) as $act) {
                                                if (($act['action'] ?? '') === 'rewrite-marker-line') {
                                                    $rewrite = (string)($act['text'] ?? '');
                                                    break;
                                                }
                                            }
                                        ?>
                                            <?php if ($rewrite !== null): ?>
                                                <div class="vpr-line vpr-line--target">
                                                    <?= htmlspecialchars($rewrite !== '' ? $rewrite : '(blank line)') ?>
                                                    <span class="text-secondary"> — becomes the line's text on Accept</span>
                                                </div>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td data-col-priority="secondary"><?= htmlspecialchars(vprFormLabel($s['form'])) ?></td>
                                <td data-col-priority="primary">
                                    <?php if ($s['status'] === 'pending' || $s['status'] === 'stale'): ?>
                                        <div class="d-flex flex-column gap-1" style="max-width: 14rem">
                                            <select class="form-select form-select-sm vpr-kind-input" aria-label="Voice part">
                                                <?php foreach ($kindsProjection as $k): ?>
                                                    <option value="<?= htmlspecialchars($k['key']) ?>" <?= $s['partKind'] === $k['key'] ? 'selected' : '' ?>><?= htmlspecialchars($k['label']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <input type="text" class="form-control form-control-sm vpr-label-input" value="<?= htmlspecialchars((string)($s['label'] ?? ''), ENT_QUOTES) ?>" placeholder="Label (optional)" aria-label="Label (optional)">
                                            <div class="form-check form-check-sm">
                                                <input type="checkbox" class="form-check-input vpr-bg-input" id="vpr-bg-<?= (int)$s['id'] ?>" <?= $s['isBackground'] ? 'checked' : '' ?>>
                                                <label class="form-check-label small" for="vpr-bg-<?= (int)$s['id'] ?>">Echo / background</label>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-secondary">
                                            <?= htmlspecialchars($kindLabelByKey[$s['partKind']] ?? $s['partKind']) ?><?= $s['label'] !== null ? ' — ' . htmlspecialchars((string)$s['label']) : '' ?>
                                            <?php if ($s['status'] === 'accepted'): ?>
                                                <span class="d-block text-secondary" style="font-size: .75rem">(as first proposed — a kind/label changed at Accept time is not re-shown here)</span>
                                            <?php endif; ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td data-col-priority="secondary"><span class="badge bg-body-secondary text-body"><?= htmlspecialchars($s['confidence']) ?></span></td>
                                <td data-col-priority="secondary"><span class="badge bg-body-secondary text-body"><?= htmlspecialchars($s['status']) ?></span></td>
                                <td data-col-priority="primary">
                                    <?php if ($s['status'] === 'pending' || $s['status'] === 'stale'): ?>
                                        <button type="button" class="btn btn-sm btn-outline-success vpr-accept-btn" data-song-id="<?= htmlspecialchars($s['songId'], ENT_QUOTES) ?>" data-id="<?= (int)$s['id'] ?>">
                                            <i class="bi bi-check-lg me-1" aria-hidden="true"></i>Accept
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary vpr-dismiss-btn" data-song-id="<?= htmlspecialchars($s['songId'], ENT_QUOTES) ?>" data-id="<?= (int)$s['id'] ?>">
                                            <i class="bi bi-x-lg me-1" aria-hidden="true"></i>Dismiss
                                        </button>
                                    <?php elseif ($s['status'] === 'accepted'): ?>
                                        <button type="button" class="btn btn-sm btn-outline-warning vpr-undo-btn" data-song-id="<?= htmlspecialchars($s['songId'], ENT_QUOTES) ?>" data-id="<?= (int)$s['id'] ?>">
                                            <i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Undo
                                        </button>
                                    <?php else: ?>
                                        <span class="text-secondary small">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <nav class="d-flex justify-content-between align-items-center mt-2" aria-label="Pagination">
                <span class="text-secondary small"><?= count($suggestions) ?> suggestion<?= count($suggestions) === 1 ? '' : 's' ?> shown<?= $offset > 0 ? ', starting at ' . ($offset + 1) : '' ?>.</span>
                <span>
                    <?php if ($offset > 0): ?>
                        <a class="btn btn-sm btn-outline-secondary" href="?<?= htmlspecialchars(http_build_query(array_merge($_GET, ['offset' => max(0, $offset - $limit)])), ENT_QUOTES) ?>">&laquo; Newer</a>
                    <?php endif; ?>
                    <?php if ($hasMore): ?>
                        <a class="btn btn-sm btn-outline-secondary" href="?<?= htmlspecialchars(http_build_query(array_merge($_GET, ['offset' => $offset + $limit])), ENT_QUOTES) ?>">Older &raquo;</a>
                    <?php endif; ?>
                </span>
            </nav>
        <?php endif; ?>
        <?php endif; ?>
    </main>

    <script>
    (function () {
        'use strict';
        var CSRF = <?= json_encode($csrf) ?>;
        function toast(msg, ok) { if (window.showToast) { window.showToast(msg, ok ? 'success' : 'error'); } else { alert(msg); } }

        /* POST helper — rule #29's client half: X-Requested-With makes the
           same-origin route pass even after the baked token goes stale. */
        function post(params) {
            params.csrf_token = CSRF;
            return fetch('/manage/vocal-parts-review', {
                method: 'POST',
                body: new URLSearchParams(params),
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (r) {
                return r.json().then(function (j) {
                    if (!r.ok || !j.success) { throw new Error(j.error || 'Request failed (HTTP ' + r.status + ')'); }
                    return j;
                });
            });
        }

        function row(id) { return document.querySelector('tr[data-suggestion-row="' + id + '"]'); }

        /* ---- Accept ---- */
        document.querySelectorAll('.vpr-accept-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var id = btn.dataset.id, songId = btn.dataset.songId;
                var r = row(id);
                var kind  = r ? (r.querySelector('.vpr-kind-input') || {}).value : '';
                var label = r ? (r.querySelector('.vpr-label-input') || {}).value : '';
                var bg    = r ? !!(r.querySelector('.vpr-bg-input') || {}).checked : false;
                btn.disabled = true;
                post({ action: 'accept', song_id: songId, id: id, kind: kind, label: label, is_background: bg ? '1' : '0' })
                    .then(function () { toast('Accepted.', true); window.location.reload(); })
                    .catch(function (e) {
                        btn.disabled = false;
                        toast(e.message || 'Accept failed.', false);
                    });
            });
        });

        /* ---- Dismiss ---- */
        document.querySelectorAll('.vpr-dismiss-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var id = btn.dataset.id, songId = btn.dataset.songId;
                if (!confirm('Dismiss this suggestion? The song is not changed either way.')) { return; }
                btn.disabled = true;
                post({ action: 'dismiss', song_id: songId, id: id })
                    .then(function () { toast('Dismissed.', true); var r = row(id); if (r) { r.remove(); } })
                    .catch(function (e) {
                        btn.disabled = false;
                        toast(e.message || 'Dismiss failed.', false);
                    });
            });
        });

        /* ---- Undo ---- */
        document.querySelectorAll('.vpr-undo-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var id = btn.dataset.id, songId = btn.dataset.songId;
                if (!confirm('Undo this Accept? Puts the marker text back and clears the voice assignment it made.')) { return; }
                btn.disabled = true;
                post({ action: 'undo', song_id: songId, id: id })
                    .then(function () { toast('Undone.', true); window.location.reload(); })
                    .catch(function (e) {
                        btn.disabled = false;
                        toast(e.message || 'Undo failed.', false);
                    });
            });
        });

        /* ---- Rescan one song ---- */
        document.querySelectorAll('.vpr-rescan-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var songId = btn.dataset.songId;
                btn.disabled = true;
                post({ action: 'refresh', song_id: songId })
                    .then(function (j) {
                        var c = j.counts || {};
                        toast('Rescanned ' + songId + ': ' + (c.found || 0) + ' found, ' + (c.inserted || 0) + ' new, ' + (c.updated || 0) + ' refreshed, ' + (c.staled || 0) + ' gone stale.', true);
                        window.location.reload();
                    })
                    .catch(function (e) {
                        btn.disabled = false;
                        toast(e.message || 'Rescan failed.', false);
                    });
            });
        });
    })();
    </script>

    <!-- Sortable table headers (#644 / #844). -->
    <script type="module">
        import { bootSortableTables } from '/js/modules/admin-table-sort.js?v=<?= filemtime(dirname(__DIR__) . '/js/modules/admin-table-sort.js') ?>';
        bootSortableTables();
    </script>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>
</body>
</html>
