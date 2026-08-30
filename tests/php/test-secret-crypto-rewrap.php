<?php

declare(strict_types=1);

/**
 * iHymns — table-held secret rewrap guard (#1989)
 * =========================================================================
 *
 * ELI5
 * ----
 * `secretEncryptInPlace()`/`secretRotateReencrypt()` (the "lock everything"
 * and "swap to a new key" jobs, `includes/secret_crypto_admin.php`) used to
 * only know about the ~15 secrets that live as ROWS in `tblAppSettings`. This
 * file proves the #1989 fix that taught them about the ONE secret that
 * instead lives as a COLUMN — `tblWebhookSubscriptions.Secret`/
 * `.SecretPrevious`, the outbound-webhook HMAC signing secret — without
 * ever risking the one mistake that would matter: re-encrypting an
 * already-enveloped value (a "double encrypt"), which would silently corrupt
 * every future outgoing webhook signature with no error anywhere.
 *
 * WHAT THIS FILE DOES (four independent layers — rule #34's "derive, then
 * prove it can fail")
 * ---------------------------------------------------------------------
 *   1. REGISTRY ↔ SCHEMA LOCKSTEP (DB-free): every table/pk/column
 *      `secretTableSecretColumns()` names is DERIVED from the registry
 *      itself (never hardcoded here) and checked against a REAL
 *      `CREATE TABLE` block parsed out of `appWeb/.sql/schema.sql` — so a
 *      rename on either side breaks this test, not silently drifts (rule #35).
 *   2. PURE DECISION TRUTH TABLE (DB-free): `secretTableRewrapDecision()`
 *      never touches a database — every cell of its 2-mode × 6-shape matrix
 *      is asserted directly, including the two cells that matter most: an
 *      ALREADY-ENVELOPED value in 'encrypt' mode must always be
 *      `skip_enveloped` (the double-encryption guard), and a value under the
 *      CURRENTLY ACTIVE key in 'rotate' mode must always be `skip_current`
 *      (the "don't touch what's already right" guard).
 *   3. STRUCTURAL WIRING (DB-free, comment-stripped): using the shared,
 *      independently-tested `tests/php/lib/php_source_units.php` (never a
 *      hand-rolled parser here — rule #22 applies to test infrastructure
 *      too), asserts (a) `secretEncryptInPlace()` actually calls the walker
 *      with `'encrypt'`, (b) `secretRotateReencrypt()` calls it with
 *      `'rotate'`, (c) the walker itself calls the registry AND the pure
 *      decision function AND its SELECT carries `FOR UPDATE`, (d) the
 *      encrypt-in-place call sits BEFORE the `SECRET_ENC_ACTIVE_KEY` flag
 *      write (so "flag set means EVERYTHING is ciphertext" stays true), and
 *      (e) the migration's re-runnability fix (D.1) actually ANDs the
 *      settings-side AND table-side legacy counts, not just the former.
 *      Because `phpSourceUnits()` erases comments before rendering its `code`
 *      view, a mention inside a comment can never satisfy any of the above —
 *      proven live below by a small self-test fixture (never trusted by
 *      assertion alone).
 *   4. LIVE-DB BEHAVIOURAL (SKIPS LOUDLY without a reachable database — the
 *      #1701 rule: a skip must never read as a pass). Builds its own scratch
 *      database (mirrors `tests/php/test-schema-installs.php`'s pattern —
 *      needs no pre-existing `dbname`, just host/user/pass, so it runs
 *      wherever CI's `IHYMNS_TEST_DSN` already points `tools/run-php-tests.php`
 *      at the provisioned MariaDB service, see `.github/workflows/test.yml`'s
 *      "All PHP test suites" step). Seeds 5 rows (plaintext, enveloped under
 *      an OLDER key, enveloped under the CURRENT key, a second plaintext, and
 *      a MALFORMED envelope) into a minimal FK-free clone of the real table,
 *      runs `secretEncryptInPlace()` then `secretRotateReencrypt()` for real
 *      against a real `\mysqli` transaction, and asserts: every recoverable
 *      value round-trips back to its ORIGINAL plaintext (never comparing raw
 *      ciphertext bytes, which change every encryption because of the random
 *      per-value nonce — see `secret_crypto.php`'s envelope format doc), every
 *      recoverable envelope's keyid ends at the active key, the row ALREADY
 *      under the active key received byte-IDENTICAL storage (proving a true
 *      no-op, not just a same-plaintext rewrite), the malformed row is
 *      reported `undecryptable` and left byte-IDENTICAL (never silently
 *      dropped, never overwritten), a SECOND full run makes zero further
 *      writes anywhere, and no audit-log call anywhere in the whole run ever
 *      carries a secret value. It ALSO runs both functions against a scratch
 *      database that has `tblAppSettings` but NO `tblWebhookSubscriptions`
 *      table at all, proving the un-migrated-install path is a clean no-op
 *      under `MYSQLI_REPORT_STRICT` rather than a fatal.
 *
 * MUTATION-TESTING PROTOCOL (rule #34 — a guard must be proven able to fail)
 * ---------------------------------------------------------------------
 * Layers 1–3 (all DB-free) were each proven RED by the author against a
 * SCRATCH COPY of `includes/secret_crypto_admin.php` (never the working
 * tree — this repo's own standing instruction is to leave `git status` clean;
 * the mutated copy lived only in a throwaway temp directory, verified via a
 * small standalone harness that `require`s the mutated copy and re-runs the
 * SAME assertion logic this file uses), then restored and reconfirmed against
 * the real file:
 *
 *   - Removed the `_secretAdminRewrapTableSecrets($db, 'rotate', ...)` line
 *     from a scratch copy's `secretRotateReencrypt()` → assertion 3b (the
 *     "rotate calls the walker with 'rotate'" regex match) went RED. Restored
 *     → GREEN.
 *   - Removed `'SecretPrevious'` from a scratch copy's
 *     `secretTableSecretColumns()` → assertion 1's
 *     "declares a 'SecretPrevious' column" check went RED (the registry no
 *     longer reports it, so the per-column loop never even looks for it —
 *     caught instead by the companion assertion that the registry's column
 *     LIST still contains 'SecretPrevious', which read straight off the
 *     mutated registry and found it missing). Restored → GREEN.
 *   - Changed the 'encrypt'-mode branch of a scratch copy's
 *     `secretTableRewrapDecision()` from `secretIsEncrypted($stored) ?
 *     'skip_enveloped' : 'encrypt'` to the INVERTED
 *     `!secretIsEncrypted($stored) ? 'skip_enveloped' : 'encrypt'` (the exact
 *     double-encryption shape risk #1 warns about — an enveloped value would
 *     now be re-"encrypted") → the truth-table assertion
 *     "decision(envK2, 'encrypt') === 'skip_enveloped'" went RED (it returned
 *     `'encrypt'` instead — precisely the double-encrypt bug this whole
 *     feature exists to prevent). Restored → GREEN.
 *   - The live-DB layer (4) could not be mutation-proven by ACTUALLY
 *     executing a mutated build against a real database in the environment
 *     this guard was authored in (no local MySQL/MariaDB server, no
 *     container runtime available) — its correctness is instead argued by
 *     construction from the SAME source the structural layer (3) already
 *     proved wiring for, and by the fact that layers 1–3 already pin the
 *     exact code path layer 4 exercises. This is a real, stated gap, not a
 *     silent one: whoever next runs this suite with `IHYMNS_TEST_DSN` set
 *     (CI already does — see `test.yml`) gets the full live round-trip;
 *     anyone reproducing the mutation proof above with a database reachable
 *     should also re-run layer 4 against each mutation and can extend this
 *     note.
 *   - The comment-not-counted self-test in layer 3 is a LIVE fixture check
 *     (not a one-time manual proof) — it runs on every invocation and
 *     independently proves both directions: a call mentioned only inside a
 *     `//` comment does NOT appear in `phpSourceUnits()`'s `code` view
 *     (fails-low control), and a genuine call DOES (fails-high control).
 *
 * Usage:
 *   php tests/php/test-secret-crypto-rewrap.php
 *   IHYMNS_TEST_DSN='host=127.0.0.1;user=root;pass=' php tests/php/test-secret-crypto-rewrap.php
 *
 * Exit status 0 = clean, 1 = at least one failure.
 *
 * @see appWeb/public_html/includes/secret_crypto_admin.php  secretTableSecretColumns() / secretTableRewrapDecision() / _secretAdminRewrapTableSecrets() / secretTableSecretInventory() / _secretAdminTableExists()
 * @see appWeb/.sql/migrate-secret-encrypt-inplace.php        the D.1 re-runnability fix
 * @see tests/php/test-webhook-secret-show-once.php            the sibling guard whose mutation-testing NARRATIVE convention this file follows
 * @see tests/php/test-schema-installs.php                     the sibling guard whose LIVE-DB scratch-database CONNECTION idiom this file follows
 * @see tests/php/lib/php_source_units.php                     the shared, independently-tested source-unit splitter section 3 delegates to
 */

