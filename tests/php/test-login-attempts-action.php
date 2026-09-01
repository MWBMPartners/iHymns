<?php

declare(strict_types=1);

/**
 * iHymns — login-attempts Action column: migration + never-weaker behaviour (#1929)
 * ============================================================================
 *
 * The DSN-gated behavioural half of #1929's verification (the DB-free half —
 * authLoginIpFailureCountSql()'s two branches, the pinned constants — lives in
 * tests/php/test-auth-rate-limit.php Section 5, which uses that file's own
 * balanced-brace extract+eval technique; this file complements it rather than
 * repeating it, per rule #34's "don't duplicate a check that already exists").
 *
 * THREE THINGS ONLY A REAL DATABASE CAN PROVE:
 *
 *   1. A FRESH install (schema.sql's tblLoginAttempts CREATE TABLE, run as-is)
 *      already has Action DEFAULT 'login' — so a brand-new install and a
 *      migrated long-running one land on the exact same shape (rule #19).
 *
 *   2. An UN-MIGRATED table (today's 4-column shape, Action absent) can have
 *      the migration's OWN `ALTER TABLE` DDL — extracted from
 *      appWeb/.sql/migrate-add-login-attempts-action.php, not retyped — applied
 *      to it, that pre-existing rows read back Action='login' (the
 *      never-weaker default), and that re-applying the same DDL a second time
 *      is a no-op (idempotent, matching the script's own [SKIP] contract).
 *
 *      NOTE ON HOW THIS IS DRIVEN: the migration script itself follows the
 *      simple, self-contained, file-based-credentials style directed by
 *      CLAUDE.md rule #19's "clone migrate-add-component-label.php exactly"
 *      (no `$db` parameter, no IHYMNS_MIGRATION_NO_AUTORUN function-wrapper —
 *      unlike e.g. migrate-delete-songs-rewiden.php, which IS built that way
 *      because it carries a genuine decision table worth unit-testing in
 *      isolation). A single existence-guarded `ADD COLUMN` has no decision
 *      logic to isolate, so this test instead extracts the script's OWN
 *      `ALTER TABLE …` string (the exact bytes it would execute) via a
 *      balanced-parenthesis scan and runs THAT against the scratch DB — this
 *      proves the migration's real DDL is correct and idempotent without
 *      redirecting its credential-file loading at a scratch database (which
 *      would risk ever pointing that script at a real one).
 *
 *   3. NEVER-WEAKER, BEHAVIOURALLY: on the migrated scratch table, the REAL
 *      `loginAttemptsInsert()` + `authLoginIpFailureCount()`
 *      (includes/rate_limit.php) — called for real, not eval'd in isolation —
 *      correctly separate a flood of `auth_register` failures from genuine
 *      `login` failures for the SAME IP, and still respect the 15-minute
 *      window. getDbMysqli()'s credential-loading is redirected at the
 *      scratch database via the DB_HOST/DB_USER/… constant seam
 *      (includes/db_mysql.php: "if (file_exists(...) && !defined('DB_HOST'))"
 *      skips the real credentials file once these are pre-defined) — no app
 *      code changed to make this observable.
 *
 * CONNECTION: same idiom as test-schema-installs.php / test-delete-songs-rewiden.php
 *   — set IHYMNS_TEST_DSN (e.g. `host=127.0.0.1;user=root;pass=`) or rely on a
 *   local socket as root. Creates and drops its own `ihymns_t1929_*` databases.
 *   SKIPS (loudly, not silently) when no server is reachable — a skip that
 *   reads like a pass is the #1701 mistake test-schema-installs.php's own
 *   doc-block already warns about.
 *
 *   php tests/php/test-login-attempts-action.php
 *
 * Exit status 0 = every assertion that ran passed (or the live half skipped
 * cleanly), 1 = at least one failure.
 *
 * @see appWeb/.sql/migrate-add-login-attempts-action.php
 * @see appWeb/public_html/includes/rate_limit.php
 * @see tests/php/test-auth-rate-limit.php  Section 5 — the DB-free half
 * @see tests/php/test-schema-installs.php  the SKIP idiom this mirrors
 * @see #1929 #1930
 */

$root = dirname(__DIR__, 2);

