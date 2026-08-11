<?php

declare(strict_types=1);

/**
 * iHymns — Internet Archive OCR reconcile core: segmenter + scoring + persistence (#94 Phase 1)
 *
 * ELI5: this file takes a big blob of OCR text off an old scanned hymnal,
 * chops it up into "this looks like one hymn" pieces, and for each piece
 * asks "does iHymns already have this hymn in the target songbook?" It
 * NEVER writes a song — the only tables it writes to are the two audit
 * tables built for this feature (`tblIaFetchCache`'s sibling
 * `tblIaImportCandidates`).
 *
 * DETAIL:
 * -------
 * Framework-free (no `$_SERVER` / session reads — the `song_similarity.php`
 * discipline) and side-effect-free to require (functions only, no HTTP, no
 * connection opened just by including this file). The segmenter
 * (`iaRecSegmentOcr()`) is a PURE function — a string in, an array out — so
 * it is unit-tested with a hand-built fixture blob and no database at all
 * (`tests/php/test-ia-reconcile-guards.php`).
 *
 * REUSE, NEVER RE-FORK (rule #22): title matching goes through
 * `ihymns_normalize_title()` (the EXACT dedup fold, `title_normalize.php`)
 * for the exact-match pass, and `ihymns_sim_score()` (the shared
 * similarity blend, `song_similarity.php`) for the fuzzy pass. This file
 * defines NO normalise/levenshtein/jaccard function of its own — the CI
 * guard (`tests/php/test-ia-reconcile-guards.php`) bans any local function
 * whose name matches that shape.
 *
 * @see .claude/ia-ocr-94-plan.md §4  the full design this file implements
 * @see includes/title_normalize.php   ihymns_normalize_title() — the exact fold
 * @see includes/song_similarity.php   ihymns_sim_score() / ihymns_sim_normalise() — the fuzzy scorer
 * @see includes/lyric_lines_read.php  the ONE lyric-line read path (rule #25)
 * @see includes/song_soft_delete.php  songVisibleSql() — never score a soft-deleted song
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'title_normalize.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'song_similarity.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'song_soft_delete.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'lyric_lines_read.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ia_client.php'; /* iaIdentifierValid() reuse in iaRecPersistRun() */

/* Verdict thresholds (§4.4 "defensible defaults" — code constants, not
   settings rows; trivially tunable). */
const IA_REC_THRESHOLD_STRONG = 0.85;
const IA_REC_THRESHOLD_REVIEW = 0.60;

/* Running-header repeat threshold — a folded short line recurring at least
   this many times across the document is treated as a page header/footer
   and blanked before segmentation. Lower than the "on every page of a
   200-page hymnal this would trivially be dozens" ideal because a small
   audited item can legitimately have very few pages; any real multi-page
   scan clears this bar easily. */
const IA_REC_HEADER_MIN_REPEATS = 3;

/* Sanity bounds (§4.1 step 9). */
const IA_REC_MIN_BODY_LINES  = 4;
const IA_REC_MAX_BODY_LINES  = 400;
const IA_REC_MAX_CANDIDATES  = 2000;

/* =========================================================================
 * §4.1 SEGMENTER — iaRecSegmentOcr() and its _iaRec*() pipeline stages.
 *
 * Known failure modes (adversarial honesty, stated up front): prose
 * front-matter (prefaces) yields junk candidates that score gap/unscorable
 * (curator noise, not corruption); two-column scans OCR interleaved and
 * segment garbage (a verdict skew toward gap/unscorable is the tell);
 * responsive-verse/psalter books without numbers or ALL-CAPS titles
 * segment poorly; Fraktur/long-s OCR ("f" for "s") degrades scores but
 * Levenshtein tolerates scattered errors.
 * ========================================================================= */

/**
 * ELI5: turn the raw text file into clean lines, and note which "page" each
 * line was on if the scan carried form-feed page breaks.
 * WHY: `\r\n`/`\r` -> `\n`; tabs expanded; trailing spaces trimmed per
 * line. Splitting on `\f` FIRST (rather than walking char-by-char) is what
 * makes `pageGuess` a simple, correct 1-based ordinal per page — IA
 * `_djvu.txt` files vary: some carry `\f`, many separate pages with blank
 * runs only, so `pageGuess` is nullable throughout and nothing downstream
 * depends on it being present.
 *
 * @return array{lines:list<string>,pageGuess:list<int|null>}
 */
function _iaRecPrepareText(string $text): array
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $hasFormFeed = str_contains($text, "\f");
    $pages = $hasFormFeed ? explode("\f", $text) : [$text];

    $lines = [];
    $pageGuess = [];
    foreach ($pages as $pageIndex => $pageText) {
        $pageNum = $hasFormFeed ? ($pageIndex + 1) : null;
        foreach (explode("\n", $pageText) as $line) {
            $line = str_replace("\t", '    ', $line);
            $line = rtrim($line, " \t");
            $lines[] = $line;
            $pageGuess[] = $pageNum;
        }
    }
    return ['lines' => $lines, 'pageGuess' => $pageGuess];
}