require_once __DIR__ . '/lib/php_source_units.php';

$repo          = dirname(__DIR__, 2);
$appRoot       = $repo . '/appWeb';
$adminFile     = $appRoot . '/public_html/includes/secret_crypto_admin.php';
$schemaFile    = $appRoot . '/.sql/schema.sql';
$migrationFile = $appRoot . '/.sql/migrate-secret-encrypt-inplace.php';

$adminSrc     = (string)file_get_contents($adminFile);
$schemaSrc    = (string)file_get_contents($schemaFile);
$migrationSrc = (string)file_get_contents($migrationFile);

$passed = 0;
$failed = 0;
$failures = [];

function ok(string $label, bool $cond): void
{
    global $passed, $failed, $failures;
    if ($cond) { $passed++; echo "  ✅ {$label}\n"; }
    else { $failed++; $failures[] = $label; echo "  ❌ {$label}\n"; }
}

/**
 * Comment-stripping helper — the SAME tests-only duplication convention
 * `test-webhook-secret-show-once.php` documents (rule #22 note: a
 * single-process-per-file test convention, not the app's own modularity
 * rule, which governs application code). Used only for the migration
 * file's file-scope idempotency check below — the `secret_crypto_admin.php`
 * checks all go through `phpSourceUnits()`, which comment-strips internally.
 */
