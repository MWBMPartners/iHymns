<?php

declare(strict_types=1);

/**
 * test-musician-orphan-reap.php — musicianReapOrphanedAutoRow() truth-table (#1843)
 * ================================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * WHAT THIS PROVES
 * -----------------
 * The v2 Credits tab saves on every debounced keystroke, and each save
 * promotes the CURRENT partial name into the tblMusicians registry — so
 * typing "Joh" → "John" → "John Newton" leaves junk registry rows ("Joh",
 * "John") behind. #1843's fix is a post-commit janitor,
 * musicianReapOrphanedAutoRow() (includes/musician_helpers.php), that deletes
 * the left-behind row for the OLD spelling — but ONLY when it is provably a
 * throw-away auto-row. This file drives that function with a scripted mysqli
 * double and asserts the WHOLE guard ladder, one guard at a time (rule #34:
 * a guard is unproven until each thing it checks has been shown to change the
 * outcome — the mutation matrix below flips each signal/reference in turn and
 * confirms the delete is prevented).
 *
 * WHY A BESPOKE IN-FILE DOUBLE (not tests/php/lib/mysqli_doubles.php)
 * ------------------------------------------------------------------
 * The shared GateRecorderMysqli throws LogicException on any SELECT it does
 * not recognise, and it only recognises the song-relocate / soft-delete
 * query shapes it was extracted for — none of the reap's five query shapes
 * (the FOR UPDATE row snapshot, the 6-table usage count, the 11 reference
 * probes, plus the three column-existence gates). Teaching the shared lib
 * this feature's queries would couple it to a concern it has no other
 * consumer for; the codebase's own extraction discipline (see that lib's
 * doc-block) is "born in-file, extracted on the SECOND consumer". So the
 * double lives here, in the same faithful-under-STRICT style: a statement
 * against an absent table THROWS 1146 (never returns false), and an
 * unrecognised query throws a LogicException NAMING itself rather than
 * silently vouching for fake data.
 *
 * @see appWeb/public_html/includes/musician_helpers.php — musicianReapOrphanedAutoRow()
 * @link https://www.php.net/manual/en/mysqli.reporting-mode.php
 *
 *   php tests/php/test-musician-orphan-reap.php
 *
 * Exit status 0 = every scenario behaved, 1 = a guard did not hold.
 */

$root   = dirname(__DIR__, 2);
$pubDir = $root . '/appWeb/public_html';

/* The reap's best-effort catch calls error_log() on the swallow path
   (scenario e, deliberately). Route it to a scratch file so the expected
   stderr line does not clutter this test's PASS/FAIL report. */
ini_set('error_log', sys_get_temp_dir() . '/ihymns-orphan-reap-test.log');

$fail = 0;
function ok(string $label, bool $cond, string $detail = ''): void
{
    global $fail;
    echo ($cond ? "  PASS  " : "  FAIL  ") . $label . "\n";
    if (!$cond) {
        $fail++;
        if ($detail !== '') { echo "        $detail\n"; }
    }
}

/* ================================================================= *
 *  THE SCRIPTED DOUBLE
 * ================================================================= */

/** A result that hands back a scripted list of assoc rows, one per fetch. */
final class ReapResult
{
    private int $i = 0;
    /** @param list<array<string,mixed>> $rows */
    public function __construct(private array $rows) {}
    public function fetch_assoc(): ?array
    {
        return $this->rows[$this->i++] ?? null;
    }
    public function fetch_row(): ?array
    {
        $r = $this->rows[$this->i++] ?? null;
        return $r === null ? null : array_values($r);
    }
}

/** One prepared statement — records its SQL, defers value read to execute(). */
final class ReapStmt
{
    /** @var list<mixed> references to the caller's bound variables */
    private array $refs = [];
    public function __construct(private ReapMysqli $db, private string $sql) {}

    public function bind_param(string $types, mixed &...$vars): bool
    {
        $this->refs = $vars;
        return true;
    }
    public function execute(): bool
    {
        $this->db->recordExecute($this->sql, array_map(static fn($v) => $v, $this->refs));
        return true;
    }
    public function get_result(): ReapResult
    {
        return new ReapResult(
            $this->db->rowsFor($this->sql, array_map(static fn($v) => $v, $this->refs))
        );
    }
    public function close(): bool { return true; }
}

