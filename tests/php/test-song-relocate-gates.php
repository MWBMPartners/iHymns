<?php

declare(strict_types=1);

/**
 * iHymns — the songbook move's existence gates BEHAVE correctly (#1691 D3/D4/D5)
 * ==============================================================================
 *
 * ELI5
 * ----
 * Before the move touches an optional table it asks "does that table even exist
 * here?", and before it hands out a new song id it asks "is any old bookmark
 * still being forwarded away from this id?". This file does not read the code
 * that asks — it CALLS it, against a pretend database that writes down every
 * statement it is given, and checks what actually happened in every case:
 * table there, table missing, and "I could not find out".
 *
 * WHY THIS FILE EXISTS — the D3/D4/D5 regress
 * -------------------------------------------
 * These gates were guarded by source inspection in
 * `test-song-relocate-hardening.php`, and an adversarial pass (#1691) defeated
 * every one of those guards against the real tree:
 *
 *   D3  "every gated statement is INSIDE the existence gate" never looked at
 *       the gate's POLARITY. Invert the `if` — so the writes run only when the
 *       table is ABSENT — and containment still holds, the suite stays green,
 *       and on an un-migrated install the move throws mid-transaction while on
 *       a migrated one it silently skips the security-relevant rewrite.
 *   D4  "the redirect gate SHORT-CIRCUITS" accepted any `return` token between
 *       the gate and the statement, so an unrelated `if ($songId === '') {
 *       return false; }` satisfied it with the real early return deleted and
 *       the SELECT running ungated.
 *   D5  nothing pinned WHICH probe a gate asks. Swap the strict
 *       `songRelocateTableExists()` for the swallowing
 *       `songRedirectsTableReady()` and a broken probe silently skips the
 *       content-restriction rewrite — "withheld content becomes readable", in
 *       the source's own words. (`test-optional-table-probes.php` pins what
 *       each probe DOES; only a call can pin which one is ASKED.)
 *
 * The fix follows `test-transaction-fatal.php`'s rule — when a property lives
 * in a function, TEST THE FUNCTION. #1691 extracted the two gated blocks into
 * `songRelocateRewriteRestrictions()` / `songRelocateRewriteEntries()`, whose
 * gate is an early `return false`, so all three properties now have runtime
 * handles:
 *
 *   - POLARITY is the return value plus the statement stream: absent → FALSE
 *     and NOTHING issued; present → TRUE and exactly the expected statements.
 *     An inverted gate fails both modes at once.
 *   - SHORT-CIRCUIT is the statement stream: the double THROWS 1146 on any
 *     statement against a table it was told does not exist — exactly what
 *     mysqli STRICT does in production — so a deleted early return is not a
 *     quiet extra query, it is a red assertion naming the statement.
 *   - PROBE IDENTITY is the failure contract: when the probe itself fails, the
 *     strict probe THROWS and the swallowing one answers false. The
 *     probe-failure mode asserts the throw propagates, which a swapped-in
 *     swallowing probe can never satisfy.
 *
 * WHY THREE CHILD PROCESSES
 * -------------------------
 * Both probes memoise per request (`static` — a bulk move must not re-ask
 * INFORMATION_SCHEMA per song), so within ONE process a table's existence can
 * be settled exactly once. Testing "absent" and "present" for the SAME tables
 * therefore needs separate processes; probe FAILURES are deliberately not
 * memoised (song_redirects.php documents why), so they get their own mode too
 * rather than relying on ordering tricks. The parent spawns each mode and
 * requires both a zero exit AND the mode's completion sentinel, so a child
 * that fatals early — or somehow exits 0 without running — is a failure, not
 * a silent skip.
 *
 * WHAT THIS FILE CANNOT CATCH (so its tick is not over-read)
 * ----------------------------------------------------------
 *   - Whether the SQL is CORRECT against a real schema — there is no MySQL in
 *     CI. The double answers shapes it recognises and throws LOUDLY on any
 *     statement it does not, so a new statement in a gated path fails here by
 *     name instead of being silently vouched for.
 *   - Whether songRelocate() still CALLS the helpers, in order, unswallowed —
 *     delegation has no runtime handle without a live DB, so that stays a
 *     source assertion in `test-song-relocate-hardening.php`.
 *
 *   php tests/php/test-song-relocate-gates.php            # parent: all modes
 *   IHYMNS_RELOCATE_GATES_MODE=migrated php tests/php/test-song-relocate-gates.php
 *
 * Exit status 0 = all pass, 1 = a failure.
 *
 * @see appWeb/public_html/includes/song_relocate.php
 * @see tests/php/test-transaction-fatal.php       — the rule this follows
 * @see tests/php/test-optional-table-probes.php   — the probes' own contracts
 */

