<?php

declare(strict_types=1);

/**
 * test-musician-credit-tables-single-list.php — rule #35 guard (G3, #1785)
 * =============================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * WHAT THIS GUARDS
 * -----------------
 * Before #1785 C5, "which tables hold a song's credits" was independently
 * hand-typed as a FIVE-table list in at least nine places (musicians.php's
 * merge/rename/delete-usage-count/list-load/view_songs, musicians-bulk-
 * promote.php's own copy, api.php's rename/merge/delete siblings, and three
 * functions in musician_helpers.php) — every one missing `tblSongArtists`,
 * which the editor's `ED2_CREDIT_TABLES` (manage/editor/api2.php) already
 * knew about. That drift is exactly what silently stranded artist credits
 * on a deleted spelling after a merge/rename, and undercounted every
 * use-count on the Musicians list AND on the API's delete-usage-count guard
 * (#1796). #1785 C5 re-points every one of those sites onto ONE shared
 * constant, `MUSICIAN_CREDIT_ROLE_TABLES` (includes/musician_helpers.php).
 * This guard is the MECHANISM (rule #35: "a comment saying 'keep these in
 * sync' is the failure, not the fix") that stops a NEW hand-typed fork from
 * silently reappearing in the four files that actually mutate/count the
 * registry.
 *
 * TWO checks:
 *
 *   PART A — no re-forked list in the registry-mutation surface. Scans the
 *   comment-stripped source of the four files #1785 C4/C5 actually touched
 *   (api.php, manage/musicians.php, manage/musicians-bulk-promote.php,
 *   includes/musician_helpers.php) for BARE occurrences of the six
 *   `tblSong…` table names (not just quoted-literal matches — the very fork
 *   this guard's first draft MISSED during this build was a table name
 *   embedded inside a larger double-quoted multi-line SQL string, e.g.
 *   `"SELECT (...FROM tblSongWriters...) AS total"`, which a naive
 *   single-quoted-literal-only regex never sees), EXCLUDING plain subscript
 *   reads of the shape `$array['tblSongWriters']` (a CONSUMER reading back
 *   a value the shared constant already populated — safe).
 *
 *   The remaining occurrences are then CLUSTERED: does any 400-character
 *   window contain ≥3 DISTINCT table names? A genuine forked list (six
 *   names declared together, or interpolated into one UNION/count query)
 *   always clusters tightly; api.php's two unrelated, pre-existing writer/
 *   composer duplicate-credit detectors (a completely different feature —
 *   finding songs that credit the SAME writer/composer name twice — lines
 *   ~9870-10250) never use more than TWO of the six names and are hundreds
 *   of lines apart, so they never cluster. This is what makes the check
 *   safe on a 20,000-line multi-purpose file like api.php: a flat
 *   whole-file "must be zero occurrences" count (this guard's own FIRST,
 *   WRONG draft) is too blunt there and would need an ever-growing
 *   exemption list for every unrelated feature that happens to mention a
 *   credit-table name once; clustering only reacts to the actual FORK
 *   shape. Asserts a cluster exists in `includes/musician_helpers.php`
 *   (the MUSICIAN_CREDIT_ROLE_TABLES declaration) and NOWHERE else.
 *
 *   PART B — the TWO cross-file registries can't drift apart. Asserts
 *   `array_values(MUSICIAN_CREDIT_ROLE_TABLES)` (loaded for real, by
 *   requiring musician_helpers.php) is SET-EQUAL to the six table names
 *   statically extracted from manage/editor/api2.php's `const
 *   ED2_CREDIT_TABLES = [...]` declaration (extracted via regex on the raw
 *   source, NOT by requiring api2.php — that file is a live HTTP dispatcher
 *   with routing side effects at the bottom and is unsafe to `require`
 *   outside a real request). VALUE-set-equal, not key-set-equal: the two
 *   constants deliberately use different key CONVENTIONS
 *   (MUSICIAN_CREDIT_ROLE_TABLES's singular 'writer'/'composer'/… mirrors
 *   this file's own kindLabel idiom elsewhere; ED2_CREDIT_TABLES's plural
 *   'writers'/'composers'/… mirrors the editor's JSON payload field names)
 *   — forcing identical keys would be change for its own sake, not a real
 *   agreement. What must never drift is WHICH SIX TABLES count as a credit
 *   table, which is exactly what value-set-equality checks.
 *
 * SCOPE NOTE (why not the whole tree): a repo-wide scan for "≥3 of these six
 * literals clustered" would ALSO flag several files with their OWN,
 * genuinely different six/five-table lists for genuinely different concerns
 * — manage/editor/save_song_core.php's per-role INSERT-SQL map, includes/
 * song_relocate.php's FK-relocate tuple list, includes/song_importers.php's
 * deliberately-artist-EXCLUDED import map, includes/pages/musician.php's
 * public-page section builder. None of those are forks of the REGISTRY-
 * mutation concern this guard's sibling commits fixed; flagging them here
 * would be the guard-fires-on-correct-code failure rule #34 warns against.
 * This guard is deliberately scoped to the SIX files that actually touch the
 * registry-mutation concern — the original four #1785 C4/C5 rewrote, PLUS
 * includes/musician_duplicates.php and manage/musician-duplicates.php,
 * added by #1785 C6/C7 (the registry-duplicate scan + its review page) and
 * folded into this guard's scan in C9 — narrow, not blunt (rule #34's
 * closing lesson). C9 is also this guard's own mutation-proof AGAINST those
 * two new files specifically (rule #34: a guard's first green run on a new
 * surface is unproven until something is broken and shown red) — see the
 * #1785 C9 commit body for the broken -> red -> restored record.
 *
 * PART C — the credit_search fork, closed (#1800 C1). manage/editor/api2.php's
 * `credit_search` action used to carry a THIRD, independent six-table map
 * (`$kindToTable`, same six tables, singular keys matching
 * MUSICIAN_CREDIT_ROLE_TABLES's own convention) — a local variable duplicating
 * BOTH ED2_CREDIT_TABLES (same file) AND MUSICIAN_CREDIT_ROLE_TABLES. It was
 * flagged here (#1785) as "discovered, not fixed" because it was a different
 * endpoint (the editor's credit-search autocomplete) outside #1785's
 * registry-merge scope. #1800 C1 closes it: `$kindToTable` now reads
 * `MUSICIAN_CREDIT_ROLE_TABLES` directly (its keys already match this
 * endpoint's `kind=` convention, so no transform was needed). PART C below is
 * the mechanism, not a comment (rule #35): it isolates the `credit_search`
 * case block by source position (NOT by requiring api2.php — a live HTTP
 * dispatcher, unsafe to include statically, same reasoning PART B already
 * documents) and asserts the block contains no bare six-table-name string
 * literal AND does reference MUSICIAN_CREDIT_ROLE_TABLES, so a future
 * hand-typed re-fork at this THIRD site is caught the same way PART A catches
 * one at the original four/six.
 *
 *   php tests/php/test-musician-credit-tables-single-list.php
 *
 * Exit status 0 = all checks pass, 1 = a fork or a drift was found.
 *
 * @see .claude/musicians-dedup-1785-plan.md §10 (guard G3)
 */

