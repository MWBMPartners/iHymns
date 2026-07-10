<?php

declare(strict_types=1);

/**
 * iHymns — Admin-configurable feature gating registry (#1481 P1)
 *
 * DB-FREE by construction (mirrors tests/php/test-gating-noop.php and
 * tests/php/test-secret-crypto-admin.php): `.github/workflows/test.yml` runs
 * no MySQL service container, so everything here either (a) exercises the
 * PURE merge/collision logic directly with hand-built row arrays — no I/O
 * at all — or (b) relies on the documented degrade path: with no
 * appWeb/.auth/db_credentials.php present, getDbMysqli() throws and
 * _tierCapsUnionAndEmit()'s try/catch degrades to TIER_CAPS alone, so the
 * real production functions run their OFF/un-migrated path with zero
 * stubbing.
 *
 * Covers:
 *   1. tierCapsMergeUnion() — the PURE union/collision/emit-map builder.
 *   2. tierCapKeyCollidesWithCode() — the shared collision test.
 *   3. Dormancy no-op — every re-pointed accessor (tierCapsEffective,
 *      tierCapStorage, tierCapDefault, tierCapColumnKeys, tierCapJsonKeys,
 *      tierCapRead, tierCapEmitInApi) is byte-identical to its pre-#1481
 *      TIER_CAPS-only behaviour when tblGatingCapabilities is absent/empty.
 *   4. Structural guard — ccli_validator.php's capValueForTierAction() key
 *      resolution reads the union (tierCapsEffective()), never the bare
 *      TIER_CAPS constant (source-grep, mirrors test-apple-siwa-sources.php).
 *   5. The migration-registry.php 'gating-registry' probe — multi-object
 *      OR-probe truth table, exercised directly via a reflection-constructed
 *      \mysqli (never connected — the stubbed _migProbe_tableExists() never
 *      touches it) so all four (capsExists, rulesExists) combinations are
 *      proven without a live database.
 *
 *   php tests/php/test-gating-registry.php
 *
 * Exit status 0 = all pass, 1 = at least one failure.
 */

$failures = 0;
$passed   = 0;
function _tgrAssert(bool $cond, string $label): void
{
    global $failures, $passed;
    if ($cond) {
        $passed++;
        echo "PASS: $label\n";
    } else {
        $failures++;
        fwrite(STDERR, "FAIL: $label\n");
    }
}

$root = dirname(__DIR__, 2) . '/appWeb/public_html';

/* Direct-access guards in these includes key on SCRIPT_FILENAME matching
   the file's own basename — under CLI that's this test script, so they
   pass without tripping. No DB connection happens at require time (lazy —
   only on first getDbMysqli() call). */
require_once $root . '/includes/access_tier_validation.php';
require_once $root . '/includes/ccli_validator.php';

/* ------------------------------------------------------------------------ *
 * 1. tierCapsMergeUnion() — PURE union/collision/emit-map builder.
 * ------------------------------------------------------------------------ */

/* (a) Empty input ⇒ union === TIER_CAPS exactly, no emit/collision noise. */
$empty = tierCapsMergeUnion([]);
_tgrAssert($empty['union'] === TIER_CAPS, '(1a) tierCapsMergeUnion([]) union === TIER_CAPS');
_tgrAssert($empty['emit'] === [], '(1a) tierCapsMergeUnion([]) emit map is empty');
_tgrAssert($empty['collisions'] === [], '(1a) tierCapsMergeUnion([]) collisions list is empty');

/* (b) A well-formed new capability is added with the correct 4-tuple shape
   and its own emit flag, WITHOUT disturbing any existing TIER_CAPS entry. */
$oneNew = tierCapsMergeUnion([
    ['CapKey' => 'CanRequestSongs', 'Label' => 'Requests', 'Description' => 'Submit song requests', 'DefaultValue' => 0, 'EmitInApi' => 1],
]);
_tgrAssert(array_key_exists('CanRequestSongs', $oneNew['union']), '(1b) new well-formed CapKey enters the union');
_tgrAssert(
    $oneNew['union']['CanRequestSongs'] === ['Requests', 'Submit song requests', 'json', 0],
    '(1b) new capability gets the same 4-tuple shape [label, description, json, default]'
);
_tgrAssert($oneNew['emit']['CanRequestSongs'] === true, '(1b) EmitInApi=1 row is true in the emit map');
foreach (TIER_CAPS as $k => $v) {
    if ($oneNew['union'][$k] !== $v) {
        _tgrAssert(false, "(1b) code key '$k' unchanged by an unrelated new cap");
    }
}
_tgrAssert(true, '(1b) every original TIER_CAPS entry is untouched by an unrelated new cap');

