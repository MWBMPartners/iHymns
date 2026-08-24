<?php

declare(strict_types=1);

/**
 * iHymns — Medley write core: workMedley*() guard unit tests (#1860 Phase 5,
 * Commit 5 — `.claude/medley-component-work-1860-phase5-plan.md` §5.3)
 * ==============================================================================
 *
 * ELI5
 * ----
 * `tblWorkComponents` says "medley Work A contains constituent Work B". The
 * five `workMedley*()` functions in `includes/work_admin.php` are the ONLY
 * place anything reads or writes that table. This file calls them directly
 * against a small, hand-built fake database — no live MySQL involved — and
 * checks each guard actually stops the write it claims to stop: a work can
 * never medley-of-itself, an attach that would close a containment loop is
 * refused (both a one-hop and a three-hop loop), re-attaching an existing
 * pair changes nothing (SD2's "never overwrites"), and every function stays
 * dormant (no query at all past the readiness probe) on an un-migrated
 * install.
 *
 * WHY A HAND-BUILT DOUBLE, NOT `tests/php/lib/mysqli_doubles.php`
 * -----------------------------------------------------------------
 * The shared `GateRecorderMysqli` answers each recognised SELECT with AT
 * MOST one row (`GateRecorderResult` wraps a single `?array`) and its query
 * table has no entries for `tblWorkComponents`/`tblWorks`. The BFS cycle
 * check here needs MULTIPLE rows per query (a medley's several
 * constituents), and extending the shared double is out of this commit's
 * file scope (`includes/work_admin.php`, `manage/editor/api2.php`, this
 * file — the locked spec's own file list). So this file follows the OTHER
 * established precedent instead: a small, self-contained, per-test-file
 * double (`tests/php/test-optional-table-probes.php`'s `ProbeFailingMysqli`
 * is the model) that answers ONLY the exact statements the code under test
 * issues and throws `LogicException`, naming the query, on anything else —
 * an unrecognised statement fails LOUDLY rather than being silently vouched
 * for with fake data (the "faithfulness is the point" doctrine
 * `mysqli_doubles.php`'s own doc-block states).
 *
 * WHY ONE SCENARIO RUNS IN A SEPARATE PHP PROCESS
 * -------------------------------------------------
 * `workMedleyReady()` memoises with a bare `static $cached = null;` — the
 * EXACT `workAdminReady()` idiom right above it in the source, and (like
 * that function) NOT keyed by which `\mysqli` was passed. Call it once in
 * this PHP process and every LATER call, even against a totally different
 * double, returns the SAME cached boolean — correct for production (one
 * process per web request) but fatal to testing both a "ready" and a
 * "not-ready" install in one script. T1-T4 below all need ready()===true and
 * run first (deliberately poisoning the process-wide cache to `true`, which
 * is harmless — every later assertion in this file wants `true` too). T5
 * (the not-ready / short-circuit scenario) is therefore spawned as a
 * genuinely separate `php` CLI process, mirroring
 * `test-intapps-stub-e2e.php`'s D1 probe (`shell_exec(PHP_BINARY …)`) —
 * copied verbatim, not reinvented (rule #22 applied to test infrastructure).
 *
 * MUTATION-TESTED (rule #34)
 * ---------------------------
 * Performed during this build, transcript in the commit body: in
 * `workMedleyWouldCycle()`, the reachability check
 *     if ($child === $medleyId) { ... return true; }
 * was inverted to `!==` (so the function reports "cycle!" for the FIRST
 * child that is NOT the medley, instead of for the child that IS). First
 * pass: this correctly turned T2 (depth-1 cycle) RED, but T3 (depth-3
 * cycle) stayed GREEN — a false sense of coverage, because T3's chain also
 * reaches the medley on 302's very FIRST child-of-a-child, so "always true
 * on any non-matching child" and a CORRECT BFS happen to agree on T3's
 * particular graph by coincidence (rule #34's "wrong but green" trap,
 * caught here rather than shipped). T3c was added specifically to kill that
 * coincidence: a constituent containing an UNRELATED work with NO path back
 * to the medley. Re-running with the mutation in place turned BOTH T2 and
 * T3c RED (T3c reports "refused" when the correct answer is "accepted" — the
 * inverse failure direction from T2, which is the point: one test proves
 * false negatives don't slip through, the other proves false positives
 * don't either). The mutation was then reverted and this file passes again,
 * T3c included. See the session report for the exact before/after output.
 *
 *   php tests/php/test-work-medley-core.php
 *
 * Exit status 0 = all assertions pass, 1 = at least one failure.
 *
 * @see .claude/medley-component-work-1860-phase5-plan.md §5
 * @see .claude/ilyrics-internal-ids-work-model-plan.md §3.6/§3.6b
 * @see appWeb/public_html/includes/work_admin.php  workMedleyReady()/workMedleyConstituents()/workMedleyConstituentsMap()/workMedleyWouldCycle()/workMedleyAttach()/workMedleyReplace()
 * @see tests/php/test-optional-table-probes.php  the per-test-file custom-double precedent this mirrors
 * @see tests/php/test-intapps-stub-e2e.php  the separate-process-for-a-memoised-gate precedent (its D1 section)
 * @see #1860
 */

