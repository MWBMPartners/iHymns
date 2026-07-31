<?php

declare(strict_types=1);

/* #1694 — songVisibleSql() for the SongCount recomputes below (degrades to
   '1=1' on an un-migrated install, so every funnel stays byte-identical). */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'song_soft_delete.php';

/**
 * iHymns - Shared song importers (#1200 Phase 4b)
 *
 * The bulk-import parsers (TXT / OpenSong / OpenLyrics / VideoPsalm / PP6 /
 * FreeShow / Proclaim / EasyWorship / PPTX), the universal saver
 * (_bulkImport_saveSong), the ZIP walker (_bulkImport_processZip), the job
 * progress writer (_bulkImport_jobMark), the two shared validators
 * (_ietfBcp47Validate / _sanitiseArrangement) and the BULK_IMPORT_* constants
 * were EXTRACTED VERBATIM from manage/editor/api.php so the clean v2 editor API
 * (manage/editor/api2.php) can reuse ONE copy instead of forking the parser code
 * (CLAUDE.md modularity rule). Behaviour-preserving: legacy api.php require_once'es
 * this file and calls the same function names; only three lazy require paths were
 * adjusted for the new includes/ location. #1200.
 *
 * @requires PHP 8.4+ — project targets PHP 8.5; 8.4 supported for backward-compat.
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

/**
 * Cached probe for tblSongMedia (#853). The table arrives via
 * migrate-song-media.php; until that migration has been applied, the
 * song_media_* endpoints return early with a friendly 503 / empty
 * list rather than 500ing on a partly-migrated install.
 */
function _songMedia_tableExists(\mysqli $db): bool
{
    static $cached = null;
    if ($cached !== null) return $cached;
    $stmt = $db->prepare(
        "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblSongMedia' LIMIT 1"
    );
    $stmt->execute();
    $cached = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $cached;
}

/**
 * Validate + normalise an IETF BCP 47 language tag (#681).
 *
 * v1 grammar (matches the songbook editor's $validateBcp47): lowercase
 * 2-3 letter language, optional 4-letter Title Case script, optional
 * 2-letter UPPER region or 3-digit numeric area code. Variants /
 * extensions / private-use are out of scope and rejected so a tampered
 * POST can't smuggle exotic subtags into the column.
 *
 * Returns:
 *   - null  if `$tag` is empty (caller decides whether to default to
 *           'en' for a NOT NULL column or NULL for a nullable one).
 *   - the trimmed tag, capped to 35 chars (the new column width per
 *     #681), if it matches the v1 grammar.
 *   - false if the input is non-empty but malformed; caller should
 *     400 / refuse to save.
 *
 * @return string|null|false
 */
function _ietfBcp47Validate(string $raw)
{
    $tag = trim($raw);
    if ($tag === '') return null;
    if (strlen($tag) > 35) return false;
    /* Subtag breakdown:
       - language:  2-3 lowercase letters (ISO 639-1 / 639-3)
       - script:    optional 4-letter Title-case (ISO 15924)
       - region:    optional 2-letter UPPERCASE (ISO 3166-1) or 3-digit (UN M.49)
       - variant*:  zero or more — each is 5-8 alphanumeric, OR 4 chars
                    starting with a digit (the IANA grammar covers
                    ʻfonipaʼ, ʻvalenciaʼ, and digit-prefixed forms
                    like ʻ1996ʼ for German post-1996 orthography).
       Variants land last; extensions and private-use are still out
       of scope for the picker. */
    if (!preg_match(
        '/^[a-z]{2,3}(-[A-Z][a-z]{3})?(-[A-Z]{2}|-[0-9]{3})?(-([a-zA-Z0-9]{5,8}|[0-9][a-zA-Z0-9]{3}))*$/',
        $tag
    )) {
        return false;
    }
    return $tag;
}

/**
 * Sanitise an incoming `arrangement` payload from the Song Editor (#892).
 *
 * The editor sends an int[] of indices into `components[]` (with
 * repetition allowed — that is the entire reason this column exists,
 * so a refrain can play between every verse). Anything outside that
 * shape — null, empty array, non-integer entries, indices outside
 * the components range — collapses to NULL so the public render
 * falls back to plain stored SortOrder (the pre-#892 behaviour).
 *
 * Returning a JSON string (or null) so the caller can bind it
 * straight into mysqli without a second encode step.
 *
 * @param mixed $raw            The decoded `arrangement` field from the request body.
 * @param int   $componentCount Bound — every index must be 0 ≤ i < $componentCount.
 * @return string|null          JSON-encoded int array, or null when the input is
 *                              missing / empty / malformed / out-of-range.
 */
function _sanitiseArrangement($raw, int $componentCount): ?string
{
    /* #1627 — the maths moved to includes/arrangement.php so the WRITE side
       (this function, via save_song_core + the ZIP importer + the new v2
       arrangement editor) and the AUDIT side (gate G4 in
       .sql/verify-lyrics-cutover.php, #1618) cannot drift apart.
       ELI5: the rule for "is this running order valid" now lives in one file.
       Detail: if the writer's bound were ever looser than the gate's, curators
       would quietly persist arrangements the gate rejects, and the #1618 cutover
       verification would go red on real data — blocking the destructive C6 drop
       that epic #1601 exists to unblock. Behaviour here is unchanged; the name
       is kept so every existing caller is untouched. */
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'arrangement.php';
    return arrangementSanitise($raw, $componentCount);
}

/* =========================================================================
 * BULK_IMPORT_ZIP constants (#664)
 *
 * Declared up here — ABOVE the switch — because PHP's top-level `const`
 * keyword defines the constant at the moment execution reaches the line,
 * not at compile time. The bulk-import helpers at the bottom of this
 * file reference these constants from inside the switch case, so the
 * declarations have to be evaluated before the switch runs or the
 * helpers blow up with "Undefined constant" the first time anyone
 * uploads a zip.
 * ========================================================================= */

/**
 * Folder name regex: matches "Christ in Song [CIS]" (the hymnal title
 * followed by a space, an opening square bracket, the abbreviation, and a
 * closing bracket). Anchored to the entire path-segment string so we
 * don't match accidental "[" inside titles.
 */
/* Folder name regex (#780): the original "<Title> [<ABBR>]" form
   is still accepted unchanged, plus an optional language suffix
   "_<LanguageName>-<ISO>" the scrapers append so a curator (and the
   bulk-import handler) can read the language straight off disk.
       e.g. "Himnario Adventista [HA]_Spanish-es"
            "Christ in Song [CIS]_English-en"
   The captured `lang` group is the BCP 47 primary subtag (2-3 lower-
   case letters or composite forms like sr-Latn). It's validated below
   before being stamped onto the songbook row. */
const _BULK_IMPORT_FOLDER_RE = '/^(?P<name>.+?)\s*\[(?P<abbr>[A-Za-z0-9_\-]+)\](?:_(?P<langname>[^-]+)-(?P<lang>[A-Za-z][A-Za-z0-9\-]{1,34}))?$/u';

/**
 * Filename regex: "001 (CIS) - Watchman Blow The Gospel Trumpet.txt".
 * Captures the (zero-padded) number, the abbreviation in parentheses,
 * and the title. Tolerant of variable padding widths (3- or 4-digit).
 */
const _BULK_IMPORT_FILE_RE = '/^(?P<num>\d{1,5})\s*\((?P<abbr>[A-Za-z0-9_\-]+)\)\s*-\s*(?P<title>.+)\.txt$/u';

/**
 * Maximum number of *real* zip entries to process in one request — the
 * count after we strip __MACOSX/, .DS_Store and bare directory entries
 * (see _bulkImport_processZip). The cap is a defensive guardrail
 * against a malformed/zip-bomb archive that's tiny on disk but expands
 * into a million entries; real auth + the 100 MB upload cap are the
 * actual access controls. 100,000 covers any realistic future bundle
 * (every published Adventist hymnal worldwide is well under 50,000)
 * while still tripping on a true zip bomb.
 */
const _BULK_IMPORT_MAX_ENTRIES = 100000;

/**
 * Decompression-bomb defenses (#682). A zip can declare a tiny
 * compressed size but a huge uncompressed payload — a 1 MB archive
 * advertising 1 TB of content is a classic DoS shape. We reject
 * anything where:
 *
 *   - any single entry's uncompressed size exceeds 5 MiB. A song
 *     text file is at most a few KB; 5 MiB is ~3 orders of magnitude
 *     above the realistic upper bound and still small enough that one
 *     read won't blow PHP's memory limit.
 *
 *   - the cumulative uncompressed size across the archive exceeds
 *     500 MiB. The biggest real bundle we know of (CIS, ~2,300 songs)
 *     is well under 30 MiB uncompressed; 500 MiB tolerates a 15× size
 *     jump while still tripping on a true bomb.
 *
 * Both caps run BEFORE any read — we use ZipArchive::statIndex to read
 * the central-directory size header, which is what `unzip -l` shows
 * and what an attacker would have to forge to bypass the check (the
 * server-side library would then catch the discrepancy on extract).
 */
const _BULK_IMPORT_MAX_ENTRY_UNCOMPRESSED = 5 * 1024 * 1024;       // 5 MiB
const _BULK_IMPORT_MAX_TOTAL_UNCOMPRESSED = 500 * 1024 * 1024;     // 500 MiB

/**
 * Byte ceiling for ONE iHymns-interchange JSON upload (#1633).
 *
 * ELI5: a JSON file balloons when PHP turns it into arrays, so we refuse
 * files past a size we have actually measured to be safe.
 *
 * Detail — `json_decode()` is NOT a streaming parser: it materialises the
 * ENTIRE document as a PHP array graph before returning, and PHP's zval /
 * hashtable overhead makes that graph several times the file's byte size.
 * Measured on a synthetic 14,000-song / 4-verse corpus (the realistic upper
 * bound for this catalogue) the decoded graph peaked at **5.5×** the file:
 *
 *     20.36 MiB file  ->  112.36 MiB peak  (5.52x)
 *
 * `import_file` already caps every upload at 25 MiB, but 25 MiB × 5.5 ≈ 140 MiB
 * — precisely the figure that OOM'd the old whole-corpus materialiser in #929,
 * and this repo pins no `memory_limit` anywhere, so a 128M shared-hosting
 * default must be assumed. 8 MiB × 5.5 ≈ 45 MiB decoded (plus the mapped song
 * dicts, which _bulkImport_parseIHymnsJson frees the source for as it goes —
 * see there) lands comfortably inside 128M with room for the rest of the
 * request. At the measured density 8 MiB is roughly 5,000 songs.
 *
 * This is an HONEST cap, not a claim of streaming: past this size the importer
 * REFUSES the file and tells the operator to split it or use the ZIP path
 * (which walks entries one at a time and genuinely is bounded). If a true
 * streaming JSON reader ever lands, this constant is the one thing to revisit.
 *
 * @see https://www.php.net/manual/en/function.json-decode.php
 * @see https://www.php.net/manual/en/ini.core.php#ini.memory-limit
 */
const _BULK_IMPORT_IHYMNS_MAX_BYTES = 8 * 1024 * 1024;             // 8 MiB

/**
 * Section-marker → component-type map. Anything not in the map (e.g.
 * non-English refrain labels like "Coro", "Ciindululo", "Pripev") is
 * treated as a refrain section — refrain labels are language-specific
 * and the editor has a single 'refrain' / 'chorus' component type
 * regardless of the surface label.
 */
function _bulkImport_componentTypeFor(string $marker): string
{
    $m = strtolower(trim($marker));
    return [
        'verse'      => 'verse',
        'refrain'    => 'refrain',
        'chorus'     => 'chorus',
        'bridge'     => 'bridge',
        'pre-chorus' => 'pre-chorus',
        'prechorus'  => 'pre-chorus',
        'intro'      => 'intro',
        'outro'      => 'outro',
    ][$m] ?? 'refrain';
}

/**
 * Parse a single .txt file into the song-object shape that the
 * existing save_song path consumes. Returns null + a reason if the
 * body is too malformed to import (caller logs the reason in
 * errors[]).
 *
 * @param string $body       File contents (UTF-8)
 * @param string $abbrev     Songbook abbreviation parsed from the filename
 * @param string $songbook   Songbook display name parsed from the folder
 * @param int    $number     Song number parsed from the filename
 * @return array{0: ?array, 1: ?string}  [songObject, errorReason]
 */
function _bulkImport_parseTxt(string $body, string $abbrev, string $songbook, int $number): array
{
    /* Normalise line endings so a CRLF source from Windows reads the
       same as an LF source from macOS/Linux. */
    $body  = str_replace(["\r\n", "\r"], "\n", $body);
    $lines = explode("\n", $body);

    /* Find the title — first non-empty line. Anything before it
       (BOM left-overs, blank prefix) is skipped. */
    $title = '';
    $i     = 0;
    $n     = count($lines);
    while ($i < $n) {
        $l = trim($lines[$i]);
        if ($l !== '') {
            /* Strip leading UTF-8 BOM if it survived the upload. */
            $title = preg_replace('/^\xEF\xBB\xBF/', '', $l);
            $i++;
            break;
        }
        $i++;
    }
    if ($title === '') {
        return [null, 'no title line'];
    }

    /* Walk the remaining lines, alternating between section markers
       and lyric lines. A blank line ends a section's lyric block. */
    $components = [];
    $current    = null;
    while ($i < $n) {
        $line = $lines[$i];
        $trim = trim($line);

        if ($current === null) {
            /* Looking for the next section marker. Blank lines between
               sections are skipped. */
            if ($trim === '') { $i++; continue; }

            /* A bare integer is a verse with that number. Any other
               non-empty token is a labelled section (Refrain, Chorus,
               Bridge, or a non-English equivalent). */
            if (preg_match('/^\d{1,3}$/', $trim)) {
                $current = ['type' => 'verse', 'number' => (int)$trim, 'lines' => []];
            } else {
                $current = [
                    'type'   => _bulkImport_componentTypeFor($trim),
                    'number' => 0,
                    'lines'  => [],
                ];
            }
            $i++;
            continue;
        }

        /* Inside a section. Blank line → flush the section. Anything
           else → append to lyric lines (preserve internal whitespace
           but strip trailing spaces). */
        if ($trim === '') {
            if (!empty($current['lines'])) {
                $components[] = $current;
            }
            $current = null;
            $i++;
            continue;
        }
        $current['lines'][] = rtrim($line);
        $i++;
    }
    /* Final section if the file didn't end with a blank line. */
    if ($current !== null && !empty($current['lines'])) {
        $components[] = $current;
    }

    if (empty($components)) {
        return [null, 'no lyric components found'];
    }

    /* Canonical SongId format: <ABBREV>-<4-digit-padded-#>. Matches the
       /song/<id> route normalisation in router.js so URLs work straight
       away after import. */
    $songId = sprintf('%s-%04d', strtoupper($abbrev), $number);

    return [[
        'id'                 => $songId,
        'title'              => $title,
        'number'             => $number,
        'songbook'           => $abbrev,
        'songbookName'       => $songbook,
        'language'           => 'en',
        'ccli'               => '',
        'iswc'               => '',
        'tuneName'           => '',
        'copyright'          => '',
        'verified'           => 0,
        'lyricsPublicDomain' => 0,
        'musicPublicDomain'  => 0,
        'hasAudio'           => 0,
        'hasSheetMusic'      => 0,
        'writers'            => [],
        'composers'          => [],
        'arrangers'          => [],
        'adaptors'           => [],
        'translators'        => [],
        'components'         => $components,
    ], null];
}

/**
 * Persist one parsed song — INSERT-ONLY. If a row with the same
 * SongId already exists, the existing row is left untouched and the
 * call returns 'skipped'. This is the explicit user requirement for
 * bulk import (#664): never overwrite curator-edited data with
 * scraped source data.
 *
 * @return array{0: string, 1: ?string}  ['create'|'skipped'|'fail', errorMessage|null]
 */
