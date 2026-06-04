<?php

declare(strict_types=1);

/**
 * iHymns — Admin: Duplicate Songs review (#1064)
 *
 * Surfaces POTENTIAL duplicate songs for moderators/admins to review + merge.
 * Duplicates become more detectable as songs accrue shared identifiers (ISRC,
 * MusicBrainz/Spotify URLs) via the lyrics-ingest enrichment (#1064 / #907) and
 * as the provisional "Misc" songs that ingest creates pile up.
 *
 * Detection (read, on load):
 *   - NORMALIZED-TITLE collisions (shared title-fold via ihymns_normalize_title).
 *   - shared ISRC (tblSongs.Isrc).
 *   - shared external-link URL (same provider + Url across >1 song).
 *
 * Merge (POST, CSRF-gated, transactional):
 *   Re-points every tblSongs.SongId FK to the survivor with the robust
 *   `UPDATE IGNORE … SET SongId=survivor` + `DELETE … WHERE SongId=duplicate`
 *   idiom (handles UNIQUE/compound-PK collisions uniformly), then deletes the
 *   duplicate song. Table/column names come from a fixed PHP list (safe);
 *   every SongId value is bound. Audited via logActivity('song.merge', …).
 *
 * Gating: manage_duplicate_songs entitlement (admin + global_admin).
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'config.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_mysql.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'title_normalize.php';

if (!isAuthenticated()) {
    header('Location: /manage/login');
    exit;
}
$currentUser = getCurrentUser();
if (!$currentUser || !userHasEntitlement('manage_duplicate_songs', $currentUser['role'] ?? null)) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><body><h1>403 — manage_duplicate_songs required</h1></body></html>';
    exit;
}
$activePage = 'duplicate-songs';

$db   = getDbMysqli();
$csrf = csrfToken();

/* Every table that references tblSongs.SongId (authoritative, from schema.sql).
   The merge re-points each to the survivor. Table + column names are fixed
   constants from THIS source (never user input) — safe to interpolate; the
   SongId VALUES are always bound.

   MERGE_FK_TABLES_* are the 22 tables with an explicit FOREIGN KEY constraint
   (single-column, then two-column relationship tables). MERGE_SOFT_REFS are
   columns that reference a SongId WITHOUT an FK constraint — they would leave
   dangling values after the duplicate is deleted (the DB can't cascade them),
   so the merge must repoint them too. (#1064 review finding.) */
const MERGE_FK_TABLES_SINGLE = [
    'tblSongbookEntries', 'tblSongWriters', 'tblSongComposers', 'tblSongArrangers',
    'tblSongAdaptors', 'tblSongTranslators', 'tblSongArtists', 'tblSongComponents',
    'tblLyrics', 'tblUserFavorites', 'tblSongKeys', 'tblSongHistory', 'tblSongTagMap',
    'tblSongLinks', 'tblCatalogueSongs', 'tblSongExternalLinks', 'tblSongAlternativeTitles',
    'tblSongLanguages', 'tblSongMedia', 'tblWorkSongs',
];
const MERGE_FK_TABLES_PAIR = [
    'tblSongTranslations'   => ['SourceSongId', 'TranslatedSongId'],
    'tblSongLinkSuggestions' => ['SongIdA', 'SongIdB'],
];
const MERGE_SOFT_REFS = [
    'tblSongRequests'                 => ['ResolvedSongId'],
    'tblSongRevisions'                => ['SongId'],
    'tblSongLinkSuggestionsDismissed' => ['SongIdA', 'SongIdB'],
];

