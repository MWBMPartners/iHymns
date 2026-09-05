<?php

declare(strict_types=1);

/**
 * iHymns — Voice parts / echo / rounds: vocabulary + read-only core (#2073, commit 1)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5: this file is the ONE place that knows "who sings this line" — the
 * fixed list of voice kinds (Men, Women, Choir, a Soloist, …), the helpers
 * that turn a typed word like "MEN" into one of those kinds, the read-only
 * queries that fetch what a song has already had assigned, AND (as of
 * commit 5) the write half: create/edit a part, put it on a run of lines,
 * mark a sub-line echo span, and the bulk "everything about this song's
 * voices" read-back every write hands back (rule #35).
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
 * COMMIT SCOPE (#2073 commits 1, 4 and 5 of 17 — see
 * `.claude/vocal-parts-2073-plan.md`, "Design pass 7" §12 for the full plan):
 * commits 1 and 4 shipped vocabulary + normalisers + shape builders + READ
 * fetchers ONLY (their own history is unchanged below — see the two
 * paragraphs that follow). Commit 5 (this one) adds the WRITE half:
 * `vocalPartsUpsert()` / `vocalPartsDelete()` (the part registry),
 * `vocalPartsAssignLines()` / `vocalPartsClearLines()` (the run-of-lines
 * grain), `vocalPartsSpanUpsert()` / `vocalPartsSpanDelete()` (the sub-line
 * echo grain), their ingest-only, version-scoped twins
 * (`vocalPartsAssignLinesForVersion()`, `vocalPartsAssignWords()`,
 * `vocalPartsPruneAgents()` — none of these three has a caller yet; they
 * are the shape a later TTML-ingest commit needs and are proven here by a
 * PURE truth table only), `vocalPartsApplyComponentVoices()` (the ONE seam
 * `lyricLinesWriteComponents()` calls so an IMPORTER'S positional `voices`
 * cells become FK rows the moment a line finally gets an Id — see that
 * function's own doc-block and `includes/lyric_lines_sync.php`), and
 * `vocalPartsForSong()` (the ONE bulk payload — `VOCAL_PARTS_PAYLOAD_KEYS`,
 * declared back in commit 1 — every write below hands back in full, rule
 * #35's "read the truth back, never trust what you just sent"). Every
 * write function in this file reuses the SAME ownership/IDOR resolvers
 * commit 1 already shipped (`vocalPartsResolveLines()` /
 * `vocalPartsResolvePart()`) rather than a second ad-hoc JOIN — a caller
 * cannot attach a part to, or read a span from, a line that is not on
 * *this* song's *primary* lyrics version, ever (see §2.2's doc-blocks,
 * unchanged by this commit).
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
 * WHY THE NEW WRITE FUNCTIONS ARE STILL "DORMANT" DESPITE EXISTING NOW:
 * nothing in the shipped Editor UI or `api2.php` calls any of them yet —
 * that wiring is commit 6/7 of the same plan. `vocalPartsApplyComponentVoices()`
 * is the one exception worth naming precisely: `lyricLinesWriteComponents()`
 * calls it on EVERY save, but only ever does anything when a caller's
 * payload carries a `voices` key on a component (`voicesProvided`) — an
 * ordinary editor save never sets that key, so this stays a verified
 * byte-identical no-op for every save that has never heard of voices, the
 * same "read the flag before doing the work" discipline #2072's
 * `notesProvided` / `chordsProvided` already established one file over.
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


/* =====================================================================
 * #2073 COMMIT 5 — THE WRITE HALF.
 *
 * Everything below INSERTs / UPDATEs / DELETEs. Same throw contract as
 * every read function above (see the file header): `\InvalidArgumentException`
 * -> the caller answers 400, `\RuntimeException` -> 404 (an id doesn't exist
 * or doesn't belong to the song asked about — this file never says which,
 * so a guess can't be used to probe for the difference). None of these
 * functions checks `vocalPartsTablesReady()` itself — that is the CALLER's
 * job (a 409 belongs to the endpoint, not the core), exactly as
 * `line_enrichment.php`'s own upsert/delete functions do.
 *
 * PURE VALIDATORS FIRST (no DB — directly unit-tested,
 * `tests/php/test-vocal-parts-core.php`), then the DB-touching functions
 * that call them. This mirrors `line_enrichment.php`'s own split: "the
 * DB-touching upsert/delete functions need a live mysqli and are covered by
 * manual / staging verification; these pure guards are the CI-enforced
 * contract" (that file's own header) — this codebase has no live MySQL in
 * CI, so pushing every real DECISION into a pure function is what makes the
 * decision itself testable at all.
 * ===================================================================== */

/**
 * ELI5: turn whatever kind text a write handed us into one of the 21 keys,
 * or refuse the write outright — unlike the read-side `vocalPartsNormalizeKind()`
 * this NEVER returns null quietly, because a WRITE with an unrecognised kind
 * is a caller bug (a stale client, a typo in a payload) that must surface as
 * a 400, not silently store something wrong.
 *
 * @throws \InvalidArgumentException  the text is not a known kind, alias or marker
 */
function vocalPartsRequireKind(string $kindInput): string
{
    $kind = vocalPartsNormalizeKind($kindInput);
    if ($kind === null) {
        throw new \InvalidArgumentException("Unknown vocal-part kind '{$kindInput}'.");
    }
    return $kind;
}

/**
 * ELI5: clean up a typed label — trim it, cap its length, and turn "I typed
 * nothing" into a real `null` rather than an empty string (so `Label <=> NULL`
 * NULL-safe comparisons downstream, and the display fallback ladder in
 * `vocalPartsDisplayLabel()`, both see the ABSENCE of a label, not a
 * zero-length string masquerading as one).
 *
 * PURE — accepts `mixed` because a JSON body decodes a missing/absent key as
 * PHP `null` and this file's write functions pass whatever the caller sent
 * straight through without a separate `is_string()` gate first.
 */
function vocalPartsNormalizeLabelInput(mixed $value, int $maxLen = 120): ?string
{
    if ($value === null) {
        return null;
    }
    $s = trim((string)$value);
    if ($s === '') {
        return null;
    }
    return function_exists('mb_substr') ? mb_substr($s, 0, $maxLen, 'UTF-8') : substr($s, 0, $maxLen);
}

/**
 * ELI5: rule #45's "hide when it says nothing a reader couldn't already
 * guess" fold, applied to a vocal part's Label — if a curator types "Women"
 * as the Label of a `female` part, that is not an override, it is exactly
 * the word `vocalPartsDisplayLabel()` would already show, so store `null`
 * instead (the D1 hide-when-equal rule this feature's own plan cites from
 * rule #27/#45's component-Label precedent). Case-folded so "women" / "WOMEN"
 * / "Women" are all recognised as "the same as the kind's own word".
 *
 * PURE. Never called for `null` (a caller passes the already-normalised
 * label from `vocalPartsNormalizeLabelInput()`, which is `null` exactly when
 * there is nothing to fold in the first place).
 */
function vocalPartsFoldHiddenLabel(?string $label, string $kind): ?string
{
    if ($label === null) {
        return null;
    }
    $def = IHYMNS_VOCAL_PART_KINDS[$kind] ?? null;
    if ($def === null) {
        return $label;
    }
    $kindLabel = (string)$def['label'];
    return (mb_strtolower($label, 'UTF-8') === mb_strtolower($kindLabel, 'UTF-8')) ? null : $label;
}

/**
 * ELI5: work out the Gender a vocal part should actually be stored with —
 * a caller's explicit choice wins, but if they said nothing, use whatever
 * the kind itself implies (rule #44 — derive, never ask twice for a fact
 * the kind already answers).
 *
 * @throws \InvalidArgumentException  a NON-EMPTY value that is not one of
 *                                     IHYMNS_VOCAL_GENDERS
 */
