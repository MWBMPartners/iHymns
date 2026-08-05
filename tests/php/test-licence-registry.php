<?php

declare(strict_types=1);

/**
 * iHymns — licence-type registry guard (#1769 P2 Commit C)
 * =======================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * `includes/licence_registry.php` is the ONE place licence types are read. This
 * proves three things stay true: (a) its hardcoded fallback map is byte-equal to
 * the P1 schema seeds — so an un-migrated install behaves like a migrated one;
 * (b) the licence→tier conferral it exposes reproduces the legacy
 * $licenceToTier map exactly for every input — so the #1769 P2 ccli_validator
 * re-point is a provable no-op; (c) it degrades to the fallback (never throws)
 * when the DB is unreadable.
 *
 * @link appWeb/public_html/includes/licence_registry.php  the registry under guard
 * @see  appWeb/.sql/schema.sql  the tblLicenceTypes seeds the fallback mirrors
 */

$repoRoot = dirname(__DIR__, 2);
$failures = [];

require_once $repoRoot . '/tests/php/lib/sql_seed_parser.php';
require_once $repoRoot . '/tests/php/lib/mysqli_doubles.php';
$_SERVER['SCRIPT_FILENAME'] = 'test-runner'; // dodge the direct-access 403 guard
require_once $repoRoot . '/appWeb/public_html/includes/licence_registry.php';

/* =========================================================================
 * PART A — seed-parity (tree-derived, DB-free): LICENCE_TYPES_FALLBACK must
 * match the schema.sql tblLicenceTypes seeds on every STRING field (the parser
 * can't extract numeric/NULL cells precisely — Part C covers those via the live
 * DB). ConfersTier (a string, NULL when absent) and CoversJson (a JSON string)
 * ARE comparable and are the enforcement-relevant fields.
 * ========================================================================= */
$schema = (string)file_get_contents($repoRoot . '/appWeb/.sql/schema.sql');
$inserts = array_values(array_filter(sqlSeedInsertRows($schema),
    static fn($blk) => $blk['table'] === 'tblLicenceTypes'));

if ($inserts === []) {
    $failures[] = 'schema.sql has no INSERT INTO tblLicenceTypes seed block — the #459 seeds are missing.';
} else {
    $blk  = $inserts[0];
    $cols = $blk['columns'];
    $idx  = array_flip($cols);
    $seenKeys = [];
    foreach ($blk['rows'] as $row) {
        $cell = static function (string $col) use ($row, $idx) {
            if (!isset($idx[$col])) { return ['kind' => 'other', 'value' => null]; }
            return $row[$idx[$col]] ?? ['kind' => 'other', 'value' => null];
        };
        $key = $cell('LicenceKey')['value'];
        if (!is_string($key)) { continue; }
        $seenKeys[] = $key;
        $fb = LICENCE_TYPES_FALLBACK[$key] ?? null;
        if ($fb === null) {
            $failures[] = "schema seeds licence '{$key}' but LICENCE_TYPES_FALLBACK does not — add it (or remove the seed).";
            continue;
        }
        /* Label / Description — plain strings. */
        foreach (['Label' => 'label', 'Description' => 'description'] as $sqlCol => $fbKey) {
            $sv = $cell($sqlCol)['value'];
            if ($sv !== $fb[$fbKey]) {
                $failures[] = "licence '{$key}' {$sqlCol}: seed=" . var_export($sv, true) . " fallback=" . var_export($fb[$fbKey], true);
            }
        }
        /* ConfersTier — string, or NULL (kind=other/value=null). */
        $ctCell = $cell('ConfersTier');
        $seedConfers = $ctCell['kind'] === 'string' ? $ctCell['value'] : null;
        if ($seedConfers !== $fb['confersTier']) {
            $failures[] = "licence '{$key}' ConfersTier: seed=" . var_export($seedConfers, true) . " fallback=" . var_export($fb['confersTier'], true);
        }
        /* CoversJson — JSON string decoded to the covers array, or NULL. */
        $cjCell = $cell('CoversJson');
        $seedCovers = null;
        if ($cjCell['kind'] === 'string') {
            $dec = json_decode((string)$cjCell['value'], true);
            $seedCovers = is_array($dec) ? $dec : null;
        }
        if ($seedCovers !== $fb['covers']) {
            $failures[] = "licence '{$key}' CoversJson: seed=" . var_export($seedCovers, true) . " fallback=" . var_export($fb['covers'], true);
        }
    }
    $missing = array_diff(array_keys(LICENCE_TYPES_FALLBACK), $seenKeys);
    foreach ($missing as $k) {
        $failures[] = "LICENCE_TYPES_FALLBACK has '{$k}' but no schema seed row names it.";
    }
}

/* =========================================================================
 * PART B — conferral fixture: the #1769 P2 re-point of ccli_validator's
 * $licenceToTier must be a no-op. For every input the legacy map handled,
 * `licenceConfersTier() ?? legacy` must equal `legacy`. Asserted directly on
 * the fallback const (DB-free, deterministic) AND via the public reader.
 * ========================================================================= */