/* ----------------------------------------------------------------------
 * POST dispatcher — JSON in / JSON out.
 * ---------------------------------------------------------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    header('Content-Type: application/json; charset=UTF-8');

    if (!validateCsrf((string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['error' => 'CSRF token invalid — refresh the page.']);
        exit;
    }
    $action = (string)($_POST['action'] ?? '');

    if ($action !== 'merge') {
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action.']);
        exit;
    }

    $survivor  = trim((string)($_POST['survivor_id'] ?? ''));
    $duplicate = trim((string)($_POST['duplicate_id'] ?? ''));
    if ($survivor === '' || $duplicate === '') {
        http_response_code(400);
        echo json_encode(['error' => 'survivor_id and duplicate_id are required.']);
        exit;
    }
    if ($survivor === $duplicate) {
        http_response_code(400);
        echo json_encode(['error' => 'A song cannot be merged into itself.']);
        exit;
    }

    /* Both must exist. */
    $chk = $db->prepare('SELECT SongId FROM tblSongs WHERE SongId IN (?, ?)');
    $chk->bind_param('ss', $survivor, $duplicate);
    $chk->execute();
    $found = [];
    $r = $chk->get_result();
    while ($row = $r->fetch_assoc()) { $found[(string)$row['SongId']] = true; }
    $chk->close();
    if (!isset($found[$survivor]) || !isset($found[$duplicate])) {
        http_response_code(400);
        echo json_encode(['error' => 'Both songs must exist.']);
        exit;
    }

    $db->begin_transaction();
    try {
        /* Single-column FK tables. */
        foreach (MERGE_FK_TABLES_SINGLE as $t) {
            $u = $db->prepare("UPDATE IGNORE `{$t}` SET SongId = ? WHERE SongId = ?");
            $u->bind_param('ss', $survivor, $duplicate);
            $u->execute();
            $u->close();
            /* Any rows that couldn't move (UNIQUE collision with a survivor row)
               are leftover duplicates — drop them. */
            $d = $db->prepare("DELETE FROM `{$t}` WHERE SongId = ?");
            $d->bind_param('s', $duplicate);
            $d->execute();
            $d->close();
        }
        /* Two-column relationship tables. */
        foreach (MERGE_FK_TABLES_PAIR as $t => $cols) {
            foreach ($cols as $c) {
                $u = $db->prepare("UPDATE IGNORE `{$t}` SET `{$c}` = ? WHERE `{$c}` = ?");
                $u->bind_param('ss', $survivor, $duplicate);
                $u->execute();
                $u->close();
                $d = $db->prepare("DELETE FROM `{$t}` WHERE `{$c}` = ?");
                $d->bind_param('s', $duplicate);
                $d->execute();
                $d->close();
            }
        }
        /* Soft references (no FK constraint → the DB won't cascade/null them,
           so repoint explicitly or they dangle after the delete). Same
           UPDATE IGNORE + DELETE-leftover idiom for any UNIQUE collisions. */
        foreach (MERGE_SOFT_REFS as $t => $cols) {
            foreach ($cols as $c) {
                $u = $db->prepare("UPDATE IGNORE `{$t}` SET `{$c}` = ? WHERE `{$c}` = ?");
                $u->bind_param('ss', $survivor, $duplicate);
                $u->execute();
                $u->close();
                $d = $db->prepare("DELETE FROM `{$t}` WHERE `{$c}` = ?");
                $d->bind_param('s', $duplicate);
                $d->execute();
                $d->close();
            }
        }
        /* Finally remove the duplicate song. */
        $del = $db->prepare('DELETE FROM tblSongs WHERE SongId = ?');
        $del->bind_param('s', $duplicate);
        $del->execute();
        $del->close();

        $db->commit();

        if (function_exists('logActivity')) {
            try {
                logActivity('song.merge', 'song', $survivor, [
                    'merged_from' => $duplicate,
                    'tables'      => count(MERGE_FK_TABLES_SINGLE) + count(MERGE_FK_TABLES_PAIR) + count(MERGE_SOFT_REFS),
                ]);
            } catch (\Throwable $_e) { /* audit best-effort */ }
        }
        echo json_encode(['success' => true, 'survivorId' => $survivor, 'mergedFrom' => $duplicate]);
        exit;
    } catch (\Throwable $e) {
        try { $db->rollback(); } catch (\Throwable $_) {}
        http_response_code(500);
        echo json_encode(['error' => 'Merge failed (rolled back): ' . $e->getMessage()]);
        exit;
    }
}

/* ----------------------------------------------------------------------
 * GET — detect candidate groups.
 * ---------------------------------------------------------------------- */

