<?php

declare(strict_types=1);

/**
 * iHymns — Voice parts / echo / rounds: vocabulary + read-only core (#2073, commit 1)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5: this file is the ONE place that knows "who sings this line" — the
 * fixed list of voice kinds (Men, Women, Choir, a Soloist, …), the helpers
 * that turn a typed word like "MEN" into one of those kinds, and the
 * read-only queries that fetch what a song has already had assigned. It
 * does NOT yet let anyone assign a voice to a line — that is write-side
 * work landing in a later commit of the same feature (#2073 commit 5).
 *
 * WHY A PHP MAP, NOT A DB REGISTRY (rule #20 of .claude/CLAUDE.md): the set
 * of "kinds" (lead/soloist/male/female/children/all/…) is fixed and shared
 * by three machine export formats (OpenLyrics `<lines part="…">`, TTML
 * `<ttm:agent type="…">`, and a plain-text UPPER-CASE marker word) — it is
 * a vocabulary, not user data, so it lives in ONE central PHP constant
 * (`IHYMNS_VOCAL_PART_KINDS`, the `IHYMNS_ORG_LOGO_KINDS` house shape) and
 * is validated against, never stored as an ENUM. Curator-level variation
 * (a specific singer's name, a custom label like "Boys' Choir") lives on
 * the PER-SONG `tblVocalParts` row's `Label` / `SingerName` / `MusicianId`
 * columns — the Type-vs-Label split rule #45 already established for
 * component names applies here too: the KIND is structural and drives
 * exports/CSS, the Label is a display-only override.
 *
 * SHIPPED SCHEMA THIS FILE READS (all pre-existing, #1137 — "ZERO ALTERs"
 * per the #2073 plan; this commit does not touch them):
 *   - tblVocalParts           — per-lyrics-version part registry.
 *   - tblLyricLineVocalParts  — many-to-many LINE assignment (duet/unison).
 *   - tblLyricWordVocalParts  — many-to-many WORD assignment (karaoke-grade
 *                               overrides; a word WITH rows here overrides
 *                               its line's parts, a word with none inherits).
 * PLUS one table this file's readiness probe for (`vocalPartsSpansReady()`)
 * anticipates but that does not exist until #2073 commit 2's migration:
 *   - tblLyricLineVocalSpans  — SUB-LINE echo / mid-line voice-switch spans,
 *                               anchored on `tblLyricLines.Id` by CODE-POINT
 *                               offset (rule #21 — never byte/UTF-16).
 * Both probes memoise per request and degrade to `false` on an install
 * that has not run the matching migration (rule #19 — migrations are not
 * auto-applied on deploy), so every reader in this file returns the empty
 * shape rather than throwing under MYSQLI_REPORT_STRICT on a bare SELECT
 * of a column/table that is not there yet.
 *
 * MODELLED ON `includes/line_enrichment.php` (#1235 P3 / #1088) — same
 * file-header shape, same throw contract every function in this file
 * honours: `\InvalidArgumentException` -> the caller answers HTTP 400 (bad
 * input), `\RuntimeException` -> HTTP 404 (the id doesn't exist or doesn't
 * belong to the song asked about — never say which, so a guess can't probe
 * for the difference), and `vocalPartsTablesReady() === false` -> the
 * CALLER answers HTTP 409 (feature not migrated here yet). This file
 * throws neither of the latter two on a bad readiness check itself — every
 * reader below simply returns its empty shape, because a read has nothing
 * to refuse; only a WRITE (commit 5) has a reason to 409.
 *
 * COMMIT SCOPE (#2073 commits 1 and 4 of 17 — see
 * `.claude/vocal-parts-2073-plan.md`, "Design pass 7" §12 for the full plan):
 * vocabulary + normalisers + shape builders + READ fetchers ONLY. There is
 * deliberately NO `vocalPartsUpsert`, `vocalPartsAssignLines`,
 * `vocalPartsSpanUpsert`, `vocalPartsDelete` or any other function that
 * INSERTs/UPDATEs/DELETEs a row in this file yet — those land in commit 5
 * alongside `includes/lyric_rounds.php`.
 *
 * Commit 1 shipped the vocabulary + probes + shape builders with NOTHING
 * calling this file yet. Commit 4 ("Design pass 7" §5.3, the public read
 * assembler) wires the first real caller: `includes/lyric_lines_read.php`'s
 * `lyricLinesFetchVoices()` requires this file lazily and calls the
 * song-keyed, chunked fetchers below (`vocalPartsLinesMapForSongs()` /
 * `vocalPartsSpansMapForSongs()`); `SongData::getSongDetailExtras()`'s
 * `?include=vocalParts|vocalWords` blocks call `vocalPartsForVersion()` /
 * `vocalPartsWordsForSong()` directly. This file is STILL read-only (rule
 * A's "verified no-op" posture is about WRITES; every table this file reads
 * already ships pre-#2073, so a read commit was never dormant in the
 * no-tables-yet sense — it is dormant only in the "no assignment rows exist
 * yet" sense, which is what keeps every one of the ~16,083 songs' fidelity
 * hashes byte-identical after this commit, per that file's own doc-block).
 *
 * Every DB value that enters a query is bound via `bindParamSafe()` (#928);
 * the only interpolated SQL text is `array_fill()`-built `?,?,?` placeholder
 * strings (rule #5) — never a caller-supplied value.
 *
 * @see https://docs.openlyrics.org/en/latest/dataformat.html#lines   OpenLyrics `<lines part="…">`
 * @see https://www.w3.org/TR/ttml2/#metadata-vocabulary-agent        TTML2 `ttm:agent`
 * @see https://www.php.net/manual/en/function.mb-strtoupper.php      code-point safe case-folding
 * @see .claude/vocal-parts-2073-plan.md                              the plan of record (read "Design pass 7" first)
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

/* The canonical DB layer — getDbMysqli() + the bindParamSafe() count-guard
   every DB function below binds through (#928). Lazy: requiring it opens no
   connection of its own. */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'db_mysql.php';

/* `lyricLinesPrimaryLyricsId()` — the ONE shared "which lyrics version is
   the curator's own" resolver (#2076). #2073's own design passes disagreed
   about whether this needed to be a NEW function; it already exists here,
   so this file reuses it rather than re-deriving the same
   `Source = 'ihymns'` filter a second time (rule #22 / rule #35). */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'lyric_lines_read.php';

/* =====================================================================
 * VOCABULARY — the fixed voice-kind map + its small satellite constants.
 * ===================================================================== */

/**
 * ELI5: the fixed list of "who sings this" kinds the whole app understands.
 *
 * WHY: one place (rule #20 / #22 of .claude/CLAUDE.md). Key ORDER is the
 * picker order a future editor UI serves verbatim (never re-sorted
 * client-side — see `vocalPartsKindsProjection()` below). Every entry
 * carries six facets:
 *   label       — the plain display word the UI shows when a part has no
 *                 curator-set Label of its own.
 *   description — one plain sentence for a picker's help text.
 *   gender      — the gender the kind IMPLIES (rule #44 — derived, never a
 *                 second hand-set flag), or null when the kind carries no
 *                 gender implication at all.
 *   markers     — UPPER-CASE words a plain-text lyric sheet uses as a voice
 *                 cue. `WORD => null` means "use the kind's own label";
 *                 `WORD => 'Override'` means the detector should PROPOSE
 *                 that more specific label instead (e.g. "BOYS" still means
 *                 the `male` kind, but the curator sees "Boys" suggested,
 *                 not the generic "Men"). The FIRST key in this list is the
 *                 canonical marker `vocalPartsExportKeyword()` writes back
 *                 out for an export format with no real voice concept.
 *                 NEVER a section word (VERSE/CHORUS/BRIDGE/REFRAIN/…) —
 *                 those belong to `tblSongPartTypes`
 *                 (`appWeb/.sql/migrate-song-part-types.php`), a completely
 *                 different vocabulary; see the SOLO note below for the one
 *                 place this line is genuinely blurry.
 *   openlyrics  — the `<lines part="…">` keyword OpenLyrics 0.8 leaves as
 *                 free text; iHymns writes this lower-case word so other
 *                 OpenLyrics-reading tools see something conventional.
 *   ttmlAgent   — the `<ttm:agent type="…">` value TTML2 defines: only
 *                 person | group | other (plus character/organization,
 *                 which iHymns maps down onto `named-singer` / `choir` on
 *                 import — see `vocalPartsKindFromTtmlAgent()`, a later
 *                 commit's ingest-only helper).
 *
 * ⚠️ KNOWN, DELIBERATE OVERLAP WITH `tblSongPartTypes` ("SOLO" / "Solo") —
 * AND ITS RESOLVED POLICY (#2073 commit 2 cross-review finding, closed):
 * the section-type seed list (`migrate-song-part-types.php`) includes a
 * structural section named "Solo" (an instrumental solo section — no
 * singing at all), while this map's `soloist` kind uses the marker word
 * "SOLO" for "one singer sings alone". These are genuinely different,
 * real-world concepts that happen to share one English word — a lyric
 * sheet's "(Solo)" is ambiguous out of context in exactly the way English
 * is, and no amount of cleverness in THIS file can resolve that from the
 * word alone.
 *
 * THE DECISION: a bare "SOLO" marker is ALWAYS proposed as the `soloist`
 * VOICE part — never silently reinterpreted as a section, because deciding
 * what is or isn't a structural section is `tblSongPartTypes`'s job, a
 * completely different vocabulary this file must never write to (rule
 * #22) — but the (not-yet-written, commit 5+) detector MUST force
 * `Confidence = 'low'` on it, because only a curator's eyes can tell
 * "(Solo)" the instrumental break from "(Solo)" the one-singer cue apart;
 * no amount of context-sniffing in code can promise that. This is neither
 * "treat it as a section" (wrong system entirely) nor "treat it exactly
 * like any other unambiguous marker" (overclaims a certainty plain text
 * can't back up) — it is the third option: an ambiguous, always-forced-
 * low-confidence suggestion that only ever reaches the review queue,
 * consistent with this whole feature's "heuristics only ever SUGGEST,
 * never auto-apply" rule. `IHYMNS_VOCAL_AMBIGUOUS_SECTION_MARKERS` below
 * is the ONE list a future detector consults to force that low
 * confidence — the SAME list `tests/php/test-vocal-parts-vocab.php`'s
 * marker/section-word collision check treats as its allowed-collision
 * allowance, so "these words are ambiguous" lives in ONE place, not a
 * production constant plus a separately hand-typed test list that could
 * drift apart (rule #35). See that test's doc-block for the collision
 * check itself.
 */