/**
 * ELI5: rejoin a word that got split by a hyphen at the end of a scanned
 * line, e.g. "beauti-" / "ful" -> closer to one word.
 * WHY: `preg_replace('/(\p{Ll})-\n(\p{Ll})/u', '$1$2' . "\n", ...)` applied
 * to the WHOLE joined text, then re-split — keeps the line COUNT stable
 * (every match consumes exactly one `\n` and re-emits exactly one). ACCEPTS
 * IMPERFECTION, deliberately (plan §4.1 step 2): the regex shifts the join
 * point by exactly one character rather than fully merging the word onto
 * one line, which costs at most one Levenshtein edit — a cost the fuzzy
 * scorer already absorbs. What it DOES guarantee: the hyphen character
 * itself is always removed, so no candidate field ever shows a
 * mid-word "-" at a line boundary.
 *
 * @param list<string> $lines
 * @return list<string> same length as $lines.
 */
function _iaRecDehyphenate(array $lines): array
{
    $joined = implode("\n", $lines);
    $joined = (string)preg_replace('/(\p{Ll})-\n(\p{Ll})/u', '$1$2' . "\n", $joined);
    return explode("\n", $joined);
}

/** ELI5: fold a line down for running-header comparison — lowercase, strip
 *  digits (so "Page 12" and "Page 87" fold the same), collapse whitespace. */
function _iaRecFoldHeaderLine(string $line): string
{
    $t = mb_strtolower($line, 'UTF-8');
    $t = (string)preg_replace('/[0-9]+/', '', $t);
    $t = (string)preg_replace('/\s+/', ' ', $t);
    return trim($t);
}

/**
 * ELI5: find lines that repeat, unchanged, many times across the document
 * (a running book title / section header printed on every page) and blank
 * them out — otherwise a hymn spanning a page break gets split in two by
 * its own header.
 * WHY: §4.1 step 3. A folded form under 60 chars recurring
 * >= IA_REC_HEADER_MIN_REPEATS times is a running header/footer. Lines are
 * BLANKED (set to ''), never removed — this keeps every other line's index
 * stable, which LineStart/LineEnd and pageGuess both depend on.
 *
 * @param list<string> $lines
 * @return list<string> same length as $lines.
 */
function _iaRecStripRunningHeaders(array $lines): array
{
    $counts = [];
    foreach ($lines as $line) {
        $t = trim($line);
        if ($t === '') { continue; }
        $folded = _iaRecFoldHeaderLine($t);
        if ($folded === '' || mb_strlen($folded, 'UTF-8') >= 60) { continue; }
        $counts[$folded] = ($counts[$folded] ?? 0) + 1;
    }
    $headerFolds = [];
    foreach ($counts as $folded => $count) {
        if ($count >= IA_REC_HEADER_MIN_REPEATS) { $headerFolds[$folded] = true; }
    }
    if (!$headerFolds) { return $lines; }

    foreach ($lines as $i => $line) {
        $t = trim($line);
        if ($t === '') { continue; }
        $folded = _iaRecFoldHeaderLine($t);
        if ($folded !== '' && isset($headerFolds[$folded])) {
            $lines[$i] = '';
        }
    }
    return $lines;
}

/** Numbered-heading candidate detector regex (§4.1 step 4). Deliberately
 *  permissive — the sequence filter (step 5), not this regex, is what
 *  separates a real hymn heading from an interleaved page number. */
const IA_REC_NUMBERED_HEADING_RE =
    '/^(?:hymn|no\.?|nr\.?|number)?\s*#?\s*(\d{1,4})\s*([a-z])?\s*[.):\x{2013}\x{2010}\-]?\s*(\S.*)?$/iu';

/**
 * ELI5: find every line that COULD be a hymn-number heading (or a bare page
 * number — this stage doesn't try to tell them apart yet).
 *
 * @param list<string> $lines
 * @return list<array{lineIndex:int,number:int,letter:?string,inline:?string}>
 */
function _iaRecDetectNumberedCandidates(array $lines): array
{
    $out = [];
    foreach ($lines as $i => $line) {
        $t = trim($line);
        if ($t === '' || mb_strlen($t, 'UTF-8') > 80) { continue; }
        if (preg_match(IA_REC_NUMBERED_HEADING_RE, $t, $m) === 1) {
            $out[] = [
                'lineIndex' => $i,
                'number'    => (int)$m[1],
                'letter'    => (isset($m[2]) && $m[2] !== '') ? strtolower($m[2]) : null,
                'inline'    => (isset($m[3]) && trim($m[3]) !== '') ? trim($m[3]) : null,
            ];
        }
    }
    return $out;
}

/**
 * ELI5: hymn numbers walk forward roughly in order; a page number dropped
 * in the middle of that walk doesn't fit and gets thrown out.
 * WHY: §4.1 step 5, "the page-number disambiguator". Greedy pass over the
 * numbered candidates IN DOCUMENT ORDER, keeping each number `n` where
 * `prevKept < n <= prevKept + 50` (gap tolerance for OCR misses). ONE reset
 * to a small number (<=20) is allowed — multi-part books restart
 * numbering. Everything else is rejected (demoted to plain text — it stays
 * in the line array untouched, it just never becomes a boundary).
 *
 * @param list<array{lineIndex:int,number:int,letter:?string,inline:?string}> $candidates
 * @return array{kept:array<int,bool>,unnumbered:bool} `kept` is keyed by
 *         the candidate's INDEX in $candidates (not lineIndex).
 */