function vocalPartsNormalizeGenderInput(?string $gender, string $kind): ?string
{
    if ($gender === null || trim($gender) === '') {
        return vocalPartsImpliedGender($kind);
    }
    $g = strtolower(trim($gender));
    if (!in_array($g, IHYMNS_VOCAL_GENDERS, true)) {
        throw new \InvalidArgumentException('gender must be one of: ' . implode(', ', IHYMNS_VOCAL_GENDERS));
    }
    return $g;
}

/**
 * ELI5: a "Named singer" part is meaningless without knowing WHICH singer —
 * either a real musician-registry row or, failing that, at least a typed
 * name. Every other kind needs neither, so this is a no-op for them.
 *
 * @throws \InvalidArgumentException  kind is 'named-singer' and BOTH are empty
 */
function vocalPartsValidateNamedSingerInputs(string $kind, ?int $musicianId, ?string $singerName): void
{
    if ($kind !== 'named-singer') {
        return;
    }
    $hasMusician = $musicianId !== null && $musicianId > 0;
    $hasName     = $singerName !== null && trim($singerName) !== '';
    if (!$hasMusician && !$hasName) {
        throw new \InvalidArgumentException('A named singer needs a musician or a name.');
    }
}

/**
 * ELI5: "add" or "replace" — anything else is a caller bug, refused loudly
 * rather than silently treated as one or the other.
 *
 * @throws \InvalidArgumentException  not (case-insensitively) 'add' or 'replace'
 */
function vocalPartsNormalizeAssignMode(string $mode): string
{
    $m = strtolower(trim($mode));
    if ($m !== 'add' && $m !== 'replace') {
        throw new \InvalidArgumentException("mode must be 'add' or 'replace'.");
    }
    return $m;
}

/**
 * ELI5: the extra rules a sub-line echo span must follow ON TOP OF the
 * ordinary offset-in-range check every code-point span in this codebase
 * shares (`lineEnrichmentValidateOffsets()`, reused here rather than
 * re-forked — rule #22): it must have real width (a zero-width span marks
 * nothing), and it must NOT be the whole line (that is a LINE assignment —
 * `vocalPartsAssignLines()` — not a span; storing it as a span would let
 * the same fact be represented two different ways, which is exactly what
 * this whole feature's DDL doc-block calls out as "the app-rejected case").
 *
 * PURE (once `line_enrichment.php` is loaded — the DB-touching span
 * functions below `require_once` it before calling this; the caller MUST
 * do the same before calling this directly, exactly like every other
 * `line_enrichment.php` consumer in this codebase).
 *
 * @return array{0:int,1:int}  [start, end], both non-null (a vocal span never
 *                              uses the enrichment schema's "to the end"/"from
 *                              the start" null shorthand — a span is always a
 *                              closed pair on ONE line)
 * @throws \InvalidArgumentException
 */
function vocalPartsValidateSpanOffsets(int $start, int $end, int $cpLen): array
{
    /* @lyrics-version-exempt: PURE offset arithmetic — no version to resolve. */
    [$s, $e] = lineEnrichmentValidateOffsets($start, $end, $cpLen, $cpLen, true);
    $s = $s ?? 0;
    $e = $e ?? $cpLen;
    if ($e <= $s) {
        throw new \InvalidArgumentException('endOffset must be greater than startOffset.');
    }
    if ($s === 0 && $e === $cpLen) {
        throw new \InvalidArgumentException('A whole-line span is a line assignment — use the line control, not a span.');
    }
    return [$s, $e];
}

/**
 * ELI5: given a TTML `<ttm:agent type="…">` and "is this the 1st, 2nd, …
 * PERSON-type agent in the file", guess a reasonable starting kind for it.
 * PURE — a curator can always re-kind the part afterwards; this only has to
 * be a REASONABLE first guess, never a promise.
 *
 * TTML2 §12.2.1 defines four `ttm:agent` types: person | group | character |
 * other (this file's own vocabulary doc-block also lists `organization`,
 * which several real-world TTML files use even though it is not in the
 * TTML2 base vocabulary — mapped the same way here).
 *
 * ⚠️ FLAGGED DEVIATION FROM THE PLAN'S OWN PROSE (per this commit's brief:
 * report a plan/reality mismatch loudly rather than silently resolving it
 * either way): "Design pass 2" §2.8 explicitly says a `person`-type agent
 * ALWAYS maps to `'lead'`, "deterministic and simple" — and explicitly
 * REJECTS ordinal differentiation ("for the FIRST person agent... 'soloist'
 * for later ones? No"). "Design pass 7" (authoritative on a contradiction)
 * then lists THIS function's signature with a `$personOrdinal` parameter
 * Pass 2's version never had, but Pass 7's prose never explains what that
 * parameter is FOR — so the plan, as written, hands this implementer a
 * parameter with no stated purpose while its own cited source function
 * explicitly argues against the one purpose a fresh reader would guess.
 * DEFENSIBLE DEFAULT CHOSEN HERE (trivially changeable — this function is
 * dormant, ingest-only, and has no caller in this commit): the FIRST
 * `person`-type agent (`$personOrdinal === 0`) is `'lead'`; every
 * SUBSEQUENT one is `'soloist'` — multiple distinct named voices in one
 * TTML file are far more often several soloists than several equally-"lead"
 * singers, and `'soloist'` is a strictly weaker, easily-corrected claim than
 * silently reusing `'lead'` for two different people. A real Apple Music
 * TTML fixture (owner checklist item, tracked in the plan's standing-tasks
 * list) is what should settle this for good.
 *
 * @param array{type?:?string,name?:?string,meta?:array} $agent
 */
function vocalPartsKindFromTtmlAgent(array $agent, int $personOrdinal): string
{
    $type = strtolower(trim((string)($agent['type'] ?? '')));
    switch ($type) {
        case 'group':
            return 'group';
        case 'other':
            return 'duet';
        case 'character':
            return 'named-singer';
        case 'organization':
            return 'choir';
        case 'person':
            return $personOrdinal <= 0 ? 'lead' : 'soloist';
        default:
            /* Missing / unrecognised type attribute — Pass 2 §2.8's own
               "missing/unknown -> 'lead'" fallback, unaffected by the
               ordinal question above (an agent with no declared type at
               all is not confidently a repeated soloist). */
            return 'lead';
    }
}

/* --------------------------------------------------------------------
 * Part registry — create / edit / delete a tblVocalParts row.
 * -------------------------------------------------------------------- */

/**
 * ELI5: "give me the part for this voice on this lyrics version — reuse it
 * if one already matches, otherwise make one." Rule #43's find-or-create
 * discipline, applied to the PART row (never to the fixed KIND vocabulary,
 * which is never minted — see this file's own vocabulary doc-block).
 *
 * Match ladder (first hit wins, all scoped to `$lyricsId`):
 *   1. `$ttmlAgentId` given -> `(LyricsId, TtmlAgentId)` — `uq_Lyrics_Agent`
 *      (schema.sql:4518), the idempotent re-ingest key.
 *   2. `$kind === 'named-singer' && $musicianId` given -> the SAME musician
 *      already registered as a named-singer part on this version.
 *   3. Otherwise -> `(LyricsId, PartKind, Label <=> $label)` — the NULL-safe
 *      `<=>` operator so "no label" only matches another "no label" row,
 *      never accidentally matching a labelled one or vice versa.
 *   4. No match anywhere above -> INSERT a new row, `SortOrder` = the
 *      version's current max + 1 (read then bound — never interpolated).
 *
 * On a hit this NEVER overwrites `Label` / `SingerName` / `MusicianId` (a
 * curator's own edits always win over a re-ingest's guess); `MetaJson` is
 * refreshed only when the row's OWN `Source` still equals the caller's
 * `$source` (a machine-owned row may have its machine metadata refreshed by
 * the SAME machine, never by a different one, and never by a curator's
 * `'ihymns'`-sourced write at all).
 *
 * @param array<string,mixed>|null $meta  JSON-encoded into MetaJson on a hit
 *                                         refresh or a create; `null` leaves
 *                                         MetaJson untouched/absent
 * @return int  tblVocalParts.Id
 * @throws \InvalidArgumentException  unknown $kind
 */