require_once dirname(__DIR__, 2) . '/appWeb/public_html/includes/work_admin.php';

$failures = 0;
function check(string $label, bool $cond, string $detail = ''): void
{
    global $failures;
    if ($cond) {
        echo "  PASS  {$label}\n";
        return;
    }
    echo "  FAIL  {$label}\n";
    if ($detail !== '') {
        echo "        {$detail}\n";
    }
    $failures++;
}

/* ========================================================================
 * The hand-built double. Every SELECT/INSERT/DELETE the workMedley*()
 * functions can issue is recognised by exact-shape regex (after whitespace
 * normalisation, the GateRecorderMysqli technique); anything else throws.
 * ==================================================================== */

final class WorkMedleyResult
{
    private int $pos = 0;
    /** @param list<array<string,mixed>> $rows */
    public function __construct(private array $rows) {}
    public function fetch_assoc(): ?array
    {
        if (!array_key_exists($this->pos, $this->rows)) {
            return null;
        }
        return $this->rows[$this->pos++];
    }
    public function fetch_row(): ?array
    {
        $row = $this->fetch_assoc();
        return $row === null ? null : array_values($row);
    }
}

final class WorkMedleyStmt
{
    /** @var list<mixed> references to the caller's bound variables */
    private array $refs = [];
    public function __construct(private WorkMedleyMysqli $db, private string $sql) {}

    /* By-ref, values dereferenced at execute() time — mirrors the real
       mysqli_stmt (and GateRecorderStmt's own justification for it). */
    public function bind_param(string $types, mixed &...$vars): bool
    {
        $this->refs = $vars;
        return true;
    }

    public function execute(): bool
    {
        $params = array_map(static fn($v) => $v, $this->refs);
        $this->db->recordExecute($this->sql, $params);
        return true;
    }

    public function get_result(): WorkMedleyResult
    {
        $params = array_map(static fn($v) => $v, $this->refs);
        return new WorkMedleyResult($this->db->rowsFor($this->sql, $params));
    }

    public function close(): bool { return true; }
}

final class WorkMedleyMysqli extends \mysqli
{
    /** Does the INFORMATION_SCHEMA probe report tblWorkComponents as present? */
    public bool $tableExists = true;
    /** @var array<int,true> ids that exist in tblWorks */
    public array $works = [];
    /** @var array<int,list<array{ComponentWorkId:int,SortOrder:int,Note:?string}>> the fixture's tblWorkComponents state, by MedleyWorkId */
    public array $components = [];
    /** @var array<int,array{Title:string,Slug:string}> tblWorks.Title/Slug, for the read-shape JOIN queries */
    public array $titles = [];
    /** @var list<array{sql:string,params:list<mixed>}> every execute(), in order — for "did it even touch the DB" assertions */
    public array $executed = [];

    public function __construct() {}   // never connects — a pure in-memory double

