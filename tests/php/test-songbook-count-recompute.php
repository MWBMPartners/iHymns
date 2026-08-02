<?php

declare(strict_types=1);

/**
 * test-songbook-count-recompute.php — songbookRecomputeSongCount() BEHAVES + is WIRED (#1742)
 * ==============================================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * Two kinds of proof, in one file. First: the shared recompute helper itself
 * (`includes/songbook_count.php`) actually does the right thing against the
 * shared recording double — trims, dedupes, skips blanks, touches the DB zero
 * times when there is nothing to do, and asks the right question
 * (`songVisibleSql()`) so it degrades safely on an install where the #1694
 * soft-delete migration has not run. Second: every caller that is SUPPOSED to
 * use the helper actually does, and none of them kept (or re-grew) its own
 * private copy of the UPDATE — including `manage/editor/api2.php`'s
 * `create_song`, which is the #1742 bug this whole file exists to guard: it
 * had NO recompute at all, so a songbook created through the v2 editor never
 * got its home tile back on a shared host with no `CREATE TRIGGER` privilege
 * (iHymns' actual deployment target — #791/#793's DB triggers are the other
 * half of the mechanism, and they simply do not exist there).
 *
 * WHY THE MODE SPLIT (same reason as test-song-soft-delete.php)
 * -----------------------------------------------------------------
 * `songSoftDeleteReady()` (`includes/song_soft_delete.php`) memoises its
 * answer in a function-local `static`, per PROCESS — not per `$db` instance.
 * The migrated-columns scenarios (assertions 1-4) and the un-migrated
 * scenario (assertion 5) need that memo to settle DIFFERENTLY, so each runs
 * in its own child PHP process; a single shared process would let whichever
 * scenario asks first pin the answer for every scenario after it. The
 * structural/wiring guards (6-9) never touch the DB or the memo, so they run
 * once in the parent.
 *
 * MUTATIONS THIS SUITE WAS PROVEN RED AGAINST (recorded per house style;
 * M1, M3, M4 and M5 were ACTUALLY PERFORMED — see the session report for the
 * red output each produced — then reverted; M2 follows the identical shape
 * as M1 and is left as a straightforward extension):
 *   M1 api2.php create_song's songbookRecomputeSongCount() call deleted
 *      → guard 6 red ("api2.php create_song no longer calls
 *        songbookRecomputeSongCount — SongCount goes stale on trigger-less
 *        hosts (#1742)")
 *   M2 an inline `UPDATE tblSongbooks ... SET SongCount` re-added to
 *      save_song_core.php (supplementing, not replacing, the shared call)
 *      → guard 7 red (the no-inline-copy half)
 *   M3 the helper's dedupe (`array_unique`) removed                  → assertion 3 red
 *   M4 the helper's empty-check moved to AFTER `$db->prepare(...)`   → assertion 4 red
 *   M5 the helper hardcodes `'IsDeleted = 0'` instead of calling
 *      `songVisibleSql($db, '')`                                     → assertion 5 red
 *
 * Usage:
 *   php tests/php/test-songbook-count-recompute.php     # parent: guards + both modes
 *
 * Exit status 0 = all pass, 1 = a failure.
 *
 * @see appWeb/public_html/includes/songbook_count.php        the module under test
 * @see appWeb/public_html/manage/editor/api2.php              create_song — the #1742 fix
 * @see appWeb/public_html/manage/editor/save_song_core.php    caller #2 (current + previous book)
 * @see appWeb/public_html/includes/song_relocate.php          caller #3 (target + current book)
 * @see tests/php/lib/mysqli_doubles.php                       the shared GateRecorderMysqli double
 * @see tests/php/test-song-soft-delete.php                    the harness shape + the migrated-columns fixture this copies
 */

$ROOT = dirname(__DIR__, 2);
require_once $ROOT . '/appWeb/public_html/includes/songbook_count.php';
require_once __DIR__ . '/lib/mysqli_doubles.php';

$fail = 0;
function ok(string $label, bool $cond, string $detail = ''): void
{
    global $fail;
    echo ($cond ? "  PASS  " : "  FAIL  ") . $label . "\n";
    if (!$cond) {
        $fail++;
        if ($detail !== '') { echo "        " . str_replace("\n", "\n        ", $detail) . "\n"; }
    }
}