function _iaRecSequenceFilter(array $candidates): array
{
    $kept = [];
    $prevKept = 0;
    $usedReset = false;

    foreach ($candidates as $idx => $c) {
        $n = $c['number'];
        if ($n > $prevKept && $n <= $prevKept + 50) {
            $kept[$idx] = true;
            $prevKept = $n;
        } elseif (!$usedReset && $n > 0 && $n <= 20 && $n <= $prevKept) {
            $kept[$idx] = true;
            $prevKept = $n;
            $usedReset = true;
        }
        /* else: rejected — a stray page number or OCR noise. */
    }

    $total = count($candidates);
    $survived = count($kept);
    $unnumbered = $total === 0 || $survived < 5 || ($survived / max(1, $total)) < 0.30;

    return ['kept' => $kept, 'unnumbered' => $unnumbered];
}

/** Tune/meter signature — hymnals print "C.M." / "L.M." / "S.M." (with an
 *  optional trailing "D." for Double) directly under a title; a numeric
 *  meter like "8.7.8.7" is deliberately ALSO matched (second alternative)
 *  for fidelity to real hymnals, even though this codebase's own fixture
 *  avoids it (a bare-digit meter line can itself look like a numbered
 *  heading candidate — see the segmenter test's doc-block). */
const IA_REC_TUNE_SIGNATURE_RE =
    '/\b(?:[CSL]\.?\s?M\.?(?:\s?D\.?)?|\d{1,2}(?:[ .,]\s?\d{1,2}){2,})\b/u';

/** ELI5: does this line look like an author/translator attribution rather
 *  than a lyric line? WHY: a 4-digit year, "arr./alt./tr./harm.", or a
 *  trailing digit (a birth-death year range's tail) are all strong tells. */
function _iaRecIsAuthorLine(string $t): bool
{
    if (preg_match('/\b(1[5-9]\d{2}|20\d{2})\b/', $t) === 1) { return true; }
    if (preg_match('/\b(arr|alt|tr|harm)\.\s*/iu', $t) === 1) { return true; }
    if (preg_match('/\d$/', $t) === 1) { return true; }
    return false;
}

/** ELI5: is this the tune/meter line itself (e.g. "C. M." or "8.7.8.7")? */
function _iaRecIsTuneLine(string $t): bool
{
    return preg_match(IA_REC_TUNE_SIGNATURE_RE, $t) === 1;
}

/** ELI5: is this line ALL CAPS (allowing for punctuation/spaces)? */
function _iaRecIsAllCaps(string $t): bool
{
    $alpha = (string)preg_replace('/[^\p{L}]/u', '', $t);
    $alphaLen = mb_strlen($alpha, 'UTF-8');
    if ($alphaLen < 3) { return false; }
    $upper = (string)preg_replace('/[^\p{Lu}]/u', '', $t);
    return (mb_strlen($upper, 'UTF-8') / $alphaLen) >= 0.80;
}

/**
 * ELI5: for hymnals that don't number their hymns (or between numbered
 * ones), find title-shaped lines a different way: ALL-CAPS lines coming
 * right after a blank line, OR any line immediately followed by a tune/
 * meter marker.
 * WHY: §4.1 step 6. Returns a SET (line index => true) of boundary lines —
 * unioned with the kept numbered boundaries in `iaRecSegmentOcr()`, so an
 * otherwise-numbered book can still catch a stray unnumbered hymn (a
 * numbered hymnal's ordinary verse text essentially never satisfies either
 * condition, so the union is safe in both directions).
 *
 * @param list<string> $lines
 * @return array<int,bool>
 */
function _iaRecUnnumberedBoundaries(array $lines): array
{
    $boundaries = [];
    $n = count($lines);
    for ($i = 0; $i < $n; $i++) {
        $line = trim($lines[$i]);
        if ($line === '') { continue; }

        $isAllCapsTitle = mb_strlen($line, 'UTF-8') <= 60 && _iaRecIsAllCaps($line);
        $precededByBlank = ($i > 0) && trim($lines[$i - 1]) === '';

        $hasNearbyTune = false;
        for ($k = $i + 1; $k <= min($i + 2, $n - 1); $k++) {
            if (_iaRecIsTuneLine($lines[$k])) { $hasNearbyTune = true; break; }
        }

        if (($isAllCapsTitle && $precededByBlank) || $hasNearbyTune) {
            $boundaries[$i] = true;
        }
    }
    return $boundaries;
}

/** Index/TOC heading detector (§4.1 step 7). */
const IA_REC_INDEX_HEADING_RE =
    '/^(general\s+)?(index|contents|table of|first lines|alphabetical|metrical index|topical)/iu';

/**
 * ELI5: find "Index of First Lines" / "Table of Contents" style sections
 * and mark every line in them (up to the next real hymn) for exclusion —
 * otherwise every entry in an index becomes a fake, high-scoring phantom
 * candidate. Also marks any long run of lines that are mostly dot-leader
 * page references (e.g. "Amazing grace .......... 1"), even without a
 * heading, the same way.
 * WHY: §4.1 step 7. `$keptBoundaries` (line index => true, from the
 * SEQUENCE-FILTERED numbered pass only) tells this stage where it's safe to
 * resume — the next kept numbered heading after an index heading. Without a
 * later kept boundary, the exclusion runs to the end of the document.
 *
 * @param list<string> $lines
 * @param array<int,bool> $keptBoundaries
 * @return array<int,bool> line indices to exclude.
 */
