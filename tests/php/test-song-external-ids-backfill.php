<?php

declare(strict_types=1);

/**
 * iHymns — Song external-IDs backfill guard (#1747 D5 backfill)
 *
 * ELI5
 * ----
 * Three new things landed together for the #1747 D5 backfill: a new
 * `genius` vocabulary entry, a new migration script that copies old data
 * into the new `tblSongExternalIds` table, and a new dashboard card that
 * says whether that copy still needs running. This file proves all three
 * actually work — not just that the files exist, but that running the real
 * SQL the migration contains, twice, against a real database produces
 * exactly one row, not zero and not two.
 *
 * DETAILED / WHY BOTH "SOURCE" AND "BEHAVIOURAL" CHECKS
 * ------------------------------------------------------
 * SOURCE checks (always run, no database needed) verify the STATIC shape:
 * `genius` is really in the vocabulary map, the migration script really
 * mentions all five origin columns it claims to backfill from, and the
 * registry probe really isn't the banned `=> true` placeholder
 * (tests/php/test-migration-registry.php already guards that pattern
 * project-wide; this file's own check is a second, narrower assertion
 * specific to THIS one entry, requested explicitly for this deliverable).
 *
 * BEHAVIOURAL checks (skipped when no database is reachable) prove the
 * thing a source scan cannot: that the SQL actually behaves idempotently
 * against a real MySQL/MariaDB `uq_Song_Type_Value (SongId, IdType,
 * IdValue)` UNIQUE key. Critically, this file does NOT keep its own
 * hand-typed copy of the backfill SQL to run — it EXTRACTS the literal
 * statement out of the real `migrate-backfill-song-external-ids.php`
 * source (see `tsebExtractIsrcSql()`) and executes exactly that. A
 * hand-typed copy would pass even after someone changed the real file's
 * `INSERT IGNORE` to a plain `INSERT` — extracting the real statement is
 * what makes the mutation in deliverable 5(a) ("break INSERT IGNORE ->
 * plain INSERT") actually turn this file red, rather than only turning red
 * a parallel copy that happens to agree with itself.
 *
 * Runs entirely inside ONE transaction that is ROLLED BACK at the very
 * end, so the live fixture database this suite runs against (dev/CI, never
 * production — see `tools/run-php-tests.php`) is left byte-for-byte as it
 * was found, even though a real row is inserted, duplicate-checked, and
 * deleted along the way.
 *
 * CONNECTION: mirrors `tests/php/test-schema-installs.php`'s
 * `IHYMNS_TEST_DSN` parse (host/user/pass/socket) for the SERVER
 * connection, then resolves which DATABASE to select from
 * `appWeb/.auth/db_credentials.php` (this repo's one source of truth for
 * "which app DB", per CLAUDE.md rule #5) — falling back to an explicit
 * `dbname=`/`db=` component on `IHYMNS_TEST_DSN` for a CI runner that sets
 * one without a credentials file. This suite needs the REAL, already-
 * migrated app schema (existing `tblSongs` rows to borrow, real FKs) to
 * exercise the backfill SQL behaviourally — unlike
 * `test-schema-installs.php`'s from-scratch scratch database, there is no
 * substitute for real fixture rows here. Neither a DSN dbname nor
 * credentials available, or the target tables aren't migrated yet =>
 * SKIP the behavioural half (exit 0, not a failure — "not run" is
 * distinct from "failed", matching `test-intapps-stub-e2e.php`'s
 * documented convention).
 *
 * MUTATION-TESTING NOTE (rule #34)
 * ---------------------------------
 * The three specific mutations named in the #1747 D5 task brief were
 * verified by hand against this file during implementation (cp backup /
 * restore on the real tree, never `git checkout`, matching the required
 * protocol) rather than re-implemented as an in-memory self-test here:
 *   (a) `migrate-backfill-song-external-ids.php`'s extracted ISRC
 *       `INSERT IGNORE` changed to a plain `INSERT` -> this file's
 *       "re-running does not create a duplicate" assertion goes red
 *       (the second run throws a duplicate-key `mysqli_sql_exception`,
 *       caught and reported as a failure rather than crashing the suite).
 *   (b) `genius` removed from `RECORDING_EXTERNAL_ID_TYPES` -> this file's
 *       "genius is a recognised recording IdType" source assertion goes red.
 *   (c) the registry probe hardcoded to `=> true` -> the EXISTING
 *       project-wide guard `tests/php/test-migration-registry.php` goes
 *       red (its regex bans that literal for every entry, not just this
 *       one); this file's OWN narrower "not => true" source assertion
 *       (checked against just this entry's slice of the registry source)
 *       independently goes red too.
 * An in-memory fake of "did the real INSERT IGNORE run twice without
 * duplicating" cannot prove anything about real MySQL unique-key semantics
 * — only a real database round trip can, which is why (a) is proven via
 * the SQL-extraction design above rather than a parallel pure-function
 * self-test the way `test-media-identifiers.php`'s vocabulary diff is.
 *
 *   php tests/php/test-song-external-ids-backfill.php
 *
 * Exit status 0 = all assertions passed (or the behavioural half was
 * skipped for lack of a database), 1 = at least one failure.
 *
 * @see appWeb/public_html/includes/media_identifiers.php        RECORDING_EXTERNAL_ID_TYPES['genius']
 * @see appWeb/.sql/migrate-backfill-song-external-ids.php        the script this file exercises
 * @see appWeb/public_html/manage/includes/migration-registry.php the 'backfill-song-external-ids' entry
 * @see tests/php/test-schema-installs.php                        the IHYMNS_TEST_DSN parse idiom this mirrors
 * @see tests/php/test-migration-registry.php                     the project-wide "no => true probe" guard
 */