function stripPhpComments(string $src): string
{
    $wrapped = (strpos(ltrim($src), '<?php') === 0) ? $src : ("<?php\n" . $src);
    $toks = @token_get_all($wrapped);
    if (!is_array($toks)) { return $src; }
    $out = '';
    foreach ($toks as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) { continue; }
        $out .= is_array($t) ? $t[1] : $t;
    }
    return $out;
}

require_once $adminFile;

echo "\nTable-held secret rewrap guard (#1989)\n\n";

/* =========================================================================
 * LAYER 1 — Registry ↔ schema.sql lockstep (DB-free).
 * Every fact is DERIVED from secretTableSecretColumns() itself — never
 * hardcoded here — so a future SECOND registry entry (rule #20's "one more
 * array line") is covered automatically, and a rename on either side of the
 * registry/schema boundary breaks this test rather than drifting silently
 * (rule #35).
 * ========================================================================= */
echo "1 — secretTableSecretColumns() registry matches schema.sql byte-for-byte\n";

$registry = secretTableSecretColumns();
ok('registry has exactly one entry today (#1989 ships with ONE table-held secret)',
    count($registry) === 1);
ok("registry's one entry names table 'tblWebhookSubscriptions'",
    ($registry[0]['table'] ?? null) === 'tblWebhookSubscriptions');
ok("registry's one entry names pk 'Id'",
    ($registry[0]['pk'] ?? null) === 'Id');
ok("registry's one entry's columns are exactly ['Secret', 'SecretPrevious']",
    ($registry[0]['columns'] ?? null) === ['Secret', 'SecretPrevious']);

foreach ($registry as $i => $entry) {
    $table   = (string)($entry['table'] ?? '');
    $pk      = (string)($entry['pk'] ?? '');
    $columns = $entry['columns'] ?? [];

    ok("registry[{$i}].table is a non-empty string", $table !== '');
    ok("registry[{$i}].pk is a non-empty string", $pk !== '');
    ok("registry[{$i}].columns is a non-empty list", is_array($columns) && $columns !== []);

    if ($table === '') { continue; }
    $tableQ = preg_quote($table, '/');
    if (!preg_match('/CREATE TABLE IF NOT EXISTS ' . $tableQ . '\s*\((.*?)\n\)\s*ENGINE/s', $schemaSrc, $m)) {
        ok("schema.sql declares CREATE TABLE {$table} (the block this section then inspects)", false);
        continue;
    }
    $block = $m[1];

    if ($pk !== '') {
        $pkQ = preg_quote($pk, '/');
        ok("schema.sql's {$table} declares '{$pk}' as the PRIMARY KEY",
            (bool)preg_match('/^\s*' . $pkQ . '\s+\S.*PRIMARY KEY/mi', $block));
    }
    foreach ($columns as $col) {
        $colQ = preg_quote((string)$col, '/');
        ok("schema.sql's {$table} declares a '{$col}' column",
            (bool)preg_match('/^\s*' . $colQ . '\s+\S/m', $block));
    }
}

/* =========================================================================
 * LAYER 2 — secretTableRewrapDecision() PURE truth table (DB-free).
 * secretCryptoInjectKeyset() is the P1 engine's test-only hook (no .auth/
 * file, no DB) — used exactly as tests/php/test-secret-crypto-admin.php
 * already does. Real envelopes are built with secretEncrypt() under each
 * keyid so the "already enveloped" cells exercise the REAL envelope shape,
 * not a hand-typed lookalike.
 * ========================================================================= */
echo "\n2 — secretTableRewrapDecision() truth table\n";

/* Build one envelope under 'k1' (the OLDER key), then switch the active key
   to 'k2' for a second envelope AND for the rest of this file — matching
   the spec's ['active'=>'k2','keys'=>['k1'=>..,'k2'=>..]] end state
   (both keys retained, k2 active — the normal "mid-rotation" shape). */
secretCryptoInjectKeyset(['active' => 'k1', 'keys' => ['k1' => str_repeat('a1', 32)]]);
$envK1 = secretEncrypt('whsec_under_k1_' . str_repeat('a', 32));

secretCryptoInjectKeyset(['active' => 'k2', 'keys' => ['k1' => str_repeat('a1', 32), 'k2' => str_repeat('b2', 32)]]);
$envK2 = secretEncrypt('whsec_under_k2_' . str_repeat('b', 32));

$plaintext = 'whsec_' . str_repeat('c', 40);
$malformed = 'enc:v1:junk';       // carries the enc: PREFIX but fails to parse into 4 fields.

/* ---- mode 'encrypt' (secretEncryptInPlace()'s job) ---- */
ok("decision(null, 'encrypt', 'k2') === 'skip_empty'",
    secretTableRewrapDecision(null, 'encrypt', 'k2') === 'skip_empty');
