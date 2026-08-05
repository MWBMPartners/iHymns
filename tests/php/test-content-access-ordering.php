<?php

declare(strict_types=1);

/**
 * iHymns — content-restriction tiebreak guard: DENY wins at equal priority (#1769 P0)
 * ==================================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * When two content-restriction rules for the same song sit at the SAME
 * priority — one "allow", one "deny" — the deny must win. That is what the file
 * header of `includes/content_access.php`, the `tblContentRestrictions` schema
 * comment, and the `/manage/restrictions` admin page have ALWAYS promised. For
 * years the SQL tiebreak (`ORDER BY … Effect ASC`) did the opposite (allow
 * won); #1769 P0 corrected it to `Effect DESC`. This guard makes sure it stays
 * corrected, in TWO independent ways so it can never silently regress:
 *
 *   Part A (always runs, no DB) — a source scan asserting the ORDER BY reads
 *     `Effect DESC` and never `Effect ASC`. Cheap, runs in every CI lane
 *     including the ones without a database.
 *   Part B (runs when a database is reachable) — a BEHAVIOURAL test: it inserts
 *     real equal-priority allow+deny rules inside a rolled-back transaction and
 *     asserts checkContentAccess() actually returns "denied". This is the guard
 *     that proves the *verdict*, not just the source text (rule #39 — prefer
 *     behavioural over source-inspection; the DB-driven evaluator had no harness
 *     before this, per content_access.php's own note).
 *
 * MUTATION PROOF (rule #34): flip the ORDER BY back to `Effect ASC` in
 * content_access.php and BOTH halves go red — Part A on the string, Part B on
 * the equal-priority verdict (allow would win). Restore and both pass. (Proven
 * by the author via a cp-backup mutation, not committed.)
 *
 * Usage:  php tests/php/test-content-access-ordering.php
 *         (Part B needs IHYMNS_TEST_DSN with a dbname, or appWeb/.auth/db_credentials.php)
 * Exit:   0 = pass (Part B may SKIP if no DB — Part A still ran), 1 = failure.
 *
 * @link appWeb/public_html/includes/content_access.php  the ORDER BY under test (#1769 P0)
 * @see  tools/audit-restriction-ordering.php             the per-docroot pre-enable audit
 */

$repoRoot = dirname(__DIR__, 2);
$failures = [];

/* =========================================================================
 * PART A — source scan (always runs). The ORDER BY must break the tie with
 * DENY first (Effect DESC), matching every piece of documentation.
 * ========================================================================= */
$caFile = $repoRoot . '/appWeb/public_html/includes/content_access.php';
$caSrc  = is_readable($caFile) ? (string)file_get_contents($caFile) : '';
if ($caSrc === '') {
    fwrite(STDERR, "FATAL: could not read {$caFile}\n");
    exit(1);
}
if (!preg_match('/ORDER\s+BY\s+Priority\s+DESC\s*,\s*Effect\s+DESC/i', $caSrc)) {
    $failures[] = "content_access.php: the restriction query's ORDER BY is not "
                . "'Priority DESC, Effect DESC' — deny must sort before allow so it wins the "
                . "equal-priority tie (#1769 P0).";
}
if (preg_match('/ORDER\s+BY\s+Priority\s+DESC\s*,\s*Effect\s+ASC/i', $caSrc)) {
    $failures[] = "content_access.php: the restriction query still uses 'Effect ASC' — that is the "
                . "#1158 inversion where a matching ALLOW beats DENY at equal priority, contradicting "
                . "the file header, schema.sql and the admin page. Must be 'Effect DESC'.";
}

/* =========================================================================
 * PART B — behavioural (runs when a DB is reachable, else SKIP). Insert real
 * equal-priority allow+deny rules and assert the verdict is DENY.
 *
 * Connection idiom mirrors test-schema-installs.php / the external-ids tests:
 * db_credentials.php first, else IHYMNS_TEST_DSN (needs a dbname), else socket.
 * We DEFINE the DB_* constants so checkContentAccess()'s internal getDbMysqli()
 * connects to the SAME database we insert into — then we begin a transaction,
 * insert on that shared singleton connection (so the uncommitted rows are
 * visible to the reader), assert, and ROLL BACK so nothing persists.
 * ========================================================================= */
$host = '127.0.0.1'; $user = 'root'; $pass = ''; $sock = null; $port = 3306; $dbName = null;
$credFile = $repoRoot . '/appWeb/.auth/db_credentials.php';
if (is_readable($credFile)) {
    require $credFile;
    if (defined('DB_HOST')) { $host = DB_HOST; }
    if (defined('DB_USER')) { $user = DB_USER; }
    if (defined('DB_PASS')) { $pass = DB_PASS; }
    if (defined('DB_PORT')) { $port = (int)DB_PORT; }
    if (defined('DB_NAME')) { $dbName = DB_NAME; }
} else {
    $dsn = getenv('IHYMNS_TEST_DSN') ?: '';
    if ($dsn !== '') {
        foreach (explode(';', $dsn) as $kv) {
            [$k, $v] = array_pad(explode('=', $kv, 2), 2, '');
            if ($k === 'host')   { $host = $v; }
            if ($k === 'user')   { $user = $v; }
            if ($k === 'pass')   { $pass = $v; }
            if ($k === 'socket') { $sock = $v; }
            if ($k === 'port')   { $port = (int)$v; }
            if ($k === 'dbname' || $k === 'db') { $dbName = $v; }
        }
    }
}

