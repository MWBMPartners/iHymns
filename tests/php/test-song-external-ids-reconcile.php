<?php

declare(strict_types=1);

/**
 * iHymns — #1749 ISRC reconcile-migration guard (epic #1741 full unification)
 *
 * ELI5
 * ----
 * #1749's cutover ships a one-time migration
 * (`migrate-reconcile-isrc-denorm.php`) plus a dashboard card whose PROBE
 * doubles as a live "is anything drifted?" detector: it turns the card green
 * only when every song's `tblSongs.Isrc` column agrees with the
 * `tblSongExternalIds` store (the new authority). This file proves that probe
 * and the migration's two heal steps actually WORK against a real database —
 * not merely that the files exist.
 *
 * WHY THIS FILE EXISTS (the gap it closes, rule #34)
 * --------------------------------------------------
 * The sibling `test-song-external-ids-backfill.php` gave the #1747 backfill a
 * behavioural round-trip; #1749's reconcile card shipped with only a
 * SOURCE-shape guard (in `test-song-external-id-mirror.php`) and NO behavioural
 * coverage of its probe's DRIFT logic. An adversarial audit of the #1749 diff
 * found that INVERTING the probe's second drift check (column-vs-projection
 * disagreement) passed every existing guard — because nothing ever RAN the
 * probe against a database with a known drift present. This file runs the REAL
 * registry probe (loaded from the registry source, never a hand-typed copy)
 * against a deliberately-drifted fixture row and asserts it reports PENDING,
 * then heals via the migration's REAL write core and asserts it clears —
 * exactly the assertion that would have gone red on the inverted check.
 *
 * SOURCE vs BEHAVIOURAL (same split + skip convention as the sibling)
 * ------------------------------------------------------------------
 * SOURCE checks (always run, no DB): the migration is DATA-ONLY (no CREATE
 * TABLE / ALTER — comment-stripped so the doc-block's prose "no DDL" cannot
 * false-positive, the same care `test-song-external-ids-backfill.php` takes),
 * gates on `SHOW TABLES LIKE`, and calls the THREE shared write/projection
 * helpers rather than re-deriving them (rule #22); the registry entry's probe
 * is a real closure (not the banned `=> true`) that calls
 * `songExternalIdIsrcProjectionSql()`, ordered AFTER the backfill card.
 *
 * BEHAVIOURAL checks (skipped, not failed, when no DB is reachable) run inside
 * ONE transaction ROLLED BACK at the end, so the live fixture DB (dev/CI,
 * never production) is left byte-for-byte unchanged. "Not run" is distinct from
 * "failed" (exit 0 on skip), matching the sibling + `test-intapps-stub-e2e.php`.
 *
 *   php tests/php/test-song-external-ids-reconcile.php
 *
 * MUTATION-TESTING NOTE (rule #34)
 * --------------------------------
 * The behavioural half is itself the mutation proof: because it loads and runs
 * the REAL probe closure out of `migration-registry.php` (via
 * `tsriLoadRegistryForProbe()`), inverting or neutering either drift check in
 * that closure turns THIS file red — a hand-typed copy of the probe query
 * would agree with itself and prove nothing. The two SOURCE mutations
 * (probe → `=> true`; a re-forked `ORDER BY` literal in the migration) are ALSO
 * caught by the project-wide `test-migration-registry.php` and the
 * `test-song-external-id-mirror.php` §7.2 sweep respectively; this file's own
 * narrower SOURCE assertions are the second, entry-scoped net.
 *
 * @see appWeb/.sql/migrate-reconcile-isrc-denorm.php                the script this file exercises
 * @see appWeb/public_html/includes/song_external_ids.php           songExternalIdMirrorIsrc()/…SyncIsrcDenorm()/…ProjectionSql()
 * @see appWeb/public_html/manage/includes/migration-registry.php   the 'reconcile-isrc-denorm' entry + probe
 * @see tests/php/test-song-external-ids-backfill.php               the sibling this mirrors
 * @see #1741
 * @see #1749
 */

$repoRoot = dirname(__DIR__, 2);