function _iaRecSuppressIndexRegions(array $lines, array $keptBoundaries): array
{
    $n = count($lines);
    $excluded = [];

    for ($i = 0; $i < $n; $i++) {
        $t = trim($lines[$i]);
        if ($t === '') { continue; }
        if (preg_match(IA_REC_INDEX_HEADING_RE, $t) === 1) {
            $resumeAt = $n;
            foreach ($keptBoundaries as $bIdx => $_true) {
                if ($bIdx > $i && $bIdx < $resumeAt) { $resumeAt = $bIdx; }
            }
            for ($k = $i; $k < $resumeAt; $k++) { $excluded[$k] = true; }
        }
    }

    /* Dot-leader-page-ref sliding window: any 50-line window where >= 60%
       of its NON-BLANK lines end in a digit is excluded wholesale — catches
       an index section this codebase's heading regex didn't recognise. */
    $window = 50;
    for ($start = 0; $start + $window <= $n; $start++) {
        $nonBlank = 0; $digitEnd = 0;
        for ($k = $start; $k < $start + $window; $k++) {
            $t = trim($lines[$k]);
            if ($t === '') { continue; }
            $nonBlank++;
            if (preg_match('/\d$/', $t) === 1) { $digitEnd++; }
        }
        if ($nonBlank > 0 && ($digitEnd / $nonBlank) >= 0.60) {
            for ($k = $start; $k < $start + $window; $k++) { $excluded[$k] = true; }
        }
    }

    return $excluded;
}

/**
 * ELI5: turn the boundary line list into actual candidate hymns — figure
 * out each one's title, first lyric line, and a short excerpt.
 * WHY: §4.1 step 8.
 *
 * @param list<string> $lines
 * @param list<int|null> $pageGuess
 * @param array<int,bool> $boundaries line index => true (already
 *        index-suppressed by the caller).
 * @param array<int,array{lineIndex:int,number:int,letter:?string,inline:?string}> $numberedInfoByLine
 *        keyed by lineIndex (only for KEPT numbered boundaries).
 * @return list<array> raw candidates (pre sanity-bounds; carries the
 *         internal _nonBlankCount/_lineCount fields the caller strips).
 */
function _iaRecAssembleCandidates(array $lines, array $pageGuess, array $boundaries, array $numberedInfoByLine): array
{
    $boundaryIdx = array_keys($boundaries);
    sort($boundaryIdx);
    $n = count($lines);
    $count = count($boundaryIdx);
    $candidates = [];

    for ($b = 0; $b < $count; $b++) {
        $start = $boundaryIdx[$b];
        $end = ($b + 1 < $count) ? $boundaryIdx[$b + 1] : $n;
        $segmentLines = array_slice($lines, $start, $end - $start);
        $headingInfo = $numberedInfoByLine[$start] ?? null;

        $headingNumber = $headingInfo !== null
            ? ((string)$headingInfo['number'] . ($headingInfo['letter'] ?? ''))
            : null;

        /* rawTitle + the line index it was found on (titleLineAt), tracked
           TOGETHER rather than re-found afterwards by string equality — a
           re-search would misfire whenever the title text recurs verbatim
           later in the same segment (e.g. a chorus repeating the title). */
        $rawTitle = '';
        $titleLineAt = -1;
        if ($headingInfo !== null && $headingInfo['inline'] !== null) {
            /* The heading line ITSELF (index 0 of the segment) carries the
               inline title text — nothing to search for. */
            $rawTitle = $headingInfo['inline'];
            $titleLineAt = 0;
        } else {
            $searchFrom = ($headingInfo !== null) ? 1 : 0;
            for ($k = $searchFrom; $k < count($segmentLines); $k++) {
                $t = trim($segmentLines[$k]);
                if ($t === '') { continue; }
                if (_iaRecIsTuneLine($t) || _iaRecIsAuthorLine($t)) { continue; }
                $rawTitle = $t;
                $titleLineAt = $k;
                break;
            }
        }

        /* firstLine: search strictly AFTER the line that carries rawTitle. */
        $firstLine = '';
        $searchFrom = $titleLineAt >= 0 ? $titleLineAt + 1 : 0;
        for ($k = $searchFrom; $k < count($segmentLines); $k++) {
            $t = trim($segmentLines[$k]);
            if ($t === '') { continue; }
            if (_iaRecIsTuneLine($t) || _iaRecIsAuthorLine($t)) { continue; }
            $wordCount = count(preg_split('/\s+/u', $t, -1, PREG_SPLIT_NO_EMPTY));
            if ($wordCount < 3) { continue; }
            if (_iaRecIsAllCaps($t)) { continue; }
            $firstLine = $t;
            break;
        }

        $bodyText = implode("\n", $segmentLines);
        $bodyExcerpt = mb_substr($bodyText, 0, 1000, 'UTF-8');

        $nonBlankCount = 0;
        foreach ($segmentLines as $l) { if (trim($l) !== '') { $nonBlankCount++; } }

        $candidates[] = [
            'index'          => $b,
            'headingNumber'  => $headingNumber,
            'pageGuess'      => $pageGuess[$start] ?? null,
            'rawTitle'       => $rawTitle,
            'firstLine'      => $firstLine,
            'bodyExcerpt'    => $bodyExcerpt,
            'lineStart'      => $start,
            'lineEnd'        => $end,
            '_nonBlankCount' => $nonBlankCount,
            '_lineCount'     => count($segmentLines),
        ];
    }

    return $candidates;
}