function _bulkImport_saveSong(\mysqli $db, array $song): array
{
    $songId       = (string)$song['id'];
    $title        = (string)$song['title'];
    /* Same NULL/''/'0'/0 normalisation as the editor save paths (#797).
       The TXT bulk-import parser usually produces a positive integer
       (the file's leading "## NN" marker drives the SongId format), but
       a future caller could legitimately pass a song with no songbook
       position. Fold any non-positive / empty value to NULL so the
       column carries the canonical sentinel. */
    $rawNumber    = $song['number'] ?? null;
    $number       = ($rawNumber === null || $rawNumber === '' || (int)$rawNumber <= 0)
        ? null
        : (int)$rawNumber;
    $songbookAbbr = (string)$song['songbook'];
    $songbookName = (string)$song['songbookName'];
    /* IETF BCP 47 sanitise (#681). Bulk-import builds the song dict
       in _bulkImport_parseTxt with 'language' => 'en' hard-coded
       today, but any future caller (a CSV bulk import, a different
       parser) can post a tag here. Soft-fallback to 'en' on a
       malformed value — the bulk import already counts skipped /
       failed entries and we'd rather not abort the whole archive
       on one bad row. */
    $validLang    = _ietfBcp47Validate((string)$song['language']);
    $language     = $validLang ?? 'en';
    $copyright    = '';
    $tuneName     = null;
    $ccli         = '';
    $iswc         = null;
    $verified     = 0;
    $lyricsPD     = 0;
    $musicPD      = 0;
    $hasAudio     = 0;
    $hasSheet     = 0;

    /* Build lyrics_text for the FULLTEXT index (matches save_song). */
    $lyricsLines = [];
    foreach ($song['components'] as $comp) {
        foreach ($comp['lines'] as $line) {
            $lyricsLines[] = $line;
        }
    }
    $lyricsText = implode("\n", $lyricsLines);

    try {
        /* Pre-flight existence check — INSERT-ONLY semantics. A row
           with this SongId already in tblSongs means a curator (or a
           prior import) has owned the data; we do not touch it.
           Cheap SELECT before opening a transaction so the no-op path
           doesn't churn the binlog. */
        /* @deleted-visible: importer identity check (#1694) — "already
           imported" must MATCH a hidden row (skip, not re-mint), or a
           re-import would create a duplicate that collides on restore. */
        $existsStmt = $db->prepare('SELECT 1 FROM tblSongs WHERE SongId = ? LIMIT 1');
        $existsStmt->bind_param('s', $songId);
        $existsStmt->execute();
        $alreadyExists = $existsStmt->get_result()->fetch_row() !== null;
        $existsStmt->close();
        if ($alreadyExists) {
            return ['skipped', null];
        }

        /* #1051 — title-level dedupe. When the importer opted in, a
           normalised-title match within the same songbook counts as an
           existing song and is skipped — the SongId check above only
           catches the SAME numbering scheme, so a song imported under a
           different number would otherwise duplicate. Excludes the same
           SongId (already handled above). Centralised here so EVERY
           importer (OpenSong / VideoPsalm / OpenLP / …) inherits it. */
        if (_bulkImport_dedupeMode() === 'skip-title') {
            foreach (_bulkImport_findDuplicateCandidates($db, $songbookAbbr, $title) as $cand) {
                if ($cand['SongId'] !== $songId) {
                    return ['skipped', null];
                }
            }
        }

        $db->begin_transaction();

        /* Plain INSERT — no ON DUPLICATE KEY clause, because we
           verified above that the row doesn't exist. The unique
           index on SongId remains a hard safety net: if a concurrent
           writer inserts the same id between the check and this
           insert, we'll surface the duplicate-key error and the
           outer try/catch reports it as a per-row failure rather
           than half-succeeding. */
        $action = 'create';
        $previousData = null;

        /* #892 — schema-probe for tblSongs.ArrangementJson. The TXT /
           OpenSong parsers in this file don't produce arrangements
           today, so this is a no-op for current bulk imports — but
           future parsers (e.g. a richer XML format) may carry one,
           and the path needs to round-trip it through. */
        $hasArrangementCol = false;
        try {
            $arrProbe = $db->prepare(
                "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME   = 'tblSongs'
                    AND COLUMN_NAME  = 'ArrangementJson' LIMIT 1"
            );
            $arrProbe->execute();
            $hasArrangementCol = $arrProbe->get_result()->fetch_row() !== null;
            $arrProbe->close();
        } catch (\Throwable $_e) { /* default false */ }

        /* #892 + #1343-B — ONE dynamic-column INSERT. ArrangementJson and
           PublicId are each appended to the column / type / value lists only
           when that column exists on this env, so a single prepared statement
           covers every migration state. The base column names + type chars are
           hardcoded constants and the placeholder string is built from a
           constant count (rule #5 — the only legitimate interpolation into
           SQL); every value is bound. SongbookName denorm column dropped
           (WS-E #1013 ph2). */
        $cols  = ['SongId', 'Number', 'Title', 'SongbookAbbr', 'Language',
                  'Copyright', 'TuneName', 'Ccli', 'Iswc', 'Verified',
                  'LyricsPublicDomain', 'MusicPublicDomain', 'HasAudio',
                  'HasSheetMusic', 'LyricsText'];
        $types = 'sisssssssiiiiis';
        $vals  = [$songId, $number, $title, $songbookAbbr, $language,
                  $copyright, $tuneName, $ccli, $iswc, $verified,
                  $lyricsPD, $musicPD, $hasAudio, $hasSheet, $lyricsText];

        if ($hasArrangementCol) {
            $arrangementJson = _sanitiseArrangement(
                $song['arrangement'] ?? null,
                count($song['components'] ?? [])
            );
            $cols[] = 'ArrangementJson';
            $types .= 's';
            $vals[] = $arrangementJson;
        }

        /* #1343-B — mint a stable PublicId when the column is migrated. Gated so
           an un-migrated install still inserts cleanly under STRICT mode. */
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'song_public_id.php';
        if (songPublicId_columnReady($db)) {
            $cols[] = 'PublicId';
            $types .= 's';
            $vals[] = songPublicId_mintUnique($db);
        }

        $ph     = implode(', ', array_fill(0, count($cols), '?'));
        $insert = $db->prepare(
            'INSERT INTO tblSongs (' . implode(', ', $cols) . ') VALUES (' . $ph . ')'
        );
        $insert->bind_param($types, ...$vals);
        $insert->execute();
        $insert->close();

        /* #1235 P4/C5 — write inversion. When the tblLyricLines mirror exists, the
           shared write path makes the normalised lines authoritative and shadow-writes
           the (still-present) JSON columns from the same payload — so this import keeps
           working before AND after the C6 JSON-column drop. Inside the import
           transaction so it commits / rolls back atomically with the song. */
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'lyric_lines_sync.php';
        if (lyricLinesSyncReady($db)) {
            lyricLinesWriteComponents($db, $songId, $song['components'] ?? []);
        } else {
            /* lines-json-fallback (#1235 P4): un-migrated install (no mirror) — write
               LinesJson directly. LinesJson provably still exists here (the C6 drop
               only runs once the mirror is present, i.e. syncReady would be true). */
            $insComp = $db->prepare(
                'INSERT INTO tblSongComponents
                    (SongId, Type, Number, SortOrder, LinesJson)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $order = 0;
            foreach ($song['components'] as $comp) {
                $type   = (string)($comp['type'] ?? 'verse');
                $cNum   = isset($comp['number']) ? (int)$comp['number'] : 0;
                $lines  = json_encode($comp['lines'] ?? [], JSON_UNESCAPED_UNICODE);
                $insComp->bind_param('ssiis', $songId, $type, $cNum, $order, $lines);
                $insComp->execute();
                $order++;
            }
            $insComp->close();
        }

        /* Revision audit row (#400). Same shape as save_song writes. */
        try {
            $editor = function_exists('getCurrentUser') ? getCurrentUser() : null;
            $userId = $editor['id'] ?? null;
            $newData = json_encode($song, JSON_UNESCAPED_UNICODE);
            $rev = $db->prepare(
                'INSERT INTO tblSongRevisions
                    (SongId, UserId, Action, PreviousData, NewData, Status)
                 VALUES (?, ?, ?, ?, ?, "approved")'
            );
            $userIdParam = $userId !== null ? (int)$userId : null;
            $rev->bind_param('sisss', $songId, $userIdParam, $action, $previousData, $newData);
            $rev->execute();
            $rev->close();
        } catch (\Throwable $_e) { /* revisions are best-effort */ }

        $db->commit();

        if (function_exists('logActivity')) {
            logActivity(
                $action === 'create' ? 'song.create' : 'song.edit',
                'song',
                $songId,
                ['title' => $title, 'songbook' => $songbookAbbr, 'source' => 'bulk_import_zip']
            );
        }

        return [$action, null];
    } catch (\Throwable $e) {
        try { $db->rollback(); } catch (\Throwable $_e) {}
        return ['fail', $e->getMessage()];
    }
}

/**
 * Dedupe-on-import matcher (#1051) — shared by every importer.
 *
 * Today bulk-import dedupes only on SongId (<ABBR>-<NNNN>); a song imported
 * under a different numbering scheme slips past it and creates a true
 * duplicate. These two PURE helpers add matching on songbook + NORMALISED
 * title so importers can detect existing songs before insert and offer
 * skip / merge / replace. No DB writes; reused by the preview endpoint and
 * (next) the processZip main flow.
 */

/**
 * Normalise a title for fuzzy comparison: ASCII-fold accents, lowercase,
 * drop all punctuation, collapse whitespace. "O God, Our Help in Ages Past"
 * and "O God Our Help in Ages Past" normalise to the same string.
 */
function _bulkImport_normalizeTitle(string $title): string
{
    /* Delegate to the shared normaliser (#1064) so the bulk-import matcher,
       the lyrics-ingest song resolver and the duplicate-songs page all fold
       titles identically. require_once is idempotent + cheap. */
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'title_normalize.php';
    return ihymns_normalize_title($title);
}

/**
 * Find existing songs in the same songbook whose NORMALISED title matches
 * (exactly, or within `levThreshold` edits). Bound param on the songbook —
 * no string-interpolated SQL (per the repo SQL rules); fuzzy matching is
 * done in PHP via levenshtein() on the normalised strings.
 *
 * @return array<int,array{SongId:string,Title:string,Number:?int,matchType:string,distance:int}>
 */
function _bulkImport_findDuplicateCandidates(\mysqli $db, string $songbookAbbr, string $title, int $levThreshold = 2): array
{
    $norm = _bulkImport_normalizeTitle($title);
    if ($norm === '' || $songbookAbbr === '') {
        return [];
    }
    /* @deleted-visible: importer identity resolver (#1694) — matching a
       hidden row preserves single identity (the import is flagged against it
       rather than minting a lookalike that conflicts on restore). */
    $stmt = $db->prepare(
        "SELECT SongId, Title, Number FROM tblSongs WHERE SongbookAbbr = ? ORDER BY Number"
    );
    $stmt->bind_param('s', $songbookAbbr);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $matches = [];
    foreach ($rows as $r) {
        $cn = _bulkImport_normalizeTitle((string)$r['Title']);
        if ($cn === '') {
            continue;
        }
        if ($cn === $norm) {
            $matches[] = [
                'SongId'    => (string)$r['SongId'],
                'Title'     => (string)$r['Title'],
                'Number'    => $r['Number'] !== null ? (int)$r['Number'] : null,
                'matchType' => 'exact-normalized',
                'distance'  => 0,
            ];
            continue;
        }
        /* PHP levenshtein() caps inputs at 255 bytes — skip pathologically long titles. */
        if (strlen($cn) <= 255 && strlen($norm) <= 255) {
            $d = levenshtein($cn, $norm);
            if ($d <= $levThreshold) {
                $matches[] = [
                    'SongId'    => (string)$r['SongId'],
                    'Title'     => (string)$r['Title'],
                    'Number'    => $r['Number'] !== null ? (int)$r['Number'] : null,
                    'matchType' => 'fuzzy',
                    'distance'  => $d,
                ];
            }
        }
    }
    return $matches;
}

/**
 * Per-request dedupe mode for the current import (#1051). Set once by the
 * import action handler from the posted `dedupeMode`, read by
 * _bulkImport_saveSong() so every importer honours it without threading the
 * value through each parse loop. Values: 'off' (default — INSERT-only) |
 * 'skip-title' (skip a normalised-title match in the same songbook).
 * (Future: 'replace-title' / interactive merge.)
 */
function _bulkImport_dedupeMode(?string $set = null): string
{
    static $mode = 'off';
    if ($set !== null) {
        $mode = in_array($set, ['off', 'skip-title'], true) ? $set : 'off';
    }
    return $mode;
}

/**
 * INSERT-ONLY songbook helper. If a songbook with this Abbreviation
 * already exists, the row is left fully untouched — no rename, no
 * Name refresh — per the bulk-import contract: never overwrite
 * existing data (#664). New abbreviations get a fresh row with the
 * supplied Name; SongCount is recomputed at the end of the import
 * pass over the songs that successfully landed.
 *
 * Returns 'created' for a brand-new abbreviation, 'existing' if the
 * abbreviation was already in tblSongbooks.
 */
function _bulkImport_upsertSongbook(\mysqli $db, string $abbr, string $name, ?string $language = null): string
{
    $sel = $db->prepare('SELECT 1 FROM tblSongbooks WHERE Abbreviation = ? LIMIT 1');
    $sel->bind_param('s', $abbr);
    $sel->execute();
    $exists = $sel->get_result()->fetch_row() !== null;
    $sel->close();

    if ($exists) {
        return 'existing';
    }

    /* Auto-colour the new songbook so its badge is visually distinct
       from existing books on the home / songbooks tile grids (#677).
       The shared palette helper lives under /manage/includes/ — we
       require it lazily here so a deployment that hasn't run the
       migration to add the helper file (it's part of the same PR
       as this edit) doesn't 500 on existing imports. Best-effort —
       on a require failure we save with an empty Colour and let
       the theme default render. */
    $colour = '';
    $paletteFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'manage' . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'songbook-palette.php';
    if (is_file($paletteFile)) {
        require_once $paletteFile;
        if (function_exists('pickAutoSongbookColour')) {
            $colour = pickAutoSongbookColour($db, $abbr);
        }
    }

    /* Language captured from the folder-name suffix (#780). Validated
       against the picker's BCP 47 grammar so a malformed suffix can't
       land bad data — falls through to NULL on rejection. */
    $langTag = null;
    if ($language !== null && $language !== '') {
        $langTrim = trim($language);
        if (preg_match('/^[a-z]{2,3}(-[A-Za-z0-9]+)*$/i', $langTrim)) {
            $langTag = mb_substr($langTrim, 0, 35);
        }
    }

    if ($langTag !== null) {
        $ins = $db->prepare(
            'INSERT INTO tblSongbooks (Abbreviation, Name, Colour, SongCount, Language) VALUES (?, ?, ?, 0, ?)'
        );
        $ins->bind_param('ssss', $abbr, $name, $colour, $langTag);
    } else {
        $ins = $db->prepare(
            'INSERT INTO tblSongbooks (Abbreviation, Name, Colour, SongCount) VALUES (?, ?, ?, 0)'
        );
        $ins->bind_param('sss', $abbr, $name, $colour);
    }
    $ins->execute();
    $ins->close();
    return 'created';
}

/**
 * Update tblBulkImportJobs row state for the async progress path
 * (#676). Called by the bulk_import_zip case to mark queued →
 * running → completed / failed transitions, and by
 * _bulkImport_processZip below to bump ProcessedEntries +
 * TotalEntries every ~50 rows so the polling endpoint can render
 * a percentage.
 *
 * Status is the new ENUM value; $extra carries column → value
 * pairs to set in the same UPDATE. NULL $jobId is a no-op so
 * the synchronous fallback path can call this without a job
 * record.
 *
 * Special-case: any value 'NOW()' is emitted as the SQL function
 * (not bound) so timestamp columns get the server clock.
 */
function _bulkImport_jobMark(\mysqli $db, ?int $jobId, string $status, array $extra = []): void
{
    if ($jobId === null || $jobId <= 0) return;

    /* Schema-probe the columns on tblBulkImportJobs once per request
       so newer columns (e.g. #906's PerSongbookJson) get dropped
       from the UPDATE on pre-migration deploys. Without the filter,
       a single unknown-column extra would 1054 the entire UPDATE
       and the rest of the legit columns wouldn't get updated either.
       Static-cached so the SELECT runs once even when this function
       is called dozens of times during a long import. */
    static $knownCols = null;
    if ($knownCols === null) {
        $knownCols = [];
        try {
            $r = $db->query(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME   = 'tblBulkImportJobs'"
            );
            if ($r) {
                while ($row = $r->fetch_assoc()) {
                    $knownCols[$row['COLUMN_NAME']] = true;
                }
                $r->close();
            }
        } catch (\Throwable $_e) { /* best-effort; empty map = filter rejects everything */ }
    }

    /* Build the SET fragment — status always, plus any extras. */
    $setParts = ['Status = ?'];
    $bindTypes  = 's';
    $bindValues = [$status];
    foreach ($extra as $col => $val) {
        /* Hard whitelist of column names we accept here so a future
           caller can't accidentally splice user data into the SQL.
           tblBulkImportJobs columns only. */
        if (!preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $col)) continue;
        /* Schema gate — skip columns not present on this deploy.
           Empty $knownCols (probe failed) falls through to the
           pre-#906 behaviour and tries every extra; the catch below
           still suppresses the resulting error. */
        if (!empty($knownCols) && !isset($knownCols[$col])) continue;
        if ($val === 'NOW()') {
            $setParts[] = "{$col} = NOW()";
            continue;
        }
        $setParts[] = "{$col} = ?";
        if (is_int($val)) {
            $bindTypes .= 'i';
        } else {
            $bindTypes .= 's';
            $val = (string)$val;
        }
        $bindValues[] = $val;
    }
    $sql = 'UPDATE tblBulkImportJobs SET ' . implode(', ', $setParts) . ' WHERE Id = ?';
    $bindTypes  .= 'i';
    $bindValues[] = $jobId;
    try {
        $stmt = $db->prepare($sql);
        $stmt->bind_param($bindTypes, ...$bindValues);
        $stmt->execute();
        $stmt->close();
    } catch (\Throwable $e) {
        error_log('[_bulkImport_jobMark] ' . $e->getMessage());
    }
}

/**
 * Walk a ZIP archive and import every recognised hymnal folder + song
 * .txt file. Returns a JSON-serialisable summary.
 *
 * When $jobDb + $jobId are passed, the function updates
 * tblBulkImportJobs every PROGRESS_BATCH entries so the polling
 * endpoint can show the progress bar move. Synchronous callers
 * (the pre-#676 fallback path, future CLI tools) can omit both
 * args and the function behaves exactly as before.
 */