$failures = 0;
function tsriOk(string $label, bool $cond, string $detail = ''): void
{
    global $failures;
    echo ($cond ? '  PASS  ' : '  FAIL  ') . $label . "\n";
    if (!$cond) {
        $failures++;
        if ($detail !== '') { echo "         {$detail}\n"; }
    }
}

/* ========================================================================
 * SOURCE CHECKS — always run, no database required.
 * ==================================================================== */

echo "1 — source checks (reconcile migration script + registry entry)\n";

$migrationFile = $repoRoot . '/appWeb/.sql/migrate-reconcile-isrc-denorm.php';
if (!is_readable($migrationFile)) {
    fwrite(STDERR, "FATAL: could not read $migrationFile\n");
    exit(1);
}
$migrationSrc = (string)file_get_contents($migrationFile);

/* ---- the migration calls the THREE shared helpers, never a re-typed copy --- */
foreach ([
    'songExternalIdMirrorIsrc('       => 'Step A (column -> store) re-mint',
    'songExternalIdSyncIsrcDenorm('    => 'Step B (store -> column) projection',
    'songExternalIdIsrcProjectionSql(' => 'the ONE §2.1 primary-ISRC builder',
] as $needle => $why) {
    tsriOk("migration references {$needle} — {$why}", strpos($migrationSrc, $needle) !== false);
}

/* ---- data-only: no CREATE TABLE / ALTER (comment-stripped first, so the
   doc-block's own "no DDL" prose cannot false-positive — same care as the
   sibling backfill guard) ---- */
$migrationSrcNoComments = preg_replace('#/\*.*?\*/#s', '', $migrationSrc) ?? $migrationSrc;
$migrationSrcNoComments = preg_replace('#//[^\n]*#', '', $migrationSrcNoComments) ?? $migrationSrcNoComments;
tsriOk('migration is data-only — no CREATE TABLE',
   !preg_match('/CREATE\s+TABLE/i', $migrationSrcNoComments));
tsriOk('migration is data-only — no ALTER TABLE (rule #19: no schema.sql mirror needed)',
   !preg_match('/ALTER\s+TABLE/i', $migrationSrcNoComments));

/* ---- gated: refuses to run if the store table is absent (STRICT-safe) ---- */
tsriOk("migration gates on SHOW TABLES LIKE 'tblSongExternalIds' before touching it",
   (bool)preg_match("/SHOW\s+TABLES\s+LIKE\s+'?\{?\\\$?t?\}?'?/i", $migrationSrcNoComments)
       && strpos($migrationSrc, 'tblSongExternalIds') !== false);

/* ---- the registry entry: real closure probe, calls the shared builder,
   ordered after the backfill card ---- */
$registryFile = $repoRoot . '/appWeb/public_html/manage/includes/migration-registry.php';
if (!is_readable($registryFile)) {
    fwrite(STDERR, "FATAL: could not read $registryFile\n");
    exit(1);
}
$registrySrc = (string)file_get_contents($registryFile);

/* Slice on the entry's OWN 4-space-indented closing `],` (its nested 'card'
   sub-array closes at 8-space indent) — an own-boundary anchor no later
   sibling insertion can move (the lesson the sibling backfill test just had
   to relearn when THIS entry was inserted after it). */
if (!preg_match(
    "/'reconcile-isrc-denorm'\s*=>\s*\[(.*?)\n    \],/s",
    $registrySrc,
    $entryMatch
)) {
    tsriOk("registry has a 'reconcile-isrc-denorm' entry (extractable slice)", false,
       "could not locate the entry block from its key to its own 4-space-indented closing '],'");
    $reconcileEntrySrc = '';
} else {
    tsriOk("registry has a 'reconcile-isrc-denorm' entry (extractable slice)", true);
    $reconcileEntrySrc = $entryMatch[1];
}

