<?php

declare(strict_types=1);

/**
 * test-musician-name-fold-check.php — create-time fold-match hint (#1800 C3)
 * ============================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * WHAT THIS GUARDS
 * -----------------
 * #1800 C3 added a soft, non-blocking "possible duplicate — view?" hint to
 * the /manage/musicians create form: as the curator types a NEW person's
 * name, a debounced GET to `?action=name_fold_check` asks whether any
 * EXISTING registry row already folds (`ihymns_sim_name_normalise()`,
 * includes/song_similarity.php, rule #22) to exactly the same spelling. The
 * comparison itself lives in the shared `musicianFindFoldMatches()` helper
 * (includes/musician_helpers.php) — this guard is what proves that helper's
 * behaviour, not just that it exists.
 *
 * PART A (always runs, no DB) — source scan: the helper function is defined,
 * and manage/musicians.php actually dispatches the `name_fold_check` GET
 * action to it (rule #33 — a hint is only real if something serves it).
 *
 * PART B (runs when a database is reachable, else SKIP) — BEHAVIOURAL:
 * inserts real disposable rows inside a rolled-back transaction (same
 * connection idiom + insert/assert/rollback shape as
 * test-content-access-ordering.php's Part B) and asserts:
 *   - an EXACT-fold candidate (whitespace/case/apostrophe-style variant) IS
 *     found;
 *   - a genuinely DIFFERENT name is NOT found (no false positives);
 *   - `exclude_id` correctly excludes a row from its own match set;
 *   - blank/punctuation-only input returns no matches (never throws).
 * musicianFindFoldMatches() is READ-ONLY (no transaction of its own), so —
 * unlike musicianMergeExecute() — wrapping the whole scenario in one
 * begin_transaction()/rollback() is safe and leaves ihymns_live untouched.
 *
 * Usage:  php tests/php/test-musician-name-fold-check.php
 *         (Part B needs IHYMNS_TEST_DSN with a dbname, or appWeb/.auth/db_credentials.php)
 * Exit:   0 = pass (Part B may SKIP if no DB — Part A still ran), 1 = failure.
 *
 * @link appWeb/public_html/includes/musician_helpers.php  musicianFindFoldMatches()
 * @link appWeb/public_html/manage/musicians.php            ?action=name_fold_check
 */

$repoRoot = dirname(__DIR__, 2);
$pubDir   = $repoRoot . '/appWeb/public_html';
$failures = [];

/* =========================================================================
 * PART A — source scan (always runs, no DB).
 * ========================================================================= */
require_once $pubDir . '/includes/musician_helpers.php';

if (!function_exists('musicianFindFoldMatches')) {
    $failures[] = 'musicianFindFoldMatches() is not defined in includes/musician_helpers.php.';
}

$musiciansSrc = (string)file_get_contents($pubDir . '/manage/musicians.php');
if (!preg_match("/\\(string\\)\\(\\\$_GET\\['action'\\]\\s*\\?\\?\\s*''\\)\\s*===\\s*'name_fold_check'/", $musiciansSrc)) {
    $failures[] = "manage/musicians.php does not dispatch a GET ?action=name_fold_check route — "
                . "the hint's client fetch() would 404 (rule #33).";
}
if (!preg_match('/musicianFindFoldMatches\s*\(\s*\$db/', $musiciansSrc)) {
    $failures[] = "manage/musicians.php's name_fold_check route doesn't appear to call "
                . "musicianFindFoldMatches() — has the wiring been re-forked or removed?";
}
if (!str_contains($musiciansSrc, 'mus-drawer-name-foldhint')) {
    $failures[] = "manage/musicians.php's Add-person drawer no longer renders the "
                . "#mus-drawer-name-foldhint element — the hint has nowhere to render.";
}