function _bulkImport_processZip(string $zipPath, ?\mysqli $jobDb = null, ?int $jobId = null): array
{
    /* How often to flush the progress counters back to the job row.
       Every 50 entries is ~1% of a typical 5000-song bundle — small
       enough to feel live, large enough that the per-update cost
       (one prepared UPDATE) stays under 0.5% of total runtime. */
    $PROGRESS_BATCH = 50;
    $zip = new \ZipArchive();
    if ($zip->open($zipPath) !== true) {
        return [
            'ok'    => false,
            'error' => 'Could not open uploaded file as a ZIP archive.',
        ];
    }

    $entryCount = $zip->numFiles;
    if ($entryCount > _BULK_IMPORT_MAX_ENTRIES) {
        $zip->close();
        return [
            'ok'    => false,
            'error' => sprintf(
                'ZIP has %d entries; the per-import cap is %d.',
                $entryCount, _BULK_IMPORT_MAX_ENTRIES
            ),
        ];
    }

    /* Decompression-bomb pre-flight (#682). Walk the central directory
       once and reject if any single entry — or the cumulative archive —
       declares an uncompressed size above the cap. statIndex() returns
       the size header, so we never read or decompress the entry just
       to find out it's too big. */
    $cumulativeUncompressed = 0;
    for ($k = 0; $k < $entryCount; $k++) {
        $stat = $zip->statIndex($k);
        if ($stat === false) continue;
        $size = (int)($stat['size'] ?? 0);
        if ($size > _BULK_IMPORT_MAX_ENTRY_UNCOMPRESSED) {
            $zip->close();
            return [
                'ok'    => false,
                'error' => sprintf(
                    'Entry "%s" reports an uncompressed size of %d bytes; per-entry cap is %d bytes.',
                    (string)($stat['name'] ?? "(index $k)"),
                    $size,
                    _BULK_IMPORT_MAX_ENTRY_UNCOMPRESSED
                ),
            ];
        }
        $cumulativeUncompressed += $size;
        if ($cumulativeUncompressed > _BULK_IMPORT_MAX_TOTAL_UNCOMPRESSED) {
            $zip->close();
            return [
                'ok'    => false,
                'error' => sprintf(
                    'Cumulative uncompressed size exceeds the per-import cap of %d bytes.',
                    _BULK_IMPORT_MAX_TOTAL_UNCOMPRESSED
                ),
            ];
        }
    }

    $db = getDbMysqli();

    /* Tally counters. Bulk import is INSERT-ONLY (#664) — existing
       songbook + song rows are skipped untouched, never overwritten,
       so the response reports skipped counts rather than updated. */
    $songbooksCreated         = [];
    $songbooksExisting        = [];
    $songsCreated             = 0;
    $songsSkippedExisting     = 0;
    $songsFailed              = 0;
    $errors                   = [];

    /* Skipped SongIds collector. Persisted as a JSON array on the job
       row so the "Download skipped SongIds" button on the completion
       notification can stream them as a CSV. Recording every skip
       costs ~10 bytes per entry — a 5,000-song re-import lands at
       ~50 KB of JSON, comfortably within the column. */
    $skippedSongIds           = [];

    /* Per-songbook breakdown (#906). Keyed by abbreviation; carries
       the songbook display name plus per-status counts so the import
       summary can render a "HA: 0 created, 527 skipped, 0 failed"
       row per book. Without this the curator sees only the aggregate
       totals and can't tell which songbook accounts for the skips. */
    $perSongbook              = [];   // abbr → ['name'=>str, 'created'=>int, 'skipped'=>int, 'failed'=>int, 'first_errors'=>[]]

    /* Per-failure activity-log rows (#908). Capped at 100 per import
       run to stay well under the per-request flood guard
       (IHYMNS_LOG_PER_REQUEST_CAP = 200). Overflow writes a single
       "and N more (truncated)" summary row at the end of the loop;
       the full failure detail still lives in tblBulkImportJobs.ErrorsJson
       for admins who need to drill into the omitted entries. */
    $loggedFailures           = 0;
    $loggedFailureCap         = 100;
    $logActivityAvailable     = function_exists('logActivity');

    $_perBookBump = static function (string $abbr, string $bookName, string $kind, ?string $errorMsg = null, ?string $entryName = null, ?int $songNum = null, ?string $phase = null) use (&$perSongbook, &$loggedFailures, $loggedFailureCap, $logActivityAvailable, $jobId): void {
        if ($abbr === '') $abbr = '_unknown';
        if (!isset($perSongbook[$abbr])) {
            $perSongbook[$abbr] = [
                'abbr'         => $abbr,
                'name'         => $bookName,
                'created'      => 0,
                'skipped'      => 0,
                'failed'       => 0,
                'first_errors' => [],
            ];
        }
        if ($bookName !== '' && $perSongbook[$abbr]['name'] === '') {
            $perSongbook[$abbr]['name'] = $bookName;
        }
        if (in_array($kind, ['created', 'skipped', 'failed'], true)) {
            $perSongbook[$abbr][$kind]++;
        }
        if ($kind === 'failed' && $errorMsg !== null
            && count($perSongbook[$abbr]['first_errors']) < 10
        ) {
            $perSongbook[$abbr]['first_errors'][] = [
                'entry' => $entryName ?? '',
                'error' => $errorMsg,
            ];
        }

        /* #908 — activity-log per-failure row. Only fires for the
           'failed' kind; under the cap; and when logActivity() is
           available (it's loaded by the editor bootstrap path but
           guarded here so a future call from a context that didn't
           load it doesn't 500). EntityId carries `<job_id>:<entry>`
           so the activity-log viewer can filter to "all failures
           from job N" via `EntityId LIKE '<job_id>:%'`. */
        if ($kind === 'failed'
            && $logActivityAvailable
            && $jobId !== null
            && $loggedFailures < $loggedFailureCap
        ) {
            $details = [
                'entry'         => $entryName ?? '',
                'error'         => $errorMsg ?? '',
                'songbook_abbr' => $abbr === '_unknown' ? null : $abbr,
                'phase'         => $phase ?? 'parse',
            ];
            if ($songNum !== null && $songNum > 0) {
                $details['song_number'] = $songNum;
            }
            try {
                logActivity(
                    'import.bulk_entry_failed',
                    'bulk_import_entry',
                    (string)$jobId . ':' . ($entryName ?? ''),
                    $details,
                    'failure'
                );
                $loggedFailures++;
            } catch (\Throwable $_e) {
                /* logActivity is documented as best-effort and never
                   throws — defensive catch in case a future change
                   surfaces an exception. Bulk import must keep going. */
            }
        }
    };

    /* Initial progress write — set TotalEntries to the post-filter
       count so the polling endpoint's percentage uses the right
       denominator. We don't know it precisely until we've walked
       once, so send the raw entry count as a ceiling. (#676)
       PhaseLabel (#907) gives the frontend a human-readable status
       above the progress bar even at 0% — the curator never sees a
       silent "0%" while the worker is doing real preflight work. */
    if ($jobDb !== null && $jobId !== null) {
        _bulkImport_jobMark($jobDb, $jobId, 'running', [
            'TotalEntries'     => (int)$entryCount,
            'ProcessedEntries' => 0,
            'PhaseLabel'       => 'walking-zip',
        ]);
    }
    $progressFlushCounter = 0;

    /* #907 — phase transition: walking-zip → parsing-songs. The
       walk-and-classify above (entry count, format detection) was
       phase 'walking-zip'; we're about to start the per-entry parse +
       save loop, which is the bulk of the work and updates
       ProcessedEntries every $PROGRESS_BATCH iterations. */
    if ($jobDb !== null && $jobId !== null) {
        _bulkImport_jobMark($jobDb, $jobId, 'running', [
            'PhaseLabel' => 'parsing-songs',
        ]);
    }

    /* Single pass over the archive: for each .txt entry, parse the
       enclosing folder name to learn (songbook name, abbrev), upsert
       the songbook on first encounter, then parse + save the song. */
    $songbookSeen      = [];   // abbrev → (created|existing) — caches the songbook upsert per archive
    $songbookCounters  = [];   // abbrev → next auto-assigned number (OpenSong fallback, #882)
    $songsParsedByKind = ['txt' => 0, 'opensong' => 0, 'videopsalm' => 0, 'openlyrics' => 0, 'propresenter6' => 0, 'freeshow' => 0];

    for ($i = 0; $i < $entryCount; $i++) {
        /* Periodic progress flush every $PROGRESS_BATCH iterations so
           the polling endpoint shows a moving bar. Async path only —
           sync callers pay no per-iteration cost. (#676) */
        if ($jobDb !== null && $jobId !== null
            && $i > 0 && ($i % $PROGRESS_BATCH) === 0
        ) {
            _bulkImport_jobMark($jobDb, $jobId, 'running', [
                'ProcessedEntries'      => (int)$i,
                'SongsCreated'          => (int)$songsCreated,
                'SongsSkippedExisting'  => (int)$songsSkippedExisting,
                'SongsFailed'           => (int)$songsFailed,
            ]);
        }

        $name = $zip->getNameIndex($i);
        if ($name === false) continue;

        /* Reject path-traversal attempts before we even read. The zip
           tools we ship don't produce these, but a hand-crafted
           archive could. */
        if (strpos($name, '..') !== false || str_starts_with($name, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $name)) {
            $errors[] = ['entry' => $name, 'error' => 'unsafe path — skipped'];
            continue;
        }

        /* Skip directory entries, mac metadata, and dot-files. */
        if (str_ends_with($name, '/'))                continue;
        if (str_contains($name, '__MACOSX/'))         continue;
        if (str_contains($name, '/.'))                continue;

        /* Detect file format by extension. .txt → existing plain-text
           per-song parser; .xml / .opensong → OpenSong XML parser
           (#882); .json with a top-level "Songs" array → VideoPsalm
           songbook parser (#883). Anything else is silently skipped
           so a curator can drop a hymnal folder containing mixed
           assets (PDFs, cover art) without each non-song entry
           surfacing as a parse error. */
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $kind = null;
        if ($ext === 'txt') {
            $kind = 'txt';
        } elseif ($ext === 'xml' || $ext === 'opensong') {
            $kind = 'opensong';
        } elseif ($ext === 'json') {
            $kind = 'videopsalm';
        } elseif ($ext === 'pro6') {
            $kind = 'pro6';
        } elseif ($ext === 'show') {
            $kind = 'freeshow';
        } else {
            continue;
        }

        /* VideoPsalm files (#883) carry their own songbook metadata
           inside the JSON payload — songbook display name from the
           top-level "Text", abbreviation derived from the filename or
           from the title — so they don't need (and don't follow) the
           "<Title> [<ABBR>]/" folder convention the .txt / OpenSong
           paths require. Handle them inline and continue. */
        if ($kind === 'videopsalm') {
            $body = $zip->getFromIndex($i);
            if ($body === false) {
                $errors[] = ['entry' => $name, 'error' => 'could not read entry'];
                $songsFailed++;
                continue;
            }
            [$vpBook, $vpSongs, $vpErr] = _bulkImport_parseVideoPsalmSongbook($body, $name);
            if ($vpBook === null) {
                /* Not a VideoPsalm document (or malformed JSON) — fall
                   through to the per-entry error log. The iHymns full-
                   corpus shape (lowercase songs[]) is intentionally not
                   accepted here; that path runs in-memory in the editor
                   client. */
                $errors[] = ['entry' => $name, 'error' => 'VideoPsalm parse failed: ' . ($vpErr ?? 'unknown')];
                $songsFailed++;
                continue;
            }
            $vpAbbr = (string)$vpBook['abbrev'];
            $vpName = (string)$vpBook['name'];
            if (!isset($songbookSeen[$vpAbbr])) {
                $state = _bulkImport_upsertSongbook($db, $vpAbbr, $vpName, $vpBook['language'] ?? null);
                $songbookSeen[$vpAbbr] = $state;
                if ($state === 'created') {
                    $songbooksCreated[] = $vpAbbr;
                } else {
                    $songbooksExisting[] = $vpAbbr;
                }
            }
            foreach ((array)($vpBook['parseErrors'] ?? []) as $pe) {
                $errors[] = [
                    'entry' => $name . ': ' . ($pe['entry'] ?? '?'),
                    'error' => (string)($pe['error'] ?? ''),
                ];
            }
            foreach ((array)$vpSongs as $song) {
                [$action, $saveErr] = _bulkImport_saveSong($db, $song);
                if ($action === 'create') {
                    $songsCreated++;
                    $songsParsedByKind['videopsalm']++;
                    $_perBookBump($vpAbbr, $vpName, 'created');
                } elseif ($action === 'skipped') {
                    $songsSkippedExisting++;
                    $_perBookBump($vpAbbr, $vpName, 'skipped');
                    if (isset($song['id']) && $song['id'] !== '') {
                        $skippedSongIds[] = (string)$song['id'];
                    }
                } else {
                    $errors[] = [
                        'entry' => $name . ': ' . ($song['id'] ?? '?'),
                        'error' => 'save failed: ' . $saveErr,
                    ];
                    $songsFailed++;
                    $_perBookBump($vpAbbr, $vpName, 'failed', 'save failed: ' . $saveErr, $name . ': ' . ($song['id'] ?? '?'), null, 'save');
                }
            }
            continue;
        }

        /* OpenLyrics / OpenLP (#1052): per-song .xml files that carry their
           own songbook metadata inside <songbooks><songbook name="…"
           entry="N"/></songbooks>, so — like VideoPsalm — they don't follow
           the "<Title> [<ABBR>]/" folder convention. Content-sniff the .xml
           (an OpenSong .xml has neither the namespace nor <verse name>) and,
           when it's OpenLyrics, handle it inline (songbook from the XML, or
           the file's own basename) and continue; otherwise fall through to
           the OpenSong path below. */
        if ($kind === 'opensong') {
            $peek = $zip->getFromIndex($i);
            if ($peek !== false && _bulkImport_looksLikeOpenLyrics($peek)) {
                [$olParsed, $olReason] = _bulkImport_parseOpenLyrics($peek);
                if ($olParsed === null) {
                    $errors[] = ['entry' => $name, 'error' => 'OpenLyrics parse failed: ' . $olReason];
                    $songsFailed++;
                    continue;
                }
                $olName = (string)$olParsed['songbookName'] !== ''
                    ? (string)$olParsed['songbookName']
                    : pathinfo($name, PATHINFO_FILENAME);
                $olAbbr = _bulkImport_videopsalmAbbrevFromHint($name, $olName);
                if (!isset($songbookSeen[$olAbbr])) {
                    $state = _bulkImport_upsertSongbook($db, $olAbbr, $olName, ($olParsed['language'] ?? '') ?: null);
                    $songbookSeen[$olAbbr] = $state;
                    if ($state === 'created') { $songbooksCreated[] = $olAbbr; }
                    else                       { $songbooksExisting[] = $olAbbr; }
                }
                if (!isset($songbookCounters[$olAbbr])) {
                    $songbookCounters[$olAbbr] = _bulkImport_nextSongNumberFor($db, $olAbbr);
                }
                $olNumber = (int)$olParsed['entry'] > 0 ? (int)$olParsed['entry'] : $songbookCounters[$olAbbr];
                $songbookCounters[$olAbbr] = max($songbookCounters[$olAbbr], $olNumber) + 1;
                $olSong = _bulkImport_assembleSong($olParsed, $olAbbr, $olName, $olNumber);
                [$olAction, $olErr] = _bulkImport_saveSong($db, $olSong);
                if ($olAction === 'create') {
                    $songsCreated++;
                    $songsParsedByKind['openlyrics']++;
                    $_perBookBump($olAbbr, $olName, 'created');
                } elseif ($olAction === 'skipped') {
                    $songsSkippedExisting++;
                    $_perBookBump($olAbbr, $olName, 'skipped');
                    if (isset($olSong['id']) && $olSong['id'] !== '') {
                        $skippedSongIds[] = (string)$olSong['id'];
                    }
                } else {
                    $errors[] = ['entry' => $name, 'error' => 'save failed: ' . $olErr];
                    $songsFailed++;
                    $_perBookBump($olAbbr, $olName, 'failed', 'save failed: ' . $olErr, $name, $olNumber ?: null, 'save');
                }
                continue;
            }
        }

        /* ProPresenter 6 (#1057): each .pro6 is one song and carries no
           songbook. Handle inline (no folder convention): the song is filed
           under the immediate parent folder's name when present (so a
           curator can group .pro6 files into per-songbook folders), else a
           default "ProPresenter Import" (PP6) songbook. Slide text is base64
           RTF, decoded by the parser. */
        if ($kind === 'pro6') {
            $p6body = $zip->getFromIndex($i);
            if ($p6body === false) {
                $errors[] = ['entry' => $name, 'error' => 'could not read entry'];
                $songsFailed++;
                continue;
            }
            [$p6Parsed, $p6Reason] = _bulkImport_parsePro6($p6body);
            if ($p6Parsed === null) {
                $errors[] = ['entry' => $name, 'error' => 'ProPresenter 6 parse failed: ' . $p6Reason];
                $songsFailed++;
                continue;
            }
            $p6Segments = explode('/', $name);
            $p6Folder   = count($p6Segments) >= 2 ? $p6Segments[count($p6Segments) - 2] : '';
            $p6Name     = $p6Folder !== '' ? $p6Folder : 'ProPresenter Import';
            $p6Abbr     = $p6Folder !== '' ? _bulkImport_videopsalmAbbrevFromHint($p6Folder, $p6Name) : 'PP6';
            if ($p6Abbr === 'VP' || $p6Abbr === '') { $p6Abbr = 'PP6'; }
            if (!isset($songbookSeen[$p6Abbr])) {
                $state = _bulkImport_upsertSongbook($db, $p6Abbr, $p6Name, null);
                $songbookSeen[$p6Abbr] = $state;
                if ($state === 'created') { $songbooksCreated[] = $p6Abbr; }
                else                       { $songbooksExisting[] = $p6Abbr; }
            }
            if (!isset($songbookCounters[$p6Abbr])) {
                $songbookCounters[$p6Abbr] = _bulkImport_nextSongNumberFor($db, $p6Abbr);
            }
            $p6Number = $songbookCounters[$p6Abbr];
            $songbookCounters[$p6Abbr] = $p6Number + 1;
            $p6Song = _bulkImport_assembleSong($p6Parsed, $p6Abbr, $p6Name, $p6Number);
            [$p6Action, $p6Err] = _bulkImport_saveSong($db, $p6Song);
            if ($p6Action === 'create') {
                $songsCreated++;
                $songsParsedByKind['propresenter6']++;
                $_perBookBump($p6Abbr, $p6Name, 'created');
            } elseif ($p6Action === 'skipped') {
                $songsSkippedExisting++;
                $_perBookBump($p6Abbr, $p6Name, 'skipped');
                if (isset($p6Song['id']) && $p6Song['id'] !== '') {
                    $skippedSongIds[] = (string)$p6Song['id'];
                }
            } else {
                $errors[] = ['entry' => $name, 'error' => 'save failed: ' . $p6Err];
                $songsFailed++;
                $_perBookBump($p6Abbr, $p6Name, 'failed', 'save failed: ' . $p6Err, $name, $p6Number ?: null, 'save');
            }
            continue;
        }

        /* FreeShow (#884): each .show is one song and carries no songbook.
           Handle inline (no folder convention): file under the parent folder
           name when present (so curators can group .show files into
           per-songbook folders), else a default "FreeShow Import" (FS). */
        if ($kind === 'freeshow') {
            $fsBody = $zip->getFromIndex($i);
            if ($fsBody === false) {
                $errors[] = ['entry' => $name, 'error' => 'could not read entry'];
                $songsFailed++;
                continue;
            }
            [$fsParsed, $fsReason] = _bulkImport_parseFreeShow($fsBody);
            if ($fsParsed === null) {
                $errors[] = ['entry' => $name, 'error' => 'FreeShow parse failed: ' . $fsReason];
                $songsFailed++;
                continue;
            }
            $fsSegments = explode('/', $name);
            $fsFolder   = count($fsSegments) >= 2 ? $fsSegments[count($fsSegments) - 2] : '';
            $fsName     = $fsFolder !== '' ? $fsFolder : 'FreeShow Import';
            $fsAbbr     = $fsFolder !== '' ? _bulkImport_videopsalmAbbrevFromHint($fsFolder, $fsName) : 'FS';
            if ($fsAbbr === 'VP' || $fsAbbr === '') { $fsAbbr = 'FS'; }
            if (!isset($songbookSeen[$fsAbbr])) {
                $state = _bulkImport_upsertSongbook($db, $fsAbbr, $fsName, null);
                $songbookSeen[$fsAbbr] = $state;
                if ($state === 'created') { $songbooksCreated[] = $fsAbbr; }
                else                       { $songbooksExisting[] = $fsAbbr; }
            }
            if (!isset($songbookCounters[$fsAbbr])) {
                $songbookCounters[$fsAbbr] = _bulkImport_nextSongNumberFor($db, $fsAbbr);
            }
            $fsNumber = $songbookCounters[$fsAbbr];
            $songbookCounters[$fsAbbr] = $fsNumber + 1;
            $fsSong = _bulkImport_assembleSong($fsParsed, $fsAbbr, $fsName, $fsNumber);
            [$fsAction, $fsErr] = _bulkImport_saveSong($db, $fsSong);
            if ($fsAction === 'create') {
                $songsCreated++;
                $songsParsedByKind['freeshow']++;
                $_perBookBump($fsAbbr, $fsName, 'created');
            } elseif ($fsAction === 'skipped') {
                $songsSkippedExisting++;
                $_perBookBump($fsAbbr, $fsName, 'skipped');
                if (isset($fsSong['id']) && $fsSong['id'] !== '') {
                    $skippedSongIds[] = (string)$fsSong['id'];
                }
            } else {
                $errors[] = ['entry' => $name, 'error' => 'save failed: ' . $fsErr];
                $songsFailed++;
                $_perBookBump($fsAbbr, $fsName, 'failed', 'save failed: ' . $fsErr, $name, $fsNumber ?: null, 'save');
            }
            continue;
        }

        /* Pull out the folder + file segments. We expect exactly one
           level of nesting: "<Hymnal Name> [<ABBR>]/<filename>.txt". */
        $segments = explode('/', $name);
        if (count($segments) < 2) {
            $errors[] = ['entry' => $name, 'error' => 'file is not inside a hymnal folder'];
            continue;
        }
        $folder = $segments[count($segments) - 2];
        $file   = $segments[count($segments) - 1];

        if (!preg_match(_BULK_IMPORT_FOLDER_RE, $folder, $folderMatch)) {
            $errors[] = ['entry' => $name, 'error' => 'folder name does not match "<Title> [<ABBR>]"'];
            $_perBookBump('_unknown', $folder, 'failed', 'folder name does not match "<Title> [<ABBR>]"', $name, null, 'folder_regex');
            continue;
        }

        $abbr     = strtoupper($folderMatch['abbr']);
        $bookName = trim($folderMatch['name']);
        /* Optional `_<LangName>-<ISO>` suffix on the folder (#780). The
           ISO code becomes the songbook's Language at upsert time. */
        $folderLang = isset($folderMatch['lang']) ? trim((string)$folderMatch['lang']) : '';

        /* Filename → song-number extraction. The two formats use
           different conventions: */
        $songNum = 0;
        if ($kind === 'txt') {
            /* Strict: "<#> (<ABBR>) - <Title>.txt". The number is
               authoritative, the embedded abbreviation is cross-
               checked against the folder. */
            if (!preg_match(_BULK_IMPORT_FILE_RE, $file, $fileMatch)) {
                $errors[] = ['entry' => $name, 'error' => 'filename does not match "<#> (<ABBR>) - <Title>.txt"'];
                $_perBookBump($abbr, $bookName, 'failed', 'filename does not match "<#> (<ABBR>) - <Title>.txt"', $name, null, 'file_regex');
                continue;
            }
            $fileAbbr = strtoupper($fileMatch['abbr']);
            $songNum  = (int)$fileMatch['num'];
            if ($fileAbbr !== $abbr) {
                $errors[] = [
                    'entry' => $name,
                    'error' => "filename abbrev '$fileAbbr' does not match folder abbrev '$abbr'",
                ];
                $_perBookBump($abbr, $bookName, 'failed', "filename abbrev '$fileAbbr' does not match folder abbrev '$abbr'", $name, $songNum ?: null, 'file_regex');
                continue;
            }
        } else {
            /* OpenSong (#882): leading digits in the filename, if
               present, are a hint only. The XML's <hymn_number>
               element wins; the parser falls back to this hint, then
               to a per-songbook auto-increment. */
            if (preg_match('/^(\d{1,5})/', $file, $m)) {
                $songNum = (int)$m[1];
            }
        }

        /* Songbook upsert — once per abbreviation per import. */
        if (!isset($songbookSeen[$abbr])) {
            $state = _bulkImport_upsertSongbook($db, $abbr, $bookName, $folderLang ?: null);
            $songbookSeen[$abbr] = $state;
            if ($state === 'created') {
                $songbooksCreated[] = $abbr;
            } else {
                $songbooksExisting[] = $abbr;
            }
        }

        /* Read the file body. ZipArchive::getFromIndex returns false
           on read errors (corrupted entry, bad CRC). */
        $body = $zip->getFromIndex($i);
        if ($body === false) {
            $errors[] = ['entry' => $name, 'error' => 'could not read entry'];
            $songsFailed++;
            continue;
        }

        if ($kind === 'opensong') {
            /* OpenSong: derive the next per-songbook auto-increment
               so a parser fallback (no <hymn_number>, no leading
               digits in filename) still produces a unique SongId. */
            if (!isset($songbookCounters[$abbr])) {
                $songbookCounters[$abbr] = _bulkImport_nextSongNumberFor($db, $abbr);
            }
            [$song, $reason] = _bulkImport_parseOpenSong($body, $abbr, $bookName, $songNum, $songbookCounters[$abbr]);
            if ($song !== null) {
                /* Bump the auto-counter to whatever number landed so a
                   second OpenSong file in the same songbook doesn't
                   collide on SongId. */
                $songbookCounters[$abbr] = max($songbookCounters[$abbr], (int)$song['number']) + 1;
                $songsParsedByKind['opensong']++;
            }
        } else {
            [$song, $reason] = _bulkImport_parseTxt($body, $abbr, $bookName, $songNum);
            if ($song !== null) {
                $songsParsedByKind['txt']++;
            }
        }
        if ($song === null) {
            $errors[] = ['entry' => $name, 'error' => 'parse failed: ' . $reason];
            $songsFailed++;
            $_perBookBump($abbr, $bookName, 'failed', 'parse failed: ' . $reason, $name, $songNum ?: null, 'parse');
            continue;
        }

        [$action, $err] = _bulkImport_saveSong($db, $song);
        if ($action === 'create') {
            $songsCreated++;
            $_perBookBump($abbr, $bookName, 'created');
        } elseif ($action === 'skipped') {
            /* Existing row — left untouched per the no-overwrite
               contract. Counted separately from failures so a curator
               can see at a glance how many imports were no-ops because
               the songs were already in the database. Record the SongId
               so the completion notification's "Download skipped SongIds"
               button can stream them as a CSV (audit which exact rows
               the import refused to overwrite). */
            $songsSkippedExisting++;
            $_perBookBump($abbr, $bookName, 'skipped');
            if (isset($song['id']) && $song['id'] !== '') {
                $skippedSongIds[] = (string)$song['id'];
            }
        } else {
            $errors[] = ['entry' => $name, 'error' => 'save failed: ' . $err];
            $songsFailed++;
            $_perBookBump($abbr, $bookName, 'failed', 'save failed: ' . $err, $name, $songNum ?: null, 'save');
        }
    }

    $zip->close();

    /* #907 — phase transition: parsing-songs → flushing-songbooks.
       The per-entry loop is done; we're now refreshing SongCount on
       any newly-created songbooks. Brief phase but worth a label so
       the bar at 100% briefly shows "Finalising…" rather than
       lingering at "Parsing songs" with no progress. */
    if ($jobDb !== null && $jobId !== null) {
        _bulkImport_jobMark($jobDb, $jobId, 'running', [
            'PhaseLabel' => 'flushing-songbooks',
        ]);
    }

    /* Refresh SongCount only for songbooks we created in this run.
       Existing songbooks are off-limits per the no-overwrite contract,
       so we leave their SongCount alone — even though zero new songs
       landed inside them, the column was already correct from
       whoever populated those rows previously. */
    foreach ($songbooksCreated as $abbr) {
        $cnt = $db->prepare(
            /* #1694 D1 — SongCount counts VISIBLE songs (agrees with the
               predicate-aware triggers; trigger-denied hosts rely on this). */
            'UPDATE tblSongbooks
                SET SongCount = (SELECT COUNT(*) FROM tblSongs WHERE SongbookAbbr = ? AND ' . songVisibleSql($db, '') . ')
              WHERE Abbreviation = ?'
        );
        $cnt->bind_param('ss', $abbr, $abbr);
        $cnt->execute();
        $cnt->close();
    }

    /* #908 — overflow row when more failures occurred than the
       per-failure activity-log cap allowed. The full failure list
       still lives in tblBulkImportJobs.ErrorsJson; this row points
       admins at the job for the omitted entries. */
    $omittedFailures = max(0, $songsFailed - $loggedFailures);
    if ($omittedFailures > 0 && $logActivityAvailable && $jobId !== null) {
        try {
            logActivity(
                'import.bulk_entries_truncated',
                'bulk_import_job',
                (string)$jobId,
                [
                    'logged_failures' => $loggedFailures,
                    'total_failures'  => $songsFailed,
                    'omitted'         => $omittedFailures,
                    'reason'          => 'per-failure activity-log cap reached; full list in tblBulkImportJobs.ErrorsJson',
                ],
                'failure'
            );
        } catch (\Throwable $_e) { /* best-effort */ }
    }

    return [
        'ok'                     => true,
        'songbooks_created'      => $songbooksCreated,
        'songbooks_existing'     => $songbooksExisting,
        'songs_created'          => $songsCreated,
        'songs_skipped_existing' => $songsSkippedExisting,
        'songs_failed'           => $songsFailed,
        'parsed_by_format'       => $songsParsedByKind,
        'errors'                 => $errors,
        /* #906 — per-songbook breakdown so the import summary can
           render "HA: 0 created, 527 skipped, 0 failed" rows instead
           of just an aggregate. Re-keyed to a list (drop the abbr
           keys) so the JSON shape is a stable array, not an object
           whose keys depend on the input. */
        'per_songbook'           => array_values($perSongbook),
        /* Skipped SongIds — every SongId the worker left untouched
           because the row already existed in tblSongs. Persisted to
           tblBulkImportJobs.SkippedSongIdsJson and surfaced by the
           completion notification's "Download skipped SongIds" CSV. */
        'skipped_song_ids'       => $skippedSongIds,
        /* #908 — counts of activity-log per-failure rows actually
           written + how many were truncated. Lets the caller link
           "Activity log filter `EntityId LIKE '<jobId>:%'`" to the
           expected row count. */
        'logged_failures'        => $loggedFailures,
        'omitted_failure_rows'   => $omittedFailures,
    ];
}

/* ===========================================================================
 * OPENSONG PARSER (#882)
 *
 * OpenSong stores each song as a single XML document. Reference:
 *   https://opensong.org/documentation/importing/#songs
 *
 * Lyrics live in <lyrics> as plain text with bracketed section markers
 * ([V1], [C], [B], …), chord rows (lines starting with ".") and
 * comment rows (lines starting with ";"). The chord rows are dropped —
 * iHymns is a lyrics catalogue. Lyric lines conventionally begin with
 * a single space; that space is stripped to recover the real text.
 *
 * The output shape mirrors _bulkImport_parseTxt() so the same
 * _bulkImport_saveSong() write path consumes it without any branching.
 * =========================================================================== */

/**
 * Map OpenSong section letters to iHymns component types.
 *
 * Falls back to 'refrain' for anything we don't recognise (e.g. a
 * non-English label slipped into the section marker), matching the
 * fallback _bulkImport_componentTypeFor() uses for the TXT parser.
 */
function _bulkImport_openSongComponentTypeFor(string $letter): string
{
    return [
        'V' => 'verse',
        'C' => 'chorus',
        'B' => 'bridge',
        'P' => 'pre-chorus',
        'T' => 'outro',
        'E' => 'outro',
        'I' => 'intro',
    ][strtoupper($letter)] ?? 'refrain';
}

/**
 * Parse one OpenSong XML body into the song-object shape that
 * _bulkImport_saveSong() consumes.
 *
 * @param string $body         Raw XML (UTF-8; BOM tolerated).
 * @param string $abbrev       Songbook abbreviation from the folder.
 * @param string $songbook     Songbook display name from the folder.
 * @param int    $numberHint   Number derived from the filename's
 *                             leading digits (0 if none).
 * @param int    $autoNumber   Per-songbook auto-increment fallback when
 *                             neither the XML nor the filename supply
 *                             a number.
 * @return array{0: ?array, 1: ?string}  [songObject, errorReason]
 */
function _bulkImport_parseOpenSong(string $body, string $abbrev, string $songbook, int $numberHint, int $autoNumber): array
{
    /* Strip a UTF-8 BOM so SimpleXML doesn't choke. */
    $body = preg_replace('/^\xEF\xBB\xBF/', '', $body);

    /* Capture libxml errors locally so a malformed file produces a
       readable per-entry error rather than a global warning splatter. */
    $prevInternal = libxml_use_internal_errors(true);
    libxml_clear_errors();
    /* LIBXML_NONET prevents the parser from following any external
       entity URI — DTD-based SSRF / billion-laughs hardening. */
    $xml = simplexml_load_string($body, \SimpleXMLElement::class, LIBXML_NONET);
    if ($xml === false) {
        $err = libxml_get_last_error();
        libxml_clear_errors();
        libxml_use_internal_errors($prevInternal);
        return [null, 'invalid XML' . ($err ? ': ' . trim($err->message) : '')];
    }
    libxml_clear_errors();
    libxml_use_internal_errors($prevInternal);

    if (strtolower($xml->getName()) !== 'song') {
        return [null, 'XML root is <' . $xml->getName() . '>, expected <song>'];
    }

    $title = trim((string)($xml->title ?? ''));
    if ($title === '') {
        return [null, 'no <title> element'];
    }

    /* Number resolution priority: <hymn_number> in XML → filename
       leading digits → per-songbook auto-increment. The auto-increment
       guarantees a unique SongId even for OpenSong corpora that don't
       carry numbers at all (which is most non-hymnal song libraries). */
    $hymnNumberRaw = trim((string)($xml->hymn_number ?? ''));
    $hymnNumber    = ($hymnNumberRaw !== '' && ctype_digit($hymnNumberRaw))
        ? (int)$hymnNumberRaw
        : 0;
    $number = $hymnNumber > 0
        ? $hymnNumber
        : ($numberHint > 0 ? $numberHint : $autoNumber);
    if ($number <= 0) {
        return [null, 'could not determine song number'];
    }

    $components = _bulkImport_parseOpenSongLyrics((string)($xml->lyrics ?? ''));
    if (empty($components)) {
        return [null, 'no lyric components found in <lyrics>'];
    }

    /* Author fields in OpenSong commonly stack multiple credits with
       slashes, ampersands or commas — split into individual writers
       to match the way the editor stores credit names. */
    $authors  = trim((string)($xml->author ?? ''));
    $writers  = [];
    if ($authors !== '') {
        foreach (preg_split('/\s*[\/&,;]\s*/u', $authors) as $w) {
            $w = trim((string)$w);
            if ($w !== '') $writers[] = $w;
        }
    }

    $songId = sprintf('%s-%04d', strtoupper($abbrev), $number);

    return [[
        'id'                 => $songId,
        'title'              => $title,
        'number'             => $number,
        'songbook'           => strtoupper($abbrev),
        'songbookName'       => $songbook,
        'language'           => 'en',
        'ccli'               => trim((string)($xml->ccli ?? '')),
        'iswc'               => '',
        'tuneName'           => '',
        'copyright'          => trim((string)($xml->copyright ?? '')),
        'verified'           => 0,
        'lyricsPublicDomain' => 0,
        'musicPublicDomain'  => 0,
        'hasAudio'           => 0,
        'hasSheetMusic'      => 0,
        'writers'            => $writers,
        'composers'          => [],
        'arrangers'          => [],
        'adaptors'           => [],
        'translators'        => [],
        'components'         => $components,
    ], null];
}