/**
 * ELI5: throw out anything too short to be a real hymn or absurdly long
 * (a segmentation run-on), and hard-cap the whole run so a garbage OCR blob
 * can never produce an unbounded result set.
 * WHY: §4.1 step 9. Drops < IA_REC_MIN_BODY_LINES non-blank lines or
 * > IA_REC_MAX_BODY_LINES total lines; caps at IA_REC_MAX_CANDIDATES.
 *
 * @param list<array> $candidates
 * @return list<array> the public candidate shape (internal fields stripped).
 */
function _iaRecApplySanityBounds(array $candidates): array
{
    $out = [];
    foreach ($candidates as $c) {
        if ($c['_nonBlankCount'] < IA_REC_MIN_BODY_LINES) { continue; }
        if ($c['_lineCount'] > IA_REC_MAX_BODY_LINES) { continue; }
        unset($c['_nonBlankCount'], $c['_lineCount']);
        $out[] = $c;
        if (count($out) >= IA_REC_MAX_CANDIDATES) { break; }
    }
    foreach ($out as $i => &$c) { $c['index'] = $i; }
    unset($c);
    return $out;
}

/**
 * ELI5: the whole pipeline — raw OCR text in, a clean list of candidate
 * hymns out.
 * WHY: PURE (no DB, no I/O) — `tests/php/test-ia-reconcile-guards.php`
 * exercises this directly against a hand-built fixture blob. Returns `[]`
 * when the blob yields zero candidates (the caller renders "could not
 * segment" rather than an empty-but-green table).
 *
 * @param array $opts reserved for future tuning (unused in Phase 1).
 * @return list<array{index:int,headingNumber:?string,pageGuess:?int,rawTitle:string,firstLine:string,bodyExcerpt:string,lineStart:int,lineEnd:int}>
 */
function iaRecSegmentOcr(string $text, array $opts = []): array
{
    $prepared = _iaRecPrepareText($text);
    $lines = $prepared['lines'];
    $pageGuess = $prepared['pageGuess'];

    $lines = _iaRecDehyphenate($lines);
    $lines = _iaRecStripRunningHeaders($lines);

    $numbered = _iaRecDetectNumberedCandidates($lines);
    $seq = _iaRecSequenceFilter($numbered);

    $keptBoundaries = [];
    $numberedInfoByLine = [];
    foreach ($numbered as $idx => $c) {
        if (!empty($seq['kept'][$idx])) {
            $keptBoundaries[$c['lineIndex']] = true;
            $numberedInfoByLine[$c['lineIndex']] = $c;
        }
    }

    $unnumberedBoundaries = _iaRecUnnumberedBoundaries($lines);

    $excluded = _iaRecSuppressIndexRegions($lines, $keptBoundaries);
    foreach ($excluded as $i => $_true) {
        $lines[$i] = '';
        unset($keptBoundaries[$i], $unnumberedBoundaries[$i], $numberedInfoByLine[$i]);
    }

    $allBoundaries = $keptBoundaries + $unnumberedBoundaries; /* union of keys */
    if (!$allBoundaries) {
        return [];
    }

    $raw = _iaRecAssembleCandidates($lines, $pageGuess, $allBoundaries, $numberedInfoByLine);
    return _iaRecApplySanityBounds($raw);
}

/* =========================================================================
 * §4.2 SONGBOOK FEATURES — one bound-param query, narrowed to one book.
 * ========================================================================= */

/**
 * ELI5: pull every song in ONE songbook, in the exact shape the scorer
 * needs to compare against an OCR candidate.
 * WHY: mirrors `includes/tools/build-song-link-suggestions.php`'s query
 * shape (rule #22), narrowed to a single book. `@disabled-visible` does
 * NOT apply — `songVisibleSql()` still excludes SOFT-DELETED songs (a
 * purged song must not resurrect as a phantom match), matching that
 * builder's own `#1694` comment.
 *
 * @return list<array{songId:string,number:?int,title:string,normExact:string,normTitle:string,normFirstLine:string,authors:string}>
 */
function iaRecSongFeatures(\mysqli $db, string $songbookAbbr): array
{
    $stmt = $db->prepare(
        "SELECT s.SongId, s.Number, s.Title, s.NormalizedTitle
           FROM tblSongs s
          WHERE s.SongbookAbbr = ? AND " . songVisibleSql($db, 's')
        /* @disabled-visible: admin surface (rule #1765) — this audit is
           reachable only via /manage/ia-reconcile (edit_songs-gated); a
           song in a disabled songbook still deserves reconcile scoring so
           a curator reviewing that book sees the same picture as any
           other book. */
    );
    $stmt->bind_param('s', $songbookAbbr);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    $songIds = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
        $songIds[] = (string)$row['SongId'];
    }
    $stmt->close();

    /* #1235 P4 (rule #25) — first lines come from the ONE read path,
       chunked internally. Only an un-migrated install falls back to the
       marked lines-json-fallback per-song read below. */
    $mirrorReady = lyricLinesMirrorPresent($db);
    $firstLineBySid = $mirrorReady ? lyricLinesFirstLineMap($db, $songIds) : [];

    $out = [];
    foreach ($rows as $row) {
        $sid = (string)$row['SongId'];
        $firstLine = $mirrorReady
            ? ($firstLineBySid[$sid] ?? '')
            : _iaRecLegacyFirstLine($db, $sid);

        $normalizedTitle = trim((string)$row['NormalizedTitle']);
        $normExact = $normalizedTitle !== '' ? $normalizedTitle : ihymns_normalize_title((string)$row['Title']);

        $out[] = [
            'songId'        => $sid,
            'number'        => $row['Number'] !== null ? (int)$row['Number'] : null,
            'title'         => (string)$row['Title'],
            'normExact'     => $normExact,
            'normTitle'     => ihymns_sim_normalise((string)$row['Title']),
            'normFirstLine' => ihymns_sim_normalise($firstLine),
            'authors'       => '', /* deliberately unused — see §4.3 */
        ];
    }
    return $out;
}