const IHYMNS_VOCAL_PART_KINDS = [
    'lead' => [
        'label' => 'Lead', 'description' => 'The main voice.',
        'gender' => null, 'markers' => ['LEAD' => null, 'MELODY' => null],
        'openlyrics' => 'lead', 'ttmlAgent' => 'person',
    ],
    'soloist' => [
        'label' => 'Soloist', 'description' => 'One singer on their own.',
        'gender' => null, 'markers' => ['SOLO' => 'Solo', 'SOLOIST' => null],
        'openlyrics' => 'solo', 'ttmlAgent' => 'person',
    ],
    'named-singer' => [
        'label' => 'Named singer', 'description' => 'A particular person — pick the musician or type their name.',
        'gender' => null, 'markers' => [],
        'openlyrics' => null, 'ttmlAgent' => 'person',
    ],
    'male' => [
        'label' => 'Men', 'description' => 'The men sing.',
        'gender' => 'male', 'markers' => [
            'MEN' => null, 'MAN' => null, 'GENTLEMEN' => null, 'MALE' => null, 'MALES' => null, 'BOYS' => 'Boys',
        ],
        'openlyrics' => 'men', 'ttmlAgent' => 'group',
    ],
    'female' => [
        'label' => 'Women', 'description' => 'The women sing.',
        'gender' => 'female', 'markers' => [
            'WOMEN' => null, 'WOMAN' => null, 'LADIES' => null, 'FEMALE' => null, 'FEMALES' => null, 'GIRLS' => 'Girls',
        ],
        'openlyrics' => 'women', 'ttmlAgent' => 'group',
    ],
    'children' => [
        'label' => 'Children', 'description' => 'The children sing.',
        'gender' => null, 'markers' => [
            'CHILDREN' => null, 'KIDS' => null, 'YOUTH' => 'Youth', 'JUNIORS' => 'Juniors',
        ],
        'openlyrics' => 'children', 'ttmlAgent' => 'group',
    ],
    'all' => [
        'label' => 'All', 'description' => 'Everyone sings.',
        'gender' => null, 'markers' => [
            'ALL' => null, 'EVERYONE' => null, 'EVERYBODY' => null, 'TOGETHER' => null, 'TUTTI' => null, 'BOTH' => 'Both',
        ],
        'openlyrics' => 'all', 'ttmlAgent' => 'group',
    ],
    'unison' => [
        'label' => 'Unison', 'description' => 'Everyone on the melody, no harmony.',
        'gender' => null, 'markers' => ['UNISON' => null],
        'openlyrics' => 'unison', 'ttmlAgent' => 'group',
    ],
    'duet' => [
        'label' => 'Duet', 'description' => 'Two voices together, treated as one part (TTML sources do this).',
        'gender' => null, 'markers' => ['DUET' => null],
        'openlyrics' => 'duet', 'ttmlAgent' => 'other',
    ],
    'group' => [
        'label' => 'Group', 'description' => 'A numbered or named group — "Group 2", "Left side".',
        'gender' => null, 'markers' => ['GROUP' => null],
        'openlyrics' => 'group', 'ttmlAgent' => 'group',
    ],
    'choir' => [
        'label' => 'Choir', 'description' => 'The choir.',
        'gender' => null, 'markers' => ['CHOIR' => null],
        'openlyrics' => 'choir', 'ttmlAgent' => 'group',
    ],
    'congregation' => [
        'label' => 'Congregation', 'description' => 'The people / the congregation.',
        'gender' => null, 'markers' => [
            'CONGREGATION' => null, 'PEOPLE' => 'People', 'ASSEMBLY' => 'Assembly', 'RESPONSE' => 'Response',
        ],
        'openlyrics' => 'congregation', 'ttmlAgent' => 'group',
    ],
    'cantor' => [
        'label' => 'Cantor', 'description' => 'The leader or cantor line.',
        'gender' => null, 'markers' => [
            'CANTOR' => null, 'LEADER' => 'Leader', 'MINISTER' => 'Minister', 'PRIEST' => 'Priest', 'CELEBRANT' => 'Celebrant',
        ],
        'openlyrics' => 'cantor', 'ttmlAgent' => 'person',
    ],
    'descant' => [
        'label' => 'Descant', 'description' => 'A high line sung above the melody.',
        'gender' => null, 'markers' => ['DESCANT' => null],
        'openlyrics' => 'descant', 'ttmlAgent' => 'group',
    ],
    'soprano' => [
        'label' => 'Soprano', 'description' => 'Choir section: soprano.',
        'gender' => null, 'markers' => ['SOPRANO' => null, 'SOPRANOS' => null],
        'openlyrics' => 'soprano', 'ttmlAgent' => 'group',
    ],
    'alto' => [
        'label' => 'Alto', 'description' => 'Choir section: alto.',
        'gender' => null, 'markers' => ['ALTO' => null, 'ALTOS' => null],
        'openlyrics' => 'alto', 'ttmlAgent' => 'group',
    ],
    'tenor' => [
        'label' => 'Tenor', 'description' => 'Choir section: tenor.',
        'gender' => null, 'markers' => ['TENOR' => null, 'TENORS' => null],
        'openlyrics' => 'tenor', 'ttmlAgent' => 'group',
    ],
    'bass' => [
        'label' => 'Bass', 'description' => 'Choir section: bass.',
        'gender' => null, 'markers' => ['BASS' => null, 'BASSES' => null],
        'openlyrics' => 'bass', 'ttmlAgent' => 'group',
    ],
    'backing' => [
        'label' => 'Backing', 'description' => 'Background or echo voices.',
        'gender' => null, 'markers' => [
            'ECHO' => 'Echo', 'BACKING' => null, 'BACKING VOCALS' => null, 'BGV' => null,
        ],
        'openlyrics' => 'backing', 'ttmlAgent' => 'group',
    ],
    'narrator' => [
        'label' => 'Narrator', 'description' => 'Spoken narration between or over the singing.',
        'gender' => null, 'markers' => ['NARRATOR' => null, 'READER' => 'Reader'],
        'openlyrics' => 'narrator', 'ttmlAgent' => 'person',
    ],
    'spoken' => [
        'label' => 'Spoken', 'description' => 'Spoken, not sung.',
        'gender' => null, 'markers' => ['SPOKEN' => null],
        'openlyrics' => 'spoken', 'ttmlAgent' => 'person',
    ],
];

/**
 * ELI5: other spellings that mean one of the 21 kinds above (see IHYMNS_VOCAL_PART_KINDS's own doc-block for a note on the plan's "22" miscount).
 *
 * Detail: lower-case, matched after `trim()`, by `vocalPartsNormalizeKind()`.
 * 'main' is the pre-#2073 schema COMMENT's legacy name for `lead`
 * (`tblVocalParts.PartKind`'s COMMENT, #1137, still lists `main`). The
 * marker words on the map above are ALSO accepted here (upper-cased) by
 * `vocalPartsNormalizeKind()`'s own fallback step — this list only needs
 * to hold spellings that are NOT already one of those markers: an
 * ingest-only shorthand (`x-bg`, TTML's `ttm:role` value for a background
 * group), a couple of choir-section abbreviations, and a few words a
 * structured importer (TTML `type="…"`, OpenLyrics `part="…"`) might hand
 * this file directly in lower case.
 */
const IHYMNS_VOCAL_PART_KIND_ALIASES = [
    'main' => 'lead',
    'background' => 'backing', 'bg' => 'backing', 'x-bg' => 'backing',
    'singer' => 'named-singer', 'person' => 'named-singer',
    'kid' => 'children', 'child' => 'children',
    'everybody' => 'all',
    'sop' => 'soprano', 'ten' => 'tenor',
];

/** The three genders a vocal part's `Gender` column may carry — an
 *  orthogonal axis to `PartKind` (a named soloist may also be female). */
const IHYMNS_VOCAL_GENDERS = ['male', 'female', 'neutral'];

/**
 * ELI5: marker words that are genuinely ambiguous with a real
 * `tblSongPartTypes` section name — today just "SOLO" (see the "SOLO" note
 * on `IHYMNS_VOCAL_PART_KINDS` above for the full reasoning). A detector
 * finding one of these words MUST still propose the voice-part kind (never
 * silently propose a section instead — a different vocabulary entirely)
 * but MUST force `Confidence = 'low'` on the suggestion, because only a
 * curator can tell "(Solo)" the instrumental break from "(Solo)" the
 * one-singer cue apart.
 *
 * `tests/php/test-vocal-parts-vocab.php` asserts every entry here is a
 * REAL marker word somewhere in `IHYMNS_VOCAL_PART_KINDS`, and reuses this
 * EXACT list as its own marker/section-word collision allowance — ONE
 * list, not two copies (a production constant and a separately hand-typed
 * test array) that could silently drift apart (rule #35).
 */
const IHYMNS_VOCAL_AMBIGUOUS_SECTION_MARKERS = ['SOLO'];