/**
 * Parse the body of an OpenSong <lyrics> block into the same
 * components[] shape produced by _bulkImport_parseTxt().
 */
function _bulkImport_parseOpenSongLyrics(string $lyrics): array
{
    $lyrics = str_replace(["\r\n", "\r"], "\n", $lyrics);
    $lines  = explode("\n", $lyrics);
    $components = [];
    $current    = null;

    foreach ($lines as $rawLine) {
        $trim = trim($rawLine);

        /* Comment row → drop. OpenSong reserves leading ";" for
           in-corpus notes the projector should never display. */
        if ($trim !== '' && $trim[0] === ';') {
            continue;
        }
        /* Chord row → drop. OpenSong puts chord names on a row that
           starts with "." so the projector can suppress them; iHymns
           is lyrics-only, so we strip them entirely rather than try
           to interleave them with the lyric line. */
        if ($rawLine !== '' && $rawLine[0] === '.') {
            continue;
        }

        /* Section marker, e.g. "[V1]", "[C]", "[B]". The optional
           trailing digits become the verse number; bare "[V]" implies
           the next sequential verse. */
        if (preg_match('/^\[([A-Za-z]+)(\d*)\]$/', $trim, $m)) {
            if ($current !== null && !empty($current['lines'])) {
                $components[] = $current;
            }
            $current = [
                'type'   => _bulkImport_openSongComponentTypeFor($m[1]),
                'number' => $m[2] !== '' ? (int)$m[2] : 0,
                'lines'  => [],
            ];
            continue;
        }

        /* Blank line ends the current section so consecutive section
           markers don't accidentally merge. */
        if ($trim === '') {
            if ($current !== null && !empty($current['lines'])) {
                $components[] = $current;
                $current = null;
            }
            continue;
        }

        if ($current === null) {
            /* Lyrics before any section marker — assume verse 1 so
               files without explicit markers (rare but legal) still
               produce a usable song object. */
            $current = ['type' => 'verse', 'number' => 1, 'lines' => []];
        }

        /* Strip a single leading space — OpenSong convention for lyric
           rows; preserving it would ladder the text in the editor. */
        $line = $rawLine;
        if (isset($line[0]) && $line[0] === ' ') {
            $line = substr($line, 1);
        }
        $current['lines'][] = rtrim($line);
    }

    if ($current !== null && !empty($current['lines'])) {
        $components[] = $current;
    }

    return $components;
}

/**
 * Return one greater than the maximum existing song number for a
 * songbook, used as the OpenSong auto-increment seed when neither
 * <hymn_number> nor the filename supply a number (#882).
 */
function _bulkImport_nextSongNumberFor(\mysqli $db, string $abbr): int
{
    try {
        $stmt = $db->prepare(
            /* @deleted-visible: number MINT SEED (#1694) — a hidden song
               keeps its number slot reserved; filtering would re-issue it and
               collide on restore (non-unique index — nothing would stop it). */
            'SELECT COALESCE(MAX(Number), 0) + 1 FROM tblSongs WHERE SongbookAbbr = ?'
        );
        $stmt->bind_param('s', $abbr);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_row();
        $stmt->close();
        return (int)($row[0] ?? 1);
    } catch (\Throwable $e) {
        error_log('[_bulkImport_nextSongNumberFor] ' . $e->getMessage());
        return 1;
    }
}

/* ===========================================================================
 * VIDEOPSALM PARSER (#883)
 *
 * VideoPsalm distributes whole songbooks as a single .json document with
 * top-level metadata and a `Songs[]` array — one drop = one whole hymnal.
 *
 *   https://myvideopsalm.weebly.com/songbooks-and-bibles.html
 *   https://myvideopsalm.weebly.com/free-songbook-collection.html
 *
 * Section tags inside `Verses[].Tag` use the same letter convention as
 * OpenSong (V1, V2, C, B, P, T, E, I) so the type map is shared in
 * spirit with the OpenSong parser.
 *
 * The parser is deliberately decoupled from the database — it returns a
 * songbook descriptor + parsed song-objects in the same shape that
 * _bulkImport_saveSong() consumes. The dispatcher (single-file handler
 * and the .json branch inside _bulkImport_processZip) is what actually
 * upserts rows.
 * =========================================================================== */

/**
 * Map a VideoPsalm verse Tag (e.g. "V1", "C", "B2") to an iHymns
 * component type. Trailing digits are stripped before the lookup; an
 * unknown letter falls through to 'refrain' to mirror the OpenSong
 * fallback behaviour.
 */
function _bulkImport_videopsalmComponentTypeFor(string $tag): string
{
    $letter = strtoupper((string)preg_replace('/\d+$/', '', trim($tag)));
    return [
        'V' => 'verse',
        'C' => 'chorus',
        'B' => 'bridge',
        'P' => 'pre-chorus',
        'T' => 'outro',
        'E' => 'outro',
        'I' => 'intro',
    ][$letter] ?? 'refrain';
}

/**
 * Derive a songbook abbreviation given a (possibly null) hint and the
 * resolved songbook display name.
 *
 * Hint precedence:
 *   1. "<Title> [<ABBR>].json" — the bracketed token wins.
 *   2. A bare alphanum filename (without extension) is taken verbatim.
 *   3. Otherwise: initials of the songbook name, uppercased; falls back
 *      to "VP" if the name yields no usable initials.
 */
function _bulkImport_videopsalmAbbrevFromHint(?string $abbrevHint, string $songbookName): string
{
    if ($abbrevHint !== null) {
        $hint = trim($abbrevHint);
        /* Strip any leading folder segments — we only care about the
           leaf filename when looking for the bracket pattern. */
        $hint = (string)preg_replace('#^.*/#', '', $hint);
        if (preg_match('/\[([A-Za-z0-9_\-]+)\]/', $hint, $m)) {
            return strtoupper($m[1]);
        }
        $hint = (string)preg_replace('/\.json$/i', '', $hint);
        $hint = trim($hint);
        if ($hint !== '' && preg_match('/^[A-Za-z0-9_\-]+$/', $hint)) {
            return strtoupper($hint);
        }
    }
    $words = preg_split('/\s+/u', trim($songbookName)) ?: [];
    $initials = '';
    foreach ($words as $w) {
        if ($w === '') continue;
        $initials .= mb_substr($w, 0, 1, 'UTF-8');
    }
    $initials = strtoupper((string)preg_replace('/[^A-Za-z0-9]/', '', $initials));
    return $initials !== '' ? $initials : 'VP';
}

/**
 * Parse a VideoPsalm songbook JSON document into:
 *   [songbookMeta, songs[], errReason]
 *
 * - songbookMeta is null only on a fatal parse error (invalid JSON or
 *   missing top-level Songs[]). On success it carries:
 *     - 'abbrev'      : derived from the filename hint or the title.
 *     - 'name'        : top-level "Text" field, or a generic fallback.
 *     - 'language'    : null (VideoPsalm does not encode IETF tags).
 *     - 'parseErrors' : per-song reasons for any songs that were
 *                       skipped (no title / no usable Verses / etc.) —
 *                       caller surfaces these via the import summary's
 *                       errors[] list.
 * - songs[] uses the exact shape that _bulkImport_saveSong() consumes,
 *   matching _bulkImport_parseTxt() / _bulkImport_parseOpenSong().
 *
 * @param string      $body         Raw JSON (UTF-8; BOM tolerated).
 * @param string|null $abbrevHint   Filename or "<Title> [<ABBR>]" hint.
 * @return array{0: ?array, 1: ?array, 2: ?string}
 */
function _bulkImport_parseVideoPsalmSongbook(string $body, ?string $abbrevHint = null): array
{
    /* Strip a UTF-8 BOM so json_decode doesn't trip. */
    $body = (string)preg_replace('/^\xEF\xBB\xBF/', '', $body);
    $data = json_decode($body, true);
    if ($data === null) {
        return [null, null, 'invalid JSON: ' . json_last_error_msg()];
    }
    if (!is_array($data) || !isset($data['Songs']) || !is_array($data['Songs'])) {
        return [null, null, 'JSON has no top-level "Songs" array'];
    }

    $bookName = trim((string)($data['Text'] ?? ''));
    if ($bookName === '') {
        $bookName = 'VideoPsalm Songbook';
    }
    $abbr = _bulkImport_videopsalmAbbrevFromHint($abbrevHint, $bookName);

    $songs         = [];
    $perSongErrors = [];

    foreach ($data['Songs'] as $idx => $sRaw) {
        $entryTag = 'song #' . ((int)$idx + 1);
        if (!is_array($sRaw)) {
            $perSongErrors[] = ['entry' => $entryTag, 'error' => 'song entry is not an object'];
            continue;
        }

        $title = trim((string)($sRaw['Text'] ?? ''));
        if ($title === '') {
            $perSongErrors[] = ['entry' => $entryTag, 'error' => 'song has no Text/title'];
            continue;
        }
        $entryTag = 'song #' . ((int)$idx + 1) . ' "' . $title . '"';

        /* Number resolution: VideoPsalm "Number" is authoritative when
           present and positive; otherwise fall back to the array index
           (1-based) so each song still gets a unique SongId. */
        $rawNumber = $sRaw['Number'] ?? null;
        if (is_int($rawNumber) || (is_string($rawNumber) && ctype_digit(trim($rawNumber)))) {
            $number = (int)$rawNumber;
        } else {
            $number = 0;
        }
        if ($number <= 0) {
            $number = (int)$idx + 1;
        }

        /* Verses → components. Empty Verses arrays / verses with empty
           Text are skipped silently; if no usable verses survive, the
           whole song is dropped with a perSongErrors note. */
        $components = [];
        $verses     = is_array($sRaw['Verses'] ?? null) ? $sRaw['Verses'] : [];
        foreach ($verses as $v) {
            if (!is_array($v)) continue;
            $tag     = (string)($v['Tag'] ?? '');
            $vNum    = 0;
            if (preg_match('/(\d+)$/', $tag, $vm)) {
                $vNum = (int)$vm[1];
            }
            $type    = $tag !== '' ? _bulkImport_videopsalmComponentTypeFor($tag) : 'verse';
            $rawText = (string)($v['Text'] ?? '');
            if (trim($rawText) === '') continue;
            $rawText = str_replace(["\r\n", "\r"], "\n", $rawText);
            $lines   = array_map('rtrim', explode("\n", $rawText));
            /* Trim trailing empties so a verse that ends with a stray
               newline doesn't render as a blank-line tail in the editor. */
            while (!empty($lines) && end($lines) === '') {
                array_pop($lines);
            }
            if (empty($lines)) continue;
            $components[] = [
                'type'   => $type,
                'number' => $vNum,
                'lines'  => $lines,
            ];
        }
        if (empty($components)) {
            $perSongErrors[] = ['entry' => $entryTag, 'error' => 'no usable Verses[] entries'];
            continue;
        }

        /* Author splits: same delimiter set as the OpenSong parser
           (slash, ampersand, comma, semicolon) so a credit string like
           "Mary E. Byrne / Eleanor H. Hull" yields two writers. */
        $authorRaw = trim((string)($sRaw['Author'] ?? ''));
        $writers   = [];
        if ($authorRaw !== '') {
            foreach ((array)preg_split('/\s*[\/&,;]\s*/u', $authorRaw) as $w) {
                $w = trim((string)$w);
                if ($w !== '') $writers[] = $w;
            }
        }

        $songs[] = [
            'id'                 => sprintf('%s-%04d', $abbr, $number),
            'title'              => $title,
            'number'             => $number,
            'songbook'           => $abbr,
            'songbookName'       => $bookName,
            'language'           => 'en',
            'ccli'               => trim((string)($sRaw['CCLI'] ?? '')),
            'iswc'               => '',
            'tuneName'           => '',
            'copyright'          => trim((string)($sRaw['Copyright'] ?? '')),
            'verified'           => 0,
            'lyricsPublicDomain' => 0,
            'musicPublicDomain'  => 0,
            'hasAudio'           => 0,
            'hasSheetMusic'      => 0,
            'writers'            => $writers,
            'composers'          => [],
            'arrangers'          => [],
            'adaptors'           => [],
            'translators'        => [],
            'components'         => $components,
        ];
    }

    $songbook = [
        'abbrev'      => $abbr,
        'name'        => $bookName,
        'language'    => null,
        'parseErrors' => $perSongErrors,
    ];
    return [$songbook, $songs, null];
}

/**
 * Synchronous single-file VideoPsalm import — invoked from the
 * bulk_import_videopsalm dispatcher case. Returns a summary in the
 * same shape that _bulkImport_processZip() emits so the editor's
 * progress / toast handlers can stay format-agnostic.
 *
 * @param string      $body          Raw JSON document.
 * @param string|null $filenameHint  Original upload filename (used to
 *                                   derive the songbook abbreviation).
 * @return array
 */
function _bulkImport_processVideoPsalm(string $body, ?string $filenameHint = null): array
{
    [$songbook, $parsedSongs, $err] = _bulkImport_parseVideoPsalmSongbook($body, $filenameHint);
    if ($songbook === null) {
        return [
            'ok'                     => false,
            'error'                  => $err ?: 'VideoPsalm parse failed',
            'songbooks_created'      => [],
            'songbooks_existing'     => [],
            'songs_created'          => 0,
            'songs_skipped_existing' => 0,
            'songs_failed'           => 0,
            'parsed_by_format'       => ['videopsalm' => 0],
            'errors'                 => [],
        ];
    }

    $db = getDbMysqli();
    $abbr     = (string)$songbook['abbrev'];
    $bookName = (string)$songbook['name'];

    $errors = [];
    foreach ((array)($songbook['parseErrors'] ?? []) as $pe) {
        $errors[] = [
            'entry' => ($filenameHint ?? 'videopsalm') . ': ' . ($pe['entry'] ?? '?'),
            'error' => (string)($pe['error'] ?? ''),
        ];
    }

    $state = _bulkImport_upsertSongbook($db, $abbr, $bookName, $songbook['language'] ?? null);
    $songbooksCreated  = ($state === 'created')  ? [$abbr] : [];
    $songbooksExisting = ($state === 'existing') ? [$abbr] : [];

    $songsCreated         = 0;
    $songsSkippedExisting = 0;
    $songsFailed          = 0;
    foreach ((array)$parsedSongs as $song) {
        [$action, $saveErr] = _bulkImport_saveSong($db, $song);
        if ($action === 'create') {
            $songsCreated++;
        } elseif ($action === 'skipped') {
            $songsSkippedExisting++;
        } else {
            $errors[] = [
                'entry' => ($filenameHint ?? 'videopsalm') . ': ' . ($song['id'] ?? '?'),
                'error' => 'save failed: ' . $saveErr,
            ];
            $songsFailed++;
        }
    }

    /* Refresh SongCount only if we created the songbook in this run —
       same no-overwrite contract as the ZIP path (#664). */
    if (!empty($songbooksCreated)) {
        $cnt = $db->prepare(
            /* #1694 D1 — SongCount counts VISIBLE songs (agrees with the
               predicate-aware triggers; trigger-denied hosts rely on this). */
            'UPDATE tblSongbooks
                SET SongCount = (SELECT COUNT(*) FROM tblSongs WHERE SongbookAbbr = ? AND ' . songVisibleSql($db, '') . ')
              WHERE Abbreviation = ?'
        );
        $cnt->bind_param('ss', $abbr, $abbr);
        $cnt->execute();
        $cnt->close();
    }

    return [
        'ok'                     => true,
        'songbooks_created'      => $songbooksCreated,
        'songbooks_existing'     => $songbooksExisting,
        'songs_created'          => $songsCreated,
        'songs_skipped_existing' => $songsSkippedExisting,
        'songs_failed'           => $songsFailed,
        'parsed_by_format'       => ['videopsalm' => $songsCreated + $songsSkippedExisting],
        'errors'                 => $errors,
    ];
}

/* ===========================================================================
 *  ChordPro import (#1264)  —  .cho / .pro / .chopro / .crd / .chord
 *
 * ChordPro is the lingua franca chart format (OnSong, OpenSong, SongBeamer,
 * Planning Center / WorshipTools all import it), so one adapter is the
 * realistic migration on-ramp OUT of WorshipTools' import-only lock-in (#1264).
 * It is the inbound twin of the lyrics-only ChordPro EXPORT shipped in PR #1277.
 *
 * SCOPE — lyrics + section structure + header metadata (symmetric with the
 * lyrics-only export). Inline `[chord]` markers are PARSED OUT to recover clean
 * lyric lines; per-line CHORD STORAGE is deliberately deferred to when the
 * per-line-chord read/render path lands (the same #299/#1094 keystone that
 * blocks the export's inline-chord half) — see _bulkImport_chordProStripChords()
 * for the one place that future work hooks in. Lyrics + structure flow through
 * the SAME _bulkImport_saveSong() → lyricLinesWriteComponents() write path as
 * every other importer (CLAUDE.md rule #25), so there is no new write surface.
 * =========================================================================== */

/**
 * Strip inline ChordPro `[chord]` markers from one lyric line, returning the
 * clean lyric text. Retained as the lyric-only helper; the chord-RETAINING path
 * (#1126) is _bulkImport_chordProSplitLine() below.
 */
function _bulkImport_chordProStripChords(string $line): string
{
    /* Remove [ ... ] chord tokens; ChordPro chords never contain ']' so the
       negated class is safe. A mid-word chord rejoins cleanly ("won[D]derful"
       → "wonderful"); the lyric is otherwise preserved verbatim (a chord-only
       line collapses to whitespace and is dropped by the caller's trim check). */
    return preg_replace('/\[[^\]]*\]/u', '', $line);
}

/**
 * Split one ChordPro lyric line into its clean lyric + the chord symbols it
 * carried, IN ORDER (#1126). Returns ['lyric' => string, 'chords' => string]
 * where `chords` is the space-separated chord symbols for that line ('' when the
 * line had none) — exactly the per-line cell shape the editor's manual chord
 * input (#1094) writes and componentChordsToText() renders, so an imported song
 * round-trips through the editor + the chord-chart toggle (#299) unchanged.
 *
 * The chords flow onto the component as a `chords` array parallel to `lines`,
 * which _bulkImport_saveSong() persists via the SANCTIONED lyricLinesWriteComponents()
 * write path — never a direct ChordsJson write (rule #25). NB: this captures the
 * chords present, not their inline character offset (the app's chord model is a
 * chord line per lyric line, not a positioned overlay); positional fidelity is a
 * separate render concern. A pure chord-only line (no lyric) is still dropped by
 * the caller's empty-lyric check — there is no lyric line to anchor it to.
 */
function _bulkImport_chordProSplitLine(string $line): array
{
    $chords = [];
    /* Capture each [chord] token's symbol in document order. */
    if (preg_match_all('/\[([^\]]*)\]/u', $line, $mm)) {
        foreach ($mm[1] as $sym) {
            $sym = trim($sym);
            if ($sym !== '') { $chords[] = $sym; }
        }
    }
    return [
        'lyric'  => preg_replace('/\[[^\]]*\]/u', '', $line),
        'chords' => implode(' ', $chords),
    ];
}

/**
 * Derive a component {type, number} from a free-text section label
 * ("Verse 2", "Chorus", "Bridge", "Refrão 1", …). Reuses the shared
 * _bulkImport_componentTypeFor() vocabulary (unknown → 'refrain').
 *
 * @return array{type: string, number: int}
 */
function _bulkImport_chordProSectionFromLabel(string $label): array
{
    $l   = trim($label);
    $num = 0;
    if (preg_match('/(\d{1,3})\s*$/', $l, $m)) {
        $num = (int)$m[1];
    }
    /* Strip a trailing number to isolate the type keyword: "Verse 2" → "verse". */
    $key = strtolower(trim(preg_replace('/[\d]+\s*$/', '', $l)));
    return ['type' => _bulkImport_componentTypeFor($key), 'number' => $num];
}

/**
 * Parse one ChordPro document into the song-object shape _bulkImport_saveSong()
 * consumes (identical to _bulkImport_parseTxt()). Returns [songObject, null] or
 * [null, errorReason].
 *
 * @return array{0: ?array, 1: ?string}
 */
