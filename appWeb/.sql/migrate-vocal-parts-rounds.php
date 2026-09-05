<?php

declare(strict_types=1);

/**
 * iHymns — Voice parts: echo spans, rounds/canon, review queue (#2073 commit 2)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5: this adds four EMPTY tables that a later commit of the same
 * feature (#2073) will start writing to. Nothing reads or writes them
 * yet — running this card is completely safe and changes nothing a user
 * or curator can see.
 *
 * PURPOSE — four dormant, additive tables that extend the #1137 vocal-parts
 * trio (`tblVocalParts` / `tblLyricLineVocalParts` / `tblLyricWordVocalParts`,
 * created by `migrate-vocal-parts.php` — this file makes **ZERO** changes to
 * any of the three; they are sufficient as-is):
 *
 *   - tblLyricLineVocalSpans  — a SUB-LINE echo or mid-line voice switch,
 *       e.g. "(he is my refuge)" spoken by a different voice than the rest
 *       of its line. Anchored on `tblLyricLines.Id` by a 0-based, EXCLUSIVE-
 *       end UTF-8 CODE-POINT offset pair (rule #21 of .claude/CLAUDE.md —
 *       never a byte or UTF-16 index; use `mb_strlen()`/`mb_substr()`).
 *
 *   - tblLyricRounds + tblLyricRoundVoices — a round / canon / partner-song
 *       definition over a run of lyric lines (the musical "sing this as a
 *       round in 3 parts" instruction), and one row per VOICE of that round
 *       carrying its own entry offset. Per "Design pass 7" of
 *       `.claude/vocal-parts-2073-plan.md` (§1, contradiction C2 — the
 *       synthesis's own correction of an earlier draft's shape, and the one
 *       this migration actually implements): `tblLyricRoundVoices`'s unique
 *       key is `(RoundId, VoiceNumber)` with a NULLABLE `VocalPartId`
 *       (`ON DELETE SET NULL`) — a round's voices are very often
 *       undifferentiated ("Voice 1", "Voice 2", "Voice 3"), so a voice
 *       existing does not require a registered vocal part to name it. Every
 *       voice ALWAYS carries an entry offset in LINES (`EntryLines`,
 *       `NOT NULL DEFAULT 0` — voice 1 always enters at 0) plus two
 *       additional, independently NULLable timing bases — `EntryBeats`
 *       (needs the round's own `Bpm` + `BeatsPerLine`) and `EntryMs` (needs
 *       timed subject lines) — reserved UP FRONT so a future timed-playback
 *       feature never needs a second migration to add a timing basis
 *       (rule #20's "design the final DDL up front" one-pass discipline).
 *
 *   - tblVocalPartSuggestions — the curator REVIEW QUEUE a later,
 *       still-to-come backfill batch (#2073 commit 14) will populate by
 *       scanning existing lyric text for voice-marker words ("MEN",
 *       "(echo)", "IN CANON") that pre-date this feature. Nothing writes to
 *       this table yet either — it ships empty, same as the other three.
 *
 * ZERO ALTERS to the #1137 trio (rule #20's "prefer extending the dormant
 * table" — there is nothing to extend here; the trio is untouched because
 * it is already sufficient for what these four new tables need to FK into).
 *
 * GROWABLE VOCABULARY IS VARCHAR, NEVER ENUM (rule #20): `Kind`,
 * `EndingMode`, `Form`, `PartKind`, `Status` on these four tables are all
 * app-validated `VARCHAR` columns. `tblVocalPartSuggestions.Confidence` in
 * particular is `VARCHAR(10)` — this is a DELIBERATE choice, not an
 * oversight: `tblSongLinkSuggestions.Confidence` is a grandfathered `ENUM`
 * (an earlier feature, predating rule #20's adoption) and copying its shape
 * here would silently re-introduce the exact "value-add is an ALTER"
 * problem rule #20 exists to prevent for every NEW growable-vocabulary
 * column from this point forward.
 *
 * CROSS-REVIEW FIXES (before this file's first commit — free to fix while
 * the tables are still dormant and unshipped, rule #20's whole point):
 * an independent review of this DDL found six things that would have
 * forced a SECOND migration later, plus one design question left
 * unresolved. All are fixed HERE, in the one-pass DDL, not deferred:
 *   F1 — tblVocalPartSuggestions could only record what Accept APPLIED
 *        (AppliedJson), never what the detector PROPOSES, and one finding
 *        often needs several actions (e.g. "assign the part" AND "delete
 *        the marker line"). Fixed with the new `ProposedJson` column —
 *        see its own COMMENT.
 *   F2 — `uq_Marker_Form (MarkerLineId, Form)` was simultaneously too
 *        TIGHT (two same-form markers on one line couldn't get separate
 *        rows) and too LOOSE (MySQL treats every NULL as distinct, and
 *        MarkerLineId goes NULL post-accept via its own `ON DELETE SET
 *        NULL`, so nothing then stopped unlimited duplicate NULL rows).
 *        Fixed with the new `DetectionLineId` (a plain, non-FK snapshot
 *        that survives MarkerLineId going NULL) + `MarkerOffset` columns
 *        and a new `uq_Detection (DetectionLineId, Form, MarkerOffset)` —
 *        see their own COMMENTs.
 *   F3 — three entry-offset columns on `tblLyricRoundVoices` (lines /
 *        beats / ms) with no declared winner when more than one is
 *        populated, and `EntryLines = 0` indistinguishable from "not
 *        set". Fixed with the new `EntryBasis` column (VARCHAR, never
 *        ENUM) naming the authoritative one. Also: `EntryBeats` widened
 *        from `DECIMAL(8,2)` to `DECIMAL(8,3)` (a triplet — 1/3 beat — has
 *        no EXACT finite-decimal representation in any base-10 scale;
 *        3 places keeps the rounding error under half a millisecond even
 *        at a fast tempo, which is the real ceiling this congregational
 *        display needs, not a DAW's), kept SIGNED rather than UNSIGNED
 *        (MySQL deprecated UNSIGNED on DECIMAL/FLOAT/DOUBLE in 8.0.17; a
 *        negative offset is musically invalid and is rejected by the APP,
 *        the same "VARCHAR + app-validated" discipline rule #20 already
 *        applies to vocabulary, applied here to a numeric range instead).
 *   F4 — deleting a line can silently change a round's musical meaning
 *        (its END line vanishing quietly turns it into a single-line
 *        subject; one endpoint of a partner-song span surviving without
 *        its "both or neither" partner; an EndingMode='coda' row
 *        outliving its own coda span). `ON DELETE SET NULL` on those FKs
 *        already degrades to a well-defined, non-corrupt state rather
 *        than a dangling reference — kept unchanged, and RESTRICT was
 *        rejected because it would fail ordinary, unrelated-feature line
 *        edits with a cryptic FK error from a table most curators don't
 *        know exists. What was missing was DISCOVERABILITY: the new
 *        `tblLyricRounds.IntegrityStatus` column (VARCHAR, 'ok' by
 *        default) gives a future delete-time repair hook — extending
 *        `lyricLinesSnapshotDeletedEnrichment()` in `lyric_lines_sync.php`
 *        per the #2073 plan, OUT OF THIS FILE'S SCOPE (owned by another
 *        agent on this branch) — somewhere to record "this round now
 *        needs a curator's attention" instead of leaving a row that looks
 *        healthy but silently isn't. `tblVocalPartSuggestions` already had
 *        its own answer to this same class of problem before this
 *        review — `Status='stale'` — so no new column was needed there;
 *        its COMMENT now says so explicitly.
 *   F5 — nothing stops a round (or a suggestion, or a span) from pointing
 *        at lines belonging to a DIFFERENT lyrics version / song than its
 *        own `LyricsId` column claims — MySQL cannot express "these two
 *        FK'd columns must resolve to the same LyricsId" without a
 *        composite FK against a new `UNIQUE(Id, LyricsId)` on the
 *        shipped, heavily-used `tblLyricLines` table — a change to a
 *        live table far outside this migration's four-dormant-tables
 *        scope, and disproportionate given the fix already exists at the
 *        application layer: `vocalPartsResolveLines()` in
 *        `vocal_parts.php` already enforces "every line id resolves to
 *        the SAME primary LyricsId" today, and the #1137 trio's own
 *        `LyricsId` columns already document the identical convention
 *        ("app derives from the line, never the caller"). Every `LyricsId`
 *        column below now documents this invariant explicitly so the
 *        future write-side commit (`includes/lyric_rounds.php`) can't
 *        miss it.
 *   F6 — verified, not fixed: every FK column below was checked against
 *        its parent's PK type/signedness (tblLyricLines.Id is BIGINT
 *        UNSIGNED, tblLyrics.Id / tblVocalParts.Id are INT UNSIGNED,
 *        tblSongs.SongId is VARCHAR(20)) and every one already matches —
 *        there was no actual mismatch to fix. What WAS fixed: this file's
 *        two helper functions are now `function_exists()`-guarded against
 *        a hypothetical double-`require` (this file changes the process-
 *        global `mysqli_report()` mode too, which is idempotent to
 *        re-apply, but a bare re-declared `function` is a hard fatal).
 *   SOLO — `soloist`'s marker word "SOLO" collides with the pre-existing
 *        `tblSongPartTypes` section literally named "Solo" (an
 *        instrumental break). Resolved in `vocal_parts.php`: a bare SOLO
 *        marker is always proposed as the `soloist` VOICE part (deciding
 *        sections is a different system's job) but the future detector
 *        MUST force `Confidence='low'` on it — see
 *        `IHYMNS_VOCAL_AMBIGUOUS_SECTION_MARKERS` there.
 *
 * IDEMPOTENT + STRICTLY ADDITIVE: each of the four `CREATE TABLE`s is
 * fronted by its own `_migVPR_tableExists()` probe, so re-running this
 * script after a partial or full apply prints `[SKIP]` lines and changes
 * nothing already there. The four probes are INDEPENDENT (mirrors
 * `migrate-vocal-parts.php`'s own three-probe shape) — a run that died
 * partway through resumes correctly on the next attempt.
 *
 * PRE-FLIGHT: every FK target this file needs (`tblVocalParts`,
 * `tblLyricLines`, `tblLyrics`, `tblSongs`, `tblMusicians`) is checked to
 * exist BEFORE any `CREATE TABLE` runs; a missing one aborts with one
 * `[ERROR]` line pointing at the "Vocal / singing parts (#1137)" card
 * rather than letting MySQL raise an opaque "cannot add foreign key
 * constraint" partway through the batch.
 *
 * NO SHARED INCLUDE NEEDED (rule #41 is therefore moot for this file, not
 * silently ignored): this is pure DDL over a connection this script opens
 * itself from `.auth/db_credentials.php`, exactly like `migrate-vocal-
 * parts.php` and `migrate-add-webhooks.php` — there is no
 * `require_once .../public_html/includes/...` anywhere in this file for
 * the deployed-docroot-rename trap (#1695) to catch. Were a shared include
 * ever needed later, it would resolve via `IHYMNS_INCLUDES_DIR` (the
 * runner-defined constant), never a hardcoded `/public_html/` literal —
 * see rule #41's full explanation in .claude/CLAUDE.md.
 *
 * SCHEMA MIRROR: all four `CREATE TABLE` blocks below are mirrored
 * BYTE-IDENTICALLY (COMMENT text included) in `appWeb/.sql/schema.sql`,
 * directly after the `tblLyricWordVocalParts` block in the existing
 * "VOCAL / SINGING PARTS (#1137)" section — rule #19's requirement that a
 * fresh install (which reads schema.sql) and a migrated install (which ran
 * this file) are structurally indistinguishable. CI enforces both
 * directions: `tests/php/test-schema-coverage.php` (presence) and
 * `tests/php/test-schema-ddl-parity.php` (full column/index/FK/COMMENT
 * shape — the #2077-class drift that test exists to catch).
 *
 * REGISTRY: `manage/includes/migration-registry.php` gets one new
 * `'vocal-parts-rounds'` entry with a MULTI-OBJECT OR-PROBE over all four
 * tables (rule #19 — a partial apply must never show the dashboard card
 * green); see that file for the entry and for this same commit's
 * unrelated fix to the neighbouring `'vocal-parts'` card's stale prose
 * ("reusing tblCreditPeople" -> "reusing tblMusicians", #2077).
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-vocal-parts-rounds.php
 *   Web:  /manage/setup-database -> "Run Voice Parts (Rounds) Migration" button
 *
 * @see .claude/vocal-parts-2073-plan.md   "Design pass 7" §2 (the DDL this file implements verbatim)
 * @see appWeb/.sql/migrate-vocal-parts.php   the #1137 trio this file extends without altering
 * @requires PHP 8.1+ with mysqli
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}
/* ELI5: "have these two little helpers already been defined?" — F6 of the
   cross-review: PHP fatals HARD on a re-declared `function` (unlike the
   process-global `mysqli_report()` mode change just below, which is
   harmless to re-apply). Nothing in this codebase's runner currently
   `require`s (rather than `require_once`) the SAME migration script twice
   in one process — so this was never observed to fail in practice — but
   guarding a plain top-level `function` declaration costs nothing and
   removes the possibility outright, the same defensive shape
   `db_mysql.php`-style shared includes use for THEIR functions. */