function vocalPartsFindOrCreate(
    \mysqli $db,
    int $lyricsId,
    string $kind,
    ?string $label = null,
    string $source = 'ihymns',
    ?string $ttmlAgentId = null,
    ?int $musicianId = null,
    ?string $singerName = null,
    ?array $meta = null
): int {
    $kind  = vocalPartsRequireKind($kind);
    $label = vocalPartsNormalizeLabelInput($label);
    $metaJson = ($meta !== null) ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null;

    if ($ttmlAgentId !== null && $ttmlAgentId !== '') {
        $stmt = $db->prepare('SELECT Id, Source FROM tblVocalParts WHERE LyricsId = ? AND TtmlAgentId = ? LIMIT 1');
        bindParamSafe(__FUNCTION__ . ':agent', $stmt, 'is', $lyricsId, $ttmlAgentId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row !== null) {
            if ($metaJson !== null && (string)$row['Source'] === $source) {
                $upd = $db->prepare('UPDATE tblVocalParts SET MetaJson = ? WHERE Id = ?');
                bindParamSafe(__FUNCTION__ . ':agent-meta', $upd, 'si', $metaJson, $row['Id']);
                $upd->execute();
                $upd->close();
            }
            return (int)$row['Id'];
        }
    } elseif ($kind === 'named-singer' && $musicianId !== null) {
        $stmt = $db->prepare(
            "SELECT Id FROM tblVocalParts WHERE LyricsId = ? AND PartKind = 'named-singer' AND MusicianId = ? LIMIT 1"
        );
        bindParamSafe(__FUNCTION__ . ':musician', $stmt, 'ii', $lyricsId, $musicianId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row !== null) {
            return (int)$row['Id'];
        }
    } else {
        $stmt = $db->prepare('SELECT Id FROM tblVocalParts WHERE LyricsId = ? AND PartKind = ? AND Label <=> ? LIMIT 1');
        bindParamSafe(__FUNCTION__ . ':label', $stmt, 'iss', $lyricsId, $kind, $label);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row !== null) {
            return (int)$row['Id'];
        }
    }

    $sortStmt = $db->prepare('SELECT COALESCE(MAX(SortOrder) + 1, 0) FROM tblVocalParts WHERE LyricsId = ?');
    bindParamSafe(__FUNCTION__ . ':sort', $sortStmt, 'i', $lyricsId);
    $sortStmt->execute();
    $sortOrder = (int)($sortStmt->get_result()->fetch_row()[0] ?? 0);
    $sortStmt->close();

    $gender = vocalPartsImpliedGender($kind);

    $insCols  = ['LyricsId', 'PartKind', 'Label', 'MusicianId', 'SingerName', 'Gender', 'TtmlAgentId', 'Source', 'SortOrder', 'MetaJson'];
    $insTypes = implode('', ['i', 's', 's', 'i', 's', 's', 's', 's', 'i', 's']);
    $ins = $db->prepare('INSERT INTO tblVocalParts (' . implode(', ', $insCols) . ') VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    bindParamSafe(
        __FUNCTION__ . ':insert',
        $ins,
        $insTypes,
        $lyricsId,
        $kind,
        $label,
        $musicianId,
        $singerName,
        $gender,
        $ttmlAgentId,
        $source,
        $sortOrder,
        $metaJson
    );
    $ins->execute();
    $id = (int)$db->insert_id;
    $ins->close();
    return $id;
}

/**
 * ELI5: create or edit ONE vocal part — the curator-facing counterpart of
 * `vocalPartsFindOrCreate()` (which is the ingest-facing one). An `id` means
 * "edit this exact row"; its absence means "find the matching part on this
 * version, or make one" — the SAME find-or-create discipline
 * `vocalPartsFindOrCreate()` already gives every other creation path (rule
 * #43), now closed here too (bug found while building the panel, see the
 * dedupe-guard block inside the function body for the fix and its match
 * ladder). A CREATE that lands on an existing match is treated exactly like
 * an EDIT of that row — any field the caller DID pass (e.g. an explicit
 * `gender`) is still applied; a field the caller left out simply keeps
 * whatever the matched row already had, same as any other omitted-means-keep
 * field on this function.
 *
 * IDOR: an `id` (given, or found by the dedupe guard) is resolved via
 * `vocalPartsResolvePart($db, $songId, $id)` (this file's own ownership
 * check, §2.2) — a caller can never edit a part that does not belong to a
 * lyrics version of THIS song.
 *
 * Input `{id?, kind, label?, singerName?, gender?, musicianId?, sortOrder?}`.
 * Every field is OMITTED-MEANS-KEEP on an UPDATE (`array_key_exists`, the
 * same convention as this codebase's Label/SourceWorkId component fields,
 * rule #45) and REQUIRED-ISH on a CREATE (`kind` is the one field that must
 * actually be present; everything else defaults sensibly). `Source` and
 * `TtmlAgentId` are machine provenance — never settable through this
 * curator-facing function at all (a curator edits what a part SAYS, not
 * where it CAME FROM).
 *
 * @param array<string,mixed> $input
 * @return array  the shape (`vocalPartsShape()`) of the row as it now stands
 * @throws \InvalidArgumentException  bad kind/gender/label/musicianId, or a
 *                                     named-singer with neither name nor musician
 * @throws \RuntimeException          `id` given but not found for this song
 */