$root   = dirname(__DIR__, 2);
$pubDir = $root . '/appWeb/public_html';

function musCtSlStripPhpComments(string $src): string
{
    $out = '';
    foreach (token_get_all($src) as $tok) {
        if (is_array($tok)) {
            if ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT) {
                $out .= str_repeat("\n", substr_count($tok[1], "\n"));
                continue;
            }
            $out .= $tok[1];
        } else {
            $out .= $tok;
        }
    }
    return $out;
}

const MUS_CT_SL_TABLES = [
    'tblSongWriters', 'tblSongComposers', 'tblSongArrangers',
    'tblSongAdaptors', 'tblSongTranslators', 'tblSongArtists',
];
const MUS_CT_SL_WINDOW = 400; // chars — comfortably spans a 6-entry declaration, far short of bridging two unrelated mentions in a large file

/**
 * Does the comment-stripped, subscript-excluded source contain a window
 * of MUS_CT_SL_WINDOW chars with >= 3 DISTINCT credit-table names? Returns
 * a short description of the first such window for reporting, or null.
 */
function musCtSlFindCluster(string $strippedSource): ?string
{
    $tableAlt = implode('|', MUS_CT_SL_TABLES);
    $bareWord = "/\\b(?:{$tableAlt})\\b/";

    if (!preg_match_all($bareWord, $strippedSource, $m, PREG_OFFSET_CAPTURE)) {
        return null;
    }
    $matches = $m[0]; // [ [text, byteOffset], ... ] — already in source order

    for ($i = 0; $i < count($matches); $i++) {
        $windowStart = $matches[$i][1];
        $distinct = [$matches[$i][0] => true];
        for ($j = $i + 1; $j < count($matches); $j++) {
            if ($matches[$j][1] - $windowStart > MUS_CT_SL_WINDOW) {
                break;
            }
            $distinct[$matches[$j][0]] = true;
        }
        if (count($distinct) >= 3) {
            $line = substr_count(substr($strippedSource, 0, $windowStart), "\n") + 1;
            return "line ~{$line}: " . implode(', ', array_keys($distinct));
        }
    }
    return null;
}