ok("decision('', 'encrypt', 'k2') === 'skip_empty'",
    secretTableRewrapDecision('', 'encrypt', 'k2') === 'skip_empty');
ok("decision(plaintext, 'encrypt', 'k2') === 'encrypt'",
    secretTableRewrapDecision($plaintext, 'encrypt', 'k2') === 'encrypt');
ok("decision(envelope-under-k1, 'encrypt', 'k2') === 'skip_enveloped' — THE double-encryption guard (risk #1)",
    secretTableRewrapDecision($envK1, 'encrypt', 'k2') === 'skip_enveloped');
ok("decision(envelope-under-k2 [the ACTIVE key], 'encrypt', 'k2') === 'skip_enveloped' — same guard, active key too",
    secretTableRewrapDecision($envK2, 'encrypt', 'k2') === 'skip_enveloped');
ok("decision(malformed 'enc:v1:junk', 'encrypt', 'k2') === 'skip_enveloped' — the enc: PREFIX alone is enough, never re-encrypt anything prefix-matched",
    secretTableRewrapDecision($malformed, 'encrypt', 'k2') === 'skip_enveloped');

/* ---- mode 'rotate' (secretRotateReencrypt()'s job), activeKeyId = 'k2' ---- */
ok("decision(null, 'rotate', 'k2') === 'skip_empty'",
    secretTableRewrapDecision(null, 'rotate', 'k2') === 'skip_empty');
ok("decision('', 'rotate', 'k2') === 'skip_empty'",
    secretTableRewrapDecision('', 'rotate', 'k2') === 'skip_empty');
ok("decision(plaintext, 'rotate', 'k2') === 'skip_plaintext' — out of scope for rotation (belongs to encrypt-in-place)",
    secretTableRewrapDecision($plaintext, 'rotate', 'k2') === 'skip_plaintext');
ok("decision(envelope-under-k2 [already active], 'rotate', 'k2') === 'skip_current'",
    secretTableRewrapDecision($envK2, 'rotate', 'k2') === 'skip_current');
ok("decision(envelope-under-k1 [older key], 'rotate', 'k2') === 'rewrap'",
    secretTableRewrapDecision($envK1, 'rotate', 'k2') === 'rewrap');
ok("decision(malformed 'enc:v1:junk', 'rotate', 'k2') === 'rewrap' (the WALKER discovers it's undecryptable on the actual decrypt attempt, not this pure function)",
    secretTableRewrapDecision($malformed, 'rotate', 'k2') === 'rewrap');

/* =========================================================================
 * LAYER 3 — structural wiring (DB-free, comment-stripped via the shared,
 * independently-tested phpSourceUnits() — never a hand-rolled parser here).
 * ========================================================================= */
echo "\n3 — structural wiring (secretEncryptInPlace/secretRotateReencrypt/walker)\n";

$units = phpSourceUnits($adminSrc);

$encBody = $units['secretEncryptInPlace']['code'] ?? '';
ok('secretEncryptInPlace() is a locatable unit', $encBody !== '');
ok("(3a) secretEncryptInPlace() calls _secretAdminRewrapTableSecrets(\$db, 'encrypt', ...)",
    (bool)preg_match('/_secretAdminRewrapTableSecrets\s*\(\s*\$db\s*,\s*\'encrypt\'/', $encBody));

$rotBody = $units['secretRotateReencrypt']['code'] ?? '';
ok('secretRotateReencrypt() is a locatable unit', $rotBody !== '');
ok("(3b) secretRotateReencrypt() calls _secretAdminRewrapTableSecrets(\$db, 'rotate', ...)",
    (bool)preg_match('/_secretAdminRewrapTableSecrets\s*\(\s*\$db\s*,\s*\'rotate\'/', $rotBody));

$walkerBody = $units['_secretAdminRewrapTableSecrets']['code'] ?? '';
ok('_secretAdminRewrapTableSecrets() is a locatable unit', $walkerBody !== '');
ok('(3c) walker calls secretTableSecretColumns(',
    str_contains($walkerBody, 'secretTableSecretColumns('));
ok('(3c) walker calls secretTableRewrapDecision(',
    str_contains($walkerBody, 'secretTableRewrapDecision('));
$walkerSqlOnly = implode(' ', $units['_secretAdminRewrapTableSecrets']['sqlOnly'] ?? []);
ok("(3c) walker's SELECT carries FOR UPDATE (row-lock inside the caller's transaction)",
    stripos($walkerSqlOnly, 'FOR UPDATE') !== false);

/* (3d) FLAG-LAST: the table-secret rewrap call must sit BEFORE the
   SECRET_ENC_ACTIVE_KEY write, so "flag set means EVERYTHING is ciphertext"
   stays a true statement of the WHOLE database, not just tblAppSettings. */
$posWalkerCall = strpos($encBody, '_secretAdminRewrapTableSecrets(');
$posFlagWrite  = strpos($encBody, 'SECRET_ENC_ACTIVE_KEY');
ok('(3d) table-secret rewrap runs BEFORE the SECRET_ENC_ACTIVE_KEY flag write (flag-last invariant)',
    $posWalkerCall !== false && $posFlagWrite !== false && $posWalkerCall < $posFlagWrite);