function _bulkImport_parseChordPro(string $body, string $abbrev, string $songbook, int $number): array
{
    $body  = str_replace(["\r\n", "\r"], "\n", $body);
    $lines = explode("\n", $body);

    $title      = '';
    $copyright  = '';
    $ccli       = '';
    $writers    = [];
    $composers  = [];
    $components = [];
    $current    = null;            // the section being built
    $autoVerse  = 0;               // auto-numbering for unlabelled verses

    $flush = static function () use (&$current, &$components): void {
        if ($current !== null && !empty($current['lines'])) {
            /* Keep chordless components byte-identical to the pre-#1126 shape:
               only carry a `chords` array when at least one line had chords. */
            $hasChords = false;
            foreach (($current['chords'] ?? []) as $c) { if ($c !== '') { $hasChords = true; break; } }
            if (!$hasChords) { unset($current['chords']); }
            $components[] = $current;
        }
        $current = null;
    };
    $startSection = static function (string $type, int $num) use (&$current, $flush): void {
        $flush();
        $current = ['type' => $type, 'number' => $num, 'lines' => [], 'chords' => []];
    };

    foreach ($lines as $raw) {
        $line = rtrim($raw);
        $trim = trim($line);

        if ($trim === '') {                       // blank line ends a section
            $flush();
            continue;
        }
        if ($trim[0] === '#') {                   // ChordPro comment line
            continue;
        }

        /* Directive line: {name} or {name: value}. A line may be ONLY a
           directive; inline directives mid-lyric are rare and treated as text. */
        if ($trim[0] === '{' && substr($trim, -1) === '}') {
            $inner = trim(substr($trim, 1, -1));
            $name  = $inner;
            $value = '';
            $colon = strpos($inner, ':');
            if ($colon !== false) {
                $name  = trim(substr($inner, 0, $colon));
                $value = trim(substr($inner, $colon + 1));
            }
            $name = strtolower($name);

            switch ($name) {
                case 'title': case 't':
                    if ($title === '') { $title = $value; }
                    break;
                case 'copyright':
                    $copyright = $value; break;
                case 'ccli': case 'ccli_no': case 'ccli_number':
                    $ccli = preg_replace('/\D+/', '', $value); break;
                case 'author': case 'lyricist': case 'words': case 'writer':
                    if ($value !== '') { $writers[] = $value; } break;
                case 'artist': case 'composer': case 'music':
                    if ($value !== '') { $composers[] = $value; } break;
                case 'start_of_verse': case 'sov':
                    $n = ($value !== '' && ctype_digit($value)) ? (int)$value : ++$autoVerse;
                    $startSection('verse', $n); break;
                case 'start_of_chorus': case 'soc':
                    $startSection('chorus', 0); break;
                case 'start_of_bridge': case 'sob':
                    $startSection('bridge', 0); break;
                case 'start_of_part': case 'sop':
                    $sec = _bulkImport_chordProSectionFromLabel($value);
                    $startSection($sec['type'], $sec['number']); break;
                case 'comment': case 'c': case 'ci': case 'comment_italic':
                    /* The export emits section labels as {comment:}; treat a
                       comment as the start of a labelled section. */
                    $sec = _bulkImport_chordProSectionFromLabel($value);
                    if ($sec['type'] === 'verse' && $sec['number'] === 0) { $sec['number'] = ++$autoVerse; }
                    $startSection($sec['type'], $sec['number']); break;
                case 'end_of_verse': case 'eov':
                case 'end_of_chorus': case 'eoc':
                case 'end_of_bridge': case 'eob':
                case 'end_of_part':  case 'eop':
                    $flush(); break;
                default:
                    /* subtitle/key/capo/tempo/time/album/year/define/… — no
                       target field in the song model; ignored (lossless for the
                       fields we DO store). */
                    break;
            }
            continue;
        }

        /* Lyric line. Split into clean lyric + its [chord] symbols (#1126); the
           chords ride a `chords` array parallel to `lines` and persist via the
           sanctioned write path. A chord-only line (empty after stripping) has no
           lyric to anchor to and is skipped. Auto-open a verse if no section tag
           preceded the lyrics. */
        $parsed = _bulkImport_chordProSplitLine($line);
        $lyric  = rtrim($parsed['lyric']);
        if (trim($lyric) === '') { continue; }
        if ($current === null) {
            $startSection('verse', ++$autoVerse);
        }
        $current['lines'][]  = $lyric;
        $current['chords'][] = $parsed['chords'];   /* parallel to lines; '' when the line had no chords */
    }
    $flush();

    if ($title === '') {
        return [null, 'no {title} directive'];
    }
    if (empty($components)) {
        return [null, 'no lyric lines found'];
    }

    $songId = sprintf('%s-%04d', strtoupper($abbrev), $number);

    return [[
        'id'                 => $songId,
        'title'              => $title,
        'number'             => $number,
        'songbook'           => $abbrev,
        'songbookName'       => $songbook,
        'language'           => 'en',
        'ccli'               => $ccli,
        'iswc'               => '',
        'tuneName'           => '',
        'copyright'          => $copyright,
        'verified'           => 0,
        'lyricsPublicDomain' => 0,
        'musicPublicDomain'  => 0,
        'hasAudio'           => 0,
        'hasSheetMusic'      => 0,
        'writers'            => $writers,
        'composers'          => $composers,
        'arrangers'          => [],
        'adaptors'           => [],
        'translators'        => [],
        'components'         => $components,
    ], null];
}

/**
 * Single-file ChordPro processor — wraps the parser + the shared saver, mirroring
 * _bulkImport_processVideoPsalm(). Songbook + number come from the filename hint
 * ("<#> (<ABBR>) - <Title>.cho") when present, else a default "ChordPro Import"
 * book with an auto-assigned next number.
 */
function _bulkImport_processChordPro(string $body, ?string $filenameHint = null): array
{
    $fail = static function (string $msg): array {
        return [
            'ok' => false, 'error' => $msg,
            'songbooks_created' => [], 'songbooks_existing' => [],
            'songs_created' => 0, 'songs_skipped_existing' => 0, 'songs_failed' => 0,
            'parsed_by_format' => ['chordpro' => 0], 'errors' => [],
        ];
    };

    $db   = getDbMysqli();
    $base = $filenameHint !== null ? pathinfo($filenameHint, PATHINFO_FILENAME) : '';

    /* Prefer the "<#> (<ABBR>) - <Title>" filename convention; else a default book. */
    $abbr = 'CHORDPRO';
    $book = 'ChordPro Import';
    $num  = 0;
    if ($base !== '' && preg_match('/^(?P<num>\d{1,5})\s*\((?P<abbr>[A-Za-z0-9_\-]+)\)\s*-\s*/u', $base, $m)) {
        $abbr = strtoupper($m['abbr']);
        $num  = (int)$m['num'];
    }
    if ($num <= 0) { $num = _bulkImport_nextSongNumberFor($db, $abbr); }

    [$song, $reason] = _bulkImport_parseChordPro($body, $abbr, $book, $num);
    if ($song === null) {
        return $fail('ChordPro parse failed: ' . ($reason ?: 'unknown'));
    }

    $state = _bulkImport_upsertSongbook($db, $abbr, $book, 'en');
    $songbooksCreated  = ($state === 'created')  ? [$abbr] : [];
    $songbooksExisting = ($state === 'existing') ? [$abbr] : [];

    $errors = [];
    $created = 0; $skipped = 0; $failed = 0;
    [$action, $saveErr] = _bulkImport_saveSong($db, $song);
    if ($action === 'create') {
        $created++;
    } elseif ($action === 'skipped') {
        $skipped++;
    } else {
        $errors[] = ['entry' => ($filenameHint ?? 'chordpro') . ': ' . ($song['id'] ?? '?'), 'error' => 'save failed: ' . $saveErr];
        $failed++;
    }

    if (!empty($songbooksCreated)) {
        $cnt = $db->prepare(
            /* #1694 D1 — visible-count recompute (see the multi-line siblings). */
            'UPDATE tblSongbooks SET SongCount = (SELECT COUNT(*) FROM tblSongs WHERE SongbookAbbr = ? AND ' . songVisibleSql($db, '') . ') WHERE Abbreviation = ?'
        );
        $cnt->bind_param('ss', $abbr, $abbr);
        $cnt->execute();
        $cnt->close();
    }

    return [
        'ok'                     => true,
        'songbooks_created'      => $songbooksCreated,
        'songbooks_existing'     => $songbooksExisting,
        'songs_created'          => $created,
        'songs_skipped_existing' => $skipped,
        'songs_failed'           => $failed,
        'parsed_by_format'       => ['chordpro' => $created + $skipped],
        'errors'                 => $errors,
    ];
}

/* ===========================================================================
 *  OpenLP / OpenLyrics import (#1052)
 * ---------------------------------------------------------------------------
 * OpenLP exports each song as a standalone OpenLyrics XML file
 * (http://openlyrics.info). Unlike the .SourceSongData / OpenSong ZIP
 * layout, OpenLyrics carries its OWN songbook metadata inside
 * <properties><songbooks><songbook name="…" entry="N"/> — so, like the
 * VideoPsalm path, these files do NOT follow the "<Title> [<ABBR>]/" folder
 * convention. The functions below parse one OpenLyrics document into the
 * shared song shape that _bulkImport_saveSong() consumes (inheriting the
 * #1051 title-dedupe), and a single-file processor mirrors
 * _bulkImport_processVideoPsalm() for the bulk_import_openlp action.
 *
 * A ZIP of OpenLyrics files is handled inline by _bulkImport_processZip()
 * (it content-sniffs .xml entries via _bulkImport_looksLikeOpenLyrics() and
 * routes OpenLyrics ones here instead of the OpenSong parser).
 * =========================================================================== */

/**
 * Cheap content sniff: is this XML body an OpenLyrics document?
 * Avoids a full parse — used to disambiguate OpenLyrics .xml from OpenSong
 * .xml inside the generic ZIP import loop.
 */
function _bulkImport_looksLikeOpenLyrics(string $body): bool
{
    $head = substr($body, 0, 4096);
    if (stripos($head, 'openlyrics.info') !== false) {
        return true;
    }
    /* Namespace may be absent on some exports; fall back to the structural
       fingerprint OpenSong never has: a <verse name="…"> with a <lines>
       child. */
    return (bool)preg_match('/<verse\b[^>]*\bname=/i', $body)
        && stripos($body, '<lines') !== false;
}

/**
 * Map an OpenLyrics verse `name` (v1, c, c2, b, p, e, i, o, …) to an
 * [iHymns component type, number] pair.
 */
function _bulkImport_openLyricsVerseType(string $name): array
{
    $letter = 'v';
    $num    = 0;
    if (preg_match('/^([A-Za-z]+)\s*(\d*)$/', trim($name), $m)) {
        $letter = strtolower($m[1]);
        $num    = $m[2] !== '' ? (int)$m[2] : 0;
    }
    $map = [
        'v' => 'verse', 'c' => 'chorus', 'b' => 'bridge', 'p' => 'pre-chorus',
        'r' => 'refrain', 'e' => 'outro', 'i' => 'intro', 'o' => 'outro',
        't' => 'outro',
    ];
    return [$map[$letter] ?? 'refrain', $num];
}

/**
 * Flatten one OpenLyrics <lines> element into an array of plain-text lines.
 * <br/> separates lines; <comment>/<chord>/<tag> and any other inline markup
 * is stripped (iHymns is lyrics-only).
 */
function _bulkImport_openLyricsLinesToArray(\SimpleXMLElement $linesNode): array
{
    $inner = (string)$linesNode->asXML();
    $inner = (string)preg_replace('#^<lines\b[^>]*>#i', '', $inner);
    $inner = (string)preg_replace('#</lines>\s*$#i', '', $inner);
    /* Self-closing or paired <br> → newline. */
    $inner = (string)preg_replace('#<br\s*/?>#i', "\n", $inner);
    /* Drop comments wholesale (including their text), then any remaining
       inline markup (chords, tags) leaving the bare lyric text. */
    $inner = (string)preg_replace('#<comment\b[^>]*>.*?</comment>#is', '', $inner);
    $inner = (string)preg_replace('#<[^>]+>#', '', $inner);
    $text  = html_entity_decode($inner, ENT_QUOTES | ENT_XML1, 'UTF-8');
    $lines = array_map('rtrim', explode("\n", $text));
    /* Trim leading / trailing blank lines but preserve internal spacing. */
    while (!empty($lines) && trim($lines[0]) === '')          array_shift($lines);
    while (!empty($lines) && trim((string)end($lines)) === '') array_pop($lines);
    return $lines;
}

/**
 * Rich per-line parse of an OpenLyrics <lines> node (#1130) — preserves the
 * enrichment the flat _bulkImport_openLyricsLinesToArray() discards. Returns a
 * list of ['text','chords','note'] (one per physical <br>-separated line), where
 *   - chords = space-separated symbols from inline <chord …> (the editor #1094
 *     per-line cell shape — name= for OpenLyrics 0.8, root[+structure][/bass] for 0.9);
 *   - note   = the line's <comment> text (joined if several).
 * These ride the component `chords` / `notes` arrays through the SANCTIONED
 * lyricLinesWriteComponents() write path (rule #25 — no direct ChordsJson/NotesJson
 * write). Per-verse translation/transliteration is handled by the CALLER setting
 * the component's `language` from the verse's lang attribute (OpenLyrics models a
 * translation as a separate <verse lang="…">, not inline) — so no per-line
 * tblLyricLineTranslations / Id-threading is needed for the OpenLyrics shape.
 */