if ($reconcileEntrySrc !== '') {
    tsriOk("'reconcile-isrc-denorm' probe is NOT the banned always-true literal",
       !preg_match("/'probe'\s*=>\s*static\s+fn\s*\(\s*\\\\?mysqli\s+\\\$\w+\s*\)\s*=>\s*true\s*,/m", $reconcileEntrySrc));
    tsriOk("'reconcile-isrc-denorm' probe is a real closure (multi-line body returning bool)",
       (bool)preg_match('/static\s+function\s*\(\s*\\\\?mysqli\s+\$db\s*\)\s*:\s*bool\s*\{/', $reconcileEntrySrc));
    tsriOk("'reconcile-isrc-denorm' probe calls songExternalIdIsrcProjectionSql() (rule #22 — no re-derived projection)",
       strpos($reconcileEntrySrc, 'songExternalIdIsrcProjectionSql(') !== false);
    tsriOk("'reconcile-isrc-denorm' probe does NOT hand-type a re-forked \"ORDER BY (e.SourceRef\" literal",
       strpos($reconcileEntrySrc, 'ORDER BY (e.SourceRef') === false);
}
tsriOk("'reconcile-isrc-denorm' is ordered AFTER 'backfill-song-external-ids' (target + backfill must precede the cutover)",
   (bool)preg_match("/'backfill-song-external-ids'\s*=>\s*\[.*?'reconcile-isrc-denorm'\s*=>\s*\[/s", $registrySrc));

/* ========================================================================
 * BEHAVIOURAL CHECKS — skipped (not failed) when no database is reachable.
 * Runs entirely inside one transaction that is ROLLED BACK.
 * ==================================================================== */

echo "\n2 — behavioural checks (real probe drift detection + heal, rolled back)\n";

/* Load the write core + canonicaliser exactly as the migration does. */
$_SERVER['SCRIPT_FILENAME'] = 'test-runner'; // bypass any direct-HTTP-access guards
require_once $repoRoot . '/appWeb/public_html/includes/song_external_ids.php';
require_once $repoRoot . '/appWeb/public_html/includes/identifier_normalize.php';

/* Resolve connection — creds-file-first, then IHYMNS_TEST_DSN (IDENTICAL
   precedence + rationale to test-song-external-ids-backfill.php). */
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
    } elseif (file_exists('/var/run/mysqld/mysqld.sock')) {
        $sock = '/var/run/mysqld/mysqld.sock';
    }
}

$db = null;
$behaviouralRan = false;
if ($dbName !== null) {
    try {
        mysqli_report(MYSQLI_REPORT_OFF); // probing: a failed connect is an answer, not a fatal
        $db = $sock !== null
            ? @new mysqli(null, $user, $pass, $dbName, 0, $sock)
            : @new mysqli($host, $user, $pass, $dbName, $port);
        if ($db->connect_errno) { $db = null; }
    } catch (\Throwable $e) {
        $db = null;
    }
}