    #[\ReturnTypeWillChange]
    public function prepare(string $query): mixed
    {
        $sql = trim((string)preg_replace('/\s+/', ' ', $query));
        return new WorkMedleyStmt($this, $sql);
    }

    public function recordExecute(string $sql, array $params): void
    {
        $this->executed[] = ['sql' => $sql, 'params' => $params];

        /* The two write statements mutate the fixture's in-memory state so a
           later query in the SAME test sees the effect, exactly like a real
           connection would. */
        if (stripos($sql, 'INSERT INTO tblWorkComponents') === 0) {
            [$medleyId, $componentId, $sortOrder, $note] = $params;
            $medleyId = (int)$medleyId;
            $componentId = (int)$componentId;
            $exists = false;
            foreach ($this->components[$medleyId] ?? [] as $row) {
                if ($row['ComponentWorkId'] === $componentId) { $exists = true; break; }
            }
            if (!$exists) {
                /* ON DUPLICATE KEY UPDATE MedleyWorkId = MedleyWorkId — a true
                   no-op on an existing pair, so this branch only ever fires
                   for a genuinely NEW (MedleyWorkId, ComponentWorkId) pair. */
                $this->components[$medleyId][] = [
                    'ComponentWorkId' => $componentId,
                    'SortOrder'       => (int)$sortOrder,
                    'Note'            => $note,
                ];
            }
            return;
        }
        if (stripos($sql, 'DELETE FROM tblWorkComponents') === 0) {
            $medleyId = (int)$params[0];
            unset($this->components[$medleyId]);
            return;
        }
    }

    /** Answer the SELECTs. Anything unrecognised throws, naming the query. */
    public function rowsFor(string $sql, array $params): array
    {
        if (str_contains($sql, "INFORMATION_SCHEMA.TABLES") && str_contains($sql, "'tblWorkComponents'")) {
            return [['n' => $this->tableExists ? 1 : 0]];
        }
        if (preg_match('/^SELECT 1 FROM tblWorks WHERE Id = \? LIMIT 1$/', $sql)) {
            $id = (int)($params[0] ?? 0);
            return isset($this->works[$id]) ? [['1' => 1]] : [];
        }
        if (preg_match('/^SELECT ComponentWorkId FROM tblWorkComponents WHERE MedleyWorkId = \?$/', $sql)) {
            $medleyId = (int)($params[0] ?? 0);
            $out = [];
            foreach ($this->components[$medleyId] ?? [] as $row) {
                $out[] = ['ComponentWorkId' => $row['ComponentWorkId']];
            }
            return $out;
        }
        if (preg_match('/^SELECT 1 FROM tblWorkComponents WHERE MedleyWorkId = \? AND ComponentWorkId = \? LIMIT 1$/', $sql)) {
            $medleyId = (int)($params[0] ?? 0);
            $componentId = (int)($params[1] ?? 0);
            foreach ($this->components[$medleyId] ?? [] as $row) {
                if ($row['ComponentWorkId'] === $componentId) { return [['1' => 1]]; }
            }
            return [];
        }
        /* workMedleyConstituents() — the single-medley JOIN read shape. */
        if (str_starts_with($sql, 'SELECT wc.ComponentWorkId AS WorkId') && str_contains($sql, 'WHERE wc.MedleyWorkId = ?')) {
            $medleyId = (int)($params[0] ?? 0);
            $rows = $this->components[$medleyId] ?? [];
            usort($rows, fn($a, $b) => $a['SortOrder'] <=> $b['SortOrder'] ?: (($this->titles[$a['ComponentWorkId']]['Title'] ?? '') <=> ($this->titles[$b['ComponentWorkId']]['Title'] ?? '')));
            $out = [];
            foreach ($rows as $row) {
                $out[] = [
                    'WorkId'    => $row['ComponentWorkId'],
                    'Title'     => $this->titles[$row['ComponentWorkId']]['Title'] ?? ('Work ' . $row['ComponentWorkId']),
                    'Slug'      => $this->titles[$row['ComponentWorkId']]['Slug'] ?? ('work-' . $row['ComponentWorkId']),
                    'SortOrder' => $row['SortOrder'],
                    'Note'      => $row['Note'],
                ];
            }
            return $out;
        }
        /* workMedleyConstituentsMap() — the bulk IN() JOIN read shape. */
        if (str_starts_with($sql, 'SELECT wc.MedleyWorkId AS MedleyWorkId') && str_contains($sql, 'WHERE wc.MedleyWorkId IN')) {
            $out = [];
            foreach ($params as $medleyId) {
                $medleyId = (int)$medleyId;
                $rows = $this->components[$medleyId] ?? [];
                usort($rows, fn($a, $b) => $a['SortOrder'] <=> $b['SortOrder'] ?: (($this->titles[$a['ComponentWorkId']]['Title'] ?? '') <=> ($this->titles[$b['ComponentWorkId']]['Title'] ?? '')));
                foreach ($rows as $row) {
                    $out[] = [
                        'MedleyWorkId' => $medleyId,
                        'WorkId'       => $row['ComponentWorkId'],
                        'Title'        => $this->titles[$row['ComponentWorkId']]['Title'] ?? ('Work ' . $row['ComponentWorkId']),
                        'Slug'         => $this->titles[$row['ComponentWorkId']]['Slug'] ?? ('work-' . $row['ComponentWorkId']),
                        'SortOrder'    => $row['SortOrder'],
                        'Note'         => $row['Note'],
                    ];
                }
            }
            return $out;
        }
        throw new \LogicException('WorkMedleyMysqli: unrecognised query: ' . $sql);
    }