/**
 * ELI5: on an un-migrated install (no tblLyricLines mirror yet), find a
 * song's first lyric line the old way.
 * WHY: lines-json-fallback (#1235 P4) — mirrors the correlated-subquery
 * pattern in `includes/tools/build-song-link-suggestions.php`, but as a
 * per-song read since `iaRecSongFeatures()` is called for one songbook at
 * a time (not the whole corpus). NEVER reached once
 * `lyricLinesMirrorPresent()` is true — `tests/php/test-component-json-guard.php`
 * requires this exact marker phrase nearby for the LinesJson reference below
 * to be considered gated.
 */
function _iaRecLegacyFirstLine(\mysqli $db, string $songId): string
{
    $stmt = $db->prepare(
        "SELECT LinesJson FROM tblSongComponents
          WHERE SongId = ? ORDER BY SortOrder ASC LIMIT 1"
    );
    $stmt->bind_param('s', $songId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    $stmt->close();
    if ($row === null || $row[0] === null) { return ''; }
    $lines = json_decode((string)$row[0], true);
    if (!is_array($lines)) { return ''; }
    foreach ($lines as $ln) {
        $ln = trim((string)$ln);
        if ($ln !== '') { return $ln; }
    }
    return '';
}

/* =========================================================================
 * §4.3 SCORING — reuse ihymns_sim_score() / ihymns_normalize_title(), never
 * re-fork the maths.
 * ========================================================================= */

/**
 * ELI5: OCR candidates carry no author data at all, so the shared scorer's
 * author sub-score is always zero — meaning a "perfect" OCR match can never
 * reach 1.0 under the unmodified blend. This just rescales so the
 * thresholds still mean what they say.
 * WHY: `ihymns_sim_authors_jaccard('', $anything) === 0.0`, so the blend's
 * maximum attainable value with an empty `authors` field is
 * `0.50 (title) + 0.35 (lyrics) = 0.85`. Dividing by 0.85 (clamped to 1.0)
 * is a PRESENTATION rescale of the shared scorer's OUTPUT — the blend
 * itself still comes ONLY from `ihymns_sim_score()` (rule #22).
 */
function iaRecAdjustedScore(array $simResult): float
{
    $adjusted = ((float)($simResult['score'] ?? 0.0)) / 0.85;
    return $adjusted > 1.0 ? 1.0 : $adjusted;
}

/** ELI5: split a folded string into its unique word tokens. */
function _iaRecTokenise(string $s): array
{
    $s = trim($s);
    if ($s === '') { return []; }
    return array_values(array_unique(array_filter(explode(' ', $s), static fn($t) => $t !== '')));
}

/**
 * ELI5: for every OCR candidate, find the best-matching song in the target
 * songbook (or decide there isn't one) and hand back a verdict.
 * WHY: §4.3, in order — (1) exact pass via `ihymns_normalize_title()`
 * against a prebuilt hash map (tries rawTitle then firstLine — covers
 * first-line-titled hymnals); (2) unscorable pass — both
 * `ihymns_sim_normalise()` folds are '' (non-Latin script stripped to
 * nothing by iconv TRANSLIT); (3) a word-token blocking prefilter so the
 * pairwise work is O(candidates x shortlist), not O(N x M); (4) fuzzy pass
 * — `ihymns_sim_score()` called TWICE per shortlisted song (straight +
 * title/first-line CROSSED — covers "hymnal titles by first line" vs
 * "iHymns titles by title" and vice versa), rescaled by
 * `iaRecAdjustedScore()`, keeping the best; (5) verdict from the two
 * threshold constants. Also computes the REVERSE coverage: songs whose best
 * INCOMING adjusted score never reached the review threshold — the
 * "in songbook, not found in OCR" list.
 *
 * @param list<array> $candidates from iaRecSegmentOcr() (or hand-built,
 *        same shape, for a test).
 * @param list<array> $songFeatures from iaRecSongFeatures() (or hand-built).
 * @param array{thresholdStrong?:float,thresholdReview?:float} $opts
 * @return array{candidates:list<array>,notFoundSongs:list<array>,summary:array{counts:array<string,int>,coveragePct:float}}
 */
function iaRecScoreCandidates(array $candidates, array $songFeatures, array $opts = []): array
{
    $thresholdStrong = (float)($opts['thresholdStrong'] ?? IA_REC_THRESHOLD_STRONG);
    $thresholdReview = (float)($opts['thresholdReview'] ?? IA_REC_THRESHOLD_REVIEW);

    $exactMap = [];
    foreach ($songFeatures as $song) {
        $ne = (string)($song['normExact'] ?? '');
        if ($ne !== '' && !isset($exactMap[$ne])) {
            $exactMap[$ne] = $song;
        }
    }

    $tokenIndex = [];
    foreach ($songFeatures as $si => $song) {
        $blob = (string)($song['normTitle'] ?? '') . ' ' . (string)($song['normFirstLine'] ?? '');
        foreach (_iaRecTokenise($blob) as $tok) {
            $tokenIndex[$tok][$si] = true;
        }
    }

    $scored = [];
    $bestIncomingBySong = []; /* songId => best adjusted score seen from any candidate */

    foreach ($candidates as $cand) {
        $rawTitle = (string)($cand['rawTitle'] ?? '');
        $firstLine = (string)($cand['firstLine'] ?? '');

        $out = $cand;
        $out['bestSongId'] = null;
        $out['bestScore']  = null;
        $out['verdict']    = 'gap';

        /* 1. exact pass. */
        $exactHit = null;
        $ne1 = ihymns_normalize_title($rawTitle);
        if ($ne1 !== '' && isset($exactMap[$ne1])) {
            $exactHit = $exactMap[$ne1];
        } else {
            $ne2 = ihymns_normalize_title($firstLine);
            if ($ne2 !== '' && isset($exactMap[$ne2])) {
                $exactHit = $exactMap[$ne2];
            }
        }
        if ($exactHit !== null) {
            $sid = (string)$exactHit['songId'];
            $out['bestSongId'] = $sid;
            $out['bestScore']  = 1.000;
            $out['verdict']    = 'exact';
            $scored[] = $out;
            $bestIncomingBySong[$sid] = max($bestIncomingBySong[$sid] ?? 0.0, 1.0);
            continue;
        }

        /* 2. unscorable pass. */
        $candNormTitle = ihymns_sim_normalise($rawTitle);
        $candNormFirst = ihymns_sim_normalise($firstLine);
        if ($candNormTitle === '' && $candNormFirst === '') {
            $out['verdict'] = 'unscorable';
            $scored[] = $out;
            continue;
        }

        /* 3. blocking prefilter. */
        $shortlist = [];
        foreach (_iaRecTokenise($candNormTitle . ' ' . $candNormFirst) as $tok) {
            if (isset($tokenIndex[$tok])) {
                foreach ($tokenIndex[$tok] as $si => $_true) { $shortlist[$si] = true; }
            }
        }
        if (!$shortlist) {
            $scored[] = $out; /* verdict stays 'gap' — empty shortlist */
            continue;
        }

        /* 4. fuzzy pass — straight + crossed, keep the best. */
        $bestAdj = 0.0;
        $bestSong = null;
        foreach (array_keys($shortlist) as $si) {
            $song = $songFeatures[$si];
            $songFeature = [
                'normTitle'     => (string)($song['normTitle'] ?? ''),
                'normFirstLine' => (string)($song['normFirstLine'] ?? ''),
                'authors'       => '',
            ];
            $straight = ihymns_sim_score(
                ['normTitle' => $candNormTitle, 'normFirstLine' => $candNormFirst, 'authors' => ''],
                $songFeature
            );
            $crossed = ihymns_sim_score(
                ['normTitle' => $candNormFirst, 'normFirstLine' => $candNormTitle, 'authors' => ''],
                $songFeature
            );
            $adj = max(iaRecAdjustedScore($straight), iaRecAdjustedScore($crossed));

            $sid = (string)$song['songId'];
            $bestIncomingBySong[$sid] = max($bestIncomingBySong[$sid] ?? 0.0, $adj);

            if ($adj > $bestAdj) {
                $bestAdj = $adj;
                $bestSong = $song;
            }
        }

        if ($bestSong !== null) {
            $out['bestSongId'] = (string)$bestSong['songId'];
            $out['bestScore']  = round($bestAdj, 3);
        }
        if ($bestAdj >= $thresholdStrong) {
            $out['verdict'] = 'strong';
        } elseif ($bestAdj >= $thresholdReview) {
            $out['verdict'] = 'review';
        } else {
            $out['verdict'] = 'gap';
        }
        $scored[] = $out;
    }

    $notFoundSongs = [];
    foreach ($songFeatures as $song) {
        $sid = (string)$song['songId'];
        $best = $bestIncomingBySong[$sid] ?? 0.0;
        if ($best < $thresholdReview) {
            $notFoundSongs[] = $song;
        }
    }

    $counts = ['exact' => 0, 'strong' => 0, 'review' => 0, 'gap' => 0, 'unscorable' => 0];
    foreach ($scored as $s) {
        if (isset($counts[$s['verdict']])) { $counts[$s['verdict']]++; }
    }
    $totalSongs = count($songFeatures);
    $coveredSongs = $totalSongs - count($notFoundSongs);
    $coveragePct = $totalSongs > 0 ? round(($coveredSongs / $totalSongs) * 100, 1) : 0.0;

    return [
        'candidates'    => $scored,
        'notFoundSongs' => $notFoundSongs,
        'summary'       => ['counts' => $counts, 'coveragePct' => $coveragePct],
    ];
}

/* =========================================================================
 * §4.4 PERSISTENCE — D1 option A (RESOLVED, see .claude/ia-ocr-94-plan.md
 * banner). Writes ONLY to tblIaImportCandidates — never a song-content
 * table (mechanically enforced by tests/php/test-ia-reconcile-guards.php).
 * ========================================================================= */

/** ELI5: does `tblIaImportCandidates` exist on this database right now? */
function _iaRecCandidatesTableExists(\mysqli $db): bool
{
    static $cached = null;
    if ($cached !== null) { return $cached; }
    try {
        $stmt = $db->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblIaImportCandidates' LIMIT 1"
        );
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();
        return $cached = $exists;
    } catch (\Throwable $e) {
        return $cached = false;
    }
}