if ($db === null) {
    echo "  SKIP  no application database reachable — the behavioural half did not run.\n";
    echo "        Configure appWeb/.auth/db_credentials.php or set IHYMNS_TEST_DSN (with a dbname) to exercise it.\n";
} else {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    try {
        $haveSongs    = (bool)$db->query("SHOW TABLES LIKE 'tblSongs'")->num_rows;
        $haveExternal = (bool)$db->query("SHOW TABLES LIKE 'tblSongExternalIds'")->num_rows;
    } catch (\Throwable $e) {
        $haveSongs = $haveExternal = false;
    }

    if (!$haveSongs || !$haveExternal) {
        echo "  SKIP  tblSongs and/or tblSongExternalIds not present — run the 'song-external-ids' card first.\n";
        $db->close();
    } else {
        $probeFn = tsriLoadRegistryForProbe($registryFile, $db)['reconcile-isrc-denorm']['probe'] ?? null;
        if (!($probeFn instanceof \Closure)) {
            tsriOk("could load the real 'reconcile-isrc-denorm' probe closure from the registry", false,
               'the registry require did not yield a Closure for this entry');
            $db->close();
        } else {
            $pid = getmypid() ?: 1234567;
            $testIsrc  = sprintf('ZZTESTA%06d', $pid % 1000000); // 13 chars, <=15 (VARCHAR(15) + projection cap)
            $testIsrc2 = sprintf('ZZTESTB%06d', $pid % 1000000); // distinct value for the multi-marker case
            $mirrorRef = SONG_EXTERNAL_ID_ISRC_MIRROR_SOURCE_REF;
            $testSongId = null; $origIsrc = null; $baseline = null;

            $db->begin_transaction();
            try {
                $row = $db->query("SELECT SongId, Isrc FROM tblSongs ORDER BY Id LIMIT 1")->fetch_assoc();
                if (!$row) { throw new \RuntimeException('tblSongs has zero rows — nothing to borrow'); }
                $testSongId = (string)$row['SongId'];
                $origIsrc   = $row['Isrc'];
                $baseline   = $probeFn($db);

                /* small bound helpers -------------------------------------- */
                $exec = static function (string $sql, string $types, array $args) use ($db): void {
                    $stmt = $db->prepare($sql);
                    $stmt->bind_param($types, ...$args);
                    $stmt->execute();
                    $stmt->close();
                };
                $scalar = static function (string $sql, string $types, array $args) use ($db) {
                    $stmt = $db->prepare($sql);
                    $stmt->bind_param($types, ...$args);
                    $stmt->execute();
                    $r = $stmt->get_result()->fetch_row();
                    $stmt->close();
                    return $r === null ? null : $r[0];
                };
                $insertIsrcRow = static function (string $idValue, string $source, string $sourceRef) use ($exec, $testSongId): void {
                    $exec(
                        "INSERT INTO tblSongExternalIds (SongId, IdScope, IdType, IdValue, Source, SourceRef)
                         VALUES (?, 'recording', 'isrc', ?, ?, ?)",
                        'ssss', [$testSongId, $idValue, $source, $sourceRef]
                    );
                };
                $clearSongIsrcRows = static function () use ($exec, $testSongId): void {
                    $exec("DELETE FROM tblSongExternalIds WHERE SongId = ? AND IdType = 'isrc'", 's', [$testSongId]);
                };
                $markerCount = static function () use ($scalar, $testSongId, $mirrorRef): int {
                    return (int)$scalar(
                        "SELECT COUNT(*) FROM tblSongExternalIds WHERE SongId = ? AND IdType = 'isrc' AND SourceRef = ?",
                        'ss', [$testSongId, $mirrorRef]
                    );
                };
                $colIsrc = static function () use ($scalar, $testSongId): ?string {
                    $v = $scalar("SELECT Isrc FROM tblSongs WHERE SongId = ?", 's', [$testSongId]);
                    return $v === null ? null : (string)$v;
                };

                /* ---- SCENARIO 1: second drift check (column vs projection).
                   A store-only manual ISRC row with an empty column = drift.
                   This is the EXACT scenario an inverted second check misses. */
                $clearSongIsrcRows();
                $insertIsrcRow($testIsrc, 'ihymns-test', 'ihymns-test-manual'); // NOT the marker ref => a "manual" row
                $exec("UPDATE tblSongs SET Isrc = '' WHERE SongId = ?", 's', [$testSongId]);

                tsriOk('probe reports PENDING while a store-only ISRC leaves the column empty (2nd drift check; monotonic)',
                   $probeFn($db) === true);

                songExternalIdSyncIsrcDenorm($db, $testSongId); // Step B's real write
                tsriOk('after Step B sync: the test song column now equals its store projection (scoped, corpus-independent)',
                   $colIsrc() === $testIsrc);
                if ($baseline === false) {
                    tsriOk('probe reports NOT PENDING once the only drift (this song) is healed [clean baseline]',
                       $probeFn($db) === false);
                }

                /* ---- SCENARIO 2: first drift check (>1 mirror marker rows).
                   uq_Song_Type_Value is (SongId,IdType,IdValue) — two marker
                   rows need DISTINCT IdValues. */
                $clearSongIsrcRows();
                $exec("UPDATE tblSongs SET Isrc = ? WHERE SongId = ?", 'ss', [$testIsrc, $testSongId]);
                $insertIsrcRow($testIsrc,  'ihymns-mirror', $mirrorRef);
                $insertIsrcRow($testIsrc2, 'ihymns-mirror', $mirrorRef);
                tsriOk('probe reports PENDING while a song carries >1 mirror marker rows (1st drift check, §2.1 anomaly)',
                   $probeFn($db) === true);

                songExternalIdMirrorIsrc($db, $testSongId, $testIsrc); // Step A's real write: DELETE-all-then-INSERT-one
                tsriOk('after Step A re-mint: exactly one mirror marker row remains (multi-marker collapsed, scoped)',
                   $markerCount() === 1);
                if ($baseline === false) {
                    tsriOk('probe reports NOT PENDING after the multi-marker collapse [clean baseline]',
                       $probeFn($db) === false);
                }

                if ($baseline !== false) {
                    echo "  NOTE  the corpus already had drift at test start (probe baseline = PENDING), so the two\n";
                    echo "        clean-baseline 'probe clears' transitions were skipped — run the reconcile card, then\n";
                    echo "        re-run this test to exercise them. The PENDING-detection + scoped-heal assertions ran.\n";
                }
            } catch (\Throwable $e) {
                tsriOk('behavioural setup/run completed without an unexpected exception', false, $e->getMessage());
            } finally {
                $db->rollback();
            }

            /* ---- After rollback: fixture restored. ---- */
            if ($testSongId !== null) {
                $after = $db->query("SELECT Isrc FROM tblSongs WHERE SongId = '" . $db->real_escape_string($testSongId) . "'")->fetch_row();
                tsriOk('after rollback: the test song Isrc is restored to its pre-test value (live fixture untouched)',
                   ($after[0] ?? null) === $origIsrc);
                if ($baseline !== null) {
                    tsriOk('after rollback: probe result is restored to its pre-test baseline',
                       $probeFn($db) === $baseline);
                }
                $behaviouralRan = true;
            }
            $db->close();
        }
    }
}

