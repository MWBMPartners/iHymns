<?php

declare(strict_types=1);

/**
 * tests/php/lib/mysqli_doubles.php — the shared mysqli test doubles
 * =================================================================
 *
 * ELI5
 * ----
 * Pretend databases for the PHP suites: one that writes down every statement
 * it is handed and answers like a real STRICT-mode connection, and one whose
 * every prepare() simply fails.
 *
 * WHY A LIB
 * ---------
 * `GateRecorderMysqli` was born inside `test-song-relocate-gates.php` (#1691)
 * and `ClaimProbeFailingMysqli` inside `test-song-redirect-claim.php` (#1689).
 * The song soft-delete suite (#1694) needed BOTH, and both were `final` classes
 * embedded in procedural test scripts — unreachable without executing the whole
 * suite that hosts them. CLAUDE.md's modularity rule is explicit that the
 * second consumer is an extraction, not a copy, so they moved here VERBATIM
 * (the relocate-specific query recognitions included) plus the additive,
 * default-inert extensions the soft-delete suite needed — marked `#1694` below.
 * With every extension unset, the classes behave byte-for-byte as they did
 * in-file, which is what keeps the two older suites' mutation-tested coverage
 * intact.
 *
 * FAITHFULNESS IS THE POINT (the original #1691 D3/D4 lesson)
 * -----------------------------------------------------------
 * The recorder behaves the way a real connection under
 * `MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT` behaves: a statement against a
 * table that does not exist THROWS 1146, it never returns false — a deleted
 * existence-gate is a loud failure here, never a quiet extra query. And any
 * SELECT it does not recognise throws a `LogicException` NAMING the query, so
 * a new statement in code under test fails by name instead of being silently
 * vouched for with fake data.
 *
 * @see tests/php/test-song-relocate-gates.php   the original recorder host (#1691)
 * @see tests/php/test-song-redirect-claim.php   the original prober host (#1689)
 * @see tests/php/test-song-soft-delete.php      the consumer the extraction was for (#1694)
 * @link https://www.php.net/manual/en/mysqli.reporting-mode.php
 */

/* ===================================================================== *
 *  THE PROBE-FAILING DOUBLE (#1689)
 *
 *  Every prepare() throws — "the database broke mid-probe". Used to pin the
 *  fail-OPEN contracts: songRedirectClaimsId() and songSoftDeletedHolds()
 *  must answer false (degrade to pre-feature behaviour), never propagate
 *  and white-screen the hottest read path (#1228 STRICT class).
 * ===================================================================== */
final class ClaimProbeFailingMysqli extends \mysqli
{
    public function __construct() {}

    #[\ReturnTypeWillChange]
    public function prepare(string $query): mixed
    {
        throw new \mysqli_sql_exception('synthetic probe failure', 1146);
    }
}

/* ===================================================================== *
 *  THE RECORDING DOUBLE (#1691)
 *
 *  A mysqli stand-in that (a) writes down every statement PREPARED and
 *  every statement EXECUTED with its bound values, and (b) behaves the
 *  way a real connection under MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT
 *  behaves: a statement against a table that does not exist THROWS 1146,
 *  it never returns false. Faithfulness is the point — the D3/D4 defects
 *  only LOOK harmless in a test whose fake database forgives the query
 *  the real one would refuse.
 *
 *  prepare() may return any object with the four methods the code under
 *  test calls (bind_param / execute / get_result / close) because every
 *  call site duck-types its statement — the same latitude
 *  ProbeFailingMysqli (test-optional-table-probes.php) already relies on.
 * ===================================================================== */

/** The rows a recognised SELECT hands back — assoc for fetch_assoc, values for fetch_row. */
final class GateRecorderResult
{
    public function __construct(private ?array $row) {}
    public function fetch_row(): ?array   { return $this->row === null ? null : array_values($this->row); }
    public function fetch_assoc(): ?array { return $this->row; }
}

/** One prepared statement: remembers its SQL, reports its execution back to the db. */
final class GateRecorderStmt
{
    /** @var list<mixed> references to the caller's bound variables */
    private array $refs = [];

    public function __construct(private GateRecorderMysqli $db, private string $sql) {}

    /* By-ref variadic, exactly like the real bind_param: the VALUES are read at
       execute() time, not bind time, so a caller that binds once and executes
       in a loop (songRelocate's SongCount recompute does) records truthfully. */
    public function bind_param(string $types, mixed &...$vars): bool
    {
        $this->refs = $vars;
        return true;
    }

    public function execute(): bool
    {
        $params = array_map(static fn($v) => $v, $this->refs);   // dereference NOW
        $this->db->recordExecute($this->sql, $params);
        return true;
    }

    public function get_result(): GateRecorderResult
    {
        return new GateRecorderResult(
            $this->db->rowFor($this->sql, array_map(static fn($v) => $v, $this->refs))
        );
    }

    public function close(): bool { return true; }
}