/* Load a lightweight row per song. ~3.6k rows — grouping in PHP is trivial. */
$songs = [];
$res = $db->query(
    'SELECT s.SongId, s.Title, s.SongbookAbbr, s.Number, s.Verified, s.Isrc,
            (SELECT COUNT(*) FROM tblLyrics l WHERE l.SongId = s.SongId) AS LyricsCount
       FROM tblSongs s'
);
if ($res) {
    while ($row = $res->fetch_assoc()) { $songs[(string)$row['SongId']] = $row; }
}

/* 1. Normalized-title collisions. */
$byNorm = [];
foreach ($songs as $sid => $row) {
    $norm = ihymns_normalize_title((string)$row['Title']);
    if ($norm === '') { continue; }
    $byNorm[$norm][] = $sid;
}
$titleGroups = [];
foreach ($byNorm as $norm => $ids) {
    if (count($ids) >= 2) { $titleGroups[] = $ids; }
}

/* 2. Shared ISRC. */
$byIsrc = [];
foreach ($songs as $sid => $row) {
    $isrc = trim((string)($row['Isrc'] ?? ''));
    if ($isrc === '') { continue; }
    $byIsrc[$isrc][] = $sid;
}
$isrcGroups = [];
foreach ($byIsrc as $isrc => $ids) {
    if (count($ids) >= 2) { $isrcGroups[$isrc] = $ids; }
}

/* 3. Shared external-link URL (same provider + Url across >1 song). */
$linkGroups = [];
$lr = $db->query(
    "SELECT t.Name AS Provider, x.Url, GROUP_CONCAT(x.SongId) AS SongIds
       FROM tblSongExternalLinks x
       JOIN tblExternalLinkTypes t ON t.Id = x.LinkTypeId
      GROUP BY x.LinkTypeId, x.Url
     HAVING COUNT(DISTINCT x.SongId) > 1
      LIMIT 500"
);
if ($lr) {
    while ($row = $lr->fetch_assoc()) {
        $ids = array_values(array_unique(explode(',', (string)$row['SongIds'])));
        $linkGroups[] = ['provider' => (string)$row['Provider'], 'url' => (string)$row['Url'], 'ids' => $ids];
    }
}

$totalGroups = count($titleGroups) + count($isrcGroups) + count($linkGroups);

/* Helper: render a song chip. */
$songLabel = static function (array $s): string {
    $num = ($s['Number'] !== null && $s['Number'] !== '') ? (' #' . (int)$s['Number']) : '';
    return htmlspecialchars((string)$s['SongId'] . ' · ' . (string)$s['Title']
        . ' [' . (string)$s['SongbookAbbr'] . $num . ']', ENT_QUOTES);
};

require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-favicon.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Duplicate Songs — iHymns Admin</title>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-libs.php'; ?>
</head>
<body>
<?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-nav.php'; ?>

