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
 * Since #1860 Phase 5 the shape additionally carries an OPTIONAL sparse `label` key
 * (present only when `tblSongComponents.Label` is set) — absent on every un-labelled
 * component, so the pre-Phase-5 byte parity holds verbatim for the whole un-labelled
 * corpus.
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
 * #1860 Phase 5 §2.1 — is either component-metadata EXTRA column present on
 * `tblSongComponents`? `Label` (REQ 3b, a curator-set display override for a
 * section — "Kyrie", "isiZulu") and `SourceWorkId` (REQ 2, the medley/
 * work-identity link) each ship in their OWN additive migration and can land
 * independently of one another and of the `tblLyricLines` mirror itself — so an
 * install may have neither, either, or both. Every reader below therefore gates
 * PER COLUMN, never behind a single combined flag: a bare `sc.Label` in a SELECT
 * on an un-migrated install would throw under MYSQLI_REPORT_STRICT (rule #19).
 *
 * THE ONE PROBE (rule #35 — no second INFORMATION_SCHEMA copy): both this read
 * seam AND the write seam (`lyric_lines_sync.php`'s `lyricLinesUpsertComponents()`,
 * Commit 3) call this exact function — the write side reaches it via a
 * same-directory `require_once __DIR__ . DIRECTORY_SEPARATOR . 'lyric_lines_read.php';`
 * inside that function, mirroring the existing in-function `line_enrichment.php`
 * require pattern already used elsewhere in this codebase.
 *
 * Catch posture mirrors `lyricLinesMirrorPresent()` / `lyricLinesSyncReady()` with
 * one addition: because the write seam calls this INSIDE its own transaction
 * (between `begin_transaction()` and `commit()`), a deadlock / lock-wait-timeout
 * (#1688 A1 — `songRelocateIsTransactionFatal()` in `song_relocate.php`) means the
 * transaction is already dead, not merely "extras absent" — swallowing it would
 * let the caller commit nothing and still report success. `song_relocate.php` is
 * NOT loaded on every path that reaches this function (the public read path in
 * particular has no reason to pull it in), so the check is
 * `function_exists()`-guarded: when the function isn't loaded there is no live
 * write transaction depending on this call's answer, so any other throw
 * (missing table, transient connection error, …) safely degrades to "both
 * absent" — the same fail-safe posture as every other schema probe in this file.
 *
 * Memoised per request (rule #19 migrations are not auto-applied, so a fresh
 * request re-probes after a migration is applied mid-deploy).
 *
 * @return array{Label:bool,SourceWorkId:bool}
 */
function lyricLinesComponentExtrasPresent(\mysqli $db): array
{
    static $present = null;
    if ($present !== null) {
        return $present;
    }
    try {
        $r = $db->query(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = 'tblSongComponents'
                AND COLUMN_NAME  IN ('Label', 'SourceWorkId')"
        );
        $found = [];
        if ($r) {
            while ($row = $r->fetch_row()) {
                $found[(string)$row[0]] = true;
            }
            $r->close();
        }
        $present = [
            'Label'        => isset($found['Label']),
            'SourceWorkId' => isset($found['SourceWorkId']),
        ];
    } catch (\Throwable $e) {
        /* #1688 A1 — a deadlock/lock-timeout mid-transaction must re-throw, not
           be swallowed as "extras absent" (see doc-block above). The
           function_exists guard covers callers (the public read path) where
           song_relocate.php — and therefore this predicate — was never loaded. */
        if (function_exists('songRelocateIsTransactionFatal') && songRelocateIsTransactionFatal($e)) {
            throw $e;
        }
        $present = ['Label' => false, 'SourceWorkId' => false];
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
 * #1860 Phase 5 §2.2 (SD3): each row MAY carry `comp_label` (the thin
 * component's `Label` column, joined upstream exactly like `comp_type` /
 * `comp_lang` — absent entirely on an un-migrated install, gated by the caller
 * via `lyricLinesComponentExtrasPresent()`). It is folded into the assembled
 * component as a SPARSE `label` key — emitted only when non-null/non-empty —
 * mirroring the sparse `lineLanguages` rule above so every pre-Phase-5 fixture
 * and the corpus-wide byte-parity claim stay literally true for the whole
 * un-labelled corpus.
 *
 * @param list<array{
 *   line_id:int|string, cid:int|string|null, text:string, line_lang:?string,
 *   line_chords:?string, comp_type:?string, comp_number:int|string|null,
 *   comp_lang:?string, line_parttype:?string, line_partnum:int|string|null,
 *   comp_label?:?string
 * }> $rows  one song's lines, already ordered by global SortOrder
 * @return list<array{type:string,number:int,lines:list<string>,chords:?array,language:?string,label?:string,lineIds?:list<int>,lineLanguages?:list<?string>}>
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
        /* #1860 Phase 5 §2.2 (SD3) — sparse label emit: the key is present ONLY
           when a curator set one, so every un-labelled component (the entire
           corpus pre-Phase-5) keeps the exact pre-existing shape and the
           strict-=== golden fixtures need no rewrite. */
        if ($c['label'] !== null) {
            $out['label'] = $c['label'];
        }
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
            /* #1860 Phase 5 §2.2 — 'comp_label' is present only when the caller's
               SELECT included it (gated on lyricLinesComponentExtrasPresent()'s
               'Label' flag); isset() tolerates it being entirely absent from the
               row on an un-migrated install, matching every other optional field
               here. */
            $label  = (isset($row['comp_label']) && $row['comp_label'] !== null && $row['comp_label'] !== '')
                ? (string)$row['comp_label']
                : null;
            $cur = [
                'type'      => $type !== '' ? $type : 'verse',
                'number'    => $number,
                'language'  => $lang,
                'label'     => $label,
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
 * ELI5: work out which "copy of the words" is THE copy for a song — the one
 * everyone reads — and hand back its row number in `tblLyrics`, or 0 if the
 * song does not have one of that kind yet.
 *
 * DETAILED (#2076 — the ONE shared lyrics-version resolver): every line
 * reader in this file (`lyricLinesFetchPrimary()` right below, and its
 * bulk/first-line/preview siblings further down) keys its JOIN on
 * `Source = 'ihymns'` — never `IsPrimary` (#1235 R6/PF3, restated on
 * `lyricLinesFetchPrimary()`'s own doc-block). Before #2076,
 * `SongData::_primaryLyricsId()` used a DIFFERENT rule
 * (`Status = 'approved' ORDER BY IsPrimary DESC`) to decide which version's
 * enrichment to hand back for `?include=vocalParts|translations|annotations`.
 * On a song that had BOTH an `ihymns` row and an approved TTML import with
 * `IsPrimary = 1`, that meant the enrichment blocks were read against the
 * TTML version while every `lineId` in the song's component payload came
 * from the `ihymns` version — two arrays that never shared an id, so a
 * client could never anchor a translation or annotation to a line. Nothing
 * errored anywhere; the lists were just quietly unrelated. This function is
 * now the ONE place that decision gets made, so a line reader and an
 * enrichment reader can never disagree about which version they mean again.
 *
 * Returns 0 — never throws FOR THE NOT-FOUND CASE — when the song has no
 * `ihymns` row yet (a TTML-only import nobody has opened in the editor), so
 * a caller can fall back to its own rule for that one case
 * (`SongData::_primaryLyricsId()` does exactly this).
 *
 * ERROR POLICY — ANSWER OR FAIL, NEVER GUESS (an independent review caught
 * a regression here before this function's #2076 write-side use ever
 * shipped): a genuine DB FAILURE (a deadlock, a lock-wait timeout, a lost
 * connection) PROPAGATES out of this function — it does NOT degrade to 0.
 * An earlier revision caught `\Throwable` here and returned 0, which reads
 * to every caller EXACTLY like "no ihymns row yet" — indistinguishable
 * from the real not-found case. That degrade is fine, even desirable, for
 * a pure READ (a missing enrichment block is a harmless omission) — but
 * `lyricLinesEnsurePrimaryVersion()` (`lyric_lines_sync.php`) uses this
 * function's answer to decide whether to INSERT a brand-new `tblLyrics`
 * row: a swallowed deadlock there turned into "no row found, create one"
 * instead of "the transaction is already dead, stop" — risking a duplicate
 * version row and a partial save that still reports success. This
 * resolver cannot know which kind of caller is asking, so it does
 * neither: it answers, or it lets the failure through, and each CALL SITE
 * decides what a failure means for it — a read (`SongData::_primaryLyricsId()`)
 * wraps this call in its own try/catch and degrades to its legacy fallback
 * query; a write (`lyricLinesEnsurePrimaryVersion()`, the `duplicate_song`
 * case in `manage/editor/api2.php`) lets it propagate so the surrounding
 * transaction handling (`$db->rollback(); throw $e;`) runs — the same
 * "a failing statement throws" contract every other write in this codebase
 * already lives under (MYSQLI_REPORT_STRICT, see this file's header
 * comment).
 *
 * CACHING — per CONNECTION and per SONG, read-safe, write-unsafe. Only a
 * FOUND row is ever memoised: `lyricLinesEnsurePrimaryVersion()` calls this
 * function to do its "find" half and, on a miss, immediately INSERTs the
 * very row being looked for — caching a MISS would hide that brand-new row
 * from this function for the rest of the request. The memo is keyed on
 * `spl_object_id($db)` as well as `$songId` (mirrors `searchFoldReady()`'s
 * per-connection memo in `search_fold.php`), so an answer cached for one
 * `\mysqli` handle can never be handed back to a different one — concretely,
 * `manage/setup-database.php`'s migration runner can close and reopen
 * `getDbMysqli()`'s singleton mid-request, and a reconnect there mints a
 * brand-new object id, so the old memo simply misses rather than silently
 * answering for a connection it never ran on.
 *
 * WHY A FOUND ROW IS SAFE TO MEMOISE FOR A READ BUT NOT FOR A WRITE — the
 * corrected version of a claim this doc-block used to make ("a found row
 * cannot go stale within one request"). That is true for a plain READ: a
 * request that only ever reads never deletes a song's 'ihymns' row, so a
 * found answer stays true for the rest of it. It is NOT true in general —
 * `lyricLinesEnsurePrimaryVersion()` runs INSIDE a transaction that can
 * still ROLL BACK after this call returns. A row this call found (possibly
 * one INSERTed moments earlier in that SAME transaction — InnoDB shows a
 * connection its own uncommitted writes) can vanish the instant that
 * transaction rolls back, while a memo entry would keep insisting it is
 * still there for anything else in the same request that asks. So a
 * find-or-create passes `$useCache = false`: it neither reads nor writes
 * the memo, and always sees live database state — the one guarantee a
 * find-or-create cannot do without.
 *
 * @param bool $useCache  Read AND write the per-connection/per-song memo
 *      (default true, safe for a plain read). Pass `false` from inside a
 *      transaction that could still roll back — see "WHY A FOUND ROW..."
 *      above; `lyricLinesEnsurePrimaryVersion()` is the worked example.
 *
 * @see lyricLinesFetchPrimary()  the identical `Source = 'ihymns'` filter,
 *      joined straight to line data instead of standing alone. Kept in
 *      lockstep by making every OTHER version-resolving site in the
 *      codebase call this function (or record why it can't) — see
 *      tests/php/test-lyrics-version-resolver.php.
 */
function lyricLinesPrimaryLyricsId(\mysqli $db, string $songId, bool $useCache = true): int
{
    /* @lyrics-version-exempt: this function IS the shared resolver (#2076) —
       there is nothing else for it to delegate to. */
    static $cache = [];
    /* Two dimensions on purpose (see the CACHING doc-block above): WHICH
       connection asked, then WHICH song — never just the song, so an answer
       cached for one \mysqli handle can never be handed back to another. */
    $connId = spl_object_id($db);
    if ($useCache && isset($cache[$connId][$songId])) {
        return $cache[$connId][$songId];
    }

    /* Deliberately NO try/catch here — see the ERROR POLICY doc-block above.
       A failing statement throws (MYSQLI_REPORT_STRICT); that throw is left
       to propagate. Degrading it to 0 is each CALLER's decision, never this
       resolver's — a read wraps this call in its own try/catch, a write
       lets it reach its transaction's rollback handling. */
    $stmt = $db->prepare(
        "SELECT Id FROM tblLyrics WHERE SongId = ? AND Source = 'ihymns' LIMIT 1"
    );
    $stmt->bind_param('s', $songId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    $stmt->close();
    $id = $row !== null ? (int)$row[0] : 0;

    /* Only a FOUND row is remembered, and only when the caller wants the
       memo at all (see the CACHING / WHY A FOUND ROW... doc-blocks above).
       lyricLinesEnsurePrimaryVersion() (lyric_lines_sync.php) calls this
       function to do its "find" half and, on a miss, immediately INSERTs
       the very row being looked for — caching a MISS would hide that
       brand-new row from this function for the rest of the request. */
    if ($useCache && $id > 0) {
        $cache[$connId][$songId] = $id;
    }
    return $id;
}

/**
 * Fetch one song's primary ('ihymns') lyric lines in global SortOrder, each joined to
 * its thin component metadata. Keyed on `Source='ihymns'` only (never IsPrimary —
 * #1235 R6/PF3). Returns [] for a song with no mirrored lines (the LEFT-JOIN-shaped
 * "0-line lyrics" case). One query.
 *
 * #2076: deliberately does NOT call lyricLinesPrimaryLyricsId() — see the
 * `@lyrics-version-exempt` note inside the function body for why.
 *
 * @return list<array<string,mixed>>  rows shaped for lyricLinesAssembleFromRows()
 */
function lyricLinesFetchPrimary(\mysqli $db, string $songId): array
{
    /* @lyrics-version-exempt: this reads the LINES themselves, not just the
       version Id, so it needs its own JOIN rather than a call to
       lyricLinesPrimaryLyricsId() first (that would be a second round trip
       for no benefit). See the doc-block above for why the WHERE clause
       below must stay byte-identical to that function's own filter (#2076). */
    /* #1860 Phase 5 §2.2 — sc.Label is added to the SELECT ONLY when the column
       exists (gated column list, never a bare reference — rule #19: it would
       throw under MYSQLI_REPORT_STRICT on an un-migrated install). SourceWorkId
       is deliberately NOT added here: it is editor/rights provenance metadata,
       not a public render field, so it stays out of this (public/export) shape
       — see lyricLinesEditableComponents() for the editor shape that does carry
       it. */
    $extras   = lyricLinesComponentExtrasPresent($db);
    $labelCol = $extras['Label'] ? ",\n                sc.Label       AS comp_label" : '';
    /* #2072 — ll.Note is selected UNCONDITIONALLY, mirroring ll.ChordsJson right
       above it: lyricLinesMirrorPresent() (the ONE gate every caller of this
       function checks before calling it) already requires BOTH ChordsJson AND
       Note to exist (rule #25's aligned read/write gate), so there is no
       un-migrated-install case where ChordsJson is safe to name here but Note
       is not. lyricLinesAssembleFromRows() (the PUBLIC/export shape) does NOT
       read this new `line_note` key — only lyricLinesEditableComponents() (the
       EDITOR shape, below) does — so adding the column here changes NOTHING
       about the public shape a caller like SongData::getSongById() sees; it
       only makes the value available to the ONE reader that is being taught to
       use it. See lyricLinesEditableComponents() for where it is spent. */
    $stmt = $db->prepare(
        "SELECT ll.Id        AS line_id,
                ll.ComponentId AS cid,
                ll.LineText    AS text,
                ll.LanguageCode AS line_lang,
                ll.ChordsJson  AS line_chords,
                ll.Note        AS line_note,
                ll.PartType    AS line_parttype,
                ll.PartNumber  AS line_partnum,
                sc.Type        AS comp_type,
                sc.Number      AS comp_number,
                sc.Language    AS comp_lang{$labelCol}
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
    /* @lyrics-version-exempt: the bulk sibling of lyricLinesFetchPrimary() —
       same reasoning (#2076): it reads lines for MANY songs in one chunked
       query, so there is no single Id to hand back from a helper call. */
    $songIds = array_values(array_unique(array_filter($songIds, static fn($s) => $s !== '' && $s !== null)));
    if (empty($songIds)) { return []; }

    /* #1860 Phase 5 §2.2 — same gated column as lyricLinesFetchPrimary(); see its
       doc-block for why SourceWorkId stays out of this (public/export) shape. */
    $extras   = lyricLinesComponentExtrasPresent($db);
    $labelCol = $extras['Label'] ? ",\n                    sc.Label       AS comp_label" : '';

    $out = [];
    foreach (array_chunk($songIds, LYRIC_LINES_READ_CHUNK) as $chunk) {
        $place = implode(',', array_fill(0, count($chunk), '?'));
        $types = str_repeat('s', count($chunk));
        /* #2072 — ll.Note, same unconditional add as the single-song sibling
           lyricLinesFetchPrimary() above (see its doc-comment for why: the ONE
           mirror-present gate already requires it alongside ChordsJson). */
        $stmt  = $db->prepare(
            "SELECT ly.SongId   AS song_id,
                    ll.Id        AS line_id,
                    ll.ComponentId AS cid,
                    ll.LineText    AS text,
                    ll.LanguageCode AS line_lang,
                    ll.ChordsJson  AS line_chords,
                    ll.Note        AS line_note,
                    ll.PartType    AS line_parttype,
                    ll.PartNumber  AS line_partnum,
                    sc.Type        AS comp_type,
                    sc.Number      AS comp_number,
                    sc.Language    AS comp_lang{$labelCol}
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
    /* @lyrics-version-exempt: reads one line's TEXT straight off the correct
       version in a single query (#2076) — resolving the Id first via
       lyricLinesPrimaryLyricsId() and then querying again by that Id would
       be two round trips to say the same thing this one WHERE clause says. */
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
    /* @lyrics-version-exempt: the bulk sibling of lyricLinesFirstLine() —
       same reasoning (#2076), many songs at once via a chunked IN(). */
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
    /* @lyrics-version-exempt: same reasoning as lyricLinesFirstLine() (#2076)
       — reads several lines' TEXT directly, so its own WHERE clause (kept
       identical to lyricLinesPrimaryLyricsId()'s filter) does the same job a
       resolve-then-requery pair would, in one query instead of two. */
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
 * `[{id,type,number,sortOrder,lines,chords,notes,language,languages,label,sourceWorkId}]` —
 * the shape the v2 editor's load + revision snapshot (`ed2_buildSongSnapshot`) speak.
 * Sourced from the authoritative `tblLyricLines` (lines/chords/notes/per-line language)
 * JOINed to the THIN `tblSongComponents` metadata
 * (Id/Type/Number/SortOrder/Language/Label/SourceWorkId) — reads NO doomed JSON
 * payload column, so it survives the #1235 P4/C6 drop (R2).
 *
 * Differs from lyricLinesAssembleComponents (the public read/export shape) in that it
 * (a) carries the component `id` + `sortOrder` the editor edits, (b) includes EMPTY
 * components (a thin row with no mirrored lines — `lines: []`), (c) emits a
 * null-padded per-line `languages` OVERRIDE array (effective ≠ component default →
 * the override; else null) byte-equal to the retired `LanguagesJson` (`chords` is the
 * null-padded parallel array, null when no line carries one, as `ChordsJson` held it),
 * (d), since #1860 Phase 5, ALWAYS emits `label` (the curator display override,
 * REQ 3b) and `sourceWorkId` (the medley/work-identity link, REQ 2) — never sparse
 * here, unlike the public shape's SD3 sparse `label`: the editor is the one place
 * that must be able to distinguish "unset" from "not yet loaded", so both keys are
 * present with a `null` default on every install, migrated or not (gated per-column
 * via `lyricLinesComponentExtrasPresent()` exactly like the SELECT below), and (e),
 * since #2072, ALSO always emits `notes` — the SAME always-present-key treatment as
 * `chords` (a key is always there; its VALUE is the null-padded parallel array, or
 * null when no line in the component has one). `notes` is added HERE ONLY — the
 * public/export shape (lyricLinesAssembleFromRows(), above) is DELIBERATELY left
 * untouched (it is hashed per-song by tools/export-fidelity-snapshot.php and
 * compared with strict `===` by tests/php/test-lyric-lines-read.php, so a new
 * always-present key there would change ~16k stored hashes for no user-visible
 * gain right now); a value that a curator or an importer WRITES via this editor
 * shape can now also be READ BACK through it, which is the entire #2072 fix —
 * before this, `tblLyricLines.Note` was written by the OpenLyrics importer and
 * read by NOTHING, so the next whole-song save silently wiped it (see
 * lyricLinesMergePreserved() in lyric_lines_sync.php for the writer-level half
 * of this fix, which protects a save that never learned about `notes` at all).
 *
 * @return list<array{id:int,type:string,number:int,sortOrder:int,lines:list<string>,chords:?array,notes:?array,language:?string,languages:?array,label:?string,sourceWorkId:?int}>
 */
function lyricLinesEditableComponents(\mysqli $db, string $songId): array
{
    /* #1860 Phase 5 §2.3 — Label/SourceWorkId are appended to the SELECT ONLY
       when each column exists (independent per-column gate — rule #19: a bare
       reference to either would throw under MYSQLI_REPORT_STRICT on an install
       that hasn't run one or both of their migrations yet). */
    $extras    = lyricLinesComponentExtrasPresent($db);
    $extraCols = ($extras['Label'] ? ', Label' : '') . ($extras['SourceWorkId'] ? ', SourceWorkId' : '');

    /* Thin component metadata, in display order (NOT a doomed column among these). */
    $cs = $db->prepare(
        "SELECT Id, Type, Number, SortOrder, Language{$extraCols}
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
        /* #2072 — the per-line presenter/slide note, straight off tblLyricLines.Note
           (line_note, from the SELECT lyricLinesFetchPrimary() now carries). This is
           the READ half of the fix: the note was already being WRITTEN by the
           OpenLyrics importer, but nothing anywhere read it back, so it was
           invisible in the editor and the next whole-song save destroyed it. */
        $byCid[$cid]['notes'][]  = ($r['line_note'] !== null && $r['line_note'] !== '')
            ? (string)$r['line_note'] : null;
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
        $notes    = $byCid[$cid]['notes']  ?? [];
        $langs    = $byCid[$cid]['langs']  ?? [];

        $anyChord = false;
        foreach ($chords as $cv) { if ($cv !== null) { $anyChord = true; break; } }
        /* #2072 — same always-present-key / sparse-value rule as chords above. */
        $anyNote = false;
        foreach ($notes as $nv) { if ($nv !== null) { $anyNote = true; break; } }

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
            /* #2072 — ALWAYS-present key (mirrors 'chords' immediately above,
               not the sparse-KEY rule the public shape uses for 'label'): the
               editor needs a stable place to read a per-line note back from,
               even on a component where nobody has set one yet. Value is the
               null-padded parallel array, or null when no line in this
               component carries a note. */
            'notes'     => $anyNote ? $notes : null,
            'language'  => $compLang,
            'languages' => $anyLang ? $langOut : null,
            /* #1860 Phase 5 §2.3 — ALWAYS-present (null default), never sparse
               (contrast the public shape's SD3 sparse 'label'): the editor needs
               to see "this component has no label" as distinctly as "this
               component has one", and the SELECT only carried the column when
               $extras says it exists, so $c['Label']/$c['SourceWorkId'] are
               simply absent (not null) on an un-migrated install — the
               ($extras[...] && ...) guard covers both cases identically. */
            'label'        => ($extras['Label'] && ($c['Label'] ?? null) !== null && $c['Label'] !== '')
                ? (string)$c['Label']
                : null,
            'sourceWorkId' => ($extras['SourceWorkId'] && ($c['SourceWorkId'] ?? null) !== null)
                ? (int)$c['SourceWorkId']
                : null,
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