/* (3e) migration re-runnability (D.1): the idempotency skip must AND the
   settings-side AND table-side legacy counts — checking only the former is
   exactly consequence (a) the spec calls out (a shared-DB install that
   already cut over on settings would refuse to ever re-run this card, so a
   pre-cutover plaintext webhook secret could never be reached). Checked on
   COMMENT-STRIPPED source so a doc-block merely describing this can't
   satisfy it. */
$migStripped = stripPhpComments($migrationSrc);
ok('(3e) migration calls secretTableSecretInventory($db)',
    str_contains($migStripped, 'secretTableSecretInventory($db)'));
ok("(3e) migration's idempotency skip ANDs \$existingInv['legacy']===0 with (int)\$tableInv['legacy']===0",
    (bool)preg_match('/\$existingInv\[\'legacy\'\]\s*===\s*0\s*&&\s*\(int\)\$tableInv\[\'legacy\'\]\s*===\s*0/', $migStripped));

/* ---- Live self-test: phpSourceUnits() cannot be fooled by a comment ---- *
 * Runs EVERY invocation (not a one-time manual proof) — proves BOTH
 * directions on real fixtures, mirroring test-webhook-secret-show-once.php's
 * own "fails-high AND fails-low" self-test shape. */
$commentOnlyFixture = <<<'PHP'
<?php
function realCaller(): void {
    // realCaller() does NOT actually call _secretAdminRewrapTableSecrets( here — only a comment mentions it
    doSomethingElse();
}
PHP;
$commentUnits = phpSourceUnits($commentOnlyFixture);
$commentBody  = $commentUnits['realCaller']['code'] ?? '';
ok('self-test (fails-LOW control): a call mentioned ONLY inside a // comment does NOT appear in phpSourceUnits()\'s code view',
    !str_contains($commentBody, '_secretAdminRewrapTableSecrets('));

$genuineCallFixture = <<<'PHP'
<?php
function realCaller(): void {
    _secretAdminRewrapTableSecrets($db, 'encrypt', $audit, $result);
}
PHP;
$genuineUnits = phpSourceUnits($genuineCallFixture);
$genuineBody  = $genuineUnits['realCaller']['code'] ?? '';
ok('self-test (fails-HIGH control): a genuine call DOES appear in phpSourceUnits()\'s code view',
    str_contains($genuineBody, '_secretAdminRewrapTableSecrets('));

/* =========================================================================
 * LAYER 4 — live-DB behavioural. SKIPS LOUDLY (never silently) when no
 * database is reachable — the #1701 rule: a skip must never read as a pass.
 * Connection idiom mirrors tests/php/test-schema-installs.php: this test
 * builds its OWN scratch database, so it needs only host/user/pass — no
 * pre-existing `dbname` in IHYMNS_TEST_DSN — and therefore runs wherever CI's
 * "All PHP test suites" step already points tools/run-php-tests.php (see
 * .github/workflows/test.yml).
 * ========================================================================= */
echo "\n4 — live-DB behavioural (real \\mysqli transaction, real round-trip)\n";

$dsn  = getenv('IHYMNS_TEST_DSN') ?: '';
$host = '127.0.0.1'; $user = 'root'; $pass = ''; $sock = null; $port = 3306;
if ($dsn !== '') {
    foreach (explode(';', $dsn) as $kv) {
        [$k, $v] = array_pad(explode('=', $kv, 2), 2, '');
        if ($k === 'host')   { $host = $v; }
        if ($k === 'user')   { $user = $v; }
        if ($k === 'pass')   { $pass = $v; }
        if ($k === 'socket') { $sock = $v; }
        if ($k === 'port')   { $port = (int)$v; }
    }
} elseif (file_exists('/var/run/mysqld/mysqld.sock')) {
    $sock = '/var/run/mysqld/mysqld.sock';
}

$liveRan = false;

