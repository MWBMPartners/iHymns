<?php

declare(strict_types=1);

/**
 * iHymns — Admin: Duplicate & Counterpart Review (#1064 / #1215)
 *
 * The single review surface for "are these the same hymn?" — it absorbed the
 * old /manage/song-link-suggestions page (#807/#808). A curator triages
 * candidate groups that the engine flags, then **Links** them as cross-book
 * counterparts, **Merges** true duplicates, or **Dismisses** false positives.
 *
 * Detection (read, on load) — candidate discovery is cheap; scoring is bounded:
 *   1. Cheap pass: one row per song (id/title/NormalizedTitle/songbook/IsOfficial
 *      /hard-ids). Group into candidate clusters via union-find over:
 *        - exact normalised-title classes (ihymns_normalize_title),
 *        - shared hard identifiers (Isrc / Iswc / Ccli),
 *        - shared external-link URL,
 *        - pre-scored fuzzy cross-book pairs from tblSongLinkSuggestions.
 *   2. Heavy pass (CANDIDATE MEMBERS ONLY — never the whole corpus, rule #17):
 *      fetch first-line lyric + writer/composer names + lyrics count.
 *   3. Score every cluster with the shared includes/song_similarity.php scorer
 *      (0.50·title + 0.35·first-line + 0.15·authors, hard-id → certain).
 *
 * Taxonomy (deterministic, by book span):
 *   §1 Cross-book counterparts (bookSpan ≥ 2)  → Link primary, Merge secondary.
 *   §2 Same title, one official songbook       → likely DISTINCT; guarded Merge.
 *   §3 Same title, one non-official collection  → probable import dupe; Merge.
 *
 * Actions (POST, CSRF-gated; per-action entitlements):
 *   - merge   (manage_duplicate_songs) — repoints every SongId FK to the
 *     survivor, then deletes the duplicate. Irreversible. Same-official-songbook
 *     pairs require force=1 (the §2 guard, #1218).
 *   - link    (edit_songs) — write tblSongLinks counterpart group (#1219).
 *   - dismiss (edit_songs) — write tblSongLinkSuggestionsDismissed (#1219).
 *   - rebuild (edit_songs) — re-run the fuzzy suggestion builder (#1219).
 *
 * Gating: page view = edit_songs (curators); merge = manage_duplicate_songs.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'config.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_mysql.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'title_normalize.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'song_similarity.php';

if (!isAuthenticated()) {
    header('Location: /manage/login');
    exit;
}
$currentUser = getCurrentUser();
$role        = $currentUser['role'] ?? null;
/* Page view is curator-level (the suggestions page it absorbed was edit_songs);
   the destructive Merge stays admin-level. */
if (!$currentUser || !userHasEntitlement('edit_songs', $role)) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><body><h1>403 — edit_songs required</h1></body></html>';
    exit;
}
$canMerge = userHasEntitlement('manage_duplicate_songs', $role);
$activePage = 'duplicate-songs';

$db   = getDbMysqli();
$csrf = csrfToken();