if (!function_exists('_migVPR_output')) {
    function _migVPR_output(string $m): void
    {
        global $isCli;
        echo $m . ($isCli ? "\n" : "<br>\n");
        if (!$isCli) {
            flush();
        }
    }
}

if (!function_exists('_migVPR_tableExists')) {
    /* ELI5: "does this table already exist?" — bind_param + INFORMATION_SCHEMA,
       not `SHOW TABLES LIKE` (that takes a LIKE *pattern*; every name passed
       here is app-controlled so it is not a real injection risk either way,
       but INFORMATION_SCHEMA + a bound parameter has no LIKE-metacharacter
       caveat at all, so this file uses that shape throughout — the same
       pattern migrate-add-webhooks.php uses).
       https://dev.mysql.com/doc/refman/8.0/en/information-schema-tables-table.html */
    function _migVPR_tableExists(\mysqli $db, string $t): bool
    {
        $stmt = $db->prepare(
            'SELECT 1 FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
        );
        $stmt->bind_param('s', $t);
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();
        return $exists;
    }
}

/* This migration opens its OWN connection straight from the credentials
   file rather than going through includes/db_mysql.php::getDbMysqli() —
   the deliberate split rule #41's doc-block and migrate-vocal-parts.php's
   own header both describe: a script that connects for itself is runnable
   even on an install whose public_html/ bootstrap isn't loadable, and
   because the handle is its own, it may safely close() it at the end. */