$repoRoot = dirname(__DIR__, 2);

$failures = 0;
function ok(string $label, bool $cond, string $detail = ''): void
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

echo "1 — source checks (vocabulary + migration script + registry entry)\n";

/* ---- genius is a recognised, correctly-scoped, numeric recording IdType --- */
$mediaIdFile = $repoRoot . '/appWeb/public_html/includes/media_identifiers.php';
if (!is_readable($mediaIdFile)) {
    fwrite(STDERR, "FATAL: could not read $mediaIdFile\n");
    exit(1);
}
$_SERVER['SCRIPT_FILENAME'] = 'test-runner'; // bypass the file's direct-HTTP-access guard
require_once $mediaIdFile;

ok('genius is a recognised RECORDING_EXTERNAL_ID_TYPES IdType',
   function_exists('mediaIdentifierIdTypeValid') && mediaIdentifierIdTypeValid('genius'));

ok("genius's declared scope is 'recording'",
   function_exists('mediaIdentifierScopeForType') && mediaIdentifierScopeForType('genius') === 'recording');

ok('genius validates a numeric id and rejects a non-numeric one',
   function_exists('mediaIdentifierValidateValue')
       && mediaIdentifierValidateValue('genius', '12345') === true
       && mediaIdentifierValidateValue('genius', 'not-a-number') === false);

$geniusDef = mediaIdentifierRecordingTypes()['genius'] ?? null;
/* NOTE: deliberately array_key_exists(), never `$geniusDef['url'] ?? 'MISSING'`
   — `??` cannot distinguish "key absent" from "key present but null", and
   url === null here is the CORRECT, intentional value being asserted, not
   an absence. Using `??` would have silently substituted 'MISSING' for a
   genuinely-null url and always failed this assertion. */
ok("genius has url === null (the D5 'do NOT invent' posture — no confirmed bare-id deep-link)",
   is_array($geniusDef) && array_key_exists('url', $geniusDef) && $geniusDef['url'] === null);

/* ---- the migration script mentions all 5 origin columns + uses INSERT IGNORE --- */
$migrationFile = $repoRoot . '/appWeb/.sql/migrate-backfill-song-external-ids.php';
if (!is_readable($migrationFile)) {
    fwrite(STDERR, "FATAL: could not read $migrationFile\n");
    exit(1);
}
$migrationSrc = (string)file_get_contents($migrationFile);

$expectedSources = [
    'tblSongs.Isrc'                                => '/\bFROM\s+tblSongs\b/i',
    'tblSongIdentityMap.MusicBrainzRecordingMBID'  => '/MusicBrainzRecordingMBID/i',
    'tblSongIdentityMap.SpotifyTrackId'            => '/SpotifyTrackId/i',
    'tblSongIdentityMap.GeniusTrackId'             => '/GeniusTrackId/i',
    'tblSongIdentityMap.IsrcCode'                  => '/IsrcCode/i',
];
foreach ($expectedSources as $label => $pattern) {
    ok("migration script references {$label}", (bool)preg_match($pattern, $migrationSrc));
}