$behaviouralRan = false;
if ($dbName !== null && $sock === null) {
    /* Point getDbMysqli() at the test DB (only if the app hasn't already been
       configured by a real credentials file above). */
    if (!defined('DB_HOST')) { define('DB_HOST', $host); }
    if (!defined('DB_USER')) { define('DB_USER', $user); }
    if (!defined('DB_PASS')) { define('DB_PASS', $pass); }
    if (!defined('DB_PORT')) { define('DB_PORT', (string)$port); }
    if (!defined('DB_NAME')) { define('DB_NAME', $dbName); }

    require_once $repoRoot . '/appWeb/public_html/includes/content_access.php';

    try {
        $db = getDbMysqli();
        $hasTable = (bool)$db->query("SHOW TABLES LIKE 'tblContentRestrictions'")->num_rows;
    } catch (\Throwable $e) {
        $db = null; $hasTable = false;
    }

    if ($db !== null && $hasTable) {
        /* Does the optional AppliesToAction column exist? (#1120) — insert it
           only when present so the test works on both schema generations. */
        $hasAction = (bool)$db->query(
            "SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblContentRestrictions'
               AND COLUMN_NAME = 'AppliesToAction' LIMIT 1"
        )->num_rows;

        $insert = static function (mysqli $db, bool $hasAction, string $eid, string $effect, int $priority): void {
            if ($hasAction) {
                $stmt = $db->prepare(
                    "INSERT INTO tblContentRestrictions
                        (EntityType, EntityId, RestrictionType, AppliesToAction, TargetType, TargetId, Effect, Priority, Reason)
                     VALUES ('song', ?, 'block_platform', 'all', 'platform', 'PWA', ?, ?, 'ordering-guard')"
                );
                $stmt->bind_param('ssi', $eid, $effect, $priority);
            } else {
                $stmt = $db->prepare(
                    "INSERT INTO tblContentRestrictions
                        (EntityType, EntityId, RestrictionType, TargetType, TargetId, Effect, Priority, Reason)
                     VALUES ('song', ?, 'block_platform', 'platform', 'PWA', ?, ?, 'ordering-guard')"
                );
                $stmt->bind_param('ssi', $eid, $effect, $priority);
            }
            $stmt->execute();
            $stmt->close();
        };

        $db->begin_transaction();
        try {
            /* Three distinct entities so each checkContentAccess() sees only its
               own rules. All rules match a PWA viewer (block_platform PWA). */
            $eqTie   = 'ZZTEST-1769-EQ';    // deny + allow at SAME priority → deny must win (THE FIX)
            $carve   = 'ZZTEST-1769-CARVE'; // higher-priority allow over lower deny → allow wins (unaffected)
            $denyTop = 'ZZTEST-1769-DENY';  // higher-priority deny over lower allow → deny wins

            $insert($db, $hasAction, $eqTie, 'deny',  500);
            $insert($db, $hasAction, $eqTie, 'allow', 500);

            $insert($db, $hasAction, $carve, 'deny',  100);
            $insert($db, $hasAction, $carve, 'allow', 200);

            $insert($db, $hasAction, $denyTop, 'allow', 100);
            $insert($db, $hasAction, $denyTop, 'deny',  200);

            $eq   = checkContentAccess('song', $eqTie,   null, 'PWA');
            $cv   = checkContentAccess('song', $carve,   null, 'PWA');
            $dt   = checkContentAccess('song', $denyTop, null, 'PWA');

            if (($eq['allowed'] ?? null) !== false) {
                $failures[] = "EQUAL-PRIORITY tie: a deny and an allow both at Priority 500 must resolve to "
                            . "DENIED, but checkContentAccess() returned allowed="
                            . var_export($eq['allowed'] ?? null, true) . " — the #1769 P0 tiebreak (deny wins) is broken.";
            }
            if (($cv['allowed'] ?? null) !== true) {
                $failures[] = "CARVE-OUT: a higher-priority (200) allow over a lower-priority (100) deny must resolve "
                            . "to ALLOWED (the deliberate 'everyone blocked except this' pattern), but got allowed="
                            . var_export($cv['allowed'] ?? null, true) . ".";
            }
            if (($dt['allowed'] ?? null) !== false) {
                $failures[] = "HIGHER-PRIORITY DENY: a higher-priority (200) deny over a lower-priority (100) allow must "
                            . "resolve to DENIED, but got allowed=" . var_export($dt['allowed'] ?? null, true) . ".";
            }
            $behaviouralRan = true;
        } finally {
            $db->rollback(); // never persist the probe rows
        }
    }
}

/* =========================================================================
 * REPORT
 * ========================================================================= */
if ($failures) {
    fwrite(STDERR, "FAIL: content-restriction ordering guard (#1769 P0):\n\n");
    foreach ($failures as $f) { fwrite(STDERR, "  - {$f}\n"); }
    fwrite(STDERR, "\n");
    exit(1);
}

echo "PASS: content-restriction tiebreak — ORDER BY is 'Effect DESC' (deny wins at equal priority)";
echo $behaviouralRan
    ? ", and the behavioural check confirmed the verdict against a live database (equal-priority tie → denied, "
      . "carve-out allow preserved, higher-priority deny wins).\n"
    : " (source scan only — Part B SKIPPED: no reachable database with dbname; set IHYMNS_TEST_DSN to exercise the verdict).\n";
exit(0);