/**
 * The five soft-delete columns `songSoftDeleteReady()` checks in lockstep
 * (`includes/song_soft_delete.php`) — verified against
 * `softDeleteColumnsFixture()` in test-song-soft-delete.php to be the SAME
 * set, so this fixture reaches the same "migrated" answer that suite does.
 * With all five present, `songVisibleSql($db, '')` returns `'IsDeleted = 0'`
 * — proved explicitly below, not just assumed.
 */
function scr_migratedColumns(): array
{
    return [
        'tblSongs.IsDeleted' => true, 'tblSongs.DeletedAt' => true,
        'tblSongs.DeletedBy' => true, 'tblSongs.DeletedReason' => true,
        'tblSongs.DeleteNote' => true,
    ];
}

/**
 * Pull the source text of one `case '<name>':` handler out of a switch
 * statement — from the case label to the next `case ` at the same
 * indentation, or end-of-file if this is the last case.
 *
 * Tree-derived, not hand-typed (rule #34): this reads the REAL file, so a
 * handler that moves, grows or shrinks is still captured correctly; only the
 * case LABEL is hardcoded, which is the thing we're actually trying to find.
 */
function scr_extractCaseWindow(string $src, string $caseNeedle): ?string
{
    $start = strpos($src, $caseNeedle);
    if ($start === false) {
        return null;
    }
    $searchFrom = $start + strlen($caseNeedle);
    $nextCasePos = strpos($src, "\n    case ", $searchFrom);
    return $nextCasePos === false ? substr($src, $start) : substr($src, $start, $nextCasePos - $start);
}

const SBC_MODE_ENV = 'IHYMNS_SBC_MODE';
const SBC_MODES    = ['migrated', 'unmigrated'];

$mode = getenv(SBC_MODE_ENV) ?: '';

if ($mode === '') {
    /* ------------------------------------------------------------------- *
     *  PARENT — structural / wiring guards (no DB, no memo), then each
     *  behavioural mode in its own process.
     * ------------------------------------------------------------------- */
    echo "0 — structural / wiring guards (tree-derived, mutation-tested — rule #34)\n";

    $api2Path     = $ROOT . '/appWeb/public_html/manage/editor/api2.php';
    $saveCorePath = $ROOT . '/appWeb/public_html/manage/editor/save_song_core.php';
    $relocatePath = $ROOT . '/appWeb/public_html/includes/song_relocate.php';
    $helperPath   = $ROOT . '/appWeb/public_html/includes/songbook_count.php';

    $api2Src     = file_get_contents($api2Path);
    $saveCoreSrc = file_get_contents($saveCorePath);
    $relocateSrc = file_get_contents($relocatePath);
    $helperSrc   = file_get_contents($helperPath);

    /* ---- guard 6: api2.php create_song calls the shared helper ---------- */
    $window = scr_extractCaseWindow($api2Src, "case 'create_song':");
    ok('the create_song window was actually captured (sanity check on the extraction itself)',
       $window !== null && strpos($window, 'INSERT INTO tblSongs') !== false,
       'window ' . ($window === null ? 'not found' : ('length ' . strlen($window) . ', no INSERT INTO tblSongs inside it'))
       . ' — the extraction logic itself is broken, not the code under test');
    ok('api2.php create_song calls songbookRecomputeSongCount() (#1742)',
       $window !== null && strpos($window, 'songbookRecomputeSongCount') !== false,
       'api2.php create_song no longer calls songbookRecomputeSongCount — SongCount goes stale on trigger-less hosts (#1742)');

    /* ---- guard 7: save_song_core.php — extracted, not merely supplemented */
    ok('save_song_core.php calls the shared songbookRecomputeSongCount()',
       strpos($saveCoreSrc, 'songbookRecomputeSongCount') !== false,
       'save_song_core.php no longer calls the shared helper (#1742 extraction regressed)');
    ok('save_song_core.php has NO remaining inline "UPDATE tblSongbooks ... SET SongCount"',
       preg_match('/UPDATE\s+tblSongbooks\s+SET\s+SongCount/i', $saveCoreSrc) !== 1,
       'an inline copy is still present — the extraction must REPLACE the inline UPDATE, not sit '
       . 'alongside it (that would leave two divergence-prone copies again)');

    /* ---- guard 8: song_relocate.php — same positive+negative pair -------- */
    ok('song_relocate.php calls the shared songbookRecomputeSongCount()',
       strpos($relocateSrc, 'songbookRecomputeSongCount') !== false,
       'song_relocate.php no longer calls the shared helper (#1742 extraction regressed)');
    ok('song_relocate.php has NO remaining inline "UPDATE tblSongbooks ... SET SongCount"',
       preg_match('/UPDATE\s+tblSongbooks\s+SET\s+SongCount/i', $relocateSrc) !== 1,
       'an inline copy is still present — the extraction must REPLACE the inline UPDATE, not sit '
       . 'alongside it (that would leave two divergence-prone copies again)');

    /* ---- guard 9: the helper file holds EXACTLY the one canonical copy --- */
    preg_match_all('/UPDATE\s+tblSongbooks\s+SET\s+SongCount/i', $helperSrc, $helperMatches);
    ok('includes/songbook_count.php contains EXACTLY ONE canonical UPDATE tblSongbooks…SET SongCount '
       . '— the single home the recompute now lives in',
       count($helperMatches[0]) === 1,
       'found ' . count($helperMatches[0]) . ' occurrences — guards 7/8 only ban the copy in the CALLERS; '
       . 'the canonical copy belongs here, exactly once');

    $allOk = ($fail === 0);
    foreach (SBC_MODES as $m) {
        echo "\n== mode: {$m} ==\n";
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__);
        putenv(SBC_MODE_ENV . '=' . $m);
        exec($cmd . ' 2>&1', $lines, $code);
        putenv(SBC_MODE_ENV);
        echo implode("\n", $lines) . "\n";
        $sentinel = (bool)preg_grep('/^MODE COMPLETE ' . preg_quote($m, '/') . ' \(\d+ assertion/', $lines);
        if ($code !== 0 || !$sentinel) {
            $allOk = false;
            fwrite(STDERR, "mode '{$m}' FAILED (exit {$code}" . ($sentinel ? '' : ', sentinel missing') . ")\n");
        }
        $lines = [];
    }

    if ($allOk && $fail === 0) {
        echo "\nAll songbook-count-recompute assertions passed.\n";
        exit(0);
    }
    fwrite(STDERR, "\none or more modes failed.\n");
    exit(1);
}