$insertIgnoreCount = preg_match_all('/INSERT\s+IGNORE\s+INTO\s+tblSongExternalIds/i', $migrationSrc);
ok('migration script uses INSERT IGNORE for all 5 backfill statements',
   $insertIgnoreCount === 5,
   "found {$insertIgnoreCount} INSERT IGNORE statement(s), expected 5");

/* Strip PHP comments (/* … *\/ and // …) before checking for a real
   `CREATE TABLE` STATEMENT — the file's own doc-block legitimately
   discusses "no CREATE TABLE" in prose (explaining why this migration is
   data-only), and matching raw source would false-positive on that
   sentence, exactly the class of mistake CLAUDE.md rule #30's fragment
   scanner already had to guard against ("strips HTML and PHP block
   comments first so <script> *mentioned* in a comment doesn't
   false-positive"). */
$migrationSrcNoComments = preg_replace('#/\*.*?\*/#s', '', $migrationSrc) ?? $migrationSrc;
$migrationSrcNoComments = preg_replace('#//[^\n]*#', '', $migrationSrcNoComments) ?? $migrationSrcNoComments;
ok('migration script does NOT create the target table itself (that stays migrate-song-external-ids.php\'s job)',
   !preg_match('/CREATE\s+TABLE/i', $migrationSrcNoComments));

/* ---- the registry entry's probe is a real function, never the banned
   `=> true` literal (tests/php/test-migration-registry.php already guards
   this project-wide; this is a second, entry-scoped assertion). ---- */
$registryFile = $repoRoot . '/appWeb/public_html/manage/includes/migration-registry.php';
if (!is_readable($registryFile)) {
    fwrite(STDERR, "FATAL: could not read $registryFile\n");
    exit(1);
}
$registrySrc = (string)file_get_contents($registryFile);

/**
 * PURE core: does $entrySrc (one registry entry's source slice) look like
 * the banned always-true probe literal? Shared by the real assertion and
 * its FAILS-HIGH mutation self-test below (rule #34).
 */
function tsebLooksAlwaysTrueProbe(string $entrySrc): bool
{
    return (bool)preg_match(
        "/'probe'\s*=>\s*static\s+fn\s*\(\s*\\\\?mysqli\s+\\\$\w+\s*\)\s*=>\s*true\s*,/m",
        $entrySrc
    );
}

if (!preg_match(
    "/'backfill-song-external-ids'\s*=>\s*\[(.*?)\n    \],\n\n    'work-bowi'/s",
    $registrySrc,
    $entryMatch
)) {
    ok("registry has a 'backfill-song-external-ids' entry (extractable slice)", false,
       'could not locate the entry block between its own key and the following work-bowi entry — '
       . 'did the entry get renamed, reordered, or is it now the last entry in the file?');
    $backfillEntrySrc = '';
} else {
    ok("registry has a 'backfill-song-external-ids' entry (extractable slice)", true);
    $backfillEntrySrc = $entryMatch[1];
}

if ($backfillEntrySrc !== '') {
    ok("'backfill-song-external-ids' probe is NOT the banned always-true literal",
       !tsebLooksAlwaysTrueProbe($backfillEntrySrc));
    ok("'backfill-song-external-ids' probe is the real smart-probe shape (a multi-line closure body)",
       (bool)preg_match('/static\s+function\s*\(\s*\\\\?mysqli\s+\$db\s*\)\s*:\s*bool\s*\{/', $backfillEntrySrc));
    ok("'backfill-song-external-ids' is ordered AFTER 'song-external-ids' (rule #19 — target table must exist first)",
       (bool)preg_match("/'song-external-ids'\s*=>\s*\[.*?'backfill-song-external-ids'\s*=>\s*\[/s", $registrySrc));
}

/* ---- FAILS-HIGH mutation self-test (rule #34) for tsebLooksAlwaysTrueProbe():
   a guard that has never been proven able to fail is not trustworthy. Runs
   entirely in memory against a fabricated string, never the real file. ---- */