    /** Every executed entry whose SQL contains $needle — for "did it touch this table" assertions. */
    public function executedContaining(string $needle): array
    {
        return array_values(array_filter($this->executed, static fn(array $e): bool => str_contains($e['sql'], $needle)));
    }
}

echo "Medley write core (workMedley*()) guard tests\n\n";

/* ------------------------------------------------------------------ *
 * T1 — self-link reject: a work can never medley-of-itself. Runs FIRST
 * (see the file doc-block) so the readiness probe genuinely fires and this
 * process's static cache latches to `true` for every scenario after it.
 * ------------------------------------------------------------------ */
echo "T1 — self-link reject\n";
$db1 = new WorkMedleyMysqli();
$db1->tableExists = true;
$db1->works = [100 => true];

$r1 = workMedleyAttach($db1, 100, 100);
check('T1: workMedleyAttach(id, id) returns false', $r1 === false);
check(
    'T1: no tblWorks query was ever issued (guard 2 short-circuits before guard 3\'s workExists() calls)',
    count($db1->executedContaining('tblWorks')) === 0
);
check(
    'T1: no query against the REAL tblWorkComponents table was ever issued'
        . ' (excludes the readiness probe, whose query merely MENTIONS the table name as a filter value)',
    count($db1->executedContaining('FROM tblWorkComponents')) === 0
);

/* ------------------------------------------------------------------ *
 * T2 — cycle reject at depth 1: componentId is ALREADY a medley that
 * directly contains medleyId. Attaching medleyId -> componentId would
 * close a 2-node loop one hop away.
 * ------------------------------------------------------------------ */
echo "\nT2 — cycle reject at depth 1\n";
$db2 = new WorkMedleyMysqli();
$db2->works = [201 => true, 202 => true];
$db2->components = [
    202 => [['ComponentWorkId' => 201, 'SortOrder' => 0, 'Note' => null]],   // 202 already contains 201
];

$r2 = workMedleyAttach($db2, 201, 202);
check('T2: workMedleyAttach(201, 202) refused — 202 already (directly) contains 201', $r2 === false);
check('T2: no (201,202) row was written', !isset($db2->components[201]));
check(
    'T2: workMedleyWouldCycle() called directly also reports true',
    workMedleyWouldCycle($db2, 201, 202) === true
);

/* ------------------------------------------------------------------ *
 * T3 — cycle reject at depth 3: componentId -> A -> B -> medleyId, a
 * three-hop chain back to the work about to become the medley.
 * ------------------------------------------------------------------ */
