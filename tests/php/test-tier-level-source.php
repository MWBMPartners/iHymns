<?php

declare(strict_types=1);

/**
 * iHymns — tier ordering reads the LIVE tblAccessTiers.Level (#1769 P0, OV-4)
 * ==========================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * When iHymns compares two access plans to decide which one wins ("is this
 * person's personal plan higher than the plan their church gives them?"), it
 * must rank them by the **Level** number an admin typed on /manage/tiers — not
 * by a hardcoded list that only knows the five built-in plan names. Before
 * #1769 P0 the resolver used the hardcoded `TIER_LEVELS` const, so a
 * curator-created plan ("Supporter", Level 500) was ranked **0** and lost to
 * everything. This guard makes sure the resolver reads the live Level and that
 * a custom tier ranks by it.
 *
 *   Part A (always, no DB) — source scan: resolveEffectiveTier() must compare
 *     tiers through tierLevelRank(), never by indexing TIER_LEVELS directly.
 *   Part B (DB) — behavioural: a freshly-inserted custom tier with Level 500
 *     (rolled back) must rank 500 and outrank 'premium'; an unknown tier ranks
 *     0 (the fallback).
 *
 * MUTATION PROOF (rule #34): (a) reverting the resolver's comparisons to
 * `TIER_LEVELS[$x] ?? 0` turns Part A red; (b) making tierLevelRank() ignore the
 * live map (`return TIER_LEVELS[$tier] ?? 0;`) turns Part B red (the custom
 * tier ranks 0, not 500). Restore → green. (Proven by the author via cp-backup.)
 *
 * @link appWeb/public_html/includes/ccli_validator.php  tierLevelRank() / resolveEffectiveTier() (#1769 P0)
 */

$repoRoot = dirname(__DIR__, 2);
$failures = [];

/* =========================================================================
 * PART A — source scan (always runs).
 * ========================================================================= */
$file = $repoRoot . '/appWeb/public_html/includes/ccli_validator.php';
$src  = is_readable($file) ? (string)file_get_contents($file) : '';
if ($src === '') {
    fwrite(STDERR, "FATAL: could not read {$file}\n");
    exit(1);
}
if (!str_contains($src, 'function tierLevelRank')) {
    $failures[] = "ccli_validator.php: tierLevelRank() is missing — the live-Level ranker the resolver must use.";
}
/* The three buggy direct-index comparisons must be gone (they ranked a custom
   tier 0). tierLevelRank()'s own fallback body legitimately references
   TIER_LEVELS, so we ban the specific COMPARISON patterns, not the const. */
foreach (['TIER_LEVELS[$personalTier]', 'TIER_LEVELS[$mapped]', 'TIER_LEVELS[$orgTier]'] as $badPattern) {
    if (str_contains($src, $badPattern)) {
        $failures[] = "ccli_validator.php: resolveEffectiveTier() still ranks via {$badPattern} — it must "
                    . "use tierLevelRank(\$db, …) so a custom tier ranks by its live Level, not 0 (#1769 P0).";
    }
}
if (substr_count($src, 'tierLevelRank($db') < 3) {
    $failures[] = "ccli_validator.php: expected resolveEffectiveTier() to call tierLevelRank(\$db, …) at least "
                . "three times (two in the org loop/compare, plus the helper is defined) — found fewer.";
}

/* =========================================================================
 * PART B — behavioural (DB, else SKIP). Mirror the connection idiom of
 * test-content-access-ordering.php: define DB_* so the library's internal
 * getDbMysqli() hits the test DB; insert a custom tier in a rolled-back txn.
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
    if (!defined('DB_HOST')) { define('DB_HOST', $host); }
    if (!defined('DB_USER')) { define('DB_USER', $user); }
    if (!defined('DB_PASS')) { define('DB_PASS', $pass); }
    if (!defined('DB_PORT')) { define('DB_PORT', (string)$port); }
    if (!defined('DB_NAME')) { define('DB_NAME', $dbName); }

    require_once $repoRoot . '/appWeb/public_html/includes/ccli_validator.php';

    try {
        $db = getDbMysqli();
        $hasTable = (bool)$db->query("SHOW TABLES LIKE 'tblAccessTiers'")->num_rows;
    } catch (\Throwable $e) {
        $db = null; $hasTable = false;
    }

    if ($db !== null && $hasTable) {
        $db->begin_transaction();
        try {
            /* Insert the custom tier BEFORE the first tierLevelRank() call so the
               request-cached map picks it up. Name is unique + reserved-looking. */
            $custom = 'zztier1769';
            $stmt = $db->prepare(
                "INSERT INTO tblAccessTiers (Name, DisplayName, Level, Description) VALUES (?, 'ZZ Test Tier', 500, 'ordering fixture')"
            );
            $stmt->bind_param('s', $custom);
            $stmt->execute();
            $stmt->close();

            $rankCustom  = tierLevelRank($db, $custom);
            $rankPremium = tierLevelRank($db, 'premium');
            $rankUnknown = tierLevelRank($db, 'zz-nope-not-a-tier');

            if ($rankCustom !== 500) {
                $failures[] = "tierLevelRank('{$custom}') = {$rankCustom}, expected 500 (the live tblAccessTiers.Level "
                            . "an admin set) — the resolver is ignoring the live Level column (#1769 P0 OV-4 regression).";
            }
            if (!($rankCustom > $rankPremium)) {
                $failures[] = "tierLevelRank('{$custom}')={$rankCustom} must outrank tierLevelRank('premium')={$rankPremium} "
                            . "— a custom Level-500 tier ranked at or below a built-in one means it is still ranking 0.";
            }
            if ($rankUnknown !== 0) {
                $failures[] = "tierLevelRank('zz-nope-not-a-tier') = {$rankUnknown}, expected 0 (an unknown tier ranks 0 — "
                            . "neither in the live table nor the TIER_LEVELS fallback).";
            }
            $behaviouralRan = true;
        } finally {
            $db->rollback();
        }
    }
}

if ($failures) {
    fwrite(STDERR, "FAIL: tier-level source guard (#1769 P0 OV-4):\n\n");
    foreach ($failures as $f) { fwrite(STDERR, "  - {$f}\n"); }
    fwrite(STDERR, "\n");
    exit(1);
}
echo "PASS: tier ordering reads the live tblAccessTiers.Level via tierLevelRank()";
echo $behaviouralRan
    ? " — behavioural check confirmed a custom Level-500 tier ranks 500 and outranks premium, unknown tier ranks 0.\n"
    : " (source scan only — Part B SKIPPED: no reachable database with dbname).\n";
exit(0);