$bogusAlwaysTrueEntry = "\n        'script' => 'x.php',\n        'probe' => static fn(\\mysqli \$db) => true,\n    ";
ok('tsebLooksAlwaysTrueProbe() mutation self-test: detects a fabricated always-true probe',
   tsebLooksAlwaysTrueProbe($bogusAlwaysTrueEntry));
ok('tsebLooksAlwaysTrueProbe() mutation self-test: does NOT flag a real smart-probe shape',
   !tsebLooksAlwaysTrueProbe("\n        'probe' => static function (\\mysqli \$db): bool {\n            return true;\n        },\n    "));

/* ========================================================================
 * BEHAVIOURAL CHECKS — skipped (not failed) when no database is reachable.
 * ==================================================================== */

echo "\n2 — behavioural checks (real INSERT IGNORE idempotency, rolled back)\n";

/* ---- Resolve connection.
   PRECEDENCE (deliberately in this order — see the long note below):
     1. appWeb/.auth/db_credentials.php, if present — its OWN
        DB_HOST/DB_USER/DB_PASS/DB_PORT/DB_NAME, exactly as every
        migration script in this repo connects (TCP, never a socket).
     2. Otherwise, the IHYMNS_TEST_DSN idiom (test-schema-installs.php
        ~line 203) for host/user/pass/socket, PLUS an explicit
        `dbname=`/`db=` component — required here (unlike
        test-schema-installs.php, which builds its OWN scratch database
        and so needs no name) because this suite needs a database that
        ALREADY has the real migrated schema and real `tblSongs` rows to
        borrow, which a bare DSN cannot supply on its own.
     3. Neither available => SKIP.

   WHY CREDENTIALS-FILE TAKES PRIORITY OVER THE SOCKET FALLBACK, NOT THE
   OTHER WAY AROUND: MySQL grants are host-scoped, and a Unix-socket
   connection authenticates as the CLIENT HOST `localhost`, which is a
   DIFFERENT grant entry than the TCP host `db_credentials.php` actually
   provisions its user for (typically `127.0.0.1` or `%`). Trying the
   local socket fallback with `db_credentials.php`'s app-specific
   user/pass produced exactly that mismatch during this file's own
   development — "Access denied for user 'ihymns'@'localhost'" — even
   though the SAME credentials connect fine over TCP to `DB_HOST`. The
   socket fallback is only meaningful for a generic root/no-password local
   MySQL (test-schema-installs.php's own scratch-DB use case), so it is
   scoped here to the "no db_credentials.php" branch, never layered on
   top of app credentials that were provisioned for a different host. */
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
$behaviouralRan = false; // flips true only once the real transaction/extraction logic below actually executes
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
    echo "  SKIP  no application database reachable (no db_credentials.php / no dbname on IHYMNS_TEST_DSN,\n";
    echo "        or the connection failed) — the behavioural half did not run. Set IHYMNS_TEST_DSN and/or\n";
    echo "        configure appWeb/.auth/db_credentials.php to exercise it.\n";
} else {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    try {
        $haveSongs      = (bool)$db->query("SHOW TABLES LIKE 'tblSongs'")->num_rows;
        $haveExternal   = (bool)$db->query("SHOW TABLES LIKE 'tblSongExternalIds'")->num_rows;
    } catch (\Throwable $e) {
        $haveSongs = $haveExternal = false;
    }

    if (!$haveSongs || !$haveExternal) {
        echo "  SKIP  tblSongs and/or tblSongExternalIds do not exist on this database yet — run the\n";
        echo "        'song-external-ids' migration card first. The behavioural half did not run.\n";
        $db->close();
    } else {
        /* ---- Extract the REAL ISRC INSERT IGNORE statement out of the
           migration script — never a hand-typed copy (see file doc-block
           for why this is load-bearing for the deliverable-5 mutation). ---- */
        $isrcSql = tsebExtractIsrcSql($migrationSrc);
        if ($isrcSql === null) {
            ok('could not extract the ISRC backfill statement from migrate-backfill-song-external-ids.php', false,
               'the FROM tblSongs INSERT IGNORE block was not found in the expected shape');
        } else {
            $behaviouralRan = true;
            $pid = getmypid() ?: random_int(1, 9999999);
            $testIsrc = sprintf('ZZTEST%07d', $pid % 10000000); // <=15 chars, fits tblSongs.Isrc VARCHAR(15)
            /* Declared up front (not just inside the try) so the post-rollback
               block below can safely `isset()`-guard on them even if the try
               throws before reaching the line that assigns them — e.g. a
               (theoretical) empty tblSongs table. */
            $testSongId = null;
            $probeFn = null;
            $baselinePending = null;

            $db->begin_transaction();
            try {
                $row = $db->query('SELECT SongId, Isrc FROM tblSongs ORDER BY Id LIMIT 1')->fetch_assoc();
                if (!$row) {
                    throw new \RuntimeException('tblSongs has zero rows — nothing to borrow for the test');
                }
                $testSongId = (string)$row['SongId'];

                $registrySrcForRequire = $registryFile;
                $MIGRATIONS = tsebLoadRegistryForProbe($registrySrcForRequire, $db);
                $probeFn = $MIGRATIONS['backfill-song-external-ids']['probe'] ?? null;
                $baselinePending = $probeFn instanceof \Closure ? $probeFn($db) : null;

                $stmt = $db->prepare('UPDATE tblSongs SET Isrc = ? WHERE SongId = ?');
                $stmt->bind_param('ss', $testIsrc, $testSongId);
                $stmt->execute();
                $stmt->close();

                if ($probeFn instanceof \Closure) {
                    ok('registry probe reports PENDING once an unmirrored ISRC exists (monotonic — true regardless of surrounding data)',
                       $probeFn($db) === true);
                }

                /* ---- Run 1: the real extracted statement ---- */
                $db->query($isrcSql);

                /* A fresh prepare()/close() pair per read — reusing one
                   mysqli_stmt across both reads by only re-execute()-ing it
                   was tried first and closed it out from under itself
                   (`get_result()` detaches the result but a second
                   `execute()` on an already-`close()`d statement throws) —
                   simplest correct fix is "don't share the statement". */
                $countIsrcRow = static function () use ($db, $testSongId, $testIsrc): array {
                    $stmt = $db->prepare(
                        "SELECT IdScope, Source FROM tblSongExternalIds
                          WHERE SongId = ? AND IdType = 'isrc' AND IdValue = ?"
                    );
                    $stmt->bind_param('ss', $testSongId, $testIsrc);
                    $stmt->execute();
                    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                    $stmt->close();
                    return $rows;
                };

                $rows = $countIsrcRow();

                ok('after 1 run: exactly one tblSongExternalIds row was created for the test ISRC',
                   count($rows) === 1, 'found ' . count($rows) . ' row(s)');
                if (count($rows) === 1) {
                    ok("the row's IdScope is 'recording'", $rows[0]['IdScope'] === 'recording');
                    ok("the row's Source is 'ihymns-backfill'", $rows[0]['Source'] === 'ihymns-backfill');
                }

                if ($probeFn instanceof \Closure && $baselinePending === false) {
                    ok('registry probe reports NOT PENDING once the (only) unmirrored row was mirrored',
                       $probeFn($db) === false);
                }

                /* ---- Run 2: the SAME real statement again — idempotency ---- */
                try {
                    $db->query($isrcSql);
                    $rowsAfterSecond = $countIsrcRow();
                    ok('after running the SAME statement a 2nd time: still exactly one row (uq_Song_Type_Value held, no duplicate)',
                       count($rowsAfterSecond) === 1, 'found ' . count($rowsAfterSecond) . ' row(s)');
                } catch (\Throwable $e) {
                    ok('after running the SAME statement a 2nd time: still exactly one row (uq_Song_Type_Value held, no duplicate)',
                       false, '2nd run THREW instead of silently no-op-ing: ' . $e->getMessage()
                            . ' — the statement is not idempotent (check it really uses INSERT IGNORE)');
                }
            } catch (\Throwable $e) {
                ok('behavioural setup/run completed without an unexpected exception', false, $e->getMessage());
            } finally {
                $db->rollback();
            }

            /* ---- After rollback: the row must be gone and the DB state
               (including what the probe reports) restored to baseline.
               Guarded on $testSongId !== null: only unset if the try above
               threw before ever picking a song (already reported as a
               failure above), in which case there's nothing to check here. ---- */
            if ($testSongId !== null) {
                $goneCheck = $db->prepare(
                    "SELECT 1 FROM tblSongExternalIds WHERE SongId = ? AND IdType = 'isrc' AND IdValue = ?"
                );
                $goneCheck->bind_param('ss', $testSongId, $testIsrc);
                $goneCheck->execute();
                $stillThere = $goneCheck->get_result()->fetch_row() !== null;
                $goneCheck->close();
                ok('after rollback: the test row is gone (transaction cleanly undone, live fixture untouched)',
                   !$stillThere);

                if ($probeFn instanceof \Closure && $baselinePending !== null) {
                    ok('after rollback: registry probe result is restored to its pre-test baseline',
                       $probeFn($db) === $baselinePending);
                }
            }
        }
        $db->close();
    }
}

