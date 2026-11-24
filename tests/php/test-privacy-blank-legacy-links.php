<?php

declare(strict_types=1);

/**
 * iHymns - blanking old personal links is safe, bounded and manual (B2, epic #2180)
 *
 * ELI5
 * ----
 * Step B2 permanently blanks old personal data, so this checks the safety
 * properties rather than trusting them: it only touches the two named tables,
 * only erased accounts' audit rows, in batches of at most 5,000, with every
 * value bound; it counts down to nothing and then stops; an install missing a
 * table or column is skipped quietly; and the migration card is MANUAL
 * (never in "Apply all"), dry-run by default, and needs confirm to change a row.
 *
 * Detailed (rule #34): the shared functions in includes/privacy_legacy_blank.php
 * are CALLED against a small pretend database (a mysqli stand-in that obeys the
 * LIMIT in each UPDATE and throws on a missing table, like a real STRICT
 * connection). The registry and script checks read their source with comments
 * removed. Mutation proofs are recorded in the B2 commit: dropping the
 * "erased accounts only" condition, or removing the manual flag, each turns
 * this red.
 *
 *   php tests/php/test-privacy-blank-legacy-links.php
 */

$root = dirname(__DIR__, 2) . '/appWeb';
$_SERVER['SCRIPT_FILENAME'] = 'test';
require_once $root . '/public_html/includes/privacy_legacy_blank.php';

$fail = 0;
function chk(string $label, bool $cond, string $detail = ''): void
{
    global $fail;
    echo ($cond ? '  PASS  ' : '  FAIL  ') . $label . ($cond || $detail === '' ? '' : " - $detail") . "\n";
    if (!$cond) { $fail++; }
}
function code(string $src): string
{
    $o = '';
    foreach (token_get_all($src) as $t) {
        if (is_array($t)) { $o .= in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? str_repeat("\n", substr_count($t[1], "\n")) : $t[1]; } else { $o .= $t; }
    }
    return $o;
}

/* ---------------- pure SQL builder ---------------- */
$all = array_merge(array_keys(PRIVACY_LEGACY_AUDIT_BLANKS), ['Details']);
$sql = privacyLegacyAuditSql($all);
chk('blanks the address, browser, referrer, country and proxy columns', (bool)preg_match("/IpAddress = ''.*UserAgent = ''.*Referrer = NULL.*Country = NULL.*IpProxyChain = NULL.*ProxyVpnDetail = NULL/", $sql['set']));
chk('removes username and email from Details', str_contains($sql['set'], "JSON_REMOVE(Details, '\$.username', '\$.email')"));
chk('only erased accounts: the row must belong to a tombstoned user', str_contains($sql['where'], "UserId IN (SELECT Id FROM tblUsers WHERE Status = 'deleted')"));
chk('only rows that still hold something match (so a re-run finds nothing)', str_contains($sql['where'], "IpAddress <> ''") && str_contains($sql['where'], 'Referrer IS NOT NULL') && str_contains($sql['where'], 'JSON_CONTAINS_PATH'));
$without = privacyLegacyAuditSql(array_values(array_diff($all, ['Referrer', 'Country'])));
chk('an install without Referrer/Country leaves them out entirely', !str_contains($without['set'], 'Referrer') && !str_contains($without['where'], 'Country'));
chk('with no known columns nothing can match (AND 0), never "everything"', str_ends_with(privacyLegacyAuditSql([])['where'], 'AND 0'));
chk('the batch size is 5,000', PRIVACY_LEGACY_BATCH === 5000);