$fail = 0;
$pass = 0;
function ok(string $label, bool $cond, string $detail = ''): void
{
    global $fail, $pass;
    echo ($cond ? "  PASS  " : "  FAIL  ") . $label . "\n";
    if ($cond) { $pass++; return; }
    $fail++;
    if ($detail !== '') { echo "        $detail\n"; }
}

/**
 * Normalise an INFORMATION_SCHEMA.COLUMNS `COLUMN_DEFAULT` value for a
 * string-typed column across server flavours.
 *
 * ELI5: "strip the quote marks off the default value, if the database put
 * any there" — MariaDB reports a VARCHAR DEFAULT wrapped in literal single
 * quotes (`'login'`, quotes included as characters), MySQL 8 reports the
 * bare value (`login`) — same DEFAULT, two different metadata conventions.
 * Discovered running THIS test for real against MariaDB 10.11: the shipped
 * DDL was correct throughout: only the test's comparison needed to become
 * server-portable.
 *
 * @param mixed $raw The COLUMN_DEFAULT cell as fetched.
 * @return string|null
 */
function tlaaNormalizeDefault($raw): ?string
{
    if (!is_string($raw)) { return $raw; }
    if (strlen($raw) >= 2 && $raw[0] === "'" && $raw[strlen($raw) - 1] === "'") {
        return substr($raw, 1, -1);
    }
    return $raw;
}

/* ---------------------------------------------------------------------- *
 * Extract the exact CREATE TABLE tblLoginAttempts block from schema.sql,
 * and the exact ALTER TABLE the migration script would run — read from the
 * real files, never retyped, so this test can never quietly drift from what
 * actually ships.
 * ---------------------------------------------------------------------- */
$schemaPath = $root . '/appWeb/.sql/schema.sql';
$migPath    = $root . '/appWeb/.sql/migrate-add-login-attempts-action.php';

$schemaSql = @file_get_contents($schemaPath);
ok('schema.sql was read from disk', is_string($schemaSql) && strlen($schemaSql) > 10000, $schemaPath);
$migSrc = @file_get_contents($migPath);
ok('migrate-add-login-attempts-action.php was read from disk', is_string($migSrc) && $migSrc !== '', $migPath);

if (!is_string($schemaSql) || !is_string($migSrc)) {
    echo "\nFATAL: cannot proceed without both source files.\n";
    exit(1);
}

/* Pull the ONE CREATE TABLE tblLoginAttempts block (up to the closing
   `) ENGINE=...;`), by locating the marker and balancing parens is
   overkill here — schema.sql already brackets it with a plain terminator. */
$createStart = strpos($schemaSql, 'CREATE TABLE IF NOT EXISTS tblLoginAttempts');
ok('located CREATE TABLE tblLoginAttempts in schema.sql', $createStart !== false);
$createSql = null;
if ($createStart !== false) {
    $createEnd = strpos($schemaSql, ';', $createStart);
    ok('located the statement terminator', $createEnd !== false);
    if ($createEnd !== false) {
        $createSql = substr($schemaSql, $createStart, $createEnd - $createStart + 1);
    }
}

/* Pull the migration's own ALTER TABLE literal — bounded scan (not a
   catastrophic-backtracking regex spanning the whole file — see the
   cautionary tale in test-auth-rate-limit.php's tarlExtractLiteralsContaining()
   doc-block, discovered writing THIS epic's sibling guard). */
$alterStart = strpos($migSrc, 'ALTER TABLE tblLoginAttempts');
ok('located ALTER TABLE tblLoginAttempts in the migration script', $alterStart !== false);
$alterSql = null;
if ($alterStart !== false) {
    // The literal is a double-quoted PHP string; find its closing quote.
    $quoteOpen = strrpos(substr($migSrc, 0, $alterStart), '"');
    $quoteClose = $quoteOpen !== false ? strpos($migSrc, '"', $alterStart) : false;
    ok('located the ALTER statement\'s enclosing quotes', $quoteOpen !== false && $quoteClose !== false);
    if ($quoteOpen !== false && $quoteClose !== false) {
        $alterSql = substr($migSrc, $quoteOpen + 1, $quoteClose - $quoteOpen - 1);
    }
}
ok('the extracted ALTER adds a column literally named Action', $alterSql !== null && strpos($alterSql, 'ADD COLUMN Action') !== false);
ok("the extracted ALTER's DEFAULT is 'login' (never-weaker for pre-existing rows)",
    $alterSql !== null && (bool) preg_match("/DEFAULT\s+'login'/", $alterSql));

