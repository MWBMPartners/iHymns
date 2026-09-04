<?php

declare(strict_types=1);

/**
 * iHymns — Vocal / singing parts (first-class) (#1137)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Promote vocal/singing-part separation (lead/backing/soloist/duet/named-singer)
 * from lossless-only (tblLyricLines.MetaJson ttm:agent/ttm:role/background-vocal)
 * to a first-class, queryable model anchored on the normalized lyrics:
 *   - tblVocalParts          — per-lyrics-version part registry (decoded ttm:agent);
 *     PartKind VARCHAR (app-validated), named singer reuses tblMusicians,
 *     Gender orthogonal axis.
 *   - tblLyricLineVocalParts — MANY-to-MANY line assignment (true duet/unison).
 *   - tblLyricWordVocalParts — MANY-to-MANY word assignment (overrides line).
 *
 * Additive + DORMANT: MetaJson stays the loss-free source of truth; the
 * TTML->first-class back-fill is follow-on feature work, so the tables ship
 * empty. STRICTLY ADDITIVE + IDEMPOTENT (table existence guarded).
 *
 * NOT DESTRUCTIVE. Nothing is dropped, altered or rewritten — the migration
 * only ever issues CREATE TABLE for tables it has just confirmed are absent.
 * There is therefore no recovery path to document: the "undo" is DROP TABLE on
 * three tables that ship empty, and re-running the card recreates them.
 *
 * IDEMPOTENCY. Each of the three CREATEs is fronted by its own
 * _migVP_tableExists() probe, so a second run prints three [SKIP] lines and
 * touches nothing. Note the probes are INDEPENDENT — the migration resumes
 * mid-way if an earlier run died after creating one or two of the three.
 *
 * OPERATOR VIEW. The setup-database card is "Vocal / singing parts (#1137)"
 * and its registry probe (manage/includes/migration-registry.php) reports the
 * card pending on `!tableExists('tblVocalParts')` alone.
 *
 * SCHEMA MIRROR. All three CREATE TABLE blocks are mirrored in
 * appWeb/.sql/schema.sql (the "VOCAL / SINGING PARTS (#1137)" section), which
 * is what a FRESH install reads. Rule #19 of .claude/CLAUDE.md requires the two
 * to stay byte-identical — including COMMENT text — so an install built by
 * migrations is structurally indistinguishable from one built from schema.sql.
 * CI (tests/php/test-schema-coverage.php, plus the structural
 * tests/php/test-schema-ddl-parity.php added alongside this fix) checks
 * presence and, for tblVocalParts, full column/index/FK/COMMENT shape.
 *
 * #2077 FIX (2026-09): tblVocalParts.CreditPersonId (FK to tblCreditPeople,
 * index idx_Person, constraint fk_VocalParts_Person) had drifted from
 * schema.sql's MusicianId (FK to tblMusicians, idx_Musician,
 * fk_VocalParts_Musician) — three COMMENT strings (PartKind/Gender/MetaJson)
 * had also drifted. Cause: migrate-musicians-rename.php (#1741 P2-A) renamed
 * the column/index/constraint on a LIVE database via ALTER, and schema.sql +
 * SongData.php were updated to match, but this file's own CREATE statement
 * — the thing that runs on an install that has NEVER had tblVocalParts —
 * was missed, so it went on minting the pre-rename shape. This file's own
 * DDL now matches schema.sql directly; a fresh table build no longer needs
 * the rename migration to fix it up afterwards.
 * ORDERING IS SAFE: manage/includes/migration-registry.php's array order
 * (which IS execution order — setup-database.php does
 * `$migrationOrder = array_keys($MIGRATIONS)`) lists 'musicians-rename'
 * BEFORE 'vocal-parts', so on "Apply all" tblMusicians already exists as a
 * base table by the time this CREATE's `REFERENCES tblMusicians(Id)` runs.
 * migrate-musicians-rename.php's own rename helpers
 * (_migMusRename_renameColumn/_renameIndex/_renameFk) are ALL guarded to
 * SKIP (not fatal) when the new name is already present, and to WARN-skip
 * (not fatal) when neither the old nor the new name exists yet — so running
 * musicians-rename BEFORE this file now creates tblVocalParts (table absent
 * at that point) is a silent, harmless no-op for the three tblVocalParts
 * steps it contains, and running this file AFTER musicians-rename (the
 * registry's actual order) needs no rename at all since the column is
 * already named MusicianId. The only remaining edge case — manually running
 * the "Vocal / singing parts" card BEFORE "Musicians rename" on an install
 * that has neither applied — would fail this file's own CREATE (FK to a
 * not-yet-existing tblMusicians) with a caught, reported [ERROR] line (see
 * the try/catch below); it is not fatal to the request and is recoverable
 * by running musicians-rename first and re-running this card, but "Apply
 * all" and the registry's documented order never hit it.
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-vocal-parts.php
 *   Web:  /manage/setup-database → "Vocal / singing parts" button
 *
 * @requires PHP 8.1+ with mysqli
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}
function _migVP_output(string $m): void { global $isCli; echo $m . ($isCli ? "\n" : "<br>\n"); if (!$isCli) flush(); }
/* ELI5: "does this table already exist?" — the check that makes re-running safe.
   Detail: SHOW TABLES LIKE takes a LIKE *pattern*, not a literal, so `_` means
   "any one character" and `%` means "any run". Every name passed here is a
   hardcoded constant from this file's source with neither character in it, so
   the pattern degenerates to an exact match — and, being source constants, the
   string interpolation carries no injection risk (rule #5 allows exactly this
   case). A future table name containing an underscore would silently over-match;
   the INFORMATION_SCHEMA + bind_param probe used by the other migrations in this
   directory (e.g. migrate-bulk-import-jobs.php) has no such caveat.
   https://dev.mysql.com/doc/refman/8.0/en/show-tables.html */