$failed = 0;

/* =========================================================================
 * PART A — no re-forked list in the four registry-mutation files.
 * ========================================================================= */
echo "1 — no re-forked five/six-table list in the registry-mutation surface\n";

$tableAlt = implode('|', MUS_CT_SL_TABLES);
$subscriptOccurrence = "/\[\s*['\"](?:{$tableAlt})['\"]\s*\]/";

/* Files that touch the registry-mutation concern MUSICIAN_CREDIT_ROLE_
   TABLES exists for — see the file doc-block's SCOPE NOTE for why this is
   deliberately not the whole tree. Only musician_helpers.php is expected
   to carry a cluster — its OWN declaration of the shared constant.
   includes/musician_duplicates.php (#1785 C6) and manage/musician-
   duplicates.php (#1785 C7) added in C9 — both legitimately reference the
   six table names (a six-table union for use-counts; six activity-log
   subscript reads mirroring musicians.php's own $logMusician payload
   shape), so both must clear the SAME "no cluster" bar the original four
   do. */
$expectCluster = [
    'includes/musician_helpers.php'          => true,
    'api.php'                                => false,
    'manage/musicians.php'                   => false,
    'manage/musicians-bulk-promote.php'      => false,
    'includes/musician_duplicates.php'       => false,
    'manage/musician-duplicates.php'         => false,
];

foreach ($expectCluster as $rel => $shouldCluster) {
    $path = $pubDir . '/' . $rel;
    if (!is_readable($path)) {
        $failed++;
        fwrite(STDERR, "FAIL: cannot read $rel\n");
        continue;
    }
    $stripped = musCtSlStripPhpComments((string)file_get_contents($path));
    // Drop subscript reads ($array['tblSongWriters']) — those are safe
    // consumers of a value MUSICIAN_CREDIT_ROLE_TABLES already populated.
    $withoutSubscripts = preg_replace($subscriptOccurrence, '', $stripped) ?? $stripped;
    $cluster = musCtSlFindCluster($withoutSubscripts);
    $found = $cluster !== null;

    if ($found === $shouldCluster) {
        echo "  PASS  $rel: " . ($found ? "cluster found ({$cluster}), as expected" : "no cluster, as expected") . "\n";
    } else {
        $failed++;
        if ($found) {
            fwrite(STDERR, "  FAIL  $rel: unexpected re-forked table list found — {$cluster}\n");
            fwrite(STDERR, "        Re-point it at MUSICIAN_CREDIT_ROLE_TABLES instead of hand-typing the table names.\n");
        } else {
            fwrite(STDERR, "  FAIL  $rel: expected MUSICIAN_CREDIT_ROLE_TABLES's own declaration to cluster here, found none.\n");
            fwrite(STDERR, "        Has the constant been renamed, moved, or reformatted past the window size?\n");
        }
    }
}

/* =========================================================================
 * PART B — MUSICIAN_CREDIT_ROLE_TABLES and ED2_CREDIT_TABLES can't drift.
 * ========================================================================= */
echo "\n2 — MUSICIAN_CREDIT_ROLE_TABLES and ED2_CREDIT_TABLES stay value-set-equal\n";

/* Load the real constant — musician_helpers.php's direct-access guard
   compares basenames, and this test's own basename never matches, so a
   plain require_once sails through exactly as every other consumer does
   (the same reasoning test-musician-slug-resolve.php's doc-block gives). */
require_once $pubDir . '/includes/musician_helpers.php';

if (!defined('MUSICIAN_CREDIT_ROLE_TABLES')) {
    $failed++;
    fwrite(STDERR, "FAIL: MUSICIAN_CREDIT_ROLE_TABLES is not defined after requiring musician_helpers.php.\n");
    $musicianTables = [];
} else {
    $musicianTables = array_values(MUSICIAN_CREDIT_ROLE_TABLES);
}

/* Extract ED2_CREDIT_TABLES's VALUES from api2.php's raw source via regex
   — NOT by requiring the file, which is a live HTTP action dispatcher with
   routing side effects at the bottom (auth/DB/routing code that runs when
   loaded outside a real request) and unsafe to include from a static test. */