function vocalPartsUpsert(\mysqli $db, string $songId, array $input, ?int $userId = null): array
{
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'lyric_lines_sync.php';   // lyricLinesEnsurePrimaryVersion()

    $id = isset($input['id']) ? (int)$input['id'] : 0;
    $existing = null;

    if ($id > 0) {
        $existing = vocalPartsResolvePart($db, $songId, $id);
        if ($existing === null) {
            throw new \RuntimeException('Vocal part not found for this song.');
        }
        $lyricsId = (int)$existing['LyricsId'];
        $kind = (array_key_exists('kind', $input) && $input['kind'] !== null)
            ? vocalPartsRequireKind((string)$input['kind'])
            : (string)$existing['PartKind'];
    } else {
        $kind     = vocalPartsRequireKind((string)($input['kind'] ?? ''));
        $lyricsId = lyricLinesEnsurePrimaryVersion($db, $songId);
    }

    $label = array_key_exists('label', $input)
        ? vocalPartsFoldHiddenLabel(vocalPartsNormalizeLabelInput($input['label']), $kind)
        : ($existing !== null ? $existing['Label'] : null);

    $singerName = array_key_exists('singerName', $input)
        ? vocalPartsNormalizeLabelInput($input['singerName'], 255)
        : ($existing !== null ? $existing['SingerName'] : null);

    $musicianId = null;
    if (array_key_exists('musicianId', $input)) {
        if ($input['musicianId'] !== null) {
            $musicianId = (int)$input['musicianId'];
            $chk = $db->prepare('SELECT 1 FROM tblMusicians WHERE Id = ? LIMIT 1');
            bindParamSafe(__FUNCTION__ . ':musician-exists', $chk, 'i', $musicianId);
            $chk->execute();
            $found = $chk->get_result()->fetch_row() !== null;
            $chk->close();
            if (!$found) {
                throw new \InvalidArgumentException('musicianId does not exist.');
            }
        }
    } elseif ($existing !== null) {
        $musicianId = $existing['MusicianId'] !== null ? (int)$existing['MusicianId'] : null;
    }

    vocalPartsValidateNamedSingerInputs($kind, $musicianId, $singerName);

    /* ---- Duplicate-part guard (bug fix — a real bug found while building
       the "Who sings" panel). BEFORE this fix, a CREATE request (`id`
       absent) always INSERTed a brand-new row, even when this exact voice
       already existed on the version: mark one run of lines "Women", mark a
       second run "Women" again, and the song ends up with TWO separate
       Women parts — one singing group silently split across two rows, with
       no error anywhere. `vocalPartsFindOrCreate()` (this file, above) has
       always had the matching check; this was the ONE creation path that
       skipped it (verified: no api2 action called the sibling function).
       The panel papered over this in the browser (`findExistingPartForKind()`
       in voices-panel.js) — a check a curator's own browser session can
       always be missed by, and that a script, a future native app, or
       anyone hitting the API directly walks straight past. Fixing it here,
       where every caller goes through it, is what actually closes it.
       Match ladder (first hit wins) — mirrors `vocalPartsFindOrCreate()`'s
       own ladder exactly, so the ingest path and the curator path treat
       "is this the same voice?" the SAME way (rule #35), extended with the
       one case that ladder has no caller for yet: a named singer typed by
       name alone, no musician record:
         1. named-singer WITH a musicianId  -> same LyricsId + PartKind +
            MusicianId (the same real person can only have one part here).
         2. named-singer WITHOUT a musicianId but WITH a singerName -> same
            LyricsId + PartKind + (MusicianId IS NULL) + that exact
            SingerName (case-insensitive — the column's own
            utf8mb4_unicode_ci collation folds case the same way the panel's
            own `.toLowerCase()` match already did). Scoped to MusicianId
            IS NULL so a typed "the same name as a REGISTERED musician's"
            can never collide with that musician's own part.
         3. every other kind (and a named-singer match that only reaches
            here because it has neither, which the validation call two
            lines up has already refused) -> same LyricsId + PartKind +
            Label <=> $label (NULL-safe, so "no label" only matches another
            "no label" row).
       A DIFFERENT label on the SAME kind is deliberately NOT folded into
       the same part — "Women" and "Ladies" stay two separate rows. Silently
       merging them would let a later create silently overwrite whatever a
       curator had typed into the first one's Label, which is a worse
       surprise than two same-kind parts; a curator who really did mean
       "rename it" already has an explicit `id`-bearing edit for that. This
       also matches the vocabulary itself: two "female" parts with different
       labels are exactly how a curator would represent "Sopranos" and
       "Altos" as two distinct rows of the one implied gender.
       On a hit, this reruns the REST of the function as an EDIT of the
       found row (falls straight into the `$id > 0` branch below) rather
       than forking a second code path — every field the caller passed
       still applies (an explicit `gender`, say); a field they left out
       keeps what the found row already had, via the very same
       `$existing !== null` fallbacks already written above and below this
       block for the ordinary edit case. */
    if ($id === 0) {
        if ($kind === 'named-singer' && $musicianId !== null) {
            $dupe = $db->prepare('SELECT Id FROM tblVocalParts WHERE LyricsId = ? AND PartKind = ? AND MusicianId = ? LIMIT 1');
            bindParamSafe(__FUNCTION__ . ':dupe-musician', $dupe, 'isi', $lyricsId, $kind, $musicianId);
        } elseif ($kind === 'named-singer' && $singerName !== null) {
            $dupe = $db->prepare('SELECT Id FROM tblVocalParts WHERE LyricsId = ? AND PartKind = ? AND MusicianId IS NULL AND SingerName = ? LIMIT 1');
            bindParamSafe(__FUNCTION__ . ':dupe-singer', $dupe, 'iss', $lyricsId, $kind, $singerName);
        } else {
            $dupe = $db->prepare('SELECT Id FROM tblVocalParts WHERE LyricsId = ? AND PartKind = ? AND Label <=> ? LIMIT 1');
            bindParamSafe(__FUNCTION__ . ':dupe-label', $dupe, 'iss', $lyricsId, $kind, $label);
        }
        $dupe->execute();
        $dupeRow = $dupe->get_result()->fetch_assoc();
        $dupe->close();
        if ($dupeRow !== null) {
            $id = (int)$dupeRow['Id'];
            $existing = vocalPartsResolvePart($db, $songId, $id);
        }
    }

    /* Gender: a caller's explicit choice always wins (even an explicit
       `null`, which re-derives from the — possibly just-changed — kind);
       an omitted key on an UPDATE keeps whatever is already stored UNLESS
       the kind itself just changed, in which case the stale gender from the
       OLD kind would be actively misleading, so it is re-derived instead. */
    if (array_key_exists('gender', $input)) {
        $gender = vocalPartsNormalizeGenderInput(
            $input['gender'] !== null ? (string)$input['gender'] : null,
            $kind
        );
    } elseif ($existing !== null && (string)$existing['PartKind'] === $kind) {
        $gender = $existing['Gender'];
    } else {
        $gender = vocalPartsImpliedGender($kind);
    }

    $sortOrder = null;
    if (array_key_exists('sortOrder', $input) && $input['sortOrder'] !== null) {
        $sortOrder = max(0, (int)$input['sortOrder']);
    } elseif ($existing !== null) {
        $sortOrder = (int)$existing['SortOrder'];
    }

    if ($id > 0) {
        $upd = $db->prepare(
            'UPDATE tblVocalParts SET PartKind = ?, Label = ?, SingerName = ?, Gender = ?, MusicianId = ?, SortOrder = ? WHERE Id = ?'
        );
        bindParamSafe(
            __FUNCTION__ . ':update',
            $upd,
            implode('', ['s', 's', 's', 's', 'i', 'i', 'i']),
            $kind,
            $label,
            $singerName,
            $gender,
            $musicianId,
            $sortOrder,
            $id
        );
        $upd->execute();
        $upd->close();
        $resultId = $id;
    } else {
        if ($sortOrder === null) {
            $sortStmt = $db->prepare('SELECT COALESCE(MAX(SortOrder) + 1, 0) FROM tblVocalParts WHERE LyricsId = ?');
            bindParamSafe(__FUNCTION__ . ':sort', $sortStmt, 'i', $lyricsId);
            $sortStmt->execute();
            $sortOrder = (int)($sortStmt->get_result()->fetch_row()[0] ?? 0);
            $sortStmt->close();
        }
        $ins = $db->prepare(
            "INSERT INTO tblVocalParts (LyricsId, PartKind, Label, SingerName, Gender, MusicianId, Source, SortOrder)
             VALUES (?, ?, ?, ?, ?, ?, 'ihymns', ?)"
        );
        bindParamSafe(
            __FUNCTION__ . ':insert',
            $ins,
            implode('', ['i', 's', 's', 's', 's', 'i', 'i']),
            $lyricsId,
            $kind,
            $label,
            $singerName,
            $gender,
            $musicianId,
            $sortOrder
        );
        $ins->execute();
        $resultId = (int)$db->insert_id;
        $ins->close();
    }

    $row = vocalPartsResolvePart($db, $songId, $resultId);
    if ($row === null) {
        /* Cannot happen on a normal path (we just wrote it under the same
           transaction) — defensive only, so a caller never gets a `null`
           shape back out of a function typed to return `array`. */
        throw new \RuntimeException('Vocal part not found for this song.');
    }
    $musicianName = null;
    if (!empty($row['MusicianId'])) {
        $mStmt = $db->prepare('SELECT Name FROM tblMusicians WHERE Id = ? LIMIT 1');
        bindParamSafe(__FUNCTION__ . ':musician-name', $mStmt, 'i', $row['MusicianId']);
        $mStmt->execute();
        $mRow = $mStmt->get_result()->fetch_assoc();
        $mStmt->close();
        $musicianName = $mRow['Name'] ?? null;
    }
    return vocalPartsShape($row, $musicianName !== null ? (string)$musicianName : null);
}

/**
 * ELI5: delete a vocal part and everything that names it — its line
 * assignments, its sub-line spans, its word overrides, and (via
 * `tblLyricRoundVoices.VocalPartId ON DELETE SET NULL`) it stops naming a
 * round's voice without deleting the round itself (an unnamed "Voice N" is
 * a fully legal row — see that table's own COMMENT).
 *
 * IDOR: ownership via `vocalPartsResolvePart()`.
 *
 * @throws \RuntimeException  not found for this song
 */
function vocalPartsDelete(\mysqli $db, string $songId, int $partId): bool
{
    $existing = vocalPartsResolvePart($db, $songId, $partId);
    if ($existing === null) {
        throw new \RuntimeException('Vocal part not found for this song.');
    }
    $stmt = $db->prepare('DELETE FROM tblVocalParts WHERE Id = ?');
    bindParamSafe(__FUNCTION__, $stmt, 'i', $partId);
    $stmt->execute();
    $affected = $stmt->affected_rows > 0;
    $stmt->close();
    return $affected;
}