echo "\nT3 — cycle reject at depth 3\n";
$db3 = new WorkMedleyMysqli();
$db3->works = [301 => true, 302 => true, 303 => true, 304 => true];
$db3->components = [
    302 => [['ComponentWorkId' => 303, 'SortOrder' => 0, 'Note' => null]],   // 302 contains 303
    303 => [['ComponentWorkId' => 304, 'SortOrder' => 0, 'Note' => null]],   // 303 contains 304
    304 => [['ComponentWorkId' => 301, 'SortOrder' => 0, 'Note' => null]],   // 304 contains 301 (the medley)
];

$r3 = workMedleyAttach($db3, 301, 302);
check('T3: workMedleyAttach(301, 302) refused — 302 reaches 301 via 303 -> 304 (3 hops)', $r3 === false);
check('T3: no (301,302) row was written', !isset($db3->components[301]));

/* A non-cycle at the SAME depth must NOT be refused — proves the BFS isn't
   just refusing every distant attach. 305 sits on the SAME chain but is
   NOT itself an ancestor of 301, so attaching it is legitimate. */
$db3->works[305] = true;
$r3b = workMedleyAttach($db3, 301, 305);
check('T3b: an unrelated work (no path back to 301) is accepted', $r3b === true);

/* T3c — the mutation-sensitive positive case (see the file doc-block's
   MUTATION-TESTED note): 306 itself contains an unrelated constituent (307,
   no path back to 301 at all) — a NON-EMPTY but non-cyclic graph. The
   inverted-comparison mutation (`!==` instead of `===`) returns "cycle!" the
   MOMENT it sees any child that ISN'T the medley — which 307 satisfies
   trivially at depth 1 — so a broken BFS refuses this attach even though
   the graph contains no cycle whatsoever. T2 alone does not catch that
   mutation class: T2's own graph reaches the medley on its VERY FIRST
   child, so an "always true on any non-matching child" bug and a CORRECT
   BFS agree on T2's answer by coincidence. This case is the one that
   disagrees. */
$db3->works[306] = true;
$db3->works[307] = true;
$db3->components[306] = [['ComponentWorkId' => 307, 'SortOrder' => 0, 'Note' => null]];   // 306 contains 307, nothing more
$r3c = workMedleyAttach($db3, 301, 306);
check(
    'T3c: a constituent that itself contains an UNRELATED work (no path back to the medley) is still accepted',
    $r3c === true
);

/* ------------------------------------------------------------------ *
 * T4 — keep-existing on duplicate: re-attaching an already-linked pair
 * with DIFFERENT sortOrder/note must change NOTHING (SD2's load-bearing
 * "never overwrites an existing row" property).
 * ------------------------------------------------------------------ */
echo "\nT4 — keep-existing on duplicate\n";
$db4 = new WorkMedleyMysqli();
$db4->works = [401 => true, 402 => true];

$first = workMedleyAttach($db4, 401, 402, 7, 'first note');
check('T4: first attach succeeds', $first === true);
check(
    'T4: fixture now holds SortOrder=7, Note="first note"',
    ($db4->components[401][0]['SortOrder'] ?? null) === 7
        && ($db4->components[401][0]['Note'] ?? null) === 'first note'
);

$second = workMedleyAttach($db4, 401, 402, 999, 'second note — must be ignored');
check('T4: re-attach still reports the row exists', $second === true);
check(
    'T4: SortOrder is STILL 7 — the second call\'s 999 was never applied',
    ($db4->components[401][0]['SortOrder'] ?? null) === 7
);
check(
    'T4: Note is STILL "first note" — the second call\'s note was never applied',
    ($db4->components[401][0]['Note'] ?? null) === 'first note'
);
check('T4: exactly one (401,402) row exists, not two', count($db4->components[401]) === 1);

/* ------------------------------------------------------------------ *
 * T4b — workMedleyReplace(): DELETE + re-Attach each row, same guards,
 * invalid rows skipped rather than thrown. Light coverage of the fifth
 * function (built entirely out of workMedleyAttach(), rule #22).
 * ------------------------------------------------------------------ */