/* ---------------- a pretend database ---------------- */
final class FakeRes
{
    public function __construct(private array $rows) {}
    public function fetch_row() { return $this->rows[0] ?? null; }
    public function fetch_all($mode = 0) { return $this->rows; }
    public function close() {}
}
final class FakeStmt
{
    public int $affected_rows = 0;
    private array $bound = [];
    public function __construct(private FakeDb $db, private string $sql) {}
    public function bind_param(string $types, &...$vars) { $this->bound = $vars; return true; }
    public function execute() { $this->db->exec($this->sql, $this->bound, $this); return true; }
    public function get_result() { return $this->db->answer($this->sql, $this->bound); }
    public function close() {}
}
class FakeDb extends \mysqli
{
    public array $log = [];
    public bool $throwOnQuery = false;
    public array $userAudit = [];   /* user id => audit rows still holding personal details */
    public function __construct(public array $tables, public array $cols, public int $search, public int $audit) {}
    #[\ReturnTypeWillChange]
    public function prepare(string $q): mixed { $this->log[] = ['prepare', $q]; return new FakeStmt($this, $q); }
    #[\ReturnTypeWillChange]
    public function query(string $q, int $m = 0): mixed {
        $this->log[] = ['query', $q];
        if (str_starts_with($q, 'SELECT COUNT(*) FROM tblSearchQueries')) { return new FakeRes([[$this->search]]); }
        if (str_starts_with($q, 'SELECT COUNT(*) FROM tblActivityLog')) { return new FakeRes([[$this->audit]]); }
        if (str_starts_with($q, 'SELECT 1 FROM tblSearchQueries') && str_ends_with($q, 'LIMIT 1')) { return new FakeRes($this->search > 0 ? [[1]] : []); }
        if (str_starts_with($q, 'SELECT 1 FROM tblActivityLog') && str_ends_with($q, 'LIMIT 1')) { return new FakeRes($this->audit > 0 ? [[1]] : []); }
        if ($this->throwOnQuery) { throw new \mysqli_sql_exception('simulated failure'); }
        throw new \LogicException("unrecognised query: $q");
    }
    public function answer(string $sql, array $b): FakeRes {
        if (str_contains($sql, 'INFORMATION_SCHEMA.TABLES')) { return new FakeRes(in_array($b[0], $this->tables, true) ? [[1]] : []); }
        if (str_contains($sql, 'INFORMATION_SCHEMA.COLUMNS')) { return new FakeRes(array_map(fn($c) => [$c], $this->cols[$b[0]] ?? [])); }
        throw new \LogicException("unrecognised select: $sql");
    }
    public function exec(string $sql, array $b, FakeStmt $st): void {
        if (str_starts_with(ltrim($sql), 'SELECT')) { return; }   /* a read: answered by get_result() */
        if (str_starts_with($sql, 'UPDATE tblActivityLog') && str_contains($sql, 'WHERE UserId = ?')) {
            [$uid, $lim] = [(int)$b[0], (int)$b[1]];
            $n = min($lim, $this->userAudit[$uid] ?? 0);
            $this->userAudit[$uid] = ($this->userAudit[$uid] ?? 0) - $n;
            $st->affected_rows = $n;
            return;
        }
        $limit = (int)$b[0];
        if (str_starts_with($sql, 'UPDATE tblSearchQueries')) { $n = min($limit, $this->search); $this->search -= $n; $st->affected_rows = $n; return; }
        if (str_starts_with($sql, 'UPDATE tblActivityLog'))   { $n = min($limit, $this->audit);  $this->audit  -= $n; $st->affected_rows = $n; return; }
        throw new \LogicException("unexpected write: $sql");
    }
}
$auditCols = ['Id', 'UserId', 'IpAddress', 'UserAgent', 'Referrer', 'Country', 'IpProxyChain', 'ProxyVpnDetail', 'Details'];
$db = new FakeDb(['tblSearchQueries', 'tblActivityLog', 'tblUsers'],
    ['tblSearchQueries' => ['Id', 'Query', 'UserId'], 'tblActivityLog' => $auditCols, 'tblUsers' => ['Id', 'Status']], 12000, 7000);