if (!in_array($mode, SBC_MODES, true)) {
    fwrite(STDERR, "Unknown mode '{$mode}'. Valid: " . implode(', ', SBC_MODES) . "\n");
    exit(1);
}

$checks = 0;
$countedOk = function (string $label, bool $cond, string $detail = '') use (&$checks): void {
    $checks++;
    ok($label, $cond, $detail);
};

/* Regex the double's whitespace-collapsed executed SQL matches (the double
   trims and collapses runs of whitespace to single spaces before recording —
   see mysqli_doubles.php's GateRecorderMysqli::prepare()). */
const SBC_RE_MIGRATED   = '/^UPDATE tblSongbooks SET SongCount = \(SELECT COUNT\(\*\) FROM tblSongs WHERE SongbookAbbr = \? AND IsDeleted = 0\) WHERE Abbreviation = \?$/i';
const SBC_RE_UNMIGRATED = '/^UPDATE tblSongbooks SET SongCount = \(SELECT COUNT\(\*\) FROM tblSongs WHERE SongbookAbbr = \? AND 1=1\) WHERE Abbreviation = \?$/i';

/* --------------------------------------------------------------------- */
if ($mode === 'migrated') {
    echo "An install where the #1694 soft-delete migration HAS run\n";

    /* -- prove the fixture actually reaches 'IsDeleted = 0' first --------- */
    $probe = new GateRecorderMysqli();
    $probe->columns = scr_migratedColumns();
    $countedOk("scr_migratedColumns() makes songVisibleSql(\$db, '') return exactly 'IsDeleted = 0'",
       songVisibleSql($probe, '') === 'IsDeleted = 0',
       'got ' . var_export(songVisibleSql($probe, ''), true)
       . ' — every assertion below assumes this; if it is wrong they would all silently '
       . 'test the wrong branch');

    /* -- 1. migrated, one book --------------------------------------------- */
    $db = new GateRecorderMysqli();
    $db->columns = scr_migratedColumns();
    songbookRecomputeSongCount($db, 'MISC');
    $m = $db->executedMatching(SBC_RE_MIGRATED);
    $countedOk('one book: exactly ONE predicate-aware UPDATE executed',
       count($m) === 1,
       'executed: ' . json_encode($db->executed));
    $countedOk('…bound (abbr, abbr) = (MISC, MISC)',
       $m !== [] && $m[0]['params'] === ['MISC', 'MISC'],
       'params were ' . json_encode($m[0]['params'] ?? null));

    /* -- 2. migrated, two distinct books ------------------------------------ */
    $db = new GateRecorderMysqli();
    $db->columns = scr_migratedColumns();
    songbookRecomputeSongCount($db, 'MP', 'SDAH');
    $m = $db->executedMatching(SBC_RE_MIGRATED);
    $countedOk('two distinct books: exactly TWO predicate-aware UPDATEs executed',
       count($m) === 2,
       'executed: ' . json_encode($db->executed));
    $countedOk('…in order, bound (MP,MP) then (SDAH,SDAH) — ONE prepare, looped executes',
       count($m) === 2 && $m[0]['params'] === ['MP', 'MP'] && $m[1]['params'] === ['SDAH', 'SDAH'],
       'params were ' . json_encode(array_column($m, 'params')));
    $countedOk('…and only ONE statement was ever prepared (prepare-once, execute-many)',
       count($db->prepared) === 1,
       'prepared: ' . json_encode($db->prepared));

    /* -- 3. dedupe + trim + empty-skip -------------------------------------- */
    $db = new GateRecorderMysqli();
    $db->columns = scr_migratedColumns();
    songbookRecomputeSongCount($db, 'MISC', 'MISC', '', '  ');
    $m = $db->executedMatching(SBC_RE_MIGRATED);
    $countedOk('dedupe(MISC,MISC) + trim/skip("", "  "): exactly ONE UPDATE executed',
       count($m) === 1,
       'executed: ' . json_encode($db->executed)
       . ' — this proves the repeat MISC collapsed AND the blank/whitespace entries were dropped, '
       . 'not merely that one happened to come first');
    $countedOk('…bound (MISC, MISC)',
       $m !== [] && $m[0]['params'] === ['MISC', 'MISC'],
       'params were ' . json_encode($m[0]['params'] ?? null));

    /* -- 4. no books survive filtering — zero DB round trips ---------------- */
    $db = new GateRecorderMysqli();
    $db->columns = scr_migratedColumns();   // irrelevant — the early return must skip the probe too
    songbookRecomputeSongCount($db, '', '   ');
    $countedOk('all-blank input: NOTHING executed',
       $db->executed === [],
       'executed: ' . json_encode($db->executed));
    $countedOk('…and NOTHING prepared — not even the songVisibleSql() INFORMATION_SCHEMA probe',
       $db->prepared === [],
       'prepared: ' . json_encode($db->prepared)
       . ' — the early return must happen BEFORE songVisibleSql() is ever called');

    echo "MODE COMPLETE {$mode} ({$checks} assertions)\n";
}

