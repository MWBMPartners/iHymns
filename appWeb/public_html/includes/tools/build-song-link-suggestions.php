<?php

declare(strict_types=1);

/**
 * iHymns — Similar-titled-song suggestion builder (#808)
 *
 * Walks tblSongs and computes pairwise title / first-lyric / author
 * similarity scores, writing the high-scoring candidates into
 * tblSongLinkSuggestions. The /manage/duplicate-songs review page
 * (and the inline suggestions panel inside the editor) reads straight
 * from that table — this script is the producer side of that pipeline.
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
 *   php appWeb/public_html/includes/tools/build-song-link-suggestions.php           # default 0.80 threshold
 *   php appWeb/public_html/includes/tools/build-song-link-suggestions.php 0.75      # custom threshold
 *
 *   Web entry: /manage/duplicate-songs?action=rebuild
 *
 * NOTE: Lives under appWeb/public_html/includes/ rather than the project-root
 * tools/ directory because the deploy pipeline only mirrors public_html to the
 * remote — a project-root tools/ would be missing on the server (#937). The
 * includes/ tree is web-blocked by the parent .htaccess (Deny from all on
 * `includes/`), so this script is reachable only via PHP require, never as
 * a direct HTTP GET.
 */

if (PHP_SAPI === 'cli') {
    require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'db_mysql.php';
    $isCli = true;
} else {
    /* Web callers must have already set up auth before requiring this file. */
    require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'db_mysql.php';
    $isCli = false;
}

/* Shared similarity scorer (#1216) — the normalise/levenshtein/jaccard helpers
   used to live here as private `_bsls_*` copies; they now live in the shared
   includes/song_similarity.php so the builder, the duplicate-songs review page
   and the editor panel all score identically (CLAUDE.md modularity rule). */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'song_similarity.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'lyric_lines_read.php';

function _bsls_out(string $line): void
{
    global $isCli;
    echo $line . ($isCli ? "\n" : "<br>\n");
    if ($isCli) flush();
}

/* The normalise / levenshtein-similarity / authors-Jaccard helpers now live in
   includes/song_similarity.php as ihymns_sim_normalise() / ihymns_sim_text() /
   ihymns_sim_authors_jaccard() / ihymns_sim_score(). See #1216. */

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

/* #1235 P4 — the first-line snippet comes from the authoritative tblLyricLines mirror
   (shared bulk reader, chunked per #929), NOT tblSongComponents.LinesJson. Only an
   un-migrated install (no mirror table) falls back to the correlated LinesJson subquery. */
$mirrorReady = lyricLinesMirrorPresent($db);
$firstLineCol = $mirrorReady
    ? ''
    : ", (SELECT cmp.LinesJson FROM tblSongComponents cmp
          WHERE cmp.SongId = s.SongId ORDER BY cmp.SortOrder ASC LIMIT 1) AS FirstComponentLines"; // lines-json-fallback (#1235 P4)

/* Pull every song's title + writer/composer list (one tblSongs scan). */
$res = $db->query(
    "SELECT s.SongId,
            s.Title,
            s.Language,
            s.SongbookAbbr,
            s.Isrc,
            s.Iswc,
            s.Ccli,
            COALESCE(GROUP_CONCAT(DISTINCT w.Name SEPARATOR '|'), '') AS Writers,
            COALESCE(GROUP_CONCAT(DISTINCT c.Name SEPARATOR '|'), '') AS Composers{$firstLineCol}
       FROM tblSongs s
       LEFT JOIN tblSongWriters   w ON w.SongId = s.SongId
       LEFT JOIN tblSongComposers c ON c.SongId = s.SongId
      GROUP BY s.SongId"
);
if (!$res) {
    _bsls_out('ERROR: failed to read tblSongs: ' . $db->error);
    exit(1);
}

/* First pass — collect kept rows (non-empty normalised title) and their ids. */
$rawRows = [];
$keptIds = [];
while ($row = $res->fetch_assoc()) {
    $normTitle = ihymns_sim_normalise((string)$row['Title']);
    if ($normTitle === '') continue;
    $row['_normTitle'] = $normTitle;
    $rawRows[]  = $row;
    $keptIds[]  = (string)$row['SongId'];
}
$res->close();