/* Every table that references tblSongs.SongId (authoritative, from schema.sql).
   The merge re-points each to the survivor. Table + column names are fixed
   constants from THIS source (never user input) — safe to interpolate; the
   SongId VALUES are always bound. (See #1064 for the FK-vs-soft-ref split.) */
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

    /* -------- merge (admin only) -------- */
    if ($action === 'merge') {
        if (!$canMerge) {
            http_response_code(403);
            echo json_encode(['error' => 'Merge requires the manage_duplicate_songs entitlement.']);
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

        /* #1218 guard — two songs in the SAME official songbook with the same
           title are almost always DIFFERENT hymns (a published hymnal does not
           list one song twice under two numbers). Refuse to merge such a pair
           unless the curator explicitly forces it via the §2 type-to-confirm
           path, so a casual click or a replayed request can't quietly destroy a
           distinct record. */
        $force = (string)($_POST['force'] ?? '') === '1';
        if (!$force) {
            $bstmt = $db->prepare(
                'SELECT s.SongId, s.SongbookAbbr, COALESCE(sb.IsOfficial, 0) AS IsOfficial
                   FROM tblSongs s
                   LEFT JOIN tblSongbooks sb ON sb.Abbreviation = s.SongbookAbbr
                  WHERE s.SongId IN (?, ?)'
            );
            $bstmt->bind_param('ss', $survivor, $duplicate);
            $bstmt->execute();
            $books = [];
            $br = $bstmt->get_result();
            while ($row = $br->fetch_assoc()) { $books[(string)$row['SongId']] = $row; }
            $bstmt->close();
            $sa = $books[$survivor]  ?? null;
            $sd = $books[$duplicate] ?? null;
            if ($sa && $sd
                && (string)$sa['SongbookAbbr'] === (string)$sd['SongbookAbbr']
                && (int)$sa['IsOfficial'] === 1) {
                http_response_code(409);
                echo json_encode(['error' =>
                    'Both songs are in the same official songbook (' . (string)$sa['SongbookAbbr']
                    . ') — almost certainly different hymns that share a title. Merge is blocked here; '
                    . 'use the type-to-confirm control if you are certain.']);
                exit;
            }
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
            /* Soft references (no FK constraint → repoint explicitly or they
               dangle after the delete). Same UPDATE IGNORE + DELETE-leftover. */
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

    http_response_code(400);
    echo json_encode(['error' => 'Unknown action.']);
    exit;
}

/* ----------------------------------------------------------------------
 * GET — detect candidate clusters.
 * ---------------------------------------------------------------------- */

/* Does the fuzzy-suggestion table exist? (It may be unmigrated on a fresh
   install.) We read it only as an edge source, so its absence is non-fatal. */
$suggTableExists = false;
$probe = $db->query(
    "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblSongLinkSuggestions' LIMIT 1"
);
if ($probe) { $suggTableExists = $probe->fetch_row() !== null; $probe->close(); }

/* ---- 1. Cheap pass: one lightweight row per song. ---- */
$songs = [];
$res = $db->query(
    'SELECT s.SongId, s.Title, s.NormalizedTitle, s.SongbookAbbr, s.Number, s.Verified,
            s.Isrc, s.Iswc, s.Ccli,
            COALESCE(sb.IsOfficial, 0) AS IsOfficial, sb.Name AS SongbookName
       FROM tblSongs s
       LEFT JOIN tblSongbooks sb ON sb.Abbreviation = s.SongbookAbbr'
);
if ($res) {
    while ($row = $res->fetch_assoc()) { $songs[(string)$row['SongId']] = $row; }
    $res->close();
}

/* ---- Union-find over candidate edges. Only candidate members are touched. ---- */
$parent = [];
$find = static function (string $x) use (&$parent, &$find): string {
    if (!isset($parent[$x])) { $parent[$x] = $x; }
    if ($parent[$x] !== $x) { $parent[$x] = $find($parent[$x]); }
    return $parent[$x];
};
$union = static function (string $a, string $b) use (&$parent, $find): void {
    $ra = $find($a); $rb = $find($b);
    if ($ra !== $rb) { $parent[$ra] = $rb; }
};
/* Link every member of a group to the first member (turns an N-member class
   into one connected component without N² edges). */
$unionGroup = static function (array $ids) use ($union): void {
    $n = count($ids);
    for ($i = 1; $i < $n; $i++) { $union($ids[0], $ids[$i]); }
};

/* (a) Exact normalised-title classes. */
$byNorm = [];
foreach ($songs as $sid => $row) {
    $norm = (string)($row['NormalizedTitle'] ?? '');
    if ($norm === '') { $norm = ihymns_normalize_title((string)$row['Title']); }
    if ($norm === '') { continue; }
    $byNorm[$norm][] = $sid;
}
foreach ($byNorm as $ids) {
    if (count($ids) >= 2) { $unionGroup($ids); }
}

/* (b) Shared hard identifiers — Isrc / Iswc / Ccli. */
foreach (['Isrc', 'Iswc', 'Ccli'] as $col) {
    $byId = [];
    foreach ($songs as $sid => $row) {
        $v = trim((string)($row[$col] ?? ''));
        if ($v === '') { continue; }
        $byId[mb_strtolower($v, 'UTF-8')][] = $sid;
    }
    foreach ($byId as $ids) {
        if (count($ids) >= 2) { $unionGroup($ids); }
    }
}

/* (c) Shared external-link URL (same provider + Url across >1 song). */
$lr = $db->query(
    "SELECT GROUP_CONCAT(x.SongId) AS SongIds
       FROM tblSongExternalLinks x
      GROUP BY x.LinkTypeId, x.Url
     HAVING COUNT(DISTINCT x.SongId) > 1
      LIMIT 1000"
);
if ($lr) {
    while ($row = $lr->fetch_assoc()) {
        $ids = array_values(array_unique(array_filter(explode(',', (string)$row['SongIds']))));
        $ids = array_values(array_filter($ids, static fn($id) => isset($songs[$id])));
        if (count($ids) >= 2) { $unionGroup($ids); }
    }
    $lr->close();
}

/* (d) Pre-scored fuzzy cross-book pairs (not dismissed, not already linked). */
if ($suggTableExists) {
    $sr = $db->query(
        'SELECT s.SongIdA, s.SongIdB
           FROM tblSongLinkSuggestions s
          WHERE NOT EXISTS (
                  SELECT 1 FROM tblSongLinkSuggestionsDismissed d
                   WHERE d.SongIdA = s.SongIdA AND d.SongIdB = s.SongIdB)
          LIMIT 5000'
    );
    if ($sr) {
        while ($row = $sr->fetch_assoc()) {
            $a = (string)$row['SongIdA']; $b = (string)$row['SongIdB'];
            if (isset($songs[$a], $songs[$b])) { $union($a, $b); }
        }
        $sr->close();
    }
}

/* Collect cluster membership (components of size ≥ 2). */
$clusters = [];
foreach (array_keys($parent) as $sid) {
    $root = $find($sid);
    $clusters[$root][] = $sid;
}
$clusters = array_values(array_filter($clusters, static fn($m) => count($m) >= 2));

/* Candidate member set = everyone in a cluster. */
$candidateIds = [];
foreach ($clusters as $members) {
    foreach ($members as $sid) { $candidateIds[$sid] = true; }
}
$candidateIds = array_keys($candidateIds);

/* ---- 2. Heavy pass — first-line lyric + authors + lyrics count, CANDIDATES
 *         ONLY (never the whole corpus; rule #17). Chunked IN(). ---- */
$feat = [];        // sid => ['normTitle','normFirstLine','authors','isrc','iswc','ccli']
$lyricsCount = []; // sid => int
$groupOf = [];     // sid => existing tblSongLinks GroupId (0 = none)
foreach (array_chunk($candidateIds, 200) as $chunk) {
    $ph    = implode(',', array_fill(0, count($chunk), '?'));
    $types = str_repeat('s', count($chunk));

    $stmt = $db->prepare(
        "SELECT s.SongId,
                COALESCE(GROUP_CONCAT(DISTINCT w.Name SEPARATOR '|'), '') AS Writers,
                COALESCE(GROUP_CONCAT(DISTINCT c.Name SEPARATOR '|'), '') AS Composers,
                (SELECT cmp.Body FROM tblSongComponents cmp
                  WHERE cmp.SongId = s.SongId ORDER BY cmp.SortOrder ASC LIMIT 1) AS FirstBody,
                (SELECT COUNT(*) FROM tblLyrics l WHERE l.SongId = s.SongId) AS LyricsCount
           FROM tblSongs s
           LEFT JOIN tblSongWriters   w ON w.SongId = s.SongId
           LEFT JOIN tblSongComposers c ON c.SongId = s.SongId
          WHERE s.SongId IN ($ph)
          GROUP BY s.SongId"
    );
    $stmt->bind_param($types, ...$chunk);
    $stmt->execute();
    $rr = $stmt->get_result();
    while ($row = $rr->fetch_assoc()) {
        $sid = (string)$row['SongId'];
        /* First non-empty line of the first component = the strongest signal. */
        $firstLine = '';
        $body = (string)($row['FirstBody'] ?? '');
        if ($body !== '') {
            foreach (preg_split('/\r?\n/', $body) ?: [] as $ln) {
                $ln = trim($ln);
                if ($ln !== '') { $firstLine = $ln; break; }
            }
        }
        $s = $songs[$sid] ?? [];
        $feat[$sid] = [
            'normTitle'     => ihymns_sim_normalise((string)($s['Title'] ?? '')),
            'normFirstLine' => ihymns_sim_normalise($firstLine),
            'authors'       => trim((string)$row['Writers'] . '|' . (string)$row['Composers'], '|'),
            'isrc'          => trim((string)($s['Isrc'] ?? '')),
            'iswc'          => trim((string)($s['Iswc'] ?? '')),
            'ccli'          => trim((string)($s['Ccli'] ?? '')),
        ];
        $lyricsCount[$sid] = (int)$row['LyricsCount'];
    }
    $stmt->close();

    /* Existing counterpart-group membership (so we can skip already-linked sets). */
    $gstmt = $db->prepare("SELECT SongId, GroupId FROM tblSongLinks WHERE SongId IN ($ph)");
    $gstmt->bind_param($types, ...$chunk);
    $gstmt->execute();
    $gr = $gstmt->get_result();
    while ($row = $gr->fetch_assoc()) { $groupOf[(string)$row['SongId']] = (int)$row['GroupId']; }
    $gstmt->close();
}

/* ---- 3. Assemble + score each cluster; bucket by section. ---- */
/* Section buckets: 'cross' (§1), 'official' (§2), 'other' (§3). */
$sectioned = ['cross' => [], 'official' => [], 'other' => []];

foreach ($clusters as $members) {
    /* Sort members for stable display (songbook, then number). */
    usort($members, static function ($a, $b) use ($songs) {
        $ba = (string)($songs[$a]['SongbookAbbr'] ?? '');
        $bb = (string)($songs[$b]['SongbookAbbr'] ?? '');
        if ($ba !== $bb) { return strcmp($ba, $bb); }
        return ((int)($songs[$a]['Number'] ?? 0)) <=> ((int)($songs[$b]['Number'] ?? 0));
    });

    /* Skip clusters whose members are ALL already in one counterpart group —
       they're linked; re-proposing them is noise. */
    $groups = array_unique(array_map(static fn($sid) => $groupOf[$sid] ?? 0, $members));
    if (count($groups) === 1 && (int)reset($groups) > 0) { continue; }

    /* Best pairwise score across the cluster + the set of signals fired. */
    $best = ['score' => 0.0, 'title' => 0.0, 'lyrics' => 0.0, 'authors' => 0.0, 'signal' => 'fuzzy', 'confidence' => 'low'];
    $signalsFired = [];
    $n = count($members);
    for ($i = 0; $i < $n; $i++) {
        for ($j = $i + 1; $j < $n; $j++) {
            $s = ihymns_sim_score($feat[$members[$i]] ?? [], $feat[$members[$j]] ?? []);
            if ($s['signal'] !== 'fuzzy') { $signalsFired[$s['signal']] = true; }
            if ($s['score'] > $best['score']) { $best = $s; }
        }
    }

    /* Book span + per-official-book collision detection. */
    $bookCount = [];
    foreach ($members as $sid) {
        $abbr = (string)($songs[$sid]['SongbookAbbr'] ?? '');
        $bookCount[$abbr] = ($bookCount[$abbr] ?? 0) + 1;
    }
    $bookSpan = count($bookCount);

    /* A member is "distinct?" when another member shares its OFFICIAL songbook
       (same title, same published hymnal, different number → almost always a
       different hymn). */
    $distinct = [];
    foreach ($members as $sid) {
        $abbr = (string)($songs[$sid]['SongbookAbbr'] ?? '');
        $isOfficial = (int)($songs[$sid]['IsOfficial'] ?? 0) === 1;
        $distinct[$sid] = ($isOfficial && ($bookCount[$abbr] ?? 0) >= 2);
    }
    $hasOfficialCollision = in_array(true, $distinct, true);

    if ($bookSpan >= 2) {
        $section = 'cross';
    } else {
        /* Single songbook — official → likely distinct (§2); else import dupe (§3). */
        $onlyOfficial = (int)($songs[$members[0]]['IsOfficial'] ?? 0) === 1;
        $section = $onlyOfficial ? 'official' : 'other';
    }

    $sectioned[$section][] = [
        'members'  => $members,
        'best'     => $best,
        'signals'  => array_keys($signalsFired),
        'distinct' => $distinct,
        'hasOfficialCollision' => $hasOfficialCollision,
    ];
}

/* Sort each section by descending confidence so the strongest candidates lead. */
foreach ($sectioned as &$bucket) {
    usort($bucket, static fn($a, $b) => $b['best']['score'] <=> $a['best']['score']);
}
unset($bucket);

$totalClusters = count($sectioned['cross']) + count($sectioned['official']) + count($sectioned['other']);

/* ---- Render helpers ---- */
$songLabel = static function (array $s): string {
    $num = ($s['Number'] !== null && $s['Number'] !== '') ? (' #' . (int)$s['Number']) : '';
    return htmlspecialchars((string)$s['SongId'] . ' · ' . (string)$s['Title']
        . ' [' . (string)$s['SongbookAbbr'] . $num . ']', ENT_QUOTES);
};

/* Confidence badge colour by tier. */
$confClass = static fn(string $c): string => match ($c) {
    'high'   => 'bg-danger',
    'medium' => 'bg-warning text-dark',
    default  => 'bg-secondary',
};

/* Signal chips: hard-ids + the fuzzy sub-scores that meaningfully fired. */
$signalChips = static function (array $best, array $signals): string {
    $chips = [];
    $map = ['shared-iswc' => 'ISWC', 'shared-ccli' => 'CCLI', 'shared-isrc' => 'ISRC'];
    foreach ($signals as $sig) {
        if (isset($map[$sig])) {
            $chips[] = '<span class="badge bg-success-subtle text-success-emphasis border border-success-subtle me-1">'
                     . 'shared ' . $map[$sig] . '</span>';
        }
    }
    if ($best['title']   >= 0.85) { $chips[] = '<span class="badge bg-body-secondary me-1">title</span>'; }
    if ($best['lyrics']  >= 0.80) { $chips[] = '<span class="badge bg-body-secondary me-1">first line</span>'; }
    if ($best['authors'] >  0.0)  { $chips[] = '<span class="badge bg-body-secondary me-1">shared writer</span>'; }
    return implode('', $chips);
};

require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-favicon.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Duplicate &amp; Counterpart Review — iHymns Admin</title>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-libs.php'; ?>
</head>
<body>
<?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-nav.php'; ?>

<main class="container-fluid py-4">
    <div class="mb-3">
        <h1 class="h3 mb-1"><i class="bi bi-git-compare me-2"></i>Duplicate &amp; Counterpart Review</h1>
        <p class="text-secondary small mb-0" style="max-width:60rem;">
            Candidate groups scored by the similarity engine — <strong>title + first lyric line +
            writers/composers</strong>, plus any shared ISWC / CCLI / ISRC (a shared code is a certain
            match). <strong>Merge</strong> true duplicates (irreversible). Two songs sharing a title
            <em>within one published hymnal</em> are almost always different hymns — they get a guarded
            section of their own (#1215).
        </p>
    </div>

    <?php if ($totalClusters === 0): ?>
        <div class="alert alert-success"><i class="bi bi-check-circle me-1"></i>No potential duplicates or counterparts detected.</div>
    <?php endif; ?>

    <?php
    /* ---- Unified cluster renderer. ---- */
    $renderCluster = function (array $cl, string $section) use ($songs, $songLabel, $confClass, $signalChips, $lyricsCount, $canMerge): void {
        $members = $cl['members'];
        $best    = $cl['best'];
        $pct     = (int)round($best['score'] * 100);
        $isOfficialSection = ($section === 'official');

        echo '<div class="card mb-2 dup-cluster" data-section="' . htmlspecialchars($section, ENT_QUOTES) . '">';
        echo '<div class="card-body py-2">';

        /* Header: confidence + signals (+ the §2 warning). */
        echo '<div class="d-flex flex-wrap align-items-center gap-2 mb-2">';
        echo '<span class="badge ' . $confClass($best['confidence']) . '" title="Composite similarity">'
           . $pct . '% · ' . htmlspecialchars($best['confidence'], ENT_QUOTES) . '</span>';
        echo '<span class="small">' . $signalChips($best, $cl['signals']) . '</span>';
        if ($isOfficialSection) {
            echo '<span class="text-warning-emphasis small ms-auto">'
               . '<i class="bi bi-exclamation-triangle me-1"></i>Same title within one published hymnal — '
               . 'likely <strong>different hymns</strong>. Review before merging.</span>';
        }
        echo '</div>';

        echo '<form class="dup-form">';
        echo '<table class="table table-sm mb-2 admin-table-responsive"><thead><tr>';
        if ($canMerge) { echo '<th title="Survivor (the song to keep on merge)">Keep</th>'; }
        echo '<th title="Include in the action">Pick</th>';
        echo '<th data-col-priority="primary">Song</th>';
        echo '<th data-col-priority="secondary">Verified</th>';
        echo '<th data-col-priority="tertiary">Lyrics</th>';
        echo '<th data-col-priority="secondary">Codes</th>';
        echo '</tr></thead><tbody>';

        $first = true;
        foreach ($members as $sid) {
            $s = $songs[$sid];
            $isDistinct = !empty($cl['distinct'][$sid]);
            $abbr = (string)$s['SongbookAbbr'];
            $official = (int)($s['IsOfficial'] ?? 0) === 1;
            /* Default "Pick" = checked, except a same-official-book "distinct?" row. */
            $checked = $isDistinct ? '' : ' checked';

            echo '<tr' . ($isDistinct ? ' class="table-warning"' : '') . '>';
            if ($canMerge) {
                echo '<td><input type="radio" name="survivor" value="' . htmlspecialchars($sid, ENT_QUOTES) . '"' . ($first ? ' checked' : '') . '></td>';
            }
            echo '<td><input type="checkbox" class="dup-pick" value="' . htmlspecialchars($sid, ENT_QUOTES) . '" data-book="' . htmlspecialchars($abbr, ENT_QUOTES) . '" data-official="' . ($official ? '1' : '0') . '"' . $checked . '></td>';
            echo '<td data-col-priority="primary"><a href="/manage/editor/?song=' . urlencode($sid) . '" target="_blank" rel="noopener">' . $songLabel($s) . '</a>';
            if ($isDistinct) {
                echo ' <span class="badge bg-warning text-dark" title="Another song shares this title in the same official songbook — probably a different hymn">distinct?</span>';
            }
            echo '</td>';
            echo '<td data-col-priority="secondary">' . ((int)$s['Verified'] === 1 ? '<span class="badge bg-success">verified</span>' : '<span class="badge bg-warning text-dark">unverified</span>') . '</td>';
            echo '<td data-col-priority="tertiary">' . (int)($lyricsCount[$sid] ?? 0) . '</td>';
            $codes = array_filter([
                trim((string)($s['Iswc'] ?? '')) !== '' ? 'ISWC' : '',
                trim((string)($s['Ccli'] ?? '')) !== '' ? 'CCLI' : '',
                trim((string)($s['Isrc'] ?? '')) !== '' ? 'ISRC' : '',
            ]);
            echo '<td data-col-priority="secondary"><span class="small text-secondary">' . htmlspecialchars(implode(' ', $codes), ENT_QUOTES) . '</span></td>';
            echo '</tr>';
            $first = false;
        }
        echo '</tbody></table>';

        /* Action row — Link/Dismiss arrive in #1219. Merge: one-click for §1/§3
           (admin); §2 (same official songbook) requires a type-to-confirm. */
        echo '<div class="d-flex flex-wrap gap-2 align-items-center">';
        if ($canMerge && !$isOfficialSection) {
            echo '<button type="button" class="btn btn-sm btn-outline-danger dup-merge-btn">'
               . '<i class="bi bi-union me-1"></i>Merge picked into kept</button>';
        } elseif ($canMerge && $isOfficialSection) {
            /* Guarded merge (#1218): the curator must type the kept song-id to
               arm the button, and the request carries force=1 to clear the
               server-side same-official-songbook block. */
            echo '<span class="small text-secondary">If these really are one hymn, type the kept song-id to enable merge:</span>';
            echo '<input type="text" class="form-control form-control-sm dup-confirm-input" style="max-width:11rem" '
               . 'placeholder="e.g. ' . htmlspecialchars((string)$members[0], ENT_QUOTES) . '" autocomplete="off" spellcheck="false" aria-label="Type the kept song id to confirm merge">';
            echo '<button type="button" class="btn btn-sm btn-outline-danger dup-merge-btn" data-force="1" disabled>'
               . '<i class="bi bi-union me-1"></i>Merge picked into kept</button>';
        }
        if (!$canMerge) {
            echo '<span class="text-secondary small align-self-center">Open each song in the editor to compare.</span>';
        }
        echo '</div>';

        echo '</form></div></div>';
    };

    /* §1 — Cross-book counterparts. */
    if (!empty($sectioned['cross'])) {
        echo '<h2 class="h5 mt-4"><i class="bi bi-link-45deg me-1"></i>Cross-book counterparts (' . count($sectioned['cross']) . ')</h2>';
        echo '<p class="text-secondary small mb-2">The same hymn appearing in different songbooks — usually you want to <strong>Link</strong> these, not merge.</p>';
        foreach ($sectioned['cross'] as $cl) { $renderCluster($cl, 'cross'); }
    }
    /* §2 — Same title, one official songbook. */
    if (!empty($sectioned['official'])) {
        echo '<h2 class="h5 mt-4"><i class="bi bi-exclamation-triangle me-1"></i>Same title, one official songbook (' . count($sectioned['official']) . ')</h2>';
        echo '<p class="text-secondary small mb-2">A published hymnal rarely lists one song twice — these are almost certainly <strong>different hymns sharing a title</strong>. Listed for review; merge is guarded.</p>';
        foreach ($sectioned['official'] as $cl) { $renderCluster($cl, 'official'); }
    }
    /* §3 — Same title, non-official collection. */
    if (!empty($sectioned['other'])) {
        echo '<h2 class="h5 mt-4"><i class="bi bi-collection me-1"></i>Same title, one collection (' . count($sectioned['other']) . ')</h2>';
        echo '<p class="text-secondary small mb-2">Same title within an unstructured collection (Misc / curated grouping) — often a genuine import duplicate.</p>';
        foreach ($sectioned['other'] as $cl) { $renderCluster($cl, 'other'); }
    }
    ?>
</main>

<script>
(function () {
    'use strict';
    var CSRF = <?= json_encode($csrf) ?>;
    function toast(msg, ok) { if (window.showToast) { window.showToast(msg, ok ? 'success' : 'error'); } else { alert(msg); } }

    /* Merge: keep the survivor (radio), merge every PICKED other member into it.
       §2 buttons carry data-force="1" to clear the same-official-songbook guard. */
    document.querySelectorAll('.dup-merge-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var form = btn.closest('.dup-form');
            var force = btn.dataset.force === '1';
            var keep = form.querySelector('input[name="survivor"]:checked');
            if (!keep) { toast('Choose which song to keep.', false); return; }
            var survivor = keep.value;
            var dups = Array.prototype.map.call(form.querySelectorAll('.dup-pick:checked'), function (c) { return c.value; })
                .filter(function (id) { return id !== survivor; });
            if (!dups.length) { toast('Tick at least one other song to merge in.', false); return; }
            if (!confirm('Merge ' + dups.length + ' song(s) into ' + survivor + '? This re-points all references and deletes the other song(s). This cannot be undone.')) { return; }
            btn.disabled = true;
            var chain = Promise.resolve();
            dups.forEach(function (dup) {
                chain = chain.then(function () {
                    var params = { action: 'merge', survivor_id: survivor, duplicate_id: dup, csrf_token: CSRF };
                    if (force) { params.force = '1'; }
                    var body = new URLSearchParams(params);
                    return fetch('/manage/duplicate-songs', { method: 'POST', body: body, credentials: 'same-origin' })
                        .then(function (r) { return r.json().then(function (j) { if (!r.ok || !j.success) { throw new Error(j.error || 'Merge failed'); } }); });
                });
            });
            chain.then(function () {
                toast('Merged into ' + survivor + '.', true);
                var card = btn.closest('.dup-cluster');
                if (card) { card.remove(); }
            }).catch(function (e) { btn.disabled = false; toast(e.message || 'Merge failed.', false); });
        });
    });

    /* §2 type-to-confirm: arm the guarded merge button only while the typed
       value matches the selected survivor's song-id (case-insensitive). */
    function syncConfirm(form) {
        var input = form.querySelector('.dup-confirm-input');
        var btn   = form.querySelector('.dup-merge-btn[data-force="1"]');
        if (!input || !btn) { return; }
        var keep = form.querySelector('input[name="survivor"]:checked');
        var want = keep ? keep.value.trim().toLowerCase() : '';
        btn.disabled = !(want && input.value.trim().toLowerCase() === want);
    }
    document.querySelectorAll('.dup-form').forEach(function (form) {
        var input = form.querySelector('.dup-confirm-input');
        if (!input) { return; }
        input.addEventListener('input', function () { syncConfirm(form); });
        form.querySelectorAll('input[name="survivor"]').forEach(function (r) {
            r.addEventListener('change', function () { syncConfirm(form); });
        });
    });
})();
</script>

<?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>
</body>
</html>