/* --------------------------------------------------------------------- */
if ($mode === 'unmigrated') {
    echo "An install where the #1694 soft-delete migration has NOT run — the fail-open degrade\n";

    /* -- prove the fixture actually reaches '1=1' first ---------------------- */
    $probe = new GateRecorderMysqli();   // ->columns left [] — nothing soft-delete exists
    $countedOk("empty columns fixture makes songVisibleSql(\$db, '') return exactly '1=1'",
       songVisibleSql($probe, '') === '1=1',
       'got ' . var_export(songVisibleSql($probe, ''), true));

    /* -- 5. the un-migrated degrade: same UPDATE, predicate swapped to 1=1 --- */
    $db = new GateRecorderMysqli();
    songbookRecomputeSongCount($db, 'MISC');
    $m = $db->executedMatching(SBC_RE_UNMIGRATED);
    $countedOk('un-migrated: exactly ONE UPDATE executed, predicate degraded to 1=1',
       count($m) === 1,
       'executed: ' . json_encode($db->executed)
       . ' — a safe no-behaviour-change degrade (rule C, fail-open): the recompute must still run '
       . 'and count EVERY row, exactly like the pre-#1694 unfiltered count');
    $countedOk('…bound (MISC, MISC), same as the migrated shape',
       $m !== [] && $m[0]['params'] === ['MISC', 'MISC'],
       'params were ' . json_encode($m[0]['params'] ?? null));
    $countedOk('…and the migrated-predicate regex did NOT also match (the predicate really did swap, not just append)',
       $db->executedMatching(SBC_RE_MIGRATED) === [],
       'executed: ' . json_encode($db->executed));

    echo "MODE COMPLETE {$mode} ({$checks} assertions)\n";
}

if ($fail === 0) {
    exit(0);
}
fwrite(STDERR, "\n{$fail} assertion(s) failed.\n");
exit(1);