/**
 * Sources whose voice signal is a STRUCTURED, machine-owned fact applied
 * directly on import — as opposed to a plain-text heuristic (a detector
 * guessing from a typed word) that a later commit routes to a curator
 * review queue instead of writing straight to the tables. Mirrors the
 * values `tblLyrics.Source` / `tblVocalParts.Source` actually carry.
 * `'import-marker'` is included per the #2073 "Design pass 7" synthesis
 * (its §1 contradiction-resolution table, item C6) even though nothing in
 * this commit writes that source value yet — a later importer commit does.
 */
const IHYMNS_VOCAL_SOURCES_STRUCTURED = ['applemusic-ttml', 'openlyrics', 'propresenter7', 'import-marker'];

/**
 * ELI5: recognise a numbered or named "side" — "Group 2", "Voice II",
 * "2nd", "Third Group", "Left", "Right side" — as the `group` kind.
 *
 * WHY THIS PATTERN DIFFERS FROM BOTH EARLIER DESIGN PASSES (flagged loudly,
 * per this commit's own instructions, rather than silently picking one):
 * "Design pass 2" §1.2 wrote a narrower pattern that only recognised a
 * BARE ordinal ("2ND", "THIRD" — optionally followed by the word GROUP,
 * never preceded by one) — it never matches a "GROUP "-prefixed form at
 * all beyond the literal digit/word case its own alternation covered.
 * "Design pass 7" (the plan's synthesis, authoritative wherever the passes
 * disagree — see this file's own commit-scope note above) replaced it with
 * a DIFFERENT pattern that requires a `GROUP|VOICE|PART|SIDE` PREFIX before
 * the token, adding Roman numerals and `LEFT`/`RIGHT`, but that pattern, as
 * written, can no longer match a bare "2ND" or "THIRD" on its own — the
 * exact case Pass 2's own test list (§9.1) says `vocalPartsKindFromWord()`
 * must resolve. Neither pass's regex alone satisfies BOTH the bare-ordinal
 * case Pass 2 tested for AND the richer prefixed/Roman/side vocabulary
 * Pass 7 added. This constant therefore MERGES both shapes rather than
 * regressing one of them: a prefixed form (`(?<tok1>...)`), a bare
 * digit-ordinal form (`(?<tok2>...)`, Pass 2's own shape), a bare
 * word-ordinal form (`(?<tok3>...)`), and a bare LEFT/RIGHT[/SIDE] form
 * (`(?<tok4>...)`, since the `group` kind's own description — "Left
 * side" — reads far more naturally as the marker word on its own than as
 * "GROUP LEFT"). Four distinct group names (`tok1..tok4`), not one reused
 * name, because PCRE2 rejects a duplicate named subpattern across
 * alternatives without the `(?J)` modifier this file does not otherwise
 * need — `vocalPartsGroupOrdinalLabel()` below reads whichever one fired.
 *
 * @see https://www.pcre.org/current/doc/html/pcre2syntax.html#SEC5   PCRE2 named subpatterns
 */
const IHYMNS_VOCAL_GROUP_ORDINAL_RE =
      '/^(?:(?:GROUP|VOICE|PART|SIDE)\s*(?<tok1>[0-9]{1,2}|[IVX]{1,4}|ONE|TWO|THREE|FOUR|FIRST|SECOND|THIRD|FOURTH|LEFT|RIGHT)'
    . '|(?<tok2>[0-9]{1,2})(?:ST|ND|RD|TH)(?:\s+GROUP)?'
    . '|(?<tok3>FIRST|SECOND|THIRD|FOURTH)(?:\s+GROUP)?'
    . '|(?<tok4>LEFT|RIGHT)(?:\s+SIDE)?'
    . ')$/u';

/**
 * The top-level keys the (not-yet-written, commit 5) bulk read payload
 * `vocalPartsForSong()` will return. Declared here — dormant, referenced by
 * nothing in this commit — so a later PHP<->JS lockstep guard (the plan's
 * "G6") has ONE place to read the contract from on both sides, rather than
 * a JS file typing the same eight words a second time (rule #35: agreement
 * needs a mechanism, not a comment saying "keep these in sync").
 */
const VOCAL_PARTS_PAYLOAD_KEYS = [
    'ready', 'spansReady', 'roundsReady', 'lyricsId', 'parts', 'lineAssignments', 'spans', 'rounds',
];

/* =====================================================================
 * NORMALISERS — pure, no DB. Turn caller-supplied text into a canonical
 * kind key (or null), never minting a new one.
 * ===================================================================== */

/**
 * ELI5: turn whatever text a caller typed into one of the 21 kind keys
 * above, or say "I don't recognise that."
 *
 * Detail: `strtolower(trim())`; a hit against `IHYMNS_VOCAL_PART_KINDS`'s
 * own keys wins first, then `IHYMNS_VOCAL_PART_KIND_ALIASES`, then the kind
 * whose `markers` list contains the UPPER-CASED input (so "men" resolves to
 * `male` even though "men" is not itself a map key or a typed alias — it is
 * one of `male`'s marker words). Returns null rather than guessing.
 */
function vocalPartsNormalizeKind(string $kind): ?string
{
    $v = strtolower(trim($kind));
    if ($v === '') {
        return null;
    }
    if (isset(IHYMNS_VOCAL_PART_KINDS[$v])) {
        return $v;
    }
    if (isset(IHYMNS_VOCAL_PART_KIND_ALIASES[$v])) {
        return IHYMNS_VOCAL_PART_KIND_ALIASES[$v];
    }
    $upper = mb_strtoupper($v, 'UTF-8');
    foreach (IHYMNS_VOCAL_PART_KINDS as $key => $def) {
        if (array_key_exists($upper, $def['markers'])) {
            return $key;
        }
    }
    return null;
}

/**
 * ELI5: the ONE place a cue word like "MEN", "Group 2" or "ladies:" becomes
 * a kind plus a proposed display label. Every other place in the app that
 * needs to read a voice cue out of plain text (a future detector, an
 * OpenLyrics `part=` importer, an API normaliser) calls THIS function
 * rather than keeping its own word list — that is what makes an export
 * marker and an import marker agree with each other (rule #35's "a
 * mechanism, not a comment").
 *
 * Detail: fold = `mb_strtoupper(trim())`; collapse runs of space / a
 * non-breaking space / tab into one plain space (real hymnal text pastes
 * in from PDF/Word sources routinely carry `\x{00A0}` where a human sees
 * an ordinary space); strip one trailing colon. Resolution order:
 *   (a) an EXACT hit in some kind's `markers` list — returns that kind and
 *       the marker's own override label (`null` = "use the kind's label").
 *   (b) `IHYMNS_VOCAL_GROUP_ORDINAL_RE` — returns `group` with a label
 *       built by `vocalPartsGroupOrdinalLabel()`.
 *   (c) `vocalPartsNormalizeKind()` (its alias step; its OWN marker
 *       fallback is redundant with (a) at this point but harmless) — with
 *       no override label.
 * `null` when none of the three resolve — the caller must NOT invent a
 * kind for an unrecognised word.
 *
 * @return array{kind:string,label:?string}|null
 */
function vocalPartsKindFromWord(string $word): ?array
{
    $folded = mb_strtoupper(trim($word), 'UTF-8');
    if ($folded === '') {
        return null;
    }
    $folded = preg_replace('/[ .\x{00A0}\t]+/u', ' ', $folded) ?? $folded;
    $folded = rtrim(trim($folded), ':');
    $folded = trim($folded);
    if ($folded === '') {
        return null;
    }

    /* (a) exact marker hit — walk the map in its own declared order so a
       word that (in error) appeared under two kinds would resolve to the
       FIRST, never both; test-vocal-parts-vocab.php separately asserts no
       such duplicate exists at all. */
    foreach (IHYMNS_VOCAL_PART_KINDS as $key => $def) {
        if (array_key_exists($folded, $def['markers'])) {
            return ['kind' => $key, 'label' => $def['markers'][$folded]];
        }
    }

    /* (b) a numbered/named group or side. */
    if (preg_match(IHYMNS_VOCAL_GROUP_ORDINAL_RE, $folded, $m)) {
        $token = '';
        foreach (['tok1', 'tok2', 'tok3', 'tok4'] as $g) {
            if (!empty($m[$g])) {
                $token = $m[$g];
                break;
            }
        }
        return ['kind' => 'group', 'label' => vocalPartsGroupOrdinalLabel($token)];
    }

    /* (c) alias fold (also catches a bare kind key typed upper-case). */
    $norm = vocalPartsNormalizeKind($folded);
    if ($norm !== null) {
        return ['kind' => $norm, 'label' => null];
    }

    return null;
}

/**
 * ELI5: turn the captured ordinal token from `IHYMNS_VOCAL_GROUP_ORDINAL_RE`
 * ("2", "II", "THIRD", "LEFT", …) into the display label a curator sees
 * ("Group 2", "Group 3", "Left side"). PURE — no DB.
 *
 * Detail: this mapping is this file's own resolution of a gap neither
 * design pass fully specified for the merged pattern above (see that
 * constant's doc-block) — a small Roman-numeral reader (I/V/X only, which
 * is all the source regex can ever capture) plus a plain word/number map.
 * Anything the two maps below don't recognise falls back to echoing the
 * token verbatim after "Group " rather than silently dropping it.
 */