/* (c) A malformed CapKey (empty, or fails the grammar) is silently excluded
   — never enters the union, never counted as a "collision" (it's a
   different failure mode). */
$malformed = tierCapsMergeUnion([
    ['CapKey' => '',            'Label' => 'x', 'Description' => 'x', 'DefaultValue' => 0, 'EmitInApi' => 1],
    ['CapKey' => 'NotACapKey',  'Label' => 'x', 'Description' => 'x', 'DefaultValue' => 0, 'EmitInApi' => 1],
    ['CapKey' => 'canlowercase', 'Label' => 'x', 'Description' => 'x', 'DefaultValue' => 0, 'EmitInApi' => 1],
]);
_tgrAssert($malformed['union'] === TIER_CAPS, '(1c) malformed CapKeys never enter the union');
_tgrAssert($malformed['collisions'] === [], '(1c) malformed CapKeys are not reported as collisions');

/* (d) A grammar-VALID CapKey that case-insensitively collides with a
   code-registered TIER_CAPS key is excluded from the union AND reported —
   code always wins, the caller (production _tierCapsUnionAndEmit) logs it. */
$collision = tierCapsMergeUnion([
    ['CapKey' => 'CanVIEWLyrics', 'Label' => 'Collide', 'Description' => 'x', 'DefaultValue' => 1, 'EmitInApi' => 1],
]);
_tgrAssert($collision['union'] === TIER_CAPS, '(1d) a colliding CapKey never enters the union (code wins)');
_tgrAssert($collision['collisions'] === ['CanVIEWLyrics'], '(1d) the collision is reported back to the caller');

/* (e) EmitInApi=0 lands false in the emit map; EmitInApi key absent
   defaults to true (matches the tblGatingCapabilities.EmitInApi DEFAULT 1). */
$emitCases = tierCapsMergeUnion([
    ['CapKey' => 'CanHiddenCap', 'Label' => 'Hidden', 'Description' => '', 'DefaultValue' => 0, 'EmitInApi' => 0],
    ['CapKey' => 'CanNoEmitKey', 'Label' => 'NoKey',  'Description' => '', 'DefaultValue' => 0],
]);
_tgrAssert($emitCases['emit']['CanHiddenCap'] === false, '(1e) EmitInApi=0 row is false in the emit map');
_tgrAssert($emitCases['emit']['CanNoEmitKey'] === true, '(1e) an absent EmitInApi key defaults to true');

/* (f) DefaultValue absent defaults to 0; DefaultValue=1 parses as 1. */
$defaultCases = tierCapsMergeUnion([
    ['CapKey' => 'CanDefaultOne', 'Label' => 'One', 'Description' => '', 'DefaultValue' => 1, 'EmitInApi' => 1],
    ['CapKey' => 'CanNoDefault',  'Label' => 'Zero', 'Description' => '', 'EmitInApi' => 1],
]);
_tgrAssert($defaultCases['union']['CanDefaultOne'][3] === 1, '(1f) DefaultValue=1 parses to 1');
_tgrAssert($defaultCases['union']['CanNoDefault'][3] === 0, '(1f) an absent DefaultValue key defaults to 0');

/* ------------------------------------------------------------------------ *
 * 2. tierCapKeyCollidesWithCode() — the shared collision test.
 * ------------------------------------------------------------------------ */
foreach (array_keys(TIER_CAPS) as $codeKey) {
    _tgrAssert(tierCapKeyCollidesWithCode($codeKey), "(2) exact code key '$codeKey' collides with itself");
    _tgrAssert(tierCapKeyCollidesWithCode(strtoupper($codeKey)), "(2) upper-cased '$codeKey' still collides (case-insensitive)");
    _tgrAssert(tierCapKeyCollidesWithCode(lcfirst($codeKey)), "(2) camelCase form of '$codeKey' still collides");
}
_tgrAssert(!tierCapKeyCollidesWithCode('CanRequestSongs'), "(2) a genuinely new key 'CanRequestSongs' does not collide");