final class ReapMysqli extends \mysqli
{
    /* ---- per-scenario configuration (public — the test sets these) ---- */
    /** Gated columns that "exist" on this install (default: all present). */
    public array $columnsPresent = [
        'Type' => true, 'Disambiguation' => true, 'Biography' => true,
        'MusicBrainzArtistMBID' => true, 'MaidenSurname' => true,
    ];
    /** The assoc row the FOR UPDATE lookup returns, or null for "not found". */
    public ?array $musicianRow = null;
    /** Total credit-usage the 6-table count query reports. */
    public int $usageCount = 0;
    /** Reference tables that "have a child row": table => true. */
    public array $refHits = [];
    /** Tables that DON'T EXIST on this install (prepare throws 1146). */
    public array $absentTables = [];
    /** Tables whose prepare throws a NON-1146 error: table => errno. */
    public array $errorTables = [];

    /* ---- recorded lifecycle ---- */
    /** @var list<string> 'begin' | 'commit' | 'rollback', in order */
    public array $tx = [];
    /** @var list<array{sql:string,params:list<mixed>}> */
    public array $executed = [];

    public function __construct() {}   // never connects

    public function begin_transaction(int $flags = 0, ?string $name = null): bool { $this->tx[] = 'begin';    return true; }
    public function commit(int $flags = 0, ?string $name = null): bool           { $this->tx[] = 'commit';   return true; }
    public function rollback(int $flags = 0, ?string $name = null): bool         { $this->tx[] = 'rollback'; return true; }

    #[\ReturnTypeWillChange]
    public function prepare(string $query): mixed
    {
        $sql = trim((string)preg_replace('/\s+/', ' ', $query));

        /* INFORMATION_SCHEMA always exists — answered in rowsFor(). */
        if (stripos($sql, 'INFORMATION_SCHEMA.') !== false) {
            return new ReapStmt($this, $sql);
        }

        $table = $this->targetTable($sql);
        if ($table !== null && isset($this->errorTables[$table])) {
            throw new \mysqli_sql_exception("synthetic error on {$table}", $this->errorTables[$table]);
        }
        if ($table !== null && ($this->absentTables[$table] ?? false)) {
            throw new \mysqli_sql_exception("Table 'ihymns.{$table}' doesn't exist", 1146);
        }
        return new ReapStmt($this, $sql);
    }

    public function recordExecute(string $sql, array $params): void
    {
        $this->executed[] = ['sql' => $sql, 'params' => $params];
    }

    /** @return list<array<string,mixed>> */
    public function rowsFor(string $sql, array $params): array
    {
        if (stripos($sql, 'INFORMATION_SCHEMA.COLUMNS') !== false) {
            /* Profile gate: COLUMN_NAME IN (?,?,?) — bound values are the cols. */
            if (stripos($sql, 'COLUMN_NAME IN') !== false) {
                $out = [];
                foreach ($params as $c) {
                    if ($this->columnsPresent[$c] ?? false) { $out[] = ['COLUMN_NAME' => $c]; }
                }
                return $out;
            }
            /* mbid / maiden gate: COLUMN_NAME = '<baked>' LIMIT 1. */
            if (preg_match("/COLUMN_NAME\s*=\s*'(\w+)'/i", $sql, $m)) {
                return ($this->columnsPresent[$m[1]] ?? false) ? [['1' => 1]] : [];
            }
            throw new \LogicException('ReapMysqli: unrecognised I_S.COLUMNS query: ' . $sql);
        }
        /* The FOR UPDATE row snapshot. */
        if (stripos($sql, 'FROM tblMusicians') !== false && stripos($sql, 'FOR UPDATE') !== false) {
            return $this->musicianRow === null ? [] : [$this->musicianRow];
        }
        /* The 6-table usage count. */
        if (stripos($sql, 'AS total') !== false) {
            return [['total' => $this->usageCount]];
        }
        /* A reference / child existence probe. */
        if (preg_match('/^SELECT 1 FROM (\w+) WHERE/i', $sql, $m)) {
            return ($this->refHits[$m[1]] ?? false) ? [['1' => 1]] : [];
        }
        throw new \LogicException('ReapMysqli: unrecognised query: ' . $sql);
    }

    private function targetTable(string $sql): ?string
    {
        if (preg_match('/^\s*UPDATE\s+`?(\w+)`?/i', $sql, $m)) { return $m[1]; }
        if (preg_match('/\bINTO\s+`?(\w+)`?/i', $sql, $m))     { return $m[1]; }
        if (preg_match('/\bFROM\s+`?(\w+)`?/i', $sql, $m))     { return $m[1]; }
        return null;
    }

    /** Did a DELETE against tblMusicians actually run? */
    public function deletedMusician(): bool
    {
        foreach ($this->executed as $e) {
            if (preg_match('/DELETE FROM tblMusicians/i', $e['sql'])) { return true; }
        }
        return false;
    }
    public function lastTx(): string { return $this->tx === [] ? '(none)' : $this->tx[count($this->tx) - 1]; }
}

