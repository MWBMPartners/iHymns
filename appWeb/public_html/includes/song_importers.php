<?php

declare(strict_types=1);

/* #1694 — songVisibleSql() for the SongCount recomputes below (degrades to
   '1=1' on an un-migrated install, so every funnel stays byte-identical). */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'song_soft_delete.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ilyrics_id.php';   /* #1860 go-live — ilidStampNewRow() for the song + songbook create funnels below */

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
 * @param string $body       File contents (UTF-8, UTF-16, or UTF-32 — see
 *                           ihymnsTextToUtf8() below; any other encoding is
 *                           rejected with a clear error rather than mangled)
 * @param string $abbrev     Songbook abbreviation parsed from the filename
 * @param string $songbook   Songbook display name parsed from the folder
 * @param int    $number     Song number parsed from the filename
 * @return array{0: ?array, 1: ?string}  [songObject, errorReason]
 */
function _bulkImport_parseTxt(string $body, string $abbrev, string $songbook, int $number): array
{
    /* #1908 Commit 6 — ELI5: a Windows "Save As... Unicode" .txt file is
       secretly UTF-16, not UTF-8; read it as UTF-8 and every character
       comes out mangled with no error at all (the READ succeeds — it just
       decodes to garbage). Detect + convert BEFORE anything else touches
       the bytes. See includes/text_encoding.php for the full detection
       ladder this delegates to (rule #22 — the ONE shared sniffer). */
    require_once __DIR__ . '/text_encoding.php';
    $converted = ihymnsTextToUtf8($body);
    if ($converted === null) {
        return [null, 'file is not UTF-8 (or UTF-16) text — re-save it as UTF-8'];
    }
    $body = $converted;

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
        'altTitles'          => [],
        'components'         => $components,
    ], null];
}

/**
 * Extract the licensing / identifier / public-domain fields from a parsed song
 * dict, with the pre-#1673 hardcoded defaults as fallbacks (#1673 / #1896).
 *
 * ELI5: the parsers already read a song's copyright, CCLI number, ISWC and
 * public-domain flags — but the shared saver used to throw them all away and
 * write blanks. This reads them back out so an imported catalogue keeps its
 * rights metadata.
 *
 * THE BUG (#1673 / #1896): `_bulkImport_saveSong()` — the ONE saver every
 * importer (TXT / OpenSong / VideoPsalm / ChordPro / FreeShow / OpenLyrics /
 * CSV / iHymns-JSON) funnels through — hardcoded `$copyright=''`, `$ccli=''`,
 * `$iswc=null`, `$verified=0`, `$lyricsPD=0`, `$musicPD=0`, discarding the
 * values the parsers had already collected into the dict. Two layers each doing
 * their job, the data falling through the gap between them. It matters because
 * Copyright + CCLI are the LICENSING metadata: the CCLI usage report (#317)
 * joins on `tblSongs.Ccli` and so undercounts imported songs; content gating
 * (#1590) reads the PD flags; and post-#1860 the bulk path's Work auto-link is a
 * near-permanent no-op with no CCLI/ISWC to link on (#1896).
 *
 * Extracted as a PURE helper (not inlined) so the field mapping is behaviourally
 * testable without a database — the saver itself does multi-statement DB work.
 * Every read keeps its fallback so a parser that omits a key (OpenLyrics builds
 * its own dict, not the template above) still saves, never throws on a missing
 * key (the #1673 ⚠️). CREDITS (writers/composers) are deliberately OUT of scope
 * here — they resolve into the separate musicians/credits tables (#832 territory)
 * with their own rules, and #1673 says split them once the columns are done.
 *
 * @param array $song A parsed song dict (any importer's output shape).
 * @return array{copyright:string, ccli:string, iswc:?string, verified:int,
 *               lyricsPublicDomain:int, musicPublicDomain:int}
 * @see https://www.php.net/manual/en/language.types.array.php
 */
function _bulkImportRightsFromSong(array $song): array
{
    $iswcRaw = trim((string)($song['iswc'] ?? ''));
    return [
        'copyright'          => trim((string)($song['copyright'] ?? '')),
        'ccli'               => trim((string)($song['ccli'] ?? '')),
        /* '' means "no ISWC": the column is nullable and the #1860 identifier /
           auto-link read path expects NULL, not an empty string. */
        'iswc'               => ($iswcRaw !== '') ? $iswcRaw : null,
        /* Truthy source value → 1, anything else (absent / '' / 0 / false) → 0,
           so a parser that never sets the flag keeps the safe default. */
        'verified'           => !empty($song['verified']) ? 1 : 0,
        'lyricsPublicDomain' => !empty($song['lyricsPublicDomain']) ? 1 : 0,
        'musicPublicDomain'  => !empty($song['musicPublicDomain']) ? 1 : 0,
    ];
}