$ROOT = dirname(__DIR__, 2);
require_once $ROOT . '/appWeb/public_html/includes/song_relocate.php';

$fail = 0;
function ok(string $label, bool $cond, string $detail = ''): void
{
    global $fail;
    echo ($cond ? "  PASS  " : "  FAIL  ") . $label . "\n";
    if (!$cond) {
        $fail++;
        if ($detail !== '') { echo "        " . str_replace("\n", "\n        ", $detail) . "\n"; }
    }
}

/* ===================================================================== *
 *  THE RECORDING DOUBLE
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

    #[\ReturnTypeWillChange]
    public function prepare(string $query): mixed
    {
        $sql = trim((string)preg_replace('/\s+/', ' ', $query));

        /* INFORMATION_SCHEMA probes are handled FIRST: I_S itself always
           exists, and the probe-failure mode makes exactly these throw. */
        if (stripos($sql, 'INFORMATION_SCHEMA.TABLES') !== false) {
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
     * Answer the SELECTs the gates are allowed to ask. Anything unrecognised
     * throws a LogicException NAMING the query — an unknown statement must be
     * a red test naming itself, never a silent "no rows" that mimics data.
     */
    public function rowFor(string $sql, array $params): ?array
    {
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

/* ===================================================================== *
 *  THE THREE MODES
 * ===================================================================== */

const GATES_MODE_ENV = 'IHYMNS_RELOCATE_GATES_MODE';
const GATES_MODES    = ['unmigrated', 'migrated', 'probe-failure'];

$mode = getenv(GATES_MODE_ENV) ?: '';

if ($mode === '') {
    /* -------- parent: run every mode in its own process ---------------- *
     * Each child settles the per-request probe memos exactly once, which is
     * the whole reason the split exists (see the file doc-block). */
    $allOk = true;
    foreach (GATES_MODES as $m) {
        echo "== mode: {$m} ==\n";
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__);
        putenv(GATES_MODE_ENV . '=' . $m);
        exec($cmd . ' 2>&1', $lines, $code);
        putenv(GATES_MODE_ENV);   // unset for the next iteration / cleanliness
        echo implode("\n", $lines) . "\n";
        /* Exit code AND sentinel: a child that fatals prints no sentinel; one
           that somehow exits 0 without running its assertions has no sentinel
           either. Both are failures, never silent skips. */
        $sentinel = (bool)preg_grep('/^MODE COMPLETE ' . preg_quote($m, '/') . ' \(\d+ assertion/', $lines);
        if ($code !== 0 || !$sentinel) {
            $allOk = false;
            fwrite(STDERR, "mode '{$m}' FAILED (exit {$code}" . ($sentinel ? '' : ', sentinel missing') . ")\n");
        }
        $lines = [];
        echo "\n";
    }
    if ($allOk) {
        echo "All songbook-move gate behaviour assertions passed.\n";
        exit(0);
    }
    fwrite(STDERR, "one or more modes failed.\n");
    exit(1);
}

if (!in_array($mode, GATES_MODES, true)) {
    fwrite(STDERR, "Unknown mode '{$mode}'. Valid: " . implode(', ', GATES_MODES) . "\n");
    exit(1);
}

$checks = 0;      // for the completion sentinel
$countedOk = function (string $label, bool $cond, string $detail = '') use (&$checks): void {
    $checks++;
    ok($label, $cond, $detail);
};

/* --------------------------------------------------------------------- */
if ($mode === 'unmigrated') {
    echo "An install where NONE of the optional tables exist (migrations are web-run)\n";

    $db = new GateRecorderMysqli();   // tables = [] — nothing optional exists

    /* -- D3: the restrictions gate, absent side ------------------------- */
    $ran = songRelocateRewriteRestrictions($db, 'MP-0041', 'CP-0007');
    $countedOk('songRelocateRewriteRestrictions() answers FALSE when tblContentRestrictions is absent',
       $ran === false,
       'got ' . var_export($ran, true) . ' — TRUE here claims a rewrite that cannot have happened');
    $countedOk('…and it issued NO statement against tblContentRestrictions',
       $db->preparedMatching('/tblContentRestrictions/') === [],
       'statements prepared: ' . json_encode($db->prepared)
       . ' — an ungated statement here throws 1146 under mysqli STRICT inside the '
       . "caller's transaction and rolls back the entire song save (#1228)");
    $countedOk('…and it reached that answer by actually ASKING (the I_S probe ran)',
       $db->preparedMatching('/INFORMATION_SCHEMA\.TABLES/') !== [],
       'no probe was prepared — a hardcoded `return false` would skip the rewrite '
       . 'on MIGRATED installs too, which the migrated mode also catches');

    /* -- D3: the entries gate, absent side ------------------------------ */
    $ran = songRelocateRewriteEntries($db, 'CP', 'CP-0007');
    $countedOk('songRelocateRewriteEntries() answers FALSE when tblSongbookEntries is absent',
       $ran === false);
    $countedOk('…and it issued NO statement against tblSongbookEntries',
       $db->preparedMatching('/tblSongbookEntries/') === [],
       'statements prepared: ' . json_encode($db->prepared));

    /* -- D4: the mint's redirect gate, absent side ----------------------- */
    $before = count($db->prepared);
    $taken  = songRelocateIdTaken($db, 'CP-0007');
    $countedOk('songRelocateIdTaken() answers FALSE for a free id when tblSongRedirects is absent',
       $taken === false,
       'got ' . var_export($taken, true));
    $countedOk('…and the redirect SELECT was NEVER prepared — the gate short-circuits (#1691 D4)',
       $db->preparedMatching('/FROM tblSongRedirects/') === [],
       'this is the property the old source guard could not hold: any `return` token '
       . 'between the gate and the SELECT satisfied it, including an unrelated one, '
       . 'while the SELECT ran ungated and threw 1146 mid-transaction');
    $countedOk('…and it DID probe tblSongs and ask the redirect-table gate',
       $db->preparedMatching('/FROM tblSongs\b/') !== []
       && count($db->prepared) > $before,
       'statements prepared: ' . json_encode(array_slice($db->prepared, $before)));

    /* -- M1's other short-circuit: a LIVE id needs no redirect answer ---- */
    $db->liveSongIds = ['MP-0041'];
    $before = count($db->prepared);
    $taken  = songRelocateIdTaken($db, 'MP-0041');
    $countedOk('an id already live in tblSongs is TAKEN, decided by the live probe alone',
       $taken === true && count($db->prepared) === $before + 1,
       'got ' . var_export($taken, true) . '; statements this call: '
       . json_encode(array_slice($db->prepared, $before)));

    echo "MODE COMPLETE {$mode} ({$checks} assertions)\n";
}

/* --------------------------------------------------------------------- */
if ($mode === 'migrated') {
    echo "An install where every optional table exists — the gates must OPEN\n";

    $db = new GateRecorderMysqli();
    $db->tables = [
        'tblContentRestrictions' => true,
        'tblSongbookEntries'     => true,
        'tblSongRedirects'       => true,
    ];

    /* -- D3 polarity, present side: the rewrite must actually RUN -------- */
    $ran = songRelocateRewriteRestrictions($db, 'MP-0041', 'CP-0007');
    $rsUpd = $db->executedMatching('/^UPDATE tblContentRestrictions SET EntityId = \?/i');
    $countedOk('songRelocateRewriteRestrictions() answers TRUE when the table exists',
       $ran === true,
       'got ' . var_export($ran, true) . ' — an INVERTED gate (the #1691 D3 mutation) '
       . 'runs the block only when the table is ABSENT, so it answers false here');
    $countedOk('…and it issued exactly ONE restrictions UPDATE',
       count($rsUpd) === 1,
       'executed: ' . json_encode($db->executed));
    $countedOk('…binding (new id, old id) IN THAT ORDER — SET to the new, matched on the old',
       $rsUpd !== [] && $rsUpd[0]['params'] === ['CP-0007', 'MP-0041'],
       'params were ' . json_encode($rsUpd[0]['params'] ?? null) . ' — swapped, the UPDATE '
       . 'matches zero rows and every restriction stays on the dead id, silently');

    /* -- F3 branch A: no membership row in the target yet ---------------- */
    $db->entriesTargetRow = null;
    $ran = songRelocateRewriteEntries($db, 'CP', 'CP-0007');
    $homeUpd = $db->executedMatching('/^UPDATE tblSongbookEntries SET SongbookAbbr = \?, SongNumber = NULL/i');
    $countedOk('songRelocateRewriteEntries() answers TRUE when the table exists (branch A: simple move)',
       $ran === true);
    $countedOk('…branch A moves the home row: UPDATE … SET SongbookAbbr=?, SongNumber=NULL bound (target, new id)',
       count($homeUpd) === 1 && $homeUpd[0]['params'] === ['CP', 'CP-0007'],
       'executed: ' . json_encode($db->executed));
    $countedOk('…and branch A deletes NOTHING (there is no duplicate membership to clear)',
       $db->executedMatching('/^DELETE FROM tblSongbookEntries/i') === []);

    /* -- F3 branch B: the song is already a member of the target book ----- */
    $db->entriesTargetRow = ['Id' => 77];
    $execBefore = count($db->executed);
    $ran = songRelocateRewriteEntries($db, 'CP', 'CP-0007');
    $thisCall = array_slice($db->executed, $execBefore);
    $delIx = $proIx = null;
    foreach ($thisCall as $i => $e) {
        if ($delIx === null && preg_match('/^DELETE FROM tblSongbookEntries/i', $e['sql'])) { $delIx = $i; }
        if ($proIx === null && preg_match('/^UPDATE tblSongbookEntries SET IsHome = 1/i', $e['sql'])) { $proIx = $i; }
    }
    $countedOk('branch B (already a member): the stale old-book home row is DELETEd',
       $ran === true && $delIx !== null
       && $thisCall[$delIx]['params'] === ['CP-0007', 'CP'],
       'this call executed: ' . json_encode($thisCall));
    $countedOk('…and the existing target row is PROMOTED to home, by the Id the probe found',
       $proIx !== null && $thisCall[$proIx]['params'] === [77]);
    $countedOk('…with the DELETE strictly BEFORE the promotion (uq_book_song must be free first)',
       $delIx !== null && $proIx !== null && $delIx < $proIx,
       'promoting first leaves two IsHome=1 rows in flight');

    /* -- M1 present side: the redirect claim is consulted and BELIEVED ---- */
    $db->redirectClaimedIds = ['CP-0007'];
    $taken = songRelocateIdTaken($db, 'CP-0007');
    $countedOk('an id a live redirect forwards away from is TAKEN (#1679 M1)',
       $taken === true,
       'got ' . var_export($taken, true) . ' — re-issuing this id makes getSongById() '
       . 'serve a DIFFERENT song to every old bookmark, 200 OK');
    $taken = songRelocateIdTaken($db, 'CP-9999');
    $countedOk('…an id free in BOTH tables is free, and the redirect probe really ran',
       $taken === false && $db->executedMatching('/FROM tblSongRedirects/i') !== [],
       'got ' . var_export($taken, true) . '; redirect probes executed: '
       . count($db->executedMatching('/FROM tblSongRedirects/i')));

    /* -- M3 fatality: a failing rewrite must PROPAGATE, never be swallowed - */
    $db->failExecutePattern = '/^UPDATE tblContentRestrictions/i';
    $caught = null;
    try {
        songRelocateRewriteRestrictions($db, 'MP-0041', 'CP-0007');
    } catch (\Throwable $e) {
        $caught = $e;
    }
    $countedOk('a failing restrictions UPDATE propagates out of the helper (M3: the rewrite is FATAL)',
       $caught instanceof \mysqli_sql_exception,
       'caught ' . ($caught === null ? 'nothing — the helper swallowed the one security-relevant '
           . 'failure of the move, which is the original M3 defect verbatim' : get_class($caught)));

    $db->failExecutePattern = '/^UPDATE tblSongbookEntries SET SongbookAbbr/i';
    $caught = null;
    try {
        $db->entriesTargetRow = null;
        songRelocateRewriteEntries($db, 'CP', 'CP-0007');
    } catch (\Throwable $e) {
        $caught = $e;
    }
    $countedOk('a failing entries rewrite propagates too — the junction invariant is the point',
       $caught instanceof \mysqli_sql_exception,
       'caught ' . ($caught === null ? 'nothing' : get_class($caught)));
    $db->failExecutePattern = null;

    echo "MODE COMPLETE {$mode} ({$checks} assertions)\n";
}

/* --------------------------------------------------------------------- */
if ($mode === 'probe-failure') {
    echo "The probes themselves fail — strictness is WHICH PROBE the gate asks (#1691 D5)\n";

    $db = new GateRecorderMysqli();
    $db->probeThrows = true;

    /* A swap to the swallowing songRedirectsTableReady() answers FALSE here
       instead of throwing — so each of these goes red on that exact mutation,
       which no settled-mode scenario can see (both probes agree whenever the
       probe WORKS). */
    foreach ([
        'songRelocateRewriteRestrictions' => static fn(\mysqli $d) => songRelocateRewriteRestrictions($d, 'MP-0041', 'CP-0007'),
        'songRelocateRewriteEntries'      => static fn(\mysqli $d) => songRelocateRewriteEntries($d, 'CP', 'CP-0007'),
    ] as $name => $call) {
        $caught = null;
        $answer = null;
        try {
            $answer = $call($db);
        } catch (\Throwable $e) {
            $caught = $e;
        }
        $countedOk("{$name}() re-throws when its existence probe fails — it must not guess",
           $caught !== null,
           'it answered ' . var_export($answer, true) . ' instead. That is the swallowing '
           . "probe's contract, not the strict one's: a false-because-the-probe-broke "
           . 'silently skips the rewrite — for the restrictions, withheld content becomes '
           . 'readable while the move commits anyway');
        $countedOk('…with the mysqli cause intact somewhere in the chain',
           $caught instanceof \mysqli_sql_exception
           || ($caught !== null && $caught->getPrevious() instanceof \mysqli_sql_exception),
           'caught ' . ($caught === null ? 'nothing' : get_class($caught)));
    }
    $countedOk('and neither helper touched its gated table on the way out',
       $db->preparedMatching('/tblContentRestrictions|tblSongbookEntries/') === [],
       'prepared: ' . json_encode($db->prepared));

    /* -- D5's mint half: songRelocateIdTaken() must use the STRICT spelling.
       The swallowing `songRedirectsTableReady($db)` (no second argument)
       catches this same failure and answers false — "no redirect claims it" —
       which silently switches the whole M1 check off (#1679 A13b). */
    $caught = null;
    $answer = null;
    try {
        $answer = songRelocateIdTaken($db, 'CP-0007');
    } catch (\Throwable $e) {
        $caught = $e;
    }
    $countedOk('songRelocateIdTaken() re-throws when the redirect-table probe fails (strict mode)',
       $caught instanceof \RuntimeException,
       'it answered ' . var_export($answer, true) . ' instead — for a caller about to issue '
       . 'a PERMANENT id, "I could not check" must never read as "nothing claims it"');
    $countedOk('…wrapping the original mysqli failure as its previous',
       $caught !== null && $caught->getPrevious() instanceof \mysqli_sql_exception,
       'caught ' . ($caught === null ? 'nothing' : get_class($caught))
       . ' — songRelocateIsTransactionFatal() walks getPrevious(), so losing the cause '
       . 'would also hide a deadlock (the N1 lesson)');
    $countedOk('…and no tblSongRedirects statement was prepared on the way out',
       $db->preparedMatching('/FROM tblSongRedirects/') === []);

    echo "MODE COMPLETE {$mode} ({$checks} assertions)\n";
}

if ($fail === 0) {
    exit(0);
}
fwrite(STDERR, "\n{$fail} assertion(s) failed.\n");
exit(1);