function _bulkImport_openLyricsParseLines(\SimpleXMLElement $linesNode): array
{
    $inner = (string)$linesNode->asXML();
    $inner = (string)preg_replace('#^<lines\b[^>]*>#i', '', $inner);
    $inner = (string)preg_replace('#</lines>\s*$#i', '', $inner);

    /* <br> is the OpenLyrics physical line separator. */
    $segments = preg_split('#<br\s*/?>#i', $inner) ?: [];
    $out = [];
    foreach ($segments as $seg) {
        /* Inline chords: <chord name="G"/> (0.8) | <chord root="C" structure="m7" bass="E"/> (0.9). */
        $chords = [];
        if (preg_match_all('#<chord\b([^>]*?)/?>#i', $seg, $cm)) {
            foreach ($cm[1] as $attrs) {
                $sym = '';
                if (preg_match('#\bname\s*=\s*("|\')(.*?)\1#i', $attrs, $a)) {
                    $sym = trim($a[2]);
                } elseif (preg_match('#\broot\s*=\s*("|\')(.*?)\1#i', $attrs, $r)) {
                    $sym = trim($r[2]);
                    if (preg_match('#\bstructure\s*=\s*("|\')(.*?)\1#i', $attrs, $s)) { $sym .= trim($s[2]); }
                    if (preg_match('#\bbass\s*=\s*("|\')(.*?)\1#i', $attrs, $b))      { $sym .= '/' . trim($b[2]); }
                }
                if ($sym !== '') { $chords[] = $sym; }
            }
        }
        /* Per-line comment → presenter note. */
        $notes = [];
        if (preg_match_all('#<comment\b[^>]*>(.*?)</comment>#is', $seg, $nm)) {
            foreach ($nm[1] as $c) {
                $c = trim(html_entity_decode((string)preg_replace('#<[^>]+>#', '', $c), ENT_QUOTES | ENT_XML1, 'UTF-8'));
                if ($c !== '') { $notes[] = $c; }
            }
        }
        /* Bare lyric text: drop comments wholesale, then all tags; collapse any
           source newline whitespace inside the segment to a single space. */
        $bare = (string)preg_replace('#<comment\b[^>]*>.*?</comment>#is', '', $seg);
        $bare = (string)preg_replace('#<[^>]+>#', '', $bare);
        $bare = html_entity_decode($bare, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $bare = (string)preg_replace('#\s*\n\s*#u', ' ', $bare);
        $out[] = ['text' => rtrim($bare), 'chords' => implode(' ', $chords), 'note' => implode(' · ', $notes)];
    }

    /* Trim leading / trailing FULLY-blank lines (no text AND no chords/notes). */
    $blank = static fn(array $l): bool => trim($l['text']) === '' && $l['chords'] === '' && $l['note'] === '';
    while (!empty($out) && $blank($out[0]))                 { array_shift($out); }
    while (!empty($out) && $blank((array)end($out)))        { array_pop($out); }
    return $out;
}

/**
 * Parse one OpenLyrics XML document into a neutral structure (no songbook
 * abbreviation / number / SongId resolution — the caller does that, since it
 * depends on the live DB auto-increment).
 *
 * @return array{0: ?array, 1: ?string}  [parsed, errorReason]
 *   parsed = { title, songbookName, entry, language, ccli, copyright,
 *              writers[], components[] }
 */
function _bulkImport_parseOpenLyrics(string $body): array
{
    $body = (string)preg_replace('/^\xEF\xBB\xBF/', '', $body);

    /* Strip namespace declarations so plain SimpleXML traversal works
       regardless of the OpenLyrics namespace year (2009/…). We only read
       local element/attribute names, so dropping namespaces is safe. */
    $clean = (string)preg_replace('/\sxmlns(:\w+)?\s*=\s*("|\').*?\2/i', '', $body);

    $prevInternal = libxml_use_internal_errors(true);
    libxml_clear_errors();
    $xml = simplexml_load_string($clean, \SimpleXMLElement::class, LIBXML_NONET);
    if ($xml === false) {
        $err = libxml_get_last_error();
        libxml_clear_errors();
        libxml_use_internal_errors($prevInternal);
        return [null, 'invalid XML' . ($err ? ': ' . trim($err->message) : '')];
    }
    libxml_clear_errors();
    libxml_use_internal_errors($prevInternal);

    if (strtolower($xml->getName()) !== 'song') {
        return [null, 'XML root is <' . $xml->getName() . '>, expected <song>'];
    }

    $props = $xml->properties ?? null;
    $title = $props ? trim((string)($props->titles->title ?? '')) : '';
    if ($title === '') {
        return [null, 'no <title> element'];
    }

    /* Authors — OpenLyrics lists one <author> per credit. */
    $writers = [];
    if ($props && isset($props->authors->author)) {
        foreach ($props->authors->author as $a) {
            $w = trim((string)$a);
            if ($w !== '') {
                $writers[] = $w;
            }
        }
    }

    $copyright = $props ? trim((string)($props->copyright ?? '')) : '';
    $ccli      = $props ? trim((string)($props->ccliNo ?? '')) : '';

    /* Songbook name + entry number (the first <songbook> wins). */
    $songbookName = '';
    $entry        = 0;
    if ($props && isset($props->songbooks->songbook)) {
        $sb           = $props->songbooks->songbook[0];
        $songbookName = trim((string)($sb['name'] ?? ''));
        $entryRaw     = trim((string)($sb['entry'] ?? ''));
        if ($entryRaw !== '' && ctype_digit($entryRaw)) {
            $entry = (int)$entryRaw;
        }
    }

    /* Song-level language: OpenLyrics may set xml:lang on <verse> or on a
       <title lang>; we read the first verse's lang as a best-effort hint. */
    $language = '';

    $components = [];
    if (isset($xml->lyrics->verse)) {
        foreach ($xml->lyrics->verse as $verse) {
            [$type, $num] = _bulkImport_openLyricsVerseType((string)($verse['name'] ?? 'v'));
            /* Per-verse language = the translation/transliteration signal (#1130):
               OpenLyrics models a translated verse as a separate <verse lang="…">. */
            $verseLang = isset($verse['lang']) ? trim((string)$verse['lang']) : '';
            if ($language === '' && $verseLang !== '') {
                $language = $verseLang;
            }
            $lines = []; $chords = []; $notes = [];
            foreach (($verse->lines ?? []) as $linesNode) {
                foreach (_bulkImport_openLyricsParseLines($linesNode) as $ln) {
                    $lines[]  = $ln['text'];
                    $chords[] = $ln['chords'];
                    $notes[]  = $ln['note'];
                }
            }
            if (!empty($lines)) {
                $comp = ['type' => $type, 'number' => $num, 'lines' => $lines];
                /* Carry enrichment only when present (chordless/noteless/mono-lingual
                   verses stay byte-identical to the pre-#1130 component shape). */
                if ($verseLang !== '') { $comp['language'] = $verseLang; }
                foreach ($chords as $c) { if ($c !== '') { $comp['chords'] = $chords; break; } }
                foreach ($notes as $n)  { if ($n !== '') { $comp['notes']  = $notes;  break; } }
                $components[] = $comp;
            }
        }
    }
    if (empty($components)) {
        return [null, 'no <verse>/<lines> content found'];
    }

    return [[
        'title'        => $title,
        'songbookName' => $songbookName,
        'entry'        => $entry,
        'language'     => $language,
        'ccli'         => $ccli,
        'copyright'    => $copyright,
        'writers'      => $writers,
        'components'   => $components,
    ], null];
}

/**
 * Assemble a neutral parsed structure into the song shape that
 * _bulkImport_saveSong() consumes (mirrors _bulkImport_parseOpenSong()).
 * Format-agnostic: any importer whose parser returns the
 * { title, language, ccli, copyright, writers[], components[] } keys can
 * reuse this (OpenLyrics #1052, ProPresenter 6 #1057, …).
 */
function _bulkImport_assembleSong(array $parsed, string $abbr, string $songbookName, int $number): array
{
    return [
        'id'                 => sprintf('%s-%04d', strtoupper($abbr), $number),
        'title'              => (string)$parsed['title'],
        'number'             => $number,
        'songbook'           => strtoupper($abbr),
        'songbookName'       => $songbookName,
        'language'           => ($parsed['language'] ?? '') !== '' ? (string)$parsed['language'] : 'en',
        'ccli'               => (string)($parsed['ccli'] ?? ''),
        'iswc'               => '',
        'tuneName'           => '',
        'copyright'          => (string)($parsed['copyright'] ?? ''),
        'verified'           => 0,
        'lyricsPublicDomain' => 0,
        'musicPublicDomain'  => 0,
        'hasAudio'           => 0,
        'hasSheetMusic'      => 0,
        'writers'            => (array)($parsed['writers'] ?? []),
        'composers'          => [],
        'arrangers'          => [],
        'adaptors'           => [],
        'translators'        => [],
        'components'         => (array)($parsed['components'] ?? []),
    ];
}

/**
 * Synchronous single-file OpenLyrics import — invoked from the
 * bulk_import_openlp dispatcher case. Returns the same summary shape as
 * _bulkImport_processVideoPsalm() / _bulkImport_processZip().
 */
function _bulkImport_processOpenLp(string $body, ?string $filenameHint = null): array
{
    [$parsed, $reason] = _bulkImport_parseOpenLyrics($body);
    if ($parsed === null) {
        return [
            'ok'                     => false,
            'error'                  => $reason ?: 'OpenLyrics parse failed',
            'songbooks_created'      => [],
            'songbooks_existing'     => [],
            'songs_created'          => 0,
            'songs_skipped_existing' => 0,
            'songs_failed'           => 0,
            'parsed_by_format'       => ['openlyrics' => 0],
            'errors'                 => [],
        ];
    }

    $db       = getDbMysqli();
    $bookName = (string)$parsed['songbookName'] !== ''
        ? (string)$parsed['songbookName']
        : (pathinfo((string)$filenameHint, PATHINFO_FILENAME) ?: 'OpenLP Import');
    $abbr  = _bulkImport_videopsalmAbbrevFromHint($filenameHint, $bookName);

    $state             = _bulkImport_upsertSongbook($db, $abbr, $bookName, ($parsed['language'] ?? '') ?: null);
    $songbooksCreated  = ($state === 'created')  ? [$abbr] : [];
    $songbooksExisting = ($state === 'existing') ? [$abbr] : [];

    $number = (int)$parsed['entry'] > 0
        ? (int)$parsed['entry']
        : _bulkImport_nextSongNumberFor($db, $abbr);
    $song = _bulkImport_assembleSong($parsed, $abbr, $bookName, $number);

    $songsCreated = 0;
    $songsSkipped = 0;
    $songsFailed  = 0;
    $errors       = [];
    [$action, $saveErr] = _bulkImport_saveSong($db, $song);
    if ($action === 'create') {
        $songsCreated = 1;
    } elseif ($action === 'skipped') {
        $songsSkipped = 1;
    } else {
        $songsFailed = 1;
        $errors[]    = [
            'entry' => ($filenameHint ?? 'openlp') . ': ' . ($song['id'] ?? '?'),
            'error' => 'save failed: ' . $saveErr,
        ];
    }

    if (!empty($songbooksCreated)) {
        $cnt = $db->prepare(
            /* #1694 D1 — SongCount counts VISIBLE songs (agrees with the
               predicate-aware triggers; trigger-denied hosts rely on this). */
            'UPDATE tblSongbooks
                SET SongCount = (SELECT COUNT(*) FROM tblSongs WHERE SongbookAbbr = ? AND ' . songVisibleSql($db, '') . ')
              WHERE Abbreviation = ?'
        );
        $cnt->bind_param('ss', $abbr, $abbr);
        $cnt->execute();
        $cnt->close();
    }

    return [
        'ok'                     => true,
        'songbooks_created'      => $songbooksCreated,
        'songbooks_existing'     => $songbooksExisting,
        'songs_created'          => $songsCreated,
        'songs_skipped_existing' => $songsSkipped,
        'songs_failed'           => $songsFailed,
        'parsed_by_format'       => ['openlyrics' => $songsCreated + $songsSkipped],
        'errors'                 => $errors,
    ];
}

/* ===========================================================================
 *  ProPresenter 6 import (#1057)
 * ---------------------------------------------------------------------------
 * A ProPresenter 6 ".pro6" file is an XML <RVPresentationDocument>. Slides
 * are organised into <RVSlideGrouping name="Verse 1"> groups; each slide's
 * lyric text lives inside an <RVTextElement> as base64-encoded RTF (either an
 * RTFData="…" attribute on older builds, or a <NSString rvXMLIvarName="RTFData">
 * child on newer ones). Song metadata (title / author / CCLI / copyright)
 * rides on the root element's CCLI* attributes.
 *
 * Each .pro6 is one song. A single file imports via the bulk_import_pro6
 * action; a ZIP of .pro6 files is handled inline by _bulkImport_processZip().
 * Both feed the shared _bulkImport_assembleSong() + dedupe-aware
 * _bulkImport_saveSong() path.
 * =========================================================================== */

/**
 * Minimal RTF → plain-text converter, sufficient for the simple RTF
 * ProPresenter stores (lyric runs with \par / \line breaks, \uN unicode,
 * \'XX hex bytes; \fonttbl / \colortbl / \*-destinations discarded).
 *
 * Implemented as a single-pass tokeniser tracking group depth so nested
 * destination groups (font/colour/style tables, \* groups) are skipped
 * wholesale rather than leaking control text into the lyrics.
 */
function _bulkImport_rtfToText(string $rtf): string
{
    $out = '';
    $i   = 0;
    $n   = strlen($rtf);
    $depth          = 0;
    $ucStack        = [1];   // \uc skip-count per group level
    $unicodeSkip    = 0;     // literal chars still to swallow after a \uN
    $skipUntilDepth = -1;    // when >=0, suppress output until depth drops below it

    while ($i < $n) {
        $c = $rtf[$i];

        if ($c === '\\') {
            $next = $i + 1 < $n ? $rtf[$i + 1] : '';

            /* Escaped literal brace / backslash. */
            if ($next === '\\' || $next === '{' || $next === '}') {
                if ($skipUntilDepth < 0) {
                    if ($unicodeSkip > 0) { $unicodeSkip--; }
                    else                  { $out .= $next; }
                }
                $i += 2;
                continue;
            }

            /* \'XX — one hex-encoded byte. */
            if ($next === "'") {
                $hex = substr($rtf, $i + 2, 2);
                $i  += 4;
                if ($skipUntilDepth < 0) {
                    if ($unicodeSkip > 0) { $unicodeSkip--; }
                    elseif (ctype_xdigit($hex)) { $out .= chr(hexdec($hex)); }
                }
                continue;
            }

            /* Control word: \word, optional signed number, optional single
               trailing space delimiter. */
            if (ctype_alpha($next)) {
                $j = $i + 1;
                while ($j < $n && ctype_alpha($rtf[$j])) { $j++; }
                $word = substr($rtf, $i + 1, $j - ($i + 1));
                $num  = '';
                if ($j < $n && ($rtf[$j] === '-' || ctype_digit($rtf[$j]))) {
                    $k = $j;
                    if ($rtf[$k] === '-') { $k++; }
                    while ($k < $n && ctype_digit($rtf[$k])) { $k++; }
                    $num = substr($rtf, $j, $k - $j);
                    $j   = $k;
                }
                if ($j < $n && $rtf[$j] === ' ') { $j++; }
                $i = $j;

                switch ($word) {
                    case 'u':
                        $code = (int)$num;
                        if ($code < 0) { $code += 65536; }
                        if ($skipUntilDepth < 0) {
                            $ch = function_exists('mb_chr') ? mb_chr($code, 'UTF-8') : null;
                            if ($ch !== null && $ch !== false) { $out .= $ch; }
                        }
                        $unicodeSkip = $ucStack[count($ucStack) - 1];
                        break;
                    case 'uc':
                        $ucStack[count($ucStack) - 1] = max(0, (int)$num);
                        break;
                    case 'par':
                    case 'line':
                        if ($skipUntilDepth < 0) { $out .= "\n"; }
                        break;
                    case 'tab':
                        if ($skipUntilDepth < 0) { $out .= "\t"; }
                        break;
                    case 'fonttbl':
                    case 'colortbl':
                    case 'stylesheet':
                    case 'info':
                    case 'pict':
                    case 'object':
                    case 'themedata':
                    case 'datastore':
                        /* Destination group we never want as text — skip the
                           rest of the current group. */
                        if ($skipUntilDepth < 0) { $skipUntilDepth = $depth; }
                        break;
                    default:
                        /* Formatting control word with no textual output. */
                        break;
                }
                continue;
            }

            /* \* — ignorable destination: skip the enclosing group. */
            if ($next === '*') {
                if ($skipUntilDepth < 0) { $skipUntilDepth = $depth; }
                $i += 2;
                continue;
            }

            /* Other control symbol (e.g. \~, \-) — drop it. */
            $i += 2;
            continue;
        }

        if ($c === '{') {
            $depth++;
            $ucStack[] = $ucStack[count($ucStack) - 1];
            $i++;
            continue;
        }
        if ($c === '}') {
            if ($skipUntilDepth >= 0 && $depth <= $skipUntilDepth) {
                $skipUntilDepth = -1;
            }
            if ($depth > 0) { $depth--; }
            if (count($ucStack) > 1) { array_pop($ucStack); }
            $i++;
            continue;
        }

        /* Raw CR/LF inside RTF source are not text. */
        if ($c === "\r" || $c === "\n") { $i++; continue; }

        if ($skipUntilDepth < 0) {
            if ($unicodeSkip > 0) { $unicodeSkip--; }
            else                  { $out .= $c; }
        }
        $i++;
    }

    return $out;
}

/**
 * Split a ProPresenter group label ("Verse 1", "Chorus", "Pre-Chorus 2")
 * into [iHymns component type, number]. Reuses _bulkImport_componentTypeFor()
 * for the word → type mapping (non-English / unknown labels → refrain).
 */
function _bulkImport_pro6GroupType(string $label): array
{
    $label = trim($label);
    $num   = 0;
    if (preg_match('/^(.*?)[\s_-]*(\d+)\s*$/u', $label, $m)) {
        $word = trim($m[1]);
        $num  = (int)$m[2];
    } else {
        $word = $label;
    }
    /* Normalise a few common ProPresenter labels to the marker words
       _bulkImport_componentTypeFor() understands. */
    $word  = strtolower($word);
    $alias = [
        'pre chorus' => 'pre-chorus',
        'prechorus'  => 'pre-chorus',
        'ending'     => 'outro',
        'tag'        => 'outro',
        'coda'       => 'outro',
        'intro'      => 'intro',
        'interlude'  => 'refrain',
        'vamp'       => 'refrain',
    ];
    $word = $alias[$word] ?? $word;
    return [_bulkImport_componentTypeFor($word), $num];
}

/**
 * Extract the base64 RTF text from one <RVTextElement>, tolerating both the
 * RTFData="…" attribute form and the <NSString rvXMLIvarName="RTFData"> child
 * form. Returns the decoded plain text (may be '').
 */
function _bulkImport_pro6TextElementToText(\SimpleXMLElement $textEl): string
{
    $b64 = trim((string)($textEl['RTFData'] ?? ''));
    if ($b64 === '') {
        foreach ($textEl->xpath('.//*[@rvXMLIvarName="RTFData"]') ?: [] as $child) {
            $b64 = trim((string)$child);
            if ($b64 !== '') { break; }
        }
    }
    if ($b64 === '') {
        return '';
    }
    $rtf = base64_decode($b64, true);
    if ($rtf === false || $rtf === '') {
        return '';
    }
    return _bulkImport_rtfToText($rtf);
}

/**
 * Parse one ProPresenter 6 ".pro6" XML body into the neutral parsed
 * structure (no abbrev / number / SongId resolution — caller does that).
 *
 * @return array{0: ?array, 1: ?string}  [parsed, errorReason]
 */
function _bulkImport_parsePro6(string $body): array
{
    $body = (string)preg_replace('/^\xEF\xBB\xBF/', '', $body);

    $prevInternal = libxml_use_internal_errors(true);
    libxml_clear_errors();
    $xml = simplexml_load_string($body, \SimpleXMLElement::class, LIBXML_NONET);
    if ($xml === false) {
        $err = libxml_get_last_error();
        libxml_clear_errors();
        libxml_use_internal_errors($prevInternal);
        return [null, 'invalid XML' . ($err ? ': ' . trim($err->message) : '')];
    }
    libxml_clear_errors();
    libxml_use_internal_errors($prevInternal);

    if (strtolower($xml->getName()) !== 'rvpresentationdocument') {
        return [null, 'XML root is <' . $xml->getName() . '>, expected <RVPresentationDocument>'];
    }

    /* Metadata from the root CCLI* attributes. */
    $title     = trim((string)($xml['CCLISongTitle'] ?? ''));
    $author    = trim((string)($xml['CCLIAuthor'] ?? ''));
    $publisher = trim((string)($xml['CCLIPublisher'] ?? ''));
    $ccli      = trim((string)($xml['CCLISongNumber'] ?? ''));
    $year      = trim((string)($xml['CCLICopyrightYear'] ?? ''));
    $copyright = trim(($publisher !== '' ? $publisher : '') . ($year !== '' ? ($publisher !== '' ? ' ' : '') . $year : ''));

    $writers = [];
    if ($author !== '') {
        foreach (preg_split('/\s*[\/&,;]\s*/u', $author) as $w) {
            $w = trim((string)$w);
            if ($w !== '') { $writers[] = $w; }
        }
    }

    /* Walk slide groupings. Each grouping → one component; its slides'
       text-element lines concatenate into that component's lines. */
    $components = [];
    foreach ($xml->xpath('//RVSlideGrouping') ?: [] as $group) {
        [$type, $num] = _bulkImport_pro6GroupType((string)($group['name'] ?? ''));
        $lines = [];
        foreach ($group->xpath('.//RVTextElement') ?: [] as $textEl) {
            $text = _bulkImport_pro6TextElementToText($textEl);
            if ($text === '') { continue; }
            foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $text)) as $ln) {
                $ln = rtrim($ln);
                if ($ln !== '' || !empty($lines)) { $lines[] = $ln; }
            }
        }
        while (!empty($lines) && trim((string)end($lines)) === '') { array_pop($lines); }
        if (!empty($lines)) {
            $components[] = ['type' => $type, 'number' => $num, 'lines' => $lines];
        }
    }

    /* Fallback: some .pro6 files have no groupings — collect every slide's
       text as sequential verses. */
    if (empty($components)) {
        $vnum = 0;
        foreach ($xml->xpath('//RVDisplaySlide') ?: [] as $slide) {
            $lines = [];
            foreach ($slide->xpath('.//RVTextElement') ?: [] as $textEl) {
                $text = _bulkImport_pro6TextElementToText($textEl);
                if ($text === '') { continue; }
                foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $text)) as $ln) {
                    $ln = rtrim($ln);
                    if ($ln !== '' || !empty($lines)) { $lines[] = $ln; }
                }
            }
            while (!empty($lines) && trim((string)end($lines)) === '') { array_pop($lines); }
            if (!empty($lines)) {
                $components[] = ['type' => 'verse', 'number' => ++$vnum, 'lines' => $lines];
            }
        }
    }

    if (empty($components)) {
        return [null, 'no slide text found in .pro6'];
    }

    /* Title fallback: first line of the first component (caller may further
       fall back to the filename). */
    if ($title === '') {
        $title = trim((string)($components[0]['lines'][0] ?? ''));
    }
    if ($title === '') {
        return [null, 'no song title (no CCLISongTitle and no slide text)'];
    }

    return [[
        'title'        => $title,
        'songbookName' => '',   // .pro6 carries no songbook; caller supplies one
        'entry'        => 0,
        'language'     => '',
        'ccli'         => $ccli,
        'copyright'    => $copyright,
        'writers'      => $writers,
        'components'   => $components,
    ], null];
}

/**
 * Synchronous single-file ProPresenter 6 import — invoked from the
 * bulk_import_pro6 dispatcher case. Same summary shape as the other
 * single-file processors. The songbook is supplied by the caller (filename
 * hint), since .pro6 carries none.
 */
function _bulkImport_processPro6(string $body, ?string $filenameHint = null): array
{
    [$parsed, $reason] = _bulkImport_parsePro6($body);
    if ($parsed === null) {
        return [
            'ok'                     => false,
            'error'                  => $reason ?: 'ProPresenter 6 parse failed',
            'songbooks_created'      => [],
            'songbooks_existing'     => [],
            'songs_created'          => 0,
            'songs_skipped_existing' => 0,
            'songs_failed'           => 0,
            'parsed_by_format'       => ['propresenter6' => 0],
            'errors'                 => [],
        ];
    }

    /* .pro6 has no songbook — file them under a single "ProPresenter Import"
       songbook (abbr "PP6", or a bracketed token from the upload filename). */
    $db       = getDbMysqli();
    $bookName = 'ProPresenter Import';
    $abbr     = _bulkImport_videopsalmAbbrevFromHint($filenameHint, '');
    if ($abbr === 'VP' || $abbr === '') { $abbr = 'PP6'; }

    $state             = _bulkImport_upsertSongbook($db, $abbr, $bookName, null);
    $songbooksCreated  = ($state === 'created')  ? [$abbr] : [];
    $songbooksExisting = ($state === 'existing') ? [$abbr] : [];

    $number = _bulkImport_nextSongNumberFor($db, $abbr);
    $song   = _bulkImport_assembleSong($parsed, $abbr, $bookName, $number);

    $songsCreated = 0;
    $songsSkipped = 0;
    $songsFailed  = 0;
    $errors       = [];
    [$action, $saveErr] = _bulkImport_saveSong($db, $song);
    if ($action === 'create')       { $songsCreated = 1; }
    elseif ($action === 'skipped')  { $songsSkipped = 1; }
    else {
        $songsFailed = 1;
        $errors[]    = [
            'entry' => ($filenameHint ?? 'pro6') . ': ' . ($song['id'] ?? '?'),
            'error' => 'save failed: ' . $saveErr,
        ];
    }

    if (!empty($songbooksCreated)) {
        $cnt = $db->prepare(
            /* #1694 D1 — SongCount counts VISIBLE songs (agrees with the
               predicate-aware triggers; trigger-denied hosts rely on this). */
            'UPDATE tblSongbooks
                SET SongCount = (SELECT COUNT(*) FROM tblSongs WHERE SongbookAbbr = ? AND ' . songVisibleSql($db, '') . ')
              WHERE Abbreviation = ?'
        );
        $cnt->bind_param('ss', $abbr, $abbr);
        $cnt->execute();
        $cnt->close();
    }

    return [
        'ok'                     => true,
        'songbooks_created'      => $songbooksCreated,
        'songbooks_existing'     => $songbooksExisting,
        'songs_created'          => $songsCreated,
        'songs_skipped_existing' => $songsSkipped,
        'songs_failed'           => $songsFailed,
        'parsed_by_format'       => ['propresenter6' => $songsCreated + $songsSkipped],
        'errors'                 => $errors,
    ];
}

/* ===========================================================================
 *  EasyWorship import (#1058)
 * ---------------------------------------------------------------------------
 * EasyWorship 6/7 stores its library in SQLite. The song metadata lives in
 * `Songs.db` (table `song`: title / author / copyright / reference_number)
 * and the lyrics in a `word` table — inline in Songs.db on some builds, or in
 * a sibling `SongWords.db` (table `word`: song_id, words) on others. The
 * `words` column is RTF, decoded with the shared _bulkImport_rtfToText().
 *
 * Upload either a single Songs.db, or a .zip containing Songs.db (+ optional
 * SongWords.db). We read with the SQLite3 class (NOT PDO — this is an upload
 * parser, not the app's MySQL data layer). EasyWorship has no songbook, so
 * songs are filed under an "EasyWorship Import" (EW) songbook.
 * =========================================================================== */

/**
 * Split decoded EasyWorship lyric text into components. EW separates slides
 * with a blank line; a leading "Verse 1"/"Chorus" label line (when present)
 * sets the component type/number, otherwise blocks become sequential verses.
 */
function _bulkImport_easyWorshipSplitComponents(string $text): array
{
    $text   = str_replace(["\r\n", "\r"], "\n", $text);
    $blocks = preg_split('/\n[ \t]*\n+/', trim($text)) ?: [];
    $components = [];
    $vnum = 0;
    foreach ($blocks as $block) {
        $lines = array_map('rtrim', explode("\n", $block));
        while (!empty($lines) && trim($lines[0]) === '')          { array_shift($lines); }
        while (!empty($lines) && trim((string)end($lines)) === '') { array_pop($lines); }
        if (empty($lines)) { continue; }

        $type = 'verse';
        $num  = ++$vnum;
        if (preg_match('/^(verse|chorus|refrain|bridge|pre[- ]?chorus|intro|outro|ending|tag)\s*(\d*)\s*$/i', trim($lines[0]), $m)) {
            $word = strtolower($m[1]);
            $word = ($word === 'prechorus' || $word === 'pre chorus') ? 'pre-chorus' : $word;
            $word = ($word === 'ending' || $word === 'tag') ? 'outro' : $word;
            $type = _bulkImport_componentTypeFor($word);
            $num  = $m[2] !== '' ? (int)$m[2] : 0;
            array_shift($lines);
            if (empty($lines)) { continue; }
        }
        $components[] = ['type' => $type, 'number' => $num, 'lines' => $lines];
    }
    return $components;
}

/**
 * Return the subset of $wanted columns that actually exist on $table, so a
 * SELECT survives EasyWorship's schema drift across versions.
 */
function _bulkImport_sqliteColumns(\SQLite3 $db, string $table): array
{
    $cols = [];
    $safeTable = preg_replace('/[^A-Za-z0-9_]/', '', $table);
    $res = @$db->query('PRAGMA table_info("' . $safeTable . '")');
    if ($res === false) { return $cols; }
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $name = (string)$row['name'];
        /* SECURITY: these column names come from an UNTRUSTED uploaded SQLite
           schema and are later interpolated (double-quoted) into SELECTs
           (e.g. '"' . $titleC . '"'). A name containing a double-quote would
           break out of the "<col>" identifier quoting and inject SQL. Reject
           anything that isn't a plain identifier — EasyWorship's real columns
           are all [A-Za-z0-9_], so this drops only hostile names. */
        if ($name === '' || !preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            continue;
        }
        $cols[strtolower($name)] = $name;
    }
    return $cols;
}

/**
 * Read an EasyWorship Songs.db (+ optional SongWords.db) into the neutral
 * parsed-song structures _bulkImport_assembleSong() consumes.
 *
 * @return array{0: array, 1: ?string}  [parsedSongs[], errorReason]
 */
function _bulkImport_easyWorshipReadDb(string $songsDbPath, ?string $songWordsDbPath = null): array
{
    if (!class_exists('SQLite3')) {
        return [[], 'the SQLite3 PHP extension is not available on this server'];
    }
    try {
        $songsDb = new \SQLite3($songsDbPath, SQLITE3_OPEN_READONLY);
    } catch (\Throwable $e) {
        return [[], 'could not open Songs.db: ' . $e->getMessage()];
    }
    $songsDb->busyTimeout(2000);

    $songCols = _bulkImport_sqliteColumns($songsDb, 'song');
    if (empty($songCols)) {
        $songsDb->close();
        return [[], 'no `song` table found (is this an EasyWorship Songs.db?)'];
    }

    /* Words may live in this db (table `word`) or in SongWords.db. */
    $wordsDb     = $songsDb;
    $wordsClose  = false;
    $wordCols    = _bulkImport_sqliteColumns($songsDb, 'word');
    if (empty($wordCols) && $songWordsDbPath !== null) {
        try {
            $wordsDb    = new \SQLite3($songWordsDbPath, SQLITE3_OPEN_READONLY);
            $wordsClose = true;
            $wordsDb->busyTimeout(2000);
            $wordCols   = _bulkImport_sqliteColumns($wordsDb, 'word');
        } catch (\Throwable $e) {
            /* No words db — titles still import, just without lyrics. */
            $wordCols = [];
        }
    }

    /* Build a song_id → words map once. */
    $wordsBySong = [];
    if (!empty($wordCols) && isset($wordCols['words'])) {
        $idCol = $wordCols['song_id'] ?? ($wordCols['songid'] ?? null);
        if ($idCol !== null) {
            $res = @$wordsDb->query('SELECT "' . $idCol . '" AS sid, "' . $wordCols['words'] . '" AS w FROM "word"');
            if ($res !== false) {
                while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
                    $wordsBySong[(string)$row['sid']] = (string)$row['w'];
                }
            }
        }
    }

    /* Select the song rows. rowid is always available; the rest are guarded. */
    $titleC = $songCols['title']            ?? null;
    $authC  = $songCols['author']           ?? null;
    $copyC  = $songCols['copyright']         ?? null;
    $refC   = $songCols['reference_number']  ?? ($songCols['song_number'] ?? null);
    if ($titleC === null) {
        $songsDb->close();
        if ($wordsClose) { $wordsDb->close(); }
        return [[], '`song` table has no `title` column'];
    }

    $sel = ['rowid AS rid', '"' . $titleC . '" AS title'];
    $sel[] = $authC !== null ? '"' . $authC . '" AS author' : "'' AS author";
    $sel[] = $copyC !== null ? '"' . $copyC . '" AS copyright' : "'' AS copyright";
    $sel[] = $refC  !== null ? '"' . $refC  . '" AS refnum' : "'' AS refnum";
    $sql = 'SELECT ' . implode(', ', $sel) . ' FROM "song"';

    $songs = [];
    $res = @$songsDb->query($sql);
    if ($res === false) {
        $songsDb->close();
        if ($wordsClose) { $wordsDb->close(); }
        return [[], 'failed to read the `song` table'];
    }
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $title = trim((string)$row['title']);
        if ($title === '') { continue; }
        $rid     = (string)$row['rid'];
        $rtf     = $wordsBySong[$rid] ?? '';
        $text    = $rtf !== '' ? _bulkImport_rtfToText($rtf) : '';
        $components = $text !== '' ? _bulkImport_easyWorshipSplitComponents($text) : [];
        if (empty($components)) {
            /* Title-only row (no lyrics) — skip rather than write an empty song. */
            continue;
        }
        $writers = [];
        $author  = trim((string)$row['author']);
        if ($author !== '') {
            foreach (preg_split('/\s*[\/&,;]\s*/u', $author) as $w) {
                $w = trim((string)$w);
                if ($w !== '') { $writers[] = $w; }
            }
        }
        $songs[] = [
            'title'        => $title,
            'songbookName' => '',
            'entry'        => ctype_digit(trim((string)$row['refnum'])) ? (int)trim((string)$row['refnum']) : 0,
            'language'     => '',
            'ccli'         => '',
            'copyright'    => trim((string)$row['copyright']),
            'writers'      => $writers,
            'components'   => $components,
        ];
    }

    $songsDb->close();
    if ($wordsClose) { $wordsDb->close(); }
    return [$songs, null];
}