/* --------------------------------------------------------------------
 * Line grain — the run gesture: put one or more parts on a set of lines.
 * -------------------------------------------------------------------- */

/**
 * ELI5: "these lines are sung by these voices" — a curator ticks a run of
 * lines, picks one or more parts (a duet is two parts on the same lines),
 * and presses Assign. ONE call, ALL lines and ALL parts, or none of it
 * (each statement below runs eagerly rather than inside its own nested
 * transaction — the CALLER is expected to already be inside one, exactly
 * like every other write in this file and in `line_enrichment.php`).
 *
 * IDOR (two layers): `vocalPartsResolveLines()` proves every line belongs
 * to THIS song's primary version (§2.2, unchanged); this function ALSO
 * proves every part belongs to that SAME lyrics version — a part minted on
 * a TTML-ingested version can never be pinned onto the curator's own lines,
 * and vice versa (§2.2's read-only sibling never had to enforce this
 * because it never crossed the two; a WRITE can, so it must check).
 *
 * OVERLAP / ABUT SEMANTICS (spelled out, because nothing is actually
 * STORED as a "run" — a run is always DERIVED at read time by
 * `vocalPartsDeriveRuns()`): assigning lines 5-8 to Men when lines 3-8 were
 * previously Women leaves 3-4 Women and 5-8 Men — two derived runs, no
 * separate "split the run" step needed. Assigning 9-12 to Men immediately
 * AFTER lines 5-8 are already Men derives as ONE run 5-12 (adjacency plus
 * an identical part set is ALL `vocalPartsDeriveRuns()` needs — it does not
 * know or care that two separate Assign calls produced it). A scattered,
 * non-adjacent `$lineIds` list is legal and simply derives as several runs.
 *
 * `$mode`:
 *   - `'replace'` (default) — DELETE any OTHER part currently on these
 *     lines with the SAME `$isBackground` class first (an echo mark on a
 *     line therefore survives a `replace` re-assignment of its LEAD voice,
 *     and a lead re-assignment survives a `replace` of its echo — the two
 *     classes never see each other's DELETEs; this is Pass 2 §2.4's
 *     "decision 6").
 *   - `'add'` — leaves whatever is already on these lines untouched and
 *     only adds the new part(s).
 * Either way the actual per-(line,part) write is
 * `INSERT ... ON DUPLICATE KEY UPDATE` against `uq_Line_Part
 * (LineId, VocalPartId)` — a part can never appear twice on one line (as
 * BOTH a voice and an echo of itself — meaningless — the DUPLICATE KEY just
 * flips `IsBackground` to whichever class this call asked for instead of
 * erroring, which is the correct, harmless behaviour rather than a bug to
 * "fix" by widening the unique key, which would be an ALTER — rule #20).
 *
 * @param list<int> $lineIds
 * @param list<int> $partIds  1..8 distinct part ids
 * @return array  `vocalPartsForSong()` — the WHOLE song's payload, per this
 *                 feature's read-back-the-truth convention (rule #35)
 * @throws \InvalidArgumentException  bad mode, empty/too-many partIds
 * @throws \RuntimeException          a line or part is not on this song's
 *                                     primary version, or a part is on a
 *                                     DIFFERENT lyrics version than the lines
 */
function vocalPartsAssignLines(
    \mysqli $db,
    string $songId,
    array $lineIds,
    array $partIds,
    string $mode = 'replace',
    bool $isBackground = false
): array {
    $mode = vocalPartsNormalizeAssignMode($mode);

    $partIdsNorm = array_values(array_unique(array_map('intval', $partIds)));
    if (!$partIdsNorm) {
        throw new \InvalidArgumentException('partIds must be a non-empty list.');
    }
    if (count($partIdsNorm) > 8) {
        throw new \InvalidArgumentException('partIds must not exceed 8 per call.');
    }

    $lines = vocalPartsResolveLines($db, $songId, $lineIds);   // IDOR + same-primary-version guard
    $lyricsId = null;
    foreach ($lines as $l) {
        $lyricsId = $l['lyricsId'];
        break;
    }

    foreach ($partIdsNorm as $pid) {
        $part = vocalPartsResolvePart($db, $songId, $pid);
        if ($part === null) {
            throw new \RuntimeException('One or more partIds do not belong to this song.');
        }
        if ((int)$part['LyricsId'] !== $lyricsId) {
            throw new \RuntimeException('A vocal part must belong to the same lyrics version as the lines it is assigned to.');
        }
    }

    $lineIdList = array_keys($lines);
    $bgInt = $isBackground ? 1 : 0;

    if ($mode === 'replace') {
        $place = implode(',', array_fill(0, count($lineIdList), '?'));
        $del = $db->prepare("DELETE FROM tblLyricLineVocalParts WHERE LineId IN ({$place}) AND IsBackground = ?");
        bindParamSafe(
            __FUNCTION__ . ':replace',
            $del,
            str_repeat('i', count($lineIdList)) . 'i',
            ...array_merge($lineIdList, [$bgInt])
        );
        $del->execute();
        $del->close();
    }

    $ins = $db->prepare(
        'INSERT INTO tblLyricLineVocalParts (LineId, VocalPartId, LyricsId, IsBackground, SortOrder)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE IsBackground = VALUES(IsBackground), SortOrder = VALUES(SortOrder)'
    );
    foreach ($lineIdList as $lineId) {
        foreach ($partIdsNorm as $i => $pid) {
            bindParamSafe(__FUNCTION__ . ':insert', $ins, 'iiiii', $lineId, $pid, $lyricsId, $bgInt, $i);
            $ins->execute();
        }
    }
    $ins->close();

    return vocalPartsForSong($db, $songId);
}

/**
 * ELI5: take a part (or every part) off a set of lines.
 *
 * `$isBackground`: `null` clears BOTH the voice AND the echo rows on these
 * lines (a full "start over" for this line); `true`/`false` clears only
 * that one background class, leaving the other untouched — the same
 * IsBackground-scoped-DELETE convention `vocalPartsAssignLines()`'s
 * `'replace'` mode uses, exposed here as its own action for a curator who
 * wants to clear WITHOUT immediately re-assigning something else.
 *
 * IDOR via `vocalPartsResolveLines()`.
 *
 * @param list<int> $lineIds
 * @return int  rows deleted
 */
function vocalPartsClearLines(\mysqli $db, string $songId, array $lineIds, ?bool $isBackground = null): int
{
    $lines = vocalPartsResolveLines($db, $songId, $lineIds);
    $lineIdList = array_keys($lines);
    if (!$lineIdList) {
        return 0;
    }

    $place = implode(',', array_fill(0, count($lineIdList), '?'));
    $sql   = "DELETE FROM tblLyricLineVocalParts WHERE LineId IN ({$place})";
    $types = str_repeat('i', count($lineIdList));
    $params = $lineIdList;
    if ($isBackground !== null) {
        $sql .= ' AND IsBackground = ?';
        $types .= 'i';
        $params[] = $isBackground ? 1 : 0;
    }
    $stmt = $db->prepare($sql);
    bindParamSafe(__FUNCTION__, $stmt, $types, ...$params);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    return $affected;
}

/* --------------------------------------------------------------------
 * Sub-line spans — the echo-inside-a-line grain.
 * -------------------------------------------------------------------- */

/**
 * ELI5: "only PART of this line is a different voice" — e.g. the closing
 * parenthetical of "Great is Thy faithfulness (He is my refuge)" sung by an
 * echo. Offsets are 0-based, end-exclusive UTF-8 CODE POINTS (rule #21 —
 * `mb_strlen`, never a byte or UTF-16 index).
 *
 * Input `{id?, lineId, partId, start, end, isBackground?, sortOrder?}`.
 * On an UPDATE (`id` given), `lineId`/`partId` are read from the EXISTING
 * row (§2.2's ownership resolvers still run against them — a caller cannot
 * "move" a span onto a different line/part by supplying new ids alongside
 * an existing span id; a moved span is a delete + a fresh create).
 *
 * @param array<string,mixed> $input
 * @return array  {id, lineId, partId, start, end, isBackground, sortOrder}
 * @throws \InvalidArgumentException  bad offsets (see `vocalPartsValidateSpanOffsets()`)
 * @throws \RuntimeException          `id` given but not found; line/part not
 *                                     on this song's primary version, or the
 *                                     part is on a different lyrics version
 */
