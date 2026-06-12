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
 * The first lyric line of a song's primary version (its lowest SortOrder), or null when
 * it has none. For the lightweight "preview line" bypass readers (SongOfTheDay,
 * duplicate-songs, the link-suggestion builder) that used to crack open LinesJson[0].
 */
function lyricLinesFirstLine(\mysqli $db, string $songId): ?string
{
    $stmt = $db->prepare(
        "SELECT ll.LineText
           FROM tblLyricLines ll
           JOIN tblLyrics ly ON ly.Id = ll.LyricsId
          WHERE ly.SongId = ? AND ly.Source = 'ihymns'
          ORDER BY ll.SortOrder, ll.Id
          LIMIT 1"
    );
    $stmt->bind_param('s', $songId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    $stmt->close();
    return $row !== null ? (string)$row[0] : null;
}