$api2Path = $pubDir . '/manage/editor/api2.php';
$ed2Tables = [];
if (is_readable($api2Path)) {
    $api2Src = (string)file_get_contents($api2Path);
    if (preg_match('/const\s+ED2_CREDIT_TABLES\s*=\s*\[(.*?)\];/s', $api2Src, $m)) {
        preg_match_all('/=>\s*\'(tblSong\w+)\'/', $m[1], $tm);
        $ed2Tables = $tm[1];
    } else {
        $failed++;
        fwrite(STDERR, "FAIL: could not find `const ED2_CREDIT_TABLES = [...]` in manage/editor/api2.php — has it been renamed?\n");
    }
} else {
    $failed++;
    fwrite(STDERR, "FAIL: cannot read manage/editor/api2.php\n");
}

$sortedMusician = $musicianTables; sort($sortedMusician);
$sortedEd2      = $ed2Tables;      sort($sortedEd2);

if ($sortedMusician === $sortedEd2 && $sortedMusician !== []) {
    echo "  PASS  MUSICIAN_CREDIT_ROLE_TABLES and ED2_CREDIT_TABLES name the exact same six tables\n";
} else {
    $failed++;
    fwrite(STDERR, "  FAIL  the two registries have drifted:\n");
    fwrite(STDERR, "        MUSICIAN_CREDIT_ROLE_TABLES: " . implode(', ', $sortedMusician) . "\n");
    fwrite(STDERR, "        ED2_CREDIT_TABLES:           " . implode(', ', $sortedEd2) . "\n");
}

/* =========================================================================
 * PART C — api2.php's credit_search action reuses MUSICIAN_CREDIT_ROLE_TABLES
 * instead of hand-typing a THIRD copy of the six-table map (#1800 C1 — closes
 * the "discovered, not fixed here" gap this file's doc-block used to flag).
 * ========================================================================= */
echo "\n3 — credit_search reuses MUSICIAN_CREDIT_ROLE_TABLES, no third hand-typed table list\n";

/* $api2Src was already read+cached by PART B above (empty string if that
   read failed — PART B already reported that failure, so this just skips
   silently rather than double-reporting the same missing-file condition). */
$api2Src = $api2Src ?? '';
if ($api2Src === '') {
    $failed++;
    fwrite(STDERR, "  FAIL  manage/editor/api2.php was not readable for the credit_search check (see PART B's failure above)\n");
} else {
    $strippedApi2 = musCtSlStripPhpComments($api2Src);
    /* Isolate the credit_search case block by SOURCE POSITION — up to the
       next `case '...'  :` at the same 4-space switch-statement indentation
       — rather than by brace-balancing (the block's own foreach loops
       contain nested braces, so a naive first-`}` match would truncate
       early). Mirrors PART B's "extract via regex on raw source, never
       require() this live dispatcher" posture. */
    if (!preg_match("/case\\s+'credit_search'\\s*:(.*?)(?=\\n\\s{4}case\\s+')/s", $strippedApi2, $csm)) {
        $failed++;
        fwrite(STDERR, "  FAIL  could not isolate the `case 'credit_search':` block in manage/editor/api2.php — has it been renamed or restructured?\n");
    } else {
        $block = $csm[1];

        /* No bare six-table-name STRING LITERAL may remain inside the
           block — that shape is exactly the hand-typed $kindToTable fork
           #1800 C1 removed. (Subscript reads like $affected['tblSongWriters']
           aren't reachable here — this block never builds that kind of
           array — so, unlike PART A, no subscript-exclusion is needed.) */
        $literalHit = null;
        foreach (MUS_CT_SL_TABLES as $tbl) {
            if (preg_match('/[\'"]' . preg_quote($tbl, '/') . '[\'"]/', $block)) {
                $literalHit = $tbl;
                break;
            }
        }
        $usesRegistry = str_contains($block, 'MUSICIAN_CREDIT_ROLE_TABLES');

        if ($literalHit === null && $usesRegistry) {
            echo "  PASS  credit_search delegates to MUSICIAN_CREDIT_ROLE_TABLES, no hand-typed table literal remains\n";
        } else {
            $failed++;
            if ($literalHit !== null) {
                fwrite(STDERR, "  FAIL  credit_search still hand-types a table literal ('{$literalHit}') — re-point it at MUSICIAN_CREDIT_ROLE_TABLES.\n");
            }
            if (!$usesRegistry) {
                fwrite(STDERR, "  FAIL  credit_search no longer references MUSICIAN_CREDIT_ROLE_TABLES — has the #1800 C1 fix regressed?\n");
            }
        }
    }
}

if ($failed > 0) {
    fwrite(STDERR, "\n$failed check(s) failed.\n");
    exit(1);
}
echo "\nmusician-credit-tables-single-list: all checks passed.\n";
exit(0);