/* ========================================================================
 * Helpers used by the behavioural section (kept below the main flow so the
 * narrative above reads top-to-bottom).
 * ==================================================================== */

/**
 * Extract the literal ISRC `INSERT IGNORE ... FROM tblSongs ...` statement
 * out of the migration script's source text. Returns null if the expected
 * shape isn't found (e.g. the migration was restructured).
 *
 * ELI5: "copy the exact SQL sentence out of the real file, word for word."
 *
 * DETAILED: matches specifically the block that reads `FROM tblSongs` (the
 * tblSongs.Isrc source) rather than any of the four `FROM tblSongIdentityMap`
 * blocks, all of which live inside the SAME kind of `$mysql->query("...")`
 * call in the same file. The migration's SQL string literals use ONLY
 * single quotes internally (never a double quote), so a `"([^"]*)"`
 * capture safely spans the whole multi-line PHP string without truncating
 * early — the same assumption `test-schema-installs.php` makes about
 * schema.sql's statements.
 */
function tsebExtractIsrcSql(string $migrationSrc): ?string
{
    if (!preg_match(
        '/\$mysql->query\(\s*"(INSERT\s+IGNORE\s+INTO\s+tblSongExternalIds[^"]*?FROM\s+tblSongs\b[^"]*)"\s*\)/s',
        $migrationSrc,
        $m
    )) {
        return null;
    }
    return $m[1];
}

