<?php

declare(strict_types=1);

/**
 * iHymns — Interchange fidelity: chords, notes, arrangements (#1066 Theme E)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * The multi-format interchange importers currently regex-strip chord rows and
 * drop presenter notes + named arrangements on every round-trip. This migration
 * gives them a lossless home:
 *   - tblSongComponents.ChordsJson — per-line chord annotations parallel to
 *     LinesJson (null-padded array).
 *   - tblSongComponents.NotesJson  — per-line presenter/slide notes.
 *   - tblSongArrangements          — named reorderings + repetitions of a song's
 *     components (the PP7 "arrangement" concept). Coexists with the simpler
 *     tblSongs.ArrangementJson (IsDefault=1 row wins, else fall back to it).
 *
 * STRICTLY ADDITIVE + IDEMPOTENT (column/table existence guarded).
 *
 * ⚠ HALF OF THIS MIGRATION IS NOW HISTORY — READ BEFORE TOUCHING IT.
 * The two tblSongComponents columns it was written to add were RETIRED by
 * the #1235 P4/C6 cutover (migrate-retire-component-lines-json.php), which
 * made tblLyricLines the single source of truth for lyric lines and
 * dropped the parallel JSON payload columns. On any install past that
 * cutover, the LinesJson guard below fires and the ChordsJson/NotesJson
 * half of this file does nothing — running the card would otherwise
 * RESURRECT two retired columns. Only tblSongArrangements is still
 * created. The file is kept rather than deleted because installs that
 * have not yet run the C6 drop still need the columns to reach it.
 *
 * NOT DESTRUCTIVE. Two ADD COLUMNs (conditionally) and one CREATE TABLE;
 * nothing is dropped or rewritten and no existing row is touched.
 * tblSongs.ArrangementJson is left in place — soft-deprecated, no
 * backfill, no drop — so rolling back is DROP TABLE tblSongArrangements
 * and the older code path still works.
 *
 * IDEMPOTENCY. Three independent probes, none of which shares state:
 * SHOW COLUMNS for each of the two columns (via _migFidelity_addCol) and
 * SHOW TABLES for tblSongArrangements. A second run prints [SKIP] for
 * each. The registry probe on the dashboard is compound —
 * `LinesJson present AND ChordsJson absent` — so the card is pending only
 * during the window where the work is both possible and outstanding.
 *
 * SCHEMA MIRROR. tblSongArrangements is mirrored in appWeb/.sql/schema.sql
 * (the "#1066 Theme E" section), which is what a FRESH install reads; per
 * rule #19 the two must stay byte-identical, COMMENT text included.
 * ChordsJson/NotesJson deliberately have NO mirror any more — schema.sql
 * documents tblSongComponents as thin post-cutover metadata — and the
 * schema-coverage test tolerates that because the retirement migration
 * carries `@migration-drops` tags for both.
 *
 * The two doctags below are LOAD-BEARING here, unlike in most migrations.
 * _migFidelity_addCol() builds its statement as
 * "ALTER TABLE {$table} ADD COLUMN {$ddl}", so the scanner's literal-ALTER
 * regex ("Signal 1") sees an interpolated variable, not a table name, and
 * matches nothing. These tags are the only way it learns what this file
 * adds — exactly the dynamic-ALTER case rule #19 describes.
 *
 * @migration-adds tblSongComponents.ChordsJson
 * @migration-adds tblSongComponents.NotesJson
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-interchange-fidelity.php
 *   Web:  /manage/setup-database → "Interchange fidelity (chords/notes/arrangements)" button
 *
 * @requires PHP 8.1+ with mysqli
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

function _migFidelity_output(string $msg): void {
    global $isCli;
    echo $msg . ($isCli ? "\n" : "<br>\n");
    if (!$isCli) flush();
}

/**
 * Add one column unless it is already there.
 *
 * Takes the column NAME (for the probe) and the full DDL fragment
 * separately, because the fragment carries the type, COMMENT and AFTER
 * clause and there is no reliable way to recover just the name from it.
 * Both arguments are hardcoded constants from this file's source, so the
 * interpolation into SQL is the "constants from PHP source" case rule #5
 * permits — never pass caller input through here.
 *
 * SHOW COLUMNS … LIKE takes a LIKE *pattern*: `_` matches any single
 * character and `%` any run. ChordsJson and NotesJson contain neither, so
 * the pattern is an exact match, but a snake_case column name would
 * over-match and this probe would report a neighbour as "already
 * present". The INFORMATION_SCHEMA + bind_param probe used elsewhere in
 * this directory has no such caveat.
 * https://dev.mysql.com/doc/refman/8.0/en/show-columns.html
 */