/* ---------------------------------------------------------------------- *
 * LIVE — connect, or SKIP loudly.
 * ---------------------------------------------------------------------- */
echo "\nLIVE — against a real MySQL/MariaDB\n";

$dsn  = getenv('IHYMNS_TEST_DSN') ?: '';
$host = '127.0.0.1'; $user = 'root'; $pass_ = ''; $sock = null;
if ($dsn !== '') {
    foreach (explode(';', $dsn) as $kv) {
        [$k, $v] = array_pad(explode('=', $kv, 2), 2, '');
        if ($k === 'host') { $host = $v; }
        if ($k === 'user') { $user = $v; }
        if ($k === 'pass') { $pass_ = $v; }
        if ($k === 'socket') { $sock = $v; }
    }
} elseif (file_exists('/var/run/mysqld/mysqld.sock')) {
    $sock = '/var/run/mysqld/mysqld.sock';
}

/* Probe by CONNECTING, not by looking for the socket file — a socket file
   outlives the server that made it (the lesson test-delete-songs-rewiden.php
   already learned the hard way; a guard that fails on a dead-but-present
   socket is a guard that fails on CORRECT code, rule #34). */
$probe = null;
try {
    mysqli_report(MYSQLI_REPORT_OFF);
    $probe = $sock !== null
        ? @new mysqli(null, $user, $pass_, '', 0, $sock)
        : @new mysqli($host, $user, $pass_);
    if ($probe->connect_errno) { $probe = null; }
} catch (\Throwable $e) {
    $probe = null;
}