/* ================================================================= *
 *  LOAD THE UNIT UNDER TEST
 *  (musician_helpers.php's direct-access guard compares basenames; this
 *  test's basename never matches, so require_once sails through — the same
 *  reasoning test-musician-credit-tables-single-list.php's PART B gives.)
 * ================================================================= */
require_once $pubDir . '/includes/musician_helpers.php';

/** A clean, reapable row: person, no curation, all gated columns present. */
function reapBaselineRow(int $id): array
{
    return [
        'Id' => $id, 'IsSpecialCase' => 0, 'IsGroup' => 0, 'Notes' => null,
        'BirthPlace' => null, 'BirthPlaceId' => null, 'BirthDate' => null,
        'DeathPlace' => null, 'DeathPlaceId' => null, 'DeathDate' => null,
        'Type' => 'person', 'Disambiguation' => '', 'Biography' => null,
        'MusicBrainzArtistMBID' => null, 'MaidenSurname' => null,
    ];
}

/** Build a double primed with a clean baseline row (id 100). */
function reapCleanDouble(): ReapMysqli
{
    $db = new ReapMysqli();
    $db->musicianRow = reapBaselineRow(100);
    return $db;
}

echo "musicianReapOrphanedAutoRow() — the guard ladder\n\n";

/* --------------------------------------------------------------- *
 * (a) HAPPY PATH — clean orphan is deleted.
 * IMPORTANT: run first so the gate helpers' static cache is primed
 * with "all columns present" (they cache per-process on first call).
 * --------------------------------------------------------------- */
echo "(a) happy path\n";
$db = reapCleanDouble();
$res = musicianReapOrphanedAutoRow($db, 'John', 999);
ok('a clean, uncited, uncurated orphan is deleted (returns true)', $res === true);
ok('…the DELETE against tblMusicians actually ran', $db->deletedMusician());
ok('…and the transaction committed', $db->lastTx() === 'commit');

/* --------------------------------------------------------------- *
 * empty old-name is an immediate no-op (never opens a transaction).
 * --------------------------------------------------------------- */
echo "\n(a2) empty/whitespace old-name short-circuits\n";
$db = reapCleanDouble();
$res = musicianReapOrphanedAutoRow($db, '   ', 999);
ok('a blank old-name returns false without touching the DB', $res === false && $db->tx === []);

/* --------------------------------------------------------------- *
 * not-found row is a no-op (rolls back, deletes nothing).
 * --------------------------------------------------------------- */
echo "\n(a3) no matching row\n";
$db = reapCleanDouble();
$db->musicianRow = null;
$res = musicianReapOrphanedAutoRow($db, 'Ghost', 999);
ok('no row for the old name → false, nothing deleted, rolled back',
   $res === false && !$db->deletedMusician() && $db->lastTx() === 'rollback');

/* --------------------------------------------------------------- *
 * (c) SAME-ROW-BY-ID guard — a case/accent retype resolves to the
 * SAME row; comparing Ids (not names) must NOT delete it.
 * --------------------------------------------------------------- */
echo "\n(c) same-row-by-Id guard\n";
$db = reapCleanDouble();
$db->musicianRow = reapBaselineRow(500);   // this row's Id …
$res = musicianReapOrphanedAutoRow($db, 'John', 500);   // … equals keepRegistryId
ok('Id === keepRegistryId → never deletes the row the save just kept',
   $res === false && !$db->deletedMusician() && $db->lastTx() === 'rollback');

/* --------------------------------------------------------------- *
 * (b) MUTATION MATRIX — each step-4 curation signal, flipped alone,
 * must PREVENT the delete.
 * --------------------------------------------------------------- */
echo "\n(b1) each curation signal prevents the delete\n";
$signalMutations = [
    'IsSpecialCase set'          => ['IsSpecialCase' => 1],
    'IsGroup set'                => ['IsGroup' => 1],
    'Type is not person'         => ['Type' => 'group'],
    'Disambiguation present'     => ['Disambiguation' => 'the elder'],
    'Notes present'              => ['Notes' => 'a curator note'],
    'Biography present'          => ['Biography' => 'A hymn writer.'],
    'MusicBrainzArtistMBID set'  => ['MusicBrainzArtistMBID' => 'aaaa-bbbb'],
    'MaidenSurname set'          => ['MaidenSurname' => 'Cowper'],
    'BirthPlace present'         => ['BirthPlace' => 'London'],
    'DeathPlace present'         => ['DeathPlace' => 'Olney'],
    'BirthPlaceId set'           => ['BirthPlaceId' => 42],
    'BirthDate set'              => ['BirthDate' => '1725-07-24'],
    'DeathPlaceId set'           => ['DeathPlaceId' => 43],
    'DeathDate set'              => ['DeathDate' => '1807-12-21'],
];
foreach ($signalMutations as $label => $override) {
    $db = new ReapMysqli();
    $db->musicianRow = array_merge(reapBaselineRow(100), $override);
    $res = musicianReapOrphanedAutoRow($db, 'John', 999);
    ok("signal: {$label} → no delete", $res === false && !$db->deletedMusician() && $db->lastTx() === 'rollback');
}