function vocalPartsSpanUpsert(\mysqli $db, string $songId, array $input): array
{
    /* @lyrics-version-exempt: the JOIN through tblLyrics right below is an
       ownership/IDOR check on ONE already-known span id — like
       vocalPartsResolvePart()'s identical exemption above, it only needs
       to confirm the span's own lyrics version belongs to $songId at all,
       never which version is "current". The actual primary-version pin for
       this write is applied a few lines down, via vocalPartsResolveLines(). */
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'line_enrichment.php';   // lineEnrichmentValidateOffsets()

    $id = isset($input['id']) ? (int)$input['id'] : 0;
    $existingSpan = null;
    if ($id > 0) {
        $stmt = $db->prepare(
            'SELECT s.*, ll.LyricsId AS LineLyricsId
               FROM tblLyricLineVocalSpans s
               JOIN tblLyricLines ll ON ll.Id = s.LineId
               JOIN tblLyrics ly     ON ly.Id = ll.LyricsId
              WHERE s.Id = ? AND ly.SongId = ?
              LIMIT 1'
        );
        bindParamSafe(__FUNCTION__ . ':existing', $stmt, 'is', $id, $songId);
        $stmt->execute();
        $existingSpan = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($existingSpan === null) {
            throw new \RuntimeException('Vocal span not found for this song.');
        }
        $lineId = (int)$existingSpan['LineId'];
        $partId = (int)$existingSpan['VocalPartId'];
    } else {
        $lineId = (int)($input['lineId'] ?? 0);
        $partId = (int)($input['partId'] ?? 0);
    }

    $lines = vocalPartsResolveLines($db, $songId, [$lineId]);   // IDOR + same-primary-version guard
    $line  = $lines[$lineId];
    $part  = vocalPartsResolvePart($db, $songId, $partId);
    if ($part === null) {
        throw new \RuntimeException('Vocal part not found for this song.');
    }
    if ((int)$part['LyricsId'] !== $line['lyricsId']) {
        throw new \RuntimeException('A vocal part must belong to the same lyrics version as the line it is assigned to.');
    }

    [$start, $end] = vocalPartsValidateSpanOffsets((int)($input['start'] ?? 0), (int)($input['end'] ?? 0), $line['cpLen']);
    $isBackground = !empty($input['isBackground'] ?? ($input['bg'] ?? false));
    $sortOrder = isset($input['sortOrder']) ? max(0, (int)$input['sortOrder']) : 0;
    $bgInt = $isBackground ? 1 : 0;

    if ($id > 0) {
        $upd = $db->prepare('UPDATE tblLyricLineVocalSpans SET StartOffset = ?, EndOffset = ?, IsBackground = ?, SortOrder = ? WHERE Id = ?');
        bindParamSafe(__FUNCTION__ . ':update', $upd, 'iiiii', $start, $end, $bgInt, $sortOrder, $id);
        $upd->execute();
        $upd->close();
        $resultId = $id;
    } else {
        $ins = $db->prepare(
            "INSERT INTO tblLyricLineVocalSpans (LineId, VocalPartId, LyricsId, StartOffset, EndOffset, IsBackground, SortOrder, Source)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'ihymns')"
        );
        bindParamSafe(__FUNCTION__ . ':insert', $ins, 'iiiiiii', $lineId, $partId, $line['lyricsId'], $start, $end, $bgInt, $sortOrder);
        $ins->execute();
        $resultId = (int)$db->insert_id;
        $ins->close();
    }

    return [
        'id'           => $resultId,
        'lineId'       => $lineId,
        'partId'       => $partId,
        'start'        => $start,
        'end'          => $end,
        'isBackground' => $isBackground,
        'sortOrder'    => $sortOrder,
    ];
}

/**
 * IDOR via a JOIN to `tblSongs` through the span's own line — a span row
 * carries no `SongId` of its own, so ownership is proved the same way
 * `vocalPartsSpanUpsert()`'s UPDATE branch proves it.
 *
 * @throws \RuntimeException  not found for this song
 */
function vocalPartsSpanDelete(\mysqli $db, string $songId, int $spanId): bool
{
    /* @lyrics-version-exempt: same reasoning as vocalPartsResolvePart()'s
       own exemption — a specific $spanId is already known, so this JOIN
       through tblLyrics only proves the span belongs to a lyrics version
       of $songId at all, never which version is the song's "current" one. */
    $stmt = $db->prepare(
        'SELECT s.Id
           FROM tblLyricLineVocalSpans s
           JOIN tblLyricLines ll ON ll.Id = s.LineId
           JOIN tblLyrics ly     ON ly.Id = ll.LyricsId
          WHERE s.Id = ? AND ly.SongId = ?
          LIMIT 1'
    );
    bindParamSafe(__FUNCTION__ . ':resolve', $stmt, 'is', $spanId, $songId);
    $stmt->execute();
    $found = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    if (!$found) {
        throw new \RuntimeException('Vocal span not found for this song.');
    }
    $del = $db->prepare('DELETE FROM tblLyricLineVocalSpans WHERE Id = ?');
    bindParamSafe(__FUNCTION__ . ':delete', $del, 'i', $spanId);
    $del->execute();
    $affected = $del->affected_rows > 0;
    $del->close();
    return $affected;
}

/* --------------------------------------------------------------------
 * Ingest-only primitives — NO caller yet in this commit (the TTML-ingest
 * wiring is a later commit of the same plan); shipped now, proven by the
 * pure `vocalPartsKindFromTtmlAgent()` truth table above, so that commit
 * needs no NEW schema-touching function, only a caller. These bypass the
 * "primary ('ihymns') version only" pin `vocalPartsResolveLines()`
 * enforces — DELIBERATELY: an ingest writes onto the version it JUST
 * parsed (an `applemusic-ttml`/`openlyrics` row), never the curator's own,
 * and the curator-facing functions above must never be relaxed to allow
 * that (see this file's own "Re-ingest wipes assignments..." risk note in
 * the plan — the pin is what stops a curator's OWN edit ever landing on
 * the wrong version by mistake).
 * -------------------------------------------------------------------- */

/**
 * ELI5: the ingest-side twin of `vocalPartsAssignLines()` — same
 * `INSERT ... ON DUPLICATE KEY UPDATE` shape, but scoped directly to a
 * KNOWN `$lyricsId` (the version the ingest itself just created) rather
 * than resolved from a `$songId` + "which version is primary" lookup,
 * because for this caller the version is never in question — it is the one
 * the ingest is writing right now, always non-'ihymns'.
 *
 * No ownership resolvers here on purpose: the caller (a future
 * `lyrics_ingest.php`) already holds `$lyricsId` from having just inserted
 * the `tblLyrics` row itself, inside the SAME transaction — there is
 * nothing left to prove that a JOIN back through `tblLyrics`/`tblSongs`
 * would tell it that it does not already know.
 *
 * @param list<int> $partIds
 * @return int  rows written (INSERTed or updated)
 */
function vocalPartsAssignLinesForVersion(\mysqli $db, int $lyricsId, int $lineId, array $partIds, bool $isBackground): int
{
    $partIdsNorm = array_values(array_unique(array_map('intval', $partIds)));
    $bgInt = $isBackground ? 1 : 0;
    $ins = $db->prepare(
        'INSERT INTO tblLyricLineVocalParts (LineId, VocalPartId, LyricsId, IsBackground, SortOrder)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE IsBackground = VALUES(IsBackground), SortOrder = VALUES(SortOrder)'
    );
    $written = 0;
    foreach ($partIdsNorm as $i => $pid) {
        bindParamSafe(__FUNCTION__, $ins, 'iiiii', $lineId, $pid, $lyricsId, $bgInt, $i);
        $ins->execute();
        $written++;
    }
    $ins->close();
    return $written;
}