chk('the probe says pending while rows remain', privacyLegacyPending($db) === true);
chk('counts the searches and the audit rows', privacyLegacySearchPendingCount($db) === 12000 && privacyLegacyAuditPendingCount($db) === 7000);
$batches = [];
while (($n = privacyLegacyBlankSearchBatch($db)) > 0) { $batches[] = $n; }
chk('searches are unlinked in batches of at most 5,000 (5000, 5000, 2000)', $batches === [5000, 5000, 2000], json_encode($batches));
$batches = [];
while (($n = privacyLegacyBlankAuditBatch($db)) > 0) { $batches[] = $n; }
chk('audit rows are blanked in batches of at most 5,000 (5000, 2000)', $batches === [5000, 2000], json_encode($batches));
chk('then nothing is pending and a re-run changes nothing', privacyLegacyPending($db) === false && privacyLegacyBlankSearchBatch($db) === 0 && privacyLegacyBlankAuditBatch($db) === 0);

$writes = array_filter($db->log, fn($e) => $e[0] === 'prepare' && preg_match('/^\s*(UPDATE|DELETE|INSERT)/i', $e[1]));
chk('there were writes to inspect', count($writes) > 0);
chk('every write is an UPDATE of tblSearchQueries or tblActivityLog, never a DELETE or INSERT',
    count(array_filter($writes, fn($e) => !preg_match('/^UPDATE (tblSearchQueries|tblActivityLog) SET /', $e[1]))) === 0);
chk('every write binds its batch limit as a value (LIMIT ?)', count(array_filter($writes, fn($e) => !str_ends_with($e[1], 'LIMIT ?'))) === 0);

/* ---------------- erasing ONE account blanks that person's audit rows (same list as the migration) ---------------- */
$one = new FakeDb(['tblSearchQueries', 'tblActivityLog', 'tblUsers'],
    ['tblSearchQueries' => ['Id', 'Query', 'UserId'], 'tblActivityLog' => $auditCols, 'tblUsers' => ['Id', 'Status']], 0, 0);
$one->userAudit = [41 => 11000, 42 => 3];
chk('one account: blanks every one of that person\'s rows (loops in batches)', privacyLegacyBlankUserAuditRows($one, 41) === 11000 && $one->userAudit[41] === 0);
chk('one account: another person\'s rows are untouched', $one->userAudit[42] === 3);
chk('one account: a re-run changes nothing', privacyLegacyBlankUserAuditRows($one, 41) === 0);
$userWrites = array_values(array_filter($one->log, fn($e) => $e[0] === 'prepare' && str_starts_with($e[1], 'UPDATE tblActivityLog')));
chk('one account: the statement is scoped by a BOUND UserId and batched', count($userWrites) >= 1 && count(array_filter($userWrites, fn($e) => !str_contains($e[1], 'WHERE UserId = ? AND (') || !str_ends_with($e[1], 'LIMIT ?'))) === 0);
chk('one account: it uses the SAME set list as the migration', str_contains($userWrites[0][1], privacyLegacyAuditSql($all)['set']));
chk('one account: a missing table or user id is skipped, never thrown', privacyLegacyBlankUserAuditRows(new FakeDb(['tblUsers'], ['tblUsers' => ['Id', 'Status']], 0, 0), 41) === 0 && privacyLegacyBlankUserAuditRows($one, 0) === 0);
/* the erasure itself must call it, and must not widen to unattributed rows */
$life = code((string)file_get_contents($root . '/public_html/includes/account_lifecycle.php'));
$eraseAt = strpos($life, 'function accountErase(');
$eraseBody = $eraseAt === false ? '' : substr($life, $eraseAt);
chk('accountErase() blanks the person\'s audit rows through the shared function', str_contains($eraseBody, 'privacyLegacyBlankUserAuditRows($db, $userId)'));
chk('...before it writes the tombstone, in the same transaction', strpos($eraseBody, 'privacyLegacyBlankUserAuditRows(') < strpos($eraseBody, "Status                 = 'deleted'"));
chk('...and account_lifecycle.php does not carry its own audit SET list', !str_contains($life, 'JSON_REMOVE') && !str_contains($life, 'UPDATE tblActivityLog'));

/* The probe asks "is there any?" (LIMIT 1), never COUNT(*), and a failure is reported, not hidden. */
$probeDb = new FakeDb(['tblSearchQueries', 'tblActivityLog', 'tblUsers'],
    ['tblSearchQueries' => ['Id', 'Query', 'UserId'], 'tblActivityLog' => $auditCols, 'tblUsers' => ['Id', 'Status']], 5, 5);