final class GateRecorderMysqli extends \mysqli
{
    /** The optional tables this scenario says exist: name => true. */
    public array $tables = [];
    /** TRUE = every INFORMATION_SCHEMA existence probe throws (the probe-failure mode). */
    public bool $probeThrows = false;
    /** Regex; an EXECUTE of a matching statement throws (the M3 fatality scenario). */
    public ?string $failExecutePattern = null;
    /** Result for the uq_book_song membership SELECT (branch pick in the entries rewrite). */
    public ?array $entriesTargetRow = null;
    /** @var list<string> ids that exist in tblSongs */
    public array $liveSongIds = [];
    /** @var list<string> ids a tblSongRedirects row claims (OldSongId) */
    public array $redirectClaimedIds = [];

    /* ---- #1694 additions (default-inert; the relocate/claim suites set none) -- */
    /** Columns the INFORMATION_SCHEMA.COLUMNS probes should see: 'tbl.Col' => true. */
    public array $columns = [];
    /** tblSongs rows by SongId for the soft-delete SELECTs, e.g. 'MP-1008' => ['IsDeleted' => 1]. */
    public array $songRows = [];
    /** PublicId => SongId map for songPublicId_resolveToSongId()'s lookup. */
    public array $publicIdMap = [];
    /** @var list<string> transaction lifecycle, in order: 'begin' | 'commit' | 'rollback'. */
    public array $tx = [];

    /** @var list<string> every SQL string handed to prepare(), in order */
    public array $prepared = [];
    /** @var list<array{sql:string, params:list<mixed>}> every execute(), in order */
    public array $executed = [];

    /* The three tables the move treats as optional. A statement against one of
       these when the scenario says it is absent throws 1146 — the STRICT-mode
       behaviour that makes a deleted gate a loud failure instead of a quiet
       extra query. tblSongs / tblSongbooks always exist (they are not gated
       anywhere and a scenario without them is not a scenario). */
    private const OPTIONAL_TABLES = ['tblContentRestrictions', 'tblSongbookEntries', 'tblSongRedirects'];

    public function __construct() {}   // never connects; see the spike note above

    /* #1694 — transaction lifecycle recorded, never sent anywhere. The write
       cores wrap their UPDATE in begin/commit with rollback-on-throw; recording
       the sequence gives the suite a runtime handle on "a refusal rolls back"
       without a live server. Signatures mirror the real methods so a caller
       using flags/name still lands here. */
    public function begin_transaction(int $flags = 0, ?string $name = null): bool { $this->tx[] = 'begin'; return true; }
    public function commit(int $flags = 0, ?string $name = null): bool { $this->tx[] = 'commit'; return true; }
    public function rollback(int $flags = 0, ?string $name = null): bool { $this->tx[] = 'rollback'; return true; }

    #[\ReturnTypeWillChange]
    public function prepare(string $query): mixed
    {
        $sql = trim((string)preg_replace('/\s+/', ' ', $query));

        /* INFORMATION_SCHEMA probes are handled FIRST: I_S itself always
           exists, and the probe-failure mode makes exactly these throw.
           (#1694 widened the match from .TABLES to any I_S view so the
           COLUMNS probes behind songSoftDeleteReady() get the same
           treatment; the relocate suites only ever issue .TABLES probes,
           for which this is behaviour-identical.) */
        if (stripos($sql, 'INFORMATION_SCHEMA.') !== false) {
            if ($this->probeThrows) {
                throw new \mysqli_sql_exception('synthetic probe failure', 2006);
            }
            $this->prepared[] = $sql;
            return new GateRecorderStmt($this, $sql);
        }

        /* A real-table statement while its table is absent: refuse at prepare,
           as MySQL does (ER_NO_SUCH_TABLE surfaces when the statement is
           prepared, before any execute). */
        $table = $this->targetTable($sql);
        if (in_array($table, self::OPTIONAL_TABLES, true) && !($this->tables[$table] ?? false)) {
            throw new \mysqli_sql_exception("Table 'ihymns.{$table}' doesn't exist", 1146);
        }

        $this->prepared[] = $sql;
        return new GateRecorderStmt($this, $sql);
    }

    public function recordExecute(string $sql, array $params): void
    {
        if ($this->failExecutePattern !== null && preg_match($this->failExecutePattern, $sql)) {
            throw new \mysqli_sql_exception('synthetic execute failure', 1451);
        }
        $this->executed[] = ['sql' => $sql, 'params' => $params];
    }