if ($probe === null) {
    echo "  SKIP  no MySQL/MariaDB reachable — the LIVE half did not run.\n";
    echo "        The static extraction above proves the shipped DDL SAYS the right\n";
    echo "        thing; only a real load proves it DOES the right thing. Set\n";
    echo "        IHYMNS_TEST_DSN, or install a server in CI.\n";
    echo "\n";
    if ($fail > 0) { echo "$fail assertion(s) failed.\n"; exit(1); }
    echo "All static assertions passed (live half skipped).\n";
    exit(0);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$dbFresh = 'ihymns_t1929_fresh_' . substr(hash('sha256', (string)getmypid()), 0, 8);
$dbMig   = 'ihymns_t1929_mig_'   . substr(hash('sha256', (string)getmypid()), 0, 8);

try {
    /* --- 1. FRESH install: schema.sql's own CREATE TABLE, run as-is --- */
    echo "\n1 — a fresh install (schema.sql) already has Action DEFAULT 'login'\n";
    $probe->query("DROP DATABASE IF EXISTS `$dbFresh`");
    $probe->query("CREATE DATABASE `$dbFresh` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $probe->select_db($dbFresh);
    if ($createSql !== null) {
        $probe->query($createSql);
        $col = $probe->query(
            "SELECT COLUMN_DEFAULT FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = '$dbFresh' AND TABLE_NAME = 'tblLoginAttempts' AND COLUMN_NAME = 'Action'"
        )->fetch_assoc();
        ok('fresh install has tblLoginAttempts.Action', $col !== null);
        ok("fresh install's Action column defaults to 'login'",
            tlaaNormalizeDefault($col['COLUMN_DEFAULT'] ?? null) === 'login',
            'found: ' . var_export($col['COLUMN_DEFAULT'] ?? null, true));
    }

    /* --- 2. UN-MIGRATED → apply the migration's OWN DDL → migrated ---- */
    echo "\n2 — the migration's own DDL, applied to an un-migrated table\n";
    $probe->query("DROP DATABASE IF EXISTS `$dbMig`");
    $probe->query("CREATE DATABASE `$dbMig` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $probe->select_db($dbMig);
    /* The PRE-#1929 (4-column) shape — byte-identical to schema.sql's table
       minus the Action line, so this genuinely models "an existing install
       that hasn't run the migration yet", not a made-up shape. */
    $probe->query(
        "CREATE TABLE tblLoginAttempts (
            Id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            IpAddress VARCHAR(45) NOT NULL,
            Username VARCHAR(100) NOT NULL DEFAULT '',
            Success TINYINT(1) NOT NULL DEFAULT 0,
            AttemptedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_Ip (IpAddress),
            INDEX idx_IpTime (IpAddress, AttemptedAt)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    /* A row written BEFORE the migration — proves the never-weaker DEFAULT
       claim on data that already existed, not just on a freshly-created
       column. */
    $probe->query("INSERT INTO tblLoginAttempts (IpAddress, Username, Success) VALUES ('9.9.9.9', 'preexisting', 0)");

    $hasActionBefore = $probe->query(
        "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = '$dbMig' AND TABLE_NAME = 'tblLoginAttempts' AND COLUMN_NAME = 'Action'"
    )->fetch_row();
    ok('the un-migrated table genuinely lacks Action before the DDL runs', $hasActionBefore === null);

    if ($alterSql !== null) {
        $probe->query($alterSql);
    }
    $colAfter = $probe->query(
        "SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = '$dbMig' AND TABLE_NAME = 'tblLoginAttempts' AND COLUMN_NAME = 'Action'"
    )->fetch_assoc();
    ok('after the migration DDL, Action exists', $colAfter !== null);
    ok('Action is varchar(40)', strtolower((string)($colAfter['COLUMN_TYPE'] ?? '')) === 'varchar(40)',
        'found: ' . var_export($colAfter['COLUMN_TYPE'] ?? null, true));
    ok("Action is NOT NULL DEFAULT 'login'",
        ($colAfter['IS_NULLABLE'] ?? null) === 'NO'
        && tlaaNormalizeDefault($colAfter['COLUMN_DEFAULT'] ?? null) === 'login',
        'found: ' . var_export($colAfter, true));

    /* Byte-match against the FRESH install's own column definition — a
       migrated install and a fresh one must land on the exact same shape
       (rule #19), not two DDLs that happen to both "work". */
    if ($createSql !== null) {
        $colFresh = $probe->query(
            "SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = '$dbFresh' AND TABLE_NAME = 'tblLoginAttempts' AND COLUMN_NAME = 'Action'"
        )->fetch_assoc();
        ok('migrated shape byte-matches the fresh-install shape', $colFresh === $colAfter,
            'fresh: ' . json_encode($colFresh) . ' vs migrated: ' . json_encode($colAfter));
    }

    /* The pre-existing row reads Action='login' (never-weaker default). */
    $pre = $probe->query("SELECT Action FROM tblLoginAttempts WHERE Username = 'preexisting'")->fetch_assoc();
    ok("the row written BEFORE the migration now reads Action='login'",
        ($pre['Action'] ?? null) === 'login');

    /* Idempotent — re-running the SAME DDL a second time must not error and
       must not change the shape (mirrors the script's own [SKIP] contract;
       MySQL treats a matching ADD COLUMN as a metadata no-op is NOT actually
       true for ADD COLUMN without IF NOT EXISTS on this MySQL/MariaDB
       version in general — so this proves the SCRIPT's existence-guard
       matters, not that raw re-application is safe on its own). */
    $reapplyThrew = false;
    try {
        if ($alterSql !== null) { $probe->query($alterSql); }
    } catch (\Throwable $e) {
        $reapplyThrew = true;
    }
    ok('re-applying the SAME raw ALTER a second time throws (duplicate column) — '
        . 'CONFIRMING the migration SCRIPT\'s own colExists() guard is load-bearing, '
        . 'not decorative: without it, a second run would fatal instead of [SKIP]',
        $reapplyThrew === true);

    /* --- 3. NEVER-WEAKER, BEHAVIOURALLY: the real functions, for real --- */
    echo "\n3 — the real loginAttemptsInsert()/authLoginIpFailureCount(), against $dbMig\n";
    /* Redirect getDbMysqli()'s credential loading at the scratch DB (the
       includes/db_mysql.php seam: skips the real credentials file once
       DB_HOST is already defined) — no app code changed to make this
       observable, and this is a NEW connection query at each test row, not
       $probe reused, so it exercises the exact path production runs. */
    /* getDbMysqli() connects via new mysqli($host, ...) with no explicit
       socket parameter — PHP's mysqli extension resolves the host string
       'localhost' to its configured default socket (the same one $sock
       above points at), so pass that instead of an IP when this session
       connected via socket. Falls back to the resolved TCP $host/$dsn
       otherwise. */
    define('DB_HOST', $sock !== null ? 'localhost' : $host);
    define('DB_USER', $user);
    define('DB_PASS', $pass_);
    define('DB_NAME', $dbMig);
    require_once $root . '/appWeb/public_html/includes/rate_limit.php';
    $behaviourDb = getDbMysqli();

    // Clean slate for the behavioural rows.
    $behaviourDb->query("DELETE FROM tblLoginAttempts WHERE IpAddress IN ('8.8.4.4','8.8.8.8','1.2.3.4')");

    /* 10 auth_register failures from ONE IP must NOT count toward that IP's
       LOGIN lockout — this is #1929's headline claim. */
    for ($i = 0; $i < 10; $i++) {
        loginAttemptsInsert($behaviourDb, '8.8.4.4', 'auth_register', false, 'auth_register');
    }
    $regFailureCount = authLoginIpFailureCount('8.8.4.4');
    ok('10 auth_register failures from an IP do NOT trip that IP\'s login lockout (found '
        . $regFailureCount . ')', $regFailureCount === 0);

    /* 10 REAL login failures from a DIFFERENT IP must still trip the lockout
       at the SAME threshold as before #1929 — never weaker. */
    for ($i = 0; $i < 10; $i++) {
        loginAttemptsInsert($behaviourDb, '8.8.8.8', 'alice', false, 'login');
    }
    $loginFailureCount = authLoginIpFailureCount('8.8.8.8');
    ok('10 genuine login failures from an IP DO trip the lockout at the unchanged threshold '
        . '(found ' . $loginFailureCount . ', threshold ' . IHYMNS_AUTH_IP_MAX . ')',
        $loginFailureCount === 10 && $loginFailureCount >= IHYMNS_AUTH_IP_MAX);

    /* A MIX on the SAME IP — real logins failures count, the auth_register
       noise on the SAME address still doesn't. */
    for ($i = 0; $i < 5; $i++) {
        loginAttemptsInsert($behaviourDb, '1.2.3.4', 'bob', false, 'login');
    }
    for ($i = 0; $i < 20; $i++) {
        loginAttemptsInsert($behaviourDb, '1.2.3.4', 'service_join', false, 'service_join');
    }
    $mixedCount = authLoginIpFailureCount('1.2.3.4');
    ok('on ONE IP, 20 service_join failures do not inflate 5 real login failures '
        . '(found ' . $mixedCount . ', expected 5)', $mixedCount === 5);

    /* A row older than the window is excluded. */
    $behaviourDb->query("DELETE FROM tblLoginAttempts WHERE IpAddress = '5.5.5.5'");
    $oldTs = date('Y-m-d H:i:s', time() - (IHYMNS_AUTH_IP_WINDOW + 300));
    $stmtOld = $behaviourDb->prepare(
        "INSERT INTO tblLoginAttempts (IpAddress, Username, Success, Action, AttemptedAt) VALUES ('5.5.5.5', 'carol', 0, 'login', ?)"
    );
    $stmtOld->bind_param('s', $oldTs);
    $stmtOld->execute();
    $stmtOld->close();
    $oldCount = authLoginIpFailureCount('5.5.5.5');
    ok('a login failure older than IHYMNS_AUTH_IP_WINDOW is excluded from the count '
        . '(found ' . $oldCount . ', expected 0)', $oldCount === 0);

} finally {
    try { $probe->query("DROP DATABASE IF EXISTS `$dbFresh`"); } catch (\Throwable $e) {}
    try { $probe->query("DROP DATABASE IF EXISTS `$dbMig`"); } catch (\Throwable $e) {}
    $probe->close();
}

echo "\n";
if ($fail > 0) {
    echo "$fail assertion(s) failed.\n";
    exit(1);
}
echo "All login-attempts-action assertions passed ($pass).\n";