/* ========================================================================
 * Helper used by the behavioural section (kept below the main flow).
 * ==================================================================== */

/**
 * Load migration-registry.php's $MIGRATIONS with a REAL `_migProbe_tableExists`
 * wired to $db (the only probe helper the reconcile closure calls) plus
 * lazy-safe stubs for the rest — IDENTICAL shape to
 * test-song-external-ids-backfill.php's `tsebLoadRegistryForProbe()`, so this
 * test exercises the ACTUAL probe closure, not a stub.
 */
function tsriLoadRegistryForProbe(string $registryFile, \mysqli $db): array
{
    static $loaded = null;
    if ($loaded !== null) { return $loaded; }

    if (!function_exists('_migProbe_tableExists')) {
        function _migProbe_tableExists(\mysqli $db, string $table): bool
        {
            $stmt = $db->prepare(
                'SELECT 1 FROM INFORMATION_SCHEMA.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
            );
            $stmt->bind_param('s', $table);
            $stmt->execute();
            $exists = (bool)$stmt->get_result()->fetch_row();
            $stmt->close();
            return $exists;
        }
    }
    if (!function_exists('_migProbe_columnExists'))     { function _migProbe_columnExists(\mysqli $db, string $t, string $c): bool { return false; } }
    if (!function_exists('_migProbe_columnIsNullable')) { function _migProbe_columnIsNullable(\mysqli $db, string $t, string $c): bool { return false; } }
    if (!function_exists('_migProbe_triggerExists'))    { function _migProbe_triggerExists(\mysqli $db, string $t): bool { return false; } }
    if (!function_exists('_migProbe_columnDataType'))   { function _migProbe_columnDataType(\mysqli $db, string $t, string $c): ?string { return null; } }

    global $hasCredentials;
    $hasCredentials = true;
    $_SERVER['SCRIPT_FILENAME'] = 'test-runner';

    $loaded = require $registryFile;
    return is_array($loaded) ? $loaded : [];
}

/* ========================================================================
 * REPORT
 * ==================================================================== */

echo "\n";
if ($failures > 0) {
    fwrite(STDERR, "FAIL: {$failures} assertion(s) failed in the #1749 reconcile guard.\n");
    exit(1);
}
echo "PASS: #1749 ISRC reconcile guard — all source checks passed"
   . ($behaviouralRan ? ', including the live behavioural probe-drift round trip.' : ' (behavioural half skipped — see SKIP line above).')
   . "\n";
exit(0);
