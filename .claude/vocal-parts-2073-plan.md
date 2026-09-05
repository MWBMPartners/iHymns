# Voice parts, echo and rounds — implementation plan (#2073)

Produced 2026-09-05 by seven SEQUENTIAL Fable deep-design passes, owner-mandated model routing.
Owner decisions D1-D4 are recorded in `.claude/sessions/2026-09-04-HANDOFF.md`.

**Read pass 7 first** — it is the synthesis and it CORRECTS several things the earlier passes
and the preceding research got wrong. Where passes disagree, pass 7 wins.


---

# Design pass 7

## Summary
Synthesis of the six design passes for #2073 (voice parts + echo + rounds + word grain + backfill review queue) into ONE ordered commit plan on branch feat/vocal-parts-echo-rounds-2073. The #1137 trio stays untouched; ONE new migration adds four dormant tables (tblLyricLineVocalSpans, tblLyricRounds, tblLyricRoundVoices, tblVocalPartSuggestions). Three cross-pass contradictions are resolved (chip is a sibling inside a run wrapper, never a child of .lyric-line; rounds carry lines+beats+ms entry offsets with a nullable part per voice; review-queue undo is queue-native, not a Revisions-tab restore). The three open bugs (#2071/#2072/#2075) are fixed inside the same program because each is a precondition. 17 commits, every one revertable, tree never broken, and the whole thing is a verified byte-identical no-op on an un-migrated install (memoised INFORMATION_SCHEMA gates on both read and write; empty tables fold to the exact pre-existing shape). Nine new mutation-proven guards. Six corrections to the established research, the loudest being that api2.php has its own X-Requested-With gate (not validateCsrfRequest) and that a chip as a CHILD of .lyric-line would corrupt every Present slide and share snippet rather than "come free".

## Spec
All paths are under `appWeb/public_html/` unless prefixed `appWeb/.sql/`, `tests/`, `.claude/` or `wiki/`.

# 1. Contradictions between passes — resolved

| # | Passes | Contradiction | Resolution |
|---|---|---|---|
| C1 | research vs P5 | chip as CHILD of `.lyric-line` vs SIBLING in a run wrapper | **Sibling in wrapper** (see corrections). |
| C2 | P1 vs P4 | rounds DDL: per-voice lines+beats+ms vs per-round `EntryUnit` + unique `(RoundId, VocalPartId)` | **P1 shape** (§2.3): nullable `VocalPartId`, unique `(RoundId, VoiceNumber)`, three nullable entry columns, basis derived. |
| C3 | P3 vs P4 | `lyricLinesPrimaryLyricsId()` new vs existing | **Exists** — reuse. |
| C4 | P4 vs P6 | curator writes via API vs importer `voices` transport | **Both**, one core (§4). |
| C5 | P6 vs tree | revision-recorded accept undone by Revisions restore | **Queue-native undo** (§8). |
| C6 | P2 vs P6 | `IHYMNS_VOCAL_SOURCES_STRUCTURED` contents | P6's list (adds `'import-marker'`). |
| C7 | P6 | "Note read in both shapes" | **Editor shape only** now (always-present `notes`); public sparse `notes` deferred (§10). |
| C8 | P2 vs P3 | `kind` vs `partKind` naming | Run parts use `kind`; the legacy `?include=vocalParts` block keeps `partKind`. Pinned by guard G6. |

# 2. DDL — `appWeb/.sql/migrate-vocal-parts-rounds.php` (mirrored byte-identically in `schema.sql` after the `tblLyricWordVocalParts` block)

Include resolution per rule #41: `$_incDir = defined('IHYMNS_INCLUDES_DIR') ? IHYMNS_INCLUDES_DIR : dirname(__DIR__).'/public_html/includes';` — this migration needs NO includes beyond db; guard each CREATE with its own `_migVPR_tableExists()` probe (the migrate-vocal-parts.php pattern). Registry entry appended at the END of `$MIGRATIONS` (execution order = array order; it FK-references tblVocalParts/tblLyricLines/tblLyrics/tblSongs which all precede it):

```php
'vocal-parts-rounds' => [
  'script' => 'migrate-vocal-parts-rounds.php',
  'card' => ['title' => 'Vocal parts: echo spans, rounds/canon, review queue (#2073)', 'body' => '…', 'button' => 'Run Vocal Parts (Rounds) Migration'],
  'probe' => static fn(\mysqli $db) =>
      !_migProbe_tableExists($db, 'tblLyricLineVocalSpans') || !_migProbe_tableExists($db, 'tblLyricRounds')
   || !_migProbe_tableExists($db, 'tblLyricRoundVoices')   || !_migProbe_tableExists($db, 'tblVocalPartSuggestions'),
],
```
Also fix the stale `'vocal-parts'` card body: replace "reusing <code>tblCreditPeople</code>" with "reusing <code>tblMusicians</code>".

## 2.1 tblLyricLineVocalSpans (sub-line echo / part span; code points, rule #21)
```sql
CREATE TABLE IF NOT EXISTS tblLyricLineVocalSpans (
    Id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    LineId       BIGINT UNSIGNED NOT NULL,
    VocalPartId  INT UNSIGNED    NOT NULL,
    LyricsId     INT UNSIGNED    NOT NULL COMMENT 'Denorm of tblLyricLines.LyricsId; app derives from the line, never the caller',
    StartOffset  INT UNSIGNED    NOT NULL COMMENT '0-based UTF-8 code-point index, inclusive (rule #21: never byte/UTF-16)',
    EndOffset    INT UNSIGNED    NOT NULL COMMENT 'Code-point index, exclusive; > StartOffset; a full-width span is rejected by the app (use the line assignment)',
    IsBackground TINYINT(1)      NOT NULL DEFAULT 0 COMMENT 'Echo / background voice for this span',
    SortOrder    INT UNSIGNED    NOT NULL DEFAULT 0,
    Source       VARCHAR(100)    NOT NULL DEFAULT 'ihymns' COMMENT 'ihymns | applemusic-ttml | openlyrics | import-marker | …',
    MetaJson     JSON            NULL DEFAULT NULL,
    CreatedAt    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_Line (LineId, StartOffset), INDEX idx_Lyrics (LyricsId), INDEX idx_Part (VocalPartId),
    CONSTRAINT fk_LineVS_Line   FOREIGN KEY (LineId)      REFERENCES tblLyricLines(Id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_LineVS_Part   FOREIGN KEY (VocalPartId) REFERENCES tblVocalParts(Id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_LineVS_Lyrics FOREIGN KEY (LyricsId)    REFERENCES tblLyrics(Id)     ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Sub-line vocal-part / echo span, code-point anchored on tblLyricLines.Id (#2073).';
```
## 2.2 tblLyricRounds
```sql
CREATE TABLE IF NOT EXISTS tblLyricRounds (
    Id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    LyricsId        INT UNSIGNED    NOT NULL,
    Kind            VARCHAR(20)     NOT NULL DEFAULT 'round' COMMENT 'round | canon | partner-song (app-validated vs IHYMNS_ROUND_KINDS; VARCHAR not ENUM)',
    Label           VARCHAR(120)    NULL DEFAULT NULL,
    StartLineId     BIGINT UNSIGNED NOT NULL COMMENT 'First subject line (version order)',
    EndLineId       BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'Last subject line inclusive; NULL = the StartLineId line only',
    TimesThrough    TINYINT UNSIGNED NOT NULL DEFAULT 2 COMMENT '1..8 — how many times the subject is sung by each voice',
    EndingMode      VARCHAR(16)     NOT NULL DEFAULT 'complete' COMMENT 'complete | together | coda (app-validated vs IHYMNS_ROUND_ENDINGS)',
    CodaStartLineId BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'Coda span sung in unison after the round (EndingMode=coda)',
    CodaEndLineId   BIGINT UNSIGNED NULL DEFAULT NULL,
    Bpm             DECIMAL(6,2)    NULL DEFAULT NULL COMMENT 'Tempo for the beats basis',
    BeatsPerBar     TINYINT UNSIGNED NULL DEFAULT NULL,
    BeatsPerLine    DECIMAL(6,2)    NULL DEFAULT NULL COMMENT 'Beats one subject line occupies (beats basis)',
    SortOrder       INT UNSIGNED    NOT NULL DEFAULT 0,
    Source          VARCHAR(100)    NOT NULL DEFAULT 'ihymns',
    MetaJson        JSON            NULL DEFAULT NULL,
    CreatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_Lyrics (LyricsId, SortOrder), INDEX idx_Start (StartLineId),
    CONSTRAINT fk_Rounds_Lyrics    FOREIGN KEY (LyricsId)        REFERENCES tblLyrics(Id)     ON DELETE CASCADE  ON UPDATE CASCADE,
    CONSTRAINT fk_Rounds_Start     FOREIGN KEY (StartLineId)     REFERENCES tblLyricLines(Id) ON DELETE CASCADE  ON UPDATE CASCADE,
    CONSTRAINT fk_Rounds_End       FOREIGN KEY (EndLineId)       REFERENCES tblLyricLines(Id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_Rounds_CodaStart FOREIGN KEY (CodaStartLineId) REFERENCES tblLyricLines(Id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_Rounds_CodaEnd   FOREIGN KEY (CodaEndLineId)   REFERENCES tblLyricLines(Id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='A round / canon / partner-song over a run of lyric lines, per lyrics version (#2073 D1).';
```
## 2.3 tblLyricRoundVoices
```sql
CREATE TABLE IF NOT EXISTS tblLyricRoundVoices (
    Id                INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    RoundId           INT UNSIGNED    NOT NULL,
    VoiceNumber       TINYINT UNSIGNED NOT NULL COMMENT '1..N contiguous; voice 1 always enters at 0',
    VocalPartId       INT UNSIGNED    NULL DEFAULT NULL COMMENT 'Optional registry part singing this voice; NULL = an unnamed "Voice N"',
    Label             VARCHAR(120)    NULL DEFAULT NULL,
    EntryLines        SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Entry offset in subject LINES (always present — the lines basis)',
    EntryBeats        DECIMAL(8,2)    NULL DEFAULT NULL COMMENT 'Entry offset in beats (beats basis, needs round Bpm+BeatsPerLine)',
    EntryMs           INT UNSIGNED    NULL DEFAULT NULL COMMENT 'Entry offset in ms (ms basis, needs timed subject lines)',
    IntervalSemitones TINYINT         NULL DEFAULT NULL COMMENT 'Canon at an interval (e.g. 7 = at the fifth); NULL = unison',
    StartLineId       BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'Partner-song: this voice''s OWN subject span (both or neither)',
    EndLineId         BIGINT UNSIGNED NULL DEFAULT NULL,
    TimesThrough      TINYINT UNSIGNED NULL DEFAULT NULL COMMENT 'Per-voice override of the round''s TimesThrough',
    SortOrder         INT UNSIGNED    NOT NULL DEFAULT 0,
    UNIQUE KEY uq_Round_Voice (RoundId, VoiceNumber),
    INDEX idx_Part (VocalPartId),
    CONSTRAINT fk_RoundV_Round FOREIGN KEY (RoundId)     REFERENCES tblLyricRounds(Id) ON DELETE CASCADE  ON UPDATE CASCADE,
    CONSTRAINT fk_RoundV_Part  FOREIGN KEY (VocalPartId) REFERENCES tblVocalParts(Id)  ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_RoundV_Start FOREIGN KEY (StartLineId) REFERENCES tblLyricLines(Id)  ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_RoundV_End   FOREIGN KEY (EndLineId)   REFERENCES tblLyricLines(Id)  ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='One row per voice of a round: entry offset in lines / beats / ms (#2073 D1).';
```
## 2.4 tblVocalPartSuggestions (backfill review queue, D4)
```sql
CREATE TABLE IF NOT EXISTS tblVocalPartSuggestions (
    Id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    SongId          VARCHAR(20)     NOT NULL,
    LyricsId        INT UNSIGNED    NOT NULL,
    MarkerLineId    BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'The line carrying the marker text (deleted or rewritten on accept)',
    StartLineId     BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'First line the part applies to (standalone form: the run after the marker; prefix/paren: the marker line itself)',
    EndLineId       BIGINT UNSIGNED NULL DEFAULT NULL,
    Form            VARCHAR(20)     NOT NULL COMMENT 'standalone | prefix | paren | canon-note (IHYMNS_VOCAL_DETECT_FORMS)',
    MarkerText      VARCHAR(120)    NOT NULL,
    PartKind        VARCHAR(30)     NOT NULL COMMENT 'Proposed IHYMNS_VOCAL_PART_KINDS key; canon-note rows use ''all'' and propose a round',
    Label           VARCHAR(120)    NULL DEFAULT NULL,
    IsBackground    TINYINT(1)      NOT NULL DEFAULT 0,
    Confidence      VARCHAR(10)     NOT NULL DEFAULT 'medium' COMMENT 'high | medium | low (VARCHAR, never the grandfathered ENUM shape)',
    Status          VARCHAR(20)     NOT NULL DEFAULT 'pending' COMMENT 'pending | accepted | dismissed | undone | stale',
    DetectorVersion SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    AppliedJson     JSON            NULL DEFAULT NULL COMMENT 'What accept did (part id, line ids, removed/rewritten marker) so Undo can reverse it exactly',
    ReviewedBy      INT UNSIGNED    NULL DEFAULT NULL,
    ReviewedAt      DATETIME        NULL DEFAULT NULL,
    CreatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_Marker_Form (MarkerLineId, Form),
    INDEX idx_Song_Status (SongId, Status), INDEX idx_Status_Conf (Status, Confidence),
    CONSTRAINT fk_VPS_Song   FOREIGN KEY (SongId)       REFERENCES tblSongs(SongId)  ON DELETE CASCADE  ON UPDATE CASCADE,
    CONSTRAINT fk_VPS_Lyrics FOREIGN KEY (LyricsId)     REFERENCES tblLyrics(Id)     ON DELETE CASCADE  ON UPDATE CASCADE,
    CONSTRAINT fk_VPS_Marker FOREIGN KEY (MarkerLineId) REFERENCES tblLyricLines(Id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_VPS_Start  FOREIGN KEY (StartLineId)  REFERENCES tblLyricLines(Id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_VPS_End    FOREIGN KEY (EndLineId)    REFERENCES tblLyricLines(Id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Curator review queue for voice-part markers found in lyric text by the shared detector (#2073 D4 / #1260).';
```
Second-migration stress: new form/kind/status/ending/basis → one PHP map line; a new timing basis → nullable column already reserved (ms); rounds crossing components → line-anchored, unaffected; word-grain spans → tblLyricWordVocalParts already exists; a re-detection after the detector improves → `DetectorVersion` + `stale` status, no ALTER.

# 3. Vocabulary + ONE cores

## 3.1 `includes/vocal_parts.php` (NEW)
Constants: `IHYMNS_VOCAL_PART_KINDS` exactly as Pass 2 §1.2 (22 keys, each `label/description/gender/markers/openlyrics/ttmlAgent`); `IHYMNS_VOCAL_PART_KIND_ALIASES = ['main'=>'lead','bgv'=>'backing','solo'=>'soloist','men'=>'male','women'=>'female','kids'=>'children','everyone'=>'all','tutti'=>'all']`; `IHYMNS_VOCAL_GENDERS = ['male','female','neutral']`; `IHYMNS_VOCAL_SOURCES_STRUCTURED = ['applemusic-ttml','openlyrics','propresenter7','import-marker']`; `IHYMNS_VOCAL_GROUP_ORDINAL_RE = '/^(?:GROUP|VOICE|PART|SIDE)\s*([0-9]{1,2}|[IVX]{1,4}|ONE|TWO|THREE|FOUR|FIRST|SECOND|THIRD|FOURTH|LEFT|RIGHT)$/u'`; `VOCAL_PARTS_PAYLOAD_KEYS = ['ready','spansReady','roundsReady','lyricsId','parts','lineAssignments','spans','rounds']` (the PHP↔JS lockstep source guard G3 reads).

Functions (final signatures; throw contract: `InvalidArgumentException`→400, `RuntimeException`→404, `…TablesReady()===false`→409 at the endpoint):
```php
function vocalPartsTablesReady(\mysqli $db): bool;       // memoised INFORMATION_SCHEMA count>=3 (tblVocalParts, tblLyricLineVocalParts, tblLyricWordVocalParts); catch posture = lineEnrichmentTablesReady() incl. songRelocateIsTransactionFatal() rethrow
function vocalPartsSpansReady(\mysqli $db): bool;        // tblLyricLineVocalSpans
function vocalPartsNormalizeKind(string $kind): ?string; // lower/trim, alias-fold, null when unknown
function vocalPartsKindFromWord(string $upperWord): ?array;  // ['kind'=>…, 'label'=>?string] from the markers map + ordinal RE ('GROUP 2' → group/'Group 2'); null when not a voice word
function vocalPartsDisplayLabel(array $row): string;     // Label ?? SingerName ?? musician name ?? kinds[kind]['label']
function vocalPartsShape(array $row): array;             // {id, partKind, label, singerName, gender, musicianId, displayLabel, ttmlAgentId, source, sortOrder}  ← superset of today's ?include=vocalParts keys
function vocalPartsForVersion(\mysqli $db, int $lyricsId): array;              // list<shape> ORDER BY SortOrder, Id (LEFT JOIN tblMusicians for displayLabel)
function vocalPartsFindOrCreate(\mysqli $db, int $lyricsId, string $kind, ?string $label, string $source = 'ihymns', ?string $ttmlAgentId = null, ?array $meta = null): int;
   // match (LyricsId, TtmlAgentId) when agent given; else (LyricsId, PartKind, LOWER(TRIM(Label)) NULL-safe <=>); else INSERT. Never overwrites an existing Label.
function vocalPartsUpsert(\mysqli $db, string $songId, array $input, ?int $userId): array;   // {id?, kind, label?, singerName?, gender?, musicianId?, sortOrder?} on lyricLinesEnsurePrimaryVersion(); returns shape
function vocalPartsDelete(\mysqli $db, string $songId, int $partId): bool;
function vocalPartsResolveLines(\mysqli $db, string $songId, array $lineIds): array;  // bulk IDOR guard: every id must belong to $songId AND to lyricLinesPrimaryLyricsId(); RuntimeException otherwise; returns lineId => cpLen
function vocalPartsAssignLines(\mysqli $db, string $songId, array $lineIds, array $partIds, string $mode = 'replace', bool $isBackground = false): array;
   // mode replace|add; replace DELETEs the lines' existing rows WITH THE SAME IsBackground only (an echo mark survives a lead re-assignment); SortOrder = index in $partIds; INSERT IGNORE on uq_Line_Part; returns vocalPartsForSong()
function vocalPartsClearLines(\mysqli $db, string $songId, array $lineIds, ?bool $isBackground = null): int;
function vocalPartsSpanUpsert(\mysqli $db, string $songId, array $input): array;   // {id?, lineId, partId, start, end, isBackground?, sortOrder?}; lineEnrichmentValidateOffsets(); rejects start===0&&end===cpLen
function vocalPartsSpanDelete(\mysqli $db, string $songId, int $spanId): bool;
function vocalPartsAssignLinesForVersion(\mysqli $db, int $lyricsId, int $lineId, array $partIds, bool $isBackground): int;   // ingest-only (non-ihymns versions)
function vocalPartsAssignWords(\mysqli $db, int $lyricsId, array $wordIds, int $partId, bool $isBackground): int;          // ingest-only
function vocalPartsAgentIndex(\mysqli $db, int $lyricsId): array;                 // agentId => partId
function vocalPartsPruneAgents(\mysqli $db, int $lyricsId, string $source, array $keepAgentIds): int;
function vocalPartsKindFromTtmlAgent(array $agent, int $personOrdinal): string;   // PURE (Pass 6 rules)
function vocalPartsLinesMapForSongs(\mysqli $db, array $songIds): array;   // SongId => [lineId => list<{id,kind,label,bg}>], 'ihymns' version, chunked IN() of LYRIC_LINES_READ_CHUNK
function vocalPartsSpansMapForSongs(\mysqli $db, array $songIds): array;   // SongId => [lineId => list<{id,partId,kind,label,bg,start,end}>]
function vocalPartsWordsForSong(\mysqli $db, string $songId): array;       // list<{lyricsId, source, words:list<{wordId,lineId,sortOrder,text,partId,kind,bg}>}> over NON-ihymns versions (word grain, D2)
function vocalPartsForSong(\mysqli $db, string $songId): array;
   // {ready, spansReady, roundsReady, lyricsId, parts:list<shape>, lineAssignments:{lineId: list<{partId,bg,sortOrder}>}, spans:{lineId: list}, rounds:list<roundShape>} — every collection [] and ready=false when un-migrated
function vocalPartsApplyComponentVoices(\mysqli $db, string $songId, array $norm, array $lineIdsByPos, string $source): array;  // §4.2
```
SQL for `vocalPartsLinesMapForSongs` (per chunk): `SELECT ly.SongId, lvp.LineId, vp.Id, vp.PartKind, vp.Label, vp.SingerName, m.Name AS musician_name, lvp.IsBackground, lvp.SortOrder, vp.SortOrder AS part_sort FROM tblLyricLineVocalParts lvp JOIN tblVocalParts vp ON vp.Id=lvp.VocalPartId JOIN tblLyrics ly ON ly.Id=lvp.LyricsId LEFT JOIN tblMusicians m ON m.Id=vp.MusicianId WHERE ly.SongId IN (…) AND ly.Source='ihymns' ORDER BY ly.SongId, lvp.LineId, lvp.SortOrder, vp.SortOrder, vp.Id`.

## 3.2 `includes/lyric_rounds.php` (NEW)
```php
const IHYMNS_ROUND_KINDS = ['round','canon','partner-song']; const IHYMNS_ROUND_ENDINGS = ['complete','together','coda'];
function lyricRoundsReady(\mysqli $db): bool;
function lyricRoundsForSong(\mysqli $db, string $songId): array;          // list<roundShape> incl. expanded timeline; [] un-migrated
function lyricRoundUpsert(\mysqli $db, string $songId, array $input, ?int $userId): array;  // voices replaced as a set (upsert by number, delete missing); validation → InvalidArgumentException: kind/endingMode not in map; TimesThrough 1..8; start/end both on the ihymns version and end SortOrder >= start; voices 1..N contiguous; voice 1 EntryLines=0 (and EntryBeats/EntryMs 0 or null); own span both-or-neither; coda span present iff endingMode='coda'
function lyricRoundDelete(\mysqli $db, string $songId, int $roundId): bool;
function lyricRoundSubjectLineIds(array $orderedLineIds, int $startLineId, ?int $endLineId): array;  // PURE
function lyricRoundTimeline(array $round, array $voices, array $subjectLineIds, array $lineStartMs, array $lineEndMs): array;  // PURE, §3.3
```
roundShape (the ONLY shape the projector reads; Pass 5 contract, frozen): `{id, kind, label, endingMode, timesThrough, bpm, beatsPerLine, lineIds:list<int>, codaLineIds:list<int>, voices:list<{id, number, partId, label, entryLines, entryBeats, entryMs, intervalSemitones, timesThrough}>, timeline:{basis:'ms'|'beats'|'lines', stepMs:?int, steps:list<{i, atMs:?int, voices:list<{n, line}>}>}}`; `line` = index into `lineIds ++ codaLineIds`, `-1` waiting, `-2` finished.

## 3.3 `lyricRoundTimeline()` algorithm (exact)
n = count(subject); T_v = voice.timesThrough ?? round.timesThrough; e_v (in line steps) = basis==='beats' && entryBeats!==null ? round(entryBeats/beatsPerLine) : entryLines. basis: `ms` when every voice with number>1 has entryMs!==null AND every subject line has start+end ms; else `beats` when bpm>0 && beatsPerLine>0; else `lines`. stepMs = beats ? (int)round(60000/bpm*beatsPerLine) : null. Total steps S: complete → max_v(e_v + n·T_v); together|coda → n·T_1 (later voices cut). For step i and voice v: p = i − e_v; line = p<0 ? −1 : (p ≥ n·T_v ? −2 : p mod n). coda: append count(coda) steps where every voice's line = n + k. atMs: lines → null; beats → i·stepMs (coda continues the grid); ms → cumulative sum of dur(k mod n) for k<i, dur = EndTimeMs−StartTimeMs of that subject line; voices' ms entry for basis ms is taken from entryMs directly by mapping to the first step whose atMs ≥ entryMs (documented rounding). Truth table pinned in `tests/php/test-lyric-rounds-timeline.php` (round, canon at 2 lines, 3 voices, together, coda, partner-song own spans, beats basis stepMs=2400 for bpm=100 beatsPerLine=4).

## 3.4 `includes/vocal_part_detect.php` (NEW, PURE, DB-free — the shared core #1260 consumes)
```php
const IHYMNS_VOCAL_DETECT_FORMS = ['standalone','prefix','paren','canon-note']; const IHYMNS_VOCAL_DETECT_VERSION = 1;
function vocalPartDetectClassifyLine(string $line): ?array;   // one line → {form, marker, kind, label, bg, rest:string, confidence} or null
function vocalPartDetectComponent(array $lines): array;        // one component → list<{index, form, marker, kind, label, bg, rest, runEnd:int|null, confidence}> (standalone: runEnd = index of the last line before the next marker/end)
function vocalPartDetectSong(array $components): array;        // list over components with componentIndex added
```
Regexes (u-flag): standalone `^\s*[\(\[]?\s*(?<w>[A-Z][A-Z .&/'-]{0,40}?)\s*[\)\]]?\s*:?\s*$`, every `&`/` AND `/`/`-split word must resolve via `vocalPartsKindFromWord()` (two words → `all` with label "Men and Women"; canon phrase handled first); prefix `^\s*(?<w>[A-Z][A-Z&/ '-]{0,30}?)\s*:?[ \t\x{00A0}]{2,}(?<rest>\S.*)$` (the NBSP run form, 89/10 in the corpus); paren `^\s*\((?<inner>[^()]{2,80})\)\s*$` → bg echo UNLESS inner matches the direction list `^(repeat|sing|to |x ?\d|\d+ ?x|instrumental|chorus|verse|refrain|bridge|last time|twice|three times|tag|coda|ending|spoken|optional)` (→ null, never queued); canon-note `\b(IN CANON|AS A ROUND|IN A ROUND|ROUND)\b` on an otherwise all-caps line → form canon-note, kind all, confidence medium. Confidence: standalone/prefix high; paren low; canon-note medium. Guard: `tests/php/test-vocal-part-detect.php` — truth table incl. the literal NBSP string `"MEN\u{00A0}\u{00A0}\u{00A0}\u{00A0}You are holy,"`, `"(repeat verse 2)"` → null, `"MEN AND WOMEN IN CANON"` → canon-note, `"CHORUS"` → null (section word, never a voice), `"ALL:"` → standalone all.

## 3.5 `includes/vocal_part_review.php` (NEW — queue core, api.php + manage page both delegate)
```php
function vocalPartReviewReady(\mysqli $db): bool;
function vocalPartReviewList(\mysqli $db, array $filter, int $limit, int $offset): array;   // filter {status, confidence, songbook, form}; joins tblSongs title
function vocalPartReviewAccept(\mysqli $db, int $suggestionId, ?int $userId, array $overrides = []): array;  // overrides {kind?, label?, isBackground?}
function vocalPartReviewDismiss(\mysqli $db, int $suggestionId, ?int $userId): array;
function vocalPartReviewUndo(\mysqli $db, int $suggestionId, ?int $userId): array;
function vocalPartReviewRefreshSong(\mysqli $db, string $songId): array;  // re-run detector on one song; marks vanished rows 'stale'
```
Accept control flow (one transaction): resolve rows → `vocalPartsFindOrCreate(lyricsId, kind, label, 'import-marker')` → for `standalone`: read `lyricLinesEditableComponents()`, remove the marker line from its component (position by lineId), call `lyricLinesWriteComponents($db,$songId,$comps)` (Id-preserving diff keeps every other line's Id), then `vocalPartsAssignLines(start..end)`; for `prefix`: rewrite that line's text to `rest` via the same write (pass-3 fuzzy match keeps its Id when similarity ≥0.5 — for a short lyric where it would not, the handler passes the line's original text through `_preserve`-safe path and applies the assignment AFTER the write by re-reading lineIds at the same position), then assign the line; for `paren`: no text change, assign with isBackground=1; for `canon-note`: no round is auto-created (a round needs voices/offsets) — the accept removes the note line and stores `AppliedJson.roundHint` so the editor's rounds panel offers "Create round from note". AppliedJson = `{partId, lineIds, marker:{componentIndex, lineIndex, text, form}, assignedRows:[…]}`; status→accepted; `tblActivityLog` row `vocal.suggestion_accepted`. Undo reverses in the same order (re-inserts marker text at the recorded position through the write path, clears the assignment rows it created, deletes the part if it now has zero rows and Source='import-marker'); status→undone.

# 4. Write path changes — `includes/lyric_lines_sync.php`

4.1 `lyricLinesApplyDesired(\mysqli $db, string $songId, array $desired, ?array &$lineIdsOut = null): int` — record `$lineIdsOut[$di] = $matchId ?? (int)$db->insert_id`; for a MATCHED line call `$d = lyricLinesMergePreserved($d, $existingById[$matchId] ?? null)` before the dirty check.
4.2 `lyricLinesWriteComponents()` normalisation gains `notesProvided`, `chordsProvided`, `voices` (list|null), `voicesProvided` (array_key_exists); after `lyricLinesApplyDesired(..., $lineIds)`: if `vocalPartsTablesReady()` and any `voicesProvided` → build `$byPos[pos][lineIdx] = lineId` and call `vocalPartsApplyComponentVoices($db,$songId,$norm,$byPos,$components['_voiceSource'] ?? 'ihymns')`. `_voiceSource` is an optional string key on the top-level array (skipped by the existing `is_array` filter). Cell semantics: absent → untouched; null/[] → clear; `list<{kind,label?,bg?}>` → replace.
4.3 `lyricLinesBuildDesiredFromComponents()` adds `'_preserve' => ['Note' => !$c['notesProvided'], 'ChordsJson' => !$c['chordsProvided']]` (the legacy `lyricLinesBuildDesired()` emits none → byte-identical).
4.4 NEW PURE `lyricLinesMergePreserved(array $desired, ?array $existingRow): array` exactly as Pass 6 §2.4; `lyricLinesRowClean()` unchanged.
4.5 `lyricLinesSnapshotDeletedEnrichment()` gains a `vocalParts` key (rows from tblLyricLineVocalParts JOIN tblVocalParts, plus tblLyricLineVocalSpans and tblLyricRounds where StartLineId IN(...)) behind a local memoised `lyricLinesVocalTablesPresent()`; the early return now also requires `empty($vocal)`.
4.6 Same-slot carry (Pass 4): inside `lyricLinesApplyDesired()`, when `deleteIds` is non-empty and an INSERTed desired line sits at the same `(ComponentId, index-within-component)` as a deleted one, copy that deleted line's vocal rows onto the new Id BEFORE the DELETE runs (SELECT rows → DELETE line → INSERT new → re-INSERT rows on the new Id). Best-effort, own try/catch, transaction-fatal rethrow.
4.7 Funnels: `api2.php component_upsert` accepts `notes` (array|null, key-present = intent, target-preserve from `$c['notes']` when omitted); `ed2_currentComponents()`/`lyricLinesEditableComponents()` emit `notes` (always-present, null when no line has one); `components_replace` carries `notes` in its FIFO (`'n'`); `save_song_core.php` carries `notes` in PF1 like chords. This is commit 3 and closes #2072.

# 5. Read path — `includes/lyric_lines_read.php`

5.1 NEW PURE `lyricLinesFoldVoiceRuns(array $lineIds, array $voicesByLine): array` — Pass 3 §1.1 algorithm verbatim (gap closes the run; `enters` = id absent from the run that ended on i−1; the closing ids are computed BEFORE overwriting `$cur`).
5.2 `lyricLinesAssembleFromRows(array $rows, array $voicesByLine = [], array $spansByLine = []): array` — in `$flush`, after `lineLanguages`: sparse `voices` (runs) then sparse `voiceSpans` (`{line, start, end, part:{id,kind,label,bg}}`). Public key order becomes `type, number, lines, chords, language, [label], lineIds, [lineLanguages], [voices], [voiceSpans]`.
5.3 `lyricLinesAssembleComponents()`/`…Map()` call NEW `lyricLinesFetchVoices($db, $songIds)` (lazy `require_once vocal_parts.php`; `[[],[]]` when `!vocalPartsTablesReady()`); unchanged early-out for empty songs.
5.4 `lyricLinesEditableComponents()` adds `notes` (always) — voices are NOT in the editor component shape; they arrive via the `vocalParts` sidecar.
5.5 `SongData::songDetailIncludeBlocks()` gains `'rounds'`, `'vocalWords'`; `getSongDetailExtras()` case `vocalParts` now `= vocalPartsForVersion($db,$lyricsId)` mapped through `vocalPartsShape()` (superset keys, same order for the first six), `rounds` = `lyricRoundsForSong()`, `vocalWords` = `vocalPartsWordsForSong()`; `$needsLyrics` list adds both. `access_resolver.php:128` strip list becomes `['components','translations','annotations','vocalParts','rounds','vocalWords']`.
5.6 `includes/pages/song.php`: `$rounds = $songData->getSongDetailExtras($id, ['rounds'])['rounds'] ?? []` (try/catch like the translations block at :479); emit `data-voice-rounds` on `.page-song` (json, `JSON_HEX_*` flags) only when non-empty.
5.7 `manage/editor/api2.php load_song` adds `'vocalParts' => vocalPartsForSong($db,$songId)` beside `lineTranslations`; classic `manage/editor/api.php` nests `$song['vocalParts']` the same way (:385).

# 6. Editor API (api2.php, inherit the file gate; POST-only; `ed2_requireEntitlement('edit_songs')`; 409 when `!vocalPartsTablesReady()` — rounds actions also require `lyricRoundsReady()`)

| action | body | response |
|---|---|---|
| `vocal_part_upsert` | `{songId, part:{id?,kind,label?,singerName?,gender?,musicianId?,sortOrder?}}` | `{ok, part, vocalParts}` |
| `vocal_part_delete` | `{songId, id}` | `{ok, deleted, vocalParts}` |
| `vocal_lines_assign` | `{songId, lineIds:[…], partIds:[…], mode:'replace'|'add', isBackground?:bool}` | `{ok, vocalParts}` |
| `vocal_lines_clear` | `{songId, lineIds:[…], isBackground?:bool|null}` | `{ok, cleared, vocalParts}` |
| `vocal_span_upsert` | `{songId, span:{id?,lineId,partId,start,end,isBackground?,sortOrder?}}` | `{ok, span, vocalParts}` |
| `vocal_span_delete` | `{songId, id}` | `{ok, deleted, vocalParts}` |
| `round_upsert` | `{songId, round:{id?,kind,label?,startLineId,endLineId?,timesThrough,endingMode,codaStartLineId?,codaEndLineId?,bpm?,beatsPerBar?,beatsPerLine?,voices:[{number,partId?,label?,entryLines,entryBeats?,entryMs?,intervalSemitones?,startLineId?,endLineId?,timesThrough?}]}}` | `{ok, round, vocalParts}` |
| `round_delete` | `{songId, id}` | `{ok, deleted, vocalParts}` |
Every response carries the WHOLE `vocalParts` payload (rule #35 read-back); each calls `ed2_touchRevision($db,$songId,$ed2UserId,'vocal_parts')` (15 s coalesce = D3's accepted revision) and `logActivity('song.vocalParts.*')`. `api-client.js` gains `upsertVocalPart/deleteVocalPart/assignVocalLines/clearVocalLines/upsertVocalSpan/deleteVocalSpan/upsertRound/deleteRound` (all `postJson`). api-docs.yaml: eight path items under `Editor API v2` + the two new `include` values on `song_detail`.

# 7. Editor2 UI — `manage/editor/v2/voices-panel.js` (NEW), hooks in `structure-tab.js` + `editor2.php`
- `editor2.php` emits `window._iHymnsVocalPartKinds = <?= json_encode(IHYMNS_VOCAL_PART_KINDS…) ?>` (the `_iHymnsSongPartTypes` convention, before the module script). No JS copy of the vocabulary.
- `buildCard()` appends `buildVoicesPanel(comp, {store, api, songId, toast, saveComponent, flushComponent})` after the enrichment panel. Panel: collapsible "Who sings" toggle; a per-line row list (checkbox + line text, Shift-click/Shift+Space range via `js/modules/combobox-a11y.js`-style keyboard handling, `role="group" aria-label="Choose lines"`); a `<select>` with two optgroups ("Parts on this song" from `store.vocalParts.parts`, "Add a part" from the served vocabulary — never free text; `named-singer` opens the shared musician picker `window.iHymnsPlaceSearch.attach({pickMode:'musician'})`, rule #43); an "Echo (background)" checkbox; Assign / Clear buttons. **D3 flow:** on Assign, if any selected index has `componentLineId(comp,i)===0`, `await saveComponent(comp)` first (flushes the pending debounce; adopts `res.lineIds`), re-resolve; if still 0 → toast "Save this section first" (the validation-error case — the section save failed and its toast already explained why). Then ONE `assignVocalLines` call; `store.set('vocalParts', res.vocalParts)`; re-render chips beside each line (`.v2-voice-chip`, same text-cue-not-colour rules as §8). A 409 → calm "Voice parts are not available on this install yet" (branch on `err.status`).
- Rounds sub-panel (in the same file, `buildRoundsPanel`): list existing rounds for the song; "New round": start/end from the same line checkboxes, kind, times through, voices count (2–4 default 2) each with optional part + entry offset in lines (+ optional bpm/beats-per-line for a beats basis), ending mode (+ coda span picker when 'coda'). Saves via `upsertRound`; shows a plain-English preview sentence from `round.timeline` ("Voice 2 enters after 2 lines; 4 lines × 2 = 10 steps").
- `store` key `vocalParts`; `loadSong()` hydrate `store.set('vocalParts', data.vocalParts || {ready:false,…})`; `teardown()` detaches pickers.

# 8. Render — ONE PHP renderer + ONE JS renderer, held in lockstep
- `includes/voice_parts_render.php` (NEW, pure): `ihymnsVoiceRunsByLineIndex()`, `ihymnsVoiceSpansByLineIndex()` (drops overlapping spans with error_log), `ihymnsVoiceRunAriaLabel()` ("Women" / "Women and Men" / "Women, echoed by Backing" / "Echo, sung by Backing"), `ihymnsVoiceChipsHtml()`, `ihymnsVoiceRunOpenHtml()`, `ihymnsVoiceLineHtml($text,$spans)` (span-aware, `mb_substr` by code point), `ihymnsRoundNoteHtml()`.
- `js/modules/voice-parts-render.js` (NEW): same six functions producing identical HTML strings; `tests/test-voice-render-lockstep.js` runs both over `tests/fixtures/voice-render-cases.json` and asserts byte-identical output (the org-logo-resolver-lockstep model).
- Markup exactly as Pass 5 §1.2 (a)–(d); rules: chip never inside `.lyric-line`; `data-voice-*`/`data-round-*` for machines, `aria-*` for humans; `.lyric-line--bg` on all-bg lines; `data-round-id` on every subject `<p>`; round note only before `lineIds[0]`.
- CSS (`css/app.css` new section after `.lyric-line-note`): `.lyric-voice-run{margin:.35rem 0 .5rem}`, `.lyric-voice-chips` inline-flex gap, `.lyric-voice-chip{font-size:.7rem;text-transform:uppercase;letter-spacing:.04em;padding:.05rem .45rem;border:1px solid var(--card-border);border-radius:999px;background:var(--surface-elevated);color:var(--text-primary)}`, `.lyric-voice-chip--bg` dashed border + italic, `.lyric-line--bg{font-style:italic;padding-left:1.25rem;border-left:2px dashed var(--card-border)}`, `.lyric-voice-span--bg{font-style:italic;opacity:.85}`, `.lyric-round-note` muted small; high-contrast/CVD: no colour-only cue anywhere (text + border style carry meaning); print.css: chips print as plain small caps.
- Render sites (tree-derived census, guard G7): `includes/pages/song.php` (PHP renderer); `js/modules/setlist.js` :2171 and :2845 (JS renderer, wraps runs from `comp.voices`); `js/modules/print.js` renderLyricsBlock (chip as `.print-voice` line before the run — voices survive to PDF through the same renderer, rule #39); `js/modules/present-mode.js` (slide text unchanged; a slide gains `voiceRuns:[{label, lineFrom, lineTo, bg}]` read from `.lyric-voice-run[data-voice-parts]` and renders a small caption line above each run); `share.js`, `display.js`, `song-markup.js`, `song-translations.js` need NO change (pure `.lyric-line` scraping still correct) — the guard asserts they contain no `.lyric-voice` reference AND that no `.lyric-line` innerHTML/textContent consumer gained a chip inside the `<p>`.
- Native (Apple `SongComponent.swift`, Android): decode `voices`/`voiceSpans` as OPTIONAL fields (unknown-key-tolerant today; guard G7 scans `.swift`/`.kt` for a `voices` decoder so the apps cannot silently be left out — the Label lesson) — DEFERRED rendering (§10).

# 9. Present mode — rounds projector + playback (D1)
`present-mode.js`: if `.page-song[data-voice-rounds]` is non-empty, each round gets ONE extra slide type `{kind:'round', round}` inserted where its first subject line's component slide sits. Render: split panel (`.present-round` grid, one column per voice, `role="group" aria-label="Voice N"`), each column shows its current line from `round.timeline.steps[step].voices[n].line` (waiting → "…", finished → "(end)"), a numeral + border style per voice (no colour-only cue), step counter; Prev/Next step inside the round slide; Play/Pause button (`aria-pressed`) auto-advances when `basis !== 'lines'` using `atMs` deltas (`setTimeout`, cleared on close — the #1568 listener-leak lesson), Space toggles play, arrow keys step; `announce()` on every step ("Step 3 of 10: Voice 1 line 3, Voice 2 line 1"). Remote drive: `service_broadcast`/`service_drive` accept an additive `roundStep:{roundId, step}` inside the existing StateJson vocabulary (`serviceMode_applyBroadcast()` validates ints; no schema change); `service-follow.js` shows "You are here: Voice 2" when the follower picked a voice (per-device `localStorage.ihymns_round_voice`). Never a second scheduler in JS.

# 10. Importers / ingest / export
- **#2075** (commit 10): NEW `_bulkImport_voiceHeaderOrSection(string $marker): array{kind:'section'|'voice'|'unknown', …}` calls the existing section maps FIRST, then `vocalPartDetectClassifyLine()`; NEW `_bulkImport_appendVoiceBlock(array &$components, array $voice, array $lines)` merges a voice block into the PREVIOUS component (never fabricates a refrain) and fills `voices[i]=[{kind,label,bg}]` for those lines; an `unknown` word becomes the component `label` (the PP7 pattern, rule #45) with `warnings[]` "Unrecognised section marker 'X' kept as a label". Applied at `_bulkImport_parseTxt` (:322-333), OpenSong (:2165 caller), VideoPsalm (:2425 caller), OpenLyrics verse-name (:3076 caller). Importers set `$song['components']['_voiceSource']='import-marker'`. Un-migrated install: voices ignored by the writer, label/merge still applied.
- **#2071** (commit 11): the two `preg_replace('#^<lines\b[^>]*>#i')` sites keep stripping the tag, but the CALLER reads `$linesNode['part']` / `['repeat']` BEFORE (Pass 6 §3.1 loop): `part` → `vocalPartsResolveOpenLyricsPart()` (kind map `openlyrics` column reverse + `vocalPartsKindFromWord()`), `repeat` 2..99 → first-line note "Repeat ×N". Attribute-less `<lines>` blocks (the exporter's slide chunks) concatenate exactly as today. Exporter (`format-export.js buildOpenLyrics`): when a component has `voices`, emit ONE `<lines part="…">` per run (`openlyrics` keyword, or the run label lower-cased when no keyword) and chunk ONLY within attribute-less runs; a component with no voices is byte-identical to today. Test: `tests/php/test-openlyrics-voice-parts.php` (import) + `tests/test-openlyrics-export-parts.js` (export → import closure through the real PHP parser via `php -r`).
- **TTML (commit 12, D2):** `lyricsIngest_parseTtml()` returns `agents:{id:{type,name}}` read from `<head>` (`getElementsByTagNameNS('*','agent')`, `xml:id`, `type`, child `<ttm:name>`); each line gets `agent` (own `ttm:agent`, IDREFS split on whitespace) and `bg`; word building distinguishes a CONTAINER span whose children are separated by whitespace text nodes (→ a word GROUP: each child becomes a word inheriting the container's `agent`/`bg`) from a syllable container (no whitespace between children, unchanged); every word inherits line agent/bg unless it carries its own. `lyricsIngest_writeToDb()` after the line loop (same transaction, gated by `vocalPartsTablesReady()`): parts via `vocalPartsFindOrCreate(lyricsId, vocalPartsKindFromTtmlAgent(), name, $source, agentId, meta)`; `vocalPartsPruneAgents()`; `vocalPartsAssignLinesForVersion()` per line; `vocalPartsAssignWords()` ONLY for words whose (agent,bg) differs from their line (the DDL's override rule). Return gains `vocalParts`/`lineAssignments`/`wordAssignments` counts. Test `tests/php/test-ttml-vocal-ingest.php` (parser-only truth table on an Apple-shaped fixture with `x-bg` container, two agents, per-word agent override).
- **Exports (commit 13):** OpenLyrics `part=`; plain-text/Proclaim/FreeShow-text header lines write the canonical UPPER marker (`array_key_first(markers)`) on its own line before the run — the detector reads it straight back (round-trip test); ChordPro `{comment: WOMEN}`; OpenSong `;WOMEN` comment row. ProPresenter/VideoPsalm/EasyWorship/TTML export: DEFERRED (§12).

# 11. Backfill + review page
- `appWeb/.sql/migrate-backfill-vocal-part-suggestions.php` — registry entry `'vocal-parts-backfill'` with `'manual' => true`, `confirm=1`-gated for the WRITE run, dry-run by default (prints counts per form/songbook), idempotent (INSERT IGNORE on `uq_Marker_Form`; existing accepted/dismissed rows untouched; rows whose marker line no longer classifies → `stale`). Probe: `!tableExists('tblVocalPartSuggestions') || sentinel tblAppSettings 'vocal_parts_backfill_ran' absent`. Walks songs in chunks of 500 via `lyricLinesFetchPrimaryMap()` (rule #17 — never the whole corpus). Include resolution per rule #41.
- `manage/vocal-parts-review.php` (NEW): `auth.php` + `isAuthenticated()` + `userHasEntitlement('edit_songs')`; `$activePage='vocal-parts-review'`; `admin-nav`/`admin-footer`/`head-favicon`; `.admin-table-responsive` + sortable headers; filters status/confidence/form/songbook; per-row: song link, marker, form, proposed part (editable kind `<select>` from the vocabulary + label), the target lines preview, Accept / Dismiss / Undo (POST, `validateCsrfRequest()`, `respond=json`). Row in `admin-links.php`: `['vocal-parts-review','/manage/vocal-parts-review','bi-people','Voice-part suggestions','edit_songs','Songs']`.
- api.php twins (rule #48): `admin_vocal_suggestion_list` (GET), `admin_vocal_suggestion_accept`, `_dismiss`, `_undo`, `_refresh_song` (POST, `getAuthenticatedUser()`, `userHasEntitlement('edit_songs')`, `validateCsrfRequest()`, `sendJson`), each delegating to `vocal_part_review.php`. Coverage-guard mapping entries for the page's four actions → `api:admin_vocal_suggestion_*`; gate-parity pairs `['edit_songs','vocal-parts-review']`; api-docs path items.

# 12. Commit plan (branch `feat/vocal-parts-echo-rounds-2073`, ONE PR to alpha, PR title `feat(lyrics): voice parts, echo, rounds and a review queue (#2073)`)
| # | title | files | proves it |
|---|---|---|---|
| 1 | `chore(vocal-parts): vocabulary + read-only core skeleton` | includes/vocal_parts.php (constants, probes, normalisers, shapes, read fetchers), tests/php/test-vocal-parts-vocab.php | G1 green; `php -l`; nothing calls it yet — dormant |
| 2 | `feat(db): one-pass tables for echo spans, rounds and the review queue` | appWeb/.sql/migrate-vocal-parts-rounds.php, schema.sql, migration-registry.php (+ stale card copy fix) | test-schema-coverage, test-schema-ddl-parity (extend to the 4 tables), test-migration-registry, test-schema-installs; dormant |
| 3 | `fix(lyrics): per-line preserve-on-omit + Note read in the editor (#2072)` | lyric_lines_sync.php (§4.1,4.3,4.4,4.5 enrichment part), lyric_lines_read.php (editor `notes`), api2.php component_upsert/components_replace/ed2_currentComponents, save_song_core.php, test-lyric-lines-diff.php, test-lyric-lines-read.php | G2; fidelity snapshot unchanged (public shape untouched); live |
| 4 | `feat(read): voice runs and spans in the public shape; rounds/vocalWords include blocks; gating strip lockstep` | lyric_lines_read.php (§5.1-5.3), vocal_parts.php fetchers, SongData.php, access_resolver.php, api-docs.yaml, tests/php/test-lyric-lines-read.php (fold truth table), tests/php/test-lyric-body-strip-lockstep.php | G4, G5; `tools/export-fidelity-snapshot.php --compare` byte-identical; live-but-empty |
| 5 | `feat(vocal-parts): write core, rounds core with pure timeline, importer voices transport` | vocal_parts.php (write half), includes/lyric_rounds.php, lyric_lines_sync.php (§4.2,4.6 + vocal snapshot), orphan-allowlist.php (remove tblVocalParts entry), tests/php/test-vocal-parts-core.php, test-lyric-rounds-timeline.php | G3 (pure), test-orphan-inventory; dormant until a caller |
| 6 | `feat(editor-api): eight vocal/round actions + vocalParts on load_song` | api2.php, manage/editor/api.php (classic sidecar), api-client.js, api-docs.yaml, tests/php/test-editor-api2-contract.php (extend) | contract guard; 409 on un-migrated |
| 7 | `feat(editor2): "Who sings" panel with run selection, echo, and rounds` | v2/voices-panel.js (NEW), structure-tab.js, editor2.php (vocab emit + store hydrate), css/admin.css, tests/test-v2-voices-ui.js | G6 |
| 8 | `feat(render): voice runs, echo and round notes on the song page + every JSON-shape site` | includes/voice_parts_render.php, js/modules/voice-parts-render.js, song.php, setlist.js, print.js, present-mode.js (runs caption only), css/app.css, print.css, tests/fixtures/voice-render-cases.json, tests/test-voice-render-lockstep.js, tests/test-voice-render-sites.js | G7, G8; a11y static checks green |
| 9 | `feat(present): staggered round projector with synchronised playback and remote drive` | present-mode.js, service_mode.php (roundStep in StateJson vocab), service-follow.js, service-projection.php, tests/test-present-round-projector.js | G9 (jsdom: 3-voice canon steps + play/pause timer cleared on close) |
| 10 | `fix(import): stop turning voice markers into fake refrains; keep the word (#2075)` | includes/vocal_part_detect.php (NEW), song_importers.php (4 sites + helpers), tests/php/test-vocal-part-detect.php, test-importer-voice-markers.php | G10; existing importer fixtures byte-identical for files with no markers |
| 11 | `fix(import): honour OpenLyrics <lines part=/repeat=> and export part-bearing blocks (#2071)` | song_importers.php, format-export.js, tests/php/test-openlyrics-voice-parts.php, tests/test-openlyrics-export-parts.js | round-trip closure; #1129 harness green |
| 12 | `feat(ingest): TTML agents, background groups and word-grain voice parts (D2)` | lyrics_ingest.php, vocal_parts.php (ingest fns), tests/php/test-ttml-vocal-ingest.php | G11 |
| 13 | `feat(export): voice markers in plain-text, Proclaim, ChordPro and OpenSong exports` | format-export.js, tests/test-export-voice-markers.js | export→detector round-trip |
| 14 | `feat(backfill): detect voice markers across the catalogue into a review queue (D4)` | appWeb/.sql/migrate-backfill-vocal-part-suggestions.php, migration-registry.php, includes/vocal_part_review.php, tests/php/test-vocal-part-review.php, test-deploy-paths (auto) | dry-run output; manual card; rule #41 |
| 15 | `feat(admin): /manage/vocal-parts-review page + API twins` | manage/vocal-parts-review.php, admin-links.php, api.php, api-docs.yaml, tests/php/test-manage-action-api-coverage.php + test-api-gate-parity.php mappings | coverage + parity guards |
| 16 | `chore(tests): native-shape decoders tolerate voices/voiceSpans/rounds` | appApple SongComponent.swift (optional fields), Android model, tests/test-native-identity-contract.js extension | contract test |
| 17 | `docs(vocal-parts): rule #51, wiki, changelog, what's new, handoff` | .claude/CLAUDE.md, .claude/ProjectBrief.md, .claude/vocal-parts-2073-plan.md, .claude/sessions/…HANDOFF.md, wiki/*, CHANGELOG.md, WHATS-NEW.md, README.md | docs guards (changelog rollover) |
`Release: patch` is NOT added (this is a `feat:` → minor bump to 1.4.0; new `## 1.4.0` heading in WHATS-NEW.md).

# 13. Dormant vs live per step
- After 1–2: 100% dormant (no caller; tables absent until the card runs).
- After 3: live bug fix; byte-identical for every caller that provides keys; a caller that omits `notes`/`chords` now preserves instead of NULLing.
- After 4: live reads; on an un-migrated install `lyricLinesFetchVoices()` returns `[[],[]]` → assembler output byte-identical; on a migrated-but-empty install identical too (sparse keys). `?include=rounds|vocalWords` omitted when un-migrated (the existing per-block try/catch + ready gate).
- After 5–7: writes exist but every endpoint 409s and the panel shows a calm notice on an un-migrated install; importer `voices` cells are dropped when `!vocalPartsTablesReady()`.
- After 8–9: render code paths are entered only when a component carries `voices`/`voiceSpans` or `.page-song[data-voice-rounds]` exists.
- After 10–13: marker preservation (label/merge) is LIVE everywhere (it is a bug fix); voice application is gated.
- After 14–15: manual card, dry-run default; page renders an empty queue with a "run the backfill" pointer when un-migrated.
Verification: `tools/export-fidelity-snapshot.php --compare` before/after on the shared DB with the migration NOT applied, then applied-but-empty; both must be byte-identical.

# 14. Guards (all tree-derived, mutation-proven — each test file runs its own in-memory mutation and prints the red before restoring)
- G1 `tests/php/test-vocal-parts-vocab.php` — every key has the six facets; markers never contain a section word (derived from `tblSongPartTypes` seed list in migrate-song-part-types.php); `vocalPartsKindFromWord()` truth table. Mutations: add `'CHORUS' => null` to markers → red; drop `openlyrics` from a key → red.
- G2 `tests/php/test-lyric-lines-diff.php` (extend) — `lyricLinesMergePreserved()` truth table; structural: `lyricLinesApplyDesired` body contains `lyricLinesMergePreserved(`. Mutation: remove the call → red.
- G3 `tests/php/test-lyric-rounds-timeline.php` + `test-vocal-parts-core.php` — pure truth tables. Mutation: change `p mod n` to `p` → red.
- G4 `tests/php/test-lyric-lines-read.php` (extend) — fold truth table (Pass 3 §1.1 table) + "no `voices` key when `$voicesByLine === []`" (sparseness). Mutation: emit `voices => []` always → red.
- G5 `tests/php/test-lyric-body-strip-lockstep.php` — derives the version-keyed block list from `SongData::getSongDetailExtras()`'s `$needsLyrics` array and asserts every member appears in access_resolver.php's strip array. Mutations: remove `'rounds'` from either side → red.
- G6 `tests/test-v2-voices-ui.js` — reads `VOCAL_PARTS_PAYLOAD_KEYS` from the PHP source, asserts voices-panel.js reads only those keys; asserts editor2.php emits `_iHymnsVocalPartKinds` from the PHP constant and voices-panel.js contains NO kind literal list; asserts the `<select>` has no free-text fallback (`contenteditable`/`type="text"` for the part). Mutations: add `['lead','male']` literal → red; rename a payload key on one side → red.
- G7 `tests/test-voice-render-sites.js` — globs every `.js`/`.php`/`.swift`/`.kt` that builds `lyric-line` markup or decodes `components[]` (fingerprint: `lyric-line` string OR `\.lines\b` beside `\.type\b`), floor list {song.php, setlist.js, print.js, present-mode.js, SongComponent.swift}; each web site must call the ONE renderer (`ihymnsVoice*`/`voicePartsRender`) or be on the explicit scraper allow-list with a reason; each native model must declare an optional `voices` field. Mutations: delete the renderer import from setlist.js → red; add a `<span class="lyric-voice-chip">` inside a `<p class="lyric-line">` in any file → red (regex: chip token between `lyric-line` open tag and `</p>`).
- G8 `tests/test-voice-render-lockstep.js` — PHP vs JS renderer over the shared fixture, byte-identical. Mutation: change one class name on one side → red.
- G9 `tests/test-present-round-projector.js` — jsdom; asserts timers are cleared on close (count `setTimeout` vs `clearTimeout` calls via stubs). Mutation: remove `clearTimeout` in close → red.
- G10 `tests/php/test-vocal-part-detect.php` + `test-importer-voice-markers.php` — detector truth table (NBSP form, direction rejects, canon-note); importer: the four sites no longer contain `?? 'refrain'` as the ONLY fallback for an unknown marker (structural), and a fixture with `WOMEN/MEN/ALL` yields ONE component with `voices` cells and NO `number=0 refrain`. Mutation: restore `?? 'refrain'` at one site → red.
- G11 `tests/php/test-ttml-vocal-ingest.php` — parser: `agents` map present; `x-bg` container yields N words not 1; per-word inheritance. Mutation: drop the whitespace-text-node discriminator → red.
- Existing guards that must stay green and are extended: test-schema-coverage, test-schema-ddl-parity (4 new tables), test-migration-registry, test-deploy-paths (.sql/), test-orphan-inventory (allowlist entry removed), test-manage-action-api-coverage (new mappings), test-api-gate-parity, test-fragment-inline-scripts (no inline script in song.php), test-component-label-sites (exporters keep zero `.label`), test-component-json-guard, test-editor-deep-links, test-event-names (any new `ihymns:*` event goes in constants.js), test-a11y-static-checks, test-icon-button-names.

# 15. Standing tasks (§7 of the brief)
- Issues: #2073 (tracker — update with the plan, close on merge with SHAs); #2071/#2072/#2075 close with commit SHAs (11/3/10); #1137 comment "write side shipped in <sha>"; #1260 comment "detector core `includes/vocal_part_detect.php` + `tblVocalPartSuggestions` available for Phase 2 triage"; #2077 comment (card-copy fix landed, guard side still open); NEW issues: sub-line span curator UI; word-grain curator entry; public-shape sparse `notes` + present-mode presenter notes; native rendering of voices/rounds (Apple + Android); ms-basis round entry from TTML timings; ProPresenter/VideoPsalm/TTML voice export; classic-editor chord remap (#1263 residue); extract `ed2_touchRevision`/`ed2_buildSongSnapshot` to `includes/song_revisions.php`; live multi-device round-follow verify.
- Wiki: Song-Data-Format.md (public/editor shapes, `voices`/`voiceSpans`/`rounds`), API-Reference.md (8 api2 + 5 api.php actions, include blocks), Database-&-Migrations.md (4 tables + 2 cards), Import-&-Export-Fidelity.md (OpenLyrics part=, marker round-trip, TTML agents), Architecture.md (the three cores), Live-Follow-&-Service-Mode.md (roundStep).
- api-docs.yaml in commits 4/6/15; CHANGELOG.md `[unreleased]` technical bullets per commit; WHATS-NEW.md new `## 1.4.0 — <date>` with plain bullets ("Songs can now show who sings each line — women, men, everyone, a soloist — and echoes; rounds and canons show each voice entering in turn in Present mode and can play along in time; older songs that had these markers typed into the words are queued for a quick review"); .claude/CLAUDE.md rule #51 (voice parts: ONE core, sibling-wrapper markup, preserve-on-omit for per-line columns, detector shared with #1260, no client scheduler); ProjectBrief.md schema summary; new `.claude/vocal-parts-2073-plan.md` (this spec); handoff refreshed after every commit per standing-directives.

## Files
- `appWeb/.sql/migrate-vocal-parts-rounds.php` (NEW) — NEW — creates tblLyricLineVocalSpans, tblLyricRounds, tblLyricRoundVoices, tblVocalPartSuggestions (spec §2), each CREATE fronted by its own existence probe; no includes beyond db; rule #41 include resolution comment.
- `appWeb/.sql/schema.sql` — Append the four CREATE TABLE blocks byte-identically after the tblLyricWordVocalParts block.
- `appWeb/.sql/migrate-backfill-vocal-part-suggestions.php` (NEW) — NEW — manual, dry-run-default, chunked (500) detector batch writing tblVocalPartSuggestions; sentinel tblAppSettings 'vocal_parts_backfill_ran'; IHYMNS_INCLUDES_DIR-resolved includes.
- `appWeb/public_html/manage/includes/migration-registry.php` — Append 'vocal-parts-rounds' (4-table OR probe) and 'vocal-parts-backfill' ('manual' => true, sentinel probe) at the END; fix the stale 'vocal-parts' card body (tblCreditPeople → tblMusicians).
- `appWeb/public_html/includes/vocal_parts.php` (NEW) — NEW — the ONE voice-part core: IHYMNS_VOCAL_PART_KINDS + aliases/genders/sources/ordinal RE, VOCAL_PARTS_PAYLOAD_KEYS, probes, normalisers, shapes, read fetchers, write half, ingest helpers, vocalPartsApplyComponentVoices() (spec §3.1).
- `appWeb/public_html/includes/lyric_rounds.php` (NEW) — NEW — rounds core + PURE lyricRoundTimeline()/lyricRoundSubjectLineIds() (spec §3.2-3.3).
- `appWeb/public_html/includes/vocal_part_detect.php` (NEW) — NEW — PURE marker detector shared with #1260 (spec §3.4).
- `appWeb/public_html/includes/vocal_part_review.php` (NEW) — NEW — review-queue core: list/accept/dismiss/undo/refresh with AppliedJson (spec §3.5).
- `appWeb/public_html/includes/voice_parts_render.php` (NEW) — NEW — pure PHP HTML renderer for runs/chips/spans/round notes (spec §8).
- `appWeb/public_html/includes/lyric_lines_sync.php` — lyricLinesApplyDesired() gains &$lineIdsOut + lyricLinesMergePreserved() call + same-slot vocal carry; lyricLinesWriteComponents() gains notesProvided/chordsProvided/voices/voicesProvided + the voices→FK conversion; lyricLinesBuildDesiredFromComponents() emits _preserve; NEW lyricLinesMergePreserved(); lyricLinesSnapshotDeletedEnrichment() snapshots vocal rows/spans/rounds.
- `appWeb/public_html/includes/lyric_lines_read.php` — NEW pure lyricLinesFoldVoiceRuns(); lyricLinesAssembleFromRows() takes $voicesByLine/$spansByLine and emits sparse voices/voiceSpans; wrappers call NEW lyricLinesFetchVoices(); lyricLinesEditableComponents() emits always-present notes.
- `appWeb/public_html/includes/SongData.php` — songDetailIncludeBlocks() + getSongDetailExtras(): vocalParts via the core (superset shape), NEW rounds + vocalWords blocks; $needsLyrics extended.
- `appWeb/public_html/includes/access_resolver.php` — Lyric-body strip list adds 'rounds', 'vocalWords'.
- `appWeb/public_html/includes/lyrics_ingest.php` — Parser reads <head> agents, distinguishes word-group containers from syllable containers, inherits agent/bg per word; writer assigns line + word parts inside its transaction, prunes stale agents.
- `appWeb/public_html/includes/song_importers.php` — #2075: four fake-refrain sites route through _bulkImport_voiceHeaderOrSection()/_bulkImport_appendVoiceBlock(); #2071: OpenLyrics verse loop reads part=/repeat= before the tag strip; importers stamp _voiceSource='import-marker'.
- `appWeb/public_html/includes/pages/song.php` — Lyrics loop wraps runs via the PHP renderer (chip as sibling of the <p>s inside .lyric-lines), span-aware line text, data-round-id on subject lines, round note, data-voice-rounds on .page-song.
- `appWeb/public_html/includes/service_mode.php` — StateJson vocabulary gains additive roundStep {roundId, step} validated in serviceMode_applyBroadcast().
- `appWeb/public_html/manage/editor/api2.php` — component_upsert/components_replace/ed2_currentComponents carry notes; load_song adds vocalParts sidecar; eight new actions vocal_part_upsert/_delete, vocal_lines_assign/_clear, vocal_span_upsert/_delete, round_upsert/_delete (POST, edit_songs, 409 gate, whole-payload read-back, ed2_touchRevision).
- `appWeb/public_html/manage/editor/api.php` — Classic load_song nests $song['vocalParts'] beside lineTranslations.
- `appWeb/public_html/manage/editor/save_song_core.php` — PF1 carry for notes (mirrors chords).
- `appWeb/public_html/manage/editor/editor2.php` — Emit window._iHymnsVocalPartKinds from the PHP constant; hydrate store 'vocalParts' in loadSong().
- `appWeb/public_html/manage/editor/v2/voices-panel.js` (NEW) — NEW — 'Who sings' per-section panel (run selection, part select from vocabulary/existing parts, echo flag, D3 pre-flush) + rounds sub-panel.
- `appWeb/public_html/manage/editor/v2/structure-tab.js` — buildCard() appends buildVoicesPanel(); pass flush hook; teardown detaches.
- `appWeb/public_html/manage/editor/v2/api-client.js` — Eight postJson methods for the new actions.
- `appWeb/public_html/manage/editor/format-export.js` — OpenLyrics part-bearing <lines> per run (chunk only attribute-less runs); text/Proclaim canonical UPPER marker line; ChordPro {comment:}; OpenSong ; row. Zero .label references (guard).
- `appWeb/public_html/js/modules/voice-parts-render.js` (NEW) — NEW — JS twin of voice_parts_render.php, byte-identical output.
- `appWeb/public_html/js/modules/setlist.js` — Both lyric renderers (:2171, :2845) wrap runs via voice-parts-render.js.
- `appWeb/public_html/js/modules/print.js` — renderLyricsBlock emits .print-voice caption per run via the shared renderer (reaches PDF through the one renderer, rule #39).
- `appWeb/public_html/js/modules/present-mode.js` — Reads .lyric-voice-run for slide captions; adds round slides: split-panel projector, Prev/Next step, Play/Pause auto-advance on atMs (timers cleared on close), announce() per step, remote roundStep.
- `appWeb/public_html/js/modules/service-follow.js` — Renders 'You are here: Voice N' from roundStep; per-device voice choice.
- `appWeb/public_html/css/app.css` — New section: .lyric-voice-run/.lyric-voice-chips/.lyric-voice-chip[--bg]/.lyric-line--bg/.lyric-voice-span[--bg]/.lyric-round-note/.present-round grid — token-based, no colour-only cue, HC/CVD safe.
- `appWeb/public_html/css/print.css` — Chips print as small caps; round note prints.
- `appWeb/public_html/manage/vocal-parts-review.php` (NEW) — NEW — review queue page (edit_songs gate, shared partials, responsive sortable table, Accept/Dismiss/Undo via validateCsrfRequest + respond=json).
- `appWeb/public_html/manage/includes/admin-links.php` — Add ['vocal-parts-review','/manage/vocal-parts-review','bi-people','Voice-part suggestions','edit_songs','Songs'].
- `appWeb/public_html/api.php` — admin_vocal_suggestion_list/_accept/_dismiss/_undo/_refresh_song delegating to vocal_part_review.php (edit_songs, validateCsrfRequest, sendJson).
- `appWeb/public_html/api-docs.yaml` — Eight api2 path items, five api.php admin actions, two new song_detail include values, the voices/voiceSpans component keys and the vocalParts superset shape.
- `appApple/Packages/iHymnsKit/Sources/IHModels/SongComponent.swift` — Optional voices/voiceSpans decoders (rendering deferred).
- `tests/php/fixtures/orphan-allowlist.php` — Remove the tblVocalParts 'reader_no_writer' entry (commit 5).
- `tests/php/test-vocal-parts-vocab.php` (NEW) — NEW guard G1.
- `tests/php/test-lyric-rounds-timeline.php` (NEW) — NEW guard G3 timeline truth table.
- `tests/php/test-vocal-parts-core.php` (NEW) — NEW — pure core functions (kind resolution, fold helpers, span validation).
- `tests/php/test-lyric-body-strip-lockstep.php` (NEW) — NEW guard G5.
- `tests/php/test-vocal-part-detect.php` (NEW) — NEW guard G10 detector truth table (NBSP form, direction rejects, canon-note).
- `tests/php/test-importer-voice-markers.php` (NEW) — NEW — four importers preserve markers, no fake refrain.
- `tests/php/test-openlyrics-voice-parts.php` (NEW) — NEW — part=/repeat= import truth table.
- `tests/php/test-ttml-vocal-ingest.php` (NEW) — NEW guard G11.
- `tests/php/test-vocal-part-review.php` (NEW) — NEW — accept/undo AppliedJson symmetry (pure parts) + structural checks.
- `tests/php/test-lyric-lines-read.php` — Extend: fold truth table + sparseness proof + editor notes key.
- `tests/php/test-lyric-lines-diff.php` — Extend: lyricLinesMergePreserved() + structural call-site check.
- `tests/php/test-schema-ddl-parity.php` — Extend the parity set to the four new tables.
- `tests/php/test-manage-action-api-coverage.php` — Mappings for vocal-parts-review.php actions → api:admin_vocal_suggestion_*.
- `tests/php/test-api-gate-parity.php` — Pairs for the five admin_vocal_suggestion_* actions → edit_songs / vocal-parts-review.
- `tests/php/test-editor-api2-contract.php` — Extend to the eight new actions (POST-only, 409 gate, vocalParts read-back key).
- `tests/test-v2-voices-ui.js` (NEW) — NEW guard G6 (payload-key lockstep from PHP, no JS kind literals, no free-text part input).
- `tests/test-voice-render-sites.js` (NEW) — NEW guard G7 (tree-derived census incl. .swift/.kt; chip-inside-<p> ban).
- `tests/test-voice-render-lockstep.js` (NEW) — NEW guard G8 PHP↔JS renderer byte-identity over tests/fixtures/voice-render-cases.json.
- `tests/fixtures/voice-render-cases.json` (NEW) — NEW shared renderer fixture (run, duet, whole-line echo, sub-line echo, round note, no-voices component).
- `tests/test-present-round-projector.js` (NEW) — NEW guard G9.
- `tests/test-openlyrics-export-parts.js` (NEW) — NEW — export→import closure through the real PHP parser.
- `tests/test-export-voice-markers.js` (NEW) — NEW — text-family exports round-trip through the detector.
- `.claude/CLAUDE.md` — New rule #51 (voice parts / echo / rounds: one core, sibling-wrapper markup, per-line preserve-on-omit, shared detector, server-side timeline) + red-flag bullets.
- `.claude/vocal-parts-2073-plan.md` (NEW) — NEW — this specification as the plan of record.
- `.claude/ProjectBrief.md` — Schema summary + feature state.
- `.claude/sessions/2026-09-05-HANDOFF.md` — Live handoff per commit.
- `wiki/Song-Data-Format.md` — Public/editor shapes incl. voices/voiceSpans/rounds/vocalWords.
- `wiki/API-Reference.md` — New actions + include blocks.
- `wiki/Database-&-Migrations.md` — Four tables + two cards.
- `wiki/Import-&-Export-Fidelity.md` — OpenLyrics part=, marker round-trip, TTML agents/word grain.
- `wiki/Architecture.md` — The three cores and the render lockstep.
- `wiki/Live-Follow-&-Service-Mode.md` — roundStep in StateJson.
- `CHANGELOG.md` — [unreleased] technical bullets per commit.
- `WHATS-NEW.md` — New `## 1.4.0 — <date>` heading with plain-language bullets, no internals.

## Risks
- SILENT: a chip or caption emitted INSIDE <p class="lyric-line"> by any future site corrupts Present slides / share snippets / set-list playback text with no error — guard G7's chip-inside-<p> regex is the only mechanism; keep it derived from the tree, never a typed list.
- SILENT: a rebuilding funnel (components_replace, save_song_core, an old revision restore, lyrics_ingest re-ingest) that omits `notes` NULLs presenter notes today — commit 3 must land BEFORE any commit that adds a per-line concern, and G2's structural check must fail if lyricLinesMergePreserved() is ever bypassed.
- SILENT: voice rows cascade away when lyricLinesDiff() scores a rewritten line below 0.5 (delete+insert) — the same-slot carry (§4.6) + the extended orphan snapshot are best-effort; a heavy rewrite of a marked run still loses assignments quietly unless the curator re-checks. Document in the panel ('re-typing a whole line clears its voice').
- SILENT: the public shape's sparse `voices` key means a consumer that never checks for it simply shows nothing — that is the intended degrade, but the review page/backfill will make thousands of songs suddenly carry the key; run the fidelity snapshot compare BEFORE any accept to prove the code path is byte-identical, and expect the hash to change legitimately AFTER accepts.
- Gating: voices/voiceSpans ride inside `components`, so they are stripped with the lyric body for free, but `rounds`/`vocalWords` are separate blocks — G5 is the only thing tying SongData's block list to the strip list; a new block added to one side and not the other leaks who-sings-what to a denied tier.
- Cross-env: the three docroots share ONE MySQL — commit 2's tables appear on alpha's DB the moment the card is run and are then visible to beta/main code that predates the feature; every read is gated by the memoised probes so that is safe, but a probe added later without the songRelocateIsTransactionFatal() rethrow posture would swallow a deadlock inside a save transaction (the #1688 class).
- Backfill scale: ~16k songs × chunked reads is fine, but the marker detector's NBSP regex must be tested against real corpus lines (the snapshot is 4.5 months stale and 22% of the corpus); the dry-run report per form/songbook is the sanity check — do NOT run the write pass until the dry-run counts are eyeballed (MP/CP ~1.2%, CH/SDAH 0 is the expected shape).
- Round timeline edge cases: partner-song own spans of unequal length, coda with `together`, a voice with TimesThrough override longer than voice 1 under `together` (cut mid-phrase by design) — pin all in G3 before writing the projector; a projector reading an unexpected `line` value must render '…' rather than throw.
- Present-mode timers: auto-advance setTimeouts that survive close() leak and can advance a closed overlay's state — G9 counts clearTimeout calls; keep the #1568 'clean up on every close path' discipline.
- TTML ingest idempotency: re-ingest DELETEs lines (CASCADE join rows) but the part registry keyed on uq_Lyrics_Agent survives — if an agent id is reused for a different singer across ingests, the old row's kind/label is kept by design (never overwrite); vocalPartsPruneAgents() only removes ids no longer present. Verify on a real Apple TTML file before trusting word-grain counts (owner checklist).
- api2 auth: new actions must be POST-only and NOT added to ED2_GET_SAFE_ACTIONS — the file-level X-Requested-With gate only runs for POST; a GET-listed write would bypass CSRF entirely.
- Review-queue accept rewrites lyric TEXT (drops a marker line / strips an NBSP prefix) — a wrong accept changes the words a congregation sees; Undo must be proven symmetric in test-vocal-part-review.php, and Accept must be per-row (never bulk) in v1.
- Rule #46: the PR title MUST start with `feat(` or the minor never bumps; do not add `Release: patch`. WHATS-NEW.md needs the new 1.4.0 heading in the same PR or /whats-new goes stale silently.
- Exporter round-trip: writing a canonical UPPER marker line into plain-text/Proclaim exports means a re-import of that file goes through the detector (commit 10) — if commit 13 lands without commit 10 the marker would become a fake refrain again; ordering in the plan prevents this, a cherry-pick would not.
---

# Design pass 1

## Summary
Data-model pass for voice parts, echo, rounds/canon, word grain and the backfill review queue. Verdict on the #1137 trio: sufficient AS-IS for line- and word-grain voice parts (MP-0120 walks cleanly into 14 join rows, no ALTER needed) — but it needs the app-side vocabulary map it never got, a version-resolver fix (two resolvers disagree today), and the deletion snapshot extended so voice rows can't cascade away silently. Echo is BOTH shapes with a hard boundary: whole-line echo = IsBackground on the existing line row; sub-line echo = a NEW code-point span table (curator versions have no tblLyricWords rows, so word grain cannot carry it). Rounds/canon = TWO new line-anchored tables (a round row + one row per voice, entry offsets in lines AND beats AND ms, per-voice own-span for partner songs, coda span, ending mode) — anchored on tblLyricLines.Id, never tblSongComponents.Id, because component Ids are re-minted by a classic save / Paste & Reflow. Backfill = a shared pure detection core + a NEW per-line suggestions table (VARCHAR confidence, not the grandfathered ENUM) that #1260's triage consumes too. ONE migration creates four tables; nothing existing is ALTERed. Word grain: the trio is sufficient, but the TTML parser today never reads <head> agent definitions and has two defects that drop per-word agents — spec'd fixes included. The pass also pins the fixes for #2071/#2072/#2075 at the data-contract level (Note/MetaJson preserve-on-omit; OpenLyrics part/repeat capture; importers stop fabricating refrains).

## Spec
## 0 — Answers to the five questions

**1. Voice parts.** The #1137 trio is sufficient as stored; NO additive columns. MP-0120 Verse 1 walk (lines L1..L14 in `tblLyricLines`, all `ComponentId = <verse 1>`, version = the song's `Source='ihymns'` `tblLyrics` row, Id = V):

```
tblVocalParts:  (Id=10, LyricsId=V, PartKind='female', Label='Women', Source='ihymns', SortOrder=0)
                (Id=11, LyricsId=V, PartKind='male',   Label='Men',   Source='ihymns', SortOrder=1)
                (Id=12, LyricsId=V, PartKind='all',    Label='All',   Source='ihymns', SortOrder=2)
tblLyricLineVocalParts (LineId, VocalPartId, LyricsId=V, IsBackground=0, SortOrder=0):
  (L1,10) (L2,10) (L3,11) (L4,11) (L5,10) (L6,10) (L7,11) (L8,11) (L9,12) (L10,12) (L11,12) (L12,12) (L13,12) (L14,12)
```
The "WOMEN" header shown once per run is DERIVED at render: `runStart = (partSet(line) !== partSet(prevLine))`. A curator-authored song gets its `tblLyrics` row from `lyricLinesEnsurePrimaryVersion()` (`lyric_lines_sync.php:134`, keyed `Source='ihymns'`, created on the first `lyricLinesApplyDesired()`), so `tblVocalParts.LyricsId` = that row — correct: parts belong to the text version they annotate, and a TTML version of the same song keeps its own agents. `uq_Line_Part (LineId, VocalPartId)` forbids only "the same part twice on one line" — harmless. `SortOrder` on the join row (confirmed present) orders multiple parts on one line.

**2. Echo.** (c) both — §3 rule table. Whole-line → `tblLyricLineVocalParts.IsBackground=1`; sub-line → NEW `tblLyricLineVocalSpans` (code points). A full-width span is rejected.

**3. Rounds.** NEW `tblLyricRounds` + `tblLyricRoundVoices` (§2.3/§2.4), pure timeline (§8).

**4. Word grain.** `tblLyricWordVocalParts` is sufficient. `tblLyricWords` (Id BIGINT, LineId FK CASCADE, SortOrder, WordText, StartTimeMs, EndTimeMs, MetaJson) and `tblLyricSyllables` (WordId FK) exist in schema.sql; `lyricsIngest_writeToDb()` (`lyrics_ingest.php:273`) writes them inside one transaction at :363/:367 from `lyricsIngest_parseTtml()`'s `lines[].words[].syllables[]`. Only TTML versions have words; curator versions never do. Parser + writer changes in §6.

**5. iLyricsDB.** §11.

---

## 1 — Vocabularies + the ONE cores (new files)

### 1.1 `appWeb/public_html/includes/vocal_parts.php` (NEW — the ONE voice-part core, rule #22)

```php
const IHYMNS_VOCAL_PART_KINDS = [   // key order = picker order; VARCHAR(30) app-validated, never ENUM
  'lead'         => ['Lead',         'The main voice'],
  'main'         => ['Main',         'Alias of lead kept for TTML round-trip'],
  'backing'      => ['Backing',      'Background / echo voice with no named group'],
  'soloist'      => ['Soloist',      'One singer'],
  'named-singer' => ['Named singer', 'A specific person (see Musician / Singer name)'],
  'male'         => ['Men',          'Men sing'],
  'female'       => ['Women',        'Women sing'],
  'children'     => ['Children',     'Children / boys / girls sing'],
  'all'          => ['All',          'Everyone sings'],
  'unison'       => ['Unison',       'All voices on one line'],
  'duet'         => ['Duet',         'Two voices'],
  'group'        => ['Group',        'An unnamed group (use Label: "Group 2")'],
  'choir'        => ['Choir',        'The choir'],
  'congregation' => ['Congregation', 'The congregation'],
  'cantor'       => ['Cantor',       'Leader / cantor line'],
  'descant'      => ['Descant',      'Descant above the melody'],
  'soprano'      => ['Soprano',      'SATB section'],
  'alto'         => ['Alto',         'SATB section'],
  'tenor'        => ['Tenor',        'SATB section'],
  'bass'         => ['Bass',         'SATB section'],
  'narrator'     => ['Narrator',     'Spoken narration'],
  'spoken'       => ['Spoken',       'Spoken, not sung'],
];
const IHYMNS_VOCAL_GENDERS = ['male', 'female', 'neutral'];
const IHYMNS_VOCAL_SOURCES_STRUCTURED = ['applemusic-ttml', 'openlyrics', 'propresenter7'];  // sources whose voice signal is applied directly, not queued

function vocalPartsTablesReady(\mysqli $db): bool;   // memoised INFORMATION_SCHEMA count>=3 of tblVocalParts,tblLyricLineVocalParts,tblLyricWordVocalParts; same catch posture as lineEnrichmentTablesReady()
function vocalPartsSpansReady(\mysqli $db): bool;    // tblLyricLineVocalSpans present
function vocalPartsNormalizeKind(string $kind): ?string;                 // lower/trim, null when not a key
function vocalPartsDisplayLabel(array $part): string;                    // Label ?? SingerName ?? IHYMNS_VOCAL_PART_KINDS[kind][0]
function vocalPartsShape(array $row): array;   // {id:int, kind:string, label:?string, displayLabel:string, singerName:?string, gender:?string, musicianId:?int, ttmlAgentId:?string, source:string, sortOrder:int}
function vocalPartsForVersion(\mysqli $db, int $lyricsId): array;        // list<shape> ORDER BY SortOrder, Id
function vocalPartsFindOrCreate(\mysqli $db, int $lyricsId, string $kind, ?string $label, string $source = 'ihymns', ?string $ttmlAgentId = null, ?array $meta = null): int;
   // match order: (LyricsId, TtmlAgentId) when $ttmlAgentId !== null; else (LyricsId, PartKind, LOWER(TRIM(Label)) [NULL-safe <=>]); else INSERT. Never overwrites an existing row's Label.
function vocalPartsUpsert(\mysqli $db, string $songId, array $input, ?int $userId): array;   // {id?, kind, label?, singerName?, gender?, musicianId?, sortOrder?} on the 'ihymns' version (lyricLinesEnsurePrimaryVersion). Throws InvalidArgumentException (kind/gender not in map, label>120, musicianId not in tblMusicians) → 400; RuntimeException (id not owned by song) → 404
function vocalPartsDelete(\mysqli $db, string $songId, int $partId): bool;    // CASCADE clears line/word/span rows + SET NULL on round voices
function vocalPartsAssignLines(\mysqli $db, string $songId, array $lineIds, array $partIds, bool $replace = true, bool $isBackground = false): array;
   // every lineId must resolve via lineEnrichmentResolveLine()-style ownership check to the 'ihymns' version → else RuntimeException(404). $replace: DELETE the lines' existing rows first (the editor's "set voice" gesture); false = add (duet). SortOrder = index in $partIds. LyricsId derived from the line (never the caller). Returns vocalPartsLinesMap() for the touched lines.
function vocalPartsClearLines(\mysqli $db, string $songId, array $lineIds): int;
function vocalPartsSpanUpsert(\mysqli $db, string $songId, array $input): array;   // {id?, lineId, partId, start, end, isBackground?, sortOrder?}; validates 0 <= start < end <= cpLen (mb_strlen) and REJECTS start===0 && end===cpLen ('whole line: use the line assignment') → InvalidArgumentException 400
function vocalPartsSpanDelete(\mysqli $db, string $songId, int $spanId): bool;
function vocalPartsAssignWords(\mysqli $db, int $lyricsId, array $wordIds, int $partId, bool $isBackground): int;   // ingest only (words exist only on ingested versions); INSERT IGNORE on uq_Word_Part
function vocalPartsLinesMap(\mysqli $db, int $lyricsId): array;   // lineId => list<{id,kind,label,bg:bool}> ORDER BY SortOrder,Id (one query, JOIN tblVocalParts)
function vocalPartsSpansMap(\mysqli $db, int $lyricsId): array;   // lineId => list<{id,spanId,kind,label,bg,start,end}> ORDER BY StartOffset,SortOrder
function vocalPartsWordsMap(\mysqli $db, int $lyricsId): array;   // wordId => list<{id,kind,label,bg}>
function vocalPartsVersionHasAny(\mysqli $db, int $lyricsId): bool;   // SELECT 1 … LIMIT 1 over the line table (cheap gate the public assembler uses so empty-corpus reads stay byte-identical)
function vocalPartsForSong(\mysqli $db, string $songId): array;
   // {parts: list<shape>, lines: {lineId: list}, spans: {lineId: list}, rounds: list<roundShape>} for lyricLinesPrimaryLyricsId(); every key [] when un-migrated. THE one payload editor load + api song_detail + song.php consume.
```

### 1.2 `appWeb/public_html/includes/lyric_rounds.php` (NEW — the ONE rounds core)

```php
const IHYMNS_ROUND_KINDS   = ['round', 'canon', 'partner-song'];
const IHYMNS_ROUND_ENDINGS = ['complete', 'together', 'coda'];
function lyricRoundsReady(\mysqli $db): bool;                        // both round tables present
function lyricRoundsForVersion(\mysqli $db, int $lyricsId): array;   // list<roundShape>, voices nested, ORDER BY SortOrder,Id
function lyricRoundShape(array $round, array $voices): array;
  // {id, kind, label, startLineId, endLineId, componentId, repeats:?int, endingMode, codaStartLineId:?int, codaEndLineId:?int, bpm:?float, beatsPerBar:?int, beatsPerLine:?float, source, sortOrder,
  //  voices: list<{id, number:int, partId:?int, part:?{id,kind,label,displayLabel}, label:?string, displayLabel:string, entryLines:int, entryBeats:?float, entryMs:?int, intervalSemitones:?int, startLineId:?int, endLineId:?int, repeats:?int, sortOrder}>}
function lyricRoundUpsert(\mysqli $db, string $songId, array $input, ?int $userId): array;
  // input = roundShape minus derived fields; voices REPLACED as a set (delete-missing, upsert by number). Validation → InvalidArgumentException(400): kind/endingMode not in map; start/end lines not both on the 'ihymns' version; EndLineId SortOrder < StartLineId SortOrder; voices empty; VoiceNumber not 1..N contiguous; voice 1 entryLines !== 0; any own span not both-or-neither; coda span present iff endingMode==='coda'; bpm/beats <= 0. Ownership → RuntimeException(404).
function lyricRoundDelete(\mysqli $db, string $songId, int $roundId): bool;
function lyricRoundSubjectLineIds(array $round, array $orderedLineIds): array;          // PURE: slice of the version's ordered lineIds from start..end inclusive; [] when either id is missing (line deleted → the round row is already gone by CASCADE, but the shape helper stays total)
function lyricRoundTimeline(array $round, array $subjectLineIds, ?array $lineTimingsMs = null): array;   // PURE — §8
```

### 1.3 `appWeb/public_html/includes/vocal_part_detect.php` (NEW — PURE detection core, no DB; #1260 consumes it too)

```php
const IHYMNS_VOCAL_DETECT_VERSION = '1';
const IHYMNS_VOCAL_MARKER_MAP = [   // UPPER-CASE marker word/phrase → [kind, label]. Multi-word keys allowed. Growable — add here, never inline.
  'MEN'=>['male','Men'], 'MAN'=>['male','Men'], 'GENTLEMEN'=>['male','Men'], 'BOYS'=>['children','Boys'], 'GIRLS'=>['children','Girls'], 'CHILDREN'=>['children','Children'],
  'WOMEN'=>['female','Women'], 'WOMAN'=>['female','Women'], 'LADIES'=>['female','Women'],
  'ALL'=>['all','All'], 'EVERYONE'=>['all','All'], 'TOGETHER'=>['all','All'], 'TUTTI'=>['all','All'], 'UNISON'=>['unison','Unison'],
  'CHOIR'=>['choir','Choir'], 'CONGREGATION'=>['congregation','Congregation'], 'PEOPLE'=>['congregation','People'],
  'LEADER'=>['cantor','Leader'], 'CANTOR'=>['cantor','Cantor'], 'MINISTER'=>['cantor','Minister'], 'SOLO'=>['soloist','Solo'], 'SOLOIST'=>['soloist','Soloist'],
  'SOPRANO'=>['soprano','Soprano'], 'SOPRANOS'=>['soprano','Sopranos'], 'ALTO'=>['alto','Alto'], 'ALTOS'=>['alto','Altos'], 'TENOR'=>['tenor','Tenor'], 'TENORS'=>['tenor','Tenors'], 'BASS'=>['bass','Bass'], 'BASSES'=>['bass','Basses'],
  'GROUP 1'=>['group','Group 1'], 'GROUP 2'=>['group','Group 2'], 'GROUP 3'=>['group','Group 3'], 'GROUP 4'=>['group','Group 4'],
  'FIRST'=>['group','Group 1'], 'SECOND'=>['group','Group 2'], '1ST'=>['group','Group 1'], '2ND'=>['group','Group 2'],
  'ECHO'=>['backing','Echo'], 'RESPONSE'=>['backing','Response'],
];
const IHYMNS_VOCAL_ECHO_DIRECTION_WORDS = ['repeat','twice','x2','x3','×','times','verse','chorus','refrain','instrumental','optional','softly','louder','slowly','faster','last time','first time','to verse','to chorus','d.s.','d.c.','fine','spoken','tag','end','key change','modulate','bridge','interlude','hum','clap','pause'];
const IHYMNS_VOCAL_SUGGESTION_SIGNALS = ['marker-line','marker-prefix','echo-paren','round-direction','ttml-agent','openlyrics-part'];
const IHYMNS_VOCAL_SUGGESTION_ACTIONS = ['assign-lines','strip-marker-line','strip-prefix','mark-echo-line','mark-echo-span','create-round'];
const IHYMNS_VOCAL_SUGGESTION_CONFIDENCES = ['high','medium','low'];
const IHYMNS_VOCAL_SUGGESTION_STATUSES = ['pending','applied','dismissed','superseded'];

function vocalPartDetectMarker(string $line): ?array;
  // Returns null or {marker:string, kind:string, label:string, remainder:?string, shape:'line'|'prefix'}.
  // 'line'   : /^\s*(?<m>[A-Z][A-Z0-9 .&\/\-]{0,30}?)\s*:?\s*$/u  where UPPER(m) ∈ MARKER_MAP (after collapsing runs of [ .]) — a standalone marker line.
  // 'prefix' : /^\s*(?<m>[A-Z][A-Z0-9 .&\/\-]{0,30}?)[:\s\x{00A0}]*[\x{00A0}\t]+[\s\x{00A0}]*(?<rest>\S.*)$/u OR marker followed by ':' then text — the NBSP-run shape ("MEN    You are holy,"). \x{00A0} MUST be listed explicitly: PHP /u is UTF mode WITHOUT UCP, so \s does not match U+00A0. remainder = rest with leading [\s\x{00A0}]+ stripped, code-point safe.
  // Never matches a line whose UPPER form is not in the map (an all-caps lyric line like "HALLELUJAH" is not a marker).
function vocalPartDetectEcho(string $line): ?array;
  // Whole-line: /^\s*[(\[](?<inner>[^()\[\]]{2,})[)\]]\s*$/u → {shape:'line', inner} unless any IHYMNS_VOCAL_ECHO_DIRECTION_WORDS matches inner case-insensitively (→ null: it is a direction, not an echo) or inner contains a digit-only token.
  // Sub-span: a parenthesised phrase at the END of a line whose inner text (case/punct-folded) equals the trailing words of the text before it, or equals a preceding line passed as $prev (2nd param, optional) → {shape:'span', start, end (code points, mb_strlen/mb_substr), inner}. Anything else → null.
function vocalPartDetectRoundDirection(string $line): ?array;
  // /\b(IN\s+CANON|AS\s+A\s+ROUND|SUNG\s+AS\s+A\s+ROUND|ROUND\s+IN\s+(\d)\s+PARTS?|(\d)[- ]PART\s+(ROUND|CANON))\b/iu → {kind:'canon'|'round', voices:int|null, marker}. Voice count from the digit, else null; a preceding "MEN AND WOMEN" (any two MARKER_MAP words joined by AND/&) → parts:[kind,kind].
function vocalPartDetectLines(array $lines, ?array $lineIds = null): array;
  // THE ONE entry point. Walks a component's lines in order and returns list<finding> where finding =
  // {index:int, lineId:?int, signal, confidence, marker, action, kind:?string, label:?string, proposed:array}
  // Rules: a 'marker-line' finding governs every FOLLOWING line until the next marker-line/prefix or end of component → proposed = {lineIds:[…]} + action 'strip-marker-line' is emitted as a SECOND finding on the same line (Signal differs, so uq_Line_Signal holds). 'marker-prefix' → action 'strip-prefix', proposed={remainder}, plus 'assign-lines' for THAT line only unless the next lines are un-marked (then the run rule applies). Confidence: marker-line/prefix 'high' when kind ∈ {male,female,all,choir,congregation,children}, 'medium' otherwise; echo-paren 'low' ('medium' when the inner text repeats adjacent words); round-direction 'medium'.
function vocalPartDetectVersion(): string;   // IHYMNS_VOCAL_DETECT_VERSION
```

### 1.4 `appWeb/public_html/includes/vocal_part_suggestions.php` (NEW — queue core, DB)

```php
function vocalSuggestionsReady(\mysqli $db): bool;
function vocalSuggestionsRunForSong(\mysqli $db, string $songId, bool $dryRun = false): array;
  // reads lyricLinesAssembleComponents() (lineIds!) for the 'ihymns' version, calls vocalPartDetectLines() per component, INSERT … ON DUPLICATE KEY UPDATE (LineId,Signal) refreshing MarkerText/Proposed*/Confidence/DetectorVersion ONLY while Status='pending'; marks 'superseded' any pending row for this song whose (LineId,Signal) was not re-found. Returns {found:int, inserted:int, updated:int, superseded:int, findings:list} (findings only when $dryRun).
function vocalSuggestionsList(\mysqli $db, array $filter, int $limit, int $offset): array;   // filter: status, signal, confidence, songbook (SongId LIKE 'ABBR-%' bound), songId
function vocalSuggestionsApply(\mysqli $db, int $suggestionId, ?int $userId, array $overrides = []): array;
  // RE-VALIDATES: re-runs the matching detector on the live LineText; if the marker is no longer there → Status='superseded', RuntimeException('stale') → 409 {reason:'stale'}. Then by ProposedAction:
  //  assign-lines      → vocalPartsFindOrCreate(kind,label,'backfill-detect') + vocalPartsAssignLines(replace=true)
  //  strip-marker-line → lyricLinesWriteComponents() with the component's lines minus that line (the ONE write path; lineIds re-read after) — ONLY after its paired assign-lines row for the same LineId is applied or dismissed (the marker's text is the only evidence; the guard is "sibling pending? → 409 {reason:'sibling_pending'}")
  //  strip-prefix      → same write path with lines[i] = remainder
  //  mark-echo-line    → vocalPartsAssignLines([lineId], [backingPartId], replace=false, isBackground=true)
  //  mark-echo-span    → vocalPartsSpanUpsert()
  //  create-round      → lyricRoundUpsert() from proposed.voices with entryLines = subject length / voices (rounded down, min 1) — curator adjusts after
  // then Status='applied', ReviewedBy/At. $overrides may replace kind/label/partId (curator picked a different part in the review UI).
function vocalSuggestionsDismiss(\mysqli $db, int $suggestionId, ?int $userId): bool;
```

### 1.5 Shared resolver (edit to `lyric_lines_read.php`)

```php
function lyricLinesPrimaryLyricsId(\mysqli $db, string $songId): int;   // SELECT Id FROM tblLyrics WHERE SongId=? AND Source='ihymns' LIMIT 1 → int, 0 when absent. Memoised per request in a static map keyed by songId.
```
`SongData::_primaryLyricsId()` becomes: `$id = lyricLinesPrimaryLyricsId($this->db, $songId); if ($id > 0) return $id; /* fallback: existing rule */`.

---

## 2 — DDL (one migration; four tables; zero ALTERs)

Exact column/index/FK text below is used VERBATIM in both `appWeb/.sql/migrate-vocal-parts-rounds.php` (`CREATE TABLE` after an INFORMATION_SCHEMA probe) and `appWeb/.sql/schema.sql` (`CREATE TABLE IF NOT EXISTS`, appended directly AFTER the `tblLyricWordVocalParts` block in the `-- VOCAL / SINGING PARTS (#1137)` section at schema.sql:4497+, under a new comment header `-- VOICE SPANS, ROUNDS/CANON + BACKFILL SUGGESTIONS (#2073) — line-anchored …`). Feature issue = #2073 (the entry-research issue #2075 cites; confirm the number when filing the epic).

### 2.1 tblLyricLineVocalSpans
```sql
CREATE TABLE IF NOT EXISTS tblLyricLineVocalSpans (
    Id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    LineId       BIGINT UNSIGNED NOT NULL COMMENT 'FK to tblLyricLines.Id — the line this sub-span sits in (rule #21 anchor)',
    VocalPartId  INT UNSIGNED    NOT NULL COMMENT 'FK to tblVocalParts.Id — who sings this span',
    LyricsId     INT UNSIGNED    NOT NULL COMMENT 'Denorm of tblLyricLines.LyricsId; app derives from the line, never the caller',
    StartOffset  INT UNSIGNED    NOT NULL COMMENT '0-based UTF-8 code-point index into LineText where the span BEGINS (inclusive). Code points, never bytes/UTF-16 (rule #21)',
    EndOffset    INT UNSIGNED    NOT NULL COMMENT '0-based EXCLUSIVE code-point index where the span ENDS. A span covering the whole line is NOT stored here — that is a tblLyricLineVocalParts row (one representation per case, app-enforced)',
    IsBackground TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '1 = this span is an ECHO / background vocal of the surrounding text (the "(he is my refuge)" case; TTML ttm:role=x-bg on a sub-span). 0 = a mid-line voice switch',
    SortOrder    INT UNSIGNED    NOT NULL DEFAULT 0,
    Source       VARCHAR(100)    NOT NULL DEFAULT 'ihymns' COMMENT 'Provenance (mirrors tblLyrics.Source): ihymns | openlyrics | backfill-detect | … VARCHAR not ENUM',
    MetaJson     JSON            NULL DEFAULT NULL COMMENT 'Lossless source attrs (the original bracketed text, TTML span attrs)',
    CreatedAt    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_Line_Part_Start (LineId, VocalPartId, StartOffset),
    INDEX idx_Lyrics (LyricsId),
    INDEX idx_Line   (LineId, StartOffset),
    INDEX idx_Part   (VocalPartId),

    CONSTRAINT fk_LineVS_Line
        FOREIGN KEY (LineId)      REFERENCES tblLyricLines(Id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_LineVS_Part
        FOREIGN KEY (VocalPartId) REFERENCES tblVocalParts(Id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_LineVS_Lyrics
        FOREIGN KEY (LyricsId)    REFERENCES tblLyrics(Id)     ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Sub-line (code-point span) vocal-part assignment — echo phrases and mid-line voice switches (#2073).';
```

### 2.2 tblLyricRounds
```sql
CREATE TABLE IF NOT EXISTS tblLyricRounds (
    Id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    LyricsId        INT UNSIGNED    NOT NULL COMMENT 'FK to tblLyrics.Id — rounds are per lyrics version, like tblVocalParts',
    StartLineId     BIGINT UNSIGNED NOT NULL COMMENT 'FK to tblLyricLines.Id — first line of the passage every voice sings (the round SUBJECT). Line-anchored, not component-anchored: component Ids are re-minted by a classic save / Paste & Reflow, line Ids survive the Id-preserving diff (rule #25)',
    EndLineId       BIGINT UNSIGNED NOT NULL COMMENT 'FK to tblLyricLines.Id — last line of the subject (inclusive). A whole-song round spans first..last line',
    ComponentId     INT UNSIGNED    NULL DEFAULT NULL COMMENT 'Soft hint (NO FK, mirrors tblLyricLines.ComponentId): the section this round was authored on; display/debug only, never authoritative',
    RoundKind       VARCHAR(20)     NOT NULL DEFAULT 'round' COMMENT 'round | canon | partner-song (app-validated vs IHYMNS_ROUND_KINDS). VARCHAR not ENUM (rule #20)',
    Label           VARCHAR(120)    NULL DEFAULT NULL COMMENT 'Display name, e.g. "Sung as a round in 3 parts"',
    Repeats         INT UNSIGNED    NULL DEFAULT NULL COMMENT 'How many times the LEADER voice sings the subject through. NULL = unspecified (leader decides live)',
    EndingMode      VARCHAR(20)     NOT NULL DEFAULT 'complete' COMMENT 'complete = each voice finishes its own last cycle (staggered end) | together = every voice stops when the leader finishes | coda = every voice jumps to the coda span on the leader''s last line (app-validated vs IHYMNS_ROUND_ENDINGS). VARCHAR not ENUM',
    CodaStartLineId BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'FK to tblLyricLines.Id — first line of the coda sung together at the end (EndingMode=coda). NULL = no coda',
    CodaEndLineId   BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'FK to tblLyricLines.Id — last line of the coda (inclusive). NULL = single-line coda ending on CodaStartLineId',
    BeatsPerMinute  DECIMAL(6,2)    NULL DEFAULT NULL COMMENT 'Tempo for timed playback when the lines carry no StartTimeMs/EndTimeMs. NULL = untimed (projection still works in LINE units)',
    BeatsPerBar     TINYINT UNSIGNED NULL DEFAULT NULL COMMENT 'Time-signature numerator for bar<->beat conversion (4 for 4/4). NULL = unknown',
    BeatsPerLine    DECIMAL(6,2)    NULL DEFAULT NULL COMMENT 'Uniform beats per subject line for beat->time derivation; irregular per-line values go in MetaJson.lineBeats[]. NULL = derive from line timing or fall back to LINE units',
    Source          VARCHAR(100)    NOT NULL DEFAULT 'ihymns' COMMENT 'Provenance (mirrors tblLyrics.Source): ihymns | backfill-detect | … VARCHAR not ENUM',
    SourceRef       VARCHAR(190)    NULL DEFAULT NULL COMMENT 'External id from Source for idempotent re-import. NULL for manual (multiple NULLs coexist under the UNIQUE)',
    SortOrder       INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT 'Order when a version carries several rounds',
    MetaJson        JSON            NULL DEFAULT NULL COMMENT 'Lossless extras: the source direction text ("MEN AND WOMEN IN CANON"), lineBeats[], future per-round playback hints',
    CreatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_SourceRef  (Source, SourceRef),
    INDEX idx_Lyrics    (LyricsId, SortOrder),
    INDEX idx_StartLine (StartLineId),
    INDEX idx_EndLine   (EndLineId),
    INDEX idx_CodaStart (CodaStartLineId),
    INDEX idx_CodaEnd   (CodaEndLineId),

    CONSTRAINT fk_Rounds_Lyrics
        FOREIGN KEY (LyricsId)        REFERENCES tblLyrics(Id)     ON DELETE CASCADE  ON UPDATE CASCADE,
    CONSTRAINT fk_Rounds_StartLine
        FOREIGN KEY (StartLineId)     REFERENCES tblLyricLines(Id) ON DELETE CASCADE  ON UPDATE CASCADE,
    CONSTRAINT fk_Rounds_EndLine
        FOREIGN KEY (EndLineId)       REFERENCES tblLyricLines(Id) ON DELETE CASCADE  ON UPDATE CASCADE,
    CONSTRAINT fk_Rounds_CodaStart
        FOREIGN KEY (CodaStartLineId) REFERENCES tblLyricLines(Id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_Rounds_CodaEnd
        FOREIGN KEY (CodaEndLineId)   REFERENCES tblLyricLines(Id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Round / canon / partner-song definition over a line span; voices in tblLyricRoundVoices (#2073).';
```

### 2.3 tblLyricRoundVoices
```sql
CREATE TABLE IF NOT EXISTS tblLyricRoundVoices (
    Id                INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    RoundId           INT UNSIGNED    NOT NULL COMMENT 'FK to tblLyricRounds.Id',
    VoiceNumber       TINYINT UNSIGNED NOT NULL COMMENT '1-based entry order: voice 1 is the LEADER (enters first; every offset is measured from it). Part of the unique key — a 4-voice round is four rows, never four columns',
    VocalPartId       INT UNSIGNED    NULL DEFAULT NULL COMMENT 'FK to tblVocalParts.Id — WHO sings this voice (men/women/choir/a named singer). NULL = an unnamed group ("Group 2")',
    Label             VARCHAR(120)    NULL DEFAULT NULL COMMENT 'Display override ("Group 2", "Left side"). NULL = derive from the vocal part, else "Voice N"',
    EntryOffsetLines  INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT 'STRUCTURAL entry: how many subject LINES behind the leader this voice starts (0 for the leader). The one unit projection always has — a projector shows voice N this many lines behind voice 1 with no timing at all',
    EntryOffsetBeats  DECIMAL(8,3)    NULL DEFAULT NULL COMMENT 'MUSICAL entry: beats behind the leader (bars x BeatsPerBar + beats) for an entry that does not fall on a line boundary. NULL = use EntryOffsetLines',
    EntryOffsetMs     INT UNSIGNED    NULL DEFAULT NULL COMMENT 'TIMED entry: milliseconds behind the leader for synchronised playback against line/word timing or a recording. NULL = derive from beats x tempo, else from lines x line timing, else untimed',
    IntervalSemitones TINYINT         NULL DEFAULT NULL COMMENT 'Pitch interval of this voice relative to the leader (a canon at the fifth = 7; 0 or NULL = at the unison). Display + future transpose hint only',
    VoiceStartLineId  BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'FK to tblLyricLines.Id — OWN subject start when this voice sings DIFFERENT text (partner song / quodlibet). NULL = inherit the round''s StartLineId..EndLineId',
    VoiceEndLineId    BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'FK to tblLyricLines.Id — own subject end (inclusive). NULL = inherit',
    Repeats           INT UNSIGNED    NULL DEFAULT NULL COMMENT 'Per-voice override of the round''s Repeats (a late voice that sings one cycle fewer under EndingMode=together). NULL = inherit',
    SortOrder         INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT 'Display lane order (defaults to VoiceNumber)',
    MetaJson          JSON            NULL DEFAULT NULL,
    CreatedAt         TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt         TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_Round_Voice (RoundId, VoiceNumber),
    INDEX idx_Part       (VocalPartId),
    INDEX idx_VoiceStart (VoiceStartLineId),
    INDEX idx_VoiceEnd   (VoiceEndLineId),

    CONSTRAINT fk_RoundVoices_Round
        FOREIGN KEY (RoundId)          REFERENCES tblLyricRounds(Id) ON DELETE CASCADE  ON UPDATE CASCADE,
    CONSTRAINT fk_RoundVoices_Part
        FOREIGN KEY (VocalPartId)      REFERENCES tblVocalParts(Id)  ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_RoundVoices_VStart
        FOREIGN KEY (VoiceStartLineId) REFERENCES tblLyricLines(Id)  ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_RoundVoices_VEnd
        FOREIGN KEY (VoiceEndLineId)   REFERENCES tblLyricLines(Id)  ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='One row per voice of a round: who sings it and where it enters, in lines / beats / ms (#2073).';
```

### 2.4 tblVocalPartSuggestions
```sql
CREATE TABLE IF NOT EXISTS tblVocalPartSuggestions (
    Id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    SongId           VARCHAR(20)     NOT NULL COMMENT 'FK to tblSongs.SongId — denorm for queue filtering by songbook',
    LyricsId         INT UNSIGNED    NOT NULL COMMENT 'FK to tblLyrics.Id — the version the finding is about',
    LineId           BIGINT UNSIGNED NOT NULL COMMENT 'FK to tblLyricLines.Id — the line the marker / echo / direction was found ON',
    `Signal`         VARCHAR(50)     NOT NULL COMMENT 'Detection method: marker-line | marker-prefix | echo-paren | round-direction | ttml-agent | openlyrics-part (app-validated vs IHYMNS_VOCAL_SUGGESTION_SIGNALS). VARCHAR not ENUM. Backtick-quoted — SIGNAL is reserved in MySQL 8',
    Confidence       VARCHAR(10)     NOT NULL DEFAULT 'low' COMMENT 'high | medium | low triage tier (app-validated vs IHYMNS_VOCAL_SUGGESTION_CONFIDENCES). VARCHAR — deliberately NOT the ENUM tblSongLinkSuggestions grandfathered',
    MarkerText       VARCHAR(255)    NULL DEFAULT NULL COMMENT 'The literal marker as found ("MEN", "(he is my refuge)", "MEN AND WOMEN IN CANON")',
    ProposedAction   VARCHAR(30)     NOT NULL COMMENT 'assign-lines | strip-marker-line | strip-prefix | mark-echo-line | mark-echo-span | create-round (app-validated vs IHYMNS_VOCAL_SUGGESTION_ACTIONS)',
    ProposedPartKind VARCHAR(30)     NULL DEFAULT NULL COMMENT 'tblVocalParts.PartKind the marker maps to (IHYMNS_VOCAL_MARKER_MAP)',
    ProposedLabel    VARCHAR(120)    NULL DEFAULT NULL,
    ProposedJson     JSON            NULL DEFAULT NULL COMMENT 'Action detail the apply step needs: {lineIds:[…]} for assign-lines (the run this marker governs), {start,end} code-point span for mark-echo-span, {remainder} the lyric left after a prefix strip, {voices:[…]} for create-round',
    Status           VARCHAR(20)     NOT NULL DEFAULT 'pending' COMMENT 'pending | applied | dismissed | superseded (app-validated). VARCHAR not ENUM',
    ReviewedBy       INT UNSIGNED    NULL DEFAULT NULL COMMENT 'FK to tblUsers.Id',
    ReviewedAt       DATETIME        NULL DEFAULT NULL,
    DetectorVersion  VARCHAR(20)     NOT NULL DEFAULT '1' COMMENT 'vocalPartDetectVersion() when this row was written — a re-run with a newer detector supersedes stale pending rows',
    CreatedAt        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_Line_Signal (LineId, `Signal`),
    INDEX idx_Song        (SongId),
    INDEX idx_Lyrics      (LyricsId),
    INDEX idx_Status_Conf (Status, Confidence),
    INDEX idx_Signal      (`Signal`),

    CONSTRAINT fk_VPSugg_Song
        FOREIGN KEY (SongId)     REFERENCES tblSongs(SongId)   ON DELETE CASCADE  ON UPDATE CASCADE,
    CONSTRAINT fk_VPSugg_Lyrics
        FOREIGN KEY (LyricsId)   REFERENCES tblLyrics(Id)      ON DELETE CASCADE  ON UPDATE CASCADE,
    CONSTRAINT fk_VPSugg_Line
        FOREIGN KEY (LineId)     REFERENCES tblLyricLines(Id)  ON DELETE CASCADE  ON UPDATE CASCADE,
    CONSTRAINT fk_VPSugg_Reviewer
        FOREIGN KEY (ReviewedBy) REFERENCES tblUsers(Id)       ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Curator review queue for detected voice markers / echoes / round directions — the D4 backfill queue, also consumed by the #1260 triage (#2073).';
```

### 2.5 Migration `appWeb/.sql/migrate-vocal-parts-rounds.php`
Same skeleton as `migrate-vocal-parts.php` (own mysqli connection from `.auth/db_credentials.php`, `$isCli`/`IHYMNS_SETUP_DASHBOARD` header guard, `return` not `exit`, catch prints `[ERROR]`), but the probe is `INFORMATION_SCHEMA.TABLES` + `bind_param` (`_migVPR_tableExists`), not `SHOW TABLES LIKE`. Pre-flight: if `tblVocalParts`, `tblLyricLines` or `tblMusicians` is absent → print `[ERROR] Run the "Vocal / singing parts (#1137)" card first.` and `return`. Then four independent guarded `CREATE TABLE` blocks in the order 2.1 → 2.2 → 2.3 → 2.4 (2.3 FKs 2.2). No `require` of any docroot file (rule #41 is moot — pure DDL; say so in the doc-block like migrate-add-component-label.php:42 does). ALSO in this same file's doc-block: nothing. SEPARATE commit in the same PR: align the three drifted COMMENTs in `migrate-vocal-parts.php` to schema.sql's text (PartKind, Gender, MetaJson).

### 2.6 Registry entry (`manage/includes/migration-registry.php`, directly after `'vocal-parts'`)
```php
'vocal-parts-rounds' => [
    'script' => 'migrate-vocal-parts-rounds.php',
    'card' => [
        'title'  => 'Voice spans, rounds/canon + backfill queue (#2073)',
        'body'   => 'Creates <code>tblLyricLineVocalSpans</code> (sub-line echo / mid-line voice switch, code-point span),'
                  . ' <code>tblLyricRounds</code> + <code>tblLyricRoundVoices</code> (round / canon definition with per-voice'
                  . ' entry offsets in lines, beats and ms — drives staggered projection) and <code>tblVocalPartSuggestions</code>'
                  . ' (the curator review queue the voice-marker backfill writes into). Additive + idempotent; requires the'
                  . ' Vocal / singing parts (#1137) card. Tables ship empty.',
        'button' => 'Run Voice Spans + Rounds Migration',
    ],
    /* Multi-object OR-probe (rule #19): pending until ALL four objects exist. */
    'probe' => static fn(\mysqli $db): bool =>
           !_migProbe_tableExists($db, 'tblLyricLineVocalSpans')
        || !_migProbe_tableExists($db, 'tblLyricRounds')
        || !_migProbe_tableExists($db, 'tblLyricRoundVoices')
        || !_migProbe_tableExists($db, 'tblVocalPartSuggestions'),
],
```
`$scriptMap`/`$migrationOrder`/`$migrationCards`/`$migrationProbes` are derived from this one entry (rule #19).

---

## 3 — Echo + grain rule table (app-enforced; the schema stores all three)

| Case | Stored as | Written by |
|---|---|---|
| Whole line is an echo, echoing part known | `tblLyricLineVocalParts` (LineId, thatPart, IsBackground=1) — alongside the lead part's row | editor / apply |
| Whole line is an echo, part unknown | `tblLyricLineVocalParts` (LineId, version's `backing` part [`vocalPartsFindOrCreate(kind='backing', label=null)`], IsBackground=1) | apply / TTML (`<p ttm:role="x-bg">` with no agent → part keyed `TtmlAgentId='x-bg'`) |
| Phrase inside a line is an echo | `tblLyricLineVocalSpans` (start,end code points, IsBackground=1) | editor / apply |
| Mid-line voice switch ("MEN: … WOMEN: …" in one line) | `tblLyricLineVocalSpans` rows, IsBackground=0 | editor |
| Per-word agent / x-bg on an INGESTED version | `tblLyricWordVocalParts` | TTML ingest only |
| Full-width span request | REJECTED 400 `{reason:'whole_line'}` | validator |

Read precedence per code point: span > word > line. Offsets validated on write with `mb_strlen($text,'UTF-8')`; on read, a span whose EndOffset > current cpLen (text shortened by a fuzzy-matched edit) is CLAMPED to cpLen and a span with start ≥ cpLen is DROPPED from the shape (same posture annotations use). Client slices with `Array.from(text)` never `.slice()`.

---

## 4 — Read shapes (exact keys)

### 4.1 Public shape — `lyricLinesAssembleFromRows(array $rows, array $extras = [])` (signature widened, second arg defaults `[]` so every existing caller + fixture is byte-identical)
`$extras = ['voicesByLine' => vocalPartsLinesMap(), 'spansByLine' => vocalPartsSpansMap(), 'notesByLine'? no — notes come from the row]`. Component keys, in this order: `type, number, lines, chords, language, [label], lineIds, [lineLanguages], [notes], [lineMeta], [voices], [voiceSpans]` — the four new ones SPARSE (emitted only when at least one cell is non-null in that component). `notes` = list parallel to lines (string|null) from `ll.Note` (row key `line_note`, added to `lyricLinesFetchPrimary()`'s SELECT gated on the existing `Note` probe); `lineMeta` = list of decoded `ll.MetaJson` (object|null); `voices` = list parallel to lines, cell = `null | [{id:int, kind:string, label:string, bg:bool}]` (label = displayLabel); `voiceSpans` = list parallel, cell = `null | [{id:int, spanId:int, kind, label, bg, start:int, end:int}]`. `lyricLinesAssembleComponents()`/`…Map()` fetch the maps only when `vocalPartsTablesReady() && vocalPartsVersionHasAny()` — so `tools/export-fidelity-snapshot.php` and every `test-lyric-lines-read.php` fixture stay byte-identical on today's (empty) tables. ⚠ `notes`/`lineMeta` DO change the fidelity hash for songs the OpenLyrics importer already wrote notes for — that is the #2072 bug fix surfacing; re-baseline the snapshot in the same PR and say so in the commit body.

### 4.2 Song level — `SongData::getSongById()` output gains SPARSE `vocalParts: list<partShape>` and `rounds: list<roundShape>` (only when non-empty; from `vocalPartsForSong()`); `?include=vocalParts` keeps working (now 'ihymns'-aligned via §1.5). `api.php song_detail` carries them through unchanged (it emits `getSongById()`); `contentGatingApply()` must strip `vocalParts`/`rounds`/`voices`/`voiceSpans` together with the lyric body (they are lyric structure) — one line in the existing gated-field list, verified no-op with `content_gating_enabled='0'`.

### 4.3 Editor shape — `lyricLinesEditableComponents()` gains ALWAYS-present `notes` (list, null cells), `lineMeta` (list, null cells), `voices` (list, null cells), `voiceSpans` (list, null cells). `load_song` response gains `vocalParts: {parts:[…], rounds:[…]}` next to `enrichment` (both from the one core), plus `vocalPartsReady:bool`, `roundsReady:bool` (so the panel can disable controls instead of 409-guessing).

### 4.4 `index.php` `$iHymnsConfig` gains `vocalPartKinds: IHYMNS_VOCAL_PART_KINDS`, `roundKinds`, `roundEndings` (the song-markup.js precedent — the client never hardcodes a shadow list).

---

## 5 — Write paths

### 5.1 Editor endpoints (`manage/editor/api2.php`, POST-JSON, `$ed2UserId`, the existing top-level `X-Requested-With` gate unless `$ed2BearerAuthed`, entitlement identical to the sibling `line_translation_*` cases). Every case: `songId` required → 400; `ed2_songExists` → 404; `!vocalPartsTablesReady($db)` → `409 {ok:false, reason:'not_migrated', error:'Vocal-part tables are not migrated.'}` (spans/rounds cases additionally check their own ready-fn); core `InvalidArgumentException` → 400 `{ok:false, reason:<validator reason>, error}`; `RuntimeException` → 404; anything else rethrown. All inside `begin_transaction()/commit()`. `logActivity('song.vocalPart.*', 'song', $songId, {...})`. NO `ed2_touchRevision()` (not component content — same doctrine as translations; the revision snapshot carries no voice rows, restore goes through components_replace whose line diff preserves them).

| action | body | response |
|---|---|---|
| `vocal_part_upsert` | `{songId, part:{id?, kind, label?, singerName?, gender?, musicianId?, sortOrder?}}` | `{ok:true, part}` |
| `vocal_part_delete` | `{songId, id}` | `{ok:true, deleted:0|1}` |
| `line_vocal_assign` | `{songId, lineIds:[int…], partIds:[int…], replace?:bool=true, isBackground?:bool=false}` — any lineId ≤ 0 → 400 `{reason:'line_unsaved'}` | `{ok:true, lines:{lineId:[cell…]}}` |
| `line_vocal_clear` | `{songId, lineIds}` | `{ok:true, cleared:int}` |
| `line_vocal_span_upsert` | `{songId, span:{id?, lineId, partId, start, end, isBackground?, sortOrder?}}` | `{ok:true, span}` |
| `line_vocal_span_delete` | `{songId, id}` | `{ok:true, deleted}` |
| `round_upsert` | `{songId, round:<roundShape input>}` | `{ok:true, round}` |
| `round_delete` | `{songId, id}` | `{ok:true, deleted}` |
| `vocal_suggestions_run` | `{songId}` → `vocalSuggestionsRunForSong()` | `{ok:true, found, inserted, updated, superseded}` |

`api-client.js` (v2) gains one method per action; `unwrap()` already attaches `err.status` — the panel branches on `409 + reason` never on prose.

### 5.2 /api twins (rule #48) — `api.php`: read is already covered by `song_detail`. Review-queue actions (twins of the new `/manage/vocal-part-suggestions.php` page, entitlement `edit_songs`, `getAuthenticatedUser()` + `validateCsrfRequest()`, `sendJson()`): `vocal_suggestion_list` (GET; filters as §1.4), `vocal_suggestion_apply` (POST `{id, overrides?}` → 409 `{reason:'stale'|'sibling_pending'}`), `vocal_suggestion_dismiss` (POST `{id}`), `vocal_suggestion_run` (POST `{songId}`). Add the page's four actions to `tests/php/test-manage-action-api-coverage.php` `$MAPPING` as `'api:vocal_suggestion_*'`. `api-docs.yaml` path items for all thirteen actions in the same change (copy the `editorLineTranslationUpsert` block shape, :22467).

### 5.3 D3 client flow (structure-tab / new vocal panel — data contract only)
`componentLineId(comp,i) === 0` → `await api.upsertComponent(songId, comp)` (existing) → re-read `lineIds` from the response's components → `api.assignLineVocal(...)`. On upsert failure: keep `{compIndex, lineIndex, partIds, bg}` in an in-memory `pendingVoice[]`, render the chip with `data-pending="1"`, toast the SAVE error; flush `pendingVoice[]` after the next `component_upsert` success for that component; discard on component delete. Nothing pending is ever sent with a lineId of 0.

### 5.4 Write-core edits (`includes/lyric_lines_sync.php`)
1. `lyricLinesWriteComponents()` `$norm[]` gains `'notesProvided' => array_key_exists('notes',$c)`, `'lineMeta' => is_array($c['lineMeta']??null) ? array_values($c['lineMeta']) : null`, `'lineMetaProvided' => array_key_exists('lineMeta',$c)`.
2. `lyricLinesBuildDesiredFromComponents()` emits per desired line `'Note'`, `'NotePreserve' => !$c['notesProvided']`, `'MetaJson' => json_encode(lineMeta[i]) | null`, `'MetaPreserve' => !$c['lineMetaProvided']`.
3. `lyricLinesApplyDesired()`: SELECT adds `MetaJson`; before the dirty-check, when `$matchId !== null`: `if ($d['NotePreserve']) $d['Note'] = $existingById[$matchId]['Note']; if ($d['MetaPreserve']) $d['MetaJson'] = $existingById[$matchId]['MetaJson'];` — INSERT/UPDATE add `MetaJson` (bind type `s`). `lyricLinesRowClean()` compares `MetaJson` via `lyricLinesJsonEqual()`. On INSERT a preserve flag means NULL.
4. `lyricLinesSnapshotDeletedEnrichment()` adds, gated on `vocalPartsTablesReady()` / `vocalPartsSpansReady()` / `lyricRoundsReady()` / `vocalSuggestionsReady()`: `lineVocalParts` (`SELECT Id,LineId,VocalPartId,IsBackground FROM tblLyricLineVocalParts WHERE LineId IN`), `lineVocalSpans`, `rounds` (`WHERE StartLineId IN … OR EndLineId IN … OR CodaStartLineId IN … OR CodaEndLineId IN …` + their voices), `vocalSuggestions` — same `tblActivityLog` snapshot row, same never-throws posture.
5. `component_upsert` (api2): accepts `notes` + `lineMeta` with the same key-present intent as `label`; target-preserve block copies `notes`/`lineMeta` from the current entry when omitted. `components_replace` / `save_song_core` need NO change — they rebuild from the editor shape, which now carries both (PF1 carry); the classic v1 editor never sends them → preserved by rule 1.

---

## 6 — TTML ingest (D2) — `includes/lyrics_ingest.php`

Parser (`lyricsIngest_parseTtml`):
- NEW: read `<head>` → every element with local name `agent` (namespace ttm): `agents[] = {id: xml:id, type: @type|null, name: text of child ttm:name|null, meta: all attrs}`; `<ttm:agent>` elsewhere ignored.
- Per `<p>`: `agent = meta['ttm:agent'] ?? meta['agent']`, `role = meta['ttm:role'] ?? meta['role']` exposed as `line.agent`, `line.role` (MetaJson unchanged — still lossless).
- Fix (a): in the leaf-span branch, when a word is created from a leaf span set `$cur['meta'] = _ttmlMeta($node)` if `$cur['meta'] === null`; when the single redundant syllable is dropped, its meta is ALREADY on the word.
- Fix (b): `_ttmlChildSpans()` gains a sibling `_ttmlSpanIsPhrase(\DOMElement)`: true when the span has ≥1 child span AND any child TEXT node containing whitespace between two child spans. A phrase span is walked RECURSIVELY with the parent loop (`$walk($node, $inheritedMeta)`), each produced word getting `meta = child meta + inherited (child wins)`; a non-phrase nested span keeps the current "one word with syllables" rule.
- Each word exposes `word.agent`, `word.bg = (role === 'x-bg')`.

Writer (`lyricsIngest_writeToDb`, inside the existing transaction, AFTER the syllable loop, gated `vocalPartsTablesReady()`):
1. `$partByAgent = []`; for each `agents[]` entry: kind = first declared agent → `'lead'`, later `type==='person'` → `'soloist'` (`'named-singer'` when name present), `'group'|'organization'` → `'group'`, else `'lead'`; `vocalPartsFindOrCreate($lyricsId, kind, name, $source, $agentId, meta)`; SortOrder = declaration index. Any `ttm:agent` value referenced by a `<p>`/`<span>` but undeclared → created with label = the id (still keyed by TtmlAgentId).
2. If any line/word has `bg` and no agent: `$bgPart = vocalPartsFindOrCreate($lyricsId,'backing',null,$source,'x-bg')`.
3. Per line: `agent` → line row (part, bg = line.role==='x-bg'); `bg` with no agent → (bgPart, IsBackground=1). Per word: `agent`/`bg` → `vocalPartsAssignWords()` (rows on the word ONLY when the word's part-set differs from its line's — the "word overrides line" read rule stays cheap).
4. Stale agents: `DELETE FROM tblVocalParts WHERE LyricsId=? AND Source=? AND TtmlAgentId IS NOT NULL AND TtmlAgentId NOT IN (<bound list>)`.
Return array gains `vocalParts:int, lineParts:int, wordParts:int`.

---

## 7 — Importers / exporter contracts (#2071, #2075)

- `_bulkImport_openLyricsParseLines()` returns per line `['text','chords','note','part' => string|null, 'repeat' => int|null]` — read `$linesNode['part']`/`['repeat']` via SimpleXML BEFORE the tag-strip regex (the regex stays; only the attribute read moves ahead of it). `_bulkImport_openLyricsLinesToArray()` unchanged (flat path). Verse loop (:3299): collects `$parts[]` and `$lineMeta[] = repeat ? ['openlyrics'=>['repeat'=>N]] : null`; sets `$comp['lineMeta']` when any non-null; keeps `$parts` on `$comp['_voiceParts']` (underscore = importer-private, stripped before the write). After `lyricLinesWriteComponents()` in the shared save pipeline, an importer hook `vocalPartsApplyComponentParts($db, $songId, array $partsByComponent, string $source='openlyrics')` re-reads `lyricLinesAssembleComponents()` for lineIds and calls `vocalPartsFindOrCreate()` per distinct part string (MARKER_MAP kind when UPPER(part) is a key, else kind `'group'` label = part as written) + `vocalPartsAssignLines()` — structured signal, applied directly (idempotent under uq_Line_Part), no queue row.
- Exporter `format-export.js buildOpenLyrics()`: a component with `voices` emits one `<lines part="…">` per RUN of equal voice-sets (part = lower-cased displayLabel of the first non-bg part; chunking by maxLines still applies WITHIN a run); components without `voices` keep the attribute-less chunk blocks byte-identical. `lineMeta[i].openlyrics.repeat` → `repeat="N"` on the block that starts at that line (only when the whole block shares it). The importer discriminates on attribute PRESENCE, so both round-trip.
- #2075: `_bulkImport_componentTypeFor()` callers (.txt :328, OpenSong :2165 family, :2425, :3076): when `vocalPartDetectMarker($token)` is non-null the token is NOT a section header: it is appended as a lyric line to `$current` (opening `['type'=>'verse','number'=>0,'lines'=>[]]` if none) and the section continues. The marker text survives into `tblLyricLines`; the bulk-import pipeline calls `vocalSuggestionsRunForSong()` per imported song when `vocalSuggestionsReady()` (non-blocking, own try/catch, never fails the import). PP7 (:5366) keeps its label behaviour AND, when `_bulkImport_pro7GroupType()`'s raw word is a MARKER_MAP key, ALSO applies it as a structured line assignment via the same hook (source `'propresenter7'`).

---

## 8 — Rounds projection contract (D1) — PURE, twin-implemented

`lyricRoundTimeline(round, subjectLineIds, lineTimingsMs)` (PHP) ≡ `roundTimeline()` in NEW `js/modules/round-timeline.js`. Inputs: the shaped round (§1.2), the ordered subject `lineIds` (from `lyricRoundSubjectLineIds`), optional `lineTimingsMs = {lineId: durationMs}`. Output:
```
{ unit: 'line' | 'ms',
  voices: [ {number, label, partId, entryLines, entryMs|null, subject:[lineId…], repeats:int|null} ],
  steps:  [ { k:int, at:int /* k in line units, or ms */, cells: [ {voice:number, lineId:int|null, cycle:int|null, phase:'waiting'|'singing'|'coda'|'done'} … ] } ],
  totalSteps:int, ending: 'complete'|'together'|'coda' }
```
Rules (line units): `L_v = len(subject_v)`, `R_v = voice.repeats ?? round.repeats ?? 1` (NULL repeats renders ONE cycle and flags `openEnded:true`), `off_v = entryLines`. At step k: `p = k − off_v`; `p < 0` → waiting; `p < L_v·R_v` → singing `subject_v[p mod L_v]`, cycle `floor(p/L_v)+1`; else done. `together`: totalSteps = `L_1·R_1` and every voice is forced done at that step; `complete`: totalSteps = `max_v(off_v + L_v·R_v)`; `coda`: after `L_1·R_1` every voice shows the coda lines in lockstep (`phase:'coda'`), totalSteps += codaLen. ms units when `entryMs` is derivable for EVERY voice (`entryMs ?? entryBeats·60000/bpm ?? entryLines·avgLineMs`) and every subject line has a duration (from `lineTimingsMs`, else `beatsPerLine·60000/bpm`); otherwise `unit:'line'`. Lockstep mechanism (rule #35): ONE fixture file `tests/fixtures/round-timeline-cases.json` (inputs + expected output) consumed by BOTH `tests/php/test-round-timeline.php` and `tests/test-round-timeline.js`; a mutation in either implementation goes red in its own test. Live playback state = `tblLiveFollowSessions.StateJson.round = {roundId, step:k}` written through the existing `serviceMode_applyBroadcast()` (`service_broadcast` body key `state.round`), read by the projector + `service-follow.js`; no schema change. Presentation (`present-mode.js`, projector): when a component's line range intersects a round, render one lane per voice (`.lyric-round-lane[data-voice]`) from the timeline's `cells` — the lane markup is a CHILD of `.lyric-component` so the slide scraper still works for the non-round path; the round path builds slides from `steps`, one slide per step.

---

## 9 — Batch runner (D4) — `appWeb/public_html/includes/tools/build-vocal-part-suggestions.php`
Mirrors `build-song-link-suggestions.php`: CLI + admin-run, `--limit`, `--songbook=ABBR`, `--since=YYYY-MM-DD` (tblLyrics.UpdatedAt), `--dry-run`, chunked `SELECT SongId FROM tblSongs ORDER BY SongId` with 200-song batches and a per-song try/catch, prints totals. Calls `vocalSuggestionsRunForSong()` only. Registered as a `setup-database` card? NO — it is a batch job, not a migration (a 16k-song scan in the web runner would time out); a manual card is not created. Admin page `/manage/vocal-part-suggestions.php` (UI pass): filter/sort list (`.admin-table-responsive`, sortable headers), per-row Apply / Dismiss / "Open in editor" (`/manage/editor2?song=<id>&tab=structure&line=<lineId>` — new `line` param the editor MUST honour, rule #33; add to `tests/test-editor-deep-links.js`'s derivation), a "Re-scan this song" button, entitlement `edit_songs`, nav entry in `admin-links.php` with the SAME entitlement.

---

## 10 — Song fragment markup contract (`includes/pages/song.php`)
Inside the line loop, when `$voices[$lineIdx]` is set and `runStart`:
```php
<p class="lyric-line mb-1" data-line-id="…" data-voice-ids="10"><span class="lyric-voice" role="group" aria-label="Sung by Women"><span class="lyric-voice-chip" aria-hidden="true">Women</span>: </span>You are holy,</p>
```
(`data-voice-ids` on EVERY assigned line so CSS/JS can style runs; the chip only at run start; `bg` lines get class `lyric-line-echo` + chip text "Echo"; spans wrap the range in `<span class="lyric-voice-span" data-voice-ids data-bg>` built by code-point slicing with `mb_substr`.) Because the chip is a CHILD, `present-mode.js` slides read "Women: You are holy" and `share.js` snippets likewise — desired. Rounds render a `.lyric-round` summary block (child of the component) listing voices + entries.

---

## 11 — iLyricsDB generalisation flags
Everything new anchors on `tblLyrics`/`tblLyricLines`/`tblLyricWords` (the shared spine) and never on `tblSongs`/`tblSongComponents` except: (1) `tblVocalPartSuggestions.SongId` (denorm, VARCHAR(20) songbook-prefixed key, `LIKE 'ABBR-%'` filter) — the shared backend must treat it as an opaque key and filter by a different scope column; (2) `tblLyricRounds.ComponentId` soft hint; (3) the `Source='ihymns'` sentinel meaning "the editable version" — the shared backend should promote this to an explicit `IsEditable`/`IsPrimary` semantic and the resolver `lyricLinesPrimaryLyricsId()` is the ONE place to change; (4) `tblVocalParts.MusicianId` → `tblMusicians` (iHymns's person registry; iLyricsDB's artist registry maps here); (5) `IHYMNS_VOCAL_MARKER_MAP` is English-hymnal-specific and must become a per-language/per-source table when shared (design the constant as `[marker => [kind,label]]` so a table swap is mechanical); (6) `PartType/PartNumber` section identity in `lyricLinesDiff()` is hymn-shaped (verse/chorus) — irrelevant to the voice tables themselves. The pure cores (`vocal_part_detect.php`, `lyricRoundTimeline`) are DB-free and portable as-is.

---

## 12 — Guards (all tree-derived, mutation-proven, rule #34)
- `tests/php/test-vocal-part-detect.php` — truth table over §1.3 (standalone marker; NBSP-prefix; `\s`-only regex mutation goes red on the NBSP case; all-caps lyric "HALLELUJAH" NOT a marker; "(repeat verse 2)" NOT an echo; "(he is my refuge)" IS; canon phrases; run governance).
- `tests/php/test-vocal-parts-core.php` — vocab normalisation, span validator (full-width rejected, code points on a multibyte line), `vocalPartsDisplayLabel`.
- `tests/php/test-round-timeline.php` + `tests/test-round-timeline.js` — shared fixture lockstep (§8).
- `tests/php/test-lyric-lines-read.php` — new fixtures: `notes`/`lineMeta`/`voices`/`voiceSpans` sparse; existing fixtures untouched (strict ===).
- `tests/php/test-lyric-lines-diff.php` — Note/MetaJson preserve-on-omit vs explicit-null clear.
- `tests/php/test-lyrics-ingest-agents.php` — head agents parsed; leaf-span agent survives; x-bg phrase yields N words with bg; undeclared agent still creates a part.
- `tests/php/test-vocal-part-vocab-lockstep.php` — `IHYMNS_VOCAL_PART_KINDS` keys ⊆ the `tblVocalParts.PartKind` COMMENT list in schema.sql AND the editor JS never hardcodes a kind literal outside `window.iHymnsConfig.vocalPartKinds`.
- `tests/php/test-schema-coverage.php` / `test-migration-registry.php` — pick the new migration up automatically.
- `tests/test-component-label-sites.js`-style: `tests/test-voice-render-sites.js` derives every `.lyric-line` consumer from the tree and asserts the chip is a CHILD (fails if a sibling `.lyric-voice` appears in `song.php`).
- `tests/test-editor-deep-links.js` — the new `&line=` param is handled by editor2.

## Files
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/appWeb/.sql/migrate-vocal-parts-rounds.php` (NEW) — NEW migration: four guarded CREATE TABLE blocks (tblLyricLineVocalSpans, tblLyricRounds, tblLyricRoundVoices, tblVocalPartSuggestions) with INFORMATION_SCHEMA probes; pre-flight refuses without tblVocalParts/tblLyricLines/tblMusicians; pure DDL, no docroot require.
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/appWeb/.sql/schema.sql` — Append the four CREATE TABLE IF NOT EXISTS blocks (byte-identical column/index/FK text) after tblLyricWordVocalParts under a new '#2073' comment header in the VOCAL / SINGING PARTS section.
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/appWeb/.sql/migrate-vocal-parts.php` — Align the three drifted COMMENT strings (PartKind, Gender, MetaJson) to schema.sql's text (rule #19 byte-identity).
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/appWeb/public_html/manage/includes/migration-registry.php` — Add the 'vocal-parts-rounds' entry with the four-table OR-probe, directly after 'vocal-parts'.
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/appWeb/public_html/includes/vocal_parts.php` (NEW) — NEW: IHYMNS_VOCAL_PART_KINDS / GENDERS maps + the ONE voice-part core (ready probes, find-or-create, upsert/delete, assign/clear lines, span upsert/delete, assign words, line/span/word maps, vocalPartsForSong).
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/appWeb/public_html/includes/lyric_rounds.php` (NEW) — NEW: IHYMNS_ROUND_KINDS / ENDINGS + the ONE rounds core (ready, forVersion, upsert with voice-set replace + validation, delete, pure subject resolver, pure lyricRoundTimeline).
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/appWeb/public_html/includes/vocal_part_detect.php` (NEW) — NEW: PURE detection core — marker map, echo direction words, suggestion vocabularies, vocalPartDetectMarker/Echo/RoundDirection/Lines, detector version. No DB. Shared with #1260.
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/appWeb/public_html/includes/vocal_part_suggestions.php` (NEW) — NEW: queue core — run-for-song (upsert by (LineId,Signal), supersede), list, apply (re-validate → stale 409; sibling-pending guard; dispatch by action through the vocal_parts / lyric_rounds / lyricLinesWriteComponents cores), dismiss.
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/appWeb/public_html/includes/lyric_lines_read.php` — Add lyricLinesPrimaryLyricsId(); lyricLinesFetchPrimary SELECT adds ll.Note AS line_note + ll.MetaJson AS line_meta (Note gated on the existing probe); lyricLinesAssembleFromRows(array $rows, array $extras = []) emits sparse notes/lineMeta/voices/voiceSpans; lyricLinesAssembleComponents/Map fetch the voice maps only when ready && version has any; lyricLinesEditableComponents emits always-present notes/lineMeta/voices/voiceSpans.
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/appWeb/public_html/includes/lyric_lines_sync.php` — notesProvided/lineMeta/lineMetaProvided in the normaliser; NotePreserve/MetaPreserve in the desired builder; lyricLinesApplyDesired reads+writes MetaJson and substitutes existing Note/MetaJson on UPDATE when preserve; lyricLinesRowClean compares MetaJson; lyricLinesSnapshotDeletedEnrichment also snapshots line vocal parts, spans, rounds(+voices) and suggestions.
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/appWeb/public_html/includes/SongData.php` — _primaryLyricsId() delegates to lyricLinesPrimaryLyricsId() first; getSongById() adds sparse vocalParts + rounds from vocalPartsForSong().
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/appWeb/public_html/includes/content_gating.php` — Strip vocalParts/rounds (song level) and voices/voiceSpans (component level) together with the lyric body; still a verified no-op when content_gating_enabled='0'.
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/appWeb/public_html/includes/lyrics_ingest.php` — Parse <head> ttm:agent definitions; expose line.agent/role and word.agent/bg; fix leaf-span meta loss and x-bg phrase collapse (_ttmlSpanIsPhrase + recursive walk with inherited meta); writer upserts tblVocalParts per agent, synthesises the 'x-bg' backing part, writes line + word assignment rows, deletes stale agents; return gains vocalParts/lineParts/wordParts counts.
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/appWeb/public_html/includes/song_importers.php` — #2071: _bulkImport_openLyricsParseLines returns part + repeat per line (read before the tag strip); verse loop carries lineMeta + _voiceParts; post-write hook vocalPartsApplyComponentParts() (structured → applied directly). #2075: all four _bulkImport_componentTypeFor/openSong fallback call sites keep a MARKER_MAP token as a lyric line instead of opening a fake refrain; PP7 group label that is a marker is also applied structurally; per-song vocalSuggestionsRunForSong() after import, non-blocking.
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/appWeb/public_html/manage/editor/format-export.js` — buildOpenLyrics: one <lines part="…"> per run of equal voice-sets when comp.voices exists, attribute-less chunk blocks otherwise (byte-identical); repeat="N" from lineMeta.openlyrics.repeat.
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/appWeb/public_html/manage/editor/api2.php` — Nine new POST cases (vocal_part_upsert/delete, line_vocal_assign/clear, line_vocal_span_upsert/delete, round_upsert/delete, vocal_suggestions_run) on the line_translation_* template; load_song adds vocalParts + vocalPartsReady/roundsReady; component_upsert accepts notes + lineMeta with key-present intent and target-preserve.
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/appWeb/public_html/manage/editor/v2/api-client.js` — One method per new api2 action; callers branch on err.status + reason.
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/appWeb/public_html/api.php` — vocal_suggestion_list / _apply / _dismiss / _run actions (edit_songs, getAuthenticatedUser + validateCsrfRequest, sendJson) delegating to vocal_part_suggestions.php; song_detail carries vocalParts/rounds/voices via SongData unchanged.
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/appWeb/public_html/api-docs.yaml` — Path items for the nine api2 actions and four /api actions; document the sparse voices/voiceSpans/notes/lineMeta component keys and song-level vocalParts/rounds.
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/appWeb/public_html/includes/tools/build-vocal-part-suggestions.php` (NEW) — NEW batch runner mirroring build-song-link-suggestions.php (--limit/--songbook/--since/--dry-run, 200-song batches, per-song try/catch) calling vocalSuggestionsRunForSong().
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/appWeb/public_html/manage/vocal-part-suggestions.php` (NEW) — NEW admin review page (UI pass): responsive sortable list, Apply/Dismiss/Re-scan/Open-in-editor (&line=), edit_songs gate matching its admin-links.php entry, actions mapped to api:vocal_suggestion_* in the coverage guard.
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/appWeb/public_html/manage/includes/admin-links.php` — Nav entry for Voice-part suggestions with the edit_songs entitlement.
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/appWeb/public_html/js/modules/round-timeline.js` (NEW) — NEW pure JS twin of lyricRoundTimeline(); consumed by present-mode.js, service-projection.php and service-follow.js.
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/appWeb/public_html/includes/pages/song.php` — Render the voice chip as a CHILD of <p class="lyric-line"> at run starts (role=group + aria-label, chip aria-hidden), data-voice-ids on assigned lines, .lyric-line-echo, code-point-sliced .lyric-voice-span, and a .lyric-round summary block per component intersecting a round; reads $song['vocalParts'] / $components[i]['voices'].
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/appWeb/public_html/index.php` — $iHymnsConfig gains vocalPartKinds / roundKinds / roundEndings from the PHP constants.
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/appWeb/public_html/manage/editor/v2/structure-tab.js` — Per-line voice picker + D3 flow (component_upsert before first assign; pendingVoice[] on failure); honours ?line= deep link. (UI pass — contract only fixed here.)
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/appWeb/public_html/manage/editor/v2/round-panel.js` (NEW) — NEW round/canon editor panel (voices, entries, ending, coda) over round_upsert/round_delete. (UI pass.)
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/appWeb/public_html/js/modules/present-mode.js` — Round-aware slide build from roundTimeline() steps when a component intersects a round; non-round path unchanged.
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/appWeb/public_html/includes/service_mode.php` — service_broadcast accepts state.round = {roundId, step}; stored in the existing StateJson (no schema change).
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/tests/php/test-vocal-part-detect.php` (NEW) — NEW truth table for the pure detector (incl. the NBSP-prefix mutation case).
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/tests/php/test-vocal-parts-core.php` (NEW) — NEW: vocab normalisation, span validator, display label.
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/tests/fixtures/round-timeline-cases.json` (NEW) — NEW shared fixture (inputs + expected timelines) for the PHP↔JS lockstep.
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/tests/php/test-round-timeline.php` (NEW) — NEW: runs the shared fixture against lyricRoundTimeline().
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/tests/test-round-timeline.js` (NEW) — NEW: runs the same fixture against round-timeline.js.
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/tests/php/test-lyric-lines-read.php` — New fixtures for sparse notes/lineMeta/voices/voiceSpans; existing fixtures untouched.
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/tests/php/test-lyric-lines-diff.php` — Preserve-on-omit vs explicit-null for Note and MetaJson.
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/tests/php/test-lyrics-ingest-agents.php` (NEW) — NEW: head agents, leaf-span agent survival, x-bg phrase → N words, undeclared agent.
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/tests/php/test-vocal-part-vocab-lockstep.php` (NEW) — NEW: PHP kind map ⊆ schema COMMENT list; editor JS has no hardcoded kind literals.
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/tests/test-voice-render-sites.js` (NEW) — NEW tree-derived guard: the voice chip is a CHILD of .lyric-line in song.php; every .lyric-line scraper still resolves.
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/tests/php/test-manage-action-api-coverage.php` — Map vocal-part-suggestions.php's actions to api:vocal_suggestion_*.
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/tests/test-editor-deep-links.js` — Derives the new &line= param from the suggestions page and asserts editor2 handles it.
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/tests/php/fixtures/orphan-allowlist.php` — Retire the tblVocalParts 'reader-no-writer' entry (it now has writers); do not add the four new tables (they have writers from day one).

## Risks
- SILENT NULL-WIPE CLASS (the #2072 shape): if an implementer adds `notes`/`lineMeta` to the desired builder WITHOUT the NotePreserve/MetaPreserve substitution in lyricLinesApplyDesired(), every classic-editor save wipes them again with no error. test-lyric-lines-diff.php must include the 'omitted key on UPDATE preserves the stored value' case and be mutation-proven (delete the substitution → red).
- FUZZY REWRITE LOSS: a line rewritten below lyricLinesDiff()'s 0.5 similarity floor is delete+insert, so its voice rows, spans, and any round whose Start/End/Coda FK points at it are CASCADE-deleted (a round loses its whole definition from one heavily edited line). The extended tblActivityLog snapshot makes it recoverable, not prevented. Mitigation to consider in the UI pass: warn in the editor when a save would drop lines carrying voice/round rows (the diff plan is computable before commit).
- SPAN OFFSET DRIFT: a fuzzy-matched edit keeps the line Id but changes the text, so span offsets can point past the end or into different words. Read clamps/drops (never throws); the fidelity is only as good as the annotation precedent. Do not 'fix' by anchoring spans on words — curator versions have no tblLyricWords rows.
- FIDELITY SNAPSHOT MOVES for songs that already have Note (OpenLyrics imports): the sparse `notes` key changes their sha256 in tools/export-fidelity-snapshot.php. This is the bug fix surfacing; re-baseline in the same PR and record it — otherwise the C6 cutover audit reads it as corruption.
- REGISTRY-VERSION MISMATCH (the rule #35 gap fixed in §1.5): if _primaryLyricsId() is left unchanged, ?include=vocalParts can return a TTML version's agents next to 'ihymns' lineIds and the client will render chips on the wrong lines with no error. The delegation is load-bearing; guard it with a fixture in test-lyric-lines-read.php or a SongData unit test.
- TTML RE-INGEST + CURATOR ASSIGNMENTS: lyricsIngest_writeToDb() DELETEs all lines of the (SongId,Source) version on re-ingest, cascading every line/word assignment for THAT version — correct for the TTML version, but if a curator ever assigns parts on a TTML version (the editor only targets 'ihymns', so not today), those are lost. Keep the editor endpoints hard-scoped to lyricLinesPrimaryLyricsId().
- PARSER FIX (b) CAN CHANGE WORD COUNTS for already-ingested TTML with x-bg wrappers: re-ingesting re-splits a bogus single 'word' into N words (new tblLyricWords Ids). Word-timing consumers only read by LineId order, so this is safe, but call it out in the ingest changelog.
- APPLY 'strip-marker-line' rewrites the component through lyricLinesWriteComponents(): the marker line is deleted (its suggestion rows cascade) and every following line's SortOrder shifts — Ids are preserved by the diff (text unchanged), so assignments survive; but the sibling-pending guard is what stops a curator from stripping the only evidence before assigning. Test the ordering explicitly (assign then strip; strip while assign pending → 409).
- CORPUS COVERAGE: the marker map is English-hymnal-shaped; 0 hits in CH/SDAH vs ~1.2% in MP/CP means the backfill mostly touches the scraped books. Non-English markers ("HOMBRES", "MUJERES") will not be detected until the map grows — a one-line add each, never an ALTER, but easy to forget: file it under #1260.
- DEPLOY ORDER: reads are gated on vocalPartsTablesReady()/vocalPartsSpansReady()/lyricRoundsReady() so an un-migrated env degrades to today's shapes; but the three docroots share ONE MySQL, so running the card on alpha makes the tables exist for beta/main code that has not shipped the readers — harmless (tables empty, nothing writes) as long as no pre-cutover code SELECTs the new columns unguarded. There are no new columns on existing tables, which is why this batch is deploy-order-safe.
- ENTITLEMENT PARITY: the new admin page, its nav entry and the /api twins must all gate on edit_songs; test-api-gate-parity.php will catch a drift only if the page's gate is expressed with the same helper. Do not gate the apply action on a bare role.
- STATEJSON ROUND STEP is a live-broadcast field with no server validation of roundId ownership; validate {roundId} belongs to CurrentSongId's 'ihymns' version in serviceMode_applyBroadcast() or a stale id renders an empty lane set on every follower.
---

# Design pass 2

## Summary
Pass 2 (vocabulary + shared core) for the #2073 voice-parts feature. DECISION: the PartKind vocabulary is a central PHP constant map (the IHYMNS_ORG_LOGO_KINDS house shape, rule #20) — 22 canonical keys, each carrying its display label, plain-English description, implied gender, the text-marker words the detector recognises, and its per-format export keyword (OpenLyrics part=, TTML ttm:agent type, the canonical UPPER-CASE marker for formats with no voice concept) — NOT a DB registry with CRUD. Curator extensibility already exists at the right layer via tblVocalParts.Label / SingerName / MusicianId (the rule-#45 Type-vs-Label split), and rule #43's find-or-create applies to the PART row (vocalPartsFindOrCreate) not to the kind. ONE word→kind resolver (vocalPartsKindFromWord) is derived from that map and is what the text-marker detector, the OpenLyrics part= importer and the API normaliser all call — so export→import round-trips close through one table (rule #35 mechanism, not a comment). The ONE core is includes/vocal_parts.php, modelled function-for-function on includes/line_enrichment.php (same throw→status contract: InvalidArgumentException→400, RuntimeException→404, tablesReady()→409), reusing lineEnrichmentResolveLine() as the IDOR guard rather than forking it, with a bulk resolver that also pins every line to the song's ONE primary version. Run assignment is a SET of lines with replace/add modes scoped by IsBackground so an echo mark survives a voice re-assignment; runs (the header-once-per-run grain) are DERIVED by a pure function with a PHP↔JS shared-fixture lockstep test. Word grain (D2) is wired end-to-end: the TTML parser gains <head> agent reading plus fixes for THREE per-word defects (one more than the research listed), and the ingest writer assigns line + word parts inside its existing transaction, idempotently on uq_Lyrics_Agent. Rounds live in includes/lyric_rounds.php (Pass 1's file) with a pure timeline function that resolves ms→beats→lines so the projector can stagger voices (D1). Two established-research errors corrected loudly: tblVocalParts' person column is MusicianId, not CreditPersonId (already #2077), and the TTML parser has three word-grain defects, not two.

## Spec
## 0 — Scope of this pass

This pass owns (a) the PartKind vocabulary and (b) the ONE shared core `includes/vocal_parts.php` (+ the rounds core's function contracts, the ingest wiring for word grain, and the API twins the core needs). It designs TO Pass 1's data model (four new tables in ONE migration `appWeb/.sql/migrate-vocal-parts-rounds.php`; zero ALTERs). Where Pass 1 typed a marker map, this pass replaces it with a derivation from the vocabulary (decision 2). Everything below was checked against the tree on 2026-09-04.

All file paths are under `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/`.

---

## 1 — THE VOCABULARY (`appWeb/public_html/includes/vocal_parts.php`, top of file)

### 1.1 Why a PHP map (summary of decision 1)
Fixed canonical set, exported by three machine formats, with curator words living in `tblVocalParts.Label`/`SingerName`/`MusicianId`. House shape = `IHYMNS_ORG_LOGO_KINDS` (key ⇒ array). Never an ENUM; a new kind is ONE line here.

### 1.2 The map — VERBATIM

```php
/**
 * ELI5: the fixed list of "who sings this" kinds the whole app understands.
 * WHY: one place (rule #20/#22). Key ORDER = picker order. Every key carries:
 *   label        — the plain display word the UI shows when a part has no Label of its own
 *   description  — one plain sentence for the picker's help text
 *   gender       — the gender the kind IMPLIES (derived, rule #44) or null
 *   markers      — UPPER-CASE words a lyric text uses as a voice cue. `word => null`
 *                  means "use the kind label"; `word => 'Boys'` overrides the Label the
 *                  detector proposes. The FIRST key is the canonical marker an exporter
 *                  writes for formats with no voice concept (§1.5). NEVER a section word
 *                  (CHORUS/VERSE/BRIDGE/REFRAIN…) — those are tblSongPartTypes.
 *   openlyrics   — the <lines part="…"> keyword (OpenLyrics 0.8 leaves part free-text;
 *                  we write the conventional lower-case word so other tools read it)
 *   ttmlAgent    — the <ttm:agent type="…"> value: person | group | other
 *                  (TTML2 §12.2.1 ttm:agent — only these + character/organization exist)
 */
const IHYMNS_VOCAL_PART_KINDS = [
    'lead'         => ['label' => 'Lead',         'description' => 'The main voice.',                                              'gender' => null,     'markers' => ['LEAD' => null, 'MELODY' => null],                                             'openlyrics' => 'lead',         'ttmlAgent' => 'person'],
    'soloist'      => ['label' => 'Soloist',      'description' => 'One singer on their own.',                                     'gender' => null,     'markers' => ['SOLO' => 'Solo', 'SOLOIST' => null],                                          'openlyrics' => 'solo',         'ttmlAgent' => 'person'],
    'named-singer' => ['label' => 'Named singer', 'description' => 'A particular person — pick the musician or type their name.',  'gender' => null,     'markers' => [],                                                                              'openlyrics' => null,           'ttmlAgent' => 'person'],
    'male'         => ['label' => 'Men',          'description' => 'The men sing.',                                                'gender' => 'male',   'markers' => ['MEN' => null, 'MAN' => null, 'GENTLEMEN' => null, 'MALE' => null, 'MALES' => null, 'BOYS' => 'Boys'], 'openlyrics' => 'men',   'ttmlAgent' => 'group'],
    'female'       => ['label' => 'Women',        'description' => 'The women sing.',                                              'gender' => 'female', 'markers' => ['WOMEN' => null, 'WOMAN' => null, 'LADIES' => null, 'FEMALE' => null, 'FEMALES' => null, 'GIRLS' => 'Girls'], 'openlyrics' => 'women', 'ttmlAgent' => 'group'],
    'children'     => ['label' => 'Children',     'description' => 'The children sing.',                                           'gender' => null,     'markers' => ['CHILDREN' => null, 'KIDS' => null, 'YOUTH' => 'Youth', 'JUNIORS' => 'Juniors'], 'openlyrics' => 'children',     'ttmlAgent' => 'group'],
    'all'          => ['label' => 'All',          'description' => 'Everyone sings.',                                              'gender' => null,     'markers' => ['ALL' => null, 'EVERYONE' => null, 'EVERYBODY' => null, 'TOGETHER' => null, 'TUTTI' => null, 'BOTH' => 'Both'], 'openlyrics' => 'all', 'ttmlAgent' => 'group'],
    'unison'       => ['label' => 'Unison',       'description' => 'Everyone on the melody, no harmony.',                          'gender' => null,     'markers' => ['UNISON' => null],                                                              'openlyrics' => 'unison',       'ttmlAgent' => 'group'],
    'duet'         => ['label' => 'Duet',         'description' => 'Two voices together, treated as one part (TTML sources do this).', 'gender' => null,  'markers' => ['DUET' => null],                                                                'openlyrics' => 'duet',         'ttmlAgent' => 'other'],
    'group'        => ['label' => 'Group',        'description' => 'A numbered or named group — "Group 2", "Left side".',           'gender' => null,     'markers' => ['GROUP' => null],                                                               'openlyrics' => 'group',        'ttmlAgent' => 'group'],
    'choir'        => ['label' => 'Choir',        'description' => 'The choir.',                                                   'gender' => null,     'markers' => ['CHOIR' => null],                                                               'openlyrics' => 'choir',        'ttmlAgent' => 'group'],
    'congregation' => ['label' => 'Congregation', 'description' => 'The people / the congregation.',                               'gender' => null,     'markers' => ['CONGREGATION' => null, 'PEOPLE' => 'People', 'ASSEMBLY' => 'Assembly', 'RESPONSE' => 'Response'], 'openlyrics' => 'congregation', 'ttmlAgent' => 'group'],
    'cantor'       => ['label' => 'Cantor',       'description' => 'The leader or cantor line.',                                   'gender' => null,     'markers' => ['CANTOR' => null, 'LEADER' => 'Leader', 'MINISTER' => 'Minister', 'PRIEST' => 'Priest', 'CELEBRANT' => 'Celebrant'], 'openlyrics' => 'cantor', 'ttmlAgent' => 'person'],
    'descant'      => ['label' => 'Descant',      'description' => 'A high line sung above the melody.',                           'gender' => null,     'markers' => ['DESCANT' => null],                                                             'openlyrics' => 'descant',      'ttmlAgent' => 'group'],
    'soprano'      => ['label' => 'Soprano',      'description' => 'Choir section: soprano.',                                      'gender' => null,     'markers' => ['SOPRANO' => null, 'SOPRANOS' => null],                                        'openlyrics' => 'soprano',      'ttmlAgent' => 'group'],
    'alto'         => ['label' => 'Alto',         'description' => 'Choir section: alto.',                                         'gender' => null,     'markers' => ['ALTO' => null, 'ALTOS' => null],                                              'openlyrics' => 'alto',         'ttmlAgent' => 'group'],
    'tenor'        => ['label' => 'Tenor',        'description' => 'Choir section: tenor.',                                        'gender' => null,     'markers' => ['TENOR' => null, 'TENORS' => null],                                            'openlyrics' => 'tenor',        'ttmlAgent' => 'group'],
    'bass'         => ['label' => 'Bass',         'description' => 'Choir section: bass.',                                         'gender' => null,     'markers' => ['BASS' => null, 'BASSES' => null],                                             'openlyrics' => 'bass',         'ttmlAgent' => 'group'],
    'backing'      => ['label' => 'Backing',      'description' => 'Background or echo voices.',                                   'gender' => null,     'markers' => ['ECHO' => 'Echo', 'BACKING' => null, 'BACKING VOCALS' => null, 'BGV' => null], 'openlyrics' => 'backing',      'ttmlAgent' => 'group'],
    'narrator'     => ['label' => 'Narrator',     'description' => 'Spoken narration between or over the singing.',                'gender' => null,     'markers' => ['NARRATOR' => null, 'READER' => 'Reader'],                                     'openlyrics' => 'narrator',     'ttmlAgent' => 'person'],
    'spoken'       => ['label' => 'Spoken',       'description' => 'Spoken, not sung.',                                            'gender' => null,     'markers' => ['SPOKEN' => null],                                                              'openlyrics' => 'spoken',       'ttmlAgent' => 'person'],
];

/** Input aliases → canonical key. Lower-case, matched after trim. 'main' is the schema
 *  comment's legacy name for lead (decision 3). The markers above are ALSO accepted
 *  (lower-cased) by vocalPartsNormalizeKind() — this list holds only the words that are
 *  NOT markers. */
const IHYMNS_VOCAL_PART_KIND_ALIASES = [
    'main' => 'lead', 'background' => 'backing', 'bg' => 'backing', 'x-bg' => 'backing',
    'singer' => 'named-singer', 'person' => 'named-singer', 'kid' => 'children',
    'child' => 'children', 'everybody' => 'all', 'sop' => 'soprano', 'ten' => 'tenor',
];

const IHYMNS_VOCAL_GENDERS = ['male', 'female', 'neutral'];

/** Sources whose voice signal is APPLIED directly on import (structured, machine-owned),
 *  as opposed to text heuristics that go to the review queue (Pass 1 §1.4). Mirrors
 *  tblLyrics.Source / tblVocalParts.Source values. */
const IHYMNS_VOCAL_SOURCES_STRUCTURED = ['applemusic-ttml', 'openlyrics', 'propresenter7'];

/** The `group` ordinal pattern (detector-owned because it captures a number; every other
 *  marker is a plain word derived from the map). UPPER-CASE input. */
const IHYMNS_VOCAL_GROUP_ORDINAL_RE = '/^(?:GROUP\s*(?<n>\d)|(?<o>\d)(?:ST|ND|RD|TH)(?:\s+GROUP)?|(?<w>FIRST|SECOND|THIRD|FOURTH)(?:\s+GROUP)?)$/u';
```

### 1.3 Export mapping table (the `openlyrics` / `ttmlAgent` / canonical `marker` columns, rendered for the implementer)

| key | display | OpenLyrics `part=` | TTML `ttm:agent type=` | canonical text marker |
|---|---|---|---|---|
| lead | Lead | `lead` | person | `LEAD` |
| soloist | Soloist | `solo` | person | `SOLO` |
| named-singer | Named singer | SingerName ?? musician Name ?? `solo` (the one data-derived keyword; OpenLyrics part is free text by spec) | person | the same name, UPPER-CASED |
| male | Men | `men` | group | `MEN` |
| female | Women | `women` | group | `WOMEN` |
| children | Children | `children` | group | `CHILDREN` |
| all | All | `all` | group | `ALL` |
| unison | Unison | `unison` | group | `UNISON` |
| duet | Duet | `duet` | other | `DUET` |
| group | Group | `group<N>` — N = 1-based ordinal of this part among the version's `group` parts by (SortOrder, Id) | group | `GROUP <N>` |
| choir | Choir | `choir` | group | `CHOIR` |
| congregation | Congregation | `congregation` | group | `CONGREGATION` |
| cantor | Cantor | `cantor` | person | `CANTOR` |
| descant | Descant | `descant` | group | `DESCANT` |
| soprano/alto/tenor/bass | S/A/T/B | same word | group | `SOPRANO`/`ALTO`/`TENOR`/`BASS` |
| backing | Backing | `backing` | group | `ECHO` |
| narrator | Narrator | `narrator` | person | `NARRATOR` |
| spoken | Spoken | `spoken` | person | `SPOKEN` |

Rules the table encodes: the keyword is derived from the KIND (plus ordinal for `group`, plus name for `named-singer`) — NEVER from `Label` (the rule-#45 analogue: a curator's "Youth" label on a `group` part exports as `group2`; the STRUCTURE round-trips, the cosmetic label does not, and that is accepted). TTML: `xml:id` = `TtmlAgentId` when set, else `'v' . (index+1)` over the version's parts by (SortOrder, Id); `<ttm:name type="full">` = `displayLabel`; `IsBackground` is NOT an agent property — it is `ttm:role="x-bg"` on the `<p>`/`<span>`. Import of an UNKNOWN OpenLyrics `part=` word (not a marker, not a kind, not an alias) → kind `group`, Label = the raw word (first letter upper-cased, ≤120 code points), `Source='openlyrics'`, `MetaJson={"part": "<raw>"}` — the weakest, lossless claim; a curator re-kinds it later.

### 1.4 Vocabulary functions (pure — unit-tested in `tests/php/test-vocal-parts.php`)

```php
function vocalPartsNormalizeKind(string $kind): ?string
```
ELI5: turn whatever the caller typed into one of our 22 kind keys, or say no. Detail: `strtolower(trim())`; returns the key if it is one; else `IHYMNS_VOCAL_PART_KIND_ALIASES[$v]`; else the kind whose `markers` contains `strtoupper($v)`; else null. Never mints.

```php
function vocalPartsKindFromWord(string $word): ?array   // {kind:string, label:?string}
```
ELI5: the ONE place a cue word like "MEN", "Group 2" or "ladies" becomes a kind + proposed label. Detail: fold = `mb_strtoupper(trim)`, collapse runs of `[ .\x{00A0}\t]` to one space, strip a trailing `:`; then (a) exact `markers` hit across the map → `{kind, label: markers[word] ?? null}`; (b) `IHYMNS_VOCAL_GROUP_ORDINAL_RE` → `{kind:'group', label:'Group N'}` with N from `n`/`o`/word-ordinal; (c) `vocalPartsNormalizeKind(lower)` (aliases) → `{kind, label:null}`; else null. Called by the detector, the OpenLyrics part= importer, the PP7/OpenSong marker recognition (#2075) and the API normaliser. No other word list may exist (guard §9.3).

```php
function vocalPartsDisplayLabel(array $part): string   // Label ?? SingerName ?? musicianName ?? KINDS[kind]['label']
function vocalPartsImpliedGender(string $kind): ?string
function vocalPartsExportKeyword(array $part, string $format, array $versionParts = []): string
```
`$format ∈ {'openlyrics','marker'}`; `$versionParts` = the version's part shapes (for the `group` ordinal). Throws `\InvalidArgumentException` on an unknown format. `'marker'` returns the canonical UPPER-CASE marker (first `markers` key; for `group` `'GROUP N'`; for `named-singer` the upper-cased name).

```php
function vocalPartsTtmlAgent(array $part, int $index): array   // {id:string, type:string, name:string}
function vocalPartsKindsProjection(): array
```
Projection = `list<{key, label, description, gender, marker}>` in map order — what `load_song` and `api.php?action=vocal_part_kinds` emit so no client types the map.

### 1.5 Marker words vs section words — invariant
A CI assertion (§9.1) checks that NO marker word equals any `tblSongPartTypes` seed name or the reflow section words (VERSE/CHORUS/REFRAIN/BRIDGE/PRE-CHORUS/INTRO/OUTRO/TAG/CODA/ENDING/INTERLUDE/VAMP), case-folded. That is what keeps `CHORUS` out of `choir`.

---

## 2 — THE ONE CORE: `appWeb/public_html/includes/vocal_parts.php`

File header mirrors line_enrichment.php's (direct-access 403 guard; `require_once db_mysql.php`; `require_once line_enrichment.php` for `lineEnrichmentResolveLine()`; `require_once lyric_rounds.php`; `require_once lyric_lines_read.php` for `lyricLinesPrimaryLyricsId()` + `lyricLinesFetchPrimary()`). Throw contract IDENTICAL to line_enrichment.php: `\InvalidArgumentException` → 400; `\RuntimeException` → 404 (ownership / not found); readiness false → the HANDLER answers 409. Every value bound; the only interpolated SQL is `array_fill`-built placeholders (rule #5). Every DB function is `bindParamSafe()`-wrapped where more than three params.

### 2.1 Readiness

```php
function vocalPartsTablesReady(\mysqli $db): bool
```
ELI5: "has the vocal-parts migration run here, with the right column names?" Detail: memoised static; one INFORMATION_SCHEMA query — `COUNT(*) >= 3` over `TABLES` for `tblVocalParts, tblLyricLineVocalParts, tblLyricWordVocalParts` AND `COUNT(*) = 1` over `COLUMNS` for `(tblVocalParts, MusicianId)` (the #2077 gate). Catch posture copied from `lyricLinesEnrichmentTablesPresent()`: `if (songRelocateIsTransactionFatal($_e)) throw; $ready=false` (the core runs inside the save funnels' transactions — require `song_relocate.php` lazily inside the catch as that file does).
```php
function vocalPartsSpansReady(\mysqli $db): bool   // tblLyricLineVocalSpans present (Pass 1 table); same posture
```

### 2.2 Ownership / IDOR resolvers

```php
function vocalPartsResolveLines(\mysqli $db, string $songId, array $lineIds): array
```
ELI5: prove every line the caller named really belongs to THIS song's editable lyrics, in one query. Detail: `$lineIds` → `array_values(array_unique(array_map('intval')))`, reject empty or any `<= 0` (→ InvalidArgumentException 'lineIds must be positive integers'). Cap 500 ids per call (→ 400). One query:
```sql
SELECT ll.Id, ll.LyricsId, ll.LineText, ll.SortOrder, ll.ComponentId
  FROM tblLyricLines ll JOIN tblLyrics ly ON ly.Id = ll.LyricsId
 WHERE ll.Id IN (?, …) AND ly.SongId = ?
```
If `count(rows) !== count($lineIds)` → `\RuntimeException('One or more lineIds do not belong to this song.')` (404 — never say which). If any `LyricsId !== lyricLinesPrimaryLyricsId($db, $songId)` → `\RuntimeException('Voice parts are edited on the primary lyrics version only.')` (404 class; the handler maps RuntimeException→404 like its siblings). Returns `array<int lineId, {lyricsId:int, text:string, cpLen:int, sortOrder:int, componentId:?int}>` (cpLen via `mb_strlen`, rule #21).

```php
function vocalPartsResolvePart(\mysqli $db, string $songId, int $partId): ?array   // raw tblVocalParts row or null
```
`SELECT vp.* FROM tblVocalParts vp JOIN tblLyrics ly ON ly.Id = vp.LyricsId WHERE vp.Id = ? AND ly.SongId = ? LIMIT 1`. Null → the caller throws RuntimeException.

Single-line callers reuse `lineEnrichmentResolveLine($db, $lineId, $songId)` — never a second JOIN.

### 2.3 Part registry

```php
function vocalPartsShape(array $r, ?string $musicianName = null): array
// {id, kind, label:?string, displayLabel, singerName:?string, gender:?string, musicianId:?int,
//  ttmlAgentId:?string, source, sortOrder:int}   — camelCase, ALWAYS-present keys (editor shape rule)
```

```php
function vocalPartsForVersion(\mysqli $db, int $lyricsId): array   // list<shape> ORDER BY SortOrder, Id; LEFT JOIN tblMusicians m ON m.Id = vp.MusicianId for displayLabel
```

```php
function vocalPartsFindOrCreate(\mysqli $db, int $lyricsId, string $kind, ?string $label = null, string $source = 'ihymns', ?string $ttmlAgentId = null, ?int $musicianId = null, ?string $singerName = null, ?array $meta = null): int
```
ELI5: "give me the part for this voice in this lyrics version — reuse it if it already exists, otherwise make it." (rule #43's find-or-create, applied to the part ROW). Detail — match ladder, first hit wins, all within `$lyricsId`:
1. `$ttmlAgentId !== null` → `WHERE LyricsId=? AND TtmlAgentId=?` (uq_Lyrics_Agent — idempotent re-ingest; confirmed in schema.sql:4516 as `UNIQUE KEY uq_Lyrics_Agent (LyricsId, TtmlAgentId)`, NULLs unconstrained).
2. `$kind === 'named-singer' && $musicianId !== null` → `WHERE LyricsId=? AND PartKind='named-singer' AND MusicianId=?`.
3. `WHERE LyricsId=? AND PartKind=? AND Label <=> ?` with `$label = ($label === null || trim($label)==='') ? null : mb_substr(trim($label),0,120)` — `<=>` is NULL-safe; `utf8mb4_unicode_ci` folds case/accent.
4. INSERT `(LyricsId, PartKind, Label, MusicianId, SingerName, Gender, TtmlAgentId, Source, SortOrder, MetaJson)` with `Gender = vocalPartsImpliedGender($kind)`, `SortOrder = (SELECT COALESCE(MAX(SortOrder)+1,0) … WHERE LyricsId=?)` (read first, bound), `MetaJson = json_encode($meta)` or NULL.
On a hit: NEVER overwrites Label/SingerName/MusicianId; if `$meta !== null` AND the row's `Source === $source` (machine-owned) → `UPDATE MetaJson`. Kind must already be normalised (throws InvalidArgumentException otherwise). Returns the Id. Race: a duplicate INSERT on uq_Lyrics_Agent throws under STRICT — the caller (ingest) retries the SELECT once.

```php
function vocalPartsUpsert(\mysqli $db, string $songId, array $input, ?int $userId): array   // → shape
```
Input `{id?, kind, label?, singerName?, gender?, musicianId?, sortOrder?}`; version = `lyricLinesEnsurePrimaryVersion($db, $songId)` (from lyric_lines_sync.php — creates the 'ihymns' row if absent, exactly as a save would; require lyric_lines_sync.php). Validation (all → InvalidArgumentException): `kind` via `vocalPartsNormalizeKind()` (required on create; on update omitted = keep); `label` ≤120 code points (empty → NULL); `singerName` ≤255; `gender` ∈ IHYMNS_VOCAL_GENDERS or omitted (→ derived from kind on create; on update omitted = keep); `musicianId` must `SELECT 1 FROM tblMusicians WHERE Id=?` else 400; `kind==='named-singer'` requires `musicianId` or `singerName` (400 'A named singer needs a musician or a name.'); `sortOrder` int ≥0. On update: `vocalPartsResolvePart()` null → RuntimeException. The `id`-given-but-`kind`-changed case is allowed (it re-kinds every assignment at once — that is the point). Returns `vocalPartsShape()` of the re-fetched row. `Source` stays whatever the row has ('ihymns' on create).

```php
function vocalPartsDelete(\mysqli $db, string $songId, int $partId): bool
```
Ownership via resolvePart (null → RuntimeException). `DELETE FROM tblVocalParts WHERE Id=?` — CASCADE clears line/word/span rows; `tblLyricRoundVoices.PartId` is `ON DELETE SET NULL` (Pass 1). Returns affected_rows > 0.

### 2.4 Line grain (the run gesture)

```php
function vocalPartsAssignLines(\mysqli $db, string $songId, array $lineIds, array $partIds, string $mode = 'replace', bool $isBackground = false): array
```
ELI5: "these lines are sung by these voices." Detail:
- `$mode ∈ {'replace','add'}` else 400. `$partIds` non-empty, ≤8, each resolves via `vocalPartsResolvePart()` AND its `LyricsId` must equal the lines' version (else RuntimeException — a part from a TTML version cannot be pinned onto the curator version).
- `$lines = vocalPartsResolveLines()` (§2.2).
- `replace`: `DELETE FROM tblLyricLineVocalParts WHERE LineId IN (…) AND IsBackground = ?` (bound `$isBackground`) — ONLY rows of the same background class, so marking an echo never wipes the voice and vice versa (decision 6).
- Then for each line × each part (index `i`): `INSERT … ON DUPLICATE KEY UPDATE SortOrder = VALUES(SortOrder), IsBackground = VALUES(IsBackground)` into `(LineId, VocalPartId, LyricsId, IsBackground, SortOrder)` with `LyricsId` from the resolved line, `SortOrder = i`. (uq_Line_Part is `(LineId, VocalPartId)` — so the SAME part cannot be both voice and echo on one line; an echo of yourself is meaningless, accepted.)
- Returns `['lines' => vocalPartsLinesMap($db, $lyricsId, $lineIds), 'runs' => <runs for the touched components>]` — the runs are recomputed from `lyricLinesFetchPrimary()` rows for the affected `ComponentId`s only.
- Overlap/abut semantics (spelled out): assigning lines 5–8 to Men when 3–8 were Women leaves 3–4 Women, 5–8 Men — two runs, no stored run object to fix. Assigning 9–12 to Men abutting 5–8 Men yields ONE derived run 5–12 (adjacency + identical part-set). Scattered ids are legal; they simply derive as several runs.

```php
function vocalPartsClearLines(\mysqli $db, string $songId, array $lineIds, string $scope = 'voice'): int
```
`$scope ∈ {'voice','echo','all'}` → `IsBackground = 0` / `= 1` / no filter. Returns rows removed.

```php
function vocalPartsLinesMap(\mysqli $db, int $lyricsId, ?array $onlyLineIds = null): array
// { <lineId>: list<{partId:int, bg:bool, sortOrder:int}> } ORDER BY LineId, SortOrder, VocalPartId — NORMALISED (no kind/label copy; look up in parts[] by id)
```

```php
function vocalPartsDeriveRuns(array $orderedLineIds, array $linesMap): array   // PURE
```
ELI5: work out where each "MEN"/"WOMEN" header goes — once at the start of each stretch of lines sung by the same voices. Detail: walk `$orderedLineIds` (ONE component's lines in SortOrder). `partSet(line)` = sorted list of `partId` where `bg === false` (background rows are invisible to runs). A run starts at index 0 and wherever `partSet !== partSet(prev)`. Lines with an EMPTY partSet form runs too (`partIds: []`) so the consumer can render "(everyone)" or nothing — but a run with `partIds: []` at index 0 is OMITTED (a song with no parts must derive `[]`, keeping every existing render byte-identical). Output `list<{startIndex, endIndex, startLineId, endLineId, partIds: list<int>}>`. Fixture-tested in BOTH languages from ONE JSON file (§9.2).

### 2.5 Sub-line spans (Pass 1's tblLyricLineVocalSpans)

```php
function vocalPartsSpanUpsert(\mysqli $db, string $songId, array $input): array   // {id?, lineId, partId, start, end, isBackground?, sortOrder?}
```
Line via `lineEnrichmentResolveLine()` (null → RuntimeException); part via resolvePart, same-version check; `0 <= start < end <= cpLen` else 400 (code points, `mb_strlen`); `start === 0 && end === cpLen` → 400 `'A whole-line span is a line assignment — use the line control.'` (one representation per case). Update path re-validates against the CURRENT line text. Returns `{id, lineId, partId, start, end, bg, sortOrder}`. `Source='ihymns'` on create.
```php
function vocalPartsSpanDelete(\mysqli $db, string $songId, int $spanId): bool
function vocalPartsSpansMap(\mysqli $db, int $lyricsId): array   // { <lineId>: list<{id, partId, start, end, bg, sortOrder}> } ORDER BY LineId, StartOffset, SortOrder; [] when !vocalPartsSpansReady()
```
Span drift on edit: `lyricLinesApplyDesired()` keeps the Id but may change the text; a span whose `end > mb_strlen(newText)` is CLAMPED at read (`vocalPartsSpansMap` returns `end = min(end, cpLen)` and drops a span whose `start >= cpLen`), never thrown — and the snapshot (§7) records the pre-edit text so nothing is silently unrecoverable.

### 2.6 Word grain (D2)

```php
function vocalPartsResolveWords(\mysqli $db, int $lyricsId, array $wordIds): array
// one query: SELECT w.Id, w.LineId FROM tblLyricWords w JOIN tblLyricLines ll ON ll.Id = w.LineId WHERE w.Id IN (…) AND ll.LyricsId = ?  → count mismatch = RuntimeException
function vocalPartsAssignWords(\mysqli $db, int $lyricsId, array $assignments): int
// $assignments = list<{wordId:int, partId:int, bg:bool, sortOrder?:int}>; ingest-only today (curator versions never have tblLyricWords rows — D2: curator word ENTRY is later work). Parts checked to belong to $lyricsId. INSERT … ON DUPLICATE KEY UPDATE IsBackground=VALUES(IsBackground), SortOrder=VALUES(SortOrder) on uq_Word_Part. Returns rows written.
function vocalPartsWordsMap(\mysqli $db, int $lyricsId): array   // { <wordId>: list<{partId, bg, sortOrder}> }
function vocalPartsWordsForLines(\mysqli $db, array $lineIds): array   // { <lineId>: list<{wordId, sortOrder, text, parts: list<{partId,bg}>}> } — the read the Present surface uses for per-word voices; JOIN tblLyricWords; [] when no words
```
Read rule (schema comment, now enforced in the READ helper): a word WITH rows overrides its line's parts; a word with none inherits. `vocalPartsWordsForLines()` emits ONLY words that have rows (so the consumer applies the inherit rule by absence) — keeps the payload tiny for the 99.9 % of lines with none.

### 2.7 Bulk read — THE one payload

```php
function vocalPartsForSong(\mysqli $db, string $songId, array $opts = []): array
```
Returns (every key ALWAYS present; empty when not ready or no rows):
```
{
  parts:  list<partShape>,                       // vocalPartsForVersion(primaryLyricsId)
  lines:  { lineId: list<{partId,bg,sortOrder}> },
  spans:  { lineId: list<{id,partId,start,end,bg,sortOrder}> },
  runs:   { componentId: list<run> },            // vocalPartsDeriveRuns per component, from lyricLinesFetchPrimary() rows (cid ?? 0)
  words:  { lineId: list<…> },                   // ONLY when $opts['words'] === true (ingested versions); else {}
  rounds: list<roundShape>,                      // lyricRoundsForVersion(); [] when !lyricRoundsReady()
}
```
Version = `lyricLinesPrimaryLyricsId($db, $songId)` (§2.9); 0 → everything empty. Short-circuit: `vocalPartsVersionHasAny()` (`SELECT 1 FROM tblLyricLineVocalParts WHERE LyricsId=? LIMIT 1` UNION the spans table when ready, UNION `tblVocalParts`) false → return the empty shape without running the other queries. Consumers: `api2 load_song` (as `vocalParts`), `api.php song_detail` include blocks (§4.2), `includes/pages/song.php` (the render pass), `SongData::getSongDetailExtras`.

```php
function vocalPartsVersionHasAny(\mysqli $db, int $lyricsId): bool
```

### 2.8 Ingest entry points (called by lyrics_ingest.php inside ITS transaction — §6)

```php
function vocalPartsIngestApply(\mysqli $db, int $lyricsId, string $source, array $agents, array $lineRefs, array $wordRefs): array
// $agents  = { agentId: {type:?string, name:?string, meta:array} }   (from <head>)
// $lineRefs = list<{lineId:int, agentId:?string, bg:bool}>
// $wordRefs = list<{wordId:int, agentId:?string, bg:bool}>
// returns {parts:int, lines:int, words:int, pruned:int}
```
Steps: (1) for each DISTINCT agentId referenced or defined → `vocalPartsFindOrCreate($db, $lyricsId, vocalPartsKindFromTtmlAgent($agents[$id] ?? null), $agents[$id]['name'] ?? null, $source, $id, null, null, $agents[$id]['meta'] ?? null)`; (2) a ref with `agentId === null && bg === true` → the version's synthetic backing part (`findOrCreate(kind 'backing', null, $source, ttmlAgentId '_bg')` — `_bg` is a reserved handle so re-ingest is idempotent; never exported as an agent id); (3) line rows via one multi-row INSERT `(LineId, VocalPartId, LyricsId, IsBackground, SortOrder)`; (4) word rows via `vocalPartsAssignWords()`; (5) PRUNE: `DELETE FROM tblVocalParts WHERE LyricsId=? AND Source=? AND TtmlAgentId IS NOT NULL AND TtmlAgentId NOT IN (…current…)` (machine-owned rows only — a curator's Source='ihymns' part on that version is never touched). Wrapped in NO transaction of its own (the caller holds one — the lyricsIngest_storeExternalIds() lesson at lyrics_ingest.php:770).

```php
function vocalPartsKindFromTtmlAgent(?array $agent): string
```
`type` `person` → `'lead'` for the FIRST person agent in document order, `'soloist'` for later ones? No — deterministic and simple: `person` → `'lead'`; `group` → `'group'`; `other` → `'duet'`; `character` → `'named-singer'` (with SingerName = ttm:name); `organization` → `'choir'`; missing/unknown → `'lead'`. The agent's `ttm:name` becomes the Label. A curator re-kinds after ingest if needed; the TTML type set is too coarse to guess more (say so in the doc-block).

### 2.9 Shared resolver (edit to `includes/lyric_lines_read.php`; #2076)

```php
function lyricLinesPrimaryLyricsId(\mysqli $db, string $songId): int
```
`SELECT Id FROM tblLyrics WHERE SongId = ? AND Source = 'ihymns' LIMIT 1`; memoised per request in a static map keyed by songId; 0 when absent. `SongData::_primaryLyricsId()` becomes `$id = lyricLinesPrimaryLyricsId(...); return $id > 0 ? $id : <existing approved/IsPrimary fallback>` so `include=translations/annotations/vocalParts` and the components' `lineIds` agree.

---

## 3 — ROUNDS CORE contracts (`appWeb/public_html/includes/lyric_rounds.php`; Pass 1's tables `tblLyricRounds` + `tblLyricRoundVoices`)

Columns relied on (must exist in Pass 1's DDL — the implementer checks the Pass 1 migration and adds any that are missing THERE, in the same one-pass batch, never a later ALTER): `tblLyricRounds(Id, LyricsId, Kind VARCHAR(20), Label VARCHAR(120) NULL, StartLineId, EndLineId, Repeats INT NULL, EndingMode VARCHAR(20), CodaStartLineId NULL, CodaEndLineId NULL, Bpm DECIMAL(6,2) NULL, BeatsPerBar TINYINT NULL, BeatsPerLine DECIMAL(6,2) NULL, Source VARCHAR(100), SortOrder, MetaJson)`; `tblLyricRoundVoices(Id, RoundId FK CASCADE, VoiceNumber TINYINT, PartId FK tblVocalParts SET NULL, Label NULL, EntryLines INT NOT NULL DEFAULT 0, EntryBeats DECIMAL(8,2) NULL, EntryMs INT NULL, IntervalSemitones TINYINT NULL, StartLineId NULL, EndLineId NULL, Repeats INT NULL, SortOrder, UNIQUE (RoundId, VoiceNumber))`. All FKs to `tblLyricLines` are `ON DELETE CASCADE` (a round whose subject line is deleted is gone — the §7 snapshot records it first).

```php
const IHYMNS_ROUND_KINDS   = ['round', 'canon', 'partner-song'];
const IHYMNS_ROUND_ENDINGS = ['complete', 'together', 'coda'];
function lyricRoundsReady(\mysqli $db): bool
function lyricRoundsForVersion(\mysqli $db, int $lyricsId): array          // list<roundShape> ORDER BY SortOrder, Id; voices nested ORDER BY VoiceNumber
function lyricRoundShape(array $round, array $voices, array $partsById): array
// {id, kind, label, startLineId, endLineId, repeats:?int, endingMode, codaStartLineId:?int, codaEndLineId:?int, bpm:?float, beatsPerBar:?int, beatsPerLine:?float, source, sortOrder,
//  voices: list<{id, number:int, partId:?int, label:?string, displayLabel:string, entryLines:int, entryBeats:?float, entryMs:?int, intervalSemitones:?int, startLineId:?int, endLineId:?int, repeats:?int, sortOrder}>}
//  displayLabel = voice Label ?? partsById[partId].displayLabel ?? "Voice N"
function lyricRoundUpsert(\mysqli $db, string $songId, array $input, ?int $userId): array
```
Validation → InvalidArgumentException: kind/endingMode not in map; `startLineId`/`endLineId` resolved TOGETHER via `vocalPartsResolveLines()` (same song, primary version) and `SortOrder(end) >= SortOrder(start)`; voices non-empty, ≤8, `number` exactly 1..N contiguous; voice 1 `entryLines === 0` and `entryBeats/entryMs ∈ {null, 0}`; every other voice `entryLines >= 0`; a voice's own `startLineId`/`endLineId` both-or-neither and resolved the same way; `codaStart/End` present IFF `endingMode === 'coda'`; `bpm > 0`, `beatsPerBar ∈ 1..16`, `beatsPerLine > 0` when given; `partId` (nullable) via `vocalPartsResolvePart()` same version. Voices are REPLACED as a set (DELETE voices whose `number` is absent; upsert by `(RoundId, VoiceNumber)`). Returns the shape. Ownership → RuntimeException.
```php
function lyricRoundDelete(\mysqli $db, string $songId, int $roundId): bool
function lyricRoundSubjectLineIds(array $round, array $orderedLineIds): array     // PURE slice start..end inclusive; [] if either missing
function lyricRoundTimeline(array $round, array $subjectLineIds, ?array $lineTimingsMs = null): array   // PURE (D1)
```
Timeline output: `{unit: 'ms'|'beats'|'lines', voices: list<{number, partId, displayLabel, offset: number, events: list<{lineId, index, at: number}>}>, length: number}`. Unit resolution, per voice, FIRST that is computable: `ms` when `$lineTimingsMs` is a full map `lineId ⇒ {startMs,endMs}` for the subject AND (`entryMs !== null` OR (`entryBeats !== null` AND `bpm`) OR (`entryLines` AND every subject line has timing)) → `at = lineStartMs + offsetMs` where `offsetMs = entryMs ?? entryBeats*60000/bpm ?? startMs(subject[entryLines]) - startMs(subject[0])`; else `beats` when `bpm && beatsPerLine` → `at = index*beatsPerLine + (entryBeats ?? entryLines*beatsPerLine)`; else `lines` → `at = index + entryLines`. The unit is chosen ONCE for the whole round (the weakest unit any voice needs), so every voice's track is comparable; `repeats` (round-level, default 1; voice-level overrides) unrolls the events; `endingMode 'together'` truncates every voice at `length = max(at of voice 1's last event)`; `'coda'` appends the coda lines once for every voice at `length`; `'complete'` lets each voice finish. Truth-tabled in §9.1.

---

## 4 — API TWINS (rule #48)

### 4.1 `manage/editor/api2.php` — new cases (POST-JSON unless noted; the file-level editor-role gate + `X-Requested-With`/Bearer CSRF gate apply; every WRITE also calls `ed2_requireEntitlement('edit_songs')`; every handler: `songId` required → 400, `ed2_songExists` → 404, `vocalPartsTablesReady($db)` false → `ed2_respond(['ok'=>false,'error'=>'Vocal-parts tables are not migrated.'], 409)`, `begin_transaction`, InvalidArgumentException→400, RuntimeException→404, rethrow Throwable after rollback, `logActivity('song.vocal.<x>', 'song', $songId, {...})`):

| action | body | core | response |
|---|---|---|---|
| `vocal_parts` (GET-safe read; add to the GET allow-list) | `?id=<songId>&words=0|1` | `vocalPartsForSong` | `{ok, vocalParts:<payload>, vocalPartKinds:<projection>}` |
| `vocal_part_upsert` | `{songId, part:{id?, kind, label?, singerName?, gender?, musicianId?, sortOrder?}}` | `vocalPartsUpsert` | `{ok, part}` |
| `vocal_part_delete` | `{songId, id}` | `vocalPartsDelete` | `{ok, deleted:0|1}` |
| `vocal_assign_lines` | `{songId, lineIds:[…], partIds:[…], mode?:'replace'|'add', isBackground?:bool}` | `vocalPartsAssignLines` | `{ok, lines, runs}` |
| `vocal_clear_lines` | `{songId, lineIds, scope?:'voice'|'echo'|'all'}` | `vocalPartsClearLines` | `{ok, cleared:int, lines, runs}` |
| `vocal_span_upsert` / `vocal_span_delete` | `{songId, span:{…}}` / `{songId, id}` | span fns (409 when `!vocalPartsSpansReady`) | `{ok, span}` / `{ok, deleted}` |
| `vocal_round_upsert` / `vocal_round_delete` | `{songId, round:{…}}` / `{songId, id}` | round fns (409 when `!lyricRoundsReady`) | `{ok, round}` / `{ok, deleted}` |

`load_song` gains `vocalParts: vocalPartsForSong($db,$songId)` and `vocalPartKinds: vocalPartsKindsProjection()` (like `lineTranslations`, outside `ed2_buildSongSnapshot()` so a revision snapshot never carries them). NOT a component-content change → no revision row for any vocal_* action (identical to the enrichment siblings). D3 flow is §0/decision 12.

The Pass-1 review-queue actions (`vocal_suggestions_list` [GET-safe], `vocal_suggestions_run`, `vocal_suggestion_apply`, `vocal_suggestion_dismiss`) follow the same handler shape, gate `edit_songs`, and map `409 {reason:'stale'|'sibling_pending'}` exactly as Pass 1 §1.4 specifies — the client branches on `err.status === 409 && body.reason`, never on prose.

### 4.2 `api.php` (public/native)
- `song_detail?include=` gains three allow-listed blocks in `SongData::songDetailIncludeBlocks()`: `vocalLines`, `vocalSpans`, `vocalRounds` (emitting `vocalPartsLinesMap`/`vocalPartsSpansMap`/`lyricRoundsForVersion` for the resolved version; also `vocalRuns` = `runs`). `vocalParts` (existing list) gains the additive fields `displayLabel`, `ttmlAgentId`, `sortOrder` — ORDER and existing keys unchanged. `access_resolver.php:128`'s strip list becomes `['components','translations','annotations','vocalParts','vocalLines','vocalSpans','vocalRuns','vocalRounds']`; `gating_rules.php:135` gains matching labels. Verified no-op: goldens carry none of the new keys.
- New GET `vocal_part_kinds` → `{kinds: vocalPartsKindsProjection()}`, cacheable, no auth.
- api-docs.yaml: one path item per new action, modelled on `/manage/editor/api2.php?action=line_translation_upsert` (:22467), responses 200/400/404/409 with the SAME 409 description wording pattern; `song_detail`'s `include` enum/example extended.

### 4.3 Coverage + parity guards
`tests/php/test-manage-action-api-coverage.php`: the future `/manage/vocal-suggestions.php` page's actions map to `api:vocal_suggestion_apply` etc. (added with that page); `tests/php/test-api-gate-parity.php` must see `ed2_requireEntitlement('edit_songs')` on every `vocal_*` write case.

---

## 5 — `#2071` OpenLyrics import/export hooks (data contract only; the importer pass implements)
- `_bulkImport_openLyricsParseLines()` (song_importers.php:3113) captures `part` and `repeat` from the `<lines>` start tag BEFORE the strip at :3120 (`preg_match('#^<lines\b([^>]*)>#i', $inner, $m)` then attribute parse of `$m[1]`) and returns them as `['part'=>?string,'repeat'=>?int]` alongside each physical line. The verse-level caller resolves `vocalPartsKindFromWord($part)` → `vocalPartsFindOrCreate(... 'openlyrics' ...)` and assigns the block's lines AFTER `lyricLinesWriteComponents()` has minted Ids (re-read via `lyricLinesEditableComponents()`'s `lineIds`, matched by position). An unknown word → §1.3's `group` fallback.
- Exporter (format-export.js:389-395) emits `<lines part="…">` per DERIVED run when the run has parts, and attribute-less `<lines>` chunks otherwise — the run boundaries are additional chunk boundaries (a chunk never spans two runs). The keyword comes from the server payload's `parts[].exportKeywords.openlyrics` (add `exportKeywords: {openlyrics, marker}` to `partShape` for the EDITOR shape only — `vocalPartsShape(..., $withExport=true)`), so JS never derives it.

## 6 — TTML word grain (D2) — `includes/lyrics_ingest.php`

### 6.1 Parser `lyricsIngest_parseTtml()` additions
- After the root check: read `<head>` agents — `$doc->getElementsByTagNameNS('*','agent')` filtered to elements whose `_ttmlLocalName === 'agent'` and that sit under a `head` ancestor; each → `$agents[xml:id] = ['type' => attr 'type' (lower) ?: null, 'name' => first child `name` element textContent (trimmed, ≤120 cp) ?: null, 'meta' => every attribute as qname ⇒ value]`. `xml:id` read via `$el->getAttributeNS('http://www.w3.org/XML/1998/namespace','id') ?: $el->getAttribute('xml:id')`. Emit `'agents' => $agents` in the return (always present, `[]` when none — the writer tolerates absence for old callers).
- Each line gains `'agent' => ?string` (from `ttm:agent` on the `<p>`, else on the enclosing `<div>` — Apple puts duet agents on `<p>`, but some tools put one on `<div>`; walk up ONE level) and `'bg' => bool` (`ttm:role === 'x-bg'` on `<p>`).
- FIX 1 (leaf-span meta): in the `else` branch (~:193), `$cur['meta'] = array_merge($cur['meta'] ?? [], _ttmlMeta($node) ?? [])` in ADDITION to the syllable meta, so dropping the 1:1 syllable keeps it.
- FIX 2 (word-group container): before treating a `<span>` with child spans as "a word with syllables", call `_ttmlIsWordGroup($node)`: true when ANY direct child TEXT node between two child spans is whitespace-only, OR when any child span itself has child spans. When true: flush, then recurse over `$node->childNodes` with the SAME word-building loop (extract the loop body into `_ttmlWalkWords(\DOMNode $container, array $inheritMeta, callable $newWord, &$words, &$cur, $flush)`), passing `$inheritMeta = _ttmlMeta($node)` which is merged UNDER each produced word's own meta (`$word['meta'] = array_merge($inheritMeta, $own)`). The container's `begin/end` are ignored (its words carry their own).
- Each word gains `'agent' => ?string`, `'bg' => bool` normalised from its (merged) meta (`ttm:agent`, `ttm:role === 'x-bg'`) — the writer reads these keys, never re-parses meta.

### 6.2 Word-count invariant
A word's `agent`/`bg` that equals the line's is REDUNDANT (the inherit rule) — the writer skips emitting a word row for it, so the word table stays sparse.

### 6.3 Writer `lyricsIngest_writeToDb()` (inside the existing transaction, before `$lineStmt->close()`)
Collect `$lineRefs[] = ['lineId'=>$lineId, 'agentId'=>$line['agent'] ?? null, 'bg'=>!empty($line['bg'])]` when agent or bg set; `$wordRefs[]` likewise for words whose (agent,bg) differ from the line's. After the loops: `require_once vocal_parts.php; if (vocalPartsTablesReady($db)) { $vp = vocalPartsIngestApply($db, $lyricsId, $source, $parsed['agents'] ?? [], $lineRefs, $wordRefs); }` — gated (fail-open on an un-migrated install, identical bytes stored otherwise), NON-BLOCKING is NOT appropriate here (it is inside the ingest's own transaction and a failure must roll the whole ingest back, so the file is never half-ingested — say so in the comment). Return gains `'vocalParts' => $vp ?? null`; `api.php`'s `lyrics.ingest` log line adds it.

## 7 — Deletion snapshot (edit `lyricLinesSnapshotDeletedEnrichment()` in lyric_lines_sync.php)
When `vocalPartsTablesReady()`: also SELECT `tblLyricLineVocalParts (Id, LineId, VocalPartId, IsBackground)` for the delete ids, `tblLyricLineVocalSpans (Id, LineId, VocalPartId, StartOffset, EndOffset, IsBackground)` when spans are ready, and (when rounds are ready) `tblLyricRounds`/`tblLyricRoundVoices` rows whose Start/End/Coda line ids intersect; add `'vocalLines' / 'vocalSpans' / 'rounds'` keys to the snapshot JSON; the early-return `empty($trans) && empty($annos)` extends to the new arrays. Same never-throw / transaction-fatal posture.

## 8 — `#2072` Note preserve-on-omit (data contract)
`lyricLinesBuildDesiredFromComponents()`'s `$noteVal` becomes: when the component payload carries NO `notes` key at all (`!array_key_exists('notes', $c)`) → `'Note' => LYRIC_LINES_KEEP` (a sentinel const) and `lyricLinesApplyDesired()` substitutes the EXISTING row's Note for the sentinel on UPDATE (and NULL on INSERT); `lyricLinesRowClean()` treats the sentinel as equal. An explicit `notes: [null,…]` still clears — same omitted-means-preserve / null-means-clear contract as rule #45's Label. `MetaJson` is not projected at all (stays untouched) — verified: it is absent from both the INSERT and UPDATE column lists at lyric_lines_sync.php:268-280.

## 9 — CI guards (all tree-derived or truth-tabled, mutation-proven per rule #34)
### 9.1 `tests/php/test-vocal-parts.php` (pure; requires db_mysql.php + line_enrichment.php + vocal_parts.php + lyric_rounds.php, no connection)
- vocabulary: every key has non-empty `label`, `description`, `openlyrics` (or null only for `named-singer`), `ttmlAgent ∈ {person,group,other}`; `markers` keys are UPPER-CASE, unique ACROSS kinds, and none equals a section word (§1.5 list) or a `tblSongPartTypes` seed name read from `migrate-song-part-types.php` (tree-derived); aliases point at real keys; `vocalPartsNormalizeKind('MAIN')==='lead'`, `('men')==='male'`, `('nope')===null`; `vocalPartsKindFromWord('MEN    ')`, `('Group 2')`, `('2nd')`, `('ladies:')`, `('HALLELUJAH')===null`, `('CHORUS')===null`; `vocalPartsExportKeyword` for a `group` part with two siblings → `group2`; `vocalPartsTtmlAgent` xml:id fallback `v3`.
- `vocalPartsDeriveRuns` from `tests/fixtures/vocal-runs.json` (§9.2).
- `lyricRoundTimeline` truth table: 3-voice round, entryLines 0/2/4, unit `lines`; with bpm+beatsPerLine → `beats`; with full timings + entryMs → `ms`; `together` truncation; `coda` append; a voice-level `repeats` override.
- span validator: rejects whole-line, inverted, > cpLen, and clamps on read.
### 9.2 `tests/test-vocal-runs.js` — loads the SAME `tests/fixtures/vocal-runs.json` and runs `js/modules/vocal-runs.js`'s `deriveRuns()` (the client twin the editor uses); the fixture is the lockstep MECHANISM. Mutation proof: flip one expected `endIndex` in the fixture → both go red.
### 9.3 `tests/php/test-vocal-vocab-sites.php` — tree-derived: scans every `.php`/`.js` under `appWeb/` EXCEPT `includes/vocal_parts.php`, the two test files and `api-docs.yaml`; comment-stripped; FAILS if any file contains ≥3 distinct kind keys from the map (read from the source, not typed) as quoted string literals within a 400-char window (a second typed vocabulary), or the literal `'named-singer'` outside the core. Mutation: paste `['male','female','all']` into any module → red. Narrow enough that prose ("men and women") never trips it.
### 9.4 `tests/php/test-ttml-agents.php` — parses a fixture TTML (`tests/fixtures/ttml-agents.ttml`: two `<head>` agents v1 person/"Alice", v2 group; a `<p ttm:agent="v1">`; a `<p>` with a multi-word `<span ttm:role="x-bg">` container; a leaf `<span ttm:agent="v2">` word) and asserts `agents`, line `agent/bg`, the container producing THREE words all `bg:true`, and the leaf word carrying `agent:'v2'`. Each of the three parser fixes is mutation-proven by reverting it (documented in the file header).

## 10 — Migration + registry touches in this pass
- `appWeb/.sql/migrate-vocal-parts.php` (#2077): the `CREATE TABLE tblVocalParts` block becomes byte-identical to schema.sql:4503-4528 (`MusicianId`, `idx_Musician`, `fk_VocalParts_Musician` → `tblMusicians(Id)`); doc-block gains a note that the table pre-dates the musicians rename and now creates the post-rename shape directly; the existing `_migVP_tableExists` probe is untouched. The `vocal-parts` registry probe additionally returns pending when `!_migProbe_columnExists($db,'tblVocalParts','MusicianId')` is true AND `!_migProbe_columnExists($db,'tblVocalParts','CreditPersonId')` (i.e. only when neither exists — an old-shape install is the rename card's job and must not show two pending cards for one fix).
- Pass 1's `migrate-vocal-parts-rounds.php` registry entry is ordered AFTER `musicians-rename` and `vocal-parts`; its probe is the multi-object OR-probe over the four tables; includes resolve via `IHYMNS_INCLUDES_DIR` only if it ever needs one (pure DDL today — none).

## 11 — Plain-English annotation obligation
Every function above ships with the two-register doc-block (an ELI5 sentence + the detailed why with links: TTML2 §12 ttm:agent, OpenLyrics 0.8 `<lines part>`, MDN/PHP mb_* for code points, WCAG 1.3.1 for the run-header semantics the render pass will consume, the `#issue`). Legacy files touched get annotations on the touched lines only.

## Files
- `appWeb/public_html/includes/vocal_parts.php` (NEW) — NEW — the ONE voice-parts core: IHYMNS_VOCAL_PART_KINDS (22 keys with label/description/gender/markers/openlyrics/ttmlAgent), IHYMNS_VOCAL_PART_KIND_ALIASES, IHYMNS_VOCAL_GENDERS, IHYMNS_VOCAL_SOURCES_STRUCTURED, IHYMNS_VOCAL_GROUP_ORDINAL_RE; pure vocabulary fns (NormalizeKind, KindFromWord, DisplayLabel, ImpliedGender, ExportKeyword, TtmlAgent, KindsProjection, KindFromTtmlAgent); readiness gates (TablesReady incl. the MusicianId column, SpansReady); resolvers (ResolveLines bulk + primary-version pin, ResolvePart); registry (Shape, ForVersion, FindOrCreate, Upsert, Delete); line grain (AssignLines replace|add scoped by IsBackground, ClearLines, LinesMap, DeriveRuns PURE); spans (SpanUpsert/Delete/SpansMap with read-clamp); word grain (ResolveWords, AssignWords, WordsMap, WordsForLines); ForSong bulk payload + VersionHasAny; IngestApply. Modelled function-for-function on line_enrichment.php, same throw→status contract.
- `appWeb/public_html/includes/lyric_rounds.php` (NEW) — NEW (Pass 1's file, contracts pinned here) — IHYMNS_ROUND_KINDS/ENDINGS, lyricRoundsReady, lyricRoundsForVersion, lyricRoundShape, lyricRoundUpsert (full validation list §3, voices replaced as a set), lyricRoundDelete, lyricRoundSubjectLineIds (pure), lyricRoundTimeline (pure ms→beats→lines resolution, repeats/together/coda — the D1 projector input).
- `appWeb/public_html/includes/lyric_lines_read.php` — Add lyricLinesPrimaryLyricsId() (Source='ihymns', memoised) — the ONE version resolver (#2076).
- `appWeb/public_html/includes/SongData.php` — _primaryLyricsId() delegates to lyricLinesPrimaryLyricsId() with the old approved/IsPrimary query as fallback only; songDetailIncludeBlocks() gains 'vocalLines','vocalSpans','vocalRuns','vocalRounds'; getSongDetailExtras() emits them via the core; 'vocalParts' block gains additive displayLabel/ttmlAgentId/sortOrder (order + existing keys unchanged).
- `appWeb/public_html/includes/access_resolver.php` — Line ~128 strip list gains 'vocalLines','vocalSpans','vocalRuns','vocalRounds' (verified no-op against the goldens — they carry none of these keys).
- `appWeb/public_html/includes/gating_rules.php` — Field labels for the four new gated keys beside 'vocalParts' (:135).
- `appWeb/public_html/includes/lyrics_ingest.php` — Parser: read <head> ttm:agent defs into parsed['agents']; per-line 'agent'/'bg'; FIX 1 leaf-span meta merged onto the word; FIX 2 _ttmlIsWordGroup()+_ttmlWalkWords() so a multi-word ttm:role=x-bg container yields N words with inherited meta; per-word 'agent'/'bg'. Writer: collect lineRefs/wordRefs inside the transaction and call vocalPartsIngestApply() gated on vocalPartsTablesReady(); return gains 'vocalParts'.
- `appWeb/public_html/includes/lyric_lines_sync.php` — lyricLinesSnapshotDeletedEnrichment(): also snapshot tblLyricLineVocalParts / tblLyricLineVocalSpans / round rows for the deleted line ids (gated, never throws). #2072: LYRIC_LINES_KEEP sentinel — omitted `notes` key preserves tblLyricLines.Note on UPDATE (explicit null still clears); lyricLinesRowClean() treats the sentinel as equal.
- `appWeb/public_html/manage/editor/api2.php` — New cases vocal_parts (GET-safe), vocal_part_upsert/delete, vocal_assign_lines, vocal_clear_lines, vocal_span_upsert/delete, vocal_round_upsert/delete (+ the Pass-1 queue actions vocal_suggestions_list/run, vocal_suggestion_apply/dismiss) — each: songId→400, exists→404, TablesReady→409, ed2_requireEntitlement('edit_songs') on writes, transaction, 400/404 mapping, logActivity. load_song response gains vocalParts + vocalPartKinds (outside the snapshot builder).
- `appWeb/public_html/api.php` — song_detail include= wiring for the new blocks; new public GET vocal_part_kinds; lyrics.ingest log line carries the vocalParts counts.
- `appWeb/public_html/api-docs.yaml` — Path items for every new api2 action (200/400/404/409, modelled on line_translation_upsert at :22467), vocal_part_kinds, and song_detail's include enum/example.
- `appWeb/.sql/migrate-vocal-parts.php` — #2077 — CREATE TABLE tblVocalParts block made byte-identical to schema.sql (MusicianId / idx_Musician / fk_VocalParts_Musician → tblMusicians); doc-block note.
- `appWeb/public_html/manage/includes/migration-registry.php` — vocal-parts probe also pending when neither MusicianId nor CreditPersonId exists; Pass 1's vocal-parts-rounds entry ordered after musicians-rename + vocal-parts.
- `appWeb/public_html/includes/song_importers.php` — #2071 data contract: _bulkImport_openLyricsParseLines() captures <lines part=/repeat=> before the strip and returns them per line; #2075 hook point: the four marker→fake-refrain sites call vocalPartsKindFromWord() (the importer pass implements the assignment after lyricLinesWriteComponents()).
- `appWeb/public_html/manage/editor/format-export.js` — OpenLyrics: emit <lines part="…"> per derived run (run boundaries are chunk boundaries) using parts[].exportKeywords.openlyrics from the server payload; attribute-less <lines> for un-parted chunks.
- `appWeb/public_html/js/modules/vocal-runs.js` (NEW) — NEW — deriveRuns() client twin of vocalPartsDeriveRuns() (ES module), consumed by the editor's live run headers; kept in lockstep by the shared fixture.
- `tests/fixtures/vocal-runs.json` (NEW) — NEW — shared PHP↔JS truth table for run derivation (adjacent identical sets merge; bg rows ignored; empty leading run omitted; scattered ids → several runs).
- `tests/php/test-vocal-parts.php` (NEW) — NEW — pure truth tables: vocabulary invariants (marker uniqueness, no section words, tree-derived tblSongPartTypes seeds), normaliser/word resolver, export keywords, TTML agent fallback ids, deriveRuns fixture, round timeline, span validator/clamp.
- `tests/test-vocal-runs.js` (NEW) — NEW — runs the same fixture against vocal-runs.js (lockstep mechanism).
- `tests/php/test-vocal-vocab-sites.php` (NEW) — NEW — tree-derived, comment-stripped ban on any second typed kind vocabulary outside includes/vocal_parts.php (≥3 kind keys as literals in a 400-char window, or 'named-singer' anywhere else); mutation-proven.
- `tests/php/test-ttml-agents.php` (NEW) — NEW — parses tests/fixtures/ttml-agents.ttml; asserts head agents, line agent/bg, the multi-word x-bg container → three bg words, the leaf word keeping its agent; each parser fix mutation-proven.
- `tests/fixtures/ttml-agents.ttml` (NEW) — NEW — reference-shaped Apple-style TTML with two head agents, a duet line, a multi-word x-bg span container and a leaf agent span.
- `tests/php/test-api-gate-parity.php` — Sees ed2_requireEntitlement('edit_songs') on every vocal_* write case (no code change expected if it is already tree-derived; verify it goes red when one call is removed).
- `appWeb/.sql/schema.sql` — No change in THIS pass (Pass 1's four new tables land with its migration; tblVocalParts is already MusicianId here).

## Risks
- SILENT half-migrated install: an install that ran the ORIGINAL migrate-vocal-parts.php after musicians-rename has tblVocalParts.CreditPersonId; vocalPartsTablesReady() answers false there, so every vocal_* write 409s and every read is empty — correct, but the ONLY visible signal is the registry card. If the probe change in §10 is skipped, the card stays green and the feature looks dead with nothing red anywhere (the #1565 class). Mutation-test the probe.
- Re-ingest wipes assignments by CASCADE (lyrics_ingest.php:313 DELETEs every line) and this spec re-creates them from the file — but a CURATOR assignment made on a TTML version (Source='ihymns' part pinned to an applemusic-ttml line) would be lost; the primary-version pin in vocalPartsResolveLines() is what prevents that write in the first place. Do not relax the pin.
- vocalPartsAssignLines() mode 'replace' deletes rows for the SAME IsBackground class only. A UI that sends isBackground=true with the intent 'this line is ONLY an echo' will keep the existing voice rows — the editor pass must send a clear_lines(scope 'voice') first if that is the gesture. Document in the endpoint's api-docs entry.
- The uq_Line_Part key forbids the same part being both voice and echo on one line; an ON DUPLICATE KEY UPDATE silently flips IsBackground instead. Acceptable (an echo of yourself is meaningless) but must be stated in the doc-block so nobody 'fixes' it by adding IsBackground to the unique key — that would be an ALTER (rule #20).
- Text-marker export for formats with no voice concept (PP7/OpenSong/.txt) is NOT enabled by this pass — only the canonical marker word is defined. If a later pass turns it on by default, every export of a part-bearing song gains lines, and the corpus-fidelity sha256 (tools/export-fidelity-snapshot.php) changes; gate it behind an export option and re-snapshot deliberately.
- The `group<N>` OpenLyrics keyword is ordinal by (SortOrder, Id) — re-sorting parts in the editor changes which stored part is 'group1' on export. Structure still round-trips; only the numbering label moves. Say so in the editor pass's UI help.
- vocalPartsIngestApply() runs INSIDE the ingest transaction on purpose (a failure rolls the whole ingest back). A DB error in the vocal tables therefore fails a lyrics ingest that used to succeed on un-migrated installs — only when vocalPartsTablesReady() is true, so the blast radius is 'migrated but broken', which SHOULD fail loudly.
- Guard 9.3's fingerprint (≥3 kind keys as literals in a 400-char window) could false-positive on a future legitimate consumer that switches on several kinds (e.g. a CSS class map keyed by kind). The escape is to consume vocalPartsKindsProjection() or the served vocalPartKinds — which is the rule anyway. Keep the window narrow; do not widen the ban to single words.
- lyricLinesPrimaryLyricsId() memoises per request; a handler that CREATES the 'ihymns' version (lyricLinesEnsurePrimaryVersion) after the memo was populated with 0 must invalidate it — give the memo a `vocalPartsForgetPrimary($songId)` hook called from lyricLinesEnsurePrimaryVersion()'s INSERT branch, or the same request's read returns empty. Add a test.
- Span offsets are clamped at READ after an edit shrinks a line, never rewritten — a later edit that re-lengthens the line resurrects the original span. Acceptable and documented; the deletion snapshot (§7) plus tblLyricLineVocalSpans.MetaJson (original bracketed text) make the intent recoverable.
---

# Design pass 3

## Summary
Read-path design for voice parts (runs + duet + echo + spans), rounds and word grain: the ONE pure fold lives in includes/lyric_lines_read.php (`lyricLinesFoldVoiceRuns()`), fed by song-keyed fetchers in includes/vocal_parts.php that are gated by memoised INFORMATION_SCHEMA probes. PUBLIC component shape gains two SPARSE keys (`voices`, `voiceSpans`) appended after `lineLanguages`, so every existing fixture and all ~16k fidelity hashes stay byte-identical (the corpus stores zero voice rows today). The EDITOR per-component shape is UNCHANGED (11 keys); the editor instead gets an ALWAYS-present top-level `vocalParts` sidecar on load_song (api2 + classic), exactly like `lineTranslations`. Voice data is LYRIC BODY for gating: runs/spans ride inside `components` (stripped for free), and the include blocks `vocalParts` (already stripped at access_resolver.php:128 — verified), plus the two new blocks `rounds` and `vocalWords`, are added to the same strip list with a tree-derived guard tying SongData's version-keyed block list to that strip list. Version resolution keys on `Source='ihymns'` via a new `lyricLinesPrimaryLyricsId()` so voice rows line up with the `lineIds` the assembler emits. Four corrections to the established research, two of them pre-existing bugs found on the way (the `include=translations` key collision and the IsPrimary-vs-Source version mismatch).

## Spec
## 0 — What this pass owns

The READ path only: the assembler fold, the two read shapes, the existence gates, the gating class, and what `song_detail` / `songbook_export` / `load_song` (api2 + classic) emit, plus api-docs. It designs TO Pass 1's tables (the #1137 trio already in `schema.sql`, plus Pass 1's `tblLyricLineVocalSpans`, `tblLyricRounds`, `tblLyricRoundVoices` in the one migration `appWeb/.sql/migrate-vocal-parts-rounds.php`) and TO Pass 2's `includes/vocal_parts.php` / `includes/lyric_rounds.php` cores. Where this pass needs a column, §7 lists the exact column names the SELECTs use so Pass 1's DDL and this read cannot drift.

All paths are under `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/appWeb/public_html/` unless stated.

Verified facts this design rests on (all read from the tree 2026-09-04):
- `lyricLinesAssembleFromRows()` is pure; the wrappers `lyricLinesAssembleComponents()` / `…Map()` fetch via `lyricLinesFetchPrimary[Map]()` which JOIN `tblLyrics ly … WHERE ly.Source = 'ihymns'` (lyric_lines_read.php:298-388).
- Public shape key order today: `type, number, lines, chords, language, [label], lineIds, [lineLanguages]` (lyric_lines_read.php:196-221).
- `tools/export-fidelity-snapshot.php:78-81` hashes `json_encode(lyricLinesAssembleComponents($db,$songId), JSON_UNESCAPED_UNICODE)` per song.
- `accessApplySong()` strips `['components','translations','annotations','vocalParts']` on lyric-body denial (access_resolver.php:128). **Verified — research correct.**
- `SongData::getSongDetailExtras()` resolves the version with `_primaryLyricsId()` = `Status='approved' ORDER BY IsPrimary DESC, Id` (SongData.php:3165) — NOT Source='ihymns'.
- api2 `load_song` attaches `lineTranslations`/`lineAnnotations` in the case, not in `ed2_buildSongSnapshot()` (api2.php:2247-2260). Classic editor nests them into `$song` (manage/editor/api.php:385-387).
- Nothing reads `tblLyricWords` today (only lyrics_ingest.php + lyric_lines_sync.php write it).

---

## 1 — The pure fold (lyric_lines_read.php)

### 1.1 New pure function

```php
/**
 * ELI5: turn "line 3 = Women, line 4 = Women, line 5 = Men" into "lines 3–4 Women, line 5 Men".
 * WHY: storage is one row per (line, part) — the sung unit is a RUN of lines. Folding once here
 * means no consumer re-implements it (rule #22). PURE — unit-tested in test-lyric-lines-read.php.
 *
 * @param list<int> $lineIds   the component's lineIds, in order
 * @param array<int, list<array{id:int,kind:string,label:string,bg:bool}>> $voicesByLine
 *        lineId => parts on that line, ALREADY ordered (join SortOrder, part SortOrder, part Id)
 * @return list<array{from:int,to:int,parts:list<array{id:int,kind:string,label:string,bg:bool,enters:bool}>}>
 */
function lyricLinesFoldVoiceRuns(array $lineIds, array $voicesByLine): array
```

Algorithm (exact):
```
runs = []; cur = null; prevSig = null; prevIds = []  // prevIds = part ids of the run that ended on line i-1
for i, lineId in lineIds:
    parts = voicesByLine[lineId] ?? []
    if parts === []:
        cur = null; prevSig = null; prevIds = []      // a gap CLOSES the run and resets adjacency
        continue
    sig = implode('|', map(parts, p => p.id . ':' . (p.bg ? 1 : 0)))   // order as given — never re-sorted here
    if cur !== null && sig === prevSig:
        cur['to'] = i                                  // extend
        continue
    // new run: enters = part id not present in the run that ended on line i-1
    out = []
    for p in parts: out[] = ['id'=>p.id,'kind'=>p.kind,'label'=>p.label,'bg'=>p.bg,'enters'=>!in_array(p.id, prevIds, true)]
    prevIds = ids of the run just closed (cur ? cur part ids : [])   // computed BEFORE overwriting cur
    cur = ['from'=>i,'to'=>i,'parts'=>out]; runs[] = &cur (by index); prevSig = sig
return runs
```
Note the ordering subtlety: `prevIds` must be the ids of the run being CLOSED (adjacent, ends at i-1). Implement as: `$closingIds = $cur !== null ? array_column($cur['parts'],'id') : [];` computed before building `out`; a gap already reset `$cur` to null so a run after a gap has every part `enters:true`.

Worked truth table (component of 6 lines, lineIds [1..6]; W=part 10 female, M=part 11 male, E=part 12 backing):
| line | rows | sig | result |
|---|---|---|---|
| 1 | W | `10:0` | run A from=0 |
| 2 | W | `10:0` | A to=1 |
| 3 | W, M (duet) | `10:0|11:0` | run B from=2,to=2 parts [W enters:false, M enters:true] |
| 4 | W, E(bg) (echo) | `10:0|12:1` | run C from=3 parts [W enters:false, E bg:true enters:true] |
| 5 | (none) | — | gap: no run |
| 6 | E(bg) only | `12:1` | run D from=5 parts [E bg:true enters:true] (gap reset) |

### 1.2 Core signature change (byte-identical when defaults used)

```php
function lyricLinesAssembleFromRows(array $rows, array $voicesByLine = [], array $spansByLine = []): array
```
`$spansByLine`: `lineId => list<array{id:int,partId:int,kind:string,label:string,bg:bool,start:int,end:int}>` ordered by `StartOffset, SortOrder, Id`.

Inside `$flush`, AFTER the existing `lineLanguages` block and BEFORE `$components[] = $out;`:
```php
/* Voice runs (sparse — key present ONLY when this component has ≥1 run). */
if ($voicesByLine !== []) {
    $runs = lyricLinesFoldVoiceRuns($c['_lineIds'], $voicesByLine);
    if ($runs !== []) { $out['voices'] = $runs; }
}
/* Sub-line voice spans (sparse). `line` = index into lines; offsets are CODE POINTS (rule #21). */
if ($spansByLine !== []) {
    $sp = [];
    foreach ($c['_lineIds'] as $i => $lid) {
        foreach ($spansByLine[$lid] ?? [] as $s) {
            $sp[] = ['line'=>$i,'start'=>(int)$s['start'],'end'=>(int)$s['end'],
                     'part'=>['id'=>(int)$s['partId'],'kind'=>(string)$s['kind'],'label'=>(string)$s['label'],'bg'=>(bool)$s['bg']]];
        }
    }
    if ($sp !== []) { $out['voiceSpans'] = $sp; }
}
```
Resulting PUBLIC key order: `type, number, lines, chords, language, [label], lineIds, [lineLanguages], [voices], [voiceSpans]`. `$flush` must capture `$voicesByLine`/`$spansByLine` via `use (...)`.

### 1.3 Version resolver (NEW, the ONE Source='ihymns' read resolver)

```php
/** The Id of a song's canonical 'ihymns' tblLyrics version (the version lineIds come from), or 0.
 *  READ-ONLY twin of lyricLinesEnsurePrimaryVersion()'s SELECT half — never inserts. */
function lyricLinesPrimaryLyricsId(\mysqli $db, string $songId): int
{
    $stmt = $db->prepare("SELECT Id FROM tblLyrics WHERE SongId = ? AND Source = 'ihymns' LIMIT 1");
    $stmt->bind_param('s', $songId); $stmt->execute();
    $row = $stmt->get_result()->fetch_row(); $stmt->close();
    return $row !== null ? (int)$row[0] : 0;
}
```

### 1.4 Wrappers (lyric_lines_read.php)

```php
function lyricLinesAssembleComponents(\mysqli $db, string $songId): array
{
    $rows = lyricLinesFetchPrimary($db, $songId);
    if ($rows === []) { return []; }                        // unchanged early-out: zero extra queries for an empty song
    [$voices, $spans] = lyricLinesFetchVoices($db, [$songId]);
    return lyricLinesAssembleFromRows($rows, $voices[$songId] ?? [], $spans[$songId] ?? []);
}

function lyricLinesAssembleComponentsMap(\mysqli $db, array $songIds): array
{
    $rowsMap = lyricLinesFetchPrimaryMap($db, $songIds);
    if ($rowsMap === []) { return []; }
    [$voices, $spans] = lyricLinesFetchVoices($db, array_keys($rowsMap));
    $out = [];
    foreach ($rowsMap as $sid => $rows) {
        $out[$sid] = lyricLinesAssembleFromRows($rows, $voices[$sid] ?? [], $spans[$sid] ?? []);
    }
    return $out;
}

/**
 * ELI5: get every "who sings this line" row for these songs, or nothing at all if the tables aren't there yet.
 * Thin, gated adapter over the ONE voice core (rule #22): [] / [] on an un-migrated install so the
 * assembler output is byte-identical to today. Lazy require mirrors the line_enrichment.php pattern.
 * @return array{0: array<string, array<int, list>>, 1: array<string, array<int, list>>}  [voicesBySong, spansBySong]
 */
function lyricLinesFetchVoices(\mysqli $db, array $songIds): array
{
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'vocal_parts.php';
    if (!vocalPartsTablesReady($db)) { return [[], []]; }
    $voices = vocalPartsLinesMapForSongs($db, $songIds);
    $spans  = vocalPartsSpansReady($db) ? vocalPartsSpansMapForSongs($db, $songIds) : [];
    return [$voices, $spans];
}
```
`lyricLinesFetchPrimary[Map]()`, `lyricLinesEditableComponents()`, `lyricLinesFirstLine[Map]()`, `lyricLinesPreviewPhrase()` — **unchanged**.

---

## 2 — Song-keyed fetchers (includes/vocal_parts.php — additions to Pass 2's core)

```php
/** lineId => ordered parts, grouped by SongId, 'ihymns' version only, chunked IN() (#929). Rows carry the
 *  display label ALREADY resolved so the pure assembler needs no vocabulary. */
function vocalPartsLinesMapForSongs(\mysqli $db, array $songIds): array   // SongId => [lineId => list<{id,kind,label,bg}>]
```
SQL per chunk of `LYRIC_LINES_READ_CHUNK` (500):
```sql
SELECT ly.SongId AS song_id, lvp.LineId AS line_id, vp.Id AS part_id, vp.PartKind AS kind,
       vp.Label AS label, vp.SingerName AS singer_name, m.Name AS musician_name,
       lvp.IsBackground AS bg, lvp.SortOrder AS join_sort
  FROM tblLyricLineVocalParts lvp
  JOIN tblVocalParts vp ON vp.Id = lvp.VocalPartId
  JOIN tblLyrics ly      ON ly.Id = lvp.LyricsId
  LEFT JOIN tblMusicians m ON m.Id = vp.MusicianId
 WHERE ly.SongId IN (?,?,…) AND ly.Source = 'ihymns'
 ORDER BY ly.SongId, lvp.LineId, lvp.SortOrder, vp.SortOrder, vp.Id
```
Row → `['id'=>(int)part_id, 'kind'=>(string)kind, 'label'=>vocalPartsDisplayLabel(['label'=>label,'singerName'=>singer_name,'musicianName'=>musician_name,'kind'=>kind]), 'bg'=>(bool)bg]`. (`vocalPartsDisplayLabel()` is Pass 2's: `Label ?? SingerName ?? musicianName ?? IHYMNS_VOCAL_PART_KINDS[kind]['label']`, falling back to `ucfirst(kind)` for an unknown kind so an unmapped stored value never throws.)

```php
function vocalPartsSpansMapForSongs(\mysqli $db, array $songIds): array   // SongId => [lineId => list<{id,partId,kind,label,bg,start,end}>]
```
```sql
SELECT ly.SongId AS song_id, sp.Id AS span_id, sp.LineId AS line_id, vp.Id AS part_id, vp.PartKind AS kind,
       vp.Label AS label, vp.SingerName AS singer_name, m.Name AS musician_name,
       sp.IsBackground AS bg, sp.StartOffset AS start_off, sp.EndOffset AS end_off
  FROM tblLyricLineVocalSpans sp
  JOIN tblVocalParts vp ON vp.Id = sp.VocalPartId
  JOIN tblLyrics ly      ON ly.Id = sp.LyricsId
  LEFT JOIN tblMusicians m ON m.Id = vp.MusicianId
 WHERE ly.SongId IN (…) AND ly.Source = 'ihymns'
 ORDER BY ly.SongId, sp.LineId, sp.StartOffset, sp.SortOrder, sp.Id
```

```php
/** THE one editor/page payload (Pass 1 §1.1's vocalPartsForSong, shape fixed here). LISTS ONLY — never a
 *  lineId-keyed map (PHP json_encode turns an empty map into [] and the client would have to branch on type). */
function vocalPartsForSong(\mysqli $db, string $songId): array
// returns:
// [
//   'ready'       => bool,   // vocalPartsTablesReady()
//   'spansReady'  => bool,   // vocalPartsSpansReady()
//   'roundsReady' => bool,   // lyricRoundsReady()
//   'lyricsId'    => ?int,   // lyricLinesPrimaryLyricsId() or null when 0 / not ready
//   'parts'       => list<PartShape>,                                           // vocalPartsForVersion()
//   'lineAssignments' => list<{lineId:int, partId:int, bg:bool, sortOrder:int}>, // ORDER BY LineId, SortOrder, Id
//   'spans'       => list<{id:int, lineId:int, partId:int, bg:bool, start:int, end:int, sortOrder:int}>,
//   'rounds'      => list<RoundShape>,                                          // lyricRoundsForVersion() (Pass 1 §1.2), [] when !roundsReady
// ]
// Every list is [] and lyricsId null when !ready. NEVER throws: each family in its own try/catch → [] + error_log.
```
`PartShape` (Pass 1 §1.1 `vocalPartsShape()`): `{id:int, kind:string, label:?string, displayLabel:string, singerName:?string, gender:?string, musicianId:?int, ttmlAgentId:?string, source:string, sortOrder:int}`.

```php
/** Word-grain read (D2). Every tblLyrics version of the song that has ≥1 tblLyricWordVocalParts row. */
function vocalPartsWordsForSong(\mysqli $db, string $songId): array
// list<{
//   lyricsId:int, source:string,
//   parts: list<PartShape>,
//   lines: list<{lineId:int, sortOrder:int, text:string, startMs:?int, endMs:?int,
//                parts: list<{id:int,bg:bool}>,                    // line-level rows (tblLyricLineVocalParts) for THIS version
//                words: list<{wordId:int, sortOrder:int, text:string, startMs:?int, endMs:?int,
//                             parts: list<{id:int,bg:bool}>}>}>    // [] = INHERIT the line's parts (schema rule) — not expanded
// }>
```
Queries (all bound, one song): (1) `SELECT DISTINCT ly.Id, ly.Source FROM tblLyricWordVocalParts w JOIN tblLyrics ly ON ly.Id = w.LyricsId WHERE ly.SongId = ? ORDER BY ly.Id`; per version: (2) `vocalPartsForVersion()`; (3) lines `SELECT Id, SortOrder, LineText, StartTimeMs, EndTimeMs FROM tblLyricLines WHERE LyricsId = ? ORDER BY SortOrder, Id`; (4) line rows `SELECT LineId, VocalPartId, IsBackground FROM tblLyricLineVocalParts WHERE LyricsId = ? ORDER BY LineId, SortOrder, Id`; (5) words `SELECT w.Id, w.LineId, w.SortOrder, w.WordText, w.StartTimeMs, w.EndTimeMs FROM tblLyricWords w JOIN tblLyricLines ll ON ll.Id = w.LineId WHERE ll.LyricsId = ? ORDER BY ll.SortOrder, w.SortOrder, w.Id`; (6) word rows `SELECT WordId, VocalPartId, IsBackground FROM tblLyricWordVocalParts WHERE LyricsId = ? ORDER BY WordId, SortOrder, Id`. Six bounded queries per song; only runs when `include=vocalWords` is asked for.

### 2.1 Gates (memoised, in the cores)
```php
function vocalPartsTablesReady(\mysqli $db): bool   // INFORMATION_SCHEMA.TABLES COUNT(*) >= 3 over ('tblVocalParts','tblLyricLineVocalParts','tblLyricWordVocalParts')
function vocalPartsSpansReady(\mysqli $db): bool    // 'tblLyricLineVocalSpans' present
function lyricRoundsReady(\mysqli $db): bool        // COUNT(*) >= 2 over ('tblLyricRounds','tblLyricRoundVoices')  (lyric_rounds.php)
```
Body template (copy `lineEnrichmentTablesReady()` line_enrichment.php:123-140 EXACTLY, then add in the catch, before `$ready = false;`): `if (function_exists('songRelocateIsTransactionFatal') && songRelocateIsTransactionFatal($_e)) { throw $_e; }` — the `lyricLinesComponentExtrasPresent()` posture, because `component_upsert` reads inside its transaction. `static $ready = null;` per function (memoised per request — a fresh request re-probes after a mid-deploy migration).

---

## 3 — Un-migrated install: proof of EXACT degradation

| install state | `lyricLinesFetchVoices()` | assembler output |
|---|---|---|
| no #1137 tables | `vocalPartsTablesReady()` false → `[[],[]]` (no SELECT issued) | `$voicesByLine=[]`,`$spansByLine=[]` → the two `if (… !== [])` blocks skip → byte-identical |
| #1137 tables, no spans table | one chunked SELECT, 0 rows → `[]`; spans not probed | identical |
| all tables, song has no rows | SELECT returns nothing for that SongId → `$voices[$sid] ?? []` | identical |
| probe throws (permission/blip) | catch → false → `[[],[]]` | identical |
| deadlock mid-transaction | re-thrown (correct — the transaction is dead) | caller's rollback path |

Cost: +1 prepared query per `song_detail` (0 on an un-migrated install), +1 per 500 songs on `getSongs()`. No N+1.

---

## 4 — The two read shapes

### 4.1 PUBLIC (`lyricLinesAssembleFromRows` → `song_detail.components[]`, `songbook_export.songs[].components[]`, `random`, `bulk_songs`, `page=song`)
Always-present: `type, number, lines, chords, language, lineIds`. Sparse: `label`, `lineLanguages`, **`voices`**, **`voiceSpans`** — in that key order. Rule: a sparse key is emitted ONLY when it carries ≥1 element; never `[]`, never `null`.

```json
{"type":"verse","number":1,"lines":["You are holy,","You are mighty,","You are worthy,","Worthy of praise."],
 "chords":null,"language":null,"lineIds":[4011,4012,4013,4014],
 "voices":[{"from":0,"to":1,"parts":[{"id":10,"kind":"female","label":"Women","bg":false,"enters":true}]},
           {"from":2,"to":3,"parts":[{"id":11,"kind":"male","label":"Men","bg":false,"enters":true}]}],
 "voiceSpans":[{"line":3,"start":7,"end":17,"part":{"id":12,"kind":"backing","label":"Echo","bg":true}}]}
```

### 4.2 EDITOR (`lyricLinesEditableComponents` → api2 snapshot/load; classic `getSongById` + sidecar)
Per-component keys: **unchanged** — `id,type,number,sortOrder,lines,chords,language,languages,label,sourceWorkId,lineIds` (11, always present). Voice data is the ALWAYS-present top-level sidecar `vocalParts` (§2 `vocalPartsForSong()` shape) on both `load_song` payloads:
- api2.php `case 'load_song'` (after `$enrichment = lineEnrichmentForSong(...)`): `require_once dirname(__DIR__, 2).'/includes/vocal_parts.php'; $vocal = vocalPartsForSong($db, $songId);` and add `'vocalParts' => $vocal,` to the `ed2_respond(array_merge(...))` array directly after `'lineAnnotations'`. NOT inside `ed2_buildSongSnapshot()` (revision NewData must not carry it — same reasoning as media/enrichment at api2.php:2247).
- manage/editor/api.php `case 'load_song'` (after `$song['lineAnnotations'] = …;` at :387): `$song['vocalParts'] = vocalPartsForSong(getDbMysqli(), $songId);` inside the same `is_array($song['components'])` branch.
- editor2.php store (line 736) gains `vocalParts: null` default and line ~859 `store.set('vocalParts', data.vocalParts || null);` (client wiring beyond the store is the editor pass's).

The classic editor's `$song['components'][]` ALSO carry the public sparse `voices`/`voiceSpans` (they come from `getSongById()`); that is fine — the classic editor ignores unknown keys, and `save_song` rebuilds components from its own fields (it never echoes `voices` back; the write path ignores the key — Pass 2 must NOT read `voices` from a save payload).

---

## 5 — Content gating (rule #28) — DECISION: lyric BODY

`includes/access_resolver.php:128` — change the strip list to:
```php
foreach (['components', 'translations', 'annotations', 'vocalParts', 'rounds', 'vocalWords'] as $bodyKey) {
```
No other gating change. `voices`/`voiceSpans` live inside `components` → stripped with it. `songbook_export` maps `accessApplySong()` per song (api.php:1622-1627) → same strip. `song.php` renders runs inside the `foreach ($renderOrder …)` loop, and `$lyricsGated` sets `$renderOrder = []` (song.php:1222) → nothing to add. `songPageGatingDecide()` unchanged. `contentGatingApply()` unchanged (thin delegate). Dormancy (rule #28A): with the flag off `accessApplySong()` returns at its first line — no new path.

Test additions: `tests/php/test-gating-equivalence.php` — one positive fixture: a song with `components[0].voices`, `vocalParts`, `rounds`, `vocalWords` under a deny-lyric-body viewer → assert all four absent and `contentRestricted === true`; under an allow viewer → byte-identical input. `tests/php/test-gating-noop.php` — extend the existing fixture at :70 with `'voices' => [...]` on the component and a `'rounds' => [...]` key and assert byte-identical with gating off.

---

## 6 — API surface (rule #48)

### 6.1 `GET /api?action=song_detail` (and alias `song_data`)
- Base payload: `components[].voices` / `components[].voiceSpans` sparse (§4.1). No other change.
- `SongData::songDetailIncludeBlocks()` returns `[..., 'vocalParts', 'translations', 'annotations', 'externalIds', 'rounds', 'vocalWords']` (append the two).
- `getSongDetailExtras()`: `$needsLyrics = (bool)array_intersect($want, ['vocalParts','translations','annotations','rounds']);` keep `$lyricsId = $needsLyrics ? $this->_primaryLyricsId($songId) : 0;` for translations/annotations (unchanged bytes), and add `$ihymnsLyricsId = (bool)array_intersect($want, ['vocalParts','rounds']) ? lyricLinesPrimaryLyricsId($this->db, $songId) : 0;` (require lyric_lines_read.php).
  - `case 'vocalParts'`: `if ($ihymnsLyricsId > 0 && vocalPartsTablesReady($this->db)) { $rows = vocalPartsForVersion($this->db, $ihymnsLyricsId); map each PartShape to ['id','partKind'=>kind,'label','singerName','gender','musicianId','kind','displayLabel','ttmlAgentId','source','sortOrder']; if ($rows) $out['vocalParts'] = $rows; }` — first six keys byte-identical to today's SELECT aliases; the rest additive.
  - `case 'rounds'`: `if ($ihymnsLyricsId > 0 && lyricRoundsReady($this->db)) { $rows = lyricRoundsForVersion($this->db, $ihymnsLyricsId); foreach: $r['subjectLineIds'] = lyricRoundSubjectLineIds($r, $orderedLineIds); $r['timeline'] = lyricRoundTimeline($r, $r['subjectLineIds'], $lineTimingsMs ?: null); if ($rows) $out['rounds'] = $rows; }` where `$orderedLineIds` = `array_column(lyricLinesFetchPrimary($this->db,$songId),'line_id')` cast int, and `$lineTimingsMs` = `lineId => StartTimeMs` from one `SELECT Id, StartTimeMs FROM tblLyricLines WHERE LyricsId = ? AND StartTimeMs IS NOT NULL` (empty on curator versions → timeline in line units; Pass 1 §8 owns the timeline shape).
  - `case 'vocalWords'`: `if (vocalPartsTablesReady($this->db)) { $rows = vocalPartsWordsForSong($this->db, $songId); if ($rows) $out['vocalWords'] = $rows; }` (version-independent — carries its own lyricsId).
  - Each case stays inside the existing per-block `try { … } catch` so an un-migrated install omits the block, never 500s.
- Rate limit / auth unchanged (`enforceReadRateLimitKeyed('song_detail', 240)`).

### 6.2 `GET /api?action=songbook_export&abbr=`
No handler change. Every song's `components[]` carry the sparse keys via `getSongs()` → `_getComponentsMap()` → `lyricLinesAssembleComponentsMap()`. Rounds/word grain are NOT in the export (no export format consumes them; a client needing them calls `song_detail?include=rounds`). Tier strip already applies per song.

### 6.3 `GET /api?action=bulk_songs&songbook=` / `page=song` fragment
`bulk_songs` renders song.php per song from `getSongs()` → runs available in `$song['components']` for the render pass; `song.php`'s rounds read (render pass) mirrors the translations block at song.php:469-501: `if (lyricLinesMirrorPresent($db)) { require_once …/vocal_parts.php; $vocalBundle = vocalPartsForSong($db, $songId); }` in try/catch → `$songRounds = $vocalBundle['rounds'] ?? []`. Shared-cache safe (song-level facts, rule #6).

### 6.4 Editor `load_song` (api2 + classic) — §4.2.

### 6.5 api-docs.yaml changes (same change as the code — rule #48)
1. `SongComponent` schema (line ~23346): add
   - `lineIds: {type: array, items: {type: integer}}` (undocumented today — document it, it is the anchor),
   - `voices: {type: array, items: {$ref: '#/components/schemas/VoiceRun'}}` — description: SPARSE, present only when ≥1 run; `from`/`to` are 0-based inclusive indexes into `lines`; stripped with the lyric body under gating.
   - `voiceSpans: {type: array, items: {$ref: '#/components/schemas/VoiceSpan'}}` — SPARSE; `start`/`end` are Unicode code-point offsets (rule #21), `end` exclusive.
2. New schemas: `VoiceRun` `{from:int, to:int, parts:[VoiceRunPart]}`; `VoiceRunPart` `{id:int, kind:string(enum = IHYMNS_VOCAL_PART_KINDS keys), label:string, bg:boolean, enters:boolean}`; `VoiceSpan` `{line:int, start:int, end:int, part:VoiceRunPart-without-enters}`; `VocalPart` `{id, partKind (deprecated: use kind), kind, label, displayLabel, singerName, gender, musicianId, ttmlAgentId, source, sortOrder}`; `Round` (Pass 1's RoundShape + `subjectLineIds` + `timeline`); `VocalWordsVersion` (§2 shape).
3. `song_detail` `include` parameter (lines ~955-968 and ~2011-2013): allow-list text += `rounds`, `vocalWords`; example unchanged.
4. Song schema (line ~23302): document `vocalParts: [VocalPart]`, `rounds: [Round]`, `vocalWords: [VocalWordsVersion]` as include-only; `contentRestricted` description (lines ~221, ~928, ~23326): add `rounds` / `vocalWords` to the stripped list.
5. `/manage/editor/api2.php?action=load_song` response (line ~19853): add `vocalParts: { $ref: '#/components/schemas/EditorVocalParts' }` with the §2 sidecar shape, and mention it in the description next to `lineTranslations`.

---

## 7 — Columns this read path REQUIRES from Pass 1's DDL (lockstep list)

The SELECTs above name exactly these; Pass 1's migration + schema.sql must carry them byte-identically (rule #19):
- `tblLyricLineVocalParts`: `LineId, VocalPartId, LyricsId, IsBackground, SortOrder` (all present in schema.sql:4530-4551 — verified).
- `tblVocalParts`: `Id, LyricsId, PartKind, Label, MusicianId, SingerName, Gender, TtmlAgentId, Source, SortOrder` (present — verified; NOTE `MusicianId`, not `CreditPersonId`).
- `tblLyricWordVocalParts`: `WordId, VocalPartId, LyricsId, IsBackground, SortOrder` (present — verified).
- `tblLyricLineVocalSpans` (Pass 1, NEW): `Id BIGINT UNSIGNED PK, LineId BIGINT UNSIGNED FK tblLyricLines CASCADE, VocalPartId INT UNSIGNED FK tblVocalParts CASCADE, LyricsId INT UNSIGNED FK tblLyrics CASCADE, IsBackground TINYINT(1) NOT NULL DEFAULT 0, StartOffset INT UNSIGNED NOT NULL COMMENT 'code points', EndOffset INT UNSIGNED NOT NULL COMMENT 'code points, exclusive', SortOrder INT UNSIGNED NOT NULL DEFAULT 0, INDEX idx_Line (LineId, StartOffset), INDEX idx_Lyrics (LyricsId)`.
- `tblLyricRounds` / `tblLyricRoundVoices`: read ONLY through Pass 1's `lyricRoundsForVersion()`; this pass adds no direct SELECT.
- `tblLyricLines.StartTimeMs` (present, schema.sql:798) for the round timeline.

---

## 8 — Guards (tree-derived, mutation-proven — rule #34)

`tests/php/test-lyric-lines-read.php` — extend the `row()` factory NOT at all; add cases:
- 12 fold truth table (§1.1 table, six lines) via `lyricLinesFoldVoiceRuns()` directly;
- 13 `lyricLinesAssembleFromRows($rows)` with NO extra args === today's output for case-1 rows (defaults are byte-identical);
- 14 `lyricLinesAssembleFromRows($rows, $voices)` emits `voices` AFTER `lineLanguages` (strict === on the full array with a lineLanguages-bearing fixture);
- 15 `$voices` for a lineId not in the component → NO `voices` key (sparse);
- 16 spans: `voiceSpans` with `line` index + code-point offsets; a span on an unknown lineId → no key.
Mutation proof (run once, record in the file header): change `$sig === $prevSig` to `!==` → cases 12/14 red; drop the `if ($runs !== [])` guard → case 15 red; restore.

`tests/php/test-vocal-parts-read.php` (NEW):
- (a) parse `SongData.php` for the `$needsLyrics = (bool)array_intersect($want, [ … ])` array literal AND the `$ihymnsLyricsId … array_intersect($want, [ … ])` literal; parse `access_resolver.php` for the `foreach ([ … ] as $bodyKey)` literal; assert every block name from the SongData literals is in the strip list, and `'components'` is in it. Mutation: remove `'rounds'` from the strip list → red.
- (b) assert `lyricLinesFetchPrimary`, `lyricLinesFetchPrimaryMap`, `lyricLinesPrimaryLyricsId`, `vocalPartsLinesMapForSongs`, `vocalPartsSpansMapForSongs` function bodies (comment-stripped) each contain `Source = 'ihymns'`. Mutation: change one to `IsPrimary = 1` → red.
- (c) every name returned by a static parse of `songDetailIncludeBlocks()` appears as a backtick-quoted word in api-docs.yaml's `song_detail` include description. Mutation: delete `vocalWords` from the docs → red.
- (d) both editor `load_song` cases (api2.php `case 'load_song'` block; manage/editor/api.php `case 'load_song'` block) reference `vocalPartsForSong(` — the same shape as `test-lyric-lines-read.php` case 11's source assertion. Mutation: remove from one → red.
- (e) `lyricLinesEditableComponents()` body does NOT contain `'voices'` (the editor per-component shape must stay 11 keys — a later "helpful" addition would silently fatten every revision snapshot).
`tests/test-component-label-sites.js`: no change needed (it enumerates label derivers, not voice keys) — verify it still passes.

---

## 9 — Consumer inventory the render/export passes must wire (for the record; not this pass)

Markup-scrape surfaces (inherit song.php's HTML): includes/pages/song.php, includes/service_mode.php, js/modules/{display,live-follow,midi-input,present-mode,service-broadcast,service-follow,setlist,share,song-markup,song-translations}.js. JSON-shape consumers (read `components[]`): includes/song_importers.php, manage/editor/{api.php,api2.php,editor.js,format-export.js,propresenter-export.js}, manage/editor/v2/{api-client,enrichment-panel,structure-tab}.js, js/modules/print.js, includes/pdf_renderer.php. NOTE for the render pass: present-mode.js:46 and share.js:169 read `.lyric-line` `textContent` — a chip rendered as a CHILD of `<p class="lyric-line">` puts the label text INTO the slide/snippet text unless the chip is excluded (e.g. rendered as a sibling `<span class="lyric-voice">` immediately before the `<p>` inside the run's `role="group"` wrapper, or the scrapers read a `data-` attribute); pick deliberately.

## Files
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/appWeb/public_html/includes/lyric_lines_read.php` — Add pure `lyricLinesFoldVoiceRuns()`; widen `lyricLinesAssembleFromRows($rows, $voicesByLine = [], $spansByLine = [])` with the two sparse emits appended after `lineLanguages` inside `$flush`; add `lyricLinesPrimaryLyricsId()` (Source='ihymns' read-only resolver); add gated `lyricLinesFetchVoices()`; wire `lyricLinesAssembleComponents()` / `…Map()` to pass voices+spans. `lyricLinesFetchPrimary[Map]()`, `lyricLinesEditableComponents()` and the preview helpers untouched. Doc-block: extend the BYTE-IDENTICAL CONTRACT paragraph with the two new sparse keys (same SD3 wording as `label`).
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/appWeb/public_html/includes/vocal_parts.php` (NEW) — (Pass 2's core) add the song-keyed, chunked, 'ihymns'-keyed fetchers `vocalPartsLinesMapForSongs()` and `vocalPartsSpansMapForSongs()` (display label resolved in the fetcher via `vocalPartsDisplayLabel()`), the list-only editor/page bundle `vocalPartsForSong()` with `ready`/`spansReady`/`roundsReady`/`lyricsId`, and the word-grain reader `vocalPartsWordsForSong()`; the memoised gates `vocalPartsTablesReady()` / `vocalPartsSpansReady()` with the #1688 fatal-transaction re-throw in the catch.
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/appWeb/public_html/includes/lyric_rounds.php` (NEW) — (Pass 1/2's core) `lyricRoundsReady()` gate with the same catch posture; `lyricRoundsForVersion()` / `lyricRoundSubjectLineIds()` / `lyricRoundTimeline()` are consumed by the `rounds` include block — no shape change requested here beyond the block adding `subjectLineIds` + `timeline` to each round it emits.
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/appWeb/public_html/includes/SongData.php` — `songDetailIncludeBlocks()` += 'rounds','vocalWords'. `getSongDetailExtras()`: keep `_primaryLyricsId()` for translations/annotations (bytes unchanged); add `$ihymnsLyricsId` via `lyricLinesPrimaryLyricsId()` for `vocalParts`/`rounds`; `case 'vocalParts'` now delegates to `vocalPartsForVersion()` and emits the six legacy keys + additive `kind/displayLabel/ttmlAgentId/source/sortOrder`; new `case 'rounds'` and `case 'vocalWords'` (each inside the existing per-block try/catch, omit-when-empty).
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/appWeb/public_html/includes/access_resolver.php` — Line 128 strip list becomes `['components','translations','annotations','vocalParts','rounds','vocalWords']`. Doc-block sentence noting voice runs/spans ride inside `components` and are therefore stripped with it.
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/appWeb/public_html/manage/editor/api2.php` — `case 'load_song'`: require includes/vocal_parts.php, `$vocal = vocalPartsForSong($db, $songId);`, add `'vocalParts' => $vocal` to the `ed2_respond(array_merge(...))` array after `'lineAnnotations'`. NOT in `ed2_buildSongSnapshot()`.
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/appWeb/public_html/manage/editor/api.php` — `case 'load_song'`: after `$song['lineAnnotations'] = …;` add `$song['vocalParts'] = vocalPartsForSong(getDbMysqli(), $songId);` (require vocal_parts.php at the top of the file next to line_enrichment.php).
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/appWeb/public_html/manage/editor/editor2.php` — Store default (line ~736) gains `vocalParts: null`; the load handler (line ~859) sets `store.set('vocalParts', data.vocalParts || null)`. UI consumption is the editor pass.
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/appWeb/public_html/includes/pages/song.php` — Read side only: next to the translations block (lines 469-501) add the gated try/catch `vocalPartsForSong()` read → `$songRounds`; the component loop already receives `voices`/`voiceSpans` on `$component` for the render pass. No markup in this pass.
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/appWeb/public_html/api-docs.yaml` — SongComponent: document `lineIds`, add sparse `voices` + `voiceSpans`; new schemas VoiceRun / VoiceRunPart / VoiceSpan / VocalPart / Round / VocalWordsVersion / EditorVocalParts; `song_detail` include allow-list text += `rounds`, `vocalWords`; Song schema gains the three include-only keys; `contentRestricted` descriptions list `rounds`/`vocalWords`; api2 `load_song` response adds `vocalParts`.
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/tests/php/test-lyric-lines-read.php` — Cases 12-16: fold truth table, default-args byte-identity, sparse placement after lineLanguages, unknown-lineId → no key, spans shape. Record the mutation-proof in the header comment.
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/tests/php/test-vocal-parts-read.php` (NEW) — NEW tree-derived guard: (a) SongData lyrics-keyed block literals ⊆ access_resolver strip list; (b) every 'ihymns' fetcher/resolver body contains `Source = 'ihymns'`; (c) every include block name is documented in api-docs.yaml; (d) both editor load_song cases call `vocalPartsForSong(`; (e) `lyricLinesEditableComponents()` does not emit 'voices'. Mutation-proven per §8.
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/tests/php/test-gating-equivalence.php` — Positive fixture: deny-lyric-body viewer strips components (with voices), vocalParts, rounds, vocalWords; allow viewer → byte-identical.
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/tests/php/test-gating-noop.php` — Extend the line-70 fixture with `voices` on the component and a `rounds` key; assert byte-identical with gating off.
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/package.json` — Add the new PHP test to the CI test list (the npm-vs-CI list lockstep rule #35 — check `tests/php/run-all` or whatever enumerates PHP tests; if the runner is tree-derived nothing to add).

## Risks
- SILENT NO-RENDER (the #1565 class): voice rows written against a tblLyrics version other than the 'ihymns' one will never appear in `components[].voices` because the assembler only reads 'ihymns' lines. Pass 2's writers MUST derive LyricsId from the line row (the schema comment already says so) — the guard's Source='ihymns' assertion covers the read side only.
- FIDELITY HASH DRIFT BY DESIGN: the first time the backfill (D4) applies suggestions, every affected song's sha256 in tools/export-fidelity-snapshot.php changes. That is correct (data changed) but a --compare run against a pre-voice baseline will report 'drift'. Re-baseline AFTER the backfill and say so in the handoff — otherwise the next cutover gate reads as failed.
- The `include=translations` key collision (see corrections) means a native client asking `include=vocalParts,translations` today ALREADY gets the whole-song `translations` list replaced by per-line rows. Adding `rounds`/`vocalWords` does not touch this, but any test fixture built from a real `include=translations` response will encode the wrong shape.
- `enters` is derived from run ADJACENCY within one component. A part that ends verse 1 and opens verse 2 shows `enters:true` at the top of verse 2 — intended (a new section restarts the chip), but a renderer wanting cross-component continuity must compare against the previous component's last run itself.
- Per-request memoised gates: after the Pass 1 migration runs on the shared DB, the SAME PHP request that ran it still sees `ready=false` — harmless (the setup-database runner never renders a song), but do not call the read inside the migration script expecting rows.
- `vocalPartsWordsForSong()` runs six queries per version; a song with many ingested versions is bounded by the number of tblLyrics rows for that song (uq_song_source → one per Source), so it cannot explode, but it is opt-in only (`include=vocalWords`) for that reason. Never add it to the base payload.
- tblVocalParts.PartKind stored values that predate the Pass 2 vocabulary (e.g. 'main') reach the public shape verbatim in `kind`; `vocalPartsDisplayLabel()` must fall back to `ucfirst(kind)` rather than throw on an unmapped key, or one stale row white-screens the song page under STRICT.
- The classic editor `save_song` rebuilds components from its own fields; if a future change echoes the loaded `components[]` back verbatim, the `voices` key would arrive at the write path — Pass 2's `lyricLinesWriteComponents()` must IGNORE `voices`/`voiceSpans` on input (they are read-only projections), otherwise a stale client could clobber assignments. Guard (e) in test-vocal-parts-read.php protects the editor shape; add a write-side ignore assertion in Pass 2's guard.
- bulk_songs / page=song fragments are shared-cache (`$_cacheablePages`, rule #6). Voice runs are song-level facts so they are safe to bake in — but the SAME fragment is what the gated render strips, so the render pass must keep the run markup INSIDE the `$renderOrder` loop (already emptied by `$lyricsGated`) and never in a separately-rendered sidebar.
---

# Design pass 4

## Summary
Pass 4 — the WRITE path and the Editor2 entry mechanism for vocal parts (#1137 write side, D1–D4). Everything below was checked against the tree on 2026-09-04. Design in one line: a new per-section "Who sings" panel in Editor2's Structure tab (`v2/voices-panel.js`, built by `buildCard()` exactly like the chord rows and the enrichment panel) where a curator ticks a RUN of lines (checkbox + Shift-click/Shift+Space range), picks a part from a two-group `<select>` (this song's existing parts / a new part from the served vocabulary — never free text), optionally flags it as Echo, and presses Assign; the panel first flushes the section's own pending `component_upsert` so every line has a `tblLyricLines.Id` (D3), then makes ONE transactional `vocal_lines_assign` call whose response carries the WHOLE song's `vocalParts` payload (read-back, rule #35) that the store adopts wholesale. Eight new api2.php actions delegate to a write half added to Pass 2's `includes/vocal_parts.php` + Pass 1's `includes/lyric_rounds.php`; `load_song` gains a top-level `vocalParts` key beside `lineTranslations`. Write safety verified against `lyricLinesDiff()`/`lyricLinesApplyDesired()`: FK'd rows survive unchanged/reordered/moved/lightly-edited lines because the Id is reused; a below-0.5 rewrite or a real deletion cascades — so this pass (a) extends the PF2 orphan snapshot to vocal rows and (b) adds a same-slot carry-over so a retyped line keeps its voice. Lockstep across api2 → editor2 → panel is enforced by a new tree-derived, mutation-proven `tests/test-v2-voices-ui.js` reading `VOCAL_PARTS_PAYLOAD_KEYS` out of the PHP core, and the vocabulary is served to the browser from the PHP constant (no JS copy). Five corrections to the established research are listed, the loudest being that api2.php/editor2.php are NOT under `v2/`, api2.php does NOT call `validateCsrfRequest()`, and `lyricLinesPrimaryLyricsId()` already exists.

## Spec
## 0 — Scope, and what this pass verified in the tree

This pass owns: the WRITE half of `includes/vocal_parts.php` (Pass 2 owns the vocabulary + read fetchers), the write functions of `includes/lyric_rounds.php` (Pass 1 owns the DDL), eight `api2.php` actions, the `load_song` hydrate, the Editor2 control (`v2/voices-panel.js` + the hooks in `structure-tab.js` / `editor2.php` / `api-client.js`), the write-safety changes in `includes/lyric_lines_sync.php`, api-docs, and the CI guards.

All paths below are relative to `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/appWeb/public_html/` unless they start with `tests/`, `.github/` or `appWeb/.sql/`.

Verified facts this design rests on (line numbers as of 2026-09-04):
- `manage/editor/api2.php` — file-level gate (:549-566): Bearer OR cookie, `hasRole('editor')`; POST gate (:630) `X-Requested-With` for cookie POSTs; `ED2_GET_SAFE_ACTIONS` (:669) → every other action must be POST (405). `ed2_respond()` (:499). `ed2_requireEntitlement()` (:584). `ed2_songExists()` (:1357). `ed2_touchRevision()` (:2148, 15-second dedupe). `load_song` (:2216) attaches `lineTranslations`/`lineAnnotations` in the case. `component_upsert` (:3342) returns `componentId, label, sourceWorkId, sourceWorkIdIgnored, lineIds`. `line_translation_upsert` (:3702) is the throw→status template (Invalid→400, Runtime→404, 409 on tables not ready).
- `includes/line_enrichment.php` — `lineEnrichmentResolveLine($db,$lineId,$songId)` (:169) returns `{lyricsId,text,cpLen}` or null; ownership is by `ly.SongId` only, deliberately not by Source. `lineEnrichmentValidateOffsets()` (:95) is the code-point offset validator.
- `includes/lyric_lines_sync.php` — `lyricLinesApplyDesired()` (:225) reads existing rows, runs `lyricLinesDiff()` (:839: pass 1 same part + same text FIFO, pass 2 same text any part, pass 3 same part fuzzy ≥ 0.5), snapshots enrichment for `deleteIds` (:1101, translations + annotations ONLY), DELETEs, then INSERTs unmatched / UPDATEs dirty matched rows **by the SAME Id**. The UPDATE writes every projected column (ComponentId, PartType, PartTypeSlug, PartNumber, SortOrder, LineText, ChordsJson, Note, LanguageCode, IsInstrumental) — vocal rows are NOT columns on this table, so the UPDATE cannot touch them.
- `includes/lyric_lines_read.php` — `lyricLinesPrimaryLyricsId()` (:332) exists; `lyricLinesEditableComponents()` (:682) emits `lineIds` parallel to `lines`, `[]` on a pre-mirror install.
- `manage/editor/v2/structure-tab.js` — `saveComponent()` (:358) never rejects, adopts `res.lineIds` (:416); `debouncedSave()`/`pendingSaves` (:317-322, keyed `comp._key`); `buildCard()` appends the enrichment panel at :813; the lyrics textarea `input` handler (:684-700) re-renders chord rows locally; `render()` wipes cards and tracks `cardPickerDetachFns` (:315, :875).
- `manage/editor/v2/enrichment-panel.js` — `componentLineId(comp,i)` (:258, exported); `isEnrichmentUnmigrated(err)` branches on `err.status === 409` (:272).
- `manage/editor/v2/api-client.js` — `unwrap()` attaches `err.status` (:71-73); `postJson()` sends `X-Requested-With` (:118).
- `manage/editor/editor2.php` — gate :21-30; `<meta name="csrf-token">` :195; classic-global vocab emits :598-625 (`window._iHymnsSongPartTypes` :620 is the template); store :736; `loadSong()` hydrate :846-866.
- `manage/editor/v2/new-song-wizard.js` — `finish()` (:588-663): `createSong` → `replaceComponents(seed,'replace')` → `loadSong()`. A new song therefore ALWAYS arrives in the editor through `load_song` with real component ids and lineIds (a blank seed line `''` still becomes a `tblLyricLines` row with `IsInstrumental=1`). D3 bites only on: a section added with **Add section** whose create round-trip has not resolved, lines typed inside the 500 ms debounce window, a failed save, or a pre-mirror install (`lineIds === []`).
- `appWeb/.sql/schema.sql:4503-4574` — `tblVocalParts` (`MusicianId`, `SingerName`, `Gender`, `TtmlAgentId`, `Source` DEFAULT 'ihymns', `MetaJson`, `SortOrder`, UNIQUE `(LyricsId, TtmlAgentId)`), `tblLyricLineVocalParts` (UNIQUE `(LineId, VocalPartId)`, `IsBackground`, `SortOrder`, three CASCADE FKs), `tblLyricWordVocalParts` (same shape on `WordId`).
- `js/modules/combobox-a11y.js` — `window.iHymnsComboboxA11y.handleComboboxKeydown(event, config)` / `applyComboboxAria(config)`; no export statement (classic + module dual load).
- `tests/php/fixtures/orphan-allowlist.php:755` — `'tblVocalParts' => '#1066 one-pass dormant — … write side is future feature work'` under `tables_reader_no_writer`. This pass ships the writer, so that entry MUST be removed (the fixture asserts no stale entries).

---

## 1 — Contract with Pass 1's DDL (columns this write path depends on)

Pass 1 ships ONE migration `appWeb/.sql/migrate-vocal-parts-rounds.php` (+ schema.sql mirror + ONE `migration-registry.php` entry with a multi-object OR-probe, rule #19). The write path below binds to these exact column names. If Pass 1 chose a different name, reconcile it in Pass 1's DDL — never by forking a second SQL fragment here.

```sql
-- Sub-line voice span (Pass 1). Offsets are 0-based end-exclusive CODE POINTS (rule #21).
CREATE TABLE IF NOT EXISTS tblLyricLineVocalSpans (
    Id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    LineId       BIGINT UNSIGNED NOT NULL,
    VocalPartId  INT UNSIGNED    NOT NULL,
    LyricsId     INT UNSIGNED    NOT NULL COMMENT 'Denorm of tblLyricLines.LyricsId; app derives from the line, never the caller',
    StartOffset  INT UNSIGNED    NOT NULL COMMENT '0-based UTF-8 code-point index, inclusive',
    EndOffset    INT UNSIGNED    NOT NULL COMMENT 'code-point index, exclusive; > StartOffset',
    IsBackground TINYINT(1)      NOT NULL DEFAULT 0,
    SortOrder    INT UNSIGNED    NOT NULL DEFAULT 0,
    Source       VARCHAR(100)    NOT NULL DEFAULT 'manual',
    CreatedAt    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_Line (LineId, StartOffset), INDEX idx_Lyrics (LyricsId), INDEX idx_Part (VocalPartId),
    CONSTRAINT fk_LineVS_Line   FOREIGN KEY (LineId)      REFERENCES tblLyricLines(Id)  ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_LineVS_Part   FOREIGN KEY (VocalPartId) REFERENCES tblVocalParts(Id)  ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_LineVS_Lyrics FOREIGN KEY (LyricsId)    REFERENCES tblLyrics(Id)      ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A round / canon over a RUN of lines (D1). Anchored on line Ids so it survives edits like every other per-line row.
CREATE TABLE IF NOT EXISTS tblLyricRounds (
    Id           INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    LyricsId     INT UNSIGNED    NOT NULL,
    StartLineId  BIGINT UNSIGNED NOT NULL,
    EndLineId    BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'NULL = the single StartLineId line',
    Label        VARCHAR(120)    NULL DEFAULT NULL,
    TimesThrough TINYINT UNSIGNED NOT NULL DEFAULT 2 COMMENT '1..8 — how many times the run is sung',
    EntryUnit    VARCHAR(16)     NOT NULL DEFAULT 'lines' COMMENT 'lines|ms|beats — the unit EntryOffset is stated in (app-validated vs IHYMNS_ROUND_ENTRY_UNITS; VARCHAR not ENUM)',
    SortOrder    INT UNSIGNED    NOT NULL DEFAULT 0,
    Source       VARCHAR(100)    NOT NULL DEFAULT 'manual',
    MetaJson     JSON            NULL DEFAULT NULL,
    CreatedAt    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_Lyrics (LyricsId, SortOrder), INDEX idx_Start (StartLineId),
    CONSTRAINT fk_Rounds_Lyrics FOREIGN KEY (LyricsId)    REFERENCES tblLyrics(Id)     ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_Rounds_Start  FOREIGN KEY (StartLineId) REFERENCES tblLyricLines(Id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_Rounds_End    FOREIGN KEY (EndLineId)   REFERENCES tblLyricLines(Id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tblLyricRoundVoices (
    Id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    RoundId      INT UNSIGNED NOT NULL,
    VocalPartId  INT UNSIGNED NOT NULL,
    EntryOffset  INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'In the round''s EntryUnit; the first voice is 0',
    SortOrder    INT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY uq_Round_Part (RoundId, VocalPartId),
    CONSTRAINT fk_RoundV_Round FOREIGN KEY (RoundId)     REFERENCES tblLyricRounds(Id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_RoundV_Part  FOREIGN KEY (VocalPartId) REFERENCES tblVocalParts(Id)  ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

"What would force a second migration?" stress (rule #20): a new entry unit → `EntryUnit` VARCHAR + one line in `IHYMNS_ROUND_ENTRY_UNITS`; a per-voice transposition/octave for a round → `tblLyricRoundVoices` gets it via Pass 1 adding `MetaJson JSON NULL` NOW (add it — cost nothing, dormant); per-round tempo → `tblLyricRounds.MetaJson`; a span needing its own label → `MetaJson` is NOT on spans — add `MetaJson JSON NULL DEFAULT NULL` to `tblLyricLineVocalSpans` NOW as well. Both MetaJson additions are Pass 1 DDL lines; nothing in this pass reads them.

Gates (memoised INFORMATION_SCHEMA probes, the `lineEnrichmentTablesReady()` shape): `vocalPartsTablesReady($db)` (the #1137 trio, Pass 2), `vocalPartsSpansReady($db)` (`tblLyricLineVocalSpans`), `lyricRoundsReady($db)` (`tblLyricRounds` AND `tblLyricRoundVoices`, count >= 2). Every catch re-throws only `songRelocateIsTransactionFatal($e)` (song_relocate.php:249) exactly as `lyricLinesEnrichmentTablesPresent()` does.

---

## 2 — The api2.php actions (eight, all POST)

Common preamble for every case (copy the `line_translation_upsert` shape, :3702-3725):
```php
$songId = trim((string)($body['songId'] ?? ''));
if ($songId === '') { ed2_respond(['ok'=>false,'error'=>'songId is required.'], 400); }
ed2_requireEntitlement('edit_songs');                                   // §Corrections item 6 — song_link_add precedent
if (!ed2_songExists($db, $songId)) { ed2_respond(['ok'=>false,'error'=>'Song not found.'], 404); }
if (!vocalPartsTablesReady($db)) { ed2_respond(['ok'=>false,'error'=>'Vocal-parts tables are not migrated.'], 409); }
$db->begin_transaction();
try { …core call…; $db->commit(); }
catch (\InvalidArgumentException $e) { $db->rollback(); ed2_respond(['ok'=>false,'error'=>$e->getMessage()], 422); }
catch (\RuntimeException $e)         { $db->rollback(); ed2_respond(['ok'=>false,'error'=>$e->getMessage()], 404); }
catch (\Throwable $e)                { $db->rollback(); throw $e; }
logActivity('song.vocalParts.<verb>', 'song', $songId, [...]);
ed2_respond(['ok'=>true, <row key> => $row, 'vocalParts' => vocalPartsForSong($db, $songId)]);
```
The `require_once … 'includes/vocal_parts.php'` and `'includes/lyric_rounds.php'` go next to the existing `line_enrichment.php` require at the top of api2.php (module scope, rule #22). NO per-action CSRF call (Corrections item 2). None of these names goes into `ED2_GET_SAFE_ACTIONS`.

| action | body | core | 200 body (plus `ok`, `vocalParts`) | activity key |
|---|---|---|---|---|
| `vocal_part_upsert` | `{songId, part:{id?, kind, label?, singerName?, musicianId?, gender?, sortOrder?}}` | `vocalPartUpsert()` | `part: PartShape` | `song.vocalParts.part` |
| `vocal_part_delete` | `{songId, partId}` | `vocalPartDelete()` | `deleted: 0|1` | `song.vocalParts.part.delete` |
| `vocal_lines_assign` | `{songId, lineIds:int[], partId, bg?:bool, mode?:'add'|'replace'}` | `vocalPartsAssignLines()` | `assigned: int` (rows written) | `song.vocalParts.assign` |
| `vocal_lines_clear` | `{songId, lineIds:int[], partId?:int}` | `vocalPartsClearLines()` | `cleared: int` | `song.vocalParts.clear` |
| `vocal_span_upsert` | `{songId, span:{id?, lineId, partId, start, end, bg?}}` | `vocalSpanUpsert()` | `span: SpanShape` | `song.vocalParts.span` |
| `vocal_span_delete` | `{songId, id}` | `vocalSpanDelete()` | `deleted: 0|1` | `song.vocalParts.span.delete` |
| `vocal_round_upsert` | `{songId, round:{id?, startLineId, endLineId?, label?, timesThrough?, entryUnit?, voices:[{partId, entryOffset}]}}` | `lyricRoundUpsert()` | `round: RoundShape` | `song.vocalParts.round` |
| `vocal_round_delete` | `{songId, id}` | `lyricRoundDelete()` | `deleted: 0|1` | `song.vocalParts.round.delete` |

Extra 409s: `vocal_span_*` also 409 when `!vocalPartsSpansReady($db)`; `vocal_round_*` also 409 when `!lyricRoundsReady($db)`. `vocal_part_upsert` on a song with NO 'ihymns' version calls `lyricLinesEnsurePrimaryVersion()` (lyric_lines_sync.php:142) — it is a WRITE action, so minting the canonical version row is correct; every other action 404s a line/part that is not on that version.

**None of these touches a revision.** They are enrichment, not component content (the `line_translation_upsert` doc-block's rule). The ONLY revision the feature ever creates is the D3 section auto-save, which is an ordinary `component_upsert`.

`load_song` (:2258, inside the existing `ed2_respond(array_merge(...))`): add `'vocalParts' => vocalPartsForSong($db, $songId),` directly after `'lineAnnotations'`. Pass 3 owns the function; this pass owns the key placement.

`api-docs.yaml`: eight new path items under tag `Editor API v2`, each modelled on `/manage/editor/api2.php?action=line_translation_upsert` (:22467), with `409`/`422`/`404`/`403` responses spelled out; the `load_song` response schema gains `vocalParts` (`$ref: '#/components/schemas/EditorVocalParts'`, the §3.1 shape). Rule #48: api2 is Bearer-capable, so these are the native twins already.

---

## 3 — The shared write core (`includes/vocal_parts.php`, write half) and `includes/lyric_rounds.php`

### 3.1 The ONE payload shape (lockstep object)

```php
/** The exact top-level keys vocalPartsForSong() returns — the ONE list the editor
 *  hydrate (editor2.php), every write response, and tests/test-v2-voices-ui.js read. */
const VOCAL_PARTS_PAYLOAD_KEYS = ['ready', 'spansReady', 'roundsReady', 'lyricsId', 'parts', 'lineAssignments', 'spans', 'rounds', 'runs'];
```
Shapes (Pass 3's, restated so an implementer needs no other document):
- `PartShape` = `{id:int, kind:string, label:?string, displayLabel:string, singerName:?string, gender:?string, musicianId:?int, ttmlAgentId:?string, source:string, sortOrder:int}`
- `lineAssignments[]` = `{lineId:int, partId:int, bg:bool, sortOrder:int}` ORDER BY LineId, SortOrder, Id
- `spans[]` (`SpanShape`) = `{id:int, lineId:int, partId:int, bg:bool, start:int, end:int, sortOrder:int}`
- `rounds[]` (`RoundShape`) = `{id:int, startLineId:int, endLineId:?int, label:?string, timesThrough:int, entryUnit:string, sortOrder:int, voices:[{partId:int, entryOffset:int, entryOffsetMs:?int, sortOrder:int}]}` — `entryOffsetMs` is RESOLVED at read by `lyricRoundResolveEntryMs()` (§3.5), never stored.
- `runs[]` (NEW, additive to Pass 3) = `{componentId:int, from:int, to:int, parts:[{id,kind,label,bg,enters}]}` — `from`/`to` are 0-based line indexes WITHIN that component (the same index space as `comp.lines`), produced by grouping `lyricLinesFetchPrimary()` rows by `cid` and calling Pass 3's `lyricLinesFoldVoiceRuns($lineIdsOfComponent, $voicesByLine)` per component. Lists only, never a keyed map (PHP `json_encode([])` would flip an empty map to `[]`).
- Every list is `[]`, `lyricsId` null, when `!ready`. The function NEVER throws (each family in its own try/catch → `[]` + `error_log`).

### 3.2 Ownership + resolution helpers

```php
/**
 * ELI5: check that every line the caller named really belongs to this song, all sit in the
 * SAME version, and hand back what we know about each one (text length, order, section).
 * @param list<int> $lineIds   1..500 distinct positive ints (else InvalidArgumentException)
 * @return array{lyricsId:int, lines: array<int, array{text:string, cpLen:int, sortOrder:int, componentId:int}>}
 * @throws \InvalidArgumentException  empty / >500 / non-int ids; lines spanning two versions
 * @throws \RuntimeException          any id not found or not owned by $songId (→404)
 */
function vocalPartsResolveLines(\mysqli $db, array $lineIds, string $songId): array
```
ONE query: `SELECT ll.Id, ll.LyricsId, ll.LineText, ll.SortOrder, ll.ComponentId FROM tblLyricLines ll JOIN tblLyrics ly ON ly.Id = ll.LyricsId WHERE ly.SongId = ? AND ll.Id IN (?,…)` (placeholders via `array_fill`, rule #5). Missing id → Runtime. Distinct `LyricsId` count > 1 → Invalid ('Lines must all be in one lyrics version.'). A single line resolves through the SAME function (list of one) — `lineEnrichmentResolveLine()` is NOT re-forked; the single-line span/round resolvers call `vocalPartsResolveLines($db, [$id], $songId)`.

```php
/** The part row, ownership-checked: its LyricsId must be a version of $songId. Runtime on miss. */
function vocalPartResolve(\mysqli $db, int $partId, string $songId): array   // raw assoc row incl. LyricsId
```

### 3.3 Parts

```php
function vocalPartUpsert(\mysqli $db, string $songId, array $input, ?int $userId): array   // PartShape
```
Validation (Invalid → 422): `kind = vocalPartsNormalizeKind($input['kind'] ?? '')` else 'kind must be one of: …' (Pass 2's function; aliases + markers accepted); `label` = `mb_substr(trim(), 0, 120)`, `''` → NULL, AND **hide-when-equal**: a label equal (case-insensitive, `mb_strtolower`) to `IHYMNS_VOCAL_PART_KINDS[kind]['label']` stores NULL (the rule-#45/#27 fold, so the read's `displayLabel` fallback is the single rendering rule); `singerName` `mb_substr(trim(),0,255)` `''`→NULL; `musicianId` int > 0 must exist in `tblMusicians` (SELECT 1) else 422; `gender` ∈ `IHYMNS_VOCAL_GENDERS` or NULL, BUT if NULL and the kind IMPLIES one (`['gender']` non-null in the vocabulary) the implied value is stored (rule #44 — derived, never asked); `kind === 'named-singer'` with neither musicianId nor singerName → 422; `sortOrder` = `max(0,(int))`, default = current max+1 for the version on create. `Source = 'manual'` on create; UPDATE never changes Source/TtmlAgentId/MetaJson (machine provenance is not the curator's to edit). On UPDATE (`id > 0`): `vocalPartResolve()` first; `LyricsId` immutable. On CREATE: `$lyricsId = lyricLinesEnsurePrimaryVersion($db, $songId)`. Returns `vocalPartsShape(row)` (Pass 1/3's shaper; `displayLabel` = `Label ?? SingerName ?? musicianName ?? kindLabel ?? ucfirst(kind)`).

```php
/** ELI5: "give me the Women part for this version — make it if it isn't there".
 *  Match = same LyricsId + same PartKind + (Label <=> $label) + (for named-singer: same MusicianId, or same SingerName fold) . Never mints a duplicate. */
function vocalPartFindOrCreate(\mysqli $db, int $lyricsId, string $kind, ?string $label = null, array $opts = []): int   // opts: musicianId, singerName, source
```
Consumers: `vocal_part_upsert` when `id` is absent AND the caller passes `findOrCreate: true` (the panel's "New part…" path always does), Pass 2's ingest, and #1260's backfill queue apply.

```php
function vocalPartDelete(\mysqli $db, string $songId, int $partId): bool   // FK CASCADE drops its line/word/span/round-voice rows
```
Before the DELETE, count dependants (`tblLyricLineVocalParts` + spans + round voices) and log them in the activity `details` so the audit trail says what went with it. A round left with < 2 voices after the cascade is deleted too (`lyricRoundsPruneDegenerate($db, $lyricsId)`), inside the same transaction.

### 3.4 Line assignment (the owner's grain)

```php
/**
 * ELI5: "these lines are sung by this part" — in ONE transaction, all lines or none.
 * @param list<int> $lineIds  a run (any order; de-duplicated)
 * @param string    $mode     'add' (default: other parts on those lines stay) | 'replace' (other parts on those lines are removed first)
 * @return int rows now present for ($lineIds × $partId)
 */
function vocalPartsAssignLines(\mysqli $db, string $songId, array $lineIds, int $partId, bool $bg = false, string $mode = 'add'): int
```
Control flow: `$res = vocalPartsResolveLines(...)`; `$part = vocalPartResolve(...)`; `(int)$part['LyricsId'] !== $res['lyricsId']` → Invalid ('Part and lines belong to different lyrics versions.'); `$mode` not in `['add','replace']` → Invalid. If `replace`: `DELETE FROM tblLyricLineVocalParts WHERE LineId IN (…) AND VocalPartId <> ?`. Then per line, in `SortOrder` order: `INSERT INTO tblLyricLineVocalParts (LineId, VocalPartId, LyricsId, IsBackground, SortOrder) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE IsBackground = VALUES(IsBackground)` — the `(LineId, VocalPartId)` UNIQUE makes a re-assign idempotent and a bg toggle an update, never a duplicate. `SortOrder` = the count of rows already on that line (so a duet lists parts in assignment order; the read orders by `lvp.SortOrder, vp.SortOrder, vp.Id`). `LyricsId` is derived from the line, never the body.

```php
/** Remove this part (or ALL parts when $partId === null) from these lines. Returns rows deleted. */
function vocalPartsClearLines(\mysqli $db, string $songId, array $lineIds, ?int $partId = null): int
```
Also clears `tblLyricLineVocalSpans` rows for the same (line, part) pairs when spans are ready — a part that no longer sings the line cannot sing part of it.

### 3.5 Spans, words, rounds

```php
function vocalSpanUpsert(\mysqli $db, string $songId, array $input, ?int $userId): array   // SpanShape
```
`lineId` resolved via `vocalPartsResolveLines([$lineId])`; `start`/`end` ints; validated with the SHARED `lineEnrichmentValidateOffsets($start, $end, $cpLen, $cpLen, true)` (rule #22 — require_once line_enrichment.php) plus `end > start` (a zero-width span is Invalid) and `end <= cpLen`; part version must match; `bg` bool. INSERT / UPDATE (on `id`, ownership via its LineId). Overlapping spans of the SAME part on one line are Invalid; different parts may overlap (duet on a phrase).

```php
/** D2 — word grain, for Pass 2's TTML ingest. No editor consumer this pass. Mirrors vocalPartsAssignLines on tblLyricWordVocalParts (WordId, VocalPartId, LyricsId derived word→line→lyrics). */
function vocalPartsAssignWords(\mysqli $db, int $lyricsId, array $wordIds, int $partId, bool $bg = false): int
```
Ownership is by `$lyricsId` here (ingest already holds it), one `SELECT w.Id, l.LyricsId FROM tblLyricWords w JOIN tblLyricLines l ON l.Id = w.LineId WHERE w.Id IN (…)`; any word outside the version → Runtime.

`includes/lyric_rounds.php` (Pass 1 file; this pass fixes the write functions):
```php
const IHYMNS_ROUND_ENTRY_UNITS = ['lines', 'ms', 'beats'];   // VARCHAR vocab, rule #20

function lyricRoundUpsert(\mysqli $db, string $songId, array $input, ?int $userId): array   // RoundShape
function lyricRoundDelete(\mysqli $db, string $songId, int $roundId): bool
function lyricRoundsPruneDegenerate(\mysqli $db, int $lyricsId): int                       // deletes rounds with < 2 voices
/** PURE. lines→ms: the StartTimeMs of line (startSortOrder + offset) within the version's ordered timed lines, or null when untimed / out of range; 'ms' → the offset itself; 'beats' → null until tempo lands. */
function lyricRoundResolveEntryMs(string $unit, int $offset, int $startSortOrder, array $lineStartMsBySortOrder): ?int
```
`lyricRoundUpsert` validation: `startLineId` (required) and `endLineId` (optional) resolved together via `vocalPartsResolveLines()`; `end.sortOrder < start.sortOrder` → Invalid; same-as-start end normalises to NULL (the annotation precedent); `timesThrough` int 1..8 (default 2); `entryUnit` ∈ `IHYMNS_ROUND_ENTRY_UNITS` (default 'lines'); `voices` list of 2..8 `{partId, entryOffset>=0}` with DISTINCT partIds, each part on the same version (else Invalid), sorted by `(entryOffset, given order)` → `SortOrder`; the FIRST voice's offset is normalised to 0 (subtract the minimum from all — a curator who starts at "1" meant relative). Voices are written as delete-then-insert for the round (they are the round's own children; no external FK to preserve). Reads (Pass 3's `lyricRoundsForVersion()`) attach `entryOffsetMs` via the pure resolver.

### 3.6 Carry-over on rewrite (PURE plan + apply) — see §6.3

```php
/** PURE. Pair leftover deleted lines with leftover inserted lines of the SAME ComponentId in ascending SortOrder (zip). Unit-tested.
 *  @param list<array{Id:int, ComponentId:int, SortOrder:int}> $deleted   existing rows in $plan['deleteIds']
 *  @param list<array{Id:int, ComponentId:int, SortOrder:int}> $inserted  freshly inserted rows
 *  @return array<int,int> deletedId => insertedId */
function vocalPartsCarryOverPlan(array $deleted, array $inserted): array

/** Replay saved vocal rows (captured BEFORE the delete) onto their replacement lines. Best-effort; never throws except transaction-fatal. */
function vocalPartsCarryOverApply(\mysqli $db, array $plan, array $savedLineRows, array $savedSpanRows, array $newCpLenById): int
```

---

## 4 — THE CONTROL: `manage/editor/v2/voices-panel.js`

```js
import { componentLineId } from './enrichment-panel.js';
import '/js/modules/combobox-a11y.js';   // side-effect: window.iHymnsComboboxA11y (used only by the musician typeahead host, which place-search.js already wires)

/** Served vocabulary (rule #20/#35 — NEVER a JS copy). editor2.php emits it from IHYMNS_VOCAL_PART_KINDS.
 *  Fallback (un-migrated / harness) is the SMALLEST usable set — the same posture as structure-tab.js's COMPONENT_TYPES. */
const FALLBACK_KINDS = [{ key: 'lead', label: 'Lead' }, { key: 'male', label: 'Men' }, { key: 'female', label: 'Women' }, { key: 'all', label: 'All' }, { key: 'backing', label: 'Backing' }];
function resolveKinds() { const s = window._iHymnsVocalPartKinds; return (Array.isArray(s) && s.length) ? s : FALLBACK_KINDS; }

/**
 * buildVoicesPanel(comp, ctx) -> { el: HTMLElement, refresh(): void, destroy(): void }
 *   ctx: { store, api, songId, toast, ensureSaved }
 *     ensureSaved(comp) -> Promise<boolean>   structure-tab.js §5.1
 */
export function buildVoicesPanel(comp, ctx)
```
editor2.php emits, next to `window._iHymnsSongPartTypes` (:620): `<script>window._iHymnsVocalPartKinds = <?= json_encode(vocalPartKindsForPicker(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>` where `vocalPartKindsForPicker()` (Pass 2's file) returns `list<{key, label, description, gender, needsName:bool, needsOrdinal:bool}>` in map order (`needsName` = key === 'named-singer', `needsOrdinal` = key === 'group').

### 4.1 Layout (top to bottom inside the collapsible)

1. **Toggle** — `<button class="btn btn-sm btn-link p-0 text-decoration-none" aria-expanded="false" aria-controls="<panelId>"><i class="bi bi-people" aria-hidden="true"></i> Who sings</button>`; auto-expanded when the loaded song has ≥ 1 assignment on this component's lines (the `hasChords` precedent). Unlike the enrichment panel, this sets `aria-expanded` (a11y audit finding class).
2. **Run summary** — `<p class="small text-muted mb-1">` built from `vocalParts.runs.filter(r => r.componentId === comp.id)`: "Lines 1–4: Women · Lines 5–8: Men · Line 9: Women + Men (echo)". Empty → "No voices assigned yet."
3. **Line list** — `<div role="group" aria-label="Lines of <headerText(comp)> — tick lines, then assign a voice">`. One row per `comp.lines[i]`:
   ```html
   <div class="d-flex align-items-start gap-2 mb-1 voices-line-row" data-line-index="i" data-line-id="<componentLineId(comp,i)>">
     <input type="checkbox" class="form-check-input mt-1" id="<uid>-l<i>" aria-describedby="<uid>-chips<i>">
     <label class="form-check-label small flex-grow-1" for="<uid>-l<i>">Line <i+1>: <text or (blank line)></label>
     <span id="<uid>-chips<i>" role="group" aria-label="Sung by: Women, Men (echo)">   <!-- house pattern: accessible name on the group -->
       <span class="badge bg-secondary-subtle text-secondary-emphasis" aria-hidden="true">Women</span>
       <button type="button" class="btn-close btn-close-sm" aria-label="Remove Women from line 3"></button>
       …
     </span>
   </div>
   ```
   The `aria-label` of the chip group is rebuilt from `lineAssignments` for that lineId: `displayLabel + (bg ? ' (echo)' : '')` joined by ", ". A span on the line adds "Men on ‘holy, holy’ (words 3–4)" to the same label and a dotted badge.
4. **Selection toolbar** (sticky under the list on wide screens):
   ```html
   <div class="d-flex flex-wrap align-items-center gap-2 mt-2">
     <button type="button" class="btn btn-sm btn-outline-secondary">Select all lines</button>
     <span role="status" aria-live="polite" class="small text-muted"><!-- "3 lines selected" / "Saving section…" / "Assigned Women to lines 1–4." --></span>
   </div>
   <div class="d-flex flex-wrap align-items-end gap-2 mt-1 voices-assign">
     <div>
       <label class="form-label small mb-0" for="<uid>-part">Assign selected lines to</label>
       <select id="<uid>-part" class="form-select form-select-sm">
         <optgroup label="This song's parts">…one <option value="p:<id>"> per vocalParts.parts (displayLabel)…</optgroup>
         <optgroup label="New part…">…one <option value="k:<key>"> per resolveKinds() (label)…</optgroup>
       </select>
     </div>
     <div class="voices-named d-none"><label class="form-label small mb-0" for="<uid>-singer">Singer</label><input id="<uid>-singer" class="form-control form-control-sm" placeholder="Pick a musician…" aria-describedby="<uid>-singer-help"><div id="<uid>-singer-help" class="form-text">Pick from the musician registry; or type a name if they are not listed.</div></div>
     <div class="voices-ordinal d-none"><label class="form-label small mb-0" for="<uid>-ord">Group number</label><input id="<uid>-ord" type="number" min="1" max="9" value="1" class="form-control form-control-sm" style="width:5rem"></div>
     <div class="form-check"><input type="checkbox" class="form-check-input" id="<uid>-bg"><label class="form-check-label small" for="<uid>-bg">Echo / background</label></div>
     <div class="form-check"><input type="checkbox" class="form-check-input" id="<uid>-replace"><label class="form-check-label small" for="<uid>-replace">Replace other voices on these lines</label></div>
     <button type="button" class="btn btn-sm btn-primary" disabled>Assign</button>
   </div>
   ```
   `Assign` is disabled until ≥ 1 line is selected. `.voices-named` is shown only when the select's value is `k:named-singer`; `.voices-ordinal` only for `k:group`. The singer input gets `window.iHymnsPlaceSearch.attach(input, { minChars: 2, pickMode: 'value', noun: {singular:'musician', plural:'musicians'}, searchUrl: (q) => '/manage/editor/api2?action=credit_search&q=' + encodeURIComponent(q) + '&kind=any&limit=10', parseResults: (d) => (d.results || d.suggestions || []).map(s => ({ id: s.id, display_name: s.name })), onSelect: (c) => { picked = { musicianId: c.id, singerName: c.display_name }; } })`; free typing clears `picked.musicianId` (the Source-work precedent :624-632) and the commit sends `singerName` only. The detach fn is returned via `destroy()`.
5. **Words of the selected line** (spans) — visible ONLY when exactly ONE line is selected AND `vocalParts.spansReady`: "Only part of this line? Tick the words:" followed by `<div role="group" aria-label="Words of line 3">` of `<button type="button" class="btn btn-sm btn-outline-secondary" aria-pressed="false">word</button>` per whitespace-split token (tokens computed with `Array.from(text)` code points so offsets are code points; each button carries `data-cp-start`/`data-cp-end`). When ≥ 1 word is pressed the Assign button reads "Assign to words" and the write becomes `upsertVocalSpan` with `start` = min pressed `cpStart`, `end` = max pressed `cpEnd`; non-contiguous presses → inline warning "Tick a continuous run of words" and Assign disabled. Existing spans on the line render as pressed-and-disabled buttons in the part's colour with a remove `×`.
6. **Round** — `<button class="btn btn-sm btn-outline-secondary" disabled>Sing as a round…</button>` enabled when ≥ 2 contiguous lines are selected AND `roundsReady`. Opens an inline form: label (optional), times through (`<input type=number min=1 max=8 value=2>`), then a voice list: 2..8 rows of `<select>` (this song's parts only) + `entry offset` `<input type=number min=0>` with unit text "lines after the first voice" (unit fixed to `lines` in this UI; the `ms`/`beats` vocab is dormant), an "Add voice" button, Save/Cancel. Save → `upsertVocalRound(songId, {startLineId, endLineId, label, timesThrough, entryUnit:'lines', voices})`. Existing rounds covering lines of this component render above the line list as a small card "Round: Women 0 · Men +2 · All +4, 3 times through" with Edit/Delete.
7. **Parts legend** — collapsed "Manage parts (N)" `<details>`: one row per part — displayLabel, kind badge, a `Label` text input (`maxlength=120`, commits on `change`, hide-when-equal happens server-side and the read-back re-fills it — the section-Label precedent), a gender `<select>` (blank/male/female/neutral, disabled+prefilled when the kind implies one), up/down (sortOrder), and Delete (confirm dialog: "Delete Women? It is sung on 12 lines — those assignments will be removed too."). Rendered in EVERY component's panel from the same store slice (it is song-wide); a change in one re-renders all via the subscription (§4.3).

### 4.2 Selection + keyboard

- `selected = new Set()` of line INDEXES, `anchor = -1`. Checkbox `click`: if `event.shiftKey && anchor >= 0` → set every index between `anchor` and `i` to the anchor's NEW state; else toggle `i`; then `anchor = i`. Keydown on a checkbox: `Shift+Space` → same range logic (preventDefault); `Escape` → clear selection (only when ≥ 1 selected; stopPropagation as combobox-a11y does, so an outer modal does not close). "Select all lines" toggles all.
- The status live region announces "<n> line(s) selected" on every change (debounced 150 ms so arrow-key sweeps don't chatter).
- The `<select>`, checkboxes and buttons are native controls — no custom ARIA beyond the group labels; the musician typeahead is `place-search.js`, already a full ARIA 1.2 combobox (#1594).
- Chip remove buttons carry a full `aria-label` ("Remove Women from line 3"); the badge text is `aria-hidden` (house pattern song.php:1307-1311, CLAUDE.md #24).
- Colour is never the only carrier: bg parts get the suffix "(echo)" in text, spans get "(words 3–4)".

### 4.3 Data flow + lifecycle

- Reads: `store.get('vocalParts')` (the §3.1 object, or `null` before hydrate). `parts`, `lineAssignments`, `spans`, `rounds`, `runs` are the ONLY keys the panel reads (the guard asserts this set ⊆ `VOCAL_PARTS_PAYLOAD_KEYS`).
- `const off = store.subscribe('vocalParts', renderBody)` — every write response replaces the slice (`store.set('vocalParts', res.vocalParts)`), so EVERY panel on the page re-renders its body (song-wide parts legend + this component's chips) from one truth. `destroy()` calls `off()` and the typeahead detach. structure-tab.js tracks `voicesPanelDestroyFns` exactly like `cardPickerDetachFns` and runs them at the top of `render()` and in `teardown()` (a stranded subscriber on a wiped card is the leak class #1860 §4.3 fixed).
- `refresh()` re-renders the line rows from the CURRENT `comp.lines`/`comp.lineIds`, preserving `selected` by index (clamped to the new length). structure-tab.js's textarea `input` handler calls it right after `renderChordRows()`.
- 409 from any call → `toast('Voices are not available on this install yet — the vocal-parts migration has not been run.', 'warning')`; 422 → inline `role="alert"` under the toolbar with `err.message`; 404 on a line → ONE automatic `ensureSaved(comp)` + retry (a line renumbered under us), then toast; anything else → `toast('Could not save voices: ' + err.message, 'danger')`. Branch on `err.status` only.

### 4.4 The Assign click — exact control flow (D3 lives here)

```
async function onAssign() {
  if (selected.size === 0) return;
  assignBtn.disabled = true; assignBtn.setAttribute('aria-busy', 'true');
  clearInlineAlert();
  try {
    /* D3 — make sure every selected line has a tblLyricLines.Id. */
    const needsSave = !comp.id || ctx.hasPendingSave(comp) || [...selected].some((i) => componentLineId(comp, i) === 0);
    if (needsSave) {
      status('Saving the section first…');
      const ok = await ctx.ensureSaved(comp);        // structure-tab §5.1; adopts comp.lineIds
      if (!ok) { inlineAlert('The section could not be saved, so no voice was assigned. Fix the problem shown above and try again.'); return; }
      refresh();                                     // rows re-read comp.lineIds
    }
    const lineIds = [...selected].sort((a, b) => a - b).map((i) => componentLineId(comp, i));
    if (lineIds.some((id) => id === 0)) { inlineAlert('This install cannot store voices per line yet (the lyric-lines migration has not been run).'); return; }

    /* Resolve the part: existing (p:<id>) or find-or-create (k:<kind>). */
    let partId;
    const v = partSelect.value;
    if (v.startsWith('p:')) partId = Number(v.slice(2));
    else {
      const kind = v.slice(2);
      const part = { kind, findOrCreate: true };
      if (kind === 'named-singer') { if (!picked.singerName) { inlineAlert('Pick or type the singer\'s name.'); return; } Object.assign(part, picked); }
      if (kind === 'group') part.label = 'Group ' + ordinalInput.value;
      const r = await ctx.api.upsertVocalPart(ctx.songId, part);   // 200 → r.part, r.vocalParts
      partId = r.part.id; store.set('vocalParts', r.vocalParts);
    }

    /* ONE transactional write — lines or words. */
    const bg = bgCheck.checked;
    let res;
    if (pressedWords.size) {
      const [start, end] = pressedWordRange();          // code points, contiguous (validated in the UI)
      res = await ctx.api.upsertVocalSpan(ctx.songId, { lineId: lineIds[0], partId, start, end, bg });
    } else {
      res = await ctx.api.assignVocalLines(ctx.songId, lineIds, partId, bg, replaceCheck.checked ? 'replace' : 'add');
    }
    store.set('vocalParts', res.vocalParts);           // rule #35 — adopt the server's truth wholesale
    status('Assigned ' + partLabel(partId) + (bg ? ' (echo)' : '') + ' to ' + describeLines(lineIds) + '.');
    selected.clear(); pressedWords.clear();
  } catch (err) { handleErr(err); }                     // §4.3 status-code branches
  finally { assignBtn.removeAttribute('aria-busy'); assignBtn.disabled = selected.size === 0; }
}
```
Guarantees: (1) nothing is written client-side before a 200; (2) the ONLY side effect of a failed run is the section save itself, which is the ordinary save the curator's own typing already queued (D3 accepted); (3) a `findOrCreate` part that gets minted before an assignment that then fails is harmless (a part with no lines is a legal, visible row in the legend — and `vocalPartFindOrCreate()` means the retry reuses it, never a duplicate); (4) the store is replaced, never patched, so a `mode:'replace'` or a server fold is always visible.

What the curator SEES on the D3 path: Assign button greys + spinner-less "Saving the section first…" in the live region (typically < 300 ms); on success the chips appear and the summary line updates; on a failed section save the red `role="alert"` under the toolbar plus the section's own red toast (from `saveComponent()`), with ticks preserved so they can retry after fixing the section.

---

## 5 — Hooks in existing files

### 5.1 `structure-tab.js`
```js
import { buildVoicesPanel } from './voices-panel.js';
let voicesPanelDestroyFns = [];                                    // beside cardPickerDetachFns

/** D3 — flush THIS component's pending debounced save now and report whether it landed.
 *  Reuses saveComponent() (never a second save path, rule #25). */
async function ensureSaved(comp) {
    const key = comp._key;
    if (saveTimers.has(key)) { clearTimeout(saveTimers.get(key)); saveTimers.delete(key); }
    pendingSaves.delete(key);
    return saveComponent(comp);                                     // resolves true/false, adopts res.lineIds
}
function hasPendingSave(comp) { return pendingSaves.has(comp._key); }
```
In `buildCard()` after :813: `const voices = buildVoicesPanel(comp, { store, api, songId, toast, ensureSaved, hasPendingSave }); body.appendChild(voices.el); voicesPanelDestroyFns.push(voices.destroy);` and in the textarea `input` handler after `renderChordRows();`: `voices.refresh();`. In `render()` and `teardown()`: run + clear `voicesPanelDestroyFns` next to `cardPickerDetachFns`.

### 5.2 `editor2.php`
- store (:736): add `vocalParts: null`.
- `loadSong()` (:860): `store.set('vocalParts', data.vocalParts || null);` immediately after `lineAnnotations`.
- vocabulary emit (:620 neighbourhood): `window._iHymnsVocalPartKinds` (§4). PHP side: `require_once … 'includes/vocal_parts.php'` where the other registries are loaded (:113 area) and `$vocalPartKindsForJs = vocalPartsTablesReady(getDbMysqli()) ? vocalPartKindsForPicker() : [];` — an un-migrated install serves `[]` so the panel's toggle still renders (the 409 toast explains).

### 5.3 `api-client.js` (after `deleteLineAnnotation`)
```js
/* Vocal parts (#1137 write side, D1-D4). All POST; every response carries the WHOLE song's
   `vocalParts` payload (rule #35 read-back) — callers store.set() it, never merge.
   err.status: 409 un-migrated / no lyrics version, 422 unprocessable, 404 not owned. */
upsertVocalPart:  (songId, part)                          => postJson('vocal_part_upsert',  { songId, part }),
deleteVocalPart:  (songId, partId)                        => postJson('vocal_part_delete',  { songId, partId }),
assignVocalLines: (songId, lineIds, partId, bg, mode)     => postJson('vocal_lines_assign', { songId, lineIds, partId, bg: !!bg, mode: mode || 'add' }),
clearVocalLines:  (songId, lineIds, partId)               => postJson('vocal_lines_clear',  { songId, lineIds, partId: partId || null }),
upsertVocalSpan:  (songId, span)                          => postJson('vocal_span_upsert',  { songId, span }),
deleteVocalSpan:  (songId, id)                            => postJson('vocal_span_delete',  { songId, id }),
upsertVocalRound: (songId, round)                         => postJson('vocal_round_upsert', { songId, round }),
deleteVocalRound: (songId, id)                            => postJson('vocal_round_delete', { songId, id }),
```
`tests/php/test-editor-api2-contract.php` is tree-derived and picks these up with no edit.

---

## 6 — WRITE SAFETY (verified against the real diff)

### 6.1 Truth table — what happens to `tblLyricLineVocalParts`/Spans/Rounds rows on every edit funnel
All funnels (`component_upsert`, `components_replace`, `save_song` v1 + v2, `revision_restore`, `song_importers`, `lyrics_ingest` for Source='ihymns') reach `lyricLinesApplyDesired()`. Verified outcomes:

| edit | diff pass that matches | line Id | vocal rows |
|---|---|---|---|
| untouched line | 1 | kept | **survive** (UPDATE skipped by `lyricLinesRowClean`) |
| reorder inside a section | 1 (FIFO by part+text) | kept | survive |
| move to another section, text unchanged | 2 | kept (ComponentId/PartType rewritten) | survive |
| typo / small edit, same section (similarity ≥ 0.5) | 3 | kept | survive |
| heavy rewrite (< 0.5) in place | none → DELETE + INSERT | **new Id** | cascade-DROPPED today → §6.3 carry-over re-creates them |
| line deleted | none | dropped | cascade-dropped — CORRECT; snapshot logged (§6.2) |
| blank line inserted above | others still match 1 | kept | survive |
| section deleted (`component_delete`) | its lines unmatched | dropped | cascade — correct |
| Paste & Reflow with the same words | 1/2 | kept | survive |
| revision restore | same diff | kept where text matches | survive (rows are not in the snapshot, by design) |
| a NON-'ihymns' version re-ingested (`lyrics_ingest.php:322`) | n/a — `DELETE … WHERE LyricsId` | that version only | that version's rows cascade; Pass 2's ingest re-creates them |
| part deleted | n/a | n/a | FK CASCADE; degenerate rounds pruned (§3.3) |
| span on a line whose text shrinks below `EndOffset` (pass 3 match) | 3 | kept | span row survives but is now OUT OF RANGE → `vocalPartsClampSpans()` (§6.4) |

### 6.2 Extend the orphan snapshot (`lyric_lines_sync.php:1101`)
Inside `lyricLinesSnapshotDeletedEnrichment()`, after the annotations SELECT: `require_once __DIR__.'/vocal_parts.php'; if (vocalPartsTablesReady($db)) { … SELECT Id, LineId, VocalPartId, LyricsId, IsBackground, SortOrder FROM tblLyricLineVocalParts WHERE LineId IN (…) … }` and, when `vocalPartsSpansReady()`, the same for spans; add `'vocalLineParts' => $vlp, 'vocalSpans' => $vsp` to the JSON and include them in the "nothing to snapshot" early-return test. The activity action stays `lyrics.enrichment_orphaned`. The function's return type changes to `array{vocalLineParts:list, vocalSpans:list}` so the caller can reuse the rows for §6.3 without a second SELECT (today it returns void; the one caller is `lyricLinesApplyDesired`).

### 6.3 Carry-over in `lyricLinesApplyDesired()` (:225)
1. Capture `$saved = lyricLinesSnapshotDeletedEnrichment(...)` (now returns the rows) BEFORE the DELETE loop.
2. In the INSERT loop, record `$insertedByDi[$di] = (int)$db->insert_id` and keep `$d['ComponentId']`, `$d['SortOrder']`, `mb_strlen($d['LineText'])`.
3. After the loop, when `$saved['vocalLineParts'] !== [] || $saved['vocalSpans'] !== []`:
   `$plan = vocalPartsCarryOverPlan($deletedRows /* Id, ComponentId, SortOrder from $existing */, $insertedRows);` then `vocalPartsCarryOverApply($db, $plan, $saved['vocalLineParts'], $saved['vocalSpans'], $newCpLenById);`
   Apply = for each `(oldId => newId)`: `INSERT INTO tblLyricLineVocalParts (LineId, VocalPartId, LyricsId, IsBackground, SortOrder) VALUES (newId, …)` (ON DUPLICATE KEY UPDATE IsBackground); spans only when `EndOffset <= newCpLen`. Wrapped in the same best-effort try/catch shape as the snapshot (re-throw only transaction-fatal). Logged as `lyrics.vocal_carried_over` with the pairs.
   Note: `tblLyricRounds.StartLineId` CASCADEs on delete — a round whose start line is rewritten is lost. Carry-over ALSO re-points rounds: before the DELETE, `SELECT Id, StartLineId, EndLineId FROM tblLyricRounds WHERE StartLineId IN (…) OR EndLineId IN (…)`; after inserts, `UPDATE tblLyricRounds SET StartLineId = ? WHERE Id = ?` for a mapped start (done BEFORE the DELETE would be impossible — the new Id does not exist yet — so instead the DELETE of a round's start line is deferred: rounds are read first, and for a start line that HAS a carry-over pair the round row is re-pointed to the new line BEFORE that old line is deleted; ordering inside the function: snapshot → INSERT new lines → re-point rounds → DELETE old lines → UPDATE matched lines). This ordering change is safe: SortOrder uniqueness is not enforced on `tblLyricLines`, and the dirty-check UPDATE path is untouched.
`vocalPartsCarryOverPlan()` (pure): group both lists by `ComponentId`; within each group sort by `SortOrder`; zip index-wise; leftovers unpaired. Truth table in `tests/php/test-vocal-parts-write.php`: (a) one rewritten line ⇒ one pair; (b) two rewritten lines in one section ⇒ two pairs in order; (c) rewritten line in section A + inserted line in section B ⇒ no pair; (d) more deleted than inserted ⇒ the extra deleted line unpaired (rows stay in the snapshot log only).

### 6.4 Spans on a shortened line
`vocalPartsClampSpans(\mysqli $db, array $lineIds)` runs at the end of `lyricLinesApplyDesired()` for every MATCHED-and-UPDATED line id (the dirty ones): `DELETE FROM tblLyricLineVocalSpans WHERE LineId = ? AND EndOffset > ?` (cpLen) — an out-of-range span is meaningless and would 422 on the next edit; the deletion is logged in the same activity row. Gated on `vocalPartsSpansReady()`.

### 6.5 Word grain (D2) under the editor's write
`tblLyricWords` rows exist only for ingested (timed) versions, never for the 'ihymns' version the editor writes (`lyricLinesApplyDesired` never touches `tblLyricWords`; only `lyrics_ingest.php:370` does). So `tblLyricWordVocalParts` rows live on the ingested version and are untouched by any editor save; a re-ingest of that version rebuilds them (Pass 2). Nothing in this pass can orphan them.

---

## 7 — CI guards (rule #34: tree-derived, mutation-proven)

**`tests/test-v2-voices-ui.js`** (Node ESM, mirrors `test-v2-enrichment-ui.js`; comment-stripped both sides):
1. Parse `VOCAL_PARTS_PAYLOAD_KEYS` out of `includes/vocal_parts.php` (regex `const VOCAL_PARTS_PAYLOAD_KEYS\s*=\s*\[([^\]]*)\]`); assert ≥ 5 keys.
2. `editor2.php` contains `store.set('vocalParts', data.vocalParts` and the initial store literal contains `vocalParts:`.
3. `api2.php`'s `load_song` case body (extracted between `case 'load_song':` and the next `case '`) contains `'vocalParts'\s*=>`.
4. Every `api-client.js` method whose action literal starts with `vocal_` has a real `case '<action>':` in api2.php, and there are exactly eight such methods (a floor, so a deleted method fails).
5. Every `store.get('vocalParts')…`/`vp.<key>` property the panel reads (regex over `\bvp\.(\w+)` and `vocalParts\.(\w+)`) is in the parsed key set.
6. The panel contains NO literal from the vocabulary except the FALLBACK_KINDS block (assert the comment-stripped panel source, with the `FALLBACK_KINDS = [...]` literal removed, does not contain `'named-singer'` outside `=== 'named-singer'` comparisons — implement as: count of `'<key>'` occurrences for every key parsed out of `IHYMNS_VOCAL_PART_KINDS` in PHP must be ≤ the count inside `FALLBACK_KINDS` + the two `kind === '…'` comparisons the design allows (`named-singer`, `group`)).
7. `editor2.php` emits `window._iHymnsVocalPartKinds` and the panel reads `window._iHymnsVocalPartKinds`.
8. `structure-tab.js` calls `voices.refresh()` inside the textarea `input` handler (within 600 chars after `renderChordRows();`) and pushes `voices.destroy` into `voicesPanelDestroyFns`.
9. The panel's `onAssign` calls `ctx.ensureSaved(` BEFORE any `assignVocalLines(`/`upsertVocalSpan(` (string index order in the comment-stripped source) — the D3 ordering.
Mutation protocol (each performed, observed RED, restored, re-run GREEN, `git diff` empty): m1 rename a key in the PHP const; m2 delete the `store.set('vocalParts'` line; m3 change one action literal in api-client.js; m4 add `vp.bogus` to the panel; m5 hardcode `'soprano'` in the panel; m6 move `ensureSaved` after the assign call; m7 delete `voices.refresh()`.

**`tests/php/test-vocal-parts-write.php`** (PHP, no DB — pure functions + source assertions): `vocalPartsCarryOverPlan()` truth table (§6.3 a-d); `lyricRoundResolveEntryMs()` table (timed lines, untimed → null, out of range → null, 'ms' passthrough, 'beats' → null); `vocalPartsForSong()`'s `return [` literal keys (parsed from source) set-equal to `VOCAL_PARTS_PAYLOAD_KEYS`; every one of the eight api2 case bodies contains `ed2_requireEntitlement('edit_songs')` AND a `409` respond AND a `422` respond (isolated per case body with `dispatch_parser.php`'s `dispatchParserCasesForSwitch`); `lyricLinesApplyDesired()`'s body references `vocalPartsCarryOverApply(` and `lyricLinesSnapshotDeletedEnrichment()`'s body references `tblLyricLineVocalParts`; none of the eight actions appears in `ED2_GET_SAFE_ACTIONS`. Mutation-proven the same way.

Wire both into `.github/workflows/test.yml` (beside `test-line-enrichment.php` :303 and the `node tests/test-v2-*.js` block) and `package.json`'s test list.

Existing guards that change: `tests/php/fixtures/orphan-allowlist.php` — delete the `'tblVocalParts'` entry (:755); `tests/php/test-manage-action-api-coverage.php` — no change (editor2.php is not a `manage/*.php` POST-action page; the api2 actions are the API twins); `tests/test-component-label-sites.js` — untouched (the panel derives no "Type Number" heading; it calls `headerText`-style text only through a label string, and never references `.label` on a component).

---

## 8 — Plain-English annotation obligations (owner directive)
Every new function carries an ELI5 sentence + the detailed why with links: MDN `aria-pressed`, `aria-live`; WAI-ARIA APG listbox/checkbox group; WCAG 2.1.2 (no keyboard trap — the Escape handling), 4.1.2 (name/role/value — the chip group labels); MySQL `INSERT … ON DUPLICATE KEY UPDATE`; PHP `mb_strlen`; `#1137`, `#2071/#2072/#2075` where relevant; `#1263` for why FK'd rows, not parallel arrays.

`WHATS-NEW.md` (feat, rule #46): "You can now mark who sings each line — men, women, choir, a soloist, an echo — by ticking the lines in the song editor and choosing a voice. Rounds (where voices come in one after another) can be set up too." No internals.

## Files
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/appWeb/public_html/includes/vocal_parts.php` — Pass 2's file — ADD the write half: `VOCAL_PARTS_PAYLOAD_KEYS` const; `vocalPartsSpansReady()`; `vocalPartsResolveLines()`, `vocalPartResolve()`, `vocalPartUpsert()` (hide-when-equal label fold, implied gender, musician existence check), `vocalPartFindOrCreate()`, `vocalPartDelete()` (dependant count + degenerate-round prune), `vocalPartsAssignLines()` (ON DUPLICATE KEY, add|replace), `vocalPartsClearLines()`, `vocalSpanUpsert()`/`vocalSpanDelete()` (reuse `lineEnrichmentValidateOffsets`), `vocalPartsAssignWords()` (D2, ingest consumer), `vocalPartsCarryOverPlan()` (PURE) + `vocalPartsCarryOverApply()`, `vocalPartsClampSpans()`, `vocalPartKindsForPicker()`. Extend `vocalPartsForSong()` with the additive `runs` list via Pass 3's fold.
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/appWeb/public_html/includes/lyric_rounds.php` — Pass 1's core file — the write functions with the exact signatures/validation in §3.5: `IHYMNS_ROUND_ENTRY_UNITS`, `lyricRoundsReady()`, `lyricRoundUpsert()`, `lyricRoundDelete()`, `lyricRoundsPruneDegenerate()`, PURE `lyricRoundResolveEntryMs()`; reader attaches `entryOffsetMs`.
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/appWeb/.sql/migrate-vocal-parts-rounds.php` (NEW) — Pass 1's ONE migration — must carry the §1 columns (`tblLyricLineVocalSpans` incl. `MetaJson`, `tblLyricRounds` incl. `EntryUnit`/`TimesThrough`/`MetaJson`, `tblLyricRoundVoices` incl. `EntryOffset` + `MetaJson`), mirrored byte-identically in schema.sql, one `migration-registry.php` entry with a multi-object OR-probe, includes resolved via `IHYMNS_INCLUDES_DIR` (rule #41).
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/appWeb/public_html/includes/lyric_lines_sync.php` — `lyricLinesSnapshotDeletedEnrichment()` (:1101): also snapshot `tblLyricLineVocalParts` + `tblLyricLineVocalSpans` rows (gated) and RETURN them. `lyricLinesApplyDesired()` (:225): capture the snapshot rows, record `insert_id` per inserted desired index, re-order to snapshot → INSERT → re-point rounds → DELETE → UPDATE, then `vocalPartsCarryOverPlan()`/`…Apply()` and `vocalPartsClampSpans()` on dirty-updated lines. All best-effort, re-throw only `songRelocateIsTransactionFatal()`.
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/appWeb/public_html/manage/editor/api2.php` — Module-scope `require_once` of vocal_parts.php + lyric_rounds.php beside line_enrichment.php; eight new POST cases (`vocal_part_upsert`, `vocal_part_delete`, `vocal_lines_assign`, `vocal_lines_clear`, `vocal_span_upsert`, `vocal_span_delete`, `vocal_round_upsert`, `vocal_round_delete`) per §2 (edit_songs entitlement, 409/422/404, full `vocalParts` read-back, activity keys, NO revision touch); `load_song` gains `'vocalParts' => vocalPartsForSong($db, $songId)` after `lineAnnotations`. Nothing added to ED2_GET_SAFE_ACTIONS.
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/appWeb/public_html/manage/editor/editor2.php` — Store literal (:736) gains `vocalParts: null`; `loadSong()` (:860) adds `store.set('vocalParts', data.vocalParts || null)`; emit `window._iHymnsVocalPartKinds` from `vocalPartKindsForPicker()` next to `window._iHymnsSongPartTypes` (:620), `[]` on an un-migrated install.
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/appWeb/public_html/manage/editor/v2/api-client.js` — Eight `editorApi` methods after `deleteLineAnnotation` (§5.3), doc-commented with the 409/422/404 contract and the read-back rule.
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/appWeb/public_html/manage/editor/v2/voices-panel.js` (NEW) — NEW module: `buildVoicesPanel(comp, ctx) -> {el, refresh, destroy}` — the per-section "Who sings" panel (§4): checkbox run selection with Shift-click/Shift+Space, two-optgroup part `<select>` over served vocabulary + find-or-create, musician typeahead for named-singer, echo + replace flags, word-chip spans, round form, parts legend, live-region status, status-code error branches, D3 `ensureSaved` ordering, store subscription + teardown.
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/appWeb/public_html/manage/editor/v2/structure-tab.js` — Import `buildVoicesPanel`; add `ensureSaved(comp)` + `hasPendingSave(comp)` inside `mountStructureTab()` (reusing `saveComponent`); `voicesPanelDestroyFns` tracked like `cardPickerDetachFns` in `render()`/`teardown()`; `buildCard()` appends the panel after the enrichment panel (:813) and the textarea `input` handler calls `voices.refresh()` after `renderChordRows()`.
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/appWeb/public_html/api-docs.yaml` — Eight `Editor API v2` path items modelled on `?action=line_translation_upsert` (:22467) with 403/404/409/422 responses; `EditorVocalParts` schema (§3.1 keys); `load_song` response gains `vocalParts`.
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/tests/test-v2-voices-ui.js` (NEW) — NEW tree-derived, mutation-proven lockstep guard (§7 items 1-9, mutations m1-m7).
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/tests/php/test-vocal-parts-write.php` (NEW) — NEW: pure truth tables for `vocalPartsCarryOverPlan()` and `lyricRoundResolveEntryMs()`; source assertions on payload-key set-equality, per-case entitlement/409/422 presence, the sync-file hooks, and GET-safe-list abstinence.
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/tests/php/fixtures/orphan-allowlist.php` — Remove the `'tblVocalParts'` entry (:755) from `tables_reader_no_writer` — the table now has a writer; the fixture's own stale-entry assertion would otherwise fail.
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/.github/workflows/test.yml` — Add `php tests/php/test-vocal-parts-write.php` beside `test-line-enrichment.php` (:303) and `node tests/test-v2-voices-ui.js` in the JS block; mirror in package.json's test list (rule #35's npm-vs-CI pair).
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/package.json` — Add the two new test invocations to the test script list, in lockstep with test.yml.
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/WHATS-NEW.md` — One plain-language bullet under the current `## <MAJOR.MINOR> — <date>` heading (feat: → minor bump), no internals.
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/CHANGELOG.md` — Technical entry: vocal-parts write path + Editor2 Who-sings panel + eight api2 actions + carry-over.
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/appWeb/public_html/manage/includes/migration-registry.php` — The existing `'vocal-parts'` card body (:2302) says "reusing tblCreditPeople" — correct the wording to tblMusicians while the file is touched for Pass 1's new registry entry (comment-only).

## Risks
- SILENT-CASCADE CLASS: if the §6.2/§6.3 changes to lyric_lines_sync.php are skipped or land after the editor, every heavy rewrite of a line silently drops its voices with nothing red anywhere (the #2072 shape). Ship the sync-file change in the SAME PR as the first write path, and prove `test-vocal-parts-write.php`'s hook assertions go red when the carry-over call is deleted.
- PASS-1 DDL DRIFT: this write binds to exact column names (`EntryOffset`, `EntryUnit`, `TimesThrough`, `StartOffset`/`EndOffset`, `IsBackground` on spans). If Pass 1 named them differently the INSERTs throw under STRICT at first use — visible, but only at click time. Mitigation: Pass 1 adopts §1 verbatim; `test-schema-coverage.php` already pins DDL↔schema.sql; add a source assertion in `test-vocal-parts-write.php` that every column name used in vocal_parts.php/lyric_rounds.php SQL appears in schema.sql's CREATE for that table.
- REORDERED DELETE/INSERT inside lyricLinesApplyDesired (§6.3) changes the write order of the ONE line write path used by every funnel and the backfill migration. The dirty-check and Id reuse are untouched, but the change must be replayed against `tools/export-fidelity-snapshot.php` (per-song sha256) on a 16k-song copy before merge to prove byte-identical reads, exactly as #1907 did.
- D3 REVISION NOISE: an auto-save creates a `tblSongRevisions` row ('component'); `ed2_touchRevision`'s 15-second dedupe means a curator assigning voices right after typing gets at most one extra row. Accepted by the owner; note it in the panel's help text so a curator is not surprised by 'component' rows in the Revisions tab.
- 409 ON EVERY INSTALL UNTIL THE MIGRATION CARD IS RUN (migrations are web-run, never auto-applied — rule #19): the panel renders on alpha immediately but every write 409s until `/manage/setup-database` runs Pass 1's card. The toast copy names the migration so this is not mistaken for a broken feature (#1565's silent-failure lesson, made loud).
- `vocalPartFindOrCreate()` matching on (kind, Label, MusicianId/SingerName) can still mint a near-duplicate when a curator types 'Solo' then 'Soloist' as singer names for the same person — the musician REGISTRY pick avoids this, the free-text SingerName fallback cannot. Acceptable per the schema's own 'no registry row' column; #1863's audit covers it.
- PRESENT/SHARE SCRAPE (Pass 5's problem, flagged here because the write path feeds it): `present-mode.js:46` reads `.lyric-line` `textContent` — a chip rendered as a CHILD of the line leaks its text ('Women') into the slide unless the renderer uses `aria-hidden` AND excludes it from `textContent` (e.g. a `::before`/`data-` attribute or `.innerText`-safe filtering). Rule #30's silent class: it will 'work' and print 'Women' on every slide.
- SHIFT+SPACE on a checkbox is not a native idiom; some screen readers intercept Space. The plain click/Space toggle path always works, so range selection is progressive enhancement — verify with VoiceOver on the a11y pass rather than assuming.
- CROSS-VERSION PART CONFUSION: `tblVocalParts` is per lyrics VERSION. The editor writes only the 'ihymns' version; a TTML-ingested version has its OWN parts. `vocalPartsForSong()` exposes only the 'ihymns' version's parts to the editor; Pass 3's `vocalPartsWordsForSong()` exposes the others read-only. A future 'copy TTML voices onto the editor version' needs a mapping step, not a shared row.
- The `load_song` payload grows by one query family per load (parts, assignments, spans, rounds, runs). Each is a single indexed query over one version (dozens of rows); no corpus read (rule #17). Confirm with the api2 timing log on a 60-line song that the added cost is < 10 ms.
---

# Design pass 5

## Summary
Pass 4 (render, projection, staggered playback) for voice parts + rounds. Decides the one HTML shape (a per-run `role="group"` wrapper whose visible chip is a SIBLING of the `<p class="lyric-line">`s, never a child, so `textContent` stays pure lyric text and every scraper/cloner keeps working), the theme-aware CSS for light/dark/high-contrast/CVD with no colour-only cue, a tree-derived census of every render site (web, print/PDF, set-list playback re-render, native Android + Apple) with the exact wiring per site through ONE PHP renderer and ONE JS renderer held in lockstep by a shared fixture, a full split-panel round projector in present-mode.js driven by a server-expanded timeline (no client scheduler twin), remote drive + congregant "you are here" via the existing StateJson (no migration), graceful degradation, and a mutation-proven guard that scans .php/.js/.kt/.swift so the native apps cannot silently be left out the way Label was.

## Spec
## 0 — Scope and the shapes this pass consumes

This pass owns: the HTML, the CSS, every render site, the round projector + playback, remote drive/follow, and the guards. It consumes Pass 3's PUBLIC shape on each component:

```
component.voices     (sparse) list<{from:int, to:int, parts:list<{id:int, kind:string, label:string, bg:bool, enters:bool}>}>
component.voiceSpans (sparse) list<{line:int, start:int, end:int, part:{id,kind,label,bg}}>   // offsets are CODE POINTS
component.lineIds    (always) list<int>
```
and Pass 3's `vocalPartsForSong($db,$songId)` → `{ready, spansReady, roundsReady, lyricsId, parts, lineAssignments, spans, rounds}`.

**Cross-pass contract this pass FIXES (Pass 1 §8 must emit exactly this — it is the only shape the projector reads):** every `rounds[]` entry carries

```
round = {
  id:int, kind:'round'|'canon'|'partner-song', label:?string, endingMode:'complete'|'together'|'coda',
  lineIds: list<int>,                                   // = lyricRoundSubjectLineIds(), start..end inclusive, version order
  voices:  list<{number:int, partId:?int, label:string, entryLines:int, entryMs:?int}>,   // label = displayLabel fallback "Voice N"
  timeline: {
    basis: 'ms'|'beats'|'lines',                        // ms: every subject line has StartTimeMs on the ihymns version; beats: bpm+beatsPerLine set; lines: neither
    stepMs: ?int,                                       // beats basis only: (int)round(60000 / bpm * beatsPerLine)
    steps: list<{ i:int, atMs:?int, voices:list<{n:int, line:int}> }>   // line = index into lineIds; -1 = waiting (not yet entered); -2 = finished
  }
}
```
`atMs` is null for basis `lines`; `steps[0].atMs === 0` otherwise. The step list is FULLY EXPANDED server-side (repeats and ending mode already applied). The client never computes a schedule (rule #35 — no JS twin of `lyricRoundTimeline()`).

All paths below are under `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/appWeb/public_html/` unless prefixed with `tests/`, `appAndroid/`, `appApple/` or `.claude/`.

---

## 1 — THE HTML

### 1.1 Rules (load-bearing)

1. A voice chip is NEVER a descendant of `<p class="lyric-line">`. `textContent` of a `.lyric-line` is, and stays, the lyric text alone.
2. A voice RUN (Pass 3's `from..to`) is wrapped in `<div class="lyric-voice-run" role="group" aria-label="…">`; the chip row is the wrapper's first child; the run's `<p>`s (with their `.lyric-chords` and `.lyric-line-translation` siblings) follow inside it.
3. The wrapper sits INSIDE `.lyric-lines`. Descendant selectors are unaffected (verified: no `.lyric-lines > …` selector exists in css/ or js/).
4. Sub-line spans are inline `<span class="lyric-voice-span">` inside the `<p>` and never add text.
5. Every attribute a machine reads is `data-voice-*` / `data-round-*`; every attribute a human reads is `aria-*`. Both are SONG-LEVEL facts, so they are safe in the shared-cache fragment (rule #6).

### 1.2 Exact markup — four cases

**(a) A voice run** (Verse 1 of MP-0120: lines 1-2 Women, 3-4 Men)

```html
<div class="lyric-lines">
  <div class="lyric-voice-run" role="group" aria-label="Women"
       data-voice-parts="[{&quot;id&quot;:10,&quot;kind&quot;:&quot;female&quot;,&quot;label&quot;:&quot;Women&quot;,&quot;bg&quot;:false}]">
    <span class="lyric-voice-chips" aria-hidden="true"><span class="lyric-voice-chip" data-voice-kind="female">Women</span></span>
    <p class="lyric-line mb-1" data-line-id="501">You are holy,</p>
    <p class="lyric-line mb-1" data-line-id="502">You are mighty,</p>
  </div>
  <div class="lyric-voice-run" role="group" aria-label="Men"
       data-voice-parts="[{&quot;id&quot;:11,&quot;kind&quot;:&quot;male&quot;,&quot;label&quot;:&quot;Men&quot;,&quot;bg&quot;:false}]">
    <span class="lyric-voice-chips" aria-hidden="true"><span class="lyric-voice-chip" data-voice-kind="male">Men</span></span>
    <p class="lyric-line mb-1" data-line-id="503">You are worthy,</p>
    <p class="lyric-line mb-1" data-line-id="504">Worthy of praise.</p>
  </div>
</div>
```
Lines with NO voice rows are emitted exactly as today (no wrapper) — a component with no `voices` key renders byte-identically to the current tree.

**(b) A line with two parts** (duet — a run whose parts are [Women, Men], the Men part `enters:true`)

```html
<div class="lyric-voice-run" role="group" aria-label="Women and Men"
     data-voice-parts="[{…Women…},{…Men…}]">
  <span class="lyric-voice-chips" aria-hidden="true"><span class="lyric-voice-chip" data-voice-kind="female">Women</span><span class="lyric-voice-chip" data-voice-kind="male">Men</span></span>
  <p class="lyric-line mb-1" data-line-id="505">I will follow You forever.</p>
</div>
```

**(c) Echo** — whole-line (every part on the line is `bg:true`) and sub-line span:

```html
<!-- whole-line echo: the run's parts are ALL bg -->
<div class="lyric-voice-run lyric-voice-run--bg" role="group" aria-label="Echo, sung by Backing"
     data-voice-parts="[{&quot;id&quot;:12,&quot;kind&quot;:&quot;backing&quot;,&quot;label&quot;:&quot;Backing&quot;,&quot;bg&quot;:true}]">
  <span class="lyric-voice-chips" aria-hidden="true"><span class="lyric-voice-chip lyric-voice-chip--bg" data-voice-kind="backing"><i class="fa-solid fa-reply fa-flip-horizontal" aria-hidden="true"></i>Echo</span></span>
  <p class="lyric-line lyric-line--bg mb-1" data-line-id="506">(You are holy)</p>
</div>

<!-- sub-line echo: the line is sung by Women, "holy" is echoed by Backing (voiceSpans, start=8,end=12 code points) -->
<p class="lyric-line mb-1" data-line-id="507">You are <span class="lyric-voice-span lyric-voice-span--bg" role="group" aria-roledescription="Echo" data-voice-part="12" data-voice-kind="backing" data-voice-bg="1" data-voice-start="8" data-voice-end="12">holy</span>,</p>
```
A line where a lead part AND a bg part are both assigned (Women + Backing(bg)) is NOT an echo line: aria-label "Women, echoed by Backing", chips [Women][↩Echo]; the `<p>` gets no `--bg` class. A NON-bg sub-line span (Men sing three words of a Women line) uses `aria-roledescription="Men part"` (`"{label} part"`) and no `--bg` class.

**(d) A round** — the round's subject `<p>`s gain `data-round-id`; a note block precedes the first subject line; the round list rides `.page-song`:

```html
<article class="page-song" … data-voice-rounds="[{&quot;id&quot;:7,&quot;kind&quot;:&quot;round&quot;,&quot;label&quot;:null,&quot;endingMode&quot;:&quot;together&quot;,&quot;lineIds&quot;:[501,502,503,504],&quot;voices&quot;:[{&quot;number&quot;:1,&quot;partId&quot;:20,&quot;label&quot;:&quot;Voice 1&quot;,&quot;entryLines&quot;:0,&quot;entryMs&quot;:null},{&quot;number&quot;:2,&quot;partId&quot;:21,&quot;label&quot;:&quot;Voice 2&quot;,&quot;entryLines&quot;:2,&quot;entryMs&quot;:null}],&quot;timeline&quot;:{&quot;basis&quot;:&quot;beats&quot;,&quot;stepMs&quot;:2400,&quot;steps&quot;:[…]}}]">
…
<div class="lyric-lines">
  <div class="lyric-round-note" role="note" aria-label="Round for 2 voices. Voice 2 enters 2 lines after Voice 1." data-round-id="7">
    <i class="fa-solid fa-rotate me-1" aria-hidden="true"></i>Round · 2 voices · Voice 2 enters after 2 lines
  </div>
  <p class="lyric-line mb-1" data-line-id="501" data-round-id="7">Row, row, row your boat,</p>
  <p class="lyric-line mb-1" data-line-id="502" data-round-id="7">Gently down the stream;</p>
  …
</div>
```
`data-round-id` on a `<p>` is emitted for EVERY subject line (a round may cross components). The note is emitted ONLY before `lineIds[0]`. A round with `roundsReady=false` or an empty `lineIds` emits nothing.

### 1.3 a11y (cite these in the code comments)

- **WCAG 1.3.1 Info and Relationships** — "who sings this" is conveyed in structure (`role="group"` + `aria-label` per run; `aria-roledescription` per span), not only by visual placement.
- **WCAG 4.1.2 Name, Role, Value** — the run group has a name; the visible chip is `aria-hidden="true"` so the label is not read twice (the song.php:1307-1311 pattern).
- **WCAG 1.4.1 Use of Colour** — the chip TEXT is the cue; echo lines are italic+indented+dashed; round voices carry a numeral + border style.
- **WCAG 1.4.3 / 1.4.11** — chip fg/bg use `--text-primary` on `--surface-elevated` with a `--card-border` border (the tokens `.lyric-label` already passes on), so contrast is inherited from the theme's existing audited pairs.
- **WCAG 2.4.3 / 2.1.2 / 4.1.3** — the projector keeps the existing dialog, focus trap and `announce()` behaviour and announces round steps.
- ARIA 1.2: `paragraph` is a naming-PROHIBITED role, which is WHY the group wrapper exists.

### 1.4 The ONE PHP renderer — `includes/voice_parts_render.php` (NEW)

Pure functions, no DB, no globals. Every string is `htmlspecialchars(…, ENT_QUOTES, 'UTF-8')`-escaped inside these helpers; callers echo the return value raw.

```php
/** lineIdx => ['start'=>bool,'end'=>bool,'parts'=>list<part>,'allBg'=>bool] built from $component['voices']; [] when the key is absent. */
function ihymnsVoiceRunsByLineIndex(array $component): array;

/** lineIdx => list<span> from $component['voiceSpans'], sorted by start; an overlapping span (start < previous end) is DROPPED with error_log() so the HTML stays well-formed. */
function ihymnsVoiceSpansByLineIndex(array $component): array;

/** "Women" | "Women and Men" | "Women, echoed by Backing" | "Echo, sung by Backing" (all-bg) | "Women, Men and Choir" (Oxford-less serial "A, B and C"). Labels are the part's `label` (already display-resolved by Pass 3). */
function ihymnsVoiceRunAriaLabel(array $parts): string;

/** <span class="lyric-voice-chips" aria-hidden="true">…one .lyric-voice-chip per part…</span>; bg parts get `lyric-voice-chip--bg` + the fa-reply icon + the word "Echo" when the label is the kind default, else "{label} (echo)". $opts: ['chipClass'=>'lyric-voice-chip','rowClass'=>'lyric-voice-chips','a11y'=>true,'dataAttrs'=>true]. */
function ihymnsVoiceChipsHtml(array $parts, array $opts = []): string;

/** Escaped line HTML with inline spans; slices by CODE POINT via mb_substr(…, 'UTF-8') (rule #21). $spans already per-line. Returns htmlspecialchars($text) when $spans === []. */
function ihymnsVoiceLineHtml(string $text, array $spans, array $opts = []): string;

/** Opening tag for a run wrapper: <div class="lyric-voice-run[ lyric-voice-run--bg]" role="group" aria-label="…" data-voice-parts="…json…">. JSON = json_encode(list of {id,kind,label,bg}, JSON_UNESCAPED_UNICODE) then htmlspecialchars ENT_QUOTES. */
function ihymnsVoiceRunOpenTag(array $parts, array $opts = []): string;

/** The <div class="lyric-round-note" role="note" …> block for the FIRST subject line of a round; '' when $round['lineIds'] is empty. Text: "Round · N voices · Voice k enters after n lines" (one clause per voice with entryLines > 0; "at 0:04" when entryMs is set and basis is ms). */
function ihymnsVoiceRoundNoteHtml(array $round): string;

/** lineId => round (first-wins) for note placement; and lineId => roundId for data-round-id on every subject line. */
function ihymnsVoiceRoundIndex(array $rounds): array;   // ['noteAt' => [lineId => round], 'lineRound' => [lineId => roundId]]

/** The value for `.page-song[data-voice-rounds]`: json_encode of the rounds list with ONLY the §0 keys (id, kind, label, endingMode, lineIds, voices, timeline), then htmlspecialchars ENT_QUOTES. '' when $rounds === []. */
function ihymnsVoiceRoundsDataAttr(array $rounds): string;
```

### 1.5 song.php changes (`includes/pages/song.php`)

Above the component loop (near the `$songLanguageUnion` setup, ~line 1232):
```php
require_once __DIR__ . '/../voice_parts_render.php';
require_once __DIR__ . '/../vocal_parts.php';
$voiceRounds = [];
try {
    $_vp = vocalPartsForSong(getDbMysqli(), (string)$song['id']);   // Pass 3 §2 — never throws by contract, belt+braces anyway
    $voiceRounds = $_vp['rounds'] ?? [];
} catch (\Throwable $e) { error_log('[song.php] vocalPartsForSong: ' . $e->getMessage()); }
$roundIdx = ihymnsVoiceRoundIndex($voiceRounds);
```
On the `<article class="page-song" …>` tag (line 524): append `<?php if ($voiceRounds !== []): ?> data-voice-rounds="<?= ihymnsVoiceRoundsDataAttr($voiceRounds) ?>"<?php endif; ?>`.

Inside the per-component block, replace the `foreach ($lines as $lineIdx => $line)` body (lines 1324-1360) with:
```php
<?php $voiceRuns = ihymnsVoiceRunsByLineIndex($component); $voiceSpans = ihymnsVoiceSpansByLineIndex($component); ?>
<div class="lyric-lines">
<?php foreach ($lines as $lineIdx => $line): ?>
    <?php
        $lineId  = (int)($lineIds[$lineIdx] ?? 0);
        $lineTr  = ($lineId > 0) ? ($lineTranslationsByLineId[$lineId] ?? []) : [];
        $chordHtml = $songHasChords ? ihymns_render_chord_line_html($compChords[$lineIdx] ?? '') : '';
        $vr = $voiceRuns[$lineIdx] ?? null;
        $roundId = ($lineId > 0) ? ($roundIdx['lineRound'][$lineId] ?? 0) : 0;
    ?>
    <?php if ($vr !== null && $vr['start']): ?><?= ihymnsVoiceRunOpenTag($vr['parts']) ?><?= ihymnsVoiceChipsHtml($vr['parts']) ?><?php endif; ?>
    <?php if ($lineId > 0 && isset($roundIdx['noteAt'][$lineId])): ?><?= ihymnsVoiceRoundNoteHtml($roundIdx['noteAt'][$lineId]) ?><?php endif; ?>
    <?php if ($chordHtml !== ''): ?><div class="lyric-chords" aria-hidden="true"><?= $chordHtml ?></div><?php endif; ?>
    <p class="lyric-line<?= ($vr !== null && $vr['allBg']) ? ' lyric-line--bg' : '' ?> mb-1"<?php if ($lineId > 0): ?> data-line-id="<?= $lineId ?>"<?php endif; ?><?php if ($roundId > 0): ?> data-round-id="<?= $roundId ?>"<?php endif; ?>><?= ihymnsVoiceLineHtml($line, $voiceSpans[$lineIdx] ?? []) ?></p>
    <?php foreach ($lineTr as $lt): ?> …unchanged translation <p>… <?php endforeach; ?>
    <?php if ($vr !== null && $vr['end']): ?></div><?php endif; ?>
<?php endforeach; ?>
</div>
```
Existing comment blocks (#1266, #1089/#1100, #299) stay verbatim; add a new comment block explaining rules 1-5 of §1.1 in both registers (ELI5 + why).

---

## 2 — THE CSS (`css/app.css`, new section after `.lyric-line` at ~line 1610; `css/print.css`; `css/accessibility.css`)

Tokens are defined on bare `:root` (light) and redefined under `[data-bs-theme="dark"]` and `[data-ihymns-theme="high-contrast"]`, following the existing `--lyric-chorus-*` placement (app.css:145, :260, :331).

```css
:root {
    --voice-chip-bg: var(--surface-elevated);
    --voice-chip-fg: var(--text-primary);
    --voice-chip-border: var(--card-border);
    --voice-run-rule: var(--card-border);            /* thin left rule per run */
    --voice-bg-rule: var(--text-muted);              /* dashed rule for echo lines */
    --voice-1: #2563eb; --voice-2: #b45309; --voice-3: #047857; --voice-4: #7c3aed;   /* round panels — hue is ADDITIVE to numeral + border-style */
}
[data-bs-theme="dark"] { --voice-1: #60a5fa; --voice-2: #fbbf24; --voice-3: #34d399; --voice-4: #a78bfa; }
[data-ihymns-theme="high-contrast"] { --voice-chip-bg:#fff; --voice-chip-fg:#000; --voice-chip-border:#000; --voice-run-rule:#000; --voice-bg-rule:#000; --voice-1:#0000cc; --voice-2:#cc0000; --voice-3:#006600; --voice-4:#660066; }

.lyric-voice-run { border-left: 2px solid var(--voice-run-rule); padding-left: .6rem; margin: .35rem 0 .5rem; }
.lyric-voice-chips { display: block; margin-bottom: .2rem; }
.lyric-voice-chip { display: inline-block; font-size: .7rem; font-weight: 700; text-transform: uppercase; letter-spacing: .08em;
    color: var(--voice-chip-fg); background: var(--voice-chip-bg); border: 1px solid var(--voice-chip-border);
    padding: .1em .55em; border-radius: 999px; margin-right: .3rem; }
.lyric-voice-chip--bg { border-style: dashed; font-style: italic; text-transform: none; }
.lyric-voice-chip--bg .fa-reply { margin-right: .3em; }
.lyric-voice-run--bg { border-left-style: dashed; border-left-color: var(--voice-bg-rule); }
.lyric-line--bg { font-style: italic; padding-left: 1.25rem; color: var(--text-secondary); }
.lyric-voice-span { font-style: italic; text-decoration: underline dotted; text-underline-offset: .2em; }
.lyric-voice-span--bg::before { content: "\2936"; /* ⤶ */ font-size: .8em; margin-right: .15em; }   /* generated content is NOT in textContent */
.lyric-round-note { font-size: .8rem; color: var(--text-secondary); border: 1px dashed var(--voice-chip-border); border-radius: var(--radius-sm); padding: .25rem .6rem; margin: .25rem 0 .5rem; }

/* Follow-mode "you are here" (service-follow / live-follow) */
.lyric-line[data-voice-now] { position: relative; padding-left: 1.6rem; }
.lyric-line[data-voice-now]::before { content: attr(data-voice-now); position: absolute; left: 0; top: .1em; min-width: 1.2rem; text-align: center;
    font-size: .7rem; font-weight: 700; border-radius: 999px; border: 2px solid var(--voice-chip-fg); color: var(--voice-chip-fg); background: var(--voice-chip-bg); }
.lyric-line[data-voice-now="1"]::before { border-style: solid; }  .lyric-line[data-voice-now="2"]::before { border-style: dashed; }
.lyric-line[data-voice-now="3"]::before { border-style: dotted; } .lyric-line[data-voice-now="4"]::before { border-style: double; }
.lyric-line[data-voice-mine="1"] { background: var(--lyric-chorus-bg); border-radius: var(--radius-sm); }

/* Presentation overlays (display.js clone + present-mode) — scoped by class so the clone inherits */
.presentation-lyrics .lyric-voice-chip, .present-lyrics .present-voice-chip { font-size: .45em; color:#fff; background: rgba(255,255,255,.14); border-color: rgba(255,255,255,.35); }
.present-lyrics .present-line--bg { font-style: italic; opacity: .85; padding-left: 1em; }

/* Round projector (present-mode.js) */
.present-round { display: grid; grid-template-columns: repeat(var(--voices, 2), minmax(0, 1fr)); gap: 2vmin; width: 100%; max-width: 96vw; }
.present-round[data-voices="3"], .present-round[data-voices="4"] { grid-template-columns: repeat(2, minmax(0, 1fr)); }
.present-round--stacked { grid-template-columns: 1fr !important; }
@media (orientation: portrait) { .present-round { grid-template-columns: 1fr; } }
.present-voice { border-top: .6vmin solid var(--voice-color, #fff); padding: 1vmin 1.5vmin; min-height: 30vh; }
.present-voice[data-voice="1"] { --voice-color: var(--voice-1); border-top-style: solid; }
.present-voice[data-voice="2"] { --voice-color: var(--voice-2); border-top-style: dashed; }
.present-voice[data-voice="3"] { --voice-color: var(--voice-3); border-top-style: dotted; }
.present-voice[data-voice="4"] { --voice-color: var(--voice-4); border-top-style: double; }
.present-voice-head { display: flex; align-items: center; gap: .6em; font-size: .45em; text-transform: uppercase; letter-spacing: .08em; opacity: .9; }
.present-voice-num { display: inline-flex; width: 1.6em; height: 1.6em; align-items: center; justify-content: center; border-radius: 999px; border: .15em solid currentColor; font-weight: 800; }
.present-voice-prev, .present-voice-next { opacity: .45; font-size: .8em; }
.present-voice-now { font-weight: 600; }
.present-voice--waiting .present-voice-now, .present-voice--done .present-voice-now { font-style: italic; opacity: .7; }
.present-round-rail { display: flex; gap: .4em; justify-content: center; margin-top: 1vmin; }
.present-round-rail span { width: .5em; height: .5em; border-radius: 999px; background: rgba(255,255,255,.25); }
.present-round-rail span[aria-current] { background: #fff; }
@media (prefers-reduced-motion: reduce) { .present-voice-now, .present-voice-prev, .present-voice-next { transition: none !important; } }
```
`css/print.css`: `.print-voice-chip { font-size: .7em; font-weight: 700; text-transform: uppercase; border: 1px solid #000; border-radius: 999px; padding: 0 .4em; margin-right: .3em; } .print-line--bg { font-style: italic; padding-left: 1.2em; } .print-voice-span { font-style: italic; text-decoration: underline dotted; }`.
`css/accessibility.css`: under the existing `[data-ihymns-cvd]` section add `[data-ihymns-cvd] .lyric-voice-chip { border-width: 2px; }` and `[data-ihymns-cvd] .present-voice { border-top-width: 1vmin; }` — the border STYLE per voice is already hue-independent; CVD just thickens it (the songbook-badge precedent at accessibility.css:363-368).
Contrast note for the comment: `--voice-1..4` are only ever used as a border/accent beside a numeral, never as the sole text colour, so 1.4.3 is not engaged by them; the chips reuse `--text-primary`/`--surface-elevated`.

---

## 3 — THE RENDER-SITE CENSUS (tree-derived 2026-09-04)

Derived by `grep -rl 'lyric-line\|lyric-component'` (DOM consumers), `grep -rl '\.lines\b'` (JSON re-renderers, JS), `grep -rl "\['lines'\]"` (PHP), and `find appAndroid appApple -name '*.kt' -o -name '*.swift' | xargs grep -l lines`.

| # | Surface | Kind | Inherits? | Wiring required |
|---|---|---|---|---|
| 1 | `includes/pages/song.php` | server render (the reference) | — | §1.5 |
| 2 | `js/modules/display.js` enterPresentationMode (:659 clones `lyricsEl.innerHTML`), practice mode, jumpToSection | DOM clone / class toggles | **FREE** (chips + notes + spans ride the clone; CSS scoped by class) | none — add `.presentation-lyrics .lyric-voice-chip` rule (§2) only |
| 3 | `js/modules/present-mode.js` | DOM scrape → slides | text stays clean (chips are siblings) but voices are LOST | **REWRITE** §4 — read `.lyric-voice-run[data-voice-parts]`, `.lyric-line--bg`, `[data-round-id]`, `.page-song[data-voice-rounds]` |
| 4 | `js/modules/share.js:166-172` | DOM scrape (2-line snippet) | **FREE and CORRECT** — snippet must not contain 'Women' | none; guard asserts it never reads `.lyric-voice-chip` |
| 5 | `js/modules/song-markup.js`, `song-translations.js`, `midi-input.js`, `service-follow.js`, `live-follow.js`, `service-broadcast.js` (chips only) | query by `[data-line-id]` / `.lyric-component` index / `.lyric-label` | **FREE** (no line content read) | follow modules get the §4.5 round-follow hook (additive) |
| 6 | `js/modules/setlist.js:2164-2174` arrangement preview | JSON re-render of `.lyric-component` markup | NO | replace the inline template's `.lyric-lines` body with `renderComponentLinesHtml(comp)` from `js/modules/voice-parts-render.js` |
| 7 | `js/modules/setlist.js:2839-2848` playback custom-arrangement re-render (REPLACES `.song-lyrics` innerHTML) | JSON re-render | NO — chips vanish today the moment an arrangement is applied | same call as #6; ALSO must emit `data-line-id` from `comp.lineIds[i]` and `data-round-id` (from `.page-song[data-voice-rounds]` lineIds) so follow/markup keep working — extract the duplicated template at :2164 and :2839 into ONE local `renderArrangedComponent(comp)` that calls the shared renderer (rule #22) |
| 8 | `js/modules/print.js` renderLyrics (:233-258) + `includes/pdf_renderer.php` (renders the POSTed bodyHtml) | JSON re-render → browser print + server PDF | NO (PDF inherits from print.js's HTML) | `renderComponentLinesHtml(comp, {lineClass:'print-line', lineBgClass:'print-line--bg', chipClass:'print-voice-chip', rowClass:'print-voice-chips', spanClass:'print-voice-span', a11y:false, dataAttrs:false, showVoices: block.showVoices !== false})`; new lyrics-block option `showVoices:true` in `includes/print_template_schema.php` AND its client mirror (guarded by `tests/php/test-print-block-registry.php`); the `print` sanitiser profile already admits `div/span/p[class]` — verify with `test-html-sanitizer`-style assertion that `<span class="print-voice-chip">` survives `ihymnsSanitizeHtml($html,'print')` |
| 9 | `manage/editor/v2/preview-tab.js:61-65` (`body.textContent = lines.join('\n')`) | JSON re-render (editor) | NO | build lines via `renderComponentLinesHtml(c, {lineClass:'preview-line'})` into `body.innerHTML` (the renderer escapes) — the editor shape (Pass 3 §editor) carries `voices` too; if it does not, read `voiceRunsByLineIndex()` from the editor's `vocal` payload (`lineAssignments` keyed by `lineIds[i]`) — the renderer accepts EITHER via `opts.assignmentsByLineId` |
| 10 | `og-image.php:534`, `index.php:418` (first-two-lines text) | text snippet | **FREE and CORRECT** (text only) | none |
| 11 | `includes/easyworship_export.php`, `manage/editor/propresenter-export.js` | machine export | must NOT carry voices | guard: zero `voices` references |
| 12 | `manage/editor/format-export.js` (OpenLyrics `<lines>`) | machine export | #2071 — MUST emit `part="…"` | emit `<lines part="{kind}">` per run using `voices[].parts[0].kind` (single-part runs; multi-part runs emit no `part`), chunk-only `<lines>` blocks keep no attribute; guard: references `voices` and `.kind`, ZERO `displayLabel`/`.label` |
| 13 | `appAndroid/…/models/Song.kt` `SongComponent` (type, number, lines) + `ui/screens/SongDetailScreen.kt:203-360` | native render | NO — model lacks `voices` | add `val voices: List<VoiceRun> = emptyList()` + `VoiceRun(from,to,parts:List<VoicePart>)` + `VoicePart(id,kind,label,bg)` (defaults, so old payloads decode); `SongComponentSection` renders a `Row` of chip `Text`s (labelSmall, uppercase) before line `from` of each run; bg lines italic + start padding; wrap each run in `Modifier.semantics { contentDescription = ariaLabel }` where ariaLabel is built by the SAME serial-join rule (§1.4 `ihymnsVoiceRunAriaLabel`) — ported, and pinned by the fixture (§5.2) via a Kotlin unit test reading `tests/fixtures/voice-render-cases.json` |
| 14 | `appApple/Packages/iHymnsKit/Sources/IHModels/SongComponent.swift` + `IHFeatures/SongComponentView.swift` + `IHFeatures/ProjectionCanvasView.swift` (+ `ProjectionResolver.swift` line mode) | native render + native projector | NO | `public let voices: [VoiceRun]?` (optional decode); `SongComponentView` renders a chip `HStack` before line `from` with `.accessibilityElement(children: .contain).accessibilityLabel(ariaLabel)`; `ProjectionCanvasView` shows the current line's chip in line mode; round projection on tvOS is a tracked follow-up issue (the guard requires `voices` in these files now; rounds later) |

Sites 5 (service-broadcast section chips) and `js/utils/components.js` are label-only and untouched.

### 3.1 The ONE JS renderer — `js/modules/voice-parts-render.js` (NEW, ES module, no DOM globals)

```js
import { escapeHtml } from '../utils/html.js';
export function voiceRunsByLineIndex(comp, assignmentsByLineId = null)   // mirror of ihymnsVoiceRunsByLineIndex; when comp.voices is absent and assignmentsByLineId given, folds runs from {lineId: [part…]} using comp.lineIds (editor shape)
export function voiceSpansByLineIndex(comp)
export function voiceRunAriaLabel(parts)                                   // byte-identical to PHP (fixture-pinned)
export function voiceChipsHtml(parts, opts = {})
export function voiceLineHtml(text, spans, opts = {})                      // Array.from(text) code-point slicing (rule #21)
export function voiceRunOpenTag(parts, opts = {})
export function renderComponentLinesHtml(comp, opts = {})
/* opts defaults: { lineClass:'lyric-line mb-1', lineBgClass:'lyric-line--bg', runClass:'lyric-voice-run', chipClass:'lyric-voice-chip', rowClass:'lyric-voice-chips', spanClass:'lyric-voice-span', a11y:true, dataAttrs:true, showVoices:true, lineIds:true, roundByLineId:null }
   Emits EXACTLY the §1.2 markup (minus chords/translations, which callers interleave themselves). With showVoices:false emits plain <p>s only. */
export function parseRoundsAttr(pageEl)                                    // JSON.parse(pageEl?.dataset.voiceRounds || '[]') in try/catch → []
export function collectSlideModel(pageEl)                                  // §4.2 — DOM → structured model for present-mode.js
```

---

## 4 — STAGGERED PROJECTION + PLAYBACK (D1)

### 4.1 What the projector shows

**Plain slide (unchanged look + voices):** label, then for each run a chip row `<div class="present-voice-chips"><span class="present-voice-chip" data-voice-kind>Women</span></div>` followed by the run's lines as `<div class="present-line[ present-line--bg]">`; sub-line spans as `<span class="present-voice-span[--bg]">`. Built with `createElement`/`textContent` (never innerHTML with page text — the current `lyricsEl.textContent = slide.text` line is replaced by DOM building).

**Round slide (2 voices, split layout):**
```
┌──────────────── Round · Row, row, row your boat ────────────────┐
│ ① VOICE 1                     │ ② VOICE 2 (dashed rule)         │
│  Row, row, row your boat,     │                                 │
│ ▶ Gently down the stream;     │ ▶ Enters in 1 line              │
│  Merrily, merrily…            │                                 │
├──────────────────────────────────────────────────────────────────┤
│  ● ● ○ ○ ○ ○ ○ ○     Step 2 of 8        ⏵ Play   Layout   Reset │
└──────────────────────────────────────────────────────────────────┘
```
Each panel: `.present-voice[data-voice=N] role="group" aria-label="Voice N, {label}"` with `.present-voice-head` (numeral badge + label), `.present-voice-prev` (line-1, dim), `.present-voice-now` (`aria-current="step"`), `.present-voice-next` (dim). State classes: `--waiting` (now = "Enters in n lines" / "Enters in m:ss" when atMs known), `--singing`, `--done` (now = "Finished" + check icon; for `endingMode:'together'` the server's last steps hold every voice on the final line so 'done' only appears after that). Rail: one dot per step, current has `aria-current="true"`. Stacked layout (`L`): `.present-round--stacked`, each panel shows only head + now.

### 4.2 Slide model (present-mode.js, replacing lines 42-49)

```js
const model = collectSlideModel(document.querySelector('.page-song'));
/* model = { title, rounds: Map<roundId, round>, components: [{ label, lines:[{ text, lineId, runStart:bool, parts:[…], bg:bool, spans:[…], roundId:?int }] }] } */
const slides = [];
for (const comp of model.components) {
    const rid = comp.lines.find(l => l.roundId && model.rounds.get(l.roundId)?.lineIds[0] === l.lineId)?.roundId;
    if (rid) slides.push({ kind:'round', round: model.rounds.get(rid), label: comp.label, textByLineId: model.textByLineId });
    slides.push({ kind:'plain', comp });
}
```
`collectSlideModel()` reads: `.lyric-component` → `.lyric-label` text, then each `.lyric-line` (descendant): `text = textContent`, `lineId = dataset.lineId`, `roundId = dataset.roundId`, `bg = classList.contains('lyric-line--bg')`, `parts = JSON.parse(closest('.lyric-voice-run')?.dataset.voiceParts || '[]')`, `runStart = (line is the first .lyric-line inside its run wrapper)`, `spans = [...line.querySelectorAll('.lyric-voice-span')].map(s => ({start:+s.dataset.voiceStart, end:+s.dataset.voiceEnd, bg:s.dataset.voiceBg==='1', kind:s.dataset.voiceKind}))`. `textByLineId` maps lineId → text so a round slide can resolve `round.lineIds` even across components. A round whose `lineIds` contain an id absent from the page is skipped (console.warn, plain slide still shown).

### 4.3 Round slide state + controls

```js
state = { step:0, playing:false, t0:null /* performance.now() at step 0 */, layout: localStorage('ihymns_present_round_layout') || 'split', raf:null }
renderRound(slide, announceStep):
  s = slide.round.timeline.steps[state.step]
  for each voice v (by number): entry = s.voices.find(x => x.n === v.number)
     line  = entry.line;  now = line >= 0 ? text(lineIds[line]) : (line === -1 ? waitingText(v) : 'Finished')
     prev  = line >= 1 ? text(lineIds[line-1]) : '';  next = (line >= 0 && line+1 < lineIds.length) ? text(lineIds[line+1]) : ''
  counter = `Step ${state.step+1} of ${steps.length}`
  if announceStep: announce(counter + changes.map(c => `Voice ${c.n} ${c.kind}`).join('. '))   // kind ∈ {'enters','finished'} computed by diffing line states with the previous step
next(): if slide.kind==='round' && state.step < steps.length-1 → state.step++, renderRound(true); else → next slide (resets state.step=0, stops playback)
prev(): symmetric (step-- else previous slide)
play(): only if timeline.basis !== 'lines'; state.t0 = performance.now() - (steps[state.step].atMs); tick via requestAnimationFrame: while (steps[state.step+1] && performance.now()-t0 >= steps[state.step+1].atMs) advance; at last step → stop, announce('Round finished')
pause(): cancelAnimationFrame; reset(): step=0, pause, render
```
Keys (in `onKey`, only while the current slide is a round; all other keys unchanged): ArrowRight/Space/PageDown = next step, ArrowLeft/PageUp = prev step, `Enter` = play/pause when `document.activeElement` is not a `<button>`, `Home` = reset, `L` = layout toggle, `T` = show this round's lines as a plain slide instead (toggle). Buttons `.present-play` (`aria-pressed`), `.present-layout`, `.present-reset` are added to `.present-nav` and are `hidden` on plain slides (`el.hidden`, never `style.display`). `.present-play` is `disabled` with `title="No timing for this round — step it manually"` when `basis==='lines'`. The guard (§5.1 check 6) asserts `js/app.js` has no `case 'l'|'L'|'t'|'T'|'Enter'|'Home'` so these cannot collide with the document-level key handler app.js installs (a mechanism, rule #35).

Reduced motion: no transitions; playback still advances (it is content, not decoration) but `announce()` throttles to at most one announcement per step.

### 4.4 Remote drive (operator console → congregants)

`includes/service_mode.php` `serviceMode_cleanState()` gains ONE allow-listed key (no migration — StateJson, service_mode.php:208):
```php
if (isset($state['round']) && is_array($state['round'])) {
    $r = $state['round']; $rid = (isset($r['id']) && is_numeric($r['id'])) ? (int)$r['id'] : 0;
    if ($rid > 0) {
        $clean['round'] = [
            'id'        => $rid,
            'step'      => (isset($r['step']) && is_numeric($r['step'])) ? max(0, min(9999, (int)$r['step'])) : 0,
            'playing'   => !empty($r['playing']),
            'startedAt' => (isset($r['startedAt']) && is_numeric($r['startedAt']) && (int)$r['startedAt'] > 0) ? (int)$r['startedAt'] : null,   // SERVER epoch ms of step 0
            'layout'    => (isset($r['layout']) && in_array($r['layout'], ['split','stacked'], true)) ? $r['layout'] : 'split',
        ];
    }
}
```
`api.php`: `service_broadcast`, `live_follow_broadcast`, `service_poll`, `live_follow_poll` responses each add `'serverNowMs' => (int)round(microtime(true) * 1000)`; the two poll responses must return the decoded `state` object (add `'state' => $row['StateJson'] !== null ? json_decode($row['StateJson'], true) : null` where not already present). `api-docs.yaml`: add `state.round` (object, the five keys above) to the broadcast request schemas and `serverNowMs` + `state` to the four responses in the SAME change (rule #48).

`js/modules/service-broadcast.js` (the docked console on service-projection.php and service-lead.php): when the picked song's `song_detail` payload carries `vocalParts.rounds` (Pass 3), the section chip of the component holding `round.lineIds[0]` gets a nested round stepper (`◀ Step k/n ▶  ⏵`), and each change POSTs `state.round` through the SAME `broadcast()` path (de-dupe key extended to `songId|componentIndex|roundId|step|playing`). `startedAt` = `lastServerNowMs + (Date.now() - lastResponseAt) - steps[step].atMs`. `present-mode.js` does NOT broadcast (it is the single-screen tool); the console does.

### 4.5 Congregant follow ("you are here" per voice)

New shared module `js/modules/voice-round-follow.js` (rule #22 — BOTH `service-follow.js` and `live-follow.js` call it from `_applyFollowState()`):
```js
export function applyRoundFollow(pageEl, state, serverSkewMs)   // state = decoded StateJson (may be null)
```
- Clears every `[data-voice-now]`/`[data-voice-mine]` first (rule #32 shape: teardown before any early return).
- If `!state?.round` → return. Look up the round in `parseRoundsAttr(pageEl)`; if absent → return.
- Compute `step`: if `state.round.playing && state.round.startedAt && timeline.basis !== 'lines'` → run a local rAF clock from `startedAt + skew` over `steps[].atMs` (server-expanded — no maths); else `step = state.round.step`.
- For each voice in `steps[step].voices` with `line >= 0`: `pageEl.querySelector('.lyric-line[data-line-id="' + round.lineIds[line] + '"]')?.setAttribute('data-voice-now', String(n))`.
- `myVoice = sessionStorage.getItem('ihymns_follow_voice')` (per-device, never in the fragment — rule #6); if set, that voice's line also gets `data-voice-mine="1"` and is `scrollIntoView({behavior: prefersReducedMotion() ? 'auto' : 'smooth', block:'center'})`; `announce('Voice N: ' + text)` at most once per step.
- The follow banner (`#service-follow-banner`, and live-follow's host bar) gains a `<select aria-label="Your voice">` with "All voices" + one option per round voice, shown only while `state.round` is present.

### 4.6 Degradation ladder

| Condition | Behaviour |
|---|---|
| Voice tables un-migrated (`ready=false`) | Pass 3 emits no `voices`/`voiceSpans`; song.php emits today's markup byte-for-byte; present-mode's model has empty parts → identical to current slides |
| Round tables un-migrated (`roundsReady=false`) or no rounds | no `data-voice-rounds`, no round slides, no stepper, no follow hook effect |
| Round present but `timeline.basis==='lines'` | round slide + manual stepping; Play disabled with reason; follow uses `state.round.step` only |
| `timeline.steps` missing/malformed | round slide skipped (console.warn), plain slide shown — never a throw (the slide builder is wrapped in try/catch per round) |
| Old browser (no `CSS.supports('display','grid')` or no `requestAnimationFrame`) | `present-round--stacked` is forced (block layout works without grid) and Play is disabled |
| `prefers-reduced-motion` | no transitions; playback still steps |
| Fragment served from cache before/after migration | all data is song-level; cache invalidation is Pass 3's (the assembled shape changes → the fragment ETag changes) |

---

## 5 — THE CI GUARDS (rule #34: tree-derived, mutation-proven)

### 5.1 `tests/test-voice-part-sites.js` (NEW, node; auto-run by `tools/run-node-tests.js`'s glob)

Walks FOUR roots: `appWeb/public_html` (`.js`, `.php`; skip `vendor/`, `node_modules/`, `*.min.js`), `appAndroid` (`.kt`), `appApple/Packages/iHymnsKit/Sources` (`.swift`). Comment-strips (`/* */`, `//` line comments for js/kt/swift, `<!-- -->`, PHP `#` lines) before every scan.

A file is a **line renderer** when EITHER idiom matches in stripped source:
- (a) it emits a lyric-line class literal: `/["'`](?:[^"'`]*\s)?(?:lyric-line|print-line|preview-line)\b/`
- (b) it turns a component's `lines` into text/UI: `/\.lines\b[\s\S]{0,160}?\.(?:join|joinToString|joined|map|forEach)\(/` (covers preview-tab.js `lines.join('\n')`, present-mode's `lines.join`, Kotlin `component.lines.joinToString`, Swift `lines.joined`/`ForEach`)

Checks:
1. **Completeness** — every line renderer references `voices` (`/\bvoices\b/`) — EXCEPT files in the exporter set (check 3). Reports each offender with path.
2. **Floor** — these must be in the derived set (an under-matching regex goes RED here): `includes/pages/song.php`, `js/modules/setlist.js`, `js/modules/print.js`, `js/modules/present-mode.js`, `manage/editor/v2/preview-tab.js`, `appAndroid/…/ui/screens/SongDetailScreen.kt`, `appApple/…/IHFeatures/SongComponentView.swift`, `appApple/…/IHFeatures/ProjectionCanvasView.swift`. The .kt and .swift entries are what prove the walker leaves appWeb.
3. **Exporter abstinence** — `manage/editor/propresenter-export.js` and `includes/easyworship_export.php`: ZERO `/\bvoices\b/`; `manage/editor/format-export.js`: MUST contain `voices` AND `.kind` AND ZERO `displayLabel` (and, as already guarded elsewhere, zero `.label`).
4. **textContent purity** — in song.php (stripped), for every `<p class="lyric-line` opening tag, the text up to the next `</p>` contains NO `lyric-voice-chip` and NO `lyric-round-note`; `lyric-voice-span` IS permitted. Same check over `js/modules/voice-parts-render.js`'s template strings.
5. **a11y shape** — song.php and voice-parts-render.js each contain `lyric-voice-run` within 200 chars of `role="group"` and `aria-label`, and `lyric-voice-chips` within 120 chars of `aria-hidden="true"`; `lyric-voice-span` within 120 chars of `aria-roledescription`.
6. **Key non-collision** — `js/app.js` (stripped) contains no `case 'l':|case 'L':|case 't':|case 'T':|case 'Enter':|case 'Home':`.
7. **Share stays clean** — `js/modules/share.js` contains no `lyric-voice`.
8. **Sanity** — ≥ 50 .js files, ≥ 5 .kt files, ≥ 20 .swift files walked (a walker that under-reads fails loudly).

Mutation proof (perform, record in the header, restore, re-run green each time): m1 delete `voices` from print.js's renderLyrics → check 1 RED; m2 replace both fingerprints with `/(?!)/` → check 2 RED; m3 add `comp.voices` to propresenter-export.js → check 3 RED; m4 move the chip `<span>` inside the `<p>` in song.php → check 4 RED; m5 remove `role="group"` from the run tag → check 5 RED; m6 add `case 'L':` to app.js → check 6 RED; m7 remove the `.kt` extension from the walker → check 2 RED (floor). The header must list each with the observed assertion name, exactly as test-component-label-sites.js does.

### 5.2 Lockstep fixture — `tests/fixtures/voice-render-cases.json` + `tests/php/test-voice-parts-render.php` + `tests/test-voice-parts-render.js` (all NEW)

Fixture: a list of cases `{name, parts, text, spans, expect:{ariaLabel, chipsHtml, lineHtml, runOpenTag}}` covering: single part; two parts; three parts (serial "A, B and C"); lead + bg ("Women, echoed by Backing"); all-bg ("Echo, sung by Backing" + `--bg` classes); a custom label on a bg part ("Response (echo)"); a span in the middle of a line with a multi-byte character BEFORE it (e.g. `"Señor, ten piedad"` span 7-10 → proves code-point slicing, rule #21); two non-overlapping spans; an overlapping span (dropped); a span at the start; a span at the end; HTML-special text (`<b> & "q"`) proving escaping; empty parts (`''` chips, '' aria). Both suites load the file and assert each expectation with strict equality; the PHP suite additionally runs `ihymnsVoiceRunsByLineIndex()` on a hand-built component (runs from Pass 3's shape → `start/end/allBg` truth table) and `ihymnsVoiceRoundNoteHtml()`/`ihymnsVoiceRoundsDataAttr()` (only the §0 keys survive; a stray key is dropped). The JS suite additionally runs `collectSlideModel()` against a jsdom-free hand-built DOM? — no jsdom in this repo: instead `collectSlideModel()` is written against a minimal element interface (`querySelectorAll`, `dataset`, `classList`, `textContent`, `closest`) and the test drives it with a hand-rolled stub tree (the test-service-follow-a11y.js precedent). Mutation: change the serial-join wording in ONE side → that side's suite RED (the other stays green) — that asymmetry is the proof both sides read the same fixture.

### 5.3 `tests/php/test-service-state-round.php` (NEW)
`serviceMode_cleanState()` truth table for `round`: valid → 5 keys; missing id → key absent; `step` clamped 0-9999; `startedAt` 0/negative/non-numeric → null; `layout` outside the two values → 'split'; `round` present alongside `blank` → both survive; no-round input → output byte-identical to today's (pin a pre-change expected string).

### 5.4 Existing guards that must stay green / grow
- `tests/test-component-label-sites.js` — untouched (voice-parts-render.js does not derive "Type Number").
- `tests/php/test-print-block-registry.php` — grows the `showVoices` option on both sides.
- `tests/php/test-fragment-inline-scripts.php` — stays green by construction (no `<script>` added; data rides attributes).
- `tests/php/test-lyric-lines-read.php` fidelity fixtures — Pass 3's concern; this pass adds NO read-shape keys.

---

## 6 — Native follow-ups filed as issues (not in this PR's web scope but the guard forces the model + chip work NOW)
- Android: `VoiceRun`/`VoicePart` models + chip row in `SongDetailScreen` (this PR, minimal) ; round projector on Android TV — new issue under the voice-parts epic.
- Apple: `voices` decode + chip `HStack` + line-mode chip in `ProjectionCanvasView` (this PR, minimal); tvOS split-panel round view — new issue, referencing §4.1 as the spec.

## 7 — Docs (same change)
`WHATS-NEW.md` bullet under the current heading (plain English, no internals): "Songs can now show who sings each line — men, women, choir, echo — and rounds can be projected with each voice on its own panel, stepped or played back in time." `wiki/` API page + `api-docs.yaml` for `state.round`/`serverNowMs`. `help/` topic for Present: the new keys. `.claude/CLAUDE.md` rule text is Pass 5's (close-out) job; this pass's summary line for it: "Voice chips are siblings of `.lyric-line` inside a `role=group` run wrapper — never inside the `<p>`; one PHP + one JS renderer pinned by `tests/fixtures/voice-render-cases.json`; round timelines are server-expanded, never scheduled client-side; drive state rides StateJson `round`."

## Files
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/appWeb/public_html/includes/voice_parts_render.php` (NEW) — The ONE PHP renderer: ihymnsVoiceRunsByLineIndex, ihymnsVoiceSpansByLineIndex, ihymnsVoiceRunAriaLabel, ihymnsVoiceChipsHtml, ihymnsVoiceLineHtml (mb_substr code-point slicing), ihymnsVoiceRunOpenTag, ihymnsVoiceRoundNoteHtml, ihymnsVoiceRoundIndex, ihymnsVoiceRoundsDataAttr. Pure, no DB, all output escaped.
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/appWeb/public_html/includes/pages/song.php` — Require the renderer + vocal_parts.php; fetch rounds via vocalPartsForSong() in try/catch; add data-voice-rounds on .page-song (line 524); rewrite the per-line loop (1324-1360) to open/close the run wrapper, emit chips, round note, lyric-line--bg, data-round-id and ihymnsVoiceLineHtml() spans. Byte-identical output when a component has no voices key.
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/appWeb/public_html/js/modules/voice-parts-render.js` (NEW) — The ONE JS renderer (twin of the PHP one, fixture-pinned): voiceRunsByLineIndex, voiceSpansByLineIndex, voiceRunAriaLabel, voiceChipsHtml, voiceLineHtml (Array.from code points), voiceRunOpenTag, renderComponentLinesHtml(comp, opts), parseRoundsAttr, collectSlideModel.
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/appWeb/public_html/js/modules/present-mode.js` — Replace the textContent scrape (lines 42-49) with collectSlideModel(); DOM-build slides (no innerHTML with page text); add the round slide (split/stacked grid, per-voice panels with prev/now/next, waiting/done states, step rail, announce on step), playback clock over server-expanded timeline.steps[].atMs, Play/Layout/Reset buttons, keys Enter/Home/L/T scoped to round slides, degradation ladder §4.6.
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/appWeb/public_html/js/modules/voice-round-follow.js` (NEW) — applyRoundFollow(pageEl, state, serverSkewMs): teardown-first, marks each voice's current line with data-voice-now=N (+ data-voice-mine for the per-device chosen voice from sessionStorage ihymns_follow_voice), local rAF clock when state.round.playing, announce() once per step.
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/appWeb/public_html/js/modules/service-follow.js` — Call applyRoundFollow() from _applyFollowState()/the poll handler with the decoded state + skew (serverNowMs − Date.now()); add the 'Your voice' select to #service-follow-banner while state.round is present.
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/appWeb/public_html/js/modules/live-follow.js` — Same two additions as service-follow.js (shared module, no fork).
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/appWeb/public_html/js/modules/service-broadcast.js` — When song_detail carries vocalParts.rounds, add a round stepper (◀ k/n ▶ ⏵) to the owning section chip; POST state.round {id, step, playing, startedAt(server-anchored), layout} through the existing broadcast() path; extend the de-dupe key.
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/appWeb/public_html/includes/service_mode.php` — serviceMode_cleanState(): allow-list the `round` key (id>0 required; step clamped 0-9999; playing bool; startedAt positive int or null; layout split|stacked). No schema change.
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/appWeb/public_html/api.php` — service_broadcast, live_follow_broadcast, service_poll, live_follow_poll responses add serverNowMs; both polls return the decoded `state` object.
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/appWeb/public_html/api-docs.yaml` — Document state.round on the two broadcast requests; serverNowMs + state on the four responses (same change, rule #48).
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/appWeb/public_html/js/modules/setlist.js` — Extract the duplicated .lyric-component template (lines 2164-2174 and 2839-2848) into one renderArrangedComponent(comp) that calls renderComponentLinesHtml(); playback re-render also emits data-line-id and data-round-id so follow/markup survive a custom arrangement.
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/appWeb/public_html/js/modules/print.js` — renderLyrics(): lines via renderComponentLinesHtml(comp, {print classes, a11y:false, dataAttrs:false, showVoices: block.showVoices !== false}); lyrics block gains showVoices (default true) in the client schema mirror.
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/appWeb/public_html/includes/print_template_schema.php` — Add `showVoices` (bool, default true) to the lyrics block options (server side of the registry guard).
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/appWeb/public_html/manage/editor/v2/preview-tab.js` — Render lines through renderComponentLinesHtml(c, {lineClass:'preview-line', assignmentsByLineId}) into body.innerHTML instead of textContent = lines.join('\n').
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/appWeb/public_html/manage/editor/format-export.js` — #2071 exporter half: emit <lines part="{kind}"> per single-part voice run (kind only, never label/displayLabel); slide-chunk <lines> blocks stay attribute-less.
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/appWeb/public_html/css/app.css` — New voice-parts section (§2): tokens on :root / dark / high-contrast, run/chip/bg/span/round-note rules, follow badges, presentation-overlay chip rule, the .present-round grid + per-voice border-style panels, reduced-motion.
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/appWeb/public_html/css/print.css` — .print-voice-chip, .print-line--bg, .print-voice-span.
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/appWeb/public_html/css/accessibility.css` — [data-ihymns-cvd] thickening of chip borders and voice-panel rules (border STYLE already carries the cue).
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/appAndroid/app/src/main/java/ltd/mwbmpartners/ihymns/models/Song.kt` — SongComponent gains voices: List<VoiceRun> = emptyList() (+ VoiceRun, VoicePart data classes with defaults).
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/appAndroid/app/src/main/java/ltd/mwbmpartners/ihymns/ui/screens/SongDetailScreen.kt` — SongComponentSection renders a chip Row before each run's first line, italic+padded bg lines, semantics contentDescription = the serial-join aria label.
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/appApple/Packages/iHymnsKit/Sources/IHModels/SongComponent.swift` — public let voices: [VoiceRun]? (optional decode) + VoiceRun/VoicePart Codable structs.
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/appApple/Packages/iHymnsKit/Sources/IHFeatures/SongComponentView.swift` — Chip HStack before each run's first line; accessibilityElement(children:.contain) + accessibilityLabel.
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/appApple/Packages/iHymnsKit/Sources/IHFeatures/ProjectionCanvasView.swift` — Line mode shows the current line's voice chip (reads voices via ProjectionResolver's component).
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/tests/test-voice-part-sites.js` (NEW) — Tree-derived, mutation-proven render-site guard across appWeb (.js/.php), appAndroid (.kt), appApple (.swift): completeness, floor (incl. .kt/.swift), exporter abstinence, textContent purity, a11y shape, key non-collision, share cleanliness, walker sanity.
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/tests/fixtures/voice-render-cases.json` (NEW) — Shared PHP↔JS(↔Kotlin) lockstep fixture for aria-label wording, chip HTML, code-point span slicing, escaping.
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/tests/php/test-voice-parts-render.php` (NEW) — PHP renderer truth table over the fixture + runs/round-index/data-attr key-whitelist assertions.
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/tests/test-voice-parts-render.js` (NEW) — JS renderer truth table over the same fixture + collectSlideModel() over a stub DOM tree.
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/tests/php/test-service-state-round.php` (NEW) — serviceMode_cleanState() `round` truth table incl. the no-round byte-identical pin.
- `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/WHATS-NEW.md` — One plain-language bullet (no internals) under the current heading.

## Risks
- SILENT textContent pollution: if any future site nests a chip inside <p class="lyric-line">, Present/share/markup all degrade with no error. Guard check 4 is the only thing standing between that and production — keep it, and extend it to any new line-template file the walker discovers.
- PDF sanitiser: if the `print` profile of includes/html_sanitizer.php strips <span class> inside <div class="print-line">, chips vanish from server PDFs while the browser print still shows them — a half-ship with no error. Verify with an explicit assertion on ihymnsSanitizeHtml('<div class="print-line"><span class="print-voice-chip">Women</span>x</div>', 'print') before merging.
- Overlapping or out-of-range voiceSpans (curator error or a stale span after a line edit shortens the text) would produce malformed HTML; the renderer DROPS an overlapping span and CLAMPS end to the code-point length, logging both — but a clamped span is visually wrong and nobody is told. Pass 2's span writer must revalidate spans on every line-text change (lyricLinesApplyDesired) or delete them; flag as a Pass 2 obligation.
- Round timeline drift: if Pass 1's lyricRoundTimeline() emits any shape other than §0 (different key names, unexpanded steps), the projector silently shows the plain slide only (the try/catch swallows it). The JS fixture pins the client's expectation; add a PHP assertion in Pass 1's suite that its output satisfies this exact shape, so both sides fail together.
- Key collisions with app.js's document-level handler (P/B/PageDown already exist there) — Enter/Home/L/T were chosen because app.js has no case for them TODAY; guard check 6 turns a future collision red instead of a silent double-action.
- Clock skew on followers: serverNowMs is sampled once per poll response; a congregant device with a slow network sees the round a few hundred ms late. Acceptable for lyrics (not karaoke), but do not promise sub-beat sync in user docs.
- Shared-cache fragment: data-voice-rounds and the chips are song-level, so safe; the per-device 'my voice' MUST stay in sessionStorage — if anyone moves it server-side onto page=song the #448 per-user-ETag regression returns.
- Fidelity snapshot / strict fixtures: this pass adds NO read-shape keys, but its song.php markup change alters the fragment HTML for songs WITH voice rows. Any golden HTML snapshot test (none found in the tree today) would need updating; if one appears in Pass 3/5, note this.
- Native apps: the guard forces `voices` into the .kt/.swift line renderers; if implementers satisfy it with a token reference rather than a real render, the guard is green and the feature is not shipped. The floor + completeness checks are structural, not semantic — the review must look at the native diffs.
- setlist.js playback re-render currently drops data-line-id; wiring it to emit lineIds is new behaviour that song-markup.js and the follow modules will start seeing on custom-arranged pages — a REPEATED component (arrangement [0,1,0]) yields duplicate data-line-id values, so querySelector picks the first; follow highlighting on a repeated verse lands on its first occurrence only. Document, do not 'fix' with synthetic ids.
---

# Design pass 6

## Summary
Pass 3 (importers / TTML ingest / exports / backfill review queue) for the voice-parts program. Designs, against the real tree: (1) the #2071 OpenLyrics `<lines part=/repeat=>` import + export, discriminating on attribute PRESENCE so the exporter's attribute-less slide chunks keep working; (2) the #2075 fix for all four fake-'refrain' importers via ONE header-marker classifier + ONE "voice block continues the previous section" merge helper, with unknown non-voice words preserved as the component Label (the ProPresenter 7 pattern); (3) the #2072 Note fix plus the general per-line preserve-on-omit machinery inside the ONE write path (`_preserve` flags → `lyricLinesMergePreserved()`), with Note read in both shapes; (4) TTML ingest wiring for head `<ttm:agent>` defs → tblVocalParts, line IDREFS → tblLyricLineVocalParts, `x-bg` → IsBackground, and the WORD grain → tblLyricWordVocalParts — including a parser correction (Apple's `x-bg` container spans are currently collapsed into one fake word) and a line-level backfill from the MetaJson blobs; (5) a per-format export table (OpenLyrics part=, a new server-side TTML exporter, ProPresenter cue names, OpenSong `;` rows, ChordPro `{comment:}`, VideoPsalm voice-run Tags, Proclaim/plain-text header blocks, EasyWorship/FreeShow dropped unless an explicit opt-in); (6) the D4 review queue: a PURE shared detector (`vocal_part_detect.php`, handles the NBSP form) that #1260 can consume, a `tblVocalPartSuggestions` table shipped in Pass 1's single migration, a dry-run-default idempotent reversible backfill card with a real probe and rule-#41 include paths, a `/manage/vocal-parts-review` page + API twins, and revision-recorded accepts that a Revisions-tab restore genuinely undoes.

## Spec
All paths below are under `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/iHymns/appWeb/public_html/` unless they start with `appWeb/.sql/`, `tests/` or `tools/`. Pass 1 and Pass 2 own `includes/vocal_parts.php`, `includes/lyric_rounds.php`, `includes/vocal_part_detect.php` (skeleton) and the single migration `appWeb/.sql/migrate-vocal-parts-rounds.php`; this pass ADDS to those files where stated and owns everything else. Every function signature below is final.

---

## 1 — Shared vocabulary hooks this pass relies on (in Pass 2's `includes/vocal_parts.php`)

Used verbatim from Pass 2: `IHYMNS_VOCAL_PART_KINDS` (per key: `label`, `description`, `gender`, `markers` (UPPER → null|override label), `openlyrics`, `ttmlAgent`), `IHYMNS_VOCAL_PART_KIND_ALIASES`, `IHYMNS_VOCAL_GROUP_ORDINAL_RE`, `vocalPartsTablesReady()`, `vocalPartsNormalizeKind()`, `vocalPartsFindOrCreate(\mysqli $db, int $lyricsId, string $kind, ?string $label, string $source='ihymns', ?string $ttmlAgentId=null, ?array $meta=null): int`, `vocalPartsAssignWords(\mysqli $db, int $lyricsId, array $wordIds, int $partId, bool $isBackground): int`, `vocalPartsLinesMap()`, `vocalPartsForSong()`, `vocalPartsDisplayLabel()`.

**Change to Pass 2's file (one line):** `IHYMNS_VOCAL_SOURCES_STRUCTURED = ['applemusic-ttml', 'openlyrics', 'propresenter7', 'import-marker'];` — `'import-marker'` is the Source stamped on parts created from a header-position marker (§5); it is applied directly, never queued.

**New functions this pass adds to `includes/vocal_parts.php`:**

```php
/** Ingest-only line assignment for a NON-'ihymns' version (TTML). No ownership
 *  resolution to the 'ihymns' version — the caller just inserted these lines.
 *  INSERT IGNORE on uq_Line_Part; SortOrder = index in $partIds. Returns rows written. */
function vocalPartsAssignLinesForVersion(\mysqli $db, int $lyricsId, int $lineId, array $partIds, bool $isBackground): int;

/** Registry rows of one version keyed by TtmlAgentId (only rows with a non-null id).
 *  @return array<string,int>  agentId => tblVocalParts.Id */
function vocalPartsAgentIndex(\mysqli $db, int $lyricsId): array;

/** Delete ingest-owned parts of a version whose agent id is no longer in the document.
 *  Only rows with Source = $source AND TtmlAgentId IS NOT NULL AND TtmlAgentId NOT IN ($keepAgentIds)
 *  (curator rows — Source 'ihymns' or NULL agent — are never touched). Returns rows deleted. */
function vocalPartsPruneAgents(\mysqli $db, int $lyricsId, string $source, array $keepAgentIds): int;

/** Kind for a TTML agent definition. PURE.
 *  $agent = ['id'=>string,'type'=>?string,'name'=>?string]; $personOrdinal = 1-based order of this
 *  agent among type=person agents in the document (1 → 'lead', >1 → 'soloist').
 *  Rules: name present (any type) → 'named-singer'; type person → lead/soloist by ordinal;
 *  type group → 'group'; type other → 'duet'; character|organization|null → 'group'. */
function vocalPartsKindFromTtmlAgent(array $agent, int $personOrdinal): string;

/** The transport→FK conversion the writer calls (§2.2). $lineIdsByPos[compPos][lineIdx] = tblLyricLines.Id.
 *  For every component whose payload carried the `voices` key: per cell —
 *    absent index / no key      → untouched;
 *    null or []                 → DELETE that line's tblLyricLineVocalParts rows (CLEAR);
 *    list<{kind,label?,bg?,name?}> → DELETE then INSERT (REPLACE), parts via vocalPartsFindOrCreate()
 *                                  on the line's LyricsId with $source, SortOrder = list index.
 *  `label` ?? `name` is the Label (≤120, trimmed, null when equal to the kind's default label).
 *  Never throws for a bad kind: an unknown kind is skipped and counted in the return.
 *  @return array{written:int, cleared:int, skippedKinds:int} */
function vocalPartsApplyComponentVoices(\mysqli $db, string $songId, array $norm, array $lineIdsByPos, string $source): array;
```

---

## 2 — The ONE write path: changes to `includes/lyric_lines_sync.php`

### 2.1 `lyricLinesApplyDesired()` gains an Id-out parameter (no caller changes required)

```php
function lyricLinesApplyDesired(\mysqli $db, string $songId, array $desired, ?array &$lineIdsOut = null): int
```
Inside the per-desired loop, immediately after `$ins->execute()` capture `$newId = (int)$db->insert_id;` and record `$lineIdsOut[$di] = $matchId ?? $newId;`. Also, for a MATCHED line, before the dirty check:
```php
if ($matchId !== null) { $d = lyricLinesMergePreserved($d, $existingById[$matchId] ?? null); }
```

### 2.2 `lyricLinesWriteComponents()` — normalisation + conversion

Add to each `$norm[]` entry (next to `labelProvided`):
```php
'notes'             => (isset($c['notes']) && is_array($c['notes'])) ? array_values($c['notes']) : null,   // already present — keep
'notesProvided'     => array_key_exists('notes',  $c),
'chordsProvided'    => array_key_exists('chords', $c),
'voices'            => (isset($c['voices']) && is_array($c['voices'])) ? array_values($c['voices']) : null,
'voicesProvided'    => array_key_exists('voices', $c),
```
After the diff:
```php
$lineIds = [];
$count   = lyricLinesApplyDesired($db, $songId, $desired, $lineIds);
/* voices transport → FK rows (rule #25: this IS the one write path; no second funnel) */
if (function_exists('vocalPartsTablesReady') || (require_once __DIR__ . DIRECTORY_SEPARATOR . 'vocal_parts.php')) {
    if (vocalPartsTablesReady($db)) {
        $anyVoices = false; foreach ($norm as $c) { if ($c['voicesProvided']) { $anyVoices = true; break; } }
        if ($anyVoices) {
            $byPos = []; $di = 0;
            foreach ($norm as $pos => $c) { foreach ($c['lines'] as $li => $_) { $byPos[$pos][$li] = (int)($lineIds[$di] ?? 0); $di++; } }
            vocalPartsApplyComponentVoices($db, $songId, $norm, $byPos, $components['_voiceSource'] ?? 'ihymns');
        }
    }
}
return $count;
```
`$components['_voiceSource']` is an OPTIONAL string key on the top-level components array (not a component) — importers set `'import-marker'` / `'openlyrics'`; the writer strips it before iterating (it already skips non-array entries). Default `'ihymns'`.

### 2.3 `lyricLinesBuildDesiredFromComponents()` — `_preserve` flags

Each desired entry gains:
```php
'_preserve' => ['Note' => !$c['notesProvided'], 'ChordsJson' => !$c['chordsProvided']],
```
(The legacy `lyricLinesBuildDesired()` projector emits NO `_preserve` key → byte-identical behaviour.)

### 2.4 New PURE helper (unit-tested)

```php
/** ELI5: if the caller said nothing about a line's note or chords, keep what is already stored.
 *  For a MATCHED line only. $existingRow null or no `_preserve` → $desired unchanged. */
function lyricLinesMergePreserved(array $desired, ?array $existingRow): array
{
    if ($existingRow === null || empty($desired['_preserve'])) { return $desired; }
    if (!empty($desired['_preserve']['Note']))       { $desired['Note']       = $existingRow['Note']       ?? null; }
    if (!empty($desired['_preserve']['ChordsJson'])) { $desired['ChordsJson'] = $existingRow['ChordsJson'] ?? null; }
    return $desired;
}
```
`lyricLinesRowClean()` is unchanged — it now sees the substituted values, so a note-only-preserved row reports clean and is not UPDATEd.

### 2.5 `lyricLinesSnapshotDeletedEnrichment()` — snapshot voice rows too

Add a local memoised probe `lyricLinesVocalTablesPresent(\mysqli $db): bool` (INFORMATION_SCHEMA count ≥ 2 of `tblLyricLineVocalParts`, `tblVocalParts`; same catch posture as `lyricLinesEnrichmentTablesPresent()`). When true, add to the snapshot JSON a `vocalParts` key: rows `SELECT lvp.LineId, lvp.VocalPartId, lvp.IsBackground, lvp.SortOrder, vp.PartKind, vp.Label FROM tblLyricLineVocalParts lvp JOIN tblVocalParts vp ON vp.Id = lvp.VocalPartId WHERE lvp.LineId IN (…)`. The early `return` when nothing was found now also requires `empty($vocal)`.

---

## 3 — #2071 OpenLyrics `<lines part=… repeat=…>`

### 3.1 Importer — `includes/song_importers.php`

`_bulkImport_openLyricsParseLines(\SimpleXMLElement $linesNode): array` keeps its per-line return shape. The CALLER (the `<verse>` loop, currently ~line 3290-3315) changes to:

```php
$lines = []; $chords = []; $notes = []; $voices = []; $anyVoice = false;
foreach (($verse->lines ?? []) as $linesNode) {
    $partRaw  = isset($linesNode['part'])   ? trim((string)$linesNode['part'])   : '';
    $repeatRaw= isset($linesNode['repeat']) ? trim((string)$linesNode['repeat']) : '';
    $voice    = $partRaw !== '' ? vocalPartsResolveOpenLyricsPart($partRaw) : null;     // §3.2
    $repeatN  = ($repeatRaw !== '' && ctype_digit($repeatRaw) && (int)$repeatRaw >= 2 && (int)$repeatRaw <= 99) ? (int)$repeatRaw : 0;
    $first    = true;
    foreach (_bulkImport_openLyricsParseLines($linesNode) as $ln) {
        $note = $ln['note'];
        if ($first && $repeatN > 0) { $note = ($note !== '' ? $note . ' · ' : '') . 'Repeat ×' . $repeatN; }
        $lines[] = $ln['text']; $chords[] = $ln['chords']; $notes[] = $note;
        $voices[] = $voice !== null ? [$voice] : null;
        if ($voice !== null) { $anyVoice = true; }
        $first = false;
    }
}
… existing `$comp` build …
if ($anyVoice) { $comp['voices'] = $voices; }
```
and in `_bulkImport_assembleSong()` / wherever the OpenLyrics dict reaches `_bulkImport_saveSong()`, the components array is stamped `$song['components']['_voiceSource'] = 'openlyrics'` ONLY when any component carries `voices` (so a voice-less file's dict is byte-identical to today).

The `<lines>` tag strip regex at lines 3087 and 3120 stays exactly as is — it strips the OPENING tag after the attributes have been read off the SimpleXML node, so nothing is lost any more. `break="optional"` is still ignored (OpenLP slide hint; we chunk ourselves).

### 3.2 Part-text fold (PURE, in `includes/vocal_parts.php`)

```php
/** OpenLyrics `part` is free text by spec — fold it, never drop it.
 *  1. lower/trim; exact match on IHYMNS_VOCAL_PART_KINDS[*]['openlyrics']  → [kind, null]
 *  2. vocalPartsNormalizeKind($p)  (keys, aliases, lower-cased markers)     → [kind, null]
 *  3. IHYMNS_VOCAL_GROUP_ORDINAL_RE on strtoupper($p) / ^group(\d+)$        → ['group', 'Group N']
 *  4. fallback                                                              → ['group', mb_substr(ucfirst($p),0,120)]
 *  @return array{kind:string,label:?string} */
function vocalPartsResolveOpenLyricsPart(string $part): array;
```

### 3.3 Exporter — `manage/editor/format-export.js` `buildOpenLyrics()`

Replace the block builder (lines 392-397) with:
```js
var blocks = voiceRuns(comp).map(function (run) {           // §8.1 helper, in this file
    return chunkLines(run.lines, maxLines).map(function (chunk) {
        var body = chunk.map(function (line) { return escapeXml(String(line == null ? '' : line)); }).join('<br/>');
        var attr = run.part ? (' part="' + escapeXml(run.part) + '"') : '';
        return '      <lines' + attr + '>' + body + '</lines>\n';
    }).join('');
}).join('');
```
`voiceRuns(comp)` → `[{part: string|null, lines: string[]}]`: walks `comp.lines`; the run key of line i is `olPartToken(comp.voices && comp.voices[i])`; consecutive lines with the same key form a run. `olPartToken(cell)`: `null` when cell is empty/absent; else takes the FIRST entry (SortOrder 0 — OpenLyrics cannot express two parts on one line; the rest are dropped), returns `OL_PART[kind]` when `entry.name` is empty or equals the kind's default display, else `entry.name`; `group` → `'group' + ordinal` where ordinal = 1-based index of that `{kind:'group', name}` among the song's distinct group parts (from `song.vocalParts` order). `OL_PART` is the `openlyrics` column of the vocabulary, mirrored into this file (PHP↔JS lockstep guard §10.3). A song with no `voices` anywhere produces byte-identical XML to today (`voiceRuns` returns one run with `part:null`).

### 3.4 Deliberately dropped on export: `repeat=` (no source model), the `bg` flag, any second part on a duet line.

---

## 4 — #2072 `tblLyricLines.Note` + display sites

1. `includes/lyric_lines_read.php`: `lyricLinesFetchPrimary()` and `lyricLinesFetchPrimaryMap()` add `ll.Note AS line_note` to the SELECT (no new gate — `lyricLinesMirrorPresent()` already requires the column).
2. `lyricLinesAssembleFromRows()`: collect `'_notes'[] = ($row['line_note'] !== null && $row['line_note'] !== '') ? (string)$row['line_note'] : null`; in `$flush`, AFTER the `lineLanguages` emit, add SPARSELY: `foreach ($c['_notes'] as $n) { if ($n !== null) { $out['notes'] = $c['_notes']; break; } }`. Key order for note-less songs is unchanged → strict-`===` fixtures untouched.
3. `lyricLinesEditableComponents()`: `$byCid[$cid]['notes'][] = …` and emit `'notes' => $anyNote ? $notes : null` (ALWAYS present, after `chords`), plus `'voices' => …` per Pass 1's editor-shape addition (always present, `null` when the version has no assignments; else parallel list of `null|list<{kind,label,bg}>` built from `vocalPartsLinesMap()` when `vocalPartsTablesReady()`).
4. `manage/editor/save_song_core.php`: PF1 carry — add `$carryNotes[$key][] = $pc['notes'];` beside `$carryChords` (line ~832); in the write loop mirror the `chords` block: `if (array_key_exists('notes', $comp)) { $cNotes = is_array($comp['notes']) ? array_values($comp['notes']) : null; } else { $cNotes = <carried or null>; }` and pass `'notes' => $cNotes`.
5. `manage/editor/api2.php` `component_upsert` (line ~3395) and `components_replace`: add `'notes' => (isset($comp['notes']) && is_array($comp['notes'])) ? array_values($comp['notes']) : null,` **only when `array_key_exists('notes', $comp)`** — copy the exact omit-preserve idiom the block uses for `chords`; `ed2_currentComponents()` now carries `notes` from the editable shape, so the RMW carry preserves it.
6. `includes/pages/song.php`: on `<p class="lyric-line">` emit `data-note="<escaped>"` when `$component['notes'][$i]` is non-null. Attribute only — `textContent` scraping (present-mode.js:46, share.js:169) is unaffected.
7. `js/modules/present-mode.js`: per slide, `notes = lines.map(l => l.dataset.note || '')`; render non-empty notes in the existing operator-only footer strip (the same element the slide counter lives in), never on the projected surface.
8. Editor2 `manage/editor/v2/structure-tab.js`: per-line "Note" input rendered by the chord-row editor pattern (same row, second input, `data-line-note`), stored in `comp.notes[i]` in the store, carried by `component_upsert`. Remap on line insert/delete/reorder via the SAME `remapChordsOnLinesChange()` generalised to `remapParallelOnLinesChange(comp, key)` called for `chords`, `notes` and `voices`.
9. `includes/access_resolver.php`: add `notes` to the lyric-body field set that is stripped when lyrics are gated (it is lyric-adjacent text).

---

## 5 — #2075 The four (five) importers

### 5.1 ONE classifier (PURE, `includes/vocal_part_detect.php`)

```php
/** Header-position voice word? Input = the raw section token. Normalise: NFC, every U+00A0/U+2007/U+202F → ' ',
 *  collapse whitespace, strip one trailing ':' and surrounding '[ ]' or '( )', UPPER-CASE.
 *  Match: (a) a key of the marker table (§9.1) — multi-word keys allowed; (b) IHYMNS_VOCAL_GROUP_ORDINAL_RE.
 *  Returns null for anything else (including every section word — the vocabulary is disjoint by guard §10.2).
 *  @return ?array{kind:string,label:?string,marker:string} */
function vocalDetectHeaderMarker(string $token): ?array;

/** Merge a voice block into the LAST component of $components (or open an unnumbered verse when empty),
 *  keeping every parallel array aligned. PURE.
 *  $block = ['lines'=>list<string>, 'chords'=>?list, 'notes'=>?list]; $voice = vocalDetectHeaderMarker() result.
 *  If the last component has `chords`/`notes`, pad the block with nulls (and vice versa); `voices` is created
 *  padded with null for the pre-existing lines. Returns the new components list. */
function vocalDetectMergeVoiceBlock(array $components, ?array $reopenHeader, array $voice, array $block): array;
```
`$reopenHeader` = a header-only section that was flushed with no lines (e.g. "Chorus" followed by a blank then "ALL") — when non-null and `$components` last element is not it, it is appended first so the voice block lands in the section the author opened.

### 5.2 `.txt` (`_bulkImport_parseTxt`, line ~340)
Replace the `else` branch:
```php
} elseif (($vm = vocalDetectHeaderMarker($trim)) !== null) {
    $current = null;
    $pending = ['voice' => $vm, 'lines' => []];              // collect until blank/next marker
} else {
    $sec = _bulkImport_sectionFromMarker($trim);            // §5.6
    $current = ['type' => $sec['type'], 'number' => $sec['number'], 'lines' => []];
    if ($sec['label'] !== null) { $current['label'] = $sec['label']; }
}
```
Lines while `$pending` is set append to `$pending['lines']`; on blank or EOF, `$components = vocalDetectMergeVoiceBlock($components, $lastHeaderOnly, $pending['voice'], ['lines' => $pending['lines']]); $pending = null;`. `$lastHeaderOnly` is set whenever a section flushes with zero lines (today it is silently discarded) and cleared when any component is pushed. Whole-song stamp: `$components['_voiceSource'] = 'import-marker'` when any merge happened.

### 5.3 OpenSong (`_bulkImport_parseOpenSongLyrics`, line ~2230)
Marker regex unchanged. After the match: `if (strlen($m[1]) > 1 && ($vm = vocalDetectHeaderMarker($m[1] . $m[2])) !== null) { …pending voice block as §5.2… continue; }`. Otherwise: `$sec = _bulkImport_sectionFromOpenSongLetter($m[1], $m[2])` → known single letter → today's map; anything else → `['type'=>'refrain','number'=>(int)$m[2] ?: 0,'label'=>$m[1].$m[2]]`. NEW: a `;` comment row whose text passes `vocalDetectHeaderMarker()` sets `$pendingVoice` for the following lyric rows of the CURRENT section (until the next marker/`;`-voice row/blank) — this is the export round-trip of §8.4; every other `;` row is still dropped.

### 5.4 VideoPsalm (`_bulkImport_parseVideoPsalmSongbook`, line ~2540)
Before `_bulkImport_videopsalmComponentTypeFor($tag)`: `$word = strtoupper(trim(preg_replace('/\d+$/', '', $tag)));` `if (strlen($word) > 1 && ($vm = vocalDetectHeaderMarker($word)) !== null) { $components = vocalDetectMergeVoiceBlock($components, null, $vm, ['lines' => $lines]); continue; }`. Unknown non-voice tag → `'refrain'` + `'label' => trim($tag)`.

### 5.5 OpenLyrics verse names (`_bulkImport_openLyricsVerseType`, line ~3076)
Returns `[type, num, label]` (third element new). When `$letter` is not a key of `$map`: if `vocalDetectHeaderMarker(strtoupper($letter))` → the caller merges the whole verse as a voice block (`vocalDetectMergeVoiceBlock`, all lines) instead of pushing a component; else `['refrain', $num, trim($name)]`. The `$nameToIndices` bookkeeping for `<verseOrder>` records the index the block MERGED INTO.

### 5.6 The shared section fold (replaces `_bulkImport_componentTypeFor()`)
```php
/** @return array{type:string,number:int,label:?string}  label = raw marker ONLY when the word was unknown
 *  (rule #45: nothing is lost when we had to guess) and differs case-insensitively from 'Refrain'. */
function _bulkImport_sectionFromMarker(string $marker): array;
```
`_bulkImport_componentTypeFor()` is DELETED; every caller (`.txt`, ChordPro `{comment:}` / `{c:}` section path, Proclaim `#1062` header path, FreeShow group names if they route here — `grep -n "_bulkImport_componentTypeFor("` lists them) is re-pointed, and each caller first tries `vocalDetectHeaderMarker()` and merges a voice block exactly as §5.2. ProPresenter 7 (`_bulkImport_pro7GroupType`) is unchanged — it already preserves the word — EXCEPT that a group name passing `vocalDetectHeaderMarker()` now merges as a voice block too (a PP7 group named "Men" is the same signal).

### 5.7 Import-time in-body scan
At the end of `_bulkImport_saveSong()` (after the `lyricLinesWriteComponents()` call, inside the transaction, own try/catch that re-throws only `songRelocateIsTransactionFatal()`): `if (vocalSuggestTablesReady($db)) { vocalSuggestScanSong($db, $songId, 'import:' . ($song['_format'] ?? 'unknown')); }` — `$song['_format']` is set by each parser (`txt|opensong|videopsalm|openlyrics|chordpro|proclaim|freeshow|pro7|ihymns|csv`). In-body markers (not header position) are thus queued, never applied.

---

## 6 — Marker table + detector (PURE core, `includes/vocal_part_detect.php`)

### 6.1 Derived marker table
```php
const IHYMNS_VOCAL_DETECT_VERSION = '1';
/** UPPER marker → [kind, label]; derived ONCE from IHYMNS_VOCAL_PART_KINDS[*]['markers'] (label = override ?? kind label). */
function vocalDetectMarkerTable(): array;              // memoised
const IHYMNS_VOCAL_CANON_RE = '/^(?<a>MEN|WOMEN|ALL|BOTH|CHOIR|CONGREGATION)(?:\s+(?:AND|&)\s+(?<b>MEN|WOMEN|CHOIR|CONGREGATION))?\s+IN\s+CANON$/u';
const IHYMNS_VOCAL_DIRECTION_RE = '/^(repeat|x\s*\d|\d+\s*x|sing|to\s|go\s+to|d\.?\s*[sc]\.?|instrumental|interlude|solo|tag|coda|end|fine|last\s+time|first\s+time|chorus|verse|bridge|refrain|spoken|optional|softly|slowly|louder|quietly|twice|three\s+times|\d)/iu';
```

### 6.2 The detector
```php
/** @param list<array{lines:list<string>,type?:string}> $components  the public/editable shape (only `lines` is read)
 *  @return list<Finding> */
function vocalDetectComponents(array $components): array;
```
`Finding` = `['componentIndex'=>int, 'lineIndex'=>int, 'form'=>'standalone'|'prefix'|'paren'|'canon', 'marker'=>string, 'kind'=>string, 'label'=>?string, 'signal'=>string, 'confidence'=>float, 'action'=>'convert-standalone'|'convert-prefix'|'mark-echo'|'create-round', 'runEndIndex'=>?int, 'cleanText'=>?string, 'parts'=>list<{kind,label}>, 'similarity'=>?float]`.

Per line `$t` (NFC, `\x{00A0}|\x{2007}|\x{202F}` → space ONLY for matching — `cleanText` is built from the original):
1. **canon**: `IHYMNS_VOCAL_CANON_RE` on the whitespace-collapsed upper line → `action create-round`, `parts` from `a`/`b` (e.g. MEN+WOMEN), confidence 0.90, signal `canon-phrase`.
2. **standalone**: `vocalDetectHeaderMarker($t)` non-null. Confidence: 0.95 if the original is ALL-CAPS with ≥3 letters or ends with ':'; 0.85 for ALL-CAPS 'ALL'/'MEN' (2-3 letters); 0.60 title-case with ':'; 0.30 title-case bare. Signal `allcaps|colon|titlecase`. `runEndIndex` = index before the next standalone/canon finding in the same component, else last line; if the marker is the component's LAST line → `runEndIndex = null` and `action` still `convert-standalone` with `ProposalJson.nextComponent = true` (apply attaches to the next component's lines).
3. **prefix**: regex `/^(?<m>[\p{Lu}][\p{Lu} &]{1,30}?)(?<sep>:)?(?<gap>[ \t\x{00A0}\x{2007}\x{202F}]+)(?<rest>\S.*)$/u` where `vocalDetectHeaderMarker(m)` non-null AND (`sep` present OR `gap` contains an NBSP-class char OR `gap` length ≥ 2). A single plain space and no colon is NOT a finding ("ALL creation sings"). Confidence 0.90 (NBSP run / colon), 0.70 (2+ plain spaces). Signal `nbsp-run|colon|space-run`. `cleanText = ltrim(rest)` from the ORIGINAL string (code points, `mb_*`). Action `convert-prefix`.
4. **paren**: `^\((?<in>.+)\)$` where `in` is not itself a marker and does not match `IHYMNS_VOCAL_DIRECTION_RE`. Echo similarity = `lyricLinesSimilarity(in, previousLine)` (the shared scorer in lyric_lines_sync.php — never a re-fork) or substring test. Confidence 0.75 when ≥ 0.6 or substring; 0.35 otherwise. Kind `backing`, `bg=true`, action `mark-echo`, signal `paren-echo`. Text unchanged.
Findings are emitted in document order; the detector is deterministic (same input → same list) — asserted by the truth-table test.

---

## 7 — TTML ingest (D2)

### 7.1 Parser fix + head agents (`includes/lyrics_ingest.php`)

- **Head agents**: after the root check, `foreach ($doc->getElementsByTagNameNS('*','agent') as $ag)` under `head` → `agents[] = ['id'=>xml:id, 'type'=>type attr|null, 'name'=>first <ttm:name> textContent (prefer type="full")|null, 'meta'=>['names'=>[{type,text}], 'type'=>…]]`. Return gains `'agents' => list`.
- **Container spans**: the `<p>` walk becomes `_ttmlWalkInline(\DOMNode $parent, ?array $inherited, array &$words, ?array &$cur, callable $flush)`. A `<span>` with nested spans is a WORD-with-syllables ONLY if `_ttmlHasWhitespaceBetweenChildren($node) === false` AND it carries no `ttm:role`/`ttm:agent`; otherwise it is a CONTAINER: `$flush()`, then recurse with `$inherited = array_merge($inherited ?? [], _ttmlMeta($node) ?? [])`, then `$flush()`. Every produced word's `meta` = `array_merge($inherited, ownMeta)` (own wins). Mutation test: revert the container rule → the fixture yields `Ohyeah` → RED.
- **Per line/word resolution** (added to each `lines[]` / `words[]` entry): `'agentIds' => list<string>` (split `ttm:agent` IDREFS on whitespace; empty when absent) and `'isBackground' => bool` (any `ttm:role` token equals `x-bg`), computed from `meta` (own+inherited). Return gains `'hasVoiceParts' => bool`.

### 7.2 Writer (`lyricsIngest_writeToDb()`), inside the existing transaction after the syllable loop

```php
require_once __DIR__ . DIRECTORY_SEPARATOR . 'vocal_parts.php';
if (!empty($parsed['hasVoiceParts']) && vocalPartsTablesReady($db)) {
    /* 1. registry: every declared agent + every referenced-but-undeclared id (kind by vocalPartsKindFromTtmlAgent, MetaJson {undeclared:true}) */
    $agentIndex = vocalPartsAgentIndex($db, $lyricsId);          // existing rows (re-ingest)
    $personOrdinal = 0; $groupOrdinal = 0; $partIdByAgent = [];
    foreach ($orderedAgents as $ag) {                            // head order, then first-reference order for undeclared
        if (($ag['type'] ?? '') === 'person') { $personOrdinal++; }
        $kind  = vocalPartsKindFromTtmlAgent($ag, $personOrdinal);
        $label = $kind === 'group' ? 'Group ' . (++$groupOrdinal) : null;
        $partIdByAgent[$ag['id']] = $agentIndex[$ag['id']] ?? vocalPartsFindOrCreate($db, $lyricsId, $kind, $label, $source, $ag['id'], $ag['meta'] + ['singerName' => $ag['name']]);
    }
    $bgPartId = null;                                             // synthetic, lazily
    $bgPart = function () use (&$bgPartId, $db, $lyricsId, $source) { return $bgPartId ??= vocalPartsFindOrCreate($db, $lyricsId, 'backing', null, $source, 'x-bg', ['synthetic' => true]); };
    vocalPartsPruneAgents($db, $lyricsId, $source, array_merge(array_keys($partIdByAgent), ['x-bg']));
    /* 2. lines */   foreach lines: $ids = map agentIds → partIds; if line isBackground && empty($ids) → [$bgPart()];
                     if ($ids) vocalPartsAssignLinesForVersion($db, $lyricsId, $lineId, $ids, $line['isBackground']);
    /* 3. words */   per word: effective = [agentIds or line agentIds, bg = word bg || line bg]; if effective differs from the LINE's (agent set or bg):
                     $wIds = map (or [$bgPart()] when bg-only); foreach $wIds as $pid → vocalPartsAssignWords($db, $lyricsId, [$wordId], $pid, $wordBg);
}
```
Return adds `'vocalParts'=>n, 'lineParts'=>n, 'wordParts'=>n`; `api.php` `lyrics_ingest` copies them into the response and the activity log. `tblLyrics` needs no new flag (the `?include=vocalParts` read + `vocalPartsVersionHasAny()` answer it).

### 7.3 Read at word grain
Pass 1's `vocalPartsWordsMap()` + `vocalPartsForSong()`; `SongData::getSongDetailExtras('?include=vocalParts')` (SongData.php:3094) is EXTENDED (not forked) to return `{parts, lines, words, spans, rounds}` = `vocalPartsForSong()` for the song's TTML version when one exists, else the 'ihymns' version, keyed as today under `vocalParts`.

### 7.4 Backfill from existing MetaJson — `appWeb/.sql/migrate-backfill-ttml-vocal-parts.php`
Registry key `backfill-ttml-vocal-parts`, `'manual'=>true`, `'dryRunnable'=>true`. Include path per rule #41 (`$_incDir = defined('IHYMNS_INCLUDES_DIR') ? IHYMNS_INCLUDES_DIR : dirname(__DIR__).'/public_html/includes'`; requires `db_mysql.php`, `vocal_parts.php`). Scope: `SELECT ll.Id, ll.LyricsId, ly.Source, ll.MetaJson FROM tblLyricLines ll JOIN tblLyrics ly ON ly.Id=ll.LyricsId WHERE ly.Source <> 'ihymns' AND ll.MetaJson IS NOT NULL AND (JSON_EXTRACT(ll.MetaJson,'$."ttm:agent"') IS NOT NULL OR JSON_SEARCH(ll.MetaJson,'one','%x-bg%') IS NOT NULL)` chunked by LyricsId. Per version: agents in first-reference order (type unknown → `person` ordinal rule: first → lead, later → soloist; MetaJson `{backfill:'metajson', declaredType:null}`), lines via `vocalPartsAssignLinesForVersion` (INSERT IGNORE), bg via the synthetic part. WORD grain is NOT backfilled: a version whose `tblLyricWords.MetaJson` contains `x-bg`/`ttm:agent` is COUNTED and listed with its `SourceUrl` under "[needs re-ingest] — word-level roles were mis-modelled before the §7.1 parser fix". Dry-run prints counts per version; apply writes + sets sentinel `tblAppSettings.ttml_vocal_backfill_v = '1'`. Probe: tables ready AND sentinel absent AND ≥1 scoped line with no `tblLyricLineVocalParts` row → pending. Undo: `?undo=1` deletes `tblVocalParts` rows whose `MetaJson->'$.backfill'='metajson'` (CASCADE clears joins) and the sentinel.

---

## 8 — Exports: what is emitted, what is dropped

Shared fold (new `manage/editor/vocal-fold.js`, loaded by `editor/index.php` and imported by `v2/export.js`): `foldVoicesOntoComponents(song, vocal)` sets `comp.voices[i] = null | [{kind, name, bg}]` from `vocal.lines[lineId]` via `comp.lineIds[i]`, and `song.vocalParts = vocal.parts` (for group ordinals). Field is `name` (never `label`, §decisions). `voiceRuns(comp)` (in format-export.js, reused by every text builder) = consecutive lines sharing the same `kind|name|bg` key of the FIRST entry.

| Format | Emitted | Deliberately dropped | Re-import path |
|---|---|---|---|
| **OpenLyrics** | `<lines part="…">` per voice run (§3.3) | `repeat`, `bg`, second part of a duet line | §3.1 (`part` presence) |
| **TTML** (NEW, §8.2) | `<head><metadata><ttm:agent xml:id type><ttm:name>` per non-synthetic part; `<p ttm:agent="a b" ttm:role="x-bg"?>`; word `<span>`s with own agent/role where word rows exist; bg words wrapped in `<span ttm:role="x-bg">` | nothing (lossless for the stored model) | `lyrics_ingest` |
| **ProPresenter 7** | Chunks break at voice-run boundaries; cue `name` = `<Type N> · <Voice name>` for a run's slides (operator-visible). RTF untouched (`buildRTF()` byte-unchanged; the `[`-premise guard extends to "no voice name in rtf_data" unless `voiceCues:'inline'`) | bg, duet second part | group names only (§5.6 last sentence) |
| **OpenSong** | `;<Voice name>` comment row before each voice run | bg, duet | §5.3 (`;`-voice row) |
| **ChordPro** | `{comment: <Voice name>}` before each voice run (after the section `{comment:}`) | bg, duet | §5.6 (header marker → continuation) |
| **VideoPsalm** | First slide of a component keeps the structural Tag; a slide that STARTS a voice run after the first keeps Tag = `<Voice name>` | the first run's voice when it opens the component; bg; duet | §5.4 |
| **Proclaim / plain text** | `\n<Voice name>\n` + the run's lines as its own blank-separated block | bg, duet | §5.6 |
| **EasyWorship** (`includes/easyworship_export.php`) | nothing by default; `voiceCues:'inline'` → first line of a run prefixed `<Voice name>: ` | everything by default | n/a (EW importer sees a lyric line → in-body queue) |
| **FreeShow** | nothing (no verified metadata slot in the vendored fixture) | all | n/a |
| **MusicXML** | no exporter exists (import-only, first `<part>` only) | n/a | n/a |
| **Public copy/share, Present** | Pass 1's DOM chip (child of `.lyric-line`) — outside this pass | — | — |

`voiceCues` is a new key on the shared export options (`maxLinesOf`-style accessor `voiceCuesOf(options)`, default `'off'`), surfaced in `v2/export.js`'s options row and persisted beside `linesPerSlide`.

### 8.2 TTML exporter (server-side, words/timings live only in MySQL)
`includes/ttml_export.php::ttmlExportVersion(\mysqli $db, int $lyricsId): string` — DOMDocument build; root `<tt xmlns="http://www.w3.org/ns/ttml" xmlns:ttm="http://www.w3.org/ns/ttml#metadata" xmlns:itunes="http://music.apple.com/lyric-ttml-internal" xml:lang=…>`; agents from `tblVocalParts WHERE LyricsId=? AND (MetaJson IS NULL OR JSON_EXTRACT(MetaJson,'$.synthetic') IS NULL)` with `xml:id = TtmlAgentId ?? 'v'.Id`, `type = IHYMNS_VOCAL_PART_KINDS[kind]['ttmlAgent']`, `<ttm:name type="full">` = SingerName ?? musician name when set; lines `<p begin end ttm:agent itunes:key="L{n}">`; words `<span begin end>` when `tblLyricWords` rows exist, wrapped per contiguous bg group. Endpoints: `manage/editor/api2.php` `ttml_export` (GET `?songId=&lyricsId?=`, `ed2_requireEntitlement('edit_songs')`, `Content-Type: application/ttml+xml`) and the api.php twin `ttml_export` (same gate via `getAuthenticatedUser()` + `userHasEntitlement('edit_songs')`); both in `api-docs.yaml`.

---

## 9 — The review queue (D4)

### 9.1 DDL — appended to Pass 1's `migrate-vocal-parts-rounds.php` (own `_migVPR_tableExists` guard, added to the multi-object OR-probe) and mirrored byte-identically in `appWeb/.sql/schema.sql` (section "VOCAL-PART SUGGESTIONS (review queue)"):

```sql
CREATE TABLE IF NOT EXISTS tblVocalPartSuggestions (
    Id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    SongId             VARCHAR(20)     NOT NULL COMMENT 'FK tblSongs.SongId',
    LyricsId           INT UNSIGNED    NOT NULL COMMENT 'FK tblLyrics.Id — the ihymns version scanned',
    LineId             BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'FK tblLyricLines.Id of the marker/echo line; SET NULL when the line is gone (row shows as stale)',
    RunEndLineId       BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'Last line the marker governs (standalone form); NULL = to component end / next component',
    WordId             BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'Dormant — word-grain proposals (D2 curator entry later); FK tblLyricWords.Id',
    Form               VARCHAR(20)     NOT NULL COMMENT 'standalone | prefix | paren | canon (app-validated; VARCHAR not ENUM)',
    Marker             VARCHAR(120)    NOT NULL COMMENT 'The matched marker text as written',
    ProposedKind       VARCHAR(30)     NOT NULL COMMENT 'IHYMNS_VOCAL_PART_KINDS key',
    ProposedLabel      VARCHAR(120)    NULL DEFAULT NULL,
    ProposedAction     VARCHAR(30)     NOT NULL COMMENT 'convert-standalone | convert-prefix | mark-echo | create-round (app-validated)',
    ProposalJson       JSON            NOT NULL COMMENT '{lineText, cleanText, runLineIds[], contextBefore[], contextAfter[], parts[], similarity, componentIndex, lineIndex, nextComponent}',
    Confidence         DECIMAL(4,3)    NOT NULL,
    Signal             VARCHAR(30)     NOT NULL COMMENT 'allcaps | colon | titlecase | nbsp-run | space-run | paren-echo | canon-phrase (app-validated)',
    DetectorVersion    VARCHAR(10)     NOT NULL,
    Source             VARCHAR(40)     NOT NULL DEFAULT 'backfill' COMMENT 'backfill | rescan | import:<format>',
    Fingerprint        CHAR(64)        NOT NULL COMMENT 'sha256(songId|componentIndex|lineIndex|form|marker|lineText) — idempotency key, deliberately NOT the line Id',
    Status             VARCHAR(20)     NOT NULL DEFAULT 'pending' COMMENT 'pending | accepted | rejected | superseded | apply_failed (app-validated)',
    ReviewedBy         INT UNSIGNED    NULL DEFAULT NULL,
    ReviewedAt         DATETIME        NULL DEFAULT NULL,
    ReviewNote         TEXT            NULL DEFAULT NULL,
    AppliedRevisionId  INT UNSIGNED    NULL DEFAULT NULL COMMENT 'tblSongRevisions.Id written by accept — the undo pointer',
    CreatedAt          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_Fingerprint (Fingerprint),
    INDEX idx_Song   (SongId),
    INDEX idx_Status (Status, Confidence),
    INDEX idx_Line   (LineId),
    INDEX idx_Lyrics (LyricsId),
    CONSTRAINT fk_VocalSugg_Song     FOREIGN KEY (SongId)            REFERENCES tblSongs(SongId)      ON DELETE CASCADE  ON UPDATE CASCADE,
    CONSTRAINT fk_VocalSugg_Lyrics   FOREIGN KEY (LyricsId)          REFERENCES tblLyrics(Id)         ON DELETE CASCADE  ON UPDATE CASCADE,
    CONSTRAINT fk_VocalSugg_Line     FOREIGN KEY (LineId)            REFERENCES tblLyricLines(Id)     ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_VocalSugg_RunEnd   FOREIGN KEY (RunEndLineId)      REFERENCES tblLyricLines(Id)     ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_VocalSugg_Word     FOREIGN KEY (WordId)            REFERENCES tblLyricWords(Id)     ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_VocalSugg_Reviewer FOREIGN KEY (ReviewedBy)        REFERENCES tblUsers(Id)          ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_VocalSugg_Revision FOREIGN KEY (AppliedRevisionId) REFERENCES tblSongRevisions(Id)  ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Machine-proposed voice-part conversions awaiting curator review (voice-parts program, D4).';
```
Rule #20 stress ("what forces a second migration?"): new forms/actions/signals/statuses → VARCHAR; word-grain proposals → `WordId` reserved; multi-part proposals → `ProposalJson.parts[]`; a different consumer (#1260 triage) stores its own rows in its own table and only shares the DETECTOR. If Pass 1 §1.4 named this table differently, that name is replaced by `tblVocalPartSuggestions` — this pass owns the queue.

### 9.2 ONE core — `includes/vocal_part_suggestions.php`
```php
function vocalSuggestTablesReady(\mysqli $db): bool;                              // tblVocalPartSuggestions + vocalPartsTablesReady()
function vocalSuggestFingerprint(string $songId, array $finding): string;         // PURE sha256 as documented on the column
/** Scan ONE song: assemble via lyricLinesAssembleComponents() (public shape → lineIds), run vocalDetectComponents(),
 *  INSERT IGNORE one row per finding (Status pending, Source=$source). Reopen rule: a finding whose fingerprint exists
 *  with Status='accepted' → UPDATE Status='pending', ReviewNote=CONCAT_WS(' | ',ReviewNote,'reopened: marker present again after a restore').
 *  Rows of this song with Status='pending' whose fingerprint was NOT found this scan → 'superseded'.
 *  @return array{found:int, inserted:int, reopened:int, superseded:int} */
function vocalSuggestScanSong(\mysqli $db, string $songId, string $source): array;
function vocalSuggestList(\mysqli $db, array $filter, int $limit, int $offset): array;   // filter: status, form, minConfidence, songbook, songId; ORDER BY Confidence DESC, SongId, LineId
function vocalSuggestReject(\mysqli $db, array $ids, ?int $userId, ?string $note): int;
/** Accept: groups $ids by SongId; per song, ONE transaction:
 *   1. $comps = lyricLinesEditableComponents() (has lineIds, chords, notes, languages, voices, label, sourceWorkId);
 *   2. for each accepted row (document order, later indices first so splices stay valid):
 *        convert-standalone: splice the marker line out of lines/chords/notes/languages/voices/lineIds; set voices[j] = [{kind,label}] for every line
 *                            LineId..RunEndLineId (or to component end; ProposalJson.nextComponent → the next component's lines);
 *        convert-prefix:     lines[i] = cleanText; voices[i] = [{kind,label}];
 *        mark-echo:          voices[i] = [{kind:'backing', bg:true}]; text unchanged;
 *        create-round:       splice the phrase line; lyricRoundUpsert() (Pass 1) with kind 'canon', voices from ProposalJson.parts;
 *      $overrides[id] = {kind?, label?} from the reviewer replaces the proposal's kind/label;
 *   3. lyricLinesWriteComponents($db, $songId, $comps + ['_voiceSource' => 'ihymns']);   (voices cells: null ⇒ CLEAR, list ⇒ REPLACE)
 *   4. $revId = songRevisionRecord($db, $songId, $userId, 'edit');   (§9.4)
 *   5. UPDATE rows: Status='accepted', ReviewedBy/At, AppliedRevisionId=$revId; searchFoldSyncSong() as save_song_core does.
 *   A throw → rollback; rows Status='apply_failed' (own short transaction) with the message in ReviewNote.
 *  @return array{accepted:int, failed:int, revisions:list<int>} */
function vocalSuggestAccept(\mysqli $db, array $ids, array $overrides, ?int $userId): array;
```
Validation: kind via `vocalPartsNormalizeKind()` (→ `InvalidArgumentException` 400), ids must be `pending` (→ 409 `{reason:'not_pending'}`), a row whose `LineId IS NULL` cannot be accepted (→ 409 `{reason:'stale'}`; the page shows "line no longer exists — rescan").

### 9.3 Page `manage/vocal-parts-review.php`
Shared partials (`admin-nav.php` with `$activePage = 'vocal-parts-review'`, `admin-footer.php`, `head-favicon.php`, `auth.php`, `db.php`); gate `isAuthenticated()` + `userHasEntitlement('edit_songs', …)` (403 otherwise); nav entry in `manage/includes/admin-links.php`: `['vocal-parts-review', '/manage/vocal-parts-review', 'bi-people', 'Voice-part review', 'edit_songs', 'Songs']`. Un-migrated install → themed "not migrated" card (no throw). POST actions (`validateCsrfRequest()`): `accept` (`ids[]`, `kind[id]`, `label[id]`), `reject` (`ids[]`, `note`), `rescan` (`songId` or `scope=all` → chunked `lyricLinesAssembleComponentsMap()` in 200-song batches — never the whole corpus in memory). Table: `admin-table-responsive` + sortable headers; columns Song (link `/manage/editor/editor2.php?song=<id>` — existing param, rule #33), Line in context (3 before/after from ProposalJson, marker highlighted), Form/Signal, Proposal (kind `<select>` from `IHYMNS_VOCAL_PART_KINDS` + Label text input), Confidence, Actions. Filters: status (default pending), form, min confidence (default 0), songbook. Bulk: "Accept all ≥ 0.90 shown", "Reject selected". Rows with `LineId IS NULL` render disabled with a "stale — rescan" badge.

### 9.4 Extraction — `includes/song_snapshot.php`
`songBuildSnapshot(\mysqli $db, string $songId): ?array` = the body of `ed2_buildSongSnapshot()` moved verbatim; `songRevisionRecord(\mysqli $db, string $songId, ?int $userId, string $actionTag): int` = api2.php's block at ~2170-2200 (previous NewData → PreviousData, snapshot → NewData, `"approved"`, the `songRelocateIsTransactionFatal` re-throw) returning `insert_id`. `api2.php` keeps `ed2_buildSongSnapshot()` as a one-line delegate and calls `songRevisionRecord()` where the block was (byte-identical rows; guard §10.6).

### 9.5 API twins (`api.php`, rule #48) — all `getAuthenticatedUser()` + `userHasEntitlement('edit_songs')`, POST JSON, `validateCsrfRequest()` unless `$apiBearerAuthed`, `sendJson()` envelope, delegate to §9.2:
`admin_vocal_suggestions_list` (GET, filter params), `admin_vocal_suggestion_accept` `{ids[], overrides{}}`, `admin_vocal_suggestion_reject` `{ids[], note}`, `admin_vocal_suggestions_rescan` `{songId}` (single song only over the API; `scope=all` stays web-only — long-running). Mapping entries in `tests/php/test-manage-action-api-coverage.php`: `'vocal-parts-review.php' => ['accept'=>'api:admin_vocal_suggestion_accept','reject'=>'api:admin_vocal_suggestion_reject','rescan'=>'api:admin_vocal_suggestions_rescan']`. `api-docs.yaml` path items in the same change.

### 9.6 Backfill card — `appWeb/.sql/migrate-backfill-vocal-part-suggestions.php`
Registry key `backfill-vocal-part-suggestions`, `'manual'=>true`, `'dryRunnable'=>true`, card title "Voice-part suggestions backfill (proposal-only)". Shape = `migrate-backfill-canonical-songids.php` (`$isCli`, `$dryRun = $isCli ? !in_array('--confirm',$argv) : empty($_GET['confirm'])`, `$_incDir` rule #41, `getDbMysqli()`, `return` not `exit` under the dashboard). Requires `db_mysql.php`, `lyric_lines_read.php`, `lyric_lines_sync.php` (for `lyricLinesSimilarity`), `vocal_parts.php`, `vocal_part_detect.php`, `vocal_part_suggestions.php`. Walk `SELECT SongId FROM tblSongs ORDER BY SongId` in 200-id chunks → `lyricLinesAssembleComponentsMap()` → `vocalDetectComponents()`; DRY RUN prints counts by form/signal/songbook and the first 40 findings with context; APPLY calls `vocalSuggestScanSong($db,$songId,'backfill')` per song and finally `setAppSetting('vocal_suggestions_backfill_v', IHYMNS_VOCAL_DETECT_VERSION)`. It NEVER writes lines or parts. Probe: `vocalSuggestTablesReady` AND `getAppSetting('vocal_suggestions_backfill_v') !== IHYMNS_VOCAL_DETECT_VERSION` → pending (bumping the detector version re-pends the card by construction). Undo: `?undo=1` / `--undo` → `DELETE FROM tblVocalPartSuggestions WHERE Source='backfill' AND Status='pending'` + clear the sentinel (reviewed rows are never deleted).

### 9.7 Revisions — the recoverability chain
Accept → revision row (PreviousData = state before, NewData = state after, both carrying `voices`) → Revisions tab restore (existing) writes the old snapshot through `lyricLinesWriteComponents()`: the marker line is re-INSERTed, run lines keep their Ids, their `voices` cells (`null` in the old snapshot) CLEAR the assignments (§1 `vocalPartsApplyComponentVoices` semantics), and the next rescan re-finds the fingerprint and flips the row back to `pending` (§9.2 reopen rule). A pre-feature snapshot (no `voices` key) preserves assignments (writer layer, `voicesProvided=false`).

---

## 10 — Guards (all tree-derived where a list exists, all mutation-proven before merge; rule #34)

1. `tests/php/test-vocal-part-detect.php` — PURE truth table: NBSP-run prefix (`"MEN\u{00A0}\u{00A0}\u{00A0}\u{00A0}You are holy,"` → prefix/male/0.90/cleanText `You are holy,`), ALL-CAPS standalone, `"ALL creation sings"` → NO finding, `"Men:"` → 0.60, paren direction `(repeat verse 2)` → none, paren echo of previous line → 0.75, canon phrase → create-round with two parts, determinism (run twice, identical). Mutation: drop `\x{00A0}` from the gap class → RED.
2. `tests/php/test-vocal-vocabulary.php` — every UPPER marker in `IHYMNS_VOCAL_PART_KINDS[*]['markers']` ∉ {tblSongPartTypes seeded Name/Slug (parsed from `migrate-song-part-types.php`), `REFLOW_TYPES` + `REFLOW_LABEL_RE` words (parsed from `reflow.js`), `_bulkImport_pro7GroupType`'s `$wordMap` keys}. Mutation: add `'CHORUS' => null` to a kind → RED.
3. `tests/test-openlyrics-voices.js` + `tests/php/test-openlyrics-voice-import.php` — export: a voice-less song's XML is byte-identical to the pinned pre-change output; a voiced song emits `part=` only on voiced runs; PHP↔JS lockstep of the `openlyrics` keyword column. Import: fixture with `<lines part="men">`, an attribute-less second block, `repeat="2"` → components/voices/notes exactly as §3.1. Mutation: make the importer treat every block as a part → RED.
4. `tests/php/test-importer-marker-preserve.php` — for EVERY `function _bulkImport_parse*(` in song_importers.php (tree-derived) whose body contains a section-marker fold, assert it references `vocalDetectHeaderMarker(`; fixtures per format with a `MEN` header → no `refrain` minted, previous component grows, `voices` set, `_voiceSource='import-marker'`; `Coro` header → `refrain` + `label:'Coro'`. Mutation: restore the old `?? 'refrain'` in one parser → RED.
5. `tests/php/test-lyric-lines-preserve-on-omit.php` — `lyricLinesMergePreserved()` truth table; `lyricLinesBuildDesiredFromComponents()` sets `_preserve` from key presence; `lyricLinesBuildDesired()` (legacy) emits NO `_preserve`; `lyricLinesEditableComponents` body contains `'notes'` and `'voices'` (extracted-body technique). Mutation: remove the Note branch → RED.
6. `tests/php/test-song-snapshot-extraction.php` — api2.php contains exactly ONE `INSERT INTO tblSongRevisions` (the shared core's) reached via `songRevisionRecord(`; `vocal_part_suggestions.php` contains ZERO `INSERT INTO tblSongRevisions`.
7. `tests/php/test-ttml-vocal-ingest.php` — parser: head agents parsed; container span with whitespace → two words each inheriting `x-bg`; word-with-syllables (no whitespace) unchanged; IDREFS split; writer (DB) idempotent on re-ingest (same part Ids, Label edit survives), synthetic `x-bg` part, word rows only where differing. Mutation: revert the container rule → `Ohyeah` → RED.
8. `tests/php/test-vocal-suggestions-core.php` — fingerprint stability across a line-Id churn; accept splices every parallel array in step (chords/notes/languages/voices/lineIds lengths equal after); reopen-on-refind; stale row → 409 `stale`.
9. `tests/test-propresenter-export.js` — extend the premise guard: no voice `name` string appears in any `rtf_data` unless `voiceCues:'inline'`; a voice-less song is byte-identical.
10. `tests/test-component-label-sites.js` — unchanged and must stay green (the fold uses `name`).
11. Existing: `test-schema-coverage.php`, `test-schema-ddl-parity.php`, `test-migration-registry.php`, `test-deploy-paths.php` (both new `.sql` scripts), `test-manage-action-api-coverage.php`, `test-api-gate-parity.php`, `test-lyric-lines-read.php` (+1 fixture with a note asserting the sparse `notes` key), `tools/export-fidelity-snapshot.php` re-baselined ONLY for songs carrying a Note (list them in the commit body).

## Files
- `appWeb/public_html/includes/vocal_parts.php` — ADD (to Pass 2's file): 'import-marker' in IHYMNS_VOCAL_SOURCES_STRUCTURED; vocalPartsAssignLinesForVersion(), vocalPartsAgentIndex(), vocalPartsPruneAgents(), vocalPartsKindFromTtmlAgent(), vocalPartsResolveOpenLyricsPart(), vocalPartsApplyComponentVoices() (§1, §3.2).
- `appWeb/public_html/includes/vocal_part_detect.php` (NEW) — The PURE detection core (§6 + §5.1): IHYMNS_VOCAL_DETECT_VERSION, vocalDetectMarkerTable() derived from the vocabulary, IHYMNS_VOCAL_CANON_RE / IHYMNS_VOCAL_DIRECTION_RE, vocalDetectHeaderMarker(), vocalDetectMergeVoiceBlock(), vocalDetectComponents(). Shared with #1260's triage.
- `appWeb/public_html/includes/vocal_part_suggestions.php` (NEW) — The ONE queue core (§9.2): tables-ready probe, fingerprint, scan (INSERT IGNORE + reopen + supersede), list, reject, accept (write through lyricLinesWriteComponents + songRevisionRecord).
- `appWeb/public_html/includes/song_snapshot.php` (NEW) — songBuildSnapshot() and songRevisionRecord() extracted verbatim from manage/editor/api2.php (§9.4).
- `appWeb/public_html/includes/ttml_export.php` (NEW) — ttmlExportVersion(): the new server-side TTML exporter round-tripping agents, x-bg, line and word grain (§8.2).
- `appWeb/public_html/includes/lyric_lines_sync.php` — lyricLinesApplyDesired() Id-out param + lyricLinesMergePreserved() call; lyricLinesWriteComponents() notesProvided/chordsProvided/voices/voicesProvided normalisation + post-write vocalPartsApplyComponentVoices(); _preserve flags in lyricLinesBuildDesiredFromComponents(); new PURE lyricLinesMergePreserved(); lyricLinesVocalTablesPresent() + voice rows in lyricLinesSnapshotDeletedEnrichment() (§2).
- `appWeb/public_html/includes/lyric_lines_read.php` — SELECT ll.Note AS line_note in both fetchers; sparse `notes` in the public shape; always-present `notes` + `voices` in the editable shape (§4.1-4.3).
- `appWeb/public_html/includes/lyrics_ingest.php` — Parser: head <ttm:agent> defs, container-span fix, per line/word agentIds + isBackground, hasVoiceParts. Writer: registry find-or-create by TtmlAgentId, prune, line rows, word rows where differing, synthetic x-bg part; return counts (§7.1-7.2).
- `appWeb/public_html/includes/song_importers.php` — #2071: read part=/repeat= off each <lines> node, voices transport, 'Repeat ×N' note, _voiceSource stamp. #2075: _bulkImport_componentTypeFor() deleted → _bulkImport_sectionFromMarker(); .txt/OpenSong/VideoPsalm/OpenLyrics/ChordPro/Proclaim header paths call vocalDetectHeaderMarker() + vocalDetectMergeVoiceBlock(); OpenSong ';' voice rows; PP7 group name voice merge; per-parser _format stamp; import-time vocalSuggestScanSong() in _bulkImport_saveSong() (§3.1, §5).
- `appWeb/public_html/includes/SongData.php` — getSongDetailExtras('vocalParts') extended to vocalPartsForSong() (parts/lines/words/spans/rounds), TTML version preferred when present (§7.3).
- `appWeb/public_html/includes/access_resolver.php` — Add `notes` to the lyric-body field set stripped under content gating (§4.9).
- `appWeb/public_html/includes/pages/song.php` — data-note attribute on .lyric-line when a per-line note exists (§4.6).
- `appWeb/public_html/includes/easyworship_export.php` — voiceCues:'inline' opt-in prefix on the first line of a voice run; default output byte-identical (§8).
- `appWeb/public_html/js/modules/present-mode.js` — Read l.dataset.note per line; show non-empty notes in the operator-only strip (§4.7).
- `appWeb/public_html/manage/editor/save_song_core.php` — PF1 carry for notes; pass 'notes' to lyricLinesWriteComponents() (§4.4).
- `appWeb/public_html/manage/editor/api2.php` — component_upsert / components_replace carry 'notes' (omit-preserve idiom); ed2_buildSongSnapshot() delegates to songBuildSnapshot(); revision block replaced by songRevisionRecord(); new ttml_export action (§4.5, §8.2, §9.4).
- `appWeb/public_html/manage/editor/v2/structure-tab.js` — Per-line Note input in the chord-row pattern; remapChordsOnLinesChange() generalised to remapParallelOnLinesChange() for chords/notes/voices (§4.8).
- `appWeb/public_html/manage/editor/vocal-fold.js` (NEW) — foldVoicesOntoComponents(song, vocal) → comp.voices[i] = [{kind,name,bg}] + song.vocalParts; shared by v1 editor.js and v2 export.js (§8).
- `appWeb/public_html/manage/editor/format-export.js` — voiceRuns(), olPartToken()/OL_PART lockstep table, part= on OpenLyrics voice-run blocks; OpenSong ';' rows; ChordPro {comment:}; VideoPsalm voice-run Tags; Proclaim header blocks; voiceCuesOf(options) (§3.3, §8).
- `appWeb/public_html/manage/editor/propresenter-export.js` — Chunk at voice-run boundaries; cue name suffix ' · <Voice name>'; voiceCues:'inline' opt-in only path that touches RTF (§8).
- `appWeb/public_html/manage/editor/v2/export.js` — Import vocal-fold.js; fold the store's vocalParts payload before every builder; voiceCues option row persisted beside linesPerSlide (§8).
- `appWeb/public_html/manage/editor/editor.js` — v1 export path calls foldVoicesOntoComponents() before handing the song to format-export / propresenter-export (§8).
- `appWeb/public_html/manage/editor/index.php` — Load vocal-fold.js before format-export.js (script order).
- `appWeb/public_html/manage/vocal-parts-review.php` (NEW) — The review-queue page (§9.3): shared partials, edit_songs gate, validateCsrfRequest() POST actions accept/reject/rescan, responsive sortable table, filters, bulk accept ≥0.90, stale rows disabled.
- `appWeb/public_html/manage/includes/admin-links.php` — Nav entry ['vocal-parts-review', '/manage/vocal-parts-review', 'bi-people', 'Voice-part review', 'edit_songs', 'Songs'].
- `appWeb/public_html/api.php` — lyrics_ingest response/log gain vocalParts/lineParts/wordParts; new actions admin_vocal_suggestions_list / admin_vocal_suggestion_accept / admin_vocal_suggestion_reject / admin_vocal_suggestions_rescan / ttml_export (§7.2, §8.2, §9.5).
- `appWeb/public_html/api-docs.yaml` — Path items for the five new API actions; lyrics_ingest response schema additions.
- `appWeb/.sql/migrate-vocal-parts-rounds.php` — ADD (to Pass 1's single migration): CREATE TABLE tblVocalPartSuggestions with its own existence guard; OR-probe in the registry entry grows `|| !tableExists('tblVocalPartSuggestions')` (§9.1).
- `appWeb/.sql/schema.sql` — Byte-identical mirror of tblVocalPartSuggestions (§9.1).
- `appWeb/.sql/migrate-backfill-vocal-part-suggestions.php` (NEW) — Proposal-only backfill, dry-run default, --confirm/&confirm=1, --undo/&undo=1, IHYMNS_INCLUDES_DIR include resolution, chunked 200-song scan, sentinel tblAppSettings.vocal_suggestions_backfill_v (§9.6).
- `appWeb/.sql/migrate-backfill-ttml-vocal-parts.php` (NEW) — Line-level backfill of tblVocalParts/tblLyricLineVocalParts from tblLyricLines.MetaJson for non-ihymns versions; reports word-level versions needing re-ingest; dry-run default; undo (§7.4).
- `appWeb/public_html/manage/includes/migration-registry.php` — Entries backfill-vocal-part-suggestions and backfill-ttml-vocal-parts (manual + dryRunnable, real sentinel/data probes); vocal-parts-rounds probe extended (§7.4, §9.6).
- `tests/php/fixtures/orphan-allowlist.php` — Retire the tblVocalParts 'read side shipped, write side is future' entry — the table now has writers (ingest, importers, queue accept).
- `tests/php/test-manage-action-api-coverage.php` — Mapping entry for vocal-parts-review.php (accept/reject/rescan → api twins).
- `tests/php/test-vocal-part-detect.php` (NEW) — Guard §10.1 (PURE truth table incl. the NBSP form, mutation-proven).
- `tests/php/test-vocal-vocabulary.php` (NEW) — Guard §10.2 (markers disjoint from every section word, tree-derived).
- `tests/php/test-openlyrics-voice-import.php` (NEW) — Guard §10.3 import half.
- `tests/test-openlyrics-voices.js` (NEW) — Guard §10.3 export half + PHP↔JS openlyrics-keyword lockstep.
- `tests/php/test-importer-marker-preserve.php` (NEW) — Guard §10.4 (tree-derived over every _bulkImport_parse* function).
- `tests/php/test-lyric-lines-preserve-on-omit.php` (NEW) — Guard §10.5.
- `tests/php/test-song-snapshot-extraction.php` (NEW) — Guard §10.6.
- `tests/php/test-ttml-vocal-ingest.php` (NEW) — Guard §10.7 (parser container-span fix + writer idempotency).
- `tests/php/test-vocal-suggestions-core.php` (NEW) — Guard §10.8.
- `tests/test-propresenter-export.js` — Extend the premise guard: no voice name in rtf_data unless voiceCues:'inline'; voice-less song byte-identical (§10.9).
- `tests/php/test-lyric-lines-read.php` — One new fixture with a per-line Note asserting the sparse `notes` key and unchanged key order for note-less components.
- `tests/fixtures/ttml/x-bg-container.ttml` (NEW) — Fixture: head agents (person v1, group v2), a <p ttm:agent="v1"> with a whitespace-separated <span ttm:role="x-bg"> container of two timed words, and a nested-syllable word — the two shapes the parser must now distinguish.
- `tests/fixtures/openlyrics/voices-part-repeat.xml` (NEW) — Fixture: <lines part="men">, <lines part="women">, an attribute-less chunk block and <lines repeat="2">.
- `WHATS-NEW.md` — Plain-language bullets under the current heading: voice parts imported from OpenLyrics/TTML; the 'Men/Women' review queue; per-line presenter notes now kept and shown. No internals.
- `CHANGELOG.md` — Technical entries for #2071, #2072, #2075, the ingest word grain, the queue and the two backfill cards.

## Risks
- SILENT-NO-OP CLASS: `lyricLinesWriteComponents()` converts `voices` only when `vocalPartsTablesReady()` is true. On an un-migrated env the import succeeds and every voice tag simply vanishes with no error — exactly the #1565 shape. Mitigation in spec: `vocalPartsApplyComponentVoices()` returns counts, `_bulkImport_saveSong()` logs `voicesDropped` when tables are not ready, and the import summary shows a warning line.
- The `_preserve` substitution changes writer behaviour for ANY caller that omitted `chords`/`notes` and MEANT 'clear'. The audit in §2 found none (importers INSERT; ingest writes its own version; restore carries both keys), but a third-party api2 client that PATCHes a component without `chords` will now keep stored chords instead of wiping them. Documented in api-docs as the intended omit-preserve contract (it already is for chords at the funnel layer).
- Adding the sparse `notes` key to the public shape changes the per-song sha256 in `tools/export-fidelity-snapshot.php` for every song that carries a Note today. The count must be reported in the commit; if it is large (OpenLyrics `<comment>` imports), the re-baseline diff needs an eyeball pass, not a blind accept.
- The TTML container-span fix changes the WORD rows a re-ingest produces (more, correct words). Any consumer keyed on `tblLyricWords.Id` for an existing TTML version (none found in the tree) would break on re-ingest; the backfill deliberately does not touch words for this reason.
- Idempotent agent matching keys on `TtmlAgentId` only. Two different TTML sources for the same song share ONE `tblLyrics` row per `Source`, so this is per-version safe — but a re-ingest under a DIFFERENT `source` string creates a second version with its own parts (correct, but a curator may see duplicated part registries in `?include=vocalParts` if the include ever returns more than one version).
- Detector false positives: bare title-case 'All'/'Together' lines score 0.30 and parenthesised non-echo lines 0.35; they will fill the bottom of the queue. The default page filter (min confidence 0) shows them; if reviewers find the noise unacceptable, the ONE knob is the default filter value, not the detector.
- Fingerprint excludes the line Id by design; if a curator EDITS the marker line's text (e.g. fixes a typo) before reviewing, the pending row is superseded and a new one created — an accepted/rejected decision is therefore per text, not per line. Documented on the column.
- `vocalDetectMergeVoiceBlock()` attaches a header-position voice block to the PREVIOUS component. A file whose first section is a voice block with no preceding section opens an unnumbered verse (number 0); a later bare '1' header then produces a separate Verse 1 — correct by the file's grammar, but a curator may want to merge them (out of scope; the editor's existing merge handles it).
- ProPresenter cue-name suffixes: `tests/test-propresenter-export.js` may pin cue names in existing fixtures. If a voice-LESS fixture's output changes, the run-splitting is wrong (it must be a no-op without voices) — treat any such diff as a bug, never re-baseline.
- Rule #19 byte-parity: the `tblVocalPartSuggestions` DDL must be pasted identically into Pass 1's migration AND schema.sql (COMMENT text included); `test-schema-ddl-parity.php` will catch drift, but only if Pass 1's migration is registered with the extended OR-probe.
- The two backfill cards are `manual`, so they never run via 'Apply all'. If an operator applies Pass 1's schema card but never runs the suggestions backfill, the review page is simply empty — the page must say 'no proposals yet — run the backfill card' rather than look broken (specified as the empty-state copy).
- `vocalSuggestScanSong()` is called inside `_bulkImport_saveSong()`'s transaction; it must re-throw only `songRelocateIsTransactionFatal()` errors and swallow everything else, or a detector bug would fail every bulk import.