echo "\nT4b — workMedleyReplace() reconcile\n";
$db4b = new WorkMedleyMysqli();
$db4b->works = [501 => true, 502 => true, 503 => true];
/* Pre-existing row that the replace call must remove. */
$db4b->components[501] = [['ComponentWorkId' => 999, 'SortOrder' => 0, 'Note' => null]];

$inserted = workMedleyReplace($db4b, 501, [
    ['workId' => 502, 'sortOrder' => 1, 'note' => 'a'],
    ['workId' => 503, 'sortOrder' => 2, 'note' => null],
    ['workId' => 0],                 // invalid — skipped, never thrown
    ['workId' => 501],               // self-link — skipped by workMedleyAttach's own guard
    'not-an-array',                  // malformed row — skipped, never thrown
]);
check('T4b: 2 valid rows inserted (invalid/self-link/malformed rows silently skipped)', $inserted === 2);
check('T4b: the stale pre-existing row (999) is gone', !in_array(999, array_column($db4b->components[501] ?? [], 'ComponentWorkId'), true));
check('T4b: 502 and 503 are both present', array_column($db4b->components[501] ?? [], 'ComponentWorkId') === [502, 503]);

/* Full read-shape round-trip over the state workMedleyReplace() just built:
   the JOIN queries, titled and ordered. */
$db4b->titles = [502 => ['Title' => 'Second', 'Slug' => 'second'], 503 => ['Title' => 'Third', 'Slug' => 'third']];
$constituents = workMedleyConstituents($db4b, 501);
check(
    'T4b: workMedleyConstituents(501) returns both rows in SortOrder',
    $constituents === [
        ['workId' => 502, 'title' => 'Second', 'slug' => 'second', 'sortOrder' => 1, 'note' => 'a'],
        ['workId' => 503, 'title' => 'Third',  'slug' => 'third',  'sortOrder' => 2, 'note' => null],
    ]
);
$map = workMedleyConstituentsMap($db4b, [501, 999999]);
check('T4b: workMedleyConstituentsMap([501, 999999]) keys ONLY the medley that actually has rows', array_keys($map) === [501]);
check('T4b: …and its value matches workMedleyConstituents(501) exactly', ($map[501] ?? null) === $constituents);

/* ------------------------------------------------------------------ *
 * T4c — workMedleyConstituents() / workMedleyConstituentsMap() read shape,
 * ordering (SortOrder then Title needs a title, so this fixture skips the
 * JOIN path and instead confirms the [] shape for an empty/absent medley —
 * the JOIN itself is exercised end-to-end by T4b's own state via the
 * single-row map lookup below).
 * ------------------------------------------------------------------ */
echo "\nT4c — read-shape smoke\n";
check('T4c: workMedleyConstituents() on a medley with no rows returns []', workMedleyConstituents($db1, 999999) === []);
check('T4c: workMedleyConstituentsMap() with an empty id list returns []', workMedleyConstituentsMap($db1, []) === []);
check(
    'T4c: workMedleyConstituentsMap() with only non-positive ids returns [] (never touches the DB)',
    workMedleyConstituentsMap($db1, [0, -5]) === []
);

/* ========================================================================
 * T5 — ready-gate short-circuit, in an ISOLATED php process (see doc-block).
 * ==================================================================== */
echo "\nT5 — ready-gate short-circuit (separate process)\n";

$repoRoot = dirname(__DIR__, 2);
$scratchDir = sys_get_temp_dir() . '/ihymns-work-medley-core-test';
if (!is_dir($scratchDir)) {
    mkdir($scratchDir, 0777, true);
}
$probeScript = $scratchDir . '/t5-probe.php';
$probeBody = <<<'PHP'
<?php
declare(strict_types=1);
require_once REPO_ROOT_ABS . '/appWeb/public_html/includes/work_admin.php';
require_once DOUBLES_PATH_ABS;

$db = new WorkMedleyMysqli();
$db->tableExists = false;   // the un-migrated install: tblWorkComponents does not exist yet
$db->works = [1 => true, 2 => true];

