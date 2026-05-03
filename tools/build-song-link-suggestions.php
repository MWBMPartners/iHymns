<?php

declare(strict_types=1);

/**
 * iHymns — Similar-titled-song suggestion builder (#808)
 *
 * Walks tblSongs and computes pairwise title / first-lyric / author
 * similarity scores, writing the high-scoring candidates into
 * tblSongLinkSuggestions. The /manage/song-link-suggestions admin
 * page (and the inline suggestions panel inside the editor) reads
 * straight from that table — this script is the producer side of
 * that pipeline.
 *
 * Pairs are pruned early to keep the cost manageable on a 12k-row
 * catalogue:
 *   - Same language only (cross-language similarity is too noisy
 *     for a heuristic pass).
 *   - Same first letter of normalised title (collapses the corpus
 *     into ~26 buckets — a typical bucket holds ~500 rows, so the
 *     pairwise cost is ~125k pair comparisons per bucket, far
 *     under the naive 76M for the whole catalogue).
 *   - Already-linked pairs (same tblSongLinks.GroupId) are skipped.
 *   - Already-dismissed pairs (tblSongLinkSuggestionsDismissed)
 *     are skipped.
 *
 * Idempotent — every run TRUNCATEs and rebuilds tblSongLinkSuggestions.
 *
 * USAGE:
 *   php tools/build-song-link-suggestions.php           # default 0.80 threshold
 *   php tools/build-song-link-suggestions.php 0.75      # custom threshold
 *
 *   Web entry: /manage/song-link-suggestions?action=rebuild
 */

if (PHP_SAPI === 'cli') {
    require_once __DIR__ . '/../appWeb/public_html/includes/db_mysql.php';
    $isCli = true;
} else {
    /* Web callers must have already set up auth before requiring this file. */
    require_once __DIR__ . '/../appWeb/public_html/includes/db_mysql.php';
    $isCli = false;
}

function _bsls_out(string $line): void
{
    global $isCli;
    echo $line . ($isCli ? "\n" : "<br>\n");
    if ($isCli) flush();
}

/**
 * Lower-cases, strips diacritics, drops leading articles + punctuation,
 * collapses whitespace. Used for both title comparison and first-line
 * comparison.
 */
function _bsls_normalise(string $s): string
{
    $s = mb_strtolower($s, 'UTF-8');
    /* Strip diacritics by transliterating to ASCII. iconv() is
       deliberately wrapped: on hosts where TRANSLIT isn't available
       we fall back to the original string rather than blanking. */
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    if ($ascii !== false) $s = $ascii;
    /* Drop leading articles. */
    $s = preg_replace('/^(the|a|an|o|el|la|le|les|der|die|das)\s+/u', '', $s) ?? $s;
    /* Strip punctuation, collapse whitespace. */
    $s = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $s) ?? $s;
    $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
    return trim($s);
}

/**
 * 1 - levenshtein(a,b) / max(|a|,|b|).
 * PHP's levenshtein() caps at 255 chars; truncate longer inputs to
 * keep the function defined. First-line comparison can hit that
 * limit on long opening verses, so the truncation is the realistic
 * shape.
 */
function _bsls_similarity(string $a, string $b): float
{
    if ($a === '' && $b === '') return 0.0;
    $a = mb_substr($a, 0, 255);
    $b = mb_substr($b, 0, 255);
    $max = max(strlen($a), strlen($b));
    if ($max === 0) return 0.0;
    $d = levenshtein($a, $b);
    return 1.0 - ($d / $max);
}

/**
 * Jaccard overlap on the lowercase token set of two pipe-joined
 * author strings. Used as a small (15%) signal — songs with shared
 * writer/composer names get a bump; differing authors get a penalty.
 */
function _bsls_authors_jaccard(string $a, string $b): float
{
    $tokA = array_filter(array_map('trim', explode('|', mb_strtolower($a, 'UTF-8'))));
    $tokB = array_filter(array_map('trim', explode('|', mb_strtolower($b, 'UTF-8'))));
    if (!$tokA && !$tokB) return 0.0;
    $setA = array_flip($tokA);
    $setB = array_flip($tokB);
    $inter = count(array_intersect_key($setA, $setB));
    $union = count($setA) + count($setB) - $inter;
    return $union > 0 ? ($inter / $union) : 0.0;
}

/* =========================================================================
 * Main
 * ========================================================================= */

$threshold = 0.80;
if ($isCli && isset($argv[1]) && is_numeric($argv[1])) {
    $threshold = max(0.0, min(1.0, (float)$argv[1]));
}

_bsls_out("Building song-link suggestions (threshold {$threshold})…");

$db = getDbMysqli();
if (!$db) {
    _bsls_out('ERROR: could not connect to database.');
    exit(1);
}

/* Probe the dependent tables — bail with a clear message rather than
   500ing if the migration hasn't run yet. */
$probe = $db->query(
    "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME IN ('tblSongLinks','tblSongLinkSuggestions','tblSongLinkSuggestionsDismissed')"
);
$present = [];
while ($probe && ($row = $probe->fetch_row())) $present[] = $row[0];
if ($probe) $probe->close();
if (count($present) < 3) {
    _bsls_out('ERROR: required tables missing — run migrate-song-links.php first. Found: ' . implode(', ', $present));
    exit(1);
}

/* Truncate the suggestion table — every run is a full rebuild. The
   dismissed-pair table is preserved across rebuilds (intentional —
   a curator's "no, different hymns" verdict is permanent). */
$db->query('TRUNCATE TABLE tblSongLinkSuggestions');

/* Pull every song's title + first lyric line + writer/composer
   list. The first-line snippet comes from tblSongComponents — we
   take the first row's first non-empty paragraph. */