/**
 * ELI5: the WORD-grain twin of `vocalPartsAssignLinesForVersion()` — a
 * TTML word carries its OWN voice only when it differs from its line's (the
 * inherit rule this whole feature's schema comment states, and
 * `vocalPartsWordsForLines()`'s own read-side doc-block already documents
 * from the reading end); ingest-only, no editor consumer this commit (D2).
 *
 * Ownership: every `$wordIds` entry is checked to belong to `$lyricsId` in
 * ONE query — mirrors `vocalPartsResolveLines()`'s shape one level down
 * (word, not line) rather than re-deriving a bespoke check.
 *
 * @param list<int> $wordIds
 * @throws \RuntimeException  a word id does not belong to `$lyricsId`
 * @return int  rows written
 */
function vocalPartsAssignWords(\mysqli $db, int $lyricsId, array $wordIds, int $partId, bool $isBackground): int
{
    $ids = array_values(array_unique(array_map('intval', $wordIds)));
    if (!$ids) {
        return 0;
    }
    $place = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare(
        "SELECT w.Id FROM tblLyricWords w JOIN tblLyricLines l ON l.Id = w.LineId
          WHERE w.Id IN ({$place}) AND l.LyricsId = ?"
    );
    bindParamSafe(__FUNCTION__ . ':resolve', $stmt, str_repeat('i', count($ids)) . 'i', ...array_merge($ids, [$lyricsId]));
    $stmt->execute();
    $found = [];
    while ($row = $stmt->get_result()->fetch_row()) {
        $found[(int)$row[0]] = true;
    }
    $stmt->close();
    if (count($found) !== count($ids)) {
        throw new \RuntimeException('One or more wordIds do not belong to this lyrics version.');
    }

    $bgInt = $isBackground ? 1 : 0;
    $ins = $db->prepare(
        'INSERT INTO tblLyricWordVocalParts (WordId, VocalPartId, LyricsId, IsBackground, SortOrder)
         VALUES (?, ?, ?, ?, 0)
         ON DUPLICATE KEY UPDATE IsBackground = VALUES(IsBackground)'
    );
    $written = 0;
    foreach ($ids as $wordId) {
        bindParamSafe(__FUNCTION__ . ':insert', $ins, 'iiii', $wordId, $partId, $lyricsId, $bgInt);
        $ins->execute();
        $written++;
    }
    $ins->close();
    return $written;
}

/**
 * ELI5: after a re-ingest re-parses a TTML/OpenLyrics file's `<head>`
 * agents, remove any MACHINE-OWNED part whose agent id is no longer
 * present — a singer who was removed from the source file should not keep
 * a stale part around forever. A CURATOR's own `'ihymns'`-sourced part is
 * NEVER touched here (the `Source = ?` filter is exact-match, and a
 * curator part's `Source` is always `'ihymns'`, never the ingest's own
 * `$source` value).
 *
 * @param list<string> $keepAgentIds  every agent id the JUST-parsed file
 *                                     still defines/references
 * @return int  rows deleted
 */
function vocalPartsPruneAgents(\mysqli $db, int $lyricsId, string $source, array $keepAgentIds): int
{
    $keep = array_values(array_unique(array_filter(array_map('strval', $keepAgentIds), static fn($v) => $v !== '')));
    if (!$keep) {
        $stmt = $db->prepare(
            "DELETE FROM tblVocalParts WHERE LyricsId = ? AND Source = ? AND TtmlAgentId IS NOT NULL"
        );
        bindParamSafe(__FUNCTION__ . ':all', $stmt, 'is', $lyricsId, $source);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        return $affected;
    }
    $place = implode(',', array_fill(0, count($keep), '?'));
    $stmt = $db->prepare(
        "DELETE FROM tblVocalParts WHERE LyricsId = ? AND Source = ? AND TtmlAgentId IS NOT NULL AND TtmlAgentId NOT IN ({$place})"
    );
    bindParamSafe(__FUNCTION__, $stmt, 'is' . str_repeat('s', count($keep)), $lyricsId, $source, ...$keep);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    return $affected;
}

/* --------------------------------------------------------------------
 * The importer voices transport — the ONE seam `lyricLinesWriteComponents()`
 * calls (includes/lyric_lines_sync.php) once it has minted real line Ids.
 * -------------------------------------------------------------------- */

/**
 * ELI5: for ONE line inside a component whose `voices` key WAS provided,
 * decide what should happen to it — leave it alone, wipe its voice marks,
 * or replace them with a fresh list. PURE — no DB — so the tri-state
 * contract `vocalPartsApplyComponentVoices()`'s own doc-block describes can
 * be proven by a truth table (tests/php/test-vocal-parts-core.php) without a
 * live mysqli, the same reason every other decision in this file that CAN
 * be made without touching the database is its own small function rather
 * than inline logic buried inside a write function.
 *
 * #2073 commit 5 cross-review finding F3: the bug this function exists to
 * make impossible to reintroduce was exactly a MISSING case here — a
 * component-level `voices: null`/`[]` was treated the same as "this line's
 * own cell is absent", so both fell into the SAME `continue` inside
 * `vocalPartsApplyComponentVoices()`, and an explicit "clear this section's
 * voices" request silently did nothing (a CLEAR quietly became a PRESERVE).
 *
 * @param array<int,mixed>|null $cells  the component's OWN `voices` value,
 *        already normalised to null-or-array by the caller
 *        (`is_array($c['voices'] ?? null) ? $c['voices'] : null`) — never a
 *        bare scalar
 * @param int $li  this line's 0-based index within the component
 * @return array{action:string,cell?:list<mixed>}  `action` is one of
 *         'untouched' | 'clear' | 'set' ('set' carries the raw list to
 *         apply as `cell`)
 */
function vocalPartsVoiceCellAction(?array $cells, int $li): array
{
    if ($cells === null || $cells === []) {
        /* Component-level `voices: null`/`[]` -> every line is CLEARED,
           never left untouched. This branch IS the F3 fix — deleting it
           reproduces the exact bug the cross-review found. */
        return ['action' => 'clear'];
    }
    if (!array_key_exists($li, $cells)) {
        return ['action' => 'untouched'];   // a shorter array -> this ONE line untouched
    }
    $cell = $cells[$li];
    if ($cell === null || $cell === []) {
        return ['action' => 'clear'];
    }
    if (!is_array($cell)) {
        return ['action' => 'untouched'];   // malformed entry — best-effort transport, skip
    }
    return ['action' => 'set', 'cell' => $cell];
}