function vocalPartsGroupOrdinalLabel(string $token): string
{
    $upper = strtoupper(trim($token));
    if ($upper === 'LEFT' || $upper === 'RIGHT') {
        return ucfirst(strtolower($upper)) . ' side';
    }
    $ordinalWords = [
        'ONE' => 1, 'FIRST' => 1, 'TWO' => 2, 'SECOND' => 2,
        'THREE' => 3, 'THIRD' => 3, 'FOUR' => 4, 'FOURTH' => 4,
    ];
    if (isset($ordinalWords[$upper])) {
        return 'Group ' . $ordinalWords[$upper];
    }
    if (preg_match('/^[0-9]{1,2}$/', $upper)) {
        return 'Group ' . $upper;
    }
    if (preg_match('/^[IVX]{1,4}$/', $upper)) {
        $n = vocalPartsRomanToInt($upper);
        return 'Group ' . ($n > 0 ? (string)$n : $upper);
    }
    return 'Group ' . $upper;
}

/**
 * A minimal I/V/X-only Roman-numeral reader — the source regex can only
 * ever capture 1-4 characters drawn from those three letters, so this
 * never needs to understand L/C/D/M. Malformed input (e.g. "IIX") is not
 * rejected — it degrades to whatever the subtractive-pair scan produces,
 * which is always used only for a cosmetic display label, never stored.
 *
 * @see https://en.wikipedia.org/wiki/Roman_numerals#Standard_form
 */
function vocalPartsRomanToInt(string $roman): int
{
    $values = ['I' => 1, 'V' => 5, 'X' => 10];
    $total = 0;
    $prev = 0;
    for ($i = strlen($roman) - 1; $i >= 0; $i--) {
        $val = $values[$roman[$i]] ?? 0;
        if ($val < $prev) {
            $total -= $val;
        } else {
            $total += $val;
            $prev = $val;
        }
    }
    return $total;
}

/**
 * The gender a kind IMPLIES (rule #44 — derived, never a second hand-set
 * flag on the row) — null for every kind that carries no gender
 * implication (which is most of them; only `male`/`female` imply one).
 */
function vocalPartsImpliedGender(string $kind): ?string
{
    $norm = vocalPartsNormalizeKind($kind);
    if ($norm === null) {
        return null;
    }
    return IHYMNS_VOCAL_PART_KINDS[$norm]['gender'] ?? null;
}

/**
 * ELI5: "is this exact marker word one that could ALSO be a section
 * name?" — the one check a future detector calls before deciding how
 * confident to be about a suggestion (see
 * `IHYMNS_VOCAL_AMBIGUOUS_SECTION_MARKERS`'s own doc-block for the policy
 * this exists to let the detector follow: propose the voice part, but
 * force low confidence). Case/whitespace-folded the same way
 * `vocalPartsKindFromWord()` folds its input, so a caller can pass the
 * raw, un-folded marker text straight through.
 */
function vocalPartsMarkerIsAmbiguousWithSection(string $marker): bool
{
    $folded = mb_strtoupper(trim($marker), 'UTF-8');
    return in_array($folded, IHYMNS_VOCAL_AMBIGUOUS_SECTION_MARKERS, true);
}

/* =====================================================================
 * SHAPE BUILDERS — pure. Turn a raw `tblVocalParts` row (or the camelCase
 * shape this file itself produces) into the wire/display shapes callers
 * use, and project the vocabulary for a client that must never type its
 * own copy of it (rule #35).
 * ===================================================================== */

/**
 * Read one field off either a raw DB row (PascalCase column names) or an
 * already-shaped array (camelCase keys) — every shape builder below
 * accepts both so a caller mid-transition between the two never has to
 * pre-convert. Not exported as part of this file's public vocabulary.
 */
function _vocalPartsField(array $row, string $pascal, string $camel, mixed $default = null): mixed
{
    if (array_key_exists($pascal, $row)) {
        return $row[$pascal];
    }
    if (array_key_exists($camel, $row)) {
        return $row[$camel];
    }
    return $default;
}

/**
 * ELI5: what should we actually print for this part — its own custom
 * label, then a typed/registry singer name, then the kind's generic word?
 *
 * Detail: `Label ?? SingerName ?? $musicianName ?? KINDS[kind]['label']`
 * (rule #45's Type-vs-Label precedent: an explicit override always wins
 * over the structural kind). `$musicianName` is passed in by the caller
 * (a JOIN result) rather than looked up here, so this stays a pure,
 * DB-free function.
 */
function vocalPartsDisplayLabel(array $row, ?string $musicianName = null): string
{
    $label = trim((string)(_vocalPartsField($row, 'Label', 'label', '') ?? ''));
    if ($label !== '') {
        return $label;
    }
    $singer = trim((string)(_vocalPartsField($row, 'SingerName', 'singerName', '') ?? ''));
    if ($singer !== '') {
        return $singer;
    }
    if ($musicianName !== null && trim($musicianName) !== '') {
        return trim($musicianName);
    }
    $kindRaw = (string)(_vocalPartsField($row, 'PartKind', 'kind', ''));
    $norm = vocalPartsNormalizeKind($kindRaw);
    if ($norm !== null) {
        return IHYMNS_VOCAL_PART_KINDS[$norm]['label'];
    }
    return $kindRaw !== '' ? ucfirst($kindRaw) : 'Part';
}

/**
 * The editor/run-derivation shape (rule #2073 "Design pass 7" contradiction
 * C8: run parts use `kind`; the pre-existing PUBLIC `?include=vocalParts`
 * API block — untouched by this commit — keeps its own long-shipped
 * `partKind` key name for backward compatibility). Every key is ALWAYS
 * present (the editor-shape convention line_enrichment.php's own shape
 * functions use) so a consumer never has to guess whether an absent key
 * means "unset" or "not fetched".
 *
 * @param array<string,mixed> $r             a raw tblVocalParts row (or an
 *                                            already-shaped array — see
 *                                            `_vocalPartsField()`)
 * @param string|null         $musicianName  a joined `tblMusicians.Name`,
 *                                            when the caller has one
 * @return array{id:int,kind:string,label:?string,displayLabel:string,
 *               singerName:?string,gender:?string,musicianId:?int,
 *               ttmlAgentId:?string,source:string,sortOrder:int}
 */
function vocalPartsShape(array $r, ?string $musicianName = null): array
{
    $label = _vocalPartsField($r, 'Label', 'label');
    $label = ($label !== null && trim((string)$label) !== '') ? trim((string)$label) : null;

    $singerName = _vocalPartsField($r, 'SingerName', 'singerName');
    $singerName = ($singerName !== null && trim((string)$singerName) !== '') ? trim((string)$singerName) : null;

    $musicianId = _vocalPartsField($r, 'MusicianId', 'musicianId');
    $ttmlAgentId = _vocalPartsField($r, 'TtmlAgentId', 'ttmlAgentId');

    return [
        'id'          => (int)_vocalPartsField($r, 'Id', 'id', 0),
        'kind'        => (string)_vocalPartsField($r, 'PartKind', 'kind', ''),
        'label'       => $label,
        'displayLabel' => vocalPartsDisplayLabel($r, $musicianName),
        'singerName'  => $singerName,
        'gender'      => _vocalPartsField($r, 'Gender', 'gender'),
        'musicianId'  => $musicianId !== null ? (int)$musicianId : null,
        'ttmlAgentId' => $ttmlAgentId !== null && (string)$ttmlAgentId !== '' ? (string)$ttmlAgentId : null,
        'source'      => (string)_vocalPartsField($r, 'Source', 'source', 'ihymns'),
        'sortOrder'   => (int)_vocalPartsField($r, 'SortOrder', 'sortOrder', 0),
    ];
}

/**
 * What `load_song` / a future `vocal_part_kinds` API action serve so no
 * client ever types its own copy of the vocabulary (rule #35) — one entry
 * per kind, IN MAP ORDER (the picker order), each carrying only what a
 * picker UI needs: the key, its label + help text, its implied gender, and
 * its own canonical marker word (or null for `named-singer`, which has no
 * fixed marker at all — its cue is a name, not a word).
 *
 * @return list<array{key:string,label:string,description:string,gender:?string,marker:?string}>
 */
function vocalPartsKindsProjection(): array
{
    $out = [];
    foreach (IHYMNS_VOCAL_PART_KINDS as $key => $def) {
        $markerKeys = array_keys($def['markers']);
        $out[] = [
            'key'         => $key,
            'label'       => $def['label'],
            'description' => $def['description'],
            'gender'      => $def['gender'],
            'marker'      => $markerKeys[0] ?? null,
        ];
    }
    return $out;
}

/**
 * ELI5: what word should an export write for this part, in this format?
 *
 * Detail: `$format` is `'openlyrics'` or `'marker'` (the canonical
 * UPPER-CASE plain-text word); anything else throws
 * `\InvalidArgumentException`. The keyword is derived from the KIND (plus
 * an ordinal for `group`, plus the singer's name for `named-singer`) —
 * NEVER from a curator's `Label` (the rule #45 analogue: a curator's
 * "Youth" label on a `group` part still exports as `group2` — the
 * STRUCTURE round-trips, the cosmetic label does not). `$versionParts`
 * (a list of `vocalPartsShape()` results for the SAME lyrics version) is
 * only consulted for `group`, to compute this part's 1-based ordinal among
 * that version's OTHER `group` parts by `(sortOrder, id)`.
 */