function _migFidelity_addCol(\mysqli $db, string $table, string $col, string $ddl): void {
    $r = $db->query("SHOW COLUMNS FROM {$table} LIKE '{$col}'");
    if ($r && $r->num_rows > 0) {
        _migFidelity_output("  [SKIP] {$table}.{$col} already present.");
        return;
    }
    $db->query("ALTER TABLE {$table} ADD COLUMN {$ddl}");
    _migFidelity_output("  [OK] Added {$table}.{$col}.");
}

function _migFidelity_tableExists(\mysqli $db, string $table): bool {
    $r = $db->query("SHOW TABLES LIKE '{$table}'");
    return (bool)($r && $r->num_rows > 0);
}

function _migFidelity_columnExists(\mysqli $db, string $table, string $col): bool {
    $r = $db->query("SHOW COLUMNS FROM {$table} LIKE '{$col}'");
    return (bool)($r && $r->num_rows > 0);
}

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) {
    _migFidelity_output("ERROR: MySQL credentials not found. Run install.php first.");
    return;
}
require_once $credFile;

_migFidelity_output("");
_migFidelity_output("=== iHymns — Interchange fidelity (#1066 Theme E) ===");
_migFidelity_output("");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    $mysql->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
} catch (\mysqli_sql_exception $e) {
    _migFidelity_output("ERROR: MySQL connection failed: " . $e->getMessage());
    return;
}
_migFidelity_output("Connected to MySQL: " . DB_NAME);