    /**
     * Answer the SELECTs the code under test is allowed to ask. Anything
     * unrecognised throws a LogicException NAMING the query — an unknown
     * statement must be a red test naming itself, never a silent "no rows"
     * that mimics data.
     */
    public function rowFor(string $sql, array $params): ?array
    {
        /* #1694 — INFORMATION_SCHEMA.COLUMNS, two spellings: the lockstep
           COUNT over an IN-list (songSoftDeleteReady) and the single-column
           existence probe (songPublicId_columnReady / the migration helpers).
           The table name is baked (= 'tblX') or bound (= ? → params[0]). */
        if (stripos($sql, 'INFORMATION_SCHEMA.COLUMNS') !== false) {
            $tbl = null;
            if (preg_match("/TABLE_NAME\s*=\s*'(\w+)'/i", $sql, $m)) {
                $tbl = $m[1];
            } elseif (strpos($sql, 'TABLE_NAME = ?') !== false) {
                $tbl = (string)($params[0] ?? '');
            }
            if ($tbl !== null && preg_match("/COLUMN_NAME\s+IN\s*\(([^)]*)\)/i", $sql, $m)) {
                preg_match_all("/'(\w+)'/", $m[1], $cols);
                $n = 0;
                foreach ($cols[1] as $c) {
                    if ($this->columns[$tbl . '.' . $c] ?? false) { $n++; }
                }
                return ['cnt' => $n];   // fetch_row() → [$n], matching COUNT(*)
            }
            if ($tbl !== null) {
                $col = null;
                if (preg_match("/COLUMN_NAME\s*=\s*'(\w+)'/i", $sql, $m)) {
                    $col = $m[1];
                } elseif (strpos($sql, 'COLUMN_NAME = ?') !== false) {
                    $col = (string)(end($params) ?: '');
                }
                if ($col !== null) {
                    return ($this->columns[$tbl . '.' . $col] ?? false) ? ['1' => 1] : null;
                }
            }
            throw new \LogicException('GateRecorderMysqli: unrecognised I_S.COLUMNS query: ' . $sql);
        }
        if (stripos($sql, 'INFORMATION_SCHEMA.TABLES') !== false) {
            /* Two probe spellings exist: songRelocateTableExists() binds the
               table name; songRedirectsTableReady() bakes it into the SQL. */
            $name = null;
            if ($params !== [] && strpos($sql, 'TABLE_NAME = ?') !== false) {
                $name = (string)$params[0];
            } elseif (preg_match("/TABLE_NAME\s*=\s*'(\w+)'/i", $sql, $m)) {
                $name = $m[1];
            }
            return ($name !== null && ($this->tables[$name] ?? false)) ? ['1' => 1] : null;
        }
        if (preg_match('/^SELECT 1 FROM tblSongs WHERE SongId = \?/i', $sql)) {
            return in_array((string)($params[0] ?? ''), $this->liveSongIds, true) ? ['1' => 1] : null;
        }
        /* #1694 — the soft-delete row-state SELECT, with or without the write
           cores' FOR UPDATE lock suffix. */
        if (preg_match('/^SELECT IsDeleted FROM tblSongs WHERE SongId = \? LIMIT 1( FOR UPDATE)?$/i', $sql)) {
            $row = $this->songRows[(string)($params[0] ?? '')] ?? null;
            return $row === null ? null : ['IsDeleted' => (int)($row['IsDeleted'] ?? 0)];
        }
        /* #1694 — songPublicId_resolveToSongId()'s lookup. */
        if (preg_match('/^SELECT SongId FROM tblSongs WHERE PublicId = \? LIMIT 1$/i', $sql)) {
            $sid = $this->publicIdMap[(string)($params[0] ?? '')] ?? null;
            return $sid === null ? null : ['SongId' => (string)$sid];
        }
        if (preg_match('/^SELECT 1 FROM tblSongRedirects WHERE OldSongId = \?/i', $sql)) {
            return in_array((string)($params[0] ?? ''), $this->redirectClaimedIds, true) ? ['1' => 1] : null;
        }
        if (preg_match('/^SELECT Id FROM tblSongbookEntries WHERE SongbookAbbr = \? AND SongId = \?/i', $sql)) {
            return $this->entriesTargetRow;
        }
        throw new \LogicException('GateRecorderMysqli: unrecognised query: ' . $sql);
    }

    /** Which real table does this statement target? (Mirrors the lib's marker logic.) */
    private function targetTable(string $sql): ?string
    {
        if (preg_match('/^\s*UPDATE\s+`?(\w+)`?/i', $sql, $m))          { return $m[1]; }
        if (preg_match('/\bINTO\s+`?(\w+)`?/i', $sql, $m))              { return $m[1]; }
        if (preg_match('/\bFROM\s+`?(\w+)`?/i', $sql, $m))              { return $m[1]; }
        return null;
    }

    /** Every prepared SQL matching $re — for "was this statement ever issued?" */
    public function preparedMatching(string $re): array
    {
        return array_values(array_filter($this->prepared, static fn(string $s): bool => (bool)preg_match($re, $s)));
    }

    /** Every executed entry matching $re, in execution order. */
    public function executedMatching(string $re): array
    {
        return array_values(array_filter($this->executed, static fn(array $e): bool => (bool)preg_match($re, $e['sql'])));
    }
}