function vocalPartsExportKeyword(array $part, string $format, array $versionParts = []): string
{
    if ($format !== 'openlyrics' && $format !== 'marker') {
        throw new \InvalidArgumentException("Unknown vocal-part export format '{$format}'.");
    }
    $kind = (string)($part['kind'] ?? '');
    $def = IHYMNS_VOCAL_PART_KINDS[$kind] ?? null;
    if ($def === null) {
        throw new \InvalidArgumentException("Unknown vocal-part kind '{$kind}'.");
    }

    if ($kind === 'named-singer') {
        $name = trim((string)($part['singerName'] ?? '')) ?: trim((string)($part['displayLabel'] ?? ''));
        if ($format === 'marker') {
            return $name !== '' ? mb_strtoupper($name, 'UTF-8') : 'SOLO';
        }
        return $name !== '' ? $name : 'solo';
    }

    if ($kind === 'group') {
        $siblings = array_values(array_filter(
            $versionParts,
            static fn(array $p): bool => ($p['kind'] ?? null) === 'group'
        ));
        usort($siblings, static function (array $a, array $b): int {
            $bySort = ($a['sortOrder'] ?? 0) <=> ($b['sortOrder'] ?? 0);
            return $bySort !== 0 ? $bySort : (($a['id'] ?? 0) <=> ($b['id'] ?? 0));
        });
        $ordinal = 1;
        foreach ($siblings as $i => $sibling) {
            if (($sibling['id'] ?? null) !== null && $sibling['id'] === ($part['id'] ?? null)) {
                $ordinal = $i + 1;
                break;
            }
        }
        return $format === 'marker' ? "GROUP {$ordinal}" : "group{$ordinal}";
    }

    if ($format === 'marker') {
        $markerKeys = array_keys($def['markers']);
        return $markerKeys[0] ?? mb_strtoupper($kind, 'UTF-8');
    }

    return $def['openlyrics'] ?? $kind;
}

/**
 * The `<ttm:agent id="…" type="…"><ttm:name>…</ttm:name></ttm:agent>` triple
 * a future TTML exporter (out of this commit's scope) would write for one
 * part. `$index` is this part's 0-based position among the version's OTHER
 * parts by `(sortOrder, id)` — the caller supplies it (this function stays
 * pure) so the fallback id `v1`, `v2`, … is stable across exports as long
 * as sort order doesn't change.
 *
 * @return array{id:string,type:string,name:string}
 */
function vocalPartsTtmlAgent(array $part, int $index): array
{
    $kind = (string)($part['kind'] ?? '');
    $def = IHYMNS_VOCAL_PART_KINDS[$kind] ?? null;
    $type = $def['ttmlAgent'] ?? 'person';
    $agentId = (string)($part['ttmlAgentId'] ?? '');
    $id = $agentId !== '' ? $agentId : ('v' . ($index + 1));
    $name = (string)($part['displayLabel'] ?? vocalPartsDisplayLabel($part));
    return ['id' => $id, 'type' => $type, 'name' => $name];
}

/* =====================================================================
 * SCHEMA READINESS — memoised per request (rule #19: migrations are not
 * auto-applied, so a fresh request must re-probe after one is applied
 * mid-deploy). Catch posture mirrors `lyricLinesMirrorPresent()` /
 * `lyricLinesComponentExtrasPresent()` in lyric_lines_read.php: a
 * transaction-fatal error (a deadlock/lock-wait mid-save) must propagate,
 * never be swallowed as "not ready", because that would let a caller
 * commit nothing while still reporting success. `song_relocate.php` (home
 * of `songRelocateIsTransactionFatal()`) is not loaded on every path that
 * reaches these probes, hence the `function_exists()` guard — no live
 * write transaction depends on the answer when it isn't loaded at all.
 * ===================================================================== */

/**
 * Is the #1137 vocal-parts trio fully present, INCLUDING the #2077
 * post-rename shape (`tblVocalParts.MusicianId`, not the pre-#1741-rename
 * person-id column it replaced)? An install that ran the vocal-parts
 * migration before the musicians rename would have the tables but the
 * WRONG person column,
 * and a bare `MusicianId` reference elsewhere in a future write commit
 * would throw under `MYSQLI_REPORT_STRICT` on such an install — checking
 * the column, not just table existence, is what keeps that a graceful
 * "not ready" instead of a fatal.
 */
function vocalPartsTablesReady(\mysqli $db): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        $r = $db->query(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME IN ('tblVocalParts', 'tblLyricLineVocalParts', 'tblLyricWordVocalParts')"
        );
        $row = $r ? $r->fetch_row() : null;
        $tablesOk = ($row !== null && (int)$row[0] >= 3);
        if ($r) {
            $r->close();
        }

        $columnOk = false;
        if ($tablesOk) {
            $rc = $db->query(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME   = 'tblVocalParts'
                    AND COLUMN_NAME  = 'MusicianId'"
            );
            $rowC = $rc ? $rc->fetch_row() : null;
            $columnOk = ($rowC !== null && (int)$rowC[0] === 1);
            if ($rc) {
                $rc->close();
            }
        }
        $ready = $tablesOk && $columnOk;
    } catch (\Throwable $_e) {
        if (function_exists('songRelocateIsTransactionFatal') && songRelocateIsTransactionFatal($_e)) {
            throw $_e;
        }
        $ready = false;
    }
    return $ready;
}

/**
 * Is `tblLyricLineVocalSpans` (the sub-line echo table #2073 commit 2's
 * migration creates) present? Independent of `vocalPartsTablesReady()` —
 * an install can have the #1137 trio without yet having run commit 2's
 * migration, and every span-reading function below degrades to an empty
 * result rather than throwing on the missing table.
 */
function vocalPartsSpansReady(\mysqli $db): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        $r = $db->query(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblLyricLineVocalSpans'"
        );
        $row = $r ? $r->fetch_row() : null;
        $ready = ($row !== null && (int)$row[0] >= 1);
        if ($r) {
            $r->close();
        }
    } catch (\Throwable $_e) {
        if (function_exists('songRelocateIsTransactionFatal') && songRelocateIsTransactionFatal($_e)) {
            throw $_e;
        }
        $ready = false;
    }
    return $ready;
}

/* =====================================================================
 * OWNERSHIP RESOLVERS + READ FETCHERS — SELECT only. No INSERT / UPDATE /
 * DELETE anywhere below this line (commit scope — see file header).
 * ===================================================================== */

/**
 * ELI5: prove every line id a caller named really belongs to THIS song's
 * one editable ("ihymns") lyrics version, in one query — an IDOR guard a
 * future write commit reuses so a caller can never enrich another song's
 * lines (or another VERSION of the same song's lines) by guessing an id.
 *
 * Detail: de-duplicates + validates positive ints (else
 * `\InvalidArgumentException`), caps at 500 ids per call (the
 * `LYRIC_LINES_READ_CHUNK` order of magnitude used elsewhere in this
 * codebase). A count mismatch after the JOIN, or any resolved line whose
 * `LyricsId` is not the song's primary ("ihymns") version, throws
 * `\RuntimeException` — never revealing WHICH id was the problem, so a
 * caller can't use the difference to enumerate another song's line ids.
 *
 * @param list<int> $lineIds
 * @return array<int,array{lyricsId:int,text:string,cpLen:int,sortOrder:int,componentId:?int}>
 */