/* ------------------------------------------------------------------------ *
 * 3. Dormancy no-op — every re-pointed accessor is byte-identical to its
 *    pre-#1481 TIER_CAPS-only behaviour when tblGatingCapabilities is
 *    absent (the real state in this DB-free CI run — no db_credentials.php
 *    means getDbMysqli() throws, caught by _tierCapsUnionAndEmit()).
 * ------------------------------------------------------------------------ */
_tgrAssert(tierCapsEffective() === TIER_CAPS, '(3) tierCapsEffective() === TIER_CAPS with no DB available');

$goldenColumnKeys = [];
$goldenJsonKeys   = [];
foreach (TIER_CAPS as $k => $tuple) {
    if ($tuple[2] === 'column') { $goldenColumnKeys[] = $k; } else { $goldenJsonKeys[] = $k; }
}
_tgrAssert(tierCapColumnKeys() === $goldenColumnKeys, '(3) tierCapColumnKeys() unchanged (the 7 built-in column caps, in order)');
_tgrAssert(tierCapJsonKeys() === $goldenJsonKeys, '(3) tierCapJsonKeys() unchanged (empty — no code json caps registered today)');

foreach (TIER_CAPS as $k => [$_lbl, $_hint, $storage, $default]) {
    _tgrAssert(tierCapStorage($k) === $storage, "(3) tierCapStorage('$k') unchanged ($storage)");
    _tgrAssert(tierCapDefault($k) === $default, "(3) tierCapDefault('$k') unchanged ($default)");
    _tgrAssert(tierCapEmitInApi($k) === true, "(3) tierCapEmitInApi('$k') === true for every built-in cap");
}

/* tierCapRead() over a representative tier row — column-backed AND
   json-backed (absent Capabilities column) paths, unchanged from #1352. */
$sampleRow = ['CanViewLyrics' => 1, 'CanViewCopyrighted' => 0];
_tgrAssert(tierCapRead($sampleRow, 'CanViewLyrics') === 1, "(3) tierCapRead() column-backed present value unchanged");
_tgrAssert(tierCapRead($sampleRow, 'CanViewCopyrighted') === 0, "(3) tierCapRead() column-backed present value (0) unchanged");
_tgrAssert(tierCapRead($sampleRow, 'CanPlayAudio') === tierCapDefault('CanPlayAudio'), "(3) tierCapRead() falls back to default for an absent column-backed key");

/* Unknown key defaults: storage 'json' (safe additive home), default 0. */
_tgrAssert(tierCapStorage('CanTotallyUnknownKey') === 'json', "(3) unknown key defaults storage to 'json'");
_tgrAssert(tierCapDefault('CanTotallyUnknownKey') === 0, "(3) unknown key defaults DefaultValue to 0");

/* ------------------------------------------------------------------------ *
 * 4. Structural guard — capValueForTierAction()'s key-resolution reads the
 *    UNION (tierCapsEffective()), never the bare TIER_CAPS constant.
 *    A DB-free behavioural test can't discriminate this (any DB touch
 *    throws → null either way), so this is a source-level guard, mirroring
 *    tests/php/test-apple-siwa-sources.php's static-assertion pattern.
 * ------------------------------------------------------------------------ */
$ccliSrc = (string)file_get_contents($root . '/includes/ccli_validator.php');
/* Extract just the capValueForTierAction() function body (up to its closing
   brace at column 0) so the assertion is scoped to the right function. */
$fnStart = strpos($ccliSrc, 'function capValueForTierAction(');
_tgrAssert($fnStart !== false, '(4) capValueForTierAction() is still defined in ccli_validator.php');
$fnBody = $fnStart !== false ? substr($ccliSrc, $fnStart, 1200) : '';
_tgrAssert(
    str_contains($fnBody, 'tierCapsEffective('),
    '(4) capValueForTierAction() resolves its cap key via tierCapsEffective() (the union)'
);
_tgrAssert(
    !preg_match('/array_key_exists\(\s*\$action\s*,\s*TIER_CAPS\s*\)/', $fnBody),
    '(4) capValueForTierAction() no longer checks array_key_exists($action, TIER_CAPS) directly (bare constant, pre-#1481 shape)'
);

