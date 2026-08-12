<?php

declare(strict_types=1);

/**
 * iHymns — line-first read assembler (#1235 P4 / C1, lyric-line normalisation)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * THE single place that assembles a song's normalised `tblLyricLines` back into the
 * component-shaped `['type','number','lines',…]` array every reader + exporter speaks
 * (the web/PWA API, ProPresenter / OpenLyrics / LRC / TTML serialisers, and the
 * iLyricsDB shared-line contract). #1235 P4 makes `tblLyricLines` the single source of
 * truth and retires the `tblSongComponents` JSON payload columns — so the line array is
 * *assembled on demand* here, never persisted as a rival truth.
 *
 * C-VARIANT (the approved P4 scope): `tblSongComponents` survives as a THIN metadata
 * row (Type / Number / SortOrder / Language); `tblLyricLines.ComponentId` is the
 * grouping anchor (proven losslessly reconstructable on the real corpus — 0
 * non-contiguous ComponentId runs, group order ≡ component SortOrder). Lines carry the
 * text, per-line language, chords and notes; the thin component supplies type/number/
 * language metadata. A future componentless source (TTML/LRC with `ComponentId IS NULL`)
 * groups by the denormed `PartType` / `PartNumber` carried on the line.
 *
 * BYTE-IDENTICAL CONTRACT: the component shape produced here matches
 * `SongData::_getComponents()` exactly (`type,number,lines,chords,language` + the
 * optional `lineIds` / sparse `lineLanguages`), so the P4b read switch (C4) is a clean
 * delegation and the L3 export-fidelity gate proves pre==post on all 16,083 songs.
 *
 * The assembly CORE (lyricLinesAssembleFromRows) is PURE — no DB, no I/O — so it is
 * unit-tested directly (tests/php/test-lyric-lines-read.php). The DB wrappers fetch the
 * rows (Source='ihymns'-keyed, never whole-corpus — chunked IN() for the bulk path,
 * #929) and hand them to the core.
 *
 * Requires a live `\mysqli` from getDbMysqli(). The DB layer runs mysqli under
 * MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT, so failing statements throw.
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

/** Max song ids per IN() chunk for the bulk fetch — bounds the prepared-statement
 *  placeholder count + memory; the corpus is read per-songbook, never all at once (#929). */
const LYRIC_LINES_READ_CHUNK = 500;

/**
 * Is the normalised `tblLyricLines` mirror FULLY present — i.e. carrying every column the
 * line-first read assembler + the projector touch? The ONE shared gate every line-first
 * reader checks before delegating here. Migrations are not auto-applied (rule #19) AND the
 * mirror is built across THREE independent migrations (`normalize-lyrics` creates the base
 * table; `song-part-types` adds `PartTypeSlug`; `lyric-lines-mirror` adds `ChordsJson` +
 * `Note`), so the bare table can exist WITHOUT those columns — the assembler's
 * `SELECT ll.ChordsJson` would then throw under MYSQLI_REPORT_STRICT (the C5 review's
 * blocker). So we require the late-added columns, NOT just the table; when any is absent the
 * reader falls back to the legacy `tblSongComponents.LinesJson` path. This is DELIBERATELY
 * the same column set as lyricLinesSyncReady() so the READ gate and the WRITE gate are
 * aligned — reads + writes flip to the mirror together, never leaving a window where reads
 * come from a mirror that writes don't maintain (or vice-versa). Memoised per request.
 */
function lyricLinesMirrorPresent(\mysqli $db): bool
{
    static $present = null;
    if ($present !== null) {
        return $present;
    }
    try {
        /* Require ChordsJson + Note + PartTypeSlug — the columns the assembler reads
           (ChordsJson) and the projector reads/writes (all three). Column names are
           hardcoded constants (rule #5). */
        $r = $db->query(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = 'tblLyricLines'
                AND COLUMN_NAME  IN ('ChordsJson', 'Note', 'PartTypeSlug')"
        );
        $row     = $r ? $r->fetch_row() : null;
        $present = ($row !== null && (int)$row[0] >= 3);
        if ($r) { $r->close(); }
    } catch (\Throwable $_e) {
        $present = false;
    }
    return $present;
}