/* step-5 orphan check: a live credit still cites the name → keep it. */
echo "\n(b2) still-cited name is kept (orphan check)\n";
$db = reapCleanDouble();
$db->usageCount = 2;
$res = musicianReapOrphanedAutoRow($db, 'John', 999);
ok('usageCount > 0 → no delete', $res === false && !$db->deletedMusician() && $db->lastTx() === 'rollback');

/* (b3) step-6: EACH reference/child table, flipped alone, prevents delete. */
echo "\n(b3) each reference/child row prevents the delete\n";
$refTables = [
    'tblMusicianAliases', 'tblMusicianIdentifiers', 'tblMusicianIPI',
    'tblMusicianLinks', 'tblMusicianExternalLinks', 'tblMusicianRelations',
    'tblMusicianDuplicatesDismissed', 'tblSongbookCompilers', 'tblTuneCredits',
    'tblPublishers', 'tblVocalParts',
];
foreach ($refTables as $tbl) {
    $db = reapCleanDouble();
    $db->refHits = [$tbl => true];
    $res = musicianReapOrphanedAutoRow($db, 'John', 999);
    ok("reference in {$tbl} → no delete", $res === false && !$db->deletedMusician() && $db->lastTx() === 'rollback');
}

/* --------------------------------------------------------------- *
 * (d) errno 1146 on an OPTIONAL reference table → still deletes
 * (an absent table can't hold a reference).
 * --------------------------------------------------------------- */
echo "\n(d) an absent optional reference table (1146) is skipped, delete proceeds\n";
$db = reapCleanDouble();
$db->absentTables = ['tblVocalParts' => true];   // e.g. un-migrated on this install
$res = musicianReapOrphanedAutoRow($db, 'John', 999);
ok('1146 on tblVocalParts → skipped, row still deleted', $res === true && $db->deletedMusician() && $db->lastTx() === 'commit');

/* --------------------------------------------------------------- *
 * (e) any OTHER DB error → returns false, nothing thrown, rolled back
 * (never delete on an unverified state).
 * --------------------------------------------------------------- */
echo "\n(e) a non-1146 DB error bails safely (no throw, no delete)\n";
$db = reapCleanDouble();
$db->errorTables = ['tblPublishers' => 1213];    // e.g. a deadlock mid-probe
$threw = false;
$res   = null;
try {
    $res = musicianReapOrphanedAutoRow($db, 'John', 999);
} catch (\Throwable $e) {
    $threw = true;
}
ok('a 1213 during a reference probe does NOT propagate out of the janitor', $threw === false);
ok('…it returns false and deletes nothing', $res === false && !$db->deletedMusician());
ok('…and it rolled the janitor transaction back', $db->lastTx() === 'rollback');

/* --------------------------------------------------------------- *
 * (f) TREE-DERIVED $refProbes COVERAGE — rule #34 (#10 fix, #1843).
 * $refProbes (musician_helpers.php) is a hand-typed 11-table map of
 * every table with a free-text/FK reference to tblMusicians, and the
 * (b3) mutation matrix above is a SECOND hand-typed copy of that same
 * list — appWeb/.sql/schema.sql is a THIRD. If a future table grows a
 * `REFERENCES tblMusicians(...)` FK (inline in a CREATE TABLE, or via
 * a later ALTER TABLE ... ADD CONSTRAINT) and nobody remembers to add
 * it to $refProbes, the reap's reference-probe loop no longer checks
 * that table — the janitor's DELETE proceeds unblocked and the FK's
 * own ON DELETE CASCADE/SET NULL silently mutates or deletes that
 * table's child rows out from under a curator, with nothing red
 * anywhere (exactly rule #30/#35's silent-no-op shape, applied to a
 * schema guard instead of a script one).
 *
 * BOTH sides here are derived from the tree, never typed literally:
 *   - the schema side walks schema.sql tracking the most recent
 *     CREATE TABLE / ALTER TABLE name and records it whenever a
 *     "REFERENCES tblMusicians" line appears while that table is the
 *     current one — this catches the FK however it was declared;
 *   - the $refProbes side regexes the array literal straight out of
 *     musician_helpers.php's live source.
 * The schema-derived set must be a SUBSET of (or equal to) the
 * $refProbes keys; anything schema.sql knows about that $refProbes
 * doesn't is reported by name so the fix is a one-line map addition,
 * not a hunt. See this file's mutation-proof note below the guard.
 * --------------------------------------------------------------- */