function vocalPartsResolveLines(\mysqli $db, string $songId, array $lineIds): array
{
    $ids = array_values(array_unique(array_map('intval', $lineIds)));
    foreach ($ids as $id) {
        if ($id <= 0) {
            throw new \InvalidArgumentException('lineIds must be positive integers.');
        }
    }
    if (!$ids) {
        throw new \InvalidArgumentException('lineIds must be positive integers.');
    }
    if (count($ids) > 500) {
        throw new \InvalidArgumentException('lineIds must not exceed 500 per call.');
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare(
        "SELECT ll.Id AS Id, ll.LyricsId AS LyricsId, ll.LineText AS LineText,
                ll.SortOrder AS SortOrder, ll.ComponentId AS ComponentId
           FROM tblLyricLines ll
           JOIN tblLyrics ly ON ly.Id = ll.LyricsId
          WHERE ll.Id IN ($placeholders) AND ly.SongId = ?"
    );
    $types = str_repeat('i', count($ids)) . 's';
    $params = $ids;
    $params[] = $songId;
    bindParamSafe(__FUNCTION__, $stmt, $types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[(int)$row['Id']] = $row;
    }
    $stmt->close();

    if (count($rows) !== count($ids)) {
        throw new \RuntimeException('One or more lineIds do not belong to this song.');
    }

    /* @lyrics-version-cache-ok: a plain read outside any transaction — this
       function runs no begin_transaction() of its own (it only SELECTs,
       both here and above), so a "found" answer cached from an earlier
       call in the same request cannot be invalidated by a rollback the
       way lyricLinesEnsurePrimaryVersion()'s find-or-create must guard
       against (see lyricLinesPrimaryLyricsId()'s own "WHY A FOUND ROW..."
       doc-block in lyric_lines_read.php for the read-vs-write distinction
       this marker exists to let a reviewer confirm). */
    $primaryLyricsId = lyricLinesPrimaryLyricsId($db, $songId);
    $out = [];
    foreach ($ids as $id) {
        $row = $rows[$id];
        $lyricsId = (int)$row['LyricsId'];
        if ($primaryLyricsId <= 0 || $lyricsId !== $primaryLyricsId) {
            throw new \RuntimeException('Voice parts are edited on the primary lyrics version only.');
        }
        $text = (string)$row['LineText'];
        $out[$id] = [
            'lyricsId'    => $lyricsId,
            'text'        => $text,
            'cpLen'       => mb_strlen($text, 'UTF-8'),
            'sortOrder'   => (int)$row['SortOrder'],
            'componentId' => $row['ComponentId'] !== null ? (int)$row['ComponentId'] : null,
        ];
    }
    return $out;
}

/**
 * Resolve one `tblVocalParts.Id` to its raw row, ENFORCING that it belongs
 * to a lyrics version of `$songId` — null when it doesn't exist or belongs
 * to a different song (the caller maps null -> 404, never distinguishing
 * "absent" from "not yours").
 */
function vocalPartsResolvePart(\mysqli $db, string $songId, int $partId): ?array
{
    /* @lyrics-version-exempt: deliberately does NOT filter by
       `Source = 'ihymns'` — a specific $partId is already known, so this
       only needs to confirm the PART's own lyrics version belongs to
       $songId at all (the ownership/IDOR check this function exists for),
       never deciding which version is the song's "current" one. It is
       therefore not a version-resolution site in the sense
       lyricLinesPrimaryLyricsId() is, exactly like
       lineEnrichmentResolveLine()'s own identical exemption in
       line_enrichment.php. */
    $stmt = $db->prepare(
        "SELECT vp.* FROM tblVocalParts vp
           JOIN tblLyrics ly ON ly.Id = vp.LyricsId
          WHERE vp.Id = ? AND ly.SongId = ?
          LIMIT 1"
    );
    bindParamSafe(__FUNCTION__, $stmt, 'is', $partId, $songId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/**
 * Every vocal part registered on one lyrics version, shaped + ordered for
 * a picker (`ORDER BY SortOrder, Id`), with a `named-singer`'s registry
 * name already joined in.
 *
 * @return list<array{id:int,kind:string,label:?string,displayLabel:string,singerName:?string,gender:?string,musicianId:?int,ttmlAgentId:?string,source:string,sortOrder:int}>
 */
function vocalPartsForVersion(\mysqli $db, int $lyricsId): array
{
    $stmt = $db->prepare(
        "SELECT vp.*, m.Name AS musician_name
           FROM tblVocalParts vp
           LEFT JOIN tblMusicians m ON m.Id = vp.MusicianId
          WHERE vp.LyricsId = ?
          ORDER BY vp.SortOrder, vp.Id"
    );
    bindParamSafe(__FUNCTION__, $stmt, 'i', $lyricsId);
    $stmt->execute();
    $res = $stmt->get_result();
    $out = [];
    while ($row = $res->fetch_assoc()) {
        $musicianName = $row['musician_name'] ?? null;
        unset($row['musician_name']);
        $out[] = vocalPartsShape($row, $musicianName !== null ? (string)$musicianName : null);
    }
    $stmt->close();
    return $out;
}

/**
 * Every LINE-grain part assignment for one lyrics version (optionally
 * narrowed to a specific set of line ids), keyed by line id.
 *
 * @param list<int>|null $onlyLineIds
 * @return array<int,list<array{partId:int,bg:bool,sortOrder:int}>>
 */
function vocalPartsLinesMap(\mysqli $db, int $lyricsId, ?array $onlyLineIds = null): array
{
    $sql = "SELECT LineId, VocalPartId, IsBackground, SortOrder
              FROM tblLyricLineVocalParts
             WHERE LyricsId = ?";
    $types = 'i';
    $params = [$lyricsId];

    if ($onlyLineIds !== null) {
        $ids = array_values(array_unique(array_map('intval', $onlyLineIds)));
        if (!$ids) {
            return [];
        }
        $sql .= ' AND LineId IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        $types .= str_repeat('i', count($ids));
        array_push($params, ...$ids);
    }
    $sql .= ' ORDER BY LineId, SortOrder, VocalPartId';

    $stmt = $db->prepare($sql);
    bindParamSafe(__FUNCTION__, $stmt, $types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $out = [];
    while ($row = $res->fetch_assoc()) {
        $lineId = (int)$row['LineId'];
        $out[$lineId][] = [
            'partId'    => (int)$row['VocalPartId'],
            'bg'        => (bool)$row['IsBackground'],
            'sortOrder' => (int)$row['SortOrder'],
        ];
    }
    $stmt->close();
    return $out;
}

/**
 * Every SUB-LINE echo/voice-switch span for one lyrics version, keyed by
 * line id — `[]` on an install that hasn't run commit 2's migration yet
 * (`vocalPartsSpansReady()` false), never a thrown error.
 *
 * SPAN DRIFT ON EDIT: a lyric edit keeps a line's Id (the Id-preserving
 * diff, rule #25) but may shorten its text. A span whose `end` now exceeds
 * the CURRENT code-point length is CLAMPED here at read time (never
 * rewritten in the table, never thrown) and a span whose `start` no longer
 * fits inside the line at all is dropped from the result entirely. The
 * original bracketed text survives in the row's `MetaJson` for a curator
 * to notice and fix — see `tblLyricLineVocalSpans.MetaJson`'s COMMENT.
 *
 * @return array<int,list<array{id:int,partId:int,start:int,end:int,bg:bool,sortOrder:int}>>
 */
function vocalPartsSpansMap(\mysqli $db, int $lyricsId): array
{
    if (!vocalPartsSpansReady($db)) {
        return [];
    }
    $stmt = $db->prepare(
        "SELECT s.Id AS Id, s.LineId AS LineId, s.VocalPartId AS VocalPartId,
                s.StartOffset AS StartOffset, s.EndOffset AS EndOffset,
                s.IsBackground AS IsBackground, s.SortOrder AS SortOrder,
                ll.LineText AS LineText
           FROM tblLyricLineVocalSpans s
           JOIN tblLyricLines ll ON ll.Id = s.LineId
          WHERE s.LyricsId = ?
          ORDER BY s.LineId, s.StartOffset, s.SortOrder"
    );
    bindParamSafe(__FUNCTION__, $stmt, 'i', $lyricsId);
    $stmt->execute();
    $res = $stmt->get_result();
    $out = [];
    while ($row = $res->fetch_assoc()) {
        $lineId = (int)$row['LineId'];
        $cpLen = mb_strlen((string)$row['LineText'], 'UTF-8');
        $start = (int)$row['StartOffset'];
        if ($start >= $cpLen) {
            continue; // the line shrank past this span's start entirely — drop it
        }
        $end = min((int)$row['EndOffset'], $cpLen);
        $out[$lineId][] = [
            'id'        => (int)$row['Id'],
            'partId'    => (int)$row['VocalPartId'],
            'start'     => $start,
            'end'       => $end,
            'bg'        => (bool)$row['IsBackground'],
            'sortOrder' => (int)$row['SortOrder'],
        ];
    }
    $stmt->close();
    return $out;
}

/**
 * Every WORD-grain part assignment for one lyrics version, keyed by word
 * id. `LyricsId` is read straight off `tblLyricWordVocalParts`'s own
 * denorm column (no join back through `tblLyricWords`/`tblLyricLines`) —
 * exactly the query shape that column exists to make cheap.
 *
 * @return array<int,list<array{partId:int,bg:bool,sortOrder:int}>>
 */
function vocalPartsWordsMap(\mysqli $db, int $lyricsId): array
{
    $stmt = $db->prepare(
        "SELECT WordId, VocalPartId, IsBackground, SortOrder
           FROM tblLyricWordVocalParts
          WHERE LyricsId = ?
          ORDER BY WordId, SortOrder, VocalPartId"
    );
    bindParamSafe(__FUNCTION__, $stmt, 'i', $lyricsId);
    $stmt->execute();
    $res = $stmt->get_result();
    $out = [];
    while ($row = $res->fetch_assoc()) {
        $wordId = (int)$row['WordId'];
        $out[$wordId][] = [
            'partId'    => (int)$row['VocalPartId'],
            'bg'        => (bool)$row['IsBackground'],
            'sortOrder' => (int)$row['SortOrder'],
        ];
    }
    $stmt->close();
    return $out;
}

/**
 * The word-grain read a presenter/karaoke render surface would want: for
 * a set of line ids, only the WORDS that carry their own override rows
 * (the app-side inherit rule — a word with none inherits its line's
 * parts, so emitting only the overrides keeps this payload tiny for the
 * overwhelming majority of lines, which have none).
 *
 * @param list<int> $lineIds
 * @return array<int,list<array{wordId:int,sortOrder:int,text:string,parts:list<array{partId:int,bg:bool}>}>>
 */
function vocalPartsWordsForLines(\mysqli $db, array $lineIds): array
{
    $ids = array_values(array_unique(array_map('intval', $lineIds)));
    if (!$ids) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare(
        "SELECT wp.WordId AS WordId, wp.VocalPartId AS VocalPartId, wp.IsBackground AS IsBackground,
                w.LineId AS LineId, w.SortOrder AS WordSort, w.WordText AS WordText
           FROM tblLyricWordVocalParts wp
           JOIN tblLyricWords w ON w.Id = wp.WordId
          WHERE w.LineId IN ($placeholders)
          ORDER BY w.LineId, w.SortOrder, wp.SortOrder, wp.VocalPartId"
    );
    bindParamSafe(__FUNCTION__, $stmt, str_repeat('i', count($ids)), ...$ids);
    $stmt->execute();
    $res = $stmt->get_result();

    $wordsByLine = [];
    while ($row = $res->fetch_assoc()) {
        $lineId = (int)$row['LineId'];
        $wordId = (int)$row['WordId'];
        if (!isset($wordsByLine[$lineId][$wordId])) {
            $wordsByLine[$lineId][$wordId] = [
                'wordId'    => $wordId,
                'sortOrder' => (int)$row['WordSort'],
                'text'      => (string)$row['WordText'],
                'parts'     => [],
            ];
        }
        $wordsByLine[$lineId][$wordId]['parts'][] = [
            'partId' => (int)$row['VocalPartId'],
            'bg'     => (bool)$row['IsBackground'],
        ];
    }
    $stmt->close();

    $out = [];
    foreach ($wordsByLine as $lineId => $words) {
        $out[$lineId] = array_values($words);
    }
    return $out;
}

/**
 * `TtmlAgentId => tblVocalParts.Id` for one lyrics version — a future TTML
 * re-ingest's idempotency lookup ("has this `<ttm:agent>` id already been
 * mapped to a part on this version?").
 *
 * @return array<string,int>
 */
function vocalPartsAgentIndex(\mysqli $db, int $lyricsId): array
{
    $stmt = $db->prepare(
        'SELECT TtmlAgentId, Id FROM tblVocalParts WHERE LyricsId = ? AND TtmlAgentId IS NOT NULL'
    );
    bindParamSafe(__FUNCTION__, $stmt, 'i', $lyricsId);
    $stmt->execute();
    $res = $stmt->get_result();
    $out = [];
    while ($row = $res->fetch_assoc()) {
        $out[(string)$row['TtmlAgentId']] = (int)$row['Id'];
    }
    $stmt->close();
    return $out;
}

/**
 * ELI5: "does this lyrics version have ANY voice-part data at all?" — a
 * cheap short-circuit a future bulk reader can call before running the
 * (comparatively expensive) lines/spans/words queries for the overwhelming
 * majority of songs that have none of this data yet.
 *
 * WHY `tblVocalParts` ALONE IS ENOUGH (a deliberate simplification of the
 * three-table union the #2073 design passes describe, not an oversight —
 * flagged here so it reads as a decision, not a shortcut): every row in
 * `tblLyricLineVocalParts` / `tblLyricWordVocalParts` / (once migrated)
 * `tblLyricLineVocalSpans` carries a `VocalPartId` FOREIGN KEY into
 * `tblVocalParts`. An assignment cannot exist without the part it assigns,
 * so a lyrics version can never have a line/word/span row without ALSO
 * having at least one `tblVocalParts` row for the same `LyricsId`.
 * Checking the one cheapest table therefore answers the exact same
 * question the three-table version would, in one query instead of up to
 * four.
 */
function vocalPartsVersionHasAny(\mysqli $db, int $lyricsId): bool
{
    $stmt = $db->prepare('SELECT 1 FROM tblVocalParts WHERE LyricsId = ? LIMIT 1');
    bindParamSafe(__FUNCTION__, $stmt, 'i', $lyricsId);
    $stmt->execute();
    $has = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $has;
}

/* =====================================================================
 * SONG-KEYED, CHUNKED FETCHERS (#2073 commit 4) — feed the ONE public read
 * assembler `lyricLinesAssembleFromRows()` (`includes/lyric_lines_read.php`)
 * via its gated adapter `lyricLinesFetchVoices()`. Each fetcher below reads
 * MANY songs' 'ihymns' version in one chunked `IN()` query (never per-song —
 * the #929 whole-corpus-memory lesson — and never the whole corpus at once,
 * `LYRIC_LINES_READ_CHUNK` songs per round trip, the SAME constant
 * `lyricLinesFetchPrimaryMap()` chunks by), so `getSongs()`'s per-songbook
 * bulk read stays the one query shape it has always been plus exactly one
 * more per chunk.
 *
 * @lyrics-version-exempt (all three functions below): these are the BULK
 * siblings of `lyricLinesFetchPrimary[Map]()` — they read MANY songs' worth
 * of assignment rows in one round trip, so there is no single Id to resolve
 * via `lyricLinesPrimaryLyricsId()` first; the `Source = 'ihymns'` filter is
 * inlined in each JOIN instead, kept byte-identical to that function's own
 * filter by `tests/php/test-lyric-lines-read.php`'s source assertions (#2076).
 * ===================================================================== */

/**
 * ELI5: for a batch of songs, "who sings each line" — grouped by song, then
 * by line, in the order a renderer wants to print them.
 *
 * DETAILED: reads `tblLyricLineVocalParts` JOINed to `tblVocalParts` (for the
 * kind/label the caller needs to render — never a bare `VocalPartId`) and
 * `tblLyrics` (for the 'ihymns'-version filter + the SongId to group by),
 * with a LEFT JOIN to `tblMusicians` for a `named-singer`'s registry name.
 * The display label is resolved HERE, in the fetcher, via
 * `vocalPartsDisplayLabel()` — so the pure fold this feeds
 * (`lyricLinesFoldVoiceRuns()`, `includes/lyric_lines_read.php`) never needs
 * to know the vocabulary at all (rule #22).
 *
 * Row order (`ORDER BY ly.SongId, lvp.LineId, lvp.SortOrder, vp.SortOrder,
 * vp.Id`) is what the PURE fold's `enters` computation depends on for a
 * multi-part line (a duet/echo's chip order is genuinely part of "the same
 * run") — never re-sorted after this query returns.
 *
 * @param list<string> $songIds
 * @return array<string, array<int, list<array{id:int,kind:string,label:string,bg:bool}>>>
 *   SongId => (LineId => ordered list of parts on that line).
 */
function vocalPartsLinesMapForSongs(\mysqli $db, array $songIds): array
{
    /* @lyrics-version-exempt: the bulk sibling of `lyricLinesFetchPrimaryMap()`
       (#2076) — reads MANY songs' 'ihymns' version in one chunked IN() query,
       so there is no single SongId to resolve via lyricLinesPrimaryLyricsId()
       first; the same `Source = 'ihymns'` filter is inlined in the JOIN
       instead (kept byte-identical to that function's own filter). */
    $songIds = array_values(array_unique(array_filter($songIds, static fn($s) => $s !== '' && $s !== null)));
    if (!$songIds) {
        return [];
    }

    $out = [];
    foreach (array_chunk($songIds, LYRIC_LINES_READ_CHUNK) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $stmt = $db->prepare(
            "SELECT ly.SongId AS song_id, lvp.LineId AS line_id, vp.Id AS part_id,
                    vp.PartKind AS kind, vp.Label AS label, vp.SingerName AS singer_name,
                    m.Name AS musician_name, lvp.IsBackground AS bg
               FROM tblLyricLineVocalParts lvp
               JOIN tblVocalParts vp     ON vp.Id = lvp.VocalPartId
               JOIN tblLyrics ly         ON ly.Id = lvp.LyricsId
               LEFT JOIN tblMusicians m  ON m.Id = vp.MusicianId
              WHERE ly.SongId IN ($placeholders) AND ly.Source = 'ihymns'
              ORDER BY ly.SongId, lvp.LineId, lvp.SortOrder, vp.SortOrder, vp.Id"
        );
        bindParamSafe(__FUNCTION__, $stmt, str_repeat('s', count($chunk)), ...$chunk);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $sid    = (string)$row['song_id'];
            $lineId = (int)$row['line_id'];
            $musicianName = ($row['musician_name'] !== null) ? (string)$row['musician_name'] : null;
            $out[$sid][$lineId][] = [
                'id'    => (int)$row['part_id'],
                'kind'  => (string)$row['kind'],
                'label' => vocalPartsDisplayLabel(
                    ['label' => $row['label'], 'singerName' => $row['singer_name'], 'kind' => $row['kind']],
                    $musicianName
                ),
                'bg'    => (bool)$row['bg'],
            ];
        }
        $stmt->close();
    }
    return $out;
}

/**
 * ELI5: for a batch of songs, every mid-line echo/voice-switch span, grouped
 * the same way `vocalPartsLinesMapForSongs()` above groups whole-line
 * assignments.
 *
 * DETAILED: `[]` immediately when `tblLyricLineVocalSpans` hasn't been
 * migrated yet (`vocalPartsSpansReady()`) — no query attempted at all, the
 * same "un-migrated degrades to nothing, never throws" contract every other
 * reader in this file follows. SPAN DRIFT ON EDIT: mirrors the single-version
 * sibling `vocalPartsSpansMap()`'s own doc-block — a span whose `start` no
 * longer fits the line's CURRENT code-point length (a curator shortened the
 * text after the span was drawn) is dropped; one whose `end` overruns is
 * clamped to the new length. Neither case is rewritten in the table or
 * thrown — a curator UI (a later commit) is the one place that should ever
 * notice and offer to fix it.
 *
 * @param list<string> $songIds
 * @return array<string, array<int, list<array{id:int,partId:int,kind:string,label:string,bg:bool,start:int,end:int}>>>
 *   SongId => (LineId => ordered list of spans on that line, by StartOffset).
 */
function vocalPartsSpansMapForSongs(\mysqli $db, array $songIds): array
{
    /* @lyrics-version-exempt: the bulk sibling of `vocalPartsLinesMapForSongs()`
       above — same reasoning (#2076): many songs, one chunked IN() query, the
       `Source = 'ihymns'` filter inlined rather than resolved song-by-song. */
    if (!vocalPartsSpansReady($db)) {
        return [];
    }
    $songIds = array_values(array_unique(array_filter($songIds, static fn($s) => $s !== '' && $s !== null)));
    if (!$songIds) {
        return [];
    }

    $out = [];
    foreach (array_chunk($songIds, LYRIC_LINES_READ_CHUNK) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $stmt = $db->prepare(
            "SELECT ly.SongId AS song_id, sp.Id AS span_id, sp.LineId AS line_id, vp.Id AS part_id,
                    vp.PartKind AS kind, vp.Label AS label, vp.SingerName AS singer_name,
                    m.Name AS musician_name, sp.IsBackground AS bg,
                    sp.StartOffset AS start_off, sp.EndOffset AS end_off, ll.LineText AS line_text
               FROM tblLyricLineVocalSpans sp
               JOIN tblVocalParts vp     ON vp.Id = sp.VocalPartId
               JOIN tblLyrics ly         ON ly.Id = sp.LyricsId
               JOIN tblLyricLines ll     ON ll.Id = sp.LineId
               LEFT JOIN tblMusicians m  ON m.Id = vp.MusicianId
              WHERE ly.SongId IN ($placeholders) AND ly.Source = 'ihymns'
              ORDER BY ly.SongId, sp.LineId, sp.StartOffset, sp.SortOrder, sp.Id"
        );
        bindParamSafe(__FUNCTION__, $stmt, str_repeat('s', count($chunk)), ...$chunk);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $sid    = (string)$row['song_id'];
            $lineId = (int)$row['line_id'];
            $cpLen  = mb_strlen((string)$row['line_text'], 'UTF-8');
            $start  = (int)$row['start_off'];
            if ($start >= $cpLen) {
                continue; // the line shrank past this span's start entirely — drop it (see doc-block)
            }
            $end = min((int)$row['end_off'], $cpLen);
            $musicianName = ($row['musician_name'] !== null) ? (string)$row['musician_name'] : null;
            $out[$sid][$lineId][] = [
                'id'     => (int)$row['span_id'],
                'partId' => (int)$row['part_id'],
                'kind'   => (string)$row['kind'],
                'label'  => vocalPartsDisplayLabel(
                    ['label' => $row['label'], 'singerName' => $row['singer_name'], 'kind' => $row['kind']],
                    $musicianName
                ),
                'bg'     => (bool)$row['bg'],
                'start'  => $start,
                'end'    => $end,
            ];
        }
        $stmt->close();
    }
    return $out;
}

/**
 * ELI5: everything word-grain "who sings this exact word" data exists for one
 * song — across EVERY copy of its lyrics that has any (a TTML import can sit
 * alongside the curator's own version), not just the curator's own.
 *
 * DETAILED (#2073, D2 word grain — `SongData::getSongDetailExtras()`'s opt-in
 * `?include=vocalWords` block, never the base payload): unlike every other
 * reader in this file, this one is deliberately VERSION-INDEPENDENT — a song
 * can carry several `tblLyrics` rows (`uq_song_source` = one per `Source`),
 * and a TTML/Apple-Music ingest's word-grain overrides live on ITS OWN
 * version, not the curator's 'ihymns' one, so there is no single `$lyricsId`
 * to resolve here the way `vocalPartsForVersion()`'s callers do. Bounded by
 * construction: at most one `tblLyrics` row per `Source` per song, so this
 * can never explode the way a whole-corpus read would (rule #17) — it is
 * still opt-in only, never folded into the base `song_detail` payload,
 * because even a bounded per-song read is wasted work for the overwhelming
 * majority of songs that carry no word-grain data at all.
 *
 * Reuses the already-landed pure per-version fetchers rather than re-deriving
 * them (rule #22): `vocalPartsForVersion()` for `parts`, `vocalPartsLinesMap()`
 * for each line's line-grain parts, `vocalPartsWordsMap()` for each word's
 * OWN override rows (a word with none INHERITS its line's parts — the schema
 * rule — so this deliberately does NOT synthesise an inherited entry; an
 * empty `parts` list on a word means exactly that).
 *
 * @return list<array{
 *   lyricsId:int, source:string,
 *   parts: list<array{id:int,kind:string,label:?string,displayLabel:string,singerName:?string,gender:?string,musicianId:?int,ttmlAgentId:?string,source:string,sortOrder:int}>,
 *   lines: list<array{
 *     lineId:int, sortOrder:int, text:string, startMs:?int, endMs:?int,
 *     parts: list<array{id:int,bg:bool}>,
 *     words: list<array{wordId:int, sortOrder:int, text:string, startMs:?int, endMs:?int, parts: list<array{id:int,bg:bool}>}>
 *   }>
 * }>
 */
function vocalPartsWordsForSong(\mysqli $db, string $songId): array
{
    /* @lyrics-version-exempt: this function is DELIBERATELY version-
       INDEPENDENT (see its own doc-block above) — it reads EVERY tblLyrics
       version with word-grain data, not "the" primary version
       lyricLinesPrimaryLyricsId() would resolve to, so calling that resolver
       here would answer a question this function was never asking. */
    $vStmt = $db->prepare(
        "SELECT DISTINCT ly.Id AS lyrics_id, ly.Source AS source
           FROM tblLyricWordVocalParts w
           JOIN tblLyrics ly ON ly.Id = w.LyricsId
          WHERE ly.SongId = ?
          ORDER BY ly.Id"
    );
    bindParamSafe(__FUNCTION__ . ':versions', $vStmt, 's', $songId);
    $vStmt->execute();
    $versions = $vStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $vStmt->close();
    if (!$versions) {
        return [];
    }

    $out = [];
    foreach ($versions as $v) {
        $lyricsId = (int)$v['lyrics_id'];
        $source   = (string)$v['source'];

        $lineStmt = $db->prepare(
            "SELECT Id, SortOrder, LineText, StartTimeMs, EndTimeMs
               FROM tblLyricLines WHERE LyricsId = ? ORDER BY SortOrder, Id"
        );
        bindParamSafe(__FUNCTION__ . ':lines', $lineStmt, 'i', $lyricsId);
        $lineStmt->execute();
        $lineRows = $lineStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $lineStmt->close();

        $wordStmt = $db->prepare(
            "SELECT w.Id AS word_id, w.LineId AS line_id, w.SortOrder AS sort_order,
                    w.WordText AS word_text, w.StartTimeMs AS start_ms, w.EndTimeMs AS end_ms
               FROM tblLyricWords w
               JOIN tblLyricLines ll ON ll.Id = w.LineId
              WHERE ll.LyricsId = ?
              ORDER BY ll.SortOrder, w.SortOrder, w.Id"
        );
        bindParamSafe(__FUNCTION__ . ':words', $wordStmt, 'i', $lyricsId);
        $wordStmt->execute();
        $wordRows = $wordStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $wordStmt->close();

        /* Reuse the existing per-version fetchers (rule #22) — no second copy
           of the line-grain / word-grain override SELECTs. */
        $lineParts = vocalPartsLinesMap($db, $lyricsId);
        $wordParts = vocalPartsWordsMap($db, $lyricsId);
        $toPartRef = static fn(array $p): array => ['id' => $p['partId'], 'bg' => $p['bg']];

        $wordsByLine = [];
        foreach ($wordRows as $wr) {
            $lid = (int)$wr['line_id'];
            $wid = (int)$wr['word_id'];
            $wordsByLine[$lid][] = [
                'wordId'    => $wid,
                'sortOrder' => (int)$wr['sort_order'],
                'text'      => (string)$wr['word_text'],
                'startMs'   => $wr['start_ms'] !== null ? (int)$wr['start_ms'] : null,
                'endMs'     => $wr['end_ms'] !== null ? (int)$wr['end_ms'] : null,
                'parts'     => array_map($toPartRef, $wordParts[$wid] ?? []),
            ];
        }

        $lines = [];
        foreach ($lineRows as $lr) {
            $lid = (int)$lr['Id'];
            $lines[] = [
                'lineId'    => $lid,
                'sortOrder' => (int)$lr['SortOrder'],
                'text'      => (string)$lr['LineText'],
                'startMs'   => $lr['StartTimeMs'] !== null ? (int)$lr['StartTimeMs'] : null,
                'endMs'     => $lr['EndTimeMs'] !== null ? (int)$lr['EndTimeMs'] : null,
                'parts'     => array_map($toPartRef, $lineParts[$lid] ?? []),
                'words'     => $wordsByLine[$lid] ?? [],
            ];
        }

        $out[] = [
            'lyricsId' => $lyricsId,
            'source'   => $source,
            'parts'    => vocalPartsForVersion($db, $lyricsId),
            'lines'    => $lines,
        ];
    }
    return $out;
}

/* =====================================================================
 * PURE DERIVED VIEW — no DB. Works out where a "MEN"/"WOMEN" run header
 * would sit given an already-fetched lines map, for ONE component's lines
 * in their display order.
 * ===================================================================== */

/**
 * ELI5: work out where each voice-header ("MEN"/"WOMEN"/…) would go —
 * once at the start of every stretch of lines sung by the same set of
 * voices — from a component's ordered line ids and the line-assignment
 * map `vocalPartsLinesMap()` returns. PURE.
 *
 * Detail: `partSet(line)` = the sorted list of `partId` where `bg` is
 * false — background/echo rows never start or end a run on their own.
 * A run starts at index 0 and wherever `partSet !== partSet(previous)`.
 * Lines with an EMPTY part set form runs too (`partIds: []`, so a render
 * surface can show "(everyone)" or nothing at all) — EXCEPT a run that
 * starts at index 0 AND is empty, which is OMITTED entirely: a component
 * with no voice data at all must derive `[]`, so every existing render
 * that has never heard of this feature stays byte-identical.
 *
 * @param list<int>                                              $orderedLineIds  one component's line ids, in display order
 * @param array<int,list<array{partId:int,bg:bool,sortOrder:int}>> $linesMap        as returned by `vocalPartsLinesMap()`
 * @return list<array{startIndex:int,endIndex:int,startLineId:int,endLineId:int,partIds:list<int>}>
 */
function vocalPartsDeriveRuns(array $orderedLineIds, array $linesMap): array
{
    $n = count($orderedLineIds);
    if ($n === 0) {
        return [];
    }

    $partSetFor = static function (int $lineId) use ($linesMap): array {
        $ids = [];
        foreach (($linesMap[$lineId] ?? []) as $row) {
            if (empty($row['bg'])) {
                $ids[] = (int)$row['partId'];
            }
        }
        sort($ids);
        return $ids;
    };

    $runs = [];
    $curStart = 0;
    $curSet = $partSetFor($orderedLineIds[0]);

    for ($i = 1; $i < $n; $i++) {
        $set = $partSetFor($orderedLineIds[$i]);
        if ($set !== $curSet) {
            if (!($curStart === 0 && $curSet === [])) {
                $runs[] = [
                    'startIndex'  => $curStart,
                    'endIndex'    => $i - 1,
                    'startLineId' => $orderedLineIds[$curStart],
                    'endLineId'   => $orderedLineIds[$i - 1],
                    'partIds'     => $curSet,
                ];
            }
            $curStart = $i;
            $curSet = $set;
        }
    }

    if (!($curStart === 0 && $curSet === [])) {
        $runs[] = [
            'startIndex'  => $curStart,
            'endIndex'    => $n - 1,
            'startLineId' => $orderedLineIds[$curStart],
            'endLineId'   => $orderedLineIds[$n - 1],
            'partIds'     => $curSet,
        ];
    }

    return $runs;
}