/**
 * Load migration-registry.php's $MIGRATIONS array with REAL helper
 * functions wired to a REAL mysqli connection, so this test's probe
 * assertions exercise the actual live-schema logic — not a stub that
 * always returns the same canned answer (which is what
 * test-migration-registry.php's OWN stubs deliberately do, because that
 * test only checks shape, never behaviour, of the probes; this test needs
 * behaviour). Only `_migProbe_tableExists` is real (the only helper the
 * 'backfill-song-external-ids' probe calls); the others are lazy-safe
 * stubs mirroring test-migration-registry.php's set, since no OTHER
 * entry's closure is ever invoked here.
 */
function tsebLoadRegistryForProbe(string $registryFile, \mysqli $db): array
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
    if (!function_exists('_migProbe_columnIsNullable'))  { function _migProbe_columnIsNullable(\mysqli $db, string $t, string $c): bool { return false; } }
    if (!function_exists('_migProbe_triggerExists'))     { function _migProbe_triggerExists(\mysqli $db, string $t): bool { return false; } }
    if (!function_exists('_migProbe_columnDataType'))    { function _migProbe_columnDataType(\mysqli $db, string $t, string $c): ?string { return null; } }

    global $hasCredentials;
    $hasCredentials = true;
    $_SERVER['SCRIPT_FILENAME'] = 'test-runner'; // bypass the registry's own direct-access guard

    $loaded = require $registryFile;
    return is_array($loaded) ? $loaded : [];
}

/* ========================================================================
 * REPORT
 * ==================================================================== */

echo "\n";
if ($failures > 0) {
    fwrite(STDERR, "FAIL: {$failures} assertion(s) failed in the #1747 D5 backfill guard.\n");
    exit(1);
}
echo "PASS: Song external-IDs backfill guard — all source checks passed"
   . ($behaviouralRan ? ', including the live behavioural round trip.' : ' (behavioural half skipped — see SKIP line above).')
   . "\n";
exit(0);
