<?php

declare(strict_types=1);

/**
 * iHymns — Per-line lyric enrichment: translations + annotations (#1088)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Two line-grain enrichment tables anchored on tblLyricLines.Id (BIGINT),
 * siblings to tblLyricWords/tblLyricSyllables:
 *   - tblLyricLineTranslations — per-line meaning TRANSLATION + TRANSLITERATION
 *     (romanization). Models the Apple Music TTML <translation>/<transliteration>
 *     head tracks: a line may carry both kinds, into many target languages, from
 *     several providers — Kind + TargetLanguage + Source form the natural key,
 *     IsPrimary picks the preferred display row.
 *   - tblLyricLineAnnotations — per-span explanatory gloss/footnote (Genius
 *     referent+annotation model). Span = StartLineId (+ optional EndLineId) with
 *     optional character offsets for sub-line phrase highlighting.
 *
 * Distinct from tblSongTranslations (whole-song -> separate song),
 * tblSongComponents.NotesJson (presenter notes) and tblLyricLines.MetaJson
 * (lossless TTML attrs). All growable vocab is VARCHAR not ENUM (#1066 policy);
 * language tags are free-text VARCHAR(35) (no FK to tblLanguages) so TTML/LRC
 * script subtags never RESTRICT-fail ingest.
 *
 * STRICTLY ADDITIVE + IDEMPOTENT (table existence guarded).
 *
 * NOT DESTRUCTIVE. Two new tables, nothing altered, nothing dropped. Both are
 * DORMANT on arrival — no ingest writes them and no read path selects them
 * until the follow-up feature work lands — so applying this migration is a
 * verified no-op for every existing page.
 *
 * HOW IDEMPOTENCY IS ACHIEVED: one _migLineEnrich_tableExists() guard per
 * CREATE, so a re-run is two SHOW TABLES probes and two [SKIP] lines. The
 * registry probe is the multi-table OR form — pending while EITHER table is
 * missing — so a run that created the first table and threw on the second
 * leaves the card correctly pending and a re-run completes it (CLAUDE.md
 * rule #19).
 *
 * PREREQUISITE: both tables FK to tblLyricLines(Id), which is created by
 * migrate-lyric-lines-mirror.php. That migration sits earlier in the registry
 * order, so the dashboard's "Apply all pending" runs them in the right
 * sequence; running THIS script standalone on an install without
 * tblLyricLines fails on the foreign key ("failed to open the referenced
 * table") and the card stays pending — a loud, recoverable failure, not a
 * silent one.
 *
 * DATA-SHAPING DECISIONS NOT VISIBLE IN THE SQL:
 *  · Language tags are free-text VARCHAR(35), NOT an FK to tblLanguages. TTML
 *    and LRC carry script/region subtags ("ja-Latn", "zh-Hans-CN") that are not
 *    rows in tblLanguages, and a RESTRICT-ing FK would reject the ingest of a
 *    perfectly valid file. 35 is the BCP 47 practical maximum
 *    (https://www.rfc-editor.org/rfc/rfc5646).
 *  · Every growable vocabulary (Kind / TranslationType / Status /
 *    AnnotationType / BodyFormat / Source) is VARCHAR app-validated against a
 *    central map, never ENUM — adding a value to an ENUM is an ALTER, i.e. the
 *    second migration CLAUDE.md rule #20 exists to prevent.
 *  · Offsets are UTF-8 CODE POINT indices, not byte or UTF-16 offsets, so
 *    readers must slice with mb_substr() / Array.from() (rule #21). A byte
 *    offset would split a multi-byte character; a UTF-16 offset would
 *    disagree with PHP for anything outside the BMP.
 *
 * SCHEMA MIRROR: both CREATE TABLE statements are mirrored in
 * appWeb/.sql/schema.sql, which is what a FRESH install reads instead of
 * running this script. They must stay byte-identical — COMMENT strings
 * included — or a fresh install differs structurally from a migrated one and
 * the Schema Audit page (#518) reports drift (CLAUDE.md rule #19).
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-line-enrichment.php
 *   Web:  /manage/setup-database → "Per-line lyric enrichment (translations + annotations)" button
 *
 * @requires PHP 8.1+ with mysqli
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

function _migLineEnrich_output(string $msg): void {
    global $isCli;
    echo $msg . ($isCli ? "\n" : "<br>\n");
    if (!$isCli) flush();
}

function _migLineEnrich_tableExists(\mysqli $db, string $table): bool {
    $r = $db->query("SHOW TABLES LIKE '{$table}'");
    return (bool)($r && $r->num_rows > 0);
}

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) {
    _migLineEnrich_output("ERROR: MySQL credentials not found. Run install.php first.");
    return;
}
require_once $credFile;

_migLineEnrich_output("");
_migLineEnrich_output("=== iHymns — Per-line lyric enrichment (#1088) ===");
_migLineEnrich_output("");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    $mysql->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
} catch (\mysqli_sql_exception $e) {
    _migLineEnrich_output("ERROR: MySQL connection failed: " . $e->getMessage());
    return;
}
_migLineEnrich_output("Connected to MySQL: " . DB_NAME);

try {
    _migLineEnrich_output("");
    _migLineEnrich_output("--- tblLyricLineTranslations ---");
    if (_migLineEnrich_tableExists($mysql, 'tblLyricLineTranslations')) {
        _migLineEnrich_output("  [SKIP] tblLyricLineTranslations already present.");
    } else {
        /* The two UNIQUE keys do different jobs and both are load-bearing:

           uq_Line_Lang_Kind_Source (LineId, TargetLanguage, Kind, Source) is
           the NATURAL key — "one English meaning-translation of this line from
           Apple Music". Source is IN the key deliberately: two providers may
           legitimately disagree about the same line in the same language, and
           we want to hold both and let IsPrimary choose, not to have the second
           import clobber the first.

           uq_SourceRef (Source, SourceRef) is the RE-IMPORT key. Re-running an
           Apple TTML import must update rather than duplicate. SourceRef is
           NULLable and MySQL treats NULLs as distinct in a unique index
           (https://dev.mysql.com/doc/refman/8.0/en/create-index.html), so any
           number of hand-written rows — which have no external id — coexist
           happily under the same Source. This is the (Source, SourceRef)
           pattern CLAUDE.md rule #20 prescribes for every externally-sourced
           table.

           LyricsId is a DENORM of tblLyricLines.LyricsId, not a second source
           of truth: it exists so "fetch every translation for this lyrics
           version" is one indexed query instead of a join through every line.
           The COMMENT says the app MUST derive it from the line rather than
           trust a caller-supplied value — nothing in the schema enforces that
           agreement, so a writer that gets it wrong silently orphans rows from
           the idx_Lyrics path while the FK still passes. */
        $mysql->query(
            "CREATE TABLE tblLyricLineTranslations (
                Id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                LineId          BIGINT UNSIGNED NOT NULL COMMENT 'FK to tblLyricLines.Id — the line being translated / romanized',
                LyricsId        INT UNSIGNED    NOT NULL COMMENT 'Denorm of tblLyricLines.LyricsId — fetch all aux text for a lyrics version in one indexed query. App MUST derive it from the line, never trust the caller.',
                Kind            VARCHAR(20)     NOT NULL DEFAULT 'translation' COMMENT 'translation (meaning, ttm:role=x-translation) | transliteration (romanization, ttm:role=x-roman). VARCHAR not ENUM — vocab may grow (furigana, ipa); app-validate against a central map',
                TargetLanguage  VARCHAR(35)     NOT NULL COMMENT 'IETF BCP 47 tag of THIS aux text (en, ja, ko, ja-Latn, ko-Latn, zh-Hans-CN). Free text, mirrors tblLyricLines.LanguageCode — NOT a FK (script subtags absent from tblLanguages)',
                TranslationType VARCHAR(20)     NULL DEFAULT NULL COMMENT 'Apple per-track type for Kind=translation: subtitle (normal) | replacement (Simplified<->Traditional). NULL for transliterations. VARCHAR not ENUM',
                Text            TEXT            NOT NULL COMMENT 'The translated / romanized line text',
                SortOrder       INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT 'Display order when a line carries several aux rows (multiple languages / both kinds)',
                Source          VARCHAR(100)    NOT NULL DEFAULT 'ihymns' COMMENT 'Provenance: applemusic-ttml / human / machine-<engine> / ihymns / … (mirrors tblLyrics.Source). Part of the natural key',
                SourceUrl       VARCHAR(1000)   NULL DEFAULT NULL COMMENT 'Origin URL of the translation track, if any',
                SourceRef       VARCHAR(190)    NULL DEFAULT NULL COMMENT 'External primary id from the Source system for idempotent re-import / dedup. NULL for manual',
                IsPrimary       TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '1 = preferred row to display for this (LineId, TargetLanguage, Kind). App demotes the prior primary on insert (no DB constraint, mirrors tblLyrics.IsPrimary)',
                IsAutoGenerated TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '1 = machine-generated (vs human-curated / publisher-supplied) — drives a machine-translation badge',
                Status          VARCHAR(20)     NOT NULL DEFAULT 'approved' COMMENT 'draft|pending_review|approved|rejected|archived (app-validated). VARCHAR not ENUM (#1066). Same column order/names as tblLyrics',
                SubmittedBy     INT UNSIGNED    NULL DEFAULT NULL COMMENT 'FK to tblUsers.Id — who submitted (NULL for imported / system)',
                ApprovedBy      INT UNSIGNED    NULL DEFAULT NULL COMMENT 'FK to tblUsers.Id — who approved (NULL until approved)',
                ApprovedAt      DATETIME        NULL DEFAULT NULL,
                MetaJson        JSON            NULL DEFAULT NULL COMMENT 'Lossless TTML attrs: original itunes:key / for= linkage, ttm:role, xml:lang as authored, sub-span timing — loss-free re-export',
                CreatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UpdatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                UNIQUE KEY uq_Line_Lang_Kind_Source (LineId, TargetLanguage, Kind, Source),
                UNIQUE KEY uq_SourceRef             (Source, SourceRef),
                INDEX idx_Lyrics    (LyricsId),
                INDEX idx_Line      (LineId, SortOrder),
                INDEX idx_Line_Kind (LineId, Kind),
                INDEX idx_Primary   (LineId, TargetLanguage, Kind, IsPrimary),
                INDEX idx_Status    (Status),

                CONSTRAINT fk_LineTrans_Line
                    FOREIGN KEY (LineId)   REFERENCES tblLyricLines(Id) ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT fk_LineTrans_Lyrics
                    FOREIGN KEY (LyricsId) REFERENCES tblLyrics(Id)     ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT fk_LineTrans_SubmittedBy
                    FOREIGN KEY (SubmittedBy) REFERENCES tblUsers(Id)   ON DELETE SET NULL,
                CONSTRAINT fk_LineTrans_ApprovedBy
                    FOREIGN KEY (ApprovedBy)  REFERENCES tblUsers(Id)   ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        _migLineEnrich_output("  [OK] Created tblLyricLineTranslations.");
    }

    _migLineEnrich_output("");
    _migLineEnrich_output("--- tblLyricLineAnnotations ---");
    if (_migLineEnrich_tableExists($mysql, 'tblLyricLineAnnotations')) {
        _migLineEnrich_output("  [SKIP] tblLyricLineAnnotations already present.");
    } else {
        /* The span model, which is the only genuinely subtle part of this table.
           An annotation covers a RANGE: from (StartLineId, StartOffset) to
           (EndLineId ?? StartLineId, EndOffset). Both Line ids are real FKs, so
           the range is anchored to rows, not to positions in a JSON array —
           re-ordering or inserting a verse cannot silently re-point an existing
           annotation at different words (that fragility is exactly why rule #21
           forbids anchoring this on tblSongComponents.LinesJson indices).

           Each of the three "optional" halves means "extend to the natural
           boundary": EndLineId NULL = single-line span; StartOffset NULL =
           from the start of the line; EndOffset NULL = to the end of the line.
           So a whole-line annotation is (StartLineId, NULL, NULL, NULL) and
           needs no special casing by the writer.

           Both line FKs CASCADE on delete, which is the right call for a gloss:
           if the line the annotation is about is gone, the gloss is meaningless.
           Note this means deleting the END line of a multi-line span deletes the
           annotation outright rather than shrinking it — deliberate, since a
           silently-shortened annotation would mis-attribute commentary.

           IsVerified is intentionally SEPARATE from Status: Status is the
           moderation state (did a reviewer let this through), IsVerified is a
           trust badge (staff / artist-cosigned). Collapsing them would make it
           impossible to have an approved-but-unverified community annotation,
           which is the common case. */
        $mysql->query(
            "CREATE TABLE tblLyricLineAnnotations (
                Id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                StartLineId     BIGINT UNSIGNED NOT NULL COMMENT 'Referent span START line. FK -> tblLyricLines.Id. Always set',
                EndLineId       BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'Referent span END line. FK -> tblLyricLines.Id. NULL = single-line span (ends on StartLineId); set only for multi-line spans',
                StartOffset     INT UNSIGNED    NULL DEFAULT NULL COMMENT '0-based UTF-8 code-point index into StartLineId LineText where the highlighted phrase BEGINS. NULL = start of the start line',
                EndOffset       INT UNSIGNED    NULL DEFAULT NULL COMMENT '0-based EXCLUSIVE code-point index into the end line (EndLineId if set, else StartLineId) where the phrase ENDS. NULL = end of that line',
                LyricsId        INT UNSIGNED    NOT NULL COMMENT 'Denorm of tblLyricLines.LyricsId for StartLineId — one indexed fetch of all annotations for a lyrics version; scopes the cascade. App MUST derive it from the start line',
                AnnotationType  VARCHAR(40)     NOT NULL DEFAULT 'explanation' COMMENT 'explanation|reference|scripture|history|translation|trivia|… VARCHAR not ENUM (#1066); app-validate against a central map -> icon/colour',
                LanguageCode    VARCHAR(35)     NULL DEFAULT NULL COMMENT 'IETF BCP 47 language the GLOSS is written in (may differ from the line). Free text, mirrors tblLyricLines.LanguageCode — NOT a FK. NULL = site default',
                Body            MEDIUMTEXT      NOT NULL COMMENT 'Annotation body (Genius annotation body). MEDIUMTEXT: prose + scripture quotes can exceed TEXT comfort, never near 16MB',
                BodyFormat      VARCHAR(20)     NOT NULL DEFAULT 'markdown' COMMENT 'markdown|html|plain. VARCHAR for future formats',
                SortOrder       INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT 'Order when a line carries several annotations',
                Source          VARCHAR(100)    NOT NULL DEFAULT 'manual' COMMENT 'manual|curator|genius|… Mirrors tblLyrics.Source vocab; VARCHAR not ENUM. Part of the dedup key',
                SourceUrl       VARCHAR(1000)   NULL DEFAULT NULL COMMENT 'Canonical URL when imported (e.g. the genius.com annotation permalink)',
                SourceRef       VARCHAR(190)    NULL DEFAULT NULL COMMENT 'External primary id from Source (e.g. Genius annotation/referent id) for idempotent re-import + dedup. NULL for manual',
                Status          VARCHAR(20)     NOT NULL DEFAULT 'approved' COMMENT 'draft|pending_review|approved|rejected|archived (app-validated). VARCHAR not ENUM',
                SubmittedBy     INT UNSIGNED    NULL DEFAULT NULL COMMENT 'FK -> tblUsers.Id; author/submitter. NULL after user deletion or for system imports',
                ApprovedBy      INT UNSIGNED    NULL DEFAULT NULL COMMENT 'FK -> tblUsers.Id; moderator who approved. NULL until approved',
                ApprovedAt      DATETIME        NULL DEFAULT NULL COMMENT 'When approved',
                IsVerified      TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '1 = first-class verified (staff/artist-cosigned) badge, distinct from Status=approved. Filterable via idx_Verified',
                VerifiedBy      INT UNSIGNED    NULL DEFAULT NULL COMMENT 'FK -> tblUsers.Id; who verified. NULL until verified',
                VerifiedAt      DATETIME        NULL DEFAULT NULL COMMENT 'When verified',
                MetaJson        JSON            NULL DEFAULT NULL COMMENT 'Lossless extra Source attrs (Genius community/author block, cosigners, custom_preview) + interim vote tallies until a real votes table lands + future per-annotation flags',
                CreatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UpdatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                UNIQUE KEY uq_SourceRef (Source, SourceRef),
                INDEX idx_Lyrics    (LyricsId),
                INDEX idx_StartLine (StartLineId, SortOrder),
                INDEX idx_EndLine   (EndLineId),
                INDEX idx_Status    (Status),
                INDEX idx_Type      (AnnotationType),
                INDEX idx_Verified  (IsVerified),

                CONSTRAINT fk_LineAnnot_StartLine
                    FOREIGN KEY (StartLineId) REFERENCES tblLyricLines(Id) ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT fk_LineAnnot_EndLine
                    FOREIGN KEY (EndLineId)   REFERENCES tblLyricLines(Id) ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT fk_LineAnnot_Lyrics
                    FOREIGN KEY (LyricsId)    REFERENCES tblLyrics(Id)     ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT fk_LineAnnot_SubmittedBy
                    FOREIGN KEY (SubmittedBy) REFERENCES tblUsers(Id)      ON DELETE SET NULL,
                CONSTRAINT fk_LineAnnot_ApprovedBy
                    FOREIGN KEY (ApprovedBy)  REFERENCES tblUsers(Id)      ON DELETE SET NULL,
                CONSTRAINT fk_LineAnnot_VerifiedBy
                    FOREIGN KEY (VerifiedBy)  REFERENCES tblUsers(Id)      ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        _migLineEnrich_output("  [OK] Created tblLyricLineAnnotations.");
    }

    _migLineEnrich_output("");
    _migLineEnrich_output("--- Summary ---");
    _migLineEnrich_output("  Lyric lines can now carry per-line translations + romanizations (Apple TTML)");
    _migLineEnrich_output("  and Genius-style explanatory annotations. Ingest/UI/display land in follow-ups.");
    _migLineEnrich_output("");
    _migLineEnrich_output("Migration complete.");
} catch (\Throwable $e) {
    _migLineEnrich_output("  [ERROR] " . $e->getMessage());
}

$mysql->close();
return;