if ($dsn === '' && $sock === null) {
    echo "  SKIP  IHYMNS_TEST_DSN is not set (and no local socket found) — the LIVE half did not run.\n";
    echo "        Set IHYMNS_TEST_DSN='host=127.0.0.1;user=root;pass=' against a scratch\n";
    echo "        MySQL/MariaDB (CI already does — see test.yml's 'All PHP test suites' step) to\n";
    echo "        exercise the real round-trip. A skip here is NOT a pass (#1701).\n";
} else {
    $db = null;
    try {
        mysqli_report(MYSQLI_REPORT_OFF);   // probing: a failed connect is an answer, not a fatal
        $db = $sock !== null
            ? @new mysqli(null, $user, $pass, '', 0, $sock)
            : @new mysqli($host, $user, $pass, '', $port);
        if ($db->connect_errno) { $db = null; }
    } catch (\Throwable $e) {
        $db = null;
    }

    if ($db === null) {
        echo "  SKIP  IHYMNS_TEST_DSN/socket was set but no MySQL/MariaDB is reachable — the LIVE half did not run.\n";
    } else {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $probe = 'ihymns_secret_rewrap_probe_' . substr(hash('sha256', (string)getmypid() . microtime()), 0, 8);

        try {
            /* ---- 4a: un-migrated install (tblAppSettings only, NO
               tblWebhookSubscriptions) must be a clean no-op, never a fatal
               under MYSQLI_REPORT_STRICT. Its own throwaway scratch DB, torn
               down before the main scenario below so the two never interact. */
            $probePre = $probe . '_pre';
            $db->query("DROP DATABASE IF EXISTS `{$probePre}`");
            $db->query("CREATE DATABASE `{$probePre}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $db->select_db($probePre);
            $db->query('CREATE TABLE tblAppSettings (
                SettingKey VARCHAR(100) NOT NULL PRIMARY KEY,
                SettingValue MEDIUMTEXT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

            secretCryptoInjectKeyset(['active' => 'k2', 'keys' => ['k1' => str_repeat('a1', 32), 'k2' => str_repeat('b2', 32)]]);

            $preThrew = false;
            $preResult = ['encrypted' => []];
            try {
                $preResult = secretEncryptInPlace($db, function (): void {});
            } catch (\Throwable $e) {
                $preThrew = true;
            }
            ok('(4a) un-migrated install (no tblWebhookSubscriptions table): secretEncryptInPlace() does not throw under MYSQLI_REPORT_STRICT',
                !$preThrew);
            $preWebhookLabels = array_filter($preResult['encrypted'] ?? [],
                static fn(string $l): bool => str_starts_with($l, 'tblWebhookSubscriptions#'));
            ok('(4a) un-migrated install: zero webhook-table entries in the result (the table probe cleanly no-ops)',
                $preWebhookLabels === []);

            $preRotThrew = false;
            try {
                secretRotateReencrypt($db, function (): void {});
            } catch (\Throwable $e) {
                $preRotThrew = true;
            }
            ok('(4a) un-migrated install: secretRotateReencrypt() also does not throw',
                !$preRotThrew);

            $db->query("DROP DATABASE IF EXISTS `{$probePre}`");

            /* ---- 4b: the real 5-row scenario ---- */
            $db->query("DROP DATABASE IF EXISTS `{$probe}`");
            $db->query("CREATE DATABASE `{$probe}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $db->select_db($probe);

            $db->query('CREATE TABLE tblAppSettings (
                SettingKey VARCHAR(100) NOT NULL PRIMARY KEY,
                SettingValue MEDIUMTEXT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

            /* FK-free clone of the real shape (Id/Secret/SecretPrevious only —
               everything this feature actually touches; no FKs to satisfy). */
            $db->query('CREATE TABLE tblWebhookSubscriptions (
                Id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                Secret VARCHAR(500) NOT NULL,
                SecretPrevious VARCHAR(500) NULL DEFAULT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

            /* Build fixtures. k1 stays LOADED (retained-during-overlap) while
               k2 is ACTIVE for the rest of this scenario — the normal
               "mid-rotation" shape (secret_crypto.php's documented model). */
            secretCryptoInjectKeyset(['active' => 'k1', 'keys' => ['k1' => str_repeat('a1', 32)]]);
            $origK1Secret = 'whsec_row2_secret_' . str_repeat('a', 24);
            $origK1Prev   = 'whsec_row2_prev_'   . str_repeat('b', 24);
            $envK1Secret  = secretEncrypt($origK1Secret);
            $envK1Prev    = secretEncrypt($origK1Prev);

            secretCryptoInjectKeyset(['active' => 'k2', 'keys' => ['k1' => str_repeat('a1', 32), 'k2' => str_repeat('b2', 32)]]);
            $origK2Secret = 'whsec_row3_secret_' . str_repeat('c', 24);
            $envK2Secret  = secretEncrypt($origK2Secret);

            $origPlain1 = 'whsec_row1_plain_' . str_repeat('d', 24);
            $origPlain2 = 'whsec_row4_plain_' . str_repeat('e', 24);
            $malformedVal = 'enc:v1:junk';

            /* Row order matches the doc-block's "5 rows" description:
               1 plaintext-bridge, 2 enveloped-under-older-key (+ its
               SecretPrevious also under the older key), 3 enveloped-under-
               the-active-key, 4 a second plaintext (NULL SecretPrevious,
               same as 1/3/5 — every row here has a NULL SecretPrevious
               except row 2, which is the one exercising that column at
               all), 5 malformed. */
            $seedRows = [
                1 => ['secret' => $origPlain1,   'prev' => null],
                2 => ['secret' => $envK1Secret,  'prev' => $envK1Prev],
                3 => ['secret' => $envK2Secret,  'prev' => null],
                4 => ['secret' => $origPlain2,   'prev' => null],
                5 => ['secret' => $malformedVal, 'prev' => null],
            ];
            $ids = [];
            foreach ($seedRows as $n => $r) {
                $stmt = $db->prepare('INSERT INTO tblWebhookSubscriptions (Secret, SecretPrevious) VALUES (?, ?)');
                $stmt->bind_param('ss', $r['secret'], $r['prev']);
                $stmt->execute();
                $ids[$n] = $db->insert_id;
                $stmt->close();
            }

            $auditLog = [];
            $audit = function (string $a, string $k, array $d) use (&$auditLog): void {
                $auditLog[] = [$a, $k, $d];
            };

            /* ---- Pass 1: encrypt-in-place ---- */
            $encResult = secretEncryptInPlace($db, $audit);
            $encWebhookEncrypted = array_values(array_filter($encResult['encrypted'],
                static fn(string $l): bool => str_starts_with($l, 'tblWebhookSubscriptions#')));
            $encWebhookSkipped = array_values(array_filter($encResult['skipped'],
                static fn(string $l): bool => str_starts_with($l, 'tblWebhookSubscriptions#')));

            ok('pass 1 (encrypt): exactly row1.Secret and row4.Secret were encrypted (the two plaintext-bridge values)',
                count($encWebhookEncrypted) === 2
                && in_array("tblWebhookSubscriptions#{$ids[1]}.Secret", $encWebhookEncrypted, true)
                && in_array("tblWebhookSubscriptions#{$ids[4]}.Secret", $encWebhookEncrypted, true));
            ok('pass 1 (encrypt): row2.Secret, row2.SecretPrevious, row3.Secret and row5.Secret were all SKIPPED (already enveloped — the double-encryption guard holding on real rows)',
                count($encWebhookSkipped) === 4
                && in_array("tblWebhookSubscriptions#{$ids[2]}.Secret", $encWebhookSkipped, true)
                && in_array("tblWebhookSubscriptions#{$ids[2]}.SecretPrevious", $encWebhookSkipped, true)
                && in_array("tblWebhookSubscriptions#{$ids[3]}.Secret", $encWebhookSkipped, true)
                && in_array("tblWebhookSubscriptions#{$ids[5]}.Secret", $encWebhookSkipped, true));

            /* Row 5 (malformed) MUST be byte-identical after pass 1 — proof the
               double-encryption guard actually held, not just that it was
               labeled correctly. */
            $row5AfterPass1 = $db->query("SELECT Secret FROM tblWebhookSubscriptions WHERE Id = {$ids[5]}")->fetch_row()[0];
            ok('pass 1: row5 (malformed enc:v1:junk) is BYTE-IDENTICAL after the encrypt pass — never touched',
                $row5AfterPass1 === $malformedVal);

            /* ---- Pass 2: rotate ---- */
            $rotResult = secretRotateReencrypt($db, $audit);
            $rotWebhookRewrapped = array_values(array_filter($rotResult['rewrapped'],
                static fn(string $l): bool => str_starts_with($l, 'tblWebhookSubscriptions#')));
            $rotWebhookUndecryptable = array_values(array_filter($rotResult['undecryptable'],
                static fn(string $l): bool => str_starts_with($l, 'tblWebhookSubscriptions#')));
            $rotWebhookSkipped = array_values(array_filter($rotResult['skipped'],
                static fn(string $l): bool => str_starts_with($l, 'tblWebhookSubscriptions#')));

            ok('pass 2 (rotate): exactly row2.Secret and row2.SecretPrevious were rewrapped (the only values under the OLDER key k1)',
                count($rotWebhookRewrapped) === 2
                && in_array("tblWebhookSubscriptions#{$ids[2]}.Secret", $rotWebhookRewrapped, true)
                && in_array("tblWebhookSubscriptions#{$ids[2]}.SecretPrevious", $rotWebhookRewrapped, true));
            ok('pass 2 (rotate): row5.Secret (malformed) is reported undecryptable, never overwritten',
                $rotWebhookUndecryptable === ["tblWebhookSubscriptions#{$ids[5]}.Secret"]);
            ok('pass 2 (rotate): row1.Secret, row3.Secret and row4.Secret are all skip_current (each already under the active key k2)',
                count($rotWebhookSkipped) === 3
                && in_array("tblWebhookSubscriptions#{$ids[1]}.Secret", $rotWebhookSkipped, true)
                && in_array("tblWebhookSubscriptions#{$ids[3]}.Secret", $rotWebhookSkipped, true)
                && in_array("tblWebhookSubscriptions#{$ids[4]}.Secret", $rotWebhookSkipped, true));

            /* ---- Round-trip + keyid assertions (decrypt-compare, NEVER
               ciphertext-byte-compare for a value that was legitimately
               re-encrypted — a fresh random nonce means the bytes DIFFER even
               though the plaintext and keyid are correct). ---- */
            $finalRows = [];
            $res = $db->query('SELECT Id, Secret, SecretPrevious FROM tblWebhookSubscriptions ORDER BY Id');
            while (($r = $res->fetch_assoc()) !== null) { $finalRows[(int)$r['Id']] = $r; }

            $keyidOf = static function (?string $stored): ?string {
                if ($stored === null || !secretIsEncrypted($stored)) { return null; }
                $p = explode(':', substr($stored, strlen('enc:v1:')), 4);
                return count($p) === 4 ? $p[1] : null;
            };

            ok('round-trip: row1.Secret decrypts back to its ORIGINAL plaintext',
                secretDecrypt($finalRows[$ids[1]]['Secret']) === $origPlain1);
            ok('round-trip: row1.Secret is now enveloped under the ACTIVE keyid k2',
                $keyidOf($finalRows[$ids[1]]['Secret']) === 'k2');

            ok('round-trip: row2.Secret decrypts back to its ORIGINAL plaintext (across the rewrap)',
                secretDecrypt($finalRows[$ids[2]]['Secret']) === $origK1Secret);
            ok('round-trip: row2.Secret moved from keyid k1 to k2',
                $keyidOf($finalRows[$ids[2]]['Secret']) === 'k2');
            ok('round-trip: row2.SecretPrevious decrypts back to its ORIGINAL plaintext (across the rewrap)',
                secretDecrypt($finalRows[$ids[2]]['SecretPrevious']) === $origK1Prev);
            ok('round-trip: row2.SecretPrevious moved from keyid k1 to k2',
                $keyidOf($finalRows[$ids[2]]['SecretPrevious']) === 'k2');

            ok('NO-OP proof: row3.Secret (already under k2) is BYTE-IDENTICAL to what was seeded — skip_current made NO update',
                $finalRows[$ids[3]]['Secret'] === $envK2Secret);

            ok('round-trip: row4.Secret decrypts back to its ORIGINAL plaintext',
                secretDecrypt($finalRows[$ids[4]]['Secret']) === $origPlain2);
            ok('round-trip: row4.Secret is now enveloped under the ACTIVE keyid k2',
                $keyidOf($finalRows[$ids[4]]['Secret']) === 'k2');

            ok('undecryptable proof: row5.Secret (malformed) is STILL BYTE-IDENTICAL after both passes — never overwritten',
                $finalRows[$ids[5]]['Secret'] === $malformedVal);

            /* ---- Pass 3: BOTH functions again, on the now-fully-current
               table — must be a whole-database zero-write no-op (byte
               comparison, the strongest form of "nothing changed"). ---- */
            $snapshotBefore = $finalRows;

            $encResult2 = secretEncryptInPlace($db, function (): void {});
            $rotResult2 = secretRotateReencrypt($db, function (): void {});

            $encWebhookEncrypted2 = array_filter($encResult2['encrypted'],
                static fn(string $l): bool => str_starts_with($l, 'tblWebhookSubscriptions#'));
            $rotWebhookRewrapped2 = array_filter($rotResult2['rewrapped'],
                static fn(string $l): bool => str_starts_with($l, 'tblWebhookSubscriptions#'));
            ok('second run: zero webhook rows report encrypted (pass 3 encrypt)',
                $encWebhookEncrypted2 === []);
            ok('second run: zero webhook rows report rewrapped (pass 3 rotate)',
                $rotWebhookRewrapped2 === []);

            $snapshotAfter = [];
            $res2 = $db->query('SELECT Id, Secret, SecretPrevious FROM tblWebhookSubscriptions ORDER BY Id');
            while (($r = $res2->fetch_assoc()) !== null) { $snapshotAfter[(int)$r['Id']] = $r; }
            ok('second run: EVERY row is byte-identical before vs after (a true whole-table zero-write no-op)',
                $snapshotBefore === $snapshotAfter);

            /* ---- No audit-log entry, across the WHOLE run, ever carries a
               secret value (repo rule #6: labels + keyids only). ---- */
            $secretValues = [$origPlain1, $origPlain2, $origK1Secret, $origK1Prev, $envK1Secret, $envK1Prev, $envK2Secret];
            $leaked = false;
            foreach ($auditLog as [$a, $k, $d]) {
                $blob = $a . '|' . $k . '|' . json_encode($d);
                foreach ($secretValues as $sv) {
                    if ($sv !== '' && str_contains($blob, $sv)) { $leaked = true; break 2; }
                }
            }
            ok('audit log: no entry across the whole run carries a secret VALUE (label + keyids only)',
                !$leaked);

            $liveRan = true;
        } finally {
            try { $db->query("DROP DATABASE IF EXISTS `{$probe}`"); } catch (\Throwable $e) { /* best effort */ }
            try { $db->query("DROP DATABASE IF EXISTS `{$probe}_pre`"); } catch (\Throwable $e) { /* best effort */ }
            $db->close();
        }
    }
}

/* =========================================================================
 * REPORT
 * ========================================================================= */
echo "\n";
if ($failed > 0) {
    fwrite(STDERR, "\nFAIL: table-held secret rewrap guard (#1989):\n\n");
    foreach ($failures as $f) { fwrite(STDERR, "  - {$f}\n"); }
    echo "{$passed} passed, {$failed} failed";
    echo $liveRan ? " (live-DB layer 4 RAN)\n" : " (live-DB layer 4 SKIPPED — see above)\n";
    exit(1);
}
echo "{$passed} passed, 0 failed";
echo $liveRan
    ? " — including the full live-DB round-trip (encrypt, rotate, round-trip, no-op-on-current, second-run zero-write, no value leaks).\n"
    : " (live-DB layer 4 SKIPPED — set IHYMNS_TEST_DSN to also exercise the real database round-trip; a skip is not a pass, #1701).\n";
exit(0);