$out = [];
$out['ready']       = workMedleyReady($db);
$out['attach']      = workMedleyAttach($db, 1, 2);
$out['wouldCycle']  = workMedleyWouldCycle($db, 1, 2);
$out['constituents'] = workMedleyConstituents($db, 1);
$out['constituentsMap'] = workMedleyConstituentsMap($db, [1, 2]);
$out['replace']     = workMedleyReplace($db, 1, [['workId' => 2]]);
/* Not-ready must mean FULLY dormant — no query beyond the readiness probe
   itself (which is the ONE query every function above is allowed: each
   calls workMedleyReady() itself, and the memo means only the very FIRST
   of those six calls actually reaches prepare() — the other five are
   answered from the cache with zero queries). */
$out['queryCount'] = count($db->executed);
echo json_encode($out);
PHP;
/* The double classes live in THIS file (test-work-medley-core.php) and are
   not autoloadable on their own, so the probe script needs its own copy of
   just the double — extracted verbatim into a sibling ".doubles" file
   rather than re-declaring the classes inline here (which would risk the
   two copies drifting). Written once, alongside the probe, each run. */
$doublesSrc = file_get_contents(__FILE__);
/* Slice out exactly the double-class block: from the WorkMedleyResult class
   doc-comment through the end of WorkMedleyMysqli's closing brace, i.e.
   between the two fixed markers below (kept literally in this file so the
   slice cannot silently go stale without a visible diff). */
$startMarker = "final class WorkMedleyResult";
$endMarker   = "echo \"Medley write core (workMedley*()) guard tests\\n\\n\";";
$startPos = strpos($doublesSrc, $startMarker);
$endPos   = strpos($doublesSrc, $endMarker);
if ($startPos === false || $endPos === false) {
    fwrite(STDERR, "T5 setup failed: could not locate the double-class markers in this file.\n");
    exit(1);
}
$doublesBody = "<?php\ndeclare(strict_types=1);\n" . substr($doublesSrc, $startPos, $endPos - $startPos);
file_put_contents($scratchDir . '/test-work-medley-core.php.doubles', $doublesBody);

/* Two DISTINCT placeholder tokens (never one token that is a substring of
   the other) so each str_replace() only ever touches the line it targets —
   a single shared REPO_ROOT_ABS token would have its FIRST replacement
   already consume the text the SECOND replacement is looking for. */
$probeBody = str_replace('REPO_ROOT_ABS', var_export($repoRoot, true), $probeBody);
$probeBody = str_replace('DOUBLES_PATH_ABS', var_export($scratchDir . '/test-work-medley-core.php.doubles', true), $probeBody);
file_put_contents($probeScript, $probeBody);

$probeOutput = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probeScript) . ' 2>&1');
$probeResult = json_decode((string)$probeOutput, true);

check('T5: the isolated probe process produced valid JSON (no fatal/uncaught output)', is_array($probeResult), (string)$probeOutput);
if (is_array($probeResult)) {
    check('T5: workMedleyReady() reports false on an un-migrated install', $probeResult['ready'] === false);
    check('T5: workMedleyAttach() short-circuits to false', $probeResult['attach'] === false);
    check('T5: workMedleyWouldCycle() short-circuits to false (never guesses "cycle")', $probeResult['wouldCycle'] === false);
    check('T5: workMedleyConstituents() short-circuits to []', $probeResult['constituents'] === []);
    check('T5: workMedleyConstituentsMap() short-circuits to []', $probeResult['constituentsMap'] === []);
    check('T5: workMedleyReplace() short-circuits to 0 inserted', $probeResult['replace'] === 0);
    check(
        'T5: exactly ONE query touched the double for all six calls combined (the memoised readiness probe, fired once)',
        $probeResult['queryCount'] === 1,
        'got ' . var_export($probeResult['queryCount'] ?? null, true) . ' — a not-ready install must never reach a real tblWorks/tblWorkComponents statement'
    );
}

@unlink($probeScript);
@unlink($scratchDir . '/test-work-medley-core.php.doubles');
@rmdir($scratchDir);

/* ---------------------------------------------------------------------- */
if ($failures === 0) {
    echo "\nAll medley write-core assertions passed.\n";
    exit(0);
}
fwrite(STDERR, "\n{$failures} assertion(s) failed.\n");
exit(1);