$probeErr = 'x';
chk('probe: pending is true with rows waiting, and no error', privacyLegacyPending($probeDb, $probeErr) === true && $probeErr === null);
$probeQueries = array_values(array_filter($probeDb->log, fn($e) => $e[0] === 'query'));
chk('probe: it used LIMIT 1 existence checks, never COUNT(*)', count($probeQueries) >= 1 && count(array_filter($probeQueries, fn($e) => str_contains($e[1], 'COUNT(*)') || !str_ends_with($e[1], 'LIMIT 1'))) === 0);
$prevLog = ini_set('error_log', sys_get_temp_dir() . '/ihymns-probe-test.log');
@unlink(sys_get_temp_dir() . '/ihymns-probe-test.log');
$errOut = null;
$brokenProbe = new class(['tblSearchQueries', 'tblActivityLog', 'tblUsers'], ['tblSearchQueries' => ['Id', 'Query', 'UserId'], 'tblActivityLog' => $auditCols, 'tblUsers' => ['Id', 'Status']]) extends FakeDb {
    public function __construct(array $t, array $c) { parent::__construct($t, $c, 1, 1); }
    public function query(string $q, int $m = 0): mixed { throw new \mysqli_sql_exception('table is broken'); }
};
chk('probe: a failing check reads as not pending (dashboard still loads)', privacyLegacyPending($brokenProbe, $errOut) === false);
chk('probe: ...but the failure is handed back, not swallowed', is_string($errOut) && str_contains($errOut, 'table is broken'));
chk('probe: ...and written to the error log', is_file(sys_get_temp_dir() . '/ihymns-probe-test.log') && str_contains((string)file_get_contents(sys_get_temp_dir() . '/ihymns-probe-test.log'), 'table is broken'));
@unlink(sys_get_temp_dir() . '/ihymns-probe-test.log');
ini_set('error_log', $prevLog === false ? '' : $prevLog);

/* An install that lacks the tables: skipped quietly, no throw. */
$bare = new FakeDb(['tblUsers'], ['tblUsers' => ['Id', 'Status']], 0, 0);
chk('an install without the tables is not pending and does not throw', privacyLegacyPending($bare) === false);

/* ---------------- manual card, dry-run by default ---------------- */
$reg = code((string)file_get_contents($root . '/public_html/manage/includes/migration-registry.php'));
$at = strpos($reg, "'privacy-blank-legacy-links' => [");
chk('the registry has the entry', $at !== false);
$entry = $at === false ? '' : substr($reg, $at, 6000);
$head = substr($entry, 0, (int)strpos($entry, "'card'"));
chk("the entry is 'manual' => true (never in Apply all)", (bool)preg_match("/'manual'\\s*=>\\s*true/", $head));
chk("the entry is 'dryRunnable' => true", (bool)preg_match("/'dryRunnable'\\s*=>\\s*true/", $head));
chk('the probe uses the shared privacyLegacyPending() (not `=> true`)', str_contains($entry, 'privacyLegacyPending($db)'));
$script = code((string)file_get_contents($root . '/.sql/migrate-privacy-blank-legacy-links.php'));
chk('the script is a dry run unless confirm is given', str_contains($script, '$dryRun = !$confirm;'));
chk("web confirm is the exact text '1' and CLI confirm is --confirm", str_contains($script, "(\$_GET['confirm'] ?? '') === '1'") && str_contains($script, "'--confirm'"));
chk('every batch loop is behind the dry-run check', substr_count($script, '!$dryRun &&') === 2 && substr_count($script, 'privacyLegacyBlank') === 2);
chk('rule #41: the shared include resolves through IHYMNS_INCLUDES_DIR', str_contains($script, "defined('IHYMNS_INCLUDES_DIR') ? IHYMNS_INCLUDES_DIR"));
chk('rule #41: no column-0 require of a literal /public_html/ path', !preg_match("#^(require|include)(_once)?[^\\n;]*public_html#m", $script));

exit($fail === 0 ? 0 : 1);