<main class="container-fluid py-4">
    <div class="mb-3">
        <h1 class="h3 mb-1"><i class="bi bi-git-compare me-2"></i>Duplicate Songs</h1>
        <p class="text-secondary small mb-0">
            Potential duplicate songs to review — grouped by normalised title, shared ISRC, and shared
            provider URL (MusicBrainz / Spotify / …). Detection sharpens as songs accrue IDs via lyrics
            ingest (#1064). <strong>Merge</strong> re-points every reference to the song you keep, then
            deletes the other — irreversible, so review carefully.
        </p>
    </div>

    <?php if ($totalGroups === 0): ?>
        <div class="alert alert-success"><i class="bi bi-check-circle me-1"></i>No potential duplicates detected.</div>
    <?php endif; ?>

    <?php
    /* Unified renderer for a candidate group. */
    $renderGroup = static function (array $ids, string $reason) use ($songs, $songLabel, $csrf): void {
        $members = array_filter(array_map(static fn($id) => $songs[$id] ?? null, $ids));
        if (count($members) < 2) { return; }
        echo '<div class="card mb-2"><div class="card-body py-2">';
        echo '<div class="small text-secondary mb-1">' . htmlspecialchars($reason, ENT_QUOTES) . '</div>';
        echo '<form class="dup-merge-form d-flex flex-wrap align-items-center gap-2">';
        echo '<table class="table table-sm mb-2 admin-table-responsive"><thead><tr>'
           . '<th>Keep</th><th data-col-priority="primary">Song</th>'
           . '<th data-col-priority="secondary">Verified</th><th data-col-priority="tertiary">Lyrics</th>'
           . '<th data-col-priority="secondary">ISRC</th></tr></thead><tbody>';
        $first = true;
        foreach ($members as $s) {
            $sid = (string)$s['SongId'];
            echo '<tr>';
            echo '<td><input type="radio" name="survivor" value="' . htmlspecialchars($sid, ENT_QUOTES) . '"' . ($first ? ' checked' : '') . '></td>';
            echo '<td data-col-priority="primary"><a href="/manage/editor/?song=' . urlencode($sid) . '" target="_blank" rel="noopener">' . $songLabel($s) . '</a></td>';
            echo '<td data-col-priority="secondary">' . ((int)$s['Verified'] === 1 ? '<span class="badge bg-success">verified</span>' : '<span class="badge bg-warning text-dark">unverified</span>') . '</td>';
            echo '<td data-col-priority="tertiary">' . (int)$s['LyricsCount'] . '</td>';
            echo '<td data-col-priority="secondary"><code class="small">' . htmlspecialchars((string)($s['Isrc'] ?? ''), ENT_QUOTES) . '</code></td>';
            echo '</tr>';
            $first = false;
        }
        echo '</tbody></table>';
        echo '<button type="button" class="btn btn-sm btn-outline-danger dup-merge-btn">'
           . '<i class="bi bi-union me-1"></i>Merge into selected</button>';
        echo '</form></div></div>';
    };

    if (!empty($titleGroups)) {
        echo '<h2 class="h5 mt-3"><i class="bi bi-fonts me-1"></i>Same title (' . count($titleGroups) . ')</h2>';
        foreach ($titleGroups as $ids) { $renderGroup($ids, 'Same normalised title'); }
    }
    if (!empty($isrcGroups)) {
        echo '<h2 class="h5 mt-3"><i class="bi bi-upc-scan me-1"></i>Same ISRC (' . count($isrcGroups) . ')</h2>';
        foreach ($isrcGroups as $isrc => $ids) { $renderGroup($ids, 'Same ISRC: ' . $isrc); }
    }
    if (!empty($linkGroups)) {
        echo '<h2 class="h5 mt-3"><i class="bi bi-link-45deg me-1"></i>Same provider link (' . count($linkGroups) . ')</h2>';
        foreach ($linkGroups as $g) { $renderGroup($g['ids'], 'Same ' . $g['provider'] . ' link: ' . $g['url']); }
    }
    ?>
</main>

<script>
(function () {
    'use strict';
    var CSRF = <?= json_encode($csrf) ?>;
    function toast(msg, ok) { if (window.showToast) { window.showToast(msg, ok ? 'success' : 'error'); } else { alert(msg); } }
    document.querySelectorAll('.dup-merge-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var form = btn.closest('.dup-merge-form');
            var keep = form.querySelector('input[name="survivor"]:checked');
            if (!keep) { toast('Choose which song to keep.', false); return; }
            var survivor = keep.value;
            var ids = Array.prototype.map.call(form.querySelectorAll('input[name="survivor"]'), function (r) { return r.value; });
            var dups = ids.filter(function (id) { return id !== survivor; });
            if (!dups.length) { return; }
            if (!confirm('Merge ' + dups.length + ' song(s) into ' + survivor + '? This re-points all references and deletes the other song(s). This cannot be undone.')) { return; }
            btn.disabled = true;
            /* Merge each duplicate into the survivor in turn. */
            var chain = Promise.resolve();
            dups.forEach(function (dup) {
                chain = chain.then(function () {
                    var body = new URLSearchParams({ action: 'merge', survivor_id: survivor, duplicate_id: dup, csrf_token: CSRF });
                    return fetch('/manage/duplicate-songs', { method: 'POST', body: body, credentials: 'same-origin' })
                        .then(function (r) { return r.json().then(function (j) { if (!r.ok || !j.success) { throw new Error(j.error || 'Merge failed'); } }); });
                });
            });
            chain.then(function () {
                toast('Merged into ' + survivor + '.', true);
                var card = btn.closest('.card');
                if (card) { card.remove(); }
            }).catch(function (e) { btn.disabled = false; toast(e.message || 'Merge failed.', false); });
        });
    });
})();
</script>

<?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>
</body>
</html>