$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) {
    _migVPR_output('ERROR: MySQL credentials not found. Run install.php first.');
    return;
}
require_once $credFile;

_migVPR_output('');
_migVPR_output('=== iHymns — Voice parts: echo spans, rounds/canon, review queue (#2073) ===');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    $mysql->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
} catch (\mysqli_sql_exception $e) {
    _migVPR_output('ERROR: MySQL connection failed: ' . $e->getMessage());
    return;
}
_migVPR_output('Connected to MySQL: ' . DB_NAME);

try {
    /* PRE-FLIGHT: every table these four CREATEs FK-reference must already
       exist, or MySQL fails the FK with an opaque errno 150/1215 partway
       through the batch. Reporting all missing dependencies in ONE clear
       line, before touching the database at all, is far more actionable
       for whoever is running this from the dashboard than a raw MySQL
       error on (say) the third of four CREATEs. */
    $required = ['tblVocalParts', 'tblLyricLines', 'tblLyrics', 'tblSongs', 'tblMusicians'];
    $missing = [];
    foreach ($required as $t) {
        if (!_migVPR_tableExists($mysql, $t)) {
            $missing[] = $t;
        }
    }
    if ($missing) {
        _migVPR_output(
            '  [ERROR] Missing required table(s): ' . implode(', ', $missing)
            . '. Run the "Vocal / singing parts (#1137)" migration card first.'
        );
        $mysql->close();
        return;
    }

    /* --- 1. tblLyricLineVocalSpans — sub-line echo / mid-line voice switch --- */
    if (_migVPR_tableExists($mysql, 'tblLyricLineVocalSpans')) {
        _migVPR_output('  [SKIP] tblLyricLineVocalSpans already present.');
    } else {
        $mysql->query(
            "CREATE TABLE tblLyricLineVocalSpans (
                Id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                LineId       BIGINT UNSIGNED NOT NULL,
                VocalPartId  INT UNSIGNED    NOT NULL,
                LyricsId     INT UNSIGNED    NOT NULL COMMENT 'Denorm of tblLyricLines.LyricsId; app derives from the line, never the caller. VocalPartId must also resolve to a tblVocalParts row on this SAME LyricsId (F5) — never a DB constraint, same convention as the #1137 trio''s own denorm LyricsId columns',
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
              COMMENT='Sub-line vocal-part / echo span, code-point anchored on tblLyricLines.Id (#2073).'"
        );
        _migVPR_output('  [OK] Created tblLyricLineVocalSpans.');
    }

    /* --- 2. tblLyricRounds — round / canon / partner-song definition --- */
    if (_migVPR_tableExists($mysql, 'tblLyricRounds')) {
        _migVPR_output('  [SKIP] tblLyricRounds already present.');
    } else {
        $mysql->query(
            "CREATE TABLE tblLyricRounds (
                Id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
                LyricsId        INT UNSIGNED    NOT NULL COMMENT 'StartLineId/EndLineId/CodaStartLineId/CodaEndLineId must each resolve to a tblLyricLines row whose OWN LyricsId equals this column — app-enforced (F5), never a DB constraint; same convention as the #1137 trio''s denorm LyricsId columns',
                Kind            VARCHAR(20)     NOT NULL DEFAULT 'round' COMMENT 'round | canon | partner-song (app-validated vs IHYMNS_ROUND_KINDS; VARCHAR not ENUM)',
                Label           VARCHAR(120)    NULL DEFAULT NULL,
                StartLineId     BIGINT UNSIGNED NOT NULL COMMENT 'First subject line (version order)',
                EndLineId       BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'Last subject line inclusive; NULL = the StartLineId line only. Deleting this line degrades a round to a single-line subject WITHOUT deleting the round (ON DELETE SET NULL is a well-defined state, not corruption) — but it is a silent semantic change nobody asked for, so the delete-time repair hook (extends lyricLinesSnapshotDeletedEnrichment() in lyric_lines_sync.php per the #2073 plan) must flip IntegrityStatus to ''needs-review'' when this happens (F4)',
                TimesThrough    TINYINT UNSIGNED NOT NULL DEFAULT 2 COMMENT '1..8 — how many times the subject is sung by each voice',
                EndingMode      VARCHAR(16)     NOT NULL DEFAULT 'complete' COMMENT 'complete | together | coda (app-validated vs IHYMNS_ROUND_ENDINGS)',
                CodaStartLineId BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'Coda span sung in unison after the round (EndingMode=coda). If EndingMode stays ''coda'' after this (or CodaEndLineId) goes NULL via a line delete, the round claims a coda it no longer has — the same repair hook flips IntegrityStatus to ''needs-review'' rather than leaving that silent (F4)',
                CodaEndLineId   BIGINT UNSIGNED NULL DEFAULT NULL,
                Bpm             DECIMAL(6,2)    NULL DEFAULT NULL COMMENT 'Tempo for the beats basis',
                BeatsPerBar     TINYINT UNSIGNED NULL DEFAULT NULL,
                BeatsPerLine    DECIMAL(6,2)    NULL DEFAULT NULL COMMENT 'Beats one subject line occupies (beats basis)',
                IntegrityStatus VARCHAR(16)     NOT NULL DEFAULT 'ok' COMMENT 'ok | needs-review (app-validated, VARCHAR not ENUM, rule #20) — discoverability flag for the F4 \"a line delete can silently change this round''s meaning\" class of problem (see EndLineId / CodaStartLineId / tblLyricRoundVoices.EndLineId COMMENTs): a delete that degrades this round sets this instead of leaving the row looking healthy. Set by the future repair hook, not by this migration',
                SortOrder       INT UNSIGNED    NOT NULL DEFAULT 0,
                Source          VARCHAR(100)    NOT NULL DEFAULT 'ihymns',
                MetaJson        JSON            NULL DEFAULT NULL,
                CreatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UpdatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_Lyrics (LyricsId, SortOrder), INDEX idx_Start (StartLineId), INDEX idx_Integrity (IntegrityStatus),
                CONSTRAINT fk_Rounds_Lyrics    FOREIGN KEY (LyricsId)        REFERENCES tblLyrics(Id)     ON DELETE CASCADE  ON UPDATE CASCADE,
                CONSTRAINT fk_Rounds_Start     FOREIGN KEY (StartLineId)     REFERENCES tblLyricLines(Id) ON DELETE CASCADE  ON UPDATE CASCADE,
                CONSTRAINT fk_Rounds_End       FOREIGN KEY (EndLineId)       REFERENCES tblLyricLines(Id) ON DELETE SET NULL ON UPDATE CASCADE,
                CONSTRAINT fk_Rounds_CodaStart FOREIGN KEY (CodaStartLineId) REFERENCES tblLyricLines(Id) ON DELETE SET NULL ON UPDATE CASCADE,
                CONSTRAINT fk_Rounds_CodaEnd   FOREIGN KEY (CodaEndLineId)   REFERENCES tblLyricLines(Id) ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='A round / canon / partner-song over a run of lyric lines, per lyrics version (#2073 D1).'"
        );
        _migVPR_output('  [OK] Created tblLyricRounds.');
    }

    /* --- 3. tblLyricRoundVoices — one row per voice of a round --- */
    if (_migVPR_tableExists($mysql, 'tblLyricRoundVoices')) {
        _migVPR_output('  [SKIP] tblLyricRoundVoices already present.');
    } else {
        $mysql->query(
            "CREATE TABLE tblLyricRoundVoices (
                Id                INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
                RoundId           INT UNSIGNED    NOT NULL,
                VoiceNumber       TINYINT UNSIGNED NOT NULL COMMENT '1..N contiguous; voice 1 always enters at 0',
                VocalPartId       INT UNSIGNED    NULL DEFAULT NULL COMMENT 'Optional registry part singing this voice; NULL = an unnamed \"Voice N\". Must belong to the SAME LyricsId as this voice''s own round (tblLyricRounds.LyricsId) — app-enforced (F5), never a DB constraint',
                Label             VARCHAR(120)    NULL DEFAULT NULL,
                EntryBasis        VARCHAR(10)     NOT NULL DEFAULT 'lines' COMMENT 'lines | beats | ms — WHICH of EntryLines/EntryBeats/EntryMs is authoritative for this voice when more than one is populated (app-validated vs IHYMNS_ROUND_ENTRY_BASES, VARCHAR not ENUM, rule #20). EntryLines is always populated so ''lines'' never needs a null-check; when this is ''beats''/''ms'' the matching column below is authoritative INSTEAD and EntryLines'' own stored value (even 0, its default) is not meaningful — resolves the \"0 vs not set\" ambiguity (F3) by making the reader consult this column first rather than guessing from a bare 0',
                EntryLines        SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Entry offset in subject LINES; authoritative only when EntryBasis=''lines'' (always true for voice 1, which enters at 0)',
                EntryBeats        DECIMAL(8,3)    NULL DEFAULT NULL COMMENT 'Entry offset in beats; authoritative only when EntryBasis=''beats'' (needs round Bpm+BeatsPerLine). 3 decimal places, not 2 (F3): no finite decimal scale is ever EXACT for a triplet (1/3 beat repeats forever in base 10) — millibeat precision keeps that rounding error under half a millisecond even at a fast tempo, the real ceiling for a congregational display, not a DAW. Kept SIGNED, not UNSIGNED: MySQL deprecated the UNSIGNED attribute on DECIMAL/FLOAT/DOUBLE in 8.0.17, so a negative offset (musically invalid — no voice ever enters before voice 1''s beat 0) is rejected by the APP, not the column type (rule #20''s \"VARCHAR + app-validated\" discipline, applied to a numeric range instead of a vocabulary)',
                EntryMs           INT UNSIGNED    NULL DEFAULT NULL COMMENT 'Entry offset in ms; authoritative only when EntryBasis=''ms'' (needs timed subject lines)',
                IntervalSemitones TINYINT         NULL DEFAULT NULL COMMENT 'Canon at an interval (e.g. 7 = at the fifth); NULL = unison',
                StartLineId       BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'Partner-song: this voice''s OWN subject span (both or neither — see EndLineId). Must resolve to the SAME LyricsId as this voice''s round (F5)',
                EndLineId         BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'Partner-song span end. If either this or StartLineId goes NULL via a line delete while the other survives, the \"both or neither\" invariant breaks silently — the repair hook (see tblLyricRounds.IntegrityStatus) must flip the PARENT round''s IntegrityStatus to ''needs-review'' (F4) rather than leave a half-span voice looking normal',
                TimesThrough      TINYINT UNSIGNED NULL DEFAULT NULL COMMENT 'Per-voice override of the round''s TimesThrough',
                SortOrder         INT UNSIGNED    NOT NULL DEFAULT 0,
                UNIQUE KEY uq_Round_Voice (RoundId, VoiceNumber),
                INDEX idx_Part (VocalPartId),
                CONSTRAINT fk_RoundV_Round FOREIGN KEY (RoundId)     REFERENCES tblLyricRounds(Id) ON DELETE CASCADE  ON UPDATE CASCADE,
                CONSTRAINT fk_RoundV_Part  FOREIGN KEY (VocalPartId) REFERENCES tblVocalParts(Id)  ON DELETE SET NULL ON UPDATE CASCADE,
                CONSTRAINT fk_RoundV_Start FOREIGN KEY (StartLineId) REFERENCES tblLyricLines(Id)  ON DELETE SET NULL ON UPDATE CASCADE,
                CONSTRAINT fk_RoundV_End   FOREIGN KEY (EndLineId)   REFERENCES tblLyricLines(Id)  ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='One row per voice of a round: entry offset in lines / beats / ms (#2073 D1).'"
        );
        _migVPR_output('  [OK] Created tblLyricRoundVoices.');
    }

    /* --- 4. tblVocalPartSuggestions — curator review queue for the D4 backfill --- */
    if (_migVPR_tableExists($mysql, 'tblVocalPartSuggestions')) {
        _migVPR_output('  [SKIP] tblVocalPartSuggestions already present.');
    } else {
        $mysql->query(
            "CREATE TABLE tblVocalPartSuggestions (
                Id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
                SongId          VARCHAR(20)     NOT NULL,
                LyricsId        INT UNSIGNED    NOT NULL COMMENT 'Every one of MarkerLineId/StartLineId/EndLineId must resolve to a tblLyricLines row whose OWN LyricsId equals this column — app-enforced (F5), never a DB constraint; mirrors vocalPartsResolveLines()''s existing same-version guard and the #1137 trio''s own denorm-LyricsId convention',
                MarkerLineId    BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'The LIVE FK to the line carrying the marker text; goes NULL once Accept deletes/rewrites that line (ON DELETE SET NULL). DetectionLineId below, not this column, is the finding''s STABLE identity (F2) — this column is for joining to the CURRENT line text while it still exists',
                DetectionLineId BIGINT UNSIGNED NOT NULL COMMENT 'Plain snapshot of the marker line''s Id AT DETECTION TIME — deliberately NOT itself a foreign key, so it is unaffected once MarkerLineId (above) goes NULL. Combined with Form + MarkerOffset this is the finding''s STABLE detection identity (F2): a re-run of an improved detector against the SAME real occurrence supersedes (UPDATEs, via INSERT...ON DUPLICATE KEY) the SAME row instead of colliding with an unrelated one, while two markers of the same Form on the SAME line (distinguished by MarkerOffset) get two independent rows — the old uq_Marker_Form (MarkerLineId, Form) key was both too TIGHT (couldn''t tell those two apart) and too LOOSE (MySQL treats every NULL as distinct, so once MarkerLineId went NULL nothing stopped unlimited duplicate rows)',
                MarkerOffset    INT UNSIGNED    NOT NULL COMMENT '0-based UTF-8 code-point offset of MarkerText''s start within the marker line at detection time (rule #21: never byte/UTF-16) — the discriminator (F2) that lets two same-Form markers on one line (e.g. two parenthetical asides) coexist as separate rows',
                StartLineId     BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'First line the part applies to (standalone form: the run after the marker; prefix/paren: the marker line itself); must resolve to the SAME LyricsId as this row''s own LyricsId (F5)',
                EndLineId       BIGINT UNSIGNED NULL DEFAULT NULL,
                Form            VARCHAR(20)     NOT NULL COMMENT 'standalone | prefix | paren | canon-note (IHYMNS_VOCAL_DETECT_FORMS)',
                MarkerText      VARCHAR(120)    NOT NULL,
                PartKind        VARCHAR(30)     NOT NULL COMMENT 'Proposed IHYMNS_VOCAL_PART_KINDS key (the PRIMARY action in ProposedJson, projected here for cheap list filtering/display — see ProposedJson); canon-note rows use ''all'' and propose a round. A marker in IHYMNS_VOCAL_AMBIGUOUS_SECTION_MARKERS (e.g. SOLO) still proposes its voice-part kind here, never a section — see Confidence',
                Label           VARCHAR(120)    NULL DEFAULT NULL,
                IsBackground    TINYINT(1)      NOT NULL DEFAULT 0,
                ProposedJson    JSON            NOT NULL COMMENT 'The ordered, COMPLETE list of actions Accept must run for this ONE finding (F1) — e.g. \"assign the Women part to lines 3-4\" AND \"delete the marker line\" are typically BOTH needed, which neither a single action nor PartKind/Label/IsBackground/StartLineId/EndLineId alone could represent. Each item is {action, ...detail}; \"action\" is app-validated against a central map, never a DB ENUM (rule #20). AppliedJson (below) mirrors this exact shape once Accept has actually run it — that symmetry is what lets Undo reverse it precisely',
                Confidence      VARCHAR(10)     NOT NULL DEFAULT 'medium' COMMENT 'high | medium | low (VARCHAR, never the grandfathered ENUM shape). A marker word in IHYMNS_VOCAL_AMBIGUOUS_SECTION_MARKERS (e.g. SOLO, which also names a real tblSongPartTypes section) is always forced to ''low'' here — English text alone cannot tell the two apart, so only a curator can (the SOLO decision)',
                Status          VARCHAR(20)     NOT NULL DEFAULT 'pending' COMMENT 'pending | accepted | dismissed | undone | stale — stale is set when re-validation finds the marker line changed or vanished out from under a still-pending row; this table''s own answer to the F4 \"a line delete can silently invalidate a finding\" problem, chosen over a DB-level FK trick because a suggestion must SURVIVE its marker line''s eventual deletion to keep its review history',
                DetectorVersion SMALLINT UNSIGNED NOT NULL DEFAULT 1,
                AppliedJson     JSON            NULL DEFAULT NULL COMMENT 'What accept did (part id, line ids, removed/rewritten marker) so Undo can reverse it exactly — the executed twin of ProposedJson above',
                ReviewedBy      INT UNSIGNED    NULL DEFAULT NULL,
                ReviewedAt      DATETIME        NULL DEFAULT NULL,
                CreatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UpdatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_Detection (DetectionLineId, Form, MarkerOffset),
                INDEX idx_Marker (MarkerLineId), INDEX idx_Song_Status (SongId, Status), INDEX idx_Status_Conf (Status, Confidence),
                CONSTRAINT fk_VPS_Song   FOREIGN KEY (SongId)       REFERENCES tblSongs(SongId)  ON DELETE CASCADE  ON UPDATE CASCADE,
                CONSTRAINT fk_VPS_Lyrics FOREIGN KEY (LyricsId)     REFERENCES tblLyrics(Id)     ON DELETE CASCADE  ON UPDATE CASCADE,
                CONSTRAINT fk_VPS_Marker FOREIGN KEY (MarkerLineId) REFERENCES tblLyricLines(Id) ON DELETE SET NULL ON UPDATE CASCADE,
                CONSTRAINT fk_VPS_Start  FOREIGN KEY (StartLineId)  REFERENCES tblLyricLines(Id) ON DELETE SET NULL ON UPDATE CASCADE,
                CONSTRAINT fk_VPS_End    FOREIGN KEY (EndLineId)    REFERENCES tblLyricLines(Id) ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Curator review queue for voice-part markers found in lyric text by the shared detector (#2073 D4 / #1260).'"
        );
        _migVPR_output('  [OK] Created tblVocalPartSuggestions.');
    }

    _migVPR_output('  Tables ship empty; nothing in the app reads or writes them yet (#2073 commits 1-2 — dormant, verified no-op).');
    _migVPR_output('Migration complete.');
/* This catch SWALLOWS the failure (prints [ERROR], falls through to a
   normal return) exactly like migrate-vocal-parts.php's own catch —
   setup-database.php re-runs the registry probe after every migration, so
   a script that "finished" without creating its objects is correctly
   reported as "ran but is STILL PENDING". */
} catch (\Throwable $e) {
    _migVPR_output('  [ERROR] ' . $e->getMessage());
}
/* Safe here only because this connection is this script's own (see the
   $credFile note above) — never add this line to a getDbMysqli() migration. */
$mysql->close();
/* `return`, not `exit` — the dashboard `require`s this file inside an
   ob_start() block and frames the captured output with a
   STATUS:/ACTION:/ELAPSED_MS: header; exit() would end the request before
   that header is written. */
return;