/**
 * Assemble ordered per-line rows (one song) into the component-shaped array
 * `SongData::_getComponents()` returns. **PURE** — no DB, no I/O.
 *
 * Grouping: a new component starts whenever the grouping key changes from the previous
 * line, in the given (SortOrder) order — `ComponentId` when set, else the denormed
 * `PartType` + `PartNumber` (the future componentless path). Each row is expected to
 * carry its component's metadata (joined upstream): `comp_type`, `comp_number`,
 * `comp_lang`. The reconstructed `chords` parallel array mirrors what the retired
 * `tblSongComponents.ChordsJson` held (the projector decomposed it per line; this
 * recomposes it); `lineIds` is always emitted (lines ARE the source post-cutover);
 * `lineLanguages` is emitted ONLY when some line's language differs from the component
 * default — identical sparse rule to the pre-cutover reader.
 *
 * @param list<array{
 *   line_id:int|string, cid:int|string|null, text:string, line_lang:?string,
 *   line_chords:?string, comp_type:?string, comp_number:int|string|null,
 *   comp_lang:?string, line_parttype:?string, line_partnum:int|string|null
 * }> $rows  one song's lines, already ordered by global SortOrder
 * @return list<array{type:string,number:int,lines:list<string>,chords:?array,language:?string,lineIds?:list<int>,lineLanguages?:list<?string>}>
 */
function lyricLinesAssembleFromRows(array $rows): array
{
    $components = [];
    $cur        = null;     // the component being built
    $curKey     = null;     // grouping key of the current run

    $flush = static function (?array &$c) use (&$components): void {
        if ($c === null) { return; }
        /* Reconstruct the chords parallel array: null when no line carried chords
           (the universal case today), else the per-line decoded values — byte-equal
           to the array the retired ChordsJson column stored. */
        $anyChords = false;
        foreach ($c['_chords'] as $cell) {
            if ($cell !== null) { $anyChords = true; break; }
        }
        $out = [
            'type'     => $c['type'],
            'number'   => $c['number'],
            'lines'    => $c['lines'],
            'chords'   => $anyChords ? $c['_chords'] : null,
            'language' => $c['language'],
        ];
        /* lineIds parallel to 'lines' (same length + order); lines are authoritative,
           so always present. */
        $out['lineIds'] = $c['_lineIds'];
        /* Sparse effective per-line language: emit only when a line differs from the
           component default (mirrors SongData::_getComponents). */
        foreach ($c['_lineLangs'] as $ll) {
            if ($ll !== $c['language']) { $out['lineLanguages'] = $c['_lineLangs']; break; }
        }
        $components[] = $out;
        $c = null;
    };

    foreach ($rows as $row) {
        $cid = ($row['cid'] !== null && $row['cid'] !== '') ? (int)$row['cid'] : null;
        /* Group key: prefer ComponentId; fall back to part identity for componentless
           (TTML/LRC) lines so a future source still groups into parts. */
        $key = $cid !== null
            ? 'c:' . $cid
            : 'p:' . (string)($row['line_parttype'] ?? '') . ':' . (string)(int)($row['line_partnum'] ?? 0);

        if ($key !== $curKey) {
            $flush($cur);
            $curKey = $key;
            /* Component metadata: from the thin component when joined, else the
               line's denormed part identity (componentless path). */
            $type   = $row['comp_type'] !== null && $row['comp_type'] !== ''
                ? (string)$row['comp_type']
                : (string)($row['line_parttype'] ?? 'verse');
            $number = $row['comp_number'] !== null
                ? (int)$row['comp_number']
                : (int)($row['line_partnum'] ?? 0);
            $lang   = ($row['comp_lang'] !== null && $row['comp_lang'] !== '') ? (string)$row['comp_lang'] : null;
            $cur = [
                'type'      => $type !== '' ? $type : 'verse',
                'number'    => $number,
                'language'  => $lang,
                'lines'     => [],
                '_chords'   => [],
                '_lineIds'  => [],
                '_lineLangs'=> [],
            ];
        }

        $cur['lines'][]    = (string)$row['text'];
        $cur['_lineIds'][] = (int)$row['line_id'];
        /* Per-line chord cell: decode the line's stored JSON (null/string/array) — the
           element the retired ChordsJson[i] held. */
        $cur['_chords'][]  = ($row['line_chords'] !== null && $row['line_chords'] !== '')
            ? (json_decode((string)$row['line_chords'], true) ?? null)
            : null;
        $cur['_lineLangs'][] = ($row['line_lang'] !== null && $row['line_lang'] !== '')
            ? (string)$row['line_lang']
            : null;
    }
    $flush($cur);

    return $components;
}