/**
 * Synchronous EasyWorship import. Accepts either a single Songs.db file or a
 * .zip containing Songs.db (+ optional SongWords.db). Writes uploaded SQLite
 * to temp files (SQLite3 needs a path), reads them, and imports each song
 * under an "EasyWorship Import" (EW) songbook. Same summary shape as the
 * other single-file processors.
 */
function _bulkImport_processEasyWorship(string $tmpPath, string $origName): array
{
    $fail = function (string $msg): array {
        return [
            'ok'                     => false,
            'error'                  => $msg,
            'songbooks_created'      => [],
            'songbooks_existing'     => [],
            'songs_created'          => 0,
            'songs_skipped_existing' => 0,
            'songs_failed'           => 0,
            'parsed_by_format'       => ['easyworship' => 0],
            'errors'                 => [],
        ];
    };

    $isZip   = strtolower(pathinfo($origName, PATHINFO_EXTENSION)) === 'zip';
    $tempFiles = [];
    $songsDbPath = null;
    $songWordsDbPath = null;

    if ($isZip) {
        $zip = new \ZipArchive();
        if ($zip->open($tmpPath) !== true) {
            return $fail('could not open the uploaded .zip');
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string)$zip->getNameIndex($i);
            $leaf = strtolower(basename($name));
            if ($leaf === 'songs.db' || $leaf === 'songwords.db') {
                $bytes = $zip->getFromIndex($i);
                if ($bytes === false) { continue; }
                $t = tempnam(sys_get_temp_dir(), 'ew_');
                file_put_contents($t, $bytes);
                $tempFiles[] = $t;
                if ($leaf === 'songs.db')     { $songsDbPath = $t; }
                if ($leaf === 'songwords.db') { $songWordsDbPath = $t; }
            }
        }
        $zip->close();
        if ($songsDbPath === null) {
            foreach ($tempFiles as $t) { @unlink($t); }
            return $fail('the .zip does not contain a Songs.db');
        }
    } else {
        /* A single uploaded .db — SQLite3 can open the upload temp file
           directly (read-only). */
        $songsDbPath = $tmpPath;
    }

    [$parsedSongs, $err] = _bulkImport_easyWorshipReadDb($songsDbPath, $songWordsDbPath);
    foreach ($tempFiles as $t) { @unlink($t); }

    if ($err !== null) {
        return $fail('EasyWorship read failed: ' . $err);
    }
    if (empty($parsedSongs)) {
        return $fail('no songs with lyrics found in the EasyWorship database');
    }

    $db       = getDbMysqli();
    $abbr     = 'EW';
    $bookName = 'EasyWorship Import';
    $state             = _bulkImport_upsertSongbook($db, $abbr, $bookName, null);
    $songbooksCreated  = ($state === 'created')  ? [$abbr] : [];
    $songbooksExisting = ($state === 'existing') ? [$abbr] : [];

    $counter = _bulkImport_nextSongNumberFor($db, $abbr);
    $songsCreated = 0;
    $songsSkipped = 0;
    $songsFailed  = 0;
    $errors       = [];
    foreach ($parsedSongs as $parsed) {
        $number = (int)$parsed['entry'] > 0 ? (int)$parsed['entry'] : $counter;
        $counter = max($counter, $number) + 1;
        $song = _bulkImport_assembleSong($parsed, $abbr, $bookName, $number);
        [$action, $saveErr] = _bulkImport_saveSong($db, $song);
        if ($action === 'create')      { $songsCreated++; }
        elseif ($action === 'skipped') { $songsSkipped++; }
        else {
            $songsFailed++;
            $errors[] = ['entry' => $origName . ': ' . ($song['id'] ?? '?'), 'error' => 'save failed: ' . $saveErr];
        }
    }

    if (!empty($songbooksCreated)) {
        $cnt = $db->prepare(
            /* #1694 D1 — visible-count recompute (see the multi-line siblings). */
            'UPDATE tblSongbooks SET SongCount = (SELECT COUNT(*) FROM tblSongs WHERE SongbookAbbr = ? AND ' . songVisibleSql($db, '') . ') WHERE Abbreviation = ?'
        );
        $cnt->bind_param('ss', $abbr, $abbr);
        $cnt->execute();
        $cnt->close();
    }

    return [
        'ok'                     => true,
        'songbooks_created'      => $songbooksCreated,
        'songbooks_existing'     => $songbooksExisting,
        'songs_created'          => $songsCreated,
        'songs_skipped_existing' => $songsSkipped,
        'songs_failed'           => $songsFailed,
        'parsed_by_format'       => ['easyworship' => $songsCreated + $songsSkipped],
        'errors'                 => $errors,
    ];
}

/* ===========================================================================
 *  Proclaim import (#1062)
 * ---------------------------------------------------------------------------
 * Faithlife Proclaim exports a song as plain text or RTF. There's no rich
 * structured export to rely on, so this imports a single song from a .txt or
 * .rtf body: RTF is decoded with the shared _bulkImport_rtfToText(); the
 * first non-marker line is taken as the title; the rest is split into
 * components (blank-line slides / "Verse 1"/"Chorus" labels) by the shared
 * _bulkImport_easyWorshipSplitComponents(). Songs file under a "Proclaim
 * Import" (PC) songbook.
 * =========================================================================== */

/**
 * Parse a Proclaim text/RTF body into the neutral parsed-song structure.
 *
 * @return array{0: ?array, 1: ?string}  [parsed, errorReason]
 */
function _bulkImport_parseProclaimText(string $body, ?string $filenameHint = null): array
{
    $body = (string)preg_replace('/^\xEF\xBB\xBF/', '', $body);

    /* RTF? Decode to plain text first. */
    if (preg_match('/^\s*\{\\\\rtf/', $body)) {
        $body = _bulkImport_rtfToText($body);
    }
    $body = str_replace(["\r\n", "\r"], "\n", $body);

    /* Title: the first non-empty line, unless it's a section marker (in which
       case fall back to the filename). */
    $lines = explode("\n", $body);
    $titleLineIdx = -1;
    $title = '';
    foreach ($lines as $idx => $ln) {
        if (trim($ln) === '') { continue; }
        if (!preg_match('/^(verse|chorus|refrain|bridge|pre[- ]?chorus|intro|outro|ending|tag)\s*\d*\s*$/i', trim($ln))) {
            $title        = trim($ln);
            $titleLineIdx = $idx;
        }
        break;
    }

    /* Body for component splitting = everything after the title line (or the
       whole body if the first content line was a section marker). */
    if ($titleLineIdx >= 0) {
        $rest = implode("\n", array_slice($lines, $titleLineIdx + 1));
    } else {
        $rest = $body;
    }

    $components = _bulkImport_easyWorshipSplitComponents($rest);

    /* If there was no separable title line but we did parse lyrics, fall back
       to the filename stem for the title. */
    if ($title === '') {
        $title = trim((string)pathinfo((string)$filenameHint, PATHINFO_FILENAME));
    }
    if ($title === '') {
        return [null, 'could not determine a song title'];
    }
    if (empty($components)) {
        return [null, 'no lyric content found'];
    }

    return [[
        'title'        => $title,
        'songbookName' => '',
        'entry'        => 0,
        'language'     => '',
        'ccli'         => '',
        'copyright'    => '',
        'writers'      => [],
        'components'   => $components,
    ], null];
}

/**
 * Synchronous single-file Proclaim import — invoked from the
 * bulk_import_proclaim dispatcher case. Same summary shape as the other
 * single-file processors.
 */
function _bulkImport_processProclaim(string $body, ?string $filenameHint = null): array
{
    [$parsed, $reason] = _bulkImport_parseProclaimText($body, $filenameHint);
    if ($parsed === null) {
        return [
            'ok'                     => false,
            'error'                  => $reason ?: 'Proclaim parse failed',
            'songbooks_created'      => [],
            'songbooks_existing'     => [],
            'songs_created'          => 0,
            'songs_skipped_existing' => 0,
            'songs_failed'           => 0,
            'parsed_by_format'       => ['proclaim' => 0],
            'errors'                 => [],
        ];
    }

    $db       = getDbMysqli();
    $abbr     = 'PC';
    $bookName = 'Proclaim Import';
    $state             = _bulkImport_upsertSongbook($db, $abbr, $bookName, null);
    $songbooksCreated  = ($state === 'created')  ? [$abbr] : [];
    $songbooksExisting = ($state === 'existing') ? [$abbr] : [];

    $number = _bulkImport_nextSongNumberFor($db, $abbr);
    $song   = _bulkImport_assembleSong($parsed, $abbr, $bookName, $number);

    $songsCreated = 0;
    $songsSkipped = 0;
    $songsFailed  = 0;
    $errors       = [];
    [$action, $saveErr] = _bulkImport_saveSong($db, $song);
    if ($action === 'create')      { $songsCreated = 1; }
    elseif ($action === 'skipped') { $songsSkipped = 1; }
    else {
        $songsFailed = 1;
        $errors[]    = ['entry' => ($filenameHint ?? 'proclaim') . ': ' . ($song['id'] ?? '?'), 'error' => 'save failed: ' . $saveErr];
    }

    if (!empty($songbooksCreated)) {
        $cnt = $db->prepare(
            /* #1694 D1 — visible-count recompute (see the multi-line siblings). */
            'UPDATE tblSongbooks SET SongCount = (SELECT COUNT(*) FROM tblSongs WHERE SongbookAbbr = ? AND ' . songVisibleSql($db, '') . ') WHERE Abbreviation = ?'
        );
        $cnt->bind_param('ss', $abbr, $abbr);
        $cnt->execute();
        $cnt->close();
    }

    return [
        'ok'                     => true,
        'songbooks_created'      => $songbooksCreated,
        'songbooks_existing'     => $songbooksExisting,
        'songs_created'          => $songsCreated,
        'songs_skipped_existing' => $songsSkipped,
        'songs_failed'           => $songsFailed,
        'parsed_by_format'       => ['proclaim' => $songsCreated + $songsSkipped],
        'errors'                 => $errors,
    ];
}

/* ===========================================================================
 *  FreeShow import (#884)
 * ---------------------------------------------------------------------------
 * A FreeShow ".show" file is JSON: [ "<id>", { show } ]. The show holds a
 * `slides` map (each slide: group label + items[].lines[].text[].value runs)
 * and a `layouts` map whose active layout lists slide order. `meta` carries
 * title / author / copyright / CCLI. One .show is one song. Round-trips with
 * the iHymns FreeShow exporter (#1056). The group label → component type uses
 * the shared _bulkImport_pro6GroupType().
 * =========================================================================== */

/**
 * Flatten one FreeShow slide's items → an array of plain-text lines.
 * Each item has lines[], each line has text[] runs with a `value`.
 */
function _bulkImport_freeShowSlideLines(array $slide): array
{
    $lines = [];
    foreach (($slide['items'] ?? []) as $item) {
        if (!is_array($item)) { continue; }
        foreach (($item['lines'] ?? []) as $line) {
            if (!is_array($line)) { continue; }
            $buf = '';
            foreach (($line['text'] ?? []) as $run) {
                if (is_array($run) && isset($run['value'])) {
                    $buf .= (string)$run['value'];
                }
            }
            /* A run value may itself contain newlines. */
            foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $buf)) as $ln) {
                $lines[] = rtrim($ln);
            }
        }
    }
    while (!empty($lines) && trim((string)end($lines)) === '') { array_pop($lines); }
    return $lines;
}

/**
 * Parse one FreeShow .show document into the neutral parsed-song structure.
 *
 * @return array{0: ?array, 1: ?string}  [parsed, errorReason]
 */
function _bulkImport_parseFreeShow(string $body): array
{
    $body = (string)preg_replace('/^\xEF\xBB\xBF/', '', $body);
    $data = json_decode($body, true);
    if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
        return [null, 'invalid JSON: ' . json_last_error_msg()];
    }

    /* FreeShow stores [ "<id>", { show } ]; tolerate a bare show object too. */
    $show = null;
    if (is_array($data)) {
        if (isset($data[1]) && is_array($data[1])) {
            $show = $data[1];
        } elseif (isset($data['slides']) || isset($data['name'])) {
            $show = $data;
        }
    }
    if (!is_array($show) || empty($show['slides']) || !is_array($show['slides'])) {
        return [null, 'not a FreeShow .show document (no slides)'];
    }

    $meta  = is_array($show['meta'] ?? null) ? $show['meta'] : [];
    $title = trim((string)($meta['title'] ?? ($show['name'] ?? '')));

    /* Slide order from the active layout; fall back to the slides-map order. */
    $order = [];
    $layoutId = (string)($show['settings']['activeLayout'] ?? '');
    $layouts  = is_array($show['layouts'] ?? null) ? $show['layouts'] : [];
    if ($layoutId !== '' && isset($layouts[$layoutId]['slides']) && is_array($layouts[$layoutId]['slides'])) {
        foreach ($layouts[$layoutId]['slides'] as $ref) {
            if (is_array($ref) && isset($ref['id'])) { $order[] = (string)$ref['id']; }
        }
    } elseif (!empty($layouts)) {
        /* No active layout pointer — take the first layout's order. */
        $first = reset($layouts);
        foreach (($first['slides'] ?? []) as $ref) {
            if (is_array($ref) && isset($ref['id'])) { $order[] = (string)$ref['id']; }
        }
    }
    if (empty($order)) {
        $order = array_keys($show['slides']);
    }

    $components = [];
    foreach ($order as $sid) {
        $slide = $show['slides'][$sid] ?? null;
        if (!is_array($slide)) { continue; }
        $lines = _bulkImport_freeShowSlideLines($slide);
        if (empty($lines)) { continue; }
        [$type, $num] = _bulkImport_pro6GroupType((string)($slide['group'] ?? 'Verse'));
        $components[] = ['type' => $type, 'number' => $num, 'lines' => $lines];
    }
    if (empty($components)) {
        return [null, 'no slide text found'];
    }

    if ($title === '') {
        $title = trim((string)($components[0]['lines'][0] ?? ''));
    }
    if ($title === '') {
        return [null, 'no song title'];
    }

    $writers = [];
    $author  = trim((string)($meta['author'] ?? ''));
    if ($author !== '') {
        foreach (preg_split('/\s*[\/&,;]\s*/u', $author) as $w) {
            $w = trim((string)$w);
            if ($w !== '') { $writers[] = $w; }
        }
    }

    return [[
        'title'        => $title,
        'songbookName' => '',
        'entry'        => 0,
        'language'     => '',
        'ccli'         => trim((string)($meta['CCLI'] ?? ($meta['ccli'] ?? ''))),
        'copyright'    => trim((string)($meta['copyright'] ?? '')),
        'writers'      => $writers,
        'components'   => $components,
    ], null];
}

/**
 * Synchronous single-file FreeShow import — invoked from the
 * bulk_import_freeshow dispatcher case. Files songs under a "FreeShow Import"
 * (FS) songbook (.show carries none). Same summary shape as the other
 * single-file processors.
 */
function _bulkImport_processFreeShow(string $body, ?string $filenameHint = null): array
{
    [$parsed, $reason] = _bulkImport_parseFreeShow($body);
    if ($parsed === null) {
        return [
            'ok'                     => false,
            'error'                  => $reason ?: 'FreeShow parse failed',
            'songbooks_created'      => [],
            'songbooks_existing'     => [],
            'songs_created'          => 0,
            'songs_skipped_existing' => 0,
            'songs_failed'           => 0,
            'parsed_by_format'       => ['freeshow' => 0],
            'errors'                 => [],
        ];
    }

    $db       = getDbMysqli();
    $abbr     = 'FS';
    $bookName = 'FreeShow Import';
    $state             = _bulkImport_upsertSongbook($db, $abbr, $bookName, null);
    $songbooksCreated  = ($state === 'created')  ? [$abbr] : [];
    $songbooksExisting = ($state === 'existing') ? [$abbr] : [];

    $number = _bulkImport_nextSongNumberFor($db, $abbr);
    $song   = _bulkImport_assembleSong($parsed, $abbr, $bookName, $number);

    $songsCreated = 0;
    $songsSkipped = 0;
    $songsFailed  = 0;
    $errors       = [];
    [$action, $saveErr] = _bulkImport_saveSong($db, $song);
    if ($action === 'create')      { $songsCreated = 1; }
    elseif ($action === 'skipped') { $songsSkipped = 1; }
    else {
        $songsFailed = 1;
        $errors[]    = ['entry' => ($filenameHint ?? 'freeshow') . ': ' . ($song['id'] ?? '?'), 'error' => 'save failed: ' . $saveErr];
    }

    if (!empty($songbooksCreated)) {
        $cnt = $db->prepare(
            /* #1694 D1 — visible-count recompute (see the multi-line siblings). */
            'UPDATE tblSongbooks SET SongCount = (SELECT COUNT(*) FROM tblSongs WHERE SongbookAbbr = ? AND ' . songVisibleSql($db, '') . ') WHERE Abbreviation = ?'
        );
        $cnt->bind_param('ss', $abbr, $abbr);
        $cnt->execute();
        $cnt->close();
    }

    return [
        'ok'                     => true,
        'songbooks_created'      => $songbooksCreated,
        'songbooks_existing'     => $songbooksExisting,
        'songs_created'          => $songsCreated,
        'songs_skipped_existing' => $songsSkipped,
        'songs_failed'           => $songsFailed,
        'parsed_by_format'       => ['freeshow' => $songsCreated + $songsSkipped],
        'errors'                 => $errors,
    ];
}

/* ===========================================================================
 *  PowerPoint (.pptx) worship deck import (#1095)
 * ---------------------------------------------------------------------------
 * Parses a .pptx via PptxImporter (slide text + song segmentation), resolves
 * each song's songbook by the deck's "# <num>-<Songbook>" reference, and
 * creates songs through the shared importer helpers. Dedup is automatic:
 * _bulkImport_saveSong returns 'skipped' when the SongId already exists, so a
 * deck that references existing catalogue songs (e.g. Mission Praise #599) does
 * not create duplicates. Decks that do not use the expected layout return zero
 * songs + a warning (the caller routes them to submit-for-analysis, #1109).
 * Returns the same summary shape as the other _bulkImport_process* functions.
 * =========================================================================== */
function _bulkImport_processPptx(string $path, ?string $filenameHint = null): array
{
    require_once __DIR__ . '/PptxImporter.php';

    $empty = [
        'songbooks_created' => [], 'songbooks_existing' => [],
        'songs_created' => 0, 'songs_skipped_existing' => 0, 'songs_failed' => 0,
        'parsed_by_format' => ['pptx' => 0], 'errors' => [],
    ];

    $result = PptxImporter::parseFile($path);
    if (!($result['ok'] ?? false)) {
        return array_merge(['ok' => false, 'error' => $result['error'] ?? 'No songs found in the deck.', 'warnings' => $result['warnings'] ?? []], $empty);
    }

    $db = getDbMysqli();
    $created = 0; $skipped = 0; $failed = 0; $errors = [];
    $booksCreated = []; $booksExisting = [];

    foreach ($result['songs'] as $song) {
        [$abbr, $bookName, $state] = _bulkImport_resolvePptxSongbook($db, (string)($song['songbookName'] ?? ''));
        if ($state === 'created' && !in_array($abbr, $booksCreated, true))   { $booksCreated[]  = $abbr; }
        if ($state === 'existing' && !in_array($abbr, $booksExisting, true)) { $booksExisting[] = $abbr; }

        $number = (int)($song['songNumber'] ?? 0);
        if ($number <= 0) {
            $number = _bulkImport_nextSongNumberFor($db, $abbr);
        }

        /* PPT verses arrive as a flat line list; create one verse component the
           curator can re-split in the editor (the normal workflow for imports). */
        $parsed = [
            'title'      => (string)($song['title'] ?? '(untitled)'),
            'components'  => [['type' => 'verse', 'number' => 1, 'lines' => array_values((array)($song['lines'] ?? []))]],
        ];
        $assembled = _bulkImport_assembleSong($parsed, $abbr, $bookName, $number);
        [$action, $saveErr] = _bulkImport_saveSong($db, $assembled);
        if ($action === 'create')       { $created++; }
        elseif ($action === 'skipped')  { $skipped++; }
        else {
            $failed++;
            $errors[] = ['entry' => ($filenameHint ?? 'pptx') . ': ' . ($assembled['id'] ?? '?'), 'error' => 'save failed: ' . $saveErr];
        }
    }

    foreach (array_unique($booksCreated) as $a) {
        /* #1694 D1 — visible-count recompute (see the multi-line siblings). */
        $cnt = $db->prepare('UPDATE tblSongbooks SET SongCount = (SELECT COUNT(*) FROM tblSongs WHERE SongbookAbbr = ? AND ' . songVisibleSql($db, '') . ') WHERE Abbreviation = ?');
        $cnt->bind_param('ss', $a, $a);
        $cnt->execute();
        $cnt->close();
    }

    return [
        'ok'                     => true,
        'songbooks_created'      => $booksCreated,
        'songbooks_existing'     => $booksExisting,
        'songs_created'          => $created,
        'songs_skipped_existing' => $skipped,
        'songs_failed'           => $failed,
        'parsed_by_format'       => ['pptx' => $created + $skipped],
        'errors'                 => $errors,
        'warnings'               => $result['warnings'] ?? [],
    ];
}

/**
 * Resolve a PPT deck's referenced songbook NAME to a catalogue songbook.
 * An exact (case-insensitive) name match reuses the existing book (so e.g.
 * "Mission Praise" maps onto the existing MP book and its songs dedup); an
 * unmatched name falls back to a generic "PowerPoint Import" book (PPTX).
 *
 * @return array{0:string,1:string,2:string} [abbr, bookName, state(created|existing)]
 */
function _bulkImport_resolvePptxSongbook(\mysqli $db, string $name): array
{
    $name = trim($name);
    if ($name !== '') {
        $stmt = $db->prepare('SELECT Abbreviation, Name FROM tblSongbooks WHERE LOWER(Name) = LOWER(?) LIMIT 1');
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            return [(string)$row['Abbreviation'], (string)$row['Name'], 'existing'];
        }
    }
    $state = _bulkImport_upsertSongbook($db, 'PPTX', 'PowerPoint Import', null);
    return ['PPTX', 'PowerPoint Import', $state];
}