/* =========================================================================
 * PART B — behavioural (runs when a DB is reachable, else SKIP).
 *
 * Connection idiom mirrors test-content-access-ordering.php's Part B:
 * db_credentials.php first, else IHYMNS_TEST_DSN (needs a dbname), else skip.
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
if ($dbName !== null) {
    try {
        $db = $sock !== null
            ? new \mysqli($host, $user, $pass, $dbName, 0, $sock)
            : new \mysqli($host, $user, $pass, $dbName, $port);
    } catch (\Throwable $e) {
        $db = null;
    }

    if ($db instanceof \mysqli && !$db->connect_error) {
        $hasTable = (bool)$db->query("SHOW TABLES LIKE 'tblMusicians'")->num_rows;
        if ($hasTable) {
            $db->begin_transaction();
            try {
                $exactName  = "ZZTEST-1800 O'Brien FoldCheck";
                $variantIn  = "  zztest-1800   O’Brien   FoldCheck  "; // extra whitespace + case + curly apostrophe
                $unrelated  = 'ZZTEST-1800-Totally Different Name';

                $stmt = $db->prepare('INSERT INTO tblMusicians (Name) VALUES (?)');
                $stmt->bind_param('s', $exactName);
                $stmt->execute();
                $exactId = (int)$db->insert_id;
                $stmt->close();

                $stmt = $db->prepare('INSERT INTO tblMusicians (Name) VALUES (?)');
                $stmt->bind_param('s', $unrelated);
                $stmt->execute();
                $unrelatedId = (int)$db->insert_id;
                $stmt->close();

                /* A whitespace/case/apostrophe-style variant of $exactName must
                   be found — this IS the "extra space / curly apostrophe" case
                   the file doc-block (musicianDuplicatesFindCandidates()'s
                   Bucket A) describes as the exact-fold class. */
                $matches = musicianFindFoldMatches($db, $variantIn);
                $foundIds = array_column($matches, 'id');
                if (!in_array($exactId, $foundIds, true)) {
                    $failures[] = "musicianFindFoldMatches() did not find the whitespace/case/apostrophe "
                                . "variant of an existing name — a fold-equal candidate was missed.";
                }
                if (in_array($unrelatedId, $foundIds, true)) {
                    $failures[] = "musicianFindFoldMatches() returned a GENUINELY DIFFERENT name as a match "
                                . "— false positive (it should only catch EXACT fold equality, not fuzzy similarity).";
                }

                /* A candidate whose OWN id is excluded must not match itself. */
                $selfExcluded = musicianFindFoldMatches($db, $exactName, $exactId);
                if (in_array($exactId, array_column($selfExcluded, 'id'), true)) {
                    $failures[] = "musicianFindFoldMatches() with exclude_id set to the candidate's OWN id "
                                . "still returned that id — the exclusion isn't being applied.";
                }

                /* A genuinely different candidate name must return no matches. */
                $noMatch = musicianFindFoldMatches($db, 'ZZTEST-1800-Nobody Has This Name At All');
                if ($noMatch !== []) {
                    $failures[] = "musicianFindFoldMatches() returned matches for a name nothing in the "
                                . "registry resembles — expected an empty list.";
                }

                /* Blank / punctuation-only input must never throw and must
                   return no matches (a curator who has typed nothing yet, or
                   only punctuation, shouldn't see a hint or a 500). */
                if (musicianFindFoldMatches($db, '') !== []) {
                    $failures[] = "musicianFindFoldMatches('') did not return an empty list.";
                }
                if (musicianFindFoldMatches($db, '...') !== []) {
                    $failures[] = "musicianFindFoldMatches('...') (folds to '') did not return an empty list.";
                }

                $behaviouralRan = true;
            } finally {
                $db->rollback(); // never persist the probe rows
            }
        }
        $db->close();
    }
}

/* =========================================================================
 * REPORT
 * ========================================================================= */
if ($failures) {
    fwrite(STDERR, "FAIL: musician create-time fold-match hint guard (#1800 C3):\n\n");
    foreach ($failures as $f) { fwrite(STDERR, "  - {$f}\n"); }
    fwrite(STDERR, "\n");
    exit(1);
}

echo "PASS: name_fold_check is wired (source scan)";
echo $behaviouralRan
    ? ", and musicianFindFoldMatches() correctly matched an exact-fold variant, rejected an unrelated "
      . "name, honoured exclude_id, and handled blank/punctuation-only input — verified against a live "
      . "database (rolled back, no residue).\n"
    : " (behavioural check SKIPPED: no reachable database with dbname; set IHYMNS_TEST_DSN or "
      . "appWeb/.auth/db_credentials.php to exercise it).\n";
exit(0);