try {
    _migFidelity_output("");
    _migFidelity_output("--- tblSongComponents: ChordsJson + NotesJson ---");
    /* #1235 P4/C6 retired-era guard: once C6 drops the payload columns, tblSongComponents.
       LinesJson is gone (the canonical "JSON era over" signal). Do NOT re-add ChordsJson/
       NotesJson — that would RESURRECT retired columns; tblLyricLines is authoritative.
       tblSongArrangements (below) is unrelated and still created. */
    /* LinesJson is used as the era sentinel rather than ChordsJson itself
       because it is the column whose presence defines "the JSON era is
       still running": the C6 drop removes all four payload columns
       together, so its absence is an unambiguous signal that this half of
       the migration has been superseded. Probing ChordsJson instead would
       be ambiguous — absent could mean "not yet added" OR "added and then
       retired", and the second reading is the one that must not lead to
       an ALTER. Note the ALTERs below position their columns with
       `AFTER Language` and, unlike migrate-activity-log-expand.php, this
       file has no fallback that drops the clause: tblSongComponents.
       Language (#858) must exist or the ALTER fails outright. */
    if (!_migFidelity_columnExists($mysql, 'tblSongComponents', 'LinesJson')) {
        _migFidelity_output("  [SKIP] retired era (tblSongComponents.LinesJson gone) — not re-adding ChordsJson/NotesJson (#1235 P4/C6).");
    } else {
        _migFidelity_addCol($mysql, 'tblSongComponents', 'ChordsJson',
            "ChordsJson JSON NULL DEFAULT NULL COMMENT 'Per-line chord annotations parallel to LinesJson; null-padded array e.g. [null,[\"C\",\"Am\"],null]. Lossless chord interchange so importers stop regex-stripping chord rows (#1066 Theme E)' AFTER Language");
        _migFidelity_addCol($mysql, 'tblSongComponents', 'NotesJson',
            "NotesJson JSON NULL DEFAULT NULL COMMENT 'Per-line presenter/slide notes parallel to LinesJson; null-padded array of strings e.g. [null,\"Repeat 2x\",null]. ProPresenter speaker notes round-trip (#1066 Theme E)' AFTER ChordsJson");
    }

    _migFidelity_output("");
    _migFidelity_output("--- tblSongArrangements ---");
    /* Unaffected by the C6 retirement above — an arrangement is a
       component ORDERING, not lyric content — so this half runs on every
       install and is mirrored in schema.sql for fresh ones.

       Two things the DDL doesn't say:

       - ComponentOrderJson holds component INDICES, not tblSongComponents
         .Id values: [0,1,1,2,3] means "first component, second, second
         again, third, fourth". Repetition is the whole feature, so a set
         of ids would not do — but positional references are only stable
         while the component list is, which is the same index-fragility
         rule #21 calls out for lyric enrichment. Reordering or deleting a
         component silently re-points every arrangement for that song.

       - uk_SongName stops one song having two arrangements of the same
         name, but NOTHING here stops it having two rows with IsDefault=1.
         idx_SongDefault makes "find the default" fast, it does not make
         it unique; the single-default invariant is the app's to keep, and
         a reader who assumes the schema enforces it will be wrong.

       KeySignature and CapoFret are dormant on arrival — reserved in this
       one-pass batch (rule #20) so the future transpose/chord-display
       work needs no second ALTER. */
    if (_migFidelity_tableExists($mysql, 'tblSongArrangements')) {
        _migFidelity_output("  [SKIP] tblSongArrangements already present.");
    } else {
        $mysql->query(
            "CREATE TABLE tblSongArrangements (
                Id                 INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
                SongId             VARCHAR(20)   NOT NULL COMMENT 'FK to tblSongs.SongId',
                Name               VARCHAR(255)  NOT NULL COMMENT 'Arrangement name, e.g. Default, Verse-only, Key of G',
                IsDefault          TINYINT(1)    NOT NULL DEFAULT 0 COMMENT '1 = canonical arrangement for PP7 export / presentation view',
                ComponentOrderJson JSON          NOT NULL COMMENT 'Array of component indices defining playback sequence, e.g. [0,1,1,2,3]',
                Description        TEXT          NULL DEFAULT NULL COMMENT 'Free-text notes about this arrangement',
                KeySignature       VARCHAR(10)   NULL DEFAULT NULL COMMENT 'Structured key, e.g. G, Bb, F#m — home for the future transpose feature',
                CapoFret           TINYINT       NULL DEFAULT NULL COMMENT 'Capo fret position for chord display (0-12); NULL = none',
                CreatedAt          TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UpdatedAt          TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                UNIQUE KEY uk_SongName     (SongId, Name),
                INDEX      idx_SongDefault (SongId, IsDefault),

                CONSTRAINT fk_SongArrangements_Song
                    FOREIGN KEY (SongId) REFERENCES tblSongs(SongId)
                    ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Named song arrangements (#1066 Theme E). PP7 exporter reads IsDefault=1.'"
        );
        _migFidelity_output("  [OK] Created tblSongArrangements.");
    }

    _migFidelity_output("");
    _migFidelity_output("--- Summary ---");
    _migFidelity_output("  Importers can now preserve chord rows + presenter notes, and songs");
    _migFidelity_output("  can carry named arrangements the PP7 exporter reads via IsDefault=1.");
    _migFidelity_output("  tblSongs.ArrangementJson is kept (soft-deprecation; no backfill, no drop).");
    _migFidelity_output("");
    _migFidelity_output("Migration complete.");
} catch (\Throwable $e) {
    /* Swallowed on purpose: the script prints [ERROR] and then returns
       normally, so the dashboard sees a completed run. That is why
       setup-database.php re-evaluates the registry probe afterwards and
       reports "ran but is STILL PENDING" when the objects are missing —
       the [ERROR] line, not the absence of an exception, is the signal
       that the DDL did not land. */
    _migFidelity_output("  [ERROR] " . $e->getMessage());
}

/* Safe because the connection above is this script's own `new mysqli`,
   not the getDbMysqli() singleton the dashboard's bulk runner hands to
   every subsequent migration in the same request. */
$mysql->close();
/* `return`, not `exit` — the dashboard `require`s this file inside an
   ob_start() block and wraps the captured output in a STATUS:/ACTION:/
   ELAPSED_MS: envelope. exit() would end the request before that header
   is written and the runner would report a failure with no output. */
return;