/**
 * Fetch one song's primary ('ihymns') lyric lines in global SortOrder, each joined to
 * its thin component metadata. Keyed on `Source='ihymns'` only (never IsPrimary —
 * #1235 R6/PF3). Returns [] for a song with no mirrored lines (the LEFT-JOIN-shaped
 * "0-line lyrics" case). One query.
 *
 * @return list<array<string,mixed>>  rows shaped for lyricLinesAssembleFromRows()
 */
function lyricLinesFetchPrimary(\mysqli $db, string $songId): array
{
    $stmt = $db->prepare(
        "SELECT ll.Id        AS line_id,
                ll.ComponentId AS cid,
                ll.LineText    AS text,
                ll.LanguageCode AS line_lang,
                ll.ChordsJson  AS line_chords,
                ll.PartType    AS line_parttype,
                ll.PartNumber  AS line_partnum,
                sc.Type        AS comp_type,
                sc.Number      AS comp_number,
                sc.Language    AS comp_lang
           FROM tblLyricLines ll
           JOIN tblLyrics ly         ON ly.Id = ll.LyricsId
           LEFT JOIN tblSongComponents sc ON sc.Id = ll.ComponentId
          WHERE ly.SongId = ? AND ly.Source = 'ihymns'
          ORDER BY ll.SortOrder, ll.Id"
    );
    $stmt->bind_param('s', $songId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/**
 * Bulk variant of lyricLinesFetchPrimary() for getSongs() — chunked IN() (never the
 * whole corpus, #929), returned grouped by SongId in global SortOrder.
 *
 * @param string[] $songIds
 * @return array<string,list<array<string,mixed>>>  SongId → ordered line rows
 */
function lyricLinesFetchPrimaryMap(\mysqli $db, array $songIds): array
{
    $songIds = array_values(array_unique(array_filter($songIds, static fn($s) => $s !== '' && $s !== null)));
    if (empty($songIds)) { return []; }

    $out = [];
    foreach (array_chunk($songIds, LYRIC_LINES_READ_CHUNK) as $chunk) {
        $place = implode(',', array_fill(0, count($chunk), '?'));
        $types = str_repeat('s', count($chunk));
        $stmt  = $db->prepare(
            "SELECT ly.SongId   AS song_id,
                    ll.Id        AS line_id,
                    ll.ComponentId AS cid,
                    ll.LineText    AS text,
                    ll.LanguageCode AS line_lang,
                    ll.ChordsJson  AS line_chords,
                    ll.PartType    AS line_parttype,
                    ll.PartNumber  AS line_partnum,
                    sc.Type        AS comp_type,
                    sc.Number      AS comp_number,
                    sc.Language    AS comp_lang
               FROM tblLyricLines ll
               JOIN tblLyrics ly         ON ly.Id = ll.LyricsId
               LEFT JOIN tblSongComponents sc ON sc.Id = ll.ComponentId
              WHERE ly.SongId IN ({$place}) AND ly.Source = 'ihymns'
              ORDER BY ly.SongId, ll.SortOrder, ll.Id"
        );
        $stmt->bind_param($types, ...$chunk);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $sid = (string)$row['song_id'];
            unset($row['song_id']);
            $out[$sid][] = $row;
        }
        $stmt->close();
    }
    return $out;
}

/**
 * Assemble one song's components from the authoritative `tblLyricLines`. Byte-identical
 * to SongData::_getComponents(). Returns [] for a song with no mirrored lines.
 *
 * @return list<array<string,mixed>>
 */
function lyricLinesAssembleComponents(\mysqli $db, string $songId): array
{
    return lyricLinesAssembleFromRows(lyricLinesFetchPrimary($db, $songId));
}

/**
 * Bulk component assembly keyed by SongId (the getSongs() path). Only songs that have
 * mirrored lines appear in the map; the caller LEFT-JOIN-defaults the rest to [].
 *
 * @param string[] $songIds
 * @return array<string,list<array<string,mixed>>>
 */
function lyricLinesAssembleComponentsMap(\mysqli $db, array $songIds): array
{
    $out = [];
    foreach (lyricLinesFetchPrimaryMap($db, $songIds) as $sid => $rows) {
        $out[$sid] = lyricLinesAssembleFromRows($rows);
    }
    return $out;
}

/**
 * The first NON-EMPTY lyric line of a song's primary version (lowest SortOrder), or null
 * when it has none. The "preview line" the lightweight bypass readers (SongOfTheDay,
 * duplicate-songs, the link-suggestion builder) used to crack out of LinesJson[0] — they
 * all skipped blank lines, so this does too (`TRIM(LineText) <> ''`, the projector's own
 * blank rule). Source='ihymns'-keyed (never IsPrimary — #1235 R6/PF3).
 */
function lyricLinesFirstLine(\mysqli $db, string $songId): ?string
{
    $stmt = $db->prepare(
        "SELECT ll.LineText
           FROM tblLyricLines ll
           JOIN tblLyrics ly ON ly.Id = ll.LyricsId
          WHERE ly.SongId = ? AND ly.Source = 'ihymns' AND TRIM(ll.LineText) <> ''
          ORDER BY ll.SortOrder, ll.Id
          LIMIT 1"
    );
    $stmt->bind_param('s', $songId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    $stmt->close();
    return $row !== null ? (string)$row[0] : null;
}

/**
 * Bulk variant of lyricLinesFirstLine() — SongId → first non-empty preview line, for the
 * candidate-set / whole-corpus bypass readers (duplicate-songs, build-song-link-suggestions)
 * that must not N+1 a query per song. Chunked IN() (never whole-corpus in one query, #929);
 * the result rows arrive grouped + ordered, so the first non-empty line seen per song is its
 * answer. Songs with no non-empty primary line are simply absent from the map (caller
 * defaults to '').
 *
 * @param string[] $songIds
 * @return array<string,string>  SongId → first non-empty line
 */
function lyricLinesFirstLineMap(\mysqli $db, array $songIds): array
{
    $songIds = array_values(array_unique(array_filter($songIds, static fn($s) => $s !== '' && $s !== null)));
    if (empty($songIds)) { return []; }

    $out = [];
    foreach (array_chunk($songIds, LYRIC_LINES_READ_CHUNK) as $chunk) {
        $place = implode(',', array_fill(0, count($chunk), '?'));
        $types = str_repeat('s', count($chunk));
        $stmt  = $db->prepare(
            "SELECT ly.SongId AS song_id, ll.LineText AS text
               FROM tblLyricLines ll
               JOIN tblLyrics ly ON ly.Id = ll.LyricsId
              WHERE ly.SongId IN ({$place}) AND ly.Source = 'ihymns' AND TRIM(ll.LineText) <> ''
              ORDER BY ly.SongId, ll.SortOrder, ll.Id"
        );
        $stmt->bind_param($types, ...$chunk);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $sid = (string)$row['song_id'];
            /* First row seen per song wins (rows are ordered by SortOrder within song). */
            if (!array_key_exists($sid, $out)) { $out[$sid] = (string)$row['text']; }
        }
        $stmt->close();
    }
    return $out;
}

/**
 * PURE — join preview lines into ONE single-line "complete thought" phrase for
 * a lyric snippet (#1841).
 *
 * WHY: a hymn's lyric lines break where the song is SUNG, so the first sung
 * line is very often identical to the title (e.g. "Restore, O Lord,"), which
 * made the Song-of-the-Day snippet read as just the title again. Concatenating
 * the first few lines into one flowing line gives a fuller, more inspiring
 * phrase; the caller displays it single-line and lets CSS truncate it to the
 * viewport with an ellipsis.
 *
 * Heuristic (kept pure + testable): append whole lines (whitespace-collapsed,
 * joined by a space) and STOP after the first line that ENDS a sentence
 * (`. ! ?`, optionally + a closing quote) once we are past `$minChars` — so the
 * result is a natural, complete thought rather than a mid-clause fragment. Also
 * stops when the next whole line would exceed `$maxChars` (never a mid-word cut
 * — the ellipsis is the client's job), or when lines run out. A single line
 * that alone exceeds `$maxChars` is still returned (a snippet is better than
 * none). Line-end punctuation is checked, never mid-line punctuation, so
 * "Amazing grace! how sweet the sound" is treated as continuing, not complete.
 *
 * @param  list<string> $lines     Preview lines in sung order (blanks tolerated).
 * @param  int          $maxChars  Soft character budget for the joined phrase.
 * @param  int          $minChars  Floor before a sentence-end may stop the join.
 * @return string|null  The joined phrase, or null when there is no usable line.
 */
function lyricLinesJoinPreview(array $lines, int $maxChars = 140, int $minChars = 20): ?string
{
    $phrase = '';
    foreach ($lines as $raw) {
        $line = trim((string)preg_replace('/\s+/u', ' ', (string)$raw));
        if ($line === '') {
            continue;
        }
        $candidate = $phrase === '' ? $line : $phrase . ' ' . $line;
        /* Adding this whole line would blow the budget and we already have
           something usable — stop at the previous whole-line boundary. */
        if ($phrase !== '' && mb_strlen($candidate) > $maxChars) {
            break;
        }
        $phrase = $candidate;
        /* Natural stop: a line that ends a sentence, once we have enough to be
           a meaningful thought (not a one-word "Rejoice!"). */
        if (mb_strlen($phrase) >= $minChars
            && preg_match('/[.!?]["\'\x{2019}\x{201D}]?$/u', $line) === 1) {
            break;
        }
        if (mb_strlen($phrase) >= $maxChars) {
            break;
        }
    }
    return $phrase !== '' ? $phrase : null;
}

/**
 * The single-line "complete thought" lyric preview for a song (#1841) — the
 * DB-reading sibling of the pure `lyricLinesJoinPreview()` above. Reads the
 * first `$maxLines` non-empty lines from the authoritative `tblLyricLines`
 * mirror (same Source='ihymns' + blank-line rule + SortOrder/Id order as
 * `lyricLinesFirstLine()`, the ONE read path — rule #25) and joins them into
 * one phrase. Returns null when the song has no usable line, so a caller can
 * fall back (e.g. to a pre-mirror LinesJson read on an un-migrated install).
 *
 * @return string|null
 */
function lyricLinesPreviewPhrase(\mysqli $db, string $songId, int $maxChars = 140, int $maxLines = 8): ?string
{
    $stmt = $db->prepare(
        "SELECT ll.LineText
           FROM tblLyricLines ll
           JOIN tblLyrics ly ON ly.Id = ll.LyricsId
          WHERE ly.SongId = ? AND ly.Source = 'ihymns' AND TRIM(ll.LineText) <> ''
          ORDER BY ll.SortOrder, ll.Id
          LIMIT ?"
    );
    $stmt->bind_param('si', $songId, $maxLines);
    $stmt->execute();
    $res = $stmt->get_result();
    $lines = [];
    while ($row = $res->fetch_row()) {
        $lines[] = (string)$row[0];
    }
    $stmt->close();
    return lyricLinesJoinPreview($lines, $maxChars);
}

/**
 * Assemble one song's components in the EDITOR/SNAPSHOT shape
 * `[{id,type,number,sortOrder,lines,chords,language,languages}]` — the shape the v2
 * editor's load + revision snapshot (`ed2_buildSongSnapshot`) speak. Sourced from the
 * authoritative `tblLyricLines` (lines/chords/per-line language) JOINed to the THIN
 * `tblSongComponents` metadata (Id/Type/Number/SortOrder/Language) — reads NO doomed
 * JSON payload column, so it survives the #1235 P4/C6 drop (R2).
 *
 * Differs from lyricLinesAssembleComponents (the public read/export shape) in that it
 * (a) carries the component `id` + `sortOrder` the editor edits, (b) includes EMPTY
 * components (a thin row with no mirrored lines — `lines: []`), and (c) emits a
 * null-padded per-line `languages` OVERRIDE array (effective ≠ component default →
 * the override; else null) byte-equal to the retired `LanguagesJson`. `chords` is the
 * null-padded parallel array (null when no line carries one), as `ChordsJson` held it.
 *
 * @return list<array{id:int,type:string,number:int,sortOrder:int,lines:list<string>,chords:?array,language:?string,languages:?array}>
 */
function lyricLinesEditableComponents(\mysqli $db, string $songId): array
{
    /* Thin component metadata, in display order (NOT a doomed column among these). */
    $cs = $db->prepare(
        "SELECT Id, Type, Number, SortOrder, Language
           FROM tblSongComponents WHERE SongId = ? ORDER BY SortOrder, Id"
    );
    $cs->bind_param('s', $songId);
    $cs->execute();
    $comps = $cs->get_result()->fetch_all(MYSQLI_ASSOC);
    $cs->close();

    /* Authoritative lines grouped by ComponentId. */
    $byCid = [];
    foreach (lyricLinesFetchPrimary($db, $songId) as $r) {
        $cid = ($r['cid'] !== null && $r['cid'] !== '') ? (int)$r['cid'] : 0;
        $byCid[$cid]['lines'][]  = (string)$r['text'];
        $byCid[$cid]['chords'][] = ($r['line_chords'] !== null && $r['line_chords'] !== '')
            ? (json_decode((string)$r['line_chords'], true) ?? null) : null;
        $byCid[$cid]['langs'][]  = ($r['line_lang'] !== null && $r['line_lang'] !== '')
            ? (string)$r['line_lang'] : null;
        /* #1627 — carry the tblLyricLines PK through to the editor shape.
           ELI5: each line's database id, so the editor can attach a translation
           or an annotation to that exact line.
           Detail: this is the ONE thing that was blocking the whole of #1088's
           per-line enrichment in the v2 editor. The row already carries
           `line_id` (lyricLinesFetchPrimary, line ~200) and the public/assembler
           shape already emits `lineIds` (line ~132); only this editor shape
           dropped it — so v2 had no anchor to hang a translation on, while v1
           (which reads the assembler shape) did. Rule #21: enrichment anchors on
           tblLyricLines.Id, never on a LinesJson array index, because indices
           shift the moment a line is inserted and the annotation would silently
           drift onto a different line. */
        $byCid[$cid]['ids'][]    = (int)$r['line_id'];
    }

    $out = [];
    foreach ($comps as $c) {
        $cid      = (int)$c['Id'];
        $compLang = ($c['Language'] !== null && $c['Language'] !== '') ? (string)$c['Language'] : null;
        $lines    = $byCid[$cid]['lines']  ?? [];
        $chords   = $byCid[$cid]['chords'] ?? [];
        $langs    = $byCid[$cid]['langs']  ?? [];

        $anyChord = false;
        foreach ($chords as $cv) { if ($cv !== null) { $anyChord = true; break; } }

        /* Per-line override = effective language when it differs from the component
           default (mirrors the retired LanguagesJson; effective ≡ override under the
           inherit rule). */
        $langOut = [];
        $anyLang = false;
        foreach ($langs as $lv) {
            $ov = ($lv !== null && $lv !== $compLang) ? $lv : null;
            $langOut[] = $ov;
            if ($ov !== null) { $anyLang = true; }
        }

        $out[] = [
            'id'        => $cid,
            'type'      => (string)$c['Type'],
            'number'    => (int)$c['Number'],
            'sortOrder' => (int)$c['SortOrder'],
            'lines'     => $lines,
            'chords'    => $anyChord ? $chords : null,
            'language'  => $compLang,
            'languages' => $anyLang ? $langOut : null,
            /* Parallel to `lines`, same order (#1627). Empty on a pre-mirror
               install, where lyricLinesFetchPrimary has no rows to report — the
               enrichment endpoints 409 on those installs anyway, so the editor
               degrades to "no add controls" rather than offering a button that
               cannot work. */
            'lineIds'   => $byCid[$cid]['ids'] ?? [],
        ];
    }
    return $out;
}