/**
 * Persist one parsed song — INSERT-ONLY. If a row with the same
 * SongId already exists, the existing row is left untouched and the
 * call returns 'skipped'. This is the explicit user requirement for
 * bulk import (#664): never overwrite curator-edited data with
 * scraped source data.
 *
 * DRY RUN (#1674): when _bulkImport_dryRun() is on, this function still runs
 * BOTH real pre-flight decisions below (the existence check and, if opted
 * in, the title-dedupe check) and returns exactly what a real run would
 * return ('create'|'skipped') — but early-returns immediately before
 * $db->begin_transaction(), so nothing after that point (the INSERT, the
 * lyric-line write, the credit tables + musicianPromote(), ilidStampNewRow,
 * the revision row, pdRecomputeForSong, logActivity) ever executes. This
 * function opens its OWN per-song transaction and MySQL has no nested
 * transactions, so "wrap the whole import and roll back" cannot work here —
 * suppressing every write BY POSITION from one early-return, placed after
 * both decisions and before the transaction, is the seam that keeps a
 * single code path for both real and preview runs. The exact placement is
 * load-bearing; see the comment at the early-return itself. In-file
 * duplicates within one dry-run request are tracked via
 * _bulkImport_dryRunSeen() so a second occurrence of the same SongId
 * reports 'skipped' — mirroring how a real run's second INSERT of that
 * SongId would be skipped by the existence check above, against the FIRST
 * insert's now-committed row.
 *
 * HONEST LIMITATION: dry-run's `songs_failed` count (aggregated by the
 * caller) reflects only parse/mapping failures caught before this function
 * is even invoked — DB-level failures (a duplicate-key race, an FK
 * surprise) are unreproducible without actually writing, so a dry run can
 * under-report failures a real import would hit.
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
    /* #1673 / #1896 — read the licensing / identifier / public-domain fields the
       parsers already collected, instead of the blanks this used to hardcode. */
    $rights       = _bulkImportRightsFromSong($song);
    $copyright    = $rights['copyright'];
    $tuneName     = null;
    $ccli         = $rights['ccli'];
    $iswc         = $rights['iswc'];
    $verified     = $rights['verified'];
    $lyricsPD     = $rights['lyricsPublicDomain'];
    $musicPD      = $rights['musicPublicDomain'];
    /* HasAudio / HasSheetMusic stay 0 at import — they are DERIVED from attached
       tblSongMedia rows (rule #44), never a hand-set flag on the song row. */
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
        /* @disabled-visible: importer / batch system path — operates over all
           songbooks regardless of public disabled state */
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

        /* #1674 — dry-run early return, WEDGED EXACTLY HERE by design (the
           single highest-risk line of this feature — see the doc-block
           above and the wave plan's adversarial-review section):
           - AFTER both pre-flight decisions above (the existence check and
             the title-dedupe check), so a dry-run preview makes the
             IDENTICAL created/skipped call a real run would make — one
             line earlier and a preview would lie about what would happen;
           - BEFORE $db->begin_transaction() below, so a dry run never opens
             a transaction — one line later and every dry-run song would
             leak an open, uncommitted transaction.
           _bulkImport_dryRunSeen() stands in for the existence check across
           an in-file duplicate: a real run's second INSERT of the same
           SongId is skipped by that check against the FIRST insert's
           now-committed row, but a dry run never commits, so this per-run
           static set is what makes the second occurrence report 'skipped'
           too instead of a phantom second 'create'. */
        if (_bulkImport_dryRun()) {
            return _bulkImport_dryRunSeen($songId) ? ['skipped', null] : ['create', null];
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
        /* #1860 go-live — mint this song's permanent IL-id (ILS…). */
        ilidStampNewRow($db, 'song', $songId, 'SongId');

        /* #1741 P5c — TuneName<->TuneId lockstep, the bulk-import-side mirror
         * of manage/works.php's :307-313 Work-side block and
         * save_song_core.php's own gated UPDATE just above this file's
         * sibling save path. The INSERT above just wrote TuneName (via
         * $tuneName, extracted with the rest of this function's locals); a
         * SEPARATE small gated UPDATE resolves + writes the registry id in
         * the SAME transaction so a bulk import can never strand TuneId the
         * way the un-mirrored write used to. This function processes ONE
         * song per call (the loop lives in the caller), so — unlike a
         * batch/shared-statement writer — there is no per-row complication
         * here; the rider named as droppable in the P5 build spec (§3.4
         * item 2) turned out straightforward to wire, so it is wired rather
         * than dropped-with-a-follow-up-issue.
         *
         * ELI5: importing a song wrote its tune's NAME — this makes sure the
         * tune's REGISTRY LINK gets written too, so a bulk-imported song's
         * tune page (/tune/<slug>) is linked correctly from day one, not
         * just resolved later via the free-text fallback ladder.
         *
         * Unguarded inside the transaction (no local try/catch): a genuine
         * DB fault here must roll the whole import of this song back, same
         * posture as every other write in this function; tuneFindOrCreate-
         * ByName() itself already degrades to null when tblTunes is absent. */
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'places.php';
        if (placeColumnExists($db, 'tblSongs', 'TuneId')) {
            require_once __DIR__ . DIRECTORY_SEPARATOR . 'tune_helpers.php';
            $tuneIdVal = $tuneName === null ? null : tuneFindOrCreateByName($db, $tuneName);
            $tuneStmt = $db->prepare('UPDATE tblSongs SET TuneId = ? WHERE SongId = ?');
            $tuneStmt->bind_param('is', $tuneIdVal, $songId);
            $tuneStmt->execute();
            $tuneStmt->close();
        }

        /* #1039 Part A — maintain the diacritic-folded search mirror
           (LyricsTextFolded) + repair NormalizedTitle for the just-imported
           song, in this import's own transaction. The INSERT above wrote
           $lyricsText and $title; the shared helper folds both with the exact
           fold the query side uses. Dormant + fail-open no-op on an
           un-migrated install. */
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'search_fold.php';
        searchFoldSyncSong($db, $songId, $title, $lyricsText);

        /* #1860 go-live — Works auto-link, same shared core the editor save
           path uses (rule #22). Inside this import's own transaction
           (ownTransaction=false); fail-safe — a link failure never aborts
           the import (transaction-fatal rethrows so the surrounding catch
           rolls back honestly; everything else is logged and swallowed).
           This function is INSERT-ONLY (an existing SongId returns
           'skipped' above, before the transaction even opens), so this
           only ever fires for a brand-new song. */
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'work_admin.php';
        workAutolinkSafe($db, $songId, (string)$ccli, (string)$iswc, false);

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

        /* #1736 — write the parsed credits. Until now saveSong wrote NO credit
           tables at all, so every importer (OpenSong / OpenLyrics / ChordPro /
           the #1633 interchange format) parsed writers/composers correctly and
           then dropped them: a bulk-imported song arrived with no credits and no
           tblMusicians registry rows.

           INSERT-ONLY: this function has already established (the pre-flight
           existence + title-dedupe checks above) that this is a BRAND-NEW song —
           an existing SongId / normalised-title match returns 'skipped' before the
           transaction opens. So the "re-import: replace vs merge vs leave" design
           question resolves to LEAVE — a re-import never reaches here — and this
           block needs no DELETE (unlike save_song_core.php's whole-song re-save).

           Mirrors save_song_core.php's credit write (rule #22): the SAME
           creditEntryNormalise() decompose + musicianPromote() registry upsert,
           so a name-string from OpenSong's <author> lands as First/Surname/Suffix
           in the registry exactly as a curator-typed credit does, and the #960
           side-effect guard (which demands every credit-writing file also call
           musicianPromote) is satisfied here. Same 5 core role tables the editor
           DELETE/INSERT loop uses (tblSongArtists is #587-migration-gated and no
           importer parses artists, so it is deliberately omitted here). All inside
           the import transaction — commits / rolls back atomically with the song. */
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'musician_helpers.php';
        $creditInserts = [
            'writers'     => 'INSERT INTO tblSongWriters     (SongId, Name) VALUES (?, ?)',
            'composers'   => 'INSERT INTO tblSongComposers   (SongId, Name) VALUES (?, ?)',
            'arrangers'   => 'INSERT INTO tblSongArrangers   (SongId, Name) VALUES (?, ?)',
            'adaptors'    => 'INSERT INTO tblSongAdaptors    (SongId, Name) VALUES (?, ?)',
            'translators' => 'INSERT INTO tblSongTranslators (SongId, Name) VALUES (?, ?)',
        ];
        $regParts = []; /* composed Name => richest {first,surname,suffix} seen across roles */
        foreach ($creditInserts as $key => $sql) {
            $entries = $song[$key] ?? [];
            if (!is_array($entries) || !$entries) { continue; }
            $stmt = $db->prepare($sql);
            $seenCredit = [];   /* per-role, case-insensitive dedup (#1178 posture) */
            foreach ($entries as $raw) {
                $entry = creditEntryNormalise($raw);
                if ($entry === null) { continue; }
                $dedupKey = function_exists('mb_strtolower') ? mb_strtolower($entry['name']) : strtolower($entry['name']);
                if (isset($seenCredit[$dedupKey])) { continue; }
                $seenCredit[$dedupKey] = true;
                $stmt->bind_param('ss', $songId, $entry['name']);
                $stmt->execute();
                /* Keep the richest parts for this name across all roles (a name
                   typed one way in Writers and another in Composers picks the more
                   complete decomposition for the single registry upsert). */
                if (!isset($regParts[$entry['name']])
                    || ($regParts[$entry['name']]['first'] === '' && $regParts[$entry['name']]['surname'] === '' && $regParts[$entry['name']]['suffix'] === '')
                ) {
                    $regParts[$entry['name']] = [
                        'first'   => $entry['first'],
                        'surname' => $entry['surname'],
                        'suffix'  => $entry['suffix'],
                    ];
                }
            }
            $stmt->close();
        }
        foreach ($regParts as $regName => $p) {
            musicianPromote($db, $regName, $p);
        }

        /* #1669 — alternative titles (e.g. an OpenLyrics file with several
           <title> elements). Written through the ONE shared song_alt_titles
           core, INSIDE this song's transaction so they are atomic with the
           INSERT. Gated on the table existing (an un-migrated install imports
           byte-identically) and on the parser actually supplying any (an
           absent key folds to [] — every other importer imports unchanged).
           A per-entry InvalidArgumentException (an over-long / malformed alt
           title) is caught and skipped so one bad alt never aborts an
           otherwise-good song; a genuine mysqli error is NOT caught here and
           still rolls the whole song back. Entries equal to the main title
           are dropped (songAltTitleIsRedundant), and INSERT IGNORE +
           uq_song_title absorb any remaining dupe.
           #1912 — `note` is now threaded through too (songAltTitleAdd()'s 5th
           param): only the iHymns-interchange parser supplies one today (the
           OpenLyrics <title> parser above never sets 'note', so this stays a
           no-op — still null — for every other importer, byte-identical to
           before #1912). */
        $altTitles = $song['altTitles'] ?? [];
        if (is_array($altTitles) && $altTitles !== []) {
            require_once __DIR__ . DIRECTORY_SEPARATOR . 'song_alt_titles.php';
            if (songAltTitlesTableExists($db)) {
                foreach ($altTitles as $alt) {
                    if (!is_array($alt)) { continue; }
                    $altTitle = trim((string)($alt['title'] ?? ''));
                    if ($altTitle === '' || songAltTitleIsRedundant($altTitle, $title)) { continue; }
                    $altLang = ($alt['language'] ?? '') !== '' ? (string)$alt['language'] : null;
                    $altNote = ($alt['note'] ?? '') !== '' ? (string)$alt['note'] : null;
                    try {
                        songAltTitleAdd($db, $songId, $altTitle, $altLang, $altNote);
                    } catch (\InvalidArgumentException $_e) { /* skip a malformed alt, keep the import */ }
                }
            }
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

        /* #1862 — the credit inserts above just established this song's
           contributor set; recompute the PD-suggestion denorm post-commit,
           own failure boundary (pdRecomputeForSong() never throws — see
           pd_suggest.php's header). Tree-derived wiring guard:
           tests/php/test-editor2-metadata-1862.php scans every
           `INSERT INTO tblSongWriters` (etc.) and asserts this reference. */
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'pd_suggest.php';
        pdRecomputeForSong($db, $songId);

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
 * skip / merge / replace. No DB writes; called from the title-dedupe
 * pre-flight inside _bulkImport_saveSong() above, which — since #1674 —
 * also runs UNMODIFIED under dry-run mode (_bulkImport_dryRun()): a preview
 * consults this exact matcher, so it reports the identical skip decision a
 * real import would make.
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
    /* @disabled-visible: importer / batch system path — operates over all
       songbooks regardless of public disabled state */
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
 * Dry-run mode flag for the current import request (#1674) — same
 * request-scoped static-flag shape as _bulkImport_dedupeMode() above. When
 * ON, _bulkImport_saveSong() and _bulkImport_upsertSongbook() report the
 * exact created/skipped/existing decision a real run would make WITHOUT
 * writing anything; see _bulkImport_saveSong()'s doc-block for the full
 * contract and its one documented limitation (DB-level failures are
 * unreproducible without writing).
 *
 * Setting the flag — in EITHER direction, `true` or `false` — also resets
 * the per-run "already seen this SongId" set that _bulkImport_saveSong()
 * consults for in-file-duplicate fidelity (via _bulkImport_dryRunSeen()
 * below), so a fresh call to _bulkImport_dryRun($x) always starts a fresh
 * run: a stray earlier dry run in the SAME PHP process/request could never
 * leak its seen-set into this one.
 *
 * ELI5: one on/off switch, set once per import request before the
 * parse/save loop runs, that every song in that request checks.
 */
function _bulkImport_dryRun(?bool $set = null): bool
{
    static $dryRun = false;
    if ($set !== null) {
        $dryRun = $set;
        _bulkImport_dryRunSeen(null, true);
    }
    return $dryRun;
}

/**
 * Per-run "already seen this SongId in this dry run" set (#1674) — the
 * dry-run stand-in for the existence check a real run's SECOND insert of
 * the same SongId would hit against the FIRST insert's committed row. A
 * dry run never commits, so nothing is there for that check to see; this
 * static set plays the same role within one request.
 *
 *   _bulkImport_dryRunSeen($songId)     → true if $songId was already
 *                                          recorded this run (record it on
 *                                          the FIRST call for a given id,
 *                                          which returns false); false on
 *                                          a genuinely new id.
 *   _bulkImport_dryRunSeen(null, true)  → reset the set. Called only from
 *                                          _bulkImport_dryRun() whenever the
 *                                          flag is (re)set — the ONE
 *                                          mechanism this file uses to keep
 *                                          the flag and its seen-set in
 *                                          lockstep (rule #35).
 */
function _bulkImport_dryRunSeen(?string $songId, bool $reset = false): bool
{
    static $seen = [];
    if ($reset) {
        $seen = [];
        return false;
    }
    if ($songId === null) {
        return false;
    }
    if (isset($seen[$songId])) {
        return true;
    }
    $seen[$songId] = true;
    return false;
}

/**
 * Read the persisted DryRun flag off a bulk-import job row (#1911).
 *
 * ELI5: look up whether THIS import job was started as a preview, by
 * reading the flag off its own database row rather than trusting whatever
 * happens to be sitting in the in-process static flag.
 *
 * Detail: called ONLY by _bulkImport_processZip() when it was handed a
 * $jobDb/$jobId pair — the async worker path (api2.php's import_zip case,
 * after fastcgi_finish_request() has already released the HTTP connection
 * the request-scoped $dryRun local variable was read from). The single-file
 * import path (#1674) never needs this: it stays fully synchronous, so its
 * caller's own _bulkImport_dryRun($dryRun) call is still authoritative for
 * the whole request.
 *
 * Column-existence-gated via try/catch rather than a separate probe query:
 * on an un-migrated install (no DryRun column) the SELECT throws under
 * MYSQLI_REPORT_STRICT and this returns false — which is always the correct
 * answer there, since import_zip's own column-existence gate
 * (ed2_bulkJobsDryRunColumnExists() in api2.php) already refuses dryRun=1
 * with a 422 before any job row can be created on such a deployment
 * (CLAUDE.md rule #33 — the honest refusal, never a silently-ignored flag).
 *
 * @see _bulkImport_dryRun()  the static flag this value feeds
 * @see appWeb/public_html/manage/editor/api2.php  import_zip case, ed2_bulkJobsDryRunColumnExists()
 * @link https://www.php.net/manual/en/mysqli-stmt.get-result.php  mysqli_stmt::get_result()
 */
function _bulkImport_jobDryRunFlag(\mysqli $db, int $jobId): bool
{
    try {
        $stmt = $db->prepare('SELECT DryRun FROM tblBulkImportJobs WHERE Id = ? LIMIT 1');
        $stmt->bind_param('i', $jobId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row !== null && (int)($row['DryRun'] ?? 0) === 1;
    } catch (\Throwable $_e) {
        /* Un-migrated install (no DryRun column) — never a dry run. */
        return false;
    }
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
 * abbreviation was already in tblSongbooks. Under _bulkImport_dryRun()
 * (#1674) a brand-new abbreviation still reports 'created' but the row is
 * never inserted — see the early-return below.
 */
function _bulkImport_upsertSongbook(\mysqli $db, string $abbr, string $name, ?string $language = null): string
{
    /* @disabled-visible: importer / batch system path — operates over all
       songbooks regardless of public disabled state */
    $sel = $db->prepare('SELECT 1 FROM tblSongbooks WHERE Abbreviation = ? LIMIT 1');
    $sel->bind_param('s', $abbr);
    $sel->execute();
    $exists = $sel->get_result()->fetch_row() !== null;
    $sel->close();

    if ($exists) {
        return 'existing';
    }

    /* #1674 — dry-run early return: report the same 'created' signal a real
       run would return, WITHOUT the INSERT / ilidStampNewRow below. No
       change needed to the wrappers' per-created-book SongCount refresh
       (`UPDATE tblSongbooks SET SongCount = … WHERE Abbreviation = ?`) —
       under dry-run this abbreviation was never actually inserted, so that
       UPDATE matches ZERO rows for it, a harmless no-op (an abbreviation
       that already existed is never in the created-list by construction,
       so this reasoning covers every abbreviation dry-run reports
       'created' for). */
    if (_bulkImport_dryRun()) {
        return 'created';
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
    $newSongbookId = (int)$db->insert_id;
    $ins->close();
    /* #1860 go-live — mint this songbook's permanent IL-id (ILB…). */
    ilidStampNewRow($db, 'songbook', $newSongbookId);
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
    /* @disabled-visible: importer / batch system path (#1765) — operates over all
       songbooks regardless of public disabled state (recomputes SongCount for
       books it created; disabled≠deleted, an admin import must still work) */
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

    /* #1911 — resolve THIS job's DryRun flag from its own persisted row
       (not from whatever the caller may already have set) so the flag every
       per-file write below obeys is the durable source of truth, not
       in-process state a genuinely separate execution of this worker step
       could never see. Set BEFORE the per-file loop starts, mirroring
       #1674's placement in import_file. Synchronous callers — both $jobDb
       and $jobId null, the "no bulk-import table" / "move_uploaded_file
       failed" fallbacks in api2.php's import_zip case, neither of which
       ever gets a job row — rely on the caller having already primed the
       flag via its own _bulkImport_dryRun() call before reaching here. */
    if ($jobDb !== null && $jobId !== null) {
        _bulkImport_dryRun(_bulkImport_jobDryRunFlag($jobDb, $jobId));
    }

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
    $songsParsedByKind = ['txt' => 0, 'opensong' => 0, 'videopsalm' => 0, 'openlyrics' => 0, 'propresenter6' => 0, 'freeshow' => 0, 'propresenter7' => 0, 'chordpro' => 0];

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
        } elseif ($ext === 'pro') {
            $kind = 'proauto';
        } elseif ($ext === 'show') {
            $kind = 'freeshow';
        } else {
            continue;
        }

        /* ProPresenter 7+ routing fix (#1968 P0/P1, plan §3.1) — '.pro' is
           genuinely ambiguous (ChordPro's own docs bless '.pro' too), so a
           ZIP entry with this extension is resolved HERE, once, by
           content-sniffing it via the ONE shared sniff
           (_bulkImport_sniffProDialect()) — the exact same authority the
           client (editor.js) and api2.php's import_file 'proauto' target
           both defer to. Reassigning $kind lets this entry fall straight
           into whichever per-kind branch below already exists ('pro6', for
           a mis-extensioned .pro6 export) or is added by this rider ('pro7'
           / 'chordpro-in-zip') — a folder of mixed .pro dialects imports
           correctly without the curator sorting them by hand first. */
        if ($kind === 'proauto') {
            $proSniffBody = $zip->getFromIndex($i);
            if ($proSniffBody === false) {
                $errors[] = ['entry' => $name, 'error' => 'could not read entry'];
                $songsFailed++;
                continue;
            }
            $kind = _bulkImport_sniffProDialect($proSniffBody);
            if ($kind === 'chordpro') {
                $kind = 'chordpro-in-zip'; // avoid colliding with any future bare 'chordpro' kind
            }
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

        /* ProPresenter 7+ (epic #1968 / #885): each .pro is one song and
           carries no songbook. Handle inline (no folder convention, mirrors
           the .pro6/.show precedent immediately above/below): file under
           the immediate parent folder's name when present, else a default
           "ProPresenter 7 Import" (PP7) songbook. Reached only once
           `_bulkImport_sniffProDialect()` (above) has confirmed the entry's
           bytes are a genuine PP7 protobuf — a mis-extensioned .pro6 export
           was already re-routed into the 'pro6' branch above instead. */
        if ($kind === 'pro7') {
            $p7body = $zip->getFromIndex($i);
            if ($p7body === false) {
                $errors[] = ['entry' => $name, 'error' => 'could not read entry'];
                $songsFailed++;
                continue;
            }
            [$p7Parsed, $p7Reason] = _bulkImport_parsePro7($p7body);
            if ($p7Parsed === null) {
                $errors[] = ['entry' => $name, 'error' => 'ProPresenter 7+ parse failed: ' . $p7Reason];
                $songsFailed++;
                continue;
            }
            if ($p7Parsed['title'] === '') {
                $stem = pathinfo($name, PATHINFO_FILENAME);
                if ($stem !== '') { $p7Parsed['title'] = $stem; }
            }
            $p7Segments = explode('/', $name);
            $p7Folder   = count($p7Segments) >= 2 ? $p7Segments[count($p7Segments) - 2] : '';
            $p7Name     = $p7Folder !== '' ? $p7Folder : 'ProPresenter 7 Import';
            $p7Abbr     = $p7Folder !== '' ? _bulkImport_videopsalmAbbrevFromHint($p7Folder, $p7Name) : 'PP7';
            if ($p7Abbr === 'VP' || $p7Abbr === '') { $p7Abbr = 'PP7'; }
            if (!isset($songbookSeen[$p7Abbr])) {
                $state = _bulkImport_upsertSongbook($db, $p7Abbr, $p7Name, null);
                $songbookSeen[$p7Abbr] = $state;
                if ($state === 'created') { $songbooksCreated[] = $p7Abbr; }
                else                       { $songbooksExisting[] = $p7Abbr; }
            }
            if (!isset($songbookCounters[$p7Abbr])) {
                $songbookCounters[$p7Abbr] = _bulkImport_nextSongNumberFor($db, $p7Abbr);
            }
            $p7Number = $songbookCounters[$p7Abbr];
            $songbookCounters[$p7Abbr] = $p7Number + 1;
            $p7Song = _bulkImport_assembleSong($p7Parsed, $p7Abbr, $p7Name, $p7Number);
            [$p7Action, $p7Err] = _bulkImport_saveSong($db, $p7Song);
            if ($p7Action === 'create') {
                $songsCreated++;
                $songsParsedByKind['propresenter7']++;
                $_perBookBump($p7Abbr, $p7Name, 'created');
            } elseif ($p7Action === 'skipped') {
                $songsSkippedExisting++;
                $_perBookBump($p7Abbr, $p7Name, 'skipped');
                if (isset($p7Song['id']) && $p7Song['id'] !== '') {
                    $skippedSongIds[] = (string)$p7Song['id'];
                }
            } else {
                $errors[] = ['entry' => $name, 'error' => 'save failed: ' . $p7Err];
                $songsFailed++;
                $_perBookBump($p7Abbr, $p7Name, 'failed', 'save failed: ' . $p7Err, $name, $p7Number ?: null, 'save');
            }
            continue;
        }

        /* Genuine ChordPro found inside a ZIP by the '.pro' three-way sniff
           above (epic #1968 P0's routing-fix rider — a folder of .pro files
           now imports correctly regardless of which of the three real
           dialects each entry actually is). Handle inline exactly like the
           pro7/pro6/freeshow entries: file under the parent folder name when
           present, else a default "ChordPro Import" (CHORDPRO) songbook.
           A .cho/.chopro/.crd/.chord entry is NOT reachable here — this
           file's ZIP router does not otherwise recognise those extensions,
           so this branch exists only for the sniffed-as-ChordPro '.pro'
           case; genuine ChordPro folders still import via the single-file
           bulk_import_chordpro endpoint per-file today. */
        if ($kind === 'chordpro-in-zip') {
            $cpBody = $zip->getFromIndex($i);
            if ($cpBody === false) {
                $errors[] = ['entry' => $name, 'error' => 'could not read entry'];
                $songsFailed++;
                continue;
            }
            $cpSegments = explode('/', $name);
            $cpFolder   = count($cpSegments) >= 2 ? $cpSegments[count($cpSegments) - 2] : '';
            $cpName     = $cpFolder !== '' ? $cpFolder : 'ChordPro Import';
            $cpAbbr     = $cpFolder !== '' ? _bulkImport_videopsalmAbbrevFromHint($cpFolder, $cpName) : 'CHORDPRO';
            if ($cpAbbr === 'VP' || $cpAbbr === '') { $cpAbbr = 'CHORDPRO'; }
            if (!isset($songbookSeen[$cpAbbr])) {
                $state = _bulkImport_upsertSongbook($db, $cpAbbr, $cpName, null);
                $songbookSeen[$cpAbbr] = $state;
                if ($state === 'created') { $songbooksCreated[] = $cpAbbr; }
                else                       { $songbooksExisting[] = $cpAbbr; }
            }
            if (!isset($songbookCounters[$cpAbbr])) {
                $songbookCounters[$cpAbbr] = _bulkImport_nextSongNumberFor($db, $cpAbbr);
            }
            $cpNumber = $songbookCounters[$cpAbbr];
            $songbookCounters[$cpAbbr] = $cpNumber + 1;
            [$cpParsed, $cpReason] = _bulkImport_parseChordPro($cpBody, $cpAbbr, $cpName, $cpNumber);
            if ($cpParsed === null) {
                $errors[] = ['entry' => $name, 'error' => 'ChordPro parse failed: ' . $cpReason];
                $songsFailed++;
                continue;
            }
            [$cpAction, $cpErr] = _bulkImport_saveSong($db, $cpParsed);
            if ($cpAction === 'create') {
                $songsCreated++;
                $songsParsedByKind['chordpro']++;
                $_perBookBump($cpAbbr, $cpName, 'created');
            } elseif ($cpAction === 'skipped') {
                $songsSkippedExisting++;
                $_perBookBump($cpAbbr, $cpName, 'skipped');
                if (isset($cpParsed['id']) && $cpParsed['id'] !== '') {
                    $skippedSongIds[] = (string)$cpParsed['id'];
                }
            } else {
                $errors[] = ['entry' => $name, 'error' => 'save failed: ' . $cpErr];
                $songsFailed++;
                $_perBookBump($cpAbbr, $cpName, 'failed', 'save failed: ' . $cpErr, $name, $cpNumber ?: null, 'save');
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
            /* #1740 — _bulkImport_parseOpenSong() now takes a callable so a
               single-file caller can defer the DB hit; the ZIP loop already
               has $db connected for the whole archive and needs the counter
               resolved up front regardless (so a second numberless entry in
               the same songbook doesn't collide), so this just wraps the
               already-resolved value — no behaviour change here. */
            $zipAutoNumber = $songbookCounters[$abbr];
            [$song, $reason] = _bulkImport_parseOpenSong($body, $abbr, $bookName, $songNum, static fn (): int => $zipAutoNumber);
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
 * @param int      $numberHint          Number derived from the filename's
 *                                      leading digits (0 if none).
 * @param callable $autoNumberProvider  Zero-arg closure returning the
 *                                      per-songbook auto-increment fallback
 *                                      (int) when neither the XML nor the
 *                                      filename supply a number. Invoked
 *                                      LAZILY (#1740) — only when actually
 *                                      needed — so a caller whose provider
 *                                      hits the database never pays for
 *                                      that query on a document that
 *                                      already has a number, or that fails
 *                                      to parse before number-resolution.
 * @return array{0: ?array, 1: ?string}  [songObject, errorReason]
 */
function _bulkImport_parseOpenSong(string $body, string $abbrev, string $songbook, int $numberHint, callable $autoNumberProvider): array
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
       carry numbers at all (which is most non-hymnal song libraries).
       $autoNumberProvider is invoked LAZILY (#1740) — only when neither
       of the first two sources supplied a number — so a caller whose
       provider hits the database (_bulkImport_processOpenSong()'s
       per-songbook MAX(Number) lookup) never opens that connection for
       a document that already carries its own number, and never opens
       it at all for a document that fails to parse (every return above
       this point happens before we get here). */
    $hymnNumberRaw = trim((string)($xml->hymn_number ?? ''));
    $hymnNumber    = ($hymnNumberRaw !== '' && ctype_digit($hymnNumberRaw))
        ? (int)$hymnNumberRaw
        : 0;
    $number = $hymnNumber > 0
        ? $hymnNumber
        : ($numberHint > 0 ? $numberHint : (int)$autoNumberProvider());
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
    /* @disabled-visible: importer number-allocation (#1765) — MAX(Number) must
       span every song in the book regardless of public disabled state so a new
       import number never collides with an existing (possibly hidden) song */
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
    /* #1908 Commit 6 — detect + convert UTF-16/UTF-32 (with or without a
       BOM) to UTF-8 before json_decode() ever sees the bytes; a raw UTF-8
       BOM strip alone (the old behaviour here) left a UTF-16 export a
       generic, unhelpful "invalid JSON" failure. See text_encoding.php. */
    require_once __DIR__ . '/text_encoding.php';
    $converted = ihymnsTextToUtf8($body);
    if ($converted === null) {
        return [null, null, 'file is not UTF-8 (or UTF-16) text — re-save it as UTF-8'];
    }
    $body = $converted;
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
    /* @disabled-visible: importer / batch system path (#1765) — operates over all
       songbooks regardless of public disabled state */
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
    /* @disabled-visible: importer / batch system path (#1765) — operates over all
       songbooks regardless of public disabled state */
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
    /* OpenLyrics permits multiple <title> elements (#1052 / #1669): the first
       non-empty is the MAIN title; each remaining distinct non-empty one is an
       ALTERNATIVE title (its optional lang attribute carried through). The
       shared song_alt_titles core writes them in _bulkImport_saveSong() when
       that table is migrated, skipping any equal to the main title. */
    $title     = '';
    $altTitles = [];
    $seenAlt   = [];
    if ($props && isset($props->titles->title)) {
        $fold = static fn(string $s): string => function_exists('mb_strtolower') ? mb_strtolower($s) : strtolower($s);
        foreach ($props->titles->title as $tNode) {
            $tText = trim((string)$tNode);
            if ($tText === '') { continue; }
            if ($title === '') { $title = $tText; continue; }
            $foldAlt = $fold($tText);
            if ($foldAlt === $fold($title) || isset($seenAlt[$foldAlt])) { continue; }
            $seenAlt[$foldAlt] = true;
            $altTitles[] = [
                'title'    => $tText,
                'language' => isset($tNode['lang']) ? trim((string)$tNode['lang']) : '',
            ];
        }
    }
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
        'altTitles'    => $altTitles,
        'components'   => $components,
    ], null];
}

/**
 * Assemble a neutral parsed structure into the song shape that
 * _bulkImport_saveSong() consumes (mirrors _bulkImport_parseOpenSong()).
 * Format-agnostic: any importer whose parser returns the
 * { title, language, ccli, copyright, writers[], components[] } keys can
 * reuse this (OpenLyrics #1052, ProPresenter 6 #1057, …).
 *
 * `arrangement` (#1968 PR-1) — an optional `?int[]` of indices into
 * `components[]` (repeats allowed, e.g. a refrain between every verse),
 * passed straight through to `_bulkImport_saveSong()`, which already
 * persists it via `_sanitiseArrangement()` under the schema-probed
 * `ArrangementJson` column (#892, L591-634) — this is simply the FIRST
 * parser to actually populate the key; every earlier caller left it unset,
 * which `?? null` below already handled identically to an explicit null.
 * `_bulkImport_parsePro7()` is the first producer (`.pro` files carry real
 * ProPresenter arrangements); any future importer with its own native
 * arrangement concept (e.g. a richer XML format) can reuse this same key
 * with no further plumbing.
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
        'altTitles'          => (array)($parsed['altTitles'] ?? []),
        'components'         => (array)($parsed['components'] ?? []),
        'arrangement'        => $parsed['arrangement'] ?? null,
    ];
}

/**
 * Synchronous single-file OpenLyrics import — invoked from the
 * bulk_import_openlp dispatcher case. Returns the same summary shape as
 * _bulkImport_processVideoPsalm() / _bulkImport_processZip().
 */
function _bulkImport_processOpenLp(string $body, ?string $filenameHint = null): array
{
    /* @disabled-visible: importer / batch system path (#1765) — operates over all
       songbooks regardless of public disabled state */
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

/**
 * Synchronous single-file OpenSong import (#882).
 *
 * ELI5: someone uploaded ONE OpenSong `<song>` XML file (not a zip of many);
 * this reads it, files it under a catch-all "OpenSong Import" songbook, and
 * saves it — the exact same job _bulkImport_processOpenLp() does for a lone
 * OpenLyrics file, just for the other XML dialect.
 *
 * Detail — before this function existed, a single OpenSong .xml had NO
 * single-file processor at all: _bulkImport_parseOpenSong() (the parser)
 * was reachable only from the ZIP-import loop, which supplies the
 * abbreviation/songbook-name/number-hint it needs from the ZIP's
 * "<Title> [<ABBR>]/<#> …" folder+filename convention. A lone upload has no
 * such folder, so unlike OpenLyrics (whose <songbook name="…" entry="N"/>
 * is self-describing) OpenSong XML carries no songbook metadata of its own
 * — the file is therefore parked under a fixed "OpenSong Import" (abbr
 * "OS") songbook, the same fixed-name convention
 * _bulkImport_processProclaim() (abbr "PC") and _bulkImport_processFreeShow()
 * (name "FreeShow Import") already use for their own metadata-less
 * single-file formats. Number resolution mirrors the ZIP loop's own
 * priority chain (song_importers.php's OpenSong branch): the XML's own
 * <hymn_number> wins if present, else the upload filename's leading
 * digits (same `preg_match('/^(\d{1,5})/', …)` the ZIP loop uses), else a
 * fresh per-songbook auto-increment — all three are folded into
 * _bulkImport_parseOpenSong()'s own priority logic via its $numberHint /
 * $autoNumber parameters, so this function only has to compute those two
 * inputs, not re-implement the ordering.
 *
 * Contract is byte-identical in SHAPE to _bulkImport_processOpenLp(): same
 * summary keys, same "ok=false only on parse failure, save failures land as
 * ok=true + songs_failed>0" contract, and the same #1694 D1 SongCount
 * recompute block on songbook creation. Invoked from the shared XML
 * auto-router (_bulkImport_processXmlAuto()) and, once wired, an explicit
 * format=opensong single-file upload.
 *
 * @param string      $body         Raw XML.
 * @param string|null $filenameHint Original upload filename — used only for
 *                                  the leading-digits number hint and error
 *                                  reporting, never for songbook naming.
 * @return array  Same summary shape as _bulkImport_processOpenLp().
 */
function _bulkImport_processOpenSong(string $body, ?string $filenameHint = null): array
{
    /* @disabled-visible: importer / batch system path (#1765) — operates over all
       songbooks regardless of public disabled state */
    $abbr     = 'OS';
    $bookName = 'OpenSong Import';

    /* Leading digits in the upload filename are a NUMBER HINT ONLY — the
       XML's own <hymn_number> (checked inside _bulkImport_parseOpenSong())
       always wins over this. Mirrors the identical extraction the ZIP
       loop performs on the archive entry's filename. */
    $numberHint = 0;
    if ($filenameHint !== null) {
        $leaf = (string)preg_replace('#^.*/#', '', $filenameHint);
        if (preg_match('/^(\d{1,5})/', $leaf, $m)) {
            $numberHint = (int)$m[1];
        }
    }

    /* #1740 — parse FIRST, connect only if the parser actually asks for an
       auto-number (i.e. the document has neither <hymn_number> nor a
       filename hint). Mirrors _bulkImport_processOpenLp()'s parse-then-
       connect shape: an unparseable file, or one that already carries its
       own number, never opens a database connection. $db is captured by
       reference so the closure's connection (if any) is reused below
       rather than reconnecting for the save/upsert that follows a
       successful parse. */
    $db = null;
    $autoNumberProvider = function () use (&$db, $abbr): int {
        if ($db === null) {
            $db = getDbMysqli();
        }
        return _bulkImport_nextSongNumberFor($db, $abbr);
    };
    [$song, $reason] = _bulkImport_parseOpenSong($body, $abbr, $bookName, $numberHint, $autoNumberProvider);
    if ($song === null) {
        return [
            'ok'                     => false,
            'error'                  => $reason ?: 'OpenSong parse failed',
            'songbooks_created'      => [],
            'songbooks_existing'     => [],
            'songs_created'          => 0,
            'songs_skipped_existing' => 0,
            'songs_failed'           => 0,
            'parsed_by_format'       => ['opensong' => 0],
            'errors'                 => [],
        ];
    }

    if ($db === null) {
        $db = getDbMysqli();
    }

    $state             = _bulkImport_upsertSongbook($db, $abbr, $bookName, null);
    $songbooksCreated  = ($state === 'created')  ? [$abbr] : [];
    $songbooksExisting = ($state === 'existing') ? [$abbr] : [];

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
            'entry' => ($filenameHint ?? 'opensong') . ': ' . ($song['id'] ?? '?'),
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
        'parsed_by_format'       => ['opensong' => $songsCreated + $songsSkipped],
        'errors'                 => $errors,
    ];
}

/**
 * Pure routing decision for a single .xml upload of UNKNOWN dialect (#882).
 *
 * ELI5: peeks at the file and guesses "OpenLyrics" or "OpenSong" — no
 * database, no parsing, just a guess so the caller knows which parser to
 * try first.
 *
 * Detail — the ONE discriminator is _bulkImport_looksLikeOpenLyrics(), the
 * same function the ZIP-import loop already uses to sort .xml entries
 * (song_importers.php's ZIP walker) — sharing it means a single-file auto
 * decision and a ZIP-entry decision can never disagree about the same file
 * (CLAUDE.md rule #35: one mechanism, not two independent guesses).
 * Extracted as its own pure function so _bulkImport_processXmlAuto()'s
 * try-both fallback logic can be tested without a database connection
 * (see tests/php/test-xml-import-routing.php).
 *
 * @param string $body Raw XML.
 * @return string 'openlp' or 'opensong'.
 */
function _bulkImport_xmlAutoPrimary(string $body): string
{
    return _bulkImport_looksLikeOpenLyrics($body) ? 'openlp' : 'opensong';
}

/**
 * Single-file XML auto-router for an UNKNOWN OpenLyrics-vs-OpenSong upload
 * (#882) — the fix for "OpenSong single-file import has never worked".
 *
 * ELI5: tries the parser the sniff thinks is right; if that one says the
 * file isn't valid, tries the OTHER parser once before giving up — so a
 * single .xml upload of either dialect lands correctly without the curator
 * having to know which one it is.
 *
 * Detail — before this function existed, EVERY single .xml upload (both
 * editors, `format=auto` and the only dropdown option that existed) was
 * hard-routed to _bulkImport_processOpenLp() alone, so an OpenSong file
 * always failed with OpenLyrics' own "no <title> element" error (OpenSong's
 * <title> lives at the XML root; OpenLyrics looks for
 * properties/titles/title) — a confusing, wrong-format error rather than
 * a clear "not recognised" message. This router:
 *   1. Picks a PRIMARY format via the one shared discriminator
 *      _bulkImport_xmlAutoPrimary() (never a second, parallel guess).
 *   2. Tries the primary parser. Both _bulkImport_processOpenLp() and
 *      _bulkImport_processOpenSong() return `ok=false` on a parse failure, so
 *      a primary miss is always safe to retry with the other parser (the retry
 *      is idempotent: a failed parse writes nothing).
 *
 *      Neither touches the database before that check (#1740): processOpenSong
 *      used to call getDbMysqli() + _bulkImport_nextSongNumberFor() BEFORE
 *      parsing (because $autoNumber was a plain int parse ARGUMENT), so
 *      rejecting an unparseable OpenSong file still opened a connection and
 *      ran a MAX(Number) query. It now takes a callable $autoNumberProvider
 *      that _bulkImport_parseOpenSong() only invokes lazily, from inside the
 *      parser, if the document actually lacks both <hymn_number> and a
 *      filename hint — so a retry here is both safe (idempotent) and free
 *      (no connection) for any document that fails to parse.
 *   3. On primary success (`ok=true`, even if the save itself later fails
 *      and songs_failed>0), stamps the RESOLVED format into the result and
 *      returns immediately — no second parse attempted.
 *   4. On primary failure, tries the secondary parser ONCE. Success there
 *      returns with the resolved format stamped. This makes auto-detect
 *      strictly MORE capable than either parser picked in isolation — e.g.
 *      a namespace-less OpenLyrics export with unnamed <verse> elements
 *      fails the cheap sniff (which looks for `<verse name=`) but still
 *      parses fine once actually tried.
 *   5. If BOTH fail, returns ONE combined error naming both formats and
 *      both underlying reasons — never a misleading single-format error,
 *      and never silently picks a "winner" between two failures.
 *   Explicit format picks (format=openlp or format=opensong from the UI)
 *   bypass this router entirely and call the single parser directly — an
 *   operator who asserted the format gets THAT format's real error, not a
 *   combined guess (matches the #1633 JSON-sniff precedent: sniffing never
 *   overrides an explicit choice).
 *
 * @param string      $body         Raw XML.
 * @param string|null $filenameHint Original upload filename, passed through
 *                                  to whichever single-format processor runs.
 * @return array  Same summary shape as _bulkImport_processOpenLp(), plus a
 *                'format' key naming the format that actually parsed
 *                ('openlp' or 'opensong'; absent/null when both failed).
 */
function _bulkImport_processXmlAuto(string $body, ?string $filenameHint = null): array
{
    $primary   = _bulkImport_xmlAutoPrimary($body);
    $secondary = $primary === 'openlp' ? 'opensong' : 'openlp';

    $run = static function (string $format, string $body, ?string $filenameHint): array {
        return $format === 'openlp'
            ? _bulkImport_processOpenLp($body, $filenameHint)
            : _bulkImport_processOpenSong($body, $filenameHint);
    };

    $primaryResult = $run($primary, $body, $filenameHint);
    if ($primaryResult['ok'] ?? false) {
        $primaryResult['format'] = $primary;
        return $primaryResult;
    }

    $secondaryResult = $run($secondary, $body, $filenameHint);
    if ($secondaryResult['ok'] ?? false) {
        $secondaryResult['format'] = $secondary;
        return $secondaryResult;
    }

    /* Both failed — name BOTH formats and BOTH real reasons rather than
       reporting only the primary guess's (potentially misleading) error. */
    $errorFor = static function (string $format) use ($primary, $primaryResult, $secondaryResult): string {
        $result = ($format === $primary) ? $primaryResult : $secondaryResult;
        return (string)($result['error'] ?? 'parse failed');
    };
    $openlpError   = $errorFor('openlp');
    $opensongError = $errorFor('opensong');

    return [
        'ok'                     => false,
        'error'                  => "not recognised as OpenLyrics ({$openlpError}) or OpenSong ({$opensongError})",
        'songbooks_created'      => [],
        'songbooks_existing'     => [],
        'songs_created'          => 0,
        'songs_skipped_existing' => 0,
        'songs_failed'           => 0,
        'parsed_by_format'       => ['openlp' => 0, 'opensong' => 0],
        'errors'                 => [],
        'format'                 => null,
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
 * cp1252 (Windows-1252) byte -> UTF-8 string, for one `\'XX` RTF hex escape.
 * ============================================================================
 * ELI5: `\ansicpg1252` (declared by every dual-dialect RTF fixture this file has
 * seen — Mac `\cocoartf…` AND Windows `\rtf0…`) means "when you see `\'93`, that
 * is not the raw byte 0x93 — it is a Windows-1252-encoded character, and 0x93
 * means a curly left double-quote, not the C1 control code raw bytes would
 * suggest." The OLD code (`chr(hexdec($hex))`, pre-#1968) just returned the raw
 * byte, which for 0x80–0xFF is not valid UTF-8 on its own — every downstream
 * consumer (the DB write, the browser) either mojibakes it or silently drops
 * the invalid sequence. This turns the byte into the UTF-8 bytes for the
 * character it actually means.
 *
 * DETAILED — why a hand-rolled 0x80–0x9F table instead of leaning on
 * `mb_convert_encoding($chr, 'UTF-8', 'Windows-1252')` for the whole range
 * (plan §3.6 change 2): 0xA0–0xFF is BYTE-IDENTICAL between Windows-1252 and
 * ISO-8859-1/Unicode Latin-1 Supplement (codepoint == byte value) — a fact
 * fixed by the standard, not an implementation detail — so those 96 bytes need
 * no lookup at all, only a codepoint->UTF-8 encode. Only 0x80–0x9F actually
 * differs (that block is where cp1252 packs the smart quotes / em-dash / €
 * that ISO-8859-1 instead reserves for C1 control codes), so that is the ONLY
 * block that needs a table — and a fixed 32-entry table is trivially
 * unit-testable (tests/php/test-pp7-rtf-extract.php) without depending on
 * mbstring's own encoding-alias support being present/correctly built (this
 * codebase already treats `mb_chr`/`mb_convert_encoding` as "assumed present,
 * guarded" rather than hard-required — see the existing `function_exists`
 * checks in `_bulkImport_rtfToText()` below). The table's values are the
 * standard Windows-1252 mapping (5 code points — 0x81/0x8D/0x8F/0x90/0x9D —
 * are UNDEFINED in the strict cp1252 spec; this table follows the WHATWG
 * `windows-1252` decoder's convention of falling back to the byte's own value
 * for those 5, which is also what PHP's own `mb_convert_encoding` does —
 * verified empirically against this exact PHP build during implementation).
 *
 * @param int $byte 0-255 (a `\'XX` escape's two hex digits, already validated
 *                  by the caller via `ctype_xdigit()`)
 * @return string the UTF-8 encoding of the character that byte means under
 *                cp1252 — never throws; degrades to '' only in the
 *                practically-impossible case that mbstring is entirely absent
 *                AND the codepoint needs multi-byte encoding
 * @see https://en.wikipedia.org/wiki/Windows-1252                 the 0x80-0x9F mapping table
 * @see https://encoding.spec.whatwg.org/#legacy-single-byte-encodings   the "undefined -> identity" fallback convention
 * @see .claude/propresenter-interop-1968-plan.md                  §3.6 change 2
 */
function _bulkImport_rtfCp1252ByteToUtf8(int $byte): string
{
    static $upperBlock = null;
    if ($upperBlock === null) {
        // 0x80-0x9F only — see the doc-block above for why 0xA0-0xFF needs no table.
        $upperBlock = [
            0x80 => 0x20AC, 0x81 => 0x0081, 0x82 => 0x201A, 0x83 => 0x0192,
            0x84 => 0x201E, 0x85 => 0x2026, 0x86 => 0x2020, 0x87 => 0x2021,
            0x88 => 0x02C6, 0x89 => 0x2030, 0x8A => 0x0160, 0x8B => 0x2039,
            0x8C => 0x0152, 0x8D => 0x008D, 0x8E => 0x017D, 0x8F => 0x008F,
            0x90 => 0x0090, 0x91 => 0x2018, 0x92 => 0x2019, 0x93 => 0x201C,
            0x94 => 0x201D, 0x95 => 0x2022, 0x96 => 0x2013, 0x97 => 0x2014,
            0x98 => 0x02DC, 0x99 => 0x2122, 0x9A => 0x0161, 0x9B => 0x203A,
            0x9C => 0x0153, 0x9D => 0x009D, 0x9E => 0x017E, 0x9F => 0x0178,
        ];
    }
    if ($byte < 0x80) {
        return chr($byte); // ASCII — identical under every encoding this function ever sees.
    }
    // 0xA0-0xFF: Windows-1252 codepoint == byte value (Latin-1 Supplement), no table needed.
    $codepoint = $byte <= 0x9F ? ($upperBlock[$byte] ?? $byte) : $byte;
    $ch = function_exists('mb_chr') ? mb_chr($codepoint, 'UTF-8') : null;
    return ($ch !== null && $ch !== false) ? $ch : '';
}

/**
 * Minimal RTF -> plain-text converter, sufficient for the simple RTF
 * ProPresenter stores (lyric runs with \par / \line breaks, \uN unicode,
 * \'XX hex bytes; \fonttbl / \colortbl / \*-destinations discarded).
 *
 * DUAL-DIALECT CONTRACT (#1968 plan §3.6) — this is the ONE shared decoder
 * for every RTF-bearing importer in this file (Pro6, EasyWorship, Proclaim,
 * and — from PR-1 onward — ProPresenter 7+'s `_bulkImport_parsePro7()`).
 * Input: one RTF document, EITHER dialect real ProPresenter writes (Mac
 * `{\rtf1\ansi\ansicpg1252\cocoartf<ver>…` using an escaped-CRLF "soft
 * return" as its line break, or Windows `{\rtf0\ansi\ansicpg1252…` using
 * `\par`), or the plain single-dialect RTF Pro6/EasyWorship/Proclaim already
 * fed it before PR-1. Output: UTF-8 plain text, lines separated by `\n`;
 * header/table/ignorable-destination groups (`\fonttbl`, `\colortbl`,
 * `{\*\…}`, …) contribute nothing; formatting control words strip silently;
 * unknown control words strip silently. NEVER THROWS — malformed/truncated
 * input degrades to whatever text was recovered before the malformed part,
 * matching every other importer's "never abort the whole batch on one bad
 * file" posture (plan §3.7).
 *
 * FOUR TARGETED CHANGES landed for #1968 PR-1 (plan §3.6; change 4 is the
 * PR-1 correctness-defect fix, dominant-font lyric selection) — each one is a
 * strict correctness fix for the PRE-EXISTING Pro6/EasyWorship/Proclaim
 * callers too, not just new ProPresenter-7 code, and each is protected by a
 * non-regression row in tests/php/test-pp7-rtf-extract.php so this shared
 * function can safely keep being shared (CLAUDE.md modularity rule) instead
 * of ProPresenter 7+ forking its own copy:
 *   1. A backslash immediately followed by a raw CR and/or LF (the Cocoa
 *      "soft return") now emits a newline instead of being silently dropped
 *      by the generic "other control symbol" branch — verified byte-for-byte
 *      against a real Mac-exported .pro fixture (see the `\` + CR/LF branch
 *      below for the exact hex evidence). Before this fix, EVERY Mac-authored
 *      ProPresenter file (and any Cocoa-flavoured .pro6/EasyWorship RTF) had
 *      its soft-return-separated lines silently joined into one run-on line.
 *   2. `\'XX` now converts through `_bulkImport_rtfCp1252ByteToUtf8()` (cp1252-
 *      aware) instead of `chr($byte)` — the old code emitted the RAW byte for
 *      0x80-0xFF, which is not valid UTF-8 on its own (a latent mojibake bug
 *      in every current importer for any Windows-authored source; retrospective
 *      issue filed per plan §12.2 item 1).
 *   3. `\uN` supplementary-plane characters are encoded in RTF as a signed
 *      16-bit HIGH surrogate escape immediately followed by a LOW surrogate
 *      escape (each with its own `\uc`-controlled ASCII fallback tail) — the
 *      old code fed each half to `mb_chr()` on its own, which returns `false`
 *      for a lone surrogate (not a valid Unicode scalar value), so BOTH
 *      halves were silently dropped. This buffers a high surrogate and
 *      combines it with the following low surrogate into the one real
 *      character (rare in hymnody, but cheap to do right — see the
 *      surrogate-pair truth-table row for the exact math). Separately, the
 *      Cocoa `\uc0\u8232 ` LINE SEPARATOR (U+2028, and U+2029 PARAGRAPH
 *      SEPARATOR) idiom is also folded to `\n` here, matching how `\par`
 *      already reads as a line break.
 *   4. OPTIONAL dominant-font suppression via `$minFontHalfPts` (epic #1968
 *      PR-1 correctness-defect fix). ELI5: a genuine ProPresenter lyric
 *      slide can carry the real, large-font lyric AND a small copyright /
 *      CCLI-display run (or, empirically, a stray small-font RTF-writer
 *      artifact — see `_bulkImport_pro7RtfMaxFontHalfPts()`'s doc-block) in
 *      the SAME `rtf_data`; without this, both runs were concatenated
 *      verbatim, PREPENDING the small run onto the real lyric line
 *      ("',I know a place" instead of "I know a place"). DETAILED: while
 *      walking, the CURRENT `\fsN` (half-point font size) is tracked on a
 *      group-scoped stack (`$fontSizeStack`), mirroring exactly how `\uc`'s
 *      skip-count is already tracked on `$ucStack` just above — `\fsN` sets
 *      the CURRENT group's size, a nested `{…}` group inherits it, and it
 *      reverts when that group closes, per the RTF spec's normal character-
 *      formatting-attribute scoping (see the `@see` RTF spec link below).
 *      Whenever `$minFontHalfPts > 0` (the caller's chosen "this run is the
 *      lyric" cutoff — see `_bulkImport_pro7RtfMaxFontHalfPts()`) AND the
 *      CURRENT tracked size is BOTH non-zero (an `\fsN` was actually seen —
 *      size 0 means "unknown", never suppressed, so a run with no explicit
 *      `\fsN` at all is never accidentally dropped) AND strictly LESS than
 *      `$minFontHalfPts`, every text-emitting branch below (`\'XX`, `\uN`,
 *      `\par`/`\line`, `\tab`, escaped braces, and plain literal characters)
 *      suppresses ONLY that emission — control words, group nesting and the
 *      `\uc`/`\'XX` skip-count bookkeeping still process completely
 *      normally, so a suppressed run never desyncs the rest of the walk.
 *      **DEFAULT-0 NON-REGRESSION CONTRACT**: `$minFontHalfPts = 0` (every
 *      EXISTING Pro6/EasyWorship/Proclaim call site, which passes nothing)
 *      makes the suppression check always false — font-size tracking still
 *      runs (harmless bookkeeping with no side effect), but NOTHING is ever
 *      suppressed, so output is BYTE-IDENTICAL to before this change;
 *      proven by tests/php/test-pp7-rtf-extract.php section (b) and by the
 *      truth-table row pairing the SAME two-font input against
 *      `$minFontHalfPts = 0` vs. a real cutoff (section (a)).
 *
 * Implemented as a single-pass tokeniser tracking group depth so nested
 * destination groups (font/colour/style tables, \* groups) are skipped
 * wholesale rather than leaking control text into the lyrics.
 *
 * @param string $rtf             one RTF document (Mac dialect, Windows dialect, or plain)
 * @param int    $minFontHalfPts  #1968 PR-1: suppress text emitted while the CURRENT
 *                                 group-scoped `\fsN` (RTF half-points) is non-zero and
 *                                 strictly less than this value. Default 0 = no filtering
 *                                 at all (today's exact behaviour — the non-regression
 *                                 contract every pre-existing caller relies on).
 * @return string UTF-8 plain text, `\n`-separated lines; never throws
 * @see https://www.biblioscape.com/rtf15_spec.htm                 §"An RTF Reader" (destinations, \uN, \'XX, character formatting scope)
 * @see .claude/propresenter-interop-1968-plan.md                  §3.6 (this function's brief + truth table)
 * @see tests/php/test-pp7-rtf-extract.php                          the truth table + non-regression rows
 */
function _bulkImport_rtfToText(string $rtf, int $minFontHalfPts = 0): string
{
    $out = '';
    $i   = 0;
    $n   = strlen($rtf);
    $depth          = 0;
    $ucStack        = [1];   // \uc skip-count per group level
    $fontSizeStack  = [0];   // #1968 change 4 — \fsN (half-points) per group level, 0 = "unknown"
    $unicodeSkip    = 0;     // literal chars still to swallow after a \uN
    $skipUntilDepth = -1;    // when >=0, suppress output until depth drops below it
    $pendingHighSurrogate = null; // #1968 change 3 — a buffered \uN high surrogate awaiting its low pair

    /* #1968 change 4 — true while the CURRENT group's tracked \fsN is smaller
       than the caller's dominant-font cutoff. A closure (not a plain bool)
       because it must always read the LIVE top of $fontSizeStack, which
       changes on every \fsN / group open+close below. $minFontHalfPts === 0
       (every pre-existing caller) makes this always false — see the
       function doc-block's "DEFAULT-0 NON-REGRESSION CONTRACT". */
    $isFontSuppressed = static function () use (&$fontSizeStack, $minFontHalfPts): bool {
        $current = $fontSizeStack[count($fontSizeStack) - 1];
        return $minFontHalfPts > 0 && $current > 0 && $current < $minFontHalfPts;
    };

    while ($i < $n) {
        $c = $rtf[$i];

        if ($c === '\\') {
            $next = $i + 1 < $n ? $rtf[$i + 1] : '';

            /* Escaped literal brace / backslash. */
            if ($next === '\\' || $next === '{' || $next === '}') {
                if ($skipUntilDepth < 0) {
                    if ($unicodeSkip > 0) { $unicodeSkip--; }
                    elseif (!$isFontSuppressed()) { $out .= $next; }
                }
                $i += 2;
                continue;
            }

            /* #1968 change 1 — a backslash immediately followed by a raw CR
               and/or LF is the Cocoa "soft return": RTF spec-equivalent to
               \par (https://www.biblioscape.com/rtf15_spec.htm — an escaped
               end-of-line is a paragraph break). Byte-verified against a real
               Mac-exported ProPresenter 7 fixture during implementation
               (tests/fixtures/propresenter/bussnet-test.pro, the "Ending" cue's
               first text element: the bytes `...31 5C 0A 54 72 61 6E 73...`
               are "1" + [\ + LF] + "Trans" — i.e. exactly a backslash directly
               followed by a raw newline, mid-word-boundary, meaning "line
               break here", not "drop this character"). Consumes AT MOST one
               CR then one LF so a CRLF-terminated soft return collapses to
               ONE newline, matching \par's single "\n" output below. Before
               this fix the "other control symbol" branch further down treated
               `\` + newline as a two-byte symbol to silently drop, joining
               every soft-return-separated line into one run-on line — the
               EXACT bug this fixture's "Trans Original 1<newline>Trans
               Original 2" text was written to expose (see
               tests/php/test-pp7-rtf-extract.php truth-table row 1). */
            if ($next === "\r" || $next === "\n") {
                $j = $i + 2;
                if ($next === "\r" && $j < $n && $rtf[$j] === "\n") { $j++; }
                if ($skipUntilDepth < 0) {
                    if ($unicodeSkip > 0) { $unicodeSkip--; }
                    elseif (!$isFontSuppressed()) { $out .= "\n"; }
                }
                $i = $j;
                continue;
            }

            /* \'XX — one hex-encoded byte. #1968 change 2: cp1252-aware
               conversion (was raw chr($byte), invalid UTF-8 for 0x80-0xFF —
               see _bulkImport_rtfCp1252ByteToUtf8()'s doc-block). */
            if ($next === "'") {
                $hex = substr($rtf, $i + 2, 2);
                $i  += 4;
                if ($skipUntilDepth < 0) {
                    if ($unicodeSkip > 0) { $unicodeSkip--; }
                    elseif (ctype_xdigit($hex) && !$isFontSuppressed()) { $out .= _bulkImport_rtfCp1252ByteToUtf8(hexdec($hex)); }
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
                        /* #1968 change 3 — surrogate-pair combining. RTF encodes a
                           supplementary-plane character (anything past the Basic
                           Multilingual Plane, e.g. an emoji) as TWO \uN escapes back
                           to back: a signed 16-bit HIGH surrogate (0xD800-0xDBFF once
                           normalised) then a LOW surrogate (0xDC00-0xDFFF), each with
                           its own \uc-controlled ASCII fallback tail. The pre-#1968
                           code called mb_chr() on EACH half independently — mb_chr()
                           returns false for a lone surrogate (not a valid Unicode
                           scalar value on its own — https://www.unicode.org/glossary/#surrogate_pair)
                           — so BOTH halves were silently dropped. This buffers a high
                           surrogate across the loop iteration and combines it with the
                           very next low surrogate into the one real codepoint (the
                           standard UTF-16 combining formula); see
                           tests/php/test-pp7-rtf-extract.php's surrogate-pair truth-
                           table row for a worked example (U+1F600 GRINNING FACE). */
                        $code = (int)$num;
                        if ($code < 0) { $code += 65536; }
                        if ($skipUntilDepth < 0) {
                            if ($code >= 0xD800 && $code <= 0xDBFF) {
                                // High surrogate — buffer it; nothing to emit yet.
                                $pendingHighSurrogate = $code;
                            } elseif ($code >= 0xDC00 && $code <= 0xDFFF && $pendingHighSurrogate !== null) {
                                // Low surrogate completing a buffered high — combine.
                                $full = 0x10000 + (($pendingHighSurrogate - 0xD800) << 10) + ($code - 0xDC00);
                                $pendingHighSurrogate = null;
                                $ch = function_exists('mb_chr') ? mb_chr($full, 'UTF-8') : null;
                                if ($ch !== null && $ch !== false && !$isFontSuppressed()) { $out .= $ch; }
                            } else {
                                // An orphaned/lone surrogate (no buffered pair) never
                                // combines with what follows — drop it, same as before.
                                $pendingHighSurrogate = null;
                                if ($code === 0x2028 || $code === 0x2029) {
                                    /* Cocoa's \uc0\u8232 idiom for LINE SEPARATOR (and
                                       \u8233 PARAGRAPH SEPARATOR) is a soft line break
                                       in practice, exactly like \par above — fold it to
                                       "\n" rather than emitting the invisible U+2028/29
                                       character verbatim, which would render as nothing
                                       and silently re-join the two lines downstream. */
                                    if (!$isFontSuppressed()) { $out .= "\n"; }
                                } else {
                                    $ch = function_exists('mb_chr') ? mb_chr($code, 'UTF-8') : null;
                                    if ($ch !== null && $ch !== false && !$isFontSuppressed()) { $out .= $ch; }
                                }
                            }
                        }
                        $unicodeSkip = $ucStack[count($ucStack) - 1];
                        break;
                    case 'uc':
                        $ucStack[count($ucStack) - 1] = max(0, (int)$num);
                        break;
                    case 'fs':
                        /* #1968 change 4 — \fsN sets the CURRENT group's RTF
                           half-point font size (see the function doc-block's
                           point 4). Tracked unconditionally — even when
                           $minFontHalfPts === 0 — because the tracking itself
                           is inert bookkeeping; $isFontSuppressed() is what
                           turns it into a no-op for every pre-existing
                           caller, not skipping the tracking. */
                        $fontSizeStack[count($fontSizeStack) - 1] = max(0, (int)$num);
                        break;
                    case 'par':
                    case 'line':
                        if ($skipUntilDepth < 0 && !$isFontSuppressed()) { $out .= "\n"; }
                        break;
                    case 'tab':
                        if ($skipUntilDepth < 0 && !$isFontSuppressed()) { $out .= "\t"; }
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
            $fontSizeStack[] = $fontSizeStack[count($fontSizeStack) - 1]; // #1968 change 4 — inherit, like $ucStack
            $i++;
            continue;
        }
        if ($c === '}') {
            if ($skipUntilDepth >= 0 && $depth <= $skipUntilDepth) {
                $skipUntilDepth = -1;
            }
            if ($depth > 0) { $depth--; }
            if (count($ucStack) > 1) { array_pop($ucStack); }
            if (count($fontSizeStack) > 1) { array_pop($fontSizeStack); } // #1968 change 4
            $i++;
            continue;
        }

        /* Raw CR/LF inside RTF source are not text. */
        if ($c === "\r" || $c === "\n") { $i++; continue; }

        if ($skipUntilDepth < 0) {
            if ($unicodeSkip > 0) { $unicodeSkip--; }
            elseif (!$isFontSuppressed()) { $out .= $c; }
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
    /* @disabled-visible: importer / batch system path (#1765) — operates over all
       songbooks regardless of public disabled state */
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
 *  ProPresenter 7+ import (#1968 PR-1, epic #885)
 * ---------------------------------------------------------------------------
 * ELI5: a `.pro` file is ProPresenter's own binary format (protobuf, not
 * XML like `.pro6`) — `includes/propresenter7_decode.php` already turns those
 * bytes into a plain PHP array (arrangements / cue groups / cues / RTF lyric
 * bytes / CCLI metadata); THIS section turns that array into the same neutral
 * "song" shape every other importer in this file produces
 * ({title,ccli,copyright,writers,components,arrangement}), so it can flow
 * through the exact same `_bulkImport_assembleSong()` -> `_bulkImport_saveSong()`
 * pipeline as Pro6/OpenSong/OpenLyrics/etc. — this file adds ZERO new writes
 * of its own (CLAUDE.md modularity rule + rule #25's ONE write path).
 *
 * DETAILED — closest precedent is `_bulkImport_parsePro6()` /
 * `_bulkImport_processPro6()` just above (same neutral-shape contract, same
 * "file under a fixed catch-all songbook" convention for a format that
 * carries no songbook of its own) — this section mirrors that shape
 * line-for-line rather than inventing a new one. It differs from Pro6 in
 * three structural ways Pro6 never had to handle: (a) a `.pro` carries
 * ARRANGEMENTS (an explicit running order, possibly with repeats — a refrain
 * between every verse) that Pro6 XML has no equivalent of, so this is the
 * FIRST parser in this file that populates the `arrangement` key
 * `_bulkImport_assembleSong()` passes through to `_sanitiseArrangement()`;
 * (b) a `.pro` can carry MULTIPLE text elements per slide (translation
 * layers) that Pro6's single `<RVTextElement>` never does — see the
 * element-selection helper below; (c) a `.pro`'s RTF is genuinely
 * DUAL-DIALECT (Mac `\cocoartf…` soft-return vs. Windows `\rtf0…` `\par`),
 * which is what motivated the three `_bulkImport_rtfToText()` changes
 * directly above this section.
 *
 * See `.claude/propresenter-interop-1968-plan.md` §3.3-3.5 for the full
 * design this section implements precisely (parse walk, element selection,
 * section-label mapping); `tests/php/test-pp7-parse.php` validates every
 * function here against REAL third-party `.pro` fixtures
 * (`tests/fixtures/propresenter/`) with committed expected output — never a
 * self-round-trip through this app's own exporter (the owner's #1 rule for
 * this epic; see the plan's header + §8).
 * =========================================================================== */

/**
 * "Type word" -> its canonical display form ("pre-chorus" -> "Pre-Chorus"),
 * used by _bulkImport_pro7GroupType() to decide whether a raw PP group name
 * carries extra information worth preserving as a display `label` (rule #45)
 * beyond what the resolved `type` + `number` already say. Title-cases each
 * hyphen-separated part, which reproduces the exporter's own
 * COMPONENT_LABEL_MAP `name` strings (propresenter-export.js) for every type
 * that map defines ("Verse", "Pre-Chorus", "Tag", …) without importing that
 * JS file — this is a NEW PHP function with no live cross-reference to the
 * export map, so the reproduction is by consistent RULE (ucfirst each part),
 * not by copying the map's literal strings, which would drift silently if
 * either side changed a spelling.
 */
function _bulkImport_pro7TypeDisplayWord(string $type): string
{
    return implode('-', array_map('ucfirst', explode('-', $type)));
}

/**
 * Reverse-map ONE ProPresenter `rv.data.Group.name` (a cue group's raw
 * label — "Verse 1", "Chorus", "Tag", "Verse 1 (SDAH)", "V2", …) into the
 * iHymns component shape `{type, number, label}` (#1968 plan §3.5).
 *
 * ELI5: ProPresenter's own export (`propresenter-export.js`'s
 * COMPONENT_LABEL_MAP) turns an iHymns component into a PP group name like
 * "Pre-Chorus 2"; this is the reverse trip — given "Pre-Chorus 2" (or a
 * label ProPresenter never generated but a human operator typed, like "V2"
 * or "Verse 1 (SDAH)"), work out which iHymns type it means.
 *
 * DETAILED — a NEW function, deliberately NOT a reuse of
 * `_bulkImport_pro6GroupType()` (which predates the #1138 `tblSongPartTypes`
 * registry and folds `tag`/`coda`/`interlude` onto `outro`/`outro`/`refrain`
 * — those are now real, distinct seeded slugs; silently changing PRO6's
 * fold to match would re-type existing Pro6 round-trip data). This targets
 * the 16 seeded `tblSongPartTypes` slugs (`migrate-song-part-types.php`)
 * PLUS every name/short-letter the export map can emit:
 *   1. Parse the label into {word, number, suffix} via one regex — base
 *      word (lazy, so trailing digits/parenthetical land in their own
 *      groups), an optional trailing integer, and an optional
 *      parenthesised suffix (the real "Verse 1 (SDAH)" hymnal-variant shape
 *      the owner's genuine v21.4 samples carry — the suffix is arrangement-
 *      scoping noise for TYPE purposes, never itself mapped to a type).
 *   2. Fold the base word (case-insensitive) against a static map. Unknown
 *      words (including every non-English section name — "Strophe" is
 *      real, byte-verified fixture coverage, see
 *      tests/fixtures/propresenter/bussnet-stille-nacht.pro) fall to
 *      'refrain', mirroring `_bulkImport_componentTypeFor()`'s established
 *      "non-English label -> refrain" convention (#885).
 *   3. `label` is set to the RAW group name whenever: the word was unknown
 *      (so nothing is lost when we had to guess — rule #45), OR a
 *      parenthesised suffix was present, OR the raw name differs from the
 *      derived "Type Number" display form (`_bulkImport_pro7TypeDisplayWord()`
 *      + a trailing " N" when N>0) — the same equality check
 *      `component_upsert`'s server-side D1 hide-when-equal already applies
 *      (rule #45), applied here locally so the importer never SENDS a
 *      redundant label in the first place. An empty raw name (a genuinely
 *      unnamed PP group — real, see the "" cue group in the Windows feature-
 *      test fixture) never gets a label; there is nothing to preserve.
 *
 * @param string $label the raw `rv.data.Group.name` for one cue group
 * @return array{type:string, number:int, label:?string}
 * @see .claude/propresenter-interop-1968-plan.md   §3.5 (this function's brief)
 * @see appWeb/.sql/migrate-song-part-types.php     the 16 seeded canonical slugs
 * @see appWeb/public_html/manage/editor/propresenter-export.js   the FORWARD map (COMPONENT_LABEL_MAP) this reverses
 */
function _bulkImport_pro7GroupType(string $label): array
{
    $raw = trim($label);

    if (!preg_match(
        '/^(?<word>.*?)[\s_-]*(?<num>\d+)?\s*(?:\((?<suffix>[^)]*)\))?\s*$/u',
        $raw,
        $m
    )) {
        // The pattern's only non-optional piece is a lazy `.*?`, so this is
        // unreachable on any input in practice — kept as a defensive
        // fallback (never throws) rather than an assertion, matching this
        // file's "a parser never throws, it reports [null, reason]" posture.
        $word = $raw; $num = 0; $suffix = '';
    } else {
        $word   = trim((string)($m['word'] ?? ''));
        $num    = isset($m['num']) && $m['num'] !== '' ? (int)$m['num'] : 0;
        $suffix = isset($m['suffix']) ? trim((string)$m['suffix']) : '';
    }

    static $wordMap = null;
    if ($wordMap === null) {
        $wordMap = [
            'verse'       => 'verse',
            'chorus'      => 'chorus',
            'refrain'     => 'refrain',
            'bridge'      => 'bridge',
            'pre-chorus'  => 'pre-chorus', 'prechorus'  => 'pre-chorus', 'pre chorus'  => 'pre-chorus',
            'post-chorus' => 'post-chorus', 'postchorus' => 'post-chorus', 'post chorus' => 'post-chorus',
            'tag'         => 'tag',
            'coda'        => 'coda',
            'intro'       => 'intro',
            'outro'       => 'outro', 'ending' => 'outro',
            'interlude'   => 'interlude',
            'vamp'        => 'vamp',
            'instrumental'=> 'instrumental',
            'breakdown'   => 'breakdown',
            'solo'        => 'solo',
            'ad-lib'      => 'ad-lib', 'adlib' => 'ad-lib', 'ad lib' => 'ad-lib',
            // Short letter forms (plan §3.5 point 2 — PP operators use both
            // full names and single-letter shorthand). 'C'/'T'/'I' are each
            // ambiguous in the FORWARD map too (chorus/refrain share 'C',
            // tag/coda share 'T', intro/interlude share 'I') — these pick
            // the map's first-listed (canonical) meaning for each letter,
            // same choice `propresenter-export.js`'s COMPONENT_LABEL_MAP
            // itself makes implicitly by listing them in this order.
            'v' => 'verse', 'c' => 'chorus', 'b' => 'bridge', 'p' => 'pre-chorus',
            't' => 'tag', 'i' => 'intro', 'o' => 'outro',
        ];
    }

    $wordLower = strtolower($word);
    $known = array_key_exists($wordLower, $wordMap);
    $type  = $known ? $wordMap[$wordLower] : 'refrain';

    $displayLabel = null;
    if ($raw !== '') {
        if (!$known) {
            $displayLabel = $raw;
        } else {
            $derived = _bulkImport_pro7TypeDisplayWord($type) . ($num > 0 ? ' ' . $num : '');
            if ($suffix !== '' || $raw !== $derived) {
                $displayLabel = $raw;
            }
        }
    }

    return ['type' => $type, 'number' => $num, 'label' => $displayLabel];
}

/**
 * Compute the MAXIMUM `\fsN` (RTF half-point font size) used anywhere in ONE
 * `rtf_data` blob — the "dominant font" signal `_bulkImport_pro7SelectCueText()`
 * feeds into `_bulkImport_rtfToText()`'s `$minFontHalfPts` to tell a real
 * lyric run apart from a smaller sub-dominant run (copyright/CCLI-display
 * text, or the small-font RTF-writer artifact this exact defect fix targets
 * — see the correctness-defect note below) merged onto the SAME slide
 * (#1968 PR-1, epic #1968).
 *
 * ELI5: scans one slide's raw RTF text for every "\fs<number>" it can find
 * and returns the biggest one. On a real ProPresenter lyric slide the
 * BIGGEST text is always the lyric itself — never the small print under it
 * — so "biggest font on this slide" is a reliable stand-in for "the actual
 * lyric run."
 *
 * DETAILED — a plain regex scan over the raw bytes (not a full tokeniser
 * walk) is deliberate and sufficient here: this is called with ONE text
 * ELEMENT's `rtf_data` (one `Graphics.Text.rtf_data`, i.e. one entry of a
 * cue's `slideRtf[]`), which never carries a `\fonttbl`/`\colortbl` of its
 * own worth excluding (those are boilerplate header groups every committed
 * fixture's `rtf_data` opens with, and they never contain an `\fsN` — font
 * SIZE information; `\fonttbl` only maps font NAMES to numeric `\fN`
 * indices, a different control word entirely — verified against every
 * committed fixture during implementation). `_bulkImport_rtfToText()` itself
 * still does the CORRECT group-scoped tracking for the case that DOES
 * matter (an `\fsN` inside a skipped destination group must never leak into
 * the "current size" the surrounding lyric text is compared against) — this
 * helper only picks the THRESHOLD passed into that function; it never
 * decides what to suppress itself.
 *
 * CORRECTNESS-DEFECT CONTEXT: two real Mac-exported fixtures
 * (`v7-at-the-cross-mac.pro`, `v7-come-thou-fount-mac.pro`) prefix every
 * lyric text run with a small `\f0\fs24 \cf0 ',` RTF-writer artifact
 * immediately before the real `\f1\fs120 …` lyric run, in the SAME
 * `rtf_data`, no paragraph break between them — before this fix that
 * literal "'," was concatenated onto the front of the first real lyric
 * line ("',I know a place"). Calling `_bulkImport_rtfToText($rtf, $maxFs)`
 * with `$maxFs` = this function's return value drops the `\fs24` run and
 * keeps the `\fs120` lyric; a single-font slide is unaffected (its only
 * size IS the max, so nothing compares as smaller than it).
 *
 * @param string $rtf one RTF document (a single text element's `rtf_data`,
 *                     not a whole presentation)
 * @return int the largest `\fsN` value found (half-points), or 0 when the
 *             RTF carries no `\fsN` at all — 0 means "no filtering",
 *             matching `_bulkImport_rtfToText()`'s own `$minFontHalfPts = 0`
 *             default.
 * @see _bulkImport_rtfToText()                     the function this feeds `$minFontHalfPts` into
 * @see .claude/propresenter-interop-1968-plan.md   PR-1 dominant-font correctness-defect fix
 */
function _bulkImport_pro7RtfMaxFontHalfPts(string $rtf): int
{
    if ($rtf === '' || strpos($rtf, '\\fs') === false) {
        return 0;
    }
    if (!preg_match_all('/\\\\fs(\d+)/', $rtf, $m)) {
        return 0;
    }
    $max = 0;
    foreach ($m[1] as $v) {
        $n = (int)$v;
        if ($n > $max) { $max = $n; }
    }
    return $max;
}

/**
 * Select ONE cue's "lyric" text + count how many of its OTHER elements carry
 * real (non-empty, once RTF-stripped) text — the element-selection rule from
 * #1968 plan §3.4.
 *
 * ELI5: a ProPresenter slide can have more than one text box on it (the
 * real lyrics, plus e.g. a translation running underneath). We only import
 * the FIRST one — anything else is counted so the caller can tell the
 * curator "N translation layers were skipped" instead of silently losing
 * them with no trace.
 *
 * DETAILED: "first" is by `Slide.Element.info` bit 2 (IS_TEXT_ELEMENT) —
 * the first element with that bit set AND a non-empty `rtf_data`; if NONE
 * qualifies (real fixture: `db551011-…` in v7-feature-test-win.pro, whose
 * only bit-2 element decodes to no visible text — see the plan's "empty
 * slides" coverage note), fall back to the first element with any non-empty
 * `rtf_data` at all; if that also finds nothing, the cue contributes no
 * text. Byte-verified against TWO real multi-element cues: `bussnet-test.pro`'s
 * "Ending" cue (a genuine original/translated pair — "Trans Original
 * 1↵Trans Original 2" / "Translated 1↵Translated 2") and
 * `v7-feature-test-win.pro`'s cues (two same-bit-mask text runs on one
 * slide — the mechanism does not need the layers to be literally different
 * LANGUAGES, only additional non-empty text elements it must not silently
 * merge into the first).
 *
 * PR-1 correctness-defect fix — EACH element's text (the chosen one AND
 * every "other" element counted below) is now extracted through
 * `_bulkImport_pro7RtfMaxFontHalfPts()` + `_bulkImport_rtfToText($rtf,
 * $maxFs)`: the dominant (largest) font run on that SPECIFIC element is
 * kept, any smaller run merged into the SAME `rtf_data` is dropped. This is
 * deliberately PER-ELEMENT, not a file-wide or cross-cue threshold — a
 * cross-cue threshold was tried and rejected during implementation because
 * it silently swallows genuinely-real content: `bussnet-test.pro`'s
 * "Ending" cue pairs a chosen `\fs84` element with an unrelated `\fs80`
 * "other" (translation-layer) element, and `v7-feature-test-win.pro` has
 * several single-font cues at DIFFERENT absolute sizes (68/92/100/140/
 * 142/200) that are each individually dominant on their own slide — a
 * shared file-wide max would wrongly suppress the smaller ones. Per-element
 * scoping leaves every one of those untouched (see the non-regression rows
 * in tests/php/test-pp7-parse.php) while still fixing the two-font-in-one-
 * element defect this was written for.
 *
 * @param array $cue one `pp7DecodePresentation()` cue: {uuid, slideRtf[],
 *                    slideElementInfos[], mediaRefs}
 * @param int   &$translationLayerCount running total the caller aggregates
 *                    into ONE summary warning (never per-cue — plan §3.4:
 *                    "P1 imports element 0 only and appends ONE summary
 *                    warning")
 * @return string the selected element's UTF-8 plain text (via
 *                 `_bulkImport_rtfToText()`), or '' when the cue carries no
 *                 usable text at all
 */
function _bulkImport_pro7SelectCueText(array $cue, int &$translationLayerCount): string
{
    $rtfList  = $cue['slideRtf'] ?? [];
    $infoList = $cue['slideElementInfos'] ?? [];
    $n        = count($rtfList);
    if ($n === 0) {
        return '';
    }

    $chosen = null;
    for ($idx = 0; $idx < $n; $idx++) {
        if ((((int)($infoList[$idx] ?? 0)) & 2) !== 0 && ($rtfList[$idx] ?? '') !== '') {
            $chosen = $idx;
            break;
        }
    }
    if ($chosen === null) {
        for ($idx = 0; $idx < $n; $idx++) {
            if (($rtfList[$idx] ?? '') !== '') {
                $chosen = $idx;
                break;
            }
        }
    }
    if ($chosen === null) {
        return '';
    }

    $chosenRtf = (string)$rtfList[$chosen];
    $text      = _bulkImport_rtfToText($chosenRtf, _bulkImport_pro7RtfMaxFontHalfPts($chosenRtf));

    for ($idx = 0; $idx < $n; $idx++) {
        if ($idx === $chosen) { continue; }
        $otherRtf = (string)($rtfList[$idx] ?? '');
        $other    = $otherRtf !== '' ? _bulkImport_rtfToText($otherRtf, _bulkImport_pro7RtfMaxFontHalfPts($otherRtf)) : '';
        if (trim($other) !== '') {
            $translationLayerCount++;
        }
    }

    return $text;
}

/**
 * Append one cue's selected text onto a GROUP's accumulating `$lines` array,
 * using the exact concatenation idiom `_bulkImport_parsePro6()` already
 * uses at L3798-3806 (skip LEADING empty lines; keep interior/trailing ones
 * — the caller does ONE trailing-blank trim pass after every cue in the
 * group has been appended, matching how the exporter chunks one group's
 * lyrics across several slides with `linesPerSlide`).
 *
 * @param array<int,string> &$lines the group's line accumulator (mutated)
 * @param string             $text  one cue's already-RTF-stripped text
 */
function _bulkImport_pro7AppendCueLines(array &$lines, string $text): void
{
    foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $text)) as $ln) {
        $ln = rtrim($ln);
        if ($ln !== '' || !empty($lines)) {
            $lines[] = $ln;
        }
    }
}

/**
 * The ONE `.pro` content-sniff (#1968 P0/P1, plan §3.1) — `.pro` is
 * genuinely ambiguous: ChordPro's own documentation blesses the extension,
 * AND ProPresenter 6/7+ both use it, so routing by extension alone
 * mis-parses real files as text. This is the SERVER-side, AUTHORITATIVE
 * sniff — the one thing every client-side guess (editor.js's
 * `sniffProContent()`) and every server entry point (api2.php's
 * `import_file` `'proauto'` target, this file's own `_bulkImport_processZip()`
 * per-entry router) must resolve to before doing any real parsing. A wrong
 * CLIENT route can never corrupt data because this function re-decides from
 * the bytes themselves, never trusting the caller's guess.
 *
 * ELI5: look at the first few KB of the file. If it's normal readable text
 * with no weird invisible control characters, it's either an old
 * ProPresenter 6 XML file or a plain ChordPro song sheet — check for the
 * XML tag to tell those two apart. If it's NOT readable text (raw binary
 * bytes, the way a real ProPresenter 7+ file always is), it must be a
 * ProPresenter 7+ protobuf.
 *
 * Rule (plan §3.1, verbatim):
 *   - decodes as clean UTF-8 text (no NUL/control bytes outside \t\r\n):
 *       - starts with an XML prolog (the "xml" processing instruction)
 *         or contains an <RVPresentationDocument element
 *         -> ProPresenter 6 ('pro6') — a mis-extensioned .pro6 import still
 *            works instead of erroring.
 *       - else -> ChordPro ('chordpro') — unchanged behaviour for genuine
 *         ChordPro `.pro` uploads.
 *   - otherwise (invalid UTF-8, or a control byte outside \t\r\n — every
 *     real PP7 protobuf trips this within ~100 bytes: varint field tags,
 *     raw float colour components, length-delimited byte slices)
 *     -> ProPresenter 7+ ('pro7').
 *
 * Only the first 4 KB is sniffed — plenty to see the RTF/protobuf
 * character within any real file, and cheap even on a large upload.
 *
 * @param string $body raw bytes of the uploaded `.pro` file (full body or
 *                      just a lead sample — only the first 4 KB is used)
 * @return string one of 'pro6' | 'pro7' | 'chordpro'
 * @see .claude/propresenter-interop-1968-plan.md §3.1
 * @see appWeb/public_html/manage/editor/editor.js  sniffProContent() — the client-side convenience twin
 */
function _bulkImport_sniffProDialect(string $body): string
{
    $sample = substr($body, 0, 4096);

    /* mb_check_encoding() rejects any byte sequence that is not valid
       UTF-8 outright — this alone already catches most real PP7 files
       (raw binary is very unlikely to happen to be well-formed UTF-8). */
    $isCleanText = $sample === '' || mb_check_encoding($sample, 'UTF-8');

    /* Belt-and-braces: even when the bytes happen to decode as valid
       UTF-8, a genuine text file (RTF/XML/ChordPro) never contains a
       C0 control byte other than \t (0x09) \r (0x0D) \n (0x0A), or the
       C1 DEL byte (0x7F). Protobuf's varint/length-delimited encoding
       produces these constantly. */
    if ($isCleanText && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $sample) === 1) {
        $isCleanText = false;
    }

    if (!$isCleanText) {
        return 'pro7';
    }

    $trimmedStart = ltrim($sample);
    if (str_starts_with($trimmedStart, '<?xml') || str_contains($sample, '<RVPresentationDocument')) {
        return 'pro6';
    }

    return 'chordpro';
}

/**
 * Parse one ProPresenter 7+ `.pro` document body into the neutral parsed
 * structure `_bulkImport_assembleSong()` consumes (#1968 plan §3.3-3.4).
 * PURE — no DB access, so `tests/php/test-pp7-parse.php` can unit-test it
 * directly against committed binary fixtures with no database at all
 * (mirrors `_bulkImport_parsePro6()`'s posture, and the decoder's own
 * `includes/propresenter7_decode.php` purity this function builds on).
 *
 * THE WALK (plan §3.3), in order:
 *   1. `pp7DecodePresentation()` the raw bytes (catches its
 *      `\InvalidArgumentException` -> `[null, reason]`, never propagates a
 *      throw out of a parser — rule #9/§3.7's "a parser reports, it does
 *      not throw" posture every OTHER parser in this file already keeps).
 *   2. Category gate — `category` non-empty and case-insensitively != "song"
 *      is rejected ("This is a ProPresenter <category> document, not a
 *      song."); the ABSENT case (proto3 default `''`, every genuine song
 *      sample this epic has seen) always passes.
 *   3. Section palette: ONE component per `cueGroups[]` ENTRY, in
 *      `cueGroups[]`'s own declared order (NOT the arrangement's walk order
 *      — the arrangement is resolved SEPARATELY in step 5 below and maps
 *      onto indices into THIS palette; a real fixture,
 *      `bussnet-test.pro`, has a cue group — "Ending" — that no arrangement
 *      in the file references at all, and it still becomes a real palette
 *      component). "Song Title" / "Lyrics Background" / "Blank"
 *      (case-insensitive exact match) are skipped outright — the three
 *      non-lyric groups real files carry. "Blank" was ADDED for the PR-1
 *      correctness-defect fix: both `v7-*-mac.pro` fixtures carry a genuine
 *      "Blank" group whose sole cue is ENTIRELY the small-font RTF-writer
 *      artifact this fix targets (`\f0\fs24 \cf0 ','`, no larger-font
 *      sibling anywhere in that SAME element) — font-size filtering alone
 *      (point 4 above / `_bulkImport_pro7SelectCueText()`'s doc-block)
 *      provably CANNOT drop it: with no larger run on that specific slide
 *      to compare against, the small run IS that slide's dominant (only)
 *      font, so nothing is ever suppressed, and the group survives as a
 *      spurious one-line "'," component. Widening the font-comparison scope
 *      to reach outside that one slide (file-wide, or borrowed from a
 *      sibling group) was tried and rejected during implementation — it
 *      breaks real content in two OTHER fixtures that must stay
 *      byte-identical (`bussnet-test.pro`'s "Ending" cue legitimately pairs
 *      an `\fs84` chosen run with an unrelated `\fs80` translation-layer
 *      run; `v7-feature-test-win.pro` has several single-font cues at
 *      different absolute sizes that are each individually dominant on
 *      their own slide). "Blank" name-matching is the same, already-proven
 *      mechanism this function uses for "Song Title"/"Lyrics Background" —
 *      confirmed safe by scanning every committed fixture's group names
 *      during implementation (no OTHER fixture has a "Blank" group with
 *      real lyric content). Every OTHER group's lines are the concatenation
 *      of each of its cues' selected text (§3.4); a group whose accumulated
 *      lines end up empty (every cue empty, OR every cue unresolvable) is
 *      DROPPED with a warning, exactly mirroring Pro6's own "drop an empty
 *      grouping" precedent.
 *   4. Unreferenced cues (present in `cues[]` but in NO group's
 *      `cueIdentifiers`) are appended, in `cues[]` order, as sequential
 *      `verse` components with a warning each — a defensive net for a
 *      malformed/hand-edited file, not something any committed fixture
 *      exercises (none of the 9 real `.pro` fixtures in this corpus has
 *      one — verified by scanning every fixture's cue/group union during
 *      implementation).
 *   5. Arrangement resolution — completely separate from step 3's palette
 *      build: pick `selectedArrangement` when it resolves to a real
 *      `arrangements[]` entry; else the first arrangement named
 *      CCLI/Standard/Original (case-insensitive); else `arrangements[0]`;
 *      else none. A SET-but-unresolvable `selected_arrangement` (real:
 *      `v7-feature-test-win.pro` — Windows PP 7.13.2 sets one while
 *      `arrangements[]` is entirely empty) is advisory-only, never an
 *      error — it just falls through the same ladder. The chosen
 *      arrangement's `groupIdentifiers` (repeats allowed and expected — a
 *      refrain between every verse) are mapped to the PALETTE's indices
 *      (built in step 3, which may have fewer entries than `cueGroups[]`
 *      once skipped/empty groups are dropped); an identity mapping
 *      (`[0,1,2,…]`) is stored as `null` (matches the render fallback,
 *      avoids noise on the common no-repeats case).
 *   6. Metadata from `ccli` (Pro6's exact ladder): `song_title` -> title
 *      (fallback `Presentation.name`, then the first lyric line — the
 *      filename-stem fallback is the PROCESSOR's job, which alone has the
 *      upload filename); `author` split on `[/&,;]` -> `writers[]`;
 *      `publisher` + `copyright_year` -> `copyright` string;
 *      `song_number` -> `ccli`. `artist_credits` is NOT imported (no
 *      importer in this file writes `tblSongArtists` yet — #587-gated) but
 *      is never silently dropped either: its presence rides a warning.
 *
 * @param string $body raw bytes of one `.pro` file
 * @return array{0: ?array, 1: ?string}  [parsed, errorReason] — parsed carries
 *         {title, songbookName:'', entry:0, language:'', ccli, copyright,
 *         writers[], components[], arrangement:?int[], warnings[],
 *         mediaRefs?:array, timeline?:array{duration:?float,loop:bool,cues:array}}
 *         (`mediaRefs`/`timeline` are SPARSE — present only when non-empty)
 * @see .claude/propresenter-interop-1968-plan.md   §3.3 (the walk), §3.4 (element selection), §3.5 (label mapping)
 * @see includes/propresenter7_decode.php           pp7DecodePresentation() — the decoder this builds on
 * @see includes/pp7_timeline.php                    pp7TimelineStore() — the (dormant/gated) DB write side
 * @see tests/php/test-pp7-parse.php                 real-fixture validation
 */
function _bulkImport_parsePro7(string $body): array
{
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'propresenter7_decode.php';

    try {
        $decoded = pp7DecodePresentation($body);
    } catch (\Throwable $e) {
        return [null, 'ProPresenter 7+ decode failed: ' . $e->getMessage()];
    }

    /* §3.3 point 2 — category gate. proto3's default for an unset string
       field is '' (never null), so an absent category — every genuine song
       sample this epic has seen — always passes; only an EXPLICIT non-song
       category (e.g. "Scripture", "Notes") is rejected. */
    $category = trim((string)($decoded['category'] ?? ''));
    if ($category !== '' && strcasecmp($category, 'song') !== 0) {
        return [null, "This is a ProPresenter {$category} document, not a song."];
    }

    $warnings = [];

    /* Index cues by uuid for O(1) group-walk lookups. A cue with no uuid at
       all (malformed input) simply can never be referenced or resolved —
       consistent with every uuid-keyed lookup elsewhere in this walk. */
    $cuesByUuid = [];
    foreach ($decoded['cues'] as $cue) {
        if (($cue['uuid'] ?? '') !== '') {
            $cuesByUuid[$cue['uuid']] = $cue;
        }
    }

    $translationLayerCount = 0;

    /* §3.3 point 3 — section palette: one component per cueGroups[] entry,
       in cueGroups[]'s own declared order. $paletteGroupUuidToIndex records
       where each SURVIVING group landed, for step 5's arrangement mapping —
       built from the surviving component list, per plan §3.3 point 5
       ("skipped/empty groups shift indices, so the map is built from the
       surviving component list, not the raw cue_groups array"). */
    $components              = [];
    $paletteGroupUuidToIndex = [];
    foreach ($decoded['cueGroups'] as $group) {
        $groupName   = (string)($group['groupName'] ?? '');
        $trimmedName = trim($groupName);

        if (strcasecmp($trimmedName, 'Song Title') === 0
            || strcasecmp($trimmedName, 'Lyrics Background') === 0
            || strcasecmp($trimmedName, 'Blank') === 0
        ) {
            // "Blank" added for the PR-1 correctness-defect fix — see this
            // function's doc-block point 3 for the full "why font-size
            // filtering alone can't reach this one" reasoning.
            //
            // PR-1 fix: a skipped group's own cues must ALSO be marked
            // consumed here (unset from $cuesByUuid), exactly like the real
            // per-cue walk below does — otherwise they fall through to step
            // 4's "unreferenced cue" defensive net and get RE-APPENDED as a
            // sequential verse component, completely defeating the skip
            // (this was a latent bug: no committed fixture exercised a
            // named-skip group with real cueIdentifiers until "Blank" was
            // added above — "Song Title"/"Lyrics Background" never appear
            // in any fixture in this corpus).
            foreach ((array)($group['cueIdentifiers'] ?? []) as $skippedCueUuid) {
                unset($cuesByUuid[$skippedCueUuid]);
            }
            $warnings[] = "skipped non-lyric group \"{$trimmedName}\"";
            continue;
        }

        $lines = [];
        foreach ((array)($group['cueIdentifiers'] ?? []) as $cueUuid) {
            if (!isset($cuesByUuid[$cueUuid])) {
                $warnings[] = "cue {$cueUuid} referenced by group \"{$trimmedName}\" was not found";
                continue;
            }
            $text = _bulkImport_pro7SelectCueText($cuesByUuid[$cueUuid], $translationLayerCount);
            _bulkImport_pro7AppendCueLines($lines, $text);
            /* Mark as referenced — whatever remains in $cuesByUuid after
               every group has been walked is step 4's "unreferenced cues". */
            unset($cuesByUuid[$cueUuid]);
        }
        while (!empty($lines) && trim((string)end($lines)) === '') {
            array_pop($lines);
        }
        if (empty($lines)) {
            $warnings[] = $trimmedName !== ''
                ? "group \"{$trimmedName}\" had no lyric text — skipped"
                : 'an unnamed group had no lyric text — skipped';
            continue;
        }

        $mapped    = _bulkImport_pro7GroupType($groupName);
        $component = ['type' => $mapped['type'], 'number' => $mapped['number'], 'lines' => $lines];
        if ($mapped['label'] !== null) {
            $component['label'] = $mapped['label'];
        }
        $components[] = $component;
        if (($group['groupUuid'] ?? '') !== '') {
            $paletteGroupUuidToIndex[$group['groupUuid']] = count($components) - 1;
        }
    }

    /* §3.3 point 4 — unreferenced cues: present in cues[] but consumed by
       NO group above (every referenced cue was unset() from $cuesByUuid as
       it was walked, so whatever remains here was never referenced at
       all). Appended in original cues[] order as sequential verses. */
    if (!empty($cuesByUuid)) {
        $vnum = 0;
        foreach ($decoded['cues'] as $cue) {
            $uuid = $cue['uuid'] ?? '';
            if ($uuid === '' || !isset($cuesByUuid[$uuid])) {
                continue; // referenced by a group, or carries no uuid at all
            }
            $text  = _bulkImport_pro7SelectCueText($cue, $translationLayerCount);
            $lines = [];
            _bulkImport_pro7AppendCueLines($lines, $text);
            while (!empty($lines) && trim((string)end($lines)) === '') {
                array_pop($lines);
            }
            if (empty($lines)) {
                continue; // an unreferenced, empty cue contributes nothing
            }
            $vnum++;
            $components[] = ['type' => 'verse', 'number' => $vnum, 'lines' => $lines];
            $warnings[]   = "cue {$uuid} was not referenced by any group — appended as verse {$vnum}";
        }
    }

    if (empty($components)) {
        return [null, 'no lyric text found in ProPresenter 7+ document'];
    }

    if ($translationLayerCount > 0) {
        $warnings[] = "{$translationLayerCount} translation layer(s) present — not imported"
            . ' (see the per-line-translations follow-up, plan §3.4)';
    }

    /* §3.3 point 5 — arrangement resolution, entirely separate from the
       palette built above. */
    $arrangement       = null;
    $chosenArrangement = null;
    $selUuid           = $decoded['selectedArrangement'];
    if ($selUuid !== null) {
        foreach ($decoded['arrangements'] as $a) {
            if (($a['uuid'] ?? '') === $selUuid) {
                $chosenArrangement = $a;
                break;
            }
        }
        if ($chosenArrangement === null) {
            /* Dangling selected_arrangement — real, byte-verified fixture:
               v7-feature-test-win.pro sets one while arrangements[] is
               entirely empty. Advisory only, never an error (plan §3.3
               point 5's parenthetical). */
            $warnings[] = 'selected_arrangement did not resolve to an arrangement — falling back to natural order';
        }
    }
    if ($chosenArrangement === null) {
        foreach ($decoded['arrangements'] as $a) {
            if (in_array(strtolower((string)($a['name'] ?? '')), ['ccli', 'standard', 'original'], true)) {
                $chosenArrangement = $a;
                break;
            }
        }
    }
    if ($chosenArrangement === null && !empty($decoded['arrangements'])) {
        $chosenArrangement = $decoded['arrangements'][0];
    }

    if ($chosenArrangement !== null) {
        $indices = [];
        foreach ((array)($chosenArrangement['groupIdentifiers'] ?? []) as $gid) {
            if (isset($paletteGroupUuidToIndex[$gid])) {
                $indices[] = $paletteGroupUuidToIndex[$gid];
            } else {
                $warnings[] = "arrangement referenced an unresolved group {$gid}";
            }
        }
        $identity = range(0, count($components) - 1);
        if (!empty($indices) && $indices !== $identity) {
            $arrangement = $indices;
        }
    }

    /* §3.3 point 6 — metadata from ccli, Pro6's exact ladder
       (_bulkImport_parsePro6() L3776-3790). */
    $ccli  = $decoded['ccli'];
    $title = trim((string)($ccli['songTitle'] ?? ''));
    if ($title === '') { $title = trim((string)($decoded['name'] ?? '')); }
    if ($title === '') { $title = trim((string)($components[0]['lines'][0] ?? '')); }
    // (the filename-stem fallback rung is the PROCESSOR's job — only it has the upload filename.)

    $author  = trim((string)($ccli['author'] ?? ''));
    $writers = [];
    if ($author !== '') {
        foreach (preg_split('/\s*[\/&,;]\s*/u', $author) as $w) {
            $w = trim((string)$w);
            if ($w !== '') { $writers[] = $w; }
        }
    }

    $publisher = trim((string)($ccli['publisher'] ?? ''));
    $year      = $ccli['copyrightYear'] !== null ? (string)$ccli['copyrightYear'] : '';
    $copyright = trim(($publisher !== '' ? $publisher : '') . ($year !== '' ? ($publisher !== '' ? ' ' : '') . $year : ''));

    $ccliNumber = $ccli['songNumber'] !== null ? (string)$ccli['songNumber'] : '';

    $artistCredits = trim((string)($ccli['artistCredits'] ?? ''));
    if ($artistCredits !== '') {
        $warnings[] = "artist_credits \"{$artistCredits}\" is not imported (no tblSongArtists writer yet)";
    }

    if ($title === '') {
        return [null, 'no song title (no CCLI song_title, no presentation name, no slide text)'];
    }

    /* #1968 P4 — surface the decoder's per-cue media references (#853 media
       ingest, plan §6.4). Flattened in cue order, deduped on the
       {absoluteString, localRoot, localPath} triple. Emitted SPARSELY (the
       rule-#45 sparse-`label` precedent): the key is present ONLY when the
       document actually references media, so only the media-bearing fixtures'
       expected JSONs gain it, not all of them. The parser only EXPOSES the
       refs — resolution + ingest happen in the bundle/playlist processors,
       which have the container bytes to resolve them against. */
    $mediaRefs = [];
    $seenRefs  = [];
    foreach ($decoded['cues'] as $cue) {
        foreach ((array)($cue['mediaRefs'] ?? []) as $ref) {
            $abs  = $ref['absoluteString'] ?? null;
            $root = $ref['localRoot'] ?? null;
            $path = $ref['localPath'] ?? null;
            $key  = ($abs ?? '') . "\x1f" . ($root ?? '') . "\x1f" . ($path ?? '');
            if (isset($seenRefs[$key])) { continue; }
            $seenRefs[$key] = true;
            $mediaRefs[] = ['absoluteString' => $abs, 'localRoot' => $root, 'localPath' => $path];
        }
    }

    $result = [
        'title'        => $title,
        'songbookName' => '',   // .pro carries no songbook; caller supplies one
        'entry'        => 0,
        'language'     => '',
        'ccli'         => $ccliNumber,
        'copyright'    => $copyright,
        'writers'      => $writers,
        'components'   => $components,
        'arrangement'  => $arrangement,
        'warnings'     => $warnings,
    ];
    if (!empty($mediaRefs)) {
        $result['mediaRefs'] = $mediaRefs;
    }

    /* #1968 dormant groundwork — carry the decoder's captured auto-advance timeline through
       (owner steer 2026-08-28: capture on import, OFF by default via a toggle, played back
       later). Emitted SPARSELY (the rule-#45 sparse-key precedent, same posture as mediaRefs
       immediately above): every real committed fixture decodes SOME `timeline` submessage —
       ProPresenter appears to always write a placeholder one (duration=300, zero cues) even
       on a song with no real auto-advance schedule — so gating on mere non-null presence would
       put a content-free `timeline` key on every parsed song. The key is present only when
       there is at least one actual cue to capture; the only consumer (the ingest step's
       pp7TimelineStore() call, itself gated on the pp7_timeline_import_enabled toggle) has
       nothing to store for an empty/placeholder timeline anyway. Carried through UNCHANGED
       (pp7DecodeTimeline()'s own {duration,loop,cues:[{triggerSeconds,cueUuid,name}]} shape) —
       this function does no timeline INTERPRETATION, only decode + sparse carry-through; DB
       storage happens one layer up, in the bundle/playlist ingest step. */
    if ($decoded['timeline'] !== null && !empty($decoded['timeline']['cues'])) {
        $result['timeline'] = $decoded['timeline'];
    }

    return [$result, null];
}

/**
 * Synchronous single-file ProPresenter 7+ import — mirrors
 * `_bulkImport_processPro6()`'s shape exactly (dedupe wiring lives inside
 * `_bulkImport_saveSong()` itself, per rule #22 — nothing bespoke needed
 * here). `.pro` carries no songbook, so — same convention as Pro6's "PP6" —
 * every import files under a fixed catch-all "ProPresenter 7 Import" (abbr
 * "PP7") songbook, unless the upload filename carries a bracketed override
 * token (`_bulkImport_videopsalmAbbrevFromHint()`, the same helper PP6
 * uses).
 *
 * @param string      $body         raw bytes of one `.pro` file
 * @param string|null $filenameHint original upload filename — used for the
 *                                  bracketed-abbreviation override, the
 *                                  title fallback's LAST rung (no importer
 *                                  above `_bulkImport_parsePro7()` sees the
 *                                  filename, only the processor does), and
 *                                  error reporting
 * @return array same summary shape as `_bulkImport_processPro6()`, plus a
 *               `warnings[]` key (the `_bulkImport_processPptx()` precedent
 *               for a non-fatal-issues channel on the summary) carrying
 *               every §3.7 warning (skipped groups, translation layers,
 *               unresolved arrangement uuids, artist_credits) so the
 *               curator sees an honest report even on a clean 'ok' import.
 *               Also carries `song_id`/`title`/`songbook_abbr`/`number` —
 *               the ATTEMPTED insert's own identity (#1968 PR-3,
 *               `.proplaylist` import): a playlist's presentation items need
 *               to know WHICH SongId a given `.pro` entry resolved to so it
 *               can be placed into a set list's `SongsJson`. Purely
 *               additive — every existing caller reads named keys off this
 *               array, never the whole shape, so a new key changes nothing
 *               for them. On 'skipped' this is the FRESH id that was never
 *               inserted (`_bulkImport_saveSong()` does not report which
 *               existing row it matched) — the `.proplaylist` importer
 *               re-derives the real existing row via
 *               `_bulkImport_proplaylistResolveSkippedSong()` rather than
 *               trusting this value on that path; see that function's
 *               doc-block.
 */
function _bulkImport_processPro7(string $body, ?string $filenameHint = null, bool $warnUnembeddedMedia = false): array
{
    /* @disabled-visible: importer / batch system path (#1765) — operates over all
       songbooks regardless of public disabled state */
    [$parsed, $reason] = _bulkImport_parsePro7($body);
    if ($parsed === null) {
        return [
            'ok'                     => false,
            'error'                  => $reason ?: 'ProPresenter 7+ parse failed',
            'songbooks_created'      => [],
            'songbooks_existing'     => [],
            'songs_created'          => 0,
            'songs_skipped_existing' => 0,
            'songs_failed'           => 0,
            'parsed_by_format'       => ['propresenter7' => 0],
            'errors'                 => [],
            'warnings'               => [],
        ];
    }

    /* Title fallback's LAST rung — the filename stem — needs the upload
       filename, which only this processor (not the pure parser above) has.
       Mirrors _bulkImport_parsePro6()'s own "caller may further fall back
       to the filename" comment for the identical situation. */
    if ($parsed['title'] === '' && $filenameHint !== null) {
        $stem = pathinfo($filenameHint, PATHINFO_FILENAME);
        if ($stem !== '') {
            $parsed['title'] = $stem;
        }
    }

    /* .pro has no songbook — file it under a single "ProPresenter 7 Import"
       songbook (abbr "PP7", or a bracketed token from the upload filename),
       exactly like PP6's precedent at L4083-4088. */
    $db       = getDbMysqli();
    $bookName = 'ProPresenter 7 Import';
    $abbr     = _bulkImport_videopsalmAbbrevFromHint($filenameHint, '');
    if ($abbr === 'VP' || $abbr === '') { $abbr = 'PP7'; }

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
            'entry' => ($filenameHint ?? 'pro7') . ': ' . ($song['id'] ?? '?'),
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

    $summary = [
        'ok'                     => true,
        'songbooks_created'      => $songbooksCreated,
        'songbooks_existing'     => $songbooksExisting,
        'songs_created'          => $songsCreated,
        'songs_skipped_existing' => $songsSkipped,
        'songs_failed'           => $songsFailed,
        'parsed_by_format'       => ['propresenter7' => $songsCreated + $songsSkipped],
        'errors'                 => $errors,
        'warnings'               => $parsed['warnings'] ?? [],
        /* #1968 PR-3 — see this function's doc-block @return note. */
        'song_id'                => $song['id'] ?? null,
        'title'                  => $song['title'] ?? '',
        'songbook_abbr'          => $abbr,
        'number'                 => $number,
    ];
    /* #1968 P4 — carry the parsed media references up SPARSELY (the `song_id`
       precedent — every caller reads named keys). The bundle/playlist
       processors read this to ingest a resolved media file into tblSongMedia. */
    if (!empty($parsed['mediaRefs'])) {
        $summary['media_refs'] = $parsed['mediaRefs'];
        /* Only a BARE `.pro` upload warns here: a lone `.pro` references media
           by absolute path but carries no container to resolve it against, so
           nothing can be ingested (the owner's real `Here To Stay …[Video].pro`
           is exactly this shape). The bundle/playlist processors pass false —
           they DO resolve + ingest, so this line would be misleading there. */
        if ($warnUnembeddedMedia) {
            $n = count($parsed['mediaRefs']);
            $summary['warnings'][] = "{$n} media reference(s) are not embedded in a .pro file — "
                . 'export a .probundle from ProPresenter to bring the media across.';
        }
    }

    /* #1968 dormant groundwork — capture the auto-advance timeline (owner steer 2026-08-28:
       capture on import, OFF by default via a toggle, played back later). Placed HERE, not
       deferred to the bundle/playlist orchestrators the way media ingest is (see
       _bulkImport_pp7IngestMedia()'s call sites), because — unlike media — a timeline needs
       NO container bytes to resolve: everything pp7TimelineStore() needs (the DB handle, the
       just-created SongId, the decoded timeline) is already in scope right here, for every
       pathway that funnels through this ONE shared single-file pipeline (bare `.pro` upload,
       `.probundle` per-entry, `.proplaylist` embedded song alike — rule #22, no duplicated
       call site in each of the three outer processors).
       Only on a genuine 'create': _bulkImport_saveSong()'s own doc-block warns that on
       'skipped' (a re-imported duplicate) $song['id'] is a FRESH id that was NEVER inserted,
       not the real pre-existing row — storing against it would either silently attach the
       timeline to a nonexistent SongId (the FK rejects it, caught below, a no-op) or, worse,
       corrupt data on a future SongId reuse. Capturing on re-import of a duplicate is future
       work; this task's scope is dormant capture on fresh import.
       NON-BLOCKING: wrapped in its OWN try/catch (pp7TimelineStore() already guards itself
       internally too — this is defence in depth) so a timeline hiccup can never fail the
       surrounding song import, exactly like the media ingest call above. VERIFIED NO-OP by
       construction while the toggle is at its shipped '0' default: pp7TimelineStore()'s own
       first gate check (pp7TimelineImportEnabled()) returns 0 before touching the database at
       all. */
    if ($action === 'create' && !empty($parsed['timeline']) && ($song['id'] ?? '') !== '') {
        try {
            require_once __DIR__ . DIRECTORY_SEPARATOR . 'pp7_timeline.php';
            pp7TimelineStore($db, (string)$song['id'], '', $parsed['timeline']);
        } catch (\Throwable $_e) {
            // Non-blocking: a timeline-capture hiccup never fails the song import.
        }
    }

    return $summary;
}

/* ===========================================================================
 *  ProPresenter 7+ `.probundle` import (#1968 PR-2, epic #885's "bundle
 *  support follows" rider)
 * ---------------------------------------------------------------------------
 * ELI5: a `.probundle` is ProPresenter's "everything in one file" export —
 * a ZIP (opened via the tolerant `pp7ZipListEntries()`/`pp7ZipReadEntry()`
 * reader in `includes/propresenter7_zip.php`, NEVER `\ZipArchive` — see that
 * file's doc-block for the byte-verified reason a real PP7 export needs a
 * reader that never touches the central directory) holding one or more
 * `.pro` presentations at its ROOT plus whatever media (images/video/audio)
 * those presentations reference. `.claude/propresenter-interop-1968-plan.md`
 * §4 records the byte-verified layout facts this section relies on: NO
 * manifest file exists in a genuine bundle — the inner `.pro`(s) ARE the
 * manifest.
 *
 * DETAILED — this is a thin ORCHESTRATOR, not a second importer: it adds
 * ZERO new song-writing logic of its own (same modularity posture as
 * `_bulkImport_parsePro7()`'s doc-block above). List entries -> partition
 * `.pro` vs media vs directory-placeholder entries -> hand EVERY `.pro`
 * entry to the EXACT SAME single-file pipeline a standalone `.pro` upload
 * already uses (`_bulkImport_processPro7()`, one call per entry) -> union
 * the per-entry summaries into one aggregate, mirroring how
 * `_bulkImport_processPptx()` above aggregates several songs parsed out of
 * ONE uploaded file (rule #22 — reuse the existing multi-song-per-upload
 * shape rather than inventing a new one).
 *
 * Media is DEFERRED to Phase 4 (plan §6 — "ingest & store", a genuinely
 * separate piece of work: MIME-sniffed validation, `tblSongMedia` kind
 * registry additions, gating). This function therefore NEVER calls
 * `pp7ZipReadEntry()` on a media entry — only its name + declared
 * (uncompressed) size from `pp7ZipListEntries()`'s own bookkeeping are ever
 * touched — but "deferred" must never mean "invisible": every media
 * filename survives into both a `warnings[]` note (the curator-facing
 * summary channel every other importer in this file uses for non-fatal
 * issues) and the summary's own `media_present`/`media_files` keys, so a
 * curator can see exactly what a bundle contained even though only its
 * `.pro` entries were imported.
 *
 * @param string      $bytes        raw bytes of one `.probundle` (a ZIP)
 * @param string|null $filenameHint original upload filename of the BUNDLE
 *                                  itself — used only when there is no
 *                                  per-entry context yet (the ZIP could not
 *                                  be read at all, or it contained no `.pro`
 *                                  entry). Once the archive walk reaches a
 *                                  real `.pro` entry, THAT entry's own name
 *                                  (not this bundle-level hint) is what gets
 *                                  passed to `_bulkImport_processPro7()` as
 *                                  ITS filenameHint — the entry's own name is
 *                                  the meaningful one for that song's
 *                                  bracket-abbreviation override / title
 *                                  fallback / per-song error label, exactly
 *                                  as it would be if that one `.pro` had
 *                                  been uploaded standalone.
 * @return array same summary shape as `_bulkImport_processPro7()`, PLUS
 *               `media_present` (int — count of non-`.pro` entries seen) and
 *               `media_files` (array<string> — every one of their names) so
 *               nothing the bundle carried is invisible even though only
 *               `.pro` entries are imported (plan §4.2; media ingest is P4,
 *               plan §6).
 * @see .claude/propresenter-interop-1968-plan.md   §4.1 (the tolerant reader), §4.2 (this import flow), §6 (the deferred media phase)
 * @see includes/propresenter7_zip.php               pp7ZipListEntries()/pp7ZipReadEntry() — the reader this builds on
 * @see tests/php/test-pp7-probundle-import.php       real-fixture validation
 * =========================================================================== */

/**
 * Pure entry classifier for `_bulkImport_processProbundle()` — split out
 * (rather than left inline) specifically so it is independently unit-
 * testable with NO database connection at all, mirroring
 * `_bulkImport_parsePro7()`'s own "pure, DB-free" split from its DB-touching
 * sibling `_bulkImport_processPro7()` just above it. Takes the raw entry
 * list `pp7ZipListEntries()` returns and sorts every entry into exactly one
 * of two buckets:
 *   - `'pro'`   — a ProPresenter presentation (name ends `.pro`, matched
 *                 case-insensitively on the last 4 bytes rather than
 *                 `pathinfo()`'s extension parser, to mirror the plan's
 *                 plain-English "whose name ends `.pro`" literally — no
 *                 real fixture uses mixed case, but a case-sensitive match
 *                 would silently mis-bucket one that did);
 *   - `'media'` — everything else with real content.
 * A directory placeholder some ZIP writers emit for path completeness
 * (name ends `/`, always zero bytes) is neither a song nor media and is
 * silently dropped from both buckets — it carries no importable content
 * either way.
 *
 * @param array<int,array{name:string,method:int,size:int,csize:int,offset:int}> $entries
 *        the exact shape `pp7ZipListEntries()` returns
 * @return array{pro: array<int,array>, media: array<int,array>}
 * @see includes/propresenter7_zip.php   pp7ZipListEntries() — the producer of $entries
 */
function _bulkImport_probundleClassifyEntries(array $entries): array
{
    $pro   = [];
    $media = [];
    foreach ($entries as $entry) {
        $name = $entry['name'] ?? '';
        if ($name === '' || substr($name, -1) === '/') {
            continue; // directory placeholder — not a real file
        }
        if (strtolower(substr($name, -4)) === '.pro') {
            $pro[] = $entry;
        } else {
            $media[] = $entry;
        }
    }
    return ['pro' => $pro, 'media' => $media];
}

/**
 * Pure per-entry aggregation step for `_bulkImport_processProbundle()`'s
 * `.pro` loop — folds ONE inner `_bulkImport_processPro7()`-shaped result
 * into the running aggregate, with NO database access of its own. Split out
 * for the SAME independent-testability reason as
 * `_bulkImport_probundleClassifyEntries()` above: the summing/unioning logic
 * is real business logic worth proving correct (and mutation-testing, rule
 * #34) on its own, with a hand-built `$inner` array standing in for a real
 * `_bulkImport_processPro7()` return — no ZIP bytes, no protobuf, no
 * database required to exercise it.
 *
 * @param array{songbooksCreated:array<string,true>,songbooksExisting:array<string,true>,songsCreated:int,songsSkipped:int,songsFailed:int,errors:array,warnings:array} $agg running aggregate (see `_bulkImport_processProbundle()`'s initial value)
 * @param string $entryName the `.pro` entry's own name (warnings are prefixed with it — see below)
 * @param array  $inner     one `_bulkImport_processPro7()`-shaped result (success OR `['ok'=>false,'error'=>…]` failure)
 * @return array the updated `$agg`, same shape as the input
 */
function _bulkImport_probundleFoldInnerSummary(array $agg, string $entryName, array $inner): array
{
    if (!($inner['ok'] ?? false)) {
        $agg['songsFailed']++;
        $agg['errors'][] = ['entry' => $entryName, 'error' => $inner['error'] ?? 'ProPresenter 7+ parse failed'];
        return $agg;
    }

    foreach ($inner['songbooks_created'] as $a) {
        $agg['songbooksCreated'][$a] = true;
    }
    foreach ($inner['songbooks_existing'] as $a) {
        /* Sets (not lists) so the SAME abbreviation reported 'created' by
           the FIRST `.pro` entry and 'existing' by every later entry in the
           same bundle (the normal case — every song in a `.probundle` files
           under the one "ProPresenter 7 Import" songbook unless an entry's
           own filename carries a bracket-abbreviation override) collapses
           to ONE final classification rather than appearing in both output
           lists. */
        if (!isset($agg['songbooksCreated'][$a])) {
            $agg['songbooksExisting'][$a] = true;
        }
    }
    $agg['songsCreated'] += (int)($inner['songs_created'] ?? 0);
    $agg['songsSkipped'] += (int)($inner['songs_skipped_existing'] ?? 0);
    $agg['songsFailed']  += (int)($inner['songs_failed'] ?? 0);
    foreach ($inner['errors'] as $e) {
        $agg['errors'][] = $e;
    }
    // Prefixed with the entry name so a multi-.pro bundle's warnings (e.g.
    // "N translation layer(s) present") stay attributable to WHICH
    // presentation they came from, same as the errors[] entries
    // _bulkImport_processPro7() itself already labels via filenameHint.
    foreach ((array)($inner['warnings'] ?? []) as $w) {
        $agg['warnings'][] = "{$entryName}: {$w}";
    }
    return $agg;
}

/**
 * Pure "finish and shape the summary" step for `_bulkImport_processProbundle()`
 * — appends the media-deferred warning (plan §4.2/§6 — "never silently
 * drop") when the bundle carried any media, and assembles the FINAL summary
 * array from the running aggregate + media counts. Split out for the same
 * DB-free-testability reason as its two siblings above: a hand-built `$agg`
 * (no real ZIP, no real DB) is enough to prove the media-warning wording and
 * the final shape are both correct.
 *
 * @param array $agg        the aggregate `_bulkImport_probundleFoldInnerSummary()` built up
 * @param int   $mediaCount count of non-`.pro` entries in the bundle
 * @param array<string> $mediaNames every one of their names
 * @return array the FINAL summary `_bulkImport_processProbundle()` returns on success
 */
/**
 * The human-facing "N media file(s) were not imported" warning line, shared
 * by `.probundle` (`_bulkImport_probundleFinishSummary()`) AND
 * `.proplaylist` (`_bulkImport_processProplaylist()`) — both defer media
 * ingest to a later phase (plan §6 / P4) and both must say so identically
 * rather than each typing its own prose (rule #22 — one shared helper per
 * decision; also keeps the two ZIP-container importers' wording in
 * lockstep, the same "cross-file agreement needs a mechanism" posture rule
 * #35 asks for). Pure — no DB. Returns `null` when there is nothing to
 * report (the caller appends only a non-null result).
 *
 * @param int               $mediaCount count of non-`.pro` entries seen
 * @param array<int,string> $mediaNames every one of their names
 */
function _bulkImport_pp7MediaDeferredWarning(int $mediaCount, array $mediaNames): ?string
{
    if ($mediaCount <= 0) {
        return null;
    }
    // Capped preview list in the human-facing warning line (matches the
    // "first_errors" capping convention used elsewhere in this file); the
    // FULL list still rides the structured media_files[] key the caller
    // returns alongside this, so nothing is lost — only the prose line is
    // kept scannable.
    $shown = array_slice($mediaNames, 0, 5);
    $more  = $mediaCount - count($shown);
    return "{$mediaCount} media file(s) in the bundle were not imported "
        . '(media ingest arrives in a later update): '
        . implode(', ', $shown)
        . ($more > 0 ? " (+{$more} more)" : '');
}

/**
 * @param array{ingested:int,duplicate:int,unresolved:int,skipped:int}|null $mediaIngest
 *        #1968 P4 — the aggregated ingest counts (null on a non-P4 path).
 * @param bool $ingestActive whether media ingest actually ran on this env (the
 *        `pp7_media_ingest_enabled` gate was open). When true, the deferred-media
 *        warning is REPLACED by real `media_ingested`/`media_duplicate`/
 *        `media_unresolved` keys; when false, today's deferred warning is kept
 *        byte-identically (the dormant default).
 */
function _bulkImport_probundleFinishSummary(array $agg, int $mediaCount, array $mediaNames, ?array $mediaIngest = null, bool $ingestActive = false): array
{
    $warnings = $agg['warnings'];

    /* Keep the deferred-media warning UNLESS ingest actually ran on this env
       (owner flipped pp7_media_ingest_enabled) — then real counts speak for it. */
    if (!$ingestActive) {
        $mediaWarning = _bulkImport_pp7MediaDeferredWarning($mediaCount, $mediaNames);
        if ($mediaWarning !== null) {
            $warnings[] = $mediaWarning;
        }
    }

    $summary = [
        'ok'                     => true,
        'songbooks_created'      => array_keys($agg['songbooksCreated']),
        'songbooks_existing'     => array_keys($agg['songbooksExisting']),
        'songs_created'          => $agg['songsCreated'],
        'songs_skipped_existing' => $agg['songsSkipped'],
        'songs_failed'           => $agg['songsFailed'],
        'parsed_by_format'       => ['propresenter7' => $agg['songsCreated'] + $agg['songsSkipped']],
        'errors'                 => $agg['errors'],
        'warnings'               => $warnings,
        'media_present'          => $mediaCount,
        'media_files'            => $mediaNames,
    ];
    if ($ingestActive && $mediaIngest !== null) {
        $summary['media_ingested']   = (int)$mediaIngest['ingested'];
        $summary['media_duplicate']  = (int)$mediaIngest['duplicate'];
        $summary['media_unresolved'] = (int)$mediaIngest['unresolved'];
    }
    return $summary;
}

/**
 * #1968 P4 — split a path into its basename on BOTH separators. ProPresenter
 * media REFERENCES can be Windows absolute paths (`C:\…\ImageSample1.jpg`,
 * byte-verified in v7-feature-test-win.pro) while ZIP entry names are always
 * `/`-separated — PHP's own `basename()` only splits on `/`, so a Windows ref
 * would keep its whole path as the "basename" and never match. Splits on `/`
 * and `\`, returns the last non-empty segment.
 */
function _bulkImport_pp7Basename(string $path): string
{
    $parts = preg_split('#[/\\\\]#', $path) ?: [];
    for ($i = count($parts) - 1; $i >= 0; $i--) {
        if (($parts[$i] ?? '') !== '') { return $parts[$i]; }
    }
    return '';
}

/** #1968 P4 — length (in characters) of the longest common SUFFIX of two paths,
 *  used to disambiguate two media entries that share a basename by which one
 *  shares more of its trailing path with the reference. */
function _bulkImport_pp7CommonSuffixLen(string $a, string $b): int
{
    $i = strlen($a) - 1;
    $j = strlen($b) - 1;
    $n = 0;
    while ($i >= 0 && $j >= 0 && $a[$i] === $b[$j]) { $n++; $i--; $j--; }
    return $n;
}

/**
 * #1968 P4 — resolve ONE decoder media reference to a bundle media ENTRY, PURE
 * + DB-free (plan §6.4). The ground-truth rule (§2): the ZIP entry name is the
 * media's absolute path with the scheme stripped and percent-DECODED, while the
 * ref's `absoluteString` is percent-ENCODED — so match by url-decoded BASENAME,
 * disambiguating same-basename collisions by the longest common suffix of the
 * full decoded paths. NEVER GUESS: zero matches, or a genuine suffix tie,
 * returns null (the caller warns + skips). Attaching the WRONG media to a song
 * is exactly the false-positive class the owner's #1 rule for this epic bans.
 * Covers all three observed layouts (absolute external path; in-library
 * `Media/x.png`; portable `CURRENT_RESOURCE` flat form).
 *
 * @param array{absoluteString:?string,localRoot:?int,localPath:?string} $ref
 * @param array<string,array<int,array>> $mediaEntriesByBasename decoded-basename => [entry, …]
 * @return array|null the matched raw ZIP entry (pp7ZipListEntries() shape), or null
 */
function _bulkImport_pp7ResolveMediaRef(array $ref, array $mediaEntriesByBasename): ?array
{
    $abs = (string)($ref['absoluteString'] ?? '');
    $decodedAbs = '';
    if ($abs !== '') {
        $noScheme   = preg_replace('#^[a-zA-Z][a-zA-Z0-9+.\-]*://#', '', $abs);
        $decodedAbs = rawurldecode((string)$noScheme);
    }
    $localPath = (string)($ref['localPath'] ?? '');

    $refBasename = $decodedAbs !== '' ? _bulkImport_pp7Basename($decodedAbs) : '';
    if ($refBasename === '' && $localPath !== '') { $refBasename = _bulkImport_pp7Basename($localPath); }
    if ($refBasename === '') { return null; }

    $cands = $mediaEntriesByBasename[$refBasename] ?? [];
    if (count($cands) === 0) { return null; }
    if (count($cands) === 1) { return $cands[0]; }

    /* Same basename in >1 entry (different directories) — pick the entry whose
       full decoded name shares the longest trailing path with the ref; a tie is
       ambiguous, so refuse rather than guess. */
    $needle  = $decodedAbs !== '' ? $decodedAbs : $localPath;
    $best    = null;
    $bestLen = -1;
    $tie     = false;
    foreach ($cands as $c) {
        $name = rawurldecode((string)($c['name'] ?? ''));
        $len  = _bulkImport_pp7CommonSuffixLen($needle, $name);
        if ($len > $bestLen)      { $best = $c; $bestLen = $len; $tie = false; }
        elseif ($len === $bestLen) { $tie = true; }
    }
    return $tie ? null : $best;
}

/**
 * #1968 P4 — THE shared media-ingest core (bundle + playlist both call it,
 * rule #22). Resolves each media reference of ONE imported song to a bundle
 * entry, validates the bytes through the EXISTING upload path, dedupes by
 * content, and stores each as a `Visibility='admin'` (curator-only) tblSongMedia
 * row (owner decision D1). Returns per-song counts.
 *
 * FAIL-CLOSED BY CONSTRUCTION (plan §6.3/§6.4):
 *   1. Runs at all ONLY when BOTH the `pp7_media_ingest_enabled` app setting is
 *      '1' AND the Visibility column exists — else returns all-zeros and the
 *      caller keeps emitting today's deferred-media warning (truthful: media
 *      was SEEN, not imported). A row that cannot be stored `admin` is never
 *      stored at all — the opposite branch ("store public for now") is the D1
 *      violation. The read gate (song_media_visibility.php) and this write gate
 *      degrade in lockstep.
 *   2. Validation reuses SongMediaStorage::validateUpload() UNCHANGED (finfo
 *      sniff + MIME allow-list + size cap) — never a second validation path
 *      (rule #42). Kind is derived from the SNIFFED MIME, then validateUpload
 *      cross-checks the specific type.
 *
 * @param array<int,array{absoluteString:?string,localRoot:?int,localPath:?string}> $mediaRefs
 * @param array<string,array<int,array>> $mediaEntriesByBasename decoded-basename => [entry, …]
 * @param string $sourceLabel activity-log source ('bulk_import_probundle'|'bulk_import_proplaylist')
 * @param array<int,string> $warnings by-ref — one line per unresolved/skipped ref
 * @return array{ingested:int,duplicate:int,unresolved:int,skipped:int}
 */
/**
 * #1968 P4 — map a finfo-sniffed MIME to a tblSongMedia media KIND, PURE. Only
 * the three top-level media families ProPresenter backgrounds use are accepted;
 * anything else (a `.pro`, a `.txt`, an unknown type) returns null so the ingest
 * core skips it with a warning. SongMediaStorage::validateUpload() then does the
 * specific-type cross-check against its own allow-list (never a second path,
 * rule #42) — this only picks the KIND bucket.
 */
function _bulkImport_pp7KindFromMime(string $mime): ?string
{
    if (str_starts_with($mime, 'video/')) { return 'video'; }
    if (str_starts_with($mime, 'image/')) { return 'image'; }
    if (str_starts_with($mime, 'audio/')) { return 'audio'; }
    return null;
}

/**
 * #1968 P4 — the ONE bundle-level "is media ingest active on this env?" gate,
 * shared by the ingest core (its own fail-closed step 1) AND the container
 * orchestrators (which decide whether the summary shows real counts or the
 * deferred-media warning), so the two can never disagree (rule #22/#35).
 * Requires BOTH the app setting flipped '1' AND the Visibility column present —
 * the read gate and this write gate degrade in lockstep. Default is inert.
 */
function _bulkImport_pp7MediaIngestActive(\mysqli $db): bool
{
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'maintenance.php';
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'song_media_visibility.php';
    return getAppSetting('pp7_media_ingest_enabled', '0') === '1'
        && songMediaVisibilityColumnExists($db);
}

function _bulkImport_pp7IngestMedia(
    \mysqli $db,
    string $songId,
    array $mediaRefs,
    string $bundleBytes,
    array $mediaEntriesByBasename,
    ?int $userId,
    string $sourceLabel,
    array &$warnings
): array {
    $counts = ['ingested' => 0, 'duplicate' => 0, 'unresolved' => 0, 'skipped' => 0];
    if ($songId === '' || empty($mediaRefs)) { return $counts; }

    require_once __DIR__ . DIRECTORY_SEPARATOR . 'SongMediaStorage.php';
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'song_media_flags.php';
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'ilyrics_id.php';
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'activity_log.php';
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'propresenter7_zip.php';

    /* Step 1 — the dormancy + lockstep gate. Fail CLOSED: if we cannot store a
       row as 'admin', we store nothing (the caller's deferred-media warning
       still fires, so nothing is silently dropped). */
    if (!_bulkImport_pp7MediaIngestActive($db)) {
        return $counts;
    }

    foreach ($mediaRefs as $ref) {
        $entry = _bulkImport_pp7ResolveMediaRef($ref, $mediaEntriesByBasename);
        if ($entry === null) {
            $counts['unresolved']++;
            $refName = (string)($ref['localPath'] ?? $ref['absoluteString'] ?? 'unknown');
            $warnings[] = 'media reference could not be matched to a bundled file (not imported): '
                        . _bulkImport_pp7Basename($refName);
            continue;
        }

        $staged = null;
        $tmpPath = null;
        try {
            $bytes = pp7ZipReadEntry($bundleBytes, $entry);
            $size  = strlen($bytes);

            /* Kind from the SNIFFED MIME (never the filename), then validateUpload
               cross-checks the specific type against SongMediaStorage's allow-list. */
            $sniff = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: '';
            $kind  = _bulkImport_pp7KindFromMime($sniff);
            if ($kind === null) {
                $counts['skipped']++;
                $warnings[] = 'bundled media has an unsupported type (' . ($sniff ?: 'unknown')
                            . ', not imported): ' . _bulkImport_pp7Basename((string)($entry['name'] ?? ''));
                continue;
            }

            $tmpPath = tempnam(sys_get_temp_dir(), 'pp7media');
            if ($tmpPath === false || file_put_contents($tmpPath, $bytes) === false) {
                throw new \RuntimeException('could not stage media bytes to a tempfile');
            }
            $meta = SongMediaStorage::validateUpload($tmpPath, $kind, $size);

            /* Step 5 — dedupe by content: re-importing the same bundle (even over
               a dedupe-'skipped' song) never double-stores. */
            $sha = hash('sha256', $bytes);
            $dup = $db->prepare('SELECT 1 FROM tblSongMedia WHERE SongId = ? AND Kind = ? AND Sha256 = ? LIMIT 1');
            $dup->bind_param('sss', $songId, $kind, $sha);
            $dup->execute();
            $already = $dup->get_result()->fetch_row() !== null;
            $dup->close();
            if ($already) { $counts['duplicate']++; @unlink($tmpPath); $tmpPath = null; continue; }

            $staged   = SongMediaStorage::stage($bytes, $kind, $meta['extension']);
            $baseName = _bulkImport_pp7Basename((string)($entry['name'] ?? 'media'));
            $fileName = mb_substr(preg_replace('/[\x00-\x1f\x7f]/', '', $baseName) ?: 'media', 0, 255);
            $annotation = mb_substr('ProPresenter import: ' . $baseName, 0, 255);
            $visibility = 'admin';   // owner decision D1 — never public on ingest

            $db->begin_transaction();
            try {
                $mx = $db->prepare('SELECT COALESCE(MAX(SortOrder), -1) AS m FROM tblSongMedia WHERE SongId = ? AND Kind = ?');
                $mx->bind_param('ss', $songId, $kind);
                $mx->execute();
                $nextOrder = (int)($mx->get_result()->fetch_assoc()['m'] ?? -1) + 1;
                $mx->close();

                $ins = $db->prepare(
                    'INSERT INTO tblSongMedia
                        (SongId, Kind, StorageBackend, FileName, MimeType, SizeBytes,
                         Sha256, Content, StoragePath, Annotation, Visibility, SortOrder, UploadedBy)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $ins->bind_param(
                    'sssssisssssii',
                    $songId, $kind, $staged['backend'], $fileName, $meta['mime'], $size,
                    $staged['sha256'], $staged['content'], $staged['path'], $annotation, $visibility, $nextOrder, $userId
                );
                $ins->execute();
                $newId = (int)$db->insert_id;
                $ins->close();
                ilidStampNewRow($db, 'document', $newId);
                $db->commit();
            } catch (\Throwable $e) {
                $db->rollback();
                throw $e;
            }

            /* Step 7 — REQUIRED post-commit recompute (own failure boundary; the
               tree-derived test-editor2-metadata-1862.php guard demands this hook
               on every tblSongMedia INSERT). Harmless for video/image (outside the
               flag-kind map); an admin AUDIO row is excluded by the recompute's own
               public filter (§6.3.4), keeping the derived flags honest. */
            songMediaRecomputeFlags($db, $songId);

            logActivity('song-media.upload', 'song', $songId, [
                'media_id'   => $newId,
                'kind'       => $kind,
                'backend'    => $staged['backend'],
                'file_name'  => $fileName,
                'mime'       => $meta['mime'],
                'size_bytes' => $size,
                'sha256'     => $staged['sha256'],
                'source'     => $sourceLabel,
                'visibility' => $visibility,
            ]);
            $counts['ingested']++;
        } catch (\Throwable $e) {
            $counts['skipped']++;
            /* Unlink a staged FS orphan if the DB write never landed. */
            if (is_array($staged) && ($staged['backend'] ?? '') === 'filesystem' && !empty($staged['path'])) {
                try { SongMediaStorage::deleteStorage(['StorageBackend' => 'filesystem', 'StoragePath' => $staged['path']]); } catch (\Throwable $_e) {}
            }
            $warnings[] = 'a bundled media file could not be imported (' . $e->getMessage() . '): '
                        . _bulkImport_pp7Basename((string)($entry['name'] ?? ''));
        } finally {
            if ($tmpPath !== null && is_file($tmpPath)) { @unlink($tmpPath); }
        }
    }

    return $counts;
}

/**
 * #1968 P4 — build the decoded-basename => [entry, …] index the resolver needs,
 * ONCE per container (plan §6.4). Keyed by the url-decoded basename of each
 * media entry's name.
 *
 * @param array<int,array> $mediaEntries the classifier's media bucket
 * @return array<string,array<int,array>>
 */
function _bulkImport_pp7IndexMediaByBasename(array $mediaEntries): array
{
    $index = [];
    foreach ($mediaEntries as $entry) {
        $name = rawurldecode((string)($entry['name'] ?? ''));
        $bn   = _bulkImport_pp7Basename($name);
        if ($bn === '') { continue; }
        $index[$bn][] = $entry;
    }
    return $index;
}

function _bulkImport_processProbundle(string $bytes, ?string $filenameHint = null, ?int $userId = null): array
{
    /* @disabled-visible: importer / batch system path (#1765) — operates over all
       songbooks regardless of public disabled state */
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'propresenter7_zip.php';

    $bundleLabel = ($filenameHint !== null && $filenameHint !== '') ? $filenameHint : 'the uploaded bundle';

    /* Shared shape for every "nothing was imported" outcome (a malformed ZIP,
       or a genuine ZIP with zero `.pro` entries) — same field set
       `_bulkImport_processPro7()` returns on failure, so every caller
       (api.php / api2.php) can treat both processors identically. Media
       counts still ride along even on a total failure — plan §4.2's "never
       silently drop" contract applies whether or not any song imported. */
    $emptyFail = static function (string $reason, int $mediaCount = 0, array $mediaNames = []): array {
        return [
            'ok'                     => false,
            'error'                  => $reason,
            'songbooks_created'      => [],
            'songbooks_existing'     => [],
            'songs_created'          => 0,
            'songs_skipped_existing' => 0,
            'songs_failed'           => 0,
            'parsed_by_format'       => ['propresenter7' => 0],
            'errors'                 => [],
            'warnings'               => [],
            'media_present'          => $mediaCount,
            'media_files'            => $mediaNames,
        ];
    };

    try {
        $entries = pp7ZipListEntries($bytes);
    } catch (\Throwable $e) {
        // A ZIP that the tolerant reader itself can't walk at all (truncated
        // upload, genuinely not a ZIP, …) — never a crash, per plan §4.2's
        // "A bundle with zero .pro -> a clean fail, not a crash" contract
        // (this is the same contract's other trigger: no readable entries
        // at all, rather than zero .pro entries among readable ones).
        return $emptyFail("Could not read {$bundleLabel} as a ProPresenter bundle: " . $e->getMessage());
    }

    $classified = _bulkImport_probundleClassifyEntries($entries);
    $proEntries   = $classified['pro'];
    $mediaEntries = $classified['media'];

    $mediaCount = count($mediaEntries);
    $mediaNames = array_map(static fn(array $e): string => $e['name'], $mediaEntries);

    if (empty($proEntries)) {
        return $emptyFail("No ProPresenter presentation found in {$bundleLabel}.", $mediaCount, $mediaNames);
    }

    $agg = [
        'songbooksCreated'  => [],
        'songbooksExisting' => [],
        'songsCreated'      => 0,
        'songsSkipped'      => 0,
        'songsFailed'       => 0,
        'errors'            => [],
        'warnings'          => [],
    ];

    /* #1968 P4 — index the bundle's media entries by decoded basename ONCE, so
       each imported song can resolve its own per-cue media refs. Dormant until
       the owner flips pp7_media_ingest_enabled (the ingest core's own gate). */
    $mediaByBasename = _bulkImport_pp7IndexMediaByBasename($mediaEntries);
    $mediaIngest = ['ingested' => 0, 'duplicate' => 0, 'unresolved' => 0, 'skipped' => 0];
    $db = getDbMysqli();                       // for media ingest + skipped-song re-resolution
    $ingestActive = _bulkImport_pp7MediaIngestActive($db);

    foreach ($proEntries as $entry) {
        $name = $entry['name'];

        /* Plan §4.2: "Non-root .pro entries (none observed in real bundles)
           are imported the same with a warning." Root = the entry's OWN
           name carries no '/' at all (it sits at the archive's top level). */
        if (strpos($name, '/') !== false) {
            $agg['warnings'][] = "'{$name}' is not at the bundle root — imported anyway";
        }

        try {
            $proBytes = pp7ZipReadEntry($bytes, $entry);
        } catch (\Throwable $e) {
            $agg['songsFailed']++;
            $agg['errors'][] = ['entry' => $name, 'error' => 'could not extract from bundle: ' . $e->getMessage()];
            continue;
        }

        /* THE reuse point: the exact single-file P1 pipeline, one call per
           `.pro` entry, this entry's OWN name as ITS filenameHint (see this
           function's own doc-block @param note for why). Adds no new save
           logic of its own — every write still lands via
           `_bulkImport_saveSong()` -> `lyricLinesWriteComponents()`. */
        $inner = _bulkImport_processPro7($proBytes, $name);
        $agg   = _bulkImport_probundleFoldInnerSummary($agg, $name, $inner);

        /* #1968 P4 — ingest this song's referenced media into tblSongMedia
           (admin-only, D1). Additive + entirely dormant behind the core's
           pp7_media_ingest_enabled gate. Resolve the target SongId: a 'create'
           reports it directly; a dedupe-'skipped' reports the never-inserted
           fresh id, so re-derive the real existing row (its doc-block exists
           for exactly this). */
        if (($inner['ok'] ?? false) && !empty($inner['media_refs']) && !empty($mediaByBasename)) {
            $targetSongId = null;
            if ((int)($inner['songs_created'] ?? 0) > 0) {
                $targetSongId = (string)($inner['song_id'] ?? '');
            } elseif ((int)($inner['songs_skipped_existing'] ?? 0) > 0) {
                $resolved = _bulkImport_proplaylistResolveSkippedSong(
                    $db,
                    (string)($inner['songbook_abbr'] ?? ''),
                    (string)($inner['title'] ?? ''),
                    (string)($inner['song_id'] ?? '')
                );
                $targetSongId = $resolved !== null ? (string)$resolved['songId'] : null;
            }
            if ($targetSongId !== null && $targetSongId !== '') {
                $ic = _bulkImport_pp7IngestMedia(
                    $db, $targetSongId, $inner['media_refs'], $bytes,
                    $mediaByBasename, $userId, 'bulk_import_probundle', $agg['warnings']
                );
                foreach ($ic as $k => $v) { $mediaIngest[$k] += $v; }
            } else {
                $agg['warnings'][] = "'{$name}': referenced media not imported (could not resolve the imported song)";
            }
        }
    }

    return _bulkImport_probundleFinishSummary($agg, $mediaCount, $mediaNames, $mediaIngest, $ingestActive);
}

/* ===========================================================================
 *  ProPresenter 7+ `.proplaylist` import -> ONE iHymns set list (#1968 PR-3,
 *  plan .claude/propresenter-interop-1968-plan.md §5.1)
 * ---------------------------------------------------------------------------
 * ELI5: a `.proplaylist` is a worship leader's whole service order — an
 * ordered list of songs (and section dividers) — exported from ProPresenter.
 * This section reads that order, imports every embedded song through the
 * EXACT SAME single-file pipeline a standalone `.pro` upload uses, and then
 * builds ONE iHymns set list that puts those songs (and the section
 * dividers) in the same order the leader had them in.
 *
 * DETAILED — layering, mirroring `.probundle`'s own split immediately above:
 *   - `includes/propresenter7_playlist.php`'s `pp7ReadPlaylistBundle()` does
 *     the protobuf decode (pure, DB-free) — this section never re-decodes
 *     anything, only WALKS the plain PHP tree it returns.
 *   - `_bulkImport_proplaylistBuildPlan()` (and the pure helpers it calls)
 *     turn that tree into a flat, ORDERED "plan" of {header|placeholder|
 *     song-embedded|song-unresolved|skipped} entries — 100% pure, no
 *     database, no ZIP bytes — so the item -> song/header/slot MAPPING
 *     decision is unit-testable against the three committed real fixtures
 *     with no MySQL at all (`tests/php/test-pp7-proplaylist-import.php`).
 *   - `_bulkImport_processProplaylist()` is the thin DB-touching shell: walk
 *     the plan, import each embedded `.pro` entry via the UNCHANGED
 *     `_bulkImport_processPro7()` (adds zero new song-writing logic of its
 *     own, same posture as `_bulkImport_processProbundle()`'s own doc-block),
 *     resolve a referenced-but-not-embedded presentation against the
 *     existing catalogue by title, and write ONE `tblUserSetlists` row
 *     through the SAME sanitisers (`setlistCollabSanitiseSongs()` /
 *     `setlistTemplateSanitisePlan()` / `setlistTemplateEncodePlan()`) the
 *     app's own multi-device sync endpoint (`user_setlists_sync` in
 *     api.php) and collaborative editor (`setlist_collab.php`) already write
 *     that exact column pair through — CLAUDE.md rule #22: the SHAPE of
 *     what's valid in `SongsJson`/`SlotsJson` is reused, not reinvented.
 *
 * ⚠️ NO EXISTING "CREATE ONE NEW SET LIST" CORE TO CALL INTO. The task brief
 * (and rule #22) ask to reuse the app's own set-list creation code path
 * rather than hand-roll a raw INSERT — but the ONLY other writers of this
 * column pair are (a) `user_setlists_sync`, the multi-device SYNC protocol
 * in api.php (tombstones, watermarks, resurrection guards, a `localLists[]`
 * body shape a client sends — not a callable single-row function at all),
 * and (b) `setlistCollabPerformUpdate()`, which can only UPDATE an
 * already-existing (UserId, SetlistId) row, never INSERT one. Neither is a
 * "create ONE new set list, server-side, for a resolved owner" core. So
 * `_bulkImport_proplaylistMintSetlistId()` + the plain INSERT inside
 * `_bulkImport_processProplaylist()` below IS the reused shape (the exact
 * same sanitisers + encoders + column set + gating as the real writers)
 * with only the missing "mint a fresh id and INSERT" wiring added — not a
 * second, forked write path for an already-solved problem.
 *
 * D2 (plan §12.3, owner decision UNANSWERED — used as directed, flagged for
 * review): **curator-first**. This import creates SONGS, so it is reached
 * only from the editor-gated `bulk_import_proplaylist` action (api.php) /
 * `import_file` format `proplaylist` (api2.php) — exactly like
 * `bulk_import_pro7`/`bulk_import_probundle` — never from a public surface.
 * `$userId` (the set list's OWNER) is threaded in from the authenticated
 * session at the HANDLER, never resolved inside this DB-free-adjacent file.
 *
 * ITEM -> SET-LIST MAPPING (plan §5.1, the task brief's explicit contract):
 *   - `presentation` items -> songs in `SongsJson`, IN ORDER.
 *   - `header` items -> a header/divider slot in `SlotsJson` (this codebase's
 *     one service-plan model, `includes/setlist_templates.php` — a slot with
 *     a `label` and no `songId` IS a divider; there is no separate
 *     "divider" concept to invent).
 *   - `placeholder` items -> a spacer slot (same SlotsJson shape, no
 *     `songId`) — the model supports exactly this, so no warning-only
 *     fallback is needed for this item type.
 *   - `cue` / `planning_center` items -> skipped with a warning (out of P3
 *     scope, named explicitly per plan §5.1 point 4 — never silently
 *     dropped).
 *   - Nested playlists/groups -> flattened in order; a NESTED node's OWN
 *     name becomes a synthetic header (see
 *     `_bulkImport_proplaylistFlattenItems()`'s doc-block for why only
 *     nested nodes, never the top-level one, get this treatment).
 *   - A `presentation` item whose `.pro` is NOT in the bundle (referenced by
 *     path only) resolves against the EXISTING catalogue by exact
 *     normalised title (`_bulkImport_proplaylistResolveExistingSong()` —
 *     CCLI is NOT available for this resolution: a `PlaylistItem.
 *     Presentation` carries no CCLI field at all, only a document path +
 *     arrangement — the task brief's "title/CCLI if possible" reduces to
 *     "title" for exactly this reason, recorded here rather than silently
 *     dropped) — a hit becomes a normal `SongsJson` entry (with a warning
 *     naming the fallback); a miss becomes a placeholder slot + warning,
 *     never a failed import (plan §5.1 point 3's explicit "do NOT fail the
 *     whole import").
 *
 * DE-DUPE (task brief: "so a bundle re-import or a song already present
 * doesn't mint duplicates"): every embedded `.pro` entry goes through the
 * SAME `_bulkImport_dedupeMode()` flag every other importer honours — set by
 * the caller from the posted `dedupeMode`, exactly like `bulk_import_pro7`/
 * `bulk_import_probundle` (no bespoke dedupe invented here). TWO further
 * safeguards specific to a playlist:
 *   (1) the SAME `.pro` ENTRY referenced by more than one playlist item
 *       (e.g. a chorus reprised later in the service) is imported/resolved
 *       ONCE and the result is CACHED — `_bulkImport_proplaylistResolveEmbedded()`'s
 *       `$cache` parameter — so two playlist items pointing at the same
 *       `Chorus.pro` produce ONE song, referenced twice in `SongsJson`, not
 *       two duplicate `tblSongs` rows;
 *   (2) a 'skipped' (title-dedupe) result from `_bulkImport_processPro7()`
 *       does NOT know which EXISTING SongId it matched (`_bulkImport_saveSong()`
 *       never reports that) — `_bulkImport_proplaylistResolveSkippedSong()`
 *       re-resolves the real existing row (by literal SongId first, then by
 *       the SAME `_bulkImport_findDuplicateCandidates()` title matcher
 *       `_bulkImport_saveSong()` itself used to decide to skip) so the set
 *       list still ends up pointing at the REAL pre-existing song, not a
 *       fresh-but-unused id.
 *
 * @see .claude/propresenter-interop-1968-plan.md   §5.1 (import -> set list)
 * @see includes/propresenter7_playlist.php          pp7ReadPlaylistBundle() — the decoder this builds on
 * @see includes/setlist_collab.php                  setlistCollabSanitiseSongs() / setlistCollabMaxSongs() — the SongsJson shape + cap, reused not reinvented
 * @see includes/setlist_templates.php                setlistTemplateSanitisePlan()/EncodePlan()/setlistSlotsColumnReady() — the SlotsJson shape + cap, reused not reinvented
 * @see tests/php/test-pp7-proplaylist-import.php      real-fixture validation of the pure mapping layer
 * =========================================================================== */

if (!function_exists('_bulkImport_proplaylistName')) {
    /**
     * Derive the imported set list's `Name` (task brief: "= the playlist
     * name"). Pure — no DB.
     *
     * Ladder: the first top-level playlist node's own `name` (the ONLY name
     * a real `.proplaylist` fixture actually carries for "the service" —
     * `document.root.name` is the literal, non-user-facing string "PLAYLIST"
     * on every real fixture seen, per `propresenter7_playlist.php`'s own
     * "UNCONFIRMED corner #5" note, so it is deliberately never read here) ->
     * the uploaded filename's stem -> a fixed fallback. Mirrors the
     * title-fallback ladders every other `_bulkImport_*` parser in this file
     * already uses (never an empty Name).
     *
     * @param array       $document     `pp7ReadPlaylistBundle()['document']`
     * @param string|null $filenameHint original upload filename
     */
    function _bulkImport_proplaylistName(array $document, ?string $filenameHint): string
    {
        $top = $document['playlists'][0] ?? null;
        if (is_array($top)) {
            $name = trim((string)($top['name'] ?? ''));
            if ($name !== '') {
                return mb_substr($name, 0, 200); // tblUserSetlists.Name width (schema.sql)
            }
        }
        if ($filenameHint !== null) {
            $stem = trim((string)pathinfo($filenameHint, PATHINFO_FILENAME));
            if ($stem !== '') {
                return mb_substr($stem, 0, 200);
            }
        }
        return 'Imported Playlist';
    }
}

if (!function_exists('_bulkImport_proplaylistMatchEntry')) {
    /**
     * Resolve a `PlaylistItem.Presentation.documentPath` (the decoder's
     * `pp7DecodeUrl()` shape — `{absoluteString, localRoot, localPath}`) to
     * one of the bundle's own `.pro` entry NAMES, by URL-DECODED BASENAME —
     * the plan §5.1 point 3 rule, byte-verified against the three committed
     * real `.proplaylist` fixtures during this task. Pure — no DB, no ZIP
     * bytes (works on entry NAMES only; the caller extracts bytes only for
     * whichever ONE entry this resolves to).
     *
     * Tries `absoluteString` first (a percent-encoded `file://` URL on every
     * real fixture — `rawurldecode()` undoes the percent-encoding before
     * `basename()`), then falls back to `localPath` (already a plain
     * relative path, no decoding needed) — the SAME two-field fallback
     * order `propresenter7_decode.php`'s own media-ref resolution note
     * documents for the identical "match by decoded basename" problem one
     * layer down (`.probundle` media refs, deferred to P4). Matched
     * case-insensitively (`strcasecmp`) — no real fixture uses mixed case,
     * but a case-sensitive match would silently fail one that did.
     *
     * @param array          $documentPath  `pp7DecodePlaylistItemPresentation()['documentPath']`
     * @param array<int,string> $proEntryNames `pp7ReadPlaylistBundle()['proEntries']`
     * @return string|null the matched entry name, or null (referenced but not embedded)
     */
    function _bulkImport_proplaylistMatchEntry(array $documentPath, array $proEntryNames): ?string
    {
        $candidates = [];
        if (!empty($documentPath['absoluteString'])) {
            $candidates[] = (string)$documentPath['absoluteString'];
        }
        if (!empty($documentPath['localPath'])) {
            $candidates[] = (string)$documentPath['localPath'];
        }

        foreach ($candidates as $raw) {
            $decoded = rawurldecode($raw);
            $base    = basename(str_replace('\\', '/', $decoded));
            if ($base === '') {
                continue;
            }
            foreach ($proEntryNames as $entryName) {
                if (strcasecmp(basename($entryName), $base) === 0) {
                    return $entryName;
                }
            }
        }
        return null;
    }
}

if (!function_exists('_bulkImport_proplaylistFlattenItems')) {
    /**
     * Flatten a `pp7ReadPlaylistBundle()['document']['playlists']`-shaped
     * tree (an array of `pp7DecodePlaylist()` nodes, each carrying its own
     * `items[]` AND a possibly-nested `playlists[]`) into ONE ordered list
     * of `{kind:'header', name:string}` / `{kind:'item', item:array}`
     * entries — pure, no DB, no ZIP bytes; a deterministic function of the
     * already-decoded tree.
     *
     * ELI5: a playlist can contain FOLDERS of songs, not just a flat list.
     * This turns the whole folder tree into ONE flat running order, adding a
     * section-divider line each time it steps INTO a folder, so the folder
     * boundary isn't silently lost.
     *
     * DETAILED — why only `$depth > 0` nodes get a synthetic header: at
     * `$depth === 0` we are walking `document.playlists[]` itself — per
     * `propresenter7_playlist.php`'s own "UNCONFIRMED corner #1" note this
     * is, on every real fixture seen, exactly ONE node (the whole service),
     * whose OWN name becomes the set list's `Name`
     * (`_bulkImport_proplaylistName()`) rather than a header INSIDE it — a
     * set list titled "Sunday Service" does not also need a redundant
     * "Sunday Service" divider as its very first row. A node reached by
     * recursing into a PARENT's `playlists[]` (`$depth >= 1` — a genuine
     * nested folder/group, UNCONFIRMED corner #2, no real fixture exercises
     * this) IS a real sub-grouping worth naming, so its `name` becomes a
     * synthetic header line immediately before its own items. An unnamed
     * nested node (empty/whitespace `name`) contributes no header line —
     * nothing to show.
     *
     * @param array<int,array> $playlists  `pp7DecodePlaylist()`-shaped nodes
     * @param int              $depth      recursion depth (0 = document's own top-level playlists[])
     * @return array<int,array{kind:string}> ordered flat list, see above
     */
    function _bulkImport_proplaylistFlattenItems(array $playlists, int $depth = 0): array
    {
        $flat = [];
        foreach ($playlists as $node) {
            if (!is_array($node)) {
                continue;
            }
            if ($depth > 0) {
                $nodeName = trim((string)($node['name'] ?? ''));
                if ($nodeName !== '') {
                    $flat[] = ['kind' => 'header', 'name' => $nodeName];
                }
            }
            foreach ((array)($node['items'] ?? []) as $item) {
                if (is_array($item)) {
                    $flat[] = ['kind' => 'item', 'item' => $item];
                }
            }
            $childPlaylists = (array)($node['playlists'] ?? []);
            if (!empty($childPlaylists)) {
                foreach (_bulkImport_proplaylistFlattenItems($childPlaylists, $depth + 1) as $child) {
                    $flat[] = $child;
                }
            }
        }
        return $flat;
    }
}

if (!function_exists('_bulkImport_proplaylistBuildPlan')) {
    /**
     * THE pure item -> {song|header|placeholder|skip} MAPPING decision (plan
     * §5.1, this section's file-level doc-block). Pure — no DB, no ZIP
     * bytes; takes the already-decoded document tree + the bundle's `.pro`
     * entry names and returns an ORDERED, flat "plan": one entry per
     * playlist item (plus a synthetic header per nested folder — see
     * `_bulkImport_proplaylistFlattenItems()`), each tagged with its `kind`:
     *
     *   - `{kind:'header', label}` — a real `header` item OR a synthetic
     *     nested-folder-name header.
     *   - `{kind:'placeholder', label}` — a real `placeholder` item.
     *   - `{kind:'song-embedded', entryName, itemName, arrangementName}` — a
     *     `presentation` item whose `documentPath` resolved to a `.pro`
     *     entry actually IN the bundle.
     *   - `{kind:'song-unresolved', itemName, documentPath}` — a
     *     `presentation` item whose `.pro` is NOT in the bundle (referenced
     *     by path only) — the CALLER resolves this against the existing
     *     catalogue (a DB operation, hence not done here).
     *   - `{kind:'skipped', itemType, label}` — `cue` / `planning_center` /
     *     an unrecognised item type / a hidden item — out of P3 scope, or
     *     the leader had it hidden in ProPresenter. The caller turns this
     *     into a warning, never a silent drop.
     *
     * This is the function `tests/php/test-pp7-proplaylist-import.php`
     * exercises directly against the three committed real fixtures — no
     * database, no mocking, just `pp7ReadPlaylistBundle()`'s own output fed
     * straight in.
     *
     * @param array             $document      `pp7ReadPlaylistBundle()['document']`
     * @param array<int,string> $proEntryNames `pp7ReadPlaylistBundle()['proEntries']`
     * @return array<int,array{kind:string}>
     */
    function _bulkImport_proplaylistBuildPlan(array $document, array $proEntryNames): array
    {
        $flat = _bulkImport_proplaylistFlattenItems((array)($document['playlists'] ?? []), 0);
        $plan = [];

        foreach ($flat as $f) {
            if ($f['kind'] === 'header') {
                $plan[] = ['kind' => 'header', 'label' => $f['name']];
                continue;
            }

            $item     = $f['item'];
            $itemName = trim((string)($item['name'] ?? ''));

            /* A leader-hidden item (`is_hidden`) is a real, recognised state
               — never silently dropped, but never rendered into the set
               list either; recorded as skipped so a warning names it. No
               real committed fixture exercises this (all `is_hidden` false)
               — a defensive, documented branch for a real schema field. */
            if (!empty($item['isHidden'])) {
                $plan[] = ['kind' => 'skipped', 'itemType' => 'hidden', 'label' => $itemName];
                continue;
            }

            switch ($item['itemType'] ?? 'unknown') {
                case 'header':
                    $plan[] = ['kind' => 'header', 'label' => $itemName !== '' ? $itemName : 'Section'];
                    break;

                case 'placeholder':
                    $plan[] = ['kind' => 'placeholder', 'label' => $itemName !== '' ? $itemName : 'Placeholder'];
                    break;

                case 'presentation':
                    $docPath = (array)($item['presentation']['documentPath'] ?? []);
                    $matched = _bulkImport_proplaylistMatchEntry($docPath, $proEntryNames);
                    if ($matched !== null) {
                        $plan[] = [
                            'kind'            => 'song-embedded',
                            'entryName'       => $matched,
                            'itemName'        => $itemName,
                            'arrangementName' => $item['presentation']['arrangementName'] ?? null,
                        ];
                    } else {
                        $plan[] = [
                            'kind'         => 'song-unresolved',
                            'itemName'     => $itemName,
                            'documentPath' => $docPath,
                        ];
                    }
                    break;

                case 'cue':
                case 'planningCenter':
                default:
                    /* 'cue' / 'planningCenter' / 'unknown' (a malformed item
                       with none of the five oneof branches set) — plan §5.1
                       point 4: named explicitly, skipped, never a crash. */
                    $plan[] = [
                        'kind'     => 'skipped',
                        'itemType' => (string)($item['itemType'] ?? 'unknown'),
                        'label'    => $itemName,
                    ];
                    break;
            }
        }

        return $plan;
    }
}

if (!function_exists('_bulkImport_proplaylistResolveExistingSong')) {
    /**
     * Resolve a playlist item's NAME against the EXISTING catalogue by EXACT
     * normalised title — the fallback for a `presentation` item whose `.pro`
     * is referenced but not embedded in the bundle (plan §5.1 point 3).
     *
     * CCLI is deliberately NOT attempted: `PlaylistItem.Presentation` (see
     * `propresenter7_playlist.php`'s field table) carries only
     * `document_path` / `arrangement` / `arrangement_name` — no CCLI field
     * exists on this message at all, so there is nothing to match on beyond
     * the item's own display name. Recorded here rather than silently
     * narrowing the task brief's "title/CCLI if possible" without comment.
     *
     * Gated on `searchFoldReady()` (the SAME gate `includes/search_fold.php`
     * itself uses) rather than a raw `NormalizedTitle` read — an un-migrated
     * install has neither the column nor its FULLTEXT index, and this
     * function degrades to "no match" (the caller falls back to a
     * placeholder + warning) rather than throwing under STRICT mysqli or
     * scanning the whole `tblSongs` table (rule #17's "never materialise the
     * whole corpus", applied to a lookup instead of a bulk read).
     *
     * Uses `ihymns_normalize_title()` directly — the EXACT fold
     * `NormalizedTitle` itself is populated with (rule #22: keep the two
     * normalisers distinct; this is the EXACT dedup fold, not the fuzzy
     * compare fold `song_similarity.php` owns) — so a stored row and this
     * lookup can never skew.
     *
     * #1694/#1765 VISIBILITY: unlike `_bulkImport_proplaylistResolveSkippedSong()`
     * below (which must see hidden/disabled rows to correctly re-resolve a
     * `_bulkImport_saveSong()` dedupe decision that itself sees them), this
     * function is picking a NEW public-catalogue link to embed in a curator's
     * imported set list — `songVisibleSql()` (soft-delete) AND
     * `songServableSql()` (disabled-songbook) are embedded so a playlist
     * import can never silently link a set list to a soft-deleted song or one
     * living in a disabled songbook; both degrade to `1=1` un-migrated
     * (test-song-visibility-guard.php / test-songbook-visibility-guard.php).
     *
     * @param \mysqli $db
     * @param string  $title the playlist item's own display name
     * @return array{songId:string,title:string,songbookAbbr:string,number:int}|null
     */
    function _bulkImport_proplaylistResolveExistingSong(\mysqli $db, string $title): ?array
    {
        $title = trim($title);
        if ($title === '') {
            return null;
        }
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'search_fold.php';
        if (!searchFoldReady($db)) {
            return null;
        }
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'title_normalize.php';
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'songbook_visibility.php';
        $norm = mb_substr(ihymns_normalize_title($title), 0, 500); // matches searchFoldSyncSong()'s own cap
        if ($norm === '') {
            return null;
        }
        $stmt = $db->prepare(
            'SELECT SongId, Title, SongbookAbbr, Number FROM tblSongs
              WHERE NormalizedTitle = ? AND ' . songVisibleSql($db, '') . ' AND ' . songServableSql($db, '') . '
              LIMIT 1'
        );
        $stmt->bind_param('s', $norm);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row === null) {
            return null;
        }
        return [
            'songId'       => (string)$row['SongId'],
            'title'        => (string)$row['Title'],
            'songbookAbbr' => (string)$row['SongbookAbbr'],
            'number'       => $row['Number'] !== null ? (int)$row['Number'] : 0,
        ];
    }
}

if (!function_exists('_bulkImport_proplaylistResolveSkippedSong')) {
    /**
     * Re-resolve the REAL existing SongId a 'skipped' `_bulkImport_processPro7()`
     * call matched — see this section's own file-level doc-block, DE-DUPE
     * point (2), for why this is needed at all: `_bulkImport_saveSong()`
     * reports only `'skipped'`, never WHICH row it matched.
     *
     * Two-step, mirroring the two ways `_bulkImport_saveSong()` itself can
     * decide to skip:
     *   1. Literal SongId already exists (the pre-flight existence check —
     *      in practice this almost never fires for a `.pro` import, since
     *      `_bulkImport_nextSongNumberFor()` always allocates a FRESH
     *      number, but it is the cheap, always-correct check to try first):
     *      `$attemptedSongId` itself names the existing row.
     *   2. Title-dedupe (`_bulkImport_dedupeMode() === 'skip-title'`) — the
     *      realistic path for a `.proplaylist` re-import. Re-runs the
     *      IDENTICAL matcher `_bulkImport_saveSong()` used
     *      (`_bulkImport_findDuplicateCandidates()`, same `$db`/
     *      `$songbookAbbr`/`$title`) and takes its first candidate — the
     *      same "first non-self match" the saver's own loop would have
     *      hit. Deterministic given the same inputs on the same connection.
     *
     * @return array{songId:string,title:string,songbookAbbr:string,number:int}|null
     *         null only on a genuine race (the matched row vanished between
     *         `_bulkImport_saveSong()`'s check and this re-resolve) — the
     *         caller falls back to the FRESH (unused) id + a warning rather
     *         than crashing.
     */
    function _bulkImport_proplaylistResolveSkippedSong(
        \mysqli $db,
        string $songbookAbbr,
        string $title,
        string $attemptedSongId
    ): ?array {
        if ($attemptedSongId !== '') {
            /* @deleted-visible: this MUST see the same row _bulkImport_saveSong()'s
               own pre-flight existence check saw (#1694) — a hidden/soft-deleted
               row still occupies its SongId, and that check's 'skipped' verdict
               is exactly what this function is re-resolving. Filtering it out
               here would disagree with the decision already made. */
            /* @disabled-visible: mirrors _bulkImport_findDuplicateCandidates()'s
               own posture immediately below — importer/dedupe matching operates
               over all songbooks regardless of public disabled state. */
            $stmt = $db->prepare('SELECT SongId, Title, SongbookAbbr, Number FROM tblSongs WHERE SongId = ? LIMIT 1');
            $stmt->bind_param('s', $attemptedSongId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row !== null) {
                return [
                    'songId'       => (string)$row['SongId'],
                    'title'        => (string)$row['Title'],
                    'songbookAbbr' => (string)$row['SongbookAbbr'],
                    'number'       => $row['Number'] !== null ? (int)$row['Number'] : 0,
                ];
            }
        }
        if ($songbookAbbr === '' || $title === '') {
            return null;
        }
        $cands = _bulkImport_findDuplicateCandidates($db, $songbookAbbr, $title);
        if (empty($cands)) {
            return null;
        }
        $c = $cands[0];
        return [
            'songId'       => (string)$c['SongId'],
            'title'        => (string)$c['Title'],
            'songbookAbbr' => $songbookAbbr,
            'number'       => $c['Number'] !== null ? (int)$c['Number'] : 0,
        ];
    }
}

if (!function_exists('_bulkImport_proplaylistResolveEmbedded')) {
    /**
     * Import (or resolve an already-imported) `.pro` ZIP entry for ONE
     * `song-embedded` plan entry, via the UNCHANGED `_bulkImport_processPro7()`
     * — adds zero new song-writing logic (same posture as
     * `_bulkImport_processProbundle()`'s doc-block). `$cache` is keyed by
     * ZIP entry name and shared across the whole playlist walk by the caller
     * (passed by reference) — see this section's DE-DUPE point (1): two
     * playlist items pointing at the SAME `.pro` entry (a reprised chorus)
     * hit the cache on the second lookup rather than re-importing.
     *
     * @param \mysqli                  $db
     * @param string                   $bytes             the WHOLE `.proplaylist` ZIP's raw bytes
     * @param array<string,array>      $zipEntriesByName  `pp7ZipListEntries()` results, keyed by `name`
     * @param string                   $entryName         the matched `.pro` entry name
     * @param array<string,array>      $cache             entry-name -> result, BY REFERENCE
     * @return array{ok:bool,created:int,skipped:int,failed:int,songbooksCreated:array,songbooksExisting:array,warnings:array,error:?string,songRef:?array}
     */
    function _bulkImport_proplaylistResolveEmbedded(
        \mysqli $db,
        string $bytes,
        array $zipEntriesByName,
        string $entryName,
        array &$cache,
        array $mediaEntriesByBasename = [],
        ?int $userId = null,
        ?array &$mediaIngestAcc = null
    ): array {
        if (isset($cache[$entryName])) {
            /* Cache hit — a `.pro` referenced by two playlist items imports (and
               ingests its media) exactly ONCE; the media counts were already
               accumulated on the first, non-cached resolution below. */
            return $cache[$entryName];
        }

        $fail = static function (string $reason): array {
            return [
                'ok' => false, 'created' => 0, 'skipped' => 0, 'failed' => 1,
                'songbooksCreated' => [], 'songbooksExisting' => [], 'warnings' => [],
                'error' => $reason, 'songRef' => null,
            ];
        };

        if (!isset($zipEntriesByName[$entryName])) {
            return $cache[$entryName] = $fail("entry '{$entryName}' could not be re-located in the bundle");
        }

        try {
            $proBytes = pp7ZipReadEntry($bytes, $zipEntriesByName[$entryName]);
        } catch (\Throwable $e) {
            return $cache[$entryName] = $fail('could not extract from bundle: ' . $e->getMessage());
        }

        /* THE reuse point — identical to _bulkImport_processProbundle()'s
           own single call site: one _bulkImport_processPro7() call per
           entry, this entry's OWN name as its filenameHint. */
        $inner = _bulkImport_processPro7($proBytes, $entryName);
        if (!($inner['ok'] ?? false)) {
            return $cache[$entryName] = $fail($inner['error'] ?? 'ProPresenter 7+ parse failed');
        }

        $songId       = (string)($inner['song_id'] ?? '');
        $title        = (string)($inner['title'] ?? '');
        $songbookAbbr = (string)($inner['songbook_abbr'] ?? '');
        $number       = (int)($inner['number'] ?? 0);

        /* DE-DUPE point (2) — see this section's file-level doc-block. */
        if ((int)($inner['songs_skipped_existing'] ?? 0) === 1) {
            $resolved = _bulkImport_proplaylistResolveSkippedSong($db, $songbookAbbr, $title, $songId);
            if ($resolved !== null) {
                $songId       = $resolved['songId'];
                $title        = $resolved['title'];
                $songbookAbbr = $resolved['songbookAbbr'];
                $number       = $resolved['number'];
            }
        }

        $ok = $songId !== '';

        /* #1968 P4 — ingest this song's referenced media into tblSongMedia
           (admin-only, D1), ONCE per unique entry (this is the non-cached path).
           Additive + dormant behind the ingest core's own gate. */
        $mediaWarnings = [];
        if ($ok && !empty($inner['media_refs']) && !empty($mediaEntriesByBasename)) {
            $ic = _bulkImport_pp7IngestMedia(
                $db, $songId, $inner['media_refs'], $bytes,
                $mediaEntriesByBasename, $userId, 'bulk_import_proplaylist', $mediaWarnings
            );
            if ($mediaIngestAcc !== null) {
                foreach ($ic as $k => $v) { $mediaIngestAcc[$k] += $v; }
            }
        }

        return $cache[$entryName] = [
            'ok'                => $ok,
            'created'           => (int)($inner['songs_created'] ?? 0),
            'skipped'           => (int)($inner['songs_skipped_existing'] ?? 0),
            'failed'            => $ok ? (int)($inner['songs_failed'] ?? 0) : 1,
            'songbooksCreated'  => (array)($inner['songbooks_created'] ?? []),
            'songbooksExisting' => (array)($inner['songbooks_existing'] ?? []),
            'warnings'          => array_merge((array)($inner['warnings'] ?? []), $mediaWarnings),
            'error'             => $ok ? null : 'song could not be resolved to a SongId',
            'songRef'           => $ok
                ? ['id' => $songId, 'title' => $title, 'songbook' => $songbookAbbr, 'number' => $number]
                : null,
        ];
    }
}

if (!function_exists('_bulkImport_proplaylistMintSetlistId')) {
    /**
     * Mint a fresh, unique `tblUserSetlists.SetlistId` for THIS owner. Same
     * charset precedent `userSyncSanitiseId()` (`includes/user_sync.php`)
     * enforces on a client-generated id (`[a-zA-Z0-9_-]`, well under the
     * `VARCHAR(100)` column) — a `pp-` prefix makes an imported set list's
     * id visibly distinct from a client-minted one in any future debugging,
     * without meaning anything structurally. Collision-checked against the
     * SAME `(UserId, SetlistId)` UNIQUE key the column itself enforces
     * (`uq_UserSetlist`, schema.sql) — astronomically unlikely with 12 hex
     * bytes of entropy, checked anyway rather than trusted blindly.
     */
    function _bulkImport_proplaylistMintSetlistId(\mysqli $db, int $userId): string
    {
        for ($i = 0; $i < 5; $i++) {
            $candidate = 'pp-' . bin2hex(random_bytes(6));
            $stmt = $db->prepare('SELECT 1 FROM tblUserSetlists WHERE UserId = ? AND SetlistId = ? LIMIT 1');
            $stmt->bind_param('is', $userId, $candidate);
            $stmt->execute();
            $exists = $stmt->get_result()->fetch_row() !== null;
            $stmt->close();
            if (!$exists) {
                return $candidate;
            }
        }
        // Practically unreachable (5 failed 48-bit-random draws in a row) — a
        // timestamp suffix guarantees uniqueness rather than looping forever.
        return 'pp-' . bin2hex(random_bytes(6)) . '-' . time();
    }
}

/**
 * THE DB-touching orchestrator: decode a `.proplaylist`, import every
 * embedded song, resolve every referenced-but-not-embedded one against the
 * catalogue, and write ONE `tblUserSetlists` row for `$userId`. See this
 * section's file-level doc-block for the full design (mapping rules,
 * de-dupe, why a plain INSERT rather than a forked "create setlist" core).
 *
 * Honours `_bulkImport_dryRun()` (#1674) for the SETLIST INSERT itself, on
 * top of the dry-run awareness `_bulkImport_processPro7()` -> `_bulkImport_
 * saveSong()` already provide for every song write — under dry-run this
 * function still computes and returns the REAL would-be summary (song
 * counts, the resolved plan, `setlists_created`) without executing the
 * INSERT, mirroring `_bulkImport_saveSong()`'s own "identical decision,
 * suppressed write" dry-run contract.
 *
 * @param int         $userId       the AUTHENTICATED session's user id — the
 *                                  imported set list's owner. Threaded in by
 *                                  the caller (never resolved here — this
 *                                  file has no session access, by design;
 *                                  see rule #22 / the file's D2 note).
 * @param string      $bytes        raw bytes of one `.proplaylist` (a ZIP)
 * @param string|null $filenameHint original upload filename
 * @return array{ok:bool,error?:string,songbooks_created:array,songbooks_existing:array,
 *   songs_created:int,songs_skipped_existing:int,songs_failed:int,setlists_created:int,
 *   setlist:?array,errors:array,warnings:array,media_present:int,media_files:array}
 * @see .claude/propresenter-interop-1968-plan.md   §5.1
 * @see tests/php/test-pp7-proplaylist-import.php    real-fixture validation
 */
function _bulkImport_processProplaylist(int $userId, string $bytes, ?string $filenameHint = null): array
{
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'propresenter7_playlist.php';
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'propresenter7_zip.php';
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'setlist_collab.php';
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'setlist_templates.php';

    $label = ($filenameHint !== null && $filenameHint !== '') ? $filenameHint : 'the uploaded playlist';

    $emptyFail = static function (string $reason, int $mediaCount = 0, array $mediaNames = []): array {
        return [
            'ok'                     => false,
            'error'                  => $reason,
            'songbooks_created'      => [],
            'songbooks_existing'     => [],
            'songs_created'          => 0,
            'songs_skipped_existing' => 0,
            'songs_failed'           => 0,
            'setlists_created'       => 0,
            'setlist'                => null,
            'errors'                 => [],
            'warnings'               => [],
            'media_present'          => $mediaCount,
            'media_files'            => $mediaNames,
        ];
    };

    if ($userId <= 0) {
        // D2 (§12.3) — curator-first: every caller of this function is
        // already editor-gated (api.php / api2.php), so a non-positive id
        // here means a caller forgot to thread the session user, not a
        // genuine anonymous request slipping through. Refuse cleanly rather
        // than minting a set list with no real owner.
        return $emptyFail('No signed-in user to own the imported set list.');
    }

    try {
        $bundle = pp7ReadPlaylistBundle($bytes);
    } catch (\Throwable $e) {
        return $emptyFail("Could not read {$label} as a ProPresenter playlist: " . $e->getMessage());
    }

    try {
        $zipEntries = pp7ZipListEntries($bytes);
    } catch (\Throwable $e) {
        // pp7ReadPlaylistBundle() above already proved this ZIP opens (it
        // uses the SAME reader internally) — this second call exists only
        // to get full entry dicts (offset/size) for pp7ZipReadEntry() below,
        // so reaching this catch on real input would mean the reader
        // disagreed with itself. Never observed; handled anyway rather than
        // trusted blindly.
        return $emptyFail("Could not re-read {$label}'s entries: " . $e->getMessage());
    }
    $zipEntriesByName = [];
    foreach ($zipEntries as $e) {
        $zipEntriesByName[$e['name']] = $e;
    }

    $document      = $bundle['document'];
    $proEntryNames = $bundle['proEntries'];
    $mediaNames    = $bundle['mediaEntries'];
    $mediaCount    = count($mediaNames);

    $plan         = _bulkImport_proplaylistBuildPlan($document, $proEntryNames);
    $playlistName = _bulkImport_proplaylistName($document, $filenameHint);

    $db = getDbMysqli();

    $songs             = [];
    $slots             = [];
    $warnings          = [];
    $errors            = [];
    $songsCreated      = 0;
    $songsSkipped      = 0;
    $songsFailed       = 0;
    $songbooksCreated  = [];
    $songbooksExisting = [];
    $cache             = [];
    $slotSeq           = 0;

    /* #1968 P4 — media ingest context: index the bundle's media entry OBJECTS
       (looked up from $zipEntriesByName by name) by decoded basename once, plus
       an accumulator and the one-shot gate. Dormant until pp7_media_ingest_enabled. */
    $mediaEntryObjects = [];
    foreach ($mediaNames as $mn) {
        if (isset($zipEntriesByName[$mn])) { $mediaEntryObjects[] = $zipEntriesByName[$mn]; }
    }
    $mediaByBasename = _bulkImport_pp7IndexMediaByBasename($mediaEntryObjects);
    $mediaIngestAcc  = ['ingested' => 0, 'duplicate' => 0, 'unresolved' => 0, 'skipped' => 0];
    $ingestActive    = _bulkImport_pp7MediaIngestActive($db);

    foreach ($plan as $entry) {
        switch ($entry['kind']) {
            case 'header':
                $slots[] = ['id' => 'h' . (++$slotSeq), 'label' => $entry['label'], 'type' => 'other'];
                break;

            case 'placeholder':
                $slots[] = ['id' => 'p' . (++$slotSeq), 'label' => $entry['label'], 'type' => 'other'];
                $warnings[] = "placeholder \"{$entry['label']}\" imported as a spacer slot (no song attached)";
                break;

            case 'skipped':
                $itemType = (string)($entry['itemType'] ?? 'unknown');
                $noun     = $itemType === 'hidden' ? 'hidden item' : "{$itemType} item";
                $warnings[] = 'skipped ' . $noun
                    . ($entry['label'] !== '' ? " \"{$entry['label']}\"" : '')
                    . ' (out of scope for this import)';
                break;

            case 'song-embedded':
                $res = _bulkImport_proplaylistResolveEmbedded(
                    $db, $bytes, $zipEntriesByName, $entry['entryName'], $cache,
                    $mediaByBasename, $userId, $mediaIngestAcc
                );
                $songsCreated += $res['created'];
                $songsSkipped += $res['skipped'];
                foreach ($res['songbooksCreated'] as $a) {
                    $songbooksCreated[$a] = true;
                }
                foreach ($res['songbooksExisting'] as $a) {
                    if (!isset($songbooksCreated[$a])) {
                        $songbooksExisting[$a] = true;
                    }
                }
                foreach ($res['warnings'] as $w) {
                    $warnings[] = "{$entry['entryName']}: {$w}";
                }
                if ($res['ok']) {
                    $songs[] = $res['songRef'];
                } else {
                    $songsFailed += $res['failed'];
                    $errors[] = ['entry' => $entry['entryName'], 'error' => $res['error'] ?? 'import failed'];
                }
                break;

            case 'song-unresolved':
                $resolved = _bulkImport_proplaylistResolveExistingSong($db, (string)$entry['itemName']);
                if ($resolved !== null) {
                    $songs[] = [
                        'id'       => $resolved['songId'],
                        'title'    => $resolved['title'],
                        'songbook' => $resolved['songbookAbbr'],
                        'number'   => $resolved['number'],
                    ];
                    $warnings[] = "\"{$entry['itemName']}\" referenced a presentation not found in the bundle"
                        . " — matched to existing song {$resolved['songId']} by title";
                } else {
                    $label = $entry['itemName'] !== '' ? $entry['itemName'] : 'Missing song';
                    $slots[] = [
                        'id'    => 'u' . (++$slotSeq),
                        'label' => $label,
                        'type'  => 'song',
                        'note'  => 'referenced presentation not found in the bundle',
                    ];
                    $warnings[] = "\"{$label}\" referenced a presentation not found in the bundle"
                        . ' — added as a placeholder (no matching song by title)';
                }
                break;
        }
    }

    $sanitisedSongs = setlistCollabSanitiseSongs($songs);
    $rawPlan        = !empty($slots) ? ['templateName' => $playlistName, 'slots' => $slots] : null;
    $sanitisedPlan  = setlistTemplateSanitisePlan($rawPlan);

    $setlistsCreated = 0;
    $setlistInfo     = null;

    if (count($sanitisedSongs) > setlistCollabMaxSongs()) {
        // #1662's anti-truncation posture, applied here: refuse to mint a
        // silently-shortened set list rather than dropping the tail. The
        // songs already imported above stay imported — only the SET LIST
        // itself is skipped.
        $warnings[] = 'the imported set list would exceed the ' . setlistCollabMaxSongs()
            . '-song cap and was not created; the songs above were still imported';
    } elseif (!empty($sanitisedPlan['slots'] ?? []) && setlistTemplateSlotsExceedCap($sanitisedPlan['slots'])) {
        $warnings[] = 'the imported set list\'s running order would exceed the ' . setlistTemplateMaxSlots()
            . '-slot cap and was not created; the songs above were still imported';
    } else {
        /* Task 1: "Empty playlist -> a clean result (an empty or zero set
           list, per the app's rules), never a crash" — this branch always
           runs (even for zero songs + no plan), so an empty playlist gets
           ONE real, empty, correctly-named set list, matching Task 1's
           "build ONE iHymns set list" for every import, not just a
           non-empty one. */
        $setlistId = _bulkImport_proplaylistMintSetlistId($db, $userId);
        $songsJson = (string)json_encode($sanitisedSongs, JSON_UNESCAPED_UNICODE);
        $slotsReady = setlistSlotsColumnReady($db);

        if (!_bulkImport_dryRun()) {
            if ($slotsReady && $sanitisedPlan !== null) {
                $slotsJson = setlistTemplateEncodePlan($sanitisedPlan);
                $ins = $db->prepare(
                    'INSERT INTO tblUserSetlists (UserId, SetlistId, Name, SongsJson, CreatedAt, UpdatedAt, SlotsJson)
                     VALUES (?, ?, ?, ?, NOW(), NOW(), ?)'
                );
                $ins->bind_param('issss', $userId, $setlistId, $playlistName, $songsJson, $slotsJson);
            } else {
                // Un-migrated install (no SlotsJson column) OR nothing to
                // put in a plan — the base 6-column INSERT every write path
                // falls back to (mirrors api.php's own $expiryReady/$slotsReady
                // branch shape for this exact table).
                $ins = $db->prepare(
                    'INSERT INTO tblUserSetlists (UserId, SetlistId, Name, SongsJson, CreatedAt, UpdatedAt)
                     VALUES (?, ?, ?, ?, NOW(), NOW())'
                );
                $ins->bind_param('isss', $userId, $setlistId, $playlistName, $songsJson);
            }
            $ins->execute();
            $ins->close();

            if (function_exists('logActivity')) {
                logActivity('setlist.import', 'setlist', $setlistId, [
                    'source' => 'bulk_import_proplaylist',
                    'name'   => $playlistName,
                    'songs'  => count($sanitisedSongs),
                    'slots'  => count($sanitisedPlan['slots'] ?? []),
                ]);
            }
        }

        $setlistsCreated = 1;
        $setlistInfo = [
            'setlistId' => $setlistId,
            'name'      => $playlistName,
            'songCount' => count($sanitisedSongs),
            'slotCount' => count($sanitisedPlan['slots'] ?? []),
        ];
    }

    /* #1968 P4 — when media ingest is ACTIVE (owner flipped
       pp7_media_ingest_enabled) real counts speak for the media; otherwise the
       SAME shared deferred-media warning `.probundle` uses (rule #22/#35 — one
       wording, not two importers each typing their own prose) still fires so
       nothing is silently dropped. */
    if (!$ingestActive) {
        $mediaWarning = _bulkImport_pp7MediaDeferredWarning($mediaCount, $mediaNames);
        if ($mediaWarning !== null) {
            $warnings[] = $mediaWarning;
        }
    }

    $summary = [
        'ok'                     => true,
        'songbooks_created'      => array_keys($songbooksCreated),
        'songbooks_existing'     => array_keys($songbooksExisting),
        'songs_created'          => $songsCreated,
        'songs_skipped_existing' => $songsSkipped,
        'songs_failed'           => $songsFailed,
        'setlists_created'       => $setlistsCreated,
        'setlist'                => $setlistInfo,
        'errors'                 => $errors,
        'warnings'               => $warnings,
        'media_present'          => $mediaCount,
        'media_files'            => $mediaNames,
    ];
    if ($ingestActive) {
        $summary['media_ingested']   = (int)$mediaIngestAcc['ingested'];
        $summary['media_duplicate']  = (int)$mediaIngestAcc['duplicate'];
        $summary['media_unresolved'] = (int)$mediaIngestAcc['unresolved'];
    }
    return $summary;
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
    /* @disabled-visible: importer / batch system path (#1765) — operates over all
       songbooks regardless of public disabled state */
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
    /* @disabled-visible: importer / batch system path (#1765) — operates over all
       songbooks regardless of public disabled state */
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
    /* #1908 Commit 6 — see the VideoPsalm parser above for why a raw UTF-8
       BOM strip alone isn't enough (a UTF-16 .show export needs a real
       conversion, not just a BOM strip). See text_encoding.php. */
    require_once __DIR__ . '/text_encoding.php';
    $converted = ihymnsTextToUtf8($body);
    if ($converted === null) {
        return [null, 'file is not UTF-8 (or UTF-16) text — re-save it as UTF-8'];
    }
    $body = $converted;
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
    /* @disabled-visible: importer / batch system path (#1765) — operates over all
       songbooks regardless of public disabled state */
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
    /* @disabled-visible: importer / batch system path (#1765) — operates over all
       songbooks regardless of public disabled state */
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
    /* @disabled-visible: importer songbook-resolve (#1765) — resolves the target
       book by name for an admin import; a disabled book is a valid import target */
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
    require_once __DIR__ . '/text_encoding.php';   // #1908 Commit 6 — ihymnsTextToUtf8() below
    $fail = static fn(string $msg): array => [null, null, $msg];

    /* (0) SIZE GATE — must precede json_decode(), which is the allocation.
           See _BULK_IMPORT_IHYMNS_MAX_BYTES for the measurement behind 8 MiB.
           #1908: this MUST stay ahead of the UTF-8 conversion below too — it
           is a memory bound on the RAW upload bytes, and converting a wider
           encoding (UTF-16/UTF-32) down to UTF-8 can only ever GROW or hold
           steady the byte count, never shrink past this cap unnoticed. */
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

    /* #1908 Commit 6 — detect + convert UTF-16/UTF-32 (with or without a
       BOM) to UTF-8; a raw UTF-8 BOM strip alone (the old behaviour here)
       left a UTF-16 export a generic "invalid JSON" failure instead of a
       clear, actionable message. Same guard as the VideoPsalm parser. */
    $converted = ihymnsTextToUtf8($body);
    if ($converted === null) {
        return $fail('file is not UTF-8 (or UTF-16) text — re-save it as UTF-8');
    }
    $body = $converted;

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

        /* #1912 — alternative titles round-trip. The interchange calls the key
           "alternativeTitles" (matching what getSongs()/getSongById() now both
           emit, SongData.php); the shared saveSong write loop below (:812-826,
           #1669 C9) reads $song['altTitles'] as [{title, language, note}] and
           feeds it straight to the ONE song_alt_titles.php core — this block
           only maps the interchange spelling to the internal one, it does not
           re-implement the write. Optional on the wire: an export made before
           #1912 (or any hand-written fixture) simply has no "alternativeTitles"
           key, `(array)(... ?? [])` folds that to [], and the song imports
           byte-identically to before this change (rule #33 — never make an
           optional wire key required). Malformed entries (not an object, or an
           empty/whitespace-only title) are skipped here rather than failing the
           whole song — the write loop re-validates the same fields anyway, so
           this mirrors ITS tolerance instead of imposing a stricter one. */
        $altTitles = [];
        foreach ((array)($raw['alternativeTitles'] ?? []) as $altRaw) {
            if (!is_array($altRaw)) { continue; }
            $altTitleText = trim((string)($altRaw['title'] ?? ''));
            if ($altTitleText === '') { continue; }
            $altLangText = trim((string)($altRaw['language'] ?? ''));
            $altNoteText = trim((string)($altRaw['note'] ?? ''));
            $altTitles[] = [
                'title'    => $altTitleText,
                'language' => $altLangText !== '' ? $altLangText : null,
                'note'     => $altNoteText !== '' ? $altNoteText : null,
            ];
        }

        /* Song dict in the exact shape _bulkImport_saveSong() consumes — the same
           key set _bulkImport_assembleSong() produces for OpenLyrics/PP6, so this
           format needs no special case anywhere downstream.
           Credits (writers/composers/…) ARE now persisted by saveSong (#1736) —
           the shared saver writes the credit tables + promotes the registry, so
           every importer inherits it. The licensing / public-domain fields carried
           below (copyright / ccli / iswc / verified / lyrics- & music-public-domain)
           ARE now persisted too: saveSong reads them via _bulkImportRightsFromSong()
           and writes them on INSERT (#1673 / #1896, 8807152d), so every importer
           inherits that as well — no per-format fork is needed here. */
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
            'altTitles'          => $altTitles,
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
    /* @disabled-visible: importer / batch system path (#1765) — operates over all
       songbooks regardless of public disabled state */
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

