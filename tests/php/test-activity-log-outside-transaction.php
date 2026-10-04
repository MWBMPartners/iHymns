<?php

declare(strict_types=1);

/**
 * iHymns — a log row or a cache row that fails OUTSIDE a transaction never
 * fails the request; inside one it still stops the save (#2137 review round 8)
 * ==========================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * Round 7 made every catch a save can reach pass back the database errors
 * that end a whole transaction (a deadlock, a lock wait timeout, MariaDB's
 * "record has changed since last read"). That is right INSIDE a save: the
 * save must stop. But the same helpers also run where no transaction is open
 * — most often the activity-log row a page writes AFTER its work has been
 * committed. There, failing the request is wrong: the work is already saved,
 * the curator is told it failed, and a retry makes a duplicate. And one write
 * should never happen inside a save at all: the shared geo-cache row, which
 * another request may be writing at the same moment.
 *
 * WHAT IS CHECKED (on MariaDB and on MySQL; neither is skipped)
 * -------------------------------------------------------------
 *   Part A — dbTransactionIsOpen() (includes/transaction_fatal.php) answers
 *            correctly on this server: open from begin_transaction() on,
 *            closed after commit() or rollback(), open with autocommit off
 *            once a statement has run, the same with error reporting off,
 *            and asking leaves an open transaction exactly as it was.
 *   Part B — logActivity() meeting a REAL lock wait timeout (1205) on its
 *            row (another session holds the lock — the seventh review's
 *            "purge lock"), and a stand-in deadlock (1213): outside a
 *            transaction it logs the failure and carries on, including
 *            after work that has just been committed; inside a transaction
 *            it passes the error back, as before. And the ISWC works
 *            backfill (appWeb/.sql/backfill-works-from-iswc.php) finishes
 *            its batch when its own log row meets a deadlock.
 *   Part C — the geo cache (includes/ip_geolocation.php): inside a
 *            transaction a lookup only READS the cache; the row is written
 *            on a later lookup outside any transaction. On MariaDB, the
 *            seventh review's race — another request writes the same IP's
 *            row while a transaction is open — used to end that whole
 *            transaction with a 1020.
 *
 * HOW THE FAILURES ARE MADE
 * -------------------------
 * The lock wait timeout is real: a second connection opens a transaction and
 * locks every row of tblActivityLog and the gap after the last one
 * (`SELECT … FOR UPDATE`), so a new row cannot be added until it lets go, and
 * this file's connection waits one second (`innodb_lock_wait_timeout = 1`)
 * before the server gives up with 1205. Neither server deadlocks an insert on
 * demand, so a deadlock is a stand-in thrown just before the row is written.
 * The geo cache's local country database (MaxMind) is not installed in a test
 * run, so a stand-in reader answers "GB" for any address, through the same
 * seam the real one uses.
 *
 * WHAT THIS CANNOT DO
 * -------------------
 * It calls logActivity() and the backfill directly, not through a web page.
 * The pages' own post-commit log calls (about a hundred, in twelve files —
 * listed in DEV_NOTES) all go through logActivity(), which is what is tested.
 *
 * Database: IHYMNS_TEST_DSN="host=127.0.0.1;port=3306;user=root;pass=".
 * Without one, this file reports a SKIP — a gap, not a pass. It builds its own
 * database from schema.sql (named after this process) and drops it at the end.
 *
 *   php tests/php/test-activity-log-outside-transaction.php
 *
 * @see appWeb/public_html/includes/transaction_fatal.php  dbTransactionIsOpen()
 * @see appWeb/public_html/includes/activity_log.php       logActivity()
 * @see appWeb/public_html/includes/ip_geolocation.php     ihymnsGeoCachePut()
 */