/* ===========================================================================
 *  iHymns interchange JSON import (#1633)  —  .json
 * ---------------------------------------------------------------------------
 * The owner asked for a JSON importer that writes STRAIGHT TO THE DATABASE
 * "in the same way as the ZIP importing" — i.e. additive / merge, never a
 * truncate. This is deliberately NOT a revival of the retired
 * `migrate-json.php` (#1614), which TRUNCATEd five tables before loading;
 * everything below goes through the SAME `_bulkImport_upsertSongbook()` +
 * `_bulkImport_saveSong()` pair every other importer uses, inheriting their
 * INSERT-only "existing rows are sacred" contract for free (rule #25: lyrics
 * reach the DB only via `lyricLinesWriteComponents()`, which `saveSong` calls).
 *
 * FORMAT: the iHymns song interchange shape formally specified by
 * `tests/fixtures/songs.schema.json` — the same document the editor's
 * `songbook_export` emits, so an export from one install imports into another.
 * Top level is `{ meta, songbooks[], songs[] }`.
 *
 * WHY IT LIVES HERE AND NOT BEHIND A NEW ENDPOINT: `import_file` in
 * `manage/editor/api2.php` already owns "one uploaded file → parse → shared
 * saver", with format auto-detection and the summary shape the progress UI
 * reads. A parallel endpoint would have forked all three (CLAUDE.md modularity
 * rule). It is one more `_bulkImport_process*` alongside VideoPsalm/OpenLP/…
 *
 * ⚠️ THE `.json` COLLISION: the `.json` EXTENSION WAS ALREADY CLAIMED by
 * VideoPsalm in `import_file`'s auto-detect map, so extension alone cannot
 * route these two apart. `_bulkImport_looksLikeIHymnsJson()` below is the
 * disambiguator, and it is deliberately CONSERVATIVE — an ambiguous file falls
 * through to VideoPsalm, because breaking a working VideoPsalm import is far
 * worse than making an operator pick "iHymns interchange" from the dropdown.
 * This mirrors `_bulkImport_looksLikeOpenLyrics()`, which already content-sniffs
 * OpenLyrics vs OpenSong inside the shared `.xml` extension.
 * =========================================================================== */

/**
 * Component types the interchange schema enumerates, as a set for O(1) lookup.
 *
 * ELI5: the only section labels a song is allowed to use.
 *
 * Detail — mirrors the `component.type` enum in `tests/fixtures/songs.schema.json`
 * verbatim. `tblSongComponents.Type` is a `VARCHAR(20)` (not an ENUM — rule #20),
 * so the DB would happily store any string; validating here instead means a
 * typo'd type is a loud parse error naming the song rather than a silently
 * unrenderable component nobody notices until a service. 'refrain' is kept
 * distinct from 'chorus' because both are real stored values (they render
 * identically — `.lyric-chorus,.lyric-refrain { font-style: italic }`, #1337).
 */
const _BULK_IMPORT_IHYMNS_COMPONENT_TYPES = [
    'verse' => true, 'chorus' => true, 'refrain' => true, 'bridge' => true,
    'pre-chorus' => true, 'tag' => true, 'coda' => true, 'intro' => true,
    'outro' => true, 'interlude' => true, 'vamp' => true, 'ad-lib' => true,
];

/**
 * Cheap content sniff: is this JSON body an iHymns interchange document? (#1633)
 *
 * ELI5: peek at the text for the three labels only our own format uses, and
 * bail out the moment we see VideoPsalm's tell-tale label instead.
 *
 * Detail — this runs on the RAW STRING and never calls `json_decode()`, so it
 * costs no memory beyond the body the caller already holds (PHP's `strpos` is
 * memchr-backed; scanning 20 MiB is microseconds). That matters because the
 * sniff happens BEFORE the size cap has a parser to enforce it — a decode here
 * would be the very memory blow-up `_BULK_IMPORT_IHYMNS_MAX_BYTES` exists to
 * prevent, and we would then decode a second time in the parser.
 *
 * The test is deliberately asymmetric, i.e. biased AGAINST claiming the file:
 *
 *   - ALL THREE of the iHymns top-level keys (`"meta"`, `"songbooks"`, `"songs"`)
 *     must appear as JSON keys — the regex requires the quotes and the colon, so
 *     the word "songs" occurring inside a lyric line cannot trigger it.
 *   - AND VideoPsalm's own top-level `"Songs"` key must be ABSENT. JSON keys are
 *     case-sensitive and VideoPsalm capitalises (`{"Text": …, "Songs": […]}`),
 *     so this single check makes a false positive on a VideoPsalm export
 *     structurally impossible — which is the regression that would matter.
 *
 * The whole body is scanned, not a head slice: JSON object keys have no
 * guaranteed order, so a document that happens to put `songs` first would push
 * `meta` and `songbooks` past any fixed-size window.
 *
 * A `true` here is a ROUTING hint, not a validation verdict —
 * `_bulkImport_parseIHymnsJson()` still does the authoritative structural check
 * and reports a real error if the shape is wrong.
 */
function _bulkImport_looksLikeIHymnsJson(string $body): bool
{
    /* VideoPsalm's discriminator wins outright — never steal one of its files. */
    if (preg_match('/"Songs"\s*:/', $body) === 1) {
        return false;
    }
    return preg_match('/"meta"\s*:/', $body) === 1
        && preg_match('/"songbooks"\s*:/', $body) === 1
        && preg_match('/"songs"\s*:/', $body) === 1;
}

/**
 * Parse an iHymns interchange JSON document into the shared importer shapes (#1633).
 *
 * ELI5: read the file, check every field the format says must be there, and hand
 * back plain lists of songbooks and songs ready to save.
 *
 * Detail — PURE (no DB, no I/O), which is what makes it unit-testable without
 * MySQL (`tests/php/test-ihymns-json-import.php`). Returns the same
 * `[$songbooks, $songs, $err]` triple as `_bulkImport_parseVideoPsalmSongbook()`
 * so the process wrapper below reads like its siblings.
 *
 * VALIDATION IS ALL-OR-NOTHING AND UP FRONT — a deliberate divergence from the
 * VideoPsalm parser, which collects per-song errors and imports the survivors.
 * #1633 asks for "a structural error should say what is wrong, not produce a
 * partial import", and that is the right call for THIS format specifically: an
 * interchange document is machine-generated, so a song missing a required field
 * means the FILE is broken, not that one hymn is unusual. Because every check
 * runs before the caller opens a transaction, the failure is genuinely atomic —
 * nothing is half-written. Errors name the offending index AND song id so a
 * 5,000-song file is still debuggable.
 *
 * Unknown/extra properties are IGNORED rather than rejected (forward
 * compatibility): a newer exporter may add fields this build has never heard of,
 * and an old importer refusing a superset would make every schema addition a
 * breaking change. Note the schema itself says `additionalProperties: false`;
 * we are deliberately more lenient than the schema on INPUT, which is the
 * robustness principle applied in the direction that cannot lose data.
 *
 * @param string      $body          Raw JSON document (UTF-8, BOM tolerated).
 * @param string|null $filenameHint  Original upload name — used only in messages.
 * @return array{0: list<array{abbrev:string,name:string,language:?string}>|null,
 *               1: list<array<string,mixed>>|null,
 *               2: string|null}     [$songbooks, $songs, $error]
 */
function _bulkImport_parseIHymnsJson(string $body, ?string $filenameHint = null): array
{
    $fail = static fn(string $msg): array => [null, null, $msg];

    /* (0) SIZE GATE — must precede json_decode(), which is the allocation.
           See _BULK_IMPORT_IHYMNS_MAX_BYTES for the measurement behind 8 MiB. */
    $bytes = strlen($body);
    if ($bytes > _BULK_IMPORT_IHYMNS_MAX_BYTES) {
        return $fail(sprintf(
            'File is too large for the JSON importer (%.1f MB; limit %.0f MB). ' .
            'json_decode() must hold the whole document in memory at once, so this ' .
            'limit is a hard memory bound, not a policy. Split the file by songbook, ' .
            'or use the ZIP import which streams entry by entry.',
            $bytes / 1048576,
            _BULK_IMPORT_IHYMNS_MAX_BYTES / 1048576
        ));
    }

    /* Strip a UTF-8 BOM — Windows editors add one and json_decode() rejects it.
       Same guard as the VideoPsalm parser. */
    $body = (string)preg_replace('/^\xEF\xBB\xBF/', '', $body);

    $data = json_decode($body, true);
    if (!is_array($data)) {
        return $fail('invalid JSON: ' . json_last_error_msg());
    }

    /* (1) TOP LEVEL — `meta`, `songbooks`, `songs` are all required. Naming every
           missing key at once beats making the operator re-upload three times. */
    $missing = [];
    foreach (['meta', 'songbooks', 'songs'] as $k) {
        if (!array_key_exists($k, $data)) { $missing[] = $k; }
    }
    if ($missing) {
        return $fail('not an iHymns interchange document — missing required top-level key(s): '
            . implode(', ', $missing));
    }
    if (!is_array($data['meta']))      { return $fail('"meta" must be an object'); }
    if (!is_array($data['songbooks'])) { return $fail('"songbooks" must be an array'); }
    if (!is_array($data['songs']))     { return $fail('"songs" must be an array'); }

    /* (2) META — required by the schema. Its VALUES are advisory (totalSongs is a
           generator convenience, not a contract we enforce against the real count,
           which would reject a legitimately hand-trimmed file), but their PRESENCE
           is what distinguishes a real export from a hand-rolled fragment. */
    foreach (['generatedAt', 'generatorVersion', 'totalSongs', 'totalSongbooks'] as $k) {
        if (!array_key_exists($k, $data['meta'])) {
            return $fail('"meta" is missing required key "' . $k . '"');
        }
    }

    /* (3) SONGBOOKS. `id` IS the SongId prefix (rule #27: tblSongbooks.Abbreviation,
           alphanumeric and at most 10 chars — never loosened, it keys every SongId
           and every URL). The schema's own pattern is narrower (`^[A-Z][A-Za-z]*$`);
           we validate against the DB constraint instead so a legitimate existing
           book with a digit in its abbreviation is not refused on import. */
    $songbooks   = [];
    $declaredIds = [];      // id => name, for the cross-check in (4)
    foreach (array_values($data['songbooks']) as $i => $sb) {
        $tag = 'songbooks[' . $i . ']';
        if (!is_array($sb)) { return $fail($tag . ' is not an object'); }
        foreach (['id', 'name', 'songCount'] as $k) {
            if (!array_key_exists($k, $sb)) { return $fail($tag . ' is missing required key "' . $k . '"'); }
        }
        $abbr = trim((string)$sb['id']);
        $name = trim((string)$sb['name']);
        if (preg_match('/^[A-Za-z0-9]{1,10}$/', $abbr) !== 1) {
            return $fail($tag . ' has an invalid songbook id "' . $abbr
                . '" — must be 1-10 letters/digits (it becomes the SongId prefix)');
        }
        if ($name === '') { return $fail($tag . ' ("' . $abbr . '") has an empty name'); }
        if (isset($declaredIds[$abbr])) { return $fail($tag . ' repeats songbook id "' . $abbr . '"'); }
        $declaredIds[$abbr] = $name;

        /* `language` is not in the interchange schema for a songbook, but
           _bulkImport_upsertSongbook() accepts one and a future exporter may add
           it — read it tolerantly, validate it, and pass null when absent. */
        $lang = isset($sb['language']) ? _ietfBcp47Validate((string)$sb['language']) : null;
        $songbooks[] = [
            'abbrev'   => $abbr,
            'name'     => $name,
            'language' => is_string($lang) ? $lang : null,
        ];
    }

    /* (4) SONGS. Mapped one at a time; each source entry is FREED as soon as its
           dict exists (see the unset() at the bottom of the loop) so we never hold
           the decoded document AND the mapped output simultaneously — that would
           double the peak the size cap in (0) was sized against. */
    $required = [
        'id', 'number', 'title', 'songbook', 'songbookName', 'language',
        'writers', 'composers', 'copyright', 'ccli', 'verified',
        'lyricsPublicDomain', 'musicPublicDomain', 'hasAudio',
        'hasSheetMusic', 'components',
    ];
    $songs   = [];
    $seenIds = [];
    $keys    = array_keys($data['songs']);
    foreach ($keys as $k) {
        $raw = $data['songs'][$k];
        $tag = 'songs[' . $k . ']';
        if (!is_array($raw)) { return $fail($tag . ' is not an object'); }

        foreach ($required as $rk) {
            if (!array_key_exists($rk, $raw)) {
                $who = isset($raw['id']) ? ' ("' . (string)$raw['id'] . '")' : '';
                return $fail($tag . $who . ' is missing required key "' . $rk . '"');
            }
        }

        $songId = trim((string)$raw['id']);
        $title  = trim((string)$raw['title']);
        $abbr   = trim((string)$raw['songbook']);

        if ($title === '') { return $fail($tag . ' ("' . $songId . '") has an empty title'); }
        /* SongId shape is `<letters/digits>-<digits>` — the exact form the PWA
           router's normalizeSongId(), the OG-image route and the song_view API
           validators parse (rule #27). A malformed id here would produce a song
           that is unreachable by URL, so it is fatal rather than repaired. */
        if (preg_match('/^([A-Za-z0-9]{1,10})-(\d+)$/', $songId, $m) !== 1) {
            return $fail($tag . ' has an invalid song id "' . $songId
                . '" — expected <SONGBOOK>-<NUMBER>, e.g. "MP-1008"');
        }
        if ($m[1] !== $abbr) {
            return $fail($tag . ' ("' . $songId . '") has id prefix "' . $m[1]
                . '" but songbook "' . $abbr . '" — they must match');
        }
        if (!isset($declaredIds[$abbr])) {
            return $fail($tag . ' ("' . $songId . '") references songbook "' . $abbr
                . '", which is not declared in "songbooks"');
        }
        if (isset($seenIds[$songId])) {
            return $fail($tag . ' repeats song id "' . $songId . '" (already seen in this file)');
        }
        $seenIds[$songId] = true;

        $number = (int)$raw['number'];
        if ($number < 1) {
            return $fail($tag . ' ("' . $songId . '") has number "' . (string)$raw['number']
                . '" — must be a positive integer');
        }

        /* Language: soft-validated. A malformed tag falls back to 'en' rather than
           killing the file, matching _bulkImport_saveSong()'s own tolerance — the
           tag is cosmetic-ish metadata, not structure, and BCP 47 has enough exotic
           legal forms that being fatal here would reject valid data. */
        $langOk   = _ietfBcp47Validate((string)$raw['language']);
        $language = is_string($langOk) ? $langOk : 'en';

        if (!is_array($raw['components']) || $raw['components'] === []) {
            return $fail($tag . ' ("' . $songId . '") has no components — at least one is required');
        }

        $components = [];
        foreach (array_values($raw['components']) as $ci => $comp) {
            $ctag = $tag . ' ("' . $songId . '") component[' . $ci . ']';
            if (!is_array($comp)) { return $fail($ctag . ' is not an object'); }
            foreach (['type', 'number', 'lines'] as $rk) {
                if (!array_key_exists($rk, $comp)) {
                    return $fail($ctag . ' is missing required key "' . $rk . '"');
                }
            }
            $ctype = strtolower(trim((string)$comp['type']));
            if (!isset(_BULK_IMPORT_IHYMNS_COMPONENT_TYPES[$ctype])) {
                return $fail($ctag . ' has unknown type "' . $ctype . '" — expected one of: '
                    . implode(', ', array_keys(_BULK_IMPORT_IHYMNS_COMPONENT_TYPES)));
            }
            if (!is_array($comp['lines']) || $comp['lines'] === []) {
                return $fail($ctag . ' has no lines — at least one is required');
            }
            $lines = [];
            foreach (array_values($comp['lines']) as $li => $line) {
                if (!is_string($line)) { return $fail($ctag . ' line[' . $li . '] is not a string'); }
                $lines[] = $line;
            }

            /* `number` is nullable in the schema (a lone chorus has none). The
               shared writer folds a non-positive number to NULL via max(0, …),
               so 0 is the canonical "unnumbered" carrier here. */
            $cnum = ($comp['number'] === null) ? 0 : (int)$comp['number'];
            if ($cnum < 0) { $cnum = 0; }

            $mapped = [
                'type'   => $ctype,
                'number' => $cnum,
                'lines'  => $lines,
            ];

            /* Per-line language overrides (#1235 P3). The interchange calls the
               parallel array `lineLanguages`; the shared writer's component key is
               `languages`, and lineEnrichmentBuildLanguagesJson() validates + pads
               each entry, so a short or partly-invalid array is safe to hand over
               verbatim. Absent → omitted, so every line inherits the song language. */
            if (isset($comp['lineLanguages']) && is_array($comp['lineLanguages'])) {
                $mapped['languages'] = array_values($comp['lineLanguages']);
            }

            /* NB `lineIds` is deliberately DROPPED. Those are tblLyricLines.Id
               values from the EXPORTING install's database; they are meaningless
               here and honouring them would collide with this install's own PKs.
               The importer inserts fresh lines and lets MySQL mint the ids. */

            $components[] = $mapped;
        }

        /* Song dict in the exact shape _bulkImport_saveSong() consumes — the same
           key set _bulkImport_assembleSong() produces for OpenLyrics/PP6, so this
           format needs no special case anywhere downstream.
           ⚠️ KNOWN LIMITATION (not introduced here): saveSong currently hardcodes
           copyright / ccli / verified / the public-domain + media flags to empty
           on INSERT and does not write writers/composers at all, so the richer
           metadata carried below is parsed, validated and then dropped by the
           shared saver. Every other importer already loses it the same way; fixing
           it belongs in saveSong (one change, all formats), not in a fork here. */
        $songDict = [
            'id'                 => $songId,
            'title'              => $title,
            'number'             => $number,
            'songbook'           => $abbr,
            'songbookName'       => trim((string)$raw['songbookName']) !== ''
                                        ? trim((string)$raw['songbookName'])
                                        : $declaredIds[$abbr],
            'language'           => $language,
            'ccli'               => trim((string)$raw['ccli']),
            'iswc'               => '',
            'tuneName'           => '',
            'copyright'          => trim((string)$raw['copyright']),
            'verified'           => !empty($raw['verified']) ? 1 : 0,
            'lyricsPublicDomain' => !empty($raw['lyricsPublicDomain']) ? 1 : 0,
            'musicPublicDomain'  => !empty($raw['musicPublicDomain']) ? 1 : 0,
            'hasAudio'           => !empty($raw['hasAudio']) ? 1 : 0,
            'hasSheetMusic'      => !empty($raw['hasSheetMusic']) ? 1 : 0,
            'writers'            => array_values(array_filter(
                                        array_map('strval', (array)$raw['writers']),
                                        static fn(string $w): bool => trim($w) !== ''
                                    )),
            'composers'          => array_values(array_filter(
                                        array_map('strval', (array)$raw['composers']),
                                        static fn(string $w): bool => trim($w) !== ''
                                    )),
            'arrangers'          => [],
            'adaptors'           => [],
            'translators'        => [],
            'components'         => $components,
        ];

        /* `arrangement` is optional; saveSong sanitises it against the component
           count via _sanitiseArrangement() and stores NULL on anything malformed,
           so passing it through raw is safe. */
        if (isset($raw['arrangement']) && is_array($raw['arrangement'])) {
            $songDict['arrangement'] = $raw['arrangement'];
        }
        $songs[] = $songDict;

        /* NB `translations` (links to OTHER song records, tblSongTranslations) is
           NOT imported: the referenced song may not exist yet, or at all, in this
           database, and the shared saver has no translation-link write path.
           Tracked separately rather than half-implemented here. */

        /* Free the source entry now that its dict exists — see (4)'s preamble.
           Without this the decoded document and the mapped output coexist and the
           peak is ~2x what the size cap was measured against. */
        unset($data['songs'][$k], $raw, $songDict, $components);
    }

    unset($data);   // release the (now songless) decoded document before returning

    if ($songs === []) {
        return $fail('the document contains no songs' . ($filenameHint !== null ? ' (' . $filenameHint . ')' : ''));
    }

    return [$songbooks, $songs, null];
}

/**
 * Synchronous single-file iHymns interchange import (#1633).
 *
 * ELI5: create any songbooks the file mentions that we do not have yet, then
 * save each song, counting what was new, what was already there, and what broke.
 *
 * Detail — the process wrapper, mirroring `_bulkImport_processVideoPsalm()`
 * exactly: same summary keys (the progress UI in `manage/editor/import2.php`
 * reads them positionally by name), same INSERT-only semantics, same
 * SongCount refresh restricted to songbooks THIS run created.
 *
 * IDEMPOTENCY comes free from `_bulkImport_saveSong()`, which pre-flights
 * `SELECT 1 FROM tblSongs WHERE SongId = ?` and returns 'skipped' on a hit —
 * so re-uploading the same file is a no-op that reports every song as
 * "already in DB", and an interrupted import can simply be re-run. Combined
 * with `_bulkImport_upsertSongbook()`'s never-overwrite rule (#664), the whole
 * path is additive/merge, which is what #1633 asked for.
 *
 * @param string      $body          Raw JSON document.
 * @param string|null $filenameHint  Original upload filename (messages only).
 * @return array  The shared bulk-import summary shape.
 */
function _bulkImport_processIHymnsJson(string $body, ?string $filenameHint = null): array
{
    [$songbooks, $parsedSongs, $err] = _bulkImport_parseIHymnsJson($body, $filenameHint);
    if ($songbooks === null) {
        return [
            'ok'                     => false,
            'error'                  => $err ?: 'iHymns JSON parse failed',
            'songbooks_created'      => [],
            'songbooks_existing'     => [],
            'songs_created'          => 0,
            'songs_skipped_existing' => 0,
            'songs_failed'           => 0,
            'parsed_by_format'       => ['ihymns' => 0],
            'errors'                 => [],
        ];
    }

    $db = getDbMysqli();

    /* Songbooks first — a song's FK-ish SongbookAbbr must resolve, and the parser
       has already guaranteed every song references a declared book. */
    $songbooksCreated  = [];
    $songbooksExisting = [];
    foreach ($songbooks as $sb) {
        $abbr  = (string)$sb['abbrev'];
        $state = _bulkImport_upsertSongbook($db, $abbr, (string)$sb['name'], $sb['language'] ?? null);
        if ($state === 'created') { $songbooksCreated[] = $abbr; }
        else                      { $songbooksExisting[] = $abbr; }
    }

    $label   = $filenameHint ?? 'ihymns';
    $errors  = [];
    $created = 0;
    $skipped = 0;
    $failed  = 0;
    foreach ($parsedSongs as $song) {
        [$action, $saveErr] = _bulkImport_saveSong($db, $song);
        if ($action === 'create') {
            $created++;
        } elseif ($action === 'skipped') {
            $skipped++;
        } else {
            $errors[] = [
                'entry' => $label . ': ' . ($song['id'] ?? '?'),
                'error' => 'save failed: ' . $saveErr,
            ];
            $failed++;
        }
    }

    /* Refresh SongCount only for songbooks created in THIS run — the same
       no-overwrite contract as the ZIP + VideoPsalm paths (#664): an existing
       book's count may have been curated by hand and is not ours to restate. */
    foreach ($songbooksCreated as $abbr) {
        $cnt = $db->prepare(
            /* #1694 D1 — SongCount counts VISIBLE songs (agrees with the
               predicate-aware triggers; trigger-denied hosts rely on this). */
            'UPDATE tblSongbooks
                SET SongCount = (SELECT COUNT(*) FROM tblSongs WHERE SongbookAbbr = ? AND ' . songVisibleSql($db, '') . ')
              WHERE Abbreviation = ?'
        );
        $cnt->bind_param('ss', $abbr, $abbr);
        $cnt->execute();
        $cnt->close();
    }

    return [
        'ok'                     => true,
        'songbooks_created'      => $songbooksCreated,
        'songbooks_existing'     => $songbooksExisting,
        'songs_created'          => $created,
        'songs_skipped_existing' => $skipped,
        'songs_failed'           => $failed,
        'parsed_by_format'       => ['ihymns' => $created + $skipped],
        'errors'                 => $errors,
    ];
}