/**
 * ELI5: an importer only ever knows a line by its POSITION ("the 3rd line
 * of the 2nd component") because no `tblLyricLines.Id` exists until the
 * write actually happens — so it hands its voice data over as a plain
 * per-line, per-component array, and THIS function is the one place that
 * turns "position" into "real FK rows on a real Id", the instant
 * `lyricLinesWriteComponents()` (rule #22 — the ONE line-write path, never
 * a second one) has resolved that Id.
 *
 * CELL SEMANTICS (per line, exactly mirroring how `chords`/`notes` per-line
 * cells already behave one file over — #2072's `notesProvided`/
 * `chordsProvided`, generalised here to a THIRD outcome because unlike a
 * scalar column, `tblLyricLineVocalParts` rows can PRE-EXIST independently
 * of this write, so "say nothing" and "actively wipe it" are genuinely
 * different things a caller needs to be able to mean):
 *   - the component never carried a `voices` key at all
 *     (`voicesProvided === false`) -> every line in it is UNTOUCHED.
 *   - `voices` is present but `null`/`[]` -> EVERY line in the component is
 *     CLEARED (both voice and echo rows — a deliberate "wipe this
 *     section's voice marks").
 *   - `voices` is a per-line array and a given line's OWN cell is
 *     absent (the array is shorter than `lines`) -> that ONE line is
 *     UNTOUCHED.
 *   - that line's cell is `null`/`[]` -> that ONE line is CLEARED.
 *   - that line's cell is `list<{kind,label?,bg?}>` -> that line's voices
 *     are REPLACED with find-or-create'd parts for exactly those entries
 *     (an unrecognised `kind` string in one entry is skipped, best-effort —
 *     this is a bulk TRANSPORT for machine-authored data, not a
 *     curator-facing validated form; a malformed single entry must not
 *     abort an otherwise-good import).
 *
 * NEVER a second write path (rule #22): every actual row change goes
 * through `vocalPartsClearLines()` / `vocalPartsAssignLines()` above — this
 * function is purely the position -> Id -> cell-semantics glue.
 *
 * #2073 commit 5 cross-review finding F3 (fixed here): the ORIGINAL version
 * of this function treated a component-level `voices: null`/`[]` the SAME
 * as "this line's own cell is absent" — both fell into ONE `continue` that
 * left every line UNTOUCHED, so an explicit "clear this section's voices"
 * request silently did nothing (a component-level CLEAR degraded into a
 * PRESERVE, the opposite of what the caller asked for). Every per-line
 * decision below is delegated to `vocalPartsVoiceCellAction()` (pure, just
 * above) precisely so the three outcomes — untouched / clear / set — are a
 * truth table this repo can prove without a live mysqli
 * (tests/php/test-vocal-parts-core.php), rather than inline branching that
 * can quietly regress the same way again.
 *
 * @param array<int,array<string,mixed>> $norm         the SAME normalised
 *          components `lyricLinesWriteComponents()` built (each carries
 *          'lines', 'voices', 'voicesProvided')
 * @param array<int,array<int,int>>      $lineIdsByPos  [componentIndex][lineIndex] => tblLyricLines.Id
 * @return array{touched:int,cleared:int,created:int}
 */
function vocalPartsApplyComponentVoices(\mysqli $db, string $songId, array $norm, array $lineIdsByPos, string $source): array
{
    $touched = 0;
    $cleared = 0;
    $created = 0;

    foreach ($norm as $ci => $c) {
        if (empty($c['voicesProvided'])) {
            continue;   // said nothing about this component's voices -> untouched
        }
        $cells = is_array($c['voices'] ?? null) ? $c['voices'] : null;
        $lineCount = count($c['lines'] ?? []);
        $lyricsId = null;

        for ($li = 0; $li < $lineCount; $li++) {
            $lineId = (int)($lineIdsByPos[$ci][$li] ?? 0);
            if ($lineId <= 0) {
                continue;   // the line failed to resolve — nothing to attach to
            }

            $decision = vocalPartsVoiceCellAction($cells, $li);
            if ($decision['action'] === 'untouched') {
                continue;
            }
            if ($decision['action'] === 'clear') {
                $cleared += vocalPartsClearLines($db, $songId, [$lineId], null);
                $touched++;
                continue;
            }

            /* 'set' — replace this line's voices with find-or-create'd parts. */
            if ($lyricsId === null) {
                $lines = vocalPartsResolveLines($db, $songId, [$lineId]);
                $lyricsId = $lines[$lineId]['lyricsId'];
            }

            $byBackground = [false => [], true => []];
            foreach ($decision['cell'] as $spec) {
                if (!is_array($spec) || empty($spec['kind'])) {
                    continue;
                }
                $kind = vocalPartsNormalizeKind((string)$spec['kind']);
                if ($kind === null) {
                    continue;   // unrecognised word — skip this entry, best-effort transport
                }
                $label = vocalPartsNormalizeLabelInput($spec['label'] ?? null);
                $bg    = !empty($spec['bg']);
                $partId = vocalPartsFindOrCreate($db, $lyricsId, $kind, $label, $source);
                $created++;
                $byBackground[$bg][] = $partId;
            }

            /* This line's cell is a REPLACEMENT for the whole line — clear
               first, then add each background class present, so a cell
               that mentions only foreground parts also wipes any
               previously-stored echo on this same line (and vice versa). */
            vocalPartsClearLines($db, $songId, [$lineId], null);
            foreach ($byBackground as $bg => $partIds) {
                if ($partIds) {
                    vocalPartsAssignLines($db, $songId, [$lineId], $partIds, 'add', (bool)$bg);
                }
            }
            $touched++;
        }
    }

    return ['touched' => $touched, 'cleared' => $cleared, 'created' => $created];
}

/* --------------------------------------------------------------------
 * The bulk read-back payload — every write function above returns this
 * (rule #35: hand back the WHOLE truth, never let a caller trust its own
 * request as the new state).
 * -------------------------------------------------------------------- */

/**
 * ELI5: "everything there is to know about this song's voices, in one
 * call" — the ONE payload shape `VOCAL_PARTS_PAYLOAD_KEYS` (declared back
 * in commit 1) names, and every write function in this file returns.
 *
 * Every key is ALWAYS present; every list/map is empty (never omitted, never
 * an error) when the tables aren't migrated, when the song has no primary
 * ('ihymns') lyrics version yet, or when it simply has no vocal data — a
 * caller never has to guess which of those three is true from an absent
 * key, exactly like `vocalPartsShape()`'s own "always present" convention.
 *
 * `roundsReady`/`rounds` are resolved via `includes/lyric_rounds.php`,
 * lazily required here (rather than at this file's own top) so the two
 * files' mutual need of each other's functions (this file's line/part
 * ownership resolvers; that file's round reader) never has to become a
 * hard, order-sensitive `require_once` cycle at load time — see
 * `lyric_rounds.php`'s own header for the other half of this note.
 *
 * @return array{ready:bool,spansReady:bool,roundsReady:bool,lyricsId:?int,
 *               parts:list<array>,lineAssignments:array<int,list<array>>,
 *               spans:array<int,list<array>>,rounds:list<array>}
 */
function vocalPartsForSong(\mysqli $db, string $songId): array
{
    $empty = [
        'ready'           => false,
        'spansReady'      => false,
        'roundsReady'     => false,
        'lyricsId'        => null,
        'parts'           => [],
        'lineAssignments' => [],
        'spans'           => [],
        'rounds'          => [],
    ];

    $ready = vocalPartsTablesReady($db);
    if (!$ready) {
        return $empty;
    }
    $spansReady = vocalPartsSpansReady($db);

    $roundsReady = false;
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'lyric_rounds.php';
    if (function_exists('lyricRoundsReady')) {
        $roundsReady = lyricRoundsReady($db);
    }

    /* @lyrics-version-cache-ok: a plain read outside any transaction — this
       function runs no begin_transaction() of its own (every write below it
       in this file resolves its OWN lyricsId independently), so a "found"
       answer cached from earlier in the SAME request cannot be invalidated
       by a rollback the way lyricLinesEnsurePrimaryVersion()'s find-or-create
       must guard against (see lyricLinesPrimaryLyricsId()'s own "WHY A FOUND
       ROW..." doc-block for the read-vs-write distinction this marker lets a
       reviewer confirm — the identical reasoning vocalPartsResolveLines()'s
       own marker states one function above). */
    $lyricsId = lyricLinesPrimaryLyricsId($db, $songId);
    if ($lyricsId <= 0) {
        $empty['ready']       = $ready;
        $empty['spansReady']  = $spansReady;
        $empty['roundsReady'] = $roundsReady;
        return $empty;
    }

    $rounds = [];
    if ($roundsReady && function_exists('lyricRoundsForVersion')) {
        $rounds = lyricRoundsForVersion($db, $lyricsId);
    }

    return [
        'ready'           => $ready,
        'spansReady'      => $spansReady,
        'roundsReady'     => $roundsReady,
        'lyricsId'        => $lyricsId,
        'parts'           => vocalPartsForVersion($db, $lyricsId),
        'lineAssignments' => vocalPartsLinesMap($db, $lyricsId),
        'spans'           => $spansReady ? vocalPartsSpansMap($db, $lyricsId) : [],
        'rounds'          => $rounds,
    ];
}