/**
 * ELI5: save this run's scored candidates so re-visiting the audit page
 * later shows the same results without re-downloading/re-scoring, and so a
 * Phase-2 curator decision (still all `ReviewState='none'` in Phase 1)
 * would survive a re-run.
 * WHY: §4.4. Table-existence-gated + try/catch (un-migrated -> return
 * false; the page shows an amber "results not persisted" notice and still
 * renders everything from the in-memory $scored array). Chunked
 * `INSERT ... ON DUPLICATE KEY UPDATE` keyed on `uq_Item_Book_Fingerprint`
 * — updates bookkeeping columns ONLY, NEVER
 * `ReviewState`/`ReviewedBy`/`ReviewedAt`/`ReviewNote` (a Phase-2 curator
 * decision must survive every re-run). Then prunes rows whose `RunSha` has
 * gone stale AND are still `ReviewState='none'` — a reviewed row persists
 * even off the current run (the `tblSongLinkSuggestionsDismissed` lesson).
 *
 * @param string $runSha sha256 of the fulltext payload this run scored
 *        (= the cached fulltext's own sha256).
 * @param array $scored the `candidates` array from iaRecScoreCandidates().
 * @return bool true on success (or nothing to persist), false when the
 *         table doesn't exist yet or a DB error occurred mid-write.
 */