$res = $db->query(
    "SELECT s.SongId,
            s.Title,
            s.Language,
            s.SongbookAbbr,
            COALESCE(GROUP_CONCAT(DISTINCT w.Name SEPARATOR '|'), '') AS Writers,
            COALESCE(GROUP_CONCAT(DISTINCT c.Name SEPARATOR '|'), '') AS Composers,
            (SELECT cmp.Body
               FROM tblSongComponents cmp
              WHERE cmp.SongId = s.SongId
              ORDER BY cmp.SortOrder ASC LIMIT 1) AS FirstComponentBody
       FROM tblSongs s
       LEFT JOIN tblSongWriters   w ON w.SongId = s.SongId
       LEFT JOIN tblSongComposers c ON c.SongId = s.SongId
      GROUP BY s.SongId"
);
if (!$res) {
    _bsls_out('ERROR: failed to read tblSongs: ' . $db->error);
    exit(1);
}

/* Group rows into language-bucket × first-letter-of-title-bucket
   bags. The pairwise pass walks each bag, not the whole corpus. */
$bags = [];
$total = 0;
while ($row = $res->fetch_assoc()) {
    $title = (string)$row['Title'];
    $normTitle = _bsls_normalise($title);
    if ($normTitle === '') continue;
    $firstLine = '';
    $body = (string)($row['FirstComponentBody'] ?? '');
    if ($body !== '') {
        /* First non-empty line of the first component. */
        foreach (preg_split('/\r?\n/', $body) ?: [] as $ln) {
            $ln = trim($ln);
            if ($ln !== '') { $firstLine = $ln; break; }
        }
    }
    $bagKey = (string)$row['Language'] . '|' . mb_substr($normTitle, 0, 1, 'UTF-8');
    if (!isset($bags[$bagKey])) $bags[$bagKey] = [];
    $bags[$bagKey][] = [
        'songId'        => (string)$row['SongId'],
        'normTitle'     => $normTitle,
        'normFirstLine' => _bsls_normalise($firstLine),
        'authors'       => trim((string)$row['Writers'] . '|' . (string)$row['Composers'], '|'),
        'songbook'      => (string)$row['SongbookAbbr'],
    ];
    $total++;
}
$res->close();
_bsls_out("Loaded {$total} song(s) into " . count($bags) . ' bucket(s).');

/* Pre-load dismissed pairs into a hash for O(1) skip. */
$dismissed = [];
$res = $db->query('SELECT SongIdA, SongIdB FROM tblSongLinkSuggestionsDismissed');
while ($res && ($row = $res->fetch_row())) {
    $dismissed[$row[0] . '|' . $row[1]] = true;
}
if ($res) $res->close();

/* Pre-load existing same-group pairs so we don't propose pairs that
   are already linked. We build a map songId -> groupId, then any
   two songs with the same group id get skipped. */
$groupOf = [];
$res = $db->query('SELECT SongId, GroupId FROM tblSongLinks');
while ($res && ($row = $res->fetch_row())) {
    $groupOf[$row[0]] = (int)$row[1];
}
if ($res) $res->close();

/* Pairwise pass per bag. */
$inserted = 0;
$insStmt = $db->prepare(
    'INSERT INTO tblSongLinkSuggestions
        (SongIdA, SongIdB, Score, TitleScore, LyricsScore, AuthorsScore)
     VALUES (?, ?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE Score=VALUES(Score), TitleScore=VALUES(TitleScore),
         LyricsScore=VALUES(LyricsScore), AuthorsScore=VALUES(AuthorsScore),
         ComputedAt=CURRENT_TIMESTAMP'
);

foreach ($bags as $bag) {
    $n = count($bag);
    for ($i = 0; $i < $n; $i++) {
        for ($j = $i + 1; $j < $n; $j++) {
            $a = $bag[$i];
            $b = $bag[$j];
            /* Same songbook isn't interesting — we want CROSS-book
               counterparts. (Two rows in the same songbook with
               near-identical titles is a curation duplicate, not
               a counterpart relationship.) */
            if ($a['songbook'] === $b['songbook']) continue;

            /* Already linked into the same group — skip. */
            if (isset($groupOf[$a['songId']], $groupOf[$b['songId']])
                && $groupOf[$a['songId']] === $groupOf[$b['songId']]) continue;

            /* Canonical order. */
            $idA = $a['songId'];
            $idB = $b['songId'];
            if ($idA > $idB) {
                [$idA, $idB] = [$idB, $idA];
                [$a, $b]     = [$b, $a];
            }
            if (isset($dismissed[$idA . '|' . $idB])) continue;

            /* Cheap pre-filter: if normalised titles differ in length
               by >5 chars they're almost certainly different hymns;
               skip the levenshtein() to save cycles. */
            if (abs(strlen($a['normTitle']) - strlen($b['normTitle'])) > 5) continue;

            $titleScore   = _bsls_similarity($a['normTitle'], $b['normTitle']);
            if ($titleScore < 0.6) continue;   /* short-circuit on hopeless titles */
            $lyricsScore  = _bsls_similarity($a['normFirstLine'], $b['normFirstLine']);
            $authorsScore = _bsls_authors_jaccard($a['authors'], $b['authors']);

            $score = (0.50 * $titleScore) + (0.35 * $lyricsScore) + (0.15 * $authorsScore);
            if ($score < $threshold) continue;

            $insStmt->bind_param('ssdddd', $idA, $idB, $score, $titleScore, $lyricsScore, $authorsScore);
            $insStmt->execute();
            $inserted++;
        }
    }
}
$insStmt->close();

_bsls_out("Inserted {$inserted} suggestion(s) at score >= {$threshold}.");
_bsls_out('Done.');