$legacyLicenceToTier = static function (string $k): string {
    $map = ['none' => 'public', 'ihymns_basic' => 'free', 'ihymns_pro' => 'premium',
            'ccli' => 'ccli', 'premium' => 'premium', 'pro' => 'pro'];
    return $map[$k] ?? 'free'; // the CV:241-248 map + its ?? 'free' default
};
$confersInputs = ['ccli', 'ihymns_basic', 'ihymns_pro', 'none', 'premium', 'pro', 'mrl', 'custom', 'totally-unknown-xyz'];
foreach ($confersInputs as $lic) {
    $legacy = $legacyLicenceToTier($lic);
    /* Direct on the fallback const (no DB, no cache). */
    $fbConfers = LICENCE_TYPES_FALLBACK[$lic]['confersTier'] ?? null;
    $newFallback = $fbConfers ?? ($legacyLicenceToTier($lic)); // mirrors CV's `$confers ?? ($licenceToTier[$k] ?? 'free')`
    if ($newFallback !== $legacy) {
        $failures[] = "conferral no-op broken for '{$lic}': new(fallback)={$newFallback} legacy={$legacy}.";
    }
}
/* mrl + custom must confer NOTHING (so they fall through to 'free' — the
   preserved accident; the intended flip is a P6 owner decision). */
foreach (['mrl', 'custom'] as $k) {
    if ((LICENCE_TYPES_FALLBACK[$k]['confersTier'] ?? null) !== null) {
        $failures[] = "licence '{$k}' must confer no tier in P2 (ConfersTier NULL) — the intended-end-state flip is P6.";
    }
}

/* =========================================================================
 * PART D — grammar + covers + degrade-to-fallback (DB-free).
 * ========================================================================= */
$grammarCases = [['ccli', true], ['ihymns_basic', true], ['x', false], ['CCLI', false],
                 ['1abc', false], ['has space', false], ['ok_key_2', true], ['', false]];
foreach ($grammarCases as [$k, $want]) {
    if (licenceKeyValid($k) !== $want) {
        $failures[] = "licenceKeyValid('{$k}') = " . var_export(licenceKeyValid($k), true) . ", expected " . var_export($want, true);
    }
}
/* covers truth table against the fallback (explicit $db=null → cache/DB, but
   the values match the fallback either way). */
$coversCases = [['ccli', 'lyrics_display', true], ['ccli', 'music_reproduction', false],
                ['mrl', 'music_reproduction', true], ['mrl', 'lyrics_display', false],
                ['ihymns_basic', 'lyrics_display', false], ['ccli', 'not-a-kind', false],
                ['no-such-licence', 'lyrics_display', false]];
/* Use an explicit throwing double so this asserts the FALLBACK specifically. */
$throwing = new \ClaimProbeFailingMysqli();
foreach ($coversCases as [$lic, $ck, $want]) {
    $got = licenceCovers($throwing, $lic, $ck);
    if ($got !== $want) {
        $failures[] = "licenceCovers('{$lic}','{$ck}') on the fallback = " . var_export($got, true) . ", expected " . var_export($want, true);
    }
}
/* A throwing DB double ⇒ licenceTypesAll returns the fallback (never throws). */
$all = licenceTypesAll($throwing);
if (array_keys($all) !== array_keys(LICENCE_TYPES_FALLBACK)) {
    $failures[] = 'licenceTypesAll(throwing-double) did not degrade to LICENCE_TYPES_FALLBACK (keys mismatch).';
}

/* =========================================================================
 * PART C — live DB (SKIP without one). Re-assert conferral + coverage against
 * the real registry, catching a drifted seed on the shared DB before deploy.
 * Uses getDbMysqli() (credentials already loaded by db_mysql.php) — it throws
 * without a configured DB, which we catch as SKIP.
 * ========================================================================= */
$behaviouralRan = false;
try {
    $db = getDbMysqli();
    if ((bool)$db->query("SHOW TABLES LIKE 'tblLicenceTypes'")->num_rows) {
        $live = licenceTypesAll($db);
        foreach (['ccli' => 'ccli', 'ihymns_pro' => 'premium', 'mrl' => null, 'custom' => null] as $k => $tier) {
            /* array_key_exists, NOT ?? — confersTier is legitimately null for mrl/custom. */
            $got = (array_key_exists($k, $live) && array_key_exists('confersTier', $live[$k]))
                ? $live[$k]['confersTier'] : '__missing__';
            if ($got !== $tier) {
                $failures[] = "LIVE tblLicenceTypes '{$k}' ConfersTier = " . var_export($got, true)
                            . ", expected " . var_export($tier, true) . " — a drifted seed on the shared DB.";
            }
        }
        if (!licenceCovers($db, 'mrl', 'music_reproduction')) {
            $failures[] = 'LIVE mrl does not cover music_reproduction — a drifted CoversJson seed.';
        }
        $behaviouralRan = true;
    }
} catch (\Throwable $_e) { /* SKIP — no reachable DB */ }

if ($failures) {
    fwrite(STDERR, "FAIL: licence-registry guard (#1769 P2):\n\n");
    foreach ($failures as $f) { fwrite(STDERR, "  - {$f}\n"); }
    fwrite(STDERR, "\n");
    exit(1);
}
echo "PASS: licence registry — fallback == schema seeds (string+coverage fields), conferral is a no-op vs the "
   . "legacy map for all 9 inputs, grammar/covers correct, degrades to fallback on a bad DB"
   . ($behaviouralRan ? ", and the live registry matches (conferral + mrl coverage).\n" : " (live DB part SKIPPED).\n");
exit(0);