/* Bulk preview-line map from the mirror (chunked internally); empty when un-migrated. */
$firstLineBySid = $mirrorReady ? lyricLinesFirstLineMap($db, $keptIds) : [];

/* Group rows into language-bucket × first-letter-of-title-bucket bags. The pairwise
   pass walks each bag, not the whole corpus. */
$bags = [];
$total = 0;
foreach ($rawRows as $row) {
    $sid       = (string)$row['SongId'];
    $normTitle = (string)$row['_normTitle'];
    if ($mirrorReady) {
        $firstLine = $firstLineBySid[$sid] ?? '';
    } else {
        /* lines-json-fallback (#1235 P4): decode the first component's LinesJson and
           take its first non-empty line. */
        $firstLine = '';
        $linesJson = (string)($row['FirstComponentLines'] ?? '');
        if ($linesJson !== '') {
            $lines = json_decode($linesJson, true);
            if (is_array($lines)) {
                foreach ($lines as $ln) {
                    $ln = trim((string)$ln);
                    if ($ln !== '') { $firstLine = $ln; break; }
                }
            }
        }
    }
    $bagKey = (string)$row['Language'] . '|' . mb_substr($normTitle, 0, 1, 'UTF-8');
    if (!isset($bags[$bagKey])) $bags[$bagKey] = [];
    $bags[$bagKey][] = [
        'songId'        => $sid,
        'normTitle'     => $normTitle,
        'normFirstLine' => ihymns_sim_normalise($firstLine),
        'authors'       => trim((string)$row['Writers'] . '|' . (string)$row['Composers'], '|'),
        'songbook'      => (string)$row['SongbookAbbr'],
        /* Hard identifiers — let a shared ISWC/CCLI/ISRC override the fuzzy
           blend to a certain match (#1216). Empty string when absent. */
        'isrc'          => trim((string)($row['Isrc'] ?? '')),
        'iswc'          => trim((string)($row['Iswc'] ?? '')),
        'ccli'          => trim((string)($row['Ccli'] ?? '')),
    ];
    $total++;
}
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
        (SongIdA, SongIdB, Score, TitleScore, LyricsScore, AuthorsScore, Confidence, `Signal`)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE Score=VALUES(Score), TitleScore=VALUES(TitleScore),
         LyricsScore=VALUES(LyricsScore), AuthorsScore=VALUES(AuthorsScore),
         Confidence=VALUES(Confidence), `Signal`=VALUES(`Signal`),
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

            /* Does this pair share a hard identifier (ISWC/CCLI/ISRC)? Then it's
               a certain match (same composition/recording) and we bypass the
               fuzzy pre-filters — a retitled arrangement can still be the same
               work. The duplicate-songs page is the GLOBAL hard-ID detector;
               here we only catch the ones that already fall in the same bucket. */
            $sharesHardId = ($a['iswc'] !== '' && strcasecmp($a['iswc'], $b['iswc']) === 0)
                         || ($a['ccli'] !== '' && strcasecmp($a['ccli'], $b['ccli']) === 0)
                         || ($a['isrc'] !== '' && strcasecmp($a['isrc'], $b['isrc']) === 0);

            /* Cheap pre-filter (fuzzy only): if normalised titles differ in
               length by >5 chars they're almost certainly different hymns;
               skip the levenshtein() to save cycles. */
            if (!$sharesHardId && abs(strlen($a['normTitle']) - strlen($b['normTitle'])) > 5) continue;

            $sim = ihymns_sim_score($a, $b);
            /* Short-circuit on hopeless titles — but not when a hard identifier
               vouches for the pair. */
            if ($sim['signal'] === 'fuzzy' && $sim['title'] < 0.6) continue;
            if ($sim['score'] < $threshold) continue;

            $score        = $sim['score'];
            $titleScore   = $sim['title'];
            $lyricsScore  = $sim['lyrics'];
            $authorsScore = $sim['authors'];
            $confidence   = $sim['confidence'];
            $signal       = $sim['signal'];

            $insStmt->bind_param('ssddddss', $idA, $idB, $score, $titleScore, $lyricsScore, $authorsScore, $confidence, $signal);
            $insStmt->execute();
            $inserted++;
        }
    }
}
$insStmt->close();

_bsls_out("Inserted {$inserted} suggestion(s) at score >= {$threshold}.");
_bsls_out('Done.');