/* Behavioural sanity that DOESN'T need the DB: an action that resolves to
   NO cap key at all (not in TIER_CAPS, not a plausible camelCase form of
   anything) short-circuits to null BEFORE ever touching the database. */
_tgrAssert(
    capValueForTierAction('public', 'not_a_capability_key_at_all') === null,
    '(4) capValueForTierAction() returns null for an unresolvable action without touching the DB'
);

/* ------------------------------------------------------------------------ *
 * 5. Migration-registry 'gating-registry' probe — multi-object OR-probe.
 *    Stub the four _migProbe_* helpers (same pattern as
 *    test-migration-registry.php) but make _migProbe_tableExists()
 *    controllable per-table so the OR logic can be proven directly.
 * ------------------------------------------------------------------------ */
$registryFile = dirname(__DIR__, 2) . '/appWeb/public_html/manage/includes/migration-registry.php';
if (!is_readable($registryFile)) {
    fwrite(STDERR, "FATAL: could not read $registryFile\n");
    $failures++;
} else {
    $GLOBALS['__tgrTableExists'] = ['tblGatingCapabilities' => false, 'tblGatingRules' => false];
    function _migProbe_tableExists(\mysqli $db, string $t): bool
    {
        return $GLOBALS['__tgrTableExists'][$t] ?? false;
    }
    function _migProbe_columnExists(\mysqli $db, string $t, string $c): bool { return false; }
    function _migProbe_columnIsNullable(\mysqli $db, string $t, string $c): bool { return false; }
    function _migProbe_triggerExists(\mysqli $db, string $t): bool { return false; }
    $hasCredentials = true;
    /* Bypass the registry file's own direct-access guard. */
    $_SERVER['SCRIPT_FILENAME'] = '/different.php';

    $MIGRATIONS = require $registryFile;
    $entry      = $MIGRATIONS['gating-registry'] ?? null;

    _tgrAssert($entry !== null, "(5) migration-registry.php has a 'gating-registry' entry");
    _tgrAssert(($entry['probe'] ?? null) instanceof \Closure, "(5) the 'gating-registry' probe is a Closure");
    _tgrAssert($entry['script'] === 'migrate-add-gating-registry.php', "(5) the 'gating-registry' script filename matches the migration file");
    _tgrAssert(
        is_readable(dirname(__DIR__, 2) . '/appWeb/.sql/' . ($entry['script'] ?? '')),
        '(5) the migration script file actually exists on disk'
    );

    if (($entry['probe'] ?? null) instanceof \Closure) {
        /* Type-hinted \mysqli param, but the stubbed helper above never
           touches it — a reflection-constructed, never-connected instance
           satisfies the type check with zero I/O (verified: mysqli's
           constructor is never invoked, so no network attempt is made). */
        $fakeDb = (new \ReflectionClass(\mysqli::class))->newInstanceWithoutConstructor();
        $probe  = $entry['probe'];

        $GLOBALS['__tgrTableExists'] = ['tblGatingCapabilities' => false, 'tblGatingRules' => false];
        _tgrAssert($probe($fakeDb) === true, '(5) neither table exists ⇒ probe reports PENDING (true)');

        $GLOBALS['__tgrTableExists'] = ['tblGatingCapabilities' => true, 'tblGatingRules' => false];
        _tgrAssert($probe($fakeDb) === true, '(5) only tblGatingCapabilities exists ⇒ still PENDING (partial apply never shows green)');

        $GLOBALS['__tgrTableExists'] = ['tblGatingCapabilities' => false, 'tblGatingRules' => true];
        _tgrAssert($probe($fakeDb) === true, '(5) only tblGatingRules exists ⇒ still PENDING (partial apply never shows green)');

        $GLOBALS['__tgrTableExists'] = ['tblGatingCapabilities' => true, 'tblGatingRules' => true];
        _tgrAssert($probe($fakeDb) === false, '(5) BOTH tables exist ⇒ probe reports APPLIED (false = not pending)');
    }
}

/* ----------------------------------------------------------------------- */
echo "\n";
echo "gating-registry: {$passed} passed, {$failures} failed\n";
exit($failures > 0 ? 1 : 0);