function _migVP_tableExists(\mysqli $db, string $t): bool { $r = $db->query("SHOW TABLES LIKE '{$t}'"); return (bool)($r && $r->num_rows > 0); }

/* This migration opens its OWN connection straight from the credentials file
   rather than going through includes/db_mysql.php::getDbMysqli(). That is the
   deliberate split among the scripts in this directory: the ones that connect
   directly are runnable on an install whose public_html/ bootstrap isn't
   loadable, and — because the handle is theirs alone — they may safely $mysql->
   close() at the end. The getDbMysqli() variants must NOT close, because the
   dashboard's bulk runner hands the same singleton to every later migration in
   the request (see the closing note in migrate-credit-people-slug.php). */
$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) { _migVP_output("ERROR: MySQL credentials not found. Run install.php first."); return; }
require_once $credFile;

_migVP_output("");
_migVP_output("=== iHymns — Vocal / singing parts (#1137) ===");
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    $mysql->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
} catch (\mysqli_sql_exception $e) { _migVP_output("ERROR: MySQL connection failed: " . $e->getMessage()); return; }
_migVP_output("Connected to MySQL: " . DB_NAME);

try {
    /* Three data-shaping decisions in the DDL below that the SQL does not say
       out loud:

       1. PartKind is VARCHAR(30), not an ENUM of the fifteen values its COMMENT
          lists. Rule #20 of .claude/CLAUDE.md: a vocabulary that can grow must
          never be an ENUM, because adding "vocal-percussion" to an ENUM is an
          ALTER TABLE — i.e. a second migration for what should be a one-line
          change to the app-side allow-list. Same reasoning for Gender.

       2. uq_Lyrics_Agent is (LyricsId, TtmlAgentId) where TtmlAgentId is
          NULLable. In SQL a UNIQUE index does not constrain NULLs — two rows
          with the same LyricsId and a NULL agent are both accepted. That is the
          point: the key makes the TTML back-fill idempotent (re-importing the
          same <ttm:agent v1> can only ever update the one row it created) while
          leaving curators free to hand-add as many un-sourced parts as they
          like. This is the (Source, SourceRef) pattern rule #20 describes.
          https://dev.mysql.com/doc/refman/8.0/en/create-index.html

       3. The two foreign keys deliberately differ on delete. Losing the lyrics
          version must take its parts with it (CASCADE) — a part has no meaning
          without the lines it annotates. Losing a musician must NOT: SET NULL
          demotes a named-singer row back to an anonymous one, leaving SingerName
          and the line assignments intact. */
    if (_migVP_tableExists($mysql, 'tblVocalParts')) {
        _migVP_output("  [SKIP] tblVocalParts already present.");
    } else {
        $mysql->query(
            "CREATE TABLE tblVocalParts (
                Id             INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
                LyricsId       INT UNSIGNED    NOT NULL COMMENT 'FK to tblLyrics.Id — parts are per lyrics version',
                PartKind       VARCHAR(30)     NOT NULL DEFAULT 'lead' COMMENT 'lead|main|backing|soloist|male|female|duet|group|unison|choir|congregation|cantor|descant|narrator|spoken|named-singer (app-validated vs a central map -> badge). VARCHAR not ENUM',
                Label          VARCHAR(120)    NULL DEFAULT NULL COMMENT 'Editor display override (Soprano, Worship Leader, …)',
                MusicianId     INT UNSIGNED    NULL DEFAULT NULL COMMENT 'FK to tblMusicians.Id — typed named-singer link (reuses the musician registry, NOT a new tblArtists)',
                SingerName     VARCHAR(255)    NULL DEFAULT NULL COMMENT 'Free-text named singer when no registry row',
                Gender         VARCHAR(16)     NULL DEFAULT NULL COMMENT 'male|female|neutral — orthogonal axis (a named soloist may also be female)',
                TtmlAgentId    VARCHAR(64)     NULL DEFAULT NULL COMMENT 'Source <ttm:agent> handle (v1,v2) — loss-free re-export + idempotent back-fill key',
                Source         VARCHAR(100)    NOT NULL DEFAULT 'ihymns' COMMENT 'applemusic-ttml | manual | … (mirrors tblLyrics.Source)',
                SortOrder      INT UNSIGNED    NOT NULL DEFAULT 0,
                MetaJson       JSON            NULL DEFAULT NULL COMMENT 'Lossless TTML <head> agent def attrs (ttm:agent type, ttm:name)',
                CreatedAt      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UpdatedAt      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_Lyrics_Agent (LyricsId, TtmlAgentId),
                INDEX idx_Lyrics (LyricsId),
                INDEX idx_Kind   (PartKind),
                INDEX idx_Musician (MusicianId),
                CONSTRAINT fk_VocalParts_Lyrics   FOREIGN KEY (LyricsId)   REFERENCES tblLyrics(Id)     ON DELETE CASCADE  ON UPDATE CASCADE,
                CONSTRAINT fk_VocalParts_Musician FOREIGN KEY (MusicianId) REFERENCES tblMusicians(Id) ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Per-version singing-part registry — first-class vocal parts (#1137).'"
        );
        _migVP_output("  [OK] Created tblVocalParts.");
    }

    /* Why a join table rather than a VocalPartId column on tblLyricLines: a duet
       or a unison line is sung by more than one part AT ONCE, and a lead line
       with a backing echo is two parts on one line distinguished only by
       IsBackground. A single FK column could represent neither. The LyricsId
       column is a denormalised copy of the line's own LyricsId — carried so the
       common "every part assignment in this lyrics version" query (idx_Lyrics)
       needs no join back through tblLyricLines. The app derives it from the
       line; it is never taken from the caller. */
    if (_migVP_tableExists($mysql, 'tblLyricLineVocalParts')) {
        _migVP_output("  [SKIP] tblLyricLineVocalParts already present.");
    } else {
        $mysql->query(
            "CREATE TABLE tblLyricLineVocalParts (
                Id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                LineId       BIGINT UNSIGNED NOT NULL,
                VocalPartId  INT UNSIGNED    NOT NULL,
                LyricsId     INT UNSIGNED    NOT NULL COMMENT 'Denorm of tblLyricLines.LyricsId; app derives from the line',
                IsBackground TINYINT(1)      NOT NULL DEFAULT 0 COMMENT 'TTML background-vocal / ttm:role=x-bg',
                SortOrder    INT UNSIGNED    NOT NULL DEFAULT 0,
                CreatedAt    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_Line_Part (LineId, VocalPartId),
                INDEX idx_Lyrics (LyricsId),
                INDEX idx_Line   (LineId, SortOrder),
                INDEX idx_Part   (VocalPartId),
                CONSTRAINT fk_LineVP_Line   FOREIGN KEY (LineId)      REFERENCES tblLyricLines(Id) ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT fk_LineVP_Part   FOREIGN KEY (VocalPartId) REFERENCES tblVocalParts(Id) ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT fk_LineVP_Lyrics FOREIGN KEY (LyricsId)    REFERENCES tblLyrics(Id)     ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Per-line vocal-part assignment, many-to-many for duet/unison (#1137).'"
        );
        _migVP_output("  [OK] Created tblLyricLineVocalParts.");
    }

    /* The word-level twin of the table above, for karaoke-grade sources (TTML /
       LRC-A) where a single line changes voice part-way through. The read rule
       lives in the app, not the schema: a word WITH rows here overrides its
       line's parts; a word with none inherits them. Structurally identical to
       the line table apart from the anchor, which is tblLyricWords.Id — per
       rule #21, enrichment anchors on a normalised row id, never on an index
       into a parallel JSON array. */
    if (_migVP_tableExists($mysql, 'tblLyricWordVocalParts')) {
        _migVP_output("  [SKIP] tblLyricWordVocalParts already present.");
    } else {
        $mysql->query(
            "CREATE TABLE tblLyricWordVocalParts (
                Id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                WordId       BIGINT UNSIGNED NOT NULL,
                VocalPartId  INT UNSIGNED    NOT NULL,
                LyricsId     INT UNSIGNED    NOT NULL COMMENT 'Denorm via word->line->lyrics; app-derived',
                IsBackground TINYINT(1)      NOT NULL DEFAULT 0,
                SortOrder    INT UNSIGNED    NOT NULL DEFAULT 0,
                CreatedAt    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_Word_Part (WordId, VocalPartId),
                INDEX idx_Lyrics (LyricsId),
                INDEX idx_Word   (WordId, SortOrder),
                INDEX idx_Part   (VocalPartId),
                CONSTRAINT fk_WordVP_Word   FOREIGN KEY (WordId)      REFERENCES tblLyricWords(Id) ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT fk_WordVP_Part   FOREIGN KEY (VocalPartId) REFERENCES tblVocalParts(Id) ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT fk_WordVP_Lyrics FOREIGN KEY (LyricsId)    REFERENCES tblLyrics(Id)     ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Per-word vocal-part assignment (#1137).'"
        );
        _migVP_output("  [OK] Created tblLyricWordVocalParts.");
    }

    _migVP_output("  Tables ship empty; the TTML ttm:agent -> first-class back-fill is follow-on feature work.");
    _migVP_output("Migration complete.");
/* This catch SWALLOWS the failure: it prints an [ERROR] line and then falls
   through to a normal return, so from the dashboard's point of view the script
   completed. That is intentional and is why setup-database.php re-runs the
   registry probe after every migration — a script that "finished" but left its
   objects uncreated is reported as "ran but is STILL PENDING". Read the [ERROR]
   line, not the absence of an exception, to know whether the DDL landed. */
} catch (\Throwable $e) { _migVP_output("  [ERROR] " . $e->getMessage()); }
/* Safe here only because the connection above is this script's own (see the
   note at $credFile). Never add this line to a getDbMysqli() migration. */
$mysql->close();
/* `return`, not `exit` — the dashboard `require`s this file inside an ob_start()
   block and frames the captured output with a STATUS:/ACTION:/ELAPSED_MS:
   header. exit() would end the request before that header is written, and the
   bulk runner's parseEnvelope() would report a failure with no output to show. */
return;