function iaRecPersistRun(\mysqli $db, string $identifier, string $songbookAbbr, string $runSha, array $scored): bool
{
    if (!iaIdentifierValid($identifier)) {
        return false;
    }
    if (!_iaRecCandidatesTableExists($db)) {
        return false;
    }

    try {
        $db->begin_transaction();

        foreach ($scored as $cand) {
            $normTitle = ihymns_normalize_title((string)($cand['rawTitle'] ?? ''));
            $fingerprint = hash('sha256', $normTitle . "\n" . ihymns_sim_normalise((string)($cand['firstLine'] ?? '')));

            $headingNumber = $cand['headingNumber'] ?? null;
            $pageGuess     = $cand['pageGuess'] ?? null;
            $rawTitle      = (string)($cand['rawTitle'] ?? '');
            $firstLine     = (string)($cand['firstLine'] ?? '');
            $bodyExcerpt   = (string)($cand['bodyExcerpt'] ?? '');
            $lineStart     = (int)($cand['lineStart'] ?? 0);
            $lineEnd       = (int)($cand['lineEnd'] ?? 0);
            $segmentIndex  = (int)($cand['index'] ?? 0);
            $bestSongId    = $cand['bestSongId'] ?? null;
            $bestScore     = $cand['bestScore'] ?? null;
            $verdict       = (string)($cand['verdict'] ?? 'gap');

            $stmt = $db->prepare(
                "INSERT INTO tblIaImportCandidates
                    (Identifier, SongbookAbbr, RunSha, SegmentIndex, SegmentFingerprint,
                     HeadingNumber, PageGuess, RawTitle, NormTitle, FirstLine, BodyExcerpt,
                     LineStart, LineEnd, BestSongId, BestScore, Verdict)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    RunSha = VALUES(RunSha), SegmentIndex = VALUES(SegmentIndex),
                    HeadingNumber = VALUES(HeadingNumber), PageGuess = VALUES(PageGuess),
                    RawTitle = VALUES(RawTitle), NormTitle = VALUES(NormTitle),
                    FirstLine = VALUES(FirstLine), BodyExcerpt = VALUES(BodyExcerpt),
                    LineStart = VALUES(LineStart), LineEnd = VALUES(LineEnd),
                    BestSongId = VALUES(BestSongId), BestScore = VALUES(BestScore),
                    Verdict = VALUES(Verdict)"
                /* Deliberately NOT touching ReviewState/ReviewedBy/ReviewedAt/
                   ReviewNote — a Phase-2 curator decision survives every re-run. */
            );
            $stmt->bind_param(
                'sssississssiisds',
                $identifier, $songbookAbbr, $runSha, $segmentIndex, $fingerprint,
                $headingNumber, $pageGuess, $rawTitle, $normTitle, $firstLine, $bodyExcerpt,
                $lineStart, $lineEnd, $bestSongId, $bestScore, $verdict
            );
            $stmt->execute();
            $stmt->close();
        }

        /* Prune stale unreviewed rows from a PRIOR run of this (item, book)
           pair — reviewed rows persist regardless of RunSha. */
        $prune = $db->prepare(
            "DELETE FROM tblIaImportCandidates
              WHERE Identifier = ? AND SongbookAbbr = ? AND RunSha <> ? AND ReviewState = 'none'"
        );
        $prune->bind_param('sss', $identifier, $songbookAbbr, $runSha);
        $prune->execute();
        $prune->close();

        $db->commit();
        return true;
    } catch (\Throwable $e) {
        try { $db->rollback(); } catch (\Throwable $e2) { /* connection already gone */ }
        return false;
    }
}