$repoRoot = dirname(__DIR__, 2);
$passed = 0;
$failed = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$passed, &$failed): void {
    if ($ok) { $passed++; echo "  PASS  {$label}\n"; }
    else     { $failed++; echo "  FAIL  {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
};
echo "tests/php/test-activity-log-outside-transaction.php — a failed log or cache row outside a transaction never fails the request\n";

$dsn = getenv('IHYMNS_TEST_DSN') ?: '';
$host = '127.0.0.1'; $port = 3306; $user = 'root'; $pass = '';
foreach (explode(';', $dsn) as $kv) {
    [$k, $v] = array_pad(explode('=', $kv, 2), 2, '');
    if ($k === 'host') { $host = $v; }
    if ($k === 'port') { $port = (int)$v; }
    if ($k === 'user') { $user = $v; }
    if ($k === 'pass') { $pass = $v; }
}
$admin = null;
if ($dsn !== '') {
    try {
        mysqli_report(MYSQLI_REPORT_OFF);
        $admin = @new mysqli($host, $user, $pass, '', $port);
        if ($admin->connect_errno) { $admin = null; }
    } catch (\Throwable $e) {
        $admin = null;
    }
}
if ($admin === null) {
    echo "  SKIP  no database — nothing here ran. Set IHYMNS_TEST_DSN; this is a gap, not a pass.\n";
    echo "\n  {$passed} passed, {$failed} failed\n";
    exit($failed > 0 ? 1 : 0);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$isMaria = str_contains((string)$admin->server_info, 'MariaDB');
$name = 'ihymns_t2137_alog_' . getmypid();
$admin->query("DROP DATABASE IF EXISTS `{$name}`");
$admin->query("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$admin->select_db($name);

/* The error log goes to a file of its own, so the expected complaints do not
   clutter the output and the checks can read what was logged. Deleted at the
   very end too (a step registered here runs after everything else). */
$logFile = (string)tempnam(sys_get_temp_dir(), 'ihymns-t2137-alog-');
ini_set('error_log', $logFile);
register_shutdown_function(static function () use ($logFile): void { @unlink($logFile); });
$logged = static function () use ($logFile): string { clearstatcache(); return (string)@file_get_contents($logFile); };

/* The stand-in for the local country database (MaxMind): the geo code uses it
   when its file exists and the reader class is loaded. Both must be in place
   before includes/ip_geolocation.php is loaded, which fixes the file's path. */
$mmdb = (string)tempnam(sys_get_temp_dir(), 'ihymns-t2137-mmdb-');
register_shutdown_function(static function () use ($mmdb): void { @unlink($mmdb); });
define('IHYMNS_GEO_MMDB_PATH', $mmdb);
eval('namespace MaxMind\\Db; final class Reader {
    public static int $reads = 0;
    public function __construct(string $path) {}
    public function get(string $ip): array { self::$reads++; return ["country" => ["iso_code" => "GB", "names" => ["en" => "United Kingdom"]]]; }
}');

try {
    /* ------------------------------------------------------------------ the database
       Built from schema.sql, one statement at a time, as
       test-song-save-whole-rollback.php does it. */
    $schemaErrors = [];
    foreach (preg_split('/;\s*\n/', (string)file_get_contents($repoRoot . '/appWeb/.sql/schema.sql')) as $chunk) {
        $keep = [];
        foreach (explode("\n", $chunk) as $l) {
            if ($keep === [] && (trim($l) === '' || str_starts_with(trim($l), '--'))) { continue; }
            $keep[] = $l;
        }
        $stmt = trim(implode("\n", $keep));
        if ($stmt === '') { continue; }
        try {
            $admin->query($stmt);
        } catch (\Throwable $e) {
            $schemaErrors[] = $e->getMessage();
        }
    }
    $check('the test database was built from schema.sql without an error', $schemaErrors === [], implode(' | ', array_slice($schemaErrors, 0, 3)));

    /* A connection that can run a little code just before it prepares or runs
       a statement matching a pattern (once per pattern) — how a stand-in
       error is placed at an exact step. getDbMysqli() hands it out. */
    final class AlogTestConnection extends \mysqli
    {
        /** @var list<array{0: string, 1: \Closure}> */
        public array $hooks = [];

        public function prepare(string $query): \mysqli_stmt|false
        {
            $this->fire($query);
            return parent::prepare($query);
        }

        public function query(string $query, int $result_mode = MYSQLI_STORE_RESULT): \mysqli_result|bool
        {
            $this->fire($query);
            return parent::query($query, $result_mode);
        }

        private function fire(string $sql): void
        {
            foreach ($this->hooks as $i => [$pattern, $do]) {
                if (preg_match($pattern, $sql) === 1) {
                    unset($this->hooks[$i]);
                    $do($sql);
                }
            }
        }
    }

    define('DB_HOST', $host);
    define('DB_USER', $user);
    define('DB_PASS', $pass);
    define('DB_NAME', $name);
    define('DB_PORT', $port);
    require_once $repoRoot . '/appWeb/public_html/includes/db_mysql.php';
    require_once $repoRoot . '/appWeb/public_html/includes/activity_log.php';
    require_once $repoRoot . '/appWeb/public_html/includes/ip_geolocation.php';

    $conn = new AlogTestConnection($host, $user, $pass, $name, $port);
    $conn->set_charset('utf8mb4');
    $GLOBALS['_mysqliConnection'] = $conn;
    /* Another request, on a connection of its own. */
    $other = new mysqli($host, $user, $pass, $name, $port);
    $other->set_charset('utf8mb4');
    /* An onlooker who sees only what has been committed. */
    $look = new mysqli($host, $user, $pass, $name, $port);
    $look->set_charset('utf8mb4');
    $rowsFor = static function (string $action) use ($look): int {
        $st = $look->prepare('SELECT COUNT(*) FROM tblActivityLog WHERE Action = ?');
        $st->bind_param('s', $action);
        $st->execute();
        $n = (int)$st->get_result()->fetch_row()[0];
        $st->close();
        return $n;
    };
    /** Run $fn; answer [what it threw or null, seconds it took]. */
    $run = static function (callable $fn): array {
        $t0 = microtime(true);
        try {
            $fn();
            return [null, microtime(true) - $t0];
        } catch (\Throwable $e) {
            return [$e, microtime(true) - $t0];
        }
    };
    $describe = static fn(?\Throwable $e): string => $e === null ? 'nothing thrown' : get_class($e) . ' ' . $e->getCode() . ': ' . $e->getMessage();

    /* ------------------------------------------------------------------ Part A */
    echo "\nPart A — dbTransactionIsOpen() on this server (" . $conn->server_info . ")\n";
    $conn->query('CREATE TABLE r8_probe (Id INT PRIMARY KEY, V INT) ENGINE=InnoDB');
    $conn->query('INSERT INTO r8_probe VALUES (1, 1)');
    $check('A1: nothing open (autocommit) → false', dbTransactionIsOpen($conn) === false);
    $conn->begin_transaction();
    $check('A2: straight after begin_transaction(), before any statement → true', dbTransactionIsOpen($conn) === true);
    $conn->query('UPDATE r8_probe SET V = 100 WHERE Id = 1');
    $check('A3: after a write inside it → true', dbTransactionIsOpen($conn) === true);
    $conn->query('UPDATE r8_probe SET V = V + 1 WHERE Id = 1');
    $conn->commit();
    $check('A4: after commit() → false, and asking twice inside did not disturb it (both writes committed: 101)',
        dbTransactionIsOpen($conn) === false && (int)$look->query('SELECT V FROM r8_probe WHERE Id = 1')->fetch_row()[0] === 101);
    $conn->begin_transaction();
    $conn->query('UPDATE r8_probe SET V = 5 WHERE Id = 1');
    $conn->rollback();
    $check('A5: after rollback() → false', dbTransactionIsOpen($conn) === false);
    $conn->autocommit(false);
    $before = dbTransactionIsOpen($conn);
    $conn->query('SELECT V FROM r8_probe')->fetch_all();
    $after = dbTransactionIsOpen($conn);
    $conn->commit();
    $conn->autocommit(true);
    $check('A6: autocommit switched off — false until a statement has run, then true', $before === false && $after === true,
        json_encode([$before, $after]));
    mysqli_report(MYSQLI_REPORT_OFF);
    $quiet = new mysqli($host, $user, $pass, $name, $port);
    $q1 = dbTransactionIsOpen($quiet);
    $quiet->begin_transaction();
    $q2 = dbTransactionIsOpen($quiet);
    $quiet->rollback();
    $q3 = dbTransactionIsOpen($quiet);
    $quiet->close();
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $check('A7: a connection with error reporting switched off gets the same answers (false, true, false)',
        $q1 === false && $q2 === true && $q3 === false, json_encode([$q1, $q2, $q3]));

    /* ------------------------------------------------------------------ Part B */
    echo "\nPart B — logActivity() outside a transaction logs a failed row and carries on; inside one it passes the error back\n";
    $conn->query('SET SESSION innodb_lock_wait_timeout = 1');
    /** Another request holds every row of the log and the gap after the last one (the "purge lock"). */
    $holdLog = static function () use ($other): void {
        $other->begin_transaction();
        $other->query('SELECT Id FROM tblActivityLog FOR UPDATE')->fetch_all();
    };
    $releaseLog = static function () use ($other): void { try { $other->rollback(); } catch (\Throwable $_) {} };

    $holdLog();
    [$e, $secs] = $run(static fn() => logActivity('r8.outside', 'test', 'B1', ['case' => 'B1']));
    $releaseLog();
    $check('B1: outside a transaction, a REAL lock wait timeout (1205) on the log row: nothing reaches the caller, and the failure is logged',
        $e === null && $secs >= 0.9 && str_contains($logged(), 'write failed for "r8.outside"') && $rowsFor('r8.outside') === 0,
        $describe($e) . sprintf(', %.1f s', $secs));

    /* The seventh review's case: the work is committed, then its log row fails. */
    $holdLog();
    $conn->begin_transaction();
    $conn->query("INSERT INTO r8_probe VALUES (2, 2)");
    $conn->commit();
    [$e, $secs] = $run(static fn() => logActivity('r8.after_commit', 'test', 'B2', ['case' => 'B2']));
    $releaseLog();
    $check('B2: after a commit (the create_song shape: commit, then its activity row), a 1205 on that row: nothing reaches the caller and the committed work stays',
        $e === null && (int)$look->query('SELECT COUNT(*) FROM r8_probe WHERE Id = 2')->fetch_row()[0] === 1, $describe($e));

    $holdLog();
    $conn->begin_transaction();
    $conn->query('UPDATE r8_probe SET V = 200 WHERE Id = 1');
    [$e, $secs] = $run(static fn() => logActivity('r8.inside', 'test', 'B3', ['case' => 'B3']));
    try { $conn->rollback(); } catch (\Throwable $_) {}
    $releaseLog();
    $check('B3: INSIDE a transaction the same 1205 is passed back to the caller (a save must stop), as since round 7',
        $e instanceof \mysqli_sql_exception && (int)$e->getCode() === 1205, $describe($e));

    $deadlock = static function (): never {
        throw new \mysqli_sql_exception('Deadlock found when trying to get lock (a stand-in)', 1213);
    };
    $conn->hooks[] = ['/^\s*INSERT\s+INTO\s+tblActivityLog\b/i', $deadlock];
    [$e] = $run(static fn() => logActivity('r8.outside_1213', 'test', 'B4'));
    $conn->hooks = [];
    $check('B4: outside a transaction, a deadlock (1213) on the log row: nothing reaches the caller', $e === null, $describe($e));

    $conn->begin_transaction();
    $conn->hooks[] = ['/^\s*INSERT\s+INTO\s+tblActivityLog\b/i', $deadlock];
    [$e] = $run(static fn() => logActivity('r8.inside_1213', 'test', 'B5'));
    $conn->hooks = [];
    try { $conn->rollback(); } catch (\Throwable $_) {}
    $check('B5: inside a transaction, the same deadlock is passed back', $e instanceof \mysqli_sql_exception && (int)$e->getCode() === 1213, $describe($e));

    $conn->hooks[] = ['/^\s*INSERT\s+INTO\s+tblActivityLog\b/i', static function (): never {
        throw new \mysqli_sql_exception('Unknown column (an ordinary error, a stand-in)', 1054);
    }];
    $conn->begin_transaction();
    [$e] = $run(static fn() => logActivity('r8.inside_1054', 'test', 'B6'));
    $conn->hooks = [];
    try { $conn->rollback(); } catch (\Throwable $_) {}
    $check('B6: inside a transaction, an ordinary error on the log row is still logged and walked past (logging is best-effort)', $e === null, $describe($e));

    /* B8 — a REAL deadlock on the log row inside a transaction. This is the
       case that shows WHEN the "is a transaction open?" question must be
       asked: a real deadlock rolls the whole transaction back before PHP sees
       the error, so asking afterwards would answer "not open" and the error
       would be swallowed (the stand-ins above end nothing, so they cannot
       show this). How the deadlock is made: this connection's transaction
       locks a row; the other connection makes its own transaction heavier
       (so the server picks THIS one to roll back), locks the log's last gap,
       and then asks for this connection's row (it waits); this connection's
       log row then waits for the other's gap lock — each waits for the other,
       and the server ends one of them. */
    $conn->query('CREATE TABLE r8_heavy (Id INT PRIMARY KEY, V INT) ENGINE=InnoDB');
    $conn->query('INSERT INTO r8_heavy (Id, V) VALUES ' . implode(', ', array_map(static fn(int $i): string => "({$i}, 0)", range(1, 200))));
    $conn->begin_transaction();
    $conn->query('UPDATE r8_probe SET V = 400 WHERE Id = 1');
    $other->begin_transaction();
    $other->query('UPDATE r8_heavy SET V = V + 1');
    $other->query('SELECT Id FROM tblActivityLog FOR UPDATE')->fetch_all();
    $other->query('UPDATE r8_probe SET V = V + 1 WHERE Id = 1', MYSQLI_ASYNC);
    usleep(300000);   /* let it start waiting for this connection's row */
    [$e] = $run(static fn() => logActivity('r8.real_deadlock', 'test', 'B8'));
    $links = $errs = $rejects = [$other];
    mysqli_poll($links, $errs, $rejects, 10);
    $otherGot = 'ok';
    try { $other->reap_async_query(); } catch (\Throwable $x) { $otherGot = (string)$x->getCode(); }
    $openAfter = dbTransactionIsOpen($conn);
    try { $conn->rollback(); } catch (\Throwable $_) {}
    $releaseLog();
    $check('B8: a REAL deadlock on the log row inside a transaction (the server rolled this transaction back) is passed back — so logActivity() asked whether a transaction was open BEFORE writing; asked afterwards it would have said "not open"',
        $e instanceof \mysqli_sql_exception && (int)$e->getCode() === 1213 && $otherGot === 'ok' && $openAfter === false
        && (int)$look->query('SELECT V FROM r8_probe WHERE Id = 1')->fetch_row()[0] !== 400,
        $describe($e) . " | the other connection: {$otherGot} | open afterwards: " . var_export($openAfter, true));

    /* The ISWC works backfill: a deadlock on its own log row, at the end of
       the batch, must not stop it. */
    $admin->query("INSERT INTO tblSongs (SongId, Number, Title, SongbookAbbr, Language, Iswc) VALUES
        ('MISC-0801', 1, 'Backfill one', 'Misc', 'en', 'T-034.524.680-C'),
        ('MISC-0802', 2, 'Backfill one', 'Misc', 'en', 'T-034.524.680-C')");
    $conn->hooks[] = ['/^\s*INSERT\s+INTO\s+tblActivityLog\b/i', $deadlock];
    ob_start();
    [$e] = $run(static function () use ($repoRoot): void { require $repoRoot . '/appWeb/.sql/backfill-works-from-iswc.php'; });
    $out = (string)ob_get_clean();
    $conn->hooks = [];
    $works = (int)$look->query("SELECT COUNT(*) FROM tblWorks WHERE Iswc = 'T-034.524.680-C'")->fetch_row()[0];
    $members = (int)$look->query("SELECT COUNT(*) FROM tblWorkSongs ws JOIN tblWorks w ON w.Id = ws.WorkId WHERE w.Iswc = 'T-034.524.680-C'")->fetch_row()[0];
    $check('B7: the ISWC works backfill whose own log row meets a deadlock finishes its batch (the work and both members are saved, and it says it finished)',
        $e === null && str_contains($out, 'Works ISWC backfill finished.') && $works === 1 && $members === 2,
        $describe($e) . ' | works=' . $works . ' members=' . $members . ' | ' . substr(trim($out), -120));

    /* ------------------------------------------------------------------ Part C */
    echo "\nPart C — the geo cache is never written inside a transaction\n";
    $cacheRow = static function (string $ip) use ($look): ?array {
        $st = $look->prepare('SELECT CountryCode, Source FROM tblIpReputation WHERE IpAddress = ?');
        $st->bind_param('s', $ip);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        return $row;
    };
    $conn->begin_transaction();
    $conn->query('SELECT COUNT(*) FROM r8_probe')->fetch_all();
    $hit = ihymnsGeoLookup('203.0.113.21');
    $conn->commit();
    $check('C1: a lookup inside a transaction answers from the local country database (GB) but writes no cache row',
        ($hit['code'] ?? null) === 'GB' && $cacheRow('203.0.113.21') === null, json_encode([$hit, $cacheRow('203.0.113.21')]));
    $hit = ihymnsGeoLookup('203.0.113.22');
    $check('C2: the same lookup outside any transaction (a later request) writes the row',
        ($hit['code'] ?? null) === 'GB' && $cacheRow('203.0.113.22') === ['CountryCode' => 'GB', 'Source' => 'maxmind'],
        json_encode($cacheRow('203.0.113.22')));

    /* The seventh review's race, at the cache write itself: the transaction has
       read the database; another request writes the same IP's row and
       commits; then the transaction's cache write. On MariaDB that write used
       to meet a 1020 and end the whole transaction. */
    $conn->begin_transaction();
    $conn->query('UPDATE r8_probe SET V = 300 WHERE Id = 1');
    $conn->query('SELECT COUNT(*) FROM tblIpReputation')->fetch_all();     /* the transaction's view of the data is fixed here */
    $other->query("INSERT INTO tblIpReputation (IpAddress, CountryCode, CountryName, GeoLookedUpAt, Source) VALUES ('203.0.113.23', 'FR', 'France', NOW(), 'other-request')");
    [$e] = $run(static fn() => ihymnsGeoCachePut($conn, '203.0.113.23', 'GB', 'United Kingdom', 'maxmind'));
    $stillOpen = dbTransactionIsOpen($conn);
    try { $conn->commit(); } catch (\Throwable $_) {}
    $check('C3 (the review\'s race): a cache write inside a transaction while another request writes the same row: nothing thrown, the transaction is still open and commits its own work, and the other request\'s row is untouched',
        $e === null && $stillOpen === true && (int)$look->query('SELECT V FROM r8_probe WHERE Id = 1')->fetch_row()[0] === 300
        && $cacheRow('203.0.113.23') === ['CountryCode' => 'FR', 'Source' => 'other-request'],
        $describe($e) . ' | open=' . var_export($stillOpen, true) . ' | ' . json_encode($cacheRow('203.0.113.23')));
} finally {
    try { $admin->query("DROP DATABASE IF EXISTS `{$name}`"); } catch (\Throwable $_) {}
}

echo "\n  {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