echo "\n(f) \$refProbes covers every schema.sql FK to tblMusicians (rule #34)\n";

$schemaPath = $root . '/appWeb/.sql/schema.sql';
$schemaSrc  = file_get_contents($schemaPath);
ok('schema.sql is readable', $schemaSrc !== false, $schemaPath);

/* Walk the file tracking the CURRENT owning table — the most recent
   CREATE TABLE / ALTER TABLE name seen — and record it whenever a
   "REFERENCES tblMusicians" line appears while that table is current.
   This catches an FK declared either inline in a CREATE TABLE's
   column/constraint block, or bolted on later via ALTER TABLE, without
   needing to parse full SQL statement boundaries. */
$schemaOwningTables = [];
$currentTable = null;
foreach (preg_split('/\R/', (string)$schemaSrc) as $line) {
    if (preg_match('/^\s*CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?/i', $line, $m)) {
        $currentTable = $m[1];
        continue;
    }
    if (preg_match('/^\s*ALTER\s+TABLE\s+`?(\w+)`?/i', $line, $m)) {
        $currentTable = $m[1];
        continue;
    }
    if ($currentTable !== null && preg_match('/REFERENCES\s+`?tblMusicians`?\b/i', $line)) {
        $schemaOwningTables[$currentTable] = true;
    }
}
$schemaOwningTables = array_keys($schemaOwningTables);
sort($schemaOwningTables);
ok('the schema.sql extractor found at least one owning table (sanity-checks the walker itself)',
   count($schemaOwningTables) > 0,
   'found: ' . implode(', ', $schemaOwningTables));

/* Extract $refProbes' table keys straight from musician_helpers.php's
   live source — the array literal between "$refProbes = [" and its
   closing "];" — rather than retyping the list a fourth time. */
$helpersPath = $pubDir . '/includes/musician_helpers.php';
$helpersSrc  = file_get_contents($helpersPath);
ok('musician_helpers.php is readable', $helpersSrc !== false, $helpersPath);

$refProbesKeys = [];
if (preg_match('/\$refProbes\s*=\s*\[(.*?)\n\s*\];/s', (string)$helpersSrc, $m)) {
    if (preg_match_all('/[\'"](\w+)[\'"]\s*=>/', $m[1], $km)) {
        $refProbesKeys = $km[1];
    }
}
sort($refProbesKeys);
ok('the $refProbes array literal was found and parsed out of musician_helpers.php',
   count($refProbesKeys) > 0,
   'source path: ' . $helpersPath);

$missingFromRefProbes = array_values(array_diff($schemaOwningTables, $refProbesKeys));
ok('every schema.sql table with a tblMusicians FK is covered by $refProbes',
   $missingFromRefProbes === [],
   $missingFromRefProbes === []
       ? ''
       : 'MISSING from $refProbes: ' . implode(', ', $missingFromRefProbes)
           . ' — schema-derived set: [' . implode(', ', $schemaOwningTables) . ']'
           . '; $refProbes keys: [' . implode(', ', $refProbesKeys) . ']');

/* MUTATION-PROOF (manual, done once while authoring this assertion,
   not re-run automatically on every CI pass): temporarily delete one
   entry (e.g. 'tblVocalParts' => 'MusicianId = ?',) from $refProbes in
   includes/musician_helpers.php, rerun this file, confirm assertion
   (f) goes RED and names exactly 'tblVocalParts' in the detail line,
   then restore the entry and confirm green again. Recorded result:
   see the session report — the assertion behaved as designed. */

/* ================================================================= */
if ($fail === 0) {
    echo "\nmusician-orphan-reap: every guard held (" . "happy path, "
       . count($signalMutations) . " signals, orphan check, "
       . count($refTables) . " reference tables, same-row-by-Id, 1146-skip, "
       . "error-bail, tree-derived \$refProbes/schema.sql coverage).\n";
    exit(0);
}
fwrite(STDERR, "\n{$fail} assertion(s) failed.\n");
exit(1);